<?php
/**
 * The line under a machine's name: what it is doing, and how often it says so.
 *
 * Its own file because the live channel sends it too. Everything in it goes out
 * of date on its own -- the status turns to "late" while nobody touches the
 * page, and "4m ago" is wrong a minute after it is written.
 *
 * @var array<string,mixed> $device
 */

$status = (string) $device['status'];
?>
<span class="pill pill--<?= e($status) ?>"><?= e(t('device.status_' . $status)) ?></span>
<span data-since="<?= e((string) ($device['last_seen_at'] ?? '')) ?>"><?= e($device['last_seen_at'] === null
    ? t('device.never_reported')
    : t('device.last_report', ['time' => format_since((string) $device['last_seen_at'])])) ?></span>
· <?= e(t('device.every', ['interval' => format_duration((int) $device['interval_seconds'])])) ?>
<?php if ((int) $device['poll_seconds'] > 0): ?>
    · <?= e(t('device.live_every', ['poll' => format_duration((int) $device['poll_seconds'])])) ?>
<?php endif; ?>
