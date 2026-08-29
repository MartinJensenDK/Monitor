<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Str;

final class Auth
{
    private const REMEMBER_COOKIE = 'monitor_remember';
    private const REMEMBER_DAYS = 30;

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    /** @var array<int,int>|null */
    private static ?array $groupIds = null;

    public static function attempt(string $email, string $password): ?array
    {
        $user = Db::selectOne(
            'SELECT * FROM {{users}} WHERE `email` = ? LIMIT 1',
            [strtolower(trim($email))]
        );

        // Always spend the hashing time so a missing account and a wrong
        // password take the same time to answer.
        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : '$2y$12$usesomesillystringfores1uO6JuMSAf1G0nOMEsdIfJTHu5rXe';

        if (!password_verify($password, $hash)) {
            return null;
        }

        if (!is_array($user) || $user['status'] !== 'active') {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int) $user['id']]);
        }

        return $user;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user, bool $remember = false, bool $secureCookie = true): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        self::$user = $user;
        self::$resolved = true;
        self::$groupIds = null;

        Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $user['id']]);

        if ($remember) {
            self::issueRememberToken((int) $user['id'], $secureCookie);
        }
    }

    public static function logout(bool $secureCookie = true): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$id] = explode(':', $cookie, 2);
            Db::execute('DELETE FROM {{remember_tokens}} WHERE `id` = ?', [(int) $id]);
        }
        self::clearRememberCookie($secureCookie);

        Session::destroy();
        self::$user = null;
        self::$resolved = true;
        self::$groupIds = null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get('user_id');
        if (is_int($id) && $id > 0) {
            $user = Db::selectOne('SELECT * FROM {{users}} WHERE `id` = ? AND `status` = ? LIMIT 1', [$id, 'active']);
            self::$user = is_array($user) ? $user : null;

            if (self::$user === null) {
                Session::forget('user_id');
            }
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): int
    {
        $user = self::user();

        return $user === null ? 0 : (int) $user['id'];
    }

    public static function role(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['role'];
    }

    public static function isAdmin(): bool
    {
        return self::role() === Rbac::ROLE_ADMIN;
    }

    public static function can(string $permission): bool
    {
        return Rbac::allows(self::role(), $permission);
    }

    /**
     * Group ids the signed-in user belongs to. Cached per request because every
     * monitor query joins against it.
     *
     * @return array<int,int>
     */
    public static function groupIds(): array
    {
        if (self::$groupIds !== null) {
            return self::$groupIds;
        }

        $id = self::id();
        if ($id === 0) {
            return self::$groupIds = [];
        }

        $rows = Db::select('SELECT `group_id` FROM {{group_user}} WHERE `user_id` = ?', [$id]);

        return self::$groupIds = array_map(static fn (array $r): int => (int) $r['group_id'], $rows);
    }

    /** Restore a session from the "remember me" cookie. */
    public static function restore(Request $request): void
    {
        if (self::check()) {
            return;
        }

        $cookie = $request->cookie(self::REMEMBER_COOKIE);
        if ($cookie === null || !str_contains($cookie, ':')) {
            return;
        }

        [$id, $validator] = explode(':', $cookie, 2);
        $row = Db::selectOne(
            'SELECT * FROM {{remember_tokens}} WHERE `id` = ? AND `expires_at` > UTC_TIMESTAMP() LIMIT 1',
            [(int) $id]
        );

        if ($row === null || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            self::clearRememberCookie($request->isSecure());

            return;
        }

        $user = Db::selectOne('SELECT * FROM {{users}} WHERE `id` = ? AND `status` = ? LIMIT 1', [(int) $row['user_id'], 'active']);
        if ($user === null) {
            return;
        }

        // Rotate the token on every use so a stolen cookie is single-use.
        Db::execute('DELETE FROM {{remember_tokens}} WHERE `id` = ?', [(int) $row['id']]);
        self::login($user, true, $request->isSecure());
    }

    private static function issueRememberToken(int $userId, bool $secure): void
    {
        $validator = Str::token(32);
        $id = Db::insert('remember_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $validator),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400),
        ]);

        setcookie(self::REMEMBER_COOKIE, $id . ':' . $validator, [
            'expires' => time() + self::REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(bool $secure): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
