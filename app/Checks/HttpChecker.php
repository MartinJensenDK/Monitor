<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;
use App\Support\Crypto;
use CurlHandle;

/**
 * Website check: fetches the URL and decides up / degraded / down from the
 * status code, an optional keyword, and the response time threshold.
 *
 * prepare()/finish() exist so the scheduler can run many of these at once
 * through curl_multi; run() is the same thing for a single check.
 */
final class HttpChecker implements CheckerInterface
{
    public static function type(): string
    {
        return 'http';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $handle = self::prepare($monitor);
        if ($handle instanceof CheckResult) {
            return $handle;
        }

        curl_exec($handle);
        $result = self::finish($monitor, $handle);
        curl_close($handle);

        return $result;
    }

    /**
     * @param array<string,mixed> $monitor
     * @return CurlHandle|CheckResult a handle to add to curl_multi, or an
     *                                immediate failure (blocked target)
     */
    public static function prepare(array $monitor): CurlHandle|CheckResult
    {
        $config = Monitors::config($monitor);
        $url = (string) $monitor['target'];
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        $guard = TargetGuard::check($host);
        if (!$guard['allowed']) {
            return CheckResult::down('blocked_target', $guard['reason']);
        }

        $handle = curl_init();
        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $timeout = max(1, (int) $monitor['timeout_seconds']);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => (bool) ($config['follow_redirects'] ?? true),
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => (bool) ($config['verify_ssl'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($config['verify_ssl'] ?? true) ? 2 : 0,
            CURLOPT_CERTINFO => true,
            CURLOPT_USERAGENT => (string) ($config['user_agent'] ?? 'Monitor/1.0 (+uptime monitor)'),
            CURLOPT_ENCODING => '',
            CURLOPT_BUFFERSIZE => 16384,
            // A monitor should not download a 2 GB file to learn the site is up.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($h, $downloadSize, $downloaded): int => $downloaded > 2_000_000 ? 1 : 0,
        ];

        $headers = [];
        foreach ((array) ($config['headers'] ?? []) as $name => $value) {
            if (is_string($name) && trim($name) !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }
        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $body = (string) ($config['body'] ?? '');
        if ($body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $username = (string) ($config['auth_username'] ?? '');
        if ($username !== '') {
            // Stored encrypted; decrypt() passes plain values through unchanged.
            $options[CURLOPT_USERPWD] = $username . ':' . Crypto::decrypt((string) ($config['auth_password'] ?? ''));
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Turn a finished handle into a result.
     *
     * curl_errno() only reports on a handle that ran through curl_exec(); after
     * curl_multi the outcome arrives via curl_multi_info_read(), so the caller
     * passes it in.
     *
     * @param array<string,mixed> $monitor
     */
    public static function finish(array $monitor, CurlHandle $handle, ?string $body = null, ?int $errno = null): CheckResult
    {
        $config = Monitors::config($monitor);
        $errno ??= curl_errno($handle);
        $info = curl_getinfo($handle);

        $totalMs = (int) round(((float) ($info['total_time'] ?? 0)) * 1000);
        $connectMs = (int) round(((float) ($info['connect_time'] ?? 0)) * 1000);
        $httpCode = (int) ($info['http_code'] ?? 0);
        $primaryIp = (string) ($info['primary_ip'] ?? '');

        $meta = array_filter([
            'ip' => $primaryIp !== '' ? $primaryIp : null,
            'redirects' => (int) ($info['redirect_count'] ?? 0) ?: null,
            'size' => (int) ($info['size_download'] ?? 0) ?: null,
            'ttfb_ms' => isset($info['starttransfer_time']) ? (int) round((float) $info['starttransfer_time'] * 1000) : null,
            'final_url' => ($info['url'] ?? '') !== (string) $monitor['target'] ? (string) ($info['url'] ?? '') : null,
        ], static fn ($v): bool => $v !== null);

        $cert = self::certificate($info);
        if ($cert !== null) {
            $meta['cert_expires_at'] = $cert['expires_at'];
            $meta['cert_issuer'] = $cert['issuer'];
        }

        // The IP curl actually reached, after any redirects.
        if ($primaryIp !== '' && !TargetGuard::allowsPrivate() && TargetGuard::isPrivateIp($primaryIp)) {
            return CheckResult::down(
                'blocked_target',
                'The request ended up at the private address ' . $primaryIp . '. Turn on "Allow private targets" to monitor internal hosts.',
                $totalMs,
                $httpCode ?: null,
                $meta
            );
        }

        if ($errno !== 0) {
            return CheckResult::down(
                self::errorCode($errno),
                self::errorMessage($errno, curl_error($handle), (string) $monitor['target']),
                $totalMs > 0 ? $totalMs : null,
                $httpCode ?: null,
                $meta
            );
        }

        $expected = (string) ($config['expected_status'] ?? '200-299');
        if (!self::statusMatches($httpCode, $expected)) {
            return CheckResult::down(
                'status_mismatch',
                sprintf('Expected HTTP %s but got %d.', $expected, $httpCode),
                $totalMs,
                $httpCode,
                $meta
            );
        }

        $keyword = trim((string) ($config['keyword'] ?? ''));
        if ($keyword !== '') {
            $haystack = $body ?? (string) curl_multi_getcontent($handle);
            $found = stripos($haystack, $keyword) !== false;
            $shouldBeAbsent = (bool) ($config['keyword_absent'] ?? false);

            if ($found === $shouldBeAbsent) {
                return CheckResult::down(
                    'keyword',
                    $shouldBeAbsent
                        ? sprintf('The page contains "%s", which should be absent.', $keyword)
                        : sprintf('The page did not contain "%s".', $keyword),
                    $totalMs,
                    $httpCode,
                    $meta
                );
            }
        }

        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);
        if ($degradedMs > 0 && $totalMs > $degradedMs) {
            return CheckResult::degraded(
                $totalMs,
                sprintf('Responded in %d ms, over the %d ms threshold.', $totalMs, $degradedMs),
                $connectMs,
                $httpCode,
                $meta
            );
        }

        return CheckResult::up($totalMs, $connectMs, $httpCode, $meta);
    }

    /**
     * @param array<string,mixed> $info
     * @return array{expires_at:string,issuer:string}|null
     */
    private static function certificate(array $info): ?array
    {
        $certs = $info['certinfo'] ?? null;
        if (!is_array($certs) || $certs === []) {
            return null;
        }

        $leaf = $certs[0];
        $expires = $leaf['Expire date'] ?? $leaf['Expire Date'] ?? null;
        if (!is_string($expires)) {
            return null;
        }

        $timestamp = strtotime($expires);
        if ($timestamp === false) {
            return null;
        }

        $issuer = '';
        if (isset($leaf['Issuer']) && is_string($leaf['Issuer'])) {
            preg_match('/(?:^|,\s*)(?:O|CN)\s*=\s*([^,]+)/', $leaf['Issuer'], $m);
            $issuer = trim($m[1] ?? $leaf['Issuer']);
        }

        return ['expires_at' => gmdate('Y-m-d H:i:s', $timestamp), 'issuer' => mb_substr($issuer, 0, 190)];
    }

    /** Accepts "200", "200-299", "200,301,302" or a mix of those. */
    public static function statusMatches(int $code, string $expected): bool
    {
        $expected = trim($expected);
        if ($expected === '') {
            return $code >= 200 && $code < 300;
        }

        foreach (explode(',', $expected) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '-')) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));
                if ($code >= $from && $code <= $to) {
                    return true;
                }
                continue;
            }
            if ($code === (int) $part) {
                return true;
            }
        }

        return false;
    }

    public static function errorCode(int $errno): string
    {
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'dns',
            CURLE_COULDNT_CONNECT => 'connection_refused',
            CURLE_OPERATION_TIMEOUTED => 'timeout',
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE => 'tls',
            CURLE_TOO_MANY_REDIRECTS => 'redirect_loop',
            default => 'curl_' . $errno,
        };
    }

    /** Turn curl's phrasing into something a person can act on. */
    public static function errorMessage(int $errno, string $raw, string $target): string
    {
        $host = (string) (parse_url($target, PHP_URL_HOST) ?: $target);

        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => sprintf('The name %s did not resolve.', $host),
            CURLE_COULDNT_CONNECT => sprintf('%s refused the connection.', $host),
            CURLE_OPERATION_TIMEOUTED => 'The request timed out.',
            CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE => sprintf('The TLS certificate for %s was rejected: %s', $host, $raw),
            CURLE_SSL_CONNECT_ERROR => sprintf('The TLS handshake with %s failed: %s', $host, $raw),
            CURLE_TOO_MANY_REDIRECTS => 'The URL redirected too many times.',
            default => $raw !== '' ? $raw : 'The request failed.',
        };
    }
}
