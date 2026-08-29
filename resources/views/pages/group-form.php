<?php
/**
 * @var array<string,mixed>|null $group
 * @var array<int,int> $members
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<string,mixed>> $monitors
 * @var ?string $grantsRole
 */

use App\Core\Rbac;

$isEdit = $group !== null;
$action = $isEdit ? '/groups/' . (int) $group['id'] : '/groups';
$managed = $isEdit && $group['source'] === 'entra';

$value = static function (string $key) use ($group): string {
    $old = old($key);

    return $old !== '' ? $old : ($group !== null && $group[$key] !== null ? (string) $group[$key] : '');
};
?>
<form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head"><h2><?= $isEdit ? 'Group' : 'Name the group' ?></h2></div>
        <div class="panel__body">
            <?php if ($managed): ?>
                <p class="flash flash--warning" style="margin-bottom:16px;">
                    <?= icon('lock') ?>
                    <span>This group is maintained in Microsoft Entra ID. Change its name or members there — the next sync brings them over.</span>
                </p>
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="name">Group name</label>
                    <input class="input" id="name" name="name" required maxlength="120"
                           value="<?= e($value('name')) ?>" placeholder="Operations" <?= $managed ? 'readonly' : '' ?>>
                </div>

                <div class="field">
                    <label class="field__label" for="description">Description</label>
                    <input class="input" id="description" name="description" maxlength="255"
                           value="<?= e($value('description')) ?>" placeholder="What this group looks after"
                           <?= $managed ? 'readonly' : '' ?>>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>What membership grants</h2></div>
        <div class="panel__body">
            <p class="field__hint mt-0" style="margin-bottom:14px;">
                Normally a role is set per person. Pick a role here and everyone the directory puts in this group gets
                it instead — which is how you keep "who is an administrator" in Entra ID rather than in two places.
                The most permissive mapped group wins.
            </p>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="grants_role">Role</label>
                    <select class="select" id="grants_role" name="grants_role">
                        <option value="">No opinion — each person keeps their own role</option>
                        <?php foreach (['viewer', 'editor', 'admin'] as $role): ?>
                            <option value="<?= e($role) ?>" <?= $grantsRole === $role ? 'selected' : '' ?>>
                                <?= e(Rbac::label($role)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">Applied on the next sync, and to anyone signing in with Microsoft.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2>Members</h2>
            <span class="muted" style="margin-left:auto;font-size:12px;"><?= count($members) ?> of <?= count($users) ?></span>
        </div>
        <div class="panel__body">
            <?php if ($users === []): ?>
                <p class="muted mt-0">No people yet. <a href="/users/new">Add someone</a> first.</p>
            <?php else: ?>
                <div class="form-grid">
                    <?php foreach ($users as $user): ?>
                        <label class="check">
                            <input type="checkbox" name="members[]" value="<?= (int) $user['id'] ?>"
                                   <?= in_array((int) $user['id'], $members, true) ? 'checked' : '' ?>
                                   <?= $managed ? 'disabled' : '' ?>>
                            <span class="check__text">
                                <strong><?= e((string) $user['name']) ?></strong>
                                <small><?= e((string) $user['email']) ?> · <?= e(Rbac::label((string) $user['role'])) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($isEdit): ?>
        <section class="panel">
            <div class="panel__head"><h2>Monitors shared with this group</h2></div>
            <?php if ($monitors === []): ?>
                <div class="panel__body">
                    <p class="muted mt-0">
                        Nothing yet. Open a monitor and set this group to <strong>Can view</strong> or <strong>Can edit</strong>.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Monitor</th><th>URL</th><th class="table__right">Access</th></tr></thead>
                        <tbody>
                            <?php foreach ($monitors as $monitor): ?>
                                <tr>
                                    <td><a href="/monitors/<?= (int) $monitor['id'] ?>"><?= e((string) $monitor['name']) ?></a></td>
                                    <td class="num truncate"><?= e((string) $monitor['target']) ?></td>
                                    <td class="table__right">
                                        <span class="pill pill--plain">
                                            <?= e($monitor['access'] === 'edit' ? t('access.edit') : t('access.view')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="form-actions">
        <a class="btn btn--ghost" href="/groups"><?= e(t('action.cancel')) ?></a>
        <div class="btn-row">
            <?php if ($isEdit && !$managed): ?>
                <button class="btn btn--danger" type="submit" form="delete-group"><?= icon('trash') ?><?= e(t('action.delete')) ?></button>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit">
                <?= icon('check') ?><?= $isEdit ? e(t('action.save')) : 'Create group' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit && !$managed): ?>
    <form id="delete-group" method="post" action="/groups/<?= (int) $group['id'] ?>/delete"
          data-confirm="Delete <?= e((string) $group['name']) ?>?"
          data-confirm-detail="Monitors shared only with this group become visible to administrators only."
          data-confirm-label="Delete group">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>
