<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    public static function start(string $path, bool $secure): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            return;
        }

        if (!is_dir($path)) {
            @mkdir($path, 0750, true);
        }

        session_name('monitor_session');
        session_save_path($path);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (self::$started) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (!self::$started) {
            return;
        }
        $_SESSION = [];
        session_destroy();
        self::$started = false;
    }

    public static function flash(string $type, string $message): void
    {
        $messages = $_SESSION['_flash'] ?? [];
        $messages[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $messages;
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }

    /** Keep form input around for one redirect so a failed form can be refilled. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_csrf']);
        $_SESSION['_old'] = $input;
    }

    /** @return array<string,mixed> */
    public static function takeOld(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);

        return is_array($old) ? $old : [];
    }
}
