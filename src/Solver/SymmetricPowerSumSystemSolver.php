<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;

/** Solve unweighted consecutive power-sum systems through Newton's identities. */
final class SymmetricPowerSumSystemSolver
{
    private const MIN_UNSCALED_ALIAS_MAGNITUDE = 1.0e-5;

    private const MAX_UNSCALED_ALIAS_MAGNITUDE = 1.0e5;

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $names
     * @param list<float> $initialValues
     * @param array<string, int|float> $parameters
     * @return list<float>|null
     */
    public function solve(
        array $equations,
        array $names,
        array $initialValues,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        $count = \count($names);
        if (
            $count < 2
            || \count($equations) < $count
            || $count > $solverOptions->maxPolynomialDegree
        ) {
            return null;
        }

        /** @var list<array<string, array{int, float}>> $variableTerms */
        $variableTerms = [];
        /** @var list<float> $powerSums */
        $powerSums = [];
        /** @var array<string, int> $basePowers */
        $basePowers = \array_fill_keys($names, 0);
        $environment = new Environment($parameters, $evaluationOptions->functions);
        $evaluator = new Evaluator($environment, $evaluationOptions->limits);
        foreach ($equations as [$left, $right]) {
            /** @var list<array{Node, int}> $terms */
            $terms = [];
            $this->collectTerms($left, 1, $terms);
            $this->collectTerms($right, -1, $terms);
            /** @var array<string, array{int, float}> $rowPowers */
            $rowPowers = [];
            $constant = 0.0;
            foreach ($terms as [$term, $sign]) {
                $powerTerm = $this->powerTerm($term, $names, $evaluator);
                if ($powerTerm !== null) {
                    [$name, $power, $coefficient] = $powerTerm;
                    if (isset($rowPowers[$name])) {
                        if ($rowPowers[$name][0] !== $power) {
                            return null;
                        }
                        $combinedCoefficient = $rowPowers[$name][1] + ($sign * $coefficient);
                        if (!\is_finite($combinedCoefficient)) {
                            return null;
                        }
                        if ($combinedCoefficient === 0.0) {
                            unset($rowPowers[$name]);
                        } else {
                            $rowPowers[$name][1] = $combinedCoefficient;
                        }
                        continue;
                    }
                    $rowPowers[$name] = [$power, $sign * $coefficient];
                    $basePowers[$name] = $basePowers[$name] === 0
                        ? $power
                        : $this->greatestCommonDivisor($basePowers[$name], $power);
                    continue;
                }

                if ($this->containsSolvedVariable($term, $names)) {
                    return null;
                }
                try {
                    $constant += $sign * (float) $evaluator->evaluate($term);
                } catch (\Throwable) {
                    return null;
                }
            }
            if (\count($rowPowers) !== $count) {
                return null;
            }
            $coefficients = \array_values(\array_map(static fn (array $term): float => $term[1], $rowPowers));
            $coefficientScale = 0.0;
            foreach ($coefficients as $coefficient) {
                $coefficientScale = \max($coefficientScale, \abs($coefficient));
            }
            if ($coefficientScale === 0.0) {
                return null;
            }
            $sharedCoefficient = 0.0;
            foreach ($coefficients as $coefficient) {
                if (
                    \abs($coefficient - $coefficients[0])
                    > 64.0 * \PHP_FLOAT_EPSILON * $coefficientScale
                ) {
                    return null;
                }
                $sharedCoefficient += $coefficient / $count;
            }
            if ($sharedCoefficient === 0.0 || !\is_finite($sharedCoefficient) || !\is_finite($constant)) {
                return null;
            }
            $variableTerms[] = $rowPowers;
            $powerSum = -$constant / $sharedCoefficient;
            if (!\is_finite($powerSum)) {
                return null;
            }
            $powerSums[] = $powerSum;
        }

        foreach ($basePowers as $power) {
            if ($power < 1) {
                return null;
            }
        }
        /** @var array<int, float> $orderedSums */
        $orderedSums = [];
        foreach ($variableTerms as $row => $rowPowers) {
            $normalizedPowers = [];
            foreach ($names as $name) {
                [$power] = $rowPowers[$name];
                if ($power % $basePowers[$name] !== 0) {
                    return null;
                }
                $normalizedPowers[] = \intdiv($power, $basePowers[$name]);
            }
            if (\count(\array_unique($normalizedPowers)) !== 1) {
                return null;
            }
            $degree = $normalizedPowers[0];
            if ($degree < 1) {
                return null;
            }
            if ($degree <= $count) {
                if (isset($orderedSums[$degree])) {
                    $scale = \max(\abs($orderedSums[$degree]), \abs($powerSums[$row]));
                    if (
                        \abs($orderedSums[$degree] - $powerSums[$row])
                        > 32.0 * PHP_FLOAT_EPSILON * $scale
                    ) {
                        return null;
                    }
                } else {
                    $orderedSums[$degree] = $powerSums[$row];
                }
            }
        }
        \ksort($orderedSums);
        if (\array_keys($orderedSums) !== \range(1, $count)) {
            return null;
        }

        $aliasScale = 0.0;
        foreach ($orderedSums as $degree => $powerSum) {
            $aliasScale = \max($aliasScale, \pow(\abs($powerSum), 1.0 / $degree));
        }
        if (!\is_finite($aliasScale)) {
            return null;
        }
        if ($aliasScale === 0.0) {
            $aliasScale = 1.0;
        }
        if (
            $aliasScale >= self::MIN_UNSCALED_ALIAS_MAGNITUDE
            && $aliasScale <= self::MAX_UNSCALED_ALIAS_MAGNITUDE
        ) {
            $aliasScale = 1.0;
        }
        $normalizedSums = [];
        $scalePower = 1.0;
        for ($degree = 1; $degree <= $count; ++$degree) {
            $scalePower *= $aliasScale;
            if (!\is_finite($scalePower) || $scalePower === 0.0) {
                return null;
            }
            $normalizedSums[$degree] = $orderedSums[$degree] / $scalePower;
            if (!\is_finite($normalizedSums[$degree])) {
                return null;
            }
        }

        $elementary = [1.0];
        for ($degree = 1; $degree <= $count; ++$degree) {
            $value = 0.0;
            for ($index = 1; $index <= $degree; ++$index) {
                $term = $elementary[$degree - $index] * $normalizedSums[$index];
                $value += $index % 2 === 1 ? $term : -$term;
            }
            $elementary[$degree] = $value / $degree;
            if (!\is_finite($elementary[$degree])) {
                return null;
            }
        }

        $coefficients = [];
        for ($degree = 0; $degree <= $count; ++$degree) {
            $elementaryIndex = $count - $degree;
            $coefficients[] = $elementary[$elementaryIndex]
                * ($elementaryIndex % 2 === 0 ? 1.0 : -1.0);
        }
        $roots = (new ComplexPolynomialSolver())->solve($coefficients, $solverOptions);
        if (!$roots->converged || \count($roots->roots) !== $count) {
            return null;
        }

        $realRoots = [];
        foreach ($roots->roots as $root) {
            if (\abs($root->imaginary) > $solverOptions->residualTolerance * \max(1.0, \abs($root->real))) {
                return null;
            }
            $realRoots[] = $root->real;
        }
        \sort($realRoots, SORT_NUMERIC);

        $variableIndexes = \range(0, $count - 1);
        $initialAliases = [];
        foreach ($names as $index => $name) {
            $initialAlias = \pow($initialValues[$index], $basePowers[$name]);
            if (!\is_finite($initialAlias)) {
                return null;
            }
            $initialAliases[] = $initialAlias / $aliasScale;
        }
        \usort(
            $variableIndexes,
            static fn (int $left, int $right): int => $initialAliases[$left] <=> $initialAliases[$right],
        );

        /** @var list<float> $candidate */
        $candidate = \array_fill(0, $count, 0.0);
        foreach ($variableIndexes as $rank => $variableIndex) {
            $name = $names[$variableIndex];
            $normalizedAlias = $realRoots[$rank];
            $alias = $normalizedAlias * $aliasScale;
            $power = $basePowers[$name];
            $magnitude = \pow(\abs($alias), 1.0 / $power);
            if (
                !\is_finite($alias)
                || !\is_finite($magnitude)
                || (
                    $power % 2 === 0
                    && $normalizedAlias
                        < -$solverOptions->residualTolerance * \max(1.0, \abs($normalizedAlias))
                )
            ) {
                return null;
            }
            $candidate[$variableIndex] = $power % 2 === 0
                ? ($initialValues[$variableIndex] < 0.0 ? -$magnitude : $magnitude)
                : ($alias < 0.0 ? -$magnitude : $magnitude);
        }

        return \array_values($candidate);
    }

    /** @param list<array{Node, int}> $terms */
    private function collectTerms(Node $node, int $sign, array &$terms): void
    {
        $node = $this->ungroup($node);
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Add) {
            $this->collectTerms($node->left, $sign, $terms);
            $this->collectTerms($node->right, $sign, $terms);

            return;
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Subtract) {
            $this->collectTerms($node->left, $sign, $terms);
            $this->collectTerms($node->right, -$sign, $terms);

            return;
        }
        if ($node instanceof UnaryOperationNode) {
            $this->collectTerms(
                $node->operand,
                $node->operator === UnaryOperator::Minus ? -$sign : $sign,
                $terms,
            );

            return;
        }

        $terms[] = [$node, $sign];
    }

    /**
     * @param list<string> $names
     * @return array{0: string, 1: int, 2: float}|null
     */
    private function powerTerm(Node $node, array $names, Evaluator $evaluator): ?array
    {
        $node = $this->ungroup($node);
        if ($node instanceof VariableNode && \in_array($node->name, $names, true)) {
            return [$node->name, 1, 1.0];
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if ($node->operator === BinaryOperator::Multiply || $node->operator === BinaryOperator::Divide) {
            $power = $this->powerTerm($node->left, $names, $evaluator);
            $coefficientNode = $node->right;
            if ($power === null && $node->operator === BinaryOperator::Multiply) {
                $power = $this->powerTerm($node->right, $names, $evaluator);
                $coefficientNode = $node->left;
            }
            if ($power === null || $this->containsSolvedVariable($coefficientNode, $names)) {
                return null;
            }
            try {
                $coefficient = (float) $evaluator->evaluate($coefficientNode);
            } catch (\Throwable) {
                return null;
            }
            if ($node->operator === BinaryOperator::Divide) {
                if ($coefficient === 0.0) {
                    return null;
                }
                $coefficient = 1.0 / $coefficient;
            }
            $scaledCoefficient = $power[2] * $coefficient;
            if (!\is_finite($scaledCoefficient)) {
                return null;
            }

            return [$power[0], $power[1], $scaledCoefficient];
        }
        if ($node->operator !== BinaryOperator::Power) {
            return null;
        }
        $base = $this->ungroup($node->left);
        $exponent = RationalLiteral::fromNode($node->right);
        if (
            !$base instanceof VariableNode
            || !\in_array($base->name, $names, true)
            || $exponent === null
            || $exponent[1] !== 1
            || $exponent[0] < 1
        ) {
            return null;
        }

        return [$base->name, $exponent[0], 1.0];
    }

    /** @param list<string> $names */
    private function containsSolvedVariable(Node $node, array $names): bool
    {
        $node = $this->ungroup($node);
        if ($node instanceof VariableNode) {
            return \in_array($node->name, $names, true);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsSolvedVariable($node->left, $names)
                || $this->containsSolvedVariable($node->right, $names);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsSolvedVariable($node->operand, $names);
        }

        return false;
    }

    private function ungroup(Node $node): Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node;
    }

    private function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }
}
