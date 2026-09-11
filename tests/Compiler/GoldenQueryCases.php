<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\SortDirection;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

final class GoldenQueryCases
{
    /** @return array<string, \Closure(): CompiledQuery> */
    public function cases(): array
    {
        $cases = [...$this->mariaDbCases(), ...$this->mySqlCases(), ...$this->sqliteCases()];
        foreach (Driver::cases() as $driver) {
            $cases[$driver->value . '-composition'] = fn (): CompiledQuery => $this->composition($driver);
        }

        return $cases;
    }

    /** @return array<string, \Closure(): CompiledQuery> */
    public function rejections(): array
    {
        return [
            'sqlite-row-locks' => static fn (): CompiledQuery => CompilerConnection::for(Driver::Sqlite)
                ->table('items')->forUpdate()->compile(),
        ];
    }

    private function composition(Driver $driver): CompiledQuery
    {
        $db = CompilerConnection::for($driver);
        $child = $db->table('items')->whereBetween('id', 1, 9);
        $original = $db->table($child, 'snapshot')->select('snapshot.id')->distinct()
            ->innerJoin('owners', static fn (JoinClause $join) => $join->on('owners.id', '=', 'snapshot.id'))
            ->leftJoin('profiles', static fn (JoinClause $join) => $join->on('profiles.id', '=', 'snapshot.id'))
            ->where(static fn ($group) => $group->where('snapshot.id', '>', 2)->orWhereNull('snapshot.id'))
            ->whereIn('snapshot.id', [])->groupBy('snapshot.id')->having($db->raw('COUNT(*)'), '>', 0)
            ->orderBy('snapshot.id')->limit(3)->offset(1);
        $copy = clone $original;
        $child->where('discarded', 99);
        $original->where('discarded', 98);

        return $copy->compile();
    }

    /** @return array<string, \Closure(): CompiledQuery> */
    private function mariaDbCases(): array
    {
        return [
            'mariadb-list-filter' => fn (): CompiledQuery => CompilerConnection::for(Driver::MariaDb)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'mariadb-filter-order' => fn (): CompiledQuery => CompilerConnection::for(Driver::MariaDb)
                ->table('users')->select('id', 'email')->where('active', true)->orderBy('id', 'DESC')->limit(5)
                ->compile(),
            'mariadb-shared-lock' => fn (): CompiledQuery => CompilerConnection::for(Driver::MariaDb)
                ->table('jobs')->forShare()->noWait()->compile(),
            'mariadb-insert' => fn (): CompiledQuery => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                ['email' => 'fixture@example.test', 'active' => true],
            ),
            'mariadb-golden-select' => fn (): CompiledQuery => $this->mariadbGoldenSelect(),
            'mariadb-insert-raw' => fn (): CompiledQuery => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                [
                    'email' => 'a@example.test',
                    'active' => true,
                    'created_at' => CompilerConnection::for(Driver::MariaDb)->raw('CURRENT_TIMESTAMP'),
                ],
            ),
            'mariadb-insert-many' => fn (): CompiledQuery => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                [
                    ['email' => 'a@example.test', 'active' => true],
                    ['email' => 'b@example.test', 'active' => false],
                ],
            ),
            'mariadb-update' => fn (): CompiledQuery => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MariaDb)->table('users')->where('id', 7),
                ['email' => new Binding('updated@example.test', ParameterType::String)],
            ),
            'mariadb-delete' => fn (): CompiledQuery => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::MariaDb)->table('users')->whereNotNull('deleted_at'),
            ),
        ];
    }

    /** @return array<string, \Closure(): CompiledQuery> */
    private function mySqlCases(): array
    {
        return [
            'mysql-list-filter' => fn (): CompiledQuery => CompilerConnection::for(Driver::MySql)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'mysql-shared-lock' => fn (): CompiledQuery => CompilerConnection::for(Driver::MySql)
                ->table('jobs')->forShare()->skipLocked()->compile(),
            'mysql-update' => fn (): CompiledQuery => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MySql)->table('users')->where('id', 7),
                ['active' => false],
            ),
            'mysql-golden-select' => fn (): CompiledQuery => $this->mysqlGoldenSelect(),
            'mysql-insert' => fn (): CompiledQuery => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MySql)->table('events'),
                ['kind' => 'login'],
            ),
            'mysql-insert-many' => fn (): CompiledQuery => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::MySql)->table('events'),
                [
                    ['kind' => 'login', 'priority' => 1],
                    ['kind' => 'logout', 'priority' => 2],
                ],
            ),
            'mysql-update-events' => fn (): CompiledQuery => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MySql)->table('events')->where('id', 4),
                ['kind' => 'logout'],
            ),
            'mysql-delete' => fn (): CompiledQuery => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::MySql)->table('events'),
            ),
        ];
    }

    /** @return array<string, \Closure(): CompiledQuery> */
    private function sqliteCases(): array
    {
        return [
            'sqlite-null-empty-list' => fn (): CompiledQuery => CompilerConnection::for(Driver::Sqlite)
                ->table('users')->whereNull('deleted_at')->whereIn('id', [])->compile(),
            'sqlite-list-filter' => fn (): CompiledQuery => CompilerConnection::for(Driver::Sqlite)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'sqlite-subquery' => fn (): CompiledQuery => $this->sqliteSubquery(),
            'sqlite-delete' => fn (): CompiledQuery => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('id', 7),
            ),
            'sqlite-golden-select' => fn (): CompiledQuery => $this->sqliteGoldenSelect(),
            'sqlite-insert' => fn (): CompiledQuery => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::Sqlite)->table('users'),
                ['name' => 'A', 'enabled' => true],
            ),
            'sqlite-insert-many' => fn (): CompiledQuery => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::Sqlite)->table('users'),
                [
                    ['name' => 'A', 'enabled' => true],
                    ['name' => 'B', 'enabled' => false],
                ],
            ),
            'sqlite-update' => fn (): CompiledQuery => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('id', 1),
                ['name' => 'Updated'],
            ),
            'sqlite-delete-enabled' => fn (): CompiledQuery => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('enabled', false),
            ),
        ];
    }

    private function sqliteSubquery(): CompiledQuery
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $roles = $db->table('roles')->select('user_id')->where('active', true);

        return $db->table('users')->whereIn('id', $roles)->compile();
    }

    private function mariadbGoldenSelect(): CompiledQuery
    {
        $db = CompilerConnection::for(Driver::MariaDb);

        return $db
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
            ->offset(5)
            ->compile();
    }

    private function mysqlGoldenSelect(): CompiledQuery
    {
        $db = CompilerConnection::for(Driver::MySql);

        return $db
            ->table(Identifier::of('app.users')->as('u'))
            ->select('u.*')
            ->innerJoin('roles', static function (JoinClause $join): void {
                $join->on('roles.user_id', '=', 'u.id')->where('roles.active', true);
            })
            ->whereIn('u.status', ['active', 'pending'])
            ->whereNot('u.email', 'LIKE', '%@invalid.test')
            ->orderBy('u.id', 'desc')
            ->limit(10)
            ->compile();
    }

    private function sqliteGoldenSelect(): CompiledQuery
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        return $db
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
            ->offset(0)
            ->compile();
    }
}
