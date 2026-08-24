<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use LogicException;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\SortDirection;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoldenFixtureTest extends TestCase
{
    /**
     * @param list<mixed> $expectedBindings
     * @param list<ParameterType> $expectedTypes
     */
    #[DataProvider('goldenCases')]
    public function testVersionedGoldenQuery(
        string $case,
        string $expectedSql,
        array $expectedBindings,
        array $expectedTypes = [],
    ): void {
        $compiled = $this->compileCase($case);

        self::assertSame($expectedSql, $compiled->sql);
        self::assertSame(
            $expectedBindings,
            array_map(static fn (Binding $binding): mixed => $binding->value, $compiled->bindings),
        );
        if ($expectedTypes !== []) {
            self::assertSame(
                $expectedTypes,
                array_map(static fn (Binding $binding): ParameterType => $binding->type, $compiled->bindings),
            );
        }
    }

    /** @return iterable<string, array{string, string, list<mixed>, list<ParameterType>}> */
    public static function goldenCases(): iterable
    {
        foreach (['mariadb', 'mysql', 'sqlite'] as $driver) {
            $fixture = self::readFixture($driver . '.json');
            self::assertSame(1, $fixture['schema_version'] ?? null);
            self::assertSame($driver, $fixture['driver'] ?? null);
            $cases = $fixture['cases'] ?? null;
            self::assertIsArray($cases);
            foreach ($cases as $case) {
                self::assertIsArray($case);
                $id = $case['id'] ?? null;
                $sql = $case['sql'] ?? null;
                $bindings = $case['bindings'] ?? null;
                $types = $case['types'] ?? [];
                self::assertIsString($id);
                self::assertIsString($sql);
                self::assertIsArray($bindings);
                self::assertTrue(array_is_list($bindings));
                self::assertIsArray($types);
                self::assertTrue(array_is_list($types));
                yield $id => [$id, $sql, $bindings, array_map(self::parseType(...), $types)];
            }
        }
    }

    public function testFeatureManifestCoversTheAcceptedCompilerSurface(): void
    {
        $fixture = self::readFixture('feature-coverage.json');
        $features = $fixture['features'] ?? null;
        self::assertIsArray($features);

        self::assertSame([
            'aliases',
            'between',
            'clone_isolation',
            'comparison_predicates',
            'delete',
            'derived_sources',
            'distinct',
            'empty_lists',
            'grouped_predicates',
            'grouping_and_having',
            'identifiers_and_wildcards',
            'inner_joins',
            'insert',
            'insert_many',
            'left_joins',
            'list_predicates',
            'null_predicates',
            'ordering_and_pagination',
            'raw_expressions',
            'row_locks',
            'subquery_snapshots',
            'update',
        ], $features);
    }

    private function compileCase(string $case): CompiledQuery
    {
        if (str_starts_with($case, 'mariadb-')) {
            return $this->compileMariaDbCase($case);
        }
        if (str_starts_with($case, 'mysql-')) {
            return $this->compileMySqlCase($case);
        }
        if (str_starts_with($case, 'sqlite-')) {
            return $this->compileSqliteCase($case);
        }

        throw new LogicException(sprintf('Unknown golden fixture: %s.', $case));
    }

    private function compileMariaDbCase(string $case): CompiledQuery
    {
        return match ($case) {
            'mariadb-list-filter' => CompilerConnection::for(Driver::MariaDb)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'mariadb-filter-order' => CompilerConnection::for(Driver::MariaDb)
                ->table('users')->select('id', 'email')->where('active', true)->orderBy('id', 'DESC')->limit(5)
                ->compile(),
            'mariadb-shared-lock' => CompilerConnection::for(Driver::MariaDb)
                ->table('jobs')->forShare()->noWait()->compile(),
            'mariadb-insert' => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                ['email' => 'fixture@example.test', 'active' => true],
            ),
            'mariadb-golden-select' => $this->mariadbGoldenSelect(),
            'mariadb-insert-raw' => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                [
                    'email' => 'a@example.test',
                    'active' => true,
                    'created_at' => CompilerConnection::for(Driver::MariaDb)->raw('CURRENT_TIMESTAMP'),
                ],
            ),
            'mariadb-insert-many' => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                [
                    ['email' => 'a@example.test', 'active' => true],
                    ['email' => 'b@example.test', 'active' => false],
                ],
            ),
            'mariadb-update' => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MariaDb)->table('users')->where('id', 7),
                ['email' => new Binding('updated@example.test', ParameterType::String)],
            ),
            'mariadb-delete' => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::MariaDb)->table('users')->whereNotNull('deleted_at'),
            ),
            default => $this->unknownCase($case),
        };
    }

    private function compileMySqlCase(string $case): CompiledQuery
    {
        return match ($case) {
            'mysql-list-filter' => CompilerConnection::for(Driver::MySql)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'mysql-shared-lock' => CompilerConnection::for(Driver::MySql)
                ->table('jobs')->forShare()->skipLocked()->compile(),
            'mysql-update' => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MySql)->table('users')->where('id', 7),
                ['active' => false],
            ),
            'mysql-golden-select' => $this->mysqlGoldenSelect(),
            'mysql-insert' => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MySql)->table('events'),
                ['kind' => 'login'],
            ),
            'mysql-insert-many' => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::MySql)->table('events'),
                [
                    ['kind' => 'login', 'priority' => 1],
                    ['kind' => 'logout', 'priority' => 2],
                ],
            ),
            'mysql-update-events' => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MySql)->table('events')->where('id', 4),
                ['kind' => 'logout'],
            ),
            'mysql-delete' => CompiledWriteQuery::delete(CompilerConnection::for(Driver::MySql)->table('events')),
            default => $this->unknownCase($case),
        };
    }

    private function compileSqliteCase(string $case): CompiledQuery
    {
        return match ($case) {
            'sqlite-null-empty-list' => CompilerConnection::for(Driver::Sqlite)
                ->table('users')->whereNull('deleted_at')->whereIn('id', [])->compile(),
            'sqlite-list-filter' => CompilerConnection::for(Driver::Sqlite)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'sqlite-subquery' => $this->sqliteSubquery(),
            'sqlite-delete' => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('id', 7),
            ),
            'sqlite-golden-select' => $this->sqliteGoldenSelect(),
            'sqlite-insert' => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::Sqlite)->table('users'),
                ['name' => 'A', 'enabled' => true],
            ),
            'sqlite-insert-many' => CompiledWriteQuery::insertMany(
                CompilerConnection::for(Driver::Sqlite)->table('users'),
                [
                    ['name' => 'A', 'enabled' => true],
                    ['name' => 'B', 'enabled' => false],
                ],
            ),
            'sqlite-update' => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('id', 1),
                ['name' => 'Updated'],
            ),
            'sqlite-delete-enabled' => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('enabled', false),
            ),
            default => $this->unknownCase($case),
        };
    }

    /** @return never */
    private function unknownCase(string $case): CompiledQuery
    {
        throw new LogicException(sprintf('Unknown golden fixture: %s.', $case));
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

    private static function parseType(string $type): ParameterType
    {
        return match ($type) {
            'null' => ParameterType::Null,
            'integer' => ParameterType::Integer,
            'string' => ParameterType::String,
            'binary' => ParameterType::Binary,
            'lob' => ParameterType::Lob,
            default => throw new LogicException(sprintf('Unknown golden binding type: %s.', $type)),
        };
    }

    /** @return array<string, mixed> */
    private static function readFixture(string $filename): array
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/Compiler/' . $filename);
        self::assertIsString($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        $result = [];
        foreach ($fixture as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
