<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Solver\HyperbolicEquationSolver;
use MathPHP\Solver\InfiniteSolutionSetException;
use MathPHP\Solver\RationalRootInterval;
use PHPUnit\Framework\TestCase;

final class HyperbolicEquationSolverTest extends TestCase
{
    public function testIsolatesAffineHyperbolicSineForEitherSlopeSign(): void
    {
        $solver = new HyperbolicEquationSolver();
        $positiveSlope = $solver->isolateAffineHyperbolicSine(
            ['1', '2'],
            ['2', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['0', '1'],
            ['1', '1000'],
        );
        $negativeSlope = $solver->isolateAffineHyperbolicSine(
            ['1', '2'],
            ['-2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['1', '1'],
            ['1', '1000'],
        );

        self::assertNotNull($positiveSlope);
        self::assertCount(1, $positiveSlope);
        self::assertNotNull($negativeSlope);
        self::assertCount(1, $negativeSlope);

        $positiveRoot = (\asinh(0.5) - 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $positiveRoot,
            (float) $positiveSlope[0]->lowerNumerator / (float) $positiveSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $positiveRoot,
            (float) $positiveSlope[0]->upperNumerator / (float) $positiveSlope[0]->upperDenominator,
        );
        $negativeRoot = (1.0 - \asinh(0.5)) / 2.0;
        self::assertLessThanOrEqual(
            $negativeRoot,
            (float) $negativeSlope[0]->lowerNumerator / (float) $negativeSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $negativeRoot,
            (float) $negativeSlope[0]->upperNumerator / (float) $negativeSlope[0]->upperDenominator,
        );
    }

    public function testFindsExactZeroAndProvesAnEmptyInterval(): void
    {
        $solver = new HyperbolicEquationSolver();
        $exact = $solver->isolateAffineHyperbolicSine(
            ['0', '1'],
            ['2', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['0', '1'],
        );
        $empty = $solver->isolateAffineHyperbolicSine(
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );

        self::assertNotNull($exact);
        self::assertCount(1, $exact);
        self::assertTrue($exact[0]->isExactRoot);
        self::assertSame('-1', $exact[0]->lowerNumerator);
        self::assertSame('2', $exact[0]->lowerDenominator);
        self::assertSame([], $empty);
    }

    public function testConstantHyperbolicSineIdentityIsInfinite(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolateAffineHyperbolicSineEquation('0', '0', '0', '-1', '1');
    }

    public function testProvesEmptyRangeWhenExponentialEnclosureExceedsItsFormerLimit(): void
    {
        $roots = Math::isolateAffineHyperbolicSineEquation(
            '0',
            '1',
            '5000',
            '0',
            '1',
        );

        self::assertSame([], $roots);
    }

    public function testIsolatesBothHyperbolicCosineBranchesInOrder(): void
    {
        $roots = Math::isolateAffineHyperbolicCosineEquation('2', '2', '-1', '-1', '2', '1/1000');
        $oneBranch = Math::isolateAffineHyperbolicCosineEquation('2', '-2', '1', '0', '2', '1/1000');

        self::assertNotNull($roots);
        self::assertCount(2, $roots);
        self::assertNotNull($oneBranch);
        self::assertCount(1, $oneBranch);
        $expectedLower = (1.0 - \acosh(2.0)) / 2.0;
        $expectedUpper = (1.0 + \acosh(2.0)) / 2.0;
        self::assertLessThanOrEqual(
            $expectedLower,
            (float) $roots[0]->lowerNumerator / (float) $roots[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expectedLower,
            (float) $roots[0]->upperNumerator / (float) $roots[0]->upperDenominator,
        );
        self::assertLessThanOrEqual(
            $expectedUpper,
            (float) $roots[1]->lowerNumerator / (float) $roots[1]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expectedUpper,
            (float) $roots[1]->upperNumerator / (float) $roots[1]->upperDenominator,
        );
        $expectedOneBranch = (1.0 + \acosh(2.0)) / 2.0;
        self::assertLessThanOrEqual(
            $expectedOneBranch,
            (float) $oneBranch[0]->lowerNumerator / (float) $oneBranch[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expectedOneBranch,
            (float) $oneBranch[0]->upperNumerator / (float) $oneBranch[0]->upperDenominator,
        );
    }

    public function testHyperbolicCosineIsolationHandlesTangentAndEmptyCases(): void
    {
        $solver = new HyperbolicEquationSolver();
        $tangent = $solver->isolateAffineHyperbolicCosine(
            ['1', '1'],
            ['2', '1'],
            ['1', '1'],
            ['-1', '1'],
            ['0', '1'],
        );
        $belowMinimum = $solver->isolateAffineHyperbolicCosine(
            ['1', '2'],
            ['1', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );
        $outsideDomain = $solver->isolateAffineHyperbolicCosine(
            ['2', '1'],
            ['1', '1'],
            ['0', '1'],
            ['-1', '1'],
            ['1', '1'],
        );

        self::assertNotNull($tangent);
        self::assertCount(1, $tangent);
        self::assertTrue($tangent[0]->isExactRoot);
        self::assertSame('-1', $tangent[0]->lowerNumerator);
        self::assertSame('2', $tangent[0]->lowerDenominator);
        self::assertSame([], $belowMinimum);
        self::assertSame([], $outsideDomain);
    }

    public function testConstantHyperbolicCosineIdentityIsInfinite(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolateAffineHyperbolicCosineEquation('1', '0', '0', '-1', '1');
    }

    public function testIsolatesAffineHyperbolicTangentForEitherSlopeSign(): void
    {
        $positiveSlope = Math::isolateAffineHyperbolicTangentEquation('1/2', '2', '-1', '0', '1', '1/1000');
        $negativeSlope = Math::isolateAffineHyperbolicTangentEquation('1/2', '-2', '1', '0', '1', '1/1000');

        self::assertNotNull($positiveSlope);
        self::assertCount(1, $positiveSlope);
        self::assertNotNull($negativeSlope);
        self::assertCount(1, $negativeSlope);
        $positiveRoot = (\atanh(0.5) + 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $positiveRoot,
            (float) $positiveSlope[0]->lowerNumerator / (float) $positiveSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $positiveRoot,
            (float) $positiveSlope[0]->upperNumerator / (float) $positiveSlope[0]->upperDenominator,
        );
        $negativeRoot = (1.0 - \atanh(0.5)) / 2.0;
        self::assertLessThanOrEqual(
            $negativeRoot,
            (float) $negativeSlope[0]->lowerNumerator / (float) $negativeSlope[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $negativeRoot,
            (float) $negativeSlope[0]->upperNumerator / (float) $negativeSlope[0]->upperDenominator,
        );
    }

    public function testHyperbolicTangentIsolationRejectsUnattainableLimits(): void
    {
        self::assertSame([], Math::isolateAffineHyperbolicTangentEquation('1', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicTangentEquation('-1', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicTangentEquation('9/10', '1', '0', '-1', '1'));
    }

    public function testConstantHyperbolicTangentIdentityIsInfinite(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolateAffineHyperbolicTangentEquation('0', '0', '0', '-1', '1');
    }

    public function testCertifiesAffineReciprocalHyperbolicEquationFamilies(): void
    {
        $secant = Math::isolateAffineHyperbolicSecantEquation('1/2', '2', '-1', '-1', '2', '1/1000');
        $cosecant = Math::isolateAffineHyperbolicCosecantEquation('1/2', '2', '-1', '0', '2', '1/1000');
        $cotangent = Math::isolateAffineHyperbolicCotangentEquation('2', '2', '-1', '0', '1', '1/1000');

        self::assertNotNull($secant);
        self::assertCount(2, $secant);
        self::assertNotNull($cosecant);
        self::assertCount(1, $cosecant);
        self::assertNotNull($cotangent);
        self::assertCount(1, $cotangent);

        $expectedCosecant = (\asinh(2.0) + 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $expectedCosecant,
            (float) $cosecant[0]->lowerNumerator / (float) $cosecant[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expectedCosecant,
            (float) $cosecant[0]->upperNumerator / (float) $cosecant[0]->upperDenominator,
        );
        $expectedCotangent = (\atanh(0.5) + 1.0) / 2.0;
        self::assertLessThanOrEqual(
            $expectedCotangent,
            (float) $cotangent[0]->lowerNumerator / (float) $cotangent[0]->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $expectedCotangent,
            (float) $cotangent[0]->upperNumerator / (float) $cotangent[0]->upperDenominator,
        );
    }

    public function testReciprocalHyperbolicEquationsRejectUnattainableTargets(): void
    {
        self::assertSame([], Math::isolateAffineHyperbolicSecantEquation('0', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicSecantEquation('2', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicCosecantEquation('0', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicCotangentEquation('1', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineHyperbolicCotangentEquation('-1', '1', '0', '-1', '1'));
    }

    public function testIsolatesAffineInverseHyperbolicEquations(): void
    {
        $asinh = Math::isolateAffineInverseHyperbolicSineEquation('1/2', '2', '-1', '0', '1', '1/1000');
        $acosh = Math::isolateAffineInverseHyperbolicCosineEquation('1', '2', '-1', '-1', '2', '1/1000');
        $atanh = Math::isolateAffineInverseHyperbolicTangentEquation('1/2', '2', '-1', '0', '1', '1/1000');

        self::assertNotNull($asinh);
        self::assertNotNull($acosh);
        self::assertNotNull($atanh);
        self::assertCount(1, $asinh);
        self::assertCount(1, $acosh);
        self::assertCount(1, $atanh);

        self::assertIntervalContains($asinh[0], (\sinh(0.5) + 1.0) / 2.0);
        self::assertIntervalContains($acosh[0], (\cosh(1.0) + 1.0) / 2.0);
        self::assertIntervalContains($atanh[0], (\tanh(0.5) + 1.0) / 2.0);
    }

    public function testIsolatesReciprocalInverseHyperbolicEquations(): void
    {
        $asech = Math::isolateAffineInverseHyperbolicSecantEquation('1', '2', '-1', '0', '1', '1/1000');
        $acsch = Math::isolateAffineInverseHyperbolicCosecantEquation('1/2', '2', '-1', '0', '2', '1/1000');
        $acoth = Math::isolateAffineInverseHyperbolicCotangentEquation('2', '2', '-1', '0', '2', '1/1000');
        $negativeSlope = Math::isolateAffineInverseHyperbolicCosecantEquation(
            '1/2',
            '-2',
            '1',
            '-2',
            '0',
            '1/1000',
        );
        $negativeAcsch = Math::isolateAffineInverseHyperbolicCosecantEquation(
            '-1/2',
            '2',
            '-1',
            '-2',
            '0',
            '1/1000',
        );
        $negativeAcoth = Math::isolateAffineInverseHyperbolicCotangentEquation(
            '-2',
            '2',
            '-1',
            '-2',
            '0',
            '1/1000',
        );
        $largeAsech = Math::isolateAffineInverseHyperbolicSecantEquation(
            '10',
            '1',
            '0',
            '-1',
            '1',
            '1/1000',
        );

        self::assertNotNull($asech);
        self::assertNotNull($acsch);
        self::assertNotNull($acoth);
        self::assertNotNull($negativeSlope);
        self::assertNotNull($negativeAcsch);
        self::assertNotNull($negativeAcoth);
        self::assertNotNull($largeAsech);
        self::assertCount(1, $asech);
        self::assertCount(1, $acsch);
        self::assertCount(1, $acoth);
        self::assertCount(1, $negativeSlope);
        self::assertCount(1, $negativeAcsch);
        self::assertCount(1, $negativeAcoth);
        self::assertCount(1, $largeAsech);
        self::assertIntervalContains($asech[0], (1.0 / \cosh(1.0) + 1.0) / 2.0);
        self::assertIntervalContains($acsch[0], (1.0 / \sinh(0.5) + 1.0) / 2.0);
        self::assertIntervalContains($acoth[0], (\cosh(2.0) / \sinh(2.0) + 1.0) / 2.0);
        self::assertIntervalContains($negativeSlope[0], (1.0 - (1.0 / \sinh(0.5))) / 2.0);
        self::assertIntervalContains($negativeAcsch[0], (1.0 - (1.0 / \sinh(0.5))) / 2.0);
        self::assertIntervalContains($negativeAcoth[0], (1.0 - (\cosh(2.0) / \sinh(2.0))) / 2.0);
        self::assertIntervalContains($largeAsech[0], 1.0 / \cosh(10.0));
    }

    public function testReciprocalInverseHyperbolicEquationsRespectTheirRanges(): void
    {
        self::assertSame([], Math::isolateAffineInverseHyperbolicSecantEquation('-1', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineInverseHyperbolicCosecantEquation('0', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineInverseHyperbolicCotangentEquation('0', '1', '0', '-1', '1'));
        self::assertSame([], Math::isolateAffineInverseHyperbolicCotangentEquation('1', '1', '0', '-1', '1'));
    }

    public function testInverseHyperbolicCotangentAcceptsEveryNonzeroTarget(): void
    {
        $roots = Math::isolateAffineInverseHyperbolicCotangentEquation(
            '1/2',
            '1',
            '0',
            '2',
            '3',
            '1/1000',
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertIntervalContains($roots[0], \cosh(0.5) / \sinh(0.5));
    }

    public function testInverseHyperbolicTangentAcceptsEveryRealTarget(): void
    {
        $roots = Math::isolateAffineInverseHyperbolicTangentEquation(
            '2',
            '1',
            '0',
            '0',
            '1',
            '1/1000',
        );

        self::assertNotNull($roots);
        self::assertCount(1, $roots);
        self::assertIntervalContains($roots[0], \tanh(2.0));
    }

    public function testInverseHyperbolicSecantRecognizesItsExactBoundaryIdentity(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolateAffineInverseHyperbolicSecantEquation('0', '0', '1', '-1', '1');
    }

    public function testInverseHyperbolicEquationsHandleDomainsAndIdentities(): void
    {
        self::assertSame([], Math::isolateAffineInverseHyperbolicCosineEquation('-1', '1', '0', '-1', '1'));
        $positiveAtanh = Math::isolateAffineInverseHyperbolicTangentEquation('1', '1', '0', '-1', '1');
        $negativeAtanh = Math::isolateAffineInverseHyperbolicTangentEquation('-1', '1', '0', '-1', '1');
        self::assertNotNull($positiveAtanh);
        self::assertNotNull($negativeAtanh);
        self::assertCount(1, $positiveAtanh);
        self::assertCount(1, $negativeAtanh);
        self::assertIntervalContains($positiveAtanh[0], \tanh(1.0));
        self::assertIntervalContains($negativeAtanh[0], \tanh(-1.0));
        self::assertSame([], Math::isolateAffineInverseHyperbolicSineEquation('0', '1', '0', '1', '2'));

        $this->expectException(InfiniteSolutionSetException::class);
        Math::isolateAffineInverseHyperbolicSineEquation('0', '0', '0', '-1', '1');
    }

    private static function assertIntervalContains(RationalRootInterval $interval, float $value): void
    {
        self::assertLessThanOrEqual(
            $value + 1.0E-14,
            (float) $interval->lowerNumerator / (float) $interval->lowerDenominator,
        );
        self::assertGreaterThanOrEqual(
            $value - 1.0E-14,
            (float) $interval->upperNumerator / (float) $interval->upperDenominator,
        );
    }
}
