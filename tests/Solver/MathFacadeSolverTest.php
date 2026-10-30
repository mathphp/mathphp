<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

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

final class MathFacadeSolverTest extends TestCase
{
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


    public function testImplicitVariableProductUsesTheExactProductRule(): void
    {
        self::assertSame(5.0, Math::differentiate('x(x + 1)', 'x', 2.0));
        self::assertSame(
            1.5,
            Math::differentiate('scale(x + 1)', 'x', 2.0, ['scale' => 1.5]),
        );
    }
}
