<?php
/**
 * The four figures above a fleet list.
 *
 * Its own file because it is rendered twice: into the page, and into the
 * answer /api/devices/list gives a page that has been left open. A machine
 * that comes back from a restart or finishes its updates changes these
 * numbers too, and they should not need a reload to say so.
 *
 * @var array<string,int> $summary
 */

$quiet = $summary['offline'] + $summary['stale'];
?>
<section class="grid grid--stats" data-devlist-stats>
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
