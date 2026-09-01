# Changelog

All notable changes will be documented in this file. The project follows
[Semantic Versioning](https://semver.org/).

## [0.3.5] - 2026-09-01

### Added

- Added stable numerical primitives `log2`, `log1p`, and `expm1`.
- Added two-argument `atan2` with an explicit origin-domain error.
- Added overflow-safe integer `gcd` and `lcm` functions.

## [0.3.4] - 2026-09-01

### Added

- Added portable aliases for common Greek variable notation (`α`, `β`, `γ`,
  `δ`, `θ`, `λ`, `μ`, `σ`, `ω`, and the remaining standard Greek letters).
  Aliases preserve source spans while resolving to case-sensitive ASCII names.

## [0.3.3] - 2026-09-01

### Added

- Added real cube roots (`cbrt`) and reciprocal trigonometric functions
  (`sec`, `csc`, and `cot`) with explicit pole handling.

## [0.3.1] - 2026-09-01

### Added

- Common Unicode notation aliases: `×`, `÷`, `−`, `π`, `τ`, and `φ`.
- Superscript powers (`x²`, `x³`, and other single-digit exponents) and the
  square-root prefix `√x`.
- Superscript powers (`x²`, `x³`, and other single-digit exponents) and the
  square-root prefix `√x`.

## [0.3.0] - 2026-09-01

### Added

- Standard implicit multiplication for adjacent factors such as `2x`,
  `2(x + 1)`, `(x + 1)(x - 1)`, and whitespace-separated factors.
- Explicit identifier semantics: names such as `xy` remain one variable,
  while `x y` is parsed as a product.

## [0.2.0] - 2026-09-01

### Added

- Expanded the safe built-in function allowlist with tangent and inverse
  trigonometric functions, hyperbolic functions, `log10`, `hypot`, `sign`, and
  bounded `min`/`max` aggregates.
- Added the reserved mathematical constants `tau` and `phi`.
- Added domain checks for all new functions so invalid values remain structured
  evaluation errors rather than non-finite results.

## [Unreleased]

### Added

- Standard implicit multiplication in the Core grammar (`2x`, `2(x + 1)`,
  adjacent groupings, and whitespace-separated factors), while preserving
  atomic identifier names such as `xy`.
- Normative v0.1 scalar-expression language, numeric, security, and
  traceability contract.
- Documented the public-identity collision evidence and decision.
- MIT license.
- Bounded ASCII lexer with source-aware tokens and complete-input parsing.
- Final readonly AST and mathematically conventional operator precedence.
- Isolated per-call evaluator with exact integer overflow handling, finite-float
  enforcement, bounded powers, modulo, and factorial.
- Immutable variables, options, resource limits, function definitions, and
  function registry with the allowlisted built-in catalogue.
- Source-aware lexical, parse, and evaluation exceptions with stable error
  codes.
- PHPUnit layer, deterministic property cases, malformed-input fuzz cases,
  strict PHPStan, PSR-12, syntax, Composer, and autoload checks.
- Executable public API examples, limitations, compatibility notes, and local
  path-repository installation guidance.

### Changed

- Replaced fictional broad-feature and architecture claims with the narrow
  evaluator's actual implementation and verified API.
- Retained the `MathPHP` product name, `mathphp/mathphp` Composer coordinate,
  and `MathPHP\` root namespace by explicit user decision, accepting the
  documented ecosystem collision risk.
- Made built-in function provenance immutable registry-owned state so custom
  definitions cannot opt into built-in error semantics.
- Extended malformed-number spans through a sign immediately following a
  repeated exponent marker.

### Release blockers

- The package must not be published until the coordinated ecosystem release
  checks pass and publication is separately approved.
- The repository is prepared for annotated SemVer tags, but no release tag or
  package publication has been created yet.
- Optional private packages remain separately distributed; their access and
  licensing workflow is intentionally manual and is not automated here.

Core `v0.1.0` is released; `v0.2.0` adds the expanded mathematical function
catalogue described above.
