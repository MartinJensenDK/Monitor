<?php
/**
 * The world with pins on it.
 *
 * Two jobs, one drawing. On the dashboard it plots every location with
 * something visible at it, coloured by the worst status there. On the location
 * form the same map takes a click and answers with coordinates.
 *
 * @var array<int,array<string,mixed>> $pins
 * @var bool $picker      true on the form, where clicking sets the coordinates
 * @var string $label     what a screen reader is told the picture is
 */

use App\Support\WorldMap;

$picker = $picker ?? false;
$label = $label ?? 'World map';
?>
<div class="wmap<?= $picker ? ' wmap--picker' : '' ?>" <?= $picker ? 'data-wmap-picker' : '' ?>
     data-wmap-top="<?= WorldMap::TOP_LAT ?>" data-wmap-height="<?= WorldMap::HEIGHT ?>"
     data-wmap-width="<?= WorldMap::WIDTH ?>">
    <svg class="wmap__svg" viewBox="0 0 <?= WorldMap::WIDTH ?> <?= WorldMap::HEIGHT ?>"
         preserveAspectRatio="xMidYMid meet" role="img" aria-label="<?= e($label) ?>">
        <rect class="wmap__sea" x="0" y="0" width="<?= WorldMap::WIDTH ?>" height="<?= WorldMap::HEIGHT ?>"/>
        <path class="wmap__grid" d="<?= WorldMap::graticulePath() ?>" fill="none" vector-effect="non-scaling-stroke"/>
        <path class="wmap__land" d="<?= WorldMap::landPath() ?>" fill-rule="evenodd" vector-effect="non-scaling-stroke"/>

        <g class="wmap__pins">
            <?php foreach ($pins as $pin): ?>
                <?php
                $x = WorldMap::x((float) $pin['longitude']);
                $y = WorldMap::y((float) $pin['latitude']);
                $status = (string) ($pin['status'] ?? 'pending');
                $total = (int) ($pin['total'] ?? 0);
                $down = (int) ($pin['down'] ?? 0);
                $degraded = (int) ($pin['degraded'] ?? 0);

                // Said in full, because the pin itself can only say it in colour.
                $summary = $down > 0
                    ? sprintf('%d of %d down', $down, $total)
                    : ($degraded > 0
                        ? sprintf('%d of %d degraded', $degraded, $total)
                        : ($total > 0 ? sprintf('all %d up', $total) : 'nothing watched yet'));
                ?>
                <?php // The anchor is carried as data as well as in the transform:
                      // zooming rewrites the transform to hold the pin's size
                      // steady, and it needs somewhere to read the place back
                      // from. Without the script the transform alone is right. ?>
                <?php if ($picker): ?>
                    <g class="wmap__pin wmap__pin--picked" data-wmap-marker
                       data-x="<?= round($x, 2) ?>" data-y="<?= round($y, 2) ?>"
                       transform="translate(<?= round($x, 2) ?> <?= round($y, 2) ?>)">
                        <circle class="wmap__ring" r="3.4" vector-effect="non-scaling-stroke"/>
                        <circle class="wmap__dot" r="1.5"/>
                    </g>
                <?php else: ?>
                    <a class="wmap__pin wmap__pin--<?= e($status) ?>" href="/monitors?location=<?= (int) $pin['id'] ?>"
                       data-location-id="<?= (int) $pin['id'] ?>"
                       data-x="<?= round($x, 2) ?>" data-y="<?= round($y, 2) ?>"
                       transform="translate(<?= round($x, 2) ?> <?= round($y, 2) ?>)">
                        <title><?= e((string) $pin['name'] . ' — ' . $summary) ?></title>
                        <circle class="wmap__halo" r="5"/>
                        <circle class="wmap__ring" r="3.2" vector-effect="non-scaling-stroke"/>
                        <circle class="wmap__dot" r="1.4"/>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </g>
    </svg>
</div>
