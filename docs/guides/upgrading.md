# Upgrading

This guide records actionable steps for moving between SimpleQuery release
lines. The first public release is `0.1.0`.

## ZeroVer expectations

- `0.y.0` may contain documented breaking changes.
- `0.y.z` patch releases should remain compatible within that minor line,
  except for urgent security or data-integrity fixes.
- Consumers should pin a tested minor line such as `~0.4.0`.
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

## Installing 0.4.0

```bash
composer require oeltimacreation/php-simplequery:^0.4
```

`0.4.0` requires PHP 8.2+, `ext-pdo`, and either `pdo_sqlite` or `pdo_mysql`.
Select `Driver::MariaDb`, `Driver::MySql`, or `Driver::Sqlite` explicitly. See
[getting started](getting-started.md) for connection examples and
[database support](../reference/database-support.md) for engine floors.

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

## Upgrading to 0.5.0

```bash
composer require oeltimacreation/php-simplequery:^0.5
```

`0.5.0` adds `when()`, `unless()`, and strict 1-based `forPage()` builder
helpers. Existing clause, terminal, binding, transaction, and engine behavior
is unchanged. `forPage()` requires positive page and page-size values and does
not add an implicit ordering.

The development-only Pixie migration analyzer and the 0.4-specific
duplication gate are no longer shipped in the source repository. Use the
manual migration checklist and the ordinary compiler, integration, static
analysis, and live-engine checks instead.
