#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Syncs users and groups from Microsoft Entra ID.
 *
 * The scheduler already runs this once an hour when syncing is switched on.
 * Use this to run it now, or to see what it does:
 *
 *   php bin/entra-sync.php
 *   php bin/entra-sync.php --status
 */

if (PHP_SAPI !== 'cli') {
    exit("This script runs from the command line only.\n");
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

use App\Core\App;
use App\Domain\Settings;
use App\Entra\Entra;
use App\Entra\Graph;
use App\Entra\Sync;

App::boot(dirname(__DIR__));

if (!App::isInstalled()) {
    fwrite(STDERR, "Monitor is not installed yet.\n");
    exit(1);
}

$options = getopt('', ['status', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 620), PHP_EOL;
    exit(0);
}

if (isset($options['status'])) {
    printf("Configured:    %s\n", Entra::isConfigured() ? 'yes' : 'no');
    printf("SSO enabled:   %s\n", Entra::ssoEnabled() ? 'yes' : 'no');
    printf("Sync enabled:  %s\n", Entra::syncEnabled() ? 'yes' : 'no');
    printf("Tenant:        %s\n", Entra::tenantId() ?: '—');
    printf("Redirect URI:  %s\n", Entra::redirectUri());
    printf("Groups:        %s\n", implode(', ', Entra::syncGroupIds()) ?: '—');
    printf("Last sync:     %s\n", Settings::get('entra_last_sync_at') ?: 'never');
    printf("Last result:   %s\n", Settings::get('entra_last_sync_summary') ?: '—');

    if (Entra::isConfigured()) {
        $test = Graph::test();
        printf("Graph check:   %s — %s\n", $test['ok'] ? 'ok' : 'failed', $test['message']);
    }

    exit(0);
}

if (!Entra::isConfigured()) {
    fwrite(STDERR, "Microsoft Entra ID is not configured. Fill it in under Settings first.\n");
    exit(1);
}

$summary = Sync::run();

echo $summary['message'], "\n";

foreach ($summary['errors'] as $error) {
    fwrite(STDERR, '  ' . $error . "\n");
}

exit($summary['ok'] ? 0 : 1);
