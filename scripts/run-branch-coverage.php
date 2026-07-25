<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$environment = getenv();
$environment['XDEBUG_MODE'] = 'coverage';

$command = [
    PHP_BINARY,
    $root . '/vendor/bin/phpunit',
    '-c',
    $root . '/phpunit.branch-coverage.xml.dist',
];
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start the Xdebug branch-coverage run.\n");
    exit(2);
}

exit(proc_close($process));
