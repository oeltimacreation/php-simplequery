<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

final readonly class ConsumerSourceAnalyzer
{
    /** @var list<string> */
    public const COUNT_FIELDS = [
        'php_files',
        'table_calls',
        'select_calls',
        'where_calls',
        'grouped_predicate_candidates',
        'join_calls',
        'group_by_calls',
        'order_by_calls',
        'pagination_calls',
        'object_get_calls',
        'object_first_calls',
        'associative_get_calls',
        'associative_first_calls',
        'cursor_calls',
        'associative_cursor_calls',
        'aggregate_calls',
        'insert_calls',
        'insert_get_id_calls',
        'insert_many_calls',
        'update_calls',
        'delete_calls',
        'compile_calls',
        'raw_expression_calls',
        'raw_query_calls',
        'pdo_calls',
        'managed_transaction_calls',
        'manual_transaction_control_calls',
    ];

    /** @var list<string> */
    public const REVIEW_FIELDS = [
        'raw_projection_contexts',
        'structured_projection_candidates',
        'expression_value_predicate_candidates',
        'complete_raw_predicate_candidates',
        'raw_join_expression_contexts',
        'raw_order_group_having_contexts',
        'dynamic_identifier_candidates',
        'interpolated_raw_sql_candidates',
        'split_generated_id_candidates',
        'delayed_generated_id_candidates',
        'intervening_statement_before_generated_id_candidates',
        'affected_row_insert_candidates',
        'direct_sql_transaction_control_candidates',
        'external_transaction_query_files',
        'nested_managed_transaction_candidates',
    ];

    public function __construct(private ConsumerSource $source)
    {
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys(self::COUNT_FIELDS, 0);
        $counts['php_files'] = 1;
        $patterns = [
            'table_calls' => '/->table\s*\(/',
            'select_calls' => '/->select\s*\(/',
            'where_calls' => '/->(?:where|orWhere|whereNot|orWhereNot|whereNull|orWhereNull|whereNotNull|'
                . 'orWhereNotNull|whereIn|orWhereIn|whereNotIn|orWhereNotIn|whereBetween|orWhereBetween|'
                . 'whereNotBetween|orWhereNotBetween)\s*\(/',
            'grouped_predicate_candidates' => '/->(?:where|orWhere)\s*\(\s*(?:static\s+)?(?:function|fn)\b/',
            'join_calls' => '/->(?:join|leftJoin|rightJoin|innerJoin)\s*\(/',
            'group_by_calls' => '/->groupBy\s*\(/',
            'order_by_calls' => '/->orderBy\s*\(/',
            'pagination_calls' => '/->(?:limit|offset)\s*\(/',
            'object_get_calls' => '/->get\s*\(/',
            'object_first_calls' => '/->first\s*\(/',
            'associative_get_calls' => '/->getAssociative\s*\(/',
            'associative_first_calls' => '/->firstAssociative\s*\(/',
            'cursor_calls' => '/->cursor\s*\(/',
            'associative_cursor_calls' => '/->cursorAssociative\s*\(/',
            'aggregate_calls' => '/->(?:count|sum|average|min|max)\s*\(/',
            'insert_calls' => '/->insert\s*\(/',
            'insert_get_id_calls' => '/->insertGetId\s*\(/',
            'insert_many_calls' => '/->insertMany\s*\(/',
            'update_calls' => '/->update\s*\(/',
            'delete_calls' => '/->delete\s*\(/',
            'compile_calls' => '/->compile\s*\(/',
            'raw_expression_calls' => '/->raw\s*\(/',
            'raw_query_calls' => '/->query\s*\(/',
            'pdo_calls' => '/->pdo\s*\(/',
            'managed_transaction_calls' => '/->transaction\s*\(/',
            'manual_transaction_control_calls' => '/->(?:beginTransaction|commit|rollBack)\s*\(/',
        ];
        foreach ($patterns as $name => $pattern) {
            $counts[$name] = $this->countMatches($pattern);
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function reviewCandidates(): array
    {
        $review = array_fill_keys(self::REVIEW_FIELDS, 0);
        $review['raw_projection_contexts'] = $this->countMatches('/->select\s*\([^;]*?->raw\s*\(/s');
        $review['structured_projection_candidates'] = $this->structuredProjectionCandidates();
        $review['expression_value_predicate_candidates'] = $this->countMatches(
            '/->(?:where|orWhere|having|orHaving)\s*\(\s*[^;]*?->raw\s*\(\s*["\'][^"\']*'
                . '(?:=|<>|!=|<=|>=|<|>|\bLIKE\b)\s*\?/is',
        );
        $rawPredicates = $this->countMatches(
            '/->(?:where|orWhere|having|orHaving)\s*\(\s*[^;]*?->raw\s*\(/s',
        );
        $review['complete_raw_predicate_candidates'] = max(
            0,
            $rawPredicates - $review['expression_value_predicate_candidates'],
        );
        $review['raw_join_expression_contexts'] = $this->countMatches(
            '/->(?:join|leftJoin|rightJoin|innerJoin|on|orOn)\s*\([^;]*?->raw\s*\(/s',
        );
        $review['raw_order_group_having_contexts'] = $this->countMatches(
            '/->(?:orderBy|groupBy|having|orHaving)\s*\([^;]*?->raw\s*\(/s',
        );
        $review['dynamic_identifier_candidates'] = $this->countMatches(
            '/->(?:table|select|join|leftJoin|rightJoin|innerJoin|groupBy|orderBy)\s*\(\s*'
                . '\$[A-Za-z_][A-Za-z0-9_]*/',
        );
        $review['interpolated_raw_sql_candidates'] = $this->countMatches(
            '/->(?:query|raw)\s*\(\s*(?:\$[A-Za-z_]|\{\$|"(?:[^"\\\\]|\\\\.)*\$|'
                . "'(?:[^'\\\\]|\\\\.)*'\\s*\\.\\s*\\$)/s",
        );

        $split = $this->splitGeneratedIdCandidates();
        $review['split_generated_id_candidates'] = $split['split'];
        $review['delayed_generated_id_candidates'] = $split['delayed'];
        $review['intervening_statement_before_generated_id_candidates'] = $split['intervening'];
        $review['direct_sql_transaction_control_candidates'] = $this->countMatches(
            '/->(?:exec|query)\s*\(\s*["\']\s*'
                . '(?:BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i',
        );
        $review['external_transaction_query_files'] = $this->hasExternalTransactionAndLibraryQuery() ? 1 : 0;

        $managedTransactionsInFile = $this->countMatches('/->transaction\s*\(/');
        $review['nested_managed_transaction_candidates'] = max(0, $managedTransactionsInFile - 1);

        return $review;
    }

    private function structuredProjectionCandidates(): int
    {
        $matches = [];
        $count = preg_match_all(
            '/->select\s*\([^;]*?->raw\s*\(\s*(["\'])(?<sql>.*?)\1/s',
            $this->contents(),
            $matches,
        );
        if ($count === false) {
            return 0;
        }

        $candidates = 0;
        foreach ($matches['sql'] as $sql) {
            if ($this->isStructuredProjection($sql)) {
                ++$candidates;
            }
        }

        return $candidates;
    }

    private function isStructuredProjection(mixed $sql): bool
    {
        if (!is_string($sql)) {
            return false;
        }

        return preg_match(
            '/^\s*(?:[A-Za-z_][A-Za-z0-9_]*\.)?(?:[A-Za-z_][A-Za-z0-9_]*|\*)'
                . '(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?'
                . '(?:\s*,\s*(?:[A-Za-z_][A-Za-z0-9_]*\.)?(?:[A-Za-z_][A-Za-z0-9_]*|\*)'
                . '(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?)*\s*$/i',
            $sql,
        ) === 1;
    }

    /** @return array{split: int, delayed: int, intervening: int} */
    private function splitGeneratedIdCandidates(): array
    {
        $insertMatches = [];
        $insertCount = preg_match_all(
            '/->insert\s*\([^;]*\)\s*;/s',
            $this->contents(),
            $insertMatches,
            PREG_OFFSET_CAPTURE,
        );
        if ($insertCount === false) {
            return ['split' => 0, 'delayed' => 0, 'intervening' => 0];
        }

        $result = ['split' => 0, 'delayed' => 0, 'intervening' => 0];
        foreach ($insertMatches[0] as $match) {
            $candidate = $this->generatedIdCandidate($match);
            if ($candidate === null) {
                continue;
            }

            ++$result['split'];
            $result['delayed'] += $candidate['delayed'];
            $result['intervening'] += $candidate['intervening'];
        }

        return $result;
    }

    /**
     * @param array{string, int} $match
     * @return array{delayed: int, intervening: int}|null
     */
    private function generatedIdCandidate(array $match): ?array
    {
        $remaining = substr($this->contents(), $match[1] + strlen($match[0]));
        $idOffset = strpos($remaining, 'lastInsertId');
        if ($idOffset === false) {
            return null;
        }
        $nextInsert = strpos($remaining, '->insert(');
        if ($nextInsert !== false) {
            if ($nextInsert < $idOffset) {
                return null;
            }
        }

        $between = substr($remaining, 0, $idOffset);
        $statementPattern = '/->(?:table|query|prepare|exec|insert|insertGetId|insertMany|update|delete)\s*\(/';

        return [
            'delayed' => str_contains($between, ';') ? 1 : 0,
            'intervening' => preg_match($statementPattern, $between) === 1 ? 1 : 0,
        ];
    }

    private function hasExternalTransactionAndLibraryQuery(): bool
    {
        $hasExternalTransaction = preg_match(
            '/->(?:beginTransaction|commit|rollBack)\s*\(/',
            $this->contents(),
        ) === 1;
        if (!$hasExternalTransaction) {
            $hasExternalTransaction = $this->countMatches(
                '/->(?:exec|query)\s*\(\s*["\']\s*'
                    . '(?:BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i',
            ) > 0;
        }
        if (!$hasExternalTransaction) {
            return false;
        }

        return preg_match('/->(?:table|query)\s*\(/', $this->contents()) === 1;
    }

    private function countMatches(string $pattern): int
    {
        $count = preg_match_all($pattern, $this->contents());

        return $count === false ? 0 : $count;
    }

    private function contents(): string
    {
        return $this->source->contents ?? '';
    }
}
