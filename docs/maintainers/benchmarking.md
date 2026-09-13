# Benchmark workflow

The maintained benchmark surface is one data-driven runner. Fixture setup is
outside timed work, every operation is correctness-gated with a SHA-256 JSON
digest before warm-up and after every sample, and each suite operation runs in
its own fresh PHP worker. Reports retain raw timing, allocation, RSS, retained-
memory, and file-descriptor evidence without making a product-wide speed claim.

## Commands

```bash
composer benchmark
composer benchmark:soak
php benchmarks/noise.php
php benchmarks/engine.php mariadb
php benchmarks/engine.php mysql
bash tools/database-probes/run-services.sh
composer probe:sqlite:contention
```

Redirect benchmark output to the ignored `benchmarks/results/` directory. The
service workflow keeps the uncertain-write diagnostic separate and operator-
controlled; it is not a default quality gate.

## Measurement contract

The CI and soak suites launch a scenario worker, which launches one clean
operation worker for every direct-PDO or SimpleQuery operation. Each operation
worker constructs an equivalent synthetic fixture before timing, performs the
configured warm-ups, and records an odd number of samples. Cross-operation
digest parity is checked only after every independent worker succeeds.

Immediately before each timed invocation the harness resets PHP's peak-memory
counter. `transient_peak_allocated_sample_bytes` is the increase in PHP
allocator-used memory reported by `memory_get_peak_usage(false)`; the
reserved-arena increase from `memory_get_peak_usage(true)` is retained
separately. Each operation also records current process RSS samples, worker
maximum RSS, allocated and used memory after every sample, retained growth, and
the worker's file-descriptor delta. This is PHP-process evidence, not total
database-server or container memory.

The live engine runner retains alternating full-result and non-retaining cursor
operations in one target connection because connection configuration is part
of that control. Scheduled service runs execute native prepares in both
buffered and unbuffered modes. The runner uses the same per-invocation
transient-memory samples, but its process-level RSS and descriptor evidence
still belongs to the shared target worker.

## Maintained scenarios

The 36-scenario CI suite covers 95 fresh-worker operations:

- direct PDO controls at 10, 100, 1,000, and 5,000 rows;
- predicate scaling, the representative build-and-compile shape, its prepared-
  builder compile-only control, allocation-sensitive compilation, and isolated
  `IN` lists at 10, 100, and 5,000 values;
- isolated three-column batch-insert compilation at 10, 100, and 1,000 rows;
  each dimensional compiler case reports SQL bytes, binding count, raw and
  median time per item, transient allocation, and worker RSS;
- equivalent-output high-cardinality controls separately measure `IN`
  placeholder arrays versus repeated strings and batch column validation,
  placeholder construction, scalar binding normalization, and pretyped
  compilation;
- a prepared 50-wide compiler attribution shape compares structured and raw
  identifier paths, accumulated and collapsed predicates, and deep and shallow
  compilation snapshots while requiring exact SQL and binding parity;
- narrow three-column and wide eighteen-column associative/object hydration,
  first-row terminals, natural cursor exhaustion, and early cursor close, each
  paired with a like-for-like direct-PDO result shape;
- isolated eighteen-column associative-key validation with `array_keys()` and
  direct-iteration controls;
- full associative results and non-retaining cursor summaries at 100, 1,000,
  and 10,000 rows, proving retained-result allocation growth independently of
  streaming consumption;
- disabled, no-op, bounded-recording, and failing observers at one and fifty
  bindings, plus single/redundant statement-close controls;
- combined read terminals, terminal reuse, batch execution, transactions, and
  connection lifecycle;
- reference-only repeated compilation, lifecycle, cursor-drain, and batch-write
  soak scenarios.

Historical migration validation remains in `docs/evidence/`; retired query-
plan scenarios and their generators are not active commands.

Component controls are attribution probes, not application throughput claims.
Each performs and validates its named work against the prepared reference SQL,
then returns the same normalized correctness facts so the fresh-worker digest
gate remains exact. Loop changes are considered only when their full prepared-
builder operation also clears the development plan's decision rule.

## Comparison and noise policy

For the current development plan, CI checks the candidate against a fresh
worktree at immutable `v0.6.0`. Labels and report filenames remain generic:
`release-baseline`, `candidate`,
`release-comparison-baseline-first.json`, and
`release-comparison-candidate-first.json`. Both source orders use three warm-
ups and nine samples per operation. The comparison rejects cross-source digest
differences and marks any median increase above 5% for review. CI fails only
when the same candidate-operation regression reproduces in both source orders.
Direct-PDO and named `*_control` operations are attribution evidence: a
repeatable movement there is retained in the reports but never blocks, because
it cannot be corrected in candidate code. A one-order candidate signal remains
in the reports as host/source-order variance.
`scripts/check-benchmark-comparison.php` applies the paired decision and
rejects malformed reports; comparison reports upload even when it fails.

Before the paired runs, CI performs five repeated identical-candidate runs with
the same three warm-ups and nine samples. The absolute timing noise floor is
the largest observed range between run medians among operations whose maximum
median remains below 1 ms. For a comparison where both medians are below 1 ms,
the relative percentage is ignored unless the absolute difference exceeds that
host-specific floor. The comparison still records every percentage. At any
duration, a greater-than-5% signal whose absolute change remains within that
operation's observed identical-source median range is retained as explained
variance rather than a regression. Only a signal outside both applicable
controls requires review. The raw identical-source runs and per-operation
ranges remain in `identical-source-noise-control.json`; controls are remeasured
on every host and are not copied between machines.

The CI-only `--allow-review` flag keeps both comparison reports available so
the workflow can apply the paired-source-order decision after both complete.
Workers refuse timing when Xdebug or PCOV instrumentation is active. The soak
runner retains the 256 KiB bound on allocated growth across timed samples.
Direct-engine results remain the control for ProxySQL and MaxScale
interpretation.

## Live and release evidence

`benchmarks/engine.php` compares 1,000-row full associative results and
non-retaining associative cursors with direct PDO for MariaDB, MySQL, ProxySQL,
and MaxScale. The service matrix retains native/emulated prepares,
buffered/unbuffered behavior probes, native result-memory controls in both
buffer modes,
execution and transaction smokes, direct-engine interpretation for proxy
results, four concurrent soak workers, and the SQLite contention probe.
Release certification additionally runs the coverage floors, no-dev consumer
checks, security review, and repository verification described in
[testing architecture](testing-architecture.md).
