<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\View;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /** @param array<string,string|int> $replace */
    function t(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $base = rtrim(Config::string('app.url'), '/');

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file = Config::string('paths.public') . '/' . ltrim($path, '/');
        $version = is_file($file) ? substr((string) filemtime($file), -6) : '1';

        return '/' . ltrim($path, '/') . '?v=' . $version;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        $old = View::shared()['old'] ?? [];
        $value = is_array($old) ? ($old[$key] ?? $default) : $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('format_ms')) {
    function format_ms(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }

        return rtrim(rtrim(number_format($ms / 1000, 2, '.', ''), '0'), '.') . ' s';
    }
}

if (!function_exists('format_uptime')) {
    function format_uptime(?float $ratio): string
    {
        if ($ratio === null) {
            return '—';
        }
        $percent = $ratio * 100;
        if ($percent >= 100) {
            return '100%';
        }

        // More decimals near the top, where the difference actually matters.
        $decimals = $percent >= 99.9 ? 3 : ($percent >= 99 ? 2 : 1);

        return number_format($percent, $decimals, '.', '') . '%';
    }
}

if (!function_exists('format_duration')) {
    function format_duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
        }

        return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
    }
}

if (!function_exists('format_since')) {
    function format_since(?string $utcDateTime): string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return '—';
        }

        $timestamp = strtotime($utcDateTime . ' UTC');
        if ($timestamp === false) {
            return '—';
        }

        $delta = max(0, time() - $timestamp);

        return $delta < 10 ? t('time.just_now') : t('time.ago', ['time' => format_duration($delta)]);
    }
}

if (!function_exists('local_time')) {
    function local_time(?string $utcDateTime, string $format = 'Y-m-d H:i:s'): string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return '—';
        }

        try {
            $date = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));
            $user = Auth::user();
            $tz = is_array($user) && is_string($user['timezone'] ?? null) && $user['timezone'] !== ''
                ? $user['timezone']
                : Config::string('app.timezone', 'UTC');

            return $date->setTimezone(new DateTimeZone($tz))->format($format);
        } catch (Throwable) {
            return $utcDateTime;
        }
    }
}

if (!function_exists('avatar')) {
    /**
     * Someone's face, or their initials when there is no photo.
     *
     * The URL carries the photo's own timestamp, so a picture that changes in
     * the directory arrives at a new address and browsers stop showing the old
     * one. The alt text is deliberately empty: every place this appears puts
     * the person's name right beside it, and a screen reader should not have
     * to hear it twice.
     *
     * @param array<string,mixed> $user
     */
    function avatar(array $user, string $class = 'avatar'): string
    {
        $id = (int) ($user['id'] ?? 0);
        $changed = (string) ($user['photo_updated_at'] ?? '');

        if ($id > 0 && $changed !== '') {
            return '<span class="' . e($class) . ' avatar--photo">'
                . '<img src="/users/' . $id . '/photo?v=' . substr(sha1($changed), 0, 8) . '"'
                . ' alt="" loading="lazy" decoding="async"></span>';
        }

        return '<span class="' . e($class) . '">' . e(App\Support\Str::initials((string) ($user['name'] ?? '?'))) . '</span>';
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon'): string
    {
        return App\Support\Icons::svg($name, $class);
    }
}
