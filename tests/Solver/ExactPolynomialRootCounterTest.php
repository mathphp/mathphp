<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Solver\ExactPolynomialRootCounter;
use MathPHP\Solver\ExactRationalInterval;
use MathPHP\Solver\InfiniteSolutionSetException;
use MathPHP\Solver\IntegerPolynomialExtractor;
use MathPHP\Solver\RationalRootInterval;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class ExactPolynomialRootCounterTest extends TestCase
{
    public function testZetaTrivialZerosAreCountedAndIsolatedExactlyOnFiniteIntervals(): void
    {
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval('zeta(x) = 0', 'x', '-8', '-1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('zeta(x) = 0', 'x', '-9', '-7'));
        self::assertSame(
            3,
            Math::countPolynomialRootsInRationalInterval('zeta(2*x + 1) = 0', 'x', '-4', '-1/2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('zeta(x - 2) = 0', 'x', '-1', '1'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'zeta(x - 2 + 1/1000000000000000000000000000000) = 0',
                'x',
                '0',
                '1',
            ),
        );
        $narrowRoots = Math::isolatePolynomialRootsOnRealLine(
            'x=sqrt(2)+sqrt(3)+sqrt(5)+sqrt(7)+sqrt(11)+sqrt(13)',
            'x',
            maximumWidth: '1/1000000000000000000000000000000',
        );
        self::assertNotNull($narrowRoots);
        self::assertCount(1, $narrowRoots);
        $narrowWidth = (new ExactRationalInterval(
            [$narrowRoots[0]->upperNumerator, $narrowRoots[0]->upperDenominator],
            [$narrowRoots[0]->upperNumerator, $narrowRoots[0]->upperDenominator],
        ))->subtract(new ExactRationalInterval(
            [$narrowRoots[0]->lowerNumerator, $narrowRoots[0]->lowerDenominator],
            [$narrowRoots[0]->lowerNumerator, $narrowRoots[0]->lowerDenominator],
        ));
        self::assertLessThanOrEqual(
            0,
            $narrowWidth->compareUpperToRational('1', '1000000000000000000000000000000'),
        );
        $coarseSum = new ExactRationalInterval(['0', '1'], ['0', '1']);
        foreach ([2, 3, 5, 7, 11, 13] as $radicand) {
            $coarseSum = $coarseSum->add((new ExactRationalInterval(
                [(string) $radicand, '1'],
                [(string) $radicand, '1'],
            ))->sqrt(100));
        }
        $lowerBound = $coarseSum->lower[0] . '/' . $coarseSum->lower[1];
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'x=sqrt(2)+sqrt(3)+sqrt(5)+sqrt(7)+sqrt(11)+sqrt(13)',
                'x',
                $lowerBound,
                '20',
            ),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('zeta(x^2 - 3) = 0', 'x', '-2', '2'),
        );
        self::assertSame(
            3,
            Math::countPolynomialRootsInRationalInterval('zeta(-x^2 - 2) = 0', 'x', '-2', '2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('zeta(x^2 - 2) = 0', 'x', '-1', '1'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'zeta(x^2 - 2 + 1/1000000000000000000000000000000) = 0',
                'x',
                '-1',
                '1',
            ),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval('zeta(x) = 0', 'x', '-8', '-1');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        foreach ([-6, -4, -2] as $index => $expected) {
            self::assertSame((string) $expected, $roots[$index]->lowerNumerator);
            self::assertSame('1', $roots[$index]->lowerDenominator);
            self::assertTrue($roots[$index]->isExactRoot);
        }

        $nonlinearRoots = Math::isolatePolynomialRootsInRationalInterval(
            'zeta(-x^2 - 2) = 0',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($nonlinearRoots);
        self::assertCount(3, $nonlinearRoots);
        self::assertFalse($nonlinearRoots[0]->isExactRoot);
        self::assertSame('0', $nonlinearRoots[1]->lowerNumerator);
        self::assertTrue($nonlinearRoots[1]->isExactRoot);
        self::assertFalse($nonlinearRoots[2]->isExactRoot);
    }

    public function testAllRealZetaTrivialZerosReportInfiniteSolutionSet(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(2*x + 1) = 0', 'x');
    }

    public function testAllRealZetaTrivialZeroIsolationReportsInfiniteSolutionSet(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('zeta(2*x + 1) = 0', 'x');
    }

    public function testRationalZetaArgumentWithNegativeDivergenceHasInfiniteAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(1/x) = 0', 'x');
    }

    public function testRationalZetaWithHigherDegreeSimplePolesHasInfiniteAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(1/(x^2 - 1)) = 0', 'x');
    }

    public function testRationalZetaSkipsRemovableHoleAndFindsOtherGenuinePole(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta((x - 1)/(x^2 - 1)) = 0', 'x');
    }

    public function testRationalZetaWithHigherDegreeSimplePolesHasInfiniteIsolatedAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('zeta(1/(x^2 - 1)) = 0', 'x');
    }

    public function testRationalZetaWithInteriorHigherDegreeSimplePoleHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(1/(x^2 - 1)) = 0', 'x', '0', '2');
    }

    public function testRationalZetaWithEvenOrderNegativePoleHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(-1/x^2) = 0', 'x');
    }

    public function testPositiveEvenPoleIsNotClassifiedAsInfiniteOnBoundedInterval(): void
    {
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('zeta(1/x^2) = 0', 'x', '1', '2'));
    }

    public function testBoundedPoleFreeRationalZetaArgumentIsSolvedOnAllRealLine(): void
    {
        $equation = 'zeta(-4/(x^2 + 1)) = 0';
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame('0', $roots[1]->lowerNumerator);
        self::assertTrue($roots[2]->isExactRoot);
        self::assertSame('1', $roots[2]->lowerNumerator);
    }

    public function testPositiveUnboundedRationalZetaRangeUsesItsFiniteMinimum(): void
    {
        $equation = 'zeta((x^4 - 4*x^2 - 4)/(x^2 + 1)) = 0';
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame('0', $roots[1]->lowerNumerator);
        self::assertFalse($roots[2]->isExactRoot);
    }

    public function testRationalZetaArgumentUnboundedBelowHasInfiniteAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(-x^4/(x^2 + 1)) = 0', 'x');
    }

    public function testPositiveDoublePoleRationalZetaHasFiniteAllRealRoots(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(1/x^2) = 0', 'x'));
        $equation = 'zeta(1/x^2 - 4) = 0';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(x^2 - 1) = 2', 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertFalse($roots[1]->isExactRoot);
    }

    public function testNegativeDoublePoleRationalZetaHasInfiniteAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(-1/x^2 - 4) = 0', 'x');
    }

    public function testRationalZetaWithHigherOrderEndpointPoleChecksInDomainSide(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(-1/x^3) = 0', 'x', '0', '1');
    }

    public function testRationalZetaWithHigherOrderUpperEndpointPoleChecksInDomainSide(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(-1/(1 - x)^3) = 0', 'x', '0', '1');
    }

    public function testRationalZetaArgumentWithNegativeDivergenceHasInfiniteIntervalRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(1/x) = 0', 'x', '-1', '0');
    }

    public function testRationalZetaArgumentWithNegativeDivergenceHasInfiniteIsolatedRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsInRationalInterval('zeta(1/x) = 0', 'x', '-1', '0');
    }

    public function testRationalZetaArgumentWithNegativeDivergenceToRightOfPoleHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(-1/x) = 0', 'x', '0', '1');
    }

    public function testRationalZetaArgumentEnumeratesExactRootsOnPoleFreeIntervals(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('zeta(-8/(x + 1)) = 0', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('zeta(1/(x + 1)) = 0', 'x', '0', '1'));

        $roots = Math::isolatePolynomialRootsInRationalInterval('zeta(-8/(x + 1)) = 0', 'x', '0', '1');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertSame('3', $roots[0]->lowerDenominator);
    }

    public function testRationalZetaArgumentEnumeratesRootsBesidePositiveDivergingEndpointPoles(): void
    {
        foreach (
            ['zeta(1/x - 4) = 0', 'zeta(1/(1 - x) - 4) = 0'] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '1');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertSame('1', $roots[0]->lowerNumerator);
            self::assertSame('2', $roots[0]->lowerDenominator);
        }
    }

    public function testConstantRationalZetaTrivialZeroReportsInfiniteRootsAroundItsHole(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('zeta(-2*x/x) = 0', 'x', '-1', '1');
    }

    public function testConstantRationalZetaNonzeroLevelHasNoRoots(): void
    {
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('zeta(-3*x/x) = 0', 'x', '-1', '1'));
    }

    public function testConstantRationalZetaTrivialZeroHasInfiniteAllRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(-2*x/x) = 0', 'x');
    }

    public function testHigherDegreeConstantRationalZetaTrivialZeroPreservesDenominatorHoles(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            'zeta((-2*x^2 + 2)/(x^2 - 1)) = 0',
            'x',
            '-2',
            '2',
        );
    }

    public function testQuadraticRationalZetaArgumentIsolatesIrrationalRootsOnPoleFreeInterval(): void
    {
        $equation = 'zeta((x^2 - 9)/(x + 2)) = 0';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));

        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertFalse($roots[1]->isExactRoot);
    }

    public function testRationalZetaArgumentRecoversPositiveDenominatorUsingSubdivision(): void
    {
        $equation = 'zeta((x^2 - 9)/(x^2 + 1)) = 0';
        self::assertSame(8, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));

        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(8, $roots);
    }

    public function testRationalZetaRangeCancelsCommonFactorsButPreservesOriginalHoles(): void
    {
        foreach (
            ['zeta(((x^2 - 1)*(x - 4))/(x^2 - 1)) = 0', 'zeta((1/2*(x^2 - 1)*(x - 4))/(x^2 - 1)) = 0'] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));

            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertTrue($roots[0]->isExactRoot);
            self::assertSame('0', $roots[0]->lowerNumerator);
        }
    }

    public function testAllRealPolynomialZetaArgumentsCountFiniteAndInfiniteTrivialZeros(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^2 - 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(x^2 + 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(x^4 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^4 - 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(x^6 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^6 - 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(x^8 + x^4 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^8 - 3) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta((x^2 + x)^2 - 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta((x^2 + x)^2 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta((x^3 + x^2)^2 - 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta((x^3 + x^2)^2 + x^2 + 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta((x^3 + x^2)^2 + (x + 1)^2 + 1) = 0', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('zeta(x^6 - 33/2*x^4 + 30*x^2 + 348) = 0', 'x'),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('zeta(x^6 - x^4 + x^2 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^8 - 4*x^2 + 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^8 - 4*x^4 + 2) = 0', 'x'));
        self::assertSame(10, Math::countPolynomialRootsOnRealLine('zeta(x^8 + 2*x^7 + 1) = 0', 'x'));
        self::assertSame(6, Math::countPolynomialRootsOnRealLine('zeta(x^18 + 7/5*x^17 + 1) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('zeta(x^256 + 256/255*x^255 - 509/255) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('zeta(x^4 + 4*x^3 + 25) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('zeta(x^4 - 2) = 0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('zeta(x^8 + x^4 + x^2 - 4) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('zeta(x^8 + x^3 - 2) = 0', 'x'));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine('zeta(x^10 - 5*x^2 + 1) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('zeta(x^2 - 2) = 0', 'x'));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'zeta(x^2 - 2 + 1/1000000000000000000000000000000) = 0',
                'x',
            ),
        );

        $roots = Math::isolatePolynomialRootsOnRealLine('zeta(x^2 - 3) = 0', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);

        $quarticRoots = Math::isolatePolynomialRootsOnRealLine('zeta(x^4 - 3) = 0', 'x');
        self::assertNotNull($quarticRoots);
        self::assertCount(2, $quarticRoots);
        self::assertSame('-1', $quarticRoots[0]->lowerNumerator);
        self::assertSame('1', $quarticRoots[1]->lowerNumerator);

        $sexticRoots = Math::isolatePolynomialRootsOnRealLine('zeta(x^6 - 3) = 0', 'x');
        self::assertNotNull($sexticRoots);
        self::assertCount(2, $sexticRoots);
        self::assertSame('-1', $sexticRoots[0]->lowerNumerator);
        self::assertSame('1', $sexticRoots[1]->lowerNumerator);

        $shiftedQuarticRoots = Math::isolatePolynomialRootsOnRealLine('zeta((x^2 + x)^2 - 3) = 0', 'x');
        self::assertNotNull($shiftedQuarticRoots);
        self::assertCount(2, $shiftedQuarticRoots);
        self::assertFalse($shiftedQuarticRoots[0]->isExactRoot);
        self::assertFalse($shiftedQuarticRoots[1]->isExactRoot);

        $sexticSquareRoots = Math::isolatePolynomialRootsOnRealLine('zeta((x^3 + x^2)^2 - 3) = 0', 'x');
        self::assertNotNull($sexticSquareRoots);
        self::assertCount(2, $sexticSquareRoots);
        self::assertFalse($sexticSquareRoots[0]->isExactRoot);
        self::assertFalse($sexticSquareRoots[1]->isExactRoot);

        $sexticTangencyRoots = Math::isolatePolynomialRootsOnRealLine(
            'zeta(x^6 - 33/2*x^4 + 30*x^2 + 348) = 0',
            'x',
        );
        self::assertNotNull($sexticTangencyRoots);
        self::assertCount(2, $sexticTangencyRoots);
        self::assertFalse($sexticTangencyRoots[0]->isExactRoot);
        self::assertFalse($sexticTangencyRoots[1]->isExactRoot);

        $octicRationalCriticalRoots = Math::isolatePolynomialRootsOnRealLine('zeta(x^8 - 4*x^2 + 1) = 0', 'x');
        self::assertNotNull($octicRationalCriticalRoots);
        self::assertCount(2, $octicRationalCriticalRoots);
        self::assertTrue($octicRationalCriticalRoots[0]->isExactRoot);
        self::assertTrue($octicRationalCriticalRoots[1]->isExactRoot);
        self::assertSame('-1', $octicRationalCriticalRoots[0]->lowerNumerator);
        self::assertSame('1', $octicRationalCriticalRoots[1]->lowerNumerator);

        $octicIrrationalCriticalRoots = Math::isolatePolynomialRootsOnRealLine('zeta(x^8 - 4*x^4 + 2) = 0', 'x');
        self::assertNotNull($octicIrrationalCriticalRoots);
        self::assertCount(2, $octicIrrationalCriticalRoots);
        self::assertFalse($octicIrrationalCriticalRoots[0]->isExactRoot);
        self::assertFalse($octicIrrationalCriticalRoots[1]->isExactRoot);

        $decicIrrationalCriticalRoots = Math::isolatePolynomialRootsOnRealLine('zeta(x^10 - 5*x^2 + 1) = 0', 'x');
        self::assertNotNull($decicIrrationalCriticalRoots);
        self::assertCount(4, $decicIrrationalCriticalRoots);
        foreach ($decicIrrationalCriticalRoots as $root) {
            self::assertFalse($root->isExactRoot);
        }

        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('zeta(-x^2 - 2) = 0', 'x');
    }

    public function testExactPolynomialRootApisAcceptPerfectRationalRadicals(): void
    {
        foreach (
            [
            'x-sqrt(4)=0' => ['2', '1'],
            'x-sqrt(1/4)=0' => ['1', '2'],
            'x-sqrt(1+3)=0' => ['2', '1'],
            'x-sqrt((2+2)/(2+2))=0' => ['1', '1'],
            'x-root(3^2+7,2)=0' => ['4', '1'],
            'x-4^(1/2)=0' => ['2', '1'],
            'x-4^0.5=0' => ['2', '1'],
            'x-16^(3/4)=0' => ['8', '1'],
            'x-4^(-1/2)=0' => ['1', '2'],
            'x-4^(2/4)=0' => ['2', '1'],
            'x-(-8)^(1/3)=0' => ['-2', '1'],
            'x-cbrt(-8)=0' => ['-2', '1'],
            'x-root(-8,3)=0' => ['-2', '1'],
            'x-root(-8,-3)=0' => ['-1', '2'],
            'x-root(81,4)=0' => ['3', '1'],
            ] as $equation => [$numerator, $denominator]
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $intervals = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($intervals);
            self::assertCount(1, $intervals);
            self::assertSame($numerator, $intervals[0]->lowerNumerator);
            self::assertSame($denominator, $intervals[0]->lowerDenominator);
            self::assertSame($numerator, $intervals[0]->upperNumerator);
            self::assertSame($denominator, $intervals[0]->upperDenominator);
            self::assertTrue($intervals[0]->isExactRoot);
        }
    }

    public function testExactPolynomialRootApisReduceElementaryRationalConstants(): void
    {
        foreach (
            [
                'x-exp(0)=0' => ['1', '1'],
                'x-sin(0)=0' => ['0', '1'],
                'x-tan(0)=0' => ['0', '1'],
                'x-sinh(0)=0' => ['0', '1'],
                'x-tanh(0)=0' => ['0', '1'],
                'x-cos(0)=0' => ['1', '1'],
                'x-cosh(0)=0' => ['1', '1'],
                'x-erfc(0)=0' => ['1', '1'],
                'x-expm1(0)=0' => ['0', '1'],
                'x-ln(1)=0' => ['0', '1'],
                'x-log10(1)=0' => ['0', '1'],
                'x-log2(1)=0' => ['0', '1'],
                'x-abs(-3/2)=0' => ['3', '2'],
                'x-sign(-2)=0' => ['-1', '1'],
                'x-floor(-1/3)=0' => ['-1', '1'],
                'x-ceil(-1/3)=0' => ['0', '1'],
                'x-ceil(1/3)=0' => ['1', '1'],
                'x-round(3/2)=0' => ['2', '1'],
                'x-round(-3/2)=0' => ['-2', '1'],
                'x-trunc(3/2)=0' => ['1', '1'],
                'x-trunc(-3/2)=0' => ['-1', '1'],
                'x-(10%3)=0' => ['1', '1'],
                'x-(-10%3)=0' => ['-1', '1'],
                'x-(10%-3)=0' => ['1', '1'],
                'exp(0)*x-1=0' => ['1', '1'],
                'cos(0)*x-1=0' => ['1', '1'],
            ] as $equation => [$expectedNumerator, $expectedDenominator]
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertSame($expectedNumerator, $roots[0]->lowerNumerator, $equation);
            self::assertSame($expectedDenominator, $roots[0]->lowerDenominator, $equation);
            self::assertSame($expectedNumerator, $roots[0]->upperNumerator, $equation);
            self::assertSame($expectedDenominator, $roots[0]->upperDenominator, $equation);
            self::assertTrue($roots[0]->isExactRoot, $equation);
        }
    }

    public function testRationalRadicalConstantsAreCountedAndIsolatedExactly(): void
    {
        foreach (['x-sqrt(2)=0', 'x-2^(1/2)=0', 'x=sqrt(2)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertFalse($roots[0]->isExactRoot);
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '1', '2'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            $intervalRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '1', '2');
            self::assertNotNull($intervalRoots);
            self::assertCount(1, $intervalRoots);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x+sqrt(2)=0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sqrt(2)-x=0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x=sqrt(-1)', 'x'));
        $rationalRoot = Math::isolatePolynomialRootsOnRealLine('x=sqrt(1/4)', 'x');
        self::assertNotNull($rationalRoot);
        self::assertSame('1', $rationalRoot[0]->lowerNumerator);
        self::assertSame('2', $rationalRoot[0]->lowerDenominator);
        self::assertTrue($rationalRoot[0]->isExactRoot);
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('x=sqrt(1/4)', 'x', '1/2', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=sqrt(1/4)', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('-x=sqrt(1/4)', 'x', '-1', '0'));

        foreach (
            [
                ['x=cbrt(-2)', '-2', '-1'],
                ['x=root(-8,3)', '-3', '-1'],
                ['x=(-8)^(1/3)', '-3', '-1'],
                ['x=root(8,3)', '1', '3'],
                ['x=root(8,-3)', '0', '1'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x=root(-8,2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x=root(0,-3)', 'x'));
    }

    public function testConstantSquareRootSumsAreCountedAndIsolatedOnTheRealLine(): void
    {
        foreach (
            [
                'x=sqrt(2)+sqrt(3)',
                'sqrt(3)+sqrt(2)=x',
                '-x=sqrt(2)+sqrt(3)',
                'x=sqrt(3)-sqrt(2)',
                'x=sqrt(2)-sqrt(3)',
                'x+1=sqrt(2)+sqrt(3)',
                'x-1=sqrt(2)+sqrt(3)',
                '-x+1=sqrt(2)+sqrt(3)',
                'x=2*sqrt(2)+3*sqrt(3)',
                'x=sqrt(2)*2+sqrt(3)*3',
                'x=2*sqrt(2)-3*sqrt(3)',
                'x=sqrt(2)/3+sqrt(3)/2',
                'x=-2*sqrt(2)+sqrt(3)/2',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertFalse($roots[0]->isExactRoot, $equation);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x=sqrt(2)+sqrt(-3)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=sqrt(2)+sqrt(3)',
            'x',
            '3',
            '4',
        ));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval(
            'x=sqrt(2)+sqrt(3)',
            'x',
            '0',
            '3',
        ));
        self::assertCount(1, Math::isolatePolynomialRootsInRationalInterval(
            'x=sqrt(2)+sqrt(3)',
            'x',
            '3',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x+1=sqrt(2)+sqrt(3)',
            'x',
            '2',
            '3',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            '-x+1=sqrt(2)+sqrt(3)',
            'x',
            '-3',
            '-2',
        ));
    }

    public function testThreeConstantSquareRootsUseAnExactResultantOnTheRealLine(): void
    {
        foreach (
            [
                'x=sqrt(2)+sqrt(3)+sqrt(5)',
                'x=sqrt(2)+sqrt(3)+sqrt(5)+sqrt(7)',
                'x=sqrt(2)+sqrt(3)+sqrt(5)+sqrt(7)+sqrt(11)',
                'x=sqrt(2)+sqrt(3)+sqrt(5)+sqrt(7)+sqrt(11)+sqrt(13)',
                'x=sqrt(1/4)+sqrt(2)+sqrt(3)',
                'x=sqrt(0)+sqrt(4)+sqrt(9)',
                'x=sqrt(2)+sqrt(2)+sqrt(3)',
                'x=sqrt(2)+sqrt(2)+sqrt(2)',
                'x=sqrt(2)+sqrt(2)+sqrt(2)+sqrt(2)+sqrt(2)+sqrt(3)',
                'x=sqrt(2)-sqrt(2)+sqrt(3)',
                'x+1=2*sqrt(2)+sqrt(3)-sqrt(5)',
                '-x=sqrt(2)-sqrt(3)-sqrt(5)',
                'x=2*(sqrt(2)+sqrt(3)+sqrt(5))',
                'x=(sqrt(2)+sqrt(3)+sqrt(5))/2',
                'x=-2*(sqrt(2)-sqrt(3)+sqrt(5))',
                'x=1+2*(sqrt(2)+sqrt(3))',
                'x=2*(sqrt(2)+sqrt(3))-1',
                'x=(sqrt(2)+sqrt(3))/2+3/2',
                'x=sqrt(2)+1',
                'x=2*sqrt(2)-1',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
        }
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=sqrt(2)+sqrt(3)+sqrt(5)',
            'x',
            '5',
            '6',
        ));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval(
            'x=sqrt(2)+sqrt(3)+sqrt(5)',
            'x',
            '0',
            '5',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=2*(sqrt(2)+sqrt(3)+sqrt(5))',
            'x',
            '10',
            '11',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=(sqrt(2)+sqrt(3)+sqrt(5))/2',
            'x',
            '2',
            '3',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=1+2*(sqrt(2)+sqrt(3))',
            'x',
            '7',
            '8',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x=2*(sqrt(2)+sqrt(3))-1',
            'x',
            '5',
            '6',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=sqrt(2)+1', 'x', '2', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=2*sqrt(2)-1', 'x', '1', '2'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'x=sqrt(2)+sqrt(8)-3*sqrt(2)',
                'x',
                '-1',
                '1',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'x=sqrt(2)+sqrt(8)-3*sqrt(2)',
                'x',
                '0',
                '1',
            ),
        );
        $rationalSum = Math::isolatePolynomialRootsOnRealLine('x=sqrt(0)+sqrt(4)+sqrt(9)', 'x');
        self::assertNotNull($rationalSum);
        self::assertCount(1, $rationalSum);
        self::assertTrue($rationalSum[0]->isExactRoot);
        self::assertSame('5', $rationalSum[0]->lowerNumerator);
        self::assertSame('1', $rationalSum[0]->lowerDenominator);
    }

    public function testExactRootApisReduceAbsoluteValueEqualityAndPreservePoles(): void
    {
        $equation = 'abs(x^2 - 1) = abs(x - 1)';
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        foreach (['-2', '0', '1'] as $index => $expectedRoot) {
            self::assertSame($expectedRoot, $roots[$index]->lowerNumerator);
            self::assertSame('1', $roots[$index]->lowerDenominator);
            self::assertSame($expectedRoot, $roots[$index]->upperNumerator);
            self::assertSame('1', $roots[$index]->upperDenominator);
        }

        $holeEquation = 'abs((x - 1)/(x - 1)) = abs(0)';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($holeEquation, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($holeEquation, 'x'));

        $identityWithHole = 'abs((x - 1)/(x - 1)) = abs(1)';
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine($identityWithHole, 'x');
    }

    public function testRationalRootCountingExcludesCancelledAndRepeatedPoles(): void
    {
        $cases = [
            '(x^2-1)/(x-1)=0' => ['-1'],
            '(x^2-1)/(x-1)=1' => ['0'],
            '(x^2-1)/(x-1)=2' => [],
            '(x-1)^2/(x-1)=0' => [],
            '(x^2-1)/(x-1)^2=0' => ['-1'],
            '(x-1)/(x^2-1)=0' => [],
            '(x-2)/(x^2-1)=0' => ['2'],
        ];
        foreach ($cases as $equation => $expectedRoots) {
            self::assertSame(\count($expectedRoots), Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $isolated = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($isolated, $equation);
            self::assertCount(\count($expectedRoots), $isolated, $equation);
            foreach ($expectedRoots as $index => $expectedRoot) {
                self::assertTrue($isolated[$index]->isExactRoot, $equation);
                self::assertSame($expectedRoot, $isolated[$index]->lowerNumerator, $equation);
                self::assertSame('1', $isolated[$index]->lowerDenominator, $equation);
            }
        }

        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('(x^2-1)/(x-1)=2', 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('(x-2)/(x^2-1)=0', 'x', '2', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('(x-2)/(x^2-1)=0', 'x', '1', '3'));
    }

    public function testAbsoluteValuesOfPositiveInjectiveFunctionsReduceToArgumentEquality(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(exp(x^2))=abs(exp(2-x^2))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(erfc(x))=abs(erfc(2-x))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(erfcx(x))=abs(erfcx(2-x))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(exp((x^2-1)/(x-1)))=abs(exp(0))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(gammainc(2, x^2 - 1)) = abs(gammainc(2, 3))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(gammaincc(2, x^2)) = abs(gammaincc(2, 1/4))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(betainc(2, 3, x^2)) = abs(betainc(2, 3, 1/4))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(betaincc(2, 3, x^2)) = abs(betaincc(2, 3, 1/4))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs(gammaincc(2, (x^2 - 1)/(x - 1))) = abs(gammaincc(2, 2))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs(gammainc(2, x)) = abs(gammainc(2, -1))',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine(
                'abs(betainc(2, 3, x)) = abs(betainc(2, 3, x))',
                'x',
            );
            self::fail('Expected equal absolute beta calls to have infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
        }
    }

    public function testAbsolutePuncturedOddFunctionsExcludeZeroArguments(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(csch(x))=abs(csch(2-x))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(coth(x))=abs(coth(2-x))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(acsch((x^2-1)/(x-1)))=abs(acsch(1/2))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs(csch(x))=abs(csch(2*x))',
            'x',
        ));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine(
            'abs(csch(x))=abs(csch(2*x))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs(csch(x))=abs(csch(0))',
            'x',
        ));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine(
            'abs(acsch(x))=abs(acsch(0))',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine('abs(csch(x))=abs(csch(-x))', 'x');
            self::fail('Expected infinitely many roots away from the excluded zero argument.');
        } catch (InfiniteSolutionSetException) {
        }
    }

    public function testAbsolutePositiveEvenHyperbolicFunctionsReduceToAbsoluteArguments(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(cosh(x^2))=abs(cosh(1))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(sech(x))=abs(sech(2-x))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(sech((x^2-1)/(x-1)))=abs(sech(0))',
            'x',
        ));
    }

    public function testMatchingHypotEquationsReduceToSumsOfSquares(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'hypot(x,1)=hypot(2,0)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(hypot(x,1))=abs(hypot(2,0))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'hypot((x^2-1)/(x-1),0)=hypot(0,2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'hypot(x,0)=sqrt((x-1)^2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'hypot(x,1)=sqrt(4*x^2+1)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'hypot(x,1)=sqrt((-2)*(-x^2)+1)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine('hypot(x,1)=hypot(1,x)', 'x');
            self::fail('Expected an identity to have infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::countPolynomialRootsOnRealLine('hypot(x,1)=sqrt(x^2+1)', 'x');
            self::fail('Expected matching norm forms to produce an identity.');
        } catch (InfiniteSolutionSetException) {
        }
    }

    public function testAbsoluteEqualitiesBetweenOddRootDegreesUseExactPowers(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(cbrt(x))=abs(root(1,5))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(root(x,-3))=abs(root(1,5))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(root(x,33))=abs(root(1,33))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(cbrt((x^2-1)/(x-1)))=abs(root(1/8,5))',
            'x',
        ));
        $roots = Math::isolatePolynomialRootsOnRealLine(
            'abs(cbrt((x^2-1)/(x-1)))=abs(root(8,9))',
            'x',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('-3', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testAbsoluteOddRootEqualitiesCanonicalizeFractionalPowerNotations(): void
    {
        foreach (
            [
                'abs(cbrt(x))=abs(x^(1/3))',
                'abs(root(x,3))=abs(x^(1/3))',
                'abs(root(x,-3))=abs(x^(-1/3))',
            ] as $identity
        ) {
            try {
                Math::countPolynomialRootsOnRealLine($identity, 'x');
                self::fail('Expected infinitely many roots for equivalent root notations.');
            } catch (InfiniteSolutionSetException) {
            }
        }
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(x^(1/3))=abs(root(1,5))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(x^(2/3))=abs(root(1,3))',
            'x',
        ));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine(
            'abs(x^2)=abs(x^3)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(x^(-2))=abs((x-1)^(-1))',
            'x',
        ));
        $roots = Math::isolatePolynomialRootsOnRealLine(
            'abs(((x^2-1)/(x-1))^(1/3))=abs(root(8,9))',
            'x',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('-3', $roots[0]->lowerNumerator);
    }

    public function testAbsoluteEvenRootEqualitiesRequireProvenNonnegativeRadicands(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^2))=abs(root(1,2))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(root(x^2,4))=abs(root(1,4))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs((x^2+1)^(1/2))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^2+2*x+1))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x^2+2*x+1)/(x^2+1)))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^4-2*x^2+2))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x^2+1)/(x^4-2*x^2+2)))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^4-4*x^2+4))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^4-4*x^2+4))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^6-4*x^4+4*x^2))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^6-4*x^4+4*x^2+1))=abs(sqrt(1))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^6-3*x^4+4))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^6-3*x^4+3))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(1/2*x^6-3/2*x^4+2))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(1/2*x^6-3/2*x^4+3/2))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x-1000)^6-3*(x-1000)^4+4))=abs(sqrt(0))',
            'x',
        ));
    }

    public function testEvenRootDomainUsesRootFreeSignCertificatesForPositivePolynomials(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x^6-3*x^4+4+1/1000000000000000000000000000000)'
                . '/(x^6-3*x^4+4+1/1000000000000000000000000000000)))=abs(sqrt(1))',
            'x',
        );
    }

    public function testAbsoluteEvenRootZeroLevelsAcceptBoundaryRootsOnPartialDomains(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^2-1))=abs(sqrt(0))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'abs(root(x^2-1,4))=abs(root(0,4))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs((x^2-1)^(-1/2))=abs(sqrt(0))',
            'x',
        ));
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'abs(sqrt(x^2-1))=abs(sqrt(1))',
            'x',
        ));
        $rationalHoleEquation = 'abs(sqrt((x-1)/(x-1)-1))=abs(sqrt(x^2-1))';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($rationalHoleEquation, 'x'));
        $rationalHoleRoots = Math::isolatePolynomialRootsOnRealLine($rationalHoleEquation, 'x');
        self::assertNotNull($rationalHoleRoots);
        self::assertCount(1, $rationalHoleRoots);
        self::assertSame('-1', $rationalHoleRoots[0]->lowerNumerator);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x-1)^2/(x-1)^2-1))=abs(sqrt(x^2-1))',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'abs(((x-1)/(x-1)-1)^(-1/2))=abs(sqrt(0))',
            'x',
        ));

        $equation = 'abs(sqrt(x^2-1))=abs(sqrt(0))';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
    }

    public function testAbsoluteEvenRootZeroIdentityWithHoleReportsInfiniteSolutions(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine(
            'abs(sqrt((x-1)/(x-1)-1))=abs(sqrt(0))',
            'x',
        );
    }

    public function testSingleAbsoluteEvenRootZeroLevelsReduceToRadicandZeros(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('abs(sqrt(x^2-1))=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('abs(root(x^2-1,4))=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('abs((x^2-1)^(1/2))=0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('abs((x^2-1)^(-1/2))=0', 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine('abs(sqrt(x^2-1))=0', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);
    }

    public function testSingleAbsoluteEvenRootZeroIdentityPreservesItsHole(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('abs(sqrt((x-1)/(x-1)-1))=0', 'x');
    }

    public function testExactRootApisReduceAbsoluteValueToConstantLevels(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('abs(x) = 2', 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine('abs(x) = 2', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-2', $roots[0]->lowerNumerator);
        self::assertSame('2', $roots[1]->lowerNumerator);

        $zeroLevel = 'abs((x^2 - 1)/(x - 1)) = 0';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($zeroLevel, 'x'));
        $zeroRoots = Math::isolatePolynomialRootsOnRealLine($zeroLevel, 'x');
        self::assertNotNull($zeroRoots);
        self::assertCount(1, $zeroRoots);
        self::assertSame('-1', $zeroRoots[0]->lowerNumerator);
        self::assertSame('1', $zeroRoots[0]->lowerDenominator);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('abs(x) = -2', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('abs(x) = -2', 'x'));
    }

    public function testExactRootApisReduceFunctionZeroLevels(): void
    {
        foreach (
            [
                'sign((x^2 - 1)/(x - 1)) = 0' => ['-1'],
                'sqrt((x^2 - 1)/(x - 1)) = 0' => ['-1'],
                'cbrt(x^2 - 1) = 0' => ['-1', '1'],
                'root(x^2 - 1, 3) = 0' => ['-1', '1'],
            ] as $equation => $expectedRoots
        ) {
            self::assertSame(\count($expectedRoots), Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(\count($expectedRoots), $roots, $equation);
            foreach ($expectedRoots as $index => $expectedRoot) {
                self::assertSame($expectedRoot, $roots[$index]->lowerNumerator, $equation);
                self::assertSame('1', $roots[$index]->lowerDenominator, $equation);
            }
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('root(x, -3) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('root(x, -3) = 0', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('sqrt(x^2 - 1) = 0', 'x', '0', '2'),
        );
    }

    public function testExactRootApisReduceElementaryAndSpecialFunctionZeroLevels(): void
    {
        $cases = [
            'ln((x^2 - 1)/(x - 1)) = 0' => 1,
            'log10(x^2 - 1) = 0' => 2,
            'log(x^2 - 1, 2) = 0' => 2,
            'log1p((x^2 - 1)/(x - 1)) = 0' => 1,
            'sinc(x^2 - 1) = 1' => 2,
            'sincpi((x^2 - 1)/(x - 1)) = 1' => 1,
            'exp(x^2 - 1) = 1' => 2,
            'cosh(x^2 - 1) = 1' => 2,
            'sech((x^2 - 1)/(x - 1)) = 1' => 1,
            'expm1(x^2 - 1) = 0' => 2,
            'atanh(x^2 - 1) = 0' => 2,
            'erf((x^2 - 1)/(x - 1)) = 0' => 1,
            'erfcinv(x - 1) = 0' => 1,
            'acos(x^2 - 1) = 0' => 2,
            'acosh(x^2 - 1) = 0' => 2,
            'asec(x^2 - 1) = 0' => 2,
            'asech(1/(x^2 + 1)) = 0' => 1,
            'lambertw(x^2 - 1) = 0' => 2,
            'lambertw(x^2 - 1, -1) = 0' => 0,
            'besseli(2, x^2 - 1) = 0' => 2,
            'besseli(-3, (x^2 - 1)/(x - 1)) = 0' => 1,
            'besseli(0, x) = 0' => 0,
            'gammainc(2, x^2 - 1) = 0' => 2,
            'gammaincinv(3/2, x^2 - 1) = 0' => 2,
            'betainc(2, 3, x^2 - 1) = 0' => 2,
            'betaincc(2, 3, x^2 - 1) = 0' => 2,
            'betaincc(2, 3, 2 - x^2) = 0' => 2,
            'betaincinv(2, 3, x^2 - 1) = 0' => 2,
            'betainccinv(2, 3, x^2 - 1) = 0' => 2,
            'betainccinv(2, 3, 2 - x^2) = 0' => 2,
            'gammainc(x, x^2 - 1) = 0' => null,
            'betainc(0, 3, x^2 - 1) = 0' => null,
            'exp(x^2 + 1) = 0' => 0,
            'cosh(x^3 - 2) = 0' => 0,
            'sinc(x) = 2' => 0,
            'sincpi(x) = -2' => 0,
            'exp(x) = -1' => 0,
            'cosh(x) = 1/2' => 0,
            'sech(x) = 2' => 0,
            'sec(x^2 - 1) = 0' => 0,
            'csc(x^3 - 2) = 0' => 0,
            'sech((x^2 - 1)/(x - 1)) = 0' => 0,
            'erfc(x^4 - 3) = 0' => 0,
            'erfcx(x^2 + x + 1) = 0' => 0,
            'gamma(x^2 - 3) = 0' => 0,
            'gammaincc(2, x) = 0' => 0,
            'gammainccinv(2, x) = 0' => 1,
            'csch(x^2 - 1) = 0' => 0,
            'coth(x^3 - 2) = 0' => 0,
            'acsch((x^2 - 1)/(x - 1)) = 0' => 0,
            'acsc(x^2 + 1) = 0' => 0,
            'acot(x^2 + 1) = 0' => 0,
            'acoth(x^2 + 1) = 0' => 0,
            'beta(x^2 + 1, 2) = 0' => 0,
            'beta(x^2 - 3, 1/2) = 0' => 6,
            'beta((x^2 - 1)/(x - 1), 1/2) = 0' => 171,
            'doublefactorial(x) = 0' => 0,
            'x! = 0' => 0,
            '(x^2 + 1)! = 0' => 0,
        ];
        foreach ($cases as $equation => $expectedCount) {
            $actual = Math::countPolynomialRootsOnRealLine($equation, 'x');
            if ($expectedCount === null) {
                self::assertNull($actual, $equation);
            } else {
                self::assertSame($expectedCount, $actual, $equation);
            }
        }

        self::assertSame(
            6,
            Math::countPolynomialRootsInRationalInterval('beta(x^2 - 3, 1/2) = 0', 'x', '-2', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'beta((x^2 - 1)/(x - 1), 1/2) = 0',
                'x',
                '0',
                '2',
            ),
        );
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval(
                'beta((x^2 - 1)/(x - 1), 1/2) = 0',
                'x',
                '0',
                '2',
            ),
        );
        $nonlinearBetaRoots = Math::isolatePolynomialRootsOnRealLine('beta(x^2 - 3, 1/2) = 0', 'x');
        self::assertNotNull($nonlinearBetaRoots);
        self::assertCount(6, $nonlinearBetaRoots);
        foreach ($nonlinearBetaRoots as $root) {
            self::assertFalse($root->isExactRoot);
        }
        $sincRoots = Math::isolatePolynomialRootsOnRealLine('sinc(x^2 - 1) = 1', 'x');
        self::assertNotNull($sincRoots);
        self::assertCount(2, $sincRoots);
        self::assertSame('-1', $sincRoots[0]->lowerNumerator);
        self::assertSame('1', $sincRoots[1]->lowerNumerator);
        $sincHoleRoots = Math::isolatePolynomialRootsOnRealLine(
            'sincpi((x^2 - 1)/(x - 1)) = 1',
            'x',
        );
        self::assertNotNull($sincHoleRoots);
        self::assertCount(1, $sincHoleRoots);
        self::assertSame('-1', $sincHoleRoots[0]->lowerNumerator);
        foreach (
            [
                'exp(x^2 + 1) = 0',
                'cosh(x^3 - 2) = 0',
                'exp(x) = -1',
                'cosh(x) = 1/2',
                'sech(x) = 2',
                'sec(x^2 - 1) = 0',
                'csc(x^3 - 2) = 0',
                'sech((x^2 - 1)/(x - 1)) = 0',
                'erfc(x^4 - 3) = 0',
                'erfcx(x^2 + x + 1) = 0',
                'gamma(x^2 - 3) = 0',
                'csch(x^2 - 1) = 0',
                'coth(x^3 - 2) = 0',
                'acsch((x^2 - 1)/(x - 1)) = 0',
                'acsc(x^2 + 1) = 0',
                'acot(x^2 + 1) = 0',
                'sinc(x) = 2',
                'sincpi(x) = -2',
                'doublefactorial(x) = 0',
                'x! = 0',
                '(x^2 + 1)! = 0',
                'acoth(x^2 + 1) = 0',
            ] as $equation
        ) {
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
        }
    }

    public function testPositiveOrderPolygammaHasNoZerosForStrictlySignedRationalArguments(): void
    {
        foreach ([1, 2, 3, 4, 5] as $order) {
            foreach (['x^2 + 1', '(x^2 + 1)/(x^2 + 2)', '(-x^2 - 1)/(-x^2 - 2)'] as $argument) {
                $equation = 'polygamma(' . $order . ', ' . $argument . ') = 0';
                self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
                self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            }
        }

        self::assertNull(Math::countPolynomialRootsOnRealLine('polygamma(3, x) = 0', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('polygamma(0, x^2 + 1) = 0', 'x'));
    }

    public function testTrigammaAndTetragammaHaveNoZerosOnPositiveRationalArguments(): void
    {
        foreach (['trigamma', 'tetragamma'] as $function) {
            $equation = $function . '((x^2 + 1)/(x^2 + 2)) = 0';
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }

        self::assertNull(Math::countPolynomialRootsOnRealLine('trigamma(x) = 0', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('digamma(x^2 + 1) = 0', 'x'));
    }

    public function testIdenticalFunctionCallsReportInfiniteExactRootSets(): void
    {
        $caught = 0;
        foreach (
            [
                'sin(x) = sin(x)',
                'erf(x^2) = erf(x^2)',
                'sinc(x/x) = sinc(1)',
                'floor(x) = floor(x)',
                'sign(x) = sign(x)',
                'erfcx(x^2) = erfcx(x^2)',
                'sqrt(x) = sqrt(x)',
                'gamma(x) = gamma(x)',
                'zeta(x) = zeta(x)',
                'zeta(x - 1) = zeta(x - 1)',
                'lgamma(x) = lgamma(x)',
                'digamma(x) = digamma(x)',
                'trigamma(x) = trigamma(x)',
                'tetragamma(x) = tetragamma(x)',
                'polygamma(3, x) = polygamma(3, x)',
                'hypot(x, x^2) = hypot(x, x^2)',
                'min(x, 0, x^2) = min(x, 0, x^2)',
                'max(x, 0, x^2) = max(x, 0, x^2)',
                'besseli(2, x) = besseli(2, x)',
                'besselj(0, x) = besselj(0, x)',
                'atan2(x, 0) = atan2(x, 0)',
                'beta(x, 2) = beta(x, 2)',
                'asin(x/10) = asin(x/10)',
                'acos(x/10) = acos(x/10)',
                'asec(x + 3) = asec(x + 3)',
                'acsc(x + 3) = acsc(x + 3)',
                'acosh(x + 3) = acosh(x + 3)',
                'asec(x) = asec(x)',
                'acsc(x) = acsc(x)',
                'acosh(x) = acosh(x)',
                'atanh(x/10) = atanh(x/10)',
                'tan(x/10) = tan(x/10)',
                'sec(x/10) = sec(x/10)',
                'csc(x + 1) = csc(x + 1)',
                'cot(x + 1) = cot(x + 1)',
                'erfinv(x/2) = erfinv(x/2)',
                'erfcinv(x + 1) = erfcinv(x + 1)',
                'root(x, 2) = root(x, 2)',
                'root(x, -3) = root(x, -3)',
            ] as $equation
        ) {
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('Expected infinitely many real roots for ' . $equation);
            } catch (InfiniteSolutionSetException) {
                ++$caught;
            }
            try {
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
                self::fail('Expected infinitely many interval roots for ' . $equation);
            } catch (InfiniteSolutionSetException) {
                ++$caught;
            }
        }
        self::assertSame(78, $caught);
        $poleIdentity = Math::countPolynomialRootsOnRealLine('gamma(x/x - 1) = gamma(-1)', 'x');
        self::assertContains($poleIdentity, [0, null]);
        $polygammaPoleIdentity = Math::countPolynomialRootsOnRealLine(
            'polygamma(3, x/x - 1) = polygamma(3, -1)',
            'x',
        );
        self::assertContains($polygammaPoleIdentity, [0, null]);
        $undefinedAtan2Identity = Math::countPolynomialRootsOnRealLine(
            'atan2(x - x, 0) = atan2(0, 0)',
            'x',
        );
        self::assertContains($undefinedAtan2Identity, [0, null]);
        $zetaPoleIdentity = Math::countPolynomialRootsOnRealLine('zeta(x - x + 1) = zeta(1)', 'x');
        self::assertContains($zetaPoleIdentity, [0, null]);
    }

    public function testExactRootApisEnumerateDyadicBetaZerosAndExcludeShapePoles(): void
    {
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(x, 1/2) = 0', 'x'));
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(1/2, x) = 0', 'x'));
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(2*x + 1, 1/2) = 0', 'x'));
        self::assertSame(85, Math::countPolynomialRootsOnRealLine('beta(2*x + 1/2, -x + 1/2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(2*x + 1, -x + 1/2) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('beta(x, 1/2) = 0', 'x', '-2', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('beta(2*x + 1, 1/2) = 0', 'x', '-1', '0'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('beta(2*x + 1/2, -x + 1/2) = 0', 'x', '-2', '0'),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(x, 2) = 0', 'x'));
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(x, 1/3) = 0', 'x'));
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(x/10, 1/2) = 0', 'x'));
        self::assertSame(171, Math::countPolynomialRootsOnRealLine('beta(3*x + 1, 1/2) = 0', 'x'));
        self::assertSame(342, Math::countPolynomialRootsOnRealLine('beta(x, x + 1/2) = 0', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('beta(x, x + 1/2) = 0', 'x', '-171', '-170'),
        );
        $lowerBoundaryRoots = Math::isolatePolynomialRootsInRationalInterval(
            'beta(x, x + 1/2) = 0',
            'x',
            '-171',
            '-170',
        );
        self::assertNotNull($lowerBoundaryRoots);
        self::assertCount(2, $lowerBoundaryRoots);
        self::assertSame('-683', $lowerBoundaryRoots[0]->lowerNumerator);
        self::assertSame('4', $lowerBoundaryRoots[0]->lowerDenominator);
        self::assertSame('-681', $lowerBoundaryRoots[1]->lowerNumerator);
        self::assertSame('4', $lowerBoundaryRoots[1]->lowerDenominator);
        self::assertSame(
            684,
            Math::countPolynomialRootsOnRealLine('beta(x^2 - 171, -170.5) = 0', 'x'),
        );
        self::assertSame(0.0, Math::evaluate('beta(-170.5, -170.5)'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('beta(x/10, 1/2) = 0', 'x', '-6', '-4'),
        );
        $rationalSlopeRoots = Math::isolatePolynomialRootsInRationalInterval(
            'beta(x/10, 1/2) = 0',
            'x',
            '-6',
            '-4',
        );
        self::assertNotNull($rationalSlopeRoots);
        self::assertCount(1, $rationalSlopeRoots);
        self::assertSame('-5', $rationalSlopeRoots[0]->lowerNumerator);
        self::assertSame('1', $rationalSlopeRoots[0]->lowerDenominator);

        $roots = Math::isolatePolynomialRootsOnRealLine('beta(x, 1/2) = 0', 'x');
        self::assertNotNull($roots);
        self::assertCount(171, $roots);
        self::assertSame('-341', $roots[0]->lowerNumerator);
        self::assertSame('2', $roots[0]->lowerDenominator);
        self::assertSame('-1', $roots[170]->lowerNumerator);
        self::assertSame('2', $roots[170]->lowerDenominator);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertTrue($roots[170]->isExactRoot);
        self::assertSame(0.0, Math::evaluate('beta(-1/2, 1/2)'));
        self::assertSame(0.0, Math::evaluate('beta(-341/2, 1/2)'));

        $affineRoots = Math::isolatePolynomialRootsOnRealLine('beta(2*x + 1, 1/2) = 0', 'x');
        self::assertNotNull($affineRoots);
        self::assertCount(171, $affineRoots);
        self::assertSame('-343', $affineRoots[0]->lowerNumerator);
        self::assertSame('4', $affineRoots[0]->lowerDenominator);
        self::assertSame('-3', $affineRoots[170]->lowerNumerator);
        self::assertSame('4', $affineRoots[170]->lowerDenominator);
        self::assertSame(0.0, Math::evaluate('beta(2*(-343/4) + 1, 1/2)'));
        self::assertSame(0.0, Math::evaluate('beta(2*(-3/4) + 1, 1/2)'));

        $bothAffineRoots = Math::isolatePolynomialRootsOnRealLine(
            'beta(2*x + 1/2, -x + 1/2) = 0',
            'x',
        );
        self::assertNotNull($bothAffineRoots);
        self::assertCount(85, $bothAffineRoots);
        self::assertSame('-85', $bothAffineRoots[0]->lowerNumerator);
        self::assertSame('-1', $bothAffineRoots[84]->lowerNumerator);

        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval('beta(x, 1/2) = 0', 'x', '-2', '1');
        self::assertNotNull($boundedRoots);
        self::assertCount(2, $boundedRoots);
        self::assertSame('-3', $boundedRoots[0]->lowerNumerator);
        self::assertSame('2', $boundedRoots[0]->lowerDenominator);
        self::assertSame('-1', $boundedRoots[1]->lowerNumerator);
        self::assertSame('2', $boundedRoots[1]->lowerDenominator);
    }

    public function testAllRealBetaRootsClassifyPeriodicPolynomialArgumentsByExactRange(): void
    {
        foreach (['sin', 'cos', 'tan', 'cot', 'sec', 'csc'] as $function) {
            try {
                Math::countPolynomialRootsOnRealLine(
                    'beta(' . $function . '(x^2 + x), ' . $function . '(x^2 + x)) = 0',
                    'x',
                );
                self::fail('A reachable shared periodic beta zero level has infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (
            [
                'beta(sin(x^2 + x), cos(x^2 + x)) = 0',
                'beta(tan(x^2 + x), cot(x^2 + x)) = 0',
                'beta(sec(x^2 + x), csc(x^2 + x)) = 0',
            ] as $equation
        ) {
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('A defined periodic beta zero level has infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (
            [
                fn () => Math::countPolynomialRootsOnRealLine('beta(sin(x), 1/2) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(1/2, sin(x^2 + x)) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(sin(2*x - 3), 1/2) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(cos(x), 1/2) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(1/2, cos(x^2 + x)) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(sec(x), 1/2) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(1/2, csc(x^2 + x)) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(tan(x), 1/2) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(1/2, cot(x^2 + x)) = 0', 'x'),
            ] as $solve
        ) {
            try {
                $solve();
                self::fail('A reachable periodic beta zero level has infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(sin(x), 2) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('beta(sin(x), 2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(sin(x), -1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(sec(x), 2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(csc(x), 2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(tan(x), 2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(cot(x), 2) = 0', 'x'));
    }

    public function testFiniteIntervalBetaRootsIsolatePeriodicPolynomialArgumentsExactly(): void
    {
        foreach (
            [
                ['beta(sin(x), 1/2) = 0', '-1', '1', 1],
                ['beta(sin(2*x), 1/2) = 0', '-1', '1', 1],
                ['beta(sin(x^2), 1/2) = 0', '0', '2', 1],
                ['beta(1/2, sin(x^2)) = 0', '0', '2', 1],
                ['beta(cos(x), 1/2) = 0', '-3', '3', 2],
                ['beta(1/2, cos(x^2)) = 0', '0', '2', 1],
                ['beta(sec(x), 1/2) = 0', '2', '3', 1],
                ['beta(1/2, csc(x^2)) = 0', '1.967', '1.968', 1],
                ['beta(tan(x), 1/2) = 0', '2.15', '2.17', 1],
                ['beta(1/2, cot(x)) = 0', '2.54', '2.57', 1],
                ['beta(sin(x), 2) = 0', '-2', '2', 0],
                ['beta(cos(x), 2) = 0', '-2', '2', 0],
            ] as [$equation, $lower, $upper, $expectedCount]
        ) {
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            foreach ($roots as $root) {
                self::assertFalse($root->isExactRoot, $equation);
                $arithmetic = new IntegerPolynomialExtractor('', []);
                $upperProduct = $arithmetic->multiplyIntegerValues(
                    $root->upperNumerator,
                    $root->lowerDenominator,
                );
                $lowerProduct = $arithmetic->multiplyIntegerValues(
                    $root->lowerNumerator,
                    $root->upperDenominator,
                );
                $widthNumerator = $arithmetic->subtract($upperProduct, $lowerProduct);
                $widthDenominator = $arithmetic->multiplyIntegerValues(
                    $root->upperDenominator,
                    $root->lowerDenominator,
                );
                self::assertLessThanOrEqual(
                    0,
                    $arithmetic->compareIntegerValues(
                        $arithmetic->multiplyIntegerValues($widthNumerator, '1000000'),
                        $widthDenominator,
                    ),
                    $equation,
                );
            }
        }
    }

    public function testFiniteIntervalBetaRootLevelsHandleNegativeNonintegerFixedShape(): void
    {
        $equation = 'beta(sin(x), -1/2) = 0';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '2');

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertEqualsWithDelta(
            \M_PI / 6.0,
            (
                ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator)
                + ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
            ) / 2.0,
            1.0E-6,
        );
    }

    public function testNegativeFixedBetaShapesEnumerateTangentAndCotangentLevels(): void
    {
        foreach (
            [
                ['beta(tan(x), -1/2) = 0', '0', '1', \atan(0.5)],
                ['beta(cot(x), -1/2) = 0', '1', '2', \atan2(1.0, 0.5)],
            ] as [$equation, $lowerBound, $upperBound, $expectedRoot]
        ) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lowerBound, $upperBound),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lowerBound,
                $upperBound,
            );

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $midpoint = (
                ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator)
                + ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
            ) / 2.0;
            self::assertEqualsWithDelta($expectedRoot, $midpoint, 1.0E-6, $equation);
        }
    }

    public function testFiniteIntervalBetaRootsForMatchingPeriodicArguments(): void
    {
        foreach (
            [
                ['beta(sin(x), sin(x)) = 0', '3.6', '3.8'],
                ['beta(sin(x), cos(x)) = 0', '2.3', '2.4'],
                ['beta(tan(x), cot(x)) = 0', '1.9', '2'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                maximumWidth: '1/1000',
            );
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertFalse($roots[0]->isExactRoot, $equation);
        }
    }

    public function testAffineBetaWithConstantNonpositiveIntegerSumHasInfiniteRoots(): void
    {
        foreach (
            [
                fn () => Math::countPolynomialRootsOnRealLine('beta(x, -x) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(x + 1/2, -x - 1/2) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(x, -200-x) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(x, -200-x) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(x, -341-x) = 0', 'x'),
                fn () => Math::isolatePolynomialRootsOnRealLine('beta(x, -341-x) = 0', 'x'),
                fn () => Math::countPolynomialRootsOnRealLine('beta(-1/2, 1/2) = x - x', 'x'),
                fn () => Math::countPolynomialRootsInRationalInterval(
                    'beta(x + 1/2, -x - 1/2) = 0',
                    'x',
                    '-1',
                    '1',
                ),
                fn () => Math::countPolynomialRootsInRationalInterval(
                    'beta(x, -200-x) = 0',
                    'x',
                    '-101',
                    '-99',
                ),
                fn () => Math::isolatePolynomialRootsInRationalInterval(
                    'beta(x, -200-x) = 0',
                    'x',
                    '-101',
                    '-99',
                ),
                fn () => Math::countPolynomialRootsInRationalInterval(
                    'beta(x, -341-x) = 0',
                    'x',
                    '-171',
                    '-170',
                ),
                fn () => Math::isolatePolynomialRootsInRationalInterval(
                    'beta(x, -341-x) = 0',
                    'x',
                    '-171',
                    '-170',
                ),
            ] as $solve
        ) {
            $thrown = false;
            try {
                $solve();
            } catch (InfiniteSolutionSetException) {
                $thrown = true;
            }
            self::assertTrue(
                $thrown,
                'A beta equation with a constant nonpositive integer sum has infinitely many roots.',
            );
        }
    }

    public function testBetaConstantSumsAtOrBelowMinimumShapeRangeHaveNoRoots(): void
    {
        foreach (['-342', '-343'] as $sum) {
            $equation = 'beta(x, ' . $sum . '-x) = 0';
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-172', '172'),
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-172', '172'),
            );
        }
    }

    public function testNonlinearRationalBetaWithConstantNonPoleSumHasNoRoots(): void
    {
        foreach (
            [
                'beta(x^2 + 1, 1/2 - x^2) = 0',
                'beta(x^2 + 1, -x^2 - 3/2) = 0',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
            );
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
    }

    public function testTwoVaryingBetaArgumentsWithNoSupportedSumLevelsHaveNoRoots(): void
    {
        foreach (
            [
                'beta(x^2 + 1, x^2 + 1/2) = 0',
                'beta(1/(x^2 + 1), 1/(x^2 + 1)) = 0',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
    }

    public function testTwoVaryingPolynomialBetaArgumentsFilterExactPoleRoots(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('beta(x^2 - 1/2, x + 1/2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(x, x^2) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('beta(x^2 - 2, x) = 0', 'x'));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('beta(x^2 - 2, (x^2 - 2)*(x^2 - 4)) = 0', 'x'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('beta(x^2 - 1/2, x + 1/2) = 0', 'x', '-2', '1'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('beta(x, x^2) = 0', 'x', '-2', '1'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('beta(x^2 - 2, x) = 0', 'x', '-2', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'beta(x^2 - 2, (x^2 - 2)*(x^2 - 4)) = 0',
                'x',
                '-2',
                '2',
            ),
        );
        $boundedIrrationalRoots = Math::isolatePolynomialRootsInRationalInterval(
            'beta(x^2 - 2, x) = 0',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($boundedIrrationalRoots);
        self::assertCount(2, $boundedIrrationalRoots);
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval(
                'beta(x^2 - 2, (x^2 - 2)*(x^2 - 4)) = 0',
                'x',
                '-2',
                '2',
            ),
        );

        $roots = Math::isolatePolynomialRootsOnRealLine('beta(x^2 - 1/2, x + 1/2) = 0', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('0', $roots[1]->lowerNumerator);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('beta(x, x^2) = 0', 'x'));

        $irrationalRoots = Math::isolatePolynomialRootsOnRealLine('beta(x^2 - 2, x) = 0', 'x');
        self::assertNotNull($irrationalRoots);
        self::assertCount(2, $irrationalRoots);
        self::assertFalse($irrationalRoots[0]->isExactRoot);
        self::assertFalse($irrationalRoots[1]->isExactRoot);
        self::assertSame(
            [],
            Math::isolatePolynomialRootsOnRealLine('beta(x^2 - 2, (x^2 - 2)*(x^2 - 4)) = 0', 'x'),
        );
    }

    public function testTwoVaryingRationalBetaArgumentsPreserveHolesAndPoleExclusions(): void
    {
        $equation = 'beta((x^2 - 2)/(x^2 + 1), x/(x^2 + 1)) = 0';
        self::assertSame(5, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
        );
        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
        self::assertNotNull($boundedRoots);
        self::assertCount(2, $boundedRoots);

        $equationWithHole = 'beta(((x^2 - 2)/(x^2 + 1))*((x - 1)/(x - 1)), x/(x^2 + 1)) = 0';
        self::assertSame(4, Math::countPolynomialRootsOnRealLine($equationWithHole, 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(5, $roots);
        self::assertSame('-2', $roots[0]->lowerNumerator);
        self::assertSame('-1', $roots[1]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerDenominator);
        self::assertSame('-1', $roots[2]->lowerNumerator);
        self::assertSame('3', $roots[2]->lowerDenominator);
        self::assertSame('1', $roots[3]->lowerNumerator);
        self::assertSame('2', $roots[3]->lowerDenominator);
        self::assertSame('1', $roots[4]->lowerNumerator);

        $rootsWithHole = Math::isolatePolynomialRootsOnRealLine($equationWithHole, 'x');
        self::assertNotNull($rootsWithHole);
        self::assertCount(4, $rootsWithHole);
        self::assertSame('-2', $rootsWithHole[0]->lowerNumerator);
        self::assertSame('1', $rootsWithHole[3]->lowerNumerator);
        self::assertSame('2', $rootsWithHole[3]->lowerDenominator);
    }

    public function testNonlinearRationalBetaWithConstantNonpositiveIntegerSumHasInfiniteRoots(): void
    {
        foreach (
            [
                ['beta(x^2 + 1, -x^2 - 2) = 0', '-1', '1'],
                ['beta(x^2/(x^2 + 1), -x^2/(x^2 + 1)) = 0', '-1', '1'],
                ['beta(2*x + 200, -2*x - 200) = 0', '-101', '-99'],
            ] as [$equation, $lower, $upper]
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                ] as $solve
            ) {
                $thrown = false;
                try {
                    $solve();
                } catch (InfiniteSolutionSetException) {
                    $thrown = true;
                }
                self::assertTrue(
                    $thrown,
                    'A nonlinear rational beta equation with a nonpositive integer sum has infinitely many roots.',
                );
            }
        }

        self::assertNull(
            Math::countPolynomialRootsInRationalInterval('beta(2*x + 200, -2*x - 200) = 0', 'x', '-1', '1'),
        );
        self::assertNull(
            Math::isolatePolynomialRootsInRationalInterval('beta(2*x + 200, -2*x - 200) = 0', 'x', '-1', '1'),
        );
    }

    public function testAffineBetaWithConstantOtherSumHasNoRoots(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('beta(x + 1/2, -x + 3/2) = 0', 'x'));
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'beta(x + 1/2, -x + 3/2) = 0',
                'x',
                '-1',
                '1',
            ),
        );
        self::assertSame(
            [],
            Math::isolatePolynomialRootsOnRealLine('beta(x + 1/2, -x + 3/2) = 0', 'x'),
        );
    }

    public function testAllRealPeriodicPolynomialZeroSetsReportInfiniteRoots(): void
    {
        foreach (
            [
                'sin(x) = 0',
                'sin(x) = 1/2',
                'cos(x^2) = 0',
                'cos(x^2) = -1',
                'sec(x^2) = 2',
                'csc(x^2) = -1',
                'tan(2*x + 1) = 0',
                'tan(2*x + 1) = 100',
                'cot(x^3 - x) = 0',
                'cot(x^3 - x) = -2/3',
                'sinc(x^2 + 1) = 0',
                'sincpi(3*x - 2) = 0',
            ] as $equation
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
                ] as $solve
            ) {
                $thrown = false;
                try {
                    $solve();
                } catch (InfiniteSolutionSetException) {
                    $thrown = true;
                }
                self::assertTrue($thrown, $equation);
            }
        }
    }

    public function testAllRealRoundingFunctionAffineZeroSetsReportInfiniteRoots(): void
    {
        foreach (
            [
                'floor(2*x + 1) = 0',
                'ceil(x/2) = 0',
                'round(3*x - 1) = 0',
            ] as $equation
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
                ] as $solve
            ) {
                $thrown = false;
                try {
                    $solve();
                } catch (InfiniteSolutionSetException) {
                    $thrown = true;
                }
                self::assertTrue($thrown, $equation);
            }
        }
    }

    public function testAllRealOddDegreePolynomialRoundingZeroSetsAreInfinite(): void
    {
        foreach (
            [
                'floor(x^3) = 0',
                'ceil(-x^3 + 2*x) = 0',
                'round(x^5 - 7*x) = 0',
                'trunc((x - 1/3)^3) = 0',
            ] as $equation
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
                ] as $solve
            ) {
                try {
                    $solve();
                    self::fail('An odd-degree polynomial attains a rounding zero interval: ' . $equation);
                } catch (InfiniteSolutionSetException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testAllRealQuadraticRoundingZeroSetsUseExactRange(): void
    {
        foreach (
            [
                'floor(x^2) = 0',
                'floor(x^2 - 1) = 0',
                'ceil(x^2 - 1) = 0',
                'round(x^2) = 0',
                'trunc(x^2 - 1) = 0',
                'floor(-x^2 + 1/2) = 0',
                'round(-x^2 + 1/4) = 0',
            ] as $equation
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsOnRealLine($equation, 'x'),
                    fn () => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
                ] as $solve
            ) {
                try {
                    $solve();
                    self::fail('A quadratic rounding zero set contains an interval: ' . $equation);
                } catch (InfiniteSolutionSetException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        foreach (['floor(-x^2) = 0', 'ceil(x^2) = 0'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertTrue($roots[0]->isExactRoot);
            self::assertSame('0', $roots[0]->lowerNumerator);
            self::assertSame('0', $roots[0]->upperNumerator);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ceil((x - 1/3)^2) = 0', 'x'));
        $rationalVertex = Math::isolatePolynomialRootsOnRealLine('ceil((x - 1/3)^2) = 0', 'x');
        self::assertNotNull($rationalVertex);
        self::assertCount(1, $rationalVertex);
        self::assertTrue($rationalVertex[0]->isExactRoot);
        self::assertSame('1', $rationalVertex[0]->lowerNumerator);
        self::assertSame('3', $rationalVertex[0]->lowerDenominator);
        self::assertSame('1', $rationalVertex[0]->upperNumerator);
        self::assertSame('3', $rationalVertex[0]->upperDenominator);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('floor(x^2 + 1) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('floor(x^2 + 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ceil(-x^2 - 1) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('ceil(-x^2 - 1) = 0', 'x'));
    }

    public function testFiniteIntervalQuadraticRoundingZeroSetsAreClassifiedExactly(): void
    {
        foreach (
            [
                ['floor(-x^2) = 0', '-1', '1', 1],
                ['ceil(x^2) = 0', '-1', '1', 1],
                ['floor(x^2 + 1) = 0', '-2', '2', 0],
                ['floor(x^2) = 0', '1', '2', 0],
                ['ceil(x^2) = 0', '0', '1', 0],
            ] as [$equation, $lower, $upper, $expected]
        ) {
            self::assertSame(
                $expected,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount($expected, $roots, $equation);
            if ($expected === 1) {
                self::assertTrue($roots[0]->isExactRoot, $equation);
                self::assertSame('0', $roots[0]->lowerNumerator, $equation);
                self::assertSame('0', $roots[0]->upperNumerator, $equation);
            }
        }

        foreach (
            [
                'floor(-x^2 + 1/2) = 0',
                'ceil(x^2 - 1) = 0',
                'round(x^2) = 0',
                'trunc(x^2 - 1) = 0',
            ] as $equation
        ) {
            foreach (
                [
                    fn () => Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                    fn () => Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                ] as $solve
            ) {
                try {
                    $solve();
                    self::fail('The quadratic rounding zero set contains an interval: ' . $equation);
                } catch (InfiniteSolutionSetException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testRoundingSquareZeroEquationsReduceToPolynomialRoots(): void
    {
        foreach (
            [
                'ceil((x^2 - 1)^2) = 0',
                'floor(-((x^2 - 1)^2)) = 0',
                'ceil((x^2 - 1)^4) = 0',
                'floor(-((x^2 - 1)^6)) = 0',
                'ceil((x^2 - 1)^(2 + 2)) = 0',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRealRoots, $equation);
            self::assertCount(2, $allRealRoots, $equation);
            self::assertSame(['-1', '1'], \array_map(
                static fn ($root): string => $root->lowerNumerator,
                $allRealRoots,
            ), $equation);

            self::assertSame(
                2,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(2, $boundedRoots, $equation);
            self::assertSame(['-1', '1'], \array_map(
                static fn ($root): string => $root->lowerNumerator,
                $boundedRoots,
            ), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
                $equation,
            );
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ceil((x^2 + 1)^2) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('ceil((x^2 + 1)^2) = 0', 'x'));
        self::expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('ceil((x - x)^2) = 0', 'x');
    }

    public function testSineAndCosineOutOfRangeLevelsHaveNoExactRoots(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(x) = 2', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('cos(x^2) = -2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sec(x^2) = 1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('csc(x^2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfcx(x^2 + 1) = -1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('erfcx(x^2 + 1) = -1', 'x', '-2', '2'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('erfcx(x^2 + 1) = -1', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval(
            'erfcx(x^2 + 1) = -1',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('sin(x + 1) = -3/2', 'x', '-2', '2'),
        );
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval('cos(x^2) = 3/2', 'x', '-2', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('sinc(x) = 2', 'x', '-2', '2'),
        );
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval('sincpi(x) = -2', 'x', '-2', '2'),
        );
    }

    public function testRegularizedProbabilityFunctionsRejectUnattainableTargets(): void
    {
        foreach (
            [
                'gammainc(2, x^2) = 2',
                'gammainc(2, x^2) = 1',
                'gammaincc(2, x^2) = -1',
                'gammaincc(2, x^2) = 0',
                'betainc(2, 2, x^2) = 2',
                'betaincc(2, 2, x^2) = -1',
                'gammaincinv(2, x^2) = -1',
                'gammainccinv(2, x^2) = -1',
                'betaincinv(2, 2, x^2) = 2',
                'betainccinv(2, 2, x^2) = -1',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
        }
    }

    public function testLogarithmsRejectNegativeTargetsWhenPolynomialArgumentIsAtLeastOne(): void
    {
        foreach (
            [
                'ln(x^2 + 1) = -1',
                'log10(x^4 + x^2 + 1) = -1',
                'log2(x^2 + 1) = -1',
                'log1p(x^2) = -1',
                'log(x^2 + 1, 2) = -1',
                'log(x^2 + 1, 1/2) = 1',
                'ln((x^2 + 1)^2) = -1',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
        }

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln(x^2 + 1) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log10(x^2 + 1) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log1p(x^2) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(x^2 + 1, 1/2) = -1', 'x'));
    }

    public function testDifferentFixedLogarithmBasesAgreeAtUnitArgument(): void
    {
        foreach (
            [
                'ln(x^2 + 1) = log2(x^2 + 1)',
                'log(x^2 + 1, 2) = log10(x^2 + 1)',
                'log(x^2 + 1, 2) = log(x^2 + 1, 1/2)',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertTrue($roots[0]->isExactRoot, $equation);
            self::assertSame(['0', '1', '0', '1'], [
                $roots[0]->lowerNumerator,
                $roots[0]->lowerDenominator,
                $roots[0]->upperNumerator,
                $roots[0]->upperDenominator,
            ], $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'log(x^2 + 2, 2) = log10(x^2 + 2)',
            'x',
        ));

        foreach (
            [
                'log((x + 1)^2, 2) = log(x + 1, 4)',
                'log(x + 1, 2) = log((x + 1)^2, 3)',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertTrue($roots[0]->isExactRoot, $equation);
            self::assertSame(['0', '1'], [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator], $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
        }
    }

    public function testDifferentLogarithmBasesDoNotReduceUnequalArgumentsAsEqual(): void
    {
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'log(x + 1, 2) = log(x + 2, 4)',
            'x',
        ));

        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine(
            'log(x + 1, 2) = log((x + 1)^2, 4)',
            'x',
        );
    }

    public function testPowerRelatedLogarithmsRecognizeInfiniteSolutionSets(): void
    {
        $equation = 'log(x + 1, 2) = log((x + 1)^2, 4)';
        $operations = [
            static fn (): mixed => Math::countPolynomialRootsOnRealLine($equation, 'x'),
            static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($equation, 'x'),
            static fn (): mixed => Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
        ];

        $infiniteResults = 0;
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Expected the logarithm identity to have infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                ++$infiniteResults;
            }
        }
        self::assertSame(\count($operations), $infiniteResults);
    }

    public function testLogarithmPowerRelationsHandleBothPoweredArguments(): void
    {
        $identity = 'log((x + 1)^2, 4) = log((x + 1)^3, 8)';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($identity, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($identity, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval($identity, 'x', '-2', '2'),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($identity, 'x', '-2', '2'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected the power-related logarithms to agree on an interval.');
            } catch (InfiniteSolutionSetException) {
            }
        }

        foreach (
            [
                'log((x + 1)^2, 2) = log((x + 1)^3, 4)' => 1,
                'log((x + 1)^2, 2) = log((x + 1)^4, 8)' => 2,
            ] as $equation => $expected
        ) {
            self::assertSame($expected, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expected,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expected, $roots, $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-3', '3');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount($expected, $boundedRoots, $equation);
        }
    }

    public function testLogarithmPowerRelationsSupportPositiveRationalExponents(): void
    {
        foreach (
            [
                'log((x + 1)^(1/2), 2) = log(x + 1, 4)',
                'log((x + 1)^(2/3), 2) = log((x + 1)^(4/3), 4)',
            ] as $identity
        ) {
            foreach (
                [
                    static fn (): mixed => Math::countPolynomialRootsOnRealLine($identity, 'x'),
                    static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($identity, 'x'),
                    static fn (): mixed => Math::countPolynomialRootsInRationalInterval($identity, 'x', '-3', '3'),
                    static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($identity, 'x', '-3', '3'),
                ] as $operation
            ) {
                try {
                    $operation();
                    self::fail('Expected the rational-power logarithms to agree on an interval.');
                } catch (InfiniteSolutionSetException) {
                }
            }
        }

        foreach (
            [
                'log((x + 1)^(1/2), 2) = log(x + 1, 2)' => ['0'],
                'log((x + 1)^(2/3), 2) = log((x + 1)^(4/3), 8)' => ['-2', '0'],
            ] as $equation => $expectedRoots
        ) {
            $expected = \count($expectedRoots);
            self::assertSame($expected, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expected,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expected, $roots, $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-3', '3');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount($expected, $boundedRoots, $equation);
            foreach ($roots as $index => $root) {
                self::assertTrue($root->isExactRoot, $equation);
                self::assertSame($expectedRoots[$index], $root->lowerNumerator, $equation);
            }
        }
    }

    public function testLogarithmPowerRelationsSupportNegativeRationalExponents(): void
    {
        foreach (
            [
                'log((x + 1)^(-1), 2) = log(x + 1, 1/2)',
                'log((x + 1)^(-2), 4) = log((x + 1)^(-1), 2)',
                'log((x + 1)^(-1/2), 2) = log(x + 1, 1/4)',
            ] as $identity
        ) {
            foreach (
                [
                    static fn (): mixed => Math::countPolynomialRootsOnRealLine($identity, 'x'),
                    static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($identity, 'x'),
                    static fn (): mixed => Math::countPolynomialRootsInRationalInterval($identity, 'x', '-3', '3'),
                    static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($identity, 'x', '-3', '3'),
                ] as $operation
            ) {
                try {
                    $operation();
                    self::fail('Expected the negative-power logarithms to agree on an interval.');
                } catch (InfiniteSolutionSetException) {
                }
            }
        }

        foreach (
            [
                'log((x + 1)^(-1), 2) = log(x + 1, 2)' => ['0'],
                'log((x + 1)^(-1/2), 2) = log(x + 1, 4)' => ['0'],
                'log((x + 1)^(-2), 2) = log((x + 1)^(-4), 8)' => ['-2', '0'],
            ] as $equation => $expectedRoots
        ) {
            $expected = \count($expectedRoots);
            self::assertSame($expected, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expected,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expected, $roots, $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-3', '3');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount($expected, $boundedRoots, $equation);
            foreach ($roots as $index => $root) {
                self::assertTrue($root->isExactRoot, $equation);
                self::assertSame($expectedRoots[$index], $root->lowerNumerator, $equation);
            }
        }
    }

    public function testLogarithmPowerRootsExcludeRemovableArgumentHoles(): void
    {
        $excludedOnly = 'log((x*(x-1)/(x-1))^(1/2),2)=log(x^(1/2),4)';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($excludedOnly, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($excludedOnly, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($excludedOnly, 'x', '0', '2'));
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval($excludedOnly, 'x', '0', '2'),
        );

        $oneValidRoot = 'log((x*(x-1)/(x-1))^(2/3),2)=log(x^(4/3),8)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($oneValidRoot, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($oneValidRoot, 'x', '-2', '0'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($oneValidRoot, 'x', '0', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($oneValidRoot, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame(['-1', '1', '-1', '1'], [
            $roots[0]->lowerNumerator,
            $roots[0]->lowerDenominator,
            $roots[0]->upperNumerator,
            $roots[0]->upperDenominator,
        ]);

        $identityWithHole = 'log((x*(x-1)/(x-1))^(1/2),2)=log(x^(1/2),2)';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($identityWithHole, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($identityWithHole, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval($identityWithHole, 'x', '0', '2'),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($identityWithHole, 'x', '0', '2'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected infinitely many roots outside the removable hole.');
            } catch (InfiniteSolutionSetException) {
            }
        }
    }

    public function testVariableBaseLogarithmsUsePositiveIntegerPowerLevels(): void
    {
        $identity = 'log((x + 1)^2, x + 1) = 2';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($identity, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($identity, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval($identity, 'x', '-2', '2'),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval($identity, 'x', '-2', '2'),
            ] as $operation
        ) {
            try {
                $operation();
            } catch (InfiniteSolutionSetException) {
                continue;
            }
            self::fail('Expected a variable-base logarithm identity on its domain.');
        }

        $rationalBaseIdentity = 'log(((x + 1) / (x - 1))^2, (x + 1) / (x - 1)) = 2';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($rationalBaseIdentity, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($rationalBaseIdentity, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval(
                    $rationalBaseIdentity,
                    'x',
                    '1',
                    '2',
                ),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval(
                    $rationalBaseIdentity,
                    'x',
                    '1',
                    '2',
                ),
            ] as $operation
        ) {
            try {
                $operation();
            } catch (InfiniteSolutionSetException) {
                continue;
            }
            self::fail('Expected a rational-function-base logarithm identity on its domain.');
        }

        $remoteDomainIdentity = 'log((x - 100)^2, x - 100) = 2';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($remoteDomainIdentity, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($remoteDomainIdentity, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval(
                    $remoteDomainIdentity,
                    'x',
                    '100',
                    '102',
                ),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval(
                    $remoteDomainIdentity,
                    'x',
                    '100',
                    '102',
                ),
            ] as $operation
        ) {
            try {
                $operation();
            } catch (InfiniteSolutionSetException) {
                continue;
            }
            self::fail('Expected the logarithm identity to be infinite on its remote domain.');
        }
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($remoteDomainIdentity, 'x', '0', '1'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($remoteDomainIdentity, 'x', '0', '1'));

        $remoteInteriorDomainIdentity = 'log(((x - 100) * (101 - x))^2, (x - 100) * (101 - x)) = 2';
        foreach (
            [
                static fn (): mixed => Math::countPolynomialRootsOnRealLine($remoteInteriorDomainIdentity, 'x'),
                static fn (): mixed => Math::isolatePolynomialRootsOnRealLine($remoteInteriorDomainIdentity, 'x'),
                static fn (): mixed => Math::countPolynomialRootsInRationalInterval(
                    $remoteInteriorDomainIdentity,
                    'x',
                    '100',
                    '101',
                ),
                static fn (): mixed => Math::isolatePolynomialRootsInRationalInterval(
                    $remoteInteriorDomainIdentity,
                    'x',
                    '100',
                    '101',
                ),
            ] as $operation
        ) {
            try {
                $operation();
            } catch (InfiniteSolutionSetException) {
                continue;
            }
            self::fail('Expected an identity on a remote bounded logarithm domain.');
        }
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval($remoteInteriorDomainIdentity, 'x', '0', '1'),
        );

        $rationalBaseLevel = 'log(x + 1, (x + 1) / (x - 1)) = 2';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($rationalBaseLevel, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rationalBaseLevel, 'x', '2', '4'));
        $rationalBaseRoots = Math::isolatePolynomialRootsOnRealLine($rationalBaseLevel, 'x');
        self::assertNotNull($rationalBaseRoots);
        self::assertCount(1, $rationalBaseRoots);
        self::assertSame(['3', '1', '3', '1'], [
            $rationalBaseRoots[0]->lowerNumerator,
            $rationalBaseRoots[0]->lowerDenominator,
            $rationalBaseRoots[0]->upperNumerator,
            $rationalBaseRoots[0]->upperDenominator,
        ]);
        $boundedRationalBaseRoots = Math::isolatePolynomialRootsInRationalInterval(
            $rationalBaseLevel,
            'x',
            '2',
            '4',
        );
        self::assertNotNull($boundedRationalBaseRoots);
        self::assertCount(1, $boundedRationalBaseRoots);

        $fractionalBaseLevel = 'log(x + 1, (x + 1) / (x - 1)) = 1/2';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($fractionalBaseLevel, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($fractionalBaseLevel, 'x', '-2', '0'));
        $fractionalBaseRoots = Math::isolatePolynomialRootsOnRealLine($fractionalBaseLevel, 'x');
        self::assertNotNull($fractionalBaseRoots);
        self::assertCount(1, $fractionalBaseRoots);
        self::assertFalse($fractionalBaseRoots[0]->isExactRoot);
        $boundedFractionalBaseRoots = Math::isolatePolynomialRootsInRationalInterval(
            $fractionalBaseLevel,
            'x',
            '1',
            '2',
        );
        self::assertNotNull($boundedFractionalBaseRoots);
        self::assertCount(1, $boundedFractionalBaseRoots);

        $rationalArgumentLevel = 'log(((x + 1) / (x - 1))^2 * (x - 3)^2, (x + 1) / (x - 1)) = 2';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($rationalArgumentLevel, 'x'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($rationalArgumentLevel, 'x', '1', '5'));
        $rationalArgumentRoots = Math::isolatePolynomialRootsOnRealLine($rationalArgumentLevel, 'x');
        self::assertNotNull($rationalArgumentRoots);
        self::assertCount(2, $rationalArgumentRoots);
        self::assertSame(['2', '4'], [
            $rationalArgumentRoots[0]->lowerNumerator,
            $rationalArgumentRoots[1]->lowerNumerator,
        ]);
        $boundedRationalArgumentRoots = Math::isolatePolynomialRootsInRationalInterval(
            $rationalArgumentLevel,
            'x',
            '1',
            '5',
        );
        self::assertNotNull($boundedRationalArgumentRoots);
        self::assertCount(2, $boundedRationalArgumentRoots);

        $negativeBaseLevel = 'log(x + 1, (x - 1) / (x + 1)) = -1';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($negativeBaseLevel, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($negativeBaseLevel, 'x', '1', '3'));
        $negativeBaseRoots = Math::isolatePolynomialRootsOnRealLine($negativeBaseLevel, 'x');
        self::assertNotNull($negativeBaseRoots);
        self::assertCount(1, $negativeBaseRoots);
        self::assertSame('2', $negativeBaseRoots[0]->lowerNumerator);

        $negativeFractionalLevel = 'log(x + 1, (x - 1) / (x + 1)) = -1/2';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($negativeFractionalLevel, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($negativeFractionalLevel, 'x', '-2', '0'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($negativeFractionalLevel, 'x', '0', '2'));

        $negativeArgumentCandidate = 'log(x, x + 1) = 1/2';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($negativeArgumentCandidate, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($negativeArgumentCandidate, 'x', '-1', '0'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($negativeArgumentCandidate, 'x', '1', '2'));

        $poleCandidate = 'log(x * (x - 1), 1 / (x - 1)) = -1';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($poleCandidate, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($poleCandidate, 'x'));

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x / x, x / x) = 1', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('log(x / x, x / x) = 1', 'x'));

        $irrationalBaseOneBoundary = 'log((x^2 - 2)^2 + (x^2 - 2) - 1, x^2 - 2) = 2';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($irrationalBaseOneBoundary, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($irrationalBaseOneBoundary, 'x'));

        $irrationalZeroArgumentBoundary = 'log(x^2 - 3, (x^2 - 3)^2 + (x^2 - 3)) = 1/2';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($irrationalZeroArgumentBoundary, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($irrationalZeroArgumentBoundary, 'x'));

        $invalidOnly = 'log(x^2 + 1, x + 1) = 2';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($invalidOnly, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($invalidOnly, 'x'));

        $validRoot = 'log(x^2 + 1, x + 2) = 2';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($validRoot, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($validRoot, 'x', '-1', '0'));
        $roots = Math::isolatePolynomialRootsOnRealLine($validRoot, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame(['-3', '4', '-3', '4'], [
            $roots[0]->lowerNumerator,
            $roots[0]->lowerDenominator,
            $roots[0]->upperNumerator,
            $roots[0]->upperDenominator,
        ]);
        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($validRoot, 'x', '-1', '0');
        self::assertNotNull($boundedRoots);
        self::assertCount(1, $boundedRoots);

        $oneLevel = 'log(x^2 + 1, x + 1) = 1';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($oneLevel, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($oneLevel, 'x', '-1/2', '1/2'));
    }

    public function testLogarithmZeroLevelsReduceToUnitArgumentsWithDomainChecks(): void
    {
        foreach (
            [
                'log(x^2 + 1, x + 2) = 0' => 1,
                'log(x^2, x + 2) = 0' => 1,
                'log(x^2 + 1, x + 1) = 0' => 0,
                'ln(x^2 + 1) = 0' => 1,
                'log1p(x^2) = 0' => 1,
            ] as $equation => $expected
        ) {
            self::assertSame($expected, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expected,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expected, $roots, $equation);
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount($expected, $boundedRoots, $equation);
        }
    }

    public function testEqualStrictlyMonotoneFunctionCallsReduceToArgumentEquality(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cbrt(x^2 - 1) = cbrt(0)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainc((-x^2 - 1)/(-x^2 - 2), x^2)'
            . ' = gammainc((-x^2 - 1)/(-x^2 - 2), 1)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asinh(x^2 - 4) = asinh(0)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2) = atan(4)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'atan((x^2-1)/(x-1))=arctan(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'asinh((x^2-1)/(x-1))=arcsinh(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acosh((x^2-1)/(x-1))=arccosh(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acosh((x^2-1)/(x-1))=acosh(1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'acosh((x^2-1)/(x-1))=acosh(0)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'acosh((x^2-1)/(x-1))=acosh(1/2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'asin((x^2-1)/(x-1))=arcsin(1/2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'asin((x^2-1)/(x-1))=asin(1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'asin((x^2-1)/(x-1))=asin(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acos((x^2-1)/(x-1))=arccos(-1/2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acos((x^2-1)/(x-1))=acos(-1)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'atanh((x^2-1)/(x-1))=arctanh(1/2)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'atanh((x^2-1)/(x-1))=atanh(1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'atanh((x^2-1)/(x-1))=atanh(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acoth((x^2-1)/(x-1))=acoth(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acoth((x^2-1)/(x-1))=acoth(-2)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'acoth((x^2-1)/(x-1))=acoth(1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'acoth((x^2-1)/(x-1))=acoth(1/2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'asech((x^2-1)/(x-1))=asech(1/2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'asech((x^2-1)/(x-1))=asech(1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'asech((x^2-1)/(x-1))=asech(0)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'asech((x^2-1)/(x-1))=asech(3/2)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine(
                'asech((x^2+1)/(2*x^2+2))=asech(1/2)',
                'x',
            );
            self::fail('Expected infinitely many solutions on the asech domain.');
        } catch (InfiniteSolutionSetException) {
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'acsch((x^2-1)/(x-1))=acsch(1/2)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'acsch(x+1)=acsch(0)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'csch((x^2-1)/(x-1))=csch(1/2)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'csch(x+1)=csch(0)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'coth(x+1)=coth(2)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'coth(x+1)=coth(0)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine(
                'acsch((x^2+1)/(x^2+1))=acsch(1)',
                'x',
            );
            self::fail('Expected infinitely many solutions on the acsch domain.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::countPolynomialRootsOnRealLine(
                'coth(x^2+1)=coth(x^2+1)',
                'x',
            );
            self::fail('Expected infinitely many solutions on the coth domain.');
        } catch (InfiniteSolutionSetException) {
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'acosh(-x^2-1)=acosh(-x^2-1)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine(
                'acosh((x^2+1)/(x^2+1))=acosh(1)',
                'x',
            );
            self::fail('Expected infinitely many solutions on the acosh domain.');
        } catch (InfiniteSolutionSetException) {
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'ln((x^2-1)/(x-1))=ln(3)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'ln((x^2-1)/(x-1))=ln(0)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'log1p((x^2-1)/(x-1))=log1p(3)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'log1p((x^2-1)/(x-1))=log1p(-1)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'log2((x^2-1)/(x-1))=log2(3)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'log10((x^2-1)/(x-1))=log(3,10)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'ln((x^2-1)/(x-1))=log(3,e)',
            'x',
        ));
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'ln(-x^2-1)=ln(-x^2-1)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine(
                'ln((x^2+1)/(x^2+1))=ln(1)',
                'x',
            );
            self::fail('Expected infinitely many solutions on the logarithm domain.');
        } catch (InfiniteSolutionSetException) {
        }
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine(
            'atan((x^2-1)/(x-1))=arctan(2)',
            'x',
        ));
        foreach (['exp', 'expm1', 'sinh', 'tanh', 'erf', 'erfc'] as $function) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine(
                $function . '((x^2-1)/(x-1))=' . $function . '(3)',
                'x',
            ), $function);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tanh(x) = tanh(1/2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(x) = exp(1/2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('expm1(x) = expm1(2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erf(x) = erf(1/2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfc(x) = erfc(1/2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcx(x^2 + 1) = erfcx(2*x)', 'x'));
        $erfcxEquation = 'erfcx(x^2 + 1) = erfcx(2*x)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($erfcxEquation, 'x', '0', '2'));
        $erfcxRoots = Math::isolatePolynomialRootsOnRealLine('erfcx(x^2 + 1) = erfcx(2*x)', 'x');
        self::assertNotNull($erfcxRoots);
        self::assertCount(1, $erfcxRoots);
        self::assertSame('1', $erfcxRoots[0]->lowerNumerator);
        self::assertTrue($erfcxRoots[0]->isExactRoot);
        $boundedErfcxRoots = Math::isolatePolynomialRootsInRationalInterval($erfcxEquation, 'x', '0', '2');
        self::assertNotNull($boundedErfcxRoots);
        self::assertCount(1, $boundedErfcxRoots);
        self::assertSame('1', $boundedErfcxRoots[0]->lowerNumerator);
        self::assertTrue($boundedErfcxRoots[0]->isExactRoot);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcx(x)=erfcx(-133/5)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcx(x)=erfcx(-53/2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcx(x)=erfcx(-267/10)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcx(x)=erfcx(-27)', 'x'));
        $overflowBoundaryRoots = Math::isolatePolynomialRootsOnRealLine(
            'erfcx(x)=erfcx(-267/10)',
            'x',
        );
        self::assertNotNull($overflowBoundaryRoots);
        self::assertCount(1, $overflowBoundaryRoots);
        self::assertSame('-267', $overflowBoundaryRoots[0]->lowerNumerator);
        self::assertSame('10', $overflowBoundaryRoots[0]->lowerDenominator);
        self::assertTrue($overflowBoundaryRoots[0]->isExactRoot);
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'erfcx(x)=erfcx(-267/10)',
            'x',
            '-27',
            '-26',
        ));
        $boundedOverflowRoots = Math::isolatePolynomialRootsInRationalInterval(
            'erfcx(x)=erfcx(-267/10)',
            'x',
            '-27',
            '-26',
        );
        self::assertNotNull($boundedOverflowRoots);
        self::assertCount(1, $boundedOverflowRoots);
        self::assertSame('-267', $boundedOverflowRoots[0]->lowerNumerator);
        self::assertSame('10', $boundedOverflowRoots[0]->lowerDenominator);
        self::assertTrue($boundedOverflowRoots[0]->isExactRoot);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'erfcx((x^2-1)/(x-1))=erfcx(2)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'erfcx(x^2 - 20000) = erfcx(x^2 + x - 19900)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainc(2, x^2 - 1) = gammainc(2, 3)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainc(x^2 + 1, x^2) = gammainc(x^2 + 1, 1)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainc(1/(x^2 + 1), x^2) = gammainc(1/(x^2 + 1), 1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincc(2, (x^2 - 1)/(x - 1)) = gammaincc(2, 2)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammaincc(2, x^2) = gammaincc(2, 1/4)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betainc(2, 3, x^2) = betainc(2, 3, 1/4)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betainc(x^2 + 1, x^2 + 1, x^2) = betainc(x^2 + 1, x^2 + 1, 1/4)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betainc(1/(x^2 + 1), 1/(x^2 + 1), x^2)'
            . ' = betainc(1/(x^2 + 1), 1/(x^2 + 1), 1/4)',
            'x',
        ));
        $regularizedBetaRoots = Math::isolatePolynomialRootsOnRealLine(
            'betainc(2, 3, x^2) = betainc(2, 3, 1/4)',
            'x',
        );
        self::assertNotNull($regularizedBetaRoots);
        self::assertCount(2, $regularizedBetaRoots);
        self::assertTrue($regularizedBetaRoots[0]->isExactRoot);
        self::assertTrue($regularizedBetaRoots[1]->isExactRoot);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'betaincc(2, 3, x) = betaincc(2, 3, 2)',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betaincc(2, 3, x^2) = betaincc(2, 3, 1/4)',
            'x',
        ));
        try {
            Math::countPolynomialRootsOnRealLine('gammainc(2, x) = gammainc(2, x)', 'x');
            self::fail('Expected equal gamma calls to define infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
        }
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('acot(x^2 - 1) = acot(0)', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('atan(x^2 - 1) + acot(2*x - 1) = pi/2', 'x'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('pi/2 = acot(2*x - 1) + arctan(x^2 - 1)', 'x'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'atan((x^2 - 1)/(x - 1)) + acot(x) = pi/2',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfi(x) = erfi(-1)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arcsinh(x) = arcsinh(3)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arctan(x) = arctan(2)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2) = arctan(4)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asinh(x) = arcsinh(3)', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('erf(x^2 - 1) + erfc(2*x - 1) = 1', 'x'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('1 = erfc(2*x - 1) + erf(x^2 - 1)', 'x'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'erf((x^2 - 1)/(x - 1)) + erfc(2*x) = 1',
                'x',
            ),
        );
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('erf(x^2 - 1) + erf(2*x - 1) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tanh(x) + tanh(x - 1) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2) + arctan(2*x) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('erf(x^2 - 1) = -erf(2*x - 1)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2) = -arctan(2*x)', 'x'));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'erf((x^2 - 1)/(x - 1)) + erf(-2) = 0',
                'x',
            ),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine(
                'abs(erf(x^2 - 1)) = abs(erf(4))',
                'x',
            ),
        );
        self::assertSame(
            4,
            Math::countPolynomialRootsOnRealLine(
                'abs(atan(x^2 - 1)) = abs(arctan(2*x))',
                'x',
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'abs(erf((x^2 - 1)/(x - 1))) = abs(erf(0))',
                'x',
            ),
        );
        self::assertSame(4, Math::countPolynomialRootsOnRealLine('sech(x^2 - 1) = sech(2*x)', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'sech((x^2 - 1)/(x - 1)) = sech(0)',
                'x',
            ),
        );
        $holeRoots = Math::isolatePolynomialRootsOnRealLine(
            'sinh((x^2 - 1)/(x - 1)) = sinh(0)',
            'x',
        );
        self::assertNotNull($holeRoots);
        self::assertCount(1, $holeRoots);
        self::assertSame('-1', $holeRoots[0]->lowerNumerator);
    }

    public function testInverseErrorFunctionsCancelForwardFunctionsInExactEquations(): void
    {
        foreach (['erfinv(erf(x)) = x', 'erfcinv(erfc(x)) = x'] as $equation) {
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('Expected the inverse-error-function identity to have infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
            try {
                Math::isolatePolynomialRootsOnRealLine($equation, 'x');
                self::fail(
                    'Expected restricted inverse identity isolation to reject infinite roots: ' . $equation,
                );
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
            try {
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
                self::fail('Expected the inverse-error-function identity to have infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(2, Math::countPolynomialRootsOnRealLine('erfinv(erf(x^2)) = 4', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('4 = erfcinv(erfc(x^2))', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'erfinv(erf((x^2 - 1)/(x - 1))) = 0',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfinv(erf(x)) = 100', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfcinv(erfc(x)) = 100', 'x'));
    }

    public function testLog1pCancelsExpm1ForNonconstantExactTargets(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log1p(expm1(x)) = 7', 'x'));

        $equations = [
            'log1p(expm1(x)) = x',
            'log1p(expm1(x^2 + 1)) = x^2 + 1',
        ];
        foreach ($equations as $equation) {
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('Expected the log1p/expm1 identity to have infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
            try {
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
                self::fail('Expected inverse identity isolation to reject an infinite root set: ' . $equation);
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
        self::assertNull(
            Math::countPolynomialRootsInRationalInterval('cos(acos(x)) = x', 'x', '2', '3'),
        );
    }

    public function testSinhCancelsAsinhForAllRealArguments(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh(asinh(x)) = 9', 'x'));

        try {
            Math::countPolynomialRootsOnRealLine('sinh(asinh(x^2 - 3)) = x^2 - 3', 'x');
            self::fail('Expected the sinh/asinh identity to have infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testTangentCancelsArctangentForAllRealArguments(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tan(atan(x)) = -5', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('tan(arctan(x^2)) = 4', 'x'));

        try {
            Math::countPolynomialRootsOnRealLine('tan(arctan(x)) = x', 'x');
            self::fail('Expected the tangent/arctangent identity to have infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testRestrictedInverseIdentitiesRequireCertifiedOpenDomainIntervals(): void
    {
        $equations = [
            'sin(asin(x)) = x',
            'cos(acos(x)) = x',
            'tanh(atanh(x)) = x',
            'cosh(acosh(x)) = x',
            'coth(acoth(x)) = x',
            'csch(acsch(x)) = x',
            'sec(asec(x)) = x',
            'csc(acsc(x)) = x',
            'sech(asech(x)) = x',
            'erf(erfinv(x)) = x',
            'erfc(erfcinv(x)) = x',
            'exp(ln(x)) = x',
            'expm1(log1p(x)) = x',
            'asin(sin(x)) = x',
            'arcsin(sin(x)) = x',
            'acos(cos(x)) = x',
            'atan(tan(x)) = x',
            'arctan(tan(x)) = x',
            'acot(cot(x)) = x',
            'asec(sec(x)) = x',
            'acsc(csc(x)) = x',
            'acosh(cosh(x)) = x',
            'acsch(csch(x)) = x',
            'acoth(coth(x)) = x',
            'asech(sech(x)) = x',
        ];
        foreach ($equations as $equation) {
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('Expected a restricted inverse identity to have infinitely many roots: ' . $equation);
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
            try {
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
                self::fail(
                    'Expected a restricted inverse identity to have infinitely many interval roots: ' . $equation,
                );
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
            try {
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
                self::fail(
                    'Expected restricted inverse identity isolation to reject infinite roots: ' . $equation,
                );
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
        self::assertNull(
            Math::countPolynomialRootsInRationalInterval('exp(ln(x)) = x', 'x', '-3', '0'),
        );
    }

    public function testCotangentCancelsInverseCotangentOnAllReals(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cot(acot(x)) = 8', 'x'));
        try {
            Math::isolatePolynomialRootsOnRealLine('cot(acot(x)) = x', 'x');
            self::fail('Expected the cotangent/inverse-cotangent identity to have infinitely many roots.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testExponentialAndLogarithmTangentEqualitiesReduceToExactZeros(): void
    {
        $equations = [
            'exp(x) = 1 + x',
            'exp(x) = 1 - x',
            'exp(-x) = 1 + x',
            'ln(1 + x) = x',
            'ln(1 - x) = -x',
            'ln(1 + x) = -x',
            'exp(x^2) = x^2 + 1',
            'ln(1 + x^2) = x^2',
        ];
        foreach ($equations as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertTrue($roots[0]->isExactRoot, $equation);
            self::assertSame('0', $roots[0]->lowerNumerator, $equation);
        }

        $rationalHoleEquation = 'exp((x^2 - 1)/(x - 1)) = 1 + (x^2 - 1)/(x - 1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($rationalHoleEquation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($rationalHoleEquation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testExponentialTaylorEqualitiesReduceToTheirExactArgumentZeros(): void
    {
        $equations = [
            'exp(x) = 1 + x + x^2/2' => 1,
            'ln(1 + x + x^2/2) = x' => 1,
            'exp(x) = 1 + x + x^2/2 + x^3/6' => 1,
            'exp(x) = 1 + x + x^2/2 + x^3/6 + x^4/24' => 1,
            'exp(x^2 - 1) = 1 + (x^2 - 1) + (x^2 - 1)^2/2' => 2,
        ];
        foreach ($equations as $equation => $expectedCount) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
        }
    }

    public function testHyperbolicTaylorEqualitiesReduceToTheirExactArgumentZeros(): void
    {
        $equations = [
            'sinh(x) = x + x^3/6' => 1,
            'sinh(x^2 - 1) = (x^2 - 1) + (x^2 - 1)^3/6' => 2,
            'cosh(x) = 1 + x^2/2' => 1,
            'cosh(x^2 - 1) = 1 + (x^2 - 1)^2/2' => 2,
            'cosh(x) = 1 + x^2/2 + x^4/24 + x^6/720' => 1,
        ];
        foreach ($equations as $equation => $expectedCount) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
        }
    }

    public function testOneSidedTrigonometricTaylorEqualitiesReduceToZeros(): void
    {
        $equations = [
            'cos(x) = 1 - x^2/2' => 1,
            'sin(x) = x - x^3/6' => 1,
            'cos(x^2 - 1) = 1 - (x^2 - 1)^2/2' => 2,
            'sin(x^2 - 1) = (x^2 - 1) - (x^2 - 1)^3/6' => 2,
        ];
        foreach ($equations as $equation => $expectedCount) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
        }
    }

    public function testZeroOnlyFixedPointsReduceForRationalPolynomialPhases(): void
    {
        $equations = [
            'sin(x^2) = x^2' => 1,
            'sinh(x^2 - 1) = x^2 - 1' => 2,
            'tanh((x - 1)^2) = (x - 1)^2' => 1,
            'atan(x^2 - 1) = x^2 - 1' => 2,
            'asinh(x^3) = x^3' => 1,
            'atanh(x^2 - 1) = x^2 - 1' => 2,
        ];
        foreach ($equations as $equation => $expectedCount) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
        }

        $equation = 'sinh((x^2 - 1)/(x - 1)) = (x^2 - 1)/(x - 1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
    }

    public function testImpossibleElementaryFixedPointsAreRecognizedForRationalPhases(): void
    {
        $equations = [
            'exp(x^2 + 1) = x^2 + 1',
            'ln(x^2 + 1) = x^2 + 1',
            'cosh(x^3) = x^3',
            'ln((x^2 + 1)/(x^2 + 1)) = (x^2 + 1)/(x^2 + 1)',
        ];
        foreach ($equations as $equation) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
        }
    }

    public function testForwardErrorFunctionsCancelInverseFunctionsWithinTheirRanges(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('erf(erfinv(x^2 - 1)) = 1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('1 = erfc(erfcinv(x^2 - 1))', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erf(erfinv(x)) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfc(erfcinv(x)) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfc(erfcinv(x)) = 2', 'x'));
    }

    public function testRegularizedGammaAndBetaFunctionsCancelMatchingInverses(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainc(2, gammaincinv(2, x^2 - 1)) = 1/2',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betainc(2, 3, betaincinv(2, 3, x^2 - 1)) = 1/3',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            '1/4 = gammaincc(3/2, gammainccinv(3/2, (x - 1)^2))',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betaincc(2, 3, betainccinv(2, 3, x^2 - 1)) = 2/3',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammainc(2, gammaincinv(2, x)) = 0',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'betainc(2, 3, betaincinv(2, 3, x)) = 1',
            'x',
        ));
    }

    public function testInverseRegularizedFunctionsCancelMatchingForwardFunctions(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2, gammainc(2, x^2 - 1)) = 1/2',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'gammainccinv(2, gammaincc(2, x^2 - 1)) = 1/3',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betaincinv(2, 3, betainc(2, 3, (x - 1)^2 / 4)) = 1/2',
            'x',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine(
            'betainccinv(2, 3, betaincc(2, 3, x^2 / 4)) = 1/2',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2, gammainc(2, x)) = 0',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'betaincinv(2, 3, betainc(2, 3, x)) = 1',
            'x',
        ));
    }

    public function testInverseLogarithmsCancelMatchingExponentialFunctionsExactly(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(exp(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(e^(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('4 = log2(2^(x^2))', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log10(10^((x - 1)^2)) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(3^(x^2), 3) = 4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln(exp((x - 1)^2)) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('exp(ln(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('e^(ln(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('2^(log2(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('3^(log(x^2 - 1, 3)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(1/3)^(log(x^2 - 1, 1/3)) = 1/3', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(ln(x)) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('e^(ln(x)) = -1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('10^(log10(x)) = -1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('3^(log(x, 3)) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log1p(expm1(x^2 - 1)) = 3', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('expm1(log1p((x - 1)^2)) = 3', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('expm1(log1p(x)) = -1', 'x'));
    }

    public function testInverseHyperbolicCompositionsReduceWithTheirRealDomains(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asinh(sinh(x^2 - 1)) = 3', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arctanh(tanh((x - 1)^2)) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('acoth(coth(x^2 - 1)) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('acsch(csch(x^2 - 1)) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acoth(coth(x)) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsch(csch(x)) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('acosh(cosh(x - 1)) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asech(sech(x)) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arccosh(cosh(x)) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asech(sech(x)) = -1', 'x'));
    }

    public function testAffineMonotoneEquationsHaveCertifiedExactRootCounts(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^x = 3', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(2*x - 1) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(1/2)^(3*x + 2) = 8', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln(x) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log(x, 1/2) = 3', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log1p(2*x - 1) = 4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfinv(x) = 5', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acoth(2*x) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('expm1(x) = -1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('tanh(x) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acoth(x) = 0', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('2^(x^2) = 3', 'x'));
    }

    public function testAllRealIsolationSupportsAffineMonotoneTranscendentalRoots(): void
    {
        foreach (
            [
                'exp(x) = 2' => \log(2.0),
                'ln(x) = 2' => \exp(2.0),
                'asinh(2*x - 1) = 1/2' => (\sinh(0.5) + 1.0) / 2.0,
                'acoth(2*x) = 2' => (1.0 / \tanh(2.0)) / 2.0,
                'erfinv(x) = 1/2' => \MathPHP\Function\SpecialFunctions::erf(0.5),
                'erfcinv(2*x - 1) = 1/2' =>
                    (\MathPHP\Function\SpecialFunctions::erfc(0.5) + 1.0) / 2.0,
                'erfinv(x) = 8' => 1.0,
                'erfcinv(x) = 8' => \MathPHP\Function\SpecialFunctions::erfc(8.0),
                '2^x = 3' => \log(3.0) / \log(2.0),
                '(1/2)^(3*x + 2) = 8' => -5.0 / 3.0,
                'log(2*x + 1, 3) = 1/2' => (\sqrt(3.0) - 1.0) / 2.0,
                'log2(x) = 3' => 8.0,
                'log10(2*x + 1) = 1/2' => (\sqrt(10.0) - 1.0) / 2.0,
            ] as $equation => $expectedRoot
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $lower = (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator;
            $upper = (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator;
            self::assertLessThan($expectedRoot + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expectedRoot - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }

        $endpointRoots = Math::isolatePolynomialRootsOnRealLine('erfinv(x) = 64', 'x');
        self::assertNotNull($endpointRoots);
        self::assertCount(1, $endpointRoots);
        $endpointRoot = $endpointRoots[0];
        $lower = new \MathPHP\Solver\ExactRationalInterval(
            [$endpointRoot->lowerNumerator, $endpointRoot->lowerDenominator],
            [$endpointRoot->lowerNumerator, $endpointRoot->lowerDenominator],
        );
        $upper = new \MathPHP\Solver\ExactRationalInterval(
            [$endpointRoot->upperNumerator, $endpointRoot->upperDenominator],
            [$endpointRoot->upperNumerator, $endpointRoot->upperDenominator],
        );
        self::assertLessThan(0, $lower->compareLowerToRational('1', '1'));
        self::assertLessThan(0, $upper->compareUpperToRational('1', '1'));
        self::assertLessThanOrEqual(
            0,
            $upper->subtract($lower)->compareUpperToRational('1', '1000000'),
        );

        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('exp(x) = 0', 'x'));
        self::assertNull(Math::isolatePolynomialRootsOnRealLine('erfinv(x) = 65', 'x'));
    }

    public function testAllRealIsolationSupportsAffineErrorFunctionRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erf(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('erfc(3*x + 1) = 1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erf(x) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfc(x) = 0', 'x'));

        foreach (
            [
                'erf(2*x - 1) = 1/2' => (\MathPHP\Function\SpecialFunctions::inverseErf(0.5) + 1.0) / 2.0,
                'erfc(-3*x + 1) = 1/2' => (1.0 - \MathPHP\Function\SpecialFunctions::inverseErfc(0.5)) / 3.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }

        $zeroRoot = Math::isolatePolynomialRootsOnRealLine('erf(3*x) = 0', 'x');
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertSame('0', $zeroRoot[0]->lowerNumerator);
        self::assertSame('0', $zeroRoot[0]->upperNumerator);
    }

    public function testAllRealIsolationSupportsAffineArctangentRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arctan(-3*x + 1) = -1/4', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(x) = 2', 'x'));

        foreach (
            [
                'atan(2*x - 1) = 1/2' => (tan(0.5) + 1.0) / 2.0,
                'arctan(-3*x + 1) = -1/4' => (1.0 - tan(-0.25)) / 3.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }

        $zeroRoot = Math::isolatePolynomialRootsOnRealLine('atan(2*x + 1) = 0', 'x');
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertTrue($zeroRoot[0]->isExactRoot);
        self::assertSame('-1', $zeroRoot[0]->lowerNumerator);
        self::assertSame('2', $zeroRoot[0]->lowerDenominator);
    }

    public function testAllRealIsolationSupportsAffineInverseSineRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arcsin(-3*x + 1) = -1/4', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(x) = 2', 'x'));

        foreach (
            [
                'asin(2*x - 1) = 1/2' => (sin(0.5) + 1.0) / 2.0,
                'arcsin(-3*x + 1) = -1/4' => (1.0 - sin(-0.25)) / 3.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }

        $zeroRoot = Math::isolatePolynomialRootsOnRealLine('asin(3*x) = 0', 'x');
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertTrue($zeroRoot[0]->isExactRoot);
        self::assertSame('0', $zeroRoot[0]->lowerNumerator);
        self::assertSame('0', $zeroRoot[0]->upperNumerator);
    }

    public function testAllRealIsolationSupportsAffineInverseCosineRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acos(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arccos(-3*x + 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acos(x) = -1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acos(x) = 4', 'x'));

        $equation = 'acos(2*x - 1) = 1/2';
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots, $equation);
        self::assertCount(1, $roots, $equation);
        $root = $roots[0];
        $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
        $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
        $expected = (cos(0.5) + 1.0) / 2.0;
        self::assertLessThan($expected + 1.0E-6, $lower);
        self::assertGreaterThan($expected - 1.0E-6, $upper);
        self::assertLessThanOrEqual(1.0E-6, $upper - $lower);

        $zeroRoot = Math::isolatePolynomialRootsOnRealLine('acos(2*x - 1) = 0', 'x');
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertTrue($zeroRoot[0]->isExactRoot);
        self::assertSame('1', $zeroRoot[0]->lowerNumerator);
        self::assertSame('1', $zeroRoot[0]->lowerDenominator);
    }

    public function testAllRealIsolationSupportsAffineInverseCotangentRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acot(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acot(-3*x + 1) = 1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acot(x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acot(x) = 4', 'x'));

        foreach (
            [
                'acot(2*x - 1) = 1/2' => (1.0 / tan(0.5) + 1.0) / 2.0,
                'acot(-3*x + 1) = 1/2' => (1.0 - (1.0 / tan(0.5))) / 3.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testAllRealIsolationSupportsAffineReciprocalInverseTrigRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asec(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asec(2*x - 1) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asec(2*x - 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asec(x) = 4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acsc(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acsc(2*x - 1) = -1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsc(x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsc(x) = 2', 'x'));

        foreach (
            [
                'asec(2*x - 1) = 1/2' => (1.0 / cos(0.5) + 1.0) / 2.0,
                'asec(2*x - 1) = 2' => (1.0 / cos(2.0) + 1.0) / 2.0,
                'acsc(2*x - 1) = 1/2' => (1.0 / sin(0.5) + 1.0) / 2.0,
                'acsc(2*x - 1) = -1/2' => (-1.0 / sin(0.5) + 1.0) / 2.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testAllRealIsolationSupportsFixedOrdinateAffineAtan2Roots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(1, 2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(-2, -3*x + 1) = -1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan2(1, x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan2(-1, x) = 1/2', 'x'));

        foreach (
            [
                'atan2(1, 2*x - 1) = 1/2' => (1.0 / tan(0.5) + 1.0) / 2.0,
                'atan2(-2, -3*x + 1) = -1/2' => (1.0 - (-2.0 / tan(-0.5))) / 3.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testAllRealIsolationSupportsFixedAbscissaAffineAtan2Roots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, 3) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, -2) = 5/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, -2) = -5/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, 3) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, -2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan2(2*x - 1, 3) = 2', 'x'));

        foreach (
            [
                'atan2(2*x - 1, 3) = 1/2' => (3.0 * tan(0.5) + 1.0) / 2.0,
                'atan2(2*x - 1, -2) = 5/2' => (-2.0 * tan(2.5) + 1.0) / 2.0,
                'atan2(2*x - 1, -2) = -5/2' => (-2.0 * tan(-2.5) + 1.0) / 2.0,
                'atan2(2*x - 1, 3) = 0' => 0.5,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testAffineTranscendentalLevelsAreIsolatedWithoutClaimingExactRoots(): void
    {
        $highPrecisionRoot = Math::isolatePolynomialRootsOnRealLine(
            'x=pi',
            'x',
            maximumWidth: '1/' . '1' . str_repeat('0', 150),
        );
        self::assertNotNull($highPrecisionRoot);

        foreach (
            [
                'x=pi' => \M_PI,
                '2*x+1=pi' => (\M_PI - 1.0) / 2.0,
                'x=pi+1' => \M_PI + 1.0,
                'x=e' => \M_E,
                '2*x+1=exp(1)' => (\M_E - 1.0) / 2.0,
                'x=e+1' => \M_E + 1.0,
                'x=phi' => (1.0 + \sqrt(5.0)) / 2.0,
                'x=phi+1' => (3.0 + \sqrt(5.0)) / 2.0,
                '2*x+1=phi' => ((\sqrt(5.0) - 1.0) / 2.0) / 2.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertFalse($roots[0]->isExactRoot, $equation);
            $lower = (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator;
            $upper = (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator;
            self::assertLessThanOrEqual($expected + 1.0E-12, $lower, $equation);
            self::assertGreaterThanOrEqual($expected - 1.0E-12, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=pi', 'x', '3', '4'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('x=pi', 'x', '0', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=pi+1', 'x', '4', '5'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=e', 'x', '2', '3'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('x=e', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x=phi', 'x', '1', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('x=phi', 'x', '0', '1'));
        $boundedRoot = Math::isolatePolynomialRootsInRationalInterval('x=pi', 'x', '3', '4');
        self::assertNotNull($boundedRoot);
        self::assertCount(1, $boundedRoot);
        self::assertFalse($boundedRoot[0]->isExactRoot);
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('x=pi', 'x', '0', '3'));
        $offsetRoot = Math::isolatePolynomialRootsInRationalInterval('x=pi+1', 'x', '4', '5');
        self::assertNotNull($offsetRoot);
        self::assertCount(1, $offsetRoot);
        self::assertFalse($offsetRoot[0]->isExactRoot);
        $eRoot = Math::isolatePolynomialRootsInRationalInterval('x=e', 'x', '2', '3');
        self::assertNotNull($eRoot);
        self::assertCount(1, $eRoot);
        self::assertFalse($eRoot[0]->isExactRoot);
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('x=e', 'x', '0', '2'));
        $phiRoot = Math::isolatePolynomialRootsInRationalInterval('x=phi', 'x', '1', '2');
        self::assertNotNull($phiRoot);
        self::assertCount(1, $phiRoot);
        self::assertFalse($phiRoot[0]->isExactRoot);
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('x=phi', 'x', '0', '1'));
    }

    public function testAllRealIsolationSupportsTwoBranchAffineHyperbolicCosineRoots(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cosh(x) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cosh(2*x - 1) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cosh(2*x - 1) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cosh(x) = 1/2', 'x'));

        foreach (
            [
                'cosh(x) = 2' => [-\acosh(2.0), \acosh(2.0)],
                'cosh(2*x - 1) = 2' => [(1.0 - \acosh(2.0)) / 2.0, (1.0 + \acosh(2.0)) / 2.0],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(2, $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-6, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-6, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }
        $root = Math::isolatePolynomialRootsOnRealLine('cosh(2*x - 1) = 1', 'x');
        self::assertNotNull($root);
        self::assertCount(1, $root);
        self::assertTrue($root[0]->isExactRoot);
        self::assertSame(['1', '2'], [$root[0]->lowerNumerator, $root[0]->lowerDenominator]);
    }

    public function testAllRealIsolationSupportsTwoBranchAffineHyperbolicSecantRoots(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sech(x) = 1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sech(2*x - 1) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sech(2*x - 1) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sech(x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sech(x) = 2', 'x'));

        foreach (
            [
                'sech(x) = 1/2' => [-\acosh(2.0), \acosh(2.0)],
                'sech(2*x - 1) = 1/2' => [(1.0 - \acosh(2.0)) / 2.0, (1.0 + \acosh(2.0)) / 2.0],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(2, $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-6, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-6, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }
        $root = Math::isolatePolynomialRootsOnRealLine('sech(2*x - 1) = 1', 'x');
        self::assertNotNull($root);
        self::assertCount(1, $root);
        self::assertTrue($root[0]->isExactRoot);
        self::assertSame(['1', '2'], [$root[0]->lowerNumerator, $root[0]->lowerDenominator]);
    }

    public function testAllRealIsolationSupportsAffineHyperbolicCosecantAndCotangentRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('csch(2*x - 1) = 1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('csch(x) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('coth(2*x - 1) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('coth(2*x - 1) = -2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('coth(x) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('coth(x) = 1/2', 'x'));

        foreach (
            [
                'csch(2*x - 1) = 1/2' => (1.0 + \asinh(2.0)) / 2.0,
                'coth(2*x - 1) = 2' => (1.0 + \atanh(0.5)) / 2.0,
                'coth(2*x - 1) = -2' => (1.0 + \atanh(-0.5)) / 2.0,
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $lower = (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator;
            $upper = (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testUnattainableHyperbolicTargetsHaveNoRootsForNonlinearPhases(): void
    {
        foreach (
            [
                'tanh(x^2) = 1',
                'tanh(x^2) = -1',
                'tanh(sin(x)) = 1',
                'tanh(sin(x)) = -1',
                'tanh(x^2) = 1000',
                'tanh(x^2) = -1000',
                'coth(x^2) = 1/2',
                'coth(x^2) = -1/2',
                'coth(x^2) = 0',
                'sech(x^2) = 0',
                'sech(x^2) = 2',
                'csch(x^2) = 0',
                'coth(x^2) = 1',
                'coth(x^2) = -1',
                'cosh(x^2) = 1/2',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
                $equation,
            );
        }

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tanh(x) = 999/1000', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('coth(x) = 1001/1000', 'x'));
    }

    public function testQuadraticTanhAndCothEquationsUseCertifiedInverseLevels(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('tanh(x^2) = 1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('coth(x^2) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('tanh(-x^2) = -1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('coth(-x^2) = -2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tanh((x - 1)^2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('coth(x^2) = -2', 'x'));

        $positiveRoot = \sqrt(\atanh(0.5));
        foreach (
            [
                'tanh(x^2) = 1/2' => [-$positiveRoot, $positiveRoot],
                'coth(x^2) = 2' => [-$positiveRoot, $positiveRoot],
                'tanh(-x^2) = -1/2' => [-$positiveRoot, $positiveRoot],
                'coth(-x^2) = -2' => [-$positiveRoot, $positiveRoot],
                'tanh((x - 1)^2) = 0' => [1.0],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(\count($expectedRoots), $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-6, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-6, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('tanh(x^2) = 1/2', 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('tanh(x^2) = 1/2', 'x', '0', '1/2'));
        $bounded = Math::isolatePolynomialRootsInRationalInterval('coth(x^2) = 2', 'x', '0', '2');
        self::assertNotNull($bounded);
        self::assertCount(1, $bounded);
        $lower = (float) $bounded[0]->lowerNumerator / (float) $bounded[0]->lowerDenominator;
        $upper = (float) $bounded[0]->upperNumerator / (float) $bounded[0]->upperDenominator;
        self::assertLessThan($positiveRoot + 1.0E-6, $lower);
        self::assertGreaterThan($positiveRoot - 1.0E-6, $upper);
        self::assertLessThanOrEqual(1.0E-6, $upper - $lower);
    }

    public function testQuadraticHyperbolicFamiliesUseCertifiedInverseLevels(): void
    {
        foreach (
            [
                'sinh(x^2) = 1/2',
                'cosh(x^2) = 2',
                'sech(x^2) = 1/2',
                'csch(x^2) = 1/2',
                'asinh(x^2) = 1/2',
                'acosh(x^2) = 1/2',
                'atanh(x^2) = 1/2',
                'asech(x^2) = 1/2',
                'acsch(x^2) = 1/2',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                2,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'),
                $equation,
            );
        }

        foreach (['sinh(x^2) = 1/2', 'cosh(x^2) = 2', 'asech(x^2) = 1/2'] as $equation) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(2, $roots, $equation);
        }
    }

    public function testQuadraticErrorFunctionFamiliesUseCertifiedInverseLevels(): void
    {
        $equations = [
            'erf(x^2 - 1) = 1/2',
            'erfc(x^2 - 1) = 1',
            'erfinv(x^2 - 1) = 1/2',
            'erfcinv(x^2 - 1) = 1/2',
        ];
        foreach ($equations as $equation) {
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                2,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(2, $roots, $equation);
            foreach ($roots as $root) {
                self::assertLessThanOrEqual(
                    1.0E-6,
                    ((float) $root->upperNumerator / (float) $root->upperDenominator)
                        - ((float) $root->lowerNumerator / (float) $root->lowerDenominator),
                    $equation,
                );
            }
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erf(x^2 - 1) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfc(x^2 - 1) = 0', 'x'));
    }

    public function testReciprocalErrorFunctionPhasesUseCertifiedAllRealRoots(): void
    {
        foreach (
            [
                'erf(1/x) = 1/2',
                '1/2 = erf(1/x)',
                'erfc(1/x) = 1/2',
                'erf(1/(2*x + 1)) = 1/2',
                'erfc(3/(2*x + 1)) = 1/2',
                'erf((2*x + 1)/(x + 3)) = 1/2',
                'tanh(1/x) = 1/2',
                'sinh(2/(x + 1)) = 1',
                'asinh(1/x) = 1',
                'cosh(1/x) = 2',
                'sech(1/x) = 1/2',
                'atan(1/x) = 1/2',
                'arctan((2*x + 1)/(x + 3)) = 1/2',
                'atan(1/x) = 6/5',
                'asin(1/x) = 1/2',
                'acos(1/x) = 1/2',
                'acot(1/x) = 1',
                'asec(1/x) = 1',
                'acsc(1/x) = 1',
            ] as $equation
        ) {
            $expectedCount = \in_array($equation, ['cosh(1/x) = 2', 'sech(1/x) = 1/2'], true) ? 2 : 1;
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            self::assertLessThanOrEqual(
                1.0E-6,
                ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
                    - ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator),
                $equation,
            );
        }

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erf(1/x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfc(1/x) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(1/x) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(1/x) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(1/x) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acos(1/x) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asec(1/x) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsc(1/x) = 0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cosh(1/x) = 2', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sech(1/x) = 1/2', 'x', '0', '2'));

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('erf(1/x) = 1/2', 'x', '1', '4'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('erf(1/x) = 1/2', 'x', '-4', '0'));
        $bounded = Math::isolatePolynomialRootsInRationalInterval('erf(1/x) = 1/2', 'x', '1', '4');
        self::assertNotNull($bounded);
        self::assertCount(1, $bounded);
    }

    public function testMonotoneHigherDegreeHyperbolicPhasesAreCountedExactly(): void
    {
        foreach (
            [
                'tanh(x^3 + x) = 1/2',
                'coth(x^3 + x) = 2',
                'tanh(-x^3 - x) = -1/2',
                'coth(-x^3 - x) = -2',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('tanh(x^3 + x) = 1/2', 'x', '0', '1'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('tanh(x^3 + x) = 1/2', 'x', '0', '1/3'),
        );
        foreach (
            [
                Math::isolatePolynomialRootsOnRealLine('tanh(x^3 + x) = 1/2', 'x'),
                Math::isolatePolynomialRootsInRationalInterval('coth(x^3 + x) = 2', 'x', '0', '1'),
            ] as $roots
        ) {
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertLessThanOrEqual(
                1.0E-6,
                ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
                    - ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator),
            );
        }
    }

    public function testHyperbolicPolynomialPhasesCountCrossingsAroundCriticalValues(): void
    {
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('tanh(x^3 - x) = 0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('tanh(x^3 - x) = 1/4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('tanh(x^3 - x) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('coth(x^3 - x) = 2', 'x'));
        self::assertSame(
            3,
            Math::countPolynomialRootsInRationalInterval('tanh(x^3 - x) = 0', 'x', '-2', '2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('tanh(x^3 - x) = 1/4', 'x', '0', '2'),
        );
        $bounded = Math::isolatePolynomialRootsInRationalInterval('tanh(x^3 - x) = 0', 'x', '-2', '2');
        self::assertNotNull($bounded);
        self::assertCount(3, $bounded);
        $allReal = Math::isolatePolynomialRootsOnRealLine('tanh(x^3 - x) = 0', 'x');
        self::assertNotNull($allReal);
        self::assertCount(3, $allReal);
    }

    public function testZeroTanhLevelsUseExactPolynomialBoundaryAndCriticalRoots(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('tanh((x^2 - 2)^2) = 0', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('tanh(x^3 - x) = 0', 'x', '-1', '2'),
        );
        $bounded = Math::isolatePolynomialRootsInRationalInterval('tanh(x^3 - x) = 0', 'x', '-1', '2');
        self::assertNotNull($bounded);
        self::assertCount(2, $bounded);
        $allReal = Math::isolatePolynomialRootsOnRealLine('tanh((x^2 - 2)^2) = 0', 'x');
        self::assertNotNull($allReal);
        self::assertCount(2, $allReal);
    }

    public function testPolynomialPhaseHyperbolicSineUsesCriticalLevelIsolation(): void
    {
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('sinh(x^3 - x) = 1/4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh(x^3 - x) = 1/2', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('sinh(x^3 - x) = -1/4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh(x^3 + x) = 10', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh(x^3 + x) = -10', 'x'));
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval('sinh(x^3 - x) = 1/4', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sinh(x^3 - x) = 1/4', 'x', '0', '2'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sinh((x^2 - 2)^2) = 0', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('sinh(x^3 - x) = 0', 'x', '-1', '2'),
        );

        foreach (
            [
                Math::isolatePolynomialRootsOnRealLine('sinh(x^3 - x) = 1/4', 'x'),
                Math::isolatePolynomialRootsInRationalInterval('sinh(x^3 - x) = 1/4', 'x', '-2', '2'),
            ] as $roots
        ) {
            self::assertNotNull($roots);
            self::assertCount(3, $roots);
            foreach ($roots as $root) {
                self::assertLessThanOrEqual(
                    1.0E-6,
                    ((float) $root->upperNumerator / (float) $root->upperDenominator)
                        - ((float) $root->lowerNumerator / (float) $root->lowerDenominator),
                );
            }
        }
        $largeTargetRoots = Math::isolatePolynomialRootsOnRealLine('sinh(x^3 + x) = 10', 'x');
        self::assertNotNull($largeTargetRoots);
        self::assertCount(1, $largeTargetRoots);
        self::assertLessThanOrEqual(
            1.0E-6,
            ((float) $largeTargetRoots[0]->upperNumerator / (float) $largeTargetRoots[0]->upperDenominator)
                - ((float) $largeTargetRoots[0]->lowerNumerator / (float) $largeTargetRoots[0]->lowerDenominator),
        );
    }

    public function testPolynomialPhaseHyperbolicUnitLevelsReduceToExactPolynomialRoots(): void
    {
        foreach (['cosh', 'sech'] as $function) {
            $equation = $function . '((x^2 - 1)^2) = 1';
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));

            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            self::assertTrue($roots[0]->isExactRoot);
            self::assertSame('-1', $roots[0]->lowerNumerator);
            self::assertTrue($roots[1]->isExactRoot);
            self::assertSame('1', $roots[1]->lowerNumerator);
        }
    }

    public function testPolynomialPhaseHyperbolicCosineAndSecantUseSymmetricLevels(): void
    {
        foreach (
            [
                ['cosh(x^3 - x) = 21/20', 6],
                ['sech(x^3 - x) = 20/21', 6],
                ['cosh(x^3 - x) = 2', 2],
                ['sech(x^3 - x) = 1/2', 2],
                ['cosh(x^3 - x) = 10', 2],
                ['sech(x^3 - x) = 1/10', 2],
            ] as [$equation, $expectedCount]
        ) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );

            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
            foreach ($roots as $root) {
                self::assertLessThanOrEqual(
                    1.0E-6,
                    ((float) $root->upperNumerator / (float) $root->upperDenominator)
                        - ((float) $root->lowerNumerator / (float) $root->lowerDenominator),
                );
            }
        }

        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
            'cosh(x^3 - x) = 21/20',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($boundedRoots);
        self::assertCount(6, $boundedRoots);
    }

    public function testPolynomialPhaseHyperbolicCosecantUsesReciprocalSineLevel(): void
    {
        foreach (['10', '-10'] as $target) {
            $equation = 'csch(x^3 - x) = ' . $target;
            self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));

            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(3, $roots);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('csch(x^3 - x) = 0', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('csch(x^3 - x) = 0', 'x'));
    }

    public function testInverseHyperbolicSineOfPolynomialUsesCertifiedForwardLevel(): void
    {
        foreach (
            [
                ['asinh(x^3 - x) = 1/4', 3],
                ['arcsinh(x^3 - x) = -1/4', 3],
                ['asinh(x^3 - x) = 1', 1],
            ] as [$equation, $expectedCount]
        ) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
        }
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asinh((x^2 - 2)^2) = 0', 'x'));
    }

    public function testOtherInverseHyperbolicFunctionsOfPolynomialUseCertifiedLevels(): void
    {
        foreach (
            [
                ['acosh(x^3 - x) = 1/4', 1],
                ['arccosh((x^2 - 2)^2) = 0', 4],
                ['atanh(x^3 - x) = 1/4', 3],
                ['arctanh((x^2 - 2)^2) = 0', 2],
                ['acoth(x^3 - x) = 2', 1],
                ['asech((x^2 - 2)^2) = 0', 4],
                ['acsch(x^3 - x) = 2', 3],
                ['acosh(x^3 - x) = -1', 0],
                ['asech(x^3 - x) = -1', 0],
                ['acoth(x^3 - x) = 0', 0],
                ['acsch(x^3 - x) = 0', 0],
            ] as [$equation, $expectedCount]
        ) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
        }
    }

    public function testPolynomialPhaseSineAndCosineZeroLevelsAreIsolatedOnBoundedIntervals(): void
    {
        self::assertSame(11, Math::countPolynomialRootsInRationalInterval('sin(x^2) = 0', 'x', '-4', '4'));
        self::assertSame(10, Math::countPolynomialRootsInRationalInterval('cos(x^2) = 0', 'x', '-4', '4'));
        self::assertSame(5, Math::countPolynomialRootsInRationalInterval('sin(x^2) = 0', 'x', '0', '4'));
        self::assertSame(5, Math::countPolynomialRootsInRationalInterval('cos(x^2) = 0', 'x', '0', '4'));

        foreach (
            [
                ['sin(x^2) = 0', '-4', '4', 11],
                ['cos(x^2) = 0', '-4', '4', 10],
                ['sin(x^2) = 0', '0', '4', 5],
                ['cos(x^2) = 0', '0', '4', 5],
            ] as [$equation, $lower, $upper, $expectedCount]
        ) {
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
        }
    }

    public function testPolynomialPhaseSineAndCosineUseRationalInverseLevels(): void
    {
        foreach (
            [
                ['sin(x^2) = 1/2', 12, 6],
                ['cos(x^2) = 1/2', 10, 5],
                ['csc(x^2) = 2', 12, 6],
                ['sec(x^2) = 2', 10, 5],
                ['tan(x^2) = 1', 10, 5],
                ['cot(x^2) = 1', 10, 5],
                ['tan(x^2) = 2', 10, 5],
                ['cot(x^2) = 2', 10, 5],
            ] as [$equation, $expectedAll, $expectedPositive]
        ) {
            self::assertSame(
                $expectedAll,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-4', '4'),
            );
            self::assertSame(
                $expectedPositive,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '4'),
            );

            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-4', '4');
            self::assertNotNull($roots);
            self::assertCount($expectedAll, $roots);
        }
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sin(x^2) = 2', 'x', '-4', '4'));
    }

    public function testPolynomialPhaseTrigonometricIsolationRefinesToRequestedNarrowWidth(): void
    {
        $maximumWidthDenominator = '1' . \str_repeat('0', 30);
        $arithmetic = new IntegerPolynomialExtractor('', []);
        foreach ([['sin(x^2) = 1/3', 2], ['cos(x^2) = 1/3', 1]] as [$equation, $expectedCount]) {
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '0',
                '2',
                maximumWidth: '1/' . $maximumWidthDenominator,
            );

            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            foreach ($roots as $root) {
                $widthNumerator = $arithmetic->subtract(
                    $arithmetic->multiplyIntegerValues($root->upperNumerator, $root->lowerDenominator),
                    $arithmetic->multiplyIntegerValues($root->lowerNumerator, $root->upperDenominator),
                );
                $widthDenominator = $arithmetic->multiplyIntegerValues(
                    $root->upperDenominator,
                    $root->lowerDenominator,
                );
                self::assertLessThanOrEqual(
                    0,
                    $arithmetic->compareIntegerValues(
                        $arithmetic->multiplyIntegerValues($widthNumerator, $maximumWidthDenominator),
                        $widthDenominator,
                    ),
                    $equation,
                );
            }
        }
    }

    public function testFiniteAffineMonotoneFunctionsAreIsolatedWithinRequestedWidth(): void
    {
        $narrowWidthDenominator = '1' . \str_repeat('0', 30);
        $defaultWidthDenominator = '1000000';
        $cases = [
            ['exp(x) = 2', '0', '3', $narrowWidthDenominator, 1],
            ['ln(x) = 1', '1', '3', $narrowWidthDenominator, 1],
            ['sinh(x) = 1', '0', '2', $defaultWidthDenominator, 1],
            ['cosh(x - 1) = 2', '-1', '3', $defaultWidthDenominator, 2],
            ['sech(x - 1) = 1', '0', '2', $defaultWidthDenominator, 1],
            ['asinh(x) = 1', '0', '2', $narrowWidthDenominator, 1],
        ];
        $arithmetic = new IntegerPolynomialExtractor('', []);

        foreach ($cases as [$equation, $lower, $upper, $maximumWidthDenominator, $expectedCount]) {
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                maximumWidth: '1/' . $maximumWidthDenominator,
            );

            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            foreach ($roots as $root) {
                $widthNumerator = $arithmetic->subtract(
                    $arithmetic->multiplyIntegerValues($root->upperNumerator, $root->lowerDenominator),
                    $arithmetic->multiplyIntegerValues($root->lowerNumerator, $root->upperDenominator),
                );
                $widthDenominator = $arithmetic->multiplyIntegerValues(
                    $root->upperDenominator,
                    $root->lowerDenominator,
                );

                self::assertLessThanOrEqual(
                    0,
                    $arithmetic->compareIntegerValues(
                        $arithmetic->multiplyIntegerValues($widthNumerator, $maximumWidthDenominator),
                        $widthDenominator,
                    ),
                    $equation,
                );
            }
        }
    }

    public function testFiniteAffineInverseTrigonometricLevelsAreCountedAndIsolated(): void
    {
        $cases = [
            ['atan(x) = 1', '0', '2'],
            ['asin(x) = 1', '0', '1'],
            ['acos(x) = 1', '0', '1'],
            ['acot(x) = 1', '0', '1'],
            ['arctan(2*x - 1) = 1', '0', '2'],
            ['atan(1 - 2*x) = 1', '-1', '0'],
        ];

        foreach ($cases as [$equation, $lower, $upper]) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                maximumWidth: '1/1000',
            );

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertFalse($roots[0]->isExactRoot, $equation);
        }

        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('acos(x) = 4', 'x', '0', '1'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('acos(x) = 4', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('atan(x) = 0', 'x', '0', '1'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('atan(x) = 0', 'x', '0', '1'));
    }

    public function testFiniteAffineErrorFunctionLevelsAreCountedAndIsolated(): void
    {
        $cases = [
            ['erf(x) = 1/2', '-2', '2', false],
            ['erfc(x) = 1', '-2', '2', true],
            ['erfinv(x) = 1/2', '-1', '1', false],
            ['erfcinv(x) = 1/2', '0', '1', false],
        ];

        foreach ($cases as [$equation, $lower, $upper, $isExactRoot]) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                maximumWidth: '1/1000',
            );

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            self::assertSame($isExactRoot, $roots[0]->isExactRoot, $equation);
        }

        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('erf(x) = 2', 'x', '-2', '2'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('erf(x) = 2', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('erfc(x) = 1', 'x', '0', '1'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('erfc(x) = 1', 'x', '0', '1'));
    }

    public function testFiniteAffineRationalBaseExponentialAndLogarithmLevelsAreIsolated(): void
    {
        $cases = [
            ['log2(x) = 1', '1', '3'],
            ['log10(x) = 1', '9', '11'],
            ['log(x, 3) = 2', '8', '10'],
            ['2^x = 3', '1', '2'],
        ];

        foreach ($cases as [$equation, $lower, $upper]) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lower,
                $upper,
                maximumWidth: '1/1000',
            );

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
        }

        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('log2(x) = 4', 'x', '1', '3'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('log2(x) = 4', 'x', '1', '3'));
    }

    public function testAffineInverseAndErrorRootsNearFiniteBoundsAreRefinedBeforeClassification(): void
    {
        $cases = [
            ['atan(x) = 1', '1.557407724', '2'],
            ['erf(x) = 1/2', '0.47693627', '1'],
        ];

        foreach ($cases as [$equation, $lower, $upper]) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);

            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $rootLower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $rootUpper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertGreaterThan((float) $lower, $rootLower, $equation);
            self::assertLessThan((float) $upper, $rootUpper, $equation);
        }

        foreach (
            [
            ['atan(x) = 1', '0', '1.557407724'],
            ['erf(x) = 1/2', '0', '0.47693627'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
        }
    }

    public function testPolynomialPhaseTangentAndCotangentAcceptUnboundedRealLevels(): void
    {
        foreach (['tan(x^2) = 2', 'cot(x^2) = 2'] as $equation) {
            self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '3'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '3');
            self::assertNotNull($roots);
            self::assertCount(3, $roots);

            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
                self::fail('A nonconstant tangent or cotangent phase has infinitely many solutions.');
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNonlinearInverseSineRootsAreIsolatedOnBoundedIntervals(): void
    {
        $equation = 'asin(x^2 - 1) = 1/2';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'arcsin(x^2 - 1) = -1/2',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval(
            'arcsin(x^2 - 1) = 2',
            'x',
            '-2',
            '2',
        ));

        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($allRealRoots);
        self::assertCount(2, $allRealRoots);
        foreach ($roots as $root) {
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower);
        }
    }

    public function testNonlinearInverseCosineRootsAreIsolatedOnBoundedIntervals(): void
    {
        $equation = 'acos(x^2 - 1) = 1/2';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('arccos(x^2 - 1) = 4', 'x'));

        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($allRealRoots);
        self::assertCount(2, $allRealRoots);
    }

    public function testNonlinearInverseTrigonometricRootsAreIsolated(): void
    {
        foreach (
            [
                'atan(x^2 - 1) = 1/2',
                'acot(x^2 - 1) = 1/2',
                'asec(x^2 - 1) = 1/2',
                'asec(x^2 - 3) = 2',
                'acsc(x^2 - 3) = 1/2',
                'acsc(x^2 - 3) = -1/2',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-3', '3'));
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-3', '3');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRealRoots);
            self::assertCount(2, $allRealRoots);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(x^2 - 1) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acot(x^2 - 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asec(x^2 - 1) = 4', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsc(x^2 - 3) = 0', 'x'));
    }

    public function testPolynomialPhaseSincZeroSetsExcludeTheRemovableOrigin(): void
    {
        foreach (
            [
                ['sinc(x^2) = 0', 10, 5],
                ['sincpi(x^2) = 0', 30, 15],
            ] as [$equation, $expectedAll, $expectedPositive]
        ) {
            self::assertSame(
                $expectedAll,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-4', '4'),
            );
            self::assertSame(
                $expectedPositive,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '4'),
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-4', '4');
            self::assertNotNull($roots);
            self::assertCount($expectedAll, $roots);
        }
    }

    public function testAllRealIsolationSupportsQuadraticExponentialEquations(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('exp(x^2) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('exp(-x^2) = 1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp((x - 1)^2) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(x^2) = 1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(-x^2) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('expm1(x^2) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('expm1(-x^2) = -1/2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('expm1(x^2) = -1', 'x'));

        foreach (
            [
                'exp(x^2) = 2' => [-\sqrt(\log(2.0)), \sqrt(\log(2.0))],
                'exp(-x^2) = 1/2' => [-\sqrt(\log(2.0)), \sqrt(\log(2.0))],
                'expm1(x^2) = 1' => [-\sqrt(\log(2.0)), \sqrt(\log(2.0))],
                'expm1(-x^2) = -1/2' => [-\sqrt(\log(2.0)), \sqrt(\log(2.0))],
                'exp((x - 1)^2) = 1' => [1.0],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(\count($expectedRoots), $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-6, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-6, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }
    }

    public function testAllRealExponentialUnitLevelsReduceToExactPolynomialRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(x^4) = 1', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('expm1((x - 1)^3) = 0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('exp((x^2 - 1)^2) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(x^4 + 1) = 1', 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine('exp((x^2 - 1)^2) = 1', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $root) {
            self::assertTrue($root->isExactRoot);
        }
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);
    }

    public function testAllRealExponentialUnitLevelIdentityReportsInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('exp(x - x) = 1', 'x');
    }

    public function testAllRealExponentialUnitLevelIdentityIsolationReportsInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('expm1(x - x) = 0', 'x');
    }

    public function testAllRealNonUnitPolynomialExponentialLevelsAreIsolated(): void
    {
        foreach (
            [
                'exp(x^3 + x) = 2' => 1,
                'exp(x^3 - x) = 2' => 1,
                'exp(x^3 - x) = 4/5' => 3,
                'expm1(-x^3 - x) = 1' => 1,
            ] as $equation => $expectedCount
        ) {
            self::assertSame($expectedCount, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
        }
    }

    public function testNearCriticalPolynomialExponentialLevelKeepsAllThreeRoots(): void
    {
        $equation = 'exp(x^3 - 3*x^2) = 999999/1000000';
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
    }

    public function testMonotoneHigherDegreePolynomialExponentialLevelsAreCertified(): void
    {
        foreach (
            [
                'exp(x^3 + x) = 2' => ['0', '1', 1, 0.537693],
                'expm1(x^3 + x) = 1' => ['0', '1', 1, 0.537693],
                'exp(-x^3 - x) = 2' => ['-1', '0', 1, -0.537693],
                'exp(x^3 + x) = 4' => ['0', '1/2', 0, null],
                'exp(x^3 - x) = 2' => ['-2', '2', 1, 1.2472864704],
                'exp(x^3 - x) = 4/5' => ['-2', '2', 3, 0.0],
            ] as $equation => [$lower, $upper, $expectedCount, $expectedRoot]
        ) {
            $count = Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertSame($expectedCount, $count, $equation);
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            if ($expectedCount === 1) {
                $root = $roots[0];
                $lowerValue = $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator);
                $upperValue = $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator);
                self::assertLessThanOrEqual($expectedRoot, $lowerValue, $equation);
                self::assertGreaterThanOrEqual($expectedRoot, $upperValue, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upperValue - $lowerValue, $equation);
            }
        }
    }

    public function testPolynomialExponentialLevelsAllowDerivativeZerosWithoutSignChanges(): void
    {
        foreach (
            [
                'exp(x^3) = 2' => ['-1', '1'],
                'expm1(x^3 + x^5) = 1' => ['-1', '1'],
                'exp(-x^3) = 2' => ['-1', '1'],
            ] as $equation => [$lower, $upper]
        ) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
        }
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('exp(x^3 - x) = 2', 'x', '-2', '2'),
        );
        self::assertSame(
            3,
            Math::countPolynomialRootsInRationalInterval('exp(x^3 - x) = 4/5', 'x', '-2', '2'),
        );
    }

    public function testUnitExponentialLevelsCertifyTangentialPolynomialRoots(): void
    {
        foreach (
            [
                'exp(x^4) = 1' => ['-1', '1', 1],
                'expm1((x - 1)^3) = 0' => ['0', '2', 1],
                'exp((x^2 - 1)^2) = 1' => ['-2', '2', 2],
            ] as $equation => [$lower, $upper, $expectedCount]
        ) {
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
                $equation,
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots, $equation);
            self::assertCount($expectedCount, $roots, $equation);
            foreach ($roots as $root) {
                self::assertTrue($root->isExactRoot, $equation);
            }
        }
    }

    public function testAllRealIsolationSupportsQuadraticLogarithmEquations(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^2 + 1) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log2(x^2 + 1) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log10(x^2 + 1) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(x^2 + 1, 3) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log2((x - 1)^2 + 1) = 1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^2 - 1) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(-x^2 + 3) = 1', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln((x - 1)^2 + 1) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x^2 + 1) = -1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(-x^2 + 1) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log1p(x^2) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log1p(-x^2 + 3) = 1', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log1p((x - 1)^2) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log1p(x^2) = -1', 'x'));

        foreach (
            [
                'ln(x^2 + 1) = 1' => [-\sqrt(\exp(1.0) - 1.0), \sqrt(\exp(1.0) - 1.0)],
                'log2(x^2 + 1) = 1' => [-1.0, 1.0],
                'log10(x^2 + 1) = 1' => [-3.0, 3.0],
                'log(x^2 + 1, 3) = 2' => [-\sqrt(8.0), \sqrt(8.0)],
                'log2((x - 1)^2 + 1) = 1/2' => [
                    1.0 - \sqrt(\sqrt(2.0) - 1.0),
                    1.0 + \sqrt(\sqrt(2.0) - 1.0),
                ],
                'ln(x^2 - 1) = 1' => [-\sqrt(\exp(1.0) + 1.0), \sqrt(\exp(1.0) + 1.0)],
                'ln(-x^2 + 3) = 1' => [-\sqrt(3.0 - \exp(1.0)), \sqrt(3.0 - \exp(1.0))],
                'ln((x - 1)^2 + 1) = 0' => [1.0],
                'log1p(x^2) = 1' => [-\sqrt(\exp(1.0) - 1.0), \sqrt(\exp(1.0) - 1.0)],
                'log1p(-x^2 + 3) = 1' => [-\sqrt(4.0 - \exp(1.0)), \sqrt(4.0 - \exp(1.0))],
                'log1p((x - 1)^2) = 0' => [1.0],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(\count($expectedRoots), $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-6, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-6, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }
    }

    public function testAllRealIsolationSupportsMonotoneHigherDegreeLogarithmEquations(): void
    {
        foreach (
            [
                'ln(x^3 + x) = 0' => 0.6823278038280193,
                'log2(x^3 + x) = 1' => 1.0,
                'log10(x^3 + x) = 0' => 0.6823278038280193,
                'log1p(x^3 + x) = 0' => 0.0,
                'ln(-x^3 - x) = 0' => -0.6823278038280193,
            ] as $equation => $expected
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThanOrEqual($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThanOrEqual($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log1p(x^3 + x) = -2', 'x'));

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('ln(x^3 + x) = 0', 'x', '0', '1'),
        );
    }

    public function testAllRealIsolationSupportsNonmonotoneHigherDegreeLogarithms(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^4 + 1) = 1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x^4 + 1) = -1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^4 - x^2 + 1) = 0.2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(x^4 + 1, 3) = 1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(x^4 + 1, 0.5) = -1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log(x^4 + 1, 16) = 1/2', 'x'));

        foreach (
            [
                'ln(x^4 + 1) = 1' => [-((\M_E - 1.0) ** 0.25), (\M_E - 1.0) ** 0.25],
                'ln(x^4 - x^2 + 1) = 0.2' => [-1.0893, 1.0893],
            ] as $equation => $expectedRoots
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots, $equation);
            self::assertCount(2, $roots, $equation);
            foreach ($roots as $index => $root) {
                $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
                $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
                self::assertLessThan($expectedRoots[$index] + 1.0E-3, $lower, $equation);
                self::assertGreaterThan($expectedRoots[$index] - 1.0E-3, $upper, $equation);
                self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
            }
        }
    }

    public function testFiniteIntervalIsolationSupportsNonmonotoneHigherDegreeLogarithms(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('ln(x^4 + 1) = 1', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('ln(x^4 + 1) = 1', 'x', '1', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('ln(x^4 + 1) = 1', 'x', '0', '1'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('log(x^4 + 1, 3) = 1', 'x', '-2', '2'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('log(x^4 + 1, 0.5) = -1', 'x', '-2', '2'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('log(x^4 + 1, 16) = 1/2', 'x', '-2', '2'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('ln((x^2 - 1)^2 + 1) = 0', 'x', '-2', '2'),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval('ln(x^4 + 1) = 1', 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertLessThan(0.0, (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator);
        self::assertGreaterThan(0.0, (float) $roots[1]->lowerNumerator / (float) $roots[1]->lowerDenominator);

        $exactRoots = Math::isolatePolynomialRootsOnRealLine('log(x^4 + 1, 0.5) = -1', 'x');
        self::assertNotNull($exactRoots);
        self::assertCount(2, $exactRoots);
        self::assertTrue($exactRoots[0]->isExactRoot);
        self::assertTrue($exactRoots[1]->isExactRoot);
    }

    public function testFiniteIntervalIsolationSupportsQuadraticLogarithmEquations(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('ln(x^2 + 1) = 1', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('ln(x^2 + 1) = 1', 'x', '-2', '0'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('ln(x^2 + 1) = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('log2(x^2 + 1) = 1', 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('log2(x^2 + 1) = 1', 'x', '1', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'log(x^2 + 1, 3) = 2',
            'x',
            '2',
            '4',
        ));

        foreach (
            [
                'ln(x^2 + 1) = 1' => ['0', '2', \sqrt(\exp(1.0) - 1.0)],
                'log2(x^2 + 1) = 1' => ['0', '2', 1.0],
                'log(x^2 + 1, 3) = 2' => ['2', '4', \sqrt(8.0)],
            ] as $equation => [$lowerBound, $upperBound, $expected]
        ) {
            $roots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $lowerBound,
                $upperBound,
            );
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testFiniteIntervalIsolationSupportsQuadraticExponentialAndLog1pEquations(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('expm1(x^2) = 1', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('log1p(x^2) = 1', 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('expm1(x^2) = 1', 'x', '0', '1/2'));

        foreach (
            [
                'expm1(x^2) = 1' => \sqrt(\log(2.0)),
                'log1p(x^2) = 1' => \sqrt(\exp(1.0) - 1.0),
            ] as $equation => $expected
        ) {
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '2');
            self::assertNotNull($roots, $equation);
            self::assertCount(1, $roots, $equation);
            $root = $roots[0];
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThan($expected + 1.0E-6, $lower, $equation);
            self::assertGreaterThan($expected - 1.0E-6, $upper, $equation);
            self::assertLessThanOrEqual(1.0E-6, $upper - $lower, $equation);
        }
    }

    public function testAffineExponentialRootCountsRespectStrictRationalIntervalBounds(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('exp(x) = 2', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('exp(x) = 2', 'x', '0', '1/2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('exp(x) = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('exp(x) = 1', 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'expm1(2*x - 1) = 0',
            'x',
            '0',
            '1',
        ));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval(
            'expm1(2*x - 1) = 0',
            'x',
            '1/2',
            '1',
        ));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('expm1(x) = -1', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('ln(x) = 2', 'x', '7', '8'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('ln(x) = 2', 'x', '1', '7'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('log1p(x) = 0', 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('log1p(x) = 0', 'x', '0', '1'));
    }

    public function testAffineHyperbolicRootCountsRespectStrictRationalIntervalBounds(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sinh(x) = 1/2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cosh(x) = 1', 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('tanh(x) = 1/2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sech(x) = 1/2', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('csch(x) = 1/2', 'x', '1', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('coth(x) = 2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('asinh(x) = 1/2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('acosh(x) = 0', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('atanh(x) = 2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('asech(x) = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('acsch(x) = 1/2', 'x', '1', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('acoth(x) = 1/2', 'x', '2', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('atanh(2*x - 1) = 2', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('atanh(x) = 0', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('acosh(x) = 0', 'x', '1', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sinh(x) = 0', 'x', '0', '1'));
    }

    public function testAffinePeriodicZeroRootCountsUseCertifiedPiBounds(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = 0', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = 0', 'x', '3', '4'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'sin(x) = 0',
                'x',
                '3141592653589793/1000000000000000',
                '4',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'sin(x) = 0',
                'x',
                '3141592653589794/1000000000000000',
                '4',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = 0', 'x', '1', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = 1', 'x', '1', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = -1', 'x', '-2', '-1'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x) = 1/2', 'x', '0', '3'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x) = -1/2', 'x', '-3', '0'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = 1', 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = -1', 'x', '3', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = 1/2', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = -1/2', 'x', '1', '3'));
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval('sin(-2*x) = 0', 'x', '-2', '2'));
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval('tan(x) = 0', 'x', '-4', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cot(x) = 0', 'x', '1', '2'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(2*x + 1) = 0', 'x', '-2', '2'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sinc(x) = 0', 'x', '-4', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sincpi(x) = 0', 'x', '-2', '2'));
        self::assertSame(6, Math::countPolynomialRootsInRationalInterval('sincpi(2*x + 1) = 0', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sin(x) = 0', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('cos(x) = 1', 'x', '0', '1'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sin(x) = 2', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sincpi(x) = 0', 'x', '1', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('cos(x) = 0', 'x', '0', '1'));
    }

    public function testAffineSineAndCosineSupportGeneralRationalLevels(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = 1/3', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x) = 1/3', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x) = -1/3', 'x', '-2', '0'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(2*x + 1) = 1/3', 'x', '-1', '1'));

        $roots = Math::isolatePolynomialRootsInRationalInterval('sin(x) = 1/3', 'x', '0', '2');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        $lower = new ExactRationalInterval(
            [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
            [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
        );
        $upper = new ExactRationalInterval(
            [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
            [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
        );
        self::assertGreaterThan(0, $lower->compareUpperToRational('339', '1000'));
        self::assertLessThan(0, $upper->compareLowerToRational('341', '1000'));
    }

    public function testAffineTangentAndCotangentSupportGeneralRationalLevels(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('tan(x) = 2', 'x', '1', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cot(x) = 2', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('tan(x) = -2', 'x', '-2', '0'));
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval('tan(2*x + 1) = 2', 'x', '-2', '2'));
    }

    public function testAffineSecantAndCosecantUseReciprocalRationalLevels(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sec(x) = 2', 'x', '0', '2'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('csc(x) = 2', 'x', '0', '3'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sec(x) = 1/2', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('csc(x) = 0', 'x', '-2', '2'));
    }

    public function testPolynomialSquareRootEquationsFilterExtraneousSquaredRoots(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x) = x - 2', 'x', '-5', '10'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x + 1) = x', 'x', '-3', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x + 1) = -x', 'x', '-3', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x^2) = -x^2', 'x', '-2', '2'));

        $roots = Math::isolatePolynomialRootsInRationalInterval('sqrt(x + 1) = x', 'x', '0', '2');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);
    }

    public function testThreeRadicalPolynomialEquationIsIsolatedOnTheRealLine(): void
    {
        $equation = 'sqrt(x + 5) + sqrt(4*x - 12) - sqrt(6*x + 1) = 0';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('4', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[0]->lowerDenominator);
    }

    public function testAdditiveSingleSquareRootTermsAreCollectedBeforeExactIsolation(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x) + x = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x - sqrt(x) = 2', 'x', '0', '5'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('sqrt(x) + x = -1', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('2*sqrt(x) + 3*sqrt(x) = 10', 'x', '0', '10'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('3*sqrt(x) - sqrt(x) = 4', 'x', '0', '10'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('2*sqrt(x) + sqrt(x) = x', 'x', '-1', '10'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x)/2 + sqrt(x)/3 = 1', 'x', '0', '2'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('abs(x) + 2*abs(x) = 3', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x) + abs(x) = 1', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('2*sqrt(x) + x = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x)/2 + x = 1', 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('(x + 1)*sqrt(x) = x + 1', 'x', '0', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x)/(x + 1) = 1/2', 'x', '0', '2'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval(
                '(sqrt(x) + sqrt(x - 3))/(2*x - 3) = 3/5',
                'x',
                '3',
                '5',
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                '(2*x - 3)*(sqrt(x) + sqrt(x - 3)) = 15',
                'x',
                '3',
                '5',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sqrt(x - 1)/(x - 1) = 1', 'x', '0', '3'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('x*sqrt(x) = x', 'x', '-1', '2'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval('(x^2 - 2)*sqrt(x) = x^2 - 2', 'x', '0', '2'),
        );

        $zeroCoefficientRoots = Math::isolatePolynomialRootsInRationalInterval('x*sqrt(x) = x', 'x', '-1', '2');
        self::assertNotNull($zeroCoefficientRoots);
        self::assertCount(2, $zeroCoefficientRoots);
        self::assertTrue(
            (new ExactRationalInterval(
                [$zeroCoefficientRoots[0]->lowerNumerator, $zeroCoefficientRoots[0]->lowerDenominator],
                [$zeroCoefficientRoots[0]->upperNumerator, $zeroCoefficientRoots[0]->upperDenominator],
            ))->containsRational('0', '1'),
        );
        self::assertTrue($zeroCoefficientRoots[1]->isExactRoot);
        self::assertSame('1', $zeroCoefficientRoots[1]->lowerNumerator);

        $poleRoots = Math::isolatePolynomialRootsInRationalInterval(
            'sqrt(x - 1)/(x - 1) = 1',
            'x',
            '0',
            '3',
        );
        self::assertNotNull($poleRoots);
        self::assertCount(1, $poleRoots);
        self::assertTrue($poleRoots[0]->isExactRoot);
        self::assertSame('2', $poleRoots[0]->lowerNumerator);

        $irrationalZeroCoefficientRoot = Math::isolatePolynomialRootsInRationalInterval(
            '(x^2 - 2)*sqrt(x) = x^2 - 2',
            'x',
            '0',
            '2',
        );
        self::assertNotNull($irrationalZeroCoefficientRoot);
        self::assertCount(2, $irrationalZeroCoefficientRoot);
        $squareRootOfTwo = $irrationalZeroCoefficientRoot[1];
        $lowerSquare = (new ExactRationalInterval(
            [$squareRootOfTwo->lowerNumerator, $squareRootOfTwo->lowerDenominator],
            [$squareRootOfTwo->lowerNumerator, $squareRootOfTwo->lowerDenominator],
        ))->multiply(new ExactRationalInterval(
            [$squareRootOfTwo->lowerNumerator, $squareRootOfTwo->lowerDenominator],
            [$squareRootOfTwo->lowerNumerator, $squareRootOfTwo->lowerDenominator],
        ));
        $upperSquare = (new ExactRationalInterval(
            [$squareRootOfTwo->upperNumerator, $squareRootOfTwo->upperDenominator],
            [$squareRootOfTwo->upperNumerator, $squareRootOfTwo->upperDenominator],
        ))->multiply(new ExactRationalInterval(
            [$squareRootOfTwo->upperNumerator, $squareRootOfTwo->upperDenominator],
            [$squareRootOfTwo->upperNumerator, $squareRootOfTwo->upperDenominator],
        ));
        self::assertLessThan(0, $lowerSquare->compareUpperToRational('2', '1'));
        self::assertGreaterThan(0, $upperSquare->compareLowerToRational('2', '1'));

        $roots = Math::isolatePolynomialRootsInRationalInterval('sqrt(x) + x = 1', 'x', '0', '1');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);

        $scaledRoots = Math::isolatePolynomialRootsInRationalInterval('2*sqrt(x) + x = 1', 'x', '0', '1');
        self::assertNotNull($scaledRoots);
        self::assertCount(1, $scaledRoots);
        self::assertFalse($scaledRoots[0]->isExactRoot);

        $repeatedRadicalRoots = Math::isolatePolynomialRootsInRationalInterval(
            '2*sqrt(x) + 3*sqrt(x) = 10',
            'x',
            '0',
            '10',
        );
        self::assertNotNull($repeatedRadicalRoots);
        self::assertCount(1, $repeatedRadicalRoots);
        self::assertTrue(
            (new ExactRationalInterval(
                [$repeatedRadicalRoots[0]->lowerNumerator, $repeatedRadicalRoots[0]->lowerDenominator],
                [$repeatedRadicalRoots[0]->upperNumerator, $repeatedRadicalRoots[0]->upperDenominator],
            ))->containsRational('4', '1'),
        );
    }

    public function testTwoDistinctAdditiveSquareRootsAreExactlyIsolated(): void
    {
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('sqrt(x) + sqrt(x + 1) = 3', 'x', '-1', '3'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('sqrt(x) + sqrt(x + 1) = -1', 'x', '-1', '3'),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sqrt(x) + sqrt(x + 1) = 3',
            'x',
            '-1',
            '3',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue(
            (new ExactRationalInterval(
                [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
                [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
            ))->containsRational('16', '9'),
        );

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('abs(x) + sqrt(x + 1) = 2', 'x', '-2', '2'),
        );
    }

    public function testTwoDistinctSquareRootsWithOppositeSignsAreExactlyIsolated(): void
    {
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('sqrt(x + 2) - sqrt(x) = 1', 'x', '-1', '3'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('sqrt(x + 2) - sqrt(x) = -1', 'x', '-1', '3'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('sqrt(x) - sqrt(x + 2) = -1', 'x', '-1', '3'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x^2 + 1) - sqrt(2*x^2 + 1) = 0',
                'x',
                '-2',
                '2',
            ),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sqrt(x + 2) - sqrt(x) = 1',
            'x',
            '-1',
            '3',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue(
            (new ExactRationalInterval(
                [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
                [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
            ))->containsRational('1', '4'),
        );
    }

    public function testThreeAdditiveSquareRootsAreExactlyIsolatedBySignPartition(): void
    {
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x) + sqrt(x + 1) = sqrt(1) + 0',
                'x',
                '-1',
                '2',
            ),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x) + sqrt(1 - x) = sqrt(1) + 0',
                'x',
                '-1',
                '2',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x) + sqrt(x + 1) = sqrt(1/4) + 0',
                'x',
                '-1',
                '2',
            ),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sqrt(x) + sqrt(1 - x) = sqrt(1) + 0',
            'x',
            '-1',
            '2',
        );
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('0', $roots[0]->lowerNumerator);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame('1', $roots[1]->lowerNumerator);
    }

    public function testSameSignRadicalSumsAtZeroUseCommonRadicandZeros(): void
    {
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x^2) + sqrt((x - 1)^2) + sqrt((x + 1)^2) = 0',
                'x',
                '-2',
                '2',
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x^2) + sqrt(4*x^2) + abs(3*x) = 0',
                'x',
                '-2',
                '2',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt(x^2) + sqrt(x^2) = (x - x)/x',
                'x',
                '-1',
                '1',
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('-sqrt(x^2) - abs(x) = 0', 'x', '-1', '1'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt((x - 1)/(x + 1)) + sqrt((x - 1)/(x + 2)) = 0',
                'x',
                '-2',
                '2',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'sqrt((x - 1)/(x - 1)) + sqrt((x - 1)/(x - 1)) = 0',
                'x',
                '-2',
                '2',
            ),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sqrt(x^2) + sqrt(4*x^2) + abs(3*x) = 0',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('0', $roots[0]->lowerNumerator);
    }

    public function testIdenticallyZeroSameSignRadicalSumReportsInfiniteSolutions(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('sqrt(x - x) + sqrt(0) = 0', 'x', '-1', '1');
    }

    public function testAdditiveSingleAbsoluteValueTermsAreCollectedBeforeExactIsolation(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('abs(x) + x = 1', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('abs(x) - x = 1', 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('abs(x - 1) + x = 2', 'x', '-2', '3'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('2*abs(x) + x = 1', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('abs(x) + x = -1', 'x', '-2', '2'));

        $roots = Math::isolatePolynomialRootsInRationalInterval('abs(x) + x = 1', 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertSame('2', $roots[0]->lowerDenominator);
    }

    public function testAdditiveAbsoluteValueIdentityCanHaveInfiniteSolutions(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('abs(x) + x = 0', 'x', '-2', '2');
    }

    public function testMultipleAdditiveAbsoluteValuesAreCountedByCertifiedSignCells(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('abs(x) + abs(x - 2) = 4', 'x', '-5', '5'));
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('abs(x - 1) + abs(x + 1) = 1', 'x', '-5', '5'),
        );
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('abs(x) + abs(x - 2) = 0', 'x', '-5', '5'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('abs(x) + abs(2*x - 1) = 3', 'x', '-5', '5'));
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('abs(x) + abs(2*x - 1) = 1/2', 'x', '-1', '1'),
        );

        $roots = Math::isolatePolynomialRootsInRationalInterval('abs(x) + abs(x - 2) = 4', 'x', '-5', '5');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertTrue(
            (new ExactRationalInterval(
                [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
                [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
            ))->containsRational('-1', '1'),
        );
        self::assertTrue(
            (new ExactRationalInterval(
                [$roots[1]->lowerNumerator, $roots[1]->lowerDenominator],
                [$roots[1]->upperNumerator, $roots[1]->upperDenominator],
            ))->containsRational('3', '1'),
        );
    }

    public function testMultipleAbsoluteValueIdentityReportsInfiniteSolutionSet(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('abs(x) + abs(x - 2) = 2', 'x', '-5', '5');
    }

    public function testPolynomialSquareRootIdentitiesClassifyInfiniteAndEmptySolutionSets(): void
    {
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('sqrt(x^2) = -x^2 - 1', 'x', '-2', '2'),
        );
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('sqrt(x^2) = x', 'x', '-2', '2');
    }

    public function testAffinePeriodicRootsAreIsolatedInCertifiedRationalIntervals(): void
    {
        $sineRoots = Math::isolatePolynomialRootsInRationalInterval('sin(x) = 0', 'x', '-2', '2');
        self::assertNotNull($sineRoots);
        self::assertCount(1, $sineRoots);
        self::assertTrue($sineRoots[0]->isExactRoot);
        self::assertSame('0', $sineRoots[0]->lowerNumerator);

        $sincRoots = Math::isolatePolynomialRootsInRationalInterval('sinc(x) = 0', 'x', '-4', '4');
        self::assertNotNull($sincRoots);
        self::assertCount(2, $sincRoots);
        self::assertFalse($sincRoots[0]->isExactRoot);
        self::assertFalse($sincRoots[1]->isExactRoot);
        $negativeRootMidpoint = (
            $this->rationalFractionToFloat($sincRoots[0]->lowerNumerator, $sincRoots[0]->lowerDenominator)
            + $this->rationalFractionToFloat($sincRoots[0]->upperNumerator, $sincRoots[0]->upperDenominator)
        ) / 2.0;
        $positiveRootMidpoint = (
            $this->rationalFractionToFloat($sincRoots[1]->lowerNumerator, $sincRoots[1]->lowerDenominator)
            + $this->rationalFractionToFloat($sincRoots[1]->upperNumerator, $sincRoots[1]->upperDenominator)
        ) / 2.0;
        self::assertEqualsWithDelta(-\M_PI, $negativeRootMidpoint, 1.0E-12);
        self::assertEqualsWithDelta(\M_PI, $positiveRootMidpoint, 1.0E-12);

        $sincPiRoots = Math::isolatePolynomialRootsInRationalInterval('sincpi(x) = 0', 'x', '-2', '2');
        self::assertNotNull($sincPiRoots);
        self::assertCount(2, $sincPiRoots);
        self::assertTrue($sincPiRoots[0]->isExactRoot);
        self::assertTrue($sincPiRoots[1]->isExactRoot);

        $halfLevelRoots = Math::isolatePolynomialRootsInRationalInterval('sin(x) = 1/2', 'x', '0', '3');
        self::assertNotNull($halfLevelRoots);
        self::assertCount(2, $halfLevelRoots);
        foreach ($halfLevelRoots as $index => $root) {
            self::assertFalse($root->isExactRoot);
            $midpoint = (
                $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
            ) / 2.0;
            self::assertEqualsWithDelta(
                $index === 0 ? \M_PI / 6.0 : (5.0 * \M_PI) / 6.0,
                $midpoint,
                1.0E-12,
            );
        }

        $reciprocalHalfLevelRoots = Math::isolatePolynomialRootsInRationalInterval(
            'sec(x) = -2',
            'x',
            '2',
            '3',
        );
        self::assertNotNull($reciprocalHalfLevelRoots);
        self::assertCount(1, $reciprocalHalfLevelRoots);
        self::assertEqualsWithDelta(
            2.0 * \M_PI / 3.0,
            (
                $this->rationalFractionToFloat(
                    $reciprocalHalfLevelRoots[0]->lowerNumerator,
                    $reciprocalHalfLevelRoots[0]->lowerDenominator,
                )
                + $this->rationalFractionToFloat(
                    $reciprocalHalfLevelRoots[0]->upperNumerator,
                    $reciprocalHalfLevelRoots[0]->upperDenominator,
                )
            ) / 2.0,
            1.0E-12,
        );

        $negativeSlopeRoots = Math::isolatePolynomialRootsInRationalInterval(
            'sin(-2*x) = 0',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($negativeSlopeRoots);
        self::assertCount(3, $negativeSlopeRoots);
        self::assertLessThan(
            $this->rationalFractionToFloat(
                $negativeSlopeRoots[1]->lowerNumerator,
                $negativeSlopeRoots[1]->lowerDenominator,
            ),
            $this->rationalFractionToFloat(
                $negativeSlopeRoots[0]->upperNumerator,
                $negativeSlopeRoots[0]->upperDenominator,
            ),
        );
    }

    public function testLargeRationalFractionsConvertWithoutOverflowing(): void
    {
        $scale = str_repeat('0', 400);

        self::assertEqualsWithDelta(
            3.14,
            $this->rationalFractionToFloat('314' . $scale, '100' . $scale),
            1.0E-15,
        );
    }

    public function testPeriodicRootCountingDoesNotDependOnRequestedIsolationWidth(): void
    {
        $smallSlopeDenominator = '1' . \str_repeat('0', 54);
        $piApproximation = '3.141592653589793238462643383279502884197169399375105820974944592307816406'
            . '2862089986280348253421170679';
        $equation = 'sin(x/' . $smallSlopeDenominator . ' - ' . $piApproximation . ') = 0';

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        $defaultBracket = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
        self::assertNotNull($defaultBracket);
        self::assertCount(1, $defaultBracket);
        $defaultLower = (float) $defaultBracket[0]->lowerNumerator / (float) $defaultBracket[0]->lowerDenominator;
        $defaultUpper = (float) $defaultBracket[0]->upperNumerator / (float) $defaultBracket[0]->upperDenominator;
        self::assertLessThanOrEqual(1.0E-6, $defaultUpper - $defaultLower);

        $wideBracket = Math::isolatePolynomialRootsInRationalInterval(
            $equation,
            'x',
            '-1',
            '1',
            maximumWidth: '1',
        );
        self::assertNotNull($wideBracket);
        self::assertCount(1, $wideBracket);
    }

    public function testAffineTrigonometricIsolationRefinesToRequestedNarrowWidth(): void
    {
        $maximumWidthDenominator = '1' . \str_repeat('0', 30);
        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sin(x) = 1/3',
            'x',
            '0',
            '2',
            maximumWidth: '1/' . $maximumWidthDenominator,
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        $root = $roots[0];
        $arithmetic = new IntegerPolynomialExtractor('', []);
        $widthNumerator = $arithmetic->subtract(
            $arithmetic->multiplyIntegerValues($root->upperNumerator, $root->lowerDenominator),
            $arithmetic->multiplyIntegerValues($root->lowerNumerator, $root->upperDenominator),
        );
        $widthDenominator = $arithmetic->multiplyIntegerValues(
            $root->upperDenominator,
            $root->lowerDenominator,
        );

        self::assertLessThanOrEqual(
            0,
            $arithmetic->compareIntegerValues(
                $arithmetic->multiplyIntegerValues($widthNumerator, $maximumWidthDenominator),
                $widthDenominator,
            ),
        );
    }

    public function testNestedRadicalsInvertExactlyForRationalTargets(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(cbrt(x)) = 2', 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine('cbrt(cbrt(x)) = 2', 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('512', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[0]->lowerDenominator);
        self::assertTrue($roots[0]->isExactRoot);

        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cbrt(cbrt(x^2 - 1)) = 2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'cbrt(cbrt((x^2 - 1)/(x - 1))) = 0',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(cbrt(x)) = -1/2', 'x'));

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sqrt(sqrt(x)) = 2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sqrt(sqrt(x^2 - 1)) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sqrt(sqrt(x)) = -2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'sqrt(sqrt((x^2 - 1)/(x - 1))) = 0',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sqrt(cbrt(x)) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(sqrt(x)) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(sqrt(cbrt(x))) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cbrt(sqrt(x)) = -2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'sqrt(cbrt((x^2 - 1)/(x - 1))) = 0',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sqrt(root(x, 3)) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(sqrt(x), 3) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(root(x, 3), 5) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(root(x, -3), 5) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(root(x, -3), -5) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(root(x, -2), 3) = 2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'root(root(x, 3), 5) = target',
                'x',
                ['target' => 2],
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'root(root(x, 3), 5) = target',
                'x',
                ['target' => 1.5],
            ),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'root(root(x, degree), 5) = 2',
                'x',
                ['degree' => 3],
            ),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('root(sqrt(x), 3) = -2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('root(root(x, -3), 5) = 0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('root(root(x, -2), 3) = -2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^(1/3))^(1/2) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^(1/2))^(1/3) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^(-1/3))^(1/2) = 2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^(-1/2))^(1/3) = 2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x^(1/2))^(1/3) = -2', 'x'));
        $nestedOddRootEquation = 'cbrt(cbrt(x^2 - 1)) = root(x + 1, 9)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($nestedOddRootEquation, 'x'));
        $nestedOddRootIntervals = Math::isolatePolynomialRootsOnRealLine($nestedOddRootEquation, 'x');
        self::assertNotNull($nestedOddRootIntervals);
        self::assertCount(2, $nestedOddRootIntervals);
        self::assertTrue($nestedOddRootIntervals[0]->isExactRoot);
        self::assertSame('-1', $nestedOddRootIntervals[0]->lowerNumerator);
        self::assertTrue($nestedOddRootIntervals[1]->isExactRoot);
        self::assertSame('2', $nestedOddRootIntervals[1]->lowerNumerator);
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'cbrt(cbrt((x^2 - 1)/(x - 1))) = root(2*x, 9)',
                'x',
            ),
        );
    }

    public function testExactRootCountingSupportsDirectRationalFractionalPowers(): void
    {
        foreach (
            [
                'x^(1/2)=2' => ['4', '1'],
                'x^(1/3)=-2' => ['-8', '1'],
                'x^(-1/2)=2' => ['1', '4'],
                'x^(-1/3)=-2' => ['-1', '8'],
                'x^(1/3)=1/3' => ['1', '27'],
                'x^(3/2)=8' => ['4', '1'],
                '(2*x+1)^(3/2)=8' => ['3', '2'],
                'x^(-3/2)=1/8' => ['4', '1'],
                'x^(3/5)=-8' => ['-32', '1'],
                '2=x^(1/2)' => ['4', '1'],
                '(2*x+1)^(1/2)=3' => ['4', '1'],
                '(2*x-1)^(1/3)=-2' => ['-7', '2'],
                '(2*x+1)^(-1/2)=1/3' => ['4', '1'],
                '(-2*x+1)^(1/3)=2' => ['-7', '2'],
                '((x+1)/2)^(1/2)=3' => ['17', '1'],
            ] as $equation => [$numerator, $denominator]
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRealRoots);
            self::assertCount(1, $allRealRoots);
            self::assertSame($numerator, $allRealRoots[0]->lowerNumerator);
            self::assertSame($denominator, $allRealRoots[0]->lowerDenominator);
            self::assertTrue($allRealRoots[0]->isExactRoot);
        }

        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('x^(1/2)=-2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(1/2)=-2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine('root(x, degree)=2', 'x', ['degree' => 3.0000000000000004]),
        );
        self::assertNull(Math::countPolynomialRootsOnRealLine('root(x, degree)=2', 'x', ['degree' => 3.1]));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(3/2)=-8', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(2/3)=4', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine(
                'x^(2/3)=4',
                'x',
                solverOptions: new SolverOptions(maxPolynomialDegree: 1),
            ),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(2/3)=-4', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(2/3)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x^2)^(2/3)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-2/3)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(4/3)=2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(3/2)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(3/2)=-2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(-3/2)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(-3/2)=-2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(2/3)=0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(-2/3)=0', 'x'));
        $irrationalInverseRoots = Math::isolatePolynomialRootsOnRealLine('x^(2/3)=2', 'x');
        self::assertNotNull($irrationalInverseRoots);
        self::assertCount(2, $irrationalInverseRoots);
        self::assertFalse($irrationalInverseRoots[0]->isExactRoot);
        self::assertFalse($irrationalInverseRoots[1]->isExactRoot);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('x^(2/3)=2', 'x', '0', '3'),
        );
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-2/3)=1/4', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x^2)^(1/3)=4', 'x'));
        $quadraticBaseRoots = Math::isolatePolynomialRootsOnRealLine('(x^2)^(1/3)=4', 'x');
        self::assertNotNull($quadraticBaseRoots);
        self::assertCount(2, $quadraticBaseRoots);
        self::assertSame('-8', $quadraticBaseRoots[0]->lowerNumerator);
        self::assertSame('8', $quadraticBaseRoots[1]->lowerNumerator);
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x^2-1)^(2/3)=4', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x^2)^(1/3)=2', 'x'));
        $irrationalQuadraticRoots = Math::isolatePolynomialRootsOnRealLine('(x^2)^(1/3)=2', 'x');
        self::assertNotNull($irrationalQuadraticRoots);
        self::assertCount(2, $irrationalQuadraticRoots);
        self::assertFalse($irrationalQuadraticRoots[0]->isExactRoot);
        self::assertFalse($irrationalQuadraticRoots[1]->isExactRoot);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x^2+1)^(1/3)=0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^3)^(1/3)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x^3)^(2/3)=4', 'x'));
        $cubicBranches = Math::isolatePolynomialRootsOnRealLine('(x^3)^(2/3)=4', 'x');
        self::assertNotNull($cubicBranches);
        self::assertCount(2, $cubicBranches);
        self::assertSame('-2', $cubicBranches[0]->lowerNumerator);
        self::assertSame('2', $cubicBranches[1]->lowerNumerator);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x^3+x)^(1/3)=2', 'x'));
        $irrationalCubic = Math::isolatePolynomialRootsOnRealLine('(x^3+x)^(1/3)=2', 'x');
        self::assertNotNull($irrationalCubic);
        self::assertCount(1, $irrationalCubic);
        self::assertFalse($irrationalCubic[0]->isExactRoot);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('(x^3+x)^(1/3)=2', 'x', '0', '3'),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x^3+x,3)=2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(x^3)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sqrt(x^2)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sqrt(x^2)=-2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x^3+x,-3)=1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('-sqrt(x^2)=-2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('-sqrt(x^2)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('-(x^(2/3))=-4', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine('x^exponent=4', 'x', ['exponent' => 0.5]),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine('root(x, degree)=2', 'x', ['degree' => 3]),
        );
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x/(x^2+1))^(1/2)=1/2', 'x'));
        $rationalBaseRoots = Math::isolatePolynomialRootsOnRealLine('(x/(x^2+1))^(1/2)=1/2', 'x');
        self::assertNotNull($rationalBaseRoots);
        self::assertCount(2, $rationalBaseRoots);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x/x)^(1/2)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('(x/x)^(1/2)=2', 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('((x-1)/(x-1))^(1/2)=2', 'x'));
        self::assertSame(4, Math::countPolynomialRootsOnRealLine('(x/(x^2+1))^(2/3)=2/5', 'x'));
        $irrationalRationalFunctionRoots = Math::isolatePolynomialRootsOnRealLine(
            '(x/(x^2+1))^(2/3)=2/5',
            'x',
        );
        self::assertNotNull($irrationalRationalFunctionRoots);
        self::assertCount(4, $irrationalRationalFunctionRoots);
        self::assertSame(4, Math::countPolynomialRootsOnRealLine('(x/(x^2+1))^(-2/3)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x/x)^(2/3)=2', 'x'));
        $twoBranches = Math::isolatePolynomialRootsOnRealLine('(2*x+1)^(2/3)=4', 'x');
        self::assertNotNull($twoBranches);
        self::assertCount(2, $twoBranches);
        self::assertSame('-9', $twoBranches[0]->lowerNumerator);
        self::assertSame('2', $twoBranches[0]->lowerDenominator);
        self::assertSame('7', $twoBranches[1]->lowerNumerator);
        self::assertSame('2', $twoBranches[1]->lowerDenominator);
        $oneFiniteBranch = Math::isolatePolynomialRootsInRationalInterval(
            '(2*x+1)^(2/3)=4',
            'x',
            '0',
            '5',
        );
        self::assertNotNull($oneFiniteBranch);
        self::assertCount(1, $oneFiniteBranch);
        self::assertSame('7', $oneFiniteBranch[0]->lowerNumerator);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(2*x+1)^(1/2)=1+2', 'x'));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine('x^(1/2)=radius', 'x', ['radius' => 3]),
        );
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('x^(1/2)=2', 'x', '3', '5'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('x^(1/2)=2', 'x', '4', '5'));
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval('x^(1/2)=2', 'x', '4', '5'),
        );
        $finiteRoots = Math::isolatePolynomialRootsInRationalInterval('x^(1/2)=2', 'x', '3', '5');
        self::assertNotNull($finiteRoots);
        self::assertCount(1, $finiteRoots);
        self::assertSame('4', $finiteRoots[0]->lowerNumerator);
        self::assertTrue($finiteRoots[0]->isExactRoot);
    }

    public function testFractionalPowerIsolationStillValidatesDepthLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Math::isolatePolynomialRootsOnRealLine('x^(1/2)=2', 'x', maxDepth: 0);
    }

    public function testFractionalPowerRationalIdentityReportsInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('(x/x)^(1/2)=1', 'x');
    }

    public function testCountsDistinctRootsInOpenRationalIntervals(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['0', '-1', '0', '1'];

        self::assertSame(1, $counter->countOpenInterval($coefficients, '0', '1', '2', '1'));
        self::assertSame(1, $counter->countOpenInterval($coefficients, '-2', '1', '0', '1'));
        self::assertSame(1, $counter->countOpenInterval($coefficients, '-1', '1', '1', '1'));
        self::assertSame(0, $counter->countOpenInterval($coefficients, '1', '1', '2', '1'));
    }

    public function testRootBracketsExcludeOtherRootsAtOpenIntervalEndpoints(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $lowerEndpointRoot = $counter->isolateOpenInterval(
            ['0', '-2', '0', '1'],
            '0',
            '1',
            '2',
            '1',
            maximumWidth: ['2', '1'],
        );
        $upperEndpointRoot = $counter->isolateOpenInterval(
            ['4', '-2', '-2', '1'],
            '0',
            '1',
            '2',
            '1',
            maximumWidth: ['2', '1'],
        );

        self::assertNotNull($lowerEndpointRoot);
        self::assertNotNull($upperEndpointRoot);
        self::assertSame([['1', '1', '2', '1']], $lowerEndpointRoot);
        self::assertSame([['1', '1', '3', '2']], $upperEndpointRoot);
        self::assertSame(
            1,
            $counter->countOpenInterval(['0', '-2', '0', '1'], ...$lowerEndpointRoot[0]),
        );
        self::assertTrue($counter->isRootAt(['0', '-2', '0', '1'], ['0', '1']));
        self::assertSame(
            1,
            $counter->countOpenInterval(['4', '-2', '-2', '1'], ...$upperEndpointRoot[0]),
        );
        self::assertTrue($counter->isRootAt(['4', '-2', '-2', '1'], ['2', '1']));
    }

    public function testRationallyExtractedRootsAreExcludedFromOtherBrackets(): void
    {
        $coefficients = ['6', '-4', '-5', '2', '1'];
        $intervals = (new ExactPolynomialRootCounter())->isolateOpenInterval(
            $coefficients,
            '0',
            '1',
            '2',
            '1',
            maximumWidth: ['2', '1'],
        );

        self::assertSame(
            [
                ['1', '1', '1', '1'],
                ['5', '4', '3', '2'],
            ],
            $intervals,
        );
    }

    public function testCountsDistinctRootsAcrossTheEntireRealLine(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(3, $counter->countAllRealRoots(['0', '-1', '0', '1']));
        self::assertSame(2, $counter->countAllRealRoots(['1', '-1', '-1', '1']));
        self::assertSame(0, $counter->countAllRealRoots(['1', '0', '1']));
        self::assertSame(0, $counter->countAllRealRoots(['7']));
        self::assertNull($counter->countAllRealRoots(['0']));
    }

    public function testAllRealAndFiniteSturmCountsAgreeForBoundedRootSets(): void
    {
        $counter = new ExactPolynomialRootCounter();
        foreach (
            [
                ['0', '-1', '0', '1'],
                ['1', '-4', '6', '-4', '1'],
                ['4', '0', '-4', '0', '1'],
                ['1', '0', '1'],
                ['-6', '11', '-6', '1'],
            ] as $coefficients
        ) {
            self::assertSame(
                $counter->countAllRealRoots($coefficients),
                $counter->countOpenInterval($coefficients, '-10', '1', '10', '1'),
                implode(',', $coefficients),
            );
        }
    }

    public function testRationalRootsAreExtractedExactlyBeforeSubdivision(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(
            [
                ['-1', '1', '-1', '1'],
                ['1', '1', '1', '1'],
                ['2', '1', '2', '1'],
            ],
            $counter->isolateAllRealRoots(['2', '-1', '-2', '1'], maxDepth: 1),
        );
        self::assertSame(
            [['2', '1', '2', '1']],
            $counter->isolateAllRealRoots(['-8', '12', '-6', '1'], maxDepth: 1),
        );
        self::assertSame(
            [['1', '1', '1', '1']],
            $counter->isolateOpenInterval(['2', '-1', '-2', '1'], '0', '1', '3', '2', maxDepth: 1),
        );
        self::assertSame(
            [],
            $counter->isolateOpenInterval(['2', '-1', '-2', '1'], '1', '1', '2', '1', maxDepth: 1),
        );
        self::assertSame(
            [['2', '1', '2', '1']],
            $counter->isolateOpenInterval(['-8', '12', '-6', '1'], '1', '1', '3', '1', maxDepth: 1),
        );
        $rationalRoots = ['6', '-13', '-10', '24'];
        self::assertSame(
            [
                ['-3', '4', '-3', '4'],
                ['1', '2', '1', '2'],
                ['2', '3', '2', '3'],
            ],
            $counter->isolateAllRealRoots($rationalRoots, maxDepth: 1),
        );
        self::assertSame(
            [['1', '2', '1', '2']],
            $counter->isolateOpenInterval($rationalRoots, '0', '1', '3', '5', maxDepth: 1),
        );
        self::assertSame(
            [['1', '2', '1', '2']],
            $counter->isolateAllRealRoots(['-1', '6', '-12', '8'], maxDepth: 1),
        );
        $scale = '1' . \str_repeat('0', 100);
        $arithmetic = new \MathPHP\Solver\IntegerPolynomialExtractor('', []);
        $largeCoefficients = \array_map(
            fn (string $coefficient): string => $arithmetic->multiplyIntegerValues($coefficient, $scale),
            $rationalRoots,
        );
        self::assertSame(
            [
                ['-3', '4', '-3', '4'],
                ['1', '2', '1', '2'],
                ['2', '3', '2', '3'],
            ],
            $counter->isolateAllRealRoots($largeCoefficients, maxDepth: 1),
        );
    }

    public function testLargePerfectPowerBinomialRootsAreIsolatedWithoutSubdivision(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $largeCube = '1' . str_repeat('0', 300);
        $largeSquare = '1' . str_repeat('0', 200);

        self::assertSame(
            [['1' . str_repeat('0', 100), '1', '1' . str_repeat('0', 100), '1']],
            $counter->isolateAllRealRoots(['-' . $largeCube, '0', '0', '1'], maxDepth: 1),
        );
        self::assertSame(
            [['-' . '1' . str_repeat('0', 100), '1', '-' . '1' . str_repeat('0', 100), '1']],
            $counter->isolateAllRealRoots([$largeCube, '0', '0', '1'], maxDepth: 1),
        );
        self::assertSame(
            [
                ['-' . '1' . str_repeat('0', 50), '1', '-' . '1' . str_repeat('0', 50), '1'],
                ['1' . str_repeat('0', 50), '1', '1' . str_repeat('0', 50), '1'],
            ],
            $counter->isolateAllRealRoots(['-' . $largeSquare, '0', '0', '0', '1'], maxDepth: 1),
        );
        self::assertNull(
            $counter->isolateAllRealRoots(
                ['-2', '0', '0', '1'],
                maxDepth: 1,
                maximumWidth: ['1', '1000'],
            ),
        );
    }

    public function testAllRealIsolationUsesATightBoundForLargeNonRationalRoots(): void
    {
        $largeConstant = '1' . str_repeat('0', 90);
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $constant = $integerArithmetic->multiplyIntegerValues(
            '2',
            $integerArithmetic->addIntegerValues($largeConstant, '1'),
        );
        $coefficients = ['-' . $constant, '0', '0', '3'];
        $intervals = (new ExactPolynomialRootCounter())->isolateAllRealRoots(
            $coefficients,
            maxDepth: 120,
            maximumWidth: ['1', '1'],
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertFalse($intervals[0][0] === $intervals[0][2]);
        self::assertTrue(
            (new ExactPolynomialRootCounter())->countOpenInterval(
                $coefficients,
                ...$intervals[0],
            ) === 1,
        );
    }

    public function testLargeRationalFactorsAreRecoveredFromExactCoefficientRatios(): void
    {
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $root = '1000000007';
        $quadraticConstant = '1000000009';
        $constant = $integerArithmetic->multiplyIntegerValues($root, $quadraticConstant);
        $coefficients = ['-' . $constant, $quadraticConstant, '-' . $root, '1'];

        self::assertSame(
            [[$root, '1', $root, '1']],
            (new ExactPolynomialRootCounter())->isolateAllRealRoots(
                $coefficients,
                maxDepth: 1,
                maximumWidth: ['1', '1000'],
            ),
        );
    }

    public function testLargeIntegerRootNearTheVietaSumIsRecoveredExactly(): void
    {
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $root = '1000000007';
        $quadraticConstant = '1000000009';
        $constant = $integerArithmetic->multiplyIntegerValues($root, $quadraticConstant);
        $linear = $integerArithmetic->subtract($quadraticConstant, $root);
        $quadratic = $integerArithmetic->subtract($root, '1');
        $coefficients = ['-' . $constant, $linear, '-' . $quadratic, '1'];

        self::assertSame(
            [[$root, '1', $root, '1']],
            (new ExactPolynomialRootCounter())->isolateAllRealRoots(
                $coefficients,
                maxDepth: 1,
                maximumWidth: ['1', '1000'],
            ),
        );
    }

    public function testRepeatedRationalFactorIsCombinedWithIrrationalRoots(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $arithmetic = new \MathPHP\Solver\IntegerPolynomialExtractor('', []);
        $coefficients = ['-2', '8', '-7', '-4', '4'];
        $intervals = $counter->isolateAllRealRoots(
            $coefficients,
            maximumWidth: ['1', '1000'],
        );

        self::assertNotNull($intervals);
        self::assertCount(3, $intervals);
        foreach ($intervals as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            if ($lowerNumerator === $upperNumerator && $lowerDenominator === $upperDenominator) {
                self::assertSame(['1', '2'], [$lowerNumerator, $lowerDenominator]);
                self::assertTrue($counter->isRootAt($coefficients, [$lowerNumerator, $lowerDenominator]));
                continue;
            }
            self::assertSame(
                1,
                $counter->countOpenInterval(
                    $coefficients,
                    $lowerNumerator,
                    $lowerDenominator,
                    $upperNumerator,
                    $upperDenominator,
                ),
            );
            $widthNumerator = $arithmetic->subtract(
                $arithmetic->multiplyIntegerValues($upperNumerator, $lowerDenominator),
                $arithmetic->multiplyIntegerValues($lowerNumerator, $upperDenominator),
            );
            $widthDenominator = $arithmetic->multiplyIntegerValues($upperDenominator, $lowerDenominator);
            self::assertLessThanOrEqual(
                0,
                $arithmetic->compareIntegerValues(
                    $arithmetic->multiplyIntegerValues($widthNumerator, '1000'),
                    $widthDenominator,
                ),
            );
        }
    }

    public function testLargeCoefficientQuarticWithoutModularRootsSkipsRationalCandidateSearch(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = [
            '1000000000000004000000000000003',
            '-4000000000000012000000000000008',
            '6000000000000012000000000000004',
            '-4000000000000004000000000000000',
            '1000000000000000000000000000000',
        ];

        self::assertSame(
            [
                ['0', '1', '1', '1'],
                ['1', '1', '2', '1'],
            ],
            $counter->isolateOpenInterval(
                $coefficients,
                '0',
                '1',
                '2',
                '1',
                maxDepth: 2,
            ),
        );
    }

    public function testRationalRootDenominatorDivisibleByFilterPrimeIsRetained(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(
            [['1', '103', '1', '103']],
            $counter->isolateOpenInterval(
                ['-6', '620', '-203', '-310', '103'],
                '0',
                '1',
                '1',
                '50',
            ),
        );
    }

    public function testCountsCommonRootsThroughExactPolynomialGcd(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(1, $counter->countCommonRealRoots(['-1', '0', '1'], ['-1', '1']));
        self::assertSame(2, $counter->countCommonRealRoots(['-2', '0', '1'], ['-2', '0', '1']));
        self::assertSame(0, $counter->countCommonRealRoots(['1', '0', '1'], ['-1', '1']));
        self::assertSame(2, $counter->countCommonRealRoots(['0'], ['-1', '0', '1']));
        self::assertSame(2, $counter->countCommonRealRoots(['-1', '0', '1'], ['0']));
        self::assertSame(['-1', '0', '1'], $counter->greatestCommonDivisor(['0'], ['-1', '0', '1']));
        self::assertNull($counter->countCommonRealRoots(['0'], ['0']));
    }

    public function testIsolatesAllRealRootsUsingAnExactGlobalBound(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['2', '0', '-1'];
        $intervals = $counter->isolateAllRealRoots(
            $coefficients,
            maximumWidth: ['1', '1000'],
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        foreach ($intervals as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            self::assertSame(
                1,
                $counter->countOpenInterval(
                    $coefficients,
                    $lowerNumerator,
                    $lowerDenominator,
                    $upperNumerator,
                    $upperDenominator,
                ),
            );
            $lower = (float) $lowerNumerator / (float) $lowerDenominator;
            $upper = (float) $upperNumerator / (float) $upperDenominator;
            $root = ($lower + $upper) / 2.0 < 0.0 ? -\sqrt(2.0) : \sqrt(2.0);
            self::assertGreaterThanOrEqual($lower, $root);
            self::assertLessThanOrEqual($upper, $root);
        }
    }

    public function testSturmIsolationIsInvariantUnderLargePositiveCoefficientScale(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['6', '0', '-5', '0', '1'];
        $arithmetic = new \MathPHP\Solver\IntegerPolynomialExtractor('', []);
        $scale = '1' . \str_repeat('0', 300);
        $scaledCoefficients = \array_map(
            static fn (string $coefficient): string => $arithmetic->multiplyIntegerValues($coefficient, $scale),
            $coefficients,
        );

        self::assertSame(4, $counter->countAllRealRoots($scaledCoefficients));
        self::assertSame(
            $counter->isolateAllRealRoots($coefficients, maximumWidth: ['1', '1000']),
            $counter->isolateAllRealRoots($scaledCoefficients, maximumWidth: ['1', '1000']),
        );
    }

    public function testParityPolynomialIsolationMapsSquaredRootsWithCertifiedBounds(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $negativeLevelIsDiscarded = $counter->isolateAllRealRoots(
            ['-2', '0', '-1', '0', '1'],
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($negativeLevelIsDiscarded);
        self::assertCount(2, $negativeLevelIsDiscarded);
        foreach ($negativeLevelIsDiscarded as $interval) {
            self::assertSame(1, $counter->countOpenInterval(['-2', '0', '-1', '0', '1'], ...$interval));
            self::assertLessThanOrEqual(0.001, (float) $interval[2] / (float) $interval[3]
                - ((float) $interval[0] / (float) $interval[1]));
        }

        $zeroAndIrrationalLevels = $counter->isolateAllRealRoots(
            ['0', '0', '-2', '0', '1'],
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($zeroAndIrrationalLevels);
        self::assertCount(3, $zeroAndIrrationalLevels);
        self::assertSame(['0', '1', '0', '1'], $zeroAndIrrationalLevels[1]);
        self::assertSame(1, $counter->countOpenInterval(
            ['0', '0', '-2', '0', '1'],
            $zeroAndIrrationalLevels[0][0],
            $zeroAndIrrationalLevels[0][1],
            $zeroAndIrrationalLevels[0][2],
            $zeroAndIrrationalLevels[0][3],
        ));
        self::assertSame(1, $counter->countOpenInterval(
            ['0', '0', '-2', '0', '1'],
            $zeroAndIrrationalLevels[2][0],
            $zeroAndIrrationalLevels[2][1],
            $zeroAndIrrationalLevels[2][2],
            $zeroAndIrrationalLevels[2][3],
        ));

        $oddPolynomial = $counter->isolateAllRealRoots(
            ['0', '-2', '0', '-1', '0', '1'],
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($oddPolynomial);
        self::assertCount(3, $oddPolynomial);
        self::assertSame(['0', '1', '0', '1'], $oddPolynomial[1]);
        foreach ([0, 2] as $index) {
            self::assertSame(1, $counter->countOpenInterval(
                ['0', '-2', '0', '-1', '0', '1'],
                $oddPolynomial[$index][0],
                $oddPolynomial[$index][1],
                $oddPolynomial[$index][2],
                $oddPolynomial[$index][3],
            ));
        }
    }

    public function testParityPolynomialIsolationRestrictsCertifiedRootsToOpenInterval(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['0', '0', '2', '0', '-3', '0', '1'];
        $roots = $counter->isolateOpenInterval(
            $coefficients,
            '-3',
            '2',
            '0',
            '1',
            maximumWidth: ['1', '1000'],
        );

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame(2, $counter->countOpenInterval($coefficients, '-3', '2', '0', '1'));
        $expectedRoots = [-\sqrt(2.0), -1.0];
        foreach ($roots as $index => $root) {
            self::assertGreaterThanOrEqual((float) $root[0] / (float) $root[1], $expectedRoots[$index]);
            self::assertLessThanOrEqual((float) $root[2] / (float) $root[3], $expectedRoots[$index]);
            self::assertLessThanOrEqual(
                0.001,
                (float) $root[2] / (float) $root[3] - (float) $root[0] / (float) $root[1],
            );
        }

        $openAtRationalRoots = $counter->isolateOpenInterval(
            $coefficients,
            '-1',
            '1',
            '0',
            '1',
        );
        self::assertSame([], $openAtRationalRoots);
    }

    public function testRationallyCenteredParityPolynomialsMapBackToOriginalRoots(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $evenCoefficients = ['62', '-96', '52', '-12', '1'];
        $centeredEvenRoots = $counter->isolateAllRealRoots(
            $evenCoefficients,
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($centeredEvenRoots);
        self::assertCount(2, $centeredEvenRoots);
        $expectedEvenRoots = [3 - \sqrt(1 + \sqrt(2)), 3 + \sqrt(1 + \sqrt(2))];
        foreach ($expectedEvenRoots as $index => $expectedRoot) {
            [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator] = $centeredEvenRoots[$index];
            $lower = (float) $lowerNumerator / (float) $lowerDenominator;
            $upper = (float) $upperNumerator / (float) $upperDenominator;
            self::assertGreaterThan($expectedRoot - 0.001, $lower);
            self::assertLessThan($expectedRoot + 0.001, $upper);
            self::assertSame(1, $counter->countOpenInterval(
                $evenCoefficients,
                $centeredEvenRoots[$index][0],
                $centeredEvenRoots[$index][1],
                $centeredEvenRoots[$index][2],
                $centeredEvenRoots[$index][3],
            ));
        }

        $fractionalCenterCoefficients = ['-7', '-120', '184', '-96', '16'];
        $fractionalCenterRoots = $counter->isolateAllRealRoots(
            $fractionalCenterCoefficients,
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($fractionalCenterRoots);
        self::assertCount(2, $fractionalCenterRoots);
        foreach ([1.5 - \sqrt(1 + \sqrt(2)), 1.5 + \sqrt(1 + \sqrt(2))] as $index => $expectedRoot) {
            [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator] = $fractionalCenterRoots[$index];
            $lower = (float) $lowerNumerator / (float) $lowerDenominator;
            $upper = (float) $upperNumerator / (float) $upperDenominator;
            self::assertGreaterThan($expectedRoot - 0.001, $lower);
            self::assertLessThan($expectedRoot + 0.001, $upper);
        }

        $oddCoefficients = ['-210', '376', '-261', '89', '-15', '1'];
        $centeredOddRoots = $counter->isolateAllRealRoots(
            $oddCoefficients,
            maximumWidth: ['1', '1000'],
        );
        self::assertNotNull($centeredOddRoots);
        self::assertCount(3, $centeredOddRoots);
        foreach ([3 - \sqrt(2), 3.0, 3 + \sqrt(2)] as $index => $expectedRoot) {
            [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator] = $centeredOddRoots[$index];
            $lower = (float) $lowerNumerator / (float) $lowerDenominator;
            $upper = (float) $upperNumerator / (float) $upperDenominator;
            self::assertGreaterThan($expectedRoot - 0.001, $lower);
            self::assertLessThan($expectedRoot + 0.001, $upper);
        }
        self::assertSame(['3', '1', '3', '1'], $centeredOddRoots[1]);
    }

    public function testLinearRootIsolationReturnsExactLargeRootsWithoutSubdivision(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $rootNumerator = '1' . \str_repeat('0', 100);
        $intervals = $counter->isolateAllRealRoots(
            ['-' . $rootNumerator, '1'],
            maxDepth: 1,
            maximumWidth: ['1', '1000000'],
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertSame($rootNumerator, $intervals[0][0]);
        self::assertSame('1', $intervals[0][1]);
        self::assertSame($intervals[0][0], $intervals[0][2]);
        self::assertSame($intervals[0][1], $intervals[0][3]);
    }

    public function testQuadraticRationalRootsAtWidelySeparatedScalesAreExact(): void
    {
        $scale = '1' . \str_repeat('0', 100);
        $scaleSquared = '1' . \str_repeat('0', 200);
        $linearMagnitude = (new \MathPHP\Solver\IntegerPolynomialExtractor('', []))
            ->addIntegerValues($scaleSquared, '1');
        $counter = new ExactPolynomialRootCounter();
        $intervals = $counter->isolateAllRealRoots(
            [$scale, '-' . $linearMagnitude, $scale],
            maxDepth: 1,
        );

        self::assertSame(
            [
                ['1', $scale, '1', $scale],
                [$scale, '1', $scale, '1'],
            ],
            $intervals,
        );
        $largeScale = '1' . \str_repeat('0', 1024);
        $largeScaleSquared = '1' . \str_repeat('0', 2048);
        $largeLinearMagnitude = (new \MathPHP\Solver\IntegerPolynomialExtractor('', []))
            ->addIntegerValues($largeScaleSquared, '1');
        self::assertSame(
            [
                ['1', $largeScale, '1', $largeScale],
                [$largeScale, '1', $largeScale, '1'],
            ],
            $counter->isolateAllRealRoots(
                [$largeScale, '-' . $largeLinearMagnitude, $largeScale],
                maxDepth: 1,
            ),
        );
        self::assertSame(
            [['1', $largeScale, '1', $largeScale]],
            $counter->isolateOpenInterval(
                [$largeScale, '-' . $largeLinearMagnitude, $largeScale],
                '0',
                '1',
                '2',
                $largeScale,
                maxDepth: 1,
                maximumWidth: ['1', $largeScale],
            ),
        );
        self::assertSame([], $counter->isolateAllRealRoots(['1' . \str_repeat('0', 200), '0', '1']));
        self::assertSame(
            [['1', '1', '1', '1']],
            $counter->isolateAllRealRoots(['1', '-2', '1']),
        );
        self::assertNull($counter->isolateAllRealRoots(['-1', '0', '1'], maxDepth: 0));
        self::assertNull(
            $counter->isolateAllRealRoots(['-1', '0', '1'], maximumWidth: ['0', '1']),
        );
        self::assertNull(
            $counter->isolateAllRealRoots(['-1', '0', '1'], maximumWidth: ['1', '0']),
        );
        self::assertSame(
            [['1', $scale, '1', $scale]],
            $counter->isolateOpenInterval(
                [$scale, '-' . $linearMagnitude, $scale],
                '0',
                '1',
                '2',
                $scale,
                maxDepth: 1,
                maximumWidth: ['1', $scale],
            ),
        );
        self::assertSame(
            [],
            $counter->isolateOpenInterval(['-1', '0', '1'], '-1', '1', '1', '1', maxDepth: 1),
        );
        self::assertSame(
            [['1', '1', '1', '1']],
            $counter->isolateOpenInterval(['-1', '0', '1'], '0', '1', '2', '1', maxDepth: 1),
        );
    }

    public function testRepeatedRootsAreCountedOnceAndBoundaryRootsExcluded(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(1, $counter->countOpenInterval(['1', '-4', '6', '-4', '1'], '0', '1', '2', '1'));
        self::assertSame(0, $counter->countOpenInterval(['0', '-1', '1'], '0', '1', '1', '1'));
    }

    public function testRepeatedIrrationalRootsAreCountedAndIsolatedOnce(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['4', '0', '-4', '0', '1'];

        self::assertSame(2, $counter->countAllRealRoots($coefficients));
        $intervals = $counter->isolateAllRealRoots(
            $coefficients,
            maximumWidth: ['1', '1000000'],
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        foreach ($intervals as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            self::assertSame(
                1,
                $counter->countOpenInterval(
                    $coefficients,
                    $lowerNumerator,
                    $lowerDenominator,
                    $upperNumerator,
                    $upperDenominator,
                ),
            );
        }
    }

    public function testIsolationExcludesBoundaryFactorsAndKeepsInteriorIrrationalRoot(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['0', '4', '-2', '-2', '1'];

        self::assertSame(1, $counter->countOpenInterval($coefficients, '0', '1', '2', '1'));
        $intervals = $counter->isolateOpenInterval(
            $coefficients,
            '0',
            '1',
            '2',
            '1',
            maxDepth: 1,
        );

        self::assertNotNull($intervals);
        self::assertCount(1, $intervals);
        self::assertNotSame(['0', '1', '0', '1'], $intervals[0]);
        self::assertNotSame(['2', '1', '2', '1'], $intervals[0]);
        self::assertSame(1, $counter->countOpenInterval($coefficients, ...$intervals[0]));
    }

    public function testIrrationalQuadraticRootsUseCertifiedSquareRootIntervals(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['-2', '0', '1'];
        $intervals = $counter->isolateAllRealRoots(
            $coefficients,
            maxDepth: 1,
            maximumWidth: ['1', '1000000'],
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        foreach ($intervals as $index => $interval) {
            self::assertSame(1, $counter->countOpenInterval($coefficients, ...$interval));
            $approximateRoot = (float) $interval[0] / (float) $interval[1];
            self::assertEqualsWithDelta($index === 0 ? -sqrt(2.0) : sqrt(2.0), $approximateRoot, 1.0E-20);
        }
        self::assertNull(
            $counter->isolateAllRealRoots(
                $coefficients,
                maxDepth: 1,
                maximumWidth: ['1', '10000000000000000000000000000000'],
            ),
        );
    }

    public function testUnsupportedDegreeAndInvalidIntervalReturnNull(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertNull($counter->countOpenInterval(['0', '1'], '1', '1', '0', '1'));
        self::assertNull($counter->countOpenInterval(['0', 'not-an-integer'], '0', '1', '1', '1'));

        $overLimit = \array_map(
            static fn (int $degree): string => match ($degree) {
                0 => '-1',
                257 => '1',
                default => '0',
            },
            \range(0, 257),
        );
        self::assertNull($counter->countOpenInterval($overLimit, '-2', '1', '2', '1'));
    }

    public function testCountsAndIsolatesAtTheMaximumSupportedDegree(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = \array_map(
            static fn (int $degree): string => match ($degree) {
                0 => '-1',
                256 => '1',
                default => '0',
            },
            \range(0, 256),
        );

        self::assertSame(2, $counter->countOpenInterval($coefficients, '-2', '1', '2', '1'));

        $intervals = $counter->isolateOpenInterval(
            $coefficients,
            '-2',
            '1',
            '2',
            '1',
            maximumWidth: ['1', '100'],
        );

        self::assertNotNull($intervals);
        self::assertCount(2, $intervals);
        foreach ($intervals as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            if ($lowerNumerator === $upperNumerator && $lowerDenominator === $upperDenominator) {
                self::assertTrue($counter->isRootAt($coefficients, [$lowerNumerator, $lowerDenominator]));
                continue;
            }

            self::assertSame(
                1,
                $counter->countOpenInterval(
                    $coefficients,
                    $lowerNumerator,
                    $lowerDenominator,
                    $upperNumerator,
                    $upperDenominator,
                ),
            );
        }
    }

    public function testMalformedRationalBoundsReturnNullWithoutTypeErrors(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertNull($counter->isRootAt(['-1', '1'], [1, '1']));
        self::assertNull($counter->isRootAt(['-1', '1'], ['numerator' => '1', 'denominator' => '1']));
        self::assertNull($counter->isolateOpenInterval(
            ['-1', '1'],
            '0',
            '1',
            '2',
            '1',
            maximumWidth: [1, '2'],
        ));
        self::assertNull($counter->isolateOpenInterval(
            ['-1', '1'],
            '0',
            '1',
            '2',
            '1',
            maximumWidth: ['numerator' => '1', 'denominator' => '2'],
        ));
    }

    public function testEvaluatesExactPolynomialSignsAtRationalPoints(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(1, $counter->signAt(['-1', '0', '1'], ['2', '1']));
        self::assertSame(-1, $counter->signAt(['-1', '0', '1'], ['0', '1']));
        self::assertSame(0, $counter->signAt(['-1', '0', '1'], ['-1', '1']));
        self::assertNull($counter->signAt(['-1', '1'], ['1', '0']));
    }

    public function testZeroPolynomialIsNotReportedAsHavingNoRoots(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(0, $counter->countOpenInterval(['7'], '0', '1', '1', '1'));
        self::assertNull($counter->countOpenInterval(['0'], '0', '1', '1', '1'));
        self::assertNull($counter->countOpenInterval(['-0'], '0', '1', '1', '1'));
        self::assertNull($counter->countOpenInterval(['-0', '-0'], '0', '1', '1', '1'));
        self::assertSame(1, $counter->countOpenInterval(['-0', '1'], '-1', '1', '1', '1'));
        self::assertSame(0, $counter->countOpenInterval(['0', '1'], '-0', '1', '1', '1'));
    }

    public function testRootIntervalRejectsNonCanonicalNegativeZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RationalRootInterval('-0', '1', '0', '1', true);
    }

    public function testRationalIntervalAndLargeExactCoefficientsRemainExact(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = [
            '9007199254740992',
            '-18014398509481985',
            '9007199254740993',
        ];

        self::assertSame(1, $counter->countOpenInterval($coefficients, '0', '1', '1', '1'));
        self::assertSame(0, $counter->countOpenInterval($coefficients, '1', '1', '2', '1'));
    }

    public function testNonDyadicRationalEndpointIsExcludedExactly(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(0, $counter->countOpenInterval(['-1', '3'], '1', '3', '1', '2'));
        self::assertSame(1, $counter->countOpenInterval(['-1', '3'], '0', '1', '1', '2'));
    }

    public function testIsolatesEveryDistinctRootAndRetainsExactRationalMidpoints(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $intervals = $counter->isolateOpenInterval(
            ['0', '-1', '0', '1'],
            '-2',
            '1',
            '2',
            '1',
        );

        $coefficients = ['0', '-1', '0', '1'];
        self::assertSame(
            [
                ['-1', '1', '-1', '1'],
                ['0', '1', '0', '1'],
                ['1', '1', '1', '1'],
            ],
            $intervals,
        );
        foreach ($intervals as [$numerator, $denominator]) {
            self::assertTrue($counter->isRootAt($coefficients, [$numerator, $denominator]));
        }
    }

    public function testExactIntegerRootsDoNotRequireSubdivisionDepth(): void
    {
        $counter = new ExactPolynomialRootCounter();

        self::assertSame(
            [
                ['-1', '1', '-1', '1'],
                ['0', '1', '0', '1'],
                ['1', '1', '1', '1'],
            ],
            $counter->isolateOpenInterval(['0', '-1', '0', '1'], '-2', '1', '2', '1', 1),
        );
    }

    public function testIsolationPartitionsRootsOfRepeatedFactorPolynomial(): void
    {
        $counter = new ExactPolynomialRootCounter();
        $coefficients = ['0', '-1', '-1', '1', '1'];
        $intervals = $counter->isolateOpenInterval($coefficients, '-2', '1', '2', '1');

        self::assertSame(
            [
                ['-1', '1', '-1', '1'],
                ['0', '1', '0', '1'],
                ['1', '1', '1', '1'],
            ],
            $intervals,
        );
    }

    public function testIsolationKeepsDistinctRootsBelowFloatResolution(): void
    {
        $arithmetic = new \MathPHP\Solver\IntegerPolynomialExtractor('', []);
        $scale = '100000000000000000000';
        $firstRootNumerator = $arithmetic->addIntegerValues($scale, '1');
        $secondRootNumerator = $arithmetic->addIntegerValues($scale, '2');
        $coefficients = [
            $arithmetic->multiplyIntegerValues($firstRootNumerator, $secondRootNumerator),
            $arithmetic->negateIntegerValue($arithmetic->multiplyIntegerValues(
                $scale,
                $arithmetic->addIntegerValues($firstRootNumerator, $secondRootNumerator),
            )),
            $arithmetic->multiplyIntegerValues($scale, $scale),
        ];
        $counter = new ExactPolynomialRootCounter();
        $intervals = $counter->isolateOpenInterval(
            $coefficients,
            '1',
            '1',
            '2',
            '1',
            1,
            ['1', '1000000'],
        );

        self::assertSame(
            [
                [$firstRootNumerator, $scale, $firstRootNumerator, $scale],
                [
                    $arithmetic->divideIntegerValueExactly($secondRootNumerator, '2'),
                    $arithmetic->divideIntegerValueExactly($scale, '2'),
                    $arithmetic->divideIntegerValueExactly($secondRootNumerator, '2'),
                    $arithmetic->divideIntegerValueExactly($scale, '2'),
                ],
            ],
            $intervals,
        );
        self::assertSame(
            (float) $firstRootNumerator / (float) $scale,
            (float) $secondRootNumerator / (float) $scale,
        );
        foreach ($intervals as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            self::assertTrue($counter->isRootAt($coefficients, [$lowerNumerator, $lowerDenominator]));
            self::assertSame([$lowerNumerator, $lowerDenominator], [$upperNumerator, $upperDenominator]);
        }
    }

    public function testRationalPowersWithVariableTargetsReduceExactly(): void
    {
        $mixedRealAndComplexLevels = 'cbrt(x^2 - 2)^4 - cbrt(x^2 - 2)^3 '
            . '- cbrt(x^2 - 2)^2 + 2*cbrt(x^2 - 2) - 2 = 0';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($mixedRealAndComplexLevels, 'x'));
        $mixedLevelRoots = Math::isolatePolynomialRootsOnRealLine($mixedRealAndComplexLevels, 'x');
        self::assertNotNull($mixedLevelRoots);
        self::assertCount(2, $mixedLevelRoots);
        $higherDegreeMixedLevels = 'cbrt(x^2 - 2)^5 - 4*cbrt(x^2 - 2)^4 '
            . '+ 2*cbrt(x^2 - 2)^3 + 5*cbrt(x^2 - 2)^2 '
            . '- 8*cbrt(x^2 - 2) + 6 = 0';
        self::assertSame(4, Math::countPolynomialRootsOnRealLine($higherDegreeMixedLevels, 'x'));
        $higherDegreeRoots = Math::isolatePolynomialRootsOnRealLine($higherDegreeMixedLevels, 'x');
        self::assertNotNull($higherDegreeRoots);
        self::assertCount(4, $higherDegreeRoots);
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(5/3)+x^(4/3)-2*x=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(1/7)-x^(1/11)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(1/61)=x^(1/63)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'x^(1/61)=x^(1/63)',
            'x',
            '-2',
            '0',
        ));
        $highDenominatorRoots = Math::isolatePolynomialRootsOnRealLine('x^(1/61)=x^(1/63)', 'x');
        self::assertNotNull($highDenominatorRoots);
        self::assertCount(3, $highDenominatorRoots);
        self::assertSame(['-1', '0', '1'], \array_map(
            static fn (RationalRootInterval $root): string => $root->lowerNumerator,
            $highDenominatorRoots,
        ));
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'x^(1/7)-x^(1/11)=0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 4),
        ));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine(
            'x^(1/7)-x^(1/11)=0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 5),
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(1/7)+x^(1/11)-2=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('(x-1)^(5/3)+(x-1)^(4/3)-2*(x-1)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(4/3)+x-2*x^(2/3)=0', 'x'));
        $affinePowerRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(2*x+1)+(2*x+1)^(2/3)-1=0',
            'x',
        );
        self::assertNotNull($affinePowerRoots);
        self::assertCount(2, $affinePowerRoots);
        $intervalBounds = static fn (RationalRootInterval $root): array => [
            (float) $root->lowerNumerator / (float) $root->lowerDenominator,
            (float) $root->upperNumerator / (float) $root->upperDenominator,
        ];
        [$lower, $upper] = $intervalBounds($affinePowerRoots[0]);
        self::assertGreaterThan(-2.62, $lower);
        self::assertLessThan(-2.61, $upper);
        [$lower, $upper] = $intervalBounds($affinePowerRoots[1]);
        self::assertGreaterThan(-0.39, $lower);
        self::assertLessThan(-0.38, $upper);
        $exactAffinePowerRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(2*x+1)+(2*x+1)^(2/3)=0',
            'x',
        );
        self::assertNotNull($exactAffinePowerRoots);
        self::assertSame(['-1', '-1'], \array_map(
            static fn (RationalRootInterval $root): string => $root->lowerNumerator,
            $exactAffinePowerRoots,
        ));
        self::assertSame(['1', '2'], \array_map(
            static fn (RationalRootInterval $root): string => $root->lowerDenominator,
            $exactAffinePowerRoots,
        ));
        self::assertSame([true, true], \array_map(
            static fn (RationalRootInterval $root): bool => $root->isExactRoot,
            $exactAffinePowerRoots,
        ));
        $nonAffinePowerRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(x^2-1)+(x^2-1)^(2/3)-(x^2-1)^(2/3)-1/2=0',
            'x',
        );
        self::assertNotNull($nonAffinePowerRoots);
        self::assertCount(2, $nonAffinePowerRoots);
        [$lower, $upper] = $intervalBounds($nonAffinePowerRoots[0]);
        self::assertGreaterThan(-1.07, $lower);
        self::assertLessThan(-1.05, $upper);
        [$lower, $upper] = $intervalBounds($nonAffinePowerRoots[1]);
        self::assertGreaterThan(1.05, $lower);
        self::assertLessThan(1.07, $upper);
        $quadraticIrrationalLevels = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(x^2-5)+(x^2-5)^(2/3)-1=0',
            'x',
        );
        self::assertNotNull($quadraticIrrationalLevels);
        self::assertCount(4, $quadraticIrrationalLevels);
        $expectedQuadraticLevelRoots = [
            -\sqrt(5.2360679775),
            -\sqrt(0.7639320225),
            \sqrt(0.7639320225),
            \sqrt(5.2360679775),
        ];
        foreach ($expectedQuadraticLevelRoots as $index => $expectedRoot) {
            [$lower, $upper] = $intervalBounds($quadraticIrrationalLevels[$index]);
            self::assertGreaterThan($expectedRoot - 0.00001, $lower);
            self::assertLessThan($expectedRoot + 0.00001, $upper);
        }
        $cubicIrrationalLevels = Math::isolatePolynomialRootsOnRealLine(
            '(x^2-5)^(3/3)-4*(x^2-5)^(2/3)-2*(x^2-5)^(1/3)+8=0',
            'x',
        );
        self::assertNotNull($cubicIrrationalLevels);
        self::assertCount(6, $cubicIrrationalLevels);
        $expectedCubicLevelRoots = [
            -\sqrt(69),
            -\sqrt(5 + (2 * \sqrt(2))),
            -\sqrt(5 - (2 * \sqrt(2))),
            \sqrt(5 - (2 * \sqrt(2))),
            \sqrt(5 + (2 * \sqrt(2))),
            \sqrt(69),
        ];
        foreach ($expectedCubicLevelRoots as $index => $expectedRoot) {
            [$lower, $upper] = $intervalBounds($cubicIrrationalLevels[$index]);
            self::assertGreaterThan($expectedRoot - 0.00001, $lower);
            self::assertLessThan($expectedRoot + 0.00001, $upper);
        }
        $quarticIrrationalLevels = Math::isolatePolynomialRootsOnRealLine(
            '(x^2-10)^(4/3)-5*(x^2-10)^(2/3)+6=0',
            'x',
        );
        self::assertNotNull($quarticIrrationalLevels);
        self::assertCount(8, $quarticIrrationalLevels);
        $expectedQuarticLevelRoots = [
            -\sqrt(10 + (3 * \sqrt(3))),
            -\sqrt(10 + (2 * \sqrt(2))),
            -\sqrt(10 - (2 * \sqrt(2))),
            -\sqrt(10 - (3 * \sqrt(3))),
            \sqrt(10 - (3 * \sqrt(3))),
            \sqrt(10 - (2 * \sqrt(2))),
            \sqrt(10 + (2 * \sqrt(2))),
            \sqrt(10 + (3 * \sqrt(3))),
        ];
        foreach ($expectedQuarticLevelRoots as $index => $expectedRoot) {
            [$lower, $upper] = $intervalBounds($quarticIrrationalLevels[$index]);
            self::assertGreaterThan($expectedRoot - 0.00001, $lower);
            self::assertLessThan($expectedRoot + 0.00001, $upper);
        }
        self::assertSame(
            10,
            Math::countPolynomialRootsOnRealLine(
                '(x^2-40)^(5/3)-5*(x^2-40)^(4/3)-5*(x^2-40)^(3/3)'
                    . '+25*(x^2-40)^(2/3)+6*(x^2-40)^(1/3)-30=0',
                'x',
            ),
        );
        $quinticIrrationalRoots = Math::isolatePolynomialRootsOnRealLine(
            '(x^2-40)^(5/3)-5*(x^2-40)^(4/3)-5*(x^2-40)^(3/3)'
                . '+25*(x^2-40)^(2/3)+6*(x^2-40)^(1/3)-30=0',
            'x',
        );
        self::assertNotNull($quinticIrrationalRoots);
        self::assertCount(10, $quinticIrrationalRoots);
        $expectedQuinticRoots = [
            -\sqrt(165),
            -\sqrt(40 + (3 * \sqrt(3))),
            -\sqrt(40 + (2 * \sqrt(2))),
            -\sqrt(40 - (2 * \sqrt(2))),
            -\sqrt(40 - (3 * \sqrt(3))),
            \sqrt(40 - (3 * \sqrt(3))),
            \sqrt(40 - (2 * \sqrt(2))),
            \sqrt(40 + (2 * \sqrt(2))),
            \sqrt(40 + (3 * \sqrt(3))),
            \sqrt(165),
        ];
        foreach ($expectedQuinticRoots as $index => $expectedRoot) {
            [$lower, $upper] = $intervalBounds($quinticIrrationalRoots[$index]);
            self::assertGreaterThan($expectedRoot - 0.00001, $lower);
            self::assertLessThan($expectedRoot + 0.00001, $upper);
        }
        $splitCommonRootPowerRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(x^2-1)+(x^2-1)^(2/3)-2*(x^2-1)=0',
            'x',
        );
        self::assertNotNull($splitCommonRootPowerRoots);
        self::assertCount(6, $splitCommonRootPowerRoots);
        $expectedSplitRoots = [-\sqrt(2), -1.0, -\sqrt(7 / 8), \sqrt(7 / 8), 1.0, \sqrt(2)];
        foreach ($expectedSplitRoots as $index => $expectedRoot) {
            [$lower, $upper] = $intervalBounds($splitCommonRootPowerRoots[$index]);
            self::assertGreaterThan($expectedRoot - 0.00001, $lower);
            self::assertLessThan($expectedRoot + 0.00001, $upper);
        }
        $rationalBaseHoleRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt((x^2-1)/(x-1))+'
                . '((x^2-1)/(x-1))^(2/3)-((x^2-1)/(x-1))^(2/3)-1=0',
            'x',
        );
        self::assertNotNull($rationalBaseHoleRoots);
        self::assertCount(1, $rationalBaseHoleRoots);
        self::assertSame('0', $rationalBaseHoleRoots[0]->lowerNumerator);
        self::assertTrue($rationalBaseHoleRoots[0]->isExactRoot);
        $negativePowerCancellation = '(x^2-1)^(-1/3)-(x^2-1)^(-1/3)+1=0';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($negativePowerCancellation, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($negativePowerCancellation, 'x'));
        $repeatedRationalBase = '((x^2-1)/(x-1))';
        $manyTermRationalPowerSum = 'cbrt(' . $repeatedRationalBase . ')';
        for ($term = 0; $term < 130; ++$term) {
            $manyTermRationalPowerSum .= '+' . $repeatedRationalBase . '^(2/3)'
                . '-' . $repeatedRationalBase . '^(2/3)';
        }
        $manyTermRationalPowerSum .= '-1=0';
        $manyTermOptions = new EvaluationOptions(
            limits: new ResourceLimits(
                maxExpressionLength: 10_000,
                maxTokens: 10_000,
                maxAstDepth: 2_048,
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            $manyTermRationalPowerSum,
            'x',
            evaluationOptions: $manyTermOptions,
        ));
        $manyTermRoots = Math::isolatePolynomialRootsOnRealLine(
            $manyTermRationalPowerSum,
            'x',
            evaluationOptions: $manyTermOptions,
        );
        self::assertNotNull($manyTermRoots);
        self::assertCount(1, $manyTermRoots);
        self::assertSame('0', $manyTermRoots[0]->lowerNumerator);
        self::assertTrue($manyTermRoots[0]->isExactRoot);
        $negativeSlopeRoots = Math::isolatePolynomialRootsOnRealLine(
            'cbrt(-2*x+1)+(-2*x+1)^(2/3)-1=0',
            'x',
        );
        self::assertNotNull($negativeSlopeRoots);
        self::assertCount(2, $negativeSlopeRoots);
        [$lower, $upper] = $intervalBounds($negativeSlopeRoots[0]);
        self::assertGreaterThan(0.38, $lower);
        self::assertLessThan(0.39, $upper);
        [$lower, $upper] = $intervalBounds($negativeSlopeRoots[1]);
        self::assertGreaterThan(2.61, $lower);
        self::assertLessThan(2.62, $upper);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(x-1)+root(x-1,5)-2=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(1/3)+x^(2/3)-2=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('8^(1/3)*x^(1/3)+x^(2/3)-3=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('2^(1/3)*4^(1/3)*x^(1/3)+x^(2/3)-3=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('2^(1/3)*2^(2/3)*x^(1/3)+x^(2/3)-3=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(8,3)*x^(1/3)+x^(2/3)-3=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(1/8)^(1/3)*x^(1/3)+x^(2/3)-1=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(-8)^(1/3)*x^(1/3)+x^(2/3)-1=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(8/27)^(1/3)*x^(1/3)+x^(2/3)-1=0', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('2^(1/3)*x^(1/3)+x^(2/3)-1=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(-x)^(1/3)+x^(2/3)-2=0', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'x^(1/3)+x^(2/3)-2=0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 1),
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-2/3)+x^(1/3)-2*x^(4/3)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x-1)^(-2/3)+(x-1)^(1/3)-2*(x-1)^(4/3)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(1/3)=x^(1/5)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-1/3)=x^(1/5)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(1/3)=(x-1)^(2/3)', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('cbrt(x)*cbrt(x+1)=x', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(1/3)+x^(1/5)=0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(1/3)+2*x^(1/5)=0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('-x^(1/3)-2*x^(1/5)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(1/3)-x^(1/5)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(1/3)-2*x^(1/5)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('-2*x^(1/3)+x^(1/5)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(1/3)+x^(2/3)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(1/3)+2*x^(2/3)=0', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(-1/3)+x^(2/3)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cbrt(x)-(x^2/x)^(1/5)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-1/3)-x^(-1/5)=0', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(1/3)-x^(2/3)=0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('x^(-1/3)+x^(-1/5)=0', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cbrt(x)+cbrt(x^2/x)=0', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('-cbrt(x)*cbrt(x+1)=-x', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(cbrt(x)*cbrt(x+1))^3=x', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(cbrt(x)*cbrt(x+1))^2=1', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cbrt(x)/cbrt(x+1)=x', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('1/(1/cbrt(x))=x', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cbrt(x)/cbrt(x+1)=1', 'x'));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'cbrt(x/(x-1))*cbrt(x-1)=cbrt(2*x-1)',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(2/3)=(x-1)^(2/3)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(-2/3)=(x-1)^(-2/3)', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(3/5)=x', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('x^(-3/5)=x', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x, -3)=x', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('(x-x)^(-1/3)=x^(1/3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2, 3)=x', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cbrt(x^3+1)=x', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('(x/(x-1))^(1/3)=x', 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('(1/(x-1))^(-1/3)=x-1', 'x'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('((x-1)/(x-1))^(-1/3)=x', 'x'),
        );
    }

    public function testDegreeNineIrrationalCommonRootLevelsAreCountedAndIsolated(): void
    {
        $base = 'root(x^2+1,3)';
        $equation = '(' . $base . ')^9-(' . $base . ')^8'
            . '-17*(' . $base . ')^7+17*(' . $base . ')^6'
            . '+101*(' . $base . ')^5-101*(' . $base . ')^4'
            . '-247*(' . $base . ')^3+247*(' . $base . ')^2'
            . '+210*(' . $base . ')-210=0';
        self::assertSame(9, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(9, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-10', '10'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(9, $roots);
        $expectedRoots = [0.0];
        foreach ([2, 3, 5, 7] as $level) {
            $positiveRoot = \sqrt(($level * \sqrt($level)) - 1);
            $expectedRoots[] = -$positiveRoot;
            $expectedRoots[] = $positiveRoot;
        }
        \sort($expectedRoots, SORT_NUMERIC);
        foreach ($expectedRoots as $index => $expectedRoot) {
            $interval = $roots[$index];
            $lower = (float) $interval->lowerNumerator / (float) $interval->lowerDenominator;
            $upper = (float) $interval->upperNumerator / (float) $interval->upperDenominator;
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
            self::assertLessThanOrEqual(0.000001, $upper - $lower);
        }
    }

    public function testRepeatedIrrationalCommonRootLevelsAreCountedAndIsolated(): void
    {
        $equation = 'root(x^2+1,3)^4-4*root(x^2+1,3)^2+4=0';
        $expectedMagnitude = \sqrt((2.0 * \sqrt(2.0)) - 1.0);

        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
        );

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $index => $root) {
            self::assertFalse($root->isExactRoot);
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = $index === 0 ? -$expectedMagnitude : $expectedMagnitude;
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
        }
    }

    public function testRepeatedQuarticCommonRootLevelsAreCountedAndIsolated(): void
    {
        $base = 'root(x^2+1,3)';
        $equation = $base . '^8-10*' . $base . '^6+37*' . $base . '^4-60*' . $base . '^2+36=0';
        $expectedRoots = [
            -\sqrt((3.0 * \sqrt(3.0)) - 1.0),
            -\sqrt((2.0 * \sqrt(2.0)) - 1.0),
            \sqrt((2.0 * \sqrt(2.0)) - 1.0),
            \sqrt((3.0 * \sqrt(3.0)) - 1.0),
        ];

        self::assertSame(4, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(4, $roots);
        foreach ($roots as $index => $root) {
            self::assertFalse($root->isExactRoot);
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            self::assertLessThanOrEqual($upper, $expectedRoots[$index]);
            self::assertGreaterThanOrEqual($lower, $expectedRoots[$index]);
        }
    }

    public function testRepeatedHigherDegreeCommonRootLevelsAreCountedAndIsolated(): void
    {
        $base = 'root(x^2+1,3)';
        $equation = $base . '^10-10*' . $base . '^8+33*' . $base . '^6-40*' . $base . '^4+16*' . $base . '^2=0';

        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        foreach ($roots as $index => $root) {
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = [-\sqrt(7.0), 0.0, \sqrt(7.0)][$index];
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
        }
    }

    public function testRepeatedCommonRootWithNonrealFactorsKeepsItsRationalRealLevels(): void
    {
        $base = 'root(x^2+1,3)';
        $equation = $base . '^8-6*' . $base . '^7+15*' . $base . '^6-24*' . $base . '^5'
            . '+31*' . $base . '^4-30*' . $base . '^3+21*' . $base . '^2-12*' . $base . '+4=0';

        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        self::assertSame('0', $roots[1]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerDenominator);
        self::assertTrue($roots[1]->isExactRoot);
        foreach ([$roots[0], $roots[2]] as $index => $root) {
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = $index === 0 ? -\sqrt(7.0) : \sqrt(7.0);
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
        }
    }

    public function testIrrationalRealCommonRootWithNonrealFactorsIsolatedThroughItsImage(): void
    {
        $base = 'root(x^2+1,3)';
        $equation = $base . '^5-' . $base . '-1=0';
        $expectedMagnitude = 0.7684834028667296;

        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $index => $root) {
            self::assertFalse($root->isExactRoot);
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = $index === 0 ? -$expectedMagnitude : $expectedMagnitude;
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
        }
    }

    public function testNonrealCommonRootImagesDoNotAddSpuriousRealLevels(): void
    {
        $base = '(9*x^2-8)/(x^2+1)';
        $cubeRoot = 'cbrt(' . $base . ')';
        $equation = $cubeRoot . '^3-3*' . $cubeRoot . '^2+6*' . $cubeRoot . '-4=0';
        $expectedMagnitude = \sqrt(9.0 / 8.0);

        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $index => $root) {
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = $index === 0 ? -$expectedMagnitude : $expectedMagnitude;
            self::assertLessThanOrEqual($upper, $expectedRoot);
            self::assertGreaterThanOrEqual($lower, $expectedRoot);
        }
    }

    public function testCollidingNonrealCommonRootImagesKeepDistinctRealLevels(): void
    {
        $base = '(9*x^2-8)/(x^2+1)';
        $cubeRoot = 'cbrt(' . $base . ')';
        $equation = $cubeRoot . '^5+' . $cubeRoot . '^3-' . $cubeRoot . '^2-1=0';
        $expectedMagnitude = \sqrt(9.0 / 8.0);

        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $index => $root) {
            $lower = (float) $root->lowerNumerator / (float) $root->lowerDenominator;
            $upper = (float) $root->upperNumerator / (float) $root->upperDenominator;
            $expectedRoot = $index === 0 ? -$expectedMagnitude : $expectedMagnitude;
            self::assertLessThanOrEqual($upper, $expectedRoot - 1.0E-12);
            self::assertGreaterThanOrEqual($lower, $expectedRoot + 1.0E-12);
        }
    }

    public function testDegreeThirteenCommonRootWithNonrealFactorsIsCountedThroughItsImage(): void
    {
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('cbrt(x^2)^13+cbrt(x^2)-1=0', 'x'),
        );
    }

    public function testLogarithmEqualityAtPositiveConstantArgumentReducesExactly(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^2-1)=ln(3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log10(x^2-3*x+2)=log10(3)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln((x^2-1)/(x-1))=ln(2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln((x^2-1)/(x-1))=ln(3)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln((x-1)/(x^2-1))=ln(1/2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x)=ln(-2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x^2-3)=ln(2-x^2)', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('ln(x^2-3)=ln(2-x^2)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^2-1)=ln(3-x^2)', 'x'));
        $partiallyDefinedRoots = Math::isolatePolynomialRootsOnRealLine('ln(x^2-1)=ln(x+1)', 'x');
        self::assertNotNull($partiallyDefinedRoots);
        self::assertCount(1, $partiallyDefinedRoots);
        self::assertSame('2', $partiallyDefinedRoots[0]->lowerNumerator);
        self::assertSame('1', $partiallyDefinedRoots[0]->lowerDenominator);
        self::assertTrue($partiallyDefinedRoots[0]->isExactRoot);
    }

    public function testLogarithmPolynomialEqualityUsesOpenFiniteIntervalBounds(): void
    {
        $equation = 'ln(x^2-1)=ln(3-x^2)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '0'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '0'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '0');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);

        $exactRootEquation = 'ln(x^2-1)=ln(x+1)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($exactRootEquation, 'x', '2', '3'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($exactRootEquation, 'x', '1', '3'));
        $exactRoots = Math::isolatePolynomialRootsInRationalInterval($exactRootEquation, 'x', '1', '3');
        self::assertNotNull($exactRoots);
        self::assertCount(1, $exactRoots);
        self::assertSame('2', $exactRoots[0]->lowerNumerator);
        self::assertTrue($exactRoots[0]->isExactRoot);
    }

    public function testLogOnePlusPolynomialEqualityHonorsItsStrictDomain(): void
    {
        $equation = 'log1p(x^2-1)=log1p(2*x-1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '1', '3'));

        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('2', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log1p(x^2-1)=log1p(-1)', 'x'));
    }

    public function testAcoshPolynomialEqualityIncludesItsValidBoundary(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acosh(x)=acosh(2-x)', 'x'));
        $boundaryRoot = Math::isolatePolynomialRootsOnRealLine('acosh(x)=acosh(2-x)', 'x');
        self::assertNotNull($boundaryRoot);
        self::assertCount(1, $boundaryRoot);
        self::assertSame('1', $boundaryRoot[0]->lowerNumerator);
        self::assertTrue($boundaryRoot[0]->isExactRoot);

        $equation = 'acosh(x^2-1)=acosh(2*x-1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '1', '3'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('2', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);

        $orderedRoots = Math::isolatePolynomialRootsOnRealLine(
            'acosh(x)=acosh(-x^2+4*x-2)',
            'x',
        );
        self::assertNotNull($orderedRoots);
        self::assertCount(2, $orderedRoots);
        self::assertSame('1', $orderedRoots[0]->lowerNumerator);
        self::assertSame('2', $orderedRoots[1]->lowerNumerator);

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arccosh(x)=arccosh(2-x)', 'x'));
    }

    public function testAcoshAsinhEqualityEnforcesBranchesAndKeepsIsolatedIdentityRoots(): void
    {
        $equation = 'acosh(1+x^2)=asinh(2*x)';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('0', $roots[0]->lowerNumerator);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acosh(x)=asinh(-1)', 'x'));

        $identityBoundary = 'acosh((1+x^4)/(1-x^4))=asinh(-2*x^2/(1-x^4))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($identityBoundary, 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($identityBoundary, 'x'));
        $boundaryRoots = Math::isolatePolynomialRootsOnRealLine($identityBoundary, 'x');
        self::assertNotNull($boundaryRoots);
        self::assertCount(1, $boundaryRoots);
        self::assertSame('0', $boundaryRoots[0]->lowerNumerator);
        self::assertTrue($boundaryRoots[0]->isExactRoot);

        $boundaryHole = 'acosh(((1+x^4)/(1-x^4))*(x/x))=asinh(-2*x^2/(1-x^4))';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($boundaryHole, 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($boundaryHole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($boundaryHole, 'x'));
    }

    public function testAcoshAsinhIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'acosh((1+x^2)/(1-x^2))=asinh(2*x/(1-x^2))';
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
    }

    public function testAsechAsinhEqualityPreservesDomainAndRationalHoles(): void
    {
        foreach (['asech(x)=asinh(x)', 'arcsinh(x)=asech(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }

        $boundary = 'asech(1/(1+x^2))=asinh(x)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($boundary, 'x'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($boundary, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('0', $roots[0]->lowerNumerator);

        $hole = 'asech((1/(1+x^2))*(x/x))=asinh(x)';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolatedIdentity = 'asech((1-x^4)/(1+x^4))=asinh(-2*x^2/(1-x^4))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolatedIdentity, 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($isolatedIdentity, 'x'));
        $identityRoots = Math::isolatePolynomialRootsOnRealLine($isolatedIdentity, 'x');
        self::assertNotNull($identityRoots);
        self::assertCount(1, $identityRoots);
        self::assertSame('0', $identityRoots[0]->lowerNumerator);
    }

    public function testAsechAsinhIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'asech((1-x^2)/(1+x^2))=asinh(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
    }

    public function testAcoshAndAsechEqualitiesWithAcschPreserveDomains(): void
    {
        foreach (['acosh(x)=acsch(x)', 'acsch(x)=arccosh(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '1', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }

        $asechEquation = 'asech(x)=acsch(1)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($asechEquation, 'x', '0', '1'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($asechEquation, 'x'));
        $asechRoots = Math::isolatePolynomialRootsInRationalInterval($asechEquation, 'x', '0', '1');
        self::assertNotNull($asechRoots);
        self::assertCount(1, $asechRoots);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asech(x)=acsch(x)', 'x'));

        $reciprocalHole = 'acosh(1+x^2)=acsch(1/x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($reciprocalHole, 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($reciprocalHole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($reciprocalHole, 'x'));
    }

    public function testAcoshAcschIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'acosh((1+x^2)/(1-x^2))=acsch((1-x^2)/(2*x))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
    }

    public function testAtanhAcothIdentityFindsAnOpenCellNearAnIrrationalBoundary(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            'atanh(x^2-1)=acoth(1/(x^2-1))',
            'x',
            '1.41421356237309504880',
            '1.41421356237309504881',
        );
    }

    public function testAcoshAsinhIdentityFindsANarrowNonSymmetricOpenCell(): void
    {
        $equation = 'acosh((1+(x^2+x-1)^2)/(1-(x^2+x-1)^2))='
            . 'asinh(2*(x^2+x-1)/(1-(x^2+x-1)^2))';
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            $equation,
            'x',
            '0.61803398874989484820',
            '0.61803398874989484821',
        );
    }

    public function testAtanhPolynomialEqualityRejectsDomainBoundaries(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atanh(x)=atanh(2-x)', 'x'));

        $equation = 'atanh(x/2)=atanh(1-x/2)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('arctanh(x)=arctanh(2-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arctanh(x/2)=arctanh(1-x/2)', 'x'));
    }

    public function testAcothPolynomialEqualityHonorsBothDomainBranches(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acoth(x)=acoth(2-x)', 'x'));

        $equation = 'acoth(x^2+1)=acoth(2*x+1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('2', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testAtanhAcothReciprocalEqualityPreservesStrictDomains(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('atanh(x)=acoth(2)', 'x', '0', '1'));
        $roots = Math::isolatePolynomialRootsInRationalInterval('atanh(x)=acoth(2)', 'x', '0', '1');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertSame('2', $roots[0]->lowerDenominator);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atanh(x)=acoth(2)', 'x'));
        $allRealRoots = Math::isolatePolynomialRootsOnRealLine('atanh(x)=acoth(2)', 'x');
        self::assertNotNull($allRealRoots);
        self::assertCount(1, $allRealRoots);
        self::assertSame('1', $allRealRoots[0]->lowerNumerator);
        self::assertSame('2', $allRealRoots[0]->lowerDenominator);
        self::assertTrue($allRealRoots[0]->isExactRoot);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atanh(x/(x-2))=acoth(3)', 'x'));
        $rationalRoots = Math::isolatePolynomialRootsOnRealLine('atanh(x/(x-2))=acoth(3)', 'x');
        self::assertNotNull($rationalRoots);
        self::assertCount(1, $rationalRoots);
        self::assertSame('-1', $rationalRoots[0]->lowerNumerator);
        self::assertTrue($rationalRoots[0]->isExactRoot);

        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('atanh(x)=acoth(1)', 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atanh(x)=acoth(1)', 'x'));
        try {
            Math::countPolynomialRootsInRationalInterval(
                'atanh(1/(1+x^2))=acoth(1+x^2)',
                'x',
                '-2',
                '2',
            );
            self::fail('An identity on open subintervals must be reported as infinite.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        try {
            Math::isolatePolynomialRootsOnRealLine('atanh(1/(1+x^2))=acoth(1+x^2)', 'x');
            self::fail('All-real isolation must report an infinite identity solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        try {
            Math::countPolynomialRootsOnRealLine('atanh(1/(1+x^2))=acoth(1+x^2)', 'x');
            self::fail('An identity on open real intervals must be reported as infinite.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testAtanhAsinhEqualityPreservesSignsAndReciprocalHoles(): void
    {
        $equation = 'atanh(x/2)=asinh(x)';
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        self::assertSame('0', $roots[1]->lowerNumerator);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('arctanh(x/2)=arcsinh(-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atanh(x)=asinh(1)', 'x'));
        $acschEquation = 'acsch(2)=atanh(x/2)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($acschEquation, 'x'));
        $acschRoots = Math::isolatePolynomialRootsInRationalInterval($acschEquation, 'x', '-2', '2');
        self::assertNotNull($acschRoots);
        self::assertCount(1, $acschRoots);

        $hole = 'atanh(x)=acsch(1/x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolatedIdentity = 'atanh(-2*x/(1+x^2))=asinh(2*x/(1-x^2))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolatedIdentity, 'x', '-1/2', '1/2'));
        $identityRoots = Math::isolatePolynomialRootsInRationalInterval($isolatedIdentity, 'x', '-1/2', '1/2');
        self::assertNotNull($identityRoots);
        self::assertCount(1, $identityRoots);
        self::assertSame('0', $identityRoots[0]->lowerNumerator);
    }

    public function testAtanhAsinhIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'atanh(2*x/(1+x^2))=asinh(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testAsinAtanAndAcscAtanEqualitiesPreserveBranchesAndHoles(): void
    {
        foreach (['asin(x/2)=atan(x)', 'arctan(x)=arcsin(x/2)'] as $equation) {
            self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(3, $roots);
            self::assertSame('0', $roots[1]->lowerNumerator);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(x/2)=atan(-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(x)=atan(0)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acsc(x)=atan(1)', 'x'));
        $acscRoots = Math::isolatePolynomialRootsInRationalInterval('acsc(x)=atan(1)', 'x', '1', '2');
        self::assertNotNull($acscRoots);
        self::assertCount(1, $acscRoots);
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asin(x)=atan(1/x)', 'x'));

        $hole = 'acsc(1/x)=atan(x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolated = 'asin(-2*x/(1+x^2))=atan(2*x/(1-x^2))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-1/2', '1/2'));
        $isolatedRoots = Math::isolatePolynomialRootsInRationalInterval($isolated, 'x', '-1/2', '1/2');
        self::assertNotNull($isolatedRoots);
        self::assertCount(1, $isolatedRoots);
        self::assertSame('0', $isolatedRoots[0]->lowerNumerator);
    }

    public function testAsinAtanIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'asin(2*x/(1+x^2))=atan(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testAcosAcotAndAsecAcotEqualitiesPreserveBranchesAndHoles(): void
    {
        foreach (['acos(x/2)=acot(x)', 'acot(x)=arccos(x/2)'] as $equation) {
            self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(3, $roots);
            self::assertSame('0', $roots[1]->lowerNumerator);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acos(x/2)=acot(-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acos(x)=acot(0)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asec(x)=acot(1)', 'x'));
        $asecRoots = Math::isolatePolynomialRootsInRationalInterval('asec(x)=acot(1)', 'x', '1', '2');
        self::assertNotNull($asecRoots);
        self::assertCount(1, $asecRoots);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asec(x)=acot(0)', 'x'));

        $hole = 'asec(1/x)=acot(x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolated = 'acos(-2*x/(1+x^2))=acot(2*x/(1-x^2))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-1/2', '1/2'));
        $isolatedRoots = Math::isolatePolynomialRootsInRationalInterval($isolated, 'x', '-1/2', '1/2');
        self::assertNotNull($isolatedRoots);
        self::assertCount(1, $isolatedRoots);
        self::assertSame('0', $isolatedRoots[0]->lowerNumerator);
    }

    public function testAcosAcotIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'acos(2*x/(1+x^2))=acot(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testComplementaryInverseTrigEqualitiesPreservePositiveBranches(): void
    {
        foreach (
            [
                ['asin(x)=acot(1)', '0', '1'],
                ['acot(1)=arcsin(x)', '0', '1'],
                ['acos(x)=atan(1)', '0', '1'],
                ['arctan(1)=arccos(x)', '0', '1'],
                ['acsc(x)=acot(1)', '1', '2'],
                ['asec(x)=atan(1)', '1', '2'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        foreach (['asin(x)=acot(0)', 'acos(x)=atan(0)', 'acsc(x)=acot(0)', 'asec(x)=atan(0)'] as $boundary) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($boundary, 'x'));
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(0)=acot(x)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acos(x)=atan(-1)', 'x'));
        $hole = 'asin((x-1)/(x-1))=acot(x-1)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolated = 'acos((1-x^4)/(1+x^4))=atan(-2*x^2/(1-x^4))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-2', '2'));
        $isolatedRoots = Math::isolatePolynomialRootsOnRealLine($isolated, 'x');
        self::assertNotNull($isolatedRoots);
        self::assertCount(1, $isolatedRoots);
        self::assertSame('0', $isolatedRoots[0]->lowerNumerator);
        self::assertTrue($isolatedRoots[0]->isExactRoot);
    }

    public function testComplementaryInverseTrigIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'asin((1-x^2)/(1+x^2))=acot(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testInverseSineCosineEqualitiesPreserveQuadrantAndPoles(): void
    {
        foreach (
            [
                ['asin(x)=acos(x)', '0', '1'],
                ['arccos(x)=arcsin(x)', '0', '1'],
                ['acsc(x)=asec(x)', '1', '2'],
                ['asin(x)=asec(2)', '0', '1'],
                ['acsc(2)=acos(x)', '0', '1'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(x)=acos(1)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(1)=acos(x)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(x)=acos(-x)', 'x'));
        $hole = 'asin(x)=acos((1+x/x)/2)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));

        $isolated = 'asin(-2*x^2/(1+x^4))=acos((1-x^4)/(1+x^4))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-2', '2'));
        $isolatedRoots = Math::isolatePolynomialRootsOnRealLine($isolated, 'x');
        self::assertNotNull($isolatedRoots);
        self::assertCount(1, $isolatedRoots);
        self::assertSame('0', $isolatedRoots[0]->lowerNumerator);
        self::assertTrue($isolatedRoots[0]->isExactRoot);
    }

    public function testInverseSineCosineIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'asin(2*x/(1+x^2))=acos((1-x^2)/(1+x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testAtanAcotEqualityPreservesPositiveBranchAndRationalHoles(): void
    {
        foreach (['atan(x)=acot(x/2)', 'acot(x/2)=arctan(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        $boundary = 'atan(x)=acot(x)';
        $boundaryRoots = Math::isolatePolynomialRootsInRationalInterval($boundary, 'x', '0', '2');
        self::assertNotNull($boundaryRoots);
        self::assertCount(1, $boundaryRoots);
        self::assertTrue($boundaryRoots[0]->isExactRoot);
        self::assertSame('1', $boundaryRoots[0]->lowerNumerator);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(-x)=acot(-1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(0)=acot(x)', 'x'));

        $hole = 'atan(x)=acot((x-1)/(x-1))';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '0', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));
    }

    public function testAtanAcotIdentityDistinguishesPositiveAndNegativeIntervals(): void
    {
        $equation = 'atan(x)=acot(1/x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '0'));
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
    }

    public function testReciprocalInverseTrigEqualitiesIncludeEndpointsAndExcludeHoles(): void
    {
        foreach (['asin(x)=acsc(x)', 'acsc(x)=arcsin(x)', 'acos(x)=asec(x)', 'asec(x)=arccos(x)'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            self::assertSame('-1', $roots[0]->lowerNumerator);
            self::assertSame('1', $roots[1]->lowerNumerator);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(x)=acsc(x/4)', 'x'));
        $constantLevel = Math::isolatePolynomialRootsInRationalInterval('acos(x)=asec(2)', 'x', '0', '1');
        self::assertNotNull($constantLevel);
        self::assertCount(1, $constantLevel);
        self::assertSame('1', $constantLevel[0]->lowerNumerator);
        self::assertSame('2', $constantLevel[0]->lowerDenominator);

        $isolated = 'asin(1+x^2)=acsc(1/(1+x^2))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($isolated, 'x'));
        $isolatedRoots = Math::isolatePolynomialRootsOnRealLine($isolated, 'x');
        self::assertNotNull($isolatedRoots);
        self::assertCount(1, $isolatedRoots);
        self::assertSame('0', $isolatedRoots[0]->lowerNumerator);

        $hole = 'acos(1+x^2)=asec((1/(1+x^2))*(x/x))';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));
    }

    public function testReciprocalInverseTrigIdentityFindsItsClosedDomain(): void
    {
        $equation = 'asin(x)=acsc(1/x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '2', '3'));
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
    }

    public function testAcothAsinhAndAcschEqualitiesPreserveBranchesAndHoles(): void
    {
        foreach (['acoth(x)=asinh(1)', 'arcsinh(1)=acoth(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '1', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('acoth(x)=asinh(-1)', 'x', '-2', '-1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acoth(x)=asinh(0)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acoth(x)=acsch(x)', 'x'));
        $acschEquation = 'acoth(x)=acsch(2)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($acschEquation, 'x', '2', '3'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($acschEquation, 'x'));
        $acschRoots = Math::isolatePolynomialRootsInRationalInterval($acschEquation, 'x', '2', '3');
        self::assertNotNull($acschRoots);
        self::assertCount(1, $acschRoots);

        $reciprocalHole = 'acoth(1/x)=asinh(x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($reciprocalHole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($reciprocalHole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($reciprocalHole, 'x'));
    }

    public function testAcothAsinhIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'acoth((1+x^2)/(2*x))=asinh(2*x/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testAtanhAcoshAndReciprocalEqualitiesPreserveBranches(): void
    {
        foreach (
            [
                ['atanh(x)=acosh(2)', '0', '1'],
                ['asech(1/2)=arctanh(x)', '0', '1'],
                ['acoth(x)=arccosh(2)', '1', '2'],
                ['asech(1/2)=acoth(x)', '1', '2'],
            ] as [$equation, $lower, $upper]
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atanh(x)=acosh(1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atanh(x)=acosh(-2)', 'x'));
        $hole = 'atanh(x)=acosh(x/x)';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '-1', '1'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));

        $isolated = 'atanh(-2*x^2/(1+x^4))=acosh((1+x^4)/(1-x^4))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($isolated, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($isolated, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('0', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testAtanhAcoshIdentityReportsInfiniteOpenSolutionCells(): void
    {
        $equation = 'atanh(2*x/(1+x^2))=acosh((1+x^2)/(1-x^2))';
        try {
            Math::countPolynomialRootsOnRealLine($equation, 'x');
            self::fail('Expected an infinite all-real solution set.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/2', '1/2');
    }

    public function testAcschPolynomialEqualityExcludesTheZeroBoundary(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acsch(x)=acsch(-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acsch(x)=acsch(2-x)', 'x'));
    }

    public function testAsechPolynomialEqualitySeparatesItsTwoBoundaries(): void
    {
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asech(x)=asech(-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asech(x)=asech(2-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('asech(x)=asech(2-x)', 'x', '0', '2'));

        $equation = 'asech(x)=asech(-x^2+5*x/2-1/2)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertSame('2', $roots[0]->lowerDenominator);
        self::assertSame('1', $roots[1]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerDenominator);
    }

    public function testArcSecantAndCosecantEqualityIncludesDomainBoundaries(): void
    {
        foreach (['asec', 'acsc'] as $function) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine("{$function}(x)={$function}(2-x)", 'x'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine("{$function}(x)={$function}(-2-x)", 'x'));
            self::assertSame(0, Math::countPolynomialRootsOnRealLine("{$function}(x)={$function}(-x)", 'x'));
        }

        $roots = Math::isolatePolynomialRootsOnRealLine('asec(x)=asec(2-x)', 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testSquareRootPolynomialEqualityUsesThePrincipalDomain(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sqrt(x)=sqrt(-x)', 'x'));
        $boundaryRoot = Math::isolatePolynomialRootsOnRealLine('sqrt(x)=sqrt(-x)', 'x');
        self::assertNotNull($boundaryRoot);
        self::assertCount(1, $boundaryRoot);
        self::assertSame('0', $boundaryRoot[0]->lowerNumerator);
        self::assertTrue($boundaryRoot[0]->isExactRoot);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sqrt(x-2)=sqrt(-x)', 'x'));
        $equation = 'sqrt(x^2-1)=sqrt(3-x^2)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '0'));
    }

    public function testEqualConstantBaseLogarithmsReduceOnPositiveArguments(): void
    {
        $equation = 'log(x^2-1,2)=log(3-x^2,2)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '0'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log(x,2)=log(2-x,2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log(x,1/2)=log(2-x,1/2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x-2,2)=log(-x,2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x,1)=log(2-x,1)', 'x'));
    }

    public function testEquivalentLogarithmBasesAcrossFunctionNamesReduceExactly(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln(x)=log(2-x,e)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log10(x)=log(2-x,10)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('log2(x^2-1)=log(3-x^2,2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x-2)=log(-x,e)', 'x'));
    }

    public function testGlobalInverseFunctionsReduceEqualArgumentsAcrossAliases(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asinh(x)=arcsinh(2-x)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2)=arctan(2-x^2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'asinh(x^2)=arcsinh(2-x^2)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('atan(x^2)=arctan(1-x^2)', 'x'));

        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(x)=arcsin(2-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acos(x)=arccos(2-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('acot(x)=acot(2-x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('asin(x^2-1)=arcsin(2*x-1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('asin(x^2+2)=arcsin(3-x^2)', 'x'));
    }

    public function testCschAndCothEqualitiesRespectTheirPuncturedDomains(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('csch(x)=csch(2-x)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('coth(x^2)=coth(1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('csch(x)=csch(-x)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('coth(x)=coth(-x)', 'x'));

        $rationalPhaseEquality = 'csch(x^3-x)=csch((x-1)/(x+2))';
        $cschRoots = Math::isolatePolynomialRootsInRationalInterval($rationalPhaseEquality, 'x', '-3', '3');
        self::assertNotNull($cschRoots);
        self::assertCount(1, $cschRoots);
        self::assertFalse($cschRoots[0]->isExactRoot);

        $cothRoots = Math::isolatePolynomialRootsInRationalInterval(
            'coth(x^3-x)=coth((x-1)/(x+2))',
            'x',
            '-3',
            '3',
        );
        self::assertNotNull($cothRoots);
        self::assertCount(1, $cothRoots);
        self::assertSame($cschRoots[0]->lowerNumerator, $cothRoots[0]->lowerNumerator);
        self::assertSame($cschRoots[0]->upperNumerator, $cothRoots[0]->upperNumerator);

        $roots = Math::isolatePolynomialRootsOnRealLine('csch(x)=csch(2-x)', 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
    }

    public function testCoshEqualitiesReduceRationalPhasesAcrossBothSigns(): void
    {
        $equation = 'cosh(x^2)=cosh(x)';
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(3, $roots);
        foreach ($roots as $root) {
            self::assertTrue($root->isExactRoot);
        }
        self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $allRealRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($allRealRoots);
        self::assertCount(3, $allRealRoots);

        $mixedPhases = 'cosh(x^3-x)=cosh((x-1)/(x+2))';
        self::assertSame(3, Math::countPolynomialRootsInRationalInterval($mixedPhases, 'x', '-3', '3'));
        $mixedRoots = Math::isolatePolynomialRootsInRationalInterval($mixedPhases, 'x', '-3', '3');
        self::assertNotNull($mixedRoots);
        self::assertCount(3, $mixedRoots);

        $punctured = 'cosh((x^2-1)/(x-1))=cosh(2)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($punctured, 'x', '-4', '4'));
        $puncturedRoots = Math::isolatePolynomialRootsInRationalInterval($punctured, 'x', '-4', '4');
        self::assertNotNull($puncturedRoots);
        self::assertCount(1, $puncturedRoots);
        self::assertSame('-3', $puncturedRoots[0]->lowerNumerator);
        self::assertSame('1', $puncturedRoots[0]->lowerDenominator);
    }

    public function testCoshAndSechEqualityRequiresBothPhasesToVanish(): void
    {
        $commonZero = 'cosh(x^2-1)=sech(x-1)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($commonZero, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($commonZero, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('1', $roots[0]->lowerNumerator);

        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('cosh(x)=sech(x-1)', 'x', '-2', '2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('sech(x-1)=cosh(x^2-1)', 'x', '-2', '2'),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($commonZero, 'x'));

        $undefinedCommonZero = 'cosh((x^2-1)/(x-1))=sech(x-1)';
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval($undefinedCommonZero, 'x', '-2', '2'),
        );
    }

    public function testSineHyperbolicAndTangentHyperbolicEqualitiesReduceMatchingPhases(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh(x)=tanh(x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sinh(x)=tanh(x)', 'x', '-1', '1'));

        $oppositePhases = 'sinh(x^2-1)=tanh(1-x^2)';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($oppositePhases, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($oppositePhases, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);

        $rationalPhase = 'sinh(x/(x-1))=tanh(x/(x-1))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rationalPhase, 'x', '-2', '2'));
        $rationalRoots = Math::isolatePolynomialRootsInRationalInterval($rationalPhase, 'x', '-2', '2');
        self::assertNotNull($rationalRoots);
        self::assertCount(1, $rationalRoots);
        self::assertTrue($rationalRoots[0]->isExactRoot);
        self::assertSame('0', $rationalRoots[0]->lowerNumerator);

        $rationalHole = 'sinh((x^2-1)/(x-1))=tanh((x^2-1)/(x-1))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rationalHole, 'x', '-2', '2'));
        $rationalHoleRoots = Math::isolatePolynomialRootsInRationalInterval($rationalHole, 'x', '-2', '2');
        self::assertNotNull($rationalHoleRoots);
        self::assertCount(1, $rationalHoleRoots);
        self::assertSame('-1', $rationalHoleRoots[0]->lowerNumerator);
    }

    public function testInverseHyperbolicCosecantMatchesInverseSineReciprocalExactly(): void
    {
        foreach (['asinh(x)=acsch(x)', 'acsch(x)=arcsinh(x)'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            self::assertSame('-1', $roots[0]->lowerNumerator);
            self::assertSame('1', $roots[1]->lowerNumerator);
        }

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'asinh(x^2)=acsch(x)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('asinh(x)=acsch(x)', 'x'));

        $rationalPole = 'asinh((x-1)^2)=acsch(1/(x-1))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rationalPole, 'x', '0', '3'));
        $rationalPoleRoots = Math::isolatePolynomialRootsInRationalInterval($rationalPole, 'x', '0', '3');
        self::assertNotNull($rationalPoleRoots);
        self::assertCount(1, $rationalPoleRoots);
        self::assertSame('2', $rationalPoleRoots[0]->lowerNumerator);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'acsch(1/(x-1))=asinh((x-1)^2)',
                'x',
                '0',
                '3',
            ),
        );

        foreach (['asinh(x)=acsch(1/x)', 'acsch(x)=asinh(1/x)'] as $identity) {
            try {
                Math::countPolynomialRootsInRationalInterval($identity, 'x', '-2', '2');
                self::fail('Expected the rational-phase inverse identity to have infinitely many roots.');
            } catch (InfiniteSolutionSetException) {
            }
        }
    }

    public function testInverseHyperbolicCosineAndSecantMeetOnlyAtOne(): void
    {
        foreach (
            [
                'acosh(x)=asech(x)',
                'asech(x)=arccosh(x)',
                'acosh(x^2)=asech(x^2)',
            ] as $equation
        ) {
            $expectedRoots = \str_contains($equation, 'x^2') ? 2 : 1;
            self::assertSame(
                $expectedRoots,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );
        }
        $quadraticRoots = Math::isolatePolynomialRootsInRationalInterval(
            'acosh(x^2)=asech(x^2)',
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($quadraticRoots);
        self::assertCount(2, $quadraticRoots);
        self::assertSame('-1', $quadraticRoots[0]->lowerNumerator);
        self::assertSame('1', $quadraticRoots[1]->lowerNumerator);

        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'acosh(x*(x-1)/(x-1))=asech(x*(x-1)/(x-1))',
                'x',
                '-2',
                '2',
            ),
        );
    }

    public function testInverseHyperbolicTangentAndCotangentHaveDisjointMatchedDomains(): void
    {
        foreach (
            [
                'atanh(x)=acoth(x)',
                'arctanh(x)=acoth(-x)',
                'acoth(x^2)=atanh(x^2)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        }
    }

    public function testHyperbolicTangentAndCotangentNeverMeetAtMatchedPhases(): void
    {
        foreach (
            [
                'tanh(x)=coth(x)',
                'tanh(x)=coth(-x)',
                'coth(x^2)=tanh(x^2)',
                'coth(-x)=tanh(x)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        }
    }

    public function testHyperbolicTangentAndCotangentHaveDisjointRealRanges(): void
    {
        foreach (
            [
                'tanh(x)=coth(2*x+1)',
                'coth(x^2+1)=tanh(x)',
                'tanh(sin(x))=coth(exp(x))',
                'tanh(x)=coth(1/(x-1))',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('tanh(a*x)=coth(x+1)', 'x', ['a' => 2]));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine(
                'tanh(sin(x))=coth(exp(x))',
                'x',
                solverOptions: new SolverOptions(maxPolynomialDegree: 1),
            ),
        );
        self::assertNull(Math::countPolynomialRootsOnRealLine('tanh(mystery(x))=coth(x)', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('tanh(y)=coth(x)', 'x'));
    }

    public function testDisjointHyperbolicEqualityStillRequiresTheRootVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Math::countPolynomialRootsOnRealLine('tanh(1)=coth(1)', 'x');
    }

    public function testHyperbolicRangesExcludeBoundedFunctionEqualities(): void
    {
        foreach (
            [
                'cosh(x)=tanh(2*x)',
                'erf(sin(x))=cosh(exp(x))',
                'coth(sin(x))=cos(x)',
                'sin(x)=coth(x^2)',
                'sech(x)=coth(x^2+1)',
                'erf(x)=coth(2*x+1)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine('cosh(mystery(x))=erf(x)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cosh(x)=sech(x)', 'x', '-1', '1'));
    }

    public function testCoshTrigonometricEqualitiesUseAlgebraicPhaseCandidates(): void
    {
        foreach (['cosh(x)=cos(x)', 'cos(x)=cosh(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertSame('0', $roots[0]->lowerNumerator);
        }
        $rational = 'cosh((x^2-1)/(x-1))=cos(x+1)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rational, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($rational, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('cosh((x-1)/(x-1))=cos(x)', 'x'));
        foreach (['cosh(x)=sin(x)', 'sin(x+1)=cosh(x^2-1)'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
        foreach (
            [
                'cosh(0*x)=cos(x)',
                'cos(x)=cosh(x-x)',
                'cosh(0*x)=sin(x)',
                'cosh((x-x)/(1+x^2))=cos(x)',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '13'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '13');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
        }
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/(x-1))=cos(x)',
            'x',
            '0',
            '13',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/x)=cos(x)',
            'x',
            '0',
            '13',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/x)=sin(x)',
            'x',
            '0',
            '13',
        ));
        self::assertNotSame(0, Math::countPolynomialRootsOnRealLine('cosh(x-1)=cos(2*pi*x)', 'x'));
    }

    public function testCoshCosineZeroPhaseIdentityHasInfiniteSolutionsDespiteHoles(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/(x-1))=cos((x-x)/(x+1))',
            'x',
            '-2',
            '2',
        );
    }

    public function testCoshWithConstantRationalTrigPhaseHasNoRoots(): void
    {
        foreach (
            [
                'cosh(0*x)=sin(1)',
                'cos(1/2)=cosh(x^2)',
                'cosh(x)=sin((x^2+1)/(x^2+1))',
                'cosh((x-x)/(x-1))=cos(1)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        }
    }

    public function testCoshWithNonzeroRationalPhaseExceedsTrigonometricRange(): void
    {
        foreach (
            [
                'cosh(1)=cos(pi*x)',
                'sin(exp(x))=cosh((x^2+1)/(x^2+1))',
                'cosh((x-1)/(x-1))=cos(sqrt(x))',
                'cosh(x^2+1)=cos(pi*x)',
                'sin(exp(x))=cosh((x^2+1)/(x-1))',
                'cosh(((x-1)*(x^2+1))/(x-1))=cos(pi*x)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine('cosh(1)=cos(mystery(x))', 'x'));
        self::assertNotSame(0, Math::countPolynomialRootsOnRealLine('cosh(x)=cos(2*pi*x)', 'x'));
        self::assertNotSame(
            0,
            Math::countPolynomialRootsOnRealLine('cosh(((x-1)*(x-2))/(x-1))=cos(2*pi*x)', 'x'),
        );
    }

    public function testCoshSechEqualityRequiresBothPhasesToVanish(): void
    {
        foreach (
            [
                'cosh(x^2+1)=sech(exp(x))',
                'sech(x^2+1)=cosh(exp(x))',
                'cosh(exp(x))=sech(((x-1)*(x^2+1))/(x-1))',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cosh(x)=sech(x)', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('cosh(x^2+1)=sech(mystery(x))', 'x'));
    }

    public function testCoshSechWithZeroPhaseReducesToOtherPhaseZeros(): void
    {
        foreach (
            [
                'cosh(0*x)=sech(sin(x))',
                'sech(0*x)=cosh(sin(x))',
                'cosh(0*x)=cosh(sin(x))',
                'sech(sin(x))=sech(0*x)',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
        }
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/(x-1))=sech(sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/x)=sech(sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cosh(x^2+1)=cosh(1)', 'x'));
    }

    public function testInjectiveHyperbolicFunctionsReduceToPhaseEquality(): void
    {
        foreach (['sinh', 'tanh', 'asinh', 'arcsinh'] as $function) {
            $equation = $function . '(sin(x))=' . $function . '(0)';
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sinh(x/(x-1))=sinh(1/(x-1))', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('sinh(mystery(x))=sinh(0)', 'x'));
    }

    public function testGloballyInjectiveFunctionsReduceToPhaseEquality(): void
    {
        foreach (['exp', 'expm1', 'cbrt', 'atan', 'arctan', 'acot', 'erf', 'erfc', 'erfcx', 'erfi'] as $function) {
            $equation = $function . '(sin(x))=' . $function . '(0)';
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(x/(x-1))=exp(1/(x-1))', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('erf(mystery(x))=erf(0)', 'x'));
    }

    public function testInjectiveFunctionAliasesReduceToPhaseEquality(): void
    {
        foreach (['atan(sin(x))=arctan(0)', 'arcsinh(sin(x))=asinh(0)'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('atan(x/(x-1))=arctan(1/(x-1))', 'x'));
    }

    public function testInjectiveEqualityPreservesInnerFunctionDomains(): void
    {
        foreach (['exp(sqrt(x))=exp(1)', 'exp(ln(x))=exp(0)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        }
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('exp(sqrt(x))=exp(-1)', 'x', '-2', '2'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('exp(sqrt(x))=exp(-1)', 'x'));
    }

    public function testDifferentZeroPreservingFunctionsReduceZeroPhase(): void
    {
        foreach (['sinh(0*x)=atan(sin(x))', 'expm1(sin(x))=erf(0*x)'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
        }
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sinh((x-x)/(x-1))=atan(sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNull(Math::countPolynomialRootsOnRealLine('sinh(0*x)=atan(mystery(x))', 'x'));
    }

    public function testPeriodicZerosAvoidAlgebraicHolesWhenCertified(): void
    {
        foreach (
            [
                'atan((x-x)/(x-1))=abs(sin(x))' => 2,
                'atan((x-x)/(x-1))=abs(cos(x))' => 1,
                'atan((x-x)/x)=abs(cot(x))' => 1,
                'atan((x-x)/x)=abs(sinc(x))' => 1,
            ] as $equation => $count
        ) {
            self::assertSame($count, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount($count, $roots);
        }
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan((x-x)/x)=abs(sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicPoleProofHandlesAlgebraicAndCosineCases(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/x)=sech(cos(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/(x^2-2))=sech(sin(x^2-2))',
            'x',
            '-2',
            '2',
        ));
    }

    public function testPeriodicPoleProofRecognizesSharedArgumentHole(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('atan((x-x)/x)=abs(sin((x-x)/x))', 'x');
    }

    public function testZeroPhasePoleProofRecognizesZeroFreeBuiltins(): void
    {
        foreach (
            ['sec(exp(x))', 'csc(sin(x))', 'exp(sin(x))', 'csch(sin(x))', 'coth(sin(x))', 'gamma(sin(x))'] as $phase
        ) {
            $equation = 'atan((x-x)/x)=abs(' . $phase . ')';
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine('atan((x-x)/x)=abs(mystery(x))', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine('atan((x-x)/x)=abs(gamma(mystery(x)))', 'x'));
    }

    public function testNestedUniqueZeroFunctionsReduceToInnermostPhase(): void
    {
        foreach (
            [
                'atan(0*x)=abs(sqrt(abs(sin(x))))' => 2,
                'cosh((x-x)/x)=sech(abs(cos(x)))' => 1,
                'atan(0*x)=abs(sin(x)^2)' => 2,
                'cosh((x-x)/x)=sech(cos(x)^4)' => 1,
                'atan(0*x)=abs(sin(x)^(1/2))' => 2,
                'atan(0*x)=abs(-sin(x))' => 2,
                'cosh((x-x)/x)=sech(-cos(x))' => 1,
            ] as $equation => $count
        ) {
            self::assertSame($count, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount($count, $roots);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('atan(0*x)=abs(ln(x+1))', 'x'));
        self::assertNotSame(
            2,
            Math::countPolynomialRootsInRationalInterval('atan(0*x)=abs(sin(x)^-2)', 'x', '-1', '4'),
        );
        self::assertNotSame(
            2,
            Math::countPolynomialRootsInRationalInterval('atan(0*x)=abs(sin(x)^(0))', 'x', '-1', '4'),
        );
    }

    public function testDirectZeroEquationsUnwrapNestedPhases(): void
    {
        foreach (
            [
                'sin(x)^2=0',
                '0=abs(sqrt(abs(sin(x))))',
                'sin(x)*(x^2+1)=0',
                'sin(x)^2=x-x',
                'sin(x)^2=(x-x)/(x-1)',
                'sin(x)^2=(x-x)/(x^2+1)',
                'sin(x)=(x-x)/(x-1)',
                '(x-x)/(x-1)=tan(x)',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertNull(Math::countPolynomialRootsInRationalInterval('sin(x)^2=1/0', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)^-2=0', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)^2=(x-x)/x', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=(x-x)/x', 'x', '-1', '4'));
    }

    public function testDirectZeroReductionValidatesTargetResourceLimits(): void
    {
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'sin(x)^(1/2)=2^2-4',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'sin(x)^(1/2)=sqrt(2^2-4)',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
    }

    public function testMixedZeroPhaseReductionValidatesResourceLimits(): void
    {
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (['atan(2^2-4)=abs(sin(x))', 'cosh(2^2-4)=sech(sin(x))'] as $equation) {
            self::assertNull(Math::countPolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                evaluationOptions: $options,
            ));
        }
    }

    public function testPeriodicTargetsRespectEvaluationLimits(): void
    {
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (
            [
                'cosh(2^2-4)=cos(x)',
                'cosh(2^2-4)=cos(x^2)',
                'sin(x)=x-x+(2^2-4)',
            ] as $equation
        ) {
            self::assertNull(Math::countPolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::countPolynomialRootsOnRealLine($equation, 'x', evaluationOptions: $options));
            self::assertNull(Math::isolatePolynomialRootsOnRealLine($equation, 'x', evaluationOptions: $options));
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'cosh(2^2-4)=cos(x)',
            'x',
            evaluationOptions: $options,
        ));
        self::assertNull(Math::isolatePolynomialRootsOnRealLine(
            'cosh(2^2-4)=cos(x)',
            'x',
            evaluationOptions: $options,
        ));
    }

    public function testPeriodicPolynomialPhasesRespectEvaluationLimits(): void
    {
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (['sin(x^2)=0', 'sin(x+(2^2-4))=0'] as $equation) {
            self::assertNull(Math::countPolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                evaluationOptions: $options,
            ));
            self::assertNull(Math::countPolynomialRootsOnRealLine($equation, 'x', evaluationOptions: $options));
            self::assertNull(Math::isolatePolynomialRootsOnRealLine($equation, 'x', evaluationOptions: $options));
        }
    }

    public function testPeriodicVariableTargetCancellationStillHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x)=x-x', 'x');
    }

    public function testDirectNestedZeroWithVariableTargetHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x)^2=x-x', 'x');
    }

    public function testDirectNestedZeroWithCertifiedTargetHoleHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x)^2=(x-x)/(x-1)', 'x');
    }

    public function testDirectNestedRationalPhasePreservesTargetHoles(): void
    {
        $equation = 'abs(x^2-1)=(x-x)/(x-1)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($boundedRoots);
        self::assertCount(1, $boundedRoots);
        self::assertSame('-1', $boundedRoots[0]->lowerNumerator);
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('(x-x)/(x-1)=abs(x^2-1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('abs(x-1)=(x-x)/(x-1)', 'x'));
    }

    public function testDirectZeroReductionSupportsWrappedZeroTargets(): void
    {
        foreach (
            [
                'sin(x)^2=atan(x-x)',
                'sin(x)=sqrt((x-x)/(x-1))',
                'sin(x)=(abs((x-x)/(x-1)))^(1/2)',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=sqrt((x-x)/x)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'abs(x^2-1)=atan((x-x)/(x-1))',
            'x',
        ));
        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sin(x)=sqrt((x-x)/(x-1))',
            'x',
            '-1',
            '4',
        );
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
    }

    public function testDirectZeroReductionSupportsZeroTimesDefinedFunctions(): void
    {
        foreach (
            [
                'sin(x)=0*cos(x)',
                'sin(x)^2=(x-x)*atan(x)',
                'sin(x)=0*sincpi(x)',
                'sin(x)=0*dawson(x)',
                'sin(x)=0*sech(x)',
                'sin(x)=0/exp(x)',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*cos(1/(x-1))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=((x-x)/(x-2))*cos(1/(x-1))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*cos(1/x)', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*ln(x)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0*csch(x)', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*coth(x)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csch(x)', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0/sin(x)', 'x', '-1', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*sec(x)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csc(x)', 'x', '-1', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*sec(-x)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csc(-x)', 'x', '-1', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*sec(2*x)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csc(3*x)', 'x', '-1', '4'));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csc(2*x)', 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*csc(x)', 'x', '-1', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*sec(x+1)', 'x', '-1', '4'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('cos(x)=0/csc(1-x)', 'x', '-1', '4'));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval('sin(x)=0*sec(2*x+1)', 'x', '-1', '4'));
    }

    public function testZeroProductPhaseMultipliersSupportIntegerParameters(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec(k*x)',
            'x',
            '-1',
            '4',
            parameters: ['k' => 2],
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0/csc(k*x)',
            'x',
            '-1',
            '4',
            parameters: ['k' => -3],
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0/csc(k*x)',
            'x',
            '-1',
            '4',
            parameters: ['k' => 2],
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec(k*x)',
            'x',
            '-1',
            '4',
            parameters: ['k' => 0.5],
        ));
    }

    public function testZeroProductPhaseMultipliersSupportEquivalentRationalForms(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec(x+x)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x+1)=0/csc(3*x+3)',
            'x',
            '-2',
            '2',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x+1)=0/csc(2*x+2)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x+1)=0*sec(2*x+1)',
            'x',
            '-2',
            '4',
        ));
    }

    public function testZeroProductShiftedNonlinearPolynomialPhases(): void
    {
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(2*x^2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(x^2/2)',
            'x',
            '0',
            '2',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'sin(x^2+x)=0*sec(2*x^2+3*x+1)',
            'x',
            '-1',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec((x^2)^2+x^2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec((x^2)^2)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(x^4+x^2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(x^4)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2+x)=0*sec(x^4+2*x^3+x^2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2+x)=0*sec((x^4+2*x^3+x^2)/2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'sin(x^2+x)=0*sec(x^4+2*x^3+x^2+x)',
            'x',
            '0',
            '2',
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(x^4+x)',
            'x',
            '0',
            '2',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec((x^4-x^4+x^2)/2)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x^2)=0/csc((x^2)^2)',
            'x',
            '0',
            '2',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'sin(x^2)=0*sec(((x^2)^2-(x^2)^2+x^2)/2)',
            'x',
            '0',
            '2',
        ));
    }

    public function testZeroProductPolynomialInPhaseHasInfiniteRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x^2)=0*sec(x^4+x^2+1)', 'x');
    }

    public function testCrossPhaseZeroProductIsolationAndDomainHoles(): void
    {
        $roots = Math::isolatePolynomialRootsInRationalInterval(
            'sin(x)=0*csc(x+1)',
            'x',
            '-1',
            '4',
        );
        self::assertCount(2, $roots);
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=(0/x)*csc(x+1)',
            'x',
            '-1',
            '4',
        ));
    }

    public function testUnreachableTrigonometricLevelsWithRationalPhases(): void
    {
        $equations = [
            'sin(1/x)=2', 'cos(1/x)=-2', 'sec(1/x)=1/2', 'csc(1/x)=0', 'sinc(1/x)=2', 'sincpi(1/x)=-2',
            'sinc(1/x)=-1', 'sincpi(1/x)=-1',
        ];
        foreach ($equations as $equation) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        }
        self::assertNull(Math::countPolynomialRootsInRationalInterval('sin(1/x)=0', 'x', '1/4', '1/2'));
        self::assertNotSame(0, Math::countPolynomialRootsInRationalInterval('sinc(x)=1', 'x', '-1', '1'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('sin(x)=-1', 'x', '-2', '0'));
        foreach (['sinc(1/x)=-1', 'sincpi(1/x)=-1'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(exp(x))=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(ln(x))=2', 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('sin(ln(x))=2', 'x'));
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertNull(Math::countPolynomialRootsOnRealLine('sinc(x^2)=-1', 'x', [], $limited));
        self::assertNull(Math::isolatePolynomialRootsOnRealLine('sinc(x^2)=-1', 'x', [], $limited));
        foreach (
            ['sin(x^2)=2', 'cos(x^2)=-2', 'sinc(x^2)=2', 'sincpi(x^2)=-2',
                'sec(x^2)=0', 'csc(x^2)=0', 'cosh(x^2)=0'] as $equation
        ) {
            self::assertNull(Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertNull(Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
        }
    }

    public function testConstantExponentLimitsApplyBeforeEquationReductions(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (
            ['exp(x^2)=1', 'expm1(x^2)=0', 'exp(x)=1^2', 'cosh(x)=0^2',
                'sin(x^(-2))=2', 'exp(x^(3/2))=1', 'exp(x^(1+1))=1', 'exp(x^(x-x+2))=1',
                'exp(x^((x+1)^2))=1'] as $equation
        ) {
            self::assertNull(Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertNull(Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertNull(Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
            self::assertNull(Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(x)=1', 'x', [], $limited));
        self::assertNull(Math::countPolynomialRootsOnRealLine('exp(x^p)=1', 'x', ['p' => 2], $limited));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(x^p)=1', 'x', ['p' => 1], $limited));
        $fractional = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.5));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('exp(x^1)=1', 'x', [], $fractional));
        self::assertNull(Math::countPolynomialRootsOnRealLine('exp(x^2)=1', 'x', [], $fractional));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(x^(3/2))=2', 'x', [], $fractional));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(x^(-3/2))=2', 'x', [], $fractional));
        $zero = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0));
        self::assertNull(Math::countPolynomialRootsOnRealLine('exp(x^1)=1', 'x', [], $zero));
        $tiny = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-300));
        self::assertNull(Math::countPolynomialRootsOnRealLine('exp(x^1)=1', 'x', [], $tiny));
    }

    public function testAffineExponentialRootRespectsVariableExponentLimit(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        foreach (['2^x=2', '2^x=1/2', '2^(x*x)=2', '2^(x*x)=1/2', '2^(1/x)=2'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2', [], $limited));
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2', [], $limited),
            );
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^x=1', 'x', [], $limited));
        self::assertNotSame(0, Math::countPolynomialRootsOnRealLine('2^(x*x)=1', 'x', [], $limited));
        $boundary = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^x=2', 'x', [], $boundary));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^x=1/2', 'x', [], $boundary));
    }

    public function testExactRationalExponentLevels(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^(1/x)=2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('2^(x*x)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('2^(1/x)=1', 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval('2^(1/x)=2', 'x', '1/2', '2'));
        self::assertCount(1, Math::isolatePolynomialRootsOnRealLine('2^(1/x)=1/2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^(1/x)=4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^(1/x)=1/4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^(1/x)=1125899906842624', 'x'));
        $largeTarget = '231584178474632390847141970017375815706539969331281128078915168015826259279872';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('2^(1/x)=' . $largeTarget, 'x'));
        $limitedPower = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 256));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('2^(1/x)=' . $largeTarget, 'x', [], $limitedPower));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('2^((x-1)/x)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('2^((x*x-1)/(x-1))=4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('4^(1/x)=2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('4^(1/x)=1/2', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('4^(x*x)=2', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('4^(1/x)=8', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('4^(1/x)=1/8', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('4^(x*x)=8', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('4^((x*x-1)/(x-1))=8', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('4^((x*x-1)/(x-1))=16', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('512^(1/x)=4', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('512^(1/x)=1/4', 'x'));
        self::assertCount(1, Math::isolatePolynomialRootsInRationalInterval('4^(1/x)=8', 'x', '1/2', '1'));
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.4));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('4^(1/x)=2', 'x', [], $limited));
        $unitLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('4^(1/x)=8', 'x', [], $unitLimit));
        $narrowLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.2));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('512^(1/x)=4', 'x', [], $narrowLimit));
        $thirdLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1 / 3));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('8^(1/x)=2', 'x', [], $thirdLimit));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('8^x=2', 'x', [], $thirdLimit));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sin(x^(1/3))=2', 'x', [], $thirdLimit));
    }

    public function testExactRationalExponentLevelsWithFractionalBases(): void
    {
        foreach (
            [
                ['(1/4)^(1/x)=8', '-2', '3'],
                ['(1/4)^(1/x)=1/8', '2', '3'],
                ['(9/4)^(1/x)=27/8', '2', '3'],
            ] as [$equation, $numerator, $denominator]
        ) {
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertCount(1, $roots);
            self::assertTrue($roots[0]->isExactRoot);
            self::assertSame($numerator, $roots[0]->lowerNumerator);
            self::assertSame($denominator, $roots[0]->lowerDenominator);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('a^(1/x)=8', 'x', ['a' => 4]));
    }

    public function testPositiveBasePowerUnreachableTargets(): void
    {
        foreach (['2^(1/x)=0', '2^(1/x)=-1', '1^(1/x)=2', '(1/2)^(x*x)=-1'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        }
        self::assertNotSame(0, Math::countPolynomialRootsOnRealLine('2^(1/x)=2', 'x'));
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        $equation = '1^((2*x)/x)=1';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
    }

    public function testPowerReductionsDoNotAcceptInvalidRationalIntervals(): void
    {
        foreach (['x=0', '2^(1/x)=0', '1^(1/x)=2', '2^(1/x)=2'] as $equation) {
            foreach ([['2', '1'], ['1', '1']] as [$lower, $upper]) {
                self::assertNull(Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
                self::assertNull(Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
            }
        }
    }

    public function testBaseOneRationalPhaseIdentityHasInfiniteInterior(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        foreach (
            [
            static fn () => Math::countPolynomialRootsOnRealLine('1^(1/x)=1', 'x', [], $limited),
            static fn () => Math::isolatePolynomialRootsOnRealLine('1^(1/x)=1', 'x', [], $limited),
            static fn () => Math::countPolynomialRootsInRationalInterval('1^(1/x)=1', 'x', '2', '4', [], $limited),
            static fn () => Math::isolatePolynomialRootsInRationalInterval('1^(1/x)=1', 'x', '2', '4', [], $limited),
            ] as $solve
        ) {
            try {
                $solve();
                self::fail('Expected an infinite solution set.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBaseOneIdentityFindsDistantInteriorAroundPhaseZero(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        $equation = '1^((x-100)*(x-100))=1';
        foreach (
            [
            static fn () => Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited),
            static fn () => Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1000', [], $limited),
            ] as $solve
        ) {
            try {
                $solve();
                self::fail('Expected an infinite solution set.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('1^((x-100)/(x-100))=1', 'x', [], $limited));
        $stationaryEquation = '1^((x-100)*(x-100)+1/4)=1';
        try {
            Math::countPolynomialRootsOnRealLine($stationaryEquation, 'x', [], $limited);
            self::fail('Expected an infinite solution set around the stationary point.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $rationalEquation = '1^(((x-100)*(x-100)+1/4)/((x-100)*(x-100)+1))=1';
        try {
            Math::countPolynomialRootsOnRealLine($rationalEquation, 'x', [], $limited);
            self::fail('Expected an infinite solution set around the rational stationary point.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testBaseOneWithZeroExponentLimit(): void
    {
        $zeroLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0));
        foreach (['1^x=1', '1^(x*x)=1'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x', [], $zeroLimit));
            self::assertCount(1, Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $zeroLimit));
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $zeroLimit),
            );
            self::assertCount(
                1,
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $zeroLimit),
            );
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('1^(1/x)=1', 'x', [], $zeroLimit));
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('1^((x-x)/x)=1', 'x', [], $zeroLimit);
    }

    public function testZeroBaseRationalExponentLevels(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('0^(x*x)=1', 'x'));
        self::assertCount(1, Math::isolatePolynomialRootsOnRealLine('0^(x*x)=1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('0^(1/x)=1', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('0^(1/x)=2', 'x'));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('0^(1/x)=-1', 'x', '-1', '1'));
        self::assertCount(1, Math::isolatePolynomialRootsInRationalInterval('0^(x*x)=1', 'x', '-1', '1'));
        $zeroLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('0^(x*x)=0', 'x', [], $zeroLimit));
        foreach (['0^(-x*x)=0', '0^(-x*x-1)=0', '0^(-1/(x*x+1))=0', '0^(-x/x)=0'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
        }
        foreach (['0^x=0', '0^(1/x)=0'] as $equation) {
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '-1'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '-1'));
        }
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '-2', '0'));
        self::assertSame([], Math::isolatePolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '-2', '0'));
        try {
            Math::countPolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '-1', '1');
            self::fail('Expected the positive-exponent half-interval to contain infinitely many solutions.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
        $unitLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('0^x=0', 'x', '2', '3', [], $unitLimit));
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '1/10', '1/5', [], $unitLimit),
        );
    }

    public function testZeroBasePositiveExponentHasInfiniteInterior(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        foreach (
            [
            static fn () => Math::countPolynomialRootsOnRealLine('0^(1/x)=0', 'x', [], $limited),
            static fn () => Math::isolatePolynomialRootsOnRealLine('0^(x*x)=0', 'x', [], $limited),
            static fn () => Math::countPolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '2', '4', [], $limited),
            static fn () => Math::isolatePolynomialRootsInRationalInterval('0^(1/x)=0', 'x', '2', '4', [], $limited),
            ] as $solve
        ) {
            try {
                $solve();
                self::fail('Expected infinitely many zero-base solutions.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPowerIdentityFindsInteriorNearOpenEndpoint(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        foreach (['0^x=0', '1^x=1'] as $equation) {
            try {
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1000', [], $limited);
                self::fail('Expected an infinite solution set near the open endpoint.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('0^(-x)=0', 'x', '-1000', '0', [], $limited);
    }

    public function testPowerIdentityFindsInteriorWithTinyExponentLimit(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-20));
        foreach (['0^x=0', '1^x=1'] as $equation) {
            try {
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1000', [], $limited);
                self::fail('Expected an infinite solution set at a tiny exponent limit.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBaseOneHasNoRootsWhenBoundedPhaseExceedsLimit(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        $cases = [['1^x=1', '2', '3'], ['1^x=1', '-3', '-2'], ['1^(1/x)=1', '-1/5', '-1/10']];
        foreach ($cases as [$equation, $lower, $upper]) {
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper, [], $limited),
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper, [], $limited),
            );
        }
    }

    public function testBaseOneIdentityAtIrrationalPhaseZeroWithTinyLimit(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-32));
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('1^((x*x-2)*(x*x-2))=1', 'x', '1', '2', [], $limited);
    }

    public function testZeroBasePositiveNeighborhoodAtIrrationalPhaseZero(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-32));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('0^(-((x*x-2)*(x*x-2)))=0', 'x', [], $limited),
        );
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('0^((x*x-2)*(x*x-2))=0', 'x', '1', '2', [], $limited);
    }

    public function testZeroBasePositiveNeighborhoodAtSimplePhaseZero(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-32));
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('0^((x*x-2)/(x*x-2))=0', 'x', '1', '2', [], $limited),
        );
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('0^((x*x-2)/(x*x-2))=0', 'x', [], $limited));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine('0^((x*x-2)/(x*x-2))=0', 'x', [], $limited));
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval('0^(x*x-2)=0', 'x', '1', '2', [], $limited);
    }

    public function testZeroBasePositiveNeighborhoodAtOddMultiplePhaseZero(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-32));
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            '0^((x*x-2)*(x*x-2)*(x*x-2))=0',
            'x',
            '1',
            '2',
            [],
            $limited,
        );
    }

    public function testZeroBaseOddZeroAmidEvenMultiplicityFactors(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.0E-32));
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsInRationalInterval(
            '0^((x*x-2)*(x*x-2)*(x*x-2)*(x-3)*(x-3))=0',
            'x',
            '1',
            '2',
            [],
            $limited,
        );
    }

    public function testBaseOnePhaseZeroProofExcludesPolesAndOutsideRoots(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 0.5));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('1^((x*x-2)/(x*x-2))=1', 'x', [], $limited),
        );
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval('1^(x-2)=1', 'x', '-1', '1', [], $limited));
    }

    public function testZeroBaseCanHaveIsolatedPositiveLimitRoots(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (['0^(x*x+1)=0', '0^((2*x*x+1)/(x*x+1))=0'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertCount(1, Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
        }
        self::assertNotSame(1, Math::countPolynomialRootsOnRealLine('0^(-x*x-1)=0', 'x', [], $limited));
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('0^(((2*x*x+1)*x*x)/((x*x+1)*x*x))=0', 'x', [], $limited),
        );
    }

    public function testBaseOneConstantRationalPhaseAtExponentBoundary(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (
            [
            static fn () => Math::countPolynomialRootsOnRealLine('1^(x/x)=1', 'x', [], $limited),
            static fn () => Math::isolatePolynomialRootsOnRealLine('1^(x/x)=1', 'x', [], $limited),
            static fn () => Math::countPolynomialRootsInRationalInterval('1^(x/x)=1', 'x', '-1', '1', [], $limited),
            static fn () => Math::isolatePolynomialRootsInRationalInterval('1^(x/x)=1', 'x', '-1', '1', [], $limited),
            ] as $solve
        ) {
            try {
                $solve();
                self::fail('Expected an infinite solution set at the exponent boundary.');
            } catch (InfiniteSolutionSetException) {
                self::assertTrue(true);
            }
        }
        $third = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1 / 3));
        try {
            Math::countPolynomialRootsOnRealLine('1^((x/x)/3)=1', 'x', [], $third);
            self::fail('Expected an infinite solution set at the fractional exponent boundary.');
        } catch (InfiniteSolutionSetException) {
            self::assertTrue(true);
        }
    }

    public function testBaseOneExponentLimitCanHaveIsolatedBoundaryRoots(): void
    {
        $limited = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        foreach (['1^(x*x+1)=1', '1^(-x*x-1)=1'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertCount(1, Math::isolatePolynomialRootsOnRealLine($equation, 'x', [], $limited));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1', [], $limited));
        }
        self::assertNull(Math::countPolynomialRootsOnRealLine('1^(x*x+2)=1', 'x', [], $limited));
        $fractional = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1.5));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('1^(x*x+1.5)=1', 'x', [], $fractional));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('1^((2*x*x+1)/(x*x+1))=1', 'x', [], $limited));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('1^(-(2*x*x+1)/(x*x+1))=1', 'x', [], $limited));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('1^((2*x*x+1)/(-x*x-1))=1', 'x', [], $limited));
        $twoLimit = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 2));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('1^((x*x+1)/x)=1', 'x', [], $twoLimit));
        self::assertCount(2, Math::isolatePolynomialRootsOnRealLine('1^((x*x+1)/x)=1', 'x', [], $twoLimit));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('1^(((x*x+1)*x)/(x*x))=1', 'x', [], $twoLimit),
        );
        self::assertCount(
            1,
            Math::isolatePolynomialRootsInRationalInterval('1^((x*x+1)/x)=1', 'x', '1/2', '2', [], $twoLimit),
        );
        self::assertCount(
            1,
            Math::isolatePolynomialRootsInRationalInterval('1^((2*x*x+1)/(x*x+1))=1', 'x', '-1', '1', [], $limited),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('1^(((2*x*x+1)*x*x)/((x*x+1)*x*x))=1', 'x', [], $limited),
        );
        $third = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1 / 3));
        self::assertNull(Math::countPolynomialRootsOnRealLine('1^(x*x+1/3)=1', 'x', [], $third));
    }

    public function testCrossPhaseZeroProductHasInfiniteRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sin(x)=0*csc(x+1)', 'x');
    }

    public function testZeroProductPhaseMultipliersUseReducedRationalParity(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0*sec(2*x)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0*tan(2*x/3)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0*sec(x+1)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x^2)=0*tan(3*x^2/2+1)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x^2)=0*sec(x^4+x^2)',
            'x',
            '0',
            '2',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x^2)=0*sec(x^4-x^4+x^2)',
            'x',
            '0',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*cot(x+1)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*csc(x^2+1)',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*csc(x^2)',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0*sec(x)',
            'x',
            '-2',
            '2',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec(2*x/3)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0/csc(3*x/2)',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec(x/2)',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(3, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0/csc(2*x/3)',
            'x',
            '-2',
            '6',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'sin(x)=0*sec((2/6)*x)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'cos(x)=0/csc((-6/4)*x)',
            'x',
            '-1',
            '4',
        ));
    }

    public function testRationalPhaseZeroProductHasInfiniteRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('sin(x)=0*sec((2/6)*x)', 'x');
    }

    public function testWrappedPuncturedZeroTargetHasInfiniteRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('sin(x)=sqrt((x-x)/(x-1))', 'x');
    }

    public function testDirectNestedRationalIdentityWithTargetHoleHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('abs(x-x)=(x-x)/(x-1)', 'x');
    }

    public function testNestedZeroPowerReductionRespectsExponentLimit(): void
    {
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)^2)',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)^((2^2)/4))',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
    }

    public function testNestedZeroPowerReductionSupportsRationalParameterExponent(): void
    {
        $equation = 'atan(0*x)=abs(sin(x)^p)';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            $equation,
            'x',
            '-1',
            '4',
            parameters: ['p' => 0.5],
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            $equation,
            'x',
            '-1',
            '4',
            parameters: ['p' => -1],
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)^x)',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)^(p/-2))',
            'x',
            '-1',
            '4',
            parameters: ['p' => -1],
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)^(p/-2))',
            'x',
            '-1',
            '4',
            parameters: ['p' => 1],
        ));
    }

    public function testNestedZeroReductionSupportsNonzeroConstantScaling(): void
    {
        foreach (['atan(0*x)=abs(2*sin(x))', 'atan(0*x)=abs(sin(x)/p)'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
                $equation,
                'x',
                '-1',
                '4',
                parameters: ['p' => -2],
            ));
        }
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)/p)',
            'x',
            '-1',
            '4',
            parameters: ['p' => 0],
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs((x-1)*sin(x))',
            'x',
            '-1',
            '4',
        ));
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 1));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs((x^2+1)*sin(x))',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(0*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testNestedZeroReductionSupportsZeroFreeRationalScaling(): void
    {
        foreach (['atan(0*x)=abs((x^2+1)*sin(x))', 'atan(0*x)=abs(sin(x)/(x^2+1))'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)/(x-1))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)/x)',
            'x',
            '-1',
            '4',
        ));
        $options = new EvaluationOptions(limits: new ResourceLimits(maxExponentMagnitude: 256));
        self::assertNull(Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(((x^2+1)*(2^300))*sin(x))',
            'x',
            '-1',
            '4',
            evaluationOptions: $options,
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs((x-1)*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingCanValidateFactorAwayFromZeroPole(): void
    {
        foreach (
            [
                'atan(0*x)=abs(cos(x)/x)',
                'atan(0*x)=abs(cos(x)*(1/x))',
                'atan(0*x)=abs(abs(cos(x))/x)',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
        }
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(sin(x)/x)',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingFindsSampleBeyondSeveralPoles(): void
    {
        $equation = 'atan(0*x)=abs(cos(x)/(x*(x^2-1)*(x^2-4)))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
    }

    public function testPeriodicScalingSupportsGloballyDefinedZeroFreeFactors(): void
    {
        foreach (['exp(x)*sin(x)', 'sin(x)/cosh(x^2)', 'sech(x)*sin(x)'] as $phase) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
                'atan(0*x)=abs(' . $phase . ')',
                'x',
                '-1',
                '4',
            ));
        }
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(exp(1/x)*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(exp(1/x)*cos(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(cos(x)/cosh(1/x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(csch(x)*cos(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(coth(x)*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingSupportsGammaWithPositiveRationalArgument(): void
    {
        foreach (['gamma(x^2+1)*sin(x)', 'sin(x)/gamma(x^2+1)'] as $phase) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
                'atan(0*x)=abs(' . $phase . ')',
                'x',
                '-1',
                '4',
            ));
        }
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(x)*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(x^2)*cos(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(x^2)*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(x^2-1)*cos(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingSupportsGammaInNegativePoleFreeStrip(): void
    {
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-1/(x^2+2))*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-1-1/(x^2+2))*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-1-1/(x^2+1))*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-4-1/(x^2+2))*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-4-1/(x^2+1))*sin(x))',
            'x',
            '-1',
            '4',
        ));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-1/(x^2+1))*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingSupportsGammaStripWithCertifiedArgumentHole(): void
    {
        $equation = 'atan(0*x)=abs(gamma(-1/2+(x-1)/(10*(x-1)*(x^2+1)))*cos(x))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(gamma(-1/2+x/(10*x*(x^2+1)))*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingExcludesZerosAtFactorPoles(): void
    {
        $equation = 'atan(0*x)=abs(cos(x)*((x-1)*(x^2+1)/(x-1)))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertNotSame(1, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs(cos(x)*((x-1)*(x-2)/(x-1)))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingAllowsFactorZerosAlreadyInPhaseZeros(): void
    {
        foreach (
            [
                'atan(0*x)=abs(x*sin(x))',
                'atan(0*x)=abs(x*(x^2+1)*tan(x))',
                'atan(0*x)=abs(x*sqrt(abs(sin(x))))',
                'atan(0*x)=abs(((x-1)*x/(x-1))*sin(x))',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        }
        self::assertNotSame(2, Math::countPolynomialRootsInRationalInterval(
            'atan(0*x)=abs((x-1)*sin(x))',
            'x',
            '-1',
            '4',
        ));
    }

    public function testPeriodicScalingReportsInfiniteRootsOnRealLine(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('atan(0*x)=abs(cos(x)/x)', 'x');
    }

    public function testPeriodicScalingReportsInfiniteRootIsolationOnRealLine(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('atan(0*x)=abs(cos(x)/x)', 'x');
    }

    public function testZeroPhaseReductionPreservesRationalHoles(): void
    {
        foreach (
            [
                'sinh((x-x)/(x-1))=atan(x-1)',
                'cosh((x-x)/(x-1))=sech(x-1)',
                'cosh((x-x)/(x-1))=cosh(x-1)',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '3'));
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('sinh((x-x)/(x-1))=atan(x-2)', 'x'));
    }

    public function testZeroPhaseReductionSupportsRestrictedDomainFunctions(): void
    {
        foreach (['sqrt', 'asin', 'arcsin', 'atanh', 'arctanh', 'erfinv', 'log1p', 'abs', 'sign'] as $function) {
            $equation = 'sinh((x-x)/(x-1))=' . $function . '(x-2)';
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '1'));
        }
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('sqrt((x-x)/(x-1))=atan(x-1)', 'x'));
    }

    public function testAbsoluteValueWithZeroPhaseSupportsPeriodicRoots(): void
    {
        $equation = 'atan(0*x)=abs(sin(x))';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
    }

    public function testSignWithZeroPhaseSupportsPeriodicRoots(): void
    {
        $equation = 'atan(0*x)=sign(sin(x))';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
    }

    public function testDawsonWithZeroPhaseSupportsPeriodicRoots(): void
    {
        $equation = 'atan(0*x)=dawson(sin(x))';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '4'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '4');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('dawson(x-1)=atan((x-x)/(x-1))', 'x'));
    }

    public function testSignWithZeroPhaseHasInfinitePeriodicRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('sign(sin(x))=atan(0*x)', 'x');
    }

    public function testZeroPhaseReductionPreservesPuncturedIdentity(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('cosh((x-x)/(x-1))=sech(0)', 'x');
    }

    public function testInjectiveEqualityWithPeriodicPhaseHasInfiniteRealRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('atan(sin(x))=arctan(0)', 'x');
    }

    public function testInjectiveEqualityWithPeriodicPhaseHasInfiniteRealRootIsolation(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolatePolynomialRootsOnRealLine('exp(sin(x))=exp(0)', 'x');
    }

    public function testInjectiveEqualityOfRestrictedIdenticalPhasesHasInfiniteRoots(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('exp(ln(x))=exp(ln(x))', 'x');
    }

    public function testCoshWithConstantRationalTrigPhaseHasNoRootAtUndefinedEndpoint(): void
    {
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('cosh((x-x)/(x-1))=sin(1)', 'x', '1', '2'),
        );
    }

    public function testCoshCosineZeroPhaseIdentityRejectsUndefinedEndpoint(): void
    {
        $this->expectException(\DomainException::class);
        Math::countPolynomialRootsInRationalInterval(
            'cosh((x-x)/(x-1))=cos(0*x)',
            'x',
            '1',
            '2',
        );
    }

    public function testReciprocalAsechEqualityTracksItsPolynomialDomainBoundary(): void
    {
        $boundaryOnly = 'acosh(1-x^2)=asech(1/(1-x^2))';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($boundaryOnly, 'x', '-2', '2'));
        $boundaryRoots = Math::isolatePolynomialRootsInRationalInterval($boundaryOnly, 'x', '-2', '2');
        self::assertNotNull($boundaryRoots);
        self::assertCount(1, $boundaryRoots);
        self::assertSame('0', $boundaryRoots[0]->lowerNumerator);

        $boundaryHole = 'acosh(1-(x-1)^2)=asech((1/(1-(x-1)^2))*(x-1)/(x-1))';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($boundaryHole, 'x', '-2', '3'));
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('acosh(x)=asech(1/x)', 'x', '-2', '1'),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($boundaryOnly, 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($boundaryHole, 'x'));
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine(
                'acosh(1-(x^2-1)^2)=asech(1/(1-(x^2-1)^2))',
                'x',
            ),
        );

        $rationalBoundary = 'acosh(1/(1+x^2))=asech(1+x^2)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($rationalBoundary, 'x', '-2', '2'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($rationalBoundary, 'x'));
        $rationalBoundaryRoots = Math::isolatePolynomialRootsOnRealLine($rationalBoundary, 'x');
        self::assertNotNull($rationalBoundaryRoots);
        self::assertCount(1, $rationalBoundaryRoots);
        self::assertTrue($rationalBoundaryRoots[0]->isExactRoot);
        self::assertSame('0', $rationalBoundaryRoots[0]->lowerNumerator);
        $rationalBoundaryHole = 'acosh(1/(1+(x-1)^2))='
            . 'asech((1+(x-1)^2)*(x-1)/(x-1))';
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($rationalBoundaryHole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($rationalBoundaryHole, 'x'));
        $rationalInfinite = 'acosh((x^2+2)/(x^2+1))=asech((x^2+1)/(x^2+2))';
        try {
            Math::countPolynomialRootsInRationalInterval($rationalInfinite, 'x', '-2', '2');
            self::fail('Expected the rational-phase equation to hold on an interval.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::isolatePolynomialRootsOnRealLine($rationalInfinite, 'x');
            self::fail('Expected all-real isolation to reject an infinite solution set.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::countPolynomialRootsOnRealLine($rationalInfinite, 'x');
            self::fail('Expected the rational-phase equation to hold on a real interval.');
        } catch (InfiniteSolutionSetException) {
        }

        try {
            Math::countPolynomialRootsInRationalInterval('acosh(x)=asech(1/x)', 'x', '0', '2');
            self::fail('Expected the equation to hold on an interval where x is greater than one.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::countPolynomialRootsOnRealLine('acosh(x)=asech(1/x)', 'x');
            self::fail('Expected the equation to hold on an unbounded interval where x is greater than one.');
        } catch (InfiniteSolutionSetException) {
        }
        try {
            Math::countPolynomialRootsOnRealLine('acosh(x^2+1)=asech(1/(x^2+1))', 'x');
            self::fail('Expected the equation to hold on intervals where x is nonzero.');
        } catch (InfiniteSolutionSetException) {
        }
    }

    public function testAcoshAsechEqualityIsolatesFiniteRootsAndExcludesPoles(): void
    {
        foreach (['acosh(x)=asech(x/2)', 'asech(x/2)=arccosh(x)'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertFalse($roots[0]->isExactRoot);
        }

        $boundary = 'acosh(x)=asech(2-x)';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($boundary, 'x', '0', '2'));
        $boundaryRoots = Math::isolatePolynomialRootsInRationalInterval($boundary, 'x', '0', '2');
        self::assertNotNull($boundaryRoots);
        self::assertCount(1, $boundaryRoots);
        self::assertTrue($boundaryRoots[0]->isExactRoot);
        self::assertSame('1', $boundaryRoots[0]->lowerNumerator);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('acosh(x)=asech(-1/2)', 'x'));
        $hole = 'acosh(x)=asech((x-2)/(2*(x-2)))';
        self::assertSame(0, Math::countPolynomialRootsInRationalInterval($hole, 'x', '0', '3'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine($hole, 'x'));
        self::assertSame([], Math::isolatePolynomialRootsOnRealLine($hole, 'x'));
    }

    public function testMatchingOddRootFunctionsReduceToTheirRadicands(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('cbrt(x^2-1)=root(0,3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2,5)=root(1,5)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(x)=root(2-x,3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2-1,1+2)=cbrt(0)', 'x'));
    }

    public function testElementarySelfEquationsHaveExactGlobalRootClassification(): void
    {
        foreach (
            [
                'sin(x)=x',
                'sinh(x)=x',
                'asinh(x)=x',
                'arcsinh(x)=x',
                'atan(x)=x',
                'arctan(x)=x',
                'tanh(x)=x',
                'log1p(x)=x',
                'expm1(x)=x',
                'x=sin(x)',
                'sin(-x)=x',
                'sin(x)=-x',
                'sinh(-x)=x',
                'asinh(x)=-x',
                'atan(-x)=-x',
                'tanh(-x)=x',
                'log1p(-x)=x',
                'log1p(x)=-x',
                'expm1(-x)=x',
                'expm1(x)=-x',
                'asin(x)=x',
                'asin(-x)=x',
                'asin(x)=-x',
                'arcsin(-x)=-x',
                'atanh(x)=x',
                'atanh(-x)=x',
                'atanh(x)=-x',
                'arctanh(-x)=-x',
                'erf(-x)=x',
                'erf(x)=-x',
                'erfinv(-x)=x',
                'erfinv(x)=-x',
            ] as $equation
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(1, $allRoots, $equation);
            self::assertSame('0', $allRoots[0]->lowerNumerator, $equation);
            self::assertTrue($allRoots[0]->isExactRoot, $equation);
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
            self::assertSame('0', $boundedRoots[0]->lowerNumerator, $equation);
            self::assertTrue($boundedRoots[0]->isExactRoot, $equation);
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            self::assertSame([], Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
        }

        foreach (
            [
                'ln(x)=x',
                'exp(x)=x',
                'cosh(x)=x',
                'cosh(-x)=x',
                'cosh(x)=-x',
                'cosh(-x)=-x',
                'acosh(x)=x',
                'acosh(-x)=x',
                'acosh(x)=-x',
                'acosh(-x)=-x',
                'arccosh(x)=x',
                'asec(x)=x',
                'asec(-x)=-x',
                'acsc(x)=x',
                'acsc(-x)=x',
                'acsc(x)=-x',
                'acsc(-x)=-x',
                'asech(-x)=x',
                'asech(x)=-x',
                'acsch(x)=-x',
                'acsch(-x)=x',
                'acoth(x)=-x',
                'acoth(-x)=x',
                'acos(-x)=x',
                'acos(x)=-x',
                'arccos(-x)=x',
                'coth(x)=-x',
                'coth(-x)=x',
                'csch(x)=-x',
                'csch(-x)=x',
            ] as $equation
        ) {
            self::assertSame(0, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame([], Math::isolatePolynomialRootsOnRealLine($equation, 'x'), $equation);
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1'),
                $equation,
            );
        }

        foreach (
            [
                'ln(x)=-x' => 1,
                'ln(-x)=x' => -1,
                'exp(x)=-x' => -1,
                'exp(-x)=x' => 1,
            ] as $equation => $rootSign
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(1, $allRoots, $equation);
            self::assertFalse($allRoots[0]->isExactRoot, $equation);
            $rootLower = $rootSign > 0 ? '0' : '-1';
            $rootUpper = $rootSign > 0 ? '1' : '0';
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $rootLower, $rootUpper),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $rootLower,
                $rootUpper,
            );
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
            self::assertFalse($boundedRoots[0]->isExactRoot, $equation);
            self::assertSame($rootSign > 0, !str_starts_with($boundedRoots[0]->lowerNumerator, '-'), $equation);
            $excludedLower = $rootSign > 0 ? '0' : '-1/2';
            $excludedUpper = $rootSign > 0 ? '1/2' : '0';
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $excludedLower, $excludedUpper),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $excludedLower, $excludedUpper),
                $equation,
            );
        }

        foreach (['erf(x)=x', 'erf(-x)=-x', 'erfinv(x)=x', 'erfinv(-x)=-x'] as $equation) {
            self::assertSame(3, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(3, $allRoots, $equation);
            self::assertTrue(str_starts_with($allRoots[0]->upperNumerator, '-'), $equation);
            self::assertTrue($allRoots[1]->isExactRoot, $equation);
            self::assertSame('0', $allRoots[1]->lowerNumerator, $equation);
            self::assertFalse($allRoots[2]->isExactRoot, $equation);
            self::assertFalse(str_starts_with($allRoots[2]->lowerNumerator, '-'), $equation);
            self::assertSame(3, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(3, $boundedRoots, $equation);
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '0'));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1/10', '1/10'));
            self::assertSame(0, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1/2'));
        }

        foreach (
            [
                'cos(x)=x' => 1,
                'cos(-x)=x' => 1,
                'cos(x)=-x' => -1,
                'cos(-x)=-x' => -1,
                'acos(x)=x' => 1,
                'acos(-x)=-x' => -1,
                'arccos(x)=x' => 1,
                'arccos(-x)=-x' => -1,
            ] as $equation => $rootSign
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(1, $allRoots, $equation);
            self::assertFalse($allRoots[0]->isExactRoot, $equation);
            $rootLower = $rootSign > 0 ? '0' : '-1';
            $rootUpper = $rootSign > 0 ? '1' : '0';
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $rootLower, $rootUpper),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $rootLower,
                $rootUpper,
            );
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
            self::assertSame($rootSign > 0, !str_starts_with($boundedRoots[0]->lowerNumerator, '-'), $equation);
            $excludedLower = $rootSign > 0 ? '0' : '-1/2';
            $excludedUpper = $rootSign > 0 ? '1/2' : '0';
            self::assertSame(
                0,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $excludedLower, $excludedUpper),
                $equation,
            );
            self::assertSame(
                [],
                Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $excludedLower, $excludedUpper),
                $equation,
            );
        }

        foreach (
            [
                'acot(x)=x' => [1, '0', '1'],
                'acot(-x)=x' => [1, '2', '3'],
                'acot(x)=-x' => [-1, '2', '3'],
                'acot(-x)=-x' => [-1, '0', '1'],
                'asec(-x)=x' => [1, '2', '3'],
                'asec(x)=-x' => [-1, '2', '3'],
                'erfc(x)=x' => [1, '0', '1'],
                'erfc(-x)=-x' => [-1, '0', '1'],
                'erfc(-x)=x' => [1, '1', '2'],
                'erfc(x)=-x' => [-1, '1', '2'],
            ] as $equation => [$rootSign, $magnitudeLower, $magnitudeUpper]
        ) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(1, $allRoots, $equation);
            self::assertFalse($allRoots[0]->isExactRoot, $equation);
            self::assertSame($rootSign > 0, !str_starts_with($allRoots[0]->lowerNumerator, '-'), $equation);
            $intervalLower = $rootSign > 0 ? $magnitudeLower : '-' . $magnitudeUpper;
            $intervalUpper = $rootSign > 0 ? $magnitudeUpper : '-' . $magnitudeLower;
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $intervalLower, $intervalUpper),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $intervalLower,
                $intervalUpper,
            );
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
        }

        foreach (['asech(x)=x' => 1, 'asech(-x)=-x' => -1] as $equation => $rootSign) {
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(1, $allRoots, $equation);
            self::assertFalse($allRoots[0]->isExactRoot, $equation);
            self::assertSame($rootSign > 0, !str_starts_with($allRoots[0]->lowerNumerator, '-'), $equation);
            $intervalLower = $rootSign > 0 ? '0' : '-1';
            $intervalUpper = $rootSign > 0 ? '1' : '0';
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $intervalLower, $intervalUpper),
                $equation,
            );
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
                $equation,
                'x',
                $intervalLower,
                $intervalUpper,
            );
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(1, $boundedRoots, $equation);
        }

        foreach (
            [
                'coth(x)=x', 'coth(-x)=-x',
                'csch(x)=x', 'csch(-x)=-x',
                'acoth(x)=x', 'acoth(-x)=-x',
                'acsch(x)=x', 'acsch(-x)=-x',
            ] as $equation
        ) {
            self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'), $equation);
            $allRoots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
            self::assertNotNull($allRoots, $equation);
            self::assertCount(2, $allRoots, $equation);
            self::assertFalse($allRoots[0]->isExactRoot, $equation);
            self::assertFalse($allRoots[1]->isExactRoot, $equation);
            self::assertTrue(str_starts_with($allRoots[0]->upperNumerator, '-'), $equation);
            self::assertFalse(str_starts_with($allRoots[1]->lowerNumerator, '-'), $equation);
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
            $boundedRoots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($boundedRoots, $equation);
            self::assertCount(2, $boundedRoots, $equation);
            $hasCothRoots = str_starts_with($equation, 'coth') || str_starts_with($equation, 'acoth');
            $rootLower = $hasCothRoots ? '1' : '0';
            $rootUpper = $hasCothRoots ? '2' : '1';
            $negativeUpper = $rootLower === '0' ? '0' : '-' . $rootLower;
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-' . $rootUpper, $negativeUpper),
            );
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', $rootLower, $rootUpper));
        }
    }

    public function testOddRootDegreesAboveSixtyThreeUseConfiguredPolynomialLimit(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x,65)=2', 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine('root(x,65)=2', 'x');
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertSame('36893488147419103232', $roots[0]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'root(x,65)=2',
                'x',
                '0',
                '40000000000000000000',
            ),
        );
        $boundedRoots = Math::isolatePolynomialRootsInRationalInterval(
            'root(x,65)=2',
            'x',
            '0',
            '40000000000000000000',
        );
        self::assertNotNull($boundedRoots);
        self::assertCount(1, $boundedRoots);
        self::assertSame('36893488147419103232', $boundedRoots[0]->lowerNumerator);
        self::assertTrue($boundedRoots[0]->isExactRoot);
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'root(x,65)=2',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 64),
        ));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2-3*x+1,65)=1', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('x^(1/65)=(2-x)^(1/65)', 'x'));
        self::assertSame(3, Math::countPolynomialRootsOnRealLine('x^(65/3)=x', 'x'));
        self::assertNull(Math::countPolynomialRootsOnRealLine(
            'x^(65/3)=x',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 64),
        ));
        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine(
                'root(root(x,65),3)=root(root(2-x,3),65)',
                'x',
            ),
        );
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(root(x,65),3)=1', 'x'));
    }

    public function testDifferentPositiveOddRootDegreesReduceToExactPowerEquality(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('cbrt(x)=root(2-x,5)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'cbrt((x^2-1)/(x-1))=root(1/8,5)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x,3)=root(1,5)', 'x'));
    }

    public function testMatchingEvenRootFunctionsEnforceNonnegativeRadicands(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('sqrt(x^2)=root(x,2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x,2)=sqrt(-x)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2-1,4)=root(3-x^2,4)', 'x'));

        $roots = Math::isolatePolynomialRootsOnRealLine('sqrt(x^2)=root(x,2)', 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('0', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertTrue($roots[1]->isExactRoot);
    }

    public function testMatchingNegativeDegreeRootsExcludeTheirSingularRadicands(): void
    {
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2,-3)=root(1,-3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('root(x^2-1,-2)=root(3-x^2,-2)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('root(x,-2)=root(-x,-2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('root(x,-3)=root(2-x,-3)', 'x'));
    }

    public function testInverseErrorFunctionEqualitiesHonorOpenDomains(): void
    {
        $erfInverseEquation = 'erfinv(x/4)=erfinv((2-x)/4)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($erfInverseEquation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($erfInverseEquation, 'x', '0', '2'));

        $erfcInverseEquation = 'erfcinv(x/4)=erfcinv((2-x)/4)';
        self::assertSame(1, Math::countPolynomialRootsOnRealLine($erfcInverseEquation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($erfcInverseEquation, 'x', '0', '2'));

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfinv(x)=erfinv(2-x)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('erfcinv(x)=erfcinv(-x)', 'x'));
    }

    public function testInverseGammaAndBetaQuantileEqualitiesReduceProbabilityArguments(): void
    {
        foreach (['gammaincinv', 'gammainccinv'] as $function) {
            $equation = "{$function}(2,x)={$function}(2,1-x)";
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammaincinv(2,-x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammainccinv(2,x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammainccinv(2,1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammainccinv(2,0)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammaincinv(2,1)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammainccinv(2,x)=gammainccinv(2,0)',
            'x',
        ));
        $lowerEndpointRoots = Math::isolatePolynomialRootsOnRealLine(
            'gammaincinv(2,x)=gammainccinv(2,1)',
            'x',
        );
        self::assertNotNull($lowerEndpointRoots);
        self::assertCount(1, $lowerEndpointRoots);
        self::assertSame('0', $lowerEndpointRoots[0]->lowerNumerator);
        self::assertTrue($lowerEndpointRoots[0]->isExactRoot);

        foreach (['betaincinv', 'betainccinv'] as $function) {
            $equation = "{$function}(2,3,x)={$function}(2,3,1-x)";
            self::assertSame(1, Math::countPolynomialRootsOnRealLine($equation, 'x'));
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            self::assertSame(1, Math::countPolynomialRootsOnRealLine(
                "{$function}(2,3,x)={$function}(2,3,0)",
                'x',
            ));
            $endpointRoots = Math::isolatePolynomialRootsOnRealLine(
                "{$function}(2,3,x)={$function}(2,3,0)",
                'x',
            );
            self::assertNotNull($endpointRoots);
            self::assertCount(1, $endpointRoots);
            self::assertSame('0', $endpointRoots[0]->lowerNumerator);
            self::assertTrue($endpointRoots[0]->isExactRoot);
        }
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'betaincinv(2,3,x)=betainccinv(2,3,x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'betaincinv(2,3,x)=betainccinv(2,3,1)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'betaincinv(2,3,x)=betainccinv(2,3,0)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'betaincinv(0,3,x)=betaincinv(0,3,1-x)',
            'x',
        ));

        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(x^2+1,x)=gammaincinv(x^2+1,1-x)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(x-1,x)=gammaincinv(x-1,1-x)',
            'x',
        ));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(2*x-1,x)=gammaincinv(2*x-1,1-x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'betaincinv(x^2+1,x^2+2,x)=betaincinv(x^2+1,x^2+2,1-x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'gammaincinv(x^2+1,x)=gammainccinv(x^2+1,x)',
            'x',
        ));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine(
            'betaincinv(x^2+1,x^2+2,x)=betainccinv(x^2+1,x^2+2,x)',
            'x',
        ));
    }

    public function testComplementaryInverseQuantileIdentityReportsInfiniteSolutions(): void
    {
        foreach (
            [
                'gammaincinv(2,x)=gammainccinv(2,1-x)',
                'betaincinv(2,3,x)=betainccinv(2,3,1-x)',
                'gammaincinv(x^2+1,x)=gammainccinv(x^2+1,1-x)',
                'betaincinv(x^2+1,x^2+2,x)=betainccinv(x^2+1,x^2+2,1-x)',
            ] as $equation
        ) {
            $threwInfiniteSolutionSet = false;
            try {
                Math::countPolynomialRootsOnRealLine($equation, 'x');
            } catch (InfiniteSolutionSetException) {
                $threwInfiniteSolutionSet = true;
            }

            self::assertTrue(
                $threwInfiniteSolutionSet,
                'Expected the inverse-quantile identity to have infinitely many roots: ' . $equation,
            );
        }
    }

    public function testNaturalLogarithmAndLogOnePlusFormsShareAnExactArgument(): void
    {
        $equation = 'ln(x^2+1)=log1p(3-x^2)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertFalse($roots[1]->isExactRoot);
        foreach ($roots as $index => $root) {
            $midpoint = (
                $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
            ) / 2.0;
            self::assertEqualsWithDelta(
                $index === 0 ? -\sqrt(1.5) : \sqrt(1.5),
                $midpoint,
                1.0E-6,
            );
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsOnRealLine('log1p(x-1)=ln(3-x)', 'x'),
        );
        self::assertSame(
            2,
            Math::countPolynomialRootsOnRealLine('log1p(x^2)=log(4-x^2,e)', 'x'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsOnRealLine('ln(x-4)=log1p(2-x)', 'x'),
        );

        $this->expectException(InfiniteSolutionSetException::class);
        Math::countPolynomialRootsOnRealLine('ln(x)=log1p(x-1)', 'x');
    }

    public function testEqualPolynomialBaseLogarithmsRespectBaseDomain(): void
    {
        $equation = 'log(x^2+1,x^2+2)=log(3-x^2,2+x^2)';
        self::assertSame(2, Math::countPolynomialRootsOnRealLine($equation, 'x'));
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '0'));
        $roots = Math::isolatePolynomialRootsOnRealLine($equation, 'x');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertSame('-1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[1]->lowerNumerator);
        $negativeRoot = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '0');
        self::assertNotNull($negativeRoot);
        self::assertCount(1, $negativeRoot);
        self::assertSame('-1', $negativeRoot[0]->lowerNumerator);
        self::assertTrue($negativeRoot[0]->isExactRoot);

        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x+1,x)=log(3-x,x)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x+1,x-1)=log(3-x,x-1)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x+1,x-2)=log(3-x,x-2)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log(x+1,(x+2)/4)=log(3-x,(x+2)/4)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('log(x-2,x+2)=log(-x,x+2)', 'x'));

        foreach ([2, 0.5] as $base) {
            self::assertSame(
                2,
                Math::countPolynomialRootsOnRealLine(
                    'log(x^2+1,b)=log(3-x^2,b)',
                    'x',
                    ['b' => $base],
                ),
            );
        }
        foreach ([0, 1, -2] as $invalidBase) {
            self::assertSame(
                0,
                Math::countPolynomialRootsOnRealLine(
                    'log(x^2+1,b)=log(3-x^2,b)',
                    'x',
                    ['b' => $invalidBase],
                ),
            );
        }
    }

    public function testLogarithmDifferencesAtPositiveConstantArgumentsReduceExactly(): void
    {
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('ln(x)-ln(2)=ln(3)', 'x'));
        self::assertSame(2, Math::countPolynomialRootsOnRealLine('ln(x^2)-ln(2)=ln(3)', 'x'));
        self::assertSame(1, Math::countPolynomialRootsOnRealLine('log10(x)-log10(2)=log10(3)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x)-ln(2)=ln(-3)', 'x'));
        self::assertSame(0, Math::countPolynomialRootsOnRealLine('ln(x)-ln(-2)=ln(3)', 'x'));
    }

    public function testExactIsolationPreservesRootsCloserThanFloatResolution(): void
    {
        $equation = '(x-1)*(x-(1+0.0000000000000001))=0';

        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0.9', '1.1'));
        $roots = Math::isolatePolynomialRootsInRationalInterval(
            $equation,
            'x',
            '0.9',
            '1.1',
            maximumWidth: '1/100000000000000000000',
        );

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertTrue($roots[0]->isExactRoot);
        self::assertSame('1', $roots[0]->lowerNumerator);
        self::assertSame('1', $roots[0]->lowerDenominator);
        self::assertTrue($roots[1]->isExactRoot);
        self::assertSame('10000000000000001', $roots[1]->lowerNumerator);
        self::assertSame('10000000000000000', $roots[1]->lowerDenominator);
    }

    public function testIntegerShapeRegularizedBetaLevelsUseExactPolynomialRoots(): void
    {
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('betainc(1,1,x)=1/2', 'x', '0', '1'),
        );
        $uniformRoots = Math::isolatePolynomialRootsInRationalInterval(
            'betainc(1,1,x)=1/2',
            'x',
            '0',
            '1',
        );
        self::assertNotNull($uniformRoots);
        self::assertCount(1, $uniformRoots);
        self::assertTrue($uniformRoots[0]->isExactRoot);
        self::assertSame('1', $uniformRoots[0]->lowerNumerator);
        self::assertSame('2', $uniformRoots[0]->lowerDenominator);

        foreach (['betainc(2,3,x)=1/2', 'betaincc(2,3,x)=1/2'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '1'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '1');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertFalse($roots[0]->isExactRoot);
        }

        $affineRoots = Math::isolatePolynomialRootsInRationalInterval(
            'betainc(1+0,1,x/2)=1/2',
            'x',
            '0',
            '2',
        );
        self::assertNotNull($affineRoots);
        self::assertCount(1, $affineRoots);
        self::assertTrue($affineRoots[0]->isExactRoot);
        self::assertSame('1', $affineRoots[0]->lowerNumerator);
        self::assertSame('1', $affineRoots[0]->lowerDenominator);
    }

    public function testIntegerShapeRegularizedBetaRequiresCertifiedArgumentDomain(): void
    {
        self::assertNull(
            Math::countPolynomialRootsInRationalInterval('betainc(1,1,x)=1/2', 'x', '-1', '2'),
        );
        self::assertNull(
            Math::isolatePolynomialRootsInRationalInterval('betainc(1,1,x)=1/2', 'x', '-1', '2'),
        );
    }

    public function testIntegerShapeRegularizedBetaCertifiesNonlinearArgumentRangeBySubdivision(): void
    {
        foreach (['betainc(1,1,x^2)=1/2', 'betaincc(1,1,x^2)=1/2'] as $equation) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            self::assertFalse($roots[0]->isExactRoot);
            self::assertFalse($roots[1]->isExactRoot);
        }
    }

    public function testHalfShapeRegularizedGammaLevelsReduceToCertifiedErrorFunctionRoots(): void
    {
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1/2,x)=1/2', 'x', '-1', '1'),
        );
        $zeroRoot = Math::isolatePolynomialRootsInRationalInterval(
            'gammainc(1/2,2*x+2)=1/2',
            'x',
            '-2',
            '0',
        );
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertFalse($zeroRoot[0]->isExactRoot);
        $boundaryMidpoint = (
            $this->rationalFractionToFloat($zeroRoot[0]->lowerNumerator, $zeroRoot[0]->lowerDenominator)
            + $this->rationalFractionToFloat($zeroRoot[0]->upperNumerator, $zeroRoot[0]->upperDenominator)
        ) / 2.0;
        self::assertLessThan(
            1.0E-6,
            \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(
                0.5,
                (2.0 * $boundaryMidpoint) + 2.0,
            ) - 0.5),
        );

        $upperLevel = 'gammainc(1/2,x)=3/4';
        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($upperLevel, 'x', '0', '2'));
        $upperLevelRoots = Math::isolatePolynomialRootsInRationalInterval(
            $upperLevel,
            'x',
            '0',
            '2',
        );
        self::assertNotNull($upperLevelRoots);
        self::assertCount(1, $upperLevelRoots);
        self::assertFalse($upperLevelRoots[0]->isExactRoot);
        $upperLevelMidpoint = (
            $this->rationalFractionToFloat(
                $upperLevelRoots[0]->lowerNumerator,
                $upperLevelRoots[0]->lowerDenominator,
            )
            + $this->rationalFractionToFloat(
                $upperLevelRoots[0]->upperNumerator,
                $upperLevelRoots[0]->upperDenominator,
            )
        ) / 2.0;
        self::assertLessThan(
            1.0E-6,
            \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, $upperLevelMidpoint) - 0.75),
        );

        $complementaryEquation = 'gammaincc(1/2,x)=1/4';
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval($complementaryEquation, 'x', '0', '2'),
        );
        $complementaryRoots = Math::isolatePolynomialRootsInRationalInterval(
            $complementaryEquation,
            'x',
            '0',
            '2',
        );
        self::assertNotNull($complementaryRoots);
        self::assertCount(1, $complementaryRoots);
        self::assertLessThan(
            1.0E-6,
            \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaQ(
                0.5,
                (
                    $this->rationalFractionToFloat(
                        $complementaryRoots[0]->lowerNumerator,
                        $complementaryRoots[0]->lowerDenominator,
                    )
                    + $this->rationalFractionToFloat(
                        $complementaryRoots[0]->upperNumerator,
                        $complementaryRoots[0]->upperDenominator,
                    )
                ) / 2.0,
            ) - 0.25),
        );

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammaincc(1/2,x)=1', 'x', '-1', '1'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1/2,x)=0', 'x', '-1', '1'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('gammainc(1/2,x)=1', 'x', '0', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('gammaincc(1/2,x)=0', 'x', '0', '2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1/2,x)=1/4', 'x', '0', '2'),
        );
    }

    public function testHalfShapeRegularizedGammaSupportsQuadraticArguments(): void
    {
        foreach (
            [
                ['gammainc(1/2,x^2)=3/4', 'p', 0.75],
                ['gammaincc(1/2,x^2)=1/4', 'q', 0.25],
            ] as [$equation, $tail, $target]
        ) {
            self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-1', '1'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-1', '1');
            self::assertNotNull($roots);
            self::assertCount(2, $roots);
            foreach ($roots as $root) {
                self::assertFalse($root->isExactRoot);
                $midpoint = (
                    $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                    + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
                ) / 2.0;
                $value = $tail === 'p'
                    ? \MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, $midpoint ** 2)
                    : \MathPHP\Function\SpecialFunctions::regularizedGammaQ(0.5, $midpoint ** 2);
                self::assertLessThan(1.0E-6, \abs($value - $target));
            }
        }
    }

    public function testHalfShapeRegularizedGammaSupportsHigherDegreePolynomialArguments(): void
    {
        foreach (
            [
                ['gammainc(1/2,x^3)=3/4', 1],
                ['gammainc(1/2,x^4)=3/4', 2],
                ['gammaincc(1/2,x^3)=1/4', 1],
                ['gammainc(1/2,(x^2-1)^2)=3/4', 4],
            ] as [$equation, $expectedCount]
        ) {
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'),
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
            foreach ($roots as $root) {
                $midpoint = (
                    $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                    + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
                ) / 2.0;
                $isComplement = str_starts_with($equation, 'gammaincc');
                $argument = str_contains($equation, '(x^2-1)^2')
                    ? ($midpoint ** 2 - 1.0) ** 2
                    : $midpoint ** (str_contains($equation, 'x^3') ? 3 : 4);
                $value = $isComplement
                    ? \MathPHP\Function\SpecialFunctions::regularizedGammaQ(0.5, $argument)
                    : \MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, $argument);
                self::assertLessThan(
                    1.0E-6,
                    \abs($value - ($isComplement ? 0.25 : 0.75)),
                );
            }
        }

        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('gammainc(1/2,-x^4)=3/4', 'x', '-2', '2'),
        );
    }

    public function testHalfShapeRegularizedGammaSupportsLinearRationalArguments(): void
    {
        foreach (
            [
                ['gammainc(1/2,(x-1)/(x+1))=3/4', '1', '10', 'p', 0.75],
                ['gammaincc(1/2,1/(x+1))=1/4', '0', '2', 'q', 0.25],
            ] as [$equation, $lower, $upper, $tail, $target]
        ) {
            self::assertSame(
                1,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            $midpoint = (
                $this->rationalFractionToFloat($roots[0]->lowerNumerator, $roots[0]->lowerDenominator)
                + $this->rationalFractionToFloat($roots[0]->upperNumerator, $roots[0]->upperDenominator)
            ) / 2.0;
            $argument = $tail === 'p'
                ? ($midpoint - 1.0) / ($midpoint + 1.0)
                : 1.0 / ($midpoint + 1.0);
            $value = $tail === 'p'
                ? \MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, $argument)
                : \MathPHP\Function\SpecialFunctions::regularizedGammaQ(0.5, $argument);
            self::assertLessThan(1.0E-6, \abs($value - $target));
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval(
                'gammainc(1/2,(x-1)/(x+1))=0',
                'x',
                '0',
                '2',
            ),
        );
        self::assertNull(
            Math::countPolynomialRootsInRationalInterval(
                'gammainc(1/2,(x-1)/(x-1))=3/4',
                'x',
                '0',
                '2',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'gammainc(1/2,(x^2-1)/(x^2-1))=0',
                'x',
                '-2',
                '2',
            ),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'gammainc(1/2,(x^2-2)/(x^2-2))=0',
                'x',
                '-2',
                '2',
            ),
        );
        self::assertSame(
            [],
            Math::isolatePolynomialRootsInRationalInterval(
                'gammainc(1/2,(x^2-2)/(x^2-2))=0',
                'x',
                '-2',
                '2',
            ),
        );

        $reciprocalSquare = 'gammainc(1/2,1/x^2)=3/4';
        self::assertSame(
            2,
            Math::countPolynomialRootsInRationalInterval($reciprocalSquare, 'x', '-2', '2'),
        );
        $reciprocalRoots = Math::isolatePolynomialRootsInRationalInterval(
            $reciprocalSquare,
            'x',
            '-2',
            '2',
        );
        self::assertNotNull($reciprocalRoots);
        self::assertCount(2, $reciprocalRoots);
        foreach ($reciprocalRoots as $root) {
            $midpoint = (
                $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
            ) / 2.0;
            self::assertLessThan(
                1.0E-6,
                \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, 1.0 / ($midpoint ** 2)) - 0.75),
            );
        }
    }

    public function testHalfShapeRegularizedGammaSupportsQuadraticRationalArguments(): void
    {
        $equation = 'gammainc(1/2,(x^2+1)/(x^2+2))=3/4';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $root) {
            $midpoint = (
                $this->rationalFractionToFloat($root->lowerNumerator, $root->lowerDenominator)
                + $this->rationalFractionToFloat($root->upperNumerator, $root->upperDenominator)
            ) / 2.0;
            $argument = (($midpoint ** 2) + 1.0) / (($midpoint ** 2) + 2.0);
            self::assertLessThan(
                1.0E-6,
                \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(0.5, $argument) - 0.75),
            );
        }
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval(
                'gammainc(1/2,(x^2+1)/(x^2+2))=1/4',
                'x',
                '-2',
                '2',
            ),
        );
    }

    public function testShapeOneRegularizedGammaLevelsUseCertifiedExponentialIsolation(): void
    {
        foreach (['gammainc(1,x)=1/2', 'gammaincc(1,x)=1/2'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '2'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '2');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertFalse($roots[0]->isExactRoot);
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1,2*x-1)=1/2', 'x', '0', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('gammainc(1,2*x-1)=1/2', 'x', '0', '1/2'),
        );
        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1,x)=0', 'x', '-1', '1'),
        );
        $boundaryRoot = Math::isolatePolynomialRootsInRationalInterval(
            'gammaincc(1,1-x)=1',
            'x',
            '0',
            '2',
        );
        self::assertNotNull($boundaryRoot);
        self::assertCount(1, $boundaryRoot);
        self::assertTrue($boundaryRoot[0]->isExactRoot);
        self::assertSame('1', $boundaryRoot[0]->lowerNumerator);
        self::assertSame('1', $boundaryRoot[0]->lowerDenominator);
    }

    public function testPositiveIntegerShapeRegularizedGammaUsesFiniteTailSeries(): void
    {
        foreach (['gammainc(2,x)=1/2', 'gammaincc(2,x)=1/2'] as $equation) {
            self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', '0', '4'));
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '0', '4');
            self::assertNotNull($roots);
            self::assertCount(1, $roots);
            self::assertFalse($roots[0]->isExactRoot);
            $midpoint = (new ExactRationalInterval(
                [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
                [$roots[0]->lowerNumerator, $roots[0]->lowerDenominator],
            ))->add(new ExactRationalInterval(
                [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
                [$roots[0]->upperNumerator, $roots[0]->upperDenominator],
            ))->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))->lower;
            self::assertLessThan(
                1.0E-6,
                \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(
                    2.0,
                    $this->rationalFractionToFloat($midpoint[0], $midpoint[1]),
                ) - 0.5),
            );
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(2,2-x)=1/2', 'x', '0', '2'),
        );
        self::assertSame(
            0,
            Math::countPolynomialRootsInRationalInterval('gammainc(2,2-x)=1/2', 'x', '1', '2'),
        );
    }

    public function testRegularizedGammaRootCountDoesNotDependOnIsolationWidthNearBounds(): void
    {
        $equation = 'gammainc(1,x)=1/2';
        $lower = '693147180559945309417232121458/1000000000000000000000000000000';
        $upper = '693147180559945309417232121459/1000000000000000000000000000000';

        self::assertSame(1, Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
        self::assertNotNull($roots);
        self::assertCount(1, $roots);
    }

    public function testShapeOneRegularizedGammaSupportsPolynomialArguments(): void
    {
        foreach (
            [
                ['gammainc(1,x^2)=1/2', '-2', '2', 2],
                ['gammainc(1,x^3)=1/2', '-2', '2', 1],
            ] as [$equation, $lower, $upper, $expectedCount]
        ) {
            self::assertSame(
                $expectedCount,
                Math::countPolynomialRootsInRationalInterval($equation, 'x', $lower, $upper),
            );
            $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', $lower, $upper);
            self::assertNotNull($roots);
            self::assertCount($expectedCount, $roots);
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(1,x^2)=0', 'x', '-1', '1'),
        );
        $zeroRoot = Math::isolatePolynomialRootsInRationalInterval(
            'gammainc(1,x^2)=0',
            'x',
            '-1',
            '1',
        );
        self::assertNotNull($zeroRoot);
        self::assertCount(1, $zeroRoot);
        self::assertTrue($zeroRoot[0]->isExactRoot);
        self::assertSame('0', $zeroRoot[0]->lowerNumerator);
    }

    public function testHigherIntegerShapeRegularizedGammaSupportsPolynomialArguments(): void
    {
        $equation = 'gammainc(2,x^2)=1/2';
        self::assertSame(2, Math::countPolynomialRootsInRationalInterval($equation, 'x', '-2', '2'));
        $roots = Math::isolatePolynomialRootsInRationalInterval($equation, 'x', '-2', '2');
        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        foreach ($roots as $root) {
            $midpoint = (new ExactRationalInterval(
                [$root->lowerNumerator, $root->lowerDenominator],
                [$root->lowerNumerator, $root->lowerDenominator],
            ))->add(new ExactRationalInterval(
                [$root->upperNumerator, $root->upperDenominator],
                [$root->upperNumerator, $root->upperDenominator],
            ))->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))->lower;
            $argument = $this->rationalFractionToFloat($midpoint[0], $midpoint[1]);
            self::assertLessThan(
                1.0E-6,
                \abs(\MathPHP\Function\SpecialFunctions::regularizedGammaP(2.0, $argument ** 2) - 0.5),
            );
        }

        self::assertSame(
            1,
            Math::countPolynomialRootsInRationalInterval('gammainc(2,x^3)=1/2', 'x', '-2', '2'),
        );
    }

    private function rationalFractionToFloat(string $numerator, string $denominator): float
    {
        $negative = str_starts_with($numerator, '-');
        $numerator = ltrim($numerator, '-');
        if ($numerator === '0') {
            return 0.0;
        }

        $numeratorLength = strlen($numerator);
        $denominatorLength = strlen($denominator);
        $numeratorSignificand = (float) substr($numerator, 0, 16);
        $denominatorSignificand = (float) substr($denominator, 0, 16);
        $exponent = $numeratorLength - $denominatorLength;
        $numeratorSignificand /= 10 ** (min(16, $numeratorLength) - 1);
        $denominatorSignificand /= 10 ** (min(16, $denominatorLength) - 1);
        $value = ($numeratorSignificand / $denominatorSignificand) * (10 ** $exponent);

        return $negative ? -$value : $value;
    }
}
