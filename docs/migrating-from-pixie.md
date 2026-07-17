# Migrating from Pixie

SimpleQuery is not a drop-in replacement for `pecee/pixie`. There is no Pixie
namespace compatibility package, runtime facade, or deprecation shim.

Migration is an application change supported by characterization tests. The
library-owned [migration validation](migration-validation.md) recreates five
representative shapes without changing any application repository.

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

## Intentional difference checklist

Every migration must explicitly resolve these differences:

| Pixie-era assumption | Native SimpleQuery contract |
| --- | --- |
| Static/default connection | Every builder and raw query has an explicit `Connection`. |
| Configuration array construction | Inject PDO or use an explicit DSN, `Driver`, and options. |
| `insert()` may return an ID | `insert()` returns affected rows; `insertGetId()` returns a string ID. |
| Write truthiness or statement return | Update/delete/batch methods return affected-row integers. |
| Named or mixed raw placeholders | Raw bindings are ordered positional values only. |
| Raw interpolation | Values are bound; dynamic identifiers are allowlisted; SQL text is trusted code. |
| Eager `query()` failure timing | `query()` is deferred until a terminal method. |
| Mutable last-query/debug SQL | Use `compile()`, structured exceptions, or a bounded observer. |
| Event-based SQL mutation/bypass | Observation is immutable, post-attempt, and non-interfering. |
| Nested/external transaction adoption | Outer managed scope owns PDO; nesting uses savepoints; external work is never adopted. |
| Transaction control-flow exceptions | All `Throwable` values enter rollback handling; domain failures retain identity. |
| Live cursor crossing completion | Transaction/savepoint completion rejects the cursor and requires explicit cleanup. |
| `updateOrInsert()` select-then-write | Generic upsert is deferred; use a deliberate vendor design or transaction workflow. |
| String lock modes | Typed lock methods; execution requires a transaction; SQLite rejects them. |
| Interpolated subqueries | Child SQL and bindings are snapshotted structurally. |
| Terminal mutation such as `first()` changing limit | Terminals do not change builder state. |
| Alias normalization | Aliases remain explicit and case-preserving. |
| Empty list inherited behavior | Empty `IN` becomes false; empty `NOT IN` becomes true. |
| Implicit aggregate coercion | Count is checked `int`; other aggregate driver scalars are preserved. |
| Arbitrary object/date stringification | Unsupported objects are rejected; callers choose date/time representation. |
| Cross-dialect best effort | MariaDB, MySQL, and SQLite are explicit independent targets. |
| Hidden reconnect/replay | Connection loss and ambiguous writes are never replayed automatically. |

## Repeatable migration playbook

1. Pin the current Pixie and database/proxy versions. Add characterization
   tests for results, writes, side effects, and failure behavior before edits.
2. Select one bounded feature slice with an owner, rollback plan, engine path,
   representative data, and observable success criteria.
3. Inventory imports/construction, every insert return, write truthiness, raw
   SQL, dynamic identifiers, transactions, diagnostics, vendor functions,
   cursors, and unsupported methods.
4. Classify insert calls as generated ID, ignored affected rows, truthiness,
   pass-through, or batch assumption. Never globally rename `insert()`.
5. Rewrite construction and types to native `Connection`, then migrate fluent
   queries. Use `compile()` tests for exact SQL and ordered binding parity.
6. Replace interpolated values with bindings and allowlist request-derived
   identifiers. Treat every raw fragment and vendor function as a security and
   portability review point.
7. Rewrite transaction ownership, query terminal timing, aggregate scalar
   expectations, write returns, and diagnostic observation explicitly.
8. Run fast SQLite/application tests, then the real MariaDB/MySQL and proxy
   paths needed by that slice. Compare rows, types, affected rows, IDs, side
   effects, SQLSTATE behavior, timings, and memory.
9. Review any mechanical output. The provided analyzer permits isolated import
   rewrites only and refuses ambiguous semantic changes.
10. Deploy the bounded slice through the application's staged rollout, observe
    errors/latency/connection state, reconcile writes, and retain a rapid
    rollback path before expanding scope.

Use `composer migration:check` for the library-owned reference corpus. A real
migration records its own measurements and must not edit the library's fixture
numbers to imply application completion.
