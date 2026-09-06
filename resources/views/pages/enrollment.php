<?php
/**
 * Where a machine is let in.
 *
 * The page is built around the one command somebody has to run, because that
 * is the whole job: make a key, copy a line, paste it on the machine.
 *
 * @var array<int,array<string,mixed>> $keys
 * @var ?string $fresh the plaintext key, on the one render after it was made
 * @var array<int,array<string,mixed>> $groups
 * @var array<int,array<string,mixed>> $locations
 * @var string $baseUrl
 * @var array<string,string> $checksums
 */

use App\Domain\EnrollmentKeys;

$example = $fresh ?? 'mek_xxxxxxxxxxxxxxxxxxxxxxxx';
$linux = sprintf(
    "curl -fsSLO %s/agent/linux/install.sh\nsudo sh install.sh --key %s",
    $baseUrl,
    $example
);
$macos = sprintf(
    "curl -fsSLO %s/agent/macos/install.sh\nsudo sh install.sh --key %s",
    $baseUrl,
    $example
);
$windows = sprintf(
    "irm %s/agent/windows/install.ps1 -OutFile install.ps1\n.\\install.ps1 -Key %s",
    $baseUrl,
    $example
);
?>

<?php if ($fresh !== null): ?>
    <section class="panel panel--accent">
        <div class="panel__head">
            <div>
                <p class="eyebrow"><?= e(t('device.copy_now')) ?></p>
                <h2><?= e(t('device.key_created')) ?></h2>
            </div>
        </div>
        <div class="panel__body">
            <p class="mt-0"><?= e(t('device.key_created_body')) ?></p>
            <div class="copyline">
                <code class="copyline__text" data-copy-source><?= e($fresh) ?></code>
                <button class="btn btn--sm" type="button" data-copy="<?= e($fresh) ?>"><?= icon('link') ?><?= e(t('action.copy')) ?></button>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="panel" <?= $fresh === null ? '' : 'style="margin-top:18px;"' ?>>
    <div class="panel__head">
        <div>
            <p class="eyebrow"><?= e(t('device.install')) ?></p>
            <h2><?= e(t('device.install_title')) ?></h2>
        </div>
    </div>
    <div class="panel__body">
        <p class="mt-0">
            The agent reports over HTTPS and never listens on anything. It runs every few minutes,
            reads what the machine can tell it about itself, and posts that here. By default nothing
            on the machine can be changed from this side — pass <code>--allow-updates</code> or
            <code>--allow-reboot</code> at install time if you want to allow that, and the machine
            keeps the right to refuse either way.
        </p>

        <div class="installs">
            <div class="install">
                <div class="install__head">
                    <h3><?= e(t('device.linux')) ?></h3>
                    <span class="muted"><?= e(t('device.linux_needs')) ?></span>
                </div>
                <div class="copyline copyline--block">
                    <pre class="copyline__text"><?= e($linux) ?></pre>
                    <button class="btn btn--sm" type="button" data-copy="<?= e($linux) ?>"><?= icon('link') ?><?= e(t('action.copy')) ?></button>
                </div>
                <p class="field__hint">
                    <?= e(t('device.checksum')) ?>
                    <code class="num"><?= e($checksums['linux']) ?></code>
                </p>
            </div>

            <div class="install">
                <div class="install__head">
                    <h3><?= e(t('device.macos')) ?></h3>
                    <span class="muted"><?= e(t('device.macos_needs')) ?></span>
                </div>
                <div class="copyline copyline--block">
                    <pre class="copyline__text"><?= e($macos) ?></pre>
                    <button class="btn btn--sm" type="button" data-copy="<?= e($macos) ?>"><?= icon('link') ?><?= e(t('action.copy')) ?></button>
                </div>
                <p class="field__hint">
                    <?= e(t('device.checksum')) ?>
                    <code class="num"><?= e($checksums['macos']) ?></code>
                </p>
            </div>

            <div class="install">
                <div class="install__head">
                    <h3><?= e(t('device.windows')) ?></h3>
                    <span class="muted"><?= e(t('device.windows_needs')) ?></span>
                </div>
                <div class="copyline copyline--block">
                    <pre class="copyline__text"><?= e($windows) ?></pre>
                    <button class="btn btn--sm" type="button" data-copy="<?= e($windows) ?>"><?= icon('link') ?><?= e(t('action.copy')) ?></button>
                </div>
                <p class="field__hint">
                    <?= e(t('device.checksum')) ?>
                    <code class="num"><?= e($checksums['windows']) ?></code>
                </p>
            </div>
        </div>

        <p class="field__hint">
            <?= e(t('device.install_options')) ?>
            <code>--interval 300</code>, <code>--collect disks,updates</code>,
            <code>--allow-updates</code>, <code>--allow-reboot</code>, <code>--uninstall</code>.
        </p>
    </div>
</section>

<section class="panel" style="margin-top:18px;">
    <div class="panel__head">
        <div>
            <p class="eyebrow"><?= e(t('device.keys')) ?></p>
            <h2><?= e(t('device.new_key')) ?></h2>
        </div>
    </div>
    <form class="panel__body" method="post" action="/devices/enrollment">
        <?= csrf_field() ?>
        <p class="field__hint" style="margin-top:0;">
            A key is a coupon, not an identity: a machine spends it once and is issued a token of its
            own. Revoking a key later stops new machines enrolling and leaves every machine that
            already did alone.
        </p>

        <div class="pair">
            <label class="field">
                <span class="field__label"><?= e(t('device.key_name')) ?></span>
                <input class="input" type="text" name="name" maxlength="120" required
                       placeholder="<?= e(t('device.key_name_placeholder')) ?>" value="<?= e(old('name')) ?>">
                <span class="field__hint"><?= e(t('device.key_name_hint')) ?></span>
            </label>

            <label class="field">
                <span class="field__label"><?= e(t('device.key_kind')) ?></span>
                <select class="select" name="kind">
                    <option value="auto"><?= e(t('device.key_kind_auto')) ?></option>
                    <option value="server"><?= e(t('device.key_kind_server')) ?></option>
                    <option value="client"><?= e(t('device.key_kind_client')) ?></option>
                </select>
                <span class="field__hint"><?= e(t('device.key_kind_hint')) ?></span>
            </label>
        </div>

        <div class="pair">
            <label class="field">
                <span class="field__label"><?= e(t('device.key_expires')) ?></span>
                <select class="select" name="expires_days">
                    <?php foreach ([1 => t('device.day'), 7 => t('device.week'), 30 => t('device.month'), 90 => t('device.quarter'), 0 => t('device.never')] as $days => $label): ?>
                        <option value="<?= (int) $days ?>" <?= $days === 30 ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field__hint"><?= e(t('device.key_expires_hint')) ?></span>
            </label>

            <label class="field">
                <span class="field__label"><?= e(t('device.key_max')) ?></span>
                <input class="input" type="number" name="max_uses" min="0" max="10000"
                       placeholder="<?= e(t('device.key_max_placeholder')) ?>" value="<?= e(old('max_uses')) ?>">
                <span class="field__hint"><?= e(t('device.key_max_hint')) ?></span>
            </label>
        </div>

        <div class="pair">
            <?php if ($groups !== []): ?>
                <label class="field">
                    <span class="field__label"><?= e(t('device.key_group')) ?></span>
                    <select class="select" name="group_id">
                        <option value="0"><?= e(t('device.key_group_none')) ?></option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>"><?= e((string) $group['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint"><?= e(t('device.key_group_hint')) ?></span>
                </label>
            <?php endif; ?>

            <?php if ($locations !== []): ?>
                <label class="field">
                    <span class="field__label"><?= e(t('device.key_location')) ?></span>
                    <select class="select" name="location_id">
                        <option value="0"><?= e(t('device.unplaced')) ?></option>
                        <?php foreach ($locations as $place): ?>
                            <option value="<?= (int) $place['id'] ?>"><?= e((string) $place['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint"><?= e(t('device.key_location_hint')) ?></span>
                </label>
            <?php endif; ?>
        </div>

        <div class="btn-row">
            <button class="btn btn--primary" type="submit"><?= icon('key') ?><?= e(t('action.make_key')) ?></button>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <div class="panel__head"><h2><?= e(t('device.existing_keys')) ?></h2></div>
    <?php if ($keys === []): ?>
        <div class="panel__body"><p class="muted mt-0"><?= e(t('device.no_keys')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('device.key_name')) ?></th>
                        <th><?= e(t('device.key_kind')) ?></th>
                        <th><?= e(t('device.key_used')) ?></th>
                        <th><?= e(t('device.key_expires')) ?></th>
                        <th><?= e(t('device.state')) ?></th>
                        <th class="table__right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $key): ?>
                        <?php $state = EnrollmentKeys::state($key); ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $key['name']) ?></strong>
                                <span class="muted block num"><?= e((string) $key['token_hint']) ?>…</span>
                            </td>
                            <td class="muted"><?= e((string) $key['kind']) ?></td>
                            <td class="num">
                                <?= (int) $key['uses'] ?><?= (int) $key['max_uses'] > 0 ? ' / ' . (int) $key['max_uses'] : '' ?>
                                <span class="muted block"><?= (int) $key['device_count'] ?> <?= e(t('device.machines')) ?></span>
                            </td>
                            <td class="muted nowrap">
                                <?= $key['expires_at'] === null ? e(t('device.never')) : e(local_time((string) $key['expires_at'], 'Y-m-d')) ?>
                            </td>
                            <td>
                                <span class="pill pill--<?= $state === 'active' ? 'up' : 'paused' ?>"><?= e($state) ?></span>
                            </td>
                            <td class="table__right">
                                <div class="btn-row" style="justify-content:flex-end;">
                                    <?php if ($state === 'active'): ?>
                                        <form method="post" action="/devices/enrollment/<?= (int) $key['id'] ?>/revoke"
                                              data-confirm="<?= e(t('device.confirm_revoke_key', ['name' => (string) $key['name']])) ?>"
                                              data-confirm-detail="<?= e(t('device.confirm_revoke_key_detail')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--sm" type="submit"><?= e(t('action.revoke')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="/devices/enrollment/<?= (int) $key['id'] ?>/delete"
                                          data-confirm="<?= e(t('device.confirm_delete_key', ['name' => (string) $key['name']])) ?>"
                                          data-confirm-tone="danger">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--sm btn--ghost" type="submit"><?= icon('trash') ?><span class="visually-hidden"><?= e(t('action.delete')) ?></span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
