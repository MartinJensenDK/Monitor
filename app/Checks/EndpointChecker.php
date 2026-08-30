<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * API endpoint check: everything the website check does, plus assertions about
 * the JSON that came back. "HTTP 200" is a weak promise for an API — this is
 * how you say what the body has to contain.
 *
 * One request. For a sequence of them — sign in, then call the endpoint the
 * token unlocks — use the API check instead.
 */
final class EndpointChecker extends HttpChecker
{
    /** Kept here so forms and views have one obvious place to read them from. */
    public const OPERATORS = Assertions::OPERATORS;

    public static function type(): string
    {
        return 'endpoint';
    }

    /**
     * @param array<string,mixed> $monitor
     * @param array<string,mixed> $config
     * @param array<string,mixed> $meta
     */
    protected function inspectBody(
        array $monitor,
        array $config,
        string $body,
        int $totalMs,
        int $httpCode,
        array $meta
    ): ?CheckResult {
        $failure = parent::inspectBody($monitor, $config, $body, $totalMs, $httpCode, $meta);
        if ($failure !== null) {
            return $failure;
        }

        $assertions = array_values(array_filter(
            (array) ($config['assertions'] ?? []),
            static fn ($a): bool => is_array($a) && trim((string) ($a['path'] ?? '')) !== ''
        ));

        if ($assertions === []) {
            return null;
        }

        $document = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return CheckResult::down(
                'not_json',
                'The response is not valid JSON (' . json_last_error_msg() . ').',
                $totalMs,
                $httpCode,
                $meta
            );
        }

        $message = Assertions::check($document, $assertions);

        return $message === null
            ? null
            : CheckResult::down('assertion', $message, $totalMs, $httpCode, $meta);
    }
}
