# Test strategy

## Layers

### Unit tests

- value objects and input validation;
- identifier and binding normalization;
- builder lifecycle, clone, and snapshot behavior;
- exception evidence;
- transaction state transitions using controlled seams where PDO permits;
- public testing helpers.

### Compiler tests

- golden SQL and exact concrete binding lists per dialect;
- every supported clause combination;
- raw/subquery binding order;
- reserved, qualified, and embedded-quote identifiers;
- null, Boolean, empty-list, and empty-group behavior;
- repeated deterministic compilation and terminal non-mutation.

### Integration tests

- live MariaDB, MySQL, and SQLite;
- prepare/bind matrix and fetched scalar types;
- hydration, affected rows, generated IDs, and batches;
- transaction/savepoint ownership and failure states;
- cursors, early close, and connection lifecycle;
- SQLSTATE and exception evidence;
- supported proxy paths as scheduled/release gates.

### Consumer tests

- `--no-dev` package installation;
- external compile assertions;
- SQLite in-memory execution;
- representative MariaDB/MySQL behavior;
- migration characterization fixtures using synthetic data.

### Migration tests

- five materially different, audit-grounded library-owned synthetic slices;
- an anonymized lexical baseline spanning nine read-only consumer checkouts;
- exact query/binding and direct-PDO row parity;
- changed-line, insert-return, unsupported-method, raw-SQL, effort, and risk
  measurements;
- deterministic safe import rewrites and mandatory semantic refusals;
- proof that the runtime package has no Pixie dependency or façade.

### Benchmarks and soak tests

- compiler scaling and memory;
- hydration and cursor behavior;
- observer overhead;
- long-running create/use/close loops;
- independent-connection process concurrency;
- direct versus proxy paths.

## Coverage floors

- overall line coverage: 90%;
- overall branch coverage: 80%;
- compiler/dialect line coverage: 95%;
- compiler/dialect branch coverage: 90%;
- every documented transaction state and failure edge: explicit test.

Percentages do not replace behavior gates. Binding order, raw SQL boundaries,
generated-ID timing, cursor cleanup, and transaction ownership require direct
assertions.

## Edge-case matrix

- literal question marks in raw SQL strings/comments;
- raw bindings before, between, and after ordinary bindings;
- nested/reused subquery snapshots and clone isolation;
- null and empty/null-containing lists;
- floats, decimals, oversized integer strings, binary, and LOB data;
- embedded identifier quote characters;
- aliases and duplicate result columns;
- zero/one-row results and aggregate null/precision;
- full-table writes and malformed batches;
- external/manual transaction-state mismatch;
- live cursors at every transaction completion edge;
- clean close, rejected close, and use after close.

## Commands

The fast command requires no containers:

```bash
composer check
```

The complete service-backed command uses exact, disposable fixtures:

```bash
bash tools/database-probes/run-services.sh
```

Coverage, the SQLite-only behavior/execution probes, and benchmarks run with
`composer test:coverage`, `composer coverage:check`,
`composer probe:sqlite`, `composer probe:execution -- sqlite`,
`composer probe:transaction -- sqlite`, `composer probe:migration -- sqlite`,
`composer migration:check`, `composer benchmark:migration`, and
`composer benchmark`. CI commands match local
commands and report fixture driver, version, SQLSTATE, placeholder SQL, and
setup context without leaking binding values. Naming, cleanup, and evidence
formats are frozen in [testing architecture](testing-architecture.md).

## CI jobs

The CI surface includes:

1. PHP 8.2 through the current supported PHP release;
2. live MariaDB at the minimum/current supported versions;
3. live MySQL at the minimum/current supported versions;
4. PDO SQLite on each PHP runtime with linked SQLite version reporting;
5. scheduled/release ProxySQL and MaxScale compatibility jobs;
6. Composer validation/audit, PHPUnit, PHPStan, PHPCS, and coverage;
7. lowest dependencies on PHP 8.2;
8. no-dev external consumer installation;
9. external compile assertion and SQLite test fixture;
10. executable documentation examples.

GitHub Actions are pinned to immutable commit SHAs and use read-only permissions
by default. Expensive proxy coverage may run on schedules and release branches,
but required release evidence must be green before a tag is published.
