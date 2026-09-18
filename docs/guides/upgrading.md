# Upgrading

This guide records actionable steps for moving between SimpleQuery release
lines. The first public release is `0.1.0`.

## ZeroVer expectations

- `0.y.0` may contain documented breaking changes.
- `0.y.z` patch releases should remain compatible within that minor line,
  except for urgent security or data-integrity fixes.
- Consumers should pin a tested minor line such as `~0.8.0`.
- Changelog entries are not a substitute for migration instructions; every
  breaking change must be documented here.

## Upgrade checklist

For each upgrade:

1. read the changelog and this guide;
2. review runtime, extension, database, and proxy support floors;
3. run static analysis and application compile assertions;
4. run SQLite and live database integration tests;
5. verify write-return and aggregate scalar expectations;
6. exercise transaction, cursor, and failure paths;
7. review generated SQL for raw or dialect-specific queries;
8. deploy through the application's normal staged rollout.

## Installing 0.8.0

```bash
composer require oeltimacreation/php-simplequery:^0.8
```

`0.8.0` requires PHP 8.2+, `ext-pdo`, and either `pdo_sqlite` or `pdo_mysql`.
Select `Driver::MariaDb`, `Driver::MySql`, or `Driver::Sqlite` explicitly. See
[getting started](getting-started.md) for connection examples and
[database support](../reference/database-support.md) for engine floors.

## Upgrading from 0.7 to 0.8

`0.8.0` adds explicit connection lifecycle and recovery APIs, normalizes
connection-construction diagnostics, and records worker-mode guidance. Public
signatures and supported floors are unchanged except for the documented
`min()`/`max()` return validation below.

The additive `Connection::isClosed()`, `isReusable()`, and `discard()` methods
support explicit lifecycle decisions. Existing `close()` remains strict.
Replace reliance on internal transaction/cursor methods with `isReusable()`
only at an application-controlled unit boundary; it is not a ping or proof of
session cleanliness. A known-lost connection must be discarded even if that
local check returns true.

Discard permanently retires the wrapper. Do not reuse old models, builders,
raw queries, or cursors against its replacement. Do not use discard to complete
a transaction: callback completion fails, escaped PDO can retain physical
work, and outstanding cursors still need cleanup. A cursor advanced after
discard fails before another fetch. Applications own initialization of the
replacement, session reset, and reconciliation of uncertain writes. See
[connection lifecycle guidance](concurrency-and-workers.md).

Connection failures now expose normalized `ConnectionException` evidence.
`operation` is `connect` for a construction or SQLite-bootstrap failure,
`closed` for a retired wrapper, and `compiler_only` for a PDO-free compiler
connection; `sqlState`, `driverCode`, `driver`, and `connectionLabel` describe
the connection context. The raw PDO exception, driver message, and trace are
not retained, so `getPrevious()` remains null; branch on `operation` and
normalized codes instead of exception messages. The `$dsn` parameter is marked
`#[SensitiveParameter]`. Missing or malformed PDO `errorInfo` is normalized
consistently for construction and query execution, so read `$driverCode`
without assuming a particular shape.

The new surface does not implement idle policy and adds no retry classifier.
Adopt the package and holder/error-handler changes together; do not remove the
application's recovery policy simply because these methods exist. Eviction,
retry classification, and ambiguous-write reconciliation remain
application-owned; see
[connection lifecycle and failure guidance](concurrency-and-workers.md).

`min()` and `max()` now describe and validate the same `int|float|string|null`
scalar union as `sum()` and `average()`. Supported driver values are unchanged;
an unsupported driver scalar now throws `QueryExecutionException` instead of
being returned. See [ADR-006](../adr/006-results-and-write-returns.md).

Worker-mode applications should also adopt the framework-free request recipe:
lazily acquire and pin one connection per role and unit; initialize every
replacement before publishing it; restore temporary session settings in
`finally`; evict the affected role on uncertain failure without reconnecting
it; and keep bounded lifecycle counters in the application. The recipe adds no
library API. It is executable in the
[worker request lifecycle example](../../examples/worker-request-lifecycle.php),
and the maintained direct probe verifies session initialization, restoration,
and statement-limit scope against disposable MariaDB and MySQL sessions. See
[session hygiene](concurrency-and-workers.md#session-initialization-and-restoration)
and [timeout and deadline distinctions](concurrency-and-workers.md#timeout-and-deadline-distinctions).

## Upgrading from 0.6 to 0.7

`0.7.0` tightens invalid-input and failure semantics and keeps public
signatures, supported floors, and accepted resource types unchanged.

Malformed named raw conditions that supply `value` without `operatorOrValue`,
or join `right` without `operator`, now throw `InvalidQueryException` instead
of silently dropping the argument. Supply the complete comparison or omit
both optional operands for a bare raw predicate. Valid calls are unchanged.

Transaction inspection failures during callback recovery now retain the original
callback in `callbackFailure`; inspect that field alongside `controlFailure`.
Quarantine and successful-rollback exception identity are unchanged.

SQLite connection construction now rejects failed busy-timeout reads even when
the declared timeout is zero. Valid integer/string zero values remain accepted.

`ParameterType::Lob` bindings remain caller-owned; review
[performance and streaming](performance-and-streaming.md) for current-position,
reuse, and cleanup expectations.

## Upgrading from 0.1 to 0.2

Non-count scalar aggregate terminals no longer accept builders with
`distinct()`, `groupBy()`, or `having()`. These shapes previously compiled and
could silently return only the first aggregate row. Fetch explicit grouped
aggregate projections when multiple results are intended; grouped/distinct
`count()` remains supported.

Treat cursor cleanup and transaction-control exceptions as connection-lifetime
boundaries. A false or throwing PDO cursor close and an uncertain nested
savepoint creation now quarantine the connection. Failed transaction begin is
recoverable only when SimpleQuery can verify an inactive physical transaction.
Discard a quarantined connection rather than retrying work on it.

Published release tags are immutable. Corrections are issued as new patch
releases.

## Upgrading from 0.2 to 0.3

`0.3.0` adds trusted expression-to-bound-value comparisons to `WHERE`, nested
condition groups, `HAVING`, and value-oriented joins. The expression SQL and
its bindings occur before the separately bound comparison value. It also adds
explicit `whereColumn()` and `orWhereColumn()` identifier comparisons, and
allows exactly one trusted expression operand in identifier-oriented joins.
Plain strings retain their existing identifier/value meaning; complete
one-argument raw conditions are unchanged. Review exact SQL and binding order
when adopting an overload.

No projection-list parser was added. Continue passing identifiers,
`Identifier::as()` aliases, wildcards, and deliberate raw projections as
separate variadic `select()` arguments.

The one-argument `transaction()` contract is unchanged. MySQL-family row locks
continue to use `forUpdate()` or `forShare()` inside an ordinary managed
transaction. SQLite immediate transactions remain externally owned direct PDO
work because supported PHP versions disagree on whether manual begin is visible
through `PDO::inTransaction()`.

Managed begin now verifies `PDO::inTransaction()` before executing the callback.
A driver/runtime combination that dispatches begin without reporting physical
activity fails before application work runs; reuse is allowed only after
physical inactivity is verified.

Applications migrating from Pixie or reviewing an existing SimpleQuery
adoption should characterize generated-ID timing and transaction ownership
manually, then retest raw boundaries, static analysis, SARGable plans, and
every claimed live-engine/proxy path. See [migrating from Pixie](migrating-from-pixie.md)
for the maintained checklist.

## Upgrading from 0.3 to 0.4

`0.4.0` is a 100% backward-compatible performance, refactoring, and code quality release. It introduces zero breaking changes to public builder, connection, or cursor signatures.

Key internal and maintenance changes include:

- Public API signatures replace `func_num_args()` dispatch with sentinel defaults and explicit variadic catch-alls. Reflection exposes default constants and variadic parameters while preserving identical runtime validation and `where('column', null)` semantics.
- Connection compilers and executors are lazily cached per `Connection` instance behind `@internal` accessors (`compilerForQueryBuilding()`, `executorForQueryBuilding()`).
- Internal AST states now use typed enums (`LockMode`, `LockModifier`, `JoinType`).
- MySQL and MariaDB dialect compilers are consolidated under `MySqlFamilyCompiler`.
- Added a development-only duplication gate and multiprocess SQLite
  write-contention stress probes (`composer probe:sqlite:contention`).

## Upgrading from 0.4 to 0.5

`0.5.0` adds `when()`, `unless()`, and strict 1-based `forPage()` builder
helpers. Existing clause, terminal, binding, transaction, and engine behavior
is unchanged. `forPage()` requires positive page and page-size values and does
not add an implicit ordering.

The development-only Pixie migration analyzer and the 0.4-specific
duplication gate are no longer shipped in the source repository. Use the
manual migration checklist and the ordinary compiler, integration, static
analysis, and live-engine checks instead.

## Upgrading from 0.5 to 0.6

`0.6.0` is a 100% backward-compatible efficiency, maintainability, and
user-experience release. It introduces zero breaking changes to public builder,
connection, terminal, transaction, or cursor signatures.

Key internal optimizations and improvements include:

- Transient allocation reductions during query compilation: high-cardinality
  `IN` lists (up to 54.4% reduction for 5,000 values) and multi-row batch inserts
  (25.8% to 54.2% reduction for 1,000 rows) by removing placeholder and
  intermediate binding allocations while preserving exact SQL and binding order.
- Associative hydration optimizes per-row key validation in place, eliminating
  transient array allocations while maintaining strict column types and duplicate
  resolution rules.
- New task-focused guides: terminal selection matrix, exception troubleshooting
  and redaction rules, driver caveats, streaming cursor unbuffered execution,
  and deterministic pagination ordering.
- Deterministic release consistency gating and full clean `--no-dev` consumer
  lifecycle verification.
