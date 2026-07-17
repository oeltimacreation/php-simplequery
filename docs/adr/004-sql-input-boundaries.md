# ADR-004: SQL input boundaries

- Status: Accepted
- Date: 2026-07-17

## Context

Identifiers, values, structured SQL, and raw SQL have different escaping,
authorization, portability, and composition rules. Treating them all as strings
creates injection and correctness risks.

## Decision

The API distinguishes:

1. identifiers quoted by a dialect;
2. values represented by bindings;
3. library-owned structured expressions/subqueries;
4. explicitly trusted raw SQL.

Normal identifier strings do not parse expressions, `AS`, operators, or sort
directions. Dynamic identifiers require application allowlists. Raw SQL is not
sanitized or made portable.

## Consequences

- Safe common operations remain structured.
- Complex vendor SQL remains possible through a visible trust boundary.
- Request input cannot select syntax simply by occupying a string parameter.
- Public expression values are a closed set owned by the library.
