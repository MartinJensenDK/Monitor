<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * Opens a TLS connection and reads the certificate off it.
 *
 * The handshake happens twice when something is wrong, and that is deliberate.
 * A verifying handshake that fails tells you nothing but "verify failed" — no
 * dates, no issuer, no subject. So the certificate is always captured on a
 * permissive handshake first, and trust is established separately. A healthy
 * host therefore pays for one handshake; a broken one pays for two and gets an
 * answer that names the actual problem.
 */
final class TlsInspector
{
    /**
     * @return array{
     *     ok:bool, ms:int, code:string, error:string,
     *     cert:array<string,mixed>|null, chain_length:int,
     *     trusted:bool, trust_error:string, protocol:string, cipher:string
     * }
     */
    public static function inspect(string $host, int $port, string $sniHost, bool $verifyChain, float $timeout): array
    {
        $sni = $sniHost !== '' ? $sniHost : $host;

        $handshake = self::connect($host, $port, $sni, false, $timeout);
        if ($handshake['cert'] === null) {
            return [
                'ok' => false,
                'ms' => $handshake['ms'],
                'code' => $handshake['code'],
                'error' => $handshake['error'],
                'cert' => null,
                'chain_length' => 0,
                'trusted' => false,
                'trust_error' => '',
                'protocol' => '',
                'cipher' => '',
            ];
        }

        $trusted = true;
        $trustError = '';

        if ($verifyChain) {
            $verified = self::connect($host, $port, $sni, true, $timeout);
            $trusted = $verified['cert'] !== null;
            $trustError = $verified['reason'] !== '' ? $verified['reason'] : $verified['error'];
        }

        return [
            'ok' => true,
            'ms' => $handshake['ms'],
            'code' => '',
            'error' => '',
            'cert' => self::describe($handshake['cert']),
            'chain_length' => $handshake['chain_length'],
            'trusted' => $trusted,
            'trust_error' => $trustError,
            'protocol' => $handshake['protocol'],
            'cipher' => $handshake['cipher'],
        ];
    }

    /**
     * @return array{cert:mixed,chain_length:int,ms:int,code:string,error:string,reason:string,protocol:string,cipher:string}
     */
    private static function connect(string $host, int $port, string $sni, bool $verify, float $timeout): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => $verify,
                // The name is matched here rather than by OpenSSL, so the
                // failure can say which names the certificate does carry.
                'verify_peer_name' => false,
                'allow_self_signed' => !$verify,
                'SNI_enabled' => true,
                'peer_name' => $sni,
            ],
        ]);

        $errno = 0;
        $error = '';
        $address = self::address($host, $port);

        // OpenSSL puts the reason a chain was rejected ("certificate has
        // expired", "unable to get local issuer certificate") in a warning,
        // not in the $error out-parameter, so it is caught rather than muted.
        $warnings = [];
        set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        $startedAt = microtime(true);
        $stream = stream_socket_client(
            'ssl://' . $address,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        restore_error_handler();

        $reason = self::reasonFrom($warnings);
        if ($reason !== '') {
            $error = $reason;
        }

        if ($stream === false) {
            return [
                'cert' => null,
                'chain_length' => 0,
                'ms' => $ms,
                'code' => self::classify($error, $errno),
                'error' => self::readable($error, $host, $port, $timeout),
                'reason' => $reason,
                'protocol' => '',
                'cipher' => '',
            ];
        }

        $params = stream_context_get_params($stream);
        $meta = stream_get_meta_data($stream);
        $crypto = $meta['crypto'] ?? [];
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];

        fclose($stream);

        return [
            'cert' => $params['options']['ssl']['peer_certificate'] ?? null,
            'chain_length' => is_array($chain) ? count($chain) : 0,
            'ms' => max(1, $ms),
            'code' => '',
            'error' => '',
            'reason' => '',
            'protocol' => (string) ($crypto['protocol'] ?? ''),
            'cipher' => (string) ($crypto['cipher_name'] ?? ''),
        ];
    }

    /**
     * Everything the interface shows about a certificate, in plain values.
     *
     * @return array<string,mixed>
     */
    private static function describe(mixed $certificate): array
    {
        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            return ['subject' => '', 'issuer' => '', 'names' => [], 'valid_from' => null, 'valid_to' => null, 'serial' => '', 'self_signed' => false];
        }

        $names = [];
        $commonName = (string) ($parsed['subject']['CN'] ?? '');
        if ($commonName !== '') {
            $names[] = strtolower($commonName);
        }

        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $names[] = strtolower(trim(substr($entry, 4)));
            }
        }

        return [
            'subject' => $commonName,
            'issuer' => self::issuerName($parsed['issuer'] ?? []),
            'names' => array_values(array_unique($names)),
            'valid_from' => isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
            'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            'signature' => (string) ($parsed['signatureTypeSN'] ?? ''),
            'self_signed' => ($parsed['subject'] ?? []) === ($parsed['issuer'] ?? []),
        ];
    }

    /** @param array<string,mixed> $issuer */
    private static function issuerName(array $issuer): string
    {
        // The organisation is what people recognise ("Let's Encrypt"); the CN
        // is the intermediate's name and only useful when there is no O.
        foreach (['O', 'CN', 'OU'] as $key) {
            $value = $issuer[$key] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 190);
            }
        }

        return '';
    }

    /**
     * Hostname matching, including a single leading wildcard label.
     *
     * @param array<int,string> $names
     */
    public static function matchesHost(string $host, array $names): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        foreach ($names as $name) {
            $name = strtolower(rtrim(trim($name), '.'));

            if ($name === $host) {
                return true;
            }

            // *.example.com covers a.example.com but not a.b.example.com
            // and not example.com itself.
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count($name, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function address(string $host, int $port): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']:' . $port
            : $host . ':' . $port;
    }

    /**
     * A failed handshake raises several warnings in a row and the last one is
     * always the useless "Unable to connect". The one worth showing is the
     * OpenSSL line that names the reason.
     *
     * @param array<int,string> $warnings
     */
    private static function reasonFrom(array $warnings): string
    {
        foreach ($warnings as $warning) {
            foreach (preg_split('/\R/', $warning) ?: [] as $line) {
                $line = trim($line);
                if (stripos($line, 'error:') !== 0) {
                    continue;
                }

                // error:0A000086:SSL routines::certificate verify failed
                $parts = explode(':', $line);
                $reason = trim((string) end($parts));
                if ($reason !== '') {
                    return $reason;
                }
            }
        }

        return '';
    }

    private static function classify(string $error, int $errno): string
    {
        return match (true) {
            str_contains($error, 'certificate verify failed'), str_contains($error, 'self signed') => 'tls',
            str_contains($error, 'Connection refused') => 'connection_refused',
            str_contains($error, 'timed out'), $errno === 110 => 'timeout',
            str_contains($error, 'getaddrinfo'), str_contains($error, 'Name or service not known') => 'dns',
            default => 'tls',
        };
    }

    private static function readable(string $error, string $host, int $port, float $timeout): string
    {
        $error = trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $error) ?? $error);

        return match (self::classify($error, 0)) {
            'connection_refused' => sprintf('%s refused the connection on port %d.', $host, $port),
            'timeout' => sprintf('%s did not complete the TLS handshake within %d seconds.', $host, (int) $timeout),
            'dns' => sprintf('The name %s did not resolve.', $host),
            default => $error === ''
                ? sprintf('The TLS handshake with %s failed.', $host)
                : sprintf('The TLS handshake with %s failed: %s', $host, $error),
        };
    }
}
