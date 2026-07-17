<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class SqliteCompilerTest extends TestCase
{
    public function testSqliteGoldenSelectUsesDoubleQuotedIdentifiers(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $query = $db
            ->table('users', 'u')
            ->select('u.id', 'p.display_name')
            ->leftJoin('profiles', static function (JoinClause $join): void {
                $join->on('profiles.user_id', '=', 'u.id');
            })
            ->where('u.active', true)
            ->orWhere(static function ($group): void {
                $group->whereNull('u.deleted_at')->where('u.status', '<>', 'blocked');
            })
            ->groupBy('u.id')
            ->having('u.id', '>', 0)
            ->orderBy('u.id')
            ->limit(20)
            ->offset(0);

        CompiledQueryAssertions::assertMatches(
            $query->compile(),
            'SELECT "u"."id", "p"."display_name" FROM "users" AS "u" '
                . 'LEFT JOIN "profiles" ON "profiles"."user_id" = "u"."id" '
                . 'WHERE "u"."active" = ? OR ("u"."deleted_at" IS NULL AND "u"."status" <> ?) '
                . 'GROUP BY "u"."id" HAVING "u"."id" > ? ORDER BY "u"."id" ASC LIMIT 20 OFFSET 0',
            [1, 'blocked', 0],
        );
        self::addToAssertionCount(1);
    }

    public function testSqliteRejectsTypedRowLocks(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        $this->expectException(UnsupportedFeatureException::class);
        $db->table('users')->forUpdate()->compile();
    }

    public function testSqliteWriteGoldenQueries(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::insertMany($db->table('users'), [
                ['name' => 'A', 'enabled' => true],
                ['name' => 'B', 'enabled' => false],
            ]),
            'INSERT INTO "users" ("name", "enabled") VALUES (?, ?), (?, ?)',
            ['A', 1, 'B', 0],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::update($db->table('users')->where('id', 1), ['name' => 'Updated']),
            'UPDATE "users" SET "name" = ? WHERE "id" = ?',
            ['Updated', 1],
        );
        CompiledQueryAssertions::assertMatches(
            CompiledWriteQuery::delete($db->table('users')->where('enabled', false)),
            'DELETE FROM "users" WHERE "enabled" = ?',
            [0],
        );
        self::addToAssertionCount(3);
    }
}
