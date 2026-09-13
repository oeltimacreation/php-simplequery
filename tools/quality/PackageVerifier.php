<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;
use ZipArchive;

final class PackageVerifier
{
    private const REQUIRED_FILES = [
        'composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'SUPPORT.md',
        'src/Connection.php', 'src/Testing/CompilerConnection.php', 'src/Testing/CompiledQueryAssertions.php',
        'docs/guides/upgrading.md', 'docs/guides/getting-started.md', 'examples/README.md',
    ];

    private const MAX_FILES = 225;

    private const MAX_BYTES = 786432;

    private const ALLOWED_ROOTS = ['src', 'docs', 'examples'];

    private const ALLOWED_ROOT_FILES = [
        'composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md',
        'CONTRIBUTING.md', 'SECURITY.md', 'SUPPORT.md',
    ];

    /** @return array{files: int, bytes: int, sha256: string} */
    public function inspect(string $path): array
    {
        $zip = $this->openArchive($path, 'Could not open package archive.');
        try {
            $entries = $this->collectEntries($zip, $this->resolvePrefix($zip));
            $this->assertRequiredFiles($entries['files']);
            $this->assertBudget(count($entries['files']), $entries['bytes']);
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Could not hash package.');
            }

            return ['files' => count($entries['files']), 'bytes' => $entries['bytes'], 'sha256' => $hash];
        } finally {
            $zip->close();
        }
    }

    private function openArchive(string $path, string $failure): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException($failure);
        }

        return $zip;
    }

    private function resolvePrefix(ZipArchive $zip): string
    {
        if ($zip->locateName('composer.json') !== false) {
            return '';
        }
        $prefix = $this->nestedPrefix($zip);
        if ($prefix === '') {
            return '';
        }
        if (preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._-]*/$~', $prefix) !== 1) {
            throw new RuntimeException('Invalid archive root prefix.');
        }

        return $prefix;
    }

    private function nestedPrefix(ZipArchive $zip): string
    {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || preg_match('~^([^/]+/)composer.json$~', $name, $match) !== 1) {
                continue;
            }

            return $match[1];
        }

        return '';
    }

    /** @return array{files: array<string, true>, bytes: int} */
    private function collectEntries(ZipArchive $zip, string $prefix): array
    {
        $files = [];
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !str_starts_with($stat['name'], $prefix)) {
                throw new RuntimeException('Malformed package entry.');
            }
            $this->assertNotLink($zip, $index);
            $name = $this->entryName($stat['name'], $prefix);
            if ($name === null) {
                continue;
            }
            if (isset($files[$name])) {
                throw new RuntimeException('Duplicate package entry: ' . $name);
            }
            $files[$name] = true;
            $bytes += $stat['size'];
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function entryName(string $entry, string $prefix): ?string
    {
        $name = substr($entry, strlen($prefix));
        if ($name === '') {
            return null;
        }
        $this->validateName(rtrim($name, '/'));
        if (str_ends_with($name, '/')) {
            return null;
        }

        return $name;
    }

    private function assertNotLink(ZipArchive $zip, int $index): void
    {
        $zip->getExternalAttributesIndex($index, $system, $attributes);
        if (($attributes >> 16 & 0170000) === 0120000) {
            throw new RuntimeException('Package links are not allowed.');
        }
    }

    /** @param array<string, true> $files */
    private function assertRequiredFiles(array $files): void
    {
        foreach (self::REQUIRED_FILES as $required) {
            if (!isset($files[$required])) {
                throw new RuntimeException('Required package file missing: ' . $required);
            }
        }
    }

    private function assertBudget(int $fileCount, int $bytes): void
    {
        if ($fileCount > self::MAX_FILES || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('Package exceeds the reviewed 225-file / 768-KiB budget.');
        }
    }

    public function composerJson(string $path): string
    {
        $zip = $this->openArchive($path, 'Could not open package metadata.');
        try {
            return $this->findComposerJson($zip);
        } finally {
            $zip->close();
        }
    }

    private function findComposerJson(ZipArchive $zip): string
    {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $json = $this->composerJsonAt($zip, $index);
            if (is_string($json)) {
                return $json;
            }
        }

        throw new RuntimeException('Package metadata is missing.');
    }

    private function composerJsonAt(ZipArchive $zip, int $index): ?string
    {
        $name = $zip->getNameIndex($index);
        if (!is_string($name)) {
            return null;
        }
        if (preg_match('~^(?:[^/]+/)?composer.json$~', $name) !== 1) {
            return null;
        }
        $json = $zip->getFromIndex($index);

        return is_string($json) ? $json : null;
    }

    /** @param list<string> $command */
    public function runCommand(array $command, string $directory): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
            $directory
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start package check command.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Package check command failed: ' . $command[0]);
        }
        if (!is_string($output)) {
            throw new RuntimeException('Package check command failed: ' . $command[0]);
        }

        return $output;
    }

    private function validateName(string $name): void
    {
        $this->rejectForbiddenName($name);
        if ($this->isAllowedPackageName($name)) {
            return;
        }

        throw new RuntimeException('Unexpected package root: ' . $name);
    }

    private function rejectForbiddenName(string $name): void
    {
        if (str_contains($name, '\\') || preg_match('~(^|/)(\.[^/]*|vendor|coverage)(/|$)~', $name) === 1) {
            throw new RuntimeException('Forbidden package path: ' . $name);
        }
    }

    private function isAllowedPackageName(string $name): bool
    {
        if (in_array(explode('/', $name)[0], self::ALLOWED_ROOTS, true)) {
            return true;
        }

        return in_array($name, self::ALLOWED_ROOT_FILES, true);
    }
}
