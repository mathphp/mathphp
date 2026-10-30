<?php

declare(strict_types=1);

namespace MathPHP\Tests\Function;

use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Function\SpecialFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionRegistryTest extends TestCase
{
    /**
     * @param list<int|float> $arguments
     */
    #[DataProvider('builtInSuccessProvider')]
    public function testDefaultAllowlistFunctionsProduceSpecifiedResults(
        string $name,
        array $arguments,
        int|float $expected,
        string $expectedType,
    ): void {
        $registry = FunctionRegistry::defaults();
        $definition = $registry->find($name);

        self::assertInstanceOf(FunctionDefinition::class, $definition);
        self::assertSame($name, $definition->name);
        self::assertTrue($registry->isBuiltIn($name));

        $actual = $definition->invoke($arguments);

        self::assertSame($expectedType, \get_debug_type($actual));
        if (\is_float($expected)) {
            self::assertEqualsWithDelta($expected, $actual, 1.0E-12);
        } else {
            self::assertSame($expected, $actual);
        }
    }

    public function testGammaRemainsFiniteAtItsDeclaredUpperArgumentLimit(): void
    {
        $registry = FunctionRegistry::defaults();
        $gamma = $registry->find('gamma');
        $logGamma = $registry->find('lgamma');

        self::assertInstanceOf(FunctionDefinition::class, $gamma);
        self::assertInstanceOf(FunctionDefinition::class, $logGamma);
        $at169 = $gamma->invoke([169]);
        $at170 = $gamma->invoke([170]);
        $at171 = $gamma->invoke([171]);

        self::assertIsFloat($at169);
        self::assertIsFloat($at170);
        self::assertIsFloat($at171);
        self::assertTrue(\is_finite($at171));
        self::assertEqualsWithDelta(170.0, $at171 / $at170, 1.0E-10);
        self::assertEqualsWithDelta(\log($at171), $logGamma->invoke([171]), 1.0E-12);
    }

    public function testLogGammaRemainsAvailableBeyondGammaOverflowLimit(): void
    {
        $registry = FunctionRegistry::defaults();
        $gamma = $registry->find('gamma');
        $logGamma = $registry->find('lgamma');

        self::assertInstanceOf(FunctionDefinition::class, $gamma);
        self::assertInstanceOf(FunctionDefinition::class, $logGamma);
        self::assertGreaterThan(700.0, $logGamma->invoke([175]));
        self::assertEqualsWithDelta(\log(174.0), $logGamma->invoke([175]) - $logGamma->invoke([174]), 1.0E-12);
        $this->expectException(\DomainException::class);
        $gamma->invoke([175]);
    }

    public function testRegularizedIncompleteGammaFunctionsMatchIdentitiesAndComplement(): void
    {
        $p = FunctionRegistry::defaults()->find('gammainc');
        $q = FunctionRegistry::defaults()->find('gammaincc');
        self::assertInstanceOf(FunctionDefinition::class, $p);
        self::assertInstanceOf(FunctionDefinition::class, $q);

        foreach ([0.01, 0.1, 0.5, 1.0, 2.0, 10.0, 100.0] as $shape) {
            foreach ([0.01, 0.5, 2.0, 10.0, 100.0] as $value) {
                $lower = $p->invoke([$shape, $value]);
                $upper = $q->invoke([$shape, $value]);
                self::assertEqualsWithDelta(1.0, $lower + $upper, 2.0E-14);
                self::assertGreaterThanOrEqual(0.0, $lower);
                self::assertLessThanOrEqual(1.0, $lower);
            }
        }

        self::assertEqualsWithDelta(1.0 - \exp(-2.0), $p->invoke([1, 2]), 2.0E-15);
        $erf = FunctionRegistry::defaults()->find('erf');
        self::assertInstanceOf(FunctionDefinition::class, $erf);
        self::assertEqualsWithDelta($erf->invoke([1.0]), $p->invoke([0.5, 1]), 2.0E-15);
        self::assertSame(0.0, $p->invoke([2, 0]));
        self::assertSame(1.0, $q->invoke([2, 0]));
    }

    public function testRegularizedIncompleteGammaFunctionsRejectUnsupportedDomains(): void
    {
        foreach (['gammainc', 'gammaincc'] as $name) {
            $definition = FunctionRegistry::defaults()->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            foreach ([[0, 1], [-1, 1], [1, -1]] as $arguments) {
                try {
                    $definition->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' must reject invalid or non-finite arguments.');
            }
            try {
                $definition->invoke([1, \INF]);
                self::fail($name . ' must reject non-finite arguments.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testRegularizedGammaUpperTailPreservesTinyShapeProbabilities(): void
    {
        foreach (
            [
                [0.1, 1.8229239584193906],
                [0.5, 0.5597735947761608],
                [0.9, 0.26018393932599965],
                [1.0, 0.21938393439552027],
            ] as [$value, $expectedE1]
        ) {
            $shape = 1.0E-12;
            $upperTail = SpecialFunctions::regularizedGammaQ($shape, $value);

            self::assertGreaterThan(0.0, $upperTail);
            self::assertEqualsWithDelta(
                $expectedE1,
                $upperTail / $shape,
                $expectedE1 * 2.0E-11,
            );
        }
    }

    public function testRegularizedGammaUpperTailRetainsSmallShapeCorrections(): void
    {
        $shape = 1.0E-8;
        $upperTail = SpecialFunctions::regularizedGammaQ($shape, 0.5);
        $fallbackTail = SpecialFunctions::regularizedGammaQ(1.0E-12, 0.005000001);

        self::assertEqualsWithDelta(
            0.55977359770995516,
            $upperTail / $shape,
            2.0E-15,
        );
        self::assertEqualsWithDelta(
            4.7260952595819674,
            $fallbackTail / 1.0E-12,
            2.0E-14,
        );
    }

    public function testInverseRegularizedGammaFunctionsRoundTripBothTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $lower = $registry->find('gammaincinv');
        $upper = $registry->find('gammainccinv');
        self::assertInstanceOf(FunctionDefinition::class, $lower);
        self::assertInstanceOf(FunctionDefinition::class, $upper);

        foreach ([0.01, 0.5, 1.0, 3.5, 20.0] as $shape) {
            $probabilities = $shape === 0.01
                ? [1.0E-3, 0.01, 0.1, 0.5, 0.9]
                : [1.0E-12, 1.0E-6, 0.1, 0.5, 0.9, 1.0 - 1.0E-12];
            foreach ($probabilities as $probability) {
                $lowerQuantile = $lower->invoke([$shape, $probability]);
                $upperQuantile = $upper->invoke([$shape, $probability]);
                self::assertEqualsWithDelta(
                    $probability,
                    SpecialFunctions::regularizedGammaP($shape, $lowerQuantile),
                    2.0E-12,
                );
                self::assertEqualsWithDelta(
                    $probability,
                    SpecialFunctions::regularizedGammaQ($shape, $upperQuantile),
                    2.0E-12,
                );
            }
        }
        self::assertSame(0.0, $lower->invoke([2.0, 0.0]));
        self::assertSame(0.0, $upper->invoke([2.0, 1.0]));
    }

    public function testInverseRegularizedGammaPreservesMinimumSubnormalProbability(): void
    {
        $probability = 5.0E-324;
        foreach ([1.0, 1.5, 2.0, 10.0] as $shape) {
            $lowerQuantile = SpecialFunctions::inverseRegularizedGammaP($shape, $probability);
            $upperQuantile = SpecialFunctions::inverseRegularizedGammaQ($shape, $probability);

            self::assertGreaterThan(0.0, $lowerQuantile);
            self::assertGreaterThan(0.0, $upperQuantile);
            self::assertSame($probability, SpecialFunctions::regularizedGammaP($shape, $lowerQuantile));
            self::assertSame($probability, SpecialFunctions::regularizedGammaQ($shape, $upperQuantile));
        }
    }

    public function testInverseRegularizedGammaRejectsInfiniteQuantileEndpoints(): void
    {
        foreach ([['gammaincinv', 1.0], ['gammainccinv', 0.0]] as [$name, $probability]) {
            $definition = FunctionRegistry::defaults()->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke([2.0, $probability]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' must reject its infinite quantile endpoint.');
        }
    }

    public function testInverseRegularizedGammaRejectsQuantilesBelowFloatResolution(): void
    {
        foreach ([['gammaincinv', 1.0E-6], ['gammainccinv', 1.0 - 1.0E-6]] as [$name, $probability]) {
            $definition = FunctionRegistry::defaults()->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke([0.01, $probability]);
            } catch (\DomainException $exception) {
                self::assertStringContainsString('floating-point resolution', $exception->getMessage());
                continue;
            }
            self::fail($name . ' must reject a quantile below positive float resolution.');
        }
    }

    public function testIncompleteGammaUpperTailRetainsTinyPositiveShapes(): void
    {
        $q = FunctionRegistry::defaults()->find('gammaincc');
        self::assertInstanceOf(FunctionDefinition::class, $q);

        $shape = 1.0E-300;
        $tail = $q->invoke([$shape, 1.0]);
        self::assertGreaterThan(0.0, $tail);
        self::assertEqualsWithDelta(
            0.21938393439552027,
            $tail / $shape,
            1.0E-12,
        );
    }

    public function testRegularizedIncompleteBetaMatchesIdentitiesAndSymmetry(): void
    {
        $function = FunctionRegistry::defaults()->find('betainc');
        self::assertInstanceOf(FunctionDefinition::class, $function);

        foreach ([0.01, 0.1, 0.5, 0.9, 0.99] as $value) {
            self::assertEqualsWithDelta($value, $function->invoke([1, 1, $value]), 3.0E-14);
            self::assertEqualsWithDelta(
                (2.0 * \asin(\sqrt($value))) / \M_PI,
                $function->invoke([0.5, 0.5, $value]),
                3.0E-14,
            );
            self::assertEqualsWithDelta(
                1.0,
                $function->invoke([2, 3, $value]) + $function->invoke([3, 2, 1.0 - $value]),
                3.0E-14,
            );
        }
        self::assertEqualsWithDelta(0.5248, $function->invoke([2, 3, 0.4]), 2.0E-14);
        self::assertSame(0.0, $function->invoke([2, 3, 0]));
        self::assertSame(1.0, $function->invoke([2, 3, 1]));
    }

    public function testRegularizedBetaUnitShapeTailsAvoidLogGammaCancellation(): void
    {
        $smallLowerTail = SpecialFunctions::regularizedBeta(1.0, 1.0E8, 1.0E-12);
        $expectedSmallLowerTail = -\expm1(1.0E8 * \log1p(-1.0E-12));
        self::assertEqualsWithDelta($expectedSmallLowerTail, $smallLowerTail, 1.0E-16);

        $largeLowerTail = SpecialFunctions::regularizedBeta(1.0E8, 1.0, 0.99999999);
        $expectedLargeLowerTail = \exp(1.0E8 * \log(0.99999999));
        self::assertEqualsWithDelta($expectedLargeLowerTail, $largeLowerTail, 1.0E-15);

        $smallUpperTail = SpecialFunctions::regularizedBetaComplement(1.0, 1.0E8, 1.0E-12);
        self::assertEqualsWithDelta(\exp(1.0E8 * \log1p(-1.0E-12)), $smallUpperTail, 1.0E-15);

        $smallArgumentDensity = SpecialFunctions::regularizedBetaDensity(1.0, 1.0E8, 1.0E-12);
        self::assertEqualsWithDelta(
            1.0E8 * \exp((1.0E8 - 1.0) * \log1p(-1.0E-12)),
            $smallArgumentDensity,
            1.0E-7,
        );

        $largeArgumentDensity = SpecialFunctions::regularizedBetaDensity(1.0E8, 1.0, 0.99999999);
        self::assertEqualsWithDelta(
            1.0E8 * \exp((1.0E8 - 1.0) * \log(0.99999999)),
            $largeArgumentDensity,
            1.0E-7,
        );
    }

    public function testRegularizedBetaRetainsRepresentableTinyShapeUpperTail(): void
    {
        foreach ([1.0E-12, 1.0E-100, 1.0E-300] as $firstShape) {
            $secondShape = 1.0E8;
            $value = 1.0E-20;
            $expectedTail = SpecialFunctions::regularizedGammaQ(
                $firstShape,
                $secondShape * $value,
            );
            $actualTail = SpecialFunctions::regularizedBetaComplement(
                $firstShape,
                $secondShape,
                $value,
            );

            self::assertGreaterThan(0.0, $actualTail);
            self::assertEqualsWithDelta($expectedTail, $actualTail, $expectedTail * 2.0E-8);
            self::assertSame(
                1.0 - $expectedTail,
                SpecialFunctions::regularizedBeta($firstShape, $secondShape, $value),
            );
            if ($firstShape <= 1.0E-100) {
                self::assertEqualsWithDelta(27.053805451028, $actualTail / $firstShape, 4.0E-7);
            }
        }
    }

    public function testRegularizedBetaLargeShapeTailJoinsContinuously(): void
    {
        $firstShape = 1.0E-100;
        $secondShape = 1.0E8;
        $atBoundary = SpecialFunctions::regularizedBetaComplement(
            $firstShape,
            $secondShape,
            1.0E-8,
        );
        $belowBoundary = SpecialFunctions::regularizedBetaComplement(
            $firstShape,
            $secondShape,
            0.999E-8,
        );
        $aboveBoundary = SpecialFunctions::regularizedBetaComplement(
            $firstShape,
            $secondShape,
            1.001E-8,
        );

        self::assertGreaterThan($aboveBoundary, $atBoundary);
        self::assertGreaterThan($atBoundary, $belowBoundary);
        self::assertEqualsWithDelta(
            1.0E-100 * 0.21938393439552027,
            $atBoundary,
            3.0E-109,
        );
    }

    public function testRegularizedIncompleteBetaRejectsValuesOutsideItsDomain(): void
    {
        foreach (['betainc', 'betaincc'] as $name) {
            $function = FunctionRegistry::defaults()->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            foreach ([[0, 1, 0.5], [1, 0, 0.5], [1, 1, -0.1], [1, 1, 1.1]] as $arguments) {
                try {
                    $function->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' must reject invalid shape and value arguments.');
            }
        }
    }

    public function testRegularizedIncompleteBetaComplementRetainsTinyUpperTail(): void
    {
        $lower = FunctionRegistry::defaults()->find('betainc');
        $upper = FunctionRegistry::defaults()->find('betaincc');
        self::assertInstanceOf(FunctionDefinition::class, $lower);
        self::assertInstanceOf(FunctionDefinition::class, $upper);

        $value = 1.0 - 1.0E-12;
        $tail = $upper->invoke([2, 3, $value]);
        self::assertSame(1.0, $lower->invoke([2, 3, $value]));
        self::assertGreaterThan(0.0, $tail);
        self::assertEqualsWithDelta(
            ((1.0 - $value) ** 3) * (1.0 + (3.0 * $value)),
            $tail,
            1.0E-48,
        );

        foreach ([0.01, 0.1, 0.5, 0.9, 0.99] as $x) {
            self::assertEqualsWithDelta(
                1.0,
                $lower->invoke([2, 3, $x]) + $upper->invoke([2, 3, $x]),
                3.0E-14,
            );
        }
    }

    public function testBetaRejectsPolesAndSupportsLargePositiveArguments(): void
    {
        $beta = FunctionRegistry::defaults()->find('beta');
        self::assertInstanceOf(FunctionDefinition::class, $beta);

        foreach ([[0, 1], [-1, 2], [-172.5, 1]] as $arguments) {
            try {
                $beta->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('beta() must reject poles and arguments below its negative real cutoff.');
        }

        self::assertEqualsWithDelta(1.0 / 172.0, $beta->invoke([172, 1]), 1.0E-15);
        self::assertEqualsWithDelta(1.0 / (172.0 * 173.0), $beta->invoke([172, 2]), 1.0E-16);
        self::assertEqualsWithDelta(2.0 / (200.0 * 201.0 * 202.0), $beta->invoke([200, 3]), 1.0E-17);
        self::assertEqualsWithDelta(1.0E-32, $beta->invoke([1.0E16, 2]), 1.0E-46);
        self::assertEqualsWithDelta(1.0E-32, $beta->invoke([2, 1.0E16]), 1.0E-46);
    }

    public function testBetaUsesTheExactArgumentSumNearIntegerPolesAndRangeLimit(): void
    {
        $beta = FunctionRegistry::defaults()->find('beta');
        self::assertInstanceOf(FunctionDefinition::class, $beta);

        $nearPole = $beta->invoke([-169.5, -0.49999999999999]);
        self::assertNotSame(0.0, $nearPole);
        self::assertTrue(\is_finite($nearPole));

        try {
            $beta->invoke([-70.50000000000001, -100.5]);
            self::fail('A non-integer sum below -171 must remain outside beta() domain.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'beta() argument sum is outside the supported real range.',
                $exception->getMessage(),
            );
        }
    }

    public function testReciprocalTrigonometricFunctionsRejectPolesButKeepNearbyInputs(): void
    {
        $registry = FunctionRegistry::defaults();
        foreach (
            [
                ['tan', [1.5707963267948963]],
                ['sec', [1.5707963267948963]],
                ['csc', [5.0E-16]],
                ['cot', [5.0E-16]],
            ] as [$name, $arguments]
        ) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            self::assertTrue(\is_finite($function->invoke($arguments)));
        }
    }

    public function testTangentRecognizesHighOrderCanonicalPoleValues(): void
    {
        $tangent = FunctionRegistry::defaults()->find('tan');
        self::assertInstanceOf(FunctionDefinition::class, $tangent);

        foreach ([1001.0, -1001.0] as $oddMultiple) {
            try {
                $tangent->invoke([($oddMultiple * \M_PI) / 2.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Odd half-integer multiples of pi must be tangent poles.');
        }
    }

    public function testRiemannZetaNearItsPoleUsesFiniteLaurentValues(): void
    {
        $zeta = FunctionRegistry::defaults()->find('zeta');
        self::assertInstanceOf(FunctionDefinition::class, $zeta);

        foreach ([0.9999, 0.999999, 1.000001, 1.0001] as $value) {
            $result = $zeta->invoke([$value]);
            $distance = $value - 1.0;
            $expected = (1.0 / $distance)
                + 0.5772156649015329
                + (0.07281584548367672 * $distance)
                - (0.004845181596436159 * ($distance ** 2));
            self::assertTrue(\is_finite($result));
            self::assertEqualsWithDelta($expected, $result, 1.0E-8);
        }

        foreach ([1] as $invalidValue) {
            try {
                $zeta->invoke([$invalidValue]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('The Riemann zeta function must reject its pole and unsupported values.');
        }
    }

    public function testRiemannZetaUsesStableContinuationNearZero(): void
    {
        $zeta = FunctionRegistry::defaults()->find('zeta');
        self::assertInstanceOf(FunctionDefinition::class, $zeta);

        foreach (
            [
                [-0.001, -0.499082063645291],
                [-0.5, -0.20788622497735457],
                [0.0, -0.5],
                [0.001, -0.500919942713218],
                [0.01, -0.509290714039839],
                [0.1, -0.603037519856240],
                [0.499999, -1.46035058617145],
                [0.5, -1.460354508809583],
            ] as [$argument, $expected]
        ) {
            self::assertEqualsWithDelta($expected, $zeta->invoke([$argument]), 2.0E-12, (string) $argument);
        }
        self::assertEqualsWithDelta(-0.20788622497735457, $zeta->invoke([-0.5]), 4.0E-15);
    }

    public function testRiemannZetaContinuesBeyondNegativeOneHundredSeventy(): void
    {
        $zeta = FunctionRegistry::defaults()->find('zeta');
        self::assertInstanceOf(FunctionDefinition::class, $zeta);

        $negativeOddValue = $zeta->invoke([-171]);
        self::assertTrue(\is_finite($negativeOddValue));
        self::assertGreaterThan(1.0E171, $negativeOddValue);
        self::assertSame(0.0, $zeta->invoke([-1_000]));
    }

    public function testBesselJUsesStableBackwardRecurrenceBeyondItsPowerSeriesRange(): void
    {
        $definition = FunctionRegistry::defaults()->find('besselj');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        self::assertEqualsWithDelta(0.16702466434058315, $definition->invoke([0, 20.0]), 1.0E-13);
        self::assertEqualsWithDelta(0.06683312417585002, $definition->invoke([1, 20.0]), 1.0E-13);
        self::assertEqualsWithDelta(0.01998585030422322, $definition->invoke([0, 100.0]), 1.0E-12);
        self::assertEqualsWithDelta(0.024786686152420176, $definition->invoke([0, 1_000.0]), 1.0E-15);
        self::assertEqualsWithDelta(-0.015603391100457081, $definition->invoke([64, 1_000.0]), 1.0E-15);
        self::assertEqualsWithDelta(-0.0038819688560433785, $definition->invoke([256, 1_000.0]), 1.0E-15);
        self::assertEqualsWithDelta(
            0.16702466434058315,
            $definition->invoke([0, -20.0]),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            -0.06683312417585002,
            $definition->invoke([1, -20.0]),
            1.0E-13,
        );
    }

    public function testArcCotangentRetainsPrecisionForLargeArguments(): void
    {
        $definition = FunctionRegistry::defaults()->find('acot');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        self::assertEqualsWithDelta(1.0E-16, $definition->invoke([1.0E16]), 1.0E-31);
        self::assertSame(\M_PI, $definition->invoke([-1.0E16]));
        self::assertSame(\M_PI / 2.0, $definition->invoke([0.0]));
    }

    public function testPolygammaRejectsInvalidOrdersAndPoles(): void
    {
        $definition = FunctionRegistry::defaults()->find('polygamma');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        foreach ([[2.5, 1.0], [-1, 1.0], [129, 1.0], [3, -2.0]] as $arguments) {
            try {
                $definition->invoke($arguments);
                self::fail('Expected invalid polygamma arguments to be rejected.');
            } catch (\DomainException) {
                // Each malformed order and pole must fail independently.
            }
        }
    }

    public function testPolygammaSupportsTheConfiguredHighOrderLimit(): void
    {
        $definition = FunctionRegistry::defaults()->find('polygamma');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        $factorial = 1.0;
        for ($factor = 2; $factor <= SpecialFunctions::MAX_POLYGAMMA_ORDER; ++$factor) {
            $factorial *= $factor;
        }
        foreach ([1.0, 0.5, 0.25] as $argument) {
            $result = $definition->invoke([SpecialFunctions::MAX_POLYGAMMA_ORDER, $argument]);
            $expected = -$factorial / ($argument ** (SpecialFunctions::MAX_POLYGAMMA_ORDER + 1));

            self::assertTrue(\is_finite($result));
            self::assertEqualsWithDelta($expected, $result, \abs($expected) * 1.0E-13);
        }
    }

    public function testGammaReflectionStaysStableNearTheNegativeArgumentLimit(): void
    {
        $registry = FunctionRegistry::defaults();
        $gamma = $registry->find('gamma');
        $logGamma = $registry->find('lgamma');

        self::assertInstanceOf(FunctionDefinition::class, $gamma);
        self::assertInstanceOf(FunctionDefinition::class, $logGamma);
        $value = $gamma->invoke([-170.5]);
        $logMagnitude = $logGamma->invoke([-170.5]);

        self::assertIsFloat($value);
        self::assertIsFloat($logMagnitude);
        self::assertTrue(\is_finite($value));
        self::assertLessThan(0.0, $value);
        self::assertEqualsWithDelta(\log(\abs($value)), $logMagnitude, 1.0E-12);
    }

    public function testGammaReflectionRetainsSignNearNegativeIntegerPoles(): void
    {
        $gamma = FunctionRegistry::defaults()->find('gamma');
        self::assertInstanceOf(FunctionDefinition::class, $gamma);

        foreach ([[-0.9999999999999998, -1], [-1.9999999999999996, 1]] as [$value, $sign]) {
            $result = $gamma->invoke([$value]);

            self::assertTrue(\is_finite($result));
            self::assertSame($sign, $result < 0.0 ? -1 : 1);
            self::assertGreaterThan(1.0E15, \abs($result));
        }
    }

    public function testLambertWLowerBranchRemainsStableForTinyNegativeInputs(): void
    {
        $definition = FunctionRegistry::defaults()->find('lambertw');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        $value = $definition->invoke([-1.0E-300, -1]);

        self::assertLessThan(-690.0, $value);
        self::assertEqualsWithDelta(-1.0E-300, $value * \exp($value), 1.0E-312);
    }

    public function testLambertWBranchesRemainDistinctNearTheBranchPoint(): void
    {
        $definition = FunctionRegistry::defaults()->find('lambertw');
        self::assertInstanceOf(FunctionDefinition::class, $definition);
        $branchPoint = -1.0 / \M_E;
        $packedBranchPoint = \unpack('J', \pack('E', $branchPoint));
        self::assertIsArray($packedBranchPoint);
        self::assertIsInt($packedBranchPoint[1] ?? null);
        $branchPointBits = $packedBranchPoint[1];
        $previousPrincipal = -1.0;
        $previousLower = -1.0;

        foreach ([-1, -2, -4, -8] as $offset) {
            $unpackedInput = \unpack('E', \pack('J', $branchPointBits + $offset));
            self::assertIsArray($unpackedInput);
            self::assertIsFloat($unpackedInput[1] ?? null);
            $input = $unpackedInput[1];
            $principal = $definition->invoke([$input, 0]);
            $lower = $definition->invoke([$input, -1]);

            self::assertGreaterThan(-1.0, $principal);
            self::assertLessThan(-1.0, $lower);
            self::assertGreaterThan($previousPrincipal, $principal);
            self::assertLessThan($previousLower, $lower);
            self::assertEqualsWithDelta($input, $principal * \exp($principal), 1.0E-15);
            self::assertEqualsWithDelta($input, $lower * \exp($lower), 1.0E-15);
            $previousPrincipal = $principal;
            $previousLower = $lower;
        }
    }

    public function testLambertWPrincipalBranchPreservesTinyInputs(): void
    {
        $definition = FunctionRegistry::defaults()->find('lambertw');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        foreach ([1.0E-100, -1.0E-100, 5.0E-324, -5.0E-324] as $input) {
            $value = $definition->invoke([$input]);

            self::assertSame($input, $value);
            self::assertEqualsWithDelta($input, $value * \exp($value), \abs($input) * 1.0E-14);
        }
    }

    public function testLambertWPrincipalGradientAndSingularPoints(): void
    {
        $definition = FunctionRegistry::defaults()->find('lambertw');
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        self::assertSame([1.0], $definition->gradient([0.0]));
        $value = $definition->invoke([1.0]);
        $gradient = $definition->gradient([1.0]);
        self::assertNotNull($gradient);
        self::assertEqualsWithDelta(
            $value / (1.0 + $value),
            $gradient[0],
            1.0E-15,
        );
        self::assertNull($definition->gradient([-1.0 / \M_E]));
        self::assertNull($definition->gradient([-0.1, -1]));
    }

    public function testErrorFunctionsRetainPrecisionInTheComplementaryTail(): void
    {
        $functions = FunctionRegistry::defaults();
        $erf = $functions->find('erf');
        $erfc = $functions->find('erfc');
        self::assertInstanceOf(FunctionDefinition::class, $erf);
        self::assertInstanceOf(FunctionDefinition::class, $erfc);

        self::assertEqualsWithDelta(0.5204998778130465, $erf->invoke([0.5]), 2.0E-16);
        self::assertEqualsWithDelta(0.8427007929497149, $erf->invoke([1.0]), 3.0E-16);
        self::assertEqualsWithDelta(1.537459794428035E-12, $erfc->invoke([5.0]), 1.0E-26);
        self::assertEqualsWithDelta(2.088487583762545E-45, $erfc->invoke([10.0]), 1.0E-58);
        self::assertEqualsWithDelta(2.0, $erfc->invoke([-10.0]), 1.0E-15);
    }

    public function testImaginaryErrorFunctionIsOddAndAccurateAcrossItsFiniteRange(): void
    {
        $erfi = FunctionRegistry::defaults()->find('erfi');
        self::assertInstanceOf(FunctionDefinition::class, $erfi);

        foreach (
            [
                [0.5, 0.6149520946965109],
                [1.0, 1.6504257587975429],
                [2.0, 18.564802414575553],
                [3.0, 1629.9946226015657],
                [10.0, 1.52430742270867E42],
            ] as [$input, $expected]
        ) {
            $positive = $erfi->invoke([$input]);

            self::assertEqualsWithDelta($expected, $positive, \abs($expected) * 4.0E-15);
            self::assertSame(-$positive, $erfi->invoke([-$input]));
        }

        self::assertSame(0.0, $erfi->invoke([0.0]));
        self::assertGreaterThan(1.0E290, $erfi->invoke([26.0]));
    }

    public function testDawsonIntegralIsOddAndAccurateAcrossBothEvaluationRegimes(): void
    {
        $dawson = FunctionRegistry::defaults()->find('dawson');
        self::assertInstanceOf(FunctionDefinition::class, $dawson);

        foreach (
            [
                [0.5, 0.4244363835020223],
                [1.0, 0.5380795069127684],
                [2.0, 0.301340388923792],
                [5.0, 0.10213407442427686],
                [10.0, 0.05025384718759853],
                [1000.0, 0.000500000250000375],
                [1.0E200, 5.0E-201],
            ] as [$input, $expected]
        ) {
            $positive = $dawson->invoke([$input]);

            self::assertEqualsWithDelta($expected, $positive, \abs($expected) * 5.0E-15);
            self::assertSame(-$positive, $dawson->invoke([-$input]));
        }

        self::assertSame(0.0, $dawson->invoke([0.0]));
    }

    public function testScaledComplementaryErrorFunctionRetainsExtremeTails(): void
    {
        $erfcx = FunctionRegistry::defaults()->find('erfcx');
        self::assertInstanceOf(FunctionDefinition::class, $erfcx);

        foreach (
            [
                [-1.0, 5.008980080762283],
                [0.0, 1.0],
                [1.0, 0.427583576155807],
                [5.0, 0.11070463773306863],
                [10.0, 0.05614099274382259],
            ] as [$input, $expected]
        ) {
            self::assertEqualsWithDelta(
                $expected,
                $erfcx->invoke([$input]),
                \abs($expected) * 5.0E-15,
            );
        }

        self::assertGreaterThan(1.0E293, $erfcx->invoke([-26.0]));
        self::assertGreaterThan(0.0, $erfcx->invoke([1.0E200]));
    }

    public function testInverseErrorFunctionsRoundTripCentralAndTailValues(): void
    {
        foreach ([-0.9999999999999999, -0.9, -0.5, 0.0, 0.5, 0.9, 0.9999999999999999] as $target) {
            $inverse = SpecialFunctions::inverseErf($target);
            self::assertEqualsWithDelta($target, SpecialFunctions::erf($inverse), 3.0E-16);
        }

        foreach ([1.0E-200, 1.0E-45, 0.5, 1.5, 2.0 - 1.0E-12] as $target) {
            $inverse = SpecialFunctions::inverseErfc($target);
            self::assertEqualsWithDelta(
                $target,
                SpecialFunctions::erfc($inverse),
                \abs($target) * 2.0E-12,
            );
        }
    }

    public function testInverseScaledComplementaryErrorFunctionRoundTripsWideRange(): void
    {
        foreach ([1.0E-300, 1.0E-100, 0.1, 1.0, 3.0, 1.0E100, 1.0E300] as $target) {
            $inverse = SpecialFunctions::inverseErfcx($target);
            self::assertEqualsWithDelta(
                $target,
                SpecialFunctions::erfcx($inverse),
                $target * 2.0E-12,
            );
        }
    }

    public function testInverseDawsonReturnsBothRealBranches(): void
    {
        $targets = [
            -0.5, -0.1, -1.0E-308, -1.0E-300, -1.0E-12, 0.0,
            5.0E-5, 1.0E-12, 1.0E-300, 1.0E-308, 0.1, 0.5,
        ];
        foreach ($targets as $target) {
            $preimages = SpecialFunctions::inverseDawson($target);
            if ($target === 0.0) {
                self::assertSame([0.0], $preimages);
                continue;
            }
            self::assertCount(2, $preimages);
            self::assertLessThan($preimages[1], $preimages[0]);
            if ($target === 5.0E-5) {
                self::assertGreaterThan($target, $preimages[0]);
            }
            foreach ($preimages as $preimage) {
                self::assertEqualsWithDelta(
                    $target,
                    SpecialFunctions::dawson($preimage),
                    \abs($target) * 2.0E-12,
                );
            }
        }
        self::assertSame([], SpecialFunctions::inverseDawson(0.6));
    }

    public function testInverseDawsonRejectsUnrepresentableOuterBranch(): void
    {
        $this->expectException(\DomainException::class);
        SpecialFunctions::inverseDawson(1.0E-320);
    }

    public function testInverseBesselIReturnsOrderDependentRealBranches(): void
    {
        foreach ([0, 1, 2, 3, 20, 256] as $order) {
            $sampleArgument = $order >= 128 ? 20.0 : 0.5;
            $target = SpecialFunctions::besselI($order, $sampleArgument);
            $preimages = SpecialFunctions::inverseBesselI($order, $target);
            if (($order & 1) === 1) {
                self::assertCount(1, $preimages);
                self::assertEqualsWithDelta($sampleArgument, $preimages[0], 1.0E-10);
            } else {
                self::assertCount(2, $preimages);
                self::assertEqualsWithDelta(-$sampleArgument, $preimages[0], 1.0E-10);
                self::assertEqualsWithDelta($sampleArgument, $preimages[1], 1.0E-10);
            }
            foreach ($preimages as $preimage) {
                self::assertEqualsWithDelta(
                    $target,
                    SpecialFunctions::besselI($order, $preimage),
                    \max(1.0E-320, \abs($target) * 2.0E-12),
                );
            }
        }

        self::assertSame([0.0], SpecialFunctions::inverseBesselI(1, 0.0));
        self::assertSame([0.0], SpecialFunctions::inverseBesselI(2, 0.0));
        self::assertSame([], SpecialFunctions::inverseBesselI(0, 0.5));
        self::assertSame([], SpecialFunctions::inverseBesselI(1, 1.0E308));

        $nearMinimum = SpecialFunctions::inverseBesselI(0, 1.0 + \PHP_FLOAT_EPSILON);
        self::assertEqualsWithDelta(
            -2.0 * \sqrt(\PHP_FLOAT_EPSILON),
            $nearMinimum[0],
            1.0E-22,
        );
        self::assertEqualsWithDelta(
            2.0 * \sqrt(\PHP_FLOAT_EPSILON),
            $nearMinimum[1],
            1.0E-22,
        );

        self::assertSame(
            [-713.0, 713.0],
            SpecialFunctions::inverseBesselI(0, SpecialFunctions::besselI(0, 713.0)),
        );
        self::assertSame(
            [713.0],
            SpecialFunctions::inverseBesselI(1, SpecialFunctions::besselI(1, 713.0)),
        );

        $tiny = SpecialFunctions::inverseBesselI(1, 1.0E-300);
        self::assertEqualsWithDelta(1.0E-300, SpecialFunctions::besselI(1, $tiny[0]), 1.0E-312);

        foreach ([1, 2, 3, 10] as $order) {
            foreach ([1.0E-6, 1.0E-8, 1.0E-12] as $argument) {
                $level = SpecialFunctions::besselI($order, $argument);
                $preimages = SpecialFunctions::inverseBesselI($order, $level);
                self::assertEqualsWithDelta(
                    $argument,
                    $preimages[\count($preimages) - 1],
                    $argument * 1.0E-10,
                );
            }
        }
    }

    public function testInverseImaginaryErrorFunctionRoundTripsSmallAndLargeValues(): void
    {
        $targets = [-1.0E300, -1.0E100, -3.0, -0.5, -1.0E-300, 0.0, 1.0E-300, 0.5, 3.0, 1.0E100, 1.0E300];
        foreach ($targets as $target) {
            $inverse = SpecialFunctions::inverseErfi($target);
            if ($target === 0.0) {
                self::assertSame(0.0, $inverse);
                continue;
            }
            self::assertEqualsWithDelta(
                $target,
                SpecialFunctions::erfi($inverse),
                \abs($target) * 2.0E-13,
            );
        }
    }

    public function testInverseErrorFunctionPreservesTinyCentralInputs(): void
    {
        foreach ([1.0E-300, 1.0E-100, 1.0E-20, 1.0E-12] as $target) {
            foreach ([$target, -$target] as $signedTarget) {
                $inverse = SpecialFunctions::inverseErf($signedTarget);
                self::assertNotSame(0.0, $inverse);
                self::assertEqualsWithDelta(
                    $signedTarget,
                    SpecialFunctions::erf($inverse),
                    \abs($signedTarget) * 4.0 * \PHP_FLOAT_EPSILON,
                );
            }
        }
    }

    public function testInverseErrorFunctionIsOddAndMonotoneAcrossTinyScaleRange(): void
    {
        $previous = 0.0;
        foreach ([5.0E-324, 1.0E-320, 1.0E-300, 1.0E-200, 1.0E-100, 1.0E-20, 1.0E-8] as $target) {
            $positive = SpecialFunctions::inverseErf($target);
            $negative = SpecialFunctions::inverseErf(-$target);

            self::assertGreaterThan($previous, $positive);
            self::assertSame(-$positive, $negative);
            self::assertEqualsWithDelta(
                $target,
                SpecialFunctions::erf($positive),
                \abs($target) * 4.0 * \PHP_FLOAT_EPSILON,
            );
            $previous = $positive;
        }
    }

    public function testInverseRegularizedBetaFunctionsPreserveBothTails(): void
    {
        foreach ([1.0E-12, 0.01, 0.5, 0.99, 1.0 - 1.0E-12] as $probability) {
            $value = SpecialFunctions::inverseRegularizedBeta(2.0, 3.0, $probability);
            self::assertEqualsWithDelta(
                $probability,
                SpecialFunctions::regularizedBeta(2.0, 3.0, $value),
                \abs($probability) * 3.0E-12,
            );

            $complementaryValue = SpecialFunctions::inverseRegularizedBetaComplement(
                2.0,
                3.0,
                $probability,
            );
            self::assertEqualsWithDelta(
                $probability,
                SpecialFunctions::regularizedBetaComplement(2.0, 3.0, $complementaryValue),
                \abs($probability) * 3.0E-12,
            );
        }
    }

    public function testInverseRegularizedBetaResolvesRepresentableExtremeQuantiles(): void
    {
        foreach (
            [
                [0.1, 5.0, 1.0E-20, false],
                [5.0, 0.1, 1.0E-20, false],
                [0.1, 5.0, 1.0E-20, true],
            ] as [$firstShape, $secondShape, $probability, $complement]
        ) {
            $quantile = $complement
                ? SpecialFunctions::inverseRegularizedBetaComplement(
                    $firstShape,
                    $secondShape,
                    $probability,
                )
                : SpecialFunctions::inverseRegularizedBeta(
                    $firstShape,
                    $secondShape,
                    $probability,
                );
            $roundTrip = $complement
                ? SpecialFunctions::regularizedBetaComplement($firstShape, $secondShape, $quantile)
                : SpecialFunctions::regularizedBeta($firstShape, $secondShape, $quantile);

            self::assertGreaterThan(0.0, $quantile);
            self::assertLessThan(1.0, $quantile);
            self::assertEqualsWithDelta($probability, $roundTrip, $probability * 2.0E-10);
        }
    }

    public function testInverseRegularizedBetaRejectsQuantilesRoundedToEndpoints(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('below floating-point resolution');

        SpecialFunctions::inverseRegularizedBetaComplement(5.0, 0.1, 1.0E-20);
    }

    public function testIntegerOrderBesselJValuesAndSymmetries(): void
    {
        $besselJ = FunctionRegistry::defaults()->find('besselj');
        self::assertInstanceOf(FunctionDefinition::class, $besselJ);
        self::assertSame(1.0, $besselJ->invoke([0, 0]));
        self::assertSame(0.0, $besselJ->invoke([3, 0]));
        self::assertEqualsWithDelta(0.7651976865579666, $besselJ->invoke([0, 1]), 2.0E-15);
        self::assertEqualsWithDelta(0.4400505857449335, $besselJ->invoke([1, 1]), 2.0E-15);
        self::assertEqualsWithDelta(0.1149034849319005, $besselJ->invoke([2, 1]), 2.0E-15);
        self::assertEqualsWithDelta(-0.4400505857449335, $besselJ->invoke([-1, 1]), 2.0E-15);
        self::assertEqualsWithDelta(-0.4400505857449335, $besselJ->invoke([1, -1]), 2.0E-15);
        self::assertEqualsWithDelta(0.04768931079683354, $besselJ->invoke([0, 12]), 3.0E-13);
        self::assertEqualsWithDelta(-0.22344710449062766, $besselJ->invoke([1, 12]), 3.0E-13);
        self::assertEqualsWithDelta(0.13904751877870125, $besselJ->invoke([2, 11]), 2.0E-13);
    }

    public function testIntegerOrderBesselJRejectsInvalidOrderAndLargeArguments(): void
    {
        $this->expectException(\DomainException::class);
        FunctionRegistry::defaults()->find('besselj')?->invoke([0.5, 1.0]);
    }

    public function testIntegerOrderBesselJRejectsArgumentsBeyondItsExpandedRange(): void
    {
        $this->expectException(\DomainException::class);
        FunctionRegistry::defaults()->find('besselj')?->invoke([0, 1_001.0]);
    }

    public function testIntegerOrderBesselYValuesRecurrenceAndWronskian(): void
    {
        $besselY = FunctionRegistry::defaults()->find('bessely');
        self::assertInstanceOf(FunctionDefinition::class, $besselY);
        self::assertEqualsWithDelta(0.08825696421567696, $besselY->invoke([0, 1.0]), 2.0E-15);
        self::assertEqualsWithDelta(-0.7812128213002887, $besselY->invoke([1, 1.0]), 2.0E-15);
        self::assertEqualsWithDelta(-1.6506826068162544, $besselY->invoke([2, 1.0]), 3.0E-15);
        self::assertEqualsWithDelta(0.7812128213002887, $besselY->invoke([-1, 1.0]), 2.0E-15);
        self::assertEqualsWithDelta(0.0556711672835994, $besselY->invoke([0, 10.0]), 5.0E-14);
        self::assertEqualsWithDelta(0.06264059680938384, $besselY->invoke([0, 20.0]), 2.0E-15);
        self::assertEqualsWithDelta(-0.1655116143625213, $besselY->invoke([1, 20.0]), 2.0E-15);
        self::assertEqualsWithDelta(-2.0 / (\M_PI * 20.0), $this->besselWronskian(20.0), 2.0E-15);
        self::assertEqualsWithDelta(-2.0 / (\M_PI * 1.0), $this->besselWronskian(1.0), 2.0E-15);
        self::assertEqualsWithDelta(
            -2.0 / (\M_PI * 1.0E-200),
            SpecialFunctions::besselY(1, 1.0E-200),
            1.0E+185,
        );
        self::assertEqualsWithDelta(
            -2.0 / (\M_PI * 1.0E-301),
            SpecialFunctions::besselY(1, 1.0E-301),
            1.0E+286,
        );
        self::assertEqualsWithDelta(
            2.0 / (\M_PI * 1.0E-301),
            SpecialFunctions::besselY(-1, 1.0E-301),
            1.0E+286,
        );
    }

    public function testBesselWronskianRemainsAccurateAcrossOrderAndArgumentScales(): void
    {
        foreach ([0, 1, 2, 8, 32, 64, 128, 255] as $order) {
            $arguments = [
                \max(0.5, $order / 2.0),
                \max(1.0, (float) $order),
                \min(1_000.0, \max(10.0, 4.0 * $order)),
            ];
            foreach (\array_unique($arguments) as $argument) {
                $expected = -2.0 / (\M_PI * $argument);
                self::assertEqualsWithDelta(
                    $expected,
                    $this->besselWronskian($argument, $order),
                    \abs($expected) * 1.0E-10,
                    \sprintf('order=%d argument=%.17g', $order, $argument),
                );
            }
        }
    }

    public function testIntegerOrderBesselYRejectsInvalidOrdersAndArguments(): void
    {
        $besselY = FunctionRegistry::defaults()->find('bessely');
        self::assertInstanceOf(FunctionDefinition::class, $besselY);
        foreach ([[0, 0], [0, -1], [0, 1_001], [0.5, 1], [257, 1], [2, 1.0E-301]] as $arguments) {
            try {
                $besselY->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('bessely() should reject arguments outside its real domain or supported order.');
        }
    }

    private function besselWronskian(float $value, int $order = 0): float
    {
        return (SpecialFunctions::besselJ($order, $value) * SpecialFunctions::besselY($order + 1, $value))
            - (SpecialFunctions::besselJ($order + 1, $value) * SpecialFunctions::besselY($order, $value));
    }

    public function testBesselFamiliesSupportExpandedIntegerOrderRange(): void
    {
        $definitions = FunctionRegistry::defaults();
        $besselJ = $definitions->find('besselj');
        $besselI = $definitions->find('besseli');
        self::assertInstanceOf(FunctionDefinition::class, $besselJ);
        self::assertInstanceOf(FunctionDefinition::class, $besselI);

        self::assertEqualsWithDelta(-0.056242657691127164, $besselJ->invoke([256, 300]), 1.0E-14);
        self::assertEqualsWithDelta(-0.056242657691127164, $besselJ->invoke([-256, 300]), 1.0E-14);
        self::assertEqualsWithDelta(7.551659920948753E-151, $besselJ->invoke([256, 50]), 1.0E-163);
        self::assertGreaterThan(0.0, $besselJ->invoke([256, 12]));
        self::assertLessThan(0.0, $besselJ->invoke([255, -12]));
        self::assertEqualsWithDelta(1.1495397478367799E288, $besselI->invoke([256, 713]), 1.0E274);
        self::assertEqualsWithDelta(1.1495397478367799E288, $besselI->invoke([-256, 713]), 1.0E274);
    }

    public function testBesselFamiliesRejectOrdersBeyondExpandedRange(): void
    {
        foreach (['besselj', 'besseli'] as $name) {
            $rejected = false;
            try {
                FunctionRegistry::defaults()->find($name)?->invoke([257, 1]);
            } catch (\DomainException) {
                $rejected = true;
            }
            self::assertTrue($rejected, $name . ' should reject order 257.');
        }
    }

    public function testModifiedBesselIValuesAndSymmetries(): void
    {
        $besselI = FunctionRegistry::defaults()->find('besseli');
        self::assertInstanceOf(FunctionDefinition::class, $besselI);
        self::assertSame(1.0, $besselI->invoke([0, 0]));
        self::assertSame(0.0, $besselI->invoke([3, 0]));
        self::assertEqualsWithDelta(1.2660658777520082, $besselI->invoke([0, 1]), 2.0E-15);
        self::assertEqualsWithDelta(0.565159103992485, $besselI->invoke([1, 1]), 2.0E-15);
        self::assertEqualsWithDelta(0.1357476697670383, $besselI->invoke([2, 1]), 2.0E-15);
        self::assertEqualsWithDelta(2815.7166284662549, $besselI->invoke([0, 10]), 2.0E-12);
        self::assertEqualsWithDelta(1.0737517071310733E42, $besselI->invoke([0, 100]), 1.0E28);
        $largeArgument = 700.0;
        $asymptoticI0 = \exp($largeArgument) / \sqrt(2.0 * \M_PI * $largeArgument)
            * (
                1.0
                + (1.0 / (8.0 * $largeArgument))
                + (9.0 / (128.0 * ($largeArgument ** 2)))
                + (225.0 / (3072.0 * ($largeArgument ** 3)))
                + (11025.0 / (98304.0 * ($largeArgument ** 4)))
            );
        self::assertEqualsWithDelta($asymptoticI0, $besselI->invoke([0, $largeArgument]), 1.0E-12 * $asymptoticI0);
        $maximumArgument = 713.0;
        $maximumAsymptoticI0 = \exp(
            $maximumArgument - (0.5 * \log(2.0 * \M_PI * $maximumArgument)),
        ) * (
            1.0
            + (1.0 / (8.0 * $maximumArgument))
            + (9.0 / (128.0 * ($maximumArgument ** 2)))
            + (225.0 / (3072.0 * ($maximumArgument ** 3)))
        );
        $maximumValue = $besselI->invoke([0, $maximumArgument]);
        self::assertTrue(\is_finite($maximumValue));
        self::assertEqualsWithDelta($maximumAsymptoticI0, $maximumValue, 1.0E-10 * $maximumValue);
        self::assertEqualsWithDelta($besselI->invoke([2, 1]), $besselI->invoke([-2, 1]), 2.0E-15);
        self::assertEqualsWithDelta(-$besselI->invoke([1, 1]), $besselI->invoke([1, -1]), 2.0E-15);
    }

    public function testModifiedBesselIHighOrderValuesMatchIndependentSeriesReferences(): void
    {
        foreach (
            [
                [64, 713.0, 3.7924117784959314E306],
                [128, 713.0, 7.0212311164880119E302],
                [256, 713.0, 1.1495397478367832E288],
                [256, 300.0, 3.5680707562444389E83],
                [256, 50.0, 9.7835023227973146E-149],
            ] as [$order, $argument, $expected]
        ) {
            self::assertEqualsWithDelta(
                $expected,
                SpecialFunctions::besselI($order, $argument),
                \abs($expected) * 5.0E-14,
                \sprintf('order=%d argument=%.17g', $order, $argument),
            );
        }
    }

    public function testModifiedBesselIRejectsArgumentsBeyondFiniteRange(): void
    {
        $this->expectException(\DomainException::class);
        FunctionRegistry::defaults()->find('besseli')?->invoke([0, 714.0]);
    }

    /**
     * @return iterable<string, array{string, list<int|float>, int|float, 'int'|'float'}>
     */
    public static function builtInSuccessProvider(): iterable
    {
        yield 'absolute integer' => ['abs', [-4], 4, 'int'];
        yield 'absolute float' => ['abs', [-4.5], 4.5, 'float'];
        yield 'square root' => ['sqrt', [9], 3.0, 'float'];
        yield 'cube root positive' => ['cbrt', [27], 3.0, 'float'];
        yield 'cube root negative' => ['cbrt', [-8], -2.0, 'float'];
        yield 'fourth root' => ['root', [81, 4], 3.0, 'float'];
        yield 'negative fifth root' => ['root', [-32, 5], -2.0, 'float'];
        yield 'reciprocal square root' => ['root', [16, -2], 0.25, 'float'];
        yield 'computed integer root degree' => ['root', [16, \sqrt(2.0) ** 2], 4.0, 'float'];
        yield 'error function at zero' => ['erf', [0], 0.0, 'float'];
        yield 'Bessel Y0' => ['bessely', [0, 1], 0.08825696421567696, 'float'];
        yield 'complementary error function at zero' => ['erfc', [0], 1.0, 'float'];
        yield 'inverse error function at zero' => ['erfinv', [0], 0.0, 'float'];
        yield 'inverse complementary error function at one' => ['erfcinv', [1], 0.0, 'float'];
        yield 'inverse error function at one half' => [
            'erfinv',
            [0.5],
            0.4769362762044699,
            'float',
        ];
        yield 'inverse complementary error function at one half' => [
            'erfcinv',
            [0.5],
            0.4769362762044699,
            'float',
        ];
        yield 'gamma at five' => ['gamma', [5], 24.0, 'float'];
        yield 'regularized lower incomplete gamma' => ['gammainc', [1, 2], 1.0 - \exp(-2.0), 'float'];
        yield 'regularized upper incomplete gamma' => ['gammaincc', [1, 2], \exp(-2.0), 'float'];
        yield 'inverse regularized lower incomplete gamma' => [
            'gammaincinv',
            [1, 1.0 - \exp(-2.0)],
            2.0,
            'float',
        ];
        yield 'inverse regularized upper incomplete gamma' => ['gammainccinv', [1, \exp(-2.0)], 2.0, 'float'];
        yield 'log gamma at five' => ['lgamma', [5], log(24.0), 'float'];
        yield 'beta at one and one' => ['beta', [1, 1], 1.0, 'float'];
        yield 'regularized incomplete beta' => ['betainc', [2, 3, 0.4], 0.5248, 'float'];
        yield 'regularized incomplete beta complement' => ['betaincc', [2, 3, 0.4], 0.4752, 'float'];
        yield 'inverse regularized incomplete beta' => ['betaincinv', [2, 3, 0.5248], 0.4, 'float'];
        yield 'inverse regularized incomplete beta complement' => [
            'betainccinv',
            [2, 3, 0.4752],
            0.4,
            'float',
        ];
        yield 'beta at halves' => ['beta', [0.5, 0.5], \M_PI, 'float'];
        yield 'beta with negative non-integer arguments' => ['beta', [-0.5, 1.5], -\M_PI, 'float'];
        yield 'beta zero at denominator gamma pole' => ['beta', [0.5, -0.5], 0.0, 'float'];
        yield 'Riemann zeta at two' => ['zeta', [2], (\M_PI ** 2) / 6.0, 'float'];
        yield 'Riemann zeta at one half' => ['zeta', [0.5], -1.4603545088095868, 'float'];
        yield 'Riemann zeta at zero' => ['zeta', [0], -0.5, 'float'];
        yield 'Riemann zeta at negative one' => ['zeta', [-1], -1.0 / 12.0, 'float'];
        yield 'Riemann zeta at negative three' => ['zeta', [-3], 1.0 / 120.0, 'float'];
        yield 'Riemann zeta at negative five' => ['zeta', [-5], -1.0 / 252.0, 'float'];
        yield 'Riemann zeta trivial zero' => ['zeta', [-2], 0.0, 'float'];
        yield 'Riemann zeta large trivial zero' => ['zeta', [-1_000], 0.0, 'float'];
        yield 'digamma at one' => ['digamma', [1], -0.5772156649015329, 'float'];
        yield 'trigamma at one' => ['trigamma', [1], (\M_PI ** 2) / 6.0, 'float'];
        yield 'trigamma at negative half' => ['trigamma', [-0.5], ((\M_PI ** 2) / 2.0) + 4.0, 'float'];
        yield 'tetragamma at one' => ['tetragamma', [1], -2.4041138063191886, 'float'];
        yield 'tetragamma at half' => ['tetragamma', [0.5], -16.82879664423432, 'float'];
        yield 'tetragamma at negative half' => ['tetragamma', [-0.5], -0.82879664423432, 'float'];
        yield 'third polygamma at one' => ['polygamma', [3, 1], 6.493939402266829, 'float'];
        yield 'fourth polygamma at one' => ['polygamma', [4, 1], -24.88626612344088, 'float'];
        yield 'third polygamma at negative half' => ['polygamma', [3, -0.5], 193.4090910340024, 'float'];
        yield 'Lambert W principal at one' => ['lambertw', [1], 0.5671432904097838, 'float'];
        yield 'Lambert W principal branch point' => [
            'lambertw',
            [-1.0 / \exp(1.0)],
            -1.0,
            'float',
        ];
        yield 'Lambert W lower branch at minus one over e' => [
            'lambertw',
            [-1.0 / \exp(1.0), -1],
            -1.0,
            'float',
        ];
        yield 'Lambert W lower branch at minus one tenth' => [
            'lambertw',
            [-0.1, -1],
            -3.577152063957297,
            'float',
        ];
        yield 'sine' => ['sin', [\M_PI / 2], 1.0, 'float'];
        yield 'unnormalized sinc at zero' => ['sinc', [0], 1.0, 'float'];
        yield 'unnormalized sinc near zero' => ['sinc', [1.0E-8], 1.0, 'float'];
        yield 'unnormalized sinc at pi' => ['sinc', [\M_PI], 0.0, 'float'];
        yield 'normalized sinc at zero' => ['sincpi', [0], 1.0, 'float'];
        yield 'normalized sinc at half' => ['sincpi', [0.5], 2.0 / \M_PI, 'float'];
        yield 'normalized sinc at one' => ['sincpi', [1], 0.0, 'float'];
        yield 'cosine' => ['cos', [0], 1.0, 'float'];
        yield 'tangent' => ['tan', [\M_PI / 4], 1.0, 'float'];
        yield 'secant' => ['sec', [0], 1.0, 'float'];
        yield 'cosecant' => ['csc', [\M_PI / 2], 1.0, 'float'];
        yield 'cotangent' => ['cot', [\M_PI / 4], 1.0, 'float'];
        yield 'arc secant' => ['asec', [2], \acos(0.5), 'float'];
        yield 'arc cosecant' => ['acsc', [2], \asin(0.5), 'float'];
        yield 'arc cotangent' => ['acot', [1], \M_PI / 4, 'float'];
        yield 'arc sine' => ['asin', [1], \M_PI / 2, 'float'];
        yield 'arc cosine' => ['acos', [0], \M_PI / 2, 'float'];
        yield 'arc tangent' => ['atan', [1], \M_PI / 4, 'float'];
        yield 'arc sine alias' => ['arcsin', [1], \M_PI / 2, 'float'];
        yield 'arc cosine alias' => ['arccos', [0], \M_PI / 2, 'float'];
        yield 'arc tangent alias' => ['arctan', [1], \M_PI / 4, 'float'];
        yield 'hyperbolic sine' => ['sinh', [0], 0.0, 'float'];
        yield 'hyperbolic cosine' => ['cosh', [0], 1.0, 'float'];
        yield 'hyperbolic tangent' => ['tanh', [0], 0.0, 'float'];
        yield 'hyperbolic secant at zero' => ['sech', [0], 1.0, 'float'];
        yield 'hyperbolic secant avoids overflow' => ['sech', [1000], 0.0, 'float'];
        yield 'hyperbolic cosecant' => ['csch', [1], 1.0 / \sinh(1.0), 'float'];
        yield 'negative hyperbolic cosecant' => ['csch', [-1], -1.0 / \sinh(1.0), 'float'];
        yield 'hyperbolic cosecant avoids overflow' => ['csch', [1000], 0.0, 'float'];
        yield 'hyperbolic cotangent' => ['coth', [1], 1.0 / \tanh(1.0), 'float'];
        yield 'hyperbolic cotangent avoids overflow' => ['coth', [1000], 1.0, 'float'];
        yield 'inverse hyperbolic sine' => ['asinh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic sine alias' => ['arcsinh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic cosine' => ['acosh', [1], 0.0, 'float'];
        yield 'inverse hyperbolic cosine alias' => ['arccosh', [1], 0.0, 'float'];
        yield 'inverse hyperbolic tangent' => ['atanh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic tangent alias' => ['arctanh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic secant' => ['asech', [0.5], \acosh(2.0), 'float'];
        yield 'inverse hyperbolic cosecant' => ['acsch', [1], \asinh(1.0), 'float'];
        yield 'negative inverse hyperbolic cosecant' => ['acsch', [-1], -\asinh(1.0), 'float'];
        yield 'inverse hyperbolic cosecant avoids reciprocal overflow' => [
            'acsch',
            [1.0E-308],
            \asinh(1.0E308),
            'float',
        ];
        yield 'inverse hyperbolic cotangent' => ['acoth', [2], \atanh(0.5), 'float'];
        yield 'negative inverse hyperbolic cotangent' => ['acoth', [-2], -\atanh(0.5), 'float'];
        yield 'exponential' => ['exp', [1], \M_E, 'float'];
        yield 'natural logarithm' => ['ln', [\M_E], 1.0, 'float'];
        yield 'logarithm' => ['log', [100, 10], 2.0, 'float'];
        yield 'base ten logarithm' => ['log10', [100], 2.0, 'float'];
        yield 'base two logarithm' => ['log2', [8], 3.0, 'float'];
        yield 'log one plus' => ['log1p', [0], 0.0, 'float'];
        yield 'exponential minus one' => ['expm1', [0], 0.0, 'float'];
        yield 'atan2' => ['atan2', [1, 1], \M_PI / 4, 'float'];
        yield 'hypotenuse' => ['hypot', [3, 4], 5.0, 'float'];
        yield 'sign' => ['sign', [-4], -1, 'int'];
        yield 'minimum' => ['min', [4, 2, 8], 2, 'int'];
        yield 'maximum' => ['max', [4, 2, 8], 8, 'int'];
        yield 'floor' => ['floor', [-1.2], -2.0, 'float'];
        yield 'ceiling' => ['ceil', [-1.2], -1.0, 'float'];
        yield 'truncate negative' => ['trunc', [-1.2], -1.0, 'float'];
        yield 'truncate positive' => ['trunc', [1.2], 1.0, 'float'];
        yield 'round positive halfway' => ['round', [2.5], 3.0, 'float'];
        yield 'round negative halfway' => ['round', [-2.5], -3.0, 'float'];
        yield 'greatest common divisor' => ['gcd', [84, 30], 6, 'int'];
        yield 'gcd absorbs machine-roundoff integer input' => [
            'gcd',
            [15.0 + (8.0 * \PHP_FLOAT_EPSILON), 6],
            3,
            'int',
        ];
        yield 'least common multiple' => ['lcm', [12, 18], 36, 'int'];
        yield 'binomial coefficient' => ['binomial', [52, 5], 2_598_960, 'int'];
        yield 'binomial symmetry' => ['binomial', [30, 28], 435, 'int'];
        yield 'binomial out of range' => ['binomial', [4, 5], 0, 'int'];
        yield 'permutation count' => ['permutation', [10, 3], 720, 'int'];
        yield 'permutation empty selection' => ['permutation', [10, 0], 1, 'int'];
        yield 'lcm absorbs machine-roundoff integer input' => [
            'lcm',
            [15.0 + (8.0 * \PHP_FLOAT_EPSILON), 6],
            30,
            'int',
        ];
    }

    #[DataProvider('builtInDefinitionProvider')]
    public function testDefaultRegistryHasExactlyTheSpecifiedArities(
        string $name,
        int $minimum,
        int $maximum,
    ): void {
        $definition = FunctionRegistry::defaults()->find($name);

        self::assertInstanceOf(FunctionDefinition::class, $definition);
        self::assertSame($minimum, $definition->minArguments);
        self::assertSame($maximum, $definition->maxArguments);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function builtInDefinitionProvider(): iterable
    {
        yield 'abs' => ['abs', 1, 1];
        yield 'sqrt' => ['sqrt', 1, 1];
        yield 'cbrt' => ['cbrt', 1, 1];
        yield 'root' => ['root', 2, 2];
        yield 'erf' => ['erf', 1, 1];
        yield 'erfc' => ['erfc', 1, 1];
        yield 'erfcx' => ['erfcx', 1, 1];
        yield 'erfi' => ['erfi', 1, 1];
        yield 'dawson' => ['dawson', 1, 1];
        yield 'erfinv' => ['erfinv', 1, 1];
        yield 'erfcinv' => ['erfcinv', 1, 1];
        yield 'gamma' => ['gamma', 1, 1];
        yield 'betainc' => ['betainc', 3, 3];
        yield 'betaincc' => ['betaincc', 3, 3];
        yield 'betaincinv' => ['betaincinv', 3, 3];
        yield 'betainccinv' => ['betainccinv', 3, 3];
        yield 'gammainc' => ['gammainc', 2, 2];
        yield 'gammaincc' => ['gammaincc', 2, 2];
        yield 'gammaincinv' => ['gammaincinv', 2, 2];
        yield 'gammainccinv' => ['gammainccinv', 2, 2];
        yield 'lgamma' => ['lgamma', 1, 1];
        yield 'digamma' => ['digamma', 1, 1];
        yield 'trigamma' => ['trigamma', 1, 1];
        yield 'tetragamma' => ['tetragamma', 1, 1];
        yield 'polygamma' => ['polygamma', 2, 2];
        yield 'besselj' => ['besselj', 2, 2];
        yield 'besseli' => ['besseli', 2, 2];
        yield 'bessely' => ['bessely', 2, 2];
        yield 'lambertw' => ['lambertw', 1, 2];
        yield 'sin' => ['sin', 1, 1];
        yield 'sinc' => ['sinc', 1, 1];
        yield 'sincpi' => ['sincpi', 1, 1];
        yield 'cos' => ['cos', 1, 1];
        yield 'tan' => ['tan', 1, 1];
        yield 'sec' => ['sec', 1, 1];
        yield 'csc' => ['csc', 1, 1];
        yield 'cot' => ['cot', 1, 1];
        yield 'asec' => ['asec', 1, 1];
        yield 'acsc' => ['acsc', 1, 1];
        yield 'acot' => ['acot', 1, 1];
        yield 'asin' => ['asin', 1, 1];
        yield 'acos' => ['acos', 1, 1];
        yield 'atan' => ['atan', 1, 1];
        yield 'arcsin' => ['arcsin', 1, 1];
        yield 'arccos' => ['arccos', 1, 1];
        yield 'arctan' => ['arctan', 1, 1];
        yield 'arcsinh' => ['arcsinh', 1, 1];
        yield 'arccosh' => ['arccosh', 1, 1];
        yield 'arctanh' => ['arctanh', 1, 1];
        yield 'sinh' => ['sinh', 1, 1];
        yield 'cosh' => ['cosh', 1, 1];
        yield 'tanh' => ['tanh', 1, 1];
        yield 'sech' => ['sech', 1, 1];
        yield 'csch' => ['csch', 1, 1];
        yield 'coth' => ['coth', 1, 1];
        yield 'asinh' => ['asinh', 1, 1];
        yield 'acosh' => ['acosh', 1, 1];
        yield 'atanh' => ['atanh', 1, 1];
        yield 'asech' => ['asech', 1, 1];
        yield 'acsch' => ['acsch', 1, 1];
        yield 'acoth' => ['acoth', 1, 1];
        yield 'exp' => ['exp', 1, 1];
        yield 'ln' => ['ln', 1, 1];
        yield 'log' => ['log', 2, 2];
        yield 'log10' => ['log10', 1, 1];
        yield 'log2' => ['log2', 1, 1];
        yield 'log1p' => ['log1p', 1, 1];
        yield 'expm1' => ['expm1', 1, 1];
        yield 'atan2' => ['atan2', 2, 2];
        yield 'hypot' => ['hypot', 2, 2];
        yield 'sign' => ['sign', 1, 1];
        yield 'min' => ['min', 1, 16];
        yield 'max' => ['max', 1, 16];
        yield 'floor' => ['floor', 1, 1];
        yield 'ceil' => ['ceil', 1, 1];
        yield 'round' => ['round', 1, 1];
        yield 'trunc' => ['trunc', 1, 1];
        yield 'gcd' => ['gcd', 2, 2];
        yield 'lcm' => ['lcm', 2, 2];
        yield 'binomial' => ['binomial', 2, 2];
        yield 'permutation' => ['permutation', 2, 2];
    }

    /**
     * @param list<int|float> $arguments
     * @param class-string<\Throwable> $expectedException
     */
    #[DataProvider('builtInDomainFailureProvider')]
    public function testBuiltInDefinitionsEnforceTheirDomains(
        string $name,
        array $arguments,
        string $expectedException,
        string $expectedMessage,
    ): void {
        $definition = FunctionRegistry::defaults()->find($name);
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $definition->invoke($arguments);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<int|float>,
     *     class-string<\Throwable>,
     *     string
     * }>
     */
    public static function builtInDomainFailureProvider(): iterable
    {
        yield 'inverse beta zero first shape' => [
            'betaincinv',
            [0.0, 2.0, 0.5],
            \DomainException::class,
            'betaincinv() requires a > 0, b > 0, and p in [0, 1].',
        ];
        yield 'inverse beta probability above one' => [
            'betainccinv',
            [2.0, 3.0, 1.1],
            \DomainException::class,
            'betainccinv() requires a > 0, b > 0, and p in [0, 1].',
        ];
        yield 'inverse error function endpoint' => [
            'erfinv',
            [1.0],
            \DomainException::class,
            'erfinv() requires a finite argument strictly between -1 and 1.',
        ];
        yield 'inverse complementary error function endpoint' => [
            'erfcinv',
            [0.0],
            \DomainException::class,
            'erfcinv() requires a finite argument strictly between 0 and 2.',
        ];
        yield 'absolute minimum integer' => [
            'abs',
            [\PHP_INT_MIN],
            \OverflowException::class,
            'absolute value of PHP_INT_MIN',
        ];
        yield 'negative square root' => [
            'sqrt',
            [-1],
            \DomainException::class,
            'sqrt() requires a non-negative argument.',
        ];
        yield 'zero integer-root degree' => [
            'root',
            [16, 0],
            \DomainException::class,
            'root() requires a nonzero integer degree',
        ];
        yield 'fractional integer-root degree' => [
            'root',
            [16, 2.5],
            \DomainException::class,
            'root() requires a nonzero integer degree',
        ];
        yield 'even root of negative value' => [
            'root',
            [-8, 2],
            \DomainException::class,
            'root() requires an odd degree for negative values.',
        ];
        yield 'negative-degree root of zero' => [
            'root',
            [0, -3],
            \DomainException::class,
            'root() is undefined for zero with a negative degree.',
        ];
        yield 'gamma pole at zero' => [
            'gamma',
            [0],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'digamma pole at zero' => [
            'digamma',
            [0],
            \DomainException::class,
            'digamma() requires a finite non-pole argument',
        ];
        yield 'trigamma pole at negative integer' => [
            'trigamma',
            [-3],
            \DomainException::class,
            'trigamma() requires a finite non-pole argument',
        ];
        yield 'tetragamma pole at zero' => [
            'tetragamma',
            [0],
            \DomainException::class,
            'tetragamma() requires a finite non-pole argument',
        ];
        yield 'tetragamma overflow near a pole' => [
            'tetragamma',
            [1.0E-109],
            \OverflowException::class,
            'tetragamma() produced a non-finite result',
        ];
        yield 'gamma pole at negative integer' => [
            'gamma',
            [-3],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'natural log zero' => [
            'ln',
            [0],
            \DomainException::class,
            'ln() requires a positive argument.',
        ];
        yield 'natural log negative' => [
            'ln',
            [-1],
            \DomainException::class,
            'ln() requires a positive argument.',
        ];
        yield 'log value zero' => [
            'log',
            [0, 2],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base zero' => [
            'log',
            [2, 0],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base one integer' => [
            'log',
            [2, 1],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base one float' => [
            'log',
            [2, 1.0],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log negative base' => [
            'log',
            [2, -10],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'inverse sine out of range' => [
            'asin',
            [2],
            \DomainException::class,
            'asin() requires an argument between -1 and 1.',
        ];
        yield 'inverse hyperbolic cosine below one' => [
            'acosh',
            [0],
            \DomainException::class,
            'acosh() requires an argument greater than or equal to one.',
        ];
        yield 'inverse hyperbolic tangent at pole' => [
            'atanh',
            [1],
            \DomainException::class,
            'atanh() requires an argument strictly between -1 and 1.',
        ];
        yield 'inverse hyperbolic secant at zero' => [
            'asech',
            [0],
            \DomainException::class,
            'asech() requires an argument in the interval (0, 1].',
        ];
        yield 'inverse hyperbolic secant above range' => [
            'asech',
            [1.1],
            \DomainException::class,
            'asech() requires an argument in the interval (0, 1].',
        ];
        yield 'inverse hyperbolic cosecant at zero' => [
            'acsch',
            [0],
            \DomainException::class,
            'acsch() is undefined at zero.',
        ];
        yield 'inverse hyperbolic cotangent at boundary' => [
            'acoth',
            [1],
            \DomainException::class,
            'acoth() requires an argument with absolute value greater than one.',
        ];
        yield 'Lambert W principal below real domain' => [
            'lambertw',
            [-0.4],
            \DomainException::class,
            'The real principal lambertw() branch requires x >= -1/e.',
        ];
        yield 'Lambert W lower branch at zero' => [
            'lambertw',
            [0, -1],
            \DomainException::class,
            'The real lambertw() branch -1 requires -1/e <= x < 0.',
        ];
        yield 'hyperbolic cosecant at pole' => [
            'csch',
            [0],
            \DomainException::class,
            'csch() is undefined at zero.',
        ];
        yield 'hyperbolic cotangent at pole' => [
            'coth',
            [0],
            \DomainException::class,
            'coth() is undefined at zero.',
        ];
        yield 'tangent at pole' => [
            'tan',
            [\M_PI / 2],
            \DomainException::class,
            'tan() is undefined where cosine is zero.',
        ];
        yield 'secant at pole' => [
            'sec',
            [\M_PI / 2],
            \DomainException::class,
            'sec() is undefined where cosine is zero.',
        ];
        yield 'cosecant at pole' => [
            'csc',
            [0],
            \DomainException::class,
            'csc() is undefined where sine is zero.',
        ];
        yield 'cotangent at pole' => [
            'cot',
            [0],
            \DomainException::class,
            'cot() is undefined where sine is zero.',
        ];
        yield 'arc secant inside range gap' => [
            'asec',
            [0.5],
            \DomainException::class,
            'asec() requires an argument with absolute value greater than or equal to one.',
        ];
        yield 'arc cosecant inside range gap' => [
            'acsc',
            [-0.5],
            \DomainException::class,
            'acsc() requires an argument with absolute value greater than or equal to one.',
        ];
        yield 'log2 zero' => [
            'log2',
            [0],
            \DomainException::class,
            'log2() requires a positive argument.',
        ];
        yield 'log1p negative one' => [
            'log1p',
            [-1],
            \DomainException::class,
            'log1p() requires an argument greater than -1.',
        ];
        yield 'atan2 origin' => [
            'atan2',
            [0, 0],
            \DomainException::class,
            'atan2() is undefined when both arguments are zero.',
        ];
        yield 'gcd non-integer' => [
            'gcd',
            [2.5, 5],
            \DomainException::class,
            'gcd() requires finite integer arguments.',
        ];
        yield 'gcd float outside host integer range' => [
            'gcd',
            [(float) \PHP_INT_MAX, 1],
            \DomainException::class,
            'gcd() requires finite integer arguments.',
        ];
        yield 'lcm overflow' => [
            'lcm',
            [\PHP_INT_MAX, 2],
            \OverflowException::class,
            'lcm() result exceeds the host integer range.',
        ];
        yield 'binomial non-integer' => [
            'binomial',
            [5.5, 2],
            \DomainException::class,
            'binomial() requires finite non-negative integer arguments.',
        ];
        yield 'permutation negative argument' => [
            'permutation',
            [5, -1],
            \DomainException::class,
            'permutation() requires finite non-negative integer arguments.',
        ];
        yield 'binomial overflow' => [
            'binomial',
            [67, 33],
            \OverflowException::class,
            'binomial() result exceeds the host integer range.',
        ];
        yield 'permutation overflow' => [
            'permutation',
            [21, 21],
            \OverflowException::class,
            'permutation() result exceeds the host integer range.',
        ];
    }

    public function testOnlyTheExactCaseSensitiveAllowlistIsPresentByDefault(): void
    {
        $registry = FunctionRegistry::defaults();

        self::assertNull($registry->find('unknown'));
        self::assertNull($registry->find('ABS'));
        self::assertNull($registry->find('Sqrt'));
        self::assertNull($registry->find('system'));
        self::assertNull($registry->find('eval'));
    }

    public function testWithReturnsANewRegistryWithoutMutatingTheOriginal(): void
    {
        $original = FunctionRegistry::defaults();
        $definition = self::definition('double', 1, 1, 2);

        $extended = $original->with($definition);

        self::assertNotSame($original, $extended);
        self::assertNull($original->find('double'));
        self::assertSame($definition, $extended->find('double'));
        self::assertSame($original->find('sqrt'), $extended->find('sqrt'));
    }

    public function testCustomDefinitionsNeverAcquireBuiltInProvenance(): void
    {
        $registry = FunctionRegistry::defaults()->with(
            self::definition('spoof', 0, 0, 1),
        );

        self::assertFalse($registry->isBuiltIn('spoof'));
        self::assertFalse($registry->isBuiltIn('Spoof'));
        self::assertFalse($registry->isBuiltIn('unknown'));
        self::assertTrue($registry->isBuiltIn('abs'));
    }

    public function testIndependentExtensionsDoNotLeakMembership(): void
    {
        $defaults = FunctionRegistry::defaults();
        $firstDefinition = self::definition('first', 0, 0, 1);
        $secondDefinition = self::definition('second', 0, 0, 2);

        $first = $defaults->with($firstDefinition);
        $second = $defaults->with($secondDefinition);

        self::assertSame($firstDefinition, $first->find('first'));
        self::assertNull($first->find('second'));
        self::assertSame($secondDefinition, $second->find('second'));
        self::assertNull($second->find('first'));
        self::assertNull($defaults->find('first'));
        self::assertNull($defaults->find('second'));
    }

    #[DataProvider('reservedNameProvider')]
    public function testConstantsCannotBeRegisteredAsFunctions(string $name): void
    {
        $registry = FunctionRegistry::defaults();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'The constant name "%s" is reserved and cannot be registered as a function.',
                $name,
            ),
        );

        $registry->with(self::definition($name, 0, 0, 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNameProvider(): iterable
    {
        yield 'pi' => ['pi'];
        yield 'e' => ['e'];
    }

    #[DataProvider('existingNameProvider')]
    public function testExistingFunctionsCannotBeOverridden(string $name): void
    {
        $registry = FunctionRegistry::defaults();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'Function "%s" is already registered and cannot be overridden.',
                $name,
            ),
        );

        $registry->with(self::definition($name, 0, 0, 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingNameProvider(): iterable
    {
        yield 'abs' => ['abs'];
        yield 'sqrt' => ['sqrt'];
        yield 'sin' => ['sin'];
        yield 'cos' => ['cos'];
        yield 'exp' => ['exp'];
        yield 'ln' => ['ln'];
        yield 'log' => ['log'];
        yield 'floor' => ['floor'];
        yield 'ceil' => ['ceil'];
        yield 'round' => ['round'];
    }

    public function testAnAlreadyAddedCustomFunctionCannotBeOverridden(): void
    {
        $registry = FunctionRegistry::defaults()->with(
            self::definition('custom', 0, 0, 1),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Function "custom" is already registered and cannot be overridden.',
        );

        $registry->with(self::definition('custom', 0, 0, 2));
    }

    public function testNamesThatOnlyDifferByCaseRemainDistinct(): void
    {
        $upperAbs = self::definition('Abs', 0, 0, 99);
        $upperPi = self::definition('Pi', 0, 0, 3);
        $registry = FunctionRegistry::defaults()
            ->with($upperAbs)
            ->with($upperPi);

        self::assertSame($upperAbs, $registry->find('Abs'));
        self::assertSame($upperPi, $registry->find('Pi'));
        self::assertNotSame($upperAbs, $registry->find('abs'));
        self::assertNull($registry->find('pi'));
    }

    public function testItIsAFinalAndReadonlyWithNoPublicConstructor(): void
    {
        $reflection = new \ReflectionClass(FunctionRegistry::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    private static function definition(
        string $name,
        int $minimum,
        int $maximum,
        int $result,
    ): FunctionDefinition {
        return new FunctionDefinition(
            $name,
            $minimum,
            $maximum,
            static fn (array $arguments): int => $result + (0 * \count($arguments)),
        );
    }
}
