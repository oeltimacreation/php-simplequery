# Support Policy

PHP SimpleQuery follows ZeroVer until its public contracts have been exercised
in production and stabilized.

## Release compatibility

- `0.1.0` was the first public release; `0.6.0` is the current minor release.
- Patch releases within one `0.y` line should remain backward compatible,
  except for urgent security or data-integrity corrections.
- A `0.y.0` release may contain documented breaking changes.
- Consumers should pin a tested ZeroVer minor, such as `~0.6.0`.
- Every breaking change receives changelog and upgrade-guide coverage.

## Runtime policy

PHP 8.2 is the compatibility floor planned for the `0.x` and `1.x` lines.
Package compatibility is not a substitute for PHP security maintenance.
Production users must run a PHP build receiving security updates from PHP or a
responsible operating-system/vendor maintainer.

Removing a documented runtime or database floor requires a breaking release,
at least 12 months' notice, and migration guidance. Notice is published in this
file, the changelog, upgrade guide, and release notes. The support policy is
reviewed before each minor release and at least annually.

The MariaDB 11.8 LTS floor remains planned through the `1.x` line unless an
exceptional security or platform issue makes that impossible. The MySQL 8.0 and
SQLite 3.39.2+ floors were established at `0.1.0` and will not rise in a patch
release merely to simplify maintenance.

## Database support

A database/version combination is supported only when it appears in the public
support matrix and passes the required live tests. Proxy compatibility is
configuration-sensitive and will be published using reproducible test
fixtures, not inferred from protocol similarity.

See [database support](docs/reference/database-support.md) for the current target matrix.
