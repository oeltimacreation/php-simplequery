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
    #[\Override]
    protected function setUp(): void
    {
        ConfigurableStatement::reset();
    }

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
        ConfigurableStatement::closeThrows();
        [$connection, $statement] = $this->statement();
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
        ConfigurableStatement::closeReturnsFalse();
        [$connection, $statement] = $this->statement();
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
        ConfigurableStatement::fetchThrows();
        [$connection, $statement] = $this->statement();
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
        ConfigurableStatement::fetchThrows();
        ConfigurableStatement::closeThrows();
        [$connection, $statement] = $this->statement();
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled dual cursor failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame(ConfigurableStatement::DEFAULT_FETCH_FAILURE, $exception->getPrevious()->getMessage());
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testExhaustionCloseFailureIsReportedAndQuarantinesConnection(): void
    {
        ConfigurableStatement::closeThrows();
        [$connection, $statement] = $this->statement();
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('The controlled exhaustion cleanup failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame(ConfigurableStatement::DEFAULT_CLOSE_FAILURE, $exception->getPrevious()->getMessage());
        }

        self::assertTrue($cursor->isClosed());
        $this->assertQuarantined($connection);
        $connection->close();
    }

    public function testDestructorSuppressesCleanupFailureButStillQuarantinesConnection(): void
    {
        ConfigurableStatement::closeThrows();
        [$connection, $statement] = $this->statement();
        $cursor = Cursor::objects($statement, $connection, new CompiledQuery('SELECT 1 AS value'));

        unset($cursor);
        gc_collect_cycles();

        $this->assertQuarantined($connection);
        $connection->close();
    }

    #[DataProvider('invalidRows')]
    public function testInvalidDriverRowsAreRejected(mixed $row, bool $associative): void
    {
        ConfigurableStatement::returns($row);
        [$connection, $statement] = $this->statement();
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

    /** @return array{Connection, \PDOStatement} */
    private function statement(): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STATEMENT_CLASS => [ConfigurableStatement::class],
        ]);
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
