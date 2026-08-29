<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Db;

/**
 * Rolls raw checks up into per-minute, per-hour and per-day buckets. The UI
 * only ever reads the buckets, which keeps charts fast and lets the raw checks
 * be pruned aggressively.
 */
final class Rollup
{
    /** Recompute one monitor's bucket for the minute a check landed in. */
    public static function minute(int $monitorId, string $checkedAt): void
    {
        $bucket = substr($checkedAt, 0, 16) . ':00';

        $rows = Db::select(
            'SELECT `status`, `response_ms` FROM {{checks}}
             WHERE `monitor_id` = ? AND `checked_at` >= ? AND `checked_at` < DATE_ADD(?, INTERVAL 1 MINUTE)',
            [$monitorId, $bucket, $bucket]
        );

        $ok = $degraded = $fail = 0;
        $times = [];
        foreach ($rows as $row) {
            match ((string) $row['status']) {
                'up' => $ok++,
                'degraded' => $degraded++,
                default => $fail++,
            };
            if ($row['response_ms'] !== null) {
                $times[] = (int) $row['response_ms'];
            }
        }

        Db::execute(
            'INSERT INTO {{stats_minute}} (`monitor_id`, `bucket`, `ok_count`, `degraded_count`, `fail_count`, `avg_ms`, `min_ms`, `max_ms`, `p95_ms`)
             VALUES (:monitor_id, :bucket, :ok, :degraded, :fail, :avg, :min, :max, :p95)
             ON DUPLICATE KEY UPDATE
                `ok_count` = :ok2, `degraded_count` = :degraded2, `fail_count` = :fail2,
                `avg_ms` = :avg2, `min_ms` = :min2, `max_ms` = :max2, `p95_ms` = :p952',
            [
                'monitor_id' => $monitorId,
                'bucket' => $bucket,
                'ok' => $ok, 'ok2' => $ok,
                'degraded' => $degraded, 'degraded2' => $degraded,
                'fail' => $fail, 'fail2' => $fail,
                'avg' => self::avg($times), 'avg2' => self::avg($times),
                'min' => $times === [] ? null : min($times), 'min2' => $times === [] ? null : min($times),
                'max' => $times === [] ? null : max($times), 'max2' => $times === [] ? null : max($times),
                'p95' => self::percentile($times, 0.95), 'p952' => self::percentile($times, 0.95),
            ]
        );
    }

    /** Refresh the cached uptime numbers shown on the dashboard. */
    public static function refreshUptime(int $monitorId, bool $includeLongRanges = false): void
    {
        $day = Db::selectOne(
            'SELECT SUM(`ok_count` + `degraded_count`) AS ok, SUM(`fail_count`) AS fail,
                    ROUND(AVG(`avg_ms`)) AS avg_ms, MAX(`p95_ms`) AS p95_ms
             FROM {{stats_minute}}
             WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)',
            [$monitorId]
        );

        $fields = [
            'uptime_24h' => self::ratio($day),
            'avg_ms_24h' => isset($day['avg_ms']) ? (int) $day['avg_ms'] : null,
            'p95_ms_24h' => isset($day['p95_ms']) ? (int) $day['p95_ms'] : null,
        ];

        if ($includeLongRanges) {
            foreach ([7 => 'uptime_7d', 30 => 'uptime_30d'] as $days => $column) {
                $row = Db::selectOne(
                    'SELECT SUM(`ok_count` + `degraded_count`) AS ok, SUM(`fail_count`) AS fail
                     FROM {{stats_hour}}
                     WHERE `monitor_id` = ? AND `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)',
                    [$monitorId, $days]
                );
                $fields[$column] = self::ratio($row);
            }
        }

        Db::update('monitor_status', $fields, ['monitor_id' => $monitorId]);
    }

    /**
     * Fold finished minutes into hours and hours into days. Safe to call often:
     * every bucket is rewritten from its source, never incremented.
     */
    public static function aggregate(): void
    {
        Db::execute(
            'INSERT INTO {{stats_hour}} (`monitor_id`, `bucket`, `ok_count`, `degraded_count`, `fail_count`, `avg_ms`, `min_ms`, `max_ms`, `p95_ms`)
             SELECT `monitor_id`, DATE_FORMAT(`bucket`, \'%Y-%m-%d %H:00:00\'),
                    SUM(`ok_count`), SUM(`degraded_count`), SUM(`fail_count`),
                    ROUND(AVG(`avg_ms`)), MIN(`min_ms`), MAX(`max_ms`), MAX(`p95_ms`)
             FROM {{stats_minute}}
             WHERE `bucket` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR)
             GROUP BY `monitor_id`, DATE_FORMAT(`bucket`, \'%Y-%m-%d %H:00:00\')
             ON DUPLICATE KEY UPDATE
                `ok_count` = VALUES(`ok_count`), `degraded_count` = VALUES(`degraded_count`),
                `fail_count` = VALUES(`fail_count`), `avg_ms` = VALUES(`avg_ms`),
                `min_ms` = VALUES(`min_ms`), `max_ms` = VALUES(`max_ms`), `p95_ms` = VALUES(`p95_ms`)'
        );

        Db::execute(
            'INSERT INTO {{stats_day}} (`monitor_id`, `bucket`, `ok_count`, `degraded_count`, `fail_count`, `avg_ms`, `min_ms`, `max_ms`, `p95_ms`)
             SELECT `monitor_id`, DATE(`bucket`),
                    SUM(`ok_count`), SUM(`degraded_count`), SUM(`fail_count`),
                    ROUND(AVG(`avg_ms`)), MIN(`min_ms`), MAX(`max_ms`), MAX(`p95_ms`)
             FROM {{stats_hour}}
             WHERE `bucket` >= DATE_SUB(UTC_DATE(), INTERVAL 2 DAY)
             GROUP BY `monitor_id`, DATE(`bucket`)
             ON DUPLICATE KEY UPDATE
                `ok_count` = VALUES(`ok_count`), `degraded_count` = VALUES(`degraded_count`),
                `fail_count` = VALUES(`fail_count`), `avg_ms` = VALUES(`avg_ms`),
                `min_ms` = VALUES(`min_ms`), `max_ms` = VALUES(`max_ms`), `p95_ms` = VALUES(`p95_ms`)'
        );

        // Downtime per day comes from the incidents, not from counting checks,
        // so a monitor on a 10 minute interval reports honest minutes.
        Db::execute(
            'UPDATE {{stats_day}} d
             SET d.`downtime_seconds` = COALESCE((
                SELECT SUM(TIMESTAMPDIFF(
                    SECOND,
                    GREATEST(i.`started_at`, d.`bucket`),
                    LEAST(COALESCE(i.`resolved_at`, UTC_TIMESTAMP()), DATE_ADD(d.`bucket`, INTERVAL 1 DAY))
                ))
                FROM {{incidents}} i
                WHERE i.`monitor_id` = d.`monitor_id`
                  AND i.`started_at` < DATE_ADD(d.`bucket`, INTERVAL 1 DAY)
                  AND COALESCE(i.`resolved_at`, UTC_TIMESTAMP()) > d.`bucket`
             ), 0)
             WHERE d.`bucket` >= DATE_SUB(UTC_DATE(), INTERVAL 2 DAY)'
        );
    }

    /** @param array<int,int> $values */
    private static function avg(array $values): ?int
    {
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }

    /** @param array<int,int> $values */
    private static function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil($percentile * count($values)) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    /** @param array<string,mixed>|null $row */
    private static function ratio(?array $row): ?float
    {
        $ok = (int) ($row['ok'] ?? 0);
        $fail = (int) ($row['fail'] ?? 0);

        return $ok + $fail > 0 ? round($ok / ($ok + $fail), 6) : null;
    }
}
