# AGENTS.md — PHP SimpleQuery contributor instructions

## Project identity

- Package: `oeltimacreation/php-simplequery`
- Namespace: `Oeltima\SimpleQuery`
- Release line: `0.1.x` (ZeroVer)
- Runtime: PHP 8.2+ with PDO
- Supported engines: MariaDB 11.8, MySQL 8.0, SQLite 3.39.2+

SimpleQuery is a framework-agnostic PDO query builder. It is not an ORM,
schema/migration system, connection pool, retry engine, or extensible dialect
platform.

## Repository map

```text
src/                 Public API and closed internal implementation
src/Expression/      Identifier and trusted raw-expression value objects
src/Exception/       Library exception hierarchy
src/Observability/   Query observation contract
src/Testing/         Public compile-testing helpers
src/Internal/        Non-public AST, compiler, executor, transactions
tests/               Unit, compiler, integration, compatibility, migration tests
examples/            Runnable user-facing examples
docs/                User guides, API reference, operations, and ADRs
tools/                Reproducible database probes and migration analysis
```

## Essential commands

```bash
composer install
composer check
composer test:coverage
composer coverage:check
composer probe:sqlite
bash tools/database-probes/run-services.sh
```

`composer check` is the normal local quality gate. The service command is for
MariaDB, MySQL, and proxy integration coverage and uses disposable fixtures.

## Implementation rules

- Every PHP file uses `declare(strict_types=1)`.
- Concrete library classes are `final` unless deliberate extension is part of
  the public contract. Prefer readonly value objects and typed private state.
- Preserve the public boundaries: values are bindings; identifiers and raw SQL
  are code. Dynamic identifiers require application allowlisting.
- `Connection::table()` returns a fresh mutable builder. Clause methods mutate;
  terminals must not mutate or reset query state.
- Keep bindings ordered exactly as their placeholders occur in compiled SQL.
- Internal AST, compiler, executor, and transaction classes are closed; do not
  make them public extension points or add framework coupling.
- Connections, builders, and cursors belong to one execution unit. Do not add
  global connection state, transparent reconnects, or automatic retries.
- Interface/public API changes need a demonstrated use case, docs, tests,
  changelog entry, and an ADR when they change an accepted decision.

## Testing and documentation

- Put focused tests beside their layer: compiler tests in `tests/Compiler`,
  SQLite execution in `tests/Integration/SQLite`, isolated behavior in
  `tests/Unit`.
- Test success, validation/failure, binding order, and engine-specific behavior
  when relevant. Do not mock internal compiler or transaction objects.
- Use synthetic fixtures only; never commit real credentials, DSNs, customer
  data, private hostnames, or proprietary SQL.
- Public behavior needs a user-facing guide update and a runnable example when
  it is useful to a new user. Update `CHANGELOG.md` under `[Unreleased]`.

## Release policy

Stable releases use a branch `release/<version>` (for example
`release/0.1.0`) and are merged through required CI. Update the dated
`CHANGELOG.md` section, `docs/upgrading.md`, user-facing documentation, and
release evidence before opening the release pull request.

After the release PR is merged, tag that default-branch commit as `v<version>`
(for example `v0.1.0`) and create a GitHub release with the matching changelog
notes. Packagist/Composer exposes the normalized non-prefixed version
`0.1.0`. Published stable tags are immutable; correct a published release with
a new patch version, never by moving or recreating a tag.

Before publishing, run `composer validate --strict`, `composer audit`,
`composer check`, coverage checks, relevant direct/proxy probes, and a clean
no-dev consumer installation. See [docs/release-process.md](docs/release-process.md).
