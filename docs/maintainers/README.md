# Maintainer documentation

This page indexes the public maintenance contract. It intentionally contains no
private consumer inventory, production topology, credentials, or customer
information.

## Core references

- [Architecture](../reference/architecture.md)
- [Architecture decisions](../adr/README.md)
- [Active `0.3` development plan](../plans/0.3.md)
- [Test strategy](testing-strategy.md)
- [Testing architecture](testing-architecture.md)
- [Compatibility evidence records](../evidence/README.md)
- [Benchmark method](benchmarking.md)
- [PDO, engine, and proxy quirks](../reference/pdo-engine-proxy-quirks.md)
- [Release process](release-process.md)
- [Security review checklist](security-review-checklist.md)
- [Technical references](references.md)
- [Support policy](../../SUPPORT.md)
- [Contributing](../../CONTRIBUTING.md)

## Public/internal boundary

Public types require documentation, static-analysis coverage, executable
examples where appropriate, and upgrade notes for breaking changes.

AST nodes, compiler strategies, executor internals, transaction state objects,
and capability implementation details remain under `Internal` and are not
consumer extension contracts.

## Change control

A change requires an ADR when it alters:

- public builder mutation/lifecycle;
- input-domain security boundaries;
- binding representation or order;
- result/write return types;
- transaction ownership or failure behavior;
- supported engines or minimum versions;
- observer guarantees;
- the public/internal extension boundary;
- runtime/support/versioning policy.

Implementation must not silently diverge from an accepted ADR. New evidence can
supersede an ADR through a new record that explains compatibility and migration
impact.

## Scope control

New features need a demonstrated query-builder use case, engine semantics,
tests, documentation, maintenance cost assessment, and proof that a trusted raw
query is not the better narrow solution.

External interest alone does not justify ORM behavior, speculative database
support, or public plugin interfaces.

## Repository and release evidence

`composer verify` checks the repository foundation. Release/deployment
certification additionally runs `php scripts/verify-repository.php --certify` and
must link real, non-secret deployment reports. A public Docker fixture version
must never be copied into the deployment inventory merely to make that gate
green.
