<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/**
 * What the agent said it was doing.
 *
 * Kept apart from device_events: an event is something this server worked out
 * about a machine ("it has stopped reporting"), a log line is something the
 * machine said about itself ("apt-get returned 0"). Mixing them would make
 * both harder to read.
 */
final class DeviceLogs
{
    /** Nobody reads more than this at once, and the live view asks for far less. */
    private const MAX_PAGE = 500;

    /**
     * Store a batch, keeping the order the agent sent them in.
     *
     * @param array<int,array<string,mixed>> $lines already through Payload
     * @param array<string,int> $commandIds command uuid => id, for lines that belong to one
     */
    public static function record(int $deviceId, array $lines, array $commandIds = []): int
    {
        if ($lines === []) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $values = [];
        $params = [];

        foreach ($lines as $line) {
            $uuid = (string) ($line['command'] ?? '');
            $values[] = '(?, ?, ?, ?, ?, ?)';
            $params[] = $deviceId;
            $params[] = $commandIds[$uuid] ?? null;
            $params[] = $line['level'];
            $params[] = $line['message'];
            $params[] = $line['at'];
            $params[] = $now;
        }

        return Db::execute(
            'INSERT INTO {{device_logs}} (`device_id`, `command_id`, `level`, `message`, `logged_at`, `received_at`)
             VALUES ' . implode(', ', $values),
            $params
        );
    }

    /**
     * Lines after $sinceId, oldest first.
     *
     * The cursor is the row id rather than a timestamp, because that is the
     * only thing that cannot repeat: a machine whose clock is wrong, or two
     * lines written in the same second, would both break a time-based one.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function since(int $deviceId, int $sinceId = 0, int $limit = 200): array
    {
        return Db::select(
            'SELECT `id`, `command_id`, `level`, `message`, `logged_at`, `received_at`
             FROM {{device_logs}}
             WHERE `device_id` = ? AND `id` > ?
             ORDER BY `id`
             LIMIT ' . max(1, min(self::MAX_PAGE, $limit)),
            [$deviceId, $sinceId]
        );
    }

    /**
     * The tail of the log, oldest first, for the first paint of the page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function tail(int $deviceId, int $limit = 200): array
    {
        $rows = Db::select(
            'SELECT `id`, `command_id`, `level`, `message`, `logged_at`, `received_at`
             FROM {{device_logs}}
             WHERE `device_id` = ?
             ORDER BY `id` DESC
             LIMIT ' . max(1, min(self::MAX_PAGE, $limit)),
            [$deviceId]
        );

        return array_reverse($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forCommand(int $commandId, int $limit = 500): array
    {
        return Db::select(
            'SELECT `id`, `level`, `message`, `logged_at`, `received_at`
             FROM {{device_logs}}
             WHERE `command_id` = ?
             ORDER BY `id`
             LIMIT ' . max(1, min(self::MAX_PAGE, $limit)),
            [$commandId]
        );
    }

    public static function lastId(int $deviceId): int
    {
        return (int) Db::value('SELECT COALESCE(MAX(`id`), 0) FROM {{device_logs}} WHERE `device_id` = ?', [$deviceId]);
    }
}
