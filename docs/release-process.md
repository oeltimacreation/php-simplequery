# Release process

## Before release

1. Create `release/<version>` from the default branch; never cut a stable
   release directly from a feature branch.
2. Confirm all required CI and scheduled database/proxy jobs are green.
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

1. Open a pull request from `release/<version>` and merge only through required
   CI and review.
2. Fetch the resulting default-branch commit and tag it with immutable
   `vX.Y.Z`.
3. Publish a GitHub release titled `vX.Y.Z` containing the matching changelog
   section as its notes.
4. Verify Packagist exposes the normalized non-prefixed version `X.Y.Z` and a
   clean no-dev consumer installation resolves the tagged commit.
5. Never move a published tag; issue a patch release for corrections.

```bash
git switch -c release/<version> origin/<default-branch>
composer validate --strict
composer audit
composer check
composer test:coverage
composer coverage:check

# After merge and green CI:
git fetch origin <default-branch> --tags
git tag v<version> origin/<default-branch>
git push origin v<version>
```

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
