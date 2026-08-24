<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReleaseConsistencyChecker
{
    /** @var list<string> */
    private const STANDARD_COMPOSER_COMMANDS = [
        'install',
        'update',
        'validate',
        'audit',
        'check-platform-reqs',
        'dump-autoload',
        'dumpautoload',
        'exec',
        'require',
        'remove',
        'config',
        'init',
        'diagnose',
        'show',
        'status',
        'why',
        'why-not',
        'outdated',
        'licenses',
        'archive',
        'create-project',
    ];

    /** @return list<string> */
    public function check(string $root): array
    {
        $errors = [];
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $composerJsonPath = $root . '/composer.json';
        if (!is_file($composerJsonPath)) {
            return ['composer.json is missing.'];
        }

        $composerContent = file_get_contents($composerJsonPath);
        if (!is_string($composerContent)) {
            return ['Could not read composer.json.'];
        }

        /** @var array{scripts?: array<string, mixed>} $composer */
        $composer = json_decode($composerContent, true) ?? [];
        $definedScripts = array_keys($composer['scripts'] ?? []);

        array_push($errors, ...$this->checkActivePlans($root));
        array_push($errors, ...$this->checkSupportAndSecurity($root));
        array_push($errors, ...$this->checkBenchmarkBaseline($root));
        array_push($errors, ...$this->checkMaintainedCommands($root, $definedScripts));
        array_push($errors, ...$this->checkConfigurationPaths($root));

        sort($errors);

        return $errors;
    }

    /** @return list<string> */
    private function checkActivePlans(string $root): array
    {
        $plansDir = $root . '/docs/plans';
        if (!is_dir($plansDir)) {
            return [];
        }

        $planFiles = $this->collectPlanFiles($plansDir);
        if (count($planFiles) > 1) {
            return [
                sprintf(
                    'Multiple active plan files found in docs/plans: %s. Exactly one active release plan is permitted.',
                    implode(', ', $planFiles),
                ),
            ];
        }

        if (count($planFiles) === 1) {
            return $this->checkActivePlanReferences($root, $planFiles[0]);
        }

        return [];
    }

    /** @return list<string> */
    private function collectPlanFiles(string $plansDir): array
    {
        $planFiles = [];
        $iterator = new RecursiveDirectoryIterator($plansDir);
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $filename = $file->getFilename();
                if ($filename !== 'README.md') {
                    $planFiles[] = $filename;
                }
            }
        }

        return $planFiles;
    }

    /** @return list<string> */
    private function checkActivePlanReferences(string $root, string $activePlan): array
    {
        $errors = [];
        foreach (['docs/README.md', 'docs/maintainers/README.md', 'docs/plans/README.md'] as $indexDoc) {
            $indexPath = $root . '/' . $indexDoc;
            if (!is_file($indexPath)) {
                continue;
            }
            $content = file_get_contents($indexPath);
            if (is_string($content) && !str_contains($content, $activePlan)) {
                $errors[] = sprintf('%s does not reference the active plan %s.', $indexDoc, $activePlan);
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function checkSupportAndSecurity(string $root): array
    {
        $securityPath = $root . '/SECURITY.md';
        $supportPath = $root . '/SUPPORT.md';

        if (!is_file($securityPath) || !is_file($supportPath)) {
            return [];
        }

        $security = file_get_contents($securityPath);
        $support = file_get_contents($supportPath);

        if (!is_string($security) || !is_string($support)) {
            return [];
        }

        if (preg_match('/\|\s*(\d+\.\d+)\.x\s*\|\s*✅\s*Current release line/i', $security, $matches) !== 1) {
            return [];
        }

        return $this->verifySupportMatchesSeries($support, $matches[1]);
    }

    /** @return list<string> */
    private function verifySupportMatchesSeries(string $support, string $currentSeries): array
    {
        $errors = [];
        $minorPattern = '/`' . preg_quote($currentSeries, '/') . '\.\d+`\s+is the current minor release/';
        if (preg_match($minorPattern, $support) !== 1) {
            $errors[] = sprintf(
                'SUPPORT.md current minor release does not match SECURITY.md current line %s.x.',
                $currentSeries,
            );
        }

        if (preg_match('/`~' . preg_quote($currentSeries, '/') . '\.\d+`/', $support) !== 1) {
            $errors[] = sprintf(
                'SUPPORT.md recommended pin does not match SECURITY.md current line ~%s.0.',
                $currentSeries,
            );
        }

        return $errors;
    }

    /** @return list<string> */
    private function checkBenchmarkBaseline(string $root): array
    {
        $ciPath = $root . '/.github/workflows/ci.yml';
        $benchDocPath = $root . '/docs/maintainers/benchmarking.md';

        if (!is_file($ciPath) || !is_file($benchDocPath)) {
            return [];
        }

        $ciContent = file_get_contents($ciPath);
        $benchContent = file_get_contents($benchDocPath);
        if (!is_string($ciContent) || !is_string($benchContent)) {
            return [];
        }

        if (preg_match('/git worktree add --detach "\$\{baseline_dir\}" (v\d+\.\d+\.\d+)/', $ciContent, $m) !== 1) {
            return [];
        }

        $baselineTag = $m[1];
        if (!str_contains($benchContent, $baselineTag)) {
            return [
                sprintf(
                    'docs/maintainers/benchmarking.md does not reference the CI benchmark baseline %s.',
                    $baselineTag,
                ),
            ];
        }

        return [];
    }

    /**
     * @param list<string> $definedScripts
     * @return list<string>
     */
    private function checkMaintainedCommands(string $root, array $definedScripts): array
    {
        $errors = [];
        $filesToCheck = $this->activeDocumentationFiles($root);

        foreach ($filesToCheck as $filePath) {
            $content = file_get_contents($filePath);
            if (!is_string($content)) {
                continue;
            }

            $relativePath = $this->relativePath($root, $filePath);
            preg_match_all('/\bcomposer\s+([a-z0-9]+(?:[:-][a-z0-9]+)*)/', $content, $matches);
            foreach ($matches[1] as $command) {
                if (in_array($command, self::STANDARD_COMPOSER_COMMANDS, true)) {
                    continue;
                }
                if (!in_array($command, $definedScripts, true)) {
                    $errors[] = sprintf(
                        '%s references unmaintained composer command "%s".',
                        $relativePath,
                        $command,
                    );
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function checkConfigurationPaths(string $root): array
    {
        $codescenePath = $root . '/.codescene/code-health-rules.json';
        if (!is_file($codescenePath)) {
            return [];
        }

        $codesceneContent = file_get_contents($codescenePath);
        if (!is_string($codesceneContent)) {
            return [];
        }

        /** @var array{rule_sets?: list<array{matching_content_path?: string}>} $codescene */
        $codescene = json_decode($codesceneContent, true) ?? [];
        $errors = [];

        foreach ($codescene['rule_sets'] ?? [] as $ruleSet) {
            $path = $ruleSet['matching_content_path'] ?? null;
            if ($path !== null && !file_exists($root . '/' . $path)) {
                $errors[] = sprintf(
                    '.codescene/code-health-rules.json configures non-existent path: %s.',
                    $path,
                );
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function activeDocumentationFiles(string $root): array
    {
        $files = [];

        foreach (['README.md', 'AGENTS.md', 'SUPPORT.md', 'SECURITY.md'] as $rootDoc) {
            $path = $root . '/' . $rootDoc;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        $directories = [
            $root . '/docs/guides',
            $root . '/docs/maintainers',
            $root . '/docs/reference',
            $root . '/.github/workflows',
        ];

        foreach ($directories as $dir) {
            array_push($files, ...$this->collectDirectoryDocumentationFiles($dir));
        }

        return $files;
    }

    /** @return list<string> */
    private function collectDirectoryDocumentationFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $this->isDocumentationFile($file)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function isDocumentationFile(SplFileInfo $file): bool
    {
        return $file->isFile() && in_array($file->getExtension(), ['md', 'yml', 'yaml'], true);
    }

    private function relativePath(string $root, string $filePath): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($filePath, $prefix) ? substr($filePath, strlen($prefix)) : $filePath;
    }
}
