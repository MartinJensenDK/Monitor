<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Checks\CheckResult;
use App\Core\Db;
use App\Domain\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Decides who hears about an event, and writes down what it did.
 *
 * The rules live per monitor and per channel: which events to send, how many
 * consecutive failures to wait for, whether to repeat while an incident is
 * open, and hours to stay quiet. Every decision — sent, failed, or deliberately
 * skipped — lands in notification_log, so "why didn't I get an email?" is a
 * question with an answer.
 */
final class Dispatcher
{
    public const EVENT_DOWN = 'monitor.down';
    public const EVENT_UP = 'monitor.up';
    public const EVENT_DEGRADED = 'monitor.degraded';
    public const EVENT_CERT = 'monitor.cert_expiry';

    /** @param array<string,mixed> $monitor */
    public static function monitorWentDown(array $monitor, CheckResult $result, int $incidentId, int $consecutiveFailures): void
    {
        $incident = Db::selectOne('SELECT * FROM {{incidents}} WHERE `id` = ? LIMIT 1', [$incidentId]);
        $startedAt = (string) ($incident['started_at'] ?? gmdate('Y-m-d H:i:s'));
        $failed = (int) ($incident['failed_checks'] ?? 1);

        self::dispatch(
            self::EVENT_DOWN,
            $monitor,
            $incidentId,
            'notify_down',
            static fn (): array => EmailTemplate::down($monitor, $result->summary(), $startedAt, $failed),
            static fn (array $rule): bool => $consecutiveFailures >= (int) $rule['failure_threshold']
        );
    }

    /** @param array<string,mixed> $monitor */
    public static function monitorRecovered(array $monitor, CheckResult $result, ?int $incidentId, int $downtimeSeconds): void
    {
        $downtime = format_duration($downtimeSeconds);

        self::dispatch(
            self::EVENT_UP,
            $monitor,
            $incidentId,
            'notify_up',
            static fn (): array => EmailTemplate::recovered($monitor, $downtime, (int) ($result->responseMs ?? 0)),
            // Only tell people it recovered if they were told it went down.
            static fn (array $rule): bool => $incidentId === null || self::wasNotified((int) $rule['channel_id'], $incidentId, self::EVENT_DOWN)
        );
    }

    /** @param array<string,mixed> $monitor */
    public static function monitorDegraded(array $monitor, CheckResult $result): void
    {
        self::dispatch(
            self::EVENT_DEGRADED,
            $monitor,
            null,
            'notify_degraded',
            static fn (): array => EmailTemplate::degraded($monitor, $result->summary(), (int) ($result->responseMs ?? 0)),
            static fn (array $rule): bool => true
        );
    }

    /**
     * Still down after the resend interval — say so again.
     *
     * @param array<string,mixed> $monitor
     */
    public static function incidentStillOpen(array $monitor, array $incident): void
    {
        $incidentId = (int) $incident['id'];
        $startedAt = (string) $incident['started_at'];
        $failed = (int) $incident['failed_checks'];
        $error = (string) ($incident['last_error'] ?? 'The check is still failing.');

        self::dispatch(
            self::EVENT_DOWN,
            $monitor,
            $incidentId,
            'notify_down',
            static fn (): array => EmailTemplate::down($monitor, $error, $startedAt, $failed),
            static function (array $rule) use ($incidentId): bool {
                $interval = (int) $rule['resend_after_minutes'];
                if ($interval <= 0) {
                    return false;
                }

                $last = self::lastSentAt((int) $rule['channel_id'], $incidentId, self::EVENT_DOWN);

                return $last !== null && time() - $last >= $interval * 60;
            },
            true
        );
    }

    /** @param array<string,mixed> $monitor */
    public static function certificateExpiring(array $monitor, int $days, string $expiresAt): void
    {
        self::dispatch(
            self::EVENT_CERT,
            $monitor,
            null,
            'notify_cert_expiry',
            static fn (): array => EmailTemplate::certificateExpiring($monitor, $days, $expiresAt),
            static function (array $rule) use ($monitor, $days): bool {
                if ($days > (int) $rule['cert_expiry_days']) {
                    return false;
                }

                // At most one certificate reminder per channel per day.
                $last = self::lastSentAtForMonitor((int) $rule['channel_id'], (int) $monitor['id'], self::EVENT_CERT);

                return $last === null || time() - $last >= 86400;
            },
            true
        );
    }

    /**
     * @param array<string,mixed> $monitor
     * @param callable():array{0:string,1:string} $body
     * @param callable(array<string,mixed>):bool $shouldSend
     */
    private static function dispatch(
        string $event,
        array $monitor,
        ?int $incidentId,
        string $flag,
        callable $body,
        callable $shouldSend,
        bool $allowRepeat = false
    ): void {
        if (!Settings::bool('notifications_enabled')) {
            return;
        }

        $monitorId = (int) $monitor['id'];
        $rules = Channels::activeFor($monitorId);
        if ($rules === []) {
            return;
        }

        if (!Mailer::isConfigured()) {
            foreach ($rules as $rule) {
                self::log($event, $monitorId, $incidentId, (int) $rule['channel_id'], null, 'skipped', 'Email is not configured.');
            }

            return;
        }

        [$html, $text] = $body();
        $subject = self::subject($event, $monitor);

        foreach ($rules as $rule) {
            $channelId = (int) $rule['channel_id'];

            if ((int) ($rule[$flag] ?? 0) !== 1) {
                continue;
            }

            if (!$allowRepeat && $incidentId !== null && self::wasNotified($channelId, $incidentId, $event)) {
                continue;
            }

            if (!$shouldSend($rule)) {
                continue;
            }

            if (self::inQuietHours($rule)) {
                self::log($event, $monitorId, $incidentId, $channelId, null, 'skipped', 'Inside the channel\'s quiet hours.');
                continue;
            }

            $recipients = Channels::recipients($rule);
            if ($recipients === []) {
                self::log($event, $monitorId, $incidentId, $channelId, null, 'skipped', 'The channel has no recipients.');
                continue;
            }

            try {
                $outcome = Mailer::send($recipients, $subject, $html, $text);
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e->getMessage()];
            }

            self::log(
                $event,
                $monitorId,
                $incidentId,
                $channelId,
                implode(', ', $recipients),
                $outcome['ok'] ? 'sent' : 'failed',
                $outcome['ok'] ? null : $outcome['error']
            );

            if ($outcome['ok'] && $incidentId !== null && $event === self::EVENT_DOWN) {
                Db::execute(
                    'UPDATE {{incidents}} SET `notified_at` = UTC_TIMESTAMP(), `resend_count` = `resend_count` + 1 WHERE `id` = ?',
                    [$incidentId]
                );
            }
        }
    }

    /** @param array<string,mixed> $monitor */
    private static function subject(string $event, array $monitor): string
    {
        $site = Settings::get('site_name', 'Monitor');
        $name = (string) $monitor['name'];

        return match ($event) {
            self::EVENT_DOWN => sprintf('[%s] %s is DOWN', $site, $name),
            self::EVENT_UP => sprintf('[%s] %s recovered', $site, $name),
            self::EVENT_DEGRADED => sprintf('[%s] %s is slow', $site, $name),
            self::EVENT_CERT => sprintf('[%s] TLS certificate for %s expires soon', $site, $name),
            default => sprintf('[%s] %s', $site, $name),
        };
    }

    /**
     * Quiet hours are read in the site's time zone, and may wrap past midnight
     * (22:00 to 07:00 is one window, not two).
     *
     * @param array<string,mixed> $rule
     */
    private static function inQuietHours(array $rule): bool
    {
        $start = (string) ($rule['quiet_hours_start'] ?? '');
        $end = (string) ($rule['quiet_hours_end'] ?? '');

        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }

        try {
            $zone = new DateTimeZone(Settings::get('default_timezone', 'UTC'));
            $now = (new DateTimeImmutable('now', $zone))->format('H:i:s');
        } catch (Throwable) {
            return false;
        }

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }

    private static function wasNotified(int $channelId, int $incidentId, string $event): bool
    {
        return self::lastSentAt($channelId, $incidentId, $event) !== null;
    }

    private static function lastSentAt(int $channelId, int $incidentId, string $event): ?int
    {
        $value = Db::value(
            'SELECT MAX(`created_at`) FROM {{notification_log}}
             WHERE `channel_id` = ? AND `incident_id` = ? AND `event` = ? AND `status` = \'sent\'',
            [$channelId, $incidentId, $event]
        );

        return $value === null ? null : (strtotime((string) $value . ' UTC') ?: null);
    }

    private static function lastSentAtForMonitor(int $channelId, int $monitorId, string $event): ?int
    {
        $value = Db::value(
            'SELECT MAX(`created_at`) FROM {{notification_log}}
             WHERE `channel_id` = ? AND `monitor_id` = ? AND `event` = ? AND `status` = \'sent\'',
            [$channelId, $monitorId, $event]
        );

        return $value === null ? null : (strtotime((string) $value . ' UTC') ?: null);
    }

    private static function log(
        string $event,
        int $monitorId,
        ?int $incidentId,
        int $channelId,
        ?string $recipient,
        string $status,
        ?string $error
    ): void {
        Db::insert('notification_log', [
            'monitor_id' => $monitorId,
            'incident_id' => $incidentId,
            'channel_id' => $channelId,
            'event' => $event,
            'recipient' => $recipient === null ? null : mb_substr($recipient, 0, 190),
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
