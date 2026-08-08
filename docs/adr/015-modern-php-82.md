# ADR-015: Modern PHP 8.2 language policy

- Status: Accepted
- Date: 2026-07-17
- Amended: 2026-08-01 (PHP 8.3+ attribute policy, SQ-0434)

## Context

The project has no legacy runtime compatibility requirement and can use modern
language features to make invalid states harder to represent.

## Decision

PHP 8.2 is the minimum. The implementation uses strict types, enums, readonly
values, typed/promoted properties, precise union types, `match`, first-class
callables, `#[SensitiveParameter]`, and PHPDoc generics/shapes where they improve
the contract.

Features are selected for correctness and clarity, not novelty.

## PHP 8.3+ attribute policy

The minimum supported runtime is PHP 8.2, but the implementation deliberately
uses the `#[Override]` attribute (a PHP 8.3+ attribute) on methods that override
a parent or implement an interface or trait contract.

Why this is safe on PHP 8.2:

- Attributes are resolved lazily; an unresolvable attribute class is a no-op
  unless reflection explicitly instantiates it. The library never reflects on
  its own `#[Override]` attributes, so it is harmless below PHP 8.3.
- `#[Override]` is lexically ordinary attribute syntax (available since PHP
  8.0), so every source file still parses and runs on PHP 8.2.

Why it is still enforced:

- PHPStan runs with `phpVersion: 80200` and `checkMissingOverrideMethodAttribute:
  true` in both `phpstan.neon` and `phpstan.consumer.neon`, so accidental
  missing or invalid overrides are reported on every local `composer check`.
- On PHP 8.3+ runtimes, the PHP engine itself validates the attribute, and
  PHPStan's version-gated analysis continues to enforce the contract.

The PHP 8.2 floor is formalized as a local gate: `composer check` runs
`composer check-platform-reqs` (pinned `config.platform.php: 8.2.0`) and
`scripts/lint-php.php` verifies the `phpVersion: 80200` analysis floor, so
accidental PHP 8.3+ syntax is rejected on the local path, matching the PHP
8.2–8.5 CI matrix.

## Consequences

- Supporting PHP below 8.2 is not a design constraint.
- Value objects and closed choices can be expressed directly.
- PHPStan level 9 or stricter is a release gate.
- Raising the runtime floor follows the published support policy.
