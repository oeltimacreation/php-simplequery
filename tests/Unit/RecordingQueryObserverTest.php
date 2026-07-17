<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PHPUnit\Framework\TestCase;

final class RecordingQueryObserverTest extends TestCase
{
    public function testHistoryIsBoundedAndCanBeCleared(): void
    {
        $observer = new RecordingQueryObserver(2);
        $observer->queryExecuted($this->execution('SELECT 1'));
        $observer->queryExecuted($this->execution('SELECT 2'));
        $observer->queryExecuted($this->execution('SELECT 3'));

        self::assertSame(['SELECT 2', 'SELECT 3'], array_map(
            static fn (QueryExecution $execution): string => $execution->sql,
            $observer->executions(),
        ));

        $observer->clear();
        self::assertSame([], $observer->executions());
    }

    public function testCapacityMustBePositive(): void
    {
        $this->expectException(InvalidQueryException::class);
        new RecordingQueryObserver(0);
    }

    private function execution(string $sql): QueryExecution
    {
        return new QueryExecution($sql, [], 0.1, true, null, Driver::Sqlite, null, 0);
    }
}
