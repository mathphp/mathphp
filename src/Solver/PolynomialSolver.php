<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\RationalLiteral;

final class PolynomialSolver
{
    private const MAX_REPEATED_ROOT_UNIFORM_SAMPLE_WORK = 1_000_000;

    private const REPEATED_ROOT_GEOMETRIC_EXPONENT_STEP = 8;

    /** @param list<float> $coefficients */
    public function evaluatePolynomial(array $coefficients, float $value): ?float
    {
        return $this->evaluate($coefficients, $value);
    }

    /** @param list<float> $coefficients */
    public function evaluatePolynomialMagnitude(array $coefficients, float $value): ?float
    {
        return $this->evaluationMagnitude($coefficients, $value);
    }

    private function residualIsWithinTolerance(
        float $residual,
        float $scale,
        float $tolerance,
    ): bool {
        return \is_finite($residual)
            && \is_finite($scale)
            && $scale > 0.0
            && \abs($residual / $scale) <= $tolerance;
    }

    /**
     * Isolate real roots by recursively partitioning at derivative roots.
     * A null result means that numeric stability was insufficient to certify
     * the candidates and the caller should use its general numeric search.
     *
     * @param list<float> $coefficients
     * @param callable(float): ?float $residualAt
     * @param callable(float): ?float|null $residualScaleAt
     * @return list<EquationSolution>|null
     */
    public function solve(
        array $coefficients,
        float $lowerBound,
        float $upperBound,
        callable $residualAt,
        SolverOptions $options,
        ?callable $residualScaleAt = null,
    ): ?array {
        if (
            \is_nan($lowerBound)
            || \is_nan($upperBound)
            || $lowerBound > $upperBound
            || $lowerBound === \INF
            || $upperBound === -\INF
        ) {
            throw new \InvalidArgumentException(
                'The solving interval must have nondecreasing bounds with infinities only at its open ends.',
            );
        }

        if ($coefficients === []) {
            throw new \InvalidArgumentException(
                'A polynomial must contain at least one coefficient.',
            );
        }

        foreach ($coefficients as $coefficient) {
            if (!\is_finite($coefficient)) {
                return null;
            }
        }

        $coefficients = $this->trim($coefficients);
        if (\count($coefficients) === 1) {
            if ($coefficients[0] === 0.0) {
                throw new \DomainException(
                    'Equation has every real number as a solution and cannot be returned as a finite root list.',
                );
            }

            return [];
        }

        if (\count($coefficients) === 2) {
            $root = -($coefficients[0] / $coefficients[1]);
            if (!\is_finite($root)) {
                return null;
            }
            if ($root < $lowerBound || $root > $upperBound) {
                return [];
            }
            $residual = $residualAt($root);
            $residualScale = $residualScaleAt === null
                ? $this->evaluationMagnitude($coefficients, $root)
                : $residualScaleAt($root);
            $residualAccepted = $residual !== null
                && $residualScale !== null
                && \is_finite($residualScale)
                && $residualScale >= 0.0
                && $this->residualIsWithinTolerance(
                    $residual,
                    \max(1.0, $residualScale),
                    $options->residualTolerance,
                );
            if (!$residualAccepted) {
                return null;
            }

            return [new EquationSolution($root == 0.0 ? 0.0 : $root, \abs($residual), 0)];
        }

        $coefficientScale = 0.0;
        foreach ($coefficients as $coefficient) {
            $coefficientScale = \max($coefficientScale, \abs($coefficient));
        }
        if ($coefficientScale === 0.0 || !\is_finite($coefficientScale)) {
            return null;
        }
        $normalizationScale = $this->powerOfTwoScale($coefficientScale);
        foreach ($coefficients as $index => $coefficient) {
            $normalizedCoefficient = $coefficient / $normalizationScale;
            if ($coefficient !== 0.0 && $normalizedCoefficient === 0.0) {
                return null;
            }
            $coefficients[$index] = $normalizedCoefficient;
        }

        if (\count($coefficients) === 3 && $coefficients[2] !== 0.0) {
            $vertex = -($coefficients[1] / (2.0 * $coefficients[2]));
            $vertexResidual = \is_finite($vertex) && $vertex >= $lowerBound && $vertex <= $upperBound
                ? $residualAt($vertex)
                : null;
            $vertexScale = $vertexResidual === null
                ? null
                : ($residualScaleAt === null
                    ? $this->evaluationMagnitude($coefficients, $vertex)
                    : $residualScaleAt($vertex));
            if (
                $vertexResidual !== null
                && $vertexScale !== null
                && \is_finite($vertexScale)
                && $vertexScale >= 0.0
                && $this->residualIsWithinTolerance(
                    $vertexResidual,
                    \max(1.0, $vertexScale),
                    \min($options->residualTolerance, 64.0 * \PHP_FLOAT_EPSILON),
                )
            ) {
                return [new EquationSolution($vertex == 0.0 ? 0.0 : $vertex, \abs($vertexResidual), 0)];
            }
        }

        $quadraticRoots = $this->quadraticRoots($coefficients);
        if ($quadraticRoots !== null) {
            $solutions = [];
            foreach ($quadraticRoots as $root) {
                if ($root < $lowerBound || $root > $upperBound) {
                    continue;
                }
                $residual = $residualAt($root);
                $residualScale = $residualScaleAt === null ? null : $residualScaleAt($root);
                if ($residual === null && $residualScaleAt === null) {
                    $polynomialResidual = $this->evaluate($coefficients, $root);
                    if ($polynomialResidual !== null) {
                        $residual = $polynomialResidual;
                        $residualScale = $this->evaluationMagnitude($coefficients, $root);
                    }
                }
                $hasValidResidualScale = $residualScale !== null
                    && \is_finite($residualScale)
                    && $residualScale >= 0.0;
                $residualAccepted = $residual === null
                    ? false
                    : ($residualScaleAt !== null
                        ? $hasValidResidualScale
                            && $this->residualIsWithinTolerance(
                                $residual,
                                \max(1.0, $residualScale),
                                $options->residualTolerance,
                            )
                        : ($hasValidResidualScale
                            ? $this->residualIsWithinTolerance(
                                $residual,
                                \max(1.0, $residualScale),
                                $options->residualTolerance,
                            )
                            : $this->residualIsWithinTolerance(
                                $residual,
                                $coefficientScale,
                                $options->residualTolerance,
                            )));
                if (!$residualAccepted) {
                    return null;
                }

                $solutions[] = new EquationSolution(
                    $root == 0.0 ? 0.0 : $root,
                    \abs($residual),
                    0,
                );
            }

            return $solutions;
        }

        $coordinateCenter = 0.0;
        $coordinateScale = 1.0;
        $workingCoefficients = $coefficients;
        $workingLowerBound = $lowerBound;
        $workingUpperBound = $upperBound;
        $workingResidualAt = $residualAt;
        $workingResidualScaleAt = $residualScaleAt;
        $normalizedCoordinates = false;
        if (\count($coefficients) >= 5 && \count($coefficients) <= 9) {
            $coordinateCenter = ($lowerBound / 2.0) + ($upperBound / 2.0);
            $coordinateScale = ($upperBound / 2.0) - ($lowerBound / 2.0);
            $normalizedCoefficients = $this->transformToNormalizedInterval(
                $coefficients,
                $coordinateCenter,
                $coordinateScale,
            );
            if ($normalizedCoefficients !== null && $coordinateScale > 0.0) {
                $workingCoefficients = $normalizedCoefficients;
                $workingLowerBound = -1.0;
                $workingUpperBound = 1.0;
                $normalizedCoordinates = true;
                $workingResidualAt = static function (float $value) use (
                    $residualAt,
                    $coordinateCenter,
                    $coordinateScale,
                ): ?float {
                    return $residualAt($coordinateCenter + ($coordinateScale * $value));
                };
                if ($residualScaleAt !== null) {
                    $workingResidualScaleAt = static function (float $value) use (
                        $residualScaleAt,
                        $coordinateCenter,
                        $coordinateScale,
                    ): ?float {
                        return $residualScaleAt(
                            $coordinateCenter + ($coordinateScale * $value),
                        );
                    };
                }
            }
        }
        $isolated = $this->isolate(
            $workingCoefficients,
            $workingLowerBound,
            $workingUpperBound,
            $options,
        );
        if ($isolated === null) {
            return null;
        }

        $solutions = [];
        foreach ($isolated as [$root, $iterations, $isBracketed]) {
            if (!$isBracketed) {
                $root = $this->refineRoot(
                    $workingCoefficients,
                    $root,
                    $workingLowerBound,
                    $workingUpperBound,
                    $options->maxIterations,
                );
            }
            if (!$this->hasFlatDerivative($workingCoefficients, $root)) {
                $root = $this->refineAgainstResidual(
                    $workingCoefficients,
                    $coefficientScale,
                    $root,
                    $workingResidualAt,
                    $workingLowerBound,
                    $workingUpperBound,
                );
            }
            $residual = $workingResidualAt($root);
            $residualScale = $workingResidualScaleAt === null
                ? null
                : $workingResidualScaleAt($root);
            $residualAccepted = $residual === null
                ? false
                : ($residualScaleAt === null
                    ? $this->residualIsWithinTolerance(
                        $residual,
                        $coefficientScale,
                        $options->residualTolerance,
                    )
                    : $residualScale !== null
                        && \is_finite($residualScale)
                        && $residualScale >= 0.0
                        && $this->residualIsWithinTolerance(
                            $residual,
                            \max(1.0, $residualScale),
                            $options->residualTolerance,
                        ));
            if (!$residualAccepted) {
                return null;
            }

            $this->addUnique(
                $solutions,
                new EquationSolution($root == 0.0 ? 0.0 : $root, \abs($residual), $iterations),
                $options,
                $workingCoefficients,
                $isBracketed,
            );
        }

        if ($normalizedCoordinates) {
            foreach ($solutions as $index => $solution) {
                $root = $coordinateCenter + ($coordinateScale * $solution->value);
                if (!\is_finite($root)) {
                    return null;
                }
                $residual = $residualAt($root);
                $residualScale = $residualScaleAt === null ? null : $residualScaleAt($root);
                $residualAccepted = $residual === null
                    ? false
                    : ($residualScaleAt === null
                        ? $this->residualIsWithinTolerance(
                            $residual,
                            $coefficientScale,
                            $options->residualTolerance,
                        )
                        : $residualScale !== null
                            && \is_finite($residualScale)
                            && $residualScale >= 0.0
                            && $this->residualIsWithinTolerance(
                                $residual,
                                \max(1.0, $residualScale),
                                $options->residualTolerance,
                            ));
                if (!$residualAccepted) {
                    return null;
                }
                $solutions[$index] = new EquationSolution(
                    $root == 0.0 ? 0.0 : $root,
                    \abs($residual),
                    $solution->iterations,
                );
            }
        }

        \usort(
            $solutions,
            static fn (EquationSolution $left, EquationSolution $right): int =>
                $left->value <=> $right->value,
        );

        return $solutions;
    }

    /**
     * Transform P(x) to P(center + scale * t) using Horner composition.
     *
     * @param list<float> $coefficients
     * @return list<float>|null
     */
    private function transformToNormalizedInterval(
        array $coefficients,
        float $center,
        float $scale,
    ): ?array {
        if (
            $coefficients === []
            || !\is_finite($center)
            || !\is_finite($scale)
            || $scale <= 0.0
        ) {
            return null;
        }

        $transformed = [$coefficients[\count($coefficients) - 1]];
        for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
            $next = \array_fill(0, \count($transformed) + 1, 0.0);
            foreach ($transformed as $index => $coefficient) {
                $centerProduct = $center * $coefficient;
                $scaleProduct = $scale * $coefficient;
                if (
                    ($center !== 0.0 && $coefficient !== 0.0 && $centerProduct === 0.0)
                    || ($scale !== 0.0 && $coefficient !== 0.0 && $scaleProduct === 0.0)
                ) {
                    return null;
                }
                $next[$index] += $centerProduct;
                $next[$index + 1] += $scaleProduct;
                if (!\is_finite($next[$index]) || !\is_finite($next[$index + 1])) {
                    return null;
                }
            }
            $next[0] += $coefficients[$degree];
            if (!\is_finite($next[0])) {
                return null;
            }
            $transformed = \array_values($next);
        }

        return $this->trim($transformed);
    }

    /**
     * Solve a quadratic using a compensated discriminant. Returns null when
     * the discriminant cannot be resolved and the general isolator should run.
     *
     * @param list<float> $coefficients
     * @return list<float>|null
     */
    private function quadraticRoots(array $coefficients): ?array
    {
        if (\count($coefficients) !== 3) {
            return null;
        }

        [$constant, $linear, $quadratic] = $coefficients;
        $variableScaleExponent = 0;
        $quadraticExponent = (int) \floor(\log(\abs($quadratic), 2.0));
        if ($constant !== 0.0) {
            $constantExponent = (int) \floor(\log(\abs($constant), 2.0));
            $variableScaleExponent = (int) \floor(($constantExponent - $quadraticExponent) / 2.0);
        }
        if ($linear !== 0.0) {
            $linearExponent = (int) \floor(\log(\abs($linear), 2.0));
            $variableScaleExponent = \max(
                $variableScaleExponent,
                $linearExponent - $quadraticExponent,
            );
        }
        $variableScaleExponent = \max(-1022, \min(1023, $variableScaleExponent));
        $variableScale = 2.0 ** $variableScaleExponent;
        if ($variableScaleExponent !== 0) {
            $scaledQuadratic = $this->scaleByPowerOfTwo(
                $quadratic,
                2 * $variableScaleExponent,
            );
            $scaledLinear = $this->scaleByPowerOfTwo($linear, $variableScaleExponent);
            $scale = \max(\abs($constant), \abs($scaledLinear), \abs($scaledQuadratic));
            if (
                !\is_finite($scaledQuadratic)
                || !\is_finite($scaledLinear)
                || $scaledQuadratic === 0.0
                || ($linear !== 0.0 && $scaledLinear === 0.0)
                || $scale === 0.0
                || !\is_finite($scale)
            ) {
                return null;
            }
            $scaledConstant = $constant / $scale;
            $normalizedLinear = $scaledLinear / $scale;
            $normalizedQuadratic = $scaledQuadratic / $scale;
            if (
                ($constant !== 0.0 && $scaledConstant === 0.0)
                || ($scaledLinear !== 0.0 && $normalizedLinear === 0.0)
                || ($scaledQuadratic !== 0.0 && $normalizedQuadratic === 0.0)
            ) {
                return null;
            }
            $constant = $scaledConstant;
            $linear = $normalizedLinear;
            $quadratic = $normalizedQuadratic;
        }
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
        [$discriminantHigh, $discriminantLow] = $this->twoSum($difference, $tail);
        $discriminant = $discriminantHigh + $discriminantLow;
        if ($discriminant === 0.0 && $discriminantLow !== 0.0) {
            $discriminant = $discriminantLow;
        }
        if (!\is_finite($discriminant)) {
            return null;
        }
        if ($discriminant < 0.0) {
            $discriminantScale = \max(
                \abs($linearSquare),
                \abs($fourQuadraticConstant),
            );
            if (
                $discriminantScale > 0.0
                && \abs($discriminant)
                    <= 8.0 * \PHP_FLOAT_EPSILON * $discriminantScale
            ) {
                return $this->exactQuadraticDiscriminantSign($constant, $linear, $quadratic) === -1
                    ? []
                    : null;
            }

            return [];
        }

        if ($discriminant === 0.0) {
            $unscaledRoot = -$linear / (2.0 * $quadratic);
            $root = $unscaledRoot * $variableScale;

            return \is_finite($root) && !($unscaledRoot !== 0.0 && $root === 0.0)
                ? [$root]
                : null;
        }

        $signedRoot = \sqrt($discriminant);
        $stableNumerator = -0.5 * ($linear + ($linear < 0.0 ? -$signedRoot : $signedRoot));
        if ($stableNumerator === 0.0) {
            $unscaledRoot = -$linear / (2.0 * $quadratic);
            $root = $unscaledRoot * $variableScale;

            return \is_finite($root) && !($unscaledRoot !== 0.0 && $root === 0.0)
                ? [$root]
                : null;
        }

        $unscaledFirstRoot = $stableNumerator / $quadratic;
        $unscaledSecondRoot = $constant / $stableNumerator;
        if (
            ($unscaledFirstRoot === 0.0)
            || ($constant !== 0.0 && $unscaledSecondRoot === 0.0)
        ) {
            return null;
        }
        $firstRoot = $unscaledFirstRoot * $variableScale;
        $secondRoot = $unscaledSecondRoot * $variableScale;
        if (!\is_finite($firstRoot) || !\is_finite($secondRoot)) {
            return null;
        }
        if (
            ($unscaledFirstRoot !== 0.0 && $firstRoot === 0.0)
            || ($unscaledSecondRoot !== 0.0 && $secondRoot === 0.0)
        ) {
            return null;
        }

        if ($firstRoot === $secondRoot) {
            return [$firstRoot];
        }

        return $firstRoot < $secondRoot
            ? [$firstRoot, $secondRoot]
            : [$secondRoot, $firstRoot];
    }

    private function exactQuadraticDiscriminantSign(float $constant, float $linear, float $quadratic): ?int
    {
        $constantFraction = RationalLiteral::fromFloatExactAsStrings($constant);
        $linearFraction = RationalLiteral::fromFloatExactAsStrings($linear);
        $quadraticFraction = RationalLiteral::fromFloatExactAsStrings($quadratic);
        if ($constantFraction === null || $linearFraction === null || $quadraticFraction === null) {
            return null;
        }

        $integerArithmetic = new IntegerPolynomialExtractor('', [], 1);
        [$constantNumerator, $constantDenominator] = $constantFraction;
        [$linearNumerator, $linearDenominator] = $linearFraction;
        [$quadraticNumerator, $quadraticDenominator] = $quadraticFraction;
        $linearSquare = $integerArithmetic->multiplyIntegerValues($linearNumerator, $linearNumerator);
        $left = $integerArithmetic->multiplyIntegerValues(
            $integerArithmetic->multiplyIntegerValues($linearSquare, $constantDenominator),
            $quadraticDenominator,
        );
        $right = $integerArithmetic->multiplyIntegerValues(
            $integerArithmetic->multiplyIntegerValues(
                $integerArithmetic->multiplyIntegerValues('4', $constantNumerator),
                $quadraticNumerator,
            ),
            $integerArithmetic->multiplyIntegerValues($linearDenominator, $linearDenominator),
        );

        return $integerArithmetic->sign($integerArithmetic->subtract($left, $right));
    }

    /**
     * Recover repeated roots when the full polynomial isolation is
     * inconclusive but its derivative still isolates near-zero critical points.
     *
     * @param list<float> $coefficients
     * @param list<EquationSolution> $solutions
     * @param callable(float): ?float $residualAt
     * @param callable(float): ?float $residualScaleAt
     * @return list<EquationSolution>
     */
    public function recoverRepeatedRoots(
        array $coefficients,
        array $solutions,
        float $lowerBound,
        float $upperBound,
        callable $residualAt,
        callable $residualScaleAt,
        SolverOptions $options,
    ): array {
        $scale = 0.0;
        foreach ($coefficients as $coefficient) {
            $scale = \max($scale, \abs($coefficient));
        }
        if ($scale === 0.0 || !\is_finite($scale)) {
            return $solutions;
        }
        $normalizationScale = $this->powerOfTwoScale($scale);
        $normalized = \array_map(
            static fn (float $coefficient): float => $coefficient / $normalizationScale,
            $coefficients,
        );
        foreach ($coefficients as $index => $coefficient) {
            if ($coefficient !== 0.0 && $normalized[$index] === 0.0) {
                return $solutions;
            }
        }
        $derivative = [];
        for ($degree = 1; $degree < \count($normalized); ++$degree) {
            $derivative[] = $degree * $normalized[$degree];
        }
        if (\count($derivative) <= 1) {
            return $solutions;
        }

        $criticalPoints = $this->solve(
            $derivative,
            $lowerBound,
            $upperBound,
            fn (float $value): ?float => $this->evaluate($derivative, $value),
            $options,
            fn (float $value): ?float => $this->evaluationMagnitude($derivative, $value),
        );
        $sampledCriticalPoints = $this->sampleCriticalPoints(
            $derivative,
            $lowerBound,
            $upperBound,
            $options,
        );
        if ($criticalPoints === null) {
            $criticalPoints = $sampledCriticalPoints;
        } else {
            foreach ($sampledCriticalPoints as $sampledCriticalPoint) {
                $this->appendDistinctRoot($criticalPoints, $sampledCriticalPoint, $options);
            }
        }

        foreach ($criticalPoints as $criticalPoint) {
            $root = $criticalPoint->value;
            $uncertainty = \max(
                $options->positionTolerance($root, $root),
                $this->criticalRootUncertainty($normalized, $root),
            );
            $polynomialValue = $this->evaluate($normalized, $root);
            if (
                $polynomialValue === null
                || !$this->isZeroAt($normalized, $root, $polynomialValue)
            ) {
                continue;
            }

            $residual = $residualAt($root);
            $residualScale = $residualScaleAt($root);
            if (
                $residual === null
                || !\is_finite($residual)
                || $residualScale === null
                || !\is_finite($residualScale)
                || $residualScale < 0.0
            ) {
                continue;
            }
            $polynomialMagnitude = $this->evaluationMagnitude($normalized, $root);
            if ($polynomialMagnitude === null) {
                continue;
            }
            $residualMagnitude = \abs($residual);
            if ($polynomialMagnitude > \PHP_FLOAT_MAX / $scale) {
                $normalizedResidual = ($residualMagnitude / $scale) / $polynomialMagnitude;
            } else {
                $residualScale = \max(
                    1.0,
                    $residualScale,
                    $polynomialMagnitude * $scale,
                    $residualMagnitude,
                );
                $normalizedResidual = $residualMagnitude / $residualScale;
            }
            if ($normalizedResidual > $options->residualTolerance + (8.0 * \PHP_FLOAT_EPSILON)) {
                continue;
            }

            $hasNearbyRootBelow = false;
            $hasNearbyRootAbove = false;
            $nearestSolutionIndex = null;
            $nearestSolutionDistance = \INF;
            foreach ($solutions as $index => $solution) {
                $distance = \abs($solution->value - $root);
                if ($distance > $uncertainty) {
                    continue;
                }
                $hasNearbyRootBelow = $hasNearbyRootBelow || $solution->value < $root;
                $hasNearbyRootAbove = $hasNearbyRootAbove || $solution->value > $root;
                if ($distance < $nearestSolutionDistance) {
                    $nearestSolutionDistance = $distance;
                    $nearestSolutionIndex = $index;
                }
            }
            if ($hasNearbyRootBelow && $hasNearbyRootAbove) {
                continue;
            }

            $recovered = new EquationSolution($root, \abs($residual), $criticalPoint->iterations);
            $updated = [];
            $inserted = false;
            foreach ($solutions as $index => $solution) {
                if ($index === $nearestSolutionIndex) {
                    $updated[] = $recovered;
                    $inserted = true;
                    continue;
                }
                $updated[] = $solution;
            }
            if (!$inserted) {
                $updated[] = $recovered;
            }
            $solutions = $updated;
        }

        return $solutions;
    }

    /**
     * Find derivative roots from sign changes when recursive isolation is
     * inconclusive. This is used only to recover repeated-root candidates.
     *
     * @param list<float> $coefficients
     * @return list<EquationSolution>
     */
    private function sampleCriticalPoints(
        array $coefficients,
        float $lowerBound,
        float $upperBound,
        SolverOptions $options,
    ): array {
        $roots = [];
        if (\count($coefficients) <= 1) {
            return $roots;
        }

        $degree = \count($coefficients) - 1;
        $maximumSampleSteps = \max(
            16,
            intdiv(self::MAX_REPEATED_ROOT_UNIFORM_SAMPLE_WORK, $degree * $degree),
        );
        $sampleSteps = \min($options->scanSteps, $maximumSampleSteps);
        $unboundedInterval = !\is_finite($lowerBound) || !\is_finite($upperBound);
        if ($unboundedInterval) {
            $leadingCoefficient = \abs($coefficients[\array_key_last($coefficients)] ?? 0.0);
            if ($leadingCoefficient === 0.0 || !\is_finite($leadingCoefficient)) {
                return $roots;
            }
            $maximumRatio = 0.0;
            foreach (\array_slice($coefficients, 0, -1) as $coefficient) {
                $ratio = \abs($coefficient) / $leadingCoefficient;
                if (!\is_finite($ratio)) {
                    // Any finite candidate still lies inside the float domain.
                    $ratio = \PHP_FLOAT_MAX;
                }
                $maximumRatio = \max($maximumRatio, $ratio);
            }
            $rootBound = 1.0 + ($maximumRatio * (1.0 + (16.0 * \PHP_FLOAT_EPSILON)));
            if (!\is_finite($rootBound)) {
                $rootBound = \PHP_FLOAT_MAX;
            }
            $lowerBound = \max($lowerBound, -$rootBound);
            $upperBound = \min($upperBound, $rootBound);
            if ($lowerBound > $upperBound) {
                return $roots;
            }
        }

        $previousPosition = null;
        $previousValue = null;
        $span = $upperBound - $lowerBound;
        $samplePositions = [];
        for ($index = 0; $index <= $sampleSteps; ++$index) {
            $fraction = $index / $sampleSteps;
            $position = \is_finite($span)
                ? $lowerBound + ($span * $fraction)
                : (($lowerBound * (1.0 - $fraction)) + ($upperBound * $fraction));
            $samplePositions[] = $position;
        }
        if ($unboundedInterval) {
            for (
                $exponent = -1074;
                $exponent <= 1023;
                $exponent += self::REPEATED_ROOT_GEOMETRIC_EXPONENT_STEP
            ) {
                $position = 2.0 ** $exponent;
                foreach ([$position, -$position] as $geometricPosition) {
                    if (
                        $geometricPosition >= $lowerBound
                        && $geometricPosition <= $upperBound
                    ) {
                        $samplePositions[] = $geometricPosition;
                    }
                }
            }
            foreach ([\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX] as $geometricPosition) {
                if ($geometricPosition >= $lowerBound && $geometricPosition <= $upperBound) {
                    $samplePositions[] = $geometricPosition;
                }
            }
        }
        \sort($samplePositions, SORT_NUMERIC);
        $uniqueSamplePositions = [];
        foreach ($samplePositions as $position) {
            if (
                $uniqueSamplePositions === []
                || $position !== $uniqueSamplePositions[\array_key_last($uniqueSamplePositions)]
            ) {
                $uniqueSamplePositions[] = $position;
            }
        }

        foreach ($uniqueSamplePositions as $position) {
            $value = $this->evaluate($coefficients, $position);
            if ($value === null) {
                $previousPosition = null;
                $previousValue = null;
                continue;
            }
            if ($value === 0.0) {
                $this->appendDistinctRoot(
                    $roots,
                    new EquationSolution($position, \abs($value), 0),
                    $options,
                );
            }
            if (
                $previousPosition !== null
                && $previousValue !== null
                && $this->oppositeSigns($previousValue, $value)
            ) {
                $root = $this->bisect(
                    $coefficients,
                    $previousPosition,
                    $previousValue,
                    $position,
                    $options,
                );
                if ($root !== null) {
                    $rootValue = $this->evaluate($coefficients, $root[0]);
                    if ($rootValue !== null) {
                        $this->appendDistinctRoot(
                            $roots,
                            new EquationSolution($root[0], \abs($rootValue), $root[1]),
                            $options,
                        );
                    }
                }
            }
            $previousPosition = $position;
            $previousValue = $value;
        }

        $derivative = [];
        for ($degree = 1; $degree < \count($coefficients); ++$degree) {
            $derivative[] = $degree * $coefficients[$degree];
        }
        foreach (
            $this->sampleCriticalPoints(
                $this->trim($derivative),
                $lowerBound,
                $upperBound,
                $options,
            ) as $stationaryPoint
        ) {
            $value = $this->evaluate($coefficients, $stationaryPoint->value);
            if ($value === null || !$this->isZeroAt($coefficients, $stationaryPoint->value, $value)) {
                continue;
            }

            $this->appendDistinctRoot(
                $roots,
                new EquationSolution($stationaryPoint->value, \abs($value), $stationaryPoint->iterations),
                $options,
            );
        }

        return $roots;
    }

    /** @param list<EquationSolution> $roots */
    private function appendDistinctRoot(
        array &$roots,
        EquationSolution $candidate,
        SolverOptions $options,
    ): void {
        foreach ($roots as $index => $root) {
            if (
                \abs($root->value - $candidate->value)
                > $options->positionTolerance($root->value, $candidate->value)
            ) {
                continue;
            }
            if ($candidate->residual < $root->residual) {
                $roots[$index] = $candidate;
            }

            return;
        }
        $roots[] = $candidate;
    }

    /**
     * Refine numerical-fallback candidates for simple polynomial roots.
     *
     * @param list<float> $coefficients
     * @param list<EquationSolution> $solutions
     * @param callable(float): ?float $residualAt
     * @param callable(float): ?float $residualScaleAt
     * @return list<EquationSolution>
     */
    public function polishSimpleRoots(
        array $coefficients,
        array $solutions,
        float $lowerBound,
        float $upperBound,
        callable $residualAt,
        callable $residualScaleAt,
        SolverOptions $options,
    ): array {
        $scale = 0.0;
        foreach ($coefficients as $coefficient) {
            $scale = \max($scale, \abs($coefficient));
        }
        if ($scale === 0.0 || !\is_finite($scale)) {
            return $solutions;
        }
        $normalizationScale = $this->powerOfTwoScale($scale);
        $normalized = \array_map(
            static fn (float $coefficient): float => $coefficient / $normalizationScale,
            $coefficients,
        );
        foreach ($coefficients as $index => $coefficient) {
            if ($coefficient !== 0.0 && $normalized[$index] === 0.0) {
                return $solutions;
            }
        }

        foreach ($solutions as $index => $solution) {
            if ($this->hasFlatDerivative($normalized, $solution->value)) {
                continue;
            }
            $root = $this->refineAgainstResidual(
                $normalized,
                $normalizationScale,
                $solution->value,
                $residualAt,
                $lowerBound,
                $upperBound,
            );
            $residual = $residualAt($root);
            $residualScale = $residualScaleAt($root);
            if (
                $residual === null
                || !\is_finite($residual)
                || $residualScale === null
                || !\is_finite($residualScale)
                || $residualScale < 0.0
            ) {
                continue;
            }
            $residualScale = \max(1.0, $residualScale, \abs($residual));
            if (
                \abs($residual / $residualScale)
                > $options->residualTolerance + (8.0 * \PHP_FLOAT_EPSILON)
            ) {
                continue;
            }
            $solutions[$index] = new EquationSolution(
                $root,
                \abs($residual),
                $solution->iterations,
            );
        }

        return $solutions;
    }

    private function powerOfTwoScale(float $scale): float
    {
        $power = 2.0 ** \floor(\log($scale, 2.0));

        return \is_finite($power) && $power > 0.0 ? $power : $scale;
    }

    private function scaleByPowerOfTwo(float $value, int $exponent): float
    {
        while ($exponent > 0) {
            $step = \min(1023, $exponent);
            $value *= 2.0 ** $step;
            if (!\is_finite($value)) {
                return $value;
            }
            $exponent -= $step;
        }
        while ($exponent < 0) {
            $step = \max(-1022, $exponent);
            $value *= 2.0 ** $step;
            $exponent -= $step;
        }

        return $value;
    }

    /**
     * Refine candidates after isolation so approximate critical points of
     * multiple roots converge to the shared root before deduplication.
     *
     * @param list<float> $coefficients
     */
    private function refineRoot(
        array $coefficients,
        float $root,
        float $lowerBound,
        float $upperBound,
        int $maxIterations,
    ): float {
        $refinementPolynomial = $coefficients;
        while (
            \count($refinementPolynomial) > 1
            && $this->hasFlatDerivative($refinementPolynomial, $root)
        ) {
            $derivative = [];
            for ($degree = 1; $degree < \count($refinementPolynomial); ++$degree) {
                $derivative[] = $degree * $refinementPolynomial[$degree];
            }
            $refinementPolynomial = $this->trim($derivative);
        }
        $derivative = [];
        for ($degree = 1; $degree < \count($refinementPolynomial); ++$degree) {
            $derivative[] = $degree * $refinementPolynomial[$degree];
        }

        $bestRoot = $root;
        $bestResidual = \abs($this->evaluate($refinementPolynomial, $root) ?? \INF);
        for ($iteration = 0; $iteration < $maxIterations; ++$iteration) {
            $value = $this->evaluate($refinementPolynomial, $bestRoot);
            $slope = $this->evaluate($derivative, $bestRoot);
            if ($value === null || $slope === null || $slope === 0.0) {
                break;
            }

            $candidate = $bestRoot - ($value / $slope);
            if (
                !\is_finite($candidate)
                || $candidate < $lowerBound
                || $candidate > $upperBound
                || $candidate === $bestRoot
            ) {
                break;
            }

            $candidateResidual = $this->evaluate($refinementPolynomial, $candidate);
            if ($candidateResidual === null || \abs($candidateResidual) > $bestResidual) {
                break;
            }
            $bestRoot = $candidate;
            $bestResidual = \abs($candidateResidual);
            if ($bestResidual === 0.0) {
                break;
            }
        }

        return $bestRoot;
    }

    /**
     * Polish simple roots against the caller's expression after polynomial
     * isolation, without letting expression rounding affect sign decisions.
     *
     * @param list<float> $coefficients
     * @param callable(float): ?float $residualAt
     */
    private function refineAgainstResidual(
        array $coefficients,
        float $coefficientScale,
        float $root,
        callable $residualAt,
        float $lowerBound,
        float $upperBound,
    ): float {
        $derivative = [];
        for ($degree = 1; $degree < \count($coefficients); ++$degree) {
            $derivative[] = $degree * $coefficients[$degree];
        }
        $bestRoot = $root;
        $bestResidual = $residualAt($root);
        if ($bestResidual === null) {
            return $root;
        }
        $bestMagnitude = \abs($bestResidual);

        for ($iteration = 0; $iteration < 16 && $bestMagnitude > 0.0; ++$iteration) {
            $slope = $this->evaluate($derivative, $bestRoot);
            if ($slope === null || $slope === 0.0) {
                break;
            }
            $candidate = $bestRoot - (($bestResidual / $coefficientScale) / $slope);
            if (
                !\is_finite($candidate)
                || $candidate < $lowerBound
                || $candidate > $upperBound
                || $candidate === $bestRoot
            ) {
                break;
            }
            $residual = $residualAt($candidate);
            if ($residual === null || \abs($residual) >= $bestMagnitude) {
                break;
            }
            $bestRoot = $candidate;
            $bestResidual = $residual;
            $bestMagnitude = \abs($residual);
        }

        $step = \PHP_FLOAT_EPSILON * \max(1.0, \abs($root));
        for ($offset = -4; $offset <= 4; ++$offset) {
            if ($offset === 0) {
                continue;
            }
            $candidate = $root + ($offset * $step);
            if (
                !\is_finite($candidate)
                || $candidate < $lowerBound
                || $candidate > $upperBound
            ) {
                continue;
            }
            $residual = $residualAt($candidate);
            if ($residual !== null && \abs($residual) < $bestMagnitude) {
                $bestRoot = $candidate;
                $bestMagnitude = \abs($residual);
            }
        }

        return $bestRoot;
    }

    /**
     * @param list<float> $coefficients
     * @return list<array{float, int, bool}>|null
     */
    private function isolate(
        array $coefficients,
        float $lower,
        float $upper,
        SolverOptions $options,
    ): ?array {
        $degree = \count($coefficients) - 1;
        if ($degree === 1) {
            $root = -$coefficients[0] / $coefficients[1];
            if (!\is_finite($root)) {
                return null;
            }

            return $root >= $lower && $root <= $upper ? [[$root, 0, true, false]] : [];
        }

        $derivative = [];
        for ($index = 1; $index < \count($coefficients); ++$index) {
            $coefficient = $coefficients[$index] * $index;
            if (!\is_finite($coefficient)) {
                return null;
            }
            $derivative[] = $coefficient;
        }

        $criticalPoints = $this->isolate(
            $this->trim($derivative),
            $lower,
            $upper,
            $options,
        );
        if ($criticalPoints === null) {
            return null;
        }

        $points = [[$lower, 0]];
        foreach ($criticalPoints as [$criticalPoint, $iterations]) {
            $points[] = [$criticalPoint, $iterations];
        }
        $points[] = [$upper, 0];
        \usort(
            $points,
            static fn (array $left, array $right): int => $left[0] <=> $right[0],
        );
        $uniquePoints = [];
        foreach ($points as $point) {
            if (
                $uniquePoints === []
                || $point[0] !== $uniquePoints[\array_key_last($uniquePoints)][0]
            ) {
                $uniquePoints[] = $point;
            }
        }

        $values = [];
        $polynomialValues = [];
        foreach ($uniquePoints as [$point]) {
            $polynomialValue = $this->evaluate($coefficients, $point);
            if ($polynomialValue === null) {
                return null;
            }
            $values[] = $polynomialValue;
            $polynomialValues[] = $polynomialValue;
        }

        $hasExactZeroRoot = $coefficients[0] === 0.0 && $lower <= 0.0 && $upper >= 0.0;
        $roots = [];
        if ($hasExactZeroRoot) {
            $roots[] = [0.0, 0, false];
        }
        foreach ($uniquePoints as $index => [$point, $iterations]) {
            if ($hasExactZeroRoot && $point === 0.0) {
                continue;
            }
            $bracketsRootsOnBothSides = $index > 0
                && $index < \count($uniquePoints) - 1
                && $this->oppositeSigns($values[$index - 1], $values[$index])
                && $this->oppositeSigns($values[$index], $values[$index + 1]);
            if (
                !$bracketsRootsOnBothSides
                && $this->isZeroAt($coefficients, $point, $polynomialValues[$index])
            ) {
                $root = $point;
                $roots[] = [$root, $iterations, false];
            }
        }

        for ($index = 0; $index < \count($uniquePoints) - 1; ++$index) {
            $left = $uniquePoints[$index][0];
            $right = $uniquePoints[$index + 1][0];
            if ($hasExactZeroRoot && $left < 0.0 && $right > 0.0) {
                continue;
            }
            $leftValue = $values[$index];
            $rightValue = $values[$index + 1];
            if (!$this->oppositeSigns($leftValue, $rightValue)) {
                continue;
            }

            $root = $this->bisect(
                $coefficients,
                $left,
                $leftValue,
                $right,
                $options,
            );
            if ($root === null) {
                return null;
            }
            $roots[] = [$root[0], $root[1], true];
        }

        return $roots;
    }

    /** @param list<float> $coefficients */
    private function criticalRootUncertainty(array $coefficients, float $root): float
    {
        $originalMagnitude = $this->evaluationMagnitude($coefficients, $root);
        if ($originalMagnitude === null || $originalMagnitude === 0.0) {
            return 0.0;
        }

        $derivative = $coefficients;
        $factorial = 1.0;
        for ($order = 1; $order < \count($coefficients); ++$order) {
            $nextDerivative = [];
            for ($degree = 1; $degree < \count($derivative); ++$degree) {
                $coefficient = $degree * $derivative[$degree];
                if (!\is_finite($coefficient)) {
                    return 0.0;
                }
                $nextDerivative[] = $coefficient;
            }
            $derivative = $this->trim($nextDerivative);
            $factorial *= $order;
            if (!\is_finite($factorial)) {
                return 0.0;
            }

            $derivativeValue = $this->evaluate($derivative, $root);
            $derivativeMagnitude = $this->evaluationMagnitude($derivative, $root);
            if ($derivativeValue === null || $derivativeMagnitude === null) {
                return 0.0;
            }
            if (
                $derivativeMagnitude === 0.0
                || \abs($derivativeValue)
                    <= \sqrt(\PHP_FLOAT_EPSILON) * $derivativeMagnitude
            ) {
                continue;
            }

            $leadingTerm = \abs($derivativeValue) / $factorial;
            if ($leadingTerm === 0.0 || !\is_finite($leadingTerm)) {
                return 0.0;
            }
            $roundoff = 16.0 * \PHP_FLOAT_EPSILON * $originalMagnitude;
            $radius = ($roundoff / $leadingTerm) ** (1.0 / $order);
            if (!\is_finite($radius)) {
                return 0.0;
            }

            return \min($radius, 0.01 * \max(1.0, \abs($root)));
        }

        return 0.0;
    }

    /** @param list<float> $coefficients */
    private function evaluationMagnitude(array $coefficients, float $x): ?float
    {
        $magnitude = 0.0;
        $absoluteX = \abs($x);
        for ($index = \count($coefficients) - 1; $index >= 0; --$index) {
            $magnitude = ($magnitude * $absoluteX) + \abs($coefficients[$index]);
            if (!\is_finite($magnitude)) {
                return null;
            }
        }

        return $magnitude;
    }

    /** @param list<float> $coefficients */
    private function evaluate(array $coefficients, float $x): ?float
    {
        $high = 0.0;
        $low = 0.0;
        for ($index = \count($coefficients) - 1; $index >= 0; --$index) {
            $product = $high * $x;
            $productError = $this->productError($high, $x, $product);
            if ($productError === null) {
                return null;
            }
            [$sum, $sumError] = $this->twoSum($product, $coefficients[$index]);
            $lowProduct = $low * $x;
            $correction = (($lowProduct + $productError) + $sumError);
            [$high, $low] = $this->twoSum($sum, $correction);
            if (!\is_finite($high) || !\is_finite($low)) {
                return null;
            }
        }

        $value = $high + $low;

        return \is_finite($value) ? $value : null;
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

    /** @param list<float> $coefficients */
    private function isZeroAt(
        array $coefficients,
        float $x,
        float $value,
    ): bool {
        $scale = $this->evaluationMagnitude($coefficients, $x);
        if ($scale === null) {
            return false;
        }
        if ($scale === 0.0) {
            return $value === 0.0;
        }

        return \abs($value / $scale) <= 8.0 * \PHP_FLOAT_EPSILON;
    }

    /**
     * @param list<float> $coefficients
     * @param callable(float): ?float|null $residualAt
     * @return array{float, int}|null
     */
    private function bisect(
        array $coefficients,
        float $left,
        float $leftValue,
        float $right,
        SolverOptions $options,
        ?callable $residualAt = null,
    ): ?array {
        for ($iteration = 1; $iteration <= $options->maxIterations; ++$iteration) {
            $span = $right - $left;
            $middle = \is_finite($span)
                ? $left + ($span / 2.0)
                : (($left / 2.0) + ($right / 2.0));
            if ($middle === $left || $middle === $right) {
                return [$middle, $iteration];
            }

            $value = $residualAt === null
                ? $this->evaluate($coefficients, $middle)
                : $residualAt($middle);
            if ($value === null) {
                return null;
            }
            if ($value === 0.0) {
                return [$middle, $iteration];
            }
            if ($this->oppositeSigns($leftValue, $value)) {
                $right = $middle;
            } else {
                $left = $middle;
                $leftValue = $value;
            }
        }

        $span = $right - $left;
        $middle = \is_finite($span)
            ? $left + ($span / 2.0)
            : (($left / 2.0) + ($right / 2.0));
        $value = $residualAt === null
            ? $this->evaluate($coefficients, $middle)
            : $residualAt($middle);
        if ($value === null) {
            return null;
        }

        return [$middle, $options->maxIterations];
    }

    private function oppositeSigns(float $left, float $right): bool
    {
        return ($left < 0.0 && $right > 0.0) || ($left > 0.0 && $right < 0.0);
    }

    /** @param list<float> $coefficients
     *  @return list<float>
     */
    private function trim(array $coefficients): array
    {
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === 0.0) {
            \array_pop($coefficients);
        }

        return $coefficients;
    }

    /**
     * @param list<EquationSolution> $solutions
     * @param list<float> $coefficients
     */
    private function addUnique(
        array &$solutions,
        EquationSolution $candidate,
        SolverOptions $options,
        array $coefficients,
        bool $preserveDistinct,
    ): void {
        if ($preserveDistinct) {
            foreach ($solutions as $index => $solution) {
                if ($solution->value !== $candidate->value) {
                    continue;
                }

                if (
                    $candidate->residual < $solution->residual
                    || (
                        $candidate->residual === $solution->residual
                        && $candidate->iterations < $solution->iterations
                    )
                ) {
                    $solutions[$index] = $candidate;
                }

                return;
            }

            $solutions[] = $candidate;
            return;
        }

        foreach ($solutions as $index => $solution) {
            if (
                ($solution->value < 0.0 && $candidate->value > 0.0)
                || ($solution->value > 0.0 && $candidate->value < 0.0)
            ) {
                continue;
            }

            $tolerance = $options->positionTolerance($solution->value, $candidate->value);
            if (
                $this->hasFlatDerivative($coefficients, $solution->value)
                || $this->hasFlatDerivative($coefficients, $candidate->value)
            ) {
                $degree = \count($coefficients) - 1;
                $uncertainty = (8.0 * \PHP_FLOAT_EPSILON) ** (1.0 / \max(2, $degree))
                    * \max(1.0, \abs($solution->value), \abs($candidate->value));
                $tolerance = \max($tolerance, $uncertainty);
            }
            if (
                \abs($solution->value - $candidate->value)
                <= $tolerance
            ) {
                if (
                    $candidate->residual < $solution->residual
                    || (
                        $candidate->residual === $solution->residual
                        && $candidate->iterations < $solution->iterations
                    )
                ) {
                    $solutions[$index] = $candidate;
                }

                return;
            }
        }

        $solutions[] = $candidate;
    }

    /** @param list<float> $coefficients */
    private function hasFlatDerivative(array $coefficients, float $value): bool
    {
        $derivative = [];
        for ($degree = 1; $degree < \count($coefficients); ++$degree) {
            $derivative[] = $degree * $coefficients[$degree];
        }
        $slope = $this->evaluate($derivative, $value);
        if ($slope === null) {
            return false;
        }

        $scale = 0.0;
        $power = 1.0;
        foreach ($derivative as $coefficient) {
            $scale += \abs($coefficient * $power);
            $power *= \abs($value);
        }

        return $scale > 0.0
            && \abs($slope / $scale) <= \sqrt(\PHP_FLOAT_EPSILON);
    }
}
