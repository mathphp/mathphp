<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Solver\ExactPolynomialRootCounter;
use MathPHP\Solver\IntegerPolynomialExtractor;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

/**
 * Generated exact polynomials retain every distinct rational root despite multiplicity.
 */
final class GeneratedExactPolynomialRootPropertyTest extends TestCase
{
    private const SEED = 673921;

    private const CASE_COUNT = 128;

    private int $randomState = self::SEED;

    public function testGeneratedBaseOneSymmetricExponentBoundaries(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 2));
        foreach ([1, 2, 3, 5] as $scale) {
            $equation = '1^((x*x+' . ($scale * $scale) . ')/(' . $scale . '*x))=1';
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited);
            self::assertCount(2, $roots);
            self::assertTrue($roots[0]->isExactRoot);
            self::assertSame((string) -$scale, $roots[0]->lowerNumerator);
            self::assertSame('1', $roots[0]->lowerDenominator);
            self::assertTrue($roots[1]->isExactRoot);
            self::assertSame((string) $scale, $roots[1]->lowerNumerator);
            self::assertSame('1', $roots[1]->lowerDenominator);
        }
    }

    public function testGeneratedRationalPowerTargetsPreserveExactReciprocalRoots(): void
    {
        foreach ([2, 3, 5] as $factor) {
            foreach ([3, 4, 5] as $denominator) {
                foreach ([2, 3] as $numerator) {
                    $base = $factor ** $denominator;
                    $target = $factor ** $numerator;
                    $divisor = self::gcd($numerator, $denominator);
                    foreach ([1, -1] as $sign) {
                        $targetText = $sign === 1 ? (string) $target : '1/' . $target;
                        $roots = Math::isolatePolynomialRootsOnRealLine($base . '^(1/x)=' . $targetText, 'x');
                        self::assertCount(1, $roots);
                        self::assertTrue($roots[0]->isExactRoot);
                        self::assertSame((string) ($sign * intdiv($denominator, $divisor)), $roots[0]->lowerNumerator);
                        self::assertSame((string) intdiv($numerator, $divisor), $roots[0]->lowerDenominator);
                    }
                }
            }
            $equation = $factor . '^((x*x-1)/(x-1))=' . ($factor * $factor);
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        }
    }

    public function testGeneratedRepeatedRationalFactorsHaveExactDistinctRootIsolation(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $arithmetic = new IntegerPolynomialExtractor('', []);
        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            /** @var list<string> $coefficients */
            $coefficients = ['1'];
            $expected = [];
            $factorCount = $this->randomInt(1, 3);
            for ($factor = 0; $factor < $factorCount; ++$factor) {
                $numerator = $this->randomInt(-12, 12);
                $denominator = $this->randomInt(1, 9);
                $divisor = self::gcd(abs($numerator), $denominator);
                $numerator = intdiv($numerator, $divisor);
                $denominator = intdiv($denominator, $divisor);
                $multiplicity = $this->randomInt(1, 2);
                $rootKey = $numerator . '/' . $denominator;
                $expected[$rootKey] = [$numerator, $denominator];

                for ($copy = 0; $copy < $multiplicity; ++$copy) {
                    $next = [];
                    for ($degree = 0; $degree <= count($coefficients); ++$degree) {
                        $next[] = '0';
                    }
                    foreach ($coefficients as $degree => $coefficient) {
                        $next[$degree] = $arithmetic->subtract(
                            $next[$degree],
                            $arithmetic->multiplyIntegerValues((string) $numerator, $coefficient),
                        );
                        $next[$degree + 1] = $arithmetic->addIntegerValues(
                            $next[$degree + 1],
                            $arithmetic->multiplyIntegerValues((string) $denominator, $coefficient),
                        );
                    }
                    $coefficients = [...$next];
                }
            }

            $expectedRoots = array_values($expected);
            usort(
                $expectedRoots,
                static fn (array $left, array $right): int => $arithmetic->compareIntegerValues(
                    $arithmetic->multiplyIntegerValues((string) $left[0], (string) $right[1]),
                    $arithmetic->multiplyIntegerValues((string) $right[0], (string) $left[1]),
                ),
            );
            $context = sprintf('PROP-EXACT seed=%d case=%d', self::SEED, $case);
            self::assertSame(count($expectedRoots), $counter->countAllRealRoots($coefficients), $context);
            $intervals = $counter->isolateAllRealRoots($coefficients, maxDepth: 128);
            self::assertNotNull($intervals, $context);
            self::assertCount(count($expectedRoots), $intervals, $context);

            foreach ($expectedRoots as $index => [$numerator, $denominator]) {
                self::assertSame((string) $numerator, $intervals[$index][0], $context);
                self::assertSame((string) $denominator, $intervals[$index][1], $context);
                self::assertSame($intervals[$index][0], $intervals[$index][2], $context);
                self::assertSame($intervals[$index][1], $intervals[$index][3], $context);
            }
        }
    }

    public function testGeneratedIntegerRootsAtIntervalBoundsAreExcluded(): void
    {
        for ($case = 0; $case < 80; ++$case) {
            $roots = [];
            $factors = [];
            for ($factor = 0, $factorCount = $this->randomInt(1, 6); $factor < $factorCount; ++$factor) {
                $root = $this->randomInt(-8, 8);
                $roots[] = $root;
                $factors[] = '(x-(' . $root . '))';
            }
            $lower = $this->randomInt(-9, 7);
            $upper = $this->randomInt($lower + 1, 9);
            $expectedRoots = array_values(array_unique(array_filter(
                $roots,
                static fn (int $root): bool => $root > $lower && $root < $upper,
            )));
            sort($expectedRoots, SORT_NUMERIC);
            $equation = implode('*', $factors) . '=0';
            $context = sprintf(
                'PROP-EXACT-BOUNDS seed=%d case=%d interval=(%d,%d)',
                self::SEED,
                $case,
                $lower,
                $upper,
            );

            self::assertSame(
                count($expectedRoots),
                Math::countPolynomialRootsInRationalInterval($equation, 'x', (string) $lower, (string) $upper),
                $context,
            );
            $intervals = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                (string) $lower,
                (string) $upper,
            );
            self::assertNotNull($intervals, $context);
            self::assertCount(count($expectedRoots), $intervals, $context);
            foreach ($expectedRoots as $index => $expectedRoot) {
                self::assertTrue($intervals[$index]->isExactRoot, $context);
                self::assertSame((string) $expectedRoot, $intervals[$index]->lowerNumerator, $context);
                self::assertSame('1', $intervals[$index]->lowerDenominator, $context);
            }
        }
    }

    public function testGeneratedRepeatedIrreducibleQuadraticsAgreeAcrossRootApis(): void
    {
        $solverOptions = new SolverOptions(scanSteps: 8);

        for ($case = 0; $case < 12; ++$case) {
            $radicands = [];
            $factors = [];
            $expectedRoots = [];
            $factorCount = $this->randomInt(1, 2);
            while (count($radicands) < $factorCount) {
                $radicand = $this->randomInt(2, 20);
                $squareRoot = (int) floor(sqrt($radicand));
                if (
                    $squareRoot * $squareRoot === $radicand
                    || in_array($radicand, $radicands, true)
                ) {
                    continue;
                }

                $radicands[] = $radicand;
                $factors[] = sprintf('(x^2-%d)^%d', $radicand, $this->randomInt(1, 2));
                $root = sqrt($radicand);
                $expectedRoots[] = [-$root, $radicand, -1];
                $expectedRoots[] = [$root, $radicand, 1];
            }

            usort($expectedRoots, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
            $equation = implode('*', $factors) . '=0';
            $context = sprintf('PROP-EXACT-IRRATIONAL seed=%d case=%d equation=%s', self::SEED, $case, $equation);
            $count = Math::countPolynomialRootsInRationalInterval($equation, 'x', '-5', '5');
            $intervals = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-5',
                '5',
                solverOptions: $solverOptions,
                maximumWidth: '1/1000',
            );
            $solutions = Math::solve(
                $equation,
                'x',
                -5.0,
                5.0,
                solverOptions: $solverOptions,
            );

            self::assertSame(count($expectedRoots), $count, $context);
            self::assertNotNull($intervals, $context);
            self::assertCount(count($expectedRoots), $intervals, $context);
            self::assertCount(count($expectedRoots), $solutions, $context);

            foreach ($expectedRoots as $index => $expectedRoot) {
                [$expectedValue, $radicand, $sign] = $expectedRoot;
                $interval = $intervals[$index];
                self::assertFalse($interval->isExactRoot, $context);
                if ($sign < 0) {
                    self::assertStringStartsWith('-', $interval->lowerNumerator, $context);
                    self::assertStringStartsWith('-', $interval->upperNumerator, $context);
                    self::assertGreaterThan(
                        0,
                        $this->compareRationalSquareToInteger(
                            $interval->lowerNumerator,
                            $interval->lowerDenominator,
                            $radicand,
                        ),
                        $context,
                    );
                    self::assertLessThan(
                        0,
                        $this->compareRationalSquareToInteger(
                            $interval->upperNumerator,
                            $interval->upperDenominator,
                            $radicand,
                        ),
                        $context,
                    );
                } else {
                    self::assertFalse(str_starts_with($interval->lowerNumerator, '-'), $context);
                    self::assertFalse(str_starts_with($interval->upperNumerator, '-'), $context);
                    self::assertLessThan(
                        0,
                        $this->compareRationalSquareToInteger(
                            $interval->lowerNumerator,
                            $interval->lowerDenominator,
                            $radicand,
                        ),
                        $context,
                    );
                    self::assertGreaterThan(
                        0,
                        $this->compareRationalSquareToInteger(
                            $interval->upperNumerator,
                            $interval->upperDenominator,
                            $radicand,
                        ),
                        $context,
                    );
                }
                self::assertEqualsWithDelta($expectedValue, $solutions[$index]->value, 1.0E-7, $context);
                self::assertLessThanOrEqual(1.0E-8, $solutions[$index]->residual, $context);
            }
        }
    }

    public function testClusteredRationalRootsAgreeAcrossApproximateAndExactApis(): void
    {
        $solverOptions = new SolverOptions(scanSteps: 1);
        foreach ([10, 100, 1_000, 10_000, 1_000_000, 100_000_000, 10_000_000_000] as $separationDenominator) {
            $equation = '(x-1)*(x-(1+1/' . $separationDenominator . '))=0';
            $lower = '1-1/' . $separationDenominator;
            $upper = '1+2/' . $separationDenominator;
            $count = Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            $intervals = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                solverOptions: $solverOptions,
                maximumWidth: '1/' . ($separationDenominator * 100),
            );
            $solutions = Math::solve(
                $equation,
                'x',
                1.0 - (1.0 / $separationDenominator),
                1.0 + (2.0 / $separationDenominator),
                solverOptions: $solverOptions,
            );
            $context = 'cluster denominator=' . $separationDenominator;

            self::assertSame(2, $count, $context);
            self::assertNotNull($intervals, $context);
            self::assertCount(2, $intervals, $context);
            self::assertCount(2, $solutions, $context);
            self::assertEqualsWithDelta(
                1.0,
                $solutions[0]->value,
                1.0E-8,
                $context,
            );
            self::assertEqualsWithDelta(
                1.0 + (1.0 / $separationDenominator),
                $solutions[1]->value,
                1.0E-8,
                $context,
            );
            foreach ($intervals as $interval) {
                self::assertTrue($interval->isExactRoot, $context);
            }
        }
    }

    private function compareRationalSquareToInteger(string $numerator, string $denominator, int $integer): int
    {
        $arithmetic = new IntegerPolynomialExtractor('', []);
        $numeratorSquared = $arithmetic->multiplyIntegerValues($numerator, $numerator);
        $denominatorSquared = $arithmetic->multiplyIntegerValues($denominator, $denominator);

        return $arithmetic->compareIntegerValues(
            $numeratorSquared,
            $arithmetic->multiplyIntegerValues((string) $integer, $denominatorSquared),
        );
    }

    private function randomInt(int $minimum, int $maximum): int
    {
        $high = intdiv($this->randomState, 127773);
        $low = $this->randomState % 127773;
        $next = 16807 * $low - 2836 * $high;
        $this->randomState = $next > 0 ? $next : $next + 2147483647;

        return $minimum + ($this->randomState % ($maximum - $minimum + 1));
    }

    private static function gcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left === 0 ? 1 : $left;
    }
}
