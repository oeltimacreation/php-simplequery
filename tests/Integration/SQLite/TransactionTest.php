<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

#[RequiresPhpExtension('pdo_sqlite')]
final class TransactionTest extends TestCase
{
    private Connection $connection;

    private RecordingQueryObserver $observer;

    #[\Override]
    protected function setUp(): void
    {
        $this->observer = new RecordingQueryObserver();
        $this->connection = Connection::connect(
            Driver::Sqlite,
            'sqlite::memory:',
            observer: $this->observer,
        );
        $this->connection->pdo()->exec(
            'CREATE TABLE ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL UNIQUE)',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        try {
            $this->connection->close();
        } catch (TransactionStateException) {
            // Unusable-state cases intentionally retain a physical transaction.
        }
    }

    public function testOuterSuccessCommitsAndReturnsCallbackValue(): void
    {
        $value = new \stdClass();
        $result = $this->connection->transaction(function (Connection $database) use ($value): \stdClass {
            $database->table('ledger')->insert(['label' => 'committed']);

            return $value;
        });

        self::assertSame($value, $result);
        self::assertSame(1, $this->connection->table('ledger')->count());
        self::assertFalse($this->connection->pdo()->inTransaction());
        self::assertSame(1, $this->observer->executions()[0]->transactionDepth);
    }

    public function testOuterCallbackFailureRollsBackAndRetainsExceptionIdentity(): void
    {
        $failure = new RuntimeException('domain failure');

        try {
            $this->connection->transaction(function (Connection $database) use ($failure): void {
                $database->table('ledger')->insert(['label' => 'rolled-back']);
                $this->throwFailure($failure);
            });
            self::fail('The failing callback unexpectedly returned.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $this->connection->table('ledger')->count());
        self::assertFalse($this->connection->pdo()->inTransaction());
    }

    public function testCallbackTypeErrorAlsoRollsBackUnchanged(): void
    {
        $failure = new TypeError('controlled type error');

        try {
            $this->connection->transaction(function (Connection $database) use ($failure): void {
                $database->table('ledger')->insert(['label' => 'type-error']);
                $this->throwFailure($failure);
            });
            self::fail('The TypeError callback unexpectedly returned.');
        } catch (TypeError $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $this->connection->table('ledger')->count());
    }

    public function testNestedSuccessUsesSavepointAndReportsManagedDepth(): void
    {
        $this->connection->transaction(function (Connection $database): void {
            $database->table('ledger')->insert(['label' => 'outer']);
            $nestedResult = $database->transaction(function (Connection $nested): int {
                $nested->table('ledger')->insert(['label' => 'inner']);

                return $nested->transactionDepth();
            });
            self::assertSame(2, $nestedResult);
            $database->table('ledger')->insert(['label' => 'outer-after']);
        });

        self::assertSame(3, $this->connection->table('ledger')->count());
        self::assertSame(
            [1, 2, 1],
            array_map(
                static fn (QueryExecution $execution): int => $execution->transactionDepth,
                array_slice($this->observer->executions(), 0, 3),
            ),
        );
        self::assertCount(4, $this->observer->executions());
    }

    public function testInnerFailureCanRollBackWhileOuterContinues(): void
    {
        $innerFailure = new RuntimeException('inner domain failure');
        $this->connection->transaction(function (Connection $database) use ($innerFailure): void {
            $database->table('ledger')->insert(['label' => 'outer-before']);
            try {
                $database->transaction(function (Connection $nested) use ($innerFailure): never {
                    $nested->table('ledger')->insert(['label' => 'inner-rollback']);
                    throw $innerFailure;
                });
            } catch (RuntimeException $caught) {
                self::assertSame($innerFailure, $caught);
            }
            $database->table('ledger')->insert(['label' => 'outer-after']);
        });

        self::assertSame(
            ['outer-before', 'outer-after'],
            array_column($this->connection->table('ledger')->orderBy('id')->getAssociative(), 'label'),
        );
    }

    public function testOuterRollbackUndoesSuccessfulInnerScope(): void
    {
        $outerFailure = new RuntimeException('outer failure');
        try {
            $this->connection->transaction(function (Connection $database) use ($outerFailure): never {
                $database->table('ledger')->insert(['label' => 'outer']);
                $database->transaction(function (Connection $nested): void {
                    $nested->table('ledger')->insert(['label' => 'inner-success']);
                });
                throw $outerFailure;
            });
        } catch (RuntimeException $caught) {
            self::assertSame($outerFailure, $caught);
        }

        self::assertSame(0, $this->connection->table('ledger')->count());
    }

    public function testExternalTransactionIsRejectedWithoutBeingCompleted(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        $this->connection->table('ledger')->insert(['label' => 'external']);

        try {
            $this->connection->transaction(static fn (): string => 'never-called');
            self::fail('An external transaction was unexpectedly adopted.');
        } catch (ExternalTransactionException $exception) {
            self::assertSame('begin', $exception->operation);
            self::assertFalse($exception->connectionUnusable);
        }

        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertSame(0, $this->connection->table('ledger')->count());
    }

    public function testManualCommitInsideCallbackIsDetectedAndConnectionIsQuarantined(): void
    {
        $builder = $this->connection->table('ledger')->where('label', 'manual-commit');
        try {
            $this->connection->transaction(function (Connection $database): void {
                $database->table('ledger')->insert(['label' => 'manual-commit']);
                $database->pdo()->commit();
            });
            self::fail('Manual commit state loss was not detected.');
        } catch (TransactionStateException $exception) {
            self::assertSame('commit', $exception->operation);
            self::assertTrue($exception->connectionUnusable);
        }

        self::assertStringContainsString('SELECT', $builder->compile()->sql);
        $this->assertConnectionIsQuarantined();
        $this->connection->close();
    }

    public function testManualRollbackInsideCallbackIsDetectedAndConnectionIsQuarantined(): void
    {
        try {
            $this->connection->transaction(function (Connection $database): void {
                $database->table('ledger')->insert(['label' => 'manual-rollback']);
                $database->pdo()->rollBack();
            });
            self::fail('Manual rollback state loss was not detected.');
        } catch (TransactionStateException $exception) {
            self::assertSame('commit', $exception->operation);
        }

        $this->assertConnectionIsQuarantined();
        $this->connection->close();
    }

    public function testOwnershipGuardDoesNotRollbackAReplacementPhysicalTransaction(): void
    {
        $pdo = $this->connection->pdo();
        try {
            $this->connection->transaction(function (Connection $database) use ($pdo): void {
                $database->table('ledger')->insert(['label' => 'first-physical']);
                $pdo->commit();
                $pdo->beginTransaction();
                $pdo->exec("INSERT INTO ledger (label) VALUES ('replacement-physical')");
            });
            self::fail('Replacement physical transaction was not detected.');
        } catch (TransactionStateException $exception) {
            self::assertSame('commit_guard', $exception->operation);
        }

        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $statement = $pdo->query('SELECT label FROM ledger ORDER BY id');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['first-physical'], $rows);
        $this->connection->close();
    }

    public function testLiveCursorBlocksOuterCommitWithoutBeingForceClosed(): void
    {
        $cursor = null;
        try {
            $this->connection->transaction(function (Connection $database) use (&$cursor): void {
                $database->table('ledger')->insert(['label' => 'cursor-commit']);
                $cursor = $database->table('ledger')->iterateAssociative();
            });
            self::fail('A live cursor unexpectedly crossed commit.');
        } catch (TransactionStateException $exception) {
            self::assertSame('commit', $exception->operation);
        }

        self::assertInstanceOf(Cursor::class, $cursor);
        self::assertFalse($cursor->isClosed());
        $cursor->close();
        $this->assertConnectionIsQuarantined();
    }

    public function testLiveCursorBlocksOuterRollbackAndRetainsBothFailures(): void
    {
        $callbackFailure = new RuntimeException('callback failure');
        $cursor = null;
        try {
            $this->connection->transaction(function (Connection $database) use (&$cursor, $callbackFailure): void {
                $database->table('ledger')->insert(['label' => 'cursor-rollback']);
                $cursor = $database->table('ledger')->iterate();
                $this->throwFailure($callbackFailure);
            });
            self::fail('A live cursor unexpectedly crossed rollback.');
        } catch (TransactionException $exception) {
            self::assertSame($callbackFailure, $exception->callbackFailure);
            self::assertInstanceOf(TransactionStateException::class, $exception->controlFailure);
            self::assertTrue($exception->connectionUnusable);
        }

        self::assertInstanceOf(Cursor::class, $cursor);
        self::assertFalse($cursor->isClosed());
        $cursor->close();
        $this->assertConnectionIsQuarantined();
    }

    public function testLiveCursorBlocksNestedReleaseAndQuarantinesWholeConnection(): void
    {
        $cursor = null;
        try {
            $this->connection->transaction(function (Connection $database) use (&$cursor): void {
                $database->table('ledger')->insert(['label' => 'outer']);
                $database->transaction(function (Connection $nested) use (&$cursor): void {
                    $cursor = $nested->table('ledger')->iterate();
                });
            });
            self::fail('A live cursor unexpectedly crossed savepoint release.');
        } catch (TransactionStateException $exception) {
            self::assertSame('release_savepoint', $exception->operation);
        }

        self::assertInstanceOf(Cursor::class, $cursor);
        $cursor->close();
        $this->assertConnectionIsQuarantined();
    }

    public function testLiveCursorBlocksNestedRollbackAndRetainsCallbackFailure(): void
    {
        $callbackFailure = new RuntimeException('nested callback failure');
        $cursor = null;
        try {
            $this->connection->transaction(function (Connection $database) use (&$cursor, $callbackFailure): void {
                $database->transaction(
                    function (Connection $nested) use (&$cursor, $callbackFailure): void {
                        $cursor = $nested->table('ledger')->iterate();
                        $this->throwFailure($callbackFailure);
                    },
                );
            });
            self::fail('A live cursor unexpectedly crossed savepoint rollback.');
        } catch (TransactionException $exception) {
            self::assertSame($callbackFailure, $exception->callbackFailure);
            self::assertSame('rollback_savepoint', $exception->operation);
        }

        self::assertInstanceOf(Cursor::class, $cursor);
        $cursor->close();
        $this->assertConnectionIsQuarantined();
    }

    private function assertConnectionIsQuarantined(): void
    {
        foreach (
            [
                fn (): mixed => $this->connection->table('ledger')->count(),
                fn (): mixed => $this->connection->transaction(static fn (): null => null),
                fn (): mixed => $this->connection->pdo(),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('An unusable connection operation unexpectedly succeeded.');
            } catch (TransactionStateException $exception) {
                self::assertTrue($exception->connectionUnusable);
            }
        }

        $replacement = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertSame(1, $replacement->query('SELECT 1 AS value')->firstAssociative()['value'] ?? null);
        $replacement->close();
    }

    private function throwFailure(\Throwable $failure): void
    {
        throw $failure;
    }
}
