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

The live engine runner retains alternating operations in one target connection
because connection configuration is part of that control. It uses the same
per-invocation transient-memory samples, but its process-level RSS and
descriptor evidence still belongs to the shared target worker.

## Maintained scenarios

The 29-scenario CI suite covers:

- direct PDO controls at 10, 100, 1,000, and 5,000 rows;
- predicate scaling, the representative build-and-compile shape, its prepared-
  builder compile-only control, allocation-sensitive compilation, and isolated
  `IN` lists at 10, 100, and 5,000 values;
- isolated three-column batch-insert compilation at 10, 100, and 1,000 rows;
  each dimensional compiler case reports SQL bytes, binding count, raw and
  median time per item, transient allocation, and worker RSS;
- narrow three-column and wide eighteen-column associative/object hydration,
  first-row terminals, natural cursor exhaustion, and early cursor close, each
  paired with a like-for-like direct-PDO result shape;
- combined read terminals, terminal reuse, observer overhead, batch execution,
  transactions, and connection lifecycle;
- reference-only repeated compilation, lifecycle, cursor-drain, and batch-write
  soak scenarios.

Historical migration validation remains in `docs/evidence/`; retired query-
plan scenarios and their generators are not active commands.

## Comparison and noise policy

For the current development plan, CI checks the candidate against a fresh
worktree at immutable `v0.5.0`. Labels and report filenames remain generic:
`release-baseline`, `candidate`,
`release-comparison-baseline-first.json`, and
`release-comparison-candidate-first.json`. Both source orders use three warm-
ups and nine samples per operation. The comparison rejects cross-source digest
differences and marks any median increase above 5% for review. CI fails only
when the same actionable regression reproduces in both source orders; a one-
order signal remains in the reports as host/source-order variance.

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

`benchmarks/engine.php` compares a 1,000-row associative result with direct
PDO for MariaDB, MySQL, ProxySQL, and MaxScale. The service matrix retains
native/emulated prepares, buffered/unbuffered queries, execution and
transaction smokes, direct engine parity, four concurrent soak workers, and
the SQLite contention probe. Release certification additionally runs the
coverage floors, no-dev consumer checks, security review, and repository
verification described in [testing architecture](testing-architecture.md).
