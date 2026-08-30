<?php
/** @var string $siteName @var array<int,array{type:string,message:string}> $flash @var ?string $cron */
?>
<div class="card">
    <div class="card__brand">
        <span class="rail__mark"><?= icon('pulse') ?></span>
        <div>
            <p class="eyebrow">Uptime monitoring</p>
            <strong style="font-family: var(--font-display); font-size:16px;"><?= e($siteName) ?></strong>
        </div>
    </div>

    <?php if ($flash !== []): ?>
        <div class="flashes" style="margin-bottom:16px;">
            <?php foreach ($flash as $message): ?>
                <div class="flash flash--<?= e($message['type']) ?>" role="status">
                    <?= icon($message['type'] === 'success' ? 'check' : 'alert') ?>
                    <span><?= e($message['message']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (is_string($cron) && $cron !== ''): ?>
        <div class="stack stack--tight" style="margin-bottom:16px;">
            <p class="eyebrow">One step left</p>
            <p class="field__hint">Add this to your crontab so the checks actually run:</p>
            <code class="code"><?= e($cron) ?></code>
        </div>
    <?php endif; ?>

    <?php if (App\Entra\Entra::ssoEnabled()): ?>
        <a class="btn btn--primary" href="/auth/entra" style="width:100%;margin-bottom:16px;">
            <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="3" y="3" width="8.4" height="8.4" fill="#f25022"/>
                <rect x="12.6" y="3" width="8.4" height="8.4" fill="#7fba00"/>
                <rect x="3" y="12.6" width="8.4" height="8.4" fill="#00a4ef"/>
                <rect x="12.6" y="12.6" width="8.4" height="8.4" fill="#ffb900"/>
            </svg>
            Sign in with Microsoft
        </a>

        <?php if (App\Entra\Entra::localLoginAllowed()): ?>
            <p class="login-divider"><span>or with a password</span></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!App\Entra\Entra::localLoginAllowed()): ?>
        <p class="muted" style="font-size:13px;">
            This site signs in through Microsoft only. If you cannot get in, ask an administrator to turn password
            sign-in back on under Settings.
        </p>
    <?php else: ?>
    <form class="card__form" method="post" action="/login">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required autofocus
                   autocomplete="username" value="<?= e(old('email')) ?>">
        </div>

        <div class="field">
            <div class="field__head">
                <label class="field__label" for="password">Password</label>
                <a href="/forgot-password">Forgot it?</a>
            </div>
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span class="check__text">Keep me signed in<small>For 30 days on this device.</small></span>
        </label>

        <button class="btn <?= App\Entra\Entra::ssoEnabled() ? '' : 'btn--primary' ?>" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
</div>
