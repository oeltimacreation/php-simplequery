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
            array_push($errors, ...$this->fileErrors($root, $file));
        }

        sort($errors);

        return $errors;
    }

    /** @return list<string> */
    private function markdownFiles(string $root): array
    {
        $files = $this->rootMarkdownFiles($root);
        array_push($files, ...$this->documentationMarkdownFiles($root));
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function rootMarkdownFiles(string $root): array
    {
        $files = [];
        foreach (['README.md', 'CHANGELOG.md', 'SECURITY.md'] as $rootFile) {
            $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rootFile;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function documentationMarkdownFiles(string $root): array
    {
        $docs = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docs';
        if (!is_dir($docs)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs));
        foreach ($iterator as $file) {
            $path = $this->markdownPath($file);
            if ($path !== null) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function markdownPath(mixed $file): ?string
    {
        if (!$file instanceof SplFileInfo) {
            return null;
        }
        if (!$file->isFile()) {
            return null;
        }
        if ($file->getExtension() !== 'md') {
            return null;
        }

        return $file->getPathname();
    }

    /** @return list<string> */
    private function fileErrors(string $root, string $file): array
    {
        $contents = file_get_contents($file);
        if (!is_string($contents)) {
            return [sprintf('Could not read %s.', $this->relativePath($root, $file))];
        }

        $errors = [];
        foreach ($this->relativeTargets($contents) as $target) {
            $error = $this->targetError($root, $file, $target);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function targetError(string $root, string $file, string $target): ?string
    {
        if (file_exists($this->targetPath($file, $target))) {
            return null;
        }

        return sprintf(
            '%s links to missing target %s.',
            $this->relativePath($root, $file),
            $target,
        );
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
