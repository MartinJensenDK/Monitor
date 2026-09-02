<?php
/**
 * @var array<int,array<string,mixed>> $monitors
 * @var array<int,array<int,array<string,mixed>>> $tapes
 * @var array<string,mixed> $filters
 * @var array<int,array<string,mixed>> $groups
 * @var array<int,array<string,mixed>> $locations
 * @var array<string,int> $counts
 */

use App\Core\View;
use App\Domain\Monitors;

$queryFor = static function (array $overrides) use ($filters): string {
    $query = array_filter(array_merge($filters, $overrides), static fn ($v): bool => $v !== '' && $v !== 'all' && $v !== 0);

    return $query === [] ? '/monitors' : '/monitors?' . http_build_query($query);
};
?>
<div data-live-scope="dashboard" data-live-interval="5000">
    <section class="panel">
        <div class="panel__head">
            <form class="filters" method="get" action="/monitors">
                <label class="search">
                    <?= icon('search') ?>
                    <span class="visually-hidden"><?= e(t('action.search')) ?></span>
                    <input class="input" type="search" name="q" value="<?= e((string) $filters['q']) ?>"
                           placeholder="Name or URL">
                </label>

                <select class="select" name="status" data-autosubmit style="width:auto;">
                    <?php foreach (['all' => 'Any status', 'up' => 'Up', 'degraded' => 'Degraded', 'down' => 'Down', 'paused' => 'Paused', 'pending' => 'Pending'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($groups !== []): ?>
                    <select class="select" name="group" data-autosubmit style="width:auto;">
                        <option value="0">Any group</option>
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

                <button class="btn" type="submit"><?= e(t('action.filter')) ?></button>
            </form>

            <?php if (can('monitors.create')): ?>
                <div class="btn-row">
                    <a class="btn btn--primary" href="/monitors/new"><?= icon('plus') ?><?= e(t('action.add_monitor')) ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($monitors === []): ?>
            <div class="empty">
                <h3>Nothing matches</h3>
                <p>
                    <?= $counts['total'] === 0
                        ? e(t('dashboard.empty_body'))
                        : 'No monitor matches these filters. Clear them to see all ' . (int) $counts['total'] . '.' ?>
                </p>
                <?php if ($counts['total'] > 0): ?>
                    <a class="btn" href="/monitors">Clear filters</a>
                <?php elseif (can('monitors.create')): ?>
                    <a class="btn btn--primary" href="/monitors/new"><?= icon('plus') ?><?= e(t('action.add_monitor')) ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rows">
                <div class="row row--head">
                    <span class="eyebrow"><?= e(t('monitor.name')) ?></span>
                    <span class="eyebrow"><?= e(t('monitor.status')) ?></span>
                    <span class="eyebrow">Recent checks</span>
                    <span class="eyebrow" style="text-align:right;"><?= e(t('monitor.response')) ?></span>
                    <span class="eyebrow row__uptime30" style="text-align:right;"><?= e(t('monitor.uptime')) ?></span>
                    <span></span>
                </div>
                <?php foreach ($monitors as $monitor): ?>
                    <?= View::partial('partials/monitor-row', [
                        'monitor' => $monitor,
                        'tape' => $tapes[(int) $monitor['id']] ?? [],
                    ]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
