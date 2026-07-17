# ADR-017: ZeroVer and support floors

- Status: Accepted
- Date: 2026-07-17

## Context

The API needs production evidence before a stable `1.x` promise, while users
still need predictable patch compatibility and transparent runtime/database
floor changes.

## Decision

The project begins at `0.1.0`. `0.y.0` may break with migration guidance;
`0.y.z` should remain compatible within a minor line except for urgent security
or data-integrity corrections.

PHP 8.2 remains the planned floor through `1.x`. Database floors are published
and tested. Removing a floor requires a breaking release, advance notice, and
migration guidance.

`1.0.0` is evidence-gated by representative production use and public-contract
stability, not an arbitrary date.

## Consequences

- Consumers pin a tested ZeroVer minor.
- Runtime compatibility is distinguished from upstream security support.
- Minimum-version CI remains even when development tools require newer PHP.
- Changelog and upgrade documentation are required throughout ZeroVer.
