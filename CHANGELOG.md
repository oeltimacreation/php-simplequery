# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with ZeroVer releases before `1.0.0`.

## [Unreleased]

### Added

- Freeze the `v0.3.0` development baseline: public-source blob manifest,
  reflection-based public API signature manifest, synthetic-query digests,
  coverage metrics, test results, and benchmark control digests recorded before
  any `0.4.0` refactor (SQ-0401).
- Record the `0.4.0` duplication and complexity inventory as a
  machine-checkable before-metrics record covering per-dialect golden SQL,
  per-test-layer assertions, `func_num_args()` dispatch, magic strings, AST
  copy sites, and documentation overlap (SQ-0402).
- Lock the `0.4.0` contract freeze: zero breaking changes, zero new public API
  surface, the deferred `0.5.x` feature backlog, and compilation hot-path
  allocation targets (SQ-0404).
- Add a duplication gate (`composer duplication:check`) that flags repeated
  golden SQL and fixture case ids, duplicate test method names, and re-added
  Phase 1/2 hotspot patterns, wired into `composer check` (SQ-0424).

### Changed

- Retire the completed `0.3.0` development plan from the plan index and keep
  only the active `0.4` plan; the `0.3.0` outcome remains in the changelog,
  the upgrade guide, ADR-019/020, and the `0.3.*` evidence records (SQ-0403).
- Consolidate the MySQL/MariaDB compilers behind one closed `@internal`
  `MySqlFamilyCompiler` base so the two dialect classes differ only in the
  shared-lock clause (`LOCK IN SHARE MODE` vs `FOR SHARE`); compiled SQL,
  bindings, and lock-rejection behavior are unchanged (SQ-0411).
- Unify condition construction into a single private `BuildsConditions`
  dispatch shared by `where()`/`orWhere()`/`whereNot()`/`orWhereNot()` and
  `having()`/`orHaving()`; null-predicate negation semantics are preserved
  exactly and `QueryBuilder::addHaving()` is removed (SQ-0412).
- Consolidate `insert()`/`insertMany()`/`update()`/`delete()` write
  compilation in `AbstractDialectCompiler` around shared state-validation and
  row/column helpers with identical validation order and error messages
  (SQ-0413).
- Extract DSN validation, PDO option merging, and supported-profile checks
  from `Connection` into one cohesive `@internal` `ConnectionProfile` class;
  construction, exception, and lifecycle behavior is unchanged (SQ-0414).
- Collapse the single-use internal cursor row-factory wrapper methods into the
  `Cursor` factories; fetch, cleanup, and quarantine semantics are unchanged
  (SQ-0415).
- Make dialect golden tests data-driven: move the per-dialect select and write
  golden assertions into the versioned `tests/Fixtures/Compiler/*.json`
  fixtures (including additive binding-type cases) so each behavior is asserted
  exactly once by `GoldenFixtureTest`; the dialect test files keep only lock
  syntax and rejection tests (SQ-0421).
- Consolidate the seven overlapping `PDOStatement` test doubles into one
  configurable `ConfigurableStatement` double that expresses fetch results,
  fetch/close failures, and false-close outcomes without losing failure-
  injection clarity (SQ-0422).
- Remove layer-duplicated assertions from unit and integration tests that
  re-proved compiler SQL output already pinned by the compiler-layer golden
  fixtures, while keeping the count-rewrite SQL-shape checks the integration
  layer requires (SQ-0423).
- Replace the `func_num_args()` overload dispatch for `where()`/`orWhere()`/
  `whereNot()`/`orWhereNot()`/`having()`/`orHaving()`/`join()`/`innerJoin()`/
  `leftJoin()`/`on()`/`orOn()` with explicit private sentinel defaults
  (`self::MISSING`, backed by the closed `Internal\MissingArgument` enum) plus
  explicit variadic catch-alls. The exact `where('column', null)` two-operand
  distinction and the too-many-arguments rejection are preserved; the public
  API manifest was regenerated for the reflection-visible default constants and
  variadic parameters only (SQ-0431).
- Extract `AbstractDialectCompiler::select()` clause assembly into named
  per-clause steps (select/join/where/group/having/order/pagination) and move
  SQLite construction PRAGMAs into `Connection::applySqliteConstruction()`,
  lowering `select()` cyclomatic complexity from 15 to 3 and `connect()` from 9
  to 8 with identical SQL output (SQ-0432).
- Replace the untyped lock/join strings in the internal AST with closed enums:
  `LockMode` (`update`/`share`), `LockModifier` (`NOWAIT`/`SKIP LOCKED`), and
  `JoinType` (`INNER`/`LEFT`); `LockState` and `JoinState` now carry typed
  state, and the MySQL-family lock clause uses the enum values with unchanged
  compiled SQL (SQ-0433).
- Formalize the PHP 8.2 runtime floor in the local `composer check` path: add
  `composer check-platform-reqs` to the check chain and extend
  `scripts/lint-php.php` to verify the pinned 8.2 platform and the
  `phpVersion: 80200` PHPStan analysis floor that rejects accidental PHP 8.3+
  syntax; document the deliberate `#[Override]` (PHP 8.3+) attribute policy for
  an 8.2-supported library in ADR-015 (SQ-0434).
- Collapse the single-use `Internal\RequestedBooleanOption` value object into
  `ConnectionProfile::requestedBooleanOption()`; the closed `@internal`
  boundary is unchanged and no public behavior changed (SQ-0435, ADR-013).
- Run the correctness pass: replace the `'null'` binding-type magic-string
  comparison with the `ParameterType::Null` enum and add edge tests pinning the
  two- versus three-operand null comparison equivalence, `having()` null
  semantics, and the full insert/update/delete read-clause validation matrix;
  the audit recorded zero unresolved findings (SQ-0436).

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

[Unreleased]: https://github.com/oeltimacreation/php-simplequery/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/oeltimacreation/php-simplequery/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/oeltimacreation/php-simplequery/releases/tag/v0.1.0
