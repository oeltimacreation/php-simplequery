# Migration validation

This validation uses library-owned synthetic recreations grounded in a
read-only scan and manual review of nine Pecee Pixie 4.15.8/4.16.3 consumer
checkouts. It does not copy or modify an application repository, publish
private SQL, or claim that a real application has already completed rollout.
The anonymous aggregate evidence is
[`consumer-audit-baseline.json`](evidence/consumer-audit-baseline.json). The
executable corpus is
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

The checked-in source pairs contain 115 non-empty legacy-shape lines and 96
native lines. A longest-common-subsequence comparison counts 91 inserted or
removed lines, while only five same-name connection imports are mechanical
candidates. The legacy recreations now use the observed `Pecee\Pixie`
namespace and connection-plus-handler construction shape. The five slices
identify:

- four insert-return rewrites requiring call-site classification;
- zero unsupported methods in the recreated slices; `updateOrInsert()` remains
  a tested automation refusal if a later consumer introduces it;
- seven raw-SQL findings requiring trust/binding review;
- five safe mechanical edits and 86 manual edits;
- 31 query-parity and 23 result-parity cases;
- one low-, three medium-, and one high-risk rollout profile.

These change measurements belong to the anonymized synthetic recreation. The
separate audit baseline records lexical scale without copying consumer source.
Application owners repeat the same inventory against their own source and
replace the numbers with application evidence.

## Automation decision

The bundled analyzer only rewrites an isolated, same-name
`Pecee\Pixie\Connection` import. It refuses handler construction, insert
returns, raw interpolation, named placeholders, last-query diagnostics, manual
transaction completion, and unsupported methods. Seven of eight
representative automation cases are intentionally refused.

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
