# Upgrading

No release has been published yet. This guide will record actionable steps for
moving between SimpleQuery release lines.

## ZeroVer expectations

- `0.y.0` may contain documented breaking changes.
- `0.y.z` patch releases should remain compatible within that minor line,
  except for urgent security or data-integrity fixes.
- Consumers should pin a tested minor line such as `~0.1.0`.
- Changelog entries are not a substitute for migration instructions; every
  breaking change must be documented here.

## Upgrade checklist

For each future upgrade:

1. read the changelog and this guide;
2. review runtime, extension, database, and proxy support floors;
3. run static analysis and application compile assertions;
4. run SQLite and live database integration tests;
5. verify write-return and aggregate scalar expectations;
6. exercise transaction, cursor, and failure paths;
7. review generated SQL for raw or dialect-specific queries;
8. deploy through the application's normal staged rollout.

## Planned sections

Version-specific instructions will be added under headings such as:

```text
## Upgrading from 0.1 to 0.2
## Upgrading from 0.x to 1.0
```

Published release tags are immutable. Corrections are issued as new patch
releases.
