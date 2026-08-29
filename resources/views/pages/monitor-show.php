<?php
/**
 * One monitor in full: the hero tape, the numbers, the response-time chart with
 * outages painted behind it, then the evidence — daily uptime, recent checks
 * and the incident history.
 *
 * @var array<string,mixed> $monitor
 * @var array<string,mixed> $config
 * @var array<int,array<string,mixed>> $tape
 * @var array<int,array<string,mixed>> $checks
 * @var array<int,array<string,mixed>> $incidents
 * @var array<int,array{date:string,uptime:?float,ok:int,fail:int,downtime:int}> $daily
 * @var array{avg:?int,p95:?int,min:?int,max:?int} $latency
 * @var string $range
 * @var array<int,array{group_id:int,access:string,name:string,source:string}> $groups
 * @var bool $canEdit
 * @var bool $canDelete
 */

use App\Domain\Monitors;
use App\Domain\Stats;
use App\Support\Icons;
use App\Support\Tape;

$id = (int) $monitor['id'];
$status = (string) ($monitor['status'] ?? 'pending');

$certDays = null;
if (!empty($monitor['cert_expires_at'])) {
    $certDays = (int) floor(((strtotime((string) $monitor['cert_expires_at'] . ' UTC') ?: time()) - time()) / 86400);
}

$uptime30 = $monitor['uptime_30d'] === null ? null : (float) $monitor['uptime_30d'];
$donutState = $uptime30 === null ? '' : ($uptime30 >= 0.999 ? '' : ($uptime30 >= 0.99 ? ' donut__value--warn' : ' donut__value--down'));
$circumference = 2 * M_PI * 34;
?>
<div data-live-scope="monitor:<?= $id ?>" data-live-interval="5000">

    <section class="panel">
        <div class="panel__head">
            <span class="row__type"><?= icon(Icons::forMonitorType((string) $monitor['type'])) ?></span>
            <div style="min-width:0;">
                <p class="eyebrow"><?= e(Monitors::typeLabel((string) $monitor['type'])) ?> · checked every <?= e(format_duration((int) $monitor['interval_seconds'])) ?></p>
                <a class="row__target" href="<?= e((string) $monitor['target']) ?>" rel="noreferrer noopener" target="_blank">
                    <?= e((string) $monitor['target']) ?> <?= icon('external', 'icon') ?>
                </a>
            </div>

            <div class="btn-row" style="margin-left:auto;">
                <span class="pill pill--<?= e($status) ?>" data-live-status><?= e(t('status.' . $status)) ?></span>

                <?php if ($canEdit): ?>
                    <form method="post" action="/monitors/<?= $id ?>/check-now">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm" type="submit"><?= icon('refresh') ?><?= e(t('action.check_now')) ?></button>
                    </form>
                    <form method="post" action="/monitors/<?= $id ?>/toggle">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm" type="submit">
                            <?= icon($monitor['enabled'] ? 'pause' : 'play') ?>
                            <?= e($monitor['enabled'] ? t('action.pause') : t('action.resume')) ?>
                        </button>
                    </form>
                    <a class="btn btn--sm" href="/monitors/<?= $id ?>/edit"><?= icon('edit') ?><?= e(t('action.edit')) ?></a>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <form method="post" action="/monitors/<?= $id ?>/delete"
                          data-confirm="Delete <?= e((string) $monitor['name']) ?> and all of its history? This cannot be undone.">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm btn--danger" type="submit"><?= icon('trash') ?><?= e(t('action.delete')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel__body">
            <div class="tape-wrap" data-tape="hero" data-tape-count="<?= count($tape) ?>"
                 data-tape-label="<?= e('Recent checks for ' . $monitor['name']) ?>">
                <?= Tape::svg($tape, 'hero', 'Recent checks for ' . $monitor['name']) ?>
            </div>
            <div class="tape-legend">
                <span>
                    <?= $status === 'down' ? 'Down' : 'Up' ?> since
                    <strong><?= e(local_time($monitor['status_since'], 'M j, H:i')) ?></strong>
                    · last check <span data-live-seen data-relative="<?= e($monitor['last_check_at'] ? str_replace(' ', 'T', (string) $monitor['last_check_at']) . 'Z' : '') ?>"><?= e(format_since($monitor['last_check_at'])) ?></span>
                </span>
                <span class="tape-now">now</span>
            </div>

            <?php if ($status === 'down' && !empty($monitor['last_error'])): ?>
                <p class="flash flash--error" style="margin-top:14px;">
                    <?= icon('alert') ?><span><?= e((string) $monitor['last_error']) ?></span>
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section class="grid grid--stats" style="margin-top:18px;">
        <div class="stat">
            <p class="eyebrow">Response now</p>
            <p class="stat__value" data-live-latency><?= e(format_ms($monitor['last_response_ms'] === null ? null : (int) $monitor['last_response_ms'])) ?></p>
            <p class="stat__foot">
                avg <span class="num" data-live-avg><?= e(format_ms($latency['avg'])) ?></span> ·
                p95 <span class="num" data-live-p95><?= e(format_ms($latency['p95'])) ?></span>
            </p>
        </div>

        <div class="stat">
            <p class="eyebrow">Uptime, 24 hours</p>
            <p class="stat__value stat__value--up" data-live-uptime="24h"><?= e(format_uptime($monitor['uptime_24h'] === null ? null : (float) $monitor['uptime_24h'])) ?></p>
            <p class="stat__foot">
                7d <span class="num" data-live-uptime="7d"><?= e(format_uptime($monitor['uptime_7d'] === null ? null : (float) $monitor['uptime_7d'])) ?></span> ·
                30d <span class="num" data-live-uptime="30d"><?= e(format_uptime($uptime30)) ?></span>
            </p>
        </div>

        <div class="stat">
            <p class="eyebrow"><?= e(t('monitor.certificate')) ?></p>
            <?php if ($certDays === null): ?>
                <p class="stat__value">—</p>
                <p class="stat__foot">No TLS certificate seen yet.</p>
            <?php else: ?>
                <p class="stat__value <?= $certDays < 7 ? 'stat__value--down' : ($certDays < 21 ? 'stat__value--warn' : '') ?>">
                    <?= (int) $certDays ?><span class="stat__unit">days</span>
                </p>
                <div class="meter" title="<?= e((string) $monitor['cert_expires_at']) ?> UTC">
                    <div class="meter__fill <?= $certDays < 7 ? 'meter__fill--down' : ($certDays < 21 ? 'meter__fill--warn' : '') ?>"
                         style="width: <?= max(3, min(100, (int) round($certDays / 90 * 100))) ?>%"></div>
                </div>
                <p class="stat__foot"><?= e((string) ($monitor['cert_issuer'] ?? '')) ?></p>
            <?php endif; ?>
        </div>

        <div class="stat">
            <p class="eyebrow">Availability, 30 days</p>
            <div class="donut">
                <svg viewBox="0 0 80 80" aria-hidden="true">
                    <circle class="donut__track" cx="40" cy="40" r="34" fill="none" stroke-width="7"/>
                    <circle class="donut__value<?= $donutState ?>" cx="40" cy="40" r="34" fill="none" stroke-width="7"
                            stroke-dasharray="<?= round($circumference, 2) ?>"
                            stroke-dashoffset="<?= round($circumference * (1 - ($uptime30 ?? 0)), 2) ?>"
                            transform="rotate(-90 40 40)"/>
                </svg>
                <div>
                    <p class="stat__value" style="font-size:22px;"><?= e(format_uptime($uptime30)) ?></p>
                    <p class="stat__foot"><?= e(format_duration(array_sum(array_column($daily, 'downtime')))) ?> down</p>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top:18px;">
        <div class="panel__head">
            <h2><?= e(t('dashboard.response_over_time')) ?></h2>
            <div class="seg">
                <?php foreach (array_keys(Stats::RANGES) as $option): ?>
                    <a href="/monitors/<?= $id ?>?range=<?= e($option) ?>" aria-current="<?= $range === $option ? 'true' : 'false' ?>">
                        <?= e($option) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel__body">
            <div class="chart chart--tall" data-chart data-chart-url="/api/monitors/<?= $id ?>/series?range=<?= e($range) ?>"></div>
            <p class="muted" style="font-size:12px;margin-top:10px;">
                The line is the average response time; the dashed line is p95. Red bands are the outages recorded in this window.
            </p>
        </div>
    </section>

    <div class="grid grid--halves" style="margin-top:18px;">
        <section class="panel">
            <div class="panel__head"><h2><?= e(t('monitor.history')) ?></h2></div>
            <div class="panel__body">
                <div class="bars">
                    <?php foreach ($daily as $day): ?>
                        <?php
                        $class = $day['uptime'] === null
                            ? 'bars__bar--none'
                            : ($day['uptime'] >= 0.999 ? '' : ($day['uptime'] >= 0.99 ? 'bars__bar--warn' : 'bars__bar--down'));
                        $height = $day['uptime'] === null ? 14 : max(14, (int) round($day['uptime'] * 100));
                        ?>
                        <div class="bars__bar <?= $class ?>" style="height: <?= $height ?>%"
                             title="<?= e($day['date'] . ' · ' . ($day['uptime'] === null ? 'no data' : format_uptime($day['uptime'])) . ($day['downtime'] > 0 ? ' · ' . format_duration($day['downtime']) . ' down' : '')) ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="bars__scale">
                    <span>30 days ago</span>
                    <span>today</span>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head"><h2>Configuration</h2></div>
            <div class="panel__body">
                <dl class="dl">
                    <dt><?= e(t('monitor.interval')) ?></dt>
                    <dd class="num"><?= e(format_duration((int) $monitor['interval_seconds'])) ?></dd>

                    <dt><?= e(t('monitor.timeout')) ?></dt>
                    <dd class="num"><?= (int) $monitor['timeout_seconds'] ?>s</dd>

                    <dt><?= e(t('monitor.retries')) ?></dt>
                    <dd class="num"><?= (int) $monitor['retries'] ?></dd>

                    <dt>Method</dt>
                    <dd class="num"><?= e((string) ($config['method'] ?? 'GET')) ?></dd>

                    <dt>Expected status</dt>
                    <dd class="num"><?= e((string) ($config['expected_status'] ?? '200-299')) ?></dd>

                    <?php if (!empty($config['keyword'])): ?>
                        <dt>Keyword</dt>
                        <dd>
                            <?= e((string) $config['keyword']) ?>
                            <span class="muted">(<?= !empty($config['keyword_absent']) ? 'must be absent' : 'must be present' ?>)</span>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($monitor['degraded_ms'])): ?>
                        <dt><?= e(t('monitor.degraded_ms')) ?></dt>
                        <dd class="num"><?= (int) $monitor['degraded_ms'] ?> ms</dd>
                    <?php endif; ?>

                    <dt><?= e(t('monitor.shared_with')) ?></dt>
                    <dd>
                        <?php if ($groups === []): ?>
                            <span class="muted"><?= e(t('monitor.no_access')) ?></span>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                                <span class="tag">
                                    <?= $group['source'] === 'entra' ? icon('lock') : '' ?>
                                    <?= e($group['name']) ?> · <?= e($group['access'] === 'edit' ? t('access.edit') : t('access.view')) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </section>
    </div>

    <div class="grid grid--halves" style="margin-top:18px;">
        <section class="panel">
            <div class="panel__head"><h2><?= e(t('monitor.recent_checks')) ?></h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th><?= e(t('monitor.status')) ?></th>
                            <th class="table__right"><?= e(t('monitor.response')) ?></th>
                            <th>Code</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody data-live-checks>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td class="num"><?= e((string) $check['checked_at']) ?></td>
                                <td><span class="pill pill--<?= e((string) $check['status']) ?>"><?= e((string) $check['status']) ?></span></td>
                                <td class="num table__right"><?= e(format_ms($check['response_ms'] === null ? null : (int) $check['response_ms'])) ?></td>
                                <td class="num"><?= e((string) ($check['http_code'] ?? '—')) ?></td>
                                <td class="muted"><?= e((string) ($check['error_message'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($checks === []): ?>
                            <tr><td colspan="5" class="muted">No checks recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head"><h2><?= e(t('nav.incidents')) ?></h2></div>
            <?php if ($incidents === []): ?>
                <div class="panel__body"><p class="muted mt-0"><?= e(t('incident.none')) ?></p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= e(t('incident.started')) ?></th>
                                <th><?= e(t('incident.duration')) ?></th>
                                <th><?= e(t('incident.cause')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incidents as $incident): ?>
                                <tr>
                                    <td class="num"><?= e(local_time((string) $incident['started_at'], 'M j, H:i')) ?></td>
                                    <td class="num">
                                        <?= $incident['status'] === 'open'
                                            ? '<span class="pill pill--down">' . e(t('incident.ongoing')) . '</span>'
                                            : e(format_duration((int) $incident['duration_seconds'])) ?>
                                    </td>
                                    <td class="muted truncate"><?= e((string) ($incident['last_error'] ?? $incident['cause'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
