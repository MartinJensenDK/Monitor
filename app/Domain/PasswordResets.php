<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use App\Support\Str;

/**
 * One-shot links for choosing a new password.
 *
 * The link carries `id:validator`, the same shape as the remember-me cookie.
 * Only the hash of the validator is stored, so a copy of the table is not a set
 * of working links, and the lookup goes through the primary key instead of
 * scanning the table for a hash.
 */
final class PasswordResets
{
    public const TTL_MINUTES = 60;

    /**
     * The account a reset may be sent to, or null. Entra accounts are excluded:
     * their password lives in Microsoft, and setting one here would not let
     * anyone in through a door this site controls.
     *
     * @return array<string,mixed>|null
     */
    public static function accountFor(string $email): ?array
    {
        return Db::selectOne(
            'SELECT * FROM {{users}}
             WHERE `email` = ? AND `status` = ? AND `auth_provider` = ? LIMIT 1',
            [strtolower(trim($email)), 'active', 'local']
        );
    }

    /** Asking for a new link retires every earlier one for that account. */
    public static function create(int $userId): string
    {
        Db::execute('DELETE FROM {{password_resets}} WHERE `user_id` = ?', [$userId]);

        $validator = Str::token(32);
        $id = Db::insert('password_resets', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $validator),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
        ]);

        return $id . ':' . $validator;
    }

    /**
     * The link's account, or null when the token is expired, already used,
     * unknown or altered. The caller cannot tell which — and neither can
     * whoever is holding the link.
     *
     * @return array{reset:array<string,mixed>,user:array<string,mixed>}|null
     */
    public static function resolve(string $token): ?array
    {
        if (!str_contains($token, ':')) {
            return null;
        }

        [$id, $validator] = explode(':', $token, 2);
        $reset = Db::selectOne(
            'SELECT * FROM {{password_resets}}
             WHERE `id` = ? AND `used_at` IS NULL AND `expires_at` > UTC_TIMESTAMP() LIMIT 1',
            [(int) $id]
        );

        if ($reset === null || !hash_equals((string) $reset['token_hash'], hash('sha256', $validator))) {
            return null;
        }

        // The account may have been disabled or moved to Entra since the mail
        // went out, so it is checked again here rather than only at send time.
        $user = Db::selectOne(
            'SELECT * FROM {{users}} WHERE `id` = ? AND `status` = ? AND `auth_provider` = ? LIMIT 1',
            [(int) $reset['user_id'], 'active', 'local']
        );

        return is_array($user) ? ['reset' => $reset, 'user' => $user] : null;
    }

    /** Spend the link. Everything else outstanding for the account goes too. */
    public static function consume(int $resetId, int $userId): void
    {
        Db::update('password_resets', ['used_at' => gmdate('Y-m-d H:i:s')], ['id' => $resetId]);
        Db::execute(
            'DELETE FROM {{password_resets}} WHERE `user_id` = ? AND `id` <> ?',
            [$userId, $resetId]
        );
    }
}
