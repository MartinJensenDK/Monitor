<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * API endpoint check: everything the website check does, plus assertions about
 * the JSON that came back. "HTTP 200" is a weak promise for an API — this is
 * how you say what the body has to contain.
 */
final class EndpointChecker extends HttpChecker
{
    public const OPERATORS = [
        'equals' => 'equals',
        'not_equals' => 'does not equal',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'gt' => 'is greater than',
        'gte' => 'is at least',
        'lt' => 'is less than',
        'lte' => 'is at most',
        'exists' => 'is present',
        'missing' => 'is absent',
        'is_true' => 'is true',
        'is_false' => 'is false',
        'not_empty' => 'is not empty',
    ];

    /** Operators that compare against a value the user typed. */
    private const NEEDS_VALUE = ['equals', 'not_equals', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte'];

    /**
     * The same operators worded to follow "should" in a failure sentence.
     * The labels above read better in the form; these read better in an alert.
     */
    private const SENTENCE = [
        'equals' => 'equal',
        'not_equals' => 'not equal',
        'contains' => 'contain',
        'not_contains' => 'not contain',
        'gt' => 'be greater than',
        'gte' => 'be at least',
        'lt' => 'be less than',
        'lte' => 'be at most',
        'exists' => 'be present',
        'missing' => 'be absent',
        'is_true' => 'be true',
        'is_false' => 'be false',
        'not_empty' => 'not be empty',
    ];

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

        foreach ($assertions as $assertion) {
            $path = trim((string) $assertion['path']);
            $operator = (string) ($assertion['operator'] ?? 'equals');
            $expected = (string) ($assertion['value'] ?? '');

            [$found, $actual] = self::resolve($document, $path);

            $message = self::evaluate($path, $operator, $expected, $found, $actual);
            if ($message !== null) {
                return CheckResult::down('assertion', $message, $totalMs, $httpCode, $meta);
            }
        }

        return null;
    }

    /**
     * Walk a dotted path. Numeric segments index into arrays, so
     * `data.items.0.status` reads the first item.
     *
     * @return array{0:bool,1:mixed} whether the path exists, and its value
     */
    public static function resolve(mixed $document, string $path): array
    {
        if ($path === '' || $path === '$') {
            return [true, $document];
        }

        $value = $document;
        foreach (explode('.', trim($path, '.')) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            if (is_array($value) && ctype_digit($segment) && array_key_exists((int) $segment, $value)) {
                $value = $value[(int) $segment];
                continue;
            }

            return [false, null];
        }

        return [true, $value];
    }

    /** Returns null when the assertion holds, or the failure sentence when it does not. */
    private static function evaluate(string $path, string $operator, string $expected, bool $found, mixed $actual): ?string
    {
        if ($operator === 'missing') {
            return $found
                ? sprintf('%s should be absent, but it is %s.', $path, self::describe($actual))
                : null;
        }

        if (!$found) {
            return sprintf('The response has no value at %s.', $path);
        }

        if ($operator === 'exists') {
            return null;
        }

        $holds = match ($operator) {
            'equals' => self::asString($actual) === $expected,
            'not_equals' => self::asString($actual) !== $expected,
            'contains' => str_contains(self::asString($actual), $expected),
            'not_contains' => !str_contains(self::asString($actual), $expected),
            'gt' => is_numeric($actual) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            'is_true' => $actual === true || $actual === 1 || $actual === 'true',
            'is_false' => $actual === false || $actual === 0 || $actual === 'false',
            'not_empty' => !in_array($actual, [null, '', [], 0], true),
            default => true,
        };

        if ($holds) {
            return null;
        }

        $label = self::SENTENCE[$operator] ?? $operator;

        if (in_array($operator, self::NEEDS_VALUE, true)) {
            if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true) && !is_numeric($actual)) {
                return sprintf('%s should %s %s, but it is not a number: %s.', $path, $label, $expected, self::describe($actual));
            }

            return sprintf('%s should %s "%s", but it is %s.', $path, $label, $expected, self::describe($actual));
        }

        return sprintf('%s should %s, but it is %s.', $path, $label, self::describe($actual));
    }

    private static function asString(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /** A short, quotable rendering for the failure sentence. */
    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? sprintf('a list of %d item(s)', count($value))
                : sprintf('an object with %d key(s)', count($value));
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) $value;

        return '"' . (mb_strlen($string) > 60 ? mb_substr($string, 0, 59) . '…' : $string) . '"';
    }
}
