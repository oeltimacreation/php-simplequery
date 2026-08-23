<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\ReleaseConsistencyChecker;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$errors = (new ReleaseConsistencyChecker())->check($root);
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Release metadata, plans, baseline labels, and configuration paths are consistent.\n");
