# Getting started

This guide starts with SQLite because it needs no database server. After that,
the same query-builder calls work with MariaDB and MySQL.

## 1. Install the package

```bash
composer require oeltimacreation/php-simplequery:^0.6
php -m | grep -E 'PDO|pdo_sqlite'
```

For MariaDB or MySQL, ensure `pdo_mysql` is enabled instead of—or in addition
to—`pdo_sqlite`.

## 2. Create your first database and query

Create `first-query.php` in a Composer project:

```php
<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

require __DIR__ . '/vendor/autoload.php';

$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
$db->query(
    'CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, completed INTEGER NOT NULL)',
)->execute();

$id = $db->table('tasks')->insertGetId([
    'title' => 'Try PHP SimpleQuery',
    'completed' => false,
]);

$task = $db->table('tasks')->where('id', (int) $id)->firstAssociative();
var_dump($task);
```

Run it with `php first-query.php`. The result is an associative array with the
row you inserted. `insertGetId()` returns a string because database generated
IDs may be wider than PHP's integer range.

The equivalent source-checkout example is
[`examples/beginner/first-query.php`](../../examples/beginner/first-query.php).

## 3. Filter, sort, and fetch many rows

Fluent clause calls mutate the builder. Read terminals do not, so you can
compile or execute the same builder repeatedly while its clauses are unchanged.

```php
$openTasks = $db
    ->table('tasks')
    ->select('id', 'title')
    ->where('completed', false)
    ->orderBy('id')
    ->getAssociative();
```

Values such as `false` are bound safely. Do not pass request input directly as
a table, column, or sort field; those are identifiers, not values. Allowlist
them first. See [raw SQL and security](raw-sql-and-security.md).

## 4. Use a transaction for related writes

`transaction()` commits the callback result, or rolls back and rethrows when
the callback throws. Nested callbacks use savepoints.

```php
$db->transaction(function (Connection $connection): void {
    $projectId = $connection->table('projects')->insertGetId(['name' => 'Website']);
    $connection->table('tasks')->insert([
        'project_id' => (int) $projectId,
        'title' => 'Publish',
        'completed' => false,
    ]);
});
```

SimpleQuery will not adopt a transaction that another library started. Keep
open cursors out of transaction boundaries. The full rules are in
[transactions](transactions.md).

## 5. Connect to MariaDB or MySQL

Choose the driver explicitly. PDO's `mysql` driver alone cannot reliably tell
MariaDB from MySQL, especially through a proxy.

```php
$db = Connection::connect(
    driver: Driver::MariaDb,
    dsn: 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    username: 'app',
    password: 'secret',
);
```

Use `Driver::MySql` for MySQL. The default connection policy uses exceptions,
native prepares, buffered MySQL-family queries, changed-row counts,
non-persistent connections, and `utf8mb4`. For an existing PDO connection use
`Connection::fromPdo($pdo, Driver::MariaDb)`.

## Next steps

- Run the [beginner examples](../../examples/README.md).
- Learn complex filters and joins in the [query builder guide](query-builder.md).
- Check engine-specific limits in [database support](../reference/database-support.md).
- Add compile assertions to your application tests with
  [testing applications](testing-applications.md).
