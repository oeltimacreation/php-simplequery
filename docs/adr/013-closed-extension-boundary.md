# ADR-013: Closed extension boundary

- Status: Accepted
- Date: 2026-07-17

## Context

Public compiler, AST, driver, middleware, and subclass hooks create long-term
compatibility obligations before the internal model has stabilized.

## Decision

The AST, compilers, executor, transaction manager, and capability strategies are
internal. Public expression inputs are final library-owned values; trusted
`RawExpression` is the escape hatch.

PDO injection and `QueryObserver` are narrow application integration points,
not a general extension architecture. Public concrete classes are final by
default.

## Consequences

- Internals can evolve during ZeroVer without third-party plugin compatibility.
- New dialects cannot be installed through a public registry.
- Applications integrate at connection, query, result, raw SQL, and observer
  boundaries.
- A future extension system would require a separate evidenced decision.
