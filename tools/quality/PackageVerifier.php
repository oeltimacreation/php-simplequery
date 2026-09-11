<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RuntimeException;
use ZipArchive;

final class PackageVerifier
{
    /** @return array{files: int, bytes: int, sha256: string} */
    public function inspect(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open package archive.');
        }
        try {
            $prefix = '';
            if ($zip->locateName('composer.json') === false) {
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $name = $zip->getNameIndex($index);
                    if (is_string($name) && preg_match('~^([^/]+/)composer.json$~', $name, $match) === 1) {
                        $prefix = $match[1];
                        break;
                    }
                }
            }
            if ($prefix !== '' && preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._-]*/$~', $prefix) !== 1) {
                throw new RuntimeException('Invalid archive root prefix.');
            }
            $files = [];
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false || !str_starts_with($stat['name'], $prefix)) {
                    throw new RuntimeException('Malformed package entry.');
                }
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000) {
                    throw new RuntimeException('Package links are not allowed.');
                }
                $name = substr($stat['name'], strlen($prefix));
                if ($name === '') {
                    continue;
                }
                $this->validateName(rtrim($name, '/'));
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (isset($files[$name])) {
                    throw new RuntimeException('Duplicate package entry: ' . $name);
                }
                $files[$name] = true;
                $bytes += $stat['size'];
            }
            foreach (
                ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'SUPPORT.md',
                'src/Connection.php', 'src/Testing/CompilerConnection.php', 'src/Testing/CompiledQueryAssertions.php',
                'docs/guides/upgrading.md', 'docs/guides/getting-started.md', 'examples/README.md'] as $required
            ) {
                if (!isset($files[$required])) {
                    throw new RuntimeException('Required package file missing: ' . $required);
                }
            }
            if (count($files) > 225 || $bytes > 786432) {
                throw new RuntimeException('Package exceeds the reviewed 225-file / 768-KiB budget.');
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Could not hash package.');
            }

            return ['files' => count($files), 'bytes' => $bytes, 'sha256' => $hash];
        } finally {
            $zip->close();
        }
    }

    public function composerJson(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open package metadata.');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (is_string($name) && preg_match('~^(?:[^/]+/)?composer.json$~', $name) === 1) {
                    $json = $zip->getFromIndex($index);
                    if (is_string($json)) {
                        return $json;
                    }
                }
            }
            throw new RuntimeException('Package metadata is missing.');
        } finally {
            $zip->close();
        }
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
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new RuntimeException('Package check command failed: ' . $command[0]);
        }

        return $output;
    }

    private function validateName(string $name): void
    {
        if (str_contains($name, '\\') || preg_match('~(^|/)(\.[^/]*|vendor|coverage)(/|$)~', $name) === 1) {
            throw new RuntimeException('Forbidden package path: ' . $name);
        }
        $root = explode('/', $name)[0];
        $directories = ['src', 'docs', 'examples'];
        $rootFiles = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md',
            'CONTRIBUTING.md', 'SECURITY.md', 'SUPPORT.md'];
        if (!in_array($root, $directories, true) && !in_array($name, $rootFiles, true)) {
            throw new RuntimeException('Unexpected package root: ' . $name);
        }
    }
}
