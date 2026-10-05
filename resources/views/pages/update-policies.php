<?php
/**
 * Every update policy: when each checks and installs, on how many machines,
 * and what it did the last time.
 *
 * The refusals sit beside the machine count because they change what the
 * schedule means. A policy that installs on five machines, two of which were
 * installed without --allow-updates, installs on three -- and that should be
 * read here, not discovered at three in the morning.
 *
 * @var array<int,array<string,mixed>> $policies
 */

use App\Domain\UpdatePolicies;

$utc = new DateTimeZone('UTC');
$tz = UpdatePolicies::timezone();
$now = new DateTimeImmutable('now', $utc);
$dayNames = UpdatePolicies::dayNames();

// Day names in the interface's own language: PHP's 'D' is English only.
$moment = static function (?DateTimeImmutable $at) use ($tz, $dayNames): string {
    if ($at === null) {
        return '';
    }
    $local = $at->setTimezone($tz);

    return $dayNames[(int) $local->format('N')] . ' ' . $local->format('H:i');
};

$schedule = static function (array $policy, string $half) use ($now, $tz, $moment): string {
    if ((int) $policy[$half . '_enabled'] !== 1) {
        return t('policy.off');
    }
    $days = (int) $policy[$half . '_days'];
    $time = UpdatePolicies::cleanTime((string) $policy[$half . '_time']);

    return t('policy.at', ['days' => UpdatePolicies::describeDays($days), 'time' => $time])
        . ((int) $policy['enabled'] === 1
            ? ' · ' . t('policy.next', ['when' => $moment(UpdatePolicies::nextOccurrence($days, $time, $now, $tz))])
            : '');
};

$lastDone = static function (array $policy, string $half) use ($utc, $moment): string {
    if ($policy['last_' . $half . '_at'] === null || $policy['last_' . $half . '_result'] === null) {
        return t('policy.never_run');
    }

    return $moment(new DateTimeImmutable((string) $policy['last_' . $half . '_at'], $utc))
        . ' — ' . (string) $policy['last_' . $half . '_result'];
};
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <p class="eyebrow"><?= e(t('nav.administration')) ?></p>
            <h2><?= e(t('policy.title')) ?></h2>
        </div>
        <div class="btn-row">
            <a class="btn btn--primary" href="/devices/updates/new"><?= icon('plus') ?><?= e(t('policy.new')) ?></a>
        </div>
    </div>

    <div class="panel__body">
        <p class="muted mt-0"><?= e(t('policy.lede')) ?></p>
    </div>

    <?php if ($policies === []): ?>
        <div class="empty">
            <h3><?= e(t('policy.none_yet')) ?></h3>
            <p><?= e(t('policy.none_yet_hint')) ?></p>
            <a class="btn btn--primary" href="/devices/updates/new"><?= icon('plus') ?><?= e(t('policy.new')) ?></a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('policy.name')) ?></th>
                        <th><?= e(t('policy.col_schedule')) ?></th>
                        <th><?= e(t('policy.col_machines')) ?></th>
                        <th><?= e(t('policy.col_last')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($policies as $policy): ?>
                        <?php
                        $on = (int) $policy['enabled'] === 1;
                        $machines = (int) $policy['machines'];
                        $installs = (int) $policy['install_enabled'] === 1;
                        $restarts = $installs && (int) $policy['restart_after'] === 1;
                        ?>
                        <tr>
                            <td>
                                <a href="/devices/updates/<?= (int) $policy['id'] ?>"><strong><?= e((string) $policy['name']) ?></strong></a>
                                <span class="block"><span class="pill pill--<?= $on ? 'up' : 'paused' ?>"><?= e($on ? t('policy.on') : t('policy.off')) ?></span></span>
                            </td>
                            <td>
                                <span class="block"><?= icon('history', 'icon icon--sm') ?> <?= e(t('policy.check')) ?>: <?= e($schedule($policy, 'check')) ?></span>
                                <span class="block"><?= icon('download', 'icon icon--sm') ?> <?= e(t('policy.install')) ?>: <?= e($schedule($policy, 'install')) ?></span>
                                <?php if ($restarts): ?>
                                    <span class="muted block"><?= icon('refresh', 'icon icon--sm') ?> <?= e(t('policy.and_restart')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="block num"><?= e($machines === 1 ? t('policy.machines_one') : t('policy.machines_count', ['count' => $machines])) ?></span>
                                <?php if ($installs && (int) $policy['refuse_install'] > 0): ?>
                                    <span class="tag tag--warn"><?= e(t('policy.refusing_install', ['count' => (int) $policy['refuse_install']])) ?></span>
                                <?php endif; ?>
                                <?php if ($restarts && (int) $policy['refuse_restart'] > 0): ?>
                                    <span class="tag tag--warn"><?= e(t('policy.refusing_restart', ['count' => (int) $policy['refuse_restart']])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="muted">
                                <span class="block"><?= e(t('policy.check')) ?>: <?= e($lastDone($policy, 'check')) ?></span>
                                <span class="block"><?= e(t('policy.install')) ?>: <?= e($lastDone($policy, 'install')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
