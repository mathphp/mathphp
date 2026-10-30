<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Solver\PolynomialSolver;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class PolynomialSolverTest extends TestCase
{
    public function testRepeatedRootRecoveryAvoidsOverflowInResidualScale(): void
    {
        $scale = 1.0E308;
        $solver = new PolynomialSolver();
        $roots = $solver->recoverRepeatedRoots(
            [0.5 * $scale, 0.0, -1.5 * $scale, $scale],
            [],
            0.0,
            2.0,
            static fn (float $value): ?float => $value === 1.0 ? 0.0 : null,
            static fn (float $value): ?float => 1.0,
            new SolverOptions(),
        );

        self::assertCount(1, $roots);
        self::assertSame(1.0, $roots[0]->value);
        self::assertSame(0.0, $roots[0]->residual);
    }

    public function testNearZeroPositiveMinimumIsNotAcceptedAsARepeatedRoot(): void
    {
        $coefficients = [1.0E-12, 0.0, 1.0];
        $residual = static function (float $value) use ($coefficients): ?float {
            $result = 0.0;
            for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
                $result = ($result * $value) + $coefficients[$power];
            }

            return $result;
        };
        $scale = static function (float $value) use ($coefficients): ?float {
            $result = 0.0;
            for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
                $result = ($result * \abs($value)) + \abs($coefficients[$power]);
            }

            return $result;
        };

        self::assertSame(
            [],
            (new PolynomialSolver())->solve(
                $coefficients,
                -1.0,
                1.0,
                $residual,
                new SolverOptions(),
                $scale,
            ),
        );
    }

    public function testNearTangentPositiveMinimumAtMachinePrecisionIsNotAcceptedAsARoot(): void
    {
        $coefficients = [1.0, -1.999999999999999, 1.0];
        $solver = new PolynomialSolver();
        $residual = static fn (float $value): ?float =>
            (($coefficients[2] * $value) + $coefficients[1]) * $value + $coefficients[0];
        $scale = static fn (float $value): ?float =>
            (($coefficients[2] * \abs($value)) + \abs($coefficients[1])) * \abs($value)
            + \abs($coefficients[0]);

        self::assertSame(
            [],
            $solver->solve($coefficients, -1.0, 1.0, $residual, new SolverOptions(), $scale),
        );
    }
}
