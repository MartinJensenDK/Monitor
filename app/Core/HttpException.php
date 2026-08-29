<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message === '' ? self::defaultMessage($status) : $message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            403 => 'You do not have access to this page.',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Try again.',
            429 => 'Too many attempts. Wait a moment and try again.',
            default => 'Something went wrong.',
        };
    }
}
