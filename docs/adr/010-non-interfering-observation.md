# ADR-010: Non-interfering observation

- Status: Accepted
- Date: 2026-07-17

## Context

Pre-execution mutation hooks make compilation nondeterministic and can change
database outcomes. Applications still need timing, error, and query-shape
telemetry.

## Decision

One optional connection-scoped `QueryObserver` receives immutable post-attempt
metadata for executor-owned builder/raw statements. It cannot modify SQL,
bindings, results, or transactions.

Observer failures are caught and never replace database success or the original
failure. Values are redacted by default. No global registry or core logging
dependency is provided.

## Optional write metadata amendment (2026-10-10)

Failure isolation includes metadata collected only for observation.
`insertGetId()` captures its generated ID immediately after execution. A later
optional `rowCount()` failure must not replace that ID or turn its successful
notification into a failure; affected rows are unknown (`null`) in that event.
Counts required as a terminal's return value remain authoritative and their
failures still propagate. No write is retried or replayed.

## Consequences

- Applications can bridge to their chosen logging/metrics systems.
- Disabled observation has a small hot-path footprint.
- Direct PDO and transaction-control statements are outside observation in
  `0.1.0`.
- Implementations own their durable observer-failure reporting.
