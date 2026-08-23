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
final class AssociativeHydrationTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ConfigurableStatement::reset();
    }

    public function testOnePassHydrationReturnsValidatedRowsAndClosesStatement(): void
    {
        ConfigurableStatement::returns(['value' => 42], once: true);
        [$connection] = $this->connection();

        self::assertSame([['value' => 42]], $connection->query('SELECT 42 AS value')->getAssociative());
        self::assertTrue(ConfigurableStatement::$closed);
        $connection->close();
    }

    #[DataProvider('invalidRows')]
    public function testOnePassHydrationRejectsInvalidRowsAndRecordsFailure(mixed $row): void
    {
        ConfigurableStatement::returns($row, once: true);
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled invalid associative row unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('SELECT 42 AS value', $exception->sql);
        }

        self::assertTrue(ConfigurableStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testOnePassHydrationTranslatesFetchFailureAndClosesStatement(): void
    {
        ConfigurableStatement::fetchThrows();
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled associative fetch failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame(
                ConfigurableStatement::DEFAULT_FETCH_FAILURE,
                $exception->getPrevious()->getMessage(),
            );
        }

        self::assertTrue(ConfigurableStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testSuccessfulResultCleanupFailureIsTranslatedAndObserved(): void
    {
        ConfigurableStatement::returns(['value' => 42], once: true);
        ConfigurableStatement::closeThrows();
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled terminal cleanup failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame(
                ConfigurableStatement::DEFAULT_CLOSE_FAILURE,
                $exception->getPrevious()->getMessage(),
            );
        }

        self::assertTrue(ConfigurableStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testInvalidResultRemainsPrimaryWhenTerminalCleanupAlsoFails(): void
    {
        ConfigurableStatement::returns([0 => 'invalid'], once: true);
        ConfigurableStatement::closeThrows();
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled dual terminal failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('PDO returned a non-string column name.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertTrue(ConfigurableStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testCursorObservationEndsAtHandoffAndFetchFailureDoesNotEmitASecondEvent(): void
    {
        ConfigurableStatement::fetchThrows();
        [$connection, $observer] = $this->connection();

        $cursor = $connection->query('SELECT 42 AS value')->iterateAssociative();
        $handoffExecution = $observer->executions()[0] ?? null;
        self::assertNotNull($handoffExecution);
        self::assertTrue($handoffExecution->successful);

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled cursor fetch failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertCount(1, $observer->executions());
        self::assertTrue($observer->executions()[0]->successful);
        self::assertTrue(ConfigurableStatement::$closed);
        $connection->close();
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRows(): iterable
    {
        yield 'non-array' => ['invalid'];
        yield 'numeric column name' => [[0 => 'invalid']];
    }

    /** @return array{Connection, RecordingQueryObserver} */
    private function connection(): array
    {
        $observer = new RecordingQueryObserver();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STATEMENT_CLASS => [ConfigurableStatement::class],
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return [Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer), $observer];
    }
}
