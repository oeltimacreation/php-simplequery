# Testing applications

SimpleQuery is designed so application query contracts can be tested without a
network database, while database-specific behavior remains covered by live
integration tests.

## Compile assertions

Use `QueryBuilder::compile()` to assert placeholder SQL, binding values, and
concrete binding types without executing the query:

```php
$compiled = $db
    ->table('users')
    ->where('active', true)
    ->compile();

self::assertSame(
    'SELECT * FROM `users` WHERE `active` = ?',
    $compiled->sql,
);
```

For a database-free dialect assertion, use the first-party compiler testing
connection and assertion helper:

```php
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

$db = CompilerConnection::for(Driver::MySql);
$compiled = $db->table('users')->where('active', true)->compile();

CompiledQueryAssertions::assertMatches(
    $compiled,
    'SELECT * FROM `users` WHERE `active` = ?',
    [1],
);
```

`CompiledWriteQuery` provides detached insert, batch-insert, update, and delete
compilation when a test must inspect a write without executing it. It is a
testing tool, not a second production query API.

Compile tests are appropriate for clause composition, identifier quoting,
binding order, snapshot behavior, and application-generated query shapes.

## SQLite tests

SQLite in-memory is useful for fast CRUD and result-shape tests:

```php
$db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
```

Do not use SQLite to prove MariaDB/MySQL SQL, affected-row behavior, generated
IDs, locks, collations, functions, or transaction edge cases.

Use a file-backed SQLite database for WAL, locking, contention, foreign-key
connection state, and busy-timeout tests. In-memory databases are
connection-local and cannot use WAL.

## Recording execution

A bounded recording observer can assert that a statement was attempted without
making the executor mockable. Recorded values remain redacted by default.

## Live integration tests

Use the same engine and relevant configuration as production when testing:

- driver scalar types and decimal precision;
- affected rows and generated IDs;
- SQL modes, collations, and vendor functions;
- lock contention and savepoints;
- native/emulated prepare differences;
- buffered/unbuffered cursor behavior;
- proxy routing, pinning, and failover errors.

## What not to mock

Do not mock the internal AST, compiler, executor, or transaction manager. Those
are closed implementation details. Prefer public compile assertions, SQLite
integration, a recording observer, and live engine tests.

## Sensitive fixtures

Tests and failure output must use synthetic data. Never commit production DSNs,
credentials, hostnames, customer identifiers, private query samples, or copied
production rows.

## Migration characterization

Historical evidence contains anonymized synthetic recreations rather than
application source. Their shapes are grounded in a read-only review of Pecee
Pixie consumers, but all names, SQL, rows, and project identifiers remain
synthetic. Use them as patterns for CRUD, injected models, raw reports,
diagnostics, and complex lists, then build application-owned characterization
tests around real behavior. No migration analyzer or corpus-check command is
maintained; review the [migration checklist](migrating-from-pixie.md) instead.
