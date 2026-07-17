# ADR-005: Ordered positional bindings

- Status: Accepted
- Date: 2026-07-17

## Context

Nested raw expressions and subqueries require deterministic binding
composition. Named placeholders add repeated-name and renaming complexity that
the initial supported use cases do not require.

## Decision

MariaDB, MySQL, and SQLite compilation uses generated positional `?`
placeholders and `list<Binding>` in exact SQL occurrence order. Compilation
normalizes automatic values into concrete parameter types before producing a
`CompiledQuery`.

Mixed named/positional and associative raw binding arrays are excluded from
`0.1.0`.

## Consequences

- Parent and child bindings append during one depth-first traversal.
- Repeated compilation yields identical SQL and binding order.
- Raw SQL placeholder syntax remains PDO/driver-sensitive.
- Exact binding-order fixtures are required for every nested/raw shape.
