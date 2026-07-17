<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compatibility;

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

    public function testAggregateContractCoversPrecisionAndOverflow(): void
    {
        $fixture = $this->readJson('tests/Fixtures/Contracts/aggregate-scalars.json');
        $encoded = json_encode($fixture, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('NumericOverflowException', $encoded);
        self::assertStringContainsString('1234567890.1234567890', $encoded);
        self::assertStringContainsString('preserve_driver_scalar', $encoded);
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

    public function testMigrationCorpusCoversRequiredConsumerStyles(): void
    {
        $fixture = $this->readJson('tests/Fixtures/Migration/v1.json');
        $fixtures = $fixture['fixtures'] ?? null;

        self::assertIsArray($fixtures);
        self::assertGreaterThanOrEqual(8, count($fixtures));
        $encoded = json_encode($fixtures, JSON_THROW_ON_ERROR);
        $requiredCases = [
            'clone_isolation',
            'ordered_bindings',
            'insert_get_id_string',
            'external_transaction_ownership',
        ];
        foreach ($requiredCases as $case) {
            self::assertStringContainsString($case, $encoded);
        }
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
