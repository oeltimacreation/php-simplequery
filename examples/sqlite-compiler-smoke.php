<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, active INTEGER NOT NULL)');
$pdo->exec("INSERT INTO users VALUES (1, 'person@example.test', 1)");

$db = Connection::fromPdo($pdo, Driver::Sqlite);
$compiled = $db->table('users')->select('id', 'email')->where('active', true)->compile();
$statement = $pdo->prepare($compiled->sql);
foreach ($compiled->bindings as $position => $binding) {
    $statement->bindValue($position + 1, $binding->value, PDO::PARAM_INT);
}
$statement->execute();
$row = $statement->fetch(PDO::FETCH_ASSOC);

if ($row !== ['id' => 1, 'email' => 'person@example.test']) {
    throw new RuntimeException('SQLite compiler smoke example returned an unexpected row.');
}

fwrite(STDOUT, "SQLite compiler smoke example passed.\n");
