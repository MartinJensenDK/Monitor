<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Auth;
use App\Core\Db;
use Throwable;

/**
 * The keys that let a machine enrol.
 *
 * A key is a bearer secret handed to whoever installs the agent, so it is
 * built to be given away carelessly and revoked cheaply. It is stored only as
 * a hash, shown once, can be made to expire, can be capped to a number of
 * machines, and buys nothing except the right to be issued a token for one
 * machine. Revoking it afterwards leaves every machine that already enrolled
 * on it running.
 */
final class EnrollmentKeys
{
    /** Distinguishes an enrolment key from a device token at a glance in a log or a paste. */
    public const PREFIX = 'mek_';

    private const SECRET_BYTES = 24;

    public static function isReady(): bool
    {
        static $ready = null;
        if (is_bool($ready)) {
            return $ready;
        }

        try {
            $ready = Db::tableExists('enrollment_keys');
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        if (!self::isReady()) {
            return [];
        }

        return Db::select(
            'SELECT k.*, g.`name` AS `group_name`, l.`name` AS `location_name`, u.`name` AS `created_by_name`,
                    (SELECT COUNT(*) FROM {{devices}} d WHERE d.`enrollment_key_id` = k.`id`) AS `device_count`
             FROM {{enrollment_keys}} k
             LEFT JOIN {{user_groups}} g ON g.`id` = k.`group_id`
             LEFT JOIN {{locations}} l ON l.`id` = k.`location_id`
             LEFT JOIN {{users}} u ON u.`id` = k.`created_by`
             ORDER BY k.`revoked_at` IS NOT NULL, k.`id` DESC'
        );
    }

    /**
     * Whether anything could enrol right now.
     *
     * Asked by the two list pages so their empty state can say the right
     * thing. It is a count rather than a fetch on purpose: the page only needs
     * to know whether, not which, and the names of keys are none of a viewer's
     * business.
     */
    public static function anyActive(): bool
    {
        if (!self::isReady()) {
            return false;
        }

        return (int) Db::value(
            'SELECT COUNT(*) FROM {{enrollment_keys}}
             WHERE `revoked_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())
               AND (`max_uses` = 0 OR `uses` < `max_uses`)'
        ) > 0;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM {{enrollment_keys}} WHERE `id` = ? LIMIT 1', [$id]);
    }

    /**
     * Make a key. The plaintext is returned here and nowhere else: it is shown
     * once on the page that asked for it and then only its hash remains.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,key:string}
     */
    public static function create(array $data): array
    {
        $secret = self::PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));

        $id = Db::insert('enrollment_keys', [
            'name' => mb_substr(trim((string) $data['name']), 0, 120),
            'token_hash' => hash('sha256', $secret),
            'token_hint' => substr($secret, 0, 12),
            'kind' => in_array($data['kind'] ?? 'auto', ['auto', 'server', 'client'], true) ? (string) $data['kind'] : 'auto',
            'group_id' => ($data['group_id'] ?? 0) > 0 ? (int) $data['group_id'] : null,
            'location_id' => ($data['location_id'] ?? 0) > 0 ? (int) $data['location_id'] : null,
            'max_uses' => max(0, (int) ($data['max_uses'] ?? 0)),
            'uses' => 0,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => Auth::id() > 0 ? Auth::id() : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'key' => $secret];
    }

    public static function revoke(int $id): void
    {
        Db::update('enrollment_keys', ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::delete('enrollment_keys', ['id' => $id]);
    }

    /**
     * The key a presented secret belongs to, if it is still good for one more
     * machine.
     *
     * Looked up by the hash of what was presented, so nothing is compared
     * character by character and an unknown key costs exactly what a known one
     * does. Every reason to say no is folded into the query for the same
     * reason: the caller learns "no", not which of the four it was.
     *
     * @return array<string,mixed>|null
     */
    public static function redeem(string $presented): ?array
    {
        if (!self::isReady() || $presented === '') {
            return null;
        }

        return Db::selectOne(
            'SELECT * FROM {{enrollment_keys}}
             WHERE `token_hash` = ?
               AND `revoked_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())
               AND (`max_uses` = 0 OR `uses` < `max_uses`)
             LIMIT 1',
            [hash('sha256', $presented)]
        );
    }

    /**
     * Spend one use. Written as a conditional UPDATE so two machines enrolling
     * at the same second cannot both slip past a max_uses of one.
     */
    public static function spend(int $id): bool
    {
        return Db::execute(
            'UPDATE {{enrollment_keys}}
             SET `uses` = `uses` + 1, `last_used_at` = UTC_TIMESTAMP()
             WHERE `id` = ? AND `revoked_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())
               AND (`max_uses` = 0 OR `uses` < `max_uses`)',
            [$id]
        ) === 1;
    }

    /** @param array<string,mixed> $key */
    public static function state(array $key): string
    {
        if ($key['revoked_at'] !== null) {
            return 'revoked';
        }
        if ($key['expires_at'] !== null && strtotime((string) $key['expires_at'] . ' UTC') < time()) {
            return 'expired';
        }
        if ((int) $key['max_uses'] > 0 && (int) $key['uses'] >= (int) $key['max_uses']) {
            return 'used up';
        }

        return 'active';
    }
}
