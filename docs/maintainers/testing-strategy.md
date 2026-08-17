# Test strategy

The maintained test, coverage, probe, benchmark, and CI contract is documented
in [testing architecture](testing-architecture.md). This page remains as a
stable historical entry point for links from earlier release records.

Product behavior is covered at the smallest useful layer: isolated unit tests,
exact per-dialect compiler fixtures, live SQLite/integration tests, and
external consumer checks. Correctness-sensitive performance, memory, cursor,
contention, and direct/proxy behavior belongs to the maintained benchmark and
probe commands listed in the architecture reference.

Migration tooling is not an active test layer in 0.5. Synthetic migration
fixtures and reports remain historical evidence; future migration reviews use
the manual characterization checklist in
[`migration-review.md`](migration-review.md).
