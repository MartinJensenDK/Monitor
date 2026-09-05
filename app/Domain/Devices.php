<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;
use Throwable;

/**
 * Machines that run the agent.
 *
 * A device row carries its own latest reading of everything a list needs, so
 * drawing forty machines is one query rather than forty. History lives in
 * device_metrics; the lists a report brings (disks, updates, packages,
 * services, ports) live in their own tables and are replaced on each report.
 */
final class Devices
{
    public const KIND_SERVER = 'server';
    public const KIND_CLIENT = 'client';

    /** Late by this multiple of its own cadence and the machine is "stale". */
    private const STALE_FACTOR = 3;

    /** Late by this much and it is called offline. */
    private const OFFLINE_FACTOR = 8;

    /** A machine reporting faster than this is refused; it is a mistake or an attack. */
    public const MIN_INTERVAL = 60;

    public const MAX_INTERVAL = 86400;

    /**
     * The live channel: how often an agent may knock for orders.
     *
     * Five seconds is the floor because the request is small but not free --
     * a hundred machines at five seconds is twenty requests a second at this
     * end, which is more than a modest self-hosted box should be asked for.
     */
    public const MIN_POLL = 5;

    public const MAX_POLL = 3600;

    public const DEFAULT_POLL = 15;

    /**
     * How often this machine is expected to say anything at all.
     *
     * With the live channel on that is the poll cadence, which is what makes
     * silence detectable in under a minute. With it off, the only thing ever
     * heard from the machine is its report, so that is the yardstick instead.
     *
     * @param array<string,mixed> $device
     */
    public static function contactInterval(array $device): int
    {
        $poll = (int) ($device['poll_seconds'] ?? 0);

        return $poll > 0 ? $poll : max(self::MIN_INTERVAL, (int) ($device['interval_seconds'] ?? 300));
    }

    /**
     * Agents arrived in migration 007. Everything above this class checks here
     * first, so an install that has not migrated yet loses the two pages
     * rather than erroring on every request.
     */
    public static function isReady(): bool
    {
        static $ready = null;
        if (is_bool($ready)) {
            return $ready;
        }

        try {
            $ready = Db::tableExists('devices');
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    /** @return array<int,string> */
    public static function kinds(): array
    {
        return [self::KIND_SERVER, self::KIND_CLIENT];
    }

    public static function normaliseKind(?string $kind): string
    {
        return $kind === self::KIND_CLIENT ? self::KIND_CLIENT : self::KIND_SERVER;
    }

    /**
     * The machines on one of the two pages, already filtered and scoped.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function visible(string $kind, array $filters = []): array
    {
        if (!self::isReady()) {
            return [];
        }

        [$scope, $params] = DeviceScope::visible('d');
        $where = ['d.`kind` = :kind', $scope];
        $params['kind'] = self::normaliseKind($kind);

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['online', 'stale', 'offline', 'pending', 'disabled'], true)) {
            $where[] = 'd.`status` = :status';
            $params['status'] = $status;
        } elseif ($status === 'attention') {
            // One filter for "something here needs a person": quiet, wanting a
            // reboot, or sitting on security updates.
            $where[] = "(d.`status` IN ('stale','offline') OR d.`reboot_required` = 1 OR d.`updates_security` > 0)";
        }

        $family = (string) ($filters['os'] ?? '');
        if (in_array($family, ['linux', 'windows', 'macos', 'other'], true)) {
            $where[] = 'd.`os_family` = :os_family';
            $params['os_family'] = $family;
        }

        if (($filters['location'] ?? 0) > 0) {
            $where[] = 'd.`location_id` = :location';
            $params['location'] = (int) $filters['location'];
        }

        if (($filters['group'] ?? 0) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {{device_group_access}} g WHERE g.`device_id` = d.`id` AND g.`group_id` = :group_id)';
            $params['group_id'] = (int) $filters['group'];
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            // Four placeholders rather than one :q repeated: prepares are not
            // emulated here, and PDO binds a named parameter to a single
            // position, so a repeat fails with "Invalid parameter number".
            $where[] = '(d.`name` LIKE :q_name OR d.`hostname` LIKE :q_host'
                . ' OR d.`primary_ip` LIKE :q_ip OR d.`os_name` LIKE :q_os)';
            $params['q_name'] = $params['q_host'] = $params['q_ip'] = $params['q_os'] = '%' . $search . '%';
        }

        return Db::select(
            'SELECT d.*, l.`name` AS `location_name`
             FROM {{devices}} d
             LEFT JOIN {{locations}} l ON l.`id` = d.`location_id`
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY FIELD(d.`status`, \'offline\', \'stale\', \'pending\', \'online\', \'disabled\'), d.`name`',
            $params
        );
    }

    /**
     * The numbers above the list. Counted in the database rather than in PHP so
     * they stay right when the list itself is paginated later.
     *
     * @return array<string,int>
     */
    public static function summary(string $kind): array
    {
        $empty = [
            'total' => 0, 'online' => 0, 'stale' => 0, 'offline' => 0, 'pending' => 0,
            'disabled' => 0, 'reboot' => 0, 'security' => 0, 'updates' => 0,
        ];

        if (!self::isReady()) {
            return $empty;
        }

        [$scope, $params] = DeviceScope::visible('d');
        $params['kind'] = self::normaliseKind($kind);

        $row = Db::selectOne(
            'SELECT COUNT(*) AS `total`,
                    SUM(d.`status` = \'online\') AS `online`,
                    SUM(d.`status` = \'stale\') AS `stale`,
                    SUM(d.`status` = \'offline\') AS `offline`,
                    SUM(d.`status` = \'pending\') AS `pending`,
                    SUM(d.`status` = \'disabled\') AS `disabled`,
                    SUM(d.`reboot_required` = 1) AS `reboot`,
                    SUM(d.`updates_security` > 0) AS `security`,
                    COALESCE(SUM(d.`updates_total`), 0) AS `updates`
             FROM {{devices}} d
             WHERE d.`kind` = :kind AND (' . $scope . ')',
            $params
        );

        if ($row === null) {
            return $empty;
        }

        return array_map(static fn ($v): int => (int) $v, array_merge($empty, $row));
    }

    /** @return array<string,mixed>|null */
    public static function findVisible(string $uuid): ?array
    {
        if (!self::isReady()) {
            return null;
        }

        [$scope, $params] = DeviceScope::visible('d');
        $params['uuid'] = $uuid;

        return Db::selectOne(
            'SELECT d.*, l.`name` AS `location_name`
             FROM {{devices}} d
             LEFT JOIN {{locations}} l ON l.`id` = d.`location_id`
             WHERE d.`uuid` = :uuid AND (' . $scope . ') LIMIT 1',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public static function findEditable(string $uuid): ?array
    {
        if (!self::isReady()) {
            return null;
        }

        [$scope, $params] = DeviceScope::editable('d');
        $params['uuid'] = $uuid;

        return Db::selectOne(
            'SELECT d.* FROM {{devices}} d WHERE d.`uuid` = :uuid AND (' . $scope . ') LIMIT 1',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{devices}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('devices', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Everything hanging off the device is ON DELETE CASCADE, so the
        // metrics, lists, commands and events go with it.
        Db::delete('devices', ['id' => $id]);
    }

    /**
     * Group id => access level, for the sharing control on the device form.
     *
     * @return array<int,string>
     */
    public static function groupAccess(int $id): array
    {
        $rows = Db::select('SELECT `group_id`, `access` FROM {{device_group_access}} WHERE `device_id` = ?', [$id]);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['group_id']] = (string) $row['access'];
        }

        return $map;
    }

    /**
     * Replace who a machine is shared with.
     *
     * @param array<int,string> $access group id => none|view|edit
     * @param array<int,int>|null $limitTo group ids the actor is allowed to touch, or null for all
     */
    public static function setGroupAccess(int $id, array $access, ?array $limitTo = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach ($access as $groupId => $level) {
            $groupId = (int) $groupId;
            if ($groupId <= 0 || ($limitTo !== null && !in_array($groupId, $limitTo, true))) {
                continue;
            }

            if (!in_array($level, ['view', 'edit'], true)) {
                Db::execute(
                    'DELETE FROM {{device_group_access}} WHERE `device_id` = ? AND `group_id` = ?',
                    [$id, $groupId]
                );
                continue;
            }

            Db::execute(
                'INSERT INTO {{device_group_access}} (`device_id`, `group_id`, `access`, `created_at`)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `access` = VALUES(`access`)',
                [$id, $groupId, $level, $now]
            );
        }
    }

    public static function share(int $deviceId, int $groupId, string $access = 'view'): void
    {
        Db::execute(
            'INSERT INTO {{device_group_access}} (`device_id`, `group_id`, `access`, `created_at`)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `access` = VALUES(`access`)',
            [$deviceId, $groupId, $access === 'edit' ? 'edit' : 'view', gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * Readings for the charts, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function metrics(int $id, int $hours = 24): array
    {
        return Db::select(
            'SELECT `captured_at`, `cpu_percent`, `memory_used_bytes`, `memory_total_bytes`,
                    `disk_used_bytes`, `disk_total_bytes`, `load1`
             FROM {{device_metrics}}
             WHERE `device_id` = ? AND `captured_at` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
             ORDER BY `captured_at`',
            [$id, max(1, min(720, $hours))]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function disks(int $id): array
    {
        return Db::select(
            'SELECT * FROM {{device_disks}} WHERE `device_id` = ? ORDER BY `total_bytes` DESC, `mount`',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function updates(int $id): array
    {
        return Db::select(
            'SELECT * FROM {{device_updates}} WHERE `device_id` = ? ORDER BY `is_security` DESC, `name`',
            [$id]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function packages(int $id, string $search = '', int $limit = 500): array
    {
        $params = ['device' => $id];
        $where = '`device_id` = :device';
        if (trim($search) !== '') {
            $where .= ' AND `name` LIKE :q';
            $params['q'] = '%' . trim($search) . '%';
        }

        return Db::select(
            'SELECT * FROM {{device_packages}} WHERE ' . $where . ' ORDER BY `name` LIMIT ' . max(1, min(2000, $limit)),
            $params
        );
    }

    public static function packageCount(int $id, string $search = ''): int
    {
        $params = ['device' => $id];
        $where = '`device_id` = :device';
        if (trim($search) !== '') {
            $where .= ' AND `name` LIKE :q';
            $params['q'] = '%' . trim($search) . '%';
        }

        return (int) Db::value('SELECT COUNT(*) FROM {{device_packages}} WHERE ' . $where, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function services(int $id): array
    {
        return Db::select(
            'SELECT * FROM {{device_services}} WHERE `device_id` = ?
             ORDER BY FIELD(`state`, \'failed\', \'running\') DESC, `name`',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function ports(int $id): array
    {
        return Db::select(
            'SELECT * FROM {{device_ports}} WHERE `device_id` = ? ORDER BY `port`, `protocol`',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function events(int $id, int $limit = 40): array
    {
        return Db::select(
            'SELECT * FROM {{device_events}} WHERE `device_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, min(200, $limit)),
            [$id]
        );
    }

    public static function recordEvent(int $id, string $type, string $summary, string $severity = 'info'): void
    {
        Db::insert('device_events', [
            'device_id' => $id,
            'type' => mb_substr($type, 0, 40),
            'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
            'summary' => mb_substr($summary, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * What a machine's status should be, given when it was last heard from.
     *
     * Silence is the only signal available: an agent that stops reporting looks
     * exactly like a machine that has been switched off, and both are worth
     * saying out loud.
     */
    public static function statusFor(?string $lastSeenAt, int $interval, bool $disabled = false): string
    {
        if ($disabled) {
            return 'disabled';
        }
        if ($lastSeenAt === null || $lastSeenAt === '') {
            return 'pending';
        }

        $seen = strtotime($lastSeenAt . ' UTC');
        if ($seen === false) {
            return 'pending';
        }

        $late = time() - $seen;

        // A little slack on top of the cadence, so a machine polling every
        // five seconds is not called late for being one second behind. Below
        // about a minute the multiples alone are too tight to be fair: a
        // process restart between cron minutes would trip them.
        $interval = max(self::MIN_POLL, $interval);
        $grace = max(30, $interval);

        if ($late <= $interval * self::STALE_FACTOR + $grace) {
            return 'online';
        }

        return $late <= $interval * self::OFFLINE_FACTOR + $grace ? 'stale' : 'offline';
    }

    /**
     * A machine checking in on the live channel.
     *
     * Deliberately the cheapest thing in this class: one UPDATE, and only when
     * the row is actually stale enough to be worth writing. An agent that
     * ignores the cadence it was given -- broken, or hostile -- therefore costs
     * one SELECT per request rather than one write, which is what stops the
     * live channel from becoming a way to make the database work.
     *
     * @param array<string,mixed> $device
     * @return string the status it now has
     */
    public static function touch(array $device): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $seen = $device['last_seen_at'] === null ? 0 : (strtotime((string) $device['last_seen_at'] . ' UTC') ?: 0);
        $wasStatus = (string) $device['status'];

        $columns = ['last_poll_at' => $now];

        // Write at most once every couple of seconds per machine.
        if (time() - $seen >= 2) {
            $columns['last_seen_at'] = $now;
        }

        if ($wasStatus !== 'online') {
            $columns['status'] = 'online';
            $columns['status_since'] = $now;
            $columns['updated_at'] = $now;
        }

        Db::update('devices', $columns, ['id' => (int) $device['id']]);

        // Coming back is worth a line on the timeline; being online already is
        // not, or every poll would write one.
        if (in_array($wasStatus, ['stale', 'offline'], true)) {
            self::recordEvent((int) $device['id'], 'back', $device['name'] . ' is reporting again.', 'info');
        } elseif ($wasStatus === 'pending') {
            self::recordEvent((int) $device['id'], 'first_contact', $device['name'] . ' checked in for the first time.', 'info');
        }

        return 'online';
    }

    /**
     * Whether a full report is due from this machine.
     *
     * Decided here rather than on the machine, so changing the interval in the
     * interface takes effect on the next poll instead of waiting out the old
     * one.
     *
     * @param array<string,mixed> $device
     */
    public static function reportDue(array $device): bool
    {
        $last = $device['last_report_at'] ?? null;
        if ($last === null || $last === '') {
            return true;
        }

        $at = strtotime((string) $last . ' UTC');
        if ($at === false) {
            return true;
        }

        return time() - $at >= max(self::MIN_INTERVAL, (int) $device['interval_seconds']);
    }

    /**
     * Bring every machine's status up to date, and write an event whenever one
     * changes. Called from the scheduler, so a machine that went quiet is
     * noticed without anybody having to open the page.
     *
     * @return array<string,int> how many moved to each status
     */
    public static function refreshStatuses(): array
    {
        if (!self::isReady()) {
            return [];
        }

        $rows = Db::select(
            'SELECT `id`, `name`, `status`, `last_seen_at`, `interval_seconds`, `poll_seconds`, `revoked_at`
             FROM {{devices}}
             WHERE `status` <> \'disabled\''
        );

        $now = gmdate('Y-m-d H:i:s');
        $changed = [];

        foreach ($rows as $row) {
            $status = self::statusFor(
                $row['last_seen_at'] === null ? null : (string) $row['last_seen_at'],
                self::contactInterval($row),
                $row['revoked_at'] !== null
            );

            if ($status === (string) $row['status']) {
                continue;
            }

            Db::update('devices', ['status' => $status, 'status_since' => $now, 'updated_at' => $now], ['id' => (int) $row['id']]);
            $changed[$status] = ($changed[$status] ?? 0) + 1;

            [$type, $summary, $severity] = match ($status) {
                'online' => ['back', $row['name'] . ' is reporting again.', 'info'],
                'stale' => ['late', $row['name'] . ' is late reporting in.', 'warning'],
                'offline' => ['offline', $row['name'] . ' has stopped reporting.', 'critical'],
                default => ['status', $row['name'] . ' is now ' . $status . '.', 'info'],
            };

            self::recordEvent((int) $row['id'], $type, $summary, $severity);
        }

        return $changed;
    }

    /**
     * Locations that have at least one machine the user may see. Feeds the
     * filter on the two list pages, and is scoped for the same reason the
     * monitor one is: naming a place somebody can see nothing at gives away
     * that it exists.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function locationsWithDevices(string $kind): array
    {
        if (!self::isReady() || !Locations::isReady()) {
            return [];
        }

        [$scope, $params] = DeviceScope::visible('d');
        $params['kind'] = self::normaliseKind($kind);

        return Db::select(
            'SELECT l.`id`, l.`name`, COUNT(d.`id`) AS `device_count`
             FROM {{locations}} l
             JOIN {{devices}} d ON d.`location_id` = l.`id` AND d.`kind` = :kind AND (' . $scope . ')
             GROUP BY l.`id`, l.`name`
             ORDER BY l.`name`',
            $params
        );
    }

    /**
     * Whether the signed-in user may act on this machine. Role first, then
     * whether they share a group with it at edit level.
     *
     * @param array<string,mixed> $device
     */
    public static function canEdit(array $device): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if (!Auth::can('devices.manage')) {
            return false;
        }
        if ((int) ($device['created_by'] ?? 0) === Auth::id() && Auth::id() > 0) {
            return true;
        }

        $groupIds = Auth::groupIds();
        if ($groupIds === []) {
            return false;
        }

        $list = implode(',', array_map('intval', $groupIds));

        return (int) Db::value(
            'SELECT COUNT(*) FROM {{device_group_access}}
             WHERE `device_id` = ? AND `access` = \'edit\' AND `group_id` IN (' . $list . ')',
            [(int) $device['id']]
        ) > 0;
    }
}
