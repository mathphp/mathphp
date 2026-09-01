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

## Development

The public core is the only package in this repository. The optional explaining,
visuals, and units packages are distributed separately and loaded by
applications that need them.
