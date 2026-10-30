<?php

declare(strict_types=1);

namespace MathPHP\Solver;

/** A closed interval with exact rational endpoints. */
final readonly class ExactRationalInterval
{
    private const MAX_INTEGER_DIGITS = 8192;

    private const MAX_ATAN_PRECISION = 1000;

    private const EXP_TAYLOR_DEGREE = 32;

    private const MAX_EXP_RANGE_REDUCTIONS = 13;

    private const MAX_LOG_RANGE_REDUCTIONS = 2048;

    private const EXP_SCALE = '10000000000000000000000000000000000000000';

    private const ERF_TAYLOR_TERMS = 512;

    private const DAWSON_TAYLOR_TERMS = 1024;

    private const LOG_TAYLOR_TERMS = 256;

    private const MAX_LOG_PRECISION = 200;

    private IntegerPolynomialExtractor $arithmetic;

    /** @var array{string, string} */
    public array $lower;

    /** @var array{string, string} */
    public array $upper;

    /**
     * @param array{string, string} $lower Lower endpoint numerator and denominator.
     * @param array{string, string} $upper Upper endpoint numerator and denominator.
     */
    public function __construct(
        array $lower,
        array $upper,
    ) {
        $this->arithmetic = new IntegerPolynomialExtractor('', []);
        $lower = $this->normalize($lower);
        $upper = $this->normalize($upper);
        if ($this->compare($lower, $upper) > 0) {
            throw new \InvalidArgumentException('An interval lower bound must not exceed its upper bound.');
        }
        $this->lower = $lower;
        $this->upper = $upper;
    }

    public function add(self $other): self
    {
        return new self(
            $this->addFractions($this->lower, $other->lower),
            $this->addFractions($this->upper, $other->upper),
        );
    }

    public function subtract(self $other): self
    {
        return new self(
            $this->addFractions($this->lower, $this->negate($other->upper)),
            $this->addFractions($this->upper, $this->negate($other->lower)),
        );
    }

    public function negateInterval(): self
    {
        return new self($this->negate($this->upper), $this->negate($this->lower));
    }

    public function multiply(self $other): self
    {
        $products = [
            $this->multiplyFractions($this->lower, $other->lower),
            $this->multiplyFractions($this->lower, $other->upper),
            $this->multiplyFractions($this->upper, $other->lower),
            $this->multiplyFractions($this->upper, $other->upper),
        ];
        usort($products, fn (array $left, array $right): int => $this->compare($left, $right));

        return new self($products[0], $products[3]);
    }

    public function containsZero(): bool
    {
        return $this->arithmetic->sign($this->lower[0]) <= 0
            && $this->arithmetic->sign($this->upper[0]) >= 0;
    }

    public function containsRational(string $numerator, string $denominator): bool
    {
        $value = $this->normalize([$numerator, $denominator]);

        return $this->compare($this->lower, $value) <= 0
            && $this->compare($value, $this->upper) <= 0;
    }

    public function compareLowerToRational(string $numerator, string $denominator): int
    {
        return $this->compare($this->lower, $this->normalize([$numerator, $denominator]));
    }

    public function compareUpperToRational(string $numerator, string $denominator): int
    {
        return $this->compare($this->upper, $this->normalize([$numerator, $denominator]));
    }

    public function compareLowerEndpoint(self $other): int
    {
        return $this->compare($this->lower, $other->lower);
    }

    public function intersect(self $other): ?self
    {
        $lower = $this->compare($this->lower, $other->lower) >= 0 ? $this->lower : $other->lower;
        $upper = $this->compare($this->upper, $other->upper) <= 0 ? $this->upper : $other->upper;
        if ($this->compare($lower, $upper) > 0) {
            return null;
        }

        return new self($lower, $upper);
    }

    public function reciprocal(): self
    {
        if ($this->containsZero()) {
            throw new \DomainException('An interval containing zero has no bounded reciprocal interval.');
        }

        $lowerReciprocal = $this->normalize([$this->lower[1], $this->lower[0]]);
        $upperReciprocal = $this->normalize([$this->upper[1], $this->upper[0]]);

        return $this->compare($lowerReciprocal, $upperReciprocal) <= 0
            ? new self($lowerReciprocal, $upperReciprocal)
            : new self($upperReciprocal, $lowerReciprocal);
    }

    public function divide(self $other): self
    {
        return $this->multiply($other->reciprocal());
    }

    public function power(int $exponent): self
    {
        if ($exponent === 0) {
            return new self(['1', '1'], ['1', '1']);
        }
        if ($exponent === \PHP_INT_MIN) {
            throw new \LengthException('The exact rational interval exponent exceeds the supported range.');
        }
        if ($exponent < 0) {
            return $this->power(-$exponent)->reciprocal();
        }

        $lowerPower = $this->powerFraction($this->lower, $exponent);
        $upperPower = $this->powerFraction($this->upper, $exponent);
        if (($exponent % 2) !== 0) {
            return new self($lowerPower, $upperPower);
        }

        if ($this->containsZero()) {
            $upper = $this->compare($lowerPower, $upperPower) >= 0 ? $lowerPower : $upperPower;

            return new self(['0', '1'], $upper);
        }
        if ($this->arithmetic->sign($this->upper[0]) < 0) {
            return new self($upperPower, $lowerPower);
        }

        return new self($lowerPower, $upperPower);
    }

    /** Enclose exp(x) over this interval using exact rational bounds. */
    public function exp(): self
    {
        $lower = $this->expPoint($this->lower);
        $upper = $this->expPoint($this->upper);

        return new self($lower[0], $upper[1]);
    }

    /** Enclose ln(x) for positive intervals whose endpoints are within 2^+/-2048. */
    public function ln(int $precision = 40): self
    {
        if ($precision < 1 || $precision > self::MAX_LOG_PRECISION) {
            throw new \InvalidArgumentException(
                'Certified logarithm precision must be between 1 and ' . self::MAX_LOG_PRECISION . ' digits.',
            );
        }
        if ($this->arithmetic->sign($this->lower[0]) <= 0) {
            throw new \DomainException('A real logarithm interval must be strictly positive.');
        }

        return new self(
            $this->lnPoint($this->lower, $precision)->lower,
            $this->lnPoint($this->upper, $precision)->upper,
        );
    }

    /** Enclose sinh(x) over this interval using exact rational exp bounds. */
    public function sinh(): self
    {
        $lower = $this->sinhPoint($this->lower);
        $upper = $this->sinhPoint($this->upper);

        return new self($lower->lower, $upper->upper);
    }

    /** Enclose cosh(x) over this interval using exact rational exp bounds. */
    public function cosh(): self
    {
        $lowerValue = $this->coshPoint($this->lower);
        $upperValue = $this->coshPoint($this->upper);
        $lower = $this->containsZero()
            ? ['1', '1']
            : ($this->arithmetic->sign($this->lower[0]) > 0 ? $lowerValue->lower : $upperValue->lower);
        $lowerUpper = new self($lowerValue->upper, $lowerValue->upper);
        $upperUpper = new self($upperValue->upper, $upperValue->upper);
        $upper = $lowerUpper->compareLowerEndpoint($upperUpper) >= 0
            ? $lowerValue->upper
            : $upperValue->upper;

        return new self($lower, $upper);
    }

    /** Enclose tanh(x) over this interval using exact sinh and cosh bounds. */
    public function tanh(): self
    {
        $lower = $this->tanhPoint($this->lower);
        $upper = $this->tanhPoint($this->upper);

        return new self($lower->lower, $upper->upper);
    }

    /** Enclose erf(x) for intervals contained in [-64, 64] to a chosen decimal precision. */
    public function erf(int $precision = 30): self
    {
        if ($precision < 1 || $precision > 30) {
            throw new \InvalidArgumentException('The certified erf precision must be between 1 and 30 digits.');
        }
        if (
            $this->compareLowerToRational('-64', '1') < 0
            || $this->compareUpperToRational('64', '1') > 0
        ) {
            throw new \LengthException('The certified erf enclosure supports inputs in [-64, 64].');
        }
        if ($this->lower === $this->upper && $this->lower[0] === '0') {
            return new self(['0', '1'], ['0', '1']);
        }

        /** @var self|null $scale */
        static $scale = null;
        if ($scale === null) {
            $five = new self(['1', '5'], ['1', '5']);
            $twoHundredThirtyNine = new self(['1', '239'], ['1', '239']);
            $pi = $five->atan()->multiply(new self(['16', '1'], ['16', '1']))
                ->subtract($twoHundredThirtyNine->atan()->multiply(new self(['4', '1'], ['4', '1'])));
            $scale = new self(['2', '1'], ['2', '1'])->divide($pi->sqrt());
        }
        if ($this->lower === $this->upper) {
            return $this->erfPoint($this->lower, $scale, $precision);
        }

        return new self(
            $this->erfPoint($this->lower, $scale, $precision)->lower,
            $this->erfPoint($this->upper, $scale, $precision)->upper,
        );
    }

    /** Enclose erfc(x) for intervals contained in [-64, 64] to a chosen decimal precision. */
    public function erfc(int $precision = 30): self
    {
        return (new self(['1', '1'], ['1', '1']))->subtract($this->erf($precision));
    }

    /** Enclose erfcx(x) for intervals contained in [-26, 26]. */
    public function erfcx(int $precision = 24): self
    {
        if (
            $this->compareLowerToRational('-26', '1') < 0
            || $this->compareUpperToRational('26', '1') > 0
        ) {
            throw new \LengthException('The certified erfcx enclosure supports inputs in [-26, 26].');
        }

        return new self(
            $this->erfcxPoint($this->upper, $precision)->lower,
            $this->erfcxPoint($this->lower, $precision)->upper,
        );
    }

    /** @param array{string, string} $value */
    private function erfcxPoint(array $value, int $precision): self
    {
        $point = new self($value, $value);
        $square = $point->power(2);

        return $square->exp()->multiply($point->erfc($precision));
    }

    /** Enclose Dawson's integral at a rational point with magnitude at most 16. */
    public function dawsonPoint(int $precision = 20): self
    {
        if ($this->lower !== $this->upper) {
            throw new \InvalidArgumentException('A Dawson point enclosure requires a singleton interval.');
        }
        if ($precision < 1 || $precision > 24) {
            throw new \InvalidArgumentException('The certified Dawson precision must be between 1 and 24 digits.');
        }
        if (
            $this->compareLowerToRational('-16', '1') < 0
            || $this->compareUpperToRational('16', '1') > 0
        ) {
            throw new \LengthException('The certified Dawson enclosure supports inputs in [-16, 16].');
        }
        if ($this->arithmetic->sign($this->lower[0]) === 0) {
            return new self(['0', '1'], ['0', '1']);
        }
        if ($this->arithmetic->sign($this->lower[0]) < 0) {
            return $this->negateInterval()->dawsonPoint($precision)->negateInterval();
        }

        $x = new self($this->lower, $this->lower);
        $xSquared = $x->power(2);
        $exponential = $xSquared->negateInterval()->exp();
        $term = $x;
        $sum = new self(['0', '1'], ['0', '1']);
        $toleranceDenominator = '1' . \str_repeat('0', $precision);
        for ($index = 1; $index <= self::DAWSON_TAYLOR_TERMS; ++$index) {
            $sum = $sum->add($term);
            $nextTerm = $term
                ->multiply($xSquared)
                ->multiply(new self(
                    [(string) (2 * $index - 1), '1'],
                    [(string) (2 * $index - 1), '1'],
                ))
                ->divide(new self(
                    [(string) ($index * (2 * $index + 1)), '1'],
                    [(string) ($index * (2 * $index + 1)), '1'],
                ));
            $ratioBound = $xSquared->divide(new self(
                [(string) ($index + 1), '1'],
                [(string) ($index + 1), '1'],
            ));
            if ($ratioBound->compareUpperToRational('1', '1') < 0) {
                $tail = $nextTerm->divide(
                    (new self(['1', '1'], ['1', '1']))->subtract($ratioBound),
                );
                if (
                    $exponential->multiply($tail)->compareUpperToRational('1', $toleranceDenominator) <= 0
                ) {
                    $integral = $sum->add(new self(['0', '1'], $tail->upper));

                    return $exponential->multiply($integral);
                }
            }
            $term = $nextTerm;
        }

        throw new \LengthException('The certified Dawson series exceeded its term limit.');
    }

    /** Enclose erfi(x) for intervals contained in [-8, 8] to a chosen decimal precision. */
    public function erfi(int $precision = 30): self
    {
        if ($precision < 1 || $precision > 30) {
            throw new \InvalidArgumentException('The certified erfi precision must be between 1 and 30 digits.');
        }
        if (
            $this->compareLowerToRational('-8', '1') < 0
            || $this->compareUpperToRational('8', '1') > 0
        ) {
            throw new \LengthException('The certified erfi enclosure supports inputs in [-8, 8].');
        }

        /** @var self|null $scale */
        static $scale = null;
        if ($scale === null) {
            $five = new self(['1', '5'], ['1', '5']);
            $twoHundredThirtyNine = new self(['1', '239'], ['1', '239']);
            $pi = $five->atan()->multiply(new self(['16', '1'], ['16', '1']))
                ->subtract($twoHundredThirtyNine->atan()->multiply(new self(['4', '1'], ['4', '1'])));
            $scale = new self(['2', '1'], ['2', '1'])->divide($pi->sqrt());
        }
        if ($this->lower === $this->upper) {
            return $this->erfiPoint($this->lower, $scale, $precision);
        }

        return new self(
            $this->erfiPoint($this->lower, $scale, $precision)->lower,
            $this->erfiPoint($this->upper, $scale, $precision)->upper,
        );
    }

    /** @param array{string, string} $value */
    private function erfiPoint(array $value, self $scale, int $precision): self
    {
        $sign = $this->arithmetic->sign($value[0]);
        if ($sign < 0) {
            return $this->erfiPoint($this->negate($value), $scale, $precision)->negateInterval();
        }
        if ($sign === 0) {
            return new self(['0', '1'], ['0', '1']);
        }

        $x = new self($value, $value);
        $xSquared = $x->multiply($x);
        $term = $x;
        $sum = new self(['0', '1'], ['0', '1']);
        $tailTolerance = '1' . \str_repeat('0', $precision + 2);
        for ($termIndex = 0; $termIndex < self::ERF_TAYLOR_TERMS; ++$termIndex) {
            $sum = $sum->add($term);
            $nextTerm = $term
                ->multiply($xSquared)
                ->multiply(new self(
                    [(string) (2 * $termIndex + 1), '1'],
                    [(string) (2 * $termIndex + 1), '1'],
                ))
                ->divide(new self(
                    [(string) (($termIndex + 1) * (2 * $termIndex + 3)), '1'],
                    [(string) (($termIndex + 1) * (2 * $termIndex + 3)), '1'],
                ));
            $ratioBound = $xSquared->divide(new self(
                [(string) ($termIndex + 2), '1'],
                [(string) ($termIndex + 2), '1'],
            ));
            if ($ratioBound->compareUpperToRational('1', '1') < 0) {
                $tail = $nextTerm->divide(
                    (new self(['1', '1'], ['1', '1']))->subtract($ratioBound),
                );
                if ($tail->compareUpperToRational('1', $tailTolerance) <= 0) {
                    $sum = $sum->add(new self(['0', '1'], $tail->upper));

                    return $sum->multiply($scale);
                }
            }
            $term = $nextTerm;
        }

        throw new \LengthException('The certified erfi series exceeded its term limit.');
    }

    /** @param array{string, string} $value */
    private function erfPoint(array $value, self $scale, int $precision): self
    {
        $sign = $this->arithmetic->sign($value[0]);
        if ($sign < 0) {
            return $this->erfPoint($this->negate($value), $scale, $precision)->negateInterval();
        }
        if ($sign === 0) {
            return new self(['0', '1'], ['0', '1']);
        }

        $x = new self($value, $value);
        if ($this->compare($value, ['4', '1']) >= 0) {
            $one = new self(['1', '1'], ['1', '1']);

            return $one->subtract($this->erfcPositiveAsymptotic($x, $scale, $precision));
        }
        $xSquared = $x->multiply($x);
        $magnitude = $x;
        $sum = new self(['0', '1'], ['0', '1']);
        $remainder = null;
        $toleranceDenominator = '1' . \str_repeat('0', $precision);
        $roundingScale = '1' . \str_repeat('0', $precision + 8);
        for ($termIndex = 0; $termIndex < self::ERF_TAYLOR_TERMS; ++$termIndex) {
            $sum = $sum->add(($termIndex % 2) === 0 ? $magnitude : $magnitude->negateInterval())
                ->roundOutwardToDecimalGrid($roundingScale);
            $nextMagnitude = $magnitude
                ->multiply($xSquared)
                ->multiply(new self([(string) (2 * $termIndex + 1), '1'], [(string) (2 * $termIndex + 1), '1']))
                ->divide(new self(
                    [(string) (($termIndex + 1) * (2 * $termIndex + 3)), '1'],
                    [(string) (($termIndex + 1) * (2 * $termIndex + 3)), '1'],
                ));
            $nextMagnitude = $nextMagnitude->roundOutwardToDecimalGrid($roundingScale);
            $isDecreasing = $xSquared->compareUpperToRational(
                (string) (($termIndex + 1) * (2 * $termIndex + 3)),
                (string) (2 * $termIndex + 1),
            ) < 0;
            if (
                $isDecreasing
                && $nextMagnitude->compareUpperToRational('1', $toleranceDenominator) <= 0
            ) {
                $remainder = ($termIndex % 2) === 0
                    ? new self(
                        $sum->subtract($nextMagnitude)->lower,
                        $sum->upper,
                    )
                    : new self(
                        $sum->lower,
                        $sum->add($nextMagnitude)->upper,
                    );
                break;
            }
            $magnitude = $nextMagnitude;
        }
        if ($remainder === null) {
            throw new \LengthException('The certified erf series exceeded its term limit.');
        }

        return $remainder->multiply($scale);
    }

    private function erfcPositiveAsymptotic(self $x, self $erfScale, int $precision): self
    {
        $xSquared = $x->multiply($x);
        $exponential = $xSquared->negateInterval()->exp();
        $prefactor = $exponential
            ->multiply($erfScale)
            ->divide($x->multiply(new self(['2', '1'], ['2', '1'])));
        $term = new self(['1', '1'], ['1', '1']);
        $sum = $term;
        $tail = null;
        for ($termIndex = 0; $termIndex < self::ERF_TAYLOR_TERMS; ++$termIndex) {
            $nextTerm = $term
                ->multiply(new self([(string) (2 * $termIndex + 1), '1'], [(string) (2 * $termIndex + 1), '1']))
                ->divide($xSquared->multiply(new self(['2', '1'], ['2', '1'])));
            $isDecreasing = $nextTerm->compareUpperToRational($term->lower[0], $term->lower[1]) <= 0;
            if (
                $isDecreasing
                && $prefactor->multiply($nextTerm)->compareUpperToRational(
                    '1',
                    '1' . \str_repeat('0', min($precision, 12)),
                ) <= 0
            ) {
                $tail = ($termIndex % 2) === 0
                    ? new self($sum->subtract($nextTerm)->lower, $sum->upper)
                    : new self($sum->lower, $sum->add($nextTerm)->upper);
                break;
            }
            $sum = $sum->add(($termIndex % 2) === 0 ? $nextTerm->negateInterval() : $nextTerm);
            $term = $nextTerm;
        }
        if ($tail === null) {
            throw new \LengthException('The certified erfc asymptotic series exceeded its term limit.');
        }

        return $prefactor->multiply($tail);
    }

    private function roundOutwardToDecimalGrid(string $scale): self
    {
        return new self(
            $this->roundFractionToDecimalGrid($this->lower, $scale, false),
            $this->roundFractionToDecimalGrid($this->upper, $scale, true),
        );
    }

    /**
     * @param array{string, string} $fraction
     * @return array{string, string}
     */
    private function roundFractionToDecimalGrid(array $fraction, string $scale, bool $roundUp): array
    {
        $negative = $this->arithmetic->sign($fraction[0]) < 0;
        $numerator = \ltrim($fraction[0], '-');
        $scaledNumerator = $this->arithmetic->multiplyIntegerValues($numerator, $scale);
        $quotient = $this->arithmetic->divideNonnegativeIntegerValuesFloor($scaledNumerator, $fraction[1]);
        $remainder = $this->arithmetic->subtract(
            $scaledNumerator,
            $this->arithmetic->multiplyIntegerValues($quotient, $fraction[1]),
        );
        $increment = $remainder !== '0' && ($roundUp !== $negative);
        if ($increment) {
            $quotient = $this->arithmetic->addIntegerValues($quotient, '1');
        }
        if ($negative && $quotient !== '0') {
            $quotient = $this->arithmetic->negateIntegerValue($quotient);
        }

        return [$quotient, $scale];
    }

    /** @param array{string, string} $value */
    private function lnPoint(array $value, int $precision): self
    {
        if ($this->compare($value, ['1', '1']) === 0) {
            return new self(['0', '1'], ['0', '1']);
        }
        if ($this->compare($value, ['1', '1']) < 0) {
            $reciprocal = (new self($value, $value))->reciprocal();

            return $this->lnPoint($reciprocal->lower, $precision)->negateInterval();
        }

        $one = new self(['1', '1'], ['1', '1']);
        $two = new self(['2', '1'], ['2', '1']);
        $reduced = new self($value, $value);
        $power = 0;
        while ($reduced->compareLowerToRational('1', '1') < 0) {
            $reduced = $reduced->multiply($two);
            --$power;
            if ($power < -self::MAX_LOG_RANGE_REDUCTIONS) {
                throw new \LengthException('The certified logarithm range reduction limit was exceeded.');
            }
        }
        while ($reduced->compareLowerToRational('2', '1') >= 0) {
            $reduced = $reduced->divide($two);
            ++$power;
            if ($power > self::MAX_LOG_RANGE_REDUCTIONS) {
                throw new \LengthException('The certified logarithm range reduction limit was exceeded.');
            }
        }
        $mantissaSign = 1;
        if ($reduced->compareLowerToRational('3', '2') >= 0) {
            $reduced = $reduced->divide($two);
            ++$power;
            if ($power > self::MAX_LOG_RANGE_REDUCTIONS) {
                throw new \LengthException('The certified logarithm range reduction limit was exceeded.');
            }
            $reduced = $reduced->reciprocal();
            $mantissaSign = -1;
        }
        $z = $reduced->subtract($one)->divide($reduced->add($one));
        if ($z->compareUpperToRational('0', '1') === 0) {
            $mantissaLog = new self(['0', '1'], ['0', '1']);
        } else {
            $zSquared = $z->multiply($z);
            $term = $z;
            $sum = new self(['0', '1'], ['0', '1']);
            $mantissaLog = null;
            $toleranceDenominator = '1' . \str_repeat('0', $precision);
            for ($index = 0; $index < self::LOG_TAYLOR_TERMS; ++$index) {
                $sum = $sum->add($term->divide(new self(
                    [(string) (2 * $index + 1), '1'],
                    [(string) (2 * $index + 1), '1'],
                )));
                $nextTerm = $term->multiply($zSquared);
                $tailBound = $nextTerm
                    ->divide(new self(
                        [(string) (2 * $index + 3), '1'],
                        [(string) (2 * $index + 3), '1'],
                    ))
                    ->divide(new self(['1', '1'], ['1', '1'])->subtract($zSquared));
                if ($tailBound->compareUpperToRational('1', $toleranceDenominator) <= 0) {
                    $lower = $sum->multiply(new self(['2', '1'], ['2', '1']));
                    $upper = $lower->add($tailBound->multiply(new self(['2', '1'], ['2', '1'])));
                    $mantissaLog = new self($lower->lower, $upper->upper);
                    break;
                }
                $term = $nextTerm;
            }
            if ($mantissaLog === null) {
                throw new \LengthException('The certified logarithm series exceeded its term limit.');
            }
        }
        if ($mantissaSign < 0) {
            $mantissaLog = $mantissaLog->negateInterval();
        }

        if ($power === 0) {
            return $mantissaLog;
        }
        $lnTwo = $this->lnHalfStep($precision);
        $powerInterval = new self([(string) \abs($power), '1'], [(string) \abs($power), '1']);
        $powerLog = $lnTwo->multiply($powerInterval);

        return $power > 0 ? $mantissaLog->add($powerLog) : $mantissaLog->subtract($powerLog);
    }

    private function lnHalfStep(int $precision): self
    {
        $one = new self(['1', '1'], ['1', '1']);
        $z = new self(['1', '3'], ['1', '3']);
        $zSquared = $z->multiply($z);
        $term = $z;
        $sum = new self(['0', '1'], ['0', '1']);
        $tail = null;
        for ($index = 0; $index < self::LOG_TAYLOR_TERMS; ++$index) {
            $sum = $sum->add($term->divide(new self(
                [(string) (2 * $index + 1), '1'],
                [(string) (2 * $index + 1), '1'],
            )));
            $nextTerm = $term->multiply($zSquared);
            $tailBound = $nextTerm->divide(new self(
                [(string) (2 * $index + 3), '1'],
                [(string) (2 * $index + 3), '1'],
            ))->divide($one->subtract($zSquared));
            if ($tailBound->compareUpperToRational('1', '1' . \str_repeat('0', $precision)) <= 0) {
                $lower = $sum->multiply(new self(['2', '1'], ['2', '1']));
                $upper = $lower->add($tailBound->multiply(new self(['2', '1'], ['2', '1'])));
                $tail = new self($lower->lower, $upper->upper);
                break;
            }
            $term = $nextTerm;
        }
        if ($tail === null) {
            throw new \LengthException('The certified logarithm series exceeded its term limit.');
        }

        return $tail;
    }

    /** Enclose sqrt(x) with rational endpoints on a fixed decimal grid. */
    public function sqrt(int $precision = 24): self
    {
        if ($precision < 1 || $precision > 4000) {
            throw new \InvalidArgumentException('Square-root interval precision must be between 1 and 4000.');
        }
        if ($this->arithmetic->sign($this->lower[0]) < 0) {
            throw new \DomainException('A real square root interval cannot include negative values.');
        }
        $lower = $this->sqrtPoint($this->lower, $precision);
        $upper = $this->sqrtPoint($this->upper, $precision);

        return new self($lower[0], $upper[1]);
    }

    /** Enclose atan(x) over any finite rational interval. */
    public function atan(int $precision = 40): self
    {
        self::validateAtanPrecision($precision);

        return new self(
            $this->atanPoint($this->lower, $precision)->lower,
            $this->atanPoint($this->upper, $precision)->upper,
        );
    }

    /** Enclose asin(x) for intervals contained in [-1, 1]. */
    public function asin(int $precision = 24): self
    {
        if ($precision < 1 || $precision > 4000) {
            throw new \InvalidArgumentException('Inverse-trigonometric precision must be between 1 and 4000.');
        }
        if (
            $this->compareLowerToRational('-1', '1') < 0
            || $this->compareUpperToRational('1', '1') > 0
        ) {
            throw new \DomainException('The certified arcsine interval requires inputs in [-1, 1].');
        }

        return new self(
            $this->asinPoint($this->lower, $precision)->lower,
            $this->asinPoint($this->upper, $precision)->upper,
        );
    }

    /** Enclose acos(x) for intervals contained in [-1, 1]. */
    public function acos(int $precision = 24): self
    {
        if ($precision < 1 || $precision > 4000) {
            throw new \InvalidArgumentException('Inverse-trigonometric precision must be between 1 and 4000.');
        }
        if (
            $this->compareLowerToRational('-1', '1') < 0
            || $this->compareUpperToRational('1', '1') > 0
        ) {
            throw new \DomainException('The certified arccosine interval requires inputs in [-1, 1].');
        }

        return new self(
            $this->acosPoint($this->upper, $precision)->lower,
            $this->acosPoint($this->lower, $precision)->upper,
        );
    }

    /** Enclose acot(x) = pi/2 - atan(x) over any finite rational interval. */
    public function acot(int $precision = 40): self
    {
        self::validateAtanPrecision($precision);

        return new self(
            $this->acotPoint($this->upper, $precision)->lower,
            $this->acotPoint($this->lower, $precision)->upper,
        );
    }

    /** Enclose asec(x) on intervals contained in (-inf, -1] or [1, inf). */
    public function asec(int $precision = 24): self
    {
        if (
            $this->compareLowerToRational('-1', '1') > 0
            && $this->compareUpperToRational('1', '1') < 0
        ) {
            throw new \DomainException('The certified arcsecant interval cannot cross its domain gap.');
        }

        return $this->reciprocal()->acos($precision);
    }

    /** Enclose acsc(x) on intervals contained in (-inf, -1] or [1, inf). */
    public function acsc(int $precision = 24): self
    {
        if (
            $this->compareLowerToRational('-1', '1') > 0
            && $this->compareUpperToRational('1', '1') < 0
        ) {
            throw new \DomainException('The certified arccosecant interval cannot cross its domain gap.');
        }

        return $this->reciprocal()->asin($precision);
    }

    /** @param array{string, string} $value */
    private function atanPoint(array $value, int $precision): self
    {
        if ($this->arithmetic->sign($value[0]) < 0) {
            return $this->atanPoint($this->negate($value), $precision)->negateInterval();
        }
        if ($this->compare($value, ['1', '1']) > 0) {
            return $this->piHalfInterval($precision)->subtract(
                $this->atanPoint([$value[1], $value[0]], $precision),
            );
        }
        if ($this->compare($value, ['1', '2']) <= 0) {
            $point = new self($value, $value);

            return $this->atanSeriesInterval($point, $precision);
        }

        $point = new self($value, $value);
        $one = new self(['1', '1'], ['1', '1']);
        $root = $one->add($point->power(2))->sqrt();
        $reduced = $point->divide($one->add($root));
        $angle = $this->atanSeriesInterval($reduced, $precision);
        $twice = new self(
            $this->multiplyFractions($angle->lower, ['2', '1']),
            $this->multiplyFractions($angle->upper, ['2', '1']),
        );

        return $twice;
    }

    /** @param array{string, string} $value */
    private function asinPoint(array $value, int $precision): self
    {
        $sign = $this->arithmetic->sign($value[0]);
        if ($sign < 0) {
            return $this->asinPoint($this->negate($value), $precision)->negateInterval();
        }
        if ($this->compare($value, ['1', '1']) === 0) {
            return $this->piHalfInterval(max(40, min(self::MAX_ATAN_PRECISION, $precision)));
        }
        if ($sign === 0) {
            return new self(['0', '1'], ['0', '1']);
        }

        $point = new self($value, $value);
        $one = new self(['1', '1'], ['1', '1']);
        $radicand = $one->subtract($point->power(2));

        if ($this->compare($value, ['1', '2']) > 0) {
            $atanPrecision = max(40, min(self::MAX_ATAN_PRECISION, $precision));
            $complementaryAngle = $radicand->sqrt($precision)
                ->divide($point)
                ->atan($atanPrecision);

            return $this->piHalfInterval($atanPrecision)->subtract($complementaryAngle);
        }

        $atanPrecision = max(40, min(self::MAX_ATAN_PRECISION, $precision));

        return $point->divide($radicand->sqrt($precision))->atan($atanPrecision);
    }

    /** @param array{string, string} $value */
    private function acosPoint(array $value, int $precision): self
    {
        $atanPrecision = max(40, min(self::MAX_ATAN_PRECISION, $precision));
        $sign = $this->arithmetic->sign($value[0]);
        if ($sign < 0) {
            $halfPi = $this->piHalfInterval($atanPrecision);
            $pi = $halfPi->multiply(new self(['2', '1'], ['2', '1']));

            return $pi->subtract($this->acosPoint($this->negate($value), $precision));
        }
        if ($this->compare($value, ['1', '1']) === 0) {
            return new self(['0', '1'], ['0', '1']);
        }
        if ($sign === 0) {
            return $this->piHalfInterval($atanPrecision);
        }
        if ($this->compare($value, ['1', '2']) <= 0) {
            return $this->piHalfInterval($atanPrecision)->subtract($this->asinPoint($value, $precision));
        }

        $point = new self($value, $value);
        $one = new self(['1', '1'], ['1', '1']);
        $radicand = $one->subtract($point->power(2));

        return $radicand->sqrt($precision)->divide($point)->atan($atanPrecision);
    }

    /** @param array{string, string} $value */
    private function acotPoint(array $value, int $precision): self
    {
        $sign = $this->arithmetic->sign($value[0]);
        if ($sign === 0) {
            return $this->piHalfInterval($precision);
        }

        $absoluteValue = $sign < 0 ? $this->negate($value) : $value;
        if ($this->compare($absoluteValue, ['1', '1']) <= 0) {
            return $this->piHalfInterval($precision)->subtract(
                (new self($value, $value))->atan($precision),
            );
        }

        $reciprocalAngle = (new self($value, $value))->reciprocal()->atan($precision);
        if ($sign > 0) {
            return $reciprocalAngle;
        }

        $halfPi = $this->piHalfInterval($precision);
        $pi = $halfPi->multiply(new self(['2', '1'], ['2', '1']));

        return $pi->add($reciprocalAngle);
    }

    private function piHalfInterval(int $precision = 40): self
    {
        /** @var array<int, self> $halfPiByPrecision */
        static $halfPiByPrecision = [];
        if (!isset($halfPiByPrecision[$precision])) {
            $five = new self(['1', '5'], ['1', '5']);
            $twoHundredThirtyNine = new self(['1', '239'], ['1', '239']);
            $pi = $five->atanSeriesInterval($five, $precision)->multiply(new self(['16', '1'], ['16', '1']))
                ->subtract($twoHundredThirtyNine->atanSeriesInterval($twoHundredThirtyNine, $precision)
                    ->multiply(new self(['4', '1'], ['4', '1'])));
            $halfPiByPrecision[$precision] = $pi->divide(new self(['2', '1'], ['2', '1']));
        }

        return $halfPiByPrecision[$precision];
    }

    private function atanSeriesInterval(self $value, int $precision = 40): self
    {
        $scale = '1' . str_repeat('0', $precision);
        $lower = $this->scaleRationalFloor($value->lower, $scale);
        $upper = $this->scaleRationalCeiling($value->upper, $scale);
        $squareLower = $this->atanProductFloor($lower, $lower, $scale);
        $squareUpper = $this->atanProductCeiling($upper, $upper, $scale);
        $powerLower = $lower;
        $powerUpper = $upper;
        $sumLower = '0';
        $sumUpper = '0';
        $degree = intdiv((5 * $precision) + 2, 3);
        for ($index = 0; $index <= $degree; ++$index) {
            $odd = (string) ((2 * $index) + 1);
            $termLower = $this->arithmetic->divideNonnegativeIntegerValuesFloor($powerLower, $odd);
            $termUpper = $this->ceilNonnegativeIntegerDivision($powerUpper, $odd);
            if ($index % 2 === 0) {
                $sumLower = $this->arithmetic->addIntegerValues($sumLower, $termLower);
                $sumUpper = $this->arithmetic->addIntegerValues($sumUpper, $termUpper);
            } else {
                $sumLower = $this->arithmetic->addIntegerValues(
                    $sumLower,
                    $this->arithmetic->negateIntegerValue($termUpper),
                );
                $sumUpper = $this->arithmetic->addIntegerValues(
                    $sumUpper,
                    $this->arithmetic->negateIntegerValue($termLower),
                );
            }
            $powerLower = $this->atanProductFloor($powerLower, $squareLower, $scale);
            $powerUpper = $this->atanProductCeiling($powerUpper, $squareUpper, $scale);
        }

        $nextUpper = $this->ceilNonnegativeIntegerDivision(
            $powerUpper,
            (string) ((2 * ($degree + 1)) + 1),
        );
        $sumLower = $this->arithmetic->addIntegerValues(
            $sumLower,
            $this->arithmetic->negateIntegerValue($nextUpper),
        );

        return new self(
            $this->normalize([$sumLower, $scale]),
            $this->normalize([$sumUpper, $scale]),
        );
    }

    private function atanProductFloor(string $left, string $right, string $scale): string
    {
        return $this->arithmetic->divideNonnegativeIntegerValuesFloor(
            $this->arithmetic->multiplyIntegerValues($left, $right),
            $scale,
        );
    }

    private function atanProductCeiling(string $left, string $right, string $scale): string
    {
        return $this->ceilNonnegativeIntegerDivision(
            $this->arithmetic->multiplyIntegerValues($left, $right),
            $scale,
        );
    }

    /**
     * @param array{string, string} $value
     * @return array{array{string, string}, array{string, string}}
     */
    private function sqrtPoint(array $value, int $precision): array
    {
        $scale = '1' . str_repeat('0', $precision);
        $scaleSquared = $this->arithmetic->multiplyIntegerValues($scale, $scale);
        $scaledNumerator = $this->arithmetic->multiplyIntegerValues($value[0], $scaleSquared);
        if (strlen($scaledNumerator) > self::MAX_INTEGER_DIGITS) {
            throw new \LengthException('The exact square-root interval exceeded its integer digit limit.');
        }
        $scaledQuotient = $this->arithmetic->divideNonnegativeIntegerValuesFloor(
            $scaledNumerator,
            $value[1],
        );
        $rootFloor = $this->arithmetic->integerSquareRoot($scaledQuotient);
        if ($rootFloor === null) {
            throw new \LogicException('A nonnegative rational square root could not be computed.');
        }
        $scaledSquare = $this->arithmetic->multiplyIntegerValues(
            $this->arithmetic->multiplyIntegerValues($rootFloor, $rootFloor),
            $value[1],
        );
        $isExact = $this->arithmetic->compareIntegerValues($scaledSquare, $scaledNumerator) === 0;
        $lower = $this->normalize([$rootFloor, $scale]);
        $upper = $isExact
            ? $lower
            : $this->normalize([
                $this->arithmetic->addIntegerValues($rootFloor, '1'),
                $scale,
            ]);

        return [$lower, $upper];
    }

    /**
     * @param array{string, string} $value
     * @return array{array{string, string}, array{string, string}}
     */
    private function expPoint(array $value): array
    {
        if ($this->arithmetic->sign($value[0]) < 0) {
            $positive = $this->expPoint($this->negate($value));
            $positiveInterval = new self($positive[0], $positive[1]);
            $reciprocal = $positiveInterval->reciprocal();

            return [$reciprocal->lower, $reciprocal->upper];
        }

        $scale = '1';
        $reductions = 0;
        while (
            $this->arithmetic->compareIntegerValues(
                $this->arithmetic->multiplyIntegerValues($value[0], '2'),
                $this->arithmetic->multiplyIntegerValues($value[1], $scale),
            ) > 0
        ) {
            if (++$reductions > self::MAX_EXP_RANGE_REDUCTIONS) {
                throw new \LengthException('The exact exponential interval exceeded its range-reduction limit.');
            }
            $scale = $this->arithmetic->multiplyIntegerValues($scale, '2');
        }

        $reducedInput = [
            $value[0],
            $this->arithmetic->multiplyIntegerValues($value[1], $scale),
        ];
        $inputLower = $this->scaleRationalFloor($reducedInput, self::EXP_SCALE);
        $inputUpper = $this->scaleRationalCeiling($reducedInput, self::EXP_SCALE);
        $sumLower = self::EXP_SCALE;
        $sumUpper = self::EXP_SCALE;
        $termLower = self::EXP_SCALE;
        $termUpper = self::EXP_SCALE;
        // Directed rounding at each recurrence step keeps both Taylor sums enclosing.
        for ($index = 1; $index <= self::EXP_TAYLOR_DEGREE; ++$index) {
            $termLower = $this->divideFixedPointProductFloor(
                $termLower,
                $inputLower,
                (string) $index,
            );
            $termUpper = $this->divideFixedPointProductCeiling(
                $termUpper,
                $inputUpper,
                (string) $index,
            );
            $sumLower = $this->arithmetic->addIntegerValues($sumLower, $termLower);
            $sumUpper = $this->arithmetic->addIntegerValues($sumUpper, $termUpper);
        }
        $nextTermUpper = $this->divideFixedPointProductCeiling(
            $termUpper,
            $inputUpper,
            (string) (self::EXP_TAYLOR_DEGREE + 1),
        );
        $lowerBound = [$sumLower, self::EXP_SCALE];
        // With the reduced input at most 1/2, the remaining series is less than twice its next term.
        $upperBound = [
            $this->arithmetic->addIntegerValues(
                $sumUpper,
                $this->arithmetic->multiplyIntegerValues($nextTermUpper, '2'),
            ),
            self::EXP_SCALE,
        ];
        for ($index = 0; $index < $reductions; ++$index) {
            $lowerProduct = $this->arithmetic->multiplyIntegerValues($lowerBound[0], $lowerBound[0]);
            $upperProduct = $this->arithmetic->multiplyIntegerValues($upperBound[0], $upperBound[0]);
            $lowerBound = [
                $this->arithmetic->divideNonnegativeIntegerValuesFloor($lowerProduct, self::EXP_SCALE),
                self::EXP_SCALE,
            ];
            $upperBound = [
                $this->ceilNonnegativeIntegerDivision($upperProduct, self::EXP_SCALE),
                self::EXP_SCALE,
            ];
        }

        return [
            $this->normalize($lowerBound),
            $this->normalize($upperBound),
        ];
    }

    /** @param array{string, string} $value */
    private function sinhPoint(array $value): self
    {
        $point = new self($value, $value);
        $positiveExponential = $point->exp();
        $negativeExponential = $point->negateInterval()->exp();

        return $positiveExponential
            ->subtract($negativeExponential)
            ->divide(new self(['2', '1'], ['2', '1']));
    }

    /** @param array{string, string} $value */
    private function coshPoint(array $value): self
    {
        $point = new self($value, $value);
        $positiveExponential = $point->exp();
        $negativeExponential = $point->negateInterval()->exp();

        return $positiveExponential
            ->add($negativeExponential)
            ->divide(new self(['2', '1'], ['2', '1']));
    }

    /** @param array{string, string} $value */
    private function tanhPoint(array $value): self
    {
        return $this->sinhPoint($value)->divide($this->coshPoint($value));
    }

    /** @param array{string, string} $fraction */
    private function scaleRationalFloor(array $fraction, string $scale): string
    {
        return $this->arithmetic->divideNonnegativeIntegerValuesFloor(
            $this->arithmetic->multiplyIntegerValues($fraction[0], $scale),
            $fraction[1],
        );
    }

    /** @param array{string, string} $fraction */
    private function scaleRationalCeiling(array $fraction, string $scale): string
    {
        return $this->ceilNonnegativeIntegerDivision(
            $this->arithmetic->multiplyIntegerValues($fraction[0], $scale),
            $fraction[1],
        );
    }

    private function divideFixedPointProductFloor(string $left, string $right, string $divisor): string
    {
        $denominator = $this->arithmetic->multiplyIntegerValues(self::EXP_SCALE, $divisor);

        return $this->arithmetic->divideNonnegativeIntegerValuesFloor(
            $this->arithmetic->multiplyIntegerValues($left, $right),
            $denominator,
        );
    }

    private function divideFixedPointProductCeiling(string $left, string $right, string $divisor): string
    {
        $denominator = $this->arithmetic->multiplyIntegerValues(self::EXP_SCALE, $divisor);

        return $this->ceilNonnegativeIntegerDivision(
            $this->arithmetic->multiplyIntegerValues($left, $right),
            $denominator,
        );
    }

    private function ceilNonnegativeIntegerDivision(string $numerator, string $denominator): string
    {
        $quotient = $this->arithmetic->divideNonnegativeIntegerValuesFloor($numerator, $denominator);
        $product = $this->arithmetic->multiplyIntegerValues($quotient, $denominator);

        return $this->arithmetic->compareIntegerValues($product, $numerator) === 0
            ? $quotient
            : $this->arithmetic->addIntegerValues($quotient, '1');
    }

    /**
     * @param array{string, string} $left
     * @param array{string, string} $right
     */
    private function compare(array $left, array $right): int
    {
        return $this->arithmetic->compareIntegerValues(
            $this->arithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->arithmetic->multiplyIntegerValues($right[0], $left[1]),
        );
    }

    /**
     * @param array{string, string} $left
     * @param array{string, string} $right
     * @return array{string, string}
     */
    private function addFractions(array $left, array $right): array
    {
        return $this->normalize([
            $this->arithmetic->addIntegerValues(
                $this->arithmetic->multiplyIntegerValues($left[0], $right[1]),
                $this->arithmetic->multiplyIntegerValues($right[0], $left[1]),
            ),
            $this->arithmetic->multiplyIntegerValues($left[1], $right[1]),
        ]);
    }

    /**
     * @param array{string, string} $left
     * @param array{string, string} $right
     * @return array{string, string}
     */
    private function multiplyFractions(array $left, array $right): array
    {
        return $this->normalize([
            $this->arithmetic->multiplyIntegerValues($left[0], $right[0]),
            $this->arithmetic->multiplyIntegerValues($left[1], $right[1]),
        ]);
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}
     */
    private function powerFraction(array $fraction, int $exponent): array
    {
        $result = ['1', '1'];
        while ($exponent > 0) {
            if (($exponent % 2) === 1) {
                $result = $this->multiplyFractions($result, $fraction);
            }
            $exponent = \intdiv($exponent, 2);
            if ($exponent > 0) {
                $fraction = $this->multiplyFractions($fraction, $fraction);
            }
        }

        return $result;
    }

    /**
     * @param array{string, string} $fraction
     * @return array{string, string}
     */
    private function negate(array $fraction): array
    {
        return [$this->arithmetic->negateIntegerValue($fraction[0]), $fraction[1]];
    }

    /**
     * @param array{string, string} $fraction
     * @return array{string, string}
     */
    private function normalize(array $fraction): array
    {
        [$numerator, $denominator] = $fraction;
        foreach ($fraction as $part) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $part) !== 1
                || strlen(ltrim($part, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                throw new \InvalidArgumentException('Rational interval endpoints require bounded canonical integers.');
            }
        }
        $numerator = $numerator === '-0' ? '0' : $numerator;
        $denominator = $denominator === '-0' ? '0' : $denominator;
        if ($denominator === '0') {
            throw new \InvalidArgumentException('A rational interval endpoint denominator cannot be zero.');
        }
        if ($denominator[0] === '-') {
            $numerator = $this->arithmetic->negateIntegerValue($numerator);
            $denominator = substr($denominator, 1);
        }
        if ($numerator === '0') {
            return ['0', '1'];
        }
        $gcd = $this->arithmetic->greatestCommonDivisorValues($numerator, $denominator);
        $reducedNumerator = $this->arithmetic->divideIntegerValueExactly($numerator, $gcd);
        $reducedDenominator = $this->arithmetic->divideIntegerValueExactly($denominator, $gcd);
        if ($reducedNumerator === null || $reducedDenominator === null) {
            throw new \LogicException('Rational endpoint reduction failed.');
        }

        return [$reducedNumerator, $reducedDenominator];
    }

    private static function validateAtanPrecision(int $precision): void
    {
        if ($precision < 1 || $precision > self::MAX_ATAN_PRECISION) {
            throw new \InvalidArgumentException(
                'Inverse-tangent precision must be between 1 and ' . self::MAX_ATAN_PRECISION . ' digits.',
            );
        }
    }
}
