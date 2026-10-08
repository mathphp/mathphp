# Findings and evidence

Review date: 2026-09-19. Baseline: core `e78b1ed`; nested revisions are in [README](README.md). Paths below are relative to the project root. These are three distinct findings, not a production-readiness assessment.

## F-001 — Scientific notation is altered by website formatting

- Category: correctness; priority **P1**; type: confirmed static defect.
- Evidence: `website/public/index.php:22` defines `formatResult`; line 28 uses `sprintf('%.14g', $result)` and lines 30–32 trim zeros from the entire formatted value when it contains a decimal point. Thus the formatted string `1.23e+20` becomes `1.23e+2`; negative exponents ending in zero are also affected.
- Impact: a mathematically correct evaluator result can be displayed at a radically wrong magnitude. This is a website formatting defect, not evidence of incorrect core evaluation.
- Confidence: high for the transformation; runtime/API reproduction not executed. Effort S; change risk low if isolated to formatting, medium if the front controller is broadly refactored.
- Recommended action: add direct formatter regression coverage and preserve the exponent verbatim. Existing default syntax/static/style paths exclude `website/` (`tools/lint.php:8`, `phpstan.neon.dist:4`, `phpcs.xml.dist:10`); establish a targeted formatter test in the existing PHPUnit suite rather than assuming the website is covered.

## F-002 — Local normative language claims contradict supported syntax

- Category: documentation/contract; priority **P2**; type: confirmed contract drift.
- Evidence: `docs/V0.1-CONTRACT.md:32` says ASCII-only, lines 54–55 reject all non-ASCII bytes, and line 95 says `2pi` is rejected. Yet its own grammar at line 139 includes implicit multiplication, `src/Parser/Lexer.php:215` implements Unicode tokens, and `tests/Facade/MathTest.php:46`–50 includes Unicode multiplication, square and root examples. `docs/V0.1-TRACEABILITY.md:21` still describes no implicit multiplication; its line 3 dates the record to July 30.
- Impact: maintainers and agents can enforce obsolete rejection rules or misread a historical audit as the current specification.
- Confidence high; effort S; risk low for clearly labeling historical records, medium for redefining the normative contract.
- Recommended action: decide which local document describes the current contract, preserve historical evidence, then reconcile supported syntax and traceability. Do not remove existing features or reopen the explicitly accepted name/namespace decision (`docs/V0.1-CONTRACT.md:5`). External specs were not read.

## F-003 — Nested add-on checkout is not a compatible declared release set

- Category: dependency/release reproducibility; priority **P2**; type: confirmed metadata mismatch, runtime compatibility unknown.
- Evidence: `private/mathphp-visuals/composer.json:3` declares version `0.1.0` and line 9 requires core `^0.1`; `private/mathphp-explaining/composer.json:8`–9 requires core and Visuals `^0.3`. `private/mathphp-docs/RELEASING.md:18`–36 already requires matching revisions and provides a local ecosystem lock checker.
- Impact: these local checkouts cannot simply be treated as a mutually compatible set of declared versions. Existing vendor autoloaders or remote packages may resolve differently; no installation failure is claimed. The ignored `private/` tree is outside core Git status (`.gitignore:7`).
- Confidence high for metadata; medium for practical effect; effort S investigation (repair unestimated); change risk low for read-only inventory, potentially high for blind version bumps.
- Recommended action: follow the existing release checklist to record intended package refs and determine whether this Visuals checkout is intentionally historical. Do not change constraints, fetch, reset, install, or publish merely to make this folder look current. Missing Units prevents running the documented four-checkout gate within this scope.

## Coverage and verification

Read: root instructions, README, manifest/scripts, CI, historical contract/traceability/identity decision; facade and evaluator sample; Unicode lexer implementation; facade and documentation tests; website README/front-controller sample; nested manifests, READMEs and release checklist. Root and immediate nested inventories checked instruction/plan locations; no additional instruction files appeared in the sampled nested roots. No existing task IDs were found; retain historical `LANG`, `LEX`, `SYN`, `REL` IDs when executing T-002.

Checks run: read-only `rtk git rev-parse --show-toplevel`, `rtk git status --short --branch`, `rtk git log -6 --format='%h %ad %s' --date=short`, tracked docs/website listing, and equivalent nested-root Git inspection via an `rtk proxy python3` wrapper. All completed successfully. Report verification checked existence, JSON parsing/counts, local Markdown links, and F-001 source anchors. No meaningful application verification ran.

Not run: PHPUnit, PHPStan, Composer validation/audit/quality, syntax lint, HTTP/browser checks, dependency resolution, remote CI or release inspection. No current vulnerability assertion. Excluded: vendors, generated output/cache contents, agent runtime data, unrelated projects, external specs/units/website repositories, and deep private analyzer review. No Eupry component was identified.

Considered and rejected: renaming MathPHP is contrary to the recorded identity decision; old verification records are valid historical evidence, not proof that current tests pass. No blanket refactor or numerical-accuracy claim is justified by this sample.
