# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with ZeroVer releases before `1.0.0`.

## [Unreleased]

### Changed

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

[Unreleased]: https://github.com/oeltimacreation/php-simplequery/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/oeltimacreation/php-simplequery/releases/tag/v0.1.0
