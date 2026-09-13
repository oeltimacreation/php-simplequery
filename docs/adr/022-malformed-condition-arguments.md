# ADR-022: Reject condition argument holes

Status: Accepted. Date: 2026-09-11.

## Context

Named arguments could supply the final operand of a raw condition while omitting
the middle operand. The raw-predicate shortcut silently discarded that input.

## Decision

Reject these shapes with `InvalidQueryException` before attaching a predicate,
both in the shared condition factory and raw join comparisons. Preserve bare
raw predicates, valid overloads, ordered bindings and join null rejection.

## Consequences

This tightens invalid-input behavior without adding overloads or changing public
signatures. Callers must supply a complete comparison. Partial multi-column
builder mutation remains the established independent lifecycle contract.
