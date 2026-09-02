<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use Throwable;

/**
 * Places monitors can be pinned to, and the status of what is pinned there.
 *
 * A location is deliberately thin: a name, an address in the words whoever
 * added it would use, and a pair of coordinates. Nothing is looked up
 * anywhere — the map picker on the form is where the numbers come from.
 */
final class Locations
{
    /**
     * Whether migration 006 has run. Every caller checks first, because the
     * feature has to stay invisible rather than fatal on a site that has
     * updated its files but not yet its database.
     */
    public static function isReady(): bool
    {
        static $ready = null;
        if (is_bool($ready)) {
            return $ready;
        }

        try {
            $ready = Db::tableExists('locations');
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    /**
     * Every location, with how many monitors sit at it. Administration reads
     * this, so the count is of all monitors, not only the visible ones.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        if (!self::isReady()) {
            return [];
        }

        return Db::select(
            'SELECT l.*, (SELECT COUNT(*) FROM {{monitors}} m WHERE m.`location_id` = l.`id`) AS `monitor_count`
             FROM {{locations}} l
             ORDER BY l.`name`'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if (!self::isReady()) {
            return null;
        }

        return Db::selectOne('SELECT * FROM {{locations}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /** The location a monitor is pinned to, if any. @return array<string,mixed>|null */
    public static function forMonitor(array $monitor): ?array
    {
        $id = (int) ($monitor['location_id'] ?? 0);

        return $id > 0 ? self::find($id) : null;
    }

    /**
     * Locations with the status of the monitors the signed-in user may see.
     *
     * A location nobody can see anything at is left out entirely: an empty pin
     * on the map would say "something is here" to someone who is not allowed
     * to know that.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function overview(): array
    {
        if (!self::isReady()) {
            return [];
        }

        [$scope, $params] = MonitorScope::visible('m');

        $rows = Db::select(
            'SELECT l.`id`, l.`name`, l.`address`, l.`latitude`, l.`longitude`,
                    COUNT(m.`id`) AS `total`,
                    SUM(s.`status` = \'down\') AS `down`,
                    SUM(s.`status` = \'degraded\') AS `degraded`,
                    SUM(s.`status` = \'up\') AS `up`
             FROM {{locations}} l
             JOIN {{monitors}} m ON m.`location_id` = l.`id` AND (' . $scope . ')
             LEFT JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             GROUP BY l.`id`, l.`name`, l.`address`, l.`latitude`, l.`longitude`
             ORDER BY l.`name`',
            $params
        );

        return array_map(static function (array $row): array {
            $down = (int) $row['down'];
            $degraded = (int) $row['degraded'];
            $up = (int) $row['up'];
            $total = (int) $row['total'];

            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'address' => (string) ($row['address'] ?? ''),
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'total' => $total,
                'down' => $down,
                'degraded' => $degraded,
                'up' => $up,
                // One pin, one colour, and the worst thing there decides it.
                // A place is not half up.
                'status' => $down > 0 ? 'down' : ($degraded > 0 ? 'degraded' : ($up > 0 ? 'up' : 'pending')),
            ];
        }, $rows);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return Db::insert('locations', [
            'name' => $data['name'],
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Db::update('locations', [
            'name' => $data['name'],
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** Monitors keep working; the foreign key just unpins them. */
    public static function delete(int $id): void
    {
        Db::execute('DELETE FROM {{locations}} WHERE `id` = ?', [$id]);
    }

    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        if (!self::isReady()) {
            return false;
        }

        return Db::selectOne(
            'SELECT `id` FROM {{locations}} WHERE `name` = ? AND `id` <> ? LIMIT 1',
            [$name, $exceptId]
        ) !== null;
    }

    /** Monitors at one location, worst first, for the location page. @return array<int,array<string,mixed>> */
    public static function monitors(int $id): array
    {
        if (!self::isReady()) {
            return [];
        }

        [$scope, $params] = MonitorScope::visible('m');
        $params['location'] = $id;

        return Db::select(
            'SELECT m.`id`, m.`name`, m.`type`, s.`status`
             FROM {{monitors}} m
             LEFT JOIN {{monitor_status}} s ON s.`monitor_id` = m.`id`
             WHERE m.`location_id` = :location AND (' . $scope . ')
             ORDER BY FIELD(s.`status`, \'down\', \'degraded\', \'pending\', \'up\', \'paused\'), m.`name`',
            $params
        );
    }
}
