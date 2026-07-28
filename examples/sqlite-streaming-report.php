<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE report_rows (id INTEGER PRIMARY KEY, category TEXT NOT NULL, amount INTEGER NOT NULL)',
)->execute();
$db->table('report_rows')->insertMany([
    ['id' => 1, 'category' => 'books', 'amount' => 20],
    ['id' => 2, 'category' => 'books', 'amount' => 30],
    ['id' => 3, 'category' => 'games', 'amount' => 40],
]);

$cursor = $db
    ->table('report_rows')
    ->select('id', 'category', 'amount')
    ->orderBy('id')
    ->iterateAssociative();
$visitedIds = [];

try {
    foreach ($cursor as $row) {
        $visitedIds[] = $row['id'];
        if (count($visitedIds) === 2) {
            break;
        }
    }
} finally {
    $cursor->close();
}

if ($visitedIds !== [1, 2] || !$cursor->isClosed()) {
    throw new RuntimeException('Streaming report example did not close its early-terminated cursor.');
}

$db->close();
fwrite(STDOUT, "SQLite streaming report example passed.\n");
