# ADR-015: Modern PHP 8.2 language policy

- Status: Accepted
- Date: 2026-07-17

## Context

The project has no legacy runtime compatibility requirement and can use modern
language features to make invalid states harder to represent.

## Decision

PHP 8.2 is the minimum. The implementation uses strict types, enums, readonly
values, typed/promoted properties, precise union types, `match`, first-class
callables, `#[SensitiveParameter]`, and PHPDoc generics/shapes where they improve
the contract.

Features are selected for correctness and clarity, not novelty.

## Consequences

- Supporting PHP below 8.2 is not a design constraint.
- Value objects and closed choices can be expressed directly.
- PHPStan level 9 or stricter is a release gate.
- Raising the runtime floor follows the published support policy.
