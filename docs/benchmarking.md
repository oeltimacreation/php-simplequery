# Benchmark harness and baselines

Status: accepted methodology; no SimpleQuery implementation baseline exists yet.

`composer benchmark` runs a deterministic PDO SQLite control at 10, 100, 1,000,
and 5,000 rows. It measures transaction inserts plus associative hydration,
checks row-count correctness, and reports five-sample minimum, median, maximum,
and median time per row as JSON. This validates the harness and preserves a
PDO-only control before compiler/executor code exists.

Compiler benchmarks add scenarios for simple selects, predicate counts, nested
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
