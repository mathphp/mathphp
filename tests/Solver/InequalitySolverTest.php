<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class InequalitySolverTest extends TestCase
{
    public function testChainedInequalitiesIntersectDomainsAndEndpointInclusion(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $logarithm = Math::solveInequality(
            '0 < ln(x) <= 1',
            'x',
            -2.0,
            3.0,
            solverOptions: $options,
        );
        self::assertCount(1, $logarithm);
        self::assertSame(1.0, $logarithm[0]->lowerBound);
        self::assertEqualsWithDelta(M_E, $logarithm[0]->upperBound, 1.0E-10);
        self::assertFalse($logarithm[0]->includesLowerBound);
        self::assertTrue($logarithm[0]->includesUpperBound);

        $rational = Math::solveInequality(
            '-1 < 1/(x-1) < 1',
            'x',
            -2.0,
            3.0,
            solverOptions: $options,
        );
        self::assertSame(
            [[-2.0, 0.0, true, false], [2.0, 3.0, false, true]],
            \array_map(
                static fn ($interval): array => [
                    $interval->lowerBound,
                    $interval->upperBound,
                    $interval->includesLowerBound,
                    $interval->includesUpperBound,
                ],
                $rational,
            ),
        );

        $squareRoot = Math::solveInequality(
            '0 <= sqrt(x) <= 1',
            'x',
            -2.0,
            3.0,
            solverOptions: $options,
        );
        self::assertCount(1, $squareRoot);
        self::assertSame(0.0, $squareRoot[0]->lowerBound);
        self::assertSame(1.0, $squareRoot[0]->upperBound);
        self::assertTrue($squareRoot[0]->includesLowerBound);
        self::assertTrue($squareRoot[0]->includesUpperBound);
    }

    public function testZeroPowerInequalityRetainsRationalBaseDomainHole(): void
    {
        $solutions = Math::solveInequality(
            '((x-1)/(x-1))^0 >= 1',
            'x',
            -2.0,
            2.0,
        );

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(1.0, $solutions[1]->lowerBound);
        self::assertSame(2.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testCancelledRationalFactorsPreserveRemovableHolesAndSigns(): void
    {
        $cases = [
            '(x-1)/(x-1)>=1' => [
                [-2.0, 1.0, true, false],
                [1.0, 2.0, false, true],
            ],
            '(x-1)^2/(x-1)>=0' => [
                [1.0, 2.0, false, true],
            ],
            '(x-1)^2/(x-1)<=0' => [
                [-2.0, 1.0, true, false],
            ],
        ];

        foreach ($cases as $inequality => $expected) {
            $solutions = Math::solveInequality($inequality, 'x', -2.0, 2.0);
            $actual = \array_map(
                static fn ($interval): array => [
                    $interval->lowerBound,
                    $interval->upperBound,
                    $interval->includesLowerBound,
                    $interval->includesUpperBound,
                ],
                $solutions,
            );

            self::assertSame($expected, $actual, $inequality);
        }
    }

    public function testCustomFunctionIntervalExtensionsComposeInInequalities(): void
    {
        $shifted = new FunctionDefinition(
            'shifted',
            1,
            1,
            static fn (array $arguments): float => $arguments[0] + 1.0,
            range: static fn (array $arguments): array => [
                $arguments[0][0] + 1.0,
                $arguments[0][1] + 1.0,
            ],
        );
        $options = new EvaluationOptions(
            functions: FunctionRegistry::defaults()->with($shifted),
        );
        $solutions = Math::solveInequality(
            'shifted(x) >= 1.5',
            'x',
            0.0,
            1.0,
            evaluationOptions: $options,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.5, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);

        $discreteDomain = Math::solveInequality(
            'gcd(shifted(x), 1) >= 1',
            'x',
            0.0,
            1.0,
            evaluationOptions: $options,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(2, $discreteDomain);
        self::assertSame(0.0, $discreteDomain[0]->lowerBound);
        self::assertSame(0.0, $discreteDomain[0]->upperBound);
        self::assertSame(1.0, $discreteDomain[1]->lowerBound);
        self::assertSame(1.0, $discreteDomain[1]->upperBound);

        $discreteInterior = Math::solveInequality(
            'gcd(shifted(2*x), 1) >= 1',
            'x',
            0.0,
            1.0,
            evaluationOptions: $options,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertSame(
            [0.0, 0.5, 1.0],
            \array_map(static fn ($interval): float => $interval->lowerBound, $discreteInterior),
        );
        foreach ($discreteInterior as $interval) {
            self::assertSame($interval->lowerBound, $interval->upperBound);
        }
    }

    public function testRoundingInequalitiesUseErfiAndDawsonIntervalRanges(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $erfiSolutions = Math::solveInequality(
            'floor(erfi(x)) >= 1',
            'x',
            0.0,
            2.0,
            solverOptions: $options,
        );

        self::assertCount(1, $erfiSolutions);
        self::assertEqualsWithDelta(
            SpecialFunctions::inverseErfi(1.0),
            $erfiSolutions[0]->lowerBound,
            1.0E-10,
        );
        self::assertTrue($erfiSolutions[0]->includesLowerBound);
        self::assertTrue($erfiSolutions[0]->includesUpperBound);

        $dawsonBoundaryRoots = Math::solve(
            '100 * dawson(x) = 54',
            'x',
            0.0,
            2.0,
            solverOptions: $options,
        );
        $dawsonSolutions = Math::solveInequality(
            'floor(100 * dawson(x)) >= 54',
            'x',
            0.0,
            2.0,
            solverOptions: $options,
        );

        self::assertCount(2, $dawsonBoundaryRoots);
        self::assertCount(1, $dawsonSolutions);
        self::assertEqualsWithDelta(
            $dawsonBoundaryRoots[0]->value,
            $dawsonSolutions[0]->lowerBound,
            1.0E-9,
        );
        self::assertEqualsWithDelta(
            $dawsonBoundaryRoots[1]->value,
            $dawsonSolutions[0]->upperBound,
            1.0E-9,
        );
        self::assertTrue($dawsonSolutions[0]->includesLowerBound);
        self::assertTrue($dawsonSolutions[0]->includesUpperBound);
    }

    public function testSmoothModuloInequalitiesEnumerateBoundedIntegerLevels(): void
    {
        $nonnegativeRemainder = Math::solveInequality(
            'sin(x) % 2 >= 0',
            'x',
            -5.0,
            5.0,
        );
        $negativeRemainder = Math::solveInequality(
            'sin(x) % 2 <= -1',
            'x',
            -5.0,
            5.0,
        );

        self::assertSame(
            [-3.0 * \M_PI / 2.0, -\M_PI, 0.0, \M_PI / 2.0, \M_PI],
            \array_map(static fn ($interval): float => $interval->lowerBound, $nonnegativeRemainder),
        );
        self::assertEqualsWithDelta(
            [-\M_PI / 2.0, 3.0 * \M_PI / 2.0],
            \array_map(static fn ($interval): float => $interval->lowerBound, $negativeRemainder),
            1.0E-8,
        );
        foreach ([...$nonnegativeRemainder, ...$negativeRemainder] as $interval) {
            self::assertSame($interval->lowerBound, $interval->upperBound);
            self::assertTrue($interval->includesLowerBound);
            self::assertTrue($interval->includesUpperBound);
        }
    }

    public function testImplicitVariableProductsAreRecognizedInInequalities(): void
    {
        $scaledVariable = Math::solveInequality('x(2) >= 4', 'x', -4.0, 4.0);
        $variableProduct = Math::solveInequality('x(x + 1) >= 0', 'x', -4.0, 4.0);

        self::assertCount(1, $scaledVariable);
        self::assertSame(2.0, $scaledVariable[0]->lowerBound);
        self::assertSame(4.0, $scaledVariable[0]->upperBound);
        self::assertCount(2, $variableProduct);
        self::assertSame(-4.0, $variableProduct[0]->lowerBound);
        self::assertSame(-1.0, $variableProduct[0]->upperBound);
        self::assertSame(0.0, $variableProduct[1]->lowerBound);
        self::assertSame(4.0, $variableProduct[1]->upperBound);
    }

    public function testFiniteInequalityBoundsMayHaveOverflowingWidth(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $nonnegative = Math::solveInequality(
            'x >= 0',
            'x',
            -1.0E308,
            1.0E308,
            solverOptions: $options,
        );
        $bounded = Math::solveInequality(
            'x^2 <= 1',
            'x',
            -1.0E308,
            1.0E308,
            solverOptions: $options,
        );

        self::assertCount(1, $nonnegative);
        self::assertSame(0.0, $nonnegative[0]->lowerBound);
        self::assertSame(1.0E308, $nonnegative[0]->upperBound);
        self::assertTrue($nonnegative[0]->includesLowerBound);
        self::assertTrue($nonnegative[0]->includesUpperBound);
        self::assertCount(1, $bounded);
        self::assertSame(-1.0, $bounded[0]->lowerBound);
        self::assertSame(1.0, $bounded[0]->upperBound);
        self::assertTrue($bounded[0]->includesLowerBound);
        self::assertTrue($bounded[0]->includesUpperBound);
    }

    public function testNonPolynomialInequalitiesRejectOverflowingWidthInsteadOfReturningPartialSets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a polynomial or rational comparison');

        Math::solveInequality('exp(x) >= 1', 'x', -1.0E308, 1.0E308);
    }

    public function testIncompleteGammaInequalitiesRespectBothArgumentDomains(): void
    {
        $argumentDomain = Math::solveInequality(
            'gammainc(1, x) <= 0.5',
            'x',
            -1.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 8),
        );
        self::assertCount(1, $argumentDomain);
        self::assertSame(0.0, $argumentDomain[0]->lowerBound);
        self::assertTrue($argumentDomain[0]->includesLowerBound);
        self::assertEqualsWithDelta(\log(2.0), $argumentDomain[0]->upperBound, 1.0E-8);
        self::assertTrue($argumentDomain[0]->includesUpperBound);

        $shapeDomain = Math::solveInequality(
            'gammaincc(x, 1) >= 0',
            'x',
            -1.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 8),
        );
        self::assertCount(1, $shapeDomain);
        self::assertSame(0.0, $shapeDomain[0]->lowerBound);
        self::assertFalse($shapeDomain[0]->includesLowerBound);
        self::assertSame(2.0, $shapeDomain[0]->upperBound);
        self::assertTrue($shapeDomain[0]->includesUpperBound);
    }

    public function testInverseErrorFunctionInequalitiesRetainNarrowDomainIntervals(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $errorDomain = Math::solveInequality(
            'erfinv(10000 * (x - 0.12345)) >= 0',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $errorDomain);
        self::assertEqualsWithDelta(0.12345, $errorDomain[0]->lowerBound, 1.0E-12);
        self::assertTrue($errorDomain[0]->includesLowerBound);
        self::assertEqualsWithDelta(0.12355, $errorDomain[0]->upperBound, 1.0E-12);
        self::assertFalse($errorDomain[0]->includesUpperBound);

        $complementaryDomain = Math::solveInequality(
            'erfcinv(10000 * (x - 0.2)) >= 0',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $complementaryDomain);
        self::assertEqualsWithDelta(0.2, $complementaryDomain[0]->lowerBound, 1.0E-12);
        self::assertFalse($complementaryDomain[0]->includesLowerBound);
        self::assertEqualsWithDelta(0.2001, $complementaryDomain[0]->upperBound, 1.0E-12);
        self::assertTrue($complementaryDomain[0]->includesUpperBound);
    }

    public function testScaledComplementaryErrorFunctionInequalitiesUseMonotoneRange(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $domain = Math::solveInequality(
            'erfcx(x^2) >= erfcx(1)',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );

        self::assertCount(1, $domain);
        self::assertEqualsWithDelta(-1.0, $domain[0]->lowerBound, 1.0E-10);
        self::assertTrue($domain[0]->includesLowerBound);
        self::assertEqualsWithDelta(1.0, $domain[0]->upperBound, 1.0E-10);
        self::assertTrue($domain[0]->includesUpperBound);

        $maximum = Math::solveInequality(
            'erfcx(x^2) >= 1',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );
        self::assertCount(1, $maximum);
        self::assertEqualsWithDelta(0.0, $maximum[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $maximum[0]->upperBound, 1.0E-10);
    }

    public function testImaginaryErrorFunctionInequalityUsesItsMonotoneRange(): void
    {
        $solutions = Math::solveInequality(
            'erfi(10 * (x - 0.12345)) >= 0',
            'x',
            0.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.12345, $solutions[0]->lowerBound, 1.0E-10);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testDawsonInequalityFindsBothBoundariesWithoutScanning(): void
    {
        $solutions = Math::solveInequality(
            'dawson(x) >= dawson(1)',
            'x',
            0.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.8516699952, $solutions[0]->lowerBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertEqualsWithDelta(1.0, $solutions[0]->upperBound, 1.0E-9);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testInverseRegularizedBetaInequalitiesPartitionProbabilityEndpoints(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $lowerInverse = Math::solveInequality(
            'betaincinv(2, 3, x) >= 0.5',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $lowerInverse);
        self::assertEqualsWithDelta(
            Math::evaluate('betainc(2, 3, 0.5)'),
            $lowerInverse[0]->lowerBound,
            1.0E-10,
        );
        self::assertTrue($lowerInverse[0]->includesLowerBound);
        self::assertSame(1.0, $lowerInverse[0]->upperBound);
        self::assertTrue($lowerInverse[0]->includesUpperBound);

        $upperInverse = Math::solveInequality(
            'betainccinv(2, 3, x) >= 0.5',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $upperInverse);
        self::assertSame(0.0, $upperInverse[0]->lowerBound);
        self::assertTrue($upperInverse[0]->includesLowerBound);
        self::assertEqualsWithDelta(
            Math::evaluate('betaincc(2, 3, 0.5)'),
            $upperInverse[0]->upperBound,
            1.0E-10,
        );
        self::assertTrue($upperInverse[0]->includesUpperBound);
    }

    public function testInverseRegularizedGammaInequalitiesExcludeInfiniteQuantileEndpoints(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $lowerInverse = Math::solveInequality(
            'gammaincinv(2, x) >= 1',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $lowerInverse);
        self::assertEqualsWithDelta(
            Math::evaluate('gammainc(2, 1)'),
            $lowerInverse[0]->lowerBound,
            1.0E-10,
        );
        self::assertTrue($lowerInverse[0]->includesLowerBound);
        self::assertSame(1.0, $lowerInverse[0]->upperBound);
        self::assertFalse($lowerInverse[0]->includesUpperBound);

        $upperInverse = Math::solveInequality(
            'gammainccinv(2, x) >= 1',
            'x',
            0.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(1, $upperInverse);
        self::assertSame(0.0, $upperInverse[0]->lowerBound);
        self::assertFalse($upperInverse[0]->includesLowerBound);
        self::assertEqualsWithDelta(
            Math::evaluate('gammaincc(2, 1)'),
            $upperInverse[0]->upperBound,
            1.0E-10,
        );
        self::assertTrue($upperInverse[0]->includesUpperBound);
    }

    public function testIncompleteBetaInequalitiesRespectShapeAndValueDomains(): void
    {
        $valueDomain = Math::solveInequality(
            'betainc(1, 1, x) >= 0',
            'x',
            -0.5,
            1.5,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $valueDomain);
        self::assertSame(0.0, $valueDomain[0]->lowerBound);
        self::assertTrue($valueDomain[0]->includesLowerBound);
        self::assertSame(1.0, $valueDomain[0]->upperBound);
        self::assertTrue($valueDomain[0]->includesUpperBound);

        $shapeDomain = Math::solveInequality(
            'betainc(x, 1, 0.5) > 0',
            'x',
            -1.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $shapeDomain);
        self::assertSame(0.0, $shapeDomain[0]->lowerBound);
        self::assertFalse($shapeDomain[0]->includesLowerBound);
        self::assertSame(2.0, $shapeDomain[0]->upperBound);
        self::assertTrue($shapeDomain[0]->includesUpperBound);
    }

    public function testIncompleteBetaComplementInequalityRespectsValueDomain(): void
    {
        $solutions = Math::solveInequality(
            'betaincc(1, 1, x) >= 0',
            'x',
            -0.5,
            1.5,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testGcdInequalityFindsIntegerDomainPointsBetweenScanSamples(): void
    {
        $solutions = Math::solveInequality(
            'gcd(x, 6) >= 3',
            'x',
            -6.0,
            6.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(5, $solutions);
        foreach ([-6.0, -3.0, 0.0, 3.0, 6.0] as $index => $point) {
            self::assertSame($point, $solutions[$index]->lowerBound);
            self::assertSame($point, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testDoubleFactorialInequalityFindsDiscreteIntegerPoints(): void
    {
        $solutions = Math::solveInequality('x!! >= 15', 'x', 4.0, 6.0);

        self::assertCount(2, $solutions);
        self::assertSame(5.0, $solutions[0]->lowerBound);
        self::assertSame(5.0, $solutions[0]->upperBound);
        self::assertSame(6.0, $solutions[1]->lowerBound);
        self::assertSame(6.0, $solutions[1]->upperBound);
    }

    public function testBetaInequalityPartitionsAtPolesAndZeros(): void
    {
        $solutions = Math::solveInequality('beta(x, 1.5) > 0', 'x', -2.5, 1.0);

        self::assertCount(3, $solutions);
        self::assertEqualsWithDelta(-2.5, $solutions[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(-2.0, $solutions[0]->upperBound, 1.0E-10);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(-1.5, $solutions[1]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $solutions[1]->upperBound, 1.0E-10);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
        self::assertSame(0.0, $solutions[2]->lowerBound);
        self::assertSame(1.0, $solutions[2]->upperBound);
        self::assertFalse($solutions[2]->includesLowerBound);
        self::assertTrue($solutions[2]->includesUpperBound);
    }

    public function testBetaInequalityContinuesAboveFormerPositiveArgumentLimit(): void
    {
        $solutions = Math::solveInequality(
            'beta(x, 0.5) > 0',
            'x',
            170.5,
            172.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertSame(170.5, $solutions[0]->lowerBound);
        self::assertSame(172.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testBetaInequalityExcludesUndefinedRangeBeforeSumLimit(): void
    {
        $solutions = Math::solveInequality(
            'beta(x, -100.5)^2 >= 0',
            'x',
            -71.0,
            -70.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertSame(-70.5, $solutions[0]->lowerBound);
        self::assertSame(-70.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testBetaInequalityKeepsIntegerSumsBelowTheRealRangeAsIsolatedPoints(): void
    {
        $solutions = Math::solveInequality(
            'beta(x, -100.5) > -1',
            'x',
            -75.0,
            -70.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(5, $solutions);
        foreach ([-74.5, -73.5, -72.5, -71.5, -70.5] as $index => $point) {
            self::assertSame($point, $solutions[$index]->lowerBound);
            self::assertSame($point, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testZetaInequalityClassifiesExtendedNegativeRange(): void
    {
        $solutions = Math::solveInequality('zeta(x) <= 0', 'x', -172.0, -168.0);

        self::assertCount(2, $solutions);
        self::assertSame(-172.0, $solutions[0]->lowerBound);
        self::assertSame(-172.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertSame(-170.0, $solutions[1]->lowerBound);
        self::assertSame(-168.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testGammaInequalityIncludesFiniteArgumentRangeBoundary(): void
    {
        $solutions = Math::solveInequality(
            'gamma(1 / x) > 0',
            'x',
            0.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(1.0 / 171.0, $solutions[0]->lowerBound, 1.0E-12);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testGammaInequalityEnumeratesPolesForUnboundedRationalArguments(): void
    {
        $solutions = Math::solveInequality(
            'gamma(1 / x) <= 0',
            'x',
            -3.0,
            3.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(86, $solutions);
        foreach (\range(-171, -1) as $pole) {
            self::assertFalse(
                $this->containsPoint($solutions, 1.0 / $pole),
                'The gamma pole at argument ' . $pole . ' must be excluded.',
            );
        }

        for ($index = 0; $index <= 684; ++$index) {
            $argument = -171.0 + ($index / 4.0);
            if ($argument === 0.0 || \floor($argument) === $argument) {
                continue;
            }
            $value = 1.0 / $argument;
            if ($value < -3.0 || $value > 3.0) {
                continue;
            }
            try {
                $expected = Math::evaluate('gamma(1 / x)', ['x' => $value]) <= 0.0;
            } catch (\Throwable) {
                $expected = false;
            }

            self::assertSame(
                $expected,
                $this->containsPoint($solutions, $value),
                'Inequality membership at reciprocal argument ' . $argument,
            );
        }
    }

    public function testNegativeVariablePowerDomainsRetainIntegerExponentSolutions(): void
    {
        $selfPower = Math::solveInequality(
            'x^x <= 2',
            'x',
            -3.0,
            3.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(4, $selfPower);
        foreach ([-3.0, -2.0, -1.0] as $index => $point) {
            self::assertSame($point, $selfPower[$index]->lowerBound);
            self::assertSame($point, $selfPower[$index]->upperBound);
            self::assertTrue($selfPower[$index]->includesLowerBound);
            self::assertTrue($selfPower[$index]->includesUpperBound);
        }
        self::assertSame(0.0, $selfPower[3]->lowerBound);
        self::assertTrue($selfPower[3]->includesLowerBound);
        self::assertEqualsWithDelta(1.5596104694623691, $selfPower[3]->upperBound, 1.0E-9);

        $negativeConstantBase = Math::solveInequality(
            '(-2)^x <= 1',
            'x',
            -3.0,
            3.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertSame(
            [-3.0, -2.0, -1.0, 0.0, 1.0, 3.0],
            \array_map(static fn ($interval): float => $interval->lowerBound, $negativeConstantBase),
        );
        foreach ($negativeConstantBase as $interval) {
            self::assertSame($interval->lowerBound, $interval->upperBound);
        }

        $piecewiseExponent = Math::solveInequality(
            '(-2)^floor(x) <= 1',
            'x',
            -3.0,
            3.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(2, $piecewiseExponent);
        self::assertSame(-3.0, $piecewiseExponent[0]->lowerBound);
        self::assertSame(2.0, $piecewiseExponent[0]->upperBound);
        self::assertTrue($piecewiseExponent[0]->includesLowerBound);
        self::assertFalse($piecewiseExponent[0]->includesUpperBound);
        self::assertSame(3.0, $piecewiseExponent[1]->lowerBound);
        self::assertSame(3.0, $piecewiseExponent[1]->upperBound);

        $zeroBaseBoundary = Math::solveInequality(
            'x^(x + 0.5) <= 1',
            'x',
            -2.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(3, $zeroBaseBoundary);
        self::assertSame(-1.5, $zeroBaseBoundary[0]->lowerBound);
        self::assertSame(-0.5, $zeroBaseBoundary[1]->lowerBound);
        self::assertSame(0.0, $zeroBaseBoundary[2]->lowerBound);
        self::assertSame(1.0, $zeroBaseBoundary[2]->upperBound);
        self::assertFalse($this->containsPoint($zeroBaseBoundary, -0.25));
        self::assertTrue($this->containsPoint($zeroBaseBoundary, 0.0));

        $smallTarget = Math::solveInequality(
            'x^(x + 0.5) <= 0.1',
            'x',
            -2.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(2, $smallTarget);
        self::assertSame(0.0, $smallTarget[1]->lowerBound);
        self::assertTrue($smallTarget[1]->includesLowerBound);
        self::assertEqualsWithDelta(0.011046674797705193, $smallTarget[1]->upperBound, 1.0E-9);
        self::assertEqualsWithDelta(0.0, Math::evaluate('x^(x + 0.5)', ['x' => 0.0]), 0.0);
        $this->expectException(\MathPHP\Exception\EvaluationException::class);
        Math::evaluate('x^(x + 0.5)', ['x' => -0.25]);
    }

    public function testVariablePowerInequalitiesPartitionZeroBaseBoundaries(): void
    {
        $negativeExponentAtZero = Math::solveInequality(
            '(x^2)^(x - 1) >= 0',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(2, $negativeExponentAtZero);
        self::assertSame(-1.0, $negativeExponentAtZero[0]->lowerBound);
        self::assertSame(0.0, $negativeExponentAtZero[0]->upperBound);
        self::assertTrue($negativeExponentAtZero[0]->includesLowerBound);
        self::assertFalse($negativeExponentAtZero[0]->includesUpperBound);
        self::assertSame(0.0, $negativeExponentAtZero[1]->lowerBound);
        self::assertSame(1.0, $negativeExponentAtZero[1]->upperBound);
        self::assertFalse($negativeExponentAtZero[1]->includesLowerBound);
        self::assertTrue($negativeExponentAtZero[1]->includesUpperBound);

        $positiveExponentAtZero = Math::solveInequality(
            '(x^2)^(x + 1) >= 0',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $positiveExponentAtZero);
        self::assertSame(-1.0, $positiveExponentAtZero[0]->lowerBound);
        self::assertSame(1.0, $positiveExponentAtZero[0]->upperBound);
        self::assertTrue($positiveExponentAtZero[0]->includesLowerBound);
        self::assertTrue($positiveExponentAtZero[0]->includesUpperBound);

        $zeroConstantBase = Math::solveInequality(
            '0^x >= 0',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $zeroConstantBase);
        self::assertSame(0.0, $zeroConstantBase[0]->lowerBound);
        self::assertSame(1.0, $zeroConstantBase[0]->upperBound);
        self::assertTrue($zeroConstantBase[0]->includesLowerBound);
        self::assertTrue($zeroConstantBase[0]->includesUpperBound);
    }

    public function testBesselInequalitiesRespectInclusiveArgumentRangeLimits(): void
    {
        $besselJ = Math::solveInequality(
            'besselj(0, x)^2 >= 0',
            'x',
            0.0,
            1001.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        $besselI = Math::solveInequality(
            'besseli(0, x) > 0',
            'x',
            0.0,
            714.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $besselJ);
        self::assertSame(0.0, $besselJ[0]->lowerBound);
        self::assertSame(SpecialFunctions::MAX_BESSEL_J_ARGUMENT, $besselJ[0]->upperBound);
        self::assertTrue($besselJ[0]->includesUpperBound);
        self::assertCount(1, $besselI);
        self::assertSame(0.0, $besselI[0]->lowerBound);
        self::assertSame(SpecialFunctions::MAX_BESSEL_I_ARGUMENT, $besselI[0]->upperBound);
        self::assertTrue($besselI[0]->includesUpperBound);
    }

    public function testBesselYInequalitiesRespectItsSingularAndFiniteRangeBoundaries(): void
    {
        $solutions = Math::solveInequality(
            'bessely(0, x)^2 >= 0',
            'x',
            0.0,
            1_001.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(SpecialFunctions::MAX_BESSEL_Y_ARGUMENT, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testBesselYSignInequalityPartitionsAtEveryZero(): void
    {
        $solutions = Math::solveInequality('bessely(0, x) >= 0', 'x', 0.0, 5.0);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.893576966279, $solutions[0]->lowerBound, 1.0E-9);
        self::assertEqualsWithDelta(3.957678419315, $solutions[0]->upperBound, 1.0E-9);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testZetaInequalityPartitionsAtItsPole(): void
    {
        $solutions = Math::solveInequality('zeta(x) > 10000', 'x', 0.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(1.0001000058, $solutions[0]->upperBound, 1.0E-9);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testFactorialInequalityFindsNonnegativeIntegerDomainPoints(): void
    {
        $solutions = Math::solveInequality(
            'x! >= 1',
            'x',
            -1.0,
            6.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(7, $solutions);
        foreach (range(0, 6) as $index => $point) {
            self::assertSame((float) $point, $solutions[$index]->lowerBound);
            self::assertSame((float) $point, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testBesselOrderInequalityFindsIsolatedValidOrders(): void
    {
        $solutions = Math::solveInequality(
            'besselj(x, 1) >= 0',
            'x',
            -2.0,
            2.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(4, $solutions);
        foreach ([-2.0, 0.0, 1.0, 2.0] as $index => $point) {
            self::assertSame($point, $solutions[$index]->lowerBound);
            self::assertSame($point, $solutions[$index]->upperBound);
        }
    }

    public function testBesselOrderInequalityUsesExpandedOrderRange(): void
    {
        $target = \sprintf('%.17g', Math::evaluate('besselj(256, 300)'));
        $solutions = Math::solveInequality(
            'besselj(x, 300) >= ' . $target,
            'x',
            255.0,
            256.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(256.0, $solutions[0]->lowerBound);
        self::assertSame(256.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testPolygammaOrderInequalityFindsIsolatedValidOrders(): void
    {
        $solutions = Math::solveInequality(
            'polygamma(x, 1) > 0',
            'x',
            0.0,
            4.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(2, $solutions);
        foreach ([1.0, 3.0] as $index => $point) {
            self::assertSame($point, $solutions[$index]->lowerBound);
            self::assertSame($point, $solutions[$index]->upperBound);
        }
    }

    public function testPolygammaOrderInequalityUsesExpandedOrderRange(): void
    {
        $target = \sprintf('%.17g', Math::evaluate('polygamma(128, 1)'));
        $solutions = Math::solveInequality(
            'polygamma(x, 1) > ' . $target,
            'x',
            127.0,
            128.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(127.0, $solutions[0]->lowerBound);
        self::assertSame(127.0, $solutions[0]->upperBound);
    }

    public function testBesselJSignInequalityPartitionsAtEveryZero(): void
    {
        $solutions = Math::solveInequality('besselj(0, x) >= 0', 'x', -8.0, 8.0);

        self::assertCount(3, $solutions);
        $expectedIntervals = [
            [-8.0, -5.520078110283066],
            [-2.404825557696313, 2.404825557696313],
            [5.520078110283066, 8.0],
        ];
        foreach ($expectedIntervals as $index => [$lower, $upper]) {
            self::assertEqualsWithDelta($lower, $solutions[$index]->lowerBound, 1.0E-9);
            self::assertEqualsWithDelta($upper, $solutions[$index]->upperBound, 1.0E-9);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testModifiedBesselITangencyRespectsStrictAndInclusiveInequalities(): void
    {
        $inclusive = Math::solveInequality('besseli(0, x) >= 1', 'x', -8.0, 8.0);
        $strict = Math::solveInequality('besseli(0, x) > 1', 'x', -8.0, 8.0);

        self::assertCount(1, $inclusive);
        self::assertSame(-8.0, $inclusive[0]->lowerBound);
        self::assertSame(8.0, $inclusive[0]->upperBound);
        self::assertTrue($inclusive[0]->includesLowerBound);
        self::assertTrue($inclusive[0]->includesUpperBound);
        self::assertCount(2, $strict);
        self::assertSame(-8.0, $strict[0]->lowerBound);
        self::assertSame(0.0, $strict[0]->upperBound);
        self::assertTrue($strict[0]->includesLowerBound);
        self::assertFalse($strict[0]->includesUpperBound);
        self::assertSame(0.0, $strict[1]->lowerBound);
        self::assertSame(8.0, $strict[1]->upperBound);
        self::assertFalse($strict[1]->includesLowerBound);
        self::assertTrue($strict[1]->includesUpperBound);
    }

    public function testInclusivePolynomialInequalityReturnsClosedInterval(): void
    {
        $solutions = Math::solveInequality('x^2 <= 1', 'x', -2.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(-1.0, $solutions[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solutions[0]->upperBound, 1.0E-10);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertTrue($solutions[0]->contains(0.0));
    }

    public function testStrictAndInclusiveBoundariesAreDifferent(): void
    {
        $strict = Math::solveInequality('x^2 < 1', 'x', -2.0, 2.0);
        $outside = Math::solveInequality('x^2 > 1', 'x', -2.0, 2.0);

        self::assertCount(1, $strict);
        self::assertFalse($strict[0]->includesLowerBound);
        self::assertFalse($strict[0]->includesUpperBound);
        self::assertCount(2, $outside);
        self::assertTrue($outside[0]->includesLowerBound);
        self::assertFalse($outside[0]->includesUpperBound);
        self::assertFalse($outside[1]->includesLowerBound);
        self::assertTrue($outside[1]->includesUpperBound);
    }

    public function testNotEqualComparisonsExcludeEqualityRootsAndUndefinedPoints(): void
    {
        $singleRoot = Math::solveInequality('x != 1', 'x', -2.0, 2.0);
        self::assertCount(2, $singleRoot);
        self::assertSame(-2.0, $singleRoot[0]->lowerBound);
        self::assertSame(1.0, $singleRoot[0]->upperBound);
        self::assertTrue($singleRoot[0]->includesLowerBound);
        self::assertFalse($singleRoot[0]->includesUpperBound);
        self::assertSame(1.0, $singleRoot[1]->lowerBound);
        self::assertSame(2.0, $singleRoot[1]->upperBound);
        self::assertFalse($singleRoot[1]->includesLowerBound);
        self::assertTrue($singleRoot[1]->includesUpperBound);

        $twoRoots = Math::solveInequality('x² ≠ 1', 'x', -2.0, 2.0);
        self::assertCount(3, $twoRoots);
        self::assertFalse($twoRoots[0]->includesUpperBound);
        self::assertFalse($twoRoots[1]->includesLowerBound);
        self::assertFalse($twoRoots[1]->includesUpperBound);
        self::assertFalse($twoRoots[2]->includesLowerBound);

        $pole = Math::solveInequality('1 / x ≠ 0', 'x', -1.0, 1.0);
        self::assertCount(2, $pole);
        self::assertSame(-1.0, $pole[0]->lowerBound);
        self::assertSame(0.0, $pole[0]->upperBound);
        self::assertFalse($pole[0]->includesUpperBound);
        self::assertSame(0.0, $pole[1]->lowerBound);
        self::assertSame(1.0, $pole[1]->upperBound);
        self::assertFalse($pole[1]->includesLowerBound);
    }

    public function testTinyExactPolynomialInequalitiesUseExactSigns(): void
    {
        $inclusive = Math::solveInequality('x^2 <= 1e-400', 'x', -1.0, 1.0);
        $strict = Math::solveInequality('x^2 < 1e-400', 'x', -1.0, 1.0);
        $outside = Math::solveInequality('x^2 >= 1e-400', 'x', -1.0, 1.0);
        $positivePolynomial = Math::solveInequality('x^2 + 1e-400 <= 0', 'x', -1.0, 1.0);
        $zeroAgainstTinyPositive = Math::solveInequality('0*x >= 1e-400', 'x', -1.0, 1.0);
        $translatedStrict = Math::solveInequality('(x - 1)^2 < 1e-30', 'x', 0.0, 2.0);
        $translatedOutside = Math::solveInequality('(x - 1)^2 >= 1e-30', 'x', 0.0, 2.0);
        $translatedPositive = Math::solveInequality('(x - 1)^2 + 1e-30 <= 0', 'x', 0.0, 2.0);
        $clusteredOutside = Math::solveInequality(
            '(x - 1)^2 * (x - 1.000000000000002)^2 >= 1e-30',
            'x',
            0.0,
            2.0,
        );

        self::assertCount(1, $inclusive);
        self::assertEqualsWithDelta(-1.0E-200, $inclusive[0]->lowerBound, 1.0E-214);
        self::assertEqualsWithDelta(1.0E-200, $inclusive[0]->upperBound, 1.0E-214);
        self::assertTrue($inclusive[0]->includesLowerBound);
        self::assertTrue($inclusive[0]->includesUpperBound);

        self::assertCount(1, $strict);
        self::assertEqualsWithDelta(-1.0E-200, $strict[0]->lowerBound, 1.0E-214);
        self::assertEqualsWithDelta(1.0E-200, $strict[0]->upperBound, 1.0E-214);
        self::assertFalse($strict[0]->includesLowerBound);
        self::assertFalse($strict[0]->includesUpperBound);

        self::assertCount(2, $outside);
        self::assertSame(-1.0, $outside[0]->lowerBound);
        self::assertEqualsWithDelta(-1.0E-200, $outside[0]->upperBound, 1.0E-214);
        self::assertEqualsWithDelta(1.0E-200, $outside[1]->lowerBound, 1.0E-214);
        self::assertSame(1.0, $outside[1]->upperBound);
        self::assertSame([], $positivePolynomial);
        self::assertSame([], $zeroAgainstTinyPositive);

        self::assertCount(1, $translatedStrict);
        self::assertEqualsWithDelta(1.0 - 1.0E-15, $translatedStrict[0]->lowerBound, 1.0E-15);
        self::assertEqualsWithDelta(1.0 + 1.0E-15, $translatedStrict[0]->upperBound, 1.0E-15);
        self::assertFalse($translatedStrict[0]->includesLowerBound);
        self::assertFalse($translatedStrict[0]->includesUpperBound);
        self::assertCount(2, $translatedOutside);
        self::assertCount(2, $clusteredOutside);
        self::assertEqualsWithDelta(0.9999999683772234, $clusteredOutside[0]->upperBound, 1.0E-14);
        self::assertEqualsWithDelta(1.0000000316227786, $clusteredOutside[1]->lowerBound, 1.0E-14);
        self::assertSame([], $translatedPositive);
    }

    public function testInequalityKeepsIntervalsBetweenAdjacentFloatRoots(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 1);
        $equation = \sprintf(
            '(x - %.17g) * (x - %.17g)',
            1.0,
            1.0000000000000002,
        );
        $inclusive = Math::solveInequality(
            $equation . ' <= 0',
            'x',
            0.9,
            1.1,
            solverOptions: $options,
        );
        $strict = Math::solveInequality(
            $equation . ' < 0',
            'x',
            0.9,
            1.1,
            solverOptions: $options,
        );
        $rationalEquation = \sprintf(
            '((x - %.17g) * (x - %.17g)) / (x + 2)',
            1.0,
            1.0000000000000002,
        );
        $rational = Math::solveInequality(
            $rationalEquation . ' <= 0',
            'x',
            0.9,
            1.1,
            solverOptions: $options,
        );
        $negativeDenominator = Math::solveInequality(
            \str_replace('/ (x + 2)', '/ (-x - 2)', $rationalEquation) . ' <= 0',
            'x',
            0.9,
            1.1,
            solverOptions: $options,
        );

        self::assertCount(1, $inclusive);
        self::assertSame(1.0, $inclusive[0]->lowerBound);
        self::assertSame(1.0000000000000002, $inclusive[0]->upperBound);
        self::assertTrue($inclusive[0]->includesLowerBound);
        self::assertTrue($inclusive[0]->includesUpperBound);
        self::assertCount(1, $strict);
        self::assertSame(1.0, $strict[0]->lowerBound);
        self::assertSame(1.0000000000000002, $strict[0]->upperBound);
        self::assertFalse($strict[0]->includesLowerBound);
        self::assertFalse($strict[0]->includesUpperBound);
        self::assertCount(1, $rational);
        self::assertSame(1.0, $rational[0]->lowerBound);
        self::assertSame(1.0000000000000002, $rational[0]->upperBound);
        self::assertTrue($rational[0]->includesLowerBound);
        self::assertTrue($rational[0]->includesUpperBound);
        self::assertCount(2, $negativeDenominator);
        self::assertSame(0.9, $negativeDenominator[0]->lowerBound);
        self::assertSame(1.0, $negativeDenominator[0]->upperBound);
        self::assertSame(1.0000000000000002, $negativeDenominator[1]->lowerBound);
        self::assertSame(1.1, $negativeDenominator[1]->upperBound);
    }

    public function testInequalitiesClassifyIntervalsWithoutARepresentableMidpoint(): void
    {
        $upper = 5.0E-324;
        foreach (
            [
                'x > 0' => [false, true],
                'x >= 0' => [true, true],
                'x^2 > 0' => [false, true],
                'x < 5e-324' => [true, false],
                'x <= 5e-324' => [true, true],
            ] as $inequality => [$includesLower, $includesUpper]
        ) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                0.0,
                $upper,
                solverOptions: new SolverOptions(scanSteps: 1),
            );

            self::assertCount(1, $solutions, $inequality);
            self::assertSame(0.0, $solutions[0]->lowerBound, $inequality);
            self::assertSame($upper, $solutions[0]->upperBound, $inequality);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound, $inequality);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound, $inequality);
        }
    }

    public function testRationalInequalitiesClassifySubnormalIntervalsAroundPoles(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $belowPole = Math::solveInequality(
            '1 / (x - 5e-324) < 0',
            'x',
            0.0,
            5.0E-324,
            solverOptions: $options,
        );
        $abovePole = Math::solveInequality(
            '1 / (x - 5e-324) > 0',
            'x',
            5.0E-324,
            1.0E-323,
            solverOptions: $options,
        );

        self::assertCount(1, $belowPole);
        self::assertSame(0.0, $belowPole[0]->lowerBound);
        self::assertSame(5.0E-324, $belowPole[0]->upperBound);
        self::assertTrue($belowPole[0]->includesLowerBound);
        self::assertFalse($belowPole[0]->includesUpperBound);
        self::assertCount(1, $abovePole);
        self::assertSame(5.0E-324, $abovePole[0]->lowerBound);
        self::assertSame(1.0E-323, $abovePole[0]->upperBound);
        self::assertFalse($abovePole[0]->includesLowerBound);
        self::assertTrue($abovePole[0]->includesUpperBound);
    }

    public function testTangentialRootCanProduceAnIsolatedSolutionPoint(): void
    {
        $solutions = Math::solveInequality('x^2 <= 0', 'x', -1.0, 1.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->contains(0.0));
    }

    public function testLogarithmicPeriodicInequalityPartitionsRootsNearZero(): void
    {
        $solutions = Math::solveInequality(
            'sin(ln(x)) >= 0',
            'x',
            \exp(-700.0),
            1.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
        );

        self::assertCount(112, $solutions);
        self::assertEqualsWithDelta(
            \exp(-222.0 * \M_PI),
            $solutions[0]->lowerBound,
            1.0E-12 * $solutions[0]->lowerBound,
        );
        self::assertEqualsWithDelta(
            \exp(-221.0 * \M_PI),
            $solutions[0]->upperBound,
            1.0E-12 * $solutions[0]->upperBound,
        );
        self::assertSame(1.0, $solutions[111]->lowerBound);
        self::assertSame(1.0, $solutions[111]->upperBound);
        self::assertTrue($solutions[111]->includesLowerBound);
        self::assertTrue($solutions[111]->includesUpperBound);
    }

    public function testLogarithmicPeriodicInequalityRejectsInfiniteIntervalFamilies(): void
    {
        $thrown = 0;
        foreach (
            [
                'sin(ln(x^2)) >= 0',
                'sin(1/x) >= 0',
                'sin(1/x) / sin(1/x) >= 1',
            ] as $inequality
        ) {
            try {
                Math::solveInequality($inequality, 'x', -1.0, 1.0);
            } catch (\MathPHP\Solver\InfiniteRootAccumulationException) {
                ++$thrown;
            }
        }

        self::assertSame(3, $thrown);
    }

    public function testNestedExponentialPeriodicInequalitiesPartitionEveryRoot(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 1);
        $inclusive = Math::solveInequality(
            'sin(exp(exp(x))) >= 0',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );
        $strict = Math::solveInequality(
            'sin(exp(exp(x))) > 0',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );

        self::assertCount(258, $inclusive);
        self::assertCount(258, $strict);
        foreach ($inclusive as $index => $interval) {
            $strictInterval = $strict[$index];
            $expectedLower = $index === 0
                ? -2.0
                : \log(\log((2 * $index) * \M_PI));
            $expectedUpper = \log(\log(((2 * $index) + 1) * \M_PI));

            self::assertEqualsWithDelta($expectedLower, $interval->lowerBound, 1.0E-9);
            self::assertEqualsWithDelta($expectedUpper, $interval->upperBound, 1.0E-9);
            self::assertTrue($interval->includesLowerBound);
            self::assertTrue($interval->includesUpperBound);
            self::assertEqualsWithDelta($interval->lowerBound, $strictInterval->lowerBound, 1.0E-9);
            self::assertEqualsWithDelta($interval->upperBound, $strictInterval->upperBound, 1.0E-9);
            self::assertSame($index === 0, $strictInterval->includesLowerBound);
            self::assertFalse($strictInterval->includesUpperBound);
        }
    }

    public function testPeriodicSquareRootInequalitiesIncludeExactDomainAndPhaseBoundaries(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 1);
        $domainBoundarySolutions = Math::solveInequality(
            'sin(sqrt(1 - x^2)) >= 0',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );
        $phaseBoundarySolutions = Math::solveInequality(
            'sin(sqrt(x^2)) >= 0',
            'x',
            -4.0,
            4.0,
            solverOptions: $options,
        );

        self::assertCount(1, $domainBoundarySolutions);
        self::assertSame(-1.0, $domainBoundarySolutions[0]->lowerBound);
        self::assertSame(1.0, $domainBoundarySolutions[0]->upperBound);
        self::assertTrue($domainBoundarySolutions[0]->includesLowerBound);
        self::assertTrue($domainBoundarySolutions[0]->includesUpperBound);

        self::assertCount(1, $phaseBoundarySolutions);
        self::assertEqualsWithDelta(
            -\M_PI,
            $phaseBoundarySolutions[0]->lowerBound,
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            \M_PI,
            $phaseBoundarySolutions[0]->upperBound,
            1.0E-10,
        );
        self::assertTrue($phaseBoundarySolutions[0]->includesLowerBound);
        self::assertTrue($phaseBoundarySolutions[0]->includesUpperBound);
    }

    public function testUndefinedSampleSplitsTheSolutionSet(): void
    {
        $solutions = Math::solveInequality('1 / x > 0', 'x', -1.0, 1.0);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.0, $solutions[0]->lowerBound, 1.0E-10);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testNonGridAlignedPoleRefinesTruthTransition(): void
    {
        $solutions = Math::solveInequality(
            '1 / (x - 0.1) > 0',
            'x',
            -1.0,
            1.0,
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(0.1, $solutions[0]->lowerBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testPiecewiseFunctionInequalitiesUseExactJumpBoundaries(): void
    {
        foreach (
            [
                ['floor(x) < 2', -1.0, 2.0, true, false],
                ['floor(x) >= 2', 2.0, 5.0, true, true],
                ['ceil(x) <= 2', -1.0, 2.0, true, true],
                ['trunc(x) < 2', -1.0, 2.0, true, false],
                ['trunc(x) >= 2', 2.0, 5.0, true, true],
                ['round(x) < 2', -1.0, 1.5, true, false],
                ['round(x) >= 2', 1.5, 5.0, true, true],
            ] as [$inequality, $lower, $upper, $includesLower, $includesUpper]
        ) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -1.0,
                5.0,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
            );

            self::assertCount(1, $solutions, $inequality);
            self::assertSame($lower, $solutions[0]->lowerBound);
            self::assertSame($upper, $solutions[0]->upperBound);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound);
        }
    }

    public function testTruncationInequalitiesPartitionAtSmoothArgumentExtrema(): void
    {
        $cases = [
            ['trunc(sin(x)) < 1', \M_PI / 2.0],
            ['trunc(sin(x)) > -1', -\M_PI / 2.0],
        ];
        foreach ($cases as [$inequality, $excludedPoint]) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -3.0,
                3.0,
                solverOptions: new SolverOptions(scanSteps: 8),
            );

            self::assertCount(2, $solutions, $inequality);
            self::assertEqualsWithDelta($excludedPoint, $solutions[0]->upperBound, 1.0E-12);
            self::assertFalse($solutions[0]->includesUpperBound);
            self::assertEqualsWithDelta($excludedPoint, $solutions[1]->lowerBound, 1.0E-12);
            self::assertFalse($solutions[1]->includesLowerBound);
        }
    }

    public function testSignInequalitiesPartitionAtPolynomialZeros(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $negativeOrZero = Math::solveInequality(
            'sign(x) < 1',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $positive = Math::solveInequality(
            'sign(x) >= 1',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $allValues = Math::solveInequality(
            'sign(x) <= 1',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $isolatedRepeatedZero = Math::solveInequality(
            'sign((x - 0.123)^2) <= 0',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );

        self::assertCount(1, $negativeOrZero);
        self::assertSame(-1.0, $negativeOrZero[0]->lowerBound);
        self::assertSame(0.0, $negativeOrZero[0]->upperBound);
        self::assertTrue($negativeOrZero[0]->includesUpperBound);
        self::assertCount(1, $positive);
        self::assertSame(0.0, $positive[0]->lowerBound);
        self::assertFalse($positive[0]->includesLowerBound);
        self::assertSame(1.0, $positive[0]->upperBound);
        self::assertCount(1, $allValues);
        self::assertSame(-1.0, $allValues[0]->lowerBound);
        self::assertSame(1.0, $allValues[0]->upperBound);
        self::assertCount(1, $isolatedRepeatedZero);
        self::assertEqualsWithDelta(0.123, $isolatedRepeatedZero[0]->lowerBound, 1.0E-8);
        self::assertSame(
            $isolatedRepeatedZero[0]->lowerBound,
            $isolatedRepeatedZero[0]->upperBound,
        );
    }

    public function testRoundingInequalitiesUsePolynomialJumpLevelsAndExactEndpointValues(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        foreach (
            [
                ['floor(x^2) < 2', \sqrt(2.0), false],
                ['ceil(x^2) <= 2', \sqrt(2.0), true],
                ['round(x^2) < 2', \sqrt(1.5), false],
            ] as [$inequality, $boundary, $includesBoundary]
        ) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -2.0,
                2.0,
                solverOptions: $options,
            );

            self::assertCount(1, $solutions, $inequality);
            self::assertEqualsWithDelta(-$boundary, $solutions[0]->lowerBound, 1.0E-10);
            self::assertEqualsWithDelta($boundary, $solutions[0]->upperBound, 1.0E-10);
            self::assertSame($includesBoundary, $solutions[0]->includesLowerBound);
            self::assertSame($includesBoundary, $solutions[0]->includesUpperBound);
        }
    }

    public function testModuloInequalitiesReturnTheirDiscreteIntegerSolutions(): void
    {
        foreach (
            [
                'x % 2 < 1' => [-5.0, -4.0, -3.0, -2.0, -1.0, 0.0, 2.0, 4.0],
                'x % 2 <= 0' => [-5.0, -4.0, -3.0, -2.0, -1.0, 0.0, 2.0, 4.0],
                'x % 2 > 0' => [1.0, 3.0, 5.0],
            ] as $inequality => $expectedPoints
        ) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -5.0,
                5.0,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
            );

            self::assertCount(\count($expectedPoints), $solutions, $inequality);
            foreach ($expectedPoints as $index => $point) {
                self::assertSame($point, $solutions[$index]->lowerBound);
                self::assertSame($point, $solutions[$index]->upperBound);
                self::assertTrue($solutions[$index]->includesLowerBound);
                self::assertTrue($solutions[$index]->includesUpperBound);
            }
        }
    }

    public function testPolynomialModuloInequalitiesEnumerateIntegerOperandLevels(): void
    {
        $quadratic = Math::solveInequality(
            'x^2 % 3 <= 1',
            'x',
            -5.0,
            5.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
        );
        $expected = [];
        for ($level = 0; $level <= 25; ++$level) {
            if ($level % 3 > 1) {
                continue;
            }
            if ($level === 0) {
                $expected[] = 0.0;
                continue;
            }
            $root = \sqrt((float) $level);
            $expected[] = -$root;
            $expected[] = $root;
        }
        \sort($expected, SORT_NUMERIC);

        self::assertCount(\count($expected), $quadratic);
        foreach ($expected as $index => $point) {
            self::assertEqualsWithDelta($point, $quadratic[$index]->lowerBound, 1.0E-10);
            self::assertSame($quadratic[$index]->lowerBound, $quadratic[$index]->upperBound);
        }

        $affine = Math::solveInequality(
            '(2 * x + 1) % 3 < 1',
            'x',
            -5.0,
            5.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
        );
        $expectedAffine = [
            -5.0,
            -4.5,
            -4.0,
            -3.5,
            -3.0,
            -2.5,
            -2.0,
            -1.5,
            -1.0,
            -0.5,
            1.0,
            2.5,
            4.0,
        ];

        self::assertCount(\count($expectedAffine), $affine);
        foreach ($expectedAffine as $index => $point) {
            self::assertSame($point, $affine[$index]->lowerBound);
            self::assertSame($point, $affine[$index]->upperBound);
        }
    }

    public function testSameSignPoleIsReportedAsAnOpenDomainHole(): void
    {
        $solutions = Math::solveInequality(
            '1 / (x - 0.1)^2 > 0',
            'x',
            -1.0,
            1.0,
        );

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(0.1, $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(0.1, $solutions[1]->lowerBound, 1.0E-8);
        self::assertFalse($solutions[1]->includesLowerBound);
    }

    public function testPolygammaInequalitiesExcludePolesEvenWithOneScanStep(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $cases = [
            ['digamma(x) > 0', 1, true, false],
            ['trigamma(x) > 0', 2, true, true],
            ['tetragamma(x) > 0', 1, true, false],
            ['polygamma(3, x) > 0', 2, true, true],
            ['trigamma(x^2) > 0', 2, true, true],
            ['trigamma(sin(x)) > 0', 2, true, true],
            ['trigamma(sqrt(x^2)) > 0', 2, true, true],
            ['trigamma(cbrt(x)) > 0', 2, true, true],
            ['trigamma(root(x, 3)) > 0', 2, true, true],
        ];

        foreach ($cases as [$inequality, $expectedCount, $expectsNegative, $expectsPositive]) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -0.3,
                0.2,
                solverOptions: $options,
            );

            self::assertCount($expectedCount, $solutions, $inequality);
            $containsNegative = false;
            $containsPositive = false;
            foreach ($solutions as $solution) {
                self::assertFalse($solution->contains(0.0), $inequality);
                $containsNegative = $containsNegative || $solution->contains(-0.1);
                $containsPositive = $containsPositive || $solution->contains(0.1);
            }
            self::assertSame($expectsNegative, $containsNegative, $inequality);
            self::assertSame($expectsPositive, $containsPositive, $inequality);
        }

        $affineArgument = Math::solveInequality(
            'trigamma(2*x + 1) > 0',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(2, $affineArgument);
        self::assertFalse($affineArgument[0]->contains(-1.0));
        self::assertFalse($affineArgument[0]->contains(-0.5));
        self::assertFalse($affineArgument[1]->contains(-0.5));
        self::assertTrue($affineArgument[0]->contains(-0.75));
        self::assertTrue($affineArgument[1]->contains(0.0));

        $exponentialArgument = Math::solveInequality(
            'trigamma(exp(x) - 2) > 0',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        self::assertCount(3, $exponentialArgument);
        foreach ($exponentialArgument as $interval) {
            self::assertFalse($interval->contains(0.0));
            self::assertFalse($interval->contains(\log(2.0)));
        }
        self::assertTrue($exponentialArgument[0]->contains(-0.5));
        self::assertTrue($exponentialArgument[1]->contains(0.3));
        self::assertTrue($exponentialArgument[2]->contains(0.8));
    }

    public function testPolygammaPoleAccumulationAtLogarithmicBoundaryIsReported(): void
    {
        foreach (['trigamma(ln(x)) > 0', 'trigamma(ln(x) + 1) > 0', 'trigamma(2 * ln(x) - 1) > 0'] as $inequality) {
            try {
                Math::solveInequality(
                    $inequality,
                    'x',
                    -1.0,
                    1.0,
                    solverOptions: new SolverOptions(scanSteps: 1),
                );
                self::fail('Expected logarithmic pole accumulation to be reported.');
            } catch (\MathPHP\Solver\InfiniteSolutionSetException $exception) {
                self::assertStringContainsString(
                    'Polygamma poles accumulate at a domain boundary',
                    $exception->getMessage(),
                );
            }
        }

        $reflectedLogarithm = Math::solveInequality(
            'trigamma(-ln(x)) > 0',
            'x',
            0.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertNotEmpty($reflectedLogarithm);
        foreach ($reflectedLogarithm as $interval) {
            self::assertFalse($interval->contains(1.0));
        }
    }

    public function testPolygammaPoleAccumulationAtReciprocalBoundaryIsReported(): void
    {
        foreach (
            [
                ['trigamma(1/x) > 0', -1.0, 0.0],
                ['trigamma(-1/x) > 0', 0.0, 1.0],
                ['trigamma(-1/x^2) > 0', -1.0, 1.0],
            ] as [$inequality, $lowerBound, $upperBound]
        ) {
            try {
                Math::solveInequality(
                    $inequality,
                    'x',
                    $lowerBound,
                    $upperBound,
                    solverOptions: new SolverOptions(scanSteps: 1),
                );
                self::fail('Expected reciprocal pole accumulation to be reported.');
            } catch (\MathPHP\Solver\InfiniteSolutionSetException $exception) {
                self::assertStringContainsString(
                    'Polygamma poles accumulate at a domain boundary',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testNegativePowerReportsItsUndefinedBaseAsAnOpenHole(): void
    {
        $solutions = Math::solveInequality('x^-2 > 0', 'x', -0.8, 0.9);

        self::assertCount(2, $solutions);
        self::assertSame(-0.8, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(0.0, $solutions[0]->upperBound, 1.0E-10);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(0.0, $solutions[1]->lowerBound, 1.0E-10);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertSame(0.9, $solutions[1]->upperBound);
    }

    public function testIncludedFunctionDomainBoundariesAreNotTruncatedToScanPoints(): void
    {
        foreach (
            [
            ['sqrt(x) >= 0', 0.0, 1.2, true, true],
            ['asin(x) >= 0', 0.0, 1.0, true, true],
            ['acos(x) >= 0', -1.0, 1.0, true, true],
            ['acosh(x) >= 0', 1.0, 1.2, true, true],
            ['asech(x) >= 0', 0.0, 1.0, false, true],
            ] as [$inequality, $lower, $upper, $includesLower, $includesUpper]
        ) {
            $solutions = Math::solveInequality(
                $inequality,
                'x',
                -1.9,
                1.2,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
            );

            self::assertCount(1, $solutions, $inequality);
            self::assertEqualsWithDelta($lower, $solutions[0]->lowerBound, 1.0E-8);
            self::assertEqualsWithDelta($upper, $solutions[0]->upperBound, 1.0E-8);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound);
        }
    }

    public function testEvenIntegerRootBoundaryIsIncludedAndNotScanTruncated(): void
    {
        $solutions = Math::solveInequality(
            'root(x, 2) >= 0',
            'x',
            -1.9,
            2.1,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(2.1, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testNegativeIntegerRootDegreeCreatesAnExcludedDomainBoundary(): void
    {
        $solutions = Math::solveInequality(
            'root(x, -3) > 0',
            'x',
            -1.9,
            2.1,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(2.1, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testParameterizedIntegerRootDegreePreservesItsDomainBoundary(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 17);
        $evenDegree = Math::solveInequality(
            'root(x, degree) >= 0',
            'x',
            -1.9,
            2.1,
            ['degree' => 4],
            solverOptions: $options,
        );
        $negativeDegree = Math::solveInequality(
            'root(x, degree) > 0',
            'x',
            -1.9,
            2.1,
            ['degree' => -3],
            solverOptions: $options,
        );

        self::assertCount(1, $evenDegree);
        self::assertSame(0.0, $evenDegree[0]->lowerBound);
        self::assertTrue($evenDegree[0]->includesLowerBound);
        self::assertCount(1, $negativeDegree);
        self::assertSame(0.0, $negativeDegree[0]->lowerBound);
        self::assertFalse($negativeDegree[0]->includesLowerBound);
    }

    public function testKnownDefinedBoundaryDoesNotCreateAnApproximateTransition(): void
    {
        $solutions = Math::solveInequality(
            'sqrt(x) > 0',
            'x',
            -2.0,
            2.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 17),
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testInverseReciprocalTrigDomainBoundariesAreIncluded(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 17);
        $secant = Math::solveInequality(
            'asec(x) >= 0',
            'x',
            -2.3,
            2.4,
            solverOptions: $options,
        );
        $cosecant = Math::solveInequality(
            'acsc(x) >= 0',
            'x',
            -2.3,
            2.4,
            solverOptions: $options,
        );

        self::assertCount(2, $secant);
        self::assertEqualsWithDelta(-2.3, $secant[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $secant[0]->upperBound, 1.0E-10);
        self::assertTrue($secant[0]->includesUpperBound);
        self::assertEqualsWithDelta(1.0, $secant[1]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(2.4, $secant[1]->upperBound, 1.0E-10);
        self::assertTrue($secant[1]->includesLowerBound);

        self::assertCount(1, $cosecant);
        self::assertEqualsWithDelta(1.0, $cosecant[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(2.4, $cosecant[0]->upperBound, 1.0E-10);
        self::assertTrue($cosecant[0]->includesLowerBound);
    }

    public function testLogGammaReportsPolesWithinTheBoundedSearchRange(): void
    {
        $solutions = Math::solveInequality('lgamma(x) > 0', 'x', -0.8, 0.9);

        self::assertCount(2, $solutions);
        self::assertSame(-0.8, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(0.0, $solutions[0]->upperBound, 1.0E-10);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(0.0, $solutions[1]->lowerBound, 1.0E-10);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertSame(0.9, $solutions[1]->upperBound);
    }

    public function testKnownTrigonometricPoleSplitsTheSearchInterval(): void
    {
        $solutions = Math::solveInequality('tan(x) > 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(-\M_PI / 2.0, $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(0.0, $solutions[1]->lowerBound, 1.0E-8);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertEqualsWithDelta(\M_PI / 2.0, $solutions[1]->upperBound, 1.0E-8);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testReciprocalTrigonometricInequalitiesRespectExactPoleLocations(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $halfPi = \M_PI / 2.0;
        $tangent = Math::solveInequality(
            'tan(x) > 0',
            'x',
            $halfPi - 1.0E-12,
            $halfPi + 1.0E-12,
            solverOptions: $options,
        );
        $cosecant = Math::solveInequality(
            'csc(x) > 0',
            'x',
            -1.0E-12,
            1.0E-12,
            solverOptions: $options,
        );

        self::assertCount(1, $tangent);
        self::assertFalse($tangent[0]->includesUpperBound);
        self::assertSame($halfPi, $tangent[0]->upperBound);
        self::assertTrue($tangent[0]->contains(1.5707963267948957));

        self::assertCount(1, $cosecant);
        self::assertFalse($cosecant[0]->includesLowerBound);
        self::assertSame(0.0, $cosecant[0]->lowerBound);
        self::assertTrue($cosecant[0]->contains(5.0E-16));
    }

    public function testAtan2PrincipalBranchCutPartitionsSparseInequalitySearch(): void
    {
        $solutions = Math::solveInequality(
            'atan2(x, -1) <= -3',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        $rational = Math::solveInequality(
            'atan2((x - 1) / (x + 1), -1) <= -3',
            'x',
            0.0,
            1.5,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        $axisAligned = Math::solveInequality(
            'atan2(0, x) <= 1',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        $rationalAxis = Math::solveInequality(
            'atan2(0, (x - 0.25) / (x + 1)) <= 1',
            'x',
            0.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(-\tan(-3.0), $solutions[0]->lowerBound, 1.0E-10);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        $rationalLevel = -\tan(-3.0);
        self::assertCount(1, $rational);
        self::assertEqualsWithDelta(
            (1.0 + $rationalLevel) / (1.0 - $rationalLevel),
            $rational[0]->lowerBound,
            1.0E-10,
        );
        self::assertSame(1.0, $rational[0]->upperBound);
        self::assertTrue($rational[0]->includesLowerBound);
        self::assertFalse($rational[0]->includesUpperBound);
        self::assertCount(1, $axisAligned);
        self::assertSame(0.0, $axisAligned[0]->lowerBound);
        self::assertSame(1.0, $axisAligned[0]->upperBound);
        self::assertFalse($axisAligned[0]->includesLowerBound);
        self::assertTrue($axisAligned[0]->includesUpperBound);
        self::assertCount(1, $rationalAxis);
        self::assertSame(0.25, $rationalAxis[0]->lowerBound);
        self::assertSame(1.0, $rationalAxis[0]->upperBound);
        self::assertFalse($rationalAxis[0]->includesLowerBound);
        self::assertTrue($rationalAxis[0]->includesUpperBound);
    }

    public function testAtan2NonPolynomialOriginPartitionsSameTruthStateIntervals(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $belowUpper = Math::solveInequality(
            'atan2(sin(x), x) < 3',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $aboveLower = Math::solveInequality(
            'atan2(sin(x), x) > -3',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $definedAxis = Math::solveInequality(
            'atan2(sin(x), 2) <= 1',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );

        foreach ([$belowUpper, $aboveLower] as $intervals) {
            self::assertCount(2, $intervals);
            self::assertSame(-1.0, $intervals[0]->lowerBound);
            self::assertSame(0.0, $intervals[0]->upperBound);
            self::assertTrue($intervals[0]->includesLowerBound);
            self::assertFalse($intervals[0]->includesUpperBound);
            self::assertSame(0.0, $intervals[1]->lowerBound);
            self::assertSame(1.0, $intervals[1]->upperBound);
            self::assertFalse($intervals[1]->includesLowerBound);
            self::assertTrue($intervals[1]->includesUpperBound);
        }
        self::assertCount(1, $definedAxis);
        self::assertSame(-1.0, $definedAxis[0]->lowerBound);
        self::assertSame(1.0, $definedAxis[0]->upperBound);
        self::assertTrue($definedAxis[0]->includesLowerBound);
        self::assertTrue($definedAxis[0]->includesUpperBound);
    }

    public function testHypotNonPolynomialSharedZeroPartitionsStrictInequality(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $strict = Math::solveInequality(
            'hypot(sin(x), x) > 0',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $inclusive = Math::solveInequality(
            'hypot(sin(x), x) >= 0',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );
        $aboveOne = Math::solveInequality(
            'hypot(sin(x), x) > 1',
            'x',
            -1.0,
            1.0,
            solverOptions: $options,
        );

        self::assertCount(2, $strict);
        self::assertSame(-1.0, $strict[0]->lowerBound);
        self::assertSame(0.0, $strict[0]->upperBound);
        self::assertTrue($strict[0]->includesLowerBound);
        self::assertFalse($strict[0]->includesUpperBound);
        self::assertSame(0.0, $strict[1]->lowerBound);
        self::assertSame(1.0, $strict[1]->upperBound);
        self::assertFalse($strict[1]->includesLowerBound);
        self::assertTrue($strict[1]->includesUpperBound);
        self::assertCount(1, $inclusive);
        self::assertSame(-1.0, $inclusive[0]->lowerBound);
        self::assertSame(1.0, $inclusive[0]->upperBound);
        self::assertTrue($inclusive[0]->includesLowerBound);
        self::assertTrue($inclusive[0]->includesUpperBound);
        self::assertCount(2, $aboveOne);
        self::assertLessThan(0.0, $aboveOne[0]->upperBound);
        self::assertGreaterThan(0.0, $aboveOne[1]->lowerBound);
    }

    public function testLambertWRealBranchDomainsPreserveEndpointInclusion(): void
    {
        $principal = Math::solveInequality(
            'lambertw(x) >= -1',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $principal);
        self::assertEqualsWithDelta(-1.0 / \M_E, $principal[0]->lowerBound, 1.0E-10);
        self::assertTrue($principal[0]->includesLowerBound);
        self::assertSame(1.0, $principal[0]->upperBound);

        $lowerBranch = Math::solveInequality(
            'lambertw(x, -1) <= -1',
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $lowerBranch);
        self::assertEqualsWithDelta(-1.0 / \M_E, $lowerBranch[0]->lowerBound, 1.0E-10);
        self::assertTrue($lowerBranch[0]->includesLowerBound);
        self::assertSame(0.0, $lowerBranch[0]->upperBound);
        self::assertFalse($lowerBranch[0]->includesUpperBound);
    }

    public function testVariableLambertWBranchPreimagesAreNotMissedBetweenSamples(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $principalBranch = Math::solveInequality(
            'lambertw(-0.1, x - sqrt(2)) > -1',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );
        $lowerBranch = Math::solveInequality(
            'lambertw(-0.1, x - sqrt(2)) < -1',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );

        self::assertCount(1, $principalBranch);
        self::assertEqualsWithDelta(\sqrt(2.0), $principalBranch[0]->lowerBound, 1.0E-8);
        self::assertSame($principalBranch[0]->lowerBound, $principalBranch[0]->upperBound);
        self::assertTrue($principalBranch[0]->includesLowerBound);
        self::assertTrue($principalBranch[0]->includesUpperBound);
        self::assertCount(1, $lowerBranch);
        self::assertEqualsWithDelta(\sqrt(2.0) - 1.0, $lowerBranch[0]->lowerBound, 1.0E-8);
        self::assertSame($lowerBranch[0]->lowerBound, $lowerBranch[0]->upperBound);
        self::assertTrue($lowerBranch[0]->includesLowerBound);
        self::assertTrue($lowerBranch[0]->includesUpperBound);
    }

    public function testIdentityClassifiesTheWholeBoundedInterval(): void
    {
        $solutions = Math::solveInequality('x <= x', 'x', -2.0, 3.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(3.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testFixedParameterCanDefineTheBoundary(): void
    {
        $solutions = Math::solveInequality(
            'x <= limit',
            'x',
            -2.0,
            2.0,
            ['limit' => 0.5],
        );

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(0.5, $solutions[0]->upperBound, 1.0E-10);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testChainedComparisonIsTheConjunctionOfAdjacentComparisons(): void
    {
        $solutions = Math::solveInequality('0 < x < 1', 'x', -1.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertFalse($solutions[0]->contains(0.0));
        self::assertTrue($solutions[0]->contains(0.5));
        self::assertFalse($solutions[0]->contains(1.0));
    }

    /** @param list<\MathPHP\Solver\InequalityInterval> $solutions */
    private function containsPoint(array $solutions, float $point): bool
    {
        foreach ($solutions as $solution) {
            if ($solution->contains($point)) {
                return true;
            }
        }

        return false;
    }
}
