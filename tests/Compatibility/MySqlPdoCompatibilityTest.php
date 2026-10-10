<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compatibility;

use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Internal\ConnectionProfile;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_mysql')]
final class MySqlPdoCompatibilityTest extends TestCase
{
    #[DataProvider('profiles')]
    public function testConnectionOptionsPreservePdoAttributesWithoutDeprecations(
        Driver $driver,
        ?bool $buffered,
        bool $rawOptions,
    ): void {
        $bufferedAttribute = class_exists(Mysql::class)
            ? Mysql::ATTR_USE_BUFFERED_QUERY
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        $foundRowsAttribute = class_exists(Mysql::class) ? Mysql::ATTR_FOUND_ROWS : PDO::MYSQL_ATTR_FOUND_ROWS;
        $declared = new ConnectionOptions(bufferedQueries: $rawOptions ? null : $buffered);
        $raw = $rawOptions ? [$bufferedAttribute => $buffered, $foundRowsAttribute => false] : [];

        $options = ConnectionProfile::buildPdoOptions($driver, $raw, $declared);
        self::assertSame($buffered ?? true, $options[$bufferedAttribute]);
        self::assertFalse($options[$foundRowsAttribute]);
        self::assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($options[PDO::ATTR_PERSISTENT]);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);

        $effective = ConnectionProfile::effectiveConnectionOptions($driver, $options, $declared);
        self::assertSame($buffered ?? true, $effective->bufferedQueries);
        self::assertFalse($effective->foundRows);
    }

    /** @return iterable<string, array{Driver, ?bool, bool}> */
    public static function profiles(): iterable
    {
        foreach ([Driver::MySql, Driver::MariaDb] as $driver) {
            yield $driver->value . '-defaults' => [$driver, null, false];
            yield $driver->value . '-buffered' => [$driver, true, false];
            yield $driver->value . '-unbuffered' => [$driver, false, false];
            yield $driver->value . '-raw-buffered' => [$driver, true, true];
            yield $driver->value . '-raw-unbuffered' => [$driver, false, true];
        }
    }
}
