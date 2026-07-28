<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class ProductionShapedAdoptionCorpusTest extends TestCase
{
    public function testManifestCoversRequiredAdoptionShapesWithoutPrivateMaterial(): void
    {
        $root = dirname(__DIR__) . '/Fixtures/Adoption';
        $contents = file_get_contents($root . '/manifest.json');
        self::assertIsString($contents);
        self::assertStringNotContainsString('/home/', $contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['schema_version'] ?? null);
        $requiredShapes = $manifest['required_shapes'] ?? null;
        self::assertIsArray($requiredShapes);
        foreach (
            [
                'long_structured_and_raw_projections',
                'aliases',
                'grouped_predicates',
                'index_friendly_date_ranges',
                'multi_condition_joins',
                'immediate_generated_id',
                'split_generated_id',
                'delayed_generated_id',
                'intervening_statement_before_generated_id',
                'affected_row_insert',
                'lock_conflict_evidence',
                'manual_transaction_refusal',
                'ordinary_managed_transaction',
                'nested_savepoint',
                'callback_exception_identity',
                'active_cursor_rejection',
                'sqlite_immediate_external_ownership',
                'application_owned_exception_classification',
            ] as $shape
        ) {
            self::assertContains($shape, $requiredShapes);
        }

        $sources = $manifest['sources'] ?? null;
        self::assertIsArray($sources);
        self::assertNotEmpty($sources);
        foreach ($sources as $source) {
            self::assertIsString($source);
            self::assertFileExists($root . '/' . $source);
            $sourceContents = file_get_contents($root . '/' . $source);
            self::assertIsString($sourceContents);
            self::assertStringNotContainsString('/home/', $sourceContents);
        }
    }
}
