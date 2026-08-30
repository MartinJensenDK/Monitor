<?php

declare(strict_types=1);

namespace App\Entra;

use App\Core\Db;
use App\Core\Rbac;
use App\Domain\AuditLog;
use App\Domain\Settings;
use App\Domain\UserPhotos;
use App\Domain\Users;
use Throwable;

/**
 * Mirrors the chosen Entra groups into Monitor.
 *
 * The rule throughout is that Entra owns what it owns and nothing else: rows it
 * created carry source='entra' and are read-only in the interface, local groups
 * and local accounts are never touched, and a person who disappears from the
 * directory is disabled rather than deleted, so their history survives.
 *
 * Safe to run repeatedly — every step is an upsert against the object id.
 */
final class Sync
{
    /** Ranked so the most permissive mapped group wins. */
    private const ROLE_RANK = [Rbac::ROLE_VIEWER => 1, Rbac::ROLE_EDITOR => 2, Rbac::ROLE_ADMIN => 3];

    /**
     * @return array{ok:bool,groups:int,users:int,memberships:int,photos:int,disabled:int,released:int,errors:array<int,string>,message:string}
     */
    public static function run(): array
    {
        $summary = [
            'ok' => true,
            'groups' => 0,
            'users' => 0,
            'memberships' => 0,
            'photos' => 0,
            'disabled' => 0,
            'released' => 0,
            'errors' => [],
            'message' => '',
        ];

        if (!Entra::isConfigured()) {
            $summary['ok'] = false;
            $summary['message'] = 'Microsoft Entra ID is not configured.';

            return $summary;
        }

        $wanted = Entra::syncGroupIds();
        if ($wanted === []) {
            $summary['ok'] = false;
            $summary['message'] = 'No Entra groups are selected to sync.';

            return $summary;
        }

        $seenUsers = [];
        $now = gmdate('Y-m-d H:i:s');

        // Who the sync is responsible for, before it changes anything. Someone
        // who signed in with Microsoft but was never in a mirrored group is not
        // ours to disable — only people who were in one and have left.
        $managedBefore = self::managedUserIds();

        foreach ($wanted as $objectId) {
            try {
                $result = self::syncGroup($objectId, $now);
                $summary['groups']++;
                $summary['users'] += $result['users'];
                $summary['memberships'] += $result['memberships'];
                $summary['photos'] += $result['photos'];
                $seenUsers = array_merge($seenUsers, $result['seen']);
            } catch (Throwable $e) {
                $summary['ok'] = false;
                $summary['errors'][] = sprintf('Group %s: %s', $objectId, $e->getMessage());
            }
        }

        // A group an administrator stopped syncing goes back to being an
        // ordinary local group, so its monitor sharing keeps working.
        $summary['released'] = self::releaseUnselectedGroups($wanted);

        // Only prune accounts if every group came back cleanly; a Graph error
        // mid-run must not read as "everybody left the company".
        if ($summary['ok']) {
            $summary['disabled'] = self::disableDeparted(
                array_values(array_diff($managedBefore, array_unique($seenUsers)))
            );
        }

        self::applyRoleMapping();

        $summary['message'] = self::describe($summary);

        Settings::set('entra_last_sync_at', $now);
        Settings::set('entra_last_sync_status', $summary['ok'] ? 'ok' : 'error');
        Settings::set('entra_last_sync_summary', $summary['message']);

        AuditLog::record(
            'entra.sync',
            'system',
            null,
            $summary['message'],
            $summary['errors'] === [] ? [] : ['errors' => $summary['errors']]
        );

        return $summary;
    }

    /**
     * @return array{users:int,memberships:int,photos:int,seen:array<int,int>}
     */
    private static function syncGroup(string $objectId, string $now): array
    {
        $remote = Graph::group($objectId);
        if ($remote === null || $remote['id'] === '') {
            throw new \RuntimeException('the directory has no group with that object id.');
        }

        $groupId = self::upsertGroup($remote, $now);
        $members = Graph::groupMembers($objectId);

        $userIds = [];
        $photos = 0;
        foreach ($members as $member) {
            $userId = self::upsertUser($member, $now);
            $userIds[] = $userId;
            $photos += self::syncPhoto($userId, $member['id'], $now);
        }

        $memberships = self::syncMembership($groupId, $userIds, $now);

        return [
            'users' => count($userIds),
            'memberships' => $memberships,
            'photos' => $photos,
            'seen' => $userIds,
        ];
    }

    /** @param array{id:string,name:string,description:string} $remote */
    private static function upsertGroup(array $remote, string $now): int
    {
        $existing = Db::selectOne('SELECT * FROM {{user_groups}} WHERE `external_id` = ? LIMIT 1', [$remote['id']]);

        if ($existing === null) {
            // A local group with the same name is adopted rather than duplicated.
            $existing = Db::selectOne(
                'SELECT * FROM {{user_groups}} WHERE `name` = ? AND `external_id` IS NULL LIMIT 1',
                [$remote['name']]
            );
        }

        if ($existing !== null) {
            Db::update('user_groups', [
                'name' => $remote['name'],
                'description' => $remote['description'] !== '' ? $remote['description'] : null,
                'source' => 'entra',
                'external_id' => $remote['id'],
                'synced_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Db::insert('user_groups', [
            'name' => $remote['name'],
            'description' => $remote['description'] !== '' ? $remote['description'] : null,
            'source' => 'entra',
            'external_id' => $remote['id'],
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array{id:string,name:string,email:string,enabled:bool} $member */
    private static function upsertUser(array $member, string $now): int
    {
        $existing = Db::selectOne('SELECT * FROM {{users}} WHERE `external_id` = ? LIMIT 1', [$member['id']]);

        if ($existing === null) {
            // Someone who already had a local account keeps it, and their role.
            $existing = Db::selectOne('SELECT * FROM {{users}} WHERE `email` = ? LIMIT 1', [$member['email']]);
        }

        if ($existing !== null) {
            $fields = [
                'name' => $member['name'],
                'email' => $member['email'],
                'auth_provider' => 'entra',
                'external_id' => $member['id'],
                'synced_at' => $now,
                'updated_at' => $now,
            ];

            // Never let a directory flag lock out the last administrator.
            if (!$member['enabled'] && !self::isLastAdmin((int) $existing['id'])) {
                $fields['status'] = 'disabled';
            } elseif ($member['enabled'] && $existing['status'] === 'disabled' && $existing['auth_provider'] === 'entra') {
                $fields['status'] = 'active';
            }

            Db::update('users', $fields, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return Db::insert('users', [
            'name' => $member['name'],
            'email' => $member['email'],
            'password_hash' => null,
            'role' => Entra::defaultRole(),
            'status' => $member['enabled'] ? 'active' : 'disabled',
            'timezone' => Settings::get('default_timezone', 'UTC'),
            'locale' => Settings::get('default_locale', 'en'),
            'theme' => 'light',
            'auth_provider' => 'entra',
            'external_id' => $member['id'],
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mirror one person's profile photo.
     *
     * Three things keep this from turning an hourly sync into an hourly
     * download of every picture in the company: a photo is only asked about
     * once a day, the ETag we already hold goes out with the request so an
     * unchanged picture answers 304, and "this person has no photo" is stored
     * as an answer rather than retried forever.
     *
     * A photo is never worth failing a sync over. Anything that goes wrong is
     * swallowed here — the account, its groups and its role have all already
     * been mirrored correctly, and the picture can wait until tomorrow.
     *
     * Public because signing in with Microsoft goes through the same door:
     * someone who is not in any mirrored group would otherwise never get a
     * picture.
     *
     * @return int 1 when a new picture was stored, 0 otherwise
     */
    public static function syncPhoto(int $userId, string $objectId, string $now): int
    {
        if (!UserPhotos::due($userId)) {
            return 0;
        }

        try {
            $photo = Graph::photo($objectId, UserPhotos::etag($userId));

            if ($photo['status'] === 304) {
                UserPhotos::touch($userId, $now);

                return 0;
            }

            // Only a plain "there is no photo" removes one. Anything else
            // unexpected leaves what we already hold alone: a picture should
            // not disappear from the interface because of a bad afternoon at
            // Microsoft.
            if ($photo['status'] === 404) {
                UserPhotos::clear($userId, $now);

                return 0;
            }

            if ($photo['status'] !== 200 || $photo['bytes'] === '') {
                UserPhotos::touch($userId, $now);

                return 0;
            }

            return UserPhotos::store($userId, $photo['bytes'], $photo['etag'], $now) ? 1 : 0;
        } catch (Throwable) {
            UserPhotos::touch($userId, $now);

            return 0;
        }
    }

    /**
     * @param array<int,int> $userIds
     * @return int rows added or removed
     */
    private static function syncMembership(int $groupId, array $userIds, string $now): int
    {
        $current = Db::select(
            'SELECT `user_id` FROM {{group_user}} WHERE `group_id` = ? AND `source` = \'entra\'',
            [$groupId]
        );
        $currentIds = array_map(static fn (array $r): int => (int) $r['user_id'], $current);

        $changes = 0;

        foreach (array_diff($userIds, $currentIds) as $userId) {
            Db::execute(
                'INSERT IGNORE INTO {{group_user}} (`group_id`, `user_id`, `source`, `created_at`) VALUES (?, ?, \'entra\', ?)',
                [$groupId, $userId, $now]
            );
            $changes++;
        }

        foreach (array_diff($currentIds, $userIds) as $userId) {
            Db::execute(
                'DELETE FROM {{group_user}} WHERE `group_id` = ? AND `user_id` = ? AND `source` = \'entra\'',
                [$groupId, $userId]
            );
            $changes++;
        }

        return $changes;
    }

    /**
     * Groups that used to be synced but are no longer selected become local
     * again, rather than vanishing along with whatever they gave access to.
     *
     * @param array<int,string> $wanted
     */
    private static function releaseUnselectedGroups(array $wanted): int
    {
        $rows = Db::select('SELECT `id`, `name`, `external_id` FROM {{user_groups}} WHERE `source` = \'entra\'');
        $released = 0;

        foreach ($rows as $row) {
            if (in_array((string) $row['external_id'], $wanted, true)) {
                continue;
            }

            Db::update('user_groups', [
                'source' => 'local',
                'external_id' => null,
                'synced_at' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            Db::execute(
                'UPDATE {{group_user}} SET `source` = \'local\' WHERE `group_id` = ?',
                [(int) $row['id']]
            );

            $released++;
        }

        return $released;
    }

    /**
     * User ids that currently hold a membership the directory put there.
     *
     * @return array<int,int>
     */
    private static function managedUserIds(): array
    {
        $rows = Db::select('SELECT DISTINCT `user_id` FROM {{group_user}} WHERE `source` = \'entra\'');

        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /**
     * @param array<int,int> $departed users who were in a mirrored group and are not any more
     */
    private static function disableDeparted(array $departed): int
    {
        if ($departed === []) {
            return 0;
        }

        $sql = 'SELECT `id`, `email` FROM {{users}} WHERE `auth_provider` = \'entra\' AND `status` = \'active\''
            . ' AND `id` IN (' . implode(',', array_map('intval', $departed)) . ')';

        $disabled = 0;
        foreach (Db::select($sql) as $row) {
            if (self::isLastAdmin((int) $row['id'])) {
                continue;
            }

            Db::update('users', [
                'status' => 'disabled',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            Db::execute('DELETE FROM {{remember_tokens}} WHERE `user_id` = ?', [(int) $row['id']]);
            $disabled++;
        }

        return $disabled;
    }

    /**
     * Roles follow group membership where a mapping says so. A local admin who
     * is not in any mapped group keeps the role they were given here.
     */
    public static function applyRoleMapping(): void
    {
        $mappings = Db::select(
            'SELECT m.`group_id`, m.`role` FROM {{group_role_map}} m JOIN {{user_groups}} g ON g.`id` = m.`group_id`'
        );

        if ($mappings === []) {
            return;
        }

        $roleByGroup = [];
        foreach ($mappings as $row) {
            $roleByGroup[(int) $row['group_id']] = (string) $row['role'];
        }

        $users = Db::select('SELECT `id`, `role` FROM {{users}} WHERE `auth_provider` = \'entra\'');

        foreach ($users as $user) {
            $groups = Db::select('SELECT `group_id` FROM {{group_user}} WHERE `user_id` = ?', [(int) $user['id']]);

            $best = null;
            foreach ($groups as $membership) {
                $role = $roleByGroup[(int) $membership['group_id']] ?? null;
                if ($role === null) {
                    continue;
                }
                if ($best === null || self::ROLE_RANK[$role] > self::ROLE_RANK[$best]) {
                    $best = $role;
                }
            }

            $target = $best ?? Entra::defaultRole();

            if ($target !== (string) $user['role']) {
                if ($target !== Rbac::ROLE_ADMIN && self::isLastAdmin((int) $user['id'])) {
                    continue;
                }
                Db::update('users', ['role' => $target, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $user['id']]);
            }
        }
    }

    private static function isLastAdmin(int $userId): bool
    {
        $row = Db::selectOne('SELECT `role`, `status` FROM {{users}} WHERE `id` = ? LIMIT 1', [$userId]);

        if ($row === null || $row['role'] !== Rbac::ROLE_ADMIN || $row['status'] !== 'active') {
            return false;
        }

        return Users::adminCount() <= 1;
    }

    /** @param array<string,mixed> $summary */
    private static function describe(array $summary): string
    {
        $parts = [
            sprintf('%d group(s)', $summary['groups']),
            sprintf('%d member(s)', $summary['users']),
        ];

        if ($summary['memberships'] > 0) {
            $parts[] = sprintf('%d membership change(s)', $summary['memberships']);
        }
        if ($summary['photos'] > 0) {
            $parts[] = sprintf('%d photo(s)', $summary['photos']);
        }
        if ($summary['disabled'] > 0) {
            $parts[] = sprintf('%d account(s) disabled', $summary['disabled']);
        }
        if ($summary['released'] > 0) {
            $parts[] = sprintf('%d group(s) released to local', $summary['released']);
        }

        $text = 'Synced ' . implode(', ', $parts) . '.';

        if ($summary['errors'] !== []) {
            $text .= ' ' . count($summary['errors']) . ' problem(s): ' . implode(' ', $summary['errors']);
        }

        return $text;
    }

    /** Sync runs at most once an hour from the scheduler. */
    public static function due(): bool
    {
        if (!Entra::syncEnabled()) {
            return false;
        }

        $last = Settings::get('entra_last_sync_at');
        if ($last === '') {
            return true;
        }

        return (strtotime($last . ' UTC') ?: 0) < time() - 3600;
    }
}
