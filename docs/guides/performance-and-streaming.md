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

### Buffered versus unbuffered results

Streaming at the PHP API does not guarantee server-side streaming:

- **MySQL/MariaDB buffered mode (default):** PDO receives the complete result set
  into client library memory immediately upon execute. PHP memory remains bounded
  as rows are yielded one-by-one by the cursor, but client network memory holds the
  full result. Other queries can execute on the connection while the cursor is open.
- **MySQL/MariaDB unbuffered mode:** `ConnectionOptions(buffered: false)` streams
  rows directly from the database server socket as they are consumed. This minimizes
  client memory for huge datasets, but the connection remains occupied in an
  active-fetch state until the cursor is exhausted or explicitly closed. No other
  query can execute on the connection while an unbuffered cursor is active.
- **SQLite:** SQLite is embedded in-process; iteration reads directly from the
  prepared statement step without network buffering.

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

`insertMany()` compiles one multi-row statement. It requires a uniform list of
rows with identical keys and deterministic first-row column order.

### Engine parameter and packet limits

`insertMany()` does not automatically chunk queries because chunking alters
atomicity, error semantics, and lock boundaries. Applications must choose chunk
sizes based on target database engine limits:

- **SQLite parameter limits:** SQLite enforces a maximum host parameter limit
  (999 parameters by default in older builds; up to 32,766 in SQLite 3.32.0+). A batch
  insert with $C$ columns per row can insert at most $\lfloor \text{limit} / C \rfloor$
  rows per single statement.
- **MySQL / MariaDB parameter limits:** Prepared statements support at most 65,535
  parameters (`2^16 - 1`). A batch insert of $C$ columns must not exceed
  $\lfloor 65,535 / C \rfloor$ rows per query.
- **Packet and statement size limits:** MySQL's `max_allowed_packet` and MariaDB's
  statement limits can reject very large batched SQL statements even if parameter
  counts are within bounds.

### Chunking and transaction policy

Applications choose between two primary batching strategies:

```php
$write = static function (Connection $connection) use ($rows, $batchSize): int {
    $affected = 0;
    foreach (array_chunk($rows, $batchSize) as $batch) {
        $affected += $connection->table('imports')->insertMany($batch);
    }

    return $affected;
};

// Option A: Single atomic transaction for all chunks
$affected = $db->transaction($write);

// Option B: Independent chunks (partial progress allowed)
$affected = $write($db);
```

- **Atomic batch (Option A):** Wrapping the batch loop in `Connection::transaction()`
  ensures all chunks succeed or all roll back. This holds database locks longer and
  incurs higher rollback cost if a later batch fails.
- **Independent chunks (Option B):** Executing chunks outside a shared transaction
  reduces lock contention and memory, permitting partial progress. The application
  is responsible for tracking progress and reconciling failures.

The runnable [SQLite batch-write example](../../examples/sqlite-batch-write.php)
demonstrates both choices with synthetic data.

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

The current [`0.6.0` release certification](../evidence/0.6-phase-6-release-certification.md)
and its linked phase records contain correctness-gated performance and memory
results. Query-plan behavior remains engine- and schema-specific; the builder
does not control application indexes.

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

## Caller-owned LOB bindings

`ParameterType::Lob` accepts a resource at construction. The application owns
its lifetime and position. SimpleQuery does not rewind, copy, buffer or close
that resource. Reusing a binding, raw query, clone or attached subquery shares
the same external state: snapshots isolate query structure, not stream bytes.
Supply fresh resources or explicitly manage their position for repeated work.
A resource closed before binding construction is rejected. Closing it afterward
leaves execution handling to PDO; do not depend on a portable exception shape.

The maintained execution probe characterizes current-position, repeated, empty,
closed, non-stream and non-seekable resource behavior without changing accepted
types. On local PDO SQLite 3.45.1, `abcdef` at position 2 yields `cdef`, then an
empty payload on reuse, and `abcdef` after caller rewind. These are observed
SQLite results, not promises for MySQL/MariaDB. Driver and prepare-mode results
belong in the live qualification evidence. Synthetic probe output retains
lengths, hashes and exception classes rather than raw driver messages.
