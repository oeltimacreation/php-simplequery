# Contributing

Thank you for your interest in PHP SimpleQuery.

The project is currently establishing its `0.1.0` contracts and implementation.
Before opening a large change, start a discussion or issue so the proposed work
can be checked against the documented scope and architecture decisions.

## Principles

Contributions must preserve these boundaries:

- values remain bindings; identifiers and SQL syntax are never value-bound;
- raw SQL is an explicit trusted-code boundary;
- public builders may be mutable, but terminals do not mutate their state;
- connections, transactions, and cursors have explicit ownership;
- no global connection, automatic replay, or hidden reconnect;
- a database feature is not advertised without compiler and live tests;
- internal compiler and AST types are not extension points;
- public API additions require a demonstrated use case and documentation.

## Development expectations

The implementation bootstrap will provide the exact commands. The required
quality baseline is:

- PHP 8.2 or later;
- `declare(strict_types=1)` in every PHP file;
- PHPUnit tests;
- PHPStan level 9 or stricter;
- PHP_CodeSniffer 4 with PSR-12 and Slevomat rules;
- independent MariaDB, MySQL, and SQLite integration coverage;
- documentation and changelog updates for public behavior changes.

## Pull requests

Keep changes focused and include:

1. the problem and intended contract;
2. tests for success, failure, and boundary behavior;
3. user-facing documentation when behavior is public;
4. an ADR update when an accepted architectural decision changes;
5. migration notes for breaking changes.

Do not include credentials, production configuration, customer data, private
repository names, internal hostnames, or proprietary query samples.

## Reporting security issues

Do not open public issues for suspected vulnerabilities. Follow
[SECURITY.md](SECURITY.md).
