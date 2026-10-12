<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Error;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class ResourceOwnershipTest extends TestCase
{
    public function testConnectionCannotBeClonedAndOriginalRemainsUsable(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertFalse((new ReflectionClass($connection))->isCloneable());
        $this->expectException(Error::class);
        try {
            self::assertNotSame($connection, clone $connection);
        } finally {
            self::assertTrue($connection->isReusable());
            self::assertSame(['value' => 1], $connection->query('SELECT 1 AS value')->firstAssociative());
            $connection->close();
        }
    }

    public function testCursorCannotBeClonedAndOriginalRetainsOwnership(): void
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $cursor = $connection->query('SELECT 1 AS value UNION ALL SELECT 2 AS value')->iterateAssociative();
        self::assertFalse((new ReflectionClass($cursor))->isCloneable());
        $this->expectException(Error::class);
        try {
            self::assertNotSame($cursor, clone $cursor);
        } finally {
            self::assertTrue($connection->hasActiveCursors());
            self::assertFalse($connection->isReusable());
            try {
                $connection->close();
                self::fail('The original cursor must still block closure.');
            } catch (TransactionStateException) {
                self::assertFalse($cursor->isClosed());
            }
            self::assertSame([['value' => 1], ['value' => 2]], iterator_to_array($cursor));
            self::assertTrue($cursor->isClosed());
            self::assertTrue($connection->isReusable());
            $cursor->close();
            $connection->close();
        }
    }
}
