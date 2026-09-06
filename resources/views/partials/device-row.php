<?php
/**
 * One machine, as a row.
 *
 * The same facts the card carries, in the order a list is read rather than the
 * order a card is: identity first, then the three readings lined up so a
 * column can be compared down the page, which is the only thing a list does
 * that a grid of cards cannot.
 *
 * @var array<string,mixed> $device
 */

use App\Support\Icons;

$status = (string) $device['status'];
$uuid = (string) $device['uuid'];
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
$osLabel = trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? '')) ?: t('device.os_unknown');
?>
<div class="devrow" data-device-id="<?= e($uuid) ?>">
    <div class="devrow__id">
        <span class="devrow__icon" title="<?= e($osLabel) ?>"><?= icon(Icons::forOsFamily((string) ($device['os_family'] ?? ''))) ?></span>
        <span class="truncate">
            <a class="row__name truncate" href="/devices/<?= e($uuid) ?>"><?= e((string) $device['name']) ?></a>
            <span class="row__target truncate"><?= e((string) ($device['primary_ip'] ?? $device['hostname'])) ?></span>
        </span>
    </div>

    <div class="devrow__status">
        <span class="pill pill--<?= e($status) ?>"><?= e(t('device.status_' . $status)) ?></span>
    </div>

    <div class="devrow__os truncate" title="<?= e(trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? ''))) ?>">
        <?= e(trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? '')) ?: t('device.os_unknown')) ?>
    </div>

    <?php foreach ([
        ['label' => t('device.cpu'), 'percent' => $cpuPercent],
        ['label' => t('device.memory'), 'percent' => $memoryPercent],
        ['label' => t('device.storage'), 'percent' => $diskPercent],
    ] as $meter): ?>
        <div class="devrow__meter" title="<?= e($meter['label']) ?>">
            <span class="num"><?= $meter['percent'] === null ? '—' : (int) $meter['percent'] . '%' ?></span>
            <span class="devrow__track">
                <span class="meter__fill meter__fill--<?= e(meter_level($meter['percent'])) ?>"
                      style="width:<?= (int) ($meter['percent'] ?? 0) ?>%"></span>
            </span>
        </div>
    <?php endforeach; ?>

    <div class="devrow__tags">
        <?php if ($security > 0): ?>
            <span class="tag tag--down"><?= icon('shield', 'icon icon--sm') ?><?= $security ?></span>
        <?php elseif ($pending > 0): ?>
            <span class="tag"><?= icon('download', 'icon icon--sm') ?><?= $pending ?></span>
        <?php endif; ?>

        <?php if ((int) $device['reboot_required'] === 1): ?>
            <span class="tag tag--warn"><?= icon('refresh', 'icon icon--sm') ?></span>
        <?php endif; ?>

        <?php if (!empty($device['location_name'])): ?>
            <span class="tag truncate"><?= icon('pin', 'icon icon--sm') ?><?= e((string) $device['location_name']) ?></span>
        <?php endif; ?>
    </div>

    <div class="devrow__seen" data-since="<?= e((string) ($device['last_seen_at'] ?? '')) ?>">
        <?= e($device['last_seen_at'] === null ? t('device.never_reported') : format_since((string) $device['last_seen_at'])) ?>
    </div>

    <div class="row__actions">
        <a class="btn btn--ghost btn--icon" href="/devices/<?= e($uuid) ?>" title="<?= e((string) $device['name']) ?>">
            <?= icon('chevron') ?><span class="visually-hidden"><?= e((string) $device['name']) ?></span>
        </a>
    </div>
</div>
