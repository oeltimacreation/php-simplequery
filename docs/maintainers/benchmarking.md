# Benchmark harness and baselines

Status: implemented reproducible harness, including the accepted `0.2.0`
hydration experiment and production-shaped `0.3.0` release comparison.

## Measurement contract

Every maintained scenario runs in a fresh PHP subprocess. Fixture construction
finishes before measurement, at least one explicit warm-up runs, and every
operation must produce the same SHA-256 JSON correctness digest during initial
validation, warm-up, and every timed sample. A correctness mismatch rejects the
scenario instead of emitting an accepted timing.

Multi-operation comparisons alternate forward/reverse order between rounds
(AB/BA for two operations). Reports contain unsorted raw samples plus
minimum/median/maximum values; absolute microsecond thresholds are deliberately
not used as portable gates.

The schema-version-2 JSON envelope records:

- source commit and dirty state;
- OS, release, and architecture;
- PHP version/SAPI, memory limit, OPcache/JIT, Xdebug, and PCOV state;
- PDO drivers plus client/server, direct/proxy target, prepare, buffering,
  persistence, and stringify-fetch configuration when applicable;
- scenario dimensions, warm-up count, raw samples, summaries, and digests;
- per-operation `allocated_after_sample_bytes`,
  `retained_growth_bytes`, and `retained_peak_above_first_bytes`;
- PHP allocated-memory peak and fresh-process maximum RSS;
- retained allocated-memory and open-file-descriptor deltas.

Workers are launched with PCOV and Xdebug timing modes disabled and refuse to
measure when instrumentation remains active. The outer orchestration process
may still have extensions loaded because it does not perform timed work.

## Commands and profiles

```bash
composer benchmark             # all deterministic SQLite scenarios, CI sizes
composer benchmark:baseline    # preserved v0.1.0 workload shapes
composer benchmark:hydration   # standalone 100,000-row hydration/cursor modes
composer benchmark:migration   # migration comparison only
composer benchmark:observer    # observer off/no-op at 1, 10, and 50 bindings
composer benchmark:plans       # SQLite range/function query-plan evidence
composer benchmark:production  # production-shaped reference-size scenarios
composer benchmark:reference   # full 100,000-row/reference-size profile
composer benchmark:soak        # repeated compile, lifecycle, cursor, and batch-write soak
```

The `ci` profile uses compact deterministic fixtures. The `reference` profile
uses the accepted audit sizes, including 100,000-row hydration/cursor fixtures,
1,000-row batch compilation/execution, and longer lifecycle loops. Redirect
stdout to a JSON file under the ignored `benchmarks/results/` directory.

## Implemented scenario matrix

`composer benchmark` executes these 23 fresh-process scenarios:

| Scenario | Operations or shapes |
|---|---|
| `pdo_control_10/100/1000/5000` | transaction inserts plus ordered associative fetch |
| `compiler_predicates_10/100/1000` | detached ordered-predicate build and compile |
| `compiler_shapes` | select, nested conditions, join, `IN`, raw bindings, and subquery snapshot |
| `compile_allocation` | allocation-sensitive fresh-builder compile loop (2000/20,000 compiles) |
| `batch_compile` | genuine multi-row insert compilation and binding count/order |
| `cursor_exhaustion` | associative and object cursor exhaustion |
| `cursor_early_close` | one-row consumption and explicit close |
| `read_terminals` | first, count/scalar aggregate, and direct PDO parity |
| `terminal_reuse` | repeated `first()`/`count()` terminals over fresh builders on the reused compiler/executor |
| `observer` | disabled, no-op, and bounded-recording observers |
| `batch_execute` | SimpleQuery `insertMany()` versus a direct prepared loop |
| `transactions` | managed outer/nested calls versus direct PDO/savepoint control |
| `lifecycle` | create/use/close loops versus direct PDO |
| `migration_query` | representative list/join result and timing parity |
| `production_report_compile` | 12 aliased/raw projections, nested filters, three joins, a date range, and a large `IN` list |
| `production_count_compile` | exact distinct-count SQL and ordered bindings over production-shaped filters |
| `production_report_execute` | object/associative full hydration, both cursor modes, direct PDO bulk read, and count parity |
| `production_batch_execute` | `insertMany()` versus repeated individual SimpleQuery writes with identical rows |

The former `hydration` scenario was removed in Phase 4: every read mode it
measured (direct PDO, associative, and object full hydration) is already
covered by `production_report_execute`, and attributable per-mode peaks remain
available from the `hydration-experiment` suite.

The historical baseline suite is the identical finalized worker restricted to the four
PDO controls, three ordered-predicate sizes, and migration query that preserve
the `v0.1.0` workload shapes.

`benchmarks/compare.php` accepts explicit `--baseline-label` and
`--candidate-label` metadata for any supported suite. It runs separate source
autoloaders in fresh processes, rejects cross-version correctness-digest
mismatches, and reports operation-level median changes. A change above 10% is a
review signal, not an automatic failure: it must repeat across paired source
orders before it needs investigation or a documented waiver. Existing schema-2
historical reports remain readable because the `baseline` and `candidate`
payloads and correctness fields are unchanged. The scenario catalog guards each
factory with `class_exists()` so the runner can compare against older source
autoloaders (for example `v0.3.0`) that do not contain every current factory
class. Pull-request CI compares `v0.3.0` and the candidate for the `baseline`
suite in addition to the `v0.2.0` production comparison.

The `hydration-experiment` suite runs direct PDO associative hydration,
SimpleQuery associative/object hydration, and both SimpleQuery cursor modes as
separate processes so each mode has an attributable PHP peak and RSS. The
runner also requires all five result digests to match. `benchmarks/compare.php`
accepts `--suite=hydration-experiment` and `--source-order=baseline-first` or
`candidate-first` for paired source-order review.

The `observer-profile` suite compares observer-disabled and no-op-observer
queries at 1, 10, and 50 positional bindings. `benchmarks/profile-observer.php`
is an explicitly instrumented workload for Xdebug hotspot ranking; its numbers
must not be mixed with timing results from workers, which reject active
profiling.

## Live engines, proxies, and soak

With services already available, `benchmarks/engine.php TARGET` compares the
same 1,000-row associative result through SimpleQuery and direct PDO for
`mariadb`, `mysql`, `proxysql`, or `maxscale`.

The scheduled/manual/release service workflow sets `RUN_BENCHMARKS=true` on
`tools/database-probes/run-services.sh`. It archives all four live comparison
reports and launches four concurrent soak suites. Direct engine results remain
the control for proxy interpretation.

The `soak` suite covers `compiler_repeated`, `lifecycle_soak`,
`streaming_cursor_soak` (a full cursor drain over 50,000 rows), and
`batch_write_soak` (a 2,000-row `insertMany()` plus delete per sample). The
harness records per-operation `allocated_after_sample_bytes`,
`retained_growth_bytes`, and `retained_peak_above_first_bytes`; the runner
fails any soak scenario whose retained allocation grows across timed samples
in the same process after warm-up beyond a portable 256 KiB bound. Soak
reports also include retained memory and descriptor deltas. The Phase 4 memory
and lock-contention evidence is in the
[`0.4.0` performance and stability evidence](../evidence/0.4-performance-and-stability.md).

## Query-plan evidence

`composer benchmark:plans` builds a deterministic indexed SQLite fixture and
compares two equivalent day filters. The half-open range uses the ordinary
`created_at` index for a bounded search; the `date(created_at)` form scans that
index. Result IDs must be identical before evidence is emitted. Plans explain
only this fixture and engine version: SimpleQuery does not create application
indexes or promise a database optimizer's choice. The recorded Phase 5 result
is in the [`0.3.0` performance evidence](../evidence/0.3-production-performance.md).

## CI and artifact policy

Pull-request CI archives the complete SQLite suite, SQLite query-plan evidence,
and labeled comparisons of `v0.2.0` (production suite) and `v0.3.0` (baseline
hot-path suite) against the candidate for 30 days. Scheduled/manual/release
service CI
archives live direct/proxy and multiprocess-soak JSON for 90 days. Raw outputs
are ephemeral artifacts, not committed universal thresholds.

The committed [`v0.1.0` evidence](../evidence/v0.1.0-performance-baseline.md)
remains the historical reference. The accepted
[`0.2.0` associative hydration experiment](../evidence/0.2-associative-hydration-experiment.md)
records both paired execution orders, the hard-gate decision, failure-path
coverage, and the observer profiling decision.
