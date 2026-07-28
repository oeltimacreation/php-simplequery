<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class CursorFailureTest extends TestCase
{
    public function testClosedCursorCannotBeginIteration(): void
    {
        [$connection, $statement] = $this->statement();
        $cursor = Cursor::associative($statement, $connection, new CompiledQuery('SELECT 1 AS value'));
        $cursor->close();

        $this->expectException(InvalidQueryException::class);
        $cursor->getIterator();
    }

    public function testCursorCannotCreateASecondIterator(): void
    {
        [$connection, $statement] = $this->statement();
        $cursor = Cursor::associative($statement, $connection, new CompiledQuery('SELECT 1 AS value'));
        $cursor->getIterator();

        try {
            $cursor->getIterator();
            self::fail('A second cursor iterator was unexpectedly created.');
        } catch (InvalidQueryException) {
            self::addToAssertionCount(1);
        }

        $cursor->close();
        $connection->close();
    }

    public function testCloseExceptionIsTranslatedAndQuarantinesConnection(): void
    {
        [$connection, $statement] = $this->statement(ThrowingCloseStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            $cursor->close();
            self::fail('The controlled close failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('SELECT 1 AS value', $exception->sql);
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testFalseCloseReturnQuarantinesConnection(): void
    {
        [$connection, $statement] = $this->statement(FalseCloseStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            $cursor->close();
            self::fail('The controlled false close return unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertSame('SELECT 1 AS value', $exception->sql);
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testFetchFailureIsTranslatedAndClosesCursor(): void
    {
        [$connection, $statement] = $this->statement(ThrowingFetchStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled fetch failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertTrue($cursor->isClosed());
        $connection->close();
    }

    public function testFetchFailureRemainsPrimaryWhenCleanupAlsoFails(): void
    {
        [$connection, $statement] = $this->statement(ThrowingFetchAndCloseStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled dual cursor failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('Controlled cursor-fetch failure.', $exception->getPrevious()->getMessage());
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testExhaustionCloseFailureIsReportedAndQuarantinesConnection(): void
    {
        [$connection, $statement] = $this->statement(ExhaustingThrowingCloseStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled exhaustion cleanup failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('Controlled cursor-close failure.', $exception->getPrevious()->getMessage());
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testDestructorSuppressesCleanupFailureButStillQuarantinesConnection(): void
    {
        [$connection, $statement] = $this->statement(ThrowingCloseStatement::class);
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        unset($cursor);
        gc_collect_cycles();

        $this->assertQuarantined($connection);
        $connection->close();
    }

    #[DataProvider('invalidRows')]
    public function testInvalidDriverRowsAreRejected(mixed $row, bool $associative): void
    {
        ControlledFetchStatement::$row = $row;
        [$connection, $statement] = $this->statement(ControlledFetchStatement::class);
        $query = new CompiledQuery('SELECT 1 AS value');
        $cursor = $associative
            ? Cursor::associative($statement, $connection, $query)
            : Cursor::objects($statement, $connection, $query);

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('An invalid controlled fetch row unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('SELECT 1 AS value', $exception->sql);
        }

        self::assertTrue($cursor->isClosed());
        $connection->close();
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function invalidRows(): iterable
    {
        yield 'associative non-array' => ['not-an-array', true];
        yield 'associative numeric key' => [[0 => 'value'], true];
        yield 'object non-object' => [['value' => 1], false];
    }

    /**
     * @param class-string<\PDOStatement>|null $statementClass
     * @return array{Connection, \PDOStatement}
     */
    private function statement(?string $statementClass = null): array
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($statementClass !== null) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [$statementClass];
        }
        $pdo = new PDO('sqlite::memory:', null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $connection = Connection::fromPdo($pdo, Driver::Sqlite);
        $statement = $pdo->prepare('SELECT 1 AS value');
        $statement->execute();

        return [$connection, $statement];
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
}
