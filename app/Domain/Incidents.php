<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;

final class Incidents
{
    public static function open(int $monitorId, string $cause, string $error, string $severity = 'down'): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $existing = Db::selectOne(
            'SELECT `id` FROM {{incidents}} WHERE `monitor_id` = ? AND `status` = \'open\' ORDER BY `id` DESC LIMIT 1',
            [$monitorId]
        );

        if ($existing !== null) {
            Db::update('incidents', [
                'last_error' => mb_substr($error, 0, 500),
                'severity' => $severity,
            ], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Db::insert('incidents', [
            'monitor_id' => $monitorId,
            'status' => 'open',
            'severity' => $severity,
            'started_at' => $now,
            'cause' => mb_substr($cause, 0, 64),
            'last_error' => mb_substr($error, 0, 500),
            'failed_checks' => 1,
        ]);
    }

    public static function recordFailure(int $incidentId, string $error): void
    {
        Db::execute(
            'UPDATE {{incidents}} SET `failed_checks` = `failed_checks` + 1, `last_error` = ? WHERE `id` = ?',
            [mb_substr($error, 0, 500), $incidentId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{incidents}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /** The open incident for a monitor, if there is one. */
    /** @return array<string,mixed>|null */
    public static function openFor(int $monitorId): ?array
    {
        return Db::selectOne(
            'SELECT * FROM {{incidents}} WHERE `monitor_id` = ? AND `status` = \'open\' ORDER BY `id` DESC LIMIT 1',
            [$monitorId]
        );
    }

    public static function resolveOpen(int $monitorId, ?string $note = null): void
    {
        $open = Db::selectOne(
            'SELECT `id`, `started_at` FROM {{incidents}} WHERE `monitor_id` = ? AND `status` = \'open\' ORDER BY `id` DESC LIMIT 1',
            [$monitorId]
        );

        if ($open === null) {
            return;
        }

        $started = strtotime((string) $open['started_at'] . ' UTC') ?: time();

        Db::update('incidents', [
            'status' => 'resolved',
            'resolved_at' => gmdate('Y-m-d H:i:s'),
            'duration_seconds' => max(0, time() - $started),
            'last_error' => $note ?? null,
        ], ['id' => (int) $open['id']]);

        Db::execute('UPDATE {{monitor_status}} SET `current_incident_id` = NULL WHERE `monitor_id` = ?', [$monitorId]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 12, string $status = 'all'): array
    {
        [$scope, $params] = MonitorScope::visible('m');
        $where = ['(' . $scope . ')'];
        if ($status !== 'all') {
            $where[] = 'i.`status` = :istatus';
            $params['istatus'] = $status;
        }

        return Db::select(
            'SELECT i.*, m.`name` AS monitor_name, m.`type` AS monitor_type, m.`target`, u.`name` AS acknowledged_by_name
             FROM {{incidents}} i
             JOIN {{monitors}} m ON m.`id` = i.`monitor_id`
             LEFT JOIN {{users}} u ON u.`id` = i.`acknowledged_by`
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.`status` = \'open\' DESC, i.`started_at` DESC
             LIMIT ' . max(1, min(200, $limit)),
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forMonitor(int $monitorId, int $limit = 10): array
    {
        return Db::select(
            'SELECT i.*, u.`name` AS acknowledged_by_name
             FROM {{incidents}} i
             LEFT JOIN {{users}} u ON u.`id` = i.`acknowledged_by`
             WHERE i.`monitor_id` = ? ORDER BY i.`started_at` DESC LIMIT ' . max(1, min(100, $limit)),
            [$monitorId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findVisible(int $id): ?array
    {
        [$scope, $params] = MonitorScope::visible('m');
        $params['id'] = $id;

        return Db::selectOne(
            'SELECT i.*, m.`name` AS monitor_name, m.`created_by`, m.`id` AS monitor_id
             FROM {{incidents}} i
             JOIN {{monitors}} m ON m.`id` = i.`monitor_id`
             WHERE i.`id` = :id AND (' . $scope . ') LIMIT 1',
            $params
        );
    }

    public static function acknowledge(int $id): void
    {
        Db::update('incidents', [
            'acknowledged_by' => Auth::id(),
            'acknowledged_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function openCount(): int
    {
        [$scope, $params] = MonitorScope::visible('m');

        return (int) Db::value(
            'SELECT COUNT(*) FROM {{incidents}} i JOIN {{monitors}} m ON m.`id` = i.`monitor_id`
             WHERE i.`status` = \'open\' AND (' . $scope . ')',
            $params
        );
    }

    /** Total downtime in seconds across visible monitors for the last N days. */
    public static function downtimeSeconds(int $days = 30): int
    {
        [$scope, $params] = MonitorScope::visible('m');
        $params['days'] = $days;

        return (int) Db::value(
            'SELECT COALESCE(SUM(COALESCE(i.`duration_seconds`, TIMESTAMPDIFF(SECOND, i.`started_at`, UTC_TIMESTAMP()))), 0)
             FROM {{incidents}} i JOIN {{monitors}} m ON m.`id` = i.`monitor_id`
             WHERE i.`started_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY) AND (' . $scope . ')',
            $params
        );
    }
}
