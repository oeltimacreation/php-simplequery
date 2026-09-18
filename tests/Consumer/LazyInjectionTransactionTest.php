<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Consumer;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Tests\Fixtures\LazyInjection\LazyDatabase;
use Oeltima\SimpleQuery\Tests\Fixtures\LazyInjection\LazyModel;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Characterizes application-shaped lazy injection and two-model transactions.
 *
 * The private consumer resolves connections lazily and hands the same owner to
 * several models during one transaction. These fixtures keep that pattern
 * synthetic and assert it through the public API only.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class LazyInjectionTransactionTest extends TestCase
{
    private LazyDatabase $database;

    #[\Override]
    protected function setUp(): void
    {
        $this->database = new LazyDatabase(
            static fn (): Connection => Connection::connect(Driver::Sqlite, 'sqlite::memory:'),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->database->close();
    }

    public function testCacheOnlyModelResolutionOpensNoConnection(): void
    {
        $first = new LazyModel($this->database, 'lazy_records');
        $second = new LazyModel($this->database, 'lazy_records');

        self::assertSame('lazy_records:cached', $first->renderFromCache());
        self::assertSame('lazy_records:cached', $second->renderFromCache());
        self::assertFalse($this->database->opened());
        self::assertSame(0, $this->database->factoryCalls());
    }

    public function testTwoModelsShareOneOwnerAndNestedFailureRollsBackInnerScope(): void
    {
        $this->createSchema();
        $first = new LazyModel($this->database, 'lazy_records');
        $second = new LazyModel($this->database, 'lazy_records');

        $result = $this->database->transaction(
            function (Connection $transaction) use ($first, $second): array {
                self::assertSame($transaction, $first->owner());
                self::assertSame($transaction, $second->owner());

                $firstId = $first->create('first');
                self::assertSame(1, $first->rename((int) $firstId, 'first-renamed'));

                try {
                    $transaction->transaction(function (Connection $inner) use ($second): void {
                        self::assertSame($inner, $second->owner());
                        $second->create('inner-rolled-back');

                        throw new RuntimeException('Synthetic inner failure.');
                    });
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }

                $secondId = $second->create('second');

                return ['first' => $firstId, 'second' => $secondId];
            },
        );

        self::assertSame('1', $result['first']);
        self::assertSame('2', $result['second']);
        self::assertSame(2, $first->owner()->table('lazy_records')->count());
        self::assertNull($first->find(3));
        self::assertSame('first-renamed', $first->find(1)['name'] ?? null);
        self::assertSame('second', $second->find(2)['name'] ?? null);
        self::assertSame(1, $this->database->factoryCalls());
    }

    public function testOuterRollbackUndoesWritesFromBothModels(): void
    {
        $this->createSchema();
        $first = new LazyModel($this->database, 'lazy_records');
        $second = new LazyModel($this->database, 'lazy_records');

        try {
            $this->database->transaction(function () use ($first, $second): void {
                $first->create('outer-first');
                $second->create('outer-second');

                throw new RuntimeException('Synthetic outer failure.');
            });
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $first->owner()->table('lazy_records')->count());
        self::assertSame(1, $this->database->factoryCalls());
    }

    public function testRowLocksCompileForMySqlFamilyAndRejectSqlite(): void
    {
        $mariaDb = CompilerConnection::for(Driver::MariaDb)->table('rows')->where('id', 1)->forUpdate()->noWait();
        self::assertSame(
            'SELECT * FROM `rows` WHERE `id` = ? FOR UPDATE NOWAIT',
            $mariaDb->compile()->sql,
        );

        $mySql = CompilerConnection::for(Driver::MySql)->table('rows')->where('id', 1)->forShare()->skipLocked();
        self::assertSame(
            'SELECT * FROM `rows` WHERE `id` = ? FOR SHARE SKIP LOCKED',
            $mySql->compile()->sql,
        );

        $this->createSchema();
        $model = new LazyModel($this->database, 'lazy_records');
        $model->create('lock-target');

        $this->expectException(UnsupportedFeatureException::class);
        $model->lockRow(1);
    }

    public function testTerminalCallsDoNotMutateTheSharedOwnerOrModelState(): void
    {
        $this->createSchema();
        $first = new LazyModel($this->database, 'lazy_records');
        $second = new LazyModel($this->database, 'lazy_records');
        $first->create('stable');

        $stored = $first->find(1);
        self::assertIsArray($stored);
        self::assertSame('stable', $stored['name']);
        self::assertSame(1, $this->database->factoryCalls());
        self::assertSame($first->owner(), $second->owner());
        self::assertSame(1, $first->owner()->table('lazy_records')->count());
    }

    private function createSchema(): void
    {
        $this->database->connection()->pdo()->exec(
            'CREATE TABLE lazy_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
    }
}
