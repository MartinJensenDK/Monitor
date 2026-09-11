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

// The same list, with whichever view is not showing on the end of it, so the
// toggle keeps every filter somebody has already set.
$viewLink = static function (string $wanted) use ($base, $listQuery): string {
    return $base . '?' . http_build_query($listQuery + ['view' => $wanted]);
};
?>
<div class="devpage">

    <?= View::partial('partials/device-stats', ['summary' => $summary]) ?>

    <?php // Where this list asks for itself again: the same filters and view, so
          // the answer is this list and not a different one. ?>
    <section class="panel" style="margin-top:18px;"
             data-devlist-src="<?= e('/api/devices/list?' . http_build_query(['kind' => $kind] + $listQuery + ['view' => $view])) ?>">
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
                <?php // One button for the whole list, as it is filtered: the
                      // filters travel in the address, and the server works
                      // the list out again from them rather than trusting a
                      // list of machines sent by the page. Not shown when
                      // there is nobody on this list it could ask. ?>
                <?php if (($checkable ?? 0) > 0): ?>
                    <form method="post"
                          action="<?= e($base . '/check-updates?' . http_build_query($listQuery + ['view' => $view])) ?>"
                          data-confirm="<?= e(t('device.check_updates_all_confirm')) ?>"
                          data-confirm-detail="<?= e(t('device.check_updates_all_detail')) ?>"
                          data-confirm-label="<?= e(t('device.check_updates_all')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn" type="submit"><?= icon('history') ?><?= e(t('device.check_updates_all')) ?></button>
                    </form>
                <?php endif; ?>

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

        <?= View::partial('partials/device-list-body', [
            'kind' => $kind,
            'devices' => $devices,
            'view' => $view,
            'summary' => $summary,
            'hasKeys' => $hasKeys,
            'listQuery' => $listQuery,
        ]) ?>
    </section>
</div>
