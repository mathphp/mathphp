<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;
use MathPHP\Solver\InfiniteSolutionSetException;
use MathPHP\Solver\RootSearchLimitException;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class EquationSetSolverTest extends TestCase
{
    public function testPolynomialCannotMasqueradeAsNumericalIdentityAtProbePoints(): void
    {
        $probeRoots = [
            -1.0 + (2.0 * 0.4142135623730951),
            -1.0 + (2.0 * 0.7320508075688772),
        ];
        $equation = '(x-(' . \sprintf('%.17g', $probeRoots[0]) . '))'
            . '*(x+0.25)'
            . '*(x-(' . \sprintf('%.17g', $probeRoots[1]) . '))=0';
        $solutions = Math::solveEquationSet(
            $equation,
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(maxPolynomialDegree: 2, scanSteps: 1),
        );

        self::assertNotCount(1, $solutions);
        self::assertNotSame([-1.0, 1.0], [
            $solutions[0]->lowerBound ?? null,
            $solutions[0]->upperBound ?? null,
        ]);
    }

    public function testTranscendentalFactorCannotMasqueradeAsNumericalIdentityAtProbePoints(): void
    {
        $probeCount = 64;
        $roots = [-1.0, 1.0];
        for ($index = 1; $index < $probeCount; ++$index) {
            $roots[] = -1.0 + (2.0 * ($index / $probeCount));
        }
        $roots[] = -1.0 + (2.0 * 0.4142135623730951);
        $roots[] = -1.0 + (2.0 * 0.7320508075688772);
        $factors = \array_map(
            static fn (float $root): string => '(x-(' . \sprintf('%.17g', $root) . '))',
            $roots,
        );
        $equation = 'sin(x)*' . \implode('*', $factors) . '=0';
        $solutions = Math::solveEquationSet(
            $equation,
            'x',
            -1.0,
            1.0,
            solverOptions: new SolverOptions(maxPolynomialDegree: 2, scanSteps: 1),
        );

        self::assertNotSame(1, \count($solutions));
        self::assertNotSame([-1.0, 1.0], [
            $solutions[0]->lowerBound ?? null,
            $solutions[0]->upperBound ?? null,
        ]);
    }

    public function testImplicitVariableProductIsNotTreatedAsVariableIndependent(): void
    {
        $solutions = Math::solveEquationSet('x(2) = 4', 'x', -4.0, 4.0);

        self::assertCount(1, $solutions);
        self::assertSame(2.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
    }

    public function testSignEqualitiesReduceToZeroSetsAndStrictSignIntervals(): void
    {
        $zeros = Math::solveEquationSet('sign(sin(x)) = 0', 'x', -5.0, 5.0);

        self::assertSame([-\M_PI, 0.0, \M_PI], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $zeros,
        ));
        foreach ($zeros as $interval) {
            self::assertSame($interval->lowerBound, $interval->upperBound);
            self::assertTrue($interval->includesLowerBound);
            self::assertTrue($interval->includesUpperBound);
        }

        $positive = Math::solveEquationSet('sign(x^2 - 1) = 1.0', 'x', -2.0, 2.0);
        self::assertCount(2, $positive);
        self::assertSame([-2.0, 1.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $positive,
        ));
        self::assertFalse($positive[0]->includesUpperBound);
        self::assertFalse($positive[1]->includesLowerBound);

        self::assertSame(
            [],
            Math::solveEquationSet('sign(sin(x)) = 2', 'x', -5.0, 5.0),
        );
    }

    public function testZeroBasePowerIntervalsExcludeZeroExponentBoundaries(): void
    {
        $options = new SolverOptions(scanSteps: 1);
        $zeroSolutions = Math::solveEquationSet(
            '0^(sin(x)) = 0',
            'x',
            -4.0,
            4.0,
            solverOptions: $options,
        );

        self::assertCount(2, $zeroSolutions);
        self::assertSame([-4.0, 0.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $zeroSolutions,
        ));
        self::assertSame([-\M_PI, \M_PI], \array_map(
            static fn ($interval): float => $interval->upperBound,
            $zeroSolutions,
        ));
        self::assertFalse($zeroSolutions[0]->includesUpperBound);
        self::assertFalse($zeroSolutions[1]->includesLowerBound);
        self::assertFalse($zeroSolutions[1]->includesUpperBound);

        $unitSolutions = Math::solveEquationSet(
            '0^(sin(x)) = 1',
            'x',
            -4.0,
            4.0,
            solverOptions: $options,
        );
        self::assertSame([-\M_PI, 0.0, \M_PI], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $unitSolutions,
        ));

        $split = Math::solveEquationSet(
            '0^((x - 1)^2) = 0',
            'x',
            -2.0,
            3.0,
            solverOptions: $options,
        );
        self::assertSame([-2.0, 1.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $split,
        ));
        self::assertSame([1.0, 3.0], \array_map(
            static fn ($interval): float => $interval->upperBound,
            $split,
        ));
        self::assertFalse($split[0]->includesUpperBound);
        self::assertFalse($split[1]->includesLowerBound);

        foreach (
            [
                '(-0)^(sin(x)) = 0',
                '(0)^(sin(x)) = 0',
                '0.0^(sin(x)) = 0',
                '(0 / 1)^(sin(x)) = 0',
            ] as $equation
        ) {
            $equivalent = Math::solveEquationSet(
                $equation,
                'x',
                -4.0,
                4.0,
                solverOptions: $options,
            );
            self::assertCount(\count($zeroSolutions), $equivalent, $equation);
            foreach ($zeroSolutions as $index => $interval) {
                self::assertEquals($interval->lowerBound, $equivalent[$index]->lowerBound, $equation);
                self::assertEquals($interval->upperBound, $equivalent[$index]->upperBound, $equation);
                self::assertSame($interval->includesLowerBound, $equivalent[$index]->includesLowerBound);
                self::assertSame($interval->includesUpperBound, $equivalent[$index]->includesUpperBound);
            }
        }
        self::assertSame(
            [],
            Math::solveEquationSet(
                '0^(sin(x)) = 2',
                'x',
                -4.0,
                4.0,
                solverOptions: $options,
            ),
        );

        self::expectException(InfiniteSolutionSetException::class);
        Math::solveEquationSet(
            '0^(x - x) = 1',
            'x',
            -2.0,
            2.0,
            solverOptions: $options,
        );
    }

    public function testNestedAbsoluteMinimumAndMaximumEqualitiesDeduplicateBoundaryRoots(): void
    {
        $minimum = Math::solveEquationSet('abs(min(x, 1)) = 1', 'x', -4.0, 4.0);

        self::assertCount(2, $minimum);
        self::assertSame(-1.0, $minimum[0]->lowerBound);
        self::assertSame(-1.0, $minimum[0]->upperBound);
        self::assertTrue($minimum[0]->includesLowerBound);
        self::assertTrue($minimum[0]->includesUpperBound);
        self::assertSame(1.0, $minimum[1]->lowerBound);
        self::assertSame(4.0, $minimum[1]->upperBound);
        self::assertTrue($minimum[1]->includesLowerBound);
        self::assertTrue($minimum[1]->includesUpperBound);

        $maximum = Math::solveEquationSet('abs(max(x, -1)) = 1', 'x', -4.0, 4.0);

        self::assertCount(2, $maximum);
        self::assertSame(-4.0, $maximum[0]->lowerBound);
        self::assertSame(-1.0, $maximum[0]->upperBound);
        self::assertTrue($maximum[0]->includesLowerBound);
        self::assertTrue($maximum[0]->includesUpperBound);
        self::assertSame(1.0, $maximum[1]->lowerBound);
        self::assertSame(1.0, $maximum[1]->upperBound);
        self::assertTrue($maximum[1]->includesLowerBound);
        self::assertTrue($maximum[1]->includesUpperBound);
    }

    public function testAbsoluteValueIdentityInMinimumUsesItsActualDomainWithCoarseScan(): void
    {
        foreach (
            [
                ['min(abs(x), 1) = x', -2.0, 2.0, [[0.0, 1.0]]],
                ['min(abs(2*x - 1), 4) = 1 - 2*x', -3.0, 3.0, [[-1.5, 0.5]]],
                ['min(abs(x^2 - 1), 3) = 1 - x^2', -3.0, 3.0, [[-1.0, 1.0]]],
                ['min(abs(x + 1), 4) = -x - 1', -5.0, 5.0, [[-5.0, -1.0]]],
                [
                    'min(abs((x + 1)/(x - 2)), 10) = -(x + 1)/(x - 2)',
                    -5.0,
                    5.0,
                    [[-1.0, 19.0 / 11.0]],
                ],
            ] as [$equation, $lowerBound, $upperBound, $expectedIntervals]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                $lowerBound,
                $upperBound,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
            );

            self::assertCount(\count($expectedIntervals), $solutions, $equation);
            foreach ($expectedIntervals as $index => [$expectedLower, $expectedUpper]) {
                self::assertSame($expectedLower, $solutions[$index]->lowerBound, $equation);
                self::assertSame($expectedUpper, $solutions[$index]->upperBound, $equation);
                self::assertTrue($solutions[$index]->includesLowerBound, $equation);
                self::assertTrue($solutions[$index]->includesUpperBound, $equation);
            }
        }
    }

    public function testAbsoluteValueRationalIdentityPreservesRemovablePoles(): void
    {
        foreach (
            [
                [
                    'min(abs((x - 1)^2/(x - 1)), 10) = 1 - x',
                    [],
                ],
                [
                    'min(abs((x - a)^2/(x - a)), 10) = a - x',
                    ['a' => 1.0],
                ],
            ] as [$equation, $parameters]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                -12.0,
                12.0,
                $parameters,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
            );

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-9.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(1.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertFalse($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testPositiveRationalAbsoluteValueIdentityUsesItsSignDomain(): void
    {
        foreach (
            [
                [
                    'min(abs((x^2 - 1)/(x - 1)), 10) = x + 1',
                    -2.0,
                    12.0,
                    [],
                    [[-1.0, 1.0, true, false], [1.0, 9.0, false, true]],
                ],
                [
                    'min(abs((x - a)^2/(x - a)), 10) = x - a',
                    -2.0,
                    12.0,
                    ['a' => 1.0],
                    [[1.0, 11.0, false, true]],
                ],
            ] as [$equation, $lowerBound, $upperBound, $parameters, $expectedBounds]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                $lowerBound,
                $upperBound,
                $parameters,
                solverOptions: new SolverOptions(scanSteps: 1),
            );

            self::assertCount(\count($expectedBounds), $solutions, $equation);
            foreach ($expectedBounds as $index => [$expectedLower, $expectedUpper, $includeLower, $includeUpper]) {
                self::assertSame($expectedLower, $solutions[$index]->lowerBound, $equation);
                self::assertSame($expectedUpper, $solutions[$index]->upperBound, $equation);
                self::assertSame($includeLower, $solutions[$index]->includesLowerBound, $equation);
                self::assertSame($includeUpper, $solutions[$index]->includesUpperBound, $equation);
            }
        }
    }

    public function testBetaEquationSetDoesNotDuplicateRoundedIntegerSumRoots(): void
    {
        $solutions = Math::solveEquationSet(
            'beta(x, -100.5) = 0',
            'x',
            -75.0,
            -70.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
        );

        self::assertCount(5, $solutions);
        foreach ([-74.5, -73.5, -72.5, -71.5, -70.5] as $index => $point) {
            self::assertSame($point, $solutions[$index]->lowerBound);
            self::assertSame($point, $solutions[$index]->upperBound);
        }
    }

    public function testBesselJEquationSetContainsEveryZeroInTheInterval(): void
    {
        $solutions = Math::solveEquationSet('besselj(0, x) = 0', 'x', -8.0, 8.0);

        self::assertCount(4, $solutions);
        foreach ([-5.520078110283066, -2.404825557696313, 2.404825557696313, 5.520078110283066] as $index => $root) {
            self::assertEqualsWithDelta($root, $solutions[$index]->lowerBound, 1.0E-9);
            self::assertSame($solutions[$index]->lowerBound, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testDoubleFactorialEquationSetFindsItsPlateau(): void
    {
        $solutions = Math::solveEquationSet('floor(x)!! = 15', 'x', 5.0, 6.0);

        self::assertCount(1, $solutions);
        self::assertSame(5.0, $solutions[0]->lowerBound);
        self::assertSame(6.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testModifiedBesselITangentEquationSetIsOnePoint(): void
    {
        $solutions = Math::solveEquationSet('besseli(0, x) = 1', 'x', -8.0, 8.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testFactorialEquationSetsHandleIsolatedAndSelectorPlateauSolutions(): void
    {
        $options = new \MathPHP\Solver\SolverOptions(scanSteps: 1);
        $isolated = Math::solveEquationSet(
            'x! = 6',
            'x',
            -1.0,
            6.0,
            solverOptions: $options,
        );
        self::assertCount(1, $isolated);
        self::assertSame(3.0, $isolated[0]->lowerBound);
        self::assertSame(3.0, $isolated[0]->upperBound);

        $nested = Math::solveEquationSet(
            '(x!)! = 2',
            'x',
            -1.0,
            6.0,
            solverOptions: $options,
        );
        self::assertCount(1, $nested);
        self::assertSame(2.0, $nested[0]->lowerBound);
        self::assertSame(2.0, $nested[0]->upperBound);

        $nestedPolynomial = Math::solveEquationSet(
            '((x^2)!)! = 2',
            'x',
            -3.0,
            3.0,
            solverOptions: $options,
        );
        self::assertCount(2, $nestedPolynomial);
        self::assertEqualsWithDelta(-\sqrt(2.0), $nestedPolynomial[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(2.0), $nestedPolynomial[1]->lowerBound, 1.0E-10);

        self::assertSame(
            [],
            Math::solveEquationSet(
                '((x + 0.5)!)! = 1',
                'x',
                0.0,
                0.1,
                solverOptions: $options,
            ),
        );

        $cases = [
            ['floor(x)! = 2', 2.0, 3.0, true, false],
            ['ceil(x)! = 2', 1.0, 2.0, false, true],
            ['round(x)! = 2', 1.5, 2.5, true, false],
        ];
        foreach ($cases as [$equation, $lower, $upper, $includesLower, $includesUpper]) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                -1.0,
                6.0,
                solverOptions: $options,
            );

            self::assertCount(1, $solutions, $equation);
            self::assertSame($lower, $solutions[0]->lowerBound, $equation);
            self::assertSame($upper, $solutions[0]->upperBound, $equation);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound, $equation);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testErfiAndDawsonRoundingSelectorsFindNarrowPlateaus(): void
    {
        $erfiSolutions = Math::solveEquationSet(
            'floor(erfi(x)) = 1',
            'x',
            0.0,
            2.0,
        );

        self::assertCount(1, $erfiSolutions);
        self::assertEqualsWithDelta(0.7316971534684924, $erfiSolutions[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(1.102328258998344, $erfiSolutions[0]->upperBound, 1.0E-10);
        self::assertTrue($erfiSolutions[0]->includesLowerBound);
        self::assertFalse($erfiSolutions[0]->includesUpperBound);

        $dawsonBoundaryRoots = Math::solve('100 * dawson(x) = 54', 'x', 0.0, 2.0);
        $dawsonSolutions = Math::solveEquationSet(
            'floor(100 * dawson(x)) = 54',
            'x',
            0.0,
            2.0,
        );

        self::assertCount(2, $dawsonBoundaryRoots);
        self::assertCount(1, $dawsonSolutions);
        self::assertEqualsWithDelta($dawsonBoundaryRoots[0]->value, $dawsonSolutions[0]->lowerBound, 1.0E-9);
        self::assertEqualsWithDelta($dawsonBoundaryRoots[1]->value, $dawsonSolutions[0]->upperBound, 1.0E-9);
        self::assertTrue($dawsonSolutions[0]->includesLowerBound);
        self::assertTrue($dawsonSolutions[0]->includesUpperBound);
    }

    public function testRoundedBesselJEquationEnumeratesEveryLevelWithSparseScan(): void
    {
        $solutions = Math::solveEquationSet(
            'floor(besselj(0, 100 * x)) = 0',
            'x',
            0.0,
            1.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(17, $solutions);
        self::assertFalse($solutions[0]->includesLowerBound);
        foreach ($solutions as $interval) {
            $sample = ($interval->lowerBound / 2.0) + ($interval->upperBound / 2.0);
            self::assertSame(
                0.0,
                Math::evaluate('floor(besselj(0, 100 * x))', ['x' => $sample]),
            );
        }
    }

    public function testRoundedModifiedBesselIEquationEnumeratesEveryLevelWithSparseScan(): void
    {
        $boundary = Math::solve('besseli(0, x) = 2', 'x', 0.0, 5.0);
        $solutions = Math::solveEquationSet(
            'floor(besseli(0, x)) = 1',
            'x',
            -5.0,
            5.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $boundary);
        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(-$boundary[0]->value, $solutions[0]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta($boundary[0]->value, $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testRoundedOddOrderModifiedBesselIFindsNegativePlateauWithSparseScan(): void
    {
        $lower = Math::solve('10 * besseli(1, x) = -5', 'x', -5.0, 0.0);
        $upper = Math::solve('10 * besseli(1, x) = -4', 'x', -5.0, 0.0);
        $solutions = Math::solveEquationSet(
            'floor(10 * besseli(1, x)) = -5',
            'x',
            -5.0,
            0.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $lower);
        self::assertCount(1, $upper);
        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta($lower[0]->value, $solutions[0]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta($upper[0]->value, $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testRoundedNegativeEvenOrderModifiedBesselIUsesMinimumAtZero(): void
    {
        $boundary = Math::solve('10 * besseli(2, x) = 1', 'x', 0.0, 4.0);
        $solutions = Math::solveEquationSet(
            'floor(10 * besseli(-2, x)) = 0',
            'x',
            -4.0,
            4.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $boundary);
        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(-$boundary[0]->value, $solutions[0]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta($boundary[0]->value, $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testVariableIntegerSpecialFunctionSelectorsPreservePlateauIntervals(): void
    {
        $besselTarget = \sprintf('%.17g', Math::evaluate('besseli(0, 1)'));
        $besselYTarget = \sprintf('%.17g', Math::evaluate('bessely(0, 1)'));
        $cases = [
            ['besseli(floor(x), 1) = ' . $besselTarget, 0.0, 1.0],
            ['bessely(floor(x), 1) = ' . $besselYTarget, 0.0, 1.0],
            ['polygamma(floor(x), 1) = -0.5772156649015329', 0.0, 1.0],
            ['lambertw(-0.1, floor(x)) = -3.577152063957297', -1.0, 0.0],
        ];

        foreach ($cases as [$equation, $lowerBound, $upperBound]) {
            $solutions = Math::solveEquationSet($equation, 'x', -1.0, 2.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame($lowerBound, $solutions[0]->lowerBound, $equation);
            self::assertSame($upperBound, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertFalse($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testVariableIntegerSpecialFunctionSelectorsRetainIsolatedRoots(): void
    {
        $solutions = Math::solveEquationSet('besseli(floor(x), x) = 1', 'x', -1.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(0.0, $solutions[0]->lowerBound, 1.0E-12);
        self::assertSame($solutions[0]->lowerBound, $solutions[0]->upperBound);
        self::assertEqualsWithDelta(1.51834710123469, $solutions[1]->lowerBound, 1.0E-8);
        self::assertSame($solutions[1]->lowerBound, $solutions[1]->upperBound);
    }

    public function testBesselYVariableOrderSelectorFindsHighOrderPlateauWithSparseScan(): void
    {
        $target = \sprintf('%.17g', Math::evaluate('bessely(40, 1)'));
        $solutions = Math::solveEquationSet(
            'bessely(floor(x^2), 1) = ' . $target,
            'x',
            0.0,
            7.0,
            solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(\sqrt(40.0), $solutions[0]->lowerBound, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(41.0), $solutions[0]->upperBound, 1.0E-10);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testBesselEquationSetUsesExpandedOrderRange(): void
    {
        $target = \sprintf('%.17g', Math::evaluate('besselj(256, 300)'));
        $solutions = Math::solveEquationSet(
            'besselj(floor(x), 300) = ' . $target,
            'x',
            256.0,
            257.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(256.0, $solutions[0]->lowerBound);
        self::assertSame(257.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testPolygammaEquationSetUsesExpandedOrderRange(): void
    {
        $target = \sprintf('%.17g', Math::evaluate('polygamma(128, 1)'));
        $solutions = Math::solveEquationSet(
            'polygamma(floor(x), 1) = ' . $target,
            'x',
            128.0,
            129.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(128.0, $solutions[0]->lowerBound);
        self::assertSame(129.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testFloorModuloEquationReturnsHalfOpenSolutionIntervals(): void
    {
        $solutions = Math::solveEquationSet('floor(x) % 2 = 1', 'x', -4.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(3.0, $solutions[1]->lowerBound);
        self::assertSame(4.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testTruncModuloEquationsPreserveSignedRemainderIntervals(): void
    {
        $positive = Math::solveEquationSet('trunc(x) % 2 = 1', 'x', -4.0, 4.0);
        $negative = Math::solveEquationSet('trunc(x) % 2 = -1', 'x', -4.0, 4.0);

        self::assertCount(2, $positive);
        self::assertSame([1.0, 3.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $positive,
        ));
        self::assertSame([2.0, 4.0], \array_map(
            static fn ($interval): float => $interval->upperBound,
            $positive,
        ));
        self::assertCount(2, $negative);
        self::assertSame([-4.0, -2.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $negative,
        ));
        self::assertSame([-3.0, -1.0], \array_map(
            static fn ($interval): float => $interval->upperBound,
            $negative,
        ));
        foreach ($positive as $interval) {
            self::assertTrue($interval->includesLowerBound);
            self::assertFalse($interval->includesUpperBound);
        }
        foreach ($negative as $interval) {
            self::assertFalse($interval->includesLowerBound);
            self::assertTrue($interval->includesUpperBound);
        }
    }

    public function testTruncLevelSetsUseSignDependentHalfOpenIntervals(): void
    {
        $cases = [
            ['trunc(x)=0', -1.0, 1.0, false, false, -2.0, 2.0],
            ['trunc(x)=-2', -3.0, -2.0, false, true, -4.0, 0.0],
            ['trunc(x)=2', 2.0, 3.0, true, false, 0.0, 4.0],
        ];

        foreach ($cases as [$equation, $lower, $upper, $includesLower, $includesUpper, $rangeLower, $rangeUpper]) {
            $solutions = Math::solveEquationSet($equation, 'x', $rangeLower, $rangeUpper);

            self::assertCount(1, $solutions, $equation);
            self::assertSame($lower, $solutions[0]->lowerBound, $equation);
            self::assertSame($upper, $solutions[0]->upperBound, $equation);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound, $equation);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound, $equation);
        }

        $gcdSolutions = Math::solveEquationSet('gcd(trunc(x),6)=2', 'x', -4.0, 4.0);
        self::assertCount(4, $gcdSolutions);
        self::assertSame([-4.0, -3.0, 2.0, 4.0], \array_map(
            static fn ($interval): float => $interval->lowerBound,
            $gcdSolutions,
        ));
    }

    public function testTruncOfPeriodicArgumentDoesNotBridgeIsolatedJumpPoints(): void
    {
        $solutions = Math::solveEquationSet('trunc(sin(x))=0', 'x', -3.0, 3.0);

        self::assertCount(3, $solutions);
        self::assertSame(-3.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(-\M_PI / 2.0, $solutions[0]->upperBound, 1.0E-12);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(-\M_PI / 2.0, $solutions[1]->lowerBound, 1.0E-12);
        self::assertEqualsWithDelta(\M_PI / 2.0, $solutions[1]->upperBound, 1.0E-12);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
        self::assertEqualsWithDelta(\M_PI / 2.0, $solutions[2]->lowerBound, 1.0E-12);
        self::assertSame(3.0, $solutions[2]->upperBound);
        self::assertFalse($solutions[2]->includesLowerBound);
        self::assertTrue($solutions[2]->includesUpperBound);
    }

    public function testSmoothModuloEqualitiesReturnTheirIsolatedRootsAsPoints(): void
    {
        $cases = [
            'zero remainder' => ['sin(x) % 2 = 0', [-\M_PI, 0.0, \M_PI], -5.0, 5.0],
            'nonzero remainder' => [
                'sin(x) % 2 = 1',
                [-3.0 * \M_PI / 2.0, \M_PI / 2.0],
                -5.0,
                5.0,
            ],
            'nonperiodic operand' => [
                'cosh(x) % 2 = 0',
                [-\acosh(2.0), \acosh(2.0)],
                -2.0,
                2.0,
            ],
        ];

        foreach ($cases as $label => [$equation, $expected, $lowerBound, $upperBound]) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                $lowerBound,
                $upperBound,
                solverOptions: new \MathPHP\Solver\SolverOptions(scanSteps: 1),
            );

            self::assertCount(\count($expected), $solutions, $label);
            foreach ($expected as $index => $root) {
                self::assertSame($root, $solutions[$index]->lowerBound, $label);
                self::assertSame($root, $solutions[$index]->upperBound, $label);
                self::assertTrue($solutions[$index]->includesLowerBound, $label);
                self::assertTrue($solutions[$index]->includesUpperBound, $label);
            }
        }
    }

    public function testConstantSmoothModuloEqualityReturnsTheWholeInterval(): void
    {
        foreach (['sin(0*x) % 2 = 0', '0 = sin(0*x) % 2'] as $equation) {
            $solutions = Math::solveEquationSet($equation, 'x', -5.0, 5.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-5.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(5.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }

        self::assertSame([], Math::solveEquationSet('sin(0*x) % 2 = 1', 'x', -5.0, 5.0));
    }

    public function testRoundModuloEquationRetainsRoundHalfAwayFromZeroBoundaries(): void
    {
        $solutions = Math::solveEquationSet('round(x) % 2 = 1', 'x', -4.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(0.5, $solutions[0]->lowerBound);
        self::assertSame(1.5, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(2.5, $solutions[1]->lowerBound);
        self::assertSame(3.5, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testNegativeFloorModuloUsesRemainderSignOfDividend(): void
    {
        $solutions = Math::solveEquationSet('floor(x) % 2 = -1', 'x', -4.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(-3.0, $solutions[0]->lowerBound);
        self::assertSame(-2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(-1.0, $solutions[1]->lowerBound);
        self::assertSame(0.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testPolynomialFloorModuloIncludesInteriorSelectorExtrema(): void
    {
        $solutions = Math::solveEquationSet('floor(x^2) % 2 = 1', 'x', -2.0, 2.0);

        self::assertCount(4, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(-\sqrt(3.0), $solutions[0]->upperBound, 1.0E-9);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(-\sqrt(2.0), $solutions[1]->lowerBound, 1.0E-9);
        self::assertSame(-1.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
        self::assertSame(1.0, $solutions[2]->lowerBound);
        self::assertEqualsWithDelta(\sqrt(2.0), $solutions[2]->upperBound, 1.0E-9);
        self::assertTrue($solutions[2]->includesLowerBound);
        self::assertFalse($solutions[2]->includesUpperBound);
        self::assertEqualsWithDelta(\sqrt(3.0), $solutions[3]->lowerBound, 1.0E-9);
        self::assertSame(2.0, $solutions[3]->upperBound);
        self::assertTrue($solutions[3]->includesLowerBound);
        self::assertFalse($solutions[3]->includesUpperBound);
    }

    public function testPiecewiseModuloOnRightSideRetainsItsSolutionIntervals(): void
    {
        $solutions = Math::solveEquationSet('1 = floor(x) % 2', 'x', -4.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(3.0, $solutions[1]->lowerBound);
        self::assertSame(4.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSetPartitionsTwoPiecewiseIntegerOperands(): void
    {
        $solutions = Math::solveEquationSet('floor(x) % floor(x + 1) = 1', 'x', -4.0, 4.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testModuloSetPartitionsMixedFloorAndRoundOperands(): void
    {
        $solutions = Math::solveEquationSet('floor(x) % round(x) = 1', 'x', -4.0, 4.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.5, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testNestedPiecewiseModuloPartitionsTheInnermostSelector(): void
    {
        $solutions = Math::solveEquationSet('floor(floor(x)) % 2 = 1', 'x', -4.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(3.0, $solutions[1]->lowerBound);
        self::assertSame(4.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testNestedPiecewiseModuloRepartitionsVariableDependentOuterSelector(): void
    {
        $solutions = Math::solveEquationSet(
            'floor(floor(x) + x / 2) % 2 = 1',
            'x',
            -4.0,
            4.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(3.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testModuloSetUsesConservativeRangeForTrigonometricSelector(): void
    {
        $solutions = Math::solveEquationSet('floor(sin(x)) % 2 = -1', 'x', -2.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testTrigonometricSelectorUsesOpenZeroCrossingBoundary(): void
    {
        $solutions = Math::solveEquationSet('floor(sin(x)) % 2 = -1', 'x', 0.1, 4.0);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(\M_PI, $solutions[0]->lowerBound, 1.0E-12);
        self::assertSame(4.0, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToSquareRootDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(sqrt(x)) % 2 = 1', 'x', -2.0, 4.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(4.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToInverseSineDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(asin(x)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(\sin(-1.0), $solutions[0]->upperBound, 1.0E-9);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(0.0, $solutions[1]->lowerBound);
        self::assertEqualsWithDelta(\sin(1.0), $solutions[1]->upperBound, 1.0E-9);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToInverseCosineDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(acos(x)) % 2 = 1', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(\cos(3.0), $solutions[0]->upperBound, 1.0E-9);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(\cos(2.0), $solutions[1]->lowerBound, 1.0E-9);
        self::assertEqualsWithDelta(\cos(1.0), $solutions[1]->upperBound, 1.0E-9);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToInverseHyperbolicCosineDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(acosh(x)) % 2 = 0', 'x', -2.0, 4.0);

        self::assertCount(2, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(\cosh(1.0), $solutions[0]->upperBound, 1.0E-9);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(\cosh(2.0), $solutions[1]->lowerBound, 1.0E-9);
        self::assertSame(4.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToInverseSecantDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(asec(x)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(1.0 / \cos(3.0), $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(1.0, $solutions[1]->lowerBound);
        self::assertEqualsWithDelta(1.0 / \cos(1.0), $solutions[1]->upperBound, 1.0E-8);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToBoundedInverseHyperbolicSecantDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(asech(x)) % 2 = 0', 'x', 0.1, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(0.1, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(1.0 / \cosh(2.0), $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(1.0 / \cosh(1.0), $solutions[1]->lowerBound, 1.0E-8);
        self::assertSame(1.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorRangeClipsToEvenRootDomain(): void
    {
        $solutions = Math::solveEquationSet('floor(root(x, 2)) % 2 = 1', 'x', -2.0, 8.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(4.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedInverseHyperbolicCosecantRange(): void
    {
        $solutions = Math::solveEquationSet('floor(acsch(x)) % 2 = -1', 'x', -2.0, -1.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(-1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedInverseHyperbolicCotangentRange(): void
    {
        $solutions = Math::solveEquationSet('floor(acoth(x)) % 2 = 0', 'x', 1.5, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(1.5, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedInverseCotangentRange(): void
    {
        $solutions = Math::solveEquationSet('floor(acot(x)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(1.0 / \tan(2.0), $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(1.0 / \tan(1.0), $solutions[1]->lowerBound, 1.0E-8);
        self::assertSame(2.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedLogOnePlusRange(): void
    {
        $solutions = Math::solveEquationSet('floor(log1p(x)) % 2 = 1', 'x', -0.9, 4.0);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(\M_E - 1.0, $solutions[0]->lowerBound, 1.0E-8);
        self::assertSame(4.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedAtanTwoRange(): void
    {
        $solutions = Math::solveEquationSet('floor(atan2(1, x)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(1.0 / \tan(2.0), $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(1.0 / \tan(1.0), $solutions[1]->lowerBound, 1.0E-8);
        self::assertSame(2.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedMinimumRange(): void
    {
        $solutions = Math::solveEquationSet('floor(min(x, 1)) % 2 = 0', 'x', -2.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(-1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(0.0, $solutions[1]->lowerBound);
        self::assertEqualsWithDelta(1.0, $solutions[1]->upperBound, 1.0E-8);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedMaximumRange(): void
    {
        $solutions = Math::solveEquationSet('floor(max(x, -1)) % 2 = 0', 'x', -2.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(2.0, $solutions[1]->lowerBound);
        self::assertSame(3.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedSignRange(): void
    {
        $solutions = Math::solveEquationSet('floor(sign(x)) % 2 = 0', 'x', -2.0, 3.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedTwoArgumentLogarithmRange(): void
    {
        $solutions = Math::solveEquationSet('floor(log(x, 2)) % 2 = 0', 'x', 0.5, 12.0);

        self::assertCount(2, $solutions);
        self::assertSame(1.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(4.0, $solutions[1]->lowerBound);
        self::assertSame(8.0, $solutions[1]->upperBound);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testVariableBaseLogarithmIdentityExcludesInvalidBaseAndArgumentDomain(): void
    {
        $solutions = Math::solveEquationSet('log(x^2, x) = 2', 'x', -3.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(1.0, $solutions[1]->lowerBound);
        self::assertSame(3.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesExactIntegerDomainForGcd(): void
    {
        $solutions = Math::solveEquationSet('floor(gcd(x, 6)) % 2 = 0', 'x', -6.0, 6.0);

        self::assertCount(7, $solutions);
        foreach ([-6.0, -4.0, -2.0, 0.0, 2.0, 4.0, 6.0] as $index => $root) {
            self::assertSame($root, $solutions[$index]->lowerBound);
            self::assertSame($root, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testModuloSelectorUsesExactIntegerDomainForLcm(): void
    {
        $solutions = Math::solveEquationSet('round(lcm(x, 3)) % 2 = 0', 'x', -6.0, 6.0);

        self::assertCount(7, $solutions);
        foreach ([-6.0, -4.0, -2.0, 0.0, 2.0, 4.0, 6.0] as $index => $root) {
            self::assertSame($root, $solutions[$index]->lowerBound);
            self::assertSame($root, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testModuloSelectorUsesExactIntegerDomainForPolynomialGcdArguments(): void
    {
        $solutions = Math::solveEquationSet('floor(gcd(x^2, 6)) % 2 = 0', 'x', -3.0, 3.0);

        $expectedRoots = [
            -\sqrt(8.0),
            -\sqrt(6.0),
            -2.0,
            -\sqrt(2.0),
            0.0,
            \sqrt(2.0),
            2.0,
            \sqrt(6.0),
            \sqrt(8.0),
        ];
        self::assertCount(\count($expectedRoots), $solutions);
        foreach ($expectedRoots as $index => $root) {
            self::assertEqualsWithDelta($root, $solutions[$index]->lowerBound, 1.0E-8);
            self::assertSame($solutions[$index]->lowerBound, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testModuloSelectorRetainsIntervalsForConstantGcdArguments(): void
    {
        $solutions = Math::solveEquationSet('floor(gcd(x - x, 6)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedPrincipalLambertWRange(): void
    {
        $solutions = Math::solveEquationSet(
            'floor(lambertw(x)) % 2 = 0',
            'x',
            -1.0 / \M_E,
            2.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(2.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedLowerLambertWBranchRange(): void
    {
        $solutions = Math::solveEquationSet(
            'floor(lambertw(x, -1)) % 2 = 0',
            'x',
            -1.0 / \M_E,
            -0.1,
        );

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(-1.0 / \M_E, $solutions[0]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta(-2.0 / (\M_E ** 2), $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(-3.0 / (\M_E ** 3), $solutions[1]->lowerBound, 1.0E-8);
        self::assertSame(-0.1, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testLowerLambertWModuloRejectsUnboundedIntegerLevelsNearZero(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'The modulo expression integer domain could not be bounded for the requested interval.',
        );

        Math::solveEquationSet(
            'floor(lambertw(x, -1)) % 2 = 0',
            'x',
            -0.1,
            0.0,
        );
    }

    public function testModuloSelectorUsesBoundedTangentRange(): void
    {
        $solutions = Math::solveEquationSet('floor(tan(x)) % 2 = 0', 'x', -0.5, 0.5);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.5, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorRejectsInfiniteBandsAccumulatingAtTangentPoles(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'The modulo expression integer domain could not be bounded for the requested interval.',
        );

        Math::solveEquationSet('floor(tan(x)) % 2 = 0', 'x', -2.0, 2.0);
    }

    public function testModuloSelectorUsesBoundedCotangentRange(): void
    {
        $solutions = Math::solveEquationSet('floor(cot(x)) % 2 = 0', 'x', 0.5, 1.5);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(\M_PI_4, $solutions[0]->lowerBound, 1.0E-8);
        self::assertSame(1.5, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedSecantRange(): void
    {
        $solutions = Math::solveEquationSet('floor(sec(x)) % 2 = 1', 'x', -0.5, 0.5);

        self::assertCount(1, $solutions);
        self::assertSame(-0.5, $solutions[0]->lowerBound);
        self::assertSame(0.5, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedCosecantRange(): void
    {
        $solutions = Math::solveEquationSet('floor(csc(x)) % 2 = 0', 'x', 0.5, 1.5);

        self::assertCount(1, $solutions);
        self::assertSame(0.5, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(\M_PI / 6.0, $solutions[0]->upperBound, 1.0E-8);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedHyperbolicCosineRange(): void
    {
        $solutions = Math::solveEquationSet('floor(cosh(x)) % 2 = 0', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(-\acosh(3.0), $solutions[0]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta(-\acosh(2.0), $solutions[0]->upperBound, 1.0E-8);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(\acosh(2.0), $solutions[1]->lowerBound, 1.0E-8);
        self::assertEqualsWithDelta(\acosh(3.0), $solutions[1]->upperBound, 1.0E-8);
        self::assertTrue($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedHyperbolicCosecantRange(): void
    {
        $solutions = Math::solveEquationSet('floor(csch(x)) % 2 = 0', 'x', -1.0, -0.5);

        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(-\asinh(1.0), $solutions[0]->lowerBound, 1.0E-8);
        self::assertSame(-0.5, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testModuloSelectorUsesBoundedHyperbolicCotangentRange(): void
    {
        $solutions = Math::solveEquationSet('floor(coth(x)) % 2 = 0', 'x', -2.0, -1.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(-1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testPolynomialIdentityReturnsTheWholeBoundedInterval(): void
    {
        $solutions = Math::solveEquationSet('x^3 = x * x * x', 'x', -2.0, 3.0);

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(3.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testTrigonometricIdentityReturnsTheWholeBoundedInterval(): void
    {
        $solutions = Math::solveEquationSet(
            'sin(x)^2 + cos(x)^2 = 1',
            'x',
            -5.0,
            5.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(-5.0, $solutions[0]->lowerBound);
        self::assertSame(5.0, $solutions[0]->upperBound);
    }

    public function testRearrangedPythagoreanIdentityReturnsTheWholeBoundedInterval(): void
    {
        foreach (
            [
                'sin(x)^2 = 1 - cos(x)^2',
                'cos(x)^2 = 1 - sin(x)^2',
                '1 - cos(x)^2 = sin(x)^2',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -5.0, 5.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-5.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(5.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testHyperbolicPythagoreanIdentityAndRearrangementsReturnTheWholeInterval(): void
    {
        foreach (
            [
                'cosh(x)^2 - sinh(x)^2 = 1',
                'cosh(x)^2 = 1 + sinh(x)^2',
                'sinh(x)^2 = cosh(x)^2 - 1',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -5.0, 5.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-5.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(5.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testHyperbolicReciprocalIdentitiesPreserveTheirDomain(): void
    {
        foreach (
            [
                'tanh(x)^2 + sech(x)^2 = 1',
                'sech(x)^2 = 1 - tanh(x)^2',
                'coth(x)^2 - csch(x)^2 = 1',
                'coth(x)^2 = 1 + csch(x)^2',
                'csch(x)^2 = coth(x)^2 - 1',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -2.0, 2.0);

            if (str_contains($equation, 'coth')) {
                self::assertCount(2, $solutions, $equation);
                self::assertFalse($solutions[0]->includesUpperBound, $equation);
                self::assertFalse($solutions[1]->includesLowerBound, $equation);
                self::assertSame(0.0, $solutions[0]->upperBound, $equation);
                self::assertSame(0.0, $solutions[1]->lowerBound, $equation);
                continue;
            }

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-2.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(2.0, $solutions[0]->upperBound, $equation);
        }
    }

    public function testReciprocalCircularIdentitiesAndRearrangementsReturnTheirDomain(): void
    {
        foreach (
            [
                'sec(x)^2 - tan(x)^2 = 1',
                'sec(x)^2 = 1 + tan(x)^2',
                'tan(x)^2 = sec(x)^2 - 1',
                'csc(x)^2 - cot(x)^2 = 1',
                'csc(x)^2 = 1 + cot(x)^2',
                'cot(x)^2 = csc(x)^2 - 1',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', 0.2, 1.2);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(0.2, $solutions[0]->lowerBound, $equation);
            self::assertSame(1.2, $solutions[0]->upperBound, $equation);
        }
    }

    public function testUnrelatedCircularAndHyperbolicSquaresAreNotMistakenForIdentity(): void
    {
        $solutions = Math::solveEquationSet('sin(x)^2 + sinh(x)^2 = 1', 'x', -2.0, 2.0);

        self::assertNotSame([[-2.0, 2.0]], \array_map(
            static fn ($interval): array => [$interval->lowerBound, $interval->upperBound],
            $solutions,
        ));
    }

    public function testInverseCompositionIdentityIsExactAcrossLargeIntervals(): void
    {
        $solutions = Math::solveEquationSet(
            'tan(atan(x)) = x',
            'x',
            -1.0E6,
            1.0E6,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertSame(-1.0E6, $solutions[0]->lowerBound);
        self::assertSame(1.0E6, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testInverseCompositionIdentityStillRespectsTheExpressionDomain(): void
    {
        $solutions = Math::solveEquationSet('sin(asin(x)) = x', 'x', -2.0, 2.0);

        self::assertCount(1, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testInverseHyperbolicCompositionsPreserveTheirRestrictedDomains(): void
    {
        $cosh = Math::solveEquationSet('cosh(acosh(x)) = x', 'x', -2.0, 3.0);
        self::assertCount(1, $cosh);
        self::assertSame(1.0, $cosh[0]->lowerBound);
        self::assertSame(3.0, $cosh[0]->upperBound);
        self::assertTrue($cosh[0]->includesLowerBound);

        $sech = Math::solveEquationSet('sech(asech(x)) = x', 'x', -1.0, 2.0);
        self::assertCount(1, $sech);
        self::assertSame(0.0, $sech[0]->lowerBound);
        self::assertSame(1.0, $sech[0]->upperBound);
        self::assertFalse($sech[0]->includesLowerBound);
        self::assertTrue($sech[0]->includesUpperBound);

        $csch = Math::solveEquationSet('csch(acsch(x)) = x', 'x', -2.0, 2.0);
        self::assertCount(2, $csch);
        self::assertSame(-2.0, $csch[0]->lowerBound);
        self::assertSame(0.0, $csch[0]->upperBound);
        self::assertTrue($csch[0]->includesLowerBound);
        self::assertFalse($csch[0]->includesUpperBound);
        self::assertFalse($csch[1]->includesLowerBound);
        self::assertSame(2.0, $csch[1]->upperBound);

        $coth = Math::solveEquationSet('coth(acoth(x)) = x', 'x', -3.0, 3.0);
        self::assertCount(2, $coth);
        self::assertSame(-3.0, $coth[0]->lowerBound);
        self::assertSame(-1.0, $coth[0]->upperBound);
        self::assertFalse($coth[0]->includesUpperBound);
        self::assertFalse($coth[1]->includesLowerBound);
        self::assertSame(3.0, $coth[1]->upperBound);
    }

    public function testGloballyValidReverseHyperbolicCompositionsAreRecognized(): void
    {
        foreach (
            [
                ['asinh(sinh(x)) = x', -3.0, 3.0, null],
                ['atanh(tanh(x)) = x', -3.0, 3.0, null],
                ['acsch(csch(x)) = x', -2.0, 2.0, 0.0],
                ['acoth(coth(x)) = x', -2.0, 2.0, 0.0],
            ] as [$equation, $lowerBound, $upperBound, $excludedPoint]
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', $lowerBound, $upperBound);
            if ($excludedPoint === null) {
                self::assertCount(1, $solutions, $equation);
                self::assertSame($lowerBound, $solutions[0]->lowerBound, $equation);
                self::assertSame($upperBound, $solutions[0]->upperBound, $equation);
                continue;
            }

            self::assertCount(2, $solutions, $equation);
            self::assertSame($lowerBound, $solutions[0]->lowerBound, $equation);
            self::assertSame($excludedPoint, $solutions[0]->upperBound, $equation);
            self::assertFalse($solutions[0]->includesUpperBound, $equation);
            self::assertSame($excludedPoint, $solutions[1]->lowerBound, $equation);
            self::assertFalse($solutions[1]->includesLowerBound, $equation);
            self::assertSame($upperBound, $solutions[1]->upperBound, $equation);
        }
    }

    public function testReverseExponentialAndLogarithmicCompositionsAreRecognized(): void
    {
        foreach (['ln(exp(x)) = x', 'log1p(expm1(x)) = x'] as $equation) {
            $solutions = Math::solveEquationSet($equation, 'x', -3.0, 3.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-3.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(3.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testNestedSelfCompositionsHaveOnlyZeroAsFixedPoint(): void
    {
        foreach (
            [
                ['asinh(arcsinh(x)) = x', -2.0, 2.0],
                ['atanh(arctanh(x)) = x', -2.0, 2.0],
                ['asin(arcsin(x)) = x', -2.0, 2.0],
                ['arctan(atan(x)) = x', -2.0, 2.0],
                ['sin(sin(x)) = x', -20.0, 20.0],
                ['tanh(tanh(x)) = x', -20.0, 20.0],
                ['atan(atan(x)) = x', 0.1, 2.0],
            ] as [$equation, $lowerBound, $upperBound]
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', $lowerBound, $upperBound);

            if ($lowerBound <= 0.0 && $upperBound >= 0.0) {
                self::assertCount(1, $solutions, $equation);
                self::assertSame(0.0, $solutions[0]->lowerBound, $equation);
                self::assertSame(0.0, $solutions[0]->upperBound, $equation);
            } else {
                self::assertSame([], $solutions, $equation);
            }
        }
    }

    public function testCubeRootAndOddPowerCompositionsAreRecognizedExactly(): void
    {
        foreach (
            [
                'cbrt(x^3) = x',
                'x = cbrt(x^3)',
                'cbrt(x)^3 = x',
                'root(x^3, 3) = x',
                'root(x, 3)^3 = x',
                'root(x^5, 5) = x',
                'root(x^7, 7) = x',
                'root(x, 5)^5 = x',
                'root(x, 7)^7 = x',
                'root((x^2 + 1)^5, 5) = x^2 + 1',
                'root(x^2 + 1, 5)^5 = x^2 + 1',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -10.0, 10.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-10.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(10.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testEvenRootPowerCompositionsDoNotUseTheOddRootIdentityRule(): void
    {
        $solutions = Math::solveEquationSet('sqrt(x^2) = x', 'x', -10.0, 10.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(10.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);
    }

    public function testNonIntegerRootDegreesRemainOutsideTheRealRootDomain(): void
    {
        foreach (['root(x^n, n) = x', 'root(x, n)^n = x'] as $equation) {
            self::assertSame(
                [],
                Math::solveEquationSet($equation, 'x', -2.0, 2.0, ['n' => 3.1]),
                $equation,
            );
        }

        $solutions = Math::solveEquationSet('x^n = x', 'x', -2.0, 2.0, ['n' => 3.1]);
        self::assertCount(2, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertSame(1.0, $solutions[1]->lowerBound);
        self::assertSame(1.0, $solutions[1]->upperBound);
    }

    public function testOddRootIdentityDegreesSupportExactExpressionsAndFixedParameters(): void
    {
        foreach (
            [
                ['root(x^(2 + 3), 2 + 3) = x', []],
                ['root(x, 2 + 3)^(2 + 3) = x', []],
                ['root(x^(n + 2), n + 2) = x', ['n' => 3]],
                ['root(x, n + 2)^(n + 2) = x', ['n' => 3]],
                ['root(x^(n * 2 - 1), n * 2 - 1) = x', ['n' => 3]],
                ['root(x^(n^3), n^3) = x', ['n' => 3]],
                ['root(x^(n % 4 + 1), n % 4 + 1) = x', ['n' => 10]],
                ['root(x^n, n) = x', ['n' => 5]],
                ['root(x, n)^n = x', ['n' => 7]],
            ] as [$equation, $parameters]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                -2.0,
                2.0,
                $parameters,
            );

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-2.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(2.0, $solutions[0]->upperBound, $equation);
        }
    }

    public function testNegativeOddRootPowerIdentitiesExcludeTheZeroPole(): void
    {
        foreach (
            [
                ['root(x^-3, -3) = x', []],
                ['root(x, -3)^-3 = x', []],
                ['root(x^n, n) = x', ['n' => -5]],
            ] as [$equation, $parameters]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                -2.0,
                2.0,
                $parameters,
            );

            self::assertCount(2, $solutions, $equation);
            self::assertSame(-2.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(0.0, $solutions[0]->upperBound, $equation);
            self::assertFalse($solutions[0]->includesUpperBound, $equation);
            self::assertFalse($solutions[1]->includesLowerBound, $equation);
            self::assertSame(2.0, $solutions[1]->upperBound, $equation);
        }
    }

    public function testErrorFunctionInverseIdentitiesUseSymbolicDomainPartitions(): void
    {
        foreach (
            [
                ['erf(erfinv(x)) = x', 1.0, false],
                ['erfc(erfcinv(x)) = x', 2.0, false],
                ['tanh(arctanh(x)) = x', 1.0, false],
            ] as [$equation, $upperBound, $includesUpperBound]
        ) {
            $solutions = Math::solveEquationSet(
                $equation,
                'x',
                0.1,
                100.0,
                solverOptions: new SolverOptions(scanSteps: 1),
            );

            self::assertCount(1, $solutions, $equation);
            self::assertSame(0.1, $solutions[0]->lowerBound, $equation);
            self::assertSame($upperBound, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertSame($includesUpperBound, $solutions[0]->includesUpperBound, $equation);
        }

        $unrestrictedAlias = Math::solveEquationSet(
            'sinh(arcsinh(x)) = x',
            'x',
            10.0,
            100.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );
        self::assertCount(1, $unrestrictedAlias);
        self::assertSame(10.0, $unrestrictedAlias[0]->lowerBound);
        self::assertSame(100.0, $unrestrictedAlias[0]->upperBound);
        self::assertTrue($unrestrictedAlias[0]->includesLowerBound);
        self::assertTrue($unrestrictedAlias[0]->includesUpperBound);
    }

    public function testSmallScaleEquationIsNotMisclassifiedAsAnIdentity(): void
    {
        $solutions = Math::solveEquationSet('1e-12 * x = 0', 'x', -1.0, 1.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
    }

    public function testLargeIntegerAffineCoefficientsAreSolvedExactly(): void
    {
        $originRoot = Math::solveEquationSet(
            '9007199254740992 * x = 9007199254740993 * x',
            'x',
            -1.0,
            1.0,
        );
        $shiftedRoot = Math::solveEquationSet(
            '9007199254740992 * x = 9007199254740993 * x + 1',
            'x',
            -2.0,
            1.0,
        );

        self::assertCount(1, $originRoot);
        self::assertSame(0.0, $originRoot[0]->lowerBound);
        self::assertSame(0.0, $originRoot[0]->upperBound);
        self::assertCount(1, $shiftedRoot);
        self::assertSame(-1.0, $shiftedRoot[0]->lowerBound);
        self::assertSame(-1.0, $shiftedRoot[0]->upperBound);
    }

    public function testAffineCoefficientDifferenceOverflowFallsBackWithoutLosingTheRoot(): void
    {
        $largestInteger = (string) \PHP_INT_MAX;
        $solutions = Math::solveEquationSet(
            $largestInteger . ' * x = -x',
            'x',
            -1.0,
            1.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
    }

    public function testAffineCoefficientsCanExceedNativeIntegerRange(): void
    {
        $largestInteger = (string) \PHP_INT_MAX;
        $zeroRoot = Math::solveEquationSet(
            $largestInteger . ' * 2 * x = 0',
            'x',
            -1.0,
            1.0,
        );
        $solutions = Math::solveEquationSet(
            $largestInteger . ' * 2 * x = ' . $largestInteger . ' * x + 1',
            'x',
            -1.0,
            1.0,
        );
        $canceledLargeProducts = Math::solveEquationSet(
            $largestInteger . ' * ' . $largestInteger . ' * x = '
                . $largestInteger . ' * ' . $largestInteger . ' * x + 1',
            'x',
            -1.0,
            1.0,
        );
        $exactDivisionIdentity = Math::solveEquationSet(
            '(' . $largestInteger . ' * 3 * x) / 3 = ' . $largestInteger . ' * x',
            'x',
            -1.0,
            1.0,
        );
        $largeProductCancellation = Math::solveEquationSet(
            '(' . $largestInteger . ' * ' . $largestInteger . ') * x = '
                . '((' . $largestInteger . ' - 1) * (' . $largestInteger . ' + 1)) * x + 1',
            'x',
            -2.0,
            1.0,
        );
        $largeDivisorIdentity = Math::solveEquationSet(
            '(' . $largestInteger . ' * ' . $largestInteger . ' * x) / '
                . $largestInteger . ' = ' . $largestInteger . ' * x',
            'x',
            -1.0,
            1.0,
        );

        self::assertCount(1, $zeroRoot);
        self::assertSame(0.0, $zeroRoot[0]->lowerBound);
        self::assertCount(1, $solutions);
        self::assertEqualsWithDelta(1.0 / (float) \PHP_INT_MAX, $solutions[0]->lowerBound, 1.0E-32);
        self::assertSame($solutions[0]->lowerBound, $solutions[0]->upperBound);
        self::assertSame([], $canceledLargeProducts);
        self::assertCount(1, $exactDivisionIdentity);
        self::assertSame(-1.0, $exactDivisionIdentity[0]->lowerBound);
        self::assertSame(1.0, $exactDivisionIdentity[0]->upperBound);
        self::assertCount(1, $largeProductCancellation);
        self::assertSame(1.0, $largeProductCancellation[0]->lowerBound);
        self::assertSame(1.0, $largeProductCancellation[0]->upperBound);
        self::assertCount(1, $largeDivisorIdentity);
        self::assertSame(-1.0, $largeDivisorIdentity[0]->lowerBound);
        self::assertSame(1.0, $largeDivisorIdentity[0]->upperBound);
    }

    public function testUnrepresentableExactAffineRootIsNotRoundedToZero(): void
    {
        $largeCoefficient = \implode(' * ', \array_fill(0, 20, '1000000000000000000'));
        $solutions = Math::solveEquationSet(
            '(' . $largeCoefficient . ') * x = 1',
            'x',
            -1.0,
            1.0,
        );

        self::assertSame([], $solutions);
    }

    public function testExactAffineRootsOutsideBoundsAreNotClampedIntoTheInterval(): void
    {
        self::assertSame(
            [],
            Math::solveEquationSet(
                '10000000000000000 * x = 10000000000000001',
                'x',
                -1.0,
                1.0,
            ),
        );
        self::assertSame(
            [],
            Math::solveEquationSet(
                '10000000000000000 * x = -10000000000000001',
                'x',
                -1.0,
                1.0,
            ),
        );
    }

    public function testPiecewiseIdentityReturnsItsSolutionInterval(): void
    {
        $solutions = Math::solveEquationSet('abs(x) = x', 'x', -5.0, 5.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(5.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->contains(2.0));
        self::assertFalse($solutions[0]->contains(-2.0));
    }

    public function testFiniteRootsAreRepresentedAsPointIntervals(): void
    {
        $solutions = Math::solveEquationSet('x^2 = 1', 'x', -2.0, 2.0);

        self::assertCount(2, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertSame(-1.0, $solutions[0]->upperBound);
        self::assertSame(1.0, $solutions[1]->lowerBound);
        self::assertSame(1.0, $solutions[1]->upperBound);
    }

    public function testPeriodicEquationReturnsEveryPointRootInTheBounds(): void
    {
        $solutions = Math::solveEquationSet('sin(x) = 0', 'x', -7.0, 7.0);

        self::assertCount(5, $solutions);
        foreach ([-2.0 * \M_PI, -\M_PI, 0.0, \M_PI, 2.0 * \M_PI] as $index => $root) {
            self::assertEqualsWithDelta($root, $solutions[$index]->lowerBound, 1.0E-10);
            self::assertSame($solutions[$index]->lowerBound, $solutions[$index]->upperBound);
            self::assertTrue($solutions[$index]->includesLowerBound);
            self::assertTrue($solutions[$index]->includesUpperBound);
        }
    }

    public function testPiecewiseAndStepFunctionSetsPreserveEndpointSemantics(): void
    {
        $cases = [
            ['floor(x) = 2', 2.0, 3.0, true, false],
            ['ceil(x) = 2', 1.0, 2.0, false, true],
            ['round(x) = 2', 1.5, 2.5, true, false],
            ['max(x, 0) = 0', -5.0, 0.0, true, true],
            ['sign(x) = 0', 0.0, 0.0, true, true],
        ];

        foreach ($cases as [$equation, $lower, $upper, $includesLower, $includesUpper]) {
            $solutions = Math::solveEquationSet($equation, 'x', -5.0, 5.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame($lower, $solutions[0]->lowerBound, $equation);
            self::assertSame($upper, $solutions[0]->upperBound, $equation);
            self::assertSame($includesLower, $solutions[0]->includesLowerBound, $equation);
            self::assertSame($includesUpper, $solutions[0]->includesUpperBound, $equation);
        }
    }

    public function testEquationSetPreservesDomainHoles(): void
    {
        $solutions = Math::solveEquationSet('1 / x = 1 / x', 'x', -1.0, 1.0);

        self::assertCount(2, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(0.0, $solutions[1]->lowerBound);
        self::assertSame(1.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);
    }

    public function testIdentitySolutionSetRespectsTheExpressionDomain(): void
    {
        $solutions = Math::solveEquationSet('sqrt(x) = sqrt(x)', 'x', -1.0, 1.0);

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
    }

    public function testPartiallyDefinedIdentityIncludesDefinedIntervalEndpoints(): void
    {
        $solutions = Math::solveEquationSet(
            'exp(ln(x)) = x',
            'x',
            0.0,
            5.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(1, $solutions);
        self::assertSame(0.0, $solutions[0]->lowerBound);
        self::assertSame(5.0, $solutions[0]->upperBound);
        self::assertFalse($solutions[0]->includesLowerBound);
        self::assertTrue($solutions[0]->includesUpperBound);

        $disconnected = Math::solveEquationSet(
            'exp(ln(x^2)) = x^2',
            'x',
            -3.0,
            3.0,
            solverOptions: new SolverOptions(scanSteps: 1),
        );

        self::assertCount(2, $disconnected);
        self::assertSame(-3.0, $disconnected[0]->lowerBound);
        self::assertSame(0.0, $disconnected[0]->upperBound);
        self::assertTrue($disconnected[0]->includesLowerBound);
        self::assertFalse($disconnected[0]->includesUpperBound);
        self::assertSame(0.0, $disconnected[1]->lowerBound);
        self::assertSame(3.0, $disconnected[1]->upperBound);
        self::assertFalse($disconnected[1]->includesLowerBound);
        self::assertTrue($disconnected[1]->includesUpperBound);

        $notAnIdentity = Math::solveEquationSet('ln(x) = 0', 'x', 0.0, 5.0);
        self::assertCount(1, $notAnIdentity);
        self::assertEqualsWithDelta(1.0, $notAnIdentity[0]->lowerBound, 1.0E-9);
        self::assertSame($notAnIdentity[0]->lowerBound, $notAnIdentity[0]->upperBound);
    }

    public function testSampledIdentityPreservesTrigonometricPoles(): void
    {
        $solutions = Math::solveEquationSet('tan(x) = tan(x)', 'x', -2.0, 2.0);

        self::assertCount(3, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta(-\M_PI / 2.0, $solutions[0]->upperBound, 1.0E-12);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta(-\M_PI / 2.0, $solutions[1]->lowerBound, 1.0E-12);
        self::assertEqualsWithDelta(\M_PI / 2.0, $solutions[1]->upperBound, 1.0E-12);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertFalse($solutions[1]->includesUpperBound);
        self::assertEqualsWithDelta(\M_PI / 2.0, $solutions[2]->lowerBound, 1.0E-12);
        self::assertSame(2.0, $solutions[2]->upperBound);
        self::assertFalse($solutions[2]->includesLowerBound);
    }

    public function testIdentitySolutionSetPreservesUnsampledRationalPole(): void
    {
        $pole = 0.123456789;
        $expression = \sprintf('1 / (x - %.9f) = 1 / (x - %.9f)', $pole, $pole);
        $solutions = Math::solveEquationSet($expression, 'x', -1.0, 1.0);

        self::assertCount(2, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertEqualsWithDelta($pole, $solutions[0]->upperBound, 1.0E-12);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertEqualsWithDelta($pole, $solutions[1]->lowerBound, 1.0E-12);
        self::assertSame(1.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
    }

    public function testEquationSetCanBeEmpty(): void
    {
        self::assertSame([], Math::solveEquationSet('x^2 + 1 = 0', 'x', -1.0, 1.0));
    }

    public function testVariableIndependentEquationReturnsItsWholeSolutionSet(): void
    {
        $solutions = Math::solveEquationSet('2 + 2 = 4', 'x', -3.0, 5.0);

        self::assertCount(1, $solutions);
        self::assertSame(-3.0, $solutions[0]->lowerBound);
        self::assertSame(5.0, $solutions[0]->upperBound);
    }

    public function testVariableIndependentFalseOrUndefinedEquationsAreEmpty(): void
    {
        self::assertSame([], Math::solveEquationSet('1 = 2', 'x', -1.0, 1.0));
        self::assertSame([], Math::solveEquationSet('sqrt(-1) = 0', 'x', -1.0, 1.0));
        self::assertSame(
            [],
            Math::solveEquationSet(
                '9007199254740992 = 9007199254740993',
                'x',
                -1.0,
                1.0,
            ),
        );
    }

    public function testVariableIndependentLargeIntegerIdentityRemainsExact(): void
    {
        $solutions = Math::solveEquationSet(
            '9007199254740993 = 9007199254740993',
            'x',
            -1.0,
            1.0,
        );

        self::assertCount(1, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
    }

    public function testVariableIndependentEquationStillRequiresItsParameters(): void
    {
        $this->expectException(\MathPHP\Exception\EvaluationException::class);
        $this->expectExceptionMessage('Unknown variable "missing"');

        Math::solveEquationSet('missing = 0', 'x', -1.0, 1.0);
    }

    public function testEquationSetUsesSuppliedParameters(): void
    {
        $solutions = Math::solveEquationSet(
            'abs(x) = radius',
            'x',
            -4.0,
            4.0,
            ['radius' => 2.0],
        );

        self::assertCount(2, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(-2.0, $solutions[0]->upperBound);
        self::assertSame(2.0, $solutions[1]->lowerBound);
        self::assertSame(2.0, $solutions[1]->upperBound);
    }

    public function testEquationSetUsesCustomFunctionsFromEvaluationOptions(): void
    {
        $functions = FunctionRegistry::defaults()->with(
            new FunctionDefinition(
                'identity',
                1,
                1,
                static fn (array $arguments): int|float => $arguments[0],
                exactIdentityArgument: 0,
            ),
        );
        $options = new EvaluationOptions(functions: $functions);

        $solutions = Math::solveEquationSet(
            'identity(x) = x',
            'x',
            -2.0,
            3.0,
            evaluationOptions: $options,
        );

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(3.0, $solutions[0]->upperBound);
    }

    public function testStructurallyIdenticalCustomFunctionCallsAreExactIdentities(): void
    {
        $functions = FunctionRegistry::defaults()->with(
            new FunctionDefinition(
                'identity',
                1,
                1,
                static fn (array $arguments): int|float => $arguments[0],
            ),
        );
        $solutions = Math::solveEquationSet(
            'identity((x)) = (identity(x))',
            'x',
            -2.0,
            3.0,
            evaluationOptions: new EvaluationOptions(functions: $functions),
        );

        self::assertCount(1, $solutions);
        self::assertSame(-2.0, $solutions[0]->lowerBound);
        self::assertSame(3.0, $solutions[0]->upperBound);
    }

    public function testOpaqueCustomFunctionIsNotAssumedToBeAnIdentity(): void
    {
        $functions = FunctionRegistry::defaults()->with(
            new FunctionDefinition(
                'identity',
                1,
                1,
                static fn (array $arguments): int|float => $arguments[0],
            ),
        );

        $this->expectException(RootSearchLimitException::class);
        Math::solveEquationSet(
            'identity(x) = x',
            'x',
            -2.0,
            3.0,
            evaluationOptions: new EvaluationOptions(functions: $functions),
        );
    }

    public function testOpaqueCustomFunctionCanStillHaveFiniteEquationSetRoots(): void
    {
        $functions = FunctionRegistry::defaults()->with(
            new FunctionDefinition(
                'square',
                1,
                1,
                static fn (array $arguments): int|float => $arguments[0] * $arguments[0],
            ),
        );

        $solutions = Math::solveEquationSet(
            'square(x) = x',
            'x',
            -2.0,
            3.0,
            evaluationOptions: new EvaluationOptions(functions: $functions),
        );

        self::assertCount(2, $solutions);
        self::assertEqualsWithDelta(0.0, $solutions[0]->lowerBound, 1.0E-9);
        self::assertSame($solutions[0]->lowerBound, $solutions[0]->upperBound);
        self::assertEqualsWithDelta(1.0, $solutions[1]->lowerBound, 1.0E-9);
        self::assertSame($solutions[1]->lowerBound, $solutions[1]->upperBound);
    }

    public function testFunctionNameCanMatchSolvedVariableForConstantEquation(): void
    {
        $solutions = Math::solveEquationSet('sin(1) = sin(1)', 'sin', -1.0, 1.0);

        self::assertCount(1, $solutions);
        self::assertSame(-1.0, $solutions[0]->lowerBound);
        self::assertSame(1.0, $solutions[0]->upperBound);
    }

    public function testPeriodicStepEquationSetsMatchDirectMembershipAcrossBounds(): void
    {
        foreach (
            [
                'round(sin(x)) = ceil(sin(x))',
                'floor(sin(3*x)) % 2 = 0',
                'floor(cos(x)) = round(cos(x))',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -7.0, 7.0);
            [$left, $right] = \explode('=', $equation, 2);
            for ($index = 0; $index <= 1400; ++$index) {
                $value = -7.0 + ($index / 100.0);
                try {
                    $matchesEquation = Math::evaluate($left, ['x' => $value])
                        == Math::evaluate($right, ['x' => $value]);
                } catch (\MathPHP\Exception\EvaluationException) {
                    $matchesEquation = false;
                }
                $isInReturnedSet = false;
                foreach ($solutions as $solution) {
                    if ($solution->contains($value)) {
                        $isInReturnedSet = true;
                        break;
                    }
                }

                self::assertSame($matchesEquation, $isInReturnedSet, $equation . ' at ' . $value);
            }
        }
    }

    public function testDistinctQuantizersRejectInfiniteComponentsAtRationalPole(): void
    {
        foreach (
            [
                'floor(1 / x) = ceil(1 / x)',
                'floor(1 / x) = round(1 / x)',
                'ceil(1 / x) = round(1 / x)',
            ] as $equation
        ) {
            try {
                Math::solveEquationSet($equation, 'x', -3.0, 3.0);
                self::fail($equation . ' should have infinitely many components.');
            } catch (InfiniteSolutionSetException $exception) {
                self::assertStringContainsString(
                    'infinitely many solution components accumulating',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testAffineQuantizerSidesDetectInfinitePoleAccumulation(): void
    {
        foreach (
            [
                'round(1 / x) = ceil(1 / x) - 1',
                'round(1 / x) = floor(1 / x)',
                'ceil(1 / x) = round(1 / x) + 1',
                'floor(1 / x) = round(1 / x) - 1',
                'round(1 / x) + 1 = ceil(1 / x)',
            ] as $equation
        ) {
            try {
                Math::solveEquationSet($equation, 'x', -3.0, 3.0);
                self::fail($equation . ' should have infinitely many components.');
            } catch (InfiniteSolutionSetException $exception) {
                self::assertStringContainsString(
                    'infinitely many solution components accumulating',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testAffineQuantizerSideRejectsNonIntegerOutputOffset(): void
    {
        self::assertSame(
            [],
            Math::solveEquationSet('floor(x) = ceil(x) + 0.5', 'x', -3.0, 3.0),
        );
    }

    public function testLargeIntegerArgumentShiftsAreCanceledByOutputOffsets(): void
    {
        foreach (
            [
                'floor(x) = floor(x + 100) - 100',
                'ceil(x) = ceil(x - 100) + 100',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -3.0, 3.0);

            self::assertCount(1, $solutions, $equation);
            self::assertSame(-3.0, $solutions[0]->lowerBound, $equation);
            self::assertSame(3.0, $solutions[0]->upperBound, $equation);
            self::assertTrue($solutions[0]->includesLowerBound, $equation);
            self::assertTrue($solutions[0]->includesUpperBound, $equation);
        }

        $this->expectException(InfiniteSolutionSetException::class);
        Math::solveEquationSet('floor(1 / x) = ceil(1 / x + 100) - 100', 'x', -3.0, 3.0);
    }

    public function testAffineQuantizerOffsetsMatchDirectMembershipAtBoundaries(): void
    {
        foreach (
            [
                'floor(x) = ceil(x) - 1',
                'round(x) = floor(x) + 1',
                'round(x) + 1 = ceil(x)',
                'round(x) = round(x + 100) - 100',
                'floor((x^2 - 1) / (x - 1)) = ceil(x + 1) - 1',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -3.0, 3.0);
            [$left, $right] = \explode('=', $equation, 2);
            $values = [];
            for ($index = 0; $index <= 600; ++$index) {
                $values[] = -3.0 + ($index / 100.0);
            }
            array_push($values, -2.5, -1.5, -0.5, 0.5, 1.5, 2.5);

            foreach ($values as $value) {
                try {
                    $matchesEquation = Math::evaluate($left, ['x' => $value])
                        == Math::evaluate($right, ['x' => $value]);
                } catch (\Throwable) {
                    $matchesEquation = false;
                }
                $isInReturnedSet = false;
                foreach ($solutions as $solution) {
                    if ($solution->contains($value)) {
                        $isInReturnedSet = true;
                        break;
                    }
                }

                self::assertSame($matchesEquation, $isInReturnedSet, $equation . ' at ' . $value);
            }
        }
    }

    public function testIdenticalQuantizerRemainsTheDomainWithItsPoleRemoved(): void
    {
        $solutions = Math::solveEquationSet('floor(1 / x) = floor(1 / x)', 'x', -3.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertSame(0.0, $solutions[1]->lowerBound);
    }

    public function testRationalQuantizerOffsetDetectsAccumulationAndImpossiblePhase(): void
    {
        try {
            Math::solveEquationSet(
                'floor(1 / x) = ceil(1 / x - 0.5)',
                'x',
                -3.0,
                3.0,
            );
            self::fail('The negative shift should create infinitely many components.');
        } catch (InfiniteSolutionSetException $exception) {
            self::assertStringContainsString(
                'infinitely many solution components accumulating',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            [],
            Math::solveEquationSet(
                'floor(1 / x) = ceil(1 / x + 0.5)',
                'x',
                -3.0,
                3.0,
            ),
        );

        $negativeTieRoots = Math::solveEquationSet('floor(x) = round(x + 0.5)', 'x', -3.0, 3.0);
        self::assertSame([-3.0, -2.0, -1.0], \array_map(
            static fn ($solution): float => $solution->lowerBound,
            $negativeTieRoots,
        ));
        $negativeHalfRoots = Math::solveEquationSet(
            'round(x) = floor(x - 0.5)',
            'x',
            -3.0,
            3.0,
        );
        self::assertSame([-2.5, -1.5, -0.5], \array_map(
            static fn ($solution): float => $solution->lowerBound,
            $negativeHalfRoots,
        ));

        $this->expectException(InfiniteSolutionSetException::class);
        Math::solveEquationSet('floor(1 / x) = round(1 / x + 0.5)', 'x', -3.0, 3.0);
    }

    public function testEquivalentRationalFloorCeilEnumeratesIntegerLevelsAroundRemovableHole(): void
    {
        $equation = 'floor((x^2 - 1) / (x - 1)) = ceil((x^2 - 1) / (x - 1))';
        $solutions = Math::solveEquationSet($equation, 'x', -3.0, 3.0);

        self::assertSame([-3.0, -2.0, -1.0, 0.0, 2.0, 3.0], \array_map(
            static fn ($solution): float => $solution->lowerBound,
            $solutions,
        ));
        foreach ($solutions as $solution) {
            self::assertSame($solution->lowerBound, $solution->upperBound);
        }
    }

    public function testConstantIntegerQuantizerIntersectsTheOriginalArgumentDomains(): void
    {
        $solutions = Math::solveEquationSet('floor(1) = ceil(x / x)', 'x', -3.0, 3.0);

        self::assertCount(2, $solutions);
        self::assertSame(-3.0, $solutions[0]->lowerBound);
        self::assertSame(0.0, $solutions[0]->upperBound);
        self::assertTrue($solutions[0]->includesLowerBound);
        self::assertFalse($solutions[0]->includesUpperBound);
        self::assertSame(0.0, $solutions[1]->lowerBound);
        self::assertSame(3.0, $solutions[1]->upperBound);
        self::assertFalse($solutions[1]->includesLowerBound);
        self::assertTrue($solutions[1]->includesUpperBound);

        $differentHoles = Math::solveEquationSet(
            'floor(x / x) = ceil((x - 1) / (x - 1))',
            'x',
            -3.0,
            3.0,
        );
        self::assertCount(3, $differentHoles);
        self::assertSame([-3.0, 0.0, 1.0], \array_map(
            static fn ($solution): float => $solution->lowerBound,
            $differentHoles,
        ));
        self::assertSame([0.0, 1.0, 3.0], \array_map(
            static fn ($solution): float => $solution->upperBound,
            $differentHoles,
        ));
    }

    public function testEquivalentRationalQuantizersMatchDirectMembershipAroundRemovableHole(): void
    {
        foreach (
            [
                'floor((x^2 - 1) / (x - 1)) = round(x + 1)',
                'ceil((x^2 - 1) / (x - 1)) = round(x + 1)',
                'floor(x + 1) = round((x^2 - 1) / (x - 1))',
                'ceil(x + 1) = round((x^2 - 1) / (x - 1))',
            ] as $equation
        ) {
            $solutions = Math::solveEquationSet($equation, 'x', -3.0, 3.0);
            [$left, $right] = \explode('=', $equation, 2);
            for ($index = 0; $index <= 600; ++$index) {
                $value = -3.0 + ($index / 100.0);
                try {
                    $matchesEquation = Math::evaluate($left, ['x' => $value])
                        == Math::evaluate($right, ['x' => $value]);
                } catch (\Throwable) {
                    $matchesEquation = false;
                }
                $isInReturnedSet = false;
                foreach ($solutions as $solution) {
                    if ($solution->contains($value)) {
                        $isInReturnedSet = true;
                        break;
                    }
                }

                self::assertSame($matchesEquation, $isInReturnedSet, $equation . ' at ' . $value);
            }
        }
    }
}
