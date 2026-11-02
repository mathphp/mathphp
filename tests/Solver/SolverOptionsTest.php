<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class SolverOptionsTest extends TestCase
{
    public function testScanStepLimitMatchesBoundedAdaptiveSolverBudget(): void
    {
        self::assertSame(
            SolverOptions::MAX_SCAN_STEPS,
            (new SolverOptions(scanSteps: SolverOptions::MAX_SCAN_STEPS))->scanSteps,
        );
    }

    public function testScanStepsAboveBoundedSolverBudgetAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SolverOptions(scanSteps: SolverOptions::MAX_SCAN_STEPS + 1);
    }
}
