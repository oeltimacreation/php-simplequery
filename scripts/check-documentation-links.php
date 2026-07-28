<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\DocumentationLinkChecker;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$errors = (new DocumentationLinkChecker())->check($root);
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Relative documentation links are valid.\n");
