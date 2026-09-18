<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;

require dirname(__DIR__) . '/vendor/autoload.php';

// A factory creates a new owner; it never reconnects an old query or wrapper.
$create = static fn (): Connection => Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$connection = $create();
$oldQuery = $connection->query('SELECT ? AS value', ['first unit']);
$row = $oldQuery->firstAssociative();
if (($row['value'] ?? null) !== 'first unit' || !$connection->isReusable()) {
    throw new RuntimeException('The first execution unit did not complete cleanly.');
}

// At the application boundary, explicitly retire this owner. In a network
// application, a lost/uncertain owner is discarded without replaying its work.
$connection->discard();
if (!$connection->isClosed()) {
    throw new RuntimeException('Discard must be terminal.');
}
$connection = $create();

try {
    $oldQuery->firstAssociative();
    throw new RuntimeException('A stale query must not follow a replacement.');
} catch (ConnectionException) {
    // Release the old artifact; later work must obtain a new query explicitly.
    unset($oldQuery);
}

$row = $connection->query('SELECT ? AS value', ['next unit'])->firstAssociative();
if (($row['value'] ?? null) !== 'next unit') {
    throw new RuntimeException('The replacement did not execute new work.');
}
$connection->close();
fwrite(STDOUT, "Explicit connection lifecycle example passed.\n");
