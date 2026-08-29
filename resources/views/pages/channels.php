<?php
/**
 * @var array<int,array<string,mixed>> $channels
 * @var array<int,array<string,mixed>> $log
 * @var bool $mailConfigured
 * @var bool $notificationsEnabled
 */
?>
<div class="stack">
    <?php if (!$notificationsEnabled || !$mailConfigured): ?>
        <div class="flash flash--warning">
            <?= icon('alert') ?>
            <span>
                <?= !$mailConfigured
                    ? 'Email is not configured yet, so nothing will be sent.'
                    : 'Notification emails are switched off, so nothing will be sent.' ?>
                <a href="/settings">Open email settings</a>.
            </span>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__head">
            <div>
                <p class="eyebrow">Who hears about it</p>
                <h2>Channels</h2>
            </div>
        </div>
        <div class="panel__body">
            <p class="field__hint mt-0" style="margin-bottom:16px;">
                A channel is a named list of addresses — <em>Ops on call</em>, <em>Support</em>, one person.
                Each monitor picks which channels it uses and what it tells them about.
            </p>

            <?php if ($channels === []): ?>
                <p class="muted">No channels yet. Add the first one below.</p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($channels as $channel): ?>
                        <form method="post" action="/settings/channels/<?= (int) $channel['id'] ?>">
                            <?= csrf_field() ?>
                            <fieldset class="fieldset">
                                <legend>
                                    <?= e((string) $channel['name']) ?>
                                    <?= (int) $channel['is_default'] === 1 ? ' · default' : '' ?>
                                </legend>

                                <div class="form-grid">
                                    <div class="field">
                                        <label class="field__label" for="name-<?= (int) $channel['id'] ?>">Name</label>
                                        <input class="input" id="name-<?= (int) $channel['id'] ?>" name="name"
                                               value="<?= e((string) $channel['name']) ?>" required>
                                    </div>

                                    <div class="field field--wide">
                                        <label class="field__label" for="recipients-<?= (int) $channel['id'] ?>">Recipients</label>
                                        <input class="input input--mono" id="recipients-<?= (int) $channel['id'] ?>" name="recipients"
                                               value="<?= e(implode(', ', $channel['recipients'])) ?>" required>
                                        <span class="field__hint">Separate addresses with a comma or a space.</span>
                                    </div>
                                </div>

                                <div class="form-actions" style="margin-top:14px;">
                                    <label class="check" style="border:0;padding:0;">
                                        <input type="checkbox" name="enabled" value="1" <?= (int) $channel['enabled'] === 1 ? 'checked' : '' ?>>
                                        <span class="check__text">Channel is active</span>
                                    </label>

                                    <span class="muted" style="font-size:12px;">
                                        used by <?= (int) $channel['monitor_count'] ?> monitor(s)
                                    </span>

                                    <div class="btn-row" style="margin-left:auto;">
                                        <?php if (can('settings.manage') && (int) $channel['is_default'] !== 1): ?>
                                            <button class="btn btn--sm btn--danger" type="submit"
                                                    formaction="/settings/channels/<?= (int) $channel['id'] ?>/delete"
                                                    formnovalidate
                                                    data-confirm="Delete <?= e((string) $channel['name']) ?>?"
                                                    data-confirm-detail="Monitors using it stop notifying through it."
                                                    data-confirm-label="Delete channel">
                                                <?= icon('trash') ?><?= e(t('action.delete')) ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (can('settings.manage')): ?>
                                            <button class="btn btn--sm btn--primary" type="submit"><?= e(t('action.save')) ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </fieldset>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (can('settings.manage')): ?>
        <section class="panel">
            <div class="panel__head"><h2>Add a channel</h2></div>
            <div class="panel__body">
                <form method="post" action="/settings/channels">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="new-name">Name</label>
                            <input class="input" id="new-name" name="name" required placeholder="Ops on call">
                        </div>

                        <div class="field field--wide">
                            <label class="field__label" for="new-recipients">Recipients</label>
                            <input class="input input--mono" id="new-recipients" name="recipients" required
                                   placeholder="ops@example.com, oncall@example.com">
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top:14px;">
                        <label class="check" style="border:0;padding:0;">
                            <input type="checkbox" name="enabled" value="1" checked>
                            <span class="check__text">Channel is active</span>
                        </label>
                        <div class="btn-row" style="margin-left:auto;">
                            <button class="btn btn--primary" type="submit"><?= icon('plus') ?>Add channel</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__head">
            <h2>What was sent</h2>
        </div>
        <?php if ($log === []): ?>
            <div class="panel__body">
                <p class="muted mt-0">Nothing yet. Every email — and every one deliberately skipped — is recorded here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Monitor</th>
                            <th>Event</th>
                            <th>Channel</th>
                            <th>Result</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $entry): ?>
                            <tr>
                                <td class="num nowrap"><?= e(local_time((string) $entry['created_at'], 'M j, H:i')) ?></td>
                                <td><?= e((string) ($entry['monitor_name'] ?? '—')) ?></td>
                                <td><span class="pill pill--plain"><?= e(str_replace('monitor.', '', (string) $entry['event'])) ?></span></td>
                                <td><?= e((string) ($entry['channel_name'] ?? '—')) ?></td>
                                <td><span class="log-status log-status--<?= e((string) $entry['status']) ?>"><?= e((string) $entry['status']) ?></span></td>
                                <td class="muted"><?= e((string) ($entry['error'] ?? $entry['recipient'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
