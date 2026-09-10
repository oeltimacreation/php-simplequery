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
        self::assertSame(1, ConfigurableStatement::$closeCalls);
        $connection->close();
    }

    public function testFalseOrdinaryCleanupPreservesResultAndConnectionUsability(): void
    {
        ConfigurableStatement::returns(['value' => 42], once: true);
        ConfigurableStatement::closeReturnsFalse();
        [$connection, $observer] = $this->connection();
        self::assertSame(['value' => 42], $connection->query('SELECT 42 AS value')->firstAssociative());
        self::assertSame('sqlite', $connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertClosedAndObserved($connection, $observer, successful: true);
    }

    #[DataProvider('invalidRows')]
    public function testOnePassHydrationRejectsInvalidRowsAndRecordsFailure(mixed $row): void
    {
        ConfigurableStatement::returns($row, once: true);
        $this->assertFailingAssociativeQuery(static function (QueryExecutionException $exception): void {
            self::assertSame('SELECT 42 AS value', $exception->sql);
        });
    }

    public function testOnePassHydrationTranslatesFetchFailureAndClosesStatement(): void
    {
        ConfigurableStatement::fetchThrows();
        $this->assertFailingAssociativeQuery(function (QueryExecutionException $exception): void {
            $this->assertPreviousPdoException($exception, ConfigurableStatement::DEFAULT_FETCH_FAILURE);
        });
    }

    public function testSuccessfulResultCleanupFailureIsTranslatedAndObserved(): void
    {
        ConfigurableStatement::returns(['value' => 42], once: true);
        ConfigurableStatement::closeThrows();
        $this->assertFailingAssociativeQuery(function (QueryExecutionException $exception): void {
            $this->assertPreviousPdoException($exception, ConfigurableStatement::DEFAULT_CLOSE_FAILURE);
        });
    }

    public function testInvalidResultRemainsPrimaryWhenTerminalCleanupAlsoFails(): void
    {
        ConfigurableStatement::returns([0 => 'invalid'], once: true);
        ConfigurableStatement::closeThrows();
        $this->assertFailingAssociativeQuery(static function (QueryExecutionException $exception): void {
            self::assertSame('PDO returned a non-string column name.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        });
    }

    /**
     * @param callable(QueryExecutionException): void $assertException
     */
    private function assertFailingAssociativeQuery(callable $assertException): void
    {
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled associative query unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            $assertException($exception);
        }

        $this->assertClosedAndObserved($connection, $observer, successful: false);
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

        $this->assertClosedAndObserved($connection, $observer, successful: true);
    }

    private function assertPreviousPdoException(QueryExecutionException $exception, string $expectedMessage): void
    {
        self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        self::assertSame($expectedMessage, $exception->getPrevious()->getMessage());
    }

    private function assertClosedAndObserved(
        Connection $connection,
        RecordingQueryObserver $observer,
        bool $successful,
    ): void {
        self::assertTrue(ConfigurableStatement::$closed);
        self::assertSame(1, ConfigurableStatement::$closeCalls);
        self::assertCount(1, $observer->executions());
        self::assertSame($successful, $observer->executions()[0]->successful);
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
