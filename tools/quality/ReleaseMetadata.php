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
            '.github/workflows/ci.yml', 'docs/maintainers/benchmarking.md'] as $file
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
        $series = substr($version, 0, (int) strrpos($version, '.'));
        if ($publishedVersion !== null && $publishedVersion !== $version) {
            $errors[] = 'CHANGELOG.md does not match the explicit published-version provenance input.';
        }
        $patterns = [
            'SECURITY.md' => '/\|\s*' . preg_quote($series, '/') . '\.x\s*\|\s*✅\s*Current release line/i',
            'SUPPORT.md' => '/`' . preg_quote($version, '/') . '`\s+is the current minor release/',
            'README.md' => '/composer require oeltimacreation\/php-simplequery:\^'
                . preg_quote($series, '/') . '(?:\s|$)/',
            '.github/workflows/ci.yml' => '/git worktree add --detach .* v' . preg_quote($version, '/') . '(?:\s|$)/',
            'docs/maintainers/benchmarking.md' => '/immutable `v' . preg_quote($version, '/') . '`/',
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
        $plans = glob($root . '/docs/plans/[0-9]*.md');
        if (!is_array($plans) || count($plans) !== 1) {
            return [...$errors, 'Release metadata requires one versioned active plan.'];
        }
        if (
            preg_match('/^(\d+\.\d+)\.md$/', basename($plans[0]), $plan) !== 1
            || version_compare($plan[1] . '.0', $version, '<=')
        ) {
            $errors[] = 'The active development plan must target a version newer than the dated release.';
        }

        return $errors;
    }
}
