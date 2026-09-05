<?php
/**
 * What a person may change about a machine. Everything else on the device page
 * came from the machine itself and is not editable here -- correcting the
 * hostname in a database would only make the two disagree.
 *
 * @var array<string,mixed> $device
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,array<string,mixed>> $assignable
 * @var array<int,string> $assigned
 */

use App\Domain\Devices;

$uuid = (string) $device['uuid'];
$isServer = Devices::normaliseKind((string) $device['kind']) === Devices::KIND_SERVER;
?>
<form method="post" action="/devices/<?= e($uuid) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <div>
                <p class="eyebrow"><?= e($isServer ? t('nav.servers') : t('nav.clients')) ?></p>
                <h2><?= e((string) $device['name']) ?></h2>
            </div>
            <div class="btn-row" style="margin-left:auto;">
                <a class="btn" href="/devices/<?= e($uuid) ?>"><?= icon('arrow-left') ?><?= e(t('action.cancel')) ?></a>
                <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e(t('action.save')) ?></button>
            </div>
        </div>

        <div class="panel__body">
            <div class="pair">
                <label class="field">
                    <span class="field__label"><?= e(t('device.display_name')) ?></span>
                    <input class="input" type="text" name="name" maxlength="190" required
                           value="<?= e(old('name', (string) $device['name'])) ?>">
                    <span class="field__hint"><?= e(t('device.display_name_hint', ['hostname' => (string) $device['hostname']])) ?></span>
                </label>

                <label class="field">
                    <span class="field__label"><?= e(t('device.interval')) ?></span>
                    <input class="input" type="number" name="interval_seconds" min="60" max="86400" step="10"
                           value="<?= e(old('interval_seconds', (string) $device['interval_seconds'])) ?>">
                    <span class="field__hint"><?= e(t('device.interval_hint')) ?></span>
                </label>
            </div>

            <?php $live = (int) $device['poll_seconds'] > 0; ?>
            <div class="pair">
                <label class="field">
                    <span class="field__label"><?= e(t('device.poll')) ?></span>
                    <input class="input" type="number" name="poll_seconds" min="5" max="3600" step="5"
                           value="<?= e(old('poll_seconds', (string) ($live ? $device['poll_seconds'] : App\Domain\Devices::DEFAULT_POLL))) ?>">
                    <span class="field__hint"><?= e(t('device.poll_hint')) ?></span>
                </label>

                <div class="field">
                    <span class="field__label"><?= e(t('device.live')) ?></span>
                    <label class="check">
                        <input type="checkbox" name="live" value="1" <?= $live ? 'checked' : '' ?>>
                        <span>
                            <?= e(t('device.live_on')) ?>
                            <span class="field__hint block"><?= e(t('device.live_hint')) ?></span>
                        </span>
                    </label>
                </div>
            </div>

            <?php $level = old('log_level', (string) $device['log_level']); ?>
            <div class="pair">
                <label class="field">
                    <span class="field__label"><?= e(t('device.log_level')) ?></span>
                    <select class="select" name="log_level">
                        <?php foreach (['error', 'warn', 'info', 'debug'] as $option): ?>
                            <option value="<?= e($option) ?>" <?= $level === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint"><?= e(t('device.log_level_hint')) ?></span>
                </label>
            </div>

            <div class="pair">
                <label class="field">
                    <span class="field__label"><?= e(t('device.kind')) ?></span>
                    <select class="select" name="kind">
                        <option value="server" <?= $isServer ? 'selected' : '' ?>><?= e(t('nav.servers')) ?></option>
                        <option value="client" <?= $isServer ? '' : 'selected' ?>><?= e(t('nav.clients')) ?></option>
                    </select>
                    <span class="field__hint">
                        <?= (int) $device['kind_locked'] === 1
                            ? e(t('device.kind_locked_hint'))
                            : e(t('device.kind_auto_hint', ['guess' => (string) $device['agent_kind']])) ?>
                    </span>
                </label>

                <?php if ($locations !== []): ?>
                    <label class="field">
                        <span class="field__label"><?= e(t('device.location')) ?></span>
                        <select class="select" name="location_id">
                            <option value="0"><?= e(t('device.unplaced')) ?></option>
                            <?php foreach ($locations as $place): ?>
                                <option value="<?= (int) $place['id'] ?>" <?= (int) ($device['location_id'] ?? 0) === (int) $place['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $place['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__hint"><?= e(t('device.location_hint')) ?></span>
                    </label>
                <?php endif; ?>
            </div>

            <label class="field">
                <span class="field__label"><?= e(t('device.notes')) ?></span>
                <textarea class="input" name="notes" rows="2" maxlength="500"><?= e(old('notes', (string) ($device['notes'] ?? ''))) ?></textarea>
            </label>

            <?php if ((int) $device['kind_locked'] === 1): ?>
                <label class="check">
                    <input type="checkbox" name="kind_auto" value="1">
                    <span><?= e(t('device.kind_auto_again')) ?></span>
                </label>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2><?= e(t('device.who_can_see')) ?></h2></div>
        <div class="panel__body">
            <?php if ($assignable === []): ?>
                <p class="muted mt-0">
                    You are not in any group yet, so there is nobody to share this with.
                    <?= can('groups.manage') ? '<a href="/groups/new">Create a group</a> first.' : 'Ask an administrator to add you to a group.' ?>
                </p>
            <?php else: ?>
                <p class="field__hint" style="margin-bottom:12px;">
                    Groups decide who sees this machine. <strong>Can edit</strong> also lets the group rename it,
                    move it between Servers and Clients, and queue commands for it — within what their role allows.
                </p>
                <div class="access">
                    <?php foreach ($assignable as $group): ?>
                        <?php $current = $assigned[(int) $group['id']] ?? 'none'; ?>
                        <div class="access__row">
                            <div>
                                <span class="access__name">
                                    <?= ($group['source'] ?? 'local') === 'entra' ? icon('lock', 'icon') : '' ?>
                                    <?= e((string) $group['name']) ?>
                                </span>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="access__meta"><?= e((string) $group['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="seg">
                                <?php foreach (['none' => t('access.none'), 'view' => t('access.view'), 'edit' => t('access.edit')] as $level => $label): ?>
                                    <label>
                                        <input class="visually-hidden" type="radio"
                                               name="group_access[<?= (int) $group['id'] ?>]"
                                               value="<?= e($level) ?>" <?= $current === $level ? 'checked' : '' ?>>
                                        <span class="btn btn--sm btn--ghost"><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2><?= e(t('device.control')) ?></h2></div>
        <div class="panel__body">
            <label class="check">
                <input type="checkbox" name="commands_enabled" value="1" <?= (int) $device['commands_enabled'] === 1 ? 'checked' : '' ?>>
                <span>
                    <?= e(t('device.commands_enabled')) ?>
                    <span class="field__hint block"><?= e(t('device.commands_enabled_hint')) ?></span>
                </span>
            </label>

            <label class="check">
                <input type="checkbox" name="disabled" value="1" <?= (string) $device['status'] === 'disabled' ? 'checked' : '' ?>>
                <span>
                    <?= e(t('device.switched_off')) ?>
                    <span class="field__hint block"><?= e(t('device.switched_off_hint')) ?></span>
                </span>
            </label>
        </div>
    </section>
</form>

<section class="panel panel--danger" style="margin-top:18px;">
    <div class="panel__head"><h2><?= e(t('device.danger')) ?></h2></div>
    <div class="panel__body">
        <div class="stack stack--tight">
            <div class="cmdrow">
                <form method="post" action="/devices/<?= e($uuid) ?>/revoke"
                      data-confirm="<?= e(t('device.confirm_revoke', ['name' => (string) $device['name']])) ?>"
                      data-confirm-detail="<?= e(t('device.confirm_revoke_detail')) ?>"
                      data-confirm-tone="danger">
                    <?= csrf_field() ?>
                    <button class="btn btn--sm btn--danger" type="submit"><?= icon('lock') ?><?= e(t('action.revoke_token')) ?></button>
                </form>
                <span class="cmdrow__hint"><?= e(t('device.revoke_hint')) ?></span>
            </div>

            <?php if (can('devices.delete')): ?>
                <div class="cmdrow">
                    <form method="post" action="/devices/<?= e($uuid) ?>/delete"
                          data-confirm="<?= e(t('device.confirm_delete', ['name' => (string) $device['name']])) ?>"
                          data-confirm-detail="<?= e(t('device.confirm_delete_detail')) ?>"
                          data-confirm-tone="danger">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm btn--danger" type="submit"><?= icon('trash') ?><?= e(t('action.delete')) ?></button>
                    </form>
                    <span class="cmdrow__hint"><?= e(t('device.delete_hint')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
