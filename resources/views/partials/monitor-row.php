<?php
/**
 * One monitor in a list: identity, status, its tape, and the two numbers that
 * matter at a glance.
 *
 * @var array<string,mixed> $monitor
 * @var array<int,array<string,mixed>> $tape
 */

use App\Domain\Monitors;
use App\Support\Icons;
use App\Support\Tape;

$status = (string) ($monitor['status'] ?? 'pending');
?>
<div class="row" data-monitor-id="<?= (int) $monitor['id'] ?>">
    <div class="row__id">
        <span class="row__type" title="<?= e(Monitors::typeLabel((string) $monitor['type'])) ?>">
            <?= icon(Icons::forMonitorType((string) $monitor['type'])) ?>
        </span>
        <span class="truncate">
            <a class="row__name truncate" href="/monitors/<?= (int) $monitor['id'] ?>"><?= e((string) $monitor['name']) ?></a>
            <span class="row__target truncate"><?= e((string) $monitor['target']) ?></span>
        </span>
    </div>

    <div class="row__status">
        <span class="pill pill--<?= e($status) ?>" data-live-status><?= e(t('status.' . $status)) ?></span>
    </div>

    <div class="row__tape tape-wrap" data-tape="row" data-tape-count="<?= count($tape) ?>"
         data-tape-label="<?= e('Recent checks for ' . $monitor['name']) ?>">
        <?= Tape::svg($tape, 'row', 'Recent checks for ' . $monitor['name']) ?>
    </div>

    <div class="row__metric">
        <span data-live-latency><?= e(format_ms($monitor['last_response_ms'] === null ? null : (int) $monitor['last_response_ms'])) ?></span>
        <small data-live-seen data-relative="<?= e($monitor['last_check_at'] ? str_replace(' ', 'T', (string) $monitor['last_check_at']) . 'Z' : '') ?>">
            <?= e(format_since($monitor['last_check_at'])) ?>
        </small>
    </div>

    <div class="row__metric row__uptime30">
        <span data-live-uptime><?= e(format_uptime($monitor['uptime_24h'] === null ? null : (float) $monitor['uptime_24h'])) ?></span>
        <small>24h</small>
    </div>

    <div class="row__actions">
        <a class="btn btn--ghost btn--icon" href="/monitors/<?= (int) $monitor['id'] ?>" title="Open">
            <?= icon('chevron') ?><span class="visually-hidden">Open <?= e((string) $monitor['name']) ?></span>
        </a>
    </div>
</div>
