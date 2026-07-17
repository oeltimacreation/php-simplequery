# ADR-002: Fresh builder and stable terminals

- Status: Accepted
- Date: 2026-07-17

## Context

Reusable root handlers and terminal methods that retain temporary clauses make
query behavior order-dependent and difficult to test.

## Decision

Every `Connection::table()` call returns a fresh builder. Terminal methods do
not reset or mutate clause state. `first()` uses a temporary limit, `count()`
uses a temporary count shape, and repeated `compile()` calls are deterministic
while state is unchanged.

Structured child builders are snapshotted when attached.

## Consequences

- One builder represents one query definition.
- Terminal ordering cannot accumulate hidden clauses.
- Reusing a builder deliberately re-executes the same logical state.
- Snapshot and terminal non-mutation require explicit tests.
