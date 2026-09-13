<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

final class ReleaseMetadata
{
    /** @return list<string> */
    public function check(string $root, ?string $publishedVersion = null): array
    {
        $contents = [];
        $errors = [];
        foreach (
            ['CHANGELOG.md', 'README.md', 'SUPPORT.md', 'SECURITY.md',
            '.github/workflows/ci.yml', 'docs/maintainers/benchmarking.md',
            'docs/evidence/README.md'] as $file
        ) {
            $text = is_file($root . '/' . $file) ? file_get_contents($root . '/' . $file) : false;
            if (!is_string($text)) {
                $errors[] = $file . ' is required for release consistency.';
            } else {
                $contents[$file] = $text;
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $changelog = $contents['CHANGELOG.md'] ?? '';
        if (!str_contains($changelog, '## [Unreleased]')) {
            $errors[] = 'CHANGELOG.md requires an Unreleased section.';
        }
        preg_match('/^## \[(?!Unreleased\])[^\n]+/m', $changelog, $heading);
        if (
            preg_match(
                '/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$/',
                $heading[0] ?? '',
                $release
            ) !== 1
        ) {
            return [...$errors, 'CHANGELOG.md requires a recognized dated release.'];
        }
        $version = $release[1];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $release[2]);
        if ($date === false || $date->format('Y-m-d') !== $release[2]) {
            $errors[] = 'CHANGELOG.md release date is invalid.';
        }
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', $changelog, $dated);
        $previous = $dated[1][1] ?? null;
        $series = substr($version, 0, (int) strrpos($version, '.'));
        if ($publishedVersion !== null && $publishedVersion !== $version) {
            $errors[] = 'CHANGELOG.md does not match the explicit published-version provenance input.';
        }
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
        $comparison = '/^\[Unreleased\]: \S+\/compare\/v' . preg_quote($version, '/') . '\.\.\.HEAD$/m';
        if (preg_match($comparison, $changelog) !== 1) {
            $errors[] = 'CHANGELOG.md Unreleased comparison must start at the dated release.';
        }
        if (preg_match('/\b' . preg_quote($version, '/') . '\b/', $contents['docs/evidence/README.md'] ?? '') !== 1) {
            $errors[] = 'docs/evidence/README.md must reference the dated release ' . $version . '.';
        }
        $plans = glob($root . '/docs/plans/[0-9]*.md');
        $plans = is_array($plans) ? $plans : [];
        $planVersion = null;
        if (count($plans) > 1) {
            $errors[] = 'Release metadata permits at most one versioned active plan.';
        } elseif (count($plans) === 1) {
            if (preg_match('/^(\d+\.\d+)\.md$/', basename($plans[0]), $plan) !== 1) {
                $errors[] = 'The active plan filename must target a minor version.';
            } else {
                $planVersion = $plan[1] . '.0';
                if (version_compare($planVersion, $version, '<=')) {
                    $errors[] = 'The active development plan must target a version newer than the dated release.';
                    $planVersion = null;
                }
            }
        }
        array_push($errors, ...$this->baselineErrors($contents, $version, $previous, $planVersion !== null));
        array_push($errors, ...$this->artifactLabelErrors($root));

        return $errors;
    }

    /**
     * @param array<string, string> $contents
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
        if (
            preg_match_all(
                '/immutable `' . preg_quote($baselineTag, '/') . '`/',
                $contents['docs/maintainers/benchmarking.md'] ?? '',
            ) !== 1
        ) {
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
            $labels = [];
            $offset = 0;
            while (($position = strpos($contents, 'actions/upload-artifact@', $offset)) !== false) {
                $segment = substr($contents, $position, 512);
                $label = null;
                if (preg_match('/\n\s*name:\s*([^\n]+)/', $segment, $match) === 1) {
                    $label = trim($match[1]);
                }
                if ($label === null || $label === '') {
                    $errors[] = $file . ' must give every artifact upload a name label.';
                } elseif (in_array($label, $labels, true)) {
                    $errors[] = $file . ' repeats the artifact label ' . $label . '.';
                } else {
                    $labels[] = $label;
                }
                $offset = $position + 1;
            }
        }

        return $errors;
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
