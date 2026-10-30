<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Solver\BivariatePolynomialSystemSolver;
use MathPHP\Solver\SolverOptions;
use MathPHP\Solver\SymmetricPowerSumSystemSolver;
use PHPUnit\Framework\TestCase;

final class SystemSolverTest extends TestCase
{
    public function testSolvesLinearSystemFromInitialValues(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 3', 'x - y = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testExactLinearSystemsCanExceedTheFormerThirtyTwoVariableLimit(): void
    {
        $equations = [];
        $initialValues = [];
        $expectedValues = [];
        for ($index = 0; $index < 33; ++$index) {
            $variable = 'v' . $index;
            $expected = $index - 16;
            $equations[] = $variable . ' = ' . $expected;
            $initialValues[$variable] = 0.0;
            $expectedValues[$variable] = (float) $expected;
        }

        $solution = Math::solveSystem($equations, $initialValues);

        self::assertNotNull($solution);
        self::assertSame($expectedValues, $solution->values);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testChainedEqualitiesCanExpandToTheSystemDimensionLimit(): void
    {
        $variables = [];
        $initialValues = [];
        $expectedValues = [];
        for ($index = 0; $index < 64; ++$index) {
            $variable = 'v' . $index;
            $variables[] = $variable;
            $initialValues[$variable] = 0.0;
            $expectedValues[$variable] = 1.0;
        }

        $solution = Math::solveSystem(
            [\implode(' = ', $variables), $variables[0] . ' = 1'],
            $initialValues,
        );

        self::assertNotNull($solution);
        self::assertSame($expectedValues, $solution->values);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testPublicSystemSolverUsesResultantsForRationalPowers(): void
    {
        $solution = Math::solveSystem(
            ['x^(2/3) = 4', 'y = 1'],
            ['x' => 0.0, 'y' => 1.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(8.0, \abs($solution->values['x']), 1.0E-9);
        self::assertSame(1.0, $solution->values['y']);
        self::assertLessThanOrEqual(1.0E-9, $solution->residualNorm);

        $principalRoot = Math::solveSystem(
            ['x^(1/2) = 2', 'y = 1'],
            ['x' => -1.0, 'y' => 1.0],
        );

        self::assertNotNull($principalRoot);
        self::assertEqualsWithDelta(4.0, $principalRoot->values['x'], 1.0E-9);
        self::assertSame(1.0, $principalRoot->values['y']);
    }

    public function testDirectVariableConstraintSubstitutesBeforePowerLinearization(): void
    {
        $solution = Math::solveSystem(
            ['2 = (x+1)*sqrt(x)/(x+y)', 'y = 0'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(0.0, $solution->values['y'], 1.0E-8);
    }

    public function testPolynomialFactorsDistributeAcrossAdditiveRadicalsForSystems(): void
    {
        $solution = Math::solveSystem(
            ['(x+y)*(sqrt(x)+sqrt(y)) = 15', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(4.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
    }

    public function testThreeRadicalSystemUsesCertifiedAllRealIsolation(): void
    {
        $solution = Math::solveSystem(
            ['sqrt(x) + sqrt(4*y-12) = sqrt(6*y+1)', 'x-y = 5'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(9.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $solution->values['y'], 1.0E-8);
    }

    public function testPublicSystemSolverReducesNestedRationalPowers(): void
    {
        $solution = Math::solveSystem(
            ['(x^(1/2))^2 + y = 4', 'y = 1'],
            ['x' => -1.0, 'y' => 1.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(3.0, $solution->values['x'], 1.0E-9);
        self::assertSame(1.0, $solution->values['y']);
        self::assertLessThanOrEqual(1.0E-9, $solution->residualNorm);

        $additiveRadical = Math::solveSystem(
            ['x^(1/2) + y = 4', 'y = 1'],
            ['x' => -1.0, 'y' => 1.0],
        );

        self::assertNotNull($additiveRadical);
        self::assertEqualsWithDelta(9.0, $additiveRadical->values['x'], 1.0E-9);
        self::assertSame(1.0, $additiveRadical->values['y']);

        $twoRadicals = Math::solveSystem(
            ['sqrt(x) + sqrt(y) = 3', 'x - y = 5'],
            ['x' => 5.0, 'y' => 0.0],
        );

        self::assertNotNull($twoRadicals);
        self::assertEqualsWithDelta(49.0 / 9.0, $twoRadicals->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0 / 9.0, $twoRadicals->values['y'], 1.0E-8);

        $radicalOnly = Math::solveSystem(
            ['sqrt(x) + sqrt(y) = 3', 'sqrt(x) - sqrt(y) = 1'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($radicalOnly);
        self::assertEqualsWithDelta(4.0, $radicalOnly->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $radicalOnly->values['y'], 1.0E-8);

        $scaledRadicals = Math::solveSystem(
            ['2*sqrt(x) + 3*sqrt(y) = 12', 'x - y = 5'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($scaledRadicals);
        self::assertEqualsWithDelta(9.0, $scaledRadicals->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $scaledRadicals->values['y'], 1.0E-8);

        $threeRadicals = Math::solveSystem(
            ['2*sqrt(x) - sqrt(y) + 3*sqrt(x+y) = 4 + 3*sqrt(13)', 'x - y = 5'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($threeRadicals);
        self::assertEqualsWithDelta(9.0, $threeRadicals->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(4.0, $threeRadicals->values['y'], 1.0E-7);

        $repeatedRadicand = Math::solveSystem(
            ['sqrt(x) + 2*sqrt(x) + sqrt(y) = 5', 'x = 1'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($repeatedRadicand);
        self::assertEqualsWithDelta(1.0, $repeatedRadicand->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $repeatedRadicand->values['y'], 1.0E-8);

        $zeroRadicand = Math::solveSystem(
            ['sqrt(x) + sqrt(y) + sqrt(x-x) = 3', 'x - y = 5'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($zeroRadicand);
        self::assertEqualsWithDelta(49.0 / 9.0, $zeroRadicand->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0 / 9.0, $zeroRadicand->values['y'], 1.0E-8);

        $singleVariableCoefficient = Math::solveSystem(
            ['(x+y)*sqrt(x) + sqrt(y) = 7', 'x = 1'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($singleVariableCoefficient);
        self::assertEqualsWithDelta(1.0, $singleVariableCoefficient->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $singleVariableCoefficient->values['y'], 1.0E-8);

        $nestedCoefficient = Math::solveSystem(
            ['2*sqrt(x)*(x+y) + sqrt(y) = 12', 'x = 1'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($nestedCoefficient);
        self::assertEqualsWithDelta(1.0, $nestedCoefficient->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $nestedCoefficient->values['y'], 1.0E-8);

        $radicalOverPolynomial = Math::solveSystem(
            ['sqrt(x)/(x+y) = 1', 'y = 0'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($radicalOverPolynomial);
        self::assertEqualsWithDelta(1.0, $radicalOverPolynomial->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(0.0, $radicalOverPolynomial->values['y'], 1.0E-8);

        $polynomialRadicalOverPolynomial = Math::solveSystem(
            ['2 = (x+1)*sqrt(x)/(x+y)', 'y = 0'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($polynomialRadicalOverPolynomial);
        self::assertEqualsWithDelta(1.0, $polynomialRadicalOverPolynomial->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(0.0, $polynomialRadicalOverPolynomial->values['y'], 1.0E-8);

        $sumOfRadicalQuotients = Math::solveSystem(
            ['sqrt(x)/(x+y) + sqrt(y)/(x-y) = 11/15', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($sumOfRadicalQuotients);
        self::assertEqualsWithDelta(4.0, $sumOfRadicalQuotients->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $sumOfRadicalQuotients->values['y'], 1.0E-8);

        $radicalSumOverPolynomial = Math::solveSystem(
            ['(sqrt(x)+sqrt(y))/(x+y) = 3/5', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($radicalSumOverPolynomial);
        self::assertEqualsWithDelta(4.0, $radicalSumOverPolynomial->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $radicalSumOverPolynomial->values['y'], 1.0E-8);

        $radicalOverRationalDenominator = Math::solveSystem(
            ['sqrt(x)/((x+y)/(x-y)) = 6/5', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($radicalOverRationalDenominator);
        self::assertEqualsWithDelta(4.0, $radicalOverRationalDenominator->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $radicalOverRationalDenominator->values['y'], 1.0E-8);

        $radicalSumOverRationalDenominator = Math::solveSystem(
            ['(sqrt(x)+sqrt(y))/((x+y)/(x-y)) = 9/5', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($radicalSumOverRationalDenominator);
        self::assertEqualsWithDelta(4.0, $radicalSumOverRationalDenominator->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $radicalSumOverRationalDenominator->values['y'], 1.0E-8);

        $chainedRadicalQuotient = Math::solveSystem(
            ['sqrt(x)/(x+y)/(x-y) = 2/15', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($chainedRadicalQuotient);
        self::assertEqualsWithDelta(4.0, $chainedRadicalQuotient->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $chainedRadicalQuotient->values['y'], 1.0E-8);

        $polynomialMultipleOfRadicalSum = Math::solveSystem(
            ['(x+y)*(sqrt(x)+sqrt(y))/((x+y)/(x-y)) = 9', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($polynomialMultipleOfRadicalSum);
        self::assertEqualsWithDelta(4.0, $polynomialMultipleOfRadicalSum->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $polynomialMultipleOfRadicalSum->values['y'], 1.0E-8);

        $twoCubeRoots = Math::solveSystem(
            ['cbrt(x) + cbrt(y) = 3', 'x-y = 7'],
            ['x' => -1.0, 'y' => -1.0],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($twoCubeRoots);
        self::assertEqualsWithDelta(8.0, $twoCubeRoots->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $twoCubeRoots->values['y'], 1.0E-8);

        $scaledCubeRoots = Math::solveSystem(
            ['(x+y)*cbrt(x) - (x-y)*cbrt(y) = 11', 'x-y = 7'],
            ['x' => 8.1, 'y' => 1.1],
            [],
            null,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($scaledCubeRoots);
        self::assertEqualsWithDelta(8.0, $scaledCubeRoots->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $scaledCubeRoots->values['y'], 1.0E-8);

        $twoVariableCoefficients = Math::solveSystem(
            ['(x+y)*sqrt(x) + (x-y)*sqrt(y) = 13', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($twoVariableCoefficients);
        self::assertEqualsWithDelta(4.0, $twoVariableCoefficients->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $twoVariableCoefficients->values['y'], 1.0E-8);

        $threeVariableCoefficients = Math::solveSystem(
            ['(x+y)*sqrt(x) - x*sqrt(y) + sqrt(x+y) = 6 + sqrt(5)', 'x-y = 3'],
            ['x' => -1.0, 'y' => -1.0],
        );

        self::assertNotNull($threeVariableCoefficients);
        self::assertEqualsWithDelta(4.0, $threeVariableCoefficients->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(1.0, $threeVariableCoefficients->values['y'], 1.0E-7);
    }

    public function testBivariateResultantsSolveRationalSystemsAndRejectPoleArtifacts(): void
    {
        $evaluationOptions = new EvaluationOptions();
        $parse = static fn (string $expression): \MathPHP\Ast\Node => (new Parser(
            (new Lexer($expression, $evaluationOptions->limits))->tokenize(),
            $evaluationOptions->limits,
        ))->parse();
        $solver = new BivariatePolynomialSystemSolver();
        $solverOptions = new SolverOptions();

        foreach ([6, 8] as $rootDegree) {
            $rootValue = 2 ** $rootDegree;
            $rootSum = 'root(x,' . $rootDegree . ')+root(y,' . $rootDegree . ')';
            $rootDifference = (string) ($rootValue - 1);
            $conditionedResultantSolution = $solver->solve(
                [
                    [$parse($rootSum), $parse('3'), 0],
                    [$parse('x-y'), $parse($rootDifference), 0],
                ],
                [$rootSum . '=3', 'x-y=' . $rootDifference],
                ['x', 'y'],
                [0.0, 0.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($conditionedResultantSolution, 'root degree ' . $rootDegree);
            self::assertEqualsWithDelta(
                (float) $rootValue,
                $conditionedResultantSolution->values['x'],
                1.0E-8,
            );
            self::assertEqualsWithDelta(1.0, $conditionedResultantSolution->values['y'], 1.0E-8);
        }

        $smallScaleRootSum = 'root(x,6)+root(y,6)';
        $smallScaleDifference = '0.000000000000000063';
        $smallScaleSolution = $solver->solve(
            [
                [$parse($smallScaleRootSum), $parse('0.003'), 0],
                [$parse('x-y'), $parse($smallScaleDifference), 0],
            ],
            [
                $smallScaleRootSum . '=0.003',
                'x-y=' . $smallScaleDifference,
            ],
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($smallScaleSolution);
        self::assertEqualsWithDelta(6.4E-17, $smallScaleSolution->values['x'], 1.0E-26);
        self::assertEqualsWithDelta(1.0E-18, $smallScaleSolution->values['y'], 1.0E-27);

        $largeScaleDifference = '6.3e19';
        $largeScaleSolution = $solver->solve(
            [
                [$parse($smallScaleRootSum), $parse('3000'), 0],
                [$parse('x-y'), $parse($largeScaleDifference), 0],
            ],
            [
                $smallScaleRootSum . '=3000',
                'x-y=' . $largeScaleDifference,
            ],
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($largeScaleSolution);
        self::assertEqualsWithDelta(6.4E19, $largeScaleSolution->values['x'], 1.0E10);
        self::assertEqualsWithDelta(1.0E18, $largeScaleSolution->values['y'], 1.0E9);

        $nearbySolutions = $solver->solve(
            [
                [$parse('root(x,6)+root(y,6)'), $parse('3'), 0],
                [$parse('x+y'), $parse('22.796438175002'), 0],
            ],
            ['root(x,6)+root(y,6)=3', 'x+y=22.796438175002'],
            ['x', 'y'],
            [10.9, 11.9],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($nearbySolutions);
        self::assertEqualsWithDelta(10.942526586601, $nearbySolutions->values['x'], 1.0E-6);
        self::assertEqualsWithDelta(11.853911588401, $nearbySolutions->values['y'], 1.0E-6);

        $smallScaleNearbySolutions = $solver->solve(
            [
                [$parse('root(x,6)+root(y,6)'), $parse('0.003'), 0],
                [$parse('x+y'), $parse('2.2796438175002e-17'), 0],
            ],
            [
                'root(x,6)+root(y,6)=0.003',
                'x+y=2.2796438175002e-17',
            ],
            ['x', 'y'],
            [1.09E-17, 1.19E-17],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($smallScaleNearbySolutions);
        self::assertEqualsWithDelta(1.0942526586601E-17, $smallScaleNearbySolutions->values['x'], 1.0E-28);
        self::assertEqualsWithDelta(1.18539115884E-17, $smallScaleNearbySolutions->values['y'], 1.0E-28);

        self::assertNull($solver->solve(
            [
                [$parse('root(x,6)+root(y,6)'), $parse('3'), 0],
                [$parse('x-y'), $parse('730'), 0],
            ],
            ['root(x,6)+root(y,6)=3', 'x-y=730'],
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        $scaledCubeRootSystem = [
            [$parse('(x+y)*cbrt(x)-(x-y)*cbrt(y)'), $parse('11'), 0],
            [$parse('x-y'), $parse('7'), 0],
        ];
        $scaledCubeRootSolution = $solver->solve(
            $scaledCubeRootSystem,
            ['(x+y)*cbrt(x)-(x-y)*cbrt(y)=11', 'x-y=7'],
            ['x', 'y'],
            [8.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($scaledCubeRootSolution);
        self::assertEqualsWithDelta(8.0, $scaledCubeRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $scaledCubeRootSolution->values['y'], 1.0E-8);

        $repeatedRadicalSolution = $solver->solve(
            [
                [$parse('cbrt(x+y-y)+cbrt(x-y+y)+cbrt(x)+cbrt(x)'), $parse('8'), 0],
                [$parse('y'), $parse('1'), 0],
            ],
            ['cbrt(x+y-y)+cbrt(x-y+y)+cbrt(x)+cbrt(x)=8', 'y=1'],
            ['x', 'y'],
            [8.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($repeatedRadicalSolution);
        self::assertEqualsWithDelta(8.0, $repeatedRadicalSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $repeatedRadicalSolution->values['y'], 1.0E-8);

        $threeCubeRootSolution = $solver->solve(
            [
                [$parse('2*cbrt(x)+cbrt(y)+cbrt(x+y+29)'), $parse('11'), 0],
                [$parse('x-y'), $parse('-19'), 0],
            ],
            ['2*cbrt(x)+cbrt(y)+cbrt(x+y+29)=11', 'x-y=-19'],
            ['x', 'y'],
            [8.1, 27.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($threeCubeRootSolution);
        self::assertEqualsWithDelta(8.0, $threeCubeRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(27.0, $threeCubeRootSolution->values['y'], 1.0E-8);

        $negativeThreeCubeRootSolution = $solver->solve(
            [
                [$parse('cbrt(x)+cbrt(y)+cbrt(x+y+8)'), $parse('2'), 0],
                [$parse('x-y'), $parse('-2'), 0],
            ],
            ['cbrt(x)+cbrt(y)+cbrt(x+y+8)=2', 'x-y=-2'],
            ['x', 'y'],
            [-1.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($negativeThreeCubeRootSolution);
        self::assertEqualsWithDelta(-1.0, $negativeThreeCubeRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $negativeThreeCubeRootSolution->values['y'], 1.0E-8);

        $mixedRadicalSolution = $solver->solve(
            [
                [$parse('2*sqrt(x)-cbrt(y)'), $parse('3'), 0],
                [$parse('x-y'), $parse('3'), 0],
            ],
            ['2*sqrt(x)-cbrt(y)=3', 'x-y=3'],
            ['x', 'y'],
            [4.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($mixedRadicalSolution);
        self::assertEqualsWithDelta(4.0, $mixedRadicalSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $mixedRadicalSolution->values['y'], 1.0E-8);

        $fourthRootSolution = $solver->solve(
            [
                [$parse('2*root(x,4)-root(y,4)'), $parse('3'), 0],
                [$parse('x-y'), $parse('15'), 0],
            ],
            ['2*root(x,4)-root(y,4)=3', 'x-y=15'],
            ['x', 'y'],
            [16.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($fourthRootSolution);
        self::assertEqualsWithDelta(16.0, $fourthRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $fourthRootSolution->values['y'], 1.0E-8);

        foreach ([5 => 32.0, 6 => 64.0, 7 => 128.0, 8 => 256.0] as $degree => $expectedX) {
            $rootPairExpression = 'root(x,' . $degree . ')+root(y,' . $degree . ')';
            $rootPairSolution = $solver->solve(
                [
                    [$parse($rootPairExpression), $parse('3'), 0],
                    [$parse('y'), $parse('1'), 0],
                ],
                [$rootPairExpression . '=3', 'y=1'],
                ['x', 'y'],
                [$expectedX + 0.1, 1.1],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($rootPairSolution);
            self::assertEqualsWithDelta($expectedX, $rootPairSolution->values['x'], 1.0E-6);
            self::assertEqualsWithDelta(1.0, $rootPairSolution->values['y'], 1.0E-8);
        }

        $fifthDegreeFractionalPair = $solver->solve(
            [
                [$parse('x^(2/5)+y^(2/5)'), $parse('5'), 0],
                [$parse('y'), $parse('1'), 0],
            ],
            ['x^(2/5)+y^(2/5)=5', 'y=1'],
            ['x', 'y'],
            [32.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($fifthDegreeFractionalPair);
        self::assertEqualsWithDelta(32.0, $fifthDegreeFractionalPair->values['x'], 1.0E-6);
        self::assertEqualsWithDelta(1.0, $fifthDegreeFractionalPair->values['y'], 1.0E-8);

        $fourthDegreeFractionalPair = $solver->solve(
            [
                [$parse('x^(3/4)+y^(3/4)'), $parse('9'), 0],
                [$parse('y'), $parse('1'), 0],
            ],
            ['x^(3/4)+y^(3/4)=9', 'y=1'],
            ['x', 'y'],
            [16.1, 1.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($fourthDegreeFractionalPair);
        self::assertEqualsWithDelta(16.0, $fourthDegreeFractionalPair->values['x'], 1.0E-6);
        self::assertEqualsWithDelta(1.0, $fourthDegreeFractionalPair->values['y'], 1.0E-8);

        $mixedSecondAndSixthRootPair = $solver->solve(
            [
                [$parse('sqrt(x)+root(y,6)'), $parse('4'), 0],
                [$parse('y'), $parse('64'), 0],
            ],
            ['sqrt(x)+root(y,6)=4', 'y=64'],
            ['x', 'y'],
            [4.1, 64.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($mixedSecondAndSixthRootPair);
        self::assertEqualsWithDelta(4.0, $mixedSecondAndSixthRootPair->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(64.0, $mixedSecondAndSixthRootPair->values['y'], 1.0E-8);

        $mixedFourthRootSolution = $solver->solve(
            [
                [$parse('sqrt(x)+root(y,4)'), $parse('5'), 0],
                [$parse('x-y'), $parse('-7'), 0],
            ],
            ['sqrt(x)+root(y,4)=5', 'x-y=-7'],
            ['x', 'y'],
            [9.1, 16.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($mixedFourthRootSolution);
        self::assertEqualsWithDelta(9.0, $mixedFourthRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(16.0, $mixedFourthRootSolution->values['y'], 1.0E-8);

        $reversedMixedFourthRootSolution = $solver->solve(
            [
                [$parse('root(y,4)+sqrt(x)'), $parse('5'), 0],
                [$parse('x-y'), $parse('-7'), 0],
            ],
            ['root(y,4)+sqrt(x)=5', 'x-y=-7'],
            ['x', 'y'],
            [9.1, 16.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($reversedMixedFourthRootSolution);
        self::assertEqualsWithDelta(9.0, $reversedMixedFourthRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(16.0, $reversedMixedFourthRootSolution->values['y'], 1.0E-8);

        $mixedFifthRootSolution = $solver->solve(
            [
                [$parse('sqrt(x)+root(y,5)'), $parse('5'), 0],
                [$parse('y'), $parse('32'), 0],
            ],
            ['sqrt(x)+root(y,5)=5', 'y=32'],
            ['x', 'y'],
            [9.1, 32.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($mixedFifthRootSolution);
        self::assertEqualsWithDelta(9.0, $mixedFifthRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(32.0, $mixedFifthRootSolution->values['y'], 1.0E-8);

        $mixedCubeAndFourthRootSolution = $solver->solve(
            [
                [$parse('cbrt(x)+root(y,4)'), $parse('4'), 0],
                [$parse('x+y'), $parse('24'), 0],
            ],
            ['cbrt(x)+root(y,4)=4', 'x+y=24'],
            ['x', 'y'],
            [8.1, 16.1],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($mixedCubeAndFourthRootSolution);
        self::assertEqualsWithDelta(8.0, $mixedCubeAndFourthRootSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(16.0, $mixedCubeAndFourthRootSolution->values['y'], 1.0E-8);

        $rationalSystem = [
            [$parse('x/(y^2+1)'), $parse('0.2'), 0],
            [$parse('y/(x^2+1)'), $parse('1'), 0],
        ];
        $solution = $solver->solve(
            $rationalSystem,
            ['x/(y^2+1)=0.2', 'y/(x^2+1)=1'],
            ['x', 'y'],
            [0.1, 0.2],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($solution);
        self::assertLessThanOrEqual(1.0E-9, $solution->residualNorm);
        self::assertEqualsWithDelta(
            0.2,
            Math::evaluate(
                'x/(y^2+1)',
                $solution->values,
                $evaluationOptions,
            ),
            1.0E-9,
        );
        self::assertEqualsWithDelta(
            1.0,
            Math::evaluate(
                'y/(x^2+1)',
                $solution->values,
                $evaluationOptions,
            ),
            1.0E-9,
        );

        $rationalSumSystem = [
            [$parse('x/(y+1)+1/(y+1)'), $parse('1'), 0],
            [$parse('x+y'), $parse('3'), 0],
        ];
        $sumSolution = $solver->solve(
            $rationalSumSystem,
            ['x/(y+1)+1/(y+1)=1', 'x+y=3'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($sumSolution);
        self::assertEqualsWithDelta(1.5, $sumSolution->values['x'], 1.0E-9);
        self::assertEqualsWithDelta(1.5, $sumSolution->values['y'], 1.0E-9);

        $nestedQuotientSystem = [
            [$parse('1/(x/y+1)'), $parse('0.5'), 0],
            [$parse('x+y'), $parse('3'), 0],
        ];
        $nestedSolution = $solver->solve(
            $nestedQuotientSystem,
            ['1/(x/y+1)=0.5', 'x+y=3'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($nestedSolution);
        self::assertEqualsWithDelta(1.5, $nestedSolution->values['x'], 1.0E-9);
        self::assertEqualsWithDelta(1.5, $nestedSolution->values['y'], 1.0E-9);

        $negativePowerSystem = [
            [$parse('x^-1+y^-1'), $parse('1'), 0],
            [$parse('x+y'), $parse('4'), 0],
        ];
        $negativePowerSolution = $solver->solve(
            $negativePowerSystem,
            ['x^-1+y^-1=1', 'x+y=4'],
            ['x', 'y'],
            [1.0, 3.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($negativePowerSolution);
        self::assertEqualsWithDelta(2.0, $negativePowerSolution->values['x'], 1.0E-9);
        self::assertEqualsWithDelta(2.0, $negativePowerSolution->values['y'], 1.0E-9);

        foreach (
            [
                ['sqrt(x+y)', '2', 'x-y', '0'],
                ['2', 'sqrt(x+y)', 'x-y', '0'],
                ['sqrt(x+y)', 'sqrt(2*x)', 'x+y', '4'],
            ] as [$leftFirst, $rightFirst, $leftSecond, $rightSecond]
        ) {
            $squareRootSystem = [
                [$parse($leftFirst), $parse($rightFirst), 0],
                [$parse($leftSecond), $parse($rightSecond), 0],
            ];
            $squareRootSolution = $solver->solve(
                $squareRootSystem,
                [
                    $leftFirst . '=' . $rightFirst,
                    $leftSecond . '=' . $rightSecond,
                ],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($squareRootSolution);
            self::assertEqualsWithDelta(2.0, $squareRootSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta(2.0, $squareRootSolution->values['y'], 1.0E-9);
        }

        foreach (
            [
                [['cbrt(x+y)', '2', 'x-y', '0'], 4.0, 4.0],
                [['root(x+y,5)', '2', 'x-y', '0'], 16.0, 16.0],
                [['cbrt(x+y)', 'sqrt(2*x)', 'x+y', '8'], 2.0, 6.0],
                [['cbrt(x+y)', '-2', 'x-y', '0'], -4.0, -4.0],
                [['root(x+y,-3)', '0.5', 'x-y', '0'], 4.0, 4.0],
                [['2', 'root(x+y,-3)', 'x-y', '0'], 0.0625, 0.0625],
                [['root(x+y,-2)', '0.5', 'x-y', '0'], 2.0, 2.0],
            ] as [[$leftFirst, $rightFirst, $leftSecond, $rightSecond], $expectedX, $expectedY]
        ) {
            $radicalSystem = [
                [$parse($leftFirst), $parse($rightFirst), 0],
                [$parse($leftSecond), $parse($rightSecond), 0],
            ];
            $radicalSolution = $solver->solve(
                $radicalSystem,
                [
                    $leftFirst . '=' . $rightFirst,
                    $leftSecond . '=' . $rightSecond,
                ],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($radicalSolution, $leftFirst . '=' . $rightFirst);
            self::assertEqualsWithDelta($expectedX, $radicalSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta($expectedY, $radicalSolution->values['y'], 1.0E-9);
        }

        foreach (
            [
                ['sqrt(x+y)^2+x', '6', 2.0],
                ['cbrt(x+y)^3', '8', 4.0],
                ['root(x+y,-3)^-3', '8', 4.0],
            ] as [$rootExpression, $target, $expectedValue]
        ) {
            $poweredRootSystem = [
                [$parse($rootExpression), $parse($target), 0],
                [$parse('x-y'), $parse('0'), 0],
            ];
            $poweredRootSolution = $solver->solve(
                $poweredRootSystem,
                [$rootExpression . '=' . $target, 'x-y=0'],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($poweredRootSolution);
            self::assertEqualsWithDelta($expectedValue, $poweredRootSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta($expectedValue, $poweredRootSolution->values['y'], 1.0E-9);
        }

        $wrongSignSquareRootSystem = [
            [$parse('sqrt(x+y)'), $parse('-2'), 0],
            [$parse('x-y'), $parse('0'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongSignSquareRootSystem,
            ['sqrt(x+y)=-2', 'x-y=0'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        foreach (
            [
                ['abs(x+y)', '2', 'x-y', '0'],
                ['2', 'abs(x+y)', 'x-y', '0'],
                ['abs(x+y)', 'abs(x-y)', 'x+y', '4'],
            ] as [$leftFirst, $rightFirst, $leftSecond, $rightSecond]
        ) {
            $absoluteValueSystem = [
                [$parse($leftFirst), $parse($rightFirst), 0],
                [$parse($leftSecond), $parse($rightSecond), 0],
            ];
            $absoluteValueSolution = $solver->solve(
                $absoluteValueSystem,
                [
                    $leftFirst . '=' . $rightFirst,
                    $leftSecond . '=' . $rightSecond,
                ],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($absoluteValueSolution);
            self::assertLessThanOrEqual(1.0E-9, $absoluteValueSolution->residualNorm);
        }

        $wrongSignAbsoluteValueSystem = [
            [$parse('abs(x+y)'), $parse('-2'), 0],
            [$parse('x-y'), $parse('0'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongSignAbsoluteValueSystem,
            ['abs(x+y)=-2', 'x-y=0'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        foreach (
            [
                ['abs(x+y)^2+x', '6', 1.1061072252245132],
                ['abs(x+y)^-2', '0.0625', 2.0],
            ] as [$absoluteExpression, $target, $expectedValue]
        ) {
            $poweredAbsoluteSystem = [
                [$parse($absoluteExpression), $parse($target), 0],
                [$parse('x-y'), $parse('0'), 0],
            ];
            $poweredAbsoluteSolution = $solver->solve(
                $poweredAbsoluteSystem,
                [$absoluteExpression . '=' . $target, 'x-y=0'],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($poweredAbsoluteSolution);
            self::assertEqualsWithDelta($expectedValue, $poweredAbsoluteSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta($expectedValue, $poweredAbsoluteSolution->values['y'], 1.0E-9);
        }

        foreach (
            [
                [['sign(x+y)', 'x-y', 'x+y', '3'], 2.0, 1.0],
                [['sign(x+y)', 'x-y', 'x+y', '0'], 0.0, 0.0],
                [['sign(x+y)', 'x-y', 'x+y', '-3'], -2.0, -1.0],
            ] as [[$leftFirst, $rightFirst, $leftSecond, $rightSecond], $expectedX, $expectedY]
        ) {
            $signSystem = [
                [$parse($leftFirst), $parse($rightFirst), 0],
                [$parse($leftSecond), $parse($rightSecond), 0],
            ];
            $signSolution = $solver->solve(
                $signSystem,
                [
                    $leftFirst . '=' . $rightFirst,
                    $leftSecond . '=' . $rightSecond,
                ],
                ['x', 'y'],
                [1.0, 2.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($signSolution);
            self::assertEqualsWithDelta($expectedX, $signSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta($expectedY, $signSolution->values['y'], 1.0E-9);
        }

        $wrongSignSystem = [
            [$parse('sign(x+y)'), $parse('x-y'), 0],
            [$parse('x+y'), $parse('-3'), 0],
            [$parse('x-y'), $parse('1'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongSignSystem,
            ['sign(x+y)=x-y', 'x+y=-3', 'x-y=1'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        $outOfRangeSignSystem = [
            [$parse('sign(x+y)'), $parse('5'), 0],
            [$parse('x-y'), $parse('0'), 0],
        ];
        self::assertNull($solver->solve(
            $outOfRangeSignSystem,
            ['sign(x+y)=5', 'x-y=0'],
            ['x', 'y'],
            [1.0, 2.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        foreach (
            [
                ['x^(1/2)', '2', 4.0],
                ['2', 'x^(1/2)', 4.0],
                ['x^(2/3)', '4', -8.0],
                ['x^(-1/2)', '0.5', 4.0],
                ['x^(1/3)', '-2', -8.0],
            ] as [$leftFractionalPower, $rightFractionalPower, $expectedX]
        ) {
            $fractionalPowerSystem = [
                [$parse($leftFractionalPower), $parse($rightFractionalPower), 0],
                [$parse('y'), $parse('1'), 0],
            ];
            $fractionalPowerSolution = $solver->solve(
                $fractionalPowerSystem,
                [
                    $leftFractionalPower . '=' . $rightFractionalPower,
                    'y=1',
                ],
                ['x', 'y'],
                [$expectedX, 1.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );
            self::assertNotNull($fractionalPowerSolution);
            self::assertEqualsWithDelta($expectedX, $fractionalPowerSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta(1.0, $fractionalPowerSolution->values['y'], 1.0E-9);
        }

        $wrongBranchFractionalPowerSystem = [
            [$parse('x^(1/2)'), $parse('-2'), 0],
            [$parse('y'), $parse('1'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongBranchFractionalPowerSystem,
            ['x^(1/2)=-2', 'y=1'],
            ['x', 'y'],
            [4.0, 1.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        foreach (
            [
                ['(x^(1/2))^2 + y', '4', 3.0],
                ['(x^(2/3))^3', '64', -8.0],
                ['(x^(-1/2))^-2', '4', 4.0],
            ] as [$nestedFractionalPower, $target, $expectedX]
        ) {
            $nestedFractionalPowerSystem = [
                [$parse($nestedFractionalPower), $parse($target), 0],
                [$parse('y'), $parse('1'), 0],
            ];
            $nestedFractionalPowerSolution = $solver->solve(
                $nestedFractionalPowerSystem,
                [$nestedFractionalPower . '=' . $target, 'y=1'],
                ['x', 'y'],
                [$expectedX, 1.0],
                [],
                $evaluationOptions,
                $solverOptions,
            );

            self::assertNotNull($nestedFractionalPowerSolution);
            self::assertEqualsWithDelta(
                $expectedX,
                $nestedFractionalPowerSolution->values['x'],
                1.0E-9,
            );
        }

        foreach (
            [
                ['x^(1/2)+y', '4', 9.0, 1.0],
                ['sqrt(x)+y', '4', 9.0, 1.0],
                ['2*sqrt(x)+y', '7', 9.0, 1.0],
                ['sqrt(x)/2+y', '2', 9.0, 0.5],
                ['x^(2/3)+y', '3', -8.0, -1.0],
                ['x^(-1/2)+y', '1', 4.0, 0.5],
                ['y-x^(1/2)', '-2', 4.0, 0.0],
                ['y', 'x^(1/2)+1', 9.0, 4.0],
                ['cbrt(x)+y', '3', 8.0, 1.0],
                ['root(x,-3)+y', '1', 8.0, 0.5],
            ] as [$additiveFractionalPower, $target, $expectedX, $y]
        ) {
            $additiveFractionalSystem = [
                [$parse($additiveFractionalPower), $parse($target), 0],
                [$parse('y'), $parse((string) $y), 0],
            ];
            $additiveFractionalSolution = $solver->solve(
                $additiveFractionalSystem,
                [$additiveFractionalPower . '=' . $target, 'y=' . $y],
                ['x', 'y'],
                [$expectedX, $y],
                [],
                $evaluationOptions,
                $solverOptions,
            );

            self::assertNotNull($additiveFractionalSolution);
            self::assertEqualsWithDelta($expectedX, $additiveFractionalSolution->values['x'], 1.0E-9);
            self::assertEqualsWithDelta($y, $additiveFractionalSolution->values['y'], 1.0E-9);
        }

        $wrongBranchAdditiveFractionalSystem = [
            [$parse('x^(1/2)+y'), $parse('-1'), 0],
            [$parse('y'), $parse('0'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongBranchAdditiveFractionalSystem,
            ['x^(1/2)+y=-1', 'y=0'],
            ['x', 'y'],
            [1.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        $twoRadicalSystem = [
            [$parse('sqrt(x)+sqrt(y)'), $parse('3'), 0],
            [$parse('x-y'), $parse('5'), 0],
        ];
        $twoRadicalSolution = $solver->solve(
            $twoRadicalSystem,
            ['sqrt(x)+sqrt(y)=3', 'x-y=5'],
            ['x', 'y'],
            [5.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($twoRadicalSolution);
        self::assertEqualsWithDelta(49.0 / 9.0, $twoRadicalSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0 / 9.0, $twoRadicalSolution->values['y'], 1.0E-8);

        $radicalOnlySystem = [
            [$parse('sqrt(x)+sqrt(y)'), $parse('3'), 0],
            [$parse('sqrt(x)-sqrt(y)'), $parse('1'), 0],
        ];
        $radicalOnlySolution = $solver->solve(
            $radicalOnlySystem,
            ['sqrt(x)+sqrt(y)=3', 'sqrt(x)-sqrt(y)=1'],
            ['x', 'y'],
            [4.0, 1.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($radicalOnlySolution);
        self::assertEqualsWithDelta(4.0, $radicalOnlySolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $radicalOnlySolution->values['y'], 1.0E-8);

        $scaledRadicalSystem = [
            [$parse('2*sqrt(x)+3*sqrt(y)'), $parse('12'), 0],
            [$parse('x-y'), $parse('5'), 0],
        ];
        $scaledRadicalSolution = $solver->solve(
            $scaledRadicalSystem,
            ['2*sqrt(x)+3*sqrt(y)=12', 'x-y=5'],
            ['x', 'y'],
            [-1.0, -1.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($scaledRadicalSolution);
        self::assertEqualsWithDelta(9.0, $scaledRadicalSolution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(4.0, $scaledRadicalSolution->values['y'], 1.0E-8);

        $threeRadicalSystem = [
            [$parse('2*sqrt(x)-sqrt(y)+3*sqrt(x+y)'), $parse('4+3*sqrt(13)'), 0],
            [$parse('x-y'), $parse('5'), 0],
        ];
        $threeRadicalSolution = $solver->solve(
            $threeRadicalSystem,
            ['2*sqrt(x)-sqrt(y)+3*sqrt(x+y)=4+3*sqrt(13)', 'x-y=5'],
            ['x', 'y'],
            [-1.0, -1.0],
            [],
            $evaluationOptions,
            $solverOptions,
        );
        self::assertNotNull($threeRadicalSolution);
        self::assertEqualsWithDelta(9.0, $threeRadicalSolution->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(4.0, $threeRadicalSolution->values['y'], 1.0E-7);

        $wrongBranchThreeRadicalSystem = [
            [$parse('sqrt(x)+sqrt(y)+sqrt(x+y)'), $parse('-5-sqrt(13)'), 0],
            [$parse('x-y'), $parse('5'), 0],
        ];
        self::assertNull($solver->solve(
            $wrongBranchThreeRadicalSystem,
            ['sqrt(x)+sqrt(y)+sqrt(x+y)=-5-sqrt(13)', 'x-y=5'],
            ['x', 'y'],
            [9.0, 4.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));

        $poleSystem = [
            [$parse('x/y'), $parse('0'), 0],
            [$parse('y'), $parse('0'), 0],
        ];
        self::assertNull($solver->solve(
            $poleSystem,
            ['x/y=0', 'y=0'],
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $evaluationOptions,
            $solverOptions,
        ));
    }

    public function testSystemResidualsRecoverFiniteScaledArithmetic(): void
    {
        $overflowRatio = Math::solveSystem(
            ['(1e200*1e200)/(1e200*1e200)*x=1'],
            ['x' => 0.0],
        );

        self::assertNotNull($overflowRatio);
        self::assertEqualsWithDelta(1.0, $overflowRatio->values['x'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $overflowRatio->residualNorm);

        $cancelledOffset = Math::solveSystem(
            ['(1e200+1e200)-(1e200+1e200-1e190)+x=2e190'],
            ['x' => 0.0],
        );

        self::assertNotNull($cancelledOffset);
        self::assertEqualsWithDelta(1.0E190, $cancelledOffset->values['x'], 1.0E181);
        self::assertLessThanOrEqual(1.0E180, $cancelledOffset->residualNorm);
    }

    public function testScalarPolynomialSystemReducesExactCommonFactors(): void
    {
        $solution = Math::solveSystem(
            ['(x^2-2)^6*(x^2+1)^2 = 0', 'x^2-2 = 0'],
            ['x' => -1.41],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(-\sqrt(2.0), $solution->values['x'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testTriangularSolverIsolatesVariablesFromAdditivePolynomialEquations(): void
    {
        $solution = Math::solveSystem(
            ['x + z^2 = 1', 'y + x^2 = 1', 'z + y^2 = 1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        foreach ($solution->values as $value) {
            self::assertEqualsWithDelta((\sqrt(5.0) - 1.0) / 2.0, $value, 1.0E-12);
        }
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testTriangularSolverIsolatesVariablesFromMultiplicativePolynomialEquations(): void
    {
        $solution = Math::solveSystem(
            ['x*y=4', 'y*z=8', 'x^2+y^2+z^2=24'],
            ['x' => 1.9, 'y' => 2.1, 'z' => 3.9],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(4.0, $solution->values['z'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testTriangularSolverFindsFarOffsetGaussianBranchFromOrigin(): void
    {
        $solution = Math::solveSystem(
            ['exp(-((x - 100)^2)) = y', 'y = z^2', 'z = 0.5'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(100.0 - \sqrt(\log(4.0)), $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(0.25, $solution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(0.5, $solution->values['z'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testNonlinearSystemIsInvariantToOpposingExtremeEquationScales(): void
    {
        $expectedX = 0.7;
        $expectedY = 1.2;
        $firstTarget = \sin($expectedX) + ($expectedY ** 2);
        $secondTarget = \cos($expectedY) + ($expectedX * $expectedY);

        foreach ([[1.0E-200, 1.0E200], [1.0E200, 1.0E-200]] as [$firstScale, $secondScale]) {
            $equations = [
                \sprintf('%.17g * (sin(x) + y^2) = %.17g', $firstScale, $firstScale * $firstTarget),
                \sprintf('%.17g * (cos(y) + x*y) = %.17g', $secondScale, $secondScale * $secondTarget),
            ];
            $solution = Math::solveSystem($equations, ['x' => 0.0, 'y' => 0.0]);

            self::assertNotNull($solution);
            self::assertEqualsWithDelta($expectedX, $solution->values['x'], 1.0E-10);
            self::assertEqualsWithDelta($expectedY, $solution->values['y'], 1.0E-10);
            self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E200);
        }
    }

    public function testTriangularSolverIsolatesVariablesFromRationalEquations(): void
    {
        $solution = Math::solveSystem(
            ['x/(y+1)=2', 'z/(y+1)=3', 'x^2+y^2+z^2=14'],
            ['x' => 1.9, 'y' => 0.01, 'z' => 2.9],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        $y = (-13.0 + \sqrt(183.0)) / 14.0;
        self::assertEqualsWithDelta($y, $solution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(2.0 * (1.0 + $y), $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(3.0 * (1.0 + $y), $solution->values['z'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testTriangularSolverKeepsZeroFactorBranchesWhenDividingProducts(): void
    {
        $solution = Math::solveSystem(
            ['x*y=0', 'x+y+z=0', 'x^2+y^2+z^2=0'],
            ['x' => 0.4, 'y' => -0.4, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(0.0, $solution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(0.0, $solution->values['z'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testSquareLinearizationSolvesCoupledEvenPowerSystems(): void
    {
        $solution = Math::solveSystem(
            ['(-x)^2+y^2=1', 'y^2+z^2=1', 'z^2+x^2=1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        foreach ($solution->values as $value) {
            self::assertEqualsWithDelta(\sqrt(0.5), \abs($value), 1.0E-12);
        }
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);

        $negativeSolution = Math::solveSystem(
            ['(-x)^2+y^2=1', 'y^2+z^2=1', 'z^2+x^2=1'],
            ['x' => -0.1, 'y' => -0.1, 'z' => -0.1],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        self::assertNotNull($negativeSolution);
        foreach ($negativeSolution->values as $value) {
            self::assertEqualsWithDelta(-\sqrt(0.5), $value, 1.0E-12);
        }
    }

    public function testPowerLinearizationSolvesCoupledOddPowerSystems(): void
    {
        $solution = Math::solveSystem(
            ['x^3+y^3=1', 'y^3+z^3=1', 'z^3+x^3=1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        foreach ($solution->values as $value) {
            self::assertEqualsWithDelta(\pow(0.5, 1.0 / 3.0), $value, 1.0E-12);
        }
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);

        $negativeSolution = Math::solveSystem(
            ['x^3+y^3=-1', 'y^3+z^3=-1', 'z^3+x^3=-1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        self::assertNotNull($negativeSolution);
        foreach ($negativeSolution->values as $value) {
            self::assertEqualsWithDelta(-\pow(0.5, 1.0 / 3.0), $value, 1.0E-12);
        }
        self::assertLessThanOrEqual(1.0E-12, $negativeSolution->residualNorm);
    }

    public function testPowerLinearizationSupportsHigherEvenPowers(): void
    {
        $solution = Math::solveSystem(
            ['x^4+y^4=1', 'y^4+z^4=1', 'z^4+x^4=1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        foreach ($solution->values as $value) {
            self::assertEqualsWithDelta(\pow(0.5, 1.0 / 4.0), $value, 1.0E-12);
        }
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testSymmetricMixedPowerSumsAreSolvedFromNewtonIdentities(): void
    {
        $solution = Math::solveSystem(
            [
                'x^2+y^4+z^6=84',
                'x^4+y^8+z^12=4368',
                'x^6+y^12+z^18=266304',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['z'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);

        $repeatedRootSolution = Math::solveSystem(
            [
                'x^2+y^4+z^6=72',
                'x^4+y^8+z^12=4128',
                'x^6+y^12+z^18=262272',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($repeatedRootSolution);
        self::assertEqualsWithDelta(2.0, $repeatedRootSolution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(2.0), $repeatedRootSolution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(2.0, $repeatedRootSolution->values['z'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $repeatedRootSolution->residualNorm);

        $scaledSolution = Math::solveSystem(
            [
                '2*x^2+2*y^4+2*z^6=168',
                '3*x^4+3*y^8+3*z^12=13104',
                '0.5*x^6+0.5*y^12+0.5*z^18=133152',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($scaledSolution);
        self::assertEqualsWithDelta(2.0, $scaledSolution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $scaledSolution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $scaledSolution->values['z'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $scaledSolution->residualNorm);

        $signedSolution = Math::solveSystem(
            ['x+y^3=7', 'x^2+y^6=65'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($signedSolution);
        self::assertEqualsWithDelta(-1.0, $signedSolution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $signedSolution->values['y'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $signedSolution->residualNorm);

        $parameterCoefficientSolution = Math::solveSystem(
            [
                'a*x^2+a*y^4+a*z^6=84*a',
                'a*x^4+a*y^8+a*z^12=4368*a',
                'a*x^6+a*y^12+a*z^18=266304*a',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            ['a' => 2.0],
        );

        self::assertNotNull($parameterCoefficientSolution);
        self::assertEqualsWithDelta(2.0, $parameterCoefficientSolution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $parameterCoefficientSolution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $parameterCoefficientSolution->values['z'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $parameterCoefficientSolution->residualNorm);

        $overdeterminedSolution = Math::solveSystem(
            [
                'x^2+y^4+z^6=84',
                'x^4+y^8+z^12=4368',
                'x^6+y^12+z^18=266304',
                'x^8+y^16+z^24=16843008',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($overdeterminedSolution);
        self::assertEqualsWithDelta(2.0, $overdeterminedSolution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $overdeterminedSolution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $overdeterminedSolution->values['z'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $overdeterminedSolution->residualNorm);

        $inconsistentOverdeterminedSolution = Math::solveSystem(
            [
                'x^2+y^4+z^6=84',
                'x^4+y^8+z^12=4368',
                'x^6+y^12+z^18=266304',
                'x^8+y^16+z^24=16843009',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNull($inconsistentOverdeterminedSolution);

        $repeatedMomentSolution = Math::solveSystem(
            [
                'x+y+z=6',
                'x^2+y^2+z^2=14',
                'x^3+y^3+z^3=36',
                '2*x+2*y+2*z=12',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($repeatedMomentSolution);
        $repeatedMomentValues = \array_values($repeatedMomentSolution->values);
        \sort($repeatedMomentValues, SORT_NUMERIC);
        foreach ([1.0, 2.0, 3.0] as $index => $expectedValue) {
            self::assertEqualsWithDelta($expectedValue, $repeatedMomentValues[$index], 1.0E-10);
        }
        self::assertLessThanOrEqual(1.0E-10, $repeatedMomentSolution->residualNorm);
    }

    public function testSymmetricPowerSumsRejectContradictoryRepeatedReconstructiveMoments(): void
    {
        $options = new EvaluationOptions();
        $equations = [
            'x+y+z=6',
            'x^2+y^2+z^2=14',
            'x^3+y^3+z^3=36',
            '2*x+2*y+2*z=14',
        ];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsedEquations = [];
        foreach ($equations as $equation) {
            [$left, $right] = \explode('=', $equation, 2);
            $parsedEquations[] = [$parseSide(\trim($left)), $parseSide(\trim($right)), 0];
        }

        $solution = (new SymmetricPowerSumSystemSolver())->solve(
            $parsedEquations,
            ['x', 'y', 'z'],
            [0.0, 0.0, 0.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNull($solution);
    }

    public function testSymmetricPowerSumsNormalizeVerySmallAliasScales(): void
    {
        $solution = Math::solveSystem(
            [
                'x+y^2+z^3=6e-20',
                'x^2+y^4+z^6=1.4e-39',
                'x^3+y^6+z^9=3.6e-59',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0e-20, $solution->values['x'], 1.0e-30);
        self::assertEqualsWithDelta(\sqrt(2.0e-20), $solution->values['y'], 1.0e-20);
        self::assertEqualsWithDelta(\pow(3.0e-20, 1.0 / 3.0), $solution->values['z'], 1.0e-16);
        self::assertLessThanOrEqual(1.0e-34, $solution->residualNorm);

        $cancelledFirstMoment = Math::solveSystem(
            [
                'x+y^2+z^3=0',
                'x^2+y^4+z^6=1.4e-39',
                'x^3+y^6+z^9=-1.8e-59',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($cancelledFirstMoment);
        self::assertEqualsWithDelta(-3.0e-20, $cancelledFirstMoment->values['x'], 1.0e-30);
        self::assertEqualsWithDelta(1.0e-10, $cancelledFirstMoment->values['y'], 1.0e-20);
        self::assertEqualsWithDelta(\pow(2.0e-20, 1.0 / 3.0), $cancelledFirstMoment->values['z'], 1.0e-16);
        self::assertLessThanOrEqual(1.0e-34, $cancelledFirstMoment->residualNorm);
    }

    public function testSymmetricPowerSumsRecoverFourDistinctVariables(): void
    {
        $solution = Math::solveSystem(
            [
                'x+y+z+w=30',
                'x^2+y^2+z^2+w^2=354',
                'x^3+y^3+z^3+w^3=4890',
                'x^4+y^4+z^4+w^4=72354',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'w' => 0.0],
        );

        self::assertNotNull($solution);
        foreach (['x' => 1.0, 'y' => 4.0, 'z' => 9.0, 'w' => 16.0] as $name => $expectedValue) {
            self::assertEqualsWithDelta($expectedValue, $solution->values[$name], 1.0E-9);
        }
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testSymmetricPowerSumsNormalizeDifferentPowersAcrossFourVariables(): void
    {
        $solution = Math::solveSystem(
            [
                'x+y^2+z^3+w^4=30',
                'x^2+y^4+z^6+w^8=354',
                'x^3+y^6+z^9+w^12=4890',
                'x^4+y^8+z^12+w^16=72354',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'w' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(\pow(9.0, 1.0 / 3.0), $solution->values['z'], 1.0E-10);
        self::assertEqualsWithDelta(2.0, $solution->values['w'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testSymmetricPowerSumsResolveClusteredRootsWithStrictResidualTolerance(): void
    {
        $solution = Math::solveSystem(
            [
                'x+y+z=4.000001',
                'x^2+y^2+z^2=6.000002000001',
                'x^3+y^3+z^3=10.000003000003',
            ],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(residualTolerance: 1.0E-14),
        );

        self::assertNotNull($solution);
        $values = \array_values($solution->values);
        \sort($values, SORT_NUMERIC);
        foreach ([1.0, 1.000001, 2.0] as $index => $expectedValue) {
            self::assertEqualsWithDelta($expectedValue, $values[$index], 1.0E-8);
        }
        self::assertLessThanOrEqual(2.0E-14, $solution->residualNorm);
    }

    public function testPowerLinearizationPreservesNegatedOddPowerBases(): void
    {
        $solution = Math::solveSystem(
            ['(-x)^3=1'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertSame(-1.0, $solution->values['x']);
        self::assertSame(0.0, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testResultantSolvesTangentialQuadraticSystemWithinOneIteration(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 1', 'x*y = 0.5'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['x']), 1.0E-12);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['y']), 1.0E-12);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testResultantUsesUnivariateConstraintsInOverdeterminedSystems(): void
    {
        $solution = Math::solveSystem(
            ['x^2 = 0.5', 'x*y = 0.5', 'y = x'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['x']), 1.0E-12);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['y']), 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testBivariateResultantEliminatesCubicAgainstLinearConstraint(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y^3 + x^2 = 0', 'y + x^2 = 0.5'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $options,
            new SolverOptions(maxIterations: 10),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['y'] + ($solution->values['x'] ** 2) - 0.5, 1.0E-9);
        self::assertEqualsWithDelta(0.0, ($solution->values['y'] ** 3) + ($solution->values['x'] ** 2), 1.0E-9);
        self::assertLessThanOrEqual(1.0E-9, $solution->residualNorm);
    }

    public function testBivariateResultantEliminatesTwoCubicConstraints(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y^3 + x^2 = 0', 'y^3 + x = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, -1.0],
            [],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSupportsSylvesterMatricesLargerThanTwelve(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^7 + y^6 = 0', 'x^6 + y^7 = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [-0.9, -0.9],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(-1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSupportsSylvesterMatrixSizeFourteen(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^7 + y^7 = 2', 'x^7 + y^7 + x*y = 3'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, 1.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantCancelsSharedHighDegreeTermsBeforeElimination(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^10 + y^10 = 2', 'x^10 + y^10 + x*y = 3'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, 1.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantCancelsPolynomialDivisibleLeadingCoefficients(): void
    {
        $options = new EvaluationOptions();
        $equations = ['(x + 1)*y^10 = 2', '(x^2 - 1)*y^10 + x*y = 2*x - 1'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, 1.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSupportsSylvesterMatrixSizeSixteen(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^8 + y^8 = 2', 'x^8 + y^8 + x*y = 3'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, 1.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSupportsSylvesterMatrixSizeEighteen(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^9 + y^9 = 2', 'x^9 + y^9 + x*y = 3'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, 1.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testBivariateResultantReducesRepeatedPolynomialFactors(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x^2 + y^4 = 2', 'x^5 + y^6 = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [-0.97, -1.03],
            [],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(-1.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantEliminatesMixedCubicAndQuarticConstraints(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y^3 + x = 0', 'y^4 + x = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [-1.0, 1.0],
            [],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertSame(-1.0, $solution->values['x']);
        self::assertSame(1.0, $solution->values['y']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSkipsIdenticallyZeroConstraints(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x = x', 'y = x'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        self::assertNull((new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $options,
            new SolverOptions(),
        ));
    }

    public function testBivariateResultantSwapsEliminationOrderWhenItReducesDegree(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y^7 + x = 0', 'y^7 + 2*x = 1'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [1.0, -1.0],
            ['unused' => 2.0],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertSame(['x', 'y'], array_keys($solution->values));
        self::assertSame(1.0, $solution->values['x']);
        self::assertSame(-1.0, $solution->values['y']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantHandlesVanishingLeadingCoefficients(): void
    {
        $options = new EvaluationOptions();
        $equations = ['x*y + x = 0', 'x*y + y = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertSame(0.0, $solution->values['x']);
        self::assertSame(0.0, $solution->values['y']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateResultantSupportsTotalDegreeAboveEight(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y + x^9 = 0', 'y^2 + x = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [-1.0, 1.0],
            [],
            $options,
            new SolverOptions(),
        );

        self::assertNotNull($solution);
        self::assertSame(-1.0, $solution->values['x']);
        self::assertSame(1.0, $solution->values['y']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateLinearResultantSupportsTotalDegreeThirteen(): void
    {
        $options = new EvaluationOptions();
        $equations = ['y + x^13 = 0', 'y - 1 = 0'];
        $parseSide = static fn (string $source) => (new Parser(
            (new Lexer($source, $options->limits))->tokenize(),
            $options->limits,
        ))->parse();
        $parsed = [];
        foreach ($equations as $equation) {
            [$left, $right] = explode('=', $equation, 2);
            $parsed[] = [$parseSide(trim($left)), $parseSide(trim($right)), 0];
        }

        $solution = (new BivariatePolynomialSystemSolver())->solve(
            $parsed,
            $equations,
            ['x', 'y'],
            [0.0, 0.0],
            [],
            $options,
            new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertSame(-1.0, $solution->values['x']);
        self::assertSame(1.0, $solution->values['y']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testSolvesSystemsWithSubscriptedVariables(): void
    {
        $solution = Math::solveSystem(
            ['x₁ + y₂ = 3', 'x₁ - y₂ = 1'],
            ['x_1' => 0.0, 'y_2' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertSame(2.0, $solution->values['x_1']);
        self::assertSame(1.0, $solution->values['y_2']);
        self::assertSame(0, $solution->iterations);
    }

    public function testExactAffineSolvePreservesNearSingularDecimalCoefficients(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 2', 'x + 1.00000000000001*y = 2.00000000000001'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertSame(1.0, $solution->values['x']);
        self::assertSame(1.0, $solution->values['y']);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
    }

    public function testExactAffineSolveFoldsConstantFunctionsAndSpecialValues(): void
    {
        $solution = Math::solveSystem(
            [
                'sqrt(2)*x + sin(1)*y = sqrt(2) + 2*sin(1)',
                'pi*x + 3!*y = pi + 12',
            ],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-12);
        self::assertSame(0, $solution->iterations);
    }

    public function testExactAffineSolveFoldsRegisteredConstantFunctions(): void
    {
        $double = new FunctionDefinition(
            'doublevalue',
            1,
            1,
            static fn (array $arguments): float => 2.0 * $arguments[0],
        );
        $solution = Math::solveSystem(
            ['doublevalue(1)*x + y = 4', 'x + 2*y = 5'],
            ['x' => 0.0, 'y' => 0.0],
            evaluationOptions: new EvaluationOptions(
                functions: FunctionRegistry::defaults()->with($double),
            ),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-12);
        self::assertSame(0, $solution->iterations);
    }

    public function testSolvesNonlinearSystemLocally(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 5', 'x - y = 1'],
            ['x' => 1.5, 'y' => 0.5],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-7);
    }

    public function testSeparableExponentialSystemCanReachRootsWithExtremeScales(): void
    {
        $solution = Math::solveSystem(
            ['exp(x) = 1e100', 'exp(y) = 1e-100'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(100.0 * \log(10.0), $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(-100.0 * \log(10.0), $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E100);
    }

    public function testLinearSystemsInExponentialVariablesSolveAtExtremeScales(): void
    {
        $solution = Math::solveSystem(
            ['exp(y) + exp(x) = e^100', 'exp(y) - exp(x) = e^99'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            \log((\exp(100.0) + \exp(99.0)) / 2.0),
            $solution->values['y'],
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            \log((\exp(100.0) - \exp(99.0)) / 2.0),
            $solution->values['x'],
            1.0E-12,
        );
        self::assertLessThanOrEqual(1.0E-14, $solution->residualNorm / \exp(100.0));
    }

    public function testLinearSystemsInConstantBasePowersSolveAtExtremeScales(): void
    {
        $solution = Math::solveSystem(
            ['2^y + 2^x = e^100', '2^y - 2^x = e^99'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            \log((\exp(100.0) - \exp(99.0)) / 2.0) / \log(2.0),
            $solution->values['x'],
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            \log((\exp(100.0) + \exp(99.0)) / 2.0) / \log(2.0),
            $solution->values['y'],
            1.0E-12,
        );
        self::assertLessThanOrEqual(1.0E-14, $solution->residualNorm / \exp(100.0));
    }

    public function testExponentialCoordinatesAllowDifferentBasesPerVariable(): void
    {
        $solution = Math::solveSystem(
            ['3^y + 2^x = e^100', '3^y - 2^x = e^99'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            \log((\exp(100.0) - \exp(99.0)) / 2.0) / \log(2.0),
            $solution->values['x'],
            1.0E-12,
        );
        self::assertEqualsWithDelta(
            \log((\exp(100.0) + \exp(99.0)) / 2.0) / \log(3.0),
            $solution->values['y'],
            1.0E-12,
        );
        self::assertLessThanOrEqual(1.0E-14, $solution->residualNorm / \exp(100.0));
    }

    public function testPolynomialSystemsInExponentialVariablesSelectPositiveBranches(): void
    {
        $solution = Math::solveSystem(
            ['exp(x)^2 + exp(y)^2 = e^100', 'exp(x) - exp(y) = 0'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(50.0 - (0.5 * \log(2.0)), $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta($solution->values['x'], $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-14, $solution->residualNorm / \exp(100.0));
    }

    public function testExponentialPolynomialBranchesAreRankedInOriginalCoordinates(): void
    {
        $solution = Math::solveSystem(
            ['(exp(x) - 1)*(exp(x) - 100) = 1', 'exp(y) = 1'],
            ['x' => \log(20.0), 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        $nearestPositiveExponentialRoot = (101.0 + \sqrt(9805.0)) / 2.0;
        self::assertEqualsWithDelta(\log($nearestPositiveExponentialRoot), $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(0.0, $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testExponentialPolynomialCandidatesMustSatisfyEveryConstraint(): void
    {
        $solution = Math::solveSystem(
            [
                'exp(x)^2 + exp(y)^2 = e^100',
                'exp(x) - exp(y) = 0',
                'exp(x) = 2',
            ],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNull($solution);
    }

    public function testScalarPolynomialInExponentialVariableUsesScaledRootIsolation(): void
    {
        $solution = Math::solveSystem(
            ['exp(x)^2 + exp(x)^3 = e^100'],
            ['x' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(100.0 / 3.0, $solution->values['x'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm / \exp(100.0));
    }

    public function testScalarExponentialRootsUseOriginalInitialCoordinateAfterOverflow(): void
    {
        $solution = Math::solveSystem(
            ['(exp(x) - 1)*(exp(x) - 100) = 1'],
            ['x' => 1000.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\log((101.0 + \sqrt(9805.0)) / 2.0), $solution->values['x'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testScalarExponentialRootsUseOriginalCoordinateForSubunitBases(): void
    {
        $solution = Math::solveSystem(
            ['(0.5^x - 1)*(0.5^x - 100) = 1'],
            ['x' => -1000.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            \log((101.0 + \sqrt(9805.0)) / 2.0) / \log(0.5),
            $solution->values['x'],
            1.0E-10,
        );
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testCoupledExponentialAffineSystemUsesLogConstraints(): void
    {
        $solution = Math::solveSystem(
            ['exp(x + y) = 1e100', 'exp(x - y) = 1e-100'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(100.0 * \log(10.0), $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E100);
    }

    public function testCoupledScaledExponentialTermsUseTheirConstantMultipliers(): void
    {
        $solution = Math::solveSystem(
            ['2*exp(x + y) = 2e100', '3*exp(x - y) = 3e-100'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(100.0 * \log(10.0), $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E100);
    }

    public function testCoupledExponentialsWithPolynomialPhasesUseResultantReduction(): void
    {
        $solution = Math::solveSystem(
            ['exp(x^2 + y) = e^3', 'exp(x - y) = e'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\sqrt(17.0) / 2.0 - 0.5, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta($solution->values['x'] - 1.0, $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-8, $solution->residualNorm);
    }

    public function testExponentialPolynomialReductionPreservesRepeatedResultantRoots(): void
    {
        $solution = Math::solveSystem(
            ['exp((x^2 - 2)^2) = 1', 'exp(y) = e'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\sqrt(2.0), \abs($solution->values['x']), 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testProductsAndQuotientsOfExponentialsCombineTheirPhases(): void
    {
        $solution = Math::solveSystem(
            [
                'exp(x + y)*exp(x - y) = e^100',
                'exp(x + y)/exp(x - y) = e^50',
            ],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(50.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(25.0, $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testReciprocalExponentialTermsNegateTheirPhases(): void
    {
        $solution = Math::solveSystem(
            ['1/exp(x + y) = e^100', '1/exp(x - y) = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(-75.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-25.0, $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testReciprocalScaledExponentialTermsPreserveTheirMultipliers(): void
    {
        $solution = Math::solveSystem(
            ['1/(2*exp(x + y)) = e^100', '1/(3*exp(x - y)) = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            -75.0 - (0.5 * \log(6.0)),
            $solution->values['x'],
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            -25.0 + (0.5 * \log(3.0 / 2.0)),
            $solution->values['y'],
            1.0E-10,
        );
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testExpOfLogPolynomialConstraintsUseResultantReduction(): void
    {
        $solution = Math::solveSystem(
            ['exp(ln(x^2)) = e^100', 'exp(ln(y^2)) = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs((\abs($solution->values['x']) / \exp(50.0)) - 1.0),
        );
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs((\abs($solution->values['y']) / \exp(25.0)) - 1.0),
        );
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testPoweredExpOfLogConstraintsUseSeparablePolynomialReduction(): void
    {
        $solution = Math::solveSystem(
            ['(exp(ln(x^2)))^2 = e^100', 'exp(ln(y^2)) = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs((\abs($solution->values['x']) / \exp(25.0)) - 1.0),
        );
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs((\abs($solution->values['y']) / \exp(25.0)) - 1.0),
        );
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testScalarExpOfLogConstraintsUsePolynomialReduction(): void
    {
        $solution = Math::solveSystem(
            ['(exp(ln(x^2)))^2 = e^100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs((\abs($solution->values['x']) / \exp(25.0)) - 1.0),
        );
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testConstantBaseLogarithmExponentCompositionsReduceBothWays(): void
    {
        $exponentialOfLogarithm = Math::solveSystem(
            ['2^log(x, 2) = e^100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        $logarithmOfExponential = Math::solveSystem(
            ['log(2^x, 2) = 100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        $outsideLogDomain = Math::solveSystem(
            ['2^log(x, 2) = -1'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($exponentialOfLogarithm);
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs(($exponentialOfLogarithm->values['x'] / \exp(100.0)) - 1.0),
        );
        self::assertNotNull($logarithmOfExponential);
        self::assertEqualsWithDelta(100.0, $logarithmOfExponential->values['x'], 1.0E-10);
        self::assertNull($outsideLogDomain);
    }

    public function testStandardLogarithmExponentCompositionsReduceBothWays(): void
    {
        $naturalBase = Math::solveSystem(
            ['e^ln(x) = e^100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        $baseTen = Math::solveSystem(
            ['log10(10^x) = 100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );
        $baseTwo = Math::solveSystem(
            ['log2(2^x) = 100'],
            ['x' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($naturalBase);
        self::assertLessThanOrEqual(
            1.0E-14,
            \abs(($naturalBase->values['x'] / \exp(100.0)) - 1.0),
        );
        self::assertNotNull($baseTen);
        self::assertEqualsWithDelta(100.0, $baseTen->values['x'], 1.0E-10);
        self::assertNotNull($baseTwo);
        self::assertEqualsWithDelta(100.0, $baseTwo->values['x'], 1.0E-10);
    }

    public function testPowersOfExponentialsReduceToScaledPhases(): void
    {
        $solution = Math::solveSystem(
            ['exp(x + y)^2 = e^100', 'exp(x - y)^2 = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(37.5, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(12.5, $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / \exp(100.0));
    }

    public function testSeparableExponentialPolynomialSystemsScaleBeyondTwoVariables(): void
    {
        $solution = Math::solveSystem(
            ['exp(x)^2 = 4', 'exp(y)^2 = 9', 'exp(z)^2 = 16'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\log(2.0), $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(\log(3.0), $solution->values['y'], 1.0E-10);
        self::assertEqualsWithDelta(\log(4.0), $solution->values['z'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testPositiveConstantBasesReduceToExponentialPhases(): void
    {
        $solution = Math::solveSystem(
            ['2^(x + y) = 1e100', '2^(x - y) = 1e50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(75.0 * \log(10.0) / \log(2.0), $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(25.0 * \log(10.0) / \log(2.0), $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E100);
    }

    public function testNegativeConstantBasesRetainIntegerExponentDomainChecks(): void
    {
        $solution = Math::solveSystem(
            ['(-2)^(x + y) = 4', '(-2)^(x - y) = -8'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.5, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-0.5, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testNegativeBaseMagnitudeReductionHandlesSignedMultipliers(): void
    {
        $solution = Math::solveSystem(
            ['-2*(-2)^(x + y) = 16', '(-2)^(x - y) = -32'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(4.0, $solution->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $solution->values['y'], 1.0E-10);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testRationalPowerConstantCanScaleAnExponentialSystem(): void
    {
        $solution = Math::solveSystem(
            ['(-8)^(1/3)*exp(x + y) = -2e100', 'exp(x - y) = e^50'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(50.0 * \log(10.0) + 25.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(50.0 * \log(10.0) - 25.0, $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm / 1.0E100);
    }

    public function testNonlinearRestartFindsPowellSingularRootFromDifficultStart(): void
    {
        $solution = Math::solveSystem(
            [
                'x + 10*y = 0',
                'sqrt(5)*(z-w) = 0',
                '(y-2*z)^2 = 0',
                'sqrt(10)*(x-w)^2 = 0',
            ],
            ['x' => 3.0, 'y' => -1.0, 'z' => 0.0, 'w' => 1.0],
        );

        self::assertNotNull($solution);
        foreach ($solution->values as $value) {
            self::assertEqualsWithDelta(0.0, $value, 1.0E-7);
        }
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testSystemRefinesNearTangentRootBeyondResidualTolerance(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 1', 'x * y = 0.5'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(-1.0 / \sqrt(2.0), $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(-1.0 / \sqrt(2.0), $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testImplicitVariableProductsFeedTheSystemJacobian(): void
    {
        $solution = Math::solveSystem(
            ['x(y + 1) = 8', 'x - y = 0'],
            ['x' => 2.0, 'y' => 2.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.3722813232690143, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta($solution->values['x'], $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-9, $solution->residualNorm);
    }

    public function testSystemSolverRecoversFromSingularZeroCoordinateStarts(): void
    {
        foreach (
            [
                ['x' => -5.0, 'y' => 0.0],
                ['x' => 0.0, 'y' => -5.0],
            ] as $initialValues
        ) {
            $solution = Math::solveSystem(
                ['x * y = 1', 'x + y = 3'],
                $initialValues,
            );

            self::assertNotNull($solution);
            self::assertEqualsWithDelta(3.0, $solution->values['x'] + $solution->values['y'], 1.0E-8);
            self::assertEqualsWithDelta(1.0, $solution->values['x'] * $solution->values['y'], 1.0E-8);
            self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
        }
    }

    public function testSevereScaleSystemFindsTinyCoordinateFromZeroStart(): void
    {
        $solution = Math::solveSystem(
            [
                '(x - 1.0e20)^4 + (y + 1.0e-20)^4 = 0',
                'x + y = 1.0e20',
            ],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E20, $solution->values['x'], 1.0E7);
        self::assertEqualsWithDelta(-1.0E-20, $solution->values['y'], 1.0E-30);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testSevereScaleSystemFindsTinyFirstCoordinateFromZeroStart(): void
    {
        $solution = Math::solveSystem(
            [
                '(x - 1.0e-20)^4 + (y - 1.0e20)^4 = 0',
                'x + y = 1.0e20',
            ],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E-20, $solution->values['x'], 1.0E-30);
        self::assertEqualsWithDelta(1.0E20, $solution->values['y'], 1.0E7);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testSparseScaleSeedsUseIndependentVariableMagnitudes(): void
    {
        foreach (
            [
                [1.0E12, 1.0E6, 2, 4],
                [1.0E12, -1.0E6, 6, 2],
                [-1.0E12, 1.0E6, 4, 6],
                [-1.0E12, -1.0E6, 2, 6],
                [1.0E12, 1.0E-12, 6, 4],
                [1.0E-12, 1.0E12, 4, 2],
            ] as [$expectedX, $expectedY, $xPower, $yPower]
        ) {
            $solution = Math::solveSystem(
                [
                    \sprintf(
                        '(x - (%.17g))^%d + (y - (%.17g))^%d = 0',
                        $expectedX,
                        $xPower,
                        $expectedY,
                        $yPower,
                    ),
                    \sprintf('x + y = %.17g', $expectedX + $expectedY),
                ],
                ['x' => 0.0, 'y' => 0.0],
            );

            self::assertNotNull($solution);
            self::assertEqualsWithDelta(
                $expectedX,
                $solution->values['x'],
                \max(1.0E-30, \abs($expectedX) * 1.0E-10),
            );
            self::assertEqualsWithDelta(
                $expectedY,
                $solution->values['y'],
                \max(1.0E-30, \abs($expectedY) * 1.0E-10),
            );
            self::assertSame(0.0, $solution->residualNorm);
        }
    }

    public function testCustomFunctionGradientFeedsSystemJacobian(): void
    {
        $product = new FunctionDefinition(
            'product',
            2,
            2,
            static fn (array $arguments): float => $arguments[0] * $arguments[1],
            static fn (array $arguments): array => [$arguments[1], $arguments[0]],
        );
        $solution = Math::solveSystem(
            ['product(x, y) = 1', 'x - y = 0'],
            ['x' => 0.8, 'y' => 1.2],
            evaluationOptions: new EvaluationOptions(
                functions: FunctionRegistry::defaults()->with($product),
            ),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testAnalyticJacobianAvoidsAliasingRapidPeriodicPhases(): void
    {
        $solution = Math::solveSystem(
            ['sin(1e10*x) + y = 1.5', 'x + y = 1 + pi/(6e10)'],
            ['x' => 0.0, 'y' => 1.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\M_PI / 6.0E10, $solution->values['x'], 1.0E-16);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testLogarithmicStepsSolveSystemsWithWidelySeparatedVariableScales(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 1e12', 'x * y = 1'],
            ['x' => 1.0E6, 'y' => 1.0E-6],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E12, $solution->values['x'], 1.0E-3);
        self::assertEqualsWithDelta(1.0E-12, $solution->values['y'], 1.0E-20);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);

        $negativeVariable = Math::solveSystem(
            ['x + y = 1e12', 'x * y = -1'],
            ['x' => 1.0E6, 'y' => -1.0E-6],
        );

        self::assertNotNull($negativeVariable);
        self::assertEqualsWithDelta(1.0E12, $negativeVariable->values['x'], 1.0E-3);
        self::assertEqualsWithDelta(-1.0E-12, $negativeVariable->values['y'], 1.0E-20);

        $overdetermined = Math::solveSystem(
            ['x + y = 1e12', 'x * y = 1', '2*x + 2*y = 2e12'],
            ['x' => 1.0E6, 'y' => 1.0E-6],
        );

        self::assertNotNull($overdetermined);
        self::assertEqualsWithDelta(1.0E12, $overdetermined->values['x'], 1.0);
        self::assertEqualsWithDelta(1.0E-12, $overdetermined->values['y'], 1.0E-20);
        self::assertLessThanOrEqual(
            1.0E-10,
            \abs(($overdetermined->values['x'] + $overdetermined->values['y'] - 1.0E12) / 1.0E12),
        );
        self::assertLessThanOrEqual(
            1.0E-10,
            \abs(($overdetermined->values['x'] * $overdetermined->values['y']) - 1.0),
        );
    }

    public function testLogarithmicStepsHandleThreeVariablesAtDifferentScales(): void
    {
        $solution = Math::solveSystem(
            [
                'x + y + z = 1000000000000.010002',
                'x*y + x*z + y*z = 10002000000.00000002',
                'x*y*z = 20000',
            ],
            ['x' => 1.0E6, 'y' => 1.0E-2, 'z' => 1.0E-3],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E12, $solution->values['x'], 1.0E-3);
        self::assertEqualsWithDelta(1.0E-2, $solution->values['y'], 1.0E-8);
        self::assertEqualsWithDelta(2.0E-6, $solution->values['z'], 1.0E-8);
    }

    public function testMixedAdditiveAndMultiplicativeStepsKeepZeroVariables(): void
    {
        $solution = Math::solveSystem(
            ['x = 0', 'y + z = 1e12', 'y * z = 1'],
            ['x' => 0.0, 'y' => 1.0E6, 'z' => 1.0E-6],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(1.0E12, $solution->values['y'], 1.0E-3);
        self::assertEqualsWithDelta(1.0E-12, $solution->values['z'], 1.0E-20);
    }

    public function testCoordinateFallbackUsesJacobianScalesFromZeroInitialValues(): void
    {
        $solution = Math::solveSystem(
            ['x = 0', 'y + z = 1e12', 'y * z = 1'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(1.0E12, $solution->values['y'], 1.0E-3);
        self::assertEqualsWithDelta(1.0E-12, $solution->values['z'], 1.0E-20);
    }

    public function testSolvesConsistentOverdeterminedSystem(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 3', 'x - y = 1', '2x = 4'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
    }

    public function testSolvesNonlinearOverdeterminedSystem(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 5', 'x - y = 1', '2x = 4'],
            ['x' => 1.5, 'y' => 0.5],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testInconsistentOverdeterminedSystemReturnsNull(): void
    {
        $solution = Math::solveSystem(
            ['x = 1', 'x = 2'],
            ['x' => 0.0],
        );

        self::assertNull($solution);
    }

    public function testBoundedStepEscapesAStationaryInitialGuess(): void
    {
        foreach ([['x^3 = 1', 1.0], ['x^3 = -1', -1.0], ['x^3 = 0.001', 0.1]] as [$equation, $root]) {
            $solution = Math::solveSystem([$equation], ['x' => 0.0]);

            self::assertNotNull($solution);
            self::assertEqualsWithDelta($root, $solution->values['x'], 1.0E-8);
            self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
        }
    }

    public function testTinyNonzeroTargetUsesCurrentEquationScaleForConvergence(): void
    {
        $solution = Math::solveSystem(['exp(x) = 1e-200'], ['x' => 0.0]);

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\log(1.0E-200), $solution->values['x'], 1.0E-9);
        self::assertLessThanOrEqual(1.0E-210, $solution->residualNorm);
    }

    public function testScalarSystemExpansionFindsDistantAnalyticRoot(): void
    {
        $solution = Math::solveSystem(['exp(x) = 1e-300'], ['x' => 0.0]);

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\log(1.0E-300), $solution->values['x'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-310, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testScalarSystemExpansionChoosesNearestPeriodicRoot(): void
    {
        $solution = Math::solveSystem(['sin(x) = 0.5'], ['x' => 0.0]);

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\M_PI / 6.0, $solution->values['x'], 1.0E-10);
    }

    public function testScalarSystemExpansionChoosesNearestGenericNonlinearRoot(): void
    {
        $solution = Math::solveSystem(['x^x = 0.7'], ['x' => 0.5]);

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(0.4623375275, $solution->values['x'], 1.0E-9);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testScalarPolynomialSystemSelectsTheNearestRealRoot(): void
    {
        $nearZero = Math::solveSystem(['x^2 = x^3'], ['x' => 0.1]);
        $nearOne = Math::solveSystem(['x^2 = x^3'], ['x' => 2.0]);

        self::assertNotNull($nearZero);
        self::assertNotNull($nearOne);
        self::assertSame(0.0, $nearZero->values['x']);
        self::assertSame(1.0, $nearOne->values['x']);
        self::assertSame(0.0, $nearZero->residualNorm);
        self::assertSame(0.0, $nearOne->residualNorm);
    }

    public function testScalarPolynomialSystemPreservesRootsOfRepeatedHighDegreeFactors(): void
    {
        $solution = Math::solveSystem(
            [
                '(x - 2) * (x - 3) * (x + 3.5) * (x - 2) * (x - 1.5) '
                    . '* (x - 1) * (x + 3) * (x + 1) = 0',
            ],
            ['x' => 4.0],
        );

        self::assertNotNull($solution);
        self::assertSame(3.0, $solution->values['x']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testScalarPolynomialSystemReturnsNullWithoutRealRoots(): void
    {
        self::assertNull(Math::solveSystem(['x^2 = -1'], ['x' => 0.0]));
    }

    public function testOverdeterminedScalarPolynomialSystemIntersectsConstraints(): void
    {
        $solution = Math::solveSystem(
            ['x^2 = 1', 'x = -1'],
            ['x' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertSame(-1.0, $solution->values['x']);
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testBivariateSymmetricPolynomialSystemsRecoverGeneratedIntegerRootsFromZero(): void
    {
        for ($firstRoot = -5; $firstRoot <= 5; ++$firstRoot) {
            for ($secondRoot = $firstRoot; $secondRoot <= 5; ++$secondRoot) {
                if ($firstRoot === 0 && $secondRoot === 0) {
                    continue;
                }

                $sum = $firstRoot + $secondRoot;
                $product = $firstRoot * $secondRoot;
                $solution = Math::solveSystem(
                    ["x + y = {$sum}", "x * y = {$product}"],
                    ['x' => 0.0, 'y' => 0.0],
                );

                self::assertNotNull($solution, "roots ({$firstRoot}, {$secondRoot})");
                self::assertEqualsWithDelta($sum, $solution->values['x'] + $solution->values['y'], 1.0E-8);
                self::assertEqualsWithDelta($product, $solution->values['x'] * $solution->values['y'], 1.0E-8);
                self::assertLessThanOrEqual(1.0E-8, $solution->residualNorm);
            }
        }
    }

    public function testBivariateSymmetricPolynomialSystemsRecoverIrrationalRootsFromZero(): void
    {
        for ($sum = -6; $sum <= 6; ++$sum) {
            for ($product = -8; $product <= 8; ++$product) {
                $discriminant = ($sum * $sum) - (4 * $product);
                if ($discriminant <= 0 || (int) \sqrt($discriminant) ** 2 === $discriminant) {
                    continue;
                }

                $solution = Math::solveSystem(
                    ["x + y = {$sum}", "x * y = {$product}"],
                    ['x' => 0.0, 'y' => 0.0],
                );

                self::assertNotNull($solution, "sum {$sum}, product {$product}");
                self::assertEqualsWithDelta($sum, $solution->values['x'] + $solution->values['y'], 1.0E-8);
                self::assertEqualsWithDelta($product, $solution->values['x'] * $solution->values['y'], 1.0E-8);
                self::assertLessThanOrEqual(1.0E-8, $solution->residualNorm);
            }
        }
    }

    public function testAffineSubstitutionPreservesGeneratedSystemsWithScaledRelations(): void
    {
        for ($x = -4; $x <= 4; ++$x) {
            for ($y = -4; $y <= 4; ++$y) {
                if ($x === 0 && $y === 0) {
                    continue;
                }

                $offset = (($x + (2 * $y)) % 3) - 1;
                $squareSum = ($x * $x) + ($y * $y);
                $product = $x * $y;
                $solution = Math::solveSystem(
                    [
                        "z = 2*x - 3*y + {$offset}",
                        "x^2 + y^2 = {$squareSum}",
                        "x*y = {$product}",
                    ],
                    ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
                );

                self::assertNotNull($solution, "roots ({$x}, {$y}), offset {$offset}");
                self::assertEqualsWithDelta(
                    2.0 * $solution->values['x'] - (3.0 * $solution->values['y']) + $offset,
                    $solution->values['z'],
                    1.0E-8,
                );
                self::assertEqualsWithDelta(
                    $squareSum,
                    ($solution->values['x'] ** 2) + ($solution->values['y'] ** 2),
                    1.0E-8,
                );
                self::assertEqualsWithDelta(
                    $product,
                    $solution->values['x'] * $solution->values['y'],
                    1.0E-8,
                );
            }
        }
    }

    public function testCoordinateFallbackFindsTinyScalarRootFromZero(): void
    {
        $solution = Math::solveSystem(['x^2 = 1.0e-200'], ['x' => 0.0]);

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E-100, \abs($solution->values['x']), 1.0E-110);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testCoordinateFallbackFindsTinySymmetricSystemRootFromZero(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 2.0e-200', 'x - y = 0'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E-100, \abs($solution->values['x']), 1.0E-110);
        self::assertEqualsWithDelta($solution->values['x'], $solution->values['y'], 1.0E-110);
    }

    public function testZeroSumsOfEvenPowersReduceDegenerateSystemsExactly(): void
    {
        foreach (
            [
                [
                    ['x^2 + y^2 = 0', 'x - y = 0'],
                    ['x' => 0.1, 'y' => -0.1],
                ],
                [
                    ['x^2 + y^4 = 0', 'x + y = 0'],
                    ['x' => 0.25, 'y' => -0.25],
                ],
            ] as [$equations, $initialValues]
        ) {
            $solution = Math::solveSystem($equations, $initialValues);

            self::assertNotNull($solution);
            self::assertSame(0.0, $solution->values['x']);
            self::assertSame(0.0, $solution->values['y']);
            self::assertSame(0.0, $solution->residualNorm);
            self::assertSame(0, $solution->iterations);
        }

        $unconstrainedSystem = Math::solveSystem(
            ['x^2 + y^2 = 0'],
            ['x' => 0.2, 'y' => -0.3],
        );

        self::assertNotNull($unconstrainedSystem);
        self::assertSame(0.0, $unconstrainedSystem->values['x']);
        self::assertSame(0.0, $unconstrainedSystem->values['y']);
        self::assertSame(0.0, $unconstrainedSystem->residualNorm);

        $weightedSystem = Math::solveSystem(
            ['weight * 3 * (x - 1)^2 + 3 * y^4 = 0', 'x + y = 1'],
            ['x' => 0.2, 'y' => 0.8],
            ['weight' => 2],
        );

        self::assertNotNull($weightedSystem);
        self::assertSame(1.0, $weightedSystem->values['x']);
        self::assertSame(0.0, $weightedSystem->values['y']);
        self::assertSame(0.0, $weightedSystem->residualNorm);
        self::assertSame(0, $weightedSystem->iterations);

        $negativeWeightSystem = Math::solveSystem(
            ['x^2 - y^2 = 0', 'x + y = 2'],
            ['x' => 0.2, 'y' => 0.8],
        );

        self::assertNotNull($negativeWeightSystem);
        self::assertEqualsWithDelta(1.0, $negativeWeightSystem->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $negativeWeightSystem->values['y'], 1.0E-8);
        self::assertSame(0, $negativeWeightSystem->iterations);
    }

    public function testTriangularSystemsReduceToScalarEquationSolving(): void
    {
        $definedSecond = Math::solveSystem(
            ['y = x^2', 'y = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );
        self::assertNotNull($definedSecond);
        self::assertEqualsWithDelta(1.0, \abs($definedSecond->values['x']), 1.0E-10);
        self::assertSame(1.0, $definedSecond->values['y']);
        self::assertSame(0.0, $definedSecond->residualNorm);

        $definedFirst = Math::solveSystem(
            ['x = y^2', 'x = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );
        self::assertNotNull($definedFirst);
        self::assertSame(1.0, $definedFirst->values['x']);
        self::assertEqualsWithDelta(1.0, \abs($definedFirst->values['y']), 1.0E-10);
        self::assertSame(0.0, $definedFirst->residualNorm);

        $transcendental = Math::solveSystem(
            ['x = ln(y)', 'x = 0'],
            ['x' => 2.0, 'y' => 2.0],
        );
        self::assertNotNull($transcendental);
        self::assertSame(0.0, $transcendental->values['x']);
        self::assertSame(1.0, $transcendental->values['y']);
        self::assertSame(0.0, $transcendental->residualNorm);
    }

    public function testAffineRelationsReduceCoupledPolynomialSystems(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 3', 'x * y = 2'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(3.0, $solution->values['x'] + $solution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['x'] * $solution->values['y'], 1.0E-12);
        self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);

        $irrational = Math::solveSystem(
            ['x + y = 0', 'x * y = -2'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($irrational);
        self::assertEqualsWithDelta(0.0, $irrational->values['x'] + $irrational->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(-2.0, $irrational->values['x'] * $irrational->values['y'], 1.0E-12);
        self::assertSame(0, $irrational->iterations);
    }

    public function testAffineSubstitutionChainsReduceThreeVariableSystems(): void
    {
        $solution = Math::solveSystem(
            ['z = x + y', 'y = x^2', 'z = 2'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.0, $solution->values['z'], 1.0E-12);
        self::assertSame(0.0, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testAffineReductionHandsRemainingBivariateSystemToResultantSolver(): void
    {
        $solution = Math::solveSystem(
            ['z = x + y', 'x^2 + y^2 = 1', 'x*y = 0.5'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['x']), 1.0E-12);
        self::assertEqualsWithDelta(\sqrt(0.5), \abs($solution->values['y']), 1.0E-12);
        self::assertEqualsWithDelta(
            $solution->values['x'] + $solution->values['y'],
            $solution->values['z'],
            1.0E-12,
        );
        self::assertSame(0.0, $solution->residualNorm);
    }

    public function testMultiVariableAffineRelationCanBeEliminatedRecursively(): void
    {
        $solution = Math::solveSystem(
            ['x + y + z = 6', 'y = x^2', 'z = y'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.5, $solution->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.25, $solution->values['y'], 1.0E-12);
        self::assertEqualsWithDelta(2.25, $solution->values['z'], 1.0E-12);
        self::assertSame(0.0, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testTinySystemsDoNotAcceptZeroSideResidualsAtTheWrongScale(): void
    {
        foreach ([1.0E-200, 2.0E-200, 3.0E-200, 4.0E-200] as $target) {
            $targetLiteral = \sprintf('%.1e', $target);
            foreach (
                [
                    ["x + y = 0", "x^2 + y^2 = {$targetLiteral}"],
                    ["1.0e20 * (x + y) = 0", "x^2 + y^2 = {$targetLiteral}"],
                    ["x^2 + y^2 = {$targetLiteral}", "x + y = 0"],
                ] as $equations
            ) {
                $solution = Math::solveSystem($equations, ['x' => 0.0, 'y' => 0.0]);

                self::assertNotNull($solution);
                self::assertEqualsWithDelta(\sqrt($target / 2.0), \abs($solution->values['x']), 1.0E-110);
                self::assertEqualsWithDelta(
                    \abs($solution->values['x']),
                    \abs($solution->values['y']),
                    1.0E-110,
                );
                self::assertEqualsWithDelta(
                    0.0,
                    $solution->values['x'] + $solution->values['y'],
                    1.0E-110,
                );
            }
        }
    }

    public function testTinySystemScalingTracksCustomResidualTolerance(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 0', 'x^2 + y^2 = 3.0e-200'],
            ['x' => 0.0, 'y' => 0.0],
            solverOptions: new SolverOptions(residualTolerance: 1.0E-6),
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(
            \sqrt(1.5E-200),
            \abs($solution->values['x']),
            1.0E-107,
        );
        self::assertEqualsWithDelta(
            0.0,
            $solution->values['x'] + $solution->values['y'],
            1.0E-110,
        );
    }

    public function testSystemSolutionIsInvariantToEquationScaling(): void
    {
        foreach (
            [
            '0.00000000000000000001 * (x - 1) = 0',
            '1.0e20 * (x - 1) = 0',
            ] as $equation
        ) {
            $solution = Math::solveSystem([$equation], ['x' => 0.0]);

            self::assertNotNull($solution);
            self::assertEqualsWithDelta(1.0, $solution->values['x'], 1.0E-8);
        }
    }

    public function testInitiallySatisfiedOverdeterminedEquationKeepsItsScaleInvariant(): void
    {
        $original = Math::solveSystem(
            ['1.0e6 * x + y = 1', 'x + y = 2', '2x + 2y = 4'],
            ['x' => 0.0, 'y' => 1.0],
        );
        $rescaled = Math::solveSystem(
            ['x + 1.0e-6 * y = 1.0e-6', 'x + y = 2', '2x + 2y = 4'],
            ['x' => 0.0, 'y' => 1.0],
        );

        self::assertNotNull($original);
        self::assertNotNull($rescaled);
        self::assertEqualsWithDelta(-1.000001E-6, $original->values['x'], 1.0E-12);
        self::assertEqualsWithDelta(2.000001, $original->values['y'], 1.0E-8);
        self::assertEqualsWithDelta($original->values['x'], $rescaled->values['x'], 1.0E-12);
        self::assertEqualsWithDelta($original->values['y'], $rescaled->values['y'], 1.0E-8);
    }

    public function testInitiallyZeroEquationKeepsItsScaleInvariant(): void
    {
        $original = Math::solveSystem(
            ['x^2 = 0', 'x + y = 1', 'y^2 = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );
        $rescaled = Math::solveSystem(
            ['1.0e-6 * x^2 = 0', 'x + y = 1', 'y^2 = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($original);
        self::assertNotNull($rescaled);
        self::assertEqualsWithDelta(0.0, $original->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $original->values['y'], 1.0E-8);
        self::assertEqualsWithDelta($original->values['x'], $rescaled->values['x'], 1.0E-12);
        self::assertEqualsWithDelta($original->values['y'], $rescaled->values['y'], 1.0E-12);
    }

    public function testSolvesSystemWithFixedParameters(): void
    {
        $solution = Math::solveSystem(
            ['x + y = total', 'x - y = 1'],
            ['x' => 0.0, 'y' => 0.0],
            ['total' => 3.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, $solution->values['y'], 1.0E-8);
    }

    public function testFixedParameterCannotShadowSolvedVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct from solved variables');

        Math::solveSystem(
            ['x = 1'],
            ['x' => 0.0],
            ['x' => 2.0],
        );
    }

    public function testConsistentRankDeficientSystemCanConverge(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 1', '2x + 2y = 2'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'] + $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testCoordinateFallbackEscapesSymmetricRankDeficientGuess(): void
    {
        $solution = Math::solveSystem(
            ['x^2 + y^2 = 1', 'x * y = 0'],
            ['x' => 0.1, 'y' => 0.1],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'] ** 2 + $solution->values['y'] ** 2, 1.0E-8);
        self::assertEqualsWithDelta(0.0, $solution->values['x'] * $solution->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testInconsistentRankDeficientSystemReturnsNull(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 1', '2x + 2y = 3'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNull($solution);
    }

    public function testSolvesUnderdeterminedSystemFromInitialValues(): void
    {
        $solution = Math::solveSystem(
            ['x + y = 3'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(3.0, $solution->values['x'] + $solution->values['y'], 1.0E-8);
        self::assertEqualsWithDelta(1.5, $solution->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(1.5, $solution->values['y'], 1.0E-7);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testSolvesUnderdeterminedSystemWithMoreVariablesThanEquations(): void
    {
        $solution = Math::solveSystem(
            ['x + y + z = 6'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(2.0, $solution->values['x'], 1.0E-7);
        self::assertEqualsWithDelta(2.0, $solution->values['y'], 1.0E-7);
        self::assertEqualsWithDelta(2.0, $solution->values['z'], 1.0E-7);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
        self::assertSame(0, $solution->iterations);
    }

    public function testExactAffineUnderdeterminedSolveProjectsInitialGuess(): void
    {
        $solution = Math::solveSystem(
            ['x + y + z = 6', 'x + z = 4', '2*x + 2*y + 2*z = 12'],
            ['x' => 10.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertSame(7.0, $solution->values['x']);
        self::assertSame(2.0, $solution->values['y']);
        self::assertSame(-3.0, $solution->values['z']);
        self::assertSame(0, $solution->iterations);
        self::assertSame(0.0, $solution->residualNorm);

        $rational = Math::solveSystem(
            ['x/2 + y = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($rational);
        self::assertSame(0.4, $rational->values['x']);
        self::assertSame(0.8, $rational->values['y']);
        self::assertSame(0, $rational->iterations);
    }

    public function testRedundantAffineConstraintsPreserveProjectedInitialGuess(): void
    {
        foreach ([
            ['x + y = 2'],
            ['x + y = 2', '2*x + 2*y = 4'],
            ['x + y = 2', '2*x + 2*y = 4', '3*x + 3*y = 6'],
        ] as $equations) {
            $solution = Math::solveSystem($equations, ['x' => 0.0, 'y' => 0.0]);

            self::assertNotNull($solution);
            self::assertSame(1.0, $solution->values['x']);
            self::assertSame(1.0, $solution->values['y']);
            self::assertSame(0, $solution->iterations);
            self::assertSame(0.0, $solution->residualNorm);
        }
    }

    public function testContradictoryExactAffineSystemReturnsNoSolution(): void
    {
        self::assertNull(Math::solveSystem(
            ['x + y = 2', '2*x + 2*y = 5'],
            ['x' => 0.0, 'y' => 0.0],
        ));
    }

    public function testExactAffineProjectionIsInvariantToExtremeEquationScales(): void
    {
        foreach (['1e-200', '1e200', '1e308'] as $scale) {
            $solution = Math::solveSystem(
                ["{$scale}*x + {$scale}*y = {$scale}"],
                ['x' => 0.0, 'y' => 0.0],
            );

            self::assertNotNull($solution, $scale);
            self::assertSame(0.5, $solution->values['x'], $scale);
            self::assertSame(0.5, $solution->values['y'], $scale);
            self::assertSame(0, $solution->iterations, $scale);
        }
    }

    public function testNonlinearUnderdeterminedSystemEscapesZeroJacobian(): void
    {
        $solution = Math::solveSystem(
            ['x * y = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0, $solution->values['x'] * $solution->values['y'], 1.0E-8);
        self::assertEqualsWithDelta(1.0, \abs($solution->values['x']), 1.0E-8);
        self::assertEqualsWithDelta(1.0, \abs($solution->values['y']), 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testMultivariableSolverRecoversFromUndefinedInitialDomains(): void
    {
        $logarithmic = Math::solveSystem(
            ['ln(x) + y = 0', 'x - y = 2'],
            ['x' => -1.0, 'y' => 0.0],
        );
        $radical = Math::solveSystem(
            ['sqrt(x) + y = 0', 'x + y = 1'],
            ['x' => -1.0, 'y' => 0.0],
        );
        $coupledLogarithm = Math::solveSystem(
            ['ln(x * y) = 0', 'x - y = 0'],
            ['x' => -1.0, 'y' => 0.0],
        );
        $reciprocal = Math::solveSystem(
            ['1 / x + y = 0', 'x - y = 2'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($logarithmic);
        self::assertEqualsWithDelta(1.5571455989976115, $logarithmic->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(-0.44285440100238865, $logarithmic->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $logarithmic->residualNorm);

        self::assertNotNull($radical);
        self::assertEqualsWithDelta(2.618033988749895, $radical->values['x'], 1.0E-10);
        self::assertEqualsWithDelta(-1.618033988749895, $radical->values['y'], 1.0E-10);
        self::assertLessThanOrEqual(1.0E-10, $radical->residualNorm);

        self::assertNotNull($coupledLogarithm);
        self::assertEqualsWithDelta(-1.0, $coupledLogarithm->values['x'], 1.0E-8);
        self::assertEqualsWithDelta(-1.0, $coupledLogarithm->values['y'], 1.0E-8);
        self::assertLessThanOrEqual(1.0E-10, $coupledLogarithm->residualNorm);

        self::assertNotNull($reciprocal);
        self::assertEqualsWithDelta(1.0, $reciprocal->values['x'], 1.0E-5);
        self::assertEqualsWithDelta(-1.0, $reciprocal->values['y'], 1.0E-5);
        self::assertLessThanOrEqual(1.0E-10, $reciprocal->residualNorm);

        foreach (['1e-20', '1e20'] as $scale) {
            $scaled = Math::solveSystem(
                [
                    $scale . ' * (ln(x) + y) = 0',
                    $scale . ' * (x - y) = 2 * ' . $scale,
                ],
                ['x' => -1.0, 'y' => 0.0],
            );

            self::assertNotNull($scaled, $scale);
            self::assertEqualsWithDelta(1.5571455989976115, $scaled->values['x'], 1.0E-8, $scale);
            self::assertEqualsWithDelta(-0.44285440100238865, $scaled->values['y'], 1.0E-8, $scale);
            self::assertLessThanOrEqual(1.0E-10, $scaled->residualNorm, $scale);
        }
    }

    public function testDomainRecoveryEnumeratesMixedSignPatternsForSixVariables(): void
    {
        $solution = Math::solveSystem(
            [
                'ln(a) = 0',
                'ln(b) = 0',
                'ln(-c) = 0',
                'ln(-d) = 0',
                'ln(g) = 0',
                'ln(-f) = 0',
            ],
            ['a' => 0.0, 'b' => 0.0, 'c' => 0.0, 'd' => 0.0, 'g' => 0.0, 'f' => 0.0],
        );

        self::assertNotNull($solution);
        foreach (['a' => 1.0, 'b' => 1.0, 'c' => -1.0, 'd' => -1.0, 'g' => 1.0, 'f' => -1.0] as $name => $expected) {
            self::assertEqualsWithDelta($expected, $solution->values[$name], 1.0E-8, $name);
        }
        self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
    }

    public function testDomainRecoveryPerturbsVariablesAtDifferentMagnitudes(): void
    {
        foreach ([100.0, 1_000_000.0, 1_000_000_000.0] as $offset) {
            $solution = Math::solveSystem(
                ['ln(x - ' . \sprintf('%.17g', $offset) . ') = 0', 'ln(y * (1 - y)) = ln(0.16)'],
                ['x' => 0.0, 'y' => 0.0],
            );

            self::assertNotNull($solution);
            self::assertEqualsWithDelta($offset + 1.0, $solution->values['x'], 1.0E-6);
            self::assertEqualsWithDelta(0.2, $solution->values['y'], 1.0E-8);
            self::assertLessThanOrEqual(1.0E-10, $solution->residualNorm);
        }
    }

    public function testJacobianPreservesDerivativesForLargeEquationOffsets(): void
    {
        $linear = Math::solveSystem(
            ['x = 1e10', 'y = 1e-10'],
            ['x' => 0.0, 'y' => 0.0],
        );
        $nonlinear = Math::solveSystem(
            ['x = 1e10', 'x * y = 1'],
            ['x' => 0.0, 'y' => 0.0],
        );

        self::assertNotNull($linear);
        self::assertSame(1.0E10, $linear->values['x']);
        self::assertSame(1.0E-10, $linear->values['y']);
        self::assertSame(0.0, $linear->residualNorm);

        self::assertNotNull($nonlinear);
        self::assertSame(1.0E10, $nonlinear->values['x']);
        self::assertEqualsWithDelta(1.0E-10, $nonlinear->values['y'], 1.0E-20);
        self::assertLessThanOrEqual(1.0E-10, $nonlinear->residualNorm);
    }

    public function testSingularZeroStartRecoversRootsWithWidelySeparatedMagnitudes(): void
    {
        foreach ([10, 15, 20, 30, 50, 80, 100] as $rootExponent) {
            $rootScale = 10.0 ** $rootExponent;
            $sumSquares = $rootScale ** 2;
            $solution = Math::solveSystem(
                [
                    'x^2 + y^2 = ' . \sprintf('%.17g', $sumSquares),
                    'x * y = 1',
                ],
                ['x' => 0.0, 'y' => 0.0],
            );

            self::assertNotNull($solution, 'root scale 1e' . $rootExponent);
            self::assertEqualsWithDelta(
                $rootScale,
                \abs($solution->values['x']),
                $rootScale * 1.0E-10,
            );
            self::assertEqualsWithDelta(
                1.0 / $rootScale,
                \abs($solution->values['y']),
                $rootScale ** -1 * 1.0E-10,
            );
            self::assertEqualsWithDelta(
                1.0,
                $solution->values['x'] * $solution->values['y'],
                1.0E-10,
            );
        }
    }

    public function testSingularZeroStartScalesThreeVariablesAsymmetrically(): void
    {
        $solution = Math::solveSystem(
            ['x^2 = 1e200', 'x * y = 1e50', 'x * z = 1e50'],
            ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        );

        self::assertNotNull($solution);
        self::assertEqualsWithDelta(1.0E100, \abs($solution->values['x']), 1.0E90);
        self::assertEqualsWithDelta(1.0E-50, \abs($solution->values['y']), 1.0E-60);
        self::assertEqualsWithDelta(1.0E-50, \abs($solution->values['z']), 1.0E-60);
        self::assertEqualsWithDelta(1.0E50, $solution->values['x'] * $solution->values['y'], 1.0E40);
        self::assertEqualsWithDelta(1.0E50, $solution->values['x'] * $solution->values['z'], 1.0E40);
    }
}
