# Execution handoff

Planning only, 2026-09-19. Core baseline `e78b1ed`; docs `2548ad7`, Explaining `85567da`, Visuals `b3da73c`. Run commands from `/Users/mark/Projects/MathPHP` unless stated. Prefix commands with `rtk`. Do not implement anything merely by reading this handoff.

| ID | Title | Priority | Status | Executor | Effort | Dependencies |
|---|---|---|---|---|---|---|
| T-001 | Preserve scientific exponents in website results | P1 | TODO | AI | S | None |
| T-002 | Resolve current language-contract authority and drift | P2 | TODO | Pair | S | Maintainer decision within task |
| T-003 | Record intended local ecosystem compatibility | P2 | TODO | Pair | S | Intended refs from maintainer; full gate blocked on missing Units |

## T-001 — Preserve scientific exponents

Status TODO; executor AI; effort S; risk low with narrow scope. Related F-001, R-001, R-004. No prior plan found.

Why: `website/public/index.php:28` computes `sprintf('%.14g', $result)`, then line 31 applies `rtrim(rtrim($formatted, '0'), '.')` to the entire string. A decimal mantissa causes exponent zeros to be stripped. Preserve existing integer handling and 14-significant-digit display policy; do not change the evaluator API.

Scope: `website/public/index.php`, a small reusable formatter file under `website/` if necessary, and new `tests/Website/ResultFormatterTest.php`. Out: private packages, unrelated UI, endpoints, arithmetic algorithms, dependencies and deployment.

1. Run `rtk git diff e78b1ed -- website/public/index.php tests phpunit.xml.dist tools/lint.php`; inspect any drift before proceeding. Check dirty state and preserve user edits.
2. Add a test of the actual production formatting function. The front controller performs request dispatch: use a small extraction if needed rather than including the entire controller or copying its algorithm into the test. Follow strict types, PHPUnit `TestCase`, and data-provider structure in `tests/Facade/MathTest.php:16`. Existing `phpunit.xml.dist:19` discovers nested `*Test.php` files.
3. Cover `1.23e20`, `1.23e-20`, negative counterparts, a nonzero-final exponent, ordinary decimal, integral float, integer and zero. The first cases must fail on current behavior. Assert preserved exponent and numeric agreement within the existing display precision, rather than adding precision requirements.
4. Fix only formatting: preserve scientific notation or trim only its mantissa. Ensure every production caller uses the tested implementation.
5. Run `rtk composer test` (all tests pass), `rtk php tools/lint.php website tests/Website` (syntax OK), then `rtk composer quality` (exit 0). These are execution-time gates, not checks completed by this review. Composer quality regenerates autoload files; run only in an authorized implementation environment. No service or browser is necessary.

Acceptance: regression cases fail before and pass after the change; original evaluator result remains unchanged; all verification commands pass; diff contains only scoped changes. If dependencies are unavailable, report the blocker without installing automatically. Stop if source drift invalidates the formatter location, broader routing refactoring is needed, or verification fails beyond the narrow change. Preserve future exponent tests when changing display precision. Clean only execution-created disposable resources, retain test evidence.

## T-002 — Resolve language-contract authority

Status TODO; executor Pair; effort S; risk medium if semantics are changed. Related F-002, R-002. Existing work: `docs/V0.1-TRACEABILITY.md` and stable `LANG-003`, `LEX-002`, `LEX-006`, `SYN-001` IDs; this task reconciles those rather than inventing another normative spec.

Why: the local contract says all non-ASCII bytes and `2pi` are rejected while current lexer and tests support Unicode aliases and implicit multiplication. The historical traceability is dated July 30 and must remain historical evidence.

Scope: `docs/V0.1-CONTRACT.md`, `docs/V0.1-TRACEABILITY.md`, and README documentation pointers if needed. Read-only evidence from `src/Parser/Lexer.php`, `tests/Parser/LexerTest.php`, `tests/Facade/MathTest.php`. Do not edit instruction files, parser behavior, branding, external repositories, or historical test-result numbers.

1. Run `rtk git diff e78b1ed -- docs README.md src/Parser/Lexer.php tests/Facade/MathTest.php`; stop for unexplained drift.
2. Present the exact contradictory claims to the maintainer and choose either a current local contract or an explicitly historical local snapshot with a clear current-contract pointer. Investigation is ready; dependent document rewriting is BLOCKED until that choice is recorded.
3. For the chosen approach reconcile Unicode allowlisting, atomic identifiers, implicit multiplication and byte-span wording. Preserve dated records and requirement IDs; do not broaden the Unicode identifier grammar based on examples alone.
4. Read `rtk rg -n 'ASCII|non-ASCII|2pi|implicit' docs/V0.1-CONTRACT.md docs/V0.1-TRACEABILITY.md` and verify each claim is scoped to its intended version. Run `rtk composer test` to check the existing behavior examples; expected all pass. Record runtime and actual results, not the historical counts.

Acceptance: authority decision recorded, no current contract contradicts the sampled supported syntax, historical records labeled, identity decision retained, no source/instruction changes. Stop if reconciliation requires external specs edits, language changes, or missing authoritative material. No service required. Future grammar additions must update the chosen contract and corresponding tests together.

## T-003 — Inventory the intended ecosystem refs

Status TODO for investigation; executor Pair; effort S; risk low for inventory. Related F-003, R-003. Existing checklist: [RELEASING.md](../../private/mathphp-docs/RELEASING.md). No unconditional package upgrade is authorized.

Why: local Visuals declares `0.1.0` and core `^0.1`; local Explaining requires core and Visuals `^0.3`. These may be historical checkouts rather than the intended release inputs. Full ecosystem gate also expects Units, which is absent here.

Scope: read nested `composer.json`, lockfile package identities/revisions only, Git refs/status, and `private/mathphp-docs/tools/check-ecosystem-locks.php`; write findings only under this report directory. Do not read auth files, vendor internals, or private runtime data. Do not modify nested repos, constraints or locks; do not fetch/reset/tag/install/publish.

1. Recheck each repository with `rtk git -C <local-root> status --short --branch` and `rtk git -C <local-root> log -1 --oneline`, using `.`, `private/mathphp-docs`, `private/mathphp-visuals`, and `private/mathphp-explaining`. Compare with the baseline above; stop on unexpected source drift.
2. Record intended versus present commits and constraint ranges. Read lock metadata without printing credentials or repository auth configuration. Ask maintainer whether Visuals is intentionally historical and which refs are intended for the next compatibility check.
3. Inspect the existing checker and its documented four-checkout invocation in `private/mathphp-docs/RELEASING.md:22`. Do not invent missing Units or point at another project. Record the full gate as BLOCKED until required checkout/scope is provided. No compatibility repair is ready until intended refs are resolved.
4. Once separately authorized and all inputs exist, run the exact release checklist lock-check command with verified local paths; expect success only when locked dependencies match intended refs. Then package gates are `rtk composer validate --strict --check-lock`, `rtk composer test`, and `rtk composer analyse` from each add-on root; core uses `rtk composer quality`. Do not run these as a substitute for choosing the right checkout.

Acceptance for this investigation: every present repository has a recorded commit and purpose, mismatch is explained or assigned to a precise human decision, missing inputs are explicitly blocked, and any proposed repair is a separate approved task. Stop on credential needs, out-of-project access, required downloads or unclear ownership. Preserve all existing caches and checkouts. No build or disposable service is needed.
