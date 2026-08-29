#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs the monitor checks. Cron starts this once a minute:
 *
 *   * * * * * /usr/bin/php8.3 /path/to/bin/scheduler.php >> /path/to/storage/logs/scheduler.log 2>&1
 *
 * Flags:
 *   --once            one pass over what is due, then exit
 *   --monitor=<id>    check one monitor regardless of its schedule
 *   --dry-run         run the checks but write nothing
 *   --verbose         print each result
 *   --seconds=<n>     how long to keep ticking (default 55)
 */

if (PHP_SAPI !== 'cli') {
    exit("This script runs from the command line only.\n");
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

use App\Core\App;
use App\Core\Db;
use App\Entra\Sync;
use App\Notifications\CertificateWatcher;
use App\Scheduler\Retention;
use App\Scheduler\Rollup;
use App\Scheduler\Runner;

$options = getopt('', ['once', 'monitor::', 'dry-run', 'verbose', 'seconds::', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 900), PHP_EOL;
    exit(0);
}

App::boot(dirname(__DIR__));

if (!App::isInstalled()) {
    fwrite(STDERR, "Monitor is not installed yet. Run bin/install.php or open the site in a browser.\n");
    exit(1);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$monitorId = isset($options['monitor']) && $options['monitor'] !== false ? (int) $options['monitor'] : null;

if (!$dryRun && !Db::tryLock(Runner::LOCK)) {
    if ($verbose) {
        echo "Another scheduler run is still working. Skipping this minute.\n";
    }
    exit(0);
}

$started = microtime(true);
$runner = new Runner($verbose, $dryRun);

try {
    if ($monitorId !== null) {
        $runner->tick($monitorId);
    } elseif (isset($options['once'])) {
        $runner->tick();
    } else {
        $runner->loop(max(5, min(300, (int) ($options['seconds'] ?? 55))));
    }

    if (!$dryRun) {
        Rollup::aggregate();

        if (Sync::due()) {
            $sync = Sync::run();
            if ($verbose) {
                echo '  entra: ' . $sync['message'] . "\n";
            }
        }

        if (Retention::due()) {
            $removed = Retention::prune();
            CertificateWatcher::run();
            Retention::markRun();
            if ($verbose) {
                foreach ($removed as $table => $count) {
                    if ($count > 0) {
                        echo sprintf("  pruned %d row(s) from %s\n", $count, $table);
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    App::logError($e);
    fwrite(STDERR, 'Scheduler error: ' . $e->getMessage() . "\n");
    if (!$dryRun) {
        Db::releaseLock(Runner::LOCK);
    }
    exit(1);
}

if (!$dryRun) {
    Db::releaseLock(Runner::LOCK);
}

if ($verbose) {
    printf("Done: %d check(s) in %.1fs\n", $runner->checkedCount(), microtime(true) - $started);
}
