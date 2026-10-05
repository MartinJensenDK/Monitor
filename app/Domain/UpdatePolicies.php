<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Automatic updates: a schedule for pressing the buttons nobody wants to press
 * by hand at three in the morning.
 *
 * A policy names weekdays and a time for checking, weekdays and a time for
 * installing, and whether a restart may follow. The scheduler calls run() once
 * a minute, and when a named moment has arrived the policy queues the same
 * commands a person could -- Check for updates, Install updates, Restart -- on
 * the machines that follow it.
 *
 * It is a person's press, written down; it is not a second way in. Each
 * command goes only to a machine that would accept it from a person: commands
 * on, not switched off, and, for the two that change a machine, consent given
 * on the machine at install. Unattended work asks for consent that was stated,
 * not merely not refused -- a machine whose agent is too old to say what it
 * allows is left alone rather than tried.
 *
 * Times are wall-clock in the site's time zone. The UTC instant they name is
 * worked out afresh each day, so 03:00 stays 03:00 across summer time.
 */
final class UpdatePolicies
{
    /** How late a scheduled moment may still be acted on: covers a scheduler that missed a few runs, not one that was off for a day. */
    public const GRACE_SECONDS = 3600;

    /** How long after an install finished a restart may still follow it. */
    public const RESTART_WITHIN_SECONDS = 3 * 3600;

    /** ISO weekday (1 = Monday) to its bit. */
    public const ALL_DAYS = 127;
    public const WEEKDAYS = 31;

    public static function isReady(): bool
    {
        static $ready = null;
        if (is_bool($ready)) {
            return $ready;
        }

        return $ready = Db::tableExists('update_policies');
    }

    public static function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone(Config::string('app.timezone', 'UTC'));
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    // ------------------------------------------------------------- reading ---

    /**
     * Every policy, with how many machines follow it and how many of those
     * would refuse the parts of it that change a machine.
     *
     * The refusals are counted here because they are the thing a policy page
     * most needs to say: a policy that installs on five machines, two of which
     * were installed without --allow-updates, installs on three.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Db::select(
            'SELECT p.*,
                    COUNT(d.`id`) AS `machines`,
                    SUM(CASE WHEN d.`id` IS NOT NULL AND COALESCE(d.`allow_updates`, 0) <> 1 THEN 1 ELSE 0 END) AS `refuse_install`,
                    SUM(CASE WHEN d.`id` IS NOT NULL AND COALESCE(d.`allow_reboot`, 0) <> 1 THEN 1 ELSE 0 END) AS `refuse_restart`
             FROM {{update_policies}} p
             LEFT JOIN {{devices}} d ON d.`update_policy_id` = p.`id`
             GROUP BY p.`id`
             ORDER BY p.`name`'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{update_policies}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /** @return array<int,array{id:int,name:string}> for a select on a machine's page */
    public static function options(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            Db::select('SELECT `id`, `name` FROM {{update_policies}} ORDER BY `name`')
        );
    }

    /** @return array<int,int> ids of the machines that follow this policy */
    public static function memberIds(int $policyId): array
    {
        return array_map('intval', array_column(
            Db::select('SELECT `id` FROM {{devices}} WHERE `update_policy_id` = ?', [$policyId]),
            'id'
        ));
    }

    /**
     * Every machine, with the policy it follows now -- for the checklist on a
     * policy's page. Administrators manage policies and see every machine, so
     * nothing here is narrowed by group.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function machines(): array
    {
        return Db::select(
            'SELECT d.`id`, d.`uuid`, d.`name`, d.`kind`, d.`os_family`, d.`status`, d.`commands_enabled`,
                    d.`allow_updates`, d.`allow_reboot`, d.`update_policy_id`, p.`name` AS `policy_name`
             FROM {{devices}} d
             LEFT JOIN {{update_policies}} p ON p.`id` = d.`update_policy_id`
             ORDER BY d.`kind`, d.`name`'
        );
    }

    // ------------------------------------------------------------- writing ---

    /**
     * @param array{name:string,enabled:bool,check_enabled:bool,check_days:int,check_time:string,install_enabled:bool,install_days:int,install_time:string,restart_after:bool} $data
     */
    public static function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return Db::insert('update_policies', self::columns($data) + [
            'created_by' => Auth::id() > 0 ? Auth::id() : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array{name:string,enabled:bool,check_enabled:bool,check_days:int,check_time:string,install_enabled:bool,install_days:int,install_time:string,restart_after:bool} $data
     */
    public static function update(int $id, array $data): void
    {
        Db::update('update_policies', self::columns($data) + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        // The foreign keys leave its machines without a policy and its past
        // commands without a name; neither is removed.
        Db::delete('update_policies', ['id' => $id]);
    }

    /**
     * Make exactly these machines follow this policy.
     *
     * A machine chosen here that followed another policy moves to this one --
     * one policy per machine is the rule, and the page says so beside each
     * machine before it is ticked. A machine unticked here follows none.
     *
     * @param array<int,int> $deviceIds
     * @return array{added:int,removed:int}
     */
    public static function setMembers(int $policyId, array $deviceIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $deviceIds), static fn (int $id): bool => $id > 0)));
        $current = self::memberIds($policyId);

        $remove = array_values(array_diff($current, $wanted));
        $add = array_values(array_diff($wanted, $current));

        if ($remove !== []) {
            Db::execute(
                'UPDATE {{devices}} SET `update_policy_id` = NULL
                 WHERE `update_policy_id` = ? AND `id` IN (' . implode(',', $remove) . ')',
                [$policyId]
            );
        }
        if ($add !== []) {
            Db::execute(
                'UPDATE {{devices}} SET `update_policy_id` = ? WHERE `id` IN (' . implode(',', $add) . ')',
                [$policyId]
            );
        }

        return ['added' => count($add), 'removed' => count($remove)];
    }

    /** One machine, from its own page. */
    public static function assign(int $deviceId, ?int $policyId): void
    {
        Db::update('devices', ['update_policy_id' => $policyId], ['id' => $deviceId]);
    }

    /**
     * The row values for a policy.
     *
     * "Last acted on" is deliberately not touched: it records only runs that
     * happened, so the policy's page never shows a time for one that did not.
     * What keeps a save from firing a moment that has just gone by is
     * updated_at instead -- run() ignores any moment before the last save.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function columns(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'enabled' => $data['enabled'] ? 1 : 0,
            'check_enabled' => $data['check_enabled'] ? 1 : 0,
            'check_days' => self::cleanDays((int) $data['check_days']),
            'check_time' => self::cleanTime((string) $data['check_time']) . ':00',
            'install_enabled' => $data['install_enabled'] ? 1 : 0,
            'install_days' => self::cleanDays((int) $data['install_days']),
            'install_time' => self::cleanTime((string) $data['install_time']) . ':00',
            'restart_after' => $data['restart_after'] ? 1 : 0,
        ];
    }

    // ------------------------------------------------------------ schedule ---

    public static function cleanDays(int $days): int
    {
        return $days & self::ALL_DAYS;
    }

    /** "3:5" and "03:05:00" both become "03:05"; anything unreadable becomes midnight. */
    public static function cleanTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{1,2})/', trim($time), $m) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%02d', min(23, (int) $m[1]), min(59, (int) $m[2]));
    }

    /**
     * The most recent moment, at or before $now, that this schedule names --
     * in UTC, or null for a schedule with no days.
     */
    public static function latestOccurrence(int $days, string $time, DateTimeImmutable $now, DateTimeZone $tz): ?DateTimeImmutable
    {
        $days = self::cleanDays($days);
        if ($days === 0) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', self::cleanTime($time)));
        $local = $now->setTimezone($tz);

        // Eight days back, not seven: with only one day ticked and its time
        // still ahead today, the latest occurrence is a full week ago.
        for ($back = 0; $back <= 7; $back++) {
            $moment = $local->modify('-' . $back . ' days')->setTime($hour, $minute);
            if (($days & (1 << ((int) $moment->format('N') - 1))) !== 0 && $moment <= $local) {
                return $moment->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return null;
    }

    /** The next moment after $now that this schedule names, in UTC. */
    public static function nextOccurrence(int $days, string $time, DateTimeImmutable $now, DateTimeZone $tz): ?DateTimeImmutable
    {
        $days = self::cleanDays($days);
        if ($days === 0) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', self::cleanTime($time)));
        $local = $now->setTimezone($tz);

        for ($ahead = 0; $ahead <= 7; $ahead++) {
            $moment = $local->modify('+' . $ahead . ' days')->setTime($hour, $minute);
            if (($days & (1 << ((int) $moment->format('N') - 1))) !== 0 && $moment > $local) {
                return $moment->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return null;
    }

    /** "Every day", "Weekdays", "Weekends", or the days themselves. */
    public static function describeDays(int $days): string
    {
        $days = self::cleanDays($days);
        if ($days === self::ALL_DAYS) {
            return t('policy.every_day');
        }
        if ($days === self::WEEKDAYS) {
            return t('policy.weekdays');
        }
        if ($days === 96) {
            return t('policy.weekends');
        }

        $names = [];
        foreach (self::dayNames() as $iso => $name) {
            if (($days & (1 << ($iso - 1))) !== 0) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /** @return array<int,string> ISO weekday => short name */
    public static function dayNames(): array
    {
        return [
            1 => t('policy.day_mon'), 2 => t('policy.day_tue'), 3 => t('policy.day_wed'),
            4 => t('policy.day_thu'), 5 => t('policy.day_fri'), 6 => t('policy.day_sat'), 7 => t('policy.day_sun'),
        ];
    }

    // ----------------------------------------------------------------- run ---

    /**
     * Do whatever is due. Called by the scheduler once a minute.
     *
     * Each half of each policy is claimed before it acts: the "last acted on"
     * moment is moved forward by an UPDATE that only succeeds while it is
     * still behind, so a run that overlapped another -- or a policy saved in
     * the same second -- cannot queue the same window twice.
     *
     * @return array<int,string> one line per thing done, for --verbose
     */
    public static function run(?DateTimeImmutable $now = null): array
    {
        if (!self::isReady() || !Devices::isReady()) {
            return [];
        }

        $utc = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $tz = self::timezone();
        $said = [];

        foreach (Db::select('SELECT * FROM {{update_policies}} WHERE `enabled` = 1 ORDER BY `id`') as $policy) {
            // A schedule begins at its next moment after a save, never one that
            // went by a minute before somebody pressed Save.
            $saved = new DateTimeImmutable((string) $policy['updated_at'], $utc);

            foreach (['check' => 'refresh_updates', 'install' => 'install_updates'] as $half => $command) {
                if ((int) $policy[$half . '_enabled'] !== 1) {
                    continue;
                }

                $moment = self::latestOccurrence((int) $policy[$half . '_days'], (string) $policy[$half . '_time'], $now, $tz);
                if ($moment === null
                    || $moment < $saved
                    || $now->getTimestamp() - $moment->getTimestamp() > self::GRACE_SECONDS) {
                    continue;
                }

                $at = $moment->format('Y-m-d H:i:s');
                $claimed = Db::execute(
                    'UPDATE {{update_policies}} SET `last_' . $half . '_at` = ?
                     WHERE `id` = ? AND (`last_' . $half . '_at` IS NULL OR `last_' . $half . '_at` < ?)',
                    [$at, (int) $policy['id'], $at]
                );
                if ($claimed !== 1) {
                    continue;
                }

                $result = self::queueFor($policy, $command);
                Db::update('update_policies', ['last_' . $half . '_result' => mb_substr($result, 0, 255)], ['id' => (int) $policy['id']]);
                $said[] = sprintf('%s, %s: %s', $policy['name'], $half, $result);
            }

            if ((int) $policy['restart_after'] === 1) {
                foreach (self::restartAfterInstall($policy, $now) as $line) {
                    $said[] = sprintf('%s, restart: %s', $policy['name'], $line);
                }
            }
        }

        return $said;
    }

    /**
     * Queue one command on every machine following this policy that would take
     * it, and say in a sentence what happened -- the sentence is kept on the
     * policy, so its page can answer "what did it do at 03:00".
     *
     * @param array<string,mixed> $policy
     */
    private static function queueFor(array $policy, string $command): string
    {
        if (!AgentPolicy::mayRunCommands()) {
            return 'Nothing queued: commands are switched off for the whole site.';
        }

        $machines = Db::select(
            'SELECT `id`, `name`, `status`, `commands_enabled`, `allow_updates`, `updates_total`
             FROM {{devices}} WHERE `update_policy_id` = ? ORDER BY `name`',
            [(int) $policy['id']]
        );
        if ($machines === []) {
            return 'Nothing queued: no machine follows this policy.';
        }

        $waiting = DeviceCommands::waitingFor(array_map(static fn (array $m): int => (int) $m['id'], $machines), $command);

        $asked = 0;
        $skipped = ['off' => 0, 'consent' => 0, 'nothing' => 0, 'waiting' => 0];
        foreach ($machines as $machine) {
            $id = (int) $machine['id'];

            if ((string) $machine['status'] === 'disabled' || (int) $machine['commands_enabled'] !== 1) {
                $skipped['off']++;
                continue;
            }
            if ($command === 'install_updates' && (int) ($machine['allow_updates'] ?? 0) !== 1) {
                $skipped['consent']++;
                continue;
            }
            // Installing what is waiting, as the button does. A machine that
            // reports nothing waiting has nothing to install; a check earlier
            // in the week is what keeps that knowledge fresh.
            if ($command === 'install_updates' && (int) $machine['updates_total'] === 0) {
                $skipped['nothing']++;
                continue;
            }
            if (in_array($id, $waiting, true)) {
                $skipped['waiting']++;
                continue;
            }

            DeviceCommands::queue($id, $command, 60, (int) $policy['id']);
            Devices::recordEvent(
                $id,
                'command_queued',
                sprintf('Update policy "%s" queued "%s".', $policy['name'], DeviceCommands::label($command)),
                'info'
            );
            $asked++;
        }

        $parts = [sprintf('Queued on %d of %d.', $asked, count($machines))];
        if ($skipped['nothing'] > 0) {
            $parts[] = sprintf('%d had nothing waiting.', $skipped['nothing']);
        }
        if ($skipped['consent'] > 0) {
            $parts[] = sprintf('%d not installed with --allow-updates.', $skipped['consent']);
        }
        if ($skipped['off'] > 0) {
            $parts[] = sprintf('%d switched off or with commands off.', $skipped['off']);
        }
        if ($skipped['waiting'] > 0) {
            $parts[] = sprintf('%d already had one waiting.', $skipped['waiting']);
        }

        return implode(' ', $parts);
    }

    /**
     * Restart the machines this policy installed on, once each, when they say
     * a restart is needed.
     *
     * Only after an install this policy queued and that finished cleanly, in
     * the last few hours -- a failed upgrade is not followed by a restart, and
     * a machine that reports needing one days later is a person's decision.
     * Only once: a restart asked for after that install, by anybody, counts.
     *
     * @param array<string,mixed> $policy
     * @return array<int,string>
     */
    private static function restartAfterInstall(array $policy, DateTimeImmutable $now): array
    {
        if (!AgentPolicy::mayRunCommands()) {
            return [];
        }

        $since = $now->modify('-' . self::RESTART_WITHIN_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        $due = Db::select(
            'SELECT d.`id`, d.`name`, MAX(c.`finished_at`) AS `installed_at`
             FROM {{device_commands}} c
             JOIN {{devices}} d ON d.`id` = c.`device_id`
             WHERE c.`policy_id` = ? AND c.`command` = \'install_updates\' AND c.`status` = \'done\'
               AND c.`finished_at` >= ?
               AND d.`update_policy_id` = c.`policy_id`
               AND d.`reboot_required` = 1 AND d.`allow_reboot` = 1
               AND d.`commands_enabled` = 1 AND d.`status` <> \'disabled\'
             GROUP BY d.`id`, d.`name`',
            [(int) $policy['id'], $since]
        );

        $said = [];
        foreach ($due as $machine) {
            $restarted = (int) Db::value(
                'SELECT COUNT(*) FROM {{device_commands}}
                 WHERE `device_id` = ? AND `command` = \'reboot\' AND `requested_at` >= ?
                   AND `status` NOT IN (\'cancelled\')',
                [(int) $machine['id'], (string) $machine['installed_at']]
            );
            if ($restarted > 0) {
                continue;
            }

            DeviceCommands::queue((int) $machine['id'], 'reboot', 60, (int) $policy['id']);
            Devices::recordEvent(
                (int) $machine['id'],
                'command_queued',
                sprintf('Update policy "%s" queued a restart: the install finished and the machine needs one.', $policy['name']),
                'warning'
            );
            $said[] = (string) $machine['name'];
        }

        return $said === [] ? [] : [sprintf('queued on %s.', implode(', ', $said))];
    }
}
