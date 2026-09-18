<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Expression\Identifier;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Executes representative consumer query shapes that were only compile-tested.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class QueryShapeExecutionTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $this->connection->pdo()->exec(
            'CREATE TABLE shapes ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL UNIQUE, '
            . 'category TEXT NULL, '
            . 'amount INTEGER NOT NULL)',
        );
        $this->connection->table('shapes')->insertMany([
            ['name' => 'alpha', 'category' => 'alpha', 'amount' => 10],
            ['name' => 'beta', 'category' => 'beta', 'amount' => 20],
            ['name' => 'gamma', 'category' => 'alpha', 'amount' => 30],
            ['name' => 'delta', 'category' => null, 'amount' => 40],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testEmptyInListsExecuteAsConstantPredicates(): void
    {
        $empty = $this->connection->table('shapes')->whereIn('id', []);
        self::assertSame('SELECT * FROM "shapes" WHERE 0 = 1', $empty->compile()->sql);
        self::assertSame([], $empty->getAssociative());

        $notEmpty = $this->connection->table('shapes')->whereNotIn('id', []);
        self::assertSame('SELECT * FROM "shapes" WHERE 1 = 1', $notEmpty->compile()->sql);
        self::assertCount(4, $notEmpty->getAssociative());
    }

    public function testProjectionAliasesAndGroupedPredicatesExecute(): void
    {
        $rows = $this->connection
            ->table('shapes', 's')
            ->select(
                Identifier::of('s.name')->as('display_name'),
                Identifier::of('s.amount')->as('total'),
            )
            ->where(static function (ConditionGroup $group): void {
                $group->where('s.category', '=', 'alpha')->orWhere('s.name', '=', 'beta');
            })
            ->orderBy('s.id')
            ->forPage(1, 2)
            ->getAssociative();

        self::assertSame(
            [
                ['display_name' => 'alpha', 'total' => 10],
                ['display_name' => 'beta', 'total' => 20],
            ],
            $rows,
        );
    }

    public function testSqlLookingValuesStayBoundAndDoNotMatchOtherRows(): void
    {
        $injectionShaped = "' OR 1=1 --";
        $this->connection->table('shapes')->insert([
            'name' => $injectionShaped,
            'category' => 'synthetic',
            'amount' => 50,
        ]);

        self::assertSame(
            1,
            $this->connection->table('shapes')->where('name', '=', $injectionShaped)->count(),
        );
        self::assertSame(
            0,
            $this->connection->table('shapes')->where('name', '=', $injectionShaped . 'x')->count(),
        );
        self::assertSame(5, $this->connection->table('shapes')->count());
    }

    public function testChunkedWritesCommitPartiallyOutsideATransactionAndRollBackInsideOne(): void
    {
        $chunks = [
            [
                ['name' => 'chunk-a', 'category' => 'chunk', 'amount' => 1],
                ['name' => 'chunk-b', 'category' => 'chunk', 'amount' => 2],
            ],
            [
                ['name' => 'chunk-c', 'category' => 'chunk', 'amount' => 3],
                ['name' => 'chunk-a', 'category' => 'chunk', 'amount' => 4],
            ],
        ];

        try {
            $this->insertChunks($chunks);
            self::fail('A duplicate batch row unexpectedly committed.');
        } catch (QueryExecutionException) {
            self::addToAssertionCount(1);
        }
        // Chunk one committed before chunk two failed; the caller owns atomicity.
        self::assertSame(2, $this->connection->table('shapes')->where('category', 'chunk')->count());

        $this->connection->table('shapes')->where('category', 'transactional')->delete();
        $transactional = [
            [
                ['name' => 'tx-a', 'category' => 'transactional', 'amount' => 1],
                ['name' => 'tx-b', 'category' => 'transactional', 'amount' => 2],
            ],
            [
                ['name' => 'tx-c', 'category' => 'transactional', 'amount' => 3],
                ['name' => 'tx-a', 'category' => 'transactional', 'amount' => 4],
            ],
        ];
        try {
            $this->connection->transaction(function (Connection $transaction) use ($transactional): void {
                $this->insertChunks($transactional, $transaction);
            });
        } catch (QueryExecutionException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->connection->table('shapes')->where('category', 'transactional')->count());
    }

    public function testOversizedSingleRowRoundTripsWithoutTruncation(): void
    {
        $payload = str_repeat('simplequery-', 81920);
        self::assertSame(983040, strlen($payload));

        $this->connection->table('shapes')->insert([
            'name' => 'oversized',
            'category' => 'payload',
            'amount' => 60,
        ]);
        $this->connection->query('CREATE TABLE payloads (id INTEGER PRIMARY KEY, body TEXT NOT NULL)')->execute();
        $this->connection->query('INSERT INTO payloads (id, body) VALUES (?, ?)', [1, $payload])->execute();

        $row = $this->connection->query('SELECT body FROM payloads WHERE id = ?', [1])->firstAssociative();
        self::assertSame($payload, $row['body'] ?? null);
    }

    /**
     * @param list<list<array<string, mixed>>> $chunks
     */
    private function insertChunks(array $chunks, ?Connection $connection = null): void
    {
        $connection ??= $this->connection;
        foreach ($chunks as $chunk) {
            $connection->table('shapes')->insertMany($chunk);
        }
    }
}
