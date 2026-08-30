<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;
use App\Support\Str;

final class Monitors
{
    public const TYPES = ['http', 'keyword', 'endpoint', 'api', 'ping', 'port', 'ssl', 'domain', 'dns'];

    /**
     * @deprecated Read available() instead — it also knows what the database
     *             in front of it can actually store.
     */
    public const AVAILABLE_TYPES = self::TYPES;

    public const INTERVALS = [30, 60, 120, 300, 600, 1800, 3600, 21600, 43200, 86400];

    /**
     * Types that read from a registry rather than from the host, where a check
     * every minute would be rude and would tell you nothing new.
     */
    public const SLOW_TYPES = ['domain'];

    public const SLOW_MIN_INTERVAL = 3600;

    /**
     * Monitors the signed-in user may see, with their live status attached.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function visible(array $filters = []): array
    {
        [$scope, $params] = MonitorScope::visible('m');

        $where = ['(' . $scope . ')'];

        if (($filters['status'] ?? '') !== '' && $filters['status'] !== 'all') {
            $where[] = 's.`status` = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (($filters['type'] ?? '') !== '' && $filters['type'] !== 'all') {
            $where[] = 'm.`type` = :type';
            $params['type'] = (string) $filters['type'];
        }
        if (($filters['group'] ?? 0) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM {{monitor_group_access}} f WHERE f.`monitor_id` = m.`id` AND f.`group_id` = :group_filter)';
            $params['group_filter'] = (int) $filters['group'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(m.`name` LIKE :q OR m.`target` LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return Db::select(
            'SELECT m.*, s.`status`, s.`status_since`, s.`last_check_at`, s.`last_response_ms`, s.`last_http_code`,
                    s.`last_error`, s.`consecutive_failures`, s.`uptime_24h`, s.`uptime_7d`, s.`uptime_30d`,
                    s.`avg_ms_24h`, s.`p95_ms_24h`, s.`cert_expires_at`, s.`current_incident_id`
             FROM {{monitors}} m
             LEFT JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY FIELD(s.`status`, \'down\', \'degraded\', \'pending\', \'up\', \'paused\'), m.`name`',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public static function findVisible(int $id): ?array
    {
        [$scope, $params] = MonitorScope::visible('m');
        $params['id'] = $id;

        return Db::selectOne(
            'SELECT m.*, s.`status`, s.`status_since`, s.`last_check_at`, s.`next_check_at`, s.`last_response_ms`,
                    s.`last_http_code`, s.`last_error`, s.`consecutive_failures`, s.`uptime_24h`, s.`uptime_7d`,
                    s.`uptime_30d`, s.`avg_ms_24h`, s.`p95_ms_24h`, s.`cert_expires_at`, s.`cert_issuer`,
                    s.`current_incident_id`
             FROM {{monitors}} m
             LEFT JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             WHERE m.`id` = :id AND (' . $scope . ')
             LIMIT 1',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{monitors}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array{group_id:int,access:string}> $groups
     */
    public static function create(array $data, array $groups): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return Db::transaction(static function () use ($data, $groups, $now): int {
            $id = Db::insert('monitors', [
                'uuid' => Str::uuid4(),
                'name' => $data['name'],
                'type' => $data['type'],
                'target' => $data['target'],
                'enabled' => $data['enabled'] ? 1 : 0,
                'interval_seconds' => $data['interval_seconds'],
                'timeout_seconds' => $data['timeout_seconds'],
                'retries' => $data['retries'],
                'degraded_ms' => $data['degraded_ms'],
                'config' => json_encode($data['config'], JSON_UNESCAPED_SLASHES),
                'tags' => $data['tags'] ?? null,
                'created_by' => Auth::id() ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::insert('monitor_status', [
                'monitor_id' => $id,
                'status' => $data['enabled'] ? 'pending' : 'paused',
                'next_check_at' => $data['enabled'] ? $now : null,
                'updated_at' => $now,
            ]);

            self::syncGroups($id, $groups);

            return $id;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array{group_id:int,access:string}> $groups
     */
    public static function update(int $id, array $data, array $groups): void
    {
        $now = gmdate('Y-m-d H:i:s');

        Db::transaction(static function () use ($id, $data, $groups, $now): void {
            Db::update('monitors', [
                'name' => $data['name'],
                'type' => $data['type'],
                'target' => $data['target'],
                'enabled' => $data['enabled'] ? 1 : 0,
                'interval_seconds' => $data['interval_seconds'],
                'timeout_seconds' => $data['timeout_seconds'],
                'retries' => $data['retries'],
                'degraded_ms' => $data['degraded_ms'],
                'config' => json_encode($data['config'], JSON_UNESCAPED_SLASHES),
                'tags' => $data['tags'] ?? null,
                'updated_at' => $now,
            ], ['id' => $id]);

            self::syncGroups($id, $groups);

            // Re-enabling a paused monitor should check it right away.
            if ($data['enabled']) {
                Db::execute(
                    'UPDATE {{monitor_status}} SET `status` = IF(`status` = \'paused\', \'pending\', `status`),
                     `next_check_at` = LEAST(COALESCE(`next_check_at`, UTC_TIMESTAMP()), UTC_TIMESTAMP()), `updated_at` = ?
                     WHERE `monitor_id` = ?',
                    [$now, $id]
                );
            } else {
                Db::execute(
                    'UPDATE {{monitor_status}} SET `status` = \'paused\', `next_check_at` = NULL, `updated_at` = ? WHERE `monitor_id` = ?',
                    [$now, $id]
                );
                Incidents::resolveOpen($id, 'Monitor paused');
            }
        });
    }

    /** @param array<int,array{group_id:int,access:string}> $groups */
    public static function syncGroups(int $monitorId, array $groups): void
    {
        Db::execute('DELETE FROM {{monitor_group_access}} WHERE `monitor_id` = ?', [$monitorId]);
        $now = gmdate('Y-m-d H:i:s');
        foreach ($groups as $group) {
            Db::execute(
                'INSERT IGNORE INTO {{monitor_group_access}} (`monitor_id`, `group_id`, `access`, `created_at`)
                 VALUES (?, ?, ?, ?)',
                [$monitorId, $group['group_id'], $group['access'], $now]
            );
        }
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Db::update('monitors', ['enabled' => $enabled ? 1 : 0, 'updated_at' => $now], ['id' => $id]);

        if ($enabled) {
            Db::execute(
                'UPDATE {{monitor_status}} SET `status` = \'pending\', `next_check_at` = UTC_TIMESTAMP(), `updated_at` = ? WHERE `monitor_id` = ?',
                [$now, $id]
            );
        } else {
            Db::execute(
                'UPDATE {{monitor_status}} SET `status` = \'paused\', `next_check_at` = NULL, `updated_at` = ? WHERE `monitor_id` = ?',
                [$now, $id]
            );
            Incidents::resolveOpen($id, 'Monitor paused');
        }
    }

    public static function delete(int $id): void
    {
        Db::execute('DELETE FROM {{monitors}} WHERE `id` = ?', [$id]);
    }

    /** @return array<int,array{group_id:int,access:string,name:string}> */
    public static function groups(int $monitorId): array
    {
        $rows = Db::select(
            'SELECT a.`group_id`, a.`access`, g.`name`, g.`source`
             FROM {{monitor_group_access}} a
             JOIN {{user_groups}} g ON g.`id` = a.`group_id`
             WHERE a.`monitor_id` = ?
             ORDER BY g.`name`',
            [$monitorId]
        );

        return array_map(static fn (array $r): array => [
            'group_id' => (int) $r['group_id'],
            'access' => (string) $r['access'],
            'name' => (string) $r['name'],
            'source' => (string) $r['source'],
        ], $rows);
    }

    /**
     * Recent checks for the signal strip, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function tape(int $monitorId, int $limit = 60): array
    {
        $rows = Db::select(
            'SELECT `checked_at`, `status`, `response_ms`, `http_code`, `error_message`
             FROM {{checks}} WHERE `monitor_id` = ? ORDER BY `checked_at` DESC, `id` DESC LIMIT ' . max(1, min(500, $limit)),
            [$monitorId]
        );

        return array_reverse($rows);
    }

    /**
     * Recent checks for many monitors in one query, so the dashboard draws a
     * strip per row without a query per row.
     *
     * @param array<int,int> $monitorIds
     * @return array<int,array<int,array<string,mixed>>> keyed by monitor id, oldest first
     */
    public static function tapes(array $monitorIds, int $limit = 60): array
    {
        if ($monitorIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $monitorIds));
        $rows = Db::select(
            'SELECT `monitor_id`, `checked_at`, `status`, `response_ms`, `http_code`, `error_message`
             FROM (
                SELECT c.`monitor_id`, c.`checked_at`, c.`status`, c.`response_ms`, c.`http_code`, c.`error_message`,
                       ROW_NUMBER() OVER (PARTITION BY c.`monitor_id` ORDER BY c.`checked_at` DESC, c.`id` DESC) AS rn
                FROM {{checks}} c
                WHERE c.`monitor_id` IN (' . $ids . ')
             ) ranked
             WHERE rn <= ' . max(1, min(200, $limit)) . '
             ORDER BY `monitor_id`, `checked_at`'
        );

        $tapes = [];
        foreach ($rows as $row) {
            $tapes[(int) $row['monitor_id']][] = $row;
        }

        return $tapes;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentChecks(int $monitorId, int $limit = 25): array
    {
        return Db::select(
            'SELECT * FROM {{checks}} WHERE `monitor_id` = ? ORDER BY `checked_at` DESC, `id` DESC LIMIT ' . max(1, min(200, $limit)),
            [$monitorId]
        );
    }

    /** @return array<string,mixed> */
    public static function config(array $monitor): array
    {
        $config = json_decode((string) ($monitor['config'] ?? '{}'), true);

        return is_array($config) ? $config : [];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'http' => 'Website',
            'keyword' => 'Keyword',
            'endpoint' => 'Endpoint',
            'api' => 'API',
            'ping' => 'Ping',
            'port' => 'Port',
            'ssl' => 'SSL certificate',
            'domain' => 'Domain',
            'dns' => 'DNS',
            default => ucfirst($type),
        };
    }

    /**
     * The types this installation can actually save.
     *
     * A pending migration leaves the type column behind the code, and the
     * failure would otherwise land as a database error at the moment someone
     * presses Create. So the column is asked what it accepts, and the form
     * offers nothing it would refuse.
     *
     * Any trouble reading the schema means "all of them" — a broken check here
     * must never take working monitor types away.
     *
     * @return array<int,string>
     */
    public static function available(): array
    {
        static $types = null;
        if (is_array($types)) {
            return $types;
        }

        try {
            $column = Db::value(
                'SELECT `COLUMN_TYPE` FROM `information_schema`.`COLUMNS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = \'type\'',
                [Db::prefix() . 'monitors']
            );

            if (!is_string($column) || preg_match_all("/'([^']+)'/", $column, $m) === 0) {
                return $types = self::TYPES;
            }

            // Keep our own order, so the dropdown does not reshuffle itself.
            $types = array_values(array_intersect(self::TYPES, $m[1]));
        } catch (\Throwable) {
            $types = self::TYPES;
        }

        return $types === [] ? (($types = self::TYPES)) : $types;
    }

    /**
     * What the expiry date on a monitor is the expiry of.
     *
     * SSL and domain monitors both store their countdown in
     * monitor_status.cert_expires_at, so the column is read through this to
     * keep a domain from being described as a certificate.
     */
    public static function expiryLabel(string $type): string
    {
        return $type === 'domain' ? 'Domain registration' : 'TLS certificate';
    }

    /** The lowest interval that makes sense for a type, in seconds. */
    public static function minimumInterval(string $type): int
    {
        return in_array($type, self::SLOW_TYPES, true) ? self::SLOW_MIN_INTERVAL : 30;
    }

    /** @return array<string,int> counts by status for the visible set */
    public static function statusCounts(): array
    {
        [$scope, $params] = MonitorScope::visible('m');

        $rows = Db::select(
            'SELECT COALESCE(s.`status`, \'pending\') AS st, COUNT(*) AS c
             FROM {{monitors}} m
             LEFT JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             WHERE ' . $scope . '
             GROUP BY st',
            $params
        );

        $counts = ['up' => 0, 'down' => 0, 'degraded' => 0, 'paused' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['st']] = (int) $row['c'];
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }
}
