<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = CompilerConnection::for(Driver::MySql);
$compiled = $db
    ->table('users')
    ->select('id', 'email')
    ->where('active', true)
    ->orderBy('id')
    ->limit(10)
    ->compile();

CompiledQueryAssertions::assertMatches(
    $compiled,
    'SELECT `id`, `email` FROM `users` WHERE `active` = ? ORDER BY `id` ASC LIMIT 10',
    [1],
    [ParameterType::Integer],
);

fwrite(STDOUT, "Compiler assertion example passed.\n");
