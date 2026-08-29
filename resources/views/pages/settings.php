<?php
/**
 * @var array<string,string> $settings
 * @var array<int,string> $timezones
 * @var array<int,string> $locales
 * @var string $cron
 * @var ?string $schedulerLastRun
 * @var array<string,int> $counts
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
            <div class="panel__head"><h2>Notifications</h2></div>
            <div class="panel__body">
                <p class="mt-0 muted">
                    Email delivery and the per-monitor notification rules arrive in the next release. The channels and rules
                    are already in the database, so nothing here has to be re-entered later.
                </p>
                <p style="margin-top:12px;">
                    <a class="btn" href="/settings/activity"><?= icon('history') ?>Open the activity log</a>
                </p>
            </div>
        </section>

        <div class="form-actions">
            <div class="btn-row" style="margin-left:auto;">
                <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e(t('action.save')) ?></button>
            </div>
        </div>
    </form>
</div>
