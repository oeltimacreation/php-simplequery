<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class ConsumerAuditBaselineTest extends TestCase
{
    public function testAnonymizedBaselineIsInternallyConsistent(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/evidence/consumer-audit-baseline.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringNotContainsString('/home/', $contents);
        self::assertStringNotContainsString('SELECT ', $contents);

        $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version'] ?? null);
        self::assertSame(2, $report['audit_tool_schema_version'] ?? null);
        self::assertSame(9, $report['consumer_count'] ?? null);

        $versions = $report['pixie_versions'] ?? null;
        self::assertIsArray($versions);
        self::assertSame(9, array_sum($versions));

        $aggregate = $report['aggregate'] ?? null;
        self::assertIsArray($aggregate);
        $insertCalls = $aggregate['insert_calls'] ?? null;
        self::assertIsInt($insertCalls);
        $classifiedInsertCounts = [];
        foreach (['insert_assigned', 'insert_returned', 'insert_truthiness', 'insert_ignored_or_chained'] as $field) {
            $count = $aggregate[$field] ?? null;
            self::assertIsInt($count);
            $classifiedInsertCounts[] = $count;
        }
        self::assertSame($insertCalls, array_sum($classifiedInsertCounts));
        self::assertGreaterThan(0, $aggregate['raw_query_calls'] ?? 0);
        self::assertGreaterThan(0, $aggregate['direct_pdo_calls'] ?? 0);

        $prevalence = $report['consumer_prevalence'] ?? null;
        self::assertIsArray($prevalence);
        foreach ($prevalence as $count) {
            self::assertIsInt($count);
            self::assertGreaterThanOrEqual(0, $count);
            self::assertLessThanOrEqual(9, $count);
        }
    }
}
