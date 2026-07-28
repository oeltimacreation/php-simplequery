<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use Oeltima\SimpleQuery\TransactionMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionModeTest extends TestCase
{
    #[DataProvider('mysqlFamilyDrivers')]
    public function testImmediateModeIsRejectedBeforeCallbackOrTransactionStart(Driver $driver): void
    {
        $connection = CompilerConnection::for($driver);
        $callbackCalled = false;

        try {
            $connection->transaction(
                function () use (&$callbackCalled): void {
                    $callbackCalled = true;
                },
                TransactionMode::Immediate,
            );
            self::fail('A MySQL-family immediate transaction unexpectedly started.');
        } catch (UnsupportedFeatureException) {
            self::assertFalse($callbackCalled);
        }
    }

    /** @return iterable<string, array{Driver}> */
    public static function mysqlFamilyDrivers(): iterable
    {
        yield 'MariaDB' => [Driver::MariaDb];
        yield 'MySQL' => [Driver::MySql];
    }
}
