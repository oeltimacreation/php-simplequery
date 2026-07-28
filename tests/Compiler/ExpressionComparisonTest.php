<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpressionComparisonTest extends TestCase
{
    #[DataProvider('drivers')]
    public function testExpressionAndColumnComparisonsPreserveSqlOccurrenceBindingOrder(Driver $driver): void
    {
        $db = CompilerConnection::for($driver);
        $quote = $driver === Driver::Sqlite ? '"' : '`';
        $identifier = static fn (string $name): string => implode(
            '.',
            array_map(static fn (string $segment): string => $quote . $segment . $quote, explode('.', $name)),
        );
        $activeRoles = $db->table('roles')->select('user_id')->where('active', true);
        $query = $db
            ->table('users', 'u')
            ->select($db->raw('? AS marker', ['projection']), 'u.id')
            ->leftJoin(Identifier::of('profiles')->as('p'), static function (JoinClause $join) use ($db): void {
                $join
                    ->on($db->raw('COALESCE(p.owner_id, ?)', [0]), '=', Identifier::of('u.id'))
                    ->orOn('p.code', '=', $db->raw('COALESCE(u.code, ?)', ['missing']))
                    ->onValue($db->raw('COALESCE(p.visible, ?)', [false]), '=', true);
            })
            ->where($db->raw('COALESCE(LOWER(u.email), ?)', ['']), '=', 'ada@example.test')
            ->whereColumn('u.team_id', '=', 'p.team_id')
            ->orWhere(static function (ConditionGroup $group) use ($db): void {
                $group
                    ->where($db->raw('u.score + ?', [1]), '>=', 10)
                    ->orWhereColumn('u.owner_id', '=', 'p.owner_id');
            })
            ->whereIn('u.id', $activeRoles)
            ->groupBy('u.id')
            ->having($db->raw('COUNT(CASE WHEN u.active = ? THEN 1 END)', [true]), '>', 2)
            ->orderBy($db->raw('CASE WHEN u.score > ? THEN 0 ELSE 1 END', [100]));
        $activeRoles->where('name', 'later mutation');

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT ? AS marker, ' . $identifier('u.id') . ' FROM ' . $identifier('users') . ' AS '
                . $identifier('u') . ' LEFT JOIN ' . $identifier('profiles') . ' AS ' . $identifier('p')
                . ' ON COALESCE(p.owner_id, ?) = ' . $identifier('u.id')
                . ' OR ' . $identifier('p.code') . ' = COALESCE(u.code, ?)'
                . ' AND COALESCE(p.visible, ?) = ?'
                . ' WHERE COALESCE(LOWER(u.email), ?) = ?'
                . ' AND ' . $identifier('u.team_id') . ' = ' . $identifier('p.team_id')
                . ' OR (u.score + ? >= ? OR ' . $identifier('u.owner_id') . ' = ' . $identifier('p.owner_id') . ')'
                . ' AND ' . $identifier('u.id') . ' IN (SELECT ' . $identifier('user_id')
                . ' FROM ' . $identifier('roles') . ' WHERE ' . $identifier('active') . ' = ?)'
                . ' GROUP BY ' . $identifier('u.id')
                . ' HAVING COUNT(CASE WHEN u.active = ? THEN 1 END) > ?'
                . ' ORDER BY CASE WHEN u.score > ? THEN 0 ELSE 1 END ASC',
            ['projection', 0, 'missing', 0, 1, '', 'ada@example.test', 1, 10, 1, 1, 2, 100],
        );
        self::addToAssertionCount(1);
    }

    public function testExpressionNullComparisonsUseIsNullWithoutLosingExpressionBindings(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('users')
            ->where($db->raw('NULLIF(email, ?)', ['']), null)
            ->where($db->raw('NULLIF(alias, ?)', ['']), '!=', null)
            ->whereNot($db->raw('NULLIF(name, ?)', ['']), null);

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT * FROM "users" WHERE NULLIF(email, ?) IS NULL '
                . 'AND NULLIF(alias, ?) IS NOT NULL AND NULLIF(name, ?) IS NOT NULL',
            ['', '', ''],
        );
        self::addToAssertionCount(1);
    }

    public function testExpressionComparisonFailureDoesNotMutateAndCloneRemainsIndependent(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db->table('users')->whereColumn('tenant_id', '=', 'owner_tenant_id');
        $before = $query->compile();

        try {
            $query->where($db->raw('LOWER(email)'), 'REGEXP', 'example');
            self::fail('An unsupported expression comparison operator was accepted.');
        } catch (InvalidQueryException) {
            self::assertEquals($before, $query->compile());
        }

        $clone = clone $query;
        $clone->where($db->raw('LOWER(email)'), '=', 'ada@example.test');
        self::assertEquals($before, $query->compile());
        self::assertNotEquals($before, $clone->compile());
    }

    #[DataProvider('invalidExpressionFactories')]
    public function testInvalidExpressionComparisonShapesAreRejected(callable $factory): void
    {
        $this->expectException(InvalidQueryException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidExpressionFactories(): iterable
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        yield 'empty trusted expression' => [static fn () => $db->raw('   ')];
        yield 'keyed expression bindings' => [static fn () => (new \ReflectionMethod($db, 'raw'))->invoke(
            $db,
            'LOWER(?)',
            ['value' => 'x'],
        )];
        yield 'expression ordering against null' => [
            static fn () => $db->table('users')->where($db->raw('LOWER(email)'), '>', null),
        ];
        yield 'invalid column operator' => [
            static fn () => $db->table('users')->whereColumn('owner_id', 'IS', 'user_id'),
        ];
        yield 'two raw join operands' => [
            static fn () => (new JoinClause())->on($db->raw('LOWER(a)'), '=', $db->raw('LOWER(b)')),
        ];
        yield 'null join expression value' => [
            static fn () => (new JoinClause())->onValue($db->raw('LOWER(a)'), '=', null),
        ];
    }

    /** @return iterable<string, array{Driver}> */
    public static function drivers(): iterable
    {
        yield 'MariaDB' => [Driver::MariaDb];
        yield 'MySQL' => [Driver::MySql];
        yield 'SQLite' => [Driver::Sqlite];
    }
}
