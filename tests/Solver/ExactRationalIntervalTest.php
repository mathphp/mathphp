<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Solver\ExactRationalInterval;
use PHPUnit\Framework\TestCase;

final class ExactRationalIntervalTest extends TestCase
{
    public function testNormalizesEndpointsAndPerformsExactAdditionAndSubtraction(): void
    {
        $interval = new ExactRationalInterval(['2', '4'], ['3', '6']);
        $other = new ExactRationalInterval(['1', '3'], ['2', '3']);

        self::assertSame(['1', '2'], $interval->lower);
        self::assertSame(['1', '2'], $interval->upper);
        self::assertSame(['5', '6'], $interval->add($other)->lower);
        self::assertSame(['7', '6'], $interval->add($other)->upper);
        self::assertSame(['-1', '6'], $interval->subtract($other)->lower);
        self::assertSame(['1', '6'], $interval->subtract($other)->upper);
    }

    public function testMultiplicationUsesEveryEndpointCombination(): void
    {
        $positive = new ExactRationalInterval(['2', '3'], ['3', '2']);
        $negative = new ExactRationalInterval(['-2', '1'], ['-1', '3']);
        $mixed = new ExactRationalInterval(['-1', '2'], ['2', '1']);

        self::assertSame(['-3', '1'], $positive->multiply($negative)->lower);
        self::assertSame(['-2', '9'], $positive->multiply($negative)->upper);
        self::assertSame(['-3', '4'], $positive->multiply($mixed)->lower);
        self::assertSame(['3', '1'], $positive->multiply($mixed)->upper);
        self::assertSame(['-1', '1'], $mixed->multiply($mixed)->lower);
        self::assertSame(['4', '1'], $mixed->multiply($mixed)->upper);
    }

    public function testDivisionReversesNegativeIntervalsAndRejectsZeroCrossing(): void
    {
        $positive = new ExactRationalInterval(['2', '1'], ['4', '1']);
        $negative = new ExactRationalInterval(['-4', '1'], ['-2', '1']);
        $crossingZero = new ExactRationalInterval(['-1', '2'], ['1', '2']);

        self::assertSame(['-2', '1'], $positive->divide($negative)->lower);
        self::assertSame(['-1', '2'], $positive->divide($negative)->upper);
        self::assertTrue($crossingZero->containsZero());
        $this->expectException(\DomainException::class);
        $positive->divide($crossingZero);
    }

    public function testExponentialEnclosuresAreMonotoneAndUseExactBounds(): void
    {
        $zero = new ExactRationalInterval(['0', '1'], ['0', '1']);
        $positive = new ExactRationalInterval(['1', '1'], ['1', '1']);
        $negative = new ExactRationalInterval(['-1', '1'], ['-1', '1']);
        $range = new ExactRationalInterval(['0', '1'], ['1', '1']);

        self::assertSame(['1', '1'], $zero->exp()->lower);
        self::assertSame(['1', '1'], $zero->exp()->upper);
        self::assertTrue($positive->exp()->multiply($negative->exp())->containsRational('1', '1'));
        self::assertTrue($range->exp()->containsRational('1', '1'));
        self::assertFalse($range->exp()->containsZero());
    }

    public function testNaturalLogarithmEnclosuresUseExactRangeReduction(): void
    {
        $one = new ExactRationalInterval(['1', '1'], ['1', '1']);
        $two = new ExactRationalInterval(['2', '1'], ['2', '1']);
        $half = new ExactRationalInterval(['1', '2'], ['1', '2']);
        $lnTwo = $two->ln();
        $lnHalf = $half->ln();
        $nearOne = new ExactRationalInterval(['999999', '1000000'], ['999999', '1000000']);
        $lnNearOne = $nearOne->ln();
        $nearTwo = new ExactRationalInterval(['1999999', '1000000'], ['1999999', '1000000']);
        $lnNearTwo = $nearTwo->ln();

        self::assertSame(['0', '1'], $one->ln()->lower);
        self::assertSame(['0', '1'], $one->ln()->upper);
        self::assertGreaterThan(
            0,
            $lnNearOne->compareLowerToRational('-1000001', '1000000000000'),
        );
        self::assertLessThan(0, $lnNearOne->compareUpperToRational('-1', '1000000'));
        self::assertGreaterThan(0, $lnNearTwo->compareLowerToRational('693146', '1000000'));
        self::assertLessThan(0, $lnNearTwo->compareUpperToRational('693147', '1000000'));
        self::assertGreaterThan(
            0,
            $lnTwo->compareLowerToRational('693147180559945309417232121457', '1000000000000000000000000000000'),
        );
        self::assertLessThan(
            0,
            $lnTwo->compareUpperToRational('693147180559945309417232121459', '1000000000000000000000000000000'),
        );
        self::assertTrue($lnTwo->add($lnHalf)->containsRational('0', '1'));
        self::assertTrue($lnTwo->exp()->containsRational('2', '1'));

        $four = new ExactRationalInterval(['4', '1'], ['4', '1']);
        $quarter = new ExactRationalInterval(['1', '4'], ['1', '4']);
        $lnFour = $four->ln();
        $lnQuarter = $quarter->ln();
        self::assertGreaterThan(
            0,
            $lnFour->compareLowerToRational('1386294361119890618834464242915', '1000000000000000000000000000000'),
        );
        self::assertLessThan(
            0,
            $lnFour->compareUpperToRational('1386294361119890618834464242917', '1000000000000000000000000000000'),
        );
        self::assertTrue($lnQuarter->add($lnFour)->containsRational('0', '1'));

        $crossingPower = new ExactRationalInterval(['3', '2'], ['3', '1'])->ln();
        self::assertGreaterThan(
            0,
            $crossingPower->compareLowerToRational('405465108108164381978013', '1000000000000000000000000'),
        );
        self::assertGreaterThan(
            0,
            $crossingPower->compareUpperToRational('109861228866810969139524', '100000000000000000000000'),
        );

        $this->expectException(\DomainException::class);
        (new ExactRationalInterval(['0', '1'], ['1', '1']))->ln();
    }

    public function testHyperbolicSineEnclosuresAreMonotoneAndOdd(): void
    {
        $zero = new ExactRationalInterval(['0', '1'], ['0', '1']);
        $positive = new ExactRationalInterval(['1', '1'], ['1', '1'])->sinh();
        $negative = new ExactRationalInterval(['-1', '1'], ['-1', '1'])->sinh();
        $range = new ExactRationalInterval(['-1', '1'], ['1', '1'])->sinh();
        $positiveNegated = $positive->negateInterval();

        self::assertSame(['0', '1'], $zero->sinh()->lower);
        self::assertSame(['0', '1'], $zero->sinh()->upper);
        self::assertSame($positiveNegated->lower, $negative->lower);
        self::assertSame($positiveNegated->upper, $negative->upper);
        self::assertTrue($range->containsZero());
        self::assertSame($negative->lower, $range->lower);
        self::assertSame($positive->upper, $range->upper);
    }

    public function testHyperbolicCosineEnclosuresRespectEvennessAndTheMinimum(): void
    {
        $zero = new ExactRationalInterval(['0', '1'], ['0', '1'])->cosh();
        $positive = new ExactRationalInterval(['1', '1'], ['1', '1'])->cosh();
        $negative = new ExactRationalInterval(['-1', '1'], ['-1', '1'])->cosh();
        $crossing = new ExactRationalInterval(['-1', '1'], ['1', '1'])->cosh();

        self::assertSame(['1', '1'], $zero->lower);
        self::assertSame(['1', '1'], $zero->upper);
        self::assertSame($positive->lower, $negative->lower);
        self::assertSame($positive->upper, $negative->upper);
        self::assertSame(['1', '1'], $crossing->lower);
        self::assertSame($positive->upper, $crossing->upper);
    }

    public function testHyperbolicTangentEnclosuresAreMonotoneAndOdd(): void
    {
        $zero = new ExactRationalInterval(['0', '1'], ['0', '1'])->tanh();
        $positive = new ExactRationalInterval(['1', '1'], ['1', '1'])->tanh();
        $negative = new ExactRationalInterval(['-1', '1'], ['-1', '1'])->tanh();
        $range = new ExactRationalInterval(['-1', '1'], ['1', '1'])->tanh();

        self::assertSame(['0', '1'], $zero->lower);
        self::assertSame(['0', '1'], $zero->upper);
        self::assertSame($positive->negateInterval()->lower, $negative->lower);
        self::assertSame($positive->negateInterval()->upper, $negative->upper);
        self::assertSame($negative->lower, $range->lower);
        self::assertSame($positive->upper, $range->upper);
        self::assertGreaterThan(0, $range->compareLowerToRational('-1', '1'));
        self::assertLessThan(0, $range->compareUpperToRational('1', '1'));
    }

    public function testErrorFunctionEnclosuresRespectMonotonicityAndComplementarity(): void
    {
        $zero = new ExactRationalInterval(['0', '1'], ['0', '1']);
        $point = new ExactRationalInterval(['1', '2'], ['1', '2']);
        $range = new ExactRationalInterval(['0', '1'], ['1', '2']);
        $erf = $point->erf();
        $coarseErf = $point->erf(10);
        $erfc = $point->erfc();

        self::assertSame(['0', '1'], $zero->erf()->lower);
        self::assertSame(['0', '1'], $zero->erf()->upper);
        self::assertTrue($erf->containsRational('5204998778130465376827466', '10000000000000000000000000'));
        self::assertTrue($coarseErf->containsRational(
            '5204998778130465376827466',
            '10000000000000000000000000',
        ));
        self::assertLessThanOrEqual(1.0E-20, (float) $erf->upper[0] / (float) $erf->upper[1]
            - (float) $erf->lower[0] / (float) $erf->lower[1]);
        self::assertTrue($erf->add($erfc)->containsRational('1', '1'));
        self::assertSame($zero->erf()->lower, $range->erf()->lower);
        self::assertSame($point->erf()->upper, $range->erf()->upper);

        $eight = new ExactRationalInterval(['8', '1'], ['8', '1']);
        self::assertTrue($eight->erfc()->containsRational(
            '1122429717298292707996203',
            '1' . str_repeat('0', 53),
        ));

        $sixtyFour = new ExactRationalInterval(['64', '1'], ['64', '1']);
        $erfAtSixtyFour = $sixtyFour->erf();
        $erfcAtSixtyFour = $sixtyFour->erfc();
        $erfWidth = (new ExactRationalInterval($erfAtSixtyFour->upper, $erfAtSixtyFour->upper))
            ->subtract(new ExactRationalInterval($erfAtSixtyFour->lower, $erfAtSixtyFour->lower));
        self::assertLessThanOrEqual(0, $erfWidth->compareUpperToRational('1', '1000000'));
        self::assertGreaterThan(0, (new \MathPHP\Solver\IntegerPolynomialExtractor('', []))
            ->sign($erfcAtSixtyFour->upper[0]));
    }

    public function testSquareRootEnclosuresUseExactRationalBounds(): void
    {
        $exact = (new ExactRationalInterval(['9', '1'], ['9', '1']))->sqrt();
        $irrational = (new ExactRationalInterval(['2', '1'], ['2', '1']))->sqrt();
        $lower = new ExactRationalInterval($irrational->lower, $irrational->lower);
        $upper = new ExactRationalInterval($irrational->upper, $irrational->upper);

        self::assertSame(['3', '1'], $exact->lower);
        self::assertSame(['3', '1'], $exact->upper);
        self::assertLessThanOrEqual(0, $lower->multiply($lower)->compareUpperToRational('2', '1'));
        self::assertGreaterThanOrEqual(0, $upper->multiply($upper)->compareLowerToRational('2', '1'));
        self::assertFalse($irrational->lower === $irrational->upper);
    }

    public function testSquareRootRejectsIntervalsOutsideTheRealDomain(): void
    {
        $negative = new ExactRationalInterval(['-1', '1'], ['0', '1']);

        $this->expectException(\DomainException::class);
        $negative->sqrt();
    }

    public function testArctangentEnclosuresUseRangeReductionAndDirectedRounding(): void
    {
        $one = new ExactRationalInterval(['1', '1'], ['1', '1'])->atan();
        $half = new ExactRationalInterval(['1', '2'], ['1', '2'])->atan();
        $negativeHalf = new ExactRationalInterval(['-1', '2'], ['-1', '2'])->atan();
        $range = new ExactRationalInterval(['-1', '2'], ['1', '2'])->atan();
        $large = new ExactRationalInterval(['10', '1'], ['10', '1'])->atan();

        self::assertGreaterThan(0, $one->compareLowerToRational('785398', '1000000'));
        self::assertLessThan(0, $one->compareUpperToRational('785399', '1000000'));
        self::assertGreaterThan(0, $half->compareLowerToRational('463647', '1000000'));
        self::assertLessThan(0, $half->compareUpperToRational('463648', '1000000'));
        self::assertSame($half->negateInterval()->lower, $negativeHalf->lower);
        self::assertSame($half->negateInterval()->upper, $negativeHalf->upper);
        self::assertSame($negativeHalf->lower, $range->lower);
        self::assertSame($half->upper, $range->upper);
        self::assertGreaterThan(0, $large->compareLowerToRational('1471127', '1000000'));
        self::assertLessThan(0, $large->compareUpperToRational('1471128', '1000000'));
    }

    public function testInverseSineAndCosineEnclosuresUseExactDomains(): void
    {
        $half = new ExactRationalInterval(['1', '2'], ['1', '2']);
        $asin = $half->asin();
        $acos = $half->acos();
        $acot = (new ExactRationalInterval(['10', '1'], ['10', '1']))->acot();
        $fullDomain = (new ExactRationalInterval(['-1', '1'], ['1', '1']))->asin();

        self::assertGreaterThan(0, $asin->compareLowerToRational('523598', '1000000'));
        self::assertLessThan(0, $asin->compareUpperToRational('523599', '1000000'));
        self::assertGreaterThan(0, $acos->compareLowerToRational('1047197', '1000000'));
        self::assertLessThan(0, $acos->compareUpperToRational('1047198', '1000000'));
        self::assertGreaterThan(0, $fullDomain->compareLowerToRational('-1570797', '1000000'));
        self::assertLessThan(0, $fullDomain->compareUpperToRational('1570797', '1000000'));
        self::assertGreaterThan(0, $acot->compareLowerToRational('99668', '1000000'));
        self::assertLessThan(0, $acot->compareUpperToRational('99669', '1000000'));
    }

    public function testReciprocalInverseTrigEnclosuresRespectBothRealBranches(): void
    {
        $two = new ExactRationalInterval(['2', '1'], ['2', '1']);
        $asec = $two->asec();
        $acsc = $two->acsc();
        $negativeTwo = new ExactRationalInterval(['-2', '1'], ['-2', '1']);
        $negativeAsec = $negativeTwo->asec();
        $negativeAcsc = $negativeTwo->acsc();

        self::assertGreaterThan(0, $asec->compareLowerToRational('1047197', '1000000'));
        self::assertLessThan(0, $asec->compareUpperToRational('1047198', '1000000'));
        self::assertGreaterThan(0, $acsc->compareLowerToRational('523598', '1000000'));
        self::assertLessThan(0, $acsc->compareUpperToRational('523599', '1000000'));
        self::assertGreaterThan(0, $negativeAsec->compareLowerToRational('2094395', '1000000'));
        self::assertLessThan(0, $negativeAsec->compareUpperToRational('2094396', '1000000'));
        self::assertGreaterThan(0, $negativeAcsc->compareLowerToRational('-523599', '1000000'));
        self::assertLessThan(0, $negativeAcsc->compareUpperToRational('-523598', '1000000'));

        $negativeBranch = (new ExactRationalInterval(['-2', '1'], ['-1', '1']))->asec();
        $positiveBranch = (new ExactRationalInterval(['1', '1'], ['2', '1']))->acsc();
        self::assertLessThan(0, $negativeBranch->compareLowerToRational('3141592', '1000000'));
        self::assertLessThan(0, $negativeBranch->compareUpperToRational('3141593', '1000000'));
        self::assertGreaterThan(0, $positiveBranch->compareLowerToRational('523598', '1000000'));
        self::assertLessThan(0, $positiveBranch->compareUpperToRational('1570797', '1000000'));

        $this->expectException(\DomainException::class);
        (new ExactRationalInterval(['-1', '1'], ['1', '1']))->asec();
    }

    public function testReciprocalInverseTrigBranchesPreserveTheirComplementaryIdentity(): void
    {
        foreach (
            [
                [new ExactRationalInterval(['2', '1'], ['3', '1']), 'positive'],
                [new ExactRationalInterval(['-3', '1'], ['-2', '1']), 'negative'],
            ] as [$argument, $branch]
        ) {
            $sum = $argument->asec()->add($argument->acsc());
            self::assertTrue($sum->containsRational('1570796', '1000000'), $branch);
            self::assertTrue($sum->containsRational('1570797', '1000000'), $branch);
        }
    }

    public function testArctangentEnclosesIntervalsBeyondUnitMagnitude(): void
    {
        $outside = new ExactRationalInterval(['1', '2'], ['3', '2']);
        $range = $outside->atan();

        self::assertGreaterThan(0, $range->compareLowerToRational('463646', '1000000'));
        self::assertLessThan(0, $range->compareLowerToRational('463648', '1000000'));
        self::assertGreaterThan(0, $range->compareUpperToRational('982793', '1000000'));
        self::assertLessThan(0, $range->compareUpperToRational('982795', '1000000'));
    }

    public function testExponentialEnclosureSupportsInputsBeyondItsPreviousRange(): void
    {
        $larger = new ExactRationalInterval(['17', '1'], ['17', '1']);
        $negative = new ExactRationalInterval(['-17', '1'], ['-17', '1']);
        $farPositive = new ExactRationalInterval(['256', '1'], ['256', '1'])->exp();
        $farNegative = new ExactRationalInterval(['-256', '1'], ['-256', '1'])->exp();
        $rangeBoundary = new ExactRationalInterval(['4096', '1'], ['4096', '1'])->exp();
        $outsideRange = new ExactRationalInterval(['4097', '1'], ['4097', '1']);
        $positiveExponential = $larger->exp();
        $negativeExponential = $negative->exp();

        self::assertGreaterThan(0, $positiveExponential->compareLowerToRational('24154952', '1'));
        self::assertLessThan(0, $positiveExponential->compareUpperToRational('24154953', '1'));
        self::assertGreaterThan(0, $negativeExponential->compareLowerToRational('0', '1'));
        self::assertLessThan(0, $negativeExponential->compareUpperToRational('1', '10000000'));
        self::assertTrue($positiveExponential->multiply($negativeExponential)->containsRational('1', '1'));
        self::assertGreaterThan(
            0,
            $farPositive->compareLowerToRational('1' . str_repeat('0', 111), '1'),
        );
        self::assertLessThan(
            0,
            $farPositive->compareUpperToRational('1' . str_repeat('0', 112), '1'),
        );
        self::assertTrue($farPositive->multiply($farNegative)->containsRational('1', '1'));
        self::assertGreaterThan(
            0,
            $rangeBoundary->compareLowerToRational('1' . str_repeat('0', 1778), '1'),
        );
        self::assertLessThan(
            0,
            $rangeBoundary->compareUpperToRational('1' . str_repeat('0', 1779), '1'),
        );

        $this->expectException(\LengthException::class);
        $outsideRange->exp();
    }

    public function testRejectsZeroDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExactRationalInterval(['1', '-0'], ['2', '1']);
    }

    public function testRejectsReversedEndpoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExactRationalInterval(['2', '1'], ['1', '1']);
    }
}
