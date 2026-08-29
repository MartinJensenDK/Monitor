<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env reader. Deliberately dependency free so a self-hosted copy of
 * Monitor needs nothing but PHP and the vendor folder that ships with it.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$values = [];
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::$values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::$values[$key] ?? null;

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$values;
    }

    /**
     * Render a .env file body. Values are quoted so passwords containing
     * spaces or "#" survive a round trip.
     *
     * @param array<string,string> $values
     */
    public static function render(array $values): string
    {
        $out = "# Monitor configuration\n# Generated " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
        foreach ($values as $key => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $value);
            $out .= $key . '="' . $escaped . "\"\n";
        }

        return $out;
    }
}
