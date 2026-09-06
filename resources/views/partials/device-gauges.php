<?php
/**
 * The four readings at the top of a machine's page.
 *
 * Processor and memory are rates, so they carry the last day as a line: the
 * question is which way they have been going. A disk is not a rate -- it is a
 * quantity with some of it gone -- so it gets a pie, which answers "how much
 * room is left" at a glance where a line of a barely-moving number does not.
 *
 * Rendered here rather than in the page because the live channel sends it as
 * well: these are the numbers that change while somebody is looking at them.
 *
 * @var array<string,mixed> $device
 * @var array<int,array<string,mixed>> $metrics
 */

use App\Support\Pie;
use App\Support\Sparkline;

$cpuSeries = [];
$memorySeries = [];
foreach ($metrics as $row) {
    $cpuSeries[] = $row['cpu_percent'] === null ? null : (float) $row['cpu_percent'];
    $memorySeries[] = percent_of(
        $row['memory_used_bytes'] === null ? null : (int) $row['memory_used_bytes'],
        $row['memory_total_bytes'] === null ? null : (int) $row['memory_total_bytes']
    );
}

$memoryPercent = percent_of(
    $device['memory_used_bytes'] === null ? null : (int) $device['memory_used_bytes'],
    $device['memory_bytes'] === null ? null : (int) $device['memory_bytes']
);
$diskPercent = percent_of(
    $device['disk_used_bytes'] === null ? null : (int) $device['disk_used_bytes'],
    $device['disk_total_bytes'] === null ? null : (int) $device['disk_total_bytes']
);
$cpuPercent = $device['cpu_percent'] === null ? null : (int) round((float) $device['cpu_percent']);
?>
<?php foreach ([
    ['label' => t('device.cpu'), 'percent' => $cpuPercent, 'shape' => 'line', 'series' => $cpuSeries, 'foot' => (string) ($device['load1'] ?? '') !== '' ? t('device.load', ['value' => $device['load1']]) : ''],
    ['label' => t('device.memory'), 'percent' => $memoryPercent, 'shape' => 'line', 'series' => $memorySeries, 'foot' => format_bytes($device['memory_used_bytes'] === null ? null : (int) $device['memory_used_bytes']) . ' / ' . format_bytes($device['memory_bytes'] === null ? null : (int) $device['memory_bytes'])],
    ['label' => t('device.storage'), 'percent' => $diskPercent, 'shape' => 'pie', 'series' => [], 'foot' => format_bytes($device['disk_used_bytes'] === null ? null : (int) $device['disk_used_bytes']) . ' / ' . format_bytes($device['disk_total_bytes'] === null ? null : (int) $device['disk_total_bytes'])],
] as $gauge): ?>
    <div class="gauge gauge--<?= e(meter_level($gauge['percent'])) ?>">
        <p class="eyebrow"><?= e($gauge['label']) ?></p>
        <p class="gauge__value num"><?= $gauge['percent'] === null ? '—' : (int) $gauge['percent'] . '<span class="gauge__unit">%</span>' ?></p>
        <div class="gauge__spark<?= $gauge['shape'] === 'pie' ? ' gauge__spark--pie' : '' ?>">
            <?= $gauge['shape'] === 'pie'
                ? Pie::svg($gauge['percent'] === null ? null : (float) $gauge['percent'])
                : Sparkline::svg($gauge['series']) ?>
        </div>
        <p class="gauge__foot"><?= e($gauge['foot']) ?></p>
    </div>
<?php endforeach; ?>

<div class="gauge">
    <p class="eyebrow"><?= e(t('device.uptime')) ?></p>
    <p class="gauge__value num"><?= e(format_duration($device['uptime_seconds'] === null ? null : (int) $device['uptime_seconds'])) ?></p>
    <p class="gauge__foot">
        <?= $device['boot_at'] === null ? '' : e(t('device.booted', ['time' => local_time((string) $device['boot_at'], 'Y-m-d H:i')])) ?>
    </p>
</div>
