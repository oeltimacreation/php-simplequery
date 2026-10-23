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

## Unknown transaction depth amendment (2026-10-23)

Observation-only transaction-state inspection failure must not erase the
statement notification. `QueryExecution::$transactionDepth` is nullable, including
its constructor parameter: `null` represents unknown depth. Preserve known
managed depth and the existing inspected unmanaged zero/one values. Never
fabricate zero, quarantine the owner solely for optional metadata, replace a
query result/failure, or retry the statement. Disabled observers perform no
metadata reads. Cursor observation still ends at handoff.

The type change requires 0.10 upgrade guidance and consumer/API-manifest review.
Mandatory transaction or lock-precondition inspection keeps its distinct
quarantine policy under ADR-008.

## Consequences

- Applications can bridge to their chosen logging/metrics systems.
- Disabled observation has a small hot-path footprint.
- Direct PDO and transaction-control statements are outside observation in
  `0.1.0`.
- Implementations own their durable observer-failure reporting.
