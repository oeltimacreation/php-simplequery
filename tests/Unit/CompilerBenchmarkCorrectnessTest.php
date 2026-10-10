<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;
use Oeltima\SimpleQuery\Benchmark\ScenarioName;
use Oeltima\SimpleQuery\Benchmark\ScenarioRequest;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompilerBenchmarkCorrectnessTest extends TestCase
{
    #[DataProvider('compilerScenarios')]
    public function testEveryCompilerOperationIncludesAllBindingsInItsDigest(string $scenario): void
    {
        $prepared = ScenarioCatalog::prepare(new ScenarioRequest(ScenarioName::from(['name' => $scenario]), 'ci'));
        $expectedDigest = null;
        foreach ($prepared['operations'] as $operation) {
            $result = $operation();
            self::assertIsArray($result);
            self::assertArrayHasKey('query', $result);
            $query = $result['query'];
            self::assertInstanceOf(CompiledQuery::class, $query);
            self::assertGreaterThanOrEqual(3, count($query->bindings));
            $digest = Harness::digest($result);
            $expectedDigest ??= $digest;
            self::assertSame($expectedDigest, $digest);

            $bindings = $query->bindings;
            $bindings[1] = Binding::fromValue('changed-middle-value');
            $changed = $result;
            $changed['query'] = new CompiledQuery($query->sql, array_values($bindings));
            self::assertNotSame($digest, Harness::digest($changed));

            $bindings[1] = new Binding('changed-middle-value', ParameterType::Binary);
            $changedType = $result;
            $changedType['query'] = new CompiledQuery($query->sql, array_values($bindings));
            self::assertNotSame(Harness::digest($changed), Harness::digest($changedType));

            $bindings = $query->bindings;
            [$bindings[0], $bindings[1]] = [$bindings[1], $bindings[0]];
            $reordered = $result;
            $reordered['query'] = new CompiledQuery($query->sql, $bindings);
            if (
                $query->bindings[0]->value !== $query->bindings[1]->value
                || $query->bindings[0]->type !== $query->bindings[1]->type
            ) {
                self::assertNotSame($digest, Harness::digest($reordered));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function compilerScenarios(): iterable
    {
        foreach (
            [
                'compiler_predicates_10', 'compiler_shapes', 'compile_allocation', 'compiler_repeated',
                'compiler_in_list_high', 'compiler_batch_insert_high', 'compiler_attribution',
            ] as $scenario
        ) {
            yield $scenario => [$scenario];
        }
    }
}
