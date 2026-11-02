<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

/**
 * PROP-002: generated rational roots agree between numerical and exact solvers.
 */
final class GeneratedPolynomialRootPropertyTest extends TestCase
{
    private const SEED = 410119;

    private const CASE_COUNT = 256;

    private int $randomState = self::SEED;

    public function testProp002FactoredRationalPolynomialsMatchExactRootCounts(): void
    {
        $solverOptions = new SolverOptions(scanSteps: 16, maxPolynomialDegree: 256);

        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            $factors = [];
            $expectedRoots = [];
            $factorCount = $this->randomInt(1, 4);

            for ($factor = 0; $factor < $factorCount; ++$factor) {
                $denominator = $this->randomInt(1, 8);
                $numerator = $this->randomInt(-15, 15);
                $multiplicity = $this->randomInt(1, 3);
                $factors[] = \sprintf(
                    '(%d*x - %d)^%d',
                    $denominator,
                    $numerator,
                    $multiplicity,
                );
                $root = $numerator / $denominator;
                if (!\in_array($root, $expectedRoots, true)) {
                    $expectedRoots[] = $root;
                }
            }

            \sort($expectedRoots, \SORT_NUMERIC);
            $equation = \implode(' * ', $factors) . ' = 0';
            $expected = Math::countPolynomialRootsInInterval(
                $equation,
                'x',
                -20.0,
                20.0,
                solverOptions: $solverOptions,
            );
            $solutions = Math::solve(
                $equation,
                'x',
                -20.0,
                20.0,
                solverOptions: $solverOptions,
            );

            self::assertNotNull($expected, self::failureContext($case, $equation));
            self::assertCount(
                $expected,
                $solutions,
                self::failureContext($case, $equation),
            );
            self::assertCount(\count($expectedRoots), $solutions, self::failureContext($case, $equation));
            foreach ($solutions as $solution) {
                $nearestRootDistance = \INF;
                foreach ($expectedRoots as $expectedRoot) {
                    $nearestRootDistance = \min(
                        $nearestRootDistance,
                        \abs($solution->value - $expectedRoot),
                    );
                }

                self::assertLessThanOrEqual(
                    1.0E-7,
                    $nearestRootDistance,
                    self::failureContext($case, $equation),
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

        return $minimum
            + ($this->randomState % ($maximum - $minimum + 1));
    }

    private static function failureContext(int $case, string $equation): string
    {
        return \sprintf(
            'PROP-002 seed=%d case=%d equation=%s',
            self::SEED,
            $case,
            $equation,
        );
    }
}
