<?php

declare(strict_types=1);

namespace App\Entra;

use App\Core\Config;
use RuntimeException;

/**
 * Verifies the ID token Microsoft returns.
 *
 * The token arrives over TLS straight from the token endpoint, which OpenID
 * Connect says is enough on its own — but a signature check costs one cached
 * HTTP request and removes the whole class of "what if the channel lied"
 * questions, so this does both: RS256 against the directory's published keys,
 * then every claim that matters.
 */
final class Jwt
{
    private const CACHE_SECONDS = 86400;

    /**
     * @return array<string,mixed> the verified claims
     */
    public static function verify(string $token, string $expectedNonce): array
    {
        [$header, $payload, $signingInput, $signature] = self::split($token);

        $algorithm = (string) ($header['alg'] ?? '');
        if ($algorithm !== 'RS256') {
            throw new RuntimeException('The sign-in token uses an unexpected algorithm (' . $algorithm . ').');
        }

        self::checkSignature($signingInput, $signature, (string) ($header['kid'] ?? ''));
        self::checkClaims($payload, $expectedNonce);

        return $payload;
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string}
     */
    private static function split(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('The sign-in token is malformed.');
        }

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('The sign-in token could not be read.');
        }

        return [$header, $payload, $parts[0] . '.' . $parts[1], self::base64UrlDecode($parts[2])];
    }

    private static function checkSignature(string $signingInput, string $signature, string $kid): void
    {
        $key = self::publicKey($kid);

        if (openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The sign-in token\'s signature does not match the directory\'s keys.');
        }
    }

    /** @param array<string,mixed> $claims */
    private static function checkClaims(array $claims, string $expectedNonce): void
    {
        $now = time();
        $leeway = 120;

        $issuer = (string) ($claims['iss'] ?? '');
        if ($issuer !== Entra::issuer()) {
            throw new RuntimeException('The sign-in token came from a different directory than the one configured.');
        }

        $audience = $claims['aud'] ?? '';
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array(Entra::clientId(), array_map('strval', $audiences), true)) {
            throw new RuntimeException('The sign-in token was issued for a different application.');
        }

        if ((int) ($claims['exp'] ?? 0) < $now - $leeway) {
            throw new RuntimeException('The sign-in token has expired. Try signing in again.');
        }

        if ((int) ($claims['nbf'] ?? 0) > $now + $leeway) {
            throw new RuntimeException('The sign-in token is not valid yet.');
        }

        $nonce = (string) ($claims['nonce'] ?? '');
        if ($expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw new RuntimeException('The sign-in did not match the request that started it. Start again from the sign-in page.');
        }

        $tenant = (string) ($claims['tid'] ?? '');
        if ($tenant !== '' && Entra::tenantId() !== 'common' && $tenant !== Entra::tenantId()) {
            throw new RuntimeException('That account belongs to a different directory.');
        }
    }

    /** Public key for a key id, from the cached JWKS — refetched if unknown. */
    private static function publicKey(string $kid): string
    {
        $keys = self::jwks(false);

        if (!isset($keys[$kid])) {
            // Microsoft rotates signing keys; an unknown one means our copy is stale.
            $keys = self::jwks(true);
        }

        if (!isset($keys[$kid])) {
            throw new RuntimeException('The directory signed the token with a key we do not know.');
        }

        return $keys[$kid];
    }

    /** @return array<string,string> key id => PEM */
    private static function jwks(bool $force): array
    {
        $file = Config::string('paths.storage') . '/cache/entra-jwks.json';

        if (!$force && is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && $cached !== []) {
                return array_map('strval', $cached);
            }
        }

        $document = Http::getJson(Entra::jwksUri());
        $keys = [];

        foreach ((array) ($document['keys'] ?? []) as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }
            $kid = (string) ($key['kid'] ?? '');
            $modulus = (string) ($key['n'] ?? '');
            $exponent = (string) ($key['e'] ?? '');

            if ($kid === '' || $modulus === '' || $exponent === '') {
                continue;
            }

            $keys[$kid] = self::pem(self::base64UrlDecode($modulus), self::base64UrlDecode($exponent));
        }

        if ($keys === []) {
            throw new RuntimeException('The directory published no usable signing keys.');
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        @file_put_contents($file, (string) json_encode($keys));

        return $keys;
    }

    /**
     * Build a PEM public key from the raw modulus and exponent in the JWKS.
     * DER by hand, because a JWK is not something openssl reads directly.
     */
    private static function pem(string $modulus, string $exponent): string
    {
        $rsaKey = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));

        $algorithm = self::derSequence(
            // OID 1.2.840.113549.1.1.1 (rsaEncryption), then NULL parameters.
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
        );

        $bitString = "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $der = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        // A leading bit of 1 would make the integer negative, so pad it.
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
