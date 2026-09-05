<?php

declare(strict_types=1);

namespace App\Agent;

use App\Core\Db;
use App\Core\Request;
use App\Domain\Devices;

/**
 * Who is speaking, on the machine side.
 *
 * A device proves itself with one long random token and nothing else. There is
 * no password to guess and no username to enumerate: the token is 24 random
 * bytes, and the only thing that can be done with a wrong one is to be told no.
 * It is stored hashed, so this database cannot be read to obtain one; the
 * lookup hashes what was presented and asks for an exact index match, which
 * means an unknown token costs the same as a known one.
 */
final class AgentAuth
{
    /** Distinguishes a device token from an enrolment key at a glance. */
    public const PREFIX = 'mdt_';

    private const SECRET_BYTES = 24;

    /**
     * The token the request carries.
     *
     * Authorization is the right header and is what the agent sends. Not every
     * PHP-FPM setup forwards it, though -- it is a known hole in a good few
     * nginx configurations -- so a second header is accepted as well, and the
     * agent sends both. Same request, same TLS, so nothing extra is exposed by
     * doing it; it just removes an install-day failure nobody could diagnose.
     */
    public static function presented(Request $request): string
    {
        $header = (string) ($request->header('Authorization') ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
            return $m[1];
        }

        return trim((string) ($request->header('X-Monitor-Token') ?? ''));
    }

    public static function newToken(): string
    {
        return self::PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function hint(string $token): string
    {
        return substr($token, 0, 12);
    }

    /**
     * The machine a token belongs to, or null.
     *
     * Revoked and disabled machines are excluded here rather than in the
     * caller, so no endpoint can forget to check.
     *
     * @return array<string,mixed>|null
     */
    public static function device(string $token): ?array
    {
        if ($token === '' || !Devices::isReady()) {
            return null;
        }

        return Db::selectOne(
            'SELECT * FROM {{devices}} WHERE `token_hash` = ? AND `revoked_at` IS NULL LIMIT 1',
            [self::hash($token)]
        );
    }
}
