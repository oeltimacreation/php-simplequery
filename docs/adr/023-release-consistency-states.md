# ADR-023: Release consistency states and benchmark baselines

Status: Accepted. Date: 2026-09-13.

## Context

Release consistency related the dated changelog release to support policy, the
active development plan, the benchmark baseline, and the evidence index.
Requiring exactly one plan that always targets a version newer than the dated
release, and requiring the baseline to name the just-dated release, made a
release commit or a correctly retired post-release tree fail. The failure forced
either a speculative next plan or a self-referential benchmark baseline.

## Decision

- Zero or one versioned plan is valid. A present plan must target a version
  newer than the latest dated changelog release; a plan-free tree is the
  post-release state and does not require a speculative successor plan.
- `.github/workflows/ci.yml` names exactly one benchmark baseline tag, and
  `docs/maintainers/benchmarking.md` references that same tag exactly once.
- A newer active plan (development state) requires the baseline to name the
  latest dated release. Without a newer plan, the baseline may name the latest
  or the previous dated release, covering release finalization and the baseline
  bump that follows publication.
- `docs/evidence/README.md` references the latest dated release and, while a
  plan is active, the plan filename together with the other maintained index
  documents.
- Every workflow artifact upload declares a non-empty `name:` label that is
  unique within its workflow file.
- The optional `--published-version` provenance comparison is unchanged.

## Consequences

Release finalization no longer depends on a speculative next plan or a
baseline that names an unpublished tag. Stale development baselines and
contradictory release facts still fail with explicit errors. The maintained
tests cover development, release-finalization, and plan-free states.
