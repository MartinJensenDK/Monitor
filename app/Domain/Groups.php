<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;

final class Groups
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::select(
            'SELECT g.*, 
                    (SELECT COUNT(*) FROM {{group_user}} gu WHERE gu.`group_id` = g.`id`) AS member_count,
                    (SELECT COUNT(*) FROM {{monitor_group_access}} a WHERE a.`group_id` = g.`id`) AS monitor_count
             FROM {{user_groups}} g
             ORDER BY g.`name`'
        );
    }

    /** Groups the signed-in user may pick when sharing a monitor. */
    /** @return array<int,array<string,mixed>> */
    public static function assignable(): array
    {
        if (Auth::isAdmin()) {
            return self::all();
        }

        $ids = Auth::groupIds();
        if ($ids === []) {
            return [];
        }

        return Db::select(
            'SELECT g.* FROM {{user_groups}} g WHERE g.`id` IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY g.`name`'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{user_groups}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    public static function create(string $name, string $description): int
    {
        return Db::insert('user_groups', [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'source' => 'local',
            'created_by' => Auth::id() ?: null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, string $name, string $description): void
    {
        Db::update('user_groups', [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::execute('DELETE FROM {{user_groups}} WHERE `id` = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function members(int $groupId): array
    {
        return Db::select(
            'SELECT u.`id`, u.`name`, u.`email`, u.`role`, u.`status`, gu.`source`
             FROM {{group_user}} gu
             JOIN {{users}} u ON u.`id` = gu.`user_id`
             WHERE gu.`group_id` = ?
             ORDER BY u.`name`',
            [$groupId]
        );
    }

    /** @return array<int,int> */
    public static function memberIds(int $groupId): array
    {
        $rows = Db::select('SELECT `user_id` FROM {{group_user}} WHERE `group_id` = ?', [$groupId]);

        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /**
     * Replace the local membership of a group. Rows that came from Entra are
     * left alone so a sync stays the source of truth for them.
     *
     * @param array<int,int> $userIds
     */
    public static function syncMembers(int $groupId, array $userIds): void
    {
        $now = gmdate('Y-m-d H:i:s');

        Db::transaction(static function () use ($groupId, $userIds, $now): void {
            Db::execute('DELETE FROM {{group_user}} WHERE `group_id` = ? AND `source` = \'local\'', [$groupId]);
            foreach (array_unique($userIds) as $userId) {
                Db::execute(
                    'INSERT IGNORE INTO {{group_user}} (`group_id`, `user_id`, `source`, `created_at`) VALUES (?, ?, \'local\', ?)',
                    [$groupId, (int) $userId, $now]
                );
            }
        });
    }

    /** @return array<int,array<string,mixed>> */
    public static function monitors(int $groupId): array
    {
        return Db::select(
            'SELECT m.`id`, m.`name`, m.`type`, m.`target`, a.`access`
             FROM {{monitor_group_access}} a
             JOIN {{monitors}} m ON m.`id` = a.`monitor_id`
             WHERE a.`group_id` = ?
             ORDER BY m.`name`',
            [$groupId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Db::select(
            'SELECT g.*, gu.`source` AS membership_source
             FROM {{group_user}} gu
             JOIN {{user_groups}} g ON g.`id` = gu.`group_id`
             WHERE gu.`user_id` = ?
             ORDER BY g.`name`',
            [$userId]
        );
    }

    public static function isManaged(array $group): bool
    {
        return ($group['source'] ?? 'local') === 'entra';
    }
}
