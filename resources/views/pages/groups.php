<?php
/** @var array<int,array<string,mixed>> $groups */
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <p class="eyebrow">Access</p>
            <h2>Groups decide who sees which monitors</h2>
        </div>
        <?php if (can('groups.manage')): ?>
            <div class="btn-row">
                <a class="btn btn--primary" href="/groups/new"><?= icon('plus') ?><?= e(t('action.add_group')) ?></a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($groups === []): ?>
        <div class="empty">
            <h3>No groups yet</h3>
            <p>A group is a set of people who share the same view. Put a monitor in a group, and everyone in it can see it.</p>
            <a class="btn btn--primary" href="/groups/new"><?= icon('plus') ?><?= e(t('action.add_group')) ?></a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th>Members</th>
                        <th>Monitors</th>
                        <th>Source</th>
                        <th class="table__right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $group['name']) ?></strong>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="row__target truncate"><?= e((string) $group['description']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= (int) $group['member_count'] ?></td>
                            <td class="num"><?= (int) $group['monitor_count'] ?></td>
                            <td>
                                <?php if ($group['source'] === 'entra'): ?>
                                    <span class="tag"><?= icon('lock', 'icon') ?>Entra ID</span>
                                <?php else: ?>
                                    <span class="pill pill--plain">Local</span>
                                <?php endif; ?>
                            </td>
                            <td class="table__right">
                                <a class="btn btn--sm" href="/groups/<?= (int) $group['id'] ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
