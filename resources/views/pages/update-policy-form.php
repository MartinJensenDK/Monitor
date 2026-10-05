<?php
/**
 * One update policy: its two schedules, whether it may restart, and the
 * machines that follow it.
 *
 * Each machine says beside its checkbox what the schedule will and will not do
 * to it -- installed without --allow-updates, commands off, already following
 * another policy -- so the effect of ticking it is read before the save rather
 * than found out after.
 *
 * @var array<string,mixed>|null $policy
 * @var array<int,array<string,mixed>> $machines
 * @var array<int,int> $members
 * @var string $zone
 */

use App\Domain\Devices;
use App\Domain\UpdatePolicies;
use App\Support\Icons;

$isNew = $policy === null;
$p = $policy ?? [
    'name' => '',
    'enabled' => 1,
    'check_enabled' => 1,
    'check_days' => UpdatePolicies::ALL_DAYS,
    'check_time' => '06:00',
    'install_enabled' => 0,
    'install_days' => 0,
    'install_time' => '03:00',
    'restart_after' => 0,
];
$action = $isNew ? '/devices/updates' : '/devices/updates/' . (int) $p['id'];
$dayNames = UpdatePolicies::dayNames();

$utc = new DateTimeZone('UTC');
$tz = UpdatePolicies::timezone();
$now = new DateTimeImmutable('now', $utc);
$nextLine = static function (string $half) use ($p, $isNew, $now, $tz, $dayNames): string {
    if ($isNew || (int) $p['enabled'] !== 1 || (int) $p[$half . '_enabled'] !== 1) {
        return '';
    }
    $next = UpdatePolicies::nextOccurrence((int) $p[$half . '_days'], (string) $p[$half . '_time'], $now, $tz);
    if ($next === null) {
        return '';
    }
    $local = $next->setTimezone($tz);

    return t('policy.next', ['when' => $dayNames[(int) $local->format('N')] . ' ' . $local->format('H:i')]);
};

// The seven toggles for one half, as a segmented row of checkboxes.
$dayPicker = static function (string $name, int $days) use ($dayNames): string {
    $html = '<div class="seg" role="group">';
    foreach ($dayNames as $iso => $label) {
        $html .= '<label><input class="visually-hidden" type="checkbox" name="' . e($name) . '[]" value="' . $iso . '"'
            . (($days & (1 << ($iso - 1))) !== 0 ? ' checked' : '') . '>'
            . '<span class="btn btn--sm btn--ghost">' . e($label) . '</span></label>';
    }

    return $html . '</div>';
};

$servers = array_values(array_filter($machines, static fn (array $m): bool => (string) $m['kind'] === Devices::KIND_SERVER));
$clients = array_values(array_filter($machines, static fn (array $m): bool => (string) $m['kind'] !== Devices::KIND_SERVER));
?>
<form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <div>
                <p class="eyebrow"><?= e(t('policy.title')) ?></p>
                <h2><?= e($isNew ? t('policy.new') : (string) $p['name']) ?></h2>
            </div>
            <div class="btn-row" style="margin-left:auto;">
                <a class="btn" href="/devices/updates"><?= icon('arrow-left') ?><?= e(t('action.cancel')) ?></a>
                <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e($isNew ? t('policy.save_new') : t('action.save')) ?></button>
            </div>
        </div>
        <div class="panel__body">
            <div class="pair">
                <label class="field">
                    <span class="field__label"><?= e(t('policy.name')) ?></span>
                    <input class="input" type="text" name="name" maxlength="120" required
                           value="<?= e(old('name', (string) $p['name'])) ?>">
                    <span class="field__hint"><?= e(t('policy.name_hint')) ?></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="enabled" value="1" <?= (int) $p['enabled'] === 1 ? 'checked' : '' ?>>
                    <span>
                        <?= e(t('policy.enabled')) ?>
                        <span class="field__hint block"><?= e(t('policy.enabled_hint')) ?></span>
                    </span>
                </label>
            </div>
            <p class="field__hint"><?= e(t('policy.timezone_note', ['zone' => $zone])) ?></p>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2><?= icon('history') ?> <?= e(t('policy.check')) ?></h2></div>
        <div class="panel__body">
            <label class="check">
                <input type="checkbox" name="check_enabled" value="1" <?= (int) $p['check_enabled'] === 1 ? 'checked' : '' ?>>
                <span>
                    <?= e(t('policy.check_on')) ?>
                    <span class="field__hint block"><?= e(t('policy.check_hint')) ?></span>
                </span>
            </label>
            <div class="pair">
                <div class="field">
                    <span class="field__label"><?= e(t('policy.days')) ?></span>
                    <?= $dayPicker('check_days', (int) $p['check_days']) ?>
                </div>
                <label class="field">
                    <span class="field__label"><?= e(t('policy.time')) ?></span>
                    <input class="input" type="time" name="check_time" step="60"
                           value="<?= e(UpdatePolicies::cleanTime((string) $p['check_time'])) ?>">
                    <?php if ($nextLine('check') !== ''): ?>
                        <span class="field__hint"><?= e($nextLine('check')) ?></span>
                    <?php endif; ?>
                </label>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2><?= icon('download') ?> <?= e(t('policy.install')) ?></h2></div>
        <div class="panel__body">
            <label class="check">
                <input type="checkbox" name="install_enabled" value="1" <?= (int) $p['install_enabled'] === 1 ? 'checked' : '' ?>>
                <span>
                    <?= e(t('policy.install_on')) ?>
                    <span class="field__hint block"><?= e(t('policy.install_hint')) ?></span>
                </span>
            </label>
            <div class="pair">
                <div class="field">
                    <span class="field__label"><?= e(t('policy.days')) ?></span>
                    <?= $dayPicker('install_days', (int) $p['install_days']) ?>
                </div>
                <label class="field">
                    <span class="field__label"><?= e(t('policy.time')) ?></span>
                    <input class="input" type="time" name="install_time" step="60"
                           value="<?= e(UpdatePolicies::cleanTime((string) $p['install_time'])) ?>">
                    <?php if ($nextLine('install') !== ''): ?>
                        <span class="field__hint"><?= e($nextLine('install')) ?></span>
                    <?php endif; ?>
                </label>
            </div>
            <label class="check">
                <input type="checkbox" name="restart_after" value="1" <?= (int) $p['restart_after'] === 1 ? 'checked' : '' ?>>
                <span>
                    <?= e(t('policy.restart_after')) ?>
                    <span class="field__hint block"><?= e(t('policy.restart_after_hint')) ?></span>
                </span>
            </label>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <div>
                <h2><?= e(t('policy.machines')) ?></h2>
                <p class="field__hint" style="margin:4px 0 0;"><?= e(t('policy.machines_hint')) ?></p>
            </div>
        </div>
        <div class="panel__body">
            <?php if ($machines === []): ?>
                <p class="muted mt-0"><?= e(t('policy.machines_none')) ?></p>
            <?php endif; ?>
            <?php foreach ([[t('nav.servers'), $servers], [t('nav.clients'), $clients]] as [$heading, $group]): ?>
                <?php if ($group === []) { continue; } ?>
                <p class="eyebrow"><?= e($heading) ?></p>
                <?php foreach ($group as $machine): ?>
                    <?php
                    $id = (int) $machine['id'];
                    $otherPolicy = $machine['update_policy_id'] !== null
                        && ($isNew || (int) $machine['update_policy_id'] !== (int) $p['id']);
                    $notes = [];
                    if ($otherPolicy) {
                        $notes[] = t('policy.in_other', ['name' => (string) $machine['policy_name']]);
                    }
                    if ((string) $machine['status'] === 'disabled') {
                        $notes[] = t('policy.switched_off');
                    } elseif ((int) $machine['commands_enabled'] !== 1) {
                        $notes[] = t('policy.commands_off');
                    }
                    if ((int) ($machine['allow_updates'] ?? 0) !== 1) {
                        $notes[] = t('policy.refuses_install');
                    }
                    if ((int) ($machine['allow_reboot'] ?? 0) !== 1) {
                        $notes[] = t('policy.refuses_restart');
                    }
                    ?>
                    <label class="check">
                        <input type="checkbox" name="devices[]" value="<?= $id ?>" <?= in_array($id, $members, true) ? 'checked' : '' ?>>
                        <span>
                            <?= icon(Icons::forOsFamily((string) $machine['os_family']), 'icon icon--sm') ?>
                            <?= e((string) $machine['name']) ?>
                            <?php if ($notes !== []): ?>
                                <span class="field__hint block"><?= e(implode(' · ', $notes)) ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </section>
</form>

<?php if (!$isNew): ?>
    <section class="panel panel--danger" style="margin-top:18px;">
        <div class="panel__head"><h2><?= e(t('device.danger')) ?></h2></div>
        <div class="panel__body">
            <form method="post" action="/devices/updates/<?= (int) $p['id'] ?>/delete"
                  data-confirm="<?= e(t('policy.delete_confirm', ['name' => (string) $p['name']])) ?>"
                  data-confirm-detail="<?= e(t('policy.delete_detail')) ?>"
                  data-confirm-label="<?= e(t('policy.delete')) ?>"
                  data-confirm-tone="danger">
                <?= csrf_field() ?>
                <button class="btn btn--danger" type="submit"><?= icon('trash') ?><?= e(t('policy.delete')) ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>
