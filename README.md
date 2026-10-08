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
Math::evaluate('|x - 2|', ['x' => 5]); // 3
Math::evaluate('⌊x⌋ + ⌈x⌉', ['x' => 1.2]); // 3.0
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

The expression grammar accepts explicit multiplication (`2 * x`), absolute
value bars (`|x - 2|`), floor/ceiling brackets (`⌊x⌋`, `⌈x⌉`), common
Unicode notation (`×`, `÷`, `−`, `√`, `π`, `τ`, `φ`, and superscript powers
such as `x²` or `x⁻²`). Subscript digits are accepted in variable names and
normalized for portable lookup (`x₁₀` uses the variable key `x10`). The
standard implicit forms include (`2x`, `2(x + 1)`, and
`(x + 1)(x - 1)`). Identifiers remain atomic, so
`xy` is one variable name; write `x y` or `x * y` when two factors are
intended.

The built-in function registry includes elementary, trigonometric (including
`sec`, `csc`, `cot`, `asec`, `acsc`, and `acot`), hyperbolic (including
`sech`, `csch`, and `coth`), numerical,
integer, combinatorial, and special functions such as `erf`, `erfc`, `erfi`, `erfinv`, `erfcinv`, `airyai`, `airybi`, `ellipk`, `ellipe`, `ellipf`, `ellipeinc`, `ellippi`, `ellippinc`, `gamma`,
`lgamma`, `gammainc`, `gammaincc`, `gammaincinv`, `gammainccinv`,
`loggammainc`, `loggammaincc`, `gammaincinvlog`, `gammainccinvlog`, `betainc`,
`betaincc`, `betaincinv`, `betainccinv`, `logbetainc`, `logbetaincc`,
`betaincinvlog`, `betainccinvlog`, `beta`, `logbeta`, `zeta`, `zetaprime`,
`airyaiprime`, `airybiprime`, `digamma`, `trigamma`, `besselj`, and
`besseli`, plus `polygamma`, `bernoulli`, integer-order `bessely`/`besselk`, real Lambert-W
`lambertw`, and inverse hyperbolic functions
`asech`, `acsch`, and `acoth`.
The real confluent hypergeometric function \( {}_1F_1(a;b;z) \) is available
as `hyp1f1(a, b, z)` (also `confluenthypergeometric`); its second parameter
must not be a non-positive integer. The series uses Kummer's transformation for
negative arguments and reports non-convergence or overflow explicitly.
The confluent hypergeometric limit function \( {}_0F_1(;b;z) \) is available
as `hyp0f1(b, z)` (also `confluenthypergeometriclimit`) for finite real inputs;
its parameter must not be a non-positive integer.
Gauss's real hypergeometric function \( {}_2F_1(a,b;c;x) \) is available as
`hyp2f1(a, b, c, x)` (also `gausshypergeometric`). It supports convergent real
inputs through `x = 1`, and terminating polynomial cases beyond one; complex
continuation is not provided.
The generalized function \( {}_3F_2(a,b,c;d,e;x) \) is available as
`hyp3f2(a, b, c, d, e, x)` (also `generalizedhypergeometric3f2`). Its real
series is supported for `|x| < 1`, at `|x| = 1` when the excess
`d + e - a - b - c` is positive, and for terminating numerator parameters.
For other orders, `hypergeom(p, q, a1, ..., ap, b1, ..., bq, x)` evaluates a
generalized \( {}_pF_q \) series, with `p` and `q` specifying the numerator
and denominator parameter counts. It supports total orders up to 13, rejects
denominator poles and divergent domains, and reports overflow or failure to
converge within its iteration limit explicitly.
General real roots are available as `root(value, degree)` and its `nthroot`
alias; even roots reject negative radicands and degrees must be positive
integers. Integer helpers also include the truncated-remainder aliases `mod` and
`modulo`; both require finite integer operands and reject a zero divisor.
`sum(x1, ..., xn)` and `product(x1, ..., xn)` accept one to sixteen finite
values. They preserve integer results when all inputs are integers and the
result fits the host integer range; `sum` promotes overflowing integer totals
to a compensated floating-point calculation, while `product` reports integer
or floating-point overflow. `clamp(x, lower, upper)` bounds a value to the
closed interval and rejects reversed bounds. `lerp(start, end, t)` performs
linear interpolation (and extrapolation for `t` outside `[0, 1]`);
`invlerp(start, end, value)` returns the corresponding normalized parameter and
rejects equal endpoints. Mean functions accept between one and sixteen values:
`mean` (alias
`arithmeticmean`) computes the arithmetic mean; `geometricmean` and
`harmonicmean` accept non-negative values and return zero if any input is zero;
`powermean(p, x1, ..., xn)` computes the generalized mean of order `p` for
non-negative values, specializing to the arithmetic, geometric, and quadratic
means at orders 1, 0, and 2. Negative orders require strictly positive values.
`logarithmicmean(a, b)` (alias `logmean`) computes `(b - a)/(log(b) - log(a))`
for finite positive values, using its continuous limit `a` when `a = b` and
stable evaluation when the inputs are close.
`meanabsolutedeviation` (alias `meanabsdev`) returns the average absolute
distance from the arithmetic mean, distinct from `mad`, which is the median
absolute deviation. `standarderror` (alias `sem`) returns the sample standard
deviation divided by the square root of the sample size and therefore requires
at least two observations. `coefficientvariation` (alias `rsd`) returns the
population standard deviation divided by the absolute arithmetic mean; it is
undefined when that mean is zero.
`quadraticmean` (alias `rms`) computes the root-mean-square and also supports
signed values. `median` returns the middle sorted value (or the average of the
middle pair). `variance` and `stddev` (aliases `stdev` and `standarddeviation`)
use population formulas; `samplevariance` and `samplestddev` use sample
correction and require at least two values. The functions accept signed finite
values. `quantile(q, x1, ..., xn)` sorts the observations and uses Type 7
linear interpolation (`h = (n - 1)q`) for `q` in `[0, 1]`; `iqr(x1, ..., xn)`
returns the third quartile minus the first. Scaled calculations avoid
unnecessary intermediate overflow.
`mad(x1, ..., xn)` returns the raw median absolute deviation: the median of
`abs(xi - median(x))`. It is a robust spread statistic and is not multiplied
by a normal-consistency factor.
`skewness`, `kurtosis`, and `excesskurtosis` compute standardized population
central moments. `kurtosis` uses Pearson's convention (normal = 3), while
`excesskurtosis` subtracts 3 (normal = 0). These shape statistics are undefined
for constant data and reject zero-variance samples. `sampleskewness` applies
the adjusted Fisher-Pearson correction and requires at least three values;
`sampleexcesskurtosis` returns the unbiased Fisher excess estimator and requires
at least four.
`centralmoment(k, x1, ..., xn)` returns the population central moment of
integer order `k` (0 through 16); the first and second orders are 0 and the
population variance, respectively. It requires at least one observation and
reports overflow if the finite result exceeds the floating-point range.
`covariance(x1, y1, ..., xn, yn)` and `samplecovariance(...)` return population
and sample covariance for interleaved paired observations. `correlation(...)`
returns Pearson's correlation coefficient; it is undefined if either variable
is constant. These functions require at least two pairs. `weightedcovariance`
and `weightedcorrelation` accept interleaved `(x, y, weight)` triplets and use
non-negative weights with at least one positive weight.
Weighted variants are also available as `weightedmean` (alias
`weightedarithmeticmean`), `weightedgeometricmean`, `weightedharmonicmean`, and
`weightedquadraticmean` (alias `weightedrms`). Pass interleaved value/weight
pairs, for example `weightedmean(10, 1, 20, 3)`. Weights must be non-negative
with at least one positive weight; geometric and harmonic weighted means also
require non-negative values, with zero-valued positive-weight terms yielding
zero.
Number-theory helpers include `totient(n)` (alias `eulerphi`), `cototient(n)`,
`multiplicativeorder(a, n)` (alias `modorder`),
`isprimitiveroot(a, n)`,
`modinverse(a, n)` (alias `modinv`),
`jacobisymbol(a, n)`, `legendresymbol(a, p)`, `kroneckersymbol(a, n)` (alias
`kronecker`),
`jordan(n, k)`, `dedekindpsi(n)`,
`divisorcount(n)`, `unitarydivisorcount(n)`, `divisorsum(n[, k])` (also
`sigma(n, k)`), `unitarydivisorsum(n)`, `unitaryaliquotsum(n)`, `isprime(n)`,
`iscomposite(n)`, `isprimepower(n)`, `isperfectpower(n[, k])`,
`issemiprime(n)`, `iscarmichael(n)`, `ispowerful(n)`,
`issquarefree(n)`, `primecount(n)` (alias `primepi`),
`mobius(n)`, `liouville(n)`, `omega(n)`,
`mertens(n)`,
`bigomega(n)`, `radical(n)`,
`carmichael(n)` (alias
`carmichaellambda`), `aliquotsum(n)`, `isperfect(n)`, `isabundant(n)`,
`isdeficient(n)`, `isamicable(a, b)`, `issociable(n, k)`, `ishappy(n)`, `issmith(n)`, and
`vonmangoldt(n)` (alias `mangoldt`). Most accept positive integers up to
`1,000,000,000,000`. `jordan(n, k)` returns the Jordan totient `J_k(n)` for
integer order `k` in `[1, 64]`; `dedekindpsi(n)` returns Dedekind's psi
function. `totient` returns Euler's totient; `divisorcount` and
`divisorsum` count and sum positive divisors; `isprime` returns `1` for a prime
and `0` otherwise; `iscomposite` identifies integers greater than one that are
not prime; `isprimepower` recognizes powers of a single prime;
`isperfectpower(n)` recognizes integers expressible as `a^k` for integers
`a > 1` and `k ≥ 2`; supplying `k` tests that exact exponent (`2 ≤ k ≤ 64`).
By convention, 1 is not a perfect power;
`issemiprime` recognizes integers with exactly two prime factors counted with
multiplicity; `iscarmichael` recognizes composite square-free integers for
which every prime factor `p` satisfies `(n - 1) mod (p - 1) = 0` (Korselt's
criterion); `ispowerful` recognizes integers whose prime factors all occur
with exponent at least two (including 1); and `issquarefree` tests whether no
prime square divides the input;
`mobius` and `liouville` return their respective arithmetic functions; `omega` counts distinct prime factors and `bigomega` counts them
with multiplicity; `radical` returns the product of distinct prime factors.
`mertens(n)` returns the summatory Möbius value `Σ_{k=1}^n μ(k)` (with
`mertens(0) = 0`) and accepts integer `0 <= n <= 10,000,000`.
`cototient(n)` returns `n - totient(n)`, the number of integers from 1 through
`n` that share a factor greater than one with `n`.
`multiplicativeorder(a, n)` returns the least positive exponent `k` such that
`a^k ≡ 1 (mod n)`. The modulus must be from 2 through `1,000,000,000,000`,
and the base must be coprime to it; negative bases are normalized modulo `n`.
`isprimitiveroot(a, n)` returns `1` exactly when `a` is coprime to `n` and its
multiplicative order equals `totient(n)`; otherwise, for a valid modulus, it
returns `0`. It accepts the same modulus range and normalizes negative bases.
`modinverse(a, n)` returns the least non-negative residue `x` satisfying
`a·x ≡ 1 (mod n)` using the extended Euclidean algorithm. It has the same
modulus range and coprimality requirement as `multiplicativeorder`.
`jacobisymbol(a, n)` returns the Jacobi symbol for an integer numerator and
odd positive denominator `n` (up to `1,000,000,000,000`); `n = 1` follows the
standard value 1. `legendresymbol(a, p)` returns the Legendre symbol and
requires an odd prime modulus `p` in that range.
`kroneckersymbol(a, n)` extends the Jacobi symbol to negative and even
denominators, with the standard `(a/0) = 1` for `a = ±1` and 0 otherwise; the
denominator magnitude is limited to `1,000,000,000,000`.
`unitarydivisorcount`, `unitarydivisorsum`, and `unitaryaliquotsum` count, sum,
and sum the proper unitary divisors of `n`. A divisor `d` is unitary when
`gcd(d, n/d) = 1`; all three require the same positive-integer input range as
the standard divisor functions.
`arithderivative(n)` returns the arithmetic derivative defined by `D(p) = 1`
for primes and the Leibniz rule `D(ab) = aD(b) + bD(a)`; for example,
`arithderivative(12) = 16`. Its positive-integer input follows the same bound
as the other factorization-based number-theory functions.
`primorial(n)` multiplies all primes less than or equal to the non-negative
integer `n` (so values below 2 return 1) and throws if the exact result exceeds
the host integer range.
`digitsum(n[, b])` and `digitproduct(n[, b])` sum and multiply the base-`b`
digits of a non-negative integer. `digitalroot(n[, b])` returns its repeated
digit sum, while `additivepersistence(n[, b])` and
`multiplicativepersistence(n[, b])` return the number of repeated digit-sum or
digit-product steps needed to reach one digit. `isharshad(n[, b])` tests
whether a positive integer is divisible by its base-`b` digit sum. The base
defaults to 10 and must be from 2 through 36; digit-product overflow is
reported rather than rounded.
`ishappy(n)` returns `1` if repeatedly replacing `n` with the sum of the
squares of its decimal digits eventually reaches 1, and `0` if the sequence
enters another cycle. It accepts integers from 0 through `1,000,000,000,000`.
`issmith(n)` returns `1` when `n` is composite and its decimal digit sum equals
the sum of the decimal digit sums of its prime factors, counted with
multiplicity; otherwise it returns `0`.
Carmichael returns the exponent of the multiplicative group modulo `n`;
`aliquotsum` returns the sum of proper positive divisors; the three `is...`
classification functions return `1` when the number's proper-divisor sum is
equal to, greater than, or less than the number, respectively, and `0`
otherwise. `isamicable(a, b)` returns `1` when distinct positive integers are
an amicable pair (each equals the other's proper-divisor sum), and `0`
otherwise. `issociable(n, k)` returns `1` when `n` belongs to a proper-divisor
sum cycle of exact length `k` (perfect numbers are length one; amicable pairs
are length two), and `0` otherwise. It accepts cycle lengths from 1 through
1000. `primecount(n)`/`primepi(n)` counts primes up to and including `n`
with an odd-only sieve and accepts integer inputs from 0 through 10,000,000.
`nthprime(n)` returns the nth prime (one-based) using an odd-only sieve and
supports indices from 1 through 1,000,000.
`nextprime(n)` and `prevprime(n)` (alias `previousprime`) return the nearest
prime strictly above or below `n`; inputs are integers from 0 through
10,000,000. `prevprime(n)` is undefined for `n <= 2` and raises a domain error.
`goldbachcount(n)` counts unordered prime pairs whose sum is the even integer
`n`; it requires `4 <= n <= 10,000,000` and reports a domain error for odd
inputs.
`logintegral(x)` (alias `li`) returns the unoffset real logarithmic integral
`Ei(log(x))` for positive finite `x` other than the pole at 1. Thus `li(2)` is
approximately `1.04516` (not zero); a convergent series and continued fraction
are used in the respective stable regions.
`ei(x)` evaluates the real exponential integral `Ei(x)` for finite real `x`
except at its logarithmic pole at zero; it uses the same stable series and
continued-fraction regions. Its derivative is `eiprime(x)` (alias
`exponentialintegralprime`), equal to `exp(x)/x` away from zero.
`ein(x)` evaluates the entire exponential integral
`∫₀ˣ (1 - exp(-t)) / t dt`, including its continuous value zero at the origin
and finite real values on both sides of zero. Its derivative is `einprime(x)`
(alias `entireexponentialintegralprime`), equal to `(1 - exp(-x))/x` with
continuous value 1 at zero.
`sineintegral(x)` (alias `si`) evaluates the odd sine integral
`∫₀ˣ sin(t)/t dt` for finite real `x`, with continuous value zero at the origin.
`cosineintegral(x)` (alias `ci`) evaluates the real cosine integral for positive
finite `x`; zero is its logarithmic singularity. Both use power series near
zero and asymptotic expansions for oscillatory tails.
`cin(x)` (alias `cosineintegralentire`) evaluates the even entire cosine
integral `∫₀ˣ (1 − cos(t))/t dt`, including its value zero at the origin.
Their derivatives are available as `siprime(x)` / `sineintegralprime(x)`
(`sin(x)/x`, continuously extended to 1 at zero), `ciprime(x)` /
`cosineintegralprime(x)` (`cos(x)/x`, for positive finite `x`), and
`cinprime(x)` / `cosineintegralentireprime(x)` (`(1 − cos(x))/x`, continuously
extended to zero at the origin).
`shi(x)` (alias `hyperbolicsineintegral`) evaluates the odd hyperbolic sine
integral for finite real `x`, with continuous value zero at the origin.
`chi(x)` (alias `hyperboliccosineintegral`) evaluates the even hyperbolic cosine
integral for finite nonzero real `x`; it has a logarithmic singularity at zero.
Their derivatives are available as `shiprime(x)` /
`hyperbolicsineintegralprime(x)` (`sinh(x)/x`, continuously extended to 1 at
zero), and `chiprime(x)` / `hyperboliccosineintegralprime(x)` (`cosh(x)/x`,
which is singular at zero).
Both use convergent series near zero and scaled exponential-integral
asymptotics for large arguments, with explicit overflow reporting.
`fresnelc(x)` and `fresnels(x)` evaluate the real Fresnel integrals with phase
`πt²/2`, both continuously defined as zero at the origin and odd for negative
arguments. Series, quadrature, and asymptotic regions keep the oscillatory
tails stable for large finite inputs. Their derivatives are available as
`fresnelcprime(x) = cos(πx²/2)` and
`fresnelsprime(x) = sin(πx²/2)`; both are even functions, and report overflow
when the phase cannot be represented as a finite floating-point value. Their
second derivatives are `fresnelcsecond(x) = -πx sin(πx²/2)` and
`fresnelssecond(x) = πx cos(πx²/2)`; these are odd and use the same finite-phase
limit.
`dilog(x)` (alias `li2`) evaluates the real dilogarithm `Li₂(x)` for finite
`x ≤ 1`, including the logarithmic branch endpoint `Li₂(1) = π²/6`. Argument
transformations reduce the computation to a rapidly convergent power series.
`polylog(s, x)` evaluates the real polylogarithm `Li_s(x)` for finite real
order `s` and `-1 ≤ x ≤ 1`. At `x = 1`, it requires `s > 1` and uses the
Riemann zeta function; at `x = -1`, it requires `s > 0` and uses the
Dirichlet eta function. Interior values use a compensated series and report
failure explicitly if the iteration limit is reached.
`expint1(x)` (alias `e1`) evaluates the exponential integral `E₁(x)` for
positive finite `x`; `expint(n, x)` evaluates `Eₙ(x)` for integer order
`1..64` and positive finite `x`. For example, `expint(2, 1)` is approximately
`0.1484955068`. The generalized function uses upward recurrence for small
arguments and a continued fraction in the larger-argument region.
`logexpint1(x)` (alias `loge1`) and `logexpint(n, x)` return the natural
logarithms of those integrals directly, retaining finite log-tail values after
the corresponding unlogged result underflows.
The
von Mangoldt function returns `ln(p)` when `n` is a power of a prime `p`, and
zero otherwise. The optional exponent `k` for `divisorsum` is an integer in
`[0, 64]` and defaults
to one; `sigma(n, k)` sums the k-th powers of all positive divisors.
The real `zeta` and `zetaprime` implementations analytically continue finite
real arguments other than their pole at one. `eta(s)` (alias `dirichleteta`)
evaluates the Dirichlet eta function for finite real `s`, including `eta(1) =
ln(2)` and the analytically continued values at non-positive arguments. Its
positive-argument branch uses an Euler-accelerated alternating series; its
negative-argument branch uses the relation to the Riemann zeta function.
`dirichletbeta(s)` analytically continues the Dirichlet beta function to finite
real `s`. Positive arguments use an Euler-accelerated alternating series;
non-positive arguments use its Hurwitz-zeta representation. For example,
`dirichletbeta(2)` is Catalan's constant, `dirichletbeta(0) = 1/2`, and
`dirichletbeta(-2) = -1/2`.
`hurwitzzeta(s, a)` (alias `hzeta`) evaluates the Hurwitz zeta function for
finite real `s ≠ 1` and positive `a`, using Euler–Maclaurin summation and
analytic continuation below `s = 1`. At `a = 1` it agrees with `zeta(s)`.
`lerchphi(z, s, a)` evaluates the real Lerch transcendent series for finite
`z` in `[-1, 1]` and positive offset `a`. At `z = 1` it is the continued
Hurwitz zeta (with a pole at `s = 1`); at `z = -1` it uses the continued
difference of two Hurwitz zeta values, except at `s = 1` where an accelerated
alternating series is used. Interior values use compensated summation and
explicitly report failure at the iteration cap.
Digamma and trigamma reject gamma poles. Beta arguments are finite
and positive; Bessel orders are currently finite
integers, with real-valued arguments and domain-checked finite results.
`gammasign(x)` (alias `signgamma`) returns the sign of `Γ(x)` for finite real
non-pole arguments, complementing `lgamma(x)`, which returns `log|Γ(x)|`.
The beta-prime distribution is available through `betaprimepdf(x, a, b)`,
`betaprimecdf(x, a, b)`, `betaprimeccdf(x, a, b)`, and
`betaprimeinv(p, a, b)` and `betaprimeccinv(q, a, b)` (also under the
`beta2*` aliases). The latter directly inverts the survival probability for
`q` in `(0, 1]`, avoiding loss of precision from forming `1 - q`. It models the ratio
of two independent gamma variables and reuses the regularized beta routines
for accurate CDF, survival, and quantile calculations. Stable log-tail
functions `betaprimelogcdf(x, a, b)` and `betaprimelogccdf(x, a, b)` are also
available (aliases `beta2logcdf` and `beta2logccdf`), together with the hazard
and cumulative hazard functions `betaprimehazard` and `betaprimecumhazard`
(and their `beta2*` aliases).
`betaprimemoment(n, a, b)` (aliases `betaprimerawmoment` and `beta2moment`)
returns integer raw moments through order 64 when `b > n`;
`betaprimemean(a, b)` requires `b > 1`, and `betaprimevariance(a, b)`
(aliases `betaprimevar`, `beta2variance`, and `beta2var`) requires `b > 2`.
`betaprimestddev(...)`, `betaprimemedian(...)`, and `betaprimemode(...)` provide
the corresponding standard deviation, median, and mode. The mode is zero when
`a ≤ 1`, otherwise `(a - 1) / (b + 1)`. Summary functions are also available
under matching `beta2*` aliases. `betaprimeskewness(a, b)` (alias
`beta2skewness`) returns the standardized skewness when `b > 3`.
`betaprimeexcesskurtosis(a, b)` and `betaprimekurtosis(a, b)` (with matching
`beta2*` aliases) return Fisher excess kurtosis and Pearson kurtosis when
`b > 4`.
`betaprimeentropy(a, b)` (alias `beta2entropy`) returns differential entropy
in natural-log units for all positive shape parameters.
The inverse-quantile derivatives `betaprimeinvprime(p, a, b)` and
`betaprimeccinvprime(q, a, b)` (aliases `beta2invprime` and
`beta2ccinvprime`) return respectively the positive CDF quantile slope and
negative survival-quantile slope, computed from the density at the quantile.
Combinatorial functions include exact integer-domain
`factorial`/`doublefactorial`/`dfactorial`/`multifactorial`,
`risingfactorial`/`pochhammer`, `fallingfactorial`, `partition`,
`binomial`/`choose`/`ncr`, `multinomial`, `catalan`, and
`permutation`/`permute`/`npr`, and `stirling1`, `stirling1unsigned`, and
`stirling2`, `eulerian`, `lah`, `narayana`, `primepartition`, `motzkin`,
`polygonal`, `pyramidal`, `squarepyramidal`, `pentagonal`, `hexagonal`,
`heptagonal`, `octagonal`, `fibonacci`/`fib`, `lucas`, `pell`,
`jacobsthal`, `jacobsthallucas`, and `stern`. `partition(n)` counts the ways to express a
non-negative integer as an unordered sum of positive integers and throws when
the exact result exceeds the host integer range; its input is bounded to
`0..10000`.
`partitioncount(n, k)` counts partitions of `n` into exactly `k` positive
parts (order does not matter), with `partitioncount(0, 0) = 1` and zero when
`k > n`; it accepts `0 <= n <= 10000` and checks exact overflow.
`distinctpartitioncount(n, k)` counts partitions into exactly `k` distinct
positive parts, using the empty-partition convention at `(0, 0)`; it has the
same input bound and exact overflow behavior.
`distinctpartitions(n)` (alias `partitiondistinct`) counts partitions of `n`
into distinct positive parts; its results are exact and checked for integer
overflow. This equals the number of partitions of `n` into odd parts.
`primepartition(n)` counts partitions using only prime parts, with one empty
partition for `n = 0`; it accepts `0 <= n <= 10000` and reports exact overflow.
`subfactorial(n)` (alias `derangement`) counts permutations with no fixed
points, with `subfactorial(0) = 1`; it requires a non-negative integer and
throws if the exact result exceeds the host integer range.
`stirling1(n, k)` returns signed Stirling numbers of the first kind,
`stirling1unsigned(n, k)` their unsigned counterparts, and `stirling2(n, k)`
Stirling numbers of the second kind. They require integer `0 <= k <= n`; exact
results that exceed the host integer range throw an overflow error.
`eulerian(n, k)` returns the Eulerian number counting permutations of `n`
elements with exactly `k` ascents. It uses `A(0,0) = 1`, requires `0 <= k < n`
(with the `n=0, k=0` convention), and reports exact integer overflow.
`lah(n, k)` returns the unsigned Lah number `n!/k! · C(n - 1, k - 1)`,
counting partitions of an n-element set into k non-empty ordered lists, with
`lah(0, 0) = 1` and `lah(n, 0) = 0` for positive n. It requires `0 <= k <= n`
and `n <= 10000`, with exact overflow checks.
`narayana(n, k)` returns the Narayana number `C(n,k)C(n,k-1)/n`, counting
Dyck paths with exactly `k` peaks (equivalently, non-crossing partitions of
`n` elements into `k` blocks). It requires `1 <= k <= n <= 10000` and checks
the exact result for integer overflow.
`bellnumber(n)` (alias `bell`) returns the nth Bell number, the number of
partitions of an n-element set; it is computed exactly from Stirling numbers
of the second kind and throws if the result exceeds the host integer range.
`motzkin(n)` returns the nth Motzkin number using its exact positive
convolution recurrence and reports overflow beyond the host integer range.
`fibonacci(n)`, `lucas(n)`, and `pell(n)` return exact sequence values for
positive or negative integer indices; `fib` is an alias for `fibonacci`.
Results beyond the host integer range are rejected.
`pelllucas(n)` returns the Pell–Lucas companion sequence with seeds `Q₀ = 2`,
`Q₁ = 2` and recurrence `Qₙ = 2Qₙ₋₁ + Qₙ₋₂`; its index must be non-negative,
and exact overflow is reported.
`nacci(n, k)` generalizes the Fibonacci recurrence to orders 2 through 64:
the first `k - 1` values are zero, value `k - 1` is one, and later values sum
the previous `k` terms. `tribonacci(n)` is the order-three form. Indices are
non-negative and overflow is rejected.
`jacobsthal(n)` and `jacobsthallucas(n)` return non-negative-index sequence
values for the recurrence `aₙ = aₙ₋₁ + 2aₙ₋₂`, with respective seeds `(0, 1)`
and `(2, 1)`; overflow beyond the host integer range is rejected.
Summatory companions `fibonaccisum`, `lucassum`, `pellsum`, `pelllucassum`,
`jacobsthalsum`, `jacobsthallucassum`, `tribonaccisum`, `padovansum`, and
`perrinsum` return the inclusive sum of sequence terms from index zero through
the supplied non-negative index. They use exact checked integer arithmetic
and require indices no greater than 10000.
`padovan(n)` and `perrin(n)` use the order-three recurrence
`aₙ = aₙ₋₂ + aₙ₋₃`, with seeds `(1, 1, 1)` and `(3, 0, 2)` respectively.
Both require non-negative integer indices and reject results outside the host
integer range.
`stern(n)` returns the nth Stern diatomic number with `a(0) = 0`, `a(1) = 1`,
`a(2n) = a(n)`, and `a(2n + 1) = a(n) + a(n + 1)`. It uses the binary form of
the index for logarithmic-time evaluation and accepts non-negative integers.
`polygonal(s, n)` returns the nth s-sided polygonal number for integer `s >= 3`
and non-negative `n`; exact overflow is rejected. Named forms are also
available as `pentagonal(n)`, `hexagonal(n)`, `heptagonal(n)`, and
`octagonal(n)`. `triangular(n)` returns the nth triangular number for a
non-negative integer index, with exact integer arithmetic and an overflow error outside the host
integer range.
`pyramidal(s, n)` sums the first `n` polygonal numbers with `s` sides;
`squarepyramidal(n)` is its square-based form. Both use exact integer
arithmetic and reject results outside the host integer range.
`tetrahedral(n)` returns the sum of triangular numbers from index 1 through
`n`, also using exact integer arithmetic and checked overflow.
`simplex(d, n)` returns the nth d-dimensional simplex number,
`binomial(n + d - 1, d)`, for integer dimension `d >= 1` and non-negative
index `n`. Thus dimensions 2 and 3 agree with `triangular(n)` and
`tetrahedral(n)`; exact overflow is reported.
`superfactorial(n)` returns `1! × 2! × … × n!`, and `hyperfactorial(n)`
returns `1¹ × 2² × … × nⁿ`; both accept non-negative integer indices and
reject results that exceed the host integer range.
`multinomial(n_1, ..., n_k)` returns the exact number of permutations of a
multiset with the supplied non-negative category counts. `catalan(n)` returns
the exact Catalan number for a non-negative integer index; results beyond the
host integer range are rejected. `doublefactorial(n)` (alias `dfactorial`)
multiplies every other non-negative integer down from `n`, with `0!! = 1`; it
also rejects results beyond the host integer range. `multifactorial(n, k)`
multiplies `n` by successive values reduced by positive integer step `k` until
the next term would be non-positive; results beyond the host integer range
are rejected. It generalizes `factorial` (`k = 1`) and double factorial
(`k = 2`). `risingfactorial(x, n)`
(alias `pochhammer`) returns `x(x + 1)…(x + n - 1)` for integer order and uses
`Γ(x + n)/Γ(x)` for real order `n ≥ 0`, including non-integer orders.
`fallingfactorial(x, n)` returns `x(x - 1)…(x - n + 1)` for integer orders
and uses `Γ(x + 1)/Γ(x - n + 1)` for real orders `n ≥ 0`. Both factorial
functions support finite real `x` and order through 10000; order zero returns
1. Gamma-ratio poles are rejected, while a non-negative integer start returns
zero when a real falling order extends beyond its zero factor.
`genbinomial(x, k)` (alias `generalizedbinomial`) evaluates the generalized
binomial coefficient `x(x - 1)…(x - k + 1)/k!` for finite real `x` and
integer `k` from 0 through 10000, reporting overflow if the result is not
finite. It extends the usual binomial coefficient to negative and non-integer
upper arguments without changing the integer-only `binomial(n, k)` API.
`logfactorial(n)` (alias `lfactorial`) returns the natural logarithm of `n!`
without requiring the factorial itself to fit in an integer.
`logbinomial(n, k)` (alias `logchoose`) and `logpermutation(n, k)` (alias
`lognpr`) return natural logarithms of the corresponding exact integer
combinatorial counts without constructing those potentially oversized counts.
They require non-negative integers with `k ≤ n`.
`harmonicnumber(n[, r])` computes the generalized harmonic number
`sum(k^-r, k=1..n)` for integer `n`; for non-integer real `n ≥ 0`, it uses the
continuous extension through digamma (`r = 1`) or Hurwitz zeta (`r ≠ 1`). The
ordinary harmonic number is used when `r` is omitted. Integer indices use
compensated summation; the index is limited to 1,000,000 and the order must be
finite and positive.
They preserve exact integer results while they fit and otherwise use floating
point, rejecting non-finite results.
Special-function results are finite and domain-checked; expressions that
approach poles or overflow return structured evaluation errors.
`polygamma(m, x)` accepts integer orders from 0 through 32 and evaluates the
real non-pole branch with convergent asymptotics. The named aliases
`tetragamma(x)`, `pentagamma(x)`, and `hexagamma(x)` evaluate orders 3, 4, and
5, respectively.
`bernoulli(n)` returns Bernoulli numbers through order 256, using the
convention `B₁ = -1/2`; odd orders above one return zero, and results outside
the finite floating-point range are rejected.
`eulernumber(n)` returns the signed secant-number convention for Euler
numbers (`E₀ = 1`, `E₂ = -1`, `E₄ = 5`); odd indices return zero. It uses an
exact binomial recurrence for orders 0 through 64 and reports integer overflow.
`bernoullipoly(n, x)` evaluates the Bernoulli polynomial through order 32 for
finite real `x`.
`legendrepoly(n, x)` (alias `legendrep`) evaluates the Legendre polynomial
using its three-term recurrence for finite real `x` and integer degrees from
0 through 10,000; non-finite results are rejected.
`associatedlegendre(n, m, x)` (aliases `assoclegendre` and `legendreassoc`)
evaluates the associated Legendre polynomial for integer `0 ≤ m ≤ n ≤ 10000`
and `x ∈ [-1, 1]`, using the Condon–Shortley phase convention. Order zero
reduces to `legendrepoly(n, x)`.
Its first derivative is available as `associatedlegendreprime(n, m, x)` (aliases
`assoclegendreprime` and `legendreassocprime`). It uses the same degree, order,
and argument bounds; for order one it is singular at `x = ±1`.
Polynomial derivatives are available as `legendrepolyprime(n, x)` (alias
`legendrepprime`), `chebyshevtprime(n, x)`, `chebyshevuprime(n, x)`,
`hermiteprime(n, x)`, `hermiteprobabilistprime(n, x)`,
`laguerreprime(n, x)`, `laguerreassocprime(n, α, x)`,
`jacobipolyprime(n, α, β, x)`, and `gegenbauerprime(n, λ, x)` (alias
`ultrasphericalprime`). Each returns the derivative with respect to `x` on
the same parameter domain and degree limit as its corresponding polynomial.
The Legendre second derivative is available as `legendrepolysecond(n, x)`
(alias `legendrepsecond`), evaluated by differentiating the stable three-term
recurrence, including at `x = ±1`.
Second derivatives for the Chebyshev polynomials of the first and second kinds
are `chebyshevtsecond(n, x)` and `chebyshevusecond(n, x)`; both use differentiated
three-term recurrences and are well-defined at the endpoints.
Hermite second derivatives are available as `hermitesecond(n, x)` and
`hermiteprobabilistsecond(n, x)`. Laguerre second derivatives are
`laguerresecond(n, x)` and `laguerreassocsecond(n, α, x)`; these use the exact
degree-reduction and parameter-shift identities for their respective families.
Jacobi second derivatives are `jacobipolysecond(n, α, β, x)`, and the third
derivative `jacobipolythird(n, α, β, x)` uses the corresponding three-step
degree reduction and parameter shift. For any integer derivative order `k`,
`jacobipolyderivative(n, α, β, x, k)` applies the same identity repeatedly;
orders above the polynomial degree return zero. Gegenbauer second derivatives are
`gegenbauersecond(n, λ, x)` (alias `ultrasphericalsecond`), and third
derivatives are `gegenbauerthird(n, λ, x)` (alias `ultrasphericalthird`); each
uses its exact parameter-shift identity. Arbitrary orders are available as
`gegenbauerderivative(n, λ, x, k)` and `ultrasphericalderivative`; both use
the rising-factor coefficient and shifted Gegenbauer polynomial.
`chebyshevt(n, x)` and `chebyshevu(n, x)` evaluate Chebyshev polynomials of
the first and second kinds; `chebyshevv(n, x)` and `chebyshevw(n, x)` (aliases
`chebyshevthird` and `chebyshebfourth`) evaluate the third and fourth kinds.
Their first derivatives are available as `chebyshevtprime`, `chebyshevuprime`,
`chebyshevvprime` (alias `chebyshevthirdprime`), and `chebyshevwprime` (alias
`chebyshebfourthprime`); recurrence evaluation includes the endpoints.
Their second derivatives are `chebyshevvsecond` (alias `chebyshevthirdsecond`)
and `chebyshevwsecond` (alias `chebyshebfourthsecond`), also evaluated by
recurrence at endpoints.
All four use finite real `x` and integer degrees from 0 through 10,000; checked
three-term recurrences and exact endpoint values are used.
`hermite(n, x)` (alias `hermitephysicist`) uses the physicists' Hermite
convention; `hermiteprobabilist(n, x)` uses the probabilists' convention.
Both require finite `x` and integer degrees from 0 through 10,000.
`laguerre(n, x)` evaluates the ordinary Laguerre polynomial, while
`laguerreassoc(n, α, x)` evaluates the generalized polynomial for finite
`α > -1`; both use the same degree range and checked recurrence.
`jacobipoly(n, α, β, x)` evaluates the Jacobi polynomial for finite `α, β > -1`
and finite real `x`, with the same degree limit and a checked three-term
recurrence.
`bernstein(n, k, x)` (alias `bernsteinpoly`) evaluates the Bernstein basis
`C(n,k) x^k (1-x)^(n-k)` for integer `0 <= k <= n <= 10000` and `x` in
`[0, 1]`. Endpoint values are exact and the interior is evaluated in the
logarithmic domain to avoid large intermediate binomial coefficients.
`gegenbauer(n, λ, x)` (alias `ultraspherical`) evaluates Gegenbauer polynomials
for integer degree `0..10000`, finite `λ > -1/2`, and finite real `x`. It uses
a checked recurrence; `λ = 1/2` reduces to Legendre polynomials, `λ = 1` to
Chebyshev polynomials of the second kind, and the `λ = 0` limiting convention
is one at degree zero and zero at positive degrees.
`bessely(n, x)` and `besselk(n, x)` accept finite integer orders and positive
real arguments; singular zero arguments are rejected explicitly.
`logbesselk(n, x)` returns `log(K_n(x))` directly and preserves useful values
for large positive `x` even when `besselk(n, x)` underflows to zero.
`kve(n, x)` (alias `scaledbesselk`) returns `exp(x) K_n(x)` for integer order
and positive finite `x`, avoiding underflow in the unscaled function.
`besseli(ν, x)` and `logbesseli(ν, x)` accept non-negative real orders `ν`
for positive `x`; the ordinary function retains signed-integer-order behavior
for negative integer orders and real arguments. The log form retains values
when `besseli(ν, x)` would underflow near zero or overflow for large arguments.
`ive(ν, x)` (alias `scaledbesseli`) evaluates `exp(-|x|) Iν(x)` without
forming the overflowing exponential scale; real orders must be non-negative,
and negative real arguments are supported only for integer orders.
`besseliprime(ν, x)` also supports non-negative real orders, and
`logbesseliprime(ν, x)` evaluates the derivative's logarithm without forming an
overflowing derivative.
`logbesselkprime(n, x)` returns the logarithm of the absolute derivative,
preserving values when `besselkprime` underflows.
`besseljprime`, `besseliprime`, `besselyprime`, and `besselkprime` evaluate
derivatives with respect to `x` for integer order `n`; the Y and K derivatives
require positive real `x`, matching their corresponding base functions.
`besseljsecond`, `besselisecond`, `besselysecond`, and `besselksecond` return
the second derivatives with respect to `x`, using neighboring-order recurrence
relations; the Y and K forms require positive real `x`.
`logbesselkprime(n, x)` returns the logarithm of the K-derivative's absolute
magnitude, preserving information when `besselkprime` underflows.
`sinc(x)` and `sincpi(x)` evaluate `sin(x)/x` and `sin(πx)/(πx)`,
respectively; both use the continuous value `1` at the origin. `sincpi`
reduces the periodic numerator before evaluation to avoid overflow for large
finite inputs. `sinhc(x)` and `sinhcpi(x) = sinh(πx)/(πx)` use their
continuous value of `1` at the origin; `sinhcpi` uses a near-zero series and
reports overflow when its result is not representable.
`sinhcprime`/`sinhcsecond` and `sinhcpiprime`/`sinhcpisecond` provide their
first and second derivatives, with near-zero series and scaled large-argument
evaluation; derivatives outside the finite floating-point range raise overflow.
`sincprime`/`sincsecond` and `sincpiprime`/`sincpisecond` provide first and
second derivatives of `sinc` and `sincpi`; near-zero series preserve their
limits and avoid cancellation.
`cosc(x)` and `coscpi(x)` evaluate `(1 - cos(x))/x²` and
`(1 - cos(πx))/(πx)²` through squared sinc identities, avoiding cancellation;
both use their continuous value `1/2` at zero.
`coscprime`/`coscsecond` and `coscpiprime`/`coscpisecond` provide first and
second derivatives, using the same stable sinc identities.
`gd(x)` evaluates the Gudermannian `2 atan(tanh(x/2))` for real `x`, and
`invgd(y)` evaluates its inverse `asinh(tan(y))` for `-π/2 < y < π/2`.
`gdprime`/`gdsecond` and `invgdprime`/`invgdsecond` provide their first and
second derivatives; the inverse derivatives use the same open domain as `invgd`.
`exprel(x)` evaluates `(exp(x) - 1) / x` with continuous value `1` at zero,
using stable near-zero and large-positive calculations. `exprelinv(y)` returns
its real inverse for finite `y > 0`, using a log-space monotone solve and the
large-negative asymptote when the exponential term is below floating-point
significance.
`log1pmx(x)` evaluates `log(1 + x) - x` for finite `x > -1`, using a series
near zero to preserve precision.
`log1mexp(x)` evaluates `log(1 - exp(x))` for finite `x < 0`, with stable
branches around `-log(2)` and near zero.
`log1pmxprime` and `log1mexpprime` evaluate their derivatives on the same
domains, using cancellation-safe quotient forms.
`logaddexp(a, b)` computes `log(exp(a) + exp(b))`, `logsumexp(x1, ..., xn)`
extends it to 1–16 terms, and `logsubexp(a, b)` computes
`log(exp(a) - exp(b))` when `a > b`. Max-shifted summation and `expm1` avoid
intermediate overflow and cancellation; results outside the finite floating-
point range raise an overflow error.
`sigmoid(x)` (alias `logisticsigmoid`) evaluates `1 / (1 + exp(-x))` with
sign-dependent branches that avoid overflow for large finite inputs;
`sigmoidinv(p)` (also `logisticsigmoidinv`) returns its inverse log-odds for
`0 < p < 1`.
`softplus(x)` evaluates `log(1 + exp(x))` with overflow-safe branches;
`softplusinv(y)` evaluates its real inverse `log(exp(y) - 1)` for `y > 0`,
using a large-argument branch that avoids exponential overflow.
`logit(p)` evaluates `log(p / (1 - p))` for probabilities strictly between
zero and one. `softplusprime` equals `sigmoid`; `sigmoidprime` and
`logitprime` evaluate first derivatives, while `softplussecond`,
`sigmoidsecond` (also `logisticsigmoidsecond`), and `logitsecond` evaluate
second derivatives. The logit derivatives report overflow when their results
are outside the finite floating-point range.
`gammainc(a, x)` and `gammaincc(a, x)` are normalized lower and upper
incomplete gamma functions, so their values sum to `1` for valid inputs. Their
shape is limited to `(0, 10000]` to keep the iterative floating-point
algorithms within their tested numerical range.
`loggammainc(a, x)` and `loggammaincc(a, x)` return the natural logarithms of
the normalized tails directly, preserving probabilities that would underflow
if computed first. They use the same shape and input domain; the lower-tail
logarithm is singular at `x = 0`, while the upper-tail logarithm is `0` there.
`gammaincinvlog(a, logP)` and `gammainccinvlog(a, logQ)` invert those log tails
without requiring `P` or `Q` to be representable as ordinary floating-point
probabilities. The lower-tail log probability must be negative; the upper-tail
log probability may be zero, which returns `x = 0`.
`gammaincinv(a, p)` and `gammainccinv(a, q)` invert those functions on finite
quantiles for shapes in `(0, 10000]`, with `p` in `[0, 1)` and `q` in `(0, 1]`
because the opposite tail endpoints are infinite.
`gammaincinvprime(a, p)` and `gammainccinvprime(a, q)` return the derivatives
of those inverse functions with respect to their probability argument, for
probabilities strictly between zero and one. The upper-tail derivative is
negative; if the reciprocal density is not representable, an overflow is
reported.
Unregularized lower and upper incomplete gamma functions are available as
`lowerincompletegamma(a, x)` / `lowergamma(a, x)` and
`upperincompletegamma(a, x)` / `uppergamma(a, x)`. Their logarithms are
`loglowerincompletegamma` / `loglowergamma` and
`logupperincompletegamma` / `loguppergamma`; these preserve very small tails
that underflow in value form. They use the same shape and non-negative `x`
domain as the regularized functions, and report overflow for unrepresentable
values.
`erfinv(x)` and `erfcinv(x)` provide the real inverse error-function branches
for `-1 < x < 1` and `0 < x < 2`, respectively.
`erfinvprime`/`erfinvsecond` and `erfcinvprime`/`erfcinvsecond` evaluate their
first and second derivatives in terms of the inverse value; overflow is
reported when the derivative cannot be represented as a finite float.
`erfprime`/`erfsecond`/`erfthird`,
`erfcprime`/`erfcsecond`/`erfcthird`, and
`erfiprime`/`erfisecond`/`erfithird` evaluate the first through third
derivatives of `erf`, `erfc`, and `erfi`; exponential overflow is reported,
while underflowing real tails return zero.
`erfcx(x)` evaluates the scaled complementary error function
`exp(x²) · erfc(x)` without forming the potentially overflowing exponential;
its positive tail remains useful even for very large finite `x`.
`dawson(x)` evaluates Dawson's integral `exp(-x²) ∫₀ˣ exp(t²) dt` for finite
real `x`, using a near-zero series, the scaled `erfi` relation, and a large-
argument asymptotic expansion.
`dawsonprime(x)` evaluates its derivative, `1 - 2x·dawson(x)`, using a
separate asymptotic expansion in the far tail to avoid cancellation.
`dawsonsecond(x)` evaluates the second derivative `-2·dawson(x) -
2x·dawsonprime(x)`, with its own far-tail expansion to retain accuracy after
the leading terms cancel. `dawsonthird(x)` evaluates the third derivative
`-4·dawsonprime(x) - 2x·dawsonsecond(x)`, again using a dedicated far-tail
expansion to avoid cancellation.
`logerfc(x)` returns `log(erfc(x))` without underflowing in the positive tail,
and `erfcinvlog(y)` inverts that tail from a finite log-probability `y ≤ 0`.
`logerfcprime(x)` returns its derivative using the scaled complementary error
function, including the large-positive tail; `erfcinvlogprime(y)` returns the
inverse derivative `-√π·erfcx(erfcinvlog(y))/2`.
`betainc(a, b, x)` and `betaincc(a, b, x)` are normalized lower and upper
incomplete beta functions on positive shapes with `a + b ≤ 10000` and
`0 ≤ x ≤ 1`; their values sum to `1` for valid inputs.
`logbetainc(a, b, x)` and `logbetaincc(a, b, x)` return the logarithms of
those normalized tails directly, retaining very small probabilities that
underflow in value form. The lower-tail logarithm is singular at `x = 0`, and
the upper-tail logarithm is singular at `x = 1`; each equals zero at the
opposite endpoint.
`betaincinvlog(a, b, logP)` and `betainccinvlog(a, b, logQ)` invert these log
tails directly; both accept any finite log probability no greater than zero
and return the corresponding endpoint when it is zero.
`lowerincompletebeta(a, b, x)` and `upperincompletebeta(a, b, x)` return the
unnormalized lower and upper integrals; `lowerbeta` and `upperbeta` are aliases.
The `loglowerincompletebeta`/`logupperincompletebeta` functions (also available
as `loglowerbeta`/`logupperbeta`) return their natural logarithms without first
forming the potentially underflowing integral. The log lower function is
singular at `x = 0`, and the log upper function is singular at `x = 1`.
`betaincinv(a, b, p)` and `betainccinv(a, b, q)` invert those normalized
functions for positive shapes with `a + b ≤ 10000` and probabilities in
`[0, 1]`, returning the corresponding `x` in `[0, 1]`.
`betaincinvprime(a, b, p)` and `betainccinvprime(a, b, q)` return the
probability-argument derivatives of the lower- and upper-tail inverse functions
for probabilities strictly between zero and one. The upper-tail derivative is
negative; unrepresentable values raise an overflow error.
`normalpdf(x[, mean[, standardDeviation]])` and
`normalcdf(x[, mean[, standardDeviation]])` evaluate the normal density and
cumulative distribution; omitted parameters select the standard normal
(`mean = 0`, `standardDeviation = 1`). `normalccdf(x[, mean[, standardDeviation]])`
returns the upper-tail probability directly using the complementary error
function, preserving precision where `1 - normalcdf(x)` would round to zero.
`normallogcdf(x[, mean[, standardDeviation]])` and
`normallogccdf(x[, mean[, standardDeviation]])` return the natural logarithm of
the corresponding lower or upper tail, retaining finite values after ordinary
CDF probabilities underflow. Aliases are `normlogcdf` and `normlogccdf`.
The `normpdf`, `normcdf`, and `normccdf` aliases are also available.
`normalhazard(x[, mean[, standardDeviation]])` (alias `normhazard`) returns
the density divided by the upper-tail probability. It uses the scaled
complementary error function in the far right tail to avoid underflow.
`normalcumhazard(...)` (alias `normcumhazard`) returns the negative log of the
upper-tail probability and remains useful when the survival probability itself
underflows.
`normalinv(p[, mean[, standardDeviation]])` (alias
`norminv`) returns the quantile for probabilities strictly between zero and
one, with the same optional location and positive scale parameters.
`normalinvlogcdf(logP[, mean[, standardDeviation]])` and
`normalinvlogccdf(logQ[, mean[, standardDeviation]])` return normal quantiles
directly from finite negative log-tail probabilities, preserving extreme-tail
inputs after the ordinary probability underflows. Their aliases are
`norminvlogcdf` and `norminvlogccdf`.
`normallogpdf(x[, mean[, standardDeviation]])` (alias `normlogpdf`) returns
the natural logarithm of the normal density and remains useful for tail values
whose ordinary density underflows.
`exponentialpdf(x, rate)`, `exponentialcdf(x, rate)`, and
`exponentialccdf(x, rate)` provide the exponential density, cumulative
probability, and survival probability; aliases are `exppdf`, `expcdf`, and
`expccdf`. The rate must be positive and finite. Negative `x` has zero density
and CDF, and survival probability one. `exponentialinv(p, rate)` (alias
`expinv`) returns the quantile for probabilities in `[0, 1)`; probability 1
has no finite quantile.
`exponentialccinv(q, rate)` (alias `expccinv`) directly inverts the survival
probability for `q` in `(0, 1]`, avoiding the precision loss of forming `1-q`.
The quantile derivatives are `exponentialinvprime(p, rate)` (alias `expinvprime`)
and `exponentialccinvprime(q, rate)` (alias `expccinvprime`); the latter is
negative, and both require probabilities strictly between zero and one.
`exponentialmean(rate)` and `exponentialvariance(rate)` (aliases `expmean`,
`expvariance`, and `expvar`) return `1/rate` and `1/rate²`.
`exponentialmedian(rate)` (alias `expmedian`) returns `ln(2)/rate`,
`exponentialmode(rate)` (alias `expmode`) returns zero,
`exponentialstddev(rate)` (alias `expstddev`) returns `1/rate`, and
`exponentialentropy(rate)` (alias `expentropy`) returns differential entropy
`1 - ln(rate)`.
`exponentialskewness`, `exponentialexcesskurtosis`, and
`exponentialkurtosis` (also available with the `exp` prefix) return 2, 6, and
9 respectively; all three take a positive finite rate argument.
`exponentialhazard(x, rate)` returns the constant hazard `rate`, and
`exponentialcumhazard(x, rate)` returns `rate × x`; both require `x ≥ 0` and a
positive finite rate.
`exponentialmgf(t, rate)` (alias `expmgf`) and `exponentiallogmgf(...)` (alias
`explogmgf`) return `rate / (rate - t)` and its natural logarithm. They accept
finite `t < rate`, including negative tilts; the ordinary MGF reports overflow
when its value exceeds the finite floating-point range.
`exponentialmoment(k, rate)` (aliases `exponentialrawmoment`) returns the raw
moment `k! / rate^k` for integer orders `0..64`, evaluated in the log domain.
`exponentiallogpdf(x, rate)` returns the log density directly for `x ≥ 0`,
including tail values whose ordinary PDF underflows; negative inputs are
rejected because their density is zero.
`uniformpdf(x, minimum, maximum)`, `uniformcdf(x, minimum, maximum)`, and
`uniformccdf(x, minimum, maximum)` evaluate the continuous uniform density,
cumulative probability, and strict survival probability on the finite interval
`[minimum, maximum]`, with `minimum < maximum`. `uniforminv(p, minimum,
maximum)` returns the quantile for `p` in `[0, 1]`.
`uniformccinv(q, minimum, maximum)` directly inverts survival probabilities
in `[0, 1]`, returning `maximum` at zero and `minimum` at one.
`uniformlogpdf(x, minimum, maximum)` returns the log density inside the closed
support; points outside it are rejected because their log density is not
finite.
`uniformmean(minimum, maximum)` and `uniformvariance(minimum, maximum)` (alias
`uniformvar`) return the interval midpoint and variance. `uniformskewness`,
`uniformexcesskurtosis`, and `uniformkurtosis` return 0, -6/5, and 9/5,
respectively; shape functions also take the two bounds.
`uniformmoment(n, minimum, maximum)` (`uniformrawmoment`) returns raw moments
for integer orders 0 through 64. `uniformmedian(minimum, maximum)` and
`uniformmode(minimum, maximum)` both return the midpoint; every point in the
closed support has the same density and is a mode.
`triangularpdf(x, minimum, mode, maximum)`, `triangularcdf(...)`, and
`triangularccdf(...)` define the triangular density and tails for finite
`minimum < maximum` and `mode` within the closed interval. `triangularinv(p,
minimum, mode, maximum)` returns quantiles for probabilities in `[0, 1]`;
endpoint modes are supported.
`triangularlogpdf(x, minimum, mode, maximum)` returns the log density directly;
zero-density points return negative infinity, while endpoint modes remain
finite.
`triangularmean(minimum, mode, maximum)`, `triangularvariance(...)` (alias
`triangularvar`), `triangularmedian(...)`, and `triangularmode(...)` provide the
standard summaries. `triangularmoment(n, minimum, mode, maximum)` (alias
`triangularrawmoment`) returns raw moments through order 32, including the
one-sided triangular limits when the mode equals either endpoint.
`generalizednormalpdf(x, location, scale, shape)`, `generalizednormalcdf(...)`,
`generalizednormalccdf(...)`, and `generalizednormalinv(p, location, scale,
shape)` implement the generalized normal (exponential-power) distribution.
Scale is positive and finite; shape is in `[0.0001, 10000]`. Shape one reduces
to the Laplace distribution, and shape two is normal with standard deviation
`scale / sqrt(2)`. The inverse requires probabilities strictly between zero
and one. `generalizednormallogpdf(x, location, scale, shape)` (alias
`generalizednormlogpdf`) returns the log density directly, preserving extreme
tail values after the ordinary PDF underflows.
`generalizednormalmean`, `generalizednormalmedian`, and `generalizednormalmode`
return the location. `generalizednormalvariance` (`generalizednormalvar`),
`generalizednormalstddev`, `generalizednormalentropy`,
`generalizednormalskewness`, `generalizednormalexcesskurtosis`, and
`generalizednormalkurtosis` expose its remaining standard summaries.
`generalizednormalmoment(n, location, scale, shape)`
(`generalizednormalrawmoment`) returns raw moments for orders 0 through 32.
`weibullpdf(x, shape, scale)`, `weibullcdf(x, shape, scale)`, and
`weibullccdf(x, shape, scale)` provide the Weibull density, cumulative
probability, and survival probability for positive finite shape and scale.
Negative values have zero density and CDF and survival probability one. At
zero, the density is singular for shape below one, equals `1 / scale` for
shape one, and is zero for shape above one. `weibullinv(p, shape, scale)`
returns the quantile for `p` in `[0, 1)`; probability 1 has no finite quantile.
`weibullccinv(q, shape, scale)` directly inverts survival probabilities for
`q` in `(0, 1]`, including the finite lower-support endpoint at `q = 1`.
The derivatives of these inverse functions are available as
`weibullinvprime(p, shape, scale)` and `weibullccinvprime(q, shape, scale)`;
both require probabilities strictly between zero and one, and the survival
quantile derivative is negative.
`weibulllogpdf(x, shape, scale)` returns the log density directly, preserving
finite tail values even when the ordinary density underflows. Inputs outside
the support and endpoints without finite log densities are rejected.
`weibulllogcdf(x, shape, scale)` and `weibulllogccdf(...)` return logarithms of
the lower- and upper-tail probabilities. The log-CDF preserves tiny lower-tail
values, and the log-survival function remains finite after the ordinary
survival probability underflows; endpoints without a finite log probability
are rejected.
`weibullmoment(n, shape, scale)` (alias `weibullrawmoment`) returns the raw
moment `scale^n Γ(1 + n/shape)` for integer orders `n` from 0 through 64; order
zero returns 1. It reports overflow rather than returning an infinite value.
`weibullmode(shape, scale)` returns zero for shapes at or below one, and
`scale × ((shape - 1) / shape)^(1 / shape)` for shapes above one.
`weibullmean(shape, scale)`, `weibullmedian(...)`, `weibullvariance(...)`
(alias `weibullvar`), and `weibullstddev(...)` return the distribution's mean,
median, variance, and standard deviation. `weibullskewness(shape)` and
`weibullexcesskurtosis(shape)` (with `weibullkurtosis(shape)` for ordinary
kurtosis) report standardized shape statistics. `weibullentropy(shape, scale)` returns
its differential entropy in natural logarithm units.
`weibullhazard(x, shape, scale)` returns
`shape / scale × (x / scale)^(shape - 1)`, and
`weibullcumhazard(x, shape, scale)` returns `(x / scale)^shape` for `x ≥ 0`.
The hazard at zero is reported as undefined for shapes below one, equals
`1 / scale` at shape one, and is zero for shapes above one.
`lognormalpdf(x, mean, standardDeviation)`, `lognormalcdf(x, mean,
standardDeviation)`, and `lognormalccdf(x, mean, standardDeviation)` provide the
log-normal density, cumulative probability, and survival probability. The
parameters are the mean and positive finite standard deviation of `ln(X)`;
non-positive `x` has zero density and CDF and survival probability one.
`lognormallogcdf` (`lognormlogcdf`) and `lognormallogccdf` (`lognormlogccdf`)
return log-tail probabilities without losing small values to ordinary CDF
underflow. The log-CDF is singular for non-positive `x`; the log-survival is
zero there.
`lognormalinv(p, mean, standardDeviation)` returns the quantile for
probabilities strictly between zero and one.
`lognormalccinv(q, mean, standardDeviation)` directly inverts survival
probabilities for `q` in `(0, 1]`, retaining precision for extreme upper tails.
The inverse-quantile derivatives are available as
`lognormalinvprime(p, mean, standardDeviation)` and
`lognormalccinvprime(q, mean, standardDeviation)` for probabilities strictly
between zero and one; the survival-quantile derivative is negative.
`lognormalhazard(x, mean, standardDeviation)` returns the density divided by
the survival probability, using a scaled complementary error function in the
upper tail; `lognormalcumhazard(...)` returns the negative log-survival.
Both are zero for non-positive `x`.
`lognormalmedian(mean, standardDeviation)` returns `exp(mean)`;
`lognormalmode(...)` returns `exp(mean - standardDeviation²)`; and
`lognormalentropy(...)` returns differential entropy
`mean + ln(standardDeviation) + ½ ln(2πe)`.
`lognormalmean(mean, standardDeviation)` and `lognormalvariance(...)` (alias
`lognormalvar`) return the ordinary-scale mean and variance computed from the
log-space parameters. `lognormalskewness`, `lognormalexcesskurtosis`, and
`lognormalkurtosis` take the same two parameters and return standardized shape
statistics.
`lognormallogpdf(x, mean, standardDeviation)` (aliases `lognormaldistlogpdf`
and `lognormlogpdf`) returns the log density directly, including extreme tails
where the ordinary density underflows; non-positive values have no finite
log-density and are rejected.
`logisticpdf(x, location, scale)`, `logisticcdf(x, location, scale)`, and
`logisticccdf(x, location, scale)` provide the logistic density, cumulative
probability, and survival probability for a finite location and positive finite
scale. `logisticinv(p, location, scale)` returns the quantile for probabilities
strictly between zero and one.
`logisticlogcdf(x, location, scale)` and `logisticlogccdf(...)` compute the
lower- and upper-tail logarithms directly, retaining finite values when the
ordinary probabilities round to zero.
`logisticccinv(q, location, scale)` directly inverts survival probabilities
strictly between zero and one without subtracting them from one.
The probability derivatives of the two inverse functions are provided by
`logisticinvprime(p, location, scale)` and
`logisticccinvprime(q, location, scale)`; both require probabilities strictly
between zero and one, and the survival-quantile derivative is negative.
`logisticlogpdf(x, location, scale)` returns the log density directly and
remains useful for extreme finite tails where the PDF underflows.
`logisticmean(location, scale)`, `logisticvariance(...)`,
`logisticstddev(...)`, `logisticskewness(...)`,
`logisticexcesskurtosis(...)`, and `logistickurtosis(...)` return the location,
`π² scale² / 3`, `π scale / √3`, zero skewness, excess kurtosis `6/5`, and
Pearson kurtosis `21/5`. `logisticvar` aliases `logisticvariance`.
`logisticmedian(location, scale)` and `logisticmode(...)` both return the
location; `logisticentropy(location, scale)` returns differential entropy
`2 + ln(scale)`. `logistichazard(x, location, scale)` returns the density divided
by survival probability, and `logisticcumhazard(...)` returns
`ln(1 + exp((x - location) / scale))`, evaluated with a stable softplus form.
`logisticmgf(t, location, scale)` and `logisticlogmgf(...)` evaluate the MGF
and its natural logarithm, using `exp(location × t) × π scale t /
sin(π scale t)`. They require finite `|scale × t| < 1`; the logarithmic form
uses a stable sinc-based expression near zero and a complementary formula near
the convergence boundary.
`logisticmoment(n, location, scale)` (`logisticrawmoment`) returns raw moments
through order 32, using the exact even central moments and symmetry for odd
central orders.
`loglogisticpdf(x, shape, scale)`, `loglogisticcdf(x, shape, scale)`, and
`loglogisticccdf(x, shape, scale)` provide the log-logistic (Fisk) density,
cumulative probability, and survival probability for positive finite shape and
scale; `fiskpdf`, `fiskcdf`, and `fiskccdf` are aliases. The support is
`x >= 0`; at zero the density is singular for shape below one, equals
`1 / scale` for shape one, and is zero for shape above one.
`loglogisticinv(p, shape, scale)` (alias `fiskinv`) returns the quantile for
probabilities in `[0, 1)`.
`loglogisticmoment(n, shape, scale)` (alias `fiskmoment`) returns the raw
moment `scale^n × (πn/shape) / sin(πn/shape)` for integer orders `n` from 0
through 64. The zero-order moment is 1; higher moments exist only when
`n < shape`. `loglogisticrawmoment` and `fiskrawmoment` are additional aliases.
`loglogisticmean(shape, scale)` (alias `fiskmean`) requires `shape > 1`;
`loglogisticvariance(...)` (aliases `loglogisticvar`, `fiskvariance`, and
`fiskvar`) and `loglogisticstddev(...)` (alias `fiskstddev`) require
`shape > 2`. `loglogisticmedian(...)` and `loglogisticmode(...)` (aliases
`fiskmedian` and `fiskmode`) return `scale` and the mode, respectively; the
mode is zero when shape is at most one. `loglogisticentropy(...)` (alias
`fiskentropy`) returns differential entropy `2 + ln(scale / shape)`.
`loglogisticlogcdf(x, shape, scale)` and
`loglogisticlogccdf(x, shape, scale)` (aliases `fisklogcdf` and `fisklogccdf`)
compute log-tail probabilities directly, retaining useful values in extreme
tails where exponentiating the log-odds would underflow.
`loglogistichazard(x, shape, scale)` (alias `fiskhazard`) and
`loglogisticcumhazard(...)` (alias `fiskcumhazard`) return the hazard rate and
cumulative hazard on the non-negative support. The hazard is singular at zero
when shape is below one.
`loglogisticlogpdf(x, shape, scale)` (alias `fisklogpdf`) returns the log
density directly, preserving finite tail values after the PDF underflows.
`laplacepdf(x, location, scale)`, `laplacecdf(x, location, scale)`, and
`laplaceccdf(x, location, scale)` provide the double-exponential density,
cumulative probability, and survival probability for finite location and
positive finite scale. `laplacelogpdf(x, location, scale)` (alias `laplogpdf`)
returns the log-density, preserving information in tails where the PDF
underflows. `laplacelogcdf(...)` and `laplacelogccdf(...)` evaluate both log
tails directly, preserving their linear far-tail values. `laplaceinv(p, location, scale)` returns the quantile for
probabilities strictly between zero and one.
`laplaceccinv(q, location, scale)` directly inverts survival probabilities
strictly between zero and one, including either side of the distribution's
median.
`laplacemean`, `laplacemedian`, and `laplacemode` return the location;
`laplacevariance` (`laplacevar`) and `laplacestddev` return `2 scale²` and
`sqrt(2) scale`. `laplaceentropy` returns differential entropy
`1 + ln(2 scale)`. `laplaceskewness`, `laplaceexcesskurtosis`, and
`laplacekurtosis` return 0, 3, and 6. `laplacemoment(n, location, scale)`
(`laplacerawmoment`) returns raw moments through order 64.
`laplacelogmgf(t, location, scale)` and `laplacemgf(...)` return the log MGF
and MGF on the open domain `|t| < 1 / scale`.
`asymmetriclaplacepdf(x, location, leftScale, rightScale)`, `asymmetriclaplacecdf(...)`,
`asymmetriclaplaceccdf(...)`, and `asymmetriclaplaceinv(p, location, leftScale,
rightScale)` define the asymmetric Laplace distribution. Both scales must be
positive and finite; the CDF at the location is `leftScale / (leftScale +
rightScale)`. Equal left and right scales reduce to the symmetric Laplace
distribution. The inverse accepts probabilities strictly between zero and one.
`asymmetriclaplacelogpdf(...)` returns the log density directly for stable
evaluation in extreme tails. `asymmetriclaplacelogcdf(...)` and
`asymmetriclaplacelogccdf(...)` provide the log cumulative and log survival
probabilities, retaining useful values when the corresponding probabilities
underflow in the tails.
`asymmetriclaplacemean(location, leftScale, rightScale)` returns
`location + rightScale - leftScale`; the median is the 0.5 quantile, and the
mode is the location. `asymmetriclaplacevariance(...)` (`asymmetriclaplacevar`)
and `asymmetriclaplacestddev(...)` return the variance and standard deviation.
The entropy, skewness, excess kurtosis, and kurtosis are available as
`asymmetriclaplaceentropy`, `asymmetriclaplaceskewness`,
`asymmetriclaplaceexcesskurtosis`, and `asymmetriclaplacekurtosis`.
`asymmetriclaplacemoment(n, location, leftScale, rightScale)`
(`asymmetriclaplacerawmoment`) returns raw moments through order 32.
`asymmetriclaplacelogmgf(t, location, leftScale, rightScale)` and
`asymmetriclaplacemgf(...)` evaluate the log MGF and MGF for
`-1 / leftScale < t < 1 / rightScale`.
`fdistributionpdf(x, numeratorDegrees, denominatorDegrees)`,
`fdistributionlogpdf(x, numeratorDegrees, denominatorDegrees)` (alias `flogpdf`),
`fdistributioncdf(x, numeratorDegrees, denominatorDegrees)`, and
`fdistributionccdf(x, numeratorDegrees, denominatorDegrees)` evaluate the
F-distribution density, cumulative probability, and survival probability;
short aliases are `fpdf`, `fcdf`, and `fccdf`. Both degrees-of-freedom
parameters must be positive finite values and their sum may not exceed 20000.
`fdistributionhazard(x, numeratorDegrees, denominatorDegrees)` (alias `fhazard`)
returns the hazard rate, while `fdistributioncumhazard(...)` (alias `fcumhazard`)
returns the cumulative hazard `-ln(survival(x))`.
`fdistributionlogcdf(...)` (alias `flogcdf`) and
`fdistributionlogccdf(...)` (alias `flogccdf`) return the log cumulative and
survival probabilities directly to retain extreme-tail values when ordinary
probabilities underflow. The log CDF at non-positive `x` is negative infinity
and is reported as a domain error; log survival at `x ≤ 0` is zero.
`fdistributioninv(p, numeratorDegrees, denominatorDegrees)` (alias `finv`)
returns the quantile for `p` in `[0, 1)`; probability 1 has no finite quantile.
`fdistributionccinv(q, numeratorDegrees, denominatorDegrees)` (alias `fccinv`)
directly inverts the survival probability for `q` in `(0, 1]`.
Quantile derivatives are available as `fdistributioninvprime(p, ...)` and
`fdistributionccinvprime(q, ...)` (aliases `finvprime` and `fccinvprime`);
the survival-quantile derivative is negative.
`fdistributionmean(numeratorDegrees, denominatorDegrees)` (alias `fmean`) and
`fdistributionvariance(...)` (aliases `fvariance` and `fvar`) return the mean
and variance when the denominator degrees are greater than 2 and 4,
respectively. `fdistributionskewness(...)`,
`fdistributionexcesskurtosis(...)`, and `fdistributionkurtosis(...)` return
skewness, excess kurtosis, and Pearson kurtosis when denominator degrees exceed
6 or 8 as required; moments outside these existence conditions raise domain
errors. The standard moment formulas are tabulated by the
[NIST/SEMATECH e-Handbook](https://www.itl.nist.gov/div898/handbook/eda/section3/eda3665.htm)
and [LibreTexts](https://stats.libretexts.org/Bookshelves/Probability_Theory/Probability_Mathematical_Statistics_and_Stochastic_Processes_%28Siegrist%29/05%3A_Special_Distributions/5.11%3A_The_F_Distribution).
`fdistributionmedian(...)` (`fmedian`) evaluates the 0.5 quantile;
`fdistributionmode(...)` (`fmode`) returns zero for numerator degrees at most
2 and the interior mode otherwise. `fdistributionentropy(...)` (`fentropy`)
returns differential entropy in natural-log units.
`betadistributionpdf(x, alpha, beta)`, `betadistributioncdf(x, alpha, beta)`,
and `betadistributionccdf(x, alpha, beta)` evaluate the beta density, CDF, and
survival probability on `[0, 1]`; short aliases are `betapdf`, `betacdf`, and
`betaccdf`. Shapes must be positive with `alpha + beta ≤ 10000`.
`betadistributioninv(p, alpha, beta)` (alias `betainv`) returns the quantile
for probabilities in `[0, 1]`. `betadistributionccinv(q, alpha, beta)` (alias
`betaccinv`) directly inverts survival probabilities in `[0, 1]`. The density rejects infinite singularities at
the endpoints when the corresponding shape is below one.
Inverse-quantile derivatives are `betadistributioninvprime(p, alpha, beta)`
(alias `betainvprime`) and `betadistributionccinvprime(q, alpha, beta)` (alias
`betaccinvprime`); both require probabilities strictly between zero and one,
and the survival-quantile derivative is negative.
`betadistributionmean(alpha, beta)` (alias `betamean`) and
`betadistributionvariance(alpha, beta)` (aliases `betavariance` and `betavar`)
return the mean and variance. `betadistributionskewness(alpha, beta)`,
`betadistributionexcesskurtosis(alpha, beta)`, and
`betadistributionkurtosis(alpha, beta)` return standardized shape statistics;
short `beta`-prefix aliases are also available.
`betadistributionmedian(alpha, beta)` (alias `betamedian`) returns the median
using the inverse regularized beta function. `betadistributionmode(alpha, beta)`
(alias `betamode`) returns `(alpha - 1) / (alpha + beta - 2)` when both shapes
exceed one; otherwise the mode is not a unique interior point and the function
raises a domain error. `betadistributionentropy(alpha, beta)` (alias
`betaentropy`) returns differential entropy in natural logarithm units.
`betalogpdf(x, alpha, beta)` (alias `betadistributionlogpdf`) returns the log
density directly for `0 < x < 1`, preserving tail values when the PDF
underflows. At an endpoint it returns a value only when the log density is
finite; outside the support it is undefined.
`betadistributionlogcdf(x, alpha, beta)` (alias `betalogcdf`) and
`betadistributionlogccdf(...)` (alias `betalogccdf`) return log probabilities
directly, retaining useful values when the corresponding tail probability
underflows. The log CDF at `x = 0` and log survival at `x = 1` are negative
infinity and are reported as domain errors; the opposite endpoints return zero.
`kumaraswamypdf(x, alpha, beta)`, `kumaraswamycdf(...)`,
`kumaraswamyccdf(...)`, and `kumaraswamyinv(p, alpha, beta)` define the
Kumaraswamy distribution on `[0, 1]` for positive finite shapes in `(0, 10000]`.
Its CDF and inverse are closed form; setting either shape to one reduces it to
a beta distribution with the other shape unchanged. Endpoint density
singularities are reported explicitly.
`kumaraswamylogpdf(x, alpha, beta)` returns the log density directly, including
the endpoint infinities where the density is singular or zero.
`kumaraswamymoment(n, alpha, beta)` (alias `kumaraswamyrawmoment`) returns raw
integer moments for orders 0 through 64. `kumaraswamymean(...)`,
`kumaraswamyvariance(...)` (alias `kumaraswamyvar(...)`),
`kumaraswamymedian(...)`, and `kumaraswamymode(...)` provide location and spread
summaries. A U-shaped density (`alpha < 1` and `beta < 1`) has two singular
endpoint modes, so its single-valued mode query is rejected; for a uniform
density, zero is returned as a representative mode.
`betaprimelogpdf(x, alpha, beta)` (alias `beta2logpdf`) returns the beta-prime
log density for non-negative `x`, including extreme
tails where the ordinary density underflows. Inputs outside the support and
endpoint cases without a finite log density are rejected.
`gammadistpdf(x, shape, scale)`, `gammadistcdf(x, shape, scale)`, and
`gammadistccdf(x, shape, scale)` provide the gamma density, cumulative
probability, and survival probability using a positive shape in `(0, 10000]`
and positive finite scale. Negative values have zero density and CDF and
survival probability one; at zero, the density is singular for shape below
one, equals `1 / scale` for shape one, and is zero for shape above one.
`gammadistlogcdf(x, shape, scale)` and `gammadistlogccdf(...)` return the
natural logarithms of those probabilities, retaining extreme tails after
ordinary probabilities underflow; aliases are `gammalogcdf` and `gammalogccdf`.
The log-CDF rejects non-positive `x`, where no finite log probability exists.
`gammadistinv(p, shape, scale)` returns the quantile for `p` in `[0, 1)`;
`gammadistccinv(q, shape, scale)` (alias `gammaccinv`) directly inverts
survival probabilities in `(0, 1]`. Short aliases are `gammapdf`, `gammacdf`,
`gammaccdf`, and `gammainv`.
`gammadistinvprime(p, shape, scale)` (alias `gammainvprime`) returns the
probability derivative of the lower-tail quantile; `gammadistccinvprime(q,
shape, scale)` (alias `gammaccinvprime`) returns the negative derivative of
the survival quantile. Both require probabilities strictly between zero and
one and include the scale factor.
`gammalogpdf(x, shape, scale)` and its `gammadistlogpdf` alias return the log
density directly for finite tail values even when the PDF underflows; inputs
outside the support and endpoints without finite log densities are rejected.
`gammadistmean(shape, scale)`, `gammadistvariance(...)`,
`gammadiststddev(...)`, `gammadistskewness(...)`,
`gammadistexcesskurtosis(...)`, and `gammadistkurtosis(...)` return respectively
`shape × scale`, `shape × scale²`, `√shape × scale`, `2 / √shape`, `6 / shape`,
and `3 + 6 / shape`. Aliases include `gammamean`, `gammavar`, `gammastddev`,
and `gammaskewness`.
`gammadistmoment(k, shape, scale)` (alias `gammamoment`) returns the raw moment
`scale^k × shape × (shape + 1) × ... × (shape + k - 1)` for integer orders
`0..64`, using log-domain evaluation.
`gammadistmedian(shape, scale)` (alias `gammamedian`) returns the 0.5 quantile;
`gammadistmode(...)` (alias `gammamode`) returns `max(shape - 1, 0) × scale`.
`gammadistentropy(...)` (alias `gammaentropy`) returns differential entropy
`shape + ln(scale) + ln Γ(shape) + (1 - shape) ψ(shape)`.
`gammadisthazard(x, shape, scale)` (alias `gammahazard`) and
`gammadistcumhazard(...)` (alias `gammacumhazard`) return the hazard rate and
negative log survival probability. At `x = 0`, the hazard is singular for
shape below one, equals `1 / scale` at shape one, and is zero above one.
`gammadistmgf(t, shape, scale)` (alias `gammamgf`) and
`gammadistlogmgf(...)` (alias `gammalogmgf`) evaluate
`(1 - scale × t)^(-shape)` and its natural logarithm. They require finite `t`
with `t < 1 / scale`; the ordinary MGF reports overflow when its value exceeds
the finite floating-point range.
`cauchypdf(x, location, scale)`, `cauchycdf(x, location, scale)`, and
`cauchyccdf(x, location, scale)` evaluate the Cauchy density, cumulative
probability, and survival probability for finite location and positive finite
scale. `cauchyinv(p, location, scale)` returns the quantile for probabilities
strictly between zero and one.
`cauchyccinv(q, location, scale)` directly returns the quantile associated with
survival probability `q`, without forming `1 - q`; both inverse functions have
probability derivatives `cauchyinvprime(p, location, scale)` and
`cauchyccinvprime(q, location, scale)`. The survival-quantile derivative is
negative, and derivatives require probabilities strictly between zero and one.
`cauchymedian(location, scale)` and `cauchymode(...)` return the location;
`cauchyentropy(location, scale)` returns differential entropy `ln(4π × scale)`.
The ordinary mean and variance are undefined for this distribution.
`cauchylogpdf(x, location, scale)` returns the log density directly, including
for large standardized values where the ordinary Cauchy density underflows.
`cauchylogcdf(...)` and `cauchylogccdf(...)` preserve small heavy-tail
probabilities in logarithmic form. `cauchyhazard(x, location, scale)` returns
the density-to-survival ratio, while `cauchycumhazard(...)` returns the
negative log-survival. `cauchycharfuncreal(t, location, scale)` and
`cauchycharfuncimag(...)` expose the real and imaginary components of the
characteristic function `exp(-scale × |t|) exp(i × location × t)`.
`cauchyabsmoment(r, location, scale)` (`cauchyabsolutemoment`) returns
`E[|X - location|^r] = scale^r / cos(πr/2)` for `0 ≤ r < 1`; orders at or
above one diverge, consistent with the Cauchy distribution's heavy tails.
`paretopdf(x, shape, minimum)`, `paretocdf(x, shape, minimum)`, and
`paretoccdf(x, shape, minimum)` provide the Pareto Type I density, cumulative
probability, and survival probability. Shape and minimum must be positive and
finite; values below the minimum have zero density and CDF. `paretoinv(p,
shape, minimum)` returns the quantile for probabilities in `[0, 1)`.
`paretoccinv(q, shape, minimum)` directly inverts survival probabilities in
`(0, 1]`. Their derivatives are `paretoinvprime(p, shape, minimum)` and
`paretoccinvprime(q, shape, minimum)` for probabilities strictly between zero
and one; the survival-quantile derivative is negative.
`paretologpdf(x, shape, minimum)` returns the log density directly, including
finite extreme-tail values after the PDF underflows. `paretologcdf(...)` and
`paretologccdf(...)` compute log probabilities directly in both tails.
`paretomoment(n, shape, minimum)` (alias `paretorawmoment`) returns the raw
moment `shape × minimum^n / (shape - n)` for integer orders `n` from 0 through
64. A moment exists only when `n < shape`; divergent moments are rejected, and
overflow is reported rather than returning infinity.
`paretomean(shape, minimum)` requires `shape > 1`;
`paretovariance(...)` (alias `paretovar`) and `paretostddev(...)` require
`shape > 2`. `paretomedian(...)` returns `minimum × 2^(1/shape)` and
`paretomode(...)` returns the minimum. `paretoentropy(shape, minimum)` returns
differential entropy `ln(minimum / shape) + 1 + 1 / shape`.
`paretoskewness(shape, minimum)` exists only for `shape > 3`;
`paretoexcesskurtosis(...)` and `paretokurtosis(...)` require `shape > 4`.
`paretohazard(x, shape, minimum)` is zero below the support and `shape / x`
on it; `paretocumhazard(...)` is zero through the minimum and
`shape × ln(x / minimum)` above it.
`lomaxpdf(x, shape, scale)`, `lomaxcdf(x, shape, scale)`, and
`lomaxccdf(x, shape, scale)` provide the Lomax (Pareto Type II) density, CDF,
and survival probability; the equivalent `pareto2pdf`, `pareto2cdf`, and
`pareto2ccdf` aliases are available. `lomaxlogcdf(...)` and `lomaxlogccdf(...)`
(also `pareto2logcdf` and `pareto2logccdf`) preserve extreme-tail log
probabilities. Shape and scale must be positive and
finite; the support is `x >= 0`, with density and CDF zero and survival one
below zero. `lomaxinv(p, shape, scale)` (alias `pareto2inv`) returns the
quantile for probabilities in `[0, 1)`.
`lomaxccinv(q, shape, scale)` (alias `pareto2ccinv`) directly inverts survival
probabilities in `(0, 1]`. The inverse derivatives are `lomaxinvprime(p, shape,
scale)` and `lomaxccinvprime(q, shape, scale)` (aliases `pareto2invprime` and
`pareto2ccinvprime`); both require probabilities strictly between zero and
one, and the survival-quantile derivative is negative.
`lomaxmoment(n, shape, scale)` (alias `pareto2moment`) returns the raw moment
`scale^n × n! / ((shape - 1) ... (shape - n))` for integer orders `n` from 0
through 64. The zero-order moment is 1; higher moments exist only when
`n < shape`. `lomaxrawmoment` and `pareto2rawmoment` are additional aliases.
`lomaxmean(shape, scale)` requires `shape > 1`; `lomaxvariance(...)` (alias
`lomaxvar`) and `lomaxstddev(...)` require `shape > 2`.
The standardized shape statistics `lomaxskewness`, `lomaxexcesskurtosis`, and
`lomaxkurtosis` (also available with the `pareto2` prefix) have the same
existence thresholds and values as Pareto Type I for a given shape.
`lomaxmedian(shape, scale)` returns `scale × (2^(1/shape) - 1)` and
`lomaxmode(...)` returns zero. `lomaxentropy(...)` returns differential entropy
`ln(scale / shape) + 1 + 1/shape`. `lomaxhazard(x, shape, scale)` returns
`shape / (scale + x)` and `lomaxcumhazard(...)` returns
`shape × ln(1 + x/scale)` on the non-negative support. Each summary and
reliability function is also available under the `pareto2` prefix.
`lomaxlogpdf(x, shape, scale)` (alias `pareto2logpdf`) returns the log density
directly, including finite tail values after the PDF underflows.
`rayleighpdf(x, scale)`, `rayleighcdf(x, scale)`, and
`rayleighccdf(x, scale)` provide Rayleigh density and tail probabilities for a
positive finite scale. `rayleighlogcdf(x, scale)` and
`rayleighlogccdf(x, scale)` return log probabilities directly, retaining useful
values for tiny lower tails and survival probabilities after ordinary
probabilities underflow. The log-CDF is undefined at and below zero and raises
a domain error there. Negative values have zero density and CDF, and the
distribution is supported on non-negative values. `rayleighinv(p, scale)`
returns the quantile for probabilities in `[0, 1)`.
`rayleighccinv(q, scale)` directly inverts survival probabilities for
`q` in `(0, 1]`, without computing `1-q`.
Their probability derivatives are available as `rayleighinvprime(p, scale)`
and `rayleighccinvprime(q, scale)` for probabilities strictly between zero
and one; the survival-quantile derivative is negative.
`rayleighmean(scale)` and `rayleighvariance(scale)` (alias `rayleighvar`) return
the first two moments; `rayleighskewness(scale)`,
`rayleighexcesskurtosis(scale)`, and `rayleighkurtosis(scale)` return skewness,
excess kurtosis, and Pearson kurtosis. The standardized shape values do not
depend on scale.
`rayleighmoment(k, scale)` (alias `rayleighrawmoment`) returns the raw real
moment `scale^k × 2^(k/2) × Γ(1 + k/2)` for `-2 < k <= 10000`. Orders at or
below `-2` diverge; unrepresentable moments raise overflow.
`rayleighmedian(scale)`, `rayleighmode(scale)`, and `rayleighstddev(scale)`
return `scale × √(2 ln 2)`, `scale`, and
`scale × √((4 - π) / 2)`. `rayleighentropy(scale)` returns differential
entropy `1 + ln(scale / √2) + γ/2`, where `γ` is Euler's constant.
`rayleighhazard(x, scale)` returns `x / scale²`, and
`rayleighcumhazard(x, scale)` returns `x² / (2 scale²)`; both require
non-negative `x` and positive finite scale.
`rayleighmgf(t, scale)` and `rayleighlogmgf(t, scale)` evaluate the
moment-generating function and its natural logarithm for every finite real
`t`. They use the scaled complementary error function and a negative-tail
asymptotic expansion to retain accuracy when direct evaluation would overflow
or suffer cancellation; the ordinary MGF may underflow to zero or report
overflow at extreme tilts.
`rayleighlogpdf(x, scale)` returns the log density directly for positive `x`,
including tail values whose ordinary PDF underflows; `x ≤ 0` has no finite
log-density value.
`arcsinepdf(x)`, `arcsinecdf(x)`, and `arcsineccdf(x)` provide the arcsine
distribution on `[0, 1]`. The density is `1 / (π√(x(1-x)))` and is singular
at both endpoints; the CDF is `2 asin(√x) / π`, while the survival function
uses its complementary form to retain accuracy near one. `arcsinelogcdf(x)` and
`arcsinelogccdf(x)` compute log probabilities directly and preserve tail values
near zero and one; their divergent endpoint values raise domain errors.
`arcsineinv(p)`
returns `sin²(πp/2)` for probabilities in `[0, 1]`.
`chisquarepdf(x, degreesOfFreedom)`, `chisquarecdf(x, degreesOfFreedom)`, and
`chisquareccdf(x, degreesOfFreedom)` provide the chi-square density, lower-tail
probability, and upper-tail probability; aliases are `chipdf`, `chicdf`, and
`chiccdf`. Degrees of freedom may be any finite real value in `(0, 20000]`.
The CDFs return 0 and 1 respectively for non-positive `x`. The density is zero
for negative `x`; at zero it is finite only when the degrees of freedom are at
least 2. `chisquareinv(p, degreesOfFreedom)` (alias `chiinv`) returns the
quantile for `p` in `[0, 1)`; probability 1 has no finite quantile.
`chisquareccinv(q, degreesOfFreedom)` (alias `chiccinv`) directly inverts
survival probabilities in `(0, 1]`.
The inverse-quantile derivatives are available as `chisquareinvprime(p, k)`
(alias `chiinvprime`) and `chisquareccinvprime(q, k)` (alias `chiccinvprime`);
the survival-quantile derivative is negative and both require probabilities
strictly between zero and one.
`chisquarelogpdf(x, degreesOfFreedom)` (alias `chilogpdf`) returns the log
density directly, including tail values whose ordinary density underflows;
points where the density is zero or singular have no finite log-density and
are rejected.
The shape functions `chisquaremean(k)`, `chisquarevariance(k)` (alias `chivar`),
`chisquarestddev(k)` (alias `chistddev`), `chisquareskewness(k)`,
`chisquareexcesskurtosis(k)`, and `chisquarekurtosis(k)` return the mean `k`,
variance `2k`, standard deviation `√(2k)`, skewness `√(8/k)`, excess kurtosis
`12/k`, and Pearson kurtosis `3 + 12/k`. Their short aliases include `chimean`,
`chivariance`, `chiskewness`, `chiexcesskurtosis`, and `chikurtosis`. All accept
the same positive finite degrees of freedom as the density functions.
`chisquaremedian(k)` (alias `chimedian`) returns the 0.5 quantile;
`chisquaremode(k)` (alias `chimode`) returns `max(k - 2, 0)`; and
`chisquareentropy(k)` (alias `chientropy`) returns differential entropy
`k/2 + ln(2) + ln Γ(k/2) + (1 - k/2) ψ(k/2)`.
`chisquarehazard(x, k)` (alias `chihazard`) and `chisquarecumhazard(x, k)`
(alias `chicumhazard`) return the hazard rate and negative log survival. At
zero, hazard is singular for `k < 2`, equals `1/2` for `k = 2`, and is zero
for `k > 2`.
`chisquaremgf(t, k)` (alias `chimgf`) and `chisquarelogmgf(t, k)` (alias
`chilogmgf`) return the moment-generating function
`(1 - 2t)^(-k/2)` and its natural logarithm. They require finite `t < 1/2` and
degrees of freedom `k` in `(0, 20000]`; the ordinary MGF reports overflow if its
value exceeds the finite floating-point range.
`studenttpdf(x, degreesOfFreedom[, mean[, scale]])`,
`studenttlogpdf(x, degreesOfFreedom[, mean[, scale]])`,
`studenttcdf(x, degreesOfFreedom[, mean[, scale]])`, and
`studenttccdf(x, degreesOfFreedom[, mean[, scale]])` evaluate the Student's
t density, log-density, cumulative probability, and upper-tail probability.
`studenttlogcdf(...)` and `studenttlogccdf(...)` return the log probabilities,
preserving finite extreme-tail values when ordinary probabilities underflow.
Their short aliases are `tpdf`, `tlogpdf`, `tcdf`, `tccdf`, `tlogcdf`, and
`tlogccdf`. The log-density also retains useful finite tail values.
Degrees of freedom may be any finite
real value in `(0, 19999]`; the upper bound keeps the calculation within the
incomplete-beta evaluator's supported parameter range. `studenttinv(p,
degreesOfFreedom[, mean[, scale]])` (alias `tinv`) returns quantiles for
probabilities strictly between zero and one. All Student's t functions default
to mean zero and scale one, and require a finite location and positive finite
scale when those optional parameters are supplied.
`studenttmean(degreesOfFreedom[, mean[, scale]])` (alias `tmean`) returns the
location when degrees of freedom exceed 1. `studenttvariance(...)` (aliases
`tvariance` and `tvar`) and `studenttstddev(...)` (alias `tstddev`) require
degrees of freedom above 2 and include the square of the scale parameter.
`studenttmedian(...)`/`tmedian` and `studenttmode(...)`/`tmode` both return the
location for every supported degrees-of-freedom value. `studenttentropy(...)`
(alias `tentropy`) returns differential entropy, including the additive
`log(scale)` adjustment. `studentthazard(x, degreesOfFreedom[, mean[, scale]])`
(alias `thazard`) returns density divided by survival probability, and
`studenttcumhazard(...)` (alias `tcumhazard`) returns negative log survival.
`studenttskewness(...)` (alias `tskewness`) is zero only when degrees of
freedom exceed 3; `studenttexcesskurtosis(...)` (alias `texcesskurtosis`) and
`studenttkurtosis(...)` (alias `tkurtosis`) require degrees of freedom above 4.
Moments that do not exist at the requested degrees of freedom raise domain
errors. The existence conditions and standard formulas are given by the
[Student's t distribution reference](https://stats.libretexts.org/Bookshelves/Probability_Theory/Probability_Mathematical_Statistics_and_Stochastic_Processes_%28Siegrist%29/05%3A_Special_Distributions/5.10%3A_The_Student_t_Distribution).
`binomialpmf(k, n, p)`, `binomialcdf(k, n, p)`, and `binomialccdf(k, n, p)`
provide the binomial probability mass, cumulative probability `P(X ≤ k)`, and
strict upper tail `P(X > k)`; aliases are `binompmf`, `binomcdf`, and
`binomccdf`. The trial count `n` must be an integer from 0 through 9,999 and
the per-trial success probability `p` must be in `[0, 1]`. CDF thresholds may
be non-integer and are rounded down. `binomialinv(q, n, p)` (alias `binominv`)
returns the smallest integer `k` whose cumulative probability is at least `q`,
for `q` in `[0, 1]`.
`binomialccinv(q, n, p)` (alias `binomccinv`) returns the smallest `k` whose
strict upper-tail probability `P(X > k)` is at most `q`, for `q` in `[0, 1]`.
`binomiallogpmf(k, n, p)` (alias `binomlogpmf`) evaluates the log mass directly,
preserving finite tail values after the ordinary PMF underflows; impossible
outcomes return negative infinity.
`binomiallogcdf(k, n, p)` (alias `binomlogcdf`) and
`binomiallogccdf(k, n, p)` (alias `binomlogccdf`) evaluate the log cumulative
and strict upper-tail probabilities directly, retaining extreme-tail values
when their ordinary probabilities underflow. Their zero-probability endpoints
are reported as domain errors because the log probabilities are negative infinity.
`binomialmean(n, p)` and `binomialvariance(n, p)` (alias `binomialvar`) return
the expected success count and its variance.
`binomialstddev(n, p)` returns the standard deviation. `binomialmedian(n, p)`
returns the lower median, and `binomialmode(n, p)` returns the largest mode (if
`(n + 1)p` is an integer, the preceding value is also a mode).
`binomialmoment(r, n, p)` and `binomialrawmoment(r, n, p)` return raw integer
moments for orders 0 through 64 using factorial moments.
`binomialmgf(t, n, p)` and `binomiallogmgf(t, n, p)` evaluate the moment-
generating function and its natural logarithm for finite real `t`.
`binomialskewness(n, p)` returns standardized third central moment;
`binomialexcesskurtosis(n, p)` returns standardized fourth central moment minus
three; `binomialkurtosis(n, p)` returns Pearson kurtosis (excess plus three).
Skewness and kurtosis are undefined when the distribution is degenerate.
The `binom*` prefix is also available for these summaries, moments, and
transforms.
`multinomialpmf(x1, p1, ..., xk, pk)` evaluates the joint probability mass for
category counts and probabilities supplied as alternating pairs. Counts must
be non-negative integers totaling at most 10,000; there may be 2–16 categories,
each probability must be in `[0, 1]`, and probabilities must sum to one.
`multinomiallogpmf(...)` returns the corresponding log mass directly; impossible
count vectors or positive counts in zero-probability categories return negative
infinity.
`multinomialmean(n, p)`, `multinomialvariance(n, p)` (alias `multinomialvar`),
and `multinomialstddev(n, p)` describe the marginal count for one category.
`multinomialmoment(r, n, p)` (alias `multinomialrawmoment`) returns its raw
integer moment for orders 0 through 64. `multinomialcovariance(n, p_i, p_j)`
returns `-n p_i p_j` for two distinct categories, requiring their probabilities
to sum to at most one.
`bernoullipmf(k, p)`, `bernoullicdf(x, p)`, `bernoulliccdf(x, p)`, and
`bernoulliinv(q, p)` provide the Bernoulli mass, lower and upper tails, and
quantile for one trial. The outcome support is `{0, 1}`; `p` and `q` are in
`[0, 1]`, and CDF thresholds may be fractional. This is distinct from
`bernoulli(n)`, which returns a Bernoulli number.
`bernoulliccinv(q, p)` returns the smallest integer whose strict upper tail is
at most `q`; its finite-quantile domain is `[0, 1)`. At `q = 1`, every integer
below the support qualifies, so there is no smallest threshold.
`bernoullilogpmf(k, p)` returns the natural log of the Bernoulli mass, with
impossible events represented by negative infinity.
`bernoullimean(p)` and `bernoullivariance(p)` (alias `bernoullivar`) return
the success probability and `p(1-p)`. `bernoullimgf(t, p)` and
`bernoullilogmgf(t, p)` evaluate the moment-generating function and its natural
logarithm for finite real `t`; these agree with the corresponding binomial
functions at `n = 1`. `bernoullimoment(n, p)` (`bernoullirawmoment`) returns
the nth raw moment for integer orders 0 through 64. `bernoullimedian(p)` and
`bernoullimode(p)` return the lower median and largest mode, respectively;
both select 0 when `p = 0.5`.
`bernoullistddev(p)` returns `sqrt(p(1-p))`; `bernoullientropy(p)` returns the
binary Shannon entropy and equals zero at `p = 0` and `p = 1`.
`bernoulliskewness(p)`, `bernoulliexcesskurtosis(p)`, and
`bernoullikurtosis(p)` provide standardized shape statistics and are undefined
for those deterministic endpoint probabilities.
`betabinomialpmf(k, n, alpha, beta)`, `betabinomialcdf(k, n, alpha, beta)`,
and `betabinomialccdf(k, n, alpha, beta)` evaluate the beta-binomial mass,
lower-tail probability, and strict upper tail. This models binomial trials whose
success probability is drawn once from a beta distribution. The trial count
must be an integer from 0 through 9,999; `alpha` and `beta` must each be in
`(0, 10000]`. CDF thresholds may be fractional and are rounded down.
`betabinomialinv(q, n, alpha, beta)` returns the smallest outcome whose CDF is
at least `q`, for `q` in `[0, 1]`.
`betabinomialccinv(q, n, alpha, beta)` returns the smallest outcome whose
strict upper tail is at most `q`, for `q` in `[0, 1]`.
`betabinomiallogpmf(k, n, alpha, beta)` returns the log mass directly,
including finite values after the corresponding PMF underflows.
`betabinomiallogcdf(k, n, alpha, beta)` and
`betabinomiallogccdf(k, n, alpha, beta)` return the lower and strict upper
log-tail probabilities using log-sum-exp over the finite support. This retains
tails that are too small for ordinary floating-point probabilities; zero-mass
endpoints are reported as domain errors.
`betabinomialmean(n, alpha, beta)` and `betabinomialvariance(n, alpha, beta)`
(alias `betabinomialvar`) return the distribution's expected success count and
variance.
`betabinomialstddev(n, alpha, beta)` returns the square root of that variance.
`betabinomialmedian(...)` returns the smallest outcome whose CDF is at least
one half; `betabinomialmode(...)` scans the finite support and selects the
largest outcome in a tie. `betabinomialmoment(r, n, alpha, beta)` and its
`betabinomialrawmoment` alias return raw integer-order moments for orders 0
through 64. `betabinomialentropy(n, alpha, beta)` returns Shannon entropy in
nats.
`betabinomialmgf(t, n, alpha, beta)` and `betabinomiallogmgf(...)` evaluate
the moment-generating function and its natural logarithm. The support is finite,
so both are defined for every finite real `t`; the log form uses log-sum-exp for
stable evaluation.
`betabinomialskewness(n, alpha, beta)`,
`betabinomialexcesskurtosis(n, alpha, beta)`, and
`betabinomialkurtosis(n, alpha, beta)` provide the corresponding standardized
third moment, excess fourth moment, and Pearson kurtosis. They are undefined
when there are no trials.
`discreteuniformpmf(x, a, b)`, `discreteuniformcdf(x, a, b)`, and
`discreteuniformccdf(x, a, b)` describe the uniform distribution over every
integer in the inclusive interval `[a, b]`. Bounds must be ordered integers
within `[-10^12, 10^12]`; CDF inputs can be fractional and are rounded down.
`discreteuniforminv(q, a, b)` returns the smallest supported integer whose
cumulative probability reaches `q`, for `q` in `[0, 1]`.
`discreteuniformccinv(q, a, b)` returns the smallest supported integer whose
strict upper-tail probability is at most `q`, also for `q` in `[0, 1]`.
`discreteuniformlogpmf(x, a, b)` returns the log mass, or negative infinity for
non-integer values and outcomes outside the inclusive support.
`discreteuniformlogcdf(x, a, b)` and `discreteuniformlogccdf(x, a, b)` return
the log lower and strict upper tails; logarithms of zero-probability tails are
reported as domain errors.
`discreteuniformmean(a, b)` and `discreteuniformvariance(a, b)` (alias
`discreteuniformvar`) return the mean and variance over the inclusive integer
support.
`discreteuniformstddev(a, b)`, `discreteuniformentropy(a, b)`,
`discreteuniformskewness(a, b)`, `discreteuniformexcesskurtosis(a, b)`, and
`discreteuniformkurtosis(a, b)` provide the standard deviation, Shannon entropy,
and standardized shape summaries. Skewness and kurtosis are undefined for a
one-value support; the other summaries remain defined.
`discreteuniformmoment(n, a, b)` (`discreteuniformrawmoment`) returns raw
moments through order 64. `discreteuniformmedian(a, b)` returns the lower
median for even-sized support, and `discreteuniformmode(a, b)` returns the
smallest support value (every value is a mode because the mass is uniform).
`discreteuniformmgf(t, a, b)` and `discreteuniformlogmgf(t, a, b)` evaluate
the moment-generating function and its natural logarithm for any finite real
`t`. The closed-form calculation handles large integer intervals without
iterating over their support.
`zipfpmf(k, s, N)`, `zipfcdf(k, s, N)`, and `zipfccdf(k, s, N)` describe the
finite Zipf distribution on ranks `1..N`, with mass proportional to `k^-s`.
The exponent `s` may be any finite non-negative real value (zero gives the
uniform distribution), and the integer support size `N` is in `[1, 10000]`.
CDF thresholds may be fractional and are rounded down. `zipfinv(q, s, N)`
returns the smallest rank whose cumulative probability reaches `q`, for
`q` in `[0, 1]`.
`zipfccinv(q, s, N)` returns the smallest rank whose strict upper tail is at
most `q`, also for `q` in `[0, 1]`.
`zipflogpmf(k, s, N)` evaluates the log mass directly and returns negative
infinity outside the finite rank support.
`zipfmean(s, N)` and `zipfvariance(s, N)` (alias `zipfvar`) return the mean and
variance of the finite Zipf distribution; these are finite for every accepted
exponent because the support is bounded. `zipfstddev(s, N)` returns the square
root of the variance.
`zipfmedian(s, N)` returns the lower median and `zipfmode(s, N)` returns rank 1
(the smallest mode when `s = 0`). `zipfentropy(s, N)`, `zipfskewness(s, N)`,
`zipfexcesskurtosis(s, N)`, and `zipfkurtosis(s, N)` provide entropy and shape
summaries; standardized moments are undefined for one-point support.
`zipfmoment(n, s, N)` (alias `zipfrawmoment`) returns raw integer moments for
orders 0 through 64, and `zipfmgf(t, s, N)` / `zipflogmgf(...)` evaluate the
moment-generating function and its logarithm for finite real `t`.
`benfordpmf(d[, base])`, `benfordcdf(d[, base])`, and `benfordccdf(d[, base])`
provide Benford's leading-digit probabilities in the given integer radix
(default base 10, supported bases 2 through 10^12). The CDF uses
`log_base(floor(d) + 1)` on the digit support `1..base-1`; fractional thresholds
are rounded down. `benfordinv(q[, base])` returns the smallest leading digit
whose cumulative probability reaches `q` in `[0, 1]`.
`benfordccinv(q[, base])` returns the smallest leading digit whose strict
upper-tail probability is at most `q`, for `q` in `[0, 1]`.
`benfordlogpmf(d[, base])` returns the log mass; invalid leading digits have
zero mass and therefore map to negative infinity.
`benfordmean([base])`, `benfordmedian([base])`, and `benfordmode([base])` expose
leading-digit location summaries. The mean uses a closed-form log-gamma
identity, so it remains practical at the full supported base limit. The median
is the lower median; digit 1 is the mode (with all digits tied in base 2).
`zetapmf(k, s)`, `zetacdf(k, s)`, and `zetaccdf(k, s)` provide the infinite-
support zeta distribution on positive integer ranks, with mass `k^-s / ζ(s)`
for finite real `s > 1`. The upper tail uses the Hurwitz zeta function directly.
`zetainv(q, s)` returns the smallest rank whose CDF reaches `q`; `q` must be in
`[0, 1)` because this distribution has no finite quantile at one.
`zetaccinv(q, s)` directly inverts the upper tail for `q` in `(0, 1]`; zero
has no finite quantile.
`zetalogpmf(k, s)` evaluates the log mass directly, including tiny tail
probabilities after the ordinary PMF underflows.
`zetamean(s)` requires `s > 2`; `zetavariance(s)` (alias `zetavar`) requires
`s > 3`. `zetastddev(s)`, `zetamedian(s)`, and `zetamode(s)` provide the
standard deviation and location summaries. `zetamoment(n, s)` (alias
`zetarawmoment`) returns integer raw moments through order 64 when `s > n + 1`.
`zetaentropy(s)` returns Shannon entropy in nats for every `s > 1`, using the
derivative identity `log(ζ(s)) - s ζ′(s) / ζ(s)`.
`zetaskewness(s)` requires `s > 4`, and `zetaexcesskurtosis(s)` /
`zetakurtosis(s)` require `s > 5`.
`discretelaplacepmf(k, q)`, `discretelaplacecdf(k, q)`,
`discretelaplaceccdf(k, q)`, `discretelaplaceinv(p, q)`, and
`discretelaplaceccinv(p, q)` implement the
two-sided geometric (discrete Laplace) distribution on integer support, with mass proportional to
`q^|k|` and decay parameter `0 ≤ q < 1`; `doublegeometric*` are aliases.
Both inverse functions require probabilities strictly between zero and one
because the distribution has unbounded support in both directions. The
inverse survival function has the `doublegeometricccinv` alias.
`discretelaplacelogpmf(k, q)` returns the log mass directly;
`doublegeometriclogpmf` is an alias.
`discretelaplacemean(q)` is zero by symmetry, and
`discretelaplacevariance(q)` (alias `discretelaplacevar`) returns
`2q / (1 - q)^2`. The same functions are available as
`doublegeometricmean`, `doublegeometricvariance`, and `doublegeometricvar`.
`discretelaplacestddev(q)`, `discretelaplacemedian(q)`, and
`discretelaplacemode(q)` provide spread and location summaries; median and mode
are zero. `discretelaplaceentropy(q)` returns the Shannon entropy,
`discretelaplacemoment(n, q)` / `discretelaplacerawmoment(...)` return raw
integer moments through order 64, and skewness/kurtosis functions provide
standardized shape summaries (excess kurtosis is undefined at `q = 0`).
`discretelaplacemgf(t, q)` and `discretelaplacelogmgf(t, q)` are defined for
`|t| < -log(q)`; at `q = 0` both are defined for every finite `t`. Matching
`doublegeometric*` aliases are provided for each summary and transform.
`poissonpmf(k, lambda)`, `poissoncdf(k, lambda)`, and
`poissonccdf(k, lambda)` provide the Poisson probability mass, cumulative
probability `P(X ≤ k)`, and strict upper tail `P(X > k)`; aliases are `poipmf`,
`poicdf`, and `poiccdf`. Outcomes may be fractional for the cumulative
functions and are rounded down. The finite rate parameter is in `[0, 1000000]`.
`poissoninv(q, lambda)` returns the smallest non-negative integer whose CDF
reaches `q`; `poiinv`, `poissonppf`, and `poippf` are aliases. The target is in
`[0, 1)`: probability 1 has no finite Poisson quantile.
`poissonccinv(q, lambda)` (alias `poiccinv`) directly inverts the strict upper
tail for `q` in `(0, 1]`; zero has no finite quantile unless `lambda` is zero.
`poissonlogpmf(k, lambda)` (alias `poilogpmf`) returns the log mass directly,
including finite values after the ordinary PMF underflows.
`poissonlogcdf(k, lambda)` (alias `poilogcdf`) and
`poissonlogccdf(k, lambda)` (alias `poilogccdf`) compute the log cumulative and
strict upper-tail probabilities directly from regularized incomplete-gamma
functions, preserving finite tail values after ordinary probabilities
underflow. Their zero-probability endpoints are reported as domain errors.
`poissonmean(lambda)` and `poissonvariance(lambda)` (alias `poissonvar`) both
return the rate parameter, which is both the distribution's mean and variance.
`poissonstddev(lambda)` (alias `poistddev`) returns the square root of the rate.
`poissonmoment(n, lambda)` returns the nth raw moment for integer orders 0
through 64; `poissonrawmoment`, `poimoment`, and `poirawmoment` are aliases.
`poissonmedian(lambda)` (`poimedian`) returns a lower median, while
`poissonmode(lambda)` (`poimode`) returns the largest mode (at integer rates,
both that value and the preceding integer are modes).
`poissonskewness(lambda)`, `poissonexcesskurtosis(lambda)`, and
`poissonkurtosis(lambda)` (also available with the `poi` prefix) return its
skewness, excess kurtosis, and Pearson kurtosis. These shape statistics are
undefined for the degenerate zero-rate distribution.
`poissonmgf(t, lambda)` and `poissonlogmgf(t, lambda)` evaluate the moment-
generating function and its natural logarithm for finite real `t`; the log form
retains values when the ordinary MGF would overflow or underflow.
`geometricpmf(k, p)`, `geometriccdf(k, p)`, and `geometricccdf(k, p)` model
the number of failures `k` before the first success with independent trial
success probability `p`; aliases are `geompdf`, `geomcdf`, and `geomccdf`.
The success probability is in `(0, 1]`; cumulative thresholds may be
fractional and are rounded down. `geometricinv(q, p)` (alias `geominv`) returns
the smallest failure count whose CDF reaches `q`, for `q` in `[0, 1)`.
Probability 1 has no finite quantile unless `p = 1`, when the distribution is
concentrated at zero failures.
`geometricccinv(q, p)` (alias `geomccinv`) returns the smallest failure count
whose strict upper-tail probability is at most `q`; zero has no finite
quantile unless `p = 1`.
`geometriclogpmf(k, p)` (alias `geomlogpmf`) returns the log mass directly,
including finite tail values after the PMF underflows; impossible outcomes
return negative infinity.
`geometriclogcdf(k, p)` (alias `geomlogcdf`) and
`geometriclogccdf(k, p)` (alias `geomlogccdf`) evaluate log tails directly,
including very small survival probabilities; zero-probability tails are
reported as domain errors.
`geometricdistributionmean(p)` (alias `geommean`) and `geometricvariance(p)`
(aliases `geomvariance` and `geomvar`) return the expected number of failures
before the first success and its variance. The distinct name avoids conflicting
with `geometricmean(values...)`, the descriptive-statistics function.
`geometricstddev(p)`, `geometricmedian(p)`, and `geometricmode(p)` provide the
standard deviation, lower median, and mode; the mode is zero. `geometricentropy(p)`
returns Shannon entropy, and `geometricmoment(n, p)` /
`geometricrawmoment(n, p)` return raw integer moments for orders 0 through 64.
`geometricmgf(t, p)` and `geometriclogmgf(t, p)` exist for
`t < -log(1 - p)`; when `p = 1`, the distribution is concentrated at zero and
both transforms equal 1 and 0 respectively for all finite `t`. Matching
`geom*` aliases are available.
`geometricskewness(p)`, `geometricexcesskurtosis(p)`, and
`geometrickurtosis(p)` (also available with the `geom` prefix) return the
distribution's skewness, excess kurtosis, and Pearson kurtosis. These shape
statistics are undefined at `p = 1`, where failures are always zero.
`negativebinomialpmf(k, r, p)`, `negativebinomialcdf(k, r, p)`, and
`negativebinomialccdf(k, r, p)` model the number of failures `k` before the
`r`th success, where each independent trial succeeds with probability `p`.
The short aliases are `nbinompmf`, `nbinomcdf`, and `nbinomccdf`; CDF outcomes
may be fractional and are rounded down. The required-success count `r` is an
integer in `[1, 10000]`, and `p` is in `(0, 1]` (with `p = 1` giving zero
failures). `negativebinomialinv(q, r, p)` (alias `nbominv`) returns the smallest
failure count whose CDF reaches `q`, for `q` in `[0, 1)`; probability 1 has no
finite quantile.
`negativebinomialccinv(q, r, p)` (alias `nbomccinv`) directly inverts the
strict upper tail for `q` in `(0, 1]`; zero has no finite quantile unless
`p = 1`.
`negativebinomiallogpmf(k, r, p)` (alias `nbinomlogpmf`) returns the log mass
directly, preserving finite tail values after the PMF underflows.
`negativebinomiallogcdf(k, r, p)` (alias `nbinomlogcdf`) and
`negativebinomiallogccdf(k, r, p)` (alias `nbinomlogccdf`) return the log lower
and strict upper tails directly, retaining extreme probabilities when ordinary
tails underflow. Logarithms of zero-probability outcomes are reported as domain
errors. `negativebinomialmean(r, p)` (alias `nbinommean`) and
`negativebinomialvariance(r, p)` (aliases `nbinomvariance` and `nbinomvar`)
return the expected failure count and its variance.
`negativebinomialstddev(r, p)` (alias `nbinomstddev`) returns the standard
deviation of the failure count.
`negativebinomialskewness(r, p)`, `negativebinomialexcesskurtosis(r, p)`,
and `negativebinomialkurtosis(r, p)` (also available with the `nbinom` prefix)
return skewness, excess kurtosis, and Pearson kurtosis. Shape statistics are
undefined when `p = 1`, where failures are always zero.
`negativebinomialmedian(r, p)` (alias `nbinommedian`) returns the lower
0.5-quantile. `negativebinomialmode(r, p)` (alias `nbinommode`) returns
`floor((r - 1)(1 - p) / p)`; when the expression inside the floor is an
integer, the distribution has a second adjacent mode one count lower. Both
summaries return zero for the degenerate case `p = 1`.
`negativebinomialmgf(t, r, p)` (alias `nbinommgf`) and
`negativebinomiallogmgf(t, r, p)` (alias `nbinomlogmgf`) evaluate the moment-
generating function and its logarithm for finite `t < -log(1 - p)`. At and
above that boundary the MGF diverges; when `p = 1`, the distribution is
concentrated at zero and both functions return one and zero respectively.
`negativebinomialmoment(n, r, p)` returns the raw moment of integer order
`n ∈ [0, 64]` (also `negativebinomialrawmoment` or `nbinomrawmoment`). It uses
the Stirling-number expansion from factorial moments and reports overflow when
the result is outside the finite floating-point range.
`hypergeometricpmf(k, N, K, n)`, `hypergeometriccdf(k, N, K, n)`, and
`hypergeometricccdf(k, N, K, n)` model the number of marked items in `n` draws
without replacement from a population of `N` containing `K` marked items. The
short aliases are `hypergeompmf`, `hypergeomcdf`, and `hypergeomccdf`. The
population size is an integer in `[0, 10000]`, and `K` and `n` are integers in
`[0, N]`. The supported outcome range is `max(0, n - (N - K))` through
`min(n, K)`. `hypergeometricinv(q, N, K, n)` (alias `hypergeominv`) returns
the smallest supported outcome whose cumulative probability is at least `q`,
for `q` in `[0, 1]`.
`hypergeometricccinv(q, N, K, n)` (alias `hypergeomccinv`) returns the
smallest supported outcome whose strict upper tail is at most `q`, for
`q` in `[0, 1]`.
`hypergeometricmedian(N, K, n)` (alias `hypergeommedian`) returns the lower
0.5-quantile. `hypergeometricmode(N, K, n)` (alias `hypergeommode`) returns
`floor((n + 1)(K + 1) / (N + 2))`; when that ratio is an integer, there is a
second adjacent mode one count lower. Both return the sole support value for a
degenerate distribution.
`hypergeometriclogpmf(k, N, K, n)` (alias `hypergeomlogpmf`) returns the log
mass directly and negative infinity for outcomes outside finite-population
support.
`hypergeometriclogcdf(k, N, K, n)` (alias `hypergeomlogcdf`) and
`hypergeometriclogccdf(k, N, K, n)` (alias `hypergeomlogccdf`) compute log tails
with log-sum-exp over the finite support, preserving probabilities too small for
ordinary floating-point CDF values. Zero-probability endpoints are domain errors.
`hypergeometricmean(N, K, n)` and `hypergeometricvariance(N, K, n)` (aliases
`hypergeommean` and `hypergeomvariance`) return the expected number of marked
items and its variance, including the finite-population correction.
`hypergeometricstddev(N, K, n)` (alias `hypergeomstddev`) returns the standard
deviation, and `hypergeometricentropy(N, K, n)` (alias `hypergeomentropy`)
returns Shannon entropy in nats, computed over the normalized finite support.
`hypergeometricskewness`, `hypergeometricexcesskurtosis`, and
`hypergeometrickurtosis` (also available with the `hypergeom` prefix) report
the distribution's skewness, excess kurtosis, and Pearson kurtosis. Shape
statistics are undefined for degenerate distributions and raise a domain error.
`hypergeometricmoment(j, N, K, n)` returns the raw moment of integer
order `j` from zero through 64, also exposed as `hypergeometricrawmoment`,
`hypergeommoment`, and `hypergeomrawmoment`. It uses the falling-factorial
moment identity and returns zero for positive orders when the sample contains
no marked items or draws.
`hypergeometricmgf(t, N, K, n)` (alias `hypergeommgf`) and
`hypergeometriclogmgf(...)` (alias `hypergeomlogmgf`) evaluate the
moment-generating function and its logarithm across the finite feasible support.
`multivariatehypergeometricpmf(x1, N1, ..., xk, Nk)` (alias
`multihypergeometricpmf`) gives the joint mass for category counts sampled
without replacement. Arguments are alternating observed counts and category
population sizes, with 2–16 categories. Each population is a non-negative
integer and their total may not exceed 10,000; observed counts outside their
category support have probability zero.
`multivariatehypergeometriclogpmf(...)` (alias `multihypergeometriclogpmf`)
returns the log mass directly, retaining information when the mass underflows.
For a category of size `K` in total population `N` when drawing `n` items,
`multivariatehypergeometricmean(N, K, n)`,
`multivariatehypergeometricvariance(N, K, n)`, and
`multivariatehypergeometricstddev(N, K, n)` provide its marginal moments using
the hypergeometric distribution. `multivariatehypergeometricmoment(r, N, K, n)`
and `multivariatehypergeometricrawmoment(...)` return raw integer moments
through order 64. `multivariatehypergeometriccovariance(N, K_i, K_j, n)` returns
the covariance between two distinct category counts and requires
`K_i + K_j ≤ N`.
`negativehypergeometricpmf(k, N, K, r)` and its `cdf`, `ccdf`, and `inv`
counterparts model the number `k` of failures before the `r`th success while
drawing without replacement from `N` items containing `K` successes. The
supported count is from zero through `N - K`; parameters are integers satisfying
`1 ≤ r ≤ K ≤ N ≤ 10000`. The short `neghypergeom*` aliases are also available.
`negativehypergeometricccinv(q, N, K, r)` (alias `neghypergeomccinv`) returns
the smallest failure count whose strict upper tail is at most `q`, for
`q` in `[0, 1]`.
`negativehypergeometriclogpmf(k, N, K, r)` (alias `neghypergeomlogpmf`)
returns the log mass directly, including extreme finite-population tails.
`negativehypergeometriclogcdf(k, N, K, r)` and
`negativehypergeometriclogccdf(k, N, K, r)` (aliases `neghypergeomlogcdf` and
`neghypergeomlogccdf`) compute log tails directly with log-sum-exp, preserving
rare outcomes that underflow in ordinary probabilities. Logarithms of zero
tails are reported as domain errors.
`negativehypergeometricmean(N, K, r)` and
`negativehypergeometricvariance(N, K, r)` (aliases `neghypergeommean` and
`neghypergeomvariance`) return the expected number and variance of failures
before the `r`th success. `negativehypergeometricstddev(...)` returns its
standard deviation; `negativehypergeometricmedian(...)` returns the smallest
failure count whose CDF reaches one half; and `negativehypergeometricmode(...)`
returns the most likely failure count, choosing the larger value for numerical
ties. `negativehypergeometricentropy(...)` returns Shannon entropy in nats.
These summaries also have `neghypergeom*` aliases.
`negativehypergeometricskewness`, `negativehypergeometricexcesskurtosis`, and
`negativehypergeometrickurtosis` (also available with the `neghypergeom` prefix)
return skewness, excess kurtosis (zero for a normal distribution), and Pearson
kurtosis (three for a normal distribution). Shape statistics are undefined for
a degenerate distribution and raise a domain error.
`negativehypergeometricmoment(j, N, K, r)` returns raw moments for integer order
`j` in `[0, 64]`, also available as `negativehypergeometricrawmoment`,
`neghypergeommoment`, and `neghypergeomrawmoment`. The finite-support
`negativehypergeometricmgf(t, N, K, r)` and
`negativehypergeometriclogmgf(t, N, K, r)` (aliases `neghypergeommgf` and
`neghypergeomlogmgf`) accept any finite real tilt; the ordinary MGF reports
overflow when its value is outside the floating-point range.
`airyai(x)` and `airybi(x)` use finite power-series and asymptotic evaluation
with explicit overflow errors for values outside the floating-point range.
Their first derivatives are `airyaiprime(x)` and `airybiprime(x)`; their
second derivatives are `airyaisecond(x) = x·airyai(x)` and
`airybisecond(x) = x·airybi(x)`, from the Airy differential equation.
The third derivatives `airyaithird(x)` and `airybithird(x)` use the identity
`y'''(x) = y(x) + x·y'(x)` from the same equation.
`ellipk(m)` and `ellipe(m)` use the complete real elliptic integrals with
parameter `m ≤ 1`; `ellipk(1)` is explicitly divergent.
`ellipkprime(m)` and `ellipeprime(m)` differentiate the complete integrals with
respect to the parameter. They use small-parameter series to retain accuracy
near `m = 0`, where their limits are `π/8` and `−π/8`; both diverge at `m = 1`.
`ellipnome(m)` converts the elliptic parameter `m` in `[0, 1]` to the nome
`q = exp(-π K(1-m) / K(m))`, with its endpoint values defined as 0 and 1.
This connects the parameter-based elliptic functions to the nome-based theta
functions below; a small-parameter series avoids loss of precision near zero.
`ellipnomeinv(q)` performs the reverse conversion for `q` in `[0, 1]`, using
the theta-constant identity and a modular transformation for rapid convergence
when `q` is above one half. Values so close to one that the corresponding
parameter cannot be distinguished from one in floating-point arithmetic
return the representable endpoint `1`.
`jacobisn(u, m)`, `jacobicn(u, m)`, and `jacobidn(u, m)` evaluate the real
Jacobi elliptic functions for finite `u` and parameter `0 ≤ m ≤ 1` (also
available as `sn`, `cn`, and `dn`). The endpoint cases reduce to circular
functions at `m = 0` and hyperbolic functions at `m = 1`.
`jacobiam(u, m)` (alias `am`) returns the continuously unwrapped Jacobi
amplitude, satisfying `sn(u, m) = sin(am(u, m))` and
`am(u + 2K(m), m) = am(u, m) + π`; its endpoint cases are `u` at `m = 0` and
the Gudermannian at `m = 1`.
The nine subsidiary real Jacobi functions are available as `jacobicd`,
`jacobisd`, `jacobind`, `jacobidc`, `jacobinc`, `jacobisc`, `jacobins`,
`jacobids`, and `jacobics`, with short aliases `cd`, `sd`, `nd`, `dc`, `nc`,
`sc`, `ns`, `ds`, and `cs`. They are formed from the primary functions by the
standard quotients, and poles caused by a zero denominator are reported.
`jacobizeta(u, m)` (alias `jzeta`) evaluates the real Jacobi zeta function on
the same parameter interval; it is odd, periodic with period `2K(m)`, and
reduces to `tanh(u)` at `m = 1`.
`jacobitheta1(z, q)` through `jacobitheta4(z, q)` (also `theta1` through
`theta4`) evaluate the four real Jacobi theta functions for finite real `z`
and nome `0 ≤ q < 1`. Their convergent series use a fixed iteration ceiling;
for nomes too close to one to converge within that bound they raise an explicit
error rather than running without limit.
`ellipf(phi, m)`/`ellipkinc(phi, m)` and `ellipeinc(phi, m)` provide incomplete
forms on the same real parameter domain and reject singular endpoints.
`ellipfprime(phi, m)` (alias `ellipkincprime`), `ellipeincprime(phi, m)`, and
`ellippincprime(phi, n, m)` return derivatives with respect to amplitude:
`1/sqrt(1 - m sin²(phi))`, `sqrt(1 - m sin²(phi))`, and
`1/((1 - n sin²(phi)) sqrt(1 - m sin²(phi)))`, respectively.
`ellippi(n, m)` and `ellippinc(phi, n, m)` provide the real third-kind
elliptic integral for `n < 1`; complete `Pi` requires `m < 1`, while the
incomplete form rejects paths through the `m = 1` singular endpoint.
`lambertw(z)` evaluates the principal real branch, while `lambertw(z, -1)`
selects the lower real branch on `-1/e <= z < 0`. `lambertwprime(z[, k])`
(alias `lambertwderivative`) evaluates `W_k(z)/(z(1 + W_k(z)))` on either real
branch, with the continuous principal-branch value 1 at zero; the derivative
is singular at the shared branch point `z = -1/e`.
The second derivative is `lambertwsecond(z[, k])` (alias
`lambertwsecondderivative`), with principal-branch value `-2` at zero and the
same branch-point singularity. The third derivative is available as
`lambertwthird(z[, k])` (alias `lambertwthirdderivative`); its continuous
principal-branch value at zero is `9`, and it is singular at `z = -1/e` on
both real branches.

## Development

The public core is the only package in this repository. The optional explaining,
visuals, and units packages are distributed separately and loaded by
applications that need them.
