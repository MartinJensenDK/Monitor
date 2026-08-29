<?php
/** @var int $status @var string $message */
?>
<div class="card" style="text-align:center;">
    <p class="eyebrow"><?= (int) $status ?></p>
    <h1 style="margin: 6px 0 10px;">
        <?= $status === 404 ? 'Not here' : ($status === 403 ? 'No access' : 'Something broke') ?>
    </h1>
    <p class="muted"><?= e($message) ?></p>
    <p style="margin-top:18px;">
        <a class="btn btn--primary" href="/"><?= icon('arrow-left') ?> Back to the dashboard</a>
    </p>
</div>
