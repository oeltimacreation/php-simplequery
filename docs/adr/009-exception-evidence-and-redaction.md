# ADR-009: Exception evidence and redaction

- Status: Accepted
- Date: 2026-07-17
- Amended: 2026-09-18 (connection construction evidence, W2)

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

## Amendment: connection construction evidence (2026-09-18)

Connection construction and SQLite bootstrap failures use `ConnectionException`
with normalized evidence: `operation=connect`, `sqlState`, `driverCode`,
`driver`, and `connectionLabel`. The raw `PDOException`, its driver message,
and its trace are deliberately not retained, so `getPrevious()` remains null
and DSNs, credentials, host/path details, and driver text cannot leak through a
chained exception. The `dsn`, `username`, and `password` parameters of
`Connection::connect()` are all marked `#[SensitiveParameter]`.

Closed-wrapper and compiler-only misuse use the same exception with
`operation=closed` or `operation=compiler_only`, so applications can separate
lifecycle bugs from a failed database connection without matching
human-readable messages. Query execution continues to retain the previous
`PDOException` for execution diagnostics, as originally decided.

Missing, empty, or malformed PDO `errorInfo`, and integer exception codes, are
normalized consistently for construction and execution failures. The library
still exposes no retry classifier: eviction, reconciliation, and replay
eligibility remain application decisions.
