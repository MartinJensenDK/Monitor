<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;

/**
 * TLS certificate check.
 *
 * A website check already notices a certificate that has broken the site. This
 * one is for the weeks before that: it watches the clock on the certificate,
 * turns amber while there is still time to renew, and goes down before the
 * first visitor sees a browser warning.
 *
 * It also answers the questions a fetch cannot — is the chain complete, does
 * the name still cover this host, did the issuer change — on hosts that serve
 * no website at all, such as a mail or database server.
 */
final class SslChecker implements CheckerInterface
{
    public const DEFAULT_PORT = 443;
    public const DEFAULT_WARN_DAYS = 14;
    public const DEFAULT_CRITICAL_DAYS = 3;

    public static function type(): string
    {
        return 'ssl';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $config = Monitors::config($monitor);
        $host = self::host($monitor);
        $port = self::port($monitor);

        $guard = TargetGuard::check($host);
        if (!$guard['allowed']) {
            return CheckResult::down('blocked_target', $guard['reason']);
        }

        $warnDays = self::days($config, 'warn_days', self::DEFAULT_WARN_DAYS);
        $criticalDays = self::days($config, 'critical_days', self::DEFAULT_CRITICAL_DAYS);

        $result = TlsInspector::inspect(
            $host,
            $port,
            trim((string) ($config['sni_host'] ?? '')),
            (bool) ($config['verify_chain'] ?? true),
            (float) max(1, (int) $monitor['timeout_seconds'])
        );

        if (!$result['ok'] || $result['cert'] === null) {
            return CheckResult::down($result['code'] ?: 'tls', $result['error'], null, null, ['port' => $port]);
        }

        $cert = $result['cert'];
        $expiresAt = $cert['valid_to'];
        $ms = $result['ms'];

        $meta = array_filter([
            'port' => $port,
            'subject' => $cert['subject'] !== '' ? $cert['subject'] : null,
            'names' => $cert['names'] !== [] ? array_slice($cert['names'], 0, 12) : null,
            'chain_length' => $result['chain_length'] ?: null,
            'protocol' => $result['protocol'] !== '' ? $result['protocol'] : null,
            'cipher' => $result['cipher'] !== '' ? $result['cipher'] : null,
            'serial' => $cert['serial'] !== '' ? $cert['serial'] : null,
            'self_signed' => $cert['self_signed'] ? true : null,
        ], static fn ($v): bool => $v !== null);

        if ($expiresAt !== null) {
            // These two keys are what the scheduler copies into monitor_status,
            // so the expiry meter and the reminder emails work unchanged.
            $meta['cert_expires_at'] = gmdate('Y-m-d H:i:s', $expiresAt);
            $meta['cert_issuer'] = $cert['issuer'];
            $meta['days_left'] = self::daysLeft($expiresAt);
        }

        $failure = self::inspect($monitor, $config, $host, $cert, $result, $criticalDays);
        if ($failure !== null) {
            return CheckResult::down($failure[0], $failure[1], $ms, null, $meta);
        }

        $daysLeft = $expiresAt === null ? null : self::daysLeft($expiresAt);

        if ($daysLeft !== null && $daysLeft <= $warnDays) {
            return CheckResult::degraded(
                $ms,
                sprintf(
                    'The certificate expires in %s, on %s.',
                    self::plural($daysLeft),
                    gmdate('j F Y', (int) $expiresAt)
                ),
                $ms,
                null,
                $meta
            );
        }

        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);
        if ($degradedMs > 0 && $ms > $degradedMs) {
            return CheckResult::degraded(
                $ms,
                sprintf('The handshake took %d ms, over the %d ms threshold.', $ms, $degradedMs),
                $ms,
                null,
                $meta
            );
        }

        return CheckResult::up($ms, $ms, null, $meta);
    }

    /**
     * Everything that makes a certificate unusable, in the order a person
     * would want to hear about it.
     *
     * @param array<string,mixed> $monitor
     * @param array<string,mixed> $config
     * @param array<string,mixed> $cert
     * @param array<string,mixed> $result
     * @return array{0:string,1:string}|null
     */
    private static function inspect(
        array $monitor,
        array $config,
        string $host,
        array $cert,
        array $result,
        int $criticalDays
    ): ?array {
        $expiresAt = $cert['valid_to'];

        if ($expiresAt === null) {
            return ['cert_unreadable', 'The certificate could not be read.'];
        }

        if ($expiresAt <= time()) {
            return ['cert_expired', sprintf(
                'The certificate expired on %s, %s ago.',
                gmdate('j F Y', (int) $expiresAt),
                self::plural((int) floor((time() - $expiresAt) / 86400))
            )];
        }

        if ($cert['valid_from'] !== null && $cert['valid_from'] > time()) {
            return ['cert_not_yet_valid', sprintf(
                'The certificate is not valid until %s.',
                gmdate('j F Y', (int) $cert['valid_from'])
            )];
        }

        if ((bool) ($config['check_hostname'] ?? true)) {
            $expected = trim((string) ($config['sni_host'] ?? '')) ?: $host;
            if (!TlsInspector::matchesHost($expected, $cert['names'])) {
                return ['cert_hostname', sprintf(
                    'The certificate does not cover %s. It is issued for %s.',
                    $expected,
                    implode(', ', array_slice($cert['names'], 0, 4)) ?: 'no host name at all'
                )];
            }
        }

        if ((bool) ($config['verify_chain'] ?? true) && !$result['trusted']) {
            return ['cert_untrusted', $cert['self_signed']
                ? sprintf('The certificate is self-signed, so no browser will trust it. Issuer: %s.', $cert['issuer'] ?: 'unknown')
                : sprintf(
                    'The certificate chain was rejected%s. An intermediate certificate is usually missing from the server.',
                    $result['trust_error'] !== '' ? ' (' . $result['trust_error'] . ')' : ''
                )];
        }

        $expectedIssuer = trim((string) ($config['expected_issuer'] ?? ''));
        if ($expectedIssuer !== '' && stripos($cert['issuer'], $expectedIssuer) === false) {
            return ['cert_issuer', sprintf(
                'The certificate was issued by %s, not by %s.',
                $cert['issuer'] ?: 'an unnamed authority',
                $expectedIssuer
            )];
        }

        $daysLeft = self::daysLeft($expiresAt);
        if ($daysLeft <= $criticalDays) {
            return ['cert_expiring', sprintf(
                'The certificate expires in %s, on %s — inside the %s this monitor allows.',
                self::plural($daysLeft),
                gmdate('j F Y', (int) $expiresAt),
                self::plural($criticalDays)
            )];
        }

        return null;
    }

    /** @param array<string,mixed> $monitor */
    public static function host(array $monitor): string
    {
        $target = trim((string) $monitor['target']);

        if (str_contains($target, '://')) {
            return (string) (parse_url($target, PHP_URL_HOST) ?: $target);
        }

        // "mail.example.com:993" is a natural thing to type.
        if (substr_count($target, ':') === 1) {
            return explode(':', $target, 2)[0];
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
            if (str_contains($target, '://')) {
                $port = (int) (parse_url($target, PHP_URL_PORT) ?: 0);
            } elseif (substr_count($target, ':') === 1) {
                $port = (int) explode(':', $target, 2)[1];
            }
        }

        return $port >= 1 && $port <= 65535 ? $port : self::DEFAULT_PORT;
    }

    public static function daysLeft(int $expiresAt): int
    {
        return (int) floor(($expiresAt - time()) / 86400);
    }

    /** @param array<string,mixed> $config */
    private static function days(array $config, string $key, int $default): int
    {
        $value = (int) ($config[$key] ?? $default);

        return $value >= 0 && $value <= 365 ? $value : $default;
    }

    private static function plural(int $days): string
    {
        return $days === 1 ? '1 day' : $days . ' days';
    }
}
