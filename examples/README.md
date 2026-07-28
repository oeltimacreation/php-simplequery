# Examples

Run these from a source checkout after `composer install`. The first three use
SQLite in memory and need only PHP with `pdo_sqlite`; they do not change files
or require a server.

| Example | What it demonstrates | Command |
| --- | --- | --- |
| [beginner/first-query.php](beginner/first-query.php) | Create a table, insert, and fetch one row | `php examples/beginner/first-query.php` |
| [beginner/filter-and-update.php](beginner/filter-and-update.php) | Filter, order, update, and count rows | `php examples/beginner/filter-and-update.php` |
| [beginner/transaction.php](beginner/transaction.php) | Atomic related writes and rollback | `php examples/beginner/transaction.php` |
| [compiler-assertions.php](compiler-assertions.php) | Test SQL without a database | `php examples/compiler-assertions.php` |
| [sqlite-execution.php](sqlite-execution.php) | PDO execution and generated IDs | `php examples/sqlite-execution.php` |
| [sqlite-transactions.php](sqlite-transactions.php) | SQLite immediate mode and nested savepoints | `php examples/sqlite-transactions.php` |
| [query-building.php](query-building.php) | Advanced predicates, joins, subqueries, and locks | `php examples/query-building.php` |
| [sqlite-migration-slice.php](sqlite-migration-slice.php) | A representative Pixie migration shape | `php examples/sqlite-migration-slice.php` |

For an installed package, copy the code from the relevant guide rather than
depending on this repository's examples directory.
