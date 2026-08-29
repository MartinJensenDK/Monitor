<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\Auth;
use App\Core\Db;

/**
 * Notification channels — today a named list of email recipients — and the
 * per-monitor rules that decide which events reach them.
 */
final class Channels
{
    public const DEFAULT_RULES = [
        'enabled' => 1,
        'notify_down' => 1,
        'notify_up' => 1,
        'notify_degraded' => 0,
        'notify_cert_expiry' => 1,
        'cert_expiry_days' => 14,
        'failure_threshold' => 1,
        'resend_after_minutes' => 0,
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
    ];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $rows = Db::select(
            'SELECT c.*, (SELECT COUNT(*) FROM {{monitor_notification_settings}} s WHERE s.`channel_id` = c.`id`) AS monitor_count
             FROM {{notification_channels}} c ORDER BY c.`is_default` DESC, c.`name`'
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['recipients'] = self::recipients($row);
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Db::selectOne('SELECT * FROM {{notification_channels}} WHERE `id` = ? LIMIT 1', [$id]);
        if ($row !== null) {
            $row['recipients'] = self::recipients($row);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $channel
     * @return array<int,string>
     */
    public static function recipients(array $channel): array
    {
        $config = json_decode((string) ($channel['config'] ?? '{}'), true);
        $list = is_array($config) ? ($config['recipients'] ?? []) : [];

        return array_values(array_filter(
            array_map('trim', (array) $list),
            static fn (string $address): bool => $address !== ''
        ));
    }

    /** @param array<int,string> $recipients */
    public static function create(string $name, array $recipients, bool $enabled): int
    {
        return Db::insert('notification_channels', [
            'name' => $name,
            'type' => 'email',
            'config' => json_encode(['recipients' => array_values($recipients)]),
            'enabled' => $enabled ? 1 : 0,
            'is_default' => 0,
            'created_by' => Auth::id() ?: null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<int,string> $recipients */
    public static function update(int $id, string $name, array $recipients, bool $enabled): void
    {
        Db::update('notification_channels', [
            'name' => $name,
            'config' => json_encode(['recipients' => array_values($recipients)]),
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::execute('DELETE FROM {{notification_channels}} WHERE `id` = ?', [$id]);
    }

    public static function defaultChannelId(): ?int
    {
        $id = Db::value('SELECT `id` FROM {{notification_channels}} WHERE `is_default` = 1 ORDER BY `id` LIMIT 1');

        return $id === null ? null : (int) $id;
    }

    /**
     * Rules for one monitor, joined with the channel, for the edit form.
     *
     * @return array<int,array<string,mixed>> keyed by channel id
     */
    public static function rulesFor(int $monitorId): array
    {
        $rows = Db::select(
            'SELECT * FROM {{monitor_notification_settings}} WHERE `monitor_id` = ?',
            [$monitorId]
        );

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[(int) $row['channel_id']] = $row;
        }

        return $byChannel;
    }

    /**
     * Channels that should hear about an event on this monitor, with their rules.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function activeFor(int $monitorId): array
    {
        return Db::select(
            'SELECT s.*, c.`name` AS channel_name, c.`config`, c.`type`
             FROM {{monitor_notification_settings}} s
             JOIN {{notification_channels}} c ON c.`id` = s.`channel_id`
             WHERE s.`monitor_id` = ? AND s.`enabled` = 1 AND c.`enabled` = 1',
            [$monitorId]
        );
    }

    /**
     * Replace a monitor's rules. Channels missing from the array are detached.
     *
     * @param array<int,array<string,mixed>> $rules keyed by channel id
     */
    public static function syncMonitorRules(int $monitorId, array $rules): void
    {
        $now = gmdate('Y-m-d H:i:s');

        Db::transaction(static function () use ($monitorId, $rules, $now): void {
            Db::execute('DELETE FROM {{monitor_notification_settings}} WHERE `monitor_id` = ?', [$monitorId]);

            foreach ($rules as $channelId => $rule) {
                Db::insert('monitor_notification_settings', [
                    'monitor_id' => $monitorId,
                    'channel_id' => (int) $channelId,
                    'enabled' => (int) ($rule['enabled'] ?? 1),
                    'notify_down' => (int) ($rule['notify_down'] ?? 1),
                    'notify_up' => (int) ($rule['notify_up'] ?? 1),
                    'notify_degraded' => (int) ($rule['notify_degraded'] ?? 0),
                    'notify_cert_expiry' => (int) ($rule['notify_cert_expiry'] ?? 1),
                    'cert_expiry_days' => (int) ($rule['cert_expiry_days'] ?? 14),
                    'failure_threshold' => (int) ($rule['failure_threshold'] ?? 1),
                    'resend_after_minutes' => (int) ($rule['resend_after_minutes'] ?? 0),
                    'quiet_hours_start' => $rule['quiet_hours_start'] ?: null,
                    'quiet_hours_end' => $rule['quiet_hours_end'] ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /** Attach the default channel to a new monitor, so alerts are not opt-in by accident. */
    public static function attachDefault(int $monitorId): void
    {
        $channelId = self::defaultChannelId();
        if ($channelId === null) {
            return;
        }

        self::syncMonitorRules($monitorId, [$channelId => self::DEFAULT_RULES]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentLog(int $limit = 50): array
    {
        return Db::select(
            'SELECT l.*, m.`name` AS monitor_name, c.`name` AS channel_name
             FROM {{notification_log}} l
             LEFT JOIN {{monitors}} m ON m.`id` = l.`monitor_id`
             LEFT JOIN {{notification_channels}} c ON c.`id` = l.`channel_id`
             ORDER BY l.`id` DESC LIMIT ' . max(1, min(200, $limit))
        );
    }
}
