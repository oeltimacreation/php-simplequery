<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('pdo_sqlite')]
final class ObservationDepthFailureTest extends TestCase
{
    public function testUnknownDepthDoesNotSuppressReadWriteOrCursorNotifications(): void
    {
        $pdo = new ControlledTransactionPdo();
        $observer = new RecordingQueryObserver();
        $db = Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        $pdo->failTransactionInspection = true;
        self::assertSame('1', $db->table('items')->insertGetId(['label' => 'synthetic-private-value']));
        self::assertSame(1, $db->table('items')->where('id', 1)->update(['label' => 'updated']));
        self::assertSame(['id' => 1], $db->table('items')->select('id')->firstAssociative());
        $cursor = $db->query('SELECT id FROM items')->iterateAssociative();
        $handoffExecutions = $observer->executions();
        self::assertCount(4, $handoffExecutions);
        self::assertSame([['id' => 1]], iterator_to_array($cursor));
        $cursor->close();
        self::assertSame($handoffExecutions, $observer->executions());
        foreach ($observer->executions() as $execution) {
            self::assertNull($execution->transactionDepth);
            self::assertTrue($execution->successful);
            self::assertStringNotContainsString('synthetic-private-value', $execution->sql);
        }
        self::assertSame([ParameterType::String], $observer->executions()[0]->parameterTypes);
        self::assertSame(1, $observer->executions()[0]->affectedRows);
        $pdo->failTransactionInspection = false;
        self::assertTrue($db->isReusable());
        $db->close();
    }

    public function testUnknownDepthPreservesTheOriginalQueryFailure(): void
    {
        $pdo = new ControlledTransactionPdo();
        $observer = new RecordingQueryObserver();
        $db = Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer);
        $pdo->failTransactionInspection = true;
        try {
            $db->query('SELECT * FROM missing_observation_table')->get();
            self::fail('An invalid query unexpectedly succeeded.');
        } catch (QueryExecutionException $failure) {
            self::assertInstanceOf(PDOException::class, $failure->getPrevious());
            self::assertSame('SELECT * FROM missing_observation_table', $failure->sql);
        }
        self::assertCount(1, $observer->executions());
        self::assertNull($observer->executions()[0]->transactionDepth);
        self::assertFalse($observer->executions()[0]->successful);
        $pdo->failTransactionInspection = false;
        self::assertTrue($db->isReusable());
        $db->close();
    }

    public function testKnownManagedDepthNeedsNoOptionalPhysicalInspection(): void
    {
        $pdo = new ControlledTransactionPdo();
        $observer = new RecordingQueryObserver();
        $db = Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer);
        $db->transaction(static function (Connection $outer) use ($pdo): void {
            $outer->transaction(static function (Connection $inner) use ($pdo): void {
                $before = $pdo->inspectionCalls;
                $pdo->failTransactionInspection = true;
                try {
                    self::assertSame(['value' => 42], $inner->query('SELECT 42 AS value')->firstAssociative());
                    self::assertSame($before, $pdo->inspectionCalls);
                } finally {
                    $pdo->failTransactionInspection = false;
                }
            });
        });
        self::assertSame(2, $observer->executions()[0]->transactionDepth);
        $pdo->beginTransaction();
        $db->query('SELECT 1')->first();
        self::assertSame(1, $observer->executions()[1]->transactionDepth);
        $pdo->rollBack();
        $db->query('SELECT 1')->first();
        self::assertSame(0, $observer->executions()[2]->transactionDepth);
        $db->close();
    }

    #[DataProvider('observers')]
    public function testDisabledAndThrowingObserversCannotChangeQueryOutcomes(bool $enabled): void
    {
        $pdo = new ControlledTransactionPdo();
        $observer = $enabled ? new class implements QueryObserver {
            public int $calls = 0;

            public ?QueryExecution $execution = null;

            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
                ++$this->calls;
                $this->execution = $execution;
                throw new RuntimeException('Synthetic observer failure.');
            }
        } : null;
        $db = Connection::fromPdo($pdo, Driver::Sqlite, observer: $observer);
        $before = $pdo->inspectionCalls;
        $pdo->failTransactionInspection = true;
        self::assertSame(['value' => 42], $db->query('SELECT 42 AS value')->firstAssociative());
        self::assertSame($before + (int) $enabled, $pdo->inspectionCalls);
        if ($observer !== null) {
            self::assertSame(1, $observer->calls);
            self::assertNotNull($observer->execution);
            self::assertNull($observer->execution->transactionDepth);
        }
        $pdo->failTransactionInspection = false;
        $db->close();
    }

    /** @return iterable<string, array{bool}> */
    public static function observers(): iterable
    {
        yield 'disabled' => [false];
        yield 'throwing' => [true];
    }
}
