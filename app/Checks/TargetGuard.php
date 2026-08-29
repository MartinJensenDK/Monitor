<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Settings;

/**
 * Monitors fetch URLs that users type in, so the checker doubles as a request
 * forger unless targets are policed. Monitoring an internal host is a real
 * need, so private ranges are a setting rather than a hard block.
 */
final class TargetGuard
{
    public static function allowsPrivate(): bool
    {
        return Settings::bool('allow_private_targets');
    }

    public static function isPrivateIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Checked before the request (on the resolved host) and again afterwards on
     * the IP curl actually talked to, which also covers redirects and DNS
     * entries that change between the two.
     *
     * @return array{allowed:bool,reason:string}
     */
    public static function check(string $host): array
    {
        if ($host === '') {
            return ['allowed' => false, 'reason' => 'The target has no host name.'];
        }

        if (self::allowsPrivate()) {
            return ['allowed' => true, 'reason' => ''];
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Name did not resolve; let the checker report the DNS failure itself.
            return ['allowed' => true, 'reason' => ''];
        }

        if (self::isPrivateIp($ip)) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    '%s resolves to the private address %s. Turn on "Allow private targets" in settings to monitor internal hosts.',
                    $host,
                    $ip
                ),
            ];
        }

        return ['allowed' => true, 'reason' => ''];
    }
}
