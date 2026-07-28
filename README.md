# OeltimaCreation PHP SimpleQuery

PHP SimpleQuery is a small, framework-agnostic PDO query builder for PHP 8.2+
with deterministic SQL compilation, typed positional bindings, and explicit
connection and transaction ownership.

It supports MariaDB 11.8, MySQL 8.0, and SQLite 3.39.2+ through PDO. It is a
query builder—not an ORM, migration tool, connection pool, or retry layer.

## Install

```bash
composer require oeltimacreation/php-simplequery:^0.3
```

Your PHP installation also needs `ext-pdo` and the matching driver, such as
`pdo_sqlite` or `pdo_mysql`.

## Quick start

This complete SQLite example creates a table, writes a row, and reads it back.
It needs no server, so it is a good first check after installation.

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
    'title' => 'Read the getting-started guide',
    'completed' => false,
]);

$task = $db->table('tasks')->where('id', (int) $id)->firstAssociative();
echo $task['title'];
```

For a runnable version, use `php examples/beginner/first-query.php` from a
source checkout.

## What it does well

- Builds predictable SQL with SQL and bindings kept separate.
- Quotes structured identifiers and rejects invalid query shapes early.
- Executes reads, writes, aggregates, and one-shot cursors with PDO.
- Provides managed callback transactions with nested savepoints.
- Lets applications test generated SQL without a database using the included
  compiler testing utilities.

## Important boundaries

Values are parameter-bound. Table names, column names, sort fields, and raw
SQL are SQL code, so dynamic identifiers must be allowlisted and raw SQL must
come only from trusted application code. SimpleQuery never interpolates values
into executable SQL and never retries an uncertain write or commit.

Each `Connection` and its builders/cursors belong to one request, job, or
execution unit. Do not share them between concurrent workers or coroutines.

## Documentation

Start here:

- [Getting started](docs/guides/getting-started.md) — SQLite first query, then MySQL/MariaDB
- [Examples](examples/README.md) — small runnable programs, ordered for beginners
- [Query builder](docs/guides/query-builder.md) — filtering, joins, ordering, and compilation
- [Results and writes](docs/guides/results-and-writes.md) — reads, cursors, aggregates, and writes
- [Transactions](docs/guides/transactions.md) — callback ownership and nested savepoints

More guides:

- [Raw SQL and security](docs/guides/raw-sql-and-security.md)
- [Database support](docs/reference/database-support.md)
- [Testing applications](docs/guides/testing-applications.md)
- [Migrating from Pixie](docs/guides/migrating-from-pixie.md)
- [Complete documentation index](docs/README.md)

## Development

```bash
composer install
composer check
composer test:coverage
composer coverage:check
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md),
[SUPPORT.md](SUPPORT.md), and [CHANGELOG.md](CHANGELOG.md) for project policy.

## Acknowledgments

PHP SimpleQuery takes inspiration from [Pecee Pixie](https://github.com/skipperbent/pecee-pixie) and the original [Pixie](https://github.com/usmanhalalit/pixie) query builder. We express our gratitude to their authors and contributors for their foundational work in PHP query builder design.

## License

PHP SimpleQuery is released under the [MIT License](LICENSE).
