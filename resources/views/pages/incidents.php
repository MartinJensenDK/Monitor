<?php
/**
 * @var array<int,array<string,mixed>> $incidents
 * @var string $status
 * @var int $openCount
 */
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <p class="eyebrow"><?= (int) $openCount ?> open</p>
            <h2>Every interruption, newest first</h2>
        </div>
        <div class="seg">
            <?php foreach (['all' => 'All', 'open' => 'Open', 'resolved' => 'Resolved'] as $value => $label): ?>
                <a href="/incidents?status=<?= e($value) ?>" aria-current="<?= $status === $value ? 'true' : 'false' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($incidents === []): ?>
        <div class="empty">
            <h3>Nothing to report</h3>
            <p><?= e(t('incident.none')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Monitor</th>
                        <th><?= e(t('incident.started')) ?></th>
                        <th><?= e(t('incident.duration')) ?></th>
                        <th><?= e(t('incident.cause')) ?></th>
                        <th>Checks failed</th>
                        <th class="table__right">Acknowledged</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $incident): ?>
                        <tr>
                            <td>
                                <a href="/monitors/<?= (int) $incident['monitor_id'] ?>"><?= e((string) $incident['monitor_name']) ?></a>
                                <span class="row__target truncate"><?= e((string) $incident['target']) ?></span>
                            </td>
                            <td class="num nowrap"><?= e(local_time((string) $incident['started_at'], 'M j, H:i')) ?></td>
                            <td class="nowrap">
                                <?php if ($incident['status'] === 'open'): ?>
                                    <span class="pill pill--down"><?= e(t('incident.ongoing')) ?></span>
                                <?php else: ?>
                                    <span class="num"><?= e(format_duration((int) $incident['duration_seconds'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= e((string) ($incident['last_error'] ?? $incident['cause'] ?? '')) ?></td>
                            <td class="num"><?= (int) $incident['failed_checks'] ?></td>
                            <td class="table__right nowrap">
                                <?php if ($incident['acknowledged_at'] !== null): ?>
                                    <span class="tag"><?= icon('check', 'icon') ?><?= e((string) $incident['acknowledged_by_name']) ?></span>
                                <?php elseif (can('incidents.acknowledge')): ?>
                                    <form method="post" action="/incidents/<?= (int) $incident['id'] ?>/acknowledge">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--sm" type="submit"><?= e(t('action.acknowledge')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
