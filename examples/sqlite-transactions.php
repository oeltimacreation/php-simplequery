<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)',
)->execute();

$returned = $db->transaction(function (Connection $connection): int {
    $connection->table('accounts')->insert(['name' => 'outer']);

    try {
        $connection->transaction(function (Connection $nested): void {
            $nested->table('accounts')->insert(['name' => 'rolled-back-inner']);
            throw new RuntimeException('Roll back only this savepoint.');
        });
    } catch (RuntimeException) {
        $connection->table('accounts')->insert(['name' => 'outer-continued']);
    }

    return $connection->table('accounts')->count();
});

$names = array_column($db->table('accounts')->orderBy('id')->getAssociative(), 'name');
if ($returned !== 2 || $names !== ['outer', 'outer-continued']) {
    throw new RuntimeException('Managed transaction example produced an unexpected result.');
}

$db->close();
fwrite(STDOUT, "SQLite managed transaction example passed.\n");
