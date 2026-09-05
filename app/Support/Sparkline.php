<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A small filled line chart, drawn server-side as inline SVG.
 *
 * Deliberately not uPlot. These are read at a glance and never interrogated --
 * the question is "has this been climbing", not "what was it at 14:07" -- and
 * an SVG the server already rendered needs no script, no library, and no
 * second colour system to keep in step with the theme. It also survives a
 * page with sixty of them, which a chart library would not.
 */
final class Sparkline
{
    private const WIDTH = 300.0;
    private const HEIGHT = 48.0;

    /**
     * @param array<int,float|null> $values in the order they happened
     * @param float $max the top of the scale; 0 asks for the largest value present
     */
    public static function svg(array $values, string $class = 'spark', float $max = 100.0): string
    {
        $points = array_values(array_filter($values, static fn ($v): bool => $v !== null));
        if (count($points) < 2) {
            return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 300 48" preserveAspectRatio="none"'
                . ' aria-hidden="true" focusable="false"></svg>';
        }

        if ($max <= 0) {
            $max = max($points);
            $max = $max <= 0 ? 1.0 : $max;
        }

        $count = count($points);
        $step = self::WIDTH / ($count - 1);

        $line = '';
        $x = 0.0;
        foreach ($points as $index => $value) {
            // A reading of zero should sit on the floor, not on the axis, so
            // the scale keeps a hair of room at both ends.
            $y = self::HEIGHT - 1 - (min($max, max(0.0, $value)) / $max) * (self::HEIGHT - 2);
            $line .= ($index === 0 ? 'M' : 'L') . round($x, 1) . ' ' . round($y, 1);
            $x += $step;
        }

        $area = $line . 'L' . round(self::WIDTH, 1) . ' ' . self::HEIGHT . 'L0 ' . self::HEIGHT . 'Z';

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 300 48"'
            . ' preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<path class="spark__area" d="' . $area . '"/>'
            . '<path class="spark__line" d="' . $line . '" fill="none" vector-effect="non-scaling-stroke"/>'
            . '</svg>';
    }
}
