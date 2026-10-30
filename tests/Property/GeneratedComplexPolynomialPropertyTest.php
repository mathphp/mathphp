<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use PHPUnit\Framework\TestCase;

/** Generated conjugate-pair polynomials preserve degree and complex roots. */
final class GeneratedComplexPolynomialPropertyTest extends TestCase
{
    private const SEED = 338921;

    private int $randomState = self::SEED;

    public function testGeneratedConjugatePairPolynomialsRecoverEveryRoot(): void
    {
        for ($case = 0; $case < 12; ++$case) {
            $factors = [];
            $expectedRoots = [];
            $zeroMultiplicity = $this->randomInt(1, 3);
            $factors[] = '(x)^' . $zeroMultiplicity;
            for ($copy = 0; $copy < $zeroMultiplicity; ++$copy) {
                $expectedRoots[] = [0, 0];
            }
            $factorCount = $this->randomInt(1, 3);
            for ($factor = 0; $factor < $factorCount; ++$factor) {
                $real = $this->randomInt(-4, 4);
                $imaginary = $this->randomInt(1, 5);
                $multiplicity = $this->randomInt(1, 3);
                $factorExpression = \sprintf(
                    '(x^2%+d*x+%d)',
                    -2 * $real,
                    ($real * $real) + ($imaginary * $imaginary),
                );
                $factors[] = '(' . $factorExpression . ')^' . $multiplicity;
                for ($copy = 0; $copy < $multiplicity; ++$copy) {
                    $expectedRoots[] = [$real, -$imaginary];
                    $expectedRoots[] = [$real, $imaginary];
                }
            }

            $equation = \implode('*', $factors) . '=0';
            $context = \sprintf(
                'PROP-COMPLEX-POLY seed=%d case=%d equation=%s',
                self::SEED,
                $case,
                $equation,
            );
            $solution = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($solution->converged, $context);
            self::assertCount(\count($expectedRoots), $solution->roots, $context);
            self::assertSame(\count($expectedRoots), $solution->degree, $context);
            $unmatchedExpectedRoots = $expectedRoots;
            foreach ($solution->roots as $root) {
                $nearestIndex = null;
                $nearestDistance = \INF;
                foreach ($unmatchedExpectedRoots as $index => [$real, $imaginary]) {
                    $distance = \hypot($root->real - $real, $root->imaginary - $imaginary);
                    if ($distance < $nearestDistance) {
                        $nearestIndex = $index;
                        $nearestDistance = $distance;
                    }
                }
                self::assertLessThanOrEqual(1.0E-7, $nearestDistance, $context);
                self::assertLessThanOrEqual(1.0E-8, $root->residual, $context);
                self::assertNotNull($nearestIndex, $context);
                unset($unmatchedExpectedRoots[$nearestIndex]);
            }
            self::assertSame([], $unmatchedExpectedRoots, $context);
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
