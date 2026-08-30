<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;

/**
 * DNS record check.
 *
 * Two questions, really. Does the name still resolve, and does it still
 * resolve to what you published? A record that quietly changes — an A record
 * pointed at an old server, an MX record dropped during a migration, an SPF
 * record edited by someone else — breaks things that look fine from the
 * outside for hours.
 *
 * Several resolvers can be asked at once. When they disagree, the record is
 * mid-propagation or one of them is stale, and that is worth knowing on its own.
 */
final class DnsChecker implements CheckerInterface
{
    public const MODES = [
        'contains' => 'must all be in the answer',
        'exact' => 'must be exactly the answer',
        'absent' => 'must not be in the answer',
    ];

    public static function type(): string
    {
        return 'dns';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $config = Monitors::config($monitor);
        $name = DnsResolver::normalise((string) $monitor['target']);
        $type = self::recordType($config);
        $timeout = (float) max(1, (int) $monitor['timeout_seconds']);

        $resolvers = self::resolvers($config);
        if ($resolvers === []) {
            return CheckResult::down('dns_no_resolver', 'This server publishes no resolver to ask, so name one on the monitor.');
        }

        $answers = [];
        $slowest = 0;

        foreach ($resolvers as $resolver) {
            $answer = DnsResolver::query($name, $type, $resolver, $timeout);
            $slowest = max($slowest, (int) $answer['ms']);

            if (!$answer['ok']) {
                return CheckResult::down(
                    self::codeFor((string) $answer['rcode']),
                    count($resolvers) > 1
                        ? sprintf('%s, asked for the %s record of %s: %s', $resolver, $type, $name, $answer['error'])
                        : $answer['error'],
                    $answer['ms'] > 0 ? (int) $answer['ms'] : null,
                    null,
                    ['record_type' => $type, 'resolver' => $resolver]
                );
            }

            $answers[$resolver] = $answer;
        }

        $first = reset($answers);
        $records = $first['records'];

        $meta = array_filter([
            'record_type' => $type,
            'resolvers' => $resolvers,
            'records' => array_slice($records, 0, 12),
            'ttl' => $first['ttl'],
        ], static fn ($v): bool => $v !== null && $v !== []);

        if ($records === [] && (string) ($config['dns_mode'] ?? 'contains') !== 'absent') {
            return CheckResult::down(
                'dns_no_records',
                sprintf('%s has no %s record.', $name, $type),
                $slowest,
                null,
                $meta
            );
        }

        $disagreement = self::disagreement($answers);
        if ($disagreement !== null) {
            return CheckResult::down('dns_disagreement', $disagreement, $slowest, null, $meta);
        }

        $failure = self::compare($config, $name, $type, $records);
        if ($failure !== null) {
            return CheckResult::down('dns_mismatch', $failure, $slowest, null, $meta);
        }

        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);
        if ($degradedMs > 0 && $slowest > $degradedMs) {
            return CheckResult::degraded(
                $slowest,
                sprintf('The lookup took %d ms, over the %d ms threshold.', $slowest, $degradedMs),
                $slowest,
                null,
                $meta
            );
        }

        return CheckResult::up(max(1, $slowest), $slowest, null, $meta);
    }

    /**
     * Every resolver must return the same set, or the record is not the same
     * everywhere it is being read from.
     *
     * @param array<string,array<string,mixed>> $answers
     */
    private static function disagreement(array $answers): ?string
    {
        if (count($answers) < 2) {
            return null;
        }

        $signatures = [];
        foreach ($answers as $resolver => $answer) {
            $records = array_map(self::normalise(...), $answer['records']);
            sort($records);
            $signatures[$resolver] = implode(', ', $records);
        }

        $unique = array_unique($signatures);
        if (count($unique) === 1) {
            return null;
        }

        $lines = [];
        foreach ($signatures as $resolver => $signature) {
            $lines[] = sprintf('%s sees %s', $resolver, $signature === '' ? 'nothing' : $signature);
        }

        return 'The resolvers do not agree: ' . implode('; ', $lines) . '.';
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,string> $records
     */
    private static function compare(array $config, string $name, string $type, array $records): ?string
    {
        $expected = self::expected($config);
        if ($expected === []) {
            return null;
        }

        $mode = (string) ($config['dns_mode'] ?? 'contains');
        $actual = array_map(self::normalise(...), $records);
        $shown = implode(', ', $records) ?: 'nothing';

        if ($mode === 'exact') {
            $wanted = array_map(self::normalise(...), $expected);
            sort($wanted);
            $seen = $actual;
            sort($seen);

            return $wanted === $seen
                ? null
                : sprintf(
                    'The %s record of %s is %s, and should be exactly %s.',
                    $type,
                    $name,
                    $shown,
                    implode(', ', $expected)
                );
        }

        if ($mode === 'absent') {
            $present = array_values(array_filter(
                $expected,
                static fn (string $value): bool => self::present($value, $actual)
            ));

            return $present === []
                ? null
                : sprintf(
                    'The %s record of %s contains %s, which should not be there.',
                    $type,
                    $name,
                    implode(', ', $present)
                );
        }

        $missing = array_values(array_filter(
            $expected,
            static fn (string $value): bool => !self::present($value, $actual)
        ));

        return $missing === []
            ? null
            : sprintf(
                'The %s record of %s is %s, and is missing %s.',
                $type,
                $name,
                $shown,
                implode(', ', $missing)
            );
    }

    /**
     * A record is "present" when it matches outright or contains the expected
     * value as a whole — so an MX of "10 mail.example.com" is matched by
     * typing just the host, and an SPF include is matched inside the TXT.
     *
     * @param array<int,string> $actual
     */
    private static function present(string $expected, array $actual): bool
    {
        $needle = self::normalise($expected);

        foreach ($actual as $record) {
            if ($record === $needle || str_contains($record, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }

    /** @param array<string,mixed> $config */
    public static function recordType(array $config): string
    {
        $type = strtoupper(trim((string) ($config['record_type'] ?? 'A')));

        return isset(DnsResolver::TYPES[$type]) ? $type : 'A';
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,string>
     */
    public static function resolvers(array $config): array
    {
        $raw = $config['resolvers'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        $resolvers = [];
        foreach ((array) $raw as $resolver) {
            $resolver = trim((string) $resolver);
            if ($resolver !== '' && DnsResolver::isValidServer($resolver)) {
                $resolvers[] = $resolver;
            }
        }

        if ($resolvers !== []) {
            return array_values(array_unique($resolvers));
        }

        // Nothing chosen: ask this server's own resolver, and say which it was.
        return array_slice(DnsResolver::systemResolvers(), 0, 1);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,string>
     */
    public static function expected(array $config): array
    {
        $raw = $config['expected_records'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/\R/', $raw) ?: [];
        }

        $values = [];
        foreach ((array) $raw as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private static function codeFor(string $rcode): string
    {
        return match ($rcode) {
            'NXDOMAIN' => 'dns_nxdomain',
            'SERVFAIL' => 'dns_servfail',
            'REFUSED' => 'dns_refused',
            default => 'dns',
        };
    }
}
