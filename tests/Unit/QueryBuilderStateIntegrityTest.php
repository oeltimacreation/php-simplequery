<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use PHPUnit\Framework\TestCase;

final class QueryBuilderStateIntegrityTest extends TestCase
{
    public function testSelectAppendsValidProjectionsBeforeValidationFailure(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('users');

        try {
            $builder->select('id', 'name', '');
            self::fail('An empty column name must throw InvalidQueryException.');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Use Identifier::wildcard() for wildcard identifiers.', $exception->getMessage());
        }

        // The builder retains projections parsed before the invalid element
        $compiled = $builder->compile();
        self::assertSame('SELECT "id", "name" FROM "users"', $compiled->sql);
    }

    public function testGroupByAppendsValidColumnsBeforeValidationFailure(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('orders')->select('status', 'amount');

        try {
            $builder->groupBy('status', '');
            self::fail('An empty group column must throw InvalidQueryException.');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Use Identifier::wildcard() for wildcard identifiers.', $exception->getMessage());
        }

        $compiled = $builder->compile();
        self::assertSame('SELECT "status", "amount" FROM "orders" GROUP BY "status"', $compiled->sql);
    }

    public function testWhereInValidatesInputsAtomicallyBeforeModifyingConditions(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('users')->where('active', 1);

        try {
            // An invalid iterable with an unbindable resource throws during validation
            $resource = fopen('php://memory', 'rb');
            try {
                /** @var list<mixed> $values */
                $values = [1, 2, $resource];
                $builder->whereIn('id', $values);
                self::fail('A resource binding must throw InvalidQueryException.');
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        } catch (InvalidQueryException) {
        }

        // Condition collection is untouched by the rejected whereIn
        $compiled = $builder->compile();
        self::assertSame('SELECT * FROM "users" WHERE "active" = ?', $compiled->sql);
        self::assertSame([1], array_map(static fn (Binding $b): mixed => $b->value, $compiled->bindings));
    }

    public function testWhereBetweenValidatesAtomicallyBeforeModifyingConditions(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('products')->where('in_stock', 1);

        try {
            $resource = fopen('php://memory', 'rb');
            try {
                $builder->whereBetween('price', 10, $resource);
                self::fail('A resource binding must throw InvalidQueryException.');
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        } catch (InvalidQueryException) {
        }

        $compiled = $builder->compile();
        self::assertSame('SELECT * FROM "products" WHERE "in_stock" = ?', $compiled->sql);
        self::assertSame([1], array_map(static fn (Binding $b): mixed => $b->value, $compiled->bindings));
    }

    public function testJoinValidatesAtomicallyBeforeModifyingJoins(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('users');

        try {
            // Wildcard join table is rejected
            $builder->join(Identifier::wildcard(), 'users.id', '=', 'orders.user_id');
            self::fail('A wildcard join table must throw InvalidQueryException.');
        } catch (InvalidQueryException $exception) {
            self::assertSame('A join source cannot be a wildcard.', $exception->getMessage());
        }

        $compiled = $builder->compile();
        self::assertSame('SELECT * FROM "users"', $compiled->sql);
    }

    public function testTerminalCompilationDoesNotMutateBuilder(): void
    {
        $connection = Connection::forCompilation(Driver::Sqlite);
        $builder = $connection->table('users')->where('id', 1);

        $first = $builder->compile();
        $second = $builder->compile();

        self::assertSame($first->sql, $second->sql);
        self::assertEquals($first->bindings, $second->bindings);
    }
}
