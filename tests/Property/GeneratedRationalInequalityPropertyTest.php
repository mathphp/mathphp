<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

/**
 * PROP-005: generated rational inequality sets agree with direct sign and domain checks.
 */
final class GeneratedRationalInequalityPropertyTest extends TestCase
{
    private const SEED = 781093;

    private const CASE_COUNT = 24;

    private int $randomState = self::SEED;

    public function testProp005GeneratedRationalInequalitiesRespectPolesAndSigns(): void
    {
        $samplePoints = [];
        for ($index = 0; $index <= 32; ++$index) {
            $samplePoints[] = -4.0 + (0.25 * $index);
        }

        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            [$numeratorRoots, $numeratorFactors] = $this->randomFactorization(1, 4);
            [$denominatorRoots, $denominatorFactors] = $this->randomFactorization(1, 3);
            $equation = '(' . \implode('*', $numeratorFactors) . ')/('
                . \implode('*', $denominatorFactors) . ')>=0';
            $solutions = Math::solveInequality(
                $equation,
                'x',
                -4.0,
                4.0,
                solverOptions: new SolverOptions(scanSteps: 1),
            );

            foreach ($samplePoints as $point) {
                $numerator = 1.0;
                foreach ($numeratorRoots as $root) {
                    $numerator *= $point - $root;
                }
                $denominator = 1.0;
                foreach ($denominatorRoots as $root) {
                    $denominator *= $point - $root;
                }
                $expected = $denominator !== 0.0 && ($numerator / $denominator) >= 0.0;
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

    /** @return array{list<int>, list<string>} */
    private function randomFactorization(int $minimumDegree, int $maximumDegree): array
    {
        $roots = [];
        $factors = [];
        $degree = $this->randomInt($minimumDegree, $maximumDegree);
        for ($factor = 0; $factor < $degree; ++$factor) {
            $root = $this->randomInt(-3, 3);
            $roots[] = $root;
            $factors[] = '(x-(' . $root . '))';
        }

        return [$roots, $factors];
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
            'PROP-005 seed=%d case=%d point=%.17g equation=%s',
            self::SEED,
            $case,
            $point,
            $equation,
        );
    }
}
