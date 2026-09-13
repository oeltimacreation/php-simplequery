# Distribution certification

Run `composer package:check` in a source checkout with Composer, Git and PHP's
Zip extension. It builds local Composer and Git ZIP archives, inspects their
contents and installs each into an independent no-dev consumer. The consumer
uses its own fixture and the artifact's declared autoload/runtime metadata,
with Packagist disabled, authoritative optimized autoloading, no scripts or
plugins, all three compiler targets and a live SQLite lifecycle. No repository
bootstrap or private test helper is imported by the installed consumer.

The allowed package roots are `src/`, `docs/`, `examples/`, Composer metadata,
license, README, changelog and contributor/security/support policies. Public
`src/Testing/` is required. Root tests, benchmarks, probes, CI, scripts, vendor,
local settings and caches are excluded. Composer wildcard matching requires an
explicit dotfile rule; a plain `/*` rule does not exclude hidden root paths.
Git export rules and Composer exclusions are independently exercised.

The reviewed clean package at candidate `b06cb8a` contained 163 files / 562,420
uncompressed bytes. A 225-file / 768-KiB ceiling allows bounded
documentation/source growth; allowed-root and forbidden-path checks remain
mandatory below that ceiling. The check also rejects missing toolkit/docs,
duplicate paths, traversal and symbolic links. A separate contaminated-source
archive injects harmless local settings, caches, vendor and coverage files and
must still pass inspection. Never raise a budget merely to hide an unexpected
file.

Generated archives, installed consumers and reports belong under ignored
`build/package-check/`. Reports identify the checkout commit and whether it was
dirty, plus each archive's SHA-256 and measured contents. A dirty-tree result is
development validation, not certification of an immutable source commit. Repeat
on a clean detached worktree before recording release provenance.

Pass an existing ZIP path to `php scripts/check-package.php` to inspect and
install a hosted source/dist artifact. Hosted archives may have one enclosing
repository directory. Independently verify their source reference; the reported
checkout identity describes the checker, not provenance of a supplied archive.
The `b06cb8a` hosted source archive was verified content-identical to the local
Git archive and passed the same installed no-dev consumer. A candidate without a
hosted source reference cannot have a matching artifact or remote CI result.
Final published-dist verification remains a post-publication step.

Installed README, guides, reference pages and examples remain available. Public
contract fixture links lead to the hosted source. Maintainer/evidence documents
are included for context; their links to excluded development tooling require a
source checkout. Runnable examples describe source-checkout execution; the
installed consumer lifecycle uses the application's own autoloader.
