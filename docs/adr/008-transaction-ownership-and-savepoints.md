# ADR-008: Transaction ownership and savepoints

- Status: Accepted
- Date: 2026-07-17

## Context

A nested helper that commits an externally started PDO transaction can violate
atomicity. Catching only `Exception` also misses rollback paths for `Error` and
other `Throwable` values.

## Decision

Managed depth zero owns the physical transaction. Nested managed callbacks use
generated savepoints. All `Throwable` values enter rollback handling.

If PDO is already in a transaction at managed depth zero, the callback API
throws `ExternalTransactionException`. It never adopts external ownership.
Live tracked cursors block transaction/savepoint completion.

No automatic callback retry or reconnect is provided.

## Consequences

- Only the outermost owner commits PDO.
- Inner failure can roll back to a savepoint while outer work continues.
- State mismatch and rollback/commit failure require explicit exception paths.
- Unusable connection state must be detected and discarded.
- DDL inside managed transactions is unsupported.

## Amendment: 2026-09-18 (`0.8.0`) — D5 ownership-guard cost

The root ownership guard costs one `SAVEPOINT`/`RELEASE SAVEPOINT` pair plus
transaction-state inspections per managed transaction. On disposable direct
MariaDB/MySQL fixtures an empty managed transaction measured roughly 1.8–2.2×
a bare `begin`/`commit` (tens of microseconds absolute), with a similar
absolute increment over a raw write that already uses a savepoint pair;
connection acquisition and session initialization are larger per-unit costs.
Nested scopes add one more pair. Control counts are exact and identical across
native/emulated and buffered/unbuffered profiles, including through the proxy
fixtures.

Decision: retain the guard and inspection by default. The pair is required to
detect an implicit commit or a replacement physical transaction before the
manager commits work it does not own; `inTransaction()` alone cannot
distinguish the original transaction from a replacement. Optional or
profile-controlled guards are declined for `0.8.0`: they need equivalent
failure evidence and a separate public opt-out contract. Re-open only with
measured consumer impact, an equivalent ownership check, and an amendment
here.
