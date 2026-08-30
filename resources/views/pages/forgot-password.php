<?php
/** @var string $siteName @var array<int,array{type:string,message:string}> $flash */
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
        <p class="eyebrow">Reset your password</p>
        <p class="field__hint">
            Tell us the address you sign in with and we will mail you a link. It works once,
            and only for the next <?= (int) App\Domain\PasswordResets::TTL_MINUTES ?> minutes.
        </p>
    </div>

    <form class="card__form" method="post" action="/forgot-password">
        <?= csrf_field() ?>

        <div class="field">
            <label class="field__label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required autofocus
                   autocomplete="username" value="<?= e(old('email')) ?>">
        </div>

        <button class="btn btn--primary" type="submit">Send the link</button>
    </form>

    <p class="card__foot"><a href="/login">Back to sign in</a></p>
</div>
