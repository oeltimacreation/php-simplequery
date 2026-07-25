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
        ControlledAssociativeStatement::$row = false;
        ControlledAssociativeStatement::$throwOnFetch = false;
        ControlledAssociativeStatement::$closed = false;
    }

    public function testOnePassHydrationReturnsValidatedRowsAndClosesStatement(): void
    {
        ControlledAssociativeStatement::$row = ['value' => 42];
        [$connection] = $this->connection();

        self::assertSame([['value' => 42]], $connection->query('SELECT 42 AS value')->getAssociative());
        self::assertTrue(ControlledAssociativeStatement::$closed);
        $connection->close();
    }

    #[DataProvider('invalidRows')]
    public function testOnePassHydrationRejectsInvalidRowsAndRecordsFailure(mixed $row): void
    {
        ControlledAssociativeStatement::$row = $row;
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled invalid associative row unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('SELECT 42 AS value', $exception->sql);
        }

        self::assertTrue(ControlledAssociativeStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        $connection->close();
    }

    public function testOnePassHydrationTranslatesFetchFailureAndClosesStatement(): void
    {
        ControlledAssociativeStatement::$throwOnFetch = true;
        [$connection, $observer] = $this->connection();

        try {
            $connection->query('SELECT 42 AS value')->getAssociative();
            self::fail('The controlled associative fetch failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame(
                'Controlled associative hydration fetch failure.',
                $exception->getPrevious()->getMessage(),
            );
        }

        self::assertTrue(ControlledAssociativeStatement::$closed);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
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
            PDO::ATTR_STATEMENT_CLASS => [ControlledAssociativeStatement::class],
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return [Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer), $observer];
    }
}
