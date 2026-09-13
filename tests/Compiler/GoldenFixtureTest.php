<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use LogicException;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\ParameterType;
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
        array $expectedTypes,
    ): void {
        $compiled = (new GoldenQueryCases())->cases()[$case]();

        self::assertSame($expectedSql, $compiled->sql);
        self::assertSame(
            $expectedBindings,
            array_map(static fn (Binding $binding): mixed => $binding->value, $compiled->bindings),
        );
        self::assertSame(
            $expectedTypes,
            array_map(static fn (Binding $binding): ParameterType => $binding->type, $compiled->bindings),
        );
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
                $types = $case['types'] ?? null;
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
        $fixtures = [];
        foreach (Driver::cases() as $driver) {
            $fixtures[$driver->value] = self::readFixture($driver->value . '.json');
        }
        (new \Oeltima\SimpleQuery\Tools\Quality\CompilerFixtureValidator())->validate(
            $fixtures,
            array_keys((new GoldenQueryCases())->cases()),
            self::readFixture('feature-coverage.json'),
            array_keys((new GoldenQueryCases())->rejections()),
        );
        self::addToAssertionCount(1);
    }

    public function testUnsupportedFeatureReferencesExecuteRejectionCases(): void
    {
        foreach ((new GoldenQueryCases())->rejections() as $reject) {
            try {
                $reject();
                self::fail('An unsupported feature unexpectedly compiled.');
            } catch (\Oeltima\SimpleQuery\Exception\UnsupportedFeatureException) {
                self::addToAssertionCount(1);
            }
        }
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
