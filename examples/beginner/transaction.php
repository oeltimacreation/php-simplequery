<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
)->execute();
$db->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NOT NULL)',
)->execute();

$db->transaction(function (Connection $connection): void {
    $projectId = $connection->table('projects')->insertGetId(['name' => 'Documentation']);
    $connection->table('tasks')->insert([
        'project_id' => (int) $projectId,
        'title' => 'Publish getting-started guide',
    ]);
});

$taskCount = $db->table('tasks')->count();
if ($taskCount !== 1) {
    throw new RuntimeException('The transaction did not commit both related writes.');
}

fwrite(STDOUT, "Transaction committed with {$taskCount} task.\n");
$db->close();
