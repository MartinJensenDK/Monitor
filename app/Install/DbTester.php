<?php

declare(strict_types=1);

namespace App\Install;

use PDO;
use PDOException;

final class DbTester
{
    /**
     * @param array<string,mixed> $config
     * @return array{ok:bool,message:string,version:string,tables:int}
     */
    public static function test(array $config): array
    {
        $host = trim((string) ($config['host'] ?? '127.0.0.1'));
        $port = (int) ($config['port'] ?? 3306);
        $database = trim((string) ($config['database'] ?? ''));
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($database === '') {
            return self::fail('Enter the database name.');
        }
        if ($username === '') {
            return self::fail('Enter the database user.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            return self::fail(self::explain($e, $host, $database, $username));
        }

        $version = (string) $pdo->query('SELECT VERSION()')?->fetchColumn();
        if (!self::versionSupported($version)) {
            return self::fail('MySQL 8.0 (or MariaDB 10.6) and newer is required. This server reports ' . $version . '.');
        }

        // The installer creates tables, so prove we may before promising success.
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS `monitor_install_probe` (`id` INT)');
            $pdo->exec('DROP TABLE IF EXISTS `monitor_install_probe`');
        } catch (PDOException $e) {
            return self::fail('Connected, but this user cannot create tables in ' . $database . '. Grant it ALL PRIVILEGES on that database. (' . $e->getMessage() . ')');
        }

        $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')?->fetchColumn();

        return [
            'ok' => true,
            'message' => 'Connected to ' . $database . ' on ' . $host . '.',
            'version' => $version,
            'tables' => $tables,
        ];
    }

    private static function versionSupported(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            preg_match('/(\d+\.\d+\.\d+)/', $version, $m);

            return version_compare($m[1] ?? '0', '10.6.0', '>=');
        }

        preg_match('/(\d+\.\d+\.\d+)/', $version, $m);

        return version_compare($m[1] ?? '0', '8.0.0', '>=');
    }

    /** Turn a PDO error into something a person can act on. */
    private static function explain(PDOException $e, string $host, string $database, string $username): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Access denied')) {
            return sprintf('The password for "%s" was rejected by %s. Check the user name and password.', $username, $host);
        }
        if (str_contains($message, 'Unknown database')) {
            return sprintf('The database "%s" does not exist on %s. Create it first, then try again.', $database, $host);
        }
        if (str_contains($message, 'Connection refused') || str_contains($message, "Can't connect")) {
            return sprintf('No MySQL server answered on %s. Check the host and port, and that the database accepts connections from this server.', $host);
        }
        if (str_contains($message, 'timed out')) {
            return sprintf('%s did not answer within 5 seconds. A firewall between the two servers is the usual cause.', $host);
        }

        return $message;
    }

    /** @return array{ok:bool,message:string,version:string,tables:int} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'version' => '', 'tables' => 0];
    }
}
