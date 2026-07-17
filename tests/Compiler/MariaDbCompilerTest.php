<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\SortDirection;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class MariaDbCompilerTest extends TestCase
{
    public function testMariaDbGoldenSelectAndBindingOrder(): void
    {
        $db = CompilerConnection::for(Driver::MariaDb);
        $query = $db
            ->table('users', 'u')
            ->select('u.id', $db->raw('LOWER(?) AS normalized', ['FIRST']))
            ->distinct()
            ->leftJoin(Identifier::of('profiles')->as('p'), static function (JoinClause $join): void {
                $join
                    ->on('p.user_id', '=', 'u.id')
                    ->orOnValue('p.visible', '=', true);
            })
            ->where(static function ($group): void {
                $group->where('u.status', 'active')->orWhereNull('u.deleted_at');
            })
            ->whereBetween('u.age', 18, 65)
            ->groupBy('u.id', 'u.email')
            ->having('u.id', '>', 10)
            ->orHaving($db->raw('COUNT(*) > ?', [2]))
            ->orderBy($db->raw('FIELD(u.status, ?, ?)', ['active', 'pending']), SortDirection::Desc)
            ->limit(25)
            ->offset(5);

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT DISTINCT `u`.`id`, LOWER(?) AS normalized FROM `users` AS `u` '
                . 'LEFT JOIN `profiles` AS `p` ON `p`.`user_id` = `u`.`id` OR `p`.`visible` = ? '
                . 'WHERE (`u`.`status` = ? OR `u`.`deleted_at` IS NULL) AND `u`.`age` BETWEEN ? AND ? '
                . 'GROUP BY `u`.`id`, `u`.`email` HAVING `u`.`id` > ? OR COUNT(*) > ? '
                . 'ORDER BY FIELD(u.status, ?, ?) DESC LIMIT 25 OFFSET 5',
            ['FIRST', 1, 'active', 18, 65, 10, 2, 'active', 'pending'],
        );
        self::addToAssertionCount(1);
    }

    public function testMariaDbUsesItsSharedLockSyntax(): void
    {
        $db = CompilerConnection::for(Driver::MariaDb);

        self::assertSame(
            'SELECT * FROM `jobs` WHERE `ready` = ? LOCK IN SHARE MODE NOWAIT',
            $db->table('jobs')->where('ready', true)->forShare()->noWait()->compile()->sql,
        );
        self::assertSame(
            'SELECT * FROM `jobs` FOR UPDATE SKIP LOCKED',
            $db->table('jobs')->forUpdate()->skipLocked()->compile()->sql,
        );
    }

    public function testMariaDbWriteGoldenQueries(): void
    {
        $db = CompilerConnection::for(Driver::MariaDb);
        $table = $db->table('users');

        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::insert($table, [
                'email' => 'a@example.test',
                'active' => true,
                'created_at' => $db->raw('CURRENT_TIMESTAMP'),
            ]),
            'INSERT INTO `users` (`email`, `active`, `created_at`) VALUES (?, ?, CURRENT_TIMESTAMP)',
            ['a@example.test', 1],
            [ParameterType::String, ParameterType::Integer],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::insertMany($table, [
                ['email' => 'a@example.test', 'active' => true],
                ['email' => 'b@example.test', 'active' => false],
            ]),
            'INSERT INTO `users` (`email`, `active`) VALUES (?, ?), (?, ?)',
            ['a@example.test', 1, 'b@example.test', 0],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::update(
                $db->table('users')->where('id', 7),
                ['email' => new Binding('updated@example.test', ParameterType::String)],
            ),
            'UPDATE `users` SET `email` = ? WHERE `id` = ?',
            ['updated@example.test', 7],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::delete($db->table('users')->whereNotNull('deleted_at')),
            'DELETE FROM `users` WHERE `deleted_at` IS NOT NULL',
        );
        self::addToAssertionCount(4);
    }
}
