<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;

/**
 * Port check: is something listening, how long the handshake took, and
 * optionally whether it announced what it should.
 */
final class PortChecker implements CheckerInterface
{
    public static function type(): string
    {
        return 'port';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $host = self::host($monitor);
        $port = self::port($monitor);

        $guard = TargetGuard::check($host);
        if (!$guard['allowed']) {
            return CheckResult::down('blocked_target', $guard['reason']);
        }

        $result = TcpProbe::connect($host, $port, (float) max(1, (int) $monitor['timeout_seconds']));

        return self::toCheckResult($monitor, $result);
    }

    /** @param array<string,mixed> $monitor */
    public static function host(array $monitor): string
    {
        $target = trim((string) $monitor['target']);

        if (str_contains($target, '://')) {
            return (string) (parse_url($target, PHP_URL_HOST) ?: $target);
        }

        // "example.com:5432" is a natural thing to type.
        if (substr_count($target, ':') === 1) {
            [$host] = explode(':', $target, 2);

            return $host;
        }

        return $target;
    }

    /** @param array<string,mixed> $monitor */
    public static function port(array $monitor): int
    {
        $config = Monitors::config($monitor);
        $port = (int) ($config['port'] ?? 0);

        if ($port < 1 || $port > 65535) {
            $target = trim((string) $monitor['target']);
            if (substr_count($target, ':') === 1) {
                $port = (int) explode(':', $target, 2)[1];
            }
        }

        return $port;
    }

    /**
     * @param array<string,mixed> $monitor
     * @param array{ok:bool,ms:int,code:string,error:string} $result
     */
    public static function toCheckResult(array $monitor, array $result): CheckResult
    {
        $config = Monitors::config($monitor);
        $host = self::host($monitor);
        $port = self::port($monitor);
        $meta = ['port' => $port];

        if (!$result['ok']) {
            return CheckResult::down($result['code'], $result['error'], null, null, $meta);
        }

        $expected = trim((string) ($config['banner'] ?? ''));
        if ($expected !== '') {
            $banner = trim(TcpProbe::readBanner($host, $port, 3.0));
            $meta['banner'] = mb_substr($banner, 0, 120);

            if ($banner === '') {
                return CheckResult::down(
                    'banner',
                    sprintf('Port %d is open, but the service said nothing within 3 seconds.', $port),
                    $result['ms'],
                    null,
                    $meta
                );
            }

            if (stripos($banner, $expected) === false) {
                return CheckResult::down(
                    'banner',
                    sprintf('Port %d answered with "%s", which does not contain "%s".', $port, mb_substr($banner, 0, 60), $expected),
                    $result['ms'],
                    null,
                    $meta
                );
            }
        }

        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);
        if ($degradedMs > 0 && $result['ms'] > $degradedMs) {
            return CheckResult::degraded(
                $result['ms'],
                sprintf('Connected in %d ms, over the %d ms threshold.', $result['ms'], $degradedMs),
                $result['ms'],
                null,
                $meta
            );
        }

        return CheckResult::up($result['ms'], $result['ms'], null, $meta);
    }
}
