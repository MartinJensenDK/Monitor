<?php
/**
 * @var array<string,string> $settings
 * @var array<int,string> $timezones
 * @var array<int,string> $locales
 * @var string $cron
 * @var ?string $schedulerLastRun
 * @var array<string,int> $counts
 * @var bool $mailConfigured
 * @var string $pingTransport
 */

$lastRunAge = $schedulerLastRun === null ? null : max(0, time() - (strtotime($schedulerLastRun . ' UTC') ?: time()));
$schedulerHealthy = $lastRunAge !== null && $lastRunAge < 300;
?>
<div class="stack">
    <section class="panel">
        <div class="panel__head">
            <h2>Scheduler</h2>
            <span class="pill pill--<?= $schedulerHealthy ? 'up' : 'down' ?>" style="margin-left:auto;">
                <?= $schedulerHealthy ? 'running' : 'not running' ?>
            </span>
        </div>
        <div class="panel__body">
            <?php if ($schedulerHealthy): ?>
                <p class="muted mt-0">Last check ran <?= e(format_duration($lastRunAge)) ?> ago. Cron is doing its job.</p>
            <?php else: ?>
                <p class="mt-0">
                    <?= $schedulerLastRun === null
                        ? 'No check has ever run. Add this line to your crontab:'
                        : 'The last check ran ' . e(format_duration((int) $lastRunAge)) . ' ago, which is too long ago. Check the cron entry:' ?>
                </p>
            <?php endif; ?>
            <code class="code"><?= e($cron) ?></code>
            <p class="field__hint" style="margin-top:10px;">
                Stored measurements: <span class="num"><?= number_format($counts['checks']) ?></span> checks,
                <span class="num"><?= number_format($counts['minutes']) ?></span> minute buckets,
                <span class="num"><?= number_format($counts['hours']) ?></span> hour buckets.
            </p>
        </div>
    </section>

    <form method="post" action="/settings" class="stack">
        <?= csrf_field() ?>

        <section class="panel">
            <div class="panel__head"><h2>Site</h2></div>
            <div class="panel__body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="site_name">Site name</label>
                        <input class="input" id="site_name" name="site_name" value="<?= e($settings['site_name']) ?>">
                        <span class="field__hint">Shown in the sidebar, the page title and outgoing email.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="site_url">Site URL</label>
                        <input class="input input--mono" id="site_url" name="site_url" value="<?= e($settings['site_url']) ?>">
                        <span class="field__hint">Used for links in notifications.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="default_timezone">Default time zone</label>
                        <select class="select" id="default_timezone" name="default_timezone">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?= e($tz) ?>" <?= $settings['default_timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__hint">New accounts start here. Everything is stored in UTC.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="theme_default">Default theme</label>
                        <select class="select" id="theme_default" name="theme_default">
                            <?php foreach (['system' => 'Match the visitor’s system', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $settings['theme_default'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field__label" for="default_locale">Language</label>
                        <select class="select" id="default_locale" name="default_locale">
                            <?php foreach ($locales as $locale): ?>
                                <option value="<?= e($locale) ?>" <?= $settings['default_locale'] === $locale ? 'selected' : '' ?>>
                                    <?= e(strtoupper($locale)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__hint">
                            Add a language by copying <code>resources/lang/en.php</code> to your locale code and translating it.
                        </span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="default_role">Default role for new people</label>
                        <select class="select" id="default_role" name="default_role">
                            <?php foreach (['viewer' => t('role.viewer'), 'editor' => t('role.editor'), 'admin' => t('role.admin')] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $settings['default_role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head"><h2>Checking</h2></div>
            <div class="panel__body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="check_concurrency">Checks at once</label>
                        <input class="input num" id="check_concurrency" name="check_concurrency" type="number" min="1" max="100"
                               value="<?= e($settings['check_concurrency']) ?>">
                        <span class="field__hint">How many requests the scheduler runs in parallel. 20 suits most servers.</span>
                    </div>

                    <div class="field field--wide">
                        <label class="check">
                            <input type="checkbox" name="allow_private_targets" value="1"
                                   <?= $settings['allow_private_targets'] === '1' ? 'checked' : '' ?>>
                            <span class="check__text">
                                Allow private and loopback addresses
                                <small>
                                    Needed to watch hosts on your own network. Leave off on a shared or public install: it stops a
                                    monitor from being pointed at internal services.
                                </small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head"><h2>How long to keep data</h2></div>
            <div class="panel__body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="retention_checks_days">Individual checks</label>
                        <input class="input num" id="retention_checks_days" name="retention_checks_days" type="number" min="1" max="365"
                               value="<?= e($settings['retention_checks_days']) ?>">
                        <span class="field__hint">Days. The charts read the rollups, so this can stay short.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="retention_minutes_days">Minute rollups</label>
                        <input class="input num" id="retention_minutes_days" name="retention_minutes_days" type="number" min="1" max="730"
                               value="<?= e($settings['retention_minutes_days']) ?>">
                        <span class="field__hint">Days. Drives the 1 hour and 24 hour charts.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="retention_hours_days">Hour rollups</label>
                        <input class="input num" id="retention_hours_days" name="retention_hours_days" type="number" min="7" max="3650"
                               value="<?= e($settings['retention_hours_days']) ?>">
                        <span class="field__hint">Days. Daily rollups are kept forever — they are tiny.</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2>Email</h2>
                <span class="pill pill--<?= $settings['notifications_enabled'] === '1' ? 'up' : 'paused' ?>" style="margin-left:auto;">
                    <?= $settings['notifications_enabled'] === '1' ? 'sending' : 'off' ?>
                </span>
            </div>
            <div class="panel__body">
                <label class="check">
                    <input type="checkbox" name="notifications_enabled" value="1"
                           <?= $settings['notifications_enabled'] === '1' ? 'checked' : '' ?>>
                    <span class="check__text">
                        Send notification emails
                        <small>Off means checks still run and incidents are still recorded — nobody is emailed about them.</small>
                    </span>
                </label>

                <div class="form-grid" style="margin-top:16px;">
                    <div class="field">
                        <label class="field__label" for="mail_driver">How to send</label>
                        <select class="select" id="mail_driver" name="mail_driver">
                            <option value="smtp" <?= $settings['mail_driver'] === 'smtp' ? 'selected' : '' ?>>SMTP server</option>
                            <option value="sendmail" <?= $settings['mail_driver'] === 'sendmail' ? 'selected' : '' ?>>Local sendmail</option>
                        </select>
                        <span class="field__hint">SMTP delivers more reliably. Local sendmail needs correct SPF and DKIM on this server.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="mail_from_address">Sender address</label>
                        <input class="input" id="mail_from_address" name="mail_from_address" type="email"
                               value="<?= e($settings['mail_from_address']) ?>" placeholder="monitor@example.com">
                    </div>

                    <div class="field">
                        <label class="field__label" for="mail_from_name">Sender name</label>
                        <input class="input" id="mail_from_name" name="mail_from_name"
                               value="<?= e($settings['mail_from_name']) ?>">
                    </div>

                    <div class="field">
                        <label class="field__label" for="smtp_host">SMTP host</label>
                        <input class="input input--mono" id="smtp_host" name="smtp_host"
                               value="<?= e($settings['smtp_host']) ?>" placeholder="smtp.example.com">
                    </div>

                    <div class="field">
                        <label class="field__label" for="smtp_port">Port</label>
                        <input class="input num" id="smtp_port" name="smtp_port" type="number" min="1" max="65535"
                               value="<?= e($settings['smtp_port']) ?>">
                        <span class="field__hint">587 with STARTTLS, or 465 with SSL.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="smtp_encryption">Encryption</label>
                        <select class="select" id="smtp_encryption" name="smtp_encryption">
                            <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $settings['smtp_encryption'] === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field__label" for="smtp_username">SMTP user</label>
                        <input class="input" id="smtp_username" name="smtp_username" autocomplete="off"
                               value="<?= e($settings['smtp_username']) ?>">
                        <span class="field__hint">Leave empty for a server that needs no authentication.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="smtp_password">SMTP password</label>
                        <input class="input" id="smtp_password" name="smtp_password" type="password" autocomplete="new-password"
                               placeholder="<?= $settings['smtp_password'] !== '' ? 'Unchanged' : '' ?>">
                        <span class="field__hint">Encrypted with APP_KEY before it is stored. Leave empty to keep the current one.</span>
                    </div>
                </div>

                <p class="field__hint" style="margin-top:14px;">
                    Save first, then send yourself a test — the test uses what is stored, not what is on screen.
                </p>
            </div>
        </section>

        <div class="form-actions">
            <div class="btn-row" style="margin-left:auto;">
                <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e(t('action.save')) ?></button>
            </div>
        </div>
    </form>

    <section class="panel">
        <div class="panel__head"><h2>Send a test email</h2></div>
        <div class="panel__body">
            <?php if (!$mailConfigured): ?>
                <p class="muted mt-0">Fill in the sender address and the SMTP host above, save, and the test becomes available.</p>
            <?php else: ?>
                <form method="post" action="/settings/test-email" class="filters">
                    <?= csrf_field() ?>
                    <label class="field" style="flex:1;min-width:240px;">
                        <span class="visually-hidden">Recipient</span>
                        <input class="input" name="recipient" type="email" required
                               value="<?= e((string) ($authUser['email'] ?? '')) ?>">
                    </label>
                    <button class="btn" type="submit"><?= icon('mail') ?>Send test</button>
                </form>
                <p class="field__hint" style="margin-top:10px;">
                    A failure comes back with the reason the mail server gave, not just "could not send".
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2>Notification channels</h2>
            <a class="btn btn--sm" style="margin-left:auto;" href="/settings/channels"><?= icon('mail') ?>Manage channels</a>
        </div>
        <div class="panel__body">
            <p class="muted mt-0">
                A channel is a named list of recipients. Each monitor decides which channels it notifies and on what —
                down, recovery, slow responses, or an expiring certificate.
            </p>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>Ping</h2></div>
        <div class="panel__body">
            <p class="mt-0"><?= e(App\Checks\PingTransport::describe($pingTransport)) ?></p>
            <?php if (!App\Checks\PingTransport::isIcmp($pingTransport)): ?>
                <p class="field__hint" style="margin-top:10px;">To send real ICMP, a server administrator runs this once:</p>
                <code class="code"><?= e(App\Checks\PingTransport::enableHint()) ?></code>
                <p class="field__hint" style="margin-top:10px;">Monitor re-checks daily and switches over on its own.</p>
            <?php endif; ?>
        </div>
    </section>
</div>
