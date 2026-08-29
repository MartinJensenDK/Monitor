<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\Db;

/**
 * Warns before a TLS certificate lapses.
 *
 * A certificate that expires quietly at 02:00 on a Sunday is one of the few
 * outages you can always prevent, so this runs from the scheduler rather than
 * waiting for the site to break. Each channel decides how many days of notice
 * it wants, and hears at most once a day.
 */
final class CertificateWatcher
{
    /** Nothing further out than this is worth looking at. */
    private const HORIZON_DAYS = 60;

    /** @return int number of monitors that triggered a reminder */
    public static function run(): int
    {
        $rows = Db::select(
            'SELECT m.*, s.`cert_expires_at`, s.`cert_issuer`
             FROM {{monitors}} m
             JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             WHERE m.`enabled` = 1
               AND s.`cert_expires_at` IS NOT NULL
               AND s.`cert_expires_at` <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)',
            [self::HORIZON_DAYS]
        );

        $notified = 0;
        foreach ($rows as $monitor) {
            $expiresAt = (string) $monitor['cert_expires_at'];
            $timestamp = strtotime($expiresAt . ' UTC');
            if ($timestamp === false) {
                continue;
            }

            $days = (int) floor(($timestamp - time()) / 86400);
            Dispatcher::certificateExpiring($monitor, $days, $expiresAt);
            $notified++;
        }

        return $notified;
    }
}
