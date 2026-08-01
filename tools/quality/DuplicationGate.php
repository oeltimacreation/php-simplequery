<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Repository duplication gate.
 *
 * Flags repeated golden SQL and fixture case ids, duplicate test method names,
 * and re-added hotspot patterns that Phase 1 and Phase 2 of the `0.4.0` plan
 * removed. The baseline thresholds mirror the machine-readable duplication and
 * complexity inventory in `docs/evidence/0.4-duplication-and-complexity-inventory.json`.
 */
final class DuplicationGate
{
    private const GOLDEN_FIXTURE_DIRECTORY = 'tests/Fixtures/Compiler';

    /** @var list<string> */
    private const GOLDEN_DRIVERS = ['mariadb', 'mysql', 'sqlite'];

    /** @var list<string> */
    private const DIALECT_COMPILER_TESTS = [
        'tests/Compiler/MariaDbCompilerTest.php',
        'tests/Compiler/MySqlCompilerTest.php',
        'tests/Compiler/SqliteCompilerTest.php',
    ];

    private const STATEMENT_DOUBLE_DIRECTORY = 'tests/Unit';

    private const ALLOWED_STATEMENT_DOUBLE = 'ConfigurableStatement.php';

    /** @var list<string> */
    private const FUNC_NUM_ARGS_FILES = [
        'src/Internal/BuildsConditions.php',
        'src/QueryBuilder.php',
        'src/JoinClause.php',
    ];

    private const QUERY_BUILDER_MAGIC_STRING_PATTERN = "'update'|'share'|'NOWAIT'|'SKIP LOCKED'|'INNER'|'LEFT'";

    private const FUNC_NUM_ARGS_BASELINE = 12;

    private const QUERY_BUILDER_MAGIC_STRING_BASELINE = 7;

    /** @return list<string> */
    public function check(string $root): array
    {
        $errors = array_merge(
            $this->goldenFixtureErrors($root),
            $this->duplicateTestNameErrors($root),
            $this->hotspotErrors($root),
        );
        sort($errors);

        return $errors;
    }

    /** @return list<string> */
    private function goldenFixtureErrors(string $root): array
    {
        [$cases, $errors] = $this->goldenCases($root);

        return array_merge($errors, $this->duplicateCaseErrors($cases), $this->duplicateSqlErrors($cases));
    }

    /** @return array{list<array{id: string, sql: string, location: string}>, list<string>} */
    private function goldenCases(string $root): array
    {
        $cases = [];
        $errors = [];
        foreach (self::GOLDEN_DRIVERS as $driver) {
            $path = $root . '/' . self::GOLDEN_FIXTURE_DIRECTORY . '/' . $driver . '.json';
            $fixture = $this->readFixture($path);
            if ($fixture === null) {
                $errors[] = sprintf('Missing or invalid golden fixture: %s.', $path);
                continue;
            }
            $fixtureCases = $fixture['cases'] ?? null;
            if (!is_array($fixtureCases)) {
                $errors[] = sprintf('%s has no cases array.', $path);
                continue;
            }
            foreach ($fixtureCases as $fixtureCase) {
                if (!is_array($fixtureCase)) {
                    continue;
                }
                $id = $fixtureCase['id'] ?? null;
                $sql = $fixtureCase['sql'] ?? null;
                if (!is_string($id) || !is_string($sql)) {
                    $errors[] = sprintf('%s contains a case without a string id and sql.', $path);
                    continue;
                }
                $cases[] = ['id' => $id, 'sql' => $sql, 'location' => $driver . '#' . $id];
            }
        }

        return [$cases, $errors];
    }

    /**
     * @param list<array{id: string, sql: string, location: string}> $cases
     * @return list<string>
     */
    private function duplicateCaseErrors(array $cases): array
    {
        $errors = [];
        $locationsById = [];
        foreach ($cases as $case) {
            $id = $case['id'];
            $location = $case['location'];
            if (isset($locationsById[$id])) {
                $errors[] = sprintf(
                    'Golden fixture case id "%s" is duplicated (%s and %s).',
                    $id,
                    $locationsById[$id],
                    $location,
                );
            } else {
                $locationsById[$id] = $location;
            }
        }

        return $errors;
    }

    /**
     * @param list<array{id: string, sql: string, location: string}> $cases
     * @return list<string>
     */
    private function duplicateSqlErrors(array $cases): array
    {
        $errors = [];
        $locationsBySql = [];
        foreach ($cases as $case) {
            $locationsBySql[$this->normalizeSql($case['sql'])][] = $case['location'];
        }
        foreach ($locationsBySql as $normalized => $locations) {
            if (count($locations) > 1) {
                $errors[] = sprintf(
                    'Golden SQL is repeated in %s: %s',
                    implode(', ', $locations),
                    $normalized,
                );
            }
        }

        return $errors;
    }

    /** @return array<string, mixed>|null */
    private function readFixture(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }
        try {
            $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($fixture)) {
            return null;
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($fixture as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return list<string> */
    private function duplicateTestNameErrors(string $root): array
    {
        $errors = [];
        foreach ($this->testMethodNames($this->testFiles($root)) as $name => $files) {
            $uniqueFiles = array_values(array_unique($files));
            if (count($uniqueFiles) > 1) {
                $errors[] = sprintf(
                    'Test method "%s" is defined in more than one file: %s.',
                    $name,
                    implode(', ', $uniqueFiles),
                );
            }
        }

        return $errors;
    }

    /** @return list<SplFileInfo> */
    private function testFiles(string $root): array
    {
        $tests = new SplFileInfo($root . '/tests');
        if (!$tests->isDir()) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tests->getPathname()));
        foreach ($iterator as $file) {
            $testFile = $this->testFile($file);
            if ($testFile !== null) {
                $files[] = $testFile;
            }
        }

        return $files;
    }

    /**
     * @param list<SplFileInfo> $files
     * @return array<string, list<string>>
     */
    private function testMethodNames(array $files): array
    {
        $names = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                continue;
            }
            $matched = preg_match_all('/\bpublic\s+function\s+(test[A-Za-z0-9_]+)\s*\(/', $contents, $matches);
            if ($matched === false) {
                continue;
            }
            foreach ($matches[1] as $name) {
                $names[$name][] = $file->getFilename();
            }
        }

        return $names;
    }

    private function testFile(mixed $file): ?SplFileInfo
    {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
            return null;
        }

        return $file;
    }

    /** @return list<string> */
    private function hotspotErrors(string $root): array
    {
        return array_merge(
            $this->conditionDispatchErrors($root),
            $this->statementDoubleErrors($root),
            $this->writeGoldenAssertionErrors($root),
            $this->magicDispatchErrors($root),
        );
    }

    /** @return list<string> */
    private function conditionDispatchErrors(string $root): array
    {
        $errors = [];
        if ($this->countOccurrences($root . '/src/QueryBuilder.php', 'function addHaving') !== 0) {
            $errors[] = 'QueryBuilder::addHaving() was re-added; use the shared BuildsConditions dispatch (SQ-0412).';
        }
        if ($this->countOccurrences($root . '/src/Internal/BuildsConditions.php', 'function addCondition') !== 1) {
            $errors[] = 'BuildsConditions must define exactly one addCondition() dispatch (SQ-0412).';
        }

        return $errors;
    }

    /** @return list<string> */
    private function statementDoubleErrors(string $root): array
    {
        $doubles = glob($root . '/' . self::STATEMENT_DOUBLE_DIRECTORY . '/*Statement.php');
        if (!is_array($doubles)) {
            return [];
        }

        $extraDoubles = [];
        foreach ($doubles as $double) {
            if (basename($double) !== self::ALLOWED_STATEMENT_DOUBLE) {
                $extraDoubles[] = basename($double);
            }
        }
        sort($extraDoubles);
        if ($extraDoubles === []) {
            return [];
        }

        return [sprintf(
            'Unexpected PDOStatement test doubles beyond %s: %s (SQ-0422).',
            self::ALLOWED_STATEMENT_DOUBLE,
            implode(', ', $extraDoubles),
        )];
    }

    /** @return list<string> */
    private function writeGoldenAssertionErrors(string $root): array
    {
        $errors = [];
        foreach (self::DIALECT_COMPILER_TESTS as $relative) {
            if ($this->countOccurrences($root . '/' . $relative, 'CompiledWriteQuery') === 0) {
                continue;
            }
            $errors[] = sprintf(
                '%s re-adds inline write golden assertions; use tests/Fixtures/Compiler (SQ-0421).',
                $relative,
            );
        }

        return $errors;
    }

    /** @return list<string> */
    private function magicDispatchErrors(string $root): array
    {
        $errors = [];
        $funcNumArgs = $this->funcNumArgsCount($root);
        if ($funcNumArgs > self::FUNC_NUM_ARGS_BASELINE) {
            $errors[] = sprintf(
                'func_num_args() dispatch grew to %d sites (baseline %d); prefer explicit parameter'
                    . ' shapes (SQ-0402-05).',
                $funcNumArgs,
                self::FUNC_NUM_ARGS_BASELINE,
            );
        }

        $magicStrings = $this->countOccurrences(
            $root . '/src/QueryBuilder.php',
            self::QUERY_BUILDER_MAGIC_STRING_PATTERN,
        );
        if ($magicStrings > self::QUERY_BUILDER_MAGIC_STRING_BASELINE) {
            $errors[] = sprintf(
                'QueryBuilder magic-string literal sites grew to %d (baseline %d); prefer typed AST'
                    . ' state (SQ-0402-06).',
                $magicStrings,
                self::QUERY_BUILDER_MAGIC_STRING_BASELINE,
            );
        }

        return $errors;
    }

    private function funcNumArgsCount(string $root): int
    {
        $count = 0;
        foreach (self::FUNC_NUM_ARGS_FILES as $relative) {
            $count += $this->countOccurrences($root . '/' . $relative, 'func_num_args');
        }

        return $count;
    }

    private function countOccurrences(string $path, string $pattern): int
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return 0;
        }
        $count = preg_match_all('/' . $pattern . '/', $contents);

        return $count === false ? 0 : $count;
    }
}
