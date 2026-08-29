<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. SQL is written with {{table}} placeholders so an optional
 * table prefix stays invisible to callers, and so a database name containing a
 * hyphen (monitor-db) never needs manual quoting.
 */
final class Db
{
    private static ?PDO $pdo = null;

    private static string $prefix = '';

    /** @param array<string,mixed> $config */
    public static function connect(array $config): PDO
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $name = (string) ($config['database'] ?? '');
        $socket = (string) ($config['socket'] ?? '');
        self::$prefix = (string) ($config['prefix'] ?? '');

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        self::$pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        self::$pdo->exec("SET time_zone = '+00:00'");
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        return self::$pdo;
    }

    public static function isConnected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('No database connection. Run the installer first.');
        }

        return self::$pdo;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /** Expand {{table}} placeholders into quoted, prefixed identifiers. */
    public static function expand(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z0-9_]+)\}\}/i',
            static fn (array $m): string => '`' . self::$prefix . $m[1] . '`',
            $sql
        );
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare(self::expand($sql));
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO {{%s}} (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        self::run($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = '`' . $column . '` = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $column . '` = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        $sql = sprintf('UPDATE {{%s}} SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions));

        return self::execute($sql, $params);
    }

    /** @param array<string,mixed> $where */
    public static function delete(string $table, array $where): int
    {
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $column . '` = :' . $column;
        }

        return self::execute(
            sprintf('DELETE FROM {{%s}} WHERE %s', $table, implode(' AND ', $conditions)),
            $where
        );
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Advisory lock used by the scheduler so two cron runs never overlap.
     */
    public static function tryLock(string $name, int $timeout = 0): bool
    {
        return (bool) self::value('SELECT GET_LOCK(?, ?)', [$name, $timeout]);
    }

    public static function releaseLock(string $name): void
    {
        self::value('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public static function tableExists(string $table): bool
    {
        $full = self::$prefix . $table;
        $row = self::selectOne('SHOW TABLES LIKE ?', [$full]);

        return $row !== null;
    }
}
