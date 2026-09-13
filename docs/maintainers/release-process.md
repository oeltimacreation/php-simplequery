# Release process

## Before release

1. Merge the reviewed candidate pull request into the default branch, then
   create `release/<version>` from the default branch for release finalization
   (dated changelog, support notes, plan retirement). Never tag an unreviewed
   feature branch directly.
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
composer test:coverage:branch
composer coverage:check:branch
composer package:check

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

Release state is explicit. During development zero or one versioned plan is
valid; a present plan must target a version newer than the latest dated release.
After publication the completed plan is removed and a plan-free tree remains
valid, so release finalization does not need a speculative successor plan. The
benchmark baseline named by CI must be referenced by the benchmarking guide and
must identify the latest dated release; when no newer plan exists it may still
identify the previous release until the post-publication baseline bump. The
evidence index must reference the dated release, maintained index documents
must reference the active plan, and every workflow artifact upload needs a
unique name label.

A self-consistent old source snapshot cannot prove what was published elsewhere.
When certifying provenance, supply the independently verified immutable tag
version explicitly, for example `composer release:check -- --published-version=0.6.0`.
Ordinary source checks need no Git history or network access. Update that input
when the independently verified published release changes.

## Candidate certification handoff

Before publishing, retain the clean checkout identity and generated reports for
both local archives and their independent no-dev consumers. Use the
[distribution checks](distribution.md) for the matching hosted archive, then
verify the final published dist resolves to the immutable tag after publication.
A local archive or a green source check does not establish hosted provenance.

Record actual SQLite minimum execution with
`bash tools/database-probes/run-minimum-sqlite.sh`, and exact minimum/current
direct/proxy fixtures with the maintained service runner. Run both immutable
baseline source orders with fresh noise controls and the reference soak, as
described in [benchmarking](benchmarking.md). Review the full runtime CI matrix,
lowest-dependency lane and required service jobs for the candidate source.
An unavailable required environment stays pending rather than inheriting a pass
from a different PHP, database or source revision. Keep publication-dependent
version wording and plan retirement pending until those steps occur.
