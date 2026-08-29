<?php

declare(strict_types=1);

namespace App\Entra;

use RuntimeException;

/**
 * A refusal from Microsoft, with the HTTP status kept alongside the sentence.
 * The status is what lets "this group was deleted" be told apart from "this
 * application is not allowed to read groups" — two very different problems that
 * would otherwise produce the same wrong message.
 */
final class DirectoryException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
