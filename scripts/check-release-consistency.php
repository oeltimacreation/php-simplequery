<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\ReleaseConsistencyChecker;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$options = getopt('', ['published-version:']);
$publishedVersion = is_array($options) ? ($options['published-version'] ?? null) : null;
if (
    $publishedVersion !== null && (!is_string($publishedVersion)
    || preg_match('/^\d+\.\d+\.\d+$/', $publishedVersion) !== 1)
) {
    fwrite(STDERR, "published-version must be a non-prefixed release version.\n");
    exit(2);
}
$errors = (new ReleaseConsistencyChecker())->check($root, $publishedVersion);
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Release metadata, plans, baseline labels, and configuration paths are consistent.\n");
