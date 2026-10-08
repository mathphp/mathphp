# Revised execution handoffs

Planning only. No implementation is authorized by this review. All paths are relative to `/Users/mark/Projects/MathPHP`; run commands there unless a different working directory is stated. Core baseline `e78b1ed7543129446d5c82f259a67f291e31f66a`, 2026-09-19. Preserve user edits and original reports. No task requires deployment or a new mathematical feature.

| Revision | Priority / owner | Dependency | Outcome |
|---|---|---|---|
| T-001 / second-pass r1 | P1 / AI | Separate implementation authorization; local test baseline | Production formatter preserves magnitude with regressions |
| T-002 / second-pass r1 | P2 / Pair | Human document disposition; authoritative material for mirror option | Local documentation clearly reflects existing authority |
| T-003 / second-pass r1 | P2 / Pair | Human release scope/refs; ownership of dirty Explaining work | Explicit release inventory and conditional gates |

## Common verification and drift rules

Before each task run `rtk proxy git status --short` and `rtk proxy git diff e78b1ed7543129446d5c82f259a67f291e31f66a -- <exact scoped paths below>`. Replace the path placeholder with that task's literal paths. Compare tracked changes and current content, not HEAD alone. Stop on unexplained drift, unavailable dependencies, requested out-of-scope access or failures outside the change. Do not install, discard local changes or broaden scope automatically. No branch, commit or push is required by these plans.

Commands below are defined by inspected repository files. “Defined” does not mean run successfully. `composer.json:30–44` defines `test`, `syntax`, `analyse`, `style` and `quality`; `tools/lint.php:5–9` accepts explicit paths; `phpunit.xml.dist` discovers nested `*Test.php` files.

| Command | Evidence / expected result |
|---|---|
| `rtk proxy php vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result --filter 'MathPHP\Tests\(Facade\MathTest|Documentation\DocumentationExamplesTest)'` | Ran: 83 tests / 227 assertions, exit 0 on PHP 8.5.5. Re-establish after drift. |
| `rtk proxy php tools/lint.php website` | Ran: one file, exit 0. File count changes after extraction. |
| `rtk proxy composer test` | Manifest-defined full PHPUnit gate; not run here. Expected exit 0, no failed/risky tests or configured warnings. Writes normal PHPUnit cache. |
| `rtk proxy composer quality` | Manifest-defined validation, autoload regeneration, syntax, tests, PHPStan and style. Not run here; future authorized implementation environment only. Expected all stages exit 0. Does not automatically cover website syntax/static/style paths. |
| `rtk proxy php tools/lint.php website tests/Website` | Source-verified explicit-path syntax gate; run only after test directory exists. Expected exit 0. |

## T-001 / second-pass r1 — Preserve scientific exponents

Owner AI; risk low for a small extraction, medium if front-controller changes expand. Scope: `website/public/index.php`, new `website/ResultFormatter.php`, new `tests/Website/ResultFormatterTest.php`. Read-only context: `website/public/assets/site.js:51`, `tests/Facade/MathTest.php:16–38`, `phpunit.xml.dist`, `composer.json`. Do not edit parser, numeric evaluator, private packages, routing, dependencies or display precision.

Current behavior: `sprintf('%.14g', $result)` followed by whole-string `rtrim(..., '0')` at `website/public/index.php:28–32`; line 169 serializes the formatter output alongside the unchanged raw numeric result.

1. Establish the selected baseline using the command table and inspect scoped diff. Record PHP version and baseline failures. Inspect all `formatResult` references with `rtk proxy rg -n 'formatResult' website tests`.
2. Extract the existing function unchanged into `website/ResultFormatter.php` and require it from the controller. Keep the same function signature and current integer branch. The PHPUnit file should require only the formatter, not execute the front controller. Match strict types and data-provider conventions in `tests/Facade/MathTest.php`.
3. Add regression cases for positive and negative `1.23e20` and `1.23e-20`; controls `1.23e21`, `1.25`, `1.0`, integer zero and 42. Assert exact exponent preservation for those representative strings. For additional finite round-trip cases use relative error at most `1e-13` for nonzero values and exact zero handling; a fixed absolute delta alone would miss tiny-value magnitude corruption. Preserve the existing 14-significant-digit policy.
4. Run `rtk proxy php vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result --filter ResultFormatterTest`. Before the fix, require the four magnitude regressions to fail for the observed exponent loss, not loading errors. Fix by preserving the scientific exponent or trimming only the mantissa. Re-run: every case passes.
5. Check the caller still sends untouched `result` and uses the tested formatter for `display`. Run explicit website/test syntax lint, then `rtk proxy composer test` and `rtk proxy composer quality` in the authorized implementation environment. Review diff for only the three scoped files. Core gates alone do not prove website coverage.

Acceptance: four red-before/green-after regressions, all control cases pass, one production implementation, unchanged result/type/API and precision, explicit syntax and full quality gates pass. No browser/server needed for this unit fix. Stop if locale policy, integer semantics or routing must change, or existing dependency/tool failures prevent a trustworthy baseline. Keep exponent cases when changing formatting later.

## T-002 / second-pass r1 — Clarify the local contract copy

Owner Pair: human chooses disposition; AI edits only after that choice. Scope: `docs/V0.1-CONTRACT.md`, `docs/V0.1-TRACEABILITY.md`, `README.md`. Evidence-only: `CLAUDE.md:17–22`, `src/Parser/Lexer.php:215–254`, `tests/Parser/ParserTest.php:531–557`, `tests/Facade/MathTest.php:46–64`. Risk low for labeling; medium for a synchronized contract mirror. No source or instruction-file edits; no external repository edits.

1. Record the existing authority: `mathphp/mathphp-specs/V0.1-CONTRACT.md`. Record exact contradictions: ASCII-only versus allowlisted Unicode, `2pi` rejection versus implicit multiplication. Preserve ASCII identifier rules, explicitly normalized Greek aliases and byte spans; they are not general Unicode identifier support.
2. Human chooses: (A, recommended) clearly label the local contract as a non-authoritative legacy copy with later amendments and point to the existing specs authority; (B) maintain a versioned mirror, requiring the reviewed external spec revision/content first. STOP dependent editing until the choice is recorded. This is not a request to reopen the authority already documented.
3. For A, remove misleading current-authority framing without pretending the entire file is an untouched July snapshot. Preserve historical requirements and verification numbers. The traceability already says frozen/datetime; only add a navigation clarification if useful. For B, reconcile against the supplied authoritative revision and preserve/map IDs; do not infer requirements from implementation alone.
4. Run `rtk proxy rg -n 'Normative|ASCII|non-ASCII|2pi|implicit|frozen|specs' docs/V0.1-CONTRACT.md docs/V0.1-TRACEABILITY.md README.md`. Each remaining stale statement must be explicitly scoped as legacy, or corrected against supplied authority. Re-run the selected facade/documentation command for recorded behavior; note those tests do not automatically validate all prose. Inspect diff manually for unchanged historical counts/identity and no source changes.

Acceptance: recorded human choice, one stated authority, explicit status/revision of the local copy, stable requirement IDs and historical audit numbers preserved. Option B remains blocked without authoritative content. Stop if a language change is proposed, current specs contradict observed behavior, or reconciliation requires another repository. Update docs alongside future grammar additions rather than silently evolving the mirror.

## T-003 / second-pass r1 — Inventory release scope and compatible refs

Owner Pair; human chooses surface/refs, AI performs read-only inventory. Write a follow-up evidence document only in an explicitly approved report location. Evidence files: `private/mathphp-docs/RELEASING.md`, its `tools/check-ecosystem-locks.php`, `private/mathphp-visuals/composer.json`, `private/mathphp-explaining/composer.json`, package lock metadata and README files. Do not modify nested code/manifests/locks or access credentials. Risk low for inventory; high for unsupported version changes, which are outside this task.

1. Human chooses core-only, core+bundled demo, or coordinated add-on release. Recommend the core+demo validation milestone, leaving optional add-on work independent. Record exact intended tags/commits for included packages and whether the current Visuals checkout is deliberately historical.
2. Use `rtk proxy git -C private/mathphp-explaining status --short` and equivalent literal commands for `private/mathphp-visuals` and `private/mathphp-docs`; use `rev-parse HEAD` in each. Explaining was dirty in two ComposedElementary files at review. Stop release-snapshot claims until the owner supplies a stable intended state; do not revert or absorb these edits.
3. Record package name, declared version/constraints, current HEAD, dirty status, intended ref and verification state. Read only package names/versions/reference hashes from locks; never authentication/config secrets. Explain mismatch or assign the unresolved ref decision. Inventory completion does not require repairing anything.
4. For full ecosystem only: the checker requires four checkout paths and Units is absent. Keep the gate BLOCKED. Once a human supplies an authorized Units path and all refs are established, use the invocation in `private/mathphp-docs/RELEASING.md:25–30` with real verified paths. Do not run placeholders. Expected exit 0 and `ok -` lines, no `error -` lines. The script exits 2 for missing options and 1 for failed checks. No claim of successful checker execution is made here.
5. For an authorized ecosystem execution later, use core `rtk proxy composer quality`; from each add-on root use `rtk proxy composer validate --strict --check-lock`, `rtk proxy composer test`, `rtk proxy composer analyse` as defined in the checklist/manifests. Record runtime and output. Reference matching is not a substitute for constraint validation or tests. Publication remains a separate human release action.

Acceptance: every included package has intended and actual refs/status, mismatches explained, full-checker missing input recorded only for its relevant scope, and any repair proposed separately. Stop on missing access, required downloads, cross-project paths or unresolved dirty work. Do not create fake Units checkouts or bump Visuals to satisfy a number.

Next actions: T-001 may proceed in an implementation task; gather T-002 disposition and T-003 release-scope decisions independently. No new external skill is needed. Reusable review rule worth adding to an existing skill later: distinguish release-scope requirements from optional ecosystem gates, and never equate HEAD with a dirty checkout's executing source.

Closing drift check: Explaining advanced concurrently from `85567da10a0ed33ca5b5358a834799456a3a9988` to `97256333733c6230ac565f93ccdf5825fd74b75c` (commit title: “Map nonlinear reciprocal trig periodic branches”), and its worktree was clean at the final check. Earlier dirty-state observations above describe the initial snapshot, not the closing state. This review did not cause or review that commit. T-003 must recheck the new source and intended release refs; the initial snapshot is not current release evidence.
