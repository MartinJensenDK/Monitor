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
 * @var string $returnTo  this list's own address, so a command asked for from it comes back here
 */

use App\Agent\Scripts;
use App\Domain\DeviceCommands;
use App\Domain\Devices;
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

// The version, and whether it is the one on offer. Scripts::version reads the
// script once per platform and remembers, so asking per row costs nothing.
// Compared as versions, the same way the machine's own page compares them: a
// machine ahead of this server is not behind.
$agentRunning = (string) ($device['agent_version'] ?? '');
$agentOffered = Scripts::version((string) ($device['os_family'] ?? ''));
$agentKnown = $agentRunning !== '' && $agentOffered !== '0.0.0';
$agentBehind = $agentKnown && version_compare($agentRunning, $agentOffered) < 0;

// The waiting tags that are also something to do. Offered exactly where the
// button on the machine's own page would be: somebody who may change this
// machine, with commands on for it, asking for something the machine agreed to
// at install. Where it would refuse, the tag stays a tag and says why rather
// than offering a question whose answer is already no.
//
// Worked out only for a row that has something waiting, because canEdit can
// cost a query for somebody who is not an administrator, and most rows have
// nothing to act on.
$rebootOwed = (int) $device['reboot_required'] === 1;
$askable = false;
$withheld = [];
if ($pending > 0 || $rebootOwed) {
    $askable = (int) $device['commands_enabled'] === 1
        && can('devices.command')
        && can('devices.command_changes')
        && Devices::canEdit($device);
    $withheld = $askable ? DeviceCommands::withheld($device) : [];
}
$returnTo = isset($returnTo) ? (string) $returnTo : '';

$waitingTag = static function (string $command, string $tone, string $inner, string $label, string $question, string $detail)
    use ($askable, $withheld, $uuid, $returnTo): string {
    $refusal = $withheld[$command] ?? null;

    if (!$askable || $refusal !== null) {
        $title = $refusal === null ? $label : t('device.command_refused', ['flag' => $refusal]);

        return '<span class="tag' . $tone . '" title="' . e($title) . '">' . $inner . '</span>';
    }

    return '<form class="tag-form" method="post" action="/devices/' . e($uuid) . '/commands"'
        . ' data-confirm="' . e($question) . '"'
        . ' data-confirm-detail="' . e($detail) . '"'
        . ' data-confirm-label="' . e(DeviceCommands::label($command)) . '"'
        . ' data-confirm-tone="danger">'
        . csrf_field()
        . '<input type="hidden" name="command" value="' . e($command) . '">'
        . ($returnTo === '' ? '' : '<input type="hidden" name="return" value="' . e($returnTo) . '">')
        . '<button class="tag tag--action' . $tone . '" type="submit" title="' . e(DeviceCommands::label($command)) . '">'
        . $inner . '<span class="visually-hidden"> — ' . e($label) . '</span>'
        . '</button>'
        . '</form>';
};

$name = (string) $device['name'];
$installQuestion = t('device.confirm_install_title', ['name' => $name]);
$installDetail = $security > 0
    ? t('device.confirm_install_detail_security', ['count' => $pending, 'security' => $security])
    : t('device.confirm_install_detail', ['count' => $pending]);
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

    <div class="devrow__os truncate" title="<?= e($osLabel) ?>"><?= e($osLabel) ?></div>

    <div class="devrow__agent num<?= $agentBehind ? ' devrow__agent--behind' : '' ?>"
         title="<?= e($agentBehind
             ? t('device.agent_update_ready', ['version' => $agentOffered])
             : ($agentKnown ? t('device.agent_current') : t('device.never_reported'))) ?>">
        <?= e($agentRunning !== '' ? $agentRunning : '—') ?>
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
            <?= $waitingTag('install_updates', ' tag--down', icon('shield', 'icon icon--sm') . $security,
                t('device.updates_notice_security', ['count' => $pending, 'security' => $security]),
                $installQuestion, $installDetail) ?>
        <?php elseif ($pending > 0): ?>
            <?= $waitingTag('install_updates', '', icon('download', 'icon icon--sm') . $pending,
                t('device.updates_notice', ['count' => $pending]),
                $installQuestion, $installDetail) ?>
        <?php endif; ?>

        <?php if ($rebootOwed): ?>
            <?= $waitingTag('reboot', ' tag--warn', icon('refresh', 'icon icon--sm'),
                t('device.reboot_required'),
                t('device.confirm_reboot_title', ['name' => $name]),
                t('device.confirm_reboot_detail')) ?>
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
