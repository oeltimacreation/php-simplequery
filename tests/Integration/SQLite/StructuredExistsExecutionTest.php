<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class StructuredExistsExecutionTest extends TestCase
{
    private Connection $db;
    private RecordingQueryObserver $observer;

    #[\Override]
    protected function setUp(): void
    {
        $this->observer = new RecordingQueryObserver(50);
        $this->db = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $this->observer);
        $this->db->query('CREATE TABLE customers (id INT PRIMARY KEY, active INT NOT NULL)')->execute();
        $this->db->query('CREATE TABLE orders (customer_id INT, status TEXT)')->execute();
        $this->db->query('INSERT INTO customers VALUES (1, 1), (2, 1), (3, 1)')->execute();
        $this->db->query("INSERT INTO orders VALUES (1, 'paid'), (1, 'paid'), (2, NULL), (NULL, 'paid')")->execute();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testCorrelatedReadsReuseSnapshotsWithoutMultiplyingRows(): void
    {
        $paid = $this->orders()->select($this->db->raw('? AS marker', ['projection']))->where('o.status', 'paid');
        $matched = $this->customers()->whereExists($paid);
        $unmatched = $this->customers()->whereNotExists($paid);
        $grouped = $this->customers()->where(static fn (ConditionGroup $group) => $group
            ->whereExists($paid)->orWhere('c.id', 3))->where('c.active', true);
        $before = $grouped->compile();
        $clone = clone $grouped;
        $clone->where('c.id', '<', 2);
        $paid->where('o.status', 'archived')->limit(0);

        self::assertSame([1], array_column($matched->getAssociative(), 'id'));
        self::assertSame([2, 3], array_column($unmatched->getAssociative(), 'id'));
        self::assertSame([1, 3], array_column($grouped->getAssociative(), 'id'));
        self::assertSame(2, $grouped->count());
        self::assertSame([1], array_column($clone->getAssociative(), 'id'));
        self::assertEquals($before, $grouped->compile());
    }

    public function testAggregateAndNullProjectionsKeepTheirExistenceSemantics(): void
    {
        $grouped = $this->orders()->select('o.customer_id')->groupBy('o.customer_id')
            ->having($this->db->raw('COUNT(*)'), '>=', 2);
        $null = $this->orders()->select($this->db->raw('NULL'));
        $aggregate = $this->orders()->select($this->db->raw('COUNT(*) AS total'))->where('o.status', 'missing');

        self::assertSame([1], array_column($this->customers()->whereExists($grouped)->getAssociative(), 'id'));
        self::assertSame([1, 2], array_column($this->customers()->whereExists($null)->getAssociative(), 'id'));
        self::assertSame(3, $this->customers()->whereExists($aggregate)->count());
    }

    public function testWriteRejectionDoesNotExecuteOrChangeRows(): void
    {
        $matching = $this->customers()->whereExists($this->orders()->where('o.status', 'paid'));
        $write = $this->db->table('customers')->whereIn('id', $matching);
        $before = $this->observer->executions();

        foreach ([static fn () => $write->update(['active' => false]), $write->delete(...)] as $apply) {
            try {
                $apply();
                self::fail('A write with structured EXISTS was executed.');
            } catch (UnsupportedFeatureException $failure) {
                self::assertSame(
                    'Structured EXISTS predicates are not supported in update or delete queries.',
                    $failure->getMessage(),
                );
            }
        }

        self::assertSame($before, $this->observer->executions());
        self::assertSame(3, $this->db->table('customers')->where('active', true)->count());
        self::assertSame(1, $write->count());
    }

    private function customers(): QueryBuilder
    {
        return $this->db->table('customers', 'c')->select('c.id')->orderBy('c.id');
    }

    private function orders(): QueryBuilder
    {
        return $this->db->table('orders', 'o')->whereColumn('o.customer_id', '=', 'c.id');
    }
}
