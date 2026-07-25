<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

#[RequiresPhpExtension('pdo_sqlite')]
final class ConnectionLifecycleTest extends TestCase
{
    public function testConnectAppliesSQLitePolicyAndCustomTimeout(): void
    {
        $connection = Connection::connect(
            Driver::Sqlite,
            'sqlite::memory:',
            connectionOptions: new ConnectionOptions(
                sqliteBusyTimeoutMilliseconds: 3210,
                label: 'sqlite-test',
            ),
        );

        $foreignKeys = $connection->pdo()->query('PRAGMA foreign_keys');
        $busyTimeout = $connection->pdo()->query('PRAGMA busy_timeout');
        self::assertInstanceOf(PDOStatement::class, $foreignKeys);
        self::assertInstanceOf(PDOStatement::class, $busyTimeout);
        self::assertSame(1, (int) $foreignKeys->fetchColumn());
        self::assertSame(3210, (int) $busyTimeout->fetchColumn());
        self::assertSame('sqlite-test', $connection->connectionOptions()->label);
        self::assertFalse((bool) $connection->pdo()->getAttribute(PDO::ATTR_PERSISTENT));
        $connection->close();
    }

    public function testInjectedSQLiteMustDeclareTheEffectiveDefaultProfile(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->expectException(ConfigurationException::class);
        Connection::fromPdo($pdo, Driver::Sqlite);
    }

    public function testCloseIsIdempotentAndPreexistingBuilderCanStillCompile(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $builder = $connection->table('records')->where('id', 4);
        $connection->close();
        $connection->close();

        self::assertSame('SELECT * FROM "records" WHERE "id" = ?', $builder->compile()->sql);

        try {
            $builder->get();
            self::fail('A builder must not execute after its connection is closed.');
        } catch (ConnectionException) {
        }

        foreach (
            [
                static fn () => $connection->table('records'),
                static fn () => $connection->query('SELECT 1'),
                static fn () => $connection->transaction(static fn (): null => null),
                static fn () => $connection->pdo(),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Closed connection operation unexpectedly succeeded.');
            } catch (ConnectionException) {
            }
        }
    }

    public function testCloseRejectsAnActivePhysicalTransaction(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $connection->pdo()->beginTransaction();

        try {
            $connection->close();
            self::fail('Connection close must reject an active transaction.');
        } catch (TransactionStateException) {
            self::assertTrue($connection->pdo()->inTransaction());
        }

        $connection->pdo()->rollBack();
        $connection->close();
    }

    public function testConnectionFailuresDoNotExposeDsnDetails(): void
    {
        try {
            Connection::connect(Driver::Sqlite, 'sqlite:/proc/simplequery-secret/database.sqlite');
            self::fail('The inaccessible SQLite path unexpectedly connected.');
        } catch (ConnectionException $exception) {
            self::assertSame('Could not establish the database connection.', $exception->getMessage());
            self::assertStringNotContainsString('simplequery-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testConnectRejectsDsnAndPdoOptionConflictsBeforeConnecting(): void
    {
        $cases = [
            static fn () => Connection::connect(Driver::Sqlite, 'mysql:host=localhost;charset=utf8mb4'),
            static fn () => Connection::connect(Driver::MySql, 'mysql:host=localhost;dbname=test'),
            static fn () => Connection::connect(
                Driver::Sqlite,
                'sqlite::memory:',
                pdoOptions: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT],
            ),
            static fn () => Connection::connect(
                Driver::Sqlite,
                'sqlite::memory:',
                pdoOptions: [PDO::ATTR_PERSISTENT => true],
            ),
            static fn () => Connection::connect(
                Driver::Sqlite,
                'sqlite::memory:',
                pdoOptions: [PDO::ATTR_EMULATE_PREPARES => false],
            ),
            static fn () => (new ReflectionMethod(Connection::class, 'connect'))->invoke(
                null,
                Driver::Sqlite,
                'sqlite::memory:',
                null,
                null,
                ['not-an-attribute' => true],
            ),
            static fn () => Connection::connect(
                Driver::MySql,
                'mysql:host=localhost;dbname=test;charset=utf8mb4',
                pdoOptions: [PDO::MYSQL_ATTR_FOUND_ROWS => true],
            ),
            static fn () => Connection::connect(
                Driver::MySql,
                'mysql:host=localhost;dbname=test;charset=utf8mb4',
                pdoOptions: [PDO::ATTR_EMULATE_PREPARES => 1],
            ),
            static fn () => Connection::connect(
                Driver::MySql,
                'mysql:host=localhost;dbname=test;charset=utf8mb4',
                pdoOptions: [PDO::ATTR_EMULATE_PREPARES => true],
                connectionOptions: new ConnectionOptions(emulatePrepares: false),
            ),
            static fn () => Connection::connect(
                Driver::MariaDb,
                'mysql:host=localhost;dbname=test;charset=utf8mb4;charset=utf8mb4',
            ),
            static fn () => Connection::connect(
                Driver::MySql,
                'mysql:host=localhost;dbname=test;charset=latin1',
            ),
            static fn () => Connection::connect(
                Driver::MySql,
                'mysql:host=localhost;dbname=test;charset=utf8mb4',
                pdoOptions: [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => 1],
            ),
            static fn () => Connection::connect(
                Driver::MariaDb,
                'mysql:host=localhost;dbname=test;charset=utf8mb4',
                pdoOptions: [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false],
                connectionOptions: new ConnectionOptions(bufferedQueries: true),
            ),
        ];

        $rejections = 0;
        foreach ($cases as $operation) {
            try {
                $operation();
                self::fail('An invalid connection configuration unexpectedly succeeded.');
            } catch (ConfigurationException) {
                ++$rejections;
            }
        }
        self::addToAssertionCount($rejections);
    }

    public function testCredentialParametersAreMarkedSensitive(): void
    {
        $parameters = (new ReflectionMethod(Connection::class, 'connect'))->getParameters();
        $byName = [];
        foreach ($parameters as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        self::assertCount(1, $byName['username']->getAttributes(SensitiveParameter::class));
        self::assertCount(1, $byName['password']->getAttributes(SensitiveParameter::class));
    }
}
