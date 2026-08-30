<?php

declare(strict_types=1);

namespace App\Checks;

use App\Domain\Monitors;

/**
 * API check: a sequence of requests, run in order.
 *
 * The endpoint check answers "is this one call healthy". Most APIs cannot be
 * asked that way — you sign in, you are handed a token, and only then can you
 * call the thing you actually care about. This runs those steps in order,
 * carries values from one response into the next, and reports which step broke
 * and why.
 *
 * Each step can capture a value out of its JSON and later steps refer to it as
 * {{name}} in the path, in a header, or in the body.
 */
final class ApiChecker implements CheckerInterface
{
    public const MAX_STEPS = 8;

    public static function type(): string
    {
        return 'api';
    }

    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult
    {
        $config = Monitors::config($monitor);
        $steps = self::steps($config);

        if ($steps === []) {
            return CheckResult::down('no_steps', 'This API monitor has no steps yet. Add at least one request.');
        }

        $budget = (float) max(2, (int) $monitor['timeout_seconds']);
        $startedAt = microtime(true);

        $variables = [];
        $timings = [];
        $totalMs = 0;

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $label = self::label($step, $number);

            $remaining = $budget - (microtime(true) - $startedAt);
            if ($remaining < 1) {
                return CheckResult::down(
                    'timeout',
                    sprintf('The sequence used its whole %d second budget before %s.', (int) $budget, $label),
                    $totalMs,
                    null,
                    ['steps' => $timings]
                );
            }

            $url = self::url($monitor, $step, $variables);
            if ($url === null) {
                return CheckResult::down(
                    'bad_step',
                    sprintf('%s has no address to call.', ucfirst($label)),
                    $totalMs,
                    null,
                    ['steps' => $timings]
                );
            }

            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $guard = TargetGuard::check($host);
            if (!$guard['allowed']) {
                return CheckResult::down('blocked_target', $guard['reason'], $totalMs, null, ['steps' => $timings]);
            }

            $response = self::request($url, $step, $config, $variables, $remaining);
            $totalMs += $response['ms'];
            $timings[] = ['name' => $label, 'ms' => $response['ms'], 'code' => $response['http_code']];
            $meta = ['steps' => $timings, 'step_count' => count($steps)];

            if ($response['errno'] !== 0) {
                return CheckResult::down(
                    HttpChecker::errorCode($response['errno']),
                    sprintf('%s failed: %s', ucfirst($label), HttpChecker::errorMessage($response['errno'], $response['error'], $url)),
                    $totalMs,
                    $response['http_code'] ?: null,
                    $meta
                );
            }

            $expected = trim((string) ($step['expected_status'] ?? '')) ?: '200-299';
            if (!HttpChecker::statusMatches($response['http_code'], $expected)) {
                return CheckResult::down(
                    'status_mismatch',
                    sprintf('%s expected HTTP %s but got %d.', ucfirst($label), $expected, $response['http_code']),
                    $totalMs,
                    $response['http_code'],
                    $meta
                );
            }

            $assertions = self::assertions($step);
            $captures = self::captures($step);

            if ($assertions === [] && $captures === []) {
                continue;
            }

            $document = json_decode($response['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return CheckResult::down(
                    'not_json',
                    sprintf('%s did not return valid JSON (%s).', ucfirst($label), json_last_error_msg()),
                    $totalMs,
                    $response['http_code'],
                    $meta
                );
            }

            $failure = Assertions::check($document, $assertions);
            if ($failure !== null) {
                return CheckResult::down(
                    'assertion',
                    sprintf('%s: %s', ucfirst($label), $failure),
                    $totalMs,
                    $response['http_code'],
                    $meta
                );
            }

            foreach ($captures as $capture) {
                [$found, $value] = Assertions::resolve($document, $capture['path']);
                if (!$found) {
                    return CheckResult::down(
                        'capture',
                        sprintf('%s has no value at %s to carry into the next step.', ucfirst($label), $capture['path']),
                        $totalMs,
                        $response['http_code'],
                        $meta
                    );
                }

                $variables[$capture['name']] = Assertions::asString($value);
            }
        }

        $meta = ['steps' => $timings, 'step_count' => count($steps)];
        $degradedMs = (int) ($monitor['degraded_ms'] ?? 0);

        if ($degradedMs > 0 && $totalMs > $degradedMs) {
            return CheckResult::degraded(
                $totalMs,
                sprintf('The sequence took %d ms, over the %d ms threshold.', $totalMs, $degradedMs),
                null,
                null,
                $meta
            );
        }

        return CheckResult::up($totalMs, null, $timings === [] ? null : (int) end($timings)['code'], $meta);
    }

    /**
     * @param array<string,mixed> $step
     * @param array<string,mixed> $config
     * @param array<string,string> $variables
     * @return array{body:string,http_code:int,ms:int,errno:int,error:string}
     */
    private static function request(string $url, array $step, array $config, array $variables, float $timeout): array
    {
        $handle = curl_init();
        $method = strtoupper((string) ($step['method'] ?? 'GET'));

        $headers = [];
        foreach ((array) ($step['headers'] ?? []) as $name => $value) {
            if (is_string($name) && trim($name) !== '') {
                $headers[] = $name . ': ' . self::substitute((string) $value, $variables);
            }
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => (bool) ($config['follow_redirects'] ?? true),
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT_MS => (int) max(1000, $timeout * 1000),
            CURLOPT_CONNECTTIMEOUT => (int) max(1, min(10, $timeout)),
            CURLOPT_SSL_VERIFYPEER => (bool) ($config['verify_ssl'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($config['verify_ssl'] ?? true) ? 2 : 0,
            CURLOPT_USERAGENT => (string) ($config['user_agent'] ?? '') ?: 'Monitor/1.0 (+uptime monitor)',
            CURLOPT_ENCODING => '',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($h, $downloadSize, $downloaded): int => $downloaded > 2_000_000 ? 1 : 0,
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $body = self::substitute((string) ($step['body'] ?? ''), $variables);
        if ($body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $startedAt = microtime(true);
        $response = curl_exec($handle);
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        $result = [
            'body' => is_string($response) ? $response : '',
            'http_code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'ms' => $ms,
            'errno' => curl_errno($handle),
            'error' => curl_error($handle),
        ];

        curl_close($handle);

        return $result;
    }

    /**
     * A step's address: an absolute URL as typed, or a path joined onto the
     * monitor's base URL so the host is written once.
     *
     * @param array<string,mixed> $monitor
     * @param array<string,mixed> $step
     * @param array<string,string> $variables
     */
    private static function url(array $monitor, array $step, array $variables): ?string
    {
        $path = self::substitute(trim((string) ($step['path'] ?? '')), $variables);
        $base = rtrim(trim((string) $monitor['target']), '/');

        if ($path === '') {
            return $base !== '' ? $base : null;
        }

        if (str_contains($path, '://')) {
            return $path;
        }

        if ($base === '') {
            return null;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** @param array<string,string> $variables */
    public static function substitute(string $subject, array $variables): string
    {
        if ($subject === '' || $variables === []) {
            return $subject;
        }

        $replacements = [];
        foreach ($variables as $name => $value) {
            $replacements['{{' . $name . '}}'] = $value;
        }

        return strtr($subject, $replacements);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array<string,mixed>>
     */
    public static function steps(array $config): array
    {
        $steps = [];
        foreach ((array) ($config['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            if (trim((string) ($step['path'] ?? '')) === '' && trim((string) ($step['name'] ?? '')) === '') {
                continue;
            }
            $steps[] = $step;
        }

        return array_slice($steps, 0, self::MAX_STEPS);
    }

    /**
     * @param array<string,mixed> $step
     * @return array<int,array<string,mixed>>
     */
    private static function assertions(array $step): array
    {
        return array_values(array_filter(
            (array) ($step['assertions'] ?? []),
            static fn ($a): bool => is_array($a) && trim((string) ($a['path'] ?? '')) !== ''
        ));
    }

    /**
     * @param array<string,mixed> $step
     * @return array<int,array{name:string,path:string}>
     */
    private static function captures(array $step): array
    {
        $captures = [];
        foreach ((array) ($step['captures'] ?? []) as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $name = trim((string) ($capture['name'] ?? ''));
            $path = trim((string) ($capture['path'] ?? ''));
            if ($name !== '' && $path !== '') {
                $captures[] = ['name' => $name, 'path' => $path];
            }
        }

        return $captures;
    }

    /** @param array<string,mixed> $step */
    private static function label(array $step, int $number): string
    {
        $name = trim((string) ($step['name'] ?? ''));

        return $name === ''
            ? 'step ' . $number
            : sprintf('step %d (%s)', $number, mb_strimwidth($name, 0, 40, '…'));
    }
}
