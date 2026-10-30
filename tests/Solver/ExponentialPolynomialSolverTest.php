<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Solver\ExponentialPolynomialSolver;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class ExponentialPolynomialSolverTest extends TestCase
{
    public function testRecentersTermsBeforeScalingOnAnOffsetWideInterval(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0, 'slope' => 1000.0, 'offset' => 0.0],
                ['coefficient' => -1.0, 'slope' => 0.0, 'offset' => 1000.0],
            ],
            0.9,
            1.1,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(1.0, $roots[0], 1.0E-12);
    }

    public function testSubdividesIntervalsWhenTheRootIsNearAnEndpoint(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0, 'slope' => 1000.0, 'offset' => 0.0],
                ['coefficient' => -1.0, 'slope' => 0.0, 'offset' => 1990.0],
            ],
            0.0,
            2.0,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(1.99, $roots[0], 1.0E-12);
    }

    public function testAdaptiveSubdividingFindsRootsBeyondTheFormerSegmentLimit(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0, 'slope' => 1000000.0, 'offset' => 0.0],
                ['coefficient' => -1.0, 'slope' => 0.0, 'offset' => 1999000.0],
            ],
            0.0,
            2.0,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(1.999, $roots[0], 1.0E-12);
    }

    public function testRecursiveDerivativesNormalizeLargeSlopeProducts(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0, 'slope' => 0.0, 'offset' => 0.0],
                ['coefficient' => -1.0, 'slope' => 1.0E200, 'offset' => 0.0],
                ['coefficient' => 1.0, 'slope' => 1.5E200, 'offset' => 0.0],
                ['coefficient' => -1.0, 'slope' => 1.7E200, 'offset' => 0.0],
            ],
            -1.0E-200,
            1.0E-200,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertNotEmpty($roots);
    }

    public function testZeroExponentialSumIsNotMisclassifiedAsRootFree(): void
    {
        $solver = new ExponentialPolynomialSolver();
        $terms = [
            ['coefficient' => 0.0, 'slope' => 0.0, 'offset' => 0.0],
            ['coefficient' => 0.0, 'slope' => 1.0, 'offset' => 0.0],
        ];
        try {
            $solver->solve(
                $terms,
                -1.0,
                1.0,
                new SolverOptions(),
            );
            self::fail('An identically zero exponential polynomial has infinitely many roots.');
        } catch (\MathPHP\Solver\InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        try {
            $solver->solve([], -1.0, 1.0, new SolverOptions());
            self::fail('The empty exponential sum is identically zero.');
        } catch (\MathPHP\Solver\InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        $singleton = $solver->solve(
            $terms,
            2.0,
            2.0,
            new SolverOptions(),
        );

        self::assertSame([2.0], $singleton);
    }

    public function testIdenticallyCancellingNonzeroTermsHaveInfiniteSolutions(): void
    {
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);

        (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 3.0, 'slope' => 2.0, 'offset' => 4.0],
                ['coefficient' => -3.0, 'slope' => 2.0, 'offset' => 4.0],
            ],
            -1.0,
            1.0,
            new SolverOptions(),
        );
    }

    public function testIdenticallyCancellingTermsHaveOneSolutionOnSingletonInterval(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 3.0, 'slope' => 2.0, 'offset' => 4.0],
                ['coefficient' => -3.0, 'slope' => 2.0, 'offset' => 4.0],
            ],
            0.25,
            0.25,
            new SolverOptions(),
        );

        self::assertSame([0.25], $roots);
    }

    public function testCommensurateSlopesReduceToPolynomialRoots(): void
    {
        $solver = new ExponentialPolynomialSolver();
        $terms = [
            ['coefficient' => 2.0, 'slope' => -2.0, 'offset' => 0.0],
            ['coefficient' => -5.0, 'slope' => -1.0, 'offset' => 0.0],
            ['coefficient' => 3.0, 'slope' => 0.0, 'offset' => 0.0],
        ];

        $roots = $solver->solve($terms, -2.0, 2.0, new SolverOptions());

        self::assertNotNull($roots);
        self::assertEqualsWithDelta([\log(2.0 / 3.0), 0.0], $roots, 1.0E-9);
        self::assertSame([], $solver->solve($terms, 0.1, 2.0, new SolverOptions()));
    }

    public function testCommensurateReductionRetainsRepeatedRootsAndOffsets(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0, 'slope' => 0.0, 'offset' => \log(4.0)],
                ['coefficient' => -8.0, 'slope' => 1.0, 'offset' => 0.0],
                ['coefficient' => 4.0, 'slope' => 2.0, 'offset' => 0.0],
            ],
            -2.0,
            2.0,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(0.0, $roots[0], 1.0E-9);
    }

    public function testIntegerSlopeGcdFindsPolynomialStepAcrossMultipleGaps(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 4.0, 'slope' => 0.0, 'offset' => 0.0],
                ['coefficient' => -5.0, 'slope' => 2.0, 'offset' => 0.0],
                ['coefficient' => 1.0, 'slope' => 5.0, 'offset' => 0.0],
            ],
            -0.1,
            0.1,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(0.0, $roots[0], 1.0E-10);
    }

    public function testCommensurateReductionNormalizesOffsetsBeforeExponentiating(): void
    {
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 2.0, 'slope' => -2.0, 'offset' => 710.0],
                ['coefficient' => -5.0, 'slope' => -1.0, 'offset' => 710.0],
                ['coefficient' => 3.0, 'slope' => 0.0, 'offset' => 710.0],
            ],
            -2.0,
            2.0,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertEqualsWithDelta([\log(2.0 / 3.0), 0.0], $roots, 1.0E-9);
    }

    public function testSingletonIntervalRetainsExactRootBeforeExponentialCoordinateMapping(): void
    {
        $terms = [];
        foreach ([[4.0, 0.0], [-5.0, 2.0], [1.0, 5.0]] as [$coefficient, $integerSlope]) {
            $slope = $integerSlope / 2.0;
            $terms[] = [
                'coefficient' => $coefficient,
                'slope' => $slope,
                'offset' => -($slope * 0.1),
            ];
        }
        $roots = (new ExponentialPolynomialSolver())->solve(
            $terms,
            0.1,
            0.1,
            new SolverOptions(),
        );

        self::assertSame([0.1], $roots);
    }

    public function testCommensurateReductionDoesNotDuplicateMappedEndpointRoots(): void
    {
        $terms = [];
        foreach ([[4.0, 0.0], [-5.0, 2.0], [1.0, 5.0]] as [$coefficient, $integerSlope]) {
            $slope = $integerSlope / 2.0;
            $terms[] = [
                'coefficient' => $coefficient,
                'slope' => $slope,
                'offset' => -($slope * 0.1),
            ];
        }
        $roots = (new ExponentialPolynomialSolver())->solve(
            $terms,
            0.1,
            0.2,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertEqualsWithDelta(0.1, $roots[0], 1.0E-12);
    }

    public function testUncertainSameSlopeCancellationFallsBackWithoutLosingRoots(): void
    {
        $smallConstant = 1.0E-16;
        $smallPolynomialRoot = (2.0 * $smallConstant)
            / (2.0 + \sqrt(4.0 - (4.0 * $smallConstant)));
        $roots = (new ExponentialPolynomialSolver())->solve(
            [
                ['coefficient' => 1.0E16, 'slope' => 0.0, 'offset' => 0.0],
                ['coefficient' => 0.25, 'slope' => 0.0, 'offset' => \log(4.0)],
                ['coefficient' => -5.0E15, 'slope' => 0.0, 'offset' => \log(2.0)],
                ['coefficient' => -2.0E16, 'slope' => 1.0, 'offset' => 0.0],
                ['coefficient' => 1.0E16, 'slope' => 2.0, 'offset' => 0.0],
            ],
            -40.0,
            1.0,
            new SolverOptions(),
        );

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertEqualsWithDelta(\log($smallPolynomialRoot), $roots[0], 1.0E-8);
        self::assertEqualsWithDelta(\log(2.0), $roots[1], 1.0E-9);
    }
}
