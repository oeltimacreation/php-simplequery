<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\NullPredicate;
use Oeltima\SimpleQuery\Internal\Compiler\CompilerFactory;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryBuilderBehaviorTest extends TestCase
{
    public function testRepeatedCompilationIsDeterministicAndDoesNotMutateState(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db->table('users')->where('active', true)->orderBy('id')->limit(5);

        $first = $query->compile();
        $second = $query->compile();

        self::assertNotSame($first, $second);
        self::assertEquals($first, $second);
        self::assertSame('SELECT * FROM "users" WHERE "active" = ? ORDER BY "id" ASC LIMIT 5', $first->sql);
    }

    public function testAmbiguousNonCountScalarAggregateShapesAreRejectedWithoutMutatingBuilder(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $compiler = CompilerFactory::for(Driver::Sqlite);
        $queries = [
            $db->table('payments')->distinct(),
            $db->table('payments')->groupBy('account_id'),
            $db->table('payments')->having('amount', '>', 0),
        ];

        foreach ($queries as $query) {
            foreach (['SUM', 'AVG', 'MIN', 'MAX'] as $function) {
                try {
                    $compiler->aggregate($query->snapshotForCompilation(), $function, Identifier::of('amount'));
                    self::fail(sprintf('%s unexpectedly accepted an ambiguous scalar shape.', $function));
                } catch (UnsupportedFeatureException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        self::assertSame('SELECT DISTINCT * FROM "payments"', $queries[0]->compile()->sql);
        self::assertSame('SELECT * FROM "payments" GROUP BY "account_id"', $queries[1]->compile()->sql);
        self::assertSame('SELECT * FROM "payments" HAVING "amount" > ?', $queries[2]->compile()->sql);
    }

    public function testFreshBuildersAndClonesHaveIndependentState(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $original = $db->table('users')->where('active', true);
        $clone = clone $original;
        $clone->where('role', 'admin');
        $fresh = $db->table('users');

        self::assertSame('SELECT * FROM "users" WHERE "active" = ?', $original->compile()->sql);
        self::assertSame(
            'SELECT * FROM "users" WHERE "active" = ? AND "role" = ?',
            $clone->compile()->sql,
        );
        self::assertSame('SELECT * FROM "users"', $fresh->compile()->sql);
    }

    public function testSnapshotForCompilationIsDeeplyIsolatedFromBuilderMutations(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db->table('users')->where('active', true);
        $snapshot = $query->snapshotForCompilation();

        $snapshot->where->add(new ConditionTerm(
            new NullPredicate(Identifier::of('deleted'), false),
            false,
        ));

        self::assertSame('SELECT * FROM "users" WHERE "active" = ?', $query->compile()->sql);
    }

    public function testSubqueryIsSnapshottedAndBindingsFollowSqlOccurrenceOrder(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $child = $db->table('roles')->select('user_id')->where('active', true);
        $parent = $db
            ->table('users')
            ->select($db->raw('? AS marker', ['projection']))
            ->whereIn('id', $child)
            ->where('tenant_id', 9);

        $child->where('name', 'later mutation');

        CompiledQueryAssertions::assertMatches(
            $parent->compile(),
            'SELECT ? AS marker FROM "users" WHERE "id" IN '
                . '(SELECT "user_id" FROM "roles" WHERE "active" = ?) AND "tenant_id" = ?',
            ['projection', 1, 9],
        );
        self::addToAssertionCount(1);
    }

    public function testDerivedSourceSnapshotMergesProjectionBeforeSourceBindings(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $child = $db->table('events')->select('user_id')->where('kind', 'login');
        $parent = $db->table($child, 'recent')->select($db->raw('? AS prefix', ['P']), 'recent.user_id');

        CompiledQueryAssertions::assertMatches(
            $parent->compile(),
            'SELECT ? AS prefix, "recent"."user_id" FROM '
                . '(SELECT "user_id" FROM "events" WHERE "kind" = ?) AS "recent"',
            ['P', 'login'],
        );
        self::addToAssertionCount(1);
    }

    public function testNullAndEmptyListPoliciesNeverEmitInvalidSql(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('items')
            ->where('deleted_at', null)
            ->where('archived_at', '!=', null)
            ->whereNot('published_at', null)
            ->whereIn('id', [])
            ->orWhereNotIn('category_id', []);

        self::assertSame(
            'SELECT * FROM "items" WHERE "deleted_at" IS NULL '
                . 'AND "archived_at" IS NOT NULL AND "published_at" IS NOT NULL '
                . 'AND 0 = 1 OR 1 = 1',
            $query->compile()->sql,
        );
        self::assertSame([], $query->compile()->bindings);
    }

    public function testTrustedRawBindingsRemainAtTheirExpressionPosition(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('metrics')
            ->select($db->raw('ROUND(value, ?) AS rounded', [2]))
            ->where($db->raw('created_at > ?', ['2026-01-01']))
            ->groupBy($db->raw('strftime(?, created_at)', ['%Y']))
            ->orderBy($db->raw('CASE WHEN value > ? THEN 0 ELSE 1 END', [100]));

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT ROUND(value, ?) AS rounded FROM "metrics" WHERE created_at > ? '
                . 'GROUP BY strftime(?, created_at) ORDER BY CASE WHEN value > ? THEN 0 ELSE 1 END ASC',
            [2, '2026-01-01', '%Y', 100],
        );
        self::addToAssertionCount(1);
    }

    public function testPredicateAndJoinConvenienceVariantsCompileInCallOrder(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('users')
            ->join('roles', 'roles.user_id', '=', 'users.id')
            ->innerJoin('flags', static function (JoinClause $join) use ($db): void {
                $join->on($db->raw('flags.user_id = users.id'))
                    ->orOn('flags.owner_id', '=', 'users.id')
                    ->orOnValue('flags.visible', '=', true);
            })
            ->where('tenant_id', 1)
            ->orWhere('tenant_id', 2)
            ->whereNot(static function (ConditionGroup $group): void {
                $group->where('blocked', true);
            })
            ->orWhereNot($db->raw('users.expired = ?', [true]))
            ->orWhereIn('status', ['active'])
            ->whereNotIn('kind', ['system'])
            ->orWhereBetween('score', 10, 20)
            ->orWhereNotNull('email');

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT * FROM "users" INNER JOIN "roles" ON "roles"."user_id" = "users"."id" '
                . 'INNER JOIN "flags" ON flags.user_id = users.id OR "flags"."owner_id" = "users"."id" '
                . 'OR "flags"."visible" = ? WHERE "tenant_id" = ? OR "tenant_id" = ? '
                . 'AND NOT (("blocked" = ?)) OR NOT (users.expired = ?) OR "status" IN (?) '
                . 'AND "kind" NOT IN (?) OR "score" BETWEEN ? AND ? OR "email" IS NOT NULL',
            [1, 1, 2, 1, 1, 'active', 'system', 10, 20],
        );
        self::addToAssertionCount(1);
    }

    public function testReplacementClausesAndIdempotentAliasesRemainStable(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db->table('users', 'u')->as('u')->distinct()->distinct();
        $query->limit(100)->limit(10)->offset(5)->offset(2);

        self::assertSame(
            'SELECT DISTINCT * FROM "users" AS "u" LIMIT 10 OFFSET 2',
            $query->compile()->sql,
        );
    }

    public function testIdentifierQuoteCharactersAreEscapedPerDialect(): void
    {
        $mysql = CompilerConnection::for(Driver::MySql);
        $sqlite = CompilerConnection::for(Driver::Sqlite);

        self::assertSame(
            'SELECT `odd``column` FROM `odd``table`',
            $mysql
                ->table(Identifier::fromSegments('odd`table'))
                ->select(Identifier::fromSegments('odd`column'))
                ->compile()
                ->sql,
        );
        self::assertSame(
            'SELECT "odd""column" FROM "odd""table"',
            $sqlite
                ->table(Identifier::fromSegments('odd"table'))
                ->select(Identifier::fromSegments('odd"column'))
                ->compile()
                ->sql,
        );
    }

    /** @param class-string<\Throwable> $expectedException */
    #[DataProvider('invalidQueryFactories')]
    public function testInvalidQueryShapesAreRejected(callable $factory, string $expectedException): void
    {
        $this->expectException($expectedException);
        $factory();
    }

    /** @return iterable<string, array{callable(): mixed, class-string<\Throwable>}> */
    public static function invalidQueryFactories(): iterable
    {
        $sqlite = CompilerConnection::for(Driver::Sqlite);
        $other = CompilerConnection::for(Driver::Sqlite);
        $mysql = CompilerConnection::for(Driver::MySql);

        yield 'cross connection in predicate' => [
            static fn () => $sqlite->table('users')->whereIn('id', $other->table('roles'))->compile(),
            InvalidQueryException::class,
        ];
        yield 'cross connection source' => [
            static fn () => $sqlite->table($other->table('users'), 'u'),
            InvalidQueryException::class,
        ];
        yield 'derived source without alias' => [
            static fn () => $sqlite->table($sqlite->table('users')),
            InvalidQueryException::class,
        ];
        yield 'conflicting source aliases' => [
            static fn () => $sqlite->table(Identifier::of('users')->as('u'), 'other'),
            InvalidQueryException::class,
        ];
        yield 'source alias changed' => [
            static fn () => $sqlite->table('users', 'u')->as('other'),
            InvalidQueryException::class,
        ];
        yield 'empty projection' => [
            static fn () => $sqlite->table('users')->select(),
            InvalidQueryException::class,
        ];
        yield 'empty group by' => [
            static fn () => $sqlite->table('users')->groupBy(),
            InvalidQueryException::class,
        ];
        yield 'wildcard outside projection' => [
            static fn () => $sqlite->table('users')->groupBy('users.*'),
            InvalidQueryException::class,
        ];
        yield 'empty where group' => [
            static fn () => $sqlite->table('users')->where(static function (ConditionGroup $group): void {
            }),
            InvalidQueryException::class,
        ];
        yield 'null ordering comparison' => [
            static fn () => $sqlite->table('users')->where('id', '>', null),
            InvalidQueryException::class,
        ];
        yield 'invalid operator' => [
            static fn () => $sqlite->table('users')->where('id', 'REGEXP', 'x'),
            InvalidQueryException::class,
        ];
        yield 'offset without limit' => [
            static fn () => $sqlite->table('users')->offset(1)->compile(),
            InvalidQueryException::class,
        ];
        yield 'negative limit' => [
            static fn () => $sqlite->table('users')->limit(-1),
            InvalidQueryException::class,
        ];
        yield 'negative offset' => [
            static fn () => $sqlite->table('users')->offset(-1),
            InvalidQueryException::class,
        ];
        yield 'modifier without lock' => [
            static fn () => $mysql->table('users')->noWait(),
            InvalidQueryException::class,
        ];
        yield 'conflicting lock modes' => [
            static fn () => $mysql->table('users')->forUpdate()->forShare(),
            InvalidQueryException::class,
        ];
        yield 'conflicting lock modifiers' => [
            static fn () => $mysql->table('users')->forUpdate()->noWait()->skipLocked(),
            InvalidQueryException::class,
        ];
        yield 'distinct lock shape' => [
            static fn () => $mysql->table('users')->distinct()->forUpdate()->compile(),
            UnsupportedFeatureException::class,
        ];
        yield 'raw projection lock shape' => [
            static fn () => $mysql->table('users')->select($mysql->raw('COUNT(*)'))->forUpdate()->compile(),
            UnsupportedFeatureException::class,
        ];
        yield 'empty join closure' => [
            static fn () => $sqlite->table('users')->join('roles', static function (JoinClause $join): void {
            }),
            InvalidQueryException::class,
        ];
        yield 'wildcard table source' => [
            static fn () => $sqlite->table(Identifier::wildcard()),
            InvalidQueryException::class,
        ];
    }

    #[DataProvider('invalidWriteFactories')]
    public function testInvalidWriteShapesAreRejected(callable $factory): void
    {
        $this->expectException(InvalidQueryException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidWriteFactories(): iterable
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        yield 'empty insert' => [static fn () => CompiledWriteQuery::insert($db->table('users'), [])];
        yield 'empty batch' => [static fn () => CompiledWriteQuery::insertMany($db->table('users'), [])];
        yield 'empty batch row' => [static fn () => CompiledWriteQuery::insertMany($db->table('users'), [[]])];
        yield 'different batch columns' => [static fn () => CompiledWriteQuery::insertMany(
            $db->table('users'),
            [['id' => 1], ['name' => 'A']],
        )];
        yield 'associative batch list' => [static fn () => CompiledWriteQuery::insertMany(
            $db->table('users'),
            ['row' => ['id' => 1]],
        )];
        yield 'empty update' => [static fn () => CompiledWriteQuery::update($db->table('users'), [])];
        yield 'qualified write column' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users'),
            ['users.id' => 1],
        )];
    }

    public function testUnsupportedWriteShapesAreRejectedWithoutDiscardingClauses(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $builder = $db->table('users')->where('id', 1)->orderBy('id');

        try {
            CompiledWriteQuery::update($builder, ['name' => 'changed']);
            self::fail('Ordered update should be rejected.');
        } catch (UnsupportedFeatureException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            'SELECT * FROM "users" WHERE "id" = ? ORDER BY "id" ASC',
            $builder->compile()->sql,
        );

        $this->expectException(UnsupportedFeatureException::class);
        CompiledWriteQuery::insert($db->table('users')->where('id', 1), ['name' => 'changed']);
    }

    public function testTwoAndThreeOperandNullComparisonsAreEquivalent(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $twoOperand = $db
            ->table('items')
            ->where('deleted_at', null)
            ->where('published_at', '<>', null);
        $threeOperand = $db
            ->table('items')
            ->where('deleted_at', '=', null)
            ->where('published_at', '!=', null);

        CompiledQueryAssertions::assertMatches(
            $twoOperand->compile(),
            'SELECT * FROM "items" WHERE "deleted_at" IS NULL AND "published_at" IS NOT NULL',
            [],
        );
        self::assertEquals($twoOperand->compile(), $threeOperand->compile());
    }

    public function testHavingNullComparisonsUseTheSameNullSemantics(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('accounts')
            ->groupBy('owner_id')
            ->having('closed_at', null)
            ->orHaving('reviewed_at', '!=', null);

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT * FROM "accounts" GROUP BY "owner_id" '
                . 'HAVING "closed_at" IS NULL OR "reviewed_at" IS NOT NULL',
            [],
        );
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidInsertReadClauseFactories(): iterable
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $mysql = CompilerConnection::for(Driver::MySql);

        yield 'insert projection' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->select('name'),
            ['name' => 'A'],
        )];
        yield 'insert distinct' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->distinct(),
            ['name' => 'A'],
        )];
        yield 'insert join' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->join('roles', 'roles.user_id', '=', 'users.id'),
            ['name' => 'A'],
        )];
        yield 'insert group' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->groupBy('name'),
            ['name' => 'A'],
        )];
        yield 'insert having' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->having('count', '>', 0),
            ['name' => 'A'],
        )];
        yield 'insert order' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->orderBy('id'),
            ['name' => 'A'],
        )];
        yield 'insert limit' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->limit(1),
            ['name' => 'A'],
        )];
        yield 'insert offset' => [static fn () => CompiledWriteQuery::insert(
            $db->table('users')->offset(1)->limit(1),
            ['name' => 'A'],
        )];
        yield 'insert lock' => [static fn () => CompiledWriteQuery::insert(
            $mysql->table('users')->forUpdate(),
            ['name' => 'A'],
        )];
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidUpdateDeleteReadClauseFactories(): iterable
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $mysql = CompilerConnection::for(Driver::MySql);

        yield 'update projection' => [static fn () => CompiledWriteQuery::update(
            $db->table('users')->select('name'),
            ['name' => 'A'],
        )];
        yield 'update join' => [static fn () => CompiledWriteQuery::update(
            $db->table('users')->join('roles', 'roles.user_id', '=', 'users.id'),
            ['name' => 'A'],
        )];
        yield 'update group' => [static fn () => CompiledWriteQuery::update(
            $db->table('users')->groupBy('name'),
            ['name' => 'A'],
        )];
        yield 'update having' => [static fn () => CompiledWriteQuery::update(
            $db->table('users')->having('count', '>', 0),
            ['name' => 'A'],
        )];
        yield 'update limit' => [static fn () => CompiledWriteQuery::update(
            $db->table('users')->limit(1),
            ['name' => 'A'],
        )];
        yield 'update lock' => [static fn () => CompiledWriteQuery::update(
            $mysql->table('users')->forUpdate(),
            ['name' => 'A'],
        )];
        yield 'delete projection' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->select('name'),
        )];
        yield 'delete distinct' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->distinct(),
        )];
        yield 'delete join' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->join('roles', 'roles.user_id', '=', 'users.id'),
        )];
        yield 'delete group' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->groupBy('name'),
        )];
        yield 'delete having' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->having('count', '>', 0),
        )];
        yield 'delete order' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->orderBy('id'),
        )];
        yield 'delete limit' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->limit(1),
        )];
        yield 'delete offset' => [static fn () => CompiledWriteQuery::delete(
            $db->table('users')->offset(1)->limit(1),
        )];
        yield 'delete lock' => [static fn () => CompiledWriteQuery::delete(
            $mysql->table('users')->forUpdate(),
        )];
    }

    #[DataProvider('invalidInsertReadClauseFactories')]
    public function testInsertValidationRejectsEveryReadClause(callable $write): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $write();
    }

    #[DataProvider('invalidUpdateDeleteReadClauseFactories')]
    public function testUpdateDeleteValidationRejectsEveryNonPredicateReadClause(callable $write): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $write();
    }

    public function testUpdateAndDeleteAcceptPredicatesButNotOtherReadClauses(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $predicated = $db->table('users')->where('tenant_id', 1);

        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::update($predicated, ['name' => 'changed']),
            'UPDATE "users" SET "name" = ? WHERE "tenant_id" = ?',
            ['changed', 1],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::delete($predicated),
            'DELETE FROM "users" WHERE "tenant_id" = ?',
            [1],
        );
        self::addToAssertionCount(1);
    }
}
