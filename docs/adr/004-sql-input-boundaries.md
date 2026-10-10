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

## Identifier clause validation amendment (2026-10-10)

Identifier objects retain their typed shape at every clause boundary. Column
operands in predicates, joins, grouping, ordering, and scalar aggregates reject
aliases and wildcards before mutation. Projection aliases/wildcards and source
aliases remain valid. A projected alias referenced by another clause is a plain
identifier name, not a second alias declaration. Trusted raw expressions remain
caller-authored SQL and are not parsed by this validation.

## Consequences

- Safe common operations remain structured.
- Complex vendor SQL remains possible through a visible trust boundary.
- Request input cannot select syntax simply by occupying a string parameter.
- Public expression values are a closed set owned by the library.
