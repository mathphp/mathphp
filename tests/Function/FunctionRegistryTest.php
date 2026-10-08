<?php

declare(strict_types=1);

namespace MathPHP\Tests\Function;

use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionRegistryTest extends TestCase
{
    public function testLogarithmicIntegralRemainsFiniteNearLargestFloat(): void
    {
        $function = FunctionRegistry::defaults()->find('li');
        self::assertInstanceOf(FunctionDefinition::class, $function);

        $scaledResult = $function->invoke([\PHP_FLOAT_MAX]) / \PHP_FLOAT_MAX;
        self::assertTrue(\is_finite($scaledResult));
        self::assertGreaterThan(1.0 / \log(\PHP_FLOAT_MAX), $scaledResult);
        self::assertLessThan(2.0 / \log(\PHP_FLOAT_MAX), $scaledResult);
    }

    public function testGeneralizedExponentialIntegralIsContinuousAcrossEvaluationBranches(): void
    {
        $function = FunctionRegistry::defaults()->find('expint');
        self::assertInstanceOf(FunctionDefinition::class, $function);

        $below = $function->invoke([2, 0.999999]);
        $at = $function->invoke([2, 1.0]);
        $above = $function->invoke([2, 1.000001]);

        self::assertGreaterThan($at, $below);
        self::assertGreaterThan($above, $at);
        self::assertEqualsWithDelta(0.0, $below - $above, 1.0e-6);
    }

    public function testNormalizedSincHandlesVeryLargeArgumentsWithoutOverflow(): void
    {
        $function = FunctionRegistry::defaults()->find('sincpi');
        self::assertInstanceOf(FunctionDefinition::class, $function);

        $value = $function->invoke([1.0e308]);
        self::assertTrue(\is_finite($value));
        self::assertLessThanOrEqual(1.0 / M_PI / 1.0e308, \abs($value));
    }

    public function testCosineQuotientsAreStableAtZeroAndForHugeInputs(): void
    {
        $cosc = FunctionRegistry::defaults()->find('cosc');
        $coscPi = FunctionRegistry::defaults()->find('coscpi');
        self::assertInstanceOf(FunctionDefinition::class, $cosc);
        self::assertInstanceOf(FunctionDefinition::class, $coscPi);

        self::assertSame(0.5, $cosc->invoke([0]));
        self::assertSame(0.5, $coscPi->invoke([0]));
        self::assertEqualsWithDelta(2.0 / (M_PI * M_PI), $cosc->invoke([M_PI]), 1.0e-15);
        self::assertEqualsWithDelta(2.0 / (M_PI * M_PI), $coscPi->invoke([1]), 1.0e-15);
        self::assertSame(0.0, $coscPi->invoke([2]));
        self::assertEqualsWithDelta(0.5 - 1.0e-16 / 24.0, $cosc->invoke([1.0e-8]), 1.0e-16);

        foreach ([$cosc, $coscPi] as $function) {
            self::assertTrue(is_finite($function->invoke([1.0e308])));
            try {
                $function->invoke([INF]);
            } catch (\InvalidArgumentException) {
                continue;
            }
            self::fail($function->name . ' should reject non-finite inputs.');
        }
    }

    public function testGudermannianAndInverseRoundTrip(): void
    {
        $functions = FunctionRegistry::defaults();
        $gd = $functions->find('gd');
        $inverse = $functions->find('invgd');
        self::assertInstanceOf(FunctionDefinition::class, $gd);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);

        foreach ([-8.0, -2.0, -0.25, 0.0, 0.25, 2.0, 8.0] as $value) {
            self::assertEqualsWithDelta($value, $inverse->invoke([$gd->invoke([$value])]), 2.0e-14);
        }
    }

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

    public function testLambertWSecondDerivativeRetainsNearBranchPointScale(): void
    {
        $registry = FunctionRegistry::defaults();
        $lambert = $registry->find('lambertw');
        $secondDerivative = $registry->find('lambertwsecond');
        self::assertInstanceOf(FunctionDefinition::class, $lambert);
        self::assertInstanceOf(FunctionDefinition::class, $secondDerivative);

        $value = -1.0 / M_E + 1.0e-16;
        $w = $lambert->invoke([$value]);
        $expected = -(($w / $value) ** 2) * ($w + 2.0) / ((1.0 + $w) ** 3);
        $actual = $secondDerivative->invoke([$value]);

        self::assertTrue(is_finite($actual));
        self::assertGreaterThan(1.0e23, abs($actual));
        self::assertEqualsWithDelta($expected, $actual, abs($expected) * 1.0e-12);
    }

    public function testLambertWThirdDerivativeMatchesFiniteDifferenceOfSecondDerivative(): void
    {
        $registry = FunctionRegistry::defaults();
        $secondDerivative = $registry->find('lambertwsecond');
        $thirdDerivative = $registry->find('lambertwthird');
        self::assertInstanceOf(FunctionDefinition::class, $secondDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $thirdDerivative);

        $step = 1.0e-5;
        $finiteDifference = ($secondDerivative->invoke([1.0 + $step])
            - $secondDerivative->invoke([1.0 - $step])) / (2.0 * $step);

        self::assertEqualsWithDelta($finiteDifference, $thirdDerivative->invoke([1.0]), 1.0e-9);
    }

    public function testErrorFunctionRetainsRelativeAccuracyNearZero(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (['erf', 'erfi'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);

            foreach ([1.0e-16, -1.0e-16, 1.0e-10, -1.0e-10] as $argument) {
                $expected = (2.0 / sqrt(M_PI)) * $argument;
                self::assertEqualsWithDelta($expected, $function->invoke([$argument]), 1.0e-30, $name);
            }
        }
    }

    public function testEntireExponentialIntegralRetainsItsSmallArgumentBehavior(): void
    {
        $ein = FunctionRegistry::defaults()->find('ein');
        self::assertInstanceOf(FunctionDefinition::class, $ein);
        self::assertSame(1.0e-16, $ein->invoke([1.0e-16]));
        self::assertSame(-1.0e-16, $ein->invoke([-1.0e-16]));
    }

    public function testSineAndCosineIntegralsRemainStableAcrossTheirAsymptoticBranch(): void
    {
        $functions = FunctionRegistry::defaults();
        $si = $functions->find('sineintegral');
        $ci = $functions->find('cosineintegral');
        self::assertInstanceOf(FunctionDefinition::class, $si);
        self::assertInstanceOf(FunctionDefinition::class, $ci);

        foreach ([8.0, 8.000001, 10.0, 20.0, 1000.0] as $value) {
            self::assertEqualsWithDelta($si->invoke([$value]), -$si->invoke([-$value]), 2.0e-14);
        }
        self::assertEqualsWithDelta(1.54824170104344, $si->invoke([20.0]), 1.0e-12);
        self::assertEqualsWithDelta(0.0444198208453533, $ci->invoke([20.0]), 1.0e-12);
        self::assertSame(0.0, $si->invoke([0]));
        self::assertSame(M_PI / 2.0, $si->invoke([1.0e100]));
        self::assertEqualsWithDelta(\sin(1.0e100) / 1.0e100, $ci->invoke([1.0e100]), 1.0e-115);
        self::assertEqualsWithDelta(
            \sin(32.0) / 32.0 * 2.0e-6,
            $si->invoke([32.000001]) - $si->invoke([31.999999]),
            1.0e-10,
        );
        self::assertEqualsWithDelta(
            \cos(32.0) / 32.0 * 2.0e-6,
            $ci->invoke([32.000001]) - $ci->invoke([31.999999]),
            1.0e-10,
        );

        foreach ([8.0, 8.000001, 10.0, 20.0, 1000.0] as $value) {
            self::assertTrue(\is_finite($ci->invoke([$value])));
        }
    }

    public function testDilogarithmUsesRealIdentitiesAcrossItsDomain(): void
    {
        $functions = FunctionRegistry::defaults();
        $dilog = $functions->find('dilog');
        $li2 = $functions->find('li2');
        self::assertInstanceOf(FunctionDefinition::class, $dilog);
        self::assertInstanceOf(FunctionDefinition::class, $li2);

        self::assertSame(0.0, $dilog->invoke([0]));
        self::assertEqualsWithDelta(M_PI * M_PI / 6.0, $dilog->invoke([1]), 1.0e-15);
        self::assertEqualsWithDelta(M_PI * M_PI / 12.0 - log(2.0) ** 2 / 2.0, $dilog->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(-M_PI * M_PI / 12.0, $dilog->invoke([-1]), 1.0e-15);
        self::assertEqualsWithDelta(-1.436746366883681, $dilog->invoke([-2]), 1.0e-14);
        self::assertEqualsWithDelta(1.6449340668482264, $li2->invoke([1]), 1.0e-15);

        foreach ([1.0e-12, 0.25, 0.5, 0.75, 0.999999, -0.25, -1.0, -100.0] as $value) {
            self::assertTrue(is_finite($dilog->invoke([$value])));
        }
        $x = 0.37;
        self::assertEqualsWithDelta(
            M_PI * M_PI / 6.0 - log($x) * log1p(-$x) - $dilog->invoke([1.0 - $x]),
            $dilog->invoke([$x]),
            2.0e-15,
        );
        try {
            $dilog->invoke([1.000001]);
        } catch (\DomainException) {
            return;
        }
        self::fail('dilog() should reject arguments above the real branch endpoint.');
    }

    public function testPolylogarithmValuesAndEndpointDomains(): void
    {
        $polylog = FunctionRegistry::defaults()->find('polylog');
        self::assertInstanceOf(FunctionDefinition::class, $polylog);

        self::assertSame(0.0, $polylog->invoke([2, 0]));
        self::assertEqualsWithDelta(M_PI * M_PI / 12.0 - log(2.0) ** 2 / 2.0, $polylog->invoke([2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(1.2020569031595942, $polylog->invoke([3, 1]), 2.0e-14);
        self::assertEqualsWithDelta(-log(2.0), $polylog->invoke([1, -1]), 2.0e-14);
        self::assertEqualsWithDelta(1.0, $polylog->invoke([0, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(2.0, $polylog->invoke([-1, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(-0.4484142069236462, $polylog->invoke([2, -0.5]), 2.0e-14);

        foreach ([[1, 1], [0, -1], [2, 1.1], [INF, 0.5]] as $arguments) {
            try {
                $polylog->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('polylog() should reject inputs outside its real convergent domain.');
        }
    }

    public function testLerchPhiValuesIdentitiesAndConvergenceDomain(): void
    {
        $lerch = FunctionRegistry::defaults()->find('lerchphi');
        self::assertInstanceOf(FunctionDefinition::class, $lerch);

        self::assertEqualsWithDelta(1.164481052930025, $lerch->invoke([0.5, 2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(M_PI * M_PI / 6.0, $lerch->invoke([1, 2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(M_PI * M_PI / 2.0, $lerch->invoke([1, 2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(log(2.0), $lerch->invoke([-1, 1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(M_PI / 2.0, $lerch->invoke([-1, 1, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(-1.5, $lerch->invoke([1, 0, 2]), 2.0e-14);
        self::assertEqualsWithDelta(-1.0 / 12.0, $lerch->invoke([1, -1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(0.5, $lerch->invoke([-1, 0, 1]), 2.0e-14);
        self::assertEqualsWithDelta(0.25, $lerch->invoke([-1, -1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(0.25, $lerch->invoke([0, 2, 2]), 0.0);

        foreach ([[1, 1, 1], [1, 2, 0], [0.5, 2, 0], [1.1, 2, 1]] as $arguments) {
            try {
                $lerch->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('lerchphi() should reject divergent or invalid inputs.');
        }
    }

    public function testGegenbauerPolynomialsReduceToLegendreAndChebyshevFamilies(): void
    {
        $functions = FunctionRegistry::defaults();
        $gegenbauer = $functions->find('gegenbauer');
        $ultraspherical = $functions->find('ultraspherical');
        $legendre = $functions->find('legendrepoly');
        $chebyshev = $functions->find('chebyshevu');
        self::assertInstanceOf(FunctionDefinition::class, $gegenbauer);
        self::assertInstanceOf(FunctionDefinition::class, $ultraspherical);
        self::assertInstanceOf(FunctionDefinition::class, $legendre);
        self::assertInstanceOf(FunctionDefinition::class, $chebyshev);

        self::assertSame(1.0, $gegenbauer->invoke([0, 2, 4]));
        self::assertSame(0.0, $gegenbauer->invoke([5, 0, 2]));
        foreach ([0.0, 0.2, 0.5, 0.9, 1.0] as $value) {
            for ($degree = 0; $degree <= 12; ++$degree) {
                self::assertEqualsWithDelta(
                    $legendre->invoke([$degree, $value]),
                    $gegenbauer->invoke([$degree, 0.5, $value]),
                    2.0e-12,
                );
                self::assertEqualsWithDelta(
                    $chebyshev->invoke([$degree, $value]),
                    $ultraspherical->invoke([$degree, 1, $value]),
                    2.0e-12,
                );
            }
        }
        self::assertEqualsWithDelta(-0.5, $gegenbauer->invoke([1, 0.25, -1]), 1.0e-15);

        foreach ([[1, -0.5, 0], [1, INF, 0], [10001, 1, 0], [1, 1, INF]] as $arguments) {
            try {
                $gegenbauer->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('gegenbauer() should reject out-of-domain parameters.');
        }
    }

    public function testAssociatedLegendrePolynomialsFollowCondonShortleyConvention(): void
    {
        $functions = FunctionRegistry::defaults();
        $associated = $functions->find('associatedlegendre');
        $alias = $functions->find('assoclegendre');
        $legendre = $functions->find('legendrepoly');
        self::assertInstanceOf(FunctionDefinition::class, $associated);
        self::assertInstanceOf(FunctionDefinition::class, $alias);
        self::assertInstanceOf(FunctionDefinition::class, $legendre);

        self::assertSame(1.0, $associated->invoke([0, 0, 0.3]));
        self::assertEqualsWithDelta(-sqrt(0.75), $associated->invoke([1, 1, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(-1.299038105676658, $associated->invoke([2, 1, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(2.25, $associated->invoke([2, 2, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(-0.3247595264191645, $associated->invoke([3, 1, 0.5]), 1.0e-15);
        self::assertSame(0.0, $associated->invoke([5, 2, 1]));

        foreach ([-1.0, -0.5, 0.0, 0.5, 1.0] as $value) {
            for ($degree = 0; $degree <= 12; ++$degree) {
                self::assertEqualsWithDelta(
                    $legendre->invoke([$degree, $value]),
                    $associated->invoke([$degree, 0, $value]),
                    1.0e-14,
                );
            }
        }
        self::assertSame($associated->invoke([4, 2, 0.25]), $alias->invoke([4, 2, 0.25]));
        foreach ([[1, 2, 0], [-1, 0, 0], [2, 0, 1.01], [2, 0, INF], [10001, 0, 0]] as $arguments) {
            try {
                $associated->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('associatedlegendre() should reject invalid order, degree, or domain values.');
        }
    }

    public function testBernsteinBasisSumsToOneAcrossDegreesAndEndpoints(): void
    {
        $bernstein = FunctionRegistry::defaults()->find('bernstein');
        self::assertInstanceOf(FunctionDefinition::class, $bernstein);

        foreach ([0.0, 0.1, 0.25, 0.5, 0.9, 1.0] as $value) {
            for ($degree = 0; $degree <= 12; ++$degree) {
                $sum = 0.0;
                for ($index = 0; $index <= $degree; ++$index) {
                    $sum += $bernstein->invoke([$degree, $index, $value]);
                }
                self::assertEqualsWithDelta(1.0, $sum, 2.0e-13);
            }
        }
    }

    public function testThirdAndFourthKindChebyshevPolynomialsMatchTrigDefinitions(): void
    {
        $functions = FunctionRegistry::defaults();
        $third = $functions->find('chebyshevv');
        $fourth = $functions->find('chebyshevw');
        $thirdAlias = $functions->find('chebyshevthird');
        $fourthAlias = $functions->find('chebyshebfourth');
        self::assertInstanceOf(FunctionDefinition::class, $third);
        self::assertInstanceOf(FunctionDefinition::class, $fourth);
        self::assertInstanceOf(FunctionDefinition::class, $thirdAlias);
        self::assertInstanceOf(FunctionDefinition::class, $fourthAlias);
        self::assertSame(2, $third->minArguments);
        self::assertSame(2, $fourth->maxArguments);

        self::assertSame(1.0, $third->invoke([0, 8]));
        self::assertSame(1.0, $fourth->invoke([0, 8]));
        self::assertSame(-1.0, $third->invoke([1, 0]));
        self::assertSame(1.0, $fourth->invoke([1, 0]));
        self::assertSame(5.0, $third->invoke([2, -1]));
        self::assertSame(1.0, $fourth->invoke([2, -1]));
        self::assertSame(-3.0, $third->invoke([1, -1]));
        self::assertSame(5.0, $fourth->invoke([2, 1]));

        $angle = 0.73;
        $value = cos($angle);
        for ($degree = 0; $degree <= 20; ++$degree) {
            $expectedThird = cos(($degree + 0.5) * $angle) / cos($angle / 2.0);
            $expectedFourth = sin(($degree + 0.5) * $angle) / sin($angle / 2.0);
            self::assertEqualsWithDelta($expectedThird, $third->invoke([$degree, $value]), 2.0e-13);
            self::assertEqualsWithDelta($expectedFourth, $fourth->invoke([$degree, $value]), 2.0e-13);
        }
        self::assertSame($third->invoke([9, 0.25]), $thirdAlias->invoke([9, 0.25]));
        self::assertSame($fourth->invoke([9, 0.25]), $fourthAlias->invoke([9, 0.25]));
        foreach ([[-1, 0], [10001, 0], [1, INF]] as $arguments) {
            try {
                $third->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Third-kind Chebyshev polynomials should reject invalid input.');
        }
    }

    public function testJacobiEllipticFunctionsSatisfyTheirAlgebraicIdentities(): void
    {
        $functions = FunctionRegistry::defaults();
        $sn = $functions->find('jacobisn');
        $cn = $functions->find('jacobicn');
        $dn = $functions->find('jacobidn');
        self::assertInstanceOf(FunctionDefinition::class, $sn);
        self::assertInstanceOf(FunctionDefinition::class, $cn);
        self::assertInstanceOf(FunctionDefinition::class, $dn);
        foreach (
            [
                'sn' => $functions->find('sn'),
                'cn' => $functions->find('cn'),
                'dn' => $functions->find('dn'),
            ] as $name => $alias
        ) {
            self::assertInstanceOf(FunctionDefinition::class, $alias, $name);
            self::assertSame(2, $alias->minArguments);
            self::assertSame(2, $alias->maxArguments);
        }

        self::assertSame(0.0, $sn->invoke([0, 0.5]));
        self::assertSame(1.0, $cn->invoke([0, 0.5]));
        self::assertSame(1.0, $dn->invoke([0, 0.5]));
        self::assertEqualsWithDelta(0.803001824895644, $sn->invoke([1, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(0.595976567672141, $cn->invoke([1, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(0.823161001631596, $dn->invoke([1, 0.5]), 2.0e-14);

        foreach ([0.0, 0.1, 0.5, 0.9, 0.999999, 1.0] as $parameter) {
            foreach ([-10.0, -2.0, -0.1, 0.0, 0.25, 1.0, 10.0, 1.0e100] as $argument) {
                $s = $sn->invoke([$argument, $parameter]);
                $c = $cn->invoke([$argument, $parameter]);
                $d = $dn->invoke([$argument, $parameter]);
                self::assertEqualsWithDelta(1.0, $s * $s + $c * $c, 3.0e-14);
                self::assertEqualsWithDelta(1.0, $d * $d + $parameter * $s * $s, 3.0e-14);
                self::assertEqualsWithDelta($s, -$sn->invoke([-$argument, $parameter]), 3.0e-14);
                self::assertEqualsWithDelta($c, $cn->invoke([-$argument, $parameter]), 3.0e-14);
                self::assertEqualsWithDelta($d, $dn->invoke([-$argument, $parameter]), 3.0e-14);
            }
        }
        self::assertEqualsWithDelta(tanh(2.0), $sn->invoke([2, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / cosh(2.0), $cn->invoke([2, 1]), 1.0e-15);
        self::assertEqualsWithDelta(sin(2.0), $sn->invoke([2, 0]), 1.0e-15);
        self::assertSame(1.0, $dn->invoke([2, 0]));

        foreach ([[1, -0.5], [1, 1.01], [INF, 0.5]] as $arguments) {
            try {
                $sn->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Jacobi elliptic functions should reject parameters outside their real domain.');
        }
    }

    public function testEllipticNomeConvertsParameterForJacobiThetaFunctions(): void
    {
        $functions = FunctionRegistry::defaults();
        $nome = $functions->find('ellipnome');
        $theta2 = $functions->find('jacobitheta2');
        $theta3 = $functions->find('jacobitheta3');
        self::assertInstanceOf(FunctionDefinition::class, $nome);
        self::assertInstanceOf(FunctionDefinition::class, $theta2);
        self::assertInstanceOf(FunctionDefinition::class, $theta3);

        foreach ([0.0, 1.0e-8, 1.0e-4, 0.1, 0.5, 0.9, 0.999] as $parameter) {
            $q = $nome->invoke([$parameter]);
            $ratio = $theta2->invoke([0.0, $q]) / $theta3->invoke([0.0, $q]);
            self::assertEqualsWithDelta($parameter, $ratio ** 4, 2.0e-13);
        }

        $inverseNome = $functions->find('ellipnomeinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverseNome);
        foreach ([0.0, 1.0e-12, 0.01, 0.2, 0.5, 0.6, 0.7] as $q) {
            $parameter = $inverseNome->invoke([$q]);
            self::assertEqualsWithDelta(
                $q,
                $nome->invoke([$parameter]),
                $q > 0.5 ? 2.0e-8 : 3.0e-12,
            );
        }
    }

    public function testJacobiZetaIsOddPeriodicAndHasExpectedDerivative(): void
    {
        $functions = FunctionRegistry::defaults();
        $zeta = $functions->find('jacobizeta');
        $alias = $functions->find('jzeta');
        $dn = $functions->find('jacobidn');
        $ellipK = $functions->find('ellipk');
        $ellipE = $functions->find('ellipe');
        self::assertInstanceOf(FunctionDefinition::class, $zeta);
        self::assertInstanceOf(FunctionDefinition::class, $alias);
        self::assertInstanceOf(FunctionDefinition::class, $dn);
        self::assertInstanceOf(FunctionDefinition::class, $ellipK);
        self::assertInstanceOf(FunctionDefinition::class, $ellipE);
        self::assertSame(2, $zeta->minArguments);
        self::assertSame(2, $zeta->maxArguments);

        self::assertSame(0.0, $zeta->invoke([0, 0.5]));
        self::assertSame(0.0, $zeta->invoke([3.0, 0]));
        self::assertEqualsWithDelta(tanh(2.0), $zeta->invoke([2, 1]), 1.0e-15);
        $parameter = 0.5;
        $completeK = $ellipK->invoke([$parameter]);
        self::assertEqualsWithDelta(0.0, $zeta->invoke([$completeK, $parameter]), 2.0e-15);
        $argument = 0.83;
        $value = $zeta->invoke([$argument, $parameter]);
        self::assertEqualsWithDelta(-$value, $zeta->invoke([-$argument, $parameter]), 2.0e-14);
        self::assertEqualsWithDelta(
            $value,
            $zeta->invoke([$argument + 2.0 * $completeK, $parameter]),
            3.0e-14,
        );
        self::assertSame($value, $alias->invoke([$argument, $parameter]));

        $step = 1.0e-5;
        $derivative = ($zeta->invoke([$argument + $step, $parameter])
                - $zeta->invoke([$argument - $step, $parameter]))
            / (2.0 * $step);
        $expectedDerivative = $dn->invoke([$argument, $parameter]) ** 2
            - $ellipE->invoke([$parameter]) / $completeK;
        self::assertEqualsWithDelta($expectedDerivative, $derivative, 2.0e-10);
        try {
            $zeta->invoke([1.0, 1.01]);
        } catch (\DomainException) {
            return;
        }
        self::fail('jacobizeta() should reject parameters outside [0, 1].');
    }

    public function testFresnelIntegralsHandleOriginParityAndOscillatoryTails(): void
    {
        $functions = FunctionRegistry::defaults();
        $cosine = $functions->find('fresnelc');
        $sine = $functions->find('fresnels');
        $cosineDerivative = $functions->find('fresnelcprime');
        $sineDerivative = $functions->find('fresnelsprime');
        $cosineSecondDerivative = $functions->find('fresnelcsecond');
        $sineSecondDerivative = $functions->find('fresnelssecond');
        self::assertInstanceOf(FunctionDefinition::class, $cosine);
        self::assertInstanceOf(FunctionDefinition::class, $sine);
        self::assertInstanceOf(FunctionDefinition::class, $cosineDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $sineDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $cosineSecondDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $sineSecondDerivative);

        self::assertSame(0.0, $cosine->invoke([0]));
        self::assertSame(0.0, $sine->invoke([0]));
        self::assertSame(0.0, $cosineSecondDerivative->invoke([0]));
        self::assertSame(0.0, $sineSecondDerivative->invoke([0]));
        self::assertEqualsWithDelta(0.779893400376823, $cosine->invoke([1]), 1.0e-14);
        self::assertEqualsWithDelta(0.438259147390355, $sine->invoke([1]), 1.0e-14);
        self::assertEqualsWithDelta(-M_PI, $cosineSecondDerivative->invoke([1]), 1.0e-14);
        self::assertEqualsWithDelta(0.0, $sineSecondDerivative->invoke([1]), 1.0e-14);
        self::assertEqualsWithDelta(M_PI, $cosineSecondDerivative->invoke([-1]), 1.0e-14);
        self::assertEqualsWithDelta(0.488253406075341, $cosine->invoke([2]), 1.0e-14);
        self::assertEqualsWithDelta(0.343415678363698, $sine->invoke([2]), 1.0e-14);
        self::assertEqualsWithDelta(0.563631188704012, $cosine->invoke([5]), 1.0e-12);
        self::assertEqualsWithDelta(0.499191381917117, $sine->invoke([5]), 1.0e-12);
        self::assertEqualsWithDelta(0.49989869420551675, $cosine->invoke([10]), 1.0e-12);
        self::assertEqualsWithDelta(0.46816997858488224, $sine->invoke([10]), 1.0e-12);

        foreach ([0.1, 1.0, 2.0, 5.0, 8.0, 20.0, 1.0e100] as $value) {
            self::assertEqualsWithDelta($cosine->invoke([$value]), -$cosine->invoke([-$value]), 1.0e-14);
            self::assertEqualsWithDelta($sine->invoke([$value]), -$sine->invoke([-$value]), 1.0e-14);
        }
        self::assertSame(0.5, $cosine->invoke([1.0e100]));
        self::assertSame(0.5, $sine->invoke([1.0e100]));

        self::assertSame(1.0, $cosineDerivative->invoke([0.0]));
        self::assertSame(0.0, $sineDerivative->invoke([0.0]));
        self::assertEqualsWithDelta(cos(M_PI / 2.0), $cosineDerivative->invoke([1.0]), 1.0e-15);
        self::assertEqualsWithDelta(sin(M_PI / 2.0), $sineDerivative->invoke([1.0]), 1.0e-15);
        foreach ([0.1, 1.0, 2.0, 5.0, 20.0] as $value) {
            self::assertSame($cosineDerivative->invoke([$value]), $cosineDerivative->invoke([-$value]));
            self::assertSame($sineDerivative->invoke([$value]), $sineDerivative->invoke([-$value]));
        }
        $step = 1.0e-5;
        $argument = 0.7;
        self::assertEqualsWithDelta(
            $cosineDerivative->invoke([$argument]),
            ($cosine->invoke([$argument + $step]) - $cosine->invoke([$argument - $step])) / (2.0 * $step),
            2.0e-10,
        );
        self::assertEqualsWithDelta(
            $sineDerivative->invoke([$argument]),
            ($sine->invoke([$argument + $step]) - $sine->invoke([$argument - $step])) / (2.0 * $step),
            2.0e-10,
        );
    }

    public function testEntireCosineIntegralIsContinuousAcrossItsCiTransition(): void
    {
        $cin = FunctionRegistry::defaults()->find('cin');
        self::assertInstanceOf(FunctionDefinition::class, $cin);

        self::assertEqualsWithDelta(2.53422332404936, $cin->invoke([8.0]), 2.0e-14);
        self::assertEqualsWithDelta(
            $cin->invoke([7.999999]),
            $cin->invoke([8.000001]),
            3.0e-7,
        );
        self::assertEqualsWithDelta($cin->invoke([100.0]), $cin->invoke([-100.0]), 0.0);
    }

    public function testScaledModifiedBesselIRemainsAccuratePastUnscaledOverflow(): void
    {
        $ive = FunctionRegistry::defaults()->find('ive');
        self::assertInstanceOf(FunctionDefinition::class, $ive);

        $value = 1.0e300;
        $expectedLeadingAsymptotic = (1.0 / sqrt(2.0 * M_PI)) / sqrt($value);
        self::assertEqualsWithDelta(
            $expectedLeadingAsymptotic,
            $ive->invoke([0, $value]),
            $expectedLeadingAsymptotic * 1.0e-12,
        );
    }

    public function testHyperbolicIntegralsUseScaledLargeArgumentAsymptotics(): void
    {
        $registry = FunctionRegistry::defaults();
        $shi = $registry->find('shi');
        $chi = $registry->find('chi');
        self::assertInstanceOf(FunctionDefinition::class, $shi);
        self::assertInstanceOf(FunctionDefinition::class, $chi);

        foreach ([$shi, $chi] as $function) {
            $scaledValue = $function->invoke([100]) * 200.0 * \exp(-100.0);
            self::assertEqualsWithDelta(1.0102062527748, $scaledValue, 1.0e-12);
            self::assertTrue(\is_finite($function->invoke([717])));
            try {
                $function->invoke([718]);
                self::fail($function->name . '() should report overflow beyond the finite range.');
            } catch (\OverflowException) {
                // The exception confirms the result no longer fits a finite float.
            }
        }
    }

    public function testDawsonFunctionPreservesOddnessAndLargeArgumentScaling(): void
    {
        $dawson = FunctionRegistry::defaults()->find('dawson');
        self::assertInstanceOf(FunctionDefinition::class, $dawson);
        self::assertSame(0.0, $dawson->invoke([0]));
        self::assertSame(1.0e-16, $dawson->invoke([1.0e-16]));
        foreach ([0.1, 0.5, 1.0, 5.0, 10.0, 100.0, 1.0e100] as $value) {
            self::assertEqualsWithDelta($dawson->invoke([$value]), -$dawson->invoke([-$value]), 1.0e-15);
        }
        self::assertEqualsWithDelta(5.0e-101, $dawson->invoke([1.0e100]), 1.0e-115);
    }

    public function testDawsonThirdDerivativeMatchesSecondDerivativeAcrossTailSwitch(): void
    {
        $functions = FunctionRegistry::defaults();
        $second = $functions->find('dawsonsecond');
        $third = $functions->find('dawsonthird');
        self::assertInstanceOf(FunctionDefinition::class, $second);
        self::assertInstanceOf(FunctionDefinition::class, $third);

        foreach ([1.0, 10.0, 99.0] as $value) {
            $step = $value * 1.0e-5;
            $finiteDifference = ($second->invoke([$value + $step])
                - $second->invoke([$value - $step])) / (2.0 * $step);
            self::assertEqualsWithDelta($finiteDifference, $third->invoke([$value]), 2.0e-7);
            self::assertEqualsWithDelta($third->invoke([$value]), $third->invoke([-$value]), 2.0e-12);
        }
    }

    public function testGammaSignMatchesTheReflectionSignAndRejectsPoles(): void
    {
        $functions = FunctionRegistry::defaults();
        $sign = $functions->find('gammasign');
        $gamma = $functions->find('gamma');
        self::assertInstanceOf(FunctionDefinition::class, $sign);
        self::assertInstanceOf(FunctionDefinition::class, $gamma);

        foreach ([-3.5, -2.5, -1.5, -0.5, 0.5, 1.0, 8.0] as $value) {
            self::assertSame($gamma->invoke([$value]) < 0.0 ? -1.0 : 1.0, $sign->invoke([$value]));
        }
        self::assertSame(-1.0, $sign->invoke([-1.0e-16]));
        foreach ([0.0, -1.0, -2.0] as $pole) {
            try {
                $sign->invoke([$pole]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('gammasign should reject gamma poles.');
        }
    }

    public function testComplementaryErrorFunctionAndInversePreserveSmallTails(): void
    {
        $functions = FunctionRegistry::defaults();
        $erfc = $functions->find('erfc');
        $erfcinv = $functions->find('erfcinv');
        self::assertInstanceOf(FunctionDefinition::class, $erfc);
        self::assertInstanceOf(FunctionDefinition::class, $erfcinv);

        foreach (
            [
                [4.0, 1.541725790028002e-8],
                [5.0, 1.5374597944280351e-12],
                [6.0, 2.1519736712498913e-17],
                [8.0, 1.1224297172982926e-29],
            ] as [$argument, $expected]
        ) {
            self::assertEqualsWithDelta(
                $expected,
                $erfc->invoke([$argument]),
                max(1.0e-300, $expected * 1.0e-12),
            );
        }
        self::assertEqualsWithDelta(2.0 - 1.541725790028002e-8, $erfc->invoke([-4.0]), 1.0e-15);
        $positive = $erfcinv->invoke([1.0e-20]);
        self::assertEqualsWithDelta(6.601580622355142, $positive, 1.0e-12);
        self::assertEqualsWithDelta(1.0e-20, $erfc->invoke([$positive]), 1.0e-33);
        foreach (
            [
                [1.0e-4, 2.7510639057120612],
                [1.0e-6, 3.4589107372795],
                [1.0e-7, 3.7665625815708381],
                [1.0e-8, 4.0522372438713887],
            ] as [$tail, $expected]
        ) {
            $inverse = $erfcinv->invoke([$tail]);
            self::assertEqualsWithDelta($expected, $inverse, 1.0e-12);
            self::assertEqualsWithDelta($tail, $erfc->invoke([$inverse]), $tail * 1.0e-12);
        }
        $nearTwo = 2.0 - 1.0e-14;
        self::assertEqualsWithDelta(-$erfcinv->invoke([2.0 - $nearTwo]), $erfcinv->invoke([$nearTwo]), 1.0e-12);
    }

    public function testComplementaryErrorFunctionIsContinuousAcrossItsAsymptoticBranch(): void
    {
        $erfc = FunctionRegistry::defaults()->find('erfc');
        self::assertInstanceOf(FunctionDefinition::class, $erfc);

        $expectedAtBoundary = 0.004677734981047265;
        $below = $erfc->invoke([1.999999]);
        $at = $erfc->invoke([2.0]);
        $above = $erfc->invoke([2.000001]);

        self::assertEqualsWithDelta(0.004677755648074377, $below, 1.0e-15);
        self::assertEqualsWithDelta($expectedAtBoundary, $at, 1.0e-15);
        self::assertEqualsWithDelta(0.004677714314103242, $above, 1.0e-15);
        self::assertGreaterThan($at, $below);
        self::assertGreaterThan($above, $at);
    }

    public function testScaledComplementaryErrorFunctionAvoidsExponentialOverflow(): void
    {
        $erfcx = FunctionRegistry::defaults()->find('erfcx');
        self::assertInstanceOf(FunctionDefinition::class, $erfcx);

        foreach (
            [
                [-2.0, 108.94090438997797],
                [0.0, 1.0],
                [1.0, 0.427583576155807],
                [2.0, 0.25539567631050575],
                [5.0, 0.11070463773306863],
                [1.0e100, 1.0 / (sqrt(M_PI) * 1.0e100)],
            ] as [$argument, $expected]
        ) {
            self::assertEqualsWithDelta($expected, $erfcx->invoke([$argument]), abs($expected) * 1.0e-12);
        }
    }

    public function testLogComplementaryErrorFunctionAndInversePreserveExtremeTails(): void
    {
        $functions = FunctionRegistry::defaults();
        $logErfc = $functions->find('logerfc');
        $logErfcDerivative = $functions->find('logerfcprime');
        $inverse = $functions->find('erfcinvlog');
        $inverseDerivative = $functions->find('erfcinvlogprime');
        self::assertInstanceOf(FunctionDefinition::class, $logErfc);
        self::assertInstanceOf(FunctionDefinition::class, $logErfcDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);

        self::assertEqualsWithDelta(log(2.0), $logErfc->invoke([-10.0]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.15729920705028513), $logErfc->invoke([1.0]), 1.0e-12);
        self::assertEqualsWithDelta(-2.0 / sqrt(M_PI), $logErfcDerivative->invoke([0.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -2.0 / sqrt(M_PI) / 0.427583576155807,
            $logErfcDerivative->invoke([1.0]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(-2.0e100, $logErfcDerivative->invoke([1.0e100]), 1.0e88);
        self::assertEqualsWithDelta(-0.5 * sqrt(M_PI), $inverseDerivative->invoke([0.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -0.5 * sqrt(M_PI) * 0.427583576155807,
            $inverseDerivative->invoke([log(0.15729920705028513)]),
            1.0e-12,
        );
        foreach ([-1.0e-12, -1.0, -100.0, -1.0e100] as $logProbability) {
            $quantile = $inverse->invoke([$logProbability]);
            self::assertEqualsWithDelta(
                $logProbability,
                $logErfc->invoke([$quantile]),
                max(1.0e-12, abs($logProbability) * 1.0e-12),
            );
        }
    }

    public function testNormalDistributionFunctionsSupportDefaultsAndLocationScale(): void
    {
        $functions = FunctionRegistry::defaults();
        $pdf = $functions->find('normalpdf');
        $cdf = $functions->find('normalcdf');
        $ccdf = $functions->find('normalccdf');
        $hazard = $functions->find('normalhazard');
        $cumulativeHazard = $functions->find('normalcumhazard');
        $inverse = $functions->find('normalinv');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);

        self::assertEqualsWithDelta(1.0 / sqrt(2.0 * M_PI), $pdf->invoke([0]), 1.0e-15);
        self::assertEqualsWithDelta(0.8413447460685429, $cdf->invoke([1]), 1.0e-15);
        self::assertEqualsWithDelta(0.15865525393145707, $cdf->invoke([-1]), 1.0e-15);
        self::assertEqualsWithDelta(0.15865525393145707, $ccdf->invoke([1]), 1.0e-15);
        self::assertGreaterThan(0.999999999999999, $cdf->invoke([8]));
        self::assertEqualsWithDelta(6.22096057427178e-16, $ccdf->invoke([8]), 1.0e-29);
        self::assertEqualsWithDelta(1.0, $ccdf->invoke([-8]), 1.0e-15);
        self::assertEqualsWithDelta(sqrt(2.0 / M_PI), $hazard->invoke([0]), 1.0e-15);
        self::assertEqualsWithDelta(10.09809323396251, $hazard->invoke([10]), 1.0e-13);
        self::assertEqualsWithDelta(40.02496884720726, $hazard->invoke([40]), 1.0e-12);
        self::assertSame(0.0, $hazard->invoke([-40]));
        self::assertEqualsWithDelta(
            $hazard->invoke([1]),
            2.0 * $hazard->invoke([3, 1, 2]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $hazard->invoke([2]) / \PHP_FLOAT_MAX,
            $hazard->invoke([\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX, \PHP_FLOAT_MAX]),
            1.0e-322,
        );
        self::assertEqualsWithDelta(log(2.0), $cumulativeHazard->invoke([0]), 1.0e-15);
        self::assertEqualsWithDelta(53.23128515051247, $cumulativeHazard->invoke([10]), 1.0e-12);
        self::assertEqualsWithDelta(804.6084420137536, $cumulativeHazard->invoke([40]), 1.0e-12);
        self::assertSame(0.0, $cumulativeHazard->invoke([-40]));
        self::assertEqualsWithDelta(
            -log($ccdf->invoke([3, 1, 2])),
            $cumulativeHazard->invoke([3, 1, 2]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $cumulativeHazard->invoke([2]),
            $cumulativeHazard->invoke([\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX, \PHP_FLOAT_MAX]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(0.15865525393145707, $ccdf->invoke([3, 1, 2]), 1.0e-15);
        self::assertEqualsWithDelta(0.17603266338214976, $pdf->invoke([2, 1, 2]), 1.0e-15);
        self::assertEqualsWithDelta(0.8413447460685429, $cdf->invoke([3, 1, 2]), 1.0e-15);
        self::assertSame(10.0, $inverse->invoke([0.5, 10, 2]));
        self::assertEqualsWithDelta(1.959963984540054, $inverse->invoke([0.975]), 1.0e-12);

        foreach ([1.0e-12, 0.01, 0.5, 0.975, 1.0 - 1.0e-12] as $probability) {
            $quantile = $inverse->invoke([$probability]);
            self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile]), 2.0e-15);
        }
    }

    public function testNormalDistributionFunctionsRejectInvalidScaleAndProbability(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (['normalpdf', 'normalcdf', 'normalinv', 'normalhazard', 'normalcumhazard'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($name === 'normalinv' ? [0.5, 0, 0] : [0, 0, 0]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject a zero standard deviation.');
        }

        $inverse = $functions->find('normalinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([0.0, 1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('normalinv should reject probabilities outside (0, 1).');
        }
    }

    public function testStudentTDistributionFunctionsMatchReferenceValuesAndRoundTrip(): void
    {
        $functions = FunctionRegistry::defaults();
        $pdf = $functions->find('studenttpdf');
        $cdf = $functions->find('studenttcdf');
        $ccdf = $functions->find('studenttccdf');
        $inverse = $functions->find('studenttinv');
        $logPdf = $functions->find('studenttlogpdf');
        $logCdf = $functions->find('studenttlogcdf');
        $logCcdf = $functions->find('studenttlogccdf');
        $mean = $functions->find('studenttmean');
        $variance = $functions->find('studenttvariance');
        $standardDeviation = $functions->find('studenttstddev');
        $median = $functions->find('studenttmedian');
        $mode = $functions->find('studenttmode');
        $entropy = $functions->find('studenttentropy');
        $hazard = $functions->find('studentthazard');
        $cumulativeHazard = $functions->find('studenttcumhazard');
        $skewness = $functions->find('studenttskewness');
        $excessKurtosis = $functions->find('studenttexcesskurtosis');
        $kurtosis = $functions->find('studenttkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertEqualsWithDelta(1.0 / M_PI, $pdf->invoke([0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(M_PI), $logPdf->invoke([0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log(M_PI) - 2.0 * log(1.0e200),
            $logPdf->invoke([1.0e200, 1]),
            1.0e-12,
        );
        self::assertSame(0.0, $pdf->invoke([1.0e200, 1]));
        self::assertEqualsWithDelta(0.75, $cdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.25, $cdf->invoke([-1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.25, $ccdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logCdf->invoke([-1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logCcdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.75), $logCdf->invoke([3, 1, 1, 2]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([-1.0e200, 1]));
        self::assertEqualsWithDelta(
            -log(M_PI) - log(1.0e200),
            $logCdf->invoke([-1.0e200, 1]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            -log(M_PI) - log(1.0e200),
            $logCcdf->invoke([1.0e200, 1]),
            1.0e-12,
        );
        $extremeLogTail = $logCcdf->invoke([1.0e200, 5]);
        self::assertTrue(is_finite($extremeLogTail));
        self::assertLessThan(-2000.0, $extremeLogTail);
        self::assertSame($extremeLogTail, $logCdf->invoke([-1.0e200, 5]));
        self::assertEqualsWithDelta(0.82955343384897, $cdf->invoke([1, 10]), 1.0e-13);
        self::assertEqualsWithDelta(log($cdf->invoke([1, 10])), $logCdf->invoke([1, 10]), 1.0e-14);
        self::assertEqualsWithDelta(log($ccdf->invoke([1, 10])), $logCcdf->invoke([1, 10]), 1.0e-14);
        self::assertEqualsWithDelta(2.2281388519649385, $inverse->invoke([0.975, 10]), 3.0e-11);
        self::assertEqualsWithDelta(0.07957747154594767, $pdf->invoke([3, 1, 1, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.75, $cdf->invoke([3, 1, 1, 2]), 1.0e-14);
        self::assertSame(10.0, $inverse->invoke([0.5, 10, 10, 2]));
        self::assertSame(0.0, $mean->invoke([10]));
        self::assertSame(3.0, $mean->invoke([10, 3, 2]));
        self::assertEqualsWithDelta(5.0, $variance->invoke([10, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(5.0), $standardDeviation->invoke([10, 3, 2]), 1.0e-14);
        self::assertSame(3.0, $median->invoke([1, 3, 2]));
        self::assertSame(3.0, $mode->invoke([1, 3, 2]));
        self::assertEqualsWithDelta(log(8.0 * M_PI), $entropy->invoke([1, 3, 2]), 1.0e-13);
        self::assertEqualsWithDelta(
            $pdf->invoke([3, 10, 3, 2]) / $ccdf->invoke([3, 10, 3, 2]),
            $hazard->invoke([3, 10, 3, 2]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            -$logCcdf->invoke([3, 10, 3, 2]),
            $cumulativeHazard->invoke([3, 10, 3, 2]),
            1.0e-14,
        );
        self::assertSame($median->invoke([10, 3, 2]), $functions->find('tmedian')?->invoke([10, 3, 2]));
        self::assertSame($mode->invoke([10, 3, 2]), $functions->find('tmode')?->invoke([10, 3, 2]));
        self::assertSame($entropy->invoke([10, 3, 2]), $functions->find('tentropy')?->invoke([10, 3, 2]));
        self::assertSame($hazard->invoke([3, 10, 3, 2]), $functions->find('thazard')?->invoke([3, 10, 3, 2]));
        self::assertSame(
            $cumulativeHazard->invoke([3, 10, 3, 2]),
            $functions->find('tcumhazard')?->invoke([3, 10, 3, 2]),
        );
        self::assertSame(0.0, $skewness->invoke([10, 3, 2]));
        self::assertSame(1.0, $excessKurtosis->invoke([10, 3, 2]));
        self::assertSame(4.0, $kurtosis->invoke([10, 3, 2]));
        self::assertSame($mean->invoke([10, 3, 2]), $functions->find('tmean')?->invoke([10, 3, 2]));
        self::assertSame($variance->invoke([10, 3, 2]), $functions->find('tvar')?->invoke([10, 3, 2]));

        foreach (
            [
                ['studenttmean', $mean, [1.0]],
                ['studenttvariance', $variance, [2.0]],
                ['studenttstddev', $standardDeviation, [2.0]],
                ['studenttskewness', $skewness, [3.0]],
                ['studenttexcesskurtosis', $excessKurtosis, [4.0]],
                ['studenttkurtosis', $kurtosis, [4.0]],
            ] as [$name, $function, $arguments]
        ) {
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject degrees of freedom where the moment does not exist.');
        }

        $medianOffset = 1.0e-12;
        $zeroDensity = $pdf->invoke([0, 7]);
        $nearMedianQuantile = $inverse->invoke([0.5 + $medianOffset, 7]);
        self::assertEqualsWithDelta($medianOffset / $zeroDensity, $nearMedianQuantile, 1.0e-15);

        foreach ([1.0e-12, 0.01, 0.5, 0.975, 1.0 - 1.0e-12] as $probability) {
            $quantile = $inverse->invoke([$probability, 7]);
            $roundTrip = $probability < 0.5
                ? $cdf->invoke([$quantile, 7])
                : 1.0 - $ccdf->invoke([$quantile, 7]);
            self::assertEqualsWithDelta($probability, $roundTrip, 2.0e-14);
        }

        $upperTailQuantile = $inverse->invoke([1.0 - 1.0e-10, 7]);
        self::assertEqualsWithDelta(
            1.0 - (1.0 - 1.0e-10),
            $ccdf->invoke([$upperTailQuantile, 7]),
            1.0e-22,
        );
    }

    public function testStudentTFunctionsRejectInvalidParameters(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (
            [
                'studenttpdf', 'studenttlogpdf', 'studenttcdf', 'studenttccdf',
                'studenttlogcdf', 'studenttlogccdf', 'studenttinv',
            ] as $name
        ) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            $value = $name === 'studenttinv' ? 0.5 : 0.0;
            foreach (
                [[$value, 0], [$value, -1], [$value, 20_000], [$value, 1, 0, 0]] as $arguments
            ) {
                try {
                    $function->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject invalid degrees of freedom or scale.');
            }
        }

        $inverse = $functions->find('studenttinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([0.0, 1.0] as $probability) {
            try {
                $inverse->invoke([$probability, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('studenttinv should reject probabilities outside (0, 1).');
        }
    }

    public function testGammaDistributionLogTailsRemainUsefulAfterUnderflow(): void
    {
        $functions = FunctionRegistry::defaults();
        $logCdf = $functions->find('gammadistlogcdf');
        $logCcdf = $functions->find('gammadistlogccdf');
        $cdf = $functions->find('gammadistcdf');
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);

        self::assertEqualsWithDelta(
            2.0 * log(1.0e-200) - log(2.0),
            $logCdf->invoke([1.0e-200, 2, 1]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(-1000.0 + log(1001.0), $logCcdf->invoke([1000, 2, 1]), 1.0e-12);
        self::assertEqualsWithDelta(
            log($cdf->invoke([4, 2, 2])),
            $logCdf->invoke([4, 2, 2]),
            1.0e-14,
        );
        self::assertSame(0.0, $logCcdf->invoke([0, 2, 1]));
        try {
            $logCdf->invoke([0, 2, 1]);
            self::fail('Gamma log-CDF should reject the support boundary with no finite log probability.');
        } catch (\DomainException) {
            // The boundary CDF is zero, whose logarithm is not finite.
        }
    }

    public function testBinomialDistributionFunctionsMatchMassCdfTailAndQuantiles(): void
    {
        $functions = FunctionRegistry::defaults();
        $mass = $functions->find('binomialpmf');
        $logMass = $functions->find('binomiallogpmf');
        $cdf = $functions->find('binomialcdf');
        $ccdf = $functions->find('binomialccdf');
        $logCdf = $functions->find('binomiallogcdf');
        $logCcdf = $functions->find('binomiallogccdf');
        $inverse = $functions->find('binomialinv');
        $meanFunction = $functions->find('binomialmean');
        $varianceFunction = $functions->find('binomialvariance');
        $standardDeviationFunction = $functions->find('binomialstddev');
        $medianFunction = $functions->find('binomialmedian');
        $modeFunction = $functions->find('binomialmode');
        $rawMoment = $functions->find('binomialmoment');
        $mgf = $functions->find('binomialmgf');
        $logMgf = $functions->find('binomiallogmgf');
        $skewness = $functions->find('binomialskewness');
        $excessKurtosis = $functions->find('binomialexcesskurtosis');
        $kurtosis = $functions->find('binomialkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $mass);
        self::assertInstanceOf(FunctionDefinition::class, $logMass);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $meanFunction);
        self::assertInstanceOf(FunctionDefinition::class, $varianceFunction);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviationFunction);
        self::assertInstanceOf(FunctionDefinition::class, $medianFunction);
        self::assertInstanceOf(FunctionDefinition::class, $modeFunction);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertEqualsWithDelta(0.24609375, $mass->invoke([5, 10, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.24609375), $logMass->invoke([5, 10, 0.5]), 1.0e-14);
        self::assertSame(-INF, $logMass->invoke([-1, 10, 0.5]));
        self::assertSame(-INF, $logMass->invoke([1, 10, 0]));
        self::assertSame(0.0, $logMass->invoke([0, 10, 0]));
        self::assertSame(0.0, $mass->invoke([0, 9999, 0.5]));
        self::assertLessThan(0.0, $logMass->invoke([0, 9999, 0.5]));
        self::assertSame(0.0, $mass->invoke([2.5, 10, 0.5]));
        self::assertSame(0.0, $mass->invoke([-1, 10, 0.5]));
        self::assertEqualsWithDelta(0.376953125, $cdf->invoke([4, 10, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(0.623046875, $ccdf->invoke([4, 10, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.376953125), $logCdf->invoke([4, 10, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.623046875), $logCcdf->invoke([4, 10, 0.5]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([10, 10, 0.5]));
        self::assertSame(0.0, $logCcdf->invoke([-1, 10, 0.5]));
        self::assertEqualsWithDelta(
            9999.0 * log1p(-0.5),
            $logCdf->invoke([0, 9999, 0.5]),
            1.0e-10,
        );
        self::assertEqualsWithDelta(
            9999.0 * log(0.5),
            $logCcdf->invoke([9998, 9999, 0.5]),
            1.0e-10,
        );
        self::assertSame(5, $inverse->invoke([0.5, 10, 0.5]));
        self::assertSame(3, $inverse->invoke([0.1, 10, 0.5]));
        self::assertSame(0, $inverse->invoke([0, 10, 0.5]));
        self::assertSame(10, $inverse->invoke([1, 10, 0.5]));
        self::assertSame(0, $inverse->invoke([1, 10, 0]));
        self::assertSame(10, $inverse->invoke([0.01, 10, 1]));
        self::assertSame(1.0, $mass->invoke([0, 10, 0]));
        self::assertSame(1.0, $mass->invoke([10, 10, 1]));
        self::assertSame(0.0, $mass->invoke([4, 10, 0]));
        self::assertSame($cdf->invoke([3, 10, 0.5]), $cdf->invoke([3.7, 10, 0.5]));
        self::assertSame(0.0, $cdf->invoke([-1, 10, 0.5]));
        self::assertSame(1.0, $cdf->invoke([10, 10, 0.5]));
        self::assertSame(0.0, $ccdf->invoke([10, 10, 0.5]));
        self::assertSame(5.0, $meanFunction->invoke([10, 0.5]));
        self::assertSame(2.5, $varianceFunction->invoke([10, 0.5]));
        self::assertEqualsWithDelta(sqrt(2.5), $standardDeviationFunction->invoke([10, 0.5]), 1.0e-14);
        self::assertSame(5, $medianFunction->invoke([10, 0.5]));
        self::assertSame(5, $modeFunction->invoke([10, 0.5]));
        self::assertSame(2, $modeFunction->invoke([4, 0.4]));
        self::assertSame(1.0, $rawMoment->invoke([0, 10, 0.5]));
        self::assertEqualsWithDelta(5.0, $rawMoment->invoke([1, 10, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(27.5, $rawMoment->invoke([2, 10, 0.5]), 1.0e-13);
        self::assertEqualsWithDelta(162.5, $rawMoment->invoke([3, 10, 0.5]), 1.0e-12);
        self::assertEqualsWithDelta(1000.0, $rawMoment->invoke([3, 10, 1.0]), 1.0e-12);
        self::assertSame(0.0, $rawMoment->invoke([3, 10, 0.0]));
        self::assertSame(0, $medianFunction->invoke([0, 0.5]));
        self::assertSame(0, $modeFunction->invoke([0, 0.5]));
        self::assertSame(5.0, $functions->find('binommean')?->invoke([10, 0.5]));
        self::assertSame(
            $standardDeviationFunction->invoke([10, 0.5]),
            $functions->find('binomstddev')?->invoke([10, 0.5]),
        );
        self::assertSame(
            $rawMoment->invoke([3, 10, 0.5]),
            $functions->find('binomrawmoment')?->invoke([3, 10, 0.5]),
        );
        self::assertSame(0.0, $meanFunction->invoke([10, 0.0]));
        self::assertSame(0.0, $varianceFunction->invoke([10, 1.0]));
        self::assertEqualsWithDelta(1.5 ** 10, $mgf->invoke([log(2.0), 10, 0.5]), 1.0e-12);
        self::assertEqualsWithDelta(10.0 * log(1.5), $logMgf->invoke([log(2.0), 10, 0.5]), 1.0e-12);
        self::assertSame(1.0, $mgf->invoke([0.0, 10, 0.5]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 10, 0.5]));
        self::assertSame(1.0, $mgf->invoke([1000.0, 0, 0.5]));
        self::assertSame(0.0, $logMgf->invoke([-1000.0, 10, 0.0]));
        self::assertEqualsWithDelta(10.0, $logMgf->invoke([1.0, 10, 1.0]), 1.0e-14);
        $weightedMgf = 0.0;
        $tilt = -0.4;
        for ($successes = 0; $successes <= 10; ++$successes) {
            $weightedMgf += exp($tilt * $successes) * $mass->invoke([$successes, 10, 0.3]);
        }
        self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, 10, 0.3]), 1.0e-14);
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 10, 1.0]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        $binomialVariance = 10.0 * 0.3 * 0.7;
        self::assertEqualsWithDelta(0.4 / sqrt($binomialVariance), $skewness->invoke([10, 0.3]), 1.0e-14);
        self::assertEqualsWithDelta(
            (1.0 - 6.0 * 0.3 * 0.7) / $binomialVariance,
            $excessKurtosis->invoke([10, 0.3]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $excessKurtosis->invoke([10, 0.3]) + 3.0,
            $kurtosis->invoke([10, 0.3]),
            1.0e-14,
        );
        $thirdCentralMoment = 0.0;
        $fourthCentralMoment = 0.0;
        for ($successes = 0; $successes <= 10; ++$successes) {
            $difference = $successes - 3.0;
            $massAtOutcome = $mass->invoke([$successes, 10, 0.3]);
            $thirdCentralMoment += $difference ** 3 * $massAtOutcome;
            $fourthCentralMoment += $difference ** 4 * $massAtOutcome;
        }
        self::assertEqualsWithDelta(
            $thirdCentralMoment / ($binomialVariance * sqrt($binomialVariance)),
            $skewness->invoke([10, 0.3]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $fourthCentralMoment / ($binomialVariance * $binomialVariance) - 3.0,
            $excessKurtosis->invoke([10, 0.3]),
            1.0e-14,
        );
        foreach (['binomialskewness', 'binomialexcesskurtosis'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([10, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }

        foreach ([['binomiallogcdf', -1, 10, 0.5], ['binomiallogccdf', 10, 10, 0.5]] as $arguments) {
            $function = $functions->find($arguments[0]);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke(array_slice($arguments, 1));
            } catch (\DomainException) {
                continue;
            }
            self::fail($arguments[0] . ' should report negative-infinite tail endpoints.');
        }

        foreach ([0, 1, 10, 100] as $trials) {
            foreach ([0.01, 0.3, 0.5, 0.99] as $probability) {
                $massTotal = 0.0;
                $firstMoment = 0.0;
                $secondMoment = 0.0;
                for ($successes = 0; $successes <= $trials; ++$successes) {
                    $massProbability = $mass->invoke([$successes, $trials, $probability]);
                    $massTotal += $massProbability;
                    $firstMoment += $successes * $massProbability;
                    $secondMoment += $successes * $successes * $massProbability;
                }
                self::assertEqualsWithDelta(1.0, $massTotal, 2.0e-12);
                self::assertEqualsWithDelta(
                    $meanFunction->invoke([$trials, $probability]),
                    $firstMoment,
                    2.0e-10,
                );
                self::assertEqualsWithDelta(
                    $varianceFunction->invoke([$trials, $probability]),
                    $secondMoment - $firstMoment * $firstMoment,
                    2.0e-9,
                );

                foreach (range(0, $trials) as $threshold) {
                    self::assertEqualsWithDelta(
                        1.0,
                        $cdf->invoke([$threshold, $trials, $probability])
                        + $ccdf->invoke([$threshold, $trials, $probability]),
                        2.0e-12,
                    );
                }
            }
        }

        foreach ([[0.1, 10, 0.3], [0.5, 100, 0.2], [0.99, 50, 0.7]] as [$target, $trials, $probability]) {
            $quantile = $inverse->invoke([$target, $trials, $probability]);
            self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $trials, $probability]));
            if ($quantile > 0) {
                self::assertLessThan(
                    $target,
                    $cdf->invoke([$quantile - 1, $trials, $probability]),
                );
            }
        }
    }

    public function testBernoulliLogMassHandlesDegenerateEvents(): void
    {
        $logPmf = FunctionRegistry::defaults()->find('bernoullilogpmf');
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertSame(log(0.25), $logPmf->invoke([1, 0.25]));
        self::assertSame(log(0.75), $logPmf->invoke([0, 0.25]));
        self::assertSame(0.0, $logPmf->invoke([0, 0]));
        self::assertSame(-INF, $logPmf->invoke([1, 0]));
        self::assertSame(0.0, $logPmf->invoke([1, 1]));
        self::assertSame(-INF, $logPmf->invoke([0, 1]));
        self::assertSame(-INF, $logPmf->invoke([0.5, 0.25]));
    }

    public function testMultinomialDistributionMassAndValidation(): void
    {
        $function = FunctionRegistry::defaults()->find('multinomialpmf');
        $logFunction = FunctionRegistry::defaults()->find('multinomiallogpmf');
        self::assertInstanceOf(FunctionDefinition::class, $function);
        self::assertInstanceOf(FunctionDefinition::class, $logFunction);
        self::assertEqualsWithDelta(0.072, $function->invoke([2, 0.2, 1, 0.3, 1, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.072), $logFunction->invoke([2, 0.2, 1, 0.3, 1, 0.5]), 1.0e-14);
        self::assertSame(-INF, $logFunction->invoke([1, 0.0, 0, 0.5, 0, 0.5]));
        self::assertEqualsWithDelta(0.0, $logFunction->invoke([0, 0.0, 0, 0.5, 0, 0.5]), 2.0e-14);
        self::assertSame(-INF, $logFunction->invoke([-1, 0.2, 2, 0.3, 1, 0.5]));
        $binomial = FunctionRegistry::defaults()->find('binomialpmf');
        self::assertInstanceOf(FunctionDefinition::class, $binomial);
        self::assertEqualsWithDelta(
            $binomial->invoke([3, 4, 0.25]),
            $function->invoke([3, 0.25, 1, 0.75]),
            1.0e-14,
        );
        self::assertSame(0.0, $function->invoke([-1, 0.2, 2, 0.3, 1, 0.5]));
        self::assertSame(0.0, $function->invoke([0.5, 0.2, 2, 0.3, 1, 0.5]));
        self::assertSame(0.0, $function->invoke([1, 0.0, 0, 0.5, 0, 0.5]));
        self::assertEqualsWithDelta(1.0, $function->invoke([0, 0.0, 0, 0.5, 0, 0.5]), 1.0e-14);

        $normalization = 0.0;
        for ($first = 0; $first <= 4; ++$first) {
            for ($second = 0; $second <= 4 - $first; ++$second) {
                $third = 4 - $first - $second;
                $normalization += $function->invoke([$first, 0.2, $second, 0.3, $third, 0.5]);
            }
        }
        self::assertEqualsWithDelta(1.0, $normalization, 2.0e-12);

        foreach ([[1, 0.2, 1], [1, 0.2, 1, 0.2], [1, -0.1, 1, 1.1], [1, 0.4, 1, 0.4]] as $arguments) {
            try {
                $function->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Multinomial PMF should reject malformed pairs and invalid probabilities.');
        }
    }

    public function testMultinomialMarginalMomentsAndCategoryCovariance(): void
    {
        $registry = FunctionRegistry::defaults();
        self::assertEqualsWithDelta(2.0, $registry->find('multinomialmean')?->invoke([10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(1.6, $registry->find('multinomialvariance')?->invoke([10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(1.6, $registry->find('multinomialvar')?->invoke([10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(1.6), $registry->find('multinomialstddev')?->invoke([10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $registry->find('multinomialmoment')?->invoke([1, 10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(5.6, $registry->find('multinomialrawmoment')?->invoke([2, 10, 0.2]), 1.0e-14);
        self::assertEqualsWithDelta(-0.6, $registry->find('multinomialcovariance')?->invoke([10, 0.2, 0.3]), 1.0e-14);
        self::assertSame(0.0, $registry->find('multinomialcovariance')?->invoke([0, 0.2, 0.3]));

        foreach ([[10, 0.7, 0.4], [10, -0.1, 0.2], [10, 0.2, 1.1], [1.5, 0.2, 0.3]] as $arguments) {
            try {
                $registry->find('multinomialcovariance')?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('multinomialcovariance() should reject invalid trials or category probabilities.');
        }
    }

    public function testBernoulliDistributionHandlesSupportTailsAndDegenerateCases(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('bernoullipmf');
        $cdf = $functions->find('bernoullicdf');
        $ccdf = $functions->find('bernoulliccdf');
        $inverse = $functions->find('bernoulliinv');
        $mean = $functions->find('bernoullimean');
        $variance = $functions->find('bernoullivariance');
        $standardDeviation = $functions->find('bernoullistddev');
        $entropy = $functions->find('bernoullientropy');
        $skewness = $functions->find('bernoulliskewness');
        $excessKurtosis = $functions->find('bernoulliexcesskurtosis');
        $kurtosis = $functions->find('bernoullikurtosis');
        $moment = $functions->find('bernoullimoment');
        $median = $functions->find('bernoullimedian');
        $mode = $functions->find('bernoullimode');
        $mgf = $functions->find('bernoullimgf');
        $logMgf = $functions->find('bernoullilogmgf');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);

        self::assertSame(0.7, $pmf->invoke([0, 0.3]));
        self::assertSame(0.3, $pmf->invoke([1, 0.3]));
        self::assertSame(0.0, $pmf->invoke([0.5, 0.3]));
        self::assertSame(0.0, $cdf->invoke([-0.1, 0.3]));
        self::assertSame(0.7, $cdf->invoke([0, 0.3]));
        self::assertSame(0.7, $cdf->invoke([0.9, 0.3]));
        self::assertSame(1.0, $cdf->invoke([1, 0.3]));
        self::assertSame(1.0, $ccdf->invoke([-0.1, 0.3]));
        self::assertSame(0.3, $ccdf->invoke([0, 0.3]));
        self::assertSame(0.0, $ccdf->invoke([1, 0.3]));
        self::assertSame(0, $inverse->invoke([0.7, 0.3]));
        self::assertSame(1, $inverse->invoke([0.700001, 0.3]));

        self::assertSame(1.0, $pmf->invoke([0, 0]));
        self::assertSame(0.0, $pmf->invoke([1, 0]));
        self::assertSame(0, $inverse->invoke([0.5, 0]));
        self::assertSame(0.0, $pmf->invoke([0, 1]));
        self::assertSame(1.0, $pmf->invoke([1, 1]));
        self::assertSame(1, $inverse->invoke([0.5, 1]));
        self::assertSame(0.3, $mean->invoke([0.3]));
        self::assertEqualsWithDelta(0.21, $variance->invoke([0.3]), 1.0e-15);
        self::assertEqualsWithDelta(sqrt(0.21), $standardDeviation->invoke([0.3]), 1.0e-15);
        self::assertEqualsWithDelta(
            -0.3 * log(0.3) - 0.7 * log(0.7),
            $entropy->invoke([0.3]),
            1.0e-15,
        );
        self::assertEqualsWithDelta(0.4 / sqrt(0.21), $skewness->invoke([0.3]), 1.0e-14);
        self::assertEqualsWithDelta((1.0 - 6.0 * 0.21) / 0.21, $excessKurtosis->invoke([0.3]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 + $excessKurtosis->invoke([0.3]), $kurtosis->invoke([0.3]), 1.0e-14);
        self::assertSame(0.0, $entropy->invoke([0.0]));
        self::assertSame(0.0, $entropy->invoke([1.0]));
        self::assertSame(-2.0, $excessKurtosis->invoke([0.5]));
        self::assertSame(1.0, $kurtosis->invoke([0.5]));
        self::assertSame(1.0, $moment->invoke([0, 0.3]));
        self::assertSame(0.3, $moment->invoke([1, 0.3]));
        self::assertSame(0.3, $moment->invoke([64, 0.3]));
        self::assertSame(0, $median->invoke([0.5]));
        self::assertSame(1, $median->invoke([0.500001]));
        self::assertSame(0, $mode->invoke([0.5]));
        self::assertSame(1, $mode->invoke([0.500001]));
        self::assertSame(0.0, $mean->invoke([0.0]));
        self::assertSame(0.0, $variance->invoke([1.0]));
        foreach ([$skewness, $excessKurtosis, $kurtosis] as $undefinedShape) {
            foreach ([0.0, 1.0] as $degenerateProbability) {
                try {
                    $undefinedShape->invoke([$degenerateProbability]);
                } catch (\DomainException) {
                    continue;
                }
                self::fail('Bernoulli shape statistics should reject deterministic endpoint distributions.');
            }
        }
        self::assertSame(0.0, $moment->invoke([1, 0.0]));
        self::assertSame(1, $median->invoke([1.0]));
        self::assertSame(1, $mode->invoke([1.0]));
        self::assertEqualsWithDelta(0.7 + 0.3 * exp(0.8), $mgf->invoke([0.8, 0.3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.7 + 0.3 * exp(0.8)), $logMgf->invoke([0.8, 0.3]), 1.0e-14);
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 0.0]));
        self::assertSame(PHP_FLOAT_MAX, $logMgf->invoke([PHP_FLOAT_MAX, 1.0]));
        self::assertSame(
            $mgf->invoke([0.8, 0.3]),
            $functions->find('binomialmgf')?->invoke([0.8, 1, 0.3]),
        );
        self::assertSame(
            $logMgf->invoke([0.8, 0.3]),
            $functions->find('binomiallogmgf')?->invoke([0.8, 1, 0.3]),
        );
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 1.0]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        foreach ([-1, 65, 1.5] as $order) {
            try {
                $moment->invoke([$order, 0.3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('bernoullimoment() should reject invalid moment orders.');
        }
        foreach ([[0, -0.1], [0, 1.1], [INF, 0.5]] as $arguments) {
            try {
                $pmf->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Bernoulli distribution should reject invalid probabilities and non-finite outcomes.');
        }
        foreach ([-0.1, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('bernoulliinv() should reject probabilities outside [0, 1].');
        }
    }

    public function testBernoulliInverseStrictUpperTail(): void
    {
        $registry = FunctionRegistry::defaults();
        $inverse = $registry->find('bernoulliccinv');
        $survival = $registry->find('bernoulliccdf');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $survival);

        foreach ([0.0, 0.2, 0.5, 0.9] as $successProbability) {
            foreach ([0.0, 0.1, 0.5, 0.9] as $target) {
                $quantile = $inverse->invoke([$target, $successProbability]);
                self::assertIsInt($quantile);
                self::assertLessThanOrEqual(
                    $target,
                    $survival->invoke([$quantile, $successProbability]),
                );
                if ($quantile > 0) {
                    self::assertGreaterThan(
                        $target,
                        $survival->invoke([$quantile - 1, $successProbability]),
                    );
                }
            }
        }

        self::assertSame(0, $inverse->invoke([0.0, 0.0]));
        self::assertSame(1, $inverse->invoke([0.0, 1.0]));
        try {
            $inverse->invoke([1.0, 0.5]);
        } catch (\DomainException) {
            return;
        }
        self::fail('bernoulliccinv() should reject probability 1 because no smallest integer quantile exists.');
    }

    public function testDiscreteLaplaceDistributionNormalizesAndInvertsBothTails(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('discretelaplacepmf');
        $logPmf = $functions->find('discretelaplacelogpmf');
        $cdf = $functions->find('discretelaplacecdf');
        $ccdf = $functions->find('discretelaplaceccdf');
        $inverse = $functions->find('discretelaplaceinv');
        $mean = $functions->find('discretelaplacemean');
        $variance = $functions->find('discretelaplacevariance');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        $alias = $functions->find('doublegeometricinv');
        self::assertInstanceOf(FunctionDefinition::class, $alias);
        self::assertSame(2, $alias->minArguments);

        self::assertEqualsWithDelta(1.0 / 3.0, $pmf->invoke([0, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logPmf->invoke([0, 0.5]), 1.0e-15);
        self::assertSame(-INF, $logPmf->invoke([1.5, 0.5]));
        self::assertSame(0.0, $pmf->invoke([1_000_000, 0.5]));
        self::assertLessThan(0.0, $logPmf->invoke([1_000_000, 0.5]));
        self::assertEqualsWithDelta(1.0 / 6.0, $pmf->invoke([-1, 0.5]), 1.0e-15);
        self::assertSame(0.0, $pmf->invoke([1.5, 0.5]));
        self::assertEqualsWithDelta(1.0 / 6.0, $cdf->invoke([-2, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 3.0, $cdf->invoke([-1, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(2.0 / 3.0, $cdf->invoke([0, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 3.0, $ccdf->invoke([0, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(2.0 / 3.0, $ccdf->invoke([-1, 0.5]), 1.0e-15);
        self::assertSame(-1, $inverse->invoke([1.0 / 3.0, 0.5]));
        self::assertSame(0, $inverse->invoke([2.0 / 3.0, 0.5]));
        self::assertSame(
            $inverse->invoke([0.9, 0.5]),
            $alias->invoke([0.9, 0.5]),
        );

        foreach ([0.01, 0.5, 0.95, 0.999999] as $decay) {
            $mass = 0.0;
            for ($outcome = -1000; $outcome <= 1000; ++$outcome) {
                $mass += $pmf->invoke([$outcome, $decay]);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$outcome, $decay]) + $ccdf->invoke([$outcome, $decay]),
                    2.0e-15,
                );
                self::assertEqualsWithDelta(
                    $pmf->invoke([$outcome, $decay]),
                    $pmf->invoke([-$outcome, $decay]),
                    1.0e-15,
                );
            }
            $expectedMass = 1.0 - (2.0 * $decay ** 1001) / (1.0 + $decay);
            self::assertEqualsWithDelta($expectedMass, $mass, 2.0e-12);
            foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $target) {
                $quantile = $inverse->invoke([$target, $decay]);
                self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $decay]));
                self::assertLessThan($target, $cdf->invoke([$quantile - 1, $decay]));
            }
        }
        self::assertSame(0, $inverse->invoke([0.5, 0]));
        self::assertSame(1.0, $pmf->invoke([0, 0]));
        self::assertSame(0.0, $mean->invoke([0.5]));
        self::assertEqualsWithDelta(4.0, $variance->invoke([0.5]), 1.0e-15);
        self::assertSame(0.0, $variance->invoke([0.0]));
        self::assertEqualsWithDelta(2.0, $functions->find('discretelaplacestddev')?->invoke([0.5]), 1.0e-15);
        self::assertSame(0, $functions->find('discretelaplacemedian')?->invoke([0.5]));
        self::assertSame(0, $functions->find('discretelaplacemode')?->invoke([0.5]));
        self::assertEqualsWithDelta(0.0, $functions->find('discretelaplacemoment')?->invoke([1, 0.5]), 0.0);
        self::assertEqualsWithDelta(4.0, $functions->find('discretelaplacemoment')?->invoke([2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(100.0, $functions->find('discretelaplacemoment')?->invoke([4, 0.5]), 1.0e-12);
        self::assertEqualsWithDelta(3.25, $functions->find('discretelaplaceexcesskurtosis')?->invoke([0.5]), 1.0e-14);
        self::assertEqualsWithDelta(6.25, $functions->find('discretelaplacekurtosis')?->invoke([0.5]), 1.0e-14);
        self::assertSame(0.0, $functions->find('discretelaplaceskewness')?->invoke([0.5]));
        self::assertEqualsWithDelta(
            log(3.0) - 2.0 * 0.5 * log(0.5) / (1.0 - 0.5 ** 2),
            $functions->find('discretelaplaceentropy')?->invoke([0.5]),
            1.0e-14,
        );
        $expectedMgf = (1.0 - 0.5) ** 2
            / ((1.0 - 0.5 * exp(0.2)) * (1.0 - 0.5 * exp(-0.2)));
        self::assertEqualsWithDelta($expectedMgf, $functions->find('discretelaplacemgf')?->invoke([0.2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(
            log($expectedMgf),
            $functions->find('discretelaplacelogmgf')?->invoke([0.2, 0.5]),
            1.0e-14,
        );
        self::assertSame(1.0, $functions->find('doublegeometricmgf')?->invoke([0.0, 0.5]));
        self::assertSame($mean->invoke([0.4]), $functions->find('doublegeometricmean')?->invoke([0.4]));
        self::assertSame(
            $variance->invoke([0.4]),
            $functions->find('doublegeometricvariance')?->invoke([0.4]),
        );
        self::assertEqualsWithDelta(
            (2.0 * 0.999999) / ((1.0 - 0.999999) * (1.0 - 0.999999)),
            $variance->invoke([0.999999]),
            1.0e-3,
        );

        foreach ([0.01, 0.5, 0.95] as $decay) {
            $secondMoment = 0.0;
            $limit = (int) ceil(50.0 / (1.0 - $decay));
            for ($outcome = -$limit; $outcome <= $limit; ++$outcome) {
                $secondMoment += $outcome * $outcome * $pmf->invoke([$outcome, $decay]);
            }
            self::assertEqualsWithDelta(0.0, $mean->invoke([$decay]), 0.0);
            self::assertEqualsWithDelta($variance->invoke([$decay]), $secondMoment, 1.0e-8);
        }
        foreach ([0.0, 1.0] as $target) {
            try {
                $inverse->invoke([$target, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('discretelaplaceinv() should reject endpoint probabilities without finite quantiles.');
        }
        foreach ([-0.1, 1.0, INF] as $decay) {
            try {
                $pmf->invoke([0, $decay]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Discrete Laplace should reject decay parameters outside [0, 1).');
        }
        foreach ([log(2.0), -log(2.0)] as $tilt) {
            try {
                $functions->find('discretelaplacelogmgf')?->invoke([$tilt, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('The discrete Laplace MGF should reject points at or beyond its convergence boundary.');
        }
    }

    public function testBinomialDistributionFunctionsRejectInvalidParameters(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (['binomialpmf', 'binomialcdf', 'binomialccdf', 'binomialinv'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            $value = $name === 'binomialinv' ? 0.5 : 0;
            $invalidParameters = [
                [$value, -1, 0.5],
                [$value, 2.5, 0.5],
                [$value, 10_000, 0.5],
                [$value, 10, -0.1],
                [$value, 10, 1.1],
            ];
            foreach ($invalidParameters as $arguments) {
                try {
                    $function->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject an invalid trial count or success probability.');
            }
        }

        $inverse = $functions->find('binomialinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([-0.1, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 10, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('binomialinv should reject targets outside [0, 1].');
        }
    }

    public function testBetaBinomialDistributionFunctionsNormalizeAndInvert(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('betabinomialpmf');
        $logPmf = $functions->find('betabinomiallogpmf');
        $cdf = $functions->find('betabinomialcdf');
        $ccdf = $functions->find('betabinomialccdf');
        $logCdf = $functions->find('betabinomiallogcdf');
        $logCcdf = $functions->find('betabinomiallogccdf');
        $inverse = $functions->find('betabinomialinv');
        $mean = $functions->find('betabinomialmean');
        $variance = $functions->find('betabinomialvariance');
        $standardDeviation = $functions->find('betabinomialstddev');
        $entropy = $functions->find('betabinomialentropy');
        $median = $functions->find('betabinomialmedian');
        $mode = $functions->find('betabinomialmode');
        $rawMoment = $functions->find('betabinomialmoment');
        $mgf = $functions->find('betabinomialmgf');
        $logMgf = $functions->find('betabinomiallogmgf');
        $skewness = $functions->find('betabinomialskewness');
        $excessKurtosis = $functions->find('betabinomialexcesskurtosis');
        $kurtosis = $functions->find('betabinomialkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertEqualsWithDelta(1.0 / 3.0, $pmf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logPmf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertSame(-INF, $logPmf->invoke([-1, 2, 1, 1]));
        self::assertEqualsWithDelta(1.0 / 3.0, $pmf->invoke([1, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0, $cdf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 / 3.0, $ccdf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logCdf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0 / 3.0), $logCcdf->invoke([0, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $mean->invoke([2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 / 3.0, $variance->invoke([2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0 / 3.0), $standardDeviation->invoke([2, 1, 1]), 1.0e-14);
        self::assertSame(1, $median->invoke([2, 1, 1]));
        self::assertSame(2, $mode->invoke([2, 1, 1])); // Uniform support: largest value is the selected mode.
        self::assertEqualsWithDelta(log(3.0), $entropy->invoke([2, 1, 1]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 2, 1, 1]));
        self::assertEqualsWithDelta(1.0, $rawMoment->invoke([1, 2, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 3.0, $rawMoment->invoke([2, 2, 1, 1]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([4, 4, 2, 3]));
        self::assertSame(0.0, $logCcdf->invoke([-1, 4, 2, 3]));
        self::assertSame(1, $inverse->invoke([0.5, 2, 1, 1]));

        $expected = [3.0 / 14.0, 2.0 / 7.0, 9.0 / 35.0, 6.0 / 35.0, 1.0 / 14.0];
        $total = 0.0;
        foreach ($expected as $successes => $mass) {
            $actual = $pmf->invoke([$successes, 4, 2, 3]);
            self::assertEqualsWithDelta($mass, $actual, 1.0e-13);
            $total += $actual;
        }
        self::assertEqualsWithDelta(1.0, $total, 1.0e-13);
        self::assertEqualsWithDelta(1.6, $mean->invoke([4, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.44, $variance->invoke([4, 2, 3]), 1.0e-14);
        self::assertSame(1, $median->invoke([4, 2, 3]));
        self::assertSame(1, $mode->invoke([4, 2, 3]));
        $firstMoment = 0.0;
        $secondMoment = 0.0;
        foreach ($expected as $successes => $mass) {
            $firstMoment += $successes * $mass;
            $secondMoment += $successes * $successes * $mass;
        }
        self::assertEqualsWithDelta($firstMoment, $mean->invoke([4, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta($secondMoment - $firstMoment ** 2, $variance->invoke([4, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta($secondMoment, $rawMoment->invoke([2, 4, 2, 3]), 1.0e-13);
        $thirdCentralMoment = 0.0;
        $fourthCentralMoment = 0.0;
        foreach ($expected as $successes => $mass) {
            $difference = $successes - 1.6;
            $thirdCentralMoment += $difference ** 3 * $mass;
            $fourthCentralMoment += $difference ** 4 * $mass;
        }
        self::assertEqualsWithDelta(
            $thirdCentralMoment / (1.44 * sqrt(1.44)),
            $skewness->invoke([4, 2, 3]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(
            $fourthCentralMoment / (1.44 * 1.44) - 3.0,
            $excessKurtosis->invoke([4, 2, 3]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(
            $excessKurtosis->invoke([4, 2, 3]) + 3.0,
            $kurtosis->invoke([4, 2, 3]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(0.0, $skewness->invoke([4, 2, 2]), 1.0e-13);
        foreach (['betabinomialskewness', 'betabinomialexcesskurtosis'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([0, 2, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
        self::assertSame(0.0, $mean->invoke([0, 2, 3]));
        self::assertSame(0.0, $variance->invoke([0, 2, 3]));
        self::assertSame(0.0, $entropy->invoke([0, 2, 3]));
        self::assertSame(0, $median->invoke([0, 2, 3]));
        self::assertSame(0, $mode->invoke([0, 2, 3]));
        self::assertSame(0.0, $rawMoment->invoke([2, 0, 2, 3]));
        self::assertSame(1.0, $mgf->invoke([0.0, 4, 2, 3]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 4, 2, 3]));
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 0, 2, 3]));
        foreach ([-0.3, 0.2] as $tilt) {
            $weightedMgf = 0.0;
            foreach ($expected as $successes => $mass) {
                $weightedMgf += exp($tilt * $successes) * $mass;
            }
            self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, 4, 2, 3]), 1.0e-13);
            self::assertEqualsWithDelta(log($weightedMgf), $logMgf->invoke([$tilt, 4, 2, 3]), 1.0e-13);
        }
        self::assertGreaterThan(700.0, $logMgf->invoke([1000.0, 4, 2, 3]));
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 4, 2, 3]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        self::assertEqualsWithDelta(
            log($cdf->invoke([2, 4, 2, 3])),
            $logCdf->invoke([2, 4, 2, 3]),
            1.0e-14,
        );
        self::assertSame($cdf->invoke([2, 4, 2, 3]), $cdf->invoke([2.75, 4, 2, 3]));
        foreach ([0.1, 0.5, 0.9] as $target) {
            $quantile = $inverse->invoke([$target, 4, 2, 3]);
            self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, 4, 2, 3]));
            if ($quantile > 0) {
                self::assertLessThan($target, $cdf->invoke([$quantile - 1, 4, 2, 3]));
            }
        }

        $smallShapes = 0.0;
        for ($successes = 0; $successes <= 20; ++$successes) {
            $smallShapes += $pmf->invoke([$successes, 20, 0.2, 0.2]);
        }
        self::assertEqualsWithDelta(1.0, $smallShapes, 2.0e-12);
        self::assertEqualsWithDelta(
            $pmf->invoke([0, 20, 0.2, 0.2]),
            $pmf->invoke([20, 20, 0.2, 0.2]),
            1.0e-12,
        );

        self::assertSame(0.0, $cdf->invoke([0, 9999, 10000, 1]));
        self::assertLessThan(-1000.0, $logCdf->invoke([0, 9999, 10000, 1]));

        foreach ([['betabinomiallogcdf', -1, 4, 2, 3], ['betabinomiallogccdf', 4, 4, 2, 3]] as $arguments) {
            $function = $functions->find($arguments[0]);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke(array_slice($arguments, 1));
            } catch (\DomainException) {
                continue;
            }
            self::fail($arguments[0] . ' should report its negative-infinite endpoint.');
        }

        foreach (
            [
                ['betabinomialpmf', [0, 10, 0, 2]],
                ['betabinomialcdf', [0, 10, 2, -1]],
                ['betabinomialccdf', [0, 10_000, 2, 2]],
                ['betabinomialinv', [1.1, 10, 2, 2]],
            ] as [$name, $arguments]
        ) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject invalid parameters.');
        }
    }

    public function testDiscreteUniformDistributionCoversInclusiveIntegerSupport(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('discreteuniformpmf');
        $logPmf = $functions->find('discreteuniformlogpmf');
        $cdf = $functions->find('discreteuniformcdf');
        $ccdf = $functions->find('discreteuniformccdf');
        $logCdf = $functions->find('discreteuniformlogcdf');
        $logCcdf = $functions->find('discreteuniformlogccdf');
        $inverse = $functions->find('discreteuniforminv');
        $mean = $functions->find('discreteuniformmean');
        $variance = $functions->find('discreteuniformvariance');
        $standardDeviation = $functions->find('discreteuniformstddev');
        $entropy = $functions->find('discreteuniformentropy');
        $skewness = $functions->find('discreteuniformskewness');
        $excessKurtosis = $functions->find('discreteuniformexcesskurtosis');
        $kurtosis = $functions->find('discreteuniformkurtosis');
        $rawMoment = $functions->find('discreteuniformmoment');
        $median = $functions->find('discreteuniformmedian');
        $mode = $functions->find('discreteuniformmode');
        $mgf = $functions->find('discreteuniformmgf');
        $logMgf = $functions->find('discreteuniformlogmgf');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);

        self::assertSame(0.2, $pmf->invoke([0, -2, 2]));
        self::assertEqualsWithDelta(log(0.2), $logPmf->invoke([0, -2, 2]), 1.0e-15);
        self::assertSame(-INF, $logPmf->invoke([0.5, -2, 2]));
        self::assertSame(0.0, $pmf->invoke([0.5, -2, 2]));
        self::assertEqualsWithDelta(0.8, $cdf->invoke([1.2, -2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(0.2, $ccdf->invoke([1.2, -2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.8), $logCdf->invoke([1.2, -2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.2), $logCcdf->invoke([1.2, -2, 2]), 1.0e-15);
        self::assertSame(0.0, $logCdf->invoke([2, -2, 2]));
        self::assertSame(0.0, $logCcdf->invoke([-3, -2, 2]));
        self::assertSame(0.2, $cdf->invoke([-2, -2, 2]));
        self::assertSame(0.0, $ccdf->invoke([2, -2, 2]));
        self::assertSame(-2, $inverse->invoke([0, -2, 2]));
        self::assertSame(-2, $inverse->invoke([0.2, -2, 2]));
        self::assertSame(-1, $inverse->invoke([0.21, -2, 2]));
        self::assertSame(2, $inverse->invoke([1, -2, 2]));
        self::assertSame(1, $inverse->invoke([0.25, 1, 1]));
        self::assertSame(0.0, $mean->invoke([-2, 2]));
        self::assertEqualsWithDelta(2.0, $variance->invoke([-2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(sqrt(2.0), $standardDeviation->invoke([-2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(log(5.0), $entropy->invoke([-2, 2]), 1.0e-15);
        self::assertSame(0.0, $skewness->invoke([-2, 2]));
        self::assertEqualsWithDelta(-1.3, $excessKurtosis->invoke([-2, 2]), 1.0e-15);
        self::assertEqualsWithDelta(1.7, $kurtosis->invoke([-2, 2]), 1.0e-15);
        self::assertSame(1.0, $rawMoment->invoke([0, -2, 2]));
        self::assertSame(0.0, $rawMoment->invoke([1, -2, 2]));
        self::assertEqualsWithDelta(2.0, $rawMoment->invoke([2, -2, 2]), 1.0e-15);
        self::assertSame(0, $median->invoke([-2, 2]));
        self::assertSame(-2, $mode->invoke([-2, 2]));
        self::assertSame(4.0, $mean->invoke([4, 4]));
        self::assertSame(0.0, $variance->invoke([4, 4]));
        self::assertSame(0.0, $standardDeviation->invoke([4, 4]));
        self::assertSame(0.0, $entropy->invoke([4, 4]));
        self::assertSame(16.0, $rawMoment->invoke([2, 4, 4]));
        self::assertSame(4, $median->invoke([4, 4]));
        self::assertSame(4, $mode->invoke([4, 4]));
        self::assertEqualsWithDelta(
            log(2_000_000_000_001.0),
            $entropy->invoke([-1_000_000_000_000, 1_000_000_000_000]),
            1.0e-14,
        );
        foreach ([$skewness, $excessKurtosis, $kurtosis] as $undefinedShapeFunction) {
            try {
                $undefinedShapeFunction->invoke([4, 4]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Discrete-uniform shape statistics should be undefined on a one-value support.');
        }
        self::assertSame(1.0, $mgf->invoke([0.0, -2, 2]));
        self::assertSame(0.0, $logMgf->invoke([0.0, -2, 2]));
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 0, 0]));
        self::assertEqualsWithDelta(4.0, $logMgf->invoke([1.0, 4, 4]), 1.0e-14);
        foreach ([[-5, 7], [-2, 2], [3, 9]] as [$minimum, $maximum]) {
            foreach ([-0.6, 0.4] as $tilt) {
                $weightedMgf = 0.0;
                for ($outcome = $minimum; $outcome <= $maximum; ++$outcome) {
                    $weightedMgf += exp($tilt * $outcome) * $pmf->invoke([$outcome, $minimum, $maximum]);
                }
                self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, $minimum, $maximum]), 1.0e-13);
                self::assertEqualsWithDelta(
                    log($weightedMgf),
                    $logMgf->invoke([$tilt, $minimum, $maximum]),
                    1.0e-13,
                );
            }
        }
        self::assertEqualsWithDelta(1.6666666666666667e-5, $logMgf->invoke([1.0e-14, -1.0e12, 1.0e12]), 1.0e-9);
        self::assertEqualsWithDelta(
            $logMgf->invoke([1.0e-14, -1.0e12, 1.0e12]),
            $logMgf->invoke([-1.0e-14, -1.0e12, 1.0e12]),
            1.0e-12,
        );
        self::assertSame(0.0, $mgf->invoke([1000.0, -2, -1]));
        self::assertEqualsWithDelta(log(0.5) - 1000.0, $logMgf->invoke([1000.0, -2, -1]), 1.0e-12);
        self::assertGreaterThan(1999.0, $logMgf->invoke([1000.0, 1, 2]));
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 1, 2]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        self::assertSame(0.0, $mean->invoke([-1_000_000_000_000, 1_000_000_000_000]));
        self::assertEqualsWithDelta(
            ((2_000_000_000_001.0 ** 2) - 1.0) / 12.0,
            $variance->invoke([-1_000_000_000_000, 1_000_000_000_000]),
            1.0e8,
        );
        self::assertEqualsWithDelta(
            sqrt(((2_000_000_000_001.0 ** 2) - 1.0) / 12.0),
            $standardDeviation->invoke([-1_000_000_000_000, 1_000_000_000_000]),
            1.0e-4,
        );

        foreach ([[-5, 7], [0, 20], [2, 4]] as [$minimum, $maximum]) {
            $expectedMean = 0.0;
            $expectedSecondMoment = 0.0;
            for ($outcome = $minimum; $outcome <= $maximum; ++$outcome) {
                $probability = $pmf->invoke([$outcome, $minimum, $maximum]);
                $expectedMean += $outcome * $probability;
                $expectedSecondMoment += $outcome * $outcome * $probability;
            }
            self::assertEqualsWithDelta($expectedMean, $mean->invoke([$minimum, $maximum]), 1.0e-12);
            self::assertEqualsWithDelta(
                $expectedSecondMoment - $expectedMean * $expectedMean,
                $variance->invoke([$minimum, $maximum]),
                1.0e-12,
            );
        }

        foreach (
            [
                ['discreteuniformpmf', [1, 2, 1]],
                ['discreteuniformcdf', [INF, 1, 2]],
                ['discreteuniformccdf', [0, 1.5, 2]],
                ['discreteuniforminv', [-0.1, 1, 2]],
                ['discreteuniformmoment', [65, 1, 2]],
            ] as [$name, $arguments]
        ) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid input.');
        }
    }

    public function testZipfDistributionNormalizesAndInvertsItsFiniteSupport(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('zipfpmf');
        $logPmf = $functions->find('zipflogpmf');
        $cdf = $functions->find('zipfcdf');
        $ccdf = $functions->find('zipfccdf');
        $inverse = $functions->find('zipfinv');
        $mean = $functions->find('zipfmean');
        $variance = $functions->find('zipfvariance');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);

        self::assertEqualsWithDelta(6.0 / 11.0, $pmf->invoke([1, 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(6.0 / 11.0), $logPmf->invoke([1, 1, 3]), 1.0e-14);
        self::assertSame(0.0, $pmf->invoke([10000, 100, 10000]));
        self::assertLessThan(0.0, $logPmf->invoke([10000, 100, 10000]));
        self::assertSame(-INF, $logPmf->invoke([0, 1, 3]));
        self::assertEqualsWithDelta(3.0 / 11.0, $pmf->invoke([2, 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(9.0 / 11.0, $cdf->invoke([2, 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 11.0, $ccdf->invoke([1, 1, 3]), 1.0e-14);
        self::assertSame(1, $inverse->invoke([0, 1, 3]));
        self::assertSame(1, $inverse->invoke([0.5, 1, 3]));
        self::assertSame(2, $inverse->invoke([0.6, 1, 3]));
        self::assertSame(3, $inverse->invoke([1, 1, 3]));
        self::assertSame(0.0, $pmf->invoke([0, 1, 3]));
        self::assertEqualsWithDelta(2.0 / 3.0, $cdf->invoke([2, 0, 3]), 1.0e-14);
        self::assertSame(1.0 / 3.0, $pmf->invoke([2, 0, 3]));
        self::assertSame(2, $inverse->invoke([0.34, 0, 3]));

        foreach ([0.0, 1.0, 2.0, 20.0] as $exponent) {
            $expectedMean = 0.0;
            $expectedSecondMoment = 0.0;
            for ($rank = 1; $rank <= 12; ++$rank) {
                $probability = $pmf->invoke([$rank, $exponent, 12]);
                $expectedMean += $rank * $probability;
                $expectedSecondMoment += $rank * $rank * $probability;
            }
            self::assertEqualsWithDelta($expectedMean, $mean->invoke([$exponent, 12]), 1.0e-12);
            self::assertEqualsWithDelta(
                $expectedSecondMoment - $expectedMean * $expectedMean,
                $variance->invoke([$exponent, 12]),
                1.0e-10,
            );
        }

        foreach ([0.0, 0.1, 1.0, 2.0, 20.0] as $exponent) {
            $sum = 0.0;
            for ($rank = 1; $rank <= 12; ++$rank) {
                $sum += $pmf->invoke([$rank, $exponent, 12]);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$rank, $exponent, 12]) + $ccdf->invoke([$rank, $exponent, 12]),
                    2.0e-14,
                );
            }
            self::assertEqualsWithDelta(1.0, $sum, 2.0e-14);
        }

        foreach (
            [
                ['zipfpmf', [INF, 1, 3]],
                ['zipfcdf', [1, -0.1, 3]],
                ['zipfccdf', [1, 1, 0]],
                ['zipfinv', [1.1, 1, 3]],
            ] as [$name, $arguments]
        ) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid Zipf parameters.');
        }
    }

    public function testZipfDistributionExposesFiniteSupportSummariesAndTransforms(): void
    {
        $functions = FunctionRegistry::defaults();
        $standardDeviation = $functions->find('zipfstddev');
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertEqualsWithDelta(sqrt(2.0 / 3.0), $standardDeviation->invoke([0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $functions->find('zipfmedian')?->invoke([0, 3]), 0.0);
        self::assertSame(1, $functions->find('zipfmode')?->invoke([0, 3]));
        self::assertEqualsWithDelta(log(3.0), $functions->find('zipfentropy')?->invoke([0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(0.0, $functions->find('zipfskewness')?->invoke([0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(-1.5, $functions->find('zipfexcesskurtosis')?->invoke([0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.5, $functions->find('zipfkurtosis')?->invoke([0, 3]), 1.0e-14);
        self::assertSame(1.0, $functions->find('zipfmoment')?->invoke([0, 0, 3]));
        self::assertEqualsWithDelta(2.0, $functions->find('zipfmoment')?->invoke([1, 0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(14.0 / 3.0, $functions->find('zipfrawmoment')?->invoke([2, 0, 3]), 1.0e-14);
        self::assertSame(0.0, $functions->find('zipflogmgf')?->invoke([0, 1, 3]));
        self::assertSame(1.0, $functions->find('zipfmgf')?->invoke([0, 1, 3]));

        foreach (
            [
                ['zipfmoment', [-1, 1, 3]],
                ['zipfmoment', [1.5, 1, 3]],
                ['zipfmoment', [65, 1, 3]],
                ['zipfskewness', [1, 1]],
                ['zipfexcesskurtosis', [1, 1]],
            ] as [$name, $arguments]
        ) {
            try {
                $functions->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject invalid orders or degenerate support.');
        }
    }

    public function testBenfordDistributionAcrossRadices(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('benfordpmf');
        $logPmf = $functions->find('benfordlogpmf');
        $cdf = $functions->find('benfordcdf');
        $ccdf = $functions->find('benfordccdf');
        $inverse = $functions->find('benfordinv');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);

        self::assertEqualsWithDelta(log10(2.0), $pmf->invoke([1]), 1.0e-15);
        self::assertEqualsWithDelta(log(log10(2.0)), $logPmf->invoke([1]), 1.0e-15);
        self::assertSame(-INF, $logPmf->invoke([0]));
        self::assertEqualsWithDelta(log10(10.0 / 9.0), $pmf->invoke([9]), 1.0e-15);
        self::assertEqualsWithDelta(log10(4.0), $cdf->invoke([3]), 1.0e-15);
        self::assertEqualsWithDelta(log10(2.5), $ccdf->invoke([3]), 1.0e-15);
        self::assertSame(1, $inverse->invoke([0]));
        self::assertSame(9, $inverse->invoke([1]));
        self::assertSame(1, $inverse->invoke([0.3]));
        self::assertSame(3, $inverse->invoke([log10(4.0)]));
        self::assertSame(0.0, $pmf->invoke([1.5]));
        self::assertSame(0.0, $cdf->invoke([0]));
        self::assertSame(1.0, $ccdf->invoke([0]));

        foreach (range(2, 24) as $base) {
            $total = 0.0;
            for ($digit = 1; $digit < $base; ++$digit) {
                $total += $pmf->invoke([$digit, $base]);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$digit, $base]) + $ccdf->invoke([$digit, $base]),
                    2.0e-15,
                );
                self::assertSame(
                    $digit,
                    $inverse->invoke([$cdf->invoke([$digit, $base]), $base]),
                );
            }
            self::assertEqualsWithDelta(1.0, $total, 2.0e-14);

            foreach ([0.0, 0.01, 0.1, 0.25, 0.5, 0.9, 0.99, 1.0] as $probability) {
                $digit = $inverse->invoke([$probability, $base]);
                self::assertGreaterThanOrEqual($probability, $cdf->invoke([$digit, $base]));
                if ($digit > 1) {
                    self::assertLessThan($probability, $cdf->invoke([$digit - 1, $base]));
                }
            }
        }

        self::assertSame(1.0, $pmf->invoke([1, 2]));
        self::assertSame(1, $inverse->invoke([0.5, 2]));
        foreach ([['benfordpmf', [1, 1]], ['benfordcdf', [0, 2.5]], ['benfordinv', [1.1, 10]]] as [$name, $arguments]) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid radix or probability.');
        }
    }

    public function testBenfordLocationSummariesAcrossSmallAndLargeBases(): void
    {
        $functions = FunctionRegistry::defaults();
        $mean = $functions->find('benfordmean');
        $median = $functions->find('benfordmedian');
        $mode = $functions->find('benfordmode');
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);

        foreach (range(2, 100) as $base) {
            $summedMean = 0.0;
            for ($digit = 1; $digit < $base; ++$digit) {
                $summedMean += $digit * log1p(1.0 / $digit) / log((float) $base);
            }
            self::assertEqualsWithDelta($summedMean, $mean->invoke([$base]), 2.0e-12);
            self::assertSame($functions->find('benfordinv')?->invoke([0.5, $base]), $median->invoke([$base]));
            self::assertSame(1, $mode->invoke([$base]));
        }

        self::assertEqualsWithDelta(1.0, $mean->invoke([2]), 1.0e-15);
        self::assertSame(1, $median->invoke([2]));
        self::assertSame(1, $mode->invoke([2]));
        self::assertGreaterThan(1.0e10, $mean->invoke([1.0e12]));
        self::assertLessThan(1.0e12, $mean->invoke([1.0e12]));

        foreach (['benfordmean', 'benfordmedian', 'benfordmode'] as $name) {
            try {
                $functions->find($name)?->invoke([1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a base below 2.');
        }
    }

    public function testZetaDistributionUsesHurwitzTailAndUnboundedQuantiles(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('zetapmf');
        $logPmf = $functions->find('zetalogpmf');
        $cdf = $functions->find('zetacdf');
        $ccdf = $functions->find('zetaccdf');
        $inverse = $functions->find('zetainv');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);

        self::assertEqualsWithDelta(6.0 / (M_PI * M_PI), $pmf->invoke([1, 2]), 2.0e-14);
        self::assertEqualsWithDelta(log(6.0 / (M_PI * M_PI)), $logPmf->invoke([1, 2]), 2.0e-14);
        self::assertSame(0.0, $pmf->invoke([1.0e300, 2]));
        self::assertLessThan(0.0, $logPmf->invoke([1.0e300, 2]));
        self::assertEqualsWithDelta(1.5 / (M_PI * M_PI), $pmf->invoke([2, 2]), 2.0e-14);
        self::assertEqualsWithDelta(6.0 / (M_PI * M_PI), $cdf->invoke([1, 2]), 2.0e-14);
        self::assertEqualsWithDelta(1.0 - 6.0 / (M_PI * M_PI), $ccdf->invoke([1, 2]), 2.0e-14);
        self::assertSame(0.0, $cdf->invoke([0, 2]));
        self::assertSame(1.0, $ccdf->invoke([0, 2]));
        self::assertSame(1, $inverse->invoke([0, 2]));
        self::assertSame(1, $inverse->invoke([0.5, 2]));
        self::assertSame(2, $inverse->invoke([0.7, 2]));

        foreach ([1.2, 1.5, 2.0, 5.0] as $exponent) {
            foreach ([0.1, 0.5, 0.9, 0.99] as $target) {
                $quantile = $inverse->invoke([$target, $exponent]);
                self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $exponent]));
                if ($quantile > 1) {
                    self::assertLessThan($target, $cdf->invoke([$quantile - 1, $exponent]));
                }
            }
        }

        $total = $ccdf->invoke([100, 2]);
        for ($rank = 1; $rank <= 100; ++$rank) {
            $total += $pmf->invoke([$rank, 2]);
            self::assertEqualsWithDelta(
                1.0,
                $cdf->invoke([$rank, 2]) + $ccdf->invoke([$rank, 2]),
                2.0e-14,
            );
        }
        self::assertEqualsWithDelta(1.0, $total, 2.0e-14);

        foreach (
            [
                ['zetapmf', [1, 1]],
                ['zetacdf', [INF, 2]],
                ['zetaccdf', [1, 1]],
                ['zetainv', [1, 2]],
            ] as [$name, $arguments]
        ) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid zeta-distribution parameters.');
        }
    }

    public function testZetaDistributionSummariesRespectMomentExistenceThresholds(): void
    {
        $functions = FunctionRegistry::defaults();
        $mean = $functions->find('zetamean');
        $variance = $functions->find('zetavariance');
        $entropy = $functions->find('zetaentropy');
        $moment = $functions->find('zetamoment');
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $moment);

        self::assertEqualsWithDelta(1.6376222886598115, $entropy->invoke([2.0]), 2.0e-13);
        self::assertEqualsWithDelta(0.007957485318509372, $entropy->invoke([10.0]), 2.0e-13);
        self::assertGreaterThan(1.0e8, $entropy->invoke([1.00000001]));
        self::assertLessThan(1.0e-25, $entropy->invoke([100.0]));

        $zeta4 = $functions->find('zeta')?->invoke([4.0]);
        $zeta3 = $functions->find('zeta')?->invoke([3.0]);
        self::assertEqualsWithDelta($zeta3 / $zeta4, $mean->invoke([4.0]), 1.0e-14);
        self::assertEqualsWithDelta($zeta3 / $zeta4, $moment->invoke([1, 4.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $moment->invoke([0, 1.5]), 0.0);
        self::assertGreaterThan(0.0, $variance->invoke([4.0]));
        self::assertGreaterThan(0.0, $variance->invoke([100.0]));
        self::assertTrue(is_finite($functions->find('zetaskewness')?->invoke([100.0])));
        self::assertTrue(is_finite($functions->find('zetaexcesskurtosis')?->invoke([100.0])));
        self::assertEqualsWithDelta(
            $functions->find('zetainv')?->invoke([0.5, 2.0]),
            $functions->find('zetamedian')?->invoke([2.0]),
            0.0,
        );
        self::assertSame(1, $functions->find('zetamode')?->invoke([2.0]));
        $zeta6 = $functions->find('zeta')?->invoke([6.0]);
        $zeta5 = $functions->find('zeta')?->invoke([5.0]);
        $zeta2 = $functions->find('zeta')?->invoke([2.0]);
        $m1 = $zeta5 / $zeta6;
        $m2 = $zeta4 / $zeta6;
        $m3 = $zeta3 / $zeta6;
        $m4 = $zeta2 / $zeta6;
        $centralVariance = $m2 - $m1 * $m1;
        $centralThird = $m3 - 3.0 * $m1 * $m2 + 2.0 * $m1 ** 3;
        $centralFourth = $m4 - 4.0 * $m1 * $m3 + 6.0 * $m1 ** 2 * $m2 - 3.0 * $m1 ** 4;
        self::assertEqualsWithDelta(
            $centralThird / ($centralVariance ** 1.5),
            $functions->find('zetaskewness')?->invoke([6.0]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            $centralFourth / ($centralVariance ** 2),
            $functions->find('zetakurtosis')?->invoke([6.0]),
            1.0e-10,
        );

        foreach (
            [
                ['zetamean', [2.0]],
                ['zetavariance', [3.0]],
                ['zetaentropy', [1.0]],
                ['zetaentropy', [0.5]],
                ['zetamoment', [1, 2.0]],
                ['zetamoment', [2, 3.0]],
                ['zetamoment', [-1, 4.0]],
                ['zetamoment', [1.5, 4.0]],
                ['zetaskewness', [4.0]],
                ['zetaexcesskurtosis', [5.0]],
            ] as [$name, $arguments]
        ) {
            try {
                $functions->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject an undefined moment or invalid order.');
        }
    }

    public function testPoissonDistributionFunctionsMatchReferenceValuesAndQuantiles(): void
    {
        $functions = FunctionRegistry::defaults();
        $mass = $functions->find('poissonpmf');
        $logMass = $functions->find('poissonlogpmf');
        $cdf = $functions->find('poissoncdf');
        $ccdf = $functions->find('poissonccdf');
        $logCdf = $functions->find('poissonlogcdf');
        $logCcdf = $functions->find('poissonlogccdf');
        $inverse = $functions->find('poissoninv');
        $meanFunction = $functions->find('poissonmean');
        $varianceFunction = $functions->find('poissonvariance');
        $standardDeviationFunction = $functions->find('poissonstddev');
        $rawMoment = $functions->find('poissonmoment');
        $median = $functions->find('poissonmedian');
        $mode = $functions->find('poissonmode');
        $skewnessFunction = $functions->find('poissonskewness');
        $excessKurtosisFunction = $functions->find('poissonexcesskurtosis');
        $kurtosisFunction = $functions->find('poissonkurtosis');
        $mgf = $functions->find('poissonmgf');
        $logMgf = $functions->find('poissonlogmgf');
        self::assertInstanceOf(FunctionDefinition::class, $mass);
        self::assertInstanceOf(FunctionDefinition::class, $logMass);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $meanFunction);
        self::assertInstanceOf(FunctionDefinition::class, $varianceFunction);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviationFunction);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $skewnessFunction);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosisFunction);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosisFunction);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);

        self::assertEqualsWithDelta(0.22404180765538775, $mass->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.22404180765538775), $logMass->invoke([2, 3]), 1.0e-14);
        self::assertSame(-INF, $logMass->invoke([-1, 3]));
        self::assertSame(0.0, $logMass->invoke([0, 0]));
        self::assertSame(-INF, $logMass->invoke([1, 0]));
        self::assertSame(0.0, $mass->invoke([0, 1000]));
        self::assertSame(-1000.0, $logMass->invoke([0, 1000]));
        self::assertEqualsWithDelta(0.42319008112684364, $cdf->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(0.5768099188731564, $ccdf->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.42319008112684364), $logCdf->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.5768099188731564), $logCcdf->invoke([2, 3]), 1.0e-14);
        self::assertSame(-1000.0, $logCdf->invoke([0, 1000]));
        self::assertEqualsWithDelta(log(1.0e-200), $logCcdf->invoke([0, 1.0e-200]), 1.0e-12);
        self::assertSame(0.0, $logCdf->invoke([0, 0]));
        self::assertSame(0.0, $logCcdf->invoke([-1, 3]));
        self::assertSame($cdf->invoke([2, 3]), $cdf->invoke([2.9, 3]));
        self::assertSame(0.0, $mass->invoke([-1, 3]));
        self::assertSame(0.0, $mass->invoke([1.5, 3]));
        self::assertSame(1.0, $mass->invoke([0, 0]));
        self::assertSame(0.0, $mass->invoke([1, 0]));
        self::assertSame(1.0, $cdf->invoke([0, 0]));
        self::assertSame(0.0, $ccdf->invoke([0, 0]));
        self::assertSame(3.0, $meanFunction->invoke([3]));
        self::assertSame(3.0, $varianceFunction->invoke([3]));
        self::assertEqualsWithDelta(sqrt(3.0), $standardDeviationFunction->invoke([3]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 3]));
        self::assertEqualsWithDelta(3.0, $rawMoment->invoke([1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(12.0, $rawMoment->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(57.0, $rawMoment->invoke([3, 3]), 1.0e-13);
        self::assertSame(0.0, $rawMoment->invoke([1, 0]));
        self::assertSame(0, $median->invoke([0]));
        self::assertSame(0, $median->invoke([0.5]));
        self::assertSame(1, $median->invoke([1]));
        self::assertSame(0, $median->invoke([log(2.0)]));
        self::assertSame(0, $mode->invoke([0]));
        self::assertSame(1, $mode->invoke([1]));
        self::assertSame(3, $mode->invoke([3.2]));
        $medianAlias = $functions->find('poimedian');
        $modeAlias = $functions->find('poimode');
        $rawMomentAlias = $functions->find('poirawmoment');
        self::assertInstanceOf(FunctionDefinition::class, $medianAlias);
        self::assertInstanceOf(FunctionDefinition::class, $modeAlias);
        self::assertInstanceOf(FunctionDefinition::class, $rawMomentAlias);
        self::assertSame($median->invoke([3]), $medianAlias->invoke([3]));
        self::assertSame($mode->invoke([3]), $modeAlias->invoke([3]));
        self::assertSame($rawMoment->invoke([2, 3]), $rawMomentAlias->invoke([2, 3]));
        self::assertEqualsWithDelta(1.0 / sqrt(3.0), $skewnessFunction->invoke([3]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0, $excessKurtosisFunction->invoke([3]), 1.0e-14);
        self::assertEqualsWithDelta(10.0 / 3.0, $kurtosisFunction->invoke([3]), 1.0e-14);
        self::assertSame(0.0, $meanFunction->invoke([0]));
        self::assertSame(0.0, $varianceFunction->invoke([0]));
        self::assertSame(0.0, $standardDeviationFunction->invoke([0]));
        foreach (['poissonskewness', 'poissonexcesskurtosis', 'poissonkurtosis'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
        self::assertEqualsWithDelta(exp(3.0), $mgf->invoke([log(2.0), 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(3.0, $logMgf->invoke([log(2.0), 3.0]), 1.0e-14);
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 0.0]));
        self::assertSame(0.0, $logMgf->invoke([PHP_FLOAT_MAX, 0.0]));
        self::assertEqualsWithDelta(-3.0, $logMgf->invoke([-1000.0, 3.0]), 1.0e-14);
        $weightedMgf = 0.0;
        $tilt = -0.4;
        for ($outcome = 0; $outcome <= 100; ++$outcome) {
            $weightedMgf += exp($tilt * $outcome) * $mass->invoke([$outcome, 3.0]);
        }
        self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, 3.0]), 1.0e-14);
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 1.0]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        foreach ([['poissonlogcdf', -1, 3], ['poissonlogccdf', 0, 0]] as $arguments) {
            $function = $functions->find($arguments[0]);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke(array_slice($arguments, 1));
            } catch (\DomainException) {
                continue;
            }
            self::fail($arguments[0] . ' should report its negative-infinite endpoint.');
        }
        self::assertSame(3, $inverse->invoke([0.5, 3]));
        self::assertSame(6, $inverse->invoke([0.95, 3]));
        self::assertSame(0, $inverse->invoke([0, 3]));
        self::assertSame(0, $inverse->invoke([0.99, 0]));

        foreach ([0.1, 3.0, 20.0, 100.0] as $rate) {
            $massTotal = 0.0;
            $firstMoment = 0.0;
            $secondMoment = 0.0;
            $lastOutcome = (int) ceil($rate + 12.0 * sqrt($rate + 1.0));
            for ($outcome = 0; $outcome <= $lastOutcome; ++$outcome) {
                $massProbability = $mass->invoke([$outcome, $rate]);
                $massTotal += $massProbability;
                $firstMoment += $outcome * $massProbability;
                $secondMoment += $outcome * $outcome * $massProbability;
            }
            self::assertEqualsWithDelta(1.0, $massTotal, 2.0e-12);
            self::assertEqualsWithDelta($rate, $firstMoment, 2.0e-8);
            self::assertEqualsWithDelta($rate, $secondMoment - $firstMoment * $firstMoment, 2.0e-7);

            $thresholds = [
                0,
                (int) floor($rate),
                (int) ceil($rate),
                (int) ceil($rate + 4.0 * sqrt($rate + 1.0)),
            ];
            foreach ($thresholds as $threshold) {
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$threshold, $rate]) + $ccdf->invoke([$threshold, $rate]),
                    2.0e-12,
                );
            }
        }

        $smallUpperTail = $ccdf->invoke([1300, 1000]);
        self::assertGreaterThan(0.0, $smallUpperTail);
        self::assertSame(1.0, $cdf->invoke([1300, 1000]));

        foreach ([[0.01, 3.0], [0.5, 20.0], [0.95, 100.0], [0.999, 0.3]] as [$target, $rate]) {
            $quantile = $inverse->invoke([$target, $rate]);
            self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $rate]));
            if ($quantile > 0) {
                self::assertLessThan($target, $cdf->invoke([$quantile - 1, $rate]));
            }
        }
    }

    public function testPoissonFunctionsRejectInvalidRatesAndQuantiles(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (['poissonpmf', 'poissoncdf', 'poissonccdf', 'poissoninv', 'poissonmoment'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            $outcome = $name === 'poissoninv' ? 0.5 : 0;
            $baseArguments = $name === 'poissonmoment' ? [1, $outcome] : [$outcome, $outcome];
            foreach (
                [
                    array_replace($baseArguments, [count($baseArguments) - 1 => -1]),
                    array_replace($baseArguments, [count($baseArguments) - 1 => 1_000_001]),
                ] as $arguments
            ) {
                try {
                    $function->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject rates outside [0, 1000000].');
            }
        }

        foreach ([['poissonmoment', [-1, 3]], ['poissonmoment', [65, 3]]] as [$name, $arguments]) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject moment orders outside [0, 64].');
        }

        $inverse = $functions->find('poissoninv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([-0.1, 1.0, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('poissoninv should reject targets outside [0, 1).');
        }
    }

    public function testNegativeBinomialFunctionsUseFailuresBeforeRequiredSuccesses(): void
    {
        $functions = FunctionRegistry::defaults();
        $mass = $functions->find('negativebinomialpmf');
        $logMass = $functions->find('negativebinomiallogpmf');
        $cdf = $functions->find('negativebinomialcdf');
        $ccdf = $functions->find('negativebinomialccdf');
        $logCdf = $functions->find('negativebinomiallogcdf');
        $logCcdf = $functions->find('negativebinomiallogccdf');
        $inverse = $functions->find('negativebinomialinv');
        $meanFunction = $functions->find('negativebinomialmean');
        $varianceFunction = $functions->find('negativebinomialvariance');
        $standardDeviationFunction = $functions->find('negativebinomialstddev');
        $skewnessFunction = $functions->find('negativebinomialskewness');
        $excessKurtosisFunction = $functions->find('negativebinomialexcesskurtosis');
        $kurtosisFunction = $functions->find('negativebinomialkurtosis');
        $mgf = $functions->find('negativebinomialmgf');
        $logMgf = $functions->find('negativebinomiallogmgf');
        $rawMoment = $functions->find('negativebinomialmoment');
        $medianFunction = $functions->find('negativebinomialmedian');
        $modeFunction = $functions->find('negativebinomialmode');
        self::assertInstanceOf(FunctionDefinition::class, $mass);
        self::assertInstanceOf(FunctionDefinition::class, $logMass);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $meanFunction);
        self::assertInstanceOf(FunctionDefinition::class, $varianceFunction);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviationFunction);
        self::assertInstanceOf(FunctionDefinition::class, $skewnessFunction);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosisFunction);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosisFunction);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $medianFunction);
        self::assertInstanceOf(FunctionDefinition::class, $modeFunction);

        self::assertEqualsWithDelta(0.25, $mass->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logMass->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertSame(-INF, $logMass->invoke([-1, 2, 0.5]));
        self::assertSame(0.0, $logMass->invoke([0, 4, 1]));
        self::assertSame(-INF, $logMass->invoke([1, 4, 1]));
        self::assertSame(0.0, $mass->invoke([2000, 1, 0.5]));
        self::assertLessThan(0.0, $logMass->invoke([2000, 1, 0.5]));
        self::assertEqualsWithDelta(0.5, $cdf->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $ccdf->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.5), $logCdf->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.5), $logCcdf->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(
            2.0 * log(1.0e-200),
            $logCdf->invoke([0, 2, 1.0e-200]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            100001.0 * log1p(-0.5),
            $logCcdf->invoke([100000, 1, 0.5]),
            1.0e-10,
        );
        self::assertEqualsWithDelta(0.5, $mass->invoke([0, 1, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(0.75, $cdf->invoke([1, 1, 0.5]), 1.0e-14);
        self::assertSame($cdf->invoke([2, 2, 0.5]), $cdf->invoke([2.8, 2, 0.5]));
        self::assertSame(0.0, $mass->invoke([-1, 2, 0.5]));
        self::assertSame(0.0, $mass->invoke([1.5, 2, 0.5]));
        self::assertSame(0.0, $cdf->invoke([-1, 2, 0.5]));
        self::assertSame(1.0, $ccdf->invoke([-1, 2, 0.5]));
        self::assertSame(1.0, $mass->invoke([0, 4, 1]));
        self::assertSame(1.0, $cdf->invoke([0, 4, 1]));
        self::assertSame(0.0, $ccdf->invoke([0, 4, 1]));
        self::assertSame(0.0, $logCdf->invoke([0, 4, 1]));
        self::assertSame(0, $inverse->invoke([0.9, 4, 1]));
        self::assertSame(0, $inverse->invoke([0.5, 1, 0.5]));
        self::assertSame(0, $inverse->invoke([0, 2, 0.5]));
        self::assertSame(2.0, $meanFunction->invoke([2, 0.5]));
        self::assertSame(4.0, $varianceFunction->invoke([2, 0.5]));
        self::assertSame(2.0, $standardDeviationFunction->invoke([2, 0.5]));
        self::assertSame(
            $standardDeviationFunction->invoke([2, 0.5]),
            $functions->find('nbinomstddev')?->invoke([2, 0.5]),
        );
        self::assertSame(1.5, $skewnessFunction->invoke([2, 0.5]));
        self::assertSame(3.25, $excessKurtosisFunction->invoke([2, 0.5]));
        self::assertSame(6.25, $kurtosisFunction->invoke([2, 0.5]));
        self::assertSame(1.0, $medianFunction->invoke([2, 0.5]));
        self::assertSame(1.0, $modeFunction->invoke([2, 0.5]));
        self::assertSame(0.0, $medianFunction->invoke([1, 0.5]));
        self::assertSame(0.0, $modeFunction->invoke([1, 0.5]));
        self::assertSame(1.0, $rawMoment->invoke([0, 2, 0.5]));
        self::assertEqualsWithDelta(2.0, $rawMoment->invoke([1, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(8.0, $rawMoment->invoke([2, 2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(44.0, $rawMoment->invoke([3, 2, 0.5]), 1.0e-13);
        self::assertSame(0.0, $rawMoment->invoke([4, 2, 1.0]));
        self::assertSame(0.0, $medianFunction->invoke([4, 1.0]));
        self::assertSame(0.0, $modeFunction->invoke([4, 1.0]));
        self::assertSame(
            $rawMoment->invoke([3, 2, 0.5]),
            $functions->find('nbinomrawmoment')?->invoke([3, 2, 0.5]),
        );
        self::assertSame(
            $rawMoment->invoke([3, 2, 0.5]),
            $functions->find('negativebinomialrawmoment')?->invoke([3, 2, 0.5]),
        );
        self::assertSame(
            $medianFunction->invoke([2, 0.5]),
            $functions->find('nbinommedian')?->invoke([2, 0.5]),
        );
        self::assertSame(
            $modeFunction->invoke([2, 0.5]),
            $functions->find('nbinommode')?->invoke([2, 0.5]),
        );
        foreach ([[-1, 2, 0.5], [1.5, 2, 0.5], [65, 2, 0.5]] as $arguments) {
            try {
                $rawMoment->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('negativebinomialmoment() should reject invalid moment orders.');
        }
        $momentOverflowed = false;
        try {
            $rawMoment->invoke([64, 10000, 1.0e-300]);
        } catch (\OverflowException) {
            $momentOverflowed = true;
        }
        self::assertTrue($momentOverflowed, 'The selected raw moment exceeds the finite floating-point range.');
        self::assertSame(0.0, $meanFunction->invoke([4, 1.0]));
        self::assertSame(0.0, $varianceFunction->invoke([4, 1.0]));
        foreach (
            ['negativebinomialskewness', 'negativebinomialexcesskurtosis', 'negativebinomialkurtosis'] as $name
        ) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([4, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 4, 1.0]));
        self::assertSame(0.0, $logMgf->invoke([PHP_FLOAT_MAX, 4, 1.0]));
        self::assertSame(1.0, $mgf->invoke([0.0, 2, 0.5]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 2, 0.5]));
        $tilt = 0.2;
        $weightedMgf = 0.0;
        for ($failures = 0; $failures <= 500; ++$failures) {
            $weightedMgf += exp($tilt * $failures) * $mass->invoke([$failures, 2, 0.5]);
        }
        self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, 2, 0.5]), 1.0e-12);
        self::assertEqualsWithDelta(log($weightedMgf), $logMgf->invoke([$tilt, 2, 0.5]), 1.0e-12);
        self::assertSame($mgf->invoke([$tilt, 2, 0.5]), $functions->find('nbinommgf')?->invoke([$tilt, 2, 0.5]));
        self::assertSame(
            $logMgf->invoke([$tilt, 2, 0.5]),
            $functions->find('nbinomlogmgf')?->invoke([$tilt, 2, 0.5]),
        );
        $nearBoundaryLogMgf = $logMgf->invoke([log(2.0) - 1.0e-10, 10000, 0.5]);
        self::assertGreaterThan(709.0, $nearBoundaryLogMgf);
        $overflowed = false;
        try {
            $mgf->invoke([log(2.0) - 1.0e-10, 10000, 0.5]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        foreach ([log(2.0), 1.0] as $tiltOutsideDomain) {
            try {
                $mgf->invoke([$tiltOutsideDomain, 2, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('negativebinomialmgf() should reject arguments outside its convergence domain.');
        }

        foreach ([['negativebinomiallogcdf', -1, 2, 0.5], ['negativebinomiallogccdf', 0, 4, 1]] as $arguments) {
            $function = $functions->find($arguments[0]);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke(array_slice($arguments, 1));
            } catch (\DomainException) {
                continue;
            }
            self::fail($arguments[0] . ' should report its negative-infinite endpoint.');
        }

        foreach ([1, 2, 5] as $requiredSuccesses) {
            foreach ([0.1, 0.5, 0.9] as $successProbability) {
                $mean = $requiredSuccesses * (1.0 - $successProbability) / $successProbability;
                $variance = $requiredSuccesses * (1.0 - $successProbability)
                    / ($successProbability * $successProbability);
                $standardDeviation = sqrt($requiredSuccesses * (1.0 - $successProbability))
                    / $successProbability;
                $lastFailureCount = (int) ceil($mean + 15.0 * $standardDeviation);
                $massTotal = 0.0;
                $firstMoment = 0.0;
                $secondMoment = 0.0;
                for ($failures = 0; $failures <= $lastFailureCount; ++$failures) {
                    $probability = $mass->invoke([$failures, $requiredSuccesses, $successProbability]);
                    $massTotal += $probability;
                    $firstMoment += $failures * $probability;
                    $secondMoment += $failures * $failures * $probability;
                }
                self::assertEqualsWithDelta(
                    1.0,
                    $massTotal + $ccdf->invoke([$lastFailureCount, $requiredSuccesses, $successProbability]),
                    2.0e-12,
                );
                $expectedMean = $meanFunction->invoke([$requiredSuccesses, $successProbability]);
                $expectedVariance = $varianceFunction->invoke([$requiredSuccesses, $successProbability]);
                self::assertLessThanOrEqual(
                    3.0e-5 * max(1.0, $expectedMean),
                    abs($expectedMean - $firstMoment),
                );
                self::assertLessThanOrEqual(
                    3.0e-5 * max(1.0, $expectedVariance),
                    abs($expectedVariance - ($secondMoment - $firstMoment * $firstMoment)),
                );

                foreach ([0, (int) floor($mean), (int) ceil($mean + 3.0)] as $threshold) {
                    self::assertEqualsWithDelta(
                        1.0,
                        $cdf->invoke([$threshold, $requiredSuccesses, $successProbability])
                        + $ccdf->invoke([$threshold, $requiredSuccesses, $successProbability]),
                        2.0e-12,
                    );
                }
            }
        }

        $smallUpperTail = $ccdf->invoke([100, 2, 0.5]);
        self::assertGreaterThan(0.0, $smallUpperTail);
        self::assertSame(1.0, $cdf->invoke([100, 2, 0.5]));

        foreach ([[0.1, 2, 0.5], [0.5, 5, 0.2], [0.99, 3, 0.8]] as [$target, $requiredSuccesses, $successProbability]) {
            $quantile = $inverse->invoke([$target, $requiredSuccesses, $successProbability]);
            self::assertGreaterThanOrEqual(
                $target,
                $cdf->invoke([$quantile, $requiredSuccesses, $successProbability]),
            );
            if ($quantile > 0) {
                self::assertLessThan(
                    $target,
                    $cdf->invoke([$quantile - 1, $requiredSuccesses, $successProbability]),
                );
            }
        }
    }

    public function testNegativeBinomialFunctionsRejectInvalidParameters(): void
    {
        $functions = FunctionRegistry::defaults();
        $functionNames = [
            'negativebinomialpmf',
            'negativebinomialcdf',
            'negativebinomialccdf',
            'negativebinomialinv',
        ];
        foreach ($functionNames as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            $outcome = $name === 'negativebinomialinv' ? 0.5 : 0;
            $invalidParameters = [
                [$outcome, 0, 0.5],
                [$outcome, 1.5, 0.5],
                [$outcome, 10_001, 0.5],
                [$outcome, 2, 0],
                [$outcome, 2, 1.1],
            ];
            foreach ($invalidParameters as $arguments) {
                try {
                    $function->invoke($arguments);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject invalid required-success counts or success probabilities.');
            }
        }

        $inverse = $functions->find('negativebinomialinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([-0.1, 1.0, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 2, 0.5]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('negativebinomialinv should reject targets outside [0, 1).');
        }
    }

    public function testHypergeometricFunctionsRespectFinitePopulationSupport(): void
    {
        $functions = FunctionRegistry::defaults();
        $mass = $functions->find('hypergeometricpmf');
        $logMass = $functions->find('hypergeometriclogpmf');
        $cdf = $functions->find('hypergeometriccdf');
        $ccdf = $functions->find('hypergeometricccdf');
        $logCdf = $functions->find('hypergeometriclogcdf');
        $logCcdf = $functions->find('hypergeometriclogccdf');
        $inverse = $functions->find('hypergeometricinv');
        $median = $functions->find('hypergeometricmedian');
        $mode = $functions->find('hypergeometricmode');
        $mean = $functions->find('hypergeometricmean');
        $variance = $functions->find('hypergeometricvariance');
        $standardDeviation = $functions->find('hypergeometricstddev');
        $entropy = $functions->find('hypergeometricentropy');
        $skewness = $functions->find('hypergeometricskewness');
        $excessKurtosis = $functions->find('hypergeometricexcesskurtosis');
        $kurtosis = $functions->find('hypergeometrickurtosis');
        $mgf = $functions->find('hypergeometricmgf');
        $logMgf = $functions->find('hypergeometriclogmgf');
        $rawMoment = $functions->find('hypergeometricmoment');
        self::assertInstanceOf(FunctionDefinition::class, $mass);
        self::assertInstanceOf(FunctionDefinition::class, $logMass);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);

        self::assertEqualsWithDelta(0.5, $mass->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.5), $logMass->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertSame(-INF, $logMass->invoke([4, 10, 4, 3]));
        self::assertEqualsWithDelta(1.0, $mass->invoke([0, 0, 0, 0]), 2.0e-14);
        self::assertSame(0.0, $mass->invoke([0, 10000, 5000, 5000]));
        self::assertLessThan(0.0, $logMass->invoke([0, 10000, 5000, 5000]));
        self::assertEqualsWithDelta(2.0 / 3.0, $cdf->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0, $ccdf->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0 / 3.0), $logCdf->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logCcdf->invoke([1, 10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.2, $mean->invoke([10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(0.56, $variance->invoke([10, 4, 3]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(0.56), $standardDeviation->invoke([10, 4, 3]), 1.0e-14);
        $enumeratedEntropy = 0.0;
        for ($outcome = 0; $outcome <= 3; ++$outcome) {
            $probability = $mass->invoke([$outcome, 10, 4, 3]);
            if ($probability > 0.0) {
                $enumeratedEntropy -= $probability * log($probability);
            }
        }
        self::assertEqualsWithDelta($enumeratedEntropy, $entropy->invoke([10, 4, 3]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 10, 4, 3]));
        foreach ([1, 2, 3, 4, 5] as $order) {
            $enumeratedMoment = 0.0;
            for ($outcome = 0; $outcome <= 3; ++$outcome) {
                $enumeratedMoment += ($outcome ** $order) * $mass->invoke([$outcome, 10, 4, 3]);
            }
            self::assertEqualsWithDelta(
                $enumeratedMoment,
                $rawMoment->invoke([$order, 10, 4, 3]),
                2.0e-13,
            );
        }
        self::assertSame(
            $rawMoment->invoke([4, 10, 4, 3]),
            $functions->find('hypergeomrawmoment')?->invoke([4, 10, 4, 3]),
        );
        $thirdCentralMoment = 0.0;
        $fourthCentralMoment = 0.0;
        for ($outcome = 0; $outcome <= 3; ++$outcome) {
            $deviation = $outcome - 1.2;
            $probability = $mass->invoke([$outcome, 10, 4, 3]);
            $thirdCentralMoment += $deviation ** 3 * $probability;
            $fourthCentralMoment += $deviation ** 4 * $probability;
        }
        self::assertEqualsWithDelta(
            $thirdCentralMoment / (0.56 * sqrt(0.56)),
            $skewness->invoke([10, 4, 3]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $fourthCentralMoment / (0.56 ** 2) - 3.0,
            $excessKurtosis->invoke([10, 4, 3]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(3.0 + $excessKurtosis->invoke([10, 4, 3]), $kurtosis->invoke([10, 4, 3]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([3, 10, 4, 3]));
        self::assertSame(0.0, $logCcdf->invoke([-1, 10, 4, 3]));
        self::assertSame(0.0, $cdf->invoke([0, 10000, 5000, 5000]));
        self::assertLessThan(-1000.0, $logCdf->invoke([0, 10000, 5000, 5000]));
        self::assertEqualsWithDelta(
            $logCdf->invoke([0, 10000, 5000, 5000]),
            $logCcdf->invoke([4999, 10000, 5000, 5000]),
            1.0e-10,
        );
        self::assertSame(1, $inverse->invoke([0.5, 10, 4, 3]));
        self::assertSame(3, $inverse->invoke([1, 10, 4, 3]));
        self::assertSame(1, $median->invoke([10, 4, 3]));
        self::assertSame(1, $mode->invoke([10, 4, 3]));
        self::assertSame(0, $median->invoke([4, 1, 2]));
        self::assertSame(1, $mode->invoke([4, 1, 2]));
        self::assertSame(0, $median->invoke([0, 0, 0]));
        self::assertSame(0, $mode->invoke([0, 0, 0]));
        self::assertSame(1, $median->invoke([1, 1, 1]));
        self::assertSame(1, $mode->invoke([1, 1, 1]));
        self::assertSame($median->invoke([10, 4, 3]), $functions->find('hypergeommedian')?->invoke([10, 4, 3]));
        self::assertSame($mode->invoke([10, 4, 3]), $functions->find('hypergeommode')?->invoke([10, 4, 3]));
        self::assertSame(0.0, $mass->invoke([4, 10, 4, 3]));
        self::assertSame(0.0, $cdf->invoke([2, 10, 8, 5]));
        self::assertEqualsWithDelta(2.0 / 9.0, $cdf->invoke([3, 10, 8, 5]), 1.0e-14);
        self::assertSame(3, $inverse->invoke([0, 10, 8, 5]));
        self::assertSame(0, $inverse->invoke([0.7, 0, 0, 0]));
        self::assertSame(0.0, $mean->invoke([0, 0, 0]));
        self::assertSame(0.0, $variance->invoke([0, 0, 0]));
        self::assertSame(0.0, $variance->invoke([1, 1, 1]));
        self::assertSame(0.0, $standardDeviation->invoke([0, 0, 0]));
        self::assertSame(0.0, $entropy->invoke([0, 0, 0]));
        self::assertSame(0.0, $entropy->invoke([1, 1, 1]));
        self::assertSame(0.0, $rawMoment->invoke([2, 0, 0, 0]));
        foreach ([[-1, 10, 4, 3], [1.5, 10, 4, 3], [65, 10, 4, 3]] as $arguments) {
            try {
                $rawMoment->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('hypergeometricmoment() should reject invalid moment orders.');
        }
        foreach (['hypergeometricskewness', 'hypergeometricexcesskurtosis', 'hypergeometrickurtosis'] as $name) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([10, 0, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
        self::assertSame(1.0, $mgf->invoke([PHP_FLOAT_MAX, 10, 0, 10]));
        self::assertSame(0.0, $logMgf->invoke([PHP_FLOAT_MAX, 10, 0, 10]));
        self::assertSame(1.0, $mgf->invoke([0.0, 10, 4, 3]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 10, 4, 3]));
        foreach ([-0.4, 0.3] as $tilt) {
            $weightedMgf = 0.0;
            for ($outcome = 0; $outcome <= 3; ++$outcome) {
                $weightedMgf += exp($tilt * $outcome) * $mass->invoke([$outcome, 10, 4, 3]);
            }
            self::assertEqualsWithDelta($weightedMgf, $mgf->invoke([$tilt, 10, 4, 3]), 1.0e-13);
            self::assertEqualsWithDelta(log($weightedMgf), $logMgf->invoke([$tilt, 10, 4, 3]), 1.0e-13);
        }
        self::assertGreaterThan(700.0, $logMgf->invoke([1000.0, 10, 4, 3]));
        $overflowed = false;
        try {
            $mgf->invoke([1000.0, 10, 4, 3]);
        } catch (\OverflowException) {
            $overflowed = true;
        }
        self::assertTrue($overflowed);
        self::assertSame(
            $mgf->invoke([0.3, 10, 4, 3]),
            $functions->find('hypergeommgf')?->invoke([0.3, 10, 4, 3]),
        );
        self::assertSame(
            $logMgf->invoke([0.3, 10, 4, 3]),
            $functions->find('hypergeomlogmgf')?->invoke([0.3, 10, 4, 3]),
        );

        $parameterSets = [[20, 7, 5], [10, 8, 5], [12, 0, 4], [12, 12, 7], [12, 6, 0], [12, 6, 12]];
        foreach ($parameterSets as [$population, $successes, $draws]) {
            $minimum = max(0, $draws - ($population - $successes));
            $maximum = min($draws, $successes);
            $total = 0.0;
            $firstMoment = 0.0;
            $secondMoment = 0.0;
            for ($k = $minimum; $k <= $maximum; ++$k) {
                $probability = $mass->invoke([$k, $population, $successes, $draws]);
                $total += $probability;
                $firstMoment += $k * $probability;
                $secondMoment += $k * $k * $probability;
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$k, $population, $successes, $draws])
                        + $ccdf->invoke([$k, $population, $successes, $draws]),
                    2.0e-12,
                );
            }
            self::assertEqualsWithDelta(1.0, $total, 2.0e-12);
            self::assertEqualsWithDelta($firstMoment, $mean->invoke([$population, $successes, $draws]), 2.0e-12);
            self::assertEqualsWithDelta(
                $secondMoment - $firstMoment ** 2,
                $variance->invoke([$population, $successes, $draws]),
                2.0e-12,
            );
            foreach ([0.01, 0.5, 0.99, 1.0] as $target) {
                $quantile = $inverse->invoke([$target, $population, $successes, $draws]);
                self::assertGreaterThanOrEqual($minimum, $quantile);
                self::assertLessThanOrEqual($maximum, $quantile);
                self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $population, $successes, $draws]));
                if ($quantile > $minimum) {
                    self::assertLessThan($target, $cdf->invoke([$quantile - 1, $population, $successes, $draws]));
                }
            }
        }
    }

    public function testMultivariateHypergeometricProbabilityMass(): void
    {
        $functions = FunctionRegistry::defaults();
        $mass = $functions->find('multivariatehypergeometricpmf');
        $logMass = $functions->find('multivariatehypergeometriclogpmf');
        $alias = $functions->find('multihypergeometricpmf');
        $univariate = $functions->find('hypergeometricpmf');
        self::assertInstanceOf(FunctionDefinition::class, $mass);
        self::assertInstanceOf(FunctionDefinition::class, $logMass);
        self::assertInstanceOf(FunctionDefinition::class, $alias);
        self::assertInstanceOf(FunctionDefinition::class, $univariate);

        self::assertEqualsWithDelta(12.0 / 35.0, $mass->invoke([1, 2, 1, 3, 1, 2]), 1.0e-14);
        self::assertEqualsWithDelta(log(12.0 / 35.0), $logMass->invoke([1, 2, 1, 3, 1, 2]), 1.0e-14);
        self::assertSame(-INF, $logMass->invoke([3, 2, 0, 3]));
        self::assertEqualsWithDelta(0.0, $logMass->invoke([0, 0, 0, 0]), 1.0e-14);
        self::assertSame(0.0, $mass->invoke([2500, 5000, 0, 5000]));
        self::assertLessThan(0.0, $logMass->invoke([2500, 5000, 0, 5000]));
        self::assertEqualsWithDelta(
            $univariate->invoke([2, 10, 4, 5]),
            $alias->invoke([2, 4, 3, 6]),
            1.0e-14,
        );
        self::assertSame(0.0, $mass->invoke([3, 2, 0, 3]));
        self::assertSame(0.0, $mass->invoke([0.5, 2, 1, 3]));
        self::assertEqualsWithDelta(1.0, $mass->invoke([0, 0, 0, 0]), 1.0e-14);

        $total = 0.0;
        for ($first = 0; $first <= 2; ++$first) {
            for ($second = 0; $second <= 3; ++$second) {
                for ($third = 0; $third <= 2; ++$third) {
                    if ($first + $second + $third === 3) {
                        $total += $mass->invoke([$first, 2, $second, 3, $third, 2]);
                    }
                }
            }
        }
        self::assertEqualsWithDelta(1.0, $total, 2.0e-12);

        foreach ([[1, 2, 1], [0, -1, 0, 2], [0, 10_001, 0, 1], [0, 6000, 0, 5000]] as $arguments) {
            try {
                $mass->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Multivariate hypergeometric PMF should reject malformed or excessive populations.');
        }
    }

    public function testMultivariateHypergeometricMarginalMomentsAndCovariance(): void
    {
        $registry = FunctionRegistry::defaults();
        $arguments = [20, 5, 8];
        foreach (
            [
                ['multivariatehypergeometricmean', 'hypergeometricmean'],
                ['multivariatehypergeometricvariance', 'hypergeometricvariance'],
                ['multivariatehypergeometricstddev', 'hypergeometricstddev'],
            ] as [$multivariateName, $univariateName]
        ) {
            self::assertEqualsWithDelta(
                $registry->find($univariateName)?->invoke($arguments),
                $registry->find($multivariateName)?->invoke($arguments),
                1.0e-14,
            );
        }
        self::assertEqualsWithDelta(
            $registry->find('hypergeometricmoment')?->invoke([2, ...$arguments]),
            $registry->find('multivariatehypergeometricmoment')?->invoke([2, ...$arguments]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            -8.0 * (20.0 - 8.0) * 5.0 * 7.0 / (20.0 ** 2 * 19.0),
            $registry->find('multivariatehypergeometriccovariance')?->invoke([20, 5, 7, 8]),
            1.0e-14,
        );
        self::assertSame(
            0.0,
            $registry->find('multivariatehypergeometriccovariance')?->invoke([1, 1, 0, 1]),
        );

        foreach ([[20, 15, 6, 8], [20, -1, 2, 8], [20, 5, 7, 21]] as $invalidArguments) {
            try {
                $registry->find('multivariatehypergeometriccovariance')?->invoke($invalidArguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Multivariate hypergeometric covariance should reject impossible population parameters.');
        }
    }

    public function testNegativeHypergeometricFunctionsModelFailuresUntilRequiredSuccesses(): void
    {
        $functions = FunctionRegistry::defaults();
        $pmf = $functions->find('negativehypergeometricpmf');
        $logPmf = $functions->find('negativehypergeometriclogpmf');
        $cdf = $functions->find('negativehypergeometriccdf');
        $ccdf = $functions->find('negativehypergeometricccdf');
        $logCdf = $functions->find('negativehypergeometriclogcdf');
        $logCcdf = $functions->find('negativehypergeometriclogccdf');
        $inverse = $functions->find('negativehypergeometricinv');
        $mean = $functions->find('negativehypergeometricmean');
        $variance = $functions->find('negativehypergeometricvariance');
        $standardDeviation = $functions->find('negativehypergeometricstddev');
        $median = $functions->find('negativehypergeometricmedian');
        $mode = $functions->find('negativehypergeometricmode');
        $entropy = $functions->find('negativehypergeometricentropy');
        $skewness = $functions->find('negativehypergeometricskewness');
        $excessKurtosis = $functions->find('negativehypergeometricexcesskurtosis');
        $kurtosis = $functions->find('negativehypergeometrickurtosis');
        $rawMoment = $functions->find('negativehypergeometricmoment');
        $mgf = $functions->find('negativehypergeometricmgf');
        $logMgf = $functions->find('negativehypergeometriclogmgf');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);

        // Five objects contain three successes and two failures; stop at the second success.
        // The number of failures before it has masses 0.3, 0.4, 0.3.
        self::assertEqualsWithDelta(0.3, $pmf->invoke([0, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.3), $logPmf->invoke([0, 5, 3, 2]), 1.0e-14);
        self::assertSame(-INF, $logPmf->invoke([-1, 5, 3, 2]));
        self::assertSame(0.0, $pmf->invoke([0, 10000, 5000, 5000]));
        self::assertLessThan(0.0, $logPmf->invoke([0, 10000, 5000, 5000]));
        self::assertEqualsWithDelta(0.4, $pmf->invoke([1, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.3, $pmf->invoke([2, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.7, $cdf->invoke([1, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.3, $ccdf->invoke([1, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.7), $logCdf->invoke([1, 5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.3), $logCcdf->invoke([1, 5, 3, 2]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([2, 5, 3, 2]));
        self::assertSame(0.0, $logCcdf->invoke([-1, 5, 3, 2]));
        self::assertSame(0.0, $cdf->invoke([0, 10000, 5000, 5000]));
        self::assertLessThan(-1000.0, $logCdf->invoke([0, 10000, 5000, 5000]));
        self::assertEqualsWithDelta(
            log(0.5),
            $logCcdf->invoke([4999, 10000, 5000, 5000]),
            1.0e-10,
        );
        self::assertSame(0, $inverse->invoke([0, 5, 3, 2]));
        self::assertSame(1, $inverse->invoke([0.5, 5, 3, 2]));
        self::assertSame(2, $inverse->invoke([1, 5, 3, 2]));
        self::assertEqualsWithDelta(1.0, $mean->invoke([5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.6, $variance->invoke([5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(0.6), $standardDeviation->invoke([5, 3, 2]), 1.0e-14);
        self::assertSame(1, $median->invoke([5, 3, 2]));
        self::assertSame(1, $mode->invoke([5, 3, 2]));
        self::assertEqualsWithDelta(
            -2.0 * 0.3 * log(0.3) - 0.4 * log(0.4),
            $entropy->invoke([5, 3, 2]),
            1.0e-14,
        );
        self::assertSame(0.0, $mean->invoke([5, 5, 2]));
        self::assertSame(0.0, $variance->invoke([5, 5, 2]));
        self::assertSame(0.0, $standardDeviation->invoke([5, 5, 2]));
        self::assertSame(0, $median->invoke([5, 5, 2]));
        self::assertSame(0, $mode->invoke([5, 5, 2]));
        self::assertSame(0.0, $entropy->invoke([5, 5, 2]));
        self::assertEqualsWithDelta(0.0, $skewness->invoke([5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(-4.0 / 3.0, $excessKurtosis->invoke([5, 3, 2]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 3.0, $kurtosis->invoke([5, 3, 2]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 5, 3, 2]));
        foreach ([1, 2, 3, 4] as $order) {
            $enumeratedMoment = 0.0;
            for ($failures = 0; $failures <= 2; ++$failures) {
                $enumeratedMoment += $failures ** $order * $pmf->invoke([$failures, 5, 3, 2]);
            }
            self::assertEqualsWithDelta(
                $enumeratedMoment,
                $rawMoment->invoke([$order, 5, 3, 2]),
                2.0e-13,
            );
        }
        self::assertSame(0.0, $rawMoment->invoke([1, 5, 5, 2]));
        self::assertSame(1.0, $mgf->invoke([0.0, 5, 3, 2]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 5, 3, 2]));
        foreach ([-0.4, 0.2] as $tilt) {
            $enumeratedMgf = 0.0;
            for ($failures = 0; $failures <= 2; ++$failures) {
                $enumeratedMgf += exp($tilt * $failures) * $pmf->invoke([$failures, 5, 3, 2]);
            }
            self::assertEqualsWithDelta($enumeratedMgf, $mgf->invoke([$tilt, 5, 3, 2]), 1.0e-13);
            self::assertEqualsWithDelta(log($enumeratedMgf), $logMgf->invoke([$tilt, 5, 3, 2]), 1.0e-13);
        }
        $lastSuccessMgf = 0.0;
        for ($failures = 0; $failures <= 7; ++$failures) {
            $lastSuccessMgf += exp(0.2 * $failures) * $pmf->invoke([$failures, 10, 3, 3]);
        }
        self::assertEqualsWithDelta($lastSuccessMgf, $mgf->invoke([0.2, 10, 3, 3]), 1.0e-13);
        $mgfOverflowed = false;
        try {
            $mgf->invoke([1000.0, 5, 3, 2]);
        } catch (\OverflowException) {
            $mgfOverflowed = true;
        }
        self::assertTrue($mgfOverflowed);
        foreach ([[-1, 5, 3, 2], [1.5, 5, 3, 2], [65, 5, 3, 2]] as $arguments) {
            try {
                $rawMoment->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('negativehypergeometricmoment() should reject invalid moment orders.');
        }

        foreach ([[5, 3, 2], [10, 8, 5], [20, 7, 4], [12, 12, 9], [12, 1, 1]] as [$n, $k, $r]) {
            $maximumFailures = $n - $k;
            $total = 0.0;
            $firstMoment = 0.0;
            $secondMoment = 0.0;
            $probabilities = [];
            for ($x = 0; $x <= $maximumFailures; ++$x) {
                $probability = $pmf->invoke([$x, $n, $k, $r]);
                $probabilities[] = $probability;
                $total += $probability;
                $firstMoment += $x * $probability;
                $secondMoment += $x * $x * $probability;
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$x, $n, $k, $r]) + $ccdf->invoke([$x, $n, $k, $r]),
                    2.0e-12,
                );
            }
            self::assertEqualsWithDelta(1.0, $total, 2.0e-12);
            self::assertEqualsWithDelta($firstMoment, $mean->invoke([$n, $k, $r]), 2.0e-12);
            self::assertEqualsWithDelta(
                $secondMoment - $firstMoment ** 2,
                $variance->invoke([$n, $k, $r]),
                2.0e-12,
            );
            self::assertEqualsWithDelta(
                sqrt($secondMoment - $firstMoment ** 2),
                $standardDeviation->invoke([$n, $k, $r]),
                2.0e-12,
            );
            $expectedMedian = 0;
            $cumulative = 0.0;
            foreach ($probabilities as $failures => $probability) {
                $cumulative += $probability;
                if ($cumulative >= 0.5) {
                    $expectedMedian = $failures;
                    break;
                }
            }
            self::assertSame(
                $expectedMedian,
                $median->invoke([$n, $k, $r]),
            );
            $expectedMode = 0;
            foreach ($probabilities as $failures => $probability) {
                if ($probability >= $probabilities[$expectedMode] - 32.0 * PHP_FLOAT_EPSILON) {
                    $expectedMode = $failures;
                }
            }
            self::assertSame(
                $expectedMode,
                $mode->invoke([$n, $k, $r]),
            );
            $expectedEntropy = 0.0;
            foreach ($probabilities as $probability) {
                if ($probability > 0.0) {
                    $expectedEntropy -= $probability * log($probability);
                }
            }
            self::assertEqualsWithDelta(
                $expectedEntropy,
                $entropy->invoke([$n, $k, $r]),
                2.0e-12,
            );
            $varianceValue = $variance->invoke([$n, $k, $r]);
            if ($varianceValue > 0.0) {
                $thirdCentralMoment = 0.0;
                $fourthCentralMoment = 0.0;
                for ($x = 0; $x <= $maximumFailures; ++$x) {
                    $deviation = $x - $firstMoment;
                    $thirdCentralMoment += $deviation ** 3 * $pmf->invoke([$x, $n, $k, $r]);
                    $fourthCentralMoment += $deviation ** 4 * $pmf->invoke([$x, $n, $k, $r]);
                }
                self::assertEqualsWithDelta(
                    $thirdCentralMoment / ($varianceValue * sqrt($varianceValue)),
                    $skewness->invoke([$n, $k, $r]),
                    2.0e-11,
                );
                self::assertEqualsWithDelta(
                    $fourthCentralMoment / ($varianceValue ** 2) - 3.0,
                    $excessKurtosis->invoke([$n, $k, $r]),
                    2.0e-11,
                );
            }
            foreach ([0.0, 0.01, 0.5, 0.99, 1.0] as $target) {
                $quantile = $inverse->invoke([$target, $n, $k, $r]);
                self::assertGreaterThanOrEqual(0, $quantile);
                self::assertLessThanOrEqual($maximumFailures, $quantile);
                self::assertGreaterThanOrEqual($target, $cdf->invoke([$quantile, $n, $k, $r]));
                if ($quantile > 0) {
                    self::assertLessThan($target, $cdf->invoke([$quantile - 1, $n, $k, $r]));
                }
            }
        }
        foreach (
            [
                'negativehypergeometricskewness',
                'negativehypergeometricexcesskurtosis',
                'negativehypergeometrickurtosis',
            ] as $name
        ) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([5, 5, 2]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
    }

    public function testNegativeHypergeometricFunctionsRejectInvalidParameters(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (
            [
                'negativehypergeometricpmf',
                'negativehypergeometriccdf',
                'negativehypergeometricccdf',
                'negativehypergeometricinv',
            ] as $name
        ) {
            $function = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            foreach ([[0, 0, 0, 0], [0, 5, 0, 1], [0, 5, 3, 0], [0, 5, 2, 3], [0, 5.5, 3, 1]] as $args) {
                try {
                    $function->invoke($args);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject invalid finite-population parameters.');
            }
        }
        $inverse = $functions->find('negativehypergeometricinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([-0.1, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 5, 3, 2]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('negativehypergeometricinv should reject probabilities outside [0, 1].');
        }
    }

    public function testHypergeometricFunctionsRejectInvalidParameters(): void
    {
        $registry = FunctionRegistry::defaults();
        foreach (['hypergeometricpmf', 'hypergeometriccdf', 'hypergeometricccdf', 'hypergeometricinv'] as $name) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            foreach ([[0, -1, 0, 0], [0, 3.5, 2, 1], [0, 4, 5, 1], [0, 4, 2, 5]] as $parameters) {
                try {
                    $function->invoke($parameters);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject invalid population parameters.');
            }
        }
        $inverse = $registry->find('hypergeometricinv');
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        foreach ([-0.1, 1.1] as $target) {
            try {
                $inverse->invoke([$target, 10, 4, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('hypergeometricinv should reject probabilities outside [0, 1].');
        }
    }

    public function testChiSquareDistributionFunctionsUseRegularizedGammaTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('chisquarepdf');
        $logPdf = $registry->find('chisquarelogpdf');
        $cdf = $registry->find('chisquarecdf');
        $ccdf = $registry->find('chisquareccdf');
        $inverse = $registry->find('chisquareinv');
        $inverseDerivative = $registry->find('chisquareinvprime');
        $survivalInverseDerivative = $registry->find('chisquareccinvprime');
        $median = $registry->find('chisquaremedian');
        $mode = $registry->find('chisquaremode');
        $entropy = $registry->find('chisquareentropy');
        $hazard = $registry->find('chisquarehazard');
        $cumulativeHazard = $registry->find('chisquarecumhazard');
        $logMgf = $registry->find('chisquarelogmgf');
        $mgf = $registry->find('chisquaremgf');
        $mean = $registry->find('chisquaremean');
        $variance = $registry->find('chisquarevariance');
        $standardDeviation = $registry->find('chisquarestddev');
        $skewness = $registry->find('chisquareskewness');
        $excessKurtosis = $registry->find('chisquareexcesskurtosis');
        $kurtosis = $registry->find('chisquarekurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertEqualsWithDelta(0.5 * exp(-1.0), $pdf->invoke([2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 - exp(-1.0), $cdf->invoke([2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-1.0), $ccdf->invoke([2, 2]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([0, 3]));
        self::assertSame(1.0, $ccdf->invoke([0, 3]));
        self::assertSame(0.0, $pdf->invoke([-1, 3]));
        self::assertSame(0.5, $pdf->invoke([0, 2]));
        self::assertEqualsWithDelta(log(0.5), $logPdf->invoke([0, 2]), 1.0e-14);
        self::assertSame(0.0, $pdf->invoke([2000, 2]));
        self::assertLessThan(0.0, $logPdf->invoke([2000, 2]));
        self::assertSame(0.0, $inverse->invoke([0, 5]));
        self::assertEqualsWithDelta(5.0, $inverse->invoke([1.0 - exp(-2.5), 2]), 1.0e-12);
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.5, 2]), 1.0e-12);
        self::assertEqualsWithDelta(-4.0, $survivalInverseDerivative->invoke([0.5, 2]), 1.0e-12);
        self::assertEqualsWithDelta(2.0 * log(2.0), $median->invoke([2.0]), 1.0e-12);
        self::assertSame(0.0, $mode->invoke([1.0]));
        self::assertSame(2.0, $mode->invoke([4.0]));
        self::assertEqualsWithDelta(1.0 + log(2.0), $entropy->invoke([2.0]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $hazard->invoke([0.0, 2.0]), 1.0e-14);
        self::assertSame(0.0, $hazard->invoke([0.0, 4.0]));
        self::assertEqualsWithDelta(0.5, $hazard->invoke([2.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $cumulativeHazard->invoke([2.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($ccdf->invoke([5.0, 4.0])),
            $cumulativeHazard->invoke([5.0, 4.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([5.0, 4.0]) / $ccdf->invoke([5.0, 4.0]),
            $hazard->invoke([5.0, 4.0]),
            1.0e-14,
        );
        self::assertSame(4.0, $mean->invoke([4.0]));
        self::assertSame(8.0, $variance->invoke([4.0]));
        self::assertSame(4.0, $standardDeviation->invoke([8.0]));
        self::assertEqualsWithDelta(sqrt(2.0), $skewness->invoke([4.0]), 1.0e-14);
        self::assertSame(3.0, $excessKurtosis->invoke([4.0]));
        self::assertSame(6.0, $kurtosis->invoke([4.0]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 4.0]));
        self::assertSame(1.0, $mgf->invoke([0.0, 4.0]));
        self::assertEqualsWithDelta(-2.0 * log(0.5), $logMgf->invoke([0.25, 4.0]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $mgf->invoke([0.25, 4.0]), 1.0e-14);
        self::assertSame($logMgf->invoke([0.2, 4]), $registry->find('chilogmgf')?->invoke([0.2, 4]));
        self::assertSame($mgf->invoke([0.2, 4]), $registry->find('chimgf')?->invoke([0.2, 4]));
        foreach ([$logMgf, $mgf] as $momentGeneratingFunction) {
            try {
                $momentGeneratingFunction->invoke([0.5, 4.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($momentGeneratingFunction->name . ' should reject t >= 1/2.');
        }
        self::assertSame($mean->invoke([7]), $registry->find('chimean')?->invoke([7]));
        self::assertSame($median->invoke([7]), $registry->find('chimedian')?->invoke([7]));
        self::assertSame($mode->invoke([7]), $registry->find('chimode')?->invoke([7]));
        self::assertSame($entropy->invoke([7]), $registry->find('chientropy')?->invoke([7]));
        self::assertSame($hazard->invoke([5.0, 7.0]), $registry->find('chihazard')?->invoke([5.0, 7.0]));
        self::assertSame($variance->invoke([7]), $registry->find('chivar')?->invoke([7]));
        self::assertSame($skewness->invoke([7]), $registry->find('chiskewness')?->invoke([7]));

        foreach ([$mean, $variance, $standardDeviation, $skewness, $excessKurtosis, $kurtosis] as $moment) {
            try {
                $moment->invoke([0.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($moment->name . ' should reject non-positive degrees of freedom.');
        }
        try {
            $hazard->invoke([0.0, 1.0]);
            self::fail('Chi-square hazard should reject its infinite boundary value below two degrees of freedom.');
        } catch (\DomainException) {
            // The density diverges at zero below two degrees of freedom.
        }
        self::assertSame(0.0, $hazard->invoke([-1.0, 3.0]));
        self::assertSame(0.0, $cumulativeHazard->invoke([-1.0, 3.0]));

        foreach ([0.01, 0.1, 0.5, 0.9, 0.99] as $probability) {
            foreach ([0.5, 1.0, 2.0, 5.0, 30.0, 100.0, 1000.0] as $degreesOfFreedom) {
                $quantile = $inverse->invoke([$probability, $degreesOfFreedom]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $degreesOfFreedom]),
                    2.0e-9,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $degreesOfFreedom])
                        + $ccdf->invoke([$quantile, $degreesOfFreedom]),
                    2.0e-12,
                );
            }
        }

        foreach ([-1.0, 0.0] as $degreesOfFreedom) {
            try {
                $pdf->invoke([1, $degreesOfFreedom]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('chisquarepdf should reject non-positive degrees of freedom.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('chisquareinv should reject probabilities outside [0, 1).');
        }
        try {
            $pdf->invoke([0, 1]);
        } catch (\DomainException) {
            return;
        }
        self::fail('chisquarepdf should reject the singular density at zero for df < 2.');
    }

    public function testChiSquareLogDensityRejectsPointsWithoutFiniteLogDensity(): void
    {
        $logPdf = FunctionRegistry::defaults()->find('chilogpdf');
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertEqualsWithDelta(-1.0 - log(2.0), $logPdf->invoke([2, 2]), 1.0e-14);

        foreach ([[0, 1], [0, 3], [-1, 2]] as $arguments) {
            try {
                $logPdf->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('chilogpdf should reject points without a finite log-density.');
        }
    }

    public function testExponentialDistributionFunctionsAreStableAndInvertEachOther(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('exponentialpdf');
        $cdf = $registry->find('exponentialcdf');
        $survival = $registry->find('exponentialccdf');
        $inverse = $registry->find('exponentialinv');
        $inverseDerivative = $registry->find('exponentialinvprime');
        $survivalInverseDerivative = $registry->find('exponentialccinvprime');
        $mean = $registry->find('exponentialmean');
        $median = $registry->find('exponentialmedian');
        $mode = $registry->find('exponentialmode');
        $variance = $registry->find('exponentialvariance');
        $standardDeviation = $registry->find('exponentialstddev');
        $entropy = $registry->find('exponentialentropy');
        $hazard = $registry->find('exponentialhazard');
        $cumulativeHazard = $registry->find('exponentialcumhazard');
        $skewness = $registry->find('exponentialskewness');
        $excessKurtosis = $registry->find('exponentialexcesskurtosis');
        $kurtosis = $registry->find('exponentialkurtosis');
        $mgf = $registry->find('exponentialmgf');
        $logMgf = $registry->find('exponentiallogmgf');
        $rawMoment = $registry->find('exponentialmoment');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);

        self::assertSame(2.0, $pdf->invoke([0, 2]));
        self::assertSame(0.0, $pdf->invoke([-1, 2]));
        self::assertSame(0.0, $cdf->invoke([0, 2]));
        self::assertSame(1.0, $survival->invoke([0, 2]));
        self::assertEqualsWithDelta(-expm1(-2.0e-12), $cdf->invoke([1.0e-12, 2]), 1.0e-27);
        self::assertGreaterThan(0.0, $survival->invoke([400, 1]));
        self::assertSame(0.0, $inverse->invoke([0, 3]));
        self::assertEqualsWithDelta(1.0, $inverseDerivative->invoke([0.5, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(-1.0, $survivalInverseDerivative->invoke([0.5, 2.0]), 1.0e-14);
        self::assertSame(0.5, $mean->invoke([2.0]));
        self::assertSame(0.25, $variance->invoke([2.0]));
        self::assertEqualsWithDelta(log(2.0) / 2.0, $median->invoke([2.0]), 1.0e-14);
        self::assertSame(0.0, $mode->invoke([2.0]));
        self::assertSame(0.5, $standardDeviation->invoke([2.0]));
        self::assertEqualsWithDelta(1.0 - log(2.0), $entropy->invoke([2.0]), 1.0e-14);
        self::assertSame(2.0, $hazard->invoke([1000.0, 2.0]));
        self::assertSame(0.0, $cumulativeHazard->invoke([0.0, 2.0]));
        self::assertSame(2000.0, $cumulativeHazard->invoke([1000.0, 2.0]));
        self::assertSame(2.0, $skewness->invoke([2.0]));
        self::assertSame(6.0, $excessKurtosis->invoke([2.0]));
        self::assertSame(9.0, $kurtosis->invoke([2.0]));
        self::assertSame(1.0, $mgf->invoke([0.0, 2.0]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 2.0]));
        self::assertSame(2.0, $mgf->invoke([1.0, 2.0]));
        self::assertEqualsWithDelta(log(2.0), $logMgf->invoke([1.0, 2.0]), 1.0e-14);
        self::assertSame(0.5, $mgf->invoke([-2.0, 2.0]));
        self::assertEqualsWithDelta(-log(4.0), $logMgf->invoke([-6.0, 2.0]), 1.0e-14);
        self::assertSame(0.0, $mgf->invoke([-1.0e300, 1.0e-300]));
        self::assertEqualsWithDelta(-600.0 * log(10.0), $logMgf->invoke([-1.0e300, 1.0e-300]), 1.0e-10);
        self::assertSame(1.0, $rawMoment->invoke([0, 2.0]));
        self::assertEqualsWithDelta(0.75, $rawMoment->invoke([3, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(24.0, $rawMoment->invoke([4, 1.0]), 1.0e-13);
        self::assertSame($mgf->invoke([1.0, 2.0]), $registry->find('expmgf')?->invoke([1.0, 2.0]));
        self::assertSame($logMgf->invoke([1.0, 2.0]), $registry->find('explogmgf')?->invoke([1.0, 2.0]));
        self::assertSame($rawMoment->invoke([3, 2.0]), $registry->find('exponentialrawmoment')?->invoke([3, 2.0]));
        self::assertSame(1.0, $registry->find('expmean')?->invoke([1.0]));
        self::assertSame($median->invoke([2.0]), $registry->find('expmedian')?->invoke([2.0]));
        self::assertSame($standardDeviation->invoke([2.0]), $registry->find('expstddev')?->invoke([2.0]));
        self::assertSame($entropy->invoke([2.0]), $registry->find('expentropy')?->invoke([2.0]));

        foreach ([-1, 1.5, 65] as $order) {
            try {
                $rawMoment->invoke([$order, 2.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('exponentialmoment should reject invalid moment orders.');
        }
        try {
            $rawMoment->invoke([64, 1.0e-100]);
            self::fail('exponentialmoment should report an unrepresentable moment.');
        } catch (\OverflowException) {
            // The moment exceeds the finite floating-point range.
        }

        foreach ([$mgf, $logMgf] as $momentGeneratingFunction) {
            try {
                $momentGeneratingFunction->invoke([2.0, 2.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($momentGeneratingFunction->name . ' should reject t >= rate.');
        }

        foreach ([0.0, 1.0e-12, 0.1, 0.5, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([1.0e-6, 0.25, 1.0, 15.0, 1.0e6] as $rate) {
                $quantile = $inverse->invoke([$probability, $rate]);
                self::assertGreaterThanOrEqual(0.0, $quantile);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $rate]), 2.0e-12);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $rate]) + $survival->invoke([$quantile, $rate]),
                    2.0e-15,
                );
            }
        }

        foreach ([0.0, -1.0] as $rate) {
            try {
                $pdf->invoke([1, $rate]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Exponential functions should reject non-positive or non-finite rates.');
        }
        foreach (
            [
                'exponentialmean',
                'exponentialvariance',
                'exponentialskewness',
                'exponentialexcesskurtosis',
                'exponentialkurtosis',
            ] as $name
        ) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([0.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a non-positive rate.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('exponentialinv should reject probabilities outside [0, 1).');
        }
        foreach ([$hazard, $cumulativeHazard] as $function) {
            try {
                $function->invoke([-1.0, 2.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($function->name . ' should reject values below the support.');
        }
    }

    public function testUniformDistributionFunctionsHandleBoundariesAndRoundTrips(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('uniformpdf');
        $logPdf = $registry->find('uniformlogpdf');
        $cdf = $registry->find('uniformcdf');
        $survival = $registry->find('uniformccdf');
        $inverse = $registry->find('uniforminv');
        $mean = $registry->find('uniformmean');
        $variance = $registry->find('uniformvariance');
        $rawMoment = $registry->find('uniformmoment');
        $median = $registry->find('uniformmedian');
        $mode = $registry->find('uniformmode');
        $skewness = $registry->find('uniformskewness');
        $excessKurtosis = $registry->find('uniformexcesskurtosis');
        $kurtosis = $registry->find('uniformkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertSame(0.25, $pdf->invoke([0, -2, 2]));
        self::assertEqualsWithDelta(log(0.25), $logPdf->invoke([0, -2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logPdf->invoke([-2, -2, 2]), 1.0e-14);
        self::assertSame(0.25, $pdf->invoke([-2, -2, 2]));
        self::assertSame(0.0, $pdf->invoke([-2.1, -2, 2]));
        self::assertSame(0.0, $pdf->invoke([2.1, -2, 2]));
        self::assertSame(0.0, $cdf->invoke([-2, -2, 2]));
        self::assertSame(1.0, $cdf->invoke([2, -2, 2]));
        self::assertSame(1.0, $survival->invoke([-2, -2, 2]));
        self::assertSame(0.0, $survival->invoke([2, -2, 2]));
        self::assertSame(-2.0, $inverse->invoke([0, -2, 2]));
        self::assertSame(2.0, $inverse->invoke([1, -2, 2]));
        self::assertSame(0.0, $mean->invoke([-2, 2]));
        self::assertEqualsWithDelta(4.0 / 3.0, $variance->invoke([-2, 2]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, -2, 2]));
        self::assertSame(0.0, $rawMoment->invoke([1, -2, 2]));
        self::assertEqualsWithDelta(4.0 / 3.0, $rawMoment->invoke([2, -2, 2]), 1.0e-14);
        self::assertSame(0.0, $median->invoke([-2, 2]));
        self::assertSame(0.0, $mode->invoke([-2, 2]));
        self::assertSame(0.0, $skewness->invoke([-2, 2]));
        self::assertSame(-1.2, $excessKurtosis->invoke([-2, 2]));
        self::assertSame(1.8, $kurtosis->invoke([-2, 2]));
        self::assertEqualsWithDelta(7.0, $rawMoment->invoke([2, 1, 4]), 1.0e-14);
        self::assertEqualsWithDelta(
            1.0e24,
            $rawMoment->invoke([2, 1.0e12, 1.0e12 + 1.0]),
            2.0e12,
        );
        self::assertSame(2.5, $median->invoke([1, 4]));
        self::assertSame(2.5, $mode->invoke([1, 4]));
        foreach ([-1, 65, 1.5] as $order) {
            try {
                $rawMoment->invoke([$order, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('uniformmoment() should reject invalid moment orders.');
        }

        foreach ([[-100.0, 100.0], [-0.25, 0.75], [1.0, 1.0001]] as [$minimum, $maximum]) {
            foreach ([0.0, 0.01, 0.5, 0.9, 1.0] as $probability) {
                $quantile = $inverse->invoke([$probability, $minimum, $maximum]);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $minimum, $maximum]), 2.0e-12);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $minimum, $maximum])
                        + $survival->invoke([$quantile, $minimum, $maximum]),
                    2.0e-12,
                );
            }
            self::assertEqualsWithDelta(
                1.0,
                ($maximum - $minimum) * $pdf->invoke([$minimum, $minimum, $maximum]),
                1.0e-12,
            );
        }

        foreach ([[0, 1, 1], [0, 2, 1], [NAN, 0, 1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            } catch (\InvalidArgumentException) {
                continue;
            }
            self::fail('uniformpdf should reject invalid bounds and non-finite values.');
        }
        foreach ([-0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('uniforminv should reject probabilities outside [0, 1].');
        }
    }

    public function testWeibullDistributionFunctionsHandleShapeBoundariesAndRoundTrips(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('weibullpdf');
        $cdf = $registry->find('weibullcdf');
        $survival = $registry->find('weibullccdf');
        $logCdf = $registry->find('weibulllogcdf');
        $logSurvival = $registry->find('weibulllogccdf');
        $inverse = $registry->find('weibullinv');
        $inverseDerivative = $registry->find('weibullinvprime');
        $survivalInverseDerivative = $registry->find('weibullccinvprime');
        $mode = $registry->find('weibullmode');
        $hazard = $registry->find('weibullhazard');
        $cumulativeHazard = $registry->find('weibullcumhazard');
        $mean = $registry->find('weibullmean');
        $median = $registry->find('weibullmedian');
        $variance = $registry->find('weibullvariance');
        $standardDeviation = $registry->find('weibullstddev');
        $entropy = $registry->find('weibullentropy');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);

        self::assertSame(0.0, $pdf->invoke([-1, 2, 3]));
        self::assertSame(0.0, $cdf->invoke([0, 2, 3]));
        self::assertSame(1.0, $survival->invoke([0, 2, 3]));
        self::assertSame(1.0 / 3.0, $pdf->invoke([0, 1, 3]));
        self::assertSame(0.0, $pdf->invoke([0, 2, 3]));
        self::assertSame(0.0, $survival->invoke([1000, 2, 3]));
        self::assertEqualsWithDelta(log1p(-exp(-1.0)), $logCdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertSame(-1.0, $logSurvival->invoke([1, 1, 1]));
        self::assertEqualsWithDelta(2.0 * log(1.0e-100), $logCdf->invoke([1.0e-100, 2, 1]), 1.0e-12);
        self::assertEqualsWithDelta(-10000.0, $logSurvival->invoke([100, 2, 1]), 1.0e-10);
        self::assertSame(0.0, $logSurvival->invoke([0, 2, 1]));
        try {
            $logCdf->invoke([0, 2, 1]);
            self::fail(
                'weibulllogcdf should reject x = 0 because the lower-tail log probability is negative infinity.',
            );
        } catch (\DomainException) {
            // The lower-tail probability is zero at the support boundary.
        }
        self::assertGreaterThan(0.0, $survival->invoke([400, 1, 1]));
        self::assertSame(0.0, $inverse->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(3.0, $inverse->invoke([1.0 - exp(-1.0), 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(6.0, $inverseDerivative->invoke([0.5, 1, 3]), 1.0e-12);
        self::assertEqualsWithDelta(-6.0, $survivalInverseDerivative->invoke([0.5, 1, 3]), 1.0e-12);
        self::assertSame(0.0, $mode->invoke([0.5, 3.0]));
        self::assertSame(0.0, $mode->invoke([1.0, 3.0]));
        self::assertEqualsWithDelta(3.0 / sqrt(2.0), $mode->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * (3.0 / 4.0) ** 0.25, $mode->invoke([4.0, 2.0]), 1.0e-14);
        self::assertSame(1.0, $hazard->invoke([1.0, 1.0, 1.0]));
        self::assertSame(1.0, $cumulativeHazard->invoke([1.0, 1.0, 1.0]));
        self::assertEqualsWithDelta(4.0, $hazard->invoke([2.0, 2.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $cumulativeHazard->invoke([2.0, 2.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($survival->invoke([2.0, 2.0, 1.0])),
            $cumulativeHazard->invoke([2.0, 2.0, 1.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([2.0, 2.0, 1.0]) / $survival->invoke([2.0, 2.0, 1.0]),
            $hazard->invoke([2.0, 2.0, 1.0]),
            1.0e-14,
        );
        self::assertSame(0.0, $hazard->invoke([0.0, 2.0, 1.0]));
        self::assertSame(1.0, $hazard->invoke([0.0, 1.0, 1.0]));
        try {
            $hazard->invoke([0.0, 0.5, 1.0]);
            self::fail('The Weibull hazard should reject its infinite boundary value.');
        } catch (\DomainException) {
            // The hazard diverges at zero for shape below one.
        }
        foreach ([$hazard, $cumulativeHazard] as $function) {
            try {
                $function->invoke([-1.0, 2.0, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($function->name . ' should reject values below the support.');
        }
        self::assertEqualsWithDelta(3.0, $mean->invoke([1.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 * log(2.0), $median->invoke([1.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(9.0, $variance->invoke([1.0, 3.0]), 1.0e-13);
        self::assertEqualsWithDelta(3.0, $standardDeviation->invoke([1.0, 3.0]), 1.0e-13);
        self::assertEqualsWithDelta(1.0 + log(3.0), $entropy->invoke([1.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            9.0 * (1.0 - M_PI / 4.0),
            $variance->invoke([2.0, 3.0]),
            1.0e-13,
        );
        $largeShapeVariance = 1.6449340668482264e-16
            * (1.0 - (2.0 * 0.5772156649015329 + 2.0 * 1.2020569031595943 / 1.6449340668482264) * 1.0e-8);
        self::assertEqualsWithDelta($largeShapeVariance, $variance->invoke([1.0e8, 1.0]), 1.0e-25);

        foreach ([0.25, 0.5, 1.0, 2.0, 5.0] as $shape) {
            foreach ([0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
                $quantile = $inverse->invoke([$probability, $shape, 2.5]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $shape, 2.5]), 3.0e-12);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $shape, 2.5]) + $survival->invoke([$quantile, $shape, 2.5]),
                    2.0e-15,
                );
            }
        }
        foreach ([[1.0e-5, 0.5, 3.0], [0.25, 1.0, 2.0], [4.0, 2.0, 0.5], [100.0, 8.0, 10.0]] as $parameters) {
            self::assertGreaterThanOrEqual(0.0, $pdf->invoke($parameters));
        }

        foreach ([[1, 0, 1], [1, 1, 0], [1, -1, 1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Weibull functions should reject non-positive shape and scale.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('weibullinv should reject probabilities outside [0, 1).');
        }
        try {
            $pdf->invoke([0, 0.5, 1]);
        } catch (\DomainException) {
            return;
        }
        self::fail('weibullpdf should reject the singular density at zero for shape < 1.');
    }

    public function testWeibullAndGammaLogDensitiesPreserveExtremeTailValues(): void
    {
        $registry = FunctionRegistry::defaults();
        $weibullPdf = $registry->find('weibullpdf');
        $weibullLogPdf = $registry->find('weibulllogpdf');
        $gammaPdf = $registry->find('gammadistpdf');
        $gammaLogPdf = $registry->find('gammalogpdf');
        self::assertInstanceOf(FunctionDefinition::class, $weibullPdf);
        self::assertInstanceOf(FunctionDefinition::class, $weibullLogPdf);
        self::assertInstanceOf(FunctionDefinition::class, $gammaPdf);
        self::assertInstanceOf(FunctionDefinition::class, $gammaLogPdf);

        self::assertSame(0.0, $weibullPdf->invoke([100, 2, 1]));
        self::assertEqualsWithDelta(log(2.0) + log(100.0) - 10000.0, $weibullLogPdf->invoke([100, 2, 1]), 1.0e-10);
        self::assertSame(0.0, $gammaPdf->invoke([1000, 2, 1]));
        self::assertEqualsWithDelta(log(1000.0) - 1000.0, $gammaLogPdf->invoke([1000, 2, 1]), 1.0e-12);
        self::assertEqualsWithDelta(-log(3.0), $weibullLogPdf->invoke([0, 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(-log(3.0), $gammaLogPdf->invoke([0, 1, 3]), 1.0e-14);

        foreach (
            [[$weibullLogPdf, [-1, 2, 1]], [$gammaLogPdf, [-1, 2, 1]]] as [$function, $arguments]
        ) {
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Log densities should reject inputs outside the distribution support.');
        }
    }

    public function testWeibullRawMomentsSupportIntegerOrdersAndValidateDomain(): void
    {
        $registry = FunctionRegistry::defaults();
        $moment = $registry->find('weibullmoment');
        self::assertInstanceOf(FunctionDefinition::class, $moment);

        foreach ([0 => 1.0, 1 => 2.0, 2 => 8.0, 3 => 48.0, 4 => 384.0] as $order => $expected) {
            self::assertEqualsWithDelta($expected, $moment->invoke([$order, 1.0, 2.0]), 1.0e-12);
        }
        self::assertSame($moment->invoke([3, 2, 4]), $registry->find('weibullrawmoment')?->invoke([3, 2, 4]));
        self::assertEqualsWithDelta(2.5 ** 3 * 3.0 * sqrt(M_PI) / 4.0, $moment->invoke([3, 2, 2.5]), 1.0e-12);

        foreach ([[-1, 2, 1], [1.5, 2, 1], [65, 2, 1]] as $invalid) {
            try {
                $moment->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('weibullmoment should reject negative, non-integer, or excessive orders.');
        }
    }

    public function testWeibullShapeStatisticsAndAliases(): void
    {
        $registry = FunctionRegistry::defaults();
        $skewness = $registry->find('weibullskewness');
        $excessKurtosis = $registry->find('weibullexcesskurtosis');
        $kurtosis = $registry->find('weibullkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertEqualsWithDelta(2.0, $skewness->invoke([1.0, 17.0]), 1.0e-12);
        self::assertEqualsWithDelta(6.0, $excessKurtosis->invoke([1.0, 0.25]), 1.0e-12);
        self::assertEqualsWithDelta(9.0, $kurtosis->invoke([1.0, 4.0]), 1.0e-12);
        self::assertEqualsWithDelta(0.6311106578189364, $skewness->invoke([2.0, 3.0]), 1.0e-12);
        self::assertEqualsWithDelta(0.245089300687638, $excessKurtosis->invoke([2.0, 3.0]), 1.0e-12);
        self::assertEqualsWithDelta(3.245089300687638, $kurtosis->invoke([2.0, 3.0]), 1.0e-12);

        foreach (['weibullskewness', 'weibullexcesskurtosis', 'weibullkurtosis'] as $name) {
            try {
                $registry->find($name)?->invoke([0.0, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a non-positive shape parameter.');
        }
    }

    public function testExponentialRayleighLogisticAndCauchyLogDensitiesCoverTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $exponential = $registry->find('exponentiallogpdf');
        $rayleigh = $registry->find('rayleighlogpdf');
        $logistic = $registry->find('logisticlogpdf');
        $cauchy = $registry->find('cauchylogpdf');
        $exponentialPdf = $registry->find('exponentialpdf');
        $rayleighPdf = $registry->find('rayleighpdf');
        $logisticPdf = $registry->find('logisticpdf');
        $cauchyPdf = $registry->find('cauchypdf');
        self::assertInstanceOf(FunctionDefinition::class, $exponential);
        self::assertInstanceOf(FunctionDefinition::class, $rayleigh);
        self::assertInstanceOf(FunctionDefinition::class, $logistic);
        self::assertInstanceOf(FunctionDefinition::class, $cauchy);
        self::assertInstanceOf(FunctionDefinition::class, $exponentialPdf);
        self::assertInstanceOf(FunctionDefinition::class, $rayleighPdf);
        self::assertInstanceOf(FunctionDefinition::class, $logisticPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cauchyPdf);

        self::assertEqualsWithDelta(log(2.0), $exponential->invoke([0, 2]), 1.0e-14);
        self::assertEqualsWithDelta(-log(2.0) - 0.5, $rayleigh->invoke([2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(-3.0 * log(2.0), $logistic->invoke([0, 0, 2]), 1.0e-14);
        self::assertEqualsWithDelta(-log(M_PI) - log(2.0), $cauchy->invoke([0, 0, 2]), 1.0e-14);

        self::assertSame(0.0, $exponentialPdf->invoke([1000, 1]));
        self::assertEqualsWithDelta(-1000.0, $exponential->invoke([1000, 1]), 1.0e-12);
        self::assertSame(0.0, $rayleighPdf->invoke([100, 1]));
        self::assertEqualsWithDelta(log(100.0) - 5000.0, $rayleigh->invoke([100, 1]), 1.0e-10);
        self::assertSame(0.0, $logisticPdf->invoke([1000, 0, 1]));
        self::assertEqualsWithDelta(-1000.0, $logistic->invoke([1000, 0, 1]), 1.0e-12);
        self::assertSame(0.0, $cauchyPdf->invoke([1.0e200, 0, 1]));
        self::assertEqualsWithDelta(
            -log(M_PI) - 400.0 * log(10.0),
            $cauchy->invoke([1.0e200, 0, 1]),
            1.0e-12,
        );
    }

    public function testBetaAndBetaPrimeLogDensitiesPreserveTailAndEndpointValues(): void
    {
        $registry = FunctionRegistry::defaults();
        $betaPdf = $registry->find('betapdf');
        $betaLogPdf = $registry->find('betalogpdf');
        $betaPrimePdf = $registry->find('betaprimepdf');
        $betaPrimeLogPdf = $registry->find('betaprime' . 'logpdf');
        $betaPrimeAlias = $registry->find('beta2logpdf');
        self::assertInstanceOf(FunctionDefinition::class, $betaPdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaLogPdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaPrimePdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaPrimeLogPdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaPrimeAlias);

        self::assertSame(0.0, $betaPdf->invoke([1.0e-100, 5, 2]));
        self::assertEqualsWithDelta(
            4.0 * log(1.0e-100) + log(30.0),
            $betaLogPdf->invoke([1.0e-100, 5, 2]),
            1.0e-12,
        );
        self::assertSame(0.0, $betaPrimePdf->invoke([1.0e100, 2, 5]));
        self::assertEqualsWithDelta(
            -6.0 * log(1.0e100) + log(30.0),
            $betaPrimeLogPdf->invoke([1.0e100, 2, 5]),
            1.0e-12,
        );
        self::assertEqualsWithDelta($betaPrimeLogPdf->invoke([2, 3, 4]), $betaPrimeAlias->invoke([2, 3, 4]), 1.0e-14);
        self::assertEqualsWithDelta(log(3.0), $betaLogPdf->invoke([0, 1, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(3.0), $betaPrimeLogPdf->invoke([0, 1, 3]), 1.0e-14);
    }

    public function testLogNormalDistributionFunctionsUseNormalLogSpaceAndRoundTrip(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('lognormalpdf');
        $logPdf = $registry->find('lognormallogpdf');
        $cdf = $registry->find('lognormalcdf');
        $survival = $registry->find('lognormalccdf');
        $logCdf = $registry->find('lognormallogcdf');
        $logSurvival = $registry->find('lognormallogccdf');
        $logCdfAlias = $registry->find('lognormlogcdf');
        $logSurvivalAlias = $registry->find('lognormlogccdf');
        $inverse = $registry->find('lognormalinv');
        $inverseDerivative = $registry->find('lognormalinvprime');
        $survivalInverseDerivative = $registry->find('lognormalccinvprime');
        $hazard = $registry->find('lognormalhazard');
        $cumulativeHazard = $registry->find('lognormalcumhazard');
        $meanFunction = $registry->find('lognormalmean');
        $medianFunction = $registry->find('lognormalmedian');
        $modeFunction = $registry->find('lognormalmode');
        $entropyFunction = $registry->find('lognormalentropy');
        $varianceFunction = $registry->find('lognormalvariance');
        $skewnessFunction = $registry->find('lognormalskewness');
        $excessKurtosisFunction = $registry->find('lognormalexcesskurtosis');
        $kurtosisFunction = $registry->find('lognormalkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdfAlias);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvivalAlias);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $meanFunction);
        self::assertInstanceOf(FunctionDefinition::class, $medianFunction);
        self::assertInstanceOf(FunctionDefinition::class, $modeFunction);
        self::assertInstanceOf(FunctionDefinition::class, $entropyFunction);
        self::assertInstanceOf(FunctionDefinition::class, $varianceFunction);
        self::assertInstanceOf(FunctionDefinition::class, $skewnessFunction);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosisFunction);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosisFunction);

        self::assertSame(0.0, $pdf->invoke([0, 0, 1]));
        self::assertSame(0.0, $cdf->invoke([-1, 0, 1]));
        self::assertSame(1.0, $survival->invoke([0, 0, 1]));
        self::assertSame(0.0, $hazard->invoke([0, 0, 1]));
        self::assertSame(0.0, $cumulativeHazard->invoke([0, 0, 1]));
        self::assertEqualsWithDelta(1.0 / sqrt(2.0 * M_PI), $pdf->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-0.5 * log(2.0 * M_PI), $logPdf->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0 * M_PI), $inverseDerivative->invoke([0.5, 0, 1]), 1.0e-13);
        self::assertEqualsWithDelta(-sqrt(2.0 * M_PI), $survivalInverseDerivative->invoke([0.5, 0, 1]), 1.0e-13);
        self::assertSame(0.0, $pdf->invoke([1.0e100, 0, 0.1]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e100, 0, 0.1]));
        foreach ([[0, 0, 1], [-1, 0, 1]] as $invalid) {
            try {
                $logPdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('lognormallogpdf should reject non-positive values.');
        }
        self::assertSame(0.5, $cdf->invoke([1, 0, 1]));
        self::assertSame(0.5, $survival->invoke([1, 0, 1]));
        self::assertSame(log(0.5), $logCdf->invoke([1, 0, 1]));
        self::assertSame(log(0.5), $logSurvival->invoke([1, 0, 1]));
        self::assertEqualsWithDelta(sqrt(2.0 / M_PI), $hazard->invoke([1, 0, 1]), 1.0e-14);
        self::assertSame(log(2.0), $cumulativeHazard->invoke([1, 0, 1]));
        self::assertEqualsWithDelta(-804.6084420137538, $logCdf->invoke([exp(-40.0), 0, 1]), 1.0e-10);
        self::assertEqualsWithDelta(-804.6084420137538, $logSurvival->invoke([exp(40.0), 0, 1]), 1.0e-10);
        self::assertSame($logCdf->invoke([1, 0, 1]), $logCdfAlias->invoke([1, 0, 1]));
        self::assertSame($logSurvival->invoke([1, 0, 1]), $logSurvivalAlias->invoke([1, 0, 1]));
        self::assertSame(0.0, $logSurvival->invoke([0, 0, 1]));
        try {
            $logCdf->invoke([0, 0, 1]);
            self::fail('lognormallogcdf should reject its zero-probability support boundary.');
        } catch (\DomainException) {
            // The logarithm of the zero lower-tail probability is singular.
        }
        self::assertSame(0.0, $survival->invoke([1.0e100, 0, 0.1]));
        self::assertGreaterThan(0.0, $hazard->invoke([exp(40.0), 0, 1]));
        self::assertLessThan(1.0e-15, $hazard->invoke([exp(40.0), 0, 1]));
        self::assertEqualsWithDelta(804.6084420137538, $cumulativeHazard->invoke([exp(40.0), 0, 1]), 1.0e-10);
        self::assertSame(1.0, $inverse->invoke([0.5, 0, 1]));
        self::assertEqualsWithDelta(exp(0.5), $meanFunction->invoke([0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $medianFunction->invoke([0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-1.0), $modeFunction->invoke([0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5 * (1.0 + log(2.0 * M_PI)), $entropyFunction->invoke([0, 1]), 1.0e-14);
        self::assertSame(
            $inverse->invoke([0.5, 0, 1]),
            $medianFunction->invoke([0, 1]),
        );
        self::assertEqualsWithDelta(exp(-0.01), $modeFunction->invoke([0, 0.1]), 1.0e-14);
        self::assertEqualsWithDelta(exp(1.0) * (exp(1.0) - 1.0), $varianceFunction->invoke([0, 1]), 1.0e-13);
        self::assertEqualsWithDelta(
            (exp(1.0) + 2.0) * sqrt(exp(1.0) - 1.0),
            $skewnessFunction->invoke([0, 1]),
            1.0e-13,
        );
        $expectedExcess = exp(4.0) + 2.0 * exp(3.0) + 3.0 * exp(2.0) - 6.0;
        self::assertEqualsWithDelta($expectedExcess, $excessKurtosisFunction->invoke([0, 1]), 1.0e-12);
        self::assertEqualsWithDelta(3.0 + $expectedExcess, $kurtosisFunction->invoke([0, 1]), 1.0e-12);

        foreach ([0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[-2.0, 0.25], [0.0, 1.0], [5.0, 3.0]] as [$mean, $standardDeviation]) {
                $quantile = $inverse->invoke([$probability, $mean, $standardDeviation]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $mean, $standardDeviation]),
                    3.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $mean, $standardDeviation])
                        + $survival->invoke([$quantile, $mean, $standardDeviation]),
                    3.0e-12,
                );
            }
        }

        foreach ([[1, 0, 0], [1, 0, -1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Log-normal functions should reject non-positive standard deviations.');
        }
        $overflowCases = [
            [$meanFunction, [1000, 1]],
            [$medianFunction, [1000, 1]],
            [$modeFunction, [1000, 1]],
            [$varianceFunction, [0, 1.0e200]],
        ];
        foreach ($overflowCases as [$function, $arguments]) {
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($arguments);
            } catch (\OverflowException) {
                continue;
            }
            self::fail('Log-normal statistics should report results outside the finite floating-point range.');
        }
        foreach ([0.0, 1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('lognormalinv should require probabilities strictly between 0 and 1.');
        }
    }

    public function testContinuousDistributionInverseSurvivalFunctionsUseDirectTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $cases = [
            ['exponentialccinv', [0.2, 2.0], -log(0.2) / 2.0, 'exponentialccdf', [2.0]],
            ['rayleighccinv', [0.2, 2.0], 2.0 * sqrt(-2.0 * log(0.2)), 'rayleighccdf', [2.0]],
            ['uniformccinv', [0.2, 1.0, 5.0], 4.2, 'uniformccdf', [1.0, 5.0]],
            ['weibullccinv', [0.2, 2.0, 3.0], 3.805908723538598, 'weibullccdf', [2.0, 3.0]],
            ['lognormalccinv', [0.2, 0.0, 1.0], 2.320125394504049, 'lognormalccdf', [0.0, 1.0]],
            ['logisticccinv', [0.2, 0.0, 1.0], log(4.0), 'logisticccdf', [0.0, 1.0]],
            ['laplaceccinv', [0.2, 0.0, 1.0], -log(0.4), 'laplaceccdf', [0.0, 1.0]],
        ];

        foreach ($cases as [$inverseName, $arguments, $expected, $survivalName, $parameters]) {
            $inverse = $registry->find($inverseName);
            $survival = $registry->find($survivalName);
            self::assertInstanceOf(FunctionDefinition::class, $inverse);
            self::assertInstanceOf(FunctionDefinition::class, $survival);
            $quantile = $inverse->invoke($arguments);
            self::assertEqualsWithDelta($expected, $quantile, 1.0e-12);
            self::assertEqualsWithDelta(
                $arguments[0],
                $survival->invoke(array_merge([$quantile], $parameters)),
                1.0e-12,
            );
        }

        self::assertEqualsWithDelta(
            690.7755278982137,
            $registry->find('exponentialccinv')?->invoke([1.0e-300, 1.0]),
            1.0e-12,
        );
        $normalTailQuantile = $registry->find('lognormalccinv')?->invoke([1.0e-300, 0.0, 1.0]);
        self::assertIsFloat($normalTailQuantile);
        self::assertEqualsWithDelta(
            log(1.0e-300),
            $registry->find('lognormallogccdf')?->invoke([$normalTailQuantile, 0.0, 1.0]),
            1.0e-10,
        );
        self::assertSame(0.0, $registry->find('weibullccinv')?->invoke([1.0, 2.0, 3.0]));
        self::assertSame(0.0, $registry->find('lognormalccinv')?->invoke([1.0, 0.0, 1.0]));
        self::assertSame(1.0, $registry->find('uniformccinv')?->invoke([1.0, 1.0, 5.0]));

        foreach (
            [
                ['exponentialccinv', [0.0, 1.0]],
                ['rayleighccinv', [0.0, 1.0]],
                ['weibullccinv', [0.0, 1.0, 1.0]],
                ['lognormalccinv', [0.0, 0.0, 1.0]],
                ['logisticccinv', [1.0, 0.0, 1.0]],
                ['laplaceccinv', [0.0, 0.0, 1.0]],
            ] as [$name, $arguments]
        ) {
            try {
                $registry->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject survival probabilities outside its finite quantile domain.');
        }
    }

    public function testBetaGammaAndFDistributionInverseSurvivalFunctionsUseDirectTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $cases = [
            ['fccinv', [0.2, 5.0, 10.0], 'fccdf', [5.0, 10.0], 'fdistributionccinv'],
            ['betaccinv', [0.2, 2.0, 3.0], 'betaccdf', [2.0, 3.0], 'betadistributionccinv'],
            ['beta2ccinv', [0.2, 2.0, 3.0], 'beta2ccdf', [2.0, 3.0], 'betaprimeccinv'],
            ['gammaccinv', [0.2, 2.0, 3.0], 'gammaccdf', [2.0, 3.0], 'gammadistccinv'],
            ['chiccinv', [0.2, 4.0], 'chiccdf', [4.0], 'chisquareccinv'],
        ];

        foreach ($cases as [$inverseName, $arguments, $survivalName, $parameters, $longName]) {
            $inverse = $registry->find($inverseName);
            $survival = $registry->find($survivalName);
            self::assertInstanceOf(FunctionDefinition::class, $inverse);
            self::assertInstanceOf(FunctionDefinition::class, $survival);
            $quantile = $inverse->invoke($arguments);
            self::assertEqualsWithDelta(
                $arguments[0],
                $survival->invoke(array_merge([$quantile], $parameters)),
                1.0e-11,
                $inverseName,
            );
            self::assertSame($quantile, $registry->find($longName)?->invoke($arguments));
            self::assertSame(0.0, $inverse->invoke(array_merge([1.0], array_slice($arguments, 1))));
        }

        $gammaTailQuantile = $registry->find('gammaccinv')?->invoke([1.0e-300, 2.0, 1.0]);
        self::assertIsFloat($gammaTailQuantile);
        self::assertEqualsWithDelta(
            log(1.0e-300),
            $registry->find('gammalogccdf')?->invoke([$gammaTailQuantile, 2.0, 1.0]),
            1.0e-10,
        );
        $fTailQuantile = $registry->find('fccinv')?->invoke([1.0e-100, 5.0, 10.0]);
        self::assertIsFloat($fTailQuantile);
        self::assertEqualsWithDelta(
            1.0e-100,
            $registry->find('fccdf')?->invoke([$fTailQuantile, 5.0, 10.0]),
            1.0e-110,
        );
        self::assertSame(1.0, $registry->find('betaccinv')?->invoke([0.0, 2.0, 3.0]));

        foreach (
            [
                ['fccinv', [0.0, 5.0, 10.0]],
                ['beta2ccinv', [0.0, 2.0, 3.0]],
                ['gammaccinv', [0.0, 2.0, 3.0]],
                ['chiccinv', [0.0, 4.0]],
            ] as [$name, $arguments]
        ) {
            try {
                $registry->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject zero survival probability because its quantile is infinite.');
        }
    }

    public function testDiscreteDistributionInverseSurvivalFunctionsUseStrictUpperTail(): void
    {
        $registry = FunctionRegistry::defaults();
        $cases = [
            ['binomccinv', [0.2, 10.0, 0.5], 'binomccdf', [10.0, 0.5], 'binomialccinv'],
            ['poiccinv', [0.2, 4.0], 'poiccdf', [4.0], 'poissonccinv'],
            ['geomccinv', [0.2, 0.25], 'geomccdf', [0.25], 'geometricccinv'],
            ['nbomccinv', [0.2, 3.0, 0.4], 'nbinomccdf', [3.0, 0.4], 'negativebinomialccinv'],
        ];

        foreach ($cases as [$inverseName, $arguments, $survivalName, $parameters, $longName]) {
            $inverse = $registry->find($inverseName);
            $survival = $registry->find($survivalName);
            self::assertInstanceOf(FunctionDefinition::class, $inverse);
            self::assertInstanceOf(FunctionDefinition::class, $survival);
            $quantile = $inverse->invoke($arguments);
            self::assertIsInt($quantile);
            self::assertLessThanOrEqual(
                $arguments[0],
                $survival->invoke(array_merge([$quantile], $parameters)),
                $inverseName . ' must return a point whose strict upper tail is at most q',
            );
            if ($quantile > 0) {
                self::assertGreaterThan(
                    $arguments[0],
                    $survival->invoke(array_merge([$quantile - 1], $parameters)),
                    $inverseName . ' must return the smallest point meeting the tail target',
                );
            }
            self::assertSame($quantile, $registry->find($longName)?->invoke($arguments));
        }

        self::assertSame(10, $registry->find('binomialccinv')?->invoke([0.0, 10.0, 0.5]));
        self::assertSame(0, $registry->find('poiccinv')?->invoke([1.0, 4.0]));
        self::assertSame(0, $registry->find('geometricccinv')?->invoke([1.0, 0.25]));
        self::assertSame(0, $registry->find('negativebinomialccinv')?->invoke([0.0, 3.0, 1.0]));

        foreach (
            [
                ['poiccinv', [0.0, 4.0]],
                ['geomccinv', [0.0, 0.25]],
                ['nbomccinv', [0.0, 3.0, 0.4]],
            ] as [$name, $arguments]
        ) {
            try {
                $registry->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject zero survival probability for an unbounded distribution.');
        }
    }

    public function testFiniteSupportDistributionInverseSurvivalFunctions(): void
    {
        $registry = FunctionRegistry::defaults();
        $cases = [
            ['discreteuniformccinv', [0.2, -3.0, 9.0], 'discreteuniformccdf', [-3.0, 9.0], 'discreteuniformccinv'],
            ['betabinomialccinv', [0.2, 10.0, 2.0, 3.0], 'betabinomialccdf', [10.0, 2.0, 3.0], 'betabinomialccinv'],
            [
                'hypergeomccinv',
                [0.2, 20.0, 15.0, 10.0],
                'hypergeometricccdf',
                [20.0, 15.0, 10.0],
                'hypergeometricccinv',
            ],
            [
                'neghypergeomccinv',
                [0.2, 20.0, 10.0, 4.0],
                'neghypergeomccdf',
                [20.0, 10.0, 4.0],
                'negativehypergeometricccinv',
            ],
        ];

        foreach ($cases as [$inverseName, $arguments, $survivalName, $parameters, $canonicalName]) {
            $inverse = $registry->find($inverseName);
            $survival = $registry->find($survivalName);
            self::assertInstanceOf(FunctionDefinition::class, $inverse);
            self::assertInstanceOf(FunctionDefinition::class, $survival);
            $quantile = $inverse->invoke($arguments);
            self::assertIsInt($quantile);
            self::assertLessThanOrEqual(
                $arguments[0],
                $survival->invoke(array_merge([$quantile], $parameters)),
                $inverseName,
            );
            $minimum = match ($inverseName) {
                'hypergeomccinv' => 5,
                'discreteuniformccinv' => -3,
                default => 0,
            };
            if ($quantile > $minimum) {
                self::assertGreaterThan(
                    $arguments[0],
                    $survival->invoke(array_merge([$quantile - 1], $parameters)),
                    $inverseName . ' must return the smallest value meeting the tail target',
                );
            }
            if ($inverseName !== $canonicalName) {
                self::assertSame($quantile, $registry->find($canonicalName)?->invoke($arguments));
            }
            self::assertSame(
                $inverseName === 'discreteuniformccinv' ? -3 : ($inverseName === 'hypergeomccinv' ? 5 : 0),
                $inverse->invoke(array_merge([1.0], array_slice($arguments, 1))),
            );
            $maximum = match ($inverseName) {
                'discreteuniformccinv' => 9,
                'betabinomialccinv' => 10,
                'hypergeomccinv' => 10,
                default => 10,
            };
            self::assertSame($maximum, $inverse->invoke(array_merge([0.0], array_slice($arguments, 1))));
        }
    }

    public function testZipfBenfordZetaAndDiscreteLaplaceInverseSurvivalFunctions(): void
    {
        $registry = FunctionRegistry::defaults();
        $cases = [
            ['zipfccinv', [0.2, 1.5, 100.0], 'zipfccdf', [1.5, 100.0], 'zipfccinv'],
            ['benfordccinv', [0.2, 10.0], 'benfordccdf', [10.0], 'benfordccinv'],
            ['zetaccinv', [0.2, 2.0], 'zetaccdf', [2.0], 'zetaccinv'],
        ];

        foreach ($cases as [$inverseName, $arguments, $survivalName, $parameters, $canonicalName]) {
            $inverse = $registry->find($inverseName);
            $survival = $registry->find($survivalName);
            self::assertInstanceOf(FunctionDefinition::class, $inverse);
            self::assertInstanceOf(FunctionDefinition::class, $survival);
            $quantile = $inverse->invoke($arguments);
            self::assertIsInt($quantile);
            self::assertLessThanOrEqual(
                $arguments[0],
                $survival->invoke(array_merge([$quantile], $parameters)),
                $inverseName,
            );
            if ($quantile > 1) {
                self::assertGreaterThan(
                    $arguments[0],
                    $survival->invoke(array_merge([$quantile - 1], $parameters)),
                    $inverseName . ' must return the smallest value meeting the tail target',
                );
            }
            if ($inverseName !== $canonicalName) {
                self::assertSame($quantile, $registry->find($canonicalName)?->invoke($arguments));
            }
        }

        foreach (
            [
                ['discretelaplaceccinv', 'discretelaplaceccdf'],
                ['doublegeometricccinv', 'doublegeometricccdf'],
            ] as [$inverseName, $survivalName]
        ) {
            foreach ([0.1, 1.0 / 3.0, 0.5, 2.0 / 3.0, 0.9] as $target) {
                $quantile = $registry->find($inverseName)?->invoke([$target, 0.5]);
                self::assertIsInt($quantile);
                self::assertLessThanOrEqual(
                    $target,
                    $registry->find($survivalName)?->invoke([$quantile, 0.5]),
                    $inverseName,
                );
                self::assertGreaterThan(
                    $target,
                    $registry->find($survivalName)?->invoke([$quantile - 1, 0.5]),
                    $inverseName . ' must return the smallest integer meeting the tail target',
                );
            }
        }

        self::assertSame(1, $registry->find('zipfccinv')?->invoke([1.0, 1.5, 10.0]));
        self::assertSame(10, $registry->find('zipfccinv')?->invoke([0.0, 1.5, 10.0]));
        self::assertSame(1, $registry->find('benfordccinv')?->invoke([1.0, 10.0]));
        self::assertSame(9, $registry->find('benfordccinv')?->invoke([0.0, 10.0]));
        self::assertSame(1, $registry->find('zetaccinv')?->invoke([1.0, 2.0]));
        self::assertSame(0, $registry->find('discretelaplaceccinv')?->invoke([0.0, 0.0]));

        foreach (
            [
                ['zetaccinv', [0.0, 2.0]],
                ['discretelaplaceccinv', [0.0, 0.5]],
                ['discretelaplaceccinv', [1.0, 0.5]],
            ] as [$name, $arguments]
        ) {
            try {
                $registry->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject an infinite-tail endpoint.');
        }
    }

    public function testLogisticDistributionFunctionsAreStableAcrossBothTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('logisticpdf');
        $cdf = $registry->find('logisticcdf');
        $survival = $registry->find('logisticccdf');
        $logCdf = $registry->find('logisticlogcdf');
        $logSurvival = $registry->find('logisticlogccdf');
        $inverse = $registry->find('logisticinv');
        $inverseDerivative = $registry->find('logisticinvprime');
        $survivalInverseDerivative = $registry->find('logisticccinvprime');
        $mean = $registry->find('logisticmean');
        $median = $registry->find('logisticmedian');
        $mode = $registry->find('logisticmode');
        $entropy = $registry->find('logisticentropy');
        $hazard = $registry->find('logistichazard');
        $cumulativeHazard = $registry->find('logisticcumhazard');
        $variance = $registry->find('logisticvariance');
        $standardDeviation = $registry->find('logisticstddev');
        $skewness = $registry->find('logisticskewness');
        $excessKurtosis = $registry->find('logisticexcesskurtosis');
        $kurtosis = $registry->find('logistickurtosis');
        $mgf = $registry->find('logisticmgf');
        $logMgf = $registry->find('logisticlogmgf');
        $moment = $registry->find('logisticmoment');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $moment);

        self::assertSame(0.25, $pdf->invoke([0, 0, 1]));
        self::assertSame(0.5, $cdf->invoke([0, 0, 1]));
        self::assertSame(0.5, $survival->invoke([0, 0, 1]));
        self::assertEqualsWithDelta(-log(2.0), $logCdf->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-log(2.0), $logSurvival->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.5, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-4.0, $survivalInverseDerivative->invoke([0.5, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-1000.0, $logCdf->invoke([-1000, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-1000.0, $logSurvival->invoke([1000, 0, 1]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([-1000, 0, 1]));
        self::assertGreaterThan(0.0, $survival->invoke([40, 0, 1]));
        self::assertGreaterThan(0.0, $cdf->invoke([-40, 0, 1]));
        self::assertSame(0.0, $survival->invoke([1000, 0, 1]));
        self::assertEqualsWithDelta(log(1.0 / 3.0), $inverse->invoke([0.25, 0, 1]), 1.0e-14);
        self::assertSame(2.0, $mean->invoke([2.0, 1.0]));
        self::assertSame(2.0, $median->invoke([2.0, 1.0]));
        self::assertSame(2.0, $mode->invoke([2.0, 1.0]));
        self::assertEqualsWithDelta(2.0, $entropy->invoke([0.0, 1.0]), 1.0e-14);
        self::assertSame(0.5, $hazard->invoke([0.0, 0.0, 1.0]));
        self::assertEqualsWithDelta(log(2.0), $cumulativeHazard->invoke([0.0, 0.0, 1.0]), 1.0e-14);
        self::assertSame(0.0, $hazard->invoke([-1000.0, 0.0, 1.0]));
        self::assertSame(1000.0, $cumulativeHazard->invoke([1000.0, 0.0, 1.0]));
        self::assertEqualsWithDelta(
            $pdf->invoke([2.0, 0.0, 1.0]) / $survival->invoke([2.0, 0.0, 1.0]),
            $hazard->invoke([2.0, 0.0, 1.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(4.0 * M_PI * M_PI / 3.0, $variance->invoke([2.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * M_PI / sqrt(3.0), $standardDeviation->invoke([2.0, 2.0]), 1.0e-14);
        self::assertSame(0.0, $skewness->invoke([0.0, 1.0]));
        self::assertSame(1.2, $excessKurtosis->invoke([0.0, 1.0]));
        self::assertSame(4.2, $kurtosis->invoke([0.0, 1.0]));
        self::assertSame(1.0, $moment->invoke([0, 0, 1]));
        self::assertSame(0.0, $moment->invoke([1, 0, 1]));
        self::assertEqualsWithDelta(M_PI * M_PI / 3.0, $moment->invoke([2, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(7.0 * M_PI ** 4 / 15.0, $moment->invoke([4, 0, 1]), 1.0e-13);
        self::assertEqualsWithDelta(4.0 + M_PI * M_PI / 3.0, $moment->invoke([2, 2, 1]), 1.0e-14);
        $rawMomentAlias = $registry->find('logisticrawmoment');
        self::assertInstanceOf(FunctionDefinition::class, $rawMomentAlias);
        self::assertSame($moment->invoke([2, 0, 1]), $rawMomentAlias->invoke([2, 0, 1]));
        self::assertSame(1.0, $mgf->invoke([0.0, 0.0, 1.0]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 0.0, 1.0]));
        self::assertEqualsWithDelta(M_PI / 2.0, $mgf->invoke([0.5, 0.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(log(M_PI / 2.0), $logMgf->invoke([0.5, 0.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(exp(1.0) * M_PI / 2.0, $mgf->invoke([0.5, 2.0, 1.0]), 1.0e-14);
        $nearBoundaryTilt = 1.0 - 1.0e-12;
        $expectedNearBoundaryLogMgf = log(M_PI) + log($nearBoundaryTilt)
            - log(sin(M_PI * (1.0 - $nearBoundaryTilt)));
        self::assertEqualsWithDelta(
            $expectedNearBoundaryLogMgf,
            $logMgf->invoke([$nearBoundaryTilt, 0.0, 1.0]),
            1.0e-12,
        );
        self::assertSame($variance->invoke([0.0, 1.0]), $registry->find('logisticvar')?->invoke([0.0, 1.0]));

        foreach ([1.0, -1.0, 2.0] as $invalidTilt) {
            try {
                $logMgf->invoke([$invalidTilt, 0.0, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('logisticlogmgf should reject tilts at or beyond either MGF boundary.');
        }

        foreach ([1.0e-12, 0.01, 0.1, 0.25, 0.5, 0.75, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[-5.0, 0.1], [0.0, 1.0], [12.0, 25.0]] as [$location, $scale]) {
                $quantile = $inverse->invoke([$probability, $location, $scale]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $location, $scale]),
                    1.0e-13,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $location, $scale])
                        + $survival->invoke([$quantile, $location, $scale]),
                    1.0e-13,
                );
                self::assertGreaterThanOrEqual(0.0, $pdf->invoke([$quantile, $location, $scale]));
            }
        }

        foreach ([[0, 0, 0], [0, 1, -1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Logistic functions should reject non-positive scales.');
        }
        foreach ([0.0, 1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('logisticinv should require probabilities strictly between 0 and 1.');
        }
        foreach ([-1, 33, 1.5] as $order) {
            try {
                $moment->invoke([$order, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('logisticmoment should reject orders outside [0, 32].');
        }
    }

    public function testLogLogisticDistributionFunctionsHandleSupportTailsAndQuantiles(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('loglogisticpdf');
        $logPdf = $registry->find('loglogisticlogpdf');
        $cdf = $registry->find('loglogisticcdf');
        $survival = $registry->find('loglogisticccdf');
        $logCdf = $registry->find('loglogisticlogcdf');
        $logSurvival = $registry->find('loglogisticlogccdf');
        $inverse = $registry->find('loglogisticinv');
        $moment = $registry->find('loglogisticmoment');
        $mean = $registry->find('loglogisticmean');
        $median = $registry->find('loglogisticmedian');
        $mode = $registry->find('loglogisticmode');
        $variance = $registry->find('loglogisticvariance');
        $standardDeviation = $registry->find('loglogisticstddev');
        $entropy = $registry->find('loglogisticentropy');
        $hazard = $registry->find('loglogistichazard');
        $cumulativeHazard = $registry->find('loglogisticcumhazard');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);

        self::assertSame(0.0, $pdf->invoke([-1, 2, 3]));
        self::assertSame(0.0, $cdf->invoke([-1, 2, 3]));
        self::assertSame(1.0, $survival->invoke([-1, 2, 3]));
        self::assertSame(-INF, $logCdf->invoke([0, 2, 3]));
        self::assertSame(0.0, $logSurvival->invoke([0, 2, 3]));
        self::assertSame(0.0, $pdf->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(1.0 / 3.0, $pdf->invoke([0, 1, 3]), 1.0e-15);
        self::assertSame(0.0, $cdf->invoke([0, 2, 3]));
        self::assertSame(1.0, $survival->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(1.0 / 6.0, $pdf->invoke([3, 2, 3]), 1.0e-15);
        self::assertEqualsWithDelta(log(1.0 / 6.0), $logPdf->invoke([3, 2, 3]), 1.0e-14);
        self::assertSame(0.0, $pdf->invoke([1.0e300, 2, 3]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e300, 2, 3]));
        self::assertSame(INF, $logPdf->invoke([0, 0.5, 3]));
        self::assertSame(0.5, $cdf->invoke([3, 2, 3]));
        self::assertSame(0.5, $survival->invoke([3, 2, 3]));
        self::assertEqualsWithDelta(log(0.5), $logCdf->invoke([3, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.5), $logSurvival->invoke([3, 2, 3]), 1.0e-14);
        self::assertLessThan(-700.0, $logCdf->invoke([1.0e-308, 2, 3]));
        self::assertLessThan(-700.0, $logSurvival->invoke([1.0e300, 2, 3]));
        self::assertSame(0.0, $inverse->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(3.0, $inverse->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertSame(1.0, $moment->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(3.0 * M_PI / 2.0, $moment->invoke([1, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * M_PI, $moment->invoke([2, 4, 2]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 * M_PI / 2.0, $mean->invoke([2.0, 3.0]), 1.0e-14);
        self::assertSame(3.0, $median->invoke([2.0, 3.0]));
        self::assertEqualsWithDelta(sqrt(3.0), $mode->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * M_PI - M_PI * M_PI / 2.0, $variance->invoke([4.0, 2.0]), 1.0e-13);
        self::assertEqualsWithDelta(
            sqrt(2.0 * M_PI - M_PI * M_PI / 2.0),
            $standardDeviation->invoke([4.0, 2.0]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(2.0 + log(3.0 / 2.0), $entropy->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0, $hazard->invoke([3.0, 2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0), $cumulativeHazard->invoke([3.0, 2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($survival->invoke([3.0, 2.0, 3.0])),
            $cumulativeHazard->invoke([3.0, 2.0, 3.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([3.0, 2.0, 3.0]) / $survival->invoke([3.0, 2.0, 3.0]),
            $hazard->invoke([3.0, 2.0, 3.0]),
            1.0e-14,
        );
        self::assertSame($moment->invoke([1, 2, 3]), $registry->find('fiskmoment')?->invoke([1, 2, 3]));
        self::assertSame($mean->invoke([2.0, 3.0]), $registry->find('fiskmean')?->invoke([2.0, 3.0]));
        self::assertSame($variance->invoke([4.0, 2.0]), $registry->find('fiskvar')?->invoke([4.0, 2.0]));
        self::assertSame($hazard->invoke([3.0, 2.0, 3.0]), $registry->find('fiskhazard')?->invoke([3.0, 2.0, 3.0]));
        $nearMomentBoundaryShape = 1.0 + 1.0e-12;
        $expectedNearBoundaryMoment = M_PI
            / sin(M_PI * (($nearMomentBoundaryShape - 1.0) / $nearMomentBoundaryShape));
        self::assertEqualsWithDelta(
            $expectedNearBoundaryMoment,
            $moment->invoke([1, $nearMomentBoundaryShape, 1]),
            $expectedNearBoundaryMoment * 2.0e-12,
        );
        self::assertGreaterThan(0.0, $survival->invoke([1.0e100, 2, 3]));
        self::assertSame(1.0, $cdf->invoke([1.0e100, 2, 3]));

        $fiskInverse = $registry->find('fiskinv');
        self::assertInstanceOf(FunctionDefinition::class, $fiskInverse);
        self::assertSame($inverse->invoke([0.75, 2, 3]), $fiskInverse->invoke([0.75, 2, 3]));

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[0.1, 1.0e-4], [1.0, 1.0], [2.0, 3.0], [50.0, 1.0e6]] as [$shape, $scale]) {
                $quantile = $inverse->invoke([$probability, $shape, $scale]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $shape, $scale]),
                    5.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $shape, $scale])
                        + $survival->invoke([$quantile, $shape, $scale]),
                    2.0e-15,
                );
            }
        }

        foreach ([[1, 0, 1], [1, 2, 0], [1, -1, 2]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Log-logistic functions should reject non-positive shape or scale.');
        }
        foreach ([1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('loglogisticinv should require a probability in [0, 1).');
        }
        foreach ([[2, 2, 1], [1, 1, 1], [1.5, 3, 1], [65, 100, 1]] as $invalidMoment) {
            try {
                $moment->invoke($invalidMoment);
            } catch (\DomainException) {
                continue;
            }
            self::fail('loglogisticmoment should reject divergent or non-integer raw moments.');
        }
        $divergentMoments = [
            ['loglogisticmean', [1.0, 1.0]],
            ['loglogisticvariance', [2.0, 2.0]],
            ['loglogisticstddev', [2.0, 2.0]],
        ];
        foreach ($divergentMoments as [$name, $parameters]) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($parameters);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject divergent moments.');
        }
        foreach ([$hazard, $cumulativeHazard] as $function) {
            try {
                $function->invoke([-1.0, 2.0, 3.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($function->name . ' should reject values below the support.');
        }
        try {
            $pdf->invoke([0, 0.5, 1]);
        } catch (\DomainException) {
            return;
        }
        self::fail('loglogisticpdf should reject the singular density at zero when shape is below 1.');
    }

    public function testLaplaceDistributionFunctionsRespectSymmetricTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('laplacepdf');
        $logPdf = $registry->find('laplacelogpdf');
        $cdf = $registry->find('laplacecdf');
        $survival = $registry->find('laplaceccdf');
        $logCdf = $registry->find('laplacelogcdf');
        $logSurvival = $registry->find('laplacelogccdf');
        $inverse = $registry->find('laplaceinv');
        $mean = $registry->find('laplacemean');
        $variance = $registry->find('laplacevariance');
        $stddev = $registry->find('laplacestddev');
        $median = $registry->find('laplacemedian');
        $mode = $registry->find('laplacemode');
        $entropy = $registry->find('laplaceentropy');
        $moment = $registry->find('laplacemoment');
        $mgf = $registry->find('laplacemgf');
        $logMgf = $registry->find('laplacelogmgf');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $stddev);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);

        self::assertSame(0.5, $pdf->invoke([0, 0, 1]));
        self::assertEqualsWithDelta(-log(2.0), $logPdf->invoke([0, 0, 1]), 1.0e-15);
        self::assertSame(0.5, $cdf->invoke([0, 0, 1]));
        self::assertSame(0.5, $survival->invoke([0, 0, 1]));
        self::assertEqualsWithDelta(-log(2.0), $logCdf->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-log(2.0), $logSurvival->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5 * exp(-2.0), $pdf->invoke([-2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-2.0 - log(2.0), $logPdf->invoke([-2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(
            -1.0e200 - log(2.0),
            $logPdf->invoke([1.0e200, 0, 1]),
            1.0e185,
        );
        self::assertEqualsWithDelta(
            -2.0 - log(2.0) - log(PHP_FLOAT_MAX),
            $logPdf->invoke([PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_MAX]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            0.5 / PHP_FLOAT_MAX,
            $pdf->invoke([0, 0, PHP_FLOAT_MAX]),
            1.0e-320,
        );
        self::assertGreaterThan(0.0, $pdf->invoke([0, 0, PHP_FLOAT_MAX]));
        self::assertEqualsWithDelta(0.5 * exp(-2.0), $cdf->invoke([-2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 - 0.5 * exp(-2.0), $cdf->invoke([2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5 * exp(-2.0), $survival->invoke([2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-2.0 - log(2.0), $logCdf->invoke([-2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-2.0 - log(2.0), $logSurvival->invoke([2, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(-1000.0 - log(2.0), $logCdf->invoke([-1000, 0, 1]), 1.0e-12);
        self::assertEqualsWithDelta(-1000.0 - log(2.0), $logSurvival->invoke([1000, 0, 1]), 1.0e-12);
        self::assertGreaterThan(0.0, $survival->invoke([40, 0, 1]));
        self::assertSame(0.0, $cdf->invoke([-1000, 0, 1]));
        self::assertSame(7.0, $inverse->invoke([0.5, 7, 3]));
        self::assertSame(7.0, $mean->invoke([7, 3]));
        self::assertSame(7.0, $median->invoke([7, 3]));
        self::assertSame(7.0, $mode->invoke([7, 3]));
        self::assertSame(18.0, $variance->invoke([7, 3]));
        self::assertEqualsWithDelta(3.0 * sqrt(2.0), $stddev->invoke([7, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 + log(6.0), $entropy->invoke([7, 3]), 1.0e-14);
        self::assertSame(1.0, $moment->invoke([0, 7, 3]));
        self::assertSame(7.0, $moment->invoke([1, 7, 3]));
        self::assertEqualsWithDelta(67.0, $moment->invoke([2, 7, 3]), 1.0e-12);
        self::assertEqualsWithDelta(24.0, $moment->invoke([4, 0, 1]), 1.0e-12);
        self::assertEqualsWithDelta(-log(0.75), $logMgf->invoke([0.5, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(4.0 / 3.0, $mgf->invoke([0.5, 0, 1]), 1.0e-14);
        self::assertSame(0.0, $registry->find('laplaceskewness')?->invoke([0, 1]));
        self::assertSame(3.0, $registry->find('laplaceexcesskurtosis')?->invoke([0, 1]));
        self::assertSame(6.0, $registry->find('laplacekurtosis')?->invoke([0, 1]));

        foreach ([1.0e-12, 0.01, 0.1, 0.25, 0.49, 0.5, 0.51, 0.75, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[-10.0, 0.01], [0.0, 1.0], [100.0, 25.0]] as [$location, $scale]) {
                $quantile = $inverse->invoke([$probability, $location, $scale]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $location, $scale]),
                    1.0e-13,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $location, $scale])
                        + $survival->invoke([$quantile, $location, $scale]),
                    3.0e-15,
                );
                self::assertEqualsWithDelta(
                    $pdf->invoke([$quantile, $location, $scale]),
                    $pdf->invoke([2 * $location - $quantile, $location, $scale]),
                    2.0e-15,
                );
            }
        }
        foreach ([$pdf, $logPdf] as $density) {
            foreach ([[0, 0, 0], [0, 0, -1]] as $invalid) {
                try {
                    $density->invoke($invalid);
                } catch (\DomainException) {
                    continue;
                }
                self::fail('Laplace functions should reject non-positive scales.');
            }
        }
        foreach ([0.0, 1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('laplaceinv should require probabilities strictly between 0 and 1.');
        }
        foreach ([-1.0, 1.0] as $tilt) {
            try {
                $logMgf->invoke([$tilt, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('laplacelogmgf should reject tilts outside its open domain.');
        }
    }

    public function testAsymmetricLaplaceDistributionAndSymmetricLimit(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('asymmetriclaplacepdf');
        $logPdf = $registry->find('asymmetriclaplacelogpdf');
        $cdf = $registry->find('asymmetriclaplacecdf');
        $survival = $registry->find('asymmetriclaplaceccdf');
        $logCdf = $registry->find('asymmetriclaplacelogcdf');
        $logSurvival = $registry->find('asymmetriclaplacelogccdf');
        $inverse = $registry->find('asymmetriclaplaceinv');
        $mean = $registry->find('asymmetriclaplacemean');
        $variance = $registry->find('asymmetriclaplacevariance');
        $stddev = $registry->find('asymmetriclaplacestddev');
        $median = $registry->find('asymmetriclaplacemedian');
        $mode = $registry->find('asymmetriclaplacemode');
        $entropy = $registry->find('asymmetriclaplaceentropy');
        $moment = $registry->find('asymmetriclaplacemoment');
        $mgf = $registry->find('asymmetriclaplacemgf');
        $logMgf = $registry->find('asymmetriclaplacelogmgf');
        $laplacePdf = $registry->find('laplacepdf');
        $laplaceCdf = $registry->find('laplacecdf');
        $laplaceInverse = $registry->find('laplaceinv');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $stddev);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $laplacePdf);
        self::assertInstanceOf(FunctionDefinition::class, $laplaceCdf);
        self::assertInstanceOf(FunctionDefinition::class, $laplaceInverse);

        self::assertEqualsWithDelta(1.0 / 3.0, $pdf->invoke([0, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logPdf->invoke([0, 0, 2, 1]), 1.0e-14);
        self::assertSame(0.0, $pdf->invoke([1.0e300, 0, 2, 1]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e300, 0, 2, 1]));
        self::assertEqualsWithDelta(2.0 / 3.0, $cdf->invoke([0, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 / 3.0 * exp(-1.0), $cdf->invoke([-2, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0 * exp(-1.0), $survival->invoke([1, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0 / 3.0), $logCdf->invoke([0, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0), $logSurvival->invoke([0, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0 / 3.0) - 1.0, $logCdf->invoke([-2, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.0 / 3.0) - 3.0, $logSurvival->invoke([3, 0, 2, 1]), 1.0e-14);
        self::assertLessThan(-900.0, $logCdf->invoke([-2000, 0, 2, 1]));
        self::assertLessThan(-900.0, $logSurvival->invoke([2000, 0, 2, 1]));
        self::assertEqualsWithDelta(2.0 * log(0.75), $inverse->invoke([0.5, 0, 2, 1]), 1.0e-14);
        self::assertSame(-1.0, $mean->invoke([0, 2, 1]));
        self::assertSame(5.0, $variance->invoke([0, 2, 1]));
        self::assertEqualsWithDelta(sqrt(5.0), $stddev->invoke([0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * log(0.75), $median->invoke([0, 2, 1]), 1.0e-14);
        self::assertSame(0.0, $mode->invoke([0, 2, 1]));
        self::assertEqualsWithDelta(1.0 + log(3.0), $entropy->invoke([0, 2, 1]), 1.0e-14);
        self::assertSame(1.0, $moment->invoke([0, 0, 2, 1]));
        self::assertSame(-1.0, $moment->invoke([1, 0, 2, 1]));
        self::assertEqualsWithDelta(6.0, $moment->invoke([2, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-30.0, $moment->invoke([3, 0, 2, 1]), 1.0e-13);
        self::assertEqualsWithDelta(1.0 / 1.08, $mgf->invoke([0.1, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(1.08), $logMgf->invoke([0.1, 0, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(
            $registry->find('laplacelogmgf')?->invoke([0.2, 0, 1]),
            $logMgf->invoke([0.2, 0, 1, 1]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            3.0,
            $registry->find('asymmetriclaplaceexcesskurtosis')?->invoke([0, 1, 1]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(6.0, $registry->find('asymmetriclaplacekurtosis')?->invoke([0, 1, 1]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([-1.0e200, 1.0e200, 2, 1]));
        self::assertSame(1.0, $survival->invoke([-1.0e200, 1.0e200, 2, 1]));

        foreach ([-5.0, -1.0, 0.0, 1.0, 5.0] as $value) {
            self::assertEqualsWithDelta(
                $laplacePdf->invoke([$value, 1, 2]),
                $pdf->invoke([$value, 1, 2, 2]),
                2.0e-14,
            );
            self::assertEqualsWithDelta(
                $laplaceCdf->invoke([$value, 1, 2]),
                $cdf->invoke([$value, 1, 2, 2]),
                2.0e-14,
            );
        }

        foreach ([0.001, 0.1, 0.25, 0.5, 2.0 / 3.0, 0.75, 0.9, 0.999] as $probability) {
            foreach ([[0.25, 2.0], [2.0, 0.25], [1.0, 1.0]] as [$leftScale, $rightScale]) {
                $quantile = $inverse->invoke([$probability, -3, $leftScale, $rightScale]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, -3, $leftScale, $rightScale]),
                    2.0e-13,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, -3, $leftScale, $rightScale])
                        + $survival->invoke([$quantile, -3, $leftScale, $rightScale]),
                    3.0e-15,
                );
            }
        }
        foreach ([0.1, 0.25, 0.5, 0.75, 0.9] as $probability) {
            self::assertEqualsWithDelta(
                $laplaceInverse->invoke([$probability, 1, 2]),
                $inverse->invoke([$probability, 1, 2, 2]),
                2.0e-14,
            );
        }
        self::assertEqualsWithDelta(
            $registry->find('laplacemean')?->invoke([1, 2]),
            $mean->invoke([1, 2, 2]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $registry->find('laplacevariance')?->invoke([1, 2]),
            $variance->invoke([1, 2, 2]),
            1.0e-14,
        );
        foreach ([-0.5, 1.0] as $tilt) {
            try {
                $logMgf->invoke([$tilt, 0, 2, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Asymmetric Laplace log-MGF should reject the boundary of its domain.');
        }

        foreach (
            [
                ['asymmetriclaplacepdf', [0, 0, 0, 1]],
                ['asymmetriclaplacecdf', [0, INF, 1, 1]],
                ['asymmetriclaplaceccdf', [0, 0, 1, -1]],
                ['asymmetriclaplaceinv', [0, 0, 1, 1]],
                ['asymmetriclaplaceinv', [1, 0, 1, 1]],
            ] as [$name, $arguments]
        ) {
            $definition = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid scales and unbounded endpoint quantiles.');
        }
    }

    public function testFDistributionFunctionsMatchClosedFormsAndInvert(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('fdistributionpdf');
        $logPdf = $registry->find('fdistributionlogpdf');
        $cdf = $registry->find('fdistributioncdf');
        $survival = $registry->find('fdistributionccdf');
        $logCdf = $registry->find('fdistributionlogcdf');
        $logSurvival = $registry->find('fdistributionlogccdf');
        $hazard = $registry->find('fdistributionhazard');
        $cumulativeHazard = $registry->find('fdistributioncumhazard');
        $inverse = $registry->find('fdistributioninv');
        $inverseDerivative = $registry->find('fdistributioninvprime');
        $survivalInverseDerivative = $registry->find('fdistributionccinvprime');
        $mean = $registry->find('fdistributionmean');
        $median = $registry->find('fdistributionmedian');
        $mode = $registry->find('fdistributionmode');
        $entropy = $registry->find('fdistributionentropy');
        $variance = $registry->find('fdistributionvariance');
        $skewness = $registry->find('fdistributionskewness');
        $excessKurtosis = $registry->find('fdistributionexcesskurtosis');
        $kurtosis = $registry->find('fdistributionkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertEqualsWithDelta(0.25, $pdf->invoke([1, 2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $cdf->invoke([1, 2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(0.5, $survival->invoke([1, 2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(log(0.5), $logCdf->invoke([1, 2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(log(0.5), $logSurvival->invoke([1, 2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(0.5, $hazard->invoke([1, 2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(log(2.0), $cumulativeHazard->invoke([1, 2, 2]), 1.0e-13);
        self::assertSame(1.0, $hazard->invoke([0, 2, 5]));
        self::assertSame(0.0, $hazard->invoke([0, 4, 5]));
        self::assertSame(0.0, $hazard->invoke([-1, 2, 5]));
        self::assertSame(0.0, $cumulativeHazard->invoke([-1, 2, 5]));
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.5, 2, 2]), 1.0e-12);
        self::assertEqualsWithDelta(-4.0, $survivalInverseDerivative->invoke([0.5, 2, 2]), 1.0e-12);
        self::assertEqualsWithDelta(0.5, $cdf->invoke([1, 1, 1]), 1.0e-13);
        self::assertSame(0.0, $cdf->invoke([0, 4, 7]));
        self::assertSame(1.0, $survival->invoke([0, 4, 7]));
        self::assertSame(0.0, $logSurvival->invoke([0, 4, 7]));
        self::assertSame(0.0, $pdf->invoke([-1, 4, 7]));
        self::assertSame(1.0, $pdf->invoke([0, 2, 5]));
        self::assertSame(0.0, $pdf->invoke([0, 4, 5]));
        self::assertEqualsWithDelta(log(0.25), $logPdf->invoke([1, 2, 2]), 1.0e-14);
        self::assertSame(0.0, $logPdf->invoke([0, 2, 5]));
        self::assertSame(0.0, $pdf->invoke([-1, 2, 5]));
        self::assertSame(0.0, $pdf->invoke([1.0e100, 2, 5]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e100, 2, 5]));
        self::assertEqualsWithDelta(1.0, $inverse->invoke([0.5, 2, 2]), 1.0e-12);
        self::assertEqualsWithDelta(1.0, $median->invoke([2, 2]), 1.0e-12);
        self::assertSame(0.0, $mode->invoke([2, 10]));
        self::assertEqualsWithDelta(0.375, $mode->invoke([4, 6]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $entropy->invoke([2, 2]), 1.0e-13);
        self::assertEqualsWithDelta(1.25, $mean->invoke([5, 10]), 1.0e-14);
        self::assertEqualsWithDelta(65.0 / 48.0, $variance->invoke([5, 10]), 1.0e-14);
        $expectedSkewness = (2.0 * 5.0 + 10.0 - 2.0) * sqrt(8.0 * (10.0 - 4.0))
            / ((10.0 - 6.0) * sqrt(5.0 * (5.0 + 10.0 - 2.0)));
        self::assertEqualsWithDelta($expectedSkewness, $skewness->invoke([5, 10]), 1.0e-14);
        $expectedExcess = 12.0 * (
            5.0 * (5.0 * 10.0 - 22.0) * (5.0 + 10.0 - 2.0)
            + (10.0 - 4.0) * (10.0 - 2.0) ** 2
        ) / (5.0 * (10.0 - 6.0) * (10.0 - 8.0) * (5.0 + 10.0 - 2.0));
        self::assertEqualsWithDelta($expectedExcess, $excessKurtosis->invoke([5, 10]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 + $expectedExcess, $kurtosis->invoke([5, 10]), 1.0e-14);
        self::assertSame($mean->invoke([5, 10]), $registry->find('fmean')?->invoke([5, 10]));
        self::assertSame($variance->invoke([5, 10]), $registry->find('fvar')?->invoke([5, 10]));

        foreach (
            [
                ['fdistributionmean', $mean, [5.0, 2.0]],
                ['fdistributionmode', $mode, [-1.0, 5.0]],
                ['fdistributionvariance', $variance, [5.0, 4.0]],
                ['fdistributionskewness', $skewness, [5.0, 6.0]],
                ['fdistributionexcesskurtosis', $excessKurtosis, [5.0, 8.0]],
            ] as [$name, $function, $arguments]
        ) {
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject degrees of freedom where the moment does not exist.');
        }
        self::assertSame(0.0, $inverse->invoke([0, 5, 10]));
        self::assertGreaterThan(0.0, $survival->invoke([1.0e100, 2, 3]));
        self::assertEqualsWithDelta(
            log(1.0e-200),
            $logCdf->invoke([1.0e-200, 2, 3]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            1.5 * (log(1.5) - log(1.0e300)),
            $logSurvival->invoke([1.0e300, 2, 3]),
            1.0e-10,
        );

        try {
            $logCdf->invoke([0, 2, 2]);
        } catch (\DomainException) {
            // Log(0) is not finite, so this endpoint is reported explicitly.
        }

        foreach ([0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-10] as $probability) {
            foreach ([[1.0, 1.0], [1.0, 10.0], [5.0, 2.0], [10.0, 20.0], [100.0, 500.0]] as [$df1, $df2]) {
                $quantile = $inverse->invoke([$probability, $df1, $df2]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $df1, $df2]), 2.0e-9);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $df1, $df2]) + $survival->invoke([$quantile, $df1, $df2]),
                    2.0e-9,
                );
            }
        }
        foreach ([[1, 0, 2], [1, 2, 0], [1, 15000, 6000]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('F distribution should reject invalid degrees of freedom.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 2]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('fdistributioninv should require a probability in [0, 1).');
        }
        try {
            $pdf->invoke([0, 1, 2]);
        } catch (\DomainException) {
            return;
        }
        self::fail('F density should reject the singular endpoint when df1 < 2.');
    }

    public function testKumaraswamyDistributionHasStableTailsAndClosedFormQuantiles(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('kumaraswamypdf');
        $logPdf = $registry->find('kumaraswamylogpdf');
        $cdf = $registry->find('kumaraswamycdf');
        $survival = $registry->find('kumaraswamyccdf');
        $inverse = $registry->find('kumaraswamyinv');
        $betaPdf = $registry->find('betadistributionpdf');
        $betaCdf = $registry->find('betadistributioncdf');
        $betaSurvival = $registry->find('betadistributionccdf');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $betaPdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaCdf);
        self::assertInstanceOf(FunctionDefinition::class, $betaSurvival);

        self::assertEqualsWithDelta(1.6875, $pdf->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(1.6875), $logPdf->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertSame(-INF, $logPdf->invoke([-0.1, 2, 3]));
        self::assertSame(INF, $logPdf->invoke([0, 0.5, 2]));
        self::assertSame(-INF, $logPdf->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(0.578125, $cdf->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(0.421875, $survival->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $inverse->invoke([0.578125, 2, 3]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([0, 2, 3]));
        self::assertSame(1.0, $survival->invoke([0, 2, 3]));
        self::assertSame(1.0, $cdf->invoke([1, 2, 3]));
        self::assertSame(0.0, $survival->invoke([1, 2, 3]));
        self::assertSame(0.0, $inverse->invoke([0, 2, 3]));
        self::assertSame(1.0, $inverse->invoke([1, 2, 3]));
        self::assertSame(1.0, $pdf->invoke([0.4, 1, 1]));
        self::assertEqualsWithDelta(0.4, $cdf->invoke([0.4, 1, 1]), 1.0e-15);

        foreach ([[1.0, 2.0], [2.0, 1.0]] as [$alpha, $beta]) {
            foreach ([0.01, 0.1, 0.4, 0.9, 0.99] as $value) {
                self::assertEqualsWithDelta(
                    $betaPdf->invoke([$value, $alpha, $beta]),
                    $pdf->invoke([$value, $alpha, $beta]),
                    3.0e-13,
                );
                self::assertEqualsWithDelta(
                    $betaCdf->invoke([$value, $alpha, $beta]),
                    $cdf->invoke([$value, $alpha, $beta]),
                    3.0e-13,
                );
                self::assertEqualsWithDelta(
                    $betaSurvival->invoke([$value, $alpha, $beta]),
                    $survival->invoke([$value, $alpha, $beta]),
                    3.0e-13,
                );
            }
        }

        foreach ([0.25, 0.5, 1.0, 2.0, 8.0] as $alpha) {
            foreach ([0.25, 0.5, 1.0, 2.0, 8.0] as $beta) {
                foreach ([1.0e-8, 0.01, 0.25, 0.5, 0.75, 0.99] as $probability) {
                    $quantile = $inverse->invoke([$probability, $alpha, $beta]);
                    self::assertEqualsWithDelta(
                        $probability,
                        $cdf->invoke([$quantile, $alpha, $beta]),
                        2.0e-10,
                    );
                    self::assertEqualsWithDelta(
                        1.0,
                        $cdf->invoke([$quantile, $alpha, $beta])
                            + $survival->invoke([$quantile, $alpha, $beta]),
                        2.0e-14,
                    );
                }
            }
        }

        foreach ([[0, 0.5, 2], [1, 2, 0.5]] as $arguments) {
            try {
                $pdf->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Kumaraswamy density should reject singular support endpoints.');
        }
        foreach ([[0.5, 0, 2], [0.5, 2, INF], [1.1, 2, 3]] as [$value, $alpha, $beta]) {
            try {
                ($value > 1.0 ? $inverse : $pdf)->invoke([$value, $alpha, $beta]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Kumaraswamy functions should reject invalid shapes or probabilities.');
        }
    }

    public function testKumaraswamyMomentsAndLocationSummaries(): void
    {
        $registry = FunctionRegistry::defaults();
        $moment = $registry->find('kumaraswamymoment');
        $mean = $registry->find('kumaraswamymean');
        $variance = $registry->find('kumaraswamyvariance');
        $median = $registry->find('kumaraswamymedian');
        $mode = $registry->find('kumaraswamymode');
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);

        self::assertSame(1.0, $moment->invoke([0, 2, 1]));
        self::assertEqualsWithDelta(2.0 / 3.0, $mean->invoke([2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 18.0, $variance->invoke([2, 1]), 1.0e-14);
        self::assertSame($variance->invoke([2, 1]), $registry->find('kumaraswamyvar')?->invoke([2, 1]));
        self::assertEqualsWithDelta(sqrt(0.5), $median->invoke([2, 1]), 1.0e-14);
        self::assertSame(1.0, $mode->invoke([2, 1]));
        self::assertEqualsWithDelta(8.0 / 15.0, $moment->invoke([1, 2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 3.0, $moment->invoke([2, 2, 2]), 1.0e-14);
        self::assertEqualsWithDelta(
            0.0,
            $mode->invoke([1, 1]),
            0.0,
            'Uniform density uses zero as its representative mode.',
        );

        foreach ([[-1, 2, 1], [1.5, 2, 1], [65, 2, 1]] as $invalid) {
            try {
                $moment->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('kumaraswamymoment should reject negative, non-integer, or excessive orders.');
        }

        try {
            $mode->invoke([0.5, 0.5]);
        } catch (\DomainException) {
            return;
        }
        self::fail('kumaraswamymode should reject the non-unique U-shaped mode.');
    }

    public function testBetaDistributionFunctionsMatchUniformAndArcsineCases(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('betadistributionpdf');
        $cdf = $registry->find('betadistributioncdf');
        $survival = $registry->find('betadistributionccdf');
        $inverse = $registry->find('betadistributioninv');
        $inverseDerivative = $registry->find('betadistributioninvprime');
        $survivalInverseDerivative = $registry->find('betadistributionccinvprime');
        $mean = $registry->find('betadistributionmean');
        $variance = $registry->find('betadistributionvariance');
        $skewness = $registry->find('betadistributionskewness');
        $excessKurtosis = $registry->find('betadistributionexcesskurtosis');
        $kurtosis = $registry->find('betadistributionkurtosis');
        $median = $registry->find('betadistributionmedian');
        $mode = $registry->find('betadistributionmode');
        $entropy = $registry->find('betadistributionentropy');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);

        self::assertEqualsWithDelta(1.0, $pdf->invoke([0.5, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $cdf->invoke([0.5, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $survival->invoke([0.5, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $inverseDerivative->invoke([0.5, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-1.0, $survivalInverseDerivative->invoke([0.5, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 / M_PI, $pdf->invoke([0.5, 0.5, 0.5]), 1.0e-13);
        self::assertSame(0.0, $cdf->invoke([0, 2, 3]));
        self::assertSame(1.0, $cdf->invoke([1, 2, 3]));
        self::assertSame(1.0, $survival->invoke([0, 2, 3]));
        self::assertSame(0.0, $survival->invoke([1, 2, 3]));
        self::assertSame(0.0, $pdf->invoke([0, 2, 3]));
        self::assertSame(0.0, $pdf->invoke([1, 2, 3]));
        self::assertSame(0.0, $inverse->invoke([0, 2, 3]));
        self::assertSame(1.0, $inverse->invoke([1, 2, 3]));
        self::assertSame(0.5, $mean->invoke([1, 1]));
        self::assertSame(0.5, $median->invoke([1, 1]));
        self::assertSame(0.5, $mode->invoke([2, 2]));
        self::assertEqualsWithDelta(0.0, $entropy->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(M_PI / 4.0), $entropy->invoke([0.5, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / 12.0, $variance->invoke([1, 1]), 1.0e-14);
        self::assertSame(0.0, $skewness->invoke([1, 1]));
        self::assertEqualsWithDelta(-1.2, $excessKurtosis->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.8, $kurtosis->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 / 7.0, $mean->invoke([2, 5]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 196.0, $variance->invoke([2, 5]), 1.0e-14);
        self::assertEqualsWithDelta(0.2, $mode->invoke([2, 5]), 1.0e-14);
        $expectedSkewness = 2.0 * (5.0 - 2.0) * sqrt(8.0)
            / (9.0 * sqrt(2.0 * 5.0));
        self::assertEqualsWithDelta($expectedSkewness, $skewness->invoke([2, 5]), 1.0e-14);
        $expectedExcessKurtosis = 6.0 * ((2.0 - 5.0) ** 2 * 8.0 - 2.0 * 5.0 * 9.0)
            / (2.0 * 5.0 * 9.0 * 10.0);
        self::assertEqualsWithDelta($expectedExcessKurtosis, $excessKurtosis->invoke([2, 5]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 + $expectedExcessKurtosis, $kurtosis->invoke([2, 5]), 1.0e-14);
        self::assertSame($mean->invoke([2, 5]), $registry->find('betamean')?->invoke([2, 5]));
        self::assertSame($variance->invoke([2, 5]), $registry->find('betavar')?->invoke([2, 5]));
        self::assertSame($skewness->invoke([2, 5]), $registry->find('betaskewness')?->invoke([2, 5]));
        self::assertSame($median->invoke([2, 5]), $registry->find('betamedian')?->invoke([2, 5]));
        self::assertSame($mode->invoke([2, 5]), $registry->find('betamode')?->invoke([2, 5]));
        self::assertSame($entropy->invoke([2, 5]), $registry->find('betaentropy')?->invoke([2, 5]));

        foreach ([[0.5, 2.0], [1.0, 2.0], [2.0, 1.0]] as $undefinedModeParameters) {
            try {
                $mode->invoke($undefinedModeParameters);
            } catch (\DomainException) {
                continue;
            }
            self::fail('betadistributionmode should require both shape parameters to exceed one.');
        }

        foreach ([0.001, 0.01, 0.1, 0.5, 0.9, 0.99, 0.999] as $probability) {
            foreach ([[0.5, 0.5], [1.0, 1.0], [2.0, 5.0], [8.0, 2.0], [100.0, 200.0]] as [$alpha, $beta]) {
                $quantile = $inverse->invoke([$probability, $alpha, $beta]);
                self::assertGreaterThanOrEqual(0.0, $quantile);
                self::assertLessThanOrEqual(1.0, $quantile);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $alpha, $beta]), 2.0e-9);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $alpha, $beta]) + $survival->invoke([$quantile, $alpha, $beta]),
                    2.0e-9,
                );
            }
        }
        foreach ([[0.5, 0, 2], [0.5, 2, 0], [0.5, 9000, 2000]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Beta distribution should reject invalid shape parameters.');
        }
        foreach ([-0.1, 1.1] as $value) {
            try {
                $cdf->invoke([$value, 2, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Beta distribution should reject values outside [0, 1].');
        }
        foreach ([-0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('betainv should reject probabilities outside [0, 1].');
        }
        foreach ([[0, 0.5, 2], [1, 2, 0.5]] as $singularity) {
            try {
                $pdf->invoke($singularity);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Beta density should reject singular endpoints.');
        }
    }

    public function testBetaDistributionLogTailsRemainStableAtExtremeProbabilities(): void
    {
        $registry = FunctionRegistry::defaults();
        $cdf = $registry->find('betadistributioncdf');
        $survival = $registry->find('betadistributionccdf');
        $logCdf = $registry->find('betadistributionlogcdf');
        $logSurvival = $registry->find('betadistributionlogccdf');
        foreach ([$cdf, $survival, $logCdf, $logSurvival] as $function) {
            self::assertInstanceOf(FunctionDefinition::class, $function);
        }
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);

        self::assertEqualsWithDelta(log(0.6875), $logCdf->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.3125), $logSurvival->invoke([0.5, 2, 3]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([1, 2, 3]));
        self::assertSame(0.0, $logSurvival->invoke([0, 2, 3]));

        $smallValue = 1.0e-200;
        self::assertEqualsWithDelta(
            log(6.0) + 2.0 * log($smallValue),
            $logCdf->invoke([$smallValue, 2, 3]),
            1.0e-12,
        );

        $upperDistance = 1.0e-8;
        $nearOne = 1.0 - $upperDistance;
        self::assertEqualsWithDelta(
            log(4.0) + 3.0 * log(1.0 - $nearOne),
            $logSurvival->invoke([$nearOne, 2, 3]),
            1.0e-7,
        );

        foreach ([['betadistributionlogcdf', 0, 2, 3], ['betadistributionlogccdf', 1, 2, 3]] as $arguments) {
            $function = $registry->find($arguments[0]);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke(array_slice($arguments, 1));
            } catch (\DomainException) {
                continue;
            }
            self::fail($arguments[0] . ' should report its negative-infinite endpoint as undefined.');
        }
    }

    public function testBetaPrimeDistributionFunctionsHandleClosedFormAndRoundTrips(): void
    {
        $functions = FunctionRegistry::defaults();
        $pdf = $functions->find('betaprimepdf');
        $cdf = $functions->find('betaprimecdf');
        $ccdf = $functions->find('betaprimeccdf');
        $inverse = $functions->find('betaprimeinv');
        $logCdf = $functions->find('betaprimelogcdf');
        $logCcdf = $functions->find('betaprimelogccdf');
        $hazard = $functions->find('betaprimehazard');
        $cumulativeHazard = $functions->find('betaprimecumhazard');
        $inverseDerivative = $functions->find('betaprimeinvprime');
        $survivalInverseDerivative = $functions->find('betaprimeccinvprime');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $ccdf);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logCcdf);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);

        // BetaPrime(1, 1) has CDF x/(1+x), density 1/(1+x)^2 and inverse p/(1-p).
        self::assertSame(1.0, $pdf->invoke([0, 1, 1]));
        self::assertEqualsWithDelta(0.25, $pdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $cdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $ccdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(2.0), $logCdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(2.0), $logCcdf->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $hazard->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0), $cumulativeHazard->invoke([1, 1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.5, 1, 1]), 1.0e-12);
        self::assertEqualsWithDelta(-4.0, $survivalInverseDerivative->invoke([0.5, 1, 1]), 1.0e-12);
        self::assertEqualsWithDelta(3.0, $inverse->invoke([0.75, 1, 1]), 1.0e-13);
        self::assertSame(0.0, $cdf->invoke([-1, 1, 1]));
        self::assertSame(1.0, $ccdf->invoke([-1, 1, 1]));
        self::assertSame(-INF, $logCdf->invoke([0, 1, 1]));
        self::assertSame(0.0, $logCcdf->invoke([0, 1, 1]));
        self::assertGreaterThan(200.0, -$logCcdf->invoke([1.0e100, 1, 1]));

        foreach ([[0.1, 0.5, 2.0], [0.5, 2.0, 3.0], [0.9, 4.0, 1.5]] as [$p, $a, $b]) {
            $quantile = $inverse->invoke([$p, $a, $b]);
            self::assertEqualsWithDelta($p, $cdf->invoke([$quantile, $a, $b]), 2.0e-11);
            self::assertEqualsWithDelta(1.0 - $p, $ccdf->invoke([$quantile, $a, $b]), 2.0e-11);
        }

        $aliasCdf = $functions->find('beta2cdf');
        self::assertInstanceOf(FunctionDefinition::class, $aliasCdf);
        self::assertEqualsWithDelta(0.5, $aliasCdf->invoke([1, 1, 1]), 1.0e-14);
        try {
            $inverse->invoke([1, 1, 1]);
        } catch (\DomainException) {
            return;
        }
        self::fail('betaprimeinv() should reject probability one, which has no finite quantile.');
    }

    public function testBetaPrimeMomentsAndLocationSummariesRespectExistenceConditions(): void
    {
        $registry = FunctionRegistry::defaults();
        self::assertEqualsWithDelta(1.0, $registry->find('betaprimemean')?->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $registry->find('betaprimevariance')?->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0), $registry->find('betaprimestddev')?->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $registry->find('betaprimeentropy')?->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(
            2.0,
            $registry->find('beta2entropy')?->invoke([1, 1]),
            1.0e-14,
        );
        $expectedSkewness = 6.0 * sqrt(3.0 / 5.0);
        self::assertEqualsWithDelta(
            $expectedSkewness,
            $registry->find('betaprimeskewness')?->invoke([1, 5]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(
            $expectedSkewness,
            $registry->find('beta2skewness')?->invoke([1, 5]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(
            70.8,
            $registry->find('betaprimeexcesskurtosis')?->invoke([1, 5]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            73.8,
            $registry->find('betaprimekurtosis')?->invoke([1, 5]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(
            70.8,
            $registry->find('beta2excesskurtosis')?->invoke([1, 5]),
            1.0e-12,
        );
        self::assertEqualsWithDelta(0.25, $registry->find('betaprimemode')?->invoke([2, 3]), 1.0e-14);
        self::assertSame(0.0, $registry->find('betaprimemode')?->invoke([1, 1]));
        $median = $registry->find('betaprimemedian')?->invoke([2, 3]);
        self::assertEqualsWithDelta(0.5, $registry->find('betaprimecdf')?->invoke([$median, 2, 3]), 2.0e-12);
        self::assertSame(1.0, $registry->find('betaprimemoment')?->invoke([0, 2, 1]));
        self::assertEqualsWithDelta(1.0, $registry->find('betaprimemoment')?->invoke([1, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(3.0, $registry->find('betaprimemoment')?->invoke([2, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(
            $registry->find('betaprimemean')?->invoke([2, 3]),
            $registry->find('beta2mean')?->invoke([2, 3]),
            0.0,
        );

        foreach (
            [
                ['betaprimemean', [2, 1]],
                ['betaprimevariance', [2, 2]],
                ['betaprimeskewness', [2, 3]],
                ['betaprimeexcesskurtosis', [1, 4]],
                ['betaprimemoment', [2, 2, 2]],
                ['betaprimemoment', [-1, 2, 3]],
                ['betaprimemoment', [1.5, 2, 3]],
            ] as [$name, $arguments]
        ) {
            try {
                $registry->find($name)?->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject inputs outside its moment-existence or order domain.');
        }
    }

    public function testGammaDistributionFunctionsMatchExponentialSpecialCaseAndInvert(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('gammadistpdf');
        $cdf = $registry->find('gammadistcdf');
        $survival = $registry->find('gammadistccdf');
        $inverse = $registry->find('gammadistinv');
        $mean = $registry->find('gammadistmean');
        $median = $registry->find('gammadistmedian');
        $mode = $registry->find('gammadistmode');
        $entropy = $registry->find('gammadistentropy');
        $hazard = $registry->find('gammadisthazard');
        $cumulativeHazard = $registry->find('gammadistcumhazard');
        $variance = $registry->find('gammadistvariance');
        $standardDeviation = $registry->find('gammadiststddev');
        $skewness = $registry->find('gammadistskewness');
        $excessKurtosis = $registry->find('gammadistexcesskurtosis');
        $kurtosis = $registry->find('gammadistkurtosis');
        $logMgf = $registry->find('gammadistlogmgf');
        $mgf = $registry->find('gammadistmgf');
        $rawMoment = $registry->find('gammadistmoment');
        $inverseDerivative = $registry->find('gammadistinvprime');
        $inverseSurvivalDerivative = $registry->find('gammadistccinvprime');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $inverseSurvivalDerivative);

        self::assertEqualsWithDelta(0.5 * exp(-1.0), $pdf->invoke([2, 1, 2]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 - exp(-1.0), $cdf->invoke([2, 1, 2]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-1.0), $survival->invoke([2, 1, 2]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 / (3.0 * M_E), $pdf->invoke([3, 2, 3]), 1.0e-14);
        self::assertSame(0.0, $pdf->invoke([-1, 2, 3]));
        self::assertSame(0.0, $cdf->invoke([0, 2, 3]));
        self::assertSame(1.0, $survival->invoke([0, 2, 3]));
        self::assertSame(0.0, $survival->invoke([1.0e100, 2, 1]));
        self::assertSame(0.0, $inverse->invoke([0, 3, 2]));
        self::assertEqualsWithDelta(2.0 * log(2.0), $inverse->invoke([0.5, 1, 2]), 1.0e-12);
        self::assertSame(6.0, $mean->invoke([2.0, 3.0]));
        self::assertEqualsWithDelta(2.0 * log(2.0), $median->invoke([1.0, 2.0]), 1.0e-12);
        self::assertSame(0.0, $mode->invoke([1.0, 2.0]));
        self::assertSame(3.0, $mode->invoke([2.0, 3.0]));
        self::assertEqualsWithDelta(1.0 + log(2.0), $entropy->invoke([1.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 + 0.5772156649015329 + log(3.0), $entropy->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(0.5, $hazard->invoke([2.0, 1.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $cumulativeHazard->invoke([2.0, 1.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($survival->invoke([3.0, 2.0, 3.0])),
            $cumulativeHazard->invoke([3.0, 2.0, 3.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([3.0, 2.0, 3.0]) / $survival->invoke([3.0, 2.0, 3.0]),
            $hazard->invoke([3.0, 2.0, 3.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(18.0, $variance->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(18.0), $standardDeviation->invoke([2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0), $skewness->invoke([2.0, 3.0]), 1.0e-14);
        self::assertSame(3.0, $excessKurtosis->invoke([2.0, 3.0]));
        self::assertSame(6.0, $kurtosis->invoke([2.0, 3.0]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 2.0, 3.0]));
        self::assertSame(1.0, $mgf->invoke([0.0, 2.0, 3.0]));
        self::assertEqualsWithDelta(2.0 * log(2.0), $logMgf->invoke([1.0 / 6.0, 2.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $mgf->invoke([1.0 / 6.0, 2.0, 3.0]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 2.0, 3.0]));
        self::assertEqualsWithDelta(648.0, $rawMoment->invoke([3, 2.0, 3.0]), 1.0e-12);
        self::assertEqualsWithDelta(6.0, $rawMoment->invoke([2, 2.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.5, 1.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(-4.0, $inverseSurvivalDerivative->invoke([0.5, 1.0, 2.0]), 1.0e-14);
        $probability = 0.4;
        $step = 1.0e-6;
        $finiteDifference = (
            $inverse->invoke([$probability + $step, 2.0, 3.0])
            - $inverse->invoke([$probability - $step, 2.0, 3.0])
        ) / (2.0 * $step);
        self::assertEqualsWithDelta($finiteDifference, $inverseDerivative->invoke([$probability, 2.0, 3.0]), 1.0e-7);
        self::assertSame(
            $inverseDerivative->invoke([$probability, 2.0, 3.0]),
            $registry->find('gammainvprime')?->invoke([$probability, 2.0, 3.0]),
        );
        self::assertSame(
            $inverseSurvivalDerivative->invoke([0.5, 2.0, 3.0]),
            $registry->find('gammaccinvprime')?->invoke([0.5, 2.0, 3.0]),
        );
        foreach ([$inverseDerivative, $inverseSurvivalDerivative] as $derivative) {
            foreach ([0.0, 1.0] as $endpoint) {
                try {
                    $derivative->invoke([$endpoint, 2.0, 3.0]);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($derivative->name . ' should reject endpoint probabilities.');
            }
        }
        self::assertEqualsWithDelta(-2.0 * log(4.0), $logMgf->invoke([-1.0, 2.0, 3.0]), 1.0e-14);
        self::assertSame($mean->invoke([2, 3]), $registry->find('gammamean')?->invoke([2, 3]));
        self::assertSame($variance->invoke([2, 3]), $registry->find('gammavar')?->invoke([2, 3]));
        self::assertSame($rawMoment->invoke([3, 2, 3]), $registry->find('gammamoment')?->invoke([3, 2, 3]));
        self::assertSame($skewness->invoke([2, 3]), $registry->find('gammaskewness')?->invoke([2, 3]));
        self::assertSame($median->invoke([2, 3]), $registry->find('gammamedian')?->invoke([2, 3]));
        self::assertSame($mode->invoke([2, 3]), $registry->find('gammamode')?->invoke([2, 3]));
        self::assertSame($entropy->invoke([2, 3]), $registry->find('gammaentropy')?->invoke([2, 3]));
        self::assertSame($hazard->invoke([3, 2, 3]), $registry->find('gammahazard')?->invoke([3, 2, 3]));
        foreach ([-1, 1.5, 65] as $order) {
            try {
                $rawMoment->invoke([$order, 2.0, 3.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('gammadistmoment should reject invalid moment orders.');
        }
        try {
            $rawMoment->invoke([64, 1.0, 1.0e100]);
            self::fail('gammadistmoment should report an unrepresentable moment.');
        } catch (\OverflowException) {
            // The moment exceeds the finite floating-point range.
        }
        foreach ([$logMgf, $mgf] as $momentGeneratingFunction) {
            try {
                $momentGeneratingFunction->invoke([1.0 / 3.0, 2.0, 3.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($momentGeneratingFunction->name . ' should reject t >= 1/scale.');
        }
        self::assertSame(0.5, $hazard->invoke([0.0, 1.0, 2.0]));
        self::assertSame(0.0, $hazard->invoke([0.0, 2.0, 2.0]));
        try {
            $hazard->invoke([0.0, 0.5, 2.0]);
            self::fail('Gamma hazard should reject its infinite boundary value for shape below one.');
        } catch (\DomainException) {
            // The density and hazard diverge at zero when shape is below one.
        }
        foreach ([$hazard, $cumulativeHazard] as $function) {
            try {
                $function->invoke([-1.0, 2.0, 3.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($function->name . ' should reject values below the support.');
        }

        foreach ([0.001, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-10] as $probability) {
            foreach ([[0.5, 0.25], [1.0, 1.0], [2.0, 3.0], [10.0, 0.1], [100.0, 5.0]] as [$shape, $scale]) {
                $quantile = $inverse->invoke([$probability, $shape, $scale]);
                self::assertGreaterThan(0.0, $quantile);
                self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile, $shape, $scale]), 2.0e-9);
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $shape, $scale])
                        + $survival->invoke([$quantile, $shape, $scale]),
                    2.0e-9,
                );
            }
        }
        foreach ([[1, 0, 1], [1, 10001, 1], [1, 1, 0]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Gamma distribution should reject invalid shape and scale parameters.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('gammainv should require a probability in [0, 1).');
        }
        try {
            $pdf->invoke([0, 0.5, 1]);
        } catch (\DomainException) {
            return;
        }
        self::fail('Gamma density should reject the singular endpoint when shape < 1.');
    }

    public function testCauchyDistributionFunctionsPreserveHeavyTailsAndQuantiles(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('cauchypdf');
        $cdf = $registry->find('cauchycdf');
        $survival = $registry->find('cauchyccdf');
        $logCdf = $registry->find('cauchylogcdf');
        $logSurvival = $registry->find('cauchylogccdf');
        $inverse = $registry->find('cauchyinv');
        $survivalInverse = $registry->find('cauchyccinv');
        $inverseDerivative = $registry->find('cauchyinvprime');
        $survivalInverseDerivative = $registry->find('cauchyccinvprime');
        $median = $registry->find('cauchymedian');
        $mode = $registry->find('cauchymode');
        $entropy = $registry->find('cauchyentropy');
        $hazard = $registry->find('cauchyhazard');
        $cumulativeHazard = $registry->find('cauchycumhazard');
        $characteristicReal = $registry->find('cauchycharfuncreal');
        $characteristicImaginary = $registry->find('cauchycharfuncimag');
        $absoluteMoment = $registry->find('cauchyabsmoment');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $characteristicReal);
        self::assertInstanceOf(FunctionDefinition::class, $characteristicImaginary);
        self::assertInstanceOf(FunctionDefinition::class, $absoluteMoment);

        self::assertEqualsWithDelta(1.0 / (2.0 * M_PI), $pdf->invoke([1, 0, 1]), 1.0e-14);
        self::assertSame(0.5, $cdf->invoke([0, 0, 1]));
        self::assertSame(0.5, $survival->invoke([0, 0, 1]));
        self::assertEqualsWithDelta(0.25, $cdf->invoke([-1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.75, $cdf->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.25, $survival->invoke([1, 0, 1]), 1.0e-14);
        self::assertGreaterThan(0.0, $survival->invoke([1.0e12, 0, 1]));
        self::assertEqualsWithDelta(log(0.25), $logCdf->invoke([-1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logSurvival->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(M_PI) - log(1.0e200), $logSurvival->invoke([1.0e200, 0, 1]), 1.0e-13);
        self::assertEqualsWithDelta($pdf->invoke([1, 0, 1]) / 0.25, $hazard->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-log(0.25), $cumulativeHazard->invoke([1, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-1.0) * cos(0.6), $characteristicReal->invoke([1, 0.6, 1]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-1.0) * sin(0.6), $characteristicImaginary->invoke([1, 0.6, 1]), 1.0e-14);
        self::assertSame(0.0, $characteristicImaginary->invoke([0, 20, 3]));
        self::assertSame(1.0, $absoluteMoment->invoke([0, 0, 3]));
        self::assertEqualsWithDelta(sqrt(2.0) * sqrt(3.0), $absoluteMoment->invoke([0.5, 0, 3]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 ** 0.9 / cos(M_PI * 0.45), $absoluteMoment->invoke([0.9, 0, 3]), 1.0e-12);
        foreach ([-0.1, 1.0, 1.1, INF] as $order) {
            try {
                $absoluteMoment->invoke([$order, 0, 1]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('cauchyabsmoment should reject orders outside [0, 1).');
        }
        self::assertSame(0.0, $pdf->invoke([1.0e200, 0, 1]));
        self::assertSame(2.0, $inverse->invoke([0.5, 2, 3]));
        self::assertSame(2.0, $median->invoke([2, 3]));
        self::assertSame(2.0, $mode->invoke([2, 3]));
        self::assertSame($inverse->invoke([0.5, 2, 3]), $median->invoke([2, 3]));
        self::assertEqualsWithDelta(log(12.0 * M_PI), $entropy->invoke([2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $inverse->invoke([0.75, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-1.0, $inverse->invoke([0.25, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(1.0, $survivalInverse->invoke([0.25, 0, 1]), 1.0e-14);
        self::assertEqualsWithDelta(16.0 / (3.0 * M_PI), $inverseDerivative->invoke([0.25, 0, 1]), 1.0e-13);
        self::assertEqualsWithDelta(
            -16.0 / (3.0 * M_PI),
            $survivalInverseDerivative->invoke([0.25, 0, 1]),
            1.0e-13,
        );
        self::assertSame(2.0, $survivalInverse->invoke([0.5, 2, 3]));

        foreach ([1.0e-12, 0.01, 0.1, 0.49, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            $survivalQuantile = $survivalInverse->invoke([$probability, 0, 1]);
            self::assertEqualsWithDelta($probability, $survival->invoke([$survivalQuantile, 0, 1]), 2.0e-12);
        }

        foreach ([1.0e-12, 0.01, 0.1, 0.49, 0.5, 0.5000000000000001, 0.51, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[-5.0, 0.1], [0.0, 1.0], [12.0, 25.0]] as [$location, $scale]) {
                $quantile = $inverse->invoke([$probability, $location, $scale]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $location, $scale]),
                    2.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $location, $scale])
                        + $survival->invoke([$quantile, $location, $scale]),
                    2.0e-12,
                );
                self::assertGreaterThanOrEqual(0.0, $pdf->invoke([$quantile, $location, $scale]));
            }
        }
        foreach ([[1, 0, 0], [1, 2, -1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Cauchy functions should reject non-positive scales.');
        }
        foreach ([0.0, 1.0, -0.1, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 0, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('cauchyinv should require probabilities strictly between 0 and 1.');
        }
    }

    public function testParetoDistributionFunctionsPreservePowerLawTail(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('paretopdf');
        $logPdf = $registry->find('paretologpdf');
        $cdf = $registry->find('paretocdf');
        $survival = $registry->find('paretoccdf');
        $logCdf = $registry->find('paretologcdf');
        $logSurvival = $registry->find('paretologccdf');
        $inverse = $registry->find('paretoinv');
        $survivalInverse = $registry->find('paretoccinv');
        $inverseDerivative = $registry->find('paretoinvprime');
        $survivalInverseDerivative = $registry->find('paretoccinvprime');
        $moment = $registry->find('paretomoment');
        $mean = $registry->find('paretomean');
        $median = $registry->find('paretomedian');
        $mode = $registry->find('paretomode');
        $variance = $registry->find('paretovariance');
        $standardDeviation = $registry->find('paretostddev');
        $entropy = $registry->find('paretoentropy');
        $hazard = $registry->find('paretohazard');
        $cumulativeHazard = $registry->find('paretocumhazard');
        $skewness = $registry->find('paretoskewness');
        $excessKurtosis = $registry->find('paretoexcesskurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);

        self::assertSame(0.0, $pdf->invoke([0.5, 2, 1]));
        self::assertSame(2.0, $pdf->invoke([1, 2, 1]));
        self::assertEqualsWithDelta(0.25, $pdf->invoke([2, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logPdf->invoke([2, 2, 1]), 1.0e-14);
        self::assertSame(-INF, $logPdf->invoke([0.5, 2, 1]));
        self::assertSame(0.0, $pdf->invoke([1.0e100, 50, 1]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e100, 50, 1]));
        self::assertSame(0.0, $cdf->invoke([1, 2, 1]));
        self::assertSame(1.0, $survival->invoke([1, 2, 1]));
        self::assertSame(-INF, $logCdf->invoke([1, 2, 1]));
        self::assertSame(0.0, $logSurvival->invoke([1, 2, 1]));
        self::assertEqualsWithDelta(0.75, $cdf->invoke([2, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(0.25, $survival->invoke([2, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.75), $logCdf->invoke([2, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logSurvival->invoke([2, 2, 1]), 1.0e-14);
        self::assertLessThan(-700.0, $logCdf->invoke([1.0 + 1.0e-100, 2, 1]));
        self::assertLessThan(-700.0, $logSurvival->invoke([1.0e300, 2, 1]));
        self::assertGreaterThan(0.0, $survival->invoke([1.0e100, 2, 1]));
        self::assertSame(1.0, $cdf->invoke([1.0e100, 2, 1]));
        self::assertSame(1.0, $inverse->invoke([0, 2, 1]));
        self::assertEqualsWithDelta(2.0, $inverse->invoke([0.75, 2, 1]), 1.0e-14);
        self::assertSame(1.0, $survivalInverse->invoke([1.0, 2, 1]));
        self::assertEqualsWithDelta(2.0, $survivalInverse->invoke([0.25, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(4.0, $inverseDerivative->invoke([0.75, 2, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-4.0, $survivalInverseDerivative->invoke([0.25, 2, 1]), 1.0e-14);
        self::assertSame(1.0, $moment->invoke([0, 5, 2]));
        self::assertEqualsWithDelta(2.5, $moment->invoke([1, 5, 2]), 1.0e-14);
        self::assertEqualsWithDelta(20.0 / 3.0, $moment->invoke([2, 5, 2]), 1.0e-14);
        self::assertEqualsWithDelta(80.0, $moment->invoke([4, 5, 2]), 1.0e-13);
        self::assertEqualsWithDelta(6.0 * sqrt(0.6), $skewness->invoke([5, 2]), 1.0e-14);
        self::assertEqualsWithDelta(70.8, $excessKurtosis->invoke([5, 2]), 1.0e-13);
        self::assertEqualsWithDelta(73.8, $registry->find('paretokurtosis')?->invoke([5, 2]), 1.0e-13);
        self::assertSame($skewness->invoke([5, 2]), $registry->find('pareto2skewness')?->invoke([5, 2]));
        self::assertSame(
            $excessKurtosis->invoke([5, 2]),
            $registry->find('pareto2excesskurtosis')?->invoke([5, 2]),
        );
        self::assertSame($moment->invoke([2, 5, 2]), $registry->find('paretorawmoment')?->invoke([2, 5, 2]));
        self::assertEqualsWithDelta(2.5, $mean->invoke([5.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * 2.0 ** 0.2, $median->invoke([5.0, 2.0]), 1.0e-14);
        self::assertSame(2.0, $mode->invoke([5.0, 2.0]));
        self::assertEqualsWithDelta(5.0 / 12.0, $variance->invoke([5.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(5.0 / 12.0), $standardDeviation->invoke([5.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(log(2.0 / 5.0) + 1.2, $entropy->invoke([5.0, 2.0]), 1.0e-14);
        self::assertSame(0.0, $hazard->invoke([1.0, 5.0, 2.0]));
        self::assertEqualsWithDelta(2.5, $hazard->invoke([2.0, 5.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 * log(2.0), $cumulativeHazard->invoke([4.0, 5.0, 2.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($survival->invoke([4.0, 5.0, 2.0])),
            $cumulativeHazard->invoke([4.0, 5.0, 2.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([4.0, 5.0, 2.0]) / $survival->invoke([4.0, 5.0, 2.0]),
            $hazard->invoke([4.0, 5.0, 2.0]),
            1.0e-14,
        );

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[0.1, 1.0e-4], [1.0, 1.0], [2.0, 5.0], [50.0, 1.0e6]] as [$shape, $minimum]) {
                $quantile = $inverse->invoke([$probability, $shape, $minimum]);
                self::assertGreaterThanOrEqual($minimum, $quantile);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $shape, $minimum]),
                    2.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $shape, $minimum])
                        + $survival->invoke([$quantile, $shape, $minimum]),
                    2.0e-12,
                );
            }
        }
        foreach ([[1, 0, 1], [1, 2, 0], [1, -1, 2]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Pareto functions should reject non-positive shape or minimum.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('paretoinv should require a probability in [0, 1).');
        }
        foreach ([[2, 2, 1], [3, 2.5, 1], [-1, 5, 1], [1.5, 5, 1], [65, 100, 1]] as $invalid) {
            try {
                $moment->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('paretomoment should require a non-negative integer order strictly below the shape.');
        }
        foreach ([['paretomean', 1.0], ['paretovariance', 2.0], ['paretostddev', 2.0]] as [$name, $shape]) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([$shape, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a divergent moment.');
        }
        foreach ([['paretoskewness', 3.0], ['paretoexcesskurtosis', 4.0]] as [$name, $shape]) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([$shape, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a shape at its divergent-moment boundary.');
        }
    }

    public function testLomaxDistributionFunctionsPreserveTailAndInvert(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('lomaxpdf');
        $logPdf = $registry->find('lomaxlogpdf');
        $cdf = $registry->find('lomaxcdf');
        $survival = $registry->find('lomaxccdf');
        $logCdf = $registry->find('lomaxlogcdf');
        $logSurvival = $registry->find('lomaxlogccdf');
        $inverse = $registry->find('lomaxinv');
        $survivalInverse = $registry->find('lomaxccinv');
        $inverseDerivative = $registry->find('lomaxinvprime');
        $survivalInverseDerivative = $registry->find('lomaxccinvprime');
        $moment = $registry->find('lomaxmoment');
        $mean = $registry->find('lomaxmean');
        $median = $registry->find('lomaxmedian');
        $mode = $registry->find('lomaxmode');
        $variance = $registry->find('lomaxvariance');
        $standardDeviation = $registry->find('lomaxstddev');
        $entropy = $registry->find('lomaxentropy');
        $hazard = $registry->find('lomaxhazard');
        $cumulativeHazard = $registry->find('lomaxcumhazard');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);

        self::assertSame(0.0, $pdf->invoke([-1, 2, 3]));
        self::assertSame(0.0, $cdf->invoke([-1, 2, 3]));
        self::assertSame(1.0, $survival->invoke([-1, 2, 3]));
        self::assertSame(-INF, $logCdf->invoke([0, 2, 3]));
        self::assertSame(0.0, $logSurvival->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(2.0 / 3.0, $pdf->invoke([0, 2, 3]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 12.0, $pdf->invoke([3, 2, 3]), 1.0e-15);
        self::assertEqualsWithDelta(log(1.0 / 12.0), $logPdf->invoke([3, 2, 3]), 1.0e-14);
        self::assertSame(-INF, $logPdf->invoke([-1, 2, 3]));
        self::assertSame(0.0, $pdf->invoke([1.0e100, 50, 3]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e100, 50, 3]));
        self::assertEqualsWithDelta(0.75, $cdf->invoke([3, 2, 3]), 1.0e-15);
        self::assertEqualsWithDelta(0.25, $survival->invoke([3, 2, 3]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.75), $logCdf->invoke([3, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(log(0.25), $logSurvival->invoke([3, 2, 3]), 1.0e-14);
        self::assertLessThan(-700.0, $logCdf->invoke([1.0e-308, 2, 3]));
        self::assertLessThan(-700.0, $logSurvival->invoke([1.0e300, 2, 3]));
        self::assertSame(0.0, $inverse->invoke([0, 2, 3]));
        self::assertEqualsWithDelta(3.0, $inverse->invoke([0.75, 2, 3]), 1.0e-14);
        self::assertSame(0.0, $survivalInverse->invoke([1.0, 2, 3]));
        self::assertEqualsWithDelta(3.0, $survivalInverse->invoke([0.25, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(12.0, $inverseDerivative->invoke([0.75, 2, 3]), 1.0e-14);
        self::assertEqualsWithDelta(-12.0, $survivalInverseDerivative->invoke([0.25, 2, 3]), 1.0e-14);
        self::assertSame(1.0, $moment->invoke([0, 5, 3]));
        self::assertEqualsWithDelta(0.75, $moment->invoke([1, 5, 3]), 1.0e-14);
        self::assertEqualsWithDelta(1.5, $moment->invoke([2, 5, 3]), 1.0e-14);
        self::assertEqualsWithDelta(81.0, $moment->invoke([4, 5, 3]), 1.0e-12);
        self::assertEqualsWithDelta(0.75, $mean->invoke([5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 * (2.0 ** 0.2 - 1.0), $median->invoke([5.0, 3.0]), 1.0e-14);
        self::assertSame(0.0, $mode->invoke([5.0, 3.0]));
        self::assertEqualsWithDelta(15.0 / 16.0, $variance->invoke([5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(15.0 / 16.0), $standardDeviation->invoke([5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(log(3.0 / 5.0) + 1.2, $entropy->invoke([5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 3.0, $hazard->invoke([0.0, 5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 / 6.0, $hazard->invoke([3.0, 5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(5.0 * log(2.0), $cumulativeHazard->invoke([3.0, 5.0, 3.0]), 1.0e-14);
        self::assertEqualsWithDelta(
            -log($survival->invoke([3.0, 5.0, 3.0])),
            $cumulativeHazard->invoke([3.0, 5.0, 3.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            $pdf->invoke([3.0, 5.0, 3.0]) / $survival->invoke([3.0, 5.0, 3.0]),
            $hazard->invoke([3.0, 5.0, 3.0]),
            1.0e-14,
        );
        self::assertSame($moment->invoke([2, 5, 3]), $registry->find('pareto2moment')?->invoke([2, 5, 3]));
        self::assertSame($mean->invoke([5.0, 3.0]), $registry->find('pareto2mean')?->invoke([5.0, 3.0]));
        self::assertSame($variance->invoke([5.0, 3.0]), $registry->find('pareto2var')?->invoke([5.0, 3.0]));
        self::assertSame($hazard->invoke([3.0, 5.0, 3.0]), $registry->find('pareto2hazard')?->invoke([3.0, 5.0, 3.0]));
        $nearMomentBoundaryShape = 2.0 + 1.0e-12;
        self::assertEqualsWithDelta(
            2.0 / (($nearMomentBoundaryShape - 1.0) * ($nearMomentBoundaryShape - 2.0)),
            $moment->invoke([2, $nearMomentBoundaryShape, 1]),
            2.0,
        );
        self::assertGreaterThan(0.0, $survival->invoke([1.0e100, 2, 3]));
        self::assertSame(1.0, $cdf->invoke([1.0e100, 2, 3]));

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([[0.1, 1.0e-4], [1.0, 1.0], [2.0, 3.0], [50.0, 1.0e6]] as [$shape, $scale]) {
                $quantile = $inverse->invoke([$probability, $shape, $scale]);
                self::assertGreaterThanOrEqual(0.0, $quantile);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $shape, $scale]),
                    3.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $shape, $scale])
                        + $survival->invoke([$quantile, $shape, $scale]),
                    2.0e-15,
                );
            }
        }

        foreach ([[-1, 0, 1], [0, 2, 0], [0, -1, 1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Lomax functions should reject non-positive shape or scale.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 2, 3]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('lomaxinv should require a probability in [0, 1).');
        }
        foreach ([[2, 2, 1], [3, 2.5, 1], [-1, 5, 1], [1.5, 5, 1], [65, 100, 1]] as $invalidMoment) {
            try {
                $moment->invoke($invalidMoment);
            } catch (\DomainException) {
                continue;
            }
            self::fail('lomaxmoment should reject divergent or non-integer raw moments.');
        }
        $divergentMoments = [
            ['lomaxmean', [1.0, 1.0]],
            ['lomaxvariance', [2.0, 1.0]],
            ['lomaxstddev', [2.0, 1.0]],
        ];
        foreach ($divergentMoments as [$name, $parameters]) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke($parameters);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject divergent moments.');
        }
    }

    public function testRayleighDistributionFunctionsRemainStableAndInvert(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('rayleighpdf');
        $cdf = $registry->find('rayleighcdf');
        $survival = $registry->find('rayleighccdf');
        $logCdf = $registry->find('rayleighlogcdf');
        $logSurvival = $registry->find('rayleighlogccdf');
        $inverse = $registry->find('rayleighinv');
        $inverseDerivative = $registry->find('rayleighinvprime');
        $survivalInverseDerivative = $registry->find('rayleighccinvprime');
        $mgf = $registry->find('rayleighmgf');
        $logMgf = $registry->find('rayleighlogmgf');
        $rawMoment = $registry->find('rayleighmoment');
        $mean = $registry->find('rayleighmean');
        $variance = $registry->find('rayleighvariance');
        $median = $registry->find('rayleighmedian');
        $mode = $registry->find('rayleighmode');
        $standardDeviation = $registry->find('rayleighstddev');
        $entropy = $registry->find('rayleighentropy');
        $hazard = $registry->find('rayleighhazard');
        $cumulativeHazard = $registry->find('rayleighcumhazard');
        $skewness = $registry->find('rayleighskewness');
        $excessKurtosis = $registry->find('rayleighexcesskurtosis');
        $kurtosis = $registry->find('rayleighkurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $inverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $survivalInverseDerivative);
        self::assertInstanceOf(FunctionDefinition::class, $mgf);
        self::assertInstanceOf(FunctionDefinition::class, $logMgf);
        self::assertInstanceOf(FunctionDefinition::class, $rawMoment);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $mode);
        self::assertInstanceOf(FunctionDefinition::class, $standardDeviation);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $hazard);
        self::assertInstanceOf(FunctionDefinition::class, $cumulativeHazard);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertSame(0.0, $pdf->invoke([-1, 1]));
        self::assertSame(0.0, $pdf->invoke([0, 1]));
        self::assertEqualsWithDelta(exp(-0.5), $pdf->invoke([1, 1]), 1.0e-14);
        self::assertSame(0.0, $cdf->invoke([0, 1]));
        self::assertSame(1.0, $survival->invoke([0, 1]));
        self::assertEqualsWithDelta(1.0 - exp(-0.5), $cdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(exp(-0.5), $survival->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log1p(-exp(-0.5)), $logCdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-0.5, $logSurvival->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(-921.7271843781782, $logCdf->invoke([1.0e-200, 1]), 1.0e-12);
        self::assertSame(0.0, $survival->invoke([40, 1]));
        self::assertEqualsWithDelta(-800.0, $logSurvival->invoke([40, 1]), 1.0e-12);
        self::assertEqualsWithDelta(log($cdf->invoke([1, 1])), $logCdf->invoke([1, 1]), 1.0e-14);
        self::assertEqualsWithDelta(log($survival->invoke([1, 1])), $logSurvival->invoke([1, 1]), 1.0e-14);
        self::assertSame(1.0, $mgf->invoke([0.0, 1.0]));
        self::assertSame(0.0, $logMgf->invoke([0.0, 1.0]));
        $erfcx = $registry->find('erfcx');
        self::assertInstanceOf(FunctionDefinition::class, $erfcx);
        $positiveMgf = 1.0 + sqrt(M_PI / 2.0) * $erfcx->invoke([-1.0 / sqrt(2.0)]);
        self::assertEqualsWithDelta($positiveMgf, $mgf->invoke([1.0, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(log($positiveMgf), $logMgf->invoke([1.0, 1.0]), 1.0e-14);
        $negativeMgf = 1.0 - 20.0 * sqrt(M_PI / 2.0) * $erfcx->invoke([20.0 / sqrt(2.0)]);
        self::assertEqualsWithDelta(log($negativeMgf), $logMgf->invoke([-20.0, 1.0]), 1.0e-12);
        self::assertEqualsWithDelta(-800.0 * log(10.0), $logMgf->invoke([-1.0e300, 1.0e100]), 1.0e-10);
        self::assertSame(0.0, $mgf->invoke([-1.0e300, 1.0e100]));
        self::assertSame(0.0, $inverse->invoke([0, 3]));
        self::assertEqualsWithDelta(sqrt(2.0 / log(2.0)), $inverseDerivative->invoke([0.5, 1.0]), 1.0e-13);
        self::assertEqualsWithDelta(-sqrt(2.0 / log(2.0)), $survivalInverseDerivative->invoke([0.5, 1.0]), 1.0e-13);
        self::assertEqualsWithDelta(sqrt(M_PI / 2.0), $mean->invoke([1.0]), 1.0e-14);
        self::assertSame(1.0, $rawMoment->invoke([0, 1.0]));
        self::assertEqualsWithDelta(sqrt(M_PI / 2.0), $rawMoment->invoke([1, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0, $rawMoment->invoke([2, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 * sqrt(M_PI / 2.0), $rawMoment->invoke([3, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(M_PI / 2.0), $rawMoment->invoke([-1, 1.0]), 1.0e-14);
        self::assertEqualsWithDelta(2.0 * $rawMoment->invoke([2, 1.0]), $rawMoment->invoke([2, sqrt(2.0)]), 1.0e-14);
        self::assertSame($rawMoment->invoke([1.5, 2.0]), $registry->find('rayleighrawmoment')?->invoke([1.5, 2.0]));
        foreach ([-2.0, 10001.0] as $order) {
            try {
                $rawMoment->invoke([$order, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('rayleighmoment should reject orders outside its domain.');
        }
        try {
            $rawMoment->invoke([2.0, \PHP_FLOAT_MAX]);
            self::fail('rayleighmoment should reject an unrepresentable result.');
        } catch (\OverflowException) {
            // The second raw moment exceeds the floating-point range.
        }
        self::assertEqualsWithDelta((4.0 - M_PI) / 2.0, $variance->invoke([1.0]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0 * log(2.0)), $median->invoke([1.0]), 1.0e-14);
        self::assertSame(1.0, $mode->invoke([1.0]));
        self::assertEqualsWithDelta(sqrt((4.0 - M_PI) / 2.0), $standardDeviation->invoke([1.0]), 1.0e-14);
        self::assertEqualsWithDelta(1.0 - 0.5 * log(2.0) + 0.5 * 0.5772156649015329, $entropy->invoke([1.0]), 1.0e-14);
        self::assertSame(2.0, $hazard->invoke([2.0, 1.0]));
        self::assertSame(2.0, $cumulativeHazard->invoke([2.0, 1.0]));
        self::assertEqualsWithDelta(
            2.0 * sqrt(M_PI) * (M_PI - 3.0) / ((4.0 - M_PI) ** 1.5),
            $skewness->invoke([1.0]),
            1.0e-14,
        );
        $expectedExcessKurtosis = (-6.0 * M_PI * M_PI + 24.0 * M_PI - 16.0) / ((4.0 - M_PI) ** 2);
        self::assertEqualsWithDelta($expectedExcessKurtosis, $excessKurtosis->invoke([1.0]), 1.0e-14);
        self::assertEqualsWithDelta(3.0 + $expectedExcessKurtosis, $kurtosis->invoke([1.0]), 1.0e-14);

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([1.0e-100, 0.5, 1.0, 1.0e100] as $scale) {
                $quantile = $inverse->invoke([$probability, $scale]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $scale]),
                    2.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $scale]) + $survival->invoke([$quantile, $scale]),
                    2.0e-12,
                );
            }
        }
        foreach ([[1, 0], [1, -1]] as $invalid) {
            try {
                $pdf->invoke($invalid);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Rayleigh functions should reject non-positive scales.');
        }
        foreach ([$hazard, $cumulativeHazard] as $function) {
            try {
                $function->invoke([-1.0, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($function->name . ' should reject values below the support.');
        }
        foreach (
            [
                'rayleighmean',
                'rayleighvariance',
                'rayleighskewness',
                'rayleighexcesskurtosis',
                'rayleighkurtosis',
            ] as $name
        ) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([0.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a non-positive scale.');
        }
        foreach ([-0.1, 1.0, 1.1] as $probability) {
            try {
                $inverse->invoke([$probability, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('rayleighinv should require a probability in [0, 1).');
        }
    }

    public function testArcsineDistributionFunctionsHandleSupportAndTails(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('arcsinepdf');
        $logPdf = $registry->find('arcsinelogpdf');
        $cdf = $registry->find('arcsinecdf');
        $survival = $registry->find('arcsineccdf');
        $logCdf = $registry->find('arcsinelogcdf');
        $logSurvival = $registry->find('arcsinelogccdf');
        $inverse = $registry->find('arcsineinv');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);

        self::assertSame(0.0, $pdf->invoke([-0.1]));
        self::assertSame(0.0, $pdf->invoke([1.1]));
        self::assertSame(0.0, $cdf->invoke([-0.1]));
        self::assertSame(1.0, $cdf->invoke([1.1]));
        self::assertSame(1.0, $survival->invoke([-0.1]));
        self::assertSame(0.0, $survival->invoke([1.1]));
        self::assertEqualsWithDelta(2.0 / M_PI, $pdf->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log(2.0 / M_PI), $logPdf->invoke([0.5]), 1.0e-15);
        foreach ([0.0, 1.0, -0.1, 1.1] as $value) {
            try {
                $logPdf->invoke([$value]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('arcsinelogpdf should reject points without finite log-density.');
        }
        self::assertEqualsWithDelta(0.5, $cdf->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(0.5, $survival->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.5), $logCdf->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.5), $logSurvival->invoke([0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log($cdf->invoke([0.25])), $logCdf->invoke([0.25]), 1.0e-15);
        self::assertEqualsWithDelta(log($survival->invoke([0.25])), $logSurvival->invoke([0.25]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 3.0, $cdf->invoke([0.25]), 1.0e-15);
        self::assertEqualsWithDelta(2.0 / 3.0, $survival->invoke([0.25]), 1.0e-15);
        self::assertSame(0.0, $inverse->invoke([0.0]));
        self::assertSame(1.0, $inverse->invoke([1.0]));
        self::assertEqualsWithDelta((2.0 - \sqrt(2.0)) / 4.0, $inverse->invoke([0.25]), 1.0e-15);
        self::assertEqualsWithDelta((2.0 + \sqrt(2.0)) / 4.0, $inverse->invoke([0.75]), 1.0e-15);
        self::assertEqualsWithDelta(2.0e-12 / M_PI, $cdf->invoke([1.0e-24]), 1.0e-24);
        $nearOne = 1.0 - 1.0e-15;
        self::assertEqualsWithDelta(
            log(2.0 / M_PI) + 0.5 * log(1.0e-300),
            $logCdf->invoke([1.0e-300]),
            1.0e-13,
        );
        self::assertEqualsWithDelta(
            log(2.0 / M_PI) + 0.5 * log(1.0 - $nearOne),
            $logSurvival->invoke([$nearOne]),
            1.0e-8,
        );
        self::assertEqualsWithDelta(
            (2.0 / M_PI) * \asin(\sqrt(1.0 - $nearOne)),
            $survival->invoke([$nearOne]),
            1.0e-15,
        );

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99] as $probability) {
            $quantile = $inverse->invoke([$probability]);
            self::assertEqualsWithDelta($probability, $cdf->invoke([$quantile]), 2.0e-14);
            self::assertEqualsWithDelta(1.0, $cdf->invoke([$quantile]) + $survival->invoke([$quantile]), 2.0e-15);
        }
    }

    public function testGeometricDistributionUsesFailuresBeforeFirstSuccess(): void
    {
        $registry = FunctionRegistry::defaults();
        $pmf = $registry->find('geometricpmf');
        $logPmf = $registry->find('geometriclogpmf');
        $cdf = $registry->find('geometriccdf');
        $survival = $registry->find('geometricccdf');
        $logCdf = $registry->find('geometriclogcdf');
        $logSurvival = $registry->find('geometriclogccdf');
        $inverse = $registry->find('geometricinv');
        $mean = $registry->find('geometricdistributionmean');
        $variance = $registry->find('geometricvariance');
        $skewness = $registry->find('geometricskewness');
        $excessKurtosis = $registry->find('geometricexcesskurtosis');
        $kurtosis = $registry->find('geometrickurtosis');
        self::assertInstanceOf(FunctionDefinition::class, $pmf);
        self::assertInstanceOf(FunctionDefinition::class, $logPmf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $logCdf);
        self::assertInstanceOf(FunctionDefinition::class, $logSurvival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $skewness);
        self::assertInstanceOf(FunctionDefinition::class, $excessKurtosis);
        self::assertInstanceOf(FunctionDefinition::class, $kurtosis);

        self::assertSame(0.5, $pmf->invoke([0, 0.5]));
        self::assertEqualsWithDelta(log(0.125), $logPmf->invoke([2, 0.5]), 1.0e-15);
        self::assertSame(-INF, $logPmf->invoke([-1, 0.5]));
        self::assertSame(0.0, $logPmf->invoke([0, 1]));
        self::assertSame(-INF, $logPmf->invoke([1, 1]));
        self::assertSame(0.0, $pmf->invoke([2000, 0.5]));
        self::assertLessThan(0.0, $logPmf->invoke([2000, 0.5]));
        self::assertEqualsWithDelta(0.125, $pmf->invoke([2, 0.5]), 1.0e-15);
        self::assertSame(0.0, $pmf->invoke([-1, 0.5]));
        self::assertSame(0.0, $cdf->invoke([-1, 0.5]));
        self::assertSame(0.5, $cdf->invoke([0, 0.5]));
        self::assertSame(0.5, $survival->invoke([0, 0.5]));
        self::assertEqualsWithDelta(log(0.5), $logCdf->invoke([0, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(log(0.5), $logSurvival->invoke([0, 0.5]), 1.0e-15);
        self::assertEqualsWithDelta(2001.0 * log(0.5), $logSurvival->invoke([2000, 0.5]), 1.0e-12);
        self::assertEqualsWithDelta(log(1.0e-300), $logCdf->invoke([0, 1.0e-300]), 1.0e-14);
        self::assertSame(0.0, $logCdf->invoke([0, 1.0]));
        self::assertSame(0.0, $logSurvival->invoke([-1, 0.5]));
        self::assertSame(0, $inverse->invoke([0, 0.5]));
        self::assertSame(0, $inverse->invoke([0.5, 0.5]));
        self::assertSame(1, $inverse->invoke([0.75, 0.5]));
        self::assertSame(0, $inverse->invoke([0.9, 1.0]));
        self::assertSame(1.0, $mean->invoke([0.5]));
        self::assertSame(2.0, $variance->invoke([0.5]));
        self::assertEqualsWithDelta(1.5 / sqrt(0.5), $skewness->invoke([0.5]), 1.0e-14);
        self::assertEqualsWithDelta(6.5, $excessKurtosis->invoke([0.5]), 1.0e-14);
        self::assertEqualsWithDelta(9.5, $kurtosis->invoke([0.5]), 1.0e-14);
        self::assertEqualsWithDelta(sqrt(2.0), $registry->find('geometricstddev')?->invoke([0.5]), 1.0e-14);
        self::assertSame(0, $registry->find('geometricmedian')?->invoke([0.5]));
        self::assertSame(0, $registry->find('geometricmode')?->invoke([0.5]));
        self::assertEqualsWithDelta(2.0 * log(2.0), $registry->find('geometricentropy')?->invoke([0.5]), 1.0e-14);
        self::assertSame(1.0, $registry->find('geometricmoment')?->invoke([0, 0.5]));
        self::assertEqualsWithDelta(1.0, $registry->find('geometricrawmoment')?->invoke([1, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(3.0, $registry->find('geometricmoment')?->invoke([2, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(13.0, $registry->find('geometricmoment')?->invoke([3, 0.5]), 1.0e-14);
        self::assertEqualsWithDelta(2, $registry->find('geometricmedian')?->invoke([0.25]), 0.0);
        self::assertEqualsWithDelta(2.0e40, $registry->find('geometricmoment')?->invoke([2, 1.0e-20]), 1.0e27);
        $expectedGeometricMgf = 0.5 / (1.0 - 0.5 * exp(0.2));
        self::assertEqualsWithDelta(
            $expectedGeometricMgf,
            $registry->find('geometricmgf')?->invoke([0.2, 0.5]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(
            log($expectedGeometricMgf),
            $registry->find('geometriclogmgf')?->invoke([0.2, 0.5]),
            1.0e-14,
        );
        self::assertSame(0.0, $mean->invoke([1.0]));
        self::assertSame(0.0, $variance->invoke([1.0]));
        self::assertSame(0.0, $registry->find('geometricentropy')?->invoke([1.0]));
        self::assertSame(0.0, $registry->find('geometricmoment')?->invoke([4, 1.0]));
        self::assertSame(1.0, $registry->find('geometricmgf')?->invoke([100.0, 1.0]));
        self::assertSame(0.0, $registry->find('geometriclogmgf')?->invoke([100.0, 1.0]));
        foreach (['geometricskewness', 'geometricexcesskurtosis', 'geometrickurtosis'] as $name) {
            $function = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $function);
            try {
                $function->invoke([1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject a degenerate distribution.');
        }
        self::assertSame($mean->invoke([0.2]), $registry->find('geommean')?->invoke([0.2]));
        self::assertSame($variance->invoke([0.2]), $registry->find('geomvar')?->invoke([0.2]));
        foreach ([[-1, 0.5], [1.5, 0.5], [65, 0.5]] as $invalidMoment) {
            try {
                $registry->find('geometricmoment')?->invoke($invalidMoment);
            } catch (\DomainException) {
                continue;
            }
            self::fail('geometricmoment() should reject invalid moment orders.');
        }
        try {
            $registry->find('geometriclogmgf')?->invoke([log(2.0), 0.5]);
            self::fail('geometriclogmgf() should reject the convergence boundary.');
        } catch (\DomainException) {
            // The MGF diverges at its convergence boundary.
        }
        self::assertSame(
            $registry->find('geometricmgf')?->invoke([0.1, 0.2]),
            $registry->find('geommgf')?->invoke([0.1, 0.2]),
        );

        foreach ([0.1, 0.25, 0.5, 0.9] as $successProbability) {
            $firstMoment = 0.0;
            $secondMoment = 0.0;
            $lastFailureCount = (int) ceil(40.0 / $successProbability);
            for ($failures = 0; $failures <= $lastFailureCount; ++$failures) {
                $probability = $pmf->invoke([$failures, $successProbability]);
                $firstMoment += $failures * $probability;
                $secondMoment += $failures * $failures * $probability;
            }
            self::assertEqualsWithDelta(
                $mean->invoke([$successProbability]),
                $firstMoment,
                1.0e-10,
            );
            self::assertEqualsWithDelta(
                $variance->invoke([$successProbability]),
                $secondMoment - $firstMoment * $firstMoment,
                1.0e-9,
            );
        }

        foreach ([1.0e-12, 0.01, 0.1, 0.5, 0.9, 0.99, 1.0 - 1.0e-12] as $probability) {
            foreach ([1.0e-6, 0.1, 0.5, 0.9, 1.0] as $successProbability) {
                $quantile = $inverse->invoke([$probability, $successProbability]);
                self::assertGreaterThanOrEqual(
                    $probability,
                    $cdf->invoke([$quantile, $successProbability]),
                );
                self::assertLessThanOrEqual(
                    1.0 - $probability,
                    $survival->invoke([$quantile, $successProbability]),
                );
                if ($quantile > 0) {
                    self::assertGreaterThan(
                        1.0 - $probability,
                        $survival->invoke([$quantile - 1, $successProbability]),
                    );
                }
            }
        }
        foreach ([0.0, -0.1, 1.1] as $successProbability) {
            try {
                $pmf->invoke([0, $successProbability]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Geometric functions should require a success probability in (0, 1].');
        }
        try {
            $inverse->invoke([1.0, 0.5]);
        } catch (\DomainException) {
            return;
        }
        self::fail('geometricinv should reject probability one when no finite quantile exists.');
    }

    public function testTriangularDistributionHandlesInteriorAndEndpointModes(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('triangularpdf');
        $logPdf = $registry->find('triangularlogpdf');
        $cdf = $registry->find('triangularcdf');
        $survival = $registry->find('triangularccdf');
        $inverse = $registry->find('triangularinv');
        $mean = $registry->find('triangularmean');
        $variance = $registry->find('triangularvariance');
        $median = $registry->find('triangularmedian');
        $modeFunction = $registry->find('triangularmode');
        $moment = $registry->find('triangularmoment');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $modeFunction);
        self::assertInstanceOf(FunctionDefinition::class, $moment);

        self::assertSame(0.0, $pdf->invoke([-1, 0, 0.5, 1]));
        self::assertSame(0.0, $cdf->invoke([0, 0, 0.5, 1]));
        self::assertSame(1.0, $survival->invoke([0, 0, 0.5, 1]));
        self::assertSame(1.0, $cdf->invoke([1, 0, 0.5, 1]));
        self::assertSame(0.0, $survival->invoke([1, 0, 0.5, 1]));
        self::assertEqualsWithDelta(2.0, $pdf->invoke([0.5, 0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(log(2.0), $logPdf->invoke([0.5, 0, 0.5, 1]), 1.0e-15);
        self::assertSame(-INF, $logPdf->invoke([0, 0, 0.5, 1]));
        self::assertEqualsWithDelta(log(2.0), $logPdf->invoke([0, 0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5, $cdf->invoke([0.5, 0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5, $survival->invoke([0.5, 0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0, $pdf->invoke([0.5, 0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.75, $cdf->invoke([0.5, 0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0, $pdf->invoke([0.5, 0, 1, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.25, $cdf->invoke([0.5, 0, 1, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5, $mean->invoke([0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 24.0, $variance->invoke([0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(0.5, $median->invoke([0, 0.5, 1]), 1.0e-15);
        self::assertSame(0.5, $modeFunction->invoke([0, 0.5, 1]));
        self::assertSame(1.0, $moment->invoke([0, 0, 0.5, 1]));
        self::assertEqualsWithDelta(0.5, $moment->invoke([1, 0, 0.5, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 3.0, $moment->invoke([1, 0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(2.0 / 3.0, $moment->invoke([1, 0, 1, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 18.0, $variance->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 / 18.0, $variance->invoke([0, 1, 1]), 1.0e-15);
        self::assertEqualsWithDelta(1.0 - sqrt(0.5), $median->invoke([0, 0, 1]), 1.0e-15);
        self::assertEqualsWithDelta(sqrt(0.5), $median->invoke([0, 1, 1]), 1.0e-15);
        foreach ([-1, 33, 1.5] as $order) {
            try {
                $moment->invoke([$order, 0, 0.5, 1]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('triangularmoment() should reject orders outside [0, 32].');
        }

        foreach ([0.0, 0.01, 0.1, 0.25, 0.5, 0.75, 0.9, 0.99, 1.0] as $probability) {
            $parameters = [
                [0.0, 0.5, 1.0],
                [0.0, 0.0, 1.0],
                [0.0, 1.0, 1.0],
                [-3.0, 2.0, 7.0],
            ];
            foreach ($parameters as [$minimum, $mode, $maximum]) {
                $quantile = $inverse->invoke([$probability, $minimum, $mode, $maximum]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, $minimum, $mode, $maximum]),
                    2.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, $minimum, $mode, $maximum])
                        + $survival->invoke([$quantile, $minimum, $mode, $maximum]),
                    2.0e-12,
                );
            }
        }
        foreach ([[0, 0, 0], [0, -1, 1], [0, 2, 1]] as $invalid) {
            try {
                $pdf->invoke([0, ...$invalid]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('Triangular functions should reject invalid bounds or mode.');
        }
    }

    public function testGeneralizedNormalDistributionMatchesLaplaceAndNormalCases(): void
    {
        $registry = FunctionRegistry::defaults();
        $pdf = $registry->find('generalizednormalpdf');
        $logPdf = $registry->find('generalizednormallogpdf');
        $cdf = $registry->find('generalizednormalcdf');
        $survival = $registry->find('generalizednormalccdf');
        $inverse = $registry->find('generalizednormalinv');
        $mean = $registry->find('generalizednormalmean');
        $median = $registry->find('generalizednormalmedian');
        $modeFunction = $registry->find('generalizednormalmode');
        $variance = $registry->find('generalizednormalvariance');
        $entropy = $registry->find('generalizednormalentropy');
        $moment = $registry->find('generalizednormalmoment');
        $laplacePdf = $registry->find('laplacepdf');
        $laplaceCdf = $registry->find('laplacecdf');
        $normalPdf = $registry->find('normalpdf');
        $normalCdf = $registry->find('normalcdf');
        self::assertInstanceOf(FunctionDefinition::class, $pdf);
        self::assertInstanceOf(FunctionDefinition::class, $logPdf);
        self::assertInstanceOf(FunctionDefinition::class, $cdf);
        self::assertInstanceOf(FunctionDefinition::class, $survival);
        self::assertInstanceOf(FunctionDefinition::class, $inverse);
        self::assertInstanceOf(FunctionDefinition::class, $mean);
        self::assertInstanceOf(FunctionDefinition::class, $median);
        self::assertInstanceOf(FunctionDefinition::class, $modeFunction);
        self::assertInstanceOf(FunctionDefinition::class, $variance);
        self::assertInstanceOf(FunctionDefinition::class, $entropy);
        self::assertInstanceOf(FunctionDefinition::class, $moment);
        self::assertInstanceOf(FunctionDefinition::class, $laplacePdf);
        self::assertInstanceOf(FunctionDefinition::class, $laplaceCdf);
        self::assertInstanceOf(FunctionDefinition::class, $normalPdf);
        self::assertInstanceOf(FunctionDefinition::class, $normalCdf);

        foreach ([-2.0, -0.5, 0.0, 0.5, 2.0] as $value) {
            self::assertEqualsWithDelta(
                log($pdf->invoke([$value, 0, 1, 2])),
                $logPdf->invoke([$value, 0, 1, 2]),
                2.0e-14,
            );
            self::assertEqualsWithDelta(
                $laplacePdf->invoke([$value, 0, 1]),
                $pdf->invoke([$value, 0, 1, 1]),
                2.0e-14,
            );
            self::assertEqualsWithDelta(
                $laplaceCdf->invoke([$value, 0, 1]),
                $cdf->invoke([$value, 0, 1, 1]),
                2.0e-14,
            );
            self::assertEqualsWithDelta(
                $normalPdf->invoke([$value, 0, 1.0 / sqrt(2.0)]),
                $pdf->invoke([$value, 0, 1, 2]),
                2.0e-14,
            );
            self::assertEqualsWithDelta(
                $normalCdf->invoke([$value, 0, 1.0 / sqrt(2.0)]),
                $cdf->invoke([$value, 0, 1, 2]),
                2.0e-14,
            );
        }
        self::assertSame(0.0, $pdf->invoke([1.0e100, 0, 1, 2]));
        self::assertLessThan(0.0, $logPdf->invoke([1.0e100, 0, 1, 2]));

        self::assertSame(-1.5, $mean->invoke([-1.5, 2.25, 1.0]));
        self::assertSame(-1.5, $median->invoke([-1.5, 2.25, 2.0]));
        self::assertSame(-1.5, $modeFunction->invoke([-1.5, 2.25, 4.0]));
        self::assertEqualsWithDelta(2.0 * 2.25 * 2.25, $variance->invoke([0, 2.25, 1.0]), 1.0e-13);
        self::assertEqualsWithDelta(
            log(2.0 * 2.25) + 1.0,
            $entropy->invoke([0, 2.25, 1.0]),
            1.0e-14,
        );
        self::assertEqualsWithDelta(2.0 * 2.25 * 2.25, $moment->invoke([2, 0, 2.25, 1.0]), 1.0e-13);
        self::assertEqualsWithDelta(
            3.0 * (2.25 ** 4) / 4.0,
            $moment->invoke([4, 0, 2.25, 2.0]),
            1.0e-12,
        );
        self::assertSame(0.0, $registry->find('generalizednormalskewness')?->invoke([0, 1, 2]));
        self::assertEqualsWithDelta(
            3.0,
            $registry->find('generalizednormalkurtosis')?->invoke([0, 1, 2]),
            1.0e-13,
        );
        foreach ([-1, 33, 2.5] as $order) {
            try {
                $moment->invoke([$order, 0, 1, 1.0]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('generalizednormalmoment should reject orders outside [0, 32].');
        }

        self::assertEqualsWithDelta(log(0.5), $inverse->invoke([0.25, 0, 1, 1]), 2.0e-14);
        self::assertSame(0.5, $cdf->invoke([-4, -4, 3, 1.7]));
        self::assertEqualsWithDelta(
            1.0,
            $cdf->invoke([2, 0, 1, 0.5]) + $survival->invoke([2, 0, 1, 0.5]),
            2.0e-14,
        );
        self::assertSame(0.0, $cdf->invoke([-1.0e200, 0, 1, 2]));
        self::assertSame(0.0, $survival->invoke([1.0e200, 0, 1, 2]));

        foreach ([0.5, 1.0, 1.5, 2.0, 4.0] as $shape) {
            foreach ([0.01, 0.1, 0.25, 0.5, 0.75, 0.9, 0.99] as $probability) {
                $quantile = $inverse->invoke([$probability, -1.5, 2.25, $shape]);
                self::assertEqualsWithDelta(
                    $probability,
                    $cdf->invoke([$quantile, -1.5, 2.25, $shape]),
                    3.0e-12,
                );
                self::assertEqualsWithDelta(
                    1.0,
                    $cdf->invoke([$quantile, -1.5, 2.25, $shape])
                        + $survival->invoke([$quantile, -1.5, 2.25, $shape]),
                    3.0e-12,
                );
            }
        }

        foreach (
            [
                ['generalizednormalpdf', [0, 0, 0, 1]],
                ['generalizednormalcdf', [0, 0, 1, 0]],
                ['generalizednormalccdf', [0, INF, 1, 1]],
                ['generalizednormalinv', [0, 0, 1, 1]],
                ['generalizednormalinv', [1, 0, 1, 1]],
            ] as [$name, $arguments]
        ) {
            $definition = $registry->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail($name . ' should reject invalid parameters or unbounded endpoint quantiles.');
        }
    }

    public function testBernoulliNumbersValidateOrdersAndReachLargestSupportedEvenIndex(): void
    {
        $bernoulli = FunctionRegistry::defaults()->find('bernoulli');
        self::assertInstanceOf(FunctionDefinition::class, $bernoulli);
        $largest = $bernoulli->invoke([256]);
        self::assertTrue(is_finite($largest));
        self::assertLessThan(0.0, $largest);

        foreach ([-1, 1.5, 257] as $order) {
            try {
                $bernoulli->invoke([$order]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('bernoulli() should reject orders outside its supported integer domain.');
        }
    }

    public function testPartitionFunctionRejectsCountsOutsideExactIntegerRange(): void
    {
        $partition = FunctionRegistry::defaults()->find('partition');
        self::assertInstanceOf(FunctionDefinition::class, $partition);
        try {
            $partition->invoke([500]);
        } catch (\OverflowException) {
            return;
        }

        self::fail('partition() should reject results that exceed the host integer range.');
    }

    public function testStirlingNumbersRejectInvalidIndicesAndOverflow(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach ([['stirling2', [4, 5]], ['stirling1', [-1, 0]]] as [$name, $arguments]) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke($arguments);
            } catch (\DomainException) {
                continue;
            }
            self::fail($name . ' should reject invalid indices.');
        }

        $unsigned = $functions->find('stirling1unsigned');
        self::assertInstanceOf(FunctionDefinition::class, $unsigned);
        try {
            $unsigned->invoke([30, 1]);
        } catch (\OverflowException) {
            return;
        }
        self::fail('Stirling numbers should reject results that exceed the host integer range.');
    }

    public function testFibonacciAndLucasSequencesRejectOverflowAndNonIntegerIndices(): void
    {
        $functions = FunctionRegistry::defaults();
        foreach (['fibonacci', 'lucas'] as $name) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            try {
                $definition->invoke([1000]);
            } catch (\OverflowException) {
                continue;
            }
            self::fail($name . ' should reject values outside the host integer range.');
        }

        $fibonacci = $functions->find('fibonacci');
        self::assertInstanceOf(FunctionDefinition::class, $fibonacci);
        try {
            $fibonacci->invoke([1.5]);
        } catch (\DomainException) {
            return;
        }
        self::fail('fibonacci() should require an integer index.');
    }

    public function testNumberTheoryFunctionsRejectNonPositiveAndOversizedInputs(): void
    {
        $functions = FunctionRegistry::defaults();
        $names = [
            'totient',
            'divisorcount',
            'divisorsum',
            'isprime',
            'mobius',
            'radical',
            'carmichael',
            'aliquotsum',
        ];
        foreach ($names as $name) {
            $definition = $functions->find($name);
            self::assertInstanceOf(FunctionDefinition::class, $definition);
            foreach ([0, -1, 1.5, 1_000_000_000_001] as $input) {
                try {
                    $definition->invoke([$input]);
                } catch (\DomainException) {
                    continue;
                }
                self::fail($name . ' should reject non-positive, fractional, and oversized inputs.');
            }
        }
    }

    public function testBellNumbersMatchStirlingSumsAndKnownValues(): void
    {
        $functions = FunctionRegistry::defaults();
        $bell = $functions->find('bellnumber');
        $alias = $functions->find('bell');
        $stirling = $functions->find('stirling2');
        self::assertInstanceOf(FunctionDefinition::class, $bell);
        self::assertInstanceOf(FunctionDefinition::class, $alias);
        self::assertInstanceOf(FunctionDefinition::class, $stirling);

        $known = [1, 1, 2, 5, 15, 52, 203, 877, 4140, 21147, 115975];
        foreach ($known as $number => $expected) {
            self::assertSame($expected, $bell->invoke([$number]));
            self::assertSame($expected, $alias->invoke([$number]));
        }
        for ($number = 0; $number <= 12; ++$number) {
            $sum = 0;
            for ($blocks = 0; $blocks <= $number; ++$blocks) {
                $sum += $stirling->invoke([$number, $blocks]);
            }
            self::assertSame($sum, $bell->invoke([$number]));
        }
        self::assertSame(4638590332229999353, $bell->invoke([25]));
        try {
            $bell->invoke([26]);
        } catch (\OverflowException) {
            return;
        }
        self::fail('bellnumber() should reject values outside the host integer range.');
    }

    public function testDistinctPartitionsMatchOddPartGeneratingFunction(): void
    {
        $functions = FunctionRegistry::defaults();
        $distinct = $functions->find('distinctpartitions');
        $alias = $functions->find('partitiondistinct');
        self::assertInstanceOf(FunctionDefinition::class, $distinct);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        $known = [1, 1, 1, 2, 2, 3, 4, 5, 6, 8, 10, 12, 15];
        foreach ($known as $number => $expected) {
            self::assertSame($expected, $distinct->invoke([$number]));
            self::assertSame($expected, $alias->invoke([$number]));
        }
        $maximum = 50;
        $oddParts = array_fill(0, $maximum + 1, 0);
        $oddParts[0] = 1;
        for ($part = 1; $part <= $maximum; $part += 2) {
            for ($sum = $part; $sum <= $maximum; ++$sum) {
                $oddParts[$sum] += $oddParts[$sum - $part];
            }
        }
        for ($number = 0; $number <= $maximum; ++$number) {
            self::assertSame($oddParts[$number], $distinct->invoke([$number]));
        }
        try {
            $distinct->invoke([10000]);
        } catch (\OverflowException) {
            return;
        }
        self::fail('distinctpartitions() should reject results outside the host integer range.');
    }

    public function testDivisorPowerSumValidatesExponentAndDetectsOverflow(): void
    {
        $divisorsum = FunctionRegistry::defaults()->find('divisorsum');
        self::assertInstanceOf(FunctionDefinition::class, $divisorsum);
        foreach ([-1, 1.5, 65] as $exponent) {
            try {
                $divisorsum->invoke([2, $exponent]);
            } catch (\DomainException) {
                continue;
            }
            self::fail('divisorsum() should reject exponents outside its integer range.');
        }
        try {
            $divisorsum->invoke([2, 63]);
        } catch (\OverflowException) {
            return;
        }
        self::fail('divisorsum() should reject results outside the host integer range.');
    }

    public function testHurwitzZetaShiftIdentityAndDomain(): void
    {
        $functions = FunctionRegistry::defaults();
        $zeta = $functions->find('zeta');
        $hurwitz = $functions->find('hurwitzzeta');
        $alias = $functions->find('hzeta');
        self::assertInstanceOf(FunctionDefinition::class, $zeta);
        self::assertInstanceOf(FunctionDefinition::class, $hurwitz);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        self::assertEqualsWithDelta($zeta->invoke([2]), $hurwitz->invoke([2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(
            $hurwitz->invoke([2.5, 3.25]),
            3.25 ** -2.5 + $alias->invoke([2.5, 4.25]),
            2.0e-14,
        );
        self::assertEqualsWithDelta(M_PI * M_PI / 2.0 - 4.0, $hurwitz->invoke([2, 1.5]), 2.0e-14);
        self::assertEqualsWithDelta(-0.5, $hurwitz->invoke([0, 1]), 2.0e-14);
        self::assertEqualsWithDelta(0.0, $hurwitz->invoke([0, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(-1.0 / 12.0, $hurwitz->invoke([-1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(1.0 / 24.0, $hurwitz->invoke([-1, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(-1.460354508685, $hurwitz->invoke([0.5, 1]), 2.0e-10);
        self::assertEqualsWithDelta(
            (sqrt(2.0) - 1.0) * $zeta->invoke([0.5]),
            $hurwitz->invoke([0.5, 0.5]),
            2.0e-10,
        );

        foreach ([[1, 1], [2, 0], [2, -1], [INF, 1], [2, INF]] as $arguments) {
            try {
                $hurwitz->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('Hurwitz zeta should reject its pole, non-positive offsets, and non-finite inputs.');
        }
    }

    public function testDirichletBetaValuesAndDomain(): void
    {
        $beta = FunctionRegistry::defaults()->find('dirichletbeta');
        self::assertInstanceOf(FunctionDefinition::class, $beta);

        self::assertEqualsWithDelta(M_PI / 4.0, $beta->invoke([1]), 2.0e-14);
        self::assertEqualsWithDelta(0.915965594177219, $beta->invoke([2]), 2.0e-14);
        self::assertEqualsWithDelta(M_PI ** 3 / 32.0, $beta->invoke([3]), 2.0e-14);
        self::assertEqualsWithDelta(1.0, $beta->invoke([1000]), 2.0e-16);
        self::assertEqualsWithDelta(0.5, $beta->invoke([0]), 2.0e-14);
        self::assertEqualsWithDelta(0.0, $beta->invoke([-1]), 2.0e-14);
        self::assertEqualsWithDelta(-0.5, $beta->invoke([-2]), 2.0e-14);
        self::assertEqualsWithDelta(0.0, $beta->invoke([-3]), 2.0e-14);

        foreach ([INF, NAN] as $exponent) {
            try {
                $beta->invoke([$exponent]);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('dirichletbeta() should require a finite real exponent.');
        }
    }

    public function testHarmonicNumbersExtendContinuouslyToRealIndices(): void
    {
        $harmonic = FunctionRegistry::defaults()->find('harmonicnumber');
        self::assertInstanceOf(FunctionDefinition::class, $harmonic);

        self::assertEqualsWithDelta(2.0 - 2.0 * log(2.0), $harmonic->invoke([0.5]), 2.0e-14);
        self::assertEqualsWithDelta(8.0 / 3.0 - 2.0 * log(2.0), $harmonic->invoke([1.5]), 2.0e-14);
        self::assertEqualsWithDelta(4.0 - M_PI * M_PI / 3.0, $harmonic->invoke([0.5, 2]), 2.0e-13);
        self::assertEqualsWithDelta(
            (2.0 - sqrt(2.0)) * -1.460354508685 + sqrt(2.0),
            $harmonic->invoke([0.5, 0.5]),
            2.0e-10,
        );
    }

    public function testRisingFactorialSupportsRealOrders(): void
    {
        $registry = FunctionRegistry::defaults();
        $rising = $registry->find('risingfactorial');
        $pochhammer = $registry->find('pochhammer');
        self::assertInstanceOf(FunctionDefinition::class, $rising);
        self::assertInstanceOf(FunctionDefinition::class, $pochhammer);

        self::assertEqualsWithDelta(1.329340388179137, $rising->invoke([2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(1.50450555612735, $pochhammer->invoke([2.5, 0.5]), 2.0e-14);
        self::assertSame(0.0, $rising->invoke([-2, 0.5]));
        self::assertEqualsWithDelta(
            $rising->invoke([1.5, 0.5]) * $rising->invoke([2.0, 1.25]),
            $pochhammer->invoke([1.5, 1.75]),
            2.0e-14,
        );

        foreach ([[-0.5, 0.5], [2, -0.5], [INF, 0.5]] as $arguments) {
            try {
                $rising->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('risingfactorial() should reject gamma poles and non-finite or negative inputs.');
        }
    }

    public function testFallingFactorialSupportsRealOrders(): void
    {
        $falling = FunctionRegistry::defaults()->find('fallingfactorial');
        self::assertInstanceOf(FunctionDefinition::class, $falling);

        self::assertEqualsWithDelta(2.292579895051204, $falling->invoke([5, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(1.661675485223922, $falling->invoke([2.5, 0.5]), 2.0e-14);
        self::assertSame(0.0, $falling->invoke([3, 3.5]));
        self::assertSame(1, $falling->invoke([2.5, 0]));

        foreach ([[2, -0.5], [INF, 0.5], [-0.5, 0.5]] as $arguments) {
            try {
                $falling->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('fallingfactorial() should reject gamma poles and non-finite or negative inputs.');
        }
    }

    public function testConfluentHypergeometricValuesIdentitiesAndDomain(): void
    {
        $registry = FunctionRegistry::defaults();
        $hyp1f1 = $registry->find('hyp1f1');
        $alias = $registry->find('confluenthypergeometric');
        self::assertInstanceOf(FunctionDefinition::class, $hyp1f1);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        self::assertEqualsWithDelta(M_E, $hyp1f1->invoke([1, 1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(M_E - 1.0, $hyp1f1->invoke([1, 2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(1.0 - exp(-1.0), $hyp1f1->invoke([1, 2, -1]), 2.0e-14);
        self::assertEqualsWithDelta(0.0, $hyp1f1->invoke([-2, 3, 2]), 1.0e-15);
        self::assertEqualsWithDelta(1.0, $alias->invoke([0, 2, 100]), 0.0);

        foreach ([[1, 0, 2], [1, -2, 2], [INF, 1, 2], [1, 2, NAN]] as $arguments) {
            try {
                $hyp1f1->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('hyp1f1() should reject singular parameters and non-finite inputs.');
        }
    }

    public function testGaussHypergeometricIdentitiesAndRealDomain(): void
    {
        $registry = FunctionRegistry::defaults();
        $hyp2f1 = $registry->find('hyp2f1');
        $alias = $registry->find('gausshypergeometric');
        self::assertInstanceOf(FunctionDefinition::class, $hyp2f1);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        self::assertEqualsWithDelta(2.0 * log(2.0), $hyp2f1->invoke([1, 1, 2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(log(2.0), $hyp2f1->invoke([1, 1, 2, -1]), 2.0e-14);
        self::assertEqualsWithDelta(2.0, $hyp2f1->invoke([1, 1, 3, 1]), 2.0e-14);
        self::assertEqualsWithDelta(-1.0 / 3.0, $hyp2f1->invoke([-1, 2, 3, 2]), 2.0e-14);
        self::assertEqualsWithDelta(1.0, $alias->invoke([0, 1, 2, 10]), 0.0);

        foreach ([[1, 1, 0, 0.5], [1, 1, 2, 1], [1, 1, 2, 1.5], [INF, 1, 2, 0.5]] as $arguments) {
            try {
                $hyp2f1->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('hyp2f1() should reject singular parameters and values outside its real convergent domain.');
        }
    }

    public function testConfluentHypergeometricLimitValuesAndDomain(): void
    {
        $registry = FunctionRegistry::defaults();
        $hyp0f1 = $registry->find('hyp0f1');
        $alias = $registry->find('confluenthypergeometriclimit');
        self::assertInstanceOf(FunctionDefinition::class, $hyp0f1);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        self::assertEqualsWithDelta(1.0, $hyp0f1->invoke([2, 0]), 0.0);
        self::assertEqualsWithDelta(2.2795853023360673, $hyp0f1->invoke([1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(1.590636854637329, $hyp0f1->invoke([2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(0.22389077914123567, $hyp0f1->invoke([1, -1]), 2.0e-14);
        self::assertEqualsWithDelta(1.0, $alias->invoke([3, 0]), 0.0);

        foreach ([[0, 1], [-2, 1], [1, INF], [NAN, 1]] as $arguments) {
            try {
                $hyp0f1->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('hyp0f1() should reject singular parameters and non-finite inputs.');
        }
    }

    public function testGeneralizedHypergeometric3F2ValuesAndConvergenceDomain(): void
    {
        $registry = FunctionRegistry::defaults();
        $hyp3f2 = $registry->find('hyp3f2');
        $alias = $registry->find('generalizedhypergeometric3f2');
        self::assertInstanceOf(FunctionDefinition::class, $hyp3f2);
        self::assertInstanceOf(FunctionDefinition::class, $alias);

        self::assertEqualsWithDelta(1.164481052930025, $hyp3f2->invoke([1, 1, 1, 2, 2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(0.896828413847292, $hyp3f2->invoke([1, 1, 1, 2, 2, -0.5]), 2.0e-14);
        self::assertEqualsWithDelta(2.0 / 3.0, $hyp3f2->invoke([-1, 1, 1, 2, 3, 2]), 2.0e-14);
        self::assertEqualsWithDelta(1.0, $alias->invoke([0, 1, 1, 2, 3, 4]), 0.0);

        foreach ([[1, 1, 1, 2, 0, 0.5], [1, 1, 1, 1.5, 1.5, 1], [1, 1, 1, 2, 2, 1.5]] as $arguments) {
            try {
                $hyp3f2->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('hyp3f2() should reject singular denominator parameters or divergent inputs.');
        }
    }

    public function testGeneralizedHypergeometricParameterizedFamilyMatchesNamedFunctions(): void
    {
        $registry = FunctionRegistry::defaults();
        $hypergeom = $registry->find('hypergeom');
        self::assertInstanceOf(FunctionDefinition::class, $hypergeom);

        self::assertEqualsWithDelta(M_E, $hypergeom->invoke([0, 0, 1]), 2.0e-14);
        self::assertEqualsWithDelta(2.2795853023360673, $hypergeom->invoke([0, 1, 1, 1]), 2.0e-14);
        self::assertEqualsWithDelta(M_E - 1.0, $hypergeom->invoke([1, 1, 1, 2, 1]), 2.0e-14);
        self::assertEqualsWithDelta(2.0 * log(2.0), $hypergeom->invoke([2, 1, 1, 1, 2, 0.5]), 2.0e-14);
        self::assertEqualsWithDelta(
            1.164481052930025,
            $hypergeom->invoke([3, 2, 1, 1, 1, 2, 2, 0.5]),
            2.0e-14,
        );
        self::assertEqualsWithDelta(-1.0, $hypergeom->invoke([2, 0, -1, 1, 2]), 2.0e-14);

        foreach ([[0.5, 0, 1], [1, 1, 1, 2], [0, 1, 0, 1], [3, 1, 1, 1, 1, 2, 0.5]] as $arguments) {
            try {
                $hypergeom->invoke($arguments);
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            self::fail('hypergeom() should reject malformed parameter counts, singularities, and divergent series.');
        }
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
        yield 'nth root alias negative odd degree' => ['nthroot', [-8, 3], -2.0, 'float'];
        yield 'error function at zero' => ['erf', [0], 0.0, 'float'];
        yield 'error function at one' => ['erf', [1], 0.8427007929497149, 'float'];
        yield 'complementary error function at zero' => ['erfc', [0], 1.0, 'float'];
        yield 'complementary error function at one' => ['erfc', [1], 0.15729920705028513, 'float'];
        yield 'scaled complementary error function at zero' => ['erfcx', [0], 1.0, 'float'];
        yield 'log complementary error function at zero' => ['logerfc', [0], 0.0, 'float'];
        yield 'inverse log complementary error function at unit probability' => ['erfcinvlog', [0], 0.0, 'float'];
        yield 'imaginary error function at one' => ['erfi', [1], 1.6504257587975428, 'float'];
        yield 'error function derivative at zero' => ['erfprime', [0], 2.0 / \sqrt(M_PI), 'float'];
        yield 'error function derivative at one' => [
            'erfprime', [1], 2.0 / \sqrt(M_PI) * \exp(-1.0), 'float',
        ];
        yield 'error function second derivative at one' => [
            'erfsecond', [1], -4.0 / \sqrt(M_PI) * \exp(-1.0), 'float',
        ];
        yield 'error function third derivative at zero' => [
            'erfthird', [0], -4.0 / \sqrt(M_PI), 'float',
        ];
        yield 'error function third derivative at one' => [
            'erfthird', [1], 4.0 / \sqrt(M_PI) * \exp(-1.0), 'float',
        ];
        yield 'complementary error function derivative' => [
            'erfcprime', [1], -2.0 / \sqrt(M_PI) * \exp(-1.0), 'float',
        ];
        yield 'complementary error function second derivative' => [
            'erfcsecond', [1], 4.0 / \sqrt(M_PI) * \exp(-1.0), 'float',
        ];
        yield 'complementary error function third derivative at zero' => [
            'erfcthird', [0], 4.0 / \sqrt(M_PI), 'float',
        ];
        yield 'imaginary error function derivative' => [
            'erfiprime', [1], 2.0 / \sqrt(M_PI) * \exp(1.0), 'float',
        ];
        yield 'imaginary error function second derivative' => [
            'erfisecond', [1], 4.0 / \sqrt(M_PI) * \exp(1.0), 'float',
        ];
        yield 'imaginary error function third derivative' => [
            'erfithird', [1], 12.0 / \sqrt(M_PI) * \exp(1.0), 'float',
        ];
        yield 'error function derivative underflows safely in the far tail' => ['erfprime', [1000], 0.0, 'float'];
        yield 'error function second derivative underflows safely in the far tail' => [
            'erfsecond', [-1000], 0.0, 'float',
        ];
        yield 'Dawson integral at one' => ['dawson', [1], 0.5380795069127684, 'float'];
        yield 'Dawson integral at two' => ['dawson', [2], 0.301340388923792, 'float'];
        yield 'Dawson integral at five' => ['dawson', [5], 0.1021340744242769, 'float'];
        yield 'Dawson integral at ten' => ['dawson', [10], 0.0502538471875985, 'float'];
        yield 'Dawson derivative at zero' => ['dawsonprime', [0], 1.0, 'float'];
        yield 'Dawson derivative at one' => [
            'dawsonprime', [1], 1.0 - 2.0 * 0.5380795069127684, 'float',
        ];
        yield 'Dawson derivative is even' => [
            'dawsonprime', [-2], 1.0 - 4.0 * 0.301340388923792, 'float',
        ];
        yield 'Dawson derivative in asymptotic region' => [
            'dawsonprime', [10], -0.0050769437519706, 'float',
        ];
        yield 'Dawson derivative remains nonzero in far tail' => [
            'dawsonprime', [1.0e100], -5.0e-201, 'float',
        ];
        yield 'Dawson second derivative at zero' => ['dawsonsecond', [0], 0.0, 'float'];
        yield 'Dawson second derivative at one' => [
            'dawsonsecond', [1], -2.0 * 0.5380795069127684
                - 2.0 * (1.0 - 2.0 * 0.5380795069127684), 'float',
        ];
        yield 'Dawson second derivative is odd' => [
            'dawsonsecond', [-2], -(-2.0 * 0.301340388923792
                - 2.0 * 2.0 * (1.0 - 4.0 * 0.301340388923792)), 'float',
        ];
        yield 'Dawson second derivative remains nonzero in far tail' => [
            'dawsonsecond', [1.0e100], 1.0e-300, 'float',
        ];
        yield 'Dawson third derivative at zero' => ['dawsonthird', [0], -4.0, 'float'];
        yield 'Dawson third derivative at one' => [
            'dawsonthird', [1], -4.0 * (1.0 - 2.0 * 0.5380795069127684)
                - 2.0 * (-2.0 * 0.5380795069127684
                    - 2.0 * (1.0 - 2.0 * 0.5380795069127684)), 'float',
        ];
        yield 'Dawson third derivative is even' => [
            'dawsonthird', [-2], -4.0 * (1.0 - 4.0 * 0.301340388923792)
                - 4.0 * (-2.0 * 0.301340388923792
                    - 4.0 * (1.0 - 4.0 * 0.301340388923792)), 'float',
        ];
        yield 'Dawson third derivative remains nonzero in far tail' => [
            'dawsonthird', [1.0e50], -3.0e-200, 'float',
        ];
        yield 'inverse error function at one half' => ['erfinv', [0.5], 0.4769362762044699, 'float'];
        yield 'inverse error function derivative at zero' => [
            'erfinvprime', [0], \sqrt(\M_PI) / 2.0, 'float',
        ];
        yield 'inverse error function second derivative at zero' => ['erfinvsecond', [0], 0.0, 'float'];
        yield 'inverse error function derivative' => [
            'erfinvprime', [0.5], \sqrt(\M_PI) / 2.0 * \exp(0.4769362762044699 ** 2), 'float',
        ];
        yield 'inverse error function second derivative' => [
            'erfinvsecond', [0.5], \M_PI / 2.0 * 0.4769362762044699
                * \exp(2.0 * 0.4769362762044699 ** 2), 'float',
        ];
        yield 'inverse complementary error function at one and a half' => [
            'erfcinv', [1.5], -0.4769362762044699, 'float',
        ];
        yield 'inverse complementary error function derivative at one' => [
            'erfcinvprime', [1], -\sqrt(\M_PI) / 2.0, 'float',
        ];
        yield 'inverse complementary error function second derivative at one' => [
            'erfcinvsecond', [1], 0.0, 'float',
        ];
        yield 'inverse complementary error function derivative' => [
            'erfcinvprime', [1.5], -\sqrt(\M_PI) / 2.0 * \exp(0.4769362762044699 ** 2), 'float',
        ];
        yield 'inverse complementary error function second derivative' => [
            'erfcinvsecond', [1.5], \M_PI / 2.0 * (-0.4769362762044699)
                * \exp(2.0 * 0.4769362762044699 ** 2), 'float',
        ];
        yield 'Airy Ai at zero' => ['airyai', [0], 0.3550280538878172, 'float'];
        yield 'Airy Ai at one' => ['airyai', [1], 0.13529241631288147, 'float'];
        yield 'Airy Ai derivative at zero' => ['airyaiprime', [0], -0.2588194037928068, 'float'];
        yield 'Airy Ai derivative at one' => ['airyaiprime', [1], -0.1591474412967932, 'float'];
        yield 'Airy Ai second derivative at zero' => ['airyaisecond', [0], 0.0, 'float'];
        yield 'Airy Ai second derivative at one' => ['airyaisecond', [1], 0.13529241631288147, 'float'];
        yield 'Airy Ai second derivative at negative one' => [
            'airyaisecond', [-1], -0.5355608832923522, 'float',
        ];
        yield 'Airy Ai third derivative at zero' => [
            'airyaithird', [0], 0.3550280538878172, 'float',
        ];
        yield 'Airy Ai third derivative at one' => [
            'airyaithird', [1], 0.13529241631288147 - 0.1591474412967932, 'float',
        ];
        yield 'Airy Bi at zero' => ['airybi', [0], 0.6149266274460007, 'float'];
        yield 'Airy Bi at one' => ['airybi', [1], 1.2074235949528712, 'float'];
        yield 'Airy Bi derivative at zero' => ['airybiprime', [0], 0.4482883573538264, 'float'];
        yield 'Airy Bi derivative at one' => ['airybiprime', [1], 0.9324359333927756, 'float'];
        yield 'Airy Bi second derivative at zero' => ['airybisecond', [0], 0.0, 'float'];
        yield 'Airy Bi second derivative at one' => ['airybisecond', [1], 1.2074235949528712, 'float'];
        yield 'Airy Bi second derivative at negative one' => [
            'airybisecond', [-1], -0.10399738949694468, 'float',
        ];
        yield 'Airy Bi third derivative at zero' => [
            'airybithird', [0], 0.6149266274460007, 'float',
        ];
        yield 'Airy Bi third derivative at one' => [
            'airybithird', [1], 1.2074235949528712 + 0.9324359333927756, 'float',
        ];
        yield 'Airy Ai at negative one' => ['airyai', [-1], 0.5355608832923522, 'float'];
        yield 'Airy Bi at negative one' => ['airybi', [-1], 0.10399738949694468, 'float'];
        yield 'complete elliptic K at zero' => ['ellipk', [0], M_PI / 2.0, 'float'];
        yield 'complete elliptic K at one half' => ['ellipk', [0.5], 1.8540746773013719, 'float'];
        yield 'complete elliptic K parameter derivative at zero' => [
            'ellipkprime', [0], M_PI / 8.0, 'float',
        ];
        yield 'complete elliptic E parameter derivative at zero' => [
            'ellipeprime', [0], -M_PI / 8.0, 'float',
        ];
        yield 'complete elliptic K parameter derivative at one half' => [
            'ellipkprime', [0.5], 0.8472130847939791, 'float',
        ];
        yield 'complete elliptic E parameter derivative at one half' => [
            'ellipeprime', [0.5], -0.503430796253696, 'float',
        ];
        yield 'elliptic nome at zero' => ['ellipnome', [0], 0.0, 'float'];
        yield 'elliptic nome at half parameter' => ['ellipnome', [0.5], exp(-M_PI), 'float'];
        yield 'elliptic nome at one' => ['ellipnome', [1], 1.0, 'float'];
        yield 'inverse elliptic nome at zero' => ['ellipnomeinv', [0], 0.0, 'float'];
        yield 'inverse elliptic nome at half parameter nome' => [
            'ellipnomeinv', [exp(-M_PI)], 0.5, 'float',
        ];
        yield 'inverse elliptic nome at one' => ['ellipnomeinv', [1], 1.0, 'float'];
        yield 'elliptic nome small-parameter expansion' => [
            'ellipnome', [1.0e-8], 1.0e-8 / 16.0 + (1.0e-8 ** 2) / 32.0, 'float',
        ];
        yield 'complete elliptic E at zero' => ['ellipe', [0], M_PI / 2.0, 'float'];
        yield 'complete elliptic E at one half' => ['ellipe', [0.5], 1.3506438810476755, 'float'];
        yield 'complete elliptic E at one' => ['ellipe', [1], 1.0, 'float'];
        yield 'complete elliptic K at negative one' => ['ellipk', [-1], 1.3110287771460598, 'float'];
        yield 'complete elliptic E at negative one' => ['ellipe', [-1], 1.9100988945138562, 'float'];
        yield 'incomplete elliptic F at zero parameter' => ['ellipf', [M_PI / 4.0, 0], M_PI / 4.0, 'float'];
        yield 'incomplete elliptic F derivative' => [
            'ellipfprime', [0.3, 0.5], 1.0 / sqrt(1.0 - 0.5 * sin(0.3) ** 2), 'float',
        ];
        yield 'incomplete elliptic K alias derivative' => [
            'ellipkincprime', [0.3, 0.5], 1.0 / sqrt(1.0 - 0.5 * sin(0.3) ** 2), 'float',
        ];
        yield 'incomplete elliptic K alias at complete angle' => [
            'ellipkinc', [M_PI / 2.0, 0.5], 1.8540746773013719, 'float',
        ];
        yield 'incomplete elliptic E alias at complete angle' => [
            'ellipeinc', [M_PI / 2.0, 0.5], 1.3506438810476755, 'float',
        ];
        yield 'incomplete elliptic E at zero parameter' => ['ellipeinc', [-M_PI / 4.0, 0], -M_PI / 4.0, 'float'];
        yield 'incomplete elliptic E derivative' => [
            'ellipeincprime', [0.3, 0.5], sqrt(1.0 - 0.5 * sin(0.3) ** 2), 'float',
        ];
        yield 'complete elliptic Pi at zero characteristic' => ['ellippi', [0, 0.5], 1.8540746773013719, 'float'];
        yield 'complete elliptic Pi at zero parameter' => ['ellippi', [0.5, 0], 2.221441469079183, 'float'];
        yield 'incomplete elliptic Pi at zero characteristic' => [
            'ellippinc', [M_PI / 2.0, 0, 0.5], 1.8540746773013719, 'float',
        ];
        yield 'incomplete elliptic Pi at zero parameters' => ['ellippinc', [M_PI / 4.0, 0, 0], M_PI / 4.0, 'float'];
        yield 'incomplete elliptic Pi derivative' => [
            'ellippincprime', [0.3, 0.2, 0.5],
            1.0 / ((1.0 - 0.2 * sin(0.3) ** 2) * sqrt(1.0 - 0.5 * sin(0.3) ** 2)),
            'float',
        ];
        yield 'Jacobi elliptic sn at unit argument' => ['jacobisn', [1, 0.5], 0.803001824895644, 'float'];
        yield 'Jacobi elliptic cn alias at unit argument' => ['cn', [1, 0.5], 0.595976567672141, 'float'];
        yield 'Jacobi elliptic dn at unit argument' => ['dn', [1, 0.5], 0.823161001631596, 'float'];
        yield 'Jacobi amplitude at zero parameter' => ['jacobiam', [2.5, 0], 2.5, 'float'];
        yield 'Jacobi amplitude at unit parameter' => ['am', [1, 1], 0.8657694832396586, 'float'];
        yield 'Jacobi amplitude in principal interval' => [
            'jacobiam', [1, 0.5], \atan2(0.803001824895644, 0.595976567672141), 'float',
        ];
        yield 'Jacobi amplitude is odd' => [
            'am', [-1, 0.5], -\atan2(0.803001824895644, 0.595976567672141), 'float',
        ];
        yield 'Jacobi amplitude quasi-period' => [
            'jacobiam', [1 + 2 * 1.8540746773013719, 0.5],
            \atan2(0.803001824895644, 0.595976567672141) + M_PI, 'float',
        ];
        yield 'Jacobi amplitude negative quasi-period' => [
            'jacobiam', [-1 - 2 * 1.8540746773013719, 0.5],
            -\atan2(0.803001824895644, 0.595976567672141) - M_PI, 'float',
        ];
        yield 'Jacobi elliptic cd' => [
            'jacobicd', [1, 0.5], 0.595976567672141 / 0.823161001631596, 'float',
        ];
        yield 'Jacobi elliptic sd alias' => [
            'sd', [1, 0.5], 0.803001824895644 / 0.823161001631596, 'float',
        ];
        yield 'Jacobi elliptic nd' => ['jacobind', [1, 0.5], 1.0 / 0.823161001631596, 'float'];
        yield 'Jacobi elliptic dc alias' => [
            'dc', [1, 0.5], 0.823161001631596 / 0.595976567672141, 'float',
        ];
        yield 'Jacobi elliptic nc' => ['jacobinc', [1, 0.5], 1.0 / 0.595976567672141, 'float'];
        yield 'Jacobi elliptic sc alias' => [
            'sc', [1, 0.5], 0.803001824895644 / 0.595976567672141, 'float',
        ];
        yield 'Jacobi elliptic ns' => ['jacobins', [1, 0.5], 1.0 / 0.803001824895644, 'float'];
        yield 'Jacobi elliptic ds alias' => [
            'ds', [1, 0.5], 0.823161001631596 / 0.803001824895644, 'float',
        ];
        yield 'Jacobi elliptic cs' => [
            'jacobics', [1, 0.5], 0.595976567672141 / 0.803001824895644, 'float',
        ];
        yield 'Jacobi theta one vanishes at zero' => ['theta1', [0, 0.25], 0.0, 'float'];
        yield 'Jacobi theta two at zero' => ['jacobitheta2', [0, 0.25], 1.5029472612993982, 'float'];
        yield 'Jacobi theta three at zero' => ['theta3', [0, 0.25], 1.5078201298601943, 'float'];
        yield 'Jacobi theta four at zero' => ['jacobitheta4', [0, 0.25], 0.5078048710711283, 'float'];
        yield 'Jacobi theta one at quarter pi' => ['theta1', [M_PI / 4.0, 0.25], 0.9372559189805543, 'float'];
        yield 'Jacobi theta three at zero nome' => ['jacobitheta3', [0.7, 0], 1.0, 'float'];
        yield 'gamma at five' => ['gamma', [5], 24.0, 'float'];
        yield 'gamma sign at negative one half' => ['gammasign', [-0.5], -1.0, 'float'];
        yield 'gamma sign alias at negative three halves' => ['signgamma', [-1.5], 1.0, 'float'];
        yield 'log gamma at five' => ['lgamma', [5], log(24.0), 'float'];
        yield 'regularized lower gamma at one and one' => ['gammainc', [1, 1], 0.6321205588285577, 'float'];
        yield 'regularized upper gamma at one and one' => ['gammaincc', [1, 1], 0.3678794411714423, 'float'];
        yield 'log regularized lower gamma' => ['loggammainc', [1, 1], \log(1.0 - \exp(-1.0)), 'float'];
        yield 'log regularized upper gamma' => ['loggammaincc', [1, 1], -1.0, 'float'];
        yield 'log lower gamma preserves a tiny tail' => [
            'loggammainc', [2, 1.0e-200], 2.0 * \log(1.0e-200) - \log(2.0), 'float',
        ];
        yield 'log upper gamma preserves a far tail' => ['loggammaincc', [1, 1000], -1000.0, 'float'];
        yield 'log upper regularized gamma at zero' => ['loggammaincc', [2, 0], 0.0, 'float'];
        yield 'regularized lower gamma at one half and one' => ['gammainc', [0.5, 1], 0.8427007929497149, 'float'];
        yield 'regularized lower gamma at two and three' => ['gammainc', [2, 3], 0.8008517265285442, 'float'];
        yield 'inverse regularized lower gamma' => ['gammaincinv', [1, 0.6321205588285577], 1.0, 'float'];
        yield 'inverse regularized upper gamma' => ['gammainccinv', [1, 0.3678794411714423], 1.0, 'float'];
        yield 'inverse regularized lower gamma endpoint' => ['gammaincinv', [2, 0], 0.0, 'float'];
        yield 'inverse regularized upper gamma endpoint' => ['gammainccinv', [2, 1], 0.0, 'float'];
        yield 'inverse lower gamma from log probability' => [
            'gammaincinvlog', [2, -100], sqrt(2.0) * exp(-50.0), 'float',
        ];
        yield 'inverse upper gamma from deep log tail' => ['gammainccinvlog', [1, -1000], 1000.0, 'float'];
        yield 'inverse lower gamma log endpoint' => ['gammaincinvlog', [2, -1000], 0.0, 'float'];
        yield 'inverse regularized lower gamma derivative' => [
            'gammaincinvprime', [1, 0.5], 2.0, 'float',
        ];
        yield 'inverse regularized upper gamma derivative' => [
            'gammainccinvprime', [1, 0.5], -2.0, 'float',
        ];
        yield 'inverse regularized lower gamma derivative for shape two' => [
            'gammaincinvprime', [2, 1.0 - 2.0 / M_E], M_E, 'float',
        ];
        yield 'lower incomplete gamma' => ['lowerincompletegamma', [1, 1], 0.6321205588285577, 'float'];
        yield 'lower incomplete gamma alias' => ['lowergamma', [0.5, 1], 1.4936482656248544, 'float'];
        yield 'upper incomplete gamma' => ['upperincompletegamma', [1, 1], 0.3678794411714423, 'float'];
        yield 'upper incomplete gamma alias' => ['uppergamma', [2, 3], 0.1991482734714558, 'float'];
        yield 'lower incomplete gamma at zero' => ['lowergamma', [2, 0], 0.0, 'float'];
        yield 'log lower incomplete gamma' => [
            'loglowerincompletegamma', [1, 1], \log(1.0 - \exp(-1.0)), 'float',
        ];
        yield 'log upper incomplete gamma at zero' => ['loguppergamma', [2, 0], 0.0, 'float'];
        yield 'log lower incomplete gamma preserves underflow' => [
            'loglowergamma', [200, 1.0e-200], -92108.70203712837, 'float',
        ];
        yield 'lower incomplete gamma underflows only in value form' => [
            'lowergamma', [200, 1.0e-200], 0.0, 'float',
        ];
        yield 'regularized lower beta at one and one' => ['betainc', [1, 1, 0.3], 0.3, 'float'];
        yield 'regularized upper beta at one and one' => ['betaincc', [1, 1, 0.3], 0.7, 'float'];
        yield 'log regularized lower beta' => ['logbetainc', [1, 1, 0.3], \log(0.3), 'float'];
        yield 'log regularized upper beta' => ['logbetaincc', [1, 1, 0.3], \log(0.7), 'float'];
        yield 'log regularized lower beta preserves a tiny tail' => [
            'logbetainc', [2, 3, 1.0e-200], 2.0 * \log(1.0e-200) - \log(2.0) - \log(1.0 / 12.0), 'float',
        ];
        yield 'log regularized upper beta preserves a tiny tail' => [
            'logbetaincc', [3, 2, 1.0 - 1.0e-8], -35.0496020219605, 'float',
        ];
        yield 'log regularized lower beta at one' => ['logbetainc', [2, 3, 1], 0.0, 'float'];
        yield 'log regularized upper beta at zero' => ['logbetaincc', [2, 3, 0], 0.0, 'float'];
        yield 'regularized lower beta at two and three' => ['betainc', [2, 3, 0.5], 0.6875, 'float'];
        yield 'regularized beta complement at two and three' => ['betaincc', [2, 3, 0.5], 0.3125, 'float'];
        yield 'lower incomplete beta at one and one' => ['lowerincompletebeta', [1, 1, 0.3], 0.3, 'float'];
        yield 'upper incomplete beta at one and one' => ['upperincompletebeta', [1, 1, 0.3], 0.7, 'float'];
        yield 'lower incomplete beta at two and three' => [
            'lowerbeta', [2, 3, 0.5], 11.0 / 192.0, 'float',
        ];
        yield 'upper incomplete beta at two and three' => [
            'upperbeta', [2, 3, 0.5], 5.0 / 192.0, 'float',
        ];
        yield 'log lower incomplete beta' => [
            'loglowerincompletebeta', [2, 3, 0.5], \log(11.0 / 192.0), 'float',
        ];
        yield 'log upper incomplete beta' => [
            'logupperincompletebeta', [2, 3, 0.5], \log(5.0 / 192.0), 'float',
        ];
        yield 'log lower incomplete beta at complete endpoint' => [
            'loglowerbeta', [2, 3, 1], \log(1.0 / 12.0), 'float',
        ];
        yield 'log upper incomplete beta at complete endpoint' => [
            'logupperbeta', [2, 3, 0], \log(1.0 / 12.0), 'float',
        ];
        yield 'lower beta alias' => ['lowerbeta', [2, 3, 0.5], 11.0 / 192.0, 'float'];
        yield 'upper incomplete beta canonical alias' => [
            'upperincompletebeta', [2, 3, 0.5], 5.0 / 192.0, 'float',
        ];
        yield 'log lower beta alias' => [
            'loglowerbeta', [2, 3, 0.5], \log(11.0 / 192.0), 'float',
        ];
        yield 'log upper beta alias' => [
            'logupperbeta', [2, 3, 0.5], \log(5.0 / 192.0), 'float',
        ];
        yield 'inverse regularized lower beta' => ['betaincinv', [2, 3, 0.6875], 0.5, 'float'];
        yield 'inverse regularized upper beta' => ['betainccinv', [2, 3, 0.3125], 0.5, 'float'];
        yield 'inverse regularized beta lower endpoint' => ['betaincinv', [2, 3, 0], 0.0, 'float'];
        yield 'inverse regularized beta upper endpoint' => ['betainccinv', [2, 3, 0], 1.0, 'float'];
        yield 'inverse lower beta from log probability' => [
            'betaincinvlog', [1, 1, -100], exp(-100.0), 'float',
        ];
        yield 'inverse upper beta from log probability' => [
            'betainccinvlog', [1, 2, -60], 1.0 - exp(-30.0), 'float',
        ];
        yield 'inverse lower beta log endpoint' => ['betaincinvlog', [2, 3, 0], 1.0, 'float'];
        yield 'inverse upper beta log endpoint' => ['betainccinvlog', [2, 3, 0], 0.0, 'float'];
        yield 'inverse regularized lower beta derivative' => [
            'betaincinvprime', [1, 1, 0.5], 1.0, 'float',
        ];
        yield 'inverse regularized upper beta derivative' => [
            'betainccinvprime', [1, 1, 0.5], -1.0, 'float',
        ];
        yield 'inverse regularized lower beta derivative for asymmetric shapes' => [
            'betaincinvprime', [2, 3, 0.6875], 2.0 / 3.0, 'float',
        ];
        yield 'beta at two and three' => ['beta', [2, 3], 1.0 / 12.0, 'float'];
        yield 'log beta at two and three' => ['logbeta', [2, 3], log(1.0 / 12.0), 'float'];
        yield 'zeta at two' => ['zeta', [2], M_PI * M_PI / 6.0, 'float'];
        yield 'Hurwitz zeta at two and one' => ['hurwitzzeta', [2, 1], M_PI * M_PI / 6.0, 'float'];
        yield 'Hurwitz zeta half offset' => ['hzeta', [2, 0.5], M_PI * M_PI / 2.0, 'float'];
        yield 'Hurwitz zeta at three and one' => ['hurwitzzeta', [3, 1], 1.2020569031595942, 'float'];
        yield 'Hurwitz zeta at zero and one' => ['hurwitzzeta', [0, 1], -0.5, 'float'];
        yield 'Hurwitz zeta at negative one and one' => ['hurwitzzeta', [-1, 1], -1.0 / 12.0, 'float'];
        yield 'Dirichlet eta at one half' => ['eta', [0.5], 0.6048986434216304, 'float'];
        yield 'Dirichlet eta at one' => ['eta', [1], \log(2.0), 'float'];
        yield 'Dirichlet eta at two' => ['dirichleteta', [2], M_PI * M_PI / 12.0, 'float'];
        yield 'Dirichlet eta at zero' => ['eta', [0], 0.5, 'float'];
        yield 'Dirichlet eta at negative one' => ['eta', [-1], 0.25, 'float'];
        yield 'Dirichlet eta trivial zero' => ['eta', [-2], 0.0, 'float'];
        yield 'Dirichlet eta at negative three' => ['dirichleteta', [-3], -0.125, 'float'];
        yield 'Bernoulli number B0' => ['bernoulli', [0], 1.0, 'float'];
        yield 'Bernoulli number B1 convention' => ['bernoulli', [1], -0.5, 'float'];
        yield 'Bernoulli number B2' => ['bernoulli', [2], 1.0 / 6.0, 'float'];
        yield 'Bernoulli number B4' => ['bernoulli', [4], -1.0 / 30.0, 'float'];
        yield 'Bernoulli odd number above one' => ['bernoulli', [3], 0.0, 'float'];
        yield 'Bernoulli number B10' => ['bernoulli', [10], 5.0 / 66.0, 'float'];
        yield 'Euler number E0' => ['eulernumber', [0], 1, 'int'];
        yield 'Euler number odd index is zero' => ['eulernumber', [7], 0, 'int'];
        yield 'Euler number E2' => ['eulernumber', [2], -1, 'int'];
        yield 'Euler number E4' => ['eulernumber', [4], 5, 'int'];
        yield 'Euler number E10' => ['eulernumber', [10], -50521, 'int'];
        yield 'Euler number E22' => ['eulernumber', [22], -69348874393137901, 'int'];
        yield 'Bernoulli polynomial order zero' => ['bernoullipoly', [0, 7], 1.0, 'float'];
        yield 'Bernoulli polynomial order one' => ['bernoullipoly', [1, 0.25], -0.25, 'float'];
        yield 'Bernoulli polynomial order two' => ['bernoullipoly', [2, 0.5], -1.0 / 12.0, 'float'];
        yield 'Bernoulli polynomial order three' => ['bernoullipoly', [3, 0.25], 3.0 / 64.0, 'float'];
        yield 'Legendre polynomial degree zero' => ['legendrepoly', [0, 7], 1.0, 'float'];
        yield 'associated Legendre polynomial P11' => ['associatedlegendre', [1, 1, 0.5], -sqrt(0.75), 'float'];
        yield 'associated Legendre alias P22' => ['assoclegendre', [2, 2, 0.5], 2.25, 'float'];
        yield 'Legendre polynomial degree one' => ['legendrepoly', [1, 2], 2.0, 'float'];
        yield 'Legendre polynomial degree two' => ['legendrepoly', [2, 2], 5.5, 'float'];
        yield 'Legendre polynomial degree three' => ['legendrep', [3, 2], 17.0, 'float'];
        yield 'Legendre polynomial endpoint parity' => ['legendrepoly', [5, -1], -1.0, 'float'];
        yield 'Legendre polynomial bounded high-degree endpoint' => ['legendrepoly', [10000, 1], 1.0, 'float'];
        yield 'Chebyshev first-kind degree zero' => ['chebyshevt', [0, 2], 1.0, 'float'];
        yield 'Chebyshev first-kind degree three' => ['chebyshevt', [3, 0.5], -1.0, 'float'];
        yield 'Chebyshev first-kind endpoint parity' => ['chebyshevt', [5, -1], -1.0, 'float'];
        yield 'Chebyshev second-kind degree two' => ['chebyshevu', [2, 0.5], 0.0, 'float'];
        yield 'Chebyshev third-kind degree two' => ['chebyshevv', [2, 0.5], -1.0, 'float'];
        yield 'Chebyshev fourth-kind degree two' => ['chebyshevw', [2, 0.5], 1.0, 'float'];
        yield 'Chebyshev second-kind endpoint' => ['chebyshevu', [5, 1], 6.0, 'float'];
        yield 'Chebyshev second-kind negative endpoint parity' => ['chebyshevu', [5, -1], -6.0, 'float'];
        yield 'Chebyshev first-kind bounded high-degree endpoint' => ['chebyshevt', [10000, 1], 1.0, 'float'];
        yield 'physicists Hermite degree zero' => ['hermite', [0, 4], 1.0, 'float'];
        yield 'physicists Hermite degree one' => ['hermite', [1, 2], 4.0, 'float'];
        yield 'physicists Hermite degree two' => ['hermite', [2, 1], 2.0, 'float'];
        yield 'physicists Hermite degree three' => ['hermitephysicist', [3, 1], -4.0, 'float'];
        yield 'probabilists Hermite degree two' => ['hermiteprobabilist', [2, 2], 3.0, 'float'];
        yield 'probabilists Hermite degree three' => ['hermiteprobabilist', [3, 2], 2.0, 'float'];
        yield 'Laguerre degree zero' => ['laguerre', [0, 5], 1.0, 'float'];
        yield 'Laguerre degree one' => ['laguerre', [1, 2], -1.0, 'float'];
        yield 'Laguerre degree two' => ['laguerre', [2, 2], -1.0, 'float'];
        yield 'Laguerre degree three' => ['laguerre', [3, 1], -2.0 / 3.0, 'float'];
        yield 'associated Laguerre degree one' => ['laguerreassoc', [1, 1, 2], 0.0, 'float'];
        yield 'associated Laguerre degree two' => ['laguerreassoc', [2, 1, 2], -1.0, 'float'];
        yield 'Jacobi polynomial degree zero' => ['jacobipoly', [0, 2, 3, 8], 1.0, 'float'];
        yield 'Jacobi polynomial degree one' => ['jacobipoly', [1, 1, 0, 2], 3.5, 'float'];
        yield 'Gegenbauer polynomial degree two at lambda one' => ['gegenbauer', [2, 1, 0.5], 0.0, 'float'];
        yield 'ultraspherical polynomial degree three' => ['ultraspherical', [3, 0.5, 0.2], -0.28, 'float'];
        yield 'Jacobi polynomial reduces to Legendre' => ['jacobipoly', [2, 0, 0, 2], 5.5, 'float'];
        yield 'symmetric Jacobi polynomial' => ['jacobipoly', [2, 1, 1, 0.5], 0.1875, 'float'];
        yield 'Bernstein midpoint basis' => ['bernstein', [4, 2, 0.5], 0.375, 'float'];
        yield 'Bernstein left endpoint' => ['bernstein', [4, 0, 0], 1.0, 'float'];
        yield 'Bernstein right endpoint' => ['bernsteinpoly', [4, 4, 1], 1.0, 'float'];
        yield 'Bernstein basis vanishes at opposite endpoints' => ['bernstein', [4, 2, 0], 0.0, 'float'];
        yield 'Bernstein single basis element at quarter' => [
            'bernstein', [3, 1, 0.25], 3.0 * 0.25 * 0.75 ** 2, 'float',
        ];
        yield 'Legendre derivative degree zero' => ['legendrepolyprime', [0, 0.3], 0.0, 'float'];
        yield 'Legendre derivative endpoint' => ['legendrepprime', [2, 1], 3.0, 'float'];
        yield 'Legendre derivative interior' => ['legendrepolyprime', [3, 0.5], 0.375, 'float'];
        yield 'Legendre second derivative degree zero' => ['legendrepolysecond', [0, 0.3], 0.0, 'float'];
        yield 'Legendre second derivative degree one' => ['legendrepsecond', [1, -0.4], 0.0, 'float'];
        yield 'Legendre second derivative degree two' => ['legendrepolysecond', [2, 0.5], 3.0, 'float'];
        yield 'Legendre second derivative interior' => ['legendrepolysecond', [3, 0.5], 7.5, 'float'];
        yield 'Legendre second derivative endpoint' => ['legendrepsecond', [4, 1], 45.0, 'float'];
        yield 'Legendre second derivative negative endpoint' => ['legendrepsecond', [3, -1], -15.0, 'float'];
        yield 'associated Legendre derivative order zero' => [
            'associatedlegendreprime', [3, 0, 0.5], 0.375, 'float',
        ];
        yield 'associated Legendre derivative order one' => [
            'assoclegendreprime', [1, 1, 0.5], 1.0 / sqrt(3.0), 'float',
        ];
        yield 'associated Legendre derivative recurrence' => [
            'associatedlegendreprime', [2, 1, 0.5], -sqrt(3.0), 'float',
        ];
        yield 'associated Legendre derivative order two' => [
            'legendreassocprime', [2, 2, 0.5], -3.0, 'float',
        ];
        yield 'associated Legendre order two derivative at positive endpoint' => [
            'assoclegendreprime', [2, 2, 1], -6.0, 'float',
        ];
        yield 'associated Legendre order two derivative at negative endpoint' => [
            'assoclegendreprime', [2, 2, -1], 6.0, 'float',
        ];
        yield 'associated Legendre higher-order derivative endpoint' => [
            'assoclegendreprime', [4, 3, 1], 0.0, 'float',
        ];
        yield 'Chebyshev T derivative' => ['chebyshevtprime', [3, 0.5], 0.0, 'float'];
        yield 'Chebyshev U derivative' => ['chebyshevuprime', [3, 0.5], 2.0, 'float'];
        yield 'Chebyshev T second derivative degree zero' => ['chebyshevtsecond', [0, 0.4], 0.0, 'float'];
        yield 'Chebyshev T second derivative' => ['chebyshevtsecond', [3, 0.5], 12.0, 'float'];
        yield 'Chebyshev T second derivative endpoint' => ['chebyshevtsecond', [4, 1], 80.0, 'float'];
        yield 'Chebyshev U second derivative degree one' => ['chebyshevusecond', [1, 0.2], 0.0, 'float'];
        yield 'Chebyshev U second derivative' => ['chebyshevusecond', [3, 0.5], 24.0, 'float'];
        yield 'Chebyshev U second derivative endpoint' => ['chebyshevusecond', [3, 1], 48.0, 'float'];
        yield 'Chebyshev V derivative' => ['chebyshevvprime', [2, 0.5], 2.0, 'float'];
        yield 'Chebyshev V derivative at negative endpoint' => ['chebyshevvprime', [2, -1], -10.0, 'float'];
        yield 'Chebyshev W derivative' => ['chebyshevwprime', [2, 0.5], 6.0, 'float'];
        yield 'Chebyshev W derivative at negative endpoint' => ['chebyshebfourthprime', [2, -1], -6.0, 'float'];
        yield 'Chebyshev V second derivative' => ['chebyshevvsecond', [3, 0.5], 16.0, 'float'];
        yield 'Chebyshev V second derivative at endpoint' => ['chebyshevthirdsecond', [3, 1], 40.0, 'float'];
        yield 'Chebyshev W second derivative' => ['chebyshevwsecond', [3, 0.5], 32.0, 'float'];
        yield 'Chebyshev W second derivative at endpoint' => ['chebyshebfourthsecond', [3, -1], -40.0, 'float'];
        yield 'physicists Hermite derivative' => ['hermiteprime', [2, 0.5], 4.0, 'float'];
        yield 'probabilists Hermite derivative' => ['hermiteprobabilistprime', [3, 0.5], -2.25, 'float'];
        yield 'physicists Hermite second derivative' => ['hermitesecond', [2, 0.5], 8.0, 'float'];
        yield 'probabilists Hermite second derivative' => ['hermiteprobabilistsecond', [3, 0.5], 3.0, 'float'];
        yield 'Laguerre derivative' => ['laguerreprime', [2, 0.5], -1.5, 'float'];
        yield 'associated Laguerre derivative' => ['laguerreassocprime', [2, 1, 0.5], -2.5, 'float'];
        yield 'Laguerre second derivative' => ['laguerresecond', [2, 0.5], 1.0, 'float'];
        yield 'associated Laguerre second derivative' => ['laguerreassocsecond', [3, 1, 0.5], 3.5, 'float'];
        yield 'Jacobi derivative' => ['jacobipolyprime', [2, 0, 0, 0.5], 1.5, 'float'];
        yield 'Gegenbauer derivative' => ['gegenbauerprime', [2, 0.5, 0.5], 1.5, 'float'];
        yield 'Jacobi second derivative' => ['jacobipolysecond', [2, 0, 0, 0.5], 3.0, 'float'];
        yield 'Jacobi second derivative with unequal parameters' => [
            'jacobipolysecond', [2, 1, 0, 0.5], 5.0, 'float',
        ];
        yield 'Jacobi third derivative reduces Legendre cubic' => [
            'jacobipolythird', [3, 0, 0, 0.5], 15.0, 'float',
        ];
        yield 'Jacobi third derivative vanishes below degree' => [
            'jacobipolythird', [2, 0, 0, 0.5], 0.0, 'float',
        ];
        yield 'Jacobi arbitrary fourth derivative' => [
            'jacobipolyderivative', [4, 0, 0, 0.5, 4], 105.0, 'float',
        ];
        yield 'Jacobi arbitrary derivative order zero is the polynomial' => [
            'jacobipolyderivative', [2, 0, 0, 0.5, 0], -0.125, 'float',
        ];
        yield 'Jacobi derivative above degree is zero' => [
            'jacobipolyderivative', [2, 0, 0, 0.5, 3], 0.0, 'float',
        ];
        yield 'Gegenbauer second derivative' => ['gegenbauersecond', [2, 0.5, 0.5], 3.0, 'float'];
        yield 'Gegenbauer second derivative alias' => ['ultrasphericalsecond', [3, 0.5, 0.5], 7.5, 'float'];
        yield 'Gegenbauer third derivative reduces to Legendre cubic' => [
            'gegenbauerthird', [3, 0.5, 0.5], 15.0, 'float',
        ];
        yield 'ultraspherical third derivative alias' => [
            'ultrasphericalthird', [3, 0.5, 0.5], 15.0, 'float',
        ];
        yield 'Gegenbauer derivative vanishes at zero parameter' => [
            'gegenbauerthird', [4, 0, 0.5], 0.0, 'float',
        ];
        yield 'Gegenbauer arbitrary third derivative' => [
            'gegenbauerderivative', [3, 0.5, 0.5, 3], 15.0, 'float',
        ];
        yield 'ultraspherical arbitrary derivative above degree is zero' => [
            'ultrasphericalderivative', [2, 0.5, 0.5, 3], 0.0, 'float',
        ];
        yield 'zeta at three' => ['zeta', [3], 1.2020569031595942, 'float'];
        yield 'zeta at one point five' => ['zeta', [1.5], 2.612375348685488, 'float'];
        yield 'zeta at zero' => ['zeta', [0], -0.5, 'float'];
        yield 'zeta at one half' => ['zeta', [0.5], -1.4603545088095868, 'float'];
        yield 'zeta at negative one half' => ['zeta', [-0.5], -0.2078862249773546, 'float'];
        yield 'zeta at negative one' => ['zeta', [-1], -1.0 / 12.0, 'float'];
        yield 'zeta at negative two trivial zero' => ['zeta', [-2], 0.0, 'float'];
        yield 'zeta at negative three' => ['zeta', [-3], 1.0 / 120.0, 'float'];
        yield 'zeta at negative five' => ['zeta', [-5], -1.0 / 252.0, 'float'];
        yield 'zeta near zero from the left' => ['zeta', [-1.0e-12], -0.49999999999912, 'float'];
        yield 'zeta derivative at two' => ['zetaprime', [2], -0.9375482543158437, 'float'];
        yield 'zeta derivative at three' => ['zetaprime', [3], -0.1981262428856369, 'float'];
        yield 'zeta derivative at zero' => ['zetaprime', [0], -0.5 * log(2.0 * M_PI), 'float'];
        yield 'zeta derivative at one half' => ['zetaprime', [0.5], -3.922646139209151, 'float'];
        yield 'zeta derivative at negative one' => ['zetaprime', [-1], -0.1654211437004509, 'float'];
        yield 'zeta derivative at negative two' => ['zetaprime', [-2], -0.03044845705839327, 'float'];
        yield 'zeta derivative at negative three' => ['zetaprime', [-3], 0.005378576357774231, 'float'];
        yield 'zeta derivative near zero from the left' => [
            'zetaprime',
            [-1.0e-12],
            -0.91893853320244,
            'float',
        ];
        yield 'digamma at one' => ['digamma', [1], -0.5772156649015329, 'float'];
        yield 'digamma at one half' => ['digamma', [0.5], -0.5772156649015329 - 2.0 * log(2.0), 'float'];
        yield 'trigamma at one' => ['trigamma', [1], M_PI * M_PI / 6.0, 'float'];
        yield 'trigamma at one half' => ['trigamma', [0.5], M_PI * M_PI / 2.0, 'float'];
        yield 'polygamma order two at one' => ['polygamma', [2, 1], -2.4041138063191885, 'float'];
        yield 'polygamma order three at one' => ['polygamma', [3, 1], M_PI ** 4 / 15.0, 'float'];
        yield 'tetragamma at one' => ['tetragamma', [1], M_PI ** 4 / 15.0, 'float'];
        yield 'pentagamma at one' => ['pentagamma', [1], -24.0 * 1.03692775514337, 'float'];
        yield 'hexagamma at one' => ['hexagamma', [1], 8.0 * M_PI ** 6 / 63.0, 'float'];
        yield 'polygamma order two at one half' => ['polygamma', [2, 0.5], -14.0 * 1.2020569031595942, 'float'];
        yield 'polygamma order zero delegates to digamma' => ['polygamma', [0, 1], -0.5772156649015329, 'float'];
        yield 'Bessel J zero at zero' => ['besselj', [0, 0], 1.0, 'float'];
        yield 'standard normal density alias' => ['normpdf', [0], 1.0 / sqrt(2.0 * M_PI), 'float'];
        yield 'standard normal log density at mean' => [
            'normallogpdf', [0], -0.5 * \log(2.0 * M_PI), 'float',
        ];
        yield 'normal log density nonstandard parameters' => [
            'normallogpdf', [12, 10, 2], -0.5 - \log(2.0) - 0.5 * \log(2.0 * M_PI), 'float',
        ];
        yield 'normal log density tail' => [
            'normlogpdf', [10], -50.0 - 0.5 * \log(2.0 * M_PI), 'float',
        ];
        yield 'normal log density scaled opposite extremes' => [
            'normallogpdf', [\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX, \PHP_FLOAT_MAX],
            -2.0 - \log(\PHP_FLOAT_MAX) - 0.5 * \log(2.0 * M_PI), 'float',
        ];
        yield 'standard normal cdf alias' => ['normcdf', [0], 0.5, 'float'];
        yield 'standard normal log CDF at mean' => ['normallogcdf', [0], -\log(2.0), 'float'];
        yield 'standard normal log CDF tail' => ['normlogcdf', [-10], -53.23128515051246, 'float'];
        yield 'standard normal log complementary CDF tail' => [
            'normallogccdf', [10], -53.23128515051246, 'float',
        ];
        yield 'standard normal log complementary CDF beyond ordinary underflow' => [
            'normlogccdf', [40], -804.6084420137536, 'float',
        ];
        yield 'standard normal inverse cdf alias' => ['norminv', [0.5], 0.0, 'float'];
        yield 'standard normal inverse from underflowed log CDF' => [
            'normalinvlogcdf', [-804.6084420137536], -40.0, 'float',
        ];
        yield 'standard normal inverse from underflowed log survival' => [
            'normalinvlogccdf', [-804.6084420137536], 40.0, 'float',
        ];
        yield 'normal inverse from log CDF near one' => [
            'norminvlogcdf', [-1.0E-10], 6.361340902411735, 'float',
        ];
        yield 'normal inverse from log survival near one' => [
            'norminvlogccdf', [-1.0E-10], -6.361340902411735, 'float',
        ];
        yield 'shifted scaled normal inverse from log CDF' => [
            'norminvlogcdf', [-804.6084420137536, 10, 2], -70.0, 'float',
        ];
        yield 'binomial mass alias' => ['binompmf', [5, 10, 0.5], 0.24609375, 'float'];
        yield 'binomial cdf alias' => ['binomcdf', [4, 10, 0.5], 0.376953125, 'float'];
        yield 'binomial complementary cdf alias' => ['binomccdf', [4, 10, 0.5], 0.623046875, 'float'];
        yield 'binomial inverse cdf alias' => ['binominv', [0.5, 10, 0.5], 5, 'int'];
        yield 'Poisson mass alias' => ['poipmf', [2, 3], 0.22404180765538775, 'float'];
        yield 'Poisson cdf alias' => ['poicdf', [2, 3], 0.42319008112684364, 'float'];
        yield 'Poisson complementary cdf alias' => ['poiccdf', [2, 3], 0.5768099188731564, 'float'];
        yield 'Poisson inverse cdf alias' => ['poippf', [0.95, 3], 6, 'int'];
        yield 'negative binomial mass alias' => ['nbinompmf', [1, 2, 0.5], 0.25, 'float'];
        yield 'negative binomial cdf alias' => ['nbinomcdf', [1, 2, 0.5], 0.5, 'float'];
        yield 'negative binomial complementary cdf alias' => ['nbinomccdf', [1, 2, 0.5], 0.5, 'float'];
        yield 'negative binomial inverse cdf alias' => ['nbominv', [0.75, 1, 0.5], 1, 'int'];
        yield 'Bessel J zero at two' => ['besselj', [0, 2], 0.22389077914123567, 'float'];
        yield 'Bessel J derivative order zero at one' => [
            'besseljprime', [0, 1], -0.4400505857449335, 'float',
        ];
        yield 'Bessel J derivative order one at one' => [
            'besseljprime', [1, 1], 0.32514710081303305, 'float',
        ];
        yield 'Bessel J second derivative order zero at one' => [
            'besseljsecond', [0, 1], -0.32514710081303305, 'float',
        ];
        yield 'Bessel J second derivative at zero' => ['besseljsecond', [0, 0], -0.5, 'float'];
        yield 'modified Bessel I zero at zero' => ['besseli', [0, 0], 1.0, 'float'];
        yield 'modified Bessel I zero at one' => ['besseli', [0, 1], 1.2660658777520084, 'float'];
        yield 'scaled modified Bessel I order zero at one' => ['ive', [0, 1], 0.4657596075936404, 'float'];
        yield 'scaled modified Bessel I order one at one' => ['scaledbesseli', [1, 1], 0.20791041534970847, 'float'];
        yield 'scaled modified Bessel I handles negative integer argument' => [
            'ive', [1, -1], -0.20791041534970847, 'float',
        ];
        yield 'scaled modified Bessel I at large argument' => [
            'ive', [0, 1000], 0.012617240455891255, 'float',
        ];
        yield 'modified Bessel I half order at one' => [
            'besseli', [0.5, 1], sqrt(2.0 / M_PI) * sinh(1.0), 'float',
        ];
        yield 'scaled modified Bessel K order zero at one' => [
            'kve', [0, 1], 1.144463079806895, 'float',
        ];
        yield 'scaled modified Bessel K order one at one' => [
            'scaledbesselk', [1, 1], 1.636153486263258, 'float',
        ];
        yield 'scaled modified Bessel K remains finite at large argument' => [
            'kve', [0, 1000], sqrt(M_PI / 2000.0) * (
                1.0 - 1.0 / 8000.0 + 9.0 / (2.0 * 8000.0 ** 2)
                - 225.0 / (6.0 * 8000.0 ** 3) + 11025.0 / (24.0 * 8000.0 ** 4)
            ), 'float',
        ];
        yield 'modified Bessel I derivative order zero at one' => [
            'besseliprime', [0, 1], 0.565159103992485, 'float',
        ];
        yield 'modified Bessel I derivative order one at one' => [
            'besseliprime', [1, 1], 0.700906773759523, 'float',
        ];
        yield 'modified Bessel I second derivative order zero at one' => [
            'besselisecond', [0, 1], 0.700906773759523, 'float',
        ];
        yield 'modified Bessel I second derivative at zero' => ['besselisecond', [0, 0], 0.5, 'float'];
        yield 'modified Bessel I half-order derivative at one' => [
            'besseliprime', [0.5, 1], sqrt(2.0 / M_PI) * (cosh(1.0) - 0.5 * sinh(1.0)), 'float',
        ];
        yield 'log modified Bessel I half-order derivative at one' => [
            'logbesseliprime', [0.5, 1], \log(sqrt(2.0 / M_PI) * (cosh(1.0) - 0.5 * sinh(1.0))), 'float',
        ];
        yield 'log modified Bessel I derivative at large argument' => [
            'logbesseliprime', [0.5, 1000], 1000.0 - 0.5 * \log(2.0 * M_PI * 1000.0) + \log(1.0 - 1.0 / 2000.0),
            'float',
        ];
        yield 'log modified Bessel I matches representable value' => [
            'logbesseli', [0, 1], \log(1.2660658777520084), 'float',
        ];
        yield 'log modified Bessel I half order at one' => [
            'logbesseli', [0.5, 1], \log(sqrt(2.0 / M_PI) * sinh(1.0)), 'float',
        ];
        yield 'log modified Bessel I half order at large argument' => [
            'logbesseli', [0.5, 1000], 1000.0 - 0.5 * \log(2.0 * M_PI * 1000.0), 'float',
        ];
        yield 'log modified Bessel I handles tiny values' => [
            'logbesseli', [2, 1.0E-200], 2.0 * \log(0.5E-200) - \log(2.0), 'float',
        ];
        yield 'log modified Bessel I preserves overflowed tail' => [
            'logbesseli', [0, 1000], 1000.0 - 0.5 * \log(2.0 * \M_PI * 1000.0)
                + \log(1.0 + 1.0 / 8000.0 + 9.0 / (2.0 * 8000.0 ** 2)
                    + 225.0 / (6.0 * 8000.0 ** 3) + 11025.0 / (24.0 * 8000.0 ** 4)),
            'float',
        ];
        yield 'modified Bessel I negative order matches positive order' => [
            'besseli',
            [-1, 1],
            0.565159103992485,
            'float',
        ];
        yield 'Bessel Y zero at one' => ['bessely', [0, 1], 0.08825696421567697, 'float'];
        yield 'Bessel Y derivative order zero at one' => [
            'besselyprime', [0, 1], 0.7812128213002887, 'float',
        ];
        yield 'Bessel Y derivative order one at one' => [
            'besselyprime', [1, 1], 0.869469785515965, 'float',
        ];
        yield 'Bessel Y second derivative order zero at one' => [
            'besselysecond', [0, 1], -0.869469785515965, 'float',
        ];
        yield 'Bessel Y one at one' => ['bessely', [1, 1], -0.7812128213002887, 'float'];
        yield 'Bessel Y two at one' => ['bessely', [2, 1], -1.650682606816254, 'float'];
        yield 'modified Bessel K zero at one' => ['besselk', [0, 1], 0.4210244382407083, 'float'];
        yield 'modified Bessel K one at one' => ['besselk', [1, 1], 0.6019072301972346, 'float'];
        yield 'modified Bessel K two at one' => ['besselk', [2, 1], 1.6248388986351774, 'float'];
        yield 'modified Bessel K derivative order zero at one' => [
            'besselkprime', [0, 1], -0.6019072301972346, 'float',
        ];
        yield 'modified Bessel K derivative order one at one' => [
            'besselkprime', [1, 1], -1.02293166843794, 'float',
        ];
        yield 'modified Bessel K second derivative order zero at one' => [
            'besselksecond', [0, 1], 1.02293166843794, 'float',
        ];
        yield 'log modified Bessel K derivative order zero at one' => [
            'logbesselkprime', [0, 1], \log(0.6019072301972346), 'float',
        ];
        yield 'log modified Bessel K derivative order one at one' => [
            'logbesselkprime', [1, 1], \log(1.02293166843794), 'float',
        ];
        yield 'log modified Bessel K derivative preserves underflowed tail' => [
            'logbesselkprime', [0, 1000], -1000.0 + 0.5 * \log(\M_PI / 2000.0)
                + \log(1.0 + 3.0 / 8000.0 - 15.0 / (2.0 * 8000.0 ** 2)
                    + 315.0 / (6.0 * 8000.0 ** 3) - 14175.0 / (24.0 * 8000.0 ** 4)),
            'float',
        ];
        yield 'log modified Bessel K matches representable value' => [
            'logbesselk', [2, 1], \log(1.6248388986351774), 'float',
        ];
        yield 'log modified Bessel K is even in integer order' => [
            'logbesselk', [-2, 1], \log(1.6248388986351774), 'float',
        ];
        yield 'log modified Bessel K preserves underflowed tail' => [
            'logbesselk',
            [0, 1000],
            -1000.0 + 0.5 * \log(\M_PI / 2000.0) + \log(
                1.0 - 1.0 / 8000.0 + 9.0 / (2.0 * 8000.0 ** 2)
                - 225.0 / (6.0 * 8000.0 ** 3) + 11025.0 / (24.0 * 8000.0 ** 4),
            ),
            'float',
        ];
        yield 'sine' => ['sin', [\M_PI / 2], 1.0, 'float'];
        yield 'sinc first derivative at zero' => ['sincprime', [0], 0.0, 'float'];
        yield 'sinc second derivative at zero' => ['sincsecond', [0], -1.0 / 3.0, 'float'];
        yield 'sinc first derivative' => ['sincprime', [\M_PI / 2], -4.0 / (\M_PI * \M_PI), 'float'];
        yield 'sinc second derivative' => [
            'sincsecond', [\M_PI / 2], -2.0 / \M_PI + 16.0 / (\M_PI ** 3), 'float',
        ];
        yield 'normalized sinc first derivative at zero' => ['sincpiprime', [0], 0.0, 'float'];
        yield 'normalized sinc second derivative at zero' => [
            'sincpisecond', [0], -(\M_PI ** 2) / 3.0, 'float',
        ];
        yield 'normalized sinc first derivative' => ['sincpiprime', [0.5], -4.0 / \M_PI, 'float'];
        yield 'normalized sinc second derivative' => [
            'sincpisecond', [0.5], -2.0 * \M_PI + 16.0 / \M_PI, 'float',
        ];
        yield 'cosine' => ['cos', [0], 1.0, 'float'];
        yield 'tangent' => ['tan', [\M_PI / 4], 1.0, 'float'];
        yield 'secant' => ['sec', [0], 1.0, 'float'];
        yield 'cosecant' => ['csc', [\M_PI / 2], 1.0, 'float'];
        yield 'cotangent' => ['cot', [\M_PI / 4], 1.0, 'float'];
        yield 'arc secant' => ['asec', [2], \acos(0.5), 'float'];
        yield 'arc cosecant' => ['acsc', [2], \asin(0.5), 'float'];
        yield 'arc cotangent' => ['acot', [1], \M_PI / 4, 'float'];
        yield 'arc hyperbolic secant' => ['asech', [1], 0.0, 'float'];
        yield 'arc hyperbolic cosecant' => ['acsch', [1], \asinh(1), 'float'];
        yield 'arc hyperbolic cotangent' => ['acoth', [2], \atanh(0.5), 'float'];
        yield 'arc sine' => ['asin', [1], \M_PI / 2, 'float'];
        yield 'arc cosine' => ['acos', [0], \M_PI / 2, 'float'];
        yield 'arc tangent' => ['atan', [1], \M_PI / 4, 'float'];
        yield 'hyperbolic sine' => ['sinh', [0], 0.0, 'float'];
        yield 'hyperbolic cosine' => ['cosh', [0], 1.0, 'float'];
        yield 'hyperbolic tangent' => ['tanh', [0], 0.0, 'float'];
        yield 'Gudermannian at one' => ['gd', [1], 0.8657694832396586, 'float'];
        yield 'inverse Gudermannian at one' => ['invgd', [1], \asinh(\tan(1.0)), 'float'];
        yield 'Gudermannian first derivative at zero' => ['gdprime', [0], 1.0, 'float'];
        yield 'Gudermannian second derivative at zero' => ['gdsecond', [0], 0.0, 'float'];
        yield 'Gudermannian second derivative' => ['gdsecond', [\asinh(1.0)], -0.5, 'float'];
        yield 'inverse Gudermannian first derivative' => ['invgdprime', [\M_PI / 4], \sqrt(2.0), 'float'];
        yield 'inverse Gudermannian second derivative' => ['invgdsecond', [\M_PI / 4], \sqrt(2.0), 'float'];
        yield 'hyperbolic secant' => ['sech', [0], 1.0, 'float'];
        yield 'hyperbolic cosecant' => ['csch', [\asinh(1)], 1.0, 'float'];
        yield 'hyperbolic cotangent' => ['coth', [\asinh(1)], \sqrt(2.0), 'float'];
        yield 'inverse hyperbolic sine' => ['asinh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic cosine' => ['acosh', [1], 0.0, 'float'];
        yield 'inverse hyperbolic tangent' => ['atanh', [0], 0.0, 'float'];
        yield 'exponential' => ['exp', [1], \M_E, 'float'];
        yield 'Lambert W principal branch' => ['lambertw', [1], 0.5671432904097838, 'float'];
        yield 'Lambert W lower real branch' => ['lambertw', [-0.1, -1], -3.577152063957297, 'float'];
        yield 'Lambert W derivative at zero' => ['lambertwprime', [0], 1.0, 'float'];
        yield 'Lambert W principal derivative' => [
            'lambertwprime', [1], 0.5671432904097838 / (1.0 + 0.5671432904097838), 'float',
        ];
        yield 'Lambert W lower-branch derivative' => [
            'lambertwderivative', [-0.1, -1],
            -3.577152063957297 / (-0.1 * (1.0 - 3.577152063957297)), 'float',
        ];
        yield 'Lambert W derivative immediately above branch point' => [
            'lambertwprime', [-1.0 / M_E + 1.0e-16], 123004988.25378326, 'float',
        ];
        yield 'Lambert W second derivative at zero' => ['lambertwsecond', [0], -2.0, 'float'];
        yield 'Lambert W second derivative at one' => ['lambertwsecondderivative', [1], -0.21454064628214, 'float'];
        yield 'Lambert W second derivative on principal negative input' => [
            'lambertwsecond', [-0.1], -3.3704856769619, 'float',
        ];
        yield 'Lambert W second derivative on lower branch' => [
            'lambertwsecond', [-0.1, -1],
            -((-3.577152063957297 / -0.1) ** 2) * (-3.577152063957297 + 2.0)
                / (1.0 - 3.577152063957297) ** 3,
            'float',
        ];
        yield 'Lambert W third derivative at zero' => ['lambertwthird', [0], 9.0, 'float'];
        yield 'Lambert W third derivative at one' => [
            'lambertwthirdderivative', [1], 0.27366852428160127, 'float',
        ];
        yield 'Lambert W third derivative on principal negative input' => [
            'lambertwthird', [-0.1], 20.575003935757135, 'float',
        ];
        yield 'Lambert W third derivative on lower branch' => [
            'lambertwthird', [-0.1, -1], -2405.6730930785197, 'float',
        ];
        yield 'natural logarithm' => ['ln', [\M_E], 1.0, 'float'];
        yield 'logarithm' => ['log', [100, 10], 2.0, 'float'];
        yield 'base ten logarithm' => ['log10', [100], 2.0, 'float'];
        yield 'base two logarithm' => ['log2', [8], 3.0, 'float'];
        yield 'log one plus' => ['log1p', [0], 0.0, 'float'];
        yield 'log one plus minus x at zero' => ['log1pmx', [0], 0.0, 'float'];
        yield 'log one plus minus x derivative at zero' => ['log1pmxprime', [0], 0.0, 'float'];
        yield 'log one plus minus x derivative' => ['log1pmxprime', [0.5], -1.0 / 3.0, 'float'];
        yield 'log one plus minus x near zero' => ['log1pmx', [1.0e-12], -5.0e-25, 'float'];
        yield 'log one plus minus x positive' => ['log1pmx', [0.5], \log(1.5) - 0.5, 'float'];
        yield 'log one plus minus x negative' => ['log1pmx', [-0.5], \log(0.5) + 0.5, 'float'];
        yield 'log one minus exponential near zero' => ['log1mexp', [-1.0e-12], \log(1.0e-12), 'float'];
        yield 'log one minus exponential derivative' => [
            'log1mexpprime', [-1], -1.0 / \expm1(1.0), 'float',
        ];
        yield 'log one minus exponential derivative in far tail' => [
            'log1mexpprime', [-1000], 0.0, 'float',
        ];
        yield 'log one minus exponential below branch point' => [
            'log1mexp', [-1], \log(1.0 - \exp(-1.0)), 'float',
        ];
        yield 'log one minus exponential at branch point' => [
            'log1mexp', [-\log(2.0)], \log(0.5), 'float',
        ];
        yield 'log one minus exponential with underflowing exponential' => [
            'log1mexp', [-1000], 0.0, 'float',
        ];
        yield 'logaddexp avoids positive overflow' => ['logaddexp', [1000, 1000], 1000.0 + \log(2.0), 'float'];
        yield 'logaddexp handles a negligible addend' => [
            'logaddexp', [-1000, -1001], -1000.0 + \log1p(\exp(-1.0)), 'float',
        ];
        yield 'logsumexp variadic' => [
            'logsumexp', [0, 0, 0], \log(3.0), 'float',
        ];
        yield 'logsumexp preserves small values' => [
            'logsumexp', [-1000, -1001, -1002], -1000.0 + \log(1.0 + \exp(-1.0) + \exp(-2.0)), 'float',
        ];
        yield 'logsubexp handles near cancellation' => [
            'logsubexp', [1000, 999.999999999],
            1000.0 + \log(-\expm1(999.999999999 - 1000.0)), 'float',
        ];
        yield 'logsubexp with widely separated values' => ['logsubexp', [1000, -1000], 1000.0, 'float'];
        yield 'log one plus minus x near domain boundary' => [
            'log1pmx', [-0.999999], \log1p(-0.999999) + 0.999999, 'float',
        ];
        yield 'exponential minus one' => ['expm1', [0], 0.0, 'float'];
        yield 'exponential relative at zero' => ['exprel', [0], 1.0, 'float'];
        yield 'exponential relative near zero' => ['exprel', [1.0e-12], 1.0000000000005, 'float'];
        yield 'exponential relative positive' => ['exprel', [1], \expm1(1.0), 'float'];
        yield 'exponential relative negative' => ['exprel', [-1], \expm1(-1.0) / -1.0, 'float'];
        yield 'inverse exponential relative at one' => ['exprelinv', [1], 0.0, 'float'];
        yield 'inverse exponential relative positive branch' => [
            'exprelinv', [(\exp(10.0) - 1.0) / 10.0], 10.0, 'float',
        ];
        yield 'inverse exponential relative negative branch' => [
            'exprelinv', [(1.0 - \exp(-1.0))], -1.0, 'float',
        ];
        yield 'inverse exponential relative near zero' => [
            'exprelinv', [\expm1(1.0e-8) / 1.0e-8], 1.0e-8, 'float',
        ];
        yield 'inverse exponential relative near unity' => [
            'exprelinv', [1.000000000001], 2.0e-12, 'float',
        ];
        yield 'inverse exponential relative large target' => [
            'exprelinv', [(\exp(700.0) - 1.0) / 700.0], 700.0, 'float',
        ];
        yield 'inverse exponential relative large negative asymptotic' => [
            'exprelinv', [0.01], -100.0, 'float',
        ];
        yield 'exponential relative avoids premature overflow' => [
            'exprel', [710], \exp(710.0 - \log(710.0)), 'float',
        ];
        yield 'sigmoid at zero' => ['sigmoid', [0], 0.5, 'float'];
        yield 'sigmoid large positive remains finite' => ['sigmoid', [1000], 1.0, 'float'];
        yield 'sigmoid large negative remains finite' => ['sigmoid', [-1000], 0.0, 'float'];
        yield 'sigmoid is inverse to logit' => ['sigmoid', [\log(0.2 / 0.8)], 0.2, 'float'];
        yield 'sigmoid inverse' => ['sigmoidinv', [0.2], \log(0.25), 'float'];
        yield 'logistic sigmoid inverse alias' => ['logisticsigmoidinv', [0.8], \log(4.0), 'float'];
        yield 'softplus at zero' => ['softplus', [0], \log(2.0), 'float'];
        yield 'softplus derivative at zero' => ['softplusprime', [0], 0.5, 'float'];
        yield 'softplus second derivative at zero' => ['softplussecond', [0], 0.25, 'float'];
        yield 'inverse softplus at log two' => ['softplusinv', [\log(2.0)], 0.0, 'float'];
        yield 'inverse softplus negative branch' => [
            'softplusinv', [\log1p(\exp(-10.0))], -10.0, 'float',
        ];
        yield 'inverse softplus near zero' => ['softplusinv', [1.0e-300], \log(1.0e-300), 'float'];
        yield 'inverse softplus large argument' => ['softplusinv', [1000], 1000.0, 'float'];
        yield 'softplus derivative in negative tail' => ['softplusprime', [-1000], 0.0, 'float'];
        yield 'sigmoid derivative at zero' => ['sigmoidprime', [0], 0.25, 'float'];
        yield 'sigmoid second derivative at zero' => ['sigmoidsecond', [0], 0.0, 'float'];
        yield 'sigmoid second derivative away from midpoint' => [
            'sigmoidsecond', [2], -0.079962501056153, 'float',
        ];
        yield 'sigmoid derivative in positive tail' => ['sigmoidprime', [1000], 0.0, 'float'];
        yield 'logit derivative at one half' => ['logitprime', [0.5], 4.0, 'float'];
        yield 'logit derivative at one fifth' => ['logitprime', [0.2], 6.25, 'float'];
        yield 'logit second derivative at midpoint' => ['logitsecond', [0.5], 0.0, 'float'];
        yield 'logit second derivative below midpoint' => ['logitsecond', [0.2], -23.4375, 'float'];
        yield 'logit second derivative above midpoint' => ['logitsecond', [0.8], 23.4375, 'float'];
        yield 'softplus large positive remains finite' => ['softplus', [1000], 1000.0, 'float'];
        yield 'softplus large negative remains finite' => ['softplus', [-1000], 0.0, 'float'];
        yield 'softplus symmetric identity' => [
            'softplus', [-2], \log1p(\exp(-2.0)), 'float',
        ];
        yield 'logit midpoint' => ['logit', [0.5], 0.0, 'float'];
        yield 'logit asymmetric probability' => ['logit', [0.2], \log(0.25), 'float'];
        yield 'logit smallest positive probability' => ['logit', [\PHP_FLOAT_MIN], \log(\PHP_FLOAT_MIN), 'float'];
        yield 'normalized sinc at zero' => ['sinc', [0], 1.0, 'float'];
        yield 'normalized sinc at pi' => ['sinc', [M_PI], 0.0, 'float'];
        yield 'pi-normalized sinc at zero' => ['sincpi', [0], 1.0, 'float'];
        yield 'pi-normalized sinc at one half' => ['sincpi', [0.5], 2.0 / M_PI, 'float'];
        yield 'pi-normalized sinc at one' => ['sincpi', [1], 0.0, 'float'];
        yield 'pi-normalized sinc at negative one half' => ['sincpi', [-0.5], 2.0 / M_PI, 'float'];
        yield 'pi-normalized sinc near zero' => ['sincpi', [1.0e-8], 1.0 - M_PI ** 2 * 1.0e-16 / 6.0, 'float'];
        yield 'cosine quotient at zero' => ['cosc', [0], 0.5, 'float'];
        yield 'cosine quotient first derivative at zero' => ['coscprime', [0], 0.0, 'float'];
        yield 'cosine quotient second derivative at zero' => ['coscsecond', [0], -1.0 / 12.0, 'float'];
        yield 'cosine quotient first derivative' => [
            'coscprime', [\M_PI], -4.0 / (\M_PI ** 3), 'float',
        ];
        yield 'cosine quotient second derivative' => [
            'coscsecond', [\M_PI], 12.0 / (\M_PI ** 4) - 1.0 / (\M_PI ** 2), 'float',
        ];
        yield 'normalized cosine quotient first derivative at zero' => ['coscpiprime', [0], 0.0, 'float'];
        yield 'normalized cosine quotient second derivative at zero' => [
            'coscpisecond', [0], -(\M_PI ** 2) / 12.0, 'float',
        ];
        yield 'normalized cosine quotient first derivative' => [
            'coscpiprime', [1], -4.0 / (\M_PI ** 2), 'float',
        ];
        yield 'normalized cosine quotient second derivative' => [
            'coscpisecond', [1], -1.0 + 12.0 / (\M_PI ** 2), 'float',
        ];
        yield 'pi-normalized cosine quotient at zero' => ['coscpi', [0], 0.5, 'float'];
        yield 'normalized hyperbolic sinc at zero' => ['sinhc', [0], 1.0, 'float'];
        yield 'normalized hyperbolic sinc at one' => ['sinhc', [1], 1.1752011936438014, 'float'];
        yield 'pi-normalized hyperbolic sinc at zero' => ['sinhcpi', [0], 1.0, 'float'];
        yield 'pi-normalized hyperbolic sinc at one half' => ['sinhcpi', [0.5], 1.4650523833366, 'float'];
        yield 'pi-normalized hyperbolic sinc is even' => ['sinhcpi', [-0.5], 1.4650523833366, 'float'];
        yield 'hyperbolic sinc first derivative at zero' => ['sinhcprime', [0], 0.0, 'float'];
        yield 'hyperbolic sinc second derivative at zero' => ['sinhcsecond', [0], 1.0 / 3.0, 'float'];
        yield 'hyperbolic sinc first derivative' => ['sinhcprime', [1], 1.0 / \M_E, 'float'];
        yield 'hyperbolic sinc second derivative' => [
            'sinhcsecond', [1], (\M_E - 5.0 / \M_E) / 2.0, 'float',
        ];
        yield 'pi-normalized hyperbolic sinc first derivative at zero' => [
            'sinhcpiprime', [0], 0.0, 'float',
        ];
        yield 'pi-normalized hyperbolic sinc second derivative at zero' => [
            'sinhcpisecond', [0], \M_PI ** 2 / 3.0, 'float',
        ];
        yield 'pi-normalized hyperbolic sinc first derivative' => [
            'sinhcpiprime', [1.0 / \M_PI], \M_PI / \M_E, 'float',
        ];
        yield 'pi-normalized hyperbolic sinc second derivative' => [
            'sinhcpisecond', [1.0 / \M_PI], \M_PI ** 2 * (\M_E - 5.0 / \M_E) / 2.0, 'float',
        ];
        yield 'pi-normalized hyperbolic sinc near zero' => [
            'sinhcpi', [1.0e-8], 1.0 + M_PI ** 2 * 1.0e-16 / 6.0, 'float',
        ];
        yield 'atan2' => ['atan2', [1, 1], \M_PI / 4, 'float'];
        yield 'hypotenuse' => ['hypot', [3, 4], 5.0, 'float'];
        yield 'sign' => ['sign', [-4], -1, 'int'];
        yield 'minimum' => ['min', [4, 2, 8], 2, 'int'];
        yield 'maximum' => ['max', [4, 2, 8], 8, 'int'];
        yield 'clamp below range' => ['clamp', [-2, 0, 10], 0, 'int'];
        yield 'clamp within range' => ['clamp', [4, 0, 10], 4, 'int'];
        yield 'clamp above range' => ['clamp', [12, 0, 10], 10, 'int'];
        yield 'linear interpolation' => ['lerp', [10, 20, 0.25], 12.5, 'float'];
        yield 'linear interpolation preserves opposite extreme endpoints' => [
            'lerp', [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX, 0.5], 0.0, 'float',
        ];
        yield 'inverse linear interpolation' => ['invlerp', [10, 20, 15], 0.5, 'float'];
        yield 'inverse linear interpolation with extreme endpoints' => [
            'invlerp', [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX, 0], 0.5, 'float',
        ];
        yield 'sum preserves integers' => ['sum', [4, -2, 8], 10, 'int'];
        yield 'sum uses compensated floating point' => ['sum', [1.0e16, 1, -1.0e16], 1.0, 'float'];
        yield 'sum promotes overflowing integer total' => [
            'sum', [\PHP_INT_MAX, 1, -\PHP_INT_MAX], 1.0, 'float',
        ];
        yield 'product preserves integers' => ['product', [2, -3, 4], -24, 'int'];
        yield 'product returns zero before multiplying' => ['product', [\PHP_INT_MAX, 2, 0], 0, 'int'];
        yield 'product supports floating point' => ['product', [0.5, 8, -2], -8.0, 'float'];
        yield 'arithmetic mean alias' => ['mean', [1, 2, 6], 3.0, 'float'];
        yield 'arithmetic mean with signed values' => ['arithmeticmean', [-10, 2, 8], 0.0, 'float'];
        yield 'arithmetic mean large values' => ['mean', [1.0e308, 1.0e308], 1.0e308, 'float'];
        yield 'power mean order zero is geometric mean' => ['powermean', [0, 1, 4, 16], 4.0, 'float'];
        yield 'power mean order one is arithmetic mean' => ['powermean', [1, 1, 4, 16], 7.0, 'float'];
        yield 'power mean order two is quadratic mean' => ['powermean', [2, 3, 4], \sqrt(12.5), 'float'];
        yield 'power mean order negative one is harmonic mean' => ['powermean', [-1, 1, 2, 4], 12 / 7, 'float'];
        yield 'power mean positive order includes zero' => ['powermean', [2, 0, 3, 4], \sqrt(25.0 / 3.0), 'float'];
        yield 'power mean handles near-zero order stably' => ['powermean', [1.0e-120, 1, 4, 16], 4.0, 'float'];
        yield 'power mean stays finite for extreme values' => [
            'powermean', [2, 1.0e308, 1.0e308], 1.0e308, 'float',
        ];
        yield 'geometric mean' => ['geometricmean', [1, 4, 16], 4.0, 'float'];
        yield 'geometric mean with zero' => ['geometricmean', [0, 4, 16], 0.0, 'float'];
        yield 'harmonic mean' => ['harmonicmean', [1, 2, 4], 12 / 7, 'float'];
        yield 'harmonic mean with zero' => ['harmonicmean', [0, 4, 16], 0.0, 'float'];
        yield 'quadratic mean' => ['quadraticmean', [3, 4], \sqrt(12.5), 'float'];
        yield 'root mean square alias' => ['rms', [3, 4], \sqrt(12.5), 'float'];
        yield 'logarithmic mean of one and e' => ['logarithmicmean', [1, M_E], M_E - 1.0, 'float'];
        yield 'logarithmic mean symmetric inputs' => ['logmean', [8, 2], 6.0 / \log(4.0), 'float'];
        yield 'logarithmic mean equal inputs' => ['logmean', [3.5, 3.5], 3.5, 'float'];
        yield 'logarithmic mean near equal inputs' => [
            'logmean', [1.0, 1.000000000001], 1.0000000000005, 'float',
        ];
        yield 'logarithmic mean extreme positive values' => [
            'logmean', [\PHP_FLOAT_MIN, \PHP_FLOAT_MAX],
            (\PHP_FLOAT_MAX - \PHP_FLOAT_MIN) / (\log(\PHP_FLOAT_MAX) - \log(\PHP_FLOAT_MIN)), 'float',
        ];
        yield 'median odd count' => ['median', [9, 1, 5], 5.0, 'float'];
        yield 'median even count' => ['median', [9, 1, 5, 3], 4.0, 'float'];
        yield 'median large opposite values' => ['median', [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX], 0.0, 'float'];
        yield 'mean absolute deviation' => ['meanabsolutedeviation', [1, 2, 9], 10.0 / 3.0, 'float'];
        yield 'mean absolute deviation alias' => ['meanabsdev', [-1, 1], 1.0, 'float'];
        yield 'mean absolute deviation remains finite at extreme values' => [
            'meanabsolutedeviation', [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX], \PHP_FLOAT_MAX, 'float',
        ];
        yield 'standard error of the mean' => ['standarderror', [1, 2, 3], 1.0 / \sqrt(3.0), 'float'];
        yield 'standard error alias' => ['sem', [1, 2, 3], 1.0 / \sqrt(3.0), 'float'];
        yield 'coefficient of variation' => ['coefficientvariation', [1, 2, 3], \sqrt(2.0 / 3.0) / 2.0, 'float'];
        yield 'relative standard deviation alias' => ['rsd', [2, 4, 6], \sqrt(8.0 / 3.0) / 4.0, 'float'];
        yield 'quantile first endpoint' => ['quantile', [0.0, 4, 1, 9], 1.0, 'float'];
        yield 'quantile last endpoint' => ['quantile', [1.0, 4, 1, 9], 9.0, 'float'];
        yield 'quantile type seven interpolation' => ['quantile', [0.25, 1, 2, 10, 20], 1.75, 'float'];
        yield 'quantile even median' => ['quantile', [0.5, 1, 2, 10, 20], 6.0, 'float'];
        yield 'interquartile range' => ['iqr', [1, 2, 3, 4], 1.5, 'float'];
        yield 'interquartile range singleton' => ['iqr', [4], 0.0, 'float'];
        yield 'median absolute deviation' => ['mad', [1, 2, 2, 4, 100], 1.0, 'float'];
        yield 'median absolute deviation even sample' => ['mad', [1, 2, 3, 4], 1.0, 'float'];
        yield 'median absolute deviation singleton' => ['mad', [7], 0.0, 'float'];
        yield 'median absolute deviation large opposite values' => [
            'mad', [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX], \PHP_FLOAT_MAX, 'float',
        ];
        yield 'central moment order zero' => ['centralmoment', [0, 1, 2, 3], 1.0, 'float'];
        yield 'central moment order one' => ['centralmoment', [1, 1, 2, 3], 0.0, 'float'];
        yield 'central moment variance' => ['centralmoment', [2, 1, 2, 3], 2 / 3, 'float'];
        yield 'central moment third order' => ['centralmoment', [3, 1, 2, 4], 20 / 27, 'float'];
        yield 'central moment fourth order' => ['centralmoment', [4, -1, 0, 1], 2 / 3, 'float'];
        yield 'symmetric population skewness' => ['skewness', [1, 2, 3], 0.0, 'float'];
        yield 'asymmetric population skewness' => ['skewness', [1, 2, 4], 10 / (7 * \sqrt(14)), 'float'];
        yield 'sample-adjusted skewness' => [
            'sampleskewness', [1, 2, 4], \sqrt(6) * 10 / (7 * \sqrt(14)), 'float',
        ];
        yield 'Pearson population kurtosis' => ['kurtosis', [-1, 0, 1], 1.5, 'float'];
        yield 'excess population kurtosis' => ['excesskurtosis', [-1, 0, 1], -1.5, 'float'];
        yield 'sample-adjusted excess kurtosis' => ['sampleexcesskurtosis', [1, 2, 3, 4], -1.2, 'float'];
        yield 'normal-like kurtosis remains stable at large scale' => [
            'kurtosis', [-1.0e300, 0, 1.0e300], 1.5, 'float',
        ];
        yield 'population variance' => ['variance', [1, 2, 3], 2 / 3, 'float'];
        yield 'population covariance' => ['covariance', [1, 2, 2, 4, 3, 6], 4 / 3, 'float'];
        yield 'sample covariance' => ['samplecovariance', [1, 2, 2, 4, 3, 6], 2.0, 'float'];
        yield 'Pearson correlation' => ['correlation', [1, 2, 2, 4, 3, 6], 1.0, 'float'];
        yield 'negative Pearson correlation' => ['correlation', [1, 6, 2, 4, 3, 2], -1.0, 'float'];
        yield 'correlation stable at large scale' => [
            'correlation', [-1.0e300, -2.0e300, 0, 0, 1.0e300, 2.0e300], 1.0, 'float',
        ];
        yield 'weighted covariance' => [
            'weightedcovariance', [1, 2, 1, 2, 4, 3, 3, 6, 2], 17 / 18, 'float',
        ];
        yield 'weighted correlation' => [
            'weightedcorrelation', [1, 2, 1, 2, 4, 3, 3, 6, 2], 1.0, 'float',
        ];
        yield 'population variance singleton' => ['variance', [7], 0.0, 'float'];
        yield 'population standard deviation' => ['stddev', [1, 2, 3], \sqrt(2 / 3), 'float'];
        yield 'standard deviation alias' => ['standarddeviation', [1, 2, 3], \sqrt(2 / 3), 'float'];
        yield 'sample variance' => ['samplevariance', [1, 2, 3], 1.0, 'float'];
        yield 'sample standard deviation' => ['samplestddev', [1, 2, 3], 1.0, 'float'];
        yield 'stable standard deviation of large values' => [
            'stddev', [-1.0e308, 1.0e308], 1.0e308, 'float',
        ];
        yield 'weighted arithmetic mean' => ['weightedmean', [10, 1, 20, 3], 17.5, 'float'];
        yield 'weighted arithmetic mean alias' => ['weightedarithmeticmean', [1, 1, 2, 3], 1.75, 'float'];
        yield 'weighted geometric mean' => ['weightedgeometricmean', [1, 1, 4, 3], \pow(4.0, 0.75), 'float'];
        yield 'weighted geometric mean ignores zero-weight zero' => [
            'weightedgeometricmean', [0, 0, 4, 1], 4.0, 'float',
        ];
        yield 'weighted harmonic mean' => ['weightedharmonicmean', [1, 1, 4, 3], 16 / 7, 'float'];
        yield 'weighted quadratic mean' => ['weightedquadraticmean', [3, 1, 4, 3], \sqrt(14.25), 'float'];
        yield 'weighted root mean square alias' => ['weightedrms', [3, 1, 4, 3], \sqrt(14.25), 'float'];
        yield 'floor' => ['floor', [-1.2], -2.0, 'float'];
        yield 'ceiling' => ['ceil', [-1.2], -1.0, 'float'];
        yield 'round positive halfway' => ['round', [2.5], 3.0, 'float'];
        yield 'round negative halfway' => ['round', [-2.5], -3.0, 'float'];
        yield 'mod function' => ['mod', [-7, 3], -1, 'int'];
        yield 'modulo alias' => ['modulo', [-7, 3], -1, 'int'];
        yield 'greatest common divisor' => ['gcd', [84, 30], 6, 'int'];
        yield 'greatest common divisor with minimum integer' => [
            'gcd', [\PHP_INT_MIN, -1], 1, 'int',
        ];
        yield 'greatest common divisor at exact floating lower bound' => [
            'gcd', [-(2.0 ** (\PHP_INT_SIZE * 8 - 1)), 1], 1, 'int',
        ];
        yield 'least common multiple' => ['lcm', [12, 18], 36, 'int'];
        yield 'multiplicative order modulo a prime' => ['multiplicativeorder', [2, 7], 3, 'int'];
        yield 'multiplicative order equals totient' => ['multiplicativeorder', [3, 7], 6, 'int'];
        yield 'multiplicative order normalizes negative base' => ['multiplicativeorder', [-2, 7], 6, 'int'];
        yield 'multiplicative order for a composite modulus' => ['multiplicativeorder', [3, 8], 2, 'int'];
        yield 'modorder alias' => ['modorder', [2, 7], 3, 'int'];
        yield 'primitive root modulo a prime' => ['isprimitiveroot', [3, 7], 1, 'int'];
        yield 'non-primitive unit modulo a prime' => ['isprimitiveroot', [2, 7], 0, 'int'];
        yield 'primitive root modulo a power of two' => ['isprimitiveroot', [3, 8], 0, 'int'];
        yield 'primitive root normalizes negative residue' => ['isprimitiveroot', [-4, 7], 1, 'int'];
        yield 'non-coprime residue is not a primitive root' => ['isprimitiveroot', [2, 8], 0, 'int'];
        yield 'modular inverse modulo a prime' => ['modinverse', [3, 11], 4, 'int'];
        yield 'modular inverse normalizes negative Euclidean coefficient' => ['modinverse', [2, 5], 3, 'int'];
        yield 'modular inverse for negative base' => ['modinverse', [-3, 11], 7, 'int'];
        yield 'modular inverse modulo a composite' => ['modinverse', [5, 12], 5, 'int'];
        yield 'modinv alias' => ['modinv', [3, 11], 4, 'int'];
        yield 'Jacobi symbol with composite modulus' => ['jacobisymbol', [2, 15], 1, 'int'];
        yield 'Jacobi symbol non-residue' => ['jacobisymbol', [3, 7], -1, 'int'];
        yield 'Jacobi symbol negative numerator' => ['jacobisymbol', [-1, 7], -1, 'int'];
        yield 'Jacobi symbol zero for shared factor' => ['jacobisymbol', [6, 15], 0, 'int'];
        yield 'Jacobi symbol modulo one' => ['jacobisymbol', [42, 1], 1, 'int'];
        yield 'Legendre symbol quadratic residue' => ['legendresymbol', [2, 7], 1, 'int'];
        yield 'Legendre symbol non-residue' => ['legendresymbol', [3, 7], -1, 'int'];
        yield 'Legendre symbol zero for divisible numerator' => ['legendresymbol', [7, 7], 0, 'int'];
        yield 'Kronecker symbol agrees with Jacobi for odd positive denominator' => [
            'kroneckersymbol', [2, 15], 1, 'int',
        ];
        yield 'Kronecker symbol for odd negative numerator over negative one' => [
            'kroneckersymbol', [-1, -1], -1, 'int',
        ];
        yield 'Kronecker symbol for an odd numerator over a power of two' => [
            'kroneckersymbol', [5, 8], -1, 'int',
        ];
        yield 'Kronecker symbol positive residue modulo a power of two' => [
            'kroneckersymbol', [7, 8], 1, 'int',
        ];
        yield 'Kronecker symbol zero for even numerator and even denominator' => [
            'kroneckersymbol', [2, 8], 0, 'int',
        ];
        yield 'Kronecker symbol with zero denominator and unit numerator' => [
            'kroneckersymbol', [-1, 0], 1, 'int',
        ];
        yield 'Kronecker symbol with zero denominator and non-unit numerator' => [
            'kroneckersymbol', [3, 0], 0, 'int',
        ];
        yield 'kronecker alias' => ['kronecker', [3, -5], -1, 'int'];
        yield 'Euler totient of one' => ['totient', [1], 1, 'int'];
        yield 'Euler totient of a prime power' => ['totient', [9], 6, 'int'];
        yield 'Euler totient of a composite' => ['totient', [36], 12, 'int'];
        yield 'Euler totient alias' => ['eulerphi', [9], 6, 'int'];
        yield 'cototient of one' => ['cototient', [1], 0, 'int'];
        yield 'cototient of a prime' => ['cototient', [13], 1, 'int'];
        yield 'cototient of a composite' => ['cototient', [12], 8, 'int'];
        yield 'Jordan totient order one' => ['jordan', [36, 1], 12, 'int'];
        yield 'Jordan totient order two' => ['jordan', [12, 2], 96, 'int'];
        yield 'Jordan totient order three' => ['jordan', [12, 3], 1456, 'int'];
        yield 'Jordan totient of one' => ['jordan', [1, 64], 1, 'int'];
        yield 'Dedekind psi of one' => ['dedekindpsi', [1], 1, 'int'];
        yield 'Dedekind psi' => ['dedekindpsi', [12], 24, 'int'];
        yield 'divisor count of one' => ['divisorcount', [1], 1, 'int'];
        yield 'divisor count' => ['divisorcount', [36], 9, 'int'];
        yield 'unitary divisor count of one' => ['unitarydivisorcount', [1], 1, 'int'];
        yield 'unitary divisor count' => ['unitarydivisorcount', [36], 4, 'int'];
        yield 'divisor sum of one' => ['divisorsum', [1], 1, 'int'];
        yield 'divisor sum' => ['divisorsum', [36], 91, 'int'];
        yield 'unitary divisor sum of one' => ['unitarydivisorsum', [1], 1, 'int'];
        yield 'unitary divisor sum' => ['unitarydivisorsum', [36], 50, 'int'];
        yield 'unitary aliquot sum' => ['unitaryaliquotsum', [36], 14, 'int'];
        yield 'zero-order divisor sum' => ['divisorsum', [36, 0], 9, 'int'];
        yield 'square divisor sum' => ['divisorsum', [12, 2], 210, 'int'];
        yield 'sigma function' => ['sigma', [12, 2], 210, 'int'];
        yield 'sigma of one at maximum order' => ['sigma', [1, 64], 1, 'int'];
        yield 'one is not prime' => ['isprime', [1], 0, 'int'];
        yield 'prime number' => ['isprime', [97], 1, 'int'];
        yield 'composite number' => ['isprime', [91], 0, 'int'];
        yield 'prime count below two' => ['primecount', [1], 0, 'int'];
        yield 'prime count through two' => ['primecount', [2], 1, 'int'];
        yield 'prime count through ten' => ['primecount', [10], 4, 'int'];
        yield 'first prime' => ['nthprime', [1], 2, 'int'];
        yield 'sixth prime' => ['nthprime', [6], 13, 'int'];
        yield 'hundredth prime' => ['nthprime', [100], 541, 'int'];
        yield 'ten thousandth prime' => ['nthprime', [10000], 104729, 'int'];
        yield 'millionth prime' => ['nthprime', [1000000], 15485863, 'int'];
        yield 'next prime after zero' => ['nextprime', [0], 2, 'int'];
        yield 'next prime after prime is strict' => ['nextprime', [13], 17, 'int'];
        yield 'next prime across composite run' => ['nextprime', [24], 29, 'int'];
        yield 'previous prime before prime is strict' => ['prevprime', [13], 11, 'int'];
        yield 'previous prime alias' => ['previousprime', [20], 19, 'int'];
        yield 'previous prime before four' => ['prevprime', [4], 3, 'int'];
        yield 'Goldbach pairs of four' => ['goldbachcount', [4], 1, 'int'];
        yield 'Goldbach pairs of six' => ['goldbachcount', [6], 1, 'int'];
        yield 'Goldbach pairs of ten' => ['goldbachcount', [10], 2, 'int'];
        yield 'Goldbach pairs of twenty' => ['goldbachcount', [20], 2, 'int'];
        yield 'Goldbach pairs of one hundred' => ['goldbachcount', [100], 6, 'int'];
        yield 'prime count through one hundred' => ['primepi', [100], 25, 'int'];
        yield 'prime count through one million' => ['primecount', [1000000], 78498, 'int'];
        yield 'prime count at supported upper bound' => ['primecount', [10000000], 664579, 'int'];
        yield 'logarithmic integral below one' => ['logintegral', [0.5], -0.3786710430610878, 'float'];
        yield 'dilogarithm at one half' => ['dilog', [0.5], M_PI * M_PI / 12.0 - log(2.0) ** 2 / 2.0, 'float'];
        yield 'dilogarithm alias at negative one' => ['li2', [-1], -M_PI * M_PI / 12.0, 'float'];
        yield 'logarithmic integral at two' => ['li', [2], 1.0451637801174928, 'float'];
        yield 'logarithmic integral at ten' => ['logintegral', [10], 6.165599504787298, 'float'];
        yield 'logarithmic integral at one hundred' => ['li', [100], 30.12614158407963, 'float'];
        yield 'logarithmic integral near zero' => ['li', [1.0e-300], -1.4455586289197e-303, 'float'];
        yield 'exponential integral Ei at one' => ['ei', [1], 1.8951178163559368, 'float'];
        yield 'exponential integral Ei at negative one' => ['ei', [-1], -0.21938393439552027, 'float'];
        yield 'exponential integral Ei at five' => ['ei', [5], 40.18527535580318, 'float'];
        yield 'exponential integral Ei at negative five' => ['ei', [-5], -0.0011482955912753257, 'float'];
        yield 'exponential integral derivative at one' => ['eiprime', [1], M_E, 'float'];
        yield 'exponential integral derivative at negative one' => [
            'exponentialintegralprime', [-1], -1.0 / M_E, 'float',
        ];
        yield 'entire exponential integral at zero' => ['ein', [0], 0.0, 'float'];
        yield 'entire exponential integral at one' => ['ein', [1], 0.796599599297053, 'float'];
        yield 'entire exponential integral at negative one' => ['ein', [-1], -1.3179021514544038, 'float'];
        yield 'entire exponential integral at ten' => ['ein', [10], 2.879804914864508, 'float'];
        yield 'entire exponential integral derivative at zero' => ['einprime', [0], 1.0, 'float'];
        yield 'entire exponential integral derivative at one' => ['einprime', [1], 1.0 - 1.0 / M_E, 'float'];
        yield 'entire exponential integral derivative at negative one' => [
            'entireexponentialintegralprime', [-1], M_E - 1.0, 'float',
        ];
        yield 'sine integral at one' => ['si', [1], 0.946083070367183, 'float'];
        yield 'sine integral at five' => ['sineintegral', [5], 1.549931244944674, 'float'];
        yield 'sine integral at ten' => ['si', [10], 1.658347594218874, 'float'];
        yield 'cosine integral at one' => ['ci', [1], 0.33740392290096816, 'float'];
        yield 'cosine integral at five' => ['cosineintegral', [5], -0.190029749656644, 'float'];
        yield 'cosine integral at ten' => ['ci', [10], -0.04545643300445537, 'float'];
        yield 'entire cosine integral at one' => ['cin', [1], 0.2398117420005647, 'float'];
        yield 'entire cosine integral is even' => ['cosineintegralentire', [-2], 0.8473820166866132, 'float'];
        yield 'entire cosine integral at zero' => ['cin', [0], 0.0, 'float'];
        yield 'sine integral derivative at zero' => ['siprime', [0], 1.0, 'float'];
        yield 'sine integral derivative alias' => ['sineintegralprime', [1], \sin(1.0), 'float'];
        yield 'cosine integral derivative' => ['ciprime', [2], \cos(2.0) / 2.0, 'float'];
        yield 'entire cosine integral derivative at zero' => ['cinprime', [0], 0.0, 'float'];
        yield 'entire cosine integral derivative alias' => [
            'cosineintegralentireprime', [1.0e-8], 5.0e-9, 'float',
        ];
        yield 'hyperbolic sine integral at one' => ['shi', [1], 1.0572508753757285, 'float'];
        yield 'hyperbolic sine integral is odd' => ['hyperbolicsineintegral', [-2], -2.5015674333549766, 'float'];
        yield 'hyperbolic sine integral at zero' => ['shi', [0], 0.0, 'float'];
        yield 'hyperbolic cosine integral at one' => ['chi', [1], 0.8378669409802082, 'float'];
        yield 'hyperbolic cosine integral is even' => [
            'hyperboliccosineintegral', [-2], 2.4526669226469147, 'float',
        ];
        yield 'hyperbolic cosine integral near zero' => [
            'chi', [1.0e-200], 0.5772156649015328606 + \log(1.0e-200), 'float',
        ];
        yield 'hyperbolic sine integral derivative at zero' => ['shiprime', [0], 1.0, 'float'];
        yield 'hyperbolic sine integral derivative' => ['shiprime', [2], \sinh(2.0) / 2.0, 'float'];
        yield 'hyperbolic sine integral derivative alias' => [
            'hyperbolicsineintegralprime', [-1], \sinh(1.0), 'float',
        ];
        yield 'hyperbolic cosine integral derivative' => ['chiprime', [2], \cosh(2.0) / 2.0, 'float'];
        yield 'hyperbolic cosine integral derivative is odd' => [
            'hyperboliccosineintegralprime', [-2], -\cosh(2.0) / 2.0, 'float',
        ];
        yield 'exponential integral E1 at one half' => ['expint1', [0.5], 0.5597735947761608, 'float'];
        yield 'exponential integral E1 alias at one' => ['e1', [1], 0.21938393439552027, 'float'];
        yield 'exponential integral E1 at two' => ['expint1', [2], 0.04890051070806112, 'float'];
        yield 'exponential integral E1 at ten' => ['e1', [10], 4.156968929685325e-6, 'float'];
        yield 'logarithmic exponential integral E1 at one half' => [
            'logexpint1', [0.5], \log(0.5597735947761608), 'float',
        ];
        yield 'logarithmic E1 alias at ten' => [
            'loge1', [10], \log(4.156968929685325e-6), 'float',
        ];
        yield 'logarithmic E1 retains an underflowing tail' => [
            'logexpint1', [1000], -1006.9087537832978, 'float',
        ];
        yield 'generalized exponential integral E2 at one half' => [
            'expint', [2, 0.5], 0.326643862324553, 'float',
        ];
        yield 'generalized exponential integral E2 at one' => [
            'expint', [2, 1], 0.14849550677592205, 'float',
        ];
        yield 'generalized exponential integral E3 at one' => [
            'expint', [3, 1], 0.10969196719776014, 'float',
        ];
        yield 'generalized exponential integral E2 at two' => [
            'expint', [2, 2], 0.03753426182049045, 'float',
        ];
        yield 'logarithmic generalized exponential integral E2 at one' => [
            'logexpint', [2, 1], \log(0.14849550677592205), 'float',
        ];
        yield 'logarithmic generalized exponential integral retains a tail' => [
            'logexpint', [8, 1000], -1006.9157155905334, 'float',
        ];
        yield 'prime is not composite' => ['iscomposite', [13], 0, 'int'];
        yield 'one is not composite' => ['iscomposite', [1], 0, 'int'];
        yield 'composite predicate' => ['iscomposite', [91], 1, 'int'];
        yield 'prime is a prime power' => ['isprimepower', [13], 1, 'int'];
        yield 'prime power' => ['isprimepower', [625], 1, 'int'];
        yield 'non-prime-power composite' => ['isprimepower', [12], 0, 'int'];
        yield 'one is not a prime power' => ['isprimepower', [1], 0, 'int'];
        yield 'one is not a perfect power' => ['isperfectpower', [1], 0, 'int'];
        yield 'general perfect power' => ['isperfectpower', [64], 1, 'int'];
        yield 'non-perfect-power composite' => ['isperfectpower', [72], 0, 'int'];
        yield 'exact square predicate' => ['isperfectpower', [144, 2], 1, 'int'];
        yield 'exact cube predicate' => ['isperfectpower', [729, 3], 1, 'int'];
        yield 'exact exponent mismatch' => ['isperfectpower', [729, 4], 0, 'int'];
        yield 'semiprime with distinct factors' => ['issemiprime', [15], 1, 'int'];
        yield 'semiprime with repeated factor' => ['issemiprime', [49], 1, 'int'];
        yield 'not semiprime' => ['issemiprime', [12], 0, 'int'];
        yield 'Carmichael number 561' => ['iscarmichael', [561], 1, 'int'];
        yield 'Carmichael number 1105' => ['iscarmichael', [1105], 1, 'int'];
        yield 'Carmichael number 1729' => ['iscarmichael', [1729], 1, 'int'];
        yield 'prime is not Carmichael' => ['iscarmichael', [13], 0, 'int'];
        yield 'square factor excludes Carmichael number' => ['iscarmichael', [4], 0, 'int'];
        yield 'composite non-Carmichael number' => ['iscarmichael', [15], 0, 'int'];
        yield 'powerful number' => ['ispowerful', [72], 1, 'int'];
        yield 'powerful number one' => ['ispowerful', [1], 1, 'int'];
        yield 'not powerful number' => ['ispowerful', [12], 0, 'int'];
        yield 'Mobius of one' => ['mobius', [1], 1, 'int'];
        yield 'Mobius square-free composite' => ['mobius', [30], -1, 'int'];
        yield 'Mobius repeated prime factor' => ['mobius', [12], 0, 'int'];
        yield 'Mertens function at zero' => ['mertens', [0], 0, 'int'];
        yield 'Mertens function at one' => ['mertens', [1], 1, 'int'];
        yield 'Mertens function at ten' => ['mertens', [10], -1, 'int'];
        yield 'Mertens function at twenty' => ['mertens', [20], -3, 'int'];
        yield 'Mertens function at thirty' => ['mertens', [30], -3, 'int'];
        yield 'Mertens function at one million' => ['mertens', [1000000], 212, 'int'];
        yield 'one is square-free' => ['issquarefree', [1], 1, 'int'];
        yield 'square-free composite predicate' => ['issquarefree', [30], 1, 'int'];
        yield 'non-square-free predicate' => ['issquarefree', [12], 0, 'int'];
        yield 'Liouville at one' => ['liouville', [1], 1, 'int'];
        yield 'Liouville prime' => ['liouville', [7], -1, 'int'];
        yield 'Liouville even total multiplicity' => ['liouville', [36], 1, 'int'];
        yield 'Liouville odd total multiplicity' => ['liouville', [72], -1, 'int'];
        yield 'distinct prime-factor count of one' => ['omega', [1], 0, 'int'];
        yield 'distinct prime-factor count' => ['omega', [72], 2, 'int'];
        yield 'prime-factor multiplicity of one' => ['bigomega', [1], 0, 'int'];
        yield 'prime-factor multiplicity' => ['bigomega', [72], 5, 'int'];
        yield 'radical of one' => ['radical', [1], 1, 'int'];
        yield 'square-free kernel' => ['radical', [72], 6, 'int'];
        yield 'arithmetic derivative of one' => ['arithderivative', [1], 0, 'int'];
        yield 'arithmetic derivative of a prime' => ['arithderivative', [13], 1, 'int'];
        yield 'arithmetic derivative uses the Leibniz rule' => ['arithderivative', [12], 16, 'int'];
        yield 'arithmetic derivative of a square-free composite' => ['arithderivative', [30], 31, 'int'];
        yield 'primorial below two' => ['primorial', [1], 1, 'int'];
        yield 'primorial through ten' => ['primorial', [10], 210, 'int'];
        yield 'primorial through twenty' => ['primorial', [20], 9699690, 'int'];
        yield 'Carmichael lambda of one' => ['carmichael', [1], 1, 'int'];
        yield 'Carmichael lambda of a power of two' => ['carmichael', [8], 2, 'int'];
        yield 'Carmichael lambda of an odd composite' => ['carmichael', [15], 4, 'int'];
        yield 'Carmichael lambda mixed factors' => ['carmichael', [72], 6, 'int'];
        yield 'aliquot sum of one' => ['aliquotsum', [1], 0, 'int'];
        yield 'aliquot sum of a perfect number' => ['aliquotsum', [28], 28, 'int'];
        yield 'aliquot sum of twelve' => ['aliquotsum', [12], 16, 'int'];
        yield 'perfect number six' => ['isperfect', [6], 1, 'int'];
        yield 'perfect number twenty-eight' => ['isperfect', [28], 1, 'int'];
        yield 'non-perfect number' => ['isperfect', [12], 0, 'int'];
        yield 'abundant number' => ['isabundant', [12], 1, 'int'];
        yield 'not abundant number' => ['isabundant', [10], 0, 'int'];
        yield 'deficient number' => ['isdeficient', [10], 1, 'int'];
        yield 'one is deficient' => ['isdeficient', [1], 1, 'int'];
        yield 'perfect number is not deficient' => ['isdeficient', [6], 0, 'int'];
        yield 'amicable pair 220 and 284' => ['isamicable', [220, 284], 1, 'int'];
        yield 'amicable pair reverse order' => ['isamicable', [284, 220], 1, 'int'];
        yield 'amicable pair 1184 and 1210' => ['isamicable', [1184, 1210], 1, 'int'];
        yield 'perfect number is not an amicable pair with itself' => ['isamicable', [6, 6], 0, 'int'];
        yield 'non-amicable numbers' => ['isamicable', [12, 10], 0, 'int'];
        yield 'sociable perfect number has cycle length one' => ['issociable', [6, 1], 1, 'int'];
        yield 'amicable number has cycle length two' => ['issociable', [220, 2], 1, 'int'];
        yield 'amicable number does not have cycle length one' => ['issociable', [220, 1], 0, 'int'];
        yield 'sociable number has cycle length five' => ['issociable', [12496, 5], 1, 'int'];
        yield 'sociable number has no spurious shorter cycle' => ['issociable', [12496, 2], 0, 'int'];
        yield 'von Mangoldt at one' => ['vonmangoldt', [1], 0.0, 'float'];
        yield 'von Mangoldt prime' => ['vonmangoldt', [13], \log(13.0), 'float'];
        yield 'von Mangoldt prime power' => ['vonmangoldt', [625], \log(5.0), 'float'];
        yield 'von Mangoldt non-prime-power composite' => ['vonmangoldt', [12], 0.0, 'float'];
        yield 'von Mangoldt alias' => ['mangoldt', [49], \log(7.0), 'float'];
        yield 'digit sum decimal' => ['digitsum', [12345], 15, 'int'];
        yield 'digit sum in base two' => ['digitsum', [255, 2], 8, 'int'];
        yield 'digit sum zero' => ['digitsum', [0], 0, 'int'];
        yield 'digit product decimal' => ['digitproduct', [234], 24, 'int'];
        yield 'digit product base three' => ['digitproduct', [26, 3], 8, 'int'];
        yield 'digit product includes zero digit' => ['digitproduct', [102], 0, 'int'];
        yield 'digit product of zero' => ['digitproduct', [0], 0, 'int'];
        yield 'digital root decimal' => ['digitalroot', [9876], 3, 'int'];
        yield 'digital root base two' => ['digitalroot', [255, 2], 1, 'int'];
        yield 'digital root zero' => ['digitalroot', [0], 0, 'int'];
        yield 'additive persistence decimal' => ['additivepersistence', [199], 3, 'int'];
        yield 'additive persistence base two' => ['additivepersistence', [255, 2], 2, 'int'];
        yield 'multiplicative persistence decimal' => ['multiplicativepersistence', [39], 3, 'int'];
        yield 'multiplicative persistence known maximum example' => [
            'multiplicativepersistence', [277777788888899], 11, 'int',
        ];
        yield 'multiplicative persistence zero' => ['multiplicativepersistence', [0], 0, 'int'];
        yield 'Harshad number decimal' => ['isharshad', [18], 1, 'int'];
        yield 'Harshad number in base two' => ['isharshad', [21, 2], 1, 'int'];
        yield 'non-Harshad number' => ['isharshad', [19], 0, 'int'];
        yield 'happy number one' => ['ishappy', [1], 1, 'int'];
        yield 'happy number seven' => ['ishappy', [7], 1, 'int'];
        yield 'happy number nineteen' => ['ishappy', [19], 1, 'int'];
        yield 'unhappy number four' => ['ishappy', [4], 0, 'int'];
        yield 'zero is not happy' => ['ishappy', [0], 0, 'int'];
        yield 'large happy number' => ['ishappy', [1000000000000], 1, 'int'];
        yield 'Smith number four' => ['issmith', [4], 1, 'int'];
        yield 'Smith number twenty two' => ['issmith', [22], 1, 'int'];
        yield 'Smith number twenty seven' => ['issmith', [27], 1, 'int'];
        yield 'prime is not Smith' => ['issmith', [13], 0, 'int'];
        yield 'composite non-Smith number' => ['issmith', [12], 0, 'int'];
        yield 'factorial function' => ['factorial', [5], 120, 'int'];
        yield 'log factorial zero' => ['logfactorial', [0], 0.0, 'float'];
        yield 'log factorial five' => ['logfactorial', [5], \log(120.0), 'float'];
        yield 'log binomial' => ['logbinomial', [10, 3], \log(120.0), 'float'];
        yield 'log choose alias' => ['logchoose', [10, 5], \log(252.0), 'float'];
        yield 'log binomial avoids overflow' => [
            'logbinomial', [100, 50], \log(100891344545564193334812497256.0), 'float',
        ];
        yield 'log binomial with small k and huge n' => [
            'logbinomial', [1000000000000, 2], \log(1000000000000.0) + \log(999999999999.0) - \log(2.0), 'float',
        ];
        yield 'log permutation' => ['logpermutation', [10, 3], \log(720.0), 'float'];
        yield 'log permutation alias' => ['lognpr', [10, 3], \log(720.0), 'float'];
        yield 'log factorial large argument remains finite' => [
            'logfactorial', [10000], 82108.92783681434, 'float',
        ];
        yield 'log factorial alias' => ['lfactorial', [6], \log(720.0), 'float'];
        yield 'harmonic number zero' => ['harmonicnumber', [0], 0.0, 'float'];
        yield 'harmonic number fractional index' => [
            'harmonicnumber', [0.5], 2.0 - 2.0 * \log(2.0), 'float',
        ];
        yield 'harmonic number two' => ['harmonicnumber', [2], 1.5, 'float'];
        yield 'harmonic number third order two' => ['harmonicnumber', [3, 2], 49 / 36, 'float'];
        yield 'generalized harmonic number fractional order' => [
            'harmonicnumber', [2, 0.5], 1.0 + 1.0 / \sqrt(2.0), 'float',
        ];
        yield 'generalized harmonic number fractional index and order' => [
            'harmonicnumber', [0.5, 2], 4.0 - M_PI * M_PI / 3.0, 'float',
        ];
        yield 'partition number p(0)' => ['partition', [0], 1, 'int'];
        yield 'partition number p(5)' => ['partition', [5], 7, 'int'];
        yield 'partition of five into one part' => ['partitioncount', [5, 1], 1, 'int'];
        yield 'partition of five into two parts' => ['partitioncount', [5, 2], 2, 'int'];
        yield 'partition of five into three parts' => ['partitioncount', [5, 3], 2, 'int'];
        yield 'partition of five into five parts' => ['partitioncount', [5, 5], 1, 'int'];
        yield 'partition zero into zero parts' => ['partitioncount', [0, 0], 1, 'int'];
        yield 'partition positive number into zero parts' => ['partitioncount', [5, 0], 0, 'int'];
        yield 'partition into too many parts' => ['partitioncount', [5, 6], 0, 'int'];
        yield 'distinct partitions of five into one part' => ['distinctpartitioncount', [5, 1], 1, 'int'];
        yield 'distinct partitions of five into two parts' => ['distinctpartitioncount', [5, 2], 2, 'int'];
        yield 'distinct partitions of five into three parts' => ['distinctpartitioncount', [5, 3], 0, 'int'];
        yield 'distinct partitions of six into three parts' => ['distinctpartitioncount', [6, 3], 1, 'int'];
        yield 'distinct partitions of ten into two parts' => ['distinctpartitioncount', [10, 2], 4, 'int'];
        yield 'empty distinct partition' => ['distinctpartitioncount', [0, 0], 1, 'int'];
        yield 'distinct partitions into too many parts' => ['distinctpartitioncount', [5, 6], 0, 'int'];
        yield 'partition number p(10)' => ['partition', [10], 42, 'int'];
        yield 'partition number p(50)' => ['partition', [50], 204226, 'int'];
        yield 'partition number p(100)' => ['partition', [100], 190569292, 'int'];
        yield 'partition number p(200)' => ['partition', [200], 3972999029388, 'int'];
        yield 'prime partitions of zero' => ['primepartition', [0], 1, 'int'];
        yield 'prime partitions of one' => ['primepartition', [1], 0, 'int'];
        yield 'prime partitions of two' => ['primepartition', [2], 1, 'int'];
        yield 'prime partitions of ten' => ['primepartition', [10], 5, 'int'];
        yield 'prime partitions of twenty' => ['primepartition', [20], 26, 'int'];
        yield 'distinct partitions of zero' => ['distinctpartitions', [0], 1, 'int'];
        yield 'distinct partitions of ten' => ['partitiondistinct', [10], 10, 'int'];
        yield 'signed Stirling number of first kind' => ['stirling1', [4, 1], -6, 'int'];
        yield 'unsigned Stirling number of first kind' => ['stirling1unsigned', [4, 1], 6, 'int'];
        yield 'signed Stirling number first-kind diagonal' => ['stirling1', [5, 5], 1, 'int'];
        yield 'Stirling number of second kind' => ['stirling2', [5, 2], 15, 'int'];
        yield 'Stirling number of second kind zero convention' => ['stirling2', [0, 0], 1, 'int'];
        yield 'Lah number zero convention' => ['lah', [0, 0], 1, 'int'];
        yield 'Lah number empty partition' => ['lah', [5, 0], 0, 'int'];
        yield 'Lah number single ordered block' => ['lah', [5, 1], 120, 'int'];
        yield 'Lah number four into two ordered blocks' => ['lah', [4, 2], 36, 'int'];
        yield 'Lah number into singleton blocks' => ['lah', [5, 5], 1, 'int'];
        yield 'Eulerian zero convention' => ['eulerian', [0, 0], 1, 'int'];
        yield 'Eulerian first ascent' => ['eulerian', [3, 1], 4, 'int'];
        yield 'Eulerian symmetry' => ['eulerian', [4, 2], 11, 'int'];
        yield 'Eulerian central value' => ['eulerian', [5, 2], 66, 'int'];
        yield 'Eulerian boundary' => ['eulerian', [8, 0], 1, 'int'];
        yield 'Narayana boundary k one' => ['narayana', [4, 1], 1, 'int'];
        yield 'Narayana central value' => ['narayana', [4, 2], 6, 'int'];
        yield 'Narayana symmetric central value' => ['narayana', [4, 3], 6, 'int'];
        yield 'Narayana singleton partitions' => ['narayana', [4, 4], 1, 'int'];
        yield 'Narayana fifth row' => ['narayana', [5, 2], 10, 'int'];
        yield 'Bell number B0' => ['bellnumber', [0], 1, 'int'];
        yield 'Motzkin number M0' => ['motzkin', [0], 1, 'int'];
        yield 'Motzkin number M5' => ['motzkin', [5], 21, 'int'];
        yield 'Motzkin number M10' => ['motzkin', [10], 2188, 'int'];
        yield 'Jacobsthal J0' => ['jacobsthal', [0], 0, 'int'];
        yield 'Jacobsthal J1' => ['jacobsthal', [1], 1, 'int'];
        yield 'Jacobsthal J10' => ['jacobsthal', [10], 341, 'int'];
        yield 'Jacobsthal-Lucas j0' => ['jacobsthallucas', [0], 2, 'int'];
        yield 'Jacobsthal-Lucas j1' => ['jacobsthallucas', [1], 1, 'int'];
        yield 'Jacobsthal-Lucas j10' => ['jacobsthallucas', [10], 1025, 'int'];
        yield 'Fibonacci sum through index ten' => ['fibonaccisum', [10], 143, 'int'];
        yield 'Lucas sum through index ten' => ['lucassum', [10], 321, 'int'];
        yield 'Pell sum through index ten' => ['pellsum', [10], 4059, 'int'];
        yield 'Pell-Lucas sum through index ten' => ['pelllucassum', [10], 11482, 'int'];
        yield 'Jacobsthal sum through index ten' => ['jacobsthalsum', [10], 682, 'int'];
        yield 'Jacobsthal-Lucas sum through index ten' => ['jacobsthallucassum', [10], 2048, 'int'];
        yield 'Tribonacci sum through index ten' => ['tribonaccisum', [10], 177, 'int'];
        yield 'Padovan sum through index ten' => ['padovansum', [10], 47, 'int'];
        yield 'Perrin sum through index ten' => ['perrinsum', [10], 66, 'int'];
        yield 'Padovan P0' => ['padovan', [0], 1, 'int'];
        yield 'Padovan P10' => ['padovan', [10], 12, 'int'];
        yield 'Perrin R0' => ['perrin', [0], 3, 'int'];
        yield 'Perrin R10' => ['perrin', [10], 17, 'int'];
        yield 'Stern diatomic zero' => ['stern', [0], 0, 'int'];
        yield 'Stern diatomic first term' => ['stern', [1], 1, 'int'];
        yield 'Stern diatomic fourth term' => ['stern', [4], 1, 'int'];
        yield 'Stern diatomic fifth term' => ['stern', [5], 3, 'int'];
        yield 'Stern diatomic ninth term' => ['stern', [9], 4, 'int'];
        yield 'Pell-Lucas Q0' => ['pelllucas', [0], 2, 'int'];
        yield 'Pell-Lucas Q1' => ['pelllucas', [1], 2, 'int'];
        yield 'Pell-Lucas Q10' => ['pelllucas', [10], 6726, 'int'];
        yield 'Bell number B5' => ['bell', [5], 52, 'int'];
        yield 'Fibonacci zero' => ['fibonacci', [0], 0, 'int'];
        yield 'Fibonacci tenth' => ['fibonacci', [10], 55, 'int'];
        yield 'negative Fibonacci index' => ['fibonacci', [-8], -21, 'int'];
        yield 'nacci order two matches Fibonacci' => ['nacci', [10, 2], 55, 'int'];
        yield 'nacci order three matches Tribonacci' => ['nacci', [10, 3], 81, 'int'];
        yield 'nacci zeros before its seed' => ['nacci', [3, 5], 0, 'int'];
        yield 'nacci seed at order minus one' => ['nacci', [4, 5], 1, 'int'];
        yield 'Tribonacci tenth term' => ['tribonacci', [10], 81, 'int'];
        yield 'Lucas zero' => ['lucas', [0], 2, 'int'];
        yield 'Lucas fifth' => ['lucas', [5], 11, 'int'];
        yield 'negative Lucas index' => ['lucas', [-3], -4, 'int'];
        yield 'Pell zero' => ['pell', [0], 0, 'int'];
        yield 'Pell tenth' => ['pell', [10], 2378, 'int'];
        yield 'negative Pell index' => ['pell', [-6], -70, 'int'];
        yield 'Pell 48' => ['pell', [48], 835002744095575440, 'int'];
        yield 'Pell 49 remains in integer range' => ['pell', [49], 2015874949414289041, 'int'];
        yield 'triangular zero' => ['triangular', [0], 0, 'int'];
        yield 'triangular tenth' => ['triangular', [10], 55, 'int'];
        yield 'polygonal square number' => ['polygonal', [4, 5], 25, 'int'];
        yield 'polygonal triangular agrees with triangular sequence' => ['polygonal', [3, 10], 55, 'int'];
        yield 'pentagonal number' => ['pentagonal', [4], 22, 'int'];
        yield 'hexagonal number' => ['hexagonal', [3], 15, 'int'];
        yield 'heptagonal number' => ['heptagonal', [3], 18, 'int'];
        yield 'octagonal number' => ['octagonal', [3], 21, 'int'];
        yield 'polygonal zero index' => ['polygonal', [8, 0], 0, 'int'];
        yield 'tetrahedral zero' => ['tetrahedral', [0], 0, 'int'];
        yield 'tetrahedral tenth' => ['tetrahedral', [10], 220, 'int'];
        yield 'simplex line agrees with index' => ['simplex', [1, 10], 10, 'int'];
        yield 'simplex triangle agrees with triangular' => ['simplex', [2, 10], 55, 'int'];
        yield 'simplex tetrahedron agrees with tetrahedral' => ['simplex', [3, 10], 220, 'int'];
        yield 'simplex four dimensional' => ['simplex', [4, 3], 15, 'int'];
        yield 'simplex zero index' => ['simplex', [5, 0], 0, 'int'];
        yield 'general triangular pyramidal agrees with tetrahedral' => ['pyramidal', [3, 10], 220, 'int'];
        yield 'square pyramidal number' => ['pyramidal', [4, 5], 55, 'int'];
        yield 'pentagonal pyramidal number' => ['pyramidal', [5, 2], 6, 'int'];
        yield 'square pyramidal alias' => ['squarepyramidal', [5], 55, 'int'];
        yield 'pyramidal zero index' => ['pyramidal', [4, 0], 0, 'int'];
        yield 'superfactorial zero' => ['superfactorial', [0], 1, 'int'];
        yield 'superfactorial fourth' => ['superfactorial', [4], 288, 'int'];
        yield 'hyperfactorial fourth' => ['hyperfactorial', [4], 27648, 'int'];
        yield 'hyperfactorial sixth' => ['hyperfactorial', [6], 4031078400000, 'int'];
        yield 'largest tetrahedral number in integer range' => [
            'tetrahedral', [3810777], 9223371416043870029, 'int',
        ];
        yield 'largest triangular number in integer range' => [
            'triangular', [4294967295], 9223372034707292160, 'int',
        ];
        yield 'binomial coefficient' => ['binomial', [5, 2], 10, 'int'];
        yield 'choose alias' => ['choose', [5, 2], 10, 'int'];
        yield 'ncr alias' => ['ncr', [5, 2], 10, 'int'];
        yield 'generalized binomial for real upper argument' => [
            'genbinomial', [2.5, 3], 0.3125, 'float',
        ];
        yield 'generalized binomial for negative upper argument' => [
            'generalizedbinomial', [-3, 2], 6.0, 'float',
        ];
        yield 'generalized binomial for fractional upper argument' => [
            'genbinomial', [0.5, 2], -0.125, 'float',
        ];
        yield 'generalized binomial order zero' => ['genbinomial', [-4.5, 0], 1.0, 'float'];
        yield 'permutation count' => ['permutation', [5, 2], 20, 'int'];
        yield 'permute alias' => ['permute', [5, 2], 20, 'int'];
        yield 'npr alias' => ['npr', [5, 2], 20, 'int'];
        yield 'multinomial coefficient' => ['multinomial', [2, 3, 4], 1260, 'int'];
        yield 'subfactorial zero' => ['subfactorial', [0], 1, 'int'];
        yield 'subfactorial one' => ['subfactorial', [1], 0, 'int'];
        yield 'subfactorial five' => ['subfactorial', [5], 44, 'int'];
        yield 'derangement alias' => ['derangement', [6], 265, 'int'];
        yield 'subfactorial maximum 64-bit result' => ['subfactorial', [20], 895014631192902121, 'int'];
        yield 'multinomial with zero categories' => ['multinomial', [2, 0, 3], 10, 'int'];
        yield 'multinomial all zero' => ['multinomial', [0, 0, 0], 1, 'int'];
        yield 'multinomial one category' => ['multinomial', [7], 1, 'int'];
        yield 'Catalan zero' => ['catalan', [0], 1, 'int'];
        yield 'Catalan fifth' => ['catalan', [5], 42, 'int'];
        yield 'Catalan tenth' => ['catalan', [10], 16796, 'int'];
        yield 'Catalan nineteenth' => ['catalan', [19], 1767263190, 'int'];
        yield 'double factorial zero' => ['doublefactorial', [0], 1, 'int'];
        yield 'double factorial even' => ['doublefactorial', [10], 3840, 'int'];
        yield 'double factorial odd' => ['doublefactorial', [9], 945, 'int'];
        yield 'double factorial alias' => ['dfactorial', [7], 105, 'int'];
        yield 'multifactorial' => ['multifactorial', [10, 3], 280, 'int'];
        yield 'factorial alias through multifactorial' => ['multifactorial', [6, 1], 720, 'int'];
        yield 'multifactorial step above operand' => ['multifactorial', [5, 8], 5, 'int'];
        yield 'rising factorial' => ['risingfactorial', [3, 4], 360, 'int'];
        yield 'rising factorial real order' => ['risingfactorial', [2, 0.5], 1.329340388179137, 'float'];
        yield 'Pochhammer alias' => ['pochhammer', [2.5, 3], 39.375, 'float'];
        yield 'falling factorial' => ['fallingfactorial', [5, 3], 60, 'int'];
        yield 'falling factorial real order' => ['fallingfactorial', [5, 0.5], 2.292579895051204, 'float'];
        yield 'falling factorial reaches zero' => ['fallingfactorial', [3, 5], 0, 'int'];
        yield 'finite product factorial order zero' => ['risingfactorial', [2.5, 0], 1, 'int'];
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
        yield 'nthroot' => ['nthroot', 2, 2];
        yield 'airyai' => ['airyai', 1, 1];
        yield 'ellipnome' => ['ellipnome', 1, 1];
        yield 'ellipnomeinv' => ['ellipnomeinv', 1, 1];
        yield 'ellipkprime' => ['ellipkprime', 1, 1];
        yield 'ellipeprime' => ['ellipeprime', 1, 1];
        yield 'airyaiprime' => ['airyaiprime', 1, 1];
        yield 'airyaisecond' => ['airyaisecond', 1, 1];
        yield 'airyaithird' => ['airyaithird', 1, 1];
        yield 'airybi' => ['airybi', 1, 1];
        yield 'airybiprime' => ['airybiprime', 1, 1];
        yield 'airybisecond' => ['airybisecond', 1, 1];
        yield 'airybithird' => ['airybithird', 1, 1];
        yield 'ellipfprime' => ['ellipfprime', 2, 2];
        yield 'ellipkincprime' => ['ellipkincprime', 2, 2];
        yield 'ellipeincprime' => ['ellipeincprime', 2, 2];
        yield 'ellippincprime' => ['ellippincprime', 3, 3];
        yield 'erf' => ['erf', 1, 1];
        yield 'erfprime' => ['erfprime', 1, 1];
        yield 'erfsecond' => ['erfsecond', 1, 1];
        yield 'erfthird' => ['erfthird', 1, 1];
        yield 'erfinvprime' => ['erfinvprime', 1, 1];
        yield 'erfinvsecond' => ['erfinvsecond', 1, 1];
        yield 'erfcinvprime' => ['erfcinvprime', 1, 1];
        yield 'erfcinvsecond' => ['erfcinvsecond', 1, 1];
        yield 'dawson' => ['dawson', 1, 1];
        yield 'erfcprime' => ['erfcprime', 1, 1];
        yield 'erfcsecond' => ['erfcsecond', 1, 1];
        yield 'erfcthird' => ['erfcthird', 1, 1];
        yield 'dawsonprime' => ['dawsonprime', 1, 1];
        yield 'dawsonsecond' => ['dawsonsecond', 1, 1];
        yield 'dawsonthird' => ['dawsonthird', 1, 1];
        yield 'erfc' => ['erfc', 1, 1];
        yield 'erfcx' => ['erfcx', 1, 1];
        yield 'erfiprime' => ['erfiprime', 1, 1];
        yield 'erfisecond' => ['erfisecond', 1, 1];
        yield 'erfithird' => ['erfithird', 1, 1];
        yield 'logerfc' => ['logerfc', 1, 1];
        yield 'logerfcprime' => ['logerfcprime', 1, 1];
        yield 'erfcinvlog' => ['erfcinvlog', 1, 1];
        yield 'erfcinvlogprime' => ['erfcinvlogprime', 1, 1];
        yield 'gamma' => ['gamma', 1, 1];
        yield 'lowerincompletegamma' => ['lowerincompletegamma', 2, 2];
        yield 'lowergamma' => ['lowergamma', 2, 2];
        yield 'upperincompletegamma' => ['upperincompletegamma', 2, 2];
        yield 'uppergamma' => ['uppergamma', 2, 2];
        yield 'loglowerincompletegamma' => ['loglowerincompletegamma', 2, 2];
        yield 'loglowergamma' => ['loglowergamma', 2, 2];
        yield 'logupperincompletegamma' => ['logupperincompletegamma', 2, 2];
        yield 'loguppergamma' => ['loguppergamma', 2, 2];
        yield 'gammaincinvprime' => ['gammaincinvprime', 2, 2];
        yield 'gammainccinvprime' => ['gammainccinvprime', 2, 2];
        yield 'loggammainc' => ['loggammainc', 2, 2];
        yield 'loggammaincc' => ['loggammaincc', 2, 2];
        yield 'gammaincinvlog' => ['gammaincinvlog', 2, 2];
        yield 'gammainccinvlog' => ['gammainccinvlog', 2, 2];
        yield 'logbetainc' => ['logbetainc', 3, 3];
        yield 'logbetaincc' => ['logbetaincc', 3, 3];
        yield 'betaincinvlog' => ['betaincinvlog', 3, 3];
        yield 'betainccinvlog' => ['betainccinvlog', 3, 3];
        yield 'lowerincompletebeta' => ['lowerincompletebeta', 3, 3];
        yield 'lowerbeta' => ['lowerbeta', 3, 3];
        yield 'upperincompletebeta' => ['upperincompletebeta', 3, 3];
        yield 'upperbeta' => ['upperbeta', 3, 3];
        yield 'loglowerincompletebeta' => ['loglowerincompletebeta', 3, 3];
        yield 'loglowerbeta' => ['loglowerbeta', 3, 3];
        yield 'logupperincompletebeta' => ['logupperincompletebeta', 3, 3];
        yield 'logupperbeta' => ['logupperbeta', 3, 3];
        yield 'betaincinvprime' => ['betaincinvprime', 3, 3];
        yield 'betainccinvprime' => ['betainccinvprime', 3, 3];
        yield 'hyp1f1' => ['hyp1f1', 3, 3];
        yield 'confluenthypergeometric' => ['confluenthypergeometric', 3, 3];
        yield 'hyp2f1' => ['hyp2f1', 4, 4];
        yield 'gausshypergeometric' => ['gausshypergeometric', 4, 4];
        yield 'hyp0f1' => ['hyp0f1', 2, 2];
        yield 'confluenthypergeometriclimit' => ['confluenthypergeometriclimit', 2, 2];
        yield 'hyp3f2' => ['hyp3f2', 6, 6];
        yield 'generalizedhypergeometric3f2' => ['generalizedhypergeometric3f2', 6, 6];
        yield 'hypergeom' => ['hypergeom', 3, 16];
        yield 'lgamma' => ['lgamma', 1, 1];
        yield 'gammasign' => ['gammasign', 1, 1];
        yield 'signgamma' => ['signgamma', 1, 1];
        yield 'beta' => ['beta', 2, 2];
        yield 'logbeta' => ['logbeta', 2, 2];
        yield 'zeta' => ['zeta', 1, 1];
        yield 'hurwitzzeta' => ['hurwitzzeta', 2, 2];
        yield 'hzeta' => ['hzeta', 2, 2];
        yield 'lerchphi' => ['lerchphi', 3, 3];
        yield 'eta' => ['eta', 1, 1];
        yield 'dirichleteta' => ['dirichleteta', 1, 1];
        yield 'dirichletbeta' => ['dirichletbeta', 1, 1];
        yield 'bernoulli' => ['bernoulli', 1, 1];
        yield 'eulernumber' => ['eulernumber', 1, 1];
        yield 'bernoullipoly' => ['bernoullipoly', 2, 2];
        yield 'zetaprime' => ['zetaprime', 1, 1];
        yield 'digamma' => ['digamma', 1, 1];
        yield 'trigamma' => ['trigamma', 1, 1];
        yield 'tetragamma' => ['tetragamma', 1, 1];
        yield 'pentagamma' => ['pentagamma', 1, 1];
        yield 'hexagamma' => ['hexagamma', 1, 1];
        yield 'besselj' => ['besselj', 2, 2];
        yield 'besseljprime' => ['besseljprime', 2, 2];
        yield 'besseljsecond' => ['besseljsecond', 2, 2];
        yield 'besselisecond' => ['besselisecond', 2, 2];
        yield 'besselysecond' => ['besselysecond', 2, 2];
        yield 'besselksecond' => ['besselksecond', 2, 2];
        yield 'besseli' => ['besseli', 2, 2];
        yield 'kve' => ['kve', 2, 2];
        yield 'scaledbesselk' => ['scaledbesselk', 2, 2];
        yield 'ive' => ['ive', 2, 2];
        yield 'scaledbesseli' => ['scaledbesseli', 2, 2];
        yield 'besseliprime' => ['besseliprime', 2, 2];
        yield 'logbesseliprime' => ['logbesseliprime', 2, 2];
        yield 'besselyprime' => ['besselyprime', 2, 2];
        yield 'besselkprime' => ['besselkprime', 2, 2];
        yield 'logbesseli' => ['logbesseli', 2, 2];
        yield 'logbesselk' => ['logbesselk', 2, 2];
        yield 'logbesselkprime' => ['logbesselkprime', 2, 2];
        yield 'jacobitheta1' => ['jacobitheta1', 2, 2];
        yield 'jacobisn' => ['jacobisn', 2, 2];
        yield 'jacobicn' => ['jacobicn', 2, 2];
        yield 'jacobidn' => ['jacobidn', 2, 2];
        yield 'jacobiam' => ['jacobiam', 2, 2];
        yield 'am' => ['am', 2, 2];
        yield 'jacobicd' => ['jacobicd', 2, 2];
        yield 'jacobisd' => ['jacobisd', 2, 2];
        yield 'jacobind' => ['jacobind', 2, 2];
        yield 'jacobidc' => ['jacobidc', 2, 2];
        yield 'jacobinc' => ['jacobinc', 2, 2];
        yield 'jacobisc' => ['jacobisc', 2, 2];
        yield 'jacobins' => ['jacobins', 2, 2];
        yield 'jacobids' => ['jacobids', 2, 2];
        yield 'jacobics' => ['jacobics', 2, 2];
        yield 'jacobitheta2' => ['jacobitheta2', 2, 2];
        yield 'jacobitheta3' => ['jacobitheta3', 2, 2];
        yield 'jacobitheta4' => ['jacobitheta4', 2, 2];
        yield 'theta1' => ['theta1', 2, 2];
        yield 'theta2' => ['theta2', 2, 2];
        yield 'theta3' => ['theta3', 2, 2];
        yield 'theta4' => ['theta4', 2, 2];
        yield 'normalpdf' => ['normalpdf', 1, 3];
        yield 'normallogpdf' => ['normallogpdf', 1, 3];
        yield 'normlogpdf' => ['normlogpdf', 1, 3];
        yield 'normpdf' => ['normpdf', 1, 3];
        yield 'normalcdf' => ['normalcdf', 1, 3];
        yield 'normalccdf' => ['normalccdf', 1, 3];
        yield 'normallogcdf' => ['normallogcdf', 1, 3];
        yield 'normlogcdf' => ['normlogcdf', 1, 3];
        yield 'normallogccdf' => ['normallogccdf', 1, 3];
        yield 'normlogccdf' => ['normlogccdf', 1, 3];
        yield 'normcdf' => ['normcdf', 1, 3];
        yield 'normccdf' => ['normccdf', 1, 3];
        yield 'normalhazard' => ['normalhazard', 1, 3];
        yield 'normhazard' => ['normhazard', 1, 3];
        yield 'normalcumhazard' => ['normalcumhazard', 1, 3];
        yield 'normcumhazard' => ['normcumhazard', 1, 3];
        yield 'normalinv' => ['normalinv', 1, 3];
        yield 'studenttpdf' => ['studenttpdf', 2, 4];
        yield 'tpdf' => ['tpdf', 2, 4];
        yield 'studenttlogpdf' => ['studenttlogpdf', 2, 4];
        yield 'tlogpdf' => ['tlogpdf', 2, 4];
        yield 'studenttcdf' => ['studenttcdf', 2, 4];
        yield 'tcdf' => ['tcdf', 2, 4];
        yield 'studenttccdf' => ['studenttccdf', 2, 4];
        yield 'tccdf' => ['tccdf', 2, 4];
        yield 'studenttlogcdf' => ['studenttlogcdf', 2, 4];
        yield 'tlogcdf' => ['tlogcdf', 2, 4];
        yield 'studenttlogccdf' => ['studenttlogccdf', 2, 4];
        yield 'tlogccdf' => ['tlogccdf', 2, 4];
        yield 'studenttinv' => ['studenttinv', 2, 4];
        yield 'tinv' => ['tinv', 2, 4];
        yield 'studenttmean' => ['studenttmean', 1, 3];
        yield 'tmean' => ['tmean', 1, 3];
        yield 'studenttvariance' => ['studenttvariance', 1, 3];
        yield 'tvariance' => ['tvariance', 1, 3];
        yield 'tvar' => ['tvar', 1, 3];
        yield 'studenttstddev' => ['studenttstddev', 1, 3];
        yield 'tstddev' => ['tstddev', 1, 3];
        yield 'studenttmedian' => ['studenttmedian', 1, 3];
        yield 'tmedian' => ['tmedian', 1, 3];
        yield 'studenttmode' => ['studenttmode', 1, 3];
        yield 'tmode' => ['tmode', 1, 3];
        yield 'studenttentropy' => ['studenttentropy', 1, 3];
        yield 'tentropy' => ['tentropy', 1, 3];
        yield 'studentthazard' => ['studentthazard', 2, 4];
        yield 'thazard' => ['thazard', 2, 4];
        yield 'studenttcumhazard' => ['studenttcumhazard', 2, 4];
        yield 'tcumhazard' => ['tcumhazard', 2, 4];
        yield 'studenttskewness' => ['studenttskewness', 1, 3];
        yield 'tskewness' => ['tskewness', 1, 3];
        yield 'studenttexcesskurtosis' => ['studenttexcesskurtosis', 1, 3];
        yield 'texcesskurtosis' => ['texcesskurtosis', 1, 3];
        yield 'studenttkurtosis' => ['studenttkurtosis', 1, 3];
        yield 'tkurtosis' => ['tkurtosis', 1, 3];
        yield 'binomialpmf' => ['binomialpmf', 3, 3];
        yield 'binomiallogpmf' => ['binomiallogpmf', 3, 3];
        yield 'binomlogpmf' => ['binomlogpmf', 3, 3];
        yield 'binomialmean' => ['binomialmean', 2, 2];
        yield 'binommean' => ['binommean', 2, 2];
        yield 'binomialvariance' => ['binomialvariance', 2, 2];
        yield 'binomvariance' => ['binomvariance', 2, 2];
        yield 'binomialvar' => ['binomialvar', 2, 2];
        yield 'binomvar' => ['binomvar', 2, 2];
        yield 'binomialstddev' => ['binomialstddev', 2, 2];
        yield 'binomstddev' => ['binomstddev', 2, 2];
        yield 'binomialmedian' => ['binomialmedian', 2, 2];
        yield 'binommedian' => ['binommedian', 2, 2];
        yield 'binomialmode' => ['binomialmode', 2, 2];
        yield 'binommode' => ['binommode', 2, 2];
        yield 'binomialmoment' => ['binomialmoment', 3, 3];
        yield 'binomialrawmoment' => ['binomialrawmoment', 3, 3];
        yield 'binommoment' => ['binommoment', 3, 3];
        yield 'binomrawmoment' => ['binomrawmoment', 3, 3];
        yield 'binomialmgf' => ['binomialmgf', 3, 3];
        yield 'binommgf' => ['binommgf', 3, 3];
        yield 'binomiallogmgf' => ['binomiallogmgf', 3, 3];
        yield 'binomlogmgf' => ['binomlogmgf', 3, 3];
        yield 'binomialskewness' => ['binomialskewness', 2, 2];
        yield 'binomskewness' => ['binomskewness', 2, 2];
        yield 'binomialexcesskurtosis' => ['binomialexcesskurtosis', 2, 2];
        yield 'binomexcesskurtosis' => ['binomexcesskurtosis', 2, 2];
        yield 'binomialkurtosis' => ['binomialkurtosis', 2, 2];
        yield 'binomkurtosis' => ['binomkurtosis', 2, 2];
        yield 'multinomialpmf' => ['multinomialpmf', 4, 32];
        yield 'multinomiallogpmf' => ['multinomiallogpmf', 4, 32];
        yield 'multinomialmean' => ['multinomialmean', 2, 2];
        yield 'multinomialvariance' => ['multinomialvariance', 2, 2];
        yield 'multinomialvar' => ['multinomialvar', 2, 2];
        yield 'multinomialstddev' => ['multinomialstddev', 2, 2];
        yield 'multinomialmoment' => ['multinomialmoment', 3, 3];
        yield 'multinomialrawmoment' => ['multinomialrawmoment', 3, 3];
        yield 'multinomialcovariance' => ['multinomialcovariance', 3, 3];
        yield 'bernoullipmf' => ['bernoullipmf', 2, 2];
        yield 'bernoullilogpmf' => ['bernoullilogpmf', 2, 2];
        yield 'bernoullicdf' => ['bernoullicdf', 2, 2];
        yield 'bernoulliccdf' => ['bernoulliccdf', 2, 2];
        yield 'bernoulliinv' => ['bernoulliinv', 2, 2];
        yield 'bernoulliccinv' => ['bernoulliccinv', 2, 2];
        yield 'bernoullimean' => ['bernoullimean', 1, 1];
        yield 'bernoullivariance' => ['bernoullivariance', 1, 1];
        yield 'bernoullivar' => ['bernoullivar', 1, 1];
        yield 'bernoullistddev' => ['bernoullistddev', 1, 1];
        yield 'bernoullientropy' => ['bernoullientropy', 1, 1];
        yield 'bernoulliskewness' => ['bernoulliskewness', 1, 1];
        yield 'bernoulliexcesskurtosis' => ['bernoulliexcesskurtosis', 1, 1];
        yield 'bernoullikurtosis' => ['bernoullikurtosis', 1, 1];
        yield 'bernoullimoment' => ['bernoullimoment', 2, 2];
        yield 'bernoullirawmoment' => ['bernoullirawmoment', 2, 2];
        yield 'bernoullimedian' => ['bernoullimedian', 1, 1];
        yield 'bernoullimode' => ['bernoullimode', 1, 1];
        yield 'bernoullimgf' => ['bernoullimgf', 2, 2];
        yield 'bernoullilogmgf' => ['bernoullilogmgf', 2, 2];
        yield 'binompmf' => ['binompmf', 3, 3];
        yield 'binomialcdf' => ['binomialcdf', 3, 3];
        yield 'binomcdf' => ['binomcdf', 3, 3];
        yield 'binomialccdf' => ['binomialccdf', 3, 3];
        yield 'binomccdf' => ['binomccdf', 3, 3];
        yield 'binomiallogcdf' => ['binomiallogcdf', 3, 3];
        yield 'binomlogcdf' => ['binomlogcdf', 3, 3];
        yield 'binomiallogccdf' => ['binomiallogccdf', 3, 3];
        yield 'binomlogccdf' => ['binomlogccdf', 3, 3];
        yield 'binomialinv' => ['binomialinv', 3, 3];
        yield 'binominv' => ['binominv', 3, 3];
        yield 'binomialccinv' => ['binomialccinv', 3, 3];
        yield 'binomccinv' => ['binomccinv', 3, 3];
        yield 'betabinomialpmf' => ['betabinomialpmf', 4, 4];
        yield 'betabinomiallogpmf' => ['betabinomiallogpmf', 4, 4];
        yield 'betabinomialcdf' => ['betabinomialcdf', 4, 4];
        yield 'betabinomialccdf' => ['betabinomialccdf', 4, 4];
        yield 'betabinomialccinv' => ['betabinomialccinv', 4, 4];
        yield 'betabinomiallogcdf' => ['betabinomiallogcdf', 4, 4];
        yield 'betabinomiallogccdf' => ['betabinomiallogccdf', 4, 4];
        yield 'betabinomialinv' => ['betabinomialinv', 4, 4];
        yield 'betabinomialmean' => ['betabinomialmean', 3, 3];
        yield 'betabinomialvariance' => ['betabinomialvariance', 3, 3];
        yield 'betabinomialvar' => ['betabinomialvar', 3, 3];
        yield 'betabinomialstddev' => ['betabinomialstddev', 3, 3];
        yield 'betabinomialentropy' => ['betabinomialentropy', 3, 3];
        yield 'betabinomialmedian' => ['betabinomialmedian', 3, 3];
        yield 'betabinomialmode' => ['betabinomialmode', 3, 3];
        yield 'betabinomialmoment' => ['betabinomialmoment', 4, 4];
        yield 'betabinomialrawmoment' => ['betabinomialrawmoment', 4, 4];
        yield 'betabinomialmgf' => ['betabinomialmgf', 4, 4];
        yield 'betabinomiallogmgf' => ['betabinomiallogmgf', 4, 4];
        yield 'betabinomialskewness' => ['betabinomialskewness', 3, 3];
        yield 'betabinomialexcesskurtosis' => ['betabinomialexcesskurtosis', 3, 3];
        yield 'betabinomialkurtosis' => ['betabinomialkurtosis', 3, 3];
        yield 'discreteuniformpmf' => ['discreteuniformpmf', 3, 3];
        yield 'discreteuniformlogpmf' => ['discreteuniformlogpmf', 3, 3];
        yield 'discreteuniformcdf' => ['discreteuniformcdf', 3, 3];
        yield 'discreteuniformccdf' => ['discreteuniformccdf', 3, 3];
        yield 'discreteuniformccinv' => ['discreteuniformccinv', 3, 3];
        yield 'discreteuniformlogcdf' => ['discreteuniformlogcdf', 3, 3];
        yield 'discreteuniformlogccdf' => ['discreteuniformlogccdf', 3, 3];
        yield 'discreteuniforminv' => ['discreteuniforminv', 3, 3];
        yield 'discreteuniformmean' => ['discreteuniformmean', 2, 2];
        yield 'discreteuniformvariance' => ['discreteuniformvariance', 2, 2];
        yield 'discreteuniformvar' => ['discreteuniformvar', 2, 2];
        yield 'discreteuniformstddev' => ['discreteuniformstddev', 2, 2];
        yield 'discreteuniformentropy' => ['discreteuniformentropy', 2, 2];
        yield 'discreteuniformskewness' => ['discreteuniformskewness', 2, 2];
        yield 'discreteuniformexcesskurtosis' => ['discreteuniformexcesskurtosis', 2, 2];
        yield 'discreteuniformkurtosis' => ['discreteuniformkurtosis', 2, 2];
        yield 'discreteuniformmoment' => ['discreteuniformmoment', 3, 3];
        yield 'discreteuniformrawmoment' => ['discreteuniformrawmoment', 3, 3];
        yield 'discreteuniformmedian' => ['discreteuniformmedian', 2, 2];
        yield 'discreteuniformmode' => ['discreteuniformmode', 2, 2];
        yield 'discreteuniformmgf' => ['discreteuniformmgf', 3, 3];
        yield 'discreteuniformlogmgf' => ['discreteuniformlogmgf', 3, 3];
        yield 'zipfpmf' => ['zipfpmf', 3, 3];
        yield 'zipflogpmf' => ['zipflogpmf', 3, 3];
        yield 'zipfcdf' => ['zipfcdf', 3, 3];
        yield 'zipfccdf' => ['zipfccdf', 3, 3];
        yield 'zipfinv' => ['zipfinv', 3, 3];
        yield 'zipfccinv' => ['zipfccinv', 3, 3];
        yield 'zipfmean' => ['zipfmean', 2, 2];
        yield 'zipfvariance' => ['zipfvariance', 2, 2];
        yield 'zipfvar' => ['zipfvar', 2, 2];
        yield 'zipfstddev' => ['zipfstddev', 2, 2];
        yield 'zipfmedian' => ['zipfmedian', 2, 2];
        yield 'zipfmode' => ['zipfmode', 2, 2];
        yield 'zipfentropy' => ['zipfentropy', 2, 2];
        yield 'zipfskewness' => ['zipfskewness', 2, 2];
        yield 'zipfexcesskurtosis' => ['zipfexcesskurtosis', 2, 2];
        yield 'zipfkurtosis' => ['zipfkurtosis', 2, 2];
        yield 'zipfmoment' => ['zipfmoment', 3, 3];
        yield 'zipfrawmoment' => ['zipfrawmoment', 3, 3];
        yield 'zipflogmgf' => ['zipflogmgf', 3, 3];
        yield 'zipfmgf' => ['zipfmgf', 3, 3];
        yield 'benfordpmf' => ['benfordpmf', 1, 2];
        yield 'benfordlogpmf' => ['benfordlogpmf', 1, 2];
        yield 'benfordcdf' => ['benfordcdf', 1, 2];
        yield 'benfordccdf' => ['benfordccdf', 1, 2];
        yield 'benfordinv' => ['benfordinv', 1, 2];
        yield 'benfordccinv' => ['benfordccinv', 1, 2];
        yield 'benfordmean' => ['benfordmean', 1, 2];
        yield 'benfordmedian' => ['benfordmedian', 1, 2];
        yield 'benfordmode' => ['benfordmode', 1, 2];
        yield 'zetapmf' => ['zetapmf', 2, 2];
        yield 'zetalogpmf' => ['zetalogpmf', 2, 2];
        yield 'zetacdf' => ['zetacdf', 2, 2];
        yield 'zetaccdf' => ['zetaccdf', 2, 2];
        yield 'zetainv' => ['zetainv', 2, 2];
        yield 'zetaccinv' => ['zetaccinv', 2, 2];
        yield 'zetamean' => ['zetamean', 1, 1];
        yield 'zetavariance' => ['zetavariance', 1, 1];
        yield 'zetavar' => ['zetavar', 1, 1];
        yield 'zetastddev' => ['zetastddev', 1, 1];
        yield 'zetamedian' => ['zetamedian', 1, 1];
        yield 'zetamode' => ['zetamode', 1, 1];
        yield 'zetaentropy' => ['zetaentropy', 1, 1];
        yield 'zetaskewness' => ['zetaskewness', 1, 1];
        yield 'zetaexcesskurtosis' => ['zetaexcesskurtosis', 1, 1];
        yield 'zetakurtosis' => ['zetakurtosis', 1, 1];
        yield 'zetamoment' => ['zetamoment', 2, 2];
        yield 'zetarawmoment' => ['zetarawmoment', 2, 2];
        yield 'discretelaplacepmf' => ['discretelaplacepmf', 2, 2];
        yield 'discretelaplacelogpmf' => ['discretelaplacelogpmf', 2, 2];
        yield 'discretelaplacecdf' => ['discretelaplacecdf', 2, 2];
        yield 'discretelaplaceccdf' => ['discretelaplaceccdf', 2, 2];
        yield 'discretelaplaceinv' => ['discretelaplaceinv', 2, 2];
        yield 'discretelaplaceccinv' => ['discretelaplaceccinv', 2, 2];
        yield 'discretelaplacemean' => ['discretelaplacemean', 1, 1];
        yield 'discretelaplacevariance' => ['discretelaplacevariance', 1, 1];
        yield 'discretelaplacevar' => ['discretelaplacevar', 1, 1];
        yield 'discretelaplacestddev' => ['discretelaplacestddev', 1, 1];
        yield 'discretelaplacemedian' => ['discretelaplacemedian', 1, 1];
        yield 'discretelaplacemode' => ['discretelaplacemode', 1, 1];
        yield 'discretelaplaceentropy' => ['discretelaplaceentropy', 1, 1];
        yield 'discretelaplacemoment' => ['discretelaplacemoment', 2, 2];
        yield 'discretelaplacerawmoment' => ['discretelaplacerawmoment', 2, 2];
        yield 'discretelaplaceskewness' => ['discretelaplaceskewness', 1, 1];
        yield 'discretelaplaceexcesskurtosis' => ['discretelaplaceexcesskurtosis', 1, 1];
        yield 'discretelaplacekurtosis' => ['discretelaplacekurtosis', 1, 1];
        yield 'discretelaplacelogmgf' => ['discretelaplacelogmgf', 2, 2];
        yield 'discretelaplacemgf' => ['discretelaplacemgf', 2, 2];
        yield 'doublegeometricmean' => ['doublegeometricmean', 1, 1];
        yield 'doublegeometricvariance' => ['doublegeometricvariance', 1, 1];
        yield 'doublegeometricvar' => ['doublegeometricvar', 1, 1];
        yield 'doublegeometricstddev' => ['doublegeometricstddev', 1, 1];
        yield 'doublegeometricmedian' => ['doublegeometricmedian', 1, 1];
        yield 'doublegeometricmode' => ['doublegeometricmode', 1, 1];
        yield 'doublegeometricentropy' => ['doublegeometricentropy', 1, 1];
        yield 'doublegeometricmoment' => ['doublegeometricmoment', 2, 2];
        yield 'doublegeometricrawmoment' => ['doublegeometricrawmoment', 2, 2];
        yield 'doublegeometricskewness' => ['doublegeometricskewness', 1, 1];
        yield 'doublegeometricexcesskurtosis' => ['doublegeometricexcesskurtosis', 1, 1];
        yield 'doublegeometrickurtosis' => ['doublegeometrickurtosis', 1, 1];
        yield 'doublegeometriclogmgf' => ['doublegeometriclogmgf', 2, 2];
        yield 'doublegeometricmgf' => ['doublegeometricmgf', 2, 2];
        yield 'doublegeometricpmf' => ['doublegeometricpmf', 2, 2];
        yield 'doublegeometriclogpmf' => ['doublegeometriclogpmf', 2, 2];
        yield 'doublegeometriccdf' => ['doublegeometriccdf', 2, 2];
        yield 'doublegeometricccdf' => ['doublegeometricccdf', 2, 2];
        yield 'doublegeometricinv' => ['doublegeometricinv', 2, 2];
        yield 'doublegeometricccinv' => ['doublegeometricccinv', 2, 2];
        yield 'poissonpmf' => ['poissonpmf', 2, 2];
        yield 'poissonlogpmf' => ['poissonlogpmf', 2, 2];
        yield 'poissonmean' => ['poissonmean', 1, 1];
        yield 'poissonvariance' => ['poissonvariance', 1, 1];
        yield 'poissonstddev' => ['poissonstddev', 1, 1];
        yield 'poistddev' => ['poistddev', 1, 1];
        yield 'poissonmoment' => ['poissonmoment', 2, 2];
        yield 'poissonrawmoment' => ['poissonrawmoment', 2, 2];
        yield 'poimoment' => ['poimoment', 2, 2];
        yield 'poirawmoment' => ['poirawmoment', 2, 2];
        yield 'poissonmedian' => ['poissonmedian', 1, 1];
        yield 'poimedian' => ['poimedian', 1, 1];
        yield 'poissonmode' => ['poissonmode', 1, 1];
        yield 'poimode' => ['poimode', 1, 1];
        yield 'poissonskewness' => ['poissonskewness', 1, 1];
        yield 'poissonexcesskurtosis' => ['poissonexcesskurtosis', 1, 1];
        yield 'poissonkurtosis' => ['poissonkurtosis', 1, 1];
        yield 'poiskewness' => ['poiskewness', 1, 1];
        yield 'poiexcesskurtosis' => ['poiexcesskurtosis', 1, 1];
        yield 'poikurtosis' => ['poikurtosis', 1, 1];
        yield 'poissonvar' => ['poissonvar', 1, 1];
        yield 'poissonmgf' => ['poissonmgf', 2, 2];
        yield 'poissonlogmgf' => ['poissonlogmgf', 2, 2];
        yield 'poilogpmf' => ['poilogpmf', 2, 2];
        yield 'poipmf' => ['poipmf', 2, 2];
        yield 'poissoncdf' => ['poissoncdf', 2, 2];
        yield 'poicdf' => ['poicdf', 2, 2];
        yield 'poissonccdf' => ['poissonccdf', 2, 2];
        yield 'poiccdf' => ['poiccdf', 2, 2];
        yield 'poissonlogcdf' => ['poissonlogcdf', 2, 2];
        yield 'poilogcdf' => ['poilogcdf', 2, 2];
        yield 'poissonlogccdf' => ['poissonlogccdf', 2, 2];
        yield 'poilogccdf' => ['poilogccdf', 2, 2];
        yield 'poissoninv' => ['poissoninv', 2, 2];
        yield 'poissonccinv' => ['poissonccinv', 2, 2];
        yield 'poiccinv' => ['poiccinv', 2, 2];
        yield 'poiinv' => ['poiinv', 2, 2];
        yield 'poissonppf' => ['poissonppf', 2, 2];
        yield 'poippf' => ['poippf', 2, 2];
        yield 'negativebinomialpmf' => ['negativebinomialpmf', 3, 3];
        yield 'negativebinomiallogpmf' => ['negativebinomiallogpmf', 3, 3];
        yield 'nbinomlogpmf' => ['nbinomlogpmf', 3, 3];
        yield 'nbinompmf' => ['nbinompmf', 3, 3];
        yield 'negativebinomialcdf' => ['negativebinomialcdf', 3, 3];
        yield 'nbinomcdf' => ['nbinomcdf', 3, 3];
        yield 'negativebinomialccdf' => ['negativebinomialccdf', 3, 3];
        yield 'nbinomccdf' => ['nbinomccdf', 3, 3];
        yield 'negativebinomiallogcdf' => ['negativebinomiallogcdf', 3, 3];
        yield 'nbinomlogcdf' => ['nbinomlogcdf', 3, 3];
        yield 'negativebinomiallogccdf' => ['negativebinomiallogccdf', 3, 3];
        yield 'nbinomlogccdf' => ['nbinomlogccdf', 3, 3];
        yield 'negativebinomialinv' => ['negativebinomialinv', 3, 3];
        yield 'negativebinomialccinv' => ['negativebinomialccinv', 3, 3];
        yield 'nbomccinv' => ['nbomccinv', 3, 3];
        yield 'nbominv' => ['nbominv', 3, 3];
        yield 'negativebinomialskewness' => ['negativebinomialskewness', 2, 2];
        yield 'negativebinomialexcesskurtosis' => ['negativebinomialexcesskurtosis', 2, 2];
        yield 'negativebinomialkurtosis' => ['negativebinomialkurtosis', 2, 2];
        yield 'negativebinomialmedian' => ['negativebinomialmedian', 2, 2];
        yield 'nbinommedian' => ['nbinommedian', 2, 2];
        yield 'negativebinomialmode' => ['negativebinomialmode', 2, 2];
        yield 'nbinommode' => ['nbinommode', 2, 2];
        yield 'nbinomskewness' => ['nbinomskewness', 2, 2];
        yield 'nbinomexcesskurtosis' => ['nbinomexcesskurtosis', 2, 2];
        yield 'nbinomkurtosis' => ['nbinomkurtosis', 2, 2];
        yield 'negativebinomialmean' => ['negativebinomialmean', 2, 2];
        yield 'nbinommean' => ['nbinommean', 2, 2];
        yield 'negativebinomialvariance' => ['negativebinomialvariance', 2, 2];
        yield 'nbinomvariance' => ['nbinomvariance', 2, 2];
        yield 'nbinomvar' => ['nbinomvar', 2, 2];
        yield 'negativebinomialstddev' => ['negativebinomialstddev', 2, 2];
        yield 'nbinomstddev' => ['nbinomstddev', 2, 2];
        yield 'negativebinomialmgf' => ['negativebinomialmgf', 3, 3];
        yield 'nbinommgf' => ['nbinommgf', 3, 3];
        yield 'negativebinomiallogmgf' => ['negativebinomiallogmgf', 3, 3];
        yield 'nbinomlogmgf' => ['nbinomlogmgf', 3, 3];
        yield 'negativebinomialmoment' => ['negativebinomialmoment', 3, 3];
        yield 'negativebinomialrawmoment' => ['negativebinomialrawmoment', 3, 3];
        yield 'nbinomrawmoment' => ['nbinomrawmoment', 3, 3];
        yield 'hypergeometricpmf' => ['hypergeometricpmf', 4, 4];
        yield 'hypergeometriclogpmf' => ['hypergeometriclogpmf', 4, 4];
        yield 'hypergeomlogpmf' => ['hypergeomlogpmf', 4, 4];
        yield 'multivariatehypergeometricpmf' => ['multivariatehypergeometricpmf', 4, 32];
        yield 'multivariatehypergeometriclogpmf' => ['multivariatehypergeometriclogpmf', 4, 32];
        yield 'multihypergeometricpmf' => ['multihypergeometricpmf', 4, 32];
        yield 'multihypergeometriclogpmf' => ['multihypergeometriclogpmf', 4, 32];
        yield 'multivariatehypergeometricmean' => ['multivariatehypergeometricmean', 3, 3];
        yield 'multivariatehypergeometricvariance' => ['multivariatehypergeometricvariance', 3, 3];
        yield 'multivariatehypergeometricstddev' => ['multivariatehypergeometricstddev', 3, 3];
        yield 'multivariatehypergeometricmoment' => ['multivariatehypergeometricmoment', 4, 4];
        yield 'multivariatehypergeometricrawmoment' => ['multivariatehypergeometricrawmoment', 4, 4];
        yield 'multivariatehypergeometriccovariance' => ['multivariatehypergeometriccovariance', 4, 4];
        yield 'hypergeompmf' => ['hypergeompmf', 4, 4];
        yield 'hypergeometriccdf' => ['hypergeometriccdf', 4, 4];
        yield 'hypergeomcdf' => ['hypergeomcdf', 4, 4];
        yield 'hypergeometricccdf' => ['hypergeometricccdf', 4, 4];
        yield 'hypergeomccdf' => ['hypergeomccdf', 4, 4];
        yield 'hypergeometriclogcdf' => ['hypergeometriclogcdf', 4, 4];
        yield 'hypergeomlogcdf' => ['hypergeomlogcdf', 4, 4];
        yield 'hypergeometriclogccdf' => ['hypergeometriclogccdf', 4, 4];
        yield 'hypergeomlogccdf' => ['hypergeomlogccdf', 4, 4];
        yield 'hypergeometricinv' => ['hypergeometricinv', 4, 4];
        yield 'hypergeometricccinv' => ['hypergeometricccinv', 4, 4];
        yield 'hypergeomccinv' => ['hypergeomccinv', 4, 4];
        yield 'hypergeominv' => ['hypergeominv', 4, 4];
        yield 'hypergeometricmedian' => ['hypergeometricmedian', 3, 3];
        yield 'hypergeommedian' => ['hypergeommedian', 3, 3];
        yield 'hypergeometricmode' => ['hypergeometricmode', 3, 3];
        yield 'hypergeommode' => ['hypergeommode', 3, 3];
        yield 'hypergeometricmean' => ['hypergeometricmean', 3, 3];
        yield 'hypergeometricvariance' => ['hypergeometricvariance', 3, 3];
        yield 'hypergeometricstddev' => ['hypergeometricstddev', 3, 3];
        yield 'hypergeometricentropy' => ['hypergeometricentropy', 3, 3];
        yield 'hypergeometricskewness' => ['hypergeometricskewness', 3, 3];
        yield 'hypergeometricexcesskurtosis' => ['hypergeometricexcesskurtosis', 3, 3];
        yield 'hypergeometrickurtosis' => ['hypergeometrickurtosis', 3, 3];
        yield 'hypergeometricmoment' => ['hypergeometricmoment', 4, 4];
        yield 'hypergeometricrawmoment' => ['hypergeometricrawmoment', 4, 4];
        yield 'hypergeommoment' => ['hypergeommoment', 4, 4];
        yield 'hypergeomrawmoment' => ['hypergeomrawmoment', 4, 4];
        yield 'hypergeommean' => ['hypergeommean', 3, 3];
        yield 'hypergeomskewness' => ['hypergeomskewness', 3, 3];
        yield 'hypergeomexcesskurtosis' => ['hypergeomexcesskurtosis', 3, 3];
        yield 'hypergeomkurtosis' => ['hypergeomkurtosis', 3, 3];
        yield 'hypergeomvariance' => ['hypergeomvariance', 3, 3];
        yield 'hypergeomstddev' => ['hypergeomstddev', 3, 3];
        yield 'hypergeomentropy' => ['hypergeomentropy', 3, 3];
        yield 'hypergeometricmgf' => ['hypergeometricmgf', 4, 4];
        yield 'hypergeommgf' => ['hypergeommgf', 4, 4];
        yield 'hypergeometriclogmgf' => ['hypergeometriclogmgf', 4, 4];
        yield 'hypergeomlogmgf' => ['hypergeomlogmgf', 4, 4];
        yield 'negativehypergeometricpmf' => ['negativehypergeometricpmf', 4, 4];
        yield 'negativehypergeometriclogpmf' => ['negativehypergeometriclogpmf', 4, 4];
        yield 'neghypergeomlogpmf' => ['neghypergeomlogpmf', 4, 4];
        yield 'negativehypergeometriccdf' => ['negativehypergeometriccdf', 4, 4];
        yield 'negativehypergeometricccdf' => ['negativehypergeometricccdf', 4, 4];
        yield 'negativehypergeometriclogcdf' => ['negativehypergeometriclogcdf', 4, 4];
        yield 'negativehypergeometriclogccdf' => ['negativehypergeometriclogccdf', 4, 4];
        yield 'negativehypergeometricinv' => ['negativehypergeometricinv', 4, 4];
        yield 'negativehypergeometricccinv' => ['negativehypergeometricccinv', 4, 4];
        yield 'negativehypergeometricmoment' => ['negativehypergeometricmoment', 4, 4];
        yield 'negativehypergeometricrawmoment' => ['negativehypergeometricrawmoment', 4, 4];
        yield 'neghypergeommoment' => ['neghypergeommoment', 4, 4];
        yield 'neghypergeomrawmoment' => ['neghypergeomrawmoment', 4, 4];
        yield 'negativehypergeometricmgf' => ['negativehypergeometricmgf', 4, 4];
        yield 'negativehypergeometriclogmgf' => ['negativehypergeometriclogmgf', 4, 4];
        yield 'neghypergeommgf' => ['neghypergeommgf', 4, 4];
        yield 'neghypergeomlogmgf' => ['neghypergeomlogmgf', 4, 4];
        yield 'negativehypergeometricmean' => ['negativehypergeometricmean', 3, 3];
        yield 'negativehypergeometricvariance' => ['negativehypergeometricvariance', 3, 3];
        yield 'negativehypergeometricstddev' => ['negativehypergeometricstddev', 3, 3];
        yield 'negativehypergeometricmedian' => ['negativehypergeometricmedian', 3, 3];
        yield 'negativehypergeometricmode' => ['negativehypergeometricmode', 3, 3];
        yield 'negativehypergeometricentropy' => ['negativehypergeometricentropy', 3, 3];
        yield 'negativehypergeometricskewness' => ['negativehypergeometricskewness', 3, 3];
        yield 'negativehypergeometricexcesskurtosis' => ['negativehypergeometricexcesskurtosis', 3, 3];
        yield 'negativehypergeometrickurtosis' => ['negativehypergeometrickurtosis', 3, 3];
        yield 'neghypergeomskewness' => ['neghypergeomskewness', 3, 3];
        yield 'neghypergeomexcesskurtosis' => ['neghypergeomexcesskurtosis', 3, 3];
        yield 'neghypergeometrickurtosis' => ['neghypergeometrickurtosis', 3, 3];
        yield 'neghypergeompmf' => ['neghypergeompmf', 4, 4];
        yield 'neghypergeomcdf' => ['neghypergeomcdf', 4, 4];
        yield 'neghypergeomccdf' => ['neghypergeomccdf', 4, 4];
        yield 'neghypergeomlogcdf' => ['neghypergeomlogcdf', 4, 4];
        yield 'neghypergeomlogccdf' => ['neghypergeomlogccdf', 4, 4];
        yield 'neghypergeominv' => ['neghypergeominv', 4, 4];
        yield 'neghypergeomccinv' => ['neghypergeomccinv', 4, 4];
        yield 'neghypergeommean' => ['neghypergeommean', 3, 3];
        yield 'neghypergeomvariance' => ['neghypergeomvariance', 3, 3];
        yield 'neghypergeomstddev' => ['neghypergeomstddev', 3, 3];
        yield 'neghypergeommedian' => ['neghypergeommedian', 3, 3];
        yield 'neghypergeommode' => ['neghypergeommode', 3, 3];
        yield 'neghypergeomentropy' => ['neghypergeomentropy', 3, 3];
        yield 'chisquarepdf' => ['chisquarepdf', 2, 2];
        yield 'chipdf' => ['chipdf', 2, 2];
        yield 'chisquarelogpdf' => ['chisquarelogpdf', 2, 2];
        yield 'chilogpdf' => ['chilogpdf', 2, 2];
        yield 'chisquarecdf' => ['chisquarecdf', 2, 2];
        yield 'chicdf' => ['chicdf', 2, 2];
        yield 'chisquareccdf' => ['chisquareccdf', 2, 2];
        yield 'chiccdf' => ['chiccdf', 2, 2];
        yield 'chisquareinv' => ['chisquareinv', 2, 2];
        yield 'chiinv' => ['chiinv', 2, 2];
        yield 'chisquareccinv' => ['chisquareccinv', 2, 2];
        yield 'chiccinv' => ['chiccinv', 2, 2];
        yield 'chisquareinvprime' => ['chisquareinvprime', 2, 2];
        yield 'chiinvprime' => ['chiinvprime', 2, 2];
        yield 'chisquareccinvprime' => ['chisquareccinvprime', 2, 2];
        yield 'chiccinvprime' => ['chiccinvprime', 2, 2];
        yield 'chisquarelogmgf' => ['chisquarelogmgf', 2, 2];
        yield 'chilogmgf' => ['chilogmgf', 2, 2];
        yield 'chisquaremgf' => ['chisquaremgf', 2, 2];
        yield 'chimgf' => ['chimgf', 2, 2];
        yield 'chisquaremean' => ['chisquaremean', 1, 1];
        yield 'chimean' => ['chimean', 1, 1];
        yield 'chisquarevariance' => ['chisquarevariance', 1, 1];
        yield 'chivariance' => ['chivariance', 1, 1];
        yield 'chivar' => ['chivar', 1, 1];
        yield 'chisquarestddev' => ['chisquarestddev', 1, 1];
        yield 'chistddev' => ['chistddev', 1, 1];
        yield 'chisquaremedian' => ['chisquaremedian', 1, 1];
        yield 'chimedian' => ['chimedian', 1, 1];
        yield 'chisquaremode' => ['chisquaremode', 1, 1];
        yield 'chimode' => ['chimode', 1, 1];
        yield 'chisquareentropy' => ['chisquareentropy', 1, 1];
        yield 'chientropy' => ['chientropy', 1, 1];
        yield 'chisquarehazard' => ['chisquarehazard', 2, 2];
        yield 'chihazard' => ['chihazard', 2, 2];
        yield 'chisquarecumhazard' => ['chisquarecumhazard', 2, 2];
        yield 'chicumhazard' => ['chicumhazard', 2, 2];
        yield 'chisquareskewness' => ['chisquareskewness', 1, 1];
        yield 'chiskewness' => ['chiskewness', 1, 1];
        yield 'chisquareexcesskurtosis' => ['chisquareexcesskurtosis', 1, 1];
        yield 'chiexcesskurtosis' => ['chiexcesskurtosis', 1, 1];
        yield 'chisquarekurtosis' => ['chisquarekurtosis', 1, 1];
        yield 'chikurtosis' => ['chikurtosis', 1, 1];
        yield 'exponentialpdf' => ['exponentialpdf', 2, 2];
        yield 'exponentiallogpdf' => ['exponentiallogpdf', 2, 2];
        yield 'exppdf' => ['exppdf', 2, 2];
        yield 'exponentialcdf' => ['exponentialcdf', 2, 2];
        yield 'expcdf' => ['expcdf', 2, 2];
        yield 'exponentialccdf' => ['exponentialccdf', 2, 2];
        yield 'expccdf' => ['expccdf', 2, 2];
        yield 'exponentialinv' => ['exponentialinv', 2, 2];
        yield 'exponentialinvprime' => ['exponentialinvprime', 2, 2];
        yield 'expinvprime' => ['expinvprime', 2, 2];
        yield 'exponentialccinvprime' => ['exponentialccinvprime', 2, 2];
        yield 'expccinvprime' => ['expccinvprime', 2, 2];
        yield 'exponentialccinv' => ['exponentialccinv', 2, 2];
        yield 'expccinv' => ['expccinv', 2, 2];
        yield 'exponentialmgf' => ['exponentialmgf', 2, 2];
        yield 'expmgf' => ['expmgf', 2, 2];
        yield 'exponentiallogmgf' => ['exponentiallogmgf', 2, 2];
        yield 'explogmgf' => ['explogmgf', 2, 2];
        yield 'exponentialmoment' => ['exponentialmoment', 2, 2];
        yield 'exponentialrawmoment' => ['exponentialrawmoment', 2, 2];
        yield 'exponentialmean' => ['exponentialmean', 1, 1];
        yield 'expmean' => ['expmean', 1, 1];
        yield 'exponentialmedian' => ['exponentialmedian', 1, 1];
        yield 'expmedian' => ['expmedian', 1, 1];
        yield 'exponentialmode' => ['exponentialmode', 1, 1];
        yield 'expmode' => ['expmode', 1, 1];
        yield 'exponentialvariance' => ['exponentialvariance', 1, 1];
        yield 'expvariance' => ['expvariance', 1, 1];
        yield 'expvar' => ['expvar', 1, 1];
        yield 'exponentialstddev' => ['exponentialstddev', 1, 1];
        yield 'expstddev' => ['expstddev', 1, 1];
        yield 'exponentialentropy' => ['exponentialentropy', 1, 1];
        yield 'expentropy' => ['expentropy', 1, 1];
        yield 'exponentialhazard' => ['exponentialhazard', 2, 2];
        yield 'exponentialcumhazard' => ['exponentialcumhazard', 2, 2];
        yield 'exponentialskewness' => ['exponentialskewness', 1, 1];
        yield 'expskewness' => ['expskewness', 1, 1];
        yield 'exponentialexcesskurtosis' => ['exponentialexcesskurtosis', 1, 1];
        yield 'expexcesskurtosis' => ['expexcesskurtosis', 1, 1];
        yield 'exponentialkurtosis' => ['exponentialkurtosis', 1, 1];
        yield 'expkurtosis' => ['expkurtosis', 1, 1];
        yield 'rayleighlogpdf' => ['rayleighlogpdf', 2, 2];
        yield 'rayleighlogcdf' => ['rayleighlogcdf', 2, 2];
        yield 'rayleighlogccdf' => ['rayleighlogccdf', 2, 2];
        yield 'expinv' => ['expinv', 2, 2];
        yield 'uniformpdf' => ['uniformpdf', 3, 3];
        yield 'uniformlogpdf' => ['uniformlogpdf', 3, 3];
        yield 'uniformcdf' => ['uniformcdf', 3, 3];
        yield 'uniformccdf' => ['uniformccdf', 3, 3];
        yield 'uniforminv' => ['uniforminv', 3, 3];
        yield 'uniformccinv' => ['uniformccinv', 3, 3];
        yield 'uniformmean' => ['uniformmean', 2, 2];
        yield 'uniformvariance' => ['uniformvariance', 2, 2];
        yield 'uniformvar' => ['uniformvar', 2, 2];
        yield 'uniformmoment' => ['uniformmoment', 3, 3];
        yield 'uniformrawmoment' => ['uniformrawmoment', 3, 3];
        yield 'uniformmedian' => ['uniformmedian', 2, 2];
        yield 'uniformmode' => ['uniformmode', 2, 2];
        yield 'uniformskewness' => ['uniformskewness', 2, 2];
        yield 'uniformexcesskurtosis' => ['uniformexcesskurtosis', 2, 2];
        yield 'uniformkurtosis' => ['uniformkurtosis', 2, 2];
        yield 'weibullpdf' => ['weibullpdf', 3, 3];
        yield 'weibulllogpdf' => ['weibulllogpdf', 3, 3];
        yield 'weibullcdf' => ['weibullcdf', 3, 3];
        yield 'weibullccdf' => ['weibullccdf', 3, 3];
        yield 'weibulllogcdf' => ['weibulllogcdf', 3, 3];
        yield 'weibulllogccdf' => ['weibulllogccdf', 3, 3];
        yield 'weibullinv' => ['weibullinv', 3, 3];
        yield 'weibullinvprime' => ['weibullinvprime', 3, 3];
        yield 'weibullccinvprime' => ['weibullccinvprime', 3, 3];
        yield 'weibullccinv' => ['weibullccinv', 3, 3];
        yield 'weibullmode' => ['weibullmode', 2, 2];
        yield 'weibullmean' => ['weibullmean', 2, 2];
        yield 'weibullmedian' => ['weibullmedian', 2, 2];
        yield 'weibullhazard' => ['weibullhazard', 3, 3];
        yield 'weibullcumhazard' => ['weibullcumhazard', 3, 3];
        yield 'weibullvariance' => ['weibullvariance', 2, 2];
        yield 'weibullvar' => ['weibullvar', 2, 2];
        yield 'weibullstddev' => ['weibullstddev', 2, 2];
        yield 'weibullentropy' => ['weibullentropy', 2, 2];
        yield 'weibullmoment' => ['weibullmoment', 3, 3];
        yield 'weibullrawmoment' => ['weibullrawmoment', 3, 3];
        yield 'lognormalpdf' => ['lognormalpdf', 3, 3];
        yield 'lognormaldistlogpdf' => ['lognormaldistlogpdf', 3, 3];
        yield 'lognormallogpdf' => ['lognormallogpdf', 3, 3];
        yield 'lognormlogpdf' => ['lognormlogpdf', 3, 3];
        yield 'lognormalcdf' => ['lognormalcdf', 3, 3];
        yield 'lognormalccdf' => ['lognormalccdf', 3, 3];
        yield 'lognormallogcdf' => ['lognormallogcdf', 3, 3];
        yield 'lognormlogcdf' => ['lognormlogcdf', 3, 3];
        yield 'lognormallogccdf' => ['lognormallogccdf', 3, 3];
        yield 'lognormlogccdf' => ['lognormlogccdf', 3, 3];
        yield 'lognormalinv' => ['lognormalinv', 3, 3];
        yield 'lognormalccinv' => ['lognormalccinv', 3, 3];
        yield 'lognormalinvprime' => ['lognormalinvprime', 3, 3];
        yield 'lognormalccinvprime' => ['lognormalccinvprime', 3, 3];
        yield 'lognormalhazard' => ['lognormalhazard', 3, 3];
        yield 'lognormalcumhazard' => ['lognormalcumhazard', 3, 3];
        yield 'lognormalmean' => ['lognormalmean', 2, 2];
        yield 'lognormalmedian' => ['lognormalmedian', 2, 2];
        yield 'lognormalmode' => ['lognormalmode', 2, 2];
        yield 'lognormalentropy' => ['lognormalentropy', 2, 2];
        yield 'lognormalvariance' => ['lognormalvariance', 2, 2];
        yield 'lognormalvar' => ['lognormalvar', 2, 2];
        yield 'lognormalskewness' => ['lognormalskewness', 2, 2];
        yield 'lognormalexcesskurtosis' => ['lognormalexcesskurtosis', 2, 2];
        yield 'lognormalkurtosis' => ['lognormalkurtosis', 2, 2];
        yield 'logisticpdf' => ['logisticpdf', 3, 3];
        yield 'logisticlogpdf' => ['logisticlogpdf', 3, 3];
        yield 'logisticcdf' => ['logisticcdf', 3, 3];
        yield 'logisticccdf' => ['logisticccdf', 3, 3];
        yield 'logisticlogcdf' => ['logisticlogcdf', 3, 3];
        yield 'logisticlogccdf' => ['logisticlogccdf', 3, 3];
        yield 'logisticinv' => ['logisticinv', 3, 3];
        yield 'logisticccinv' => ['logisticccinv', 3, 3];
        yield 'logisticinvprime' => ['logisticinvprime', 3, 3];
        yield 'logisticccinvprime' => ['logisticccinvprime', 3, 3];
        yield 'logisticmean' => ['logisticmean', 2, 2];
        yield 'logisticmedian' => ['logisticmedian', 2, 2];
        yield 'logisticmode' => ['logisticmode', 2, 2];
        yield 'logisticentropy' => ['logisticentropy', 2, 2];
        yield 'logistichazard' => ['logistichazard', 3, 3];
        yield 'logisticcumhazard' => ['logisticcumhazard', 3, 3];
        yield 'logisticvariance' => ['logisticvariance', 2, 2];
        yield 'logisticvar' => ['logisticvar', 2, 2];
        yield 'logisticstddev' => ['logisticstddev', 2, 2];
        yield 'logisticskewness' => ['logisticskewness', 2, 2];
        yield 'logisticexcesskurtosis' => ['logisticexcesskurtosis', 2, 2];
        yield 'logistickurtosis' => ['logistickurtosis', 2, 2];
        yield 'logisticmgf' => ['logisticmgf', 3, 3];
        yield 'logisticlogmgf' => ['logisticlogmgf', 3, 3];
        yield 'logisticmoment' => ['logisticmoment', 3, 3];
        yield 'logisticrawmoment' => ['logisticrawmoment', 3, 3];
        yield 'loglogisticpdf' => ['loglogisticpdf', 3, 3];
        yield 'loglogisticlogpdf' => ['loglogisticlogpdf', 3, 3];
        yield 'loglogisticcdf' => ['loglogisticcdf', 3, 3];
        yield 'loglogisticccdf' => ['loglogisticccdf', 3, 3];
        yield 'loglogisticlogcdf' => ['loglogisticlogcdf', 3, 3];
        yield 'loglogisticlogccdf' => ['loglogisticlogccdf', 3, 3];
        yield 'loglogisticinv' => ['loglogisticinv', 3, 3];
        yield 'loglogisticmoment' => ['loglogisticmoment', 3, 3];
        yield 'loglogisticrawmoment' => ['loglogisticrawmoment', 3, 3];
        yield 'loglogisticmean' => ['loglogisticmean', 2, 2];
        yield 'loglogisticmedian' => ['loglogisticmedian', 2, 2];
        yield 'loglogisticmode' => ['loglogisticmode', 2, 2];
        yield 'loglogisticvariance' => ['loglogisticvariance', 2, 2];
        yield 'loglogisticvar' => ['loglogisticvar', 2, 2];
        yield 'loglogisticstddev' => ['loglogisticstddev', 2, 2];
        yield 'loglogisticentropy' => ['loglogisticentropy', 2, 2];
        yield 'loglogistichazard' => ['loglogistichazard', 3, 3];
        yield 'loglogisticcumhazard' => ['loglogisticcumhazard', 3, 3];
        yield 'fiskpdf' => ['fiskpdf', 3, 3];
        yield 'fisklogpdf' => ['fisklogpdf', 3, 3];
        yield 'fiskcdf' => ['fiskcdf', 3, 3];
        yield 'fiskccdf' => ['fiskccdf', 3, 3];
        yield 'fisklogcdf' => ['fisklogcdf', 3, 3];
        yield 'fisklogccdf' => ['fisklogccdf', 3, 3];
        yield 'fiskinv' => ['fiskinv', 3, 3];
        yield 'fiskmoment' => ['fiskmoment', 3, 3];
        yield 'fiskrawmoment' => ['fiskrawmoment', 3, 3];
        yield 'fiskmean' => ['fiskmean', 2, 2];
        yield 'fiskmedian' => ['fiskmedian', 2, 2];
        yield 'fiskmode' => ['fiskmode', 2, 2];
        yield 'fiskvariance' => ['fiskvariance', 2, 2];
        yield 'fiskvar' => ['fiskvar', 2, 2];
        yield 'fiskstddev' => ['fiskstddev', 2, 2];
        yield 'fiskentropy' => ['fiskentropy', 2, 2];
        yield 'fiskhazard' => ['fiskhazard', 3, 3];
        yield 'fiskcumhazard' => ['fiskcumhazard', 3, 3];
        yield 'laplacepdf' => ['laplacepdf', 3, 3];
        yield 'laplacelogpdf' => ['laplacelogpdf', 3, 3];
        yield 'laplogpdf' => ['laplogpdf', 3, 3];
        yield 'laplacecdf' => ['laplacecdf', 3, 3];
        yield 'laplaceccdf' => ['laplaceccdf', 3, 3];
        yield 'laplacelogcdf' => ['laplacelogcdf', 3, 3];
        yield 'laplacelogccdf' => ['laplacelogccdf', 3, 3];
        yield 'laplaceinv' => ['laplaceinv', 3, 3];
        yield 'laplaceccinv' => ['laplaceccinv', 3, 3];
        yield 'laplacemean' => ['laplacemean', 2, 2];
        yield 'laplacevariance' => ['laplacevariance', 2, 2];
        yield 'laplacevar' => ['laplacevar', 2, 2];
        yield 'laplacestddev' => ['laplacestddev', 2, 2];
        yield 'laplacemedian' => ['laplacemedian', 2, 2];
        yield 'laplacemode' => ['laplacemode', 2, 2];
        yield 'laplaceentropy' => ['laplaceentropy', 2, 2];
        yield 'laplaceskewness' => ['laplaceskewness', 2, 2];
        yield 'laplaceexcesskurtosis' => ['laplaceexcesskurtosis', 2, 2];
        yield 'laplacekurtosis' => ['laplacekurtosis', 2, 2];
        yield 'laplacemoment' => ['laplacemoment', 3, 3];
        yield 'laplacerawmoment' => ['laplacerawmoment', 3, 3];
        yield 'laplacemgf' => ['laplacemgf', 3, 3];
        yield 'laplacelogmgf' => ['laplacelogmgf', 3, 3];
        yield 'asymmetriclaplacepdf' => ['asymmetriclaplacepdf', 4, 4];
        yield 'asymmetriclaplacelogpdf' => ['asymmetriclaplacelogpdf', 4, 4];
        yield 'asymmetriclaplacecdf' => ['asymmetriclaplacecdf', 4, 4];
        yield 'asymmetriclaplaceccdf' => ['asymmetriclaplaceccdf', 4, 4];
        yield 'asymmetriclaplacelogcdf' => ['asymmetriclaplacelogcdf', 4, 4];
        yield 'asymmetriclaplacelogccdf' => ['asymmetriclaplacelogccdf', 4, 4];
        yield 'asymmetriclaplaceinv' => ['asymmetriclaplaceinv', 4, 4];
        yield 'asymmetriclaplacemean' => ['asymmetriclaplacemean', 3, 3];
        yield 'asymmetriclaplacevariance' => ['asymmetriclaplacevariance', 3, 3];
        yield 'asymmetriclaplacevar' => ['asymmetriclaplacevar', 3, 3];
        yield 'asymmetriclaplacestddev' => ['asymmetriclaplacestddev', 3, 3];
        yield 'asymmetriclaplacemedian' => ['asymmetriclaplacemedian', 3, 3];
        yield 'asymmetriclaplacemode' => ['asymmetriclaplacemode', 3, 3];
        yield 'asymmetriclaplaceentropy' => ['asymmetriclaplaceentropy', 3, 3];
        yield 'asymmetriclaplaceskewness' => ['asymmetriclaplaceskewness', 3, 3];
        yield 'asymmetriclaplaceexcesskurtosis' => ['asymmetriclaplaceexcesskurtosis', 3, 3];
        yield 'asymmetriclaplacekurtosis' => ['asymmetriclaplacekurtosis', 3, 3];
        yield 'asymmetriclaplacemoment' => ['asymmetriclaplacemoment', 4, 4];
        yield 'asymmetriclaplacerawmoment' => ['asymmetriclaplacerawmoment', 4, 4];
        yield 'asymmetriclaplacemgf' => ['asymmetriclaplacemgf', 4, 4];
        yield 'asymmetriclaplacelogmgf' => ['asymmetriclaplacelogmgf', 4, 4];
        yield 'fdistributionpdf' => ['fdistributionpdf', 3, 3];
        yield 'fpdf' => ['fpdf', 3, 3];
        yield 'fdistributionlogpdf' => ['fdistributionlogpdf', 3, 3];
        yield 'flogpdf' => ['flogpdf', 3, 3];
        yield 'fdistributioncdf' => ['fdistributioncdf', 3, 3];
        yield 'fcdf' => ['fcdf', 3, 3];
        yield 'fdistributionccdf' => ['fdistributionccdf', 3, 3];
        yield 'fccdf' => ['fccdf', 3, 3];
        yield 'fdistributionlogcdf' => ['fdistributionlogcdf', 3, 3];
        yield 'flogcdf' => ['flogcdf', 3, 3];
        yield 'fdistributionlogccdf' => ['fdistributionlogccdf', 3, 3];
        yield 'fdistributionhazard' => ['fdistributionhazard', 3, 3];
        yield 'fhazard' => ['fhazard', 3, 3];
        yield 'fdistributioncumhazard' => ['fdistributioncumhazard', 3, 3];
        yield 'fcumhazard' => ['fcumhazard', 3, 3];
        yield 'flogccdf' => ['flogccdf', 3, 3];
        yield 'fdistributioninv' => ['fdistributioninv', 3, 3];
        yield 'finv' => ['finv', 3, 3];
        yield 'fdistributionccinv' => ['fdistributionccinv', 3, 3];
        yield 'fdistributioninvprime' => ['fdistributioninvprime', 3, 3];
        yield 'finvprime' => ['finvprime', 3, 3];
        yield 'fdistributionccinvprime' => ['fdistributionccinvprime', 3, 3];
        yield 'fccinvprime' => ['fccinvprime', 3, 3];
        yield 'fccinv' => ['fccinv', 3, 3];
        yield 'fdistributionmean' => ['fdistributionmean', 2, 2];
        yield 'fdistributionmedian' => ['fdistributionmedian', 2, 2];
        yield 'fmedian' => ['fmedian', 2, 2];
        yield 'fdistributionmode' => ['fdistributionmode', 2, 2];
        yield 'fmode' => ['fmode', 2, 2];
        yield 'fdistributionentropy' => ['fdistributionentropy', 2, 2];
        yield 'fentropy' => ['fentropy', 2, 2];
        yield 'fmean' => ['fmean', 2, 2];
        yield 'fdistributionvariance' => ['fdistributionvariance', 2, 2];
        yield 'fvariance' => ['fvariance', 2, 2];
        yield 'fvar' => ['fvar', 2, 2];
        yield 'fdistributionskewness' => ['fdistributionskewness', 2, 2];
        yield 'fskewness' => ['fskewness', 2, 2];
        yield 'fdistributionexcesskurtosis' => ['fdistributionexcesskurtosis', 2, 2];
        yield 'fexcesskurtosis' => ['fexcesskurtosis', 2, 2];
        yield 'fdistributionkurtosis' => ['fdistributionkurtosis', 2, 2];
        yield 'fkurtosis' => ['fkurtosis', 2, 2];
        yield 'betadistributionpdf' => ['betadistributionpdf', 3, 3];
        yield 'betapdf' => ['betapdf', 3, 3];
        yield 'betadistributionlogpdf' => ['betadistributionlogpdf', 3, 3];
        yield 'betalogpdf' => ['betalogpdf', 3, 3];
        yield 'betadistributioncdf' => ['betadistributioncdf', 3, 3];
        yield 'betacdf' => ['betacdf', 3, 3];
        yield 'betadistributionccdf' => ['betadistributionccdf', 3, 3];
        yield 'betaccdf' => ['betaccdf', 3, 3];
        yield 'betadistributionlogcdf' => ['betadistributionlogcdf', 3, 3];
        yield 'betalogcdf' => ['betalogcdf', 3, 3];
        yield 'betadistributionlogccdf' => ['betadistributionlogccdf', 3, 3];
        yield 'betalogccdf' => ['betalogccdf', 3, 3];
        yield 'betadistributioninv' => ['betadistributioninv', 3, 3];
        yield 'betainv' => ['betainv', 3, 3];
        yield 'betadistributionccinv' => ['betadistributionccinv', 3, 3];
        yield 'betaccinv' => ['betaccinv', 3, 3];
        yield 'betadistributioninvprime' => ['betadistributioninvprime', 3, 3];
        yield 'betainvprime' => ['betainvprime', 3, 3];
        yield 'betadistributionccinvprime' => ['betadistributionccinvprime', 3, 3];
        yield 'betaccinvprime' => ['betaccinvprime', 3, 3];
        yield 'betadistributionmean' => ['betadistributionmean', 2, 2];
        yield 'betamean' => ['betamean', 2, 2];
        yield 'betadistributionmedian' => ['betadistributionmedian', 2, 2];
        yield 'betamedian' => ['betamedian', 2, 2];
        yield 'betadistributionmode' => ['betadistributionmode', 2, 2];
        yield 'betamode' => ['betamode', 2, 2];
        yield 'betadistributionentropy' => ['betadistributionentropy', 2, 2];
        yield 'betaentropy' => ['betaentropy', 2, 2];
        yield 'betadistributionvariance' => ['betadistributionvariance', 2, 2];
        yield 'betavariance' => ['betavariance', 2, 2];
        yield 'betavar' => ['betavar', 2, 2];
        yield 'betadistributionskewness' => ['betadistributionskewness', 2, 2];
        yield 'betaskewness' => ['betaskewness', 2, 2];
        yield 'betadistributionexcesskurtosis' => ['betadistributionexcesskurtosis', 2, 2];
        yield 'betaexcesskurtosis' => ['betaexcesskurtosis', 2, 2];
        yield 'betadistributionkurtosis' => ['betadistributionkurtosis', 2, 2];
        yield 'betakurtosis' => ['betakurtosis', 2, 2];
        yield 'kumaraswamypdf' => ['kumaraswamypdf', 3, 3];
        yield 'kumaraswamylogpdf' => ['kumaraswamylogpdf', 3, 3];
        yield 'kumaraswamycdf' => ['kumaraswamycdf', 3, 3];
        yield 'kumaraswamyccdf' => ['kumaraswamyccdf', 3, 3];
        yield 'kumaraswamyinv' => ['kumaraswamyinv', 3, 3];
        yield 'kumaraswamymean' => ['kumaraswamymean', 2, 2];
        yield 'kumaraswamymedian' => ['kumaraswamymedian', 2, 2];
        yield 'kumaraswamymode' => ['kumaraswamymode', 2, 2];
        yield 'kumaraswamyvariance' => ['kumaraswamyvariance', 2, 2];
        yield 'kumaraswamyvar' => ['kumaraswamyvar', 2, 2];
        yield 'kumaraswamymoment' => ['kumaraswamymoment', 3, 3];
        yield 'kumaraswamyrawmoment' => ['kumaraswamyrawmoment', 3, 3];
        yield 'betaprimepdf' => ['betaprimepdf', 3, 3];
        yield 'beta2pdf' => ['beta2pdf', 3, 3];
        yield 'betaprime log-density registry name' => ['betaprime' . 'logpdf', 3, 3];
        yield 'beta2logpdf' => ['beta2logpdf', 3, 3];
        yield 'betaprimecdf' => ['betaprimecdf', 3, 3];
        yield 'beta2cdf' => ['beta2cdf', 3, 3];
        yield 'betaprimeccdf' => ['betaprimeccdf', 3, 3];
        yield 'beta2ccdf' => ['beta2ccdf', 3, 3];
        yield 'betaprimelogcdf' => ['betaprimelogcdf', 3, 3];
        yield 'beta2logcdf' => ['beta2logcdf', 3, 3];
        yield 'betaprimelogccdf' => ['betaprimelogccdf', 3, 3];
        yield 'beta2logccdf' => ['beta2logccdf', 3, 3];
        yield 'betaprimehazard' => ['betaprimehazard', 3, 3];
        yield 'beta2hazard' => ['beta2hazard', 3, 3];
        yield 'betaprimecumhazard' => ['betaprimecumhazard', 3, 3];
        yield 'beta2cumhazard' => ['beta2cumhazard', 3, 3];
        yield 'betaprimeinv' => ['betaprimeinv', 3, 3];
        yield 'beta2inv' => ['beta2inv', 3, 3];
        yield 'betaprimeccinv' => ['betaprimeccinv', 3, 3];
        yield 'beta2ccinv' => ['beta2ccinv', 3, 3];
        yield 'betaprimeinvprime' => ['betaprimeinvprime', 3, 3];
        yield 'beta2invprime' => ['beta2invprime', 3, 3];
        yield 'betaprimeccinvprime' => ['betaprimeccinvprime', 3, 3];
        yield 'beta2ccinvprime' => ['beta2ccinvprime', 3, 3];
        yield 'betaprimemoment' => ['betaprimemoment', 3, 3];
        yield 'betaprimerawmoment' => ['betaprimerawmoment', 3, 3];
        yield 'beta2moment' => ['beta2moment', 3, 3];
        yield 'betaprimemean' => ['betaprimemean', 2, 2];
        yield 'beta2mean' => ['beta2mean', 2, 2];
        yield 'betaprimemedian' => ['betaprimemedian', 2, 2];
        yield 'beta2median' => ['beta2median', 2, 2];
        yield 'betaprimemode' => ['betaprimemode', 2, 2];
        yield 'beta2mode' => ['beta2mode', 2, 2];
        yield 'betaprimevariance' => ['betaprimevariance', 2, 2];
        yield 'betaprimevar' => ['betaprimevar', 2, 2];
        yield 'beta2variance' => ['beta2variance', 2, 2];
        yield 'beta2var' => ['beta2var', 2, 2];
        yield 'betaprimestddev' => ['betaprimestddev', 2, 2];
        yield 'beta2stddev' => ['beta2stddev', 2, 2];
        yield 'betaprimeskewness' => ['betaprimeskewness', 2, 2];
        yield 'beta2skewness' => ['beta2skewness', 2, 2];
        yield 'betaprimeexcesskurtosis' => ['betaprimeexcesskurtosis', 2, 2];
        yield 'beta2excesskurtosis' => ['beta2excesskurtosis', 2, 2];
        yield 'betaprimekurtosis' => ['betaprimekurtosis', 2, 2];
        yield 'beta2kurtosis' => ['beta2kurtosis', 2, 2];
        yield 'betaprimeentropy' => ['betaprimeentropy', 2, 2];
        yield 'beta2entropy' => ['beta2entropy', 2, 2];
        yield 'gammadistpdf' => ['gammadistpdf', 3, 3];
        yield 'gammapdf' => ['gammapdf', 3, 3];
        yield 'gammadistlogpdf' => ['gammadistlogpdf', 3, 3];
        yield 'gammalogpdf' => ['gammalogpdf', 3, 3];
        yield 'gammadistcdf' => ['gammadistcdf', 3, 3];
        yield 'gammadistlogcdf' => ['gammadistlogcdf', 3, 3];
        yield 'gammalogcdf' => ['gammalogcdf', 3, 3];
        yield 'gammadistlogccdf' => ['gammadistlogccdf', 3, 3];
        yield 'gammalogccdf' => ['gammalogccdf', 3, 3];
        yield 'gammacdf' => ['gammacdf', 3, 3];
        yield 'gammadistccdf' => ['gammadistccdf', 3, 3];
        yield 'gammaccdf' => ['gammaccdf', 3, 3];
        yield 'gammadistinv' => ['gammadistinv', 3, 3];
        yield 'gammadistccinv' => ['gammadistccinv', 3, 3];
        yield 'gammaccinv' => ['gammaccinv', 3, 3];
        yield 'gammadistinvprime' => ['gammadistinvprime', 3, 3];
        yield 'gammainvprime' => ['gammainvprime', 3, 3];
        yield 'gammadistccinvprime' => ['gammadistccinvprime', 3, 3];
        yield 'gammaccinvprime' => ['gammaccinvprime', 3, 3];
        yield 'gammadistmean' => ['gammadistmean', 2, 2];
        yield 'gammamean' => ['gammamean', 2, 2];
        yield 'gammadistmedian' => ['gammadistmedian', 2, 2];
        yield 'gammamedian' => ['gammamedian', 2, 2];
        yield 'gammadistmode' => ['gammadistmode', 2, 2];
        yield 'gammamode' => ['gammamode', 2, 2];
        yield 'gammadistentropy' => ['gammadistentropy', 2, 2];
        yield 'gammaentropy' => ['gammaentropy', 2, 2];
        yield 'gammadisthazard' => ['gammadisthazard', 3, 3];
        yield 'gammahazard' => ['gammahazard', 3, 3];
        yield 'gammadistcumhazard' => ['gammadistcumhazard', 3, 3];
        yield 'gammacumhazard' => ['gammacumhazard', 3, 3];
        yield 'gammadistvariance' => ['gammadistvariance', 2, 2];
        yield 'gammavariance' => ['gammavariance', 2, 2];
        yield 'gammavar' => ['gammavar', 2, 2];
        yield 'gammadiststddev' => ['gammadiststddev', 2, 2];
        yield 'gammastddev' => ['gammastddev', 2, 2];
        yield 'gammadistskewness' => ['gammadistskewness', 2, 2];
        yield 'gammaskewness' => ['gammaskewness', 2, 2];
        yield 'gammadistexcesskurtosis' => ['gammadistexcesskurtosis', 2, 2];
        yield 'gammadistkurtosis' => ['gammadistkurtosis', 2, 2];
        yield 'gammadistlogmgf' => ['gammadistlogmgf', 3, 3];
        yield 'gammalogmgf' => ['gammalogmgf', 3, 3];
        yield 'gammadistmgf' => ['gammadistmgf', 3, 3];
        yield 'gammamgf' => ['gammamgf', 3, 3];
        yield 'gammadistmoment' => ['gammadistmoment', 3, 3];
        yield 'gammamoment' => ['gammamoment', 3, 3];
        yield 'gammainv' => ['gammainv', 3, 3];
        yield 'cauchypdf' => ['cauchypdf', 3, 3];
        yield 'cauchylogpdf' => ['cauchylogpdf', 3, 3];
        yield 'cauchycdf' => ['cauchycdf', 3, 3];
        yield 'cauchyccdf' => ['cauchyccdf', 3, 3];
        yield 'cauchylogcdf' => ['cauchylogcdf', 3, 3];
        yield 'cauchylogccdf' => ['cauchylogccdf', 3, 3];
        yield 'cauchyinv' => ['cauchyinv', 3, 3];
        yield 'cauchyccinv' => ['cauchyccinv', 3, 3];
        yield 'cauchyinvprime' => ['cauchyinvprime', 3, 3];
        yield 'cauchyccinvprime' => ['cauchyccinvprime', 3, 3];
        yield 'cauchymedian' => ['cauchymedian', 2, 2];
        yield 'cauchymode' => ['cauchymode', 2, 2];
        yield 'cauchyentropy' => ['cauchyentropy', 2, 2];
        yield 'cauchyhazard' => ['cauchyhazard', 3, 3];
        yield 'cauchycumhazard' => ['cauchycumhazard', 3, 3];
        yield 'cauchycharfuncreal' => ['cauchycharfuncreal', 3, 3];
        yield 'cauchycharfuncimag' => ['cauchycharfuncimag', 3, 3];
        yield 'cauchyabsmoment' => ['cauchyabsmoment', 3, 3];
        yield 'cauchyabsolutemoment' => ['cauchyabsolutemoment', 3, 3];
        yield 'paretopdf' => ['paretopdf', 3, 3];
        yield 'paretologpdf' => ['paretologpdf', 3, 3];
        yield 'paretocdf' => ['paretocdf', 3, 3];
        yield 'paretoccdf' => ['paretoccdf', 3, 3];
        yield 'paretologcdf' => ['paretologcdf', 3, 3];
        yield 'paretologccdf' => ['paretologccdf', 3, 3];
        yield 'paretoinv' => ['paretoinv', 3, 3];
        yield 'paretoccinv' => ['paretoccinv', 3, 3];
        yield 'paretoinvprime' => ['paretoinvprime', 3, 3];
        yield 'paretoccinvprime' => ['paretoccinvprime', 3, 3];
        yield 'paretomoment' => ['paretomoment', 3, 3];
        yield 'paretorawmoment' => ['paretorawmoment', 3, 3];
        yield 'paretomean' => ['paretomean', 2, 2];
        yield 'paretomedian' => ['paretomedian', 2, 2];
        yield 'paretomode' => ['paretomode', 2, 2];
        yield 'paretovariance' => ['paretovariance', 2, 2];
        yield 'paretovar' => ['paretovar', 2, 2];
        yield 'paretostddev' => ['paretostddev', 2, 2];
        yield 'paretoentropy' => ['paretoentropy', 2, 2];
        yield 'paretoskewness' => ['paretoskewness', 2, 2];
        yield 'paretoexcesskurtosis' => ['paretoexcesskurtosis', 2, 2];
        yield 'paretokurtosis' => ['paretokurtosis', 2, 2];
        yield 'paretohazard' => ['paretohazard', 3, 3];
        yield 'paretocumhazard' => ['paretocumhazard', 3, 3];
        yield 'lomaxpdf' => ['lomaxpdf', 3, 3];
        yield 'lomaxlogpdf' => ['lomaxlogpdf', 3, 3];
        yield 'lomaxcdf' => ['lomaxcdf', 3, 3];
        yield 'lomaxccdf' => ['lomaxccdf', 3, 3];
        yield 'lomaxlogcdf' => ['lomaxlogcdf', 3, 3];
        yield 'lomaxlogccdf' => ['lomaxlogccdf', 3, 3];
        yield 'lomaxinv' => ['lomaxinv', 3, 3];
        yield 'lomaxccinv' => ['lomaxccinv', 3, 3];
        yield 'lomaxinvprime' => ['lomaxinvprime', 3, 3];
        yield 'lomaxccinvprime' => ['lomaxccinvprime', 3, 3];
        yield 'lomaxmoment' => ['lomaxmoment', 3, 3];
        yield 'lomaxrawmoment' => ['lomaxrawmoment', 3, 3];
        yield 'lomaxmean' => ['lomaxmean', 2, 2];
        yield 'lomaxmedian' => ['lomaxmedian', 2, 2];
        yield 'lomaxmode' => ['lomaxmode', 2, 2];
        yield 'lomaxvariance' => ['lomaxvariance', 2, 2];
        yield 'lomaxvar' => ['lomaxvar', 2, 2];
        yield 'lomaxstddev' => ['lomaxstddev', 2, 2];
        yield 'lomaxentropy' => ['lomaxentropy', 2, 2];
        yield 'lomaxskewness' => ['lomaxskewness', 2, 2];
        yield 'lomaxexcesskurtosis' => ['lomaxexcesskurtosis', 2, 2];
        yield 'lomaxkurtosis' => ['lomaxkurtosis', 2, 2];
        yield 'lomaxhazard' => ['lomaxhazard', 3, 3];
        yield 'lomaxcumhazard' => ['lomaxcumhazard', 3, 3];
        yield 'pareto2pdf' => ['pareto2pdf', 3, 3];
        yield 'pareto2logpdf' => ['pareto2logpdf', 3, 3];
        yield 'pareto2cdf' => ['pareto2cdf', 3, 3];
        yield 'pareto2ccdf' => ['pareto2ccdf', 3, 3];
        yield 'pareto2logcdf' => ['pareto2logcdf', 3, 3];
        yield 'pareto2logccdf' => ['pareto2logccdf', 3, 3];
        yield 'pareto2inv' => ['pareto2inv', 3, 3];
        yield 'pareto2ccinv' => ['pareto2ccinv', 3, 3];
        yield 'pareto2invprime' => ['pareto2invprime', 3, 3];
        yield 'pareto2ccinvprime' => ['pareto2ccinvprime', 3, 3];
        yield 'pareto2moment' => ['pareto2moment', 3, 3];
        yield 'pareto2rawmoment' => ['pareto2rawmoment', 3, 3];
        yield 'pareto2mean' => ['pareto2mean', 2, 2];
        yield 'pareto2median' => ['pareto2median', 2, 2];
        yield 'pareto2mode' => ['pareto2mode', 2, 2];
        yield 'pareto2variance' => ['pareto2variance', 2, 2];
        yield 'pareto2var' => ['pareto2var', 2, 2];
        yield 'pareto2stddev' => ['pareto2stddev', 2, 2];
        yield 'pareto2entropy' => ['pareto2entropy', 2, 2];
        yield 'pareto2skewness' => ['pareto2skewness', 2, 2];
        yield 'pareto2excesskurtosis' => ['pareto2excesskurtosis', 2, 2];
        yield 'pareto2kurtosis' => ['pareto2kurtosis', 2, 2];
        yield 'pareto2hazard' => ['pareto2hazard', 3, 3];
        yield 'pareto2cumhazard' => ['pareto2cumhazard', 3, 3];
        yield 'rayleighpdf' => ['rayleighpdf', 2, 2];
        yield 'rayleighcdf' => ['rayleighcdf', 2, 2];
        yield 'rayleighccdf' => ['rayleighccdf', 2, 2];
        yield 'rayleighinv' => ['rayleighinv', 2, 2];
        yield 'rayleighinvprime' => ['rayleighinvprime', 2, 2];
        yield 'rayleighccinvprime' => ['rayleighccinvprime', 2, 2];
        yield 'rayleighccinv' => ['rayleighccinv', 2, 2];
        yield 'rayleighmgf' => ['rayleighmgf', 2, 2];
        yield 'rayleighlogmgf' => ['rayleighlogmgf', 2, 2];
        yield 'rayleighmoment' => ['rayleighmoment', 2, 2];
        yield 'rayleighrawmoment' => ['rayleighrawmoment', 2, 2];
        yield 'rayleighmean' => ['rayleighmean', 1, 1];
        yield 'rayleighvariance' => ['rayleighvariance', 1, 1];
        yield 'rayleighvar' => ['rayleighvar', 1, 1];
        yield 'rayleighmedian' => ['rayleighmedian', 1, 1];
        yield 'rayleighmode' => ['rayleighmode', 1, 1];
        yield 'rayleighstddev' => ['rayleighstddev', 1, 1];
        yield 'rayleighentropy' => ['rayleighentropy', 1, 1];
        yield 'rayleighhazard' => ['rayleighhazard', 2, 2];
        yield 'rayleighcumhazard' => ['rayleighcumhazard', 2, 2];
        yield 'rayleighskewness' => ['rayleighskewness', 1, 1];
        yield 'rayleighexcesskurtosis' => ['rayleighexcesskurtosis', 1, 1];
        yield 'rayleighkurtosis' => ['rayleighkurtosis', 1, 1];
        yield 'arcsinepdf' => ['arcsinepdf', 1, 1];
        yield 'arcsinelogpdf' => ['arcsinelogpdf', 1, 1];
        yield 'arcsinecdf' => ['arcsinecdf', 1, 1];
        yield 'arcsineccdf' => ['arcsineccdf', 1, 1];
        yield 'arcsinelogcdf' => ['arcsinelogcdf', 1, 1];
        yield 'arcsinelogccdf' => ['arcsinelogccdf', 1, 1];
        yield 'arcsineinv' => ['arcsineinv', 1, 1];
        yield 'geometricpmf' => ['geometricpmf', 2, 2];
        yield 'geometriclogpmf' => ['geometriclogpmf', 2, 2];
        yield 'geomlogpmf' => ['geomlogpmf', 2, 2];
        yield 'geometriccdf' => ['geometriccdf', 2, 2];
        yield 'geometricccdf' => ['geometricccdf', 2, 2];
        yield 'geometriclogcdf' => ['geometriclogcdf', 2, 2];
        yield 'geomlogcdf' => ['geomlogcdf', 2, 2];
        yield 'geometriclogccdf' => ['geometriclogccdf', 2, 2];
        yield 'geomlogccdf' => ['geomlogccdf', 2, 2];
        yield 'geometricinv' => ['geometricinv', 2, 2];
        yield 'geometricccinv' => ['geometricccinv', 2, 2];
        yield 'geomccinv' => ['geomccinv', 2, 2];
        yield 'geometricdistributionmean' => ['geometricdistributionmean', 1, 1];
        yield 'geommean' => ['geommean', 1, 1];
        yield 'geometricvariance' => ['geometricvariance', 1, 1];
        yield 'geomvariance' => ['geomvariance', 1, 1];
        yield 'geomvar' => ['geomvar', 1, 1];
        yield 'geometricstddev' => ['geometricstddev', 1, 1];
        yield 'geomstddev' => ['geomstddev', 1, 1];
        yield 'geometricmedian' => ['geometricmedian', 1, 1];
        yield 'geommedian' => ['geommedian', 1, 1];
        yield 'geometricmode' => ['geometricmode', 1, 1];
        yield 'geommode' => ['geommode', 1, 1];
        yield 'geometricentropy' => ['geometricentropy', 1, 1];
        yield 'geomentropy' => ['geomentropy', 1, 1];
        yield 'geometricmoment' => ['geometricmoment', 2, 2];
        yield 'geometricrawmoment' => ['geometricrawmoment', 2, 2];
        yield 'geommoment' => ['geommoment', 2, 2];
        yield 'geomrawmoment' => ['geomrawmoment', 2, 2];
        yield 'geometriclogmgf' => ['geometriclogmgf', 2, 2];
        yield 'geomlogmgf' => ['geomlogmgf', 2, 2];
        yield 'geometricmgf' => ['geometricmgf', 2, 2];
        yield 'geommgf' => ['geommgf', 2, 2];
        yield 'geometricskewness' => ['geometricskewness', 1, 1];
        yield 'geometricexcesskurtosis' => ['geometricexcesskurtosis', 1, 1];
        yield 'geometrickurtosis' => ['geometrickurtosis', 1, 1];
        yield 'geomskewness' => ['geomskewness', 1, 1];
        yield 'geomexcesskurtosis' => ['geomexcesskurtosis', 1, 1];
        yield 'geomkurtosis' => ['geomkurtosis', 1, 1];
        yield 'triangularpdf' => ['triangularpdf', 4, 4];
        yield 'triangularlogpdf' => ['triangularlogpdf', 4, 4];
        yield 'triangularcdf' => ['triangularcdf', 4, 4];
        yield 'triangularccdf' => ['triangularccdf', 4, 4];
        yield 'triangularinv' => ['triangularinv', 4, 4];
        yield 'triangularmean' => ['triangularmean', 3, 3];
        yield 'triangularvariance' => ['triangularvariance', 3, 3];
        yield 'triangularvar' => ['triangularvar', 3, 3];
        yield 'triangularmedian' => ['triangularmedian', 3, 3];
        yield 'triangularmode' => ['triangularmode', 3, 3];
        yield 'triangularmoment' => ['triangularmoment', 4, 4];
        yield 'triangularrawmoment' => ['triangularrawmoment', 4, 4];
        yield 'generalizednormalpdf' => ['generalizednormalpdf', 4, 4];
        yield 'generalizednormallogpdf' => ['generalizednormallogpdf', 4, 4];
        yield 'generalizednormlogpdf' => ['generalizednormlogpdf', 4, 4];
        yield 'generalizednormalcdf' => ['generalizednormalcdf', 4, 4];
        yield 'generalizednormalccdf' => ['generalizednormalccdf', 4, 4];
        yield 'generalizednormalinv' => ['generalizednormalinv', 4, 4];
        yield 'generalizednormalmean' => ['generalizednormalmean', 3, 3];
        yield 'generalizednormalmedian' => ['generalizednormalmedian', 3, 3];
        yield 'generalizednormalmode' => ['generalizednormalmode', 3, 3];
        yield 'generalizednormalvariance' => ['generalizednormalvariance', 3, 3];
        yield 'generalizednormalvar' => ['generalizednormalvar', 3, 3];
        yield 'generalizednormalstddev' => ['generalizednormalstddev', 3, 3];
        yield 'generalizednormalentropy' => ['generalizednormalentropy', 3, 3];
        yield 'generalizednormalskewness' => ['generalizednormalskewness', 3, 3];
        yield 'generalizednormalexcesskurtosis' => ['generalizednormalexcesskurtosis', 3, 3];
        yield 'generalizednormalkurtosis' => ['generalizednormalkurtosis', 3, 3];
        yield 'generalizednormalmoment' => ['generalizednormalmoment', 4, 4];
        yield 'generalizednormalrawmoment' => ['generalizednormalrawmoment', 4, 4];
        yield 'norminv' => ['norminv', 1, 3];
        yield 'normalinvlogcdf' => ['normalinvlogcdf', 1, 3];
        yield 'norminvlogcdf' => ['norminvlogcdf', 1, 3];
        yield 'normalinvlogccdf' => ['normalinvlogccdf', 1, 3];
        yield 'norminvlogccdf' => ['norminvlogccdf', 1, 3];
        yield 'sin' => ['sin', 1, 1];
        yield 'cos' => ['cos', 1, 1];
        yield 'tan' => ['tan', 1, 1];
        yield 'sec' => ['sec', 1, 1];
        yield 'csc' => ['csc', 1, 1];
        yield 'cot' => ['cot', 1, 1];
        yield 'asec' => ['asec', 1, 1];
        yield 'acsc' => ['acsc', 1, 1];
        yield 'acot' => ['acot', 1, 1];
        yield 'asech' => ['asech', 1, 1];
        yield 'acsch' => ['acsch', 1, 1];
        yield 'acoth' => ['acoth', 1, 1];
        yield 'asin' => ['asin', 1, 1];
        yield 'acos' => ['acos', 1, 1];
        yield 'atan' => ['atan', 1, 1];
        yield 'sinh' => ['sinh', 1, 1];
        yield 'cosh' => ['cosh', 1, 1];
        yield 'tanh' => ['tanh', 1, 1];
        yield 'sech' => ['sech', 1, 1];
        yield 'csch' => ['csch', 1, 1];
        yield 'coth' => ['coth', 1, 1];
        yield 'asinh' => ['asinh', 1, 1];
        yield 'acosh' => ['acosh', 1, 1];
        yield 'atanh' => ['atanh', 1, 1];
        yield 'gd' => ['gd', 1, 1];
        yield 'invgd' => ['invgd', 1, 1];
        yield 'gdprime' => ['gdprime', 1, 1];
        yield 'gdsecond' => ['gdsecond', 1, 1];
        yield 'invgdprime' => ['invgdprime', 1, 1];
        yield 'invgdsecond' => ['invgdsecond', 1, 1];
        yield 'exp' => ['exp', 1, 1];
        yield 'lambertw' => ['lambertw', 1, 2];
        yield 'lambertwprime' => ['lambertwprime', 1, 2];
        yield 'lambertwderivative' => ['lambertwderivative', 1, 2];
        yield 'lambertwsecond' => ['lambertwsecond', 1, 2];
        yield 'lambertwsecondderivative' => ['lambertwsecondderivative', 1, 2];
        yield 'lambertwthird' => ['lambertwthird', 1, 2];
        yield 'lambertwthirdderivative' => ['lambertwthirdderivative', 1, 2];
        yield 'ln' => ['ln', 1, 1];
        yield 'log' => ['log', 2, 2];
        yield 'log10' => ['log10', 1, 1];
        yield 'log2' => ['log2', 1, 1];
        yield 'log1p' => ['log1p', 1, 1];
        yield 'log1pmx' => ['log1pmx', 1, 1];
        yield 'log1pmxprime' => ['log1pmxprime', 1, 1];
        yield 'log1mexp' => ['log1mexp', 1, 1];
        yield 'log1mexpprime' => ['log1mexpprime', 1, 1];
        yield 'logaddexp' => ['logaddexp', 2, 2];
        yield 'logsumexp' => ['logsumexp', 1, 16];
        yield 'logsubexp' => ['logsubexp', 2, 2];
        yield 'expm1' => ['expm1', 1, 1];
        yield 'exprel' => ['exprel', 1, 1];
        yield 'exprelinv' => ['exprelinv', 1, 1];
        yield 'sigmoid' => ['sigmoid', 1, 1];
        yield 'sigmoidprime' => ['sigmoidprime', 1, 1];
        yield 'sigmoidsecond' => ['sigmoidsecond', 1, 1];
        yield 'sigmoidinv' => ['sigmoidinv', 1, 1];
        yield 'logisticsigmoid' => ['logisticsigmoid', 1, 1];
        yield 'logisticsigmoidprime' => ['logisticsigmoidprime', 1, 1];
        yield 'logisticsigmoidsecond' => ['logisticsigmoidsecond', 1, 1];
        yield 'logisticsigmoidinv' => ['logisticsigmoidinv', 1, 1];
        yield 'softplus' => ['softplus', 1, 1];
        yield 'softplusprime' => ['softplusprime', 1, 1];
        yield 'softplussecond' => ['softplussecond', 1, 1];
        yield 'softplusinv' => ['softplusinv', 1, 1];
        yield 'logitprime' => ['logitprime', 1, 1];
        yield 'logitsecond' => ['logitsecond', 1, 1];
        yield 'logit' => ['logit', 1, 1];
        yield 'atan2' => ['atan2', 2, 2];
        yield 'hypot' => ['hypot', 2, 2];
        yield 'sign' => ['sign', 1, 1];
        yield 'min' => ['min', 1, 16];
        yield 'max' => ['max', 1, 16];
        yield 'clamp' => ['clamp', 3, 3];
        yield 'lerp' => ['lerp', 3, 3];
        yield 'invlerp' => ['invlerp', 3, 3];
        yield 'inverselerp' => ['inverselerp', 3, 3];
        yield 'sum' => ['sum', 1, 16];
        yield 'product' => ['product', 1, 16];
        yield 'mean' => ['mean', 1, 16];
        yield 'arithmeticmean' => ['arithmeticmean', 1, 16];
        yield 'powermean' => ['powermean', 2, 16];
        yield 'geometricmean' => ['geometricmean', 1, 16];
        yield 'harmonicmean' => ['harmonicmean', 1, 16];
        yield 'quadraticmean' => ['quadraticmean', 1, 16];
        yield 'rms' => ['rms', 1, 16];
        yield 'logarithmicmean' => ['logarithmicmean', 2, 2];
        yield 'logmean' => ['logmean', 2, 2];
        yield 'median' => ['median', 1, 16];
        yield 'meanabsolutedeviation' => ['meanabsolutedeviation', 1, 16];
        yield 'meanabsdev' => ['meanabsdev', 1, 16];
        yield 'standarderror' => ['standarderror', 2, 16];
        yield 'sem' => ['sem', 2, 16];
        yield 'coefficientvariation' => ['coefficientvariation', 1, 16];
        yield 'rsd' => ['rsd', 1, 16];
        yield 'quantile' => ['quantile', 2, 16];
        yield 'iqr' => ['iqr', 1, 16];
        yield 'mad' => ['mad', 1, 16];
        yield 'centralmoment' => ['centralmoment', 2, 16];
        yield 'skewness' => ['skewness', 2, 16];
        yield 'kurtosis' => ['kurtosis', 2, 16];
        yield 'excesskurtosis' => ['excesskurtosis', 2, 16];
        yield 'sampleskewness' => ['sampleskewness', 3, 16];
        yield 'sampleexcesskurtosis' => ['sampleexcesskurtosis', 4, 16];
        yield 'variance' => ['variance', 1, 16];
        yield 'stddev' => ['stddev', 1, 16];
        yield 'stdev' => ['stdev', 1, 16];
        yield 'standarddeviation' => ['standarddeviation', 1, 16];
        yield 'samplevariance' => ['samplevariance', 2, 16];
        yield 'samplestddev' => ['samplestddev', 2, 16];
        yield 'covariance' => ['covariance', 4, 16];
        yield 'samplecovariance' => ['samplecovariance', 4, 16];
        yield 'correlation' => ['correlation', 4, 16];
        yield 'weightedcovariance' => ['weightedcovariance', 6, 15];
        yield 'weightedcorrelation' => ['weightedcorrelation', 6, 15];
        yield 'weightedmean' => ['weightedmean', 2, 16];
        yield 'weightedarithmeticmean' => ['weightedarithmeticmean', 2, 16];
        yield 'weightedgeometricmean' => ['weightedgeometricmean', 2, 16];
        yield 'weightedharmonicmean' => ['weightedharmonicmean', 2, 16];
        yield 'weightedquadraticmean' => ['weightedquadraticmean', 2, 16];
        yield 'weightedrms' => ['weightedrms', 2, 16];
        yield 'floor' => ['floor', 1, 1];
        yield 'ceil' => ['ceil', 1, 1];
        yield 'round' => ['round', 1, 1];
        yield 'mod' => ['mod', 2, 2];
        yield 'modulo' => ['modulo', 2, 2];
        yield 'gcd' => ['gcd', 2, 2];
        yield 'lcm' => ['lcm', 2, 2];
        yield 'multiplicativeorder' => ['multiplicativeorder', 2, 2];
        yield 'modorder' => ['modorder', 2, 2];
        yield 'isprimitiveroot' => ['isprimitiveroot', 2, 2];
        yield 'modinverse' => ['modinverse', 2, 2];
        yield 'modinv' => ['modinv', 2, 2];
        yield 'jacobisymbol' => ['jacobisymbol', 2, 2];
        yield 'legendresymbol' => ['legendresymbol', 2, 2];
        yield 'kroneckersymbol' => ['kroneckersymbol', 2, 2];
        yield 'kronecker' => ['kronecker', 2, 2];
        yield 'factorial' => ['factorial', 1, 1];
        yield 'logfactorial' => ['logfactorial', 1, 1];
        yield 'logbinomial' => ['logbinomial', 2, 2];
        yield 'logchoose' => ['logchoose', 2, 2];
        yield 'logpermutation' => ['logpermutation', 2, 2];
        yield 'lognpr' => ['lognpr', 2, 2];
        yield 'lfactorial' => ['lfactorial', 1, 1];
        yield 'harmonicnumber' => ['harmonicnumber', 1, 2];
        yield 'legendrepoly' => ['legendrepoly', 2, 2];
        yield 'legendrepolyprime' => ['legendrepolyprime', 2, 2];
        yield 'legendrepprime' => ['legendrepprime', 2, 2];
        yield 'legendrepolysecond' => ['legendrepolysecond', 2, 2];
        yield 'legendrepsecond' => ['legendrepsecond', 2, 2];
        yield 'legendrep' => ['legendrep', 2, 2];
        yield 'associatedlegendre' => ['associatedlegendre', 3, 3];
        yield 'assoclegendre' => ['assoclegendre', 3, 3];
        yield 'legendreassoc' => ['legendreassoc', 3, 3];
        yield 'associatedlegendreprime' => ['associatedlegendreprime', 3, 3];
        yield 'assoclegendreprime' => ['assoclegendreprime', 3, 3];
        yield 'legendreassocprime' => ['legendreassocprime', 3, 3];
        yield 'chebyshevt' => ['chebyshevt', 2, 2];
        yield 'chebyshevu' => ['chebyshevu', 2, 2];
        yield 'chebyshevtprime' => ['chebyshevtprime', 2, 2];
        yield 'chebyshevuprime' => ['chebyshevuprime', 2, 2];
        yield 'chebyshevtsecond' => ['chebyshevtsecond', 2, 2];
        yield 'chebyshevusecond' => ['chebyshevusecond', 2, 2];
        yield 'chebyshevv' => ['chebyshevv', 2, 2];
        yield 'chebyshevw' => ['chebyshevw', 2, 2];
        yield 'chebyshevvprime' => ['chebyshevvprime', 2, 2];
        yield 'chebyshevwprime' => ['chebyshevwprime', 2, 2];
        yield 'chebyshevvsecond' => ['chebyshevvsecond', 2, 2];
        yield 'chebyshevwsecond' => ['chebyshevwsecond', 2, 2];
        yield 'chebyshevthird' => ['chebyshevthird', 2, 2];
        yield 'chebyshebfourth' => ['chebyshebfourth', 2, 2];
        yield 'chebyshevthirdprime' => ['chebyshevthirdprime', 2, 2];
        yield 'chebyshebfourthprime' => ['chebyshebfourthprime', 2, 2];
        yield 'chebyshevthirdsecond' => ['chebyshevthirdsecond', 2, 2];
        yield 'chebyshebfourthsecond' => ['chebyshebfourthsecond', 2, 2];
        yield 'hermite' => ['hermite', 2, 2];
        yield 'hermitephysicist' => ['hermitephysicist', 2, 2];
        yield 'hermiteprobabilist' => ['hermiteprobabilist', 2, 2];
        yield 'hermiteprime' => ['hermiteprime', 2, 2];
        yield 'hermiteprobabilistprime' => ['hermiteprobabilistprime', 2, 2];
        yield 'hermitesecond' => ['hermitesecond', 2, 2];
        yield 'hermiteprobabilistsecond' => ['hermiteprobabilistsecond', 2, 2];
        yield 'laguerre' => ['laguerre', 2, 2];
        yield 'laguerreassoc' => ['laguerreassoc', 3, 3];
        yield 'laguerreprime' => ['laguerreprime', 2, 2];
        yield 'laguerreassocprime' => ['laguerreassocprime', 3, 3];
        yield 'laguerresecond' => ['laguerresecond', 2, 2];
        yield 'laguerreassocsecond' => ['laguerreassocsecond', 3, 3];
        yield 'jacobipoly' => ['jacobipoly', 4, 4];
        yield 'jacobipolyprime' => ['jacobipolyprime', 4, 4];
        yield 'jacobipolysecond' => ['jacobipolysecond', 4, 4];
        yield 'jacobipolythird' => ['jacobipolythird', 4, 4];
        yield 'jacobipolyderivative' => ['jacobipolyderivative', 5, 5];
        yield 'bernstein' => ['bernstein', 3, 3];
        yield 'bernsteinpoly' => ['bernsteinpoly', 3, 3];
        yield 'gegenbauer' => ['gegenbauer', 3, 3];
        yield 'gegenbauerprime' => ['gegenbauerprime', 3, 3];
        yield 'ultrasphericalprime' => ['ultrasphericalprime', 3, 3];
        yield 'gegenbauersecond' => ['gegenbauersecond', 3, 3];
        yield 'ultrasphericalsecond' => ['ultrasphericalsecond', 3, 3];
        yield 'gegenbauerthird' => ['gegenbauerthird', 3, 3];
        yield 'ultrasphericalthird' => ['ultrasphericalthird', 3, 3];
        yield 'gegenbauerderivative' => ['gegenbauerderivative', 4, 4];
        yield 'ultrasphericalderivative' => ['ultrasphericalderivative', 4, 4];
        yield 'ultraspherical' => ['ultraspherical', 3, 3];
        yield 'partition' => ['partition', 1, 1];
        yield 'partitioncount' => ['partitioncount', 2, 2];
        yield 'distinctpartitioncount' => ['distinctpartitioncount', 2, 2];
        yield 'primepartition' => ['primepartition', 1, 1];
        yield 'distinctpartitions' => ['distinctpartitions', 1, 1];
        yield 'partitiondistinct' => ['partitiondistinct', 1, 1];
        yield 'stirling1' => ['stirling1', 2, 2];
        yield 'stirling1unsigned' => ['stirling1unsigned', 2, 2];
        yield 'stirling2' => ['stirling2', 2, 2];
        yield 'lah' => ['lah', 2, 2];
        yield 'eulerian' => ['eulerian', 2, 2];
        yield 'narayana' => ['narayana', 2, 2];
        yield 'bellnumber' => ['bellnumber', 1, 1];
        yield 'bell' => ['bell', 1, 1];
        yield 'motzkin' => ['motzkin', 1, 1];
        yield 'fibonacci' => ['fibonacci', 1, 1];
        yield 'fibonaccisum' => ['fibonaccisum', 1, 1];
        yield 'nacci' => ['nacci', 2, 2];
        yield 'tribonacci' => ['tribonacci', 1, 1];
        yield 'tribonaccisum' => ['tribonaccisum', 1, 1];
        yield 'fib' => ['fib', 1, 1];
        yield 'lucas' => ['lucas', 1, 1];
        yield 'lucassum' => ['lucassum', 1, 1];
        yield 'pell' => ['pell', 1, 1];
        yield 'pellsum' => ['pellsum', 1, 1];
        yield 'pelllucas' => ['pelllucas', 1, 1];
        yield 'pelllucassum' => ['pelllucassum', 1, 1];
        yield 'jacobsthal' => ['jacobsthal', 1, 1];
        yield 'jacobsthalsum' => ['jacobsthalsum', 1, 1];
        yield 'jacobsthallucas' => ['jacobsthallucas', 1, 1];
        yield 'jacobsthallucassum' => ['jacobsthallucassum', 1, 1];
        yield 'padovan' => ['padovan', 1, 1];
        yield 'padovansum' => ['padovansum', 1, 1];
        yield 'perrin' => ['perrin', 1, 1];
        yield 'stern' => ['stern', 1, 1];
        yield 'perrinsum' => ['perrinsum', 1, 1];
        yield 'triangular' => ['triangular', 1, 1];
        yield 'polygonal' => ['polygonal', 2, 2];
        yield 'pentagonal' => ['pentagonal', 1, 1];
        yield 'hexagonal' => ['hexagonal', 1, 1];
        yield 'heptagonal' => ['heptagonal', 1, 1];
        yield 'octagonal' => ['octagonal', 1, 1];
        yield 'tetrahedral' => ['tetrahedral', 1, 1];
        yield 'simplex' => ['simplex', 2, 2];
        yield 'pyramidal' => ['pyramidal', 2, 2];
        yield 'squarepyramidal' => ['squarepyramidal', 1, 1];
        yield 'superfactorial' => ['superfactorial', 1, 1];
        yield 'hyperfactorial' => ['hyperfactorial', 1, 1];
        yield 'subfactorial' => ['subfactorial', 1, 1];
        yield 'derangement' => ['derangement', 1, 1];
        yield 'totient' => ['totient', 1, 1];
        yield 'cototient' => ['cototient', 1, 1];
        yield 'eulerphi' => ['eulerphi', 1, 1];
        yield 'jordan' => ['jordan', 2, 2];
        yield 'dedekindpsi' => ['dedekindpsi', 1, 1];
        yield 'divisorcount' => ['divisorcount', 1, 1];
        yield 'unitarydivisorcount' => ['unitarydivisorcount', 1, 1];
        yield 'divisorsum' => ['divisorsum', 1, 2];
        yield 'unitarydivisorsum' => ['unitarydivisorsum', 1, 1];
        yield 'unitaryaliquotsum' => ['unitaryaliquotsum', 1, 1];
        yield 'sigma' => ['sigma', 2, 2];
        yield 'isprime' => ['isprime', 1, 1];
        yield 'isprimepower' => ['isprimepower', 1, 1];
        yield 'isperfectpower' => ['isperfectpower', 1, 2];
        yield 'issemiprime' => ['issemiprime', 1, 1];
        yield 'iscarmichael' => ['iscarmichael', 1, 1];
        yield 'ispowerful' => ['ispowerful', 1, 1];
        yield 'primecount' => ['primecount', 1, 1];
        yield 'nthprime' => ['nthprime', 1, 1];
        yield 'nextprime' => ['nextprime', 1, 1];
        yield 'prevprime' => ['prevprime', 1, 1];
        yield 'previousprime' => ['previousprime', 1, 1];
        yield 'goldbachcount' => ['goldbachcount', 1, 1];
        yield 'primepi' => ['primepi', 1, 1];
        yield 'logintegral' => ['logintegral', 1, 1];
        yield 'li' => ['li', 1, 1];
        yield 'expint1' => ['expint1', 1, 1];
        yield 'logexpint1' => ['logexpint1', 1, 1];
        yield 'loge1' => ['loge1', 1, 1];
        yield 'ei' => ['ei', 1, 1];
        yield 'eiprime' => ['eiprime', 1, 1];
        yield 'exponentialintegralprime' => ['exponentialintegralprime', 1, 1];
        yield 'ein' => ['ein', 1, 1];
        yield 'einprime' => ['einprime', 1, 1];
        yield 'entireexponentialintegralprime' => ['entireexponentialintegralprime', 1, 1];
        yield 'dilog' => ['dilog', 1, 1];
        yield 'li2' => ['li2', 1, 1];
        yield 'polylog' => ['polylog', 2, 2];
        yield 'sineintegral' => ['sineintegral', 1, 1];
        yield 'si' => ['si', 1, 1];
        yield 'cosineintegral' => ['cosineintegral', 1, 1];
        yield 'ci' => ['ci', 1, 1];
        yield 'cosineintegralentire' => ['cosineintegralentire', 1, 1];
        yield 'cin' => ['cin', 1, 1];
        yield 'sineintegralprime' => ['sineintegralprime', 1, 1];
        yield 'siprime' => ['siprime', 1, 1];
        yield 'cosineintegralprime' => ['cosineintegralprime', 1, 1];
        yield 'ciprime' => ['ciprime', 1, 1];
        yield 'cosineintegralentireprime' => ['cosineintegralentireprime', 1, 1];
        yield 'cinprime' => ['cinprime', 1, 1];
        yield 'hyperbolicsineintegral' => ['hyperbolicsineintegral', 1, 1];
        yield 'shi' => ['shi', 1, 1];
        yield 'hyperboliccosineintegral' => ['hyperboliccosineintegral', 1, 1];
        yield 'chi' => ['chi', 1, 1];
        yield 'shiprime' => ['shiprime', 1, 1];
        yield 'hyperbolicsineintegralprime' => ['hyperbolicsineintegralprime', 1, 1];
        yield 'chiprime' => ['chiprime', 1, 1];
        yield 'hyperboliccosineintegralprime' => ['hyperboliccosineintegralprime', 1, 1];
        yield 'fresnelc' => ['fresnelc', 1, 1];
        yield 'fresnels' => ['fresnels', 1, 1];
        yield 'fresnelcprime' => ['fresnelcprime', 1, 1];
        yield 'fresnelsprime' => ['fresnelsprime', 1, 1];
        yield 'fresnelcsecond' => ['fresnelcsecond', 1, 1];
        yield 'fresnelssecond' => ['fresnelssecond', 1, 1];
        yield 'e1' => ['e1', 1, 1];
        yield 'expint' => ['expint', 2, 2];
        yield 'logexpint' => ['logexpint', 2, 2];
        yield 'sinc' => ['sinc', 1, 1];
        yield 'sincprime' => ['sincprime', 1, 1];
        yield 'sincsecond' => ['sincsecond', 1, 1];
        yield 'sincpi' => ['sincpi', 1, 1];
        yield 'sincpiprime' => ['sincpiprime', 1, 1];
        yield 'sincpisecond' => ['sincpisecond', 1, 1];
        yield 'sinhc' => ['sinhc', 1, 1];
        yield 'sinhcprime' => ['sinhcprime', 1, 1];
        yield 'sinhcsecond' => ['sinhcsecond', 1, 1];
        yield 'sinhcpi' => ['sinhcpi', 1, 1];
        yield 'sinhcpiprime' => ['sinhcpiprime', 1, 1];
        yield 'sinhcpisecond' => ['sinhcpisecond', 1, 1];
        yield 'cosc' => ['cosc', 1, 1];
        yield 'coscprime' => ['coscprime', 1, 1];
        yield 'coscsecond' => ['coscsecond', 1, 1];
        yield 'coscpi' => ['coscpi', 1, 1];
        yield 'coscpiprime' => ['coscpiprime', 1, 1];
        yield 'coscpisecond' => ['coscpisecond', 1, 1];
        yield 'iscomposite' => ['iscomposite', 1, 1];
        yield 'mobius' => ['mobius', 1, 1];
        yield 'mertens' => ['mertens', 1, 1];
        yield 'issquarefree' => ['issquarefree', 1, 1];
        yield 'liouville' => ['liouville', 1, 1];
        yield 'omega' => ['omega', 1, 1];
        yield 'bigomega' => ['bigomega', 1, 1];
        yield 'radical' => ['radical', 1, 1];
        yield 'arithderivative' => ['arithderivative', 1, 1];
        yield 'primorial' => ['primorial', 1, 1];
        yield 'carmichael' => ['carmichael', 1, 1];
        yield 'carmichaellambda' => ['carmichaellambda', 1, 1];
        yield 'aliquotsum' => ['aliquotsum', 1, 1];
        yield 'isperfect' => ['isperfect', 1, 1];
        yield 'isabundant' => ['isabundant', 1, 1];
        yield 'isdeficient' => ['isdeficient', 1, 1];
        yield 'isamicable' => ['isamicable', 2, 2];
        yield 'issociable' => ['issociable', 2, 2];
        yield 'vonmangoldt' => ['vonmangoldt', 1, 1];
        yield 'mangoldt' => ['mangoldt', 1, 1];
        yield 'digitsum' => ['digitsum', 1, 2];
        yield 'digitproduct' => ['digitproduct', 1, 2];
        yield 'digitalroot' => ['digitalroot', 1, 2];
        yield 'additivepersistence' => ['additivepersistence', 1, 2];
        yield 'multiplicativepersistence' => ['multiplicativepersistence', 1, 2];
        yield 'isharshad' => ['isharshad', 1, 2];
        yield 'ishappy' => ['ishappy', 1, 1];
        yield 'issmith' => ['issmith', 1, 1];
        yield 'binomial' => ['binomial', 2, 2];
        yield 'choose' => ['choose', 2, 2];
        yield 'ncr' => ['ncr', 2, 2];
        yield 'genbinomial' => ['genbinomial', 2, 2];
        yield 'generalizedbinomial' => ['generalizedbinomial', 2, 2];
        yield 'permutation' => ['permutation', 2, 2];
        yield 'permute' => ['permute', 2, 2];
        yield 'npr' => ['npr', 2, 2];
        yield 'multinomial' => ['multinomial', 1, 16];
        yield 'catalan' => ['catalan', 1, 1];
        yield 'doublefactorial' => ['doublefactorial', 1, 1];
        yield 'dfactorial' => ['dfactorial', 1, 1];
        yield 'multifactorial' => ['multifactorial', 2, 2];
        yield 'risingfactorial' => ['risingfactorial', 2, 2];
        yield 'pochhammer' => ['pochhammer', 2, 2];
        yield 'fallingfactorial' => ['fallingfactorial', 2, 2];
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
        yield 'absolute minimum integer' => [
            'abs',
            [\PHP_INT_MIN],
            \OverflowException::class,
            'absolute value of PHP_INT_MIN',
        ];
        yield 'scaled complementary error function rejects non-finite input' => [
            'erfcx',
            [INF],
            \InvalidArgumentException::class,
            'Function arguments must be finite numbers.',
        ];
        yield 'inverse log erfc rejects positive log probability' => [
            'erfcinvlog', [0.1], \DomainException::class,
            'erfcinvlog() requires a finite log probability no greater than zero.',
        ];
        yield 'imaginary error function derivative reports overflow' => [
            'erfiprime', [27], \OverflowException::class,
            'erfiprime() result exceeds the finite floating-point range.',
        ];
        yield 'imaginary error function second derivative reports overflow' => [
            'erfisecond', [27], \OverflowException::class,
            'erfisecond() result exceeds the finite floating-point range.',
        ];
        yield 'mod non-integer dividend' => [
            'mod',
            [1.5, 2],
            \DomainException::class,
            'mod() requires finite integer arguments.',
        ];
        yield 'modulo zero divisor' => [
            'modulo',
            [1, 0],
            \DomainException::class,
            'mod() divisor must not be zero.',
        ];
        yield 'von Mangoldt zero outside domain' => [
            'vonmangoldt',
            [0],
            \DomainException::class,
            'vonmangoldt() requires a positive integer no greater than 1000000000000.',
        ];
        yield 'arithmetic derivative rejects zero' => [
            'arithderivative', [0], \DomainException::class,
            'arithderivative() requires a positive integer no greater than 1000000000000.',
        ];
        yield 'Mertens function bounds its work' => [
            'mertens', [10000001], \DomainException::class,
            'mertens() supports integer inputs from 0 through 10000000.',
        ];
        yield 'perfect power rejects exponent below two' => [
            'isperfectpower', [64, 1], \DomainException::class,
            'isperfectpower() exponent must be an integer in [2, 64].',
        ];
        yield 'digit sum rejects base below two' => [
            'digitsum', [10, 1], \DomainException::class,
            'digitsum() base must be an integer in [2, 36].',
        ];
        yield 'digital root rejects base above thirty-six' => [
            'digitalroot', [10, 37], \DomainException::class,
            'digitalroot() base must be an integer in [2, 36].',
        ];
        yield 'Harshad predicate rejects zero' => [
            'isharshad', [0], \DomainException::class,
            'isharshad() requires a positive integer.',
        ];
        yield 'happy predicate enforces integer bound' => [
            'ishappy', [1000000000001], \DomainException::class,
            'ishappy() requires a non-negative integer no greater than 1000000000000.',
        ];
        yield 'perfect-number predicate rejects zero' => [
            'isperfect',
            [0],
            \DomainException::class,
            'isperfect() requires a positive integer no greater than 1000000000000.',
        ];
        yield 'Lah number rejects k above n' => [
            'lah', [3, 4], \DomainException::class, 'lah() requires k <= n.',
        ];
        yield 'Lah number bounds n' => [
            'lah', [10001, 1], \DomainException::class, 'lah() supports integer n from 0 through 10000.',
        ];
        yield 'Lah number reports exact overflow' => [
            'lah', [21, 1], \OverflowException::class, 'lah() result exceeds the host integer range.',
        ];
        yield 'Eulerian number rejects k outside row' => [
            'eulerian', [4, 4], \DomainException::class, 'eulerian() requires k from 0 through n - 1.',
        ];
        yield 'Eulerian number bounds n' => [
            'eulerian', [10001, 0], \DomainException::class,
            'eulerian() supports integer n from 0 through 10000.',
        ];
        yield 'Eulerian number reports exact overflow' => [
            'eulerian', [100, 20], \OverflowException::class,
            'eulerian() result exceeds the host integer range.',
        ];
        yield 'Narayana requires a positive k no greater than n' => [
            'narayana', [4, 0], \DomainException::class, 'narayana() requires k from 1 through n.',
        ];
        yield 'Narayana bounds n' => [
            'narayana', [10001, 1], \DomainException::class,
            'narayana() supports integer n from 1 through 10000.',
        ];
        yield 'prime partitions bound n' => [
            'primepartition', [10001], \DomainException::class,
            'primepartition() supports integer arguments from 0 through 10000.',
        ];
        yield 'prime partitions report exact overflow' => [
            'primepartition', [1500], \OverflowException::class,
            'primepartition() result exceeds the host integer range.',
        ];
        yield 'Motzkin number rejects negative index' => [
            'motzkin', [-1], \DomainException::class,
            'motzkin() requires non-negative integer arguments.',
        ];
        yield 'Motzkin number reports exact overflow' => [
            'motzkin', [50], \OverflowException::class,
            'motzkin() result exceeds the host integer range.',
        ];
        yield 'Jacobsthal rejects negative index' => [
            'jacobsthal', [-1], \DomainException::class,
            'jacobsthal() requires non-negative integer arguments.',
        ];
        yield 'Jacobsthal reports exact overflow' => [
            'jacobsthal', [100], \OverflowException::class,
            'jacobsthal() result exceeds the host integer range.',
        ];
        yield 'Fibonacci sum rejects negative index' => [
            'fibonaccisum', [-1], \DomainException::class,
            'fibonaccisum() requires non-negative integer arguments.',
        ];
        yield 'Fibonacci sum reports exact overflow' => [
            'fibonaccisum', [100], \OverflowException::class,
            'fibonaccisum() result exceeds the host integer range.',
        ];
        yield 'sequence sum bounds its index' => [
            'perrinsum', [10001], \DomainException::class,
            'perrinsum() supports integer indices from 0 through 10000.',
        ];
        yield 'Padovan rejects negative index' => [
            'padovan', [-1], \DomainException::class,
            'padovan() requires non-negative integer arguments.',
        ];
        yield 'Padovan reports exact overflow' => [
            'padovan', [200], \OverflowException::class,
            'padovan() result exceeds the host integer range.',
        ];
        yield 'Perrin rejects negative index' => [
            'perrin', [-1], \DomainException::class,
            'perrin() requires non-negative integer arguments.',
        ];
        yield 'Stern sequence rejects negative index' => [
            'stern', [-1], \DomainException::class,
            'stern() requires non-negative integer arguments.',
        ];
        yield 'Perrin reports exact overflow' => [
            'perrin', [200], \OverflowException::class,
            'perrin() result exceeds the host integer range.',
        ];
        yield 'Jacobsthal-Lucas reports exact overflow' => [
            'jacobsthallucas', [100], \OverflowException::class,
            'jacobsthallucas() result exceeds the host integer range.',
        ];
        yield 'Pell-Lucas rejects negative index' => [
            'pelllucas', [-1], \DomainException::class,
            'pelllucas() requires non-negative integer arguments.',
        ];
        yield 'Pell-Lucas reports exact overflow' => [
            'pelllucas', [100], \OverflowException::class,
            'pelllucas() result exceeds the host integer range.',
        ];
        yield 'nacci rejects order below two' => [
            'nacci', [10, 1], \DomainException::class, 'nacci() order must be an integer in [2, 64].',
        ];
        yield 'nacci rejects order above sixty-four' => [
            'nacci', [10, 65], \DomainException::class, 'nacci() order must be an integer in [2, 64].',
        ];
        yield 'nacci reports exact overflow' => [
            'nacci', [100, 2], \OverflowException::class, 'nacci() result exceeds the host integer range.',
        ];
        yield 'partitioncount supports bounded n' => [
            'partitioncount', [10001, 1], \DomainException::class,
            'partitioncount() supports integer n from 0 through 10000.',
        ];
        yield 'distinct partition count supports bounded n' => [
            'distinctpartitioncount', [10001, 1], \DomainException::class,
            'distinctpartitioncount() supports integer n from 0 through 10000.',
        ];
        yield 'polygonal requires at least three sides' => [
            'polygonal', [2, 5], \DomainException::class, 'polygonal() requires at least three sides.',
        ];
        yield 'pyramidal requires at least three sides' => [
            'pyramidal', [2, 5], \DomainException::class, 'pyramidal() requires at least three sides.',
        ];
        yield 'simplex rejects zero dimension' => [
            'simplex', [0, 5], \DomainException::class, 'simplex() dimension must be at least one.',
        ];
        yield 'simplex rejects negative index' => [
            'simplex', [3, -1], \DomainException::class,
            'simplex() requires non-negative integer arguments.',
        ];
        yield 'polygonal reports exact overflow' => [
            'polygonal', [3, 4294967296], \OverflowException::class,
            'polygonal() result exceeds the host integer range.',
        ];
        yield 'Bernstein rejects index above degree' => [
            'bernstein', [4, 5, 0.5], \DomainException::class, 'bernstein() requires k <= n.',
        ];
        yield 'Bernstein restricts argument to unit interval' => [
            'bernstein', [4, 2, 1.1], \DomainException::class, 'bernstein() requires x in [0, 1].',
        ];
        yield 'Legendre derivative enforces degree limit' => [
            'legendrepolyprime', [10001, 0], \DomainException::class,
            'legendrepolyprime() degree must not exceed 10000.',
        ];
        yield 'associated Legendre derivative reports endpoint singularity' => [
            'assoclegendreprime', [1, 1, 1], \DomainException::class,
            'associatedlegendreprime() is singular at the endpoints for order one.',
        ];
        yield 'Jacobi derivative validates parameters' => [
            'jacobipolyprime', [2, -1, 0, 0], \DomainException::class,
            'jacobipoly() requires finite parameters alpha and beta greater than -1.',
        ];
        yield 'sociable predicate rejects zero cycle length' => [
            'issociable', [220, 0], \DomainException::class,
            'issociable() cycle length must be an integer in [1, 1000].',
        ];
        yield 'sociable predicate caps cycle length' => [
            'issociable', [220, 1001], \DomainException::class,
            'issociable() cycle length must be an integer in [1, 1000].',
        ];
        yield 'pi-normalized hyperbolic sinc reports overflow' => [
            'sinhcpi', [1000], \OverflowException::class,
            'sinhcpi() result exceeds the finite floating-point range.',
        ];
        yield 'hyperbolic sinc derivative reports overflow' => [
            'sinhcprime', [1000], \OverflowException::class,
            'sinhcprime() derivative exceeds the finite floating-point range.',
        ];
        yield 'pi-normalized hyperbolic sinc second derivative reports overflow' => [
            'sinhcpisecond', [1000], \OverflowException::class,
            'sinhcpisecond() derivative exceeds the finite floating-point range.',
        ];
        yield 'prime count explicit upper bound' => [
            'primecount',
            [10000001],
            \DomainException::class,
            'primecount() supports integer inputs from 0 through 10000000.',
        ];
        yield 'nth prime rejects zero index' => [
            'nthprime', [0], \DomainException::class,
            'nthprime() supports positive integer indices through 1000000.',
        ];
        yield 'nth prime enforces index bound' => [
            'nthprime', [1000001], \DomainException::class,
            'nthprime() supports positive integer indices through 1000000.',
        ];
        yield 'previous prime requires a value above two' => [
            'prevprime', [2], \DomainException::class,
            'prevprime() requires an integer greater than two.',
        ];
        yield 'next prime enforces input bound' => [
            'nextprime', [10000001], \DomainException::class,
            'nextprime() supports integer inputs from 0 through 10000000.',
        ];
        yield 'Goldbach count rejects values below four' => [
            'goldbachcount', [2], \DomainException::class,
            'goldbachcount() requires an even integer of at least four.',
        ];
        yield 'Goldbach count rejects odd values' => [
            'goldbachcount', [9], \DomainException::class,
            'goldbachcount() requires an even integer of at least four.',
        ];
        yield 'Goldbach count enforces input bound' => [
            'goldbachcount', [10000002], \DomainException::class,
            'goldbachcount() supports even integer inputs through 10000000.',
        ];
        yield 'arcsine density singular at zero' => [
            'arcsinepdf', [0], \DomainException::class,
            'arcsinepdf() is singular at the support endpoints zero and one.',
        ];
        yield 'arcsine density singular at one' => [
            'arcsinepdf', [1], \DomainException::class,
            'arcsinepdf() is singular at the support endpoints zero and one.',
        ];
        yield 'logarithmic integral rejects zero' => [
            'logintegral', [0], \DomainException::class,
            'logintegral() requires a finite positive argument.',
        ];
        yield 'logarithmic integral rejects negative input' => [
            'li', [-1], \DomainException::class,
            'logintegral() requires a finite positive argument.',
        ];
        yield 'logarithmic integral pole at one' => [
            'li', [1], \DomainException::class,
            'logintegral() is singular at one.',
        ];
        yield 'exponential integral Ei pole at zero' => [
            'ei', [0], \DomainException::class,
            'ei() is singular at zero.',
        ];
        yield 'Ei derivative pole at zero' => [
            'eiprime', [0], \DomainException::class,
            'eiprime() requires a finite argument other than zero.',
        ];
        yield 'cosine integral singular at zero' => [
            'ci', [0], \DomainException::class,
            'cosineintegral() requires a finite positive argument.',
        ];
        yield 'cosine integral derivative singular at zero' => [
            'ciprime', [0], \DomainException::class,
            'cosineintegralprime() requires a finite positive argument.',
        ];
        yield 'entire cosine integral rejects non-finite input' => [
            'cin', [\INF], \InvalidArgumentException::class,
            'Function arguments must be finite numbers.',
        ];
        yield 'hyperbolic cosine integral singular at zero' => [
            'chi', [0], \DomainException::class,
            'chi() requires a finite non-zero argument.',
        ];
        yield 'hyperbolic cosine integral derivative singular at zero' => [
            'chiprime', [0], \DomainException::class,
            'chiprime() is singular at zero.',
        ];
        yield 'cosine integral rejects negative input' => [
            'ci', [-1], \DomainException::class,
            'cosineintegral() requires a finite positive argument.',
        ];
        yield 'exponential integral E1 rejects zero' => [
            'expint1', [0], \DomainException::class,
            'expint1() requires a finite positive argument.',
        ];
        yield 'logarithmic exponential integral E1 rejects zero' => [
            'logexpint1', [0], \DomainException::class,
            'logexpint1() requires a finite positive argument.',
        ];
        yield 'exponential integral E1 rejects negative input' => [
            'e1', [-1], \DomainException::class,
            'expint1() requires a finite positive argument.',
        ];
        yield 'generalized exponential integral rejects zero order' => [
            'expint', [0, 1], \DomainException::class,
            'expint() order must be an integer in [1, 64].',
        ];
        yield 'generalized exponential integral rejects excessive order' => [
            'expint', [65, 1], \DomainException::class,
            'expint() order must be an integer in [1, 64].',
        ];
        yield 'log generalized exponential integral rejects excessive order' => [
            'logexpint', [65, 1], \DomainException::class,
            'logexpint() order must be an integer in [1, 64].',
        ];
        yield 'generalized exponential integral rejects nonpositive argument' => [
            'expint', [2, 0], \DomainException::class,
            'expint() requires a finite positive argument.',
        ];
        yield 'Jordan totient rejects zero order' => [
            'jordan',
            [12, 0],
            \DomainException::class,
            'jordan() order must be an integer in [1, 64].',
        ];
        yield 'multiplicative order rejects non-coprime inputs' => [
            'multiplicativeorder', [2, 8], \DomainException::class,
            'multiplicativeorder() requires the base and modulus to be coprime.',
        ];
        yield 'multiplicative order requires a nontrivial modulus' => [
            'multiplicativeorder', [2, 1], \DomainException::class,
            'multiplicativeorder() modulus must be an integer from 2 through 1000000000000.',
        ];
        yield 'primitive-root predicate requires a nontrivial modulus' => [
            'isprimitiveroot', [3, 1], \DomainException::class,
            'isprimitiveroot() modulus must be an integer from 2 through 1000000000000.',
        ];
        yield 'modular inverse rejects non-coprime inputs' => [
            'modinverse', [6, 15], \DomainException::class,
            'modinverse() requires the base and modulus to be coprime.',
        ];
        yield 'modular inverse rejects unit modulus' => [
            'modinverse', [2, 1], \DomainException::class,
            'modinverse() modulus must be an integer from 2 through 1000000000000.',
        ];
        yield 'Jacobi symbol rejects even modulus' => [
            'jacobisymbol', [2, 8], \DomainException::class,
            'jacobisymbol() requires an odd positive modulus no greater than 1000000000000.',
        ];
        yield 'Legendre symbol rejects composite modulus' => [
            'legendresymbol', [2, 9], \DomainException::class,
            'legendresymbol() requires an odd prime modulus.',
        ];
        yield 'Kronecker symbol bounds denominator' => [
            'kroneckersymbol', [1, 1000000000001], \DomainException::class,
            'kroneckersymbol() denominator magnitude must not exceed 1000000000000.',
        ];
        yield 'negative square root' => [
            'sqrt',
            [-1],
            \DomainException::class,
            'sqrt() requires a non-negative argument.',
        ];
        yield 'zero root degree' => [
            'root',
            [16, 0],
            \DomainException::class,
            'root() requires a positive integer degree.',
        ];
        yield 'even root of negative radicand' => [
            'nthroot',
            [-16, 2],
            \DomainException::class,
            'root() cannot take an even root of a negative radicand.',
        ];
        yield 'inverse error function endpoint' => [
            'erfinv',
            [1],
            \DomainException::class,
            'requires a finite argument strictly between -1 and 1',
        ];
        yield 'inverse complementary error function endpoint' => [
            'erfcinv',
            [0],
            \DomainException::class,
            'requires a finite argument strictly between 0 and 2',
        ];
        yield 'gamma pole at zero' => [
            'gamma',
            [0],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'gamma pole at negative integer' => [
            'gamma',
            [-3],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'regularized gamma zero shape' => [
            'gammainc',
            [0, 1],
            \DomainException::class,
            'require a finite shape in (0, 10000] and a non-negative value',
        ];
        yield 'regularized gamma negative value' => [
            'gammaincc',
            [1, -1],
            \DomainException::class,
            'require a finite shape in (0, 10000] and a non-negative value',
        ];
        yield 'regularized gamma shape above tested range' => [
            'gammainc',
            [10001, 1],
            \DomainException::class,
            'require a finite shape in (0, 10000] and a non-negative value',
        ];
        yield 'inverse regularized lower gamma probability one' => [
            'gammaincinv',
            [1, 1],
            \DomainException::class,
            'requires a finite shape in (0, 10000] and a probability in [0, 1)',
        ];
        yield 'inverse regularized gamma derivative lower endpoint' => [
            'gammaincinvprime', [1, 0], \DomainException::class,
            'requires a probability strictly between zero and one',
        ];
        yield 'inverse regularized gamma derivative upper endpoint' => [
            'gammainccinvprime', [1, 1], \DomainException::class,
            'requires a probability strictly between zero and one',
        ];
        yield 'log lower incomplete gamma at zero' => [
            'loglowergamma', [1, 0], \DomainException::class,
            'loglowerincompletegamma() is singular at zero.',
        ];
        yield 'log regularized lower gamma at zero' => [
            'loggammainc', [1, 0], \DomainException::class,
            'loggammainc() is singular at zero.',
        ];
        yield 'inverse lower gamma log probability zero' => [
            'gammaincinvlog', [2, 0], \DomainException::class,
            'gammaincinvlog() requires a negative log probability.',
        ];
        yield 'inverse upper gamma log probability above zero' => [
            'gammainccinvlog', [2, 0.1], \DomainException::class,
            'gammainccinvlog() requires a finite shape in (0, 10000] and a finite log probability '
            . 'no greater than zero.',
        ];
        yield 'log lower incomplete beta at zero' => [
            'loglowerincompletebeta', [2, 3, 0], \DomainException::class,
            'loglowerincompletebeta() is singular at zero.',
        ];
        yield 'log upper incomplete beta at one' => [
            'logupperincompletebeta', [2, 3, 1], \DomainException::class,
            'logupperincompletebeta() is singular at one.',
        ];
        yield 'log regularized lower beta at zero' => [
            'logbetainc', [2, 3, 0], \DomainException::class,
            'logbetainc() is singular at zero.',
        ];
        yield 'log regularized upper beta at one' => [
            'logbetaincc', [2, 3, 1], \DomainException::class,
            'logbetaincc() is singular at one.',
        ];
        yield 'inverse lower beta log probability above zero' => [
            'betaincinvlog', [2, 3, 0.1], \DomainException::class,
            'betaincinvlog() requires a finite log probability no greater than zero.',
        ];
        yield 'lower incomplete beta value above one' => [
            'lowerincompletebeta', [1, 1, 1.1], \DomainException::class,
            'require finite positive shapes with a sum in (0, 10000] and a value in [0, 1]',
        ];
        yield 'upper incomplete gamma overflow at zero' => [
            'uppergamma', [200, 0], \OverflowException::class,
            'upperincompletegamma() result exceeds the finite floating-point range.',
        ];
        yield 'regularized beta value above one' => [
            'betainc',
            [1, 1, 1.1],
            \DomainException::class,
            'require finite positive shapes with a sum in (0, 10000] and a value in [0, 1]',
        ];
        yield 'regularized beta shape sum above tested range' => [
            'betainc',
            [5001, 5000, 0.5],
            \DomainException::class,
            'require finite positive shapes with a sum in (0, 10000] and a value in [0, 1]',
        ];
        yield 'inverse regularized beta probability above one' => [
            'betaincinv',
            [1, 1, 1.1],
            \DomainException::class,
            'requires finite positive shapes with a sum in (0, 10000] and a probability in [0, 1]',
        ];
        yield 'inverse beta derivative rejects endpoint probability' => [
            'betaincinvprime', [1, 1, 0], \DomainException::class,
            'requires a probability strictly between zero and one',
        ];
        yield 'normal inverse log CDF rejects zero log probability' => [
            'norminvlogcdf', [0], \DomainException::class,
            'normalinvlogcdf() requires a finite negative log probability.',
        ];
        yield 'normal inverse log survival rejects positive log probability' => [
            'norminvlogccdf', [0.1], \DomainException::class,
            'normalinvlogccdf() requires a finite negative log probability.',
        ];
        yield 'complete elliptic K at one' => [
            'ellipk',
            [1],
            \DomainException::class,
            'diverges at parameter one',
        ];
        yield 'complete elliptic K derivative at one' => [
            'ellipkprime', [1], \DomainException::class,
            'ellipkprime() diverges at parameter one.',
        ];
        yield 'complete elliptic E derivative at one' => [
            'ellipeprime', [1], \DomainException::class,
            'ellipeprime() diverges at parameter one.',
        ];
        yield 'complete elliptic E above one' => [
            'ellipe',
            [1.1],
            \DomainException::class,
            'requires a finite real parameter less than or equal to one',
        ];
        yield 'elliptic nome rejects negative parameter' => [
            'ellipnome',
            [-0.1],
            \DomainException::class,
            'requires a finite parameter in [0, 1]',
        ];
        yield 'elliptic nome rejects parameter above one' => [
            'ellipnome',
            [1.1],
            \DomainException::class,
            'requires a finite parameter in [0, 1]',
        ];
        yield 'inverse elliptic nome rejects negative nome' => [
            'ellipnomeinv',
            [-0.1],
            \DomainException::class,
            'requires a finite nome in [0, 1]',
        ];
        yield 'inverse elliptic nome rejects nome above one' => [
            'ellipnomeinv',
            [1.1],
            \DomainException::class,
            'requires a finite nome in [0, 1]',
        ];
        yield 'incomplete elliptic F singular endpoint' => [
            'ellipf',
            [M_PI / 2.0, 1],
            \DomainException::class,
            'singular at ±pi/2',
        ];
        yield 'incomplete elliptic F derivative singular endpoint' => [
            'ellipfprime', [M_PI / 2.0, 1], \DomainException::class,
            'ellipfprime() is singular at ±pi/2',
        ];
        yield 'complete elliptic Pi singular characteristic' => [
            'ellippi',
            [1, 0],
            \DomainException::class,
            'characteristic n < 1',
        ];
        yield 'incomplete elliptic Pi singular endpoint' => [
            'ellippinc',
            [M_PI / 2.0, 0, 1],
            \DomainException::class,
            'singular at and beyond ±pi/2',
        ];
        yield 'Jacobi elliptic ns pole' => [
            'jacobins', [0, 0], \DomainException::class,
            'jacobins() is singular when its denominator is zero.',
        ];
        yield 'Jacobi elliptic nc pole' => [
            'jacobinc', [M_PI / 2.0, 0], \DomainException::class,
            'jacobinc() is singular when its denominator is zero.',
        ];
        yield 'Jacobi amplitude rejects out-of-range parameter' => [
            'jacobiam', [1, 1.1], \DomainException::class,
            'jacobiam() requires a finite argument and parameter in [0, 1].',
        ];
        yield 'Jacobi theta nome at singular boundary' => [
            'theta3', [0, 1], \DomainException::class,
            'nome in [0, 1)',
        ];
        yield 'Bessel J non-integer order' => [
            'besselj',
            [0.5, 1],
            \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'Bessel J derivative non-integer order' => [
            'besseljprime', [0.5, 1], \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'modified Bessel I negative real order' => [
            'besseli',
            [-0.5, 1],
            \DomainException::class,
            'requires a non-negative real order',
        ];
        yield 'log modified Bessel I derivative negative real order' => [
            'logbesseliprime', [-0.5, 1], \DomainException::class,
            'requires a non-negative real order',
        ];
        yield 'log modified Bessel I non-positive argument' => [
            'logbesseli', [0, 0], \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'scaled modified Bessel I rejects negative real order' => [
            'ive', [-1, 1], \DomainException::class,
            'ive() requires a non-negative real order no greater than 100000.',
        ];
        yield 'scaled modified Bessel I rejects negative argument for fractional order' => [
            'ive', [0.5, -1], \DomainException::class,
            'ive() requires a non-negative argument for non-integer orders.',
        ];
        yield 'scaled modified Bessel K rejects non-positive argument' => [
            'kve', [0, 0], \DomainException::class,
            'kve() requires a finite positive real argument.',
        ];
        yield 'Bessel Y zero argument' => [
            'bessely',
            [0, 0],
            \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'Bessel Y second derivative zero argument' => [
            'besselysecond', [0, 0], \DomainException::class,
            'besselysecond() requires a finite positive real argument.',
        ];
        yield 'Bessel Y derivative zero argument' => [
            'besselyprime', [0, 0], \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'modified Bessel K negative argument' => [
            'besselk',
            [0, -1],
            \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'log modified Bessel K non-integer order' => [
            'logbesselk', [0.5, 1], \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'log modified Bessel K non-positive argument' => [
            'logbesselk', [0, 0], \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'log modified Bessel K derivative non-integer order' => [
            'logbesselkprime', [0.5, 1], \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'log modified Bessel K derivative non-positive argument' => [
            'logbesselkprime', [0, 0], \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'beta non-positive first argument' => [
            'beta',
            [0, 2],
            \DomainException::class,
            'require two finite positive arguments',
        ];
        yield 'log beta non-positive second argument' => [
            'logbeta',
            [2, 0],
            \DomainException::class,
            'require two finite positive arguments',
        ];
        yield 'zeta pole at one' => [
            'zeta',
            [1],
            \DomainException::class,
            'requires a finite real argument other than its pole at one',
        ];
        yield 'zeta derivative pole at one' => [
            'zetaprime',
            [1],
            \DomainException::class,
            'requires a finite real argument other than the pole at one',
        ];
        yield 'Lambert W principal branch below real domain' => [
            'lambertw',
            [-1],
            \DomainException::class,
            'principal branch requires an argument greater than or equal to -1/e',
        ];
        yield 'Lambert W lower branch at zero' => [
            'lambertw',
            [0, -1],
            \DomainException::class,
            'branch -1 requires an argument in [-1/e, 0)',
        ];
        yield 'Lambert W principal derivative at branch point' => [
            'lambertwprime', [-1.0 / M_E], \DomainException::class,
            'lambertwprime() is singular at the branch point -1/e.',
        ];
        yield 'Lambert W lower derivative at branch point' => [
            'lambertwprime', [-1.0 / M_E, -1], \DomainException::class,
            'lambertwprime() is singular at the branch point -1/e.',
        ];
        yield 'Lambert W second derivative at branch point' => [
            'lambertwsecond', [-1.0 / M_E], \DomainException::class,
            'lambertwsecond() is singular at the branch point -1/e.',
        ];
        yield 'Lambert W third derivative at branch point' => [
            'lambertwthird', [-1.0 / M_E], \DomainException::class,
            'lambertwthird() is singular at the branch point -1/e.',
        ];
        yield 'digamma pole at zero' => [
            'digamma',
            [0],
            \DomainException::class,
            'requires a finite non-pole real argument',
        ];
        yield 'trigamma pole at negative integer' => [
            'trigamma',
            [-2],
            \DomainException::class,
            'requires a finite non-pole real argument',
        ];
        yield 'polygamma non-integer order' => [
            'polygamma',
            [0.5, 1],
            \DomainException::class,
            'requires an integer order',
        ];
        yield 'Euler number enforces order bound' => [
            'eulernumber', [65], \DomainException::class,
            'eulernumber() order must be an integer from 0 through 64.',
        ];
        yield 'Euler number reports exact overflow' => [
            'eulernumber', [24], \OverflowException::class,
            'eulernumber() result exceeds the host integer range.',
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
        yield 'inverse Gudermannian at upper endpoint' => [
            'invgd', [M_PI / 2.0], \DomainException::class,
            'invgd() requires an argument strictly between -pi/2 and pi/2.',
        ];
        yield 'inverse Gudermannian at lower endpoint' => [
            'invgd', [-M_PI / 2.0], \DomainException::class,
            'invgd() requires an argument strictly between -pi/2 and pi/2.',
        ];
        yield 'inverse hyperbolic secant above one' => [
            'asech',
            [2],
            \DomainException::class,
            'asech() requires a finite argument strictly greater than zero and at most one.',
        ];
        yield 'inverse hyperbolic cosecant at zero' => [
            'acsch',
            [0],
            \DomainException::class,
            'acsch() requires a finite non-zero argument.',
        ];
        yield 'inverse hyperbolic cotangent inside range' => [
            'acoth',
            [1],
            \DomainException::class,
            'acoth() requires a finite argument with absolute value greater than one.',
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
        yield 'log1pmx negative one' => [
            'log1pmx', [-1], \DomainException::class,
            'log1pmx() requires a finite argument greater than -1.',
        ];
        yield 'log1mexp rejects zero' => [
            'log1mexp', [0], \DomainException::class,
            'log1mexp() requires a finite argument less than zero.',
        ];
        yield 'log1mexp rejects positive input' => [
            'log1mexp', [0.1], \DomainException::class,
            'log1mexp() requires a finite argument less than zero.',
        ];
        yield 'logit derivative rejects boundary probability' => [
            'logitprime', [0], \DomainException::class,
            'logitprime() requires a probability strictly between 0 and 1.',
        ];
        yield 'sigmoid inverse rejects boundary probability' => [
            'sigmoidinv', [1], \DomainException::class,
            'sigmoidinv() requires a probability strictly between 0 and 1.',
        ];
        yield 'logit second derivative rejects boundary probability' => [
            'logitsecond', [0], \DomainException::class,
            'logitsecond() requires a probability strictly between 0 and 1.',
        ];
        yield 'logsubexp rejects equal arguments' => [
            'logsubexp', [2, 2], \DomainException::class,
            'logsubexp() requires the first argument to be greater than the second.',
        ];
        yield 'logsubexp rejects negative result' => [
            'logsubexp', [1, 2], \DomainException::class,
            'logsubexp() requires the first argument to be greater than the second.',
        ];
        yield 'logsumexp reports unrepresentable result' => [
            'logsumexp', [\PHP_FLOAT_MAX, \PHP_FLOAT_MAX], \OverflowException::class,
            'logsumexp() produced a non-finite result.',
        ];
        yield 'clamp rejects reversed bounds' => [
            'clamp', [1, 2, 0], \DomainException::class,
            'clamp() requires the minimum to be no greater than the maximum.',
        ];
        yield 'inverse interpolation rejects equal endpoints' => [
            'invlerp', [2, 2, 3], \DomainException::class,
            'invlerp() requires distinct endpoints.',
        ];
        yield 'product reports integer overflow' => [
            'product', [\PHP_INT_MAX, 2], \OverflowException::class,
            'product() result exceeds the host integer range.',
        ];
        yield 'product reports floating-point overflow' => [
            'product', [\PHP_FLOAT_MAX, 2.0], \OverflowException::class,
            'product() produced a non-finite result.',
        ];
        yield 'sum reports unrepresentable result' => [
            'sum', [\PHP_FLOAT_MAX, \PHP_FLOAT_MAX], \OverflowException::class,
            'sum() produced a non-finite result.',
        ];
        yield 'primorial reports integer overflow' => [
            'primorial', [53], \OverflowException::class,
            'primorial() result exceeds the host integer range.',
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
        yield 'gcd rejects positive floating-point host limit' => [
            'gcd',
            [2.0 ** (\PHP_INT_SIZE * 8 - 1), 1],
            \DomainException::class,
            'gcd() requires finite integer arguments.',
        ];
        yield 'gcd minimum with zero exceeds positive host range' => [
            'gcd',
            [\PHP_INT_MIN, 0],
            \OverflowException::class,
            'gcd() result exceeds the host integer range.',
        ];
        yield 'gcd of two minimum integers exceeds positive host range' => [
            'gcd',
            [\PHP_INT_MIN, \PHP_INT_MIN],
            \OverflowException::class,
            'gcd() result exceeds the host integer range.',
        ];
        yield 'lcm overflow' => [
            'lcm',
            [\PHP_INT_MAX, 2],
            \OverflowException::class,
            'lcm() result exceeds the host integer range.',
        ];
        yield 'lcm minimum integer overflow' => [
            'lcm',
            [\PHP_INT_MIN, 1],
            \OverflowException::class,
            'lcm() result exceeds the host integer range.',
        ];
        yield 'factorial negative' => [
            'factorial',
            [-1],
            \DomainException::class,
            'factorial() requires non-negative integer arguments.',
        ];
        yield 'binomial non-integer' => [
            'binomial',
            [5.5, 2],
            \DomainException::class,
            'binomial() requires finite integer arguments.',
        ];
        yield 'binomial k above n' => [
            'binomial',
            [2, 3],
            \DomainException::class,
            'binomial() requires k to be less than or equal to n.',
        ];
        yield 'log binomial k above n' => [
            'logbinomial', [2, 3], \DomainException::class,
            'logbinomial() requires k to be less than or equal to n.',
        ];
        yield 'log permutation k above n' => [
            'logpermutation', [2, 3], \DomainException::class,
            'logpermutation() requires k to be less than or equal to n.',
        ];
        yield 'binomial overflow' => [
            'binomial',
            [67, 33],
            \OverflowException::class,
            'binomial() result exceeds the host integer range.',
        ];
        yield 'generalized binomial rejects excessive order' => [
            'genbinomial', [2.5, 10001], \DomainException::class,
            'genbinomial() order must be an integer in [0, 10000].',
        ];
        yield 'permutation k above n' => [
            'permutation',
            [2, 3],
            \DomainException::class,
            'permutation() requires k to be less than or equal to n.',
        ];
        yield 'permutation overflow' => [
            'permutation',
            [21, 21],
            \OverflowException::class,
            'permutation() result exceeds the host integer range.',
        ];
        yield 'multinomial negative count' => [
            'multinomial',
            [2, -1],
            \DomainException::class,
            'multinomial() requires non-negative integer arguments.',
        ];
        yield 'multinomial overflow' => [
            'multinomial',
            [50, 50, 50],
            \OverflowException::class,
            'multinomial() result exceeds the host integer range.',
        ];
        yield 'Catalan negative index' => [
            'catalan',
            [-1],
            \DomainException::class,
            'catalan() requires non-negative integer arguments.',
        ];
        yield 'Catalan overflow' => [
            'catalan',
            [36],
            \OverflowException::class,
            'catalan() result exceeds the host integer range.',
        ];
        yield 'subfactorial overflow' => [
            'subfactorial', [21], \OverflowException::class,
            'subfactorial() result exceeds the host integer range.',
        ];
        yield 'Pell sequence overflow' => [
            'pell', [50], \OverflowException::class,
            'pell() result exceeds the host integer range.',
        ];
        yield 'triangular negative index' => [
            'triangular', [-1], \DomainException::class,
            'triangular() requires non-negative integer arguments.',
        ];
        yield 'triangular overflow' => [
            'triangular', [4294967296], \OverflowException::class,
            'triangular() result exceeds the host integer range.',
        ];
        yield 'tetrahedral negative index' => [
            'tetrahedral', [-1], \DomainException::class,
            'tetrahedral() requires non-negative integer arguments.',
        ];
        yield 'tetrahedral overflow' => [
            'tetrahedral', [3810778], \OverflowException::class,
            'tetrahedral() result exceeds the host integer range.',
        ];
        yield 'simplex reports exact overflow' => [
            'simplex', [3, 3810778], \OverflowException::class,
            'simplex() result exceeds the host integer range.',
        ];
        yield 'square pyramidal overflow' => [
            'squarepyramidal', [4000000], \OverflowException::class,
            'squarepyramidal() result exceeds the host integer range.',
        ];
        yield 'superfactorial negative' => [
            'superfactorial', [-1], \DomainException::class,
            'superfactorial() requires non-negative integer arguments.',
        ];
        yield 'superfactorial overflow' => [
            'superfactorial', [9], \OverflowException::class,
            'superfactorial() result exceeds the host integer range.',
        ];
        yield 'hyperfactorial negative' => [
            'hyperfactorial', [-1], \DomainException::class,
            'hyperfactorial() requires non-negative integer arguments.',
        ];
        yield 'hyperfactorial overflow' => [
            'hyperfactorial', [8], \OverflowException::class,
            'hyperfactorial() result exceeds the host integer range.',
        ];
        yield 'double factorial negative' => [
            'doublefactorial',
            [-1],
            \DomainException::class,
            'doublefactorial() requires non-negative integer arguments.',
        ];
        yield 'double factorial overflow' => [
            'doublefactorial',
            [\PHP_INT_MAX],
            \OverflowException::class,
            'doublefactorial() result exceeds the host integer range.',
        ];
        yield 'multifactorial zero step' => [
            'multifactorial', [5, 0], \DomainException::class,
            'multifactorial() step must be a positive integer.',
        ];
        yield 'multifactorial overflow' => [
            'multifactorial', [21, 1], \OverflowException::class,
            'multifactorial() result exceeds the host integer range.',
        ];
        yield 'rising factorial negative order' => [
            'risingfactorial',
            [2, -1],
            \DomainException::class,
            'risingfactorial() requires non-negative integer arguments.',
        ];
        yield 'rising factorial order limit' => [
            'pochhammer',
            [1, 10001],
            \DomainException::class,
            'risingfactorial() supports integer order from 0 through 10000.',
        ];
        yield 'falling factorial overflow' => [
            'fallingfactorial',
            [\PHP_FLOAT_MAX, 2],
            \OverflowException::class,
            'fallingfactorial() result exceeds the finite numeric range.',
        ];
        yield 'geometric mean negative input' => [
            'geometricmean',
            [-1, 4],
            \DomainException::class,
            'geometricmean() requires non-negative arguments.',
        ];
        yield 'coefficient of variation rejects zero mean' => [
            'coefficientvariation', [-1, 1], \DomainException::class,
            'coefficientvariation() is undefined when the arithmetic mean is zero.',
        ];
        yield 'power mean rejects negative data' => [
            'powermean', [2, -1, 3], \DomainException::class,
            'powermean() requires non-negative arguments.',
        ];
        yield 'power mean negative order rejects zero data' => [
            'powermean', [-1, 0, 3], \DomainException::class,
            'powermean() requires positive arguments for a negative order.',
        ];
        yield 'quantile probability below zero' => [
            'quantile',
            [-0.1, 1, 2],
            \DomainException::class,
            'quantile() probability must be in [0, 1].',
        ];
        yield 'quantile probability above one' => [
            'quantile',
            [1.1, 1, 2],
            \DomainException::class,
            'quantile() probability must be in [0, 1].',
        ];
        yield 'skewness rejects zero variance' => [
            'skewness',
            [5, 5, 5],
            \DomainException::class,
            'skewness() and kurtosis() are undefined for zero-variance data.',
        ];
        yield 'kurtosis rejects zero variance' => [
            'kurtosis',
            [0, 0],
            \DomainException::class,
            'skewness() and kurtosis() are undefined for zero-variance data.',
        ];
        yield 'correlation rejects constant variable' => [
            'correlation', [1, 2, 1, 3, 1, 5], \DomainException::class,
            'correlation() is undefined when either variable has zero variance.',
        ];
        yield 'covariance rejects incomplete pair' => [
            'covariance', [1, 2, 3, 4, 5], \DomainException::class,
            'covariance() requires interleaved x/y pairs.',
        ];
        yield 'weighted covariance rejects incomplete triplet' => [
            'weightedcovariance', [1, 2, 3, 4, 5, 6, 7], \DomainException::class,
            'weightedcovariance() requires interleaved x/y/weight triplets.',
        ];
        yield 'weighted correlation rejects all-zero weights' => [
            'weightedcorrelation', [1, 2, 0, 3, 4, 0], \DomainException::class,
            'weightedcorrelation() requires at least one positive weight.',
        ];
        yield 'weighted covariance rejects negative weight' => [
            'weightedcovariance', [1, 2, 1, 3, 4, -1], \DomainException::class,
            'weightedcovariance() requires non-negative weights.',
        ];
        yield 'exprel overflow' => [
            'exprel', [720], \OverflowException::class,
            'exprel() produced a non-finite result.',
        ];
        yield 'inverse exponential relative rejects zero' => [
            'exprelinv', [0], \DomainException::class,
            'exprelinv() requires a finite positive argument.',
        ];
        yield 'inverse exponential relative rejects negative target' => [
            'exprelinv', [-1], \DomainException::class,
            'exprelinv() requires a finite positive argument.',
        ];
        yield 'inverse exponential relative reports unrepresentable negative tail' => [
            'exprelinv', [1.0e-320], \OverflowException::class,
            'exprelinv() result exceeds the finite floating-point range.',
        ];
        yield 'sigmoid rejects positive infinity' => [
            'sigmoid', [INF], \InvalidArgumentException::class,
            'Function arguments must be finite numbers.',
        ];
        yield 'sigmoid rejects NaN' => [
            'sigmoid', [NAN], \InvalidArgumentException::class,
            'Function arguments must be finite numbers.',
        ];
        yield 'logit rejects zero' => [
            'logit', [0], \DomainException::class,
            'logit() requires a probability strictly between 0 and 1.',
        ];
        yield 'logit rejects one' => [
            'logit', [1], \DomainException::class,
            'logit() requires a probability strictly between 0 and 1.',
        ];
        yield 'inverse softplus rejects zero' => [
            'softplusinv', [0], \DomainException::class,
            'softplusinv() requires a finite argument greater than zero.',
        ];
        yield 'inverse softplus rejects negative input' => [
            'softplusinv', [-1], \DomainException::class,
            'softplusinv() requires a finite argument greater than zero.',
        ];
        yield 'harmonic number rejects negative index' => [
            'harmonicnumber', [-1], \DomainException::class,
            'harmonicnumber() requires a finite non-negative real index.',
        ];
        yield 'harmonic number rejects non-positive order' => [
            'harmonicnumber', [5, 0], \DomainException::class,
            'harmonicnumber() requires a finite positive order.',
        ];
        yield 'harmonic number enforces work bound' => [
            'harmonicnumber', [1000001], \DomainException::class,
            'harmonicnumber() index must not exceed 1000000.',
        ];
        yield 'Legendre polynomial rejects negative degree' => [
            'legendrepoly', [-1, 0], \DomainException::class,
            'legendrepoly() requires non-negative integer arguments.',
        ];
        yield 'Legendre polynomial enforces degree limit' => [
            'legendrepoly', [10001, 0], \DomainException::class,
            'legendrepoly() degree must not exceed 10000.',
        ];
        yield 'Legendre polynomial rejects non-finite value' => [
            'legendrepoly', [2, \INF], \InvalidArgumentException::class,
            'Function arguments must be finite numbers.',
        ];
        yield 'Legendre polynomial reports overflow' => [
            'legendrepoly', [2, \PHP_FLOAT_MAX], \OverflowException::class,
            'legendrepoly() produced a non-finite result.',
        ];
        yield 'Chebyshev polynomial rejects negative degree' => [
            'chebyshevt', [-1, 0], \DomainException::class,
            'chebyshevt() requires non-negative integer arguments.',
        ];
        yield 'Chebyshev polynomial enforces degree limit' => [
            'chebyshevu', [10001, 0], \DomainException::class,
            'chebyshevu() degree must not exceed 10000.',
        ];
        yield 'Chebyshev polynomial reports overflow' => [
            'chebyshevt', [2, \PHP_FLOAT_MAX], \OverflowException::class,
            'chebyshevt() produced a non-finite result.',
        ];
        yield 'Hermite polynomial rejects negative degree' => [
            'hermite', [-1, 0], \DomainException::class,
            'hermite() requires non-negative integer arguments.',
        ];
        yield 'Hermite polynomial enforces degree limit' => [
            'hermiteprobabilist', [10001, 0], \DomainException::class,
            'hermiteprobabilist() degree must not exceed 10000.',
        ];
        yield 'Hermite polynomial reports overflow' => [
            'hermite', [2, \PHP_FLOAT_MAX], \OverflowException::class,
            'hermite() produced a non-finite result.',
        ];
        yield 'Laguerre polynomial rejects negative degree' => [
            'laguerre', [-1, 0], \DomainException::class,
            'laguerre() requires non-negative integer arguments.',
        ];
        yield 'associated Laguerre polynomial rejects parameter at boundary' => [
            'laguerreassoc', [2, -1, 0], \DomainException::class,
            'laguerreassoc() requires a finite parameter greater than -1.',
        ];
        yield 'Laguerre polynomial reports overflow' => [
            'laguerre', [2, \PHP_FLOAT_MAX], \OverflowException::class,
            'laguerre() produced a non-finite result.',
        ];
        yield 'Jacobi polynomial rejects parameter at boundary' => [
            'jacobipoly', [2, -1, 0, 0], \DomainException::class,
            'jacobipoly() requires finite parameters alpha and beta greater than -1.',
        ];
        yield 'Jacobi polynomial enforces degree limit' => [
            'jacobipoly', [10001, 0, 0, 0], \DomainException::class,
            'jacobipoly() degree must not exceed 10000.',
        ];
        yield 'Jacobi polynomial reports overflow' => [
            'jacobipoly', [2, 0, 0, \PHP_FLOAT_MAX], \OverflowException::class,
            'jacobipoly() produced a non-finite result.',
        ];
        yield 'normal log density overflow' => [
            'normallogpdf', [\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX, \PHP_FLOAT_MIN],
            \OverflowException::class,
            'normallogpdf() result exceeds the finite floating-point range.',
        ];
        yield 'harmonic mean negative input' => [
            'harmonicmean',
            [1, -2],
            \DomainException::class,
            'harmonicmean() requires non-negative arguments.',
        ];
        yield 'logarithmic mean rejects zero' => [
            'logmean', [0, 1], \DomainException::class,
            'logarithmicmean() requires finite positive arguments.',
        ];
        yield 'logarithmic mean rejects negative values' => [
            'logarithmicmean', [-1, 2], \DomainException::class,
            'logarithmicmean() requires finite positive arguments.',
        ];
        yield 'population variance overflow' => [
            'variance',
            [-\PHP_FLOAT_MAX, \PHP_FLOAT_MAX],
            \OverflowException::class,
            'variance() produced a non-finite result.',
        ];
        yield 'weighted mean rejects odd argument count' => [
            'weightedmean',
            [1, 2, 3],
            \DomainException::class,
            'weightedmean() requires alternating value/weight pairs.',
        ];
        yield 'weighted mean requires positive total weight' => [
            'weightedmean',
            [1, 0, 2, 0],
            \DomainException::class,
            'weightedmean() requires a positive total weight.',
        ];
        yield 'weighted mean rejects negative weight' => [
            'weightedmean',
            [1, -1],
            \DomainException::class,
            'weightedmean() requires non-negative weights.',
        ];
        yield 'weighted geometric mean rejects negative value' => [
            'weightedgeometricmean',
            [-1, 1],
            \DomainException::class,
            'weightedgeometricmean() requires non-negative values.',
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
