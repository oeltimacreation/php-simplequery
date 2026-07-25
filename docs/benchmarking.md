# Benchmark harness and baselines

Status: implemented reproducible harness; Phase 1 / package `0.2-A` and the
Phase 2 / package `0.2-E` hydration experiment completed 2026-07-26.

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
composer benchmark:reference   # full 100,000-row/reference-size profile
composer benchmark:soak        # repeated compile and lifecycle stress
```

The `ci` profile uses compact deterministic fixtures. The `reference` profile
uses the accepted audit sizes, including 100,000-row hydration/cursor fixtures,
1,000-row batch compilation/execution, and longer lifecycle loops. Redirect
stdout to a JSON file under the ignored `benchmarks/results/` directory.

## Implemented scenario matrix

`composer benchmark` executes these 18 fresh-process scenarios:

| Scenario | Operations or shapes |
|---|---|
| `pdo_control_10/100/1000/5000` | transaction inserts plus ordered associative fetch |
| `compiler_predicates_10/100/1000` | detached ordered-predicate build and compile |
| `compiler_shapes` | select, nested conditions, join, `IN`, raw bindings, and subquery snapshot |
| `batch_compile` | genuine multi-row insert compilation and binding count/order |
| `hydration` | direct PDO associative versus SimpleQuery associative/object full results |
| `cursor_exhaustion` | associative and object cursor exhaustion |
| `cursor_early_close` | one-row consumption and explicit close |
| `read_terminals` | first, count/scalar aggregate, and direct PDO parity |
| `observer` | disabled, no-op, and bounded-recording observers |
| `batch_execute` | SimpleQuery `insertMany()` versus a direct prepared loop |
| `transactions` | managed outer/nested calls versus direct PDO/savepoint control |
| `lifecycle` | create/use/close loops versus direct PDO |
| `migration_query` | representative list/join result and timing parity |

The baseline suite is the identical finalized worker restricted to the four
PDO controls, three ordered-predicate sizes, and migration query that preserve
the `v0.1.0` workload shapes. `benchmarks/compare.php` runs that suite against
separate `v0.1.0` and candidate autoloaders in fresh processes and rejects any
cross-version correctness-digest mismatch.

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
reports and launches four concurrent soak suites. Soak reports include repeated
compile and create/use/close timings plus retained memory and descriptor deltas.
Direct engine results remain the control for proxy interpretation.

## CI and artifact policy

Pull-request CI archives the complete SQLite suite and a `v0.1.0` versus
candidate baseline comparison for 30 days. Scheduled/manual/release service CI
archives live direct/proxy and multiprocess-soak JSON for 90 days. Raw outputs
are ephemeral artifacts, not committed universal thresholds.

The committed [`v0.1.0` evidence](evidence/v0.1.0-performance-baseline.md)
remains the historical reference. The accepted
[`0.2.0` associative hydration experiment](evidence/0.2-associative-hydration-experiment.md)
records both paired execution orders, the hard-gate decision, failure-path
coverage, and the observer profiling decision.
