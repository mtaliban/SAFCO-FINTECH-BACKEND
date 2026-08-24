<?php

namespace App\Services\Backup;

/**
 * Fallback pure-PHP MySQL dumper — used when mysqldump binary isn't in the
 * container. Produces a valid SQL file that psql/mysql can restore.
 *
 * Not as fast as mysqldump but works everywhere. Handles:
 *  - CREATE TABLE (with keys, indexes)
 *  - INSERT rows in chunks of 500 to keep memory bounded
 *  - Proper escaping (uses PDO::quote)
 *  - Output is streamed straight into gzip so RAM stays flat even for big DBs
 */
class PhpMysqlDumper
{
    public function __construct(private readonly array $cfg) {}

    public function dumpToGzFile(string $absolutePath): void
    {
        $gz = gzopen($absolutePath, 'wb9');
        if ($gz === false) throw new \RuntimeException('Cannot open gz target for write');

        try {
            $pdo = new \PDO(
                "mysql:host={$this->cfg['host']};port={$this->cfg['port']};dbname={$this->cfg['database']}",
                $this->cfg['username'],
                $this->cfg['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            gzwrite($gz, "-- SAFCO FINTECH LMS backup\n");
            gzwrite($gz, '-- Generated ' . date('c') . "\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $this->dumpTable($pdo, $table, $gz);
            }

            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }
    }

    private function dumpTable(\PDO $pdo, string $table, $gz): void
    {
        gzwrite($gz, "\n-- ─── {$table} ───\n");
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");

        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
        $ddl = $create['Create Table'] ?? $create['Create View'] ?? null;
        if (!$ddl) return;
        gzwrite($gz, $ddl . ";\n\n");

        // Skip data for _view_ tables
        if (str_starts_with($ddl, 'CREATE VIEW') || str_starts_with($ddl, 'CREATE ALGORITHM')) return;

        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($count === 0) return;

        $chunk = 500;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) break;

            $columns = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $values = [];
            foreach ($rows as $row) {
                $escaped = array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($row),
                );
                $values[] = '(' . implode(',', $escaped) . ')';
            }
            gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
        }
        gzwrite($gz, "\n");
    }
}
