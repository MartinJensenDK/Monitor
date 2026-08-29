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

    <form class="card__form" method="post" action="/login">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required autofocus
                   autocomplete="username" value="<?= e(old('email')) ?>">
        </div>

        <div class="field">
            <label class="field__label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span class="check__text">Keep me signed in<small>For 30 days on this device.</small></span>
        </label>

        <button class="btn btn--primary" type="submit">Sign in</button>
    </form>
</div>
