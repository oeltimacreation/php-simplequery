# ADR-014: Day-zero tests and documentation

- Status: Accepted
- Date: 2026-07-17

## Context

Adding tests and documentation after implementation lets accidental behavior
become the effective contract and makes engine claims difficult to verify.

## Decision

Contracts, user documentation, ADRs, test architecture, public test helpers,
engine fixtures, security guidance, and quality gates are foundation work.
Every implemented public behavior has executable examples/tests before it is
advertised.

## Consequences

- Repository bootstrap includes docs and CI rather than only source classes.
- Compiler and integration fixtures constrain implementation from the start.
- Examples must run in CI once implementation exists.
- Documentation status must distinguish planned from released behavior.
