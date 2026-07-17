<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceExists = false;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
        $sourceExists = true;
        break;
    }
}

$command = [PHP_BINARY, $root . '/vendor/bin/phpunit'];
if ($sourceExists) {
    $command[] = '-c';
    $command[] = $root . '/phpunit.coverage.xml.dist';
} else {
    $command[] = '--no-coverage';
    fwrite(STDOUT, "Coverage thresholds are armed; running tests without an empty source filter.\n");
}

$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start PHPUnit.\n");
    exit(2);
}

exit(proc_close($process));
