# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with ZeroVer releases before `1.0.0`.

## [Unreleased]

### Added

- Add `QueryBuilder::when()`, `unless()`, and strict 1-based `forPage()` helpers
  for conditional clauses and deterministic limit/offset pagination.

### Removed

- Remove development-only Pixie migration automation and the 0.4-specific
  duplication gate; historical migration and quality evidence remains archived.

## [0.4.0] - 2026-08-08

### Added

- Add code duplication gate (`composer duplication:check`) integrated into `composer check` to detect golden SQL/fixture ID duplicates, duplicate test names, and hotspot regressions.
- Add multiprocess SQLite write-contention probe (`composer probe:sqlite:contention`) testing concurrent WAL database transactions, `SQLITE_BUSY` surfacing, busy-timeout blocking, and 4-process write safety.
- Add memory-stability soak scenarios for streaming cursors and batch writes, per-sample allocation metrics, and a 256 KiB retained-allocation memory gate.
- Add hot-path benchmark scenarios (`compile_allocation` and `terminal_reuse`) and automated paired `v0.3.0` baseline comparisons in CI.
- Record `v0.3.0` baseline manifest, duplication/complexity inventory, performance/stability evidence, and ADR-015 for PHP 8.2+ attribute floor policy.

### Changed

- Consolidate MySQL and MariaDB dialect compilers behind a shared `@internal` `MySqlFamilyCompiler` base.
- Unify clause condition construction into a single `BuildsConditions` dispatcher for `where()` and `having()`.
- Centralize `insert()`, `insertMany()`, `update()`, and `delete()` write compilation and state validation in `AbstractDialectCompiler`.
- Extract connection lifecycle, DSN validation, and PDO options into `@internal` `ConnectionProfile`.
- Replace `func_num_args()` overload dispatch with explicit sentinel defaults (`MISSING` sentinel enum) and variadic catch-alls across clause methods, exposing reflection defaults while preserving exact `where('col', null)` behavior.
- Replace magic strings in AST internal state with closed enums (`LockMode`, `LockModifier`, `JoinType`).
- Reuse stateless dialect compilers and executors per `Connection` instance via `@internal` `compilerForQueryBuilding()` and `executorForQueryBuilding()` accessors, cutting compilation and terminal allocations.
- Shallow-clone condition and join state during query compilation snapshots (`QueryState::copyForCompilation()`), optimizing hot-path aggregate rewrites.
- Data-drive dialect golden assertions via JSON fixtures (`tests/Fixtures/Compiler/*.json`) and consolidate `PDOStatement` test doubles into `ConfigurableStatement`.
- Formalize PHP 8.2 runtime floor checks in `composer check` with `check-platform-reqs`, PHP linting, and PHPStan analysis floors.

## [0.3.0] - 2026-07-28

### Added

- Add canonical `0.3.0` query, transaction, streaming, batching, migration,
  upgrade, and API guidance, plus CI-executed SQLite examples for early cursor
  cleanup and application-owned batch/atomicity policy.
- Add release-candidate evidence for local quality and coverage, clean no-dev
  installation, minimum/current direct and proxy probes, path-redacted
  consumer validation, security review, and the remaining deployment gate.
- Add correctness-gated production-shaped compiler, report hydration/cursor,
  logical count, batch write, and SQLite query-plan benchmark evidence.
- Add generic baseline/candidate comparison labels, operation-level median
  change review signals, and paired `v0.2.0` release-candidate measurements.
- Add a deterministic reflection-based public API signature manifest and
  `composer check` gates for unreviewed contract drift and broken relative
  documentation links.
- Add an architecture and code-quality audit covering the `@internal` public
  seam, core responsibility hotspots, extraction decisions, style rules, and
  focused condition, transaction, exception, cursor, and connection-state
  tests.
- Extend the independent PHPStan level-9 consumer with production-shaped model
  construction, mutable query helpers, generated IDs, managed transactions,
  callbacks, raw-expression overloads, generic cursors, and exception evidence.
- Add transaction-classification and exception-ergonomics evidence, ADR-020,
  supported-PHP SQLite immediate probes, and application-owned immediate-
  transaction and lock-conflict recipes without retry claims.
- Add trusted expression-to-bound-value comparisons across `WHERE`, nested
  condition groups, `HAVING`, and value-oriented joins while retaining ordered
  positional bindings and established null semantics.
- Add explicit `whereColumn()` and `orWhereColumn()` identifier comparisons,
  plus join comparisons with exactly one trusted expression operand.
- Add an expression-comparison ADR, projection-ergonomics decision record,
  index-friendly date-range guidance, all-dialect binding-order coverage,
  SQLite execution coverage, and direct MariaDB/MySQL probe coverage.
- Add a deterministic, read-only SimpleQuery adoption audit with path-redacted
  findings for clauses, result terminals, raw-expression contexts, generated-ID
  timing, direct PDO, transaction ownership, dynamic identifiers, batching,
  and streaming.
- Add a production-shaped synthetic adoption corpus, a required migration
  review report, an anonymized first-production-migration evidence record, and
  a reproducible `v0.2.0` source/query/coverage/benchmark baseline.

### Changed

- Reuse one private associative-row validator across full and first-result
  execution while preserving result, exception, SQL, and binding behavior.
- Add low-noise import and comparison consistency rules without a style
  baseline or broad formatting churn.
- Move pinned checkout and artifact-upload CI actions to their Node.js 24
  releases, removing runner deprecation annotations.
- Require PDO to report physical activity immediately after every managed
  transaction begin, and retain direct PDO ownership for schema control,
  manually started work, SQLite immediate transactions, and deployment-specific
  exception classification.
- Extend the public API and independent level-9 consumer contract with the
  accepted expression and column comparison overloads; retain variadic
  `select()` instead of adding a SQL-list parser or parallel projection API.
- Reorganize documentation into guides, reference, maintainer operations,
  active plans, ADRs, and evidence; remove completed roadmap and release-plan
  documents, and add the evidence-gated `0.3.0` development plan.

## [0.2.0] - 2026-07-26

### Added

- Add an independent level-9 external-consumer PHPStan fixture for callback,
  positional-binding, and generic cursor inference.
- Add executable null-containing list, oversized integer string, duplicate/
  numeric result-column, cursor-observation, connection-policy, and public
  value-boundary tests.
- Add a required Xdebug branch/path coverage job and minimum/current MariaDB
  11.8 and MySQL 8.0 direct-engine fixture matrix.
- Add a dependency-free, fresh-process benchmark runner with explicit setup,
  warm-up, correctness digests, alternating direct-PDO controls, raw samples,
  source/runtime/PDO metadata, PHP memory peaks, process RSS, and retained
  memory/file-descriptor deltas.
- Add executable compiler-shape, hydration/cursor, observer, batch, terminal,
  transaction, lifecycle, migration, live direct/proxy, and multiprocess-soak
  scenarios, plus an identical-runner `v0.1.0` comparison.
- Archive deterministic SQLite benchmark reports in pull-request CI and live
  engine/proxy/soak reports in scheduled and release workflows.

### Changed

- Publish positional bindings as lists, infer typed join/condition callbacks,
  and expose object/associative cursor row types through PHPDoc generics.
- Validate consumer-constructed `CompiledQuery` binding members and
  `QueryExecution` parameter types and numeric metadata at runtime.
- Make configured branch thresholds fail when Clover omits branch metrics;
  retain PCOV as the separate fast line-coverage gate.
- Reduce associative full-result peak memory by validating rows in a one-pass
  fetch loop, and avoid copying already-validated associative cursor rows.
- Add paired source-order hydration comparison and realistic observer binding-
  count/profile commands to the maintained performance harness.
- Replace the legacy benchmark scripts and overstated planned matrix with the
  exact schema-version-2 executable scenario and artifact contract.
- `sum()`, `average()`, `min()`, and `max()` now reject `distinct()`, `GROUP
  BY`, and `HAVING` query shapes with `UnsupportedFeatureException`; use
  `count()` for supported logical grouped/distinct counts or select grouped
  aggregate rows explicitly.
- Managed transaction startup now verifies physical inactivity after a failed
  begin, and uncertain nested savepoint creation quarantines the connection.

### Fixed

- Prevent non-count scalar aggregates from silently returning the first value
  of a multi-row grouped aggregate result.
- Treat a false or throwing `PDOStatement::closeCursor()` as uncertain
  connection state, quarantine the connection, and preserve an earlier
  fetch/result failure as the primary exception when cleanup also fails.

## [0.1.0] - 2026-07-17

### Added

- First public release of the deterministic PDO query builder for MariaDB,
  MySQL, and SQLite.
- Fluent query construction, typed positional bindings, PDO execution, result
  hydration, writes, cursors, managed transactions, and query observation.
- Compile-only testing tools, live-engine compatibility probes, migration
  validation, and runnable SQLite beginner examples.
- Complete user guides, public API reference, database support matrix, release
  process, and contributor instructions.

- Public documentation foundation.
- Initial architecture decision records.
- Composer package and PHP 8.2 development bootstrap.
- PHPUnit 11, PHPStan level 9 strict/deprecation rules, PHPCS 4/Slevomat,
  coverage thresholds, and pinned CI workflows.
- Versioned public API, aggregate, connection, migration, consumer-audit, and
  deployment-evidence contracts.
- Reproducible MariaDB, MySQL, SQLite, ProxySQL, and MaxScale PDO behavior
  probes, including file-backed SQLite contention and affinity fixtures.
- PDO SQLite benchmark control and release certification verifier.
- Immutable query values, mutable builders with typed private state, and
  deterministic MariaDB, MySQL, and SQLite compilers.
- Structured projection, predicates, joins, grouping, ordering, pagination,
  locks, writes, raw expressions, subquery snapshots, and clone isolation.
- Compiler testing utilities, versioned golden fixtures, executable examples,
  and a SQLite in-memory compiler smoke test.
- Supported-profile PDO construction, connection lifecycle enforcement, and
  explicit prepared-statement execution with concrete binding types.
- Object/associative hydration, scalar aggregates, affected-row writes,
  immediate generated IDs, genuine multi-row inserts, and deferred raw queries.
- One-shot tracked cursors, redacted query exceptions, immutable execution
  observations, and a bounded recording observer.
- Public execution smokes for SQLite, MariaDB, MySQL, ProxySQL, and MaxScale,
  plus a no-dev SQLite execution consumer example.
- Managed callback transactions with generated savepoint nesting, strict
  external ownership rejection, physical-state mismatch detection, active
  cursor guards, structured failure evidence, and unusable-state quarantine.
- Direct/proxy transaction smokes, controlled transaction-control failure
  injection, and an executable no-dev SQLite transaction example.
- An anonymized read-only baseline across nine Pecee Pixie 4.15.8/4.16.3
  consumers and five grounded synthetic migration slices covering SQLite CRUD,
  injected models/direct PDO, raw reporting, diagnostic joins, and complex
  list queries.
- Deterministic migration ambiguity/refusal reporting, direct-PDO query/result
  parity, a migration performance control, and live migration smokes for all
  direct and proxy fixtures.
- A repeatable direct-migration playbook and complete intentional-difference
  checklist without a runtime Pixie dependency or compatibility façade.

[Unreleased]: https://github.com/oeltimacreation/php-simplequery/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/oeltimacreation/php-simplequery/releases/tag/v0.1.0
