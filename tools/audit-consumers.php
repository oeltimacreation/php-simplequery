<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: audit-consumers.php <workspace-root> [--include-paths]\n");
    exit(2);
}

$countMatches = static function (string $pattern, string $contents): int {
    $count = preg_match_all($pattern, $contents);

    return $count === false ? 0 : $count;
};

$workspaceRoot = realpath($argv[1]);
if (!is_string($workspaceRoot) || !is_dir($workspaceRoot)) {
    fwrite(STDERR, "The consumer workspace root does not exist.\n");
    exit(2);
}

$includePaths = in_array('--include-paths', $argv, true);
$repositories = [];
$entries = scandir($workspaceRoot);
if (!is_array($entries)) {
    fwrite(STDERR, "Could not enumerate the consumer workspace.\n");
    exit(2);
}

foreach ($entries as $entry) {
    $repositoryPath = $workspaceRoot . DIRECTORY_SEPARATOR . $entry;
    if ($entry === '.' || $entry === '..' || !is_dir($repositoryPath)) {
        continue;
    }

    $composerPath = $repositoryPath . '/composer.json';
    if (!is_file($composerPath)) {
        continue;
    }

    $composerContents = file_get_contents($composerPath);
    if (!is_string($composerContents) || !str_contains($composerContents, 'pecee/pixie')) {
        continue;
    }

    $repositories[] = $repositoryPath;
}

sort($repositories);
$profiles = [];
$aggregate = [
    'insert_calls' => 0,
    'insert_assigned' => 0,
    'insert_returned' => 0,
    'insert_truthiness' => 0,
    'insert_ignored_or_chained' => 0,
    'empty_in_literal' => 0,
    'update_or_insert' => 0,
    'lock_calls' => 0,
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
            '/->(?:lockForUpdate|sharedLock|forUpdate|forShare|noWait|skipLocked)\s*\(/',
            $contents,
        );
        $counts['named_placeholder_candidates'] += $countMatches(
            '/["\'][^"\'\n]*:[A-Za-z_][A-Za-z0-9_]*/',
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
        'counts' => $counts,
    ];
    if ($includePaths) {
        $profile['path'] = $repositoryPath;
    }
    $profiles[] = $profile;
}

fwrite(
    STDOUT,
    json_encode(
        [
            'schema_version' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'method' => 'lexical candidate scan; every return-sensitive call requires manual confirmation',
            'consumer_count' => count($profiles),
            'aggregate' => $aggregate,
            'profiles' => $profiles,
        ],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ) . PHP_EOL,
);
