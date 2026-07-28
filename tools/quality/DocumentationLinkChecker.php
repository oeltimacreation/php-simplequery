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
        $rootDirectory = new SplFileInfo($root);
        $errors = [];
        foreach ($this->markdownFiles($rootDirectory) as $file) {
            array_push($errors, ...$this->fileErrors($rootDirectory, $file));
        }

        sort($errors);

        return $errors;
    }

    /** @return list<SplFileInfo> */
    private function markdownFiles(SplFileInfo $root): array
    {
        $files = $this->rootMarkdownFiles($root);
        array_push($files, ...$this->documentationMarkdownFiles($root));
        usort(
            $files,
            static fn (SplFileInfo $left, SplFileInfo $right): int => $left->getPathname() <=> $right->getPathname(),
        );

        return $files;
    }

    /** @return list<SplFileInfo> */
    private function rootMarkdownFiles(SplFileInfo $root): array
    {
        $files = [];
        foreach (['README.md', 'CHANGELOG.md', 'SECURITY.md'] as $rootFile) {
            $file = new SplFileInfo($root->getPathname() . DIRECTORY_SEPARATOR . $rootFile);
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return list<SplFileInfo> */
    private function documentationMarkdownFiles(SplFileInfo $root): array
    {
        $docs = new SplFileInfo($root->getPathname() . DIRECTORY_SEPARATOR . 'docs');
        if (!$docs->isDir()) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs->getPathname()));
        foreach ($iterator as $file) {
            $markdownFile = $this->markdownFile($file);
            if ($markdownFile !== null) {
                $files[] = $markdownFile;
            }
        }

        return $files;
    }

    private function markdownFile(mixed $file): ?SplFileInfo
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

        return $file;
    }

    /** @return list<string> */
    private function fileErrors(SplFileInfo $root, SplFileInfo $file): array
    {
        $contents = file_get_contents($file->getPathname());
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

    private function targetError(SplFileInfo $root, SplFileInfo $file, string $target): ?string
    {
        $path = $this->targetPath($file, $target);
        if (file_exists($path->getPathname())) {
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

    private function targetPath(SplFileInfo $sourceFile, string $target): SplFileInfo
    {
        $path = explode('#', explode('?', $target, 2)[0], 2)[0];

        return new SplFileInfo($sourceFile->getPath() . DIRECTORY_SEPARATOR . rawurldecode($path));
    }

    private function relativePath(SplFileInfo $root, SplFileInfo $file): string
    {
        $prefix = rtrim($root->getPathname(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = $file->getPathname();

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
