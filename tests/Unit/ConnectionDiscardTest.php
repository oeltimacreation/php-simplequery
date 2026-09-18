<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class ConnectionDiscardTest extends TestCase
{
    public function testLocalStateTracksTransactionsCursorsAndClosedWrappers(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertFalse($connection->isClosed());
        self::assertTrue($connection->isReusable());

        $connection->transaction(function (Connection $connection): void {
            self::assertFalse($connection->isReusable());
            $connection->transaction(static function (Connection $nested): void {
                self::assertFalse($nested->isReusable());
            });
        });
        self::assertTrue($connection->isReusable());

        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        self::assertFalse($connection->isReusable());
        $pdo->rollBack();

        $cursor = $connection->query('SELECT 1 AS value')->iterate();
        self::assertFalse($connection->isReusable());
        $cursor->close();
        self::assertTrue($connection->isReusable());
        $connection->close();
        self::assertTrue($connection->isClosed());
        self::assertFalse($connection->isReusable());
        $connection->discard();
        self::assertTrue($connection->isClosed());
    }

    public function testCompilerOnlyAndQuarantinedWrappersAreNotReusable(): void
    {
        $compiler = Connection::forCompilation(Driver::Sqlite);
        self::assertFalse($compiler->isClosed());
        self::assertFalse($compiler->isReusable());
        $compiler->discard();
        self::assertTrue($compiler->isClosed());

        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $connection->quarantine();
        self::assertFalse($connection->isClosed());
        self::assertFalse($connection->isReusable());
        $connection->discard();
        self::assertTrue($connection->isClosed());
    }

    public function testInspectionFailureQuarantinesAndDiscardRequiresNoInspection(): void
    {
        $pdo = new ControlledTransactionPdo();
        $connection = Connection::fromPdo($pdo, Driver::Sqlite, new ConnectionOptions(label: 'worker'));
        $pdo->failTransactionInspection = true;

        try {
            $connection->isReusable();
            self::fail('Inspection failure must remain visible.');
        } catch (TransactionStateException $failure) {
            self::assertSame('is_reusable', $failure->operation);
            self::assertSame('worker', $failure->connectionLabel);
            self::assertTrue($failure->connectionUnusable);
            self::assertInstanceOf(PDOException::class, $failure->controlFailure);
            self::assertSame($failure->controlFailure, $failure->getPrevious());
        }

        self::assertFalse($connection->isReusable());
        try {
            $connection->close();
            self::fail('Strict close still inspects physical transaction state.');
        } catch (PDOException) {
            self::assertFalse($connection->isClosed());
        }
        $connection->discard();
        $connection->discard();
        $connection->close();
        self::assertTrue($connection->isClosed());
        self::assertSame([], $pdo->controlCalls);
    }

    public function testDiscardNeverRebindsStaleArtifactsOrRevokesEscapedPdo(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE records (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO records VALUES (4)');
        $builder = $connection->table('records')->where('id', 4);
        $raw = $connection->query('SELECT ? AS value', [4]);
        $compiled = $builder->compile();
        $model = new class ($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function count(): int
            {
                return $this->connection->table('records')->count();
            }
        };

        $connection->discard();
        $replacement = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertTrue($replacement->isReusable());
        self::assertEquals($compiled, $builder->compile());
        self::assertTrue($pdo->inTransaction());
        $count = $pdo->query('SELECT COUNT(*) FROM records');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(1, (int) $count->fetchColumn());

        foreach (
            [
            static fn () => $builder->get(),
            static fn () => $raw->first(),
            static fn () => $model->count(),
            static fn () => $connection->pdo(),
            static fn () => $connection->query('SELECT 1'),
            static fn () => $connection->transaction(static fn (): int => 1),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Discarded artifacts must not execute.');
            } catch (ConnectionException) {
                self::addToAssertionCount(1);
            }
        }

        $pdo->rollBack();
        $count = $pdo->query('SELECT COUNT(*) FROM records');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
        $replacement->close();
    }

    #[DataProvider('transactionDiscardCases')]
    public function testDiscardedTransactionCannotComplete(bool $nested, bool $throwAfterDiscard): void
    {
        $pdo = new ControlledTransactionPdo();
        $connection = Connection::fromPdo($pdo, Driver::Sqlite);
        $callbackFailure = new \RuntimeException('synthetic callback failure');
        $callsAtDiscard = [];
        $callback = static function (Connection $connection) use (
            $pdo,
            $throwAfterDiscard,
            $callbackFailure,
            &$callsAtDiscard,
        ): void {
            $connection->discard();
            $callsAtDiscard = $pdo->controlCalls;
            if ($throwAfterDiscard) {
                throw $callbackFailure;
            }
        };

        try {
            $connection->transaction(static function (Connection $connection) use ($nested, $callback): void {
                if ($nested) {
                    $connection->transaction($callback);
                } else {
                    $callback($connection);
                }
            });
            self::fail('Discarded callbacks cannot report success.');
        } catch (TransactionStateException $failure) {
            self::assertTrue($failure->connectionUnusable);
            self::assertSame($nested ? 2 : 1, $failure->managedDepth);
            self::assertSame($throwAfterDiscard ? $callbackFailure : null, $failure->callbackFailure);
        }

        self::assertSame($callsAtDiscard, $pdo->controlCalls);
        self::assertTrue($connection->isClosed());
        self::assertFalse($connection->isReusable());
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function transactionDiscardCases(): iterable
    {
        yield 'root return' => [false, false];
        yield 'root throw' => [false, true];
        yield 'nested return' => [true, false];
        yield 'nested throw' => [true, true];
    }

    public function testCaughtNestedDiscardFailureStillBlocksOuterCommit(): void
    {
        $pdo = new ControlledTransactionPdo();
        $connection = Connection::fromPdo($pdo, Driver::Sqlite);
        try {
            $connection->transaction(static function (Connection $connection): void {
                try {
                    $connection->transaction(static function (Connection $nested): void {
                        $nested->discard();
                    });
                } catch (TransactionStateException) {
                }
            });
            self::fail('Catching an inner failure must not permit commit.');
        } catch (TransactionStateException $failure) {
            self::assertTrue($failure->connectionUnusable);
            self::assertNotContains('commit', $pdo->controlCalls);
        } finally {
            $pdo->rollBack();
        }
    }
}
