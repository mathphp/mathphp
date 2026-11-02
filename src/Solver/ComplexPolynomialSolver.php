<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\RationalLiteral;

final class ComplexPolynomialSolver
{
    /**
     * Re-evaluate factor-derived roots against the complete polynomial.
     *
     * @param list<int|float> $coefficients Coefficients in ascending degree order.
     */
    public function recalculateResiduals(
        array $coefficients,
        ComplexPolynomialSolution $solution,
        SolverOptions $options,
    ): ComplexPolynomialSolution {
        if ($coefficients === []) {
            throw new \InvalidArgumentException('A polynomial must contain at least one coefficient.');
        }
        $coefficientScale = 0.0;
        foreach ($coefficients as $coefficient) {
            if (!\is_finite($coefficient)) {
                throw new \DomainException('Polynomial coefficients must be finite.');
            }
            $coefficientScale = \max($coefficientScale, \abs($coefficient));
        }
        if ($coefficientScale === 0.0) {
            return $solution;
        }
        $roots = [];
        $converged = $solution->converged;
        foreach ($solution->roots as $root) {
            $residual = $this->normalizedResidual($coefficients, [
                'real' => $root->real,
                'imaginary' => $root->imaginary,
            ], true);
            if (!\is_finite($residual)) {
                throw new \DomainException('Complex polynomial residual is not finite.');
            }
            $roots[] = new ComplexRoot($root->real, $root->imaginary, $residual);
            $converged = $converged && $residual <= $options->residualTolerance;
        }

        return new ComplexPolynomialSolution(
            $roots,
            $solution->degree,
            $converged,
            $solution->iterations,
        );
    }

    /**
     * @param array<array-key, mixed> $coefficients Coefficients in ascending degree order.
     * @param array<array-key, mixed>|null $exactCoefficients Exact integer coefficients, when available.
     */
    public function solve(
        array $coefficients,
        SolverOptions $options,
        ?array $exactCoefficients = null,
    ): ComplexPolynomialSolution {
        if ($coefficients === []) {
            throw new \InvalidArgumentException('A polynomial must contain at least one coefficient.');
        }
        if (!\array_is_list($coefficients)) {
            throw new \InvalidArgumentException('Polynomial coefficients must be a list.');
        }
        $validatedCoefficients = [];
        foreach ($coefficients as $coefficient) {
            if (!\is_int($coefficient) && !\is_float($coefficient)) {
                throw new \InvalidArgumentException('Polynomial coefficients must be real numbers.');
            }
            $coefficient = (float) $coefficient;
            if (!\is_finite($coefficient)) {
                throw new \DomainException('Polynomial coefficients must be finite.');
            }
            $validatedCoefficients[] = $coefficient;
        }
        $coefficients = $validatedCoefficients;
        if ($exactCoefficients !== null) {
            if (!\array_is_list($exactCoefficients)) {
                throw new \InvalidArgumentException('Exact polynomial coefficients must be a list.');
            }
            foreach ($exactCoefficients as $index => $coefficient) {
                if (
                    !\is_string($coefficient)
                    || \preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $coefficient) !== 1
                ) {
                    throw new \InvalidArgumentException(
                        'Exact polynomial coefficients must be canonical decimal integers.',
                    );
                }
                if ($coefficient === '-0') {
                    $exactCoefficients[$index] = '0';
                }
            }
            if (\count($exactCoefficients) !== \count($coefficients)) {
                $exactCoefficients = null;
            }
        }
        if (
            $exactCoefficients !== null
            && \count($exactCoefficients) === \count($coefficients)
        ) {
            $hasExactCoefficientUnderflow = false;
            foreach ($coefficients as $power => $coefficient) {
                if ($coefficient === 0.0 && $exactCoefficients[$power] !== '0') {
                    $hasExactCoefficientUnderflow = true;
                    break;
                }
            }
            if ($hasExactCoefficientUnderflow) {
                $nonzeroExactPowers = [];
                foreach ($exactCoefficients as $power => $coefficient) {
                    if ($coefficient !== '0') {
                        $nonzeroExactPowers[] = $power;
                    }
                }
                if (\count($nonzeroExactPowers) === 1) {
                    $degree = $nonzeroExactPowers[0];
                    $roots = [];
                    for ($multiplicity = 0; $multiplicity < $degree; ++$multiplicity) {
                        $roots[] = new ComplexRoot(0.0, 0.0, 0.0);
                    }

                    return new ComplexPolynomialSolution($roots, $degree, true, 0);
                }
                $scaledSolution = $this->solveWithExactVariableScaling($exactCoefficients, $options);
                if ($scaledSolution !== null) {
                    return $scaledSolution;
                }
                throw new \DomainException(
                    'Exact polynomial coefficients cannot be represented at a finite variable scale.',
                );
            }
        }
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === 0.0) {
            \array_pop($coefficients);
            if ($exactCoefficients !== null) {
                \array_pop($exactCoefficients);
            }
        }
        if ($exactCoefficients !== null && \count($exactCoefficients) !== \count($coefficients)) {
            $exactCoefficients = null;
        }

        $originalCoefficients = $coefficients;
        $originalDegree = \count($coefficients) - 1;
        $zeroRootMultiplicity = 0;
        while (\count($coefficients) > 1 && $coefficients[0] === 0.0) {
            \array_shift($coefficients);
            if ($exactCoefficients !== null) {
                \array_shift($exactCoefficients);
            }
            ++$zeroRootMultiplicity;
        }
        if ($zeroRootMultiplicity > 0) {
            $reduced = $this->solve($coefficients, $options, $exactCoefficients);
            $coefficientScale = 0.0;
            foreach ($originalCoefficients as $coefficient) {
                $coefficientScale = \max($coefficientScale, \abs($coefficient));
            }
            $roots = [];
            $converged = $reduced->converged;
            foreach ($reduced->roots as $root) {
                $residual = $this->normalizedResidual($originalCoefficients, [
                    'real' => $root->real,
                    'imaginary' => $root->imaginary,
                ], true);
                if (!\is_finite($residual)) {
                    throw new \DomainException('Complex polynomial residual is not finite.');
                }
                $roots[] = new ComplexRoot($root->real, $root->imaginary, $residual);
                $converged = $converged && $residual <= $options->residualTolerance;
            }
            for ($index = 0; $index < $zeroRootMultiplicity; ++$index) {
                $roots[] = new ComplexRoot(0.0, 0.0, 0.0);
            }
            \usort(
                $roots,
                static fn (ComplexRoot $left, ComplexRoot $right): int =>
                    [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
            );

            return new ComplexPolynomialSolution(
                $roots,
                $originalDegree,
                $converged,
                $reduced->iterations,
            );
        }

        $degree = \count($coefficients) - 1;
        if ($degree < 0) {
            throw new \LogicException('Polynomial trimming removed every coefficient.');
        }
        if ($degree === 0) {
            if ($coefficients[0] === 0.0) {
                throw new InfiniteSolutionSetException(
                    'The zero polynomial has infinitely many complex roots.',
                );
            }

            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        if ($degree > 1) {
            $sparseRoots = $this->solveSparsePolynomial($coefficients, $degree, $options);
            if ($sparseRoots !== null) {
                return $sparseRoots;
            }
        }

        $coefficientScale = 0.0;
        foreach ($coefficients as $coefficient) {
            $coefficientScale = \max($coefficientScale, \abs($coefficient));
        }
        if ($coefficientScale === 0.0) {
            throw new InfiniteSolutionSetException(
                'The zero polynomial has infinitely many complex roots.',
            );
        }
        $unscaledCoefficients = $coefficients;
        $normalizationScale = $this->binaryNormalizationScale($coefficientScale);
        $normalizationUnderflow = false;
        foreach ($coefficients as $index => $coefficient) {
            $normalized = $coefficient / $normalizationScale;
            if ($coefficient !== 0.0 && $normalized === 0.0) {
                $normalizationUnderflow = true;
                break;
            }
            $coefficients[$index] = $normalized;
        }
        if ($normalizationUnderflow) {
            $initialRoots = $this->newtonPolygonInitialRoots($unscaledCoefficients);
            if ($initialRoots === null) {
                throw new \DomainException(
                    'Coefficient normalization underflowed and no scaled root initialization was available.',
                );
            }
            [$roots, $converged, $iterations] = $this->iterateAberthScaled(
                $unscaledCoefficients,
                $initialRoots,
                $options,
                true,
            );
            if (
                $exactCoefficients !== null
                && $this->exactPolynomialHasRepeatedRoots($exactCoefficients) === true
            ) {
                [$roots, $recoveryIterations] = $this->mergeRepeatedRootsFromDerivative(
                    $unscaledCoefficients,
                    $roots,
                    $options,
                    $exactCoefficients,
                );
                $iterations += $recoveryIterations;
            }
            $solutions = [];
            foreach ($roots as $root) {
                if (!\is_finite($root['real']) || !\is_finite($root['imaginary'])) {
                    throw new \DomainException('Scaled complex root iteration produced a non-finite candidate.');
                }
                $residual = $this->normalizedResidual($unscaledCoefficients, $root, true);
                if (!\is_finite($residual)) {
                    throw new \DomainException('Scaled complex polynomial residual is not finite.');
                }
                $converged = $converged && $residual <= $options->residualTolerance;
                $solutions[] = new ComplexRoot($root['real'], $root['imaginary'], $residual);
            }
            \usort(
                $solutions,
                static fn (ComplexRoot $left, ComplexRoot $right): int =>
                    [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
            );

            return new ComplexPolynomialSolution($solutions, $degree, $converged, $iterations);
        }

        if ($degree === 1) {
            $root = -$coefficients[0] / $coefficients[1];
            if (!\is_finite($root)) {
                throw new \DomainException('The linear polynomial root is not representable as a finite float.');
            }
            $residual = $this->normalizedResidual(
                $coefficients,
                ['real' => $root, 'imaginary' => 0.0],
            );

            return new ComplexPolynomialSolution(
                [new ComplexRoot($root, 0.0, $residual)],
                1,
                $residual <= $options->residualTolerance,
                0,
            );
        }

        if ($degree === 2) {
            return $this->solveQuadratic($coefficients, $options, $exactCoefficients);
        }

        if ($degree === 3 && $exactCoefficients !== null) {
            $cubicDiscriminantSign = $this->cubicDiscriminantSign($exactCoefficients);
            if ($cubicDiscriminantSign > 0) {
                $realCubicRoots = $this->solveThreeRealRootCubic($coefficients, $options);
                if ($realCubicRoots !== null) {
                    return $realCubicRoots;
                }
            } elseif ($cubicDiscriminantSign < 0) {
                $oneRealCubicRoot = $this->solveOneRealRootCubic(
                    $coefficients,
                    $exactCoefficients,
                    $options,
                );
                if ($oneRealCubicRoot !== null) {
                    return $oneRealCubicRoot;
                }
            }
        }

        $exponentLatticeRoots = $this->solveExponentLatticePolynomial(
            $coefficients,
            $degree,
            $options,
            $exactCoefficients,
        );
        if ($exponentLatticeRoots !== null) {
            return $exponentLatticeRoots;
        }

        $repeatedRoot = $this->solveRepeatedLinearFactor(
            $coefficients,
            $degree,
            $options,
            $exactCoefficients,
        );
        if ($repeatedRoot !== null) {
            return $repeatedRoot;
        }

        $repeatedQuadratic = $this->solveRepeatedQuadraticFactor(
            $coefficients,
            $degree,
            $options,
            $exactCoefficients,
        );
        if ($repeatedQuadratic !== null) {
            return $repeatedQuadratic;
        }

        $centeredSolution = $this->solveAfterPolynomialCentering(
            $coefficients,
            $degree,
            $options,
            $exactCoefficients,
        );
        if ($centeredSolution !== null) {
            return $centeredSolution;
        }

        $hasExactSquareFreeLowDegreePolynomial = $exactCoefficients !== null
            && (($degree === 3 && !$this->hasZeroCubicDiscriminant($exactCoefficients))
                || ($degree === 4 && !$this->hasZeroQuarticDiscriminant($exactCoefficients)));
        $perfectPowerRoots = $hasExactSquareFreeLowDegreePolynomial
            ? null
            : $this->solvePerfectPowerPolynomial($coefficients, $degree, $options);
        if ($perfectPowerRoots !== null) {
            return $perfectPowerRoots;
        }

        $squareFreeRoots = $this->solveSquareFreeDecomposition(
            $coefficients,
            $degree,
            $options,
            $exactCoefficients,
        );
        if ($squareFreeRoots !== null) {
            return $squareFreeRoots;
        }

        $polygonRoots = $this->newtonPolygonInitialRoots($coefficients);
        $usedScaledIteration = $polygonRoots !== null && $this->rootsHaveSeparatedScales($polygonRoots);
        if ($usedScaledIteration) {
            [$roots, $converged, $iterations] = $this->iterateAberthScaled(
                $coefficients,
                $polygonRoots,
                $options,
            );
        } elseif (
            $polygonRoots !== null
            && $this->newtonPolygonSeedsAreTighterThanCauchyBound($coefficients, $polygonRoots)
        ) {
            $radius = 1.0;
            foreach ($polygonRoots as $root) {
                $radius = \max($radius, \hypot($root['real'], $root['imaginary']));
            }
            [$roots, $converged, $iterations] = $this->iterateAberth(
                $coefficients,
                $polygonRoots,
                $options,
                $radius,
            );
        } else {
            $leading = \abs($coefficients[$degree]);
            $maximumLower = 0.0;
            for ($index = 0; $index < $degree; ++$index) {
                $maximumLower = \max($maximumLower, \abs($coefficients[$index]));
            }
            $ratio = $maximumLower / $leading;
            $radius = \max(1.0, 1.0 + $ratio);
            if (!\is_finite($radius)) {
                throw new \DomainException('A finite Cauchy root bound could not be represented.');
            }

            $roots = [];
            $phaseOffset = 0.2718281828459045;
            for ($index = 0; $index < $degree; ++$index) {
                $angle = $phaseOffset + ((2.0 * \M_PI * $index) / $degree);
                $roots[] = [
                    'real' => $radius * \cos($angle),
                    'imaginary' => $radius * \sin($angle),
                ];
            }
            $initialRoots = $roots;

            $converged = false;
            $iterations = 0;
            for ($iteration = 1; $iteration <= $options->maxIterations; ++$iteration) {
                $next = $roots;
                $largestChange = 0.0;
                foreach ($roots as $index => $root) {
                    $value = $this->evaluate($coefficients, $root);
                    $denominator = ['real' => 1.0, 'imaginary' => 0.0];
                    foreach ($roots as $otherIndex => $otherRoot) {
                        if ($otherIndex !== $index) {
                            $denominator = $this->multiply(
                                $denominator,
                                $this->subtract($root, $otherRoot),
                            );
                        }
                    }
                    $denominatorMagnitude = \hypot($denominator['real'], $denominator['imaginary']);
                    if ($denominatorMagnitude === 0.0 || !\is_finite($denominatorMagnitude)) {
                        $iterations = $iteration;
                        break 2;
                    }
                    $delta = $this->divide($value, $denominator);
                    $next[$index] = $this->subtract($root, $delta);
                    $change = \hypot($delta['real'], $delta['imaginary']);
                    $candidateRadius = \hypot($next[$index]['real'], $next[$index]['imaginary']);
                    if (
                        !\is_finite($change)
                        || !\is_finite($candidateRadius)
                        || $candidateRadius > $radius
                    ) {
                        $iterations = $iteration;
                        break 2;
                    }
                    $largestChange = \max($largestChange, $change);
                }
                $roots = $next;
                $iterations = $iteration;
                if ($largestChange <= $options->absoluteTolerance + $options->relativeTolerance * $radius) {
                    $converged = true;
                    break;
                }
            }
            if (!$converged) {
                [$aberthRoots, $aberthConverged, $aberthIterations] = $this->iterateAberth(
                    $coefficients,
                    $initialRoots,
                    $options,
                    $radius,
                );
                $roots = $aberthRoots;
                $converged = $aberthConverged;
                $iterations += $aberthIterations;
            }
        }

        $unrefinedRoots = $roots;
        $recoverRepeatedRoots = !$usedScaledIteration;
        if ($usedScaledIteration && $exactCoefficients !== null) {
            $recoverRepeatedRoots = $this->exactPolynomialHasRepeatedRoots($exactCoefficients) === true;
        }
        if ($recoverRepeatedRoots) {
            [$roots, $derivativeIterations] = $this->mergeRepeatedRootsFromDerivative(
                $coefficients,
                $roots,
                $options,
                $exactCoefficients,
            );
            $iterations += $derivativeIterations;
        }
        if ($roots !== $unrefinedRoots) {
            $converged = true;
        }

        $solutions = [];
        foreach ($roots as $rootIndex => $root) {
            if (!\is_finite($root['real']) || !\is_finite($root['imaginary'])) {
                throw new \DomainException('Complex root iteration produced a non-finite candidate.');
            }
            $isValidatedMultipleRoot = false;
            foreach ($roots as $otherIndex => $otherRoot) {
                if (
                    $otherIndex !== $rootIndex
                    &&
                    $otherRoot['real'] === $root['real']
                    && $otherRoot['imaginary'] === $root['imaginary']
                    && $this->normalizedResidual($coefficients, $root)
                        <= 128.0 * \PHP_FLOAT_EPSILON * \count($coefficients)
                ) {
                    $isValidatedMultipleRoot = true;
                    break;
                }
            }
            if (!$isValidatedMultipleRoot && !$usedScaledIteration) {
                $root = $this->polish($coefficients, $root, $options);
            }
            $residual = $this->normalizedResidual($coefficients, $root);
            if (!\is_finite($residual)) {
                throw new \DomainException('Complex polynomial residual is not finite.');
            }
            $solutions[] = new ComplexRoot($root['real'], $root['imaginary'], $residual);
            $converged = $converged && $residual <= $options->residualTolerance;
        }
        \usort(
            $solutions,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($solutions, $degree, $converged, $iterations);
    }

    /** @param list<string> $exactCoefficients */
    private function solveWithExactVariableScaling(
        array $exactCoefficients,
        SolverOptions $options,
    ): ?ComplexPolynomialSolution {
        $points = [];
        foreach ($exactCoefficients as $power => $coefficient) {
            if ($coefficient !== '0') {
                $points[] = [$power, $this->logIntegerMagnitude($coefficient)];
            }
        }
        if (\count($points) < 2) {
            throw new \DomainException('Exact polynomial coefficients cannot be represented at a finite scale.');
        }
        $hull = [];
        foreach ($points as $point) {
            $hull[] = $point;
            while (\count($hull) >= 3) {
                $last = \count($hull) - 1;
                [$x0, $y0] = $hull[$last - 2];
                [$x1, $y1] = $hull[$last - 1];
                [$x2, $y2] = $hull[$last];
                if (($y1 - $y0) * ($x2 - $x1) > ($y2 - $y1) * ($x1 - $x0)) {
                    break;
                }
                \array_splice($hull, $last - 1, 1);
            }
        }
        $weightedLogScale = 0.0;
        $rootCount = 0;
        foreach ($hull as $index => [$leftPower, $leftLogMagnitude]) {
            if (!isset($hull[$index + 1])) {
                continue;
            }
            [$rightPower, $rightLogMagnitude] = $hull[$index + 1];
            $multiplicity = $rightPower - $leftPower;
            $weightedLogScale += -($rightLogMagnitude - $leftLogMagnitude);
            $rootCount += $multiplicity;
        }
        $logVariableScale = $rootCount === 0 ? \NAN : $weightedLogScale / $rootCount;
        if (
            !\is_finite($logVariableScale)
            || $logVariableScale > \log(\PHP_FLOAT_MAX)
            || $logVariableScale < \log(5.0E-324)
        ) {
            throw new \DomainException('Exact polynomial root scales are not representable as finite floats.');
        }
        $variableScale = \exp($logVariableScale);
        $transformedLogs = [];
        $maximumLogMagnitude = -\INF;
        foreach ($exactCoefficients as $power => $coefficient) {
            $logMagnitude = $coefficient === '0'
                ? -\INF
                : $this->logIntegerMagnitude($coefficient) + ($power * $logVariableScale);
            $transformedLogs[] = $logMagnitude;
            $maximumLogMagnitude = \max($maximumLogMagnitude, $logMagnitude);
        }
        $scaledCoefficients = [];
        foreach ($exactCoefficients as $power => $coefficient) {
            $scaledCoefficients[] = $coefficient === '0'
                ? 0.0
                : ($coefficient[0] === '-' ? -1.0 : 1.0)
                    * \exp($transformedLogs[$power] - $maximumLogMagnitude);
            if ($coefficient !== '0' && $scaledCoefficients[$power] === 0.0) {
                return null;
            }
        }
        if ($scaledCoefficients === [] || \end($scaledCoefficients) === 0.0) {
            throw new \DomainException('Exact polynomial degree could not be retained during variable scaling.');
        }

        $scaledExactCoefficients = $this->scaleExactCoefficients(
            $exactCoefficients,
            $variableScale,
        );
        if ($scaledExactCoefficients !== null) {
            $scaledCoefficients = (new IntegerPolynomialExtractor('', [], \count($exactCoefficients) - 1))
                ->normalizedCoefficients($scaledExactCoefficients, true) ?? $scaledCoefficients;
        }
        $scaledSolution = $this->solve($scaledCoefficients, $options, $scaledExactCoefficients);
        $roots = [];
        $converged = $scaledSolution->converged;
        foreach ($scaledSolution->roots as $root) {
            $real = $root->real * $variableScale;
            $imaginary = $root->imaginary * $variableScale;
            if (
                !\is_finite($real)
                || !\is_finite($imaginary)
                || ($root->real !== 0.0 && $real === 0.0)
                || ($root->imaginary !== 0.0 && $imaginary === 0.0)
            ) {
                throw new \DomainException(
                    'An exact-scaled polynomial root is not representable as a finite complex number.',
                );
            }
            $residual = $this->exactPolynomialResidual($exactCoefficients, $real, $imaginary);
            $converged = $converged && $residual <= $options->residualTolerance;
            $roots[] = new ComplexRoot($real, $imaginary, $residual);
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution(
            $roots,
            \count($exactCoefficients) - 1,
            $converged,
            $scaledSolution->iterations,
        );
    }

    private function logIntegerMagnitude(string $integer): float
    {
        $digits = \ltrim($integer, '-');
        $prefixLength = \min(15, \strlen($digits));
        $prefix = (float) \substr($digits, 0, $prefixLength);

        return \log($prefix) + (\strlen($digits) - $prefixLength) * \log(10.0);
    }

    /**
     * @param list<string> $coefficients
     * @return list<string>|null
     */
    private function scaleExactCoefficients(array $coefficients, float $scale): ?array
    {
        $scaleFraction = RationalLiteral::fromFloatAsStrings($scale);
        if ($scaleFraction === null || $scaleFraction[0] === '0') {
            return null;
        }
        [$numerator, $denominator] = $scaleFraction;
        $degree = \count($coefficients) - 1;
        $arithmetic = new IntegerPolynomialExtractor('', [], $degree);
        $numeratorPowers = ['1'];
        $denominatorPowers = ['1'];
        for ($power = 1; $power <= $degree; ++$power) {
            $numeratorPowers[] = $arithmetic->multiplyIntegerValues(
                $numeratorPowers[$power - 1],
                $numerator,
            );
            $denominatorPowers[] = $arithmetic->multiplyIntegerValues(
                $denominatorPowers[$power - 1],
                $denominator,
            );
            if (
                \strlen($numeratorPowers[$power]) > 8192
                || \strlen($denominatorPowers[$power]) > 8192
            ) {
                return null;
            }
        }

        $scaled = [];
        foreach ($coefficients as $degree => $coefficient) {
            if ($coefficient === '0') {
                $scaled[] = '0';
                continue;
            }
            $scaledCoefficient = $arithmetic->multiplyIntegerValues(
                $coefficient,
                $numeratorPowers[$degree],
            );
            $scaledCoefficient = $arithmetic->multiplyIntegerValues(
                $scaledCoefficient,
                $denominatorPowers[\count($coefficients) - $degree - 1],
            );
            if (\strlen(\ltrim($scaledCoefficient, '-')) > 8192) {
                return null;
            }
            $scaled[] = $scaledCoefficient;
        }

        return $scaled;
    }

    /** @param list<string> $coefficients */
    private function exactPolynomialResidual(array $coefficients, float $real, float $imaginary): float
    {
        if ($real === 0.0 && $imaginary === 0.0 && $coefficients[0] === '0') {
            return 0.0;
        }
        $radius = \hypot($real, $imaginary);
        $scale = $radius === 0.0 ? 1.0 : $radius;
        $scaledValue = ['real' => $real / $scale, 'imaginary' => $imaginary / $scale];
        $scaledRadius = $radius / $scale;
        $logScale = \log($scale);
        $logs = [];
        $maximumLogMagnitude = -\INF;
        foreach ($coefficients as $power => $coefficient) {
            $logs[$power] = $coefficient === '0'
                ? -\INF
                : $this->logIntegerMagnitude($coefficient) + ($power * $logScale);
            $maximumLogMagnitude = \max($maximumLogMagnitude, $logs[$power]);
        }

        $value = ['real' => 0.0, 'imaginary' => 0.0];
        $bound = 0.0;
        for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
            $coefficient = $logs[$power] === -\INF
                ? 0.0
                : ($coefficients[$power][0] === '-' ? -1.0 : 1.0)
                    * \exp($logs[$power] - $maximumLogMagnitude);
            $value = $this->add(
                $this->multiply($value, $scaledValue),
                ['real' => $coefficient, 'imaginary' => 0.0],
            );
            $bound = ($bound * $scaledRadius) + \abs($coefficient);
        }

        return $bound === 0.0 ? \INF : \hypot($value['real'], $value['imaginary']) / $bound;
    }

    /**
     * Translate the variable to the polynomial centroid when that sharply
     * reduces coefficient magnitude, then validate shifted roots in the
     * original polynomial before returning them.
     *
     * @param list<float> $coefficients
     * @param list<string>|null $exactCoefficients
     */
    private function solveAfterPolynomialCentering(
        array $coefficients,
        int $degree,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ?ComplexPolynomialSolution {
        if ($degree < 5 || $exactCoefficients === null || \count($exactCoefficients) !== $degree + 1) {
            return null;
        }
        $integerArithmetic = new IntegerPolynomialExtractor('', []);
        $centroidNumerator = $integerArithmetic->negateIntegerValue($exactCoefficients[$degree - 1]);
        $centroidDenominator = $integerArithmetic->multiplyIntegerValues(
            $exactCoefficients[$degree],
            (string) $degree,
        );
        if ($centroidDenominator[0] === '-') {
            $centroidNumerator = $integerArithmetic->negateIntegerValue($centroidNumerator);
            $centroidDenominator = $integerArithmetic->negateIntegerValue($centroidDenominator);
        }
        $divisor = $integerArithmetic->greatestCommonDivisorValues($centroidNumerator, $centroidDenominator);
        $centerNumerator = $integerArithmetic->divideIntegerValueExactly($centroidNumerator, $divisor);
        $centerDenominator = $integerArithmetic->divideIntegerValueExactly($centroidDenominator, $divisor);
        if ($centerNumerator === null || $centerDenominator === null || $centerDenominator === '0') {
            return null;
        }
        $centerValue = $integerArithmetic->ratio($centerNumerator, $centerDenominator);
        if (!\is_float($centerValue) || !\is_finite($centerValue) || \abs($centerValue) <= 64.0 * \PHP_FLOAT_EPSILON) {
            return null;
        }
        $center = $centerValue;

        $translatedExact = \array_fill(0, $degree + 1, '0');
        foreach ($exactCoefficients as $power => $coefficient) {
            $binomial = '1';
            for ($translatedPower = 0; $translatedPower <= $power; ++$translatedPower) {
                $term = $integerArithmetic->multiplyIntegerValues(
                    $coefficient,
                    $binomial,
                );
                $term = $integerArithmetic->multiplyIntegerValues(
                    $term,
                    $this->integerPower($centerNumerator, $power - $translatedPower, $integerArithmetic),
                );
                $term = $integerArithmetic->multiplyIntegerValues(
                    $term,
                    $this->integerPower($centerDenominator, $degree - $power + $translatedPower, $integerArithmetic),
                );
                if (\strlen(\ltrim($term, '-')) > 8192) {
                    return null;
                }
                $translatedExact[$translatedPower] = $integerArithmetic->addIntegerValues(
                    $translatedExact[$translatedPower],
                    $term,
                );
                if (\strlen(\ltrim($translatedExact[$translatedPower], '-')) > 8192) {
                    return null;
                }
                if ($translatedPower < $power) {
                    $numerator = $integerArithmetic->multiplyIntegerValues(
                        $binomial,
                        (string) ($power - $translatedPower),
                    );
                    $binomial = $integerArithmetic->divideIntegerValueExactly(
                        $numerator,
                        (string) ($translatedPower + 1),
                    ) ?? '0';
                }
            }
        }
        $translatedExact = \array_values($translatedExact);
        $exactScale = '0';
        foreach ($exactCoefficients as $coefficient) {
            $magnitude = \ltrim($coefficient, '-');
            if (
                \strlen($magnitude) > \strlen($exactScale)
                || (\strlen($magnitude) === \strlen($exactScale) && \strcmp($magnitude, $exactScale) > 0)
            ) {
                $exactScale = $magnitude;
            }
        }
        if ($exactScale === '0') {
            return null;
        }
        $coefficientMagnitude = 0.0;
        foreach ($coefficients as $coefficient) {
            $coefficientMagnitude = \max($coefficientMagnitude, \abs($coefficient));
        }
        $translated = [];
        $translatedScale = $integerArithmetic->multiplyIntegerValues(
            $exactScale,
            $this->integerPower($centerDenominator, $degree, $integerArithmetic),
        );
        foreach ($translatedExact as $coefficient) {
            $ratio = $integerArithmetic->ratio($coefficient, $translatedScale);
            if (!\is_float($ratio)) {
                return null;
            }
            $translated[] = $ratio * $coefficientMagnitude;
        }

        $originalMagnitude = \array_sum(\array_map(\abs(...), $coefficients));
        $translatedMagnitude = \array_sum(\array_map(\abs(...), $translated));
        if (
            !\is_finite($translatedMagnitude)
            || $translatedMagnitude >= 0.25 * $originalMagnitude
        ) {
            return null;
        }
        if (
            $this->exactPolynomialHasRepeatedRoots($exactCoefficients) !== false
        ) {
            return null;
        }

        $centered = $this->solve($translated, $options, $translatedExact);
        $roots = [];
        $converged = $centered->converged;
        foreach ($centered->roots as $root) {
            $real = $root->real + $center;
            $residual = $this->normalizedResidual($coefficients, [
                'real' => $real,
                'imaginary' => $root->imaginary,
            ]);
            if (!\is_finite($residual)) {
                throw new \DomainException('Centered polynomial residual is not finite.');
            }
            $roots[] = new ComplexRoot($real, $root->imaginary, $residual);
            $converged = $converged && $residual <= $options->residualTolerance;
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($roots, $degree, $converged, $centered->iterations);
    }

    private function integerPower(string $base, int $exponent, IntegerPolynomialExtractor $arithmetic): string
    {
        $result = '1';
        for ($power = 0; $power < $exponent; ++$power) {
            $result = $arithmetic->multiplyIntegerValues($result, $base);
            if (\strlen(\ltrim($result, '-')) > 8192) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * @param list<float> $coefficients
     * @param list<array{real: float, imaginary: float}> $roots
     * @param list<string>|null $exactCoefficients
     * @return array{list<array{real: float, imaginary: float}>, int}
     */
    private function mergeRepeatedRootsFromDerivative(
        array $coefficients,
        array $roots,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): array {
        if (
            \count($coefficients) === 4
            && $exactCoefficients !== null
            && \count($exactCoefficients) === 4
            && !$this->hasZeroCubicDiscriminant($exactCoefficients)
        ) {
            return [$roots, 0];
        }
        if (
            \count($coefficients) === 5
            && $exactCoefficients !== null
            && \count($exactCoefficients) === 5
            && !$this->hasZeroQuarticDiscriminant($exactCoefficients)
        ) {
            return [$roots, 0];
        }
        if ($exactCoefficients !== null && $this->exactPolynomialHasRepeatedRoots($exactCoefficients) === false) {
            return [$roots, 0];
        }

        $scale = 1.0;
        $hasCloseRoots = false;
        $recoveryRadius = 0.01;
        foreach ($roots as $root) {
            $scale = \max($scale, \hypot($root['real'], $root['imaginary']));
        }
        foreach ($roots as $index => $root) {
            for ($otherIndex = 0; $otherIndex < $index; ++$otherIndex) {
                if (
                    \hypot(
                        $root['real'] - $roots[$otherIndex]['real'],
                        $root['imaginary'] - $roots[$otherIndex]['imaginary'],
                    ) <= $recoveryRadius * $scale
                ) {
                    $hasCloseRoots = true;
                    break 2;
                }
            }
        }
        if (!$hasCloseRoots) {
            return [$roots, 0];
        }
        if ($exactCoefficients !== null) {
            $hasRepeatedRoot = $this->exactPolynomialHasRepeatedRoots($exactCoefficients);
            if ($hasRepeatedRoot === false) {
                return [$roots, 0];
            }
        }

        $derivativeLevels = [$coefficients];
        while (\count($derivativeLevels[\array_key_last($derivativeLevels)]) > 2) {
            $previous = $derivativeLevels[\array_key_last($derivativeLevels)];
            $derivative = [];
            for ($power = 1, $degree = \count($previous) - 1; $power <= $degree; ++$power) {
                $derivative[] = $power * $previous[$power];
            }
            $derivativeScale = \max(\array_map('\\abs', $derivative));
            if ($derivativeScale === 0.0 || !\is_finite($derivativeScale)) {
                break;
            }
            $derivativeScale = $this->binaryNormalizationScale($derivativeScale);
            foreach ($derivative as $power => $coefficient) {
                $derivative[$power] = $coefficient / $derivativeScale;
            }
            $derivativeLevels[] = $derivative;
        }

        $residualTolerance = \min(
            $options->residualTolerance,
            128.0 * \PHP_FLOAT_EPSILON * \count($coefficients),
        );
        $additionalIterations = 0;
        for ($order = \count($derivativeLevels) - 1; $order >= 1; --$order) {
            $derivativeSolution = $this->solve($derivativeLevels[$order], $options);
            $additionalIterations += $derivativeSolution->iterations;
            if (!$derivativeSolution->converged) {
                continue;
            }

            foreach ($derivativeSolution->roots as $candidate) {
                $candidateRoot = [
                    'real' => $candidate->real,
                    'imaginary' => $candidate->imaginary,
                ];
                $candidateRoot = $this->polish(
                    $derivativeLevels[$order],
                    $candidateRoot,
                    $options,
                );
                if (
                    $this->normalizedResidual($coefficients, $candidateRoot)
                        > $residualTolerance
                ) {
                    continue;
                }

                $derivativeMultiplicity = 0;
                foreach ($derivativeSolution->roots as $otherCandidate) {
                    if (
                        $otherCandidate->real === $candidate->real
                        && $otherCandidate->imaginary === $candidate->imaginary
                    ) {
                        ++$derivativeMultiplicity;
                    }
                }
                $multiplicity = $order + $derivativeMultiplicity;
                if ($multiplicity > \count($roots)) {
                    continue;
                }

                $commonRoot = true;
                for ($derivativeOrder = 1; $derivativeOrder <= $order; ++$derivativeOrder) {
                    if (
                        $this->normalizedResidual(
                            $derivativeLevels[$derivativeOrder],
                            $candidateRoot,
                        ) > $residualTolerance
                    ) {
                        $commonRoot = false;
                        break;
                    }
                }
                if (!$commonRoot) {
                    continue;
                }

                $isRealRoot = \abs($candidateRoot['imaginary'])
                    <= \min(
                        1.0E-8,
                        128.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($candidateRoot['real'])),
                    );
                $rootMultiplicity = $multiplicity * ($isRealRoot ? 1 : 2);
                if ($rootMultiplicity > \count($roots)) {
                    continue;
                }
                if ($isRealRoot) {
                    $deflationRoot = $candidateRoot['real'];
                    $nearestInteger = \round($deflationRoot);
                    if (\abs($deflationRoot - $nearestInteger) <= 1.0E-8) {
                        $deflationRoot = $nearestInteger;
                    }
                    $quotient = $this->deflateRealRoot(
                        $coefficients,
                        $deflationRoot,
                        $multiplicity,
                    );
                    if ($quotient !== null) {
                        $quotientSolution = $this->solve($quotient, $options);
                        $additionalIterations += $quotientSolution->iterations;
                        $recoveredRoots = \array_fill(0, $multiplicity, [
                            'real' => $deflationRoot,
                            'imaginary' => 0.0,
                        ]);
                        foreach ($quotientSolution->roots as $quotientRoot) {
                            $recoveredRoots[] = [
                                'real' => $quotientRoot->real,
                                'imaginary' => $quotientRoot->imaginary,
                            ];
                        }
                        $allResidualsAccepted = \count($recoveredRoots) === \count($roots);
                        foreach ($recoveredRoots as $recoveredRoot) {
                            $allResidualsAccepted = $allResidualsAccepted
                                && $this->normalizedResidual($coefficients, $recoveredRoot)
                                    <= $options->residualTolerance;
                        }
                        if ($allResidualsAccepted) {
                            return [$recoveredRoots, $additionalIterations];
                        }
                    }
                }
                $recoveredRoots = $roots;
                $claimedIndices = [];
                for ($copy = 0; $copy < $multiplicity; ++$copy) {
                    $candidatePair = [$candidateRoot];
                    if (!$isRealRoot) {
                        $candidatePair[] = [
                            'real' => $candidateRoot['real'],
                            'imaginary' => -$candidateRoot['imaginary'],
                        ];
                    }
                    foreach ($candidatePair as $recoveredRoot) {
                        $nearestIndex = null;
                        $nearestDistance = \INF;
                        foreach ($roots as $rootIndex => $root) {
                            if (isset($claimedIndices[$rootIndex])) {
                                continue;
                            }
                            $distance = \hypot(
                                $root['real'] - $recoveredRoot['real'],
                                $root['imaginary'] - $recoveredRoot['imaginary'],
                            );
                            if ($distance < $nearestDistance) {
                                $nearestDistance = $distance;
                                $nearestIndex = $rootIndex;
                            }
                        }
                        if ($nearestIndex === null) {
                            continue 3;
                        }
                        $claimedIndices[$nearestIndex] = true;
                        $recoveredRoots[$nearestIndex] = $recoveredRoot;
                    }
                }
                $allResidualsAccepted = true;
                foreach ($recoveredRoots as $recoveredRoot) {
                    if (
                        $this->normalizedResidual($coefficients, $recoveredRoot)
                            > $options->residualTolerance
                    ) {
                        $allResidualsAccepted = false;
                        break;
                    }
                }
                if ($allResidualsAccepted) {
                    return [$recoveredRoots, $additionalIterations];
                }
            }
        }

        return [$roots, $additionalIterations];
    }

    /** @param list<string> $coefficients */
    private function hasZeroCubicDiscriminant(array $coefficients): bool
    {
        return $this->cubicDiscriminantSign($coefficients) === 0;
    }

    /** @param list<string> $coefficients */
    private function cubicDiscriminantSign(array $coefficients): int
    {
        [$constant, $linear, $quadratic, $cubic] = $coefficients;
        $integerArithmetic = new IntegerPolynomialExtractor('', [], 3);
        $linearSquared = $integerArithmetic->multiplyIntegerValues($linear, $linear);
        $quadraticSquared = $integerArithmetic->multiplyIntegerValues($quadratic, $quadratic);
        $cubicSquared = $integerArithmetic->multiplyIntegerValues($cubic, $cubic);
        $linearCubed = $integerArithmetic->multiplyIntegerValues($linearSquared, $linear);
        $quadraticCubed = $integerArithmetic->multiplyIntegerValues($quadraticSquared, $quadratic);
        $constantSquared = $integerArithmetic->multiplyIntegerValues($constant, $constant);
        $discriminant = $integerArithmetic->multiplyIntegerValues(
            $quadraticSquared,
            $linearSquared,
        );
        $discriminant = $integerArithmetic->subtract(
            $discriminant,
            $integerArithmetic->multiplyIntegerValues(
                '4',
                $integerArithmetic->multiplyIntegerValues($cubic, $linearCubed),
            ),
        );
        $discriminant = $integerArithmetic->subtract(
            $discriminant,
            $integerArithmetic->multiplyIntegerValues(
                '4',
                $integerArithmetic->multiplyIntegerValues($quadraticCubed, $constant),
            ),
        );
        $discriminant = $integerArithmetic->subtract(
            $discriminant,
            $integerArithmetic->multiplyIntegerValues(
                '27',
                $integerArithmetic->multiplyIntegerValues($cubicSquared, $constantSquared),
            ),
        );
        $discriminant = $integerArithmetic->addIntegerValues(
            $discriminant,
            $integerArithmetic->multiplyIntegerValues(
                '18',
                $integerArithmetic->multiplyIntegerValues(
                    $cubic,
                    $integerArithmetic->multiplyIntegerValues(
                        $quadratic,
                        $integerArithmetic->multiplyIntegerValues($linear, $constant),
                    ),
                ),
            ),
        );

        return $integerArithmetic->sign($discriminant);
    }

    /**
     * @param list<float> $coefficients Coefficients are normalized by the caller.
     */
    private function solveThreeRealRootCubic(
        array $coefficients,
        SolverOptions $options,
    ): ?ComplexPolynomialSolution {
        $leading = \abs($coefficients[3]);
        if ($leading === 0.0) {
            return null;
        }
        $maximumLowerCoefficient = \max(\abs($coefficients[0]), \abs($coefficients[1]), \abs($coefficients[2]));
        $ratio = $maximumLowerCoefficient / $leading;
        $bound = 1.0 + $ratio;
        if (!\is_finite($bound) || $bound <= 0.0) {
            return null;
        }

        $polynomialSolver = new PolynomialSolver();
        $roots = $polynomialSolver->solve(
            $coefficients,
            -$bound,
            $bound,
            fn (float $value): ?float => $polynomialSolver->evaluatePolynomial($coefficients, $value),
            $options,
            fn (float $value): ?float =>
                $polynomialSolver->evaluatePolynomialMagnitude($coefficients, $value),
        );
        if ($roots === null || \count($roots) !== 3) {
            return null;
        }

        $complexRoots = [];
        $iterations = 0;
        foreach ($roots as $root) {
            $residual = $this->normalizedResidual($coefficients, [
                'real' => $root->value,
                'imaginary' => 0.0,
            ]);
            if (!\is_finite($residual) || $residual > $options->residualTolerance) {
                return null;
            }
            $complexRoots[] = new ComplexRoot($root->value, 0.0, $residual);
            $iterations += $root->iterations;
        }
        \usort(
            $complexRoots,
            static fn (ComplexRoot $left, ComplexRoot $right): int => $left->real <=> $right->real,
        );

        return new ComplexPolynomialSolution($complexRoots, 3, true, $iterations);
    }

    /**
     * @param list<float> $coefficients Coefficients are normalized by the caller.
     * @param list<string> $exactCoefficients
     */
    private function solveOneRealRootCubic(
        array $coefficients,
        array $exactCoefficients,
        SolverOptions $options,
    ): ?ComplexPolynomialSolution {
        [$constant, $linear, $quadratic, $cubic] = $exactCoefficients;
        $arithmetic = new IntegerPolynomialExtractor('', [], 3);
        $linearTerm = $arithmetic->subtract(
            $arithmetic->multiplyIntegerValues('3', $arithmetic->multiplyIntegerValues($cubic, $linear)),
            $arithmetic->multiplyIntegerValues($quadratic, $quadratic),
        );
        $linearDenominator = $arithmetic->multiplyIntegerValues(
            '3',
            $arithmetic->multiplyIntegerValues($cubic, $cubic),
        );
        $quadraticTerm = $arithmetic->addIntegerValues(
            $arithmetic->subtract(
                $arithmetic->multiplyIntegerValues(
                    '2',
                    $arithmetic->multiplyIntegerValues(
                        $quadratic,
                        $arithmetic->multiplyIntegerValues($quadratic, $quadratic),
                    ),
                ),
                $arithmetic->multiplyIntegerValues(
                    '9',
                    $arithmetic->multiplyIntegerValues(
                        $cubic,
                        $arithmetic->multiplyIntegerValues($quadratic, $linear),
                    ),
                ),
            ),
            $arithmetic->multiplyIntegerValues(
                '27',
                $arithmetic->multiplyIntegerValues(
                    $cubic,
                    $arithmetic->multiplyIntegerValues($cubic, $constant),
                ),
            ),
        );
        $quadraticDenominator = $arithmetic->multiplyIntegerValues(
            '27',
            $arithmetic->multiplyIntegerValues(
                $cubic,
                $arithmetic->multiplyIntegerValues($cubic, $cubic),
            ),
        );
        $depressedLinear = $arithmetic->ratio($linearTerm, $linearDenominator);
        $depressedConstant = $arithmetic->ratio($quadraticTerm, $quadraticDenominator);
        $shift = $arithmetic->ratio(
            $arithmetic->multiplyIntegerValues('-1', $quadratic),
            $arithmetic->multiplyIntegerValues('3', $cubic),
        );
        if (
            !\is_float($depressedLinear)
            || !\is_float($depressedConstant)
            || !\is_float($shift)
            || !\is_finite($depressedLinear)
            || !\is_finite($depressedConstant)
            || !\is_finite($shift)
        ) {
            return null;
        }

        $scale = \max(\sqrt(\abs($depressedLinear)), \pow(\abs($depressedConstant), 1.0 / 3.0));
        if ($scale === 0.0 || !\is_finite($scale)) {
            return null;
        }
        $scaledLinear = ($depressedLinear / $scale) / $scale;
        $scaledConstant = (($depressedConstant / $scale) / $scale) / $scale;
        $qHalf = $scaledConstant / 2.0;
        $pThirdCubed = (($scaledLinear / 3.0) * ($scaledLinear / 3.0))
            * ($scaledLinear / 3.0);
        $discriminant = ($qHalf * $qHalf) + $pThirdCubed;
        if (!\is_finite($discriminant) || $discriminant <= 0.0) {
            return null;
        }

        $squareRootDiscriminant = \sqrt($discriminant);
        $radicand = $qHalf >= 0.0
            ? -$qHalf - $squareRootDiscriminant
            : -$qHalf + $squareRootDiscriminant;
        $cubeRoot = ($radicand < 0.0 ? -1.0 : 1.0) * \pow(\abs($radicand), 1.0 / 3.0);
        if ($cubeRoot === 0.0 || !\is_finite($cubeRoot)) {
            return null;
        }
        $secondCubeRoot = -$scaledLinear / (3.0 * $cubeRoot);
        $realRoot = $shift + ($scale * ($cubeRoot + $secondCubeRoot));
        if (!\is_finite($realRoot)) {
            return null;
        }
        $realResidual = $this->normalizedResidual($coefficients, [
            'real' => $realRoot,
            'imaginary' => 0.0,
        ]);
        if (!\is_finite($realResidual) || $realResidual > $options->residualTolerance) {
            return null;
        }

        $quadraticLinear = $coefficients[2] + ($coefficients[3] * $realRoot);
        $quadraticConstant = $coefficients[1] + ($realRoot * $quadraticLinear);
        if (!\is_finite($quadraticLinear) || !\is_finite($quadraticConstant)) {
            return null;
        }
        $quadratic = $this->solveQuadratic(
            [$quadraticConstant, $quadraticLinear, $coefficients[3]],
            $options,
            null,
        );
        if (
            !$quadratic->converged
            || \count($quadratic->roots) !== 2
            || $quadratic->roots[0]->imaginary === 0.0
            || $quadratic->roots[1]->imaginary === 0.0
            || $quadratic->roots[0]->imaginary !== -$quadratic->roots[1]->imaginary
            || $quadratic->roots[0]->real !== $quadratic->roots[1]->real
        ) {
            return null;
        }

        $roots = [new ComplexRoot($realRoot, 0.0, $realResidual), ...$quadratic->roots];
        foreach ($roots as $index => $root) {
            $residual = $this->normalizedResidual($coefficients, [
                'real' => $root->real,
                'imaginary' => $root->imaginary,
            ]);
            if (!\is_finite($residual) || $residual > $options->residualTolerance) {
                return null;
            }
            $roots[$index] = new ComplexRoot($root->real, $root->imaginary, $residual);
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution(
            $roots,
            3,
            true,
            $quadratic->iterations,
        );
    }

    /** @param list<string> $coefficients */
    private function hasZeroQuarticDiscriminant(array $coefficients): bool
    {
        [$e, $d, $c, $b, $a] = $coefficients;
        $integerArithmetic = new IntegerPolynomialExtractor('', [], 4);
        $terms = [
            [256, [$a, $a, $a, $e, $e, $e]],
            [-192, [$a, $a, $b, $d, $e, $e]],
            [-128, [$a, $a, $c, $c, $e, $e]],
            [144, [$a, $a, $c, $d, $d, $e]],
            [-27, [$a, $a, $d, $d, $d, $d]],
            [144, [$a, $b, $b, $c, $e, $e]],
            [-6, [$a, $b, $b, $d, $d, $e]],
            [-80, [$a, $b, $c, $c, $d, $e]],
            [18, [$a, $b, $c, $d, $d, $d]],
            [16, [$a, $c, $c, $c, $c, $e]],
            [-4, [$a, $c, $c, $c, $d, $d]],
            [-27, [$b, $b, $b, $b, $e, $e]],
            [18, [$b, $b, $b, $c, $d, $e]],
            [-4, [$b, $b, $b, $d, $d, $d]],
            [-4, [$b, $b, $c, $c, $c, $e]],
            [1, [$b, $b, $c, $c, $d, $d]],
        ];
        $discriminant = '0';
        foreach ($terms as [$multiplier, $factors]) {
            $term = (string) $multiplier;
            foreach ($factors as $factor) {
                $term = $integerArithmetic->multiplyIntegerValues($term, $factor);
            }
            $discriminant = $integerArithmetic->addIntegerValues($discriminant, $term);
        }

        return $discriminant === '0';
    }

    /**
     * @param list<string> $exactCoefficients
     * @param list<float> $factor
     */
    private function exactPolynomialPowerMatches(
        array $exactCoefficients,
        array $factor,
        int $power,
    ): bool {
        if ($power < 1 || $exactCoefficients === [] || $factor === []) {
            return false;
        }

        $fractions = [];
        $commonDenominator = 1;
        foreach ($factor as $coefficient) {
            $nearestInteger = \round($coefficient);
            $fraction = \abs($coefficient - $nearestInteger)
                <= \min(
                    1.0E-8,
                    32.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($coefficient)),
                )
                && \abs($nearestInteger) <= 9_007_199_254_740_992.0
                ? [(int) $nearestInteger, 1]
                : RationalLiteral::fromFloat($coefficient);
            if ($fraction === null || $fraction[1] < 1) {
                return false;
            }
            $divisor = $this->greatestCommonDivisor($commonDenominator, $fraction[1]);
            $denominatorFactor = intdiv($fraction[1], $divisor);
            if ($commonDenominator > intdiv(\PHP_INT_MAX, $denominatorFactor)) {
                return false;
            }
            $commonDenominator *= $denominatorFactor;
            $fractions[] = $fraction;
        }

        $integerArithmetic = new IntegerPolynomialExtractor('', [], \count($exactCoefficients));
        $integerFactor = [];
        foreach ($fractions as [$numerator, $denominator]) {
            $integerFactor[] = $integerArithmetic->multiplyIntegerValues(
                (string) $numerator,
                (string) intdiv($commonDenominator, $denominator),
            );
        }

        $poweredFactor = ['1'];
        for ($copy = 0; $copy < $power; ++$copy) {
            $product = \array_fill(0, \count($poweredFactor) + \count($integerFactor) - 1, '0');
            foreach ($poweredFactor as $leftDegree => $leftCoefficient) {
                foreach ($integerFactor as $rightDegree => $rightCoefficient) {
                    $index = $leftDegree + $rightDegree;
                    $term = $integerArithmetic->multiplyIntegerValues(
                        $leftCoefficient,
                        $rightCoefficient,
                    );
                    $product[$index] = $integerArithmetic->addIntegerValues(
                        $product[$index],
                        $term,
                    );
                }
            }
            $poweredFactor = $product;
        }

        if (
            \count($poweredFactor) !== \count($exactCoefficients)
            || $poweredFactor[\array_key_last($poweredFactor)] === '0'
        ) {
            return false;
        }
        $factorLeading = $poweredFactor[\array_key_last($poweredFactor)];
        $polynomialLeading = $exactCoefficients[\array_key_last($exactCoefficients)];
        foreach ($exactCoefficients as $degree => $coefficient) {
            if (
                $integerArithmetic->multiplyIntegerValues($coefficient, $factorLeading)
                !== $integerArithmetic->multiplyIntegerValues(
                    $poweredFactor[$degree],
                    $polynomialLeading,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }

    /** @param list<string> $coefficients */
    private function exactPolynomialHasRepeatedRoots(array $coefficients): ?bool
    {
        $integerArithmetic = new IntegerPolynomialExtractor('', [], \count($coefficients));
        $coefficients = $this->primitiveIntegerPolynomial($coefficients, $integerArithmetic);
        if ($coefficients === null) {
            return null;
        }
        $derivative = [];
        foreach ($coefficients as $power => $coefficient) {
            if ($power > 0) {
                $derivative[] = $integerArithmetic->multiplyIntegerValues(
                    (string) $power,
                    $coefficient,
                );
            }
        }
        $derivative = $this->primitiveIntegerPolynomial($derivative, $integerArithmetic);
        if ($derivative === null) {
            return null;
        }

        $left = $coefficients;
        $right = $derivative;
        $work = 0;
        while ($right !== ['0']) {
            $remainder = $left;
            while (\count($remainder) >= \count($right) && $remainder !== ['0']) {
                if (++$work > 4096) {
                    return null;
                }
                $offset = \count($remainder) - \count($right);
                $leftLeadingIndex = \array_key_last($remainder);
                $rightLeadingIndex = \array_key_last($right);
                if ($leftLeadingIndex === null || $rightLeadingIndex === null) {
                    return null;
                }
                $leftLeading = $remainder[$leftLeadingIndex];
                $rightLeading = $right[$rightLeadingIndex];
                foreach ($remainder as $power => $coefficient) {
                    $scaledCoefficient = $integerArithmetic->multiplyIntegerValues(
                        $coefficient,
                        $rightLeading,
                    );
                    if (\strlen(\ltrim($scaledCoefficient, '-')) > 4096) {
                        return null;
                    }
                    $remainder[$power] = $scaledCoefficient;
                }
                foreach ($right as $power => $coefficient) {
                    $index = $offset + $power;
                    $correction = $integerArithmetic->multiplyIntegerValues(
                        $leftLeading,
                        $coefficient,
                    );
                    if (\strlen(\ltrim($correction, '-')) > 4096) {
                        return null;
                    }
                    $remainder[$index] = $integerArithmetic->subtract(
                        $remainder[$index],
                        $correction,
                    );
                }
                $remainder = $this->primitiveIntegerPolynomial(
                    \array_values($remainder),
                    $integerArithmetic,
                );
                if ($remainder === null) {
                    return null;
                }
            }
            [$left, $right] = [$right, $remainder];
        }

        return \count($left) > 1;
    }

    /**
     * Solve each square-free factor once, then restore its exact multiplicity.
     *
     * @param list<float> $coefficients
     * @param list<string>|null $exactCoefficients
     */
    private function solveSquareFreeDecomposition(
        array $coefficients,
        int $degree,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ?ComplexPolynomialSolution {
        if ($degree < 3 || $exactCoefficients === null || \count($exactCoefficients) !== $degree + 1) {
            return null;
        }

        $arithmetic = new IntegerPolynomialExtractor('', [], $degree);
        $counter = new ExactPolynomialRootCounter();
        $polynomial = $this->primitiveIntegerPolynomial($exactCoefficients, $arithmetic);
        if ($polynomial === null) {
            return null;
        }
        $derivative = [];
        foreach ($polynomial as $power => $coefficient) {
            if ($power > 0) {
                $derivative[] = $arithmetic->multiplyIntegerValues((string) $power, $coefficient);
            }
        }
        $derivative = $this->primitiveIntegerPolynomial($derivative, $arithmetic);
        if ($derivative === null) {
            return null;
        }

        $repeatedPart = $counter->greatestCommonDivisor($polynomial, $derivative);
        if ($repeatedPart === null || \count($repeatedPart) < 2) {
            return null;
        }
        $remaining = $counter->dividePolynomialExactly($polynomial, $repeatedPart);
        if ($remaining === null) {
            return null;
        }

        $factors = [];
        for ($multiplicity = 1; $multiplicity <= $degree; ++$multiplicity) {
            if (\count($remaining) < 2) {
                break;
            }
            $shared = $counter->greatestCommonDivisor($remaining, $repeatedPart);
            if ($shared === null) {
                return null;
            }
            $factor = $counter->dividePolynomialExactly($remaining, $shared);
            if ($factor === null) {
                return null;
            }
            if (\count($factor) > 1) {
                $factors[] = [$factor, $multiplicity];
            }
            $remaining = $shared;
            $repeatedPart = $counter->dividePolynomialExactly($repeatedPart, $shared);
            if ($repeatedPart === null) {
                return null;
            }
        }
        if ($factors === [] || \count($remaining) > 1 || \count($repeatedPart) > 1) {
            return null;
        }
        $decomposedDegree = 0;
        foreach ($factors as [$factor, $multiplicity]) {
            $decomposedDegree += (\count($factor) - 1) * $multiplicity;
        }
        if ($decomposedDegree !== $degree) {
            return null;
        }
        $reconstructed = ['1'];
        foreach ($factors as [$factor, $multiplicity]) {
            for ($copy = 0; $copy < $multiplicity; ++$copy) {
                if (\count($reconstructed) + \count($factor) - 2 > $degree) {
                    return null;
                }
                $product = \array_fill(0, \count($reconstructed) + \count($factor) - 1, '0');
                foreach ($reconstructed as $leftPower => $leftCoefficient) {
                    foreach ($factor as $rightPower => $rightCoefficient) {
                        $term = $arithmetic->multiplyIntegerValues($leftCoefficient, $rightCoefficient);
                        if (\strlen(\ltrim($term, '-')) > 4096) {
                            return null;
                        }
                        $sum = $arithmetic->addIntegerValues(
                            $product[$leftPower + $rightPower],
                            $term,
                        );
                        if (\strlen(\ltrim($sum, '-')) > 4096) {
                            return null;
                        }
                        $product[$leftPower + $rightPower] = $sum;
                    }
                }
                $reconstructed = $this->primitiveIntegerPolynomial(\array_values($product), $arithmetic);
                if ($reconstructed === null) {
                    return null;
                }
            }
        }
        $inputLeadingIndex = \array_key_last($polynomial);
        $reconstructedLeadingIndex = \array_key_last($reconstructed);
        if ($inputLeadingIndex === null || $reconstructedLeadingIndex === null) {
            return null;
        }
        $inputLeading = $polynomial[$inputLeadingIndex];
        $reconstructedLeading = $reconstructed[$reconstructedLeadingIndex];
        foreach ($polynomial as $power => $coefficient) {
            if (
                $arithmetic->multiplyIntegerValues($coefficient, $reconstructedLeading)
                !== $arithmetic->multiplyIntegerValues(
                    $reconstructed[$power] ?? '0',
                    $inputLeading,
                )
            ) {
                return null;
            }
        }

        $roots = [];
        $iterations = 0;
        $converged = true;
        foreach ($factors as [$factor, $multiplicity]) {
            $factorCoefficients = $arithmetic->normalizedCoefficients($factor, true);
            if ($factorCoefficients === null) {
                return null;
            }
            $factorSolution = $this->solve($factorCoefficients, $options, $factor);
            $iterations += $factorSolution->iterations;
            $converged = $converged && $factorSolution->converged;
            foreach ($factorSolution->roots as $root) {
                $residual = $this->normalizedResidual($coefficients, [
                    'real' => $root->real,
                    'imaginary' => $root->imaginary,
                ]);
                if (!\is_finite($residual)) {
                    throw new \DomainException('Square-free polynomial residual is not finite.');
                }
                $converged = $converged && $residual <= $options->residualTolerance;
                for ($copy = 0; $copy < $multiplicity; ++$copy) {
                    $roots[] = new ComplexRoot($root->real, $root->imaginary, $residual);
                }
            }
        }
        if (\count($roots) !== $degree) {
            return null;
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($roots, $degree, $converged, $iterations);
    }

    /**
     * @param list<string> $coefficients
     * @return list<string>|null
     */
    private function primitiveIntegerPolynomial(
        array $coefficients,
        IntegerPolynomialExtractor $integerArithmetic,
    ): ?array {
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === '0') {
            \array_pop($coefficients);
        }
        if ($coefficients === []) {
            return ['0'];
        }

        $content = '0';
        foreach ($coefficients as $coefficient) {
            $content = $integerArithmetic->greatestCommonDivisorValues($content, $coefficient);
        }
        if ($content !== '0' && $content !== '1') {
            foreach ($coefficients as $power => $coefficient) {
                $quotient = $integerArithmetic->divideIntegerValueExactly($coefficient, $content);
                if ($quotient === null) {
                    return null;
                }
                $coefficients[$power] = $quotient;
            }
        }
        $leading = $coefficients[\array_key_last($coefficients)];
        if ($leading[0] === '-') {
            foreach ($coefficients as $power => $coefficient) {
                $coefficients[$power] = $integerArithmetic->negateIntegerValue($coefficient);
            }
        }
        foreach ($coefficients as $coefficient) {
            if (\strlen(\ltrim($coefficient, '-')) > 2048) {
                return null;
            }
        }

        return $coefficients;
    }

    /**
     * @param list<float> $coefficients
     * @return list<float>|null
     */
    private function deflateRealRoot(array $coefficients, float $root, int $multiplicity): ?array
    {
        for ($copy = 0; $copy < $multiplicity; ++$copy) {
            $degree = \count($coefficients) - 1;
            if ($degree < 1) {
                return null;
            }
            $quotientReversed = [$coefficients[$degree]];
            for ($power = $degree - 1; $power >= 1; --$power) {
                $quotientReversed[] = $coefficients[$power]
                    + ($root * $quotientReversed[\array_key_last($quotientReversed)]);
            }
            $quotient = \array_reverse($quotientReversed);
            $remainder = $coefficients[0] + ($root * $quotient[0]);
            $scale = 1.0;
            foreach ($coefficients as $coefficient) {
                $scale = \max($scale, \abs($coefficient));
            }
            $tolerance = 64.0 * \PHP_FLOAT_EPSILON * \count($coefficients) * $scale;
            if (!\is_finite($remainder) || \abs($remainder) > $tolerance) {
                return null;
            }
            $coefficients = $quotient;
        }

        return $coefficients;
    }

    private function binaryNormalizationScale(float $scale): float
    {
        $power = \floor(\log($scale, 2.0));
        $binaryScale = 2.0 ** $power;

        return \is_finite($binaryScale) && $binaryScale > 0.0 ? $binaryScale : $scale;
    }

    /**
     * Initialize roots on the radii predicted by the upper Newton polygon.
     *
     * @param list<float> $coefficients
     * @return list<array{real: float, imaginary: float}>|null
     */
    private function newtonPolygonInitialRoots(array $coefficients): ?array
    {
        $points = [];
        foreach ($coefficients as $power => $coefficient) {
            if ($coefficient !== 0.0) {
                $points[] = [$power, \log(\abs($coefficient))];
            }
        }
        if (\count($points) < 2) {
            return null;
        }

        $hull = [];
        foreach ($points as $point) {
            $hull[] = $point;
            while (\count($hull) >= 3) {
                $last = \count($hull) - 1;
                [$x0, $y0] = $hull[$last - 2];
                [$x1, $y1] = $hull[$last - 1];
                [$x2, $y2] = $hull[$last];
                $leftSlopeNumerator = $y1 - $y0;
                $rightSlopeNumerator = $y2 - $y1;
                if (
                    $leftSlopeNumerator * ($x2 - $x1)
                    > $rightSlopeNumerator * ($x1 - $x0)
                ) {
                    break;
                }
                \array_splice($hull, $last - 1, 1);
            }
        }

        $roots = \array_fill(
            0,
            $points[0][0],
            ['real' => 0.0, 'imaginary' => 0.0],
        );
        foreach ($hull as $index => [$leftPower, $leftLogMagnitude]) {
            if (!isset($hull[$index + 1])) {
                continue;
            }
            [$rightPower, $rightLogMagnitude] = $hull[$index + 1];
            $multiplicity = $rightPower - $leftPower;
            $logRadius = -($rightLogMagnitude - $leftLogMagnitude) / $multiplicity;
            $radius = \exp($logRadius);
            if ($radius === 0.0 || !\is_finite($radius)) {
                return null;
            }
            for ($rootIndex = 0; $rootIndex < $multiplicity; ++$rootIndex) {
                $angle = (2.0 * \M_PI * ($rootIndex + 0.2718281828459045)) / $multiplicity;
                $roots[] = [
                    'real' => $radius * \cos($angle),
                    'imaginary' => $radius * \sin($angle),
                ];
            }
        }

        return \count($roots) === \count($coefficients) - 1 ? $roots : null;
    }

    /** @param list<array{real: float, imaginary: float}> $roots */
    private function rootsHaveSeparatedScales(array $roots): bool
    {
        $minimumLogRadius = \INF;
        $maximumLogRadius = -\INF;
        foreach ($roots as $root) {
            $radius = \hypot($root['real'], $root['imaginary']);
            if ($radius === 0.0) {
                continue;
            }
            if (!\is_finite($radius)) {
                return true;
            }
            $logRadius = \log($radius);
            $minimumLogRadius = \min($minimumLogRadius, $logRadius);
            $maximumLogRadius = \max($maximumLogRadius, $logRadius);
        }

        return $maximumLogRadius - $minimumLogRadius > \log(1.0E8);
    }

    /**
     * @param list<float> $coefficients
     * @param list<array{real: float, imaginary: float}> $roots
     */
    private function newtonPolygonSeedsAreTighterThanCauchyBound(
        array $coefficients,
        array $roots,
    ): bool {
        $degree = \count($coefficients) - 1;
        $leading = \abs($coefficients[$degree]);
        if ($leading === 0.0) {
            return false;
        }
        $maximumLower = 0.0;
        foreach ($coefficients as $index => $coefficient) {
            if ($index < $degree) {
                $maximumLower = \max($maximumLower, \abs($coefficient));
            }
        }
        $cauchyRadius = 1.0 + ($maximumLower / $leading);
        if (!\is_finite($cauchyRadius)) {
            return false;
        }
        $polygonRadius = 0.0;
        foreach ($roots as $root) {
            $polygonRadius = \max($polygonRadius, \hypot($root['real'], $root['imaginary']));
        }

        return \is_finite($polygonRadius) && $polygonRadius * 1_000.0 < $cauchyRadius;
    }

    /**
     * Aberth iteration with each candidate evaluated in its own normalized
     * coordinate, preventing large powers and distant root groups from
     * overflowing intermediate values.
     *
     * @param list<float> $coefficients
     * @param list<array{real: float, imaginary: float}> $roots
     * @return array{list<array{real: float, imaginary: float}>, bool, int}
     */
    private function iterateAberthScaled(
        array $coefficients,
        array $roots,
        SolverOptions $options,
        bool $relativeScaling = false,
    ): array {
        $converged = false;
        $iterations = 0;
        $degree = \count($coefficients) - 1;
        for ($iteration = 1; $iteration <= $options->maxIterations; ++$iteration) {
            $next = $roots;
            $validIteration = true;
            $allCorrectionsSmall = true;
            foreach ($roots as $index => $root) {
                $radius = \hypot($root['real'], $root['imaginary']);
                if (!$relativeScaling) {
                    $radius = \max(1.0, $radius);
                } elseif ($radius === 0.0) {
                    $radius = 1.0;
                }
                if (!\is_finite($radius)) {
                    $validIteration = false;
                    break;
                }
                $coordinate = ['real' => $root['real'] / $radius, 'imaginary' => $root['imaginary'] / $radius];
                [$value, $derivative] = $this->evaluateScaledWithDerivative(
                    $coefficients,
                    $coordinate,
                    $radius,
                    $relativeScaling,
                );
                if (\hypot($derivative['real'], $derivative['imaginary']) === 0.0) {
                    $validIteration = false;
                    break;
                }
                $newtonStep = $this->divide($value, $derivative);
                $reciprocalSum = ['real' => 0.0, 'imaginary' => 0.0];
                foreach ($roots as $otherIndex => $otherRoot) {
                    if ($otherIndex === $index) {
                        continue;
                    }
                    $otherCoordinate = [
                        'real' => $otherRoot['real'] / $radius,
                        'imaginary' => $otherRoot['imaginary'] / $radius,
                    ];
                    if (
                        !\is_finite($otherCoordinate['real'])
                        || !\is_finite($otherCoordinate['imaginary'])
                    ) {
                        continue;
                    }
                    $difference = $this->subtract($coordinate, $otherCoordinate);
                    if (\hypot($difference['real'], $difference['imaginary']) === 0.0) {
                        $validIteration = false;
                        break 2;
                    }
                    $reciprocalSum = $this->add(
                        $reciprocalSum,
                        $this->divide(['real' => 1.0, 'imaginary' => 0.0], $difference),
                    );
                }

                $aberthDenominator = $this->subtract(
                    ['real' => 1.0, 'imaginary' => 0.0],
                    $this->multiply($newtonStep, $reciprocalSum),
                );
                if (\hypot($aberthDenominator['real'], $aberthDenominator['imaginary']) === 0.0) {
                    $validIteration = false;
                    break;
                }
                $scaledCorrection = $this->divide($newtonStep, $aberthDenominator);
                $correction = [
                    'real' => $radius * $scaledCorrection['real'],
                    'imaginary' => $radius * $scaledCorrection['imaginary'],
                ];
                $next[$index] = $this->subtract($root, $correction);
                if (
                    !\is_finite($next[$index]['real'])
                    || !\is_finite($next[$index]['imaginary'])
                ) {
                    $validIteration = false;
                    break;
                }
                $change = \hypot($correction['real'], $correction['imaginary']);
                $correctionTolerance = $relativeScaling
                    ? $options->absoluteTolerance + $options->relativeTolerance
                    : $options->absoluteTolerance
                        + $options->relativeTolerance * \max(1.0, $radius);
                $measuredChange = $relativeScaling
                    ? \hypot($scaledCorrection['real'], $scaledCorrection['imaginary'])
                    : $change;
                $allCorrectionsSmall = $allCorrectionsSmall && $measuredChange <= $correctionTolerance;
            }
            if (!$validIteration) {
                break;
            }
            $roots = $next;
            $iterations = $iteration;
            if ($allCorrectionsSmall) {
                $converged = true;
                break;
            }
        }

        return [$roots, $converged, $iterations];
    }

    /**
     * @param list<float> $coefficients
     * @param array{real: float, imaginary: float} $coordinate
     * @return array{array{real: float, imaginary: float}, array{real: float, imaginary: float}}
     */
    private function evaluateScaledWithDerivative(
        array $coefficients,
        array $coordinate,
        float $scale,
        bool $logNormalize = false,
    ): array {
        $degree = \count($coefficients) - 1;
        $scaledCoefficients = \array_fill(0, $degree + 1, 0.0);
        if ($logNormalize) {
            $maximumLogMagnitude = -\INF;
            $logMagnitudes = [];
            $logScale = \log($scale);
            foreach ($coefficients as $power => $coefficient) {
                if ($coefficient === 0.0) {
                    $logMagnitudes[$power] = -\INF;
                    continue;
                }
                $logMagnitude = \log(\abs($coefficient)) + ($power * $logScale);
                $logMagnitudes[$power] = $logMagnitude;
                $maximumLogMagnitude = \max($maximumLogMagnitude, $logMagnitude);
            }
            foreach ($coefficients as $power => $coefficient) {
                $scaledCoefficients[$power] = $coefficient === 0.0
                    ? 0.0
                    : ($coefficient < 0.0 ? -1.0 : 1.0)
                        * \exp($logMagnitudes[$power] - $maximumLogMagnitude);
            }
        } else {
            $coefficientScale = 1.0;
            for ($power = $degree; $power >= 0; --$power) {
                $scaledCoefficients[$power] = $coefficients[$power] * $coefficientScale;
                $coefficientScale /= $scale;
            }
        }

        $value = ['real' => $scaledCoefficients[$degree], 'imaginary' => 0.0];
        $derivative = ['real' => 0.0, 'imaginary' => 0.0];
        for ($power = $degree - 1; $power >= 0; --$power) {
            $derivative = $this->add(
                $this->multiply($derivative, $coordinate),
                $value,
            );
            $value = $this->add(
                $this->multiply($value, $coordinate),
                ['real' => $scaledCoefficients[$power], 'imaginary' => 0.0],
            );
        }

        return [$value, $derivative];
    }

    /**
     * @param list<float> $coefficients
     */
    private function solvePerfectPowerPolynomial(
        array $coefficients,
        int $degree,
        SolverOptions $options,
    ): ?ComplexPolynomialSolution {
        if ($degree < 4) {
            return null;
        }

        for ($multiplicity = 2; $multiplicity <= intdiv($degree, 2); ++$multiplicity) {
            if (!$this->isPrime($multiplicity)) {
                continue;
            }

            $factorDegree = intdiv($degree, $multiplicity);
            if ($factorDegree < 2 || $factorDegree * $multiplicity !== $degree) {
                continue;
            }

            $sign = $coefficients[$degree] < 0.0 ? -1.0 : 1.0;
            $leadingFactor = \pow(\abs($coefficients[$degree]), 1.0 / $multiplicity);
            $leadingDerivative = $multiplicity
                * \pow($leadingFactor, $multiplicity - 1);
            if (
                !\is_finite($leadingFactor)
                || $leadingFactor === 0.0
                || !\is_finite($leadingDerivative)
                || $leadingDerivative === 0.0
            ) {
                continue;
            }

            $factorReversed = [$leadingFactor];
            for ($reverseDegree = 1; $reverseDegree <= $factorDegree; ++$reverseDegree) {
                $knownPower = $this->polynomialPowerCoefficients(
                    [...$factorReversed, 0.0],
                    $multiplicity,
                    $reverseDegree,
                );
                $factorCoefficient = (
                    ($sign * $coefficients[$degree - $reverseDegree])
                    - $knownPower[$reverseDegree]
                ) / $leadingDerivative;
                if (!\is_finite($factorCoefficient)) {
                    continue 2;
                }
                $factorReversed[] = $factorCoefficient;
            }
            $factor = \array_reverse($factorReversed);
            $reconstructed = $this->polynomialPowerCoefficients(
                $factor,
                $multiplicity,
                $degree,
            );
            $reconstructionTolerance = 8.0 * \PHP_FLOAT_EPSILON * $degree;
            foreach ($coefficients as $power => $coefficient) {
                $reconstructedCoefficient = $sign * $reconstructed[$power];
                if (
                    !\is_finite($reconstructedCoefficient)
                    || \abs($coefficient - $reconstructedCoefficient)
                        > $reconstructionTolerance
                            * \max(1.0, \abs($coefficient), \abs($reconstructedCoefficient))
                ) {
                    continue 2;
                }
            }

            $factorSolution = $this->solve($factor, $options);
            $roots = [];
            $converged = $factorSolution->converged;
            foreach ($factorSolution->roots as $root) {
                $residual = $this->normalizedResidual(
                    $coefficients,
                    ['real' => $root->real, 'imaginary' => $root->imaginary],
                );
                if (!\is_finite($residual)) {
                    throw new \DomainException('Repeated-root residual is not finite.');
                }
                $converged = $converged && $residual <= $options->residualTolerance;
                for ($copy = 0; $copy < $multiplicity; ++$copy) {
                    $roots[] = new ComplexRoot($root->real, $root->imaginary, $residual);
                }
            }
            \usort(
                $roots,
                static fn (ComplexRoot $left, ComplexRoot $right): int =>
                    [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
            );

            return new ComplexPolynomialSolution(
                $roots,
                $degree,
                $converged,
                $factorSolution->iterations,
            );
        }

        return null;
    }

    /**
     * @param list<float> $coefficients
     * @return list<float>
     */
    private function polynomialPowerCoefficients(
        array $coefficients,
        int $exponent,
        int $maximumDegree,
    ): array {
        $result = [1.0];
        for ($power = 0; $power < $exponent; ++$power) {
            $product = [];
            $corrections = [];
            for ($degree = 0; $degree <= $maximumDegree; ++$degree) {
                $product[] = 0.0;
                $corrections[] = 0.0;
            }
            for ($degree = 0; $degree <= $maximumDegree; ++$degree) {
                for ($leftDegree = 0; $leftDegree <= $degree; ++$leftDegree) {
                    $rightDegree = $degree - $leftDegree;
                    if (
                        isset($result[$leftDegree])
                        && isset($coefficients[$rightDegree])
                    ) {
                        $sum = $product[$degree];
                        $term = $result[$leftDegree] * $coefficients[$rightDegree];
                        $next = $sum + $term;
                        $corrections[$degree] += \abs($sum) >= \abs($term)
                            ? ($sum - $next) + $term
                            : ($term - $next) + $sum;
                        $product[$degree] = $next;
                    }
                }
            }
            $result = \array_map(
                static fn (float $coefficient, float $correction): float => $coefficient + $correction,
                $product,
                $corrections,
            );
        }

        return $result;
    }

    private function isPrime(int $value): bool
    {
        for ($divisor = 2; $divisor * $divisor <= $value; ++$divisor) {
            if ($value % $divisor === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<float> $coefficients
     * @param list<array{real: float, imaginary: float}> $roots
     * @return array{list<array{real: float, imaginary: float}>, bool, int}
     */
    private function iterateAberth(
        array $coefficients,
        array $roots,
        SolverOptions $options,
        float $radius,
    ): array {
        $converged = false;
        $iterations = 0;
        $degree = \count($roots);
        for ($iteration = 1; $iteration <= $options->maxIterations; ++$iteration) {
            $next = $roots;
            $largestChange = 0.0;
            $validIteration = true;
            foreach ($roots as $index => $root) {
                $value = $this->evaluate($coefficients, $root);
                $derivative = $this->evaluateDerivative($coefficients, $root);
                $derivativeMagnitude = \hypot($derivative['real'], $derivative['imaginary']);
                if ($derivativeMagnitude === 0.0 || !\is_finite($derivativeMagnitude)) {
                    $validIteration = false;
                    break;
                }
                $newtonStep = $this->divide($value, $derivative);
                $reciprocalSum = ['real' => 0.0, 'imaginary' => 0.0];
                foreach ($roots as $otherIndex => $otherRoot) {
                    if ($otherIndex === $index) {
                        continue;
                    }
                    $difference = $this->subtract($root, $otherRoot);
                    $differenceMagnitude = \hypot($difference['real'], $difference['imaginary']);
                    if ($differenceMagnitude === 0.0 || !\is_finite($differenceMagnitude)) {
                        $validIteration = false;
                        break 2;
                    }
                    $reciprocalSum = $this->add(
                        $reciprocalSum,
                        $this->divide(['real' => 1.0, 'imaginary' => 0.0], $difference),
                    );
                }
                $denominator = $this->subtract(
                    ['real' => 1.0, 'imaginary' => 0.0],
                    $this->multiply($newtonStep, $reciprocalSum),
                );
                $denominatorMagnitude = \hypot($denominator['real'], $denominator['imaginary']);
                if ($denominatorMagnitude === 0.0 || !\is_finite($denominatorMagnitude)) {
                    $validIteration = false;
                    break;
                }
                $correction = $this->divide($newtonStep, $denominator);
                $candidate = $this->subtract($root, $correction);
                $change = \hypot($correction['real'], $correction['imaginary']);
                if (
                    !\is_finite($candidate['real'])
                    || !\is_finite($candidate['imaginary'])
                    || !\is_finite($change)
                ) {
                    $validIteration = false;
                    break;
                }
                $next[$index] = $candidate;
                $largestChange = \max($largestChange, $change);
            }
            if (!$validIteration) {
                break;
            }
            $roots = $next;
            $iterations = $iteration;
            if ($largestChange <= $options->absoluteTolerance + $options->relativeTolerance * $radius) {
                $converged = true;
                break;
            }
        }

        return [$roots, $converged, $iterations];
    }

    /**
     * @param list<float> $coefficients Coefficients in ascending degree order.
     * @param list<string>|null $exactCoefficients
     */
    private function solveQuadratic(
        array $coefficients,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ComplexPolynomialSolution {
        $constant = $coefficients[0];
        $linear = $coefficients[1];
        $quadratic = $coefficients[2];
        $coefficientScale = \max(\abs($constant), \abs($linear), \abs($quadratic));
        if ($coefficientScale === 0.0) {
            throw new \LogicException('A quadratic polynomial must have a nonzero coefficient.');
        }
        $constant /= $coefficientScale;
        $linear /= $coefficientScale;
        $quadratic /= $coefficientScale;
        $discriminant = $this->compensatedQuadraticDiscriminant($constant, $linear, $quadratic)
            ?? (($linear * $linear) - (4.0 * $quadratic * $constant));
        $exactRealRoots = null;
        $exactDiscriminantUnderflow = false;
        if ($exactCoefficients !== null && \count($exactCoefficients) === 3) {
            $integerArithmetic = new IntegerPolynomialExtractor('', [], 2);
            $squareLinear = $integerArithmetic->multiplyIntegerValues(
                $exactCoefficients[1],
                $exactCoefficients[1],
            );
            $fourTimesProduct = $integerArithmetic->multiplyIntegerValues(
                $integerArithmetic->multiplyIntegerValues(
                    $exactCoefficients[2],
                    $exactCoefficients[0],
                ),
                '4',
            );
            $exactDiscriminant = $integerArithmetic->subtract($squareLinear, $fourTimesProduct);
            $exactSquareRoot = $integerArithmetic->integerSquareRoot($exactDiscriminant);
            if (
                $exactSquareRoot !== null
                && $integerArithmetic->multiplyIntegerValues($exactSquareRoot, $exactSquareRoot)
                    === $exactDiscriminant
            ) {
                $signedSquareRoot = $exactCoefficients[1][0] === '-'
                    ? $integerArithmetic->negateIntegerValue($exactSquareRoot)
                    : $exactSquareRoot;
                $qNumerator = $integerArithmetic->negateIntegerValue(
                    $integerArithmetic->addIntegerValues($exactCoefficients[1], $signedSquareRoot),
                );
                $exactQ = $integerArithmetic->divideIntegerValueExactly($qNumerator, '2');
                if ($exactQ !== null && $exactQ !== '0') {
                    $firstRoot = $integerArithmetic->ratio($exactQ, $exactCoefficients[2]);
                    $secondRoot = $integerArithmetic->ratio($exactCoefficients[0], $exactQ);
                    if (\is_float($firstRoot) && \is_float($secondRoot)) {
                        $exactRealRoots = [$firstRoot, $secondRoot];
                    }
                }
            }
            $coefficientMagnitude = '0';
            foreach ($exactCoefficients as $coefficient) {
                $magnitude = \ltrim($coefficient, '-');
                if (
                    \strlen($magnitude) > \strlen($coefficientMagnitude)
                    || (
                        \strlen($magnitude) === \strlen($coefficientMagnitude)
                        && \strcmp($magnitude, $coefficientMagnitude) > 0
                    )
                ) {
                    $coefficientMagnitude = $magnitude;
                }
            }
            $scaleSquared = $integerArithmetic->multiplyIntegerValues(
                $coefficientMagnitude,
                $coefficientMagnitude,
            );
            $exactDiscriminantRatio = $integerArithmetic->ratio($exactDiscriminant, $scaleSquared);
            $discriminantSign = $integerArithmetic->sign($exactDiscriminant);
            if (\is_float($exactDiscriminantRatio)) {
                if ($exactDiscriminantRatio === 0.0 && $discriminantSign !== 0) {
                    $discriminant = $discriminantSign * \PHP_FLOAT_MIN;
                    $exactDiscriminantUnderflow = $exactRealRoots === null;
                } else {
                    $discriminant = $discriminantSign * \abs($exactDiscriminantRatio);
                }
            } elseif ($discriminantSign === 0) {
                $discriminant = 0.0;
            } elseif ($discriminant === 0.0) {
                $discriminant = $discriminantSign * \PHP_FLOAT_MIN;
                $exactDiscriminantUnderflow = $exactRealRoots === null;
            } else {
                $discriminant = $discriminantSign * \abs($discriminant);
            }
            if ($exactRealRoots === null && $exactDiscriminant[0] !== '-') {
                $twiceQuadratic = $integerArithmetic->multiplyIntegerValues(
                    $exactCoefficients[2],
                    '2',
                );
                $quadraticSquared = $integerArithmetic->multiplyIntegerValues(
                    $exactCoefficients[2],
                    $exactCoefficients[2],
                );
                $centerRatio = $integerArithmetic->ratio($exactCoefficients[1], $twiceQuadratic);
                $discriminantOverQuadraticSquared = $integerArithmetic->ratio(
                    $exactDiscriminant,
                    $quadraticSquared,
                );
                if (
                    \is_float($centerRatio)
                    && \is_float($discriminantOverQuadraticSquared)
                    && $discriminantOverQuadraticSquared >= 0.0
                ) {
                    $center = -$centerRatio;
                    $halfSeparation = \sqrt($discriminantOverQuadraticSquared) / 2.0;
                    if ($center === 0.0 || $halfSeparation <= 0.5 * \abs($center)) {
                        if (\abs($center) <= 4_503_599_627_370_496.0) {
                            $centerBase = (string) (int) \round($center);
                            $centerRemainder = $integerArithmetic->subtract(
                                $integerArithmetic->negateIntegerValue($exactCoefficients[1]),
                                $integerArithmetic->multiplyIntegerValues(
                                    $twiceQuadratic,
                                    $centerBase,
                                ),
                            );
                            $centerOffset = $integerArithmetic->ratio($centerRemainder, $twiceQuadratic);
                            if (\is_float($centerOffset)) {
                                $centerBaseValue = (float) $centerBase;
                                $exactRealRoots = [
                                    $centerBaseValue + ($centerOffset - $halfSeparation),
                                    $centerBaseValue + ($centerOffset + $halfSeparation),
                                ];
                            }
                        } else {
                            $exactRealRoots = [
                                $center - $halfSeparation,
                                $center + $halfSeparation,
                            ];
                        }
                    }
                }
            }
        }

        if (!\is_finite($discriminant)) {
            throw new \DomainException('The quadratic discriminant is not representable.');
        }

        if ($exactRealRoots !== null) {
            $candidates = [
                ['real' => $exactRealRoots[0], 'imaginary' => 0.0],
                ['real' => $exactRealRoots[1], 'imaginary' => 0.0],
            ];
        } elseif ($discriminant < 0.0) {
            $real = -$linear / (2.0 * $quadratic);
            $imaginary = \sqrt(-$discriminant) / (2.0 * \abs($quadratic));
            $candidates = [
                ['real' => $real, 'imaginary' => -$imaginary],
                ['real' => $real, 'imaginary' => $imaginary],
            ];
        } else {
            $squareRoot = \sqrt($discriminant);
            $q = -0.5 * ($linear + ($linear >= 0.0 ? $squareRoot : -$squareRoot));
            if ($q === 0.0) {
                $root = -$linear / (2.0 * $quadratic);
                $candidates = [
                    ['real' => $root, 'imaginary' => 0.0],
                    ['real' => $root, 'imaginary' => 0.0],
                ];
            } else {
                $candidates = [
                    ['real' => $q / $quadratic, 'imaginary' => 0.0],
                    ['real' => $constant / $q, 'imaginary' => 0.0],
                ];
            }
        }

        \usort(
            $candidates,
            static fn (array $left, array $right): int =>
                [$left['real'], $left['imaginary']] <=> [$right['real'], $right['imaginary']],
        );
        $roots = [];
        $converged = !$exactDiscriminantUnderflow;
        foreach ($candidates as $candidate) {
            if (!\is_finite($candidate['real']) || !\is_finite($candidate['imaginary'])) {
                throw new \DomainException('A quadratic root is not representable as a finite complex number.');
            }
            $residual = $this->normalizedResidual($coefficients, $candidate);
            if (!\is_finite($residual)) {
                throw new \DomainException('Quadratic root residual is not finite.');
            }
            $roots[] = new ComplexRoot($candidate['real'], $candidate['imaginary'], $residual);
            $converged = $converged && $residual <= $options->residualTolerance;
        }

        return new ComplexPolynomialSolution($roots, 2, $converged, 0);
    }

    private function compensatedQuadraticDiscriminant(
        float $constant,
        float $linear,
        float $quadratic,
    ): ?float {
        $linearSquare = $linear * $linear;
        $fourQuadratic = 4.0 * $quadratic;
        $fourQuadraticConstant = $fourQuadratic * $constant;
        $linearError = $this->productError($linear, $linear, $linearSquare);
        $constantError = $this->productError(
            $fourQuadratic,
            $constant,
            $fourQuadraticConstant,
        );
        if ($linearError === null || $constantError === null) {
            return null;
        }

        [$difference, $sumError] = $this->twoSum($linearSquare, -$fourQuadraticConstant);
        $tail = ($sumError + $linearError) - $constantError;
        [$high, $low] = $this->twoSum($difference, $tail);
        $discriminant = $high + $low;
        if ($discriminant === 0.0 && $low !== 0.0) {
            $discriminant = $low;
        }

        return \is_finite($discriminant) ? $discriminant : null;
    }

    /** @return array{float, float} */
    private function twoSum(float $left, float $right): array
    {
        $sum = $left + $right;
        $rightVirtual = $sum - $left;
        $leftVirtual = $sum - $rightVirtual;
        $rightRoundoff = $right - $rightVirtual;
        $leftRoundoff = $left - $leftVirtual;

        return [$sum, $leftRoundoff + $rightRoundoff];
    }

    private function productError(float $left, float $right, float $product): ?float
    {
        if (!\is_finite($product)) {
            return null;
        }
        if ($left === 0.0 || $right === 0.0) {
            return 0.0;
        }

        $leftParts = $this->splitForProduct($left);
        $rightParts = $this->splitForProduct($right);
        if ($leftParts === null || $rightParts === null) {
            return null;
        }
        [$leftHigh, $leftLow] = $leftParts;
        [$rightHigh, $rightLow] = $rightParts;
        $error = (($leftHigh * $rightHigh - $product) + ($leftHigh * $rightLow))
            + (($leftLow * $rightHigh) + ($leftLow * $rightLow));

        return \is_finite($error) ? $error : 0.0;
    }

    /** @return array{float, float}|null */
    private function splitForProduct(float $value): ?array
    {
        $splitter = 134_217_729.0;
        $scale = \abs($value) > (\PHP_FLOAT_MAX / $splitter)
            ? 2.0 ** -28
            : 1.0;
        $scaledValue = $value * $scale;
        $split = $splitter * $scaledValue;
        if (!\is_finite($split)) {
            return null;
        }

        $high = ($split - ($split - $scaledValue)) / $scale;
        $low = ($scaledValue - ($split - ($split - $scaledValue))) / $scale;
        if (!\is_finite($high) || !\is_finite($low)) {
            return [$value, 0.0];
        }

        return [$high, $low];
    }

    /**
     * Reduce P(x) = x^offset * Q(x^step) before root iteration when all
     * nonzero powers lie on a common exponent lattice.
     *
     * @param list<float> $coefficients
     * @param list<string>|null $exactCoefficients
     */
    private function solveExponentLatticePolynomial(
        array $coefficients,
        int $degree,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ?ComplexPolynomialSolution {
        $nonzeroDegrees = [];
        foreach ($coefficients as $power => $coefficient) {
            if ($coefficient !== 0.0) {
                $nonzeroDegrees[] = $power;
            }
        }
        if (\count($nonzeroDegrees) < 3) {
            return null;
        }

        $offset = $nonzeroDegrees[0];
        $step = 0;
        foreach ($nonzeroDegrees as $power) {
            $step = $this->greatestCommonDivisor($step, $power - $offset);
        }
        if ($step < 1 || ($step === 1 && $offset === 0)) {
            return null;
        }

        $reducedDegree = intdiv($degree - $offset, $step);
        $reducedCoefficients = \array_fill(0, $reducedDegree + 1, 0.0);
        $reducedExactCoefficients = $exactCoefficients === null
            ? null
            : \array_fill(0, $reducedDegree + 1, '0');
        for ($reducedPower = 0; $reducedPower <= $reducedDegree; ++$reducedPower) {
            $sourcePower = $offset + ($reducedPower * $step);
            $reducedCoefficients[$reducedPower] = $coefficients[$sourcePower] ?? 0.0;
            if ($reducedExactCoefficients !== null) {
                $reducedExactCoefficients[$reducedPower] = $exactCoefficients[$sourcePower] ?? '0';
            }
        }

        $reduced = $this->solve(
            \array_values($reducedCoefficients),
            $options,
            $reducedExactCoefficients === null
                ? null
                : \array_values($reducedExactCoefficients),
        );
        $roots = \array_fill(0, $offset, new ComplexRoot(0.0, 0.0, 0.0));
        $converged = $reduced->converged;
        foreach ($reduced->roots as $reducedRoot) {
            $magnitude = \hypot($reducedRoot->real, $reducedRoot->imaginary);
            if ($magnitude === 0.0) {
                for ($branch = 0; $branch < $step; ++$branch) {
                    $roots[] = new ComplexRoot(0.0, 0.0, $reducedRoot->residual);
                }
                continue;
            }
            $rootMagnitude = \exp(\log($magnitude) / $step);
            if (!\is_finite($rootMagnitude) || $rootMagnitude === 0.0) {
                throw new \DomainException('A polynomial root is not representable as a finite complex number.');
            }
            $baseAngle = \atan2($reducedRoot->imaginary, $reducedRoot->real);
            for ($branch = 0; $branch < $step; ++$branch) {
                $angle = ($baseAngle + (2.0 * \M_PI * $branch)) / $step;
                $realDirection = \cos($angle);
                $imaginaryDirection = \sin($angle);
                if (\abs($realDirection) <= 4.0 * \PHP_FLOAT_EPSILON) {
                    $realDirection = 0.0;
                }
                if (\abs($imaginaryDirection) <= 4.0 * \PHP_FLOAT_EPSILON) {
                    $imaginaryDirection = 0.0;
                }
                $root = [
                    'real' => $rootMagnitude * $realDirection,
                    'imaginary' => $rootMagnitude * $imaginaryDirection,
                ];
                if (!\is_finite($root['real']) || !\is_finite($root['imaginary'])) {
                    throw new \DomainException(
                        'A polynomial root is not representable as a finite complex number.',
                    );
                }
                $residual = $this->normalizedResidual($coefficients, $root);
                if (!\is_finite($residual)) {
                    throw new \DomainException('Complex polynomial residual is not finite.');
                }
                $converged = $converged && $residual <= $options->residualTolerance;
                $roots[] = new ComplexRoot($root['real'], $root['imaginary'], $residual);
            }
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($roots, $degree, $converged, $reduced->iterations);
    }

    /** @param list<float> $coefficients */
    private function solveSparsePolynomial(
        array $coefficients,
        int $degree,
        SolverOptions $options,
    ): ?ComplexPolynomialSolution {
        $nonzeroDegrees = [];
        foreach ($coefficients as $power => $coefficient) {
            if ($coefficient !== 0.0) {
                $nonzeroDegrees[] = $power;
            }
        }
        if (\count($nonzeroDegrees) > 2) {
            return null;
        }

        if ($nonzeroDegrees === [$degree]) {
            return new ComplexPolynomialSolution(
                \array_fill(0, $degree, new ComplexRoot(0.0, 0.0, 0.0)),
                $degree,
                true,
                0,
            );
        }
        if (
            \count($nonzeroDegrees) !== 2
            || $nonzeroDegrees[1] !== $degree
        ) {
            return null;
        }

        $offset = $nonzeroDegrees[0];
        $binomialDegree = $degree - $offset;
        $constant = $coefficients[$offset];
        $leading = $coefficients[$degree];
        $logRadius = (\log(\abs($constant)) - \log(\abs($leading))) / $binomialDegree;
        $radius = \exp($logRadius);
        if ($radius === 0.0 || !\is_finite($radius)) {
            throw new \DomainException('A finite binomial root radius could not be represented.');
        }
        $ratioIsNegative = ($constant < 0.0) === ($leading < 0.0);
        $baseAngle = $ratioIsNegative ? \M_PI : 0.0;
        $roots = [];
        $converged = true;
        for ($index = 0; $index < $offset; ++$index) {
            $roots[] = new ComplexRoot(0.0, 0.0, 0.0);
        }
        for ($index = 0; $index < $binomialDegree; ++$index) {
            $angle = ($baseAngle + (2.0 * \M_PI * $index)) / $binomialDegree;
            $realDirection = \cos($angle);
            $imaginaryDirection = \sin($angle);
            if (\abs($realDirection) <= 4.0 * \PHP_FLOAT_EPSILON) {
                $realDirection = 0.0;
            }
            if (\abs($imaginaryDirection) <= 4.0 * \PHP_FLOAT_EPSILON) {
                $imaginaryDirection = 0.0;
            }
            $root = [
                'real' => $radius * $realDirection,
                'imaginary' => $radius * $imaginaryDirection,
            ];
            $residual = $this->normalizedResidual($coefficients, $root);
            if (!\is_finite($residual)) {
                throw new \DomainException('Complex binomial residual is not finite.');
            }
            $roots[] = new ComplexRoot($root['real'], $root['imaginary'], $residual);
            $converged = $converged && $residual <= $options->residualTolerance;
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($roots, $degree, $converged, 0);
    }

    /**
     * @param list<float> $coefficients
     * @param list<string>|null $exactCoefficients
     */
    private function solveRepeatedLinearFactor(
        array $coefficients,
        int $degree,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ?ComplexPolynomialSolution {
        if ($degree < 3 || $coefficients[$degree] === 0.0) {
            return null;
        }
        $root = -$coefficients[$degree - 1] / ($degree * $coefficients[$degree]);
        if (!\is_finite($root)) {
            return null;
        }

        if ($exactCoefficients !== null) {
            if (\count($exactCoefficients) !== $degree + 1) {
                return null;
            }
            $integerArithmetic = new IntegerPolynomialExtractor('', [], $degree);
            $denominator = $integerArithmetic->multiplyIntegerValues(
                (string) $degree,
                $exactCoefficients[$degree],
            );
            $exactRoot = $integerArithmetic->ratio(
                $integerArithmetic->negateIntegerValue($exactCoefficients[$degree - 1]),
                $denominator,
            );
            if (
                !\is_float($exactRoot)
                || !$this->isExactRepeatedLinearFactor(
                    $exactCoefficients,
                    $degree,
                    $integerArithmetic,
                    $denominator,
                )
            ) {
                return null;
            }
            $root = $exactRoot;
        }

        $expected = [$coefficients[$degree]];
        for ($power = 0; $power < $degree; ++$power) {
            $next = \array_fill(0, \count($expected) + 1, 0.0);
            foreach ($expected as $index => $coefficient) {
                $next[$index] += -$root * $coefficient;
                $next[$index + 1] += $coefficient;
                if (!\is_finite($next[$index]) || !\is_finite($next[$index + 1])) {
                    return null;
                }
            }
            $expected = $next;
        }
        if ($exactCoefficients === null) {
            $reconstructionTolerance = 8.0 * \PHP_FLOAT_EPSILON * $degree;
            for ($power = 0; $power <= $degree; ++$power) {
                $actual = $coefficients[$power] ?? 0.0;
                $reconstructed = $expected[$power] ?? 0.0;
                if (
                    \abs($actual - $reconstructed)
                        > $reconstructionTolerance * \max(1.0, \abs($actual), \abs($reconstructed))
                ) {
                    return null;
                }
            }
        }

        $residual = $this->normalizedResidual(
            $coefficients,
            ['real' => $root, 'imaginary' => 0.0],
        );
        if (!\is_finite($residual)) {
            throw new \DomainException('Repeated-root residual is not finite.');
        }
        $roots = \array_fill(0, $degree, new ComplexRoot($root, 0.0, $residual));

        return new ComplexPolynomialSolution(
            $roots,
            $degree,
            $residual <= $options->residualTolerance,
            0,
        );
    }

    /**
     * @param list<string> $coefficients
     */
    private function isExactRepeatedLinearFactor(
        array $coefficients,
        int $degree,
        IntegerPolynomialExtractor $integerArithmetic,
        string $denominator,
    ): bool {
        $rootNumerator = $coefficients[$degree - 1];
        $binomial = '1';
        for ($power = 0; $power <= $degree; ++$power) {
            $remainingPower = $degree - $power;
            $left = $coefficients[$power];
            $right = $coefficients[$degree];
            $denominatorPower = '1';
            $numeratorPower = '1';
            for ($index = 0; $index < $remainingPower; ++$index) {
                $denominatorPower = $integerArithmetic->multiplyIntegerValues(
                    $denominatorPower,
                    $denominator,
                );
                $numeratorPower = $integerArithmetic->multiplyIntegerValues(
                    $numeratorPower,
                    $rootNumerator,
                );
            }
            $left = $integerArithmetic->multiplyIntegerValues($left, $denominatorPower);
            $right = $integerArithmetic->multiplyIntegerValues(
                $integerArithmetic->multiplyIntegerValues($right, $binomial),
                $numeratorPower,
            );
            if ($left !== $right) {
                return false;
            }
            if ($power < $degree) {
                $binomialNumerator = $integerArithmetic->multiplyIntegerValues(
                    $binomial,
                    (string) ($degree - $power),
                );
                $nextBinomial = $integerArithmetic->divideIntegerValueExactly(
                    $binomialNumerator,
                    (string) ($power + 1),
                );
                if ($nextBinomial === null) {
                    return false;
                }
                $binomial = $nextBinomial;
            }
        }

        return true;
    }

    /**
     * @param list<float> $coefficients
     * @param list<string>|null $exactCoefficients
     */
    private function solveRepeatedQuadraticFactor(
        array $coefficients,
        int $degree,
        SolverOptions $options,
        ?array $exactCoefficients,
    ): ?ComplexPolynomialSolution {
        if ($degree < 4 || $degree % 2 !== 0 || $coefficients[$degree] === 0.0) {
            return null;
        }

        $multiplicity = intdiv($degree, 2);
        $leading = $coefficients[$degree];
        $linear = $coefficients[$degree - 1] / ($multiplicity * $leading);
        $quadratic = (
            ($coefficients[$degree - 2] / $leading)
            - (($multiplicity * ($multiplicity - 1) / 2.0) * $linear * $linear)
        ) / $multiplicity;
        if (!\is_finite($linear) || !\is_finite($quadratic)) {
            return null;
        }

        $expected = [$leading];
        for ($factor = 0; $factor < $multiplicity; ++$factor) {
            $next = \array_fill(0, \count($expected) + 2, 0.0);
            foreach ($expected as $power => $coefficient) {
                $next[$power] += $quadratic * $coefficient;
                $next[$power + 1] += $linear * $coefficient;
                $next[$power + 2] += $coefficient;
                if (
                    !\is_finite($next[$power])
                    || !\is_finite($next[$power + 1])
                    || !\is_finite($next[$power + 2])
                ) {
                    return null;
                }
            }
            $expected = $next;
        }

        if (
            $exactCoefficients !== null
            && !$this->exactPolynomialPowerMatches(
                $exactCoefficients,
                [$quadratic, $linear, 1.0],
                $multiplicity,
            )
        ) {
            return null;
        }

        $reconstructionTolerance = 8.0 * \PHP_FLOAT_EPSILON * $degree;
        for ($power = 0; $power <= $degree; ++$power) {
            $actual = $coefficients[$power] ?? 0.0;
            $reconstructed = $expected[$power] ?? 0.0;
            if (
                \abs($actual - $reconstructed)
                    > $reconstructionTolerance * \max(1.0, \abs($actual), \abs($reconstructed))
            ) {
                return null;
            }
        }

        $discriminant = $this->compensatedQuadraticDiscriminant($quadratic, $linear, 1.0)
            ?? (($linear * $linear) - (4.0 * $quadratic));
        if (!\is_finite($discriminant)) {
            return null;
        }
        if ($discriminant >= 0.0) {
            $squareRoot = \sqrt($discriminant);
            $q = -0.5 * ($linear + ($linear < 0.0 ? -$squareRoot : $squareRoot));
            if ($q === 0.0) {
                $pair = [
                    ['real' => -$linear / 2.0, 'imaginary' => 0.0],
                    ['real' => -$linear / 2.0, 'imaginary' => 0.0],
                ];
            } else {
                $pair = [
                    ['real' => $q, 'imaginary' => 0.0],
                    ['real' => $quadratic / $q, 'imaginary' => 0.0],
                ];
            }
        } else {
            $pair = [
                ['real' => -$linear / 2.0, 'imaginary' => -\sqrt(-$discriminant) / 2.0],
                ['real' => -$linear / 2.0, 'imaginary' => \sqrt(-$discriminant) / 2.0],
            ];
        }

        $roots = [];
        $converged = true;
        foreach ($pair as $root) {
            $residual = $this->normalizedResidual($coefficients, $root);
            if (!\is_finite($residual)) {
                throw new \DomainException('Repeated quadratic residual is not finite.');
            }
            for ($copy = 0; $copy < $multiplicity; ++$copy) {
                $roots[] = new ComplexRoot($root['real'], $root['imaginary'], $residual);
            }
            $converged = $converged && $residual <= $options->residualTolerance;
        }
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution($roots, $degree, $converged, 0);
    }

    /**
     * @param list<float> $coefficients
     * @param array{real: float, imaginary: float} $root
     * @return array{real: float, imaginary: float}
     */
    private function polish(array $coefficients, array $root, SolverOptions $options): array
    {
        for ($iteration = 0; $iteration < 16; ++$iteration) {
            $value = $this->evaluate($coefficients, $root);
            $derivative = $this->evaluateDerivative($coefficients, $root);
            $derivativeMagnitude = \hypot($derivative['real'], $derivative['imaginary']);
            if ($derivativeMagnitude === 0.0 || !\is_finite($derivativeMagnitude)) {
                break;
            }
            $delta = $this->divide($value, $derivative);
            $change = \hypot($delta['real'], $delta['imaginary']);
            $candidate = $this->subtract($root, $delta);
            if (
                !\is_finite($change)
                || !\is_finite($candidate['real'])
                || !\is_finite($candidate['imaginary'])
            ) {
                break;
            }
            $root = $candidate;
            if (
                $change <= $options->absoluteTolerance
                    + ($options->relativeTolerance * \max(1.0, \hypot($root['real'], $root['imaginary'])))
            ) {
                break;
            }
        }

        return $root;
    }

    /** @param list<float> $coefficients
     *  @param array{real: float, imaginary: float} $value
     *  @return array{real: float, imaginary: float}
     */
    private function evaluate(array $coefficients, array $value): array
    {
        $result = ['real' => 0.0, 'imaginary' => 0.0];
        for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
            $result = $this->add(
                $this->multiply($result, $value),
                ['real' => $coefficients[$power], 'imaginary' => 0.0],
            );
            if (!\is_finite($result['real']) || !\is_finite($result['imaginary'])) {
                throw new \DomainException('Complex polynomial evaluation overflowed.');
            }
        }

        return $result;
    }

    /**
     * @param list<float> $coefficients
     * @param array{real: float, imaginary: float} $value
     */
    private function normalizedResidual(array $coefficients, array $value, bool $logNormalize = false): float
    {
        $radius = \hypot($value['real'], $value['imaginary']);
        if (!\is_finite($radius)) {
            return \INF;
        }
        $scale = \max(1.0, $radius);
        $scaledValue = [
            'real' => $value['real'] / $scale,
            'imaginary' => $value['imaginary'] / $scale,
        ];
        $scaledRadius = $radius / $scale;
        $coefficientScale = 1.0;
        $requiresLogScaling = $logNormalize;
        for ($power = \count($coefficients) - 1; !$requiresLogScaling && $power >= 0; --$power) {
            if (
                $coefficients[$power] !== 0.0
                && $coefficients[$power] * $coefficientScale === 0.0
            ) {
                $requiresLogScaling = true;
                break;
            }
            $coefficientScale /= $scale;
            if ($coefficientScale === 0.0) {
                for ($lowerPower = $power - 1; $lowerPower >= 0; --$lowerPower) {
                    if ($coefficients[$lowerPower] !== 0.0) {
                        $requiresLogScaling = true;
                        break 2;
                    }
                }
            }
        }
        if (!$requiresLogScaling) {
            $coefficientScale = 1.0;
            $polynomialValue = ['real' => 0.0, 'imaginary' => 0.0];
            $magnitudeBound = 0.0;
            for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
                $scaledCoefficient = $coefficients[$power] * $coefficientScale;
                $polynomialValue = $this->add(
                    $this->multiply($polynomialValue, $scaledValue),
                    ['real' => $scaledCoefficient, 'imaginary' => 0.0],
                );
                $magnitudeBound = ($magnitudeBound * $scaledRadius) + \abs($scaledCoefficient);
                $coefficientScale /= $scale;
            }
            $residual = \hypot($polynomialValue['real'], $polynomialValue['imaginary']);
            $denominator = \max($coefficientScale, $magnitudeBound);

            return $denominator === 0.0
                ? ($residual === 0.0 ? 0.0 : \INF)
                : $residual / $denominator;
        }

        $logScale = \log($scale);
        $logMagnitudes = [];
        $maximumLogMagnitude = -\INF;
        foreach ($coefficients as $power => $coefficient) {
            if ($coefficient === 0.0) {
                $logMagnitudes[$power] = -\INF;
                continue;
            }
            $logMagnitude = \log(\abs($coefficient)) + ($power * $logScale);
            $logMagnitudes[$power] = $logMagnitude;
            $maximumLogMagnitude = \max($maximumLogMagnitude, $logMagnitude);
        }

        $polynomialValue = ['real' => 0.0, 'imaginary' => 0.0];
        $magnitudeBound = 0.0;
        for ($power = \count($coefficients) - 1; $power >= 0; --$power) {
            $scaledCoefficient = $logMagnitudes[$power] === -\INF
                ? 0.0
                : ($coefficients[$power] < 0.0 ? -1.0 : 1.0)
                    * \exp($logMagnitudes[$power] - $maximumLogMagnitude);
            $polynomialValue = $this->add(
                $this->multiply($polynomialValue, $scaledValue),
                ['real' => $scaledCoefficient, 'imaginary' => 0.0],
            );
            $magnitudeBound = ($magnitudeBound * $scaledRadius) + \abs($scaledCoefficient);
        }
        $residual = \hypot($polynomialValue['real'], $polynomialValue['imaginary']);

        return $magnitudeBound === 0.0
            ? ($residual === 0.0 ? 0.0 : \INF)
            : $residual / $magnitudeBound;
    }

    /** @param list<float> $coefficients
     *  @param array{real: float, imaginary: float} $value
     *  @return array{real: float, imaginary: float}
     */
    private function evaluateDerivative(array $coefficients, array $value): array
    {
        $result = ['real' => 0.0, 'imaginary' => 0.0];
        for ($power = \count($coefficients) - 1; $power >= 1; --$power) {
            $result = $this->add(
                $this->multiply($result, $value),
                ['real' => $power * $coefficients[$power], 'imaginary' => 0.0],
            );
        }

        return $result;
    }

    /** @param array{real: float, imaginary: float} $left
     *  @param array{real: float, imaginary: float} $right
     *  @return array{real: float, imaginary: float}
     */
    private function add(array $left, array $right): array
    {
        return [
            'real' => $left['real'] + $right['real'],
            'imaginary' => $left['imaginary'] + $right['imaginary'],
        ];
    }

    /** @param array{real: float, imaginary: float} $left
     *  @param array{real: float, imaginary: float} $right
     *  @return array{real: float, imaginary: float}
     */
    private function subtract(array $left, array $right): array
    {
        return [
            'real' => $left['real'] - $right['real'],
            'imaginary' => $left['imaginary'] - $right['imaginary'],
        ];
    }

    /** @param array{real: float, imaginary: float} $left
     *  @param array{real: float, imaginary: float} $right
     *  @return array{real: float, imaginary: float}
     */
    private function multiply(array $left, array $right): array
    {
        return [
            'real' => ($left['real'] * $right['real']) - ($left['imaginary'] * $right['imaginary']),
            'imaginary' => ($left['real'] * $right['imaginary']) + ($left['imaginary'] * $right['real']),
        ];
    }

    /** @param array{real: float, imaginary: float} $left
     *  @param array{real: float, imaginary: float} $right
     *  @return array{real: float, imaginary: float}
     */
    private function divide(array $left, array $right): array
    {
        if (\abs($right['real']) >= \abs($right['imaginary'])) {
            $ratio = $right['imaginary'] / $right['real'];
            $denominator = $right['real'] + ($right['imaginary'] * $ratio);

            return [
                'real' => ($left['real'] + ($left['imaginary'] * $ratio)) / $denominator,
                'imaginary' => ($left['imaginary'] - ($left['real'] * $ratio)) / $denominator,
            ];
        }
        $ratio = $right['real'] / $right['imaginary'];
        $denominator = $right['imaginary'] + ($right['real'] * $ratio);

        return [
            'real' => (($left['real'] * $ratio) + $left['imaginary']) / $denominator,
            'imaginary' => (($left['imaginary'] * $ratio) - $left['real']) / $denominator,
        ];
    }
}
