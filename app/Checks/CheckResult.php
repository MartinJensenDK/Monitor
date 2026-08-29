<?php

declare(strict_types=1);

namespace App\Checks;

final class CheckResult
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $status,
        public readonly ?int $responseMs = null,
        public readonly ?int $connectMs = null,
        public readonly ?int $httpCode = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = []
    ) {
    }

    /** @param array<string,mixed> $meta */
    public static function up(int $responseMs, ?int $connectMs = null, ?int $httpCode = null, array $meta = []): self
    {
        return new self('up', $responseMs, $connectMs, $httpCode, null, null, $meta);
    }

    /** @param array<string,mixed> $meta */
    public static function degraded(int $responseMs, string $reason, ?int $connectMs = null, ?int $httpCode = null, array $meta = []): self
    {
        return new self('degraded', $responseMs, $connectMs, $httpCode, 'slow', $reason, $meta);
    }

    /** @param array<string,mixed> $meta */
    public static function down(string $code, string $message, ?int $responseMs = null, ?int $httpCode = null, array $meta = []): self
    {
        return new self('down', $responseMs, null, $httpCode, $code, $message, $meta);
    }

    public function isUp(): bool
    {
        return $this->status !== 'down';
    }

    /** Short human sentence used in incidents and notifications. */
    public function summary(): string
    {
        if ($this->status === 'up') {
            return 'Responded in ' . $this->responseMs . ' ms';
        }

        return (string) ($this->errorMessage ?? 'Check failed');
    }
}
