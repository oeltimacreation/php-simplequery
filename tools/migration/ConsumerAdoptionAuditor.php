<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ConsumerAdoptionAuditor
{
    private const PACKAGE = 'oeltimacreation/php-simplequery';

    /** @var list<string> */
    private const EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'coverage'];

    /** @var list<string> */
    private const COUNT_FIELDS = [
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
    private const REVIEW_FIELDS = [
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

    public function __construct(
        private readonly bool $includePaths = false,
        private readonly bool $deterministic = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function audit(string $workspaceRoot): array
    {
        $resolvedRoot = realpath($workspaceRoot);
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot)) {
            throw new RuntimeException('The consumer workspace root does not exist.');
        }

        $repositories = $this->repositories($resolvedRoot);
        $aggregateCounts = array_fill_keys(self::COUNT_FIELDS, 0);
        $aggregateReview = array_fill_keys(self::REVIEW_FIELDS, 0);
        $profiles = [];

        foreach ($repositories as $index => $repositoryPath) {
            $profile = $this->profile($repositoryPath, $index + 1);
            $this->addCounts($aggregateCounts, $profile['counts']);
            $this->addCounts($aggregateReview, $profile['review_candidates']);
            $profiles[] = $profile;
        }

        $report = [
            'schema_version' => 1,
            'mode' => 'simplequery-adoption',
            'method' => 'read-only lexical candidate scan; findings require manual confirmation',
            'path_policy' => $this->includePaths ? 'repository-relative paths included' : 'stable source tokens only',
            'consumer_count' => count($profiles),
            'aggregate' => [
                'counts' => $aggregateCounts,
                'review_candidates' => $aggregateReview,
            ],
            'profiles' => $profiles,
        ];
        if (!$this->deterministic) {
            $report = ['generated_at' => gmdate(DATE_ATOM), ...$report];
        }

        return $report;
    }

    /** @return list<string> */
    private function repositories(string $workspaceRoot): array
    {
        $directory = new RecursiveDirectoryIterator($workspaceRoot, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $file): bool {
                if (!$file->isDir()) {
                    return $file->getFilename() === 'composer.json';
                }

                return !in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true);
            },
        );
        $iterator = new RecursiveIteratorIterator($filter);
        $repositories = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $composer = $this->jsonObject($file->getPathname());
            if ($composer === null) {
                continue;
            }
            $requires = array_merge(
                is_array($composer['require'] ?? null) ? $composer['require'] : [],
                is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
            );
            if (array_key_exists(self::PACKAGE, $requires)) {
                $repositories[] = $file->getPath();
            }
        }

        sort($repositories);

        return array_values(array_unique($repositories));
    }

    /** @return array<string, mixed> */
    private function profile(string $repositoryPath, int $number): array
    {
        $counts = array_fill_keys(self::COUNT_FIELDS, 0);
        $review = array_fill_keys(self::REVIEW_FIELDS, 0);
        $findings = [];
        foreach ($this->phpFiles($repositoryPath) as $path) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }

            ++$counts['php_files'];
            $this->countApiCalls($contents, $counts);
            $fileReview = $this->reviewCandidates($contents);
            foreach ($fileReview as $name => $occurrences) {
                $review[$name] += $occurrences;
                if ($occurrences > 0) {
                    $relativePath = ltrim(substr($path, strlen($repositoryPath)), DIRECTORY_SEPARATOR);
                    $findings[] = [
                        'kind' => $name,
                        'source' => $this->includePaths
                            ? $relativePath
                            : 'source-' . substr(hash('sha256', $relativePath), 0, 12),
                        'occurrences' => $occurrences,
                        'review' => 'manual_confirmation_required',
                    ];
                }
            }
        }

        $review['affected_row_insert_candidates'] = max(
            0,
            $counts['insert_calls'] - $review['split_generated_id_candidates'],
        );
        if ($review['affected_row_insert_candidates'] > 0) {
            $findings[] = [
                'kind' => 'affected_row_insert_candidates',
                'source' => 'profile-wide',
                'occurrences' => $review['affected_row_insert_candidates'],
                'review' => 'confirm affected-row result is intentional',
            ];
        }

        usort(
            $findings,
            static fn (array $left, array $right): int => [$left['source'], $left['kind']]
                <=> [$right['source'], $right['kind']],
        );

        $profile = [
            'profile' => sprintf('consumer-%02d', $number),
            'simplequery_version' => $this->packageVersion($repositoryPath),
            'counts' => $counts,
            'review_candidates' => $review,
            'findings' => $findings,
        ];
        if ($this->includePaths) {
            $profile['path'] = $repositoryPath;
        }

        return $profile;
    }

    /** @return list<string> */
    private function phpFiles(string $repositoryPath): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repositoryPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relativePath = ltrim(substr($file->getPathname(), strlen($repositoryPath)), DIRECTORY_SEPARATOR);
            $parts = preg_split('~[/\\\\]+~', $relativePath);
            if (is_array($parts) && array_intersect(self::EXCLUDED_DIRECTORIES, $parts) !== []) {
                continue;
            }
            $paths[] = $file->getPathname();
        }
        sort($paths);

        return $paths;
    }

    /** @param array<string, int> $counts */
    private function countApiCalls(string $source, array &$counts): void
    {
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
            $counts[$name] += $this->countMatches($pattern, $source);
        }
    }

    /**
     * @return array<string, int>
     */
    private function reviewCandidates(string $source): array
    {
        $review = array_fill_keys(self::REVIEW_FIELDS, 0);
        $review['raw_projection_contexts'] = $this->countMatches('/->select\s*\([^;]*?->raw\s*\(/s', $source);
        $review['structured_projection_candidates'] = $this->structuredProjectionCandidates($source);
        $review['expression_value_predicate_candidates'] = $this->countMatches(
            '/->(?:where|orWhere|having|orHaving)\s*\(\s*[^;]*?->raw\s*\(\s*["\'][^"\']*'
                . '(?:=|<>|!=|<=|>=|<|>|\bLIKE\b)\s*\?/is',
            $source,
        );
        $rawPredicates = $this->countMatches(
            '/->(?:where|orWhere|having|orHaving)\s*\(\s*[^;]*?->raw\s*\(/s',
            $source,
        );
        $review['complete_raw_predicate_candidates'] = max(
            0,
            $rawPredicates - $review['expression_value_predicate_candidates'],
        );
        $review['raw_join_expression_contexts'] = $this->countMatches(
            '/->(?:join|leftJoin|rightJoin|innerJoin|on|orOn)\s*\([^;]*?->raw\s*\(/s',
            $source,
        );
        $review['raw_order_group_having_contexts'] = $this->countMatches(
            '/->(?:orderBy|groupBy|having|orHaving)\s*\([^;]*?->raw\s*\(/s',
            $source,
        );
        $review['dynamic_identifier_candidates'] = $this->countMatches(
            '/->(?:table|select|join|leftJoin|rightJoin|innerJoin|groupBy|orderBy)\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*/',
            $source,
        );
        $review['interpolated_raw_sql_candidates'] = $this->countMatches(
            '/->(?:query|raw)\s*\(\s*(?:\$[A-Za-z_]|\{\$|"(?:[^"\\\\]|\\\\.)*\$|'
                . "'(?:[^'\\\\]|\\\\.)*'\\s*\\.\\s*\\$)/s",
            $source,
        );

        $split = $this->splitGeneratedIdCandidates($source);
        $review['split_generated_id_candidates'] = $split['split'];
        $review['delayed_generated_id_candidates'] = $split['delayed'];
        $review['intervening_statement_before_generated_id_candidates'] = $split['intervening'];
        $review['direct_sql_transaction_control_candidates'] = $this->countMatches(
            '/->(?:exec|query)\s*\(\s*["\']\s*'
                . '(?:BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i',
            $source,
        );
        $hasExternalTransaction = preg_match('/->(?:beginTransaction|commit|rollBack)\s*\(/', $source) === 1
            || $review['direct_sql_transaction_control_candidates'] > 0;
        $hasLibraryQuery = preg_match('/->(?:table|query)\s*\(/', $source) === 1;
        $review['external_transaction_query_files'] = $hasExternalTransaction && $hasLibraryQuery ? 1 : 0;

        $managedTransactionsInFile = $this->countMatches('/->transaction\s*\(/', $source);
        $review['nested_managed_transaction_candidates'] = $managedTransactionsInFile > 1
            ? $managedTransactionsInFile - 1
            : 0;

        return $review;
    }

    private function structuredProjectionCandidates(string $source): int
    {
        $matches = [];
        $count = preg_match_all(
            '/->select\s*\([^;]*?->raw\s*\(\s*(["\'])(?<sql>.*?)\1/s',
            $source,
            $matches,
        );
        if ($count === false) {
            return 0;
        }

        $candidates = 0;
        foreach ($matches['sql'] as $sql) {
            if (
                preg_match(
                    '/^\s*(?:[A-Za-z_][A-Za-z0-9_]*\.)?(?:[A-Za-z_][A-Za-z0-9_]*|\*)'
                        . '(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?'
                        . '(?:\s*,\s*(?:[A-Za-z_][A-Za-z0-9_]*\.)?(?:[A-Za-z_][A-Za-z0-9_]*|\*)'
                        . '(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?)*\s*$/i',
                    $sql,
                ) === 1
            ) {
                ++$candidates;
            }
        }

        return $candidates;
    }

    /** @return array{split: int, delayed: int, intervening: int} */
    private function splitGeneratedIdCandidates(string $source): array
    {
        $insertMatches = [];
        $insertCount = preg_match_all('/->insert\s*\([^;]*\)\s*;/s', $source, $insertMatches, PREG_OFFSET_CAPTURE);
        if ($insertCount === false) {
            return ['split' => 0, 'delayed' => 0, 'intervening' => 0];
        }

        $result = ['split' => 0, 'delayed' => 0, 'intervening' => 0];
        foreach ($insertMatches[0] as $match) {
            $afterOffset = $match[1] + strlen($match[0]);
            $remaining = substr($source, $afterOffset);
            $idOffset = strpos($remaining, 'lastInsertId');
            if ($idOffset === false) {
                continue;
            }
            $nextInsert = strpos($remaining, '->insert(');
            if ($nextInsert !== false && $nextInsert < $idOffset) {
                continue;
            }

            ++$result['split'];
            $between = substr($remaining, 0, $idOffset);
            if (str_contains($between, ';')) {
                ++$result['delayed'];
            }
            $statementPattern = '/->(?:table|query|prepare|exec|insert|insertGetId|insertMany|update|delete)\s*\(/';
            if (preg_match($statementPattern, $between) === 1) {
                ++$result['intervening'];
            }
        }

        return $result;
    }

    private function packageVersion(string $repositoryPath): ?string
    {
        $lock = $this->jsonObject($repositoryPath . '/composer.lock');
        if ($lock !== null) {
            foreach (['packages', 'packages-dev'] as $section) {
                $packages = $lock[$section] ?? null;
                if (!is_array($packages)) {
                    continue;
                }
                foreach ($packages as $package) {
                    if (!is_array($package) || ($package['name'] ?? null) !== self::PACKAGE) {
                        continue;
                    }
                    $version = $package['version'] ?? null;

                    return is_string($version) && $version !== '' ? $version : null;
                }
            }
        }

        $composer = $this->jsonObject($repositoryPath . '/composer.json');
        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? null;
            $version = is_array($requirements) ? ($requirements[self::PACKAGE] ?? null) : null;
            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function jsonObject(string $path): ?array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function countMatches(string $pattern, string $source): int
    {
        $count = preg_match_all($pattern, $source);

        return $count === false ? 0 : $count;
    }

    /**
     * @param array<string, int> $target
     * @param mixed $source
     */
    private function addCounts(array &$target, mixed $source): void
    {
        if (!is_array($source)) {
            throw new RuntimeException('Audit profile counts are invalid.');
        }
        foreach ($target as $name => $count) {
            $value = $source[$name] ?? null;
            if (!is_int($value)) {
                throw new RuntimeException(sprintf('Audit count "%s" is invalid.', $name));
            }
            $target[$name] = $count + $value;
        }
    }
}
