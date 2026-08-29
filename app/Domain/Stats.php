<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/**
 * Everything the charts read. Series come from the rollup tables, never from
 * the raw checks table, so a monitor with a year of history draws as fast as a
 * fresh one.
 */
final class Stats
{
    public const RANGES = [
        '1h' => ['label' => 'Last hour', 'table' => 'stats_minute', 'interval' => '1 HOUR', 'step' => 60],
        '24h' => ['label' => '24 hours', 'table' => 'stats_minute', 'interval' => '24 HOUR', 'step' => 300],
        '7d' => ['label' => '7 days', 'table' => 'stats_hour', 'interval' => '7 DAY', 'step' => 3600],
        '30d' => ['label' => '30 days', 'table' => 'stats_hour', 'interval' => '30 DAY', 'step' => 10800],
        '90d' => ['label' => '90 days', 'table' => 'stats_day', 'interval' => '90 DAY', 'step' => 86400],
    ];

    /**
     * Time series for one monitor: [timestamps, avg ms, p95 ms, failure ratio].
     *
     * @return array{t:array<int,int>,avg:array<int,int|null>,p95:array<int,int|null>,fail:array<int,float>}
     */
    public static function series(int $monitorId, string $range = '24h'): array
    {
        $spec = self::RANGES[$range] ?? self::RANGES['24h'];
        $step = (int) $spec['step'];

        $rows = Db::select(
            sprintf(
                'SELECT UNIX_TIMESTAMP(`bucket`) AS ts, `ok_count`, `degraded_count`, `fail_count`, `avg_ms`, `p95_ms`
                 FROM {{%s}}
                 WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s)
                 ORDER BY `bucket`',
                $spec['table'],
                $spec['interval']
            ),
            [$monitorId]
        );

        // Fold into fixed-width buckets so the x axis is evenly spaced even when
        // a monitor was paused or the scheduler missed a minute.
        $buckets = [];
        foreach ($rows as $row) {
            $slot = intdiv((int) $row['ts'], $step) * $step;
            $bucket = $buckets[$slot] ?? ['ok' => 0, 'fail' => 0, 'avg_sum' => 0, 'avg_n' => 0, 'p95' => 0];
            $bucket['ok'] += (int) $row['ok_count'] + (int) $row['degraded_count'];
            $bucket['fail'] += (int) $row['fail_count'];
            if ($row['avg_ms'] !== null) {
                $bucket['avg_sum'] += (int) $row['avg_ms'];
                $bucket['avg_n']++;
            }
            $bucket['p95'] = max($bucket['p95'], (int) ($row['p95_ms'] ?? 0));
            $buckets[$slot] = $bucket;
        }

        $t = $avg = $p95 = $fail = [];
        foreach ($buckets as $slot => $bucket) {
            $total = $bucket['ok'] + $bucket['fail'];
            $t[] = $slot;
            $avg[] = $bucket['avg_n'] > 0 ? (int) round($bucket['avg_sum'] / $bucket['avg_n']) : null;
            $p95[] = $bucket['p95'] > 0 ? $bucket['p95'] : null;
            $fail[] = $total > 0 ? round($bucket['fail'] / $total, 4) : 0.0;
        }

        return ['t' => $t, 'avg' => $avg, 'p95' => $p95, 'fail' => $fail];
    }

    /**
     * Daily uptime bars for the last N days, oldest first.
     *
     * @return array<int,array{date:string,uptime:?float,ok:int,fail:int,downtime:int}>
     */
    public static function dailyUptime(int $monitorId, int $days = 30): array
    {
        $rows = Db::select(
            'SELECT `bucket`, `ok_count`, `degraded_count`, `fail_count`, `downtime_seconds`
             FROM {{stats_day}}
             WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_DATE(), INTERVAL ? DAY)
             ORDER BY `bucket`',
            [$monitorId, $days]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['bucket']] = $row;
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', time() - $i * 86400);
            $row = $byDate[$date] ?? null;
            if ($row === null) {
                $out[] = ['date' => $date, 'uptime' => null, 'ok' => 0, 'fail' => 0, 'downtime' => 0];
                continue;
            }
            $ok = (int) $row['ok_count'] + (int) $row['degraded_count'];
            $fail = (int) $row['fail_count'];
            $total = $ok + $fail;
            $out[] = [
                'date' => $date,
                'uptime' => $total > 0 ? $ok / $total : null,
                'ok' => $ok,
                'fail' => $fail,
                'downtime' => (int) $row['downtime_seconds'],
            ];
        }

        return $out;
    }

    /**
     * Uptime ratio over a window, straight from the minute rollups.
     */
    public static function uptime(int $monitorId, int $hours): ?float
    {
        $row = Db::selectOne(
            'SELECT SUM(`ok_count` + `degraded_count`) AS ok, SUM(`fail_count`) AS fail
             FROM {{stats_minute}}
             WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)',
            [$monitorId, $hours]
        );

        $ok = (int) ($row['ok'] ?? 0);
        $fail = (int) ($row['fail'] ?? 0);
        $total = $ok + $fail;

        return $total > 0 ? $ok / $total : null;
    }

    /** Uptime over a window using hourly rollups, for the longer ranges. */
    public static function uptimeDays(int $monitorId, int $days): ?float
    {
        $row = Db::selectOne(
            'SELECT SUM(`ok_count` + `degraded_count`) AS ok, SUM(`fail_count`) AS fail
             FROM {{stats_hour}}
             WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)',
            [$monitorId, $days]
        );

        $ok = (int) ($row['ok'] ?? 0);
        $fail = (int) ($row['fail'] ?? 0);
        $total = $ok + $fail;

        return $total > 0 ? $ok / $total : null;
    }

    /**
     * Response time percentiles over a window.
     *
     * @return array{avg:?int,p95:?int,min:?int,max:?int}
     */
    public static function latency(int $monitorId, int $hours = 24): array
    {
        $row = Db::selectOne(
            'SELECT ROUND(AVG(`avg_ms`)) AS avg_ms, MAX(`p95_ms`) AS p95_ms, MIN(`min_ms`) AS min_ms, MAX(`max_ms`) AS max_ms
             FROM {{stats_minute}}
             WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)',
            [$monitorId, $hours]
        );

        return [
            'avg' => isset($row['avg_ms']) ? (int) $row['avg_ms'] : null,
            'p95' => isset($row['p95_ms']) ? (int) $row['p95_ms'] : null,
            'min' => isset($row['min_ms']) ? (int) $row['min_ms'] : null,
            'max' => isset($row['max_ms']) ? (int) $row['max_ms'] : null,
        ];
    }

    /**
     * Fleet-wide response time and failure series for the dashboard.
     *
     * @return array{t:array<int,int>,avg:array<int,int|null>,fail:array<int,float>}
     */
    public static function fleetSeries(string $range = '24h'): array
    {
        $spec = self::RANGES[$range] ?? self::RANGES['24h'];
        $step = (int) $spec['step'];
        [$scope, $params] = MonitorScope::visible('m');

        $rows = Db::select(
            sprintf(
                'SELECT UNIX_TIMESTAMP(b.`bucket`) AS ts, SUM(b.`ok_count` + b.`degraded_count`) AS ok,
                        SUM(b.`fail_count`) AS fail, ROUND(AVG(b.`avg_ms`)) AS avg_ms
                 FROM {{%s}} b
                 JOIN {{monitors}} m ON m.`id` = b.`monitor_id`
                 WHERE b.`bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s) AND (%s)
                 GROUP BY b.`bucket`
                 ORDER BY b.`bucket`',
                $spec['table'],
                $spec['interval'],
                $scope
            ),
            $params
        );

        $buckets = [];
        foreach ($rows as $row) {
            $slot = intdiv((int) $row['ts'], $step) * $step;
            $bucket = $buckets[$slot] ?? ['ok' => 0, 'fail' => 0, 'sum' => 0, 'n' => 0];
            $bucket['ok'] += (int) $row['ok'];
            $bucket['fail'] += (int) $row['fail'];
            if ($row['avg_ms'] !== null) {
                $bucket['sum'] += (int) $row['avg_ms'];
                $bucket['n']++;
            }
            $buckets[$slot] = $bucket;
        }

        $t = $avg = $fail = [];
        foreach ($buckets as $slot => $bucket) {
            $total = $bucket['ok'] + $bucket['fail'];
            $t[] = $slot;
            $avg[] = $bucket['n'] > 0 ? (int) round($bucket['sum'] / $bucket['n']) : null;
            $fail[] = $total > 0 ? round($bucket['fail'] / $total, 4) : 0.0;
        }

        return ['t' => $t, 'avg' => $avg, 'fail' => $fail];
    }

    /** Fleet uptime across all visible monitors over the last N hours. */
    public static function fleetUptime(int $hours = 24): ?float
    {
        [$scope, $params] = MonitorScope::visible('m');
        $params['hours'] = $hours;

        $row = Db::selectOne(
            'SELECT SUM(b.`ok_count` + b.`degraded_count`) AS ok, SUM(b.`fail_count`) AS fail
             FROM {{stats_minute}} b
             JOIN {{monitors}} m ON m.`id` = b.`monitor_id`
             WHERE b.`bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR) AND (' . $scope . ')',
            $params
        );

        $ok = (int) ($row['ok'] ?? 0);
        $fail = (int) ($row['fail'] ?? 0);

        return $ok + $fail > 0 ? $ok / ($ok + $fail) : null;
    }

    /** Median response time across visible monitors right now. */
    public static function fleetLatency(): ?int
    {
        [$scope, $params] = MonitorScope::visible('m');

        $value = Db::value(
            'SELECT ROUND(AVG(s.`avg_ms_24h`)) FROM {{monitor_status}} s
             JOIN {{monitors}} m ON m.`id` = s.`monitor_id`
             WHERE s.`avg_ms_24h` IS NOT NULL AND (' . $scope . ')',
            $params
        );

        return $value === null ? null : (int) $value;
    }
}
