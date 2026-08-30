<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * Reading a value out of a JSON response and saying what it should be.
 *
 * Shared by the endpoint check (one request) and the API check (a sequence of
 * them), so both offer the same operators and word a failure the same way.
 */
final class Assertions
{
    /** Worded for the dropdown in the form. */
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
    public const NEEDS_VALUE = ['equals', 'not_equals', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte'];

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

    public static function exists(string $operator): bool
    {
        return isset(self::OPERATORS[$operator]);
    }

    /**
     * Check every assertion against a decoded document.
     *
     * @param array<int,array<string,mixed>> $assertions
     * @return string|null the first failure sentence, or null when all hold
     */
    public static function check(mixed $document, array $assertions): ?string
    {
        foreach ($assertions as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }

            $path = trim((string) ($assertion['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            [$found, $actual] = self::resolve($document, $path);

            $failure = self::evaluate(
                $path,
                (string) ($assertion['operator'] ?? 'equals'),
                (string) ($assertion['value'] ?? ''),
                $found,
                $actual
            );

            if ($failure !== null) {
                return $failure;
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
    public static function evaluate(string $path, string $operator, string $expected, bool $found, mixed $actual): ?string
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

    public static function asString(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /** A short, quotable rendering for the failure sentence. */
    public static function describe(mixed $value): string
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
