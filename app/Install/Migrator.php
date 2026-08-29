<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Db;
use RuntimeException;

/**
 * Runs the .sql files in database/migrations once each, in filename order.
 * Deliberately simple: plain SQL files are what a self-hoster can read, diff
 * and restore from a backup without learning a migration DSL.
 */
final class Migrator
{
    public function __construct(private readonly string $path)
    {
    }

    public function ensureTable(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS {{migrations}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(190) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `ran_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int,string> */
    public function available(): array
    {
        $files = glob($this->path . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        return array_map(static fn (string $f): string => basename($f), $files);
    }

    /** @return array<int,string> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = Db::select('SELECT `migration` FROM {{migrations}} ORDER BY `id`');

        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /** @return array<int,string> */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * @return array<int,string> names of the migrations that ran
     */
    public function run(): array
    {
        $this->ensureTable();
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }

        $batch = (int) Db::value('SELECT COALESCE(MAX(`batch`), 0) + 1 FROM {{migrations}}');

        foreach ($pending as $migration) {
            $sql = (string) file_get_contents($this->path . '/' . $migration);
            foreach ($this->statements($sql) as $statement) {
                try {
                    Db::pdo()->exec(Db::expand($statement));
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        sprintf('Migration %s failed: %s', $migration, $e->getMessage()),
                        0,
                        $e
                    );
                }
            }

            Db::insert('migrations', [
                'migration' => $migration,
                'batch' => $batch,
                'ran_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return $pending;
    }

    /** @return array<int,string> */
    private function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (explode(";\n", implode("\n", $lines) . "\n") as $statement) {
            $statement = trim($statement, " \n\r\t;");
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
