<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\RawQuery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class ConnectionStateMatrixTest extends TestCase
{
    #[DataProvider('stateMatrix')]
    public function testConnectionStateTransitions(StateTransitionCase $case): void
    {
        [$connection, $retained, $cleanup] = ($case->fixtureFactory)();

        $thrown = null;
        try {
            $result = ($case->operation)($connection);
            if ($case->expectation->exception === null) {
                self::assertNotNull($result);
            }
        } catch (\Throwable $exception) {
            $thrown = $exception;
        } finally {
            unset($retained);
            $cleanup();
        }

        $this->assertTransitionOutcome($case, $thrown);
    }

    private function assertTransitionOutcome(StateTransitionCase $case, ?\Throwable $thrown): void
    {
        if ($case->expectation->exception === null) {
            if ($thrown !== null) {
                throw $thrown;
            }

            return;
        }

        self::assertNotNull(
            $thrown,
            sprintf(
                'Expected %s was not thrown for %s on %s.',
                $case->expectation->exception,
                $case->operationLabel,
                $case->stateLabel,
            ),
        );
        self::assertInstanceOf($case->expectation->exception, $thrown);
        $this->assertExceptionProperties($case->expectation, $thrown);
    }

    private function assertExceptionProperties(TransitionExpectation $expectation, \Throwable $thrown): void
    {
        if ($expectation->operation !== null) {
            $operation = match (true) {
                $thrown instanceof TransactionStateException => $thrown->operation,
                $thrown instanceof ExternalTransactionException => $thrown->operation,
                default => null,
            };
            self::assertSame($expectation->operation, $operation);
        }

        if ($expectation->connectionUnusable !== null && $thrown instanceof TransactionStateException) {
            self::assertSame($expectation->connectionUnusable, $thrown->connectionUnusable);
        }
    }

    /**
     * @return iterable<string, array{0: StateTransitionCase}>
     */
    public static function stateMatrix(): iterable
    {
        $states = self::stateConfigurations();
        $operations = self::stateOperations();

        foreach ($states as $stateName => $stateConfig) {
            foreach ($operations as $opName => $operation) {
                $expected = $stateConfig['expectations'][$opName];
                $label = sprintf('%s on %s', $opName, $stateName);
                yield $label => [
                    new StateTransitionCase(
                        stateLabel: $stateName,
                        operationLabel: $opName,
                        fixtureFactory: $stateConfig['factory'],
                        operation: $operation,
                        expectation: new TransitionExpectation(
                            exception: $expected[0] ?? null,
                            operation: $expected[1] ?? null,
                            connectionUnusable: $expected[2] ?? null,
                        ),
                    ),
                ];
            }
        }
    }

    /**
     * @param ?\Closure(Connection): mixed $setup
     * @param ?\Closure(Connection, mixed): void $extraCleanup
     * @return \Closure(): array{Connection, mixed, \Closure(): void}
     */
    private static function createMemoryTableFixture(?\Closure $setup = null, ?\Closure $extraCleanup = null): \Closure
    {
        return static function () use ($setup, $extraCleanup): array {
            $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
            $connection->pdo()->exec('CREATE TABLE items (id INT, name TEXT)');
            $connection->pdo()->exec("INSERT INTO items VALUES (1, 'one')");
            $retained = $setup !== null ? $setup($connection) : null;

            return [$connection, $retained, static function () use ($connection, $retained, $extraCleanup): void {
                if ($extraCleanup !== null) {
                    $extraCleanup($connection, $retained);
                }
                try {
                    $connection->close();
                } catch (\Throwable) {
                }
            }];
        };
    }

    /**
     * @return array<string, array{
     *     factory: \Closure(): array{Connection, mixed, \Closure(): void},
     *     expectations: array<string, array{0?: class-string<\Throwable>|null, 1?: string|null, 2?: bool|null}>
     * }>
     */
    private static function stateConfigurations(): array
    {
        return self::activeStates() + self::terminalStates();
    }

    /**
     * @return array<string, array{
     *     factory: \Closure(): array{Connection, mixed, \Closure(): void},
     *     expectations: array<string, array{0?: class-string<\Throwable>|null, 1?: string|null, 2?: bool|null}>
     * }>
     */
    private static function activeStates(): array
    {
        return [
            'clean' => [
                'factory' => self::createMemoryTableFixture(),
                'expectations' => [
                    'table' => [null],
                    'query' => [null],
                    'pdo' => [null],
                    'execute_query' => [null],
                    'transaction' => [null],
                    'close' => [null],
                    'require_lock' => [TransactionStateException::class, 'lock_query', false],
                ],
            ],
            'active_cursor' => [
                'factory' => self::createMemoryTableFixture(
                    setup: static fn (Connection $c): mixed => $c->query('SELECT * FROM items')->iterateAssociative(),
                    extraCleanup: static function (Connection $_c, mixed $cursor): void {
                        if (is_object($cursor) && method_exists($cursor, 'close')) {
                            $cursor->close();
                        }
                    },
                ),
                'expectations' => [
                    'table' => [null],
                    'query' => [null],
                    'pdo' => [null],
                    'execute_query' => [null],
                    'transaction' => [TransactionStateException::class, 'commit', true],
                    'close' => [TransactionStateException::class, 'close', false],
                    'require_lock' => [TransactionStateException::class, 'lock_query', false],
                ],
            ],
            'external_transaction' => [
                'factory' => self::createMemoryTableFixture(
                    setup: static function (Connection $c): void {
                        $c->pdo()->beginTransaction();
                    },
                    extraCleanup: static function (Connection $c): void {
                        if ($c->pdo()->inTransaction()) {
                            $c->pdo()->rollBack();
                        }
                    },
                ),
                'expectations' => [
                    'table' => [null],
                    'query' => [null],
                    'pdo' => [null],
                    'execute_query' => [null],
                    'transaction' => [ExternalTransactionException::class, 'begin', false],
                    'close' => [TransactionStateException::class, 'close', false],
                    'require_lock' => [null],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     factory: \Closure(): array{Connection, mixed, \Closure(): void},
     *     expectations: array<string, array{0?: class-string<\Throwable>|null, 1?: string|null, 2?: bool|null}>
     * }>
     */
    private static function terminalStates(): array
    {
        return [
            'quarantined' => [
                'factory' => static function (): array {
                    $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
                    $connection->quarantine();
                    return [$connection, null, static function () use ($connection): void {
                        try {
                            $connection->close();
                        } catch (\Throwable) {
                        }
                    }];
                },
                'expectations' => [
                    'table' => [null],
                    'query' => [null],
                    'pdo' => [TransactionStateException::class, 'use_connection', true],
                    'execute_query' => [TransactionStateException::class, 'use_connection', true],
                    'transaction' => [TransactionStateException::class, 'use_connection', true],
                    'close' => [null],
                    'require_lock' => [TransactionStateException::class, 'use_connection', true],
                ],
            ],
            'closed' => [
                'factory' => static function (): array {
                    $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
                    $connection->close();
                    return [
                        $connection,
                        null,
                        static function (): void {
                        },
                    ];
                },
                'expectations' => [
                    'table' => [ConnectionException::class],
                    'query' => [ConnectionException::class],
                    'pdo' => [ConnectionException::class],
                    'execute_query' => [ConnectionException::class],
                    'transaction' => [ConnectionException::class],
                    'close' => [null],
                    'require_lock' => [ConnectionException::class],
                ],
            ],
        ];
    }

    /** @return array<string, \Closure(Connection): mixed> */
    private static function stateOperations(): array
    {
        return [
            'table' => static fn (Connection $c): QueryBuilder => $c->table('items'),
            'query' => static fn (Connection $c): RawQuery => $c->query('SELECT 1'),
            'pdo' => static fn (Connection $c): PDO => $c->pdo(),
            'execute_query' => static fn (Connection $c): mixed => $c->query('SELECT 1 AS val')->firstAssociative(),
            'transaction' => static fn (Connection $c): mixed => $c->transaction(static fn (): int => 42),
            'close' => static function (Connection $c): bool {
                $c->close();
                return true;
            },
            'require_lock' => static function (Connection $c): bool {
                $c->requireTransactionForLock();
                return true;
            },
        ];
    }

    public function testManagedTransactionStateTransitions(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $connection = Connection::fromPdo($pdo, Driver::Sqlite);
        $pdo->exec('CREATE TABLE items (id INT, name TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'one')");

        $connection->transaction(function (Connection $txConn): void {
            self::assertSame(1, $txConn->transactionDepth());
            self::assertSame(['val' => 1], $txConn->query('SELECT 1 AS val')->firstAssociative());

            // Lock query is accepted inside managed transaction
            $txConn->requireTransactionForLock();

            // Nested managed transaction succeeds
            $nested = $txConn->transaction(
                static fn (Connection $c): mixed => $c->query('SELECT 2 AS n')->firstAssociative()['n'] ?? null,
            );
            self::assertSame(2, $nested);

            // Close is rejected during active transaction
            try {
                $txConn->close();
                self::fail('Close must be rejected during managed transaction.');
            } catch (TransactionStateException $exception) {
                self::assertSame('close', $exception->operation);
                self::assertFalse($exception->connectionUnusable);
            }
        });

        // Managed transaction with active cursor blocks nested savepoint completion
        $quarantineException = null;
        try {
            $connection->transaction(function (Connection $txConn) use ($connection): void {
                $cursor = $connection->query('SELECT * FROM items')->iterateAssociative();

                try {
                    $txConn->transaction(static fn (): string => 'cursor-blocked');
                    self::fail('Savepoint release must be blocked by active cursor.');
                } catch (TransactionStateException $exception) {
                    self::assertSame('release_savepoint', $exception->operation);
                    self::assertTrue($exception->connectionUnusable);
                }

                $cursor->close();
            });
        } catch (TransactionStateException $exception) {
            $quarantineException = $exception;
        }

        self::assertNotNull($quarantineException);
        self::assertTrue($quarantineException->connectionUnusable);

        // After the transaction fails, connection is quarantined
        try {
            $connection->query('SELECT 1')->firstAssociative();
            self::fail('Quarantined connection must reject query.');
        } catch (TransactionStateException $exception) {
            self::assertTrue($exception->connectionUnusable);
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $connection->close();
    }
}
