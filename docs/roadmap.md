# Roadmap and release gates

This roadmap describes public project work without exposing private deployment
or consumer details. Dates remain evidence-based rather than calendar-driven.

## Phase 0: foundation

Repository implementation status: complete. Deployment certification remains
open until operations records the exact deployed MySQL, SQLite, ProxySQL, and
MaxScale facts and attaches their probe output; see the
[compatibility evidence records](evidence/README.md).

- bootstrap Composer, PHP 8.2, strict types, tests, static analysis, style, and
  CI;
- publish public API contracts, policies, ADRs, and test architecture;
- inventory supported public engine/runtime combinations;
- create reproducible PDO, engine, SQLite, and proxy behavior probes;
- establish security, quirks, benchmark, and release records.

Gate: no unresolved write-return or transaction-ownership decision; every
deployment-sensitive policy has a public reproduction and accepted ADR.

## Phase 1: compiler vertical slice

Repository implementation status: complete. Independent MariaDB, MySQL, and
SQLite golden suites cover the advertised compiler features, executable
examples exercise the public testing toolkit, SQLite runs an in-memory
compile/execute smoke test, and the benchmark records predicate scaling.

- implement public immutable values and typed private query state;
- implement identifiers, projection, predicates, joins, grouping, ordering,
  pagination, locks, writes, raw expressions, subquery snapshots, and cloning;
- produce deterministic `CompiledQuery` output for MariaDB, MySQL, and SQLite;
- publish executable examples and compiler assertion helpers.

Gate: independent golden SQL/binding suites, edge cases, linear compiler growth,
and green static/style checks.

## Phase 2: PDO execution and results

Repository implementation status: complete. The public executor is covered by
SQLite integration tests and exact MariaDB/MySQL/ProxySQL/MaxScale execution
smokes alongside the lower-level PDO behavior matrix. The no-dev consumer job
executes the SQLite example using production autoloading only.

- implement connection policy and executor;
- implement object/associative results, aggregates, raw-query terminals,
  affected rows, generated IDs, batch inserts, cursors, exceptions, and
  observer diagnostics.

Gate: live supported-engine tests, required proxy smoke tests, binding matrix,
safe early cursor close, and no-dev consumer installation.

## Phase 3: transactions

Repository implementation status: complete. The managed transaction API,
savepoint nesting, ownership guard, failure metadata, cursor boundaries, and
unusable-state quarantine are covered by controlled failure injection and the
SQLite/MariaDB/MySQL/ProxySQL/MaxScale transaction smoke matrix.

- implement callback ownership, savepoint nesting, external transaction
  detection, state mismatch detection, unusable-state handling, and failure
  evidence.

Gate: the documented state/failure matrix passes live and no external
transaction can be committed by SimpleQuery.

## Phase 4: migration validation

- validate representative SQLite, MariaDB/MySQL, raw-query, join, direct-PDO,
  and diagnostic application shapes;
- publish a repeatable migration playbook and intentional differences;
- measure correctness, performance, and migration ambiguity.

Gate: no unknown blocker in the supported fluent patterns and no runtime Pixie
compatibility layer.

## Phase 5: `0.1.0`

Release requires:

- PHP 8.2-current CI and strict quality checks;
- independent MariaDB, MySQL, and SQLite live coverage;
- published minimum versions and proxy fixture baselines;
- compiler fixtures for every advertised feature;
- characterized binding order, hydration, writes, transactions, and cursors;
- public security, support, migration, quirks, and maintainer documentation;
- acceptable benchmark baselines;
- verified package metadata, release automation, and immutable tagging.

## Stabilization and `1.0.0`

ZeroVer releases collect production evidence and stabilize public contracts.
`1.0.0` requires sustained production use without unresolved data-integrity,
transaction-ownership, binding-order, or connection-state defects, plus a
complete `0.x` upgrade guide and frozen `1.x` support matrix.

## Deferred

- generic upsert;
- engine-specific row-returning DML;
- unions and right joins;
- prepared-query reuse/caching;
- explicit transaction retry helpers;
- new database engines;
- public extension/plugin architecture.
