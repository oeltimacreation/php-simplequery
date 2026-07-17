# Getting started

Status: compiler, execution, result, cursor, and observation APIs are available
from the development checkout. Managed transactions and a package release
remain pending.

## Requirements

- PHP 8.2 or later;
- PDO;
- one supported PDO driver: `pdo_mysql` or `pdo_sqlite`;
- a supported MariaDB, MySQL, or SQLite runtime.

## Wrap an existing PDO

Injected PDO is the canonical construction path:

```php
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

$pdo = new PDO($dsn, $username, $password, $pdoOptions);
$db = Connection::fromPdo($pdo, Driver::MariaDb);
```

The driver is explicit. `pdo_mysql` alone cannot reliably distinguish MariaDB
from MySQL, especially through a proxy.

## Connect from a DSN

The convenience factory creates and owns PDO:

```php
$db = Connection::connect(
    driver: Driver::MariaDb,
    dsn: 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    username: 'app',
    password: 'secret',
);
```

The factory defaults to exception mode, native prepares, buffered MySQL-family
queries, changed-row counts, non-persistent connections, and explicit fetch
modes. MariaDB/MySQL DSNs must specify `charset=utf8mb4`.

SQLite connections enable and verify foreign keys and default to a configurable
5,000 ms busy timeout. Journal and synchronous modes are never changed
silently.

## Build a query

```php
$compiled = $db
    ->table('users')
    ->select('id', 'email')
    ->where('active', true)
    ->orderBy('id')
    ->compile();
```

Execution terminals such as `get()`, `getAssociative()`, aggregates, and writes
prepare and explicitly bind the compiled query on the same connection.

Every `table()` call returns a fresh mutable builder. Fluent clause methods
mutate that builder, while `compile()`, `get()`, `first()`, aggregates, and
writes do not mutate its clause state.

## Compile without execution

```php
$compiled = $db
    ->table('users')
    ->where('status', 'active')
    ->compile();

$compiled->sql;
$compiled->bindings;
```

Compiled SQL plus ordered typed bindings is canonical. Interpolated debug SQL
is never used for execution.

## Deterministic close

```php
$db->close();
```

Closing rejects active transactions and tracked cursors. Destructor cleanup is
best effort; applications should close at a deterministic lifecycle boundary
when cleanup guarantees matter.

Continue with the [query builder](query-builder.md), [results and writes](results-and-writes.md),
and [raw SQL security](raw-sql-and-security.md) guides.
