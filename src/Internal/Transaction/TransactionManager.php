<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Transaction;

use Closure;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDO;
use RuntimeException;
use Throwable;

/** @internal */
final class TransactionManager
{
    private int $depth = 0;

    private int $savepointCounter = 0;

    private bool $ownsPhysicalTransaction = false;

    private bool $unusable = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $this->assertUsable();
        $pdo = $this->connection->pdoForExecution();

        return $this->depth === 0
            ? $this->runOuter($pdo, $callback)
            : $this->runNested($pdo, $callback);
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function assertUsable(): void
    {
        if ($this->unusable) {
            throw new TransactionStateException(
                'The connection transaction state is unusable and must be discarded.',
                operation: 'use_connection',
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                connectionUnusable: true,
            );
        }
    }

    /** @internal */
    public function quarantine(): void
    {
        $this->markUnusable();
    }

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    private function runOuter(PDO $pdo, Closure $callback): mixed
    {
        if ($this->physicalTransactionActive($pdo, 'begin')) {
            throw new ExternalTransactionException(
                'SimpleQuery cannot adopt an externally started transaction.',
                operation: 'begin',
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
            );
        }

        try {
            $this->begin($pdo);
        } catch (Throwable $failure) {
            $recoveryFailure = $this->tryPhysicalRollback($pdo);
            if ($recoveryFailure !== null) {
                $this->markUnusable();
            } else {
                $this->resetManagedState();
            }

            throw new TransactionException(
                'Could not begin the managed transaction.',
                operation: 'begin',
                managedDepth: 0,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                controlFailure: $failure,
                recoveryFailure: $recoveryFailure,
                connectionUnusable: $recoveryFailure !== null,
            );
        }

        $guard = $this->nextSavepoint('root');
        try {
            $this->savepoint($pdo, $guard);
        } catch (Throwable $failure) {
            $recoveryFailure = $this->tryPhysicalRollback($pdo);
            if ($recoveryFailure !== null) {
                $this->markUnusable();
            } else {
                $this->resetManagedState();
            }

            throw new TransactionException(
                'Could not establish the managed transaction ownership guard.',
                operation: 'begin_guard',
                managedDepth: 0,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                controlFailure: $failure,
                recoveryFailure: $recoveryFailure,
                connectionUnusable: $recoveryFailure !== null,
            );
        }

        $this->depth = 1;
        $this->ownsPhysicalTransaction = true;

        try {
            $result = $callback($this->connection);
        } catch (Throwable $callbackFailure) {
            $this->completeOuterFailure($pdo, $guard, $callbackFailure);
        }

        $this->completeOuterSuccess($pdo, $guard);

        return $result;
    }

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    private function runNested(PDO $pdo, Closure $callback): mixed
    {
        $parentDepth = $this->depth;
        $scopeDepth = $parentDepth + 1;
        $this->requireOwnedPhysicalTransaction($pdo, 'savepoint', $scopeDepth);
        $savepoint = $this->nextSavepoint('nested');

        try {
            $this->savepoint($pdo, $savepoint);
        } catch (Throwable $failure) {
            $this->markUnusable();

            throw new TransactionException(
                'Could not create a nested transaction savepoint.',
                operation: 'savepoint',
                managedDepth: $scopeDepth,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                controlFailure: $failure,
                connectionUnusable: true,
            );
        }

        $this->depth = $scopeDepth;
        try {
            $result = $callback($this->connection);
        } catch (Throwable $callbackFailure) {
            $this->completeNestedFailure($pdo, $savepoint, $parentDepth, $callbackFailure);
        }

        $this->completeNestedSuccess($pdo, $savepoint, $parentDepth);

        return $result;
    }

    private function completeOuterSuccess(PDO $pdo, string $guard): void
    {
        $scopeDepth = $this->depth;
        $this->rejectActiveCursor('commit', $scopeDepth, null);
        $this->requireOwnedPhysicalTransaction($pdo, 'commit', $scopeDepth);

        try {
            $this->releaseSavepoint($pdo, $guard);
        } catch (Throwable $failure) {
            $exception = $this->stateException(
                'The managed transaction ownership guard was lost before commit.',
                'commit_guard',
                $scopeDepth,
                null,
                $failure,
            );
            $this->markUnusable();

            throw $exception;
        }

        try {
            $this->commit($pdo);
        } catch (Throwable $failure) {
            $recoveryFailure = $this->tryPhysicalRollback($pdo);
            $this->markUnusable();

            throw new TransactionException(
                'Managed transaction commit failed; its outcome must be treated as uncertain.',
                operation: 'commit',
                managedDepth: $scopeDepth,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                controlFailure: $failure,
                recoveryFailure: $recoveryFailure,
                connectionUnusable: true,
            );
        }

        if ($this->physicalTransactionActive($pdo, 'commit_verify')) {
            $exception = $this->stateException(
                'PDO still reports an active transaction after commit.',
                'commit_verify',
                $scopeDepth,
            );
            $this->markUnusable();

            throw $exception;
        }

        $this->resetManagedState();
    }

    private function completeOuterFailure(PDO $pdo, string $guard, Throwable $callbackFailure): never
    {
        $scopeDepth = $this->depth;
        if ($this->unusable) {
            $this->resetLogicalDepthOnly();

            throw $callbackFailure;
        }

        $this->rejectActiveCursor('rollback', $scopeDepth, $callbackFailure);
        $this->requireOwnedPhysicalTransaction($pdo, 'rollback', $scopeDepth, $callbackFailure);

        try {
            $this->releaseSavepoint($pdo, $guard);
        } catch (Throwable $failure) {
            $exception = $this->stateException(
                'The managed transaction ownership guard was lost before rollback.',
                'rollback_guard',
                $scopeDepth,
                $callbackFailure,
                $failure,
            );
            $this->markUnusable();

            throw $exception;
        }

        try {
            $this->rollBack($pdo);
        } catch (Throwable $failure) {
            $this->markUnusable();

            throw new TransactionException(
                'Managed transaction rollback failed.',
                operation: 'rollback',
                managedDepth: $scopeDepth,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                callbackFailure: $callbackFailure,
                controlFailure: $failure,
                connectionUnusable: true,
            );
        }

        if ($this->physicalTransactionActive($pdo, 'rollback_verify')) {
            $exception = $this->stateException(
                'PDO still reports an active transaction after rollback.',
                'rollback_verify',
                $scopeDepth,
                $callbackFailure,
            );
            $this->markUnusable();

            throw $exception;
        }

        $this->resetManagedState();

        throw $callbackFailure;
    }

    private function completeNestedSuccess(PDO $pdo, string $savepoint, int $parentDepth): void
    {
        $scopeDepth = $this->depth;
        $this->rejectActiveCursor('release_savepoint', $scopeDepth, null);
        $this->requireOwnedPhysicalTransaction($pdo, 'release_savepoint', $scopeDepth);

        try {
            $this->releaseSavepoint($pdo, $savepoint);
        } catch (Throwable $failure) {
            $this->markUnusable();

            throw new TransactionException(
                'Could not release the nested transaction savepoint.',
                operation: 'release_savepoint',
                managedDepth: $scopeDepth,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                controlFailure: $failure,
                connectionUnusable: true,
            );
        }

        $this->depth = $parentDepth;
    }

    private function completeNestedFailure(
        PDO $pdo,
        string $savepoint,
        int $parentDepth,
        Throwable $callbackFailure,
    ): never {
        $scopeDepth = $this->depth;
        if ($this->unusable) {
            $this->resetLogicalDepthOnly();

            throw $callbackFailure;
        }

        $this->rejectActiveCursor('rollback_savepoint', $scopeDepth, $callbackFailure);
        $this->requireOwnedPhysicalTransaction($pdo, 'rollback_savepoint', $scopeDepth, $callbackFailure);

        try {
            $this->rollbackToSavepoint($pdo, $savepoint);
            $this->releaseSavepoint($pdo, $savepoint);
        } catch (Throwable $failure) {
            $this->markUnusable();

            throw new TransactionException(
                'Could not roll back the nested transaction savepoint.',
                operation: 'rollback_savepoint',
                managedDepth: $scopeDepth,
                driver: $this->connection->driver(),
                connectionLabel: $this->connection->connectionOptions()->label,
                callbackFailure: $callbackFailure,
                controlFailure: $failure,
                connectionUnusable: true,
            );
        }

        $this->depth = $parentDepth;

        throw $callbackFailure;
    }

    private function rejectActiveCursor(
        string $operation,
        int $scopeDepth,
        ?Throwable $callbackFailure,
    ): void {
        if (!$this->connection->hasActiveCursors()) {
            return;
        }

        $stateFailure = $this->stateException(
            'A live cursor blocks transaction or savepoint completion.',
            $operation,
            $scopeDepth,
        );
        $this->markUnusable();
        if ($callbackFailure === null) {
            throw $stateFailure;
        }

        throw new TransactionException(
            'A live cursor blocked rollback after the transaction callback failed.',
            operation: $operation,
            managedDepth: $scopeDepth,
            driver: $this->connection->driver(),
            connectionLabel: $this->connection->connectionOptions()->label,
            callbackFailure: $callbackFailure,
            controlFailure: $stateFailure,
            connectionUnusable: true,
        );
    }

    private function requireOwnedPhysicalTransaction(
        PDO $pdo,
        string $operation,
        int $scopeDepth,
        ?Throwable $callbackFailure = null,
    ): void {
        if ($this->ownsPhysicalTransaction && $this->physicalTransactionActive($pdo, $operation)) {
            return;
        }

        $exception = $this->stateException(
            'The managed physical transaction is no longer active.',
            $operation,
            $scopeDepth,
            $callbackFailure,
        );
        $this->markUnusable();

        throw $exception;
    }

    private function physicalTransactionActive(PDO $pdo, string $operation): bool
    {
        try {
            return $pdo->inTransaction();
        } catch (Throwable $failure) {
            $exception = $this->stateException(
                'PDO transaction state could not be inspected.',
                $operation,
                $this->depth,
                null,
                $failure,
            );
            $this->markUnusable();

            throw $exception;
        }
    }

    private function begin(PDO $pdo): void
    {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('PDO returned false while beginning a transaction.');
        }
    }

    private function commit(PDO $pdo): void
    {
        if (!$pdo->commit()) {
            throw new RuntimeException('PDO returned false while committing a transaction.');
        }
    }

    private function rollBack(PDO $pdo): void
    {
        if (!$pdo->rollBack()) {
            throw new RuntimeException('PDO returned false while rolling back a transaction.');
        }
    }

    private function savepoint(PDO $pdo, string $savepoint): void
    {
        $this->executeControlSql($pdo, 'SAVEPOINT ' . $savepoint);
    }

    private function releaseSavepoint(PDO $pdo, string $savepoint): void
    {
        $this->executeControlSql($pdo, 'RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackToSavepoint(PDO $pdo, string $savepoint): void
    {
        $this->executeControlSql($pdo, 'ROLLBACK TO SAVEPOINT ' . $savepoint);
    }

    private function executeControlSql(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new RuntimeException('PDO returned false while executing transaction control SQL.');
        }
    }

    private function tryPhysicalRollback(PDO $pdo): ?Throwable
    {
        try {
            if (!$pdo->inTransaction()) {
                return null;
            }
            $this->rollBack($pdo);
            if ($pdo->inTransaction()) {
                return new RuntimeException('PDO still reports an active transaction after recovery rollback.');
            }

            return null;
        } catch (Throwable $failure) {
            return $failure;
        }
    }

    private function stateException(
        string $message,
        string $operation,
        int $scopeDepth,
        ?Throwable $callbackFailure = null,
        ?Throwable $controlFailure = null,
    ): TransactionStateException {
        return new TransactionStateException(
            $message,
            operation: $operation,
            managedDepth: $scopeDepth,
            driver: $this->connection->driver(),
            connectionLabel: $this->connection->connectionOptions()->label,
            callbackFailure: $callbackFailure,
            controlFailure: $controlFailure,
            connectionUnusable: true,
        );
    }

    private function nextSavepoint(string $scope): string
    {
        ++$this->savepointCounter;

        return sprintf('simplequery_%s_%d', $scope, $this->savepointCounter);
    }

    private function markUnusable(): void
    {
        $this->unusable = true;
        $this->resetLogicalDepthOnly();
    }

    private function resetManagedState(): void
    {
        $this->depth = 0;
        $this->ownsPhysicalTransaction = false;
    }

    private function resetLogicalDepthOnly(): void
    {
        $this->resetManagedState();
    }
}
