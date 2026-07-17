# Benchmark harness and baselines

Status: accepted methodology with PDO control and compiler-scaling scenarios.

`composer benchmark` runs a deterministic PDO SQLite control at 10, 100, 1,000,
and 5,000 rows. It measures transaction inserts plus associative hydration,
checks row-count correctness, and reports five-sample minimum, median, maximum,
and median time per row as JSON. The same run compiles queries containing 10,
100, and 1,000 ordered predicates, verifies binding counts and SQL shape, and
reports median time per predicate as the initial linear-scaling signal.

Additional compiler benchmarks add scenarios for simple selects, nested
groups, joins, large `IN` lists, batch inserts, raw binding composition, and
subquery snapshots. Executor benchmarks add object/associative hydration,
cursor, batch, and observer-on/off scenarios. Each scenario must verify SQL,
binding order, or row results before its timing is accepted.

## Baseline procedure

1. Use a clean checkout and record commit, OS/architecture, PHP version,
   extensions, PDO client, engine/runtime versions, and relevant configuration.
2. Disable debuggers and unrelated background load where possible.
3. Warm each scenario before collecting at least five samples.
4. Preserve raw JSON as a CI/release artifact; do not commit workstation output
   as a universal threshold.
5. Compare the same scenario and environment. Review wall time, peak memory,
   allocations when tooling supports them, correctness, and scaling ratio.
6. Reject super-linear growth or a clear release regression after confirming it
   is reproducible. Do not use fragile per-PR microsecond limits.

The hard compiler invariant is approximately linear growth with query size.
Database latency is reported separately from detached compilation. Proxy
measurements are compared with their direct-engine control and never used to
claim topology-independent performance.

## Migration query control

`composer benchmark:migration` recreates the complex list/join migration slice
over 500 SQLite rows, compares 100 ordered associative results with direct PDO,
and records nine-sample median timings. Correctness and result digest parity
are mandatory. The SimpleQuery/PDO timing ratio is recorded for review but is
not a portable hard threshold. The accepted 2026-07-17 control is retained in
[`migration-benchmark.json`](evidence/migration-benchmark.json).
