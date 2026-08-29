<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use App\Support\Crypto;

/**
 * Site settings live in the database so an admin can change them without shell
 * access. Secrets (SMTP password, Entra client secret) are encrypted at rest.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @var array<int,string> */
    private const SECRETS = ['smtp_password', 'entra_client_secret'];

    public const DEFAULTS = [
        'site_name' => 'Monitor',
        'site_url' => '',
        'default_timezone' => 'UTC',
        'default_locale' => 'en',
        'default_role' => 'viewer',
        'theme_default' => 'system',
        'allow_private_targets' => '0',
        'check_concurrency' => '20',
        'retention_checks_days' => '14',
        'retention_minutes_days' => '30',
        'retention_hours_days' => '400',
        'mail_driver' => 'smtp',
        'mail_from_address' => '',
        'mail_from_name' => 'Monitor',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'notifications_enabled' => '0',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            // Before the installer has run — and in CLI tools that only need
            // defaults — there is no database to read from.
            if (!Db::isConnected()) {
                return self::DEFAULTS;
            }

            $rows = Db::select('SELECT `key`, `value` FROM {{settings}}');
            $values = [];
            foreach ($rows as $row) {
                $values[(string) $row['key']] = (string) $row['value'];
            }
            self::$cache = $values + self::DEFAULTS;
        }

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = self::all()[$key] ?? $default;
        if (in_array($key, self::SECRETS, true) && $value !== '') {
            return Crypto::decrypt($value);
        }

        return $value;
    }

    public static function bool(string $key): bool
    {
        return in_array(self::get($key), ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        if (in_array($key, self::SECRETS, true) && $value !== '') {
            $value = Crypto::encrypt($value);
        }

        Db::execute(
            'INSERT INTO {{settings}} (`key`, `value`, `updated_at`) VALUES (:key, :value, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `value` = :value2, `updated_at` = UTC_TIMESTAMP()',
            ['key' => $key, 'value' => $value, 'value2' => $value]
        );

        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
