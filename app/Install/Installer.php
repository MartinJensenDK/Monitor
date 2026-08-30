<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Auth;
use App\Core\Db;
use App\Domain\Settings;
use App\Support\Crypto;
use App\Support\Env;
use RuntimeException;

/**
 * Shared by the browser setup wizard and bin/install.php, so the two can never
 * drift apart.
 */
final class Installer
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string,mixed> $db      host, port, database, username, password, prefix
     * @param array<string,mixed> $site    name, url, timezone, locale
     * @param array<string,mixed> $admin   name, email, password
     */
    public function install(array $db, array $site, array $admin): void
    {
        $test = DbTester::test($db);
        if (!$test['ok']) {
            throw new RuntimeException($test['message']);
        }

        $key = Crypto::generateKey();
        Crypto::setKey($key);

        $this->writeEnv($db, $site, $key);

        Db::connect([
            'host' => $db['host'] ?? '127.0.0.1',
            'port' => $db['port'] ?? 3306,
            'database' => $db['database'] ?? '',
            'username' => $db['username'] ?? '',
            'password' => $db['password'] ?? '',
            'prefix' => $db['prefix'] ?? '',
        ]);

        (new Migrator($this->basePath . '/database/migrations'))->run();

        $this->seed($site, $admin);
    }

    /**
     * @param array<string,mixed> $db
     * @param array<string,mixed> $site
     */
    public function writeEnv(array $db, array $site, string $appKey): void
    {
        $body = Env::render([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim((string) ($site['url'] ?? ''), '/'),
            'APP_KEY' => $appKey,
            'APP_TIMEZONE' => (string) ($site['timezone'] ?? 'UTC'),
            'APP_LOCALE' => (string) ($site['locale'] ?? 'en'),
            'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($db['port'] ?? 3306),
            'DB_NAME' => (string) ($db['database'] ?? ''),
            'DB_USER' => (string) ($db['username'] ?? ''),
            'DB_PASS' => (string) ($db['password'] ?? ''),
            'DB_PREFIX' => (string) ($db['prefix'] ?? ''),
        ]);

        $path = $this->basePath . '/.env';
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException('Could not write ' . $path . '. Make the project folder writable and try again.');
        }
        @chmod($path, 0600);
    }

    /**
     * @param array<string,mixed> $site
     * @param array<string,mixed> $admin
     */
    private function seed(array $site, array $admin): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $userId = Db::insert('users', [
            'name' => (string) ($admin['name'] ?? 'Administrator'),
            'email' => strtolower(trim((string) ($admin['email'] ?? ''))),
            'password_hash' => Auth::hash((string) ($admin['password'] ?? '')),
            'role' => 'admin',
            'status' => 'active',
            'timezone' => (string) ($site['timezone'] ?? 'UTC'),
            'locale' => (string) ($site['locale'] ?? 'en'),
            'theme' => 'light',
            'auth_provider' => 'local',
            'created_at' => $now,
        ]);

        // Every install starts with one group so a new monitor always has
        // somewhere to live and never becomes invisible by accident.
        $groupId = Db::insert('user_groups', [
            'name' => 'All monitors',
            'description' => 'Default group. Members can see every monitor shared with it.',
            'source' => 'local',
            'created_by' => $userId,
            'created_at' => $now,
        ]);

        Db::insert('group_user', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'source' => 'local',
            'created_at' => $now,
        ]);

        Db::insert('notification_channels', [
            'name' => 'Email — ' . (string) ($admin['email'] ?? ''),
            'type' => 'email',
            'config' => json_encode(['recipients' => [strtolower(trim((string) ($admin['email'] ?? '')))]]),
            'enabled' => 1,
            'is_default' => 1,
            'created_by' => $userId,
            'created_at' => $now,
        ]);

        Settings::setMany([
            'site_name' => (string) ($site['name'] ?? 'Monitor'),
            'site_url' => rtrim((string) ($site['url'] ?? ''), '/'),
            'default_timezone' => (string) ($site['timezone'] ?? 'UTC'),
            'default_locale' => (string) ($site['locale'] ?? 'en'),
            'mail_from_address' => strtolower(trim((string) ($admin['email'] ?? ''))),
            'mail_from_name' => (string) ($site['name'] ?? 'Monitor'),
            'installed_at' => $now,
            'installed_version' => '1.0.0',
        ]);

        Db::insert('audit_log', [
            'user_id' => $userId,
            'user_label' => (string) ($admin['email'] ?? ''),
            'action' => 'install.completed',
            'entity' => 'system',
            'summary' => 'Monitor installed',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'created_at' => $now,
        ]);
    }

    public function lock(): void
    {
        $file = $this->basePath . '/storage/installed.lock';
        if (file_put_contents($file, gmdate('c') . "\n") === false) {
            throw new RuntimeException('Could not write ' . $file);
        }
        @chmod($file, 0640);
    }

    public function cronLine(): string
    {
        $php = PHP_BINARY !== '' && PHP_SAPI === 'cli' ? PHP_BINARY : '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        return sprintf(
            '* * * * * %s %s/bin/scheduler.php >> %s/storage/logs/scheduler.log 2>&1',
            $php,
            $this->basePath,
            $this->basePath
        );
    }
}
