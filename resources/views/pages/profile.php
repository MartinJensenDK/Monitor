<?php
/** @var array<string,mixed> $user @var array<int,array<string,mixed>> $groups */

use App\Core\Rbac;
?>
<form method="post" action="/profile" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <h2>You</h2>
            <span class="pill pill--plain" style="margin-left:auto;"><?= e(Rbac::label((string) $user['role'])) ?></span>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="name">Name</label>
                    <input class="input" id="name" name="name" required value="<?= e((string) $user['name']) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="email">Email</label>
                    <input class="input" id="email" name="email" type="email" required value="<?= e((string) $user['email']) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="timezone">Time zone</label>
                    <select class="select" id="timezone" name="timezone">
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= (string) $user['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">Timestamps are shown in this zone. Data is always stored in UTC.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>Change your password</h2></div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="current_password">Current password</label>
                    <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password">
                </div>

                <div class="field">
                    <label class="field__label" for="password">New password</label>
                    <input class="input" id="password" name="password" type="password" autocomplete="new-password"
                           placeholder="At least 10 characters">
                </div>

                <div class="field">
                    <label class="field__label" for="password_confirmation">Repeat new password</label>
                    <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                </div>
            </div>
            <p class="field__hint" style="margin-top:10px;">Changing your password signs out every other device.</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>Your groups</h2></div>
        <div class="panel__body">
            <?php if ($groups === []): ?>
                <p class="muted mt-0">
                    You are not in any group, so you only see monitors you created yourself.
                    Ask an administrator to add you to a group.
                </p>
            <?php else: ?>
                <p class="field__hint mt-0" style="margin-bottom:12px;">These decide which monitors you can see.</p>
                <div class="inline" style="flex-wrap:wrap;">
                    <?php foreach ($groups as $group): ?>
                        <span class="tag">
                            <?= $group['source'] === 'entra' ? icon('lock', 'icon') : icon('group', 'icon') ?>
                            <?= e((string) $group['name']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-actions">
        <div class="btn-row" style="margin-left:auto;">
            <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e(t('action.save')) ?></button>
        </div>
    </div>
</form>
