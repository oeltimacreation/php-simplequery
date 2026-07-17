# ADR-001: Mutable public builder

- Status: Accepted
- Date: 2026-07-17

## Context

Conditional fluent query construction commonly mutates a builder without
assigning every returned value. An immutable-only API adds repetitive caller
changes without making the internal query representation safer by itself.

## Decision

`QueryBuilder` clause methods mutate and return the same public builder. Query
state remains private and typed. Cloning produces independent mutable state,
and builders are not safe for concurrent use.

## Consequences

- Familiar conditional construction remains concise.
- Internal arrays or AST nodes are never exposed for mutation.
- Tests must prove clone isolation and prevent cross-builder state leakage.
- Applications must not share one builder between concurrent execution units.
