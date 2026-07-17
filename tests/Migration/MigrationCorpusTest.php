<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use Oeltima\SimpleQuery\Tools\Migration\MigrationCorpusReporter;
use Oeltima\SimpleQuery\Tools\Migration\SourcePatternRewriter;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class MigrationCorpusTest extends TestCase
{
    public function testRepresentativeCorpusProducesTheAcceptedMeasurementReport(): void
    {
        $report = $this->reporter()->report($this->fixturePath());

        self::assertSame('passed', $report['status']);
        self::assertSame(5, $report['slice_count']);
        $sliceIds = $report['slice_ids'];
        self::assertIsArray($sliceIds);
        self::assertSame(
            ['injected-shared-model', 'raw-reporting-model', 'diagnostic-raw-join', 'complex-list-join'],
            array_values(array_filter(
                $sliceIds,
                static fn (mixed $id): bool => $id !== 'sqlite-importer-crud',
            )),
        );
        self::assertSame(['mariadb', 'mysql', 'sqlite'], $report['engine_profiles']);
        self::assertSame([
            'legacy_lines' => 115,
            'native_lines' => 96,
            'changed_lines' => 91,
            'import_only_edits' => 5,
            'insert_return_rewrites' => 4,
            'unsupported_methods' => 0,
            'raw_sql_findings' => 7,
            'safe_mechanical_edits' => 5,
            'manual_edits' => 86,
            'query_parity_cases' => 31,
            'result_parity_cases' => 23,
        ], $report['measurements']);
        self::assertSame([
            'safe' => 1,
            'refused' => 7,
            'reasons' => [
                'construction_context' => 1,
                'diagnostic_semantics' => 1,
                'import_only' => 1,
                'insert_return_semantics' => 1,
                'named_placeholder_contract' => 1,
                'raw_sql_security' => 1,
                'transaction_ownership' => 1,
                'unsupported_method' => 1,
            ],
        ], $report['automation']);
        self::assertSame(['low' => 1, 'medium' => 3, 'high' => 1], $report['rollout_risk']);
        self::assertFalse($report['runtime_compatibility_layer']);
        self::assertSame([], $report['unknown_blockers']);
        self::assertSame(
            $report,
            $this->readJsonObject(dirname(__DIR__, 2) . '/docs/evidence/migration-validation.json'),
        );
    }

    public function testOnlyIsolatedImportsAreMechanicallyRewritten(): void
    {
        $rewriter = new SourcePatternRewriter();

        $connection = $rewriter->analyze('use Pecee\\Pixie\\Connection;');
        self::assertTrue($connection->safe);
        self::assertSame('import_only', $connection->reason);
        self::assertSame('use Oeltima\\SimpleQuery\\Connection;', $connection->rewrittenSource);

        $ambiguous = [
            '$id = $db->table(\'users\')->insert($row);' => 'insert_return_semantics',
            '$db->table(\'users\')->updateOrInsert($key, $values);' => 'unsupported_method',
            '$sql = $db->getLastQuery()->getRawSql();' => 'diagnostic_semantics',
            '$db->query(\'SELECT * FROM users WHERE id = :id\');' => 'named_placeholder_contract',
            'use Pecee\\Pixie\\QueryBuilder\\QueryBuilderHandler;' => 'construction_context',
        ];
        foreach ($ambiguous as $source => $reason) {
            $decision = $rewriter->analyze($source);
            self::assertFalse($decision->safe);
            self::assertSame($reason, $decision->reason);
            self::assertNull($decision->rewrittenSource);
        }
    }

    public function testDeclaredDerivedMeasurementMustMatchItsSourcePair(): void
    {
        $fixturePath = $this->fixturePath();
        $fixture = $this->readJsonObject($fixturePath);
        $slices = $fixture['slices'] ?? null;
        self::assertIsArray($slices);
        self::assertIsArray($slices[0] ?? null);
        self::assertIsArray($slices[0]['measurement'] ?? null);
        $slices[0]['measurement']['manual_edits'] = 999;
        $fixture['slices'] = $slices;

        $temporaryPath = tempnam(dirname($fixturePath), 'invalid-corpus-');
        self::assertIsString($temporaryPath);
        file_put_contents($temporaryPath, json_encode($fixture, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sqlite-importer-crud.manual_edits does not match the source pair.');
        try {
            $this->reporter()->report($temporaryPath);
        } finally {
            unlink($temporaryPath);
        }
    }

    public function testRuntimePackageContainsNoPixieFacadeOrDependency(): void
    {
        $composerContents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertIsString($composerContents);
        $composer = json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $requirements = $composer['require'] ?? null;
        self::assertIsArray($requirements);
        self::assertArrayNotHasKey('pecee/pixie', $requirements);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertStringNotContainsString('namespace Pixie', $contents);
            self::assertStringNotContainsString('Pecee\\Pixie', $contents);
            self::assertStringNotContainsString('class_alias(', $contents);
        }
    }

    private function reporter(): MigrationCorpusReporter
    {
        return new MigrationCorpusReporter(new SourcePatternRewriter());
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__) . '/Fixtures/Migration/representative-slices.json';
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $path): array
    {
        $contents = file_get_contents($path);
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
