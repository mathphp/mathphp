<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

/**
 * PROP-004: generated polynomial equality sets agree with an independent sign oracle.
 */
final class GeneratedEquationSetPropertyTest extends TestCase
{
    private const SEED = 613240;

    private const CASE_COUNT = 24;

    private int $randomState = self::SEED;

    public function testProp004GeneratedPolynomialInequalitiesMatchDirectSignEvaluation(): void
    {
        $samplePoints = [];
        for ($index = 0; $index <= 32; ++$index) {
            $samplePoints[] = -4.0 + (0.25 * $index);
        }

        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            $roots = [];
            $factors = [];
            $degree = $this->randomInt(1, 6);
            for ($factor = 0; $factor < $degree; ++$factor) {
                $root = $this->randomInt(-3, 3);
                $roots[] = $root;
                $factors[] = '(x-(' . $root . '))';
            }

            $equation = \implode('*', $factors) . '>=0';
            $solutions = Math::solveInequality(
                $equation,
                'x',
                -4.0,
                4.0,
                solverOptions: new SolverOptions(scanSteps: 1),
            );

            foreach ($samplePoints as $point) {
                $polynomialValue = 1.0;
                foreach ($roots as $root) {
                    $polynomialValue *= $point - $root;
                }
                $expected = $polynomialValue >= 0.0;
                $actual = false;
                foreach ($solutions as $interval) {
                    if ($interval->contains($point)) {
                        $actual = true;
                        break;
                    }
                }

                self::assertSame(
                    $expected,
                    $actual,
                    self::failureContext($case, $equation, $point),
                );
            }
        }
    }

    private function randomInt(int $minimum, int $maximum): int
    {
        $high = \intdiv($this->randomState, 127773);
        $low = $this->randomState % 127773;
        $next = 16807 * $low - 2836 * $high;
        $this->randomState = $next > 0 ? $next : $next + 2147483647;

        return $minimum + ($this->randomState % ($maximum - $minimum + 1));
    }

    private static function failureContext(int $case, string $equation, float $point): string
    {
        return \sprintf(
            'PROP-004 seed=%d case=%d point=%.17g equation=%s',
            self::SEED,
            $case,
            $point,
            $equation,
        );
    }
}
