<?php
/**
 * @var string $content
 * @var string $title
 * @var string $theme
 * @var string $siteName
 * @var string $currentPath
 * @var array<string,mixed>|null $authUser
 * @var array<int,array{type:string,message:string}> $flash
 */

use App\Domain\Incidents;
use App\Support\Str;

$openIncidents = Incidents::openCount();
$counts = App\Domain\Monitors::statusCounts();
$readoutState = $counts['down'] > 0 ? 'down' : ($counts['degraded'] > 0 ? 'warn' : ($counts['total'] === 0 ? 'idle' : 'up'));
$readoutText = $counts['down'] > 0
    ? ($counts['down'] === 1 ? t('status.monitor_down') : t('status.monitors_down', ['count' => $counts['down']]))
    : ($counts['degraded'] > 0
        ? t('status.degraded_count', ['count' => $counts['degraded']])
        : ($counts['total'] === 0 ? t('status.nothing_yet') : t('status.all_operational')));

$nav = [
    ['path' => '/', 'label' => t('nav.dashboard'), 'icon' => 'gauge', 'match' => '/'],
    ['path' => '/monitors', 'label' => t('nav.monitors'), 'icon' => 'pulse', 'match' => '/monitors'],
    ['path' => '/incidents', 'label' => t('nav.incidents'), 'icon' => 'alert', 'match' => '/incidents', 'count' => $openIncidents],
];

$adminNav = [];
if (can('users.view')) {
    $adminNav[] = ['path' => '/users', 'label' => t('nav.people'), 'icon' => 'users', 'match' => '/users'];
}
if (can('groups.view')) {
    $adminNav[] = ['path' => '/groups', 'label' => t('nav.groups'), 'icon' => 'group', 'match' => '/groups'];
}
if (can('settings.view')) {
    $adminNav[] = ['path' => '/settings', 'label' => t('nav.settings'), 'icon' => 'sliders', 'match' => '/settings'];
}

$isActive = static function (string $match) use ($currentPath): bool {
    return $match === '/' ? $currentPath === '/' : str_starts_with($currentPath, $match);
};
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
<body>
<div class="shell">
    <nav class="rail" aria-label="Main">
        <a class="rail__brand" href="/">
            <span class="rail__mark"><?= icon('pulse') ?></span>
            <span class="rail__name"><?= e($siteName) ?></span>
        </a>

        <div class="rail__nav">
            <?php foreach ($nav as $item): ?>
                <a class="rail__link" href="<?= e($item['path']) ?>"
                   <?= $isActive($item['match']) ? 'aria-current="page"' : '' ?>>
                    <?= icon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                    <?php if (($item['count'] ?? 0) > 0): ?>
                        <span class="rail__count num"><?= (int) $item['count'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($adminNav !== []): ?>
            <p class="eyebrow rail__section"><?= e(t('nav.administration')) ?></p>
            <div class="rail__nav">
                <?php foreach ($adminNav as $item): ?>
                    <a class="rail__link" href="<?= e($item['path']) ?>"
                       <?= $isActive($item['match']) ? 'aria-current="page"' : '' ?>>
                        <?= icon($item['icon']) ?>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="rail__foot">
            <a class="rail__user" href="/profile">
                <span class="avatar"><?= e(Str::initials((string) ($authUser['name'] ?? '?'))) ?></span>
                <span class="rail__userinfo">
                    <span class="rail__username truncate"><?= e((string) ($authUser['name'] ?? '')) ?></span>
                    <span class="rail__role"><?= e(App\Core\Rbac::label((string) ($authUser['role'] ?? 'viewer'))) ?></span>
                </span>
            </a>
        </div>
    </nav>

    <div class="main">
        <header class="topbar">
            <div class="topbar__title">
                <p class="eyebrow"><?= e($siteName) ?></p>
                <h1><?= e($title) ?></h1>
            </div>

            <div class="topbar__actions">
                <span class="readout readout--<?= e($readoutState) ?>" data-live-readout>
                    <span class="readout__dot"></span>
                    <span data-live-readout-text><?= e($readoutText) ?></span>
                    <span class="readout__clock num" data-clock>--:--:--</span>
                </span>

                <?php // The button shows the theme it switches to, so the icon is the
                      // outcome rather than the current state. Which of the two icons
                      // is visible is decided in CSS from <html data-theme>, so the
                      // server's own choice paints correctly before any script runs. ?>
                <button class="btn btn--ghost btn--icon theme-toggle" type="button" data-theme-toggle
                        data-label-dark="<?= e(t('nav.theme_dark')) ?>" data-label-light="<?= e(t('nav.theme_light')) ?>"
                        title="<?= e(t($theme === 'dark' ? 'nav.theme_light' : 'nav.theme_dark')) ?>"
                        aria-label="<?= e(t($theme === 'dark' ? 'nav.theme_light' : 'nav.theme_dark')) ?>">
                    <?= icon('moon', 'icon theme-toggle__moon') ?>
                    <?= icon('sun', 'icon theme-toggle__sun') ?>
                </button>

                <form method="post" action="/logout">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--icon" type="submit" title="<?= e(t('nav.sign_out')) ?>">
                        <?= icon('logout') ?><span class="visually-hidden"><?= e(t('nav.sign_out')) ?></span>
                    </button>
                </form>
            </div>
        </header>

        <main class="page">
            <?php
            // A migration that has not run makes newer features fail at the
            // point of saving, with a database error rather than an
            // explanation. Say so up front, and only to someone who can fix it.
            $pendingMigrations = can('settings.manage') ? \App\Install\Migrator::outstanding() : [];
            ?>
            <?php if ($pendingMigrations !== []): ?>
                <div class="flashes">
                    <div class="flash flash--warning" role="status">
                        <?= icon('alert') ?>
                        <span>
                            <strong>A database update is waiting.</strong>
                            <?= count($pendingMigrations) === 1
                                ? 'One migration has not run yet. Until it does,'
                                : count($pendingMigrations) . ' migrations have not run yet. Until they do,' ?>
                            anything they add — the newer monitor types among them — cannot be saved.
                            Run this on the server, as the user that owns the site:
                            <code><?= e('php ' . \App\Core\App::basePath('bin/migrate.php')) ?></code>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($flash !== []): ?>
                <div class="flashes">
                    <?php foreach ($flash as $message): ?>
                        <div class="flash flash--<?= e($message['type']) ?>" role="status">
                            <?= icon($message['type'] === 'success' ? 'check' : ($message['type'] === 'error' ? 'alert' : 'clock')) ?>
                            <span><?= e($message['message']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= asset('assets/js/modal.js') ?>" defer></script>
<script src="<?= asset('assets/js/app.js') ?>" defer></script>
<script src="<?= asset('assets/js/tape.js') ?>" defer></script>
<script src="<?= asset('assets/js/live.js') ?>" defer></script>
<?php if (!empty($needsCharts)): ?>
    <script src="<?= asset('assets/vendor/uPlot.iife.min.js') ?>" defer></script>
    <script src="<?= asset('assets/js/charts.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
