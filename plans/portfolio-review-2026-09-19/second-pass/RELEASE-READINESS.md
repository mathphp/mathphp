# Release readiness

MathPHP is a dependency-free PHP scalar-expression library for application developers. `README.md` documents installation, `Math::evaluate()` calls, variables, notation and an observer extension point; `website/README.md:3–5` describes a companion demo using the same core. Optional private products add teaching steps and visual models. This is an implemented product, not a concept needing speculative features.

Maturity: **implemented, partially locally verified; release readiness unverified**. The selected 83 tests passed on PHP 8.5.5 and bundled website syntax passed. That does not establish numerical accuracy across the whole library, PHP-matrix success, distribution availability or production health.

| Surface | Implemented evidence | Unverified / missing evidence or behavior |
|---|---|---|
| Core user journey: integrate the library, evaluate an expression with variables, consume finite result or structured error | README examples; `src/Math.php`; facade/documentation tests pass locally | Current clean-consumer installation, full quality gate, remote PHP 8.2–8.5 matrix and release refs were not checked |
| Bundled demo: enter expression → evaluate API → read displayed result | `website/public/index.php:154–174`; JS renders `display` at line 51 | Correct magnitude formatting is actually missing for some scientific exponents (F-001); no HTTP/browser validation was run |
| Contract/navigation | Existing external specs authority in `CLAUDE.md:17–22`; documentation links in README | Local contract has contradictory current-sounding assertions; external spec contents not reviewed |
| Optional explanations/visuals | Nested package READMEs, manifests and website optional-package checks | Compatible selected refs, runtime integration and package gates unverified; Visuals metadata mismatch and dirty Explaining state require disposition |
| Full ecosystem release tooling | Manual checklist and local lock checker exist | Units checkout absent, so four-checkout gate cannot run in this scope; this is not missing core capability |

## Smallest useful milestone

Validate the existing public core plus bundled scalar demo after T-001. The key observable journey is a developer evaluating `2x + 1` with `x=4` and getting 9, then a demo user receiving the correct display magnitude for `1.23e20` and `1.23e-20`. Invalid expressions must retain the existing structured error response. Optional add-ons may remain unavailable as expressly supported by `website/README.md:17–22`.

No additional math function, account system, checkout, billing service or release automation is supported as a prerequisite by this review. The actual identified capability gap is reliable result presentation; broader release evidence is missing, not proven failing.

## Gates by release surface

- **Core package:** identify immutable intended source; execute full `composer quality` in authorized execution; obtain green configured PHP 8.2–8.5 CI including the explicitly required PHP 8.3 evidence (`CLAUDE.md:14–15`); verify package constraints and user installation separately; retain the existing identity decision. No current publication claim.
- **Bundled demo:** T-001 regressions and explicit website lint pass; verify expression/variables, structured errors and scientific display through the reviewed deployment surface in a later authorized smoke/browser task. No service is started by this review. Public-only optional-feature behavior must remain clear.
- **Documentation:** resolve local-copy disposition without creating a competing authority. Historical traceability remains historical.
- **Add-ons, only if included:** resolve intended refs and dirty ownership, validate constraints/locks and package tests, then execute the full ecosystem gate when its four inputs exist. The lock checker alone is insufficient.
- **Publication/deployment:** separate maintainer release approval and checklist. `private/mathphp-docs/RELEASING.md:55–72` describes a separate website release/deployment workflow. Do not assume those separate website health/smoke commands exist in this bundled site, or claim they have run.

## Human decision queue

| Decision | Options | Recommendation / effect |
|---|---|---|
| D-001 Release surface | Core only; core+bundled demo; coordinated optional ecosystem | Core+demo validation milestone first, or core only if demo is not shipping. Ecosystem mismatch need not delay core validation. Gates depend on the chosen surface. |
| D-002 Local contract disposition | Label legacy copy with authority pointer; versioned mirror of external specs | Label the local copy clearly and preserve its amended history. The external authority is already designated. Mirror work requires its exact reviewed revision first. |
| D-003 Optional release inputs | Treat old Visuals as historical; select intended compatible checkout refs | Maintainer specifies intended refs and resolves ownership of dirty Explaining work before compatibility repairs. Do not infer a version upgrade. Required only for included add-ons. |

## Proposed sequence, not delivery commitments

**One day:** re-establish scoped baseline and implement T-001 with failing-then-passing tests; choose D-001/D-002; gather full quality/CI evidence where access and authorization allow. If gates fail, diagnose before claiming readiness. T-002 labeling can proceed after its decision; T-003 can inventory without modifying packages.

**One week:** finish the chosen surface's full runtime/consumer validation and contract navigation; perform a separately authorized demo smoke check if shipping it. If optional products are included, obtain intended refs and required checkouts, then run their existing release checklist in dependency order: core, optional Units/Visuals, Explaining, docs/website. If decisions or inputs remain unavailable, keep those gates blocked rather than expanding implementation scope.

## Grounded overlap

T-002 overlaps existing normative specs and historical traceability; reuse IDs and authority rather than writing another specification. T-003 overlaps the existing docs release checklist and checker; do not build duplicate orchestration. The bundled site and separately named `mathphp-website` have overlapping documented purpose, but the latter's source is absent and was not reviewed: no consolidation recommendation is justified. Cross-project portfolio comparisons remain with the portfolio reviewer.

No disposable runtime resources were created; existing resources and unrelated edits were preserved. Next actions: execute revised T-001, then collect the surface and document decisions. Source evidence does not substitute for release or production evidence.

Closing drift check: Explaining advanced concurrently from `85567da10a0ed33ca5b5358a834799456a3a9988` to `97256333733c6230ac565f93ccdf5825fd74b75c` (commit title: “Map nonlinear reciprocal trig periodic branches”), and its worktree was clean at the final check. Earlier dirty-state observations above describe the initial snapshot, not the closing state. This review did not cause or review that commit. T-003 must recheck the new source and intended release refs; the initial snapshot is not current release evidence.
