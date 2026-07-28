<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use Closure;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\TransactionMode;
use PDOException;
use RuntimeException;
use Throwable;

final class TransactionModeProbe
{
    /** @param Closure(): Connection $connect */
    public function __construct(
        private readonly Driver $driver,
        private readonly Closure $connect,
        private readonly ?string $serverVersion,
    ) {
    }

    /** @return list<array{name: string, status: string, details: array<string, mixed>}> */
    public function run(): array
    {
        if ($this->driver !== Driver::Sqlite) {
            return [$this->probeUnsupportedDriver()];
        }

        return [
            $this->probeSqliteManagedContention(),
            $this->probeSqliteExternalOwnership(),
        ];
    }

    /** @return array{name: string, status: string, details: array<string, mixed>} */
    private function probeUnsupportedDriver(): array
    {
        $connection = $this->connect();
        $callbackCalled = false;
        $failure = null;

        try {
            $connection->transaction(
                function () use (&$callbackCalled): void {
                    $callbackCalled = true;
                },
                TransactionMode::Immediate,
            );
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $this->require(
            $failure instanceof UnsupportedFeatureException,
            'sqlite_immediate_mode_rejected_by_mysql_family',
        );
        $this->require(!$callbackCalled, 'sqlite_immediate_mode_rejected_by_mysql_family');
        $this->require(!$connection->pdo()->inTransaction(), 'sqlite_immediate_mode_rejected_by_mysql_family');
        $connection->close();

        return $this->passed('sqlite_immediate_mode_rejected_by_mysql_family');
    }

    /** @return array{name: string, status: string, details: array<string, mixed>} */
    private function probeSqliteManagedContention(): array
    {
        $writer = $this->connect();
        $contender = $this->connect();
        $contender->pdo()->exec('PRAGMA busy_timeout = 75');
        $beginFailure = null;

        $generatedId = $writer->transaction(
            function (Connection $database) use ($contender, &$beginFailure): string {
                $id = $database->table('simplequery_transaction_probe')->insertGetId([
                    'label' => 'immediate-writer',
                ]);
                $database->transaction(static fn (): null => null);
                $beginFailure = $this->captureBusyBegin($contender);

                return $id;
            },
            TransactionMode::Immediate,
        );
        $controlFailure = $beginFailure?->controlFailure;
        $contenderReusable = $contender->transaction(
            static fn (Connection $database): int => $database
                ->table('simplequery_transaction_probe')
                ->insert(['label' => 'immediate-contender-reused']),
            TransactionMode::Immediate,
        );
        $name = 'sqlite_immediate_mode_and_busy_recovery';
        $this->require($generatedId !== '', $name);
        $this->require($beginFailure instanceof TransactionException, $name);
        $this->require($beginFailure?->connectionUnusable === false, $name);
        $this->require($this->isSqliteBusy($controlFailure), $name);
        $this->require($contenderReusable === 1, $name);
        $this->require(!$writer->pdo()->inTransaction(), $name);
        $this->require(!$contender->pdo()->inTransaction(), $name);
        $writer->close();
        $contender->close();

        return $this->passed($name, [
            'php_version' => PHP_VERSION,
            'sqlite_version' => $this->serverVersion,
            'busy_sqlstate' => $this->errorInfo($controlFailure, 0),
            'busy_driver_code' => $this->errorInfo($controlFailure, 1),
        ]);
    }

    private function captureBusyBegin(Connection $connection): ?TransactionException
    {
        try {
            $connection->transaction(static fn (): null => null, TransactionMode::Immediate);
        } catch (TransactionException $exception) {
            return $exception;
        }

        return null;
    }

    /** @return array{name: string, status: string, details: array<string, mixed>} */
    private function probeSqliteExternalOwnership(): array
    {
        $connection = $this->connect();
        $pdo = $connection->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $failure = null;

        try {
            $connection->transaction(static fn (): null => null);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $wasActive = $pdo->inTransaction();
        $pdo->rollBack();
        $name = 'sqlite_manual_immediate_remains_external';
        $this->require($failure instanceof ExternalTransactionException, $name);
        $this->require($wasActive, $name);
        $this->require(!$pdo->inTransaction(), $name);
        $connection->close();

        return $this->passed($name);
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

    private function require(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException(sprintf('Transaction mode probe assertion failed: %s.', $name));
        }
    }

    /**
     * @param array<string, mixed> $details
     * @return array{name: string, status: string, details: array<string, mixed>}
     */
    private function passed(string $name, array $details = []): array
    {
        return ['name' => $name, 'status' => 'passed', 'details' => $details];
    }
}
