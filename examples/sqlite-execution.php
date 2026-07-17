<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$database->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, completed INTEGER NOT NULL)',
)->execute();
$id = $database->table('tasks')->insertGetId([
    'title' => 'Verify the execution layer',
    'completed' => false,
]);
$task = $database->table('tasks')->where('id', (int) $id)->firstAssociative();

if ($id !== '1' || ($task['title'] ?? null) !== 'Verify the execution layer') {
    throw new RuntimeException('SQLite execution example returned an unexpected result.');
}

$database->close();
fwrite(STDOUT, "SQLite execution example passed.\n");
