# ADR-018: Evidence-gated compatibility

- Status: Accepted
- Date: 2026-07-17

## Context

PDO, engines, linked SQLite builds, and proxies have behavior that depends on
versions, attributes, SQL modes, buffering, routing, and session configuration.
Generic best-practice claims cannot substitute for deployment evidence.

## Decision

Deployment-sensitive policies require primary documentation plus minimal
synthetic reproductions against supported direct and proxy configurations.
Records capture source/date, tested version, non-secret configuration, results,
selected policy, known weakness, portability impact, tests, and owning ADR.

Unexpected behavior first updates the evidence record and decision process; it
must not cause an undocumented implementation divergence.

## Consequences

- Support matrices publish exact tested baselines before release.
- Direct engine results are the control for proxy tests.
- Private hostnames, credentials, customer data, and topology remain excluded.
- Compatibility claims can be reproduced and revised transparently.
