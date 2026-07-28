<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Migration\ConsumerAdoptionAuditor;

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) < 2) {
    fwrite(
        STDERR,
        "Usage: audit-consumers.php <workspace-root> [--mode=pixie|simplequery] "
            . "[--include-paths] [--deterministic]\n",
    );
    exit(2);
}

$mode = 'pixie';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--mode=')) {
        $mode = substr($argument, strlen('--mode='));
    }
}
if (!in_array($mode, ['pixie', 'simplequery'], true)) {
    fwrite(STDERR, "Audit mode must be pixie or simplequery.\n");
    exit(2);
}

if ($mode === 'simplequery') {
    require dirname(__DIR__) . '/vendor/autoload.php';

    try {
        $report = (new ConsumerAdoptionAuditor(
            in_array('--include-paths', $arguments, true),
            in_array('--deterministic', $arguments, true),
        ))->audit($arguments[1]);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(2);
    }

    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
}

$countMatches = static function (string $pattern, string $contents): int {
    $count = preg_match_all($pattern, $contents);

    return $count === false ? 0 : $count;
};
$pixieVersion = static function (string $repositoryPath): ?string {
    $lockPath = $repositoryPath . DIRECTORY_SEPARATOR . 'composer.lock';
    $contents = is_file($lockPath) ? file_get_contents($lockPath) : false;
    if (!is_string($contents)) {
        return null;
    }
    $lock = json_decode($contents, true);
    if (!is_array($lock)) {
        return null;
    }
    foreach (['packages', 'packages-dev'] as $section) {
        $packages = $lock[$section] ?? null;
        if (!is_array($packages)) {
            continue;
        }
        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'pecee/pixie') {
                $version = $package['version'] ?? null;

                return is_string($version) && $version !== '' ? $version : null;
            }
        }
    }

    return null;
};

$workspaceRoot = realpath($arguments[1]);
if (!is_string($workspaceRoot) || !is_dir($workspaceRoot)) {
    fwrite(STDERR, "The consumer workspace root does not exist.\n");
    exit(2);
}

$includePaths = in_array('--include-paths', $arguments, true);
$deterministic = in_array('--deterministic', $arguments, true);
$repositories = [];
$directory = new RecursiveDirectoryIterator($workspaceRoot, RecursiveDirectoryIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static function (SplFileInfo $file): bool {
        if (!$file->isDir()) {
            return $file->getFilename() === 'composer.json';
        }

        return !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', 'coverage'], true);
    },
);
$iterator = new RecursiveIteratorIterator($filter);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'composer.json') {
        continue;
    }
    $composerContents = file_get_contents($file->getPathname());
    if (!is_string($composerContents)) {
        continue;
    }
    $composer = json_decode($composerContents, true);
    if (!is_array($composer)) {
        continue;
    }
    $requires = array_merge(
        is_array($composer['require'] ?? null) ? $composer['require'] : [],
        is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
    );
    if (!array_key_exists('pecee/pixie', $requires)) {
        continue;
    }

    $repositories[] = $file->getPath();
}

sort($repositories);
$profiles = [];
$aggregate = [
    'php_files' => 0,
    'table_calls' => 0,
    'where_calls' => 0,
    'get_calls' => 0,
    'first_calls' => 0,
    'select_calls' => 0,
    'count_calls' => 0,
    'join_calls' => 0,
    'having_calls' => 0,
    'raw_expression_calls' => 0,
    'raw_query_calls' => 0,
    'raw_query_terminal_candidates' => 0,
    'interpolated_raw_sql_candidates' => 0,
    'insert_calls' => 0,
    'insert_assigned' => 0,
    'insert_returned' => 0,
    'insert_truthiness' => 0,
    'insert_ignored_or_chained' => 0,
    'empty_in_literal' => 0,
    'update_or_insert' => 0,
    'lock_calls' => 0,
    'lock_clause_candidates' => 0,
    'transaction_calls' => 0,
    'manual_pdo_transaction_calls' => 0,
    'direct_pdo_calls' => 0,
    'last_query_diagnostics' => 0,
    'dynamic_table_candidates' => 0,
    'closure_criteria_candidates' => 0,
    'named_placeholder_candidates' => 0,
    'clone_calls' => 0,
    'pixie_exception_catches' => 0,
];

foreach ($repositories as $index => $repositoryPath) {
    $counts = array_fill_keys(array_keys($aggregate), 0);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repositoryPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_contains($path, '/vendor/') || str_contains($path, '/.git/') || str_contains($path, '/coverage/')) {
            continue;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            continue;
        }

        ++$counts['php_files'];
        $counts['table_calls'] += $countMatches('/->table\s*\(/', $contents);
        $counts['where_calls'] += $countMatches('/->where\s*\(/', $contents);
        $counts['get_calls'] += $countMatches('/->get\s*\(/', $contents);
        $counts['first_calls'] += $countMatches('/->first\s*\(/', $contents);
        $counts['select_calls'] += $countMatches('/->select\s*\(/', $contents);
        $counts['count_calls'] += $countMatches('/->count\s*\(/', $contents);
        $counts['join_calls'] += $countMatches(
            '/->(?:join|leftJoin|rightJoin|joinUsing|leftJoinUsing|rightJoinUsing)\s*\(/',
            $contents,
        );
        $counts['having_calls'] += $countMatches('/->(?:having|orHaving)\s*\(/', $contents);
        $counts['raw_expression_calls'] += $countMatches('/->raw\s*\(/', $contents);
        $counts['raw_query_calls'] += $countMatches('/->query\s*\(/', $contents);
        $counts['raw_query_terminal_candidates'] += $countMatches(
            '/->query\s*\([^;]*?\)\s*->(?:get|first)\s*\(/s',
            $contents,
        );
        $counts['interpolated_raw_sql_candidates'] += $countMatches(
            '/->(?:query|raw)\s*\([^;]*?(?:\.\s*\$|\{\$|["\'][^"\'\n]*\$)/s',
            $contents,
        );
        $counts['insert_calls'] += $countMatches('/->insert\s*\(/', $contents);
        $counts['insert_assigned'] += $countMatches(
            '/(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=)[^;\n]*->insert\s*\(/',
            $contents,
        );
        $counts['insert_returned'] += $countMatches('/\breturn\s+[^;\n]*->insert\s*\(/', $contents);
        $counts['insert_truthiness'] += $countMatches('/\b(?:if|while)\s*\([^\n]*->insert\s*\(/', $contents);
        $counts['empty_in_literal'] += $countMatches(
            '/->where(?:Not)?In\s*\([^,]+,\s*\[\s*\]\s*\)/i',
            $contents,
        );
        $counts['update_or_insert'] += $countMatches('/->updateOrInsert\s*\(/', $contents);
        $counts['lock_calls'] += $countMatches(
            '/->(?:lockForUpdate|sharedLock|forUpdate|forShare|noWait|skipLocked|for)\s*\(/',
            $contents,
        );
        $counts['lock_clause_candidates'] += $countMatches('/\bFOR\s+(?:UPDATE|SHARE)\b/i', $contents);
        $counts['transaction_calls'] += $countMatches('/->transaction\s*\(/', $contents);
        $counts['manual_pdo_transaction_calls'] += $countMatches(
            '/->(?:beginTransaction|commit|rollBack)\s*\(/',
            $contents,
        );
        $counts['direct_pdo_calls'] += $countMatches(
            '/->(?:pdo|getPdoInstance)\s*\(/',
            $contents,
        );
        $counts['last_query_diagnostics'] += $countMatches(
            '/->(?:getLastQuery|getRawSql)\s*\(/',
            $contents,
        );
        $counts['dynamic_table_candidates'] += $countMatches(
            '/->table\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*/',
            $contents,
        );
        $counts['closure_criteria_candidates'] += $countMatches(
            '/->(?:where|orWhere|having|orHaving)\s*\(\s*(?:static\s+)?(?:function|fn)\b/',
            $contents,
        );
        $counts['named_placeholder_candidates'] += $countMatches(
            '/->(?:query|raw)\s*\(\s*["\'][^"\'\n]*:[A-Za-z_][A-Za-z0-9_]*/',
            $contents,
        );
        $counts['clone_calls'] += $countMatches('/\bclone\s+\$[A-Za-z_][A-Za-z0-9_]*/', $contents);
        $counts['pixie_exception_catches'] += $countMatches(
            '/catch\s*\([^)]*(?:Pixie|pecee\\\\pixie)/i',
            $contents,
        );
    }

    $classifiedInsertUses = $counts['insert_assigned'] + $counts['insert_returned'] + $counts['insert_truthiness'];
    $counts['insert_ignored_or_chained'] = max(0, $counts['insert_calls'] - $classifiedInsertUses);
    foreach ($aggregate as $name => $count) {
        $aggregate[$name] = $count + $counts[$name];
    }

    $profile = [
        'profile' => sprintf('consumer-%02d', $index + 1),
        'pixie_version' => $pixieVersion($repositoryPath),
        'counts' => $counts,
    ];
    if ($includePaths) {
        $profile['path'] = $repositoryPath;
    }
    $profiles[] = $profile;
}

$report = [
    'schema_version' => 2,
    'method' => 'recursive lexical candidate scan; every ambiguous or return-sensitive '
        . 'call requires manual confirmation',
    'consumer_count' => count($profiles),
    'aggregate' => $aggregate,
    'profiles' => $profiles,
];
if (!$deterministic) {
    $report = ['generated_at' => gmdate(DATE_ATOM), ...$report];
}

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
