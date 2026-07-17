# ADR-007: Deferred raw-query execution

- Status: Accepted
- Date: 2026-07-17

## Context

Immediate execution at raw-query construction makes result shape unclear and
causes exception timing to differ from fluent builder terminals.

## Decision

`Connection::query()` creates a dedicated `RawQuery` containing trusted SQL and
ordered bindings. Execution occurs only at `get()`, `first()`, associative
variants, iteration, or `execute()`.

Result terminals are for row-returning statements; `execute()` returns affected
rows for non-row-returning statements.

## Consequences

- Construction and execution are consistently separated.
- Fetch shape is selected explicitly.
- Exceptions occur at the terminal.
- `first()` does not rewrite arbitrary caller SQL; callers add server-side
  limits when needed.
