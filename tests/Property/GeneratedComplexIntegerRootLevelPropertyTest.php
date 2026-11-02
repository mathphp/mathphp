<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use PHPUnit\Framework\TestCase;

/** Generated nonzero integer-root levels agree with their power-equation reductions. */
final class GeneratedComplexIntegerRootLevelPropertyTest extends TestCase
{
    private const SEED = 781423;

    private int $randomState = self::SEED;

    public function testGeneratedRootLevelsMatchTransformedLinearEquations(): void
    {
        $degrees = [-5, -3, -2, -1, 1, 2, 3, 4, 5];
        $levels = [-3, -2, -1, 0, 1, 2, 3];
        foreach ($degrees as $degree) {
            foreach ($levels as $level) {
                $coefficient = $this->randomInt(-5, 5);
                if ($coefficient === 0) {
                    $coefficient = 1;
                }
                $constant = $this->randomInt(-7, 7);
                $equation = \sprintf(
                    'root((%d*x%+d),%d)=%d',
                    $coefficient,
                    $constant,
                    $degree,
                    $level,
                );
                $solution = Math::solvePolynomialComplex($equation, 'x');
                $noSolution = ($degree < 0 && $level === 0)
                    || ((\abs($degree) % 2) === 0 && $level < 0);
                if ($noSolution) {
                    self::assertSame([], $solution->roots, $equation);
                    self::assertTrue($solution->converged, $equation);
                    continue;
                }

                $expected = (($level ** $degree) - $constant) / $coefficient;
                self::assertTrue($solution->converged, $equation);
                self::assertCount(1, $solution->roots, $equation);
                self::assertEqualsWithDelta($expected, $solution->roots[0]->real, 1.0E-9, $equation);
                self::assertSame(0.0, $solution->roots[0]->imaginary, $equation);
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
}
