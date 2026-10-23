<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class CursorHandoffTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ConfigurableStatement::reset();
    }

    #[DataProvider('failedHandoffs')]
    public function testFailedHandoffClosesOnceAndPreservesPrimaryFailure(
        bool $builder,
        bool $associative,
        string $stage,
        string $cleanup,
    ): void {
        [$connection, $observer] = $this->connection();
        ConfigurableStatement::$bindReturnsFalse = $stage === 'bind-false';
        ConfigurableStatement::$bindThrows = $stage === 'bind-throws';
        ConfigurableStatement::$executeReturnsFalse = $stage === 'execute-false';
        ConfigurableStatement::$executeThrows = $stage === 'execute-throws';
        ConfigurableStatement::$closeReturnsFalse = $cleanup === 'false';
        ConfigurableStatement::$closeThrows = $cleanup === 'throws';
        $query = $builder
            ? $connection->table('items')->where('value', 'synthetic-private-value')
            : $connection->query('SELECT ? AS value', ['synthetic-private-value']);

        try {
            $associative ? $query->iterateAssociative() : $query->iterate();
            self::fail('A failed cursor attempt unexpectedly succeeded.');
        } catch (QueryExecutionException $failure) {
            if (str_ends_with($stage, 'throws')) {
                self::assertInstanceOf(PDOException::class, $failure->getPrevious());
                self::assertSame(
                    str_starts_with($stage, 'bind')
                        ? 'Controlled statement bind failure.'
                        : 'Controlled statement execute failure.',
                    $failure->getPrevious()->getMessage(),
                );
            } else {
                self::assertNull($failure->getPrevious());
                self::assertSame(
                    $stage === 'bind-false'
                        ? 'PDO could not bind a statement parameter.'
                        : 'PDO could not execute the statement.',
                    $failure->getMessage(),
                );
            }
            self::assertStringNotContainsString('synthetic-private-value', $failure->getMessage());
            self::assertStringNotContainsString('synthetic-private-value', $failure->sql);
        }

        self::assertSame(1, ConfigurableStatement::$closeCalls);
        self::assertSame(str_starts_with($stage, 'bind') ? 0 : 1, ConfigurableStatement::$executeCalls);
        self::assertFalse($connection->hasActiveCursors());
        self::assertTrue($connection->isReusable());
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    /** @return iterable<string, array{bool, bool, string, string}> */
    public static function failedHandoffs(): iterable
    {
        $contexts = [
            'raw-object' => [false, false],
            'raw-associative' => [false, true],
            'builder-object' => [true, false],
            'builder-associative' => [true, true],
        ];
        foreach ($contexts as $context => [$builder, $associative]) {
            foreach (self::handoffFailures() as $failure => [$stage, $cleanup]) {
                yield $context . '-' . $failure => [$builder, $associative, $stage, $cleanup];
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    private static function handoffFailures(): iterable
    {
        foreach (['bind-false', 'bind-throws', 'execute-false', 'execute-throws'] as $stage) {
            foreach (['success', 'false', 'throws'] as $cleanup) {
                yield $stage . '-' . $cleanup => [$stage, $cleanup];
            }
        }
    }

    public function testPrepareFailureHasNoStatementToClose(): void
    {
        [$connection, $observer] = $this->connection();
        try {
            $connection->query('SELECT * FROM missing_cursor_table')->iterate();
            self::fail('An invalid cursor query unexpectedly prepared.');
        } catch (QueryExecutionException $failure) {
            self::assertInstanceOf(PDOException::class, $failure->getPrevious());
        }
        self::assertSame(0, ConfigurableStatement::$closeCalls);
        self::assertFalse($connection->hasActiveCursors());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testSuccessfulHandoffLeavesCleanupWithTheCursor(): void
    {
        [$connection, $observer] = $this->connection();
        foreach ([false, true] as $associative) {
            $query = $connection->query('SELECT 42 AS value');
            $cursor = $associative ? $query->iterateAssociative() : $query->iterate();
            self::assertSame((int) $associative, ConfigurableStatement::$closeCalls);
            self::assertTrue($connection->hasActiveCursors());
            $cursor->close();
            $cursor->close();
            self::assertFalse($connection->hasActiveCursors());
        }
        self::assertSame(2, ConfigurableStatement::$closeCalls);
        self::assertCount(2, $observer->executions());
        self::assertTrue($observer->executions()[0]->successful);
        self::assertTrue($connection->isReusable());
        $connection->close();
    }

    /** @return array{Connection, RecordingQueryObserver} */
    private function connection(): array
    {
        $observer = new RecordingQueryObserver();
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:', pdoOptions: [
            PDO::ATTR_STATEMENT_CLASS => [ConfigurableStatement::class],
        ], observer: $observer);
        $connection->pdo()->exec('CREATE TABLE items (value TEXT)');

        return [$connection, $observer];
    }
}
