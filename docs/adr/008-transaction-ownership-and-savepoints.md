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
