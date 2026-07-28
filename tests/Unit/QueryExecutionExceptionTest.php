<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryExecutionExceptionTest extends TestCase
{
    public function testMissingPdoErrorInfoDoesNotInventPortableEvidence(): void
    {
        $pdoException = new PDOException('synthetic transport failure');
        $exception = QueryExecutionException::fromPdo(
            $pdoException,
            'SELECT ?',
            Driver::Sqlite,
            'synthetic',
        );

        self::assertNull($exception->sqlState);
        self::assertNull($exception->driverCode);
        self::assertSame('Database statement execution failed.', $exception->getMessage());
        self::assertSame($pdoException, $exception->getPrevious());
    }

    #[DataProvider('driverEvidence')]
    public function testPdoEvidenceRemainsAccessibleWithoutAssigningPortableSemantics(
        Driver $driver,
        string $sqlState,
        int|string $driverCode,
    ): void {
        $pdoFailure = new PDOException('synthetic driver detail must remain out of the safe message');
        $pdoFailure->errorInfo = [$sqlState, $driverCode, 'synthetic driver detail'];

        $exception = QueryExecutionException::fromPdo(
            $pdoFailure,
            'UPDATE records SET state = ? WHERE id = ?',
            $driver,
            'writer',
        );

        self::assertSame($sqlState, $exception->sqlState);
        self::assertSame($driverCode, $exception->driverCode);
        self::assertSame($driver, $exception->driver);
        self::assertSame('writer', $exception->connectionLabel);
        self::assertSame($pdoFailure, $exception->getPrevious());
        self::assertStringNotContainsString('synthetic driver detail', $exception->getMessage());
    }

    /** @return iterable<string, array{Driver, string, int|string}> */
    public static function driverEvidence(): iterable
    {
        yield 'MySQL deadlock' => [Driver::MySql, '40001', 1213];
        yield 'MariaDB lock timeout with string code' => [Driver::MariaDb, 'HY000', '1205'];
        yield 'SQLite busy' => [Driver::Sqlite, 'HY000', 5];
        yield 'SQLite locked' => [Driver::Sqlite, 'HY000', 6];
        yield 'SQLite constraint' => [Driver::Sqlite, '23000', 19];
        yield 'MySQL transport loss' => [Driver::MySql, 'HY000', 2006];
    }
}
