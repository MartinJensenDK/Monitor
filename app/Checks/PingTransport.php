<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Settings;

/**
 * Works out how this server is allowed to ping.
 *
 * Real ICMP needs either an unprivileged ICMP socket (which the kernel only
 * hands out when net.ipv4.ping_group_range covers our group) or a ping binary
 * carrying cap_net_raw. Plenty of shared hosts give you neither, so the check
 * falls back to timing a TCP connect and says so in the interface rather than
 * pretending it sent an echo request.
 */
final class PingTransport
{
    public const ICMP = 'icmp';
    public const BINARY = 'binary';
    public const TCP = 'tcp';

    /** Re-detect once a day; an admin may have fixed the sysctl in the meantime. */
    private const CACHE_SECONDS = 86400;

    public static function detect(bool $force = false): string
    {
        if (!$force) {
            $cached = Settings::get('ping_transport');
            $checkedAt = Settings::get('ping_transport_checked_at');
            $age = $checkedAt === '' ? PHP_INT_MAX : time() - (strtotime($checkedAt . ' UTC') ?: 0);

            if ($cached !== '' && $age < self::CACHE_SECONDS) {
                return $cached;
            }
        }

        $transport = self::probe();

        Settings::set('ping_transport', $transport);
        Settings::set('ping_transport_checked_at', gmdate('Y-m-d H:i:s'));

        return $transport;
    }

    /** What the current transport means, in a sentence for the interface. */
    public static function describe(string $transport): string
    {
        return match ($transport) {
            self::ICMP => 'ICMP echo, sent directly from PHP.',
            self::BINARY => 'ICMP echo, via the system ping command.',
            default => 'TCP connect timing — this server may not send ICMP, so the check times a connection to the fallback port instead.',
        };
    }

    public static function isIcmp(string $transport): bool
    {
        return $transport === self::ICMP || $transport === self::BINARY;
    }

    private static function probe(): string
    {
        if (self::canOpenIcmpSocket()) {
            return self::ICMP;
        }

        if (self::findBinary() !== null) {
            return self::BINARY;
        }

        return self::TCP;
    }

    public static function canOpenIcmpSocket(): bool
    {
        if (!function_exists('socket_create')) {
            return false;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, getprotobyname('icmp') ?: 1);
        if ($socket === false) {
            return false;
        }

        socket_close($socket);

        return true;
    }

    public static function findBinary(): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        foreach (['/bin/ping', '/usr/bin/ping', '/sbin/ping', '/usr/sbin/ping'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The exact command an administrator can run to enable real ICMP. */
    public static function enableHint(): string
    {
        return 'sudo sysctl -w net.ipv4.ping_group_range="0 2147483647"';
    }
}
