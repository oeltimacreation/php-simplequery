<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ConnectionFailureEvidenceTest extends TestCase
{
    #[DataProvider('syntheticConstructionFailures')]
    public function testConstructionFailuresExposeNormalizedDriverEvidence(
        PDOException $failure,
        ?string $expectedSqlState,
        int|string|null $expectedDriverCode,
    ): void {
        $exception = ConnectionException::fromConstructionFailure($failure, Driver::MySql, 'writer');

        self::assertSame('connect', $exception->operation);
        self::assertSame($expectedSqlState, $exception->sqlState);
        self::assertSame($expectedDriverCode, $exception->driverCode);
        self::assertSame(Driver::MySql, $exception->driver);
        self::assertSame('writer', $exception->connectionLabel);
        self::assertSame('Could not establish the database connection.', $exception->getMessage());
        self::assertStringNotContainsString('synthetic-driver-detail', $exception->getMessage());
    }

    public function testConstructionEvidenceDoesNotRetainTheRawPdoException(): void
    {
        $failure = self::pdoFailure('HY000', 1045, 'Access denied for user "synthetic"@"private-host"');

        $exception = ConnectionException::fromConstructionFailure($failure, Driver::MariaDb, 'writer');

        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('private-host', $exception->getMessage());
        self::assertStringNotContainsString('synthetic', $exception->getMessage());
    }

    public function testPlainConstructorCallsRemainSupported(): void
    {
        $exception = new ConnectionException('Synthetic application-level failure.');

        self::assertSame('connection', $exception->operation);
        self::assertSame('Synthetic application-level failure.', $exception->getMessage());
        self::assertNull($exception->sqlState);
        self::assertNull($exception->driverCode);
        self::assertNull($exception->driver);
        self::assertNull($exception->connectionLabel);
        self::assertNull($exception->getPrevious());
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testClosedAndCompilerOnlyMisuseAreNotConstructionFailures(): void
    {
        $connection = Connection::connect(
            Driver::Sqlite,
            'sqlite::memory:',
            connectionOptions: new ConnectionOptions(label: 'worker'),
        );
        $connection->close();

        try {
            $connection->table('records');
            self::fail('A closed connection unexpectedly accepted query creation.');
        } catch (ConnectionException $failure) {
            self::assertSame('closed', $failure->operation);
            self::assertSame('The database connection is closed.', $failure->getMessage());
            self::assertSame(Driver::Sqlite, $failure->driver);
            self::assertSame('worker', $failure->connectionLabel);
            self::assertNull($failure->sqlState);
            self::assertNull($failure->driverCode);
            self::assertNull($failure->getPrevious());
        }

        $compiler = Connection::forCompilation(Driver::MariaDb);

        try {
            $compiler->pdo();
            self::fail('A compiler-only connection unexpectedly exposed a PDO instance.');
        } catch (ConnectionException $failure) {
            self::assertSame('compiler_only', $failure->operation);
            self::assertSame('A compiler-only connection has no PDO instance.', $failure->getMessage());
            self::assertSame(Driver::MariaDb, $failure->driver);
            self::assertNull($failure->connectionLabel);
            self::assertNull($failure->sqlState);
            self::assertNull($failure->driverCode);
            self::assertNull($failure->getPrevious());
        }
    }

    public function testConfigurationRejectionStaysAConfigurationFailure(): void
    {
        try {
            Connection::connect(Driver::Sqlite, 'mysql:host=localhost;charset=utf8mb4');
            self::fail('A mismatched DSN unexpectedly connected.');
        } catch (ConfigurationException $exception) {
            self::assertSame('The DSN does not match the selected driver.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{PDOException, ?string, int|string|null}> */
    public static function syntheticConstructionFailures(): iterable
    {
        yield 'capacity exhaustion' => [self::pdoFailure('HY000', 1040), 'HY000', 1040];
        yield 'authentication rejection' => [self::pdoFailure('28000', 1045), '28000', 1045];
        yield 'socket refusal' => [self::pdoFailure('HY000', 2002), 'HY000', 2002];
        yield 'server refusal' => [self::pdoFailure('HY000', 2003), 'HY000', 2003];
        yield 'server gone away' => [self::pdoFailure('HY000', 2006), 'HY000', 2006];
        yield 'lost connection' => [self::pdoFailure('HY000', 2013), 'HY000', 2013];
        yield 'client interaction timeout' => [self::pdoFailure('HY000', 4031), 'HY000', 4031];
        yield 'string driver code' => [self::pdoFailure('HY000', '2006'), 'HY000', '2006'];
        yield 'missing errorInfo with integer code' => [
            self::pdoFailure(null, null, 'synthetic-driver-detail', 2002),
            null,
            2002,
        ];
        yield 'missing errorInfo without code' => [self::pdoFailure(null, null), null, null];
        yield 'non-string state entry' => [self::pdoFailure(123, 2003), null, 2003];
        yield 'empty state entry' => [self::pdoFailure('', 2013), null, 2013];
        yield 'non-scalar code entry' => [self::pdoFailure('HY000', 1.5), 'HY000', null];
        yield 'empty code entry' => [self::pdoFailure('HY000', ''), 'HY000', null];
        yield 'truncated errorInfo' => [self::pdoFailure('HY000', null), 'HY000', null];
    }

    private static function pdoFailure(
        mixed $sqlState,
        mixed $driverCode,
        string $detail = 'synthetic-driver-detail',
        int $code = 0,
    ): PDOException {
        $failure = new PDOException($detail, $code);
        if ($sqlState !== null || $driverCode !== null) {
            $failure->errorInfo = [$sqlState, $driverCode, $detail];
        }

        return $failure;
    }
}
