<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoldenFixtureTest extends TestCase
{
    /** @param list<mixed> $expectedBindings */
    #[DataProvider('goldenCases')]
    public function testVersionedGoldenQuery(string $case, string $expectedSql, array $expectedBindings): void
    {
        $compiled = $this->compileCase($case);

        self::assertSame($expectedSql, $compiled->sql);
        self::assertSame(
            $expectedBindings,
            array_map(static fn ($binding) => $binding->value, $compiled->bindings),
        );
    }

    /** @return iterable<string, array{string, string, list<mixed>}> */
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
                self::assertIsString($id);
                self::assertIsString($sql);
                self::assertIsArray($bindings);
                self::assertTrue(array_is_list($bindings));
                yield $id => [$id, $sql, $bindings];
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
        return match ($case) {
            'mariadb-filter-order' => CompilerConnection::for(Driver::MariaDb)
                ->table('users')->select('id', 'email')->where('active', true)->orderBy('id', 'DESC')->limit(5)
                ->compile(),
            'mariadb-shared-lock' => CompilerConnection::for(Driver::MariaDb)
                ->table('jobs')->forShare()->noWait()->compile(),
            'mariadb-insert' => CompiledWriteQuery::insert(
                CompilerConnection::for(Driver::MariaDb)->table('users'),
                ['email' => 'fixture@example.test', 'active' => true],
            ),
            'mysql-list-filter' => CompilerConnection::for(Driver::MySql)
                ->table('users', 'u')->select('u.*')->whereIn('u.status', ['active', 'pending'])->compile(),
            'mysql-shared-lock' => CompilerConnection::for(Driver::MySql)
                ->table('jobs')->forShare()->skipLocked()->compile(),
            'mysql-update' => CompiledWriteQuery::update(
                CompilerConnection::for(Driver::MySql)->table('users')->where('id', 7),
                ['active' => false],
            ),
            'sqlite-null-empty-list' => CompilerConnection::for(Driver::Sqlite)
                ->table('users')->whereNull('deleted_at')->whereIn('id', [])->compile(),
            'sqlite-subquery' => $this->sqliteSubquery(),
            'sqlite-delete' => CompiledWriteQuery::delete(
                CompilerConnection::for(Driver::Sqlite)->table('users')->where('id', 7),
            ),
            default => throw new \LogicException(sprintf('Unknown golden fixture: %s.', $case)),
        };
    }

    private function sqliteSubquery(): CompiledQuery
    {
        $db = CompilerConnection::for(Driver::Sqlite);
        $roles = $db->table('roles')->select('user_id')->where('active', true);

        return $db->table('users')->whereIn('id', $roles)->compile();
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
