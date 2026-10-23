<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockedSubqueryTest extends TestCase
{
    #[DataProvider('lockedChildren')]
    public function testLockedChildrenAreRejectedBeforeParentMutation(Driver $driver, string $mode): void
    {
        $db = CompilerConnection::for($driver);
        $child = $db->table('jobs')->select('id')->where('state', 'ready');
        $mode === 'forUpdate' ? $child->forUpdate() : $child->forShare();
        $parent = $db->table('owners')->where('active', true);
        $before = $parent->compile();
        $copy = clone $parent;

        foreach (['whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn'] as $method) {
            try {
                match ($method) {
                    'whereIn' => $parent->whereIn('id', $child),
                    'orWhereIn' => $parent->orWhereIn('id', $child),
                    'whereNotIn' => $parent->whereNotIn('id', $child),
                    default => $parent->orWhereNotIn('id', $child),
                };
                self::fail('A locked child was attached.');
            } catch (UnsupportedFeatureException $failure) {
                self::assertSame('Structured child queries cannot contain row-lock clauses.', $failure->getMessage());
            }
            self::assertEquals($before, $parent->compile());
            self::assertEquals($before, $copy->compile());
        }
        try {
            $db->table($child, 'locked');
            self::fail('A locked derived source was attached.');
        } catch (UnsupportedFeatureException) {
        }
        try {
            $parent->where(static fn (ConditionGroup $group) => $group->whereIn('id', $child));
            self::fail('A locked child was attached inside a group.');
        } catch (UnsupportedFeatureException) {
        }
        self::assertEquals($before, $parent->compile());
        $quote = $driver === Driver::Sqlite ? '"' : '`';
        self::assertSame(
            'DELETE FROM ' . $quote . 'owners' . $quote . ' WHERE ' . $quote . 'active' . $quote . ' = ?',
            CompiledWriteQuery::delete($parent)->sql,
        );
        self::assertSame(
            [1],
            array_map(static fn ($binding): mixed => $binding->value, CompiledWriteQuery::delete($parent)->bindings),
        );
    }

    /** @return iterable<string, array{Driver, string}> */
    public static function lockedChildren(): iterable
    {
        foreach (Driver::cases() as $driver) {
            foreach (['forUpdate', 'forShare'] as $mode) {
                yield $driver->value . '-' . $mode => [$driver, $mode];
            }
        }
    }

    #[DataProvider('drivers')]
    public function testLaterChildLocksCannotChangeAnAttachedSnapshot(Driver $driver): void
    {
        $db = CompilerConnection::for($driver);
        $child = $db->table('jobs')->select('id')->where('state', 'ready');
        $middle = $db->table($child, 'ready_jobs')->select('id');
        $parent = $db->table('owners')->select($db->raw('? AS marker', ['outer']))->whereIn('id', $middle);
        $copy = clone $parent;
        $before = $parent->compile();
        $child->forUpdate();
        $middle->forShare();
        self::assertEquals($before, $parent->compile());
        self::assertEquals($before, $copy->compile());
        self::assertSame(
            ['outer', 'ready'],
            array_map(static fn ($binding): mixed => $binding->value, $before->bindings),
        );
        $quote = $driver === Driver::Sqlite ? '"' : '`';
        self::assertSame(
            str_replace('`', $quote, 'SELECT ? AS marker FROM `owners` WHERE `id` IN '
                . '(SELECT `id` FROM (SELECT `id` FROM `jobs` WHERE `state` = ?) AS `ready_jobs`)'),
            $before->sql,
        );
    }

    #[DataProvider('drivers')]
    public function testCrossOwnerValidationStillPrecedesLockPolicy(Driver $driver): void
    {
        $db = CompilerConnection::for($driver);
        $other = CompilerConnection::for($driver);
        $child = $other->table('jobs')->forUpdate();
        $this->expectException(InvalidQueryException::class);
        $db->table('owners')->whereIn('id', $child);
    }

    /** @return iterable<string, array{Driver}> */
    public static function drivers(): iterable
    {
        foreach (Driver::cases() as $driver) {
            yield $driver->value => [$driver];
        }
    }
}
