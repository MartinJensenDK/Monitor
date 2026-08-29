#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies pending database migrations.
 *
 *   php bin/migrate.php            run everything that is pending
 *   php bin/migrate.php --status   list migrations and whether they ran
 */

if (PHP_SAPI !== 'cli') {
    exit("This script runs from the command line only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Install\Migrator;

App::boot(dirname(__DIR__));

if (!App::isInstalled()) {
    fwrite(STDERR, "Monitor is not installed yet. Run bin/install.php first.\n");
    exit(1);
}

$migrator = new Migrator(App::basePath('database/migrations'));
$options = getopt('', ['status']);

if (isset($options['status'])) {
    $applied = $migrator->applied();
    foreach ($migrator->available() as $migration) {
        printf("%-8s %s\n", in_array($migration, $applied, true) ? 'ran' : 'pending', $migration);
    }
    exit(0);
}

try {
    $ran = $migrator->run();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($ran === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($ran as $migration) {
    echo 'Migrated: ' . $migration . "\n";
}
