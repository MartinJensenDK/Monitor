<?php
/**
 * Which agent a machine is running, and whether that is the one on offer.
 *
 * Its own file because it is rendered twice: into the page, and into the answer
 * the live log already asks for, so it keeps up while somebody watches an
 * update land rather than telling them to reload. The state changes on its own
 * -- a machine replaces its agent and reports the new version a minute later --
 * which is exactly the kind of thing a page should not have to be reloaded for.
 *
 * @var array<string,mixed> $device
 */

use App\Agent\Scripts;
use App\Domain\AgentPolicy;

// What this server would hand out, next to what the machine is running: the two
// disagreeing is the whole of why an update is or is not on its way. Compared
// as versions rather than as strings, so a machine that is ahead of this server
// -- which is what a rolled-back script here looks like -- is not told to fetch
// an update that would take it backwards. A machine that has never said which
// agent it runs is not behind, it is unknown, and says nothing either way.
$running = (string) ($device['agent_version'] ?? '');
$offered = Scripts::version((string) $device['os_family']);
$known = $running !== '' && $offered !== '0.0.0';
$compared = $known ? version_compare($running, $offered) : 0;
$offering = AgentPolicy::mayOfferUpdates();
?>
<?= e($running !== '' ? $running : '—') ?>
<?php if ($compared < 0): ?>
    <span class="pill pill--degraded"><?= e(t('device.agent_update_ready', ['version' => $offered])) ?></span>
    <span class="muted block"><?php
        // The pill says a newer one exists either way. This line says whether
        // anything is going to come of that, and the site switch is the first
        // thing in the way.
        if (!$offering) {
            echo e(t('device.agent_updates_off', ['version' => $offered]));
        } elseif ((int) $device['self_update'] === 1) {
            echo e(t('device.agent_updating', ['version' => $offered]));
        } else {
            echo e(t('device.agent_behind', ['version' => $offered]));
        }
    ?></span>
<?php elseif ($compared > 0): ?>
    <span class="muted block"><?= e(t('device.agent_ahead', ['version' => $offered])) ?></span>
<?php elseif ($known): ?>
    <?php // Level with what this server holds. Saying so is worth a word: the
          // difference between "there is nothing newer" and "nobody has
          // checked" is the whole question somebody opened this to answer. ?>
    <span class="pill pill--up"><?= e(t('device.agent_current')) ?></span>
    <?php if ((int) $device['self_update'] !== 1): ?>
        <span class="muted block"><?= e(t('device.agent_pinned')) ?></span>
    <?php endif; ?>
<?php elseif ((int) $device['self_update'] !== 1): ?>
    <span class="muted block"><?= e(t('device.agent_pinned')) ?></span>
<?php endif; ?>
