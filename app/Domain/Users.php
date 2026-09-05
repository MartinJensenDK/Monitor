<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;

final class Users
{
    /** @return array<int,array<string,mixed>> */
    public static function all(string $search = ''): array
    {
        $where = '1 = 1';
        $params = [];
        if ($search !== '') {
            // Two placeholders rather than :q twice -- see the note in
            // App\Domain\Devices::visible().
            $where = '(`name` LIKE :q_name OR `email` LIKE :q_email)';
            $params['q_name'] = $params['q_email'] = '%' . $search . '%';
        }

        return Db::select(
            'SELECT u.*, (SELECT COUNT(*) FROM {{group_user}} gu WHERE gu.`user_id` = u.`id`) AS group_count
             FROM {{users}} u WHERE ' . $where . ' ORDER BY u.`name`',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{users}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        return (bool) Db::value(
            'SELECT 1 FROM {{users}} WHERE `email` = ? AND `id` <> ? LIMIT 1',
            [strtolower(trim($email)), $exceptId]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Db::insert('users', [
            'name' => $data['name'],
            'email' => strtolower(trim((string) $data['email'])),
            'password_hash' => Auth::hash((string) $data['password']),
            'role' => $data['role'],
            'status' => $data['status'] ?? 'active',
            'timezone' => $data['timezone'] ?? 'UTC',
            'locale' => $data['locale'] ?? 'en',
            'theme' => 'light',
            'auth_provider' => 'local',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('users', $data, ['id' => $id]);
    }

    public static function setPassword(int $id, string $password): void
    {
        Db::update('users', [
            'password_hash' => Auth::hash($password),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
        Db::execute('DELETE FROM {{remember_tokens}} WHERE `user_id` = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::execute('DELETE FROM {{users}} WHERE `id` = ?', [$id]);
    }

    public static function adminCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM {{users}} WHERE `role` = \'admin\' AND `status` = \'active\'');
    }

    /** @param array<int,int> $groupIds */
    public static function syncGroups(int $userId, array $groupIds): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Db::transaction(static function () use ($userId, $groupIds, $now): void {
            Db::execute('DELETE FROM {{group_user}} WHERE `user_id` = ? AND `source` = \'local\'', [$userId]);
            foreach (array_unique($groupIds) as $groupId) {
                Db::execute(
                    'INSERT IGNORE INTO {{group_user}} (`group_id`, `user_id`, `source`, `created_at`) VALUES (?, ?, \'local\', ?)',
                    [(int) $groupId, $userId, $now]
                );
            }
        });
    }

    public static function isManaged(array $user): bool
    {
        return ($user['auth_provider'] ?? 'local') === 'entra';
    }
}
