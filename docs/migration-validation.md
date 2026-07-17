# Migration validation

Status: complete synthetic migration spike for the target `0.1.0` contract.

This validation uses library-owned synthetic recreations. It does not copy or
modify an application repository, publish private SQL, or claim that a real
application has already completed rollout. The executable corpus is
[`representative-slices.json`](../tests/Fixtures/Migration/representative-slices.json),
and its deterministic summary is
[`migration-validation.json`](evidence/migration-validation.json).

## Representative slices

| Slice | Recreated application shape | Validation | Support effort / rollout risk |
| --- | --- | --- | --- |
| SQLite importer CRUD | Dynamic allowlisted table, writable object row, generated ID, update/delete results, nullable first row | SQLite unit, example, and live smoke | Small / low |
| Injected shared model | Constructor-owned connection, fresh builders, raw projection, joins, direct PDO transaction | Independent compiler paths and all live targets | Medium / medium |
| Raw reporting model | Deferred raw result chain, positional bindings, vendor date grouping, aggregates | SQLite/MariaDB/MySQL plus proxy smokes | Large / high |
| Diagnostic raw join | Raw join binding order, detached compilation, redacted bounded observer | Exact SQL/bindings and live execution | Medium / medium |
| Complex list/join | Mutable filters, grouped predicate, inner/left joins, pagination, logical count | Direct PDO query/result parity on every live target | Medium / medium |

All five slices pass. The service matrix adds eight migration observations for
each of SQLite 3.45.1, MariaDB 11.8.8, MySQL 8.0.45, ProxySQL 3.0.1, and
MaxScale 23.02.17-2: 40 passed observations and no failures.

## Change and ambiguity measurements

The checked-in source pairs contain 79 non-empty legacy-shape lines and 84
native lines. A longest-common-subsequence comparison counts 63 inserted or
removed lines, while only eight legacy imports are mechanical candidates. The five slices
identify:

- four insert-return rewrites requiring call-site classification;
- one unsupported method (`updateOrInsert()`) requiring redesign;
- seven raw-SQL findings requiring trust/binding review;
- 21 safe mechanical edits and 53 manual edits;
- 31 query-parity and 23 result-parity cases;
- one low-, three medium-, and one high-risk rollout profile.

These are measurements of the synthetic recreation, not estimates secretly
derived from an external project. Application owners repeat the same inventory
against their own source and replace the numbers with application evidence.

## Automation decision

The bundled analyzer only rewrites an isolated, known Pixie import. It refuses
insert returns, raw interpolation, named placeholders, last-query diagnostics,
manual transaction completion, construction context, and unsupported methods.
Six of eight representative automation cases are intentionally refused.

This limited tool does not mutate application files. It demonstrates the safe
boundary for an optional project-owned codemod; it is not installed at runtime
and is not a compatibility layer.

## Query, result, and performance parity

The complex list slice compares native SimpleQuery output with a direct PDO
query over the same 500-row SQLite fixture. Both returned the same ordered 100
rows and digest. In the recorded PHP 8.4.23 / SQLite 3.45.1 run, median query
time was 0.1204 ms for SimpleQuery and 0.0507 ms for direct PDO, a 2.375 ratio
at sub-millisecond scale. This is an environment-specific review baseline, not
a universal limit. Correctness is gated; timing regressions are confirmed on
the same environment before release decisions.

The first MySQL live run also found a portability trap: separate prepared
parameters used as a repeated `DATE_FORMAT()` format are not considered the
same grouping expression under MySQL 8 `ONLY_FULL_GROUP_BY`. The migrated slice
now treats the fixed date format as trusted static SQL and binds the dynamic
filter. MariaDB accepted the original shape, illustrating why it remains an
independent target.

## Gate result

- no unknown blocker remains in the recreated supported fluent patterns;
- no runtime Pixie dependency, namespace alias, façade, or adapter exists;
- raw SQL, writes, diagnostics, transactions, and vendor expressions remain
  explicit manual review points;
- real application rollout remains application work and post-`0.1.0`
  production evidence, not a library-side source mutation.

Run the repeatable validation with:

```bash
composer migration:check
composer benchmark:migration
composer probe:migration -- sqlite
bash tools/database-probes/run-services.sh
```
