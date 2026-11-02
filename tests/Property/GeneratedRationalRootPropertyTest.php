<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

/**
 * PROP-003: generated rational equations agree between numerical and exact real-root solvers.
 */
final class GeneratedRationalRootPropertyTest extends TestCase
{
    private const SEED = 884221;

    private const CASE_COUNT = 24;

    private int $randomState = self::SEED;

    public function testProp003GeneratedRationalEquationsMatchExactRootCounts(): void
    {
        $solverOptions = new SolverOptions(scanSteps: 16);

        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            $numeratorRoots = [];
            $numeratorFactors = [];
            $numeratorFactorCount = $this->randomInt(1, 4);
            for ($factor = 0; $factor < $numeratorFactorCount; ++$factor) {
                $root = $this->randomInt(-10, 10);
                $numeratorRoots[] = $root;
                $numeratorFactors[] = '(x-(' . $root . '))';
            }

            $poles = [];
            $denominatorFactors = [];
            $denominatorFactorCount = $this->randomInt(1, 3);
            for ($factor = 0; $factor < $denominatorFactorCount; ++$factor) {
                $pole = $this->randomInt(-10, 10);
                $poles[] = $pole;
                $denominatorFactors[] = '(x-(' . $pole . '))';
            }

            $equation = '(' . \implode('*', $numeratorFactors) . ')/('
                . \implode('*', $denominatorFactors) . ')=0';
            $expectedRoots = \array_values(\array_unique(\array_filter(
                $numeratorRoots,
                static fn (int $root): bool => !\in_array($root, $poles, true),
            )));
            \sort($expectedRoots, \SORT_NUMERIC);
            $failureContext = self::failureContext($case, $equation);
            $exactCount = Math::countPolynomialRootsInInterval($equation, 'x', -12.0, 12.0);
            $isolatedRoots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-12',
                '12',
            );
            $solutions = Math::solve(
                $equation,
                'x',
                -12.0,
                12.0,
                solverOptions: $solverOptions,
            );

            self::assertNotNull($exactCount, $failureContext);
            self::assertSame(\count($expectedRoots), $exactCount, $failureContext);
            self::assertCount($exactCount, $solutions, $failureContext);
            self::assertNotNull($isolatedRoots, $failureContext);
            self::assertCount(\count($expectedRoots), $isolatedRoots, $failureContext);
            foreach ($isolatedRoots as $interval) {
                $lower = (float) $interval->lowerNumerator / (float) $interval->lowerDenominator;
                $upper = (float) $interval->upperNumerator / (float) $interval->upperDenominator;
                $matchingRoots = \array_filter(
                    $expectedRoots,
                    static fn (int $root): bool => $interval->isExactRoot
                        ? $lower === (float) $root
                        : $lower < $root && $root < $upper,
                );
                self::assertCount(1, $matchingRoots, $failureContext);
            }
            foreach ($solutions as $solution) {
                $nearestRootDistance = \INF;
                foreach ($expectedRoots as $expectedRoot) {
                    $nearestRootDistance = \min(
                        $nearestRootDistance,
                        \abs($solution->value - $expectedRoot),
                    );
                }
                self::assertLessThanOrEqual(1.0E-8, $nearestRootDistance, $failureContext);
                self::assertLessThanOrEqual(1.0E-10, $solution->residual, $failureContext);
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

    private static function failureContext(int $case, string $equation): string
    {
        return \sprintf(
            'PROP-003 seed=%d case=%d equation=%s',
            self::SEED,
            $case,
            $equation,
        );
    }
}
