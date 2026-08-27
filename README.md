# MathPHP

MathPHP is a small, dependency-free PHP library for safely evaluating scalar
mathematical expressions. It parses an explicit language into an immutable AST
and evaluates that AST; it never uses PHP `eval` or resolves expression names
to arbitrary PHP callables.

The v0.1 implementation targets PHP `^8.2` and is suitable for untrusted
expression text when its resource limits are kept at appropriate values.

> **Package identity:** this project intentionally retains the `MathPHP` name,
> `mathphp/mathphp` Composer coordinate, and `MathPHP\` namespace despite their
> confirmed brand and root-namespace collision with the established
> `markrogoyski/math-php` package. This is an explicit, documented decision and
> does not imply affiliation. See
> [the identity record](docs/IDENTITY-GATE.md).

## Installation

The package has not been published. For development in this checkout:

```console
composer install
composer quality
```

To use this checkout from another Composer project before publication, declare
it as a path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../MathPHP"
        }
    ],
    "require": {
        "mathphp/mathphp": "@dev"
    }
}
```

Publishing, tagging, pushing, and repository initialization are deliberately
outside the scope of this checkout and require separate approval.

## Quick start

```php
use MathPHP\Math;

Math::evaluate('2 + 3 * 4'); // 14
Math::evaluate('2^3^2'); // 512
Math::evaluate('-2^2'); // -4
Math::evaluate('sqrt(81) + 3!'); // 15.0

Math::evaluate(
    'gross * (1 - discount)',
    ['gross' => 125, 'discount' => 0.2],
); // 100.0
```

`Math::evaluate()` returns only a finite PHP `int` or `float`. Integer syntax
and exact integer arithmetic remain integers; decimal/scientific syntax and
floating-point operations remain floats. Division always returns a float.

## Website

The repository also includes a small companion site with an overview, reference
docs, and an interactive evaluator backed by this same package:

```console
php -S 127.0.0.1:8080 -t website/public
```

Open <http://127.0.0.1:8080/> after starting the local server.

## Optional private extensions

The core evaluator does not require teaching or presentation features. Two
optional private packages extend it without changing the public evaluator:

- `mathphp/mathphp-explaining` teaches the evaluation order with detailed,
  translatable steps and partial-result explanations.
- `mathphp/mathphp-visuals` turns formulas, plots, graphs, charts, and analysis
  data into renderer-neutral models with accessible SVG and image-ready data
  URIs.

The explaining package depends on the visuals package only when both layers
are needed; applications that only need charts can install the visuals package
directly.

## Supported public API

The supported v0.1 API consists of:

- `MathPHP\Math`;
- `MathPHP\Configuration\EvaluationOptions` and `ResourceLimits`;
- `MathPHP\Function\FunctionDefinition` and `FunctionRegistry`;
- `MathPHP\Exception\MathException` and its three phase subclasses; and
- `MathPHP\Source\SourceSpan`, returned by expression exceptions.

Classes and enums under `MathPHP\Ast`, `MathPHP\Parser`, and
`MathPHP\Evaluator` are implementation details rather than extension points.
The immutable options and registry are the supported way to customize an
evaluation.

The optional `Math::evaluateWithObserver()` method is the public instrumentation
seam for separately distributed tooling. The step-by-step explanation layer is
maintained as the private `mathphp/mathphp-explaining` package and is not part
of this free package or its release archive.

## Language

The complete grammar and numeric rules live in the
[normative v0.1 contract](docs/V0.1-CONTRACT.md). The supported surface is:

- integers, decimals, and scientific notation;
- ASCII variable names and the reserved constants `pi` and `e`;
- grouping with parentheses;
- binary `+`, `-`, `*`, `/`, `%`, and `^`;
- unary `+` and `-`;
- one postfix factorial per postfix expression; and
- calls to explicitly registered functions.

Exponentiation is right-associative and binds more tightly than a unary sign:

| Expression | Result |
|---|---:|
| `2^3^2` | `512` |
| `-2^2` | `-4` |
| `2^-2` | `0.25` |
| `(-2)^2` | `4` |
| `-3!` | `-6` |

The default function allowlist is:

| Function | Arity | Domain/result |
|---|---:|---|
| `abs(x)` | 1 | finite; preserves integer/float type |
| `sqrt(x)` | 1 | `x >= 0`; float |
| `sin(x)`, `cos(x)` | 1 | float |
| `exp(x)` | 1 | finite result; float |
| `ln(x)` | 1 | `x > 0`; float |
| `log(x, base)` | 2 | `x > 0`, `base > 0`, `base != 1`; float |
| `floor(x)`, `ceil(x)`, `round(x)` | 1 | float |

Names are case-sensitive. Function names are never looked up in PHP's global
function table.

## Variables and constants

Variables are a per-call map of ASCII identifier names to finite `int|float`
values:

```php
use MathPHP\Math;

$area = Math::evaluate('pi * radius^2', ['radius' => 3]);
// $area is approximately 28.274333882308138
```

`pi` and `e` are reserved and cannot be overridden. Numeric strings, booleans,
NaN, and Infinity are rejected rather than coerced.

## Resource limits

Every evaluation uses immutable limits. The defaults are:

| Safeguard | Default |
|---|---:|
| expression length | 4,096 bytes |
| non-EOF tokens | 1,024 |
| open parentheses/calls | 64 |
| AST depth | 128 |
| arguments per call | 16 |
| factorial operand | 20 on 64-bit PHP; 12 on 32-bit PHP |
| absolute exponent | 1,024 |

Use named arguments to lower or deliberately raise a limit:

```php
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Math;

$limits = new ResourceLimits(
    maxExpressionLength: 256,
    maxExponentMagnitude: 8,
);
$options = new EvaluationOptions(limits: $limits);

Math::evaluate('2^8', options: $options); // 256
```

Limit failures are source-aware exceptions with stable `limit.*` error codes.
Host applications should choose tighter limits when their input policy permits.

## Custom functions

Custom functions are explicit immutable definitions. Their closures are trusted
host code and must be pure, side-effect-free, and return a finite
`int|float`.

```php
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;

$triple = new FunctionDefinition(
    'triple',
    1,
    1,
    static fn (array $arguments): int|float => $arguments[0] * 3,
);

$functions = FunctionRegistry::defaults()->with($triple);
$options = new EvaluationOptions(functions: $functions);

Math::evaluate('triple(7)', options: $options); // 21
```

Built-ins and the constant names `pi` and `e` cannot be overridden. Registries,
options, and evaluation environments are isolated; a function or variable
provided to one call does not leak into another.

## Errors

All expression failures extend `MathPHP\Exception\MathException` and belong to
one phase:

- `LexicalException` for malformed numbers, unknown bytes, and lexer limits;
- `ParseException` for invalid syntax and parser limits; or
- `EvaluationException` for unknown names, domain errors, numeric failures, and
  evaluation limits.

Each exception exposes a stable machine-readable code and a zero-based,
end-exclusive byte span:

```php
use MathPHP\Exception\MathException;
use MathPHP\Math;

try {
    Math::evaluate('10 / 0');
} catch (MathException $error) {
    $error->errorCode(); // "eval.division_by_zero"
    $error->span()->start; // 3
    $error->span()->end; // 4
}
```

Invalid host API inputs, such as a non-numeric variable or invalid limit, throw
`InvalidArgumentException` because they have no expression source location.
The exhaustive code and span contract is in
[section 7 of the v0.1 contract](docs/V0.1-CONTRACT.md#7-errors-and-source-spans).

## Explicit limitations

v0.1 intentionally does not support:

- implicit multiplication;
- assignment, comparisons, or logical operators;
- symbolic algebra, simplification, or derivatives;
- units, currency, matrices, complex numbers, or formatting;
- arbitrary precision; or
- arbitrary PHP functions.

Inputs such as `2pi`, `2(3)`, `3!!`, `1 < 2`, and `sqrt 4` are rejected.
Integer overflow is an error rather than a silent promotion to a lossy float.

## Compatibility and verification

The supported Composer range is PHP `^8.2` (`>=8.2.0,<9.0.0`) with no runtime
dependencies. The full suite is verified locally on every installed supported
runtime:

| PHP family | Local runtime | Status |
|---|---:|---|
| 8.2 | 8.2.30 | verified |
| 8.3 | unavailable locally | remote CI required before release |
| 8.4 | 8.4.16 | verified |
| 8.5 | 8.5.5 | verified |

Run the local CI-equivalent aggregate with:

```console
composer quality
composer audit --locked
```

It covers strict manifest validation, optimized strict PSR-4 generation, syntax
linting, PHPUnit, PHPStan at maximum level against PHP 8.2, and PSR-12.
The requirement-to-source/test evidence and exact local verification record are
in [the v0.1 traceability audit](docs/V0.1-TRACEABILITY.md).

## Roadmap

- `0.1.0`: the secure scalar evaluator defined by the v0.1 contract.
- `0.2.0`: only features accepted through a separate contract; no deferred
  feature is implicitly promised.
- `1.0.0`: a stable API after real-world use and compatibility review.

The project follows [Semantic Versioning](https://semver.org/) for planned
releases and records notable changes in [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
