<?php
/** @var array<int,array<string,mixed>> $locations */
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <p class="eyebrow"><?= e(t('dashboard.where')) ?></p>
            <h2><?= e(t('location.list_title')) ?></h2>
        </div>
        <div class="btn-row">
            <a class="btn btn--primary" href="/locations/new"><?= icon('plus') ?><?= e(t('action.add_location')) ?></a>
        </div>
    </div>

    <?php if ($locations === []): ?>
        <div class="empty">
            <h3><?= e(t('location.empty_title')) ?></h3>
            <p><?= e(t('location.empty_body')) ?></p>
            <a class="btn btn--primary" href="/locations/new"><?= icon('plus') ?><?= e(t('action.add_location')) ?></a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('location.name')) ?></th>
                        <th><?= e(t('location.address')) ?></th>
                        <th class="table__right"><?= e(t('location.coordinates')) ?></th>
                        <th class="table__right"><?= e(t('nav.monitors')) ?></th>
                        <th class="table__right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td><strong><?= e((string) $location['name']) ?></strong></td>
                            <td class="muted"><?= e((string) ($location['address'] ?? '')) ?></td>
                            <td class="num table__right nowrap">
                                <?= e(number_format((float) $location['latitude'], 4)) ?>,
                                <?= e(number_format((float) $location['longitude'], 4)) ?>
                            </td>
                            <td class="num table__right"><?= (int) $location['monitor_count'] ?></td>
                            <td class="table__right">
                                <a class="btn btn--sm" href="/locations/<?= (int) $location['id'] ?>"><?= e(t('action.edit')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
