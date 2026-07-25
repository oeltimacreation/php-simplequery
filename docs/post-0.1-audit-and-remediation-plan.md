# Post-0.1 stability, performance, and quality audit

Date: 2026-07-18

Audited revision: `v0.1.0` / `16c59bf`

Worktree before the report: clean

Decision: use the findings and accepted baseline to scope `0.2.0`; a redesign is not.

Implementation update (2026-07-26): **Phases 0, 1, 2, and 3 completed
successfully** on `feature/0.2-candidate`. Correctness/resource safety, the
reproducible baseline, and the evidence-gated associative hydration
optimization plus type/test hardening passed their respective acceptance
criteria.

## Executive summary

The `0.1.0` release has a strong baseline. All repository quality gates, all
nine executable examples, all SQLite behavior probes, and the complete
disposable MariaDB/MySQL/proxy probe matrix passed. PHPStan level 9 is clean,
the package has no runtime Composer dependency beyond PHP and PDO, and repeated
compile, execute, and connection-lifecycle stress loops showed stable allocated
memory and file-descriptor counts.

Two correctness/resource-lifecycle findings should be fixed in `0.2.0`:

1. `sum()`, `average()`, `min()`, and `max()` retain `GROUP BY`/`HAVING` but
   fetch only the first scalar result. A grouped query can therefore silently
   return one arbitrary group's aggregate instead of rejecting an incompatible
   scalar shape.
2. `Cursor::close()` releases logical connection ownership even when physical
   `PDOStatement::closeCursor()` cleanup fails. On unbuffered MySQL-family
   connections this can make an uncertain connection appear reusable. A close
   failure can also replace an earlier fetch failure.

The performance evidence is encouraging but incomplete. Compiler scaling is
approximately linear, one million repeated compilations were memory-stable,
250,000 SQLite query executions were memory- and descriptor-stable, and cursor
hydration remained bounded at a 4 MiB PHP allocation peak for 100,000 rows.
However, associative full-result hydration peaked at 92.0 MiB versus 54.0 MiB
for direct PDO in the same synthetic workload, because rows are fetched in
bulk and then copied for validation. This is the clearest optimization
candidate, but it should be changed only after a committed, correctness-gated
benchmark confirms the result on representative row widths and engines.

The accepted repeated measurements and comparison rules are retained in the
[`v0.1.0` performance baseline](evidence/v0.1.0-performance-baseline.md), and
the implementation order and release gates are frozen in the
[`0.2.0` release plan](0.2-release-plan.md).

The current benchmark documentation overstates the executable suite. The
repository says nested groups, joins, `IN`, batches, hydration, cursors,
observers, memory, soak, concurrency, and proxies are maintained benchmark
scenarios. The committed harness currently implements only a PDO SQLite
insert/fetch control, ordered-predicate compilation, and one migration query.
The benchmark suite should be completed before making broader performance
claims.

## Scope, environment, and limitations

### Audit scope

- all programs listed by `composer examples:check`;
- lint, PHPUnit, PHPStan, PHPCS, migration validation, and repository checks;
- line coverage and the configured coverage policy;
- Composer manifest validity and advisory audit;
- SQLite behavior, execution, transaction, and migration probes;
- disposable MariaDB, MySQL, ProxySQL, and MaxScale probes;
- built-in benchmark repeated in seven fresh processes and the migration
  control in eleven;
- ad hoc compiler, hydration, observer, connection, and soak measurements;
- public API, compiler, executor, cursor, connection, and transaction code;
- test architecture, CI matrix, public type contracts, and documented edge
  cases.

### Measurement environment

| Item | Value |
|---|---|
| OS | Ubuntu 24.04.4 LTS, Linux 6.17.0-40-generic, x86-64 |
| CPU | Intel Core i5-13400, 10 cores / 16 logical CPUs |
| PHP | 8.4.23 NTS |
| SQLite | 3.45.1 |
| Database services | MariaDB 11.8.8, MySQL 8.0.45, ProxySQL 3.0.1, MaxScale 23.02.17-2 |
| PDO drivers | `mysql`, `sqlite` |
| Coverage | PCOV 1.0.12 line baseline; Xdebug 3.5.3 branch/path follow-up |
| CLI OPcache/JIT baseline | disabled |
| PHP memory limit | unlimited |
| Source footprint | 59 PHP files, 125,595 source bytes, 340 KiB on disk |
| Runtime dependencies | PHP 8.2+ and `ext-pdo`; no runtime package dependency |

Timing results are workstation baselines, not portable pass/fail thresholds.
The ad hoc stress commands were intentionally not committed as permanent
benchmark files. They establish audit evidence and requirements for the
maintained harness.

`perf` hardware counters remained unavailable because the host has
`kernel.perf_event_paranoid=4`. Xdebug 3.5.3 was installed after the initial
audit and supplied branch/path coverage plus Cachegrind function profiles.
Valgrind and a native allocation profiler were not installed. `strace -c`
showed 2,884 system calls in the built-in benchmark, dominated by PHP
startup/autoload memory maps and file metadata/read operations; only 4.826 ms
was attributed to system calls. Xdebug and PCOV were disabled for all accepted
timing/memory baselines; profiler times are used only to rank hotspots.

## Validation results

### Repository and examples

| Check | Result |
|---|---|
| `composer validate --strict` | passed |
| `composer audit` | passed; no known advisories |
| PHP lint | 119 files passed |
| PHPUnit | 287 tests, 1,141 assertions, 0.226 s, 12 MiB |
| PHPStan | level 9, PHP 8.2 target, no errors |
| PHPCS | passed |
| Migration report | passed; five slices, 31 query-parity and 23 result-parity cases |
| Repository structural verification | passed; 45 required records |

All nine configured examples passed:

- beginner first query;
- beginner filter/update;
- beginner transaction;
- compiler assertions;
- query building;
- SQLite compiler smoke;
- SQLite execution;
- SQLite managed transactions;
- SQLite migration slice.

The examples exercise real SQLite execution where applicable and are not only
syntax snippets. No stale or failing example was found.

### Coverage

| Metric | Result | Policy status |
|---|---:|---|
| Overall line | 92.57% (1,334/1,441) | passes 90% floor |
| Compiler line | 97.70% (255/261) | passes 95% floor |
| Overall branch | 89.47% (1,088/1,216) | passes 80% floor under Xdebug |
| Compiler branch | 92.03% (277/301) | passes 90% floor under Xdebug |
| Paths | 6.43% (463/7,197) | measured; no configured floor |

At audit time, Xdebug proved that the documented line and branch floors passed,
while CI still used PCOV and continued when branch metrics were absent. The
Phase 3 implementation update above closes that enforcement gap. The lowest
meaningful branch areas
include `JoinClause` (76.47%), `CompiledQuery` (76.92%), `Connection` (83.33%),
and `Executor` (86.21%). The low path percentage reflects combinatorial paths;
high-risk behavior should be tested directly instead of introducing an
arbitrary path floor.

### Engine and topology probes

The complete disposable service runner passed and cleaned up its containers,
volumes, and network.

| Target | Behavior profiles | Execution | Transactions | Migration |
|---|---|---|---|---|
| SQLite 3.45.1 | 11 observed | 11 passed | 15 passed | 8 passed |
| MariaDB 11.8.8 | native/emulated × buffered/unbuffered; 12 observed each | 13 passed | 15 passed | 8 passed |
| MySQL 8.0.45 | native/emulated × buffered/unbuffered; 12 observed each | 13 passed | 15 passed | 8 passed |
| ProxySQL → MariaDB | native/emulated × buffered/unbuffered; 12 observed each | 13 passed | 15 passed | 8 passed |
| MaxScale → MariaDB | native/emulated × buffered/unbuffered; 12 observed each | 13 passed | 15 passed | 8 passed |

This is strong compatibility evidence for the exact service versions. It does
not satisfy the testing strategy's separate claim that both minimum and current
versions are tested across each supported engine line: Compose and CI pin one
MariaDB and one MySQL version, and direct-engine probes run on PHP 8.2 only.

## Performance and stability evidence

### Maintained benchmark results

Seven complete process runs were collected with Xdebug and PCOV disabled.

The PDO SQLite insert-plus-fetch control remained approximately linear:

| Rows | Median range across runs | Median time/row range |
|---:|---:|---:|
| 10 | 0.013–0.014 ms | 1.30–1.40 µs |
| 100 | 0.065–0.066 ms | 0.650–0.660 µs |
| 1,000 | 0.606–0.775 ms | 0.606–0.775 µs |
| 5,000 | 3.113–3.308 ms | 0.623–0.662 µs |

The detached ordered-predicate benchmark also remained approximately linear.
At 1,000 predicates, medians ranged from 1.446 to 1.560 ms, or 1.446–1.560 µs
per predicate. `benchmarks/run.php` should perform an explicit warm-up before
recording samples, as its own documentation requires.

The migration query produced exact result parity and the same digest in all
eleven process runs:

| Metric | Range |
|---|---:|
| SimpleQuery median | 0.0799–0.0827 ms |
| Direct PDO median | 0.0498–0.0510 ms |
| SimpleQuery/PDO ratio | 1.584–1.643× |

At this sub-millisecond scale, the ratio is useful only as a same-machine
control. The absolute overhead of roughly 0.03 ms is small relative to a
network database round trip. Samples should be interleaved AB/BA rather than
collecting all SimpleQuery samples before all PDO samples.

GNU `time` reported 44,112 KiB maximum RSS for the main benchmark and 39,920
KiB for the migration benchmark. With CLI OPcache/JIT enabled, compiler medians
improved in this short run, but maximum RSS increased to 49,016 KiB and startup
outliers remained. No JIT-specific recommendation follows from one process
run; production applications should benchmark their real PHP runtime profile.

### Hydration and memory stress

A fresh process created 100,000 SQLite rows containing an integer, category,
and 96-byte payload, then ran exactly one result mode. Setup was identical for
all modes.

| Mode | Median query time | Median PHP peak allocation | Median process max RSS |
|---|---:|---:|---:|
| Direct PDO `FETCH_ASSOC` | 33.973 ms | 54.0 MiB | 98.8 MiB |
| SimpleQuery associative `get` | 64.423 ms | 92.0 MiB | 137.1 MiB |
| SimpleQuery object `get` | 51.312 ms | 62.0 MiB | 106.8 MiB |
| SimpleQuery associative cursor | 38.708 ms | 4.0 MiB | 49.7 MiB |
| SimpleQuery object cursor | 32.984 ms | 4.0 MiB | 49.7 MiB |

Conclusions:

- streaming is effective and bounded when the consumer does not retain rows;
- full-result memory necessarily scales with retained results;
- associative `get` has avoidable transient duplication because `fetchAll()`
  materializes one array and the executor builds a second validated array;
- object `get` validates and rebuilds only the outer list, so its overhead is
  materially smaller;
- cursor timings are close to direct PDO and do not justify a heavier streaming
  abstraction.

The most promising lightweight experiment is to fetch associative rows one at
a time into the final list, preserving key validation without retaining two
complete result arrays. A second experiment may validate keys and yield the
original associative cursor row rather than copying it. Neither should be
merged until the maintained benchmark proves a material benefit and all
failure/resource tests remain green.

### Compiler shape stress

Ad hoc single-run measurements were correctness-gated by binding count:

| Scenario | Size | Time | Additional observed allocated peak |
|---|---:|---:|---:|
| Ordered predicates | 1,000 | 1.652 ms | allocator reused existing pages |
| Ordered predicates | 5,000 | 8.859 ms | 4 MiB |
| `IN` list | 10,000 bindings | 4.427 ms | 2 MiB |
| `IN` list | 50,000 bindings | 21.760 ms | 6 MiB |
| `insertMany` compile | 1,000 × 3 values | 3.397 ms | allocator reused existing pages |
| `insertMany` compile | 10,000 × 3 values | 33.185 ms | allocator reused existing pages |
| Fixed query recompile | 100,000 compiles | 248.445 ms | no allocated-memory growth |

The sizes show approximately linear behavior. The peak-memory deltas in this
combined process are allocator-page observations rather than isolated scenario
peaks; the permanent harness must run memory scenarios in fresh subprocesses.
Large one-statement batches and `IN` lists still remain bounded by engine
parameter, packet, and SQL-length limits. The library should document
application-selected chunking rather than add automatic chunking that changes
atomicity and failure semantics.

### Soak and lifecycle stress

| Workload | Result |
|---|---|
| 1,000,000 repeated fixed-query compilations | 3.541 s median, 38,288 KiB median RSS; PHP allocation stayed 4 MiB |
| 250,000 build/compile/execute/fetch cycles | 2.167 s median, 39,256 KiB median RSS; PHP allocation stayed 4 MiB; FDs 5 → 5 |
| 50,000 SQLite connect/query/close cycles | 0.932 s median, 40,288 KiB median RSS; PHP allocation stayed 4 MiB; FDs 5 → 5 |

No leak slope, descriptor leak, crash, deadlock, or data mismatch was observed.
The connection loop collected 150,000 cyclic objects over 17 GC runs, which is
expected from short-lived connection/transaction-manager ownership. It did not
increase retained allocated memory. A scheduled long-duration, multi-process
database soak is still needed before claiming concurrency or network stability.

### Observer cost

For 50,000 trivial in-memory `SELECT ?` calls:

- observer disabled: 139.462 ms median, about 358,520 calls/s;
- no-op observer enabled: 170.982 ms median, about 292,429 calls/s;
- observed overhead: approximately 22.6%, or 0.6304 µs per call.

This intentionally isolates framework overhead with almost no database
latency; it is a worst-case percentage, not a network-database expectation.
The disabled path already avoids timing and event allocation. No optimization
is recommended until a maintained observer benchmark separates no-op and
bounded-recording observers across binding counts.

## Findings and recommendations

### P0 — Reject grouped non-count scalar aggregates

Status: **completed successfully (2026-07-26)**. Non-count scalar terminals
reject distinct/grouped/HAVING shapes before execution; compiler and SQLite
tests cover every terminal, supported joins/filters, empty results, grouped
count, and builder-state preservation.

`AbstractDialectCompiler::aggregate()` removes pagination and locking but
retains grouping and `HAVING`. `Executor::scalar()` then calls one
`fetchColumn()`. The audit reproduced the defect with two groups whose sums
were 3 and 100: `sum('amount')` returned 3.

Smallest safe remediation:

1. reject `GROUP BY` and `HAVING` for `sum`, `average`, `min`, and `max` with
   `UnsupportedFeatureException`;
2. explicitly decide and document `distinct()` behavior. Current SQL does not
   mean `SUM(DISTINCT column)`, so unsupported ambiguous shapes should fail;
3. add compiler and SQLite execution tests for grouped/HAVING/distinct scalar
   aggregates, plus supported filter/join and empty-result cases;
4. update the aggregate documentation and `[Unreleased]` changelog.

Do not change these terminals to return arrays in `0.2.0`; that would be a
larger public API expansion than the required correctness fix. A grouped
aggregate result API can be considered only with a demonstrated use case.

### P0 — Quarantine uncertain cursor cleanup

Status: **completed successfully (2026-07-26)**. Cursor finalization is
idempotent, false/throwing physical cleanup quarantines the connection, an
earlier fetch/result error remains primary, and explicit, exhaustion,
generator, and destructor paths share the same finalizer.

`Cursor::close()` marks the cursor closed and always releases connection
ownership even if `closeCursor()` throws; it also ignores a `false` return.
`rows()` invokes `close()` in `finally`, allowing cleanup failure to replace a
fetch failure. The existing unit test explicitly expects ownership release
after close failure, which is unsafe for an unbuffered statement whose physical
state is unknown.

Smallest robust remediation:

1. recognize `closeCursor() === false` as failure;
2. preserve fetch/result failure as primary when cleanup also fails;
3. add a connection quarantine/unusable state for uncertain statement cleanup;
4. converge explicit close, generator finalization, and destructor cleanup on
   one idempotent finalizer;
5. add controlled false-return, dual-failure, exhaustion-failure, quarantine,
   and non-throwing-destructor tests;
6. run native unbuffered MariaDB/MySQL and proxy probes after the change.

### P1 — Wire the passing Xdebug branch baseline into CI

Status: **completed successfully in Phase 3 (2026-07-26)**. A dedicated
Xdebug path/branch job enforces both configured branch floors from a separate
Clover report, while PCOV remains the line-only fast job. Missing configured
branch metrics now fail with executable checker coverage.

At audit time, Xdebug measured 89.47% overall and 92.03% compiler branch
coverage, while the PCOV-backed ordinary checker continued on missing branch
values. The completed status above records the implemented correction.

Preferred remediation:

- add an Xdebug branch/path-coverage CI job while retaining fast PCOV line
  coverage if useful;
- make configured branch thresholds fail when the selected report omits them;
- add tests for the coverage-checking script, including a missing-metric report.

Do not introduce a path-percentage floor from the initial 6.43% observation;
path coverage should guide direct tests for critical state transitions.

### P1 — Fail closed after ambiguous begin/savepoint dispatch failures

Status: **completed successfully in Phase 0 (2026-07-26)**. Failed begin is
reusable only after verified inactivity (including recovery rollback when
needed); uncertain nested savepoint creation quarantines the connection.
Controlled tests cover failures before and after successful parent dispatch.

Commit, rollback, and guard failures quarantine uncertain transaction state.
In contrast, thrown `beginTransaction()` and nested `SAVEPOINT` creation are
treated as recoverable without proving whether the server accepted the control
statement before the response failed. Controlled tests currently throw before
dispatch and therefore do not model ambiguity.

Remediation:

- after begin failure, allow reuse only if transaction state can be inspected,
  any active transaction can be rolled back, and inactivity is verified;
- quarantine after uncertain nested savepoint creation unless physical and
  savepoint state can be proven;
- add post-dispatch fault-injection cases that successfully perform the parent
  operation and then throw.

This conservative behavior matches the existing ownership model and is
preferable to adding retries or reconnect behavior.

### P1 — Bring benchmark claims and implementation into agreement

Status: **completed successfully in Phase 1 (2026-07-26)**. The dependency-
free schema-version-2 runner executes the documented compiler, executor,
observer, batch, transaction, lifecycle, migration, live-engine/proxy, and
multiprocess-soak scenarios. Every timing is correctness-gated and emitted with
raw samples, environment metadata, PHP peak allocation, and fresh-process RSS.

Immediate documentation remediation should label existing versus planned
scenarios. Then extend the small JSON harness rather than adding a benchmark
framework dependency.

Required benchmark envelope:

- commit and dirty state;
- OS/architecture and PHP SAPI/version;
- loaded debugger/coverage/JIT state;
- PDO client, engine, and proxy/direct identity;
- emulated-prepare, buffering, and relevant connection options;
- warm-up count, raw samples, min/median/max, and correctness digest;
- PHP peak allocation and subprocess maximum RSS.

Required scenario groups:

1. detached build-plus-compile and repeated compile for simple selects,
   predicates, nested groups, joins, `IN`, raw bindings, subqueries, and batch
   inserts;
2. direct PDO versus SimpleQuery object/associative full hydration, cursor
   exhaustion/early close, first/scalar/write terminals, and observer off/no-op/
   bounded recording;
3. managed outer/nested transactions and create/use/close loops;
4. scheduled direct-engine/proxy comparisons and multiprocess soak.

All scenarios must prove SQL/binding or result correctness before accepting a
timing. Review same-environment regressions and scaling, not fragile absolute
microsecond limits.

### P1 — Optimize associative hydration only after the benchmark exists

Status: **completed successfully in Phase 2 (2026-07-26)**. The accepted
[comparison report](evidence/0.2-associative-hydration-experiment.md) shows a
one-pass associative full-result peak of 78–80 MiB versus 116 MiB on `v0.1.0`
and 78 MiB for same-run direct PDO. The separately measured no-copy
associative cursor introduced no regression.

The measured 100,000-row associative path took 1.896× direct PDO query time and
used about 40 MiB more PHP peak allocation. The `v0.1.0` implementation fetches
all rows and then constructs a second complete result set to validate string
keys.

Experiment in this order:

1. replace `fetchAll()` plus whole-result copying with a validated `fetch()`
   loop that builds only the final result list;
2. separately test yielding the validated original associative cursor row
   rather than copying every key/value pair;
3. compare narrow/wide results at 10, 1,000, 10,000, and 100,000 rows;
4. retain explicit rejection of non-string column keys and all existing error
   translation/cleanup semantics.

Do not optimize object hydration unless profiles show a worthwhile gain; its
measured overhead is smaller and the code preserves useful runtime validation.

### P2 — Tighten public static-analysis contracts

Status: **completed successfully in Phase 3 (2026-07-26)**. Public positional
bindings, callback parameters, and cursor rows retain their precise downstream
types; consumer-constructible values validate list members at runtime. The
independent external-consumer fixture passes PHPStan level 9.

At audit time PHPStan was clean, but several public annotations admitted
invalid calls or lost known result information:

- positional raw bindings are documented as keyed arrays in `Connection`,
  `RawExpression`, `RawQuery`, and `CompiledQuery` even though runtime requires
  a list;
- `CompiledQuery` does not validate that every public constructor entry is a
  `Binding` before reading `$binding->type`;
- join closures should be annotated as `Closure(JoinClause): mixed`;
- condition/HAVING closures should be annotated as
  `Closure(ConditionGroup): mixed`;
- a PHPDoc-generic `Cursor<T>` could make `iterate()` yield `stdClass` and
  `iterateAssociative()` yield `array<string, mixed>` to downstream analyzers;
- public `QueryExecution` promises `list<ParameterType>` but does not validate
  listness/members when constructed by consumers.

Use PHPDoc and small boundary checks; do not split cursor implementations or
add a public type hierarchy solely for IDE inference. Add an external-consumer
PHPStan fixture so package annotations are checked from the caller side.

### P2 — Fill behavior gaps rather than chase coverage percentage

Status: **completed successfully in Phase 3 (2026-07-26)**. Focused tests and
the direct-engine behavior matrix now execute every listed high-risk case. CI
tests minimum/current MariaDB 11.8 and MySQL 8.0 fixtures and reports Xdebug
path coverage without imposing an arbitrary path floor.

Add focused tests for:

- grouped/HAVING/distinct scalar aggregate rejection;
- cursor `closeCursor() === false`, dual fetch/close failure precedence, and
  uncertain cleanup quarantine;
- mixed/null-only `IN` and `NOT IN` lists on every engine, explicitly freezing
  SQL three-valued-logic behavior;
- oversized integer strings round-tripped without conversion;
- duplicate and numeric result-column names, documenting associative/object
  hydration behavior;
- post-dispatch begin/savepoint ambiguity;
- cursor observer semantics: success/duration currently end at statement
  execute/hand-off, not full consumption;
- MySQL-family connection-profile failure branches under PHPUnit coverage.

Compatibility tests that only assert fixture strings are present should be
backed by executable cases. Keep fixtures as versioned records, but do not
treat text membership as proof that behavior works.

The engine matrix should parameterize at least the documented minimum and
current MariaDB/MySQL versions. Keep the broad PHP 8.2–8.5 SQLite matrix and add
another PHP runtime to direct-engine probes only if it catches a distinct PDO
behavior; avoid a combinatorial matrix without evidence.

### P3 — Clarify cursor observation and abandonment contracts

Status: **completed successfully in Phase 3 (2026-07-26)**. Documentation and
controlled tests freeze the one-event execute/hand-off contract, including no
second event after a later fetch failure, and reiterate explicit cursor close.

The observer records cursor success and duration when execution hands off a
cursor, before rows are consumed. A later fetch/close failure does not emit a
second event. This is a reasonable lightweight one-event contract but should
be explicit in `docs/observability.md` and tested.

Also emphasize that callers which obtain but abandon a cursor must call
`close()`; destructor cleanup is best effort and should not be the primary
lifecycle mechanism.

## Remediation and implementation plan

### Phase 0 — Correctness and resource safety

Status: **completed successfully (2026-07-26)**.

Estimated size: one focused patch per item; complete before performance work.

1. [x] Add failing grouped scalar aggregate tests, implement shape rejection, and
   update aggregate docs/changelog.
2. [x] Add cursor false/dual-failure tests, implement connection quarantine and
   deterministic exception precedence, then run SQLite and all unbuffered
   direct/proxy probes.
3. [x] Add post-dispatch transaction-control fault seams, make ambiguous begin/
   savepoint failures fail closed, and rerun transaction probes.

Acceptance:

- no scalar terminal silently consumes a multi-row aggregate shape;
- no uncertain statement or transaction state is exposed as reusable;
- original query/fetch failures remain authoritative when cleanup also fails;
- `composer check`, coverage, SQLite probes, and the full service matrix pass.

Acceptance result: **all Phase 0 criteria passed**. The complete disposable
matrix covered MariaDB 11.8.8, MySQL 8.0.45, ProxySQL, and MaxScale with native
and emulated buffered/unbuffered profiles; direct/proxy execution,
transactions, and migrations also passed.

### Phase 1 — Honest, reproducible performance baseline

Status: **completed successfully (2026-07-26)**.

Estimated size: one to two engineering days without a new dependency.

1. [x] Correct benchmark documentation to separate implemented and planned work.
2. [x] Refactor the current harness around a small scenario/measurement function.
3. [x] Add environment metadata, explicit warm-up, raw samples, correctness
   digests, peak PHP memory, and fresh-process RSS capture.
4. [x] Add compiler-shape, hydration/cursor, observer, batch, transaction, and
   lifecycle scenarios.
5. [x] Preserve JSON artifacts in CI; run deterministic SQLite scenarios per CI
   and engine/proxy/soak scenarios on schedule and releases.

Acceptance:

- every scenario verifies correctness before emitting measurements;
- timing and memory are separable by scenario and process;
- repeated runs show approximately linear compiler growth;
- the documented maintained matrix exactly matches executable scenarios.

Acceptance result: **all Phase 1 criteria passed**. The 18-scenario CI profile
passed with instrumentation disabled in timed workers; 10/100/1,000 predicate
medians scaled approximately linearly. The identical finalized baseline suite
produced matching correctness digests against `v0.1.0` and the candidate.
MariaDB, MySQL, ProxySQL, MaxScale, and four concurrent soak workers passed;
all soak compile/lifecycle workers reported zero retained allocated-memory and
file-descriptor deltas.

### Phase 2 — Evidence-based lightweight optimization

Status: **completed successfully (2026-07-26)**.

1. [x] Prototype associative fetch-loop hydration without changing public API.
2. [x] Benchmark it against the `0.1.0` implementation for time, PHP peak memory,
   RSS, and invalid-row/error paths.
3. [x] Merge only if the gain is reproducible and material; otherwise discard it.
4. [x] Profile observer parameter-type collection only if the maintained benchmark
   shows meaningful overhead under realistic binding counts.

Acceptance target for associative hydration: reduce the 92.0 MiB PHP peak on
the 100,000-row reference fixture to at most 70 MiB, or within 1.30× the
same-run direct PDO peak, without loss of key validation or a regression in
other result modes greater than 10% under repeated paired AB/BA measurements.
These are same-workstation goals; portable review uses same-run ratios and
correctness digests.

Acceptance result: **all Phase 2 hard criteria passed** in baseline-first and
candidate-first runs. Associative full-result peak allocation fell from 116
MiB to 78–80 MiB, or 1.00–1.03× the same-run 78 MiB direct-PDO peak, while
median time improved by 19.6–20.8%. Exact digests matched and object/cursor
modes had no greater than 10% regression. The 1.58–1.62× associative/direct
time ratio missed the non-blocking 1.50× aspiration. A 1/10/50-binding observer
sweep and 50-binding Xdebug profile found only 1.308 µs total no-op observer
overhead per query at 50 bindings, so no observer runtime complexity was added.
See the [Phase 2 evidence](evidence/0.2-associative-hydration-experiment.md).

### Phase 3 — Type and test hardening

Status: **completed successfully (2026-07-26)**.

1. [x] Correct list and closure PHPDoc contracts and add boundary validation where
   consumers can construct invalid public values.
2. [x] Add external-consumer static-analysis fixtures and the missing behavior
   cases listed above.
3. [x] Add the proven Xdebug branch/path command to CI and fail on absent configured
   branch metrics.
4. [x] Parameterize minimum/current engine fixtures and include engine PHPUnit
   behavior in coverage or a separately reported behavior matrix.

Acceptance:

- downstream PHPStan understands callbacks, positional bindings, and cursor
  row types;
- configured coverage thresholds cannot silently skip missing metrics;
- versioned contract records are backed by executable tests;
- supported engine-version claims match CI evidence.

Acceptance result: **all Phase 3 criteria passed**. Internal and external-
consumer PHPStan level 9 runs infer positional bindings, callbacks, and generic
cursor rows. Xdebug measured 89.18% overall and 92.18% compiler branch coverage;
configured missing branch metrics fail, while 6.75% path coverage remains
reported without a floor. Focused SQLite and direct-engine cases cover the
versioned contracts and missing high-risk behavior. MariaDB 11.8.2/11.8.8 and
MySQL 8.0.11/8.0.46 are now separate minimum/current CI fixtures. See the
[Phase 3 evidence](evidence/0.2-phase-3-hardening.md).

## Explicit non-goals

To keep SimpleQuery lightweight, this audit does **not** recommend:

- builder compilation or prepared-statement caches;
- cached compiler/executor objects without a profile proving value;
- global or pooled connection state;
- automatic retries, reconnects, or transaction replay;
- automatic batch chunking;
- SQL placeholder parsing or debug interpolation;
- a dialect/plugin framework;
- a benchmark framework dependency;
- separate runtime cursor classes solely for static-analysis precision.

The current tiny stateless compiler and executor allocations are unlikely to
justify added ownership/caching complexity. Database and network latency will
normally dominate them. Optimize the measured associative-copy path first and
only after the permanent harness can prove the benefit.

## Release recommendation

`v0.1.0` is operationally healthy for its documented core profile based on the
passing examples, quality gates, exact-version engine/proxy probes, and stable
local stress runs. The grouped aggregate behavior and uncertain cursor cleanup
should be treated as `0.2.0` remediation, with release notes describing the
stricter failure behavior. Benchmark and branch-coverage claims should be made
accurate in the same development cycle.

No git commit, tag, push, or shared-infrastructure change was performed during
this audit.
