# ADR-011: Supported databases and proxies

- Status: Accepted
- Date: 2026-07-17

## Context

A connection adapter does not prove SQL or behavioral compatibility. MariaDB
and MySQL also diverge despite sharing `pdo_mysql` and much SQL syntax.

## Decision

The initial first-class engines are MariaDB 11.8 LTS, MySQL 8, and SQLite
3.39.2 or later. MariaDB and MySQL use explicit driver choices and independent
capability/live suites.

ProxySQL and MaxScale are tested transport/routing paths, not dialects.
Certified versions and synthetic fixture configuration are published only
after reproducible testing.

Every other engine is unsupported and does not shape current abstractions.

## Consequences

- Support claims require golden compiler and live behavior evidence.
- Proxy routing, topology, and causal consistency remain operational concerns.
- Version/configuration-sensitive proxy behavior is documented explicitly.
- Adding an engine is a future product decision, not a compiler plugin install.
