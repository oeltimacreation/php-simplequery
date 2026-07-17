<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

use RuntimeException;

final class MigrationCorpusReporter
{
    /** @var list<string> */
    private const COUNT_FIELDS = [
        'legacy_lines',
        'native_lines',
        'changed_lines',
        'import_only_edits',
    ];

    /** @var list<string> */
    private const DECLARED_COUNT_FIELDS = [
        'insert_return_rewrites',
        'unsupported_methods',
        'raw_sql_findings',
        'safe_mechanical_edits',
        'manual_edits',
        'query_parity_cases',
        'result_parity_cases',
    ];

    public function __construct(private readonly SourcePatternRewriter $rewriter)
    {
    }

    /** @return array<string, mixed> */
    public function report(string $fixturePath): array
    {
        $contents = file_get_contents($fixturePath);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read the migration corpus.');
        }
        $root = $this->object(json_decode($contents, true, 512, JSON_THROW_ON_ERROR), 'corpus');
        if (($root['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported migration corpus schema.');
        }

        $corpusVersion = $this->string($root['corpus_version'] ?? null, 'corpus_version');
        $collectedAt = $this->string($root['collected_at'] ?? null, 'collected_at');
        $slices = $this->objectList($root['slices'] ?? null, 'slices');
        $automationCases = $this->objectList($root['automation_cases'] ?? null, 'automation_cases');
        $blockers = $root['unknown_blockers'] ?? null;
        if (!is_array($blockers) || !array_is_list($blockers)) {
            throw new RuntimeException('unknown_blockers must be a list.');
        }

        $totals = array_fill_keys([...self::COUNT_FIELDS, ...self::DECLARED_COUNT_FIELDS], 0);
        $risks = ['low' => 0, 'medium' => 0, 'high' => 0];
        $sliceIds = [];
        $engines = [];
        foreach ($slices as $slice) {
            $id = $this->string($slice['id'] ?? null, 'slice.id');
            if (isset($sliceIds[$id])) {
                throw new RuntimeException(sprintf('Duplicate migration slice "%s".', $id));
            }
            $sliceIds[$id] = true;

            foreach ($this->stringList($slice['engine_profiles'] ?? null, $id . '.engine_profiles') as $engine) {
                $engines[$engine] = true;
            }
            $legacySource = $this->sourceLines(
                dirname($fixturePath),
                $this->string($slice['legacy_source'] ?? null, $id . '.legacy_source'),
            );
            $nativeSource = $this->sourceLines(
                dirname($fixturePath),
                $this->string($slice['native_source'] ?? null, $id . '.native_source'),
            );
            $computedCounts = [
                'legacy_lines' => count($legacySource),
                'native_lines' => count($nativeSource),
                'changed_lines' => $this->changedLineCount($legacySource, $nativeSource),
                'import_only_edits' => count(array_filter(
                    $legacySource,
                    static fn (string $line): bool => str_starts_with($line, 'use Pixie\\'),
                )),
            ];
            foreach ($computedCounts as $field => $value) {
                $totals[$field] += $value;
            }
            $measurement = $this->object($slice['measurement'] ?? null, $id . '.measurement');
            foreach (self::DECLARED_COUNT_FIELDS as $field) {
                $value = $measurement[$field] ?? null;
                if (!is_int($value) || $value < 0) {
                    throw new RuntimeException(sprintf('%s.%s must be a non-negative integer.', $id, $field));
                }
                $totals[$field] += $value;
            }
            $risk = $this->string($slice['rollout_risk'] ?? null, $id . '.rollout_risk');
            if (!isset($risks[$risk])) {
                throw new RuntimeException(sprintf('Unknown rollout risk "%s".', $risk));
            }
            ++$risks[$risk];
            $this->string($slice['support_effort'] ?? null, $id . '.support_effort');
            $this->stringList($slice['validated_shapes'] ?? null, $id . '.validated_shapes');
        }

        $automation = ['safe' => 0, 'refused' => 0, 'reasons' => []];
        foreach ($automationCases as $case) {
            $id = $this->string($case['id'] ?? null, 'automation_case.id');
            $source = $this->string($case['source'] ?? null, $id . '.source');
            $expectedSafe = $case['safe'] ?? null;
            if (!is_bool($expectedSafe)) {
                throw new RuntimeException(sprintf('%s.safe must be Boolean.', $id));
            }
            $expectedReason = $this->string($case['reason'] ?? null, $id . '.reason');
            $decision = $this->rewriter->analyze($source);
            if ($decision->safe !== $expectedSafe || $decision->reason !== $expectedReason) {
                throw new RuntimeException(sprintf('Automation case "%s" did not produce its expected decision.', $id));
            }
            if ($decision->safe) {
                ++$automation['safe'];
            } else {
                ++$automation['refused'];
            }
            $automation['reasons'][$decision->reason] = ($automation['reasons'][$decision->reason] ?? 0) + 1;
        }
        ksort($automation['reasons']);
        $engineNames = array_keys($engines);
        sort($engineNames);

        return [
            'schema_version' => 1,
            'corpus_version' => $corpusVersion,
            'collected_at' => $collectedAt,
            'scope' => 'synthetic library-owned migration slices; no application repository modified',
            'slice_count' => count($slices),
            'slice_ids' => array_keys($sliceIds),
            'engine_profiles' => $engineNames,
            'measurements' => $totals,
            'automation' => $automation,
            'rollout_risk' => $risks,
            'runtime_compatibility_layer' => false,
            'unknown_blockers' => $blockers,
            'status' => $blockers === [] ? 'passed' : 'blocked',
        ];
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('%s must be an object.', $field));
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('%s must use string keys.', $field));
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('%s must be a list.', $field));
        }
        $result = [];
        foreach ($value as $index => $item) {
            $result[] = $this->object($item, sprintf('%s.%d', $field, $index));
        }

        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('%s must be a string list.', $field));
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->string($item, $field);
        }

        return $result;
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('%s must be a non-empty string.', $field));
        }

        return $value;
    }

    /** @return list<string> */
    private function sourceLines(string $fixtureDirectory, string $relativePath): array
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Migration source paths must remain inside the fixture directory.');
        }
        $contents = file_get_contents($fixtureDirectory . DIRECTORY_SEPARATOR . $relativePath);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Could not read migration source fixture "%s".', $relativePath));
        }
        $lines = preg_split('/\R/', trim($contents));
        if (!is_array($lines)) {
            throw new RuntimeException(sprintf('Could not split migration source fixture "%s".', $relativePath));
        }

        return array_values(array_map('trim', array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '',
        )));
    }

    /**
     * @param list<string> $legacy
     * @param list<string> $native
     */
    private function changedLineCount(array $legacy, array $native): int
    {
        $legacyCount = count($legacy);
        $nativeCount = count($native);
        $matrix = array_fill(0, $legacyCount + 1, array_fill(0, $nativeCount + 1, 0));
        for ($legacyIndex = 1; $legacyIndex <= $legacyCount; ++$legacyIndex) {
            for ($nativeIndex = 1; $nativeIndex <= $nativeCount; ++$nativeIndex) {
                $matrix[$legacyIndex][$nativeIndex] = $legacy[$legacyIndex - 1] === $native[$nativeIndex - 1]
                    ? $matrix[$legacyIndex - 1][$nativeIndex - 1] + 1
                    : max($matrix[$legacyIndex - 1][$nativeIndex], $matrix[$legacyIndex][$nativeIndex - 1]);
            }
        }

        return $legacyCount + $nativeCount - (2 * $matrix[$legacyCount][$nativeCount]);
    }
}
