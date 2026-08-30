<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Inline SVG icons. Drawn on a 24px grid with a single stroke weight so the
 * navigation reads like instrument panel engraving rather than a stock icon set.
 */
final class Icons
{
    private const PATHS = [
        'gauge' => '<path d="M3 18a9 9 0 1 1 18 0"/><path d="M12 18V9"/><path d="m15.5 11.5-3.5-2.5"/>',
        'pulse' => '<path d="M2 12h4l2.5-7 4 14L15 12h7"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.6 2.6 3.9 6 3.9 9s-1.3 6.4-3.9 9c-2.6-2.6-3.9-6-3.9-9S9.4 5.6 12 3Z"/>',
        'braces' => '<path d="M8 4c-2 0-2 3-2 4s0 4-2 4c2 0 2 3 2 4s0 4 2 4"/><path d="M16 4c2 0 2 3 2 4s0 4 2 4c-2 0-2 3-2 4s0 4-2 4"/>',
        'radio' => '<circle cx="12" cy="12" r="2"/><path d="M8.5 15.5a5 5 0 0 1 0-7"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M5.5 18.5a9 9 0 0 1 0-13"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>',
        'plug' => '<path d="M9 3v6"/><path d="M15 3v6"/><path d="M6 9h12v3a6 6 0 0 1-12 0Z"/><path d="M12 18v3"/>',
        'alert' => '<path d="M12 4 2.5 20h19L12 4Z"/><path d="M12 10v4"/><circle cx="12" cy="17" r=".6" fill="currentColor" stroke="none"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.5a3.2 3.2 0 0 1 0 5"/><path d="M17.5 14.5A6 6 0 0 1 21 20"/>',
        'group' => '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="8" y="14" width="8" height="6" rx="1.5"/><path d="M7 11v1.5h10V11"/><path d="M12 12.5V14"/>',
        'sliders' => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="10" cy="17" r="2"/>',
        'logout' => '<path d="M14 5V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1"/><path d="M10 12h11"/><path d="m18 9 3 3-3 3"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.5-4.5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/>',
        'desktop' => '<rect x="3" y="5" width="18" height="12" rx="1.5"/><path d="M9 21h6"/><path d="M12 17v4"/>',
        'check' => '<path d="m4 12 5.5 5.5L20 7"/>',
        'pause' => '<path d="M9 5v14"/><path d="M15 5v14"/>',
        'play' => '<path d="M7 4.5v15l13-7.5Z"/>',
        'edit' => '<path d="M4 20h4L20 8l-4-4L4 16v4Z"/><path d="m14 6 4 4"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 4h4"/><path d="M6 7v13h12V7"/><path d="M10 11v5M14 11v5"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'lock' => '<rect x="4" y="10" width="16" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m3.5 6.5 8.5 6 8.5-6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6.5V12l3.5 2.5"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4 3 7.5 7 9 4-1.5 7-5 7-9V6l-7-3Z"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v4h-4"/>',
        'certificate' => '<circle cx="12" cy="9" r="5"/><path d="m8.5 13-1 7 4.5-2.5L16.5 20l-1-7"/>',
        'history' => '<path d="M4 12a8 8 0 1 0 2.3-5.6"/><path d="M4 4v4h4"/><path d="M12 8v4.5l3 2"/>',
        'link' => '<path d="M10.5 13.5a4 4 0 0 0 5.7 0l2.3-2.3a4 4 0 0 0-5.7-5.7L11.5 6.8"/><path d="M13.5 10.5a4 4 0 0 0-5.7 0l-2.3 2.3a4 4 0 0 0 5.7 5.7l1.3-1.3"/>',
        'dots' => '<circle cx="5" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'arrow-left' => '<path d="M20 12H4"/><path d="m10 6-6 6 6 6"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.6"/>',
        'quote' => '<path d="M9 6C6.2 7.4 4.5 9.8 4.5 13v5h6v-6H8c0-2 .5-3.4 2-4.4Z"/><path d="M19 6c-2.8 1.4-4.5 3.8-4.5 7v5h6v-6H18c0-2 .5-3.4 2-4.4Z"/>',
        'steps' => '<path d="M3 19h4v-4h5v-4h5V7h4"/><circle cx="7" cy="19" r="1.6"/><circle cx="12" cy="15" r="1.6"/><circle cx="17" cy="11" r="1.6"/>',
        'registry' => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
        'signpost' => '<path d="M12 3v18"/><path d="M12 6h6l2.5 2.5L18 11h-6"/><path d="M12 13H6l-2.5 2.5L6 18h6"/>',
    ];

    public static function svg(string $name, string $class = 'icon'): string
    {
        $path = self::PATHS[$name] ?? self::PATHS['dots'];

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true" focusable="false">' . $path . '</svg>';
    }

    public static function forMonitorType(string $type): string
    {
        return match ($type) {
            'http' => 'globe',
            'keyword' => 'quote',
            'endpoint' => 'braces',
            'api' => 'steps',
            'ping' => 'radio',
            'port' => 'plug',
            'ssl' => 'certificate',
            'domain' => 'registry',
            'dns' => 'signpost',
            default => 'globe',
        };
    }
}
