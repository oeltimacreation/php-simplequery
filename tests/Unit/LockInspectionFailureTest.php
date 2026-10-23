<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class LockInspectionFailureTest extends TestCase
{
    #[DataProvider('depths')]
    public function testInspectionFailurePreservesDepthAndQuarantinesWithoutControlSql(int $depth): void
    {
        $pdo = new ControlledTransactionPdo();
        $db = Connection::fromPdo($pdo, Driver::Sqlite, new ConnectionOptions(label: 'lock-owner'));
        $stale = $db->table('items');
        $evidence = new class {
            /** @var list<string> */
            public array $controls = [];

            public ?TransactionStateException $failure = null;
        };
        $attempt = static function (Connection $connection) use ($pdo, $evidence): void {
            $evidence->controls = $pdo->controlCalls;
            $pdo->failTransactionInspection = true;
            try {
                $connection->requireTransactionForLock();
            } catch (TransactionStateException $failure) {
                $evidence->failure = $failure;
                throw $failure;
            }
        };

        try {
            match ($depth) {
                0 => $attempt($db),
                1 => $db->transaction($attempt),
                default => $db->transaction(static fn (Connection $outer) => $outer->transaction($attempt)),
            };
            self::fail('An uninspectable lock precondition was accepted.');
        } catch (TransactionStateException $failure) {
            self::assertSame($evidence->failure, $failure);
            self::assertSame('lock_query', $failure->operation);
            self::assertSame($depth, $failure->managedDepth);
            self::assertSame(Driver::Sqlite, $failure->driver);
            self::assertSame('lock-owner', $failure->connectionLabel);
            self::assertTrue($failure->connectionUnusable);
            self::assertInstanceOf(PDOException::class, $failure->controlFailure);
            self::assertSame($failure->controlFailure, $failure->getPrevious());
            self::assertStringNotContainsString('Controlled', $failure->getMessage());
        }
        self::assertSame($evidence->controls, $pdo->controlCalls);
        $inspections = $pdo->inspectionCalls;
        self::assertFalse($db->isReusable());
        self::assertFalse($db->isClosed());
        self::assertNotSame('', $stale->compile()->sql);
        try {
            $stale->get();
            self::fail('A stale builder executed on a quarantined owner.');
        } catch (TransactionStateException $failure) {
            self::assertSame('use_connection', $failure->operation);
        }
        self::assertSame($inspections, $pdo->inspectionCalls);
        $db->discard();
        $db->close();
        self::assertTrue($db->isClosed());
        self::assertSame($inspections, $pdo->inspectionCalls);

        $pdo->failTransactionInspection = false;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $replacement = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        self::assertTrue($replacement->isReusable());
        $replacement->close();
    }

    /** @return iterable<string, array{int}> */
    public static function depths(): iterable
    {
        yield 'unmanaged' => [0];
        yield 'outer' => [1];
        yield 'nested' => [2];
    }
}
