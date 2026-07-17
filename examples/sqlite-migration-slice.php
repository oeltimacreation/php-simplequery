<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)')->execute();
$db->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, title TEXT, active INTEGER)',
)->execute();

$projectId = $db->transaction(function (Connection $connection): string {
    $id = $connection->table('projects')->insertGetId(['name' => 'Synthetic migration']);
    $connection->table('tasks')->insertMany([
        ['project_id' => (int) $id, 'title' => 'Characterize', 'active' => true],
        ['project_id' => (int) $id, 'title' => 'Migrate', 'active' => true],
    ]);

    return $id;
});

$allowedSorts = ['tasks.id', 'tasks.title'];
$allowlistedSort = static function (string $requested, array $allowed): string {
    if (!in_array($requested, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported sort field.');
    }

    return $requested;
};
$requestedSort = $allowlistedSort('tasks.id', $allowedSorts);

$rows = $db
    ->table('tasks')
    ->select('tasks.id', 'tasks.title', Identifier::of('projects.name')->as('project_name'))
    ->join('projects', 'projects.id', '=', 'tasks.project_id')
    ->where('tasks.active', true)
    ->orderBy($requestedSort)
    ->getAssociative();
$rawCount = $db->query('SELECT COUNT(*) AS total FROM tasks WHERE project_id = ?', [(int) $projectId])
    ->firstAssociative()['total'] ?? null;
if (!is_int($rawCount) && !is_string($rawCount)) {
    throw new RuntimeException('The raw count did not return an integer-compatible scalar.');
}

if (array_column($rows, 'title') !== ['Characterize', 'Migrate'] || (int) $rawCount !== 2) {
    throw new RuntimeException('The synthetic migration slice produced an unexpected result.');
}

$db->close();
fwrite(STDOUT, "SQLite migration slice passed.\n");
