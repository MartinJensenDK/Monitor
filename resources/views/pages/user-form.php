<?php
/**
 * @var array<string,mixed>|null $user
 * @var array<int,array<string,mixed>> $groups
 * @var array<int,int> $memberOf
 */

use App\Core\Rbac;

$isEdit = $user !== null;
$action = $isEdit ? '/users/' . (int) $user['id'] : '/users';
$managed = $isEdit && ($user['auth_provider'] ?? 'local') === 'entra';

$value = static function (string $key, string $default = '') use ($user): string {
    $old = old($key);
    if ($old !== '') {
        return $old;
    }

    return $user !== null && $user[$key] !== null ? (string) $user[$key] : $default;
};
?>
<form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head"><h2><?= $isEdit ? 'Account' : 'Who is joining?' ?></h2></div>
        <div class="panel__body">
            <?php if ($managed): ?>
                <p class="flash flash--warning" style="margin-bottom:16px;">
                    <?= icon('lock') ?><span>This account comes from Microsoft Entra ID. Name and email are overwritten on the next sync.</span>
                </p>
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="name">Name</label>
                    <input class="input" id="name" name="name" required value="<?= e($value('name')) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="email">Email</label>
                    <input class="input" id="email" name="email" type="email" required value="<?= e($value('email')) ?>">
                    <span class="field__hint">Used to sign in, and to reach them about incidents.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="password"><?= $isEdit ? 'New password' : 'Password' ?></label>
                    <input class="input" id="password" name="password" type="password" autocomplete="new-password"
                           <?= $isEdit ? '' : 'required' ?> placeholder="<?= $isEdit ? 'Leave empty to keep the current one' : 'At least 10 characters' ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="timezone">Time zone</label>
                    <select class="select" id="timezone" name="timezone">
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= $value('timezone', 'UTC') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>What they may do</h2></div>
        <div class="panel__body">
            <div class="form-grid">
                <?php foreach (Rbac::roles() as $role): ?>
                    <label class="check">
                        <input type="radio" name="role" value="<?= e($role) ?>"
                               <?= $value('role', 'viewer') === $role ? 'checked' : '' ?>>
                        <span class="check__text">
                            <strong><?= e(Rbac::label($role)) ?></strong>
                            <small><?= e(t('role.' . $role . '_hint')) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="check" style="margin-top:16px;">
                <input type="checkbox" name="active" value="1" <?= $isEdit ? ($user['status'] === 'active' ? 'checked' : '') : 'checked' ?>>
                <span class="check__text">Account is active<small>A disabled account cannot sign in, but its history stays.</small></span>
            </label>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>What they can see</h2></div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:12px;">
                Group membership decides which monitors this person sees. Their role decides what they can do to them.
            </p>

            <?php if ($groups === []): ?>
                <p class="muted mt-0">No groups yet. <a href="/groups/new">Create one</a> to start sharing monitors.</p>
            <?php else: ?>
                <div class="form-grid">
                    <?php foreach ($groups as $group): ?>
                        <label class="check">
                            <input type="checkbox" name="groups[]" value="<?= (int) $group['id'] ?>"
                                   <?= in_array((int) $group['id'], $memberOf, true) ? 'checked' : '' ?>
                                   <?= ($group['source'] ?? 'local') === 'entra' ? 'disabled' : '' ?>>
                            <span class="check__text">
                                <?= ($group['source'] ?? 'local') === 'entra' ? icon('lock', 'icon') : '' ?>
                                <?= e((string) $group['name']) ?>
                                <small>
                                    <?= ($group['source'] ?? 'local') === 'entra'
                                        ? e(t('access.managed'))
                                        : (int) $group['monitor_count'] . ' monitor(s)' ?>
                                </small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn--ghost" href="/users"><?= e(t('action.cancel')) ?></a>
        <div class="btn-row">
            <?php if ($isEdit): ?>
                <button class="btn btn--danger" type="submit" form="delete-user"><?= icon('trash') ?><?= e(t('action.delete')) ?></button>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= $isEdit ? e(t('action.save')) : 'Add person' ?></button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <form id="delete-user" method="post" action="/users/<?= (int) $user['id'] ?>/delete"
          data-confirm="Remove <?= e((string) $user['name']) ?>? Their monitors stay, but they lose access immediately.">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>
