<?php
/** @var array<int,array<string,mixed>> $users @var string $search */

use App\Core\Rbac;
use App\Support\Str;
?>
<section class="panel">
    <div class="panel__head">
        <form class="filters" method="get" action="/users">
            <label class="search">
                <?= icon('search') ?>
                <span class="visually-hidden"><?= e(t('action.search')) ?></span>
                <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Name or email">
            </label>
        </form>
        <?php if (can('users.manage')): ?>
            <div class="btn-row">
                <a class="btn btn--primary" href="/users/new"><?= icon('plus') ?><?= e(t('action.add_person')) ?></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Role</th>
                    <th>Groups</th>
                    <th>Last signed in</th>
                    <th class="table__right"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <span class="inline">
                                <span class="avatar"><?= e(Str::initials((string) $user['name'])) ?></span>
                                <span>
                                    <strong><?= e((string) $user['name']) ?></strong>
                                    <?php if ($user['status'] !== 'active'): ?>
                                        <span class="pill pill--paused">disabled</span>
                                    <?php endif; ?>
                                    <?php if (($user['auth_provider'] ?? 'local') === 'entra'): ?>
                                        <span class="tag"><?= icon('lock', 'icon') ?>Entra</span>
                                    <?php endif; ?>
                                    <span class="row__target"><?= e((string) $user['email']) ?></span>
                                </span>
                            </span>
                        </td>
                        <td><span class="pill pill--plain"><?= e(Rbac::label((string) $user['role'])) ?></span></td>
                        <td class="num"><?= (int) $user['group_count'] ?></td>
                        <td class="muted nowrap"><?= e($user['last_login_at'] ? local_time((string) $user['last_login_at'], 'M j, H:i') : t('time.never')) ?></td>
                        <td class="table__right nowrap">
                            <?php if (can('users.manage')): ?>
                                <a class="btn btn--sm" href="/users/<?= (int) $user['id'] ?>/edit"><?= e(t('action.edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
