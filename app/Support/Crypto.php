<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Authenticated encryption for secrets kept in the database (SMTP password,
 * Entra client secret). Keyed by APP_KEY from .env, so a database dump alone
 * never leaks credentials.
 */
final class Crypto
{
    private static ?string $key = null;

    public static function setKey(string $key): void
    {
        $raw = self::decodeKey($key);
        if (strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY must be 32 bytes, base64 encoded.');
        }
        self::$key = $raw;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function hasKey(): bool
    {
        return self::$key !== null;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'enc:v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public static function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, 'enc:v1:')) {
            return $ciphertext;
        }

        $raw = base64_decode(substr($ciphertext, 7), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        if (self::$key === null) {
            throw new RuntimeException('Encryption key is not configured. Set APP_KEY in .env.');
        }

        return self::$key;
    }

    private static function decodeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }
}
