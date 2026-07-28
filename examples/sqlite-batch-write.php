<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE imports (id INTEGER PRIMARY KEY, label TEXT NOT NULL)',
)->execute();

$rows = [
    ['id' => 1, 'label' => 'first'],
    ['id' => 2, 'label' => 'second'],
    ['id' => 3, 'label' => 'third'],
    ['id' => 4, 'label' => 'fourth'],
    ['id' => 5, 'label' => 'fifth'],
];
$applicationBatchSize = 2;
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$applicationRequiresAtomicImport = !in_array('--non-atomic', $arguments, true);
$writeBatches = static function (Connection $connection) use ($rows, $applicationBatchSize): int {
    $affected = 0;
    foreach (array_chunk($rows, $applicationBatchSize) as $batch) {
        $affected += $connection->table('imports')->insertMany($batch);
    }

    return $affected;
};

$affected = $applicationRequiresAtomicImport
    ? $db->transaction($writeBatches)
    : $writeBatches($db);

if ($affected !== 5 || $db->table('imports')->count() !== 5) {
    throw new RuntimeException('SQLite batch-write example produced an unexpected result.');
}

$db->close();
fwrite(STDOUT, "SQLite batch-write example passed.\n");
