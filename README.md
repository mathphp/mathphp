![MathPHP — Mathematics for PHP, made predictable.](.github/assets/readme-banner.png)

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
Math::evaluate('⁴√16'); // 2.0
Math::evaluate('root(-32, 5)'); // -2.0
Math::evaluate('(-8)^(1/3)'); // -2.0
Math::evaluate('2x + 1', ['x' => 4]); // 9
Math::evaluate('2(3 + 4)'); // 14
Math::evaluate('binomial(52, 5)'); // 2598960
Math::evaluate('permutation(10, 3)'); // 720
Math::evaluate('gammainc(1, 2)'); // 1 - exp(-2), regularized lower incomplete gamma
Math::evaluate('betainc(1, 1, 0.4)'); // 0.4, regularized incomplete beta
Math::evaluate('betaincc(1, 1, 0.4)'); // 0.6, regularized complementary incomplete beta
Math::evaluate('betaincinv(2, 3, 0.4)'); // inverse regularized incomplete beta
Math::evaluate('betainccinv(2, 3, 0.4)'); // inverse complementary regularized incomplete beta
Math::evaluate('gammaincinv(2, 0.5)'); // inverse regularized lower incomplete gamma
Math::evaluate('gammainccinv(2, 0.5)'); // inverse regularized upper incomplete gamma
Math::evaluate(
    'gross * (1 - discount)',
    ['gross' => 125, 'discount' => 0.2],
); // 100.0
```

Polynomial equations up to degree 256 use recursive derivative partitioning
and bounded numerical real-root isolation. Variable-independent built-in
function expressions can be evaluated as polynomial coefficients when they
produce finite values (for example, `x + sin(1) = 0`). Other equations use a bounded
numerical search over a finite interval. Exact rational-function equations
also accept one or both infinite interval ends; a Cauchy search bound computed
from the exact numerator coefficients reduces them to a finite search. Direct
trigonometric equations with polynomial phases and constant targets are also
classified on unbounded intervals: unattainable targets return no roots, while
attainable targets with nonconstant phases report infinitely many roots. Equal
calls to injective functions can also reduce unbounded equations to supported
argument equations, including `exp(x^2) = exp(x + 1)`.
A nonzero rational-function amplitude multiplying `sin(P(x))` or `cos(P(x))`,
where `P` is an exact rational function whose numerator degree exceeds its
denominator degree, forces infinitely many roots on an unbounded interval
when every other additive term has strictly lower rational growth degree than
the amplitude. The amplitude may grow, approach a nonzero limit, or decay.
Same-degree rational offsets are combined exactly and supported when their net
leading-coefficient ratio stays strictly inside the sinusoid's amplitude
envelope. When all remaining terms are rational, a combined rational target
approaching either range boundary from inside on an unbounded tail also gives
infinitely many roots. A remainder identically equal to either signed
amplitude is also classified, including when it is combined with other
rational terms, since each matching extremum is a root.
Rational amplitudes multiplying `tan(P(x))` or `cot(P(x))` also
yield infinitely many roots against additive terms that
remain continuous on the unbounded tail: every sufficiently distant pole
branch crosses the continuous remainder. For `sec(P(x))` and `csc(P(x))`, a
same-order rational offset whose exact leading-coefficient ratio has magnitude
greater than one forces crossings on infinitely many branches when all other
terms have lower rational growth degree. At the exact unit boundary, an
all-rational remainder identically equal to either signed amplitude is
classified, including when it combines multiple rational terms, because the
secant or cosecant reaches that extremum infinitely often.
A combined rational target approaching either signed range boundary from
outside on an unbounded tail also crosses infinitely many alternating-sign
secant or cosecant branches.
Globally valid real inverse compositions also reduce before interval solving:
`ln(exp(U))`, `log1p(expm1(U))`, `asinh(sinh(U))`, `atanh(tanh(U))`,
`sinh(asinh(U))`, `tan(atan(U))`, `tan(arctan(U))`, `erfinv(erf(U))`, and
`erfcinv(erfc(U))` reduce to `U`, including when nested inside supported
arithmetic expressions when interval ranges keep the forward inputs finite and
inside the inverse functions' domains. For nonzero rational-function `U`,
`acsch(csch(U))` and `acoth(coth(U))` use the same reduction. The original
equation is checked at each transformed candidate to preserve excluded points
such as `U = 0`. Reverse compositions `exp(ln(U))` and `expm1(log1p(U))`
are reduced only when interval-range analysis proves `U > 0` or `U > -1`,
respectively, throughout the requested interval. Likewise,
`exp(log1p(U))` reduces to `1 + U`, and `expm1(ln(1 + U))` reduces to `U`,
when certified interval analysis proves `U > -1` throughout the search range.
The equivalent `ln(1 + expm1(U))` and `log(1 + expm1(U), e)` forms reduce to
`U`, recognizing either term order.
`log1p(exp(U) - 1)` also reduces to `U` for the exact
exponential-minus-one form, including when nested inside supported arithmetic.
The nested composition `log1p(ln(U)) = t` reduces for every finite real target
to `U = exp(expm1(t))`.
`sin(asin(U))`, `cos(acos(U))`, and `tanh(atanh(U))` reduce only when certified
ranges prove their inverse-function domains. The same applies to
`cosh(acosh(U))`, `sech(asech(U))`, `csch(acsch(U))`, and `coth(acoth(U))`,
with ranges restricted to their respective real domains. Reverse error-function
compositions `erf(erfinv(U))` and `erfc(erfcinv(U))` also reduce when interval
analysis proves `U` stays strictly inside `(-1, 1)` or `(0, 2)`. The reverse
trigonometric compositions `asin(sin(U))`, `acos(cos(U))`, and `atan(tan(U))`
reduce only when certified ranges stay inside `[-pi/2, pi/2]`, `[0, pi]`, and
`(-pi/2, pi/2)`, respectively. Reciprocal inverse-trig compositions
`asec(sec(U))`, `acsc(csc(U))`, and `acot(cot(U))` require certified principal
intervals that exclude the `sec`/`csc` poles and inverse branch gaps. The
`lambertw(U*exp(U))` also reduces to `U` on branch 0 for `U >= -1` and branch
-1 for `U <= -1`, subject to certified interval and floating-point bounds. For positive constant bases
`b, c != 1`, logarithmic compositions transform as follows:
`log(exp(U), b) = V` to `U = V ln(b)` and `log(b^U, c) = V` to
`U ln(b) = V ln(c)`; these reductions also apply when the logarithm occurs
inside a supported arithmetic expression, as do `ln(a^U)`, `log2(2^U)`,
`log10(10^U)`, and `log2(exp(U))`. The global
compositions `acosh(cosh(U))` and `asech(sech(U))` reduce to `abs(U)`.
Exponential sums with affine or polynomial phases that are real multiples of
one shared phase are also supported on unbounded intervals. Extreme-slope
dominance bounds limit the phase search, and polynomial root bounds retain all
real preimages. Supported unbounded `hypot()` equations reduce to sums of
squares; every returned candidate is checked against the original equation
to reject roots introduced by squaring.
Some unbounded sums with unrelated polynomial phases are also classified
root-free when lower bounds from positive constant and convex polynomial-phase
exponentials dominate all constant negative terms. The solver uses
`exp(t) >= 1 + t` to combine minima at different points for convex quadratics
and translated even-degree monomials. Quartics with other phases also use a
conservative quadratic lower bound when their cubic term can be absorbed by
positive quadratic curvature.
Unbounded absolute-value equations with polynomial or rational-function
arguments and targets reduce to both signed branches; identity branches are
classified over the target's nonnegative intervals, and candidates are checked
in the original equation so denominator holes remain excluded.
Globally bounded real-function ranges also restrict polynomial targets to a
finite search interval, including `atan(x) = x`, `acos(x) = x`,
`tanh(x) = x`, `erf(x) = x`, and `gammainc(2, x) = x`; the original function
domains are still checked at every candidate. For direct trigonometric
equations with phase `a*exp(P(x)) + b`, where `a` is nonzero and `P` is a
nonconstant polynomial, unbounded phase tails are classified as infinite root sets when the target is
attainable. When `P` tends to negative infinity on the unbounded ends, direct
`sin`, `cos`, `tan`, `cot`,
`sec`, and `csc` equations enumerate the finitely many inverse phase levels
and solve their polynomial preimages, including tangencies at extrema.
Unbounded equations that apply `floor`, `ceil`, `round`, or `trunc` to a
polynomial or rational-function argument and compare it with a constant
integer target classify interval solution regions as infinite and retain
isolated transition-level roots.
Same-argument `floor(g) + ceil(g)` equations also classify their unbounded
interval bands and isolate the integer levels that yield point solutions.
Repeated same-argument quantizer terms with constant coefficients reduce to
one integer level before solving, including `round` and `trunc` terms.
Reflected quantizer pairs such as `round(g) + round(-g)` use their exact sign
symmetries to classify identities, integer levels, and unbounded solution bands.
Nested `min`/`max` and absolute-value branches are expanded for unbounded scalar
equations; candidates are checked against the original expression, and constant
active branches are partitioned at their switch points to detect solution intervals.
Supported invertible-function equations with constant targets are reduced to
their inverse level equations and use the existing unbounded polynomial solver.
Regularized gamma and beta equations with fixed positive shape parameters use
their inverse level functions before solving the argument equation. Unbounded
`erfi`, `erfcx`, Dawson, and fixed-integer-order modified Bessel-I equations
also reduce through their inverse levels.
Inverse error, regularized gamma, and regularized beta equations also support
decaying rational sinusoidal inputs of the form `P(x)*sin(Q(x))/D(x)` or
`P(x)*cos(Q(x))/D(x)`, where `P` and `D` are polynomials, `Q` is a nonconstant
polynomial, and `degree(P) < degree(D)`. Polynomial targets are handled by
forward-function reduction and finite-range isolation; growing positive
targets for `erfcinv` and `gammainccinv` are classified as having infinitely
many roots on an applicable unbounded tail.
Equations of the form `exp(a*x+b) = c*x+d` are solved on unbounded intervals
through the real Lambert W branches, including the coalesced branch point.
Equations `ln(a*x+b) = d + c/(a*x+b)` (in either side order) reduce to the
real Lambert W branches when `a` is nonzero, retaining only roots where the
logarithm argument is positive and inside the requested interval.
For affine logarithm arguments, unbounded equations against rational targets
are also isolated when their numerator and denominator degrees are at most 16
and at most half `SolverOptions::maxPolynomialDegree`. Decreasing arguments
are handled by reflecting the variable. Target poles are excluded and split
the domain, and derivative critical points partition each component into
monotone intervals; the rational target's leading terms determine the
unbounded-tail limit.
Commensurate sums of affine exponentials are reduced to a polynomial in
`exp(base*x)` and isolated over its positive real roots.
On unbounded intervals, bounded arithmetic expressions built from `sin` and
`cos`, supported integer powers, nonnegative-base real powers, and
domain-certified functions including `sqrt`, `cbrt`, `root`, `abs`, `exp`,
`expm1`, `ln`, `log2`, `log10`, `cosh`, inverse sine/cosine, `erf`/`erfc`,
`sech`, `sinc`, and `sincpi` can be compared with a nonconstant polynomial
target. Bounded `tan`, `cot`, `sec`, and `csc` compositions use interval bounds
only when their argument ranges exclude the corresponding poles or zeros.
`csch` and `coth` are bounded only when their argument range excludes zero;
`sign`, `min`, `max`, and `hypot` use operand interval bounds, and division is
bounded only when its denominator range excludes zero. Two-argument `log`
uses interval bounds only when the value range stays positive and the base
range stays strictly on one side of 1. Two-argument `atan2` bounds continuous
argument rectangles from their corners and axis extrema, and falls back to its
global principal-angle range `[-pi, pi]` when a rectangle contains the origin
or crosses the negative-x branch cut.
Conservative interval bounds restrict the search to finite polynomial-feasible
intervals; single scaled trigonometric terms with supported polynomial phases
and polynomial offsets also use this reduction.
Nonconstant polynomial targets against nonconstant affine phases of `tan`,
`cot`, `sec`, or `csc` have infinitely many roots on a half-line and are
classified accordingly.
Unbounded zero-level equations for `besselj` and `bessely` are restricted to
their finite evaluator argument domains and solved over every resulting
polynomial-phase interval. Constant phases are classified separately.
Root isolation for rational equations remains numerical, and original
denominator holes stay excluded:

```php
use MathPHP\Math;
use MathPHP\Solver\SolverOptions;

$roots = Math::solve('x^2 = 2', 'x', 0.0, 2.0);
$roots[0]->value; // approximately 1.4142135624

$allRealRoots = Math::solve('x^3 = x', 'x', -\INF, \INF);
array_map(static fn ($root) => $root->value, $allRealRoots); // [-1.0, 0.0, 1.0]

$sharedRoots = Math::solve('x^2 = 1 = 2*x - 1', 'x', -2.0, 2.0);
array_map(static fn ($root) => $root->value, $sharedRoots); // [1.0]

$rootCount = Math::countPolynomialRootsInInterval('x^3 - x = 0', 'x', -2.0, 2.0);
// 3 distinct real roots strictly inside the interval

$allRealRootCount = Math::countPolynomialRootsOnRealLine('x^3 - x = 0', 'x');
// 3 distinct real roots over the entire real line

$allRealRootIntervals = Math::isolatePolynomialRootsOnRealLine('x^2 - 2 = 0', 'x');
// Two disjoint rational intervals, each certified to contain one real root

$rationalRootCount = Math::countPolynomialRootsInRationalInterval(
    '3*x - 1 = 0',
    'x',
    '0',
    '1/2',
); // 1; rational endpoints need not be rounded to floats

$rootIntervals = Math::isolatePolynomialRootsInRationalInterval(
    'x^3 - x = 0',
    'x',
    '-2',
    '2',
);
// Each interval is certified to contain one distinct root and is at most 1/1000000 wide.
// Pass maximumWidth: '1/1000' to request wider brackets.
```

Equal finite bounds are accepted as a singleton interval: the equation is
evaluated at that point and returns either that point as a root or an empty
list if it is undefined there or its residual exceeds the configured tolerance.

Exact rational root counting and isolation support polynomial and rational-
function equations with integer powers through
`SolverOptions::maxPolynomialDegree` (default 256, configurable up to 256), subject to bounded
integer-arithmetic and subdivision limits. Coefficients and rational-bound
components are limited to 4096 decimal digits; increasing
`ResourceLimits::maxExpressionLength` does not raise that arithmetic limit.
Unsupported or over-budget cases return `null` rather than an uncertified result. The
`Math::countPolynomialRootsOnRealLine()` API uses exact Sturm signs at negative
and positive infinity to count every distinct real root without requiring
finite search bounds. `Math::isolatePolynomialRootsOnRealLine()` returns
disjoint rational intervals for those roots, using an exact integer Cauchy
bound; each interval contains exactly one root. It accepts `maxDepth` and
`maximumWidth` limits and returns `null` if certification exceeds them. Both
all-real APIs and the finite-interval count/isolation APIs accept supported
rational-function equations formed from exact polynomial arithmetic and
integer powers; denominator zeros are removed even when numerator factors
cancel algebraically. They also accept chained equalities such as
`x^2 = 1 = 2*x - 1` as intersections of adjacent equations when every link
is a supported rational-polynomial expression; poles from every link are
excluded. These APIs also isolate affine-variable equations
equating sums of constant rational square roots, including rational
coefficients and offsets, directly from exact rational enclosures. Radicands
in the same rational square class are combined before constructing the
enclosure, whose precision scales to the requested maximum width within the
exact-arithmetic digit limit. If that limit is exceeded, or finite bounds
still intersect the refined enclosure, a bounded resultant fallback handles
up to five distinct square classes and filters candidates against the exact
signed-radical branch. Even polynomials and odd polynomials of the form
`x*q(x^2)` are isolated through the reduced polynomial in `x^2`, with
certified rational square-root bounds for positive and negative roots.
All-real counting and isolation also support `exp(P(x)) = q` and
`expm1(P(x)) = q` for rational-polynomial phases, including non-monotone
phases, when critical values and tail bounds can be certified within the
configured arithmetic and depth limits. Unit levels reduce directly to exact
polynomial roots, including repeated and tangential roots.
Polynomials that become even or odd after a rational translation are handled
by translating their certified intervals back to the original variable.
Equations that isolate one odd integer root of a polynomial or rational
function at a constant level are reduced exactly through root degrees up to
`SolverOptions::maxPolynomialDegree`, provided the resulting polynomial and
exact arithmetic stay within their configured limits. Matching and nested odd
root chains also accept degrees through that configured bound, with chain
products checked for integer overflow.
Finite-interval exact root counting and isolation also support equations with
one additive `sqrt(P(x))` or `abs(P(x))` term multiplied or divided by a
rational function, or multiple additive terms of the same radical kind and
identical polynomial radicand with rational constant coefficients, as well as
two `sqrt(P(x))`/`abs(P(x))` terms with distinct polynomial radicands and
rational constant coefficients, including opposite-sign radical terms, as well
as three additive `sqrt(P(x))`/`abs(P(x))` terms with rational constant
coefficients when one term is on one side and two are on the other. Those
three-term candidates are filtered using the original radical domains and the
certified sign branch after squaring. Up to eight additive `abs(P(x))` terms
are also supported, with otherwise rational-polynomial terms. Candidates are
filtered against the original
nonnegative branches; roots where the radical coefficient vanishes are
isolated separately, and infinite solution sets are reported explicitly when
sign-cell identities contain an open interval. Roots at poles of the radical
coefficient are excluded from the certified result.
Sums of any number of same-sign nonnegative radical terms set to zero are
reduced to common zeros of their polynomial or rational-function radicands,
excluding denominator holes; identically zero sums are reported as infinite
solution sets.
Finite-interval root counting also certifies `exp(a*x+b) = q`,
`expm1(a*x+b) = q`, `ln(a*x+b) = q`, `log1p(a*x+b) = q`, and affine
hyperbolic equations for `sinh`, `cosh`, `tanh`, `sech`, `csch`, `coth`,
`asinh`, `acosh`, `atanh`, `asech`, `acsch`, and `acoth` with rational
coefficients and targets, excluding roots exactly on either interval endpoint.
Equations for affine `sin`, `cos`, `tan`, `cot`, `sec`, and `csc` arguments with
rational targets are counted and isolated using certified inverse-trigonometric
and pi enclosures; sine and cosine targets outside `[-1, 1]` have no roots,
while secant and cosecant reduce through their reciprocal levels. Zero-level
equations for affine `sinc` and `sincpi` arguments are also supported using a
certified rational enclosure of pi (or exact integer levels for `sincpi`), with
removable zero phases excluded. Unusually large phase ranges or unresolved
precision limits return `null`.
Finite rational intervals also support zero equations of `floor`, `ceil`,
`round`, and `trunc` applied to quadratic polynomials with rational
coefficients. Exact range checks distinguish empty or isolated boundary roots
from intervals containing infinitely many solutions; isolated roots use
Sturm-certified counts and brackets. `ceil(Q(x)^(2k)) = 0` and
`floor(-Q(x)^(2k)) = 0` for positive integer `k` also reduce to polynomial
root counting and isolation for any supported polynomial degree.
The rational-interval isolation API returns one certified root interval for
each of these supported phase levels and enforces its requested maximum width.
Zero-sum equations containing three or more rational powers of the variable or
one common nonconstant affine expression, with exact rational coefficients
(including rational perfect-power constants) and odd exponent denominators,
are also reduced to an exact polynomial in a common real root. Negative
exponents keep the zero-base exclusion. For a non-affine rational-function
base, the reduction also handles common-root polynomials whose real roots are
rational and can be isolated exactly, as well as quadratic common-root
polynomials with two irrational roots, cubic common-root polynomials with
three distinct real roots, and quartic common-root polynomials with four
distinct real roots. Exact square-free reduction also handles repeated factors
when the distinct roots of the common-root polynomial are all real. Square-free
common-root polynomials above degree two, up to the configured maximum
polynomial degree, can include non-real roots when the exact odd-power image
polynomial has the same number of distinct real roots as the source polynomial,
proving no spurious real levels were introduced. Rational real roots are also
reduced when other common roots are non-real. For mixed irrational real and
non-real roots over non-affine bases, an exact GCD of the composed image and
source polynomials filters extra real image levels, including irrational ones,
when the bounded-degree image polynomial can be constructed. Rational extra
levels can also be removed individually by exact real-preimage tests.
Exact root counting and isolation also support equations `abs(f(x)) = abs(g(x))`
when both arguments are supported rational functions; both sign branches are
reduced to a polynomial equation, and original denominator holes remain excluded.
The same reduction applies to `abs(cosh(P)) = abs(cosh(Q))` and the equivalent
`sech()` equations, using the even symmetry and strict monotonicity in `|P|`.
Direct equalities `cosh(f(x)) = cosh(g(x))` and
`sech(f(x)) = sech(g(x))` reduce to the two signed phase relations
`f(x) = g(x)` or `f(x) = -g(x)`; infinite identity branches are reported.
Mixed equalities `cosh(f(x)) = sech(g(x))` require both arguments to be zero,
since the functions' ranges meet only at 1; their zero sets are intersected.
Equalities between `asinh()`/`arcsinh()`, `atan()`/`arctan()`,
`asin()`/`arcsin()`, or `acos()`/`arccos()` applied to supported
rational-polynomial expressions reduce exactly to equality of their arguments,
including when the two supported aliases differ. `acot()` equalities use the
same reduction over its full real domain; inverse sine and cosine candidates
are filtered against `[-1, 1]`, including valid boundary roots.
Equalities between `csch()` or `coth()` calls also reduce to their arguments;
the undefined zero-argument point is excluded from certified roots.
Equalities between `erfinv()` calls and between `erfcinv()` calls reduce to
their arguments with the strict domains `(-1, 1)` and `(0, 2)`, respectively.
Mixed `erfinv(f(x)) = erfcinv(g(x))` equalities reduce to `f(x) + g(x) = 1`,
with the original equation enforcing both strict inverse-function domains.
Equalities between matching inverse regularized gamma or beta quantile calls
reduce to equality of their probability arguments when all shape parameters are
the same positive rational constants. Gamma quantiles require probabilities in
`[0, 1)` for finite lower-tail roots and `(0, 1]` for finite upper-tail roots;
beta quantiles include both probability endpoints.
Mixed lower- and upper-tail inverse calls canonicalize the upper-tail
probability as `1 - q` before root isolation.
Equal lower-tail `gammainc()` or upper-tail `gammaincc()` calls, and equal
`betainc()` or `betaincc()` calls, reduce to equality of their final arguments
when their shape parameters are identical rational functions whose numerators
and denominators each have exact strictly positive global lower bounds. Gamma
arguments are restricted to `[0, infinity)` and beta arguments to `[0, 1]`;
original rational-function poles remain excluded.
The same reduction applies when both calls are wrapped in `abs()`, since these
regularized functions are nonnegative on their real domains.
Equalities between matching `sqrt()`, `cbrt()`, and `root()` calls with the same
nonzero integer degree reduce to equality of their radicands. Even-degree
roots retain their nonnegative-radicand condition; negative degrees exclude
zero radicands and also require positive radicands for even degrees.
Equalities between positive odd roots of different degrees reduce to exact
integer-power equations, preserving rational-function denominator exclusions.
Absolute equalities between odd `cbrt()` and `root()` calls use the corresponding
even-power equation, including equivalent fractional-power notation with an odd
denominator; negative exponents retain their zero-radicand exclusions.
Absolute equalities involving even roots also reduce exactly when every
radicand is certified nonnegative over the real line. Certificates include
structural power rules, exact polynomial lower bounds, and root-isolation sign
checks; rational denominators must be certified strictly positive everywhere.
For zero levels, absolute values of positive even-root powers reduce to their
radicand-zero equations; equalities between two such powers also reduce when
one radicand is identically zero. These candidates lie on valid even-root
domain boundaries. Other radicands that are real only on a proper subset of the
line remain outside this exact reduction.
The natural-logarithm family also canonicalizes `ln(1 + P(x))`,
`log1p(P(x))`, and `log(P(x), e)` before exact root counting, while enforcing
the positive-argument domain.
Equal calls to globally injective real functions reduce to equality of their
arguments for `exp()`, `expm1()`, `cbrt()`, `sinh()`, `tanh()`, `erf()`,
`erfc()`, `erfcx()`, `erfi()`, `acot()`, `asinh()`/`arcsinh()`, and
`atan()`/`arctan()`. The reduced equation still needs to be supported by an
exact root solver, and restrictions in the arguments continue to apply.
Logarithms with the
same fixed base also reduce this way, including `ln()`/`log(value, e)`,
`log10()`/`log(value, 10)`, and `log2()`/`log(value, 2)` aliases. Mixed
`ln(f(x))`/`log1p(g(x))` calls
reduce to `f(x) = 1 + g(x)`. `ln()` and fixed-base `log()` require
positive arguments; `log1p()` requires arguments greater than `-1`. Roots at
poles of either original rational argument are excluded. Mixed
`exp(f(x)) = expm1(g(x))` equalities reduce to
`exp(f(x)) + 1 = exp(g(x))` for exponential-polynomial root isolation.
Equal `acosh()`/`arccosh()` calls reduce similarly on `argument >= 1`; equal
`asin()`/`arcsin()` and `acos()`/`arccos()` calls reduce on the closed domain
`[-1, 1]`. Equal
`atanh()`/`arctanh()` calls reduce on the open domain `(-1, 1)`, while `acoth()`
requires `|argument| > 1`. Equal `asech()` calls reduce on `(0, 1]`, and equal
`acsch()`, `csch()`, and `coth()` calls reduce on the nonzero real domain.
The inverse-composition identities `acsch(csch(P)) = P` and
`acoth(coth(P)) = P` are classified as infinite solution sets on intervals of
positive width when `P` is a nonzero rational function of the solved variable;
excluded points where `P = 0` or has a pole do not change infinitude.
The exact root APIs also recognize that `tanh(P) = coth(Q)` has no real roots,
including when `P` and `Q` are different known built-in expressions.
The same range check excludes `coth(P)` equal to `sin(Q)`, `cos(Q)`, `erf(Q)`,
or `sech(Q)`, and `cosh(P)` equal to `tanh(Q)` or `erf(Q)`.
For rational phases with nonzero `P`, `cosh(P) = cos(Q)` reduces to
`P^2 + Q^2 = 0`, while `cosh(P) = sin(Q)` has no real roots. Candidates with
`P = 0` are algebraic, so a nonzero rational phase value cannot be a multiple
of pi; original rational poles remain excluded.
Zero-phase equalities also reduce through supported unique-zero functions,
positive rational powers, and certified rational factors. For periodic phases,
an algebraic pole is discarded only when exact polynomial checks prove it cannot
remove a valid root; unresolved intersections remain unsupported.
For zero products or quotients with matched trigonometric phases, a reduced
rational phase ratio `p/q` is also certified when `q` is odd for sine versus
secant/tangent poles, or `p` is odd for cosine versus cosecant/cotangent poles.
For exact bounded-interval and all-real root APIs, `acosh(P) = asinh(Q)`
reduces to `P^2 - Q^2 = 1` with `P >= 1` and `Q >= 0`, and
`atanh(P) = acoth(Q)` reduces to `P * Q = 1` with `|P| < 1`. Rational-phase
poles are excluded. The related `asech(P) = asinh(Q)` equality uses the same
reduction with `1/P`, retaining the domain `0 < P <= 1`. Equalities with
`acsch(Q)` use `asinh(1/Q)` and exclude `Q = 0` and original rational poles.
The exact APIs also reduce `acosh(P) = asech(Q)` to `P * Q = 1` with
`P >= 1`, including non-identity equations and valid domain-boundary roots.
For `atanh(P) = asinh(Q)`, they use `P^2 * (1 + Q^2) = Q^2`, retain
`|P| < 1`, and require `P` and `Q` to have matching signs. The same applies
to `acsch(Q)` after substituting `1/Q` and preserving its poles. An `acoth(P)`
side similarly uses `atanh(1/P)`, retaining the strict domain `|P| > 1`.
The exact APIs use the same polynomial and sign conditions for
`asin(P) = atan(Q)` and `acsc(P) = atan(Q)`, substituting `1/P` for `acsc`.
They also apply to `acos(P) = acot(Q)` and `asec(P) = acot(Q)`, with
`1/P` substituted for `asec` and the inverse-cotangent principal branch retained.
For `asin(P) = acot(Q)` and `acos(P) = atan(Q)`, the exact reduction is
`P^2 * (1 + Q^2) = 1` with `P > 0` and `Q >= 0`; reciprocal `acsc` and `asec`
forms substitute `1/P` and retain their poles.
For `asin(P) = acos(Q)`, they use `P^2 + Q^2 = 1` with `P >= 0` and
`Q >= 0`, including reciprocal `acsc` and `asec` forms.
For `atan(P) = acot(Q)`, they use `P * Q = 1` with `P > 0`, preserving
rational-phase poles and excluding the opposite-sign principal branch.
For `asin(P) = acsc(Q)` and `acos(P) = asec(Q)`, they use `P * Q = 1`
with `|P| <= 1`, including the closed endpoint roots.
For `atanh(P) = acosh(Q)`, the exact reduction is `Q^2 * (1 - P^2) = 1`
with `0 <= P < 1` and `Q >= 1`; reciprocal `acoth` and `asech` forms use the
same branch checks.
Identities with open solution intervals raise
`InfiniteSolutionSetException`; isolated valid boundary roots remain countable.
Equal `erfcx()` calls reduce to equality of their rational-function arguments
across the full real domain, including values whose function outputs exceed
floating-point range. Original rational-function poles remain excluded.
Finite-interval `Math::solve()` also applies this reduction before evaluating
the shared `erfcx()` value, so equal calls remain solvable when that value
exceeds floating-point range.
Equal `zeta()` calls reduce to argument equality only when both argument
ranges are proven to lie strictly above 1 throughout the requested interval,
where the real zeta function is strictly decreasing.
Equal `gamma()` and `lgamma()` calls reduce to argument equality when both
argument ranges are proven at least 3/2 throughout the interval. Digamma is
positive from 3/2 onward, so both functions are strictly increasing there; no
cancellation is applied across their non-monotone lower domains.
The same reduction applies under `abs()` when the gamma arguments are at least
3/2 or the `lgamma()` arguments are at least 2, so the function outputs are
nonnegative throughout the interval. It also applies to equal `digamma()`
arguments from 3/2 onward and to matching positive-order `polygamma()` calls
on positive argument ranges, including `trigamma()` and `tetragamma()` aliases;
the order-zero digamma case retains the 3/2 lower bound.
Absolute equalities for positive injective `exp()`, `erfc()`, and `erfcx()` calls
reduce directly to argument equality without introducing a spurious negative
branch.
For globally defined odd, strictly increasing functions, absolute equalities
reduce to both signed argument relations; supported functions include `sinh`,
`tanh`, `asinh()`/`arcsinh()`, `atan()`/`arctan()`, `asin()`/`arcsin()`,
`atanh()`/`arctanh()`, `erf()`, `erfi()`, `erfinv()`, `acsc()`, and `cbrt()`.
Zero-sums of two `erfinv()` calls also reduce to the sum of their arguments,
with candidates restricted to the original strict domains `(-1, 1)`.
`erfcinv()` uses its symmetry about input 1. Nonnegative injective `acot()`,
`acos()`/`arccos()`, `acosh()`/`arccosh()`, `asec()`, and `asech()` use direct
argument equality. The same phase reduction applies to `csch()`, `coth()`,
`acsch()`, and `acoth()` on their original domains; candidates are checked
against those domains, and domain-sensitive identity branches fall back to
the general solver.
Absolute equalities for `acsch()`, `csch()`, and `coth()` reduce to absolute
argument equality on their nonzero domains; zero-argument candidates are
excluded along with rational-function poles.
All-real root counting and isolation additionally support equations formed by
strictly monotone built-ins applied to nonconstant affine rational expressions,
including rational-base exponentials, logarithms, selected hyperbolic functions,
the error functions, and `asin()`, `arcsin()`, `acos()`, `arccos()`, `atan()`,
`arctan()`, `acot()`, `asec()`, `acsc()`, and affine-coordinate `atan2()` when
one coordinate is a nonzero rational constant. The
isolator returns certified intervals for affine exponential, logarithmic, and
hyperbolic forms with finite-interval isolators, rational-base exponentials,
and affine constant-base `log()`, `log2()`, `log10()`, inverse-sine,
inverse-cosine, arctangent, inverse-cotangent, inverse-secant,
inverse-cosecant, and affine-coordinate `atan2()` equations.
For a positive non-unit rational base and a rational-function exponent, exact
counting and isolation also reduce recognized rational power targets to the
corresponding exponent equation. For example, `4^(1/x) = 8` reduces to
`1/x = 3/2`; poles of the exponent remain excluded. Integer power levels are
recognized through magnitude 1024, and the bounded general rational-power
search uses numerators and denominators through magnitude 32. Configured
exponent magnitude limits still apply.
For a base of one, rational exponents can yield an infinite solution set or
isolated boundary roots under a configured exponent magnitude limit. Exact
counting reports these cases only when it can certify the relevant interior or
boundary conditions, and it excludes poles of the exponent.
Affine `cosh()` and `sech()` equations are also counted exactly at rational
levels and isolated on both real branches when their targets lie in the
functions' two-root ranges.
Affine `csch()` and `coth()` equations are counted and isolated when their
nonzero targets are in range (`|q| > 1` for `coth()`).
All-real counting and isolation support `exp(a*x^2+b*x+c) = q` and
`expm1(a*x^2+b*x+c) = q` for rational coefficients, classifying the exact real
range at the quadratic vertex and isolating every root. The same equations are
supported on finite rational intervals.
For rational quadratic `P(x)`, `ln(P(x)) = q`, `log2(P(x)) = q`,
`log10(P(x)) = q`, and `log(P(x), b) = q` for a positive rational base `b != 1`
are likewise counted and isolated at rational levels using certified bounds
for the transformed level and the quadratic discriminant.
The same quadratic logarithm forms and `log1p(P₂(x)) = q` are supported on
finite rational intervals; roots exactly at either interval endpoint are
excluded.
Affine `erf()` and `erfc()` equations are isolated when their target is in the
function's open range and the certified root bracket fits within `[-64, 64]`.
The floating inverse supplies only a starting estimate; rational endpoint
enclosures certify the returned bracket and requested width. Affine
`erfinv()` and `erfcinv()` equations are also isolated when the exact target is
in `[-64, 64]`; those inverse-error roots use rational `erf()` enclosures.
Affine `erfi()` equations have one real root for every finite target. The
isolator certifies brackets when the inverse phase lies in `[-8, 8]`, using a
rational Taylor enclosure for `erfi()`; roots outside that phase range return
an unresolved result.
Polynomial-phase `erfi(P(x)) = q` equations with rational coefficients and
rational targets are also counted and isolated by partitioning at the critical
points of `P`, when the certified inverse level lies in `[-8, 8]`.
Rational Möbius-phase `erfi((a*x+b)/(c*x+d)) = q` equations are isolated
through the same certified inverse level when it lies in `[-8, 8]`; the
identically zero target reduces exactly to the phase numerator's root, while
denominator poles are excluded.
Rational Möbius-phase `erfcx((a*x+b)/(c*x+d)) = q` equations are isolated
when the target's certified inverse lies in `[-26, 26]`, using rational
enclosures of `erfcx` at the bracket endpoints; nonpositive targets are
root-free and denominator poles are excluded.
Other supported monotone forms remain count-only.
Equations `abs(f(x)) = c` are also reduced for exact rational constants `c`;
negative levels return no roots, while nonnegative levels retain all valid
branches and exclude denominator holes.
Zero-level equations for `sign()`, `sqrt()`, `cbrt()`, and positive-integer-degree
`root()` are reduced to their rational-function argument zeros; negative-degree
`root()` zero levels have no solutions.
The exact reducer also handles the zero levels of `log1p`, `expm1`, `sinh`,
`tanh`, inverse hyperbolic sine/tangent, inverse sine/tangent, `erf`, `erfi`,
`dawson`, and `erfinv`; `ln`, `log10`, and `log2` reduce to argument level 1.
For supported rational-function arguments, `dawson(R(x)) = 0` is reduced
exactly to the numerator roots of `R`; roots at denominator poles are removed
before polynomial counting and isolation.
Nonzero rational levels of Dawson with polynomial or linear-rational phases
are isolated on both inverse branches when `0 < |q| <= 541/1000` and the upper
branch lies within `[-16, 16]`; each inverse phase bracket is certified by a
positive-term Dawson series enclosure. Exact targets with `|q| >= 1` are
classified as root-free from Dawson's strict range bound, and targets with
`|q| >= 11/20` are root-free from a tighter certified maximum bound.
`log(value, base)` is reduced at value 1 when its base is an exact positive
constant other than 1. Boundary zero levels of `acos`, `acosh`, `asec`,
`asech`, and `erfcinv`, as well as the principal `lambertw` zero, are included.
Equalities between matching `ln`, `log10`, or `log2` calls reduce to equality
of their arguments when one argument is an exact positive rational constant;
nonpositive constant arguments have no real solutions, and general pairs keep
their logarithm-domain restrictions.
For exact integer order `n` within the evaluator's supported range, `besseli(n,
f(x)) = 0` reduces to `f(x) = 0` when `n` is nonzero; order zero has no real
zero level.
Nested `erfinv(erf(f(x)))` and `erfcinv(erfc(f(x)))` compositions reduce to
`f(x)` in exact polynomial and rational-function equations when equated to a
rational constant in `[-4, 4]`, retaining denominator holes in `f`. Outside
that range, the exact reducer declines the rewrite because the floating-point
forward functions can round to inverse-domain endpoints.
Nested square roots, cube roots, integer `root()` calls (nonzero degree from
-63 through 63, including fixed integer parameters), and rational powers with
exponent `+/-1/n` are inverted exactly in mixed chains up to eight levels for
rational targets. The reducer respects even-root branches, rejects zero
targets for negative degrees, and retains rational-function denominator
holes. Fixed numeric parameters are accepted as exact decimal-rational
targets. Equality between positive odd-root chains with the same total degree
also reduces to equality of their radicands, including differently nested
`root()` and fractional-power forms.
Regularized gamma and beta functions and their inverses also reduce at their
exact endpoint zero levels when shape parameters are positive exact constants.
The regularized upper incomplete gamma has no finite zero level, while its
inverse is zero at input 1.
Exact zero-level queries for `exp`, `cosh`, `sec`, `csc`, `sech`, `erfc`,
`erfcx`, `gamma`, `doublefactorial`, `csch`, `coth`, `acsch`, `acoth`, `acsc`,
and `acot` return no real roots, since none can be zero on its real domain.
The unit levels of `exp`, `cosh`, and `sech` reduce to a zero argument; their
exact levels outside the real ranges return no roots.
Equations applying the same `exp`, `expm1`, `sinh`, `tanh`, `asinh`, `atan`,
`erf`, `erfc`, `erfi`, `cbrt`, or `acot` function to both sides reduce to
argument equality. Alias spellings are normalized across sides, so `atan()`
and `arctan()` (or `asinh()` and `arcsinh()`) can be mixed in one equation.
Equal `lambertw()` calls also reduce to equality of their arguments when both
use the same real branch, including the default principal branch 0.
Equal `gammaincinv()` or `gammainccinv()` calls with equal positive shape
arguments also reduce to equality of their probability arguments; infinite
quantile endpoints are excluded. Mixed lower/upper inverse calls with equal
shape reduce to the complementary probability relation `p + q = 1`. The same
reductions apply to `betaincinv()` and `betainccinv()` when both shape
arguments match.
For swapped beta shapes, `betaincinv(a, b, p) + betainccinv(b, a, q) = 1`
reduces to `p = q`, while two lower-inverse calls or two upper-inverse calls
reduce to `p + q = 1`. The equivalent `f(x) = 1 - g(x)` forms use the same
probability reductions.
The complementary identity `atan(f(x)) + acot(g(x)) = pi/2` (and its
`arctan` alias, either term order, or equation-side order) reduces to
`f(x) = g(x)` when both arguments are supported rational functions; finite-
interval solving also applies this reduction before numerical residual search.
Likewise, `erf(f(x)) + erfc(g(x)) = 1` reduces to argument equality and retains
holes in rational-function arguments.
The principal real-branch identities `asin(f(x)) + acos(g(x)) = pi/2` and
`asec(f(x)) + acsc(g(x)) = pi/2` reduce to `f(x) = g(x)`, while
`acos(f(x)) + acos(g(x)) = pi` and `asec(f(x)) + asec(g(x)) = pi` reduce to
`f(x) + g(x) = 0`. Candidate roots are checked against the original equation
so inverse-function domain restrictions remain enforced.
The equality `asin(f(x)) = acos(g(x))` reduces to `f(x)^2 + g(x)^2 = 1`, and
`asec(f(x)) = acsc(g(x))` reduces to `1/f(x)^2 + 1/g(x)^2 = 1`; candidate
filtering enforces the principal-angle branches and real domains.
The equality `asin(f(x)) = asec(g(x))` reduces to
`f(x)^2 + 1/g(x)^2 = 1`; original-equation checks enforce the nonnegative
principal `asec` angle and reject the extraneous sign branch.
Likewise, `acos(f(x)) = acsc(g(x))` reduces to
`f(x)^2 + 1/g(x)^2 = 1`, with original-equation validation enforcing the
principal-angle branch.
The principal inverse-angle equalities `asin(f(x)) = acsc(g(x))` and
`acos(f(x)) = asec(g(x))` reduce to `f(x) g(x) = 1`; the original equation
validation retains the real domains and angle branches.
The equalities `asin(f(x)) = acot(g(x))` and `acos(f(x)) = acot(g(x))` reduce
to `f(x)^2 (1 + g(x)^2) = 1` and `f(x)^2 (1 + g(x)^2) = g(x)^2`, respectively;
original-equation checks enforce the principal-angle branches.
The reciprocal inverse-angle equalities `asec(f(x)) = acot(g(x))` and
`acsc(f(x)) = acot(g(x))` reduce to `f(x)^2 - 1/g(x)^2 = 1` and
`f(x)^2 - g(x)^2 = 1`; original-equation checks retain their principal branches.
The mixed hyperbolic equality `asinh(f(x)) = acosh(g(x))` reduces to
`g(x)^2 - f(x)^2 = 1`, with alias spellings and side-reversed equations
supported; candidate filtering enforces the nonnegative `acosh` branch.
The equality `asinh(f(x)) = acsch(g(x))` reduces to `f(x) g(x) = 1`, including
side-reversed forms; this relation excludes the singular `g(x) = 0` case.
The mixed equality `acosh(f(x)) = acsch(g(x))` reduces to
`f(x)^2 - 1/g(x)^2 = 1`; original-equation checking preserves the positive
`acsch` argument required by the nonnegative `acosh` range.
The identity `acoth(f(x)) = acsch(g(x))` reduces to
`f(x)^2 - g(x)^2 = 1`; original-equation checks reject roots on the wrong
principal `acoth` branch.
The complementary inverse-hyperbolic equality `acoth(f(x)) = atanh(g(x))`
reduces to `f(x) g(x) = 1`; original-domain checks exclude the strict-boundary
cases.
The equality `acosh(f(x)) = atanh(g(x))` reduces to
`f(x)^2 (1 - g(x)^2) = 1`; original-equation checks enforce both real domains
and the nonnegative `acosh` branch.
The equality `asech(f(x)) = atanh(g(x))` reduces to
`f(x)^2 + g(x)^2 = 1`; original-equation checks preserve the nonnegative
`asech` output branch.
The equality `acsch(f(x)) = atanh(g(x))` reduces to
`g(x)^2 (1 + f(x)^2) = 1`; original-equation validation removes the wrong-sign
branch and enforces the real domains.
Equal built-in inverse functions on both sides, including aliases such as
`atan(f(x)) = arctan(g(x))`, reduce to `f(x) = g(x)`. The original equation
evaluation enforces the shared function's real domain.
The equality `asinh(f(x)) = atanh(g(x))` reduces to
`f(x)^2 (1 - g(x)^2) = g(x)^2`; original-equation validation rejects roots
with the wrong sign after squaring.
The equality `acosh(f(x)) = acoth(g(x))` reduces to
`f(x)^2 (g(x)^2 - 1) = g(x)^2`; original-equation checks retain only roots on
the real principal branches.
The equality `asinh(f(x)) = acoth(g(x))` reduces to
`f(x)^2 (g(x)^2 - 1) = 1`; original-equation checks restore the sign condition
lost by squaring and enforce the real domains.
The equality `acoth(f(x)) = asech(g(x))` reduces to
`f(x)^2 (1 - g(x)^2) = 1`; original-equation checks enforce the positive
`asech` output and strict `acoth` domain.
The equality `acosh(f(x)) = asech(g(x))` reduces to `f(x) g(x) = 1`; original
domain checks preserve the nonnegative `acosh` branch and the positive `asech`
argument.
The equality `asinh(f(x)) = asech(g(x))` reduces to
`g(x)^2 (1 + f(x)^2) = 1`; original-equation checks discard roots that violate
the nonnegative `asech` output branch.
The inverse-tangent equalities `asin(f(x)) = atan(g(x))` and
`acos(f(x)) = atan(g(x))` reduce to `f(x)^2 (1 + g(x)^2) = g(x)^2` and
`f(x)^2 (1 + g(x)^2) = 1`, respectively. The reciprocal forms
`asec(f(x)) = atan(g(x))` and `acsc(f(x)) = atan(g(x))` reduce to
`f(x)^2 - g(x)^2 = 1` and `f(x)^2 g(x)^2 = 1 + g(x)^2`; the original
equation checks preserve principal branches and domains. Finally,
`acot(f(x)) = atan(g(x))` reduces to `f(x) g(x) = 1`.
The equality `acsch(f(x)) = asech(g(x))` reduces to
`f(x)^2 - g(x)^2 = f(x)^2 g(x)^2`; original-equation validation enforces the
positive `asech` output and the matching `acsch` argument branch.
The equivalent zero-sum identity `erfc(f(x)) + erfc(g(x)) = 2` reduces to
`f(x) + g(x) = 0`, including side-reversed and rearranged forms.
For odd, injective built-ins `atan`, `asinh`, `sinh`, `tanh`, `erf`, `erfi`,
and `cbrt`, zero-sum equations such as
`erf(f(x)) + erf(g(x)) = 0` reduce to `f(x) + g(x) = 0`; `atan()`/`arctan()`
and `asinh()`/`arcsinh()` aliases may be mixed. The same
reduction handles `asin()`/`arcsin()` and `atanh()`/`arctanh()` when both
original arguments are in their real domains, as well as `csch()`, `coth()`,
`acsch()`, `acoth()`, and `acsc()` on their respective real domains. Candidates
are checked against the original equation, preserving rational-function
denominator holes and function domains.
The equivalent form `erf(f(x)) = -erf(g(x))` is reduced identically, including
mixed `atan()`/`arctan()` aliases for their odd increasing functions.
Absolute-value equations `abs(h(u(x))) = abs(h(v(x)))` for those same odd,
strictly increasing functions reduce to both argument branches `u = v` and
`u = -v`, with rational-function holes excluded.
Equal `sech()` calls use the same two argument branches because `sech()` is
strictly decreasing as a function of the absolute argument.
Affine beta arguments with bounded exact rational coefficients are reduced by
enumerating defined exact levels
`a(x)+b(x) = -n` for `0 <= n <= 342` and excluding roots where either
argument is a pole. Nonlinear polynomial and rational-function beta arguments
are also reduced level by level when the other argument is an exact constant,
excluding roots at rational-function denominator holes. Two varying rational-
function arguments are reduced by isolating each sum level, excluding original
denominator holes and exact roots at gamma poles within the supported argument
range. A rational-function beta equation with a constant sum that is not a
nonpositive integer has no roots. If the sum is a supported nonpositive
integer, an exact rational witness within the requested interval (when
bounded), the supported argument range, and away from poles proves an infinite
solution set.
For nonconstant rational-function sums, exact Sturm counts prove an empty zero
set when the sum reaches none of the supported nonpositive-integer levels.
On finite intervals, beta zero-level equations with other interval-evaluable
argument forms enumerate reachable supported sum levels and solve each sum
equation numerically, excluding gamma poles. This fallback is limited to levels
from zero through 342; all-real exact counting and isolation remain restricted
to the specialized polynomial and periodic forms above.
Beta zero-level equations with one `sin(P(x))`, `cos(P(x))`, `tan(P(x))`,
`cot(P(x))`, `sec(P(x))`, or `csc(P(x))` argument, one exact rational constant
argument, and a nonconstant rational-polynomial phase are classified by the
function range and supported beta zero levels.
Finite-interval equation solving also handles beta zero-level equations whose
two arguments are bounded affine combinations of sine and cosine phases with
commensurate frequencies. It also isolates matching or complementary sine and
cosine arguments applied to a shared nonconstant rational-polynomial phase.
It enumerates reachable nonpositive-integer sum levels and excludes beta poles.
When both beta arguments are the same one of those periodic functions applied
to the same nonconstant rational polynomial, all-real APIs detect a reachable
defined shared level as an infinite solution set; finite rational intervals
count and isolate its supported levels. All-real classification also detects
infinite zero sets for matching-phase `sin`/`cos`, `tan`/`cot`, and `sec`/`csc`
pairs when their sum reaches a defined nonpositive-integer beta level. Finite
interval APIs also count and isolate matching-phase `sin`/`cos` and `tan`/`cot`
pairs.
Finite-interval equation solving also isolates matching-phase `sec`/`csc`
beta zero-level equations. Rational-interval count and isolation APIs support
matching-phase `sin`/`cos` and `tan`/`cot` pairs. All-real APIs report an
infinite solution set when a defined level is reachable.
For nonconstant polynomial arguments, all-real root counting and isolation
report infinite solution sets for `sin(P) = c` and `cos(P) = c` when the exact
real level `c` is in `[-1, 1]`, and for `tan(P) = c` and `cot(P) = c` at any
exact finite level. Zero-level equations of `sinc(P)` and `sincpi(P)` also have
infinite solution sets; exact sine or cosine levels outside `[-1, 1]` return no
roots. For real arguments, `sinc(P) = 1` and `sincpi(P) = 1` reduce to `P = 0`,
and levels outside `[-1, 1]` have no roots. These APIs also report infinite
solution sets for `floor`, `ceil`, `round`, and `trunc` with nonconstant affine
arguments, all nonconstant odd-degree polynomial arguments, and quadratic
arguments whose exact ranges overlap the interior of the corresponding zero
bin. Quadratic boundary-only cases are counted and isolated exactly when they
reduce to a single rational vertex root. Equations `ceil(Q(x)^(2k)) = 0` and
`floor(-Q(x)^(2k)) = 0` for positive integer `k` reduce to the exact
polynomial roots of `Q` for any supported polynomial degree.
Postfix factorial equations have no zero roots because factorial is nonzero
wherever it is defined.
Finite-interval exact root APIs enumerate the trivial zeros of `zeta` for
polynomial arguments using rational interval bounds. All-real APIs also handle
positive-leading quadratics, even polynomials with nonnegative nonconstant
terms, and even-degree polynomials with recursively certified rational-square
decompositions and bounded residuals. Even-degree polynomials use certified
rational intervals for real critical points within the configured polynomial
degree and arithmetic limits. Affine and other
unbounded-below polynomial arguments report their infinite trivial-zero set.
Finite rational intervals also support exact trivial-zero enumeration for
rational zeta arguments when adaptively refined interval Horner bounds certify
that the denominator stays away from zero. Resulting polynomial level
equations use Sturm counting and isolation, excluding denominator holes.
All-real counting and isolation also handle rational zeta arguments with a
root-free reduced denominator and either bounded range or positive growth on
both tails; critical points and asymptotics bound the relevant level range.
Reciprocal-square arguments with positive double poles are handled by exact
range levels as well. Arguments unbounded below are reported as infinite root
sets.
Genuine real poles with a negative-diverging side are reported as infinite
root sets when exact derivative and sign checks certify the pole order and
direction. Constant rational arguments are recognized across denominator
holes, with trivial-zero levels reported as infinite solution sets.
Variable-free perfect rational radicals are also folded exactly, including
`sqrt()`, `cbrt()`, integer-degree `root()`, and rational fractional powers
such as `4^(1/2)` or `16^(3/4)`. Irrational results such as `sqrt(2)` are not
approximated as exact coefficients. Equations of the form `R(x)^(p/n) = q`,
where `R` is a supported rational function formed from exact rational
polynomials, are counted and isolated by transforming each valid real branch
to `R(x) = q^(n/p)` when that inverse is rational, or to an equivalent integer
polynomial equation when it is not. The transformed polynomial must stay
within configured degree and arithmetic limits. Denominator poles and zero
bases for negative exponents are excluded; irrational roots are returned as
certified rational intervals. Bounded integer degrees, real-domain rules, both
branches for even `p` where applicable, and finite-interval endpoints are
checked.

`Math::isolateExponentialEquation()` certifies roots of `exp(x) = q` on a
finite rational interval. It returns an empty list when exact endpoint
enclosures prove there is no root, and `null` when configured arithmetic or
subdivision limits prevent certification:

```php
use MathPHP\Math;

$logarithmIntervals = Math::isolateExponentialEquation('2', '0', '1', '1/1000');
// A certified rational interval containing ln(2), no wider than 1/1000.
```

For rational slope and intercept, `Math::isolateAffineExponentialEquation()`
certifies `exp(a*x + b) = q` over the requested rational bounds, including
decreasing exponential phases:

```php
use MathPHP\Math;

$affineLogarithmIntervals = Math::isolateAffineExponentialEquation(
    '4',
    '2',
    '1',
    '0',
    '1',
    '1/1000',
);
// A certified interval containing (ln(4) - 1) / 2.
```

For rational coefficients, `Math::isolateQuadraticExponentialEquation()`
isolates all real roots of `exp(a*x^2 + b*x + c) = q`. The quadratic phase is
split at its exact rational vertex, then each monotone branch is certified by
rational exponential enclosures. A zero quadratic coefficient delegates to
the affine solver:

```php
use MathPHP\Math;

$quadraticExponentialIntervals = Math::isolateQuadraticExponentialEquation(
    '2',
    '1',
    '0',
    '0',
    '-2',
    '2',
    '1/1000',
);
// Two certified intervals containing +/-sqrt(ln(2)).
```

`Math::isolateAffineHyperbolicSineEquation()` certifies all roots of
`sinh(a*x + b) = q` on rational bounds. Since `sinh` is strictly increasing,
the solver isolates at most one root and handles either sign of `a`:

```php
use MathPHP\Math;

$sinhIntervals = Math::isolateAffineHyperbolicSineEquation(
    '1/2',
    '2',
    '1',
    '-1',
    '0',
    '1/1000',
);
// A certified interval containing (asinh(1/2) - 1) / 2.
```

`Math::isolateAffineHyperbolicCosineEquation()` isolates the zero, one, or two
roots of `cosh(a*x + b) = q`. It handles the exact minimum case `q = 1`,
rejects `q < 1`, and isolates the positive and negative phase branches for
`q > 1`:

```php
use MathPHP\Math;

$coshIntervals = Math::isolateAffineHyperbolicCosineEquation(
    '2',
    '2',
    '-1',
    '-1',
    '2',
    '1/1000',
);
// Two certified intervals containing (1 +/- acosh(2)) / 2.
```

`Math::isolateAffineHyperbolicTangentEquation()` certifies the unique root, if
present, of `tanh(a*x + b) = q` on rational bounds. The unattainable endpoint
targets `q = -1` and `q = 1`, and all targets outside `(-1, 1)`, return no
roots:

```php
use MathPHP\Math;

$tanhIntervals = Math::isolateAffineHyperbolicTangentEquation(
    '1/2',
    '2',
    '-1',
    '0',
    '1',
    '1/1000',
);
// A certified interval containing (atanh(1/2) + 1) / 2.
```

The reciprocal hyperbolic functions use the same certified affine paths via
exact reciprocal levels: `sech(z) = q` reduces to `cosh(z) = 1/q`,
`csch(z) = q` to `sinh(z) = 1/q`, and `coth(z) = q` to `tanh(z) = 1/q`.
Their real ranges and excluded points are checked before isolation. For
example, `sech(z) = 1/2` has two branches, while `coth(z) = 1` has no finite
real solution:

```php
use MathPHP\Math;

$sechIntervals = Math::isolateAffineHyperbolicSecantEquation(
    '1/2',
    '2',
    '-1',
    '-1',
    '2',
    '1/1000',
);
$noCothEndpointRoots = Math::isolateAffineHyperbolicCotangentEquation('1', '1', '0', '-2', '2');
```

The inverse hyperbolic equations `asinh(a*x + b) = q`,
`acosh(a*x + b) = q`, and `atanh(a*x + b) = q` are also supported by
`Math::isolateAffineInverseHyperbolicSineEquation()`,
`Math::isolateAffineInverseHyperbolicCosineEquation()`, and
`Math::isolateAffineInverseHyperbolicTangentEquation()`. They enclose the
corresponding `sinh(q)`, `cosh(q)`, or `tanh(q)` level and map that certified
interval back through the affine expression. Results may be `null` when the
enclosure exceeds the requested width or overlaps a domain boundary.
The reciprocal inverse functions `asech`, `acsch`, and `acoth` have matching
certified affine APIs: `Math::isolateAffineInverseHyperbolicSecantEquation()`,
`Math::isolateAffineInverseHyperbolicCosecantEquation()`, and
`Math::isolateAffineInverseHyperbolicCotangentEquation()`. Their unattainable
target ranges return an empty list; otherwise the inverse level is enclosed
using exact rational hyperbolic intervals before mapping back to `x`.

`Math::isolateAffineLogarithmEquation()` certifies `ln(a*x + b) = q` by
enclosing `exp(q)` with exact rational bounds and mapping them through the
inverse affine expression:

```php
use MathPHP\Math;

$affineLogIntervals = Math::isolateAffineLogarithmEquation(
    '1',
    '2',
    '1',
    '0',
    '1',
    '1/1000',
);
// A certified interval containing (e - 1) / 2.
```

For a quadratic argument, `Math::isolateQuadraticLogarithmEquation()` certifies
both real branches of `ln(a*x^2 + c) = q` and clips them to the requested
interval:

```php
use MathPHP\Math;

$quadraticLogIntervals = Math::isolateQuadraticLogarithmEquation(
    '1',
    '1',
    '1',
    '-2',
    '2',
    '1/1000',
);
// Two certified intervals containing +/-sqrt(e - 1).
```

For a general quadratic argument, `Math::isolateQuadraticPolynomialLogarithmEquation()`
uses certified discriminant and square-root intervals, returning one interval
for a repeated root or separate intervals for distinct real roots:

```php
use MathPHP\Math;

$quadraticIntervals = Math::isolateQuadraticPolynomialLogarithmEquation(
    '1',
    '1',
    '2',
    '1',
    '-3',
    '1',
    '1/1000',
);
// Two certified intervals containing -1 +/- sqrt(e).
```

For a rational logarithm base other than one,
`Math::isolateAffineLogarithmWithBaseEquation()` certifies
`log_base(a*x + b) = q` by enclosing `base^q` and isolating the resulting
affine polynomial level:

```php
use MathPHP\Math;

$baseLogIntervals = Math::isolateAffineLogarithmWithBaseEquation(
    '2',
    '3',
    '2',
    '0',
    '0',
    '5',
    '1/1000',
);
// A certified interval containing 4, the solution of log_2(2*x) = 3.
```

The same method supports a quadratic polynomial argument, including two-root
cases such as `log_2(x^2) = 3`:

```php
use MathPHP\Math;

$quadraticBaseLogIntervals = Math::isolateQuadraticPolynomialLogarithmWithBaseEquation(
    '2',
    '3',
    '1',
    '0',
    '0',
    '-4',
    '4',
    '1/1000',
);
// Certified intervals containing -sqrt(8) and sqrt(8).
```

`ExactRationalInterval` provides bounded exact arithmetic on rational endpoints.
Its `exp()` method encloses the exponential with a rational Taylor polynomial
and a geometric remainder bound, without floating-point rounding. Directed
fixed-point rounding bounds intermediate integer growth; inputs outside
`[-4096, 4096]` are rejected. Its
`sqrt()` method encloses rational square roots on a `10^-24` grid and rejects
intervals that include negative values.

```php
use MathPHP\Solver\ExactRationalInterval;

$range = new ExactRationalInterval(['0', '1'], ['1', '1']);
$exponentialRange = $range->exp();
$exponentialRange->containsRational('1', '1'); // true
```

```php
$solutionSet = Math::solveEquationSet('abs(x) = x', 'x', -3.0, 3.0);
// one closed interval from 0 to 3

$ranges = Math::solveInequality('x^2 <= 1', 'x', -3.0, 3.0);
// one closed interval from -1 to 1

$slope = Math::differentiate('sin(x) + x^2', 'x', 0.5);
// approximately cos(0.5) + 1

$roots = Math::solve(
    'sin(x) = 0',
    'x',
    -7.0,
    7.0,
    solverOptions: new SolverOptions(scanSteps: 1024),
);
```

For real-coefficient polynomial equations over the complex numbers, use
`Math::solvePolynomialComplex()`. It returns one approximate candidate per
degree, including multiplicity. Monomials return their repeated zero roots
directly; two-term polynomials factor out their lowest power and generate the
remaining binomial roots in polar form. Other polynomials use Durand-Kerner
iteration and Newton polishing; a polynomial verified as a repeated power of
one linear or quadratic factor is reconstructed directly, including repeated
real roots and complex-conjugate pairs. Expanded polynomials verified as a
real scalar multiple of a polynomial power are reduced to the lower-degree
factor before solving, preserving all root multiplicities. Mixed repeated
factors are polished at candidates validated through the polynomial's
derivative chain, including non-real conjugate roots. When exact integer
coefficients are available, repeated roots of arbitrary multiplicity are also
split into square-free polynomial factors, solved independently, and restored
with their exact algebraic multiplicities.
Chained polynomial equalities such as `x^2 = 1 = 2*x - 1` are solved as the
intersection of adjacent polynomial equations using an exact polynomial GCD;
identity chains are reported as infinite solution sets.
`converged` is false when the numerical method cannot validate every
candidate or when an inverse-function target cannot be represented without
collapsing onto an excluded endpoint. Repeated or ill-conditioned roots may
not converge, so this API does not certify a complete root set. If exact
coefficients reveal a nonzero term that cannot be retained by finite variable
scaling, the solver throws `DomainException` rather than silently lowering the
polynomial degree:

At their zero levels, `sqrt()`, `cbrt()`, `root(, n)` for positive integer
`n`, `asin()`, `atan()`, `asinh()`, `atanh()`, `abs()`, and `erfinv()` reduce
to the polynomial argument's zero set. `ln()`, one-argument `log()`,
`log10()`, `log2()`, `log()` with a valid positive constant base other than
one, `acos()`, `acosh()`, `asec()`, `asech()`, and `erfcinv()` reduce to the
corresponding argument-equals-one equation. Complex `exp()`, `gamma()`, and
factorial have no zeros, so those equations return an empty root set.
For nonzero real levels, `gamma(P(x))` with nonconstant polynomial `P` has
infinitely many complex solutions and reports `InfiniteSolutionSetException`.
For a real constant target `c`, `sqrt(P(x)) = c` is also reduced to
`P(x) = c^2` when `c >= 0`, and returns no roots when `c < 0`, matching the
principal complex square-root branch.
Matching signed principal square roots reduce to radicand equality when their
signs agree; opposite signs can agree only at common radicand zeros.
Equal `sec()` calls on nonconstant polynomial phases have infinitely many
complex solutions: wherever defined, the equation is equivalent to equality
of the corresponding cosine values.
For exact rational-coefficient polynomials `P` and `Q`, `abs(P(x)) = abs(Q(x))`
is classified by their exact ratio: a nonconstant ratio or unit constant ratio
has infinitely many solutions, while other constant ratios reduce to common
polynomial zeros.
Nonzero levels of `cbrt(P(x))` and `root(P(x), n)` for fixed nonzero integer
`n` reduce to `P(x) = c^n`; negative levels are excluded for even-degree
roots, and zero levels for negative-degree roots have no solutions.
For real constant levels within the principal ranges, inverse trigonometric
and hyperbolic equations are reduced by applying the corresponding forward
function to the level. Out-of-range and singular levels produce an empty root
set; targets lost to floating-point overflow, underflow, or endpoint rounding
produce an empty root list with `converged` false.
The cofunction equations `sin(acos(U)) = t` and `cos(asin(U)) = t` reduce
for `0 <= t <= 1` to the argument levels `U = ±sqrt(1 - t^2)`; each candidate
is checked against the original equation and inverse-function domains.
The mixed equations `sin(atan(U)) = t`, `cos(atan(U)) = t`, and
`tan(asin(U)) = t` are also reduced to finite real argument levels, with
unattainable or endpoint levels excluded.
Likewise, `sinh(atanh(U)) = t` and `tanh(asinh(U)) = t` reduce to their
single real argument levels; the latter excludes targets at or beyond ±1.
`csch(atanh(U)) = t` reduces for every nonzero finite target, excluding zero,
which is not attained at any finite real argument.
`acsch(atanh(U)) = t` likewise reduces for nonzero targets by reversing the
inverse-reciprocal and inverse-hyperbolic operations.
`acoth(asinh(U)) = t` reduces for every nonzero target through
`U = sinh(coth(t))`; zero is excluded by the `acoth` range.
`acoth(atanh(U)) = t` similarly reduces for nonzero targets through
`U = tanh(coth(t))`.
`acoth(csch(U)) = t` reduces for nonzero targets to
`U = asinh(tanh(t))`.
`acoth(asech(U)) = t` reduces for `t > 0` to the unique level
`U = sech(coth(t))`.
`acsch(tanh(U)) = t` reduces for `|t| > asinh(1)` to
`U = atanh(csch(t))`, with strict endpoints.
`asinh(coth(U)) = t` reduces for `|t| > asinh(1)` through a factored
inverse level near the endpoint and a stable reciprocal form farther away.
`asinh(tanh(U)) = t` reduces for `|t| < asinh(1)` through a factored
inverse level that preserves the strict target boundaries.
`acsch(coth(U)) = t` reduces for `0 < |t| < asinh(1)` through a factored
inverse level that preserves both strict endpoints.
`asech(tanh(U)) = t` reduces for `t > 0` to `U = atanh(sech(t))`;
zero is excluded because `tanh(U)` never reaches one.
`atanh(csch(U)) = t` reduces for nonzero targets through the inverse
hyperbolic-cosecant level of `tanh(t)`.
`acsch(sech(U)) = t` reduces for `t >= asinh(1)` to symmetric inverse-sech
levels, merging to zero at the included lower endpoint.
`csch(acoth(U)) = t` reduces for nonzero targets to the unique signed level
`U = sign(t) * hypot(1, t)`.
`acosh(csch(U)) = t` reduces for `t >= 0` to the unique level
`U = asinh(sech(t))`.
`asech(csch(U)) = t` reduces for `t >= 0` to `U = asinh(cosh(t))`,
evaluated in a log-domain form to avoid overflow.
`acosh(coth(U)) = t` reduces for `t > 0` to the unique positive level
`U = log(coth(t/2))`; zero is an unattained limit.
`acosh(asech(U)) = t` reduces for `t >= 0` to `U = sech(cosh(t))`,
including its attained zero target.
`acosh(acsch(U)) = t` reduces for `t >= 0` to the unique positive level
`U = csch(cosh(t))`.
`acosh(acoth(U)) = t` reduces for `t >= 0` to `U = coth(cosh(t))`.
`asinh(acosh(U)) = t` and `acosh(asinh(U)) = t` reduce for `t >= 0` to
`U = cosh(sinh(t))` and `U = sinh(cosh(t))`, respectively.
`asinh(acos(U))`, `asinh(atan(U))`, and `acosh(acos(U))` reduce on their exact
bounded real ranges through `U = cos(sinh(t))`, `tan(sinh(t))`, and
`cos(cosh(t))`, respectively.
`acosh(asin(U))` reduces through `U = sin(cosh(t))` up to its attained upper
target `acosh(π/2)`; `acosh(atan(U))` uses `U = tan(cosh(t))` below that open
upper target.
`asin(acosh(U)) = t` and `acos(acosh(U)) = t` reduce for `t` in `[0, π/2]`
to `U = cosh(sin(t))` and `U = cosh(cos(t))`, respectively.
`acos(asinh(U)) = t`, `atan(asinh(U)) = t`, and `atan(acosh(U)) = t`
reduce on `[0, π]`, `(-π/2, π/2)`, and `[0, π/2)`, respectively.
`asin(atan(U))` and `acos(atan(U))` reduce on `[-π/2, π/2]` and `[0, π]`
through `U = tan(sin(t))` and `U = tan(cos(t))`; `asin(acos(U))` and
`acos(acos(U))` reduce on `[0, π/2]` through `U = cos(sin(t))` and
`U = cos(cos(t))`, respectively.
`asin(asin(U))` reduces on `[-π/2, π/2]` to `U = sin(sin(t))`, while
`acos(asin(U))` reduces on `[0, π]` to `U = sin(cos(t))`.
`atan(asin(U))` and `atan(acos(U))` reduce on
`[-atan(π/2), atan(π/2)]` and `[0, atan(π)]` through
`U = sin(tan(t))` and `U = cos(tan(t))`, respectively.
Since `acot(U)` has range `(0, π)`, `asinh(acot(U))` and `acosh(acot(U))`
reduce on `(0, asinh(π))` and `[0, acosh(π))` through cotangent levels.
`asin(acot(U))` reduces on `(0, π/2]`, while `acos(acot(U))` reduces on
`[0, π/2)`; both use `U = cot(sin(t))` or `U = cot(cos(t))`, respectively.
`atan(asec(U))` reduces on `[0, atan(π)]` except at `atan(π/2)`;
`atan(acsc(U))` reduces on `[-atan(π/2), atan(π/2)]` except zero,
using `U = sec(tan(t))` and `U = csc(tan(t))` on those ranges.
`atan(atan(U))` reduces on `(-atan(π/2), atan(π/2))` to
`U = tan(tan(t))`; `atan(acot(U))` reduces on `(0, atan(π))` to
`U = cot(tan(t))`.
`atanh(asinh(U))`, `atanh(asin(U))`, and `atanh(atan(U))` reduce for every
real target to `U = sinh(tanh(t))`, `U = sin(tanh(t))`, and
`U = tan(tanh(t))`; `atanh(acos(U))` reduces for `t >= 0` to
`U = cos(tanh(t))`.
The self-compositions `atanh(atanh(U))`, `acsch(acsch(U))`, and
`acoth(acoth(U))` reduce to `U = tanh(tanh(t))`, `csch(csch(t))`, and
`coth(coth(t))`; the latter two exclude `t = 0`. `asech(asech(U))` reduces
for `t >= 0` to `U = sech(sech(t))`. `acot(acot(U))` reduces on
`(atan2(1, π), π/2)` to `U = cot(cot(t))`.
`atanh(acot(U))` reduces for `t > 0` to a cotangent level;
`atanh(asec(U))` reduces for `t >= 0` to `U = sec(tanh(t))`, and
`atanh(acsc(U))` reduces for nonzero targets to `U = csc(tanh(t))`.
`atanh(sqrt(U))` reduces for `t >= 0` through `tanh(t)^2`;
`atanh(cbrt(U))` reduces for all real targets through `tanh(t)^3`; and
`atanh(abs(U))` reduces for `t >= 0` through both signed levels `±tanh(t)`.
Nested integer-root compositions with hyperbolic functions are reduced when
`root` has a constant nonzero integer degree: inverse levels are raised back
to the radicand, with even-degree sign constraints and reciprocal handling
for negative degrees. This covers `sinh`, `cosh`, `tanh`, `sech`, `csch`,
`coth` and their real inverse functions, as well as the real inverse
trigonometric functions, square/cube roots, absolute value, and exponential
and logarithmic functions. Error-function compositions with `erf`, `erfc`,
`erfinv`, `erfcinv`, `erfi`, `erfcx`, and `dawson` also use their real inverse
levels, preserving bounded ranges and multiple Dawson preimages. Periodic
trigonometric compositions, including functions of `root(...)`, enumerate
attainable phase levels from bounded inner-expression ranges. If floating-point
spacing cannot distinguish adjacent phases, the solver raises
`RootSearchLimitException` rather than silently merging levels.
`asinh(acoth(U))`, `atanh(acoth(U))`, and `acsch(acoth(U))` reduce for
nonzero `t` to `U = coth(sinh(t))`, `coth(tanh(t))`, and `coth(csch(t))`.
`asech(acoth(U))` reduces for `t >= 0` to `U = coth(sech(t))`.
`asin(acoth(U))` reduces on `[-π/2, 0) ∪ (0, π/2]`, and
`atan(acoth(U))` on `(-π/2, 0) ∪ (0, π/2)`. `acos(acoth(U))` reduces on
`[0, π/2) ∪ (π/2, π]`, while `acot(acoth(U))` lies in
`(0, π/2) ∪ (π/2, π)`. The inverse levels are `coth(sin(t))`,
`coth(cos(t))`, `coth(tan(t))`, and `coth(cot(t))`, respectively.
`acot(asinh(U))` and `acot(atanh(U))` reduce on `(0, π)` through
`U = sinh(cot(t))` and `U = tanh(cot(t))`. `acot(acosh(U))` and
`acot(asech(U))` reduce on `(0, π/2]` through `cosh(cot(t))` and
`sech(cot(t))`. `acot(asec(U))` reduces on
`[atan2(1, π), π/2]` except at `atan2(1, π/2)`; `acot(acsc(U))` reduces on
`[atan2(1, π/2), π/2) ∪ (π/2, atan2(1, -π/2)]`.
`acot(asin(U))` reduces on
`[atan2(1, π/2), atan2(1, -π/2)]` to `U = sin(cot(t))`;
`acot(acos(U))` reduces on `[atan2(1, π), π/2]` to `U = cos(cot(t))`;
`acot(atan(U))` reduces on
`(atan2(1, π/2), atan2(1, -π/2))` to `U = tan(cot(t))`.
`asin(asech(U))` reduces on `[0, π/2]`, while `acos(asech(U))` and
`atan(asech(U))` reduce on `[0, π/2)`; their levels are `sech(sin(t))`,
`sech(cos(t))`, and `sech(tan(t))`. `asec(asech(U))` reduces on
`[0, π/2)` to `sech(sec(t))`, and `acsc(asech(U))` on `(0, π/2]` to
`sech(csc(t))`.
`acoth(asec(U))` reduces from `atanh(1/π)` upward except at
`atanh(2/π)`; `acoth(acsc(U))` reduces when
`|t| >= atanh(2/π)`, using secant or cosecant levels of `coth(t)`.
`acoth(asin(U))` uses the same closed symmetric boundary; `acoth(atan(U))`
uses the strict symmetric range `|t| > atanh(2/π)`. `acoth(acos(U))`
reduces for `t >= atanh(1/π)`, while `acoth(acot(U))` requires
`t > atanh(1/π)` because `acot` excludes π. Their inverse levels are
`U = sin(coth(t))`, `tan(coth(t))`, `cos(coth(t))`, and
`cot(coth(t))`, respectively.
`acoth(sinh(U))` reduces for nonzero `t` through a stable `asinh(coth(t))`
level; `acoth(cosh(U))` reduces for `t > 0` to the two levels
`±log(coth(t/2))`. `acoth(tan(U))`, `acoth(cot(U))`, `acoth(sec(U))`, and
`acoth(csc(U))` reduce for nonzero targets through periodic `coth(t)` levels.
No real roots exist for `acoth(sin(U))`, `acoth(cos(U))`, `acoth(tanh(U))`,
or `acoth(sech(U))`, since those inner values never have magnitude greater
than one.
`acoth(exp(U))` and `acoth(expm1(U))` reduce for positive targets through
stable logarithmic levels; `acoth(ln(U))`, `acoth(log1p(U))`, `acoth(log2(U))`,
and `acoth(log10(U))` reduce for nonzero targets by applying their respective
exponential inverse to `coth(t)`.
`acoth(sqrt(U))` reduces for positive targets through `coth(t)^2`,
`acoth(cbrt(U))` for nonzero targets through `coth(t)^3`, and `acoth(abs(U))`
for positive targets through both signed levels `±coth(t)`.
`asinh(asec(U))` reduces on `[0, asinh(π)]` except at `asinh(π/2)`,
and `asinh(acsc(U))` reduces on `[-asinh(π/2), asinh(π/2)]` except zero,
using `U = sec(sinh(t))` and `U = csc(sinh(t))`, respectively.
`acosh(asec(U))` reduces on `[0, acosh(π)]` except at `acosh(π/2)`,
and `acosh(acsc(U))` reduces on `[0, acosh(π/2)]` through
`U = sec(cosh(t))` and `U = csc(cosh(t))`, respectively.
`asin(asec(U))` and `acos(asec(U))` reduce on `[0, π/2]` through
`U = sec(sin(t))` and `U = sec(cos(t))`. `asin(acsc(U))` reduces on
`[-π/2, 0) ∪ (0, π/2]`, while `acos(acsc(U))` reduces on
`[0, π/2) ∪ (π/2, π]`, using `U = csc(sin(t))` and `U = csc(cos(t))`.
`asin(atanh(U))`, `acos(atanh(U))`, and `atan(atanh(U))` reduce to
`tanh(sin(t))`, `tanh(cos(t))`, and `tanh(tan(t))` on their principal ranges.
`asinh(atanh(U)) = t` reduces for all real `t` to `U = tanh(sinh(t))`, and
`acosh(atanh(U)) = t` reduces for `t >= 0` to `U = tanh(cosh(t))`.
`acosh(sinh(U)) = t` reduces for `t >= 0` to a stable level for `U`;
`acosh(tan(U))` reduces to tangent levels, and `acosh(sin(U))` or
`acosh(cos(U))` are attainable only at target zero.
`asech(acsch(U)) = t` reduces for `t >= 0` to the unique positive level
`U = csch(sech(t))`.
`acsch(asech(U)) = t` reduces for `t > 0` to the unique level
`U = sech(csch(t))`; zero is excluded at the inner singularity.
`asech(acosh(U)) = t` reduces for `t >= 0` to `U = cosh(sech(t))`;
`acsch(acosh(U))` reduces for `t > 0` to `U = cosh(csch(t))`.
`asech(sqrt(U))` and `asech(cbrt(U))` reduce for nonnegative targets through
`sech(t)^2` and `sech(t)^3`; `asech(abs(U))` reduces over the same range
through both signed levels `±sech(t)`.
`acosh(sqrt(U))`, `acosh(cbrt(U))`, and `acosh(abs(U))` reduce for
nonnegative targets through `cosh(t)^2`, `cosh(t)^3`, and both signed levels
`±cosh(t)`. `asinh(sqrt(U))` and `asinh(abs(U))` require nonnegative targets
and use `sinh(t)^2` or both signed `sinh(t)` levels; `asinh(cbrt(U))` uses
`sinh(t)^3` for every real target.
`acsch(asinh(U))` reduces for every nonzero `t` through
`U = sinh(csch(t))`; `acsch(asin(U))` has `|t| >= asinh(2/π)`,
and `acsch(atan(U))` has the strict bound `|t| > asinh(2/π)`.
`acsch(acos(U))` and `acsch(acot(U))` require positive targets, bounded below
by `asinh(1/π)` with equality included only for `acos`. The reciprocal
compositions use `csc`, `sec`, `cot`, or `tan` of `csch(t)` on the matching
inner principal ranges; `acsch(asec(U))` excludes `asinh(2/π)`, and
`acsch(acsc(U))` requires `|t| >= asinh(2/π)`.
`acsch(sqrt(U))` reduces for positive targets through `csch(t)^2`,
`acsch(cbrt(U))` for nonzero targets through `csch(t)^3`, and `acsch(abs(U))`
for positive targets through both signed levels `±csch(t)`.
`acsch(exp(U))` reduces for positive targets through a stable logarithm of
`csch(t)`. `acsch(expm1(U))` reduces for positive targets and for
`t < -asinh(1)`, using a factored logarithm near the negative range boundary.
`acsch(ln(U))`, `acsch(log1p(U))`, `acsch(log2(U))`, and
`acsch(log10(U))` reduce for nonzero targets by applying their exponential
inverses to `csch(t)`.
`asinh(acsch(U))` reduces for nonzero targets to `U = csch(sinh(t))`.
`asin(acsch(U))` and `atan(acsch(U))` have their usual bounded principal
ranges with zero removed; `acos(acsch(U))` and `acot(acsch(U))` also exclude
π/2. Their levels are `csch(sin(t))`, `csch(tan(t))`, `csch(cos(t))`, and
`csch(cot(t))`, respectively.
For every `t >= 0`, `asech` composed with `asinh`, `asin`, `acos`, `atan`,
`acot`, `asec`, or `acsc` reduces through `sech(t)`, respectively to
`sinh(sech(t))`, `sin(sech(t))`, `cos(sech(t))`, `tan(sech(t))`,
`cot(sech(t))`, `sec(sech(t))`, or `csc(sech(t))`.
`asech(atanh(U)) = t` reduces for `t >= 0` through `U = tanh(sech(t))`,
including the attained zero output.
`asinh(asech(U)) = t` reduces for `t >= 0` through the stable level
`U = sech(sinh(t))`, including its root at `t = 0`.
`atanh(asech(U)) = t` reduces for `t >= 0` to `U = sech(tanh(t))`,
including the root `U = 1` at zero.
`atanh(sech(U)) = t` reduces for `t > 0` to the two symmetric inverse-sech
levels; zero is excluded because `sech(U)` is positive at every finite input.
`asinh(sech(U)) = t` reduces on `(0, asinh(1)]`, with symmetric levels that
merge to the single root zero at the upper endpoint.
`asin(sech(U)) = t` and `acos(sech(U)) = t` reduce over `(0, π/2]` and
`[0, π/2)`, respectively, merging the symmetric levels at the included ends.
`coth(asinh(U)) = t` reduces for `|t| > 1` to the signed reciprocal square
root of `t^2 - 1`; its boundary values are excluded.
`sin(asinh(U)) = t` and `cos(asinh(U)) = t` are reduced over their strict
real ranges, with both signs retained where the argument is symmetric.
`cosh(sinh(U)) = t` and `sinh(cosh(U)) = t` reduce to symmetric real argument
levels when their respective minimum target levels are attained.
`sech(atanh(U)) = t` reduces for `0 < t <= 1` to symmetric argument levels,
with a single root at `t = 1` and no roots at the unattainable zero boundary.
`asin(asinh(U)) = t` and `asinh(asin(U)) = t` reduce within their bounded
real ranges to `U = sinh(sin(t))` and `U = sin(sinh(t))`, respectively.
Equations applying `sinh` or `cosh` to `asin(U)` or `acos(U)` are inverted
within the corresponding principal-angle ranges; `cosh(asin(U))` retains both
symmetric argument branches.
`sinh(atan(U))` and `cosh(atan(U))` are inverted within the open principal
range of `atan`, retaining both symmetric branches for the latter.
`tan(acos(U))`, `cot(acos(U))`, and `cot(asin(U))` reduce to real argument
levels, with both ±1 endpoints retained when the target is zero.
For periodic arguments, `asinh(sin(U))`, `asinh(cos(U))`, `atanh(sin(U))`,
and `atanh(cos(U))` reduce to periodic sine or cosine levels when the target
is in range and the transformed `atanh` level remains strictly inside `(-1,1)`.
`atan(sin(U))`, `asin(tanh(U))`, and `atan(tanh(U))` also reduce to single
argument levels within their exact attainable output ranges.
The equations `asinh(tan(U)) = t`, `atanh(sinh(U)) = t`, and
`atanh(tan(U)) = t` reduce to tangent or hyperbolic-sine levels when their
inverse values remain finite and inside the real domains.
`atan(cos(U))`, `asin(cos(U))`, `acos(sin(U))`, `acos(tanh(U))`,
`tanh(asin(U))`, and `tanh(acos(U))` likewise
reduce to cosine, sine, or hyperbolic-tangent levels within their real ranges.
For sine or cosine inputs, `acot` is attainable only on `[π/4, 3π/4]`;
`asec` and `acsc` compositions reduce to their attainable boundary levels.
`acot(sinh(U))`, `acot(tan(U))`, and `acot(tanh(U))` also reduce through
cotangent levels, with strict endpoint exclusions for their real domains.
`asec(sinh(U))`, `asec(tan(U))`, `acsc(sinh(U))`, and `acsc(tan(U))`
reduce through reciprocal-trigonometric levels, excluding `asec`'s `π/2`
and `acsc`'s zero output.
`asec(cosh(U))` and `asec`/`acsc` applied to `csch(U)` or `coth(U)` also
reduce through reciprocal levels, distinguishing attained `csch` boundaries
from the open `coth` range.
`log1p(P(x)) = 0` reduces to `P(x) = 0`. The periodic zero equation
`sin(P(x)) = 0` is reported with `InfiniteSolutionSetException` when `P` is a
nonconstant polynomial, since this result type cannot enumerate infinitely
many complex roots. Every finite real level of `sinc(P(x))` and `sincpi(P(x))`
also has infinitely many roots for nonconstant polynomial `P`; their
first-order differential equations contradict finite-zero Hadamard
factorizations. The same applies to `besselj(n, P(x)) = 0`,
`besseli(n, P(x)) = 0`, and
`bessely(n, P(x)) = 0` for fixed integer order `n` when `P` is nonconstant.
For every finite real level, nonconstant polynomial arguments to `besselj` and
`besseli` have infinitely many complex roots; a finite-zero Hadamard
factorization would contradict their Bessel differential equations. Every
finite real level of `bessely(n, P(x))` also has infinitely many complex roots
for nonconstant polynomial `P`, from the large-argument sine asymptotics in a
logarithmically shifted sector. Constant arguments are checked directly;
numeric evaluation failures are returned as unconverged, except the known
singularity of `bessely(n, 0)`.
The reciprocal trig/hyperbolic functions `sec`, `csc`, `sech`, and `csch` have no
zeros over the complex numbers.
For real constant levels of `exp`, `expm1`, `sin`, `cos`, `tan`, `cot`,
`sinh`, `cosh`, `tanh`, `coth`, `sec`, `csc`, `sech`, and `csch` applied to
a nonconstant polynomial, the solver reports `InfiniteSolutionSetException`
when the level is in the function's complex range and returns no roots for
known omitted values. Constant arguments return either no roots or an infinite
solution set according to whether the equation is an identity.
The same infinite-preimage classification applies to every real level of
`erf(P(x))`, `erfi(P(x))`, `erfc(P(x))`, `erfcx(P(x))`, and `dawson(P(x))`
for nonconstant polynomial `P`. Finite-zero Hadamard factorizations contradict
the first-order differential equations for `erfcx` and Dawson. Constant
arguments are evaluated against the requested level.
For positive integer shape, `gammainc(a, P(x))` and `gammaincc(a, P(x))` have
infinitely many complex roots at every finite real level except `1` and `0`,
respectively, when `P` is nonconstant polynomial. These omitted levels reduce
to the zeros of the degree-`a-1` polynomial in `P(x)`,
`sum(P(x)^k / k!, k=0..a-1)`. Shape `1` has no roots at the omitted levels;
for larger shapes the finite complex roots are isolated when the composed
polynomial degree fits `SolverOptions::maxPolynomialDegree`, otherwise the
result is unconverged.
When both regularized incomplete-beta shapes are positive integers,
`betainc(a, b, P(x))` and `betaincc(a, b, P(x))` reduce to exact polynomials
in `P(x)` and their finite complex roots are isolated when the resulting
equations with polynomial right-hand sides are isolated when the resulting
degree fits `SolverOptions::maxPolynomialDegree`. Rational arguments and
rational right-hand sides are handled by the rational equation solver, with
original denominator poles excluded.
Every real level of `zeta(P(x))` also has infinitely many roots for
nonconstant polynomial `P`: values at negative odd integers alternate sign
and grow without bound between the trivial zeros at negative even integers.
Every real level of `digamma(P(x))` and `tetragamma(P(x))` has infinitely many
roots for nonconstant polynomial `P`, since the functions span the real line
between each pair of adjacent nonpositive-integer poles. The same applies to
`polygamma(n, P(x))` for order zero and positive even integer `n` through 128.
Every real level of positive odd-order polygamma has infinitely many roots for
nonconstant polynomial `P`. For nonzero levels, this follows from locally
uniform convergence near translated poles to the periodic cotangent-derivative
limit from the polygamma reflection formula. At zero level, orders `n >= 3`
have roots near translated poles; trigamma has roots in a logarithmically
shifted sector, where the reflection term balances the positive-half-plane
asymptotic `psi_1(z) ~ 1/z`. Specifically, near `z = -k + w` with
`Re(w) = 1/2` and `Im(w) ~ log(k)/(2*pi)`, the reflection term is of order
`1/k`; a local Rouché estimate then gives a zero near each sufficiently large
translated pole.
For `solveRationalComplex()`, a nonconstant rational argument to `exp()` or
`expm1()` likewise produces infinitely many complex solutions at every
non-omitted real level, so the solver reports `InfiniteSolutionSetException`.
For `solvePolynomialComplex()`, equal `exp()` or equal `expm1()` calls with
exact rational-polynomial phases are classified from their phase difference:
identical phases give an identity, a nonzero constant difference gives no
roots, and a nonconstant difference gives infinitely many complex solutions.
Mixed `exp(P)=expm1(Q)` calls (in either order) with a constant exact phase
difference have no solutions when the phases are identical, and infinitely many
complex solutions for a nonzero difference when the phases are nonconstant.
A constant phase sum with nonconstant phases also gives infinitely many complex
solutions. Constant-only equations are evaluated directly.
For equal `sin`, `cos`, `sinh`, or `cosh` calls with exact rational-polynomial
phases, a zero phase difference gives an identity; for `cos` and `cosh`, a zero
phase sum also gives an identity. Any nonconstant phase sum or difference gives
infinitely many solutions; otherwise there are no solutions.
Mixed `sin(P)=cos(Q)` or `cos(P)=sin(Q)` equations with exact rational-
polynomial phases have infinitely many solutions when either phase sum or
difference is nonconstant, and no solutions when both are constant.
Mixed pairs of `sin`/`cos` and `sec`/`csc`, in either order, with a
nonconstant exact rational-polynomial phase have infinitely many complex
solutions, except that a zero constant sine/cosine side or undefined constant
reciprocal side gives no roots. Clearing the reciprocal forces its denominator
away from poles; constant-only equations are evaluated directly.
Mixed pairs drawn from `sin`, `cos`, `sinh`, and `cosh`, with one trigonometric
and one hyperbolic function, have infinitely many complex solutions whenever
at least one exact rational-polynomial phase is nonconstant; these entire
functions have infinitely many preimages of every complex level.
Mixed `sec(P)=csc(Q)` or `csc(P)=sec(Q)` equations with nonconstant exact
rational-polynomial phases have infinitely many complex solutions, except for
constant half-period relations: some make the calls identical on their common
domain, while the opposite relations yield only undefined reciprocal poles.
With one constant phase, an undefined constant reciprocal has no solutions; a
defined constant reciprocal equals the nonconstant periodic side infinitely
often. Two constant phases are evaluated directly.
Mixed `tanh(P)=coth(Q)` equations with a constant real polynomial phase
difference have no solutions, since they would require `cosh(P-Q)=0`. With a
nonconstant difference and one constant phase, there are infinitely many roots
unless the constant `coth()` phase is zero, where the equation is undefined.
With two nonconstant phases, a constant sum and nonconstant difference gives
infinitely many defined solutions from the zeros of `cosh(P-Q)`.
Mixed `tanh`/`coth` versus `sech`/`csch` equations are classified when either
phase is constant: impossible zero reciprocal/tangent levels and the omitted
`tanh`/`coth` levels `-1` and `1` have no solutions; other defined constant
levels have infinitely many preimages. Two nonconstant phases remain
unclassified to preserve reciprocal-function pole constraints.
For `tan(P)=cot(Q)` in either order, one constant real phase and one
nonconstant polynomial phase gives infinitely many solutions, except when the
constant is the zero `cot()` argument, which is undefined.
With two nonconstant exact rational-polynomial phases, a constant phase sum
gives no roots because it cannot equal `pi/2` modulo `pi`.
Equal `sec()` or `csc()` calls with nonconstant phases classify constant phase
sums and differences against their periods: identity relations give infinitely
many roots on the common domain, while pole-only relations give no roots.
Other nonconstant phase pairs have infinitely many complex solutions. Equal
`sech()` calls with nonconstant polynomial phases likewise have infinitely
many complex solutions; poles of the original reciprocal expressions remain
excluded.
Mixed `cosh(P)=sech(Q)` has infinitely many defined solutions when one phase
is constant and the other is nonconstant, or when nonconstant phases have a
constant sum or difference. It also has infinitely many complex solutions
when both phases are nonconstant and neither their sum nor difference is
constant; the product equation excludes reciprocal poles. Mixed
`sech(P)=csch(Q)` has no solutions when
nonconstant phases have an identically zero sum or difference; nonzero constant
phase combinations give infinitely many roots. One constant phase also gives
infinitely many roots except when it is a zero `csch()` argument.
For mixed `sinh(P)=csch(Q)` and `cosh(P)=csch(Q)`, a constant phase sum or
difference with both phases nonconstant gives infinitely many solutions. With
one constant phase, `sinh(P)=csch(Q)` has no roots if the constant `sinh()`
value is zero or the constant `csch()` argument is zero; `cosh(P)=csch(Q)` has
no roots only when the constant `csch()` argument is zero. Mixed
`sinh(P)=csch(Q)` also has infinitely many complex solutions when both phase
sum and difference are nonconstant; the product equation forces `sinh(Q)` to
be nonzero at every candidate, preserving the `csch()` domain. The same
nonconstant phase-sum-and-difference classification applies to
`cosh(P)=csch(Q)`; its product equation likewise excludes the `csch()` poles.
Mixed
`sinh(P)=cosh(Q)` has infinitely
many solutions when exactly one phase is constant; with two nonconstant phases,
a zero constant sum or difference gives no solutions, while a nonzero constant
combination gives infinitely many. If both phase sum and difference are
nonconstant exact rational polynomials, it also has infinitely many complex
solutions. Mixed `sinh(P)=sech(Q)` has infinitely many
solutions when one phase is constant except when the constant `sinh()` value is
zero; a constant phase sum or difference also gives infinitely many solutions.
If both phases are nonconstant and neither their sum nor difference is
constant, the equation likewise has infinitely many complex solutions.
Matching `tan()` and `cot()` calls whose phases differ by an integer multiple
of `pi` agree on their common domain; other constant phase differences give no
roots. Matching `tanh()` and `coth()` calls have the analogous identity only
for identical real phases, since their complex period is imaginary. A
nonconstant phase against a defined constant level gives infinitely many
roots, except for omitted `tanh()`/`coth()` levels `-1` and `1`. Constant
equations are evaluated directly, and zero phases of `cot()` and `coth()` have
empty domain. For matching `cot()` or `coth()`
calls with two nonconstant phases, a constant phase sum and nonconstant
difference gives infinitely many valid complex roots. Opposite nonconstant
`coth()` phases have infinitely many roots from the zeros of hyperbolic
cotangent. For matching `csch()` calls, an
identically zero phase sum gives no roots; a nonconstant phase sum, or a
nonconstant phase difference with constant nonzero sum, gives infinitely many
roots. Matching `tan()` or `tanh()` calls with two nonconstant phases and a
constant sum also have infinitely many valid roots. Two affine
rational-polynomial `tan()` or `tanh()` phases with a nonconstant difference
are likewise classified. Other matching nonconstant meromorphic phase pairs
remain unclassified.
Complex absolute-value equations use modulus semantics: `abs(R(x)) = 0`
reduces to `R(x) = 0` and retains complex roots, while a positive level for a
nonconstant rational `R` has infinitely many complex solutions. Constant
rational arguments are compared to the level exactly. For rational `F` and `G`,
`abs(F(x)) = abs(G(x))` is classified using their exact ratio: a nonconstant
ratio or a constant ratio of modulus one gives infinitely many solutions; any
other constant ratio reduces to their common zeros, excluding poles from either
original argument.
Matching signed principal square roots reduce exactly: equal signs in
`±sqrt(F(x)) = ±sqrt(G(x))` reduce to `F(x) = G(x)`; opposite signs can agree
only where both radicands are zero. Rational-domain exclusions are preserved.
For complex equation solving, `hypot(a, b)` uses the principal algebraic
extension `sqrt(a^2 + b^2)` and reduces to that squared equation for
nonnegative real levels; it is distinct from modulus-based `abs()`.
Equal `exp()` calls and equal `expm1()` calls with rational-function arguments
are classified from their exact phase difference: an identical phase gives an
identity on its domain, a nonzero constant difference gives no roots, and a
nonconstant difference gives infinitely many complex solutions. Mixed `exp()`
and `expm1()` calls with nonzero constant rational amplitudes use a separate
exact reduction when their rational phase difference is constant: identical
phases with equal amplitudes have no roots, identical phases with unequal
amplitudes and a nonconstant phase give infinitely many, and a nonzero constant
difference with a nonconstant `expm1()` phase and exact rational phase
expressions gives infinitely many. When the phases instead have a constant sum
and a nonconstant rational phase, a quadratic exponential reduction also proves
infinitely many roots. Rationally proportional phases reduce to a polynomial
in a fractional exponential level; this gives infinitely many roots except
when the exponential phase is zero and the amplitudes sum to zero, which has no
roots.
For `solveRationalComplex()`, equalities between nonzero constant multiples of
`exp()` calls with rational-function phases are classified exactly: a
nonconstant phase difference gives infinitely many complex solutions, a zero
difference is an identity only when the amplitudes match, and a nonzero
constant difference gives no roots. Matching scaled `expm1()` calls are also
classified when amplitudes match, or when exact rational phase expressions
differ by a constant and the right phase is nonconstant; other scaled
`expm1()` forms remain unclassified.
Unequal scaled `expm1()` calls with rationally proportional nonconstant phases
also have infinitely many roots where both phases meet exponential period
levels.
Unequal scaled `expm1()` calls with nonconstant rational phases whose sum is
constant are classified as having infinitely many roots by reducing the
equation to a quadratic in the exponential of one phase.
Equalities between equal nonzero constant rational multiples of `sin()`/`cos()`
calls, or matching `sinh()` or matching `cosh()` calls, with rational-function
arguments are classified as having infinitely many complex solutions when
either the exact phase sum or difference is nonconstant. Identical phases and
the even-function identities at opposite phases are also recognized. At
opposite phases, matching `sin()` and `sinh()` reduce to their zero levels:
nonconstant rational phases have infinitely many complex roots, while a
nonzero exact rational phase has none. Other matching equations with exact
constant rational phases are classified as empty unless the phases are
identical or are opposite for an even function. Named constants such as `pi`
are not treated as exact rational phases by this rule. Mixed `sin()`/`cos()`
calls with exact rational constant phases are also classified as empty. Mixed
`sinh()`/`cosh()` calls with identical or opposite exact rational phases are
classified: constant phases give no roots, and nonconstant phases have no roots
for equal or opposite amplitudes, but infinitely many for other nonzero
rational amplitudes. Nonconstant rational phases with a nonzero constant sum or
difference also give infinitely many roots. Other mixed `sinh()`/`cosh()` phase
relations with rationally proportional nonconstant phases of unequal absolute
weights are classified as infinite as well. Remaining mixed `sinh()`/`cosh()`
phase relations are unclassified. Meromorphic periodic functions are excluded
from that general phase rule because their poles can remove an entire period
branch. Matching rational `tan()`, `cot()`, `tanh()`, `coth()`, and `csch()`
calls are classified separately for identical phases, nonzero constant phase
differences, and a constant phase paired with a nonconstant rational phase;
identically zero `cot()`, `coth()`, and `csch()` phases have empty domain. For
`csch()`, a nonconstant phase sum with constant nonzero phase difference also
gives infinitely many solutions through its second equality branch. For `tan()`,
two affine polynomial phases with a nonconstant difference also have infinitely
many solutions away from poles. The same affine classification applies to
`tanh()`. Opposite rational phases make matching `csch()` calls have no roots;
matching `coth()` calls have infinitely many roots for a nonconstant phase and
none for a constant real phase.
For matching or opposite rational phases, mixed scaled `tan()`/`cot()` calls
reduce to a squared tangent target: nonconstant rational phases have infinitely
many roots except when that target is `-1`, which is omitted by complex tangent;
exact rational constant phases have none. The analogous mixed `tanh()`/`coth()`
equations have no roots when the target is `1` or `-1`, the omitted hyperbolic-
tangent values; otherwise nonconstant rational phases give infinitely many
roots, while exact rational constants give none.
For equal-amplitude rational-phase `sec()`, `csc()`, and `sech()` equations,
identical phases are recognized on their common domain; opposite phases are
identities for the even `sec()` and `sech()`, while opposite `csc()` phases
have no solutions. For matching or opposite rational phases, mixed
`sec(P)=csc(±P)` has infinitely many roots for nonconstant rational `P` and
none for exact rational constants. Mixed `sech(P)=csch(±P)` has no roots when
the nonzero constant scales have equal magnitude; with unequal magnitudes it
has infinitely many roots for nonconstant rational `P` and none for exact
rational constants. Nonzero constant sums or differences between nonconstant
`sech()`/`csch()` phases give infinitely many roots. Rationally proportional
nonconstant `sech()`/`csch()` phases with unequal absolute integer weights also
have infinitely many roots.
Mixed scaled pairs between `sec()`/`csc()` and
`sech()`/`csch()` with identical or opposite exact rational phases have
infinitely many roots for nonconstant rational phases. Exact rational constant
phases have no roots, except equal scaled `sec(0)=sech(0)`, which is an
identity.
The same classification applies to nonconstant rational arguments to `sin`,
`cos`, `tan`, `cot`, `sinh`, `cosh`, `tanh`, `coth`, `sec`, `csc`, `sech`, and
`csch`: non-omitted real levels have infinitely many complex preimages, while
the known omitted levels return no roots. Constant rational phases are checked
directly; numeric evaluation failures remain unconverged, except for the known
zero poles of `cot`, `csc`, `csch`, and `coth`.
Every finite real level of `sinc(R(x))` and `sincpi(R(x))` has infinitely many
complex solutions for nonconstant rational `R`: the entire phase functions
have infinitely many preimages of every level, and a nonconstant rational map
omits at most one finite value.
Nonconstant rational arguments to `erf`, `erfc`, and `erfi` have infinitely
many complex preimages at every real level. Every real level of Dawson with a
nonconstant rational argument has infinitely many complex solutions. Every
finite real level of `erfcx(R(x))` also has infinitely many complex solutions
for nonconstant rational `R`, by the same finite-zero factorization argument
and the fact that a nonconstant rational map omits at most one finite value.
Constant rational error-function and Dawson arguments are checked when numeric
evaluation succeeds; otherwise the result is unconverged. For fixed integer
order, every finite real level of `besselj(n, R(x))`, `besseli(n, R(x))`, and
`bessely(n, R(x))` with nonconstant rational `R` has infinitely many complex
solutions. For `bessely`, the large-argument sine asymptotics supply infinitely
many target values, and a nonconstant rational map omits at most one finite
value. Constant rational arguments are checked on their defined domain;
numeric evaluation failures remain unconverged except for the known
`bessely(n, 0)` singularity.
For nonconstant rational `R`, `gamma(R(x)) = 0` has no solutions because the
Gamma function has no zeros, while every nonzero real level has infinitely
many complex solutions. Constant rational arguments are checked on their
defined domain. For positive integer shape, `gammainc(a, R(x))` and
`gammaincc(a, R(x))` have infinitely many complex solutions at every
non-omitted real level when `R` is nonconstant rational; their omitted levels
are `1` and `0`, respectively. At shape `1` the omitted levels have no roots;
for larger integer shapes their finite roots are isolated when the composed
numerator degree fits `SolverOptions::maxPolynomialDegree`, with denominator
poles excluded; otherwise the result is unconverged. Constant rational
arguments are checked on their defined domain.
For positive integer shapes `a` and `b`, `betainc(a, b, R(x))` and
`betaincc(a, b, R(x))` reduce to exact rational-polynomial equations; their
complex roots are isolated within the configured degree, excluding poles of
`R` and of rational right-hand sides. Polynomial right-hand sides are also
supported by the polynomial equation solver.
For every real level `c`, `digamma(R(x)) = c` and
`polygamma(n, R(x)) = c` for nonnegative even integer `n` have infinitely
many complex solutions when `R` is nonconstant rational: each interval between
adjacent nonpositive-integer poles has opposite infinite one-sided limits.
Every real level of positive odd-order polygamma through 127 has infinitely
many solutions for nonconstant rational `R`. Nonzero levels and zero levels
for orders `n >= 3` follow from the periodic cotangent-derivative limit near
translated poles. At trigamma level zero, roots instead occur in a
logarithmically shifted sector where the reflection term balances
`psi_1(z) ~ 1/z`; a local Rouché estimate gives roots near infinitely many
translated poles.
The aliases `trigamma(R(x))` and `tetragamma(R(x))` are classified as
`polygamma(1, R(x))` and `polygamma(2, R(x))`, respectively.
For every real level `c`, `zeta(R(x)) = c` has infinitely many complex
solutions for nonconstant rational `R`: zeta takes every real value infinitely
often between its negative even trivial zeros, since its values at negative
odd integers alternate sign and grow without bound. A nonconstant rational
map omits at most one finite complex value, so infinitely many of those real
preimages remain attainable through `R`.
For a real constant level `c`, `ln(P)=c` reduces to `P=exp(c)`,
`log1p(P)=c` to `P=expm1(c)`, and logarithms with constant valid bases reduce
to the corresponding exponentiated level. Underflowed and overflowed targets
produce an empty root list with `converged` false rather than being rounded to
zero or infinity and reported as a valid root.
For an exact rational level `p/q`, equations `log(A(x), B(x)) = p/q` with a
variable polynomial or rational base reduce algebraically to `B^p = A^q` (or
`A^q*B^(-p)=1` when `p < 0`). Candidates are retained only when the original
real logarithm is defined, including its positive argument, positive-base,
and non-unit-base requirements. An identity such as `log(x,x)=1` is reported
as an infinite solution set.
When `A` is a positive constant, the same variable-base equations also support
any finite real level `c != 0` by reducing to `B(x) = exp(log(A)/c)`; this
covers irrational levels such as `pi` without approximating them as rationals.
If this reduction produces a recognized periodic complex equation with a
nonconstant argument, the solver reports its infinite solution set; unsupported
transformed forms return an empty result with `converged` false.

```php
$result = Math::solvePolynomialComplex('x^2 + 1 = 0', 'x');
$result->converged; // true
$result->roots[0]->real; // approximately 0
$result->roots[0]->imaginary; // approximately -1
$result->roots[0]->residual; // scale-normalized backward residual
```

Use `Math::solveRationalComplex()` for rational equations with exact rational
coefficients and evaluable variable-free constants. It solves the numerator
polynomial and uses an exact polynomial GCD to exclude numerator roots that
are also poles. Variable-free expressions such as `pi` or `sqrt(2)` are
evaluated and their resulting floats are encoded as exact decimal rationals for
the polynomial and GCD calculations. Consequently, pole checks are exact for
those evaluated values, not symbolic proofs about the underlying
transcendental constants. Roots retain numerator multiplicity, except
excluded poles. The returned residual is the numerator polynomial's
scale-normalized backward residual; numerical convergence does not certify
completeness. Chained rational-polynomial equalities intersect their exact
numerator root sets and exclude poles from every link.

```php
$solution = Math::solveRationalComplex('(x^2 - 1) / (x - 1) = 0', 'x');
// The root -1 is returned; x=1 is excluded because it is a pole.

$shared = Math::solveRationalComplex('x^2 = 1 = 2*x - 1', 'x');
// The shared root 1 is returned.
```

For a polynomial `p(z) = sum(a[i] * z^i)`, the reported residual is
`abs(p(z)) / max(1, sum(abs(a[i]) * abs(z)^i))`. This scale-aware backward
error makes residuals comparable across roots of different magnitudes. A small
backward error does not guarantee small forward error for an ill-conditioned
root, and `converged` does not certify that the numerical method found every
root.

For polynomials at or below `SolverOptions::maxPolynomialDegree`, the solver
partitions at numerically isolated derivative roots and finds real roots in the
requested interval, including repeated roots detected at critical points.
This is not a formal completeness proof: ill-conditioned and closely spaced
roots can exceed floating-point resolution, and candidates are verified
against the original equation's residual tolerance. For direct inverse-trig
and inverse-hyperbolic equations near domain boundaries, the solver can search
nearby representable inputs for a lower residual even when it remains above
tolerance; the reported residual remains the actual evaluated residual. Direct
inverse-error-function levels and nested `erf()`/`erfc()` inverse compositions
are reduced analytically, including polynomial arguments. Direct
`zeta(x) = c` equations over intervals contained in `[0, 2]` that cross the
pole can likewise return the nearest representable root when the mathematical
root lies between adjacent floats, but reject it when farther than half an ULP.
Integer-coefficient polynomial equations with integer literals and integer
parameters are reduced using exact decimal-integer coefficients before the
floating-point polynomial path, preserving coefficient differences beyond
native integer and float precision.
Higher-degree polynomials containing translated integer powers are re-expanded
in affine coordinates over the requested interval before root isolation, which
helps preserve roots in expressions such as `(x - 100)^10 - 1 = 0`.
Additive constants and nonzero constant scalar factors around powers of
nontrivial polynomial bases are isolated before solving, preserving both real
branches for even powers, as in `(x^2 - 10000)^4 - 1 = 0`.
Equations containing one exact rational power `p/q`, `sqrt()`, `cbrt()`, or
constant-degree `root()`, with a polynomial base and polynomial remaining
terms, can be reduced to bounded polynomial equations. Multiple such terms
sharing an affine base are also reduced when the remaining terms are constant.
Examples include `sqrt(x) + x = 6` and `sqrt(x) + cbrt(x) = 3`; resulting roots
are checked against the original expression and its real-domain rules,
including for even-denominator powers.
Powers with a finite, variable-independent non-rational exponent, such as
`x^sqrt(2) = 4`, are also inverted for constant targets on the positive-base
real domain; the transformed base-level candidates are checked against the
original equation. Negative targets are rejected for these exponents because
the evaluator does not define non-rational powers of negative real bases.
When the target equals the power's base expression, `u^a = u` reduces to
`u = 1`, with `u = 0` included as well when `a > 0`.
Sums with four through six distinct polynomial cube-root radicands are also
eliminated through a bounded quotient basis; identical real cube-root terms
and radicands differing by an exact scalar cube are combined before applying
that limit.
Sums or differences with up to eight distinct scalar-multiplied square roots
with polynomial radicands and a polynomial remainder are also reduced to
polynomial equations; repeated identical radicands are combined, and candidates
from squaring are filtered against the original equation.
For two-root sums, direct `abs()` polynomial radicands are partitioned at their
zeros and lifted separately on each sign region.
The two-root reduction also absorbs polynomial coefficients into the radical
polynomials, for example in `(x - 2) * sqrt(abs(x)) + cbrt(x + 7) = 1`.
Products of three or more square roots with polynomial radicands and a
polynomial target are reduced by multiplying their radicand polynomials and
squaring once; candidates are checked against the original equation and all
radical domains.
Sums containing up to eight products of square roots are combined with
polynomial remainders through the same bounded multiquadratic elimination.
Products of simple rational roots with polynomial or rational-function
radicands and targets, such as
`sqrt(x + 3) * cbrt(x + 7) = 4` or
`root(x + 15, 4) * cbrt(x + 7) = 4`, are lifted to polynomial equations using
the least common multiple of their root degrees. Candidates are verified
against the original real branches and domains. Negative rational exponents
are lifted as reciprocals, with base-zero holes excluded by the original
expression check. Direct `abs()` radicands that are rational functions are
partitioned at their zeros and poles so each branch is lifted with a consistent
sign.
On all-real intervals, equations `log_b(x) = k*x^(p/q) + c` are reduced
analytically for a positive fixed base `b != 1`, exact rational exponent
`p/q`, and finite variable-independent coefficients `k` and `c`. This includes
`ln()`, `log2()`, and `log10()`; the additive-constant case uses real
Lambert-W branches. Candidates are retained only when representable and
verified against the original equation. Near-tangent cases whose branch count
cannot be classified reliably are deferred to the general solver path.
For other equations, the solver samples the interval, bisects sign-changing
brackets, partitions at detected derivative sign changes, and refines sampled
local minima of the absolute residual. Stationary-point partitioning can find
paired roots inside a scan cell and roots that touch zero, but sampling alone
cannot guarantee that every root is found. The final numerical fallback uses
interval arithmetic to certify the complete root list, including when the scan
finds no candidates. Unsupported or unresolved interval expressions raise
`RootSearchLimitException` instead of returning a possibly partial list or
reporting an uncertified root-free interval. Increase `scanSteps` in
`SolverOptions` for a denser candidate search; values from 1 through 65,536 are
allowed, at increased evaluation and memory cost. When symbolic reductions decline an
equation with at least four variable-dependent cube-root terms, fallback uses
at least 256 steps and scales by 64 steps per term. For periodic functions with polynomial
arguments, fallback sampling is increased according to the argument's total
variation over the interval to a bounded internal limit. For non-polynomial
trigonometric arguments that are not rational functions, fallback resolution
also scales with the largest phase step observed over a bounded subdivision;
if the estimated scan requirement exceeds the internal limit, the solver raises
`RootSearchLimitException` rather than silently clamping the scan. The sampled
phase estimate is not a proof that an arbitrary non-polynomial phase has no
unobserved rapid oscillations; final interval certification remains required.
Direct periodic trigonometric equations
such as `sin(3*x + 1) = 0.5` enumerate polynomial-argument phase levels over
the interval independently of the scan grid. Polynomial phases in `tan()` and
`cot()` accept any finite rational target; only `sin()`, `cos()`, `sec()`, and
`csc()` require targets within their respective real ranges. Trigonometric
functions of exponentials of polynomial arguments, such as
`sin(exp(x^2)) = 0`, enumerate
their phase levels by analytic inversion. This also handles arbitrarily nested
exponentials of polynomials and polynomial square roots.
Fixed-integer-order `besselj` and `bessely` expressions with polynomial
arguments also raise fallback scan density according to argument total
variation. If the estimated requirement exceeds the internal scan limit, the
solver raises `RootSearchLimitException` instead of returning a scan-limited
candidate list.
Affine periodic equations that require more than 65,536 root candidates throw
`RootSearchLimitException`; they do not fall through to a scan that could
silently return a partial root list.
When numerical fallback contains a user-defined function without an interval
extension, it throws `RootSearchLimitException` instead of treating a finite
set of samples as a complete root list. This applies even when samples found
roots: arbitrary additional features cannot be ruled out by sampling alone.
Periodic functions with rational-function arguments, such as `sin(1/x) = 0`,
enumerate polynomial phase-level equations when the argument has no genuine
poles inside the solving interval. Shared numerator and denominator factors at
removable holes are canceled for range and phase-level analysis, while
candidates are checked against the original denominator and expression so
undefined points remain excluded.
Nested sine, cosine, and tangent equations also check whether an outer inverse
phase level contains an inner rational-periodic zero set that accumulates at a
pole.
Affine equations for `sinc` and `sincpi` with constant targets in `[-1, 1]`
enumerate monotone lobe branches independently of the scan grid, including
tangent roots at side-lobe extrema. On unbounded intervals, nonzero constant
targets with nonconstant polynomial arguments enumerate the finitely many
attainable lobe levels and solve their polynomial preimages; zero targets have
infinitely many roots. Reciprocal identities where the phase times the
rational target is constant, including `sinc(P) = c/P`, reduce to sine levels;
the original equation's denominator holes are preserved, and impossible
targets or infinite root sets are classified. Zero equations with polynomial arguments enumerate
exact nonzero integer or `pi`-multiple phase levels independently of the scan
grid.
Rational-function arguments to `sinc` and `sincpi` are solved by inverting
nonzero targets across their monotone lobes, including intervals spanning
rational phase poles; the sinc envelope bounds the required phase levels.
Zero targets enumerate the nonzero `pi`-multiple and integer phase levels,
respectively.
Equations written as `sin(p(x))/p(x) = c` use the same phase inversion when
`p(x)` is a supported polynomial or rational expression; the original
quotient's undefined points remain excluded.
Trigonometric functions of square roots of polynomial arguments enumerate
squared phase levels. Outer sine,
cosine, and tangent functions of inner sine/cosine expressions are reduced over
the inner function's bounded range before solving its levels. Sine, cosine,
and related periodic functions of logarithms of affine or interval-positive
polynomial arguments also enumerate their phase levels, including roots
clustered near zero. At a pole of a rational periodic phase, rational scales
and offsets are analyzed for infinite root accumulation when the limiting
target lies in an open attainable range or approaches a range boundary from
the attainable side. Logarithms
of positive products, quotients, and nested powers of exponential terms are reduced to
polynomial arguments when possible.
Periodic phases that diverge at interval endpoints or rational singularities
through reciprocals, logarithms, square roots, or exponentials are checked for
infinite root accumulation before finite numerical scanning. Decaying
exponential phases with rational nonpositive exponents are range-checked to
avoid interpreting floating-point underflow as an interval of roots.
Bounded products of sine and cosine factors with polynomial arguments are
reduced with product-to-sum identities before root isolation. Supported
exponential, logarithmic, root, absolute-value, sign, error, hyperbolic, and inverse
trigonometric functions of polynomials are inverted into polynomial level
equations. This includes polynomial radicands with constant integer `root()`
degrees and reciprocal inverse hyperbolic functions. Equations that set such a
`root()` expression equal to a variable expression are reduced to power
equations, then checked against the original principal-root branch and domain.
Transformed identities are classified against the principal-root branch, so
infinite intervals, isolated zero targets, and inadmissible intervals are
distinguished instead of relying on sampled finite roots.
Supported inverse-function equations with rational arguments are also reduced
to polynomial levels, with candidates at original denominator holes excluded.
Supported scalar inverses are applied layer by layer for nested equations such
as `exp(sin(x)) = 2` and `sqrt(abs(x - 1)) = 2`, so their inner levels can use
the same analytic or polynomial solver paths instead of depending on the scan.
Equal `root()` calls with the same degree expression, including algebraically
equivalent integer-polynomial degrees and differently written equal constants,
are reduced to equality of their radicands; candidates are still checked
against each root's real domain, including even-root and negative-degree
singularities.
Equal `log()` calls with the same base expression, including algebraically
equivalent integer-polynomial bases, are reduced to equality of their
arguments; original logarithm domains are checked at every candidate.
When `log()` has a constant value and variable base, its base level is isolated
before recursively solving the base expression, with the logarithm's domain
and infinite zero-level case preserved. Equations with variable values or
bases reduce to `value(x) = base(x)^target`, including variable targets; roots
are checked against the original logarithm domain, and transformed identities
are reported as infinite only when a valid open domain region exists.
`hypot()` equations with
polynomial or rational arguments are reduced to polynomial levels, and `atan2()`
polynomial or rational-coordinate candidates are filtered by the principal angle's quadrant.
When one `atan2()` coordinate is a nonzero constant and the other is a
non-rational, non-polynomial expression, the variable coordinate is reduced
to its tangent or cotangent level on the matching principal-angle branch;
candidate values are rechecked against the original `atan2()` equation.
When either constant coordinate is zero, the principal-axis outputs are
classified over the corresponding strict-sign intervals, including infinite
solution sets and exclusion of the undefined origin.
For a non-rational, non-polynomial variable coordinate and a constant other
coordinate, `hypot(f(x), c) = t` reduces to the two levels
`f(x) = ±sqrt(t^2 - c^2)` (one level at equality) when `t >= |c|`; the
level is evaluated in a scaled, factorized form to avoid overflow and
cancellation near the boundary.
Equalities between matching two-argument `hypot()` calls reduce to equality of
the sums of squared coordinates, also when either call is wrapped in `abs()`.
They also reduce against `sqrt()` of a structurally nonnegative expression,
including sums and positive rational multiples of squares, preserving the real
square-root domain.
`min()` and `max()`
equations with rational arguments and rational targets are isolated branch by
branch, including poles and infinite solution intervals. Isolated `floor()`,
`ceil()`, `round()`, and `trunc()` boundary roots for polynomial and rational arguments
are solved without depending on the scan grid, with poles retained as domain
breaks. Rounding terms with polynomial coefficients and a polynomial remainder
are solved by enumerating integer levels and checking discontinuity boundaries,
including equations such as `x*floor(x) + x*ceil(x) = 2*x^2`. Polynomial
same-argument sums `floor(g) + ceil(g)` reduce odd targets to open ranges of
`g` and even targets to a single level equation; rational poles and removable
holes are retained in the resulting interval sets. Polynomial
and finite-range rational arguments to `gcd()` and `lcm()`
are searched over bounded integer levels, with machine-roundoff-sized integer
normalization; candidates are verified against the original expression. For
constant-operand rational equations at genuine poles, finite `lcm()` divisor
levels are enumerated, while infinite `gcd()` root accumulation is reported.
On unbounded intervals, one nonconstant polynomial argument with a constant
operand is also handled: compatible `gcd()` levels are classified as infinite,
and finite `lcm()` divisor levels are isolated through polynomial roots.
Unbounded polynomial arguments to `binomial()`, `permutation()`, and
`doublefactorial()` are reduced to their finite integer output levels; zero
plateaus from a polynomial argument tending to positive infinity are reported
as infinite solution sets.
`sign()` of polynomial and rational arguments isolates zero-level roots and
classifies nonzero levels across sign intervals, reporting nonempty regions as
infinite solution sets even when adjacent boundaries have no representable
floating-point midpoint.
Zero-product equations solve each variable-dependent factor separately;
zero-quotient equations reduce to their numerator, and reciprocal powers add no
zero levels. Candidates are verified against the original expression to
preserve its domain. Positive powers of non-polynomial bases are reduced to
their base before generic root isolation, avoiding flat residual neighborhoods
around repeated roots. Factor candidates within the configured position
tolerance of a denominator zero are excluded as domain holes.
Zero-target `hypot()` equations combine roots of their two coordinates and
verify shared zeros in the original expression, including non-rational
coordinates whose roots do not change sign.
Powers with a constant real base and polynomial exponent are reduced to
polynomial level equations. Positive bases use logarithmic inversion; negative
bases enumerate integer exponent levels. Polynomial bases that stay strictly
negative over the interval use the same integer-level reduction; sign-changing
polynomial bases can use it for negative targets, which cannot arise from a
nonnegative base. Zero bases honor the evaluator's `0^0 = 1` convention while
reporting intervals of zero-valued solutions as infinite. A polynomial base
equaled to zero is isolated directly for zero targets, then filtered by the
exponent's sign and configured magnitude limit.
Equations of the form `a*u^n*ln(u) + b*u^n = c` or
`a*u^n*log(u, B) + b*u^n = c`, where `n` is a finite nonzero real number and
`u` is a polynomial or rational function, are inverted through the real Lambert
W branches and solved as polynomial levels. The real exponent `n` must also be
within the configured polynomial-degree limit; `B` must be a positive constant
other than one. This includes shifted tangent levels and both real branches
without relying on the interval scan grid.
Equations reducible to `u^n*exp(a*u+b) = c`, where `u` is a polynomial and
`n` is a positive integer, are inverted through the real branches of
`lambertw()` before solving the resulting polynomial levels. Constant scaling,
constant exponents, and additive offsets are reduced into this form. Both real
Lambert W branches are considered where valid, and coalesced branch-point
roots are deduplicated. For `n = 1`, `u` may also be an exact rational function;
the resulting rational level equations preserve denominator exclusions. A
matching supported symbolic expression may also be used for both `u` and the
exponential phase, including affine phases `a*u+b`; this covers shared radical
bases such as `sqrt(x)` and `cbrt(x)` while checking candidates in the original
equation.
Sums and products of exponentials, including positive constant-base powers,
whose polynomial arguments have nonconstant parts that are integer multiples
of one shared phase are reduced by substituting `t = exp(g(x))` and isolating
the resulting polynomial's positive roots. Integer powers of exponential terms
and finite products are expanded within the configured polynomial-degree
limit; constant offsets are folded into coefficients. Reachable phase levels
are then solved over the requested interval, including repeated and closely
spaced roots independently of the numerical scan grid. Rational expressions
of these terms are cross-multiplied and candidates are checked in the original
expression to exclude denominator singularities.
For positive constant bases with non-polynomial exponents, logarithmic
inversion is applied through exponent-level sets; piecewise-constant level
intervals are reported as infinite solution sets instead of sampled roots.
Negative constant bases also classify non-polynomial integer exponent levels,
including interval-valued levels for piecewise exponents.
Zero-base, zero-target equations with non-polynomial exponents classify the
positive exponent intervals and retain any isolated points.
Variable powers with a base certified nonnegative over the solving interval
and a positive constant target are reduced through `exponent * ln(base) =
ln(target)`, except supported integer powers of polynomial or rational-
polynomial bases, which use direct polynomial level isolation. Unit targets
use the exact union of base-one and exponent-zero level sets, including the
evaluator's `0^0 = 1` convention.
Candidates are checked in the original equation to handle base-zero boundaries
and avoid scan-tolerance artifacts near flat roots. For an ill-conditioned
rational-power root, the solver may accept a transformed candidate when its
local Newton correction is below floating-point variable resolution; the
reported residual remains the absolute residual at that representable value.
For zero targets, non-polynomial bases are reduced to their zero levels, and
zero-base unit targets are reduced to zero levels of the exponent.
Gamma-family scans partition at poles found through polynomial arguments and
through other arguments whose finite interval ranges can be bounded
conservatively; supported nested expressions are solved at each integer pole
level before scanning. Range analysis clips `asin()`, `acos()`, `acosh()`, and
`sqrt()` to their real domains, preserving whether the resulting domain
boundaries are included.
Inequality partitioning also splits at the inclusive finite-input boundaries
`gamma()` and `lgamma()` support, so compositions such as `gamma(1/x)` do not
lose defined intervals near a reciprocal endpoint.
Equation pole enumeration is bounded to 32,768 levels; larger polygamma-family
pole ranges raise `DomainException` rather than scanning across unpartitioned
poles. Periodic functions whose polygamma argument tends to negative infinity
at a logarithmic boundary raise `InfiniteRootAccumulationException`.
Constant-target compositions `gamma(f(x)) = c` and `lgamma(f(x)) = c` first
isolate the outer levels over the intersection of `f`'s bounded range and the
special function's supported domain, then solve `f(x)` against each level.
Matching constant arguments from equal `gamma()` or `lgamma()` target calls,
and exactly matching positive-integer levels, are retained during inversion,
so a rounded numerical inverse cannot erase a valid level at a narrow extremum.
For `digamma(f(x)) = c` and `polygamma(n, f(x)) = c` with a strictly positive
polynomial argument, the solver numerically inverts the monotone positive
branch and solves the resulting polynomial level independently of the scan
grid. Equal digamma- and polygamma-family calls cancel their shared strictly
monotone function, for every matching supported integer order, when interval
or exact polynomial analysis certifies both arguments are positive.
`polygamma(0, x)`, `polygamma(1, x)`, and `polygamma(2, x)` are also recognized
as the `digamma(x)`, `trigamma(x)`, and `tetragamma(x)` aliases, respectively.
Fixed-integer-order `besseli(n, f(x)) = c` levels are inverted over the full
supported real argument range when interval analysis bounds the argument.
Odd orders use the monotone signed branch, while even orders invert the positive
and negative branches separately. The exact real levels `besseli(0, f(x)) = 1`
and `besseli(n, f(x)) = 0` for nonzero integer `n` reduce directly to
`f(x) = 0`.
Equal `besseli()` calls with the same absolute integer order reduce through
argument equality for odd orders and through both equal and opposite argument
levels for even orders.
Candidates are checked in the original equation, with nearby representable
inputs considered when rounding would otherwise move a valid boundary outside
the function's domain.
Generic residual checks are scaled against finite
scan and off-grid probe values, and off-grid probes help distinguish an
identity with domain holes from a finite root list. Roundoff-sized residuals
are also compared with equation-side magnitudes, so common non-polynomial
identities are rejected as infinite solution sets rather than sampled root lists.
When a factorial operand is a supported polynomial in the solved variable (for
example `x!`, `(2*x + 1)!`, or `(x^2)!`), the solver isolates points where that
operand is an allowed integer up to the configured factorial resource limit
instead of relying on scan points.
When a `root()` degree is polynomial in the solved variable, the solver
isolates its integer degree levels using polynomial extrema. For other degree
expressions, it samples a bounded degree range, refines detected derivative
sign changes to estimate stationary values, and solves the corresponding
integer-level equations. These searches have a finite work budget; large or
inconclusive ranges fall back to the ordinary numerical search and can miss
roots.
Equations with no detected root return an empty list. Each result includes the
root estimate, absolute equation residual, and refinement iteration count.
`Math::solveEquationSet()` returns equation solutions as bounded intervals;
isolated roots are represented by intervals whose bounds are equal. This can
represent continuous solution regions such as `abs(x) = x`, which cannot be
returned as a finite root list. The method intersects the corresponding
inequality sets. Chained equalities such as `x = y = z` intersect the solution
sets for each adjacent pair. The solver uses the same finite-domain numerical
sampling and tolerance model, so it does not prove completeness for arbitrary
expressions. Equations
independent of the solved variable return either the full bounded interval or an
empty set after evaluating both sides; undefined constant expressions have no
solutions, while missing parameters remain evaluation errors.
Periodic logarithmic equations with positive arguments approaching zero can
have infinitely many roots accumulating at a domain boundary; these raise
`InfiniteSolutionSetException` instead of returning a truncated root list.
This includes supported scaled, repeated-product, and complementary squares of
trigonometric and hyperbolic functions that approach zero at isolated phase
values. Exact-zero candidates within floating-point resolution of a variable
logarithm base's singular value of one are omitted when two-sided probes show
the residual converging to a stable nonzero limit, indicating floating-point
cancellation rather than a root.
Periodic equations with variable logarithm bases approaching one, including
bases such as `1 + sin(p(x))^2`, `1 + sinh(p(x))^2`, and their supported
square-root wrappers, report the same infinite accumulation when the logarithm
argument does not vanish quickly enough to cancel the singularity.
Periodic functions of `gamma()` and `lgamma()`, and periodic logarithms of
`gamma()`, `abs(gamma())`, or `lgamma()`, report infinite root accumulation at
their poles when the logarithm is defined on an adjacent side.
Periodic compositions of `gamma()`, `lgamma()`, `digamma()`, and
`polygamma(0, x)` also report infinite root accumulation when their arguments
tend to positive infinity and the composed phase grows without bound. The same
boundary analysis covers growing `asinh()`, `acosh()`, logarithmic, and
principal or lower-branch `lambertw()` phases, including unambiguous sums,
products, and quotients with such phases.
Periodic trigonometric equations with rational arguments that have genuine
poles inside the interval also raise `InfiniteSolutionSetException` when roots
accumulate at those poles; removable numerator/denominator factors are checked
by multiplicity.
Polynomial extraction supports arithmetic, division by a variable-independent
expression, and bounded non-negative integer powers. Rational expressions made
from polynomial arithmetic and bounded integer powers are cross-multiplied and
their candidate roots are checked against the original equation to reject
denominator poles. Set
`maxPolynomialDegree` between 1 and 256 to control polynomial work; expressions
outside that supported degree use the numerical fallback.

`Math::differentiate()` differentiates supported arithmetic, power, and smooth
elementary-function expressions directly, including nested compositions.
Two-argument `log()`, `hypot()`, and `atan2()` expressions use chain-rule
derivatives when their arguments are differentiable. Other expressions use
fourth-order finite-difference estimates over shrinking,
nonuniform step sizes, with Richardson extrapolation when the stencils agree
and one-sided stencils when only one side of a point is defined. It accepts the
same expressions, parameters, and function registry as `Math::evaluate()`.
Supported piecewise functions use branch-aware derivatives away from their
kinks and throw `DomainException` at detected nondifferentiable boundaries.
Numerical estimates may be inaccurate near discontinuities, singularities, or
functions varying faster than floating-point sampling can resolve.

`Math::integrate()` numerically integrates an expression over finite or
improper infinite bounds with adaptive Gauss-Kronrod quadrature. It accepts the same parameter and
custom-function options as `Math::evaluate()`, supports reversed bounds, and
uses configurable absolute and relative error tolerances. Integrals with
interior singularities or discontinuities are not guaranteed to converge; a
deterministic initial panel grid helps resolve localized features. The
embedded-rule error estimate is numerical, not a proof: an arbitrarily narrow
feature can still fall between all sample points. A domain error is raised
when the requested tolerance cannot be met within the interval budget.
Conditionally convergent infinite tails, such as oscillatory integrals, are not
currently supported reliably.

`Math::solveInequality()` returns bounded solution intervals for comparisons
(`<`, `<=`, `>`, `>=`, or `!=`; `≤`, `≥`, and `≠` are also accepted), including
chains such as `a < x <= b`, which are interpreted as the conjunction of their
adjacent comparisons. Each interval records
whether its endpoints are included; sampled truth-state transitions are
refined, and undefined sampled points split intervals. The solver also
partitions at structurally recognized
denominator zeros, polynomial `sign()` jumps, polynomial `floor()`, `ceil()`,
`round()`, and `trunc()` jump levels, `atan2()` principal-branch cuts, and built-in function
domain boundaries, including non-positive integer poles of gamma-family and
polygamma functions when their arguments have a supported polynomial or
conservative elementary range, preserving whether each boundary is defined.
Variable powers whose base may be negative are partitioned at integer
exponent levels, preserving isolated real-domain solutions independently of
the scan grid.
If logarithmic or direct reciprocal arguments create infinitely many
polygamma poles accumulating at a domain boundary, the solver raises
`InfiniteSolutionSetException` rather than returning a finite list of
intervals that omits the accumulating components. General unrecognized
same-sign domain holes can still be missed by finite sampling.
Polynomial boundaries use the scalar root solver, while general expressions
are classified numerically. Infinite equality
regions are classified as intervals; root families accumulating at a domain
boundary still raise
`InfiniteRootAccumulationException`. Unrecognized same-sign domain holes can
remain undetected, so finite sampling does not guarantee a complete solution
set for arbitrary expressions.

Systems can be solved locally from an initial guess with an AST-derived
Jacobian where supported, falling back to finite differences for unsupported
or nondifferentiable entries, and damped Newton or least-squares steps. The
solver also tries bounded multiplicative Newton steps in log-magnitude
coordinates for nonzero variables while retaining additive steps for
zero-valued variables. This can help when solution variables have very
different scales. Underdetermined systems can have multiple solutions. When a
square-system Jacobian is singular, coordinate exploration uses its per-variable
sensitivities to choose probe scales. For severe equation scales, bounded
asymmetric probes also estimate variable magnitudes from literals on both sides
of each equation before coordinate descent. An all-zero start with severe
equation scales also retries from sparse literal-scale seeds when they improve
the initially normalized residual; per-variable literal hints preserve
independent magnitudes and signs.

Affine systems with rational coefficients are reduced exactly. Underdetermined
affine systems return the point on the solution set nearest to the supplied
initial guess, also using exact rational arithmetic before converting the
result to floating point.
Systems with a sum of even-integer powers, multiplied by positive constant
expressions (including supplied parameters), equal to zero reduce each power
base to zero during exact elimination; negative or solved-variable coefficients
are not reduced. Returned candidates are still validated against the original
equations.
Systems with variables isolatable through rational arithmetic, explicit
definitions, or rational affine relations are recursively reduced to
lower-dimensional systems. Exact-zero product constraints also retain
zero-factor branches when a nonzero factor is divided out. Scalar polynomial
roots use the scalar equation solver; reconstructed values are validated
against every original equation. Systems affine in consistent nonzero integer
powers of variables shifted by constants, including mixed first and higher
powers and reciprocal powers with variable-free numerators and constant
coefficients, are solved in transformed coordinates. Affine bases with exact
rational slopes and offsets are normalized without replacing their rational
coefficients with decimal approximations. This includes forms such as
`(2*x + 3)^n`, combined same-variable terms such as `(2*x + x + 3)^n`,
`1/(x + a)`, and `c/(d*x)^n` when the transformed system is affine. Even
powers use nonnegative coordinates with signs selected nearest
the initial guess; odd powers use their unique real inverse.
Reciprocal-power candidates at zero transformed value are rejected, and all
candidates are checked against the original system.
Consecutive power-sum systems whose variable coefficients
agree up to floating-point roundoff are also reduced through Newton's
identities; repeated terms with the same variable and exponent are combined
before classification.

```php
$solution = Math::solveSystem(
    ['x + y = 3', 'x - y = 1'],
    ['x' => 0.0, 'y' => 0.0],
);
$solution?->values; // ['x' => 2.0, 'y' => 1.0]

$solution = Math::solveSystem(
    ['x + y = total', 'x - y = 1'],
    ['x' => 0.0, 'y' => 0.0],
    ['total' => 3.0],
);
```

`solveSystem()` supports up to 64 equations and variables. For underdetermined
systems, the selected solution depends on the initial guess. Equation entries
may use chained equality notation such as `x = y = z`, which
adds the equivalent adjacent constraints to the system. Polynomial
equations in one variable use real-root isolation, verify each candidate against
all equations, and return the common root nearest the initial value. Other
one-variable systems search expanding intervals around the initial value with the scalar equation solver
before falling back to local iteration. Two-variable systems containing
bounded-degree polynomial equations also try resultant-based candidate
isolation after local iteration fails. Sides built from polynomial expressions
using rational arithmetic and integer powers can be cross-multiplied for this
reduction, within the bivariate degree limit. Built-in `sqrt()`, `cbrt()`, and
nonzero-integer-degree `root()` calls with polynomial radicands are reduced
using integer powers, including inside larger expressions when the outer power
produces an integer power of the radicand. Exact rational powers with bounded
numerators and denominators receive the same reduction when an enclosing
integer power makes their combined exponent integral, including inside larger
polynomial expressions. Candidates are checked against the original equations
to reject wrong-branch or undefined values. A single rational-power term added
to or subtracted from polynomial terms is also isolated and reduced when its
base and the remaining expression stay within the degree bound. Sums and
differences of up to three polynomial-multiple square-root terms with polynomial
radicands are reduced through bounded polynomial norms and validated against
the original branches. Sums or differences of two polynomial-multiple cube-root
terms use a cubic norm and are validated against the original equations. A
sum or difference of up to three polynomial-multiple cube-root terms is also
eliminated through nested bounded cubic norms. A
single square-root and cube-root term can also be combined through a mixed
polynomial norm, two fourth-root terms are eliminated through a bounded
resultant, square-root/fourth-root and square-root/fifth-root pairs use
mixed-degree norms, and cube-root/fourth-root pairs use a cubic norm. These
candidates are validated against the original equations. Pairs of positive
rational-power terms with denominators through eight use bounded quotient-
algebra norms, including equal and mixed root degrees. Repeated terms with
identical extracted polynomial radicands and exponents are combined before the
norm term limit is applied. A
single root term may also have a finite nonzero constant
divisor. In two-variable systems, sums of up to three radical terms
over polynomial denominators, including nested polynomial quotients, can also
be reduced when common-denominator numerators remain within the polynomial
degree bound; candidates are checked against the original equations so
denominator poles are rejected.
Top-level
built-in `abs()` equations with polynomial arguments are also squared and
validated against the original equations; integer even powers of absolute-value
polynomials also reduce directly, including negative even powers where defined.
Top-level built-in `sign()` compared
with a polynomial constraint is reduced to the necessary discrete constraint
`q^3-q=0` (or its exact constant case) and then checked against the original
sign equation. Each candidate is validated
against the original equations, excluding denominator-pole artifacts. Systems with more than two variables that become separate
univariate polynomials after replacing each variable with a consistent
positive-base exponential coordinate are isolated variable by variable, then
validated against the original equations. Other multivariable systems try up to 32 bounded
single-coordinate initial-value
perturbations if the first solve fails; each retry uses the same residual
validation and returns `null` if none converges to a common solution.
Householder-QR damped least-squares steps support overdetermined systems and can
also converge on some consistent rank-deficient systems. Iteration scales each
equation by its initial side magnitudes; if both
sides are initially zero, variable-scaled probes estimate the row magnitude.
This keeps constant rescaling from changing relative weight. Convergence is
checked against current side magnitudes. When all solved variables and at least
one equation scale are extremely small relative to the configured residual
tolerance, initially zero-side equations use their local variation across the
current variable magnitudes for step weighting and convergence; calibrated
initial scales remain the fallback when no variation is measurable. The
returned `SystemSolution::residualNorm` is the Euclidean norm of raw residuals
in the equations' original units; it is not the normalized quantity compared
with `SolverOptions::residualTolerance`. Its magnitude therefore depends on
equation scaling. The solver returns one solution near the initial guess; it does not find
all system solutions or prove that none exist.
Underdetermined systems may have non-unique solutions, so the result depends on
the initial guess.

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
- Numerical equation-solving contract: https://github.com/mathphp/mathphp-specs/blob/main/NUMERICAL-EQUATIONS.md
- Private step-by-step explanations: https://github.com/mathphp/mathphp-explaining
- Private visual models and renderers: https://github.com/mathphp/mathphp-visuals
- Private unit-aware quantities: https://github.com/mathphp/mathphp-units

The complete API, grammar, examples, and integration guidance live in the
[documentation repository](https://github.com/mathphp/mathphp-docs). The
language contract and traceability records live in the
[specifications repository](https://github.com/mathphp/mathphp-specs).

The expression grammar accepts explicit multiplication (`2 * x`), common
Unicode notation (`×`, `÷`, `−`, `√`, `∛`, indexed radicals such as `⁴√x`,
`π`, `τ`, `φ`, superscript powers such as `x²`, and subscripted variables
such as `x₁₂`), absolute-value bars
such as `|x - 1|`, and the standard implicit forms (`2x`, `2(x + 1)`, and
`(x + 1)(x - 1)`). Identifiers remain atomic, so
`xy` is one variable name; write `x y` or `x * y` when two factors are
intended. A bound variable followed by one parenthesized expression, such as
`x(y + 1)`, is also implicit multiplication when no function named `x` is
registered. Subscript digits are normalized to ASCII suffixes (`x₁₂` becomes
`x_12`) for variable maps and solver arguments. Registered functions take
precedence over same-named variables. Registered unary functions also accept
prefix application (`sin x`, `sqrt x²`); it binds to one prefix expression,
so use parentheses when the argument includes addition or multiplication
(`sin(x + 1)`, `sin(2x)`). A superscript or numeric caret exponent on a
registered unary function raises the function result (`sin² x` and `sin^2 x`
both mean `(sin(x))²`); write `sin²(x + 1)` or `sin^2(x + 1)` for a compound
argument or `sin²(-x)` for a signed argument. The conventional negative-one
exponent on registered inverse pairs denotes the inverse function
(`sin⁻¹ x` and `sin^-1 x` mean `asin(x)`, `asin⁻¹ x` means `sin(x)`, and
`erf⁻¹ x` means `erfinv(x)`). This includes trigonometric, hyperbolic, error,
and exponential/logarithmic inverse pairs. Write `(sin(x))⁻¹` or `1 / sin(x)`
for the reciprocal.
Named constants include `pi`, `e`, `tau`, `phi`, `eulerGamma` (Euler–Mascheroni),
`catalan` (Catalan's constant), and `apery` (the value of `zeta(3)`). They are
reserved and cannot also be used as variables.

The built-in function registry includes elementary, integer-degree real roots
through `root(value, degree)` (negative degrees return reciprocals),
trigonometric functions including unnormalized `sinc(x) = sin(x) / x` and
normalized `sincpi(x) = sin(pi * x) / (pi * x)` (both equal 1 at zero),
`sec`, `csc`, `cot`, `asec`, `acsc`, and `acot`, with `arcsin`, `arccos`, and
`arctan` aliases for `asin`, `acos`, and `atan`, hyperbolic (including
`sech`, `csch`, `coth`, `asech`, `acsch`, and `acoth`, with `arcsinh`,
`arccosh`, and `arctanh` aliases for `asinh`, `acosh`, and `atanh`), numerical,
integer, and special functions such as `erf`, `erfc`, `erfcx`, `erfi`, `dawson`,
`gamma`, `lgamma`,
their real inverses `erfinv(y)` for `-1 < y < 1` and `erfcinv(y)` for
`0 < y < 2`,
the regularized incomplete gamma functions `gammainc(a, x)` and
`gammaincc(a, x)` for finite `a > 0` and `x >= 0`,
and the regularized incomplete beta functions `betainc(a, b, x)` and
`betaincc(a, b, x)` for finite `a > 0`, `b > 0`, and `x` in `[0, 1]`. Their
inverse functions `betaincinv(a, b, p)` and `betainccinv(a, b, q)` return the
lower- or upper-tail quantile for probabilities in `[0, 1]`. Interior
probabilities whose quantiles round to 0 or 1 are rejected rather than
silently returned at an endpoint.
`dawson(x)` evaluates Dawson's integral for finite real `x` using a power
series for moderate magnitudes and an asymptotic expansion for large magnitudes.
`erfcx(x)` evaluates the scaled complementary error function directly, retaining
finite positive-tail values after `erfc(x)` itself underflows.
Equations `erfcx(p(x)) = c` with supported polynomial arguments invert the
monotone outer function before root isolation, including both roots of even
polynomial arguments without relying on the scan grid.
For rational polynomial phases of degree at least two, `erfcx(P(x)) = c`
also uses certified inverse levels and polynomial root isolation when the
inverse phase lies in `[-26, 26]`; nonpositive targets are root-free.
The inverse regularized incomplete gamma functions `gammaincinv(a, p)` and
`gammainccinv(a, q)` accept finite `a > 0` and probabilities in `[0, 1]`;
endpoint probabilities whose quantiles are infinite are rejected to preserve
the evaluator's finite-result contract. Quantiles below positive floating-point
resolution are also rejected instead of silently rounding to zero.
`betaincc()` evaluates the complementary tail directly to retain small
probabilities near `x = 1`,
the real beta function `beta(a, b)` for non-pole arguments, and the real
Riemann zeta function `zeta(s)` away from its pole at `s = 1`,
`digamma`, `trigamma`, `tetragamma`, and integer-order Bessel functions
`besselj(order, x)`, `besseli(order, x)`, and `bessely(order, x)`.
`besselj()` supports integer orders from -256 through 256 and real arguments
from -1000 through 1000, using a backward recurrence for arguments whose
power series would be numerically unstable.
`besseli()` supports the same integer orders and real arguments from -713
through 713, near the finite-result limit for double precision.
`bessely()` supports the same integer orders and positive real arguments in
`(0, 1000]`; its singularity at zero and non-real negative-argument values are
excluded. All three functions support analytic derivatives with respect to
their argument and participate in numerical equation solving.
Incomplete-gamma inequalities partition at both argument-domain boundaries;
the shape boundary `a = 0` is excluded and the value boundary `x = 0` is
included.
Incomplete-beta inequalities partition at both positive-shape boundaries and
the inclusive value boundaries `x = 0` and `x = 1`.
Inequality searches partition at the argument limits of the supported Bessel
functions, preserving each valid endpoint and excluding the singular `bessely`
boundary at zero.
The real `beta(a, b)` implementation accepts finite non-pole arguments greater
than `-171`; positive arguments may exceed `171`. It evaluates in log-gamma
space and returns zero when `a + b` is a non-positive integer while both
arguments are otherwise valid. Results remain subject to double-precision
overflow and underflow.
Equation and inequality searches partition at beta argument poles and the
negative real-domain boundaries. They also enumerate reachable
non-positive-integer argument sums as valid zero levels, including isolated
points below the negative real-range cutoff, while keeping the intervening
undefined spans out of solution intervals.
`zeta(s)` supports finite real inputs except its pole at one, subject to the
finite-result range. It uses the Hasse eta series for nonnegative inputs and
the functional equation for negative inputs, including exact trivial zeros at
negative even integer literals. Scalar equation solving enumerates these
trivial-zero levels through supported polynomial arguments independently of
the scan grid. Direct reciprocal arguments tending to negative infinity
report `InfiniteRootAccumulationException` when those trivial zeros accumulate
at the reciprocal boundary. Near the pole, direct equations over intervals contained in
`[0, 2]` that cross `s = 1` use the local Laurent expansion to recover the
nearest representable input for large targets; the reported residual remains
the actual evaluated value.
The general `polygamma(order, x)` function supports integer orders 0 through 128,
including negative non-integer arguments by reflection; its poles at
non-positive integers are excluded from inequality intervals.
When a Bessel or polygamma integer order, or a Lambert W branch, depends on the
solved variable, equation solving isolates its supported integer levels. It
extracts polynomial levels directly and recursively solves other level
equations, verifying each candidate in the original equation. Equation-set
solving intersects each level's preimage with its solutions, preserving
continuous intervals from piecewise-constant integer selectors.
The real Lambert W function is available as `lambertw(x)` for the principal
branch (`x >= -1/e`) and `lambertw(x, -1)` for the lower real branch
(`-1/e <= x < 0`).
Special-function results are finite and domain-checked; expressions that
approach poles or overflow return structured evaluation errors.
`erfc()` is evaluated directly so small positive tail probabilities are not
lost to cancellation in `1 - erf(x)`.
Custom functions may provide a gradient callback returning one partial
derivative per argument. Symbolic differentiation applies those partials by the
chain rule; functions without a gradient continue to use numerical fallback.
When a custom function is total and returns one of its arguments unchanged for
every valid call, set `exactIdentityArgument` to that zero-based argument index.
This is an explicit semantic assertion used by equation solving; a function
name or sampled callback values are never treated as proof. The option cannot
be combined with a `domain` callback because exact identity rewrites require a
total function.
For scalar equations containing custom functions in supported arithmetic
expressions, a custom function may also provide a sound interval-extension
callback. It receives one `[lower, upper]` pair per argument and must return
bounds enclosing every function value over those intervals. The solver
subdivides these enclosures to find narrow roots missed by the scan and to
certify root-free regions. Numerical scans can produce root candidates, but
without interval extensions they cannot certify that the list is complete;
the solver raises `RootSearchLimitException` rather than returning a possibly
partial result. An unresolved enclosure also raises that exception. To certify
that a custom function is defined over an entire interval, provide a separate
`domain` callback that returns `true` only when every argument tuple in the
supplied intervals is in-domain. When that proof and an exact constant range
enclosure match the equation target, the solver can classify the solution set
as infinite; a range enclosure alone does not prove total definedness.
Interval propagation currently covers arithmetic (including `%`), postfix factorial,
integer powers, `exp`/`log`,
`sqrt`/`cbrt`/`root`, `lambertw`, `atan`/`arctan`, `atan2`, `abs`, `sinc`/`sincpi`, `sin`/`cos`/`tan` and their reciprocal forms, inverse reciprocal
trigonometric and hyperbolic functions, selected hyperbolic and error functions
including `erfcx`, `erfinv`, and `erfcinv`, rounding functions, `min`/`max`, and `hypot`.
`binomial`, `permutation`, `gcd`, and `lcm` are enclosed by finite integer-pair
enumeration when their argument ranges contain at most 4,096 combinations;
`doublefactorial` enumerates its bounded integer domain.
For `gcd` and `lcm`, custom-function arguments with interval extensions can
also be solved by enumerating bounded integer levels and isolating their
preimages. The same integer-level method supports custom-function arguments
to `binomial` and `permutation` when the bounded level combinations stay
within 4,096.
Sinc interval bounds tighten under subdivision: intervals around zero use
monotonicity through the first zero, and intervals away from zero enclose the
`sin(x) / x` quotient directly.
Integer-order `besselj` uses its global unit bound. For `besseli`, bounded
integer-order ranges use endpoint bounds when at most 64 orders are present;
wider order ranges use a finite exponential envelope when available.
Regularized incomplete gamma and beta functions, including inverse quantiles,
are enclosed when their shape intervals are bounded and strictly positive.
Gamma inverse intervals touching an infinite quantile endpoint remain unresolved.
`atan2` uses corner bounds away from the negative-axis branch cut and the full
`[-pi, pi]` range when an interval crosses that cut.
`lgamma` also uses pole-aware gamma extrema on negative intervals when the
enclosed gamma range can be proved to stay away from zero.
`digamma` uses monotonic endpoint bounds on negative intervals that contain no
non-positive integer pole.
`trigamma` uses its recurrence and positive-domain bounds on negative
pole-free intervals, subject to a finite recurrence work limit.
`tetragamma` uses the same recurrence on negative pole-free intervals, with
increasing endpoint bounds and a finite recurrence work limit.
Fixed integer-order `polygamma` intervals use the order-specific positive-tail
monotonicity and recurrence on negative pole-free intervals, up to order 128
and the same finite recurrence work limit.
Interval ranges for `root(base, degree)` enumerate valid integer degree levels
in a bounded degree range; non-integer degrees remain outside the function's
real domain.
To mark an input as outside a custom function's real domain, throw
`MathPHP\Exception\FunctionDomainException`; equation solving treats those
points as undefined domain gaps. Other callback exceptions remain errors.
Factorial accepts computed operands within four scaled machine epsilons of an
integer to absorb floating-point root-refinement error; ordinary fractional
operands remain invalid.

## Development

The public core is the only package in this repository. The optional explaining,
visuals, and units packages are distributed separately and loaded by
applications that need them.
