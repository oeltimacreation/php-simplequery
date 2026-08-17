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

```php
$cursor = $db->table('report_rows')->orderBy('id')->iterateAssociative();
try {
    foreach ($cursor as $row) {
        consume($row);
        if (reportIsComplete()) {
            break;
        }
    }
} finally {
    $cursor->close();
}
```

Natural exhaustion also closes the cursor, but explicit `finally` cleanup is
required when the loop can stop early or throw. Do not let a live cursor cross
a transaction/savepoint completion or connection-close boundary. The runnable
[SQLite streaming report](../../examples/sqlite-streaming-report.php) verifies
early cleanup.

## Batch writes

`insertMany()` avoids one round trip per row. It does not select a chunk size
or transaction policy. Applications must account for database parameter,
packet, statement-size, lock-duration, and rollback costs.

```php
$write = static function (Connection $connection) use ($rows, $batchSize): int {
    $affected = 0;
    foreach (array_chunk($rows, $batchSize) as $batch) {
        $affected += $connection->table('imports')->insertMany($batch);
    }

    return $affected;
};

$affected = $atomicImport ? $db->transaction($write) : $write($db);
```

`$batchSize` and `$atomicImport` are application decisions. One transaction can
make all chunks atomic but also lengthens lock duration and increases rollback
cost; independent chunks permit partial progress that the application must
record and reconcile. The runnable
[SQLite batch-write example](../../examples/sqlite-batch-write.php) keeps both
choices explicit.

## No speculative cache

Builder compilation caching is not supported because invalidation is complex
and a cache can retain sensitive values. Prepared-statement caching is also
outside the current contract because it consumes connection/server resources
and interacts with proxy and connection lifecycles.

Optimization requires a profile and a benchmark showing a meaningful benefit.

## Index-friendly predicates

Prefer comparisons that leave an indexed column unwrapped. For a half-open
calendar-day window, compute boundaries in application code and bind them:

```php
$query
    ->where('events.created_at', '>=', $startUtc)
    ->where('events.created_at', '<', $nextDayUtc);
```

This shape preserves exact boundary semantics and lets supported engines
consider a normal index on `created_at`. A predicate such as
`DATE(events.created_at) = ?` applies a function to each candidate value and
commonly prevents use of that ordinary index unless the engine and schema have
a matching functional/expression index. Confirm important cases with the
engine's query-plan tooling and production-like data.

The maintained [Phase 5 evidence](../evidence/0.3-production-performance.md)
shows this comparison on a synthetic indexed SQLite fixture. It explains those
exact shapes; it is not a portability claim or an assertion that the builder
controls application indexes.

Trusted raw expression comparisons are available for genuinely required
vendor functions, but their convenience does not make the resulting query
portable or index-friendly. Prefer structured ranges and indexed identifier
joins when they express the same business rule.

Associative full-result hydration now fetches and validates one row at a time
into the final returned list. This avoids retaining PDO's complete `fetchAll()`
array while constructing a second validated copy. Associative cursors likewise
validate and yield the fetched row without rebuilding it. The
[0.2 comparison](../evidence/0.2-associative-hydration-experiment.md) records
the paired `v0.1.0` evidence and acceptance decision; public return contracts
and validation behavior are unchanged.

Object hydration validates PDO's fetched list in place instead of rebuilding a
second list of the same objects. Use a cursor when the complete result should
not be retained in PHP memory.

## Benchmark suite

The executable fresh-process harness is documented in
[benchmarking](../maintainers/benchmarking.md). Its maintained matrix covers:

- simple and deeply conditional queries;
- joins and raw expressions;
- `IN` lists and nested subqueries;
- 100/1,000-row insert compilation;
- repeated deterministic compilation;
- object and associative hydration;
- cursor exhaustion and early close;
- observer disabled, no-op, and bounded-recording overhead;
- first/scalar/write terminals, managed transactions, and connection lifecycle;
- direct versus supported proxy paths;
- production-shaped report compilation, full/cursor reads, logical counts, and
  batch-versus-repeated writes.

Every scenario correctness-gates its raw samples, reports PHP peak allocation
and subprocess RSS, and runs without active coverage/profiling instrumentation.
Scheduled runs add four-process compile/lifecycle soak evidence.

Release review compares wall time, peak memory, allocation data where
available, correctness, and complexity growth. Fragile per-commit microsecond
limits are avoided.
