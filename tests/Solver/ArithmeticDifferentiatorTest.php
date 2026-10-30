<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Solver\ArithmeticDifferentiator;
use PHPUnit\Framework\TestCase;

final class ArithmeticDifferentiatorTest extends TestCase
{
    public function testTruncationDerivativeIsZeroAwayFromNonzeroIntegerJumps(): void
    {
        self::assertSame(0.0, \MathPHP\Math::differentiate('trunc(x)', 'x', -0.5));
        self::assertSame(0.0, \MathPHP\Math::differentiate('trunc(x)', 'x', 0.0));
        self::assertSame(0.0, \MathPHP\Math::differentiate('trunc(x)', 'x', 0.5));

        $detectedJumps = 0;
        foreach ([-2.0, -1.0, 1.0, 2.0] as $boundary) {
            try {
                \MathPHP\Math::differentiate('trunc(x)', 'x', $boundary);
                self::fail('trunc() is discontinuous at each nonzero integer.');
            } catch (\DomainException) {
                ++$detectedJumps;
            }
        }
        self::assertSame(4, $detectedJumps);
    }

    public function testUnrepresentableAnalyticSlopeReturnsNullForNumericalFallback(): void
    {
        $node = (new Parser((new Lexer('cot(x)'))->tokenize()))->parse();
        $derivative = (new ArithmeticDifferentiator())->differentiate(
            $node,
            'x',
            1.0E-200,
            [],
            new EvaluationOptions(),
        );

        self::assertNull($derivative);
    }

    public function testArcTrigonometricAliasesHaveAnalyticDerivatives(): void
    {
        self::assertEqualsWithDelta(
            1.0 / \sqrt(1.0 - (0.4 ** 2)),
            \MathPHP\Math::differentiate('arcsin(x)', 'x', 0.4),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            -1.0 / \sqrt(1.0 - (0.4 ** 2)),
            \MathPHP\Math::differentiate('arccos(x)', 'x', 0.4),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            1.0 / (1.0 + (0.4 ** 2)),
            \MathPHP\Math::differentiate('arctan(x)', 'x', 0.4),
            1.0E-14,
        );
    }

    public function testInverseHyperbolicAliasesHaveAnalyticDerivatives(): void
    {
        self::assertEqualsWithDelta(
            1.0 / \hypot(1.0, 0.4),
            \MathPHP\Math::differentiate('arcsinh(x)', 'x', 0.4),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            1.0 / \sqrt((1.4 - 1.0) * (1.4 + 1.0)),
            \MathPHP\Math::differentiate('arccosh(x)', 'x', 1.4),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            1.0 / (1.0 - (0.4 ** 2)),
            \MathPHP\Math::differentiate('arctanh(x)', 'x', 0.4),
            1.0E-14,
        );
    }

    public function testReciprocalInverseFunctionDerivativesMatchFiniteDifferences(): void
    {
        foreach (
            [
                ['asec(x)', [-3.0, -1.4, 1.4, 3.0]],
                ['acsc(x)', [-3.0, -1.4, 1.4, 3.0]],
                ['asech(x)', [0.2, 0.4, 0.8]],
                ['acsch(x)', [-3.0, -0.4, 0.4, 3.0]],
                ['acoth(x)', [-3.0, -1.4, 1.4, 3.0]],
            ] as [$expression, $points]
        ) {
            foreach ($points as $at) {
                $step = 1.0E-6 * \max(1.0, \abs($at));
                $upper = \MathPHP\Math::evaluate(
                    \str_replace('x', \sprintf('%.17g', $at + $step), $expression),
                );
                $lower = \MathPHP\Math::evaluate(
                    \str_replace('x', \sprintf('%.17g', $at - $step), $expression),
                );
                $finiteDifference = ($upper - $lower) / (2.0 * $step);
                $derivative = \MathPHP\Math::differentiate($expression, 'x', $at);

                self::assertEqualsWithDelta(
                    $finiteDifference,
                    $derivative,
                    1.0E-6 * \max(1.0, \abs($finiteDifference)),
                    $expression . ' at ' . $at,
                );
            }
        }
    }

    public function testInverseErrorFunctionDerivativesUseTheirInverseValues(): void
    {
        foreach (
            [
                ['erfinv(0.5*x)', 0.8, 0.4],
                ['erfcinv(0.5*x)', 0.8, 0.4],
            ] as [$expression, $at, $argument]
        ) {
            $inverse = $expression === 'erfinv(0.5*x)'
                ? SpecialFunctions::inverseErf($argument)
                : SpecialFunctions::inverseErfc($argument);
            $sign = $expression === 'erfinv(0.5*x)' ? 1.0 : -1.0;
            $expected = $sign * (\sqrt(\M_PI) / 2.0) * \exp($inverse * $inverse) * 0.5;

            self::assertEqualsWithDelta($expected, \MathPHP\Math::differentiate($expression, 'x', $at), 1.0E-14);
        }
    }

    public function testImaginaryErrorFunctionHasItsAnalyticDerivative(): void
    {
        foreach ([-2.0, 0.0, 1.0, 3.0] as $at) {
            self::assertEqualsWithDelta(
                (2.0 / \sqrt(\M_PI)) * \exp($at * $at),
                \MathPHP\Math::differentiate('erfi(x)', 'x', $at),
                \abs((2.0 / \sqrt(\M_PI)) * \exp($at * $at)) * 2.0E-14,
            );
        }
    }

    public function testDawsonDerivativeUsesItsDifferentialEquation(): void
    {
        foreach ([-10.0, -1.0, 0.0, 1.0, 10.0, 1.0E200] as $at) {
            $value = SpecialFunctions::dawson($at);

            self::assertEqualsWithDelta(
                1.0 - (2.0 * ($at * $value)),
                \MathPHP\Math::differentiate('dawson(x)', 'x', $at),
                2.0E-14,
            );
        }
    }

    public function testScaledComplementaryErrorFunctionDerivativeIsStableInItsTail(): void
    {
        self::assertEqualsWithDelta(
            -2.0 / \sqrt(\M_PI),
            \MathPHP\Math::differentiate('erfcx(x)', 'x', 0.0),
            1.0E-15,
        );

        foreach ([1.0, 8.0, 10.0, 1.0E200] as $at) {
            self::assertEquals(
                SpecialFunctions::erfcxDerivative($at),
                \MathPHP\Math::differentiate('erfcx(x)', 'x', $at),
            );
        }
    }

    public function testTetragammaDerivativeUsesNextPolygammaOrder(): void
    {
        $node = (new Parser((new Lexer('tetragamma(x)'))->tokenize()))->parse();
        $derivative = (new ArithmeticDifferentiator())->differentiate(
            $node,
            'x',
            1.5,
            [],
            new EvaluationOptions(),
        );

        self::assertSame(SpecialFunctions::polygamma(3, 1.5), $derivative);
        $step = 1.0E-5;
        $finiteDifference = (
            SpecialFunctions::tetragamma(1.5 + $step)
            - SpecialFunctions::tetragamma(1.5 - $step)
        ) / (2.0 * $step);
        self::assertEqualsWithDelta($finiteDifference, $derivative, 1.0E-8);
    }

    public function testPolygammaDerivativesAdvanceExactlyOneOrder(): void
    {
        foreach ([0, 1, 2, 3] as $order) {
            foreach ([-0.37, 0.4, 2.3] as $at) {
                $expression = 'polygamma(' . $order . ',x)';
                $derivative = \MathPHP\Math::differentiate($expression, 'x', $at);
                $expected = SpecialFunctions::polygamma($order + 1, $at);
                $step = 1.0E-6 * \max(1.0, \abs($at));
                $finiteDifference = (
                    SpecialFunctions::polygamma($order, $at + $step)
                    - SpecialFunctions::polygamma($order, $at - $step)
                ) / (2.0 * $step);

                self::assertSame($expected, $derivative, $expression . ' at ' . $at);
                self::assertEqualsWithDelta($finiteDifference, $derivative, 1.0E-6, $expression . ' at ' . $at);
            }
        }
    }

    public function testRiemannZetaDerivativeUsesDifferentiatedEtaTransform(): void
    {
        foreach (
            [
                [0.0, -0.9189385332046727],
                [2.0, -0.9375482543158438],
                [4.0, -0.0689112658961255],
            ] as [$at, $expected]
        ) {
            $node = (new Parser((new Lexer('zeta(x)'))->tokenize()))->parse();
            $actual = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                $at,
                [],
                new EvaluationOptions(),
            );

            self::assertEqualsWithDelta($expected, $actual, 2.0E-12);
        }
    }

    public function testRiemannZetaDerivativeUsesStableContinuationNearZero(): void
    {
        foreach (
            [
                [0.0, -0.9189385332046727],
                [-0.001, -0.916935175109833],
                [0.001, -0.920947896020692],
                [0.01, -0.939306383446482],
                [0.1, -1.15416515410975],
            ] as [$at, $expected]
        ) {
            $actual = \MathPHP\Math::differentiate('zeta(x)', 'x', $at);
            self::assertEqualsWithDelta($expected, $actual, 3.0E-12, (string) $at);
            $step = 1.0E-5;
            $upper = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at + $step) . ')');
            $lower = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at - $step) . ')');
            self::assertEqualsWithDelta(($upper - $lower) / (2.0 * $step), $actual, 2.0E-8, (string) $at);
        }
    }

    public function testRiemannZetaDerivativeUsesFunctionalEquationOnNegativeInputs(): void
    {
        foreach (
            [
                [-2.0, -0.03044845705839327],
                [-4.0, 0.00798381145026860],
            ] as [$at, $expected]
        ) {
            $node = (new Parser((new Lexer('zeta(x)'))->tokenize()))->parse();
            $actual = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                $at,
                [],
                new EvaluationOptions(),
            );

            self::assertEqualsWithDelta($expected, $actual, 2.0E-11);
        }

        foreach ([-2.000000001, -1.999999999, -4.000000001, -3.999999999] as $at) {
            $derivative = SpecialFunctions::riemannZetaDerivative($at);
            self::assertNotNull($derivative, (string) $at);
            self::assertTrue(\is_finite($derivative), (string) $at);

            $step = 1.0E-11;
            $upper = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at + $step) . ')');
            $lower = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at - $step) . ')');
            self::assertEqualsWithDelta(($upper - $lower) / (2.0 * $step), $derivative, 2.0E-6, (string) $at);
        }

        foreach ([-1.0E-6, -1.0E-8, -1.0E-10, -1.0E-12] as $at) {
            self::assertEqualsWithDelta(
                -0.9189385332046727,
                SpecialFunctions::riemannZetaDerivative($at),
                3.0E-6,
                (string) $at,
            );
        }

        foreach ([-63.5, -64.0, -70.5, -100.0] as $at) {
            $derivative = \MathPHP\Math::differentiate('zeta(x)', 'x', $at);

            self::assertTrue(\is_finite($derivative));
        }
    }

    public function testRiemannZetaDerivativeAgreesWithFiniteDifferences(): void
    {
        foreach ([-10.5, -3.0, -1.5, 0.0, 0.5, 0.9, 1.1, 1.5, 2.0, 8.0, 40.0] as $at) {
            $node = (new Parser((new Lexer('zeta(x)'))->tokenize()))->parse();
            $actual = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                $at,
                [],
                new EvaluationOptions(),
            );
            $step = 1.0E-5 * \max(1.0, \abs($at));
            $upper = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at + $step) . ')');
            $lower = \MathPHP\Math::evaluate('zeta(' . \sprintf('%.17g', $at - $step) . ')');
            $finiteDifference = ($upper - $lower) / (2.0 * $step);

            self::assertNotNull($actual);
            self::assertEqualsWithDelta($finiteDifference, $actual, 2.0E-6, (string) $at);
        }
    }

    public function testRiemannZetaDerivativeMatchesEvaluatorPoleAndLargeArgumentBranches(): void
    {
        foreach ([0.9995, 1.0005] as $at) {
            $distance = $at - 1.0;
            $expected = -(1.0 / ($distance * $distance))
                + 0.07281584548367672
                - (0.009690363192872318 * $distance);

            self::assertSame($expected, \MathPHP\Math::differentiate('zeta(x)', 'x', $at));
        }

        self::assertSame(0.0, \MathPHP\Math::differentiate('zeta(x)', 'x', 64.0));
        self::assertSame(0.0, \MathPHP\Math::differentiate('zeta(x)', 'x', 100.0));
    }

    public function testRiemannZetaContinuationRejectsOutOfRangeInputs(): void
    {
        foreach ([-1.0, 1.0, \INF, \NAN] as $value) {
            $thrown = false;
            try {
                SpecialFunctions::riemannZetaContinuation($value);
            } catch (\DomainException) {
                $thrown = true;
            }
            self::assertTrue($thrown, 'The eta helper must reject invalid inputs.');
        }
    }

    public function testIncompleteBetaDerivativesUseFiniteEndpointDensityLimits(): void
    {
        foreach (
            [
                ['betainc(1, 3, x)', 0.0, 3.0],
                ['betainc(2, 3, x)', 0.0, 0.0],
                ['betainc(2, 1, x)', 1.0, 2.0],
                ['betaincc(3, 1, x)', 1.0, -3.0],
                ['betaincc(2, 3, x)', 1.0, 0.0],
                ['betainc(x, 3, 0)', 2.0, 0.0],
                ['betaincc(x, 3, 1)', 2.0, 0.0],
                ['betainc(0.5 + x, 3, 0)', 0.0, 0.0],
                ['betaincc(3, 0.5 + x, 1)', 0.0, 0.0],
                ['betainc(1 + x, 3, x)', 0.0, 3.0],
                ['betainc(x, 3, x - 1)', 1.0, 3.0],
            ] as [$expression, $at, $expected]
        ) {
            self::assertSame($expected, \MathPHP\Math::differentiate($expression, 'x', $at));
        }
    }

    public function testIncompleteBetaShapeDerivativesWithAUnitFixedShape(): void
    {
        $x = 0.4;
        $firstShapePartial = $x * \log($x);
        $secondShapePartial = -(1.0 - $x) * \log1p(-$x);

        self::assertEqualsWithDelta(
            $firstShapePartial,
            \MathPHP\Math::differentiate('betainc(1 + x, 1, 0.4)', 'x', 0.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            $secondShapePartial,
            \MathPHP\Math::differentiate('betainc(1, 1 + x, 0.4)', 'x', 0.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            \pow($x, 2.0) * \log($x),
            \MathPHP\Math::differentiate('betainc(2 + x, 1, 0.4)', 'x', 0.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -\pow(1.0 - $x, 2.0) * \log1p(-$x),
            \MathPHP\Math::differentiate('betainc(1, 2 + x, 0.4)', 'x', 0.0),
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            -($firstShapePartial + (2.0 * $secondShapePartial) + 1.0),
            \MathPHP\Math::differentiate('betaincc(1 + x, 1 + 2*x, 0.4 + x)', 'x', 0.0),
            1.0E-12,
        );
        self::assertSame(
            1.0,
            \MathPHP\Math::differentiate('betainc(1 + x, 1, x)', 'x', 0.0),
        );
    }

    public function testIncompleteBetaShapeDerivativesForGeneralPositiveShapes(): void
    {
        foreach (
            [
                'betainc(2.3 + x, 3.7, 0.42)',
                'betainc(2.3, 3.7 + x, 0.42)',
                'betaincc(2.3 + x, 3.7 + 2*x, 0.42 + x)',
                'betainc(2.3 + x, 3.7, 0.98)',
                'betainc(2.3, 3.7 + x, 0.98)',
                'betainc(2.3 + x, 5.4, 0.3402061855670103)',
                'betainc(2.3, 5.4 + x, 0.3402061855670103)',
            ] as $expression
        ) {
            $step = 1.0E-4;
            $evaluate = static fn (float $point): float => (float) \MathPHP\Math::evaluate(
                $expression,
                ['x' => $point],
            );
            $expected = (
                $evaluate(-2.0 * $step)
                - (8.0 * $evaluate(-$step))
                + (8.0 * $evaluate($step))
                - $evaluate(2.0 * $step)
            ) / (12.0 * $step);
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $analyticPathDerivative = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                0.0,
                [],
                new EvaluationOptions(),
            );

            self::assertEqualsWithDelta(
                $expected,
                $analyticPathDerivative,
                1.0E-8,
                $expression . ' should be differentiated through its shape partials',
            );
        }
    }

    public function testIncompleteBetaShapeDerivativesChooseTheSmallerTailForSkewedShapes(): void
    {
        foreach (
            [
                ['betainc(100 + x, 0.1, 0.6)', 'betainc(100 + x, 0.1, 0.6)', 1.0],
                ['betainc(0.1, 100 + x, 0.6)', 'betainc(100 + x, 0.1, 0.4)', -1.0],
            ] as [$expression, $tailExpression, $tailSign]
        ) {
            $step = 1.0E-4;
            $evaluate = static fn (float $point): float => (float) \MathPHP\Math::evaluate(
                $tailExpression,
                ['x' => $point],
            );
            $expected = $tailSign * (
                $evaluate(-2.0 * $step)
                - (8.0 * $evaluate(-$step))
                + (8.0 * $evaluate($step))
                - $evaluate(2.0 * $step)
            ) / (12.0 * $step);
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $shapeDerivative = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                0.0,
                [],
                new EvaluationOptions(),
            );

            self::assertNotSame(0.0, $expected, $expression);
            self::assertEqualsWithDelta(
                $expected,
                $shapeDerivative,
                \abs($expected) * 1.0E-5,
                $expression,
            );
        }
    }

    public function testInverseIncompleteBetaDerivativesUseQuantileDensity(): void
    {
        $density = SpecialFunctions::regularizedBetaDensity(
            2.0,
            3.0,
            SpecialFunctions::inverseRegularizedBeta(2.0, 3.0, 0.4),
        );

        self::assertEqualsWithDelta(
            1.0 / $density,
            \MathPHP\Math::differentiate('betaincinv(2, 3, x)', 'x', 0.4),
            1.0E-10,
        );
        $complementaryDensity = SpecialFunctions::regularizedBetaDensity(
            2.0,
            3.0,
            SpecialFunctions::inverseRegularizedBetaComplement(2.0, 3.0, 0.4),
        );
        self::assertEqualsWithDelta(
            -1.0 / $complementaryDensity,
            \MathPHP\Math::differentiate('betainccinv(2, 3, x)', 'x', 0.4),
            1.0E-10,
        );
    }

    public function testInverseIncompleteBetaShapeDerivativesForGeneralPositiveShapes(): void
    {
        foreach (
            [
                'betaincinv(2.3 + x, 3.7, 0.4)',
                'betaincinv(2.3, 3.7 + x, 0.4)',
                'betainccinv(2.3 + x, 3.7 + 2*x, 0.4 + x)',
                'betaincinv(2.3 + x, 3.7, 0.99)',
                'betainccinv(2.3 + x, 3.7, 0.01)',
            ] as $expression
        ) {
            $step = 1.0E-4;
            $evaluate = static fn (float $point): float => (float) \MathPHP\Math::evaluate(
                $expression,
                ['x' => $point],
            );
            $expected = (
                $evaluate(-2.0 * $step)
                - (8.0 * $evaluate(-$step))
                + (8.0 * $evaluate($step))
                - $evaluate(2.0 * $step)
            ) / (12.0 * $step);
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $implicitDerivative = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                0.0,
                [],
                new EvaluationOptions(),
            );

            self::assertEqualsWithDelta(
                $expected,
                $implicitDerivative,
                1.0E-8,
                $expression . ' should be differentiated implicitly',
            );
        }
    }

    public function testIncompleteBetaDensityStillRejectsUnboundedEndpointLimits(): void
    {
        foreach ([[0.5, 2.0, 0.0], [2.0, 0.5, 1.0]] as [$first, $second, $at]) {
            $thrown = false;
            try {
                SpecialFunctions::regularizedBetaDensity($first, $second, $at);
            } catch (\DomainException) {
                $thrown = true;
            }

            self::assertTrue($thrown);
        }
    }

    public function testIncompleteGammaIsConstantInItsShapeAtZeroArgument(): void
    {
        self::assertSame(0.0, \MathPHP\Math::differentiate('gammainc(1 + x, 0)', 'x', 2.0));
        self::assertSame(0.0, \MathPHP\Math::differentiate('gammaincc(1 + x, 0)', 'x', 2.0));
    }

    public function testIncompleteGammaShapeDerivativesAtUnitShape(): void
    {
        $step = 1.0E-5;
        foreach ([1.0E-4, 0.1, 1.0, 3.0, 10.0] as $value) {
            $finiteDifference = (
                SpecialFunctions::regularizedGammaP(1.0 + $step, $value)
                - SpecialFunctions::regularizedGammaP(1.0 - $step, $value)
            ) / (2.0 * $step);
            $analytic = SpecialFunctions::regularizedGammaShapeDerivativeAtOne($value);
            self::assertEqualsWithDelta($finiteDifference, $analytic, 2.0E-8);
            self::assertEqualsWithDelta(
                $analytic,
                \MathPHP\Math::differentiate('gammainc(1 + x, value)', 'x', 0.0, ['value' => $value]),
                1.0E-12,
            );
            self::assertEqualsWithDelta(
                -$analytic,
                \MathPHP\Math::differentiate('gammaincc(1 + x, value)', 'x', 0.0, ['value' => $value]),
                1.0E-12,
            );
        }

        $shapeDerivative = SpecialFunctions::regularizedGammaShapeDerivativeAtOne(2.0);
        $density = SpecialFunctions::regularizedGammaDensity(1.0, 2.0);
        self::assertEqualsWithDelta(
            $shapeDerivative + $density,
            \MathPHP\Math::differentiate('gammainc(1 + x, 2 + x)', 'x', 0.0),
            1.0E-12,
        );
        self::assertSame(1.0, \MathPHP\Math::differentiate('gammainc(1 + x, x)', 'x', 0.0));
    }

    public function testIncompleteGammaShapeDerivativesAtPositiveIntegerShapes(): void
    {
        $step = 1.0E-5;
        foreach ([2, 3, 5, 10] as $shape) {
            foreach ([0.1, 1.0, 3.0, 10.0] as $value) {
                $finiteDifference = (
                    SpecialFunctions::regularizedGammaP($shape + $step, $value)
                    - SpecialFunctions::regularizedGammaP($shape - $step, $value)
                ) / (2.0 * $step);
                $analytic = SpecialFunctions::regularizedGammaShapeDerivativeAtPositiveInteger(
                    (float) $shape,
                    $value,
                );
                self::assertEqualsWithDelta($finiteDifference, $analytic, 2.0E-8);
                self::assertEqualsWithDelta(
                    $analytic,
                    \MathPHP\Math::differentiate(
                        \sprintf('gammainc(%d + x, value)', $shape),
                        'x',
                        0.0,
                        ['value' => $value],
                    ),
                    1.0E-13,
                );
                self::assertEqualsWithDelta(
                    -$analytic,
                    \MathPHP\Math::differentiate(
                        \sprintf('gammaincc(%d + x, value)', $shape),
                        'x',
                        0.0,
                        ['value' => $value],
                    ),
                    1.0E-13,
                );
            }
        }
    }

    public function testIncompleteGammaShapeDerivativesAtFractionalShapes(): void
    {
        $step = 1.0E-5;
        foreach ([0.25, 0.5, 0.75, 1.5, 2.5, 4.25] as $shape) {
            foreach ([0.01, 0.5, 2.0, 10.0] as $value) {
                $finiteDifference = (
                    SpecialFunctions::regularizedGammaP($shape + $step, $value)
                    - SpecialFunctions::regularizedGammaP($shape - $step, $value)
                ) / (2.0 * $step);
                $analytic = SpecialFunctions::regularizedGammaShapeDerivative($shape, $value);
                self::assertEqualsWithDelta($finiteDifference, $analytic, 3.0E-8);
                self::assertEqualsWithDelta(
                    $analytic,
                    \MathPHP\Math::differentiate(
                        \sprintf('gammainc(%.2f + x, value)', $shape),
                        'x',
                        0.0,
                        ['value' => $value],
                    ),
                    1.0E-13,
                );
            }
        }
    }

    public function testInverseIncompleteGammaDerivativesUseQuantileDensity(): void
    {
        $lowerQuantile = SpecialFunctions::inverseRegularizedGammaP(2.0, 0.4);
        $upperQuantile = SpecialFunctions::inverseRegularizedGammaQ(2.0, 0.4);
        $lowerDensity = SpecialFunctions::regularizedGammaDensity(2.0, $lowerQuantile);
        $upperDensity = SpecialFunctions::regularizedGammaDensity(2.0, $upperQuantile);

        self::assertEqualsWithDelta(
            1.0 / $lowerDensity,
            \MathPHP\Math::differentiate('gammaincinv(2, x)', 'x', 0.4),
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            -1.0 / $upperDensity,
            \MathPHP\Math::differentiate('gammainccinv(2, x)', 'x', 0.4),
            1.0E-10,
        );
    }

    public function testInverseIncompleteGammaShapeDerivativesUseImplicitDifferentiation(): void
    {
        foreach (
            [
                'gammaincinv(2.3 + x, 0.4)',
                'gammainccinv(2.3 + x, 0.4)',
                'gammainccinv(2.3 + x, 0.4 + x)',
                'gammaincinv(0.35 + x, 0.01)',
                'gammaincinv(5.2 + x, 0.99)',
                'gammainccinv(0.35 + x, 0.01)',
                'gammainccinv(8.2 + x, 0.99)',
            ] as $expression
        ) {
            $step = 1.0E-4;
            $evaluate = static fn (float $point): float => (float) \MathPHP\Math::evaluate(
                $expression,
                ['x' => $point],
            );
            $expected = (
                $evaluate(-2.0 * $step)
                - (8.0 * $evaluate(-$step))
                + (8.0 * $evaluate($step))
                - $evaluate(2.0 * $step)
            ) / (12.0 * $step);
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $implicitDerivative = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                0.0,
                [],
                new EvaluationOptions(),
            );

            self::assertEqualsWithDelta(
                $expected,
                $implicitDerivative,
                1.0E-8,
                $expression . ' should be differentiated implicitly',
            );
        }
    }

    public function testBesselDerivativesMatchAdjacentOrderIdentities(): void
    {
        foreach (
            [
                ['besselj(0, x)', 0.7],
                ['besselj(1, x)', -0.7],
                ['besselj(-256, x)', -2.5],
                ['besselj(256, x)', 2.5],
                ['bessely(0, x)', 0.7],
                ['bessely(1, x)', 1.1],
                ['bessely(-3, x)', 2.5],
                ['bessely(256, x)', 300.0],
                ['bessely(-256, x)', 300.0],
                ['besseli(0, x)', -0.7],
                ['besseli(-1, x)', -0.7],
                ['besseli(256, x)', 2.5],
            ] as [$expression, $at]
        ) {
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $actual = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                $at,
                [],
                new EvaluationOptions(),
            );
            $registry = FunctionRegistry::defaults();
            $limits = (new EvaluationOptions())->limits;
            $step = 1.0E-5;
            $plus = (new Evaluator(
                new Environment(['x' => $at + $step], $registry),
                $limits,
            ))->evaluate($node);
            $minus = (new Evaluator(
                new Environment(['x' => $at - $step], $registry),
                $limits,
            ))->evaluate($node);
            $finiteDifference = ($plus - $minus) / (2.0 * $step);

            self::assertNotNull($actual, $expression);
            self::assertEqualsWithDelta($finiteDifference, $actual, 2.0E-7, $expression);
        }

        foreach (
            [
                ['besselj(1, x)', 0.5],
                ['besselj(-1, x)', -0.5],
                ['besselj(0, x)', 0.0],
                ['besseli(1, x)', 0.5],
                ['besseli(-1, x)', 0.5],
                ['besseli(0, x)', 0.0],
                ['besseli(256, x)', 0.0],
            ] as [$expression, $expected]
        ) {
            $node = (new Parser((new Lexer($expression))->tokenize()))->parse();
            $actual = (new ArithmeticDifferentiator())->differentiate(
                $node,
                'x',
                0.0,
                [],
                new EvaluationOptions(),
            );

            self::assertSame($expected, $actual, $expression);
        }
    }

    public function testMaximumOrderModifiedBesselDerivativeMatchesItsStableRecurrence(): void
    {
        $node = (new Parser((new Lexer('besseli(256, x)'))->tokenize()))->parse();
        $differentiator = new ArithmeticDifferentiator();
        foreach ([-713.0, -700.0, 700.0, 713.0] as $argument) {
            $actual = $differentiator->differentiate(
                $node,
                'x',
                $argument,
                [],
                new EvaluationOptions(),
            );
            $expected = SpecialFunctions::besselI(255, $argument)
                - ((256.0 / $argument) * SpecialFunctions::besselI(256, $argument));

            self::assertNotNull($actual);
            self::assertEqualsWithDelta($expected, $actual, \abs($expected) * 1.0E-13);
        }
    }
}
