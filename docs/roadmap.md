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

Repository implementation status: complete. A read-only audit of nine Pecee
Pixie 4.15.8/4.16.3 consumers grounds five anonymized, library-owned synthetic
slices covering SQLite CRUD, injected MariaDB/MySQL model construction, raw
reporting, diagnostic raw joins, direct PDO ownership, and complex lists. The
deterministic corpus records change/ambiguity/security effort, direct-PDO
result parity, an environment-specific benchmark, and 40 passing live
migration observations across all direct/proxy fixtures. No application
repository was modified and no compatibility façade was introduced.

- validate representative SQLite, MariaDB/MySQL, raw-query, join, direct-PDO,
  and diagnostic application shapes;
- publish a repeatable migration playbook and intentional differences;
- measure correctness, performance, and migration ambiguity.

Gate: no unknown blocker in the supported fluent patterns and no runtime Pixie
compatibility layer.

## Phase 5: `0.1.0`

Status: released as immutable tag `v0.1.0` at
`16c59bf5fbf2490e19bbd05aa7ccbb7c274ab0dc`. The release gates completed were:

- PHP 8.2-current CI and strict quality checks;
- independent MariaDB, MySQL, and SQLite live coverage;
- published minimum versions and proxy fixture baselines;
- compiler fixtures for every advertised feature;
- characterized binding order, hydration, writes, transactions, and cursors;
- public security, support, migration, quirks, and maintainer documentation;
- acceptable benchmark baselines;
- verified package metadata and release automation.

## Phase 6: `0.2.0`

Status: planned from the post-`0.1.0` correctness, profiling, performance, and
quality audit. The detailed work packages, gates, and immutable comparison
scorecard are in the [`0.2.0` release plan](0.2-release-plan.md); the accepted
initial reference is the [`v0.1.0` performance and stability
baseline](evidence/v0.1.0-performance-baseline.md).

- reject ambiguous grouped scalar aggregate terminals;
- quarantine uncertain cursor and transaction-control state;
- make branch coverage an enforced Xdebug-backed CI gate;
- establish reproducible compiler, hydration, observer, memory, and soak
  comparisons;
- optimize associative hydration only when same-run PDO controls prove a
  material benefit;
- tighten downstream type contracts and high-risk path tests without adding a
  framework or runtime dependency.

Gate: all correctness/resource blockers are closed, branch floors are actually
enforced, direct/proxy tests pass, compiler scaling remains linear, soaks remain
leak-free, and the final `v0.1.0`/`0.2.0` comparison has no unexplained material
regression.

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
