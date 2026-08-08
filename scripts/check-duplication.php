<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\DuplicationGate;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$errors = (new DuplicationGate())->check($root);
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Duplication gate passed.\n");
