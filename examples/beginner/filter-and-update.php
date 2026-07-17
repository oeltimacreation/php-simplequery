<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, completed INTEGER NOT NULL)',
)->execute();
$db->table('tasks')->insertMany([
    ['title' => 'Write release notes', 'completed' => false],
    ['title' => 'Run tests', 'completed' => false],
    ['title' => 'Archive old draft', 'completed' => true],
]);

$openTasks = $db
    ->table('tasks')
    ->select('id', 'title')
    ->where('completed', false)
    ->orderBy('id')
    ->getAssociative();

$updated = $db->table('tasks')->where('id', 1)->update(['completed' => true]);
$remaining = $db->table('tasks')->where('completed', false)->count();

if (array_column($openTasks, 'title') !== ['Write release notes', 'Run tests'] || $updated !== 1 || $remaining !== 1) {
    throw new RuntimeException('The query-builder example produced an unexpected result.');
}

fwrite(STDOUT, "Updated {$updated} task; {$remaining} task remains open.\n");
$db->close();
