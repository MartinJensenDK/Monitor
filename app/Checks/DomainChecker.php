<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;

/**
 * Domain registration check.
 *
 * The outage nobody sees coming: the site is fine, the certificate is fine,
 * and the domain quietly lapses because the renewal notice went to an inbox
 * that no longer exists. This reads the registry — RDAP where it exists, WHOIS
 * where it does not — and counts down to the expiry date.
 *
 * It also watches the registry status, because a domain put on clientHold
 * stops resolving hours before it expires.
 */
final class DomainChecker implements CheckerInterface
{
    public const DEFAULT_WARN_DAYS = 30;
    public const DEFAULT_CRITICAL_DAYS = 7;

    /** Registry statuses that stop a domain working, or are about to. */
    private const BAD_STATUSES = [
        'clienthold' => 'the registrar has put it on hold',
        'serverhold' => 'the registry has put it on hold',
        'pendingdelete' => 'it is pending deletion',
        'redemptionperiod' => 'it has lapsed and is in the redemption period',
        'deleted' => 'the registry has deleted it',
    ];

    public static function type(): string
    {
        return 'domain';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $config = Monitors::config($monitor);
        $domain = DomainRegistry::normalise((string) $monitor['target']);

        $warnDays = self::days($config, 'warn_days', self::DEFAULT_WARN_DAYS);
        $criticalDays = self::days($config, 'critical_days', self::DEFAULT_CRITICAL_DAYS);

        $registry = DomainRegistry::lookup($domain, (float) max(5, (int) $monitor['timeout_seconds']));
        $ms = max(1, (int) $registry['ms']);

        if (!$registry['ok']) {
            return CheckResult::down((string) $registry['code'], (string) $registry['error'], $ms);
        }

        $expiresAt = $registry['expires_at'];
        $daysLeft = $expiresAt === null ? null : (int) floor(($expiresAt - time()) / 86400);

        $meta = array_filter([
            'source' => $registry['source'],
            'registrar' => $registry['registrar'] !== '' ? $registry['registrar'] : null,
            'statuses' => $registry['statuses'] !== [] ? array_slice($registry['statuses'], 0, 8) : null,
            'nameservers' => $registry['nameservers'] !== [] ? array_slice($registry['nameservers'], 0, 8) : null,
            'created_at' => $registry['created_at'] === null ? null : gmdate('Y-m-d', (int) $registry['created_at']),
            'days_left' => $daysLeft,
        ], static fn ($v): bool => $v !== null);

        if ($expiresAt !== null) {
            // Shared with the certificate check: the scheduler copies these
            // into monitor_status, which drives the expiry meter and the
            // "expires soon" reminders.
            $meta['cert_expires_at'] = gmdate('Y-m-d H:i:s', $expiresAt);
            $meta['cert_issuer'] = $registry['registrar'];
        }

        if ($expiresAt !== null && $expiresAt <= time()) {
            return CheckResult::down(
                'domain_expired',
                sprintf('The registration expired on %s.', gmdate('j F Y', $expiresAt)),
                $ms,
                null,
                $meta
            );
        }

        if ((bool) ($config['watch_status'] ?? true)) {
            foreach ($registry['statuses'] as $status) {
                $key = str_replace([' ', '-'], '', strtolower($status));
                if (isset(self::BAD_STATUSES[$key])) {
                    return CheckResult::down(
                        'domain_status',
                        sprintf('%s is not resolving: %s (%s).', $domain, self::BAD_STATUSES[$key], $status),
                        $ms,
                        null,
                        $meta
                    );
                }
            }
        }

        $expectedRegistrar = trim((string) ($config['expected_registrar'] ?? ''));
        if ($expectedRegistrar !== '' && stripos($registry['registrar'], $expectedRegistrar) === false) {
            return CheckResult::down(
                'domain_registrar',
                sprintf(
                    'The domain is registered with %s, not with %s. A transfer you did not start is worth looking into.',
                    $registry['registrar'] !== '' ? $registry['registrar'] : 'an unnamed registrar',
                    $expectedRegistrar
                ),
                $ms,
                null,
                $meta
            );
        }

        $expectedNameservers = self::expectedNameservers($config);
        if ($expectedNameservers !== [] && $registry['nameservers'] !== []) {
            $missing = array_diff($expectedNameservers, $registry['nameservers']);
            if ($missing !== []) {
                return CheckResult::down(
                    'domain_nameservers',
                    sprintf(
                        'The registry delegates %s to %s, and no longer to %s.',
                        $domain,
                        implode(', ', array_slice($registry['nameservers'], 0, 4)),
                        implode(', ', $missing)
                    ),
                    $ms,
                    null,
                    $meta
                );
            }
        }

        if ($daysLeft !== null && $daysLeft <= $criticalDays) {
            return CheckResult::down(
                'domain_expiring',
                sprintf(
                    'The registration expires in %s, on %s — inside the %s this monitor allows. Renew it now.',
                    self::plural($daysLeft),
                    gmdate('j F Y', (int) $expiresAt),
                    self::plural($criticalDays)
                ),
                $ms,
                null,
                $meta
            );
        }

        if ($daysLeft !== null && $daysLeft <= $warnDays) {
            return CheckResult::degraded(
                $ms,
                sprintf(
                    'The registration expires in %s, on %s.',
                    self::plural($daysLeft),
                    gmdate('j F Y', (int) $expiresAt)
                ),
                $ms,
                null,
                $meta
            );
        }

        return CheckResult::up($ms, $ms, null, $meta);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,string>
     */
    public static function expectedNameservers(array $config): array
    {
        $raw = $config['expected_nameservers'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        $servers = [];
        foreach ((array) $raw as $server) {
            $server = strtolower(rtrim(trim((string) $server), '.'));
            if ($server !== '') {
                $servers[] = $server;
            }
        }

        return array_values(array_unique($servers));
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
