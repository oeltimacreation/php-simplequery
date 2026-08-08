<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['src', 'tests', 'tools', 'scripts', 'benchmarks', 'examples'];
$files = [];

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
foreach ($files as $file) {
    $command = [PHP_BINARY, '-l', $file];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, sprintf("Could not start PHP lint for %s.\n", $file));
        exit(2);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, $stdout . $stderr);
        exit($exitCode);
    }
}

printf("PHP syntax is valid for %d files.\n", count($files));

/*
 * The minimum supported runtime is PHP 8.2. This block makes the lower bound
 * part of the local `composer check` path (SQ-0434): the dependency platform is
 * pinned to 8.2, `composer check-platform-reqs` verifies the environment, and
 * PHPStan analyzes every source file with `phpVersion: 80200`, which rejects
 * accidental PHP 8.3+ syntax and features. The `#[Override]` attribute is
 * deliberate on an 8.2-supported library (see ADR-015); it is an unresolvable
 * but harmless no-op attribute below PHP 8.3 and is enforced by PHPStan's
 * override check on 8.3+.
 */
$composerPath = $root . '/composer.json';
$composerRaw = is_file($composerPath) ? (string) file_get_contents($composerPath) : '';
$composer = json_decode($composerRaw, true);
if (!is_array($composer)) {
    fwrite(STDERR, "composer.json must require php ^8.2 (PHP 8.2 runtime floor).\n");
    exit(1);
}
$requireSection = is_array($composer['require'] ?? null) ? $composer['require'] : null;
/** @var string|null $requirePhp */
$requirePhp = is_array($requireSection) ? ($requireSection['php'] ?? null) : null;
if ($requirePhp !== '^8.2') {
    fwrite(STDERR, "composer.json must require php ^8.2 (PHP 8.2 runtime floor).\n");
    exit(1);
}
$configSection = is_array($composer['config'] ?? null) ? $composer['config'] : null;
/** @var string|null $configuredPlatform */
$configuredPlatform = null;
$platformSection = is_array($configSection) ? ($configSection['platform'] ?? null) : null;
if (is_array($platformSection) && is_string($platformSection['php'] ?? null)) {
    $configuredPlatform = $platformSection['php'];
}
if ($configuredPlatform !== '8.2.0') {
    fwrite(STDERR, "composer.json must pin config.platform.php to 8.2.0.\n");
    exit(1);
}

foreach (['phpstan.neon', 'phpstan.consumer.neon'] as $config) {
    $contents = @file_get_contents($root . '/' . $config);
    if (!is_string($contents) || preg_match('/^\s*phpVersion:\s*80200\s*$/m', $contents) !== 1) {
        fwrite(STDERR, sprintf("%s must analyze with phpVersion: 80200 to lint for PHP 8.3+ syntax.\n", $config));
        exit(1);
    }
}

printf("PHP 8.2 runtime gate is enforced (composer platform, phpstan phpVersion).\n");
