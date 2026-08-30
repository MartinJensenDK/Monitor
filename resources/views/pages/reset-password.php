<?php
/** @var string $siteName @var string $token @var array<int,array{type:string,message:string}> $flash */
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

    <div class="stack stack--tight" style="margin-bottom:16px;">
        <p class="eyebrow">Choose a new password</p>
        <p class="field__hint">At least 10 characters. Signing in on other devices will need it too.</p>
    </div>

    <form class="card__form" method="post" action="/reset-password/<?= e(rawurlencode($token)) ?>">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="password">New password</label>
            <input class="input" id="password" name="password" type="password" required autofocus
                   autocomplete="new-password" minlength="10">
        </div>

        <div class="field">
            <label class="field__label" for="password_confirmation">Repeat it</label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" required
                   autocomplete="new-password" minlength="10">
        </div>

        <button class="btn btn--primary" type="submit">Save the password</button>
    </form>
</div>
