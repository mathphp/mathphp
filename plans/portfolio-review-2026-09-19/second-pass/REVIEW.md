# Independent second-pass review

Reviewed 2026-09-19, locally and without implementation. Source baseline: core `e78b1ed7543129446d5c82f259a67f291e31f66a`; docs `2548ad7aabca192f2a453024da76127c06f8aba4`; Explaining `85567da10a0ed33ca5b5358a834799456a3a9988`; Visuals `b3da73c39176d72c8978d139efbfc8deae71bfdd`. Paths and line numbers below are relative to `/Users/mark/Projects/MathPHP`.

Read all five first-pass reports, workspace/project instructions, the improve and capability-scout skills and improve references. The CLEO injection remains absent; no tooling was initialized. Domains: PHP numerical library, PHPUnit verification, documentation authority and package release coordination. Existing review skills suffice; no external documentation or installation was needed for this source review.

README product purpose, root package identity and nested package descriptions identify a standalone scalar mathematics library and optional explanation/visualization products. They do not establish Eupry affiliation. No email-domain inference was used. Core, bundled website and relevant local nested metadata/docs were in scope; no other project was reviewed.

## Verdicts

| Original ID | Verdict | Independent evidence and reasoning | Impact / confidence |
|---|---|---|---|
| F-001 / T-001 | CONFIRMED | `website/public/index.php:22–32` trims trailing zeros from the entire formatted string. Executing this exact source function in memory produced `1.23e+20 → 1.23e+2`, `1.23e-20 → 1.23e-2`, and the same corruption for negative values. The actual endpoint sends both raw `result` and formatted `display` at line 169; `website/public/assets/site.js:51` renders `display`. | P1: user-visible magnitude corruption. High confidence; actual function reproduced, HTTP/browser not exercised. Core arithmetic is not implicated. |
| F-002 / T-002 | CORRECTED | Contradictions are real: `docs/V0.1-CONTRACT.md:32,54–55,94–95` reject supported syntax while line 139 includes implicit multiplication; `src/Parser/Lexer.php:215–254`, `tests/Facade/MathTest.php:46–64`, and `tests/Parser/ParserTest.php:531–557` support it. However, `CLAUDE.md:17–22` already assigns normative authority to the separate specs repository, and `README.md` points there. `docs/V0.1-TRACEABILITY.md:3–7` already declares a frozen, dated record. Do not create a competing local authority or rewrite historical outcomes by default. | P2: misleading local contract claims, not missing language capability. High confidence in local contradiction and existing authority; current external specification content unverified. |
| F-003 / T-003 | CONFIRMED | Visuals `composer.json:3,9` declares `0.1.0` and core `^0.1`; Explaining `composer.json:8–9` requires core and Visuals `^0.3`. These declarations are not a coherent intended current release set. `private/mathphp-docs/RELEASING.md:18–36` already defines the compatibility workflow. Units checkout is absent. | P2 for ecosystem release preparation; high confidence in metadata mismatch, runtime/install impact unverified. Not a core-only release blocker. |

## Material handoff corrections

1. T-002 should reconcile the local copy with the already designated external authority. The human decision is whether to archive the local copy or maintain an explicitly versioned mirror, not whether to invent a second current normative specification. Preserve the July traceability evidence and stable IDs. The local contract has also received newer grammar edits (`git log` shows bracket/bar changes); do not falsely call every line an untouched July snapshot.
2. T-003 is conditional on the release surface. `website/README.md:17–22` permits a public-only installation. `private/mathphp-explaining/README.md:11–13` explicitly makes Units optional for scalar explanations. Missing Units blocks the documented four-checkout checker, not public scalar evaluation. No package-version bump is justified from this inventory alone.
3. The first pass recorded clean nested checkouts. Explaining now has uncommitted changes in `src/ComposedElementaryEquationAnalyzer.php` and `tests/ComposedElementaryEquationAnalyzerTest.php`. These were not reviewed or altered. HEAD alone no longer describes that checkout's current source; coordinate ownership before using it as release evidence.
4. The lock checker is a reference check, not proof of installability or runtime compatibility: its relations table checks core references and Explaining→Visuals, and it accepts stable-tag references (`tools/check-ecosystem-locks.php:59–89`). Manifest validation and package tests remain separate gates.

## Checks actually run

- Read-only Git HEAD/status for core and the three nested checkouts; scoped contract history. Core had only the pre-existing untracked `plans/` tree; nested dirty state is recorded above.
- PHP CLI: **8.5.5**. PHPUnit: **11.5.56**.
- Exact formatter function extracted between its declaration and `renderLayout`, passed to PHP via stdin; no file, autoloader, request dispatch, server or duplicated implementation. Four exponent cases corrupted; controls `1.23e+21`, `1.25`, `1.0`, integer zero and 42 preserved expected displays. Process exit 0 means the probe ran, not that formatting passed.
- `rtk proxy php tools/lint.php website`: exit 0, **Syntax OK: 1 PHP file(s)**.
- `rtk proxy php vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result --filter 'MathPHP\Tests\(Facade\MathTest|Documentation\DocumentationExamplesTest)'`: exit 0, **83 tests / 227 assertions**. Result cache writes disabled. This verifies a selected local baseline only.
- Manifest field inspection and Units path existence check. No dependency resolution, lock checker, Composer quality, PHPStan, full tests, remote CI or deployment checks ran.
- Output JSON, counts, file inventory and unchanged first-pass content verified after writing.

## Limitations and cleanup

No exhaustive numeric/security audit; no private analyzer review; external normative specs, package registry state, production and separate website repository remain unverified. No evidence of a production failure was gathered; this is not evidence of production health. Passing sampled tests does not validate all supported PHP versions. Release gates remain pending.

All source and original reports preserved. No disposable runtime resources, service, build, install, Git mutation, tracker write or external message was created. Existing vendors, caches and unrelated work have uncertain ownership and were retained. Only four files under this new directory were written.

Next actions: implement T-001 in a separately authorized execution task; record the T-002 document disposition and T-003 release-scope decisions before dependent changes.

Closing drift check: Explaining advanced concurrently from `85567da10a0ed33ca5b5358a834799456a3a9988` to `97256333733c6230ac565f93ccdf5825fd74b75c` (commit title: “Map nonlinear reciprocal trig periodic branches”), and its worktree was clean at the final check. Earlier dirty-state observations above describe the initial snapshot, not the closing state. This review did not cause or review that commit. T-003 must recheck the new source and intended release refs; the initial snapshot is not current release evidence.
