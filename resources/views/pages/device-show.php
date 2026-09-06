<?php
/**
 * One machine, in full.
 *
 * @var array<string,mixed> $device
 * @var array<int,array<string,mixed>> $metrics
 * @var array<int,array<string,mixed>> $disks
 * @var array<int,array<string,mixed>> $updates
 * @var array<int,array<string,mixed>> $packages
 * @var int $packageTotal
 * @var string $packageSearch
 * @var array<int,array<string,mixed>> $services
 * @var array<int,array<string,mixed>> $ports
 * @var array<int,array<string,mixed>> $events
 * @var array<int,array<string,mixed>> $logs
 * @var bool $logBusy
 * @var array<int,array<string,mixed>> $commands
 * @var array<string,array<string,mixed>> $catalogue
 * @var bool $canEdit
 */

use App\Domain\Devices;
use App\Support\Sparkline;

$status = (string) $device['status'];
$uuid = (string) $device['uuid'];
$isServer = Devices::normaliseKind((string) $device['kind']) === Devices::KIND_SERVER;

$cpuSeries = [];
$memorySeries = [];
$diskSeries = [];
foreach ($metrics as $row) {
    $cpuSeries[] = $row['cpu_percent'] === null ? null : (float) $row['cpu_percent'];
    $memorySeries[] = percent_of(
        $row['memory_used_bytes'] === null ? null : (int) $row['memory_used_bytes'],
        $row['memory_total_bytes'] === null ? null : (int) $row['memory_total_bytes']
    );
    $diskSeries[] = percent_of(
        $row['disk_used_bytes'] === null ? null : (int) $row['disk_used_bytes'],
        $row['disk_total_bytes'] === null ? null : (int) $row['disk_total_bytes']
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

$facts = array_filter([
    t('device.operating_system') => trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? '')),
    t('device.kernel') => (string) ($device['kernel'] ?? ''),
    t('device.architecture') => (string) ($device['arch'] ?? ''),
    t('device.processor') => trim((string) ($device['cpu_model'] ?? '') . ((int) ($device['cpu_cores'] ?? 0) > 0 ? ' · ' . $device['cpu_cores'] . ' cores' : '')),
    t('device.memory') => format_bytes($device['memory_bytes'] === null ? null : (int) $device['memory_bytes']),
    t('device.hardware') => trim((string) ($device['manufacturer'] ?? '') . ' ' . (string) ($device['model'] ?? '')),
    t('device.serial') => (string) ($device['serial_number'] ?? ''),
    t('device.virtualisation') => (string) ($device['virtualisation'] ?? ''),
    t('device.hostname') => (string) $device['hostname'],
    t('device.address') => (string) ($device['primary_ip'] ?? ''),
    t('device.seen_from') => (string) ($device['report_ip'] ?? ''),
], static fn ($value): bool => trim((string) $value) !== '' && $value !== '—');
?>
<div class="devpage">

    <?php if ($canEdit): ?>
        <?php
        // What can be asked of this machine, as the page's own toolbar: it is
        // the only thing here that does something rather than says something,
        // and it stays put while the rest scrolls under it.
        //
        // The hint that was beside each button is now the button's own title,
        // hung on a wrapper because a disabled button does not reliably raise
        // one of its own -- and the buttons that are going to be refused are
        // exactly the ones that are disabled.
        $withheld = App\Domain\DeviceCommands::withheld($device);
        ?>
        <div class="devbar">
            <?php if ((int) $device['commands_enabled'] !== 1): ?>
                <p class="devbar__note" style="margin-left:0;"><?= e(t('device.commands_off')) ?></p>
            <?php else: ?>
                <?php foreach ($catalogue as $name => $meta): ?>
                    <?php if ($meta['changes'] && !can('devices.command_changes')) { continue; } ?>
                    <?php
                    $blocked = $withheld[$name] ?? null;
                    $why = $blocked === null
                        ? (string) $meta['hint']
                        : ($name === 'update_agent'
                            ? t('device.command_refused_pinned', ['flag' => $blocked])
                            : t('device.command_refused', ['flag' => $blocked]));
                    ?>
                    <span class="devbar__slot" title="<?= e($why) ?>">
                        <form method="post" action="/devices/<?= e($uuid) ?>/commands"
                              <?= $meta['changes'] && $blocked === null ? 'data-confirm="' . e(t('device.confirm_command', ['command' => $meta['label']])) . '"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="command" value="<?= e((string) $name) ?>">
                            <button class="btn btn--sm <?= $meta['changes'] ? 'btn--danger' : '' ?>" type="submit"
                                    <?= $blocked === null ? '' : 'disabled' ?>>
                                <?= icon((string) $meta['icon']) ?><?= e((string) $meta['label']) ?>
                            </button>
                        </form>
                    </span>
                <?php endforeach; ?>

                <p class="devbar__note">
                    <?= e((int) $device['poll_seconds'] > 0
                        ? t('device.commands_hint_live', ['poll' => format_duration((int) $device['poll_seconds'])])
                        : t('device.commands_hint')) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__head">
            <div class="devhead">
                <span class="devhead__icon" title="<?= e(trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? ''))) ?>"><?= icon(App\Support\Icons::forOsFamily((string) $device['os_family'])) ?></span>
                <div>
                    <p class="eyebrow">
                        <?= e($isServer ? t('nav.servers') : t('nav.clients')) ?>
                        <?php if (!empty($device['location_name'])): ?>
                            · <?= e((string) $device['location_name']) ?>
                        <?php endif; ?>
                    </p>
                    <h2><?= e((string) $device['name']) ?></h2>
                    <p class="muted mt-0">
                        <span class="pill pill--<?= e($status) ?>" data-device-status><?= e(t('device.status_' . $status)) ?></span>
                        <?= e($device['last_seen_at'] === null
                            ? t('device.never_reported')
                            : t('device.last_report', ['time' => format_since((string) $device['last_seen_at'])])) ?>
                        · <?= e(t('device.every', ['interval' => format_duration((int) $device['interval_seconds'])])) ?>
                        <?php if ((int) $device['poll_seconds'] > 0): ?>
                            · <?= e(t('device.live_every', ['poll' => format_duration((int) $device['poll_seconds'])])) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="btn-row" style="margin-left:auto;">
                <a class="btn" href="<?= e($isServer ? '/servers' : '/clients') ?>"><?= icon('arrow-left') ?><?= e(t('action.back')) ?></a>
                <?php if ($canEdit): ?>
                    <a class="btn" href="/devices/<?= e($uuid) ?>/edit"><?= icon('edit') ?><?= e(t('action.edit')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel__body">
            <div class="grid grid--gauges">
                <?php foreach ([
                    ['label' => t('device.cpu'), 'percent' => $cpuPercent, 'series' => $cpuSeries, 'foot' => (string) ($device['load1'] ?? '') !== '' ? t('device.load', ['value' => $device['load1']]) : ''],
                    ['label' => t('device.memory'), 'percent' => $memoryPercent, 'series' => $memorySeries, 'foot' => format_bytes($device['memory_used_bytes'] === null ? null : (int) $device['memory_used_bytes']) . ' / ' . format_bytes($device['memory_bytes'] === null ? null : (int) $device['memory_bytes'])],
                    ['label' => t('device.storage'), 'percent' => $diskPercent, 'series' => $diskSeries, 'foot' => format_bytes($device['disk_used_bytes'] === null ? null : (int) $device['disk_used_bytes']) . ' / ' . format_bytes($device['disk_total_bytes'] === null ? null : (int) $device['disk_total_bytes'])],
                ] as $gauge): ?>
                    <div class="gauge gauge--<?= e(meter_level($gauge['percent'])) ?>">
                        <p class="eyebrow"><?= e($gauge['label']) ?></p>
                        <p class="gauge__value num"><?= $gauge['percent'] === null ? '—' : (int) $gauge['percent'] . '<span class="gauge__unit">%</span>' ?></p>
                        <div class="gauge__spark"><?= Sparkline::svg($gauge['series']) ?></div>
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
            </div>
        </div>
    </section>

    <?php if ((int) $device['reboot_required'] === 1 || (int) $device['updates_security'] > 0): ?>
        <div class="flashes" style="margin-top:18px;">
            <?php if ((int) $device['updates_security'] > 0): ?>
                <div class="flash flash--error" role="status">
                    <?= icon('shield') ?>
                    <span><?= e(t('device.security_banner', ['count' => (int) $device['updates_security']])) ?></span>
                </div>
            <?php endif; ?>
            <?php if ((int) $device['reboot_required'] === 1): ?>
                <div class="flash flash--warning" role="status">
                    <?= icon('refresh') ?>
                    <span><?= e(t('device.reboot_banner')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cols cols--sidebar" style="margin-top:18px;">
        <div class="stack">

            <?php
            // What the agent says about itself, as it says it. The cursor is
            // the last row id rather than a time, because the agent's clock is
            // not to be trusted for ordering and two lines can share a second.
            $logCursor = $logs === [] ? 0 : (int) $logs[count($logs) - 1]['id'];
            ?>
            <section class="panel panel--log" data-log="<?= e($uuid) ?>"
                     data-log-since="<?= $logCursor ?>" data-log-busy="<?= $logBusy ? '1' : '0' ?>">
                <div class="panel__head">
                    <h2><?= icon('terminal') ?><?= e(t('device.log')) ?></h2>
                    <span class="pill pill--pending log__live" hidden><?= e(t('device.log_live')) ?></span>
                </div>
                <div class="panel__body">
                    <p class="field__hint mt-0"><?= e(t('device.log_hint')) ?></p>
                    <ol class="log" data-log-lines>
                        <?php foreach ($logs as $line): ?>
                            <li class="log__line log__line--<?= e((string) $line['level']) ?>">
                                <span class="log__at"><?= e(local_time((string) $line['received_at'], 'H:i:s')) ?></span>
                                <span class="log__text"><?= e((string) $line['message']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="muted log__empty"<?= $logs === [] ? '' : ' hidden' ?>><?= e(t('device.log_empty')) ?></p>
                </div>
            </section>

            <?php if ($disks !== []): ?>
                <section class="panel">
                    <div class="panel__head"><h2><?= icon('disk') ?><?= e(t('device.disks')) ?></h2></div>
                    <div class="panel__body">
                        <div class="stack">
                            <?php foreach ($disks as $disk): ?>
                                <?php $percent = percent_of((int) $disk['used_bytes'], (int) $disk['total_bytes']); ?>
                                <div class="meter meter--wide">
                                    <div class="meter__top">
                                        <span class="meter__label">
                                            <strong><?= e((string) $disk['mount']) ?></strong>
                                            <span class="muted"><?= e(trim((string) ($disk['source'] ?? '') . ' ' . (string) ($disk['filesystem'] ?? ''))) ?></span>
                                        </span>
                                        <span class="meter__value num">
                                            <?= e(format_bytes((int) $disk['used_bytes'])) ?> / <?= e(format_bytes((int) $disk['total_bytes'])) ?>
                                            · <?= $percent === null ? '—' : (int) $percent . '%' ?>
                                        </span>
                                    </div>
                                    <div class="meter__track">
                                        <span class="meter__fill meter__fill--<?= e(meter_level($percent)) ?>" style="width:<?= (int) ($percent ?? 0) ?>%"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel__head">
                    <h2><?= icon('download') ?><?= e(t('device.pending_updates')) ?></h2>
                    <span class="muted" style="margin-left:auto;"><?= (int) $device['updates_total'] ?> <?= e(t('device.waiting')) ?></span>
                </div>
                <?php if ($updates === []): ?>
                    <div class="panel__body"><p class="muted mt-0"><?= e(t('device.updates_none')) ?></p></div>
                <?php else: ?>
                    <div class="table-wrap table-wrap--tall">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= e(t('device.package')) ?></th>
                                    <th><?= e(t('device.installed')) ?></th>
                                    <th><?= e(t('device.available')) ?></th>
                                    <th><?= e(t('device.source')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($updates as $update): ?>
                                    <tr>
                                        <td>
                                            <?php if ((int) $update['is_security'] === 1): ?>
                                                <span class="tag tag--down"><?= e(t('device.security')) ?></span>
                                            <?php endif; ?>
                                            <?= e((string) $update['name']) ?>
                                        </td>
                                        <td class="num muted"><?= e((string) ($update['current_version'] ?? '—')) ?></td>
                                        <td class="num"><?= e((string) ($update['available_version'] ?? '—')) ?></td>
                                        <td class="muted truncate"><?= e((string) ($update['source'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($services !== []): ?>
                <section class="panel">
                    <div class="panel__head">
                        <h2><?= icon('chip') ?><?= e(t('device.services')) ?></h2>
                        <span class="muted" style="margin-left:auto;"><?= count($services) ?></span>
                    </div>
                    <div class="table-wrap table-wrap--tall">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= e(t('device.service')) ?></th>
                                    <th><?= e(t('device.state')) ?></th>
                                    <th><?= e(t('device.startup')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services as $service): ?>
                                    <?php $state = strtolower((string) ($service['state'] ?? '')); ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) $service['name']) ?></strong>
                                            <?php if (!empty($service['display_name'])): ?>
                                                <span class="muted block truncate"><?= e((string) $service['display_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="pill pill--<?= $state === 'running' ? 'up' : ($state === 'failed' ? 'down' : 'paused') ?>">
                                                <?= e($state !== '' ? $state : '—') ?>
                                            </span>
                                        </td>
                                        <td class="muted"><?= e((string) ($service['startup'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($ports !== []): ?>
                <section class="panel">
                    <div class="panel__head">
                        <h2><?= icon('plug') ?><?= e(t('device.listening')) ?></h2>
                        <span class="muted" style="margin-left:auto;"><?= count($ports) ?></span>
                    </div>
                    <div class="table-wrap table-wrap--tall">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= e(t('device.port')) ?></th>
                                    <th><?= e(t('device.protocol')) ?></th>
                                    <th><?= e(t('device.bound_to')) ?></th>
                                    <th><?= e(t('device.process')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ports as $port): ?>
                                    <tr>
                                        <td class="num"><strong><?= (int) $port['port'] ?></strong></td>
                                        <td class="muted"><?= e((string) $port['protocol']) ?></td>
                                        <td class="num muted"><?= e((string) ($port['address'] ?? '')) ?></td>
                                        <td><?= e((string) ($port['process'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($packageTotal > 0 || $packageSearch !== ''): ?>
                <section class="panel">
                    <div class="panel__head">
                        <h2><?= icon('package') ?><?= e(t('device.software')) ?></h2>
                        <form class="filters" method="get" action="/devices/<?= e($uuid) ?>" style="margin-left:auto;">
                            <label class="search">
                                <?= icon('search') ?>
                                <span class="visually-hidden"><?= e(t('action.search')) ?></span>
                                <input class="input" type="search" name="q" value="<?= e($packageSearch) ?>"
                                       placeholder="<?= e(t('device.software_search')) ?>">
                            </label>
                        </form>
                    </div>
                    <?php if ($packages === []): ?>
                        <div class="panel__body"><p class="muted mt-0"><?= e(t('device.software_none')) ?></p></div>
                    <?php else: ?>
                        <div class="table-wrap table-wrap--tall">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><?= e(t('device.name')) ?></th>
                                        <th><?= e(t('device.version')) ?></th>
                                        <th><?= e(t('device.publisher')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($packages as $package): ?>
                                        <tr>
                                            <td><?= e((string) $package['name']) ?></td>
                                            <td class="num muted"><?= e((string) ($package['version'] ?? '')) ?></td>
                                            <td class="muted truncate"><?= e((string) ($package['publisher'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($packageTotal > count($packages)): ?>
                            <div class="panel__body">
                                <p class="muted mt-0"><?= e(t('device.software_more', ['shown' => count($packages), 'total' => $packageTotal])) ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="stack">
            <section class="panel">
                <div class="panel__head"><h2><?= e(t('device.facts')) ?></h2></div>
                <div class="panel__body">
                    <dl class="facts">
                        <?php foreach ($facts as $label => $value): ?>
                            <dt><?= e((string) $label) ?></dt>
                            <dd class="truncate" title="<?= e((string) $value) ?>"><?= e((string) $value) ?></dd>
                        <?php endforeach; ?>
                        <dt><?= e(t('device.agent')) ?></dt>
                        <?php
                        // What this server would hand out, next to what the
                        // machine is running: the two disagreeing is the whole
                        // of why an update is or is not on its way. Compared
                        // as versions rather than as strings, so a machine
                        // that is ahead of this server -- which is what a
                        // rolled-back script here looks like -- is not told
                        // to fetch an update that would take it backwards. A
                        // machine that has never said which agent it runs is
                        // not behind, it is unknown, and says nothing.
                        $running = (string) ($device['agent_version'] ?? '');
                        $offered = App\Agent\Scripts::version((string) $device['os_family']);
                        $compared = $running !== '' && $offered !== '0.0.0'
                            ? version_compare($running, $offered)
                            : 0;
                        $offering = App\Domain\AgentPolicy::mayOfferUpdates();
                        ?>
                        <dd>
                            <?= e($running !== '' ? $running : '—') ?>
                            <?php if ($compared < 0): ?>
                                <span class="pill pill--degraded"><?= e(t('device.agent_update_ready', ['version' => $offered])) ?></span>
                                <span class="muted block"><?php
                                    // The pill says a newer one exists either
                                    // way. This line says whether anything is
                                    // going to come of that, and the site
                                    // switch is the first thing in the way.
                                    if (!$offering) {
                                        echo e(t('device.agent_updates_off', ['version' => $offered]));
                                    } elseif ((int) $device['self_update'] === 1) {
                                        echo e(t('device.agent_updating', ['version' => $offered]));
                                    } else {
                                        echo e(t('device.agent_behind', ['version' => $offered]));
                                    }
                                ?></span>
                            <?php elseif ($compared > 0): ?>
                                <span class="muted block"><?= e(t('device.agent_ahead', ['version' => $offered])) ?></span>
                            <?php elseif ((int) $device['self_update'] !== 1): ?>
                                <span class="muted block"><?= e(t('device.agent_pinned')) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt><?= e(t('device.enrolled')) ?></dt>
                        <dd><?= e(local_time((string) $device['enrolled_at'], 'Y-m-d H:i')) ?></dd>
                        <dt><?= e(t('device.token')) ?></dt>
                        <dd class="num"><?= e((string) $device['token_hint']) ?>…</dd>
                    </dl>
                    <?php if (!empty($device['notes'])): ?>
                        <p class="muted"><?= e((string) $device['notes']) ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($canEdit): ?>
                <section class="panel">
                    <div class="panel__head"><h2><?= icon('history') ?><?= e(t('device.commands_recent')) ?></h2></div>
                    <div class="panel__body">
                        <?php if ($commands === []): ?>
                            <p class="muted mt-0"><?= e(t('device.commands_none_yet')) ?></p>
                        <?php else: ?>
                            <div class="table-wrap" style="margin-top:16px;">
                                <table class="table table--compact">
                                    <tbody>
                                        <?php foreach ($commands as $command): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= e(App\Domain\DeviceCommands::label((string) $command['command'])) ?></strong>
                                                    <span class="muted block"><?= e(format_since((string) $command['requested_at'])) ?><?= empty($command['requested_by_name']) ? '' : ' · ' . e((string) $command['requested_by_name']) ?></span>
                                                    <?php if (!empty($command['error'])): ?>
                                                        <span class="muted block truncate" title="<?= e((string) $command['error']) ?>"><?= e((string) $command['error']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="table__right">
                                                    <?php $state = (string) $command['status']; ?>
                                                    <span class="pill pill--<?= $state === 'done' ? 'up' : ($state === 'failed' ? 'down' : ($state === 'queued' || $state === 'claimed' ? 'pending' : 'paused')) ?>">
                                                        <?= e($state) ?>
                                                    </span>
                                                    <?php if ($state === 'queued'): ?>
                                                        <form method="post" action="/devices/<?= e($uuid) ?>/commands/<?= (int) $command['id'] ?>/cancel">
                                                            <?= csrf_field() ?>
                                                            <button class="btn btn--sm btn--ghost" type="submit"><?= e(t('action.cancel')) ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel__head"><h2><?= icon('history') ?><?= e(t('device.timeline')) ?></h2></div>
                <div class="panel__body">
                    <?php if ($events === []): ?>
                        <p class="muted mt-0"><?= e(t('device.timeline_empty')) ?></p>
                    <?php else: ?>
                        <ol class="timeline">
                            <?php foreach ($events as $event): ?>
                                <li class="timeline__item timeline__item--<?= e((string) $event['severity']) ?>">
                                    <p class="timeline__text"><?= e((string) $event['summary']) ?></p>
                                    <p class="timeline__when muted"><?= e(local_time((string) $event['created_at'], 'Y-m-d H:i')) ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
