<?php

declare(strict_types=1);

namespace MathPHP\Tests\Facade;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathTest extends TestCase
{
    #[DataProvider('publicResultProvider')]
    public function testPublicResultsHaveTheSpecifiedExactNumericType(
        string $expression,
        int|float $expected,
        string $expectedType,
    ): void {
        $actual = Math::evaluate($expression);

        self::assertSame($expectedType, \get_debug_type($actual));
        if (\is_float($expected)) {
            self::assertEqualsWithDelta($expected, $actual, 1.0E-12);
        } else {
            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{string, int|float, 'int'|'float'}>
     */
    public static function publicResultProvider(): iterable
    {
        yield 'integer literal' => ['42', 42, 'int'];
        yield 'decimal literal' => ['42.0', 42.0, 'float'];
        yield 'integer arithmetic' => ['2 + 3 * 4', 14, 'int'];
        yield 'division is always float' => ['6 / 3', 2.0, 'float'];
        yield 'constant is float' => ['pi', \M_PI, 'float'];
        yield 'tau constant is float' => ['tau', 2 * \M_PI, 'float'];
        yield 'phi constant is float' => ['phi', (1 + \sqrt(5)) / 2, 'float'];
        yield 'unicode multiplication and pi' => ['2 × π', 2 * \M_PI, 'float'];
        yield 'unicode division' => ['6 ÷ 2', 3.0, 'float'];
        yield 'unicode minus' => ['−2 + 5', 3, 'int'];
        yield 'superscript square' => ['2²', 4, 'int'];
        yield 'multi-digit superscript power' => ['2¹⁰', 1024, 'int'];
        yield 'negative superscript power' => ['2⁻²', 0.25, 'float'];
        yield 'unicode square root' => ['√16', 4.0, 'float'];
        yield 'base two logarithm' => ['log2(8)', 3.0, 'float'];
        yield 'atan2 geometry' => ['atan2(1, 1)', \M_PI / 4, 'float'];
        yield 'arithmetic mean' => ['mean(1, 2, 6)', 3.0, 'float'];
        yield 'geometric mean' => ['geometricmean(1, 4, 16)', 4.0, 'float'];
        yield 'harmonic mean' => ['harmonicmean(1, 2, 4)', 12 / 7, 'float'];
        yield 'root mean square' => ['rms(3, 4)', \sqrt(12.5), 'float'];
        yield 'median' => ['median(9, 1, 5, 3)', 4.0, 'float'];
        yield 'quantile' => ['quantile(0.25, 1, 2, 10, 20)', 1.75, 'float'];
        yield 'interquartile range' => ['iqr(1, 2, 3, 4)', 1.5, 'float'];
        yield 'median absolute deviation' => ['mad(1, 2, 2, 4, 100)', 1.0, 'float'];
        yield 'central moment' => ['centralmoment(3, 1, 2, 4)', 20 / 27, 'float'];
        yield 'population skewness' => ['skewness(1, 2, 4)', 10 / (7 * \sqrt(14)), 'float'];
        yield 'population kurtosis' => ['kurtosis(-1, 0, 1)', 1.5, 'float'];
        yield 'population excess kurtosis' => ['excesskurtosis(-1, 0, 1)', -1.5, 'float'];
        yield 'sample-adjusted skewness' => ['sampleskewness(1, 2, 4)', \sqrt(6) * 10 / (7 * \sqrt(14)), 'float'];
        yield 'sample-adjusted excess kurtosis' => ['sampleexcesskurtosis(1, 2, 3, 4)', -1.2, 'float'];
        yield 'population variance' => ['variance(1, 2, 3)', 2 / 3, 'float'];
        yield 'population covariance' => ['covariance(1, 2, 2, 4, 3, 6)', 4 / 3, 'float'];
        yield 'sample covariance' => ['samplecovariance(1, 2, 2, 4, 3, 6)', 2.0, 'float'];
        yield 'Pearson correlation' => ['correlation(1, 2, 2, 4, 3, 6)', 1.0, 'float'];
        yield 'weighted covariance' => ['weightedcovariance(1, 2, 1, 2, 4, 3, 3, 6, 2)', 17 / 18, 'float'];
        yield 'weighted correlation' => ['weightedcorrelation(1, 2, 1, 2, 4, 3, 3, 6, 2)', 1.0, 'float'];
        yield 'population standard deviation' => ['stddev(1, 2, 3)', \sqrt(2 / 3), 'float'];
        yield 'sample variance' => ['samplevariance(1, 2, 3)', 1.0, 'float'];
        yield 'sample standard deviation' => ['samplestddev(1, 2, 3)', 1.0, 'float'];
        yield 'weighted arithmetic mean' => ['weightedmean(10, 1, 20, 3)', 17.5, 'float'];
        yield 'weighted geometric mean' => ['weightedgeometricmean(1, 1, 4, 3)', \pow(4.0, 0.75), 'float'];
        yield 'weighted harmonic mean' => ['weightedharmonicmean(1, 1, 4, 3)', 16 / 7, 'float'];
        yield 'weighted root mean square' => ['weightedrms(3, 1, 4, 3)', \sqrt(14.25), 'float'];
        yield 'integer gcd' => ['gcd(84, 30)', 6, 'int'];
        yield 'factorial function' => ['factorial(5)', 120, 'int'];
        yield 'log factorial' => ['logfactorial(5)', \log(120.0), 'float'];
        yield 'harmonic number' => ['harmonicnumber(3, 2)', 49 / 36, 'float'];
        yield 'exponential relative' => ['exprel(1)', \expm1(1.0), 'float'];
        yield 'log one plus minus x' => ['log1pmx(1e-12)', -5.0e-25, 'float'];
        yield 'log one minus exponential' => ['log1mexp(-1e-12)', \log(1.0e-12), 'float'];
        yield 'softplus' => ['softplus(0)', \log(2.0), 'float'];
        yield 'logit' => ['logit(0.2)', \log(0.25), 'float'];
        yield 'normal log density' => ['normallogpdf(10)', -50.0 - 0.5 * \log(2.0 * \M_PI), 'float'];
        yield 'integer partition count' => ['partition(10)', 42, 'int'];
        yield 'derangements' => ['derangement(5)', 44, 'int'];
        yield 'Stirling number of the second kind' => ['stirling2(5, 2)', 15, 'int'];
        yield 'Fibonacci number' => ['fibonacci(10)', 55, 'int'];
        yield 'Lucas number' => ['lucas(5)', 11, 'int'];
        yield 'Pell number' => ['pell(10)', 2378, 'int'];
        yield 'triangular number' => ['triangular(10)', 55, 'int'];
        yield 'tetrahedral number' => ['tetrahedral(10)', 220, 'int'];
        yield 'Euler totient' => ['totient(36)', 12, 'int'];
        yield 'Jordan totient' => ['jordan(12, 2)', 96, 'int'];
        yield 'Dedekind psi' => ['dedekindpsi(12)', 24, 'int'];
        yield 'divisor count' => ['divisorcount(36)', 9, 'int'];
        yield 'divisor sum' => ['divisorsum(36)', 91, 'int'];
        yield 'sum of squared divisors' => ['sigma(12, 2)', 210, 'int'];
        yield 'prime indicator' => ['isprime(97)', 1, 'int'];
        yield 'prime-power indicator' => ['isprimepower(625)', 1, 'int'];
        yield 'semiprime indicator' => ['issemiprime(49)', 1, 'int'];
        yield 'powerful-number indicator' => ['ispowerful(72)', 1, 'int'];
        yield 'prime-counting function' => ['primecount(100)', 25, 'int'];
        yield 'logarithmic integral' => ['logintegral(2)', 1.0451637801174928, 'float'];
        yield 'composite indicator' => ['iscomposite(91)', 1, 'int'];
        yield 'square-free indicator' => ['issquarefree(30)', 1, 'int'];
        yield 'Mobius function' => ['mobius(30)', -1, 'int'];
        yield 'Liouville function' => ['liouville(36)', 1, 'int'];
        yield 'distinct prime-factor count' => ['omega(72)', 2, 'int'];
        yield 'prime-factor multiplicity' => ['bigomega(72)', 5, 'int'];
        yield 'square-free kernel' => ['radical(72)', 6, 'int'];
        yield 'Carmichael lambda' => ['carmichael(72)', 6, 'int'];
        yield 'aliquot sum' => ['aliquotsum(12)', 16, 'int'];
        yield 'perfect number classification' => ['isperfect(28)', 1, 'int'];
        yield 'abundant number classification' => ['isabundant(12)', 1, 'int'];
        yield 'deficient number classification' => ['isdeficient(10)', 1, 'int'];
        yield 'von Mangoldt function' => ['vonmangoldt(625)', \log(5.0), 'float'];
        yield 'Bernoulli number' => ['bernoulli(4)', -1.0 / 30.0, 'float'];
        yield 'Bernoulli polynomial' => ['bernoullipoly(2, 0.5)', -1.0 / 12.0, 'float'];
        yield 'Legendre polynomial' => ['legendrepoly(3, 2)', 17.0, 'float'];
        yield 'Chebyshev first-kind polynomial' => ['chebyshevt(3, 0.5)', -1.0, 'float'];
        yield 'Chebyshev second-kind polynomial' => ['chebyshevu(2, 0.5)', 0.0, 'float'];
        yield 'physicists Hermite polynomial' => ['hermite(3, 1)', -4.0, 'float'];
        yield 'probabilists Hermite polynomial' => ['hermiteprobabilist(3, 2)', 2.0, 'float'];
        yield 'Laguerre polynomial' => ['laguerre(3, 1)', -2.0 / 3.0, 'float'];
        yield 'associated Laguerre polynomial' => ['laguerreassoc(2, 1, 2)', -1.0, 'float'];
        yield 'Jacobi polynomial' => ['jacobipoly(2, 0, 0, 2)', 5.5, 'float'];
        yield 'binomial coefficient' => ['binomial(5, 2)', 10, 'int'];
        yield 'permutation count' => ['permutation(5, 2)', 20, 'int'];
        yield 'multinomial coefficient' => ['multinomial(2, 3, 4)', 1260, 'int'];
        yield 'Catalan number' => ['catalan(10)', 16796, 'int'];
        yield 'double factorial' => ['doublefactorial(9)', 945, 'int'];
        yield 'rising factorial' => ['risingfactorial(3, 4)', 360, 'int'];
        yield 'Pochhammer symbol' => ['pochhammer(2.5, 3)', 39.375, 'float'];
        yield 'falling factorial' => ['fallingfactorial(5, 3)', 60, 'int'];
        yield 'error function' => ['erf(0)', 0.0, 'float'];
        yield 'normal density' => ['normalpdf(0)', 1.0 / \sqrt(2.0 * \M_PI), 'float'];
        yield 'normal cumulative distribution alias' => ['normcdf(0)', 0.5, 'float'];
        yield 'normal inverse cumulative distribution' => ['normalinv(0.5)', 0.0, 'float'];
        yield 'Student t density alias' => ['tpdf(0, 1)', 1.0 / \M_PI, 'float'];
        yield 'Student t cumulative distribution' => ['studenttcdf(1, 1)', 0.75, 'float'];
        yield 'Student t inverse cumulative distribution' => ['tinv(0.75, 1)', 1.0, 'float'];
        yield 'binomial probability mass' => ['binomialpmf(5, 10, 0.5)', 0.24609375, 'float'];
        yield 'binomial cumulative distribution' => ['binomialcdf(4, 10, 0.5)', 0.376953125, 'float'];
        yield 'binomial inverse cumulative distribution' => ['binomialinv(0.5, 10, 0.5)', 5, 'int'];
        yield 'Poisson probability mass' => ['poissonpmf(2, 3)', 0.22404180765538775, 'float'];
        yield 'Poisson cumulative distribution alias' => ['poicdf(2, 3)', 0.42319008112684364, 'float'];
        yield 'Poisson inverse cumulative distribution' => ['poissoninv(0.95, 3)', 6, 'int'];
        yield 'negative binomial probability mass' => ['negativebinomialpmf(1, 2, 0.5)', 0.25, 'float'];
        yield 'negative binomial cumulative distribution' => ['negativebinomialcdf(1, 2, 0.5)', 0.5, 'float'];
        yield 'negative binomial inverse cumulative distribution' => ['negativebinomialinv(0.75, 1, 0.5)', 1, 'int'];
        yield 'hypergeometric probability mass' => ['hypergeometricpmf(1, 10, 4, 3)', 0.5, 'float'];
        yield 'hypergeometric cumulative distribution' => ['hypergeomcdf(1, 10, 4, 3)', 2.0 / 3.0, 'float'];
        yield 'hypergeometric inverse cumulative distribution' => ['hypergeometricinv(0.5, 10, 4, 3)', 1, 'int'];
        yield 'chi-square density' => ['chisquarepdf(2, 2)', 0.5 * exp(-1.0), 'float'];
        yield 'chi-square cumulative distribution' => ['chicdf(2, 2)', 1.0 - exp(-1.0), 'float'];
        yield 'chi-square inverse cumulative distribution' => ['chisquareinv(1 - exp(-2.5), 2)', 5.0, 'float'];
        yield 'exponential density' => ['exponentialpdf(2, 0.5)', 0.5 * exp(-1.0), 'float'];
        yield 'exponential cumulative distribution' => ['expcdf(2, 0.5)', 1.0 - exp(-1.0), 'float'];
        yield 'exponential inverse cumulative distribution' => ['exponentialinv(1 - exp(-1), 0.5)', 2.0, 'float'];
        yield 'uniform density' => ['uniformpdf(0.5, 0, 2)', 0.5, 'float'];
        yield 'uniform cumulative distribution' => ['uniformcdf(0.5, 0, 2)', 0.25, 'float'];
        yield 'uniform inverse cumulative distribution' => ['uniforminv(0.25, 0, 2)', 0.5, 'float'];
        yield 'Weibull density' => ['weibullpdf(2, 1, 3)', exp(-2.0 / 3.0) / 3.0, 'float'];
        yield 'Weibull cumulative distribution' => ['weibullcdf(2, 1, 3)', 1.0 - exp(-2.0 / 3.0), 'float'];
        yield 'Weibull inverse cumulative distribution' => ['weibullinv(1 - exp(-1), 1, 3)', 3.0, 'float'];
        yield 'log-normal density' => ['lognormalpdf(1, 0, 1)', 1.0 / sqrt(2.0 * M_PI), 'float'];
        yield 'log-normal cumulative distribution' => ['lognormalcdf(1, 0, 1)', 0.5, 'float'];
        yield 'log-normal inverse cumulative distribution' => ['lognormalinv(0.5, 0, 1)', 1.0, 'float'];
        yield 'logistic density' => ['logisticpdf(0, 0, 1)', 0.25, 'float'];
        yield 'logistic cumulative distribution' => ['logisticcdf(0, 0, 1)', 0.5, 'float'];
        yield 'logistic inverse cumulative distribution' => ['logisticinv(0.75, 0, 1)', log(3.0), 'float'];
        yield 'Laplace density' => ['laplacepdf(0, 0, 1)', 0.5, 'float'];
        yield 'Laplace cumulative distribution' => ['laplacecdf(-1, 0, 1)', 0.5 * exp(-1.0), 'float'];
        yield 'Laplace inverse cumulative distribution' => ['laplaceinv(0.75, 0, 1)', log(2.0), 'float'];
        yield 'F distribution density' => ['fpdf(1, 2, 2)', 0.25, 'float'];
        yield 'F distribution cumulative distribution' => ['fcdf(1, 2, 2)', 0.5, 'float'];
        yield 'F distribution inverse cumulative distribution' => ['finv(0.5, 2, 2)', 1.0, 'float'];
        yield 'beta distribution density' => ['betapdf(0.5, 1, 1)', 1.0, 'float'];
        yield 'beta distribution cumulative distribution' => ['betacdf(0.5, 1, 1)', 0.5, 'float'];
        yield 'beta distribution inverse cumulative distribution' => ['betainv(0.5, 2, 2)', 0.5, 'float'];
        yield 'gamma distribution density' => ['gammapdf(2, 1, 2)', 0.5 * exp(-1.0), 'float'];
        yield 'gamma distribution cumulative distribution' => ['gammacdf(2, 1, 2)', 1.0 - exp(-1.0), 'float'];
        yield 'gamma distribution inverse cumulative distribution' => ['gammainv(0.5, 1, 2)', 2.0 * log(2.0), 'float'];
        yield 'Cauchy density' => ['cauchypdf(1, 0, 1)', 1.0 / (2.0 * M_PI), 'float'];
        yield 'Cauchy cumulative distribution' => ['cauchycdf(1, 0, 1)', 0.75, 'float'];
        yield 'Cauchy inverse cumulative distribution' => ['cauchyinv(0.75, 0, 1)', 1.0, 'float'];
        yield 'Pareto density' => ['paretopdf(2, 2, 1)', 0.25, 'float'];
        yield 'Pareto cumulative distribution' => ['paretocdf(2, 2, 1)', 0.75, 'float'];
        yield 'Pareto inverse cumulative distribution' => ['paretoinv(0.75, 2, 1)', 2.0, 'float'];
        yield 'Rayleigh density' => ['rayleighpdf(1, 1)', exp(-0.5), 'float'];
        yield 'Rayleigh cumulative distribution' => ['rayleighcdf(1, 1)', 1.0 - exp(-0.5), 'float'];
        yield 'Rayleigh inverse cumulative distribution' => ['rayleighinv(1 - exp(-0.5), 1)', 1.0, 'float'];
        yield 'geometric probability mass' => ['geometricpmf(2, 0.5)', 0.125, 'float'];
        yield 'geometric cumulative distribution' => ['geometriccdf(1, 0.5)', 0.75, 'float'];
        yield 'geometric inverse cumulative distribution' => ['geometricinv(0.75, 0.5)', 1, 'int'];
        yield 'triangular density' => ['triangularpdf(0.5, 0, 0.5, 1)', 2.0, 'float'];
        yield 'triangular cumulative distribution' => ['triangularcdf(0.5, 0, 0.5, 1)', 0.5, 'float'];
        yield 'triangular inverse cumulative distribution' => ['triangularinv(0.5, 0, 0.5, 1)', 0.5, 'float'];
        yield 'gamma function' => ['gamma(5)', 24.0, 'float'];
    }

    public function testGreekVariableAliasesResolveAgainstPortableNames(): void
    {
        self::assertSame(13, Math::evaluate('α² + λ', ['alpha' => 2, 'lambda' => 9]));
    }

    public function testSubscriptVariableAliasesResolveAgainstPortableNames(): void
    {
        self::assertSame(12, Math::evaluate('x₂ + α₃', ['x2' => 5, 'alpha3' => 7]));
    }

    public function testHostMinimumIntegerCanBeWrittenAsASignedLiteral(): void
    {
        $magnitude = \substr((string) \PHP_INT_MIN, 1);

        self::assertSame(\PHP_INT_MIN, Math::evaluate('-' . $magnitude));
        self::assertSame(\PHP_INT_MIN + 1, Math::evaluate('- ' . $magnitude . ' + 1'));
        self::assertSame(\PHP_INT_MIN, Math::evaluate('(-' . $magnitude . ')'));
    }

    public function testPositiveMagnitudeOfHostMinimumIsStillRejected(): void
    {
        try {
            Math::evaluate(\substr((string) \PHP_INT_MIN, 1));
            self::fail('Expected the positive minimum magnitude to be rejected.');
        } catch (\MathPHP\Exception\LexicalException $exception) {
            self::assertSame('lex.number_out_of_range', $exception->errorCode());
        }
    }

    public function testArcCotangentPreservesSmallPositiveTailForLargeArguments(): void
    {
        $actual = Math::evaluate('acot(1e308)');

        self::assertSame(\atan2(1.0, 1.0e308), $actual);
        self::assertGreaterThan(0.0, $actual);
    }

    public function testInverseHyperbolicCosecantRemainsFiniteForSubnormalInput(): void
    {
        $input = 1.0e-320;
        $actual = Math::evaluate('acsch(1e-320)');

        self::assertEqualsWithDelta(\log(2.0) - \log($input), $actual, 1.0e-12);
    }

    public function testReciprocalHyperbolicFunctionsAcceptSmallNonzeroArguments(): void
    {
        $input = 1.0e-15;

        self::assertSame(1.0 / \sinh($input), Math::evaluate('csch(1e-15)'));
        self::assertSame(\cosh($input) / \sinh($input), Math::evaluate('coth(1e-15)'));
        self::assertEqualsWithDelta(\asinh(1.0 / $input), Math::evaluate('acsch(1e-15)'), 1.0e-14);
    }

    public function testGammaAcceptsFiniteValuesImmediatelyBesideNegativeIntegerPoles(): void
    {
        $nearPoleCases = [
            [-0.9999999999999999, 1.0, -1.0],
            [-1.0000000000000002, 1.0, -1.0],
            [-1.9999999999999998, 2.0, 0.5],
        ];
        foreach ($nearPoleCases as [$argument, $pole, $residue]) {
            $gamma = Math::evaluate('gamma(x)', ['x' => $argument]);

            self::assertTrue(\is_finite($gamma));
            self::assertEqualsWithDelta($residue, $gamma * ($argument + $pole), 0.1);
        }
    }

    public function testRegularizedBetaAndGammaUpperTailsPreserveSmallProbabilities(): void
    {
        $betaX = 0.99999999;
        $betaDelta = 1.0 - $betaX;
        $betaTail = Math::evaluate('betaincc(2, 3, x)', ['x' => $betaX]);
        $expectedBetaTail = 4.0 * $betaDelta ** 3 - 3.0 * $betaDelta ** 4;

        self::assertGreaterThan(0.0, $betaTail);
        self::assertEqualsWithDelta($expectedBetaTail, $betaTail, $expectedBetaTail * 1.0e-8);

        $betaQuantile = Math::evaluate('betainccinv(2, 3, 1e-20)');
        self::assertLessThan(1.0, $betaQuantile);
        self::assertEqualsWithDelta(
            1.0e-20,
            Math::evaluate('betaincc(2, 3, x)', ['x' => $betaQuantile]),
            1.0e-28,
        );

        $gammaQuantile = Math::evaluate('gammainccinv(1, 1e-20)');
        self::assertEqualsWithDelta(-\log(1.0e-20), $gammaQuantile, 1.0e-12);
        self::assertEqualsWithDelta(
            1.0e-20,
            Math::evaluate('gammaincc(1, x)', ['x' => $gammaQuantile]),
            1.0e-32,
        );

        $subnormalTail = 5.0e-324;
        $subnormalQuantile = Math::evaluate('gammainccinv(1, q)', ['q' => $subnormalTail]);
        self::assertEqualsWithDelta(-\log($subnormalTail), $subnormalQuantile, 1.0e-12);
        self::assertSame(
            $subnormalTail,
            Math::evaluate('gammaincc(1, x)', ['x' => $subnormalQuantile]),
        );
    }

    public function testSmallLowerTailQuantilesForShapesBelowOne(): void
    {
        $tail = 1.0e-20;
        $gammaQuantile = Math::evaluate('gammaincinv(a, q)', ['a' => 0.2, 'q' => $tail]);
        $gammaRoundTrip = Math::evaluate('gammainc(a, x)', ['a' => 0.2, 'x' => $gammaQuantile]);
        self::assertGreaterThan(0.0, $gammaQuantile);
        self::assertEqualsWithDelta(1.0, $gammaRoundTrip / $tail, 1.0e-6);

        $betaQuantile = Math::evaluate('betaincinv(a, b, q)', [
            'a' => 0.2,
            'b' => 0.3,
            'q' => $tail,
        ]);
        $betaRoundTrip = Math::evaluate('betainc(a, b, x)', [
            'a' => 0.2,
            'b' => 0.3,
            'x' => $betaQuantile,
        ]);
        self::assertGreaterThan(0.0, $betaQuantile);
        self::assertEqualsWithDelta(1.0, $betaRoundTrip / $tail, 1.0e-6);
    }

    public function testGammaAndBetaRemainFiniteAtTheirMaximumSupportedShapes(): void
    {
        $expectedLogGamma = 0.0;
        for ($factor = 2; $factor <= 170; ++$factor) {
            $expectedLogGamma += \log($factor);
        }

        $gamma = Math::evaluate('gamma(171)');
        $logGamma = Math::evaluate('lgamma(171)');
        self::assertTrue(\is_finite($gamma));
        self::assertEqualsWithDelta($expectedLogGamma, $logGamma, 1.0e-12);
        self::assertEqualsWithDelta(1.0, $gamma / \exp($expectedLogGamma), 1.0e-12);

        $lowerGamma = Math::evaluate('gammainc(171, 171)');
        $upperGamma = Math::evaluate('gammaincc(171, 171)');
        self::assertEqualsWithDelta(1.0, $lowerGamma + $upperGamma, 1.0e-14);

        $lowerBeta = Math::evaluate('betainc(80, 90, 0.5)');
        $upperBeta = Math::evaluate('betaincc(80, 90, 0.5)');
        self::assertTrue(\is_finite($lowerBeta));
        self::assertTrue(\is_finite($upperBeta));
        self::assertEqualsWithDelta(1.0, $lowerBeta + $upperBeta, 1.0e-14);
    }

    public function testLogGammaAndLogBetaAvoidIntermediateGammaOverflow(): void
    {
        $expectedLogGamma = 0.0;
        for ($factor = 2; $factor <= 999; ++$factor) {
            $expectedLogGamma += \log($factor);
        }
        self::assertEqualsWithDelta($expectedLogGamma, Math::evaluate('lgamma(1000)'), 1.0e-10);

        $logGamma200 = 0.0;
        $logGamma400 = 0.0;
        for ($factor = 2; $factor <= 199; ++$factor) {
            $logGamma200 += \log($factor);
        }
        for ($factor = 2; $factor <= 399; ++$factor) {
            $logGamma400 += \log($factor);
        }
        $expectedLogBeta = 2.0 * $logGamma200 - $logGamma400;
        self::assertEqualsWithDelta($expectedLogBeta, Math::evaluate('logbeta(200, 200)'), 1.0e-10);
    }

    public function testGammaUsesTheFloatingPointRangeInsteadOfAnIntegerCutoff(): void
    {
        $positiveLogGamma = Math::evaluate('lgamma(171.62)');
        $positiveGamma = Math::evaluate('gamma(171.62)');
        self::assertTrue(\is_finite($positiveGamma));
        self::assertEqualsWithDelta(\exp($positiveLogGamma), $positiveGamma, 1.0e-14 * $positiveGamma);

        $negativeLogGamma = Math::evaluate('lgamma(-172.5)');
        $negativeGamma = Math::evaluate('gamma(-172.5)');
        self::assertLessThan(0.0, $negativeGamma);
        self::assertEqualsWithDelta(-\exp($negativeLogGamma), $negativeGamma, 1.0e-14 * \abs($negativeGamma));

        $this->expectException(EvaluationException::class);
        Math::evaluate('gamma(172)');
    }

    public function testRegularizedGammaAndBetaSupportShapesBeyondGammaOverflow(): void
    {
        foreach ([200.0, 1_000.0, 10_000.0] as $shape) {
            $lower = Math::evaluate('gammainc(a, a)', ['a' => $shape]);
            $upper = Math::evaluate('gammaincc(a, a)', ['a' => $shape]);
            self::assertEqualsWithDelta(1.0, $lower + $upper, 1.0e-12);

            $median = Math::evaluate('gammaincinv(a, 0.5)', ['a' => $shape]);
            self::assertEqualsWithDelta(0.5, Math::evaluate('gammainc(a, x)', [
                'a' => $shape,
                'x' => $median,
            ]), 1.0e-10);
        }

        foreach ([200.0, 1_000.0] as $shape) {
            $lower = Math::evaluate('betainc(a, a, 0.5)', ['a' => $shape]);
            $upper = Math::evaluate('betaincc(a, a, 0.5)', ['a' => $shape]);
            self::assertEqualsWithDelta(0.5, $lower, 1.0e-10);
            self::assertEqualsWithDelta(0.5, $upper, 1.0e-10);
            self::assertEqualsWithDelta(0.5, Math::evaluate('betaincinv(a, a, 0.5)', [
                'a' => $shape,
            ]), 1.0e-12);
        }
    }

    public function testHighOrderBesselValuesUseOrderAwareNumerics(): void
    {
        self::assertEqualsWithDelta(
            0.104705558063432,
            Math::evaluate('besselj(10, 41)'),
            1.0e-9,
        );
        self::assertEqualsWithDelta(
            2.2873023510901e-29,
            Math::evaluate('besselj(100, 41)'),
            1.0e-37,
        );
        self::assertEqualsWithDelta(
            -1.5257999576579e26,
            Math::evaluate('bessely(100, 41)'),
            1.0e18,
        );

        $order = 100;
        $argument = 41.0;
        $modifiedIRecurrence = Math::evaluate('besseli(n - 1, x) - besseli(n + 1, x)', [
            'n' => $order,
            'x' => $argument,
        ]);
        $modifiedIExpected = 2.0 * $order / $argument * Math::evaluate('besseli(n, x)', [
            'n' => $order,
            'x' => $argument,
        ]);
        self::assertEqualsWithDelta($modifiedIExpected, $modifiedIRecurrence, 1.0e-38);

        $modifiedKRecurrence = Math::evaluate('besselk(n + 1, x) - besselk(n - 1, x)', [
            'n' => $order,
            'x' => $argument,
        ]);
        $modifiedKExpected = 2.0 * $order / $argument * Math::evaluate('besselk(n, x)', [
            'n' => $order,
            'x' => $argument,
        ]);
        self::assertEqualsWithDelta($modifiedKExpected, $modifiedKRecurrence, 1.0e10);
    }

    public function testVariablesAndConstantsAreResolvedCaseSensitively(): void
    {
        $result = Math::evaluate(
            'radius * Pi + pi + E + e',
            [
                'radius' => 2,
                'Pi' => 3,
                'E' => 4.0,
            ],
        );

        self::assertEqualsWithDelta(
            (2 * 3) + \M_PI + 4.0 + \M_E,
            $result,
            1.0E-12,
        );
    }

    /**
     * @param array<string, int|float> $variables
     */
    #[DataProvider('invalidFacadeVariableProvider')]
    public function testInvalidHostVariablesAreRejectedBeforeExpressionProcessing(
        array $variables,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Math::evaluate('@', $variables);
    }

    /**
     * @return iterable<string, array{array<string, int|float>, string}>
     */
    public static function invalidFacadeVariableProvider(): iterable
    {
        yield 'invalid name' => [
            ['not-valid' => 1],
            'Every variable name must be a valid ASCII identifier.',
        ];
        yield 'reserved pi' => [
            ['pi' => 3],
            'The constant name "pi" is reserved and cannot be overridden.',
        ];
        yield 'reserved e' => [
            ['e' => 3],
            'The constant name "e" is reserved and cannot be overridden.',
        ];
        yield 'positive infinity' => [
            ['value' => \INF],
            'Variable "value" must have a finite integer or float value.',
        ];
        yield 'negative infinity' => [
            ['value' => -\INF],
            'Variable "value" must have a finite integer or float value.',
        ];
        yield 'NaN' => [
            ['value' => \NAN],
            'Variable "value" must have a finite integer or float value.',
        ];
    }

    public function testUnknownVariableHasTheIdentifierSpan(): void
    {
        try {
            Math::evaluate('1 + missing * 2');
            self::fail('An unknown variable should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
            self::assertSame(4, $error->span()->start);
            self::assertSame(11, $error->span()->end);
            self::assertSame(
                'Unknown variable "missing" at position 4',
                $error->getMessage(),
            );
        }
    }

    #[DataProvider('unknownFunctionProvider')]
    public function testOnlyExplicitCaseSensitiveFunctionsCanBeCalled(
        string $expression,
        int $spanStart,
        int $spanEnd,
    ): void {
        try {
            Math::evaluate($expression);
            self::fail('An unregistered function should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame($spanStart, $error->span()->start);
            self::assertSame($spanEnd, $error->span()->end);
            self::assertStringContainsString(
                'Unknown function',
                $error->getMessage(),
            );
            self::assertStringEndsWith(
                \sprintf('at position %d', $spanStart),
                $error->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function unknownFunctionProvider(): iterable
    {
        yield 'ordinary unknown function' => ['1 + mystery(2)', 4, 11];
        yield 'built-in with wrong case' => ['Sqrt(4)', 0, 4];
        yield 'PHP function is not dynamically dispatched' => [
            'phpversion()',
            0,
            10,
        ];
        yield 'dangerous name is not dynamically dispatched' => [
            'system(1)',
            0,
            6,
        ];
    }

    public function testCustomFunctionReceivesEvaluatedArgumentsInOrder(): void
    {
        $received = null;
        $weighted = new FunctionDefinition(
            'weighted',
            3,
            3,
            static function (array $arguments) use (&$received): float {
                $received = $arguments;

                return $arguments[0] + ($arguments[1] * $arguments[2]);
            },
        );
        $options = self::optionsWith($weighted);

        $result = Math::evaluate(
            'weighted(x + 1, 2.5, 4)',
            ['x' => 3],
            $options,
        );

        self::assertSame([4, 2.5, 4], $received);
        self::assertSame(14.0, $result);
    }

    public function testCustomFunctionResultsPreserveIntegerAndFloatTypes(): void
    {
        $integer = new FunctionDefinition(
            'integer',
            0,
            0,
            static fn (array $arguments): int => 7 + \count($arguments),
        );
        $decimal = new FunctionDefinition(
            'decimal',
            0,
            0,
            static fn (array $arguments): float => 7.0 + \count($arguments),
        );
        $registry = FunctionRegistry::defaults()
            ->with($integer)
            ->with($decimal);
        $options = new EvaluationOptions(functions: $registry);

        self::assertSame(7, Math::evaluate('integer()', options: $options));
        self::assertSame(7.0, Math::evaluate('decimal()', options: $options));
    }

    public function testFunctionArityIsCheckedBeforeAnyArgumentsAreEvaluated(): void
    {
        try {
            Math::evaluate('sqrt(missing, 1 / 0)');
            self::fail('Wrong arity should fail before argument evaluation.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.arity', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(
                \strlen('sqrt(missing, 1 / 0)'),
                $error->span()->end,
            );
            self::assertSame(
                'Function "sqrt" expects exactly 1 argument but received 2 at position 0',
                $error->getMessage(),
            );
        }
    }

    public function testUnknownFunctionIsResolvedBeforeItsArguments(): void
    {
        try {
            Math::evaluate('missing_function(unknown_variable, 1 / 0)');
            self::fail('Function resolution should precede argument evaluation.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen('missing_function'), $error->span()->end);
        }
    }

    public function testArgumentsAreEvaluatedFromLeftToRightBeforeInvocation(): void
    {
        $order = [];
        $first = new FunctionDefinition(
            'first',
            0,
            0,
            static function (array $arguments) use (&$order): int {
                self::assertSame([], $arguments);
                $order[] = 'first';

                return 1;
            },
        );
        $second = new FunctionDefinition(
            'second',
            0,
            0,
            static function (array $arguments) use (&$order): int {
                self::assertSame([], $arguments);
                $order[] = 'second';

                return 2;
            },
        );
        $combine = new FunctionDefinition(
            'combine',
            2,
            2,
            static function (array $arguments) use (&$order): int|float {
                $order[] = 'combine';

                return $arguments[0] + $arguments[1];
            },
        );
        $registry = FunctionRegistry::defaults()
            ->with($first)
            ->with($second)
            ->with($combine);

        $result = Math::evaluate(
            'combine(first(), second())',
            options: new EvaluationOptions(functions: $registry),
        );

        self::assertSame(3, $result);
        self::assertSame(['first', 'second', 'combine'], $order);
    }

    public function testArgumentFailurePreventsCallbackInvocation(): void
    {
        $invoked = false;
        $combine = new FunctionDefinition(
            'combine',
            2,
            2,
            static function (array $arguments) use (&$invoked): int|float {
                $invoked = true;

                return $arguments[0] + $arguments[1];
            },
        );

        try {
            Math::evaluate(
                'combine(missing, 1 / 0)',
                options: self::optionsWith($combine),
            );
            self::fail('The first argument should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
            self::assertSame(8, $error->span()->start);
            self::assertSame(15, $error->span()->end);
            self::assertFalse($invoked);
        }
    }

    #[DataProvider('customThrowableProvider')]
    public function testCustomCallbackErrorsAreWrappedAtTheCompleteCallSpan(
        \Throwable $failure,
    ): void {
        $function = new FunctionDefinition(
            'fail',
            1,
            1,
            static function (array $arguments) use ($failure): never {
                self::assertSame([3], $arguments);

                throw $failure;
            },
        );
        $expression = '1 + fail(1 + 2)';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('The custom callback should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(4, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertSame(
                'Custom function "fail" failed at position 4',
                $error->getMessage(),
            );
            self::assertSame($failure, $error->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function customThrowableProvider(): iterable
    {
        yield 'runtime failure' => [new \RuntimeException('runtime')];
        yield 'domain failure' => [new \DomainException('domain')];
        yield 'overflow failure' => [new \OverflowException('overflow')];
        yield 'type failure' => [new \TypeError('type')];
    }

    public function testRegistryOwnedProvenanceKeepsCustomDomainFailuresCustom(): void
    {
        $failure = new \DomainException('custom domain');
        $spoof = new FunctionDefinition(
            'spoof',
            0,
            0,
            static function (array $arguments) use ($failure): never {
                self::assertSame([], $arguments);

                throw $failure;
            },
        );
        $registry = FunctionRegistry::defaults()->with($spoof);

        self::assertFalse($registry->isBuiltIn('spoof'));

        try {
            Math::evaluate(
                'spoof()',
                options: new EvaluationOptions(functions: $registry),
            );
            self::fail('A custom domain failure must remain custom.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(7, $error->span()->end);
            self::assertSame($failure, $error->getPrevious());
        }
    }

    /**
     * @param class-string<\Throwable> $previousClass
     */
    #[DataProvider('invalidCustomResultProvider')]
    public function testInvalidCustomResultsAreWrapped(
        \Closure $callback,
        string $previousClass,
    ): void {
        $function = new FunctionDefinition('invalid', 0, 0, $callback);
        $expression = 'invalid()';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('An invalid custom result should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertInstanceOf($previousClass, $error->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{
     *     \Closure(list<int|float>): mixed,
     *     class-string<\Throwable>
     * }>
     */
    public static function invalidCustomResultProvider(): iterable
    {
        yield 'string' => [
            static fn (array $arguments): string => (string) \count($arguments),
            \UnexpectedValueException::class,
        ];
        yield 'boolean' => [
            static fn (array $arguments): bool => $arguments === [],
            \UnexpectedValueException::class,
        ];
        yield 'null' => [
            static fn (array $arguments): mixed => $arguments[0] ?? null,
            \UnexpectedValueException::class,
        ];
    }

    #[DataProvider('nonFiniteCustomResultProvider')]
    public function testNonFiniteCustomResultsMapToCustomFunctionFailure(
        float $result,
    ): void {
        $function = new FunctionDefinition(
            'nonfinite',
            0,
            0,
            static fn (array $arguments): float => $result + \count($arguments),
        );
        $expression = 'nonfinite()';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('A non-finite custom result should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertInstanceOf(
                EvaluationException::class,
                $error->getPrevious(),
            );
            self::assertSame(
                'eval.non_finite',
                $error->getPrevious()->errorCode(),
            );
        }
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteCustomResultProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'NaN' => [\NAN];
    }

    /**
     * @param array<string, int|float> $variables
     * @param class-string<\Throwable>|null $previousClass
     */
    #[DataProvider('builtInFailureProvider')]
    public function testBuiltInFailuresMapToStableEvaluationErrors(
        string $expression,
        array $variables,
        string $expectedCode,
        ?string $previousClass,
    ): void {
        try {
            Math::evaluate($expression, $variables);
            self::fail('The built-in function should fail.');
        } catch (EvaluationException $error) {
            self::assertSame($expectedCode, $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            if ($previousClass === null) {
                self::assertNull($error->getPrevious());
            } else {
                self::assertInstanceOf($previousClass, $error->getPrevious());
            }
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, int|float>,
     *     string,
     *     class-string<\Throwable>|null
     * }>
     */
    public static function builtInFailureProvider(): iterable
    {
        yield 'sqrt domain' => [
            'sqrt(-1)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'ln domain' => [
            'ln(0)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'log domain' => [
            'log(10, 1)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'absolute integer overflow' => [
            'abs(value)',
            ['value' => \PHP_INT_MIN],
            'eval.integer_overflow',
            \OverflowException::class,
        ];
        yield 'exponential non-finite output' => [
            'exp(10000)',
            [],
            'eval.non_finite',
            null,
        ];
    }

    public function testNegativeZeroFromVariablesAndCustomFunctionsIsNormalized(): void
    {
        $negativeZero = -0.0;
        $function = new FunctionDefinition(
            'negative_zero',
            0,
            0,
            static fn (array $arguments): float => $negativeZero
                + \count($arguments),
        );

        $variableResult = Math::evaluate('value', ['value' => $negativeZero]);
        $customResult = Math::evaluate(
            'negative_zero()',
            options: self::optionsWith($function),
        );

        self::assertSame(0.0, $variableResult);
        self::assertSame(0.0, $customResult);
        self::assertSame('d:0;', \serialize($variableResult));
        self::assertSame('d:0;', \serialize($customResult));
    }

    public function testSequentialVariableEvaluationsAreIsolated(): void
    {
        self::assertSame(3, Math::evaluate('value + 1', ['value' => 2]));
        self::assertSame(10, Math::evaluate('value + 1', ['value' => 9]));

        try {
            Math::evaluate('value + 1');
            self::fail('A prior variable map must not leak.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
        }
    }

    public function testSequentialAndInterleavedRegistriesAreIsolated(): void
    {
        $first = self::optionsWith(
            new FunctionDefinition(
                'value',
                0,
                0,
                static fn (array $arguments): int => 1 + \count($arguments),
            ),
        );
        $second = self::optionsWith(
            new FunctionDefinition(
                'value',
                0,
                0,
                static fn (array $arguments): int => 2 + \count($arguments),
            ),
        );

        self::assertSame(1, Math::evaluate('value()', options: $first));
        self::assertSame(2, Math::evaluate('value()', options: $second));
        self::assertSame(1, Math::evaluate('value()', options: $first));

        try {
            Math::evaluate('value()');
            self::fail('A custom registry must not leak into default options.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
        }
    }

    public function testNestedEvaluationsDoNotLeakVariablesOrFunctions(): void
    {
        $innerFunction = new FunctionDefinition(
            'inner',
            0,
            0,
            static fn (array $arguments): int => 7 + \count($arguments),
        );
        $innerOptions = self::optionsWith($innerFunction);
        $outerFunction = new FunctionDefinition(
            'outer',
            0,
            0,
            static fn (array $arguments): int|float => Math::evaluate(
                'inner() + value',
                ['value' => 10 + \count($arguments)],
                $innerOptions,
            ),
        );
        $outerOptions = self::optionsWith($outerFunction);

        self::assertSame(
            18,
            Math::evaluate('outer() + value', ['value' => 1], $outerOptions),
        );

        try {
            Math::evaluate('inner() + value', ['value' => 1], $outerOptions);
            self::fail('The nested registry must not leak into its caller.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(5, $error->span()->end);
        }
    }

    public function testAReusedOptionsObjectCarriesNoEvaluationState(): void
    {
        $identity = new FunctionDefinition(
            'identity',
            1,
            1,
            static fn (array $arguments): int|float => $arguments[0],
        );
        $options = self::optionsWith($identity);

        self::assertSame(
            1,
            Math::evaluate('identity(value)', ['value' => 1], $options),
        );
        self::assertSame(
            2.5,
            Math::evaluate('identity(value)', ['value' => 2.5], $options),
        );
    }

    public function testFacadeUsesTheConfiguredExponentLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxExponentMagnitude: 2),
        );

        self::assertSame(4, Math::evaluate('2^2', options: $options));

        try {
            Math::evaluate('2^3', options: $options);
            self::fail('The exponent should exceed the configured limit.');
        } catch (EvaluationException $error) {
            self::assertSame('limit.exponent', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testFacadeUsesTheConfiguredFactorialLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxFactorial: 3),
        );

        self::assertSame(6, Math::evaluate('3!', options: $options));

        try {
            Math::evaluate('4!', options: $options);
            self::fail('The factorial should exceed the configured limit.');
        } catch (EvaluationException $error) {
            self::assertSame('limit.factorial', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testFacadeUsesTheConfiguredExpressionLengthLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxExpressionLength: 1),
        );

        self::assertSame(1, Math::evaluate('1', options: $options));

        try {
            Math::evaluate('12', options: $options);
            self::fail('The expression should exceed the configured limit.');
        } catch (\MathPHP\Exception\LexicalException $error) {
            self::assertSame('limit.expression_length', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testMathFacadeIsFinalAndHasNoMutableStaticProperties(): void
    {
        $reflection = new \ReflectionClass(Math::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC));
    }

    private static function optionsWith(
        FunctionDefinition $definition,
    ): EvaluationOptions {
        return new EvaluationOptions(
            functions: FunctionRegistry::defaults()->with($definition),
        );
    }
}
