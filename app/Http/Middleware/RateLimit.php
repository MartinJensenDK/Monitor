<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Db;

/**
 * Login throttling. Counts recent failures per email and per IP so neither a
 * targeted guess nor a spray gets many tries.
 */
final class RateLimit
{
    private const WINDOW_MINUTES = 15;
    private const MAX_PER_IDENTIFIER = 5;
    private const MAX_PER_IP = 20;

    public static function tooManyAttempts(string $identifier, string $ip): bool
    {
        $byIdentifier = (int) Db::value(
            'SELECT COUNT(*) FROM {{login_attempts}}
             WHERE `identifier` = ? AND `succeeded` = 0 AND `attempted_at` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)',
            [strtolower($identifier), self::WINDOW_MINUTES]
        );

        if ($byIdentifier >= self::MAX_PER_IDENTIFIER) {
            return true;
        }

        $byIp = (int) Db::value(
            'SELECT COUNT(*) FROM {{login_attempts}}
             WHERE `ip` = ? AND `succeeded` = 0 AND `attempted_at` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)',
            [$ip, self::WINDOW_MINUTES]
        );

        return $byIp >= self::MAX_PER_IP;
    }

    public static function record(string $identifier, string $ip, bool $succeeded): void
    {
        Db::insert('login_attempts', [
            'identifier' => mb_substr(strtolower($identifier), 0, 190),
            'ip' => $ip,
            'succeeded' => $succeeded ? 1 : 0,
            'attempted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if ($succeeded) {
            self::clear($identifier);
        }
    }

    /** Forget the failures for one identifier. Getting in is proof enough. */
    public static function clear(string $identifier): void
    {
        Db::execute(
            'DELETE FROM {{login_attempts}} WHERE `identifier` = ? AND `succeeded` = 0',
            [strtolower($identifier)]
        );
    }

    public static function windowMinutes(): int
    {
        return self::WINDOW_MINUTES;
    }
}
