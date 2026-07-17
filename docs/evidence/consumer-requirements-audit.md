# Consumer requirements and API review

Status: requirement review accepted; representative audit-grounded corpus
version 3.0.0 passes migration validation.

The current read-only workspace audit covers nine independently scannable
Pecee Pixie consumers: eight resolve 4.16.3 and one resolves 4.15.8. Public
records use anonymous aggregate profiles rather than paths, project names,
application source, or proprietary SQL. The frozen result is
[`consumer-audit-baseline.json`](consumer-audit-baseline.json).

## Read-only audit observations

The recursive scanner reviewed 836 non-vendor PHP files and found 3,290
`table()`, 4,683 `where()`, 3,378 `get()`, 1,158 `first()`, 1,221 `select()`,
413 join-family, and 1,481 `raw()` call candidates. All nine consumers contain
insert and raw-interpolation review candidates; eight contain joins; five use
raw `query()`; seven contain dynamic-table candidates; and one exposes direct
PDO through its application model boundary.

These are conservative lexical measurements across source, tests, scripts,
and checked-in support copies, not a runtime call graph. Manual review
confirmed the shapes represented by the five synthetic slices: connection
injection plus fresh handler creation, mutable fluent lists, raw query result
terminals, raw join bindings, compiled/last-query diagnostics, dynamic SQLite
tables, direct PDO, manual PDO transactions, and generated-ID pass-through.

Inspection of the installed Pixie implementation also confirmed why these
rewrites are semantic: single-row `insert()` returns an ID or null while batch
insert returns ID lists; update/delete return PDO statements; `query()`
executes before its later fetch terminal; last-query diagnostics interpolate
bindings into SQL; transaction callbacks permit explicit halt-style
completion; and the first connection is stored statically. None of those
implementation contracts is reproduced as a compatibility layer.

## Reviewed consumer styles

| Style | Migration-sensitive needs | Accepted native contract | Owner |
| --- | --- | --- | --- |
| High-volume mutable fluent application | Conditional mutation, joins, raw fragments, pagination, count | Mutable builder, stable terminals, raw trust boundary, logical count | Package maintainer |
| Transactional backend | Direct PDO, locks, compiled/debug SQL, explicit transaction ownership | `pdo()` escape hatch, typed locks, detached compile, strict external ownership | Package maintainer + application migration owner |
| SQLite importer/embedded process | Dynamic tables, writable object rows, generated IDs | Identifier allowlist guidance, `stdClass`, `insertGetId()`, SQLite live tests | Package maintainer |
| SQL-heavy reporting service | Deferred raw query results, MySQL functions, grouped predicates/having | `RawQuery`, trusted raw expressions, closure groups, aggregate scalar preservation | Application migration owner |
| Container-injected model layer | Independent connections, fresh table builders, object hydration | `fromPdo()`, no globals, fresh builder, object/associative terminals | Package maintainer |

This is review against more than three materially different styles. The
executable, anonymized synthetic cases live in
[`tests/Fixtures/Migration/v1.json`](../../tests/Fixtures/Migration/v1.json) and
the measured
[`representative-slices.json`](../../tests/Fixtures/Migration/representative-slices.json).
No runtime Pixie adapter is accepted.

## Required migration features

- mutable conditional query construction and fresh `table()` roots;
- object `get()` and nullable `first()`, with writable rows;
- two-/three-argument and grouped predicates;
- closure joins plus explicit raw join bindings;
- trusted raw SQL in select/predicate/join/group/order positions;
- raw SQL with positional bindings and result terminals;
- deterministic binding order, clone isolation, and child snapshots;
- insert IDs, affected-row writes, scalar counts, pagination, and aggregates;
- direct PDO access, lock clauses, transaction ownership, and diagnostics.

## Unsupported or rewrite-owned patterns

| Pattern | Resolution | Owner |
| --- | --- | --- |
| Interpolated request data in raw SQL | Security rewrite to values/allowlisted identifiers | Application migration owner; security review required |
| Pixie last-query interpolation | Detached `compile()` or bounded recording observer | Package maintainer provides API; application owner rewrites |
| Insert's polymorphic return | Classify per call; use `insertGetId()` only for ID consumers | Application migration owner |
| Direct PDO transaction completion inside managed callback | Rewrite ownership boundary | Application migration owner |
| Joined/ordered/limited writes | Trusted engine-specific raw SQL or application redesign | Application migration owner |
| Generic `updateOrInsert()` | Deferred; deliberate transaction/upsert design outside `0.1.0` | Package maintainer |

No observed requirement justifies unions, right joins, fetch-mode mutation,
table prefixes, static/default connection lookup, public statement-array
replacement, broad vendor exception subclasses, or a plugin/dialect extension
API.
