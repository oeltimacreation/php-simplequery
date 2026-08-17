# AGENTS.md — PHP SimpleQuery contributor and AI-agent instructions

Read this file before changing the repository. It is a navigation and scope
guide; the linked reference, ADR, evidence, and release documents are the
source of truth for detailed behavior. Keep all agent communication in
English.

## Working agreement for AI agents

- Start with `git status --short --branch`, then inspect the relevant files and
  nearby tests before editing. Use `rg` and `rg --files` for repository search.
- Treat existing uncommitted changes as user-owned. Preserve them, inspect
  overlapping diffs, and never reset, stash, clean, checkout, or overwrite
  unrelated work.
- Make the smallest coherent change that satisfies the request. Do not
  reformat unrelated files, perform speculative refactors, add framework
  coupling, or create temporary plans and task-stage records in the repository.
- Do not invent a public API, supported engine, compatibility claim, release
  version, or security guarantee. If the missing decision would materially
  change a public contract, security boundary, data-safety outcome, or external
  action, ask for direction; otherwise make a narrow assumption and report it.
- Do not commit, push, open a pull request, tag, publish, or modify external
  services unless the user explicitly asks for that action.
- Prefer existing test helpers, fixtures, probes, and scripts over one-off
  replacements. Do not add AI-specific wording to user-facing documentation,
  source code, changelog entries, or release evidence unless requested.
- Finish with a concise handoff: what changed, which checks ran and their
  result, what was not run and why, and any remaining assumption or risk.

## Project contract

- Package: `oeltimacreation/php-simplequery`
- Namespace: `Oeltima\SimpleQuery`
- Runtime floor: PHP 8.2 with `ext-pdo`.
- Every PHP file uses `declare(strict_types=1);`; keep that baseline for new
  PHP files.
- Current declared database targets: MariaDB 11.8, MySQL 8.0, and SQLite
  3.39.2+. Confirm `docs/reference/database-support.md` before making or
  changing a support claim.
- ProxySQL and MaxScale are configuration-sensitive compatibility fixtures,
  not additional SQL dialects.

SimpleQuery is a small, framework-agnostic PDO query builder. It is not an ORM,
schema or migration system, connection pool, router, retry engine, or public
dialect/plugin platform. A requested feature that belongs to one of those
boundaries needs explicit scope approval; a trusted raw query may be the
narrower supported solution.

### Non-negotiable invariants

- Values are parameter bindings. Identifiers and SQL syntax are code;
  application-supplied identifiers must be allowlisted, and raw SQL is an
  explicit trusted-code boundary.
- `Connection::table()` returns a fresh mutable builder. Clause methods mutate
  that builder; terminal methods do not accumulate, reset, or otherwise mutate
  query state.
- Compiler bindings remain in the exact order of their placeholders in SQL.
- Nested builders are snapshotted when attached, and structured composition
  across connections is rejected.
- Connections, builders, cursors, and transaction state belong to one request,
  job, or other execution unit. Do not add global connection state, transparent
  reconnects, automatic retries, or hidden replay of uncertain writes.
- AST, compiler, executor, transaction-manager, and capability classes under
  `src/Internal/` are closed implementation details. Do not make them public
  extension points or add framework dependencies.
- Concrete library classes under `src/` are `final` unless deliberate
  extension is part of the accepted public contract. Use strict types,
  typed private state, and readonly value objects where appropriate.

## Source of truth and repository map

- `composer.json` — runtime constraints, autoloading, and the authoritative
  development commands.
- `src/` — public API and closed implementation. Public values and integration
  surfaces include `Connection`, `QueryBuilder`, `RawQuery`, `Cursor`,
  `Expression`, `Exception`, `Observability`, and `Testing`.
- `src/Internal/` — AST/state, dialect compilers, binding normalization,
  executor, connection profile, and transaction manager.
- `tests/Unit/` — isolated public and internal behavior.
- `tests/Compiler/` and `tests/Fixtures/Compiler/` — dialect SQL, validation,
  and exact ordered-binding coverage.
- `tests/Integration/SQLite/` — live SQLite execution, result, transaction,
  and PDO behavior tests. Direct MariaDB/MySQL and proxy matrices are
  probe-driven under `tools/database-probes/`; do not infer their behavior from
  SQLite or from a single successful query.
- `tests/Compatibility/` and `tests/Consumer/` — runtime and public-contract
  compatibility plus external/no-dev consumer analysis. Migration fixtures
  and evidence are historical records, reviewed manually.
- `examples/` — runnable user-facing compiler and SQLite examples; useful
  examples are executed by `composer examples:check`.
- `docs/guides/` — user tasks and recipes; `docs/reference/` — current API,
  architecture, and support claims; `docs/maintainers/` — testing,
  benchmarking, migration, security, and release operations.
- `docs/adr/` — accepted durable architecture decisions; `docs/evidence/` —
  reproducible compatibility and release records; `docs/plans/` — temporary
  active release planning only.
- `tools/database-probes/` — synthetic SQLite, direct-engine, proxy, and
  contention probes plus disposable Docker fixtures. `benchmarks/` — compact,
  correctness-gated performance harnesses.
- `scripts/` — quality, coverage, documentation-link, public-API, and
  repository-verification scripts.
- `tools/database-probes/results/`, `benchmarks/results/`, `coverage/`, and
  cache/vendor directories are generated or local output and are ignored. Do
  not commit their contents or hand-edit a generated report to satisfy a gate.

For release status and support policy, do not rely on a hard-coded version in
this file. Check `CHANGELOG.md`, `SECURITY.md`, `SUPPORT.md`, `composer.json`,
the active branch, and `docs/maintainers/release-process.md`; report a
contradiction instead of guessing.

## Route changes to the right evidence

- **Compiler or SQL shape:** add/update compiler tests and the appropriate
  golden fixture, asserting exact SQL and binding order. Add SQLite execution
  coverage when runtime behavior matters, and direct/proxy probes when the
  claim is engine-, PDO-, or routing-sensitive.
- **Builder lifecycle, results, writes, cursors, or transactions:** cover
  success, validation/failure, state ownership, and terminal non-mutation in
  focused unit tests; add SQLite integration coverage for actual PDO behavior.
- **Public signature, overload, return shape, exception, or semantic contract:**
  document the use case, update focused tests and the relevant guide/reference,
  update `CHANGELOG.md`, and run `composer public-api:check`. For an intentional
  signature change, run `composer public-api:update` and review the complete
  `tests/Fixtures/Contracts/public-api.json` diff. Add an ADR when an accepted
  architectural or compatibility decision changes; add upgrade notes for a
  breaking change.
- **Database or proxy support:** update the support/reference and evidence
  records, add a reproducible probe and the relevant direct control, and never
  advertise support from compiler output alone or from another engine.
- **Raw SQL, identifiers, bindings, generated IDs, exception evidence, or
  transaction ownership:** include rejection and redaction cases as well as
  success cases, and review the applicable security checklist and ADR.
- **Performance or allocation work:** establish correctness first, use the
  existing benchmark scenario and comparison method, and record only a
  meaningful, reproducible result. Do not refresh a baseline merely because an
  internal refactor changed a number.
- **Documentation-only or agent-guidance work:** validate links and wording;
  do not create a changelog entry for internal instructions unless the user
  explicitly wants that maintenance change announced.

## Testing and quality commands

Install development dependencies before running the suite:

```bash
composer install
```

Use the smallest relevant check while iterating, then run the broader gate:

```bash
composer test -- --filter Connection
vendor/bin/phpunit tests/Unit/ConnectionTest.php
composer phpstan
composer cs:check
composer check
```

`composer check` is the normal container-free quality gate. It includes PHP
linting, the PHP 8.2 platform check, PHPUnit, PHPStan level 9, coding
standards, public-API drift, documentation links, executable examples, and
repository verification. It does not replace live database/proxy coverage.

Run coverage when source behavior changes or the task requires it:

```bash
composer test:coverage
composer coverage:check
composer test:coverage:branch
composer coverage:check:branch
```

The regular report enforces 90% overall and 95% compiler line coverage. The
separate Xdebug path/branch report enforces 80% overall and 90% compiler branch
coverage. Coverage output is generated under `coverage/`.

Run SQLite probes for PDO, execution, or transaction behavior:

```bash
composer probe:sqlite
composer probe:execution -- sqlite
composer probe:transaction -- sqlite
```

Run the full direct/proxy matrix only when the change needs it:

```bash
bash tools/database-probes/run-services.sh
```

That command requires Docker Compose, starts disposable MariaDB/MySQL/
ProxySQL/MaxScale fixtures, clears ignored probe output, and removes the
containers, networks, and volumes on exit. It is intentionally more expensive
than `composer check`.

Do not mock internal compiler or transaction objects. Prefer public compile
assertions, the existing controlled PDO seams for failure-state tests, live
SQLite tests, and the maintained database probes. Use synthetic fixtures only;
never add credentials, secret DSNs, customer data, private hostnames,
production topology, or proprietary SQL.

## Documentation, changelog, and planning discipline

- Public behavior needs a user-facing guide or reference update and a runnable
  example when that materially helps a new user.
- Keep `CHANGELOG.md` under `[Unreleased]` using standard Keep a Changelog
  sections (`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`).
  Include concise, notable user-, consumer-, or maintainer-facing changes.
  Omit task/stage labels, internal helper extraction, test line counts,
  quality-baseline republishing, micro-benchmark reruns, and routine refactors.
  Do not duplicate one change across sections.
- Use an ADR for changes to builder lifecycle, SQL input boundaries, binding
  representation/order, results or writes, transactions, supported engines or
  floors, observation guarantees, public extension boundaries, or runtime and
  release policy. Preserve the decision in `docs/adr/README.md`.
- Keep only the active release plan in `docs/plans/`. Move durable outcomes to
  the changelog, upgrade guide, ADRs, or evidence records when work completes.
- Do not update tracked evidence, benchmark baselines, fixture inventories, or
  public manifests just to make a failing check green. Regenerate them only
  when the reviewed contract or measured evidence actually changed.

## Release and compatibility policy

Stable releases use a `release/<version>` branch created from the default
branch and pass required CI. Before publishing, run the release checklist in
[`docs/maintainers/release-process.md`](docs/maintainers/release-process.md),
including:

```bash
composer validate --strict
composer audit
composer check
composer test:coverage
composer coverage:check
```

Also run the relevant branch coverage, direct/proxy probes, release evidence,
and a clean no-dev consumer installation. Release documentation must include
the dated changelog section, upgrade guidance, support notes, and migration
impact. After merge, tag the resulting default-branch commit as `v<version>`
and never move an immutable published tag; correct it with a new patch release.
ZeroVer permits documented breaking changes in a `0.y.0` release, but every
break still needs upgrade guidance.

## Completion handoff

Before reporting completion, confirm that:

- the diff is limited to the requested scope and preserves pre-existing user
  changes;
- success, failure/validation, binding order, and relevant engine behavior are
  covered;
- public documentation, examples, changelog, ADRs, manifests, and evidence
  were updated only when the change requires them;
- the selected quality commands passed, or the exact blocker and unrun checks
  are stated;
- no generated output, secrets, credentials, or unsupported compatibility
  claims were added.
