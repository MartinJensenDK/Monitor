<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Str;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = Str::token(32);
            Session::put('_csrf', $token);
        }

        return $token;
    }

    public static function check(?string $candidate): bool
    {
        $token = Session::get('_csrf');

        return is_string($token) && is_string($candidate) && hash_equals($token, $candidate);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }
}
