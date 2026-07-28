<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Quality;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DocumentationLinkChecker
{
    /** @return list<string> */
    public function check(string $root): array
    {
        $errors = [];
        foreach ($this->markdownFiles($root) as $file) {
            $contents = file_get_contents($file);
            if (!is_string($contents)) {
                $errors[] = sprintf('Could not read %s.', $this->relativePath($root, $file));
                continue;
            }
            foreach ($this->relativeTargets($contents) as $target) {
                $path = $this->targetPath($file, $target);
                if (!file_exists($path)) {
                    $errors[] = sprintf(
                        '%s links to missing target %s.',
                        $this->relativePath($root, $file),
                        $target,
                    );
                }
            }
        }

        sort($errors);

        return $errors;
    }

    /** @return list<string> */
    private function markdownFiles(string $root): array
    {
        $files = [];
        foreach (['README.md', 'CHANGELOG.md', 'SECURITY.md'] as $rootFile) {
            $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rootFile;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        $docs = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docs';
        if (is_dir($docs)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function relativeTargets(string $markdown): array
    {
        preg_match_all('/!?\[[^\]]*\]\((<[^>]+>|[^\s)]+)(?:\s+["\'][^"\']*["\'])?\)/', $markdown, $matches);
        $targets = [];
        foreach ($matches[1] as $target) {
            $target = trim($target, '<>');
            if ($this->isRelativeFileTarget($target)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function isRelativeFileTarget(string $target): bool
    {
        return $target !== ''
            && $target[0] !== '#'
            && $target[0] !== '/'
            && !str_starts_with($target, '//')
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) !== 1;
    }

    private function targetPath(string $sourceFile, string $target): string
    {
        $path = explode('#', explode('?', $target, 2)[0], 2)[0];

        return dirname($sourceFile) . DIRECTORY_SEPARATOR . rawurldecode($path);
    }

    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
