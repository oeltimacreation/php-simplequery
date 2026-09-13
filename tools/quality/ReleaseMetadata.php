<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

final class ReleaseMetadata
{
    private const REQUIRED_FILES = [
        'CHANGELOG.md', 'README.md', 'SUPPORT.md', 'SECURITY.md',
        '.github/workflows/ci.yml', 'docs/maintainers/benchmarking.md',
        'docs/evidence/README.md',
    ];

    /** @return list<string> */
    public function check(string $root, ?string $publishedVersion = null): array
    {
        $loaded = $this->loadRequiredFiles($root);
        if ($loaded['errors'] !== []) {
            return $loaded['errors'];
        }
        $contents = $loaded['contents'];
        $changelog = $contents['CHANGELOG.md'] ?? '';
        $errors = $this->changelogSectionErrors($changelog);
        $release = $this->parseLatestRelease($changelog);
        if ($release === null) {
            return [...$errors, 'CHANGELOG.md requires a recognized dated release.'];
        }
        $version = $release['version'];
        if (!$this->isValidDate($release['date'])) {
            $errors[] = 'CHANGELOG.md release date is invalid.';
        }
        if ($publishedVersion !== null && $publishedVersion !== $version) {
            $errors[] = 'CHANGELOG.md does not match the explicit published-version provenance input.';
        }
        array_push($errors, ...$this->policyErrors($contents, $version, $release['series']));
        array_push($errors, ...$this->changelogLinkErrors($changelog, $version));
        array_push($errors, ...$this->evidenceIndexErrors($contents, $version));
        $plan = $this->planState($root, $version);
        array_push($errors, ...$plan['errors']);
        array_push(
            $errors,
            ...$this->baselineErrors($contents, $version, $release['previous'], $plan['developing']),
        );
        array_push($errors, ...$this->artifactLabelErrors($root));

        return $errors;
    }

    /** @return array{contents: array<string, string>, errors: list<string>} */
    private function loadRequiredFiles(string $root): array
    {
        $contents = [];
        $errors = [];
        foreach (self::REQUIRED_FILES as $file) {
            $text = is_file($root . '/' . $file) ? file_get_contents($root . '/' . $file) : false;
            if (!is_string($text)) {
                $errors[] = $file . ' is required for release consistency.';
            } else {
                $contents[$file] = $text;
            }
        }

        return ['contents' => $contents, 'errors' => $errors];
    }

    /** @return list<string> */
    private function changelogSectionErrors(string $changelog): array
    {
        if (str_contains($changelog, '## [Unreleased]')) {
            return [];
        }

        return ['CHANGELOG.md requires an Unreleased section.'];
    }

    /** @return array{version: string, previous: ?string, series: string, date: string}|null */
    private function parseLatestRelease(string $changelog): ?array
    {
        preg_match('/^## \[(?!Unreleased\])[^\n]+/m', $changelog, $heading);
        if (preg_match('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$/', $heading[0] ?? '', $release) !== 1) {
            return null;
        }
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', $changelog, $dated);

        return [
            'version' => $release[1],
            'previous' => $dated[1][1] ?? null,
            'series' => substr($release[1], 0, (int) strrpos($release[1], '.')),
            'date' => $release[2],
        ];
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @param array<string, string> $contents
     * @return list<string>
     */
    private function policyErrors(array $contents, string $version, string $series): array
    {
        $errors = [];
        $patterns = [
            'SECURITY.md' => '/\|\s*' . preg_quote($series, '/') . '\.x\s*\|\s*✅\s*Current release line/i',
            'SUPPORT.md' => '/`' . preg_quote($version, '/') . '`\s+is the current minor release/',
            'README.md' => '/composer require oeltimacreation\/php-simplequery:\^'
                . preg_quote($series, '/') . '(?:\s|$)/',
        ];
        foreach ($patterns as $file => $pattern) {
            if (preg_match_all($pattern, $contents[$file] ?? '') !== 1) {
                $errors[] = $file . ' must identify the dated changelog release ' . $version . ' exactly once.';
            }
        }
        if (!str_contains($contents['SUPPORT.md'] ?? '', '`~' . $version . '`')) {
            $errors[] = 'SUPPORT.md pin must match the dated changelog release.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function changelogLinkErrors(string $changelog, string $version): array
    {
        $comparison = '/^\[Unreleased\]: \S+\/compare\/v' . preg_quote($version, '/') . '\.\.\.HEAD$/m';
        if (preg_match($comparison, $changelog) === 1) {
            return [];
        }

        return ['CHANGELOG.md Unreleased comparison must start at the dated release.'];
    }

    /** @param array<string, string> $contents
     * @return list<string>
     */
    private function evidenceIndexErrors(array $contents, string $version): array
    {
        $pattern = '/\b' . preg_quote($version, '/') . '\b/';
        if (preg_match($pattern, $contents['docs/evidence/README.md'] ?? '') === 1) {
            return [];
        }

        return ['docs/evidence/README.md must reference the dated release ' . $version . '.'];
    }

    /** @return array{errors: list<string>, developing: bool} */
    private function planState(string $root, string $version): array
    {
        $plans = glob($root . '/docs/plans/[0-9]*.md');
        $plans = is_array($plans) ? $plans : [];
        if (count($plans) > 1) {
            return [
                'errors' => ['Release metadata permits at most one versioned active plan.'],
                'developing' => false,
            ];
        }
        if ($plans === []) {
            return ['errors' => [], 'developing' => false];
        }
        if (preg_match('/^(\d+\.\d+)\.md$/', basename($plans[0]), $plan) !== 1) {
            return ['errors' => ['The active plan filename must target a minor version.'], 'developing' => false];
        }
        if (version_compare($plan[1] . '.0', $version, '>')) {
            return ['errors' => [], 'developing' => true];
        }

        return [
            'errors' => ['The active development plan must target a version newer than the dated release.'],
            'developing' => false,
        ];
    }

    /** @param array<string, string> $contents
     * @return list<string>
     */
    private function baselineErrors(array $contents, string $version, ?string $previous, bool $developing): array
    {
        $ci = $contents['.github/workflows/ci.yml'] ?? '';
        preg_match_all('/git worktree add --detach [^\n]*?\b(v\d+\.\d+\.\d+)\b/', $ci, $matches);
        $baselineTag = $matches[1][0] ?? null;
        if (count($matches[0]) !== 1 || !is_string($baselineTag)) {
            return ['.github/workflows/ci.yml must identify exactly one benchmark baseline tag.'];
        }
        $errors = [];
        $benchmarking = $contents['docs/maintainers/benchmarking.md'] ?? '';
        if (preg_match_all('/immutable `' . preg_quote($baselineTag, '/') . '`/', $benchmarking) !== 1) {
            $errors[] = 'docs/maintainers/benchmarking.md must reference the CI benchmark baseline '
                . $baselineTag . ' exactly once.';
        }
        $allowed = [$version];
        if (!$developing && $previous !== null) {
            $allowed[] = $previous;
        }
        if (!in_array(substr($baselineTag, 1), $allowed, true)) {
            $errors[] = 'The benchmark baseline ' . $baselineTag . ' must identify '
                . implode(
                    ' or ',
                    array_map(static fn (string $allowedVersion): string => 'v' . $allowedVersion, $allowed),
                ) . '.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function artifactLabelErrors(string $root): array
    {
        $errors = [];
        foreach ($this->workflowFiles($root) as $file => $contents) {
            array_push($errors, ...$this->workflowArtifactErrors($file, $contents));
        }

        return $errors;
    }

    /** @return list<string> */
    private function workflowArtifactErrors(string $file, string $contents): array
    {
        $labels = [];
        $errors = [];
        foreach ($this->artifactLabels($contents) as $label) {
            if ($label === '') {
                $errors[] = $file . ' must give every artifact upload a name label.';
                continue;
            }
            if (in_array($label, $labels, true)) {
                $errors[] = $file . ' repeats the artifact label ' . $label . '.';
                continue;
            }
            $labels[] = $label;
        }

        return $errors;
    }

    /** @return list<string> */
    private function artifactLabels(string $contents): array
    {
        $labels = [];
        $offset = 0;
        while (($position = strpos($contents, 'actions/upload-artifact@', $offset)) !== false) {
            $labels[] = $this->artifactLabel(substr($contents, $position, 512));
            $offset = $position + 1;
        }

        return $labels;
    }

    private function artifactLabel(string $segment): string
    {
        if (preg_match('/\n\s*name:\s*([^\n]+)/', $segment, $match) !== 1) {
            return '';
        }

        return trim($match[1]);
    }

    /** @return array<string, string> */
    private function workflowFiles(string $root): array
    {
        $files = [];
        foreach (['yml', 'yaml'] as $extension) {
            $found = glob($root . '/.github/workflows/*.' . $extension);
            if (!is_array($found)) {
                continue;
            }
            foreach ($found as $path) {
                $contents = file_get_contents($path);
                if (is_string($contents)) {
                    $files[$this->relativePath($root, $path)] = $contents;
                }
            }
        }

        return $files;
    }

    private function relativePath(string $root, string $path): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
