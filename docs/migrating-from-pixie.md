# Migrating from Pixie

SimpleQuery is not a drop-in replacement for `pecee/pixie`. There is no Pixie
namespace compatibility package, runtime facade, or deprecation shim.

Migration is an application change supported by characterization tests.

## Familiar behavior retained

- mutable fluent builders;
- a fresh query from `Connection::table()`;
- writable object rows by default;
- nullable `first()`;
- scalar `count()`;
- grouped predicates and joins;
- quoted dynamic identifiers;
- trusted raw expressions with bindings;
- deferred raw-query result chains;
- direct PDO access;
- deterministic compilation inspection.

## Required rewrites

### Imports and construction

Replace Pixie classes/configuration arrays with `Connection::fromPdo()` or
`Connection::connect()` and explicit `Driver` selection.

### Insert results

SimpleQuery separates affected rows from generated IDs:

```php
$affected = $db->table('users')->insert($row);
$id = $db->table('users')->insertGetId($row);
```

Audit each old `insert()` call. Do not blindly replace all inserts with one
terminal; classify generated-ID use, ignored returns, truthiness checks,
pass-through values, and batch assumptions.

### Update and delete results

`update()` and `delete()` return affected-row integers. Rewrite code that
expects a PDO statement or relies on ambiguous truthiness.

### Last-query/debug use

Replace mutable last-query state with:

- `compile()` for construction assertions;
- structured query exceptions for failures;
- a bounded recording observer for execution history.

Never execute interpolated debug SQL.

### Raw SQL

Rewrite raw fragments to use ordered bindings and review every interpolated
value. Raw SQL remains trusted and engine-specific.

### Transactions

SimpleQuery does not adopt an external transaction. Nested managed callbacks
use savepoints, catch all `Throwable` values, and never commit a transaction
they do not own.

### Query timing

`Connection::query()` is deferred. Exceptions occur at a result/execute
terminal rather than when `query()` is constructed.

### Aggregates

`count()` is a range-checked PHP integer. Other aggregates preserve driver
scalars, including exact numeric strings, rather than applying implicit float
coercion.

## Behavior intentionally removed

- static/default connections;
- public statement arrays;
- event-based SQL mutation or execution bypass;
- terminal state mutation;
- interpolated subquery execution;
- non-atomic select-then-write upsert;
- arbitrary object stringification;
- alias lowercasing;
- hidden reconnect or automatic replay;
- overclaimed cross-dialect support.

## Migration workflow

1. Pin the existing Pixie version and add characterization tests.
2. Inventory imports, construction, write returns, raw SQL, transactions,
   debug helpers, and engine-specific clauses.
3. Migrate one bounded feature slice to native SimpleQuery APIs.
4. Compare SQL/bindings, result shapes, affected rows, and side effects.
5. Run live database tests for vendor-specific behavior.
6. Review raw SQL and dynamic identifier authorization.
7. Roll out and observe before expanding the migration.

Mechanical codemods may help with imports and unambiguous method changes, but
they must flag uncertain write returns and raw SQL instead of guessing.
