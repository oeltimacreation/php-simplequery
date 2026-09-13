<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MalformedConditionTest extends TestCase
{
    #[DataProvider('wrappers')]
    public function testRejectedArgumentsDoNotAttachPredicates(Driver $driver, string $kind, string $method): void
    {
        $db = CompilerConnection::for($driver);
        $target = match ($kind) {
            'group' => (new ConditionGroup($db))->where('id', 7),
            'join' => (new JoinClause())->on('a.id', '=', 'b.id'),
            default => $db->table('items')->where('id', 7),
        };
        $before = $target instanceof QueryBuilder ? $target->compile() : $target->snapshot();
        $raw = $db->raw('1 = ?', [1]);
        $hole = $kind === 'join' ? ['left' => $raw, 'right' => 'b.id'] : ['subject' => $raw, 'value' => 123];
        foreach ([$hole, [$raw, '=', 123, 'extra'], [$raw, 'unknown' => 123]] as $arguments) {
            try {
                (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
                self::fail('Malformed arguments were accepted.');
            } catch (InvalidQueryException) {
                self::assertEquals(
                    $before,
                    $target instanceof QueryBuilder ? $target->compile() : $target->snapshot(),
                );
            }
        }
    }

    /** @return iterable<string, array{Driver, string, string}> */
    public static function wrappers(): iterable
    {
        foreach (Driver::cases() as $driver) {
            foreach (['builder', 'group', 'join'] as $kind) {
                $methods = $kind === 'join' ? ['on', 'orOn'] : ['where', 'orWhere', 'whereNot', 'orWhereNot'];
                if ($kind === 'builder') {
                    $methods = [...$methods, 'having', 'orHaving'];
                }
                foreach ($methods as $method) {
                    yield $driver->value . '-' . $kind . '-' . $method => [$driver, $kind, $method];
                }
            }
        }
    }

    public function testNamedAndUnpackedCallsRetainValidSemantics(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db->table('items')->where(subject: $db->raw('1 = ?', [1]));
        $before = $query->compile();
        try {
            $query->where(subject: $db->raw('1 = 1'), value: 123);
            self::fail('A named argument hole was accepted.');
        } catch (InvalidQueryException) {
            self::assertEquals($before, $query->compile());
        }
        $query->where(...['subject' => $db->raw('x + ?', [2]), 'operatorOrValue' => 3]);
        $query->where(subject: $db->raw('y'), operatorOrValue: '=', value: null);
        self::assertSame('SELECT * FROM "items" WHERE 1 = ? AND x + ? = ? AND y IS NULL', $query->compile()->sql);
        self::assertSame([1, 2, 3], array_map(static fn ($binding) => $binding->value, $query->compile()->bindings));
    }
}
