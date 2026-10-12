# Connection wrapper release efficiency

Date: 2026-10-10. Scope: removal of reference cycles between a connection and
its cached executor/transaction manager. This is local PHP-process evidence,
not a database-server memory or application throughput claim.

## Sources and correctness

The before snapshot is commit `4b6d5ce29673f30dd1c0aef1ff05d28153631ef5`,
after the correctness and benchmark-validation fixes and before the ownership
refactor. Its `src` tree is `05fdcbfd876a0a355865b7a0d215d74ab198b99c`.
The after snapshot contains the stateless executor, explicit connection
arguments at terminals, and a weak owner reference in the transaction manager.
Its `src` tree is `b110988cdfaa2dd3825c06c4c231080c2637adc9`.
Both snapshots use optimized no-dev Composer autoloaders and the same maintained
benchmark harness from the candidate branch.

`tests/Unit/ConnectionReleaseTest.php` uses weak references without invoking
`gc_collect_cycles()`. It checks release after close, discard, and completed
nested transactions, while retained builders, raw queries, and cursors keep
their owner until released. These checks fail against the before source and
pass after the change (four failing cases before, four passing cases after).
Existing lifecycle, cursor, execution, and transaction failure tests continue
to enforce closed-state rejection and resource cleanup.

The executor and transaction manager remain closed implementation details.
This changes neither transaction control nor the owner of any query or cursor;
see [ADR-008](../adr/008-transaction-ownership-and-savepoints.md) and
[ADR-024](../adr/024-explicit-connection-lifecycle.md).

## Method and measured result

Environment: Linux x86_64, PHP 8.4.26, PDO SQLite 3.45.1, CLI OPcache/JIT
disabled, Xdebug and PCOV instrumentation disabled during timing. Each
operation runs in a fresh process. The CI profile uses three warm-ups and nine
samples. Five identical-after runs establish the host noise controls; the
absolute sub-millisecond floor is 0.138899 ms and the lifecycle operation's
own median range is 0.007898 ms.

Both source orders passed full SQL/result and ordered typed-binding digest
parity across all 36 CI scenarios and 96 operations. The paired comparison
gate found no repeatable candidate-operation regression; one-order signals
remain in the raw reports.

A separate paired comparison against the starting branch commit
`bfbcbff6b0d7bff8f947c4dd753a6dd08e5fd27e` also passes correctness parity and
the regression gate for the complete set of fixes, using the same after-source
noise control and harness. Its local reports are `all-fixes-baseline-first.json`
and `all-fixes-candidate-first.json` under `benchmarks/results/`.

The `lifecycle/simplequery` operation opens, queries, and closes 20 fresh SQLite
connections per sample:

| Source order | Before median | After median | Before retained growth | After retained growth |
| --- | ---: | ---: | ---: | ---: |
| Before first | 0.373425 ms | 0.379091 ms | 106,632 bytes | 3,720 bytes |
| After first | 0.380230 ms | 0.373656 ms | 106,632 bytes | 3,720 bytes |

Retained growth measures used PHP memory from the first to the last timed
sample. The after growth matches the direct-PDO control's 3,720 bytes of
harness/sample bookkeeping. Maximum transient used-memory increase falls from
22,152 to 2,744 bytes in both orders. File-descriptor deltas remain zero.
Timing changes reverse direction between orders and remain within the noise
controls; no speed improvement is claimed.

The reference-profile after soak also passes: seven samples after two warm-ups,
including 5,000 connections per lifecycle sample, 50,000 streamed rows, and
2,000-row batch writes. Every operation reports 2,968 bytes retained growth and
zero file-descriptor delta, within the maintained 256 KiB growth bound.

These results establish prompt wrapper release when no application-owned
reference remains. Escaped statements, cursors, observers, exception traces,
or other application references can still retain resources; physical transport
closure and server memory are not measured here.

## Local verification

`composer check` passes with 898 tests and 3,006 assertions, including PHPStan,
coding standards, public API, documentation, and executable examples. Line
coverage passes at 96.72% overall and 97.33% for compilers. Branch coverage
passes at 92.63% overall and 92.99% for compilers, with identical test results.
The branch run takes 6m46s on this host; its initial run exceeded Composer's
default 300-second limit. The complete rerun uses
`COMPOSER_PROCESS_TIMEOUT=900 composer test:coverage:branch`.

SQLite behavior, execution, and transaction probes pass. The socket-based LOB
fixture runs outside the filesystem/network sandbox. The direct MariaDB/MySQL
and proxy service matrix was not rerun: these changes preserve valid SQL shape
and engine support claims, and this record measures shared ownership logic and
local SQLite behavior rather than extending live-engine qualification.

## Reproduction

Create before/after source snapshots with `git archive`, then run
`composer dump-autoload --no-dev` inside each. Point the candidate branch's
harness at those autoloaders; do not compare different harness versions.
Use the same host and leave other quality/probe workloads idle while timing:

```bash
php -d pcov.enabled=0 -d xdebug.mode=off benchmarks/noise.php \
  --autoload=/tmp/simplequery-09-after-memory/vendor/autoload.php \
  --suite=ci --profile=ci --runs=5 --iterations=9 --warmups=3 \
  > benchmarks/results/connection-release-noise.json

for order in baseline-first candidate-first; do
  php -d pcov.enabled=0 -d xdebug.mode=off benchmarks/compare.php \
    --baseline-autoload=/tmp/simplequery-09-before-memory/vendor/autoload.php \
    --candidate-autoload=/tmp/simplequery-09-after-memory/vendor/autoload.php \
    --baseline-label=before-memory --candidate-label=after-memory \
    --suite=ci --profile=ci --iterations=9 --warmups=3 --source-order="$order" \
    --noise-report=benchmarks/results/connection-release-noise.json --allow-review \
    > "benchmarks/results/connection-release-$order.json"
done

php scripts/check-benchmark-comparison.php \
  benchmarks/results/connection-release-baseline-first.json \
  benchmarks/results/connection-release-candidate-first.json
php -d pcov.enabled=0 -d xdebug.mode=off benchmarks/run.php \
  --autoload=/tmp/simplequery-09-after-memory/vendor/autoload.php \
  --suite=soak --profile=reference --iterations=7 --warmups=2 \
  > benchmarks/results/connection-release-soak.json
```

Raw timing, memory, environment, and correctness reports are local generated
artifacts under ignored `benchmarks/results/`; they are not committed baselines.
