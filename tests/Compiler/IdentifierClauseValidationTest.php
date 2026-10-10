<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Closure;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierClauseValidationTest extends TestCase
{
    /** @param Closure(QueryBuilder, Identifier): mixed $clause */
    #[DataProvider('invalidClauses')]
    public function testInvalidIdentifierShapeIsRejectedWithoutMutation(
        Driver $driver,
        Identifier $identifier,
        Closure $clause,
    ): void {
        $connection = CompilerConnection::for($driver);
        $builder = $connection->table('items')->where('active', true);
        $before = $builder->compile();
        try {
            $clause($builder, $identifier);
            self::fail('A non-column identifier was accepted outside a projection or source.');
        } catch (InvalidQueryException $failure) {
            self::assertSame('Column identifiers cannot be wildcards or aliases.', $failure->getMessage());
        }
        self::assertSame($before->sql, $builder->compile()->sql);
        self::assertEquals($before->bindings, $builder->compile()->bindings);
    }

    /** @return iterable<string, array{Driver, Identifier, Closure(QueryBuilder, Identifier): mixed}> */
    public static function invalidClauses(): iterable
    {
        $clauses = [
            'where' => static fn (QueryBuilder $q, Identifier $i) => $q->where($i, 1),
            'where column left' => static fn (QueryBuilder $q, Identifier $i) => $q->whereColumn($i, '=', 'id'),
            'where column right' => static fn (QueryBuilder $q, Identifier $i) => $q->whereColumn('id', '=', $i),
            'in' => static fn (QueryBuilder $q, Identifier $i) => $q->whereIn($i, [1]),
            'empty in' => static fn (QueryBuilder $q, Identifier $i) => $q->whereIn($i, []),
            'between' => static fn (QueryBuilder $q, Identifier $i) => $q->whereBetween($i, 1, 2),
            'null' => static fn (QueryBuilder $q, Identifier $i) => $q->whereNull($i),
            'having' => static fn (QueryBuilder $q, Identifier $i) => $q->having($i, 1),
            'group' => static fn (QueryBuilder $q, Identifier $i) => $q->groupBy($i),
            'order' => static fn (QueryBuilder $q, Identifier $i) => $q->orderBy($i),
            'join left' => static fn (QueryBuilder $q, Identifier $i) => $q->join('other', $i, '=', 'other.id'),
            'join right' => static fn (QueryBuilder $q, Identifier $i) => $q->join('other', 'items.id', '=', $i),
            'join value' => static fn (QueryBuilder $q, Identifier $i) => $q->join(
                'other',
                static fn (JoinClause $join) => $join->onValue($i, '=', 1),
            ),
        ];
        $identifiers = ['alias' => Identifier::of('id')->as('x'), 'wildcard' => Identifier::wildcard('items')];
        foreach (Driver::cases() as $driver) {
            foreach ($identifiers as $shape => $identifier) {
                foreach ($clauses as $name => $clause) {
                    yield $driver->value . ' ' . $shape . ' ' . $name => [$driver, $identifier, $clause];
                }
            }
        }
    }

    #[DataProvider('dialects')]
    public function testProjectionAndSourceAliasesRemainValidWithOrderedBindings(Driver $driver, string $quote): void
    {
        $db = CompilerConnection::for($driver);
        $query = $db->table(Identifier::of('items')->as('i'))
            ->select(Identifier::of('i.id')->as('item_id'), Identifier::wildcard('i'))
            ->where(Identifier::of('i.id'), '>', 1)->orderBy(Identifier::of('item_id'))->compile();
        self::assertSame(
            'SELECT ' . $quote . 'i' . $quote . '.' . $quote . 'id' . $quote . ' AS ' . $quote . 'item_id' . $quote
                . ', ' . $quote . 'i' . $quote . '.* FROM ' . $quote . 'items' . $quote . ' AS ' . $quote . 'i' . $quote
                . ' WHERE ' . $quote . 'i' . $quote . '.' . $quote . 'id' . $quote . ' > ? ORDER BY '
                . $quote . 'item_id' . $quote . ' ASC',
            $query->sql,
        );
        self::assertEquals([Binding::fromValue(1)], $query->bindings);
    }

    /** @return iterable<string, array{Driver, string}> */
    public static function dialects(): iterable
    {
        yield 'MariaDB' => [Driver::MariaDb, '`'];
        yield 'MySQL' => [Driver::MySql, '`'];
        yield 'SQLite' => [Driver::Sqlite, '"'];
    }
}
