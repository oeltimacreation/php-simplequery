<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
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
        $cursor = new Cursor($statement, $connection, new CompiledQuery('SELECT 1 AS value'), true);
        $cursor->close();

        $this->expectException(InvalidQueryException::class);
        $cursor->getIterator();
    }

    public function testCloseFailureIsTranslatedAndStillReleasesConnectionOwnership(): void
    {
        [$connection, $statement] = $this->statement(ThrowingCloseStatement::class);
        $cursor = new Cursor($statement, $connection, new CompiledQuery('SELECT 1 AS value'), false);

        try {
            $cursor->close();
            self::fail('The controlled close failure unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('SELECT 1 AS value', $exception->sql);
        }

        self::assertTrue($cursor->isClosed());
        $connection->close();
    }

    public function testFetchFailureIsTranslatedAndClosesCursor(): void
    {
        [$connection, $statement] = $this->statement(ThrowingFetchStatement::class);
        $cursor = new Cursor($statement, $connection, new CompiledQuery('SELECT 1 AS value'), false);

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

    #[DataProvider('invalidRows')]
    public function testInvalidDriverRowsAreRejected(mixed $row, bool $associative): void
    {
        ControlledFetchStatement::$row = $row;
        [$connection, $statement] = $this->statement(ControlledFetchStatement::class);
        $cursor = new Cursor(
            $statement,
            $connection,
            new CompiledQuery('SELECT 1 AS value'),
            $associative,
        );

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
}
