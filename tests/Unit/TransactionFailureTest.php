<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

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

    public function testNestedSavepointCreationFailureCanBeCaughtByOuterCallback(): void
    {
        [$pdo, $connection] = $this->connection();

        $result = $connection->transaction(function (Connection $database) use ($pdo): int {
            $pdo->failControlPrefix = 'SAVEPOINT simplequery_nested_';
            try {
                $database->transaction(static fn (): null => null);
                self::fail('The controlled nested savepoint failure unexpectedly succeeded.');
            } catch (TransactionException $exception) {
                self::assertSame('savepoint', $exception->operation);
                self::assertFalse($exception->connectionUnusable);
            }
            $pdo->failControlPrefix = null;

            return $database->transactionDepth();
        });

        self::assertSame(1, $result);
        $connection->close();
    }

    public function testNestedReleaseFailureQuarantinesConnection(): void
    {
        [$pdo, $connection] = $this->connection();

        try {
            $connection->transaction(function (Connection $database) use ($pdo): void {
                $pdo->failControlPrefix = 'RELEASE SAVEPOINT simplequery_nested_';
                $database->transaction(static fn (): null => null);
            });
            self::fail('The controlled nested release failure unexpectedly succeeded.');
        } catch (TransactionException $exception) {
            self::assertSame('release_savepoint', $exception->operation);
            self::assertTrue($exception->connectionUnusable);
        }

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

    private function throwFailure(\Throwable $failure): void
    {
        throw $failure;
    }
}
