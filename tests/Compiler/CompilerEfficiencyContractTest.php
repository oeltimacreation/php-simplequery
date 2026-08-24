<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CompilerEfficiencyContractTest extends TestCase
{
    #[DataProvider('dialects')]
    public function testHighCardinalityListsPreserveExactSqlAndTypedBindingOrder(
        Driver $driver,
        string $quote,
    ): void {
        $connection = CompilerConnection::for($driver);
        $inputPattern = [null, true, 7, 1.5, 'tail'];
        $expectedValuePattern = [null, 1, 7, '1.5', 'tail'];
        $expectedTypePattern = [
            ParameterType::Null,
            ParameterType::Integer,
            ParameterType::Integer,
            ParameterType::String,
            ParameterType::String,
        ];
        $values = [];
        $expectedValues = [];
        $expectedTypes = [];
        for ($group = 0; $group < 1_000; ++$group) {
            array_push($values, ...$inputPattern);
            array_push($expectedValues, ...$expectedValuePattern);
            array_push($expectedTypes, ...$expectedTypePattern);
        }

        $compiled = $connection->table('items')->whereIn('id', $values)->compile();

        self::assertSame(
            'SELECT * FROM ' . $quote . 'items' . $quote . ' WHERE ' . $quote . 'id' . $quote
                . ' IN (' . str_repeat('?, ', 4_999) . '?)',
            $compiled->sql,
        );
        self::assertSame(
            $expectedValues,
            array_map(static fn (Binding $binding): mixed => $binding->value, $compiled->bindings),
        );
        self::assertSame(
            $expectedTypes,
            array_map(static fn (Binding $binding): ParameterType => $binding->type, $compiled->bindings),
        );
    }

    #[DataProvider('dialects')]
    public function testBatchSqlBufferPreservesRawSqlAndTypedBindingOrder(Driver $driver, string $quote): void
    {
        $connection = CompilerConnection::for($driver);
        $rows = [
            [
                'id' => 1,
                'label' => 'first',
                'created_at' => $connection->raw('COALESCE(?, CURRENT_TIMESTAMP)', ['fallback-1']),
            ],
            [
                'id' => 2,
                'label' => null,
                'created_at' => $connection->raw(
                    'COALESCE(?, CURRENT_TIMESTAMP)',
                    [new Binding('fallback-2', ParameterType::Binary)],
                ),
            ],
        ];

        $compiled = CompiledWriteQuery::insertMany($connection->table('events'), $rows);

        self::assertSame(
            'INSERT INTO ' . $quote . 'events' . $quote
                . ' (' . $quote . 'id' . $quote . ', ' . $quote . 'label' . $quote . ', '
                . $quote . 'created_at' . $quote . ') VALUES '
                . '(?, ?, COALESCE(?, CURRENT_TIMESTAMP)), (?, ?, COALESCE(?, CURRENT_TIMESTAMP))',
            $compiled->sql,
        );
        self::assertSame(
            [1, 'first', 'fallback-1', 2, null, 'fallback-2'],
            array_map(static fn (Binding $binding): mixed => $binding->value, $compiled->bindings),
        );
        self::assertSame(
            [
                ParameterType::Integer,
                ParameterType::String,
                ParameterType::String,
                ParameterType::Integer,
                ParameterType::Null,
                ParameterType::Binary,
            ],
            array_map(static fn (Binding $binding): ParameterType => $binding->type, $compiled->bindings),
        );
    }

    public function testBatchValidationPreservesRowLocalFailureOrdering(): void
    {
        $connection = CompilerConnection::for(Driver::Sqlite);

        try {
            CompiledWriteQuery::insertMany($connection->table('events'), [
                ['id' => new stdClass()],
                ['label' => 2],
            ]);
            self::fail('The invalid first-row binding should fail before a later column mismatch.');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Value is invalid for parameter type auto.', $exception->getMessage());
        }

        try {
            CompiledWriteQuery::insertMany($connection->table('events'), [
                ['id' => 1],
                ['label' => new stdClass()],
            ]);
            self::fail('The second-row column mismatch should fail before its invalid binding.');
        } catch (InvalidQueryException $exception) {
            self::assertSame(
                'Every batch insert row must have identical ordered columns.',
                $exception->getMessage(),
            );
        }
    }

    public function testListSnapshotsClonesAndWriteCompilationRemainIsolated(): void
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $child = $connection->table('roles')->select('id')->whereIn('level', [1, 2]);
        $parent = $connection->table('users')->whereIn('role_id', $child)->whereIn('id', [3, 4]);
        $clone = clone $parent;
        $clone->whereIn('id', [5]);
        $parentBeforeMutation = $parent->compile();

        $child->whereIn('level', [99]);

        self::assertEquals($parentBeforeMutation, $parent->compile());
        self::assertNotEquals($parentBeforeMutation, $clone->compile());

        $writeBuilder = $connection->table('events');
        $builderBeforeWrites = $writeBuilder->compile();
        $first = CompiledWriteQuery::insertMany($writeBuilder, [['id' => 1], ['id' => 2]]);
        $second = CompiledWriteQuery::insertMany($writeBuilder, [['id' => 1], ['id' => 2]]);

        self::assertEquals($first, $second);
        self::assertEquals($builderBeforeWrites, $writeBuilder->compile());
    }

    /** @return iterable<string, array{Driver, string}> */
    public static function dialects(): iterable
    {
        yield 'MariaDB' => [Driver::MariaDb, '`'];
        yield 'MySQL' => [Driver::MySql, '`'];
        yield 'SQLite' => [Driver::Sqlite, '"'];
    }
}
