<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Consumer;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Tests\Fixtures\Legacy\LegacyWriteAdapter;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes legacy boolean write returns on top of affected-row terminals.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class LegacyWriteReturnTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $this->connection->pdo()->exec(
            'CREATE TABLE legacy_records ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, state TEXT NOT NULL)',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testAdapterBooleanReturnsAreDerivedFromAffectedRows(): void
    {
        $adapter = new LegacyWriteAdapter($this->connection, 'legacy_records');

        self::assertSame(1, $adapter->insert(['name' => 'first', 'state' => 'ready']));
        self::assertSame('2', $adapter->insertGetId(['name' => 'second', 'state' => 'ready']));
        self::assertSame(
            2,
            $adapter->insertMany([
                ['name' => 'third', 'state' => 'ready'],
                ['name' => 'fourth', 'state' => 'done'],
            ]),
        );

        self::assertTrue($adapter->updateById(1, ['state' => 'done']));
        self::assertFalse($adapter->updateById(999, ['state' => 'done']));
        self::assertTrue($adapter->deleteById(4));
        self::assertFalse($adapter->deleteById(999));
        self::assertSame(3, $this->connection->table('legacy_records')->count());
    }

    public function testAdapterBooleanReturnCannotDistinguishUnchangedFromMissing(): void
    {
        $adapter = new LegacyWriteAdapter($this->connection, 'legacy_records');
        $id = (int) $adapter->insertGetId(['name' => 'stable', 'state' => 'ready']);

        self::assertTrue($adapter->updateById($id, ['state' => 'done']));
        // The row exists and the assignment is a no-op, so a driver that
        // reports changed rows can return zero affected rows on MySQL-family
        // engines. The synthetic SQLite fixture reports the matched row.
        self::assertTrue($adapter->updateById($id, ['state' => 'done']));
    }
}
