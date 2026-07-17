# Release process

## Before release

1. Confirm all required CI and scheduled database/proxy jobs are green.
2. Review the public database support matrix and tested minimums.
3. Run the complete test, coverage, static-analysis, style, audit, example, and
   no-dev consumer suites.
4. Review benchmark artifacts for correctness and clear regressions.
5. Update `CHANGELOG.md`, `docs/upgrading.md`, support notes, and migration
   guidance.
6. Verify every public API change is documented and accepted by an ADR when
   required.
7. Perform the raw SQL/compiler security checklist.

## Publishing

1. Prepare a release branch and pull request.
2. Merge only through required CI and review.
3. Tag the default-branch commit with immutable `vX.Y.Z`.
4. Publish a GitHub release containing actual release notes.
5. Verify Packagist metadata and a clean consumer installation.
6. Never move a published tag; issue a patch release for corrections.

## ZeroVer

`0.y.0` may include documented breaking changes. Patch releases should remain
compatible within a minor line except for urgent security/data-integrity fixes.
Every break receives an upgrade entry even when ZeroVer permits it.

## Release evidence

Archive or link:

- CI run and tested runtime/database versions;
- public proxy fixture versions/settings;
- coverage report;
- benchmark comparison;
- dependency audit;
- documentation/example validation;
- security review outcome.

Evidence must use synthetic/non-secret configuration.
