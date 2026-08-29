#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Installs Monitor from the command line. Asks the same questions as the
 * browser wizard and shares the same Installer, so the two cannot drift.
 *
 *   php bin/install.php
 *   php bin/install.php --same-host --db-name=monitor-db --db-user=user-db-monitor \
 *       --db-pass=secret --admin-email=you@example.com --admin-password=... --non-interactive
 */

if (PHP_SAPI !== 'cli') {
    exit("This script runs from the command line only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Install\Installer;
use App\Install\RequirementsCheck;
use App\Install\DbTester;

$basePath = dirname(__DIR__);
App::boot($basePath);

$options = getopt('', [
    'same-host', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'db-prefix::',
    'site-name::', 'site-url::', 'timezone::', 'locale::',
    'admin-name::', 'admin-email::', 'admin-password::',
    'non-interactive', 'force', 'help',
]);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 700), PHP_EOL;
    exit(0);
}

$interactive = !isset($options['non-interactive']);

line('');
line('  Monitor installer');
line('  ' . str_repeat('─', 60));

if (is_file(App::lockFile()) && !isset($options['force'])) {
    fail('Monitor is already installed. Pass --force to run the installer again (this creates a second admin).');
}

/* ── 1. Requirements ─────────────────────────────────────────────────── */

line('');
line('  Requirements');
$blocking = 0;
foreach (RequirementsCheck::run($basePath) as $check) {
    $mark = $check['ok'] ? '✓' : ($check['required'] ? '✗' : '!');
    line(sprintf('   %s  %-38s %s', $mark, $check['label'], $check['detail']));
    if (!$check['ok'] && $check['required']) {
        $blocking++;
        line('        → ' . $check['fix']);
    }
}
if ($blocking > 0) {
    fail(sprintf('%d requirement(s) are not met. Fix them and run the installer again.', $blocking));
}

/* ── 2. Database ─────────────────────────────────────────────────────── */

line('');
line('  Database');

$sameHost = isset($options['same-host']);
if ($interactive && !isset($options['db-host']) && !$sameHost) {
    $sameHost = confirm('   MySQL runs on this server', true);
}

$db = [
    'host' => $sameHost ? '127.0.0.1' : (string) ($options['db-host'] ?? ask('   Database host', '127.0.0.1', $interactive)),
    'port' => (int) ($options['db-port'] ?? ($sameHost ? 3306 : ask('   Database port', '3306', $interactive))),
    'database' => (string) ($options['db-name'] ?? ask('   Database name', 'monitor-db', $interactive)),
    'username' => (string) ($options['db-user'] ?? ask('   Database user', 'user-db-monitor', $interactive)),
    'password' => (string) ($options['db-pass'] ?? askSecret('   Database password', $interactive)),
    'prefix' => (string) ($options['db-prefix'] ?? ''),
];

while (true) {
    $test = DbTester::test($db);
    if ($test['ok']) {
        line('   ✓ ' . $test['message'] . ' (MySQL ' . $test['version'] . ')');
        if ($test['tables'] > 0) {
            line('   ! The database already holds ' . $test['tables'] . ' table(s). Existing tables are left alone.');
        }
        break;
    }

    line('   ✗ ' . $test['message']);
    if (!$interactive) {
        fail('Database connection failed.');
    }
    line('');
    $db['host'] = ask('   Database host', $db['host'], true);
    $db['port'] = (int) ask('   Database port', (string) $db['port'], true);
    $db['database'] = ask('   Database name', $db['database'], true);
    $db['username'] = ask('   Database user', $db['username'], true);
    $db['password'] = askSecret('   Database password', true);
}

/* ── 3. Site ─────────────────────────────────────────────────────────── */

line('');
line('  Site');
$site = [
    'name' => (string) ($options['site-name'] ?? ask('   Site name', 'Monitor', $interactive)),
    'url' => rtrim((string) ($options['site-url'] ?? ask('   Site URL', 'https://' . (gethostname() ?: 'localhost'), $interactive)), '/'),
    'timezone' => (string) ($options['timezone'] ?? ask('   Time zone', date_default_timezone_get(), $interactive)),
    'locale' => (string) ($options['locale'] ?? 'en'),
];

if (!in_array($site['timezone'], timezone_identifiers_list(), true)) {
    line('   ! "' . $site['timezone'] . '" is not a known time zone. Using UTC.');
    $site['timezone'] = 'UTC';
}

/* ── 4. Administrator ────────────────────────────────────────────────── */

line('');
line('  Administrator');
$admin = [
    'name' => (string) ($options['admin-name'] ?? ask('   Your name', 'Administrator', $interactive)),
    'email' => strtolower(trim((string) ($options['admin-email'] ?? ask('   Email', '', $interactive)))),
    'password' => (string) ($options['admin-password'] ?? askSecret('   Password (10 characters or more)', $interactive)),
];

if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
    fail('"' . $admin['email'] . '" is not a valid email address.');
}
if (strlen($admin['password']) < 10) {
    fail('The administrator password must be at least 10 characters.');
}

/* ── 5. Install ──────────────────────────────────────────────────────── */

line('');
$installer = new Installer($basePath);

try {
    $installer->install($db, $site, $admin);
    $installer->lock();
} catch (Throwable $e) {
    fail($e->getMessage());
}

line('  ✓ Wrote .env (chmod 600) with the database credentials');
line('  ✓ Created the database tables');
line('  ✓ Created the administrator and the "All monitors" group');
line('');
line('  One step left — add this line to your crontab (crontab -e):');
line('');
line('    ' . $installer->cronLine());
line('');
line('  Then sign in at ' . $site['url'] . ' as ' . $admin['email']);
line('');

exit(0);

/* ── helpers ─────────────────────────────────────────────────────────── */

function line(string $text): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL . '  Install stopped: ' . $message . PHP_EOL . PHP_EOL);
    exit(1);
}

function ask(string $label, string $default, bool $interactive): string
{
    if (!$interactive) {
        return $default;
    }

    $suffix = $default === '' ? ': ' : ' [' . $default . ']: ';
    fwrite(STDOUT, $label . $suffix);
    $answer = trim((string) fgets(STDIN));

    return $answer === '' ? $default : $answer;
}

function askSecret(string $label, bool $interactive): string
{
    if (!$interactive) {
        return '';
    }

    fwrite(STDOUT, $label . ': ');
    $hidden = @shell_exec('stty -g 2>/dev/null');
    if (is_string($hidden) && trim($hidden) !== '') {
        @shell_exec('stty -echo 2>/dev/null');
        $answer = trim((string) fgets(STDIN));
        @shell_exec('stty ' . trim($hidden) . ' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);

        return $answer;
    }

    return trim((string) fgets(STDIN));
}

function confirm(string $label, bool $default): bool
{
    fwrite(STDOUT, $label . ' [' . ($default ? 'Y/n' : 'y/N') . ']: ');
    $answer = strtolower(trim((string) fgets(STDIN)));

    if ($answer === '') {
        return $default;
    }

    return in_array($answer, ['y', 'yes', 'j', 'ja'], true);
}
