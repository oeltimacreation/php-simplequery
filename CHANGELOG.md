# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with ZeroVer releases before `1.0.0`.

## [Unreleased]

### Added

- Public documentation foundation.
- Initial architecture decision records.
- Composer package and PHP 8.2 development bootstrap.
- PHPUnit 11, PHPStan level 9 strict/deprecation rules, PHPCS 4/Slevomat,
  coverage thresholds, and pinned CI workflows.
- Versioned public API, aggregate, connection, migration, consumer-audit, and
  deployment-evidence contracts.
- Reproducible MariaDB, MySQL, SQLite, ProxySQL, and MaxScale PDO behavior
  probes, including file-backed SQLite contention and affinity fixtures.
- PDO SQLite benchmark control and release certification verifier.
- Immutable query values, mutable builders with typed private state, and
  deterministic MariaDB, MySQL, and SQLite compilers.
- Structured projection, predicates, joins, grouping, ordering, pagination,
  locks, writes, raw expressions, subquery snapshots, and clone isolation.
- Compiler testing utilities, versioned golden fixtures, executable examples,
  and a SQLite in-memory compiler smoke test.

[Unreleased]: https://github.com/oeltimacreation/php-simplequery
