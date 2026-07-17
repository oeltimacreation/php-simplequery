# Testing applications

Status: target `0.1.0` testing contract.

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

The planned `CompiledQueryAssertions` helper will provide useful diffs without
exposing internal compiler types.

Compile tests are appropriate for clause composition, identifier quoting,
binding order, snapshot behavior, and application-generated query shapes.

## SQLite tests

SQLite in-memory is useful for fast CRUD and result-shape tests:

```php
$pdo = new PDO('sqlite::memory:');
$db = Connection::fromPdo($pdo, Driver::Sqlite);
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
