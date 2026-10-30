<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\Node;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;

/** Resultant-based solving for bounded-degree two-variable polynomial systems. */
final class BivariatePolynomialSystemSolver
{
    private const MAX_BIVARIATE_TOTAL_DEGREE = 18;

    private const MAX_SYLVESTER_RESULTANT_SIZE = 18;

    private const RESULTANT_TAIL_RELATIVE_TOLERANCE = 1.0E-4;

    private const MAX_DIRECT_RESULTANT_ROOT_BOUND = 1.0E3;

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $initialValues
     * @param array<string, int|float> $parameters
     * @param bool $solveExtractedPolynomial Use extracted coefficients for the final univariate roots.
     * @param list<string> $positiveVariables Variables constrained to positive candidates.
     * @param (callable(array<string, float>): float)|null $candidateDistance Distance from the initial guess.
     */
    public function solve(
        array $equations,
        array $equationSources,
        array $names,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
        bool $solveExtractedPolynomial = false,
        array $positiveVariables = [],
        ?callable $candidateDistance = null,
    ): ?SystemSolution {
        if (
            \count($names) !== 2
            || \count($initialValues) !== 2
            || $names[0] === $names[1]
            || \count($equations) < 2
            || \array_key_exists($names[0], $parameters)
            || \array_key_exists($names[1], $parameters)
        ) {
            return null;
        }
        foreach ($initialValues as $initialValue) {
            if (!\is_finite($initialValue)) {
                return null;
            }
        }
        foreach ($positiveVariables as $positiveVariable) {
            if (!\in_array($positiveVariable, $names, true)) {
                return null;
            }
        }
        $extractor = new BivariatePolynomialExtractor(
            $names[0],
            $names[1],
            $parameters,
            $evaluationOptions,
            self::MAX_BIVARIATE_TOTAL_DEGREE,
        );
        $polynomials = [];
        foreach ($equations as [$left, $right]) {
            $polynomial = $extractor->difference($left, $right);
            if ($polynomial === null) {
                $polynomials[] = [[0.0]];
                continue;
            }
            $polynomials[] = $polynomial;
        }

        $linearConstraintIndices = [];
        foreach ($polynomials as $index => $polynomial) {
            if (
                $this->degreeInSecond($polynomial) === 1
                && $this->degreeInSecond($this->transpose($polynomial)) === 1
            ) {
                $linearConstraintIndices[$index] = true;
            }
        }

        $nonzeroPolynomialKeys = [];
        foreach ($polynomials as $polynomial) {
            if ($this->degreeInSecond($polynomial) >= 0) {
                $nonzeroPolynomialKeys[$this->bivariatePolynomialKey($polynomial)] = true;
            }
        }
        if (\count($nonzeroPolynomialKeys) >= 2) {
            $initialResiduals = $this->validatedResiduals(
                $equations,
                $names,
                $initialValues,
                $parameters,
                $evaluationOptions,
                $solverOptions->residualTolerance,
            );
            if ($initialResiduals !== null) {
                return new SystemSolution(
                    [$names[0] => $initialValues[0], $names[1] => $initialValues[1]],
                    $this->norm($initialResiduals),
                    0,
                );
            }
        }

        $candidates = [];
        $polynomialSolver = new PolynomialSolver();
        foreach ($polynomials as $firstIndex => $first) {
            foreach ($polynomials as $secondIndex => $second) {
                if ($secondIndex <= $firstIndex) {
                    continue;
                }
                foreach (
                    $this->orientations($first, $second, $names) as [
                    $eliminatedFirst,
                    $eliminatedSecond,
                    $eliminatedVariable,
                    $remainingVariable,
                    ]
                ) {
                    $eliminatedSecond = $this->cancelCommonLeadingCoefficient(
                        $eliminatedFirst,
                        $eliminatedSecond,
                    );
                    $resultant = $this->resultant($eliminatedFirst, $eliminatedSecond);
                    $resultant = $resultant === null ? null : $this->trim($resultant);
                    if ($resultant === null || \count($resultant) < 2) {
                        continue;
                    }
                    $firstRoots = $this->rootsOfResultant(
                        $resultant,
                        $eliminatedVariable,
                        $polynomialSolver,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    if ($firstRoots === null) {
                        continue;
                    }

                    foreach ($firstRoots as $firstRoot) {
                        $fixedParameters = $parameters + [$eliminatedVariable => $firstRoot->value];
                        /** @var list<array{index: int|null, coefficients: list<float>, extracted: bool}> $seedCandidates */
                        $seedCandidates = [];
                        $seedCandidateKeys = [];
                        $yExtractor = new PolynomialExtractor(
                            $remainingVariable,
                            $fixedParameters,
                            $evaluationOptions,
                            $solverOptions->maxPolynomialDegree,
                        );
                        foreach ($equations as $equationIndex => [$left, $right]) {
                            try {
                                $leftCoefficients = $yExtractor->extract($left);
                                $rightCoefficients = $yExtractor->extract($right);
                            } catch (EvaluationException) {
                                continue;
                            }
                            if ($leftCoefficients === null || $rightCoefficients === null) {
                                continue;
                            }
                            $difference = $this->subtract($leftCoefficients, $rightCoefficients);
                            $difference = $this->trim($difference);
                            if (\count($difference) > 1) {
                                $key = $this->polynomialCoefficientKey($difference);
                                if (isset($seedCandidateKeys[$key])) {
                                    continue;
                                }
                                $seedCandidateKeys[$key] = true;
                                $seedCandidates[] = [
                                    'index' => $equationIndex,
                                    'coefficients' => $difference,
                                    'extracted' => false,
                                ];
                            }
                        }
                        foreach ([$eliminatedFirst, $eliminatedSecond] as $eliminatedPolynomial) {
                            $recoveredCoefficients = $this->evaluateAtEliminatedValue(
                                $eliminatedPolynomial,
                                $firstRoot->value,
                                $polynomialSolver,
                            );
                            if (
                                $recoveredCoefficients === null
                                || \count($recoveredCoefficients) < 2
                            ) {
                                continue;
                            }
                            $key = $this->polynomialCoefficientKey($recoveredCoefficients);
                            if (isset($seedCandidateKeys[$key])) {
                                continue;
                            }
                            $seedCandidateKeys[$key] = true;
                            $seedCandidates[] = [
                                'index' => null,
                                'coefficients' => $recoveredCoefficients,
                                'extracted' => true,
                            ];
                        }
                        \usort(
                            $seedCandidates,
                            static function (array $left, array $right) use ($linearConstraintIndices): int {
                                $leftIsLinearConstraint = $left['index'] !== null
                                    && isset($linearConstraintIndices[$left['index']])
                                    && \count($left['coefficients']) === 2;
                                $rightIsLinearConstraint = $right['index'] !== null
                                    && isset($linearConstraintIndices[$right['index']])
                                    && \count($right['coefficients']) === 2;
                                if ($leftIsLinearConstraint !== $rightIsLinearConstraint) {
                                    return $leftIsLinearConstraint ? -1 : 1;
                                }

                                return \count($right['coefficients']) <=> \count($left['coefficients']);
                            },
                        );
                        foreach ($seedCandidates as $seedCandidate) {
                            $seedCoefficients = $seedCandidate['coefficients'];
                            $secondBound = $this->rootBound($seedCoefficients);
                            $isLinearConstraint = $seedCandidate['index'] !== null
                                && isset($linearConstraintIndices[$seedCandidate['index']])
                                && \count($seedCoefficients) === 2
                                && $seedCoefficients[1] !== 0.0;
                            $secondEquation = null;
                            if ($seedCandidate['index'] !== null) {
                                $secondEquation = $equationSources[$seedCandidate['index']] ?? null;
                            }
                            if ($secondBound === null) {
                                $secondEquation ??= $this->polynomialEquationSource(
                                    $seedCoefficients,
                                    $remainingVariable,
                                );
                                if (
                                    $secondEquation === null
                                    || \strlen($secondEquation) > $evaluationOptions->limits->maxExpressionLength
                                ) {
                                    continue;
                                }
                                try {
                                    $secondRoots = (new EquationSolver())->solve(
                                        $secondEquation,
                                        $remainingVariable,
                                        -\INF,
                                        \INF,
                                        $fixedParameters,
                                        $evaluationOptions,
                                        $solverOptions,
                                    );
                                } catch (\DomainException) {
                                    continue;
                                }
                            } elseif ($solveExtractedPolynomial || $seedCandidate['extracted']) {
                                $secondRoots = $polynomialSolver->solve(
                                    $seedCoefficients,
                                    -$secondBound,
                                    $secondBound,
                                    static fn (float $value): ?float =>
                                        $polynomialSolver->evaluatePolynomial($seedCoefficients, $value),
                                    $solverOptions,
                                    static fn (float $value): ?float =>
                                        $polynomialSolver->evaluatePolynomialMagnitude($seedCoefficients, $value),
                                );
                                if ($secondRoots === null) {
                                    $secondEquation ??= $this->polynomialEquationSource(
                                        $seedCoefficients,
                                        $remainingVariable,
                                    );
                                    if (
                                        $secondEquation === null
                                        || \strlen($secondEquation) > $evaluationOptions->limits->maxExpressionLength
                                    ) {
                                        continue;
                                    }
                                    try {
                                        $secondRoots = (new EquationSolver())->solve(
                                            $secondEquation,
                                            $remainingVariable,
                                            -$secondBound,
                                            $secondBound,
                                            $fixedParameters,
                                            $evaluationOptions,
                                            $solverOptions,
                                        );
                                    } catch (\DomainException) {
                                        continue;
                                    }
                                }
                            } else {
                                if ($secondEquation === null) {
                                    continue;
                                }
                                $secondRoots = (new EquationSolver())->solve(
                                    $secondEquation,
                                    $remainingVariable,
                                    -$secondBound,
                                    $secondBound,
                                    $fixedParameters,
                                    $evaluationOptions,
                                    $solverOptions,
                                );
                            }
                            foreach ($secondRoots as $secondRoot) {
                                $candidateVariables = $fixedParameters + [$remainingVariable => $secondRoot->value];
                                $positiveCandidate = true;
                                foreach ($positiveVariables as $positiveVariable) {
                                    if (($candidateVariables[$positiveVariable] ?? 0.0) <= 0.0) {
                                        $positiveCandidate = false;
                                        break;
                                    }
                                }
                                if (!$positiveCandidate) {
                                    continue;
                                }
                                $values = [$candidateVariables[$names[0]], $candidateVariables[$names[1]]];
                                $residuals = $this->validatedResiduals(
                                    $equations,
                                    $names,
                                    $values,
                                    $parameters,
                                    $evaluationOptions,
                                    $solverOptions->residualTolerance,
                                );
                                if ($residuals === null) {
                                    continue;
                                }
                                $candidates[] = new SystemSolution(
                                    [$names[0] => $values[0], $names[1] => $values[1]],
                                    $this->norm($residuals),
                                    $firstRoot->iterations + $secondRoot->iterations,
                                );
                            }
                            if ($isLinearConstraint) {
                                break;
                            }
                        }
                    }
                }
            }
        }
        if ($candidates === []) {
            return null;
        }

        if ($candidateDistance === null) {
            \usort(
                $candidates,
                fn (SystemSolution $first, SystemSolution $second): int =>
                    \hypot(
                        $first->values[$names[0]] - $initialValues[0],
                        $first->values[$names[1]] - $initialValues[1],
                    ) <=> \hypot(
                        $second->values[$names[0]] - $initialValues[0],
                        $second->values[$names[1]] - $initialValues[1],
                    ) ?: $first->residualNorm <=> $second->residualNorm,
            );
        } else {
            /** @var list<array{SystemSolution, float}> $rankedCandidates */
            $rankedCandidates = [];
            foreach ($candidates as $candidate) {
                $distance = $candidateDistance($candidate->values);
                if (!\is_finite($distance)) {
                    continue;
                }
                $rankedCandidates[] = [$candidate, $distance];
            }
            if ($rankedCandidates === []) {
                return null;
            }
            \usort(
                $rankedCandidates,
                static fn (array $first, array $second): int => $first[1] <=> $second[1]
                    ?: $first[0]->residualNorm <=> $second[0]->residualNorm,
            );
            $candidates = \array_map(
                static fn (array $rankedCandidate): SystemSolution => $rankedCandidate[0],
                $rankedCandidates,
            );
        }

        return $candidates[0];
    }

    /**
     * @param array<int, list<float>> $polynomial
     * @return list<list<float>>
     */
    private function transpose(array $polynomial): array
    {
        $maximumFirstDegree = 0;
        foreach ($polynomial as $coefficients) {
            $maximumFirstDegree = \max($maximumFirstDegree, \count($coefficients) - 1);
        }
        $transposed = [];
        for ($firstDegree = 0; $firstDegree <= $maximumFirstDegree; ++$firstDegree) {
            $dense = [];
            foreach ($polynomial as $coefficients) {
                $dense[] = $coefficients[$firstDegree] ?? 0.0;
            }
            $transposed[] = $dense;
        }

        return $transposed;
    }

    /**
     * @param array<int, list<float>> $first
     * @param array<int, list<float>> $second
     * @param list<string> $names
     * @return list<array{array<int, list<float>>, array<int, list<float>>, string, string}>
     */
    private function orientations(array $first, array $second, array $names): array
    {
        return [
            [$first, $second, $names[0], $names[1]],
            [$this->transpose($first), $this->transpose($second), $names[1], $names[0]],
        ];
    }

    /**
     * @param array<int, list<float>> $first
     * @param array<int, list<float>> $second
     * @return list<float>|null
     */
    private function resultant(array $first, array $second): ?array
    {
        $firstDegree = $this->degreeInSecond($first);
        $secondDegree = $this->degreeInSecond($second);
        if (
            $firstDegree < 0
            || $secondDegree < 0
            || $firstDegree > self::MAX_BIVARIATE_TOTAL_DEGREE
            || $secondDegree > self::MAX_BIVARIATE_TOTAL_DEGREE
        ) {
            return null;
        }
        if ($firstDegree === 0 && $secondDegree === 0) {
            return null;
        }
        if ($firstDegree === 0) {
            return $this->power($first[0], $secondDegree);
        }
        if ($secondDegree === 0) {
            return $this->power($second[0], $firstDegree);
        }
        if ($firstDegree === 1 && $secondDegree > 2) {
            return $this->linearPolynomialResultant($second, $first[1], $first[0]);
        }
        if ($secondDegree === 1 && $firstDegree > 2) {
            return $this->linearPolynomialResultant($first, $second[1], $second[0]);
        }
        if ($firstDegree > 2 || $secondDegree > 2) {
            return $this->sylvesterResultant($first, $second, $firstDegree, $secondDegree);
        }
        if ($firstDegree === 1 && $secondDegree === 1) {
            $firstProduct = $this->multiply($first[1], $second[0]);
            $secondProduct = $this->multiply($first[0], $second[1]);

            return $firstProduct === null || $secondProduct === null
                ? null
                : $this->combine($firstProduct, $secondProduct, -1.0);
        }
        if ($firstDegree === 2 && $secondDegree === 1) {
            return $this->linearQuadraticResultant($first[2], $first[1], $first[0], $second[1], $second[0]);
        }
        if ($firstDegree === 1) {
            return $this->linearQuadraticResultant($second[2], $second[1], $second[0], $first[1], $first[0]);
        }

        $a = $first[2];
        $b = $first[1] ?? [0.0];
        $c = $first[0] ?? [0.0];
        $d = $second[2];
        $e = $second[1] ?? [0.0];
        $f = $second[0] ?? [0.0];
        $af = $this->multiply($a, $f);
        $cd = $this->multiply($c, $d);
        $ae = $this->multiply($a, $e);
        $bd = $this->multiply($b, $d);
        $bf = $this->multiply($b, $f);
        $ce = $this->multiply($c, $e);
        if ($af === null || $cd === null || $ae === null || $bd === null || $bf === null || $ce === null) {
            return null;
        }
        $u = $this->combine($af, $cd, -1.0);
        $v = $this->combine($ae, $bd, -1.0);
        $w = $this->combine($bf, $ce, -1.0);
        if ($u === null || $v === null || $w === null) {
            return null;
        }
        $uSquared = $this->multiply($u, $u);
        $vw = $this->multiply($v, $w);
        if ($uSquared === null || $vw === null) {
            return null;
        }

        return $this->combine($uSquared, $vw, -1.0);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @param list<float> $c
     * @param list<float> $linear
     * @param list<float> $constant
     * @return list<float>|null
     */
    private function linearQuadraticResultant(
        array $a,
        array $b,
        array $c,
        array $linear,
        array $constant,
    ): ?array {
        $linearSquared = $this->multiply($linear, $linear);
        $constantSquared = $this->multiply($constant, $constant);
        $linearConstant = $this->multiply($linear, $constant);
        if ($linearSquared === null || $constantSquared === null || $linearConstant === null) {
            return null;
        }
        $firstTerm = $this->multiply($a, $constantSquared);
        $secondTerm = $this->multiply($b, $linearConstant);
        $thirdTerm = $this->multiply($c, $linearSquared);
        if ($firstTerm === null || $secondTerm === null || $thirdTerm === null) {
            return null;
        }
        $result = $this->combine($firstTerm, $secondTerm, -1.0);

        return $result === null ? null : $this->combine($result, $thirdTerm, 1.0);
    }

    /**
     * @param array<int, list<float>> $polynomial
     * @param list<float> $linear
     * @param list<float> $constant
     * @return list<float>|null
     */
    private function linearPolynomialResultant(array $polynomial, array $linear, array $constant): ?array
    {
        $degree = $this->degreeInSecond($polynomial);
        $result = [0.0];
        $negativeConstant = \array_map(static fn (float $value): float => -$value, $constant);
        for ($index = 0; $index <= $degree; ++$index) {
            $coefficient = $polynomial[$index] ?? [0.0];
            $constantPower = $this->power($negativeConstant, $index);
            $linearPower = $this->power($linear, $degree - $index);
            if ($constantPower === null || $linearPower === null) {
                return null;
            }
            $term = $this->multiply($coefficient, $constantPower);
            $term = $term === null ? null : $this->multiply($term, $linearPower);
            if ($term === null) {
                return null;
            }
            $result = $this->combine($result, $term, 1.0);
            if ($result === null) {
                return null;
            }
        }

        return $result;
    }

    /**
     * @param array<int, list<float>> $first
     * @param array<int, list<float>> $second
     * @return list<float>|null
     */
    private function sylvesterResultant(
        array $first,
        array $second,
        int $firstDegree,
        int $secondDegree,
    ): ?array {
        $size = $firstDegree + $secondDegree;
        if ($size > self::MAX_SYLVESTER_RESULTANT_SIZE) {
            return null;
        }
        $matrix = [];
        foreach ([[$first, $secondDegree], [$second, $firstDegree]] as [$polynomial, $rowCount]) {
            for ($shift = 0; $shift < $rowCount; ++$shift) {
                $row = \array_fill(0, $size, [0.0]);
                $degree = $this->degreeInSecond($polynomial);
                foreach ($polynomial as $power => $coefficient) {
                    $column = $shift + ($degree - $power);
                    $row[$column] = $coefficient;
                }
                $matrix[] = $row;
            }
        }
        foreach ($matrix as &$row) {
            $rowScale = 0.0;
            foreach ($row as $entry) {
                foreach ($entry as $coefficient) {
                    $rowScale = \max($rowScale, \abs($coefficient));
                }
            }
            if ($rowScale === 0.0) {
                return [0.0];
            }
            foreach ($row as &$entry) {
                foreach ($entry as $degree => $coefficient) {
                    $normalizedCoefficient = $coefficient / $rowScale;
                    if ($coefficient !== 0.0 && $normalizedCoefficient === 0.0) {
                        return null;
                    }
                    $entry[$degree] = $normalizedCoefficient;
                }
            }
            unset($entry);
        }
        unset($row);

        $states = [0 => [1.0]];
        for ($row = 0; $row < $size; ++$row) {
            $nextStates = [];
            foreach ($states as $mask => $state) {
                if ($this->isZeroPolynomial($state)) {
                    continue;
                }
                for ($column = 0; $column < $size; ++$column) {
                    $bit = 1 << $column;
                    if (($mask & $bit) !== 0) {
                        continue;
                    }
                    $matrixEntry = $matrix[$row][$column];
                    if ($this->isZeroPolynomial($matrixEntry)) {
                        continue;
                    }
                    $term = $this->multiply($state, $matrixEntry);
                    if ($term === null) {
                        return null;
                    }
                    $inversions = $this->bitCount($mask >> ($column + 1));
                    $nextMask = $mask | $bit;
                    $combined = $this->combine(
                        $nextStates[$nextMask] ?? [0.0],
                        $term,
                        $inversions % 2 === 0 ? 1.0 : -1.0,
                    );
                    if ($combined === null) {
                        return null;
                    }
                    $nextStates[$nextMask] = $combined;
                }
            }
            $states = $nextStates;
        }

        $fullMask = (1 << $size) - 1;

        return isset($states[$fullMask]) ? $this->trim($states[$fullMask]) : null;
    }

    private function bitCount(int $value): int
    {
        $count = 0;
        while ($value !== 0) {
            $value &= $value - 1;
            ++$count;
        }

        return $count;
    }

    /** @param array<int, list<float>> $polynomial */
    private function degreeInSecond(array $polynomial): int
    {
        $degree = -1;
        foreach ($polynomial as $index => $coefficients) {
            if (\array_filter($coefficients, static fn (float $coefficient): bool => $coefficient !== 0.0) !== []) {
                $degree = \max($degree, $index);
            }
        }

        return $degree;
    }

    /**
     * @param array<int, list<float>> $first
     * @param array<int, list<float>> $second
     * @return array<int, list<float>>
     */
    private function cancelCommonLeadingCoefficient(array $first, array $second): array
    {
        $firstDegree = $this->degreeInSecond($first);
        $secondDegree = $this->degreeInSecond($second);
        if ($firstDegree < 1 || $firstDegree !== $secondDegree) {
            return $second;
        }

        $firstLeading = $first[$firstDegree] ?? [];
        $secondLeading = $second[$secondDegree] ?? [];
        $quotient = $this->dividePolynomialsExactly($secondLeading, $firstLeading);
        if ($quotient === null || $this->isZeroPolynomial($quotient)) {
            return $second;
        }

        $reduced = [];
        foreach (\range(0, $secondDegree) as $degree) {
            $multiple = $this->multiply($first[$degree] ?? [0.0], $quotient);
            if ($multiple === null) {
                return $second;
            }
            $coefficient = $this->combine(
                $second[$degree] ?? [0.0],
                $multiple,
                -1.0,
            );
            if ($coefficient === null) {
                return $second;
            }
            $reduced[$degree] = $coefficient;
        }

        while (\count($reduced) > 1 && $this->isZeroPolynomial($reduced[\array_key_last($reduced)])) {
            \array_pop($reduced);
        }

        return $this->degreeInSecond($reduced) >= 0 ? $reduced : $second;
    }

    /**
     * @param list<float> $dividend
     * @param list<float> $divisor
     * @return list<float>|null
     */
    private function dividePolynomialsExactly(array $dividend, array $divisor): ?array
    {
        $remainder = $this->trim($dividend);
        $divisor = $this->trim($divisor);
        if ($this->isZeroPolynomial($divisor) || \count($remainder) < \count($divisor)) {
            return null;
        }

        $quotient = \array_fill(0, \count($remainder) - \count($divisor) + 1, 0.0);
        $divisorDegree = \count($divisor) - 1;
        $divisorLeading = $divisor[$divisorDegree];
        while (\count($remainder) >= \count($divisor) && !$this->isZeroPolynomial($remainder)) {
            $degree = \count($remainder) - \count($divisor);
            $factor = $remainder[\count($remainder) - 1] / $divisorLeading;
            if (!\is_finite($factor)) {
                return null;
            }
            $quotient[$degree] = $factor;
            $term = \array_fill(0, $degree, 0.0);
            foreach ($divisor as $coefficient) {
                $term[] = $coefficient * $factor;
            }
            $remainder = $this->combine($remainder, $term, -1.0);
            if ($remainder === null) {
                return null;
            }
            if (\count($remainder) > 1 && \count($remainder) === $degree + \count($divisor)) {
                return null;
            }
        }

        return $this->isZeroPolynomial($remainder) ? $this->trim(\array_values($quotient)) : null;
    }

    /** @param list<float> $polynomial */
    private function isZeroPolynomial(array $polynomial): bool
    {
        return \array_filter($polynomial, static fn (float $coefficient): bool => $coefficient !== 0.0) === [];
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>|null
     */
    private function multiply(array $left, array $right): ?array
    {
        $product = [0.0];
        for ($index = 1; $index < \count($left) + \count($right) - 1; ++$index) {
            $product[] = 0.0;
        }
        foreach ($left as $leftDegree => $leftCoefficient) {
            foreach ($right as $rightDegree => $rightCoefficient) {
                $term = $leftCoefficient * $rightCoefficient;
                if ($leftCoefficient !== 0.0 && $rightCoefficient !== 0.0 && $term === 0.0) {
                    return null;
                }
                $index = $leftDegree + $rightDegree;
                $previousCoefficient = $product[$index];
                $value = $previousCoefficient + $term;
                if ($term !== 0.0 && $value === $previousCoefficient) {
                    return null;
                }
                if (!\is_finite($value)) {
                    return null;
                }
                $product[$index] = $value;
            }
        }

        return $this->trim(\array_values($product));
    }

    /**
     * @param list<float> $polynomial
     * @return list<float>|null
     */
    private function power(array $polynomial, int $exponent): ?array
    {
        $result = [1.0];
        for ($index = 0; $index < $exponent; ++$index) {
            $result = $this->multiply($result, $polynomial);
            if ($result === null) {
                return null;
            }
        }

        return $this->trim($result);
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>|null
     */
    private function combine(array $left, array $right, float $rightScale): ?array
    {
        $result = [];
        for ($degree = 0; $degree < \max(\count($left), \count($right)); ++$degree) {
            $scaledCoefficient = $rightScale * ($right[$degree] ?? 0.0);
            if (
                $rightScale !== 0.0
                && ($right[$degree] ?? 0.0) !== 0.0
                && $scaledCoefficient === 0.0
            ) {
                return null;
            }
            $previousCoefficient = $left[$degree] ?? 0.0;
            $value = $previousCoefficient + $scaledCoefficient;
            if ($scaledCoefficient !== 0.0 && $value === $previousCoefficient) {
                return null;
            }
            if (!\is_finite($value)) {
                return null;
            }
            $result[] = $value;
        }

        return $this->trim($result);
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>
     */
    private function subtract(array $left, array $right): array
    {
        $difference = [];
        for ($degree = 0; $degree < \max(\count($left), \count($right)); ++$degree) {
            $difference[] = ($left[$degree] ?? 0.0) - ($right[$degree] ?? 0.0);
        }

        return $difference;
    }

    /** @param list<float> $coefficients */
    private function polynomialCoefficientKey(array $coefficients): string
    {
        $key = '';
        foreach ($coefficients as $coefficient) {
            $key .= \pack('d', $coefficient === 0.0 ? 0.0 : $coefficient);
        }

        return \bin2hex($key);
    }

    /** @param array<int, list<float>> $polynomial */
    private function bivariatePolynomialKey(array $polynomial): string
    {
        $key = '';
        foreach ($polynomial as $coefficients) {
            $key .= $this->polynomialCoefficientKey($coefficients) . ':';
        }

        return $key;
    }

    /**
     * @param list<float> $polynomial
     * @return list<float>
     */
    private function trim(array $polynomial): array
    {
        while (\count($polynomial) > 1 && \end($polynomial) === 0.0) {
            \array_pop($polynomial);
        }

        return $polynomial;
    }

    /** @param list<float> $polynomial */
    private function rootBound(array $polynomial): ?float
    {
        $polynomial = $this->trim($polynomial);
        if (\count($polynomial) < 2) {
            return null;
        }
        $leading = \abs($polynomial[\count($polynomial) - 1]);
        if ($leading === 0.0) {
            return null;
        }
        $ratio = 0.0;
        foreach (\array_slice($polynomial, 0, -1) as $coefficient) {
            $candidate = \abs($coefficient) / $leading;
            if (!\is_finite($candidate)) {
                return null;
            }
            $ratio = \max($ratio, $candidate);
        }
        $bound = 1.0 + $ratio;
        if (!\is_finite($bound) || $bound > (\PHP_FLOAT_MAX / 2.0)) {
            return null;
        }

        return $bound;
    }

    /**
     * @param array<int, list<float>> $polynomial Coefficients in the remaining variable;
     *     each value is a polynomial in the eliminated variable.
     * @return list<float>|null
     */
    private function evaluateAtEliminatedValue(
        array $polynomial,
        float $eliminatedValue,
        PolynomialSolver $polynomialSolver,
    ): ?array {
        $coefficients = [];
        foreach ($polynomial as $eliminatedCoefficients) {
            $coefficient = $polynomialSolver->evaluatePolynomial($eliminatedCoefficients, $eliminatedValue);
            if ($coefficient === null || !\is_finite($coefficient)) {
                return null;
            }
            $coefficients[] = $coefficient;
        }

        return $this->trim($coefficients);
    }

    /**
     * Isolate roots from direct and conditioned resultants; the caller validates
     * every candidate against the original system equations.
     *
     * @param list<float> $resultant
     * @return list<EquationSolution>|null
     */
    private function rootsOfResultant(
        array $resultant,
        string $variable,
        PolynomialSolver $polynomialSolver,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        $polynomials = [];
        foreach ($this->conditionedResultantCandidates($resultant) as $polynomial) {
            $polynomials[] = $polynomial;
            $squareFreeCandidate = $this->squareFreePart($polynomial);
            if ($squareFreeCandidate !== $polynomial) {
                $polynomials[] = $squareFreeCandidate;
            }
        }

        $hasZeroRoot = $resultant[0] === 0.0;
        $zeroResidual = $hasZeroRoot
            ? $polynomialSolver->evaluatePolynomial($resultant, 0.0)
            : null;
        $roots = [];
        $isolationCompleted = false;
        foreach ($polynomials as $polynomialIndex => $polynomial) {
            $bound = $this->rootBound($polynomial);
            if ($bound === null) {
                if (\count($polynomial) < 2) {
                    continue;
                }
                $equation = $this->polynomialEquationSource($polynomial, $variable);
                if (
                    $equation === null
                    || \strlen($equation) > $evaluationOptions->limits->maxExpressionLength
                ) {
                    continue;
                }
                try {
                    $isolatedRoots = (new EquationSolver())->solve(
                        $equation,
                        $variable,
                        -\INF,
                        \INF,
                        [],
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (\DomainException) {
                    continue;
                }
                if ($polynomial === $resultant) {
                    $isolationCompleted = true;
                }
                foreach ($isolatedRoots as $root) {
                    if (
                        $polynomial !== $resultant
                        && !$this->hasAcceptableRelativeResultantResidual(
                            $resultant,
                            $root->value,
                            $polynomialSolver,
                            $solverOptions->residualTolerance,
                        )
                    ) {
                        continue;
                    }
                    $this->addDistinctResultantRoot($roots, $root, $solverOptions);
                }
                continue;
            }
            $firstNonzeroDegree = 0;
            while (
                $firstNonzeroDegree < \count($polynomial) - 1
                && $polynomial[$firstNonzeroDegree] === 0.0
            ) {
                ++$firstNonzeroDegree;
            }
            $nonzeroPolynomial = \array_slice($polynomial, $firstNonzeroDegree);
            if (\count($nonzeroPolynomial) < 2) {
                $isolationCompleted = $isolationCompleted || $polynomial === $resultant;
                continue;
            }
            try {
                $isolatedRoots = $polynomialSolver->solve(
                    $nonzeroPolynomial,
                    -$bound,
                    $bound,
                    static fn (float $value): ?float =>
                        $polynomialSolver->evaluatePolynomial($resultant, $value),
                    $solverOptions,
                    static fn (float $value): ?float =>
                        $polynomialSolver->evaluatePolynomialMagnitude($resultant, $value),
                );
            } catch (\DomainException) {
                continue;
            }
            if (
                $polynomialIndex > 0
                && ($isolatedRoots === null || $isolatedRoots === [])
            ) {
                $equation = $this->polynomialEquationSource($nonzeroPolynomial, $variable);
                if (
                    $equation === null
                    || \strlen($equation) > $evaluationOptions->limits->maxExpressionLength
                ) {
                    continue;
                }
                try {
                    $isolatedRoots = (new EquationSolver())->solve(
                        $equation,
                        $variable,
                        -$bound,
                        $bound,
                        [],
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (\DomainException) {
                    continue;
                }
            }
            if ($isolatedRoots === null) {
                continue;
            }
            if ($polynomial === $resultant) {
                $isolationCompleted = true;
            }
            foreach ($isolatedRoots as $root) {
                if (
                    !$this->hasAcceptableRelativeResultantResidual(
                        $resultant,
                        $root->value,
                        $polynomialSolver,
                        $solverOptions->residualTolerance,
                    )
                ) {
                    continue;
                }
                $this->addDistinctResultantRoot($roots, $root, $solverOptions);
            }
        }

        if ($hasZeroRoot && $zeroResidual !== null) {
            $this->addDistinctResultantRoot(
                $roots,
                new EquationSolution(0.0, \abs($zeroResidual), 0),
                $solverOptions,
            );
            $isolationCompleted = true;
        }

        return $isolationCompleted || $roots !== [] ? $roots : null;
    }

    /** @param list<EquationSolution> $roots */
    private function addDistinctResultantRoot(
        array &$roots,
        EquationSolution $candidate,
        SolverOptions $options,
    ): void {
        foreach ($roots as $index => $root) {
            if (
                \abs($root->value - $candidate->value) > $options->positionTolerance(
                    $root->value,
                    $candidate->value,
                )
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

    /** @param list<float> $resultant */
    private function hasAcceptableRelativeResultantResidual(
        array $resultant,
        float $value,
        PolynomialSolver $polynomialSolver,
        float $tolerance,
    ): bool {
        $residual = $polynomialSolver->evaluatePolynomial($resultant, $value);
        $magnitude = $polynomialSolver->evaluatePolynomialMagnitude($resultant, $value);
        if ($residual === null || $magnitude === null) {
            return false;
        }

        return $residual === 0.0
            || ($magnitude > 0.0 && \abs($residual) <= $tolerance * $magnitude);
    }

    /**
     * Retain lower-degree approximations when cancellation leaves a tiny leading tail.
     * Every candidate root is still checked against the original equations.
     *
     * @param list<float> $resultant
     * @return list<list<float>>
     */
    private function conditionedResultantCandidates(array $resultant): array
    {
        $rootBound = $this->rootBound($resultant);
        if ($rootBound !== null && $rootBound <= self::MAX_DIRECT_RESULTANT_ROOT_BOUND) {
            return [$resultant];
        }

        $maximumCoefficient = 0.0;
        foreach ($resultant as $coefficient) {
            $maximumCoefficient = \max($maximumCoefficient, \abs($coefficient));
        }
        $conditioned = $resultant;
        while (
            $maximumCoefficient > 0.0
            && \count($conditioned) > 2
            && \abs($conditioned[\count($conditioned) - 1])
                <= $maximumCoefficient * self::RESULTANT_TAIL_RELATIVE_TOLERANCE
        ) {
            \array_pop($conditioned);
        }

        return $conditioned === $resultant
            ? [$resultant]
            : [$conditioned, $resultant];
    }

    /** @param list<float> $coefficients */
    private function polynomialEquationSource(array $coefficients, string $variable): ?string
    {
        $terms = [];
        foreach ($coefficients as $degree => $coefficient) {
            if ($coefficient === 0.0) {
                continue;
            }
            if (!\is_finite($coefficient)) {
                return null;
            }
            $term = '(' . \sprintf('%.17g', $coefficient) . ')';
            if ($degree > 0) {
                $term .= '*' . $variable;
                if ($degree > 1) {
                    $term .= '^' . $degree;
                }
            }
            $terms[] = $term;
        }

        return $terms === [] ? null : \implode('+', $terms) . '=0';
    }

    /** @param list<float> $polynomial
     * @return list<float>
     */
    private function squareFreePart(array $polynomial): array
    {
        $squareRoot = $this->squareRootPolynomial($polynomial);
        if ($squareRoot !== null) {
            return $squareRoot;
        }
        if (\count($polynomial) !== 5) {
            return $polynomial;
        }
        $leading = $polynomial[4];
        if ($leading === 0.0) {
            return $polynomial;
        }
        $scale = $polynomial[2] / $leading;
        $linearSquare = $polynomial[2] / $leading;
        if (
            $scale >= 0.0
            || \abs($polynomial[1] / $leading) > 1.0E-12
            || \abs($polynomial[3] / $leading) > 1.0E-12
            || \abs(($polynomial[0] / $leading) - ($scale * $scale / 4.0))
                > 1.0E-12 * \max(1.0, \abs($polynomial[0] / $leading))
        ) {
            return $polynomial;
        }

        return [$polynomial[0] / $leading * 2.0, 0.0, $linearSquare];
    }

    /** @param list<float> $polynomial
     *  @return list<float>|null
     */
    private function squareRootPolynomial(array $polynomial): ?array
    {
        $degree = \count($polynomial) - 1;
        if ($degree < 2 || $degree % 2 !== 0) {
            return null;
        }
        $scale = 0.0;
        foreach ($polynomial as $coefficient) {
            if (!\is_finite($coefficient)) {
                return null;
            }
            $scale = \max($scale, \abs($coefficient));
        }
        if ($scale === 0.0) {
            return null;
        }

        $orientation = $polynomial[$degree] < 0.0 ? -1.0 : 1.0;
        $normalized = \array_map(
            static fn (float $coefficient): float => $orientation * ($coefficient / $scale),
            $polynomial,
        );
        $rootDegree = \intdiv($degree, 2);
        $leading = \sqrt($normalized[$degree]);
        if (!\is_finite($leading) || $leading === 0.0) {
            return null;
        }
        $root = \array_fill(0, $rootDegree + 1, 0.0);
        $root[$rootDegree] = $leading;
        for ($coefficientDegree = $degree - 1; $coefficientDegree >= $rootDegree; --$coefficientDegree) {
            $unknownDegree = $coefficientDegree - $rootDegree;
            $knownSum = 0.0;
            for ($index = $unknownDegree + 1; $index < $rootDegree; ++$index) {
                $knownSum += $root[$index] * $root[$coefficientDegree - $index];
            }
            $root[$unknownDegree] = ($normalized[$coefficientDegree] - $knownSum) / (2.0 * $leading);
            if (!\is_finite($root[$unknownDegree])) {
                return null;
            }
        }

        $reconstructed = \array_fill(0, $degree + 1, 0.0);
        foreach ($root as $leftDegree => $leftCoefficient) {
            foreach ($root as $rightDegree => $rightCoefficient) {
                $reconstructed[$leftDegree + $rightDegree]
                    += $leftCoefficient * $rightCoefficient;
            }
        }
        foreach ($normalized as $coefficientDegree => $coefficient) {
            $error = \abs($coefficient - $reconstructed[$coefficientDegree]);
            $errorTolerance = 1024.0 * \PHP_FLOAT_EPSILON
                * \max(1.0, \abs($coefficient), \abs($reconstructed[$coefficientDegree]));
            if (!\is_finite($error) || $error > $errorTolerance) {
                return null;
            }
        }

        return \array_values($root);
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @return list<float>|null
     */
    private function validatedResiduals(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        float $tolerance,
    ): ?array {
        $environment = new Environment(
            $parameters + [$names[0] => $values[0], $names[1] => $values[1]],
            $options->functions,
        );
        $evaluator = new Evaluator($environment, $options->limits);
        $residuals = [];
        foreach ($equations as [$left, $right]) {
            try {
                $leftValue = (float) $evaluator->evaluate($left);
                $rightValue = (float) $evaluator->evaluate($right);
            } catch (EvaluationException) {
                return null;
            }
            $residual = $leftValue - $rightValue;
            $scale = \max(1.0, \abs($leftValue), \abs($rightValue));
            if (!\is_finite($residual) || \abs($residual) > $tolerance * $scale) {
                return null;
            }
            $residuals[] = $residual;
        }

        return $residuals;
    }

    /** @param list<float> $values */
    private function norm(array $values): float
    {
        $norm = 0.0;
        foreach ($values as $value) {
            $norm = \hypot($norm, $value);
        }

        return $norm;
    }
}
