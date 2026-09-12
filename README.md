# MathPHP

MathPHP is a small, dependency-free PHP library for safely evaluating scalar
mathematical expressions. It targets PHP `^8.2`, uses an immutable AST, and
never evaluates input with PHP `eval()`. Continuous integration verifies PHP
8.2 through 8.5.

## Installation

```console
composer require mathphp/mathphp:^0.3
```

For development in this checkout:

```console
composer install
composer quality
```

## Quick start

```php
use MathPHP\Math;

Math::evaluate('2 + 3 * 4'); // 14
Math::evaluate('2^3^2'); // 512
Math::evaluate('-2^2'); // -4
Math::evaluate('sqrt(81) + 3!'); // 15.0
Math::evaluate('binomial(10, 3) + permutation(4, 2)'); // 132
Math::evaluate('2x + 1', ['x' => 4]); // 9
Math::evaluate('2(3 + 4)'); // 14
Math::evaluate(
    'gross * (1 - discount)',
    ['gross' => 125, 'discount' => 0.2],
); // 100.0
```

Extensions can observe the same post-order evaluation without changing the
scalar result. Pass an observer directly when the expression has no variables,
or keep the array-first form when it does:

```php
$result = Math::evaluateWithObserver('2 * (3 + 4)', $observer);
$result = Math::evaluateWithObserver('gross * tax', ['gross' => 42, 'tax' => 0.2], $observer);
```

## Ecosystem

- Website and interactive evaluator: https://mathphp.diderichsen.com
- User documentation: https://github.com/mathphp/mathphp-docs
- Normative specifications: https://github.com/mathphp/mathphp-specs
- Private step-by-step explanations: https://github.com/mathphp/mathphp-explaining
- Private visual models and renderers: https://github.com/mathphp/mathphp-visuals
- Private unit-aware quantities: https://github.com/mathphp/mathphp-units

The complete API, grammar, examples, and integration guidance live in the
[documentation repository](https://github.com/mathphp/mathphp-docs). The
language contract and traceability records live in the
[specifications repository](https://github.com/mathphp/mathphp-specs).

The expression grammar accepts explicit multiplication (`2 * x`), common
Unicode notation (`×`, `÷`, `−`, `√`, `π`, `τ`, `φ`, and superscript powers
such as `x²`), and the standard implicit forms (`2x`, `2(x + 1)`, and
`(x + 1)(x - 1)`). Identifiers remain atomic, so
`xy` is one variable name; write `x y` or `x * y` when two factors are
intended.

The built-in function registry includes elementary, trigonometric (including
`sec`, `csc`, `cot`, `asec`, `acsc`, and `acot`), hyperbolic (including
`sech`, `csch`, and `coth`), numerical,
integer, combinatorial, and special functions such as `erf`, `erfc`, `erfi`, `erfinv`, `erfcinv`, `airyai`, `airybi`, `ellipk`, `ellipe`, `ellipf`, `ellipeinc`, `ellippi`, `ellippinc`, `gamma`,
`lgamma`, `gammainc`, `gammaincc`, `gammaincinv`, `gammainccinv`, `betainc`, `betaincc`, `betaincinv`, `betainccinv`, `beta`, `logbeta`, `zeta`, `digamma`, `trigamma`, `besselj`, and
`besseli`, plus `polygamma`, integer-order `bessely`/`besselk`, real Lambert-W
`lambertw`, and inverse hyperbolic functions
`asech`, `acsch`, and `acoth`.
The real zeta implementation requires an argument greater than
one; digamma and trigamma reject gamma poles. Beta arguments are
finite and positive; Bessel orders are currently finite
integers, with real-valued arguments and domain-checked finite results.
Combinatorial functions include exact integer-domain
`factorial`, `binomial`/`choose`/`ncr`, and `permutation`/`permute`/`npr`.
Special-function results are finite and domain-checked; expressions that
approach poles or overflow return structured evaluation errors.
`polygamma(m, x)` accepts integer orders from 0 through 32 and evaluates the
real non-pole branch with convergent asymptotics.
`bessely(n, x)` and `besselk(n, x)` accept finite integer orders and positive
real arguments; singular zero arguments are rejected explicitly.
`sinc(x)` and `sinhc(x)` use their continuous value of `1` at the origin.
`gammainc(a, x)` and `gammaincc(a, x)` are normalized lower and upper
incomplete gamma functions, so their values sum to `1` for valid inputs.
`gammaincinv(a, p)` and `gammainccinv(a, q)` invert those functions on finite
quantiles, with `p` in `[0, 1)` and `q` in `(0, 1]` because the opposite tail
endpoints are infinite.
`erfinv(x)` and `erfcinv(x)` provide the real inverse error-function branches
for `-1 < x < 1` and `0 < x < 2`, respectively.
`betainc(a, b, x)` and `betaincc(a, b, x)` are normalized lower and upper
incomplete beta functions on `a > 0`, `b > 0`, and `0 ≤ x ≤ 1`; their values
sum to `1` for valid inputs.
`betaincinv(a, b, p)` and `betainccinv(a, b, q)` invert those normalized
functions for probabilities in `[0, 1]`, returning the corresponding `x` in
`[0, 1]`.
`airyai(x)` and `airybi(x)` use finite power-series and asymptotic evaluation
with explicit overflow errors for values outside the floating-point range.
`ellipk(m)` and `ellipe(m)` use the complete real elliptic integrals with
parameter `m ≤ 1`; `ellipk(1)` is explicitly divergent.
`ellipf(phi, m)`/`ellipkinc(phi, m)` and `ellipeinc(phi, m)` provide incomplete
forms on the same real parameter domain and reject singular endpoints.
`ellippi(n, m)` and `ellippinc(phi, n, m)` provide the real third-kind
elliptic integral for `n < 1`; complete `Pi` requires `m < 1`, while the
incomplete form rejects paths through the `m = 1` singular endpoint.
`lambertw(z)` evaluates the principal real branch, while `lambertw(z, -1)`
selects the lower real branch on `-1/e <= z < 0`.

## Development

The public core is the only package in this repository. The optional explaining,
visuals, and units packages are distributed separately and loaded by
applications that need them.
