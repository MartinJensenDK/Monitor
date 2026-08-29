<?php
/**
 * The setup wizard. Everything lives in one form; the steps are shown one at a
 * time by install.js and all at once without JavaScript, so the install never
 * depends on scripting.
 *
 * @var array<int,array{label:string,ok:bool,detail:string,fix:string,required:bool}> $checks
 * @var bool $ready
 * @var array<string,string> $defaults
 * @var array<int,string> $timezones
 * @var array<int,array{type:string,message:string}> $errors
 * @var string $csrf
 */
?>
<div class="card card--wide" data-wizard>
    <div class="card__brand">
        <span class="rail__mark"><?= icon('pulse') ?></span>
        <div>
            <p class="eyebrow">Set up</p>
            <strong style="font-family: var(--font-display); font-size:16px;">Monitor</strong>
        </div>
    </div>

    <?php if ($errors !== []): ?>
        <div class="flashes" style="margin-bottom:18px;">
            <?php foreach ($errors as $message): ?>
                <div class="flash flash--<?= e($message['type']) ?>"><?= icon('alert') ?><span><?= e($message['message']) ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <ol class="steps" data-steps>
        <li class="steps__item" data-state="current">1. Requirements</li>
        <li class="steps__item">2. Database</li>
        <li class="steps__item">3. Site</li>
        <li class="steps__item">4. Administrator</li>
    </ol>

    <form method="post" action="/install" class="card__form" data-install-form>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <!-- 1 ─ Requirements ------------------------------------------------->
        <section class="step" data-step="0" data-active="true">
            <h2>Can this server run Monitor?</h2>
            <p class="field__hint">Everything marked with a cross has to be fixed before the install can continue.</p>

            <div class="checklist" style="margin-top:14px;">
                <?php foreach ($checks as $check): ?>
                    <div class="checklist__item" data-ok="<?= $check['ok'] ? '1' : ($check['required'] ? '0' : 'warn') ?>">
                        <span class="checklist__mark"><?= $check['ok'] ? '✓' : ($check['required'] ? '✗' : '!') ?></span>
                        <span>
                            <?= e($check['label']) ?>
                            <?php if (!$check['ok']): ?>
                                <small style="display:block;color:var(--ink-muted);"><?= e($check['fix']) ?></small>
                            <?php endif; ?>
                        </span>
                        <span class="checklist__detail"><?= e($check['detail']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions" style="margin-top:18px;">
                <div class="btn-row" style="margin-left:auto;">
                    <?php if ($ready): ?>
                        <button class="btn btn--primary" type="button" data-next>Continue<?= icon('chevron') ?></button>
                    <?php else: ?>
                        <a class="btn" href="/install">Check again</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 2 ─ Database ---------------------------------------------------->
        <section class="step" data-step="1">
            <h2>Connect the database</h2>
            <p class="field__hint">
                Monitor needs an empty MySQL 8 or MariaDB 10.6 database and a user with full rights to it.
                The password is written to <code>.env</code> outside the webroot — never into the database itself.
            </p>

            <label class="check" style="margin-top:14px;">
                <input type="checkbox" name="same_host" value="1" checked data-same-host>
                <span class="check__text">
                    MySQL runs on this server
                    <small>Uses 127.0.0.1 on port 3306. Uncheck to point at another database server.</small>
                </span>
            </label>

            <div class="form-grid" style="margin-top:14px;">
                <div class="field" data-remote-only hidden>
                    <label class="field__label" for="db_host">Database host</label>
                    <input class="input input--mono" id="db_host" name="db_host" value="127.0.0.1">
                </div>

                <div class="field" data-remote-only hidden>
                    <label class="field__label" for="db_port">Port</label>
                    <input class="input num" id="db_port" name="db_port" type="number" value="3306" min="1" max="65535">
                </div>

                <div class="field">
                    <label class="field__label" for="db_name">Database name</label>
                    <input class="input input--mono" id="db_name" name="db_name" required
                           value="<?= e(old('db_name', $defaults['db_name'])) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="db_user">Database user</label>
                    <input class="input input--mono" id="db_user" name="db_user" required
                           value="<?= e(old('db_user', $defaults['db_user'])) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="db_password">Database password</label>
                    <input class="input" id="db_password" name="db_password" type="password" autocomplete="off">
                </div>

                <div class="field">
                    <label class="field__label" for="db_prefix">Table prefix <span class="muted">(optional)</span></label>
                    <input class="input input--mono" id="db_prefix" name="db_prefix" value="<?= e(old('db_prefix')) ?>"
                           placeholder="mon_">
                    <span class="field__hint">Only needed when the database is shared with another application.</span>
                </div>
            </div>

            <div class="form-actions" style="margin-top:16px;">
                <button class="btn" type="button" data-test-db><?= icon('link') ?>Test the connection</button>
                <div class="btn-row" style="margin-left:auto;">
                    <button class="btn btn--ghost" type="button" data-back>Back</button>
                    <button class="btn btn--primary" type="button" data-next disabled data-requires-db>Continue<?= icon('chevron') ?></button>
                </div>
            </div>

            <p class="result" data-db-result role="status" style="margin-top:12px;"></p>
        </section>

        <!-- 3 ─ Site -------------------------------------------------------->
        <section class="step" data-step="2">
            <h2>Name the site</h2>
            <div class="form-grid" style="margin-top:14px;">
                <div class="field">
                    <label class="field__label" for="site_name">Site name</label>
                    <input class="input" id="site_name" name="site_name" required value="<?= e(old('site_name', 'Monitor')) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="site_url">Site URL</label>
                    <input class="input input--mono" id="site_url" name="site_url" required
                           value="<?= e(old('site_url', $defaults['site_url'])) ?>">
                </div>

                <div class="field field--wide">
                    <label class="field__label" for="timezone">Time zone</label>
                    <select class="select" id="timezone" name="timezone">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= old('timezone', $defaults['timezone']) === $tz ? 'selected' : '' ?>>
                                <?= e($tz) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">Used when showing times. Measurements are always stored in UTC.</span>
                </div>
            </div>

            <div class="form-actions" style="margin-top:16px;">
                <div class="btn-row" style="margin-left:auto;">
                    <button class="btn btn--ghost" type="button" data-back>Back</button>
                    <button class="btn btn--primary" type="button" data-next>Continue<?= icon('chevron') ?></button>
                </div>
            </div>
        </section>

        <!-- 4 ─ Administrator ----------------------------------------------->
        <section class="step" data-step="3">
            <h2>Create your account</h2>
            <p class="field__hint">This first account is an administrator. You can add more people afterwards.</p>

            <div class="form-grid" style="margin-top:14px;">
                <div class="field">
                    <label class="field__label" for="admin_name">Your name</label>
                    <input class="input" id="admin_name" name="admin_name" required value="<?= e(old('admin_name')) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="admin_email">Email</label>
                    <input class="input" id="admin_email" name="admin_email" type="email" required value="<?= e(old('admin_email')) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="admin_password">Password</label>
                    <input class="input" id="admin_password" name="admin_password" type="password" required
                           autocomplete="new-password" placeholder="At least 10 characters">
                </div>

                <div class="field">
                    <label class="field__label" for="admin_password_confirmation">Repeat password</label>
                    <input class="input" id="admin_password_confirmation" name="admin_password_confirmation" type="password"
                           required autocomplete="new-password">
                </div>
            </div>

            <div class="form-actions" style="margin-top:16px;">
                <div class="btn-row" style="margin-left:auto;">
                    <button class="btn btn--ghost" type="button" data-back>Back</button>
                    <button class="btn btn--primary" type="submit"><?= icon('check') ?>Install Monitor</button>
                </div>
            </div>
        </section>
    </form>
</div>
