<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Closure;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDO;
use PDOStatement;
use RuntimeException;

final class ScenarioCatalog
{
    /** @return list<string> */
    public static function suite(string $suite): array
    {
        return match ($suite) {
            'baseline' => [
                'pdo_control_10',
                'pdo_control_100',
                'pdo_control_1000',
                'pdo_control_5000',
                'compiler_predicates_10',
                'compiler_predicates_100',
                'compiler_predicates_1000',
                'migration_query',
            ],
            'compiler' => [
                'compiler_shapes',
                'compiler_predicates_10',
                'compiler_predicates_100',
                'compiler_predicates_1000',
                'batch_compile',
            ],
            'executor' => [
                'hydration',
                'cursor_exhaustion',
                'cursor_early_close',
                'read_terminals',
                'observer',
                'batch_execute',
                'transactions',
                'lifecycle',
                'migration_query',
            ],
            'migration' => ['migration_query'],
            'soak' => ['compiler_repeated', 'lifecycle_soak'],
            'ci', 'full' => array_values(array_unique(array_merge(
                self::suite('baseline'),
                self::suite('compiler'),
                self::suite('executor'),
            ))),
            default => throw new RuntimeException(sprintf('Unknown benchmark suite "%s".', $suite)),
        };
    }

    /**
     * Fixture setup is complete before this method returns its timed operations.
     *
     * @return array{
     *     operations: array<non-empty-string, Closure(): mixed>,
     *     pdo: PDO|null,
     *     dimensions: array<string, int>
     * }
     */
    public static function prepare(string $name, string $profile): array
    {
        $reference = $profile === 'reference';

        if (preg_match('/^pdo_control_(10|100|1000|5000)$/', $name, $matches) === 1) {
            return self::pdoControl((int) $matches[1]);
        }
        if (preg_match('/^compiler_predicates_(10|100|1000)$/', $name, $matches) === 1) {
            return self::compilerPredicates((int) $matches[1]);
        }

        return match ($name) {
            'compiler_shapes' => self::compilerShapes($reference ? 250 : 50),
            'compiler_repeated' => self::compilerRepeated($reference ? 20_000 : 2_000),
            'batch_compile' => self::batchCompile($reference ? 1_000 : 200),
            'hydration' => self::hydration($reference ? 100_000 : 2_000),
            'cursor_exhaustion' => self::cursorExhaustion($reference ? 100_000 : 2_000),
            'cursor_early_close' => self::cursorEarlyClose($reference ? 100_000 : 2_000),
            'read_terminals' => self::readTerminals($reference ? 10_000 : 1_000),
            'observer' => self::observer($reference ? 1_000 : 100),
            'batch_execute' => self::batchExecute($reference ? 1_000 : 100),
            'transactions' => self::transactions($reference ? 100 : 20),
            'lifecycle' => self::lifecycle($reference ? 100 : 20),
            'lifecycle_soak' => self::lifecycle($reference ? 5_000 : 500),
            'migration_query' => self::migration(),
            default => throw new RuntimeException(sprintf('Unknown benchmark scenario "%s".', $name)),
        };
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function pdoControl(int $rows): array
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE benchmark_rows (id INTEGER PRIMARY KEY, value_text TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO benchmark_rows (id, value_text) VALUES (?, ?)');
        $generation = 0;

        $operation = Closure::fromCallable(static function () use ($pdo, $insert, $rows, &$generation): array {
            ++$generation;
            $base = $generation * $rows;
            $pdo->beginTransaction();
            for ($row = 1; $row <= $rows; ++$row) {
                $insert->execute([$base + $row, 'value-' . $row]);
            }
            $pdo->commit();
            $statement = $pdo->prepare(
                'SELECT id, value_text FROM benchmark_rows WHERE id > ? AND id <= ? ORDER BY id',
            );
            $statement->execute([$base, $base + $rows]);
            $result = $statement->fetchAll();

            return [
                'row_count' => count($result),
                'first_value' => $result[0]['value_text'] ?? null,
                'last_value' => $result[$rows - 1]['value_text'] ?? null,
            ];
        });

        return self::prepared(['pdo' => $operation], $pdo, ['rows' => $rows]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: null, dimensions: array<string, int>} */
    private static function compilerPredicates(int $predicates): array
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $operation = static function () use ($connection, $predicates): array {
            $query = $connection->table('benchmark_rows')->select('id');
            for ($predicate = 0; $predicate < $predicates; ++$predicate) {
                $query->where('id', '>=', $predicate);
            }
            $compiled = $query->compile();

            return [
                'sql_hash' => hash('sha256', $compiled->sql),
                'binding_count' => count($compiled->bindings),
                'first_binding' => $compiled->bindings[0]->value ?? null,
                'last_binding' => $compiled->bindings[$predicates - 1]->value ?? null,
            ];
        };

        return self::prepared(['simplequery' => $operation], null, ['predicates' => $predicates]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: null, dimensions: array<string, int>} */
    private static function compilerShapes(int $width): array
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $operation = static function () use ($connection, $width): array {
            $subquery = $connection->table('roles')->select('user_id')->where('enabled', true);
            $query = $connection
                ->table('users', 'u')
                ->select('u.id', $connection->raw('COALESCE(u.score, ?) AS score', [0]))
                ->join('profiles', 'profiles.user_id', '=', 'u.id')
                ->where(static function (ConditionGroup $group) use ($width): void {
                    for ($index = 0; $index < $width; ++$index) {
                        $group->orWhere('u.rank', '>=', $index);
                    }
                })
                ->whereIn('u.id', range(1, $width))
                ->whereIn('u.id', $subquery)
                ->orderBy('u.id');
            $compiled = $query->compile();

            return [
                'sql_hash' => hash('sha256', $compiled->sql),
                'binding_count' => count($compiled->bindings),
                'first_binding' => $compiled->bindings[0]->value ?? null,
                'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
            ];
        };

        return self::prepared(['build_and_compile' => $operation], null, ['shape_width' => $width]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: null, dimensions: array<string, int>} */
    private static function compilerRepeated(int $compiles): array
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $query = $connection->table('events')->where('active', true)->whereIn('kind', ['a', 'b', 'c']);
        $operation = static function () use ($query, $compiles): array {
            $last = $query->compile();
            for ($index = 1; $index < $compiles; ++$index) {
                $last = $query->compile();
            }

            return ['compiles' => $compiles, 'sql_hash' => hash('sha256', $last->sql)];
        };

        return self::prepared(['repeated_compile' => $operation], null, ['compiles' => $compiles]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: null, dimensions: array<string, int>} */
    private static function batchCompile(int $rows): array
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $fixture = [];
        for ($index = 1; $index <= $rows; ++$index) {
            $fixture[] = ['id' => $index, 'label' => 'row-' . $index, 'enabled' => $index % 2 === 0];
        }
        $operation = static function () use ($connection, $fixture, $rows): array {
            $compiled = CompiledWriteQuery::insertMany($connection->table('batch_rows'), $fixture);

            return [
                'binding_count' => count($compiled->bindings),
                'expected_bindings' => $rows * 3,
                'sql_hash' => hash('sha256', $compiled->sql),
            ];
        };

        return self::prepared(['insert_many_compile' => $operation], null, ['rows' => $rows, 'columns' => 3]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function hydration(int $rows): array
    {
        [$connection, $pdo] = self::rowFixture($rows);
        $direct = static fn (): array => self::query(
            $pdo,
            'SELECT id, category, payload FROM benchmark_rows ORDER BY id',
        )->fetchAll(PDO::FETCH_ASSOC);
        $associative = static fn (): array => $connection->table('benchmark_rows')->orderBy('id')->getAssociative();
        $objects = static function () use ($connection): array {
            return array_map(
                static fn (object $row): array => get_object_vars($row),
                $connection->table('benchmark_rows')->orderBy('id')->get(),
            );
        };

        return self::prepared(
            ['pdo_associative' => $direct, 'simplequery_associative' => $associative, 'simplequery_object' => $objects],
            $pdo,
            ['rows' => $rows, 'payload_bytes' => 96],
        );
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function cursorExhaustion(int $rows): array
    {
        [$connection, $pdo] = self::rowFixture($rows);
        $associative = static function () use ($connection): array {
            $result = [];
            foreach ($connection->table('benchmark_rows')->orderBy('id')->iterateAssociative() as $row) {
                $result[] = $row;
            }

            return $result;
        };
        $objects = static function () use ($connection): array {
            $result = [];
            foreach ($connection->table('benchmark_rows')->orderBy('id')->iterate() as $row) {
                if (!is_object($row)) {
                    throw new RuntimeException('Object cursor returned a non-object row.');
                }
                $result[] = get_object_vars($row);
            }

            return $result;
        };

        return self::prepared(
            ['associative_cursor' => $associative, 'object_cursor' => $objects],
            $pdo,
            ['rows' => $rows, 'payload_bytes' => 96],
        );
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function cursorEarlyClose(int $rows): array
    {
        [$connection, $pdo] = self::rowFixture($rows);
        $operation = static function () use ($connection): array {
            $cursor = $connection->table('benchmark_rows')->orderBy('id')->iterateAssociative();
            $first = null;
            foreach ($cursor as $row) {
                $first = $row;
                break;
            }
            $cursor->close();

            return ['first' => $first, 'closed' => $cursor->isClosed()];
        };

        return self::prepared(['early_close' => $operation], $pdo, ['fixture_rows' => $rows, 'consumed_rows' => 1]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function readTerminals(int $rows): array
    {
        [$connection, $pdo] = self::rowFixture($rows);
        $simpleQuery = static function () use ($connection): array {
            return [
                'first' => $connection->table('benchmark_rows')->orderBy('id')->firstAssociative(),
                'count' => $connection->table('benchmark_rows')->count(),
                'sum' => $connection->table('benchmark_rows')->sum('id'),
            ];
        };
        $direct = static function () use ($pdo): array {
            $first = self::query($pdo, 'SELECT * FROM benchmark_rows ORDER BY id LIMIT 1')
                ->fetch(PDO::FETCH_ASSOC);

            return [
                'first' => $first,
                'count' => (int) self::query($pdo, 'SELECT COUNT(*) FROM benchmark_rows')->fetchColumn(),
                'sum' => self::query($pdo, 'SELECT SUM(id) FROM benchmark_rows')->fetchColumn(),
            ];
        };

        return self::prepared(['simplequery' => $simpleQuery, 'pdo' => $direct], $pdo, ['rows' => $rows]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function observer(int $calls): array
    {
        $off = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $noop = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: new class implements QueryObserver {
            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
            }
        });
        $recording = new RecordingQueryObserver(10);
        $recorded = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $recording);
        $operation = static function (Connection $connection) use ($calls): array {
            $value = null;
            for ($call = 0; $call < $calls; ++$call) {
                $value = $connection->query('SELECT ? AS value', [$call])->firstAssociative()['value'] ?? null;
            }

            return ['calls' => $calls, 'last' => $value];
        };

        return self::prepared([
            'observer_off' => static fn (): array => $operation($off),
            'observer_noop' => static fn (): array => $operation($noop),
            'observer_bounded_recording' => static fn (): array => $operation($recorded),
        ], $off->pdo(), ['calls_per_sample' => $calls]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function batchExecute(int $rows): array
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE batch_rows (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $fixture = [];
        for ($id = 1; $id <= $rows; ++$id) {
            $fixture[] = ['id' => $id, 'label' => 'row-' . $id];
        }
        $simpleQuery = static function () use ($connection, $fixture): array {
            return $connection->transaction(static function (Connection $database) use ($fixture): array {
                $affected = $database->table('batch_rows')->insertMany($fixture);
                $count = $database->table('batch_rows')->count();
                $database->query('DELETE FROM batch_rows')->execute();

                return ['affected' => $affected, 'count' => $count];
            });
        };
        $direct = static function () use ($pdo, $fixture): array {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO batch_rows (id, label) VALUES (?, ?)');
            foreach ($fixture as $row) {
                $statement->execute([$row['id'], $row['label']]);
            }
            $count = (int) self::query($pdo, 'SELECT COUNT(*) FROM batch_rows')->fetchColumn();
            $pdo->exec('DELETE FROM batch_rows');
            $pdo->commit();

            return ['affected' => count($fixture), 'count' => $count];
        };

        return self::prepared(['simplequery_insert_many' => $simpleQuery, 'pdo_prepared_loop' => $direct], $pdo, [
            'rows' => $rows,
        ]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function transactions(int $transactions): array
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $simpleQuery = static function () use ($connection, $transactions): array {
            $depth = null;
            for ($index = 0; $index < $transactions; ++$index) {
                $depth = $connection->transaction(static function (Connection $database): int {
                    return $database->transaction(static fn (Connection $nested): int => $nested->transactionDepth());
                });
            }

            return ['transactions' => $transactions, 'nested_depth' => $depth];
        };
        $direct = static function () use ($pdo, $transactions): array {
            for ($index = 0; $index < $transactions; ++$index) {
                $pdo->beginTransaction();
                $pdo->exec('SAVEPOINT benchmark_nested');
                $pdo->exec('RELEASE SAVEPOINT benchmark_nested');
                $pdo->commit();
            }

            return ['transactions' => $transactions, 'nested_depth' => 2];
        };

        return self::prepared(['simplequery_managed' => $simpleQuery, 'pdo_control' => $direct], $pdo, [
            'transactions_per_sample' => $transactions,
        ]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: null, dimensions: array<string, int>} */
    private static function lifecycle(int $loops): array
    {
        $simpleQuery = static function () use ($loops): array {
            $last = null;
            for ($index = 0; $index < $loops; ++$index) {
                $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
                $last = $connection->query('SELECT 1 AS value')->firstAssociative()['value'] ?? null;
                $connection->close();
            }

            return ['loops' => $loops, 'last' => $last];
        };
        $direct = static function () use ($loops): array {
            $last = null;
            for ($index = 0; $index < $loops; ++$index) {
                $pdo = self::pdo();
                $last = self::query($pdo, 'SELECT 1 AS value')->fetchColumn();
                unset($pdo);
            }

            return ['loops' => $loops, 'last' => $last];
        };

        return self::prepared(['simplequery' => $simpleQuery, 'pdo' => $direct], null, ['loops_per_sample' => $loops]);
    }

    /** @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: PDO, dimensions: array<string, int>} */
    private static function migration(): array
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE benchmark_teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec(
            'CREATE TABLE benchmark_members ('
            . 'id INTEGER PRIMARY KEY, team_id INTEGER NOT NULL, name TEXT NOT NULL, '
            . 'active INTEGER NOT NULL, score INTEGER)',
        );
        $connection->table('benchmark_teams')->insertMany([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
            ['id' => 3, 'name' => 'Gamma'],
        ]);
        $members = [];
        for ($id = 1; $id <= 500; ++$id) {
            $members[] = [
                'id' => $id,
                'team_id' => (($id - 1) % 3) + 1,
                'name' => 'Member ' . $id,
                'active' => $id % 4 !== 0,
                'score' => $id % 100,
            ];
        }
        $connection->table('benchmark_members')->insertMany($members);

        $simpleQuery = static function () use ($connection): array {
            return $connection
                ->table('benchmark_members', 'm')
                ->select(
                    'm.id',
                    Identifier::of('m.name')->as('member_name'),
                    Identifier::of('t.name')->as('team_name'),
                    'm.score',
                )
                ->join(Identifier::of('benchmark_teams')->as('t'), 't.id', '=', 'm.team_id')
                ->where('m.active', true)
                ->where('m.score', '>=', 50)
                ->orderBy('m.id')
                ->limit(100)
                ->getAssociative();
        };
        $direct = static function () use ($pdo): array {
            $statement = $pdo->prepare(
                'SELECT m.id, m.name AS member_name, t.name AS team_name, m.score FROM benchmark_members m '
                . 'INNER JOIN benchmark_teams t ON t.id = m.team_id '
                . 'WHERE m.active = ? AND m.score >= ? ORDER BY m.id LIMIT 100',
            );
            $statement->execute([1, 50]);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };

        return self::prepared(['simplequery' => $simpleQuery, 'pdo' => $direct], $pdo, [
            'fixture_rows' => 500,
            'result_rows' => 100,
        ]);
    }

    /** @return array{Connection, PDO} */
    private static function rowFixture(int $rows): array
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec(
            'CREATE TABLE benchmark_rows ('
            . 'id INTEGER PRIMARY KEY, category TEXT NOT NULL, payload TEXT NOT NULL)',
        );
        $insert = $pdo->prepare('INSERT INTO benchmark_rows (id, category, payload) VALUES (?, ?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= $rows; ++$id) {
            $insert->execute([$id, 'category-' . ($id % 10), str_repeat((string) ($id % 10), 96)]);
        }
        $pdo->commit();

        return [$connection, $pdo];
    }

    private static function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('The benchmark query did not return a statement.');
        }

        return $statement;
    }

    /**
     * @template T of PDO|null
     * @param array<non-empty-string, Closure(): mixed> $operations
     * @param T $pdo
     * @param array<string, int> $dimensions
     * @return array{operations: array<non-empty-string, Closure(): mixed>, pdo: T, dimensions: array<string, int>}
     */
    private static function prepared(array $operations, ?PDO $pdo, array $dimensions): array
    {
        return ['operations' => $operations, 'pdo' => $pdo, 'dimensions' => $dimensions];
    }
}
