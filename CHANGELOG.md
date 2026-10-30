# Changelog

All notable changes will be documented in this file. The project follows
[Semantic Versioning](https://semver.org/).

## [0.3.7] - 2026-09-07

### Added

- Allow custom functions to declare an exact, total identity argument for
  symbolic equation reduction, while leaving opaque callbacks uncertified.
- Reduce nested structurally identical quotients before equation solving and
  revalidate roots against the original expression domain.
- Solve exactly linear rational numerators before numerical root isolation,
  preserving denominator holes and correctly rounded rational roots.
- Reduce constant-base logarithmic compositions `log(exp(U), b) = V` and
  `log(a^U, b) = V` to exact argument equations before real interval solving,
  including distinct positive bases other than one.
- Reduce `erfinv(erf(U))` and `erfcinv(erfc(U))` to `U` before real equation
  solving, allowing arbitrary supported equations in the inner argument.
- Reduce the reverse compositions `erf(erfinv(U))` and `erfc(erfcinv(U))`
  when certified interval ranges stay strictly inside their real inverse domains.
- Reduce `acsch(csch(U))` and `acoth(coth(U))` while rechecking candidates
  against the original equation to preserve excluded zero inputs; certify
  infinite identity cases only when the argument is an exact nonzero rational
  function.
- Reduce supported unary inverse compositions inside larger arithmetic
  expressions by replacing only the matching AST source span and validating
  candidates against the original equation.
- Reduce nested constant-base `log(exp(U), b)` and `log(a^U, b)` compositions
  inside arithmetic expressions, including unequal positive bases.
- Reduce nested `ln(a^U)`, `log2(2^U)`, `log10(10^U)`, and `log2(exp(U))`
  compositions using their exact base-change identities.
- Reduce `exp(log1p(U))` to `1 + U` and `expm1(ln(1 + U))` to `U` when
  certified ranges prove the strict real-domain condition `U > -1`.
- Reduce `ln(1 + expm1(U))` and `log(1 + expm1(U), e)` to `U`, recognizing
  either term order.
- Reduce `log1p(exp(U) - 1)` to `U` when the subtraction is exactly a built-in
  exponential call minus one, including inside supported arithmetic.
- Reduce globally valid `sinh(asinh(U))` and `tan(atan(U))` compositions,
  including when they occur inside larger supported expressions.
- Reduce `log1p(ln(U))` for finite real targets through `U = exp(expm1(t))`.
- Reduce nested `exp(ln(U))` and `expm1(log1p(U))` only when certified interval
  ranges prove their strict real-domain conditions throughout the search range.
- Reduce nested `sin(asin(U))`, `cos(acos(U))`, and `tanh(atanh(U))` only when
  certified interval ranges prove the required inverse-function domains.
- Reduce exact levels of `sin(acos(U))` and `cos(asin(U))` to the two real
  argument levels derived from `sqrt(1 - target^2)`, validating candidates in
  the original equation.
- Reduce `sin(atan(U))`, `cos(atan(U))`, and `tan(asin(U))` level equations
  to their finite real argument levels, preserving open range endpoints.
- Reduce `sinh(atanh(U))` and `tanh(asinh(U))` levels through their real
  inverse maps, excluding unreachable `tanh` endpoint levels.
- Reduce `csch(atanh(U))` for nonzero real targets through its reciprocal
  hyperbolic-sine inverse level.
- Reduce `acsch(atanh(U))` for nonzero targets through the reverse inverse
  reciprocal-hyperbolic composition.
- Reduce `acoth(asinh(U))` for nonzero targets through the inverse-hyperbolic
  cotangent level.
- Reduce `acoth(atanh(U))` for nonzero targets through the inverse hyperbolic
  cotangent and tangent levels.
- Reduce `acoth(csch(U))` for nonzero targets to `asinh(tanh(t))`.
- Reduce `acoth(asech(U))` for positive targets through a stable `coth` level.
- Reduce `acsch(tanh(U))` outside the closed interval `[-asinh(1), asinh(1)]`
  through a stable reciprocal-hyperbolic-sine inverse level.
- Reduce `asinh(coth(U))` outside the same closed interval, stabilizing the
  inverse near its strict target boundaries.
- Reduce `asinh(tanh(U))` inside the open interval `(-asinh(1), asinh(1))`
  with a factored inverse level near both endpoints.
- Reduce `acsch(coth(U))` on its punctured open target intervals with a
  factored inverse that remains stable near both endpoints.
- Reduce `asech(tanh(U))` for positive targets through a stable `sech` level,
  excluding the unattained zero boundary.
- Reduce `atanh(csch(U))` for nonzero targets through `acsch(tanh(t))`.
- Reduce `acsch(sech(U))` for targets at least `asinh(1)` to symmetric levels,
  merging at the attained lower endpoint.
- Reduce `csch(acoth(U))` for nonzero targets to its signed `hypot` level.
- Reduce `acosh(csch(U))` for nonnegative targets to its unique positive
  inverse level.
- Reduce `asech(csch(U))` for nonnegative targets using a log-domain inverse
  level that remains finite at large targets.
- Reduce `acosh(coth(U))` for positive targets using a stable log-coth level,
  excluding its unattained zero limit.
- Reduce `acosh(asech(U))` for nonnegative targets through a stable nested
  hyperbolic-secant level.
- Reduce `acosh(sinh(U))` and `acosh(tan(U))` for nonnegative targets, and
  handle the zero-only `acosh(sin(U))` and `acosh(cos(U))` cases.
- Reduce `acosh(acsch(U))` for nonnegative targets through a stable
  reciprocal-hyperbolic-cosecant level.
- Reduce `acosh(acoth(U))` for nonnegative targets through a stable
  reciprocal-hyperbolic-cotangent level.
- Reduce `asinh(acosh(U))` and `acosh(asinh(U))` for nonnegative targets to
  their direct nested hyperbolic levels.
- Reduce `asinh(acos(U))`, `asinh(atan(U))`, and `acosh(acos(U))` over their
  exact bounded target ranges.
- Reduce `acosh(asin(U))` through its attained upper endpoint and `acosh(atan(U))`
  over the corresponding strict upper target range.
- Reduce `asin(acosh(U))` and `acos(acosh(U))` on `[0, pi/2]` through exact
  nested hyperbolic levels.
- Reduce `acos(asinh(U))`, `atan(asinh(U))`, and `atan(acosh(U))` over their
  exact principal target ranges.
- Reduce `asin(atan(U))`, `acos(atan(U))`, `asin(acos(U))`, and `acos(acos(U))`
  over their exact real target ranges through nested trigonometric levels.
- Reduce `asin(asin(U))` and `acos(asin(U))` through their closed principal
  target ranges and nested sine levels.
- Reduce `atan(asin(U))` and `atan(acos(U))` on their exact bounded target
  ranges through nested trigonometric levels.
- Reduce `asinh(acot(U))`, `acosh(acot(U))`, `asin(acot(U))`, and `acos(acot(U))`
  on exact ranges derived from `acot`'s open `(0, pi)` principal range.
- Reduce `atan(asec(U))` and `atan(acsc(U))` over their bounded ranges while
  excluding the unattainable principal-value gaps.
- Reduce `atan(atan(U))` and `atan(acot(U))` over their exact open target ranges.
- Reduce `atanh(asinh(U))`, `atanh(asin(U))`, and `atanh(atan(U))` for all real
  targets, and `atanh(acos(U))` for nonnegative targets.
- Reduce the self-compositions `atanh(atanh(U))`, `acsch(acsch(U))`,
  `acoth(acoth(U))`, `asech(asech(U))`, and `acot(acot(U))` over their exact
  real target sets, including punctures and open principal endpoints.
- Reduce `asinh(acoth(U))`, `atanh(acoth(U))`, `asech(acoth(U))`, and
  `acsch(acoth(U))` through stable nested hyperbolic-cotangent levels.
- Reduce `asin(acoth(U))`, `acos(acoth(U))`, `atan(acoth(U))`, and
  `acot(acoth(U))`, preserving zero and half-turn target exclusions.
- Reduce `acot` composed with `asinh`, `atanh`, `acosh`, `asech`, `asec`,
  `acsc`, `asin`, `acos`, and `atan` over their exact principal target ranges.
- Reduce `asin(asech(U))`, `acos(asech(U))`, `atan(asech(U))`,
  `asec(asech(U))`, and `acsc(asech(U))` through stable sech levels on their
  exact real target intervals.
- Reduce `atanh(acot(U))`, `atanh(asec(U))`, and `atanh(acsc(U))` over their
  exact positive, nonnegative, and punctured-real target ranges.
- Reduce `acoth(asec(U))` and `acoth(acsc(U))` over their exact bounded or
  symmetric target ranges, preserving unattainable reciprocal-angle values.
- Reduce `acoth(asin(U))`, `acoth(acos(U))`, `acoth(atan(U))`, and
  `acoth(acot(U))` with open or closed endpoints matching each inverse range.
- Reduce `acoth(sinh(U))` and `acoth(cosh(U))` through stable hyperbolic
  inverse levels, and periodic `acoth` compositions through `coth(t)` levels;
  certify no real roots for bounded inner ranges that cannot satisfy `|x| > 1`.
- Reduce `acoth` compositions with exponential and logarithmic functions
  through exact inverse levels, preserving the positive-target and nonzero
  target restrictions imposed by their real ranges.
- Reduce `acoth(sqrt(U))`, `acoth(cbrt(U))`, and `acoth(abs(U))` through
  squared, cubed, and signed `coth` levels over their exact target domains.
- Reduce `acsch(sqrt(U))`, `acsch(cbrt(U))`, and `acsch(abs(U))` through
  squared, cubed, and signed `csch` levels over their exact target domains.
- Reduce `acsch` compositions with exponentials and logarithms through stable
  `csch` inverse levels, including a factored boundary formula for `expm1`.
- Reduce `asech(sqrt(U))`, `asech(cbrt(U))`, and `asech(abs(U))` through
  squared, cubed, and signed `sech` levels for nonnegative targets.
- Reduce algebraic `asinh` and `acosh` compositions with `sqrt`, `cbrt`, and
  `abs`, applying the exact signed levels and inverse-range restrictions.
- Reduce `atanh(sqrt(U))`, `atanh(cbrt(U))`, and `atanh(abs(U))` through
  squared, cubed, and signed `tanh` levels with principal-range constraints.
- Reduce constant-integer `root(U, n)` compositions with the hyperbolic
  functions and their real inverses, respecting even-degree domains and
  reciprocal semantics for negative degrees.
- Extend constant-integer `root(U, n)` composition reductions to inverse
  trigonometric, algebraic, exponential, and logarithmic outer functions.
- Reduce constant-integer root compositions with error functions and their
  inverses, including all real Dawson preimages for each attainable target.
- Guard bounded periodic phase enumeration for `sin`, `cos`, `tan`, `cot`,
  `sec`, and `csc` against floating-point collapse of adjacent levels, raising
  `RootSearchLimitException` instead of silently merging phase roots.
- Reduce `hypot()` equations with one non-rational, non-polynomial coordinate
  and one constant coordinate to stable positive and negative function levels.
- Reduce `atan2()` equations with one non-rational, non-polynomial coordinate
  and one constant coordinate through the correct principal branch, including
  sign-interval classifications when the constant coordinate is zero.
- Reduce `acsch(asinh(U))`, `acsch(asin(U))`, `acsch(acos(U))`,
  `acsch(atan(U))`, `acsch(acot(U))`, `acsch(asec(U))`, and `acsch(acsc(U))`
  using a stable `csch` target level and exact principal-range bounds.
- Reduce `asech(asinh(U))`, `asech(asin(U))`, `asech(acos(U))`,
  `asech(atan(U))`, `asech(acot(U))`, `asech(asec(U))`, and `asech(acsc(U))`
  for nonnegative targets using stable `sech` levels.
- Reduce `asinh(acsch(U))`, `asin(acsch(U))`, `acos(acsch(U))`,
  `atan(acsch(U))`, and `acot(acsch(U))` with zero and half-turn exclusions
  inherited from the reciprocal-hyperbolic-cosecant range.
- Reduce `asinh(asec(U))` and `asinh(acsc(U))` through nested hyperbolic levels,
  excluding the `asec` half-turn and `acsc` zero gaps.
- Reduce `acosh(asec(U))` and `acosh(acsc(U))` over their exact target ranges,
  preserving the excluded `asec` half-turn target.
- Reduce `asin(asec(U))`, `acos(asec(U))`, `asin(acsc(U))`, and `acos(acsc(U))`
  through their exact inverse levels and split real target ranges.
- Reduce `asin(atanh(U))`, `acos(atanh(U))`, and `atan(atanh(U))` through
  their exact `tanh` inverse levels.
- Reduce `asinh(atanh(U))` for all real targets and `acosh(atanh(U))` for
  nonnegative targets through nested hyperbolic tangent levels.
- Reduce `asech(acsch(U))` for nonnegative targets to its unique positive
  reciprocal-hyperbolic-cosecant level.
- Reduce `acsch(asech(U))` for positive targets through stable reciprocal
  hyperbolic levels, excluding the inner zero singularity.
- Reduce `asech(acosh(U))` for nonnegative targets and `acsch(acosh(U))` for
  positive targets through their exact inverse hyperbolic levels.
- Reduce `asech(atanh(U))` for nonnegative targets through a stable hyperbolic
  secant inverse level, including zero.
- Reduce `asinh(asech(U))` for nonnegative targets through a stable nested
  hyperbolic-secant level, including zero.
- Reduce `atanh(asech(U))` for nonnegative targets through a stable sech level,
  including the endpoint root at one.
- Reduce `atanh(sech(U))` for positive targets to symmetric inverse-sech
  argument levels.
- Reduce `asinh(sech(U))` over `(0, asinh(1)]`, merging symmetric levels at
  the attained upper endpoint.
- Reduce `asin(sech(U))` and `acos(sech(U))` over their exact half-quadrant
  ranges, merging symmetric levels at the included endpoints.
- Reduce `coth(asinh(U))` for targets outside `[-1, 1]` using a stable signed
  reciprocal square-root level.
- Reduce `sin(asinh(U))` and `cos(asinh(U))` levels with their strict real
  ranges and symmetric argument branches.
- Reduce `cosh(sinh(U))` and `sinh(cosh(U))` to their symmetric inverse
  argument levels, preserving the attained lower endpoint.
- Reduce `sech(atanh(U))` over its exact `(0, 1]` range, preserving the single
  root at one and excluding the unattained zero boundary.
- Reduce `asin(asinh(U))` and `asinh(asin(U))` through their bounded real
  output ranges and inverse argument levels.
- Reduce `sinh`/`cosh` of principal `asin` or `acos` arguments over their
  bounded real ranges, retaining both branches for even compositions.
- Reduce `sinh(atan(U))` and `cosh(atan(U))` within the open principal range,
  retaining symmetric argument branches for the latter.
- Reduce `tan(acos(U))`, `cot(acos(U))`, and `cot(asin(U))` to exact real
  argument levels, preserving the two endpoint roots at zero targets.
- Reduce `asinh` and `atanh` applied to sine or cosine to periodic trig levels,
  preserving inverse-domain bounds and falling back when `tanh` rounds to an
  excluded endpoint.
- Reduce `atan(sin(U))`, `asin(tanh(U))`, and `atan(tanh(U))` to exact
  sine or hyperbolic-tangent levels, preserving their open and closed ranges.
- Reduce `asinh(tan(U))`, `atanh(sinh(U))`, and `atanh(tan(U))` to supported
  tangent or hyperbolic-sine levels, falling back on overflow or endpoint rounding.
- Reduce `atan(cos(U))`, `asin(cos(U))`, `acos(sin(U))`, `acos(tanh(U))`,
  `tanh(asin(U))`, and `tanh(acos(U))`
  to periodic or hyperbolic argument levels within their real ranges.
- Reduce `acot(sin(U))` and `acot(cos(U))` across their attainable principal
  range, and solve the endpoint-only `asec`/`acsc` compositions of sine/cosine.
- Reduce `acot(sinh(U))`, `acot(tan(U))`, and in-range `acot(tanh(U))`
  equations through cotangent levels while respecting open endpoint domains.
- Reduce `asec` and `acsc` of hyperbolic-sine or tangent arguments through
  secant/cosecant levels, excluding their singular output levels.
- Extend reciprocal inverse-trig level solving to `asec(cosh(U))` and
  `asec`/`acsc` of `csch(U)` or `coth(U)`, respecting unattained coth endpoints.
- Reduce principal-branch `asin(sin(U))`, `acos(cos(U))`, and `atan(tan(U))`
  only when certified ranges remain inside the inverse functions' principal intervals.
- Reduce `lambertw(U*exp(U))` to `U` on the corresponding certified real branch
  domains, including branches 0 and -1.
- Reduce `asec(sec(U))`, `acsc(csc(U))`, and `acot(cot(U))` on certified
  principal intervals that exclude their poles and branch gaps.
- Reduce nested `cosh(acosh(U))`, `sech(asech(U))`, `csch(acsch(U))`, and
  `coth(acoth(U))` when certified ranges stay within their real domains.
- Require strict interval-domain proofs before reducing `erfinv(erf(U))` and
  `erfcinv(erfc(U))`, preventing rewrites through rounded inverse endpoints.
- Classify regularized incomplete-gamma equations at every non-omitted real
  level for positive integer shapes and nonconstant polynomial or rational
  arguments over the complex plane; isolate finite omitted-level roots for
  shapes above one using exact polynomial composition and preserve rational
  argument poles.
- Reduce regularized incomplete-beta equations with positive integer shapes
  to exact polynomial equations for polynomial arguments and targets, and to
  exact rational-polynomial equations for rational arguments and targets,
  preserving original denominator poles.
- Classify supported unbounded exponential sums as root-free when summed
  lower bounds for positive constant and convex polynomial-phase terms dominate
  constant negative terms; Taylor and weighted-variance bounds combine
  translated even-degree monomials with different minimizers. Quartics with
  other phases also use conservative quadratic lower bounds when their cubic
  terms can be absorbed.
- Reduced unbounded incommensurate exponential sums over polynomial phases to
  finite phase and polynomial-preimage searches using extreme-slope dominance
  and Cauchy root bounds.
- Reduced supported unbounded `hypot()` equations by squaring coordinates and
  verifying every candidate against the original equation.
- Added `trunc()` for rounding finite values toward zero, including equation and
  inequality solving across its sign-dependent integer boundaries.
- Isolate all-real roots of supported affine exponential, logarithmic, and
  hyperbolic equations by expanding exact rational bounds around the verified
  unique root.
- Added exact rational interval enclosures for `erf()` on `[-64, 64]`, combining
  alternating power series and an asymptotic `erfc()` remainder bound, and used
  them to isolate affine `erfinv()` and `erfcinv()` equations on the real line.
- Added certified real-line isolation for affine `erf()` and `erfc()` equations,
  using inverse estimates only to seed rational endpoint verification; cached
  the shared rational `erf()` scale and made its enclosure precision selectable.
- Extended certified `atan()` interval bounds to all finite rational inputs
  using reciprocal reduction, and added all-real counting and isolation for
  affine `atan()` and `arctan()` equations.
- Added certified `asin()` and `acos()` interval enclosures on `[-1, 1]`, plus
  all-real counting and isolation for affine `asin()`/`arcsin()` and
  `acos()`/`arccos()` equations.
- Added certified interval bounds for `acot()` and all-real counting and
  isolation for affine inverse-cotangent equations on the principal range `(0, pi)`.
- Added branch-aware `asec()` and `acsc()` rational interval enclosures and
  certified all-real counting and isolation for affine reciprocal inverse-trig
  equations, retaining the real-domain gap `(-1, 1)`.
- Added all-real counting and certified isolation for affine-coordinate `atan2`
  equations when either coordinate is a nonzero rational constant.
- Added exact all-real root counts and certified two-branch isolation for
  affine `cosh()` equations at rational target levels.
- Extended exact all-real counting and certified two-branch isolation to affine
  `sech()` equations at rational target levels.
- Added exact all-real counts and certified isolation for affine `csch()` and
  `coth()` equations, including their excluded zero and unit boundary levels.
- Added exact all-real root counts and certified isolation for `exp()` of a
  rational quadratic phase, including zero-, one-, and two-root cases.
- Added exact all-real root counts and certified isolation for `ln()`, `log2()`,
  `log10()`, and constant-rational-base `log()` of rational quadratic phases.
- Extended those quadratic logarithm equations to finite rational intervals,
  excluding roots at the open interval endpoints.
- Extended quadratic-phase exponential solving to `expm1()` and `log1p()`
  equations for all-real and finite rational intervals.
- Added certified natural-logarithm interval bounds using exact power-of-two
  range reduction and a positive atanh series; use them to isolate rational-base
  exponential, `log()`, `log2()`, and `log10()` equations on the real line.
- Count and isolate affine `sin`, `cos`, `tan`, `cot`, `sec`, and `csc` equations
  at exact rational levels on finite rational intervals using certified
  inverse-trig bounds and reciprocal identities.
- Count and isolate the unique real fixed point in `cos(±x)=±x` equations
  using certified inverse-cosine rational bounds.
- Classify signed `cosh(±x)=±x` equations as having no real roots.
- Count and isolate both real roots of signed `coth(±x)=±x` and
  `csch(±x)=±x` equations using certified hyperbolic rational bounds.
- Classify signed inverse-trigonometric and inverse-hyperbolic self-equations
  for `asin`, `atanh`, and `acosh`, including their aliases.
- Count and isolate the real fixed points of signed `acos(±x)=±x` forms with
  certified inverse-cosine bounds.
- Count and isolate signed `acot(±x)=±x` equations using certified inverse-
  cotangent bounds for both principal branches.
- Count and isolate the reflected-branch fixed points of `asec(±x)=±x` and
  classify all signed `acsc` self-equations as root-free.
- Extend reciprocal-hyperbolic fixed-point coverage to `asech`, `acsch`, and
  `acoth` using certified interval identities.
- Count and isolate signed `erfc(±x)=±x` fixed points using certified rational
  enclosures with bounded precision.
- Count and isolate the three real fixed points of signed `erf(±x)=±x` and
  `erfinv(±x)=±x` forms using certified error-function intervals.
- Count and isolate equations with one additive polynomial square-root term
  or up to eight additive absolute-value terms on finite rational intervals,
  filtering candidates by their original branches.
- Reduced systems with explicit variable definitions and rational affine
  relations by recursively substituting into lower-dimensional systems.
- Reduced real system equations consisting of sums of positively weighted even
  powers equal to zero into exact base-zero constraints before nonlinear iteration.
- Added absolute-value bar notation, such as `|x - 1|`, to evaluation and
  equation solving.
- Raised the default equation-solving polynomial degree limit from 128 to 256,
  matching the maximum supported exact polynomial isolation degree.
- Added Unicode `≤` and `≥` comparisons to `Math::solveInequality()`.
- Added ASCII `!=` and Unicode `≠` comparisons to `Math::solveInequality()`,
  excluding equality roots while preserving undefined domain points.
- Added subscript digits to variable notation, normalizing forms such as `x₁₂`
  to portable ASCII identifiers such as `x_12`.
- Added the real imaginary error function `erfi()`, including an odd positive-term
  series, analytic differentiation, and equation-solving support.
- Added Dawson's integral `dawson()` with convergent-series and large-argument
  asymptotic evaluation, analytic differentiation, and equation-solving support.
- Added scaled complementary error function `erfcx()` with stable positive-tail
  and reflected negative-input evaluation, tail-stable differentiation, and
  scan-independent inversion for polynomial equation arguments.
- Extended `Math::solve()` to evaluate equations over singleton finite intervals.
- Hardened exact polynomial isolation so brackets do not include separate
  roots at excluded interval endpoints.
- Added `Solver\ExactRationalInterval` with exact rational interval arithmetic
  and a Taylor-series enclosure for `exp()` on inputs within `[-4096, 4096]`.
- Improved exponential enclosure performance with directed fixed-point Taylor
  bounds and outward-rounded range-reduction squaring.
- Added `Math::isolateExponentialEquation()` to return certified rational root
  intervals for `exp(x) = q` on finite rational bounds.
- Added `Math::isolateAffineExponentialEquation()` for certified roots of
  `exp(a*x + b) = q`, including negative slopes.
- Added `Math::isolateQuadraticExponentialEquation()` for certified roots of
  `exp(a*x^2 + b*x + c) = q`, splitting at the exact rational phase vertex.
- Added exact rational interval bounds for `sinh()` and
  `Math::isolateAffineHyperbolicSineEquation()` for certified affine sinh roots.
- Added exact rational interval bounds for `cosh()` and
  `Math::isolateAffineHyperbolicCosineEquation()` for both affine cosh branches.
- Added exact rational interval bounds for `tanh()` and
  `Math::isolateAffineHyperbolicTangentEquation()` with strict range handling.
- Added certified affine isolators for `sech()`, `csch()`, and `coth()` through
  exact reciprocal level transformations and their real-range restrictions.
- Added certified affine equation isolators for `asinh()`, `acosh()`, and
  `atanh()` using rational interval enclosures of their inverse levels.
- Added `Math::isolateAffineLogarithmEquation()` for certified roots of
  `ln(a*x + b) = q` using exact exponential enclosures.
- Added `Math::isolateQuadraticLogarithmEquation()` for certified roots of
  `ln(a*x^2 + c) = q`, including symmetric branches and exact rational square roots.
- Added `Math::isolateQuadraticPolynomialLogarithmEquation()` for certified
  roots of `ln(a*x^2 + b*x + c) = q`, including repeated-root handling.
- Added the `∛` prefix notation as a real cube-root expression, including for
  negative radicands.
- Added `arcsin()`, `arccos()`, and `arctan()` as inverse-trigonometric aliases
  with analytic derivatives.
- Added `arcsinh()`, `arccosh()`, and `arctanh()` as inverse-hyperbolic aliases
  with analytic derivatives.
- Added the named constants `eulerGamma`, `catalan`, and `apery` for the
  Euler–Mascheroni constant, Catalan's constant, and Apéry's constant.
- Added `Math::countPolynomialRootsOnRealLine()` for certified distinct-root
  counts of supported exact rational polynomials over the entire real axis,
  using Sturm sequence signs at both infinities.
- Added `Math::isolatePolynomialRootsOnRealLine()` to return certified,
  disjoint rational brackets for every distinct real root, using an exact
  integer Cauchy bound and the bounded Sturm subdivision machinery.
- Accelerated all-real isolation of even and odd polynomials by reducing
  through `x^2` and mapping nonnegative roots back with certified rational
  square-root intervals. Apply the same reduction after exact rational
  translations when those reveal even or odd symmetry.
- Extended all-real polynomial root counting and isolation to supported exact
  rational-function equations, excluding common numerator/denominator roots
  so cancelled denominator holes are not reported as solutions.
- Extended finite-interval exact root counting and isolation to supported
  rational-function equations with the same denominator-hole filtering.
- Extended exact root counting and isolation to rational fractional powers of
  rational functions through exact inverse levels or equivalent bounded
  integer-polynomial transformations, preserving poles and negative-power
  zero-base exclusions while certifying irrational roots.
- Added lower- and upper-tail inverse regularized incomplete gamma functions
  `gammaincinv()` and `gammainccinv()`, including analytic probability
  derivatives and scan-independent equation inversion for polynomial inputs.
  Infinite and sub-float quantiles are rejected rather than returned as
  misleading finite values.
- Added tail-aware inverse regularized beta functions `betaincinv()` and
  `betainccinv()`, with analytic probability-argument derivatives and
  scan-independent equation inversion for polynomial probability arguments.
  Extreme representable tails are refined to adjacent floats; quantiles that
  round to an endpoint are rejected.
- Extended exact rational polynomial root counting and isolation through the
  configured polynomial degree limit of 256, subject to bounded arithmetic and
  subdivision limits.
- Inverted positive-argument `digamma()` equations over polynomial arguments
  before solving all polynomial preimages, avoiding scan-grid misses.
- Extended scan-independent polynomial level inversion to supported
  `polygamma(order, argument)` equations on strictly positive argument ranges.
- Inverted fixed-integer-order `besseli(order, argument)` levels over the full
  supported real argument range, using signed monotone branches for odd orders
  and separate positive/negative branches for even orders.
- Reduced the exact real levels `besseli(0, f(x)) = 1` and
  `besseli(n, f(x)) = 0` for nonzero integer orders to the inner zero equation,
  including arguments that change sign.
- Reduced equal `besseli()` calls with matching absolute integer orders through
  parity-aware argument levels, including infinite identity sets.
- Cancelled equal digamma- and polygamma-family calls on certified strictly
  positive argument ranges, including fixed orders through 128.
- Added the real beta function `beta(a, b)` for finite non-pole arguments above
  the negative real cutoff, including positive arguments greater than 171;
  evaluated in log-gamma space and differentiated analytically by both
  arguments. Equation and inequality searches partition at its argument poles
  and negative real-domain boundaries.
- Added the real Riemann zeta function away from its pole at one, using the
  Hasse eta series for nonnegative inputs and the functional equation for
  negative inputs, including its trivial zeros. Exact negative integer parity
  is preserved beyond the exactly representable float range. Scalar equation
  solving enumerates trivial-zero levels through polynomial arguments, and a
  Laurent expansion stabilizes evaluation near the pole.
- Improved direct zeta equation solving for large targets near the pole by
  selecting the nearest representable input when the exact root falls between
  adjacent floats, while reporting its actual residual and rejecting roots
  beyond input-float resolution.
- Improved multivariable system recovery by retrying failed local solves from
  bounded deterministic perturbations of individual initial coordinates.
- Solved underdetermined affine systems exactly by projecting the initial guess
  onto the rational solution set, avoiding numerical iteration for these cases.
- Added real inverse error functions `erfinv()` and `erfcinv()` with domain checks,
  tail-preserving inversion, and analytic derivatives.
- Inverted polynomial levels and nested forward/inverse compositions of the
  error and complementary error functions independently of scan density.
- Fixed inequality domain partitioning at the finite input limits of `gamma()`
  and `lgamma()`, including preimages through reciprocal expressions.
- Fixed inequality domain partitioning at the finite argument limits of
  `besselj()` and `besseli()`, preserving their inclusive endpoints.
- Added `polygamma(order, x)` for integer orders 0 through 128, with
  recurrence, reflection, and an Euler-Maclaurin tail for higher orders.
- Added analytic chain differentiation and inequality pole partitioning for
  general polygamma expressions.
- Added real `digamma()`, `trigamma()`, and `tetragamma()` functions with pole
  validation and reflection formulas, plus analytic differentiation of
  `digamma()` and `trigamma()`.
- Partitioned inequality searches at non-positive integer poles of the
  polygamma functions, including preimages in polynomial and bounded elementary
  arguments even when those poles fall between scan points.
- Reported infinite polygamma pole accumulation at logarithmic domain
  boundaries instead of returning finite intervals that cross those poles.
- Reported infinite periodic root accumulation when growing gamma-family
  functions tend to positive infinity at a domain boundary.
- Extended periodic boundary growth analysis through logarithms, inverse
  hyperbolic functions, Lambert W, and unambiguous arithmetic wrappers.
- Detected accumulating inner periodic zero sets when they coincide with a
  phase level of nested sine, cosine, or tangent equations.
- Reduced equations of the form `a*u^n*ln(u) + b*u^n = c` and constant-base
  logarithm equivalents through real Lambert W branches when `n` is a finite,
  nonzero real value within the configured polynomial-degree limit and `u` is
  a polynomial or rational function, including shifted tangencies and both
  real branches independently of scan density.
- Reduced affine self-power equations of the form `u^(-u) = c` through the
  reciprocal `u^u` levels, preserving negative-integer real-domain branches.
- Reduced positive-base equations `u^(k*u) = c` to self-power levels for
  finite nonzero real `k`, with interval range checks before transforming.
- Reduced `exp(k*u*ln(u)) = c` to the same self-power levels when the
  polynomial base is strictly positive on the interval, preserving both
  preimages of tangential polynomial levels.
- Extended self-power level solving to positive rational bases and scaled
  exponents, using rational range analysis and original-equation validation to
  exclude removable holes.
- Factored zero-target positive powers of non-polynomial bases before
  exponential expansion, preventing repeated roots from splitting into
  nearby residual-tolerance candidates, and filtered factor roots that coincide
  with denominator holes.
- Solved zero-target `hypot()` equations with non-rational coordinates by
  combining scalar coordinate roots and validating candidates in the original
  equation, including shared roots with no sign change.
- Partitioned sparse inequalities at validated shared zeros of non-polynomial
  `hypot()` coordinates, while treating these points as comparison roots only
  when the complete inequality sides are equal.
- Partitioned sparse inequality searches at non-polynomial zeros of both
  `atan2()` coordinates, so same-truth-state intervals still exclude undefined
  origins while defined axis crossings remain included.
- Solved zero-level polynomial and rational `hypot()` equations by re-solving
  one coordinate in its original factored form and validating against the
  original two-coordinate equation, avoiding ill-conditioned squared common
  roots and filtering denominator holes.
- Added direct chain-rule derivatives for two-argument `log()`, `hypot()`, and
  `atan2()` expressions, including explicit nondifferentiability handling at
  singular points.
- Added bounded multiplicative Newton candidates in log-magnitude coordinates
  for nonzero variables in multivariable systems, improving convergence when
  solved variables have widely separated scales.
- Scaled singular-Jacobian coordinate probes per variable sensitivity, helping
  system solving escape zero-valued initial guesses across mixed scales.
- Added `Math::solvePolynomialComplex()` for approximate complex roots of
  univariate polynomial equalities, with residuals and an explicit
  convergence flag. Monomials and two-term polynomials use closed-form roots,
  including high-degree root-of-unity families and factored zero roots.
  General polynomials now factor exact powers of the variable before iteration,
  preserving repeated zero roots exactly and evaluating residuals against the
  original polynomial. An Aberth-Ehrlich fallback now improves candidate root
  recovery when Durand-Kerner stalls across widely separated root magnitudes;
  scale-normalized backward residuals determine convergence across roots of
  different magnitudes.
  Repeated powers of a single linear factor are recovered by coefficient
  reconstruction before general root iteration. Repeated powers of a single
  quadratic factor, including conjugate pairs, are recovered the same way.
- Re-expanded higher-degree polynomials containing translated integer powers in
  affine coordinates over the requested interval before root isolation, avoiding
  coefficient cancellation for expressions such as `(x - 100)^10 - 1 = 0`.
- Isolated additive offsets and nonzero constant scalar factors around powers of
  nontrivial polynomial bases before solving, preserving the real branches for
  even powers and non-unit targets.
- Reduced sums and products of exponentials, including positive constant-base
  powers, whose polynomial arguments have nonconstant parts that are integer
  multiples of one shared phase to bounded positive-root polynomial equations.
  Integer powers and finite products are expanded within configured bounds,
  preserving repeated and closely spaced roots independently of scan
  resolution. Rational expressions are cross-multiplied and verified against
  the original equation to reject poles.
- Inverted polynomial equations reducible to `u^n*exp(a*u+b) = c` through the
  real Lambert W branches for positive integer powers, including constant
  scaling and additive offsets, while filtering and deduplicating candidates
  against the original equation.
- Expanded bounded products of sine and cosine terms with polynomial phase
  arguments using product-to-sum identities before root isolation.
- Added polynomial and bounded non-polynomial integer exponent levels as
  candidates for variable-power equations, then refined and verified each
  candidate against the original equation and real-domain rules. Conservative
  interval arithmetic, `hypot()`, `sech()`, `sec()`, and `csc()` extrema,
  monotone function compositions, sine/cosine ranges, and bounded `sinc()` and
  `sincpi()` ranges include interior extrema. Reciprocal hyperbolic `csch()`
  and `coth()` ranges are bounded only when their input intervals exclude
  zero. Tangent, cotangent, secant, and cosecant ranges are bounded only on
  intervals without poles. Two-argument `log()` ranges use interval arithmetic
  when the value and base stay in the real domain and the base stays away from
  one. Inverse reciprocal and inverse hyperbolic reciprocal ranges are
  restricted to their real monotonic branches. `atan2()` ranges include axis
  crossings and conservatively cover its principal branch cut. Conservative
  `gcd()`/`lcm()` output bounds expose integer exponent levels while retaining
  operand-domain verification. Factorial exponent levels, including affine
  wrappers, enumerate their bounded valid integer operands and solve
  non-polynomial operand equations. Integer levels of `floor()`, `ceil()`, and
  `round()` combined with an affine multiple of their own argument are isolated
  at integer argument boundaries, including tangent boundaries of
  non-polynomial arguments. Modulo ranges conservatively bound integer
  remainders by the divisor magnitude and preserve the dividend sign.
  This finds
  isolated and tangent roots such as `(-x)^exp(x) = ln(2)^2` at `x = ln(2)`
  independently of the fallback scan grid.
- Avoided classifying floating-point neighborhoods around isolated flat roots
  of `min()` and `max()` equations as infinite solution intervals.
- Evaluated exact rational powers of negative bases when the reduced exponent
  has an odd denominator, including integer fractions and finite decimals, and
  inverted both real branches for constant-power equations where applicable.
  Polynomial targets are reduced to bounded polynomial equations, then
  candidates are verified against the original expression and real-domain
  constraints, including for even-denominator powers and powers isolated from
  additive polynomial equations. The same reduction applies to additive
  `sqrt()`, `cbrt()`, and constant-degree `root()` terms, including sums of
  rational powers that share an affine polynomial base.
- Reduced scalar multiples of up to four square roots with polynomial
  radicands and polynomial remaining terms to bounded polynomial equations, validating
  candidates against the original equation to reject branches introduced by
  squaring.
- Applied supported scalar-function inverses recursively through nested
  arguments, allowing inner periodic, algebraic, and function equations to use
  their specialized solvers instead of depending on numerical scan density.
  Variable-base `log()` equations with constant values now isolate the base
  level recursively and classify the unit-argument zero-level infinite set.
  Variable-base logs with variable targets reduce to power equations, while
  preserving strict logarithm domains and valid infinite identity sets.
- Enumerated reachable sine and cosine levels through bounded `tanh()` and
  `erf()` values and through `abs()`, `atan()`, `asinh()`, `sinh()`, and
  `acosh()`/`cosh()` ranges, including clipped real domains for `asin()` and
  `acos()`, then solved each inner level recursively with interval-aware
  endpoint handling.
- Reduced `sin()`/`cos()` equalities with polynomial arguments, including mixed
  pairs, `tan()`/`cot()` pairs, and `sec()`/`csc()` reciprocal pairs to periodic
  polynomial level sets instead of scan-dependent roots, including signed
  equalities; poles are excluded.
- Solved bounded-degree integer-coefficient polynomial equations using exact
  decimal-integer differences before floating-point root isolation.
- Adapted numerical differentiation across shrinking, nonuniform step sizes to
  reduce aliasing errors for rapidly oscillating expressions.
- Added `Math::solveEquationSet()` to represent bounded equation solution sets
  as interval unions, including continuous regions and isolated point roots.
- Reported infinite periodic root sets accumulating at genuine poles of rational
  trigonometric arguments, while distinguishing removable factors by multiplicity.
- Reported infinite periodic root accumulation when polynomial-phase `tan()`,
  `sec()`, `cot()`, or `csc()` poles are nested inside another periodic function.
- Reported infinite root accumulation when Gamma or log-Gamma poles are nested
  inside periodic or logarithmic functions.
- Partitioned `gamma()` and `lgamma()` equations at poles of bounded
  non-polynomial arguments, solving nested integer pole levels before the
  numerical scan.
- Reduced constant-target `gamma()` and `lgamma()` compositions to bounded
  outer levels and solved their inner expressions independently of scan density,
  preserving valid roots at the finite supported-domain boundary.
- Clipped bounded ranges for `asin()`, `acos()`, `acosh()`, and `sqrt()` to
  their real domains and preserved inclusion of valid function endpoints when
  partitioning nested equations.
- Detected infinite root accumulation for periodic equations at rational
  logarithm singularities and zeros of supported scaled, repeated-product, or
  complementary smooth-function squares. Also handled `log1p()`'s shifted
  boundary and variable-base logarithm singularities for rational, exponential,
  square-root, hyperbolic, periodic-maximum, squared trigonometric/hyperbolic,
  and positive constant-power bases, while excluding removable factors,
  matched vanishing orders, invalid bases, non-positive logarithm sides, and
  numerically unresolved exact-zero candidates adjacent to a variable-base
  singularity.
- Enumerated periodic trigonometric phase levels for rational-function arguments
  over intervals without genuine poles, including reciprocal-polynomial phases
  and rational arguments with removable holes; retained repeated-root uniqueness
  and excluded candidates at the original denominator's holes. Rational
  `sinc` and `sincpi` equations now enumerate zero levels and invert nonzero
  targets across their monotone lobes, including across rational phase poles
  using the sinc envelope to bound the required phase levels. Quotients of the
  form `sin(p(x))/p(x)` use the same inversion for supported polynomial and
  rational `p`, while retaining the original quotient's undefined points.
- Isolated rational-polynomial equation roots by cross-multiplying bounded
  numerator and denominator polynomials, then validating candidates against the
  original equation to reject poles.
- Inverted supported scalar functions of rational arguments into polynomial
  levels, preserving tangencies and excluding candidates at original denominator
  holes.
- Solved `floor()`, `ceil()`, and `round()` equations of rational arguments by
  enumerating their boundary levels and preserving denominator holes.
- Enumerated bounded integer levels for finite-range rational arguments to
  `gcd()` and `lcm()`; constant-operand rational poles now enumerate finite
  `lcm()` divisor levels or report infinite `gcd()` root accumulation.
- Improved `erf` accuracy to near machine precision and evaluated `erfc` directly,
  preserving small complementary tails and their polynomial equation inverses.
  Inverse-`erf` brackets now scale to tiny nonzero targets instead of accepting
  zero after a fixed-width search.
- Added real Lambert W branches `lambertw(x)` and `lambertw(x, -1)`, with
  bracketed evaluation, analytic inversion over polynomial arguments, and
  branch-aware inequality domain boundaries.
- Isolated the discrete valid preimages of variable Lambert W branch arguments
  at branches `0` and `-1` in inequality solving, even when they fall between
  scan samples.
- Added stable unnormalized `sinc(x)` and normalized `sincpi(x)` built-ins,
  including their removable values at zero; polynomial-argument zero equations
  enumerate all nonzero phase levels without relying on scan resolution.
- Decomposed zero-product and zero-quotient equations across polynomial and
  non-polynomial factors, ignored reciprocal powers as zero-free factors, and
  verified candidates against the original expression to preserve its domain.
- Added domain-checked integer-degree real roots through `root(value, degree)`,
  including odd roots of negative values and reciprocal negative degrees.
- Solved polynomial radicands with constant integer `root()` degrees by
  isolating their polynomial level equations, including reciprocal degrees.
- Classified even-root endpoints and negative-degree root singularities as
  included or excluded inequality boundaries, respectively.
- Enumerated bounded integer degree levels for equations where `root()`'s degree
  is polynomial in the solved variable, avoiding missed discrete-domain solutions.
- Isolated sampled integer levels for transcendental `root()` degree expressions
  by refining detected stationary points and solving their level equations
  with floating-point-scale accuracy.
- Accepted only machine-roundoff-sized error in computed integer root degrees,
  such as the result of squaring a numerically computed square root.
- Scaled nonlinear-system residuals by initial equation-side magnitudes, with
  variable-scaled probes for initially zero rows, so equivalent row-scaled
  systems retain the same weighting.
- Checked nonlinear-system convergence against current equation-side
  magnitudes, preventing tiny nonzero targets from being accepted as zero, and
  increased the default iteration budget to reach distant roots.
- Scaled zero-side nonlinear-system residuals by local equation variation at
  the current variable magnitudes, avoiding premature convergence on tiny-scale
  systems while retaining calibrated fallback scales at zero variation.
- Used expanding bounded root searches for one-variable nonlinear systems
  before falling back to local Newton iteration.
- Isolated real roots for single-variable polynomial systems, checked
  overdetermined constraints against each candidate, and selected the common
  root nearest the supplied initial value.
- Continued refining steep sign-changing roots until the residual tolerance is
  met instead of discarding a bracket at position tolerance.
- Verified polynomial and numerical root candidates against the local equation
  scale, preventing large residuals elsewhere in the interval from making
  nearby non-roots appear acceptable.
- Solved explicit polynomial products equal to zero by isolating their factors
  independently, avoiding expanded-coefficient instability for repeated roots
  while retaining close roots from distinct factors.
- Used a compensated discriminant and stable quadratic formula to preserve
  closely spaced real roots that are hidden by near-zero critical-point values.
- Kept sign-bracketed roots on either side of a near-zero critical point and
  refined brackets to adjacent floats, preserving close real roots in cubics.
- Reduced positive constant powers equal to zero to their polynomial bases,
  avoiding unnecessary high-multiplicity expansion.
- Used the extracted polynomial consistently for root isolation, then verified
  candidates against the original equation to avoid AST-rounding sign artifacts.
- Retained polynomial root isolation when derivative solving is inconclusive by
  recursively sampling stationary points, and used exact power-of-two scaling
  to reduce roundoff around repeated roots.
- Normalized bounded solving intervals for moderate-degree expanded polynomials
  before derivative isolation, improving recovery of clustered roots while
  verifying every candidate against the original equation.
- Analytically enumerated bounded roots for direct periodic trigonometric
  equations with polynomial arguments, including reciprocal trigonometric functions.
- Enumerated periodic trigonometric phase levels when the argument is an
  exponential of a polynomial, avoiding scan-dependent misses for rapidly
  growing oscillations such as `sin(exp(x^2)) = 0`.
- Inverted periodic phases through exponential square roots of polynomial
  arguments, finding both sides of symmetric root families at coarse scan
  resolutions.
- Enumerated periodic levels through arbitrarily nested exponentials of
  polynomial arguments, isolating oscillatory roots by repeated logarithmic
  inversion instead of scan-grid sampling.
- Inverted nested exponential phases composed with polynomial square roots by
  solving the squared repeated-log phase levels.
- Reduced logarithms of positive exponential/power products, quotients, and
  nested powers to polynomial arguments before periodic phase enumeration.
- Enumerated periodic phases over square roots of polynomial arguments by
  solving their squared phase levels, including `sin(sqrt(x^2)) = 0` at coarse
  scan resolution.
- Inverted periodic trigonometric equations of natural, base-10, base-2, and
  constant-base logarithms of affine or positive polynomial arguments,
  retaining tightly clustered roots near zero and reusing those boundaries for
  inequalities.
- Reported infinite periodic-logarithm root families that accumulate at a
  polynomial domain boundary instead of returning a silently truncated list;
  the inequality solver propagates the same finite-representation error.
- Inverted outer sine, cosine, and tangent levels over the bounded range of
  nested sine/cosine functions, preserving exact roots in rapidly oscillating
  compositions such as `sin(sin(exp(x))) = 0`.
- Analytically enumerated affine equations combining sine and cosine terms with
  the same argument, combined repeated terms, and reduced sine/cosine squares
  and products with equal or opposite affine arguments through
  double-angle identities independent of scan resolution; factored zero-level
  sums and differences of affine sine or cosine terms by sum-to-product identities,
  including nonzero-target phase-shift reductions, constant-factor cases, and
  non-finite identities.
- Isolated nonzero-target sums and differences of rational-ratio, phase-shifted
  affine sine and cosine harmonics through bounded Chebyshev-polynomial equations
  in the cosine level, within the configured polynomial-degree limit.
- Adaptively increased fallback scan density for periodic and steep
  transcendental functions of polynomial arguments, with bounded work for
  high-frequency or rapidly varying equations.
- Rewrote bounded products of up to nine affine sine/cosine factors through
  product-to-sum identities before exact harmonic-polynomial root isolation.
- Inverted constant real powers into their valid base levels, including
  positive, negative, odd, even, and fractional exponents; this preserves narrow
  transcendental roots and avoids duplicate high-multiplicity results.
- Split absolute-value equations into signed inner levels, including
  variable-dependent targets, nested absolute values, and narrow
  transcendental branches.
- Inverted supported exponential, logarithmic, root, absolute-value, and
  hyperbolic and inverse trigonometric functions of polynomials into bounded
  polynomial level equations.
- Inverted `asech`, `acsch`, and `acoth` equations over polynomial arguments,
  with their real output domains enforced.
- Inverted `erf` and `erfc` equations over polynomial arguments so bounded
  polynomial level roots do not depend on the numerical scan grid, and excluded
  their unattainable asymptotic endpoints despite floating-point saturation.
- Reduced equations with equal injective scalar functions, including logarithms
  with a shared base expression, to their argument equations; this avoids false
  roots caused by floating-point overflow or underflow.
- Reduced `hypot()` equations with polynomial or rational arguments to
  polynomial level equations, including cases where both coordinates depend on
  the variable, while excluding candidates at original denominator holes.
- Reduced equal `root()` calls with matching degree expressions, including
  algebraically equivalent integer-polynomial degrees and differently written
  equal constants, to radicand equations, then verified candidates against the
  original real domains, including even-root and negative-degree restrictions.
  Constant logarithm bases receive the same equivalence check.
- Reduced `atan2()` equations with rational coordinates to polynomial level
  equations and retained principal-angle and denominator-hole verification.
- Partitioned inequalities at polynomial coordinate-axis crossings for `atan2()`
  so sparse scans detect solution intervals adjacent to its principal branch cut.
- Avoided squared-target underflow for affine `hypot(p(x), 0)` equations and
  retained distinct roots at very small coordinate scales.
- Scaled the repeated-root deduplication allowance by root magnitude so it
  does not merge distinct near-zero roots when tighter tolerances are requested.
- Avoided returning zero as a root when inverting tiny square- and cube-root
  targets whose polynomial levels cannot be represented as floating-point values.
- Avoided underflow in constant-degree `root()` inversion of affine radicands by
  scaling the target power by the polynomial slope before evaluating it.
- Preserved inequality intervals between adjacent floating-point polynomial
  and rational roots when no representable midpoint exists to sample.
- Preserved factorized rational `sign()` roots and detected infinite solution
  intervals between adjacent floating-point zeros and poles.
- Solved `atan2()` equations with polynomial coordinates by isolating
  collinear polynomial levels and verifying the requested principal quadrant.
- Solved `min()` and `max()` equations over polynomial arguments by isolating
  each target level and retaining only roots on an active branch.
- Isolated finite polynomial boundary solutions for `floor()`, `ceil()`, and
  `round()` equations, while leaving interval-valued cases to the general solver.
- Enumerated bounded polynomial integer levels for `gcd()` and `lcm()` equation
  arguments, then verified candidates against their integer domains.
- Allowed `gcd()` and `lcm()` to absorb only machine-roundoff-sized error in
  computed integer arguments while continuing to reject fractional inputs.
- Partitioned gamma-family equation scans at polynomially transformed poles
  and the finite-argument boundary.
- Evaluated positive gamma values in log space to avoid intermediate overflow
  when the final result is still representable.
- Applied gamma reflection in log space for negative arguments near the domain
  limit, and computed `lgamma()` without materializing gamma first.
- Preserved included domain endpoints for square root and inverse trigonometric
  and hyperbolic functions, including inverse reciprocal functions, when
  classifying inequalities.
- Enumerated bounded integer-level sets for equations containing factorials of
  supported polynomial expressions, so roots do not depend on the continuous scan grid.
- Allowed factorial to absorb only machine-precision rounding error in computed
  operands while keeping ordinary fractional inputs invalid.

- Added domain-checked inverse reciprocal trigonometric functions `asec`,
  `acsc`, and `acot` to the default function registry.
- Extended `Math::solveSystem()` to solve overdetermined systems through
  damped least-squares steps while continuing to reject underdetermined systems.
- Improved numerical equation solving with scale-relative residual checks,
  refined repeated-root candidates, and off-grid identity probes for domain holes.
- Partitioned numerical equation scans at variable denominator zeros so removable
  singularities are not reported as roots while nearby valid roots remain detectable.
- Refined equation scans geometrically near logarithmic, reciprocal trigonometric,
  reciprocal hyperbolic, and gamma-family domain boundaries.
- Solved constant-radicand equations with variable integer root degrees by isolating
  the required degree before solving its expression over the requested interval.
- Detected non-isolated solution intervals during numerical equation scans instead
  of returning scan points as a finite root list.
- Enumerated integer operand levels for polynomial modulo equations, including
  affine and nonlinear operands, before validating candidates against the equation.
- Partitioned equation sets across multiple bounded `floor()`, `ceil()`, and
  `round()` operands inside modulo expressions, using polynomial extrema or
  conservative expression ranges for `min()`, `max()`, `sign()`, two-argument
  `log()`, both bounded real `lambertw()` branches, `atan2()`, `gcd()`/`lcm()`
  integer domains, pole-free trigonometric functions, monotone hyperbolic
  functions, and clipped real domains, then validating each substituted cell.
- Partitioned piecewise-function inequalities at exact integer and half-integer
  jumps for signed-variable `floor`, `ceil`, and `round` arguments.
- Enumerated bounded non-negative integer preimages for factorial inequalities,
  including nested factorials and factorials of `floor()`, `ceil()`, and `round()`
  arguments, using conservative bounded ranges to preserve isolated points and
  selector plateaus.
- Enumerated polynomial integer operand levels in modulo inequalities and returned
  valid discrete solutions as singleton intervals.
- Classified floating-point roundoff identities relative to equation-side
  magnitudes so non-polynomial identities do not become sampled root lists.
- Avoided inserting approximate inequality transitions beside already isolated
  function-domain boundaries.
- Coalesced duplicate bracket candidates at odd-multiplicity polynomial roots
  without merging separately bracketed close roots.
- Retried nonlinear-system iterations with coordinate steps after damped
  Newton candidates stall at a rank-deficient initial point.
- Improved numerical derivatives with Richardson extrapolation to reduce
  truncation noise around flat roots and stationary points.

## [0.3.6] - 2026-09-07

### Added

- Added finite, domain-checked special functions `erf`, `erfc`, `gamma`, and
  `lgamma` to the safe built-in registry.
- Added Core facade, registry, and domain-boundary tests for the new functions.

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

- Reduce multi-term odd rational-power sums over non-affine rational-function
  bases when all real roots of the transformed common-root polynomial are
  rational, or when that polynomial is quadratic with two real roots, while
  preserving rational-function poles. Also reduce cubic common-root
  polynomials with three distinct real roots through their odd-power image
  polynomial, and quartic common-root polynomials with four distinct real roots
  through exact companion-matrix powers and Newton identities, preserving
  base-zero and pole exclusions. Extend the same exact image-polynomial
  construction through degree eight when all common-root levels are distinct
  and real.
- Added integer-order `besselj(order, x)` evaluation for orders -256 through 256
  and real arguments from -1000 through 1000, with symmetry handling, backward
  recurrence for large arguments, and explicit domain errors outside the range.
- Added integer-order `besseli(order, x)` evaluation for orders -256 through 256
  and arguments -713 through 713, using compensated summation, plus analytic
  chain-rule derivatives for both Bessel families.
- Added integer-order `bessely(order, x)` evaluation for orders -256 through
  256 and positive arguments through 1000, with series and large-argument
  asymptotic evaluation, parity, recurrence, derivatives, finite small-argument
  asymptotics, solver domains, and variation-based scan sizing.
- Report infinitely many roots for complex zero equations of fixed supported
  integer-order `besselj`, `besseli`, or `bessely` with nonconstant polynomial
  arguments, including negative integer orders.
- Isolated polynomial variable orders of `besselj()`, `besseli()`, and
  `polygamma()`, and variable `lambertw()` branches, at supported integer levels.
  Non-polynomial level equations are solved recursively and all candidates are
  verified against the original expression. Equation-set solving intersects
  level preimages with solution intervals to preserve continuous plateaus.

- Reduce nonnegative variable-base power equations with positive constant
  targets through logarithmic equations, while routing supported integer
  powers of polynomial and rational-polynomial bases through direct polynomial
  level isolation. Unit targets use exact base-one and exponent-zero level sets,
  including the `0^0 = 1` convention and avoiding false near-roots at flat
  contacts. Ill-conditioned rational-power candidates may be accepted when the
  local correction is below floating-point variable resolution; their measured
  residual remains reported.
- Enumerate rounding-function levels in polynomial equations with polynomial
  coefficients, including equations with multiple `floor()`, `ceil()`, or
  `round()` terms and exact roots at their discontinuities.
- Reduce same-argument `floor(g) + ceil(g)` equations to parity-dependent
  open ranges or level equations, retaining rational poles and removable holes
  in interval-set results.
- Detect infinite root accumulation at endpoints and interior singularities for
  periodic equations with
  unbounded composed phases, including exponential-of-reciprocal, logarithmic,
  and reciprocal-square-root phases, while leaving bounded decaying
  exponentials outside that classification.
- Range-check and enumerate zero levels for sine, cosine, and tangent equations
  with rationally nonpositive exponential exponents, preventing underflow from
  masquerading as an interval of roots or hiding finite roots.

- Added `Math::differentiate()` with fourth-order finite differences and
  one-sided stencils near expression-domain boundaries.
- Added bounded real inequality solving through `Math::solveInequality()`,
  returning interval components with open and closed endpoint metadata.
- Improved non-polynomial equation searches with derivative-based stationary
  point partitioning to help detect paired and tangential roots between samples.
- Added stable real hyperbolic reciprocal functions (`sech`, `csch`, `coth`)
  and their inverse functions (`asech`, `acsch`, `acoth`) with domain checks.
- Bounded real-root solving for one-variable equations through `Math::solve()`;
  the numerical search reports detected roots without claiming completeness.
- Analytically invert `root(value, degree) = target` when the degree is a
  constant nonzero integer, including rational value expressions and negative
  degrees.
- Reduce variable-target equations with constant-integer `root()` degrees to
  power equations, then verify candidates against the principal-root branch and
  original expression domain.
- Classify transformed `root()` identities against the principal branch, so
  inadmissible intervals are excluded, isolated zero targets are retained, and
  admissible infinite solution intervals are reported accurately.
- Detect infinite roots accumulating at rational phase poles when a periodic
  function has rational scaling and offset, including boundary targets and
  equations split from `abs()`.
- Bounded numerical real-root isolation for supported polynomials through
  degree 32, using derivative partitioning and residual verification.
- Added local Newton solving for square systems of up to 32 equations and
  variables through `Math::solveSystem()`, with fixed parameters and damped
  Householder-QR least-squares fallback for singular Newton steps.
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
