<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use PHPUnit\Framework\TestCase;

/** Generated exact affine systems agree with independently constructed solutions. */
final class GeneratedAffineSystemPropertyTest extends TestCase
{
    private const SEED = 942731;

    private const CASE_COUNT = 16;

    private int $randomState = self::SEED;

    public function testGeneratedInvertibleIntegerSystemsRecoverTheirKnownSolutions(): void
    {
        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            do {
                $a = $this->randomInt(-9, 9);
                $b = $this->randomInt(-9, 9);
                $c = $this->randomInt(-9, 9);
                $d = $this->randomInt(-9, 9);
                $determinant = ($a * $d) - ($b * $c);
            } while ($determinant === 0);

            $expectedX = $this->randomInt(-12, 12);
            $expectedY = $this->randomInt(-12, 12);
            $firstRight = ($a * $expectedX) + ($b * $expectedY);
            $secondRight = ($c * $expectedX) + ($d * $expectedY);
            $equations = [
                $a . '*x + (' . $b . ')*y = ' . $firstRight,
                $c . '*x + (' . $d . ')*y = ' . $secondRight,
            ];
            $context = \sprintf(
                'PROP-AFFINE-SYSTEM seed=%d case=%d equations=%s',
                self::SEED,
                $case,
                \implode(' ; ', $equations),
            );

            $solution = Math::solveSystem(
                $equations,
                ['x' => (float) $this->randomInt(-5, 5), 'y' => (float) $this->randomInt(-5, 5)],
            );

            self::assertNotNull($solution, $context);
            self::assertEqualsWithDelta((float) $expectedX, $solution->values['x'], 1.0E-12, $context);
            self::assertEqualsWithDelta((float) $expectedY, $solution->values['y'], 1.0E-12, $context);
            self::assertLessThanOrEqual(1.0E-12, $solution->residualNorm, $context);
        }
    }

    private function randomInt(int $minimum, int $maximum): int
    {
        $high = \intdiv($this->randomState, 127773);
        $low = $this->randomState % 127773;
        $next = (16807 * $low) - (2836 * $high);
        $this->randomState = $next > 0 ? $next : $next + 2147483647;

        return $minimum + ($this->randomState % ($maximum - $minimum + 1));
    }
}
