<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use WeakReference;

#[RequiresPhpExtension('pdo_sqlite')]
final class ConnectionReleaseTest extends TestCase
{
    public function testClosedConnectionAndCachedServicesReleaseWithoutCycleCollection(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertSame(['value' => 1], $connection->query('SELECT 1 AS value')->firstAssociative());
        $owner = WeakReference::create($connection);
        $pdo = WeakReference::create($connection->pdo());
        $connection->close();
        self::assertNull($pdo->get());
        unset($connection);
        self::assertNull($owner->get());
    }

    public function testDiscardedArtifactsKeepTheirOwnerUntilReleased(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $builder = $connection->table('items')->where('id', 1);
        $raw = $connection->query('SELECT 1 AS value');
        $before = $builder->compile();
        self::assertSame(['value' => 1], $raw->firstAssociative());
        $owner = WeakReference::create($connection);
        $pdo = WeakReference::create($connection->pdo());
        $connection->discard();
        unset($connection);
        self::assertNull($pdo->get());
        self::assertNotNull($owner->get());
        self::assertEquals($before, $builder->compile());
        unset($builder, $raw);
        self::assertNull($owner->get());
    }

    public function testCompletedTransactionDoesNotKeepClosedOwnerAlive(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $connection->query('CREATE TABLE items (id INTEGER)')->execute();
        self::assertSame(1, $connection->transaction(static function (Connection $outer): int {
            return $outer->transaction(
                static fn (Connection $inner): int => $inner->table('items')->insert(['id' => 1]),
            );
        }));
        $owner = WeakReference::create($connection);
        $connection->close();
        unset($connection);
        self::assertNull($owner->get());
    }

    public function testCursorKeepsDiscardedOwnerUntilReleased(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $cursor = $connection->query('SELECT 1 AS value')->iterateAssociative();
        $owner = WeakReference::create($connection);
        $connection->discard();
        unset($connection);
        self::assertNotNull($owner->get());
        $cursor->close();
        self::assertTrue($cursor->isClosed());
        self::assertNotNull($owner->get());
        unset($cursor);
        self::assertNull($owner->get());
    }
}
