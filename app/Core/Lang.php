<?php

declare(strict_types=1);

namespace App\Core;

final class Lang
{
    /** @var array<string,string> */
    private static array $lines = [];

    /** @var array<string,string> */
    private static array $fallback = [];

    private static string $locale = 'en';

    public static function load(string $path, string $locale): void
    {
        self::$locale = $locale;
        $fallbackFile = $path . '/en.php';
        self::$fallback = is_file($fallbackFile) ? (array) require $fallbackFile : [];

        $file = $path . '/' . preg_replace('/[^a-z_\-]/', '', $locale) . '.php';
        self::$lines = is_file($file) ? (array) require $file : self::$fallback;
    }

    /**
     * Locale codes that have a file in resources/lang. Drop a new file in and it
     * shows up in Settings — no code change needed.
     *
     * @return array<int,string>
     */
    public static function available(string $path): array
    {
        $files = glob($path . '/*.php') ?: [];
        $locales = array_map(static fn (string $f): string => basename($f, '.php'), $files);
        sort($locales);

        return $locales;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string,string|int> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $line = self::$lines[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($replace as $search => $value) {
            $line = str_replace(':' . $search, (string) $value, $line);
        }

        return $line;
    }
}
