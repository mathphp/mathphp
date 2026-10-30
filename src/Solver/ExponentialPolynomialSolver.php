<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\RationalLiteral;

final class ExponentialPolynomialSolver
{
    /**
     * @param list<array{coefficient: float, slope: float, offset: float}> $terms
     * @return list<float>|null
     */
    public function solve(array $terms, float $lowerBound, float $upperBound, SolverOptions $options): ?array
    {
        if (
            is_nan($lowerBound)
            || is_nan($upperBound)
            || $lowerBound > $upperBound
            || $lowerBound === INF
            || $upperBound === -INF
        ) {
            return null;
        }
        /** @var list<array{coefficient: float, slope: float, offset: float}> $groupedTerms */
        $groupedTerms = [];
        $termIndexes = [];
        $termCompensations = [];
        foreach ($terms as $term) {
            if (
                !is_finite($term['coefficient'])
                || !is_finite($term['slope'])
                || !is_finite($term['offset'])
            ) {
                return null;
            }
            if ($term['coefficient'] === 0.0) {
                continue;
            }
            $slope = $term['slope'] == 0.0 ? 0.0 : $term['slope'];
            $offset = $term['offset'] == 0.0 ? 0.0 : $term['offset'];
            $key = bin2hex(pack('d', $slope)) . ':' . bin2hex(pack('d', $offset));
            if (isset($termIndexes[$key])) {
                $index = $termIndexes[$key];
                $existing = $groupedTerms[$index];
                $coefficient = $existing['coefficient'] + $term['coefficient'];
                if (!is_finite($coefficient)) {
                    return null;
                }
                $termCompensations[$index] += abs($existing['coefficient']) >= abs($term['coefficient'])
                    ? ($existing['coefficient'] - $coefficient) + $term['coefficient']
                    : ($term['coefficient'] - $coefficient) + $existing['coefficient'];
                if (!is_finite($termCompensations[$index])) {
                    return null;
                }
                $groupedTerms[$index] = [
                    'coefficient' => $coefficient,
                    'slope' => $existing['slope'],
                    'offset' => $existing['offset'],
                ];
            } else {
                $termIndexes[$key] = count($groupedTerms);
                $termCompensations[] = 0.0;
                $groupedTerms[] = [
                    'coefficient' => $term['coefficient'],
                    'slope' => $slope,
                    'offset' => $offset,
                ];
            }
        }
        $activeTerms = [];
        foreach ($groupedTerms as $index => $term) {
            $coefficient = $term['coefficient'] + $termCompensations[$index];
            if (!is_finite($coefficient)) {
                return null;
            }
            if ($coefficient !== 0.0) {
                $activeTerms[] = [
                    'coefficient' => $coefficient,
                    'slope' => $term['slope'],
                    'offset' => $term['offset'],
                ];
            }
        }
        if ($activeTerms === []) {
            if ($lowerBound === $upperBound) {
                return [$lowerBound];
            }
            throw new InfiniteSolutionSetException(
                'The exponential polynomial is identically zero.',
            );
        }
        if (count($activeTerms) === 1) {
            return [];
        }
        $terms = $activeTerms;
        if ($lowerBound === $upperBound && count($terms) > 2) {
            return $this->solveOnInterval($terms, $lowerBound, $upperBound, $options);
        }
        if (count($terms) === 2) {
            return $this->solveTwoTerms($terms, $lowerBound, $upperBound);
        }
        $slopeReduction = $this->aggregateBySlope($terms);
        if ($slopeReduction !== null) {
            if (count($slopeReduction) === 1) {
                return [];
            }
            $terms = $slopeReduction;
            if (count($terms) === 2) {
                return $this->solveTwoTerms($terms, $lowerBound, $upperBound);
            }
        }
        $commensurateRoots = $slopeReduction === null
            ? null
            : $this->solveCommensurateSlopes($terms, $lowerBound, $upperBound, $options);
        if ($commensurateRoots !== null) {
            return $commensurateRoots;
        }
        $minimumSlope = $terms[0]['slope'];
        $maximumSlope = $minimumSlope;
        foreach (array_slice($terms, 1) as $term) {
            $minimumSlope = min($minimumSlope, $term['slope']);
            $maximumSlope = max($maximumSlope, $term['slope']);
        }
        if (!is_finite($maximumSlope - $minimumSlope)) {
            return $this->solveWithScaledSlopes($terms, $lowerBound, $upperBound, $options);
        }
        if (!is_finite($lowerBound) || !is_finite($upperBound)) {
            $rootBounds = $this->rootBounds($terms);
            if ($rootBounds === null) {
                return null;
            }
            $lowerBound = max($lowerBound, $rootBounds[0]);
            $upperBound = min($upperBound, $rootBounds[1]);
            if ($lowerBound > $upperBound) {
                return [];
            }
            if ($lowerBound === $upperBound) {
                return [];
            }
        }

        $roots = [];
        /** @var list<array{float, float, int}> $pending */
        $pending = [[$lowerBound, $upperBound, 0]];
        $pendingIndex = 0;
        $processedIntervals = 0;
        $rootKeys = [];
        while ($pendingIndex < count($pending)) {
            if (++$processedIntervals > 16384) {
                return null;
            }
            [$intervalLower, $intervalUpper, $depth] = $pending[$pendingIndex++];
            $intervalRoots = $this->solveOnInterval($terms, $intervalLower, $intervalUpper, $options);
            if ($intervalRoots === null) {
                if ($this->hasConstantSign($terms, $intervalLower, $intervalUpper)) {
                    continue;
                }
                if ($depth >= 64) {
                    return null;
                }
                $midpoint = ($intervalLower / 2.0) + ($intervalUpper / 2.0);
                if ($midpoint === $intervalLower || $midpoint === $intervalUpper) {
                    return null;
                }
                $pending[] = [$midpoint, $intervalUpper, $depth + 1];
                $pending[] = [$intervalLower, $midpoint, $depth + 1];
                continue;
            }
            foreach ($intervalRoots as $root) {
                $root = $root == 0.0 ? 0.0 : $root;
                $rootKey = bin2hex(pack('d', $root));
                if (!isset($rootKeys[$rootKey])) {
                    $rootKeys[$rootKey] = true;
                    $roots[] = $root;
                }
            }
        }

        sort($roots, SORT_NUMERIC);

        return $roots;
    }

    /**
     * Reduce slopes on an exact binary-rational grid to a polynomial in exp(step*x).
     *
     * @param list<array{coefficient: float, slope: float, offset: float}> $terms
     * @return list<float>|null Null means the transformation is not safely representable.
     */
    private function solveCommensurateSlopes(
        array $terms,
        float $lowerBound,
        float $upperBound,
        SolverOptions $options,
    ): ?array {
        if (!is_finite($lowerBound) || !is_finite($upperBound)) {
            return null;
        }
        $slopes = array_values(array_unique(array_column($terms, 'slope')));
        sort($slopes, SORT_NUMERIC);
        if (count($slopes) < 3) {
            return null;
        }
        $minimumSlope = $slopes[0];
        $step = $this->integerSlopeStep($slopes);
        for ($index = 1, $count = count($slopes); $index < $count; ++$index) {
            $gap = $slopes[$index] - $minimumSlope;
            if (!is_finite($gap) || $gap <= 0.0) {
                return null;
            }
            if ($step === null) {
                $step = $gap;
            }
            $multiple = round($gap / $step);
            if (
                $multiple < 1.0
                || $multiple > $options->maxPolynomialDegree
                || $multiple * $step !== $gap
            ) {
                return null;
            }
        }
        if ($step === 0.0) {
            return null;
        }

        $degree = 0;
        foreach ($terms as $term) {
            $multiple = round(($term['slope'] - $minimumSlope) / $step);
            if (
                $multiple < 0.0
                || $multiple > $options->maxPolynomialDegree
                || $multiple * $step !== $term['slope'] - $minimumSlope
            ) {
                return null;
            }
            $degree = max($degree, (int) $multiple);
        }
        $polynomial = array_fill(0, $degree + 1, 0.0);
        $polynomialCompensations = array_fill(0, $degree + 1, 0.0);
        $verificationTerms = [];
        $logAmplitudes = [];
        $maximumLogAmplitude = -INF;
        foreach ($terms as $term) {
            $logAmplitude = log(abs($term['coefficient'])) + $term['offset'];
            if (!is_finite($logAmplitude)) {
                return null;
            }
            $logAmplitudes[] = $logAmplitude;
            $maximumLogAmplitude = max($maximumLogAmplitude, $logAmplitude);
        }
        $binaryScaleExponent = floor($maximumLogAmplitude / log(2.0));
        $useBinaryScale = $binaryScaleExponent >= -1074.0 && $binaryScaleExponent <= 1023.0;
        $binaryScaleLog = $useBinaryScale ? $binaryScaleExponent * log(2.0) : 0.0;
        foreach ($terms as $index => $term) {
            $multiple = (int) round(($term['slope'] - $minimumSlope) / $step);
            $amplitudeScaleLog = $useBinaryScale ? $binaryScaleLog : $maximumLogAmplitude;
            $amplitude = ($term['coefficient'] > 0.0 ? 1.0 : -1.0)
                * exp($logAmplitudes[$index] - $amplitudeScaleLog);
            if (!is_finite($amplitude) || $amplitude === 0.0) {
                return null;
            }
            $sum = $polynomial[$multiple];
            $next = $sum + $amplitude;
            $polynomialCompensations[$multiple] += abs($sum) >= abs($amplitude)
                ? ($sum - $next) + $amplitude
                : ($amplitude - $next) + $sum;
            $polynomial[$multiple] = $next;
            if (
                !is_finite($polynomial[$multiple])
                || !is_finite($polynomialCompensations[$multiple])
            ) {
                return null;
            }
            $verificationTerms[] = [
                'coefficient' => $amplitude,
                'slope' => $term['slope'] - $minimumSlope,
            ];
        }
        foreach ($polynomial as $power => $coefficient) {
            $polynomial[$power] += $polynomialCompensations[$power];
            if (!is_finite($polynomial[$power])) {
                return null;
            }
        }

        $scaledLower = $step * $lowerBound;
        $scaledUpper = $step * $upperBound;
        if (!is_finite($scaledLower) || !is_finite($scaledUpper)) {
            return null;
        }
        $transformedLower = exp(min($scaledLower, $scaledUpper));
        $transformedUpper = exp(max($scaledLower, $scaledUpper));
        if (
            !is_finite($transformedLower)
            || !is_finite($transformedUpper)
            || $transformedLower === 0.0
            || $transformedUpper === 0.0
        ) {
            return null;
        }

        if ($degree >= 3 && $degree <= 8) {
            $exactRoots = $this->exactPolynomialRootsInRange(
                $polynomial,
                $transformedLower,
                $transformedUpper,
            );
            if ($exactRoots !== null) {
                $roots = [];
                foreach ($exactRoots as $polynomialRoot) {
                    if ($polynomialRoot <= 0.0) {
                        continue;
                    }
                    $root = log($polynomialRoot) / $step;
                    if (!is_finite($root) || $root < $lowerBound || $root > $upperBound) {
                        continue;
                    }
                    $evaluation = $this->evaluateScaled($verificationTerms, $root);
                    if (
                        $evaluation === null
                        || abs($evaluation[0]) > $options->residualTolerance * $evaluation[1]
                    ) {
                        return null;
                    }
                    $roots[] = $root == 0.0 ? 0.0 : $root;
                }
                sort($roots, SORT_NUMERIC);

                return $roots;
            }
        }

        $polynomialSolver = new PolynomialSolver();
        $polynomialResidual = static function (float $value) use ($polynomial): ?float {
            $result = 0.0;
            for ($index = count($polynomial) - 1; $index >= 0; --$index) {
                $result = ($result * $value) + $polynomial[$index];
                if (!is_finite($result)) {
                    return null;
                }
            }

            return $result;
        };
        $polynomialScale = static function (float $value) use ($polynomial): ?float {
            $result = 0.0;
            for ($index = count($polynomial) - 1; $index >= 0; --$index) {
                $result = ($result * abs($value)) + abs($polynomial[$index]);
                if (!is_finite($result)) {
                    return null;
                }
            }

            return $result;
        };
        $polynomialRoots = $polynomialSolver->solve(
            array_values($polynomial),
            $transformedLower,
            $transformedUpper,
            $polynomialResidual,
            $options,
            $polynomialScale,
        );
        if ($polynomialRoots === null) {
            return null;
        }

        $roots = [];
        $rootKeys = [];
        foreach ($polynomialRoots as $polynomialRoot) {
            if ($polynomialRoot->value <= 0.0) {
                continue;
            }
            $root = log($polynomialRoot->value) / $step;
            if (!is_finite($root) || $root < $lowerBound || $root > $upperBound) {
                continue;
            }
            $evaluation = $this->evaluateScaled($verificationTerms, $root);
            if (
                $evaluation === null
                || abs($evaluation[0]) > $options->residualTolerance * $evaluation[1]
            ) {
                return null;
            }
            $root = $root == 0.0 ? 0.0 : $root;
            $rootKey = bin2hex(pack('d', $root));
            if (!isset($rootKeys[$rootKey])) {
                $rootKeys[$rootKey] = true;
                $roots[] = $root;
            }
        }
        sort($roots, SORT_NUMERIC);

        return $roots;
    }

    /**
     * @param list<float> $coefficients
     * @return list<float>|null
     */
    private function exactPolynomialRootsInRange(
        array $coefficients,
        float $lowerBound,
        float $upperBound,
    ): ?array {
        $lower = RationalLiteral::fromFloatExactAsStrings($lowerBound);
        $upper = RationalLiteral::fromFloatExactAsStrings($upperBound);
        if ($lower === null || $upper === null) {
            return null;
        }
        $integerArithmetic = new IntegerPolynomialExtractor('', [], 8);
        $fractions = [];
        $commonDenominator = '1';
        foreach ($coefficients as $coefficient) {
            $fraction = RationalLiteral::fromFloatExactAsStrings($coefficient);
            if ($fraction === null) {
                return null;
            }
            if (strlen($fraction[1]) > 12) {
                return null;
            }
            $fractions[] = $fraction;
            if ($integerArithmetic->compareIntegerValues($fraction[1], $commonDenominator) > 0) {
                $commonDenominator = $fraction[1];
            }
        }

        $integerCoefficients = [];
        foreach ($fractions as [$numerator, $denominator]) {
            $factor = $integerArithmetic->divideIntegerValueExactly($commonDenominator, $denominator);
            if ($factor === null) {
                return null;
            }
            $integerCoefficients[] = $integerArithmetic->multiplyIntegerValues($numerator, $factor);
        }
        $rootCounter = new ExactPolynomialRootCounter();
        $intervals = $rootCounter->isolateAllRealRoots(
            $integerCoefficients,
            256,
            ['1', '1000000000000'],
        );
        if ($intervals === null) {
            return null;
        }

        $roots = [];
        foreach ($intervals as [$rootLower, $rootLowerDenominator, $rootUpper, $rootUpperDenominator]) {
            if (
                $integerArithmetic->compareIntegerRatioToFraction(
                    $rootUpper,
                    $rootUpperDenominator,
                    $lower[0],
                    $lower[1],
                ) < 0
                || $integerArithmetic->compareIntegerRatioToFraction(
                    $rootLower,
                    $rootLowerDenominator,
                    $upper[0],
                    $upper[1],
                ) > 0
            ) {
                continue;
            }
            $lowerValue = $integerArithmetic->ratio($rootLower, $rootLowerDenominator);
            $upperValue = $integerArithmetic->ratio($rootUpper, $rootUpperDenominator);
            if (!is_float($lowerValue) || !is_float($upperValue)) {
                return null;
            }
            $root = ($lowerValue / 2.0) + ($upperValue / 2.0);
            if (!is_finite($root)) {
                return null;
            }
            $roots[] = $root;
        }

        return $roots;
    }

    /** @param list<float> $slopes */
    private function integerSlopeStep(array $slopes): ?float
    {
        for ($binaryScale = 0; $binaryScale <= 40; ++$binaryScale) {
            $scale = 2.0 ** $binaryScale;
            $integerSlopes = [];
            foreach ($slopes as $slope) {
                $scaledSlope = $slope * $scale;
                if (
                    !is_finite($scaledSlope)
                    || floor($scaledSlope) !== $scaledSlope
                    || abs($scaledSlope) > 1_000_000_000_000.0
                ) {
                    continue 2;
                }
                $integerSlopes[] = (int) $scaledSlope;
            }
            $minimum = $integerSlopes[0];
            $greatestCommonDivisor = 0;
            foreach (array_slice($integerSlopes, 1) as $slope) {
                $difference = $slope - $minimum;
                $greatestCommonDivisor = $this->integerGreatestCommonDivisor(
                    $greatestCommonDivisor,
                    $difference,
                );
            }
            if ($greatestCommonDivisor > 0) {
                return $greatestCommonDivisor / $scale;
            }
        }

        return null;
    }

    private function integerGreatestCommonDivisor(int $left, int $right): int
    {
        $left = abs($left);
        $right = abs($right);
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }

    /** @param list<array{coefficient: float, slope: float, offset: float}> $terms
     *  @return list<float>|null
     */
    private function solveWithScaledSlopes(
        array $terms,
        float $lowerBound,
        float $upperBound,
        SolverOptions $options,
    ): ?array {
        $slopeScale = 0.0;
        foreach ($terms as $term) {
            $slopeScale = max($slopeScale, abs($term['slope']));
        }
        if ($slopeScale === 0.0 || !is_finite($slopeScale)) {
            return null;
        }

        $scaledTerms = [];
        $scaledSlopeKeys = [];
        foreach ($terms as $term) {
            $scaledSlope = $term['slope'] / $slopeScale;
            if ($term['slope'] !== 0.0 && $scaledSlope === 0.0) {
                return null;
            }
            $slopeKey = bin2hex(pack('d', $scaledSlope));
            $originalSlopeKey = bin2hex(pack('d', $term['slope']));
            if (isset($scaledSlopeKeys[$slopeKey]) && $scaledSlopeKeys[$slopeKey] !== $originalSlopeKey) {
                return null;
            }
            $scaledSlopeKeys[$slopeKey] = $originalSlopeKey;
            $scaledTerms[] = [
                'coefficient' => $term['coefficient'],
                'slope' => $scaledSlope,
                'offset' => $term['offset'],
            ];
        }

        $scaledAbsoluteTolerance = $options->absoluteTolerance * $slopeScale;
        if (!is_finite($scaledAbsoluteTolerance)) {
            return null;
        }
        $scaledAbsoluteTolerance = max(PHP_FLOAT_MIN, $scaledAbsoluteTolerance);
        $scaledOptions = new SolverOptions(
            $options->scanSteps,
            $options->maxIterations,
            $scaledAbsoluteTolerance,
            $options->relativeTolerance,
            $options->residualTolerance,
            $options->maxPolynomialDegree,
        );
        $scaledLowerBound = $this->scaleCoordinateBound($lowerBound, $slopeScale);
        $scaledUpperBound = $this->scaleCoordinateBound($upperBound, $slopeScale);
        if (
            $scaledLowerBound === null
            || $scaledUpperBound === null
            || $scaledLowerBound > $scaledUpperBound
        ) {
            return null;
        }
        $scaledRootBounds = $this->rootBounds($scaledTerms);
        if (
            $scaledRootBounds === null
            && (!is_finite($scaledLowerBound) || !is_finite($scaledUpperBound))
        ) {
            return null;
        }
        if ($scaledRootBounds !== null) {
            $scaledLowerBound = max($scaledLowerBound, $scaledRootBounds[0]);
            $scaledUpperBound = min($scaledUpperBound, $scaledRootBounds[1]);
            if (
                $scaledLowerBound > $scaledUpperBound
                || (
                    $scaledLowerBound === $scaledUpperBound
                    && (
                        $lowerBound !== $upperBound
                        || $scaledLowerBound <= $scaledRootBounds[0]
                        || $scaledUpperBound >= $scaledRootBounds[1]
                    )
                )
            ) {
                return [];
            }
        }

        $scaledRoots = $this->solve(
            $scaledTerms,
            $scaledLowerBound,
            $scaledUpperBound,
            $scaledOptions,
        );
        if ($scaledRoots === null) {
            return null;
        }

        $roots = [];
        $rootKeys = [];
        foreach ($scaledRoots as $scaledRoot) {
            $root = $scaledRoot / $slopeScale;
            if (!is_finite($root) || $root < $lowerBound || $root > $upperBound) {
                continue;
            }
            $root = $root == 0.0 ? 0.0 : $root;
            $rootKey = bin2hex(pack('d', $root));
            if (!isset($rootKeys[$rootKey])) {
                $rootKeys[$rootKey] = true;
                $roots[] = $root;
            }
        }
        sort($roots, SORT_NUMERIC);

        return $roots;
    }

    private function scaleCoordinateBound(float $bound, float $scale): ?float
    {
        if (!is_finite($bound) || $bound === 0.0) {
            return $bound;
        }

        $scaledBound = $bound * $scale;

        return is_finite($scaledBound) ? $scaledBound : null;
    }

    /**
     * Reduce each slope group by summing signed amplitudes in log space.
     *
     * @param list<array{coefficient: float, slope: float, offset: float}> $terms
     * @return list<array{coefficient: float, slope: float, offset: float}>|null
     */
    private function aggregateBySlope(array $terms): ?array
    {
        $groups = [];
        foreach ($terms as $term) {
            $slope = $term['slope'] == 0.0 ? 0.0 : $term['slope'];
            $key = bin2hex(pack('d', $slope));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'slope' => $slope,
                    'terms' => [],
                ];
            }
            $logMagnitude = log(abs($term['coefficient'])) + $term['offset'];
            if (!is_finite($logMagnitude)) {
                return null;
            }
            $groups[$key]['terms'][] = [
                'sign' => $term['coefficient'] > 0.0 ? 1.0 : -1.0,
                'logMagnitude' => $logMagnitude,
            ];
        }
        $reduced = [];
        foreach ($groups as $group) {
            $maximumLogMagnitude = -INF;
            foreach ($group['terms'] as $term) {
                $maximumLogMagnitude = max($maximumLogMagnitude, $term['logMagnitude']);
            }
            $sum = 0.0;
            $correction = 0.0;
            $magnitudeSum = 0.0;
            foreach ($group['terms'] as $term) {
                $magnitude = exp($term['logMagnitude'] - $maximumLogMagnitude);
                $value = $term['sign'] * $magnitude;
                $next = $sum + $value;
                $correction += abs($sum) >= abs($value)
                    ? ($sum - $next) + $value
                    : ($value - $next) + $sum;
                $sum = $next;
                $magnitudeSum += $magnitude;
            }
            $amplitude = $sum + $correction;
            if (
                !is_finite($amplitude)
                || !is_finite($magnitudeSum)
                || abs($amplitude) <= (32.0 + (4.0 * count($group['terms'])))
                    * PHP_FLOAT_EPSILON * $magnitudeSum
            ) {
                return null;
            }
            $reduced[] = [
                'coefficient' => $amplitude,
                'slope' => $group['slope'],
                'offset' => $maximumLogMagnitude,
            ];
        }

        return $reduced;
    }

    /**
     * Bound all real roots using dominance of the extreme-slope terms.
     *
     * @param list<array{coefficient: float, slope: float, offset: float}> $terms
     * @return array{float, float}|null
     */
    public function rootBounds(array $terms): ?array
    {
        if (count($terms) < 2) {
            return null;
        }
        usort($terms, static fn (array $left, array $right): int => $left['slope'] <=> $right['slope']);
        $count = count($terms);
        $minimum = $terms[0];
        $maximum = $terms[$count - 1];
        $minimumLogAmplitude = log(abs($minimum['coefficient'])) + $minimum['offset'];
        $maximumLogAmplitude = log(abs($maximum['coefficient'])) + $maximum['offset'];
        if (!is_finite($minimumLogAmplitude) || !is_finite($maximumLogAmplitude)) {
            return null;
        }
        $margin = log(2.0 * ($count - 1));
        $lower = 0.0;
        $upper = 0.0;
        foreach (array_slice($terms, 1) as $term) {
            $slopeGap = $term['slope'] - $minimum['slope'];
            $termLogAmplitude = log(abs($term['coefficient'])) + $term['offset'];
            if ($slopeGap <= 0.0 || !is_finite($slopeGap) || !is_finite($termLogAmplitude)) {
                return null;
            }
            $uncertainty = $this->logMagnitudeUncertainty(
                [$term, $minimum],
                $termLogAmplitude,
                $minimumLogAmplitude,
            );
            $candidate = $this->scaledSumRatio(
                [$minimumLogAmplitude, -$termLogAmplitude, -$margin, -$uncertainty],
                $slopeGap,
            );
            if ($candidate === null) {
                return null;
            }
            $lower = min($lower, $candidate);
        }
        foreach (array_slice($terms, 0, -1) as $term) {
            $slopeGap = $maximum['slope'] - $term['slope'];
            $termLogAmplitude = log(abs($term['coefficient'])) + $term['offset'];
            if ($slopeGap <= 0.0 || !is_finite($slopeGap) || !is_finite($termLogAmplitude)) {
                return null;
            }
            $uncertainty = $this->logMagnitudeUncertainty(
                [$term, $maximum],
                $termLogAmplitude,
                $maximumLogAmplitude,
            );
            $candidate = $this->scaledSumRatio(
                [$termLogAmplitude, -$maximumLogAmplitude, $margin, $uncertainty],
                $slopeGap,
            );
            if ($candidate === null) {
                return null;
            }
            $upper = max($upper, $candidate);
        }
        $lower -= 32.0 * PHP_FLOAT_EPSILON * max(1.0, abs($lower));
        $upper += 32.0 * PHP_FLOAT_EPSILON * max(1.0, abs($upper));
        if (!is_finite($lower) || !is_finite($upper)) {
            return null;
        }

        return [$lower, $upper];
    }

    /** @param list<float> $numeratorTerms */
    private function scaledSumRatio(array $numeratorTerms, float $denominator): ?float
    {
        if ($denominator === 0.0 || !is_finite($denominator)) {
            return null;
        }
        $sum = 0.0;
        $correction = 0.0;
        $requiresScaling = false;
        foreach ($numeratorTerms as $term) {
            if (!is_finite($term)) {
                return null;
            }
            $next = $sum + $term;
            if (!is_finite($next)) {
                $requiresScaling = true;
                break;
            }
            $correction += abs($sum) >= abs($term)
                ? ($sum - $next) + $term
                : ($term - $next) + $sum;
            if (!is_finite($correction)) {
                $requiresScaling = true;
                break;
            }
            $sum = $next;
        }
        if (!$requiresScaling) {
            $numerator = $sum + $correction;
            if (!is_finite($numerator)) {
                $requiresScaling = true;
            } else {
                $ratio = $numerator / $denominator;

                return is_finite($ratio) ? $ratio : null;
            }
        }

        $scale = abs($denominator);
        foreach ($numeratorTerms as $term) {
            $scale = max($scale, abs($term));
        }
        if ($scale === 0.0 || !is_finite($scale)) {
            return null;
        }
        $scaledDenominator = $denominator / $scale;
        if ($scaledDenominator === 0.0) {
            return null;
        }
        $sum = 0.0;
        $correction = 0.0;
        foreach ($numeratorTerms as $term) {
            $scaledTerm = $term / $scale;
            $next = $sum + $scaledTerm;
            $correction += abs($sum) >= abs($scaledTerm)
                ? ($sum - $next) + $scaledTerm
                : ($scaledTerm - $next) + $sum;
            $sum = $next;
        }
        $ratio = ($sum + $correction) / $scaledDenominator;

        return is_finite($ratio) ? $ratio : null;
    }

    /** @param list<array{coefficient: float, slope: float, offset: float}> $terms
     *  @return list<float>|null
     */
    private function solveTwoTerms(array $terms, float $lowerBound, float $upperBound): ?array
    {
        [$first, $second] = $terms;
        if (($first['coefficient'] > 0.0) === ($second['coefficient'] > 0.0)) {
            return [];
        }
        $firstLogMagnitude = log(abs($first['coefficient'])) + $first['offset'];
        $secondLogMagnitude = log(abs($second['coefficient'])) + $second['offset'];
        if (!is_finite($firstLogMagnitude) || !is_finite($secondLogMagnitude)) {
            return null;
        }
        if ($first['slope'] === $second['slope']) {
            if (
                abs($first['coefficient']) === abs($second['coefficient'])
                && $first['offset'] === $second['offset']
            ) {
                throw new InfiniteSolutionSetException(
                    'Opposite exponential terms with identical exponents cancel identically.',
                );
            }
            $logMagnitudeDifference = $secondLogMagnitude - $firstLogMagnitude;
            $uncertainty = $this->logMagnitudeUncertainty(
                $terms,
                $firstLogMagnitude,
                $secondLogMagnitude,
            );

            return abs($logMagnitudeDifference) > $uncertainty ? [] : null;
        }
        $root = $this->differenceRatio(
            $firstLogMagnitude,
            $secondLogMagnitude,
            $first['slope'],
            $second['slope'],
        );
        if ($root === null) {
            return null;
        }

        return $root >= $lowerBound && $root <= $upperBound ? [$root] : [];
    }

    private function differenceRatio(
        float $numeratorLeft,
        float $numeratorRight,
        float $denominatorLeft,
        float $denominatorRight,
    ): ?float {
        $numerator = $numeratorRight - $numeratorLeft;
        $denominator = $denominatorLeft - $denominatorRight;
        if (is_finite($numerator) && is_finite($denominator)) {
            if ($denominator === 0.0) {
                return null;
            }
            $ratio = $numerator / $denominator;

            return is_finite($ratio) ? $ratio : null;
        }

        $numeratorDifference = $this->signedLogDifference($numeratorLeft, $numeratorRight);
        $denominatorDifference = $this->signedLogDifference($denominatorRight, $denominatorLeft);
        if ($numeratorDifference === null || $denominatorDifference === null) {
            return null;
        }
        if ($numeratorDifference[0] === 0) {
            return 0.0;
        }
        if ($denominatorDifference[0] === 0) {
            return null;
        }
        $logRatio = $numeratorDifference[1] - $denominatorDifference[1];
        if (!is_finite($logRatio) || $logRatio > log(PHP_FLOAT_MAX)) {
            return null;
        }
        $magnitude = exp($logRatio);

        return ($numeratorDifference[0] === $denominatorDifference[0] ? 1.0 : -1.0) * $magnitude;
    }

    /** @return array{int, float}|null Sign and log magnitude of right - left. */
    private function signedLogDifference(float $left, float $right): ?array
    {
        $difference = $right - $left;
        if (is_finite($difference) && $difference !== 0.0) {
            return [$difference > 0.0 ? 1 : -1, log(abs($difference))];
        }
        if ($difference === 0.0 && $left === $right) {
            return [0, -INF];
        }

        $scale = max(abs($left), abs($right));
        if ($scale === 0.0 || !is_finite($scale)) {
            return null;
        }
        $scaledDifference = ($right / $scale) - ($left / $scale);
        if ($scaledDifference === 0.0 || !is_finite($scaledDifference)) {
            return null;
        }
        $logMagnitude = log(abs($scaledDifference)) + log($scale);
        if (!is_finite($logMagnitude)) {
            return null;
        }

        return [$scaledDifference > 0.0 ? 1 : -1, $logMagnitude];
    }

    /** @param list<array{coefficient: float, slope: float, offset: float}> $terms */
    private function hasConstantSign(array $terms, float $lowerBound, float $upperBound): bool
    {
        $positiveLower = [];
        $positiveUpper = [];
        $negativeLower = [];
        $negativeUpper = [];
        foreach ($terms as $term) {
            if ($term['coefficient'] === 0.0) {
                continue;
            }
            $atLower = $this->logWeight(
                $term['coefficient'],
                $term['slope'],
                $term['offset'],
                $lowerBound,
            );
            $atUpper = $this->logWeight(
                $term['coefficient'],
                $term['slope'],
                $term['offset'],
                $upperBound,
            );
            if ($atLower === null || $atUpper === null) {
                return false;
            }
            $lower = min($atLower, $atUpper);
            $upper = max($atLower, $atUpper);
            if ($term['coefficient'] > 0.0) {
                $positiveLower[] = $lower;
                $positiveUpper[] = $upper;
            } else {
                $negativeLower[] = $lower;
                $negativeUpper[] = $upper;
            }
        }
        if ($positiveLower === [] && $negativeLower === []) {
            return false;
        }
        if ($positiveLower === []) {
            return true;
        }
        if ($negativeLower === []) {
            return true;
        }

        $positiveMinimum = $this->logSumExp($positiveLower);
        $positiveMaximum = $this->logSumExp($positiveUpper);
        $negativeMinimum = $this->logSumExp($negativeLower);
        $negativeMaximum = $this->logSumExp($negativeUpper);
        $margin = 32.0 * PHP_FLOAT_EPSILON * max(
            1.0,
            abs($positiveMinimum),
            abs($positiveMaximum),
            abs($negativeMinimum),
            abs($negativeMaximum),
        );

        return $positiveMinimum > $negativeMaximum + $margin
            || $negativeMinimum > $positiveMaximum + $margin;
    }

    /** @param list<float> $values */
    private function logSumExp(array $values): float
    {
        if ($values === []) {
            return -INF;
        }
        $maximum = max($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += exp($value - $maximum);
        }

        return $maximum + log($sum);
    }

    /** @param list<array{coefficient: float, slope: float, offset: float}> $terms */
    private function logMagnitudeUncertainty(array $terms, float ...$logMagnitudes): float
    {
        $scale = 1.0;
        foreach ($logMagnitudes as $logMagnitude) {
            $scale = max($scale, abs($logMagnitude));
        }
        foreach ($terms as $term) {
            $scale = max(
                $scale,
                abs(log(abs($term['coefficient']))),
                abs($term['offset']),
            );
        }

        return (32.0 + (4.0 * count($terms))) * PHP_FLOAT_EPSILON * $scale;
    }

    /** @param list<array{coefficient: float, slope: float, offset: float}> $terms
     *  @return list<float>|null
     */
    private function solveOnInterval(
        array $terms,
        float $lowerBound,
        float $upperBound,
        SolverOptions $options,
    ): ?array {
        $center = ($lowerBound / 2.0) + ($upperBound / 2.0);
        $maximumLogMagnitude = null;
        foreach ($terms as $term) {
            if (
                !is_finite($term['coefficient'])
                || !is_finite($term['slope'])
                || !is_finite($term['offset'])
            ) {
                return null;
            }
            if ($term['coefficient'] !== 0.0) {
                $logMagnitude = $this->logWeight(
                    $term['coefficient'],
                    $term['slope'],
                    $term['offset'],
                    $center,
                );
                if ($logMagnitude === null) {
                    return null;
                }
                $maximumLogMagnitude = $maximumLogMagnitude === null
                    ? $logMagnitude
                    : max($maximumLogMagnitude, $logMagnitude);
            }
        }
        if ($maximumLogMagnitude === null) {
            return null;
        }

        $scaledTerms = [];
        foreach ($terms as $term) {
            if ($term['coefficient'] === 0.0) {
                continue;
            }
            $logMagnitude = $this->logWeight(
                $term['coefficient'],
                $term['slope'],
                $term['offset'],
                $center,
            );
            if ($logMagnitude === null) {
                return null;
            }
            $coefficient = ($term['coefficient'] < 0.0 ? -1.0 : 1.0)
                * exp($logMagnitude - $maximumLogMagnitude);
            if ($coefficient === 0.0 || !is_finite($coefficient)) {
                return null;
            }
            $scaledTerms[] = ['coefficient' => $coefficient, 'slope' => $term['slope']];
        }

        $shiftedLowerBound = $lowerBound - $center;
        $shiftedUpperBound = $upperBound - $center;
        if (!is_finite($shiftedLowerBound) || !is_finite($shiftedUpperBound)) {
            return null;
        }
        $roots = $this->solveRecursive(
            $this->combineEqualSlopes($scaledTerms),
            $shiftedLowerBound,
            $shiftedUpperBound,
            $options,
        );
        if ($roots === null) {
            return null;
        }

        return array_map(static fn (float $root): float => $root + $center, $roots);
    }

    /**
     * @param list<array{coefficient: float, slope: float}> $terms
     * @return list<float>|null
     */
    private function solveRecursive(array $terms, float $lowerBound, float $upperBound, SolverOptions $options): ?array
    {
        $terms = $this->combineEqualSlopes($terms);
        if ($terms === []) {
            return null;
        }
        if (\count($terms) === 1) {
            return [];
        }
        if (\count($terms) === 2) {
            if (($terms[0]['coefficient'] > 0.0) === ($terms[1]['coefficient'] > 0.0)) {
                return [];
            }
            $root = $this->differenceRatio(
                \log(\abs($terms[1]['coefficient'])),
                \log(\abs($terms[0]['coefficient'])),
                $terms[1]['slope'],
                $terms[0]['slope'],
            );

            return $root !== null && $root >= $lowerBound && $root <= $upperBound
                ? [$root]
                : [];
        }

        $minimumSlope = $terms[0]['slope'];
        $derivativeLogs = [];
        $maximumDerivativeLog = -\INF;
        foreach (\array_slice($terms, 1) as $term) {
            $slopeDifference = $term['slope'] - $minimumSlope;
            if ($slopeDifference <= 0.0 || !\is_finite($slopeDifference)) {
                return null;
            }
            $logMagnitude = \log(\abs($term['coefficient'])) + \log($slopeDifference);
            if (!\is_finite($logMagnitude)) {
                return null;
            }
            $derivativeLogs[] = [
                'sign' => $term['coefficient'] > 0.0 ? 1.0 : -1.0,
                'logMagnitude' => $logMagnitude,
                'slope' => $slopeDifference,
            ];
            $maximumDerivativeLog = \max($maximumDerivativeLog, $logMagnitude);
        }
        $derivativeTerms = [];
        foreach ($derivativeLogs as $term) {
            $coefficient = $term['sign'] * \exp($term['logMagnitude'] - $maximumDerivativeLog);
            if ($coefficient === 0.0) {
                return null;
            }
            $derivativeTerms[] = [
                'coefficient' => $coefficient,
                'slope' => $term['slope'],
            ];
        }
        $criticalPoints = $this->solveRecursive(
            $derivativeTerms,
            $lowerBound,
            $upperBound,
            $options,
        );
        if ($criticalPoints === null) {
            return null;
        }

        $criticalPoints = \array_values(\array_filter(
            $criticalPoints,
            static fn (float $point): bool => $point > $lowerBound && $point < $upperBound,
        ));
        $points = [$lowerBound, ...$criticalPoints, $upperBound];
        $roots = [];
        foreach ($points as $point) {
            $evaluation = $this->evaluateScaled($terms, $point);
            if ($evaluation === null) {
                return null;
            }
            if ($this->isNumericalZero($evaluation[0], $evaluation[1])) {
                $roots[] = $point;
            }
        }

        for ($index = 0, $last = \count($points) - 1; $index < $last; ++$index) {
            $left = $points[$index];
            $right = $points[$index + 1];
            $leftEvaluation = $this->evaluateScaled($terms, $left);
            $rightEvaluation = $this->evaluateScaled($terms, $right);
            if ($leftEvaluation === null || $rightEvaluation === null) {
                return null;
            }
            if (
                $this->isNumericalZero($leftEvaluation[0], $leftEvaluation[1])
                || $this->isNumericalZero($rightEvaluation[0], $rightEvaluation[1])
            ) {
                continue;
            }
            if (($leftEvaluation[0] > 0.0) === ($rightEvaluation[0] > 0.0)) {
                continue;
            }
            $root = $this->bisect($terms, $left, $right, $leftEvaluation[0], $options);
            if ($root === null) {
                return null;
            }
            $roots[] = $root;
        }

        \sort($roots, SORT_NUMERIC);
        $distinctRoots = [];
        $rootKeys = [];
        foreach ($roots as $root) {
            $root = $root == 0.0 ? 0.0 : $root;
            $rootKey = \bin2hex(\pack('d', $root));
            if (!isset($rootKeys[$rootKey])) {
                $rootKeys[$rootKey] = true;
                $distinctRoots[] = $root;
            }
        }

        return $distinctRoots;
    }

    /**
     * @param list<array{coefficient: float, slope: float}> $terms
     * @return list<array{coefficient: float, slope: float}>
     */
    private function combineEqualSlopes(array $terms): array
    {
        \usort(
            $terms,
            static fn (array $first, array $second): int => $first['slope'] <=> $second['slope'],
        );
        $combined = [];
        $currentSlope = null;
        $sum = 0.0;
        $correction = 0.0;
        foreach ($terms as $term) {
            if ($currentSlope !== null && $currentSlope !== $term['slope']) {
                $coefficient = $sum + $correction;
                if (!\is_finite($coefficient)) {
                    return [];
                }
                if ($coefficient !== 0.0) {
                    $combined[] = ['coefficient' => $coefficient, 'slope' => $currentSlope];
                }
                $currentSlope = null;
                $sum = 0.0;
                $correction = 0.0;
            }
            if ($currentSlope === null) {
                $currentSlope = $term['slope'];
            }
            $next = $sum + $term['coefficient'];
            if (!\is_finite($next)) {
                return [];
            }
            $correction += \abs($sum) >= \abs($term['coefficient'])
                ? ($sum - $next) + $term['coefficient']
                : ($term['coefficient'] - $next) + $sum;
            if (!\is_finite($correction)) {
                return [];
            }
            $sum = $next;
        }
        if ($currentSlope !== null) {
            $coefficient = $sum + $correction;
            if (!\is_finite($coefficient)) {
                return [];
            }
            if ($coefficient !== 0.0) {
                $combined[] = ['coefficient' => $coefficient, 'slope' => $currentSlope];
            }
        }

        return $combined;
    }

    /**
     * @param list<array{coefficient: float, slope: float}> $terms
     * @return array{float, float}|null
     */
    private function evaluateScaled(array $terms, float $position): ?array
    {
        $logWeights = [];
        $maximumLogWeight = -\INF;
        foreach ($terms as $term) {
            $logWeight = $this->logWeight($term['coefficient'], $term['slope'], 0.0, $position);
            if ($logWeight === null) {
                return null;
            }
            $logWeights[] = $logWeight;
            $maximumLogWeight = \max($maximumLogWeight, $logWeight);
        }

        $sum = 0.0;
        $correction = 0.0;
        $scale = 0.0;
        foreach ($terms as $index => $term) {
            $magnitude = \exp($logWeights[$index] - $maximumLogWeight);
            $signedValue = ($term['coefficient'] > 0.0 ? 1.0 : -1.0) * $magnitude;
            $adjusted = $signedValue - $correction;
            $next = $sum + $adjusted;
            $correction = ($next - $sum) - $adjusted;
            $sum = $next;
            $scale += $magnitude;
        }

        return \is_finite($sum) && \is_finite($scale) ? [$sum, $scale] : null;
    }

    private function logWeight(float $coefficient, float $slope, float $offset, float $position): ?float
    {
        $coefficientLog = \log(\abs($coefficient));
        if (!\is_finite($coefficientLog)) {
            return null;
        }
        $product = $slope * $position;
        if (\is_finite($product)) {
            $sum = $coefficientLog;
            $correction = 0.0;
            $requiresScaling = false;
            foreach ([$offset, $product] as $value) {
                $next = $sum + $value;
                if (!\is_finite($next)) {
                    $requiresScaling = true;
                    break;
                }
                $correction += \abs($sum) >= \abs($value)
                    ? ($sum - $next) + $value
                    : ($value - $next) + $sum;
                if (!\is_finite($correction)) {
                    $requiresScaling = true;
                    break;
                }
                $sum = $next;
            }
            if (!$requiresScaling) {
                $result = $sum + $correction;
                if (\is_finite($result)) {
                    return $result;
                }
            }
        }

        $parts = [];
        if ($coefficientLog !== 0.0) {
            $parts[] = [
                'sign' => $coefficientLog > 0.0 ? 1.0 : -1.0,
                'logMagnitude' => \log(\abs($coefficientLog)),
            ];
        }
        if ($offset !== 0.0) {
            $parts[] = [
                'sign' => $offset > 0.0 ? 1.0 : -1.0,
                'logMagnitude' => \log(\abs($offset)),
            ];
        }
        if ($slope !== 0.0 && $position !== 0.0) {
            $productLogMagnitude = \log(\abs($slope)) + \log(\abs($position));
            if (!\is_finite($productLogMagnitude)) {
                return null;
            }
            $parts[] = [
                'sign' => ($slope > 0.0) === ($position > 0.0) ? 1.0 : -1.0,
                'logMagnitude' => $productLogMagnitude,
            ];
        }
        if ($parts === []) {
            return 0.0;
        }

        $maximumLogMagnitude = \max(\array_column($parts, 'logMagnitude'));
        $sum = 0.0;
        $correction = 0.0;
        foreach ($parts as $part) {
            $value = $part['sign'] * \exp($part['logMagnitude'] - $maximumLogMagnitude);
            $adjusted = $value - $correction;
            $next = $sum + $adjusted;
            $correction = ($next - $sum) - $adjusted;
            $sum = $next;
        }
        if ($sum === 0.0) {
            return 0.0;
        }

        $logMagnitude = $maximumLogMagnitude + \log(\abs($sum));
        if (!\is_finite($logMagnitude) || $logMagnitude > \log(\PHP_FLOAT_MAX)) {
            return null;
        }
        if ($logMagnitude < \log(\PHP_FLOAT_MIN) - (52.0 * \log(2.0))) {
            return 0.0;
        }

        return ($sum > 0.0 ? 1.0 : -1.0) * \exp($logMagnitude);
    }

    private function isNumericalZero(float $value, float $scale): bool
    {
        return \abs($value) <= 0.5 * \PHP_FLOAT_EPSILON * $scale;
    }

    /** @param list<array{coefficient: float, slope: float}> $terms */
    private function bisect(
        array $terms,
        float $left,
        float $right,
        float $leftValue,
        SolverOptions $options,
    ): ?float {
        for ($iteration = 0; $iteration < $options->maxIterations; ++$iteration) {
            $middle = ($left / 2.0) + ($right / 2.0);
            if ($middle === $left || $middle === $right) {
                return ($left / 2.0) + ($right / 2.0);
            }
            $width = $right - $left;
            if (
                is_finite($width)
                && $width <= $options->positionTolerance($left, $right)
            ) {
                return $middle;
            }
            $evaluation = $this->evaluateScaled($terms, $middle);
            if ($evaluation === null) {
                return null;
            }
            if ($evaluation[0] === 0.0) {
                return $middle;
            }
            if (($evaluation[0] > 0.0) === ($leftValue > 0.0)) {
                $left = $middle;
                $leftValue = $evaluation[0];
            } else {
                $right = $middle;
            }
        }

        return ($left / 2.0) + ($right / 2.0);
    }
}
