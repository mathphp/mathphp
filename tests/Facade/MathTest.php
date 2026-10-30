<?php

declare(strict_types=1);

namespace MathPHP\Tests\Facade;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\FunctionDomainException;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;
use MathPHP\Solver\IntegerPolynomialExtractor;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathTest extends TestCase
{
    public function testIntegratePolynomialAndReverseBounds(): void
    {
        self::assertEqualsWithDelta(8.0 / 3.0, Math::integrate('x^2', 'x', 0.0, 2.0), 1.0E-12);
        self::assertEqualsWithDelta(-8.0 / 3.0, Math::integrate('x^2', 'x', 2.0, 0.0), 1.0E-12);
        self::assertSame(0.0, Math::integrate('x^2', 'x', 1.0, 1.0));
    }

    public function testIntegrateResolvesOscillationAndNarrowPeakAdaptively(): void
    {
        self::assertEqualsWithDelta(
            2.0,
            Math::integrate('sin(x)', 'x', 0.0, \M_PI),
            1.0E-9,
        );
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * 0.01,
            Math::integrate('exp(-((x - 0.5) / 0.01)^2)', 'x', 0.0, 1.0),
            1.0E-8,
        );
    }

    public function testIntegrateRegularizesIntegrableEndpointSingularities(): void
    {
        self::assertEqualsWithDelta(2.0, Math::integrate('1 / sqrt(x)', 'x', 0.0, 1.0), 1.0E-8);
        self::assertEqualsWithDelta(2.0, Math::integrate('1 / sqrt(1 - x)', 'x', 0.0, 1.0), 1.0E-8);
        self::assertEqualsWithDelta(-1.0, Math::integrate('ln(x)', 'x', 0.0, 1.0), 1.0E-8);
        self::assertEqualsWithDelta(10.0, Math::integrate('1 / x^0.9', 'x', 0.0, 1.0), 1.0E-8);
        foreach ([0.9, 0.99, 0.999, 0.9999] as $power) {
            $expression = '1 / x^' . \sprintf('%.4f', $power);
            $expected = 1.0 / (1.0 - $power);
            self::assertEqualsWithDelta($expected, Math::integrate($expression, 'x', 0.0, 1.0), 1.0E-10);
            self::assertEqualsWithDelta($expected, -Math::integrate($expression, 'x', 1.0, 0.0), 1.0E-10);
            self::assertEqualsWithDelta(-$expected, Math::integrate('-(' . $expression . ')', 'x', 0.0, 1.0), 1.0E-10);
            self::assertEqualsWithDelta(
                2.0 * $expected,
                Math::integrate('2 * (' . $expression . ')', 'x', 0.0, 1.0),
                1.0E-10,
            );
        }
        self::assertEqualsWithDelta(
            2.0 * (2.0 ** 0.1 - 1.0) / 0.1,
            Math::integrate('2 / (3 - x)^0.9', 'x', 1.0, 2.0),
            1.0E-12,
        );
    }

    public function testIntegrateHandlesInteriorLinearAlgebraicSingularities(): void
    {
        $root = 0.123456;
        foreach ([0.5, 0.9, 0.99] as $power) {
            $primitive = static fn (float $x): float =>
                ($x < $root ? -1.0 : 1.0)
                * (\abs($x - $root) ** (1.0 - $power))
                / (1.0 - $power);
            $expected = $primitive(1.0) - $primitive(0.0);
            $expression = '1 / abs(x - 0.123456)^' . \sprintf('%.2f', $power);
            $negativePowerExpression = 'abs(x - 0.123456)^(' . \sprintf('%.2f', -$power) . ')';
            $scaledNegativePowerExpression = '3 * ' . $negativePowerExpression;

            self::assertEqualsWithDelta($expected, Math::integrate($expression, 'x', 0.0, 1.0), 1.0E-12);
            self::assertEqualsWithDelta($expected, -Math::integrate($expression, 'x', 1.0, 0.0), 1.0E-12);
            self::assertEqualsWithDelta($expected, Math::integrate($negativePowerExpression, 'x', 0.0, 1.0), 1.0E-12);
            self::assertEqualsWithDelta(
                3.0 * $expected,
                Math::integrate($scaledNegativePowerExpression, 'x', 0.0, 1.0),
                1.0E-12,
            );
        }
    }

    public function testIntegrateFindsLocalizedPeaksBetweenGlobalQuadratureNodes(): void
    {
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * 0.001,
            Math::integrate('exp(-((x - 0.12345) / 0.001)^2)', 'x', 0.0, 1.0),
            1.0E-9,
        );
    }

    public function testIntegrateFindsPeaksNarrowerThanTheInitialQuadratureMesh(): void
    {
        foreach ([1.0E-5, 1.0E-6] as $width) {
            $expression = 'exp(-((x - 0.123456) / ' . \sprintf('%.0e', $width) . ')^2)';
            self::assertEqualsWithDelta(
                \sqrt(\M_PI) * $width,
                Math::integrate($expression, 'x', 0.0, 1.0),
                $width * 1.0E-7,
                $expression,
            );
        }
    }

    public function testIntegrateFindsNarrowPeakNearBoundaryWithParameterizedScale(): void
    {
        $scale = 1.0E-6;
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * $scale,
            Math::integrate(
                'exp(-((x - center) / width)^2)',
                'x',
                0.0,
                1.0,
                ['center' => 1.0E-5, 'width' => $scale],
            ),
            1.0E-10,
        );
    }

    public function testIntegrateFindsNarrowPeakWrittenWithLargePolynomialMultiplier(): void
    {
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * 1.0E-5,
            Math::integrate('exp(-10000000000*(x - 0.123456)^2)', 'x', 0.0, 1.0),
            1.0E-10,
        );
    }

    public function testIntegrateFindsFeaturesScaledInsidePolynomialArguments(): void
    {
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * 1.0E-5,
            Math::integrate('exp(-((x - 0.123456) * 100000)^2)', 'x', 0.0, 1.0),
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            2.0E-5,
            Math::integrate('exp(-abs((x - 0.123456) * 100000))', 'x', 0.0, 1.0),
            1.0E-10,
        );
    }

    public function testIntegrateDerivesFeatureWidthFromDivisorAndRootMultiplicity(): void
    {
        self::assertEqualsWithDelta(
            \sqrt(\M_PI) * 1.0E-5,
            Math::integrate('exp(-((x - 0.123456)^2) / 1e-10)', 'x', 0.0, 1.0),
            1.0E-10,
        );
    }

    public function testIntegrateSupportsImproperInfiniteIntervals(): void
    {
        self::assertEqualsWithDelta(1.0, Math::integrate('exp(-x)', 'x', 0.0, \INF), 1.0E-8);
        self::assertEqualsWithDelta(1.0, Math::integrate('exp(x)', 'x', -\INF, 0.0), 1.0E-8);
        self::assertEqualsWithDelta(\sqrt(\M_PI), Math::integrate('exp(-x^2)', 'x', -\INF, \INF), 1.0E-8);
        self::assertEqualsWithDelta(\M_PI, Math::integrate('1 / (1 + x^2)', 'x', -\INF, \INF), 1.0E-8);
        self::assertEqualsWithDelta(-1.0, Math::integrate('exp(-x)', 'x', \INF, 0.0), 1.0E-8);
        self::assertEqualsWithDelta(1.0, Math::integrate('1 / x^2', 'x', 1.0, \INF), 1.0E-8);
    }

    public function testIntegrateSeedsNarrowFeaturesOnImproperIntervals(): void
    {
        $expected = \sqrt(\M_PI) * 1.0E-6;
        self::assertEqualsWithDelta(
            $expected,
            Math::integrate('exp(-((x - 10) / 1e-6)^2)', 'x', -\INF, \INF),
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            $expected,
            Math::integrate('exp(-((x - 10) / 1e-6)^2)', 'x', 0.0, \INF),
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            $expected,
            Math::integrate('exp(-((x + 10) / 1e-6)^2)', 'x', -\INF, 0.0),
            1.0E-10,
        );
    }

    public function testIntegrateReportsUnresolvedConditionalTailAsDomainFailure(): void
    {
        $this->expectException(\DomainException::class);
        Math::integrate('sin(x) / x', 'x', 0.0, \INF, absoluteTolerance: 1.0E-8);
    }

    public function testIntegrateUsesParametersAndCustomFunctions(): void
    {
        $functions = FunctionRegistry::defaults()->with(
            new FunctionDefinition('twice', 1, 1, static fn (array $arguments): float => 2.0 * $arguments[0]),
        );
        $options = new EvaluationOptions(functions: $functions);
        self::assertEqualsWithDelta(
            6.0,
            Math::integrate('twice(scale * x)', 'x', 0.0, 2.0, ['scale' => 1.5], options: $options),
            1.0E-12,
        );
    }

    public function testIntegrateRejectsInvalidBoundsAndSingularSamples(): void
    {
        $this->expectException(\DomainException::class);
        Math::integrate('1 / x', 'x', -1.0, 1.0);
    }

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
        yield 'negative base with an odd rational exponent' => ['(-8)^(1/3)', -2.0, 'float'];
        yield 'decimal integer ratio exponent' => ['(-8)^(1.0/3.0)', -2.0, 'float'];
        yield 'rational exponent is reduced before domain checking' => ['(-64)^(2/6)', -4.0, 'float'];
        yield 'even numerator gives a positive real root' => ['(-8)^(2/3)', 4.0, 'float'];
        yield 'negative odd rational exponent' => ['(-8)^(-1/3)', -0.5, 'float'];
        yield 'exact decimal odd rational exponent' => ['(-32)^0.2', -2.0, 'float'];
        yield 'constant is float' => ['pi', \M_PI, 'float'];
        yield 'tau constant is float' => ['tau', 2 * \M_PI, 'float'];
        yield 'phi constant is float' => ['phi', (1 + \sqrt(5)) / 2, 'float'];
        yield 'unicode multiplication and pi' => ['2 × π', 2 * \M_PI, 'float'];
        yield 'unicode division' => ['6 ÷ 2', 3.0, 'float'];
        yield 'unicode minus' => ['−2 + 5', 3, 'int'];
        yield 'superscript square' => ['2²', 4, 'int'];
        yield 'multi-digit superscript power' => ['2¹⁰', 1024, 'int'];
        yield 'negative superscript power' => ['2⁻³', 0.125, 'float'];
        yield 'superscript power is right associative with caret' => ['2²^3', 256, 'int'];
        yield 'caret is right associative with superscript power' => ['2^3²', 512, 'int'];
        yield 'negative superscript preserves unary precedence' => ['2⁻³^2', 1.0 / 512.0, 'float'];
        yield 'nested negative superscript preserves precedence' => ['2²⁻³^2', 1.0013547198921082, 'float'];
        yield 'unicode square root' => ['√16', 4.0, 'float'];
        yield 'unicode cube root of a negative value' => ['∛(-8)', -2.0, 'float'];
        yield 'base two logarithm' => ['log2(8)', 3.0, 'float'];
        yield 'atan2 geometry' => ['atan2(1, 1)', \M_PI / 4, 'float'];
        yield 'integer gcd' => ['gcd(84, 30)', 6, 'int'];
        yield 'binomial coefficient' => ['binomial(52, 5)', 2_598_960, 'int'];
        yield 'permutation count' => ['permutation(10, 3)', 720, 'int'];
        yield 'double factorial postfix' => ['5!!', 15, 'int'];
        yield 'double factorial function' => ['doublefactorial(6)', 48, 'int'];
        yield 'negative-one double factorial' => ['(-1)!!', 1, 'int'];
        yield 'error function' => ['erf(0)', 0.0, 'float'];
        yield 'imaginary error function' => ['erfi(0)', 0.0, 'float'];
        yield 'scaled complementary error function' => ['erfcx(0)', 1.0, 'float'];
        yield 'Dawson integral' => ['dawson(0)', 0.0, 'float'];
        yield 'Bessel J0' => ['besselj(0, 0)', 1.0, 'float'];
        yield 'Bessel Y0' => ['bessely(0, 1)', 0.08825696421567696, 'float'];
        yield 'modified Bessel I0' => ['besseli(0, 0)', 1.0, 'float'];
        yield 'gamma function' => ['gamma(5)', 24.0, 'float'];
        yield 'regularized lower incomplete gamma' => ['gammainc(1, 2)', 1.0 - \exp(-2.0), 'float'];
        yield 'regularized upper incomplete gamma' => ['gammaincc(1, 2)', \exp(-2.0), 'float'];
        yield 'regularized incomplete beta' => ['betainc(1, 1, 0.4)', 0.4, 'float'];
        yield 'regularized incomplete beta complement' => ['betaincc(1, 1, 0.4)', 0.6, 'float'];
        yield 'beta function' => ['beta(2, 3)', 1.0 / 12.0, 'float'];
        yield 'beta at negative non-integers' => ['beta(-0.5, 1.5)', -\M_PI, 'float'];
        yield 'Riemann zeta at a negative half' => ['zeta(-0.5)', -0.2078862249773546, 'float'];
        yield 'hyperbolic reciprocal identity' => ['sech(0) + coth(1) * tanh(1)', 2.0, 'float'];
        yield 'inverse hyperbolic reciprocal identity' => [
            'acoth(2) + acoth(-2)',
            0.0,
            'float',
        ];
    }

    public function testNegativeBaseRejectsAnEvenDenominatorRationalExponent(): void
    {
        try {
            Math::evaluate('(-8)^(1/2)');
            self::fail('Expected an even-denominator real power to be rejected.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.domain', $error->errorCode());
            self::assertStringStartsWith(
                'A negative base requires an integer exponent',
                $error->getMessage(),
            );
        }
    }

    public function testGreekVariableAliasesResolveAgainstPortableNames(): void
    {
        self::assertSame(13, Math::evaluate('α² + λ', ['alpha' => 2, 'lambda' => 9]));
    }

    public function testSubscriptedVariablesEvaluateAndSolveUsingPortableNames(): void
    {
        self::assertSame(11, Math::evaluate('x₁ + 2x₂', ['x_1' => 3, 'x_2' => 4]));

        $solutions = Math::solve('x₁² = 4', 'x_1', -3.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(-2.0, $solutions[0]->value, 1.0E-10);
        self::assertEqualsWithDelta(2.0, $solutions[1]->value, 1.0E-10);
    }

    public function testAbsoluteValueBarsEvaluateAndSolveEquations(): void
    {
        self::assertSame(3, Math::evaluate('|-2 + 5|'));
        self::assertSame(2, Math::evaluate('2|x - 2|', ['x' => 3]));
        self::assertSame(1, Math::evaluate('||x| - 1|', ['x' => 2]));
        self::assertEqualsWithDelta(2.0 ** \sqrt(2.0), Math::evaluate('|x|^sqrt(2)', ['x' => -2]), 1.0E-12);

        $irrationalPower = Math::solve(
            '|x|^sqrt(2) = 4',
            'x',
            -5.0,
            5.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertEqualsWithDelta(-4.0 ** (1.0 / \sqrt(2.0)), $irrationalPower[0]->value, 1.0E-12);
        self::assertEqualsWithDelta(4.0 ** (1.0 / \sqrt(2.0)), $irrationalPower[1]->value, 1.0E-12);

        $absoluteSelfPower = Math::solve(
            '|x|^sqrt(2) = |x|',
            'x',
            -5.0,
            5.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertSame([-1.0, 0.0, 1.0], \array_map(
            static fn (\MathPHP\Solver\EquationSolution $solution): float => $solution->value,
            $absoluteSelfPower,
        ));

        $negativeAbsoluteSelfPower = Math::solve(
            '|x|^(-sqrt(2)) = |x|',
            'x',
            -5.0,
            5.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertSame([-1.0, 1.0], \array_map(
            static fn (\MathPHP\Solver\EquationSolution $solution): float => $solution->value,
            $negativeAbsoluteSelfPower,
        ));

        $solutions = Math::solve('|x - 1| = 2', 'x', -5.0, 5.0);

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(-1.0, $solutions[0]->value, 1.0E-10);
        self::assertEqualsWithDelta(3.0, $solutions[1]->value, 1.0E-10);

        $rightHandAbsoluteValue = Math::solve('x = |x - 1|', 'x', -5.0, 5.0);
        self::assertCount(1, $rightHandAbsoluteValue);
        self::assertEqualsWithDelta(0.5, $rightHandAbsoluteValue[0]->value, 1.0E-10);

        $range = Math::solveInequality('|x| ≤ 2', 'x', -3.0, 3.0);

        self::assertCount(1, $range);
        self::assertSame(-2.0, $range[0]->lowerBound);
        self::assertSame(2.0, $range[0]->upperBound);
        self::assertTrue($range[0]->includesLowerBound);
        self::assertTrue($range[0]->includesUpperBound);
    }

    public function testUnicodeInequalityComparisonsPreserveInclusiveEndpoints(): void
    {
        $lessThanOrEqual = Math::solveInequality('x² ≤ 4', 'x', -3.0, 3.0);
        $greaterThanOrEqual = Math::solveInequality('x² ≥ 4', 'x', -3.0, 3.0);

        self::assertCount(1, $lessThanOrEqual);
        self::assertSame(-2.0, $lessThanOrEqual[0]->lowerBound);
        self::assertSame(2.0, $lessThanOrEqual[0]->upperBound);
        self::assertTrue($lessThanOrEqual[0]->includesLowerBound);
        self::assertTrue($lessThanOrEqual[0]->includesUpperBound);

        self::assertCount(2, $greaterThanOrEqual);
        self::assertSame(-3.0, $greaterThanOrEqual[0]->lowerBound);
        self::assertSame(-2.0, $greaterThanOrEqual[0]->upperBound);
        self::assertTrue($greaterThanOrEqual[0]->includesUpperBound);
        self::assertSame(2.0, $greaterThanOrEqual[1]->lowerBound);
        self::assertSame(3.0, $greaterThanOrEqual[1]->upperBound);
        self::assertTrue($greaterThanOrEqual[1]->includesLowerBound);
    }

    public function testAtan2UsesScaledPolynomialCoordinatesAcrossFloatRange(): void
    {
        self::assertEqualsWithDelta(
            1.0E-200,
            Math::evaluate('atan2(x^2, x)', ['x' => 1.0E-200]),
            5.0E-214,
        );
        self::assertEqualsWithDelta(
            1.0E-200,
            Math::evaluate('atan2(x^2 + x^4, x)', ['x' => 1.0E-200]),
            5.0E-214,
        );
        self::assertEqualsWithDelta(
            -1.0E-200,
            Math::evaluate('atan2(x^2 - 2*x^2, x)', ['x' => 1.0E-200]),
            2.0E-213,
        );
        self::assertSame(
            0.0,
            Math::evaluate('atan2(x^2 - x^2, x)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            \M_PI,
            Math::evaluate('atan2(x^2, x)', ['x' => -1.0E-200]),
        );
        self::assertSame(
            \M_PI,
            Math::evaluate('atan2(x^3, -2*x^2)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            \M_PI_2,
            Math::evaluate('atan2(x^2, 0)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            0.0,
            Math::evaluate('atan2(0, x^2)', ['x' => 1.0E-200]),
        );
        self::assertEqualsWithDelta(
            \atan(0.5),
            Math::evaluate('atan2(x^2, 2*x^2)', ['x' => 1.0E200]),
            5.0E-14,
        );
        self::assertSame(
            \M_PI_2,
            Math::evaluate('atan2(x^3, -2*x^2)', ['x' => 1.0E200]),
        );
        self::assertSame(\M_PI_4, Math::evaluate('atan2(2^64, 2^64)'));
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(sqrt(x^2), x)', ['x' => 1.0E-200]),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(cbrt(x^3), x)', ['x' => 1.0E-200]),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            \atan(\M_E),
            Math::evaluate('atan2(exp(-1000), exp(-1001))'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan(1.0 / \M_E),
            Math::evaluate('atan2(exp(1000), exp(1001))'),
            1.0E-14,
        );
    }

    public function testScaledAtan2SupportsIntegerRootsAndPreservesRealDomains(): void
    {
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(root(x^4, 4), x)', ['x' => 1.0E-100]),
            1.0E-13,
        );

        foreach (['sqrt(-x^2)', 'root(-x^2, 2)'] as $expression) {
            try {
                Math::evaluate('atan2(' . $expression . ', 1)', ['x' => 1.0E-200]);
                self::fail('A negative radicand must remain outside the real domain.');
            } catch (EvaluationException $error) {
                self::assertSame('eval.domain', $error->errorCode());
                self::assertInstanceOf(\DomainException::class, $error->getPrevious());
            }
        }
    }

    public function testScaledAtan2SupportsLogarithmsBeyondFloatRange(): void
    {
        self::assertEqualsWithDelta(
            \atan2(-1000.0, 1.0),
            Math::evaluate('atan2(ln(exp(-1000)), 1)'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan2(-1000.0 / \log(2.0), 1.0),
            Math::evaluate('atan2(log2(exp(-1000)), 1)'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan2(1000.0, 1.0),
            Math::evaluate('atan2(log(exp(1000), exp(1)), 1)'),
            1.0E-14,
        );

        try {
            Math::evaluate('atan2(ln(-exp(-1000)), 1)');
            self::fail('A negative logarithm argument must remain outside the real domain.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.domain', $error->errorCode());
            self::assertInstanceOf(\DomainException::class, $error->getPrevious());
        }
    }

    public function testScaledAtan2CarriesTinyElementaryFunctionValues(): void
    {
        foreach (['sin', 'tan', 'sinh', 'tanh', 'asin', 'asinh', 'atan', 'atanh'] as $function) {
            self::assertEqualsWithDelta(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), exp(-1000))',
                ),
                1.0E-14,
                $function,
            );
        }

        foreach (['csc', 'cot', 'csch', 'coth'] as $function) {
            self::assertEqualsWithDelta(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), exp(1000))',
                ),
                1.0E-14,
                $function,
            );
        }

        foreach (['sec', 'sinc', 'sincpi'] as $function) {
            self::assertSame(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), 1)',
                ),
                $function,
            );
        }

        $functionSigns = [
            'sin' => -1,
            'tan' => 1,
            'sinh' => 1,
            'tanh' => -1,
            'asin' => 1,
            'asinh' => -1,
            'atan' => -1,
            'atanh' => 1,
        ];
        foreach ($functionSigns as $function => $sign) {
            self::assertSame(
                $sign < 0 ? -\M_PI_2 : \M_PI_2,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)) - exp(-1000), exp(-4000))',
                ),
                $function,
            );
        }
    }

    public function testDifferentiateSupportsPolynomialAndComposedExpressions(): void
    {
        self::assertEqualsWithDelta(
            14.0,
            Math::differentiate('x^3 + 2x', 'x', 2.0),
            1.0E-8,
        );
        self::assertEqualsWithDelta(
            \cos(0.7) + \exp(0.7),
            Math::differentiate('sin(x) + exp(x)', 'x', 0.7),
            1.0E-8,
        );
        self::assertEqualsWithDelta(
            5.0,
            Math::differentiate('scale * x^2', 'x', 2.0, ['scale' => 1.25]),
            1.0E-8,
        );
        self::assertEqualsWithDelta(
            0.0,
            Math::differentiate('sqrt(x)^4', 'x', 0.0),
            1.0E-8,
        );
        self::assertEqualsWithDelta(
            0.0,
            Math::differentiate('tan(x) - x', 'x', 0.0),
            1.0E-12,
        );
    }

    public function testDifferentiateClassifiesRadicalZerosFromBothSides(): void
    {
        foreach (
            ['sqrt(x)', 'sqrt(x^2)', 'cbrt(x)', 'cbrt(x^2)', 'cbrt(x)^2'] as $expression
        ) {
            try {
                Math::differentiate($expression, 'x', 0.0);
                self::fail($expression . ' must not report a finite two-sided derivative.');
            } catch (\DomainException) {
            }
        }

        foreach (
            [
                ['sqrt(x^3)', 0.0],
                ['sqrt(x^4)', 0.0],
                ['sqrt(x^6)', 0.0],
                ['sqrt((x - 1)^4)', 1.0],
                ['cbrt(x^4)', 0.0],
                ['cbrt(x^5)', 0.0],
                ['cbrt(x)^4', 0.0],
                ['cbrt(x)^5', 0.0],
            ] as [$expression, $at]
        ) {
            self::assertSame(0.0, Math::differentiate($expression, 'x', $at), $expression);
        }
        self::assertEqualsWithDelta(1.0, Math::differentiate('cbrt(x^3)', 'x', 0.0), 1.0E-12);
        self::assertEqualsWithDelta(1.0, Math::differentiate('cbrt(x)^3', 'x', 0.0), 1.0E-12);
        foreach (
            [
                'abs(x)^2',
                'abs(x)^3',
                'abs(x)^(3/2)',
                'abs(x)^pi',
                'max(x, 0)^2',
                'max(x, 0)^e',
                'min(x, 0)^3',
                'max(x, -x)^2',
            ] as $expression
        ) {
            self::assertSame(0.0, Math::differentiate($expression, 'x', 0.0), $expression);
        }

        try {
            Math::differentiate('sqrt((x - 1)^2)', 'x', 1.0);
            self::fail('A translated square-root cusp must be rejected.');
        } catch (\DomainException) {
        }
    }

    public function testDifferentiateClassifiesFiniteInverseFunctionEndpoints(): void
    {
        foreach (
            [
                'asin(1 - x^2)',
                'acos(1 - x^2)',
                'asech(1 - x^2)',
                'acosh(1 + x^2)',
            ] as $expression
        ) {
            try {
                Math::differentiate($expression, 'x', 0.0);
                self::fail($expression . ' has a cusp at the inverse-function endpoint.');
            } catch (\DomainException) {
            }
        }

        foreach (
            [
                'asin(1 - x^4)',
                'acos(1 - x^4)',
                'acosh(1 + x^4)',
                'asech(1 - x^4)',
            ] as $expression
        ) {
            self::assertSame(0.0, Math::differentiate($expression, 'x', 0.0), $expression);
        }
    }

    public function testDifferentiateClassifiesZeroRadicandRootContacts(): void
    {
        foreach (['root(x^2, 3)', 'root(x^2, 5)', 'root(x, 3)'] as $expression) {
            try {
                Math::differentiate($expression, 'x', 0.0);
                self::fail($expression . ' has an infinite slope or cusp at zero.');
            } catch (\DomainException) {
            }
        }

        self::assertEqualsWithDelta(1.0, Math::differentiate('root(x^3, 3)', 'x', 0.0), 1.0E-12);
        self::assertSame(0.0, Math::differentiate('root(x^4, 3)', 'x', 0.0));
        self::assertSame(0.0, Math::differentiate('root(x^4, 2)', 'x', 0.0));
    }

    public function testDifferentiateUsesCustomFunctionPartialsAndChainRule(): void
    {
        $product = new FunctionDefinition(
            'product',
            2,
            2,
            static fn (array $arguments): float => $arguments[0] * $arguments[1],
            static fn (array $arguments): array => [$arguments[1], $arguments[0]],
        );

        self::assertEqualsWithDelta(
            (0.4 ** 2) * \cos(0.4) + (2.0 * 0.4) * \sin(0.4),
            Math::differentiate(
                'product(x^2, sin(x))',
                'x',
                0.4,
                options: new EvaluationOptions(
                    functions: FunctionRegistry::defaults()->with($product),
                ),
            ),
            1.0E-12,
        );
    }

    public function testCustomGradientDomainFailureFallsBackToNumericalDifferentiation(): void
    {
        $square = new FunctionDefinition(
            'partialSquare',
            1,
            1,
            static fn (array $arguments): float => $arguments[0] ** 2,
            static function (array $arguments): array {
                throw new \DomainException('Analytic derivative unavailable.');
            },
        );

        self::assertEqualsWithDelta(
            4.0,
            Math::differentiate(
                'partialSquare(x)',
                'x',
                2.0,
                options: new EvaluationOptions(
                    functions: FunctionRegistry::defaults()->with($square),
                ),
            ),
            1.0E-7,
        );
    }

    public function testCustomGradientChainRuleScalesBeforeSummingProducts(): void
    {
        $scaledSum = new FunctionDefinition(
            'scaledSum',
            2,
            2,
            static fn (array $arguments): float => ($arguments[0] + $arguments[1]) * 1.0E308,
            static fn (array $arguments): array => [1.0E308, 1.0E308],
        );
        $options = new EvaluationOptions(
            functions: FunctionRegistry::defaults()->with($scaledSum),
        );

        self::assertSame(
            0.0,
            Math::differentiate('scaledSum(x^2, -x^2)', 'x', 1.0, options: $options),
        );
    }

    public function testDifferentiateArithmeticExpressionsWithoutLargeOffsetCancellation(): void
    {
        self::assertSame(
            5.0,
            Math::differentiate('(x - 1e8)^5', 'x', 1.0E8 + 1.0),
        );
        self::assertSame(
            4.0,
            Math::differentiate('(x - 1e8)^4', 'x', 1.0E8 + 1.0),
        );
        self::assertSame(
            3.0,
            Math::differentiate('scale * x^2', 'x', 2.0, ['scale' => 0.75]),
        );
        self::assertSame(
            -1.0,
            Math::differentiate('1 / (x - 1e8)', 'x', 1.0E8 + 1.0),
        );
        self::assertSame(
            -0.25,
            Math::differentiate('x^-2', 'x', 2.0),
        );
        self::assertSame(
            0.25,
            Math::differentiate('x^0.5', 'x', 4.0),
        );
        self::assertSame(
            0.25,
            Math::differentiate('x^alpha', 'x', 4.0, ['alpha' => 0.5]),
        );
        self::assertEqualsWithDelta(
            1.0 / 12.0,
            Math::differentiate('x^(1/3)', 'x', -8.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -1.0 / 3.0,
            Math::differentiate('x^(2/3)', 'x', -8.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -1.0 / 48.0,
            Math::differentiate('x^(-1/3)', 'x', -8.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -1.0 / 20.0,
            Math::differentiate('x^(2/5)', 'x', -32.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            2.0 / 3.0,
            Math::differentiate('(-2*x)^(2/3)', 'x', 4.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            1.0 / 80.0,
            Math::differentiate('x^0.2', 'x', -32.0),
            1.0E-12,
        );
        self::assertSame(
            0.0,
            Math::differentiate('x^(5/3)', 'x', 0.0),
        );
        self::assertSame(
            0.0,
            Math::differentiate('x^(3/2)', 'x', 0.0),
        );
        self::assertEqualsWithDelta(
            \sqrt(0.5) * (1.0 + \log(0.5)),
            Math::differentiate('x^x', 'x', 0.5),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \log(5.0E-324) + 1.0,
            Math::differentiate('x^x', 'x', 5.0E-324),
            1.0E-12,
        );
    }

    public function testDifferentiateSmoothElementaryFunctionsByChainRule(): void
    {
        $cases = [
            ['tan(x)', 0.4, 1.0 / (\cos(0.4) ** 2)],
            ['sec(x)', 0.4, (1.0 / \cos(0.4)) * \tan(0.4)],
            ['csc(x)', 0.8, -(1.0 / \sin(0.8)) * (\cos(0.8) / \sin(0.8))],
            ['cot(x)', 0.8, -1.0 / (\sin(0.8) ** 2)],
            ['asin(x)', 0.5, 2.0 / \sqrt(3.0)],
            ['asec(x)', 2.0, 1.0 / (2.0 * \sqrt(3.0))],
            ['acsc(x)', 2.0, -1.0 / (2.0 * \sqrt(3.0))],
            ['acot(x)', 2.0, -0.2],
            ['asinh(x)', 3.0, 1.0 / \sqrt(10.0)],
            ['acosh(x)', 2.0, 1.0 / \sqrt(3.0)],
            ['atanh(x)', 0.5, 4.0 / 3.0],
            ['sech(x)', 2.0, -Math::evaluate('sech(2)') * \tanh(2.0)],
            ['csch(x)', 2.0, -Math::evaluate('csch(2)') * (\cosh(2.0) / \sinh(2.0))],
            ['coth(x)', 2.0, -(1.0 / \sinh(2.0)) ** 2],
            ['asech(x)', 0.5, -1.0 / (0.5 * \sqrt(0.75))],
            ['acsch(x)', 2.0, -1.0 / (2.0 * \sqrt(5.0))],
            ['acoth(x)', 2.0, -1.0 / 3.0],
            [
                'tanh(x)',
                20.0,
                (4.0 * \exp(-40.0)) / ((1.0 + \exp(-40.0)) ** 2),
            ],
            ['log1p(x)', 0.5, 2.0 / 3.0],
            ['sqrt(x)', 4.0, 0.25],
            ['cbrt(x)', -8.0, 1.0 / 12.0],
            ['sinc(x)', 0.0, 0.0],
            ['sinc(x)', 1.0, \cos(1.0) - \sin(1.0)],
            ['sincpi(x)', 0.5, -4.0 / \M_PI],
            ['sincpi(x)', 1.0E-8, -(\M_PI ** 2) * 1.0E-8 / 3.0],
            ['exp(sin(x))', 0.7, \exp(\sin(0.7)) * \cos(0.7)],
            ['erfc(x)', 1.0, -(2.0 / \sqrt(\M_PI)) * \exp(-1.0)],
            ['besselj(0, x)', 1.0, -0.4400505857449335],
            ['bessely(0, x)', 1.0, 0.7812128213002887],
            ['besseli(0, x)', 1.0, 0.565159103992485],
            [
                'besselj(2, 3*x)',
                1.0,
                1.5 * (Math::evaluate('besselj(1, 3)') - Math::evaluate('besselj(3, 3)')),
            ],
        ];

        foreach ($cases as [$expression, $at, $expected]) {
            self::assertEqualsWithDelta(
                $expected,
                Math::differentiate($expression, 'x', $at),
                1.0E-13,
                $expression,
            );
        }
    }

    public function testDifferentiateTwoArgumentFunctionsByChainRule(): void
    {
        self::assertEqualsWithDelta(
            1.0 / 80.0,
            Math::differentiate('root(x, 5)', 'x', -32.0),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            1.0 / (4.0 * \log(2.0)),
            Math::differentiate('log(x, 2)', 'x', 4.0),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            1.0 / (8.0 * \log(2.0)),
            Math::differentiate('log(x, 2*x)', 'x', 2.0),
            1.0E-14,
        );
        self::assertSame(
            5.0,
            Math::differentiate('hypot(3*x, 4*x)', 'x', 2.0),
        );
        self::assertEqualsWithDelta(
            0.25,
            Math::differentiate('atan2(x^2, 2*x)', 'x', 2.0),
            1.0E-14,
        );
        foreach ([1.0E-300, 1.0E-310, 1.0E-320, 5.0E-324] as $subnormalScale) {
            self::assertSame(
                0.0,
                Math::differentiate('atan2(x, 2*x)', 'x', $subnormalScale),
            );
        }
        foreach ([1.0E-150, 1.0E-170, 1.0E-200, 1.0E-300, 5.0E-324] as $smallPositive) {
            self::assertEqualsWithDelta(
                1.0,
                Math::differentiate('atan2(x^2, x)', 'x', $smallPositive),
                1.0E-14,
            );
        }
        foreach ([1.0E-200, -1.0E-200] as $smallSigned) {
            self::assertEqualsWithDelta(
                -0.5,
                Math::differentiate('atan2(x^3, -2*x^2)', 'x', $smallSigned),
                1.0E-14,
            );
            self::assertSame(0.0, Math::differentiate('atan2(0, x^2)', 'x', $smallSigned));
            self::assertSame(0.0, Math::differentiate('atan2(x^2, 0)', 'x', $smallSigned));
        }
        self::assertSame(
            0.0,
            Math::differentiate('hypot(x^2, x^3)', 'x', 0.0),
        );
        foreach ([64, -64, 256, -256] as $order) {
            $expected = $order > 0
                ? Math::evaluate('besselj(' . ($order - 1) . ', 1)')
                    - ($order * Math::evaluate('besselj(' . $order . ', 1)'))
                : ($order * Math::evaluate('besselj(' . $order . ', 1)'))
                    - Math::evaluate('besselj(' . ($order + 1) . ', 1)');
            self::assertEqualsWithDelta(
                $expected,
                Math::differentiate('besselj(' . $order . ', x)', 'x', 1.0),
                1.0E-12,
            );
        }
        self::assertSame(0.5, Math::differentiate('besselj(1, x)', 'x', 0.0));
        self::assertSame(-0.5, Math::differentiate('besselj(-1, x)', 'x', 0.0));
        foreach ([64, -64, 256, -256] as $order) {
            $expected = Math::evaluate('besseli(' . (\abs($order) - 1) . ', 1)')
                - (\abs($order) * Math::evaluate('besseli(' . \abs($order) . ', 1)'));
            self::assertEqualsWithDelta(
                $expected,
                Math::differentiate('besseli(' . $order . ', x)', 'x', 1.0),
                1.0E-13,
            );
        }
        self::assertSame(0.5, Math::differentiate('besseli(1, x)', 'x', 0.0));
        self::assertSame(0.5, Math::differentiate('besseli(-1, x)', 'x', 0.0));
    }

    public function testDifferentiateGammaFunctionsByChainRule(): void
    {
        $eulerMascheroni = 0.5772156649015329;
        $positiveDigamma = (25.0 / 12.0) - $eulerMascheroni;
        $negativeHalfDigamma = 2.0 - $eulerMascheroni - (2.0 * \log(2.0));

        self::assertEqualsWithDelta(
            24.0 * $positiveDigamma,
            Math::differentiate('gamma(x)', 'x', 5.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            $positiveDigamma,
            Math::differentiate('lgamma(x)', 'x', 5.0),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            -2.0 * \sqrt(\M_PI) * $negativeHalfDigamma,
            Math::differentiate('gamma(x)', 'x', -0.5),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            $negativeHalfDigamma,
            Math::differentiate('lgamma(x)', 'x', -0.5),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            (\M_PI ** 2) / 6.0,
            Math::differentiate('digamma(x)', 'x', 1.0),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            (\M_PI ** 2) / 2.0,
            Math::differentiate('digamma(x)', 'x', 0.5),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            -2.4041138063191886,
            Math::differentiate('trigamma(x)', 'x', 1.0),
            1.0E-13,
        );
    }

    public function testDifferentiateIncompleteGammaValueArgumentsAnalytically(): void
    {
        $x = 1.5;
        $density = \MathPHP\Function\SpecialFunctions::regularizedGammaDensity(2.0, $x);
        self::assertEqualsWithDelta(
            $density,
            Math::differentiate('gammainc(2, x)', 'x', $x),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -$density,
            Math::differentiate('gammaincc(2, x)', 'x', $x),
            1.0E-12,
        );
        $composedPoint = 0.7;
        self::assertEqualsWithDelta(
            (2.0 * $composedPoint)
                * \MathPHP\Function\SpecialFunctions::regularizedGammaDensity(
                    2.0,
                    $composedPoint ** 2,
                ),
            Math::differentiate('gammainc(2, x^2)', 'x', $composedPoint),
            1.0E-12,
        );
        self::assertSame(1.0, Math::differentiate('gammainc(1, x)', 'x', 0.0));
        self::assertSame(-1.0, Math::differentiate('gammaincc(1, x)', 'x', 0.0));
        self::assertSame(0.0, Math::differentiate('gammainc(2, x)', 'x', 0.0));
    }

    public function testDifferentiateBetaByBothArguments(): void
    {
        $first = 2.0;
        $second = 3.0;
        $beta = Math::evaluate('beta(2, 3)');
        $firstDerivative = $beta * (
            Math::evaluate('digamma(2)') - Math::evaluate('digamma(5)')
        );
        $secondDerivative = $beta * (
            Math::evaluate('digamma(3)') - Math::evaluate('digamma(5)')
        );
        $diagonalBeta = Math::evaluate('beta(2, 2)');
        $diagonalDerivative = 2.0 * $diagonalBeta * (
            Math::evaluate('digamma(2)') - Math::evaluate('digamma(4)')
        );

        self::assertEqualsWithDelta(
            $firstDerivative,
            Math::differentiate('beta(x, 3)', 'x', $first),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            $secondDerivative,
            Math::differentiate('beta(2, x)', 'x', $second),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            $diagonalDerivative,
            Math::differentiate('beta(x, x)', 'x', 2.0),
            1.0E-12,
        );

        $negativeBeta = Math::evaluate('beta(-0.5, 1.5)');
        $negativeDerivative = $negativeBeta * (
            Math::evaluate('digamma(-0.5)') - Math::evaluate('digamma(1)')
        );
        self::assertEqualsWithDelta(
            $negativeDerivative,
            Math::differentiate('beta(x, 1.5)', 'x', -0.5),
            1.0E-12,
        );
    }

    public function testDifferentiateIncompleteBetaValueArgumentsAnalytically(): void
    {
        $x = 0.4;
        $density = \MathPHP\Function\SpecialFunctions::regularizedBetaDensity(2.0, 3.0, $x);
        self::assertEqualsWithDelta(
            $density,
            Math::differentiate('betainc(2, 3, x)', 'x', $x),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -$density,
            Math::differentiate('betaincc(2, 3, x)', 'x', $x),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            2.0 * $x * \MathPHP\Function\SpecialFunctions::regularizedBetaDensity(
                2.0,
                3.0,
                $x * $x,
            ),
            Math::differentiate('betainc(2, 3, x^2)', 'x', $x),
            1.0E-12,
        );
    }

    public function testBetaOverflowAtTinyPositiveArgumentIsReportedAsEvaluationError(): void
    {
        foreach (['beta(5e-324, 1)', 'beta(1, 5e-324)'] as $expression) {
            try {
                Math::evaluate($expression);
                self::fail('The beta value is larger than the finite floating-point range.');
            } catch (EvaluationException $error) {
                self::assertSame('eval.integer_overflow', $error->errorCode());
            }
        }
    }

    public function testRiemannZetaDifferentiatesNumericallyAwayFromItsPole(): void
    {
        self::assertEqualsWithDelta(
            -0.9375482543158438,
            Math::differentiate('zeta(x)', 'x', 2.0),
            1.0E-9,
        );
    }

    public function testDifferentiateLogGammaNearNegativeIntegerPoles(): void
    {
        foreach ([[-1.0E-16, 1], [-0.9999999999999998, -1]] as [$nearPole, $sign]) {
            $derivative = Math::differentiate('lgamma(x)', 'x', $nearPole);

            self::assertTrue(\is_finite($derivative));
            self::assertSame($sign, $derivative < 0.0 ? -1 : 1);
            self::assertGreaterThan(1.0E15, \abs($derivative));
        }
    }

    public function testDifferentiateLambertWBranchesByChainRule(): void
    {
        $principal = Math::evaluate('lambertw(1)');
        $lower = Math::evaluate('lambertw(-0.1, -1)');

        self::assertEqualsWithDelta(
            $principal / (1.0 + $principal),
            Math::differentiate('lambertw(x)', 'x', 1.0),
            1.0E-13,
        );
        self::assertSame(1.0, Math::differentiate('lambertw(x)', 'x', 0.0));
        self::assertEqualsWithDelta(
            $lower / (-0.1 * (1.0 + $lower)),
            Math::differentiate('lambertw(x, -1)', 'x', -0.1),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            (2.0 * $principal) / (1.0 + $principal),
            Math::differentiate('lambertw(x^2)', 'x', 1.0),
            1.0E-13,
        );
    }

    public function testDifferentiateTwoArgumentFunctionsRejectsSingularPoints(): void
    {
        $rejected = 0;
        foreach (
            [
                ['hypot(x, -x)', 0.0],
                ['atan2(x, x)', 0.0],
                ['x^(2/3)', 0.0],
                ['x^(1/2)', 0.0],
            ] as [$expression, $point]
        ) {
            try {
                Math::differentiate($expression, 'x', $point);
                self::fail($expression . ' should not have a derivative at this point.');
            } catch (\DomainException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }

    public function testDifferentiatePiecewiseFunctionsAwayFromKinks(): void
    {
        $cases = [
            ['abs(x)', 2.0, 1.0],
            ['abs(x)', -2.0, -1.0],
            ['abs(x^2)', 0.0, 0.0],
            ['sign(x)', 2.0, 0.0],
            ['floor(x)', 2.25, 0.0],
            ['ceil(x)', 2.25, 0.0],
            ['round(x)', 2.25, 0.0],
            ['min(x, 2*x)', -1.0, 2.0],
            ['max(x, 2*x)', -1.0, 1.0],
            ['min(x^2, 2*x^2)', 0.0, 0.0],
            ['min(abs(x), x^2)', 0.0, 0.0],
            ['min(abs(x) + 1, x^2)', 0.0, 0.0],
            ['min(sqrt(x), x)', 0.0, 1.0],
            ['min(sqrt(x), x^2)', 0.0, 0.0],
        ];

        foreach ($cases as [$expression, $at, $expected]) {
            self::assertSame(
                $expected,
                Math::differentiate($expression, 'x', $at),
                $expression,
            );
        }
    }

    public function testDifferentiateRejectsNondifferentiableKinks(): void
    {
        $cases = [
            ['abs(x)', 0.0],
            ['sign(x)', 0.0],
            ['floor(x)', 1.0],
            ['ceil(x)', 1.0],
            ['round(x)', 0.5],
            ['min(x, -x)', 0.0],
            ['max(x, -x)', 0.0],
        ];
        $rejected = 0;

        foreach ($cases as [$expression, $at]) {
            try {
                Math::differentiate($expression, 'x', $at);
                self::fail($expression . ' should not have a derivative at this point.');
            } catch (\DomainException) {
                ++$rejected;
            }
        }

        self::assertSame(\count($cases), $rejected);
    }

    public function testDifferentiateAdaptsToRapidOscillation(): void
    {
        foreach ([10_000.0, 1_000_000.0] as $frequency) {
            self::assertEqualsWithDelta(
                $frequency,
                Math::differentiate(
                    'sin(frequency * x)',
                    'x',
                    0.0,
                    ['frequency' => $frequency],
                ),
                $frequency * 1.0E-8,
            );
        }
    }

    public function testNumericalDerivativeRejectsInfiniteSlopeAtCustomDomainBoundary(): void
    {
        $squareRoot = new FunctionDefinition(
            'domainroot',
            1,
            1,
            static function (array $arguments): float {
                if ($arguments[0] < 0.0) {
                    throw new FunctionDomainException('outside the real domain');
                }

                return \sqrt($arguments[0]);
            },
        );
        $line = new FunctionDefinition(
            'domainline',
            1,
            1,
            static function (array $arguments): float {
                if ($arguments[0] < 0.0) {
                    throw new FunctionDomainException('outside the real domain');
                }

                return (3.0 * $arguments[0]) + 2.0;
            },
        );
        $steepLine = new FunctionDefinition(
            'domainsteep',
            1,
            1,
            static function (array $arguments): float {
                if ($arguments[0] < 0.0) {
                    throw new FunctionDomainException('outside the real domain');
                }

                return (1.0E12 * $arguments[0]) + 2.0;
            },
        );
        $options = new EvaluationOptions(
            functions: FunctionRegistry::defaults()->with($squareRoot)->with($line)->with($steepLine),
        );

        try {
            Math::differentiate('domainroot(x)', 'x', 0.0, options: $options);
            self::fail('A vertical tangent should not be returned as a finite derivative.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('cannot be differentiated', $error->getMessage());
        }

        self::assertEqualsWithDelta(
            3.0,
            Math::differentiate('domainline(x)', 'x', 0.0, options: $options),
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            1.0E12,
            Math::differentiate('domainsteep(x)', 'x', 0.0, options: $options),
            1.0E-3,
        );
    }

    public function testDifferentiateRejectsInvalidVariableAndPoint(): void
    {
        try {
            Math::differentiate('x', 'pi', 1.0);
            self::fail('A reserved constant cannot be differentiated as a variable.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('non-reserved', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        Math::differentiate('x', 'x', \INF);
    }

    public function testNamedMathematicalConstantsEvaluateAndParticipateInEquations(): void
    {
        self::assertSame(0.5772156649015328606, Math::evaluate('eulerGamma'));
        self::assertSame(0.91596559417721901505, Math::evaluate('catalan'));
        self::assertSame(1.2020569031595942854, Math::evaluate('apery'));

        $solutions = Math::solve('x = catalan', 'x', 0.0, 1.0);
        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.91596559417721901505, $solutions[0]->value, 1.0E-12);

        foreach (['eulerGamma', 'catalan', 'apery'] as $constant) {
            try {
                Math::solve($constant . ' = 0', $constant, -1.0, 1.0);
                self::fail('A named mathematical constant cannot be solved as a variable.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('non-reserved', $exception->getMessage());
            }
        }
    }

    public function testArcTrigonometricAliasesCanBeSolved(): void
    {
        foreach (
            [
                ['arcsin(x) = 0.5', \sin(0.5)],
                ['arccos(x) = 1', \cos(1.0)],
                ['arctan(x) = 1', \tan(1.0)],
            ] as [$equation, $expected]
        ) {
            $solutions = Math::solve($equation, 'x', -2.0, 2.0);
            self::assertCount(1, $solutions, $equation);
            self::assertEqualsWithDelta($expected, $solutions[0]->value, 1.0E-8, $equation);
        }
    }

    public function testInverseHyperbolicAliasesCanBeSolved(): void
    {
        foreach (
            [
                ['arcsinh(x) = 1', \sinh(1.0)],
                ['arccosh(x) = 1', \cosh(1.0)],
                ['arctanh(x) = 0.5', \tanh(0.5)],
            ] as [$equation, $expected]
        ) {
            $solutions = Math::solve($equation, 'x', -2.0, 2.0);
            self::assertCount(1, $solutions, $equation);
            self::assertEqualsWithDelta($expected, $solutions[0]->value, 1.0E-8, $equation);
        }
    }

    public function testUnicodeCubeRootCanBeSolvedOverNegativeInputs(): void
    {
        $solutions = Math::solve('∛x = -2', 'x', -10.0, 10.0);

        self::assertCount(1, $solutions);
        self::assertSame(-8.0, $solutions[0]->value);
        self::assertSame(0.0, $solutions[0]->residual);
    }

    public function testDifferentiatePreservesExpressionEvaluationErrors(): void
    {
        try {
            Math::differentiate('x + missing', 'x', 1.0);
            self::fail('An unknown expression variable should remain an evaluation error.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
        }
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

    public function testBoundVariableFollowedByParenthesesIsImplicitMultiplication(): void
    {
        self::assertSame(8, Math::evaluate('x(y + 1)', ['x' => 2, 'y' => 3]));

        $solutions = Math::solve('x(x + 1) = 6', 'x', -4.0, 4.0);
        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(-3.0, $solutions[0]->value, 1.0E-10);
        self::assertEqualsWithDelta(2.0, $solutions[1]->value, 1.0E-10);
    }

    public function testExactPolynomialRootCountingUsesDistinctRootsAndOpenBounds(): void
    {
        self::assertSame(3, Math::countPolynomialRootsInInterval('x^3 - x = 0', 'x', -2.0, 2.0));
        self::assertSame(1, Math::countPolynomialRootsInInterval('(x - 1)^2 * (x + 1) = 0', 'x', 0.0, 2.0));
        self::assertSame(0, Math::countPolynomialRootsInInterval('x * (x - 1) = 0', 'x', 0.0, 1.0));
        self::assertSame(1, Math::countPolynomialRootsInInterval('sin(x) = 0', 'x', -2.0, 2.0));
    }

    public function testCertifiedExponentialEquationIsolationUsesExactRationalInputs(): void
    {
        $logarithm = Math::isolateExponentialEquation('2', '0', '1', '1/1000');
        $exact = Math::isolateExponentialEquation('1', '-1', '1');
        $empty = Math::isolateExponentialEquation('3', '-1', '1');

        self::assertNotNull($logarithm);
        self::assertCount(1, $logarithm);
        self::assertFalse($logarithm[0]->isExactRoot);
        self::assertLessThanOrEqual(
            \log(2.0),
            (float) $logarithm[0]->lowerNumerator / (float) $logarithm[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            \log(2.0),
            (float) $logarithm[0]->upperNumerator / (float) $logarithm[0]->upperDenominator,
        );
        self::assertNotNull($exact);
        self::assertCount(1, $exact);
        self::assertTrue($exact[0]->isExactRoot);
        self::assertSame([], $empty);
    }

    public function testCertifiedAffineExponentialEquationIsolation(): void
    {
        $roots = Math::isolateAffineExponentialEquation('4', '2', '1', '0', '1', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertLessThanOrEqual(
            (\log(4.0) - 1.0) / 2.0,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            (\log(4.0) - 1.0) / 2.0,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
    }

    public function testCertifiedQuadraticExponentialEquationIsolation(): void
    {
        $roots = Math::isolateQuadraticExponentialEquation('2', '1', '0', '0', '-2', '2', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        $expected = \sqrt(\log(2.0));
        self::assertLessThanOrEqual(
            -$expected,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            -$expected,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
        self::assertLessThanOrEqual(
            $expected,
            (float) $roots[1]->lowerNumerator / (float) $roots[1]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expected,
            (float) $roots[1]->upperNumerator / (float) $roots[1]->upperDenominator,
        );
    }

    public function testCertifiedAffineHyperbolicSineEquationIsolation(): void
    {
        $roots = Math::isolateAffineHyperbolicSineEquation('1/2', '2', '1', '-1', '0', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        $expected = (\asinh(0.5) - 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $expected,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expected,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
    }

    public function testCertifiedAffineLogarithmEquationIsolation(): void
    {
        $roots = Math::isolateAffineLogarithmEquation('1', '2', '1', '0', '1', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        $expected = (\exp(1.0) - 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $expected + 1.0E-12,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expected - 1.0E-12,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
    }

    public function testCertifiedQuadraticLogarithmEquationIsolation(): void
    {
        $roots = Math::isolateQuadraticLogarithmEquation('1', '1', '1', '-2', '2', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertLessThan(0.0, (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator);
        self::assertGreaterThan(0.0, (float) $roots[1]->lowerNumerator / (float) $roots[1]->lowerDenominator);
    }

    public function testCertifiedGeneralQuadraticLogarithmEquationIsolation(): void
    {
        $roots = Math::isolateQuadraticPolynomialLogarithmEquation(
            '1',
            '1',
            '2',
            '1',
            '-3',
            '1',
            '1/1000',
        );

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
    }

    public function testFiniteExactRationalEquationCountingAndIsolationExcludePoles(): void
    {
        $equation = '(x^2 - 1) / (x - 1) = 0';
        self::assertSame(1, Math::countPolynomialRootsInInterval($equation, 'x', -2.0, 2.0));
        self::assertSame(0, Math::countPolynomialRootsInInterval($equation, 'x', 0.0, 2.0));

        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            $equation,
            'x',
            '-2',
            '2',
            maximumWidth: '1/1000',
        );
        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertTrue($intervals[0]->isExactRoot);
        self::assertSame('-1', $intervals[0]->lowerNumerator);
    }

    public function testRationalIsolationAcceptsArbitraryPrecisionWidthRatios(): void
    {
        $scale = '1' . \str_repeat('0', 40);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'x^2 - 2 = 0',
                'x',
                '0/' . $scale,
                '2' . \str_repeat('0', 40) . '/' . $scale,
            ),
        );

        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            'x^2 - 2 = 0',
            'x',
            $scale . '/' . $scale,
            '2' . $scale . '/' . $scale,
            maxDepth: 256,
            maximumWidth: '1/' . $scale,
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertFalse($intervals[0]->isExactRoot);
        self::assertGreaterThan(19, \strlen($intervals[0]->lowerDenominator));
        self::assertGreaterThan(19, \strlen($intervals[0]->upperDenominator));

        $interval = $intervals[0];
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $widthNumerator = $integerArithmetic->subtract(
            $integerArithmetic->multiplyIntegerValues(
                $interval->upperNumerator,
                $interval->lowerDenominator,
            ),
            $integerArithmetic->multiplyIntegerValues(
                $interval->lowerNumerator,
                $interval->upperDenominator,
            ),
        );
        $widthDenominator = $integerArithmetic->multiplyIntegerValues(
            $interval->upperDenominator,
            $interval->lowerDenominator,
        );
        self::assertLessThanOrEqual(
            0,
            $integerArithmetic->compareIntegerValues(
                $integerArithmetic->multiplyIntegerValues($widthNumerator, $scale),
                $widthDenominator,
            ),
        );
    }

    public function testExactPolynomialRootCountingAcceptsLargeIntegerLiterals(): void
    {
        $equation = '100000000000000000000*x - 1 = 0';
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'),
        );

        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            $equation,
            'x',
            '0',
            '1',
            maximumWidth: '1/1000000000000000000000000',
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertGreaterThan(19, \strlen($intervals[0]->lowerDenominator));
        self::assertGreaterThan(19, \strlen($intervals[0]->upperDenominator));

        $veryLargeCoefficient = '1' . \str_repeat('0', 300);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                $veryLargeCoefficient . '*x - 1 = 0',
                'x',
                '0',
                '1',
            ),
        );

        $unrepresentableCoefficient = '1' . \str_repeat('0', 400);
        $upperDenominator = '1' . \str_repeat('0', 399);
        $maximumWidthDenominator = '1' . \str_repeat('0', 401);
        $tinyRootEquation = $unrepresentableCoefficient . '*x - 1 = 0';
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                $tinyRootEquation,
                'x',
                '0',
                '1/' . $upperDenominator,
            ),
        );
        $tinyRootIntervals = Math::isolatePolynomialRootsInRationalInterval(
            $tinyRootEquation,
            'x',
            '0',
            '1/' . $upperDenominator,
            maximumWidth: '1/' . $maximumWidthDenominator,
            maxDepth: 64,
        );
        self::assertNotNull($tinyRootIntervals);
        self::assertCount(1, $tinyRootIntervals);
        self::assertGreaterThan(399, \strlen($tinyRootIntervals[0]->lowerDenominator));

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                '1e400*x - 1 = 0',
                'x',
                '0',
                '1/' . $upperDenominator,
            ),
        );
        $rationalFunction = '(' . $unrepresentableCoefficient . '*x - 3)/('
            . $unrepresentableCoefficient . '*x + 1) = 0';
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                $rationalFunction,
                'x',
                '0',
                '1/' . $upperDenominator,
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($rationalFunction, 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine($tinyRootEquation, 'x'),
        );
        $allRealTinyRoots = Math::isolatePolynomialRootsOnRealLine(
            $tinyRootEquation,
            'x',
            maxDepth: 2048,
            maximumWidth: '1/' . $maximumWidthDenominator,
        );
        self::assertNotNull($allRealTinyRoots);
        self::assertCount(1, $allRealTinyRoots);
        self::assertTrue($allRealTinyRoots[0]->isExactRoot);
        self::assertSame('1', $allRealTinyRoots[0]->lowerNumerator);
        self::assertSame($unrepresentableCoefficient, $allRealTinyRoots[0]->lowerDenominator);

        $negativeTinyRootEquation = '-' . $unrepresentableCoefficient . '*x - 1 = 0';
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                $negativeTinyRootEquation,
                'x',
                '-1/' . $upperDenominator,
                '0',
            ),
        );
        $negativeAllRealRoot = Math::isolatePolynomialRootsOnRealLine(
            $negativeTinyRootEquation,
            'x',
            maxDepth: 2048,
            maximumWidth: '1/' . $maximumWidthDenominator,
        );
        self::assertNotNull($negativeAllRealRoot);
        self::assertCount(1, $negativeAllRealRoot);
        self::assertTrue($negativeAllRealRoot[0]->isExactRoot);
        self::assertSame('-1', $negativeAllRealRoot[0]->lowerNumerator);
        self::assertSame($unrepresentableCoefficient, $negativeAllRealRoot[0]->lowerDenominator);
    }

    public function testExactRootApisFailClosedAtTheirIntegerDigitLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxExpressionLength: 5000),
        );
        foreach ([4096 => true, 4097 => false] as $digits => $withinLimit) {
            $coefficient = '1' . \str_repeat('0', $digits - 1);
            $equation = $coefficient . '*x - 1 = 0';
            if ($withinLimit) {
                self::assertSame(
                    1,
                    Math::countPolynomialRootsInRationalInterval(
                        $equation,
                        'x',
                        '0',
                        '1',
                        evaluationOptions: $options,
                    ),
                );
                self::assertSame(
                    1,
                    Math::countPolynomialRootsOnRealLine(
                        $equation,
                        'x',
                        evaluationOptions: $options,
                    ),
                );
                self::assertNotNull(Math::isolatePolynomialRootsInRationalInterval(
                    $equation,
                    'x',
                    '0',
                    '1',
                    evaluationOptions: $options,
                ));
                self::assertNotNull(Math::isolatePolynomialRootsOnRealLine(
                    $equation,
                    'x',
                    evaluationOptions: $options,
                ));

                continue;
            }

            self::assertNull(Math::countPolynomialRootsInRationalInterval(
                $equation,
                'x',
                '0',
                '1',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::countPolynomialRootsOnRealLine(
                $equation,
                'x',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '0',
                '1',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::isolatePolynomialRootsOnRealLine(
                $equation,
                'x',
                evaluationOptions: $options,
            ));
        }
    }

    public function testRationalIsolationBracketsDoNotIncludeExcludedEndpointRoots(): void
    {
        $lowerEndpointRoot = Math::isolatePolynomialRootsInRationalInterval(
            'x^3 - 2*x = 0',
            'x',
            '0',
            '2',
            maximumWidth: '2',
        );
        $upperEndpointRoot = Math::isolatePolynomialRootsInRationalInterval(
            'x^3 - 2*x^2 - 2*x + 4 = 0',
            'x',
            '0',
            '2',
            maximumWidth: '2',
        );

        self::assertNotNull($lowerEndpointRoot);
        self::assertNotNull($upperEndpointRoot);
        self::assertCount(1, $lowerEndpointRoot);
        self::assertCount(1, $upperEndpointRoot);
        self::assertSame('1', $lowerEndpointRoot[0]->lowerNumerator);
        self::assertSame('2', $lowerEndpointRoot[0]->upperNumerator);
        self::assertSame('1', $upperEndpointRoot[0]->lowerNumerator);
        self::assertSame('3', $upperEndpointRoot[0]->upperNumerator);
        self::assertSame('2', $upperEndpointRoot[0]->upperDenominator);

        $extractedRationalRoot = Math::isolatePolynomialRootsInRationalInterval(
            'x^4 + 2*x^3 - 5*x^2 - 4*x + 6 = 0',
            'x',
            '0',
            '2',
            maximumWidth: '2',
        );
        self::assertNotNull($extractedRationalRoot);
        self::assertCount(2, $extractedRationalRoot);
        self::assertTrue($extractedRationalRoot[0]->isExactRoot);
        self::assertSame('1', $extractedRationalRoot[0]->lowerNumerator);
        self::assertSame('5', $extractedRationalRoot[1]->lowerNumerator);
        self::assertSame('4', $extractedRationalRoot[1]->lowerDenominator);

        $cancelledPole = Math::isolatePolynomialRootsInRationalInterval(
            '((x - 1) * (x^2 - 2)) / (x - 1) = 0',
            'x',
            '0',
            '2',
            maximumWidth: '2',
        );
        self::assertNotNull($cancelledPole);
        self::assertCount(1, $cancelledPole);
        self::assertSame('5', $cancelledPole[0]->lowerNumerator);
        self::assertSame('4', $cancelledPole[0]->lowerDenominator);
        self::assertSame('3', $cancelledPole[0]->upperNumerator);
        self::assertSame('2', $cancelledPole[0]->upperDenominator);
    }

    public function testExactPolynomialRootCountingCanCoverTheEntireRealLine(): void
    {
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^3 - x = 0', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('(x - 1)^2 * (x + 1) = 0', 'x'),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^2 + 1 = 0', 'x'));
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x) = 0', 'x');
    }

    public function testExactPolynomialRootIsolationCanCoverTheEntireRealLine(): void
    {
        $intervals = Math::isolatePolynomialRootsOnRealLine(
            'x^2 - 2 = 0',
            'x',
            maximumWidth: '1/1000',
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        self::assertLessThan('0', $intervals[0]->upperNumerator);
        self::assertGreaterThan('0', $intervals[1]->lowerNumerator);
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^2 - 2 = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('x^2 + 1 = 0', 'x'));
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('sin(x) = 0', 'x');
    }

    public function testAllRealRationalEquationRootsExcludeDenominatorHoles(): void
    {
        $equation = '(x^2 - 1) / (x - 1) = 0';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine(
            $equation,
            'x',
            maximumWidth: '1/100',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[0]->lowerDenominator);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('1 / (x - 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x - 1) / (x - 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('1 / x^2 = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('1 / (1 / x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(1 / x)^(-1) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('1 / (1 / x) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('(1 / x)^(-1) = 0', 'x'));
    }

    public function testAllRealRationalIdentityStillReportsInfiniteSolutions(): void
    {
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('(x - 1) / (x - 1) = 1', 'x');
    }

    public function testExactPolynomialRootCountingRejectsIdentitiesAndVariableIndependentEquations(): void
    {
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInInterval('x^2 = x^2', 'x', -1.0, 1.0);
    }

    public function testExactPolynomialRootCountingRejectsMissingVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Math::countPolynomialRootsInInterval('2 = 3', 'x', -1.0, 1.0);
    }

    public function testExactPolynomialRootCountingAcceptsExactRationalBounds(): void
    {
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('3*x - 1 = 0', 'x', '1/3', '1/2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('3*x - 1 = 0', 'x', '0', '1/2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('x^2 - 1 = 0', 'x', '-3/1', '0/-2'),
        );
    }

    public function testExactPolynomialRootApisSupportMaximumConfiguredDegree(): void
    {
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval(
                'x^256 - 1 = 0',
                'x',
                '-2',
                '2',
                solverOptions: new SolverOptions(maxPolynomialDegree: 256),
            ),
        );

        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            'x^256 - 1 = 0',
            'x',
            '-2',
            '2',
            solverOptions: new SolverOptions(maxPolynomialDegree: 256),
            maximumWidth: '1/100',
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        self::assertSame(['-1', '1', '-1', '1'], [
            $intervals[0]->lowerNumerator,
            $intervals[0]->lowerDenominator,
            $intervals[0]->upperNumerator,
            $intervals[0]->upperDenominator,
        ]);
        self::assertSame(['1', '1', '1', '1'], [
            $intervals[1]->lowerNumerator,
            $intervals[1]->lowerDenominator,
            $intervals[1]->upperNumerator,
            $intervals[1]->upperDenominator,
        ]);
        self::assertTrue($intervals[0]->isExactRoot);
        self::assertTrue($intervals[1]->isExactRoot);
    }

    public function testExactPolynomialRootIsolationReturnsCertifiedRationalIntervals(): void
    {
        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            'x^3 - 2*x = 0',
            'x',
            '-2',
            '2',
        );

        self::assertNotNull($intervals);
        self::assertCount(3, $intervals);
        self::assertTrue($intervals[1]->isExactRoot);
        self::assertSame('0', $intervals[1]->lowerNumerator);
        self::assertSame('0', $intervals[1]->upperNumerator);
        foreach ([$intervals[0], $intervals[2]] as $interval) {
            self::assertFalse($interval->isExactRoot);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval(
                    'x^3 - 2*x = 0',
                    'x',
                    $interval->lowerNumerator . '/' . $interval->lowerDenominator,
                    $interval->upperNumerator . '/' . $interval->upperDenominator,
                ),
            );
        }

        $noSubdivisionBudget = Math::isolatePolynomialRootsInRationalInterval(
            'x^3 - 2*x = 0',
            'x',
            '-2',
            '2',
            maxDepth: 1,
        );
        self::assertNotNull($noSubdivisionBudget);
        self::assertCount(3, $noSubdivisionBudget);
        self::assertTrue($noSubdivisionBudget[1]->isExactRoot);
        foreach ([$noSubdivisionBudget[0], $noSubdivisionBudget[2]] as $interval) {
            self::assertFalse($interval->isExactRoot);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval(
                    'x^3 - 2*x = 0',
                    'x',
                    $interval->lowerNumerator . '/' . $interval->lowerDenominator,
                    $interval->upperNumerator . '/' . $interval->upperDenominator,
                ),
            );
        }
    }

    public function testExactPolynomialRootIsolationRefinesToRequestedRationalWidth(): void
    {
        $intervals = Math::isolatePolynomialRootsInRationalInterval(
            'x^2 - 2 = 0',
            'x',
            '1',
            '2',
            maximumWidth: '1/1000',
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        $interval = $intervals[0];
        self::assertFalse($interval->isExactRoot);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'x^2 - 2 = 0',
                'x',
                $interval->lowerNumerator . '/' . $interval->lowerDenominator,
                $interval->upperNumerator . '/' . $interval->upperDenominator,
            ),
        );
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $widthNumerator = $integerArithmetic->subtract(
            $integerArithmetic->multiplyIntegerValues(
                $interval->upperNumerator,
                $interval->lowerDenominator,
            ),
            $integerArithmetic->multiplyIntegerValues(
                $interval->lowerNumerator,
                $interval->upperDenominator,
            ),
        );
        $widthDenominator = $integerArithmetic->multiplyIntegerValues(
            $interval->upperDenominator,
            $interval->lowerDenominator,
        );
        self::assertLessThanOrEqual(
            0,
            $integerArithmetic->compareIntegerValues(
                $integerArithmetic->multiplyIntegerValues($widthNumerator, '1000'),
                $widthDenominator,
            ),
        );

        self::assertNull(Math::isolatePolynomialRootsInRationalInterval(
            'x^2 - 2 = 0',
            'x',
            '1',
            '2',
            maximumWidth: '0',
        ));
    }

    public function testRegisteredFunctionTakesPrecedenceOverSameNamedVariable(): void
    {
        $function = new FunctionDefinition(
            'scale',
            1,
            1,
            static fn (array $arguments): int|float => 3 * $arguments[0],
        );

        self::assertSame(
            6,
            Math::evaluate(
                'scale(2)',
                ['scale' => 10],
                self::optionsWith($function),
            ),
        );
    }

    public function testImplicitVariableProductUsesTheExactProductRule(): void
    {
        self::assertSame(5.0, Math::differentiate('x(x + 1)', 'x', 2.0));
        self::assertSame(
            1.5,
            Math::differentiate('scale(x + 1)', 'x', 2.0, ['scale' => 1.5]),
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

    public function testCustomFunctionCanSignalAnUndefinedDomain(): void
    {
        $function = new FunctionDefinition(
            'rightdomain',
            1,
            1,
            static function (array $arguments): float {
                if ($arguments[0] <= 0.123) {
                    throw new FunctionDomainException('outside domain');
                }

                return $arguments[0] - 0.123;
            },
        );

        try {
            Math::evaluate('rightdomain(0.1)', options: self::optionsWith($function));
            self::fail('The custom function should reject the input domain.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function_domain', $error->errorCode());
            self::assertSame('outside domain at position 0', $error->getMessage());
            self::assertInstanceOf(FunctionDomainException::class, $error->getPrevious());
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
