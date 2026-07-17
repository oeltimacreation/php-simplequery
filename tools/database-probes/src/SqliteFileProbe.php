<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class SqliteFileProbe
{
    /** @return array<string, mixed> */
    public function run(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'simplequery-sqlite-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary SQLite fixture.');
        }

        $first = $this->connect($path);
        $second = $this->connect($path);

        try {
            $first->exec('PRAGMA foreign_keys = ON');
            $first->exec('PRAGMA busy_timeout = 75');
            $second->exec('PRAGMA busy_timeout = 75');
            $journalMode = $this->query($first, 'PRAGMA journal_mode = WAL')->fetchColumn();
            $first->exec('CREATE TABLE values_fixture (id INTEGER PRIMARY KEY, numeric_value INTEGER)');
            $first->exec("INSERT INTO values_fixture VALUES (1, 'not-an-integer')");
            $affinityValue = $this->query($first, 'SELECT numeric_value FROM values_fixture')->fetchColumn();

            $strictRejectedText = false;
            $first->exec('CREATE TABLE strict_fixture (numeric_value INTEGER) STRICT');
            try {
                $first->exec("INSERT INTO strict_fixture VALUES ('not-an-integer')");
            } catch (PDOException) {
                $strictRejectedText = true;
            }

            $first->beginTransaction();
            $first->exec("INSERT INTO values_fixture VALUES (2, 2)");
            $busyStarted = hrtime(true);
            $busySqlState = null;
            try {
                $second->exec("INSERT INTO values_fixture VALUES (3, 3)");
            } catch (PDOException $exception) {
                $busySqlState = isset($exception->errorInfo[0])
                    ? (string) $exception->errorInfo[0]
                    : (string) $exception->getCode();
            }
            $busyElapsedMilliseconds = (hrtime(true) - $busyStarted) / 1_000_000;
            $first->rollBack();

            return [
                'journal_mode' => $journalMode,
                'foreign_keys_first_connection' => $this->query($first, 'PRAGMA foreign_keys')->fetchColumn(),
                'foreign_keys_second_connection' => $this->query($second, 'PRAGMA foreign_keys')->fetchColumn(),
                'ordinary_table_affinity_value' => $affinityValue,
                'ordinary_table_affinity_type' => get_debug_type($affinityValue),
                'strict_table_rejected_text' => $strictRejectedText,
                'contention_sqlstate' => $busySqlState,
                'contention_wait_ms' => round($busyElapsedMilliseconds, 3),
                'writer_serialization_observed' => $busySqlState !== null,
            ];
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }

            unset($first, $second);
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($path . '-shm')) {
                unlink($path . '-shm');
            }
            if (is_file($path . '-wal')) {
                unlink($path . '-wal');
            }
        }
    }

    private function connect(string $path): PDO
    {
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new PDOException('The SQLite fixture query did not return a statement.');
        }

        return $statement;
    }
}
