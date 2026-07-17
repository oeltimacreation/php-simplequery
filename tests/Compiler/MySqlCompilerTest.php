<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class MySqlCompilerTest extends TestCase
{
    public function testMySqlGoldenSelectIsIndependent(): void
    {
        $db = CompilerConnection::for(Driver::MySql);
        $query = $db
            ->table(Identifier::of('app.users')->as('u'))
            ->select('u.*')
            ->innerJoin('roles', static function (JoinClause $join): void {
                $join->on('roles.user_id', '=', 'u.id')->where('roles.active', true);
            })
            ->whereIn('u.status', ['active', 'pending'])
            ->whereNot('u.email', 'LIKE', '%@invalid.test')
            ->orderBy('u.id', 'desc')
            ->limit(10);

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT `u`.* FROM `app`.`users` AS `u` INNER JOIN `roles` '
                . 'ON `roles`.`user_id` = `u`.`id` AND `roles`.`active` = ? '
                . 'WHERE `u`.`status` IN (?, ?) AND NOT (`u`.`email` LIKE ?) '
                . 'ORDER BY `u`.`id` DESC LIMIT 10',
            [1, 'active', 'pending', '%@invalid.test'],
        );
        self::addToAssertionCount(1);
    }

    public function testMySqlUsesForShareLockSyntax(): void
    {
        $db = CompilerConnection::for(Driver::MySql);

        self::assertSame('SELECT * FROM `jobs` FOR SHARE', $db->table('jobs')->forShare()->compile()->sql);
        self::assertSame(
            'SELECT * FROM `jobs` FOR UPDATE NOWAIT',
            $db->table('jobs')->forUpdate()->noWait()->compile()->sql,
        );
    }

    public function testMySqlWriteGoldenQueries(): void
    {
        $db = CompilerConnection::for(Driver::MySql);

        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::insert($db->table('events'), ['kind' => 'login']),
            'INSERT INTO `events` (`kind`) VALUES (?)',
            ['login'],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::update($db->table('events')->where('id', 4), ['kind' => 'logout']),
            'UPDATE `events` SET `kind` = ? WHERE `id` = ?',
            ['logout', 4],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::delete($db->table('events')),
            'DELETE FROM `events`',
        );
        self::addToAssertionCount(3);
    }
}
