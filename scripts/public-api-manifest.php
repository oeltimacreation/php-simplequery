<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\PublicApiManifest;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$fixture = $root . '/tests/Fixtures/Contracts/public-api.json';
$actual = (new PublicApiManifest())->encode($root);
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (in_array('--write', $arguments, true)) {
    file_put_contents($fixture, $actual);
    fwrite(STDOUT, "Public API manifest updated.\n");
    exit(0);
}

$expected = is_file($fixture) ? file_get_contents($fixture) : false;
if ($expected !== $actual) {
    fwrite(STDERR, "Public API manifest differs from the reviewed fixture. Run composer public-api:update.\n");
    exit(1);
}

fwrite(STDOUT, "Public API manifest matches the reviewed fixture.\n");
