<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\RationalLiteral;

/** Exact Sturm root counts for bounded integer-coefficient polynomials. */
final class ExactPolynomialRootCounter
{
    private const MAX_DEGREE = 256;

    private const MAX_INTEGER_DIGITS = 4096;

    private const MAX_RATIONAL_ROOT_TRIAL_DIVISOR = 10000;

    private const MAX_RATIONAL_ROOT_CANDIDATES = 10000;

    private readonly IntegerPolynomialExtractor $integerArithmetic;

    public function __construct()
    {
        $this->integerArithmetic = new IntegerPolynomialExtractor('', [], self::MAX_DEGREE);
    }

    /**
     * Count distinct real roots in the open rational interval.
     * Equal bounds form an empty interval and return zero. Reversed bounds,
     * the zero polynomial, unsupported degrees, or bounds and intermediate
     * arithmetic beyond limits return null.
     *
     * @param list<string> $coefficients Ascending integer coefficients.
     */
    public function countOpenInterval(
        array $coefficients,
        string $lowerNumerator,
        string $lowerDenominator,
        string $upperNumerator,
        string $upperDenominator,
    ): ?int {
        if ($coefficients === []) {
            return null;
        }
        foreach ([$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator] as $boundPart) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $boundPart) !== 1
                || \strlen(\ltrim($boundPart, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
        }
        $lowerNumerator = $this->canonicalZero($lowerNumerator);
        $lowerDenominator = $this->canonicalZero($lowerDenominator);
        $upperNumerator = $this->canonicalZero($upperNumerator);
        $upperDenominator = $this->canonicalZero($upperDenominator);
        $lowerFraction = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
        $upperFraction = $this->reduceFraction([$upperNumerator, $upperDenominator]);
        if ($lowerFraction === null || $upperFraction === null) {
            return null;
        }
        $intervalOrder = $this->integerArithmetic->compareIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues($lowerFraction[0], $upperFraction[1]),
            $this->integerArithmetic->multiplyIntegerValues($upperFraction[0], $lowerFraction[1]),
        );
        if ($intervalOrder > 0) {
            return null;
        }
        $emptyInterval = $intervalOrder === 0;
        foreach ($coefficients as &$coefficient) {
            if (\preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1) {
                return null;
            }
            if (\strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        $coefficients = $this->trim($coefficients);
        if (\count($coefficients) < 2 || \count($coefficients) - 1 > self::MAX_DEGREE) {
            return \count($coefficients) === 1 && $coefficients[0] !== '0' ? 0 : null;
        }
        if ($emptyInterval) {
            return 0;
        }

        $sequence = $this->sturmSequence($coefficients);

        return $sequence === null
            ? null
            : $this->countOpenIntervalWithSequence($sequence, $lowerFraction, $upperFraction);
    }

    /**
     * Count distinct real roots on the entire real line using Sturm signs at
     * negative and positive infinity.
     *
     * @param list<string> $coefficients Ascending integer coefficients.
     */
    public function countAllRealRoots(array $coefficients): ?int
    {
        if ($coefficients === []) {
            return null;
        }
        foreach ($coefficients as &$coefficient) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        $coefficients = $this->trim($coefficients);
        if (\count($coefficients) < 2 || \count($coefficients) - 1 > self::MAX_DEGREE) {
            return \count($coefficients) === 1 && $coefficients[0] !== '0' ? 0 : null;
        }

        $sequence = $this->sturmSequence($coefficients);
        if ($sequence === null) {
            return null;
        }

        $negativeInfinityVariations = $this->variationsAtInfinity($sequence, -1);
        $positiveInfinityVariations = $this->variationsAtInfinity($sequence, 1);

        return $negativeInfinityVariations === null || $positiveInfinityVariations === null
            ? null
            : $negativeInfinityVariations - $positiveInfinityVariations;
    }

    /**
     * Count distinct common real roots of two integer-coefficient polynomials.
     *
     * @param list<string> $left
     * @param list<string> $right
     */
    public function countCommonRealRoots(array $left, array $right): ?int
    {
        $gcd = $this->greatestCommonDivisor($left, $right);
        if ($gcd === null) {
            return null;
        }
        if (\count($gcd) < 2) {
            return 0;
        }

        return $this->countAllRealRoots($gcd);
    }

    /**
     * Return the primitive integer polynomial greatest common divisor.
     *
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>|null
     */
    public function greatestCommonDivisor(array $left, array $right): ?array
    {
        foreach ($left as &$coefficient) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        foreach ($right as &$coefficient) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        $left = $this->trim($left);
        $right = $this->trim($right);
        if ($left === [] || $right === []) {
            return null;
        }
        $leftIsZero = $this->isZeroCoefficientList($left);
        $rightIsZero = $this->isZeroCoefficientList($right);
        if ($leftIsZero && $rightIsZero) {
            return null;
        }
        if ($leftIsZero) {
            $primitive = $this->primitiveIntegerCoefficients($right);

            return $primitive === null ? null : $this->positiveLeadingCoefficient($primitive);
        }
        if ($rightIsZero) {
            $primitive = $this->primitiveIntegerCoefficients($left);

            return $primitive === null ? null : $this->positiveLeadingCoefficient($primitive);
        }

        $dividend = $this->integerPolynomial($this->primitivePart($left));
        $divisor = $this->integerPolynomial($this->primitivePart($right));
        while (!$this->isZeroPolynomial($divisor)) {
            $remainder = $this->remainder($dividend, $divisor);
            if ($remainder === null) {
                return null;
            }
            $dividend = $divisor;
            $divisor = $remainder;
        }
        $gcd = $this->primitivePositiveScale($dividend);
        $integerGcd = $this->integerCoefficients($gcd);

        return $integerGcd === null ? null : $this->positiveLeadingCoefficient($integerGcd);
    }

    /**
     * @param list<string> $coefficients
     * @return list<string>|null
     */
    private function primitiveIntegerCoefficients(array $coefficients): ?array
    {
        return $this->integerCoefficients($this->primitivePositiveScale(
            $this->integerPolynomial($coefficients),
        ));
    }

    /** @param list<string> $coefficients
     *  @return list<string>
     */
    private function positiveLeadingCoefficient(array $coefficients): array
    {
        $coefficients = $this->trim($coefficients);
        if ($coefficients === []) {
            return [];
        }
        $leading = $coefficients[\array_key_last($coefficients)];
        if ($this->integerArithmetic->sign($leading) < 0) {
            foreach ($coefficients as $index => $coefficient) {
                $coefficients[$index] = $this->integerArithmetic->negateIntegerValue($coefficient);
            }
        }

        return $coefficients;
    }

    /**
     * @param list<array{string, string}> $polynomial
     * @return list<string>|null
     */
    private function integerCoefficients(array $polynomial): ?array
    {
        $coefficients = [];
        foreach ($polynomial as [$numerator, $denominator]) {
            if ($denominator !== '1') {
                return null;
            }
            $coefficients[] = $numerator;
        }

        return $coefficients;
    }

    /**
     * Divide an integer polynomial by an exact integer factor.
     *
     * @param list<string> $dividend
     * @param list<string> $divisor
     * @return list<string>|null
     */
    public function dividePolynomialExactly(array $dividend, array $divisor): ?array
    {
        foreach ([&$dividend, &$divisor] as &$polynomial) {
            foreach ($polynomial as &$coefficient) {
                if (
                    \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                    || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
                ) {
                    return null;
                }
                $coefficient = $this->canonicalZero($coefficient);
            }
            unset($coefficient);
            $polynomial = $this->trim($polynomial);
        }
        unset($polynomial);
        if ($divisor === [] || $this->isZeroCoefficientList($divisor)) {
            return null;
        }
        if ($dividend === [] || $this->isZeroCoefficientList($dividend)) {
            return ['0'];
        }
        if (\count($dividend) < \count($divisor)) {
            return null;
        }

        $remainder = $this->integerPolynomial($dividend);
        $divisorPolynomial = $this->integerPolynomial($divisor);
        $divisorDegree = \count($divisorPolynomial) - 1;
        $quotient = \array_fill(
            0,
            \count($dividend) - \count($divisor) + 1,
            ['0', '1'],
        );
        while (\count($remainder) >= \count($divisorPolynomial) && !$this->isZeroPolynomial($remainder)) {
            $degree = \count($remainder) - 1;
            $shift = $degree - $divisorDegree;
            $factor = $this->divideFractions(
                $remainder[$degree],
                $divisorPolynomial[$divisorDegree],
            );
            if ($factor === null) {
                return null;
            }
            $quotient[$shift] = $factor;
            for ($index = 0; $index <= $divisorDegree; ++$index) {
                $product = $this->multiplyFractions($factor, $divisorPolynomial[$index]);
                $difference = $product === null
                    ? null
                    : $this->subtractFractions($remainder[$index + $shift], $product);
                if ($difference === null) {
                    return null;
                }
                $remainder[$index + $shift] = $difference;
            }
            $remainder = $this->trimFractions($remainder);
            if (!$this->withinLimits($remainder)) {
                return null;
            }
        }
        if (!$this->isZeroPolynomial($remainder)) {
            return null;
        }

        $integerQuotient = [];
        foreach ($quotient as [$numerator, $denominator]) {
            if ($denominator !== '1') {
                return null;
            }
            $integerQuotient[] = $numerator;
        }

        return $this->trim($integerQuotient);
    }

    /**
     * Isolate all distinct real roots in rational intervals without user bounds.
     *
     * @param list<string> $coefficients Ascending integer coefficients.
     * @param array<array-key, mixed>|null $maximumWidth Positive exact rational width.
     * @return list<array{string, string, string, string}>|null
     */
    public function isolateAllRealRoots(
        array $coefficients,
        int $maxDepth = 256,
        ?array $maximumWidth = null,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            return null;
        }
        if ($maximumWidth !== null) {
            if (
                !\array_is_list($maximumWidth)
                || \count($maximumWidth) !== 2
                || !\is_string($maximumWidth[0] ?? null)
                || !\is_string($maximumWidth[1] ?? null)
                || \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $maximumWidth[0]) !== 1
                || \preg_match('/\A[1-9][0-9]*\z/D', $maximumWidth[1]) !== 1
                || \strlen(\ltrim($maximumWidth[0], '-')) > self::MAX_INTEGER_DIGITS
                || \strlen($maximumWidth[1]) > self::MAX_INTEGER_DIGITS
                || $this->integerArithmetic->compareIntegerValues($maximumWidth[0], '0') <= 0
            ) {
                return null;
            }
            $maximumWidth = $this->reduceFraction($maximumWidth);
            if ($maximumWidth === null) {
                return null;
            }
        }
        if ($coefficients === []) {
            return null;
        }
        foreach ($coefficients as &$coefficient) {
            if (
                preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || strlen(ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        $coefficients = $this->trim($coefficients);
        if (count($coefficients) < 2 || count($coefficients) - 1 > self::MAX_DEGREE) {
            return count($coefficients) === 1 && $coefficients[0] !== '0' ? [] : null;
        }
        $parityPolynomialRoots = $this->isolateParityPolynomialRoots($coefficients, $maxDepth, $maximumWidth);
        if ($parityPolynomialRoots !== null) {
            return $parityPolynomialRoots;
        }
        $centeredParityRoots = $this->isolateCenteredParityPolynomialRoots($coefficients, $maxDepth, $maximumWidth);
        if ($centeredParityRoots !== null) {
            return $centeredParityRoots;
        }
        $binomialRoots = $this->exactBinomialRoots($coefficients);
        if ($binomialRoots !== null) {
            return $binomialRoots;
        }
        if (count($coefficients) > 3) {
            [$rationalRoots, $remainingPolynomial] = $this->extractRationalRoots($coefficients);
            if ($rationalRoots !== []) {
                $remainingIntervals = count($remainingPolynomial) > 1
                    ? $this->isolateAllRealRoots($remainingPolynomial, $maxDepth, $maximumWidth)
                    : [];
                if ($remainingIntervals === null) {
                    return null;
                }
                $intervals = [
                    ...\array_map(
                        static fn (array $root): array => [$root[0], $root[1], $root[0], $root[1]],
                        $this->uniqueRationalRoots($rationalRoots),
                    ),
                    ...$remainingIntervals,
                ];
                return $this->sortAndDeduplicateIntervals($intervals);
            }
        }
        if (count($coefficients) === 2) {
            $root = $this->reduceFraction([
                $this->integerArithmetic->negateIntegerValue($coefficients[0]),
                $coefficients[1],
            ]);
            if ($root === null) {
                return null;
            }

            return [[$root[0], $root[1], $root[0], $root[1]]];
        }
        if (count($coefficients) === 3) {
            $quadraticRoots = $this->exactQuadraticRoots($coefficients);
            if ($quadraticRoots !== null) {
                return $quadraticRoots;
            }
            if ($maxDepth <= 16) {
                $quadraticIntervals = $this->irrationalQuadraticRootIntervals($coefficients, $maximumWidth);
                if ($quadraticIntervals !== null) {
                    return $quadraticIntervals;
                }
            }
        }
        $seededIntervals = $this->isolateWithNumericRootSeeds($coefficients, $maxDepth, $maximumWidth);
        if ($seededIntervals !== null) {
            return $seededIntervals;
        }
        $leading = $coefficients[array_key_last($coefficients)];
        if ($leading === '0') {
            return null;
        }

        $bound = $this->fujiwaraRootBound($coefficients);
        if ($bound === null) {
            return null;
        }

        return $this->isolateOpenInterval(
            $coefficients,
            '-' . $bound,
            '1',
            $bound,
            '1',
            $maxDepth,
            $maximumWidth,
        );
    }

    /**
     * Use floating-point roots only to propose narrow brackets; Sturm counts
     * certify every bracket and prove that the complete real-root set was found.
     *
     * @param list<string> $coefficients
     * @param array{string, string}|null $maximumWidth
     * @return list<array{string, string, string, string}>|null
     */
    private function isolateWithNumericRootSeeds(
        array $coefficients,
        int $maxDepth,
        ?array $maximumWidth,
    ): ?array {
        $degree = count($coefficients) - 1;
        if ($degree < 4 || $degree > 32 || $maxDepth < 8 || $maximumWidth === null) {
            return null;
        }
        $rootCount = $this->countAllRealRoots($coefficients);
        if ($rootCount === null) {
            return null;
        }
        if ($rootCount === 0) {
            return [];
        }
        $floatCoefficients = $this->integerArithmetic->normalizedCoefficients($coefficients);
        if ($floatCoefficients === null) {
            return null;
        }
        $bound = $this->fujiwaraRootBound($coefficients);
        if ($bound === null) {
            return null;
        }
        $floatBound = (float) $bound;
        if (!is_finite($floatBound) || $floatBound <= 0.0 || $floatBound > 1.0E12) {
            return null;
        }

        $polynomialSolver = new PolynomialSolver();
        $residualAt = static fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
            $floatCoefficients,
            $value,
        );
        $residualScaleAt = static fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
            $floatCoefficients,
            $value,
        );
        $candidates = $polynomialSolver->solve(
            $floatCoefficients,
            -$floatBound,
            $floatBound,
            $residualAt,
            new SolverOptions(residualTolerance: 1.0E-8),
            $residualScaleAt,
        );
        if ($candidates === null || count($candidates) !== $rootCount) {
            return null;
        }
        usort($candidates, static fn (EquationSolution $left, EquationSolution $right): int =>
            $left->value <=> $right->value);

        $sturmSequence = $this->sturmSequence($coefficients);
        if ($sturmSequence === null) {
            return null;
        }
        $intervals = [];
        $previousUpper = null;
        foreach ($candidates as $candidate) {
            $center = RationalLiteral::fromFloatAsStrings($candidate->value);
            if ($center === null) {
                return null;
            }
            $centerIsRoot = $this->isRootAt($coefficients, $center);
            if ($centerIsRoot === null) {
                return null;
            }
            if ($centerIsRoot) {
                if ($previousUpper !== null && $this->compareFractions($center, $previousUpper) <= 0) {
                    return null;
                }
                $intervals[] = [$center[0], $center[1], $center[0], $center[1]];
                $previousUpper = $center;
                continue;
            }

            $certifiedInterval = null;
            foreach ([8, 4, 2] as $radiusDivisor) {
                $radius = $this->divideFractions($maximumWidth, [(string) $radiusDivisor, '1']);
                $lower = $radius === null ? null : $this->subtractFractions($center, $radius);
                $upper = $radius === null ? null : $this->addFractions($center, $radius);
                if ($lower === null || $upper === null) {
                    return null;
                }
                if (
                    $previousUpper !== null
                    && $this->compareFractions($lower, $previousUpper) <= 0
                ) {
                    return null;
                }
                $count = $this->countOpenIntervalWithSequence($sturmSequence, $lower, $upper);
                if ($count === 1) {
                    $certifiedInterval = [$lower[0], $lower[1], $upper[0], $upper[1]];
                    $previousUpper = $upper;
                    break;
                }
                if ($count !== 0) {
                    return null;
                }
            }
            if ($certifiedInterval === null) {
                return null;
            }
            $intervals[] = $certifiedInterval;
        }

        return count($intervals) === $rootCount ? $intervals : null;
    }

    /**
     * Isolate roots of p(x) = q(x^2) or p(x) = x*q(x^2) by isolating q and
     * mapping nonnegative roots through certified rational square-root bounds.
     *
     * @param list<string> $coefficients
     * @param array{string, string}|null $maximumWidth
     * @return list<array{string, string, string, string}>|null
     */
    private function isolateParityPolynomialRoots(array $coefficients, int $maxDepth, ?array $maximumWidth): ?array
    {
        if (\count($coefficients) < 5) {
            return null;
        }
        $onlyEvenPowers = true;
        $onlyOddPowers = true;
        $reducedCoefficients = [];
        foreach ($coefficients as $degree => $coefficient) {
            if (($degree % 2) === 0 && $coefficient !== '0') {
                $onlyOddPowers = false;
            }
            if (($degree % 2) === 1 && $coefficient !== '0') {
                $onlyEvenPowers = false;
            }
            if (($degree % 2) === 0 && $onlyEvenPowers) {
                $reducedCoefficients[] = $coefficient;
            }
        }
        if ($onlyOddPowers) {
            $reducedCoefficients = [];
            foreach ($coefficients as $degree => $coefficient) {
                if (($degree % 2) === 1) {
                    $reducedCoefficients[] = $coefficient;
                }
            }
        } elseif (!$onlyEvenPowers) {
            return null;
        }
        if (\count($reducedCoefficients) < 2) {
            return null;
        }

        $reducedRoots = $this->isolateAllRealRoots($reducedCoefficients, $maxDepth, $maximumWidth);
        if ($reducedRoots === null) {
            return null;
        }

        $roots = $onlyOddPowers ? [['0', '1', '0', '1']] : [];
        foreach ($reducedRoots as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            $lower = [$lowerNumerator, $lowerDenominator];
            $upper = [$upperNumerator, $upperDenominator];
            $lowerSign = $this->integerArithmetic->sign($lowerNumerator);
            $upperSign = $this->integerArithmetic->sign($upperNumerator);
            if ($upperSign < 0) {
                continue;
            }
            if ($lowerSign < 0 && $upperSign > 0) {
                return null;
            }
            if ($lowerNumerator === '0' && $upperNumerator === '0') {
                $roots[] = ['0', '1', '0', '1'];
                continue;
            }
            if ($lowerSign < 0 || $upperSign <= 0) {
                continue;
            }

            $lowerRoot = (new ExactRationalInterval($lower, $lower))->sqrt();
            $upperRoot = (new ExactRationalInterval($upper, $upper))->sqrt();
            $positiveRoot = [$lowerRoot->lower, $upperRoot->upper];
            if (
                $maximumWidth !== null
                && !$this->widthAtMost($positiveRoot[0], $positiveRoot[1], $maximumWidth)
            ) {
                if ($lowerNumerator === $upperNumerator && $lowerDenominator === $upperDenominator) {
                    return null;
                }
                if ($lowerSign > 0) {
                    $targetReducedWidth = $this->multiplyFractions($maximumWidth, $lowerRoot->lower);
                } else {
                    $squaredWidth = $this->multiplyFractions($maximumWidth, $maximumWidth);
                    $targetReducedWidth = $squaredWidth === null
                        ? null
                        : $this->divideFractions($squaredWidth, ['4', '1']);
                }
                if ($targetReducedWidth === null || $targetReducedWidth[0] === '0') {
                    return null;
                }
                $refined = $this->isolateOpenIntervalWithBoundaryPolynomial(
                    $reducedCoefficients,
                    $lowerNumerator,
                    $lowerDenominator,
                    $upperNumerator,
                    $upperDenominator,
                    $maxDepth,
                    $targetReducedWidth,
                    $reducedCoefficients,
                );
                if ($refined === null || \count($refined) !== 1) {
                    return null;
                }
                [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator] = $refined[0];
                $lower = [$lowerNumerator, $lowerDenominator];
                $upper = [$upperNumerator, $upperDenominator];
                $lowerRoot = (new ExactRationalInterval($lower, $lower))->sqrt();
                $upperRoot = (new ExactRationalInterval($upper, $upper))->sqrt();
                $positiveRoot = [$lowerRoot->lower, $upperRoot->upper];
                if (!$this->widthAtMost($positiveRoot[0], $positiveRoot[1], $maximumWidth)) {
                    return null;
                }
            }
            $negativeRootLower = $this->negateFraction($positiveRoot[1]);
            $negativeRootUpper = $this->negateFraction($positiveRoot[0]);
            if ($negativeRootLower === null || $negativeRootUpper === null) {
                return null;
            }
            $roots[] = [
                $negativeRootLower[0],
                $negativeRootLower[1],
                $negativeRootUpper[0],
                $negativeRootUpper[1],
            ];
            $roots[] = [
                $positiveRoot[0][0],
                $positiveRoot[0][1],
                $positiveRoot[1][0],
                $positiveRoot[1][1],
            ];
        }
        $roots = $this->sortAndDeduplicateIntervals($roots);
        if ($maximumWidth !== null) {
            foreach ($roots as $root) {
                $rootWidth = $this->subtractFractions(
                    [$root[2], $root[3]],
                    [$root[0], $root[1]],
                );
                if ($rootWidth === null || $this->compareFractions($rootWidth, $maximumWidth) > 0) {
                    return null;
                }
            }
        }

        return $roots;
    }

    /**
     * Translate a polynomial to its exact rational center when that reveals
     * even or odd parity, then map the isolated roots back to the input axis.
     *
     * @param list<string> $coefficients
     * @param array{string, string}|null $maximumWidth
     * @return list<array{string, string, string, string}>|null
     */
    private function isolateCenteredParityPolynomialRoots(
        array $coefficients,
        int $maxDepth,
        ?array $maximumWidth,
    ): ?array {
        $degree = \count($coefficients) - 1;
        if ($degree < 4 || ($coefficients[$degree - 1] ?? '0') === '0') {
            return null;
        }
        $center = $this->reduceFraction([
            $this->integerArithmetic->negateIntegerValue($coefficients[$degree - 1]),
            $this->integerArithmetic->multiplyIntegerValues((string) $degree, $coefficients[$degree]),
        ]);
        if ($center === null || $center[0] === '0') {
            return null;
        }

        $translated = [[$coefficients[$degree], '1']];
        for ($power = $degree - 1; $power >= 0; --$power) {
            $next = \array_fill(0, \count($translated) + 1, ['0', '1']);
            foreach ($translated as $translatedPower => $coefficient) {
                $centerProduct = $this->multiplyFractions($coefficient, $center);
                if ($centerProduct === null) {
                    return null;
                }
                $currentCoefficient = $next[$translatedPower];
                $translatedCoefficient = $this->addFractions($currentCoefficient, $centerProduct);
                if ($translatedCoefficient === null) {
                    return null;
                }
                $next[$translatedPower] = $translatedCoefficient;
                if ($translatedPower > 0) {
                    $previousCoefficient = $translated[$translatedPower - 1];
                    $translatedCoefficient = $this->addFractions($translatedCoefficient, $previousCoefficient);
                    if ($translatedCoefficient === null) {
                        return null;
                    }
                    $next[$translatedPower] = $translatedCoefficient;
                }
            }
            $inputCoefficient = $coefficients[$power] ?? null;
            if ($inputCoefficient === null) {
                return null;
            }
            $constant = $this->addFractions($next[0], [$inputCoefficient, '1']);
            if ($constant === null) {
                return null;
            }
            $next[0] = $constant;
            $translated = $next;
        }

        $hasEvenCoefficient = false;
        $hasOddCoefficient = false;
        foreach ($translated as $power => [$numerator]) {
            if ($numerator === '0') {
                continue;
            }
            if (($power % 2) === 0) {
                $hasEvenCoefficient = true;
            } else {
                $hasOddCoefficient = true;
            }
        }
        if ($hasEvenCoefficient && $hasOddCoefficient) {
            return null;
        }

        $integerTranslation = $this->primitivePositiveScale($translated);
        $translatedCoefficients = [];
        foreach ($integerTranslation as [$numerator, $denominator]) {
            if ($denominator !== '1') {
                return null;
            }
            $translatedCoefficients[] = $numerator;
        }
        $translatedRoots = $this->isolateParityPolynomialRoots(
            $translatedCoefficients,
            $maxDepth,
            $maximumWidth,
        );
        if ($translatedRoots === null) {
            return null;
        }

        $roots = [];
        foreach ($translatedRoots as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
            $lower = $this->addFractions([$lowerNumerator, $lowerDenominator], $center);
            $upper = $this->addFractions([$upperNumerator, $upperDenominator], $center);
            if ($lower === null || $upper === null) {
                return null;
            }
            $roots[] = [$lower[0], $lower[1], $upper[0], $upper[1]];
        }

        return $this->sortAndDeduplicateIntervals($roots);
    }

    /**
     * Extract rational roots using a bounded divisor search and exact synthetic division.
     *
     * @param list<string> $coefficients
     * @return array{list<array{string, string}>, list<string>}
     */
    private function extractRationalRoots(array $coefficients): array
    {
        $roots = [];
        $remaining = $this->primitivePart($coefficients);
        while (\count($remaining) > 3) {
            $rootCandidates = $this->rationalRootCandidates($remaining);
            $foundRoot = false;
            foreach ($rootCandidates as $candidate) {
                if ($this->isRootAt($remaining, $candidate) !== true) {
                    continue;
                }
                $quotient = $this->divideByRationalRoot($remaining, $candidate);
                if ($quotient === null) {
                    break;
                }
                $roots[] = $candidate;
                $remaining = $quotient;
                $foundRoot = true;
                break;
            }
            if (!$foundRoot) {
                break;
            }
        }

        return [$roots, $remaining];
    }

    /** @param list<string> $coefficients
     *  @return list<array{string, string}>
     */
    private function rationalRootCandidates(array $coefficients): array
    {
        if ($coefficients[0] === '0') {
            return [['0', '1']];
        }
        $candidates = [];
        for ($index = 0; $index < \count($coefficients) - 1; ++$index) {
            if ($coefficients[$index + 1] === '0') {
                continue;
            }
            $candidate = $this->reduceFraction([
                $this->integerArithmetic->negateIntegerValue($coefficients[$index]),
                $coefficients[$index + 1],
            ]);
            if ($candidate !== null) {
                $candidates[$candidate[0] . '/' . $candidate[1]] = $candidate;
            }
        }
        $degree = \count($coefficients) - 1;
        $vietaSum = $this->reduceFraction([
            $this->integerArithmetic->negateIntegerValue($coefficients[$degree - 1]),
            $coefficients[$degree],
        ]);
        if ($vietaSum !== null && $vietaSum[1] === '1') {
            foreach (['-1', '1'] as $offset) {
                $neighbor = $this->integerArithmetic->addIntegerValues($vietaSum[0], $offset);
                if (\strlen(\ltrim($neighbor, '-')) <= self::MAX_INTEGER_DIGITS) {
                    $candidates[$neighbor . '/1'] = [$neighbor, '1'];
                }
            }
        }
        if (!$this->hasRationalRootModuloSmallPrime($coefficients)) {
            return \array_values($candidates);
        }
        $numerators = $this->positiveDivisors($coefficients[0]);
        $denominators = $this->positiveDivisors($coefficients[\array_key_last($coefficients)]);
        $maximumIndexSum = \count($numerators) + \count($denominators) - 2;
        for ($indexSum = 0; $indexSum <= $maximumIndexSum; ++$indexSum) {
            $firstNumeratorIndex = \max(0, $indexSum - (\count($denominators) - 1));
            $lastNumeratorIndex = \min($indexSum, \count($numerators) - 1);
            for ($numeratorIndex = $firstNumeratorIndex; $numeratorIndex <= $lastNumeratorIndex; ++$numeratorIndex) {
                $denominatorIndex = $indexSum - $numeratorIndex;
                $numerator = $numerators[$numeratorIndex];
                $denominator = $denominators[$denominatorIndex];
                foreach ([$numerator, $this->integerArithmetic->negateIntegerValue($numerator)] as $signedNumerator) {
                    $candidate = $this->reduceFraction([$signedNumerator, $denominator]);
                    if ($candidate !== null) {
                        $candidates[$candidate[0] . '/' . $candidate[1]] = $candidate;
                    }
                    if (\count($candidates) >= self::MAX_RATIONAL_ROOT_CANDIDATES) {
                        return \array_values($candidates);
                    }
                }
            }
        }

        return \array_values($candidates);
    }

    /** @param list<string> $coefficients */
    private function hasRationalRootModuloSmallPrime(array $coefficients): bool
    {
        foreach ([2, 3, 5, 7, 11, 13, 103] as $prime) {
            $residues = [];
            foreach ($coefficients as $coefficient) {
                $negative = \str_starts_with($coefficient, '-');
                $magnitude = $negative ? \substr($coefficient, 1) : $coefficient;
                $residue = $this->remainderBySmallInteger($magnitude, $prime);
                $residues[] = $negative && $residue !== 0 ? $prime - $residue : $residue;
            }
            $leadingIndex = \array_key_last($residues);
            if ($leadingIndex === null || $residues[$leadingIndex] === 0) {
                continue;
            }

            $hasResidueRoot = false;
            for ($candidate = 0; $candidate < $prime; ++$candidate) {
                $value = 0;
                for ($degree = \count($residues) - 1; $degree >= 0; --$degree) {
                    $value = (($value * $candidate) + $residues[$degree]) % $prime;
                }
                if ($value === 0) {
                    $hasResidueRoot = true;
                    break;
                }
            }
            if (!$hasResidueRoot) {
                // A rational root must reduce to a root modulo every prime
                // that does not divide the polynomial's leading coefficient.
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $coefficients
     *  @param array{string, string} $root
     *  @return list<string>|null
     */
    private function divideByRationalRoot(array $coefficients, array $root): ?array
    {
        if (\count($coefficients) < 2) {
            return null;
        }
        [$rootNumerator, $rootDenominator] = $root;
        $degree = \count($coefficients) - 1;
        $quotient = \array_fill(0, $degree, '0');
        $leadingQuotient = $this->integerArithmetic->divideIntegerValueExactly(
            $coefficients[$degree],
            $rootDenominator,
        );
        if ($leadingQuotient === null) {
            return null;
        }
        $quotient[$degree - 1] = $leadingQuotient;
        for ($power = $degree - 1; $power > 0; --$power) {
            $numerator = $this->integerArithmetic->addIntegerValues(
                $coefficients[$power],
                $this->integerArithmetic->multiplyIntegerValues($rootNumerator, $quotient[$power]),
            );
            $nextCoefficient = $this->integerArithmetic->divideIntegerValueExactly(
                $numerator,
                $rootDenominator,
            );
            if ($nextCoefficient === null) {
                return null;
            }
            $quotient[$power - 1] = $nextCoefficient;
        }
        $remainder = $this->integerArithmetic->addIntegerValues(
            $coefficients[0],
            $this->integerArithmetic->multiplyIntegerValues($rootNumerator, $quotient[0]),
        );
        if ($remainder !== '0') {
            return null;
        }
        foreach ($quotient as $coefficient) {
            if (\strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS) {
                return null;
            }
        }

        return $this->trim(\array_values($quotient));
    }

    /** @return list<string> */
    private function positiveDivisors(string $integer): array
    {
        $magnitude = \str_starts_with($integer, '-') ? \substr($integer, 1) : $integer;
        if ($magnitude === '0') {
            return [];
        }
        $nativeMagnitude = \filter_var($magnitude, FILTER_VALIDATE_INT);

        $divisors = [];
        for ($divisor = 1; $divisor <= self::MAX_RATIONAL_ROOT_TRIAL_DIVISOR; ++$divisor) {
            if (
                \is_int($nativeMagnitude)
                && $divisor > intdiv($nativeMagnitude, $divisor)
            ) {
                break;
            }
            $remainder = \is_int($nativeMagnitude)
                ? $nativeMagnitude % $divisor
                : $this->remainderBySmallInteger($magnitude, $divisor);
            if ($remainder !== 0) {
                continue;
            }
            $divisors[] = (string) $divisor;
            $pairedDivisor = \is_int($nativeMagnitude)
                ? (string) intdiv($nativeMagnitude, $divisor)
                : $this->integerArithmetic->divideIntegerValueExactly($magnitude, (string) $divisor);
            if ($pairedDivisor !== null && $pairedDivisor !== (string) $divisor) {
                $divisors[] = (string) $pairedDivisor;
            }
        }

        $divisors = \array_values(\array_unique($divisors));
        \usort(
            $divisors,
            fn (string $left, string $right): int => $this->integerArithmetic->compareIntegerValues($left, $right),
        );

        return $divisors;
    }

    /** @param list<string> $coefficients
     *  @return list<string>
     */
    private function primitivePart(array $coefficients): array
    {
        $content = '0';
        foreach ($coefficients as $coefficient) {
            $content = $this->integerArithmetic->greatestCommonDivisorValues($content, $coefficient);
            if ($content === '1') {
                return $coefficients;
            }
        }
        if ($content === '0') {
            return $coefficients;
        }
        foreach ($coefficients as $index => $coefficient) {
            $primitiveCoefficient = $this->integerArithmetic->divideIntegerValueExactly($coefficient, $content);
            if ($primitiveCoefficient === null) {
                return $coefficients;
            }
            $coefficients[$index] = $primitiveCoefficient;
        }

        return $coefficients;
    }

    private function remainderBySmallInteger(string $integer, int $divisor): int
    {
        $remainder = 0;
        $length = \strlen($integer);
        for ($index = 0; $index < $length; ++$index) {
            $remainder = (($remainder * 10) + (\ord($integer[$index]) - 48)) % $divisor;
        }

        return $remainder;
    }

    /** @param list<array{string, string}> $roots
     *  @return list<array{string, string}>
     */
    private function uniqueRationalRoots(array $roots): array
    {
        $unique = [];
        foreach ($roots as [$numerator, $denominator]) {
            $unique[$numerator . '/' . $denominator] = [$numerator, $denominator];
        }

        return \array_values($unique);
    }

    /**
     * @param list<array{string, string, string, string}> $intervals
     * @return list<array{string, string, string, string}>
     */
    private function sortAndDeduplicateIntervals(array $intervals): array
    {
        \usort(
            $intervals,
            fn (array $left, array $right): int => $this->integerArithmetic->compareIntegerValues(
                $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
                $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
            ),
        );
        $distinctIntervals = [];
        foreach ($intervals as $interval) {
            $lastInterval = $distinctIntervals[\array_key_last($distinctIntervals)] ?? null;
            if ($lastInterval === $interval) {
                continue;
            }
            $distinctIntervals[] = $interval;
        }

        return $distinctIntervals;
    }

    /**
     * @param list<string> $coefficients
     * @return list<array{string, string, string, string}>|null
     */
    private function exactQuadraticRoots(array $coefficients): ?array
    {
        [$constant, $linear, $quadratic] = $coefficients;
        $linearSquared = $this->integerArithmetic->multiplyIntegerValues($linear, $linear);
        $fourConstantQuadratic = $this->integerArithmetic->multiplyIntegerValues(
            '4',
            $this->integerArithmetic->multiplyIntegerValues($constant, $quadratic),
        );
        $discriminant = $this->integerArithmetic->subtract($linearSquared, $fourConstantQuadratic);
        if ($this->integerArithmetic->sign($discriminant) < 0) {
            return [];
        }
        if (strlen(ltrim($discriminant, '-')) > (2 * self::MAX_INTEGER_DIGITS) + 1) {
            return null;
        }
        $squareRoot = $this->integerArithmetic->integerSquareRoot($discriminant);
        if (
            $squareRoot === null
            || $this->integerArithmetic->multiplyIntegerValues($squareRoot, $squareRoot) !== $discriminant
        ) {
            return null;
        }

        $denominator = $this->integerArithmetic->multiplyIntegerValues('2', $quadratic);
        $negativeLinear = $this->integerArithmetic->negateIntegerValue($linear);
        $roots = [];
        foreach (
            [
                $this->integerArithmetic->subtract($negativeLinear, $squareRoot),
                $this->integerArithmetic->addIntegerValues($negativeLinear, $squareRoot),
            ] as $numerator
        ) {
            $root = $this->reduceFraction([$numerator, $denominator]);
            if ($root === null) {
                return null;
            }
            $roots[] = $root;
        }
        if (
            $this->integerArithmetic->compareIntegerValues(
                $this->integerArithmetic->multiplyIntegerValues($roots[0][0], $roots[1][1]),
                $this->integerArithmetic->multiplyIntegerValues($roots[1][0], $roots[0][1]),
            ) > 0
        ) {
            [$roots[0], $roots[1]] = [$roots[1], $roots[0]];
        }

        $intervals = [];
        $rootCount = $roots[0] === $roots[1] ? 1 : 2;
        for ($index = 0; $index < $rootCount; ++$index) {
            [$numerator, $rootDenominator] = $roots[$index];
            $intervals[] = [$numerator, $rootDenominator, $numerator, $rootDenominator];
        }

        return $intervals;
    }

    /**
     * Enclose irrational quadratic roots directly using rational square-root bounds.
     *
     * @param list<string> $coefficients
     * @param array{string, string}|null $maximumWidth
     * @return list<array{string, string, string, string}>|null
     */
    private function irrationalQuadraticRootIntervals(array $coefficients, ?array $maximumWidth): ?array
    {
        [$constant, $linear, $quadratic] = $coefficients;
        $discriminant = $this->integerArithmetic->subtract(
            $this->integerArithmetic->multiplyIntegerValues($linear, $linear),
            $this->integerArithmetic->multiplyIntegerValues(
                '4',
                $this->integerArithmetic->multiplyIntegerValues($constant, $quadratic),
            ),
        );
        if (
            $this->integerArithmetic->sign($discriminant) <= 0
            || \strlen(\ltrim($discriminant, '-')) > (2 * self::MAX_INTEGER_DIGITS) + 1
        ) {
            return null;
        }
        try {
            $discriminantInterval = new ExactRationalInterval(
                [$discriminant, '1'],
                [$discriminant, '1'],
            );
            $squareRoot = $discriminantInterval->sqrt();
            $negativeLinearValue = $this->integerArithmetic->negateIntegerValue($linear);
            $negativeLinear = new ExactRationalInterval(
                [$negativeLinearValue, '1'],
                [$negativeLinearValue, '1'],
            );
            $denominatorValue = $this->integerArithmetic->multiplyIntegerValues('2', $quadratic);
            $denominator = new ExactRationalInterval(
                [$denominatorValue, '1'],
                [$denominatorValue, '1'],
            );
            $candidates = [
                $negativeLinear->subtract($squareRoot)->divide($denominator),
                $negativeLinear->add($squareRoot)->divide($denominator),
            ];
        } catch (\DomainException | \LengthException) {
            return null;
        }
        usort(
            $candidates,
            static fn (ExactRationalInterval $left, ExactRationalInterval $right): int =>
                $left->compareLowerEndpoint($right),
        );

        $width = $maximumWidth === null
            ? null
            : new ExactRationalInterval(['0', '1'], $maximumWidth);
        $intervals = [];
        $previousUpper = null;
        foreach ($candidates as $candidate) {
            if (
                $previousUpper !== null
                && $this->integerArithmetic->compareIntegerValues(
                    $this->integerArithmetic->multiplyIntegerValues($previousUpper[0], $candidate->lower[1]),
                    $this->integerArithmetic->multiplyIntegerValues($candidate->lower[0], $previousUpper[1]),
                ) >= 0
            ) {
                return null;
            }
            $candidateWidth = $candidate->subtract(
                new ExactRationalInterval($candidate->lower, $candidate->lower),
            );
            if (
                $width !== null
                && $candidateWidth->compareUpperToRational($width->upper[0], $width->upper[1]) > 0
            ) {
                return null;
            }
            $intervals[] = [
                $candidate->lower[0],
                $candidate->lower[1],
                $candidate->upper[0],
                $candidate->upper[1],
            ];
            $previousUpper = $candidate->upper;
        }

        return $intervals;
    }

    /**
     * Resolve binomials whose real roots are exact rational perfect powers.
     *
     * @param list<string> $coefficients
     * @return list<array{string, string, string, string}>|null
     */
    private function exactBinomialRoots(array $coefficients): ?array
    {
        if (count($coefficients) < 3 || $coefficients[0] === '0') {
            return null;
        }
        foreach (array_slice($coefficients, 1, -1) as $coefficient) {
            if ($coefficient !== '0') {
                return null;
            }
        }

        $degree = count($coefficients) - 1;
        $ratio = $this->reduceFraction([
            $this->integerArithmetic->negateIntegerValue($coefficients[0]),
            $coefficients[$degree],
        ]);
        if ($ratio === null) {
            return null;
        }
        if ($degree % 2 === 0 && str_starts_with($ratio[0], '-')) {
            return [];
        }

        $negative = str_starts_with($ratio[0], '-');
        $numerator = $negative ? substr($ratio[0], 1) : $ratio[0];
        $rootNumerator = $this->exactIntegerNthRoot($numerator, $degree);
        $rootDenominator = $this->exactIntegerNthRoot($ratio[1], $degree);
        if ($rootNumerator === null || $rootDenominator === null) {
            return null;
        }
        if ($negative) {
            $rootNumerator = $this->integerArithmetic->negateIntegerValue($rootNumerator);
        }
        $root = $this->reduceFraction([$rootNumerator, $rootDenominator]);
        if ($root === null) {
            return null;
        }

        $roots = [$root];
        if ($degree % 2 === 0 && $root[0] !== '0') {
            $roots[] = [$this->integerArithmetic->negateIntegerValue($root[0]), $root[1]];
        }
        usort(
            $roots,
            fn (array $left, array $right): int => $this->integerArithmetic->compareIntegerValues(
                $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
                $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
            ),
        );

        return array_map(
            static fn (array $root): array => [$root[0], $root[1], $root[0], $root[1]],
            $roots,
        );
    }

    private function exactIntegerNthRoot(string $value, int $degree): ?string
    {
        return $degree === 1
            ? $value
            : $this->integerArithmetic->integerNthRoot($value, $degree);
    }

    private function integerNthRootCeiling(string $value, int $degree): ?string
    {
        $floor = $this->integerArithmetic->integerNthRootFloor($value, $degree);
        if ($floor === null) {
            return null;
        }

        return $this->integerArithmetic->integerNthRoot($value, $degree)
            ?? $this->integerArithmetic->addIntegerValues($floor, '1');
    }

    /**
     * Compute a strict integer upper bound using Fujiwara's coefficient bound.
     *
     * @param list<string> $coefficients
     */
    private function fujiwaraRootBound(array $coefficients): ?string
    {
        $degree = count($coefficients) - 1;
        $leading = $coefficients[$degree];
        $leadingMagnitude = str_starts_with($leading, '-') ? substr($leading, 1) : $leading;
        $maximumRootBound = '0';
        foreach ($coefficients as $index => $coefficient) {
            if ($index === $degree || $coefficient === '0') {
                continue;
            }
            $magnitude = str_starts_with($coefficient, '-') ? substr($coefficient, 1) : $coefficient;
            $quotient = $this->integerArithmetic->divideNonnegativeIntegerValuesFloor(
                $magnitude,
                $leadingMagnitude,
            );
            $remainder = $this->integerArithmetic->subtract(
                $magnitude,
                $this->integerArithmetic->multiplyIntegerValues($quotient, $leadingMagnitude),
            );
            $ratioCeiling = $remainder === '0'
                ? $quotient
                : $this->integerArithmetic->addIntegerValues($quotient, '1');
            $termBound = $this->integerNthRootCeiling($ratioCeiling, $degree - $index);
            if ($termBound === null) {
                return null;
            }
            if ($this->integerArithmetic->compareIntegerValues($termBound, $maximumRootBound) > 0) {
                $maximumRootBound = $termBound;
            }
        }
        $bound = $this->integerArithmetic->addIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues('2', $maximumRootBound),
            '1',
        );

        return strlen($bound) <= self::MAX_INTEGER_DIGITS ? $bound : null;
    }

    /** @param list<list<array{string, string}>> $sequence */
    private function variationsAtInfinity(array $sequence, int $direction): ?int
    {
        $signs = [];
        foreach ($sequence as $polynomial) {
            $leadingIndex = \array_key_last($polynomial);
            if ($leadingIndex === null) {
                return null;
            }
            $leading = $polynomial[$leadingIndex][0];
            if ($leading === '0') {
                return null;
            }
            $sign = $this->integerArithmetic->sign($leading);
            $degree = \count($polynomial) - 1;
            if ($direction < 0 && ($degree % 2) === 1) {
                $sign *= -1;
            }
            $signs[] = $sign;
        }

        $variations = 0;
        for ($index = 1, $count = \count($signs); $index < $count; ++$index) {
            if ($signs[$index - 1] !== $signs[$index]) {
                ++$variations;
            }
        }

        return $variations;
    }

    /**
     * Isolate distinct roots into disjoint rational open intervals, each with
     * exactly one root. Returns null when the configured work or arithmetic
     * limits prevent a complete isolation. Equal bounds return an empty list;
     * reversed bounds return null.
     *
     * @param list<string> $coefficients Ascending integer coefficients.
     * @param array<array-key, mixed>|null $maximumWidth Positive exact rational width limit.
     * @return list<array{string, string, string, string}>|null
     */
    public function isolateOpenInterval(
        array $coefficients,
        string $lowerNumerator,
        string $lowerDenominator,
        string $upperNumerator,
        string $upperDenominator,
        int $maxDepth = 256,
        ?array $maximumWidth = null,
    ): ?array {
        return $this->isolateOpenIntervalWithBoundaryPolynomial(
            $coefficients,
            $lowerNumerator,
            $lowerDenominator,
            $upperNumerator,
            $upperDenominator,
            $maxDepth,
            $maximumWidth,
            $coefficients,
            true,
        );
    }

    /**
     * @param list<string> $coefficients
     * @param list<string> $boundaryPolynomial Original polynomial retained
     *        after rational factors are removed from the working polynomial.
     * @param array<array-key, mixed>|null $maximumWidth
     * @param list<list<array{string, string}>>|null $sturmSequence
     * @return list<array{string, string, string, string}>|null
     */
    private function isolateOpenIntervalWithBoundaryPolynomial(
        array $coefficients,
        string $lowerNumerator,
        string $lowerDenominator,
        string $upperNumerator,
        string $upperDenominator,
        int $maxDepth,
        ?array $maximumWidth,
        array $boundaryPolynomial,
        bool $tryParityOptimization = false,
        ?array $sturmSequence = null,
        ?int $leftVariations = null,
        ?int $rightVariations = null,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            return null;
        }
        if ($maximumWidth !== null) {
            if (
                !\array_is_list($maximumWidth)
                || \count($maximumWidth) !== 2
                || !\is_string($maximumWidth[0] ?? null)
                || !\is_string($maximumWidth[1] ?? null)
            ) {
                return null;
            }
            $widthNumerator = $maximumWidth[0];
            $widthDenominator = $maximumWidth[1];
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $widthNumerator) !== 1
                || \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $widthDenominator) !== 1
                || \strlen(\ltrim($widthNumerator, '-')) > self::MAX_INTEGER_DIGITS
                || \strlen(\ltrim($widthDenominator, '-')) > self::MAX_INTEGER_DIGITS
                || $this->integerArithmetic->compareIntegerValues($widthNumerator, '0') <= 0
                || $this->integerArithmetic->compareIntegerValues($widthDenominator, '0') <= 0
            ) {
                return null;
            }
            $maximumWidth = [$widthNumerator, $widthDenominator];
            $maximumWidth = $this->reduceFraction($maximumWidth);
            if ($maximumWidth === null) {
                return null;
            }
        }
        if ($coefficients === []) {
            return null;
        }
        $normalizedCoefficients = [];
        foreach ($coefficients as $coefficient) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $normalizedCoefficients[] = $this->canonicalZero($coefficient);
        }
        $coefficients = $this->trim($normalizedCoefficients);
        if ($coefficients === ['0'] || \count($coefficients) - 1 > self::MAX_DEGREE) {
            return null;
        }
        foreach (
            [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]
            as $boundPart
        ) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $boundPart) !== 1
                || \strlen(\ltrim($boundPart, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
        }
        $lowerNumerator = $this->canonicalZero($lowerNumerator);
        $lowerDenominator = $this->canonicalZero($lowerDenominator);
        $upperNumerator = $this->canonicalZero($upperNumerator);
        $upperDenominator = $this->canonicalZero($upperDenominator);
        $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
        $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
        if ($left === null || $right === null) {
            return null;
        }
        $intervalOrder = $this->compareFractions($left, $right);
        if ($intervalOrder > 0) {
            return null;
        }
        if ($intervalOrder === 0) {
            return [];
        }
        if (\count($coefficients) === 2 && $coefficients[1] !== '0') {
            $root = $this->reduceFraction([
                $this->integerArithmetic->negateIntegerValue($coefficients[0]),
                $coefficients[1],
            ]);
            $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
            $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
            if (
                $root === null
                || $left === null
                || $right === null
                || $this->compareFractions($left, $right) >= 0
            ) {
                return null;
            }
            if ($this->compareFractions($root, $left) <= 0 || $this->compareFractions($root, $right) >= 0) {
                return [];
            }
            $isRootOfBoundaryPolynomial = $this->isRootAt($boundaryPolynomial, $root);
            if ($isRootOfBoundaryPolynomial === null) {
                return null;
            }
            if ($isRootOfBoundaryPolynomial) {
                return [[$root[0], $root[1], $root[0], $root[1]]];
            }
        }
        if ($tryParityOptimization) {
            $parityIntervals = $this->isolateParityRootsInOpenInterval(
                $coefficients,
                [$lowerNumerator, $lowerDenominator],
                [$upperNumerator, $upperDenominator],
                $maxDepth,
                $maximumWidth,
            );
            if ($parityIntervals !== null) {
                return $parityIntervals;
            }
        }
        if ($tryParityOptimization && \count($coefficients) > 3) {
            $integerCoefficients = [];
            foreach ($coefficients as $coefficient) {
                if (
                    \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                    || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
                ) {
                    $integerCoefficients = [];
                    break;
                }
                $integerCoefficients[] = $this->canonicalZero($coefficient);
            }
            $integerCoefficients = $this->trim($integerCoefficients);
            if (\count($integerCoefficients) > 3) {
                [$rationalRoots, $remainingPolynomial] = $this->extractRationalRoots($integerCoefficients);
                if ($rationalRoots !== []) {
                    $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
                    $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
                    if (
                        $left === null
                        || $right === null
                        || $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
                        ) >= 0
                    ) {
                        return null;
                    }
                    $remainingIntervals = \count($remainingPolynomial) > 1
                        ? $this->isolateOpenIntervalWithBoundaryPolynomial(
                            $remainingPolynomial,
                            $left[0],
                            $left[1],
                            $right[0],
                            $right[1],
                            $maxDepth,
                            $maximumWidth,
                            $boundaryPolynomial,
                        )
                        : [];
                    if ($remainingIntervals === null) {
                        return null;
                    }
                    $intervals = [...$remainingIntervals];
                    foreach ($this->uniqueRationalRoots($rationalRoots) as [$numerator, $denominator]) {
                        $rootAfterLeft = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($numerator, $left[1]),
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $denominator),
                        ) > 0;
                        $rootBeforeRight = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($numerator, $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $denominator),
                        ) < 0;
                        if ($rootAfterLeft && $rootBeforeRight) {
                            $intervals[] = [$numerator, $denominator, $numerator, $denominator];
                        }
                    }

                    return $this->sortAndDeduplicateIntervals($intervals);
                }
            }
        }
        if (\count($coefficients) === 3) {
            $quadraticCoefficients = [];
            foreach ($coefficients as $coefficient) {
                if (
                    \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                    || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
                ) {
                    $quadraticCoefficients = [];
                    break;
                }
                $quadraticCoefficients[] = $this->canonicalZero($coefficient);
            }
            $quadraticCoefficients = $this->trim($quadraticCoefficients);
            if (\count($quadraticCoefficients) === 3) {
                $quadraticRoots = $this->exactQuadraticRoots($quadraticCoefficients);
                if ($quadraticRoots !== null) {
                    $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
                    $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
                    if (
                        $left === null
                        || $right === null
                        || $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
                        ) >= 0
                    ) {
                        return null;
                    }
                    $containedRoots = [];
                    foreach ($quadraticRoots as [$numerator, $denominator]) {
                        $afterLeft = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($numerator, $left[1]),
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $denominator),
                        ) > 0;
                        $beforeRight = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($numerator, $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $denominator),
                        ) < 0;
                        if ($afterLeft && $beforeRight) {
                            $containedRoots[] = [$numerator, $denominator, $numerator, $denominator];
                        }
                    }

                    return $containedRoots;
                }
                $quadraticIntervals = $maxDepth <= 16
                    ? $this->irrationalQuadraticRootIntervals($quadraticCoefficients, $maximumWidth)
                    : null;
                if ($quadraticIntervals !== null) {
                    $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
                    $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
                    if ($left === null || $right === null) {
                        return null;
                    }
                    $containedRoots = [];
                    $ambiguousBoundary = false;
                    foreach ($quadraticIntervals as $interval) {
                        $candidateLower = [$interval[0], $interval[1]];
                        $candidateUpper = [$interval[2], $interval[3]];
                        $upperIsLeftOrBefore = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($candidateUpper[0], $left[1]),
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $candidateUpper[1]),
                        ) <= 0;
                        $lowerIsRightOrAfter = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($candidateLower[0], $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $candidateLower[1]),
                        ) >= 0;
                        if ($upperIsLeftOrBefore || $lowerIsRightOrAfter) {
                            continue;
                        }
                        $lowerIsInside = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($candidateLower[0], $left[1]),
                            $this->integerArithmetic->multiplyIntegerValues($left[0], $candidateLower[1]),
                        ) >= 0;
                        $upperIsInside = $this->integerArithmetic->compareIntegerValues(
                            $this->integerArithmetic->multiplyIntegerValues($candidateUpper[0], $right[1]),
                            $this->integerArithmetic->multiplyIntegerValues($right[0], $candidateUpper[1]),
                        ) <= 0;
                        if (!$lowerIsInside || !$upperIsInside) {
                            $ambiguousBoundary = true;
                            break;
                        }
                        $containedRoots[] = $interval;
                    }
                    if (!$ambiguousBoundary) {
                        return $containedRoots;
                    }
                }
            }
        }
        $sturmSequence ??= $this->sturmSequence($coefficients);
        if ($sturmSequence === null) {
            return null;
        }
        $left = $this->reduceFraction([$lowerNumerator, $lowerDenominator]);
        $right = $this->reduceFraction([$upperNumerator, $upperDenominator]);
        if ($left === null || $right === null) {
            return null;
        }
        $leftVariations ??= $this->variations($sturmSequence, $left, 1);
        $rightVariations ??= $this->variations($sturmSequence, $right, -1);
        if ($leftVariations === null || $rightVariations === null) {
            return null;
        }
        $count = $leftVariations - $rightVariations;
        if ($count === 0) {
            return [];
        }
        if ($count === 1 && ($maximumWidth === null || $this->widthAtMost($left, $right, $maximumWidth))) {
            $leftIsRoot = $this->isRootAt($boundaryPolynomial, $left);
            $rightIsRoot = $this->isRootAt($boundaryPolynomial, $right);
            if ($leftIsRoot === null || $rightIsRoot === null) {
                return null;
            }
            if (!$leftIsRoot && !$rightIsRoot) {
                $boundaryRootCount = $boundaryPolynomial === $coefficients
                    ? $count
                    : $this->countOpenInterval(
                        $boundaryPolynomial,
                        $left[0],
                        $left[1],
                        $right[0],
                        $right[1],
                    );
                if ($boundaryRootCount === null) {
                    return null;
                }
                if ($boundaryRootCount === 1) {
                    return [[
                        $left[0],
                        $left[1],
                        $right[0],
                        $right[1],
                    ]];
                }
            }
        }
        if ($maxDepth === 1) {
            return null;
        }

        $middleNumerator = $this->integerArithmetic->addIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
        );
        $middleDenominator = $this->integerArithmetic->multiplyIntegerValues(
            '2',
            $this->integerArithmetic->multiplyIntegerValues($left[1], $right[1]),
        );
        $middle = $this->reduceFraction([$middleNumerator, $middleDenominator]);
        if ($middle === null) {
            return null;
        }
        $middleLeftVariations = $this->variations($sturmSequence, $middle, -1);
        $middleRightVariations = $this->variations($sturmSequence, $middle, 1);
        if ($middleLeftVariations === null || $middleRightVariations === null) {
            return null;
        }
        $middleIsRoot = $this->isRootAt($coefficients, $middle);
        if ($middleIsRoot === null) {
            return null;
        }
        $leftIntervals = $this->isolateOpenIntervalWithBoundaryPolynomial(
            $coefficients,
            $left[0],
            $left[1],
            $middle[0],
            $middle[1],
            $maxDepth - 1,
            $maximumWidth,
            $boundaryPolynomial,
            false,
            $sturmSequence,
            $leftVariations,
            $middleLeftVariations,
        );
        $rightIntervals = $this->isolateOpenIntervalWithBoundaryPolynomial(
            $coefficients,
            $middle[0],
            $middle[1],
            $right[0],
            $right[1],
            $maxDepth - 1,
            $maximumWidth,
            $boundaryPolynomial,
            false,
            $sturmSequence,
            $middleRightVariations,
            $rightVariations,
        );
        if ($leftIntervals === null || $rightIntervals === null) {
            return null;
        }
        return [
            ...$leftIntervals,
            ...($middleIsRoot ? [[$middle[0], $middle[1], $middle[0], $middle[1]]] : []),
            ...$rightIntervals,
        ];
    }

    /**
     * Use parity reduction for finite intervals only when every all-real
     * isolating interval can be classified without crossing an endpoint.
     *
     * @param list<string> $coefficients
     * @param array{string, string} $left
     * @param array{string, string} $right
     * @param array{string, string}|null $maximumWidth
     * @return list<array{string, string, string, string}>|null
     */
    private function isolateParityRootsInOpenInterval(
        array $coefficients,
        array $left,
        array $right,
        int $maxDepth,
        ?array $maximumWidth,
    ): ?array {
        $left = $this->reduceFraction($left);
        $right = $this->reduceFraction($right);
        if ($left === null || $right === null) {
            return null;
        }
        $allRoots = $this->isolateParityPolynomialRoots($coefficients, $maxDepth, $maximumWidth);
        if ($allRoots === null) {
            return null;
        }

        $containedRoots = [];
        foreach ($allRoots as $root) {
            $rootLower = [$root[0], $root[1]];
            $rootUpper = [$root[2], $root[3]];
            $lowerToLeft = $this->compareFractions($rootLower, $left);
            $upperToLeft = $this->compareFractions($rootUpper, $left);
            $lowerToRight = $this->compareFractions($rootLower, $right);
            $upperToRight = $this->compareFractions($rootUpper, $right);
            if ($lowerToLeft === 0 && $upperToLeft === 0) {
                continue;
            }
            if ($lowerToRight === 0 && $upperToRight === 0) {
                continue;
            }
            if ($upperToLeft <= 0 || $lowerToRight >= 0) {
                continue;
            }
            if ($lowerToLeft <= 0 || $upperToRight >= 0) {
                return null;
            }
            $containedRoots[] = $root;
        }

        return $containedRoots;
    }

    /** @param list<string> $coefficients
     *  @return list<array{string, string}>
     */
    private function integerPolynomial(array $coefficients): array
    {
        return \array_map(static fn (string $coefficient): array => [$coefficient, '1'], $coefficients);
    }

    /**
     * @param list<string> $coefficients
     * @return list<list<array{string, string}>>|null
     */
    private function sturmSequence(array $coefficients): ?array
    {
        if ($coefficients === []) {
            return null;
        }
        foreach ($coefficients as &$coefficient) {
            if (
                preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || strlen(ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
            $coefficient = $this->canonicalZero($coefficient);
        }
        unset($coefficient);
        $coefficients = $this->trim($coefficients);
        if ($coefficients === ['0'] || count($coefficients) - 1 > self::MAX_DEGREE) {
            return null;
        }

        $coefficients = $this->primitivePart($coefficients);
        $polynomial = $this->integerPolynomial($coefficients);
        if (count($polynomial) === 1) {
            return [$polynomial];
        }
        $derivative = $this->primitivePositiveScale($this->derivative($polynomial));
        if (!$this->withinLimits($derivative)) {
            return null;
        }
        $sequence = [$polynomial, $derivative];
        while (count($sequence[count($sequence) - 1]) > 1) {
            $remainder = $this->remainder(
                $sequence[count($sequence) - 2],
                $sequence[count($sequence) - 1],
            );
            if ($remainder === null) {
                return null;
            }
            if ($this->isZeroPolynomial($remainder)) {
                break;
            }
            $negativeRemainder = [];
            foreach ($remainder as $coefficient) {
                $negative = $this->negateFraction($coefficient);
                if ($negative === null) {
                    return null;
                }
                $negativeRemainder[] = $negative;
            }
            $primitiveRemainder = $this->primitivePositiveScale($negativeRemainder);
            if (!$this->withinLimits($primitiveRemainder)) {
                return null;
            }
            $sequence[] = $primitiveRemainder;
        }

        return $sequence;
    }

    /**
     * @param list<list<array{string, string}>> $sequence
     * @param array{string, string} $lower
     * @param array{string, string} $upper
     */
    private function countOpenIntervalWithSequence(array $sequence, array $lower, array $upper): ?int
    {
        $leftVariations = $this->variations($sequence, $lower, 1);
        $rightVariations = $this->variations($sequence, $upper, -1);

        return $leftVariations === null || $rightVariations === null
            ? null
            : $leftVariations - $rightVariations;
    }

    /** @param list<array{string, string}> $polynomial
     *  @return list<array{string, string}>
     */
    private function derivative(array $polynomial): array
    {
        if (\count($polynomial) < 2) {
            return [['0', '1']];
        }
        $result = [];
        for ($degree = 1; $degree < \count($polynomial); ++$degree) {
            $numerator = $this->integerArithmetic->multiplyIntegerValues(
                $polynomial[$degree][0],
                (string) $degree,
            );
            $fraction = $this->reduceFraction([$numerator, $polynomial[$degree][1]]);
            if ($fraction === null) {
                return [['0', '0']];
            }
            $result[] = $fraction;
        }

        return $this->trimFractions($result);
    }

    /** @param list<array{string, string}> $dividend
     *  @param list<array{string, string}> $divisor
     *  @return list<array{string, string}>|null
     */
    private function remainder(array $dividend, array $divisor): ?array
    {
        $integerRemainder = [];
        $integerDivisor = [];
        foreach ($dividend as [$numerator, $denominator]) {
            if ($denominator !== '1') {
                return null;
            }
            $integerRemainder[] = $numerator;
        }
        foreach ($divisor as [$numerator, $denominator]) {
            if ($denominator !== '1') {
                return null;
            }
            $integerDivisor[] = $numerator;
        }

        $integerRemainder = $this->trim($integerRemainder);
        $integerDivisor = $this->trim($integerDivisor);
        if ($integerDivisor === ['0']) {
            return null;
        }
        $divisorDegree = \count($integerDivisor) - 1;
        $divisorLead = $integerDivisor[$divisorDegree];
        $positiveDivisorLead = \ltrim($divisorLead, '-');
        $divisorLeadSign = $this->integerArithmetic->sign($divisorLead);

        // Positive scaling preserves the Euclidean remainder's sign for Sturm sequences.
        while (\count($integerRemainder) >= \count($integerDivisor) && $integerRemainder !== ['0']) {
            $previousRemainderLength = \count($integerRemainder);
            $degree = \count($integerRemainder) - 1;
            $shift = $degree - $divisorDegree;
            $leadingRemainder = $integerRemainder[$degree];
            $subtractionFactor = $divisorLeadSign > 0
                ? $leadingRemainder
                : $this->integerArithmetic->negateIntegerValue($leadingRemainder);
            foreach ($integerRemainder as $index => $coefficient) {
                $integerRemainder[$index] = $this->integerArithmetic->multiplyIntegerValues(
                    $coefficient,
                    $positiveDivisorLead,
                );
            }
            for ($index = 0; $index <= $divisorDegree; ++$index) {
                $product = $this->integerArithmetic->multiplyIntegerValues(
                    $subtractionFactor,
                    $integerDivisor[$index],
                );
                $integerRemainder[$index + $shift] = $this->integerArithmetic->subtract(
                    $integerRemainder[$index + $shift],
                    $product,
                );
            }
            $integerRemainder = \array_values($integerRemainder);
            $integerRemainder = $this->trim($integerRemainder);
            if (
                $integerRemainder !== ['0']
                && \count($integerRemainder) >= $previousRemainderLength
            ) {
                return null;
            }
            foreach ($integerRemainder as $coefficient) {
                if (\strlen(\ltrim($coefficient, '-')) > (2 * self::MAX_INTEGER_DIGITS) + 1) {
                    return null;
                }
            }
            $integerRemainder = $this->primitivePart($integerRemainder);
            foreach ($integerRemainder as $coefficient) {
                if (\strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS) {
                    return null;
                }
            }
        }

        return $this->integerPolynomial($integerRemainder);
    }

    /** @param list<array{string, string}> $polynomial
     *  @return list<array{string, string}>
     */
    private function primitivePositiveScale(array $polynomial): array
    {
        $commonDenominator = '1';
        foreach ($polynomial as [, $denominator]) {
            $gcd = $this->integerArithmetic->greatestCommonDivisorValues($commonDenominator, $denominator);
            $factor = $this->integerArithmetic->divideIntegerValueExactly($denominator, $gcd);
            if ($factor === null) {
                return $polynomial;
            }
            $commonDenominator = $this->integerArithmetic->multiplyIntegerValues($commonDenominator, $factor);
        }
        $integers = [];
        $content = '0';
        foreach ($polynomial as [$numerator, $denominator]) {
            $factor = $this->integerArithmetic->divideIntegerValueExactly($commonDenominator, $denominator);
            if ($factor === null) {
                return $polynomial;
            }
            $integer = $this->integerArithmetic->multiplyIntegerValues($numerator, $factor);
            $integers[] = $integer;
            $content = $this->integerArithmetic->greatestCommonDivisorValues($content, $integer);
        }
        if ($content !== '0') {
            foreach ($integers as $index => $integer) {
                $integers[$index] = $this->integerArithmetic->divideIntegerValueExactly($integer, $content) ?? $integer;
            }
        }
        return $this->integerPolynomial($integers);
    }

    /** @param list<list<array{string, string}>> $sequence
     *  @param array{string, string} $point
     */
    private function variations(array $sequence, array $point, int $direction): ?int
    {
        $signs = [];
        foreach ($sequence as $polynomial) {
            $derivativeOrder = 0;
            $evaluatedPolynomial = $polynomial;
            while (true) {
                $value = ['0', '1'];
                for ($degree = \count($evaluatedPolynomial) - 1; $degree >= 0; --$degree) {
                    $product = $this->multiplyFractions($value, $point);
                    $value = $product === null
                        ? null
                        : $this->addFractions($product, $evaluatedPolynomial[$degree]);
                    if ($value === null) {
                        return null;
                    }
                }
                if ($value[0] !== '0') {
                    $sign = $this->integerArithmetic->sign($value[0]);
                    if ($direction < 0 && ($derivativeOrder % 2) === 1) {
                        $sign *= -1;
                    }
                    $signs[] = $sign;
                    break;
                }
                if (\count($evaluatedPolynomial) === 1) {
                    break;
                }
                $evaluatedPolynomial = $this->derivative($evaluatedPolynomial);
                ++$derivativeOrder;
                if (!$this->withinLimits($evaluatedPolynomial)) {
                    return null;
                }
            }
        }
        $variations = 0;
        for ($index = 1; $index < \count($signs); ++$index) {
            if ($signs[$index] !== $signs[$index - 1]) {
                ++$variations;
            }
        }

        return $variations;
    }

    /**
     * Evaluate whether an integer-coefficient polynomial is zero at a rational point.
     * Returns null when the supplied integers exceed the exact arithmetic limits.
     *
     * @param list<string> $coefficients
     * @param array<array-key, mixed> $point Reduced numerator and positive denominator.
     */
    public function isRootAt(array $coefficients, array $point): ?bool
    {
        $sign = $this->signAt($coefficients, $point);

        return $sign === null ? null : $sign === 0;
    }

    /**
     * Evaluate the exact sign of an integer-coefficient polynomial at a rational point.
     * Returns null when the supplied integers exceed the exact arithmetic limits.
     *
     * @param list<string> $coefficients
     * @param array<array-key, mixed> $point Reduced numerator and positive denominator.
     */
    public function signAt(array $coefficients, array $point): ?int
    {
        if (
            $coefficients === []
            || !\array_is_list($point)
            || \count($point) !== 2
            || !\is_string($point[0] ?? null)
            || !\is_string($point[1] ?? null)
            || \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $point[0]) !== 1
            || \preg_match('/\A[1-9][0-9]*\z/D', $point[1]) !== 1
            || \strlen(\ltrim($point[0], '-')) > self::MAX_INTEGER_DIGITS
            || \strlen($point[1]) > self::MAX_INTEGER_DIGITS
        ) {
            return null;
        }
        foreach ($coefficients as $coefficient) {
            if (
                \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                || \strlen(\ltrim($coefficient, '-')) > self::MAX_INTEGER_DIGITS
            ) {
                return null;
            }
        }
        $value = ['0', '1'];
        for ($degree = \count($coefficients) - 1; $degree >= 0; --$degree) {
            $product = $this->multiplyFractions($value, $point);
            if ($product === null) {
                return null;
            }
            $value = $this->addFractions($product, [$coefficients[$degree], '1']);
            if ($value === null) {
                return null;
            }
        }

        return $this->integerArithmetic->compareIntegerValues($value[0], '0') <=> 0;
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @param array{string, string} $maximumWidth
     */
    private function widthAtMost(array $left, array $right, array $maximumWidth): bool
    {
        $differenceNumerator = $this->integerArithmetic->subtract(
            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
        );
        $differenceDenominator = $this->integerArithmetic->multiplyIntegerValues($right[1], $left[1]);

        return $this->integerArithmetic->compareIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues($differenceNumerator, $maximumWidth[1]),
            $this->integerArithmetic->multiplyIntegerValues($maximumWidth[0], $differenceDenominator),
        ) <= 0;
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function addFractions(array $left, array $right): ?array
    {
        $leftProduct = $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]);
        $rightProduct = $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]);
        $numerator = $this->integerArithmetic->addIntegerValues($leftProduct, $rightProduct);
        $denominator = $this->integerArithmetic->multiplyIntegerValues($left[1], $right[1]);

        return $this->reduceFraction([$numerator, $denominator]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function subtractFractions(array $left, array $right): ?array
    {
        return $this->addFractions($left, [$this->integerArithmetic->negateIntegerValue($right[0]), $right[1]]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function multiplyFractions(array $left, array $right): ?array
    {
        return $this->reduceFraction([
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[0]),
            $this->integerArithmetic->multiplyIntegerValues($left[1], $right[1]),
        ]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function divideFractions(array $left, array $right): ?array
    {
        if ($right[0] === '0') {
            return null;
        }

        return $this->reduceFraction([
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->integerArithmetic->multiplyIntegerValues($left[1], $right[0]),
        ]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     */
    private function compareFractions(array $left, array $right): int
    {
        return $this->integerArithmetic->compareIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
        );
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private function reduceFraction(array $fraction): ?array
    {
        [$numerator, $denominator] = $fraction;
        if ($denominator === '0') {
            return null;
        }
        if ($denominator[0] === '-') {
            $numerator = $this->integerArithmetic->negateIntegerValue($numerator);
            $denominator = \substr($denominator, 1);
        }
        if ($numerator === '0') {
            return ['0', '1'];
        }
        $gcd = $this->integerArithmetic->greatestCommonDivisorValues($numerator, $denominator);
        $numerator = $this->integerArithmetic->divideIntegerValueExactly($numerator, $gcd);
        $denominator = $this->integerArithmetic->divideIntegerValueExactly($denominator, $gcd);
        if ($numerator === null || $denominator === null) {
            return null;
        }
        $fraction = [$numerator, $denominator];

        return $this->withinLimits([$fraction]) ? $fraction : null;
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private function negateFraction(array $fraction): ?array
    {
        return $this->reduceFraction([
            $this->integerArithmetic->negateIntegerValue($fraction[0]),
            $fraction[1],
        ]);
    }

    /** @param list<array{string, string}> $polynomial
     *  @return list<array{string, string}>
     */
    private function trimFractions(array $polynomial): array
    {
        while (\count($polynomial) > 1 && $polynomial[\array_key_last($polynomial)][0] === '0') {
            \array_pop($polynomial);
        }

        return $polynomial === [] ? [['0', '1']] : $polynomial;
    }

    /** @param list<string> $polynomial
     *  @return list<string>
     */
    private function trim(array $polynomial): array
    {
        while (\count($polynomial) > 1 && $polynomial[\array_key_last($polynomial)] === '0') {
            \array_pop($polynomial);
        }

        return $polynomial;
    }

    /** @param list<array{string, string}> $polynomial */
    private function isZeroPolynomial(array $polynomial): bool
    {
        return \count($polynomial) === 1 && $polynomial[0][0] === '0';
    }

    /** @param list<array{string, string}> $polynomial */
    private function withinLimits(array $polynomial): bool
    {
        foreach ($polynomial as [$numerator, $denominator]) {
            if (
                \strlen(\ltrim($numerator, '-')) > self::MAX_INTEGER_DIGITS
                || \strlen($denominator) > self::MAX_INTEGER_DIGITS
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $coefficients */
    private function isZeroCoefficientList(array $coefficients): bool
    {
        foreach ($coefficients as $coefficient) {
            if ($coefficient !== '0') {
                return false;
            }
        }

        return true;
    }

    private function canonicalZero(string $integer): string
    {
        return $integer === '-0' ? '0' : $integer;
    }
}
