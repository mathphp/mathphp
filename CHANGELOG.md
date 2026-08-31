# Changelog

All notable changes will be documented in this file. The project intends to
follow [Semantic Versioning](https://semver.org/) once it is release-ready.

## [Unreleased]

### Added

- Normative v0.1 scalar-expression language, numeric, security, and
  traceability contract.
- Documented the public-identity collision evidence and decision.
- MIT license.
- Bounded ASCII lexer with source-aware tokens and complete-input parsing.
- Final readonly AST and mathematically conventional operator precedence.
- Isolated per-call evaluator with exact integer overflow handling, finite-float
  enforcement, bounded powers, modulo, and factorial.
- Immutable variables, options, resource limits, function definitions, and
  function registry with ten allowlisted built-ins.
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

No version has been released.
