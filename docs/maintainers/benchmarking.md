# Benchmark workflow

The 0.5 benchmark surface is one data-driven, fresh-process runner. Fixture
setup is outside timed work, every operation is correctness-gated with a
SHA-256 JSON digest before warm-up and after every sample, and paired
operations alternate execution order. Reports retain raw samples, medians,
allocation deltas, retained-memory growth, file-descriptor deltas, PHP peak
allocation, and process RSS.

## Commands

```bash
composer benchmark
composer benchmark:soak
php benchmarks/engine.php mariadb
php benchmarks/engine.php mysql
bash tools/database-probes/run-services.sh
composer probe:sqlite:contention
```

Redirect benchmark output to the ignored `benchmarks/results/` directory. The
service workflow keeps the uncertain-write diagnostic separate and
operator-controlled; it is not a default quality gate.

## Maintained scenarios

The CI suite keeps the smallest set that covers the required behavior:

- PDO controls at 10, 100, 1,000, and 5,000 rows;
- predicate scaling, representative compiler shapes, allocation-sensitive
  compilation, and multi-row insert compilation;
- associative/object hydration, cursor exhaustion and early close, terminal
  results, terminal reuse, observer overhead, batches, transactions, and
  connection lifecycle;
- reference-only repeated compilation, lifecycle, cursor-drain, and batch-write
  soak scenarios.

Hydration, observer, production-shaped read/write behavior, and historical
workload shapes are represented by these core/reference modes rather than
separate profile classes or command wrappers. Historical migration and query
plan results remain in `docs/evidence/`; the tools that produced them are not
active 0.5 commands.

## Comparison policy

CI checks the candidate against a fresh `v0.4.0` worktree with the same `ci`
scenario set, profile, warm-ups, and odd sample count. The comparison rejects
cross-version correctness-digest differences and marks compiler or terminal
median increases above 5% for investigation. A marked result needs a
repeatable paired run and a documented explanation or waiver before release.

Workers refuse timing when Xdebug or PCOV instrumentation is active. The
benchmark runner also enforces the existing soak bound: retained allocation
must not grow by more than 256 KiB across timed samples in a worker. Direct
engine results remain the control for ProxySQL and MaxScale interpretation.

## Live and release evidence

`benchmarks/engine.php` compares a 1,000-row associative result with direct
PDO for MariaDB, MySQL, ProxySQL, and MaxScale. The service matrix retains
native/emulated prepares, buffered/unbuffered queries, execution and
transaction smokes, direct engine parity, four concurrent soak workers, and
the SQLite contention probe. Release certification additionally runs the
coverage floors, no-dev consumer checks, security review, and repository
verification described in [testing architecture](testing-architecture.md).
