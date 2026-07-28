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
    /** @var list<string> */
    private const EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'coverage'];

    private readonly ConsumerAdoptionAuditOptions $options;

    public function __construct(?ConsumerAdoptionAuditOptions $options = null)
    {
        $this->options = $options ?? new ConsumerAdoptionAuditOptions();
    }

    /** @return array<string, mixed> */
    public function audit(SplFileInfo $workspaceRoot): array
    {
        $resolvedRoot = realpath($workspaceRoot->getPathname());
        if (!is_string($resolvedRoot)) {
            throw new RuntimeException('The consumer workspace root does not exist.');
        }
        if (!is_dir($resolvedRoot)) {
            throw new RuntimeException('The consumer workspace root does not exist.');
        }

        $repositories = $this->repositories(new SplFileInfo($resolvedRoot));
        $aggregateCounts = array_fill_keys(ConsumerSourceAnalyzer::COUNT_FIELDS, 0);
        $aggregateReview = array_fill_keys(ConsumerSourceAnalyzer::REVIEW_FIELDS, 0);
        $profiles = [];

        foreach ($repositories as $index => $repository) {
            $profile = ['profile' => sprintf('consumer-%02d', $index + 1), ...$this->profile($repository)];
            $this->addCounts($aggregateCounts, $profile['counts']);
            $this->addCounts($aggregateReview, $profile['review_candidates']);
            $profiles[] = $profile;
        }

        $report = [
            'schema_version' => 1,
            'mode' => 'simplequery-adoption',
            'method' => 'read-only lexical candidate scan; findings require manual confirmation',
            'path_policy' => $this->pathPolicy(),
            'consumer_count' => count($profiles),
            'aggregate' => [
                'counts' => $aggregateCounts,
                'review_candidates' => $aggregateReview,
            ],
            'profiles' => $profiles,
        ];
        return $this->withTimestamp($report);
    }

    /** @return list<SplFileInfo> */
    private function repositories(SplFileInfo $workspaceRoot): array
    {
        $directory = new RecursiveDirectoryIterator(
            $workspaceRoot->getPathname(),
            RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            self::includeDirectoryEntry(...),
        );
        $iterator = new RecursiveIteratorIterator($filter);
        /** @var array<string, SplFileInfo> $repositories */
        $repositories = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $repository = new SplFileInfo($file->getPath());
            if (!(new ConsumerPackageInspector($repository))->isConsumer()) {
                continue;
            }
            $repositories[$repository->getPathname()] = $repository;
        }

        ksort($repositories);

        return array_values($repositories);
    }

    /** @return array<string, mixed> */
    private function profile(SplFileInfo $repository): array
    {
        $counts = array_fill_keys(ConsumerSourceAnalyzer::COUNT_FIELDS, 0);
        $review = array_fill_keys(ConsumerSourceAnalyzer::REVIEW_FIELDS, 0);
        $findings = [];
        foreach ($this->phpFiles($repository) as $source) {
            if ($source->contents === null) {
                continue;
            }

            $analysis = new ConsumerSourceAnalyzer($source);
            $this->addCounts($counts, $analysis->counts());
            $this->addReviewFindings($source, $analysis->reviewCandidates(), $review, $findings);
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
            'simplequery_version' => (new ConsumerPackageInspector($repository))->version(),
            'counts' => $counts,
            'review_candidates' => $review,
            'findings' => $findings,
        ];
        if ($this->options->includesPaths()) {
            $profile['path'] = $repository->getPathname();
        }

        return $profile;
    }

    /** @return list<ConsumerSource> */
    private function phpFiles(SplFileInfo $repository): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repository->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var array<string, ConsumerSource> $sources */
        $sources = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = new ConsumerSource($file, $repository);
            if ($this->isExcluded($source)) {
                continue;
            }
            $sources[$file->getPathname()] = $source;
        }
        ksort($sources);

        return array_values($sources);
    }

    private function isExcluded(ConsumerSource $source): bool
    {
        $parts = preg_split('~[/\\\\]+~', $source->relativePath());
        if ($parts === false) {
            return false;
        }

        return array_intersect(self::EXCLUDED_DIRECTORIES, $parts) !== [];
    }

    /**
     * @param array<string, int> $fileReview
     * @param array<string, int> $review
     * @param list<array<string, int|string>> $findings
     */
    private function addReviewFindings(
        ConsumerSource $source,
        array $fileReview,
        array &$review,
        array &$findings,
    ): void {
        foreach ($fileReview as $name => $occurrences) {
            $review[$name] += $occurrences;
            if ($occurrences === 0) {
                continue;
            }

            $relativePath = $source->relativePath();
            $findings[] = [
                'kind' => $name,
                'source' => $this->sourceName($relativePath),
                'occurrences' => $occurrences,
                'review' => 'manual_confirmation_required',
            ];
        }
    }

    private static function includeDirectoryEntry(SplFileInfo $file): bool
    {
        if (!$file->isDir()) {
            return $file->getFilename() === 'composer.json';
        }

        return !in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true);
    }

    private function pathPolicy(): string
    {
        return $this->options->includesPaths()
            ? 'repository-relative paths included'
            : 'stable source tokens only';
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function withTimestamp(array $report): array
    {
        if ($this->options->isDeterministic()) {
            return $report;
        }

        return ['generated_at' => gmdate(DATE_ATOM), ...$report];
    }

    private function sourceName(string $relativePath): string
    {
        return $this->options->includesPaths()
            ? $relativePath
            : 'source-' . substr(hash('sha256', $relativePath), 0, 12);
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
