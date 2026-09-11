# Release process

## Before release

1. Create `release/<version>` from the default branch; never cut a stable
   release directly from a feature branch.
2. Confirm all required CI and scheduled database/proxy jobs are green.
3. Review the public database support matrix and tested minimums.
4. Run the complete test, coverage, static-analysis, style, audit, example, and
   no-dev consumer suites.
5. Review benchmark artifacts for correctness and clear regressions.
6. Update `CHANGELOG.md`, `docs/guides/upgrading.md`, support notes, and
   migration guidance.
7. Verify every public API change is documented and accepted by an ADR when
   required.
8. Perform the raw SQL/compiler security checklist.

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

## Source consistency and published provenance

`composer release:check` requires recognized release facts and compares the
latest dated changelog release with support/security, installation guidance,
the active development plan and benchmark inputs. Missing or mutually stale
facts fail rather than silently skipping validation. A malformed latest release
cannot fall back to an older valid heading.

A self-consistent old source snapshot cannot prove what was published elsewhere.
When certifying provenance, supply the independently verified immutable tag
version explicitly, for example `composer release:check -- --published-version=0.6.0`.
Ordinary source checks need no Git history or network access. Update that input
when the independently verified published release changes.
