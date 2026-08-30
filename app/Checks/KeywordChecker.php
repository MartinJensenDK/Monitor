<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * Keyword check: is the wording still on the page?
 *
 * A site that returns 200 while showing "We'll be right back" is down as far
 * as anyone visiting it is concerned. This looks at what the page actually
 * says — several words or phrases at once, and either all of them, any of
 * them, or none of them.
 *
 * The markup is stripped before matching by default, so a phrase split across
 * tags still counts and a word hidden in a class name does not.
 */
final class KeywordChecker extends HttpChecker
{
    public const MODES = [
        'all' => 'All of them must appear',
        'any' => 'At least one must appear',
        'none' => 'None of them may appear',
    ];

    public static function type(): string
    {
        return 'keyword';
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
        $keywords = self::keywords($config);
        if ($keywords === []) {
            return null;
        }

        $mode = (string) ($config['keyword_mode'] ?? 'all');
        $caseSensitive = (bool) ($config['case_sensitive'] ?? false);
        $haystack = (bool) ($config['strip_html'] ?? true) ? self::readableText($body) : $body;

        $found = [];
        $missing = [];
        foreach ($keywords as $keyword) {
            $hit = $caseSensitive
                ? str_contains($haystack, $keyword)
                : mb_stripos($haystack, $keyword) !== false;

            if ($hit) {
                $found[] = $keyword;
            } else {
                $missing[] = $keyword;
            }
        }

        $meta['keywords_found'] = count($found);
        $meta['keywords_total'] = count($keywords);

        $failure = match ($mode) {
            'any' => $found === []
                ? sprintf(
                    'The page contains none of %s.',
                    self::listOf($keywords)
                )
                : null,
            'none' => $found !== []
                ? sprintf(
                    'The page contains %s, which should not appear on it.',
                    self::listOf($found, 'and')
                )
                : null,
            default => $missing !== []
                ? sprintf(
                    'The page does not contain %s.',
                    self::listOf($missing)
                )
                : null,
        };

        return $failure === null
            ? null
            : CheckResult::down('keyword', $failure, $totalMs, $httpCode, $meta);
    }

    /**
     * One keyword or phrase per line.
     *
     * @param array<string,mixed> $config
     * @return array<int,string>
     */
    public static function keywords(array $config): array
    {
        $raw = $config['keywords'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/\R/', $raw) ?: [];
        }

        $keywords = [];
        foreach ((array) $raw as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $keywords[] = $keyword;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * What a person reading the page would see: no scripts, no styles, no
     * tags, entities resolved, and runs of whitespace collapsed so a phrase
     * broken over two lines of source still matches.
     */
    public static function readableText(string $html): string
    {
        $text = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking spaces read as spaces to a person, so they should here too.
        $text = str_replace(["\xc2\xa0", "\xe2\x80\x8b"], ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param array<int,string> $keywords */
    private static function listOf(array $keywords, string $conjunction = 'or'): string
    {
        $quoted = array_map(static fn (string $k): string => '"' . mb_strimwidth($k, 0, 60, '…') . '"', $keywords);

        if (count($quoted) === 1) {
            return $quoted[0];
        }

        $last = array_pop($quoted);

        return implode(', ', $quoted) . ' ' . $conjunction . ' ' . $last;
    }
}
