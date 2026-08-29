<?php
/** @var string $content @var string $title @var string $theme @var string $siteName */
?>
<!DOCTYPE html>
<html lang="<?= e(App\Core\Lang::locale()) ?>" data-theme="<?= e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title !== '' ? $title . ' · ' . $siteName : $siteName) ?></title>
    <link rel="icon" href="<?= asset('assets/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="bare">
<?= $content ?>
<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</body>
</html>
