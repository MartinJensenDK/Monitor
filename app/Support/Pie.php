<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A pie of one proportion, drawn server-side as inline SVG.
 *
 * The same reasoning as Sparkline next to it: read at a glance, never
 * interrogated, and rendered by the thing that already knows the number rather
 * than by a library that would need its own copy of the palette.
 *
 * It is a pie rather than a ring because a disk is a quantity of space with
 * some of it gone -- a shape people read as "that much is used" without being
 * told. The trick that makes it one is worth naming: a circle whose stroke is
 * as wide as its diameter closes its own hole, so the same dash arithmetic
 * that draws a ring elsewhere in this stylesheet draws a wedge here.
 */
final class Pie
{
    /** The box, and the geometry that fills it exactly. */
    private const SIZE = 68.0;
    private const RADIUS = 17.0;

    /**
     * @param ?float $percent 0 to 100; null when the machine has not said
     */
    public static function svg(?float $percent, string $class = 'pie'): string
    {
        $safe = htmlspecialchars($class, ENT_QUOTES);
        $open = '<svg class="' . $safe . '" viewBox="0 0 ' . (int) self::SIZE . ' ' . (int) self::SIZE . '"'
            . ' aria-hidden="true" focusable="false">';

        $centre = self::SIZE / 2;

        // Nothing known: the empty dish, so the space is held and the row does
        // not jump when the first reading arrives.
        if ($percent === null) {
            return $open
                . '<circle class="pie__dish" cx="' . $centre . '" cy="' . $centre . '" r="' . $centre . '"/>'
                . '</svg>';
        }

        $fraction = max(0.0, min(1.0, $percent / 100));
        $circumference = 2 * M_PI * self::RADIUS;

        return $open
            . '<circle class="pie__dish" cx="' . $centre . '" cy="' . $centre . '" r="' . $centre . '"/>'
            . '<circle class="pie__slice" cx="' . $centre . '" cy="' . $centre . '" r="' . self::RADIUS . '"'
            . ' fill="none" stroke-width="' . (int) (self::RADIUS * 2) . '"'
            . ' stroke-dasharray="' . round($circumference, 2) . '"'
            . ' stroke-dashoffset="' . round($circumference * (1 - $fraction), 2) . '"'
            . ' transform="rotate(-90 ' . $centre . ' ' . $centre . ')"/>'
            . '</svg>';
    }
}
