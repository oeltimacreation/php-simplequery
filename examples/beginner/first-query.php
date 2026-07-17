<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, completed INTEGER NOT NULL)',
)->execute();

$id = $db->table('tasks')->insertGetId([
    'title' => 'Try PHP SimpleQuery',
    'completed' => false,
]);

$task = $db->table('tasks')->where('id', (int) $id)->firstAssociative();
if (($task['title'] ?? null) !== 'Try PHP SimpleQuery') {
    throw new RuntimeException('Could not read the inserted task.');
}

fwrite(STDOUT, "Created task #{$id}: {$task['title']}\n");
$db->close();
