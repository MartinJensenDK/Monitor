<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Db;
use App\Domain\Settings;

/**
 * Keeps the database from growing without bound. Raw checks are short lived
 * because the rollups already hold everything the charts need.
 */
final class Retention
{
    /** @return array<string,int> rows removed per table */
    public static function prune(): array
    {
        $removed = [];

        $removed['checks'] = self::deleteInBatches(
            'DELETE FROM {{checks}} WHERE `checked_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT 5000',
            Settings::int('retention_checks_days', 14)
        );

        $removed['stats_minute'] = self::deleteInBatches(
            'DELETE FROM {{stats_minute}} WHERE `bucket` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT 5000',
            Settings::int('retention_minutes_days', 30)
        );

        $removed['stats_hour'] = self::deleteInBatches(
            'DELETE FROM {{stats_hour}} WHERE `bucket` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT 5000',
            Settings::int('retention_hours_days', 400)
        );

        $removed['login_attempts'] = Db::execute(
            'DELETE FROM {{login_attempts}} WHERE `attempted_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)'
        );

        $removed['remember_tokens'] = Db::execute(
            'DELETE FROM {{remember_tokens}} WHERE `expires_at` < UTC_TIMESTAMP()'
        );

        $removed['notification_log'] = Db::execute(
            'DELETE FROM {{notification_log}} WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)'
        );

        return $removed;
    }

    private static function deleteInBatches(string $sql, int $days): int
    {
        $total = 0;
        do {
            $deleted = Db::execute($sql, [$days]);
            $total += $deleted;
        } while ($deleted === 5000);

        return $total;
    }

    /** Maintenance runs at most once every 10 minutes, whichever tick gets there first. */
    public static function due(): bool
    {
        $last = Settings::get('last_maintenance_at', '');
        if ($last === '') {
            return true;
        }

        return (strtotime($last . ' UTC') ?: 0) < time() - 600;
    }

    public static function markRun(): void
    {
        Settings::set('last_maintenance_at', gmdate('Y-m-d H:i:s'));
    }
}
