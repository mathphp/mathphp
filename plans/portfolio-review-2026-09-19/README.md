# MathPHP state assessment

Reviewed 2026-09-19. Scope: only `/Users/mark/Projects/MathPHP`, with a sampled core and bundled website review and metadata-only assessment of nested packages. No Eupry affiliation found in root Composer identity, `.git/config:9`, README ecosystem links, or nested package metadata. No external project was reviewed.

MathPHP serves PHP application developers who need bounded scalar expression evaluation; optional private packages support educational explanations and visual output. The bundled website demonstrates the evaluator.

Maturity: **implemented-unverified**. Source and substantial tests exist; no application tests, static analysis, or runtime checks were executed during this review. Historical successful checks are not current verification.

## Repository map

| Location | Role | Local commit | Working-tree signal before report |
|---|---|---|---|
| `.` | Public core plus tracked `website/` and historical `docs/` | `e78b1ed` | `main`, clean, 28 ahead of locally recorded origin/main |
| `private/mathphp-docs` | Separate user documentation repository | `2548ad7` | `main`, clean, 156 ahead |
| `private/mathphp-explaining` | Separate private explanation/analyzer repository | `85567da` | `main`, clean, 178 ahead |
| `private/mathphp-visuals` | Separate private visual models repository | `b3da73c` | `main`, clean, no ahead signal |

The actual core Git root is this project, not its parent workspace. Nested roots were individually confirmed. Upstream signals were not refreshed. Core activity sampled: six commits on September 15 adding roots, bracket/bar notation, modulo, and special-function derivatives. Nested docs/explaining latest activity is September 16; Visuals is August 27. Units and the separate specs/website repositories mentioned in documentation are not present as local repositories here and were not inspected.

Implemented evidence: `src/Math.php` builds a fresh lexer/parser/environment/evaluator per call; typed AST evaluation enforces finite results and supports observers. Tests cover facade results, Unicode aliases, configuration, parsing, fuzz/property cases, and documented examples. CI config targets PHP 8.2–8.5. This is configured coverage, not a verified green matrix.

Main blocker for trusting the bundled demo: a result formatter can corrupt scientific-notation exponents. Release readiness also remains unverified; local add-on metadata is not a coherent current ecosystem snapshot. No production-health claim is made.

- [Audit and evidence](AUDIT.md)
- [Requirements](REQUIREMENTS.md)
- [Execution tasks](TASKS.md)
- [Machine-readable summary](summary.json)

Smallest next action: execute T-001 by first adding a regression assertion that the website formatter preserves the exponent in `1.23e+20`, before changing formatting behavior.

Cleanup: bounded top-level inventories found existing `vendor/`, `.phpunit.cache/`, nested vendors and PHPUnit caches, and agent-state directories. Ownership/current use was uncertain; all were preserved and their contents excluded. No services, browsers, builds, temporary artifacts, or databases were created. Only these five planning files were written. No earlier local plans directory was present; historical traceability and the existing release checklist are linked instead of duplicated.

Limitations: short static sample, no exhaustive numeric algorithm or security audit, no remote Git/CI/package registry/deployment checks, no installs or test execution. Nested package source and private runtime data were excluded. Executor cleanup rule: preserve source and required evidence; remove only disposable resources created by that execution when no longer needed.
