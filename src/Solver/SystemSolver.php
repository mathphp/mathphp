<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\ParseException;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Source\SourceSpan;

final class SystemSolver
{
    private const MAX_SYSTEM_DIMENSION = 64;

    private const MAX_DEFINED_START_PROBES = 4096;

    private const MAX_WIDE_SCALE_START_PROBES = 16_384;

    private const DOMAIN_PROFILE_RATIO = 4096.0;

    private const MAX_SYSTEM_RESTARTS = 32;

    private const MAX_AFFINE_SUBSTITUTION_ATTEMPTS = 128;

    /**
     * Retry failed local system solves from deterministic unit-cube patterns
     * and one-coordinate perturbations. Each attempt remains bounded by SolverOptions.
     *
     * @param list<string> $equations
     * @param array<string, int|float> $initialValues
     * @param array<string, int|float> $parameters
     */
    public function solve(
        array $equations,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $equations = $this->expandChainedEqualities($equations, $evaluationOptions);
        $solution = $this->solveFromInitialValues(
            $equations,
            $initialValues,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($solution !== null) {
            return $solution;
        }

        if (\count($initialValues) === 2 && \count($equations) >= 2) {
            $parsedEquations = $this->parseSystemEquations($equations, $evaluationOptions);
            if ($parsedEquations !== null) {
                $bivariatePolynomialSolution = (new BivariatePolynomialSystemSolver())->solve(
                    $parsedEquations,
                    $equations,
                    \array_keys($initialValues),
                    \array_values(\array_map(static fn (int|float $value): float => (float) $value, $initialValues)),
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                if ($bivariatePolynomialSolution !== null) {
                    return $bivariatePolynomialSolution;
                }
            }
        }
        if (\count($initialValues) < 2) {
            return null;
        }

        $names = \array_keys($initialValues);
        $orderedIndexes = \array_keys($names);
        \usort(
            $orderedIndexes,
            static fn (int $left, int $right): int =>
                \abs((float) $initialValues[$names[$left]])
                <=> \abs((float) $initialValues[$names[$right]]),
        );
        $restarts = 0;
        $patterns = [
            \array_fill(0, \count($names), 1.0),
            \array_fill(0, \count($names), -1.0),
            \array_map(static fn (int $index): float => $index % 2 === 0 ? 1.0 : -1.0, \array_keys($names)),
            \array_map(static fn (int $index): float => $index % 2 === 0 ? -1.0 : 1.0, \array_keys($names)),
        ];
        foreach ($patterns as $pattern) {
            if ($restarts >= self::MAX_SYSTEM_RESTARTS) {
                return null;
            }
            ++$restarts;
            $candidateInitialValues = [];
            foreach ($names as $index => $name) {
                $candidateInitialValues[$name] = $pattern[$index];
            }
            $solution = $this->solveFromInitialValues(
                $equations,
                $candidateInitialValues,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($solution !== null) {
                return $solution;
            }

            foreach ($names as $index => $name) {
                $initial = $pattern[$index];
                $step = \max(1.0, \abs($initial));
                foreach ([-1.0, 1.0] as $direction) {
                    if ($restarts >= self::MAX_SYSTEM_RESTARTS) {
                        return null;
                    }
                    ++$restarts;
                    $candidateValue = $initial + ($direction * $step);
                    $neighborInitialValues = $candidateInitialValues;
                    $neighborInitialValues[$name] = $candidateValue;
                    $solution = $this->solveFromInitialValues(
                        $equations,
                        $neighborInitialValues,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    if ($solution !== null) {
                        return $solution;
                    }
                }
            }
        }

        foreach ($orderedIndexes as $index) {
            $name = $names[$index];
            $initial = (float) $initialValues[$name];
            $step = \max(1.0, \abs($initial));
            foreach ([-1.0, 1.0] as $direction) {
                if ($restarts >= self::MAX_SYSTEM_RESTARTS) {
                    return null;
                }
                ++$restarts;
                $candidateValue = $initial + ($direction * $step);
                if (!\is_finite($candidateValue) || $candidateValue === $initial) {
                    continue;
                }
                $candidateInitialValues = $initialValues;
                $candidateInitialValues[$name] = $candidateValue;
                $solution = $this->solveFromInitialValues(
                    $equations,
                    $candidateInitialValues,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                if ($solution !== null) {
                    return $solution;
                }
            }
        }

        return null;
    }

    /**
     * Solve a separable system, retaining initial values for free variables.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $initialValues
     * @param array<string, int|float> $parameters
     */
    private function solveIndependentScalarEquations(
        array $equations,
        array $equationSources,
        array $names,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        if ($equations === [] || $names === []) {
            return null;
        }

        $equationByVariable = [];
        $knownVariables = \array_fill_keys($names, true);
        foreach ($equations as $index => [$left, $right]) {
            $nodes = [];
            $this->collectVariableNodes($left, $nodes);
            $this->collectVariableNodes($right, $nodes);
            $variables = [];
            foreach ($nodes as $node) {
                if (isset($knownVariables[$node->name])) {
                    $variables[$node->name] = true;
                } elseif (!\array_key_exists($node->name, $parameters)) {
                    return null;
                }
            }
            if (\count($variables) !== 1) {
                return null;
            }

            $variable = \array_key_first($variables);
            $equationByVariable[$variable][] = [
                'source' => $equationSources[$index],
                'equation' => $equations[$index],
            ];
        }
        $rootCounter = new PolynomialRootCountingSolver();
        $valuesByName = [];
        foreach ($names as $index => $name) {
            $constraints = $equationByVariable[$name] ?? [];
            if ($constraints === []) {
                $valuesByName[$name] = $initialValues[$index];
                continue;
            }

            $roots = null;
            $anchorSource = null;
            foreach ($constraints as $constraint) {
                try {
                    $isolatedRoots = $rootCounter->isolateAllReal(
                        $constraint['source'],
                        $name,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                        256,
                        '1/1000000000000',
                    );
                } catch (InfiniteSolutionSetException) {
                    continue;
                } catch (\DomainException | \LengthException) {
                    continue;
                }
                if ($isolatedRoots === null) {
                    continue;
                }
                $roots = $isolatedRoots;
                $anchorSource = $constraint['source'];
                break;
            }
            if ($roots === null || $roots === [] || $anchorSource === null) {
                return null;
            }

            $nearestValue = null;
            $nearestIsExact = false;
            $constraintEquations = \array_map(
                static fn (array $constraint): array => $constraint['equation'],
                $constraints,
            );
            $initialResiduals = $this->residualsWithLocalZeroSideScales(
                $constraintEquations,
                [$name],
                [$initialValues[$index]],
                $parameters,
                $evaluationOptions,
            );
            $preferExactRoots = $initialResiduals === null;
            for ($refinement = 0; $refinement <= 16; ++$refinement) {
                if ($refinement > 0) {
                    $width = '1/' . '1' . \str_repeat('0', 12 + $refinement);
                    try {
                        $refinedRoots = $rootCounter->isolateAllReal(
                            $anchorSource,
                            $name,
                            $parameters,
                            $evaluationOptions,
                            $solverOptions,
                            256,
                            $width,
                        );
                    } catch (InfiniteSolutionSetException) {
                        break;
                    } catch (\DomainException | \LengthException) {
                        break;
                    }
                    if ($refinedRoots === null || $refinedRoots === []) {
                        break;
                    }
                    $roots = $refinedRoots;
                }

                $nearestDistance = \INF;
                foreach ($roots as $root) {
                    try {
                        $lower = [$root->lowerNumerator, $root->lowerDenominator];
                        $upper = [$root->upperNumerator, $root->upperDenominator];
                        $midpoint = (new ExactRationalInterval($lower, $lower))
                            ->add(new ExactRationalInterval($upper, $upper))
                            ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                            ->lower;
                    } catch (\LengthException) {
                        continue;
                    }
                    $candidate = $this->rationalFractionToFloat($midpoint);
                    if ($candidate === null) {
                        continue;
                    }
                    $constraintResiduals = $this->residualsWithLocalZeroSideScales(
                        $constraintEquations,
                        [$name],
                        [$candidate],
                        $parameters,
                        $evaluationOptions,
                    );
                    if (
                        $constraintResiduals === null
                        || $this->norm($constraintResiduals[1]) > $solverOptions->residualTolerance
                    ) {
                        continue;
                    }
                    $distance = \abs($candidate - $initialValues[$index]);
                    $isPreferredExactRoot = $preferExactRoots && $root->isExactRoot;
                    if (
                        \is_finite($distance)
                        && (($isPreferredExactRoot && !$nearestIsExact)
                            || ($isPreferredExactRoot === $nearestIsExact && $distance < $nearestDistance))
                    ) {
                        $nearestValue = $candidate;
                        $nearestDistance = $distance;
                        $nearestIsExact = $isPreferredExactRoot;
                    }
                }
                if ($nearestValue !== null) {
                    break;
                }
            }
            if ($nearestValue === null) {
                return null;
            }
            $valuesByName[$name] = $nearestValue;
        }

        $values = \array_map(static fn (string $name): float => $valuesByName[$name], $names);
        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
        );
        if (
            $residuals === null
            || $this->norm($residuals[1]) > $solverOptions->residualTolerance
        ) {
            return null;
        }

        return new SystemSolution(\array_combine($names, $values), $this->norm($residuals[0]), 0);
    }

    /**
     * Split a system into independent connected components of equations and variables.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param array<string, int|float> $initialValues
     * @param array<string, int|float> $parameters
     */
    private function solveDisconnectedSystemComponents(
        array $equations,
        array $equationSources,
        array $names,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        if (\count($names) < 2) {
            return null;
        }

        $knownVariables = \array_fill_keys($names, true);
        $adjacency = \array_fill_keys($names, []);
        $equationVariables = [];
        foreach ($equations as [$left, $right]) {
            $nodes = [];
            $this->collectVariableNodes($left, $nodes);
            $this->collectVariableNodes($right, $nodes);
            $usedVariables = [];
            foreach ($nodes as $node) {
                if (isset($knownVariables[$node->name])) {
                    $usedVariables[$node->name] = true;
                } elseif (!\array_key_exists($node->name, $parameters)) {
                    return null;
                }
            }
            $usedNames = \array_keys($usedVariables);
            $equationVariables[] = $usedNames;
            if (\count($usedNames) > 1) {
                $first = $usedNames[0];
                foreach (\array_slice($usedNames, 1) as $other) {
                    $adjacency[$first][$other] = true;
                    $adjacency[$other][$first] = true;
                }
            }
        }

        $components = [];
        $componentByVariable = [];
        foreach ($names as $name) {
            if (isset($componentByVariable[$name])) {
                continue;
            }
            $componentIndex = \count($components);
            $components[$componentIndex] = [];
            $pending = [$name];
            while ($pending !== []) {
                $current = \array_pop($pending);
                if (isset($componentByVariable[$current])) {
                    continue;
                }
                $componentByVariable[$current] = $componentIndex;
                $components[$componentIndex][] = $current;
                foreach (\array_keys($adjacency[$current]) as $neighbor) {
                    if (!isset($componentByVariable[$neighbor])) {
                        $pending[] = $neighbor;
                    }
                }
            }
        }
        if (\count($components) < 2) {
            return null;
        }

        $equationIndexesByComponent = \array_fill(0, \count($components), []);
        foreach ($equationVariables as $equationIndex => $usedNames) {
            if ($usedNames !== []) {
                $componentIndex = $componentByVariable[$usedNames[0]];
                $equationIndexesByComponent[$componentIndex][] = $equationIndex;
            }
        }

        $valuesByName = [];
        $iterations = 0;
        $hasUnsolvedComponent = false;
        foreach ($components as $componentIndex => $componentNames) {
            $equationIndexes = $equationIndexesByComponent[$componentIndex];
            if ($equationIndexes === []) {
                foreach ($componentNames as $name) {
                    $valuesByName[$name] = (float) $initialValues[$name];
                }
                continue;
            }

            $componentSources = [];
            $componentInitialValues = [];
            foreach ($equationIndexes as $equationIndex) {
                $componentSources[] = $equationSources[$equationIndex];
            }
            foreach ($componentNames as $name) {
                $componentInitialValues[$name] = $initialValues[$name];
            }
            $componentSolution = $this->solveFromInitialValues(
                $componentSources,
                $componentInitialValues,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($componentSolution === null) {
                $hasUnsolvedComponent = true;
                foreach ($componentNames as $name) {
                    $valuesByName[$name] = (float) $initialValues[$name];
                }
                continue;
            }
            foreach ($componentSolution->values as $name => $value) {
                $valuesByName[$name] = $value;
            }
            $iterations += $componentSolution->iterations;
        }

        if ($hasUnsolvedComponent) {
            $seedInitialValues = $initialValues;
            $hasImprovedSeed = false;
            foreach ($valuesByName as $name => $value) {
                $seedInitialValues[$name] = $value;
                $hasImprovedSeed = $hasImprovedSeed || $value !== (float) $initialValues[$name];
            }

            return $hasImprovedSeed
                ? $this->solveFromInitialValues(
                    $equationSources,
                    $seedInitialValues,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                    false,
                )
                : null;
        }

        $values = \array_map(static fn (string $name): float => $valuesByName[$name], $names);
        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
        );
        if (
            $residuals === null
            || $this->norm($residuals[1]) > $solverOptions->residualTolerance
        ) {
            return null;
        }

        return new SystemSolution(\array_combine($names, $values), $this->norm($residuals[0]), $iterations);
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $initialValues
     * @param array<string, int|float> $parameters
     */
    private function solveExactAffineEquations(
        array $equations,
        array $names,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): SystemSolution|false|null {
        $values = (new ExactAffineSystemSolver())->solve(
            $equations,
            $names,
            $initialValues,
            $parameters,
            $evaluationOptions,
        );
        if ($values === false) {
            return false;
        }
        if ($values === null) {
            return null;
        }

        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
        );
        if ($residuals === null || $this->norm($residuals[1]) > $solverOptions->residualTolerance) {
            return null;
        }

        return new SystemSolution(\array_combine($names, $values), $this->norm($residuals[0]), 0);
    }

    /**
     * @param list<string> $equations
     * @param array<string, int|float> $initialValues
     * @param array<string, int|float> $parameters
     */
    private function solveFromInitialValues(
        array $equations,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
        bool $allowComponentDecomposition = true,
    ): ?SystemSolution {
        if (
            $equations === []
            || \count($equations) > self::MAX_SYSTEM_DIMENSION
            || $initialValues === []
            || \count($initialValues) > self::MAX_SYSTEM_DIMENSION
        ) {
            throw new \InvalidArgumentException(
                'A system requires equations and initial values, with up to '
                    . self::MAX_SYSTEM_DIMENSION . ' of each.',
            );
        }

        foreach ($initialValues as $name => $value) {
            if (
                \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || \in_array($name, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
                || !\is_finite((float) $value)
            ) {
                throw new \InvalidArgumentException(
                    'Initial values must use non-reserved ASCII variable names and finite numbers.',
                );
            }
        }

        foreach ($parameters as $name => $value) {
            if (
                \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || \in_array($name, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
                || \array_key_exists($name, $initialValues)
                || !\is_finite((float) $value)
            ) {
                throw new \InvalidArgumentException(
                    'Parameters must use finite numbers and valid names distinct from solved variables.',
                );
            }
        }

        $parsedEquations = [];
        $equationSources = [];
        foreach ($equations as $equation) {
            if (\strlen($equation) > $evaluationOptions->limits->maxExpressionLength) {
                $position = $evaluationOptions->limits->maxExpressionLength;
                throw new LexicalException(
                    'Equation exceeds the configured byte limit',
                    'limit.expression_length',
                    new SourceSpan($position, $position + 1),
                );
            }

            [$left, $right, $rightOffset] = $this->splitEquation($equation);
            $parsedEquations[] = [
                $this->parseSide($left, 0, $evaluationOptions),
                $this->parseSide($right, $rightOffset, $evaluationOptions),
                $rightOffset,
            ];
            $equationSources[] = $equation;
        }

        $names = \array_keys($initialValues);
        $values = \array_values(\array_map(
            static fn (int|float $value): float => (float) $value,
            $initialValues,
        ));
        $exactAffineSolution = $this->solveExactAffineEquations(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($exactAffineSolution === false) {
            return null;
        }
        if ($exactAffineSolution !== null) {
            return $exactAffineSolution;
        }

        $independentSolution = $this->solveIndependentScalarEquations(
            $parsedEquations,
            $equationSources,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($independentSolution !== null) {
            return $independentSolution;
        }
        if ($allowComponentDecomposition) {
            $componentSolution = $this->solveDisconnectedSystemComponents(
                $parsedEquations,
                $equationSources,
                $names,
                $initialValues,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($componentSolution !== null) {
                return $componentSolution;
            }
        }

        $substitutionAttempts = self::MAX_AFFINE_SUBSTITUTION_ATTEMPTS;
        $hasTriangularDefinition = false;
        $affineSystemSolver = new ExactAffineSystemSolver();
        foreach ($parsedEquations as [$left, $right]) {
            foreach ([[$left, $right], [$right, $left]] as [$candidate, $expression]) {
                if (
                    $candidate instanceof VariableNode
                    && \in_array($candidate->name, $names, true)
                    && !$this->containsAnyVariable($expression, [$candidate->name])
                ) {
                    $hasTriangularDefinition = true;
                    break 2;
                }
            }
            $affineForm = $affineSystemSolver->equationCoefficientForm(
                $left,
                $right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($affineForm !== null) {
                foreach ($affineForm['coefficients'] as $coefficient) {
                    if ($coefficient[0] !== '0') {
                        $hasTriangularDefinition = true;
                        break 2;
                    }
                }
            }
        }
        if ($hasTriangularDefinition && \count($names) >= 2) {
            $triangularSolution = $this->solveTriangularSystem(
                $parsedEquations,
                $equationSources,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                $substitutionAttempts,
            );
            if ($triangularSolution !== null) {
                return $triangularSolution;
            }
        }

        $powerSumValues = (new SymmetricPowerSumSystemSolver())->solve(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($powerSumValues !== null) {
            $powerSumResiduals = $this->residualsWithLocalZeroSideScales(
                $parsedEquations,
                $names,
                $powerSumValues,
                $parameters,
                $evaluationOptions,
            );
            if (
                $powerSumResiduals !== null
                && $this->norm($powerSumResiduals[1]) <= $solverOptions->residualTolerance
            ) {
                return new SystemSolution(
                    \array_combine($names, $powerSumValues),
                    $this->norm($powerSumResiduals[0]),
                    0,
                );
            }
        }
        $powerLinearSolution = $this->solvePowerLinearSystem(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($powerLinearSolution !== null) {
            return $powerLinearSolution;
        }
        $linearizedExponentialSolution = $this->solveLinearizedExponentialVariables(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($linearizedExponentialSolution !== null) {
            return $linearizedExponentialSolution;
        }
        $eliminationEquations = $this->withZeroEvenPowerConsequences(
            $parsedEquations,
            $names,
            $parameters,
            $evaluationOptions,
        );
        $hasDerivedZeroPowerConstraints = \count($eliminationEquations) !== \count($parsedEquations);
        $hasZeroProductConstraint = false;
        foreach ($parsedEquations as [$left, $right]) {
            if (
                $this->zeroProductVariableNames($left, $right, $names) !== []
                || $this->zeroProductVariableNames($right, $left, $names) !== []
            ) {
                $hasZeroProductConstraint = true;
                break;
            }
        }
        $exponentialReduction = $this->solveExponentialAffineReduction(
            $parsedEquations,
            $equationSources,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($exponentialReduction !== null) {
            return $exponentialReduction;
        }
        $exactAffineSolution = $this->solveExactAffineEquations(
            $eliminationEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($exactAffineSolution === false) {
            return null;
        }
        if ($exactAffineSolution !== null) {
            return $exactAffineSolution;
        }
        $scalarPolynomialSolution = $this->solveScalarPolynomialSystem(
            $parsedEquations,
            $equationSources,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($scalarPolynomialSolution instanceof SystemSolution) {
            return $scalarPolynomialSolution;
        }
        if ($scalarPolynomialSolution === false) {
            return null;
        }
        if (\count($names) === 1) {
            $scalarNumericalSolution = $this->solveScalarSystemByExpansion(
                $parsedEquations,
                $equationSources,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($scalarNumericalSolution !== null) {
                return $scalarNumericalSolution;
            }
        }
        if (\count($names) > 1) {
            $separableSolution = $this->solveSeparableSystem(
                $parsedEquations,
                $equationSources,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($separableSolution !== null) {
                return $separableSolution;
            }
        }
        if (
            \count($names) >= 2
            && (!$hasDerivedZeroPowerConstraints || $hasZeroProductConstraint)
        ) {
            $triangularSolution = $this->solveTriangularSystem(
                $parsedEquations,
                $equationSources,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                $substitutionAttempts,
            );
            if ($triangularSolution !== null) {
                return $triangularSolution;
            }
        }
        $rawResiduals = $this->residuals(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $scales,
            $zeroSideRows,
        );
        if ($rawResiduals === null) {
            $values = $this->findDefinedStartingValues(
                $parsedEquations,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
            );
            if ($values === null) {
                return null;
            }
            $rawResiduals = $this->residuals(
                $parsedEquations,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $scales,
                $zeroSideRows,
            );
            if ($rawResiduals === null) {
                return null;
            }
        }
        $initialZeroScaleRows = [];
        foreach ($scales as $scale) {
            $initialZeroScaleRows[] = $scale === 0.0;
        }
        $initialValuesWereZero = !\in_array(true, \array_map(
            static fn (float $value): bool => $value !== 0.0,
            $values,
        ), true);
        if (
            $initialValuesWereZero
            && \max([0.0, ...$scales]) > (1.0 / \sqrt(\PHP_FLOAT_EPSILON))
        ) {
            $probeValues = $this->findDefinedStartingValues(
                $parsedEquations,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                true,
            );
            if ($probeValues !== null && $probeValues !== $values) {
                $probeRawResiduals = $this->residuals(
                    $parsedEquations,
                    $names,
                    $probeValues,
                    $parameters,
                    $evaluationOptions,
                );
                $probeResiduals = $probeRawResiduals === null
                    ? null
                    : $this->normalizeResiduals($probeRawResiduals, $scales);
                if (
                    $probeResiduals !== null
                    && $this->norm($probeResiduals) <= $solverOptions->residualTolerance
                ) {
                    $restartedSolution = $this->solveFromInitialValues(
                        $equationSources,
                        \array_combine($names, $probeValues),
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    if ($restartedSolution !== null) {
                        return $restartedSolution;
                    }
                }
            }
        }
        $scales = $this->calibrateZeroEquationScales(
            $parsedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
            $scales,
        );
        $residuals = $this->normalizeResiduals($rawResiduals, $scales);
        if ($residuals === null) {
            return null;
        }

        $usedDefinedStartProbe = false;
        for ($iteration = 0; $iteration <= $solverOptions->maxIterations; ++$iteration) {
            $currentScales = [];
            $currentZeroSideRows = [];
            $currentRawResiduals = $this->residuals(
                $parsedEquations,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $currentScales,
                $currentZeroSideRows,
            );
            if ($currentRawResiduals === null) {
                return null;
            }
            $variableMagnitude = 0.0;
            foreach ($values as $value) {
                $variableMagnitude = \max($variableMagnitude, \abs($value));
            }
            $tinyVariableThreshold = \max(
                \PHP_FLOAT_MIN,
                $solverOptions->residualTolerance ** 2,
            );
            $zeroSideScales = $scales;
            $hasTinyEquationScale = false;
            foreach ($currentScales as $scale) {
                if ($scale > 0.0 && $scale < $tinyVariableThreshold) {
                    $hasTinyEquationScale = true;
                    break;
                }
            }
            if (
                $variableMagnitude < $tinyVariableThreshold
                && $hasTinyEquationScale
                && \in_array(true, $initialZeroScaleRows, true)
            ) {
                $zeroSideScales = $this->zeroSideLocalScales(
                    $parsedEquations,
                    $names,
                    $values,
                    $parameters,
                    $evaluationOptions,
                    $scales,
                    $initialZeroScaleRows,
                );
            }
            $iterationScales = $scales;
            $convergenceScales = $currentScales;
            foreach ($currentScales as $row => $scale) {
                if ($currentZeroSideRows[$row]) {
                    if ($initialZeroScaleRows[$row]) {
                        $iterationScales[$row] = $zeroSideScales[$row];
                        $convergenceScales[$row] = $zeroSideScales[$row];
                    }
                }
            }
            $iterationScales = \array_values($iterationScales);
            $convergenceScales = \array_values($convergenceScales);
            $residuals = $this->normalizeResiduals(
                $currentRawResiduals,
                $iterationScales,
            );
            if ($residuals === null) {
                return null;
            }
            $norm = $this->norm($residuals);
            if (!\is_finite($norm)) {
                return null;
            }
            $convergenceResiduals = $this->normalizeResiduals(
                $currentRawResiduals,
                $convergenceScales,
            );
            $residualConverged =
                $convergenceResiduals !== null
                && $this->norm($convergenceResiduals) <= $solverOptions->residualTolerance;
            if ($iteration === $solverOptions->maxIterations) {
                return $residualConverged
                    ? new SystemSolution(
                        \array_combine($names, $values),
                        $this->norm($currentRawResiduals),
                        $iteration,
                    )
                    : null;
            }

            $jacobian = $this->jacobian(
                $parsedEquations,
                $names,
                $values,
                $iterationScales,
                $parameters,
                $evaluationOptions,
                $variableMagnitude < $tinyVariableThreshold,
            );
            if ($jacobian === null) {
                return $residualConverged
                    ? new SystemSolution(
                        \array_combine($names, $values),
                        $this->norm($currentRawResiduals),
                        $iteration,
                    )
                    : null;
            }

            if ($residualConverged) {
                $correction = \count($jacobian) === \count($values)
                    ? $this->solveLinearSystem(
                        $jacobian,
                        \array_map(static fn (float $residual): float => -$residual, $residuals),
                    )
                    : null;
                $correctionIsSmall = $correction === null;
                if ($correction !== null) {
                    $correctionIsSmall = true;
                    foreach ($values as $index => $value) {
                        if (
                            \abs($correction[$index])
                                > $solverOptions->positionTolerance($value, $value)
                        ) {
                            $correctionIsSmall = false;
                            break;
                        }
                    }
                }
                if ($correctionIsSmall) {
                    return new SystemSolution(
                        \array_combine($names, $values),
                        $this->norm($currentRawResiduals),
                        $iteration,
                    );
                }
            }

            if (
                \count($jacobian) === \count($values)
                && \max([0.0, ...$iterationScales]) > (1.0 / \sqrt(\PHP_FLOAT_EPSILON))
                && !$residualConverged
                && $this->solveLinearSystem(
                    $jacobian,
                    \array_fill(0, \count($values), 0.0),
                ) === null
            ) {
                if (!$usedDefinedStartProbe) {
                    $usedDefinedStartProbe = true;
                    $probeValues = $this->findDefinedStartingValues(
                        $parsedEquations,
                        $names,
                        $values,
                        $parameters,
                        $evaluationOptions,
                        true,
                    );
                    if ($probeValues !== null && $probeValues !== $values) {
                        $probeRawResiduals = $this->residuals(
                            $parsedEquations,
                            $names,
                            $probeValues,
                            $parameters,
                            $evaluationOptions,
                        );
                        $probeResiduals = $probeRawResiduals === null
                            ? null
                            : $this->normalizeResiduals($probeRawResiduals, $iterationScales);
                        if ($probeResiduals !== null && $this->norm($probeResiduals) < $norm) {
                            $values = $probeValues;
                            $residuals = $probeResiduals;
                            continue;
                        }
                    }
                }
                $coordinateStep = $this->coordinateStep(
                    $parsedEquations,
                    $names,
                    $values,
                    $residuals,
                    $iterationScales,
                    $parameters,
                    $evaluationOptions,
                    $jacobian,
                    $solverOptions->residualTolerance,
                );
                if ($coordinateStep !== null) {
                    [$values, $residuals] = $coordinateStep;
                    continue;
                }
            }

            $steps = $this->stepCandidates($jacobian, $residuals, $values);
            $accepted = false;
            $stepBudget = 24;
            foreach ($steps as $stepIndex => $delta) {
                $attemptLimit = $stepIndex === 0 && \count($steps) > 1 ? 6 : 4;
                $step = 1.0;
                for ($attempt = 0; $attempt < $attemptLimit && $stepBudget > 0; ++$attempt) {
                    --$stepBudget;
                    $candidate = [];
                    foreach ($values as $index => $value) {
                        $next = $value + ($step * $delta[$index]);
                        if (!\is_finite($next)) {
                            $candidate = [];
                            break;
                        }
                        $candidate[] = $next;
                    }
                    if ($candidate !== []) {
                        $candidateRawResiduals = $this->residuals(
                            $parsedEquations,
                            $names,
                            $candidate,
                            $parameters,
                            $evaluationOptions,
                        );
                        $candidateResiduals = $candidateRawResiduals === null
                            ? null
                            : $this->normalizeResiduals($candidateRawResiduals, $iterationScales);
                        if ($candidateResiduals !== null && $this->norm($candidateResiduals) < $norm) {
                            $values = $candidate;
                            $residuals = $candidateResiduals;
                            $accepted = true;
                            break 2;
                        }
                    }

                    $step *= 0.5;
                }
            }

            if (!$accepted) {
                foreach ($this->multiplicativeStepCandidates($jacobian, $residuals, $values) as $delta) {
                    $candidate = [];
                    foreach ($values as $index => $value) {
                        $next = $value + $delta[$index];
                        if (!\is_finite($next)) {
                            $candidate = [];
                            break;
                        }
                        $candidate[] = $next;
                    }
                    if ($candidate === []) {
                        continue;
                    }
                    $candidateRawResiduals = $this->residuals(
                        $parsedEquations,
                        $names,
                        $candidate,
                        $parameters,
                        $evaluationOptions,
                    );
                    $candidateResiduals = $candidateRawResiduals === null
                        ? null
                        : $this->normalizeResiduals($candidateRawResiduals, $iterationScales);
                    if ($candidateResiduals !== null && $this->norm($candidateResiduals) < $norm) {
                        $values = $candidate;
                        $residuals = $candidateResiduals;
                        $accepted = true;
                        break;
                    }
                }
            }

            if (!$accepted) {
                $coordinateStep = $this->coordinateStep(
                    $parsedEquations,
                    $names,
                    $values,
                    $residuals,
                    $iterationScales,
                    $parameters,
                    $evaluationOptions,
                    $jacobian,
                    $solverOptions->residualTolerance,
                );
                if ($coordinateStep === null) {
                    if (!$usedDefinedStartProbe) {
                        $usedDefinedStartProbe = true;
                        $probeValues = $this->findDefinedStartingValues(
                            $parsedEquations,
                            $names,
                            $values,
                            $parameters,
                            $evaluationOptions,
                            true,
                        );
                        if ($probeValues !== null && $probeValues !== $values) {
                            $probeRawResiduals = $this->residuals(
                                $parsedEquations,
                                $names,
                                $probeValues,
                                $parameters,
                                $evaluationOptions,
                            );
                            $probeResiduals = $probeRawResiduals === null
                                ? null
                                : $this->normalizeResiduals($probeRawResiduals, $iterationScales);
                            if ($probeResiduals !== null && $this->norm($probeResiduals) < $norm) {
                                $values = $probeValues;
                                $residuals = $probeResiduals;
                                continue;
                            }
                        }
                    }
                    return $residualConverged
                        ? new SystemSolution(
                            \array_combine($names, $values),
                            $this->norm($currentRawResiduals),
                            $iteration,
                        )
                        : null;
                }

                [$values, $residuals] = $coordinateStep;
            }
        }

        return null;
    }

    /**
     * Solve systems whose equations each involve at most one solved variable.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveSeparableSystem(
        array $equations,
        array $equationSources,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $equationIndexesByVariable = \array_fill_keys($names, []);
        foreach ($equations as $equationIndex => [$left, $right]) {
            $variables = [];
            $this->collectVariableNodes($left, $variables);
            $this->collectVariableNodes($right, $variables);
            $usedNames = [];
            foreach ($variables as $variable) {
                if (\in_array($variable->name, $names, true)) {
                    $usedNames[$variable->name] = true;
                }
            }
            if (\count($usedNames) > 1) {
                return null;
            }
            if ($usedNames !== []) {
                $name = \array_key_first($usedNames);
                $equationIndexesByVariable[$name][] = $equationIndex;
            }
        }

        $solvedValues = [];
        $iterations = 0;
        foreach ($names as $index => $name) {
            $indexes = $equationIndexesByVariable[$name];
            if ($indexes === []) {
                return null;
            }
            $scalarEquations = [];
            $scalarSources = [];
            foreach ($indexes as $equationIndex) {
                $scalarEquations[] = $equations[$equationIndex];
                $scalarSources[] = $equationSources[$equationIndex];
            }
            $solution = $this->solveScalarSystemByExpansion(
                $scalarEquations,
                $scalarSources,
                [$name],
                [$values[$index]],
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($solution === null) {
                return null;
            }
            $solvedValues[$name] = $solution->values[$name];
            $iterations += $solution->iterations;
        }

        $orderedValues = [];
        foreach ($names as $name) {
            $orderedValues[] = $solvedValues[$name];
        }
        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $orderedValues,
            $parameters,
            $evaluationOptions,
        );
        if ($residuals === null || \count($residuals[0]) !== \count($equations)) {
            return null;
        }
        if ($this->norm($residuals[1]) > $solverOptions->residualTolerance) {
            return null;
        }

        return new SystemSolution($solvedValues, $this->norm($residuals[0]), $iterations);
    }

    /**
     * Reduce equalities between exponentials to equivalent affine log constraints.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveExponentialAffineReduction(
        array $equations,
        array $equationSources,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $reducedEquations = [];
        $changed = false;
        foreach ($equations as [$left, $right, $rightOffset]) {
            $left = $this->ungroup($left);
            $right = $this->ungroup($right);
            $simplifiedLeft = $this->simplifyLogarithmExponentComposition(
                $left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($simplifiedLeft !== null) {
                $left = $simplifiedLeft;
                $changed = true;
            }
            $simplifiedRight = $this->simplifyLogarithmExponentComposition(
                $right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($simplifiedRight !== null) {
                $right = $simplifiedRight;
                $changed = true;
            }
            $leftExponential = $this->extractScaledExponentialTerm(
                $left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            $rightExponential = $this->extractScaledExponentialTerm(
                $right,
                $names,
                $parameters,
                $evaluationOptions,
            );

            if ($leftExponential !== null && $rightExponential !== null) {
                if ($leftExponential['scale'] === 0.0 || $rightExponential['scale'] === 0.0) {
                    $reducedEquations[] = [$left, $right, $rightOffset];
                    continue;
                }
                $logScaleRatio = \log(\abs($rightExponential['scale']))
                    - \log(\abs($leftExponential['scale']));
                if (
                    (!$leftExponential['negativeBase'] && !$rightExponential['negativeBase'])
                    && (($leftExponential['scale'] > 0.0) !== ($rightExponential['scale'] > 0.0))
                ) {
                    $reducedEquations[] = [$left, $right, $rightOffset];
                    continue;
                }
                if (!\is_finite($logScaleRatio)) {
                    $reducedEquations[] = [$left, $right, $rightOffset];
                    continue;
                }
                $difference = new BinaryOperationNode(
                    BinaryOperator::Subtract,
                    $leftExponential['argument'],
                    $rightExponential['argument'],
                    new SourceSpan(
                        $leftExponential['argument']->span()->end,
                        $leftExponential['argument']->span()->end,
                    ),
                );
                $reducedEquations[] = [
                    $difference,
                    new NumberNode(
                        $logScaleRatio,
                        \sprintf('%.17g', $logScaleRatio),
                        $right->span(),
                    ),
                    $rightOffset,
                ];
                $changed = true;
                continue;
            }

            if (
                $leftExponential !== null
                && $leftExponential['scale'] !== 0.0
                && !$this->containsAnyVariable($right, $names)
            ) {
                try {
                    $target = $this->evaluate($right, $parameters, $evaluationOptions);
                } catch (EvaluationException) {
                    return null;
                }
                $targetMagnitude = $leftExponential['negativeBase'] ? \abs($target) : $target;
                $scaleMagnitude = $leftExponential['negativeBase']
                    ? \abs($leftExponential['scale'])
                    : $leftExponential['scale'];
                $logArgument = $targetMagnitude / $scaleMagnitude;
                if ($logArgument > 0.0 && \is_finite($logArgument)) {
                    $reducedEquations[] = [
                        $leftExponential['argument'],
                        new NumberNode(
                            \log($logArgument),
                            \sprintf('%.17g', \log($logArgument)),
                            $right->span(),
                        ),
                        $rightOffset,
                    ];
                    $changed = true;
                    continue;
                }
            }

            if (
                $rightExponential !== null
                && $rightExponential['scale'] !== 0.0
                && !$this->containsAnyVariable($left, $names)
            ) {
                try {
                    $target = $this->evaluate($left, $parameters, $evaluationOptions);
                } catch (EvaluationException) {
                    return null;
                }
                $targetMagnitude = $rightExponential['negativeBase'] ? \abs($target) : $target;
                $scaleMagnitude = $rightExponential['negativeBase']
                    ? \abs($rightExponential['scale'])
                    : $rightExponential['scale'];
                $logArgument = $targetMagnitude / $scaleMagnitude;
                if ($logArgument > 0.0 && \is_finite($logArgument)) {
                    $reducedEquations[] = [
                        new NumberNode(
                            \log($logArgument),
                            \sprintf('%.17g', \log($logArgument)),
                            $left->span(),
                        ),
                        $rightExponential['argument'],
                        $rightOffset,
                    ];
                    $changed = true;
                    continue;
                }
            }

            $reducedEquations[] = [$left, $right, $rightOffset];
        }
        if (!$changed) {
            return null;
        }

        $reducedValues = (new ExactAffineSystemSolver())->solve(
            $reducedEquations,
            $names,
            $values,
            $parameters,
            $evaluationOptions,
        );
        if ($reducedValues === null && \count($names) === 2) {
            $bivariateSolution = (new BivariatePolynomialSystemSolver())->solve(
                $reducedEquations,
                $equationSources,
                $names,
                $values,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($bivariateSolution !== null) {
                $reducedValues = \array_values($bivariateSolution->values);
            }
        }
        if ($reducedValues === null) {
            $indexesByVariable = \array_fill_keys($names, []);
            $separable = true;
            foreach ($reducedEquations as $equationIndex => [$left, $right]) {
                $variables = [];
                $this->collectVariableNodes($left, $variables);
                $this->collectVariableNodes($right, $variables);
                $usedNames = [];
                foreach ($variables as $variable) {
                    if (\in_array($variable->name, $names, true)) {
                        $usedNames[$variable->name] = true;
                    }
                }
                if (\count($usedNames) > 1) {
                    $separable = false;
                    break;
                }
                if ($usedNames !== []) {
                    $indexesByVariable[\array_key_first($usedNames)][] = $equationIndex;
                }
            }
            if ($separable) {
                $reducedValues = [];
                foreach ($names as $index => $name) {
                    $indexes = $indexesByVariable[$name];
                    if ($indexes === []) {
                        $reducedValues = null;
                        break;
                    }
                    $scalarEquations = [];
                    $scalarSources = [];
                    foreach ($indexes as $equationIndex) {
                        $scalarEquations[] = $reducedEquations[$equationIndex];
                        $scalarSources[] = $equationSources[$equationIndex];
                    }
                    $scalarSolution = $this->solveScalarPolynomialSystem(
                        $scalarEquations,
                        $scalarSources,
                        [$name],
                        [$values[$index]],
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    if (!$scalarSolution instanceof SystemSolution) {
                        $reducedValues = null;
                        break;
                    }
                    $reducedValues[] = $scalarSolution->values[$name];
                }
            }
        }
        if ($reducedValues === null || $reducedValues === false) {
            return null;
        }

        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $reducedValues,
            $parameters,
            $evaluationOptions,
        );
        if ($residuals === null) {
            return null;
        }
        if ($this->norm($residuals[1]) > $solverOptions->residualTolerance) {
            return null;
        }

        return new SystemSolution(
            \array_combine($names, $reducedValues),
            $this->norm($residuals[0]),
            0,
        );
    }

    /**
     * Solve systems that are polynomial in consistent positive-base powers
     * of their solved variables by using those powers as positive variables.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveLinearizedExponentialVariables(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $aliases = [];
        $bases = [];
        $usedNames = [...$names, ...\array_keys($parameters)];
        $linearizedEquations = [];
        $valid = true;
        foreach ($equations as [$left, $right, $rightOffset]) {
            $linearizedEquations[] = [
                $this->replaceExponentialVariables(
                    $left,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $this->replaceExponentialVariables(
                    $right,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $rightOffset,
            ];
        }
        if (!$valid || \count($aliases) !== \count($names)) {
            return null;
        }

        $aliasNames = [];
        $initialExponentialValues = [];
        foreach ($names as $index => $name) {
            $aliasNames[] = $aliases[$name];
            $exponentialValue = \pow($bases[$name], $values[$index]);
            $initialExponentialValues[] = \is_finite($exponentialValue) && $exponentialValue > 0.0
                ? $exponentialValue
                : 1.0;
        }
        $exponentialValues = (new ExactAffineSystemSolver())->solve(
            $linearizedEquations,
            $aliasNames,
            $initialExponentialValues,
            $parameters,
            $evaluationOptions,
        );
        if (($exponentialValues === null || $exponentialValues === false) && \count($names) === 2) {
            $bivariateSolution = (new BivariatePolynomialSystemSolver())->solve(
                $linearizedEquations,
                [],
                $aliasNames,
                $initialExponentialValues,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                true,
                $aliasNames,
                static function (array $candidateValues) use ($names, $values, $bases, $aliases): float {
                    $squaredDistance = 0.0;
                    foreach ($names as $index => $name) {
                        $candidate = $candidateValues[$aliases[$name]];
                        $difference = (\log($candidate) / \log($bases[$name])) - $values[$index];
                        $squaredDistance += $difference * $difference;
                    }

                    return \sqrt($squaredDistance);
                },
            );
            if ($bivariateSolution !== null) {
                $exponentialValues = \array_values($bivariateSolution->values);
            }
        }
        if (($exponentialValues === null || $exponentialValues === false) && \count($names) === 1) {
            $scalarExponentialValue = $this->solvePositiveScalarPolynomial(
                $linearizedEquations,
                $aliasNames[0],
                $values[0],
                $bases[$names[0]],
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($scalarExponentialValue !== null) {
                $exponentialValues = [$scalarExponentialValue];
            }
        }
        if (($exponentialValues === null || $exponentialValues === false) && \count($names) > 2) {
            $equationIndexesByAlias = \array_fill_keys($aliasNames, []);
            $separable = true;
            foreach ($linearizedEquations as $equationIndex => [$left, $right]) {
                $variables = [];
                $this->collectVariableNodes($left, $variables);
                $this->collectVariableNodes($right, $variables);
                $usedAliases = [];
                foreach ($variables as $variable) {
                    if (\in_array($variable->name, $aliasNames, true)) {
                        $usedAliases[$variable->name] = true;
                    }
                }
                if (\count($usedAliases) > 1) {
                    $separable = false;
                    break;
                }
                if ($usedAliases !== []) {
                    $equationIndexesByAlias[\array_key_first($usedAliases)][] = $equationIndex;
                }
            }
            if ($separable) {
                $separatedValues = [];
                foreach ($aliasNames as $index => $aliasName) {
                    $indexes = $equationIndexesByAlias[$aliasName];
                    if ($indexes === []) {
                        $separatedValues = [];
                        break;
                    }
                    $scalarEquations = [];
                    foreach ($indexes as $equationIndex) {
                        $scalarEquations[] = $linearizedEquations[$equationIndex];
                    }
                    $scalarValue = $this->solvePositiveScalarPolynomial(
                        $scalarEquations,
                        $aliasName,
                        $values[$index],
                        $bases[$names[$index]],
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    if ($scalarValue === null) {
                        $separatedValues = [];
                        break;
                    }
                    $separatedValues[] = $scalarValue;
                }
                if (\count($separatedValues) === \count($aliasNames)) {
                    $exponentialValues = $separatedValues;
                }
            }
        }
        if ($exponentialValues === null || $exponentialValues === false) {
            return null;
        }

        $solutionValues = [];
        foreach ($names as $index => $name) {
            $exponentialValue = $exponentialValues[$index];
            if (!\is_finite($exponentialValue) || $exponentialValue <= 0.0) {
                return null;
            }
            $solutionValues[] = \log($exponentialValue) / \log($bases[$name]);
        }

        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $solutionValues,
            $parameters,
            $evaluationOptions,
        );
        if ($residuals === null) {
            return null;
        }
        if ($this->norm($residuals[1]) > $solverOptions->residualTolerance) {
            return null;
        }

        return new SystemSolution(
            \array_combine($names, $solutionValues),
            $this->norm($residuals[0]),
            0,
        );
    }

    /**
     * Solve affine systems in consistent nonzero integer powers of shifted variables.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solvePowerLinearSystem(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $aliases = [];
        $powers = [];
        $shifts = [];
        $shiftKeys = [];
        $usedNames = [...$names, ...\array_keys($parameters)];
        $transformedEquations = [];
        $valid = true;
        foreach ($equations as [$left, $right, $rightOffset]) {
            $transformedEquations[] = [
                $this->replacePowerVariables(
                    $left,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $this->replacePowerVariables(
                    $right,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $rightOffset,
            ];
        }
        if (!$valid) {
            return null;
        }

        $aliasNames = [];
        $aliasVariables = [];
        $initialPowers = [];
        foreach ($names as $index => $name) {
            if (!isset($powers[$name])) {
                continue;
            }
            if (!isset($aliases[$name])) {
                return null;
            }
            $aliasNames[] = $aliases[$name];
            $aliasVariables[] = [$index, $name];
            $initialBase = $values[$index] + ($shifts[$name] ?? 0.0);
            $initialPower = $powers[$name] < 0 && $initialBase === 0.0
                ? 1.0
                : \pow($initialBase, $powers[$name]);
            if ($powers[$name] < 0 && (!\is_finite($initialPower) || $initialPower === 0.0)) {
                $initialPower = 1.0;
            }
            if (!\is_finite($initialPower)) {
                return null;
            }
            $initialPowers[] = $initialPower;
        }
        if ($aliasNames === []) {
            $powerValues = [];
        } else {
            $powerValues = (new ExactAffineSystemSolver())->solve(
                $transformedEquations,
                $aliasNames,
                $initialPowers,
                $parameters,
                $evaluationOptions,
            );
            if ($powerValues === null || $powerValues === false) {
                return null;
            }
        }

        $resolvedValues = [];
        foreach ($powerValues as $index => $powerValue) {
            [$valueIndex, $name] = $aliasVariables[$index];
            $power = $powers[$name];
            $shift = $shifts[$name] ?? 0.0;
            $initialBase = $values[$valueIndex] + $shift;
            $tolerance = 64.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($powerValue));
            if (
                !\is_finite($powerValue)
                || ($power < 0 && ($power % 2 === 0 ? $powerValue <= 0.0 : $powerValue === 0.0))
                || ($power > 0 && $power % 2 === 0 && $powerValue < -$tolerance)
            ) {
                return null;
            }
            if ($power % 2 === 0) {
                $root = \pow(\max(0.0, $powerValue), 1.0 / $power);
                $baseValue = $initialBase < 0.0 ? -$root : $root;
            } else {
                $root = \pow(\abs($powerValue), 1.0 / $power);
                $baseValue = $powerValue < 0.0 ? -$root : $root;
            }
            $candidateValue = $baseValue - $shift;
            if (!\is_finite($candidateValue)) {
                return null;
            }
            $resolvedValues[$name] = $candidateValue;
        }
        $candidateValues = [];
        foreach ($names as $index => $name) {
            $candidateValues[] = $resolvedValues[$name] ?? $values[$index];
        }

        $residuals = $this->residualsWithLocalZeroSideScales(
            $equations,
            $names,
            $candidateValues,
            $parameters,
            $evaluationOptions,
        );
        if (
            $residuals === null
            || $this->norm($residuals[1]) > $solverOptions->residualTolerance
        ) {
            return null;
        }

        return new SystemSolution(
            \array_combine($names, $candidateValues),
            $this->norm($residuals[0]),
            0,
        );
    }

    /**
     * @param list<string> $names
     * @param array<string, string> $aliases
     * @param array<string, int> $powers
     * @param array<string, float> $shifts
     * @param array<string, string|null> $shiftKeys
     * @param list<string> $usedNames
     * @param array<string, int|float> $parameters
     */
    private function replacePowerVariables(
        Node $node,
        array $names,
        array &$aliases,
        array &$powers,
        array &$shifts,
        array &$shiftKeys,
        array &$usedNames,
        bool &$valid,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): Node {
        $node = $this->ungroup($node);
        if (
            $node instanceof BinaryOperationNode
            && $node->operator === BinaryOperator::Power
            && RationalLiteral::fromNode($node->right) === [0, 1]
        ) {
            if ($this->signedSolvedVariableName($node->left, $names) !== null) {
                return new NumberNode(1, '1', $node->span);
            }
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Divide) {
            $numerator = $this->ungroup($node->left);
            $denominator = $this->ungroup($node->right);
            $denominatorSign = 1;
            $denominatorPower = 1;
            $denominatorShift = 0.0;
            $denominatorShiftKey = '0/1';
            $denominatorCoefficient = null;
            while ($denominator instanceof UnaryOperationNode) {
                if ($denominator->operator === UnaryOperator::Minus) {
                    $denominatorSign *= -1;
                }
                $denominator = $this->ungroup($denominator->operand);
            }
            if (
                $denominator instanceof BinaryOperationNode
                && $denominator->operator === BinaryOperator::Multiply
            ) {
                $leftFactor = $this->ungroup($denominator->left);
                $rightFactor = $this->ungroup($denominator->right);
                if (
                    !$this->containsAnyVariable($leftFactor, $names)
                    && $this->containsAnyVariable($rightFactor, $names)
                ) {
                    $denominatorCoefficient = $leftFactor;
                    $denominator = $rightFactor;
                } elseif (
                    !$this->containsAnyVariable($rightFactor, $names)
                    && $this->containsAnyVariable($leftFactor, $names)
                ) {
                    $denominatorCoefficient = $rightFactor;
                    $denominator = $leftFactor;
                }
            }
            if (
                $denominator instanceof BinaryOperationNode
                && $denominator->operator === BinaryOperator::Power
            ) {
                $powerNode = $denominator;
                $base = $this->ungroup($denominator->left);
                $baseCoefficient = null;
                if (!$base instanceof VariableNode) {
                    $baseForm = $this->affineVariable(
                        $base,
                        $names,
                    );
                    if ($baseForm !== null) {
                        $shift = $this->affineShift(
                            $baseForm,
                            $parameters,
                            $evaluationOptions,
                        );
                        if ($shift !== null) {
                            $base = new VariableNode($baseForm['name'], $base->span());
                            $baseCoefficient = $baseForm['scale'];
                            $denominatorShift = $shift;
                            $denominatorShiftKey = $this->affineShiftKey($baseForm);
                        }
                    }
                }
                $exponent = RationalLiteral::fromNode($powerNode->right);
                if (
                    $base instanceof VariableNode
                    && $exponent !== null
                    && $exponent[1] === 1
                    && $exponent[0] !== 0
                    && \abs($exponent[0]) <= 256
                ) {
                    if ($baseCoefficient !== null) {
                        $poweredCoefficient = new BinaryOperationNode(
                            BinaryOperator::Power,
                            $baseCoefficient,
                            $powerNode->right,
                            $powerNode->operatorSpan,
                        );
                        $denominatorCoefficient = $denominatorCoefficient === null
                            ? $poweredCoefficient
                            : new BinaryOperationNode(
                                BinaryOperator::Multiply,
                                $denominatorCoefficient,
                                $poweredCoefficient,
                                $powerNode->operatorSpan,
                            );
                    }
                    $denominator = $base;
                    $denominatorPower = $exponent[0];
                }
            }
            if (!$denominator instanceof VariableNode) {
                $denominatorForm = $this->affineVariable(
                    $denominator,
                    $names,
                );
                if ($denominatorForm !== null) {
                    $shift = $this->affineShift(
                        $denominatorForm,
                        $parameters,
                        $evaluationOptions,
                    );
                    if ($shift !== null) {
                        $denominator = new VariableNode($denominatorForm['name'], $denominator->span());
                        $denominatorCoefficient = $denominatorCoefficient === null
                            ? $denominatorForm['scale']
                            : new BinaryOperationNode(
                                BinaryOperator::Multiply,
                                $denominatorCoefficient,
                                $denominatorForm['scale'],
                                $node->operatorSpan,
                            );
                        $denominatorShift = $shift;
                        $denominatorShiftKey = $this->affineShiftKey($denominatorForm);
                    }
                }
            }
            $substitutedPower = -$denominatorPower;
            if (
                !$this->containsAnyVariable($numerator, $names)
                && $denominator instanceof VariableNode
                && \in_array($denominator->name, $names, true)
                && \abs($substitutedPower) <= 256
            ) {
                if (
                    isset($powers[$denominator->name])
                    && $powers[$denominator->name] !== $substitutedPower
                ) {
                    $valid = false;
                }
                if (
                    !$this->powerShiftMatches(
                        $denominator->name,
                        $denominatorShift,
                        $denominatorShiftKey,
                        $shifts,
                        $shiftKeys,
                    )
                ) {
                    $valid = false;
                }
                $powers[$denominator->name] = $substitutedPower;
                $shifts[$denominator->name] = $denominatorShift;
                $shiftKeys[$denominator->name] = $denominatorShiftKey;
                if (!\array_key_exists($denominator->name, $aliases)) {
                    $alias = '__mathphp_power_' . \count($aliases);
                    while (\in_array($alias, $usedNames, true)) {
                        $alias .= '_';
                    }
                    $aliases[$denominator->name] = $alias;
                    $usedNames[] = $alias;
                }
                $aliasNode = new VariableNode($aliases[$denominator->name], $node->span);
                $signedAlias = $denominatorSign < 0
                    ? new UnaryOperationNode(UnaryOperator::Minus, $aliasNode, $node->operatorSpan)
                    : $aliasNode;
                $scaledNumerator = $denominatorCoefficient === null
                    ? $numerator
                    : new BinaryOperationNode(
                        BinaryOperator::Divide,
                        $numerator,
                        $denominatorCoefficient,
                        $node->operatorSpan,
                    );

                return new BinaryOperationNode(
                    BinaryOperator::Multiply,
                    $scaledNumerator,
                    $signedAlias,
                    $node->operatorSpan,
                );
            }
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Power) {
            $base = $this->ungroup($node->left);
            $baseShift = 0.0;
            $baseShiftKey = '0/1';
            $baseCoefficient = null;
            if (!$base instanceof VariableNode) {
                $baseForm = $this->affineVariable(
                    $base,
                    $names,
                );
                if ($baseForm !== null) {
                    $shift = $this->affineShift(
                        $baseForm,
                        $parameters,
                        $evaluationOptions,
                    );
                    if ($shift !== null) {
                        $base = new VariableNode($baseForm['name'], $base->span());
                        $baseCoefficient = $baseForm['scale'];
                        $baseShift = $shift;
                        $baseShiftKey = $this->affineShiftKey($baseForm);
                    }
                }
            }
            $exponent = RationalLiteral::fromNode($node->right);
            if (
                $base instanceof VariableNode
                && \in_array($base->name, $names, true)
                && $exponent !== null
                && $exponent[1] === 1
                && $exponent[0] !== 0
                && $exponent[0] >= -256
                && $exponent[0] <= 256
            ) {
                if (isset($powers[$base->name]) && $powers[$base->name] !== $exponent[0]) {
                    $valid = false;
                }
                if (
                    !$this->powerShiftMatches(
                        $base->name,
                        $baseShift,
                        $baseShiftKey,
                        $shifts,
                        $shiftKeys,
                    )
                ) {
                    $valid = false;
                }
                $powers[$base->name] = $exponent[0];
                $shifts[$base->name] = $baseShift;
                $shiftKeys[$base->name] = $baseShiftKey;
                if (!\array_key_exists($base->name, $aliases)) {
                    $alias = '__mathphp_power_' . \count($aliases);
                    while (\in_array($alias, $usedNames, true)) {
                        $alias .= '_';
                    }
                    $aliases[$base->name] = $alias;
                    $usedNames[] = $alias;
                }
                $aliasNode = new VariableNode($aliases[$base->name], $node->span);
                if ($baseCoefficient !== null) {
                    $poweredCoefficient = new BinaryOperationNode(
                        BinaryOperator::Power,
                        $baseCoefficient,
                        $node->right,
                        $node->operatorSpan,
                    );

                    return new BinaryOperationNode(
                        BinaryOperator::Multiply,
                        $poweredCoefficient,
                        $aliasNode,
                        $node->operatorSpan,
                    );
                }

                return $aliasNode;
            }
        }
        $affineBase = $this->affineVariable(
            $node,
            $names,
        );
        if ($affineBase !== null) {
            $name = $affineBase['name'];
            $shift = $this->affineShift(
                $affineBase,
                $parameters,
                $evaluationOptions,
            );
            if ($shift === null) {
                return $node;
            }
            $shiftKey = $this->affineShiftKey($affineBase);
            if (
                (isset($powers[$name]) && $powers[$name] !== 1)
                || !$this->powerShiftMatches($name, $shift, $shiftKey, $shifts, $shiftKeys)
            ) {
                $valid = false;
            }
            $powers[$name] = 1;
            $shifts[$name] = $shift;
            $shiftKeys[$name] = $shiftKey;
            if (!\array_key_exists($name, $aliases)) {
                $alias = '__mathphp_power_' . \count($aliases);
                while (\in_array($alias, $usedNames, true)) {
                    $alias .= '_';
                }
                $aliases[$name] = $alias;
                $usedNames[] = $alias;
            }
            $aliasNode = new VariableNode($aliases[$name], $node->span());

            return new BinaryOperationNode(
                BinaryOperator::Multiply,
                $affineBase['scale'],
                $aliasNode,
                $node->span(),
            );
        }
        if ($node instanceof VariableNode) {
            if (\in_array($node->name, $names, true)) {
                if (isset($powers[$node->name]) && $powers[$node->name] !== 1) {
                    $valid = false;
                }
                if (!$this->powerShiftMatches($node->name, 0.0, '0/1', $shifts, $shiftKeys)) {
                    $valid = false;
                }
                $powers[$node->name] = 1;
                $shifts[$node->name] = 0.0;
                $shiftKeys[$node->name] = '0/1';
                if (!\array_key_exists($node->name, $aliases)) {
                    $alias = '__mathphp_power_' . \count($aliases);
                    while (\in_array($alias, $usedNames, true)) {
                        $alias .= '_';
                    }
                    $aliases[$node->name] = $alias;
                    $usedNames[] = $alias;
                }

                return new VariableNode($aliases[$node->name], $node->span);
            }

            return $node;
        }
        if ($node instanceof BinaryOperationNode) {
            return new BinaryOperationNode(
                $node->operator,
                $this->replacePowerVariables(
                    $node->left,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $this->replacePowerVariables(
                    $node->right,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }
        if ($node instanceof UnaryOperationNode) {
            return new UnaryOperationNode(
                $node->operator,
                $this->replacePowerVariables(
                    $node->operand,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }
        if ($node instanceof FunctionCallNode) {
            return new FunctionCallNode(
                $node->name,
                \array_map(
                    fn (Node $argument): Node => $this->replacePowerVariables(
                        $argument,
                        $names,
                        $aliases,
                        $powers,
                        $shifts,
                        $shiftKeys,
                        $usedNames,
                        $valid,
                        $parameters,
                        $evaluationOptions,
                    ),
                    $node->arguments,
                ),
                $node->nameSpan,
                $node->span,
            );
        }
        if ($node instanceof FactorialNode) {
            return new FactorialNode(
                $this->replacePowerVariables(
                    $node->operand,
                    $names,
                    $aliases,
                    $powers,
                    $shifts,
                    $shiftKeys,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }

        return $node;
    }

    /**
     * Extract an affine form while retaining its exact coefficient expressions.
     *
     * @param list<string> $names
     * @return array{name: string, scale: Node, offset: Node}|null
     */
    private function affineVariable(Node $node, array $names): ?array
    {
        $node = $this->ungroup($node);
        if ($node instanceof VariableNode) {
            return \in_array($node->name, $names, true)
                ? [
                    'name' => $node->name,
                    'scale' => new NumberNode(1, '1', $node->span()),
                    'offset' => new NumberNode(0, '0', $node->span()),
                ]
                : null;
        }
        if ($node instanceof UnaryOperationNode) {
            $form = $this->affineVariable($node->operand, $names);
            if ($form === null || $node->operator === UnaryOperator::Plus) {
                return $form;
            }

            return [
                'name' => $form['name'],
                'scale' => new UnaryOperationNode(UnaryOperator::Minus, $form['scale'], $node->operatorSpan),
                'offset' => new UnaryOperationNode(UnaryOperator::Minus, $form['offset'], $node->operatorSpan),
            ];
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if (
            $node->operator === BinaryOperator::Power
            && RationalLiteral::fromNode($node->right) === [1, 1]
        ) {
            return $this->affineVariable($node->left, $names);
        }
        if (
            $node->operator === BinaryOperator::Power
            && RationalLiteral::fromNode($node->right) === [0, 1]
        ) {
            $name = $this->signedSolvedVariableName($node->left, $names);
            if ($name !== null) {
                return [
                    'name' => $name,
                    'scale' => new NumberNode(0, '0', $node->span),
                    'offset' => new NumberNode(1, '1', $node->span),
                ];
            }
        }

        $left = $this->affineVariable($node->left, $names);
        $right = $this->affineVariable($node->right, $names);
        $leftIsConstant = $left !== null
            && RationalLiteral::fromNodeAsStrings($left['scale']) === ['0', '1'];
        $rightIsConstant = $right !== null
            && RationalLiteral::fromNodeAsStrings($right['scale']) === ['0', '1'];
        $leftConstant = $leftIsConstant ? $left['offset'] : $node->left;
        $rightConstant = $rightIsConstant ? $right['offset'] : $node->right;
        if ($leftIsConstant && $rightIsConstant) {
            return null;
        }
        if ($leftIsConstant) {
            $left = null;
        }
        if ($rightIsConstant) {
            $right = null;
        }
        if ($left !== null && $right !== null) {
            if ($left['name'] !== $right['name']) {
                return null;
            }
            $operatorSpan = $node->operatorSpan;
            $combine = static fn (BinaryOperator $operator, Node $a, Node $b): BinaryOperationNode =>
                new BinaryOperationNode($operator, $a, $b, $operatorSpan);
            if ($node->operator === BinaryOperator::Add || $node->operator === BinaryOperator::Subtract) {
                return [
                    'name' => $left['name'],
                    'scale' => $combine($node->operator, $left['scale'], $right['scale']),
                    'offset' => $combine($node->operator, $left['offset'], $right['offset']),
                ];
            }
            if ($node->operator === BinaryOperator::Multiply) {
                $leftIsConstant = RationalLiteral::fromNodeAsStrings($left['scale']) === ['0', '1'];
                $rightIsConstant = RationalLiteral::fromNodeAsStrings($right['scale']) === ['0', '1'];
                if (!$leftIsConstant && !$rightIsConstant) {
                    return null;
                }
                $leftLinearTerm = $combine(BinaryOperator::Multiply, $left['scale'], $right['offset']);
                $rightLinearTerm = $combine(BinaryOperator::Multiply, $right['scale'], $left['offset']);

                return [
                    'name' => $left['name'],
                    'scale' => $combine(BinaryOperator::Add, $leftLinearTerm, $rightLinearTerm),
                    'offset' => $combine(BinaryOperator::Multiply, $left['offset'], $right['offset']),
                ];
            }
            if (
                $node->operator === BinaryOperator::Divide
                && RationalLiteral::fromNodeAsStrings($right['scale']) === ['0', '1']
            ) {
                return [
                    'name' => $left['name'],
                    'scale' => $combine(BinaryOperator::Divide, $left['scale'], $right['offset']),
                    'offset' => $combine(BinaryOperator::Divide, $left['offset'], $right['offset']),
                ];
            }

            return null;
        }
        if ($left === null && $right === null) {
            return null;
        }
        $form = $left ?? $right;
        $constant = $left === null ? $leftConstant : $rightConstant;
        if ($this->containsAnyVariable($constant, $names)) {
            return null;
        }
        $operatorSpan = $node->operatorSpan;
        $combine = static fn (BinaryOperator $operator, Node $a, Node $b): BinaryOperationNode =>
            new BinaryOperationNode($operator, $a, $b, $operatorSpan);

        if ($node->operator === BinaryOperator::Add) {
            $form['offset'] = $combine(BinaryOperator::Add, $form['offset'], $constant);
        } elseif ($node->operator === BinaryOperator::Subtract && $left !== null) {
            $form['offset'] = $combine(BinaryOperator::Subtract, $form['offset'], $constant);
        } elseif ($node->operator === BinaryOperator::Subtract) {
            $form['scale'] = new UnaryOperationNode(UnaryOperator::Minus, $form['scale'], $node->operatorSpan);
            $form['offset'] = $combine(BinaryOperator::Subtract, $constant, $form['offset']);
        } elseif ($node->operator === BinaryOperator::Multiply) {
            $form['scale'] = $combine(BinaryOperator::Multiply, $form['scale'], $constant);
            $form['offset'] = $combine(BinaryOperator::Multiply, $form['offset'], $constant);
        } elseif ($node->operator === BinaryOperator::Divide && $left !== null) {
            $form['scale'] = $combine(BinaryOperator::Divide, $form['scale'], $constant);
            $form['offset'] = $combine(BinaryOperator::Divide, $form['offset'], $constant);
        } else {
            return null;
        }

        return $form;
    }

    /**
     * @param list<string> $names
     */
    private function signedSolvedVariableName(Node $node, array $names): ?string
    {
        $node = $this->ungroup($node);
        while ($node instanceof UnaryOperationNode) {
            $node = $this->ungroup($node->operand);
        }

        return $node instanceof VariableNode && \in_array($node->name, $names, true)
            ? $node->name
            : null;
    }

    /**
     * Compute the translated coordinate used for numerical root reconstruction.
     *
     * @param array{name: string, scale: Node, offset: Node} $form
     * @param array<string, int|float> $parameters
     */
    private function affineShift(
        array $form,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?float {
        $scaleFraction = RationalLiteral::fromNodeAsStrings($form['scale']);
        $offsetFraction = RationalLiteral::fromNodeAsStrings($form['offset']);
        if ($scaleFraction !== null && $offsetFraction !== null) {
            $arithmetic = new IntegerPolynomialExtractor('', [], 1);
            $shift = $arithmetic->ratio(
                $arithmetic->multiplyIntegerValues($offsetFraction[0], $scaleFraction[1]),
                $arithmetic->multiplyIntegerValues($offsetFraction[1], $scaleFraction[0]),
            );

            return \is_float($shift) && \is_finite($shift) ? $shift : null;
        }

        try {
            $scale = (float) $this->evaluate($form['scale'], $parameters, $evaluationOptions);
            $offset = (float) $this->evaluate($form['offset'], $parameters, $evaluationOptions);
            $shift = $offset / $scale;
        } catch (EvaluationException | \DomainException | \ArithmeticError | \ValueError) {
            return null;
        }

        return $scale !== 0.0 && \is_finite($shift) ? $shift : null;
    }

    /** @param array{name: string, scale: Node, offset: Node} $form */
    private function affineShiftKey(array $form): ?string
    {
        $scale = RationalLiteral::fromNodeAsStrings($form['scale']);
        $offset = RationalLiteral::fromNodeAsStrings($form['offset']);
        if ($scale === null || $offset === null) {
            return null;
        }

        $arithmetic = new IntegerPolynomialExtractor('', [], 1);
        $numerator = $arithmetic->multiplyIntegerValues($offset[0], $scale[1]);
        $denominator = $arithmetic->multiplyIntegerValues($offset[1], $scale[0]);
        if ($denominator === '0') {
            return null;
        }
        if ($arithmetic->sign($denominator) < 0) {
            $numerator = $arithmetic->negateIntegerValue($numerator);
            $denominator = $arithmetic->negateIntegerValue($denominator);
        }
        $divisor = $arithmetic->greatestCommonDivisorValues($numerator, $denominator);
        $numerator = $arithmetic->divideIntegerValueExactly($numerator, $divisor);
        $denominator = $arithmetic->divideIntegerValueExactly($denominator, $divisor);
        if ($numerator === null || $denominator === null) {
            return null;
        }

        return $numerator . '/' . $denominator;
    }

    /** @param array<string, float> $shifts
     * @param array<string, string|null> $shiftKeys
     */
    private function powerShiftMatches(
        string $name,
        float $shift,
        ?string $shiftKey,
        array $shifts,
        array $shiftKeys,
    ): bool {
        if (!\array_key_exists($name, $shifts)) {
            return true;
        }
        $previousKey = $shiftKeys[$name] ?? null;
        if ($shiftKey !== null && $previousKey !== null) {
            return $shiftKey === $previousKey;
        }

        return $shifts[$name] === $shift;
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param array<string, int|float> $parameters
     */
    private function solvePositiveScalarPolynomial(
        array $equations,
        string $variable,
        float $initialCoordinate,
        float $base,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?float {
        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $seedPolynomial = null;
        foreach ($equations as [$left, $right]) {
            try {
                $leftCoefficients = $extractor->extract($left);
                $rightCoefficients = $extractor->extract($right);
            } catch (EvaluationException) {
                return null;
            }
            if ($leftCoefficients === null || $rightCoefficients === null) {
                return null;
            }

            $degree = \max(\count($leftCoefficients), \count($rightCoefficients)) - 1;
            $coefficients = [];
            for ($power = 0; $power <= $degree; ++$power) {
                $coefficient = ($leftCoefficients[$power] ?? 0.0) - ($rightCoefficients[$power] ?? 0.0);
                if (!\is_finite($coefficient)) {
                    return null;
                }
                $coefficients[] = $coefficient;
            }
            while (\count($coefficients) > 1 && \end($coefficients) === 0.0) {
                \array_pop($coefficients);
            }
            if (\count($coefficients) === 1) {
                if ($coefficients[0] !== 0.0) {
                    return null;
                }
                continue;
            }
            if ($seedPolynomial === null || \count($coefficients) > \count($seedPolynomial)) {
                $seedPolynomial = $coefficients;
            }
        }
        if ($seedPolynomial === null) {
            return null;
        }

        $degree = \count($seedPolynomial) - 1;
        $leadingCoefficient = $seedPolynomial[$degree];
        $logScale = 0.0;
        for ($power = 0; $power < $degree; ++$power) {
            $coefficient = $seedPolynomial[$power];
            if ($coefficient !== 0.0) {
                $logScale = \max(
                    $logScale,
                    (\log(\abs($coefficient)) - \log(\abs($leadingCoefficient))) / ($degree - $power),
                );
            }
        }
        $variableScale = \exp($logScale);
        if (!\is_finite($variableScale) || $variableScale <= 0.0) {
            return null;
        }
        $logVariableScale = \log($variableScale);
        $maximumTermLog = -\INF;
        foreach ($seedPolynomial as $power => $coefficient) {
            if ($coefficient !== 0.0) {
                $maximumTermLog = \max(
                    $maximumTermLog,
                    \log(\abs($coefficient)) + ($power * $logVariableScale),
                );
            }
        }
        $scaledPolynomial = [];
        foreach ($seedPolynomial as $power => $coefficient) {
            $scaledPolynomial[] = $coefficient === 0.0
                ? 0.0
                : (($coefficient < 0.0 ? -1.0 : 1.0)
                    * \exp(\log(\abs($coefficient)) + ($power * $logVariableScale) - $maximumTermLog));
        }
        $scaledLeadingCoefficient = $scaledPolynomial[$degree];
        if ($scaledLeadingCoefficient === 0.0) {
            return null;
        }
        $largestRatio = 0.0;
        foreach (\array_slice($scaledPolynomial, 0, -1) as $coefficient) {
            $largestRatio = \max($largestRatio, \abs($coefficient / $scaledLeadingCoefficient));
        }
        $bound = 1.0 + $largestRatio;
        if (!\is_finite($bound)) {
            return null;
        }

        $polynomialSolver = new PolynomialSolver();
        $roots = $polynomialSolver->solve(
            $scaledPolynomial,
            -$bound,
            $bound,
            fn (float $value): ?float => $polynomialSolver->evaluatePolynomial($scaledPolynomial, $value),
            $solverOptions,
            fn (float $value): ?float =>
                $polynomialSolver->evaluatePolynomialMagnitude($scaledPolynomial, $value),
        );
        if ($roots === null) {
            return null;
        }
        \usort(
            $roots,
            fn (EquationSolution $first, EquationSolution $second): int =>
                \abs(
                    ((\log($variableScale) + \log(\max($first->value, \PHP_FLOAT_MIN))) / \log($base))
                    - $initialCoordinate,
                ) <=> \abs(
                    ((\log($variableScale) + \log(\max($second->value, \PHP_FLOAT_MIN))) / \log($base))
                    - $initialCoordinate,
                ),
        );
        foreach ($roots as $root) {
            $exponentialValue = $variableScale * $root->value;
            if ($root->value <= 0.0 || !\is_finite($exponentialValue)) {
                continue;
            }
            $residuals = $this->residualsWithLocalZeroSideScales(
                $equations,
                [$variable],
                [$exponentialValue],
                $parameters,
                $evaluationOptions,
            );
            if ($residuals === null) {
                continue;
            }
            if ($this->norm($residuals[1]) <= $solverOptions->residualTolerance) {
                return $exponentialValue;
            }
        }

        return null;
    }

    /**
     * @param list<string> $names
     * @param array<string, string> $aliases
     * @param array<string, float> $bases
     * @param list<string> $usedNames
     * @param array<string, int|float> $parameters
     */
    private function replaceExponentialVariables(
        Node $node,
        array $names,
        array &$aliases,
        array &$bases,
        array &$usedNames,
        bool &$valid,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): Node {
        $node = $this->ungroup($node);
        if (
            $node instanceof FunctionCallNode
            && $node->name === 'exp'
            && \count($node->arguments) === 1
        ) {
            $argument = $this->ungroup($node->arguments[0]);
            if ($argument instanceof VariableNode && \in_array($argument->name, $names, true)) {
                if (isset($bases[$argument->name]) && $bases[$argument->name] !== \M_E) {
                    $valid = false;
                }
                $bases[$argument->name] = \M_E;
                if (!\array_key_exists($argument->name, $aliases)) {
                    $alias = '__mathphp_exp_' . \count($aliases);
                    while (\in_array($alias, $usedNames, true)) {
                        $alias .= '_';
                    }
                    $aliases[$argument->name] = $alias;
                    $usedNames[] = $alias;
                }

                return new VariableNode($aliases[$argument->name], $node->span);
            }
        }

        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Power) {
            $exponent = $this->ungroup($node->right);
            if ($exponent instanceof VariableNode && \in_array($exponent->name, $names, true)) {
                try {
                    $base = $this->containsAnyVariable($node->left, $names)
                        ? 0.0
                        : (float) $this->evaluate($node->left, $parameters, $evaluationOptions);
                } catch (EvaluationException) {
                    $base = 0.0;
                }
                if (!\is_finite($base) || $base <= 0.0 || $base === 1.0) {
                    $valid = false;
                    return $node;
                }
                if (isset($bases[$exponent->name]) && $bases[$exponent->name] !== $base) {
                    $valid = false;
                }
                $bases[$exponent->name] = $base;
                if (!\array_key_exists($exponent->name, $aliases)) {
                    $alias = '__mathphp_exp_' . \count($aliases);
                    while (\in_array($alias, $usedNames, true)) {
                        $alias .= '_';
                    }
                    $aliases[$exponent->name] = $alias;
                    $usedNames[] = $alias;
                }

                return new VariableNode($aliases[$exponent->name], $node->span);
            }
        }

        if ($node instanceof VariableNode) {
            if (\in_array($node->name, $names, true)) {
                $valid = false;
            }
            return $node;
        }
        if ($node instanceof BinaryOperationNode) {
            return new BinaryOperationNode(
                $node->operator,
                $this->replaceExponentialVariables(
                    $node->left,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $this->replaceExponentialVariables(
                    $node->right,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }
        if ($node instanceof UnaryOperationNode) {
            return new UnaryOperationNode(
                $node->operator,
                $this->replaceExponentialVariables(
                    $node->operand,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }
        if ($node instanceof FactorialNode) {
            return new FactorialNode(
                $this->replaceExponentialVariables(
                    $node->operand,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                ),
                $node->operatorSpan,
            );
        }
        if ($node instanceof FunctionCallNode) {
            $arguments = [];
            foreach ($node->arguments as $argument) {
                $arguments[] = $this->replaceExponentialVariables(
                    $argument,
                    $names,
                    $aliases,
                    $bases,
                    $usedNames,
                    $valid,
                    $parameters,
                    $evaluationOptions,
                );
            }
            return new FunctionCallNode($node->name, $arguments, $node->nameSpan, $node->span);
        }

        return $node;
    }

    /**
     * @param list<string> $names
     * @param array<string, int|float> $parameters
     */
    private function simplifyLogarithmExponentComposition(
        Node $node,
        array $names,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?Node {
        $node = $this->ungroup($node);
        if ($node instanceof FunctionCallNode && $node->name === 'exp' && \count($node->arguments) === 1) {
            $logarithm = $this->ungroup($node->arguments[0]);
            if (
                $logarithm instanceof FunctionCallNode
                && $logarithm->name === 'ln'
                && \count($logarithm->arguments) === 1
            ) {
                return $this->simplifyLogarithmExponentComposition(
                    $logarithm->arguments[0],
                    $names,
                    $parameters,
                    $evaluationOptions,
                )
                    ?? $logarithm->arguments[0];
            }
        }
        if ($node instanceof FunctionCallNode && $node->name === 'ln' && \count($node->arguments) === 1) {
            $exponential = $this->ungroup($node->arguments[0]);
            if (
                $exponential instanceof FunctionCallNode
                && $exponential->name === 'exp'
                && \count($exponential->arguments) === 1
            ) {
                return $this->simplifyLogarithmExponentComposition(
                    $exponential->arguments[0],
                    $names,
                    $parameters,
                    $evaluationOptions,
                ) ?? $exponential->arguments[0];
            }
        }

        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Power) {
            $logarithm = $this->ungroup($node->right);
            if ($logarithm instanceof FunctionCallNode) {
                $logarithmArgument = null;
                if (
                    $logarithm->name === 'log'
                    && \count($logarithm->arguments) === 2
                    && $this->samePositiveNonUnitConstant(
                        $node->left,
                        $logarithm->arguments[1],
                        $names,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    $logarithmArgument = $logarithm->arguments[0];
                } elseif (
                    \count($logarithm->arguments) === 1
                    && ($base = $this->standardLogarithmBase($logarithm->name)) !== null
                    && $this->constantEquals(
                        $node->left,
                        $base,
                        $names,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    $logarithmArgument = $logarithm->arguments[0];
                }
                if ($logarithmArgument !== null) {
                    return $this->simplifyLogarithmExponentComposition(
                        $logarithmArgument,
                        $names,
                        $parameters,
                        $evaluationOptions,
                    ) ?? $logarithmArgument;
                }
            }
        }

        if ($node instanceof FunctionCallNode) {
            $power = null;
            if ($node->name === 'log' && \count($node->arguments) === 2) {
                $candidatePower = $this->ungroup($node->arguments[0]);
                if (
                    $candidatePower instanceof BinaryOperationNode
                    && $candidatePower->operator === BinaryOperator::Power
                    && $this->samePositiveNonUnitConstant(
                        $candidatePower->left,
                        $node->arguments[1],
                        $names,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    $power = $candidatePower;
                }
            } elseif (\count($node->arguments) === 1) {
                $base = $this->standardLogarithmBase($node->name);
                $candidatePower = $this->ungroup($node->arguments[0]);
                if (
                    $base !== null
                    && $candidatePower instanceof BinaryOperationNode
                    && $candidatePower->operator === BinaryOperator::Power
                    && $this->constantEquals(
                        $candidatePower->left,
                        $base,
                        $names,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    $power = $candidatePower;
                }
            }
            if ($power !== null) {
                return $this->simplifyLogarithmExponentComposition(
                    $power->right,
                    $names,
                    $parameters,
                    $evaluationOptions,
                ) ?? $power->right;
            }
        }

        if ($node instanceof BinaryOperationNode) {
            $left = $this->simplifyLogarithmExponentComposition(
                $node->left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            $right = $this->simplifyLogarithmExponentComposition(
                $node->right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($left !== null || $right !== null) {
                return new BinaryOperationNode(
                    $node->operator,
                    $left ?? $node->left,
                    $right ?? $node->right,
                    $node->operatorSpan,
                );
            }
        } elseif ($node instanceof UnaryOperationNode) {
            $operand = $this->simplifyLogarithmExponentComposition(
                $node->operand,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($operand !== null) {
                return new UnaryOperationNode($node->operator, $operand, $node->operatorSpan);
            }
        } elseif ($node instanceof FactorialNode) {
            $operand = $this->simplifyLogarithmExponentComposition(
                $node->operand,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($operand !== null) {
                return new FactorialNode($operand, $node->operatorSpan);
            }
        } elseif ($node instanceof FunctionCallNode) {
            $arguments = [];
            $changed = false;
            foreach ($node->arguments as $argument) {
                $simplified = $this->simplifyLogarithmExponentComposition(
                    $argument,
                    $names,
                    $parameters,
                    $evaluationOptions,
                );
                $arguments[] = $simplified ?? $argument;
                $changed = $changed || $simplified !== null;
            }
            if ($changed) {
                return new FunctionCallNode($node->name, $arguments, $node->nameSpan, $node->span);
            }
        }

        return null;
    }

    /**
     * @param list<string> $names
     * @param array<string, int|float> $parameters
     */
    private function samePositiveNonUnitConstant(
        Node $first,
        Node $second,
        array $names,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        if ($this->containsAnyVariable($first, $names) || $this->containsAnyVariable($second, $names)) {
            return false;
        }

        try {
            $firstValue = $this->evaluate($first, $parameters, $evaluationOptions);
            $secondValue = $this->evaluate($second, $parameters, $evaluationOptions);
        } catch (EvaluationException) {
            return false;
        }

        return $firstValue > 0.0
            && $firstValue !== 1.0
            && $firstValue === $secondValue;
    }

    private function standardLogarithmBase(string $name): ?float
    {
        return match ($name) {
            'ln' => \M_E,
            'log10' => 10.0,
            'log2' => 2.0,
            default => null,
        };
    }

    /**
     * @param list<string> $names
     * @param array<string, int|float> $parameters
     */
    private function constantEquals(
        Node $node,
        float $expected,
        array $names,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        if ($this->containsAnyVariable($node, $names)) {
            return false;
        }

        try {
            return (float) $this->evaluate($node, $parameters, $evaluationOptions) === $expected;
        } catch (EvaluationException) {
            return false;
        }
    }

    /**
     * @param list<string> $names
     * @param array<string, int|float> $parameters
     * @return array{argument: Node, scale: float, negativeBase: bool}|null
     */
    private function extractScaledExponentialTerm(
        Node $node,
        array $names,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?array {
        $parameters = \array_map(static fn (int|float $value): float => (float) $value, $parameters);
        $node = $this->ungroup($node);
        if (
            $node instanceof FunctionCallNode
            && $node->name === 'exp'
            && \count($node->arguments) === 1
        ) {
            return ['argument' => $node->arguments[0], 'scale' => 1.0, 'negativeBase' => false];
        }
        if ($node instanceof UnaryOperationNode) {
            $term = $this->extractScaledExponentialTerm(
                $node->operand,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($term !== null && $node->operator === UnaryOperator::Minus) {
                $term['scale'] *= -1.0;
            }

            return $term;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        if (
            $node->operator === BinaryOperator::Power
            && !$this->containsAnyVariable($node->right, $names)
        ) {
            try {
                $power = (float) $this->evaluate($node->right, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            $baseTerm = $this->extractScaledExponentialTerm(
                $node->left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if (
                $baseTerm !== null
                && !$baseTerm['negativeBase']
                && $baseTerm['scale'] > 0.0
                && \is_finite($power)
            ) {
                $argument = $baseTerm['argument'];
                if ($baseTerm['scale'] !== 1.0 && $power !== 0.0) {
                    $logScale = \log($baseTerm['scale']);
                    $argument = new BinaryOperationNode(
                        BinaryOperator::Add,
                        $argument,
                        new NumberNode(
                            $logScale,
                            \sprintf('%.17g', $logScale),
                            $node->left->span(),
                        ),
                        new SourceSpan($argument->span()->end, $argument->span()->end),
                    );
                }
                $poweredArgument = new BinaryOperationNode(
                    BinaryOperator::Multiply,
                    $argument,
                    new NumberNode(
                        $power,
                        \sprintf('%.17g', $power),
                        $node->right->span(),
                    ),
                    new SourceSpan($argument->span()->end, $argument->span()->end),
                );

                return ['argument' => $poweredArgument, 'scale' => 1.0, 'negativeBase' => false];
            }
        }

        if (
            $node->operator === BinaryOperator::Power
            && !$this->containsAnyVariable($node->left, $names)
        ) {
            try {
                $base = (float) $this->evaluate($node->left, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            $negativeVariableBase = $base < 0.0
                && $this->containsAnyVariable($node->right, $names);
            if (
                ($base > 0.0 || $negativeVariableBase)
                && \abs($base) !== 1.0
                && \is_finite($base)
            ) {
                $logBase = \log(\abs($base));
                if (\is_finite($logBase) && $logBase !== 0.0) {
                    // Negative-base candidates are checked against the original integer-domain power below.
                    $argument = new BinaryOperationNode(
                        BinaryOperator::Multiply,
                        new NumberNode(
                            $logBase,
                            \sprintf('%.17g', $logBase),
                            $node->left->span(),
                        ),
                        $node->right,
                        new SourceSpan(
                            $node->left->span()->end,
                            $node->left->span()->end,
                        ),
                    );

                    return [
                        'argument' => $argument,
                        'scale' => 1.0,
                        'negativeBase' => $negativeVariableBase,
                    ];
                }
            }
        }

        if (
            $node->operator === BinaryOperator::Multiply
            || $node->operator === BinaryOperator::Divide
        ) {
            $leftTerm = $this->extractScaledExponentialTerm(
                $node->left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            $rightTerm = $this->extractScaledExponentialTerm(
                $node->right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($leftTerm !== null && $rightTerm !== null) {
                if ($node->operator === BinaryOperator::Divide && $rightTerm['scale'] === 0.0) {
                    return null;
                }
                $scale = $node->operator === BinaryOperator::Multiply
                    ? $leftTerm['scale'] * $rightTerm['scale']
                    : $leftTerm['scale'] / $rightTerm['scale'];
                if (!\is_finite($scale)) {
                    return null;
                }
                $argument = new BinaryOperationNode(
                    $node->operator === BinaryOperator::Multiply
                        ? BinaryOperator::Add
                        : BinaryOperator::Subtract,
                    $leftTerm['argument'],
                    $rightTerm['argument'],
                    new SourceSpan(
                        $leftTerm['argument']->span()->end,
                        $leftTerm['argument']->span()->end,
                    ),
                );

                return [
                    'argument' => $argument,
                    'scale' => $scale,
                    'negativeBase' => $leftTerm['negativeBase'] || $rightTerm['negativeBase'],
                ];
            }
        }

        if (
            $node->operator === BinaryOperator::Divide
            && !$this->containsAnyVariable($node->left, $names)
        ) {
            $denominatorTerm = $this->extractScaledExponentialTerm(
                $node->right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($denominatorTerm !== null && $denominatorTerm['scale'] !== 0.0) {
                try {
                    $numerator = (float) $this->evaluate($node->left, $parameters, $evaluationOptions);
                } catch (EvaluationException) {
                    return null;
                }
                $scale = $numerator / $denominatorTerm['scale'];
                if (!\is_finite($scale)) {
                    return null;
                }
                $argument = new UnaryOperationNode(
                    UnaryOperator::Minus,
                    $denominatorTerm['argument'],
                    $node->operatorSpan,
                );

                return [
                    'argument' => $argument,
                    'scale' => $scale,
                    'negativeBase' => $denominatorTerm['negativeBase'],
                ];
            }
        }

        if (
            $node->operator === BinaryOperator::Multiply
            && !$this->containsAnyVariable($node->left, $names)
        ) {
            try {
                $scale = (float) $this->evaluate($node->left, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            $term = $this->extractScaledExponentialTerm(
                $node->right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($term !== null) {
                $term['scale'] *= $scale;

                return \is_finite($term['scale']) ? $term : null;
            }
        }
        if (
            $node->operator === BinaryOperator::Multiply
            && !$this->containsAnyVariable($node->right, $names)
        ) {
            try {
                $scale = (float) $this->evaluate($node->right, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            $term = $this->extractScaledExponentialTerm(
                $node->left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($term !== null) {
                $term['scale'] *= $scale;

                return \is_finite($term['scale']) ? $term : null;
            }
        }
        if (
            $node->operator === BinaryOperator::Divide
            && !$this->containsAnyVariable($node->right, $names)
        ) {
            try {
                $divisor = (float) $this->evaluate($node->right, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            if ($divisor === 0.0) {
                return null;
            }
            $term = $this->extractScaledExponentialTerm(
                $node->left,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($term !== null) {
                $term['scale'] /= $divisor;

                return \is_finite($term['scale']) ? $term : null;
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function containsAnyVariable(Node $node, array $names): bool
    {
        $variables = [];
        $this->collectVariableNodes($node, $variables);
        foreach ($variables as $variable) {
            if (\in_array($variable->name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search expanding intervals around a scalar initial value using the
     * equation solver's analytic reductions and bounded root isolation.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveScalarSystemByExpansion(
        array $equations,
        array $equationSources,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?SystemSolution {
        $initialValue = $values[0];
        $radius = 1.0;
        for ($expansion = 0; $expansion < 32; ++$expansion) {
            $radius = \min($radius, \PHP_FLOAT_MAX / 4.0);
            $lowerBound = \max(-\PHP_FLOAT_MAX, $initialValue - $radius);
            $upperBound = \min(\PHP_FLOAT_MAX, $initialValue + $radius);
            $width = $upperBound - $lowerBound;
            if (
                !\is_finite($width)
                || $width <= 0.0
                || $lowerBound > $initialValue
                || $upperBound < $initialValue
            ) {
                return null;
            }

            $candidates = [];
            foreach ($equationSources as $equationSource) {
                try {
                    $roots = (new EquationSolver())->solve(
                        $equationSource,
                        $names[0],
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (RootSearchLimitException | \DomainException | \InvalidArgumentException) {
                    continue;
                }

                foreach ($roots as $root) {
                    $candidateResidualData = $this->residualsWithLocalZeroSideScales(
                        $equations,
                        $names,
                        [$root->value],
                        $parameters,
                        $evaluationOptions,
                    );
                    if ($candidateResidualData === null) {
                        continue;
                    }
                    [$candidateResiduals, $normalizedResiduals] = $candidateResidualData;
                    if ($this->norm($normalizedResiduals) > $solverOptions->residualTolerance) {
                        continue;
                    }

                    $candidates[] = new SystemSolution(
                        [$names[0] => $root->value],
                        $this->norm($candidateResiduals),
                        $root->iterations,
                    );
                }
            }

            if ($candidates !== []) {
                \usort(
                    $candidates,
                    static fn (SystemSolution $first, SystemSolution $second): int =>
                        \abs($first->values[$names[0]] - $initialValue)
                        <=> \abs($second->values[$names[0]] - $initialValue),
                );

                return $candidates[0];
            }

            if ($radius === (\PHP_FLOAT_MAX / 4.0)) {
                return null;
            }
            $radius *= 2.0;
        }

        return null;
    }

    /**
     * Solve scalar polynomial systems through the bounded real-root isolator.
     * A null result means extraction or isolation was inconclusive; false means
     * the recognized polynomial has no real roots.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveScalarPolynomialSystem(
        array $equations,
        array $equationSources,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): SystemSolution|false|null {
        if (\count($names) !== 1) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $names[0],
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $seedPolynomial = null;
        $seedEquation = null;
        $polynomialConstraints = [];
        $exactCommonPolynomial = null;
        $exactPolynomialCount = 0;
        $exactExtractor = new ExactRationalPolynomialExtractor(
            $names[0],
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $rootCounter = new ExactPolynomialRootCounter();
        foreach ($equations as $equationIndex => [$left, $right]) {
            try {
                $leftCoefficients = $extractor->extract($left);
                $rightCoefficients = $extractor->extract($right);
            } catch (EvaluationException) {
                return null;
            }
            if ($leftCoefficients === null || $rightCoefficients === null) {
                return null;
            }

            $degree = \max(\count($leftCoefficients), \count($rightCoefficients)) - 1;
            $coefficients = [];
            for ($index = 0; $index <= $degree; ++$index) {
                $coefficient = ($leftCoefficients[$index] ?? 0.0)
                    - ($rightCoefficients[$index] ?? 0.0);
                if (!\is_finite($coefficient)) {
                    return null;
                }
                $coefficients[] = $coefficient;
            }
            while (\count($coefficients) > 1 && \end($coefficients) === 0.0) {
                \array_pop($coefficients);
            }
            $polynomialConstraints[] = $coefficients;
            $exactCoefficients = $exactExtractor->difference($left, $right);
            if ($exactCoefficients !== null && $exactCoefficients !== ['0']) {
                ++$exactPolynomialCount;
                $exactCommonPolynomial = $exactCommonPolynomial === null
                    ? $exactCoefficients
                    : $rootCounter->greatestCommonDivisor($exactCommonPolynomial, $exactCoefficients);
            }
            if (\count($coefficients) === 1) {
                if ($coefficients[0] !== 0.0) {
                    return false;
                }
                continue;
            }
            if ($seedPolynomial === null || \count($coefficients) > \count($seedPolynomial)) {
                $seedPolynomial = $coefficients;
                $seedEquation = $equationSources[$equationIndex];
            }
        }
        if ($seedPolynomial === null || $seedEquation === null) {
            return null;
        }
        if (
            $exactPolynomialCount > 1
            && $exactCommonPolynomial !== null
            && \count($exactCommonPolynomial) > 1
        ) {
            $exactRoots = $rootCounter->isolateAllRealRoots(
                $exactCommonPolynomial,
                maximumWidth: ['1', '1000000000000'],
            );
            if ($exactRoots !== null) {
                $candidates = [];
                foreach ($exactRoots as [$lowerNumerator, $lowerDenominator, $upperNumerator, $upperDenominator]) {
                    try {
                        $lower = new ExactRationalInterval(
                            [$lowerNumerator, $lowerDenominator],
                            [$lowerNumerator, $lowerDenominator],
                        );
                        $upper = new ExactRationalInterval(
                            [$upperNumerator, $upperDenominator],
                            [$upperNumerator, $upperDenominator],
                        );
                        $midpoint = $lower
                            ->add($upper)
                            ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                            ->lower;
                    } catch (\LengthException) {
                        continue;
                    }
                    $candidate = $this->rationalFractionToFloat($midpoint);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
                \usort(
                    $candidates,
                    static fn (float $first, float $second): int =>
                        \abs($first - $values[0]) <=> \abs($second - $values[0]),
                );
                foreach ($candidates as $candidate) {
                    $candidateScales = [];
                    $candidateResiduals = $this->residuals(
                        $equations,
                        $names,
                        [$candidate],
                        $parameters,
                        $evaluationOptions,
                        $candidateScales,
                    );
                    if (
                        $candidateResiduals === null
                        || $candidateScales === []
                        || \count($candidateResiduals) !== \count($equations)
                    ) {
                        continue;
                    }
                    $acceptable = true;
                    foreach ($candidateResiduals as $index => $residual) {
                        $polynomialScale = 0.0;
                        for ($degree = \count($polynomialConstraints[$index]) - 1; $degree >= 0; --$degree) {
                            $polynomialScale = ($polynomialScale * \abs($candidate))
                                + \abs($polynomialConstraints[$index][$degree]);
                        }
                        if (!\is_finite($polynomialScale)) {
                            $acceptable = false;
                            break;
                        }
                        $scale = \max($candidateScales[$index], $polynomialScale, \abs($residual));
                        if (
                            $scale > 0.0
                            && \abs($residual / $scale) > $solverOptions->residualTolerance
                        ) {
                            $acceptable = false;
                            break;
                        }
                    }
                    if ($acceptable) {
                        return new SystemSolution(
                            [$names[0] => $candidate],
                            $this->norm($candidateResiduals),
                            0,
                        );
                    }
                }
            }
        }
        $coefficients = $seedPolynomial;
        $leadingCoefficient = $coefficients[\count($coefficients) - 1];
        $largestRatio = 0.0;
        for ($index = 0; $index < \count($coefficients) - 1; ++$index) {
            $ratio = \abs($coefficients[$index] / $leadingCoefficient);
            if (!\is_finite($ratio)) {
                return null;
            }
            $largestRatio = \max($largestRatio, $ratio);
        }
        $bound = 1.0 + $largestRatio;
        if (!\is_finite($bound) || $bound > (\PHP_FLOAT_MAX / 2.0)) {
            return null;
        }

        $roots = (new EquationSolver())->solve(
            $seedEquation,
            $names[0],
            -$bound,
            $bound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($roots === []) {
            return false;
        }
        \usort(
            $roots,
            static fn (EquationSolution $first, EquationSolution $second): int =>
                \abs($first->value - $values[0]) <=> \abs($second->value - $values[0]),
        );
        foreach ($roots as $root) {
            $candidateScales = [];
            $candidateResiduals = $this->residuals(
                $equations,
                $names,
                [$root->value],
                $parameters,
                $evaluationOptions,
                $candidateScales,
            );
            if (
                $candidateResiduals === null
                || $candidateScales === []
                || \count($candidateResiduals) !== \count($equations)
            ) {
                continue;
            }
            $acceptable = true;
            foreach ($candidateResiduals as $index => $residual) {
                $scale = \max($candidateScales[$index], \abs($residual));
                if (
                    $scale > 0.0
                    && \abs($residual / $scale) > $solverOptions->residualTolerance
                ) {
                    $acceptable = false;
                    break;
                }
            }
            if (!$acceptable) {
                continue;
            }

            return new SystemSolution(
                [$names[0] => $root->value],
                $this->norm($candidateResiduals),
                $root->iterations,
            );
        }

        return null;
    }

    /**
     * Repeatedly substitute explicitly defined or affine variables until the
     * remaining system is scalar, then reconstruct and validate each value.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $equationSources
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     */
    private function solveTriangularSystem(
        array $equations,
        array $equationSources,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
        int &$remainingAttempts,
    ): ?SystemSolution {
        if (\count($names) < 2 || $remainingAttempts <= 0) {
            return null;
        }

        foreach ($equations as $definitionIndex => [$left, $right, $rightOffset]) {
            $definitions = [];
            $definitionSides = [[$left, $right, $rightOffset], [$right, $left, 0]];
            foreach ($definitionSides as [$definitionSide, $expression, $expressionOffset]) {
                $definitionSide = $this->ungroup($definitionSide);
                if (!$definitionSide instanceof VariableNode || !\in_array($definitionSide->name, $names, true)) {
                    continue;
                }

                $expressionSpan = $expression->span();
                $expressionSource = \substr(
                    $equationSources[$definitionIndex],
                    $expressionSpan->start + $expressionOffset,
                    $expressionSpan->length(),
                );
                $definitions[] = [$definitionSide->name, $expression, $expressionSource, false];
            }
            $zeroProductVariables = [
                ...$this->zeroProductVariableNames($left, $right, $names),
                ...$this->zeroProductVariableNames($right, $left, $names),
            ];
            foreach (\array_unique($zeroProductVariables) as $name) {
                if (\in_array($name, \array_column($definitions, 0), true)) {
                    continue;
                }
                try {
                    $zero = $this->parseSide('0', 0, $evaluationOptions);
                } catch (LexicalException | ParseException) {
                    continue;
                }
                $definitions[] = [$name, $zero, '0', false];
            }
            if ($this->isRationalArithmeticExpression($left) && $this->isRationalArithmeticExpression($right)) {
                foreach ($names as $name) {
                    $expressionSource = $this->isolatePolynomialVariableSource(
                        $right,
                        $left,
                        $equationSources[$definitionIndex],
                        $rightOffset,
                        0,
                        $name,
                    );
                    if ($expressionSource === null) {
                        $expressionSource = $this->isolatePolynomialVariableSource(
                            $left,
                            $right,
                            $equationSources[$definitionIndex],
                            0,
                            $rightOffset,
                            $name,
                        );
                    }
                    if (
                        $expressionSource !== null
                        && !\in_array($name, \array_column($definitions, 0), true)
                    ) {
                        try {
                            $expression = $this->parseSide($expressionSource, 0, $evaluationOptions);
                        } catch (LexicalException | ParseException) {
                            continue;
                        }
                        $definitions[] = [$name, $expression, $expressionSource, false];
                    }
                }
            }
            $affineForm = (new ExactAffineSystemSolver())->equationCoefficientForm(
                $left,
                $right,
                $names,
                $parameters,
                $evaluationOptions,
            );
            if ($affineForm !== null) {
                foreach ($names as $definedName) {
                    $coefficient = $affineForm['coefficients'][$definedName] ?? ['0', '1'];
                    if ($coefficient[0] === '0') {
                        continue;
                    }
                    $expressionSource = $this->affineSolutionExpression(
                        $affineForm,
                        $definedName,
                    );
                    try {
                        $expression = $this->parseSide($expressionSource, 0, $evaluationOptions);
                    } catch (LexicalException | ParseException) {
                        continue;
                    }
                    $definitions[] = [$definedName, $expression, $expressionSource, true];
                }
            }

            foreach ($definitions as [$definedName, $expression, $expressionSource, $affineDefinition]) {
                if ($remainingAttempts <= 0) {
                    return null;
                }
                --$remainingAttempts;
                $reducedNames = \array_values(\array_filter(
                    $names,
                    static fn (string $name): bool => $name !== $definedName,
                ));
                $expressionVariables = [];
                $this->collectVariableNodes($expression, $expressionVariables);
                $allowedNames = [
                    ...\array_fill_keys($reducedNames, true),
                    ...\array_fill_keys(\array_keys($parameters), true),
                ];
                $supportedExpression = true;
                foreach ($expressionVariables as $variableNode) {
                    if (!isset($allowedNames[$variableNode->name])) {
                        $supportedExpression = false;
                        break;
                    }
                }
                if (!$supportedExpression) {
                    continue;
                }

                if ($expressionSource === '') {
                    continue;
                }

                $reducedEquations = [];
                foreach ($equationSources as $equationIndex => $source) {
                    if ($equationIndex === $definitionIndex) {
                        continue;
                    }
                    [$otherLeft, $otherRight, $otherRightOffset] = $equations[$equationIndex];
                    $replacements = [];
                    foreach ([[$otherLeft, 0], [$otherRight, $otherRightOffset]] as [$side, $sideOffset]) {
                        $variableNodes = [];
                        $this->collectVariableNodes($side, $variableNodes);
                        foreach ($variableNodes as $variableNode) {
                            if ($variableNode->name === $definedName) {
                                $span = $variableNode->span;
                                $replacements[] = [
                                    $span->start + $sideOffset,
                                    $span->length(),
                                    '(' . $expressionSource . ')',
                                ];
                            }
                        }
                    }
                    \usort(
                        $replacements,
                        static fn (array $first, array $second): int => $second[0] <=> $first[0],
                    );
                    foreach ($replacements as [$start, $length, $replacement]) {
                        $source = \substr_replace($source, $replacement, $start, $length);
                    }
                    $reducedEquations[] = $source;
                }
                if ($reducedEquations === []) {
                    continue;
                }
                if (
                    $affineDefinition
                    && \count($reducedNames) === 1
                    && !$this->arePolynomialEquations(
                        $reducedEquations,
                        $reducedNames[0],
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    )
                ) {
                    continue;
                }

                $reducedInitialValues = [];
                foreach ($names as $index => $name) {
                    if ($name !== $definedName) {
                        $reducedInitialValues[$name] = $values[$index];
                    }
                }
                try {
                    if (\count($reducedNames) === 1) {
                        $reducedSolution = $this->solveFromInitialValues(
                            $reducedEquations,
                            $reducedInitialValues,
                            $parameters,
                            $evaluationOptions,
                            $solverOptions,
                        );
                    } else {
                        $reducedParsedEquations = $this->parseSystemEquations(
                            $reducedEquations,
                            $evaluationOptions,
                        );
                        if ($reducedParsedEquations === null) {
                            continue;
                        }
                        $reducedSolution = $this->solveTriangularSystem(
                            $reducedParsedEquations,
                            $reducedEquations,
                            $reducedNames,
                            \array_values($reducedInitialValues),
                            $parameters,
                            $evaluationOptions,
                            $solverOptions,
                            $remainingAttempts,
                        );
                        if ($reducedSolution === null) {
                            $reducedSolution = (new BivariatePolynomialSystemSolver())->solve(
                                $reducedParsedEquations,
                                $reducedEquations,
                                $reducedNames,
                                \array_values($reducedInitialValues),
                                $parameters,
                                $evaluationOptions,
                                $solverOptions,
                            );
                        }
                        if ($reducedSolution === null) {
                            $reducedSolution = $this->solveFromInitialValues(
                                $reducedEquations,
                                $reducedInitialValues,
                                $parameters,
                                $evaluationOptions,
                                $solverOptions,
                            );
                        }
                    }
                } catch (EvaluationException | LexicalException | ParseException) {
                    continue;
                }
                if ($reducedSolution === null) {
                    continue;
                }

                try {
                    $definedValue = (new Evaluator(
                        new Environment($parameters + $reducedSolution->values, $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    ))->evaluate($expression);
                } catch (EvaluationException) {
                    continue;
                }
                $candidateValueMap = $reducedSolution->values;
                $candidateValueMap[$definedName] = (float) $definedValue;
                $candidateValues = [];
                foreach ($names as $name) {
                    $candidateValues[] = $candidateValueMap[$name];
                }
                $candidateScales = [];
                $candidateResiduals = $this->residuals(
                    $equations,
                    $names,
                    $candidateValues,
                    $parameters,
                    $evaluationOptions,
                    $candidateScales,
                );
                $normalizedResiduals = $candidateResiduals === null
                    ? null
                    : $this->normalizeResiduals($candidateResiduals, $candidateScales);
                if (
                    $candidateResiduals === null
                    || $normalizedResiduals === null
                    || $this->norm($normalizedResiduals) > $solverOptions->residualTolerance
                ) {
                    continue;
                }

                return new SystemSolution(
                    \array_combine($names, $candidateValues),
                    $this->norm($candidateResiduals),
                    $reducedSolution->iterations,
                );
            }
        }

        return null;
    }

    private function isolatePolynomialVariableSource(
        Node $side,
        Node $target,
        string $equationSource,
        int $sideOffset,
        int $targetOffset,
        string $variable,
    ): ?string {
        $targetSpan = $target->span();
        $targetSource = \substr(
            $equationSource,
            $targetSpan->start + $targetOffset,
            $targetSpan->length(),
        );

        return $this->isolatePolynomialVariable(
            $side,
            $targetSource,
            $equationSource,
            $sideOffset,
            $variable,
        );
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function zeroProductVariableNames(Node $productSide, Node $zeroSide, array $names): array
    {
        $zero = RationalLiteral::fromNode($zeroSide);
        $productSide = $this->ungroup($productSide);
        if (
            $zero === null
            || $zero[0] !== 0
            || !$productSide instanceof BinaryOperationNode
            || $productSide->operator !== BinaryOperator::Multiply
        ) {
            return [];
        }

        $variables = [];
        foreach ([$productSide->left, $productSide->right] as $factor) {
            $factor = $this->ungroup($factor);
            if ($factor instanceof VariableNode && \in_array($factor->name, $names, true)) {
                $variables[] = $factor->name;
            }
        }

        return $variables;
    }

    private function isRationalArithmeticExpression(Node $node): bool
    {
        if ($node instanceof GroupingNode) {
            return $this->isRationalArithmeticExpression($node->expression);
        }
        if ($node instanceof NumberNode || $node instanceof VariableNode) {
            return true;
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isRationalArithmeticExpression($node->operand);
        }
        if (!$node instanceof BinaryOperationNode) {
            return false;
        }
        if (
            $node->operator === BinaryOperator::Add
            || $node->operator === BinaryOperator::Subtract
            || $node->operator === BinaryOperator::Multiply
            || $node->operator === BinaryOperator::Divide
        ) {
            return $this->isRationalArithmeticExpression($node->left)
                && $this->isRationalArithmeticExpression($node->right);
        }
        if ($node->operator !== BinaryOperator::Power) {
            return false;
        }
        $exponent = RationalLiteral::fromNode($node->right);
        return $exponent !== null
            && $exponent[1] === 1
            && \abs($exponent[0]) <= 256
            && $this->isRationalArithmeticExpression($node->left);
    }

    private function isolatePolynomialVariable(
        Node $side,
        string $targetSource,
        string $equationSource,
        int $sideOffset,
        string $variable,
    ): ?string {
        $side = $this->ungroup($side);
        if ($side instanceof VariableNode) {
            return $side->name === $variable ? $targetSource : null;
        }
        if ($side instanceof UnaryOperationNode) {
            $operandVariables = [];
            $this->collectVariableNodes($side->operand, $operandVariables);
            $containsVariable = false;
            foreach ($operandVariables as $operandVariable) {
                if ($operandVariable->name === $variable) {
                    $containsVariable = true;
                    break;
                }
            }
            if (!$containsVariable) {
                return null;
            }
            if ($side->operator === UnaryOperator::Minus) {
                $targetSource = '-(' . $targetSource . ')';
            }

            return $this->isolatePolynomialVariable(
                $side->operand,
                $targetSource,
                $equationSource,
                $sideOffset,
                $variable,
            );
        }
        if (!$side instanceof BinaryOperationNode) {
            return null;
        }

        $leftVariables = [];
        $rightVariables = [];
        $this->collectVariableNodes($side->left, $leftVariables);
        $this->collectVariableNodes($side->right, $rightVariables);
        $leftContainsVariable = false;
        foreach ($leftVariables as $leftVariable) {
            if ($leftVariable->name === $variable) {
                $leftContainsVariable = true;
                break;
            }
        }
        $rightContainsVariable = false;
        foreach ($rightVariables as $rightVariable) {
            if ($rightVariable->name === $variable) {
                $rightContainsVariable = true;
                break;
            }
        }
        if ($leftContainsVariable === $rightContainsVariable) {
            return null;
        }
        $leftSpan = $side->left->span();
        $rightSpan = $side->right->span();
        $leftSource = \substr($equationSource, $leftSpan->start + $sideOffset, $leftSpan->length());
        $rightSource = \substr($equationSource, $rightSpan->start + $sideOffset, $rightSpan->length());
        $otherSideSource = $leftContainsVariable ? $rightSource : $leftSource;
        $variableSide = $leftContainsVariable ? $side->left : $side->right;
        if ($side->operator === BinaryOperator::Add) {
            $nextTarget = '(' . $targetSource . ') - (' . $otherSideSource . ')';
        } elseif ($side->operator === BinaryOperator::Subtract) {
            $nextTarget = $leftContainsVariable
                ? '(' . $targetSource . ') + (' . $rightSource . ')'
                : '(' . $leftSource . ') - (' . $targetSource . ')';
        } elseif ($side->operator === BinaryOperator::Multiply) {
            $nextTarget = '(' . $targetSource . ') / (' . $otherSideSource . ')';
        } elseif ($side->operator === BinaryOperator::Divide) {
            $nextTarget = $leftContainsVariable
                ? '(' . $targetSource . ') * (' . $rightSource . ')'
                : '(' . $leftSource . ') / (' . $targetSource . ')';
        } else {
            return null;
        }

        return $this->isolatePolynomialVariable(
            $variableSide,
            $nextTarget,
            $equationSource,
            $sideOffset,
            $variable,
        );
    }

    /**
     * @param list<string> $equations
     * @param array<string, int|float> $parameters
     */
    private function arePolynomialEquations(
        array $equations,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): bool {
        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        foreach ($equations as $equation) {
            try {
                [$left, $right] = $this->splitEquation($equation);
                $leftPolynomial = $extractor->extract($this->parseSide($left, 0, $evaluationOptions));
                $rightPolynomial = $extractor->extract($this->parseSide($right, 0, $evaluationOptions));
            } catch (EvaluationException | LexicalException | ParseException) {
                return false;
            }
            if ($leftPolynomial === null || $rightPolynomial === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $form
     */
    private function affineSolutionExpression(array $form, string $definedVariable): string
    {
        $numerator = '(-1) * (' . $this->fractionExpression($form['constant']) . ')';
        foreach ($form['coefficients'] as $name => $coefficient) {
            if ($name === $definedVariable || $coefficient[0] === '0') {
                continue;
            }
            $numerator .= ' - (' . $this->fractionExpression($coefficient) . ') * (' . $name . ')';
        }

        return '(' . $numerator . ') / ('
            . $this->fractionExpression($form['coefficients'][$definedVariable]) . ')';
    }

    /** @param array{string, string} $fraction */
    private function fractionExpression(array $fraction): string
    {
        if ($fraction[1] === '1') {
            return $fraction[0];
        }

        return '(' . $fraction[0] . '/' . $fraction[1] . ')';
    }

    /**
     * @param list<string> $equations
     * @return list<array{Node, Node, int}>|null
     */
    private function parseSystemEquations(array $equations, EvaluationOptions $options): ?array
    {
        $parsed = [];
        foreach ($equations as $equation) {
            if (\strlen($equation) > $options->limits->maxExpressionLength) {
                return null;
            }
            try {
                [$left, $right, $rightOffset] = $this->splitEquation($equation);
                $parsed[] = [
                    $this->parseSide($left, 0, $options),
                    $this->parseSide($right, $rightOffset, $options),
                    $rightOffset,
                ];
            } catch (LexicalException | ParseException) {
                return null;
            }
        }

        return $parsed;
    }

    private function ungroup(Node $node): Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node;
    }

    /** @param list<VariableNode> $variables */
    private function collectVariableNodes(Node $node, array &$variables): void
    {
        if ($node instanceof VariableNode) {
            $variables[] = $node;
        } elseif ($node instanceof GroupingNode) {
            $this->collectVariableNodes($node->expression, $variables);
        } elseif ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            $this->collectVariableNodes($node->operand, $variables);
        } elseif ($node instanceof BinaryOperationNode) {
            $this->collectVariableNodes($node->left, $variables);
            $this->collectVariableNodes($node->right, $variables);
        } elseif ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $this->collectVariableNodes($argument, $variables);
            }
        }
    }

    /**
     * A real sum of positive multiples of even powers is zero exactly when
     * each power's base is zero. Expose those consequences to elimination.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $variables
     * @param array<string, int|float> $parameters
     * @return list<array{Node, Node, int}>
     */
    private function withZeroEvenPowerConsequences(
        array $equations,
        array $variables,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): array {
        $constantEvaluator = new Evaluator(
            new Environment($parameters, $evaluationOptions->functions),
            $evaluationOptions->limits,
        );
        $constrained = [];
        foreach ($equations as [$left, $right, $rightOffset]) {
            $sum = $this->isZeroNode($right)
                ? $left
                : ($this->isZeroNode($left) ? $right : null);
            if ($sum === null) {
                $constrained[] = [$left, $right, $rightOffset];
                continue;
            }

            $terms = [];
            $this->collectAdditiveTerms($sum, $terms);
            if (\count($terms) < 2) {
                $constrained[] = [$left, $right, $rightOffset];
                continue;
            }
            $bases = [];
            foreach ($terms as $term) {
                $base = $this->positiveEvenPowerBase($term, $variables, $constantEvaluator);
                if ($base === null) {
                    $bases = [];
                    break;
                }
                $bases[] = $base;
            }

            if ($bases === []) {
                $constrained[] = [$left, $right, $rightOffset];
                continue;
            }
            foreach ($bases as $base) {
                $constrained[] = [
                    $base,
                    new NumberNode(0, '0', $base->span()),
                    $rightOffset,
                ];
            }
        }

        return $constrained;
    }

    /**
     * @param list<string> $variables
     */
    private function positiveEvenPowerBase(
        Node $term,
        array $variables,
        Evaluator $constantEvaluator,
    ): ?Node {
        $factors = [];
        $this->collectMultiplicativeFactors($term, $factors);
        $base = null;
        foreach ($factors as $factor) {
            while ($factor instanceof GroupingNode) {
                $factor = $factor->expression;
            }
            if ($this->containsSolvedVariable($factor, $variables)) {
                if (
                    $base !== null
                    || !$factor instanceof BinaryOperationNode
                    || $factor->operator !== BinaryOperator::Power
                    || !$factor->right instanceof NumberNode
                    || !\is_int($factor->right->value)
                    || $factor->right->value < 2
                    || $factor->right->value % 2 !== 0
                ) {
                    return null;
                }
                $base = $factor->left;
                continue;
            }

            try {
                $coefficient = $constantEvaluator->evaluate($factor);
            } catch (EvaluationException) {
                return null;
            }
            if (!\is_finite((float) $coefficient) || $coefficient <= 0) {
                return null;
            }
        }

        return $base;
    }

    /** @param list<Node> $factors */
    private function collectMultiplicativeFactors(Node $node, array &$factors): void
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Multiply) {
            $this->collectMultiplicativeFactors($node->left, $factors);
            $this->collectMultiplicativeFactors($node->right, $factors);

            return;
        }

        $factors[] = $node;
    }

    /** @param list<string> $variables */
    private function containsSolvedVariable(Node $node, array $variables): bool
    {
        if ($node instanceof VariableNode) {
            return \in_array($node->name, $variables, true);
        }
        if ($node instanceof GroupingNode) {
            return $this->containsSolvedVariable($node->expression, $variables);
        }
        if ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            return $this->containsSolvedVariable($node->operand, $variables);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsSolvedVariable($node->left, $variables)
                || $this->containsSolvedVariable($node->right, $variables);
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsSolvedVariable($argument, $variables)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<Node> $terms */
    private function collectAdditiveTerms(Node $node, array &$terms): void
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Add) {
            $this->collectAdditiveTerms($node->left, $terms);
            $this->collectAdditiveTerms($node->right, $terms);

            return;
        }

        $terms[] = $node;
    }

    private function isZeroNode(Node $node): bool
    {
        if ($node instanceof NumberNode) {
            return $node->value === 0 || $node->value === 0.0;
        }
        if ($node instanceof GroupingNode) {
            return $this->isZeroNode($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isZeroNode($node->operand);
        }

        return false;
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @return array{list<float>, list<float>}|null Raw and normalized residuals.
     */
    private function residualsWithLocalZeroSideScales(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        $scales = [];
        $zeroSideRows = [];
        $residuals = $this->residuals(
            $equations,
            $names,
            $values,
            $parameters,
            $options,
            $scales,
            $zeroSideRows,
        );
        if ($residuals === null) {
            return null;
        }
        if (\in_array(true, $zeroSideRows, true)) {
            $scales = $this->zeroSideLocalScales(
                $equations,
                $names,
                $values,
                $parameters,
                $options,
                $scales,
                $zeroSideRows,
            );
        }
        $normalizedResiduals = $this->normalizeResiduals($residuals, $scales);

        return $normalizedResiduals === null ? null : [$residuals, $normalizedResiduals];
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param list<float> $scales
     * @param array<string, int|float> $parameters
     * @return list<list<float>>|null
     */
    private function jacobian(
        array $equations,
        array $names,
        array $values,
        array $scales,
        array $parameters,
        EvaluationOptions $options,
        bool $useRelativeSteps,
    ): ?array {
        $baseSideValues = $this->equationSideValues(
            $equations,
            $names,
            $values,
            $parameters,
            $options,
        );
        if ($baseSideValues === null) {
            return null;
        }

        $rowCount = \count($equations);
        $columnCount = \count($names);
        $matrix = [];
        for ($row = 0; $row < $rowCount; ++$row) {
            $matrixRow = [];
            for ($column = 0; $column < $columnCount; ++$column) {
                $matrixRow[] = 0.0;
            }
            $matrix[] = $matrixRow;
        }

        $differentiator = new ArithmeticDifferentiator();
        $variablesAtPoint = \array_combine($names, $values);
        for ($column = 0; $column < $columnCount; ++$column) {
            $derivativeParameters = $variablesAtPoint;
            unset($derivativeParameters[$names[$column]]);
            $derivativeParameters += $parameters;
            $finiteDifferenceRows = [];
            foreach ($equations as $row => [$left, $right]) {
                try {
                    $leftDerivative = $differentiator->differentiate(
                        $left,
                        $names[$column],
                        $values[$column],
                        $derivativeParameters,
                        $options,
                    );
                    $rightDerivative = $differentiator->differentiate(
                        $right,
                        $names[$column],
                        $values[$column],
                        $derivativeParameters,
                        $options,
                    );
                } catch (\DomainException) {
                    $leftDerivative = null;
                    $rightDerivative = null;
                }
                if ($leftDerivative === null || $rightDerivative === null) {
                    $finiteDifferenceRows[] = $row;
                    continue;
                }

                $scale = $scales[$row] > 0.0 ? $scales[$row] : 1.0;
                $matrix[$row][$column] = ($leftDerivative - $rightDerivative) / $scale;
                if (!\is_finite($matrix[$row][$column])) {
                    $finiteDifferenceRows[] = $row;
                }
            }
            if ($finiteDifferenceRows === []) {
                continue;
            }

            $step = $this->finiteDifferenceStep($values[$column], $useRelativeSteps);
            $plus = $values;
            $minus = $values;
            $plus[$column] += $step;
            $minus[$column] -= $step;
            $plus = \array_values($plus);
            $minus = \array_values($minus);
            $plusSideValues = \is_finite($plus[$column])
                ? $this->equationSideValues($equations, $names, $plus, $parameters, $options)
                : null;
            $minusSideValues = \is_finite($minus[$column])
                ? $this->equationSideValues($equations, $names, $minus, $parameters, $options)
                : null;

            if ($plusSideValues !== null && $minusSideValues !== null) {
                $denominator = $plus[$column] - $minus[$column];
                foreach ($finiteDifferenceRows as $row) {
                    $leftChange = $plusSideValues[$row][0] - $minusSideValues[$row][0];
                    $rightChange = $plusSideValues[$row][1] - $minusSideValues[$row][1];
                    $matrix[$row][$column] = ($leftChange - $rightChange)
                        / $denominator
                        / ($scales[$row] > 0.0 ? $scales[$row] : 1.0);
                }
            } elseif ($plusSideValues !== null) {
                $denominator = $plus[$column] - $values[$column];
                foreach ($finiteDifferenceRows as $row) {
                    $leftChange = $plusSideValues[$row][0] - $baseSideValues[$row][0];
                    $rightChange = $plusSideValues[$row][1] - $baseSideValues[$row][1];
                    $matrix[$row][$column] = ($leftChange - $rightChange)
                        / $denominator
                        / ($scales[$row] > 0.0 ? $scales[$row] : 1.0);
                }
            } elseif ($minusSideValues !== null) {
                $denominator = $values[$column] - $minus[$column];
                foreach ($finiteDifferenceRows as $row) {
                    $leftChange = $baseSideValues[$row][0] - $minusSideValues[$row][0];
                    $rightChange = $baseSideValues[$row][1] - $minusSideValues[$row][1];
                    $matrix[$row][$column] = ($leftChange - $rightChange)
                        / $denominator
                        / ($scales[$row] > 0.0 ? $scales[$row] : 1.0);
                }
            } else {
                return null;
            }

            foreach ($matrix as $row) {
                if (!\is_finite($row[$column])) {
                    return null;
                }
            }
        }

        return \array_map(
            static fn (array $row): array => \array_values($row),
            $matrix,
        );
    }

    /**
     * Evaluate both sides of every system equation without subtracting them.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @param list<bool>|null $rowMask
     * @return list<array{float, float}>|null
     */
    private function equationSideValues(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        ?array $rowMask = null,
    ): ?array {
        $variables = \array_combine($names, $values);
        $variables += $parameters;
        $sideValues = [];
        foreach ($equations as $row => [$left, $right, $rightOffset]) {
            if ($rowMask !== null && !$rowMask[$row]) {
                $sideValues[] = [0.0, 0.0];
                continue;
            }
            try {
                $leftValue = $this->evaluate($left, $variables, $options);
            } catch (EvaluationException $error) {
                if ($this->mustPropagate($error)) {
                    throw $error;
                }

                return null;
            }
            try {
                $rightValue = $this->evaluate($right, $variables, $options);
            } catch (EvaluationException $error) {
                if ($this->mustPropagate($error)) {
                    throw $this->shiftException($error, $rightOffset);
                }

                return null;
            }
            $sideValues[] = [(float) $leftValue, (float) $rightValue];
        }

        return $sideValues;
    }

    /**
     * @param list<list<float>> $jacobian
     * @param list<float> $residuals
     * @param list<float> $values
     * @return list<list<float>>
     */
    private function stepCandidates(
        array $jacobian,
        array $residuals,
        array $values,
    ): array {
        $equationCount = \count($residuals);
        $variableCount = \count($values);
        $steps = [];
        $newton = $equationCount === $variableCount
            ? $this->solveLinearSystem(
                $jacobian,
                \array_map(static fn (float $residual): float => -$residual, $residuals),
            )
            : null;
        if ($newton !== null) {
            $steps[] = $newton;
            $boundedNewton = $this->boundStep($newton, $this->trustRadius($values));
            if ($boundedNewton !== null) {
                $steps[] = $boundedNewton;
            }
        }

        $scale = 0.0;
        for ($column = 0; $column < $variableCount; ++$column) {
            $columnNorm = 0.0;
            for ($row = 0; $row < $equationCount; ++$row) {
                $columnNorm = \hypot($columnNorm, $jacobian[$row][$column]);
            }
            $scale = \max($scale, $columnNorm);
        }
        if ($scale === 0.0 || !\is_finite($scale)) {
            return $steps;
        }

        foreach ([1.0E-12, 1.0E-9, 1.0E-6, 1.0E-3, 1.0, 1.0E3] as $damping) {
            $regularization = $scale * \sqrt($damping);
            if (!\is_finite($regularization) || $regularization === 0.0) {
                continue;
            }
            $step = $this->solveAugmentedLeastSquares(
                $jacobian,
                $residuals,
                $regularization,
            );
            if ($step !== null) {
                $steps[] = $step;
                $boundedStep = $this->boundStep($step, $this->trustRadius($values));
                if ($boundedStep !== null) {
                    $steps[] = $boundedStep;
                }
            }
        }

        return $steps;
    }

    /**
     * Generate multiplicative Newton moves after additive steps stall.
     *
     * @param list<list<float>> $jacobian
     * @param list<float> $residuals
     * @param list<float> $values
     * @return list<list<float>>
     */
    private function multiplicativeStepCandidates(
        array $jacobian,
        array $residuals,
        array $values,
    ): array {
        $logarithmicColumns = \array_map(
            static fn (float $value): bool => $value !== 0.0,
            $values,
        );
        if (!\in_array(true, $logarithmicColumns, true)) {
            return [];
        }

        $logJacobian = [];
        foreach ($jacobian as $row) {
            $logRow = [];
            foreach ($row as $column => $coefficient) {
                $logRow[] = $logarithmicColumns[$column]
                    ? $coefficient * $values[$column]
                    : $coefficient;
            }
            $logJacobian[] = $logRow;
        }

        $equationCount = \count($residuals);
        $variableCount = \count($values);
        $logSteps = [];
        if ($equationCount === $variableCount) {
            $logStep = $this->solveLinearSystem(
                $logJacobian,
                \array_map(static fn (float $residual): float => -$residual, $residuals),
            );
            if ($logStep !== null) {
                $logSteps[] = $logStep;
            }
        } else {
            $scale = 0.0;
            foreach ($logJacobian as $row) {
                foreach ($row as $coefficient) {
                    $scale = \max($scale, \abs($coefficient));
                }
            }
            if ($scale > 0.0 && \is_finite($scale)) {
                foreach ([1.0E-12, 1.0E-6, 1.0E-3] as $damping) {
                    $logStep = $this->solveAugmentedLeastSquares(
                        $logJacobian,
                        $residuals,
                        $scale * \sqrt($damping),
                    );
                    if ($logStep !== null) {
                        $logSteps[] = $logStep;
                    }
                }
            }
        }

        $candidates = [];
        foreach ($logSteps as $logStep) {
            $largestComponent = 0.0;
            foreach ($logStep as $index => $component) {
                if ($logarithmicColumns[$index]) {
                    $largestComponent = \max($largestComponent, \abs($component));
                }
            }
            if ($largestComponent > 5.0) {
                $logStep = \array_map(
                    static fn (float $component): float =>
                        $component * (5.0 / $largestComponent),
                    $logStep,
                );
            }

            foreach ([1.0, 0.5, 0.25, 0.125] as $stepScale) {
                $candidate = [];
                foreach ($values as $index => $value) {
                    $change = $logarithmicColumns[$index]
                        ? $value * \expm1($stepScale * $logStep[$index])
                        : $stepScale * $logStep[$index];
                    if (!\is_finite($change)) {
                        $candidate = [];
                        break;
                    }
                    $candidate[] = $change;
                }
                if ($candidate !== []) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param list<float> $baseResiduals
     * @param list<float> $scales
     * @param array<string, int|float> $parameters
     * @param list<list<float>> $jacobian
     * @param float $residualTolerance
     * @return array{list<float>, list<float>}|null
     */
    private function coordinateStep(
        array $equations,
        array $names,
        array $values,
        array $baseResiduals,
        array $scales,
        array $parameters,
        EvaluationOptions $options,
        array $jacobian,
        float $residualTolerance,
    ): ?array {
        $coordinateScales = [];
        foreach ($values as $column => $value) {
            $coordinateScales[$column] = \max(1.0, \abs($value));
            foreach ($jacobian as $row) {
                $sensitivity = \abs($row[$column]);
                if ($sensitivity === 0.0) {
                    continue;
                }
                $characteristicChange = 1.0 / $sensitivity;
                $coordinateScales[$column] = \max(
                    $coordinateScales[$column],
                    \is_finite($characteristicChange)
                        ? $characteristicChange
                        : \PHP_FLOAT_MAX / 2.0,
                );
            }
        }
        $preferSparseMoves = \max([1.0, ...$coordinateScales])
            > (1.0 / \sqrt(\PHP_FLOAT_EPSILON));
        $bestNorm = $this->norm($baseResiduals);
        $best = null;
        $bestChangedCoordinates = \PHP_INT_MAX;
        $radiusScales = [];
        for ($exponent = 0; $exponent >= -300; --$exponent) {
            $radiusScales[] = 10.0 ** $exponent;
        }
        foreach ($radiusScales as $radiusScale) {
            foreach ($values as $index => $value) {
                foreach ([-1.0, 1.0] as $direction) {
                    $candidate = $values;
                    $candidate[$index] = $value
                        + ($direction * $coordinateScales[$index] * $radiusScale);
                    $candidate = \array_values($candidate);
                    if (!\is_finite($candidate[$index])) {
                        continue;
                    }
                    $candidateRawResiduals = $this->residuals(
                        $equations,
                        $names,
                        $candidate,
                        $parameters,
                        $options,
                    );
                    if ($candidateRawResiduals === null) {
                        continue;
                    }
                    $candidateResiduals = $this->normalizeResiduals(
                        $candidateRawResiduals,
                        $scales,
                    );
                    if ($candidateResiduals === null) {
                        continue;
                    }
                    $candidateNorm = $this->norm($candidateResiduals);
                    if ($candidateNorm === 0.0) {
                        return [$candidate, $candidateResiduals];
                    }
                    $changedCoordinates = $this->changedCoordinateCount($values, $candidate);
                    $tieTolerance = $residualTolerance * \max(1.0, $bestNorm);
                    if (
                        $candidateNorm < $bestNorm - $tieTolerance
                        || (
                            $best !== null
                            && $preferSparseMoves
                            && \abs($candidateNorm - $bestNorm) <= $tieTolerance
                            && $changedCoordinates < $bestChangedCoordinates
                        )
                    ) {
                        $bestNorm = $candidateNorm;
                        $best = [$candidate, $candidateResiduals];
                        $bestChangedCoordinates = $changedCoordinates;
                    }
                }
            }

            foreach (['same', 'alternating'] as $directionPattern) {
                foreach ([-1.0, 1.0] as $direction) {
                    $candidate = [];
                    foreach ($values as $index => $value) {
                        $componentDirection = (
                            $directionPattern === 'same'
                            || $index % 2 === 0
                        )
                            ? $direction
                            : -$direction;
                        $candidateValue = $value
                            + ($componentDirection * $coordinateScales[$index] * $radiusScale);
                        if (!\is_finite($candidateValue)) {
                            $candidate = [];
                            break;
                        }
                        $candidate[] = $candidateValue;
                    }
                    if ($candidate === []) {
                        continue;
                    }
                    $candidateRawResiduals = $this->residuals(
                        $equations,
                        $names,
                        $candidate,
                        $parameters,
                        $options,
                    );
                    if ($candidateRawResiduals === null) {
                        continue;
                    }
                    $candidateResiduals = $this->normalizeResiduals(
                        $candidateRawResiduals,
                        $scales,
                    );
                    if ($candidateResiduals === null) {
                        continue;
                    }
                    $candidateNorm = $this->norm($candidateResiduals);
                    if ($candidateNorm === 0.0) {
                        return [$candidate, $candidateResiduals];
                    }
                    $changedCoordinates = $this->changedCoordinateCount($values, $candidate);
                    $tieTolerance = $residualTolerance * \max(1.0, $bestNorm);
                    if (
                        $candidateNorm < $bestNorm - $tieTolerance
                        || (
                            $best !== null
                            && $preferSparseMoves
                            && \abs($candidateNorm - $bestNorm) <= $tieTolerance
                            && $changedCoordinates < $bestChangedCoordinates
                        )
                    ) {
                        $bestNorm = $candidateNorm;
                        $best = [$candidate, $candidateResiduals];
                        $bestChangedCoordinates = $changedCoordinates;
                    }
                }
            }
        }

        return $best;
    }

    /** @param list<float> $left
     *  @param list<float> $right
     */
    private function changedCoordinateCount(array $left, array $right): int
    {
        $changed = 0;
        foreach ($left as $index => $value) {
            if ($value !== $right[$index]) {
                ++$changed;
            }
        }

        return $changed;
    }

    /** @param list<float> $values */
    private function trustRadius(array $values): float
    {
        $radius = 1.0;
        foreach ($values as $value) {
            $radius = \max($radius, \abs($value));
        }

        return $radius;
    }

    /** @param list<float> $step
     * @return list<float>|null
     */
    private function boundStep(array $step, float $radius): ?array
    {
        $largestComponent = 0.0;
        foreach ($step as $component) {
            if (!\is_finite($component)) {
                return null;
            }
            $largestComponent = \max($largestComponent, \abs($component));
        }
        if ($largestComponent <= $radius || $largestComponent === 0.0) {
            return null;
        }

        $scale = $radius / $largestComponent;

        return \array_map(
            static fn (float $component): float => $component * $scale,
            $step,
        );
    }

    /**
     * Solve min ||J * step + residuals||^2 + regularization^2 * ||step||^2
     * using Householder QR on the augmented system, without forming J^T * J.
     *
     * @param list<list<float>> $jacobian
     * @param list<float> $residuals
     * @return list<float>|null
     */
    private function solveAugmentedLeastSquares(
        array $jacobian,
        array $residuals,
        float $regularization,
    ): ?array {
        $equationCount = \count($residuals);
        $variableCount = \count($jacobian[0]);
        $rowCount = $equationCount + $variableCount;
        $matrix = \array_fill(0, $rowCount, \array_fill(0, $variableCount, 0.0));
        $rightHandSide = \array_fill(0, $rowCount, 0.0);

        for ($row = 0; $row < $equationCount; ++$row) {
            $matrix[$row] = $jacobian[$row];
            $rightHandSide[$row] = -$residuals[$row];
        }
        for ($column = 0; $column < $variableCount; ++$column) {
            $matrix[$equationCount + $column][$column] = $regularization;
        }

        for ($column = 0; $column < $variableCount; ++$column) {
            $columnNorm = 0.0;
            for ($row = $column; $row < $rowCount; ++$row) {
                $columnNorm = \hypot($columnNorm, $matrix[$row][$column]);
            }
            if ($columnNorm === 0.0 || !\is_finite($columnNorm)) {
                return null;
            }

            $alpha = $matrix[$column][$column] >= 0.0 ? -$columnNorm : $columnNorm;
            $reflector = [];
            for ($row = $column; $row < $rowCount; ++$row) {
                $reflector[] = $matrix[$row][$column];
            }
            $reflector[0] -= $alpha;

            $reflectorNorm = 0.0;
            foreach ($reflector as $value) {
                $reflectorNorm = \hypot($reflectorNorm, $value);
            }
            if ($reflectorNorm === 0.0 || !\is_finite($reflectorNorm)) {
                return null;
            }
            foreach ($reflector as $index => $value) {
                $reflector[$index] = $value / $reflectorNorm;
            }

            for ($entry = $column; $entry < $variableCount; ++$entry) {
                $projection = 0.0;
                for ($row = $column; $row < $rowCount; ++$row) {
                    $projection += $reflector[$row - $column] * $matrix[$row][$entry];
                }
                for ($row = $column; $row < $rowCount; ++$row) {
                    $matrix[$row][$entry] -=
                        2.0 * $reflector[$row - $column] * $projection;
                    if (!\is_finite($matrix[$row][$entry])) {
                        return null;
                    }
                }
            }

            $projection = 0.0;
            for ($row = $column; $row < $rowCount; ++$row) {
                $projection += $reflector[$row - $column] * $rightHandSide[$row];
            }
            for ($row = $column; $row < $rowCount; ++$row) {
                $rightHandSide[$row] -=
                    2.0 * $reflector[$row - $column] * $projection;
                if (!\is_finite($rightHandSide[$row])) {
                    return null;
                }
            }

            $matrix[$column][$column] = $alpha;
            for ($row = $column + 1; $row < $rowCount; ++$row) {
                $matrix[$row][$column] = 0.0;
            }
        }

        $solution = \array_fill(0, $variableCount, 0.0);
        for ($row = $variableCount - 1; $row >= 0; --$row) {
            $value = $rightHandSide[$row];
            for ($column = $row + 1; $column < $variableCount; ++$column) {
                $value -= $matrix[$row][$column] * $solution[$column];
            }
            $solution[$row] = $value / $matrix[$row][$row];
            if (!\is_finite($solution[$row])) {
                return null;
            }
        }

        return \array_values($solution);
    }

    /**
     * @param list<list<float>> $matrix
     * @param list<float> $rightHandSide
     * @return list<float>|null
     */
    private function solveLinearSystem(array $matrix, array $rightHandSide): ?array
    {
        $size = \count($rightHandSide);
        for ($column = 0; $column < $size; ++$column) {
            $pivotRow = $column;
            for ($row = $column + 1; $row < $size; ++$row) {
                if (\abs($matrix[$row][$column]) > \abs($matrix[$pivotRow][$column])) {
                    $pivotRow = $row;
                }
            }

            if ($matrix[$pivotRow][$column] === 0.0) {
                return null;
            }
            if (!\is_finite($matrix[$pivotRow][$column])) {
                return null;
            }

            if ($pivotRow !== $column) {
                [$matrix[$pivotRow], $matrix[$column]] = [$matrix[$column], $matrix[$pivotRow]];
                [$rightHandSide[$pivotRow], $rightHandSide[$column]] =
                    [$rightHandSide[$column], $rightHandSide[$pivotRow]];
            }

            for ($row = $column + 1; $row < $size; ++$row) {
                $factor = $matrix[$row][$column] / $matrix[$column][$column];
                if (!\is_finite($factor)) {
                    return null;
                }
                for ($entry = $column; $entry < $size; ++$entry) {
                    $matrix[$row][$entry] -= $factor * $matrix[$column][$entry];
                    if (!\is_finite($matrix[$row][$entry])) {
                        return null;
                    }
                }
                $rightHandSide[$row] -= $factor * $rightHandSide[$column];
                if (!\is_finite($rightHandSide[$row])) {
                    return null;
                }
            }
        }

        $solution = \array_fill(0, $size, 0.0);
        for ($row = $size - 1; $row >= 0; --$row) {
            $value = $rightHandSide[$row];
            for ($column = $row + 1; $column < $size; ++$column) {
                $value -= $matrix[$row][$column] * $solution[$column];
            }
            $solution[$row] = $value / $matrix[$row][$row];
            if (!\is_finite($solution[$row])) {
                return null;
            }
        }

        return \array_values($solution);
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @param array<int, float>|null $equationScales
     * @param-out list<float> $equationScales
     * @param list<bool>|null $zeroSideRows
     * @param-out list<bool> $zeroSideRows
     * @return list<float>|null
     */
    private function residuals(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        ?array &$equationScales = null,
        ?array &$zeroSideRows = null,
    ): ?array {
        $variables = \array_combine($names, $values);
        $variables += $parameters;
        $residuals = [];
        $equationScales = [];
        $zeroSideRows = [];
        foreach ($equations as [$left, $right, $rightOffset]) {
            try {
                $leftValue = $this->evaluate($left, $variables, $options);
            } catch (EvaluationException $error) {
                if ($this->mustPropagate($error)) {
                    throw $error;
                }
                return null;
            }

            try {
                $rightValue = $this->evaluate($right, $variables, $options);
            } catch (EvaluationException $error) {
                if ($this->mustPropagate($error)) {
                    throw $this->shiftException($error, $rightOffset);
                }
                return null;
            }

            $residual = (float) $leftValue - (float) $rightValue;
            if (!\is_finite($residual)) {
                return null;
            }
            $residuals[] = $residual;
            $scale = \max(
                \abs((float) $leftValue),
                \abs((float) $rightValue),
                \abs($residual),
            );
            $equationScales[] = $scale;
            $zeroSideRows[] = (float) $leftValue === 0.0 || (float) $rightValue === 0.0;
        }

        return $residuals;
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @return list<float>|null
     */
    private function findDefinedStartingValues(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        bool $wideScaleProfiles = false,
    ): ?array {
        $variableCount = \count($values);
        $patterns = [];
        for ($index = 0; $index < $variableCount; ++$index) {
            foreach ([-1, 1] as $direction) {
                $pattern = \array_fill(0, $variableCount, 0);
                $pattern[$index] = $direction;
                $patterns[] = $pattern;
            }
        }
        $patterns[] = \array_fill(0, $variableCount, 1);
        $patterns[] = \array_fill(0, $variableCount, -1);
        $patterns[] = \array_map(
            static fn (int $index): int => $index % 2 === 0 ? 1 : -1,
            \range(0, $variableCount - 1),
        );
        $patterns[] = \array_map(
            static fn (int $direction): int => -$direction,
            $patterns[\array_key_last($patterns)],
        );
        if ($variableCount <= 7) {
            for ($mask = 0, $combinations = 1 << $variableCount; $mask < $combinations; ++$mask) {
                $patterns[] = \array_map(
                    static fn (int $index): int => (($mask >> $index) & 1) === 0 ? -1 : 1,
                    \range(0, $variableCount - 1),
                );
            }
        }
        $patternsByKey = [];
        foreach ($patterns as $pattern) {
            $patternsByKey[\implode(',', $pattern)] = $pattern;
        }
        $patterns = \array_values($patternsByKey);
        $magnitudeProfiles = [\array_fill(0, $variableCount, 1.0)];
        if ($variableCount <= 3) {
            for ($dominant = 0; $dominant < $variableCount; ++$dominant) {
                $profile = \array_fill(0, $variableCount, 1.0 / self::DOMAIN_PROFILE_RATIO);
                $profile[$dominant] = 1.0;
                $magnitudeProfiles[] = $profile;
                if ($wideScaleProfiles) {
                    for ($ratioExponent = 4; $ratioExponent <= 200; $ratioExponent += 4) {
                        $profile = \array_fill(0, $variableCount, 10.0 ** (-$ratioExponent));
                        $profile[$dominant] = 1.0;
                        $magnitudeProfiles[] = $profile;
                    }
                }
            }
        }

        $scales = [];
        $steps = [];
        $scaleHints = \array_fill_keys($names, 1.0);
        $hasVariableScaleHint = \array_fill_keys($names, false);
        foreach ($equations as [$left, $right]) {
            $equationVariables = [];
            $maximumLiteralMagnitude = 1.0;
            foreach ([$left, $right] as $node) {
                $this->collectVariableScaleHints(
                    $node,
                    $scaleHints,
                    $hasVariableScaleHint,
                );
                $this->collectDomainScaleHints(
                    $node,
                    $equationVariables,
                    $maximumLiteralMagnitude,
                );
            }
            if ($maximumLiteralMagnitude > self::DOMAIN_PROFILE_RATIO) {
                foreach ($equationVariables as $name => $_) {
                    if (\array_key_exists($name, $scaleHints) && !$hasVariableScaleHint[$name]) {
                        $scaleHints[$name] = \max(
                            $scaleHints[$name],
                            $maximumLiteralMagnitude,
                        );
                    }
                }
            }
        }
        foreach ($values as $index => $value) {
            $hint = $scaleHints[$names[$index]];
            $scale = \max(1.0, \abs($value), $wideScaleProfiles ? \sqrt($hint) : $hint);
            $scales[] = $scale;
            $steps[] = \max(
                ($wideScaleProfiles ? 1.0 : 1.0E-8) * $scale,
                8.0 * \PHP_FLOAT_EPSILON * $scale,
            );
        }

        $probeCount = 0;
        $maximumProbeCount = $wideScaleProfiles
            ? self::MAX_WIDE_SCALE_START_PROBES
            : self::MAX_DEFINED_START_PROBES;
        $bestValues = null;
        $bestResidualNorm = \INF;
        $bestScalePenalty = \INF;
        $bestDistance = \INF;
        if ($wideScaleProfiles) {
            foreach ($patterns as $pattern) {
                $candidate = [];
                foreach ($pattern as $index => $direction) {
                    $candidate[] = $direction * $scaleHints[$names[$index]];
                }
                if ($candidate === $values || \in_array(\INF, $candidate, true)) {
                    continue;
                }
                $candidateScales = [];
                $candidateResiduals = $this->residuals(
                    $equations,
                    $names,
                    $candidate,
                    $parameters,
                    $options,
                    $candidateScales,
                );
                if ($candidateResiduals === null) {
                    continue;
                }
                $normalizedResiduals = [];
                foreach ($candidateResiduals as $row => $residual) {
                    $scale = $candidateScales[$row];
                    $normalizedResiduals[] = $scale === 0.0 ? 0.0 : $residual / $scale;
                }
                $residualNorm = $this->norm($normalizedResiduals);
                $referenceScale = \max([1.0, ...$scales]);
                $scalePenalty = 0.0;
                foreach ($candidateScales as $scale) {
                    $scalePenalty += \log1p($scale / $referenceScale);
                }
                $distance = 0.0;
                foreach ($candidate as $candidateIndex => $candidateValue) {
                    $scaledDelta = ($candidateValue - $values[$candidateIndex])
                        / $scales[$candidateIndex];
                    $distance += $scaledDelta * $scaledDelta;
                }
                if (
                    $residualNorm < $bestResidualNorm
                    || ($residualNorm === $bestResidualNorm
                        && ($scalePenalty < $bestScalePenalty
                            || ($scalePenalty === $bestScalePenalty && $distance < $bestDistance)))
                ) {
                    $bestValues = $candidate;
                    $bestResidualNorm = $residualNorm;
                    $bestScalePenalty = $scalePenalty;
                    $bestDistance = $distance;
                }
                if ($residualNorm === 0.0) {
                    return $candidate;
                }
            }
        }
        $multipliers = [];
        if ($wideScaleProfiles) {
            for ($exponent = 0; $exponent <= 308; ++$exponent) {
                $decade = 10.0 ** $exponent;
                foreach ([1.0, 2.0, 5.0] as $mantissa) {
                    $multiplier = $mantissa * $decade;
                    if (\is_finite($multiplier)) {
                        $multipliers[] = $multiplier;
                    }
                }
            }
        } else {
            $multiplier = 1.0;
            while (\is_finite($multiplier)) {
                $multipliers[] = $multiplier;
                $multiplier *= 2.0;
            }
        }
        foreach ($multipliers as $multiplier) {
            foreach ($magnitudeProfiles as $magnitudeProfile) {
                foreach ($patterns as $pattern) {
                    if (++$probeCount > $maximumProbeCount) {
                        return $bestValues;
                    }
                    $candidate = [];
                    $distance = 0.0;
                    foreach ($values as $index => $value) {
                        $perturbed = $value + (
                            $pattern[$index]
                            * $steps[$index]
                            * $multiplier
                            * $magnitudeProfile[$index]
                        );
                        if (!\is_finite($perturbed)) {
                            $candidate = [];
                            break;
                        }
                        $candidate[] = $perturbed;
                        $scaledDelta = ($perturbed - $value) / $scales[$index];
                        $distance += $scaledDelta * $scaledDelta;
                    }
                    if ($candidate === [] || $candidate === $values) {
                        continue;
                    }
                    $candidateScales = [];
                    $candidateZeroSideRows = [];
                    $candidateResiduals = $this->residuals(
                        $equations,
                        $names,
                        $candidate,
                        $parameters,
                        $options,
                        $candidateScales,
                        $candidateZeroSideRows,
                    );
                    if ($candidateResiduals === null) {
                        continue;
                    }
                    $normalizedResiduals = [];
                    foreach ($candidateResiduals as $row => $residual) {
                        $scale = $candidateScales[$row];
                        $normalizedResiduals[] = $scale === 0.0 ? 0.0 : $residual / $scale;
                    }
                    $residualNorm = $this->norm($normalizedResiduals);
                    $referenceScale = \max([1.0, ...$scales]);
                    $scalePenalty = 0.0;
                    foreach ($candidateScales as $scale) {
                        $scalePenalty += \log1p($scale / $referenceScale);
                    }
                    if (
                        $residualNorm < $bestResidualNorm
                        || ($residualNorm === $bestResidualNorm
                            && ($scalePenalty < $bestScalePenalty
                                || ($scalePenalty === $bestScalePenalty && $distance < $bestDistance)))
                    ) {
                        $bestValues = $candidate;
                        $bestResidualNorm = $residualNorm;
                        $bestScalePenalty = $scalePenalty;
                        $bestDistance = $distance;
                    }
                    if ($residualNorm === 0.0) {
                        return $candidate;
                    }
                }
            }
        }

        return $bestValues;
    }

    /**
     * @param array<string, true> $variables
     */
    private function collectDomainScaleHints(
        Node $node,
        array &$variables,
        float &$maximumLiteralMagnitude,
    ): void {
        if ($node instanceof VariableNode) {
            $variables[$node->name] = true;

            return;
        }
        if ($node instanceof NumberNode) {
            $maximumLiteralMagnitude = \max(
                $maximumLiteralMagnitude,
                \abs((float) $node->value),
            );

            return;
        }
        if ($node instanceof BinaryOperationNode) {
            $this->collectDomainScaleHints($node->left, $variables, $maximumLiteralMagnitude);
            $this->collectDomainScaleHints($node->right, $variables, $maximumLiteralMagnitude);

            return;
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $this->collectDomainScaleHints($argument, $variables, $maximumLiteralMagnitude);
            }

            return;
        }
        if (
            $node instanceof FactorialNode
            || $node instanceof GroupingNode
            || $node instanceof UnaryOperationNode
        ) {
            $operand = $node instanceof GroupingNode ? $node->expression : $node->operand;
            $this->collectDomainScaleHints($operand, $variables, $maximumLiteralMagnitude);
        }
    }

    /**
     * Prefer literal magnitudes from the smallest subexpressions that contain
     * only one solved variable; equation-wide magnitudes remain a fallback.
     *
     * @param array<string, float> $scaleHints
     * @param array<string, bool> $hasVariableScaleHint
     */
    private function collectVariableScaleHints(
        Node $node,
        array &$scaleHints,
        array &$hasVariableScaleHint,
    ): void {
        $variables = [];
        $maximumLiteralMagnitude = 1.0;
        $this->collectVariableScaleHintFacts(
            $node,
            $variables,
            $maximumLiteralMagnitude,
        );
        if (\count($variables) === 1 && $maximumLiteralMagnitude > 1.0) {
            $name = \array_key_first($variables);
            if (\array_key_exists($name, $scaleHints)) {
                $scaleHints[$name] = \max($scaleHints[$name], $maximumLiteralMagnitude);
                $hasVariableScaleHint[$name] = true;
            }
        }

        if ($node instanceof BinaryOperationNode) {
            $this->collectVariableScaleHints($node->left, $scaleHints, $hasVariableScaleHint);
            $this->collectVariableScaleHints($node->right, $scaleHints, $hasVariableScaleHint);

            return;
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $this->collectVariableScaleHints($argument, $scaleHints, $hasVariableScaleHint);
            }

            return;
        }
        if (
            $node instanceof FactorialNode
            || $node instanceof GroupingNode
            || $node instanceof UnaryOperationNode
        ) {
            $operand = $node instanceof GroupingNode ? $node->expression : $node->operand;
            $this->collectVariableScaleHints($operand, $scaleHints, $hasVariableScaleHint);
        }
    }

    /**
     * Collect scale literals while excluding numeric exponents, whose values
     * describe powers rather than characteristic variable magnitudes.
     *
     * @param array<string, true> $variables
     */
    private function collectVariableScaleHintFacts(
        Node $node,
        array &$variables,
        float &$maximumLiteralMagnitude,
        bool $includeLiteralMagnitudes = true,
    ): void {
        if ($node instanceof VariableNode) {
            $variables[$node->name] = true;

            return;
        }
        if ($node instanceof NumberNode) {
            if ($includeLiteralMagnitudes) {
                $maximumLiteralMagnitude = \max(
                    $maximumLiteralMagnitude,
                    \abs((float) $node->value),
                );
            }

            return;
        }
        if ($node instanceof BinaryOperationNode) {
            $this->collectVariableScaleHintFacts(
                $node->left,
                $variables,
                $maximumLiteralMagnitude,
                $includeLiteralMagnitudes,
            );
            $this->collectVariableScaleHintFacts(
                $node->right,
                $variables,
                $maximumLiteralMagnitude,
                $includeLiteralMagnitudes && $node->operator !== \MathPHP\Ast\BinaryOperator::Power,
            );

            return;
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $this->collectVariableScaleHintFacts(
                    $argument,
                    $variables,
                    $maximumLiteralMagnitude,
                    $includeLiteralMagnitudes,
                );
            }

            return;
        }
        if (
            $node instanceof FactorialNode
            || $node instanceof GroupingNode
            || $node instanceof UnaryOperationNode
        ) {
            $operand = $node instanceof GroupingNode ? $node->expression : $node->operand;
            $this->collectVariableScaleHintFacts(
                $operand,
                $variables,
                $maximumLiteralMagnitude,
                $includeLiteralMagnitudes,
            );
        }
    }

    /**
     * A row that is exactly zero at the initial point has no usable magnitude
     * there. Probe one characteristic step in each variable to retain
     * invariance when such an equation is multiplied by a constant.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @param list<float> $scales
     * @return list<float>
     */
    private function calibrateZeroEquationScales(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        array $scales,
    ): array {
        $zeroScaleRows = [];
        foreach ($scales as $row => $scale) {
            if ($scale === 0.0) {
                $zeroScaleRows[] = $row;
            }
        }

        foreach ($values as $column => $value) {
            $step = max(1.0, abs($value));
            foreach ([$step, -$step] as $direction) {
                $probe = $values;
                $probe[$column] = $value + $direction;
                $probe = \array_values($probe);
                if (!is_finite($probe[$column]) || $probe[$column] === $value) {
                    continue;
                }

                $probeResiduals = $this->residuals(
                    $equations,
                    $names,
                    $probe,
                    $parameters,
                    $options,
                    $probeScales,
                );
                if ($probeResiduals === null) {
                    continue;
                }
                foreach ($zeroScaleRows as $row) {
                    $scales[$row] = max($scales[$row], $probeScales[$row] ?? 0.0);
                }
            }
        }

        foreach ($zeroScaleRows as $row) {
            if ($scales[$row] === 0.0) {
                $scales[$row] = 1.0;
            }
        }

        return \array_values($scales);
    }

    /**
     * Scale zero-side residuals by their local variation across the current
     * variable magnitudes. A fixed unit probe otherwise hides relative errors
     * in systems whose solutions are much smaller than one.
     *
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $values
     * @param array<string, int|float> $parameters
     * @param list<float> $fallbackScales
     * @param list<bool> $rowsToScale
     * @return list<float>
     */
    private function zeroSideLocalScales(
        array $equations,
        array $names,
        array $values,
        array $parameters,
        EvaluationOptions $options,
        array $fallbackScales,
        array $rowsToScale,
    ): array {
        $sideValues = $this->equationSideValues(
            $equations,
            $names,
            $values,
            $parameters,
            $options,
            $rowsToScale,
        );
        if ($sideValues === null) {
            return $fallbackScales;
        }

        $variationScales = \array_fill(0, \count($equations), 0.0);
        foreach ($values as $column => $value) {
            $step = $this->finiteDifferenceStep($value, true);
            $plus = $values;
            $minus = $values;
            $plus[$column] += $step;
            $minus[$column] -= $step;
            $plus = \array_values($plus);
            $minus = \array_values($minus);
            $plusSideValues = \is_finite($plus[$column])
                ? $this->equationSideValues(
                    $equations,
                    $names,
                    $plus,
                    $parameters,
                    $options,
                    $rowsToScale,
                )
                : null;
            $minusSideValues = \is_finite($minus[$column])
                ? $this->equationSideValues(
                    $equations,
                    $names,
                    $minus,
                    $parameters,
                    $options,
                    $rowsToScale,
                )
                : null;

            foreach ($equations as $row => $_equation) {
                if (!$rowsToScale[$row]) {
                    continue;
                }
                if ($plusSideValues !== null && $minusSideValues !== null) {
                    $derivative = (
                        ($plusSideValues[$row][0] - $minusSideValues[$row][0])
                        - ($plusSideValues[$row][1] - $minusSideValues[$row][1])
                    ) / ($plus[$column] - $minus[$column]);
                } elseif ($plusSideValues !== null) {
                    $derivative = (
                        ($plusSideValues[$row][0] - $sideValues[$row][0])
                        - ($plusSideValues[$row][1] - $sideValues[$row][1])
                    ) / ($plus[$column] - $value);
                } elseif ($minusSideValues !== null) {
                    $derivative = (
                        ($sideValues[$row][0] - $minusSideValues[$row][0])
                        - ($sideValues[$row][1] - $minusSideValues[$row][1])
                    ) / ($value - $minus[$column]);
                } else {
                    continue;
                }

                $variation = \abs($derivative * $value);
                if (\is_finite($variation)) {
                    $variationScales[$row] = \hypot($variationScales[$row], $variation);
                }
            }
        }

        foreach ($variationScales as $row => $scale) {
            if ($scale === 0.0) {
                $variationScales[$row] = $fallbackScales[$row];
            }
        }

        return \array_values($variationScales);
    }

    private function finiteDifferenceStep(float $value, bool $useRelativeSteps): float
    {
        $magnitude = \abs($value);
        if (!$useRelativeSteps) {
            return \sqrt(\PHP_FLOAT_EPSILON) * \max(1.0, $magnitude);
        }
        if ($magnitude === 0.0) {
            return \sqrt(\PHP_FLOAT_EPSILON);
        }

        $step = \sqrt(\PHP_FLOAT_EPSILON) * $magnitude;
        if (
            $step === 0.0
            || $value + $step === $value
            || $value - $step === $value
        ) {
            return $magnitude;
        }

        return $step;
    }

    /**
     * @param list<float> $residuals
     * @param list<float> $scales
     * @return list<float>|null
     */
    private function normalizeResiduals(array $residuals, array $scales): ?array
    {
        if (\count($residuals) !== \count($scales)) {
            return null;
        }

        foreach ($residuals as $index => $residual) {
            if ($scales[$index] === 0.0) {
                if ($residual !== 0.0) {
                    return null;
                }
                $residuals[$index] = 0.0;
                continue;
            }
            $residuals[$index] = $residual / $scales[$index];
            if (!\is_finite($residuals[$index])) {
                return null;
            }
        }

        return $residuals;
    }

    /** @param array<string, float> $variables */
    private function evaluate(Node $node, array $variables, EvaluationOptions $options): int|float
    {
        return (new Evaluator(
            new Environment($variables, $options->functions),
            $options->limits,
        ))->evaluate($node);
    }

    /** @return array{string, string, int} */
    private function splitEquation(string $equation): array
    {
        $position = \strpos($equation, '=');
        if ($position === false || \strpos($equation, '=', $position + 1) !== false) {
            $spanStart = $position === false ? \strlen($equation) : $position;
            throw new ParseException(
                'Equation must contain exactly one "=" sign',
                'parse.equation_equals',
                new SourceSpan($spanStart, $spanStart + ($position === false ? 0 : 1)),
            );
        }

        $left = \substr($equation, 0, $position);
        $right = \substr($equation, $position + 1);
        if (\trim($left) === '' || \trim($right) === '') {
            throw new ParseException(
                'Both sides of the equation must contain an expression',
                'parse.empty_equation_side',
                new SourceSpan($position, $position + 1),
            );
        }

        return [$left, $right, $position + 1];
    }

    /**
     * Expand a chained relation into the equivalent adjacent system constraints.
     *
     * @param list<string> $equations
     * @return list<string>
     */
    private function expandChainedEqualities(array $equations, EvaluationOptions $options): array
    {
        $expanded = [];
        foreach ($equations as $equation) {
            if (\strlen($equation) > $options->limits->maxExpressionLength) {
                $position = $options->limits->maxExpressionLength;
                throw new LexicalException(
                    'Equation exceeds the configured byte limit',
                    'limit.expression_length',
                    new SourceSpan($position, $position + 1),
                );
            }
            $parts = \explode('=', $equation);
            if (\count($parts) < 3) {
                $expanded[] = $equation;
                continue;
            }
            $partOffset = 0;
            foreach ($parts as $part) {
                $this->parseSide($part, $partOffset, $options);
                $partOffset += \strlen($part) + 1;
            }
            for ($index = 0; $index + 1 < \count($parts); ++$index) {
                if (\count($expanded) >= self::MAX_SYSTEM_DIMENSION) {
                    throw new \InvalidArgumentException(
                        'A system requires equations and initial values, with up to '
                            . self::MAX_SYSTEM_DIMENSION . ' of each.',
                    );
                }
                $expanded[] = $parts[$index] . '=' . $parts[$index + 1];
            }
        }

        return $expanded;
    }

    private function parseSide(string $expression, int $offset, EvaluationOptions $options): Node
    {
        try {
            return (new Parser(
                (new Lexer($expression, $options->limits))->tokenize(),
                $options->limits,
                $options->functions,
            ))->parse();
        } catch (LexicalException | ParseException $error) {
            throw $this->shiftException($error, $offset);
        }
    }

    private function mustPropagate(EvaluationException $error): bool
    {
        return \in_array(
            $error->errorCode(),
            [
                'eval.unknown_variable',
                'eval.unknown_function',
                'eval.arity',
                'eval.custom_function',
            ],
            true,
        );
    }

    private function shiftException(MathException $error, int $offset): MathException
    {
        if ($offset === 0) {
            return $error;
        }

        $message = \preg_replace('/ at position \d+$/', '', $error->getMessage());
        $span = new SourceSpan(
            $error->span()->start + $offset,
            $error->span()->end + $offset,
        );

        return match (true) {
            $error instanceof LexicalException => new LexicalException(
                $message ?? 'Equation expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
            $error instanceof ParseException => new ParseException(
                $message ?? 'Equation expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
            default => new EvaluationException(
                $message ?? 'Equation expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
        };
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

    /** @param array{string, string} $fraction */
    private function rationalFractionToFloat(array $fraction): ?float
    {
        [$numerator, $denominator] = $fraction;
        if ($numerator === '0') {
            return 0.0;
        }
        $negative = \str_starts_with($numerator, '-');
        $numeratorDigits = $negative ? \substr($numerator, 1) : $numerator;
        $directNumerator = (float) $numerator;
        $directDenominator = (float) $denominator;
        if (\is_finite($directNumerator) && \is_finite($directDenominator) && $directDenominator !== 0.0) {
            $directValue = $directNumerator / $directDenominator;
            if (\is_finite($directValue)) {
                return $directValue;
            }
        }

        $numeratorPrefix = \substr($numeratorDigits, 0, 17);
        $denominatorPrefix = \substr($denominator, 0, 17);
        $numeratorMantissa = (float) $numeratorPrefix / (10 ** (\strlen($numeratorPrefix) - 1));
        $denominatorMantissa = (float) $denominatorPrefix / (10 ** (\strlen($denominatorPrefix) - 1));
        $mantissa = $numeratorMantissa / $denominatorMantissa;
        $exponent = \strlen($numeratorDigits) - \strlen($denominator);
        $scientific = \sprintf('%.17ge%d', $mantissa, $exponent);
        $value = (float) $scientific;
        if (!\is_finite($value)) {
            return null;
        }

        return $negative ? -$value : $value;
    }
}
