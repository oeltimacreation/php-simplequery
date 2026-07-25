# Performance and streaming

Database and network execution normally dominate latency, but compilation,
hydration, and memory behavior still matter in high-throughput applications.

## Design targets

The implementation targets:

- one depth-first traversal per compile;
- one SQL-fragment accumulator;
- one ordered binding accumulator;
- no repeated `array_merge()` in loops;
- no debug interpolation in execution;
- no reflection in normal query paths;
- lazy observer/diagnostic allocation;
- one multi-row statement for `insertMany()`;
- approximately linear compilation growth with query size.

## Streaming

Use `iterate()` or `iterateAssociative()` when retaining the entire result set
would consume too much PHP memory. Always close early-terminated cursors in a
`finally` block.

Streaming at the PHP API does not guarantee server-side streaming. MySQL-family
buffered mode may receive the complete result into client memory. Unbuffered
mode changes connection availability and requires explicit configuration and
tests.

## Batch writes

`insertMany()` avoids one round trip per row. It does not select a chunk size
or transaction policy. Applications must account for database parameter,
packet, statement-size, lock-duration, and rollback costs.

## No speculative cache

Builder compilation caching is deferred because invalidation is complex and a
cache can retain sensitive values. Prepared-statement caching is also deferred
because it consumes connection/server resources and interacts with proxy and
connection lifecycles.

Optimization requires a profile and a benchmark showing a meaningful benefit.

## Benchmark suite

The executable fresh-process harness is documented in
[benchmarking](benchmarking.md). Its maintained matrix covers:

- simple and deeply conditional queries;
- joins and raw expressions;
- `IN` lists and nested subqueries;
- 100/1,000-row insert compilation;
- repeated deterministic compilation;
- object and associative hydration;
- cursor exhaustion and early close;
- observer disabled, no-op, and bounded-recording overhead;
- first/scalar/write terminals, managed transactions, and connection lifecycle;
- direct versus supported proxy paths.

Every scenario correctness-gates its raw samples, reports PHP peak allocation
and subprocess RSS, and runs without active coverage/profiling instrumentation.
Scheduled runs add four-process compile/lifecycle soak evidence.

Release review compares wall time, peak memory, allocation data where
available, correctness, and complexity growth. Fragile per-commit microsecond
limits are avoided.
