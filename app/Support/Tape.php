<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "The tape" — the signal strip that carries this product's identity.
 *
 * One thin bar per check: height is the response time on a log scale, colour is
 * the outcome. Same geometry in PHP (first paint) and in public/assets/js/tape.js
 * (live updates), so a strip never jumps when the poller replaces it.
 */
final class Tape
{
    public const VARIANTS = [
        'row' => ['bar' => 3, 'gap' => 1, 'height' => 26, 'slots' => 48],
        'hero' => ['bar' => 4, 'gap' => 2, 'height' => 64, 'slots' => 96],
        'fleet' => ['bar' => 2, 'gap' => 1, 'height' => 34, 'slots' => 160],
    ];

    /**
     * @param array<int,array<string,mixed>> $points oldest first
     */
    public static function svg(array $points, string $variant = 'row', string $label = ''): string
    {
        $spec = self::VARIANTS[$variant] ?? self::VARIANTS['row'];
        $slots = (int) $spec['slots'];
        $bar = (int) $spec['bar'];
        $gap = (int) $spec['gap'];
        $height = (int) $spec['height'];
        $width = $slots * ($bar + $gap) - $gap;

        $points = array_slice($points, -$slots);
        $offset = $slots - count($points);

        $ceiling = self::ceiling($points);

        $bars = '';
        foreach ($points as $index => $point) {
            $x = ($offset + $index) * ($bar + $gap);
            $status = (string) ($point['status'] ?? 'down');
            $ms = $point['response_ms'] === null ? null : (int) $point['response_ms'];

            $barHeight = $status === 'down'
                ? $height
                : max(2, (int) round(self::scale($ms, $ceiling) * ($height - 3)) + 2);

            $y = $height - $barHeight;

            $bars .= sprintf(
                '<rect class="tape-bar tape-bar--%s" x="%d" y="%d" width="%d" height="%d" rx="%s"><title>%s</title></rect>',
                $status,
                $x,
                $y,
                $bar,
                $barHeight,
                $bar > 2 ? '1' : '0.5',
                htmlspecialchars(self::tooltip($point), ENT_QUOTES)
            );
        }

        // Empty slots read as "no data yet" rather than as an outage.
        $placeholders = '';
        for ($i = 0; $i < $offset; $i++) {
            $placeholders .= sprintf(
                '<rect class="tape-bar tape-bar--empty" x="%d" y="%d" width="%d" height="2" rx="0.5"/>',
                $i * ($bar + $gap),
                $height - 2,
                $bar
            );
        }

        return sprintf(
            '<svg class="tape tape--%s" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">'
            . '<line class="tape-base" x1="0" y1="%s" x2="%d" y2="%s"/>%s%s</svg>',
            $variant,
            $width,
            $height,
            htmlspecialchars($label !== '' ? $label : 'Recent checks', ENT_QUOTES),
            $height - 0.5,
            $width,
            $height - 0.5,
            $placeholders,
            $bars
        );
    }

    /**
     * Round the top of the scale up to a friendly number so two monitors with
     * similar latency get comparable looking strips.
     *
     * @param array<int,array<string,mixed>> $points
     */
    private static function ceiling(array $points): int
    {
        $max = 0;
        foreach ($points as $point) {
            if ($point['response_ms'] !== null) {
                $max = max($max, (int) $point['response_ms']);
            }
        }

        foreach ([200, 500, 1000, 2000, 5000, 10000, 30000] as $step) {
            if ($max <= $step) {
                return $step;
            }
        }

        return max(30000, $max);
    }

    /** Log scale: the difference between 20 ms and 200 ms should be visible. */
    private static function scale(?int $ms, int $ceiling): float
    {
        if ($ms === null || $ms <= 0) {
            return 0.15;
        }

        $value = log10(max(1, $ms)) / log10(max(10, $ceiling));

        return max(0.08, min(1.0, $value));
    }

    /** @param array<string,mixed> $point */
    private static function tooltip(array $point): string
    {
        $time = (string) ($point['checked_at'] ?? '');
        $status = strtoupper((string) ($point['status'] ?? ''));
        $ms = $point['response_ms'] === null ? '—' : $point['response_ms'] . ' ms';
        $error = (string) ($point['error_message'] ?? '');

        return trim($time . ' UTC · ' . $status . ' · ' . $ms . ($error !== '' ? ' · ' . $error : ''));
    }
}
