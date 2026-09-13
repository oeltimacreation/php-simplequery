<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class ProfileReadTest extends TestCase
{
    #[DataProvider('failedReads')]
    public function testFailedProfileReadsAreRejected(string $query, string $behavior): void
    {
        $pdo = new ControlledProfilePdo($query, $behavior);
        $this->expectException(ConfigurationException::class);
        Connection::fromPdo($pdo, Driver::Sqlite, new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 0));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedReads(): iterable
    {
        foreach (['SELECT sqlite_version()', 'PRAGMA foreign_keys', 'PRAGMA busy_timeout'] as $query) {
            foreach (['query-false', 'fetch-false', 'query-throws'] as $behavior) {
                yield $query . '-' . $behavior => [$query, $behavior];
            }
        }
    }

    public function testValidZeroShapesAndConstructionTimeBoundary(): void
    {
        foreach (['integer-zero', 'string-zero'] as $behavior) {
            $pdo = new ControlledProfilePdo('PRAGMA busy_timeout', $behavior);
            $db = Connection::fromPdo($pdo, Driver::Sqlite, new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 0));
            self::assertSame(0, $db->connectionOptions()->sqliteBusyTimeoutMilliseconds);
            $db->close();
        }
        $db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $db->pdo()->exec('PRAGMA foreign_keys = OFF');
        $db->pdo()->exec('PRAGMA busy_timeout = 0');
        self::assertSame(['value' => 1], $db->query('SELECT 1 AS value')->firstAssociative());
        $db->close();
    }
}
