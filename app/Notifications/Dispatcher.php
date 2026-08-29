<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Checks\CheckResult;
use App\Domain\Settings;

/**
 * Notification hook. Stage one records the events an operator would be told
 * about; stage two adds the email transport and the per-monitor rules that
 * decide who hears about them.
 *
 * @param array<string,mixed> $monitor
 */
final class Dispatcher
{
    /** @param array<string,mixed> $monitor */
    public static function monitorWentDown(array $monitor, CheckResult $result, int $incidentId): void
    {
        self::note('monitor.down', $monitor, $incidentId, $result->summary());
    }

    /** @param array<string,mixed> $monitor */
    public static function monitorRecovered(array $monitor, CheckResult $result, ?int $incidentId): void
    {
        self::note('monitor.up', $monitor, $incidentId, $result->summary());
    }

    /** @param array<string,mixed> $monitor */
    public static function monitorDegraded(array $monitor, CheckResult $result): void
    {
        self::note('monitor.degraded', $monitor, null, $result->summary());
    }

    /** @param array<string,mixed> $monitor */
    private static function note(string $event, array $monitor, ?int $incidentId, string $summary): void
    {
        if (!Settings::bool('notifications_enabled')) {
            return;
        }

        // Stage two: resolve channels and per-monitor rules, then send.
    }
}
