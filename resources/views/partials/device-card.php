<?php
/**
 * One machine, as it appears in a list.
 *
 * Reading order is what needed attention first: the status light, then the
 * name, then the three bars, then whatever is waiting. A machine with nothing
 * wrong shows no colour beyond its own status dot.
 *
 * @var array<string,mixed> $device
 */

use App\Support\Icons;

$status = (string) $device['status'];
$memoryPercent = percent_of(
    $device['memory_used_bytes'] === null ? null : (int) $device['memory_used_bytes'],
    $device['memory_bytes'] === null ? null : (int) $device['memory_bytes']
);
$diskPercent = percent_of(
    $device['disk_used_bytes'] === null ? null : (int) $device['disk_used_bytes'],
    $device['disk_total_bytes'] === null ? null : (int) $device['disk_total_bytes']
);
$cpuPercent = $device['cpu_percent'] === null ? null : (int) round((float) $device['cpu_percent']);

$security = (int) $device['updates_security'];
$pending = (int) $device['updates_total'];
?>
<a class="devcard devcard--<?= e($status) ?>" href="/devices/<?= e((string) $device['uuid']) ?>"
   data-device-id="<?= e((string) $device['uuid']) ?>">

    <div class="devcard__head">
        <span class="devcard__dot" aria-hidden="true"></span>
        <span class="devcard__title">
            <span class="devcard__name truncate"><?= e((string) $device['name']) ?></span>
            <span class="devcard__host truncate">
                <?= e((string) ($device['primary_ip'] ?? $device['hostname'])) ?>
            </span>
        </span>
        <span class="pill pill--<?= e($status) ?>"><?= e(t('device.status_' . $status)) ?></span>
    </div>

    <p class="devcard__os truncate">
        <?= icon(Icons::forOsFamily((string) ($device['os_family'] ?? '')), 'icon icon--sm') ?>
        <?= e(trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? '')) ?: t('device.os_unknown')) ?>
    </p>

    <div class="meters">
        <?php foreach ([
            ['label' => t('device.cpu'), 'percent' => $cpuPercent, 'foot' => (int) ($device['cpu_cores'] ?? 0) > 0 ? $device['cpu_cores'] . '×' : ''],
            ['label' => t('device.memory'), 'percent' => $memoryPercent, 'foot' => format_bytes($device['memory_bytes'] === null ? null : (int) $device['memory_bytes'])],
            ['label' => t('device.storage'), 'percent' => $diskPercent, 'foot' => format_bytes($device['disk_total_bytes'] === null ? null : (int) $device['disk_total_bytes'])],
        ] as $meter): ?>
            <div class="meter">
                <div class="meter__top">
                    <span class="meter__label"><?= e($meter['label']) ?></span>
                    <span class="meter__value num"><?= $meter['percent'] === null ? '—' : (int) $meter['percent'] . '%' ?></span>
                </div>
                <div class="meter__track">
                    <span class="meter__fill meter__fill--<?= e(meter_level($meter['percent'])) ?>"
                          style="width:<?= (int) ($meter['percent'] ?? 0) ?>%"></span>
                </div>
                <span class="meter__foot"><?= e((string) $meter['foot']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="devcard__foot">
        <?php if ($security > 0): ?>
            <span class="tag tag--down"><?= icon('shield', 'icon icon--sm') ?><?= (int) $security ?> <?= e(t('device.security')) ?></span>
        <?php elseif ($pending > 0): ?>
            <span class="tag"><?= icon('download', 'icon icon--sm') ?><?= (int) $pending ?> <?= e(t('device.updates')) ?></span>
        <?php endif; ?>

        <?php if ((int) $device['reboot_required'] === 1): ?>
            <span class="tag tag--warn"><?= icon('refresh', 'icon icon--sm') ?><?= e(t('device.reboot_required')) ?></span>
        <?php endif; ?>

        <?php if (!empty($device['location_name'])): ?>
            <span class="tag"><?= icon('pin', 'icon icon--sm') ?><?= e((string) $device['location_name']) ?></span>
        <?php endif; ?>

        <span class="devcard__seen muted"
              data-since="<?= e((string) ($device['last_seen_at'] ?? '')) ?>">
            <?= e($device['last_seen_at'] === null ? t('device.never_reported') : format_since((string) $device['last_seen_at'])) ?>
        </span>
    </div>
</a>
