<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compatibility;

use Oeltima\SimpleQuery\Exception\NumericOverflowException;
use Oeltima\SimpleQuery\Internal\AggregateResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicContractFixturesTest extends TestCase
{
    #[DataProvider('contractFiles')]
    public function testContractFixtureIsVersionedAndNonEmpty(string $relativePath, string $expectedContract): void
    {
        $fixture = $this->readJson($relativePath);

        self::assertSame(1, $fixture['schema_version'] ?? null);
        self::assertSame($expectedContract, $fixture['contract'] ?? null);
    }

    public function testAggregateContractCountCasesExecuteAgainstTheRuntimePolicy(): void
    {
        $fixture = $this->readJson('tests/Fixtures/Contracts/aggregate-scalars.json');
        $cases = $fixture['cases'] ?? null;
        self::assertIsArray($cases);

        $executed = 0;
        foreach ($cases as $case) {
            self::assertIsArray($case);
            if (($case['terminal'] ?? null) !== 'count') {
                continue;
            }
            $value = $case['database_value'] ?? null;
            self::assertIsString($value);
            ++$executed;

            if (($case['expected_exception'] ?? null) === 'NumericOverflowException') {
                try {
                    AggregateResult::count($value);
                    self::fail('The versioned overflow case unexpectedly succeeded.');
                } catch (NumericOverflowException) {
                    self::addToAssertionCount(1);
                }
                continue;
            }

            self::assertSame($case['expected_value'] ?? null, AggregateResult::count($value));
        }

        self::assertSame(3, $executed);
    }

    public function testConnectionContractFreezesRequiredDefaults(): void
    {
        $fixture = $this->readJson('tests/Fixtures/Contracts/connection-construction.json');
        $invariants = $fixture['hard_invariants'] ?? null;
        $defaults = $fixture['defaults'] ?? null;
        self::assertIsArray($invariants);
        self::assertIsArray($defaults);

        self::assertFalse($invariants['persistent'] ?? null);
        self::assertFalse($invariants['found_rows'] ?? null);
        self::assertSame(5000, $defaults['sqlite_busy_timeout_milliseconds'] ?? null);
        self::assertSame('utf8mb4', $invariants['mysql_charset'] ?? null);
        self::assertFalse($fixture['automatic_reconnect'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function contractFiles(): iterable
    {
        yield 'aggregate scalar contract' => [
            'tests/Fixtures/Contracts/aggregate-scalars.json',
            'aggregate_scalars',
        ];
        yield 'connection construction contract' => [
            'tests/Fixtures/Contracts/connection-construction.json',
            'connection_construction',
        ];
    }

    /** @return array<string, mixed> */
    private function readJson(string $relativePath): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
