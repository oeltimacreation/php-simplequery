<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testFromPdoRetainsExplicitIdentityOptionsAndObserver(): void
    {
        $pdo = $this->sqlitePdo();
        $observer = new class implements QueryObserver {
            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
            }
        };
        $options = new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 5000, label: 'test');
        $connection = Connection::fromPdo($pdo, Driver::Sqlite, $options, $observer);

        self::assertSame($pdo, $connection->pdo());
        self::assertSame(Driver::Sqlite, $connection->driver());
        self::assertSame($options, $connection->connectionOptions());
        self::assertSame($observer, $connection->observer());
        self::assertNotSame($connection->table('users'), $connection->table('users'));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testFromPdoRejectsBroadDriverMismatch(): void
    {
        $this->expectException(ConfigurationException::class);
        Connection::fromPdo($this->sqlitePdo(), Driver::MySql);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testFromPdoRejectsDisabledForeignKeys(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->expectException(ConfigurationException::class);
        Connection::fromPdo($pdo, Driver::Sqlite);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testFromPdoRejectsNonExceptionMode(): void
    {
        $pdo = $this->sqlitePdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(ConfigurationException::class);
        Connection::fromPdo($pdo, Driver::Sqlite);
    }

    public function testCompilerOnlyConnectionCannotExposePdo(): void
    {
        $connection = CompilerConnection::for(Driver::MariaDb);
        self::assertNotSame('', $connection->table('users')->compile()->sql);

        $this->expectException(ConnectionException::class);
        $connection->pdo();
    }

    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}
