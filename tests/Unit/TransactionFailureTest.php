<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Closure;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('pdo_sqlite')]
final class TransactionFailureTest extends TestCase
{
    public function testTransactionStateInspectionFailureQuarantinesConnection(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failTransactionInspection = true;

        try {
            $connection->transaction(static fn (): string => 'not-called');
            self::fail('The controlled transaction-state inspection failure unexpectedly succeeded.');
        } catch (TransactionStateException $exception) {
            self::assertSame('begin', $exception->operation);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        $pdo->failTransactionInspection = false;
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testBeginFailureHasMetadataAndDoesNotQuarantineCleanConnection(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failBegin = true;

        try {
            $connection->transaction(static fn (): string => 'not-called');
            self::fail('The controlled begin failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('begin', $exception->operation);
            self::assertSame(0, $exception->managedDepth);
            self::assertSame(Driver::Sqlite, $exception->driver);
            self::assertSame('controlled-transaction', $exception->connectionLabel);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertFalse($exception->connectionUnusable);
        }

        $pdo->failBegin = false;
        self::assertSame(
            1,
            $connection->transaction(
                static fn (Connection $database): mixed => $database->query('SELECT 1 AS recovered')
                    ->firstAssociative()['recovered'] ?? null,
            ),
        );
        $connection->close();
    }

    public function testPostDispatchBeginFailureRollsBackAndAllowsReuseAfterVerifiedRecovery(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failBeginAfterDispatch = true;

        try {
            $connection->transaction(static fn (): string => 'not-called');
            self::fail('The controlled post-dispatch begin failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('begin', $exception->operation);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertNull($exception->recoveryFailure);
            self::assertFalse($exception->connectionUnusable);
        }

        self::assertFalse($pdo->inTransaction());
        $pdo->failBeginAfterDispatch = false;
        self::assertSame(
            1,
            $connection->query('SELECT 1 AS recovered')->firstAssociative()['recovered'] ?? null,
        );
        $connection->close();
    }

    public function testPostDispatchBeginFailureQuarantinesWhenRecoveryCannotBeVerified(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failBeginAfterDispatch = true;
        $pdo->failRollback = true;

        $exception = $this->captureTransactionFailure(
            static fn () => $connection->transaction(static fn (): string => 'not-called'),
            'The unrecoverable post-dispatch begin failure unexpectedly succeeded.',
        );
        self::assertSame('begin', $exception->operation);
        self::assertInstanceOf(PDOException::class, $exception->controlFailure);
        self::assertInstanceOf(PDOException::class, $exception->recoveryFailure);
        self::assertTrue($exception->connectionUnusable);

        $this->assertQuarantined($connection);
        $pdo->failRollback = false;
        $pdo->rollBack();
        $connection->close();
    }

    public function testRootGuardCreationFailureRollsBackAndAllowsReplacementAttempt(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failControlPrefix = 'SAVEPOINT simplequery_root_';

        try {
            $connection->transaction(static fn (): string => 'not-called');
            self::fail('The controlled root guard failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('begin_guard', $exception->operation);
            self::assertNull($exception->recoveryFailure);
            self::assertFalse($exception->connectionUnusable);
        }

        self::assertFalse($pdo->inTransaction());
        $pdo->failControlPrefix = null;
        self::assertSame(
            2,
            $connection->transaction(
                static fn (Connection $database): mixed => $database->query('SELECT 2 AS recovered')
                    ->firstAssociative()['recovered'] ?? null,
            ),
        );
        $connection->close();
    }

    public function testCommitFailureAttemptsRollbackAndQuarantinesConnection(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failCommit = true;

        try {
            $connection->transaction(static fn (): string => 'result');
            self::fail('The controlled commit failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('commit', $exception->operation);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertNull($exception->recoveryFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        self::assertFalse($pdo->inTransaction());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testCommitAndRecoveryRollbackFailuresAreBothRetained(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failCommit = true;
        $pdo->failRollback = true;

        try {
            $connection->transaction(static fn (): string => 'result');
            self::fail('The controlled commit failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertInstanceOf(PDOException::class, $exception->recoveryFailure);
            self::assertSame($exception->recoveryFailure, $exception->getPrevious());
        }

        self::assertTrue($pdo->inTransaction());
        $pdo->failRollback = false;
        $pdo->rollBack();
        $connection->close();
    }

    public function testRecoveryRollbackReturnWithActivePhysicalStateQuarantinesConnection(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failControlPrefix = 'SAVEPOINT simplequery_root_';
        $pdo->pretendRollbackSuccess = true;

        try {
            $connection->transaction(static fn (): string => 'not-called');
            self::fail('The controlled false recovery rollback success unexpectedly passed verification.');
        } catch (TransactionException $exception) {
            self::assertSame('begin_guard', $exception->operation);
            self::assertInstanceOf(RuntimeException::class, $exception->recoveryFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        self::assertTrue($pdo->inTransaction());
        $pdo->pretendRollbackSuccess = false;
        $pdo->rollBack();
        $connection->close();
    }

    public function testCallbackAndRollbackFailuresAreBothRetained(): void
    {
        [$pdo, $connection] = $this->connection();
        $callbackFailure = new RuntimeException('domain failure');
        $pdo->failRollback = true;

        try {
            $connection->transaction(function () use ($callbackFailure): void {
                $this->throwFailure($callbackFailure);
            });
            self::fail('The controlled rollback failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame($callbackFailure, $exception->callbackFailure);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        $pdo->failRollback = false;
        $pdo->rollBack();
        $connection->close();
    }

    public function testRootGuardReleaseFailureDuringRollbackRetainsBothFailures(): void
    {
        [$pdo, $connection] = $this->connection();
        $callbackFailure = new RuntimeException('domain failure before rollback guard');
        $pdo->failControlPrefix = 'RELEASE SAVEPOINT simplequery_root_';

        try {
            $connection->transaction(function () use ($callbackFailure): void {
                $this->throwFailure($callbackFailure);
            });
            self::fail('The controlled rollback guard failure unexpectedly succeeded.');
        } catch (TransactionStateException $exception) {
            self::assertSame('rollback_guard', $exception->operation);
            self::assertSame($callbackFailure, $exception->callbackFailure);
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        $pdo->failControlPrefix = null;
        $pdo->rollBack();
        $connection->close();
    }

    public function testNestedSavepointCreationFailureQuarantinesManagedTransaction(): void
    {
        $this->assertNestedSavepointFailure(afterDispatch: false);
    }

    public function testPostDispatchNestedSavepointFailureQuarantinesManagedTransaction(): void
    {
        $this->assertNestedSavepointFailure(afterDispatch: true);
    }

    public function testNestedReleaseFailureQuarantinesConnection(): void
    {
        $this->assertNestedReleaseFailure();
    }

    private function assertNestedSavepointFailure(bool $afterDispatch): void
    {
        [$pdo, $connection] = $this->connection();
        if ($afterDispatch) {
            $pdo->failControlAfterDispatchPrefix = 'SAVEPOINT simplequery_nested_';
        } else {
            $pdo->failControlPrefix = 'SAVEPOINT simplequery_nested_';
        }

        $exception = $this->captureNestedTransactionFailure(
            $connection,
            'The controlled nested savepoint failure unexpectedly succeeded.',
        );
        self::assertSame('savepoint', $exception->operation);
        self::assertTrue($exception->connectionUnusable);
        if ($afterDispatch) {
            self::assertInstanceOf(PDOException::class, $exception->controlFailure);
            $pdo->failControlAfterDispatchPrefix = null;
        } else {
            $pdo->failControlPrefix = null;
        }

        $this->assertQuarantined($connection);
        $pdo->rollBack();
        $connection->close();
    }

    private function assertNestedReleaseFailure(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failControlPrefix = 'RELEASE SAVEPOINT simplequery_nested_';

        $exception = $this->captureNestedTransactionFailure(
            $connection,
            'The controlled nested release failure unexpectedly succeeded.',
        );
        self::assertSame('release_savepoint', $exception->operation);
        self::assertTrue($exception->connectionUnusable);

        $pdo->failControlPrefix = null;
        $pdo->rollBack();
        $connection->close();
    }

    public function testNestedRollbackFailureRetainsCallbackFailure(): void
    {
        [$pdo, $connection] = $this->connection();
        $callbackFailure = new RuntimeException('inner domain failure');

        try {
            $connection->transaction(function (Connection $database) use ($pdo, $callbackFailure): void {
                $pdo->failControlPrefix = 'ROLLBACK TO SAVEPOINT simplequery_nested_';
                $database->transaction(function () use ($callbackFailure): void {
                    $this->throwFailure($callbackFailure);
                });
            });
            self::fail('The controlled nested rollback failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('rollback_savepoint', $exception->operation);
            self::assertSame($callbackFailure, $exception->callbackFailure);
        }

        $pdo->failControlPrefix = null;
        $pdo->rollBack();
        $connection->close();
    }

    public function testSuccessfulCommitReturnWithActivePhysicalStateIsDetected(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->pretendCommitSuccess = true;

        try {
            $connection->transaction(static fn (): null => null);
            self::fail('The controlled false commit success unexpectedly passed verification.');
        } catch (TransactionStateException $exception) {
            self::assertSame('commit_verify', $exception->operation);
        }

        $pdo->pretendCommitSuccess = false;
        $pdo->rollBack();
        $connection->close();
    }

    public function testSuccessfulRollbackReturnWithActivePhysicalStateIsDetected(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->pretendRollbackSuccess = true;
        $callbackFailure = new RuntimeException('domain failure');

        try {
            $connection->transaction(function () use ($callbackFailure): void {
                $this->throwFailure($callbackFailure);
            });
            self::fail('The controlled false rollback success unexpectedly passed verification.');
        } catch (TransactionStateException $exception) {
            self::assertSame('rollback_verify', $exception->operation);
            self::assertSame($callbackFailure, $exception->callbackFailure);
        }

        $pdo->pretendRollbackSuccess = false;
        $pdo->rollBack();
        $connection->close();
    }

    /** @return array{ControlledTransactionPdo, Connection} */
    private function connection(): array
    {
        $pdo = new ControlledTransactionPdo();

        return [
            $pdo,
            Connection::fromPdo(
                $pdo,
                Driver::Sqlite,
                new ConnectionOptions(label: 'controlled-transaction'),
            ),
        ];
    }

    private function assertQuarantined(Connection $connection): void
    {
        try {
            $connection->query('SELECT 1')->get();
            self::fail('The quarantined connection unexpectedly executed a query.');
        } catch (TransactionStateException $exception) {
            self::assertTrue($exception->connectionUnusable);
        }
    }

    /** @param Closure(): mixed $operation */
    private function captureTransactionFailure(Closure $operation, string $failureMessage): TransactionException
    {
        try {
            $operation();
            self::fail($failureMessage);
        } catch (TransactionException $exception) {
            return $exception;
        }
    }

    private function captureNestedTransactionFailure(
        Connection $connection,
        string $failureMessage,
    ): TransactionException {
        return $this->captureTransactionFailure(
            static function () use ($connection): void {
                $connection->transaction(static function (Connection $database): void {
                    $database->transaction(static fn (): null => null);
                });
            },
            $failureMessage,
        );
    }

    private function throwFailure(\Throwable $failure): void
    {
        throw $failure;
    }
}
