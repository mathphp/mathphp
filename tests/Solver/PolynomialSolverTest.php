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
}
