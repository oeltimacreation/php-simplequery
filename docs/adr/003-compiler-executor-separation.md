# ADR-003: Compiler/executor separation

- Status: Accepted
- Date: 2026-07-17

## Context

SQL composition, PDO behavior, binding order, and hydration are easier to
reason about and test when they are not implemented in one stateful class.

## Decision

Internal dialect compilers transform typed query state into an immutable
`CompiledQuery` containing placeholder SQL and ordered concrete bindings. A
separate internal executor prepares, binds, executes, hydrates, observes, and
translates errors.

These boundaries are internal implementation details, not replacement APIs.

## Consequences

- Compiler tests need no live database.
- PDO integration tests focus on driver behavior.
- Binding composition has one canonical representation.
- Compilation and execution can be benchmarked independently.
- Internal refactoring does not require third-party compiler compatibility.
