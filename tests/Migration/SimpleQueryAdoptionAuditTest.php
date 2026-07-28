<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use FilesystemIterator;
use Oeltima\SimpleQuery\Tools\Migration\ConsumerAdoptionAuditor;
use Oeltima\SimpleQuery\Tools\Migration\ConsumerAdoptionAuditOptions;
use Oeltima\SimpleQuery\Tools\Migration\ConsumerPathPolicy;
use Oeltima\SimpleQuery\Tools\Migration\ConsumerTimestampPolicy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class SimpleQueryAdoptionAuditTest extends TestCase
{
    private string $consumerPath;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $temporaryPath = tempnam(sys_get_temp_dir(), 'simplequery-audit-');
        self::assertIsString($temporaryPath);
        unlink($temporaryPath);
        self::assertTrue(mkdir($temporaryPath . '/src', 0700, true));
        $this->consumerPath = $temporaryPath;

        $composer = json_encode(
            ['require' => ['oeltimacreation/php-simplequery' => '^0.2']],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(file_put_contents($temporaryPath . '/composer.json', $composer));

        $fixtureRoot = dirname(__DIR__) . '/Fixtures/Adoption';
        $manifestContents = file_get_contents($fixtureRoot . '/manifest.json');
        self::assertIsString($manifestContents);
        $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $sources = $manifest['sources'] ?? null;
        self::assertIsArray($sources);
        foreach ($sources as $source) {
            self::assertIsString($source);
            $target = basename($source, '.txt');
            self::assertTrue(copy($fixtureRoot . '/' . $source, $temporaryPath . '/src/' . $target));
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->consumerPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->consumerPath);

        parent::tearDown();
    }

    public function testAuditIsDeterministicReadOnlyAndPathRedactedByDefault(): void
    {
        $before = $this->directoryDigest($this->consumerPath);
        $auditor = new ConsumerAdoptionAuditor(new ConsumerAdoptionAuditOptions(
            timestamp: ConsumerTimestampPolicy::Deterministic,
        ));

        $first = $auditor->audit(new SplFileInfo($this->consumerPath));
        $second = $auditor->audit(new SplFileInfo($this->consumerPath));

        self::assertSame($first, $second);
        self::assertSame($before, $this->directoryDigest($this->consumerPath));
        self::assertSame(1, $first['schema_version'] ?? null);
        self::assertSame('simplequery-adoption', $first['mode'] ?? null);
        self::assertSame('stable source tokens only', $first['path_policy'] ?? null);
        self::assertArrayNotHasKey('generated_at', $first);
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->consumerPath, $encoded);
        self::assertStringNotContainsString('query-shapes.php', $encoded);
        self::assertMatchesRegularExpression('/source-[a-f0-9]{12}/', $encoded);
    }

    public function testAuditProfilesProductionShapedUsageAndReviewResidue(): void
    {
        $report = (new ConsumerAdoptionAuditor(new ConsumerAdoptionAuditOptions(
            timestamp: ConsumerTimestampPolicy::Deterministic,
        )))->audit(new SplFileInfo($this->consumerPath));
        self::assertSame(1, $report['consumer_count'] ?? null);
        $profiles = $report['profiles'] ?? null;
        self::assertIsArray($profiles);
        $profile = $profiles[0] ?? null;
        self::assertIsArray($profile);
        self::assertSame('^0.2', $profile['simplequery_version'] ?? null);
        $aggregate = $report['aggregate'] ?? null;
        self::assertIsArray($aggregate);
        $counts = $aggregate['counts'] ?? null;
        self::assertIsArray($counts);
        self::assertSame(3, $counts['php_files'] ?? null);
        self::assertGreaterThan(0, $counts['select_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['grouped_predicate_candidates'] ?? 0);
        self::assertGreaterThan(0, $counts['join_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['associative_get_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['associative_cursor_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['insert_many_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['pdo_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['managed_transaction_calls'] ?? 0);
        self::assertGreaterThan(0, $counts['manual_transaction_control_calls'] ?? 0);

        $review = $aggregate['review_candidates'] ?? null;
        self::assertIsArray($review);
        self::assertGreaterThan(0, $review['raw_projection_contexts'] ?? 0);
        self::assertGreaterThan(0, $review['structured_projection_candidates'] ?? 0);
        self::assertGreaterThan(0, $review['expression_value_predicate_candidates'] ?? 0);
        self::assertGreaterThan(0, $review['complete_raw_predicate_candidates'] ?? 0);
        self::assertGreaterThan(0, $review['raw_join_expression_contexts'] ?? 0);
        self::assertGreaterThan(0, $review['dynamic_identifier_candidates'] ?? 0);
        self::assertGreaterThan(0, $review['interpolated_raw_sql_candidates'] ?? 0);
        self::assertSame(3, $review['split_generated_id_candidates'] ?? null);
        self::assertSame(2, $review['delayed_generated_id_candidates'] ?? null);
        self::assertSame(1, $review['intervening_statement_before_generated_id_candidates'] ?? null);
        self::assertSame(2, $review['affected_row_insert_candidates'] ?? null);
        self::assertGreaterThan(0, $review['direct_sql_transaction_control_candidates'] ?? 0);
        self::assertGreaterThan(0, $review['external_transaction_query_files'] ?? 0);
        self::assertGreaterThan(0, $review['nested_managed_transaction_candidates'] ?? 0);
    }

    public function testPathsAreAvailableOnlyWhenExplicitlyRequested(): void
    {
        $report = (new ConsumerAdoptionAuditor(new ConsumerAdoptionAuditOptions(
            ConsumerPathPolicy::Included,
            ConsumerTimestampPolicy::Deterministic,
        )))->audit(new SplFileInfo($this->consumerPath));
        $profiles = $report['profiles'] ?? null;
        self::assertIsArray($profiles);
        $profile = $profiles[0] ?? null;
        self::assertIsArray($profile);
        self::assertSame($this->consumerPath, $profile['path'] ?? null);
        self::assertSame('repository-relative paths included', $report['path_policy'] ?? null);
        $findings = $profile['findings'] ?? null;
        self::assertIsArray($findings);
        $sources = array_column($findings, 'source');
        self::assertContains('src/query-shapes.php', $sources);
    }

    public function testLockedPackageVersionTakesPrecedenceOverComposerConstraint(): void
    {
        $lock = json_encode([
            'packages' => [],
            'packages-dev' => [[
                'name' => 'oeltimacreation/php-simplequery',
                'version' => 'v0.2.7',
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        self::assertNotFalse(file_put_contents($this->consumerPath . '/composer.lock', $lock));

        $report = (new ConsumerAdoptionAuditor(new ConsumerAdoptionAuditOptions(
            timestamp: ConsumerTimestampPolicy::Deterministic,
        )))->audit(new SplFileInfo($this->consumerPath));
        $profiles = $report['profiles'] ?? null;
        self::assertIsArray($profiles);
        $profile = $profiles[0] ?? null;
        self::assertIsArray($profile);
        self::assertSame('v0.2.7', $profile['simplequery_version'] ?? null);
    }

    public function testDefaultAuditIncludesGenerationTimestamp(): void
    {
        $report = (new ConsumerAdoptionAuditor())->audit(new SplFileInfo($this->consumerPath));

        self::assertIsString($report['generated_at'] ?? null);
    }

    public function testMissingWorkspaceIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workspace root does not exist');

        (new ConsumerAdoptionAuditor())->audit(new SplFileInfo($this->consumerPath . '/missing'));
    }

    private function directoryDigest(string $path): string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relativePath = ltrim(substr($item->getPathname(), strlen($path)), DIRECTORY_SEPARATOR);
            $digest = hash_file('sha256', $item->getPathname());
            self::assertIsString($digest);
            $files[$relativePath] = $digest;
        }
        ksort($files);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
