<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use Closure;
use Oeltima\SimpleQuery\Connection;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class SqliteImmediateProbe
{
    /** @param Closure(): Connection $connect */
    public function __construct(
        private readonly Closure $connect,
        private readonly ?string $serverVersion,
    ) {
    }

    /** @return array{name: string, status: string, details: array<string, mixed>} */
    public function run(): array
    {
        $writer = $this->connect();
        $contender = $this->connect();
        $contender->pdo()->exec('PRAGMA busy_timeout = 75');
        $writerPdo = $writer->pdo();
        $writerPdo->exec('BEGIN IMMEDIATE');
        $writerTracked = $writerPdo->inTransaction();

        $writerPdo->exec('SAVEPOINT simplequery_immediate_probe');
        $writer->table('simplequery_transaction_probe')->insert(['label' => 'immediate-rolled-back']);
        $writerPdo->exec('ROLLBACK TO SAVEPOINT simplequery_immediate_probe');
        $writerPdo->exec('RELEASE SAVEPOINT simplequery_immediate_probe');
        $busyFailure = $this->captureBusyBegin($contender->pdo());
        $rollbackMechanism = $this->rollback($writerPdo);

        $contenderPdo = $contender->pdo();
        $contenderPdo->exec('BEGIN IMMEDIATE');
        $contenderTracked = $contenderPdo->inTransaction();
        $contender->table('simplequery_transaction_probe')->insert(['label' => 'immediate-committed']);
        $commitMechanism = $this->commit($contenderPdo);

        $name = 'sqlite_immediate_external_ownership_characterized';
        $this->require($this->isSqliteBusy($busyFailure), $name);
        $this->require(!$writerPdo->inTransaction(), $name);
        $this->require(!$contenderPdo->inTransaction(), $name);
        $this->require(
            $contender->table('simplequery_transaction_probe')->where('label', 'immediate-rolled-back')->count() === 0,
            $name,
        );
        $this->require(
            $contender->table('simplequery_transaction_probe')->where('label', 'immediate-committed')->count() === 1,
            $name,
        );
        $writer->close();
        $contender->close();

        return [
            'name' => $name,
            'status' => 'passed',
            'details' => [
                'php_version' => PHP_VERSION,
                'sqlite_version' => $this->serverVersion,
                'pdo_tracked_begin' => $writerTracked,
                'pdo_tracked_reuse_begin' => $contenderTracked,
                'rollback_mechanism' => $rollbackMechanism,
                'commit_mechanism' => $commitMechanism,
                'busy_sqlstate' => $this->errorInfo($busyFailure, 0),
                'busy_driver_code' => $this->errorInfo($busyFailure, 1),
            ],
        ];
    }

    private function captureBusyBegin(PDO $pdo): ?PDOException
    {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (PDOException $exception) {
            return $exception;
        }

        return null;
    }

    private function rollback(PDO $pdo): string
    {
        if ($pdo->inTransaction()) {
            $this->require($pdo->rollBack(), 'sqlite_immediate_pdo_rollback');

            return 'pdo';
        }

        $this->executeControlSql($pdo, 'ROLLBACK');

        return 'control_sql';
    }

    private function commit(PDO $pdo): string
    {
        if ($pdo->inTransaction()) {
            $this->require($pdo->commit(), 'sqlite_immediate_pdo_commit');

            return 'pdo';
        }

        $this->executeControlSql($pdo, 'COMMIT');

        return 'control_sql';
    }

    private function connect(): Connection
    {
        return ($this->connect)();
    }

    private function isSqliteBusy(?Throwable $failure): bool
    {
        if (!$failure instanceof PDOException) {
            return false;
        }
        if ($this->errorInfo($failure, 0) !== 'HY000') {
            return false;
        }

        return $this->errorInfo($failure, 1) === 5;
    }

    private function errorInfo(?Throwable $failure, int $offset): mixed
    {
        return $failure instanceof PDOException ? ($failure->errorInfo[$offset] ?? null) : null;
    }

    private function executeControlSql(PDO $pdo, string $sql): void
    {
        $this->require($pdo->exec($sql) !== false, 'sqlite_immediate_' . strtolower($sql));
    }

    private function require(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException(sprintf('SQLite immediate probe assertion failed: %s.', $name));
        }
    }
}
