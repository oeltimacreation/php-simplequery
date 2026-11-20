<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Closure;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredExistsTest extends TestCase
{
    /**
     * @param Closure(Connection): QueryBuilder $childFactory
     * @param class-string<InvalidQueryException|UnsupportedFeatureException> $exceptionClass
     */
    #[DataProvider('rejectedChildren')]
    public function testInvalidChildLeavesParentUnchanged(
        Closure $childFactory,
        string $exceptionClass,
        string $message,
    ): void {
        $db = CompilerConnection::for(Driver::MySql);
        $child = $childFactory($db)->where('label', 'bound-marker');
        $parent = $db->table('owners')->where('active', true);
        $before = $parent->compile();
        $attachments = [
            static fn () => $parent->orWhereExists($child),
            static fn () => $parent->where(static fn (ConditionGroup $group) => $group
                ->where('name', 'earlier')->orWhereNotExists($child)),
        ];

        foreach ($attachments as $attach) {
            try {
                $attach();
                self::fail('An invalid child was attached.');
            } catch (InvalidQueryException | UnsupportedFeatureException $failure) {
                self::assertInstanceOf($exceptionClass, $failure);
                self::assertSame($message, $failure->getMessage());
            }
            self::assertEquals($before, $parent->compile());
        }
    }

    /** @return iterable<string, array{
     *     Closure(Connection): QueryBuilder, class-string<InvalidQueryException|UnsupportedFeatureException>, string
     * }> */
    public static function rejectedChildren(): iterable
    {
        yield 'ownership precedes other checks' => [
            static fn (Connection $_db) => CompilerConnection::for(Driver::MySql)
                ->table('orders')->forUpdate()->limit(0),
            InvalidQueryException::class,
            'Structured subqueries must belong to the same connection.',
        ];
        yield 'lock check' => [
            static fn (Connection $db) => $db->table('orders')->forShare()->limit(0),
            UnsupportedFeatureException::class,
            'Structured child queries cannot contain row-lock clauses.',
        ];
        $paginationMessage = 'Structured EXISTS child queries cannot contain LIMIT or OFFSET.';
        yield 'zero limit' => [
            static fn (Connection $db) => $db->table('orders')->limit(0),
            UnsupportedFeatureException::class,
            $paginationMessage,
        ];
        yield 'offset without limit' => [
            static fn (Connection $db) => $db->table('orders')->offset(0),
            UnsupportedFeatureException::class,
            $paginationMessage,
        ];
        yield 'page' => [
            static fn (Connection $db) => $db->table('orders')->forPage(2, 10),
            UnsupportedFeatureException::class,
            $paginationMessage,
        ];
    }

    #[DataProvider('predicatePaths')]
    public function testUnsupportedContextsRejectDirectAndNestedPredicates(string $path): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $child = $db->table('orders')->select('owner_id')->where('status', 'bound-marker');
        $withExists = $db->table('owners')->select('id')->whereExists($child);
        $derived = $db->table($withExists, 'snapshot')->select('id');
        $attach = static fn (QueryBuilder | ConditionGroup $parent) => match ($path) {
            'direct' => $parent->whereExists($child),
            'group' => $parent->where(static fn (ConditionGroup $group) => $group->whereNotExists($child)),
            'in' => $parent->whereIn('id', $withExists),
            default => $parent->orWhereNotIn('id', $derived),
        };

        $write = $db->table('owners')->where('active', true);
        $attach($write);
        $read = $write->compile();
        $this->assertUnsupported(
            static fn () => $write->update(['active' => false]),
            'Structured EXISTS predicates are not supported in update or delete queries.',
        );
        $this->assertUnsupported(
            $write->delete(...),
            'Structured EXISTS predicates are not supported in update or delete queries.',
        );
        self::assertEquals($read, $write->compile());

        $having = $db->table('owners')->groupBy('id')->having('id', '>', 0);
        $before = $having->compile();
        $this->assertUnsupported(
            static fn () => $having->orHaving($attach),
            'Structured EXISTS predicates are not supported in HAVING.',
        );
        self::assertEquals($before, $having->compile());
    }

    /** @return iterable<string, array{string}> */
    public static function predicatePaths(): iterable
    {
        foreach (['direct', 'group', 'in', 'derived-in'] as $path) {
            yield $path => [$path];
        }
    }

    public function testRawExistsAndPaginatedInRetainTheirExistingWriteShape(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $child = $db->table('orders')->select('owner_id')->where($db->raw('EXISTS (SELECT ?)', [7]))->limit(1);
        $query = $db->table('owners')->whereIn('id', $child);
        $compiled = CompiledWriteQuery::delete($query);

        self::assertSame(
            'DELETE FROM "owners" WHERE "id" IN (SELECT "owner_id" FROM "orders" WHERE EXISTS (SELECT ?) LIMIT 1)',
            $compiled->sql,
        );
        self::assertSame([7], array_column($compiled->bindings, 'value'));

        $having = $db->table('owners')->groupBy('id')
            ->having(static fn (ConditionGroup $group) => $group->where($db->raw('EXISTS (SELECT ?)', [7])))
            ->compile();
        self::assertSame('SELECT * FROM "owners" GROUP BY "id" HAVING (EXISTS (SELECT ?))', $having->sql);
        self::assertSame([7], array_column($having->bindings, 'value'));
    }

    /** @param Closure(): mixed $operation */
    private function assertUnsupported(Closure $operation, string $message): void
    {
        try {
            $operation();
            self::fail('A structured predicate was accepted in an unsupported context.');
        } catch (UnsupportedFeatureException $failure) {
            self::assertSame($message, $failure->getMessage());
        }
    }
}
