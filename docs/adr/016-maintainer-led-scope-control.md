# ADR-016: Maintainer-led scope control

- Status: Accepted
- Date: 2026-07-17

## Context

An open-source query builder can accumulate requests for ORM behavior, many
engines, and broad extension points that exceed a small maintainer team's
capacity and weaken its core guarantees.

## Decision

Maintainers prioritize proven query-builder use cases, correctness, security,
and sustainable maintenance over ecosystem feature parity. A public feature
requires demonstrated need, clear engine semantics, tests, documentation, and
acceptable ongoing cost.

The project remains an open-source library, but external interest alone does
not expand the product boundary.

## Consequences

- Unsupported features can remain available through trusted raw SQL.
- ORM, schema, pooling, routing, retry, and plugin requests are declined unless
  the product scope is deliberately revised.
- Small public surface and honest support claims take precedence over breadth.
