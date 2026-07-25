<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class ResultEdgeCasesTest extends TestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $this->connection->pdo()->exec(
            'CREATE TABLE edge_values ('
            . 'id INTEGER PRIMARY KEY, category TEXT NULL, oversized_value TEXT NOT NULL)',
        );
        $this->connection->table('edge_values')->insertMany([
            ['id' => 1, 'category' => 'alpha', 'oversized_value' => '1'],
            ['id' => 2, 'category' => 'beta', 'oversized_value' => '2'],
            ['id' => 3, 'category' => null, 'oversized_value' => '3'],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testNullContainingInListsPreserveSqlThreeValuedLogic(): void
    {
        self::assertSame(
            [1],
            $this->ids($this->connection->table('edge_values')->whereIn('category', [null, 'alpha'])),
        );
        self::assertSame(
            [],
            $this->ids($this->connection->table('edge_values')->whereNotIn('category', [null, 'alpha'])),
        );
        self::assertSame(
            [],
            $this->ids($this->connection->table('edge_values')->whereIn('category', [null])),
        );
        self::assertSame(
            [],
            $this->ids($this->connection->table('edge_values')->whereNotIn('category', [null])),
        );
    }

    public function testOversizedIntegerStringsRoundTripWithoutNumericConversion(): void
    {
        $oversized = '18446744073709551616000000000000000001';
        $this->connection->table('edge_values')->where('id', 1)->update(['oversized_value' => $oversized]);

        $row = $this->connection->table('edge_values')->where('oversized_value', $oversized)->firstAssociative();

        self::assertSame($oversized, $row['oversized_value'] ?? null);
    }

    public function testDuplicateColumnsFollowPdoLastValueAndNumericAssociativeColumnsAreRejected(): void
    {
        $object = $this->connection->query(
            'SELECT 1 AS duplicate_name, 2 AS duplicate_name, 3 AS "0"',
        )->first();
        self::assertNotNull($object);
        self::assertSame(2, $object->duplicate_name);
        self::assertSame(3, $object->{'0'});

        $associative = $this->connection->query(
            'SELECT 1 AS duplicate_name, 2 AS duplicate_name',
        )->firstAssociative();
        self::assertSame(['duplicate_name' => 2], $associative);

        $this->expectException(QueryExecutionException::class);
        $this->connection->query('SELECT 3 AS "0"')->getAssociative();
    }

    /** @return list<int> */
    private function ids(\Oeltima\SimpleQuery\QueryBuilder $builder): array
    {
        return array_map(
            static function (array $row): int {
                $id = $row['id'] ?? null;
                self::assertIsInt($id);

                return $id;
            },
            $builder->select('id')->orderBy('id')->getAssociative(),
        );
    }
}
