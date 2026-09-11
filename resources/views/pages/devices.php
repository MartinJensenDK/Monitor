<?php
/**
 * Servers, or Clients. The same page either way -- what differs is one column
 * in the query and the words on it.
 *
 * @var string $kind
 * @var array<int,array<string,mixed>> $devices
 * @var array<string,int> $summary
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $groups
 * @var bool $hasKeys
 * @var string $view 'cards' or 'list'
 */

use App\Core\View;
use App\Domain\Devices;

$isServers = $kind === Devices::KIND_SERVER;
$base = $isServers ? '/servers' : '/clients';
$quiet = $summary['offline'] + $summary['stale'];
$noun = $isServers ? t('device.servers_lower') : t('device.clients_lower');

// The same list, with whichever view is not showing on the end of it, so the
// toggle keeps every filter somebody has already set.
$viewLink = static function (string $wanted) use ($base, $filters): string {
    $query = array_filter([
        'q' => $filters['q'],
        'status' => $filters['status'],
        'os' => $filters['os'],
        'group' => $filters['group'] > 0 ? (string) $filters['group'] : '',
        'location' => $filters['location'] > 0 ? (string) $filters['location'] : '',
        'view' => $wanted,
    ], static fn ($value): bool => (string) $value !== '');

    return $base . '?' . http_build_query($query);
};
?>
<div class="devpage">

    <section class="grid grid--stats">
        <div class="stat">
            <p class="eyebrow"><?= e(t('device.reporting')) ?></p>
            <p class="stat__value stat__value--up num"><?= (int) $summary['online'] ?><span class="stat__of">/<?= (int) $summary['total'] ?></span></p>
            <p class="stat__foot"><?= e(t('device.checked_in_recently')) ?></p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('device.quiet')) ?></p>
            <p class="stat__value <?= $quiet > 0 ? 'stat__value--down' : '' ?> num"><?= (int) $quiet ?></p>
            <p class="stat__foot">
                <?= $quiet === 0
                    ? e(t('device.everything_reporting'))
                    : e(t('device.quiet_foot', ['stale' => $summary['stale'], 'offline' => $summary['offline']])) ?>
            </p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('device.security_updates')) ?></p>
            <p class="stat__value <?= $summary['security'] > 0 ? 'stat__value--down' : '' ?> num"><?= (int) $summary['security'] ?></p>
            <p class="stat__foot"><?= e(t('device.machines_with_security', ['count' => $summary['updates']])) ?></p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('device.awaiting_restart')) ?></p>
            <p class="stat__value <?= $summary['reboot'] > 0 ? 'stat__value--warn' : '' ?> num"><?= (int) $summary['reboot'] ?></p>
            <p class="stat__foot"><?= e(t('device.reboot_foot')) ?></p>
        </div>
    </section>

    <section class="panel" style="margin-top:18px;">
        <div class="panel__head">
            <form class="filters" method="get" action="<?= e($base) ?>">
                <label class="search">
                    <?= icon('search') ?>
                    <span class="visually-hidden"><?= e(t('action.search')) ?></span>
                    <input class="input" type="search" name="q" value="<?= e((string) $filters['q']) ?>"
                           placeholder="<?= e(t('device.search_placeholder')) ?>">
                </label>

                <select class="select" name="status" data-autosubmit style="width:auto;">
                    <?php foreach ([
                        '' => t('device.any_status'),
                        'attention' => t('device.needs_attention'),
                        'online' => t('device.status_online'),
                        'stale' => t('device.status_stale'),
                        'offline' => t('device.status_offline'),
                        'pending' => t('device.status_pending'),
                        'disabled' => t('device.status_disabled'),
                    ] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (string) $filters['status'] === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <select class="select" name="os" data-autosubmit style="width:auto;">
                    <?php foreach (['' => t('device.any_os'), 'linux' => 'Linux', 'windows' => 'Windows', 'macos' => 'macOS', 'other' => t('device.os_other')] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (string) $filters['os'] === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($groups !== []): ?>
                    <select class="select" name="group" data-autosubmit style="width:auto;">
                        <option value="0"><?= e(t('device.any_group')) ?></option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $filters['group'] === (int) $group['id'] ? 'selected' : '' ?>>
                                <?= e((string) $group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($locations !== []): ?>
                    <select class="select" name="location" data-autosubmit style="width:auto;">
                        <option value="0"><?= e(t('monitor.any_location')) ?></option>
                        <?php foreach ($locations as $place): ?>
                            <option value="<?= (int) $place['id'] ?>" <?= (int) $filters['location'] === (int) $place['id'] ? 'selected' : '' ?>>
                                <?= e((string) $place['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <input type="hidden" name="view" value="<?= e($view) ?>">
                <button class="btn" type="submit"><?= e(t('action.filter')) ?></button>
            </form>

            <div class="btn-row">
                <span class="viewtoggle" role="group" aria-label="<?= e(t('device.view')) ?>">
                    <?php foreach ([
                        'cards' => ['icon' => 'grid', 'label' => t('device.view_cards')],
                        'list' => ['icon' => 'list', 'label' => t('device.view_list')],
                    ] as $option => $meta): ?>
                        <a class="viewtoggle__option" href="<?= e($viewLink($option)) ?>"
                           data-view-choice="<?= e($option) ?>"
                           aria-current="<?= $view === $option ? 'true' : 'false' ?>"
                           title="<?= e($meta['label']) ?>"><?= icon($meta['icon'], 'icon icon--sm') ?><span class="visually-hidden"><?= e($meta['label']) ?></span></a>
                    <?php endforeach; ?>
                </span>

                <?php if (can('devices.enroll')): ?>
                    <a class="btn btn--primary" href="/devices/enrollment"><?= icon('plus') ?><?= e(t('action.add_machine')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($devices === []): ?>
            <div class="empty">
                <?php if ($summary['total'] === 0 && !$hasKeys): ?>
                    <h3><?= e(t('device.empty_title', ['kind' => $noun])) ?></h3>
                    <p>
                        A machine gets here by running a small agent that reports in over HTTPS.
                        Nothing is opened on the machine and nothing is installed on this server.
                        Make an enrolment key, then run one command on the machine.
                    </p>
                    <?php if (can('devices.enroll')): ?>
                        <a class="btn btn--primary" href="/devices/enrollment"><?= icon('key') ?><?= e(t('action.make_key')) ?></a>
                    <?php else: ?>
                        <p class="muted">Ask an administrator for an enrolment key.</p>
                    <?php endif; ?>
                <?php elseif ($summary['total'] === 0): ?>
                    <h3><?= e(t('device.empty_title', ['kind' => $noun])) ?></h3>
                    <p>
                        There is an enrolment key ready. Run the installer on a machine and it will
                        appear here within a minute.
                    </p>
                    <?php if (can('devices.enroll')): ?>
                        <a class="btn" href="/devices/enrollment"><?= icon('terminal') ?><?= e(t('action.show_install')) ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <h3><?= e(t('device.no_match_title')) ?></h3>
                    <p><?= e(t('device.no_match_body')) ?></p>
                    <a class="btn" href="<?= e($base) ?>"><?= e(t('action.clear_filters')) ?></a>
                <?php endif; ?>
            </div>
        <?php elseif ($view === 'list'): ?>
            <div class="devrows">
                <div class="devrow devrow--head">
                    <span class="eyebrow"><?= e(t('device.machine')) ?></span>
                    <span class="eyebrow"><?= e(t('device.status')) ?></span>
                    <span class="eyebrow"><?= e(t('device.operating_system')) ?></span>
                    <span class="eyebrow"><?= e(t('device.agent')) ?></span>
                    <span class="eyebrow" style="text-align:right;"><?= e(t('device.cpu')) ?></span>
                    <span class="eyebrow" style="text-align:right;"><?= e(t('device.memory')) ?></span>
                    <span class="eyebrow" style="text-align:right;"><?= e(t('device.storage')) ?></span>
                    <span class="eyebrow"><?= e(t('device.waiting_head')) ?></span>
                    <span class="eyebrow" style="text-align:right;"><?= e(t('device.last_seen')) ?></span>
                    <span></span>
                </div>
                <?php $here = $viewLink('list'); ?>
                <?php foreach ($devices as $device): ?>
                    <?= View::partial('partials/device-row', ['device' => $device, 'returnTo' => $here]) ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="panel__body">
                <div class="devgrid">
                    <?php foreach ($devices as $device): ?>
                        <?= View::partial('partials/device-card', ['device' => $device]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
