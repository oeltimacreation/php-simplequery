<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;

final class ProductionCompilerExpectations
{
    public static function assertReport(CompiledQuery $query, int $filterCount, int $inListSize): void
    {
        CompiledQueryAssertions::assertMatches(
            $query,
            self::reportSql($filterCount, $inListSize),
            self::reportBindings($filterCount, $inListSize),
        );
    }

    public static function assertCount(CompiledQuery $query, int $inListSize): void
    {
        $placeholders = implode(', ', array_fill(0, $inListSize, '?'));
        CompiledQueryAssertions::assertMatches(
            $query,
            'SELECT COUNT(DISTINCT r.account_id) AS aggregate FROM "report_events" AS "r" '
                . 'WHERE "r"."created_at" >= ? AND "r"."created_at" < ? '
                . 'AND "r"."account_id" IN (' . $placeholders . ')',
            ['2026-01-01 00:00:00', '2026-02-01 00:00:00', ...range(1, $inListSize)],
        );
    }

    private static function reportSql(int $filterCount, int $inListSize): string
    {
        $sql = 'SELECT "r"."id", "r"."account_id" AS "account_id", "a"."name" AS "account_name", '
            . '"t"."name" AS "team_name", "rg"."name" AS "region_name", "r"."status", "r"."kind", '
            . '"r"."created_at", "r"."updated_at", "r"."score", "r"."payload_size", '
            . 'COALESCE(r.score, ?) AS normalized_score FROM "report_events" AS "r" '
            . 'INNER JOIN "accounts" AS "a" ON "a"."id" = "r"."account_id" '
            . 'LEFT JOIN "teams" AS "t" ON "t"."id" = "r"."team_id" AND "t"."enabled" = ? '
            . 'LEFT JOIN "regions" AS "rg" ON "rg"."id" = "a"."region_id" AND rg.archived_at IS NULL '
            . 'WHERE "r"."created_at" >= ? AND "r"."created_at" < ? '
            . 'AND ("r"."status" = ? OR ("r"."status" = ? AND "r"."updated_at" IS NOT NULL)) '
            . 'AND COALESCE(r.score, ?) >= ? AND "r"."account_id" IN ('
            . implode(', ', array_fill(0, $inListSize, '?')) . ')';
        for ($filter = 0; $filter < $filterCount; ++$filter) {
            $sql .= sprintf(' AND "r"."filter_%d" >= ?', $filter);
        }

        return $sql . ' ORDER BY "r"."created_at" DESC, "r"."id" ASC LIMIT 250';
    }

    /** @return list<mixed> */
    private static function reportBindings(int $filterCount, int $inListSize): array
    {
        return [
            0,
            1,
            '2026-01-01 00:00:00',
            '2026-02-01 00:00:00',
            'ready',
            'queued',
            0,
            50,
            ...range(1, $inListSize),
            ...range(0, $filterCount - 1),
        ];
    }
}
