<?php
/**
 * The status board. The fleet tape at the top is the hero: every check across
 * every monitor the viewer can see, newest on the right.
 *
 * @var array<int,array<string,mixed>> $monitors
 * @var array<int,array<int,array<string,mixed>>> $tapes
 * @var array<string,int> $counts
 * @var ?float $fleetUptime
 * @var ?int $fleetLatency
 * @var int $downtime
 * @var int $openIncidents
 * @var array<int,array<string,mixed>> $incidents
 * @var array{t:array<int,int>,avg:array<int,int|null>,fail:array<int,float>} $series
 * @var string $range
 * @var array<int,array<string,mixed>> $groups
 */

use App\Core\View;
use App\Domain\Stats;
use App\Support\Tape;

$fleetTape = [];
foreach ($tapes as $points) {
    foreach ($points as $point) {
        $fleetTape[] = $point;
    }
}
usort($fleetTape, static fn (array $a, array $b): int => strcmp((string) $a['checked_at'], (string) $b['checked_at']));
$fleetTape = array_slice($fleetTape, -160);
?>
<div data-live-scope="dashboard" data-live-interval="5000">

    <section class="panel">
        <div class="panel__head">
            <div>
                <p class="eyebrow"><?= e(t('dashboard.live')) ?></p>
                <h2>Signal</h2>
            </div>
            <span class="inline muted" style="margin-left:auto;font-size:12px;">
                <span class="live-dot"></span> updating every 5s
            </span>
        </div>
        <div class="panel__body">
            <div class="tape-wrap" data-fleet-tape data-tape-count="<?= count($fleetTape) ?>"
                 data-tape-label="All checks across your monitors">
                <?= Tape::svg($fleetTape, 'fleet', 'All checks across your monitors') ?>
            </div>
            <div class="tape-legend">
                <span><?= e(t('dashboard.fleet_tape')) ?></span>
                <span class="tape-now">now</span>
            </div>
        </div>
    </section>

    <section class="grid grid--stats" style="margin-top:18px;">
        <div class="stat">
            <p class="eyebrow"><?= e(t('dashboard.uptime_24h')) ?></p>
            <p class="stat__value stat__value--up" data-live-fleet="uptime"><?= e(format_uptime($fleetUptime)) ?></p>
            <p class="stat__foot"><?= e(format_duration($downtime)) ?> of downtime in 30 days</p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('dashboard.response')) ?></p>
            <p class="stat__value" data-live-fleet="latency"><?= e(format_ms($fleetLatency)) ?></p>
            <p class="stat__foot">Average across every monitor, 24 hours</p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('dashboard.open_incidents')) ?></p>
            <p class="stat__value <?= $openIncidents > 0 ? 'stat__value--down' : '' ?>" data-live-fleet="incidents"><?= (int) $openIncidents ?></p>
            <p class="stat__foot"><a href="/incidents">See the incident log</a></p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('dashboard.monitored')) ?></p>
            <p class="stat__value"><span data-live-count="total"><?= (int) $counts['total'] ?></span></p>
            <p class="stat__foot">
                <span class="pill pill--up"><span data-live-count="up"><?= (int) $counts['up'] ?></span> up</span>
                <span class="pill pill--down"><span data-live-count="down"><?= (int) $counts['down'] ?></span> down</span>
            </p>
        </div>
    </section>

    <div class="grid grid--split" style="margin-top:18px;">
        <section class="panel">
            <div class="panel__head">
                <h2><?= e(t('dashboard.response_over_time')) ?></h2>
                <div class="seg">
                    <?php foreach (['1h', '24h', '7d', '30d'] as $option): ?>
                        <a href="/?range=<?= e($option) ?>" aria-current="<?= $range === $option ? 'true' : 'false' ?>">
                            <?= e($option) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel__body">
                <?php if ($series['t'] === []): ?>
                    <p class="muted">No measurements in this window yet. The chart fills in as checks land.</p>
                <?php else: ?>
                    <div class="chart chart--tall" data-chart
                         data-chart-data='<?= e(json_encode(['series' => $series, 'incidents' => []], JSON_UNESCAPED_SLASHES)) ?>'></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2><?= e(t('dashboard.recent_incidents')) ?></h2>
            </div>
            <?php if ($incidents === []): ?>
                <div class="panel__body">
                    <p class="muted mt-0"><?= e(t('dashboard.no_incidents')) ?></p>
                </div>
            <?php else: ?>
                <div class="rows">
                    <?php foreach ($incidents as $incident): ?>
                        <div class="row" style="grid-template-columns: minmax(0,1fr) auto;">
                            <div class="truncate">
                                <a class="row__name truncate" href="/monitors/<?= (int) $incident['monitor_id'] ?>">
                                    <?= e((string) $incident['monitor_name']) ?>
                                </a>
                                <span class="row__target truncate"><?= e((string) ($incident['last_error'] ?? $incident['cause'] ?? '')) ?></span>
                            </div>
                            <div style="text-align:right;">
                                <span class="pill pill--<?= $incident['status'] === 'open' ? 'down' : 'up' ?>">
                                    <?= $incident['status'] === 'open' ? e(t('incident.ongoing')) : e(format_duration((int) $incident['duration_seconds'])) ?>
                                </span>
                                <span class="row__target"><?= e(local_time((string) $incident['started_at'], 'M j, H:i')) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel" style="margin-top:18px;">
        <div class="panel__head">
            <h2><?= e(t('nav.monitors')) ?></h2>
            <div class="seg">
                <?php foreach (['all' => 'All', 'down' => 'Down', 'degraded' => 'Degraded', 'up' => 'Up'] as $value => $label): ?>
                    <a href="/?status=<?= e($value) ?>" aria-current="<?= $activeStatus === $value ? 'true' : 'false' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($monitors === []): ?>
            <div class="empty">
                <h3><?= e(t('dashboard.empty_title')) ?></h3>
                <p><?= e(t('dashboard.empty_body')) ?></p>
                <?php if (can('monitors.create')): ?>
                    <a class="btn btn--primary" href="/monitors/new"><?= icon('plus') ?><?= e(t('action.add_monitor')) ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rows">
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
