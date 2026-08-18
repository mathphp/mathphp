# MathPHP Scalar Expression Evaluator

## Current status

This is an implemented v0.1 scalar-expression evaluator. Executable code and
tests remain the implementation truth; do not infer features beyond the
normative contract.

The user explicitly chose the `MathPHP` brand, `mathphp/mathphp` Composer
coordinate, and `MathPHP\` root namespace while accepting the confirmed
ecosystem collision documented in `docs/IDENTITY-GATE.md`. Do not silently
rename them.

The identity decision is resolved. Publication is permitted only after the
release checks documented below, including remote PHP 8.3 coverage.

## Normative contract

`docs/V0.1-CONTRACT.md` defines the required v0.1 language, numeric behavior,
error model, limits, isolation guarantees, implementation order, and
traceability IDs. Change that contract deliberately before implementing
different behavior.

Key grammar outcomes include:

- `2^3^2 = 512`
- `-2^2 = -4`
- `2^-2 = 0.25`
- `(-2)^2 = 4`

## Implemented architecture

1. Source spans, token types, and a bounded ASCII lexer.
2. Final readonly typed AST nodes.
3. A full-consuming recursive-descent parser.
4. Per-call environment and numeric evaluator.
5. Immutable function registry, constants, and allowlisted built-ins.
6. `MathPHP\Math::evaluate()` plus immutable options and limits.
7. Layered PHPUnit, deterministic property, malformed-fuzz, and documentation
   example tests.
8. Composer validation, strict autoload checks, syntax lint, PHPUnit, PHPStan
   at maximum level, and PSR-12 checks.

## Non-negotiable constraints

- PHP `^8.2` (`>=8.2.0,<9.0.0`).
- No runtime dependency unless it is technically necessary and justified.
- Never use `eval`.
- Never turn an expression identifier into an arbitrary PHP callable.
- No static mutable environment, evaluator, variables, functions, or limits.
- Every source error has a zero-based, end-exclusive byte span.
- Full input must be consumed.
- NaN and Infinity are never valid inputs or public results.
- All resource limits are tested at the boundary and one step beyond it.
- Deferred features remain deferred unless separately accepted.
