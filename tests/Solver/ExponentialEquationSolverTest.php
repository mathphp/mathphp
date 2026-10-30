<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Solver\ExponentialEquationSolver;
use PHPUnit\Framework\TestCase;

final class ExponentialEquationSolverTest extends TestCase
{
    public function testIsolatesLogarithmicRootsWithCertifiedRationalBounds(): void
    {
        $roots = (new ExponentialEquationSolver())->isolate(
            ['2', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isExactRoot);
        self::assertLessThanOrEqual(
            1.0E-3,
            ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
                - ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator),
        );
        self::assertLessThanOrEqual(
            \log(2.0),
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            \log(2.0),
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
    }

    public function testFindsExactRationalRootAndProvesAnEmptyDomain(): void
    {
        $solver = new ExponentialEquationSolver();
        $exact = $solver->isolate(['1', '1'], ['-1', '1'], ['1', '1']);
        $empty = $solver->isolate(['3', '1'], ['-1', '1'], ['1', '1']);
        $nonpositiveTarget = $solver->isolate(['0', '1'], ['-1', '1'], ['1', '1']);

        self::assertNotNull($exact);
        self::assertCount(1, $exact);
        self::assertTrue($exact[0]->isExactRoot);
        self::assertSame('0', $exact[0]->lowerNumerator);
        self::assertSame('1', $exact[0]->lowerDenominator);
        self::assertSame([], $empty);
        self::assertSame([], $nonpositiveTarget);
    }

    public function testProvesEmptyOutsideRangeAndReturnsNullWhenDepthPreventsCertification(): void
    {
        $solver = new ExponentialEquationSolver();
        $outsideRange = $solver->isolate(['100', '1'], ['4097', '1'], ['4098', '1']);
        $insufficientDepth = $solver->isolate(
            ['2', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000000000'],
            maxDepth: 1,
        );

        self::assertSame([], $outsideRange);
        self::assertNull($insufficientDepth);
    }

    public function testIsolatesExponentialEquationBeyondPreviousRangeLimit(): void
    {
        $roots = (new ExponentialEquationSolver())->isolate(
            ['25000000', '1'],
            ['169', '10'],
            ['171', '10'],
            ['1', '100000'],
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertLessThan(
            17.1,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
        self::assertGreaterThan(
            16.9,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
    }

    public function testIsolatesAffineExponentialRootsForEitherSlopeSign(): void
    {
        $solver = new ExponentialEquationSolver();
        $positiveSlope = $solver->isolateAffine(
            ['4', '1'],
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );
        $negativeSlope = $solver->isolateAffine(
            ['2', '1'],
            ['-2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );

        self::assertNotNull($positiveSlope);
        self::assertCount(1, $positiveSlope);
        self::assertLessThanOrEqual(
            (\log(4.0) - 1.0) / 2.0,
            (float) $positiveSlope[0]->lowerNumerator / (float) $positiveSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            (\log(4.0) - 1.0) / 2.0,
            (float) $positiveSlope[0]->upperNumerator / (float) $positiveSlope[0]->upperDenominator,
        );
        self::assertNotNull($negativeSlope);
        self::assertCount(1, $negativeSlope);
        self::assertLessThanOrEqual(
            (1.0 - \log(2.0)) / 2.0,
            (float) $negativeSlope[0]->lowerNumerator / (float) $negativeSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            (1.0 - \log(2.0)) / 2.0,
            (float) $negativeSlope[0]->upperNumerator / (float) $negativeSlope[0]->upperDenominator,
        );
    }

    public function testIsolatesQuadraticExponentialRootsAcrossTheVertex(): void
    {
        $solver = new ExponentialEquationSolver();
        $positiveQuadratic = $solver->isolateQuadratic(
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-2', '1'],
            ['2', '1'],
            ['1', '1000'],
        );
        $negativeQuadratic = $solver->isolateQuadratic(
            ['1', '2'],
            ['-1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-2', '1'],
            ['2', '1'],
            ['1', '1000'],
        );
        $tangent = $solver->isolateQuadratic(
            ['1', '1'],
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
        $noRoots = $solver->isolateQuadratic(
            ['1', '2'],
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );

        self::assertNotNull($positiveQuadratic);
        self::assertCount(2, $positiveQuadratic);
        self::assertLessThan(
            0.0,
            (float) $positiveQuadratic[0]->upperNumerator / (float) $positiveQuadratic[0]->upperDenominator,
        );
        self::assertGreaterThan(
            0.0,
            (float) $positiveQuadratic[1]->lowerNumerator / (float) $positiveQuadratic[1]->lowerDenominator,
        );
        self::assertNotNull($negativeQuadratic);
        self::assertCount(2, $negativeQuadratic);
        self::assertNotNull($tangent);
        self::assertCount(1, $tangent);
        self::assertTrue($tangent[0]->isExactRoot);
        self::assertSame([], $noRoots);
    }

    public function testQuadraticExponentialIsolationReducesAffineAndIdentityCases(): void
    {
        $solver = new ExponentialEquationSolver();
        $affine = $solver->isolateQuadratic(
            ['2', '1'],
            ['0', '1'],
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );

        self::assertNotNull($affine);
        self::assertCount(1, $affine);
        self::assertLessThanOrEqual(
            \log(2.0),
            (float) $affine[0]->lowerNumerator / (float) $affine[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            \log(2.0),
            (float) $affine[0]->upperNumerator / (float) $affine[0]->upperDenominator,
        );

        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        $solver->isolateQuadratic(
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
    }

    public function testConstantExponentialIdentityIsReportedAsInfinite(): void
    {
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        (new ExponentialEquationSolver())->isolateAffine(
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
    }

    public function testAffineIsolationScalesWidthForLargeSlope(): void
    {
        $roots = (new ExponentialEquationSolver())->isolateAffine(
            ['2', '1'],
            ['1000', '1'],
            ['0', '1'],
            ['0', '1'],
            ['1', '1000'],
            ['1', '1000'],
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        $width = ((float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator)
            - ((float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator);
        self::assertLessThanOrEqual(1.0E-3, $width);
        self::assertLessThanOrEqual(
            \log(2.0) / 1000.0,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            \log(2.0) / 1000.0,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
    }

    public function testIsolatesAffineLogarithmsAndProvesOutsideRoots(): void
    {
        $solver = new ExponentialEquationSolver();
        $root = $solver->isolateAffineLogarithm(
            ['1', '1'],
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );
        $outside = $solver->isolateAffineLogarithm(
            ['1', '1'],
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['1', '2'],
            ['1', '1000'],
        );
        $exact = $solver->isolateAffineLogarithm(
            ['0', '1'],
            ['1', '1'],
            ['1', '1'],
            ['0', '1'],
            ['0', '1'],
        );
        $negativeSlope = $solver->isolateAffineLogarithm(
            ['1', '1'],
            ['-2', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['0', '1'],
            ['1', '1000'],
        );

        self::assertNotNull($root);
        self::assertCount(1, $root);
        $expected = (\exp(1.0) - 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $expected + 1.0E-12,
            (float) $root[0]->lowerNumerator / (float) $root[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expected - 1.0E-12,
            (float) $root[0]->upperNumerator / (float) $root[0]->upperDenominator,
        );
        self::assertSame([], $outside);
        self::assertNotNull($exact);
        self::assertTrue($exact[0]->isExactRoot);
        self::assertSame('0', $exact[0]->lowerNumerator);
        self::assertNotNull($negativeSlope);
        self::assertCount(1, $negativeSlope);
        self::assertLessThanOrEqual(
            (1.0 - \exp(1.0)) / 2.0 + 1.0E-12,
            (float) $negativeSlope[0]->lowerNumerator / (float) $negativeSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            (1.0 - \exp(1.0)) / 2.0 - 1.0E-12,
            (float) $negativeSlope[0]->upperNumerator / (float) $negativeSlope[0]->upperDenominator,
        );
    }

    public function testConstantLogarithmIdentityIsReportedAsInfinite(): void
    {
        $this->expectException(\MathPHP\Solver\InfiniteSolutionSetException::class);
        (new ExponentialEquationSolver())->isolateAffineLogarithm(
            ['0', '1'],
            ['0', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
    }

    public function testConstantAffineLogarithmValidatesTheRequestedInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExponentialEquationSolver())->isolateAffineLogarithm(
            ['0', '1'],
            ['0', '1'],
            ['2', '1'],
            ['1', '1'],
            ['-1', '1'],
        );
    }

    public function testConstantAffineLogarithmValidatesTheMaximumWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExponentialEquationSolver())->isolateAffineLogarithm(
            ['0', '1'],
            ['0', '1'],
            ['2', '1'],
            ['-1', '1'],
            ['1', '1'],
            ['0', '1'],
        );
    }

    public function testIsolatesQuadraticLogarithmRootsAndClipsToDomain(): void
    {
        $solver = new ExponentialEquationSolver();
        $both = $solver->isolateQuadraticLogarithm(
            ['1', '1'],
            ['1', '1'],
            ['1', '1'],
            ['-2', '1'],
            ['2', '1'],
            ['1', '1000'],
        );
        $positiveOnly = $solver->isolateQuadraticLogarithm(
            ['1', '1'],
            ['1', '1'],
            ['1', '1'],
            ['0', '1'],
            ['2', '1'],
            ['1', '1000'],
        );
        $exactZero = $solver->isolateQuadraticLogarithm(
            ['0', '1'],
            ['2', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
        $noRealRoot = $solver->isolateQuadraticLogarithm(
            ['-1', '1'],
            ['1', '1'],
            ['1', '1'],
            ['-2', '1'],
            ['2', '1'],
        );

        self::assertNotNull($both);
        self::assertCount(2, $both);
        self::assertLessThan(0.0, (float) $both[0]->upperNumerator / (float) $both[0]->upperDenominator);
        self::assertGreaterThan(0.0, (float) $both[1]->lowerNumerator / (float) $both[1]->lowerDenominator);
        self::assertNotNull($positiveOnly);
        self::assertCount(1, $positiveOnly);
        self::assertNotNull($exactZero);
        self::assertCount(1, $exactZero);
        self::assertTrue($exactZero[0]->isExactRoot);
        self::assertSame([], $noRealRoot);
    }

    public function testIsolatesGeneralQuadraticLogarithmBranches(): void
    {
        $solver = new ExponentialEquationSolver();
        $twoRoots = $solver->isolateQuadraticPolynomialLogarithm(
            ['1', '1'],
            ['1', '1'],
            ['2', '1'],
            ['1', '1'],
            ['-3', '1'],
            ['1', '1'],
            ['1', '1000'],
        );
        $repeatedRoot = $solver->isolateQuadraticPolynomialLogarithm(
            ['0', '1'],
            ['1', '1'],
            ['2', '1'],
            ['2', '1'],
            ['-2', '1'],
            ['0', '1'],
        );
        $endpointRoot = $solver->isolateQuadraticPolynomialLogarithm(
            ['0', '1'],
            ['1', '1'],
            ['2', '1'],
            ['2', '1'],
            ['-1', '1'],
            ['-1', '1'],
        );
        $singletonWithoutRoot = $solver->isolateQuadraticPolynomialLogarithm(
            ['0', '1'],
            ['1', '1'],
            ['2', '1'],
            ['2', '1'],
            ['0', '1'],
            ['0', '1'],
        );

        self::assertNotNull($twoRoots);
        self::assertCount(2, $twoRoots);
        self::assertLessThan(0.0, (float) $twoRoots[0]->upperNumerator / (float) $twoRoots[0]->upperDenominator);
        self::assertGreaterThan(0.0, (float) $twoRoots[1]->lowerNumerator / (float) $twoRoots[1]->lowerDenominator);
        self::assertNotNull($repeatedRoot);
        self::assertCount(1, $repeatedRoot);
        self::assertTrue($repeatedRoot[0]->isExactRoot);
        self::assertSame('-1', $repeatedRoot[0]->lowerNumerator);
        self::assertNotNull($endpointRoot);
        self::assertCount(1, $endpointRoot);
        self::assertSame('-1', $endpointRoot[0]->lowerNumerator);
        self::assertSame([], $singletonWithoutRoot);
    }
}
