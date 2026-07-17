# ADR-009: Exception evidence and redaction

- Status: Accepted
- Date: 2026-07-17

## Context

Interpolated SQL in exceptions can leak secrets and is not a reliable
representation of executed prepared statements. Callers still need structured
evidence to diagnose failures.

## Decision

Query execution exceptions expose SQLSTATE, driver code where available,
placeholder SQL or a compiled query, driver identity, and the previous
`PDOException`. Binding values are not interpolated into messages.

Transaction-control failures use transaction exception types. Application
exceptions are rethrown unchanged when rollback succeeds.

## Consequences

- Automatic exception logging is less likely to expose values.
- Diagnostics remain machine-readable.
- Any structured binding access must be deliberately redacted and reviewed.
- Constraint-specific subclasses are deferred until portable SQLSTATE behavior
  is proven for every supported engine.
