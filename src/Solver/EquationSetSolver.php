<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\ConstantNode;
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
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\ParseException;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Math;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Source\SourceSpan;

final class EquationSetSolver
{
    private const MAX_VARIABLE_INTEGER_LEVELS = 65_536;

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    public function solve(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        $this->validateInputs(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
        );
        if (\substr_count($equation, '=') > 1) {
            return $this->solveChainedEqualitySet(
                $equation,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        [$left, $right] = $this->splitEquation($equation);
        if (
            $this->hasGloballyInvalidRootDegree($left, $variable, $parameters, $evaluationOptions)
            || $this->hasGloballyInvalidRootDegree($right, $variable, $parameters, $evaluationOptions)
        ) {
            return [];
        }
        if (
            !$this->containsVariable($left, $variable)
            && !$this->containsVariable($right, $variable)
            && !(
                $evaluationOptions->functions->find($variable) === null
                && $this->containsVariableCall($left, $variable, $evaluationOptions)
            )
            && !(
                $evaluationOptions->functions->find($variable) === null
                && $this->containsVariableCall($right, $variable, $evaluationOptions)
            )
        ) {
            return $this->solveVariableIndependentEquation(
                $left,
                $right,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
        }
        $signReduction = $this->solveSignEqualityReduction(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($signReduction !== null) {
            return $signReduction;
        }
        $zeroBasePowerReduction = $this->solveZeroBasePowerEqualityReduction(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($zeroBasePowerReduction !== null) {
            return $zeroBasePowerReduction;
        }
        $extremumReduction = $this->solveExtremumEqualityReduction(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($extremumReduction !== null) {
            return $extremumReduction;
        }
        $quantizerReduction = $this->solveQuantizerEqualityReduction(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($quantizerReduction !== null) {
            return $quantizerReduction;
        }
        $variableIntegerArgumentIntervals = $this->solveVariableIntegerArgumentSet(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($variableIntegerArgumentIntervals !== null) {
            return $variableIntegerArgumentIntervals;
        }
        $piecewiseModuloWork = 65_536;
        $piecewiseModuloIntervals = $this->solveSinglePiecewiseModuloSet(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
            $piecewiseModuloWork,
        );
        if ($piecewiseModuloIntervals !== null) {
            return $piecewiseModuloIntervals;
        }
        $smoothModuloEquality = $this->solveSmoothModuloEquality(
            $equation,
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($smoothModuloEquality !== null) {
            return $smoothModuloEquality;
        }
        $sameArgumentFloorCeilSet = $this->solveSameArgumentFloorCeilSet(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($sameArgumentFloorCeilSet !== null) {
            return $sameArgumentFloorCeilSet;
        }
        $exactAffineSolutions = $this->solveExactIntegerAffineEquation(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($exactAffineSolutions !== null) {
            return $exactAffineSolutions;
        }

        $nestedSelfCompositionFixedPoint = $this->solveNestedSelfCompositionFixedPoint(
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $evaluationOptions,
        );
        if ($nestedSelfCompositionFixedPoint !== null) {
            return $nestedSelfCompositionFixedPoint;
        }

        $isKnownIdentity = $this->isKnownIdentity(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );

        $inequalitySolver = new InequalitySolver();
        if ($isKnownIdentity) {
            return $inequalitySolver->solve(
                '0 * (' . $left . ') + 0 * (' . $right . ') >= 0',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        $lessThanOrEqual = $inequalitySolver->solve(
            '(' . $left . ') <= (' . $right . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $greaterThanOrEqual = $inequalitySolver->solve(
            '(' . $left . ') >= (' . $right . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );

        $intersections = $this->intersect($lessThanOrEqual, $greaterThanOrEqual);
        $intersections = $this->removeDiscontinuousEqualityArtifacts(
            $intersections,
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
        );
        $intersections = $this->removeDominatedBoundaryPoints(
            $intersections,
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $intersections = $this->deduplicateNearbyPointIntervals(
            $intersections,
            $left,
            $right,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );

        if (
            \count($intersections) === 1
            && $intersections[0]->lowerBound === $lowerBound
            && $intersections[0]->upperBound === $upperBound
            && $intersections[0]->includesLowerBound
            && $intersections[0]->includesUpperBound
            && $this->containsOpaqueFunctionCallInEquation($left, $right, $evaluationOptions)
        ) {
            throw new RootSearchLimitException(
                'The equation appears to hold throughout the interval, but an opaque custom function prevents identity certification.',
            );
        }

        return $this->merge($intersections);
    }

    private function containsOpaqueFunctionCallInEquation(
        string $left,
        string $right,
        EvaluationOptions $evaluationOptions,
    ): bool {
        try {
            foreach ([$left, $right] as $source) {
                $node = (new Parser(
                    (new Lexer($source, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
                if ($this->containsOpaqueFunctionCall($node, $evaluationOptions)) {
                    return true;
                }
            }
        } catch (MathException) {
            return false;
        }

        return false;
    }

    private function containsOpaqueFunctionCall(Node $node, EvaluationOptions $evaluationOptions): bool
    {
        if ($node instanceof FunctionCallNode) {
            if (!$evaluationOptions->functions->isBuiltIn($node->name)) {
                return true;
            }
            foreach ($node->arguments as $argument) {
                if ($this->containsOpaqueFunctionCall($argument, $evaluationOptions)) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsOpaqueFunctionCall($node->expression, $evaluationOptions);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsOpaqueFunctionCall($node->operand, $evaluationOptions);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsOpaqueFunctionCall($node->left, $evaluationOptions)
                || $this->containsOpaqueFunctionCall($node->right, $evaluationOptions);
        }
        if ($node instanceof FactorialNode) {
            return $this->containsOpaqueFunctionCall($node->operand, $evaluationOptions);
        }

        return false;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSignEqualityReduction(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        foreach ([[$left, $right], [$right, $left]] as [$signSource, $targetSource]) {
            try {
                $signNode = (new Parser(
                    (new Lexer($signSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
            } catch (MathException) {
                return null;
            }
            while ($signNode instanceof GroupingNode) {
                $signNode = $signNode->expression;
            }
            if (
                !$signNode instanceof FunctionCallNode
                || $signNode->name !== 'sign'
                || \count($signNode->arguments) !== 1
                || !$evaluationOptions->functions->isBuiltIn('sign')
            ) {
                continue;
            }
            $argumentSpan = $signNode->arguments[0]->span();
            $argumentSource = \substr(
                $signSource,
                $argumentSpan->start,
                $argumentSpan->end - $argumentSpan->start,
            );
            if ($this->containsVariable($targetSource, $variable)) {
                return $this->solveSignEqualityWithVariableTarget(
                    $argumentSource,
                    $targetSource,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }
            try {
                $target = Math::evaluate($targetSource, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            $targetSign = (int) $target;
            if (
                !\in_array($targetSign, [-1, 0, 1], true)
                || (float) $target !== (float) $targetSign
            ) {
                return [];
            }

            if ($targetSign === 0) {
                $zeroEquation = '(' . $argumentSource . ') = 0';
                $intervals = $this->solve(
                    $zeroEquation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                try {
                    $roots = (new EquationSolver())->solve(
                        $zeroEquation,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (InfiniteSolutionSetException) {
                    return $intervals;
                }

                $intervals = \array_values(\array_filter(
                    $intervals,
                    static fn (InequalityInterval $interval): bool =>
                        $interval->lowerBound < $interval->upperBound,
                ));
                foreach ($roots as $root) {
                    $intervals[] = new InequalityInterval($root->value, $root->value, true, true);
                }
                \usort(
                    $intervals,
                    static fn (InequalityInterval $first, InequalityInterval $second): int =>
                        ($first->lowerBound <=> $second->lowerBound)
                        ?: ($first->upperBound <=> $second->upperBound),
                );

                return $this->merge($intervals);
            }

            $operator = $targetSign === 1 ? '>' : '<';

            return (new InequalitySolver())->solve(
                '(' . $argumentSource . ') ' . $operator . ' 0',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        return null;
    }

    /**
     * Classify zero-base powers using the evaluator's 0^0 = 1 convention.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveZeroBasePowerEqualityReduction(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        foreach ([[$left, $right], [$right, $left]] as [$powerSource, $targetSource]) {
            try {
                $powerNode = (new Parser(
                    (new Lexer($powerSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
            } catch (MathException) {
                continue;
            }
            while ($powerNode instanceof GroupingNode) {
                $powerNode = $powerNode->expression;
            }
            if (
                !$powerNode instanceof BinaryOperationNode
                || $powerNode->operator !== BinaryOperator::Power
                || $this->constantExpressionValue(
                    $powerNode->left,
                    $powerSource,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                ) !== 0.0
                || !$this->containsVariable($powerSource, $variable)
            ) {
                continue;
            }
            try {
                $target = Math::evaluate($targetSource, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return [];
            }
            if ((float) $target !== 0.0 && (float) $target !== 1.0) {
                return [];
            }
            $exponentSpan = $powerNode->right->span();
            $exponentSource = \substr(
                $powerSource,
                $exponentSpan->start,
                $exponentSpan->end - $exponentSpan->start,
            );
            if ((float) $target === 0.0) {
                $positiveRegions = (new InequalitySolver())->solve(
                    '(' . $exponentSource . ') > 0',
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                try {
                    $zeroRoots = (new EquationSolver())->solve(
                        '(' . $exponentSource . ') = 0',
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (InfiniteSolutionSetException) {
                    return [];
                }
                foreach ($zeroRoots as $zeroRoot) {
                    $point = $zeroRoot->value;
                    if ($point == 0.0) {
                        $point = 0.0;
                    }
                    $updatedRegions = [];
                    foreach ($positiveRegions as $region) {
                        if (!$region->contains($point)) {
                            $updatedRegions[] = $region;
                            continue;
                        }
                        if ($region->lowerBound < $point) {
                            $updatedRegions[] = new InequalityInterval(
                                $region->lowerBound,
                                $point,
                                $region->includesLowerBound,
                                false,
                            );
                        }
                        if ($point < $region->upperBound) {
                            $updatedRegions[] = new InequalityInterval(
                                $point,
                                $region->upperBound,
                                false,
                                $region->includesUpperBound,
                            );
                        }
                    }
                    $positiveRegions = $updatedRegions;
                }

                return $positiveRegions;
            }
            $roots = (new EquationSolver())->solve(
                '(' . $exponentSource . ') = 0',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );

            return \array_map(
                static fn (EquationSolution $root): InequalityInterval => new InequalityInterval(
                    $root->value,
                    $root->value,
                    true,
                    true,
                ),
                $roots,
            );
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function constantExpressionValue(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?float {
        $span = $node->span();
        $expression = \substr($source, $span->start, $span->end - $span->start);
        if ($this->containsVariable($expression, $variable)) {
            return null;
        }
        try {
            $value = Math::evaluate($expression, $parameters, $evaluationOptions);
        } catch (EvaluationException) {
            return null;
        }

        return \is_finite((float) $value) ? (float) $value : null;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSignEqualityWithVariableTarget(
        string $argumentSource,
        string $targetSource,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        $solutions = [];
        foreach ([[1, '>', '1'], [-1, '<', '-1']] as [$sign, $operator, $level]) {
            $validRegion = (new InequalitySolver())->solve(
                '(' . $argumentSource . ') ' . $operator . ' 0',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($validRegion === []) {
                continue;
            }
            try {
                $roots = (new EquationSolver())->solve(
                    '(' . $targetSource . ') = ' . $level,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (\DomainException) {
                return null;
            }
            $rootIntervals = \array_map(
                static fn (EquationSolution $root): InequalityInterval => new InequalityInterval(
                    $root->value,
                    $root->value,
                    true,
                    true,
                ),
                $roots,
            );
            $validRoots = $this->intersect($rootIntervals, $validRegion);
            foreach ($validRoots as $root) {
                $argumentValue = Math::evaluate(
                    $argumentSource,
                    [$variable => $root->lowerBound] + $parameters,
                    $evaluationOptions,
                );
                if (($sign === 1 && $argumentValue <= 0.0) || ($sign === -1 && $argumentValue >= 0.0)) {
                    continue;
                }
                $solutions[] = $root;
            }
        }

        $zeroArgumentRoots = $this->solveSignEqualityLevel(
            $argumentSource,
            '0',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $zeroTargetRoots = $this->solveSignEqualityLevel(
            $targetSource,
            '0',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($zeroArgumentRoots === null || $zeroTargetRoots === null) {
            return null;
        }
        $zeroTargetValues = [];
        foreach ($zeroTargetRoots as $root) {
            $zeroTargetValues[] = $root->lowerBound;
        }
        foreach ($zeroArgumentRoots as $root) {
            $candidate = $root->lowerBound;
            $targetValue = Math::evaluate(
                $targetSource,
                [$variable => $candidate] + $parameters,
                $evaluationOptions,
            );
            $scale = \max(1.0, \abs((float) $targetValue));
            foreach ($zeroTargetValues as $targetRoot) {
                $scale = \max($scale, \abs($targetRoot), \abs($candidate));
            }
            if (\abs((float) $targetValue) <= 8.0 * \PHP_FLOAT_EPSILON * $scale) {
                $solutions[] = new InequalityInterval($candidate, $candidate, true, true);
            }
        }
        \usort(
            $solutions,
            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                $first->lowerBound <=> $second->lowerBound,
        );

        return $this->merge($solutions);
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSignEqualityLevel(
        string $expression,
        string $level,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $roots = (new EquationSolver())->solve(
                '(' . $expression . ') = ' . $level,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\DomainException) {
            return null;
        }

        return \array_map(
            static fn (EquationSolution $root): InequalityInterval => new InequalityInterval(
                $root->value,
                $root->value,
                true,
                true,
            ),
            $roots,
        );
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveExtremumEqualityReduction(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        foreach ([[$left, $right], [$right, $left]] as [$extremumSource, $targetSource]) {
            try {
                $node = (new Parser(
                    (new Lexer($extremumSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
            } catch (MathException) {
                return null;
            }
            while ($node instanceof GroupingNode) {
                $node = $node->expression;
            }
            if (
                !$node instanceof FunctionCallNode
                || !\in_array($node->name, ['min', 'max'], true)
                || \count($node->arguments) < 2
                || !$evaluationOptions->functions->isBuiltIn($node->name)
            ) {
                continue;
            }
            $targetValue = null;
            if (!$this->containsVariable($targetSource, $variable)) {
                try {
                    $targetValue = Math::evaluate($targetSource, $parameters, $evaluationOptions);
                } catch (EvaluationException) {
                    return null;
                }
            }

            $boundOperator = $node->name === 'min' ? '>=' : '<=';
            $validDomainAndBounds = [new InequalityInterval($lowerBound, $upperBound, true, true)];
            $argumentSources = [];
            foreach ($node->arguments as $argument) {
                $span = $argument->span();
                $argumentSource = \substr(
                    $extremumSource,
                    $span->start,
                    $span->end - $span->start,
                );
                $argumentSources[] = $argumentSource;
                if (!$this->containsVariable($argumentSource, $variable) && $targetValue !== null) {
                    try {
                        $argumentValue = Math::evaluate(
                            $argumentSource,
                            $parameters,
                            $evaluationOptions,
                        );
                    } catch (EvaluationException) {
                        return [];
                    }
                    $satisfiesBound = $node->name === 'min'
                        ? $argumentValue >= $targetValue
                        : $argumentValue <= $targetValue;
                    if (!$satisfiesBound) {
                        return [];
                    }

                    continue;
                }
                $argumentConstraint = (new InequalitySolver())->solve(
                    '(' . $argumentSource . ') ' . $boundOperator . ' (' . $targetSource . ')',
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                $validDomainAndBounds = $this->intersect($validDomainAndBounds, $argumentConstraint);
                if ($validDomainAndBounds === []) {
                    return [];
                }
            }

            $solutions = [];
            foreach ($argumentSources as $argumentSource) {
                $absoluteValueIdentity = $this->solveAbsoluteValueIdentitySet(
                    $argumentSource,
                    $targetSource,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                if ($absoluteValueIdentity !== null) {
                    array_push(
                        $solutions,
                        ...$this->intersect($absoluteValueIdentity, $validDomainAndBounds),
                    );
                    continue;
                }
                try {
                    $equalitySolutions = $this->solve(
                        '(' . $argumentSource . ') = (' . $targetSource . ')',
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (InfiniteSolutionSetException) {
                    foreach ($validDomainAndBounds as $interval) {
                        if ($interval->lowerBound < $interval->upperBound) {
                            throw new InfiniteSolutionSetException(
                                'Equation has infinitely many solutions in the requested interval.',
                            );
                        }
                        $solutions[] = $interval;
                    }
                    continue;
                }
                array_push(
                    $solutions,
                    ...$this->intersect($equalitySolutions, $validDomainAndBounds),
                );
            }

            \usort(
                $solutions,
                static fn (InequalityInterval $first, InequalityInterval $second): int =>
                    ($first->lowerBound <=> $second->lowerBound)
                    ?: ($first->upperBound <=> $second->upperBound),
            );

            return $this->merge($solutions);
        }

        return null;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveAbsoluteValueIdentitySet(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        foreach ([[$left, $right], [$right, $left]] as [$absoluteSource, $targetSource]) {
            try {
                $absolute = (new Parser(
                    (new Lexer($absoluteSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
                $target = (new Parser(
                    (new Lexer($targetSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
            } catch (MathException) {
                return null;
            }
            while ($absolute instanceof GroupingNode) {
                $absolute = $absolute->expression;
            }
            if (
                !$absolute instanceof FunctionCallNode
                || $absolute->name !== 'abs'
                || \count($absolute->arguments) !== 1
                || !$evaluationOptions->functions->isBuiltIn('abs')
            ) {
                continue;
            }
            $argument = $absolute->arguments[0];
            while ($argument instanceof GroupingNode) {
                $argument = $argument->expression;
            }
            while ($target instanceof GroupingNode) {
                $target = $target->expression;
            }

            $operator = null;
            if (
                $this->equivalentNodes($argument, $target)
                || $this->areExactRationalEquivalents(
                    $argument,
                    $target,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                )
            ) {
                $operator = '>=';
            } elseif (
                $this->areNegations($argument, $target)
                || $this->areExactRationalNegations(
                    $argument,
                    $target,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                )
            ) {
                $operator = '<=';
            }
            if ($operator === null) {
                continue;
            }
            $span = $absolute->arguments[0]->span();
            $argumentSource = \substr($absoluteSource, $span->start, $span->end - $span->start);

            return (new InequalitySolver())->solve(
                '(' . $argumentSource . ') ' . $operator . ' 0',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        return null;
    }

    private function areNegations(Node $first, Node $second): bool
    {
        while ($first instanceof GroupingNode) {
            $first = $first->expression;
        }
        while ($second instanceof GroupingNode) {
            $second = $second->expression;
        }
        if (
            $second instanceof UnaryOperationNode
            && $second->operator === UnaryOperator::Minus
            && $this->equivalentNodes($first, $second->operand)
        ) {
            return true;
        }

        return $first instanceof BinaryOperationNode
            && $second instanceof BinaryOperationNode
            && $first->operator === BinaryOperator::Subtract
            && $second->operator === BinaryOperator::Subtract
            && $this->equivalentNodes($first->left, $second->right)
            && $this->equivalentNodes($first->right, $second->left);
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function areExactRationalNegations(
        Node $first,
        Node $second,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): bool {
        $negativeSecond = new UnaryOperationNode(
            UnaryOperator::Minus,
            $second,
            $second->span(),
        );
        return $this->areExactRationalEquivalents(
            $first,
            $negativeSecond,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function areExactRationalEquivalents(
        Node $first,
        Node $second,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): bool {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        if ($extractor->difference($first, $second) === ['0']) {
            return true;
        }
        $rationalDifference = $extractor->rationalDifference($first, $second);

        return $rationalDifference !== null
            && $rationalDifference[0] === ['0'];
    }

    /**
     * Reduce distinct quantizers of rational arguments that differ by a
     * constant, including empty phase intersections and pole accumulation.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveQuantizerEqualityReduction(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        $leftQuantizer = $this->extractQuantizerAffineSide(
            $leftNode,
            $left,
            $variable,
            $parameters,
            $evaluationOptions,
        );
        $rightQuantizer = $this->extractQuantizerAffineSide(
            $rightNode,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
        );
        if ($leftQuantizer === null || $rightQuantizer === null) {
            return null;
        }
        [$leftCall, $leftConstant, $leftArgumentSource] = $leftQuantizer;
        [$rightCall, $rightConstant, $rightArgumentSource] = $rightQuantizer;

        if (
            \in_array($leftCall->name, ['floor', 'ceil', 'round'], true)
            && \in_array($rightCall->name, ['floor', 'ceil', 'round'], true)
            && $this->equivalentNodes($leftCall->arguments[0], $rightCall->arguments[0])
        ) {
            $rawOutputOffset = $rightConstant - $leftConstant;
            $outputOffset = \round($rawOutputOffset);
            if (
                \abs($rawOutputOffset - $outputOffset)
                <= 64.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($rawOutputOffset))
            ) {
                $range = (new InequalitySolver())->boundedExpressionRangeForSolver(
                    $leftCall->arguments[0],
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($range !== null && \max(\abs($range[0]), \abs($range[1])) <= 9_007_199_254_740_991.0) {
                    $phaseIntervals = $this->quantizerAgreementIntervals(
                        $leftCall->name,
                        $rightCall->name,
                        0.0,
                        $outputOffset,
                        $range[0],
                        $range[1],
                    );
                    if ($phaseIntervals !== null) {
                        $solutions = [];
                        $inequalitySolver = new InequalitySolver();
                        foreach ($phaseIntervals as $phaseInterval) {
                            $phaseSolutions = [
                                new InequalityInterval($lowerBound, $upperBound, true, true),
                            ];
                            $lowerOperator = $phaseInterval->includesLowerBound ? '>=' : '>';
                            $upperOperator = $phaseInterval->includesUpperBound ? '<=' : '<';
                            $lowerSolutions = $inequalitySolver->solve(
                                '(' . $leftArgumentSource . ') ' . $lowerOperator . ' '
                                    . \sprintf('%.17g', $phaseInterval->lowerBound),
                                $variable,
                                $lowerBound,
                                $upperBound,
                                $parameters,
                                $evaluationOptions,
                                $solverOptions,
                            );
                            $upperSolutions = $inequalitySolver->solve(
                                '(' . $leftArgumentSource . ') ' . $upperOperator . ' '
                                    . \sprintf('%.17g', $phaseInterval->upperBound),
                                $variable,
                                $lowerBound,
                                $upperBound,
                                $parameters,
                                $evaluationOptions,
                                $solverOptions,
                            );
                            $phaseSolutions = $this->intersect($phaseSolutions, $lowerSolutions);
                            $phaseSolutions = $this->intersect($phaseSolutions, $upperSolutions);
                            array_push($solutions, ...$phaseSolutions);
                        }

                        \usort(
                            $solutions,
                            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                                ($first->lowerBound <=> $second->lowerBound)
                                ?: ($first->upperBound <=> $second->upperBound),
                        );

                        return $this->merge($solutions);
                    }
                }
            }
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $leftForm = $extractor->extractRationalForm($leftCall->arguments[0]);
        $rightForm = $extractor->extractRationalForm($rightCall->arguments[0]);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }

        $offset = $this->rationalFormConstantDifference(
            $rightForm,
            $leftForm,
            $solverOptions->maxPolynomialDegree,
        );
        if ($offset === null) {
            return null;
        }
        $rawOutputOffset = $rightConstant - $leftConstant;
        $outputOffset = \round($rawOutputOffset);
        if (
            \abs($rawOutputOffset - $outputOffset)
                > 64.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($rawOutputOffset))
        ) {
            return [];
        }
        if ($leftCall->name === $rightCall->name && $offset === 0.0 && $outputOffset === 0.0) {
            return null;
        }
        if (!$this->quantizersAgreeForUnitPhase($leftCall->name, $rightCall->name, $offset, $outputOffset)) {
            return [];
        }

        $polynomialSolver = new PolynomialSolver();
        foreach ([$leftForm[1], $rightForm[1]] as $denominator) {
            if (\count($denominator) < 2) {
                continue;
            }
            $poles = $polynomialSolver->solve(
                $denominator,
                $lowerBound,
                $upperBound,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomial($denominator, $position),
                $solverOptions,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomialMagnitude($denominator, $position),
            );
            if ($poles === null) {
                return null;
            }

            foreach ($poles as $pole) {
                $leftNumerator = $polynomialSolver->evaluatePolynomial($leftForm[0], $pole->value);
                $leftNumeratorScale = $polynomialSolver->evaluatePolynomialMagnitude($leftForm[0], $pole->value);
                $leftDenominator = $polynomialSolver->evaluatePolynomial($leftForm[1], $pole->value);
                $leftDenominatorScale = $polynomialSolver->evaluatePolynomialMagnitude($leftForm[1], $pole->value);
                $rightNumerator = $polynomialSolver->evaluatePolynomial($rightForm[0], $pole->value);
                $rightNumeratorScale = $polynomialSolver->evaluatePolynomialMagnitude($rightForm[0], $pole->value);
                $rightDenominator = $polynomialSolver->evaluatePolynomial($rightForm[1], $pole->value);
                $rightDenominatorScale = $polynomialSolver->evaluatePolynomialMagnitude($rightForm[1], $pole->value);
                if (
                    $leftNumerator === null
                    || $leftNumeratorScale === null
                    || $leftDenominator === null
                    || $leftDenominatorScale === null
                    || $rightNumerator === null
                    || $rightNumeratorScale === null
                    || $rightDenominator === null
                    || $rightDenominatorScale === null
                ) {
                    continue;
                }
                $hasLeftPole = \abs($leftNumerator)
                        > 128.0 * \PHP_FLOAT_EPSILON * \max(1.0, $leftNumeratorScale)
                    && \abs($leftDenominator)
                        <= 128.0 * \PHP_FLOAT_EPSILON * \max(1.0, $leftDenominatorScale);
                $hasRightPole = \abs($rightNumerator)
                        > 128.0 * \PHP_FLOAT_EPSILON * \max(1.0, $rightNumeratorScale)
                    && \abs($rightDenominator)
                        <= 128.0 * \PHP_FLOAT_EPSILON * \max(1.0, $rightDenominatorScale);
                if (!$hasLeftPole || !$hasRightPole) {
                    continue;
                }

                throw new InfiniteSolutionSetException(
                    'The equation has infinitely many solution components accumulating at a rational pole.',
                );
            }
        }

        return $this->solveEquivalentRationalQuantizerPhases(
            $leftArgumentSource,
            $rightArgumentSource,
            $leftCall,
            $rightCall,
            $leftForm,
            $offset,
            $outputOffset,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{FunctionCallNode, float, string}|null
     */
    private function extractQuantizerAffineSide(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?array {
        $terms = [];
        $this->flattenAdditiveNodes($node, 1.0, $terms);
        $quantizer = null;
        $constant = 0.0;
        foreach ($terms as [$term, $sign]) {
            if (
                $term instanceof FunctionCallNode
                && \in_array($term->name, ['floor', 'ceil', 'round', 'trunc'], true)
                && \count($term->arguments) === 1
                && $sign === 1.0
                && $quantizer === null
            ) {
                $quantizer = $term;
                continue;
            }
            $span = $term->span();
            $termSource = \substr($source, $span->start, $span->end - $span->start);
            if ($this->containsVariable($termSource, $variable)) {
                return null;
            }
            try {
                $constant += $sign * Math::evaluate($termSource, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return null;
            }
            if (!\is_finite($constant)) {
                return null;
            }
        }
        if ($quantizer === null) {
            return null;
        }

        $span = $quantizer->arguments[0]->span();
        $argumentSource = \substr($source, $span->start, $span->end - $span->start);
        if ($argumentSource === '') {
            return null;
        }

        return [$quantizer, $constant, $argumentSource];
    }

    /**
     * Resolve equal quantizers over the finite phase intervals of equivalent
     * rational arguments that differ by a constant.
     *
     * @param array<string, int|float> $parameters
     * @param array{list<float>, list<float>} $argumentForm
     * @return list<InequalityInterval>|null
     */
    private function solveEquivalentRationalQuantizerPhases(
        string $leftArgumentSource,
        string $rightArgumentSource,
        FunctionCallNode $leftCall,
        FunctionCallNode $rightCall,
        array $argumentForm,
        float $offset,
        float $outputOffset,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        $argument = $leftCall->arguments[0];
        $range = (new InequalitySolver())->boundedExpressionRangeForSolver(
            $argument,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
        );
        $range ??= $this->boundedRationalFormRange(
            $argumentForm,
            $lowerBound,
            $upperBound,
            $solverOptions,
        );
        if (
            $range === null
            || \max(\abs($range[0]), \abs($range[1])) > 9_007_199_254_740_991.0
        ) {
            return null;
        }

        $phaseIntervals = $this->quantizerAgreementIntervals(
            $leftCall->name,
            $rightCall->name,
            $offset,
            $outputOffset,
            $range[0],
            $range[1],
        );
        if ($phaseIntervals === null) {
            return null;
        }
        if ($phaseIntervals === []) {
            return [];
        }

        $leftDomain = $this->solve(
            '(' . $leftArgumentSource . ') = (' . $leftArgumentSource . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $rightDomain = $this->solve(
            '(' . $rightArgumentSource . ') = (' . $rightArgumentSource . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $domain = $this->intersect($leftDomain, $rightDomain);
        $solutions = [];
        foreach ($phaseIntervals as $phaseInterval) {
            if ($phaseInterval->lowerBound === $phaseInterval->upperBound) {
                $levelSolutions = $this->solveRationalQuantizerLevel(
                    $leftArgumentSource,
                    $rightArgumentSource,
                    $argumentForm,
                    $phaseInterval->lowerBound,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                if ($levelSolutions === null) {
                    return null;
                }
                array_push($solutions, ...$this->intersect($levelSolutions, $domain));
                continue;
            }

            $inequalitySolver = new InequalitySolver();
            $lowerOperator = $phaseInterval->includesLowerBound ? '>=' : '>';
            $upperOperator = $phaseInterval->includesUpperBound ? '<=' : '<';
            $lowerSolutions = $inequalitySolver->solve(
                '(' . $leftArgumentSource . ') ' . $lowerOperator . ' '
                    . \sprintf('%.17g', $phaseInterval->lowerBound),
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $upperSolutions = $inequalitySolver->solve(
                '(' . $leftArgumentSource . ') ' . $upperOperator . ' '
                    . \sprintf('%.17g', $phaseInterval->upperBound),
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $lowerBoundaryRoots = $this->solveRationalQuantizerLevel(
                $leftArgumentSource,
                $leftArgumentSource,
                $argumentForm,
                $phaseInterval->lowerBound,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $upperBoundaryRoots = $this->solveRationalQuantizerLevel(
                $leftArgumentSource,
                $leftArgumentSource,
                $argumentForm,
                $phaseInterval->upperBound,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($lowerBoundaryRoots === null || $upperBoundaryRoots === null) {
                return null;
            }
            $lowerSolutions = $this->snapIntervalBoundariesToRoots(
                $lowerSolutions,
                $lowerBoundaryRoots,
                $solverOptions,
            );
            $upperSolutions = $this->snapIntervalBoundariesToRoots(
                $upperSolutions,
                $upperBoundaryRoots,
                $solverOptions,
            );
            array_push(
                $solutions,
                ...$this->intersect(
                    $this->intersect($lowerSolutions, $upperSolutions),
                    $domain,
                ),
            );
        }

        \usort(
            $solutions,
            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                ($first->lowerBound <=> $second->lowerBound)
                ?: ($first->upperBound <=> $second->upperBound),
        );

        return $this->merge($solutions);
    }

    /**
     * Replace approximate inequality endpoints with roots of their exact phase levels.
     *
     * @param list<InequalityInterval> $intervals
     * @param list<InequalityInterval> $roots
     * @return list<InequalityInterval>
     */
    private function snapIntervalBoundariesToRoots(
        array $intervals,
        array $roots,
        SolverOptions $solverOptions,
    ): array {
        foreach ($intervals as $index => $interval) {
            $lower = $interval->lowerBound;
            $upper = $interval->upperBound;
            foreach ($roots as $root) {
                if (
                    \abs($lower - $root->lowerBound)
                    <= $solverOptions->positionTolerance($lower, $root->lowerBound)
                ) {
                    $lower = $root->lowerBound;
                }
                if (
                    \abs($upper - $root->upperBound)
                    <= $solverOptions->positionTolerance($upper, $root->upperBound)
                ) {
                    $upper = $root->upperBound;
                }
            }
            if (
                $lower > $upper
                || (
                    $lower === $upper
                    && (!$interval->includesLowerBound || !$interval->includesUpperBound)
                )
            ) {
                unset($intervals[$index]);
                continue;
            }
            $intervals[$index] = new InequalityInterval(
                $lower,
                $upper,
                $interval->includesLowerBound,
                $interval->includesUpperBound,
            );
        }

        return \array_values($intervals);
    }

    /** @return list<InequalityInterval>|null */
    private function quantizerAgreementIntervals(
        string $left,
        string $right,
        float $offset,
        float $outputOffset,
        float $minimum,
        float $maximum,
    ): ?array {
        $breakpoints = [$minimum, $maximum];
        foreach ([[$left, 0.0], [$right, $offset]] as [$quantizer, $shift]) {
            $firstLevel = (int) \floor($minimum + $shift) - 1;
            $lastLevel = (int) \ceil($maximum + $shift) + 1;
            if ($lastLevel - $firstLevel > 65_536) {
                return null;
            }
            for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
                $threshold = $level + ($quantizer === 'round' ? 0.5 : 0.0) - $shift;
                if ($threshold > $minimum && $threshold < $maximum) {
                    $breakpoints[] = $threshold;
                    if (\count($breakpoints) > 65_536) {
                        return null;
                    }
                }
            }
        }
        \sort($breakpoints, \SORT_NUMERIC);
        $breakpoints = \array_values(\array_unique($breakpoints, \SORT_REGULAR));
        $intervals = [];
        foreach ($breakpoints as $index => $point) {
            if ($this->quantizersAgreeAt($left, $right, $point, $offset, $outputOffset)) {
                $intervals[] = new InequalityInterval($point, $point, true, true);
            }
            if (!isset($breakpoints[$index + 1])) {
                continue;
            }
            $nextPoint = $breakpoints[$index + 1];
            $midpoint = $point + (($nextPoint - $point) / 2.0);
            if ($this->quantizersAgreeAt($left, $right, $midpoint, $offset, $outputOffset)) {
                $intervals[] = new InequalityInterval($point, $nextPoint, false, false);
            }
        }

        \usort(
            $intervals,
            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                ($first->lowerBound <=> $second->lowerBound)
                ?: ($first->upperBound <=> $second->upperBound),
        );

        return $this->merge($intervals);
    }

    private function quantizersAgreeAt(
        string $left,
        string $right,
        float $value,
        float $offset,
        float $outputOffset,
    ): bool {
        $leftValue = match ($left) {
            'floor' => \floor($value),
            'ceil' => \ceil($value),
            'round' => \round($value),
            default => throw new \LogicException('Unsupported quantizer.'),
        };
        $rightArgument = $value + $offset;
        $rightValue = match ($right) {
            'floor' => \floor($rightArgument),
            'ceil' => \ceil($rightArgument),
            'round' => \round($rightArgument),
            default => throw new \LogicException('Unsupported quantizer.'),
        };

        return $leftValue === ($rightValue + $outputOffset);
    }

    /**
     * @param array{list<float>, list<float>} $form
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveRationalQuantizerLevel(
        string $leftArgumentSource,
        string $rightArgumentSource,
        array $form,
        float $level,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        $coefficients = [];
        $coefficientCount = \max(\count($form[0]), \count($form[1]));
        for ($degree = 0; $degree < $coefficientCount; ++$degree) {
            $coefficients[] = ($form[0][$degree] ?? 0.0) - ($level * ($form[1][$degree] ?? 0.0));
        }
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === 0.0) {
            \array_pop($coefficients);
        }

        if (\count($coefficients) === 1 && $coefficients[0] === 0.0) {
            $leftDomain = $this->solve(
                '(' . $leftArgumentSource . ') = (' . $leftArgumentSource . ')',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $rightDomain = $this->solve(
                '(' . $rightArgumentSource . ') = (' . $rightArgumentSource . ')',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );

            return $this->intersect($leftDomain, $rightDomain);
        }

        $polynomialSolver = new PolynomialSolver();
        $roots = $polynomialSolver->solve(
            $coefficients,
            $lowerBound,
            $upperBound,
            static fn (float $position): ?float =>
                $polynomialSolver->evaluatePolynomial($coefficients, $position),
            $solverOptions,
            static fn (float $position): ?float =>
                $polynomialSolver->evaluatePolynomialMagnitude($coefficients, $position),
        );
        if ($roots === null) {
            return null;
        }

        $denominatorRoots = [];
        if (\count($form[1]) > 1) {
            $denominatorRoots = $polynomialSolver->solve(
                $form[1],
                $lowerBound,
                $upperBound,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomial($form[1], $position),
                $solverOptions,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomialMagnitude($form[1], $position),
            );
            if ($denominatorRoots === null) {
                return null;
            }
        }

        $solutions = [];
        foreach ($roots as $root) {
            $rootValue = $root->value;
            $nearHole = false;
            foreach ($denominatorRoots as $denominatorRoot) {
                if (
                    \abs($rootValue - $denominatorRoot->value)
                    <= $solverOptions->positionTolerance($rootValue, $denominatorRoot->value)
                ) {
                    $nearHole = true;
                    break;
                }
            }
            if ($nearHole) {
                continue;
            }
            $nearestInteger = \round($rootValue);
            if (
                \abs($rootValue - $nearestInteger)
                    <= $solverOptions->positionTolerance($rootValue, $nearestInteger)
            ) {
                $variables = [...$parameters, $variable => $nearestInteger];
                $levelsMatch = false;
                try {
                    $leftLevel = Math::evaluate($leftArgumentSource, $variables, $evaluationOptions);
                    $rightLevel = Math::evaluate($rightArgumentSource, $variables, $evaluationOptions);
                    $levelsMatch = $this->roundoffEqual($leftLevel, $level)
                        && $this->roundoffEqual($rightLevel, $level);
                } catch (\Throwable) {
                }
                if ($levelsMatch) {
                    $rootValue = $nearestInteger;
                }
            }
            $variables = $parameters;
            $variables[$variable] = $rootValue;
            try {
                Math::evaluate($leftArgumentSource, $variables, $evaluationOptions);
                Math::evaluate($rightArgumentSource, $variables, $evaluationOptions);
            } catch (\Throwable) {
                continue;
            }

            $solutions[] = new InequalityInterval($rootValue, $rootValue, true, true);
        }

        return $solutions;
    }

    /**
     * Estimate the range of a bounded rational function after removable holes
     * have been distinguished from genuine poles.
     *
     * @param array{list<float>, list<float>} $form
     * @return array{float, float}|null
     */
    private function boundedRationalFormRange(
        array $form,
        float $lowerBound,
        float $upperBound,
        SolverOptions $solverOptions,
    ): ?array {
        [$numerator, $denominator] = $form;
        $numeratorDerivative = [];
        foreach ($numerator as $degree => $coefficient) {
            if ($degree > 0) {
                $numeratorDerivative[] = $degree * $coefficient;
            }
        }
        $denominatorDerivative = [];
        foreach ($denominator as $degree => $coefficient) {
            if ($degree > 0) {
                $denominatorDerivative[] = $degree * $coefficient;
            }
        }
        $firstProduct = $this->multiplyPolynomialCoefficients(
            $numeratorDerivative === [] ? [0.0] : $numeratorDerivative,
            $denominator,
            $solverOptions->maxPolynomialDegree,
        );
        $secondProduct = $this->multiplyPolynomialCoefficients(
            $numerator,
            $denominatorDerivative === [] ? [0.0] : $denominatorDerivative,
            $solverOptions->maxPolynomialDegree,
        );
        if ($firstProduct === null || $secondProduct === null) {
            return null;
        }
        $derivativeNumerator = [];
        $derivativeLength = \max(\count($firstProduct), \count($secondProduct));
        for ($degree = 0; $degree < $derivativeLength; ++$degree) {
            $derivativeNumerator[] = ($firstProduct[$degree] ?? 0.0) - ($secondProduct[$degree] ?? 0.0);
        }
        while (
            \count($derivativeNumerator) > 1
            && $derivativeNumerator[\array_key_last($derivativeNumerator)] === 0.0
        ) {
            \array_pop($derivativeNumerator);
        }

        $polynomialSolver = new PolynomialSolver();
        $criticalPoints = \count($derivativeNumerator) === 1
            && $derivativeNumerator[0] === 0.0
            ? []
            : $polynomialSolver->solve(
                $derivativeNumerator,
                $lowerBound,
                $upperBound,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomial($derivativeNumerator, $position),
                $solverOptions,
                static fn (float $position): ?float =>
                    $polynomialSolver->evaluatePolynomialMagnitude($derivativeNumerator, $position),
            );
        if ($criticalPoints === null) {
            return null;
        }

        $positions = [$lowerBound, $upperBound];
        foreach ($criticalPoints as $criticalPoint) {
            $positions[] = $criticalPoint->value;
        }
        $poles = $polynomialSolver->solve(
            $denominator,
            $lowerBound,
            $upperBound,
            static fn (float $position): ?float =>
                $polynomialSolver->evaluatePolynomial($denominator, $position),
            $solverOptions,
            static fn (float $position): ?float =>
                $polynomialSolver->evaluatePolynomialMagnitude($denominator, $position),
        );
        if ($poles === null) {
            return null;
        }
        $probeDistance = ($upperBound - $lowerBound) * 1.0E-8;
        foreach ($poles as $pole) {
            foreach ([-1.0, 1.0] as $direction) {
                $probe = $pole->value + ($direction * $probeDistance);
                if ($probe > $lowerBound && $probe < $upperBound) {
                    $positions[] = $probe;
                }
            }
        }

        $minimum = \INF;
        $maximum = -\INF;
        foreach ($positions as $position) {
            $numeratorValue = $polynomialSolver->evaluatePolynomial($numerator, $position);
            $denominatorValue = $polynomialSolver->evaluatePolynomial($denominator, $position);
            if ($numeratorValue === null || $denominatorValue === null || $denominatorValue === 0.0) {
                continue;
            }
            $value = $numeratorValue / $denominatorValue;
            if (!\is_finite($value)) {
                return null;
            }
            $minimum = \min($minimum, $value);
            $maximum = \max($maximum, $value);
        }

        return \is_finite($minimum) && \is_finite($maximum) ? [$minimum, $maximum] : null;
    }

    /**
     * @param array{list<float>, list<float>} $first
     * @param array{list<float>, list<float>} $second
     */
    private function rationalFormConstantDifference(array $first, array $second, int $maxDegree): ?float
    {
        $firstProduct = $this->multiplyPolynomialCoefficients($first[0], $second[1], $maxDegree);
        $secondProduct = $this->multiplyPolynomialCoefficients($second[0], $first[1], $maxDegree);
        $denominator = $this->multiplyPolynomialCoefficients($first[1], $second[1], $maxDegree);
        if ($firstProduct === null || $secondProduct === null || $denominator === null) {
            return null;
        }

        $length = \max(\count($firstProduct), \count($secondProduct));
        $difference = [];
        for ($degree = 0; $degree < $length; ++$degree) {
            $difference[$degree] = ($firstProduct[$degree] ?? 0.0) - ($secondProduct[$degree] ?? 0.0);
        }
        $degree = \max(\count($difference), \count($denominator));
        $scaleIndex = null;
        $denominatorScale = 0.0;
        for ($index = 0; $index < \count($denominator); ++$index) {
            if (\abs($denominator[$index]) > $denominatorScale) {
                $denominatorScale = \abs($denominator[$index]);
                $scaleIndex = $index;
            }
        }
        if ($scaleIndex === null || $denominatorScale === 0.0) {
            return null;
        }

        $offset = ($difference[$scaleIndex] ?? 0.0) / $denominator[$scaleIndex];
        for ($index = 0; $index < $degree; ++$index) {
            $residual = ($difference[$index] ?? 0.0) - ($offset * ($denominator[$index] ?? 0.0));
            $scale = \max(
                1.0,
                \abs($difference[$index] ?? 0.0),
                \abs($offset * ($denominator[$index] ?? 0.0)),
            );
            if (\abs($residual) > 128.0 * \PHP_FLOAT_EPSILON * $scale) {
                return null;
            }
        }

        return \is_finite($offset) ? $offset : null;
    }

    private function quantizersAgreeForUnitPhase(
        string $left,
        string $right,
        float $offset,
        float $outputOffset,
    ): bool {
        $nearestIntegerOffset = \round($offset);
        $integerOffset = \abs($offset - $nearestIntegerOffset)
                <= 64.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($offset))
            ? $nearestIntegerOffset
            : \floor($offset);
        $fractionalOffset = $offset - $integerOffset;
        $normalizedOutputOffset = $outputOffset + $integerOffset;
        if (\abs($normalizedOutputOffset) > 3.0) {
            return false;
        }

        $breakpoints = [0.0, 1.0];
        foreach ([[$left, 0.0], [$right, $fractionalOffset]] as [$quantizer, $shift]) {
            $fractionalLower = $shift;
            $fractionalUpper = 1.0 + $shift;
            $firstThreshold = (int) \floor($fractionalLower) - 1;
            $lastThreshold = (int) \ceil($fractionalUpper) + 1;
            for ($integer = $firstThreshold; $integer <= $lastThreshold; ++$integer) {
                $threshold = $integer - $shift;
                if ($quantizer === 'round') {
                    $threshold = $integer + 0.5 - $shift;
                }
                if ($threshold > 0.0 && $threshold < 1.0) {
                    $breakpoints[] = $threshold;
                }
            }
        }
        \sort($breakpoints, \SORT_NUMERIC);
        $breakpoints = \array_values(\array_unique($breakpoints, \SORT_REGULAR));
        $phases = $breakpoints;
        for ($index = 1; $index < \count($breakpoints); ++$index) {
            $phases[] = ($breakpoints[$index - 1] + $breakpoints[$index]) / 2.0;
        }
        foreach ([1_000_000.0, -1_000_000.0] as $integerPart) {
            foreach ($phases as $phase) {
                $leftArgument = $integerPart + $phase;
                $leftValue = match ($left) {
                    'floor' => \floor($leftArgument),
                    'ceil' => \ceil($leftArgument),
                    'round' => \round($leftArgument),
                    default => throw new \LogicException('Unsupported quantizer.'),
                };
                $rightArgument = $leftArgument + $fractionalOffset;
                $rightValue = match ($right) {
                    'floor' => \floor($rightArgument),
                    'ceil' => \ceil($rightArgument),
                    'round' => \round($rightArgument),
                    default => throw new \LogicException('Unsupported quantizer.'),
                };
                if ($leftValue === ($rightValue + $normalizedOutputOffset)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function solveVariableIndependentEquation(
        string $left,
        string $right,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): array {
        try {
            $leftValue = Math::evaluate($left, $parameters, $evaluationOptions);
            $rightValue = Math::evaluate($right, $parameters, $evaluationOptions);
        } catch (EvaluationException $error) {
            if (
                \in_array(
                    $error->errorCode(),
                    [
                        'eval.domain',
                        'eval.division_by_zero',
                        'eval.modulo_by_zero',
                        'eval.non_finite',
                        'eval.integer_overflow',
                        'limit.factorial',
                    ],
                    true,
                )
            ) {
                return [];
            }

            throw $error;
        }

        if (!$this->roundoffEqual($leftValue, $rightValue)) {
            return [];
        }

        return [new InequalityInterval($lowerBound, $upperBound, true, true)];
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveVariableIntegerArgumentSet(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        $calls = [];
        $this->collectVariableIntegerArgumentSetCalls($leftNode, 0, $left, $variable, $calls);
        $this->collectVariableIntegerArgumentSetCalls($rightNode, 1, $right, $variable, $calls);
        if ($calls === []) {
            return null;
        }

        $intervals = [];
        foreach ($calls as [$call, $argumentIndex, $minimumLevel, $maximumLevel, $sideIndex, $sideSource]) {
            if ($call instanceof FactorialNode) {
                $maximumLevel = \min($maximumLevel, $evaluationOptions->limits->maxFactorial);
            }
            $argumentNode = $call instanceof FactorialNode
                ? $call->operand
                : $call->arguments[$argumentIndex];
            $argumentSpan = $argumentNode->span();
            $argumentSource = \substr(
                $sideSource,
                $argumentSpan->start,
                $argumentSpan->end - $argumentSpan->start,
            );
            $range = (new InequalitySolver())->boundedExpressionRangeForSolver(
                $argumentNode,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
            if ($range !== null && \is_finite($range[0]) && \is_finite($range[1])) {
                $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                    * \max(1.0, \abs($range[0]), \abs($range[1]));
                $minimumLevel = \max($minimumLevel, (int) \ceil($range[0] - $tolerance));
                $maximumLevel = \min($maximumLevel, (int) \floor($range[1] + $tolerance));
            }
            if ($maximumLevel < $minimumLevel) {
                continue;
            }
            if ($maximumLevel - $minimumLevel + 1 > self::MAX_VARIABLE_INTEGER_LEVELS) {
                return null;
            }
            for ($level = $minimumLevel; $level <= $maximumLevel; ++$level) {
                if (
                    $argumentNode instanceof FunctionCallNode
                    && \in_array($argumentNode->name, ['floor', 'ceil', 'round', 'trunc'], true)
                    && \count($argumentNode->arguments) === 1
                ) {
                    $innerSpan = $argumentNode->arguments[0]->span();
                    $innerArgument = \substr(
                        $sideSource,
                        $innerSpan->start,
                        $innerSpan->end - $innerSpan->start,
                    );
                    $levelIntervals = $this->solvePiecewiseIntegerFunctionLevelSet(
                        $argumentNode->name,
                        $innerArgument,
                        $level,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } else {
                    $levelIntervals = $this->solve(
                        '(' . $argumentSource . ') = ' . $level,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                }
                if ($levelIntervals === []) {
                    continue;
                }

                $levelSide = \substr($sideSource, 0, $argumentSpan->start)
                    . $level
                    . \substr($sideSource, $argumentSpan->end);
                $levelEquation = $sideIndex === 0
                    ? $levelSide . ' = ' . $right
                    : $left . ' = ' . $levelSide;
                [$levelLeft, $levelRight] = $this->splitEquation($levelEquation);

                foreach ($levelIntervals as $levelInterval) {
                    if ($levelInterval->lowerBound === $levelInterval->upperBound) {
                        $point = $levelInterval->lowerBound;
                        $variables = [...$parameters, $variable => $point];
                        try {
                            $leftValue = Math::evaluate($levelLeft, $variables, $evaluationOptions);
                            $rightValue = Math::evaluate($levelRight, $variables, $evaluationOptions);
                        } catch (EvaluationException) {
                            continue;
                        }
                        $scale = \max(1.0, \abs((float) $leftValue), \abs((float) $rightValue));
                        $tolerance = $solverOptions->absoluteTolerance
                            + ($solverOptions->relativeTolerance * $scale)
                            + ($solverOptions->residualTolerance * $scale);
                        if (\abs((float) $leftValue - (float) $rightValue) <= $tolerance) {
                            $intervals[] = $levelInterval;
                        }

                        continue;
                    }
                    $solutionsAtLevel = $this->solve(
                        $levelEquation,
                        $variable,
                        $levelInterval->lowerBound,
                        $levelInterval->upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                    array_push(
                        $intervals,
                        ...$this->intersect($solutionsAtLevel, [$levelInterval]),
                    );
                }
            }
        }

        \usort(
            $intervals,
            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                ($first->lowerBound <=> $second->lowerBound)
                ?: ($first->upperBound <=> $second->upperBound),
        );

        return $this->merge($intervals);
    }

    /**
     * Convert isolated roots of smooth modulo equalities into equation-set points.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSmoothModuloEquality(
        string $equation,
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        foreach (
            [
                [$leftNode, $rightNode, $left, $right],
                [$rightNode, $leftNode, $right, $left],
            ] as [$moduloSide, $targetSide, $moduloSource, $targetSource]
        ) {
            while ($moduloSide instanceof GroupingNode) {
                $moduloSide = $moduloSide->expression;
            }
            if (
                !$moduloSide instanceof BinaryOperationNode
                || $moduloSide->operator !== BinaryOperator::Modulo
                || $this->containsVariable($targetSource, $variable)
            ) {
                continue;
            }
            $numerator = $moduloSide->left;
            $numeratorSpan = $numerator->span();
            $numeratorSource = \substr(
                $moduloSource,
                $numeratorSpan->start,
                $numeratorSpan->end - $numeratorSpan->start,
            );
            $divisorSpan = $moduloSide->right->span();
            $divisorSource = \substr(
                $moduloSource,
                $divisorSpan->start,
                $divisorSpan->end - $divisorSpan->start,
            );
            if (
                $numeratorSource === ''
                || $this->containsVariable($divisorSource, $variable)
                || !$this->containsVariable($numeratorSource, $variable)
                || !$this->isSmoothModuloOperand($numerator, $evaluationOptions)
            ) {
                continue;
            }

            try {
                $roots = (new EquationSolver())->solve(
                    $equation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (InfiniteSolutionSetException) {
                if (!$this->isEverywhereDefinedModuloOperand($numerator)) {
                    return null;
                }
                $range = (new InequalitySolver())->boundedExpressionRangeForSolver(
                    $numerator,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($range === null || $range[0] !== $range[1]) {
                    return null;
                }
                $probePositions = [
                    $lowerBound,
                    $lowerBound + (($upperBound - $lowerBound) / 2.0),
                    $upperBound,
                ];
                foreach ($probePositions as $position) {
                    $variables = [...$parameters, $variable => $position];
                    try {
                        $leftValue = Math::evaluate($left, $variables, $evaluationOptions);
                        $rightValue = Math::evaluate($right, $variables, $evaluationOptions);
                    } catch (EvaluationException) {
                        return null;
                    }
                    if (!$this->roundoffEqual($leftValue, $rightValue)) {
                        return null;
                    }
                }

                return [new InequalityInterval($lowerBound, $upperBound, true, true)];
            }

            return \array_map(
                static fn (EquationSolution $root): InequalityInterval => new InequalityInterval(
                    $root->value,
                    $root->value,
                    true,
                    true,
                ),
                $roots,
            );
        }

        return null;
    }

    private function isSmoothModuloOperand(Node $node, EvaluationOptions $evaluationOptions): bool
    {
        if (
            $node instanceof NumberNode
            || $node instanceof ConstantNode
            || $node instanceof VariableNode
        ) {
            return true;
        }
        if ($node instanceof GroupingNode) {
            return $this->isSmoothModuloOperand($node->expression, $evaluationOptions);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isSmoothModuloOperand($node->operand, $evaluationOptions);
        }
        if ($node instanceof BinaryOperationNode) {
            return $node->operator !== BinaryOperator::Modulo
                && $this->isSmoothModuloOperand($node->left, $evaluationOptions)
                && $this->isSmoothModuloOperand($node->right, $evaluationOptions);
        }
        if ($node instanceof FunctionCallNode) {
            if (
                !$evaluationOptions->functions->isBuiltIn($node->name)
                || !\in_array(
                    $node->name,
                    [
                        'abs', 'sqrt', 'cbrt', 'exp', 'expm1', 'ln', 'log', 'log10', 'log2', 'log1p',
                        'sin', 'cos', 'tan', 'sec', 'csc', 'cot', 'sinh', 'cosh', 'tanh', 'sech',
                        'csch', 'coth', 'asinh', 'acosh', 'atanh', 'asin', 'acos', 'atan', 'asec',
                        'acsc', 'acot', 'erf', 'erfc', 'sinc', 'sincpi',
                    ],
                    true,
                )
            ) {
                return false;
            }
            foreach ($node->arguments as $argument) {
                if (!$this->isSmoothModuloOperand($argument, $evaluationOptions)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function isEverywhereDefinedModuloOperand(Node $node): bool
    {
        if (
            $node instanceof NumberNode
            || $node instanceof ConstantNode
            || $node instanceof VariableNode
        ) {
            return true;
        }
        if ($node instanceof GroupingNode) {
            return $this->isEverywhereDefinedModuloOperand($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isEverywhereDefinedModuloOperand($node->operand);
        }
        if ($node instanceof BinaryOperationNode) {
            return \in_array(
                $node->operator,
                [BinaryOperator::Add, BinaryOperator::Subtract, BinaryOperator::Multiply],
                true,
            )
                && $this->isEverywhereDefinedModuloOperand($node->left)
                && $this->isEverywhereDefinedModuloOperand($node->right);
        }
        if (
            $node instanceof FunctionCallNode
            && \in_array($node->name, ['sin', 'cos', 'tanh', 'erf', 'sinc', 'sincpi'], true)
        ) {
            foreach ($node->arguments as $argument) {
                if (!$this->isEverywhereDefinedModuloOperand($argument)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Solve modulo equalities with polynomial floor, ceil, or round selectors.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSinglePiecewiseModuloSet(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
        int &$remainingWork,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        $calls = [];
        $this->collectSinglePiecewiseModuloCall($leftNode, 0, $left, $variable, false, $calls);
        $this->collectSinglePiecewiseModuloCall($rightNode, 1, $right, $variable, false, $calls);
        if ($calls === []) {
            return null;
        }
        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $polynomialSolver = new PolynomialSolver();
        $selectors = [];
        $maximumCombinations = 65_536;
        $combinationCount = 1;
        foreach ($calls as [$call, $sideIndex, $sideSource]) {
            $argument = $call->arguments[0];
            try {
                $coefficients = $extractor->extract($argument);
            } catch (EvaluationException) {
                return null;
            }
            if ($coefficients === null) {
                $range = (new InequalitySolver())->boundedExpressionRangeForSolver(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($range === null) {
                    return null;
                }
                [$minimum, $maximum] = $range;
            } else {
                $derivative = [];
                foreach ($coefficients as $power => $coefficient) {
                    if ($power > 0) {
                        $derivative[] = $power * $coefficient;
                    }
                }
                $criticalPoints = \count($derivative) > 1
                    ? $polynomialSolver->solve(
                        $derivative,
                        $lowerBound,
                        $upperBound,
                        fn (float $x): ?float => $polynomialSolver->evaluatePolynomial($derivative, $x),
                        $solverOptions,
                        fn (float $x): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                            $derivative,
                            $x,
                        ),
                    )
                    : [];
                if ($criticalPoints === null) {
                    return null;
                }
                $positions = [
                    $lowerBound,
                    $upperBound,
                    ...\array_map(
                        static fn (EquationSolution $solution): float => $solution->value,
                        $criticalPoints,
                    ),
                ];
                $values = [];
                foreach ($positions as $x) {
                    $value = $polynomialSolver->evaluatePolynomial($coefficients, $x);
                    if ($value === null) {
                        return null;
                    }
                    $values[] = $value;
                }
                $minimum = \min($values);
                $maximum = \max($values);
            }
            if (
                \abs($minimum) > 9_007_199_254_740_991.0
                || \abs($maximum) > 9_007_199_254_740_991.0
            ) {
                return null;
            }
            $firstLevel = (int) \floor($minimum) - 1;
            $lastLevel = (int) \ceil($maximum) + 1;
            if ($lastLevel - $firstLevel + 1 > $maximumCombinations) {
                return null;
            }

            $callSpan = $call->span();
            $argumentSpan = $call->arguments[0]->span();
            $argumentSource = \substr(
                $sideSource,
                $argumentSpan->start,
                $argumentSpan->end - $argumentSpan->start,
            );
            $levels = [];
            for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
                if (--$remainingWork < 0) {
                    return null;
                }
                $levelSet = $this->solvePiecewiseIntegerFunctionLevelSet(
                    $call->name,
                    $argumentSource,
                    $level,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
                if ($levelSet !== []) {
                    $levels[] = [$level, $levelSet];
                }
            }
            if ($levels === []) {
                return [];
            }
            $combinationCount *= \count($levels);
            if ($combinationCount > $maximumCombinations) {
                return null;
            }
            $selectors[] = [$callSpan->start, $callSpan->end, $sideIndex, $levels];
        }

        /** @var list<array{InequalityInterval, list<array{int, int, int, int}>}> $cells */
        $cells = [[
            new InequalityInterval($lowerBound, $upperBound, true, true),
            [],
        ]];
        foreach ($selectors as [$callStart, $callEnd, $sideIndex, $levels]) {
            $nextCells = [];
            foreach ($cells as [$cell, $replacements]) {
                foreach ($levels as [$level, $levelIntervals]) {
                    foreach ($this->intersect([$cell], $levelIntervals) as $intersection) {
                        $nextCells[] = [
                            $intersection,
                            [...$replacements, [$sideIndex, $callStart, $callEnd, $level]],
                        ];
                        if (\count($nextCells) > $maximumCombinations) {
                            return null;
                        }
                    }
                }
            }
            $cells = $nextCells;
            if ($cells === []) {
                return [];
            }
        }

        $intervals = [];
        foreach ($cells as [$cell, $replacements]) {
            if (--$remainingWork < 0) {
                return null;
            }
            $substitutedLeft = $left;
            $substitutedRight = $right;
            \usort(
                $replacements,
                static fn (array $a, array $b): int => $b[1] <=> $a[1],
            );
            foreach ($replacements as [$sideIndex, $start, $end, $level]) {
                if ($sideIndex === 0) {
                    $substitutedLeft = \substr($substitutedLeft, 0, $start)
                        . (string) $level
                        . \substr($substitutedLeft, $end);
                } else {
                    $substitutedRight = \substr($substitutedRight, 0, $start)
                        . (string) $level
                        . \substr($substitutedRight, $end);
                }
            }
            if ($cell->lowerBound === $cell->upperBound) {
                if (
                    $this->matchesAt(
                        $substitutedLeft,
                        $substitutedRight,
                        $variable,
                        $cell->lowerBound,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    $intervals[] = $cell;
                }
                continue;
            }
            $nestedSolutions = $this->solveSinglePiecewiseModuloSet(
                $substitutedLeft,
                $substitutedRight,
                $variable,
                $cell->lowerBound,
                $cell->upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                $remainingWork,
            );
            if ($nestedSolutions !== null) {
                array_push($intervals, ...$this->intersect($nestedSolutions, [$cell]));
                continue;
            }
            $solutions = $this->solve(
                $substitutedLeft . ' = ' . $substitutedRight,
                $variable,
                $cell->lowerBound,
                $cell->upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            array_push($intervals, ...$this->intersect($solutions, [$cell]));
        }
        \usort(
            $intervals,
            static fn (InequalityInterval $a, InequalityInterval $b): int =>
                ($a->lowerBound <=> $b->lowerBound) ?: ($a->upperBound <=> $b->upperBound),
        );

        return $this->merge($intervals);
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function solvePiecewiseIntegerFunctionLevelSet(
        string $function,
        string $argument,
        int $level,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        $integerFunctionLevelSet = $this->solveGcdLcmPiecewiseLevelSet(
            $argument,
            $level,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($integerFunctionLevelSet !== null) {
            return $integerFunctionLevelSet;
        }

        $levelText = (string) $level;
        $inequalities = match ($function) {
            'floor' => [
                '(' . $argument . ') >= ' . $levelText,
                '(' . $argument . ') < ' . (string) ($level + 1),
            ],
            'ceil' => [
                '(' . $argument . ') > ' . (string) ($level - 1),
                '(' . $argument . ') <= ' . $levelText,
            ],
            'trunc' => $level > 0
                ? [
                    '(' . $argument . ') >= ' . $levelText,
                    '(' . $argument . ') < ' . (string) ($level + 1),
                ]
                : ($level < 0
                    ? [
                        '(' . $argument . ') > ' . (string) ($level - 1),
                        '(' . $argument . ') <= ' . $levelText,
                    ]
                    : [
                        '(' . $argument . ') > -1',
                        '(' . $argument . ') < 1',
                    ]),
            'round' => $level > 0
                ? [
                    '(' . $argument . ') >= ' . \sprintf('%.17g', $level - 0.5),
                    '(' . $argument . ') < ' . \sprintf('%.17g', $level + 0.5),
                ]
                : ($level < 0
                    ? [
                        '(' . $argument . ') > ' . \sprintf('%.17g', $level - 0.5),
                        '(' . $argument . ') <= ' . \sprintf('%.17g', $level + 0.5),
                    ]
                    : [
                        '(' . $argument . ') > -0.5',
                        '(' . $argument . ') < 0.5',
                    ]),
            default => [],
        };
        if ($inequalities === []) {
            return [];
        }

        $solver = new InequalitySolver();
        $intervals = $solver->solve(
            $inequalities[0],
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $intervals = $this->intersect(
            $intervals,
            $solver->solve(
                $inequalities[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            ),
        );

        return $intervals;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveGcdLcmPiecewiseLevelSet(
        string $argument,
        int $level,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $node = (new Parser(
                (new Lexer($argument, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            !$node instanceof FunctionCallNode
            || !\in_array($node->name, ['gcd', 'lcm'], true)
            || \count($node->arguments) !== 2
        ) {
            return null;
        }

        $allArgumentsConstant = true;
        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        foreach ($node->arguments as $nodeArgument) {
            if (!$this->isEverywhereDefinedPolynomial($nodeArgument)) {
                $allArgumentsConstant = false;
                break;
            }
            try {
                $coefficients = $extractor->extract($nodeArgument);
            } catch (EvaluationException) {
                $coefficients = null;
            }
            if ($coefficients === null || \count($coefficients) !== 1) {
                $allArgumentsConstant = false;
                break;
            }
        }

        if ($allArgumentsConstant || !$this->containsVariable($argument, $variable)) {
            try {
                $value = Math::evaluate(
                    $argument,
                    [...$parameters, $variable => $lowerBound],
                    $evaluationOptions,
                );
            } catch (EvaluationException $error) {
                if (
                    \in_array(
                        $error->errorCode(),
                        [
                            'eval.domain',
                            'eval.division_by_zero',
                            'eval.integer_overflow',
                            'eval.modulo_by_zero',
                            'eval.non_finite',
                            'limit.factorial',
                        ],
                        true,
                    )
                ) {
                    return [];
                }
                throw $error;
            }

            return (float) $value === (float) $level
                ? [new InequalityInterval($lowerBound, $upperBound, true, true)]
                : [];
        }

        $roots = (new EquationSolver())->solve(
            '(' . $argument . ') = ' . (string) $level,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $intervals = [];
        foreach ($roots as $root) {
            $intervals[] = new InequalityInterval($root->value, $root->value, true, true);
        }

        return $this->merge($intervals);
    }

    private function isEverywhereDefinedPolynomial(Node $node): bool
    {
        if (
            $node instanceof NumberNode
            || $node instanceof ConstantNode
            || $node instanceof VariableNode
        ) {
            return true;
        }
        if ($node instanceof GroupingNode) {
            return $this->isEverywhereDefinedPolynomial($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            return \in_array($node->operator, [UnaryOperator::Plus, UnaryOperator::Minus], true)
                && $this->isEverywhereDefinedPolynomial($node->operand);
        }
        if ($node instanceof BinaryOperationNode) {
            return \in_array(
                $node->operator,
                [BinaryOperator::Add, BinaryOperator::Subtract, BinaryOperator::Multiply],
                true,
            )
                && $this->isEverywhereDefinedPolynomial($node->left)
                && $this->isEverywhereDefinedPolynomial($node->right);
        }

        return false;
    }

    /** @param list<array{FunctionCallNode, int, string}> $calls */
    private function collectSinglePiecewiseModuloCall(
        Node $node,
        int $sideIndex,
        string $source,
        string $variable,
        bool $insideModulo,
        array &$calls,
    ): void {
        if ($node instanceof BinaryOperationNode) {
            $inside = $insideModulo || $node->operator === BinaryOperator::Modulo;
            $this->collectSinglePiecewiseModuloCall($node->left, $sideIndex, $source, $variable, $inside, $calls);
            $this->collectSinglePiecewiseModuloCall($node->right, $sideIndex, $source, $variable, $inside, $calls);
        } elseif ($node instanceof FunctionCallNode) {
            $isSelector =
                $insideModulo
                && \in_array($node->name, ['floor', 'ceil', 'round', 'trunc'], true)
                && \count($node->arguments) === 1
                && $this->containsVariable($source, $variable);
            $callCount = \count($calls);
            foreach ($node->arguments as $argument) {
                $this->collectSinglePiecewiseModuloCall(
                    $argument,
                    $sideIndex,
                    $source,
                    $variable,
                    $insideModulo,
                    $calls,
                );
            }
            if ($isSelector && \count($calls) === $callCount) {
                $calls[] = [$node, $sideIndex, $source];
            }
        } elseif ($node instanceof GroupingNode) {
            $this->collectSinglePiecewiseModuloCall(
                $node->expression,
                $sideIndex,
                $source,
                $variable,
                $insideModulo,
                $calls,
            );
        } elseif ($node instanceof UnaryOperationNode) {
            $this->collectSinglePiecewiseModuloCall(
                $node->operand,
                $sideIndex,
                $source,
                $variable,
                $insideModulo,
                $calls,
            );
        }
    }

    /**
     * @param list<array{FunctionCallNode|FactorialNode, int, int, int, int, string}> $calls
     */
    private function collectVariableIntegerArgumentSetCalls(
        Node $node,
        int $sideIndex,
        string $sideSource,
        string $variable,
        array &$calls,
    ): void {
        if ($node instanceof FunctionCallNode) {
            if (
                \in_array($node->name, ['besselj', 'besseli', 'bessely', 'polygamma'], true)
                && \count($node->arguments) === 2
            ) {
                $argumentIndex = 0;
                $minimumLevel = $node->name === 'polygamma'
                    ? 0
                    : -SpecialFunctions::MAX_BESSEL_ORDER;
                $maximumLevel = $node->name === 'polygamma'
                    ? SpecialFunctions::MAX_POLYGAMMA_ORDER
                    : SpecialFunctions::MAX_BESSEL_ORDER;
            } elseif ($node->name === 'lambertw' && \count($node->arguments) === 2) {
                $argumentIndex = 1;
                $minimumLevel = -1;
                $maximumLevel = 0;
            } elseif ($node->name === 'doublefactorial' && \count($node->arguments) === 1) {
                $argumentIndex = 0;
                $minimumLevel = -1;
                $maximumLevel = ResourceLimits::factorialMaximum();
            } elseif (
                \in_array($node->name, ['binomial', 'permutation'], true)
                && \count($node->arguments) === 2
            ) {
                foreach ([0, 1] as $combinatorialArgumentIndex) {
                    $argumentSpan = $node->arguments[$combinatorialArgumentIndex]->span();
                    $argumentSource = \substr(
                        $sideSource,
                        $argumentSpan->start,
                        $argumentSpan->end - $argumentSpan->start,
                    );
                    if ($this->containsVariable($argumentSource, $variable)) {
                        $calls[] = [
                            $node,
                            $combinatorialArgumentIndex,
                            0,
                            \PHP_INT_MAX,
                            $sideIndex,
                            $sideSource,
                        ];
                    }
                }
                $argumentIndex = -1;
                $minimumLevel = 0;
                $maximumLevel = -1;
            } elseif (
                \in_array($node->name, ['gcd', 'lcm'], true)
                && \count($node->arguments) === 2
            ) {
                foreach ([0, 1] as $integerArgumentIndex) {
                    $otherArgumentIndex = 1 - $integerArgumentIndex;
                    $integerArgument = $node->arguments[$integerArgumentIndex];
                    $otherArgument = $node->arguments[$otherArgumentIndex];
                    $otherArgumentSource = \substr(
                        $sideSource,
                        $otherArgument->span()->start,
                        $otherArgument->span()->end - $otherArgument->span()->start,
                    );
                    $otherIsVariableQuantizer = $otherArgument instanceof FunctionCallNode
                        && \in_array($otherArgument->name, ['floor', 'ceil', 'round', 'trunc'], true)
                        && \count($otherArgument->arguments) === 1
                        && $this->containsVariableNode($otherArgument->arguments[0], $variable);
                    if ($otherIsVariableQuantizer && $integerArgumentIndex === 1) {
                        continue;
                    }
                    if (
                        $integerArgument instanceof FunctionCallNode
                        && \in_array($integerArgument->name, ['floor', 'ceil', 'round', 'trunc'], true)
                        && \count($integerArgument->arguments) === 1
                        && (
                            !$this->containsVariable($otherArgumentSource, $variable)
                            || $otherIsVariableQuantizer
                        )
                        && $this->containsVariable(
                            \substr(
                                $sideSource,
                                $integerArgument->span()->start,
                                $integerArgument->span()->end - $integerArgument->span()->start,
                            ),
                            $variable,
                        )
                    ) {
                        $calls[] = [
                            $node,
                            $integerArgumentIndex,
                            -\PHP_INT_MAX,
                            \PHP_INT_MAX,
                            $sideIndex,
                            $sideSource,
                        ];
                    }
                }
                $argumentIndex = -1;
                $minimumLevel = 0;
                $maximumLevel = -1;
            } else {
                $argumentIndex = -1;
                $minimumLevel = 0;
                $maximumLevel = -1;
            }

            if ($argumentIndex >= 0 && isset($node->arguments[$argumentIndex])) {
                $span = $node->arguments[$argumentIndex]->span();
                $argumentSource = \substr(
                    $sideSource,
                    $span->start,
                    $span->end - $span->start,
                );
                if ($this->containsVariable($argumentSource, $variable)) {
                    $calls[] = [
                        $node,
                        $argumentIndex,
                        $minimumLevel,
                        $maximumLevel,
                        $sideIndex,
                        $sideSource,
                    ];
                }
            }

            foreach ($node->arguments as $argument) {
                $this->collectVariableIntegerArgumentSetCalls(
                    $argument,
                    $sideIndex,
                    $sideSource,
                    $variable,
                    $calls,
                );
            }

            return;
        }
        if ($node instanceof FactorialNode) {
            $operandSpan = $node->operand->span();
            $operandSource = \substr(
                $sideSource,
                $operandSpan->start,
                $operandSpan->end - $operandSpan->start,
            );
            if ($this->containsVariable($operandSource, $variable)) {
                $calls[] = [
                    $node,
                    0,
                    0,
                    ResourceLimits::factorialMaximum(),
                    $sideIndex,
                    $sideSource,
                ];
            }
            $this->collectVariableIntegerArgumentSetCalls(
                $node->operand,
                $sideIndex,
                $sideSource,
                $variable,
                $calls,
            );
        } elseif ($node instanceof GroupingNode) {
            $this->collectVariableIntegerArgumentSetCalls(
                $node->expression,
                $sideIndex,
                $sideSource,
                $variable,
                $calls,
            );
        } elseif ($node instanceof UnaryOperationNode) {
            $this->collectVariableIntegerArgumentSetCalls(
                $node->operand,
                $sideIndex,
                $sideSource,
                $variable,
                $calls,
            );
        } elseif ($node instanceof BinaryOperationNode) {
            $this->collectVariableIntegerArgumentSetCalls(
                $node->left,
                $sideIndex,
                $sideSource,
                $variable,
                $calls,
            );
            $this->collectVariableIntegerArgumentSetCalls(
                $node->right,
                $sideIndex,
                $sideSource,
                $variable,
                $calls,
            );
        }
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{int, int}|null
     */

    /**
     * Convert a same-argument floor/ceil sum with an odd target to an open range.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSameArgumentFloorCeilSet(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        $leftTerms = [];
        $rightTerms = [];
        $this->flattenAdditiveNodes($leftNode, 1.0, $leftTerms);
        $this->flattenAdditiveNodes($rightNode, 1.0, $rightTerms);
        $leftIsFloorCeilSum = $this->isDirectFloorCeilSum($leftTerms);
        $rightIsFloorCeilSum = $this->isDirectFloorCeilSum($rightTerms);
        if ($leftIsFloorCeilSum === $rightIsFloorCeilSum) {
            return null;
        }
        $functionTerms = $leftIsFloorCeilSum ? $leftTerms : $rightTerms;
        $constantSource = $leftIsFloorCeilSum ? $right : $left;
        if ($this->containsVariable($constantSource, $variable)) {
            return null;
        }

        try {
            $target = Math::evaluate($constantSource, $parameters, $evaluationOptions);
        } catch (EvaluationException) {
            return null;
        }
        if (
            !\is_finite((float) $target)
            || \floor((float) $target) !== (float) $target
            || \abs((float) $target) > 9_007_199_254_740_991.0
            || (((int) $target % 2) === 0)
        ) {
            return null;
        }

        $arguments = [];
        foreach ($functionTerms as [$node, $sign]) {
            if (!$node instanceof FunctionCallNode || $sign !== 1.0) {
                return null;
            }
            $arguments[] = $node->arguments[0];
        }
        $firstSpan = $arguments[0]->span();
        $secondSpan = $arguments[1]->span();
        $functionSource = $leftIsFloorCeilSum ? $left : $right;
        $firstSource = \substr(
            $functionSource,
            $firstSpan->start,
            $firstSpan->end - $firstSpan->start,
        );
        $secondSource = \substr(
            $functionSource,
            $secondSpan->start,
            $secondSpan->end - $secondSpan->start,
        );
        if ($firstSource === '' || $secondSource === '') {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $firstForm = $extractor->extractRationalForm($arguments[0]);
        $secondForm = $extractor->extractRationalForm($arguments[1]);
        if (
            $firstForm === null
            || $secondForm === null
            || !$this->rationalFormsAreEquivalent(
                $firstForm,
                $secondForm,
                $solverOptions->maxPolynomialDegree,
            )
        ) {
            return null;
        }

        $lowerLevel = (((float) $target) - 1.0) / 2.0;
        $upperLevel = (((float) $target) + 1.0) / 2.0;
        $inequalitySolver = new InequalitySolver();
        $aboveLower = $inequalitySolver->solve(
            '(' . $firstSource . ') > (' . \sprintf('%.17g', $lowerLevel) . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $belowUpper = $inequalitySolver->solve(
            '(' . $firstSource . ') < (' . \sprintf('%.17g', $upperLevel) . ')',
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $intersection = $this->intersect($aboveLower, $belowUpper);
        if ($this->containsVariable($secondSource, $variable)) {
            $aboveSecondLower = $inequalitySolver->solve(
                '(' . $secondSource . ') > (' . \sprintf('%.17g', $lowerLevel) . ')',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $belowSecondUpper = $inequalitySolver->solve(
                '(' . $secondSource . ') < (' . \sprintf('%.17g', $upperLevel) . ')',
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $intersection = $this->intersect($intersection, $aboveSecondLower);
            $intersection = $this->intersect($intersection, $belowSecondUpper);
        } else {
            try {
                $secondValue = Math::evaluate($secondSource, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                return [];
            }
            if ($secondValue <= $lowerLevel || $secondValue >= $upperLevel) {
                return [];
            }
        }

        return $this->merge($intersection);
    }

    /**
     * @param array{list<float>, list<float>} $left
     * @param array{list<float>, list<float>} $right
     */
    private function rationalFormsAreEquivalent(array $left, array $right, int $maxDegree): bool
    {
        $leftCrossProduct = $this->multiplyPolynomialCoefficients($left[0], $right[1], $maxDegree);
        $rightCrossProduct = $this->multiplyPolynomialCoefficients($right[0], $left[1], $maxDegree);
        if ($leftCrossProduct === null || $rightCrossProduct === null) {
            return false;
        }
        $length = \max(\count($leftCrossProduct), \count($rightCrossProduct));
        for ($degree = 0; $degree < $length; ++$degree) {
            $first = $leftCrossProduct[$degree] ?? 0.0;
            $second = $rightCrossProduct[$degree] ?? 0.0;
            if ($first !== $second) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>|null
     */
    private function multiplyPolynomialCoefficients(array $left, array $right, int $maxDegree): ?array
    {
        $degree = \count($left) + \count($right) - 2;
        if ($degree > $maxDegree) {
            return null;
        }
        $product = \array_fill(0, $degree + 1, 0.0);
        foreach ($left as $leftDegree => $leftCoefficient) {
            foreach ($right as $rightDegree => $rightCoefficient) {
                $index = $leftDegree + $rightDegree;
                $product[$index] += $leftCoefficient * $rightCoefficient;
                if (!\is_finite($product[$index])) {
                    return null;
                }
            }
        }
        while (\count($product) > 1 && $product[\array_key_last($product)] === 0.0) {
            \array_pop($product);
        }

        return \array_values($product);
    }

    /** @param list<array{Node, float}> $terms */
    private function flattenAdditiveNodes(Node $node, float $sign, array &$terms): void
    {
        if ($node instanceof GroupingNode) {
            $this->flattenAdditiveNodes($node->expression, $sign, $terms);

            return;
        }
        if ($node instanceof UnaryOperationNode && $node->operator === UnaryOperator::Minus) {
            $this->flattenAdditiveNodes($node->operand, -$sign, $terms);

            return;
        }
        if (
            $node instanceof BinaryOperationNode
            && \in_array($node->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
        ) {
            $this->flattenAdditiveNodes($node->left, $sign, $terms);
            $this->flattenAdditiveNodes(
                $node->right,
                $node->operator === BinaryOperator::Add ? $sign : -$sign,
                $terms,
            );

            return;
        }
        $terms[] = [$node, $sign];
    }

    /** @param list<array{Node, float}> $terms */
    private function isDirectFloorCeilSum(array $terms): bool
    {
        if (\count($terms) !== 2) {
            return false;
        }
        $names = [];
        foreach ($terms as [$node, $sign]) {
            if (
                !$node instanceof FunctionCallNode
                || !\in_array($node->name, ['floor', 'ceil'], true)
                || \count($node->arguments) !== 1
                || $sign !== 1.0
            ) {
                return false;
            }
            $names[] = $node->name;
        }

        return \count(\array_unique($names)) === 2;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveExactIntegerAffineEquation(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        $difference = (new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        ))->difference($leftNode, $rightNode);
        if ($difference === null) {
            return null;
        }
        $extractor = new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree);
        while (
            \count($difference) > 1
            && $extractor->isZero($difference[\array_key_last($difference)])
        ) {
            \array_pop($difference);
        }
        if (\count($difference) > 2) {
            return null;
        }

        $constant = $difference[0] ?? '0';
        $linear = $difference[1] ?? '0';
        if ($extractor->isZero($linear)) {
            return $extractor->isZero($constant)
                ? [new InequalityInterval($lowerBound, $upperBound, true, true)]
                : [];
        }

        $root = $extractor->ratio($extractor->subtract('0', $constant), $linear);
        if ($root === false) {
            return [];
        }
        if ($root === null || !\is_finite($root)) {
            return null;
        }
        $lowerFraction = RationalLiteral::fromFloatExactAsStrings($lowerBound);
        $upperFraction = RationalLiteral::fromFloatExactAsStrings($upperBound);
        if ($lowerFraction === null || $upperFraction === null) {
            return null;
        }
        $rootNumerator = $extractor->subtract('0', $constant);
        $lowerComparison = $extractor->compareIntegerRatioToFraction(
            $rootNumerator,
            $linear,
            $lowerFraction[0],
            $lowerFraction[1],
        );
        $upperComparison = $extractor->compareIntegerRatioToFraction(
            $rootNumerator,
            $linear,
            $upperFraction[0],
            $upperFraction[1],
        );
        if ($lowerComparison === null || $upperComparison === null) {
            return null;
        }
        if ($lowerComparison < 0) {
            return [];
        } elseif ($upperComparison > 0) {
            return [];
        }

        return [new InequalityInterval($root, $root, true, true)];
    }

    /** @return array{string, string} */
    private function splitEquation(string $equation): array
    {
        $position = \strpos($equation, '=');
        if ($position === false || \strpos($equation, '=', $position + 1) !== false) {
            $errorPosition = $position === false ? \strlen($equation) : $position;
            throw new ParseException(
                'Equation must contain exactly one "=" sign',
                'parse.equation_equals',
                new SourceSpan(
                    $errorPosition,
                    $errorPosition + ($position === false ? 0 : 1),
                ),
            );
        }

        $left = \trim(\substr($equation, 0, $position));
        $right = \trim(\substr($equation, $position + 1));
        if ($left === '' || $right === '') {
            throw new ParseException(
                'Both sides of the equation must contain an expression',
                'parse.empty_equation_side',
                new SourceSpan($position, $position + 1),
            );
        }

        return [$left, $right];
    }

    /**
     * Intersect the bounded solution sets for each adjacent equality.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function solveChainedEqualitySet(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        $parts = \explode('=', $equation);
        $solutions = [new InequalityInterval($lowerBound, $upperBound, true, true)];
        $partOffset = 0;
        for ($index = 0; $index + 1 < \count($parts); ++$index) {
            try {
                $linkSolutions = $this->solve(
                    $parts[$index] . '=' . $parts[$index + 1],
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (LexicalException | ParseException $error) {
                throw $this->shiftChainedParseError($error, $partOffset);
            }
            $previousSolutions = $solutions;
            $solutions = $this->intersect($solutions, $linkSolutions);
            array_push(
                $solutions,
                ...$this->nearPointIntersections(
                    $previousSolutions,
                    $linkSolutions,
                    $parts,
                    $index,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
            );
            if ($solutions === []) {
                return [];
            }
            \usort(
                $solutions,
                static fn (InequalityInterval $left, InequalityInterval $right): int =>
                    ($left->lowerBound <=> $right->lowerBound)
                    ?: ($left->upperBound <=> $right->upperBound),
            );
            $solutions = $this->merge($solutions);
            $partOffset += \strlen($parts[$index]) + 1;
        }

        return $solutions;
    }

    /**
     * @param list<InequalityInterval> $left
     * @param list<InequalityInterval> $right
     * @param list<string> $parts
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function nearPointIntersections(
        array $left,
        array $right,
        array $parts,
        int $lastLink,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        $result = [];
        $leftIndex = 0;
        $rightIndex = 0;
        while (isset($left[$leftIndex], $right[$rightIndex])) {
            $leftInterval = $left[$leftIndex];
            $rightInterval = $right[$rightIndex];
            $candidate = $this->nearPointIntersectionCandidate($leftInterval, $rightInterval, $solverOptions);
            if (
                $candidate !== null
                && $this->chainedEqualitiesHoldAt(
                    $parts,
                    $lastLink,
                    $variable,
                    $candidate,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                )
            ) {
                $result[] = new InequalityInterval($candidate, $candidate, true, true);
            }
            if ($leftInterval->upperBound <= $rightInterval->upperBound) {
                ++$leftIndex;
            }
            if ($rightInterval->upperBound <= $leftInterval->upperBound) {
                ++$rightIndex;
            }
        }

        return $result;
    }

    private function shiftChainedParseError(MathException $error, int $offset): MathException
    {
        if ($offset === 0) {
            return $error;
        }
        $message = \preg_replace('/ at position \d+$/', '', $error->getMessage())
            ?? 'Equation expression failed';
        $sourceSpan = $error->span();
        $span = new SourceSpan($sourceSpan->start + $offset, $sourceSpan->end + $offset);

        return $error instanceof LexicalException
            ? new LexicalException($message, $error->errorCode(), $span, $error)
            : new ParseException($message, $error->errorCode(), $span, $error);
    }

    private function nearPointIntersectionCandidate(
        InequalityInterval $left,
        InequalityInterval $right,
        SolverOptions $solverOptions,
    ): ?float {
        $leftIsPoint = $left->lowerBound === $left->upperBound;
        $rightIsPoint = $right->lowerBound === $right->upperBound;
        if ($leftIsPoint && $rightIsPoint) {
            if ($left->lowerBound === $right->lowerBound) {
                return null;
            }

            return \abs($left->lowerBound - $right->lowerBound)
                    <= $solverOptions->positionTolerance($left->lowerBound, $right->lowerBound)
                ? ($left->lowerBound + $right->lowerBound) / 2.0
                : null;
        }
        if ($leftIsPoint === $rightIsPoint) {
            return null;
        }
        $point = $leftIsPoint ? $left->lowerBound : $right->lowerBound;
        $interval = $leftIsPoint ? $right : $left;
        if ($interval->contains($point)) {
            return null;
        }
        if ($point < $interval->lowerBound) {
            return $interval->lowerBound - $point
                    <= $solverOptions->positionTolerance($interval->lowerBound, $point)
                ? $point
                : null;
        }

        return $point - $interval->upperBound
                <= $solverOptions->positionTolerance($interval->upperBound, $point)
            ? $point
            : null;
    }

    /**
     * @param list<string> $parts
     * @param array<string, int|float> $parameters
     */
    private function chainedEqualitiesHoldAt(
        array $parts,
        int $lastLink,
        string $variable,
        float $candidate,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): bool {
        $variables = $parameters;
        $variables[$variable] = $candidate;
        for ($index = 0; $index <= $lastLink; ++$index) {
            try {
                $left = Math::evaluate($parts[$index], $variables, $evaluationOptions);
                $right = Math::evaluate($parts[$index + 1], $variables, $evaluationOptions);
            } catch (\Throwable) {
                return false;
            }
            if (
                \abs($left - $right)
                > $solverOptions->residualTolerance * \max(1.0, \abs($left), \abs($right))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int|float> $parameters */
    private function validateInputs(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): void {
        if (\strlen($equation) > $evaluationOptions->limits->maxExpressionLength) {
            $position = $evaluationOptions->limits->maxExpressionLength;
            throw new \MathPHP\Exception\LexicalException(
                'Equation exceeds the configured byte limit',
                'limit.expression_length',
                new SourceSpan($position, $position + 1),
            );
        }
        if (
            \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) !== 1
            || \in_array($variable, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
        ) {
            throw new \InvalidArgumentException(
                'The solved variable must be a non-reserved ASCII identifier.',
            );
        }
        if (\array_key_exists($variable, $parameters)) {
            throw new \InvalidArgumentException(
                'The solved variable cannot also be supplied as a parameter.',
            );
        }
        if (
            !\is_finite($lowerBound)
            || !\is_finite($upperBound)
            || $lowerBound >= $upperBound
            || !\is_finite($upperBound - $lowerBound)
        ) {
            throw new \InvalidArgumentException(
                'The solving interval must have finite, increasing bounds and a finite width.',
            );
        }
    }

    private function containsVariable(string $expression, string $variable): bool
    {
        return \preg_match(
            '/(?<![A-Za-z0-9_])' . \preg_quote($variable, '/') . '(?![A-Za-z0-9_])(?!\s*\()/',
            $expression,
        ) === 1;
    }

    private function containsVariableCall(
        string $expression,
        string $variable,
        EvaluationOptions $evaluationOptions,
    ): bool {
        return $evaluationOptions->functions->find($variable) === null
            && \preg_match(
                '/(?<![A-Za-z0-9_])' . \preg_quote($variable, '/') . '(?![A-Za-z0-9_])\s*\(/',
                $expression,
            ) === 1;
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function isKnownIdentity(
        string $left,
        string $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): bool {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $leftNode = $this->stripExactIdentityCalls($leftNode, $evaluationOptions);
            $rightNode = $this->stripExactIdentityCalls($rightNode, $evaluationOptions);
            $polynomialDifference = (new ExactRationalPolynomialExtractor(
                $variable,
                $parameters,
                $solverOptions->maxPolynomialDegree,
                $evaluationOptions,
            ))->difference($leftNode, $rightNode);
            if ($polynomialDifference !== null) {
                return $polynomialDifference === ['0'];
            }
            if ($this->equivalentNodes($leftNode, $rightNode)) {
                return true;
            }
        } catch (EvaluationException | ParseException) {
            return false;
        }

        if ($this->isInverseCompositionIdentity($left, $right, $evaluationOptions)) {
            return true;
        }

        if ($this->isPythagoreanTrigonometricIdentity($leftNode, $rightNode)) {
            return true;
        }

        if ($this->isOddRootPowerIdentity($leftNode, $rightNode, $parameters)) {
            return true;
        }

        return false;
    }

    private function stripExactIdentityCalls(Node $node, EvaluationOptions $evaluationOptions): Node
    {
        if ($node instanceof FunctionCallNode) {
            $definition = $evaluationOptions->functions->find($node->name);
            if (
                $definition?->exactIdentityArgument !== null
                && isset($node->arguments[$definition->exactIdentityArgument])
            ) {
                return $this->stripExactIdentityCalls(
                    $node->arguments[$definition->exactIdentityArgument],
                    $evaluationOptions,
                );
            }

            return new FunctionCallNode(
                $node->name,
                \array_map(
                    fn (Node $argument): Node => $this->stripExactIdentityCalls($argument, $evaluationOptions),
                    $node->arguments,
                ),
                $node->nameSpan,
                $node->span,
            );
        }
        if ($node instanceof GroupingNode) {
            return new GroupingNode(
                $this->stripExactIdentityCalls($node->expression, $evaluationOptions),
                $node->span,
            );
        }
        if ($node instanceof UnaryOperationNode) {
            return new UnaryOperationNode(
                $node->operator,
                $this->stripExactIdentityCalls($node->operand, $evaluationOptions),
                $node->operatorSpan,
            );
        }
        if ($node instanceof BinaryOperationNode) {
            return new BinaryOperationNode(
                $node->operator,
                $this->stripExactIdentityCalls($node->left, $evaluationOptions),
                $this->stripExactIdentityCalls($node->right, $evaluationOptions),
                $node->operatorSpan,
            );
        }
        if ($node instanceof FactorialNode) {
            return new FactorialNode(
                $this->stripExactIdentityCalls($node->operand, $evaluationOptions),
                $node->operatorSpan,
            );
        }

        return $node;
    }

    /** @return list<InequalityInterval>|null */
    private function solveNestedSelfCompositionFixedPoint(
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        EvaluationOptions $evaluationOptions,
    ): ?array {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return null;
        }

        foreach ([[$leftNode, $rightNode], [$rightNode, $leftNode]] as [$composition, $target]) {
            while ($composition instanceof GroupingNode) {
                $composition = $composition->expression;
            }
            while ($target instanceof GroupingNode) {
                $target = $target->expression;
            }
            if (
                !$target instanceof VariableNode
                || $target->name !== $variable
                || !$composition instanceof FunctionCallNode
                || !$evaluationOptions->functions->isBuiltIn($composition->name)
                || \count($composition->arguments) !== 1
            ) {
                continue;
            }
            $canonicalName = match ($composition->name) {
                'arcsin' => 'asin',
                'arctan' => 'atan',
                'arcsinh' => 'asinh',
                'arctanh' => 'atanh',
                default => $composition->name,
            };
            // Each listed function strictly contracts or expands |x| away from zero.
            if (!\in_array($canonicalName, ['sin', 'asin', 'atan', 'tanh', 'asinh', 'atanh'], true)) {
                continue;
            }
            $inner = $composition->arguments[0];
            while ($inner instanceof GroupingNode) {
                $inner = $inner->expression;
            }
            if (
                !$inner instanceof FunctionCallNode
                || \count($inner->arguments) !== 1
                || !$evaluationOptions->functions->isBuiltIn($inner->name)
            ) {
                continue;
            }
            $innerCanonicalName = match ($inner->name) {
                'arcsin' => 'asin',
                'arctan' => 'atan',
                'arcsinh' => 'asinh',
                'arctanh' => 'atanh',
                default => $inner->name,
            };
            if ($innerCanonicalName !== $canonicalName) {
                continue;
            }
            $innerArgument = $inner->arguments[0];
            while ($innerArgument instanceof GroupingNode) {
                $innerArgument = $innerArgument->expression;
            }
            if (!$innerArgument instanceof VariableNode || $innerArgument->name !== $variable) {
                continue;
            }

            return $lowerBound <= 0.0 && $upperBound >= 0.0
                ? [new InequalityInterval(0.0, 0.0, true, true)]
                : [];
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function hasGloballyInvalidRootDegree(
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        try {
            $node = (new Parser(
                (new Lexer($source, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return false;
        }

        return $this->containsGloballyInvalidRootDegreeNode(
            $node,
            $variable,
            $parameters,
            $evaluationOptions,
        );
    }

    /** @param array<string, int|float> $parameters */
    private function containsGloballyInvalidRootDegreeNode(
        Node $node,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        if ($node instanceof FunctionCallNode) {
            if (
                $node->name === 'root'
                && \count($node->arguments) === 2
                && !$this->containsVariableNode($node->arguments[1], $variable)
            ) {
                try {
                    $degree = (float) (new Evaluator(
                        new Environment($parameters, $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    ))->evaluate($node->arguments[1]);
                } catch (EvaluationException) {
                    return true;
                }
                $nearest = \round($degree);
                $tolerance = \min(
                    1.0E-9,
                    16.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($degree)),
                );
                if (\abs($degree - $nearest) <= $tolerance) {
                    $degree = $nearest;
                }
                if (
                    !\is_finite($degree)
                    || $degree === 0.0
                    || \floor($degree) !== $degree
                    || \abs($degree) > 9_007_199_254_740_991.0
                ) {
                    return true;
                }
            }
            foreach ($node->arguments as $argument) {
                if (
                    $this->containsGloballyInvalidRootDegreeNode(
                        $argument,
                        $variable,
                        $parameters,
                        $evaluationOptions,
                    )
                ) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsGloballyInvalidRootDegreeNode(
                $node->left,
                $variable,
                $parameters,
                $evaluationOptions,
            ) || $this->containsGloballyInvalidRootDegreeNode(
                $node->right,
                $variable,
                $parameters,
                $evaluationOptions,
            );
        }
        if ($node instanceof GroupingNode || $node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            $child = match (true) {
                $node instanceof GroupingNode => $node->expression,
                $node instanceof UnaryOperationNode => $node->operand,
                default => $node->operand,
            };

            return $this->containsGloballyInvalidRootDegreeNode(
                $child,
                $variable,
                $parameters,
                $evaluationOptions,
            );
        }

        return false;
    }

    private function containsVariableNode(Node $node, string $variable): bool
    {
        if ($node instanceof VariableNode) {
            return $node->name === $variable;
        }
        if ($node instanceof FunctionCallNode) {
            if ($node->name === $variable) {
                return true;
            }
            foreach ($node->arguments as $argument) {
                if ($this->containsVariableNode($argument, $variable)) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariableNode($node->left, $variable)
                || $this->containsVariableNode($node->right, $variable);
        }
        if ($node instanceof GroupingNode || $node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            $child = match (true) {
                $node instanceof GroupingNode => $node->expression,
                $node instanceof UnaryOperationNode => $node->operand,
                default => $node->operand,
            };

            return $this->containsVariableNode($child, $variable);
        }

        return false;
    }

    /** @param array<string, int|float> $parameters */
    private function isOddRootPowerIdentity(Node $left, Node $right, array $parameters): bool
    {
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $argument]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            while ($argument instanceof GroupingNode) {
                $argument = $argument->expression;
            }
            if ($candidate instanceof FunctionCallNode) {
                $root = $this->oddRootArgument($candidate, $parameters);
                if ($root !== null) {
                    [$rootArgument, $degree] = $root;
                    while ($rootArgument instanceof GroupingNode) {
                        $rootArgument = $rootArgument->expression;
                    }
                    if (
                        !$rootArgument instanceof BinaryOperationNode
                        || $rootArgument->operator !== BinaryOperator::Power
                    ) {
                        continue;
                    }
                    $power = $this->nonzeroOddInteger($rootArgument->right, $parameters);
                    if (
                        $power === $degree
                        && $this->equivalentNodes($rootArgument->left, $argument)
                    ) {
                        return true;
                    }
                }
            }
            if (
                !$candidate instanceof BinaryOperationNode
                || $candidate->operator !== BinaryOperator::Power
            ) {
                continue;
            }
            $power = $this->nonzeroOddInteger($candidate->right, $parameters);
            if ($power === null) {
                continue;
            }
            $base = $candidate->left;
            while ($base instanceof GroupingNode) {
                $base = $base->expression;
            }
            if (
                !$base instanceof FunctionCallNode
            ) {
                continue;
            }
            $root = $this->oddRootArgument($base, $parameters);
            if (
                $root !== null
                && $root[1] === $power
                && $this->equivalentNodes($root[0], $argument)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{Node, int}|null
     */
    private function oddRootArgument(FunctionCallNode $node, array $parameters): ?array
    {
        if ($node->name === 'cbrt' && \count($node->arguments) === 1) {
            return [$node->arguments[0], 3];
        }
        if (
            $node->name === 'root'
            && \count($node->arguments) === 2
        ) {
            $degree = $this->nonzeroOddInteger($node->arguments[1], $parameters);

            return $degree === null ? null : [$node->arguments[0], $degree];
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function nonzeroOddInteger(Node $node, array $parameters): ?int
    {
        $integer = $this->exactIntegerExpression($node, $parameters);
        if ($integer === null || $integer === 0) {
            return null;
        }

        return ($integer % 2) !== 0 ? $integer : null;
    }

    /** @param array<string, int|float> $parameters */
    private function exactIntegerExpression(Node $node, array $parameters): ?int
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        $fraction = RationalLiteral::fromNode($node);
        if (
            $fraction !== null
            && $fraction[1] === 1
            && $fraction[0] >= -9_007_199_254_740_991
            && $fraction[0] <= 9_007_199_254_740_991
        ) {
            return $fraction[0];
        }
        if ($node instanceof VariableNode && \array_key_exists($node->name, $parameters)) {
            $value = $parameters[$node->name];
            if (
                \is_finite((float) $value)
                && (float) $value <= 9_007_199_254_740_991.0
                && (float) $value >= -9_007_199_254_740_991.0
                && \floor((float) $value) === (float) $value
            ) {
                return (int) $value;
            }

            return null;
        }
        if ($node instanceof UnaryOperationNode) {
            $operand = $this->exactIntegerExpression($node->operand, $parameters);
            if ($operand === null) {
                return null;
            }

            return $node->operator === UnaryOperator::Minus ? -$operand : $operand;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        $left = $this->exactIntegerExpression($node->left, $parameters);
        $right = $this->exactIntegerExpression($node->right, $parameters);
        if ($left === null || $right === null) {
            return null;
        }
        $limit = 9_007_199_254_740_991;
        $result = match ($node->operator) {
            BinaryOperator::Add => $left + $right,
            BinaryOperator::Subtract => $left - $right,
            BinaryOperator::Multiply => $left === 0 || $right === 0
                ? 0
                : (abs($right) > intdiv($limit, abs($left)) ? null : $left * $right),
            BinaryOperator::Divide => $right !== 0 && $left % $right === 0
                ? intdiv($left, $right)
                : null,
            BinaryOperator::Power => $this->exactIntegerPower($left, $right, $limit),
            BinaryOperator::Modulo => $right !== 0 ? $left % $right : null,
        };

        return $result === null || abs($result) > $limit ? null : $result;
    }

    private function exactIntegerPower(int $base, int $exponent, int $limit): ?int
    {
        if ($exponent < 0) {
            if ($base === 1) {
                return 1;
            }

            return $base === -1 && ($exponent % 2) !== 0 ? -1 : ($base === -1 ? 1 : null);
        }
        $result = 1;
        while ($exponent > 0) {
            if (($exponent % 2) !== 0) {
                if ($base !== 0 && abs($result) > intdiv($limit, abs($base))) {
                    return null;
                }
                $result *= $base;
            }
            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                if ($base !== 0 && abs($base) > intdiv($limit, abs($base))) {
                    return null;
                }
                $base *= $base;
            }
        }

        return $result;
    }

    private function isPythagoreanTrigonometricIdentity(Node $left, Node $right): bool
    {
        foreach ([[$left, $right], [$right, $left]] as [$sum, $target]) {
            while ($sum instanceof GroupingNode) {
                $sum = $sum->expression;
            }
            while ($target instanceof GroupingNode) {
                $target = $target->expression;
            }
            if (
                !$sum instanceof BinaryOperationNode
                || $sum->operator !== BinaryOperator::Add
                || !$target instanceof NumberNode
                || (float) $target->value !== 1.0
            ) {
                continue;
            }
            $first = $this->squaredTrigonometricFunction($sum->left);
            $second = $this->squaredTrigonometricFunction($sum->right);
            if (
                $first !== null
                && $second !== null
                && $this->isSquareIdentityPair($first[0], $second[0], BinaryOperator::Add)
                && $this->equivalentNodes($first[1], $second[1])
            ) {
                return true;
            }
        }

        foreach ([[$left, $right], [$right, $left]] as [$difference, $target]) {
            while ($difference instanceof GroupingNode) {
                $difference = $difference->expression;
            }
            while ($target instanceof GroupingNode) {
                $target = $target->expression;
            }
            if (
                !$difference instanceof BinaryOperationNode
                || $difference->operator !== BinaryOperator::Subtract
                || !$target instanceof NumberNode
                || (float) $target->value !== 1.0
            ) {
                continue;
            }
            $first = $this->squaredTrigonometricFunction($difference->left);
            $second = $this->squaredTrigonometricFunction($difference->right);
            if (
                $first !== null
                && $second !== null
                && $this->isSquareIdentityPair($first[0], $second[0], BinaryOperator::Subtract)
                && $this->equivalentNodes($first[1], $second[1])
            ) {
                return true;
            }
        }

        foreach ([[$left, $right], [$right, $left]] as [$square, $complement]) {
            $function = $this->squaredTrigonometricFunction($square);
            while ($complement instanceof GroupingNode) {
                $complement = $complement->expression;
            }
            if (
                $function === null
                || !$complement instanceof BinaryOperationNode
                || !$complement->left instanceof NumberNode
                || (float) $complement->left->value !== 1.0
            ) {
                continue;
            }
            $complementFunction = $this->squaredTrigonometricFunction($complement->right);
            if ($complementFunction === null || !$this->equivalentNodes($function[1], $complementFunction[1])) {
                continue;
            }
            $circularRearrangement = $complement->operator === BinaryOperator::Subtract
                && $this->isSquareIdentityPair(
                    $function[0],
                    $complementFunction[0],
                    BinaryOperator::Add,
                );
            $hyperbolicRearrangement = $complement->operator === BinaryOperator::Add
                && $this->isSquareComplementPair(
                    $function[0],
                    $complementFunction[0],
                );
            if ($circularRearrangement || $hyperbolicRearrangement) {
                return true;
            }
        }

        foreach ([[$left, $right], [$right, $left]] as [$square, $difference]) {
            $function = $this->squaredTrigonometricFunction($square);
            while ($difference instanceof GroupingNode) {
                $difference = $difference->expression;
            }
            if (
                $function === null
                || !\in_array($function[0], ['sinh', 'csch', 'tan', 'cot'], true)
                || !$difference instanceof BinaryOperationNode
                || $difference->operator !== BinaryOperator::Subtract
                || !$difference->left instanceof BinaryOperationNode
                || $difference->left->operator !== BinaryOperator::Power
            ) {
                continue;
            }
            $complementFunction = $this->squaredTrigonometricFunction($difference->left);
            $constant = $difference->right;
            while ($constant instanceof GroupingNode) {
                $constant = $constant->expression;
            }
            if (
                $complementFunction !== null
                && $this->isSquareIdentityPair(
                    $complementFunction[0],
                    $function[0],
                    BinaryOperator::Subtract,
                )
                && $this->equivalentNodes($function[1], $complementFunction[1])
                && $constant instanceof NumberNode
                && (float) $constant->value === 1.0
            ) {
                return true;
            }
        }

        return false;
    }

    private function isSquareIdentityPair(
        string $first,
        string $second,
        BinaryOperator $operator,
    ): bool {
        if ($operator === BinaryOperator::Add) {
            return ($first === 'sin' && $second === 'cos')
                || ($first === 'cos' && $second === 'sin')
                || ($first === 'tanh' && $second === 'sech')
                || ($first === 'sech' && $second === 'tanh');
        }

        return ($first === 'cosh' && $second === 'sinh')
            || ($first === 'coth' && $second === 'csch')
            || ($first === 'sec' && $second === 'tan')
            || ($first === 'csc' && $second === 'cot');
    }

    private function isSquareComplementPair(string $first, string $second): bool
    {
        return ($first === 'cosh' && $second === 'sinh')
            || ($first === 'coth' && $second === 'csch')
            || ($first === 'sec' && $second === 'tan')
            || ($first === 'csc' && $second === 'cot');
    }

    /** @return array{'sin'|'cos'|'sinh'|'cosh'|'tanh'|'sech'|'coth'|'csch'|'tan'|'cot'|'sec'|'csc', Node}|null */
    private function squaredTrigonometricFunction(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            !$node instanceof BinaryOperationNode
            || $node->operator !== BinaryOperator::Power
            || !$node->right instanceof NumberNode
            || (float) $node->right->value !== 2.0
        ) {
            return null;
        }
        $function = $node->left;
        while ($function instanceof GroupingNode) {
            $function = $function->expression;
        }
        if (
            !$function instanceof FunctionCallNode
            || !\in_array(
                $function->name,
                [
                    'sin', 'cos', 'sinh', 'cosh', 'tanh', 'sech', 'coth', 'csch',
                    'tan', 'cot', 'sec', 'csc',
                ],
                true,
            )
            || \count($function->arguments) !== 1
        ) {
            return null;
        }

        return [$function->name, $function->arguments[0]];
    }

    private function isInverseCompositionIdentity(
        string $left,
        string $right,
        EvaluationOptions $evaluationOptions,
    ): bool {
        try {
            $leftNode = (new Parser(
                (new Lexer($left, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
            $rightNode = (new Parser(
                (new Lexer($right, $evaluationOptions->limits))->tokenize(),
                $evaluationOptions->limits,
                $evaluationOptions->functions,
            ))->parse();
        } catch (MathException) {
            return false;
        }

        return $this->isInverseCompositionPair($leftNode, $rightNode)
            || $this->isInverseCompositionPair($rightNode, $leftNode);
    }

    private function isInverseCompositionPair(Node $composition, Node $argument): bool
    {
        while ($composition instanceof GroupingNode) {
            $composition = $composition->expression;
        }
        while ($argument instanceof GroupingNode) {
            $argument = $argument->expression;
        }
        if (!$composition instanceof FunctionCallNode || \count($composition->arguments) !== 1) {
            return false;
        }

        $inverseFunctions = [
            'asinh' => ['sinh'],
            'atanh' => ['tanh'],
            'acsch' => ['csch'],
            'acoth' => ['coth'],
            'ln' => ['exp'],
            'log1p' => ['expm1'],
            'exp' => ['ln'],
            'expm1' => ['log1p'],
            'sin' => ['asin', 'arcsin'],
            'cos' => ['acos', 'arccos'],
            'tan' => ['atan', 'arctan'],
            'cosh' => ['acosh', 'arccosh'],
            'sinh' => ['asinh', 'arcsinh'],
            'tanh' => ['atanh', 'arctanh'],
            'sech' => ['asech'],
            'csch' => ['acsch'],
            'coth' => ['acoth'],
            'erf' => ['erfinv'],
            'erfc' => ['erfcinv'],
        ];
        $inner = $composition->arguments[0];
        while ($inner instanceof GroupingNode) {
            $inner = $inner->expression;
        }
        if (
            !$inner instanceof FunctionCallNode
            || \count($inner->arguments) !== 1
            || !\in_array($inner->name, $inverseFunctions[$composition->name] ?? [], true)
        ) {
            return false;
        }

        return $this->equivalentNodes($inner->arguments[0], $argument);
    }

    private function equivalentNodes(Node $first, Node $second): bool
    {
        while ($first instanceof GroupingNode) {
            $first = $first->expression;
        }
        while ($second instanceof GroupingNode) {
            $second = $second->expression;
        }

        if ($first::class !== $second::class) {
            return false;
        }
        if ($first instanceof VariableNode && $second instanceof VariableNode) {
            return $first->name === $second->name;
        }
        if ($first instanceof ConstantNode && $second instanceof ConstantNode) {
            return $first->name === $second->name;
        }
        if ($first instanceof NumberNode && $second instanceof NumberNode) {
            return $first->value == $second->value;
        }
        if ($first instanceof UnaryOperationNode && $second instanceof UnaryOperationNode) {
            return $first->operator === $second->operator
                && $this->equivalentNodes($first->operand, $second->operand);
        }
        if ($first instanceof BinaryOperationNode && $second instanceof BinaryOperationNode) {
            return $first->operator === $second->operator
                && $this->equivalentNodes($first->left, $second->left)
                && $this->equivalentNodes($first->right, $second->right);
        }
        if ($first instanceof FactorialNode && $second instanceof FactorialNode) {
            return $this->equivalentNodes($first->operand, $second->operand);
        }
        if ($first instanceof FunctionCallNode && $second instanceof FunctionCallNode) {
            if ($first->name !== $second->name || \count($first->arguments) !== \count($second->arguments)) {
                return false;
            }
            foreach ($first->arguments as $index => $argument) {
                if (!$this->equivalentNodes($argument, $second->arguments[$index])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @param array<string, int|float> $parameters */
    private function matchesAt(
        string $left,
        string $right,
        string $variable,
        float $position,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        try {
            $variables = $parameters;
            $variables[$variable] = $position;
            $leftValue = Math::evaluate($left, $variables, $evaluationOptions);
            $rightValue = Math::evaluate($right, $variables, $evaluationOptions);
        } catch (\Throwable) {
            return false;
        }

        return $this->roundoffEqual($leftValue, $rightValue);
    }

    private function roundoffEqual(int|float $left, int|float $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left === $right;
        }

        $scale = \max(\abs((float) $left), \abs((float) $right));
        $residual = (float) $left - (float) $right;

        return ($scale === 0.0 && $residual === 0.0)
            || ($scale !== 0.0 && \abs($residual) <= 8.0 * \PHP_FLOAT_EPSILON * $scale);
    }

    /**
     * @param list<InequalityInterval> $left
     * @param list<InequalityInterval> $right
     * @return list<InequalityInterval>
     */
    private function intersect(array $left, array $right): array
    {
        $intersections = [];
        $leftIndex = 0;
        $rightIndex = 0;
        while (isset($left[$leftIndex], $right[$rightIndex])) {
            $leftInterval = $left[$leftIndex];
            $rightInterval = $right[$rightIndex];

            if ($leftInterval->lowerBound > $rightInterval->lowerBound) {
                $lowerBound = $leftInterval->lowerBound;
                $includesLowerBound = $leftInterval->includesLowerBound;
            } elseif ($leftInterval->lowerBound < $rightInterval->lowerBound) {
                $lowerBound = $rightInterval->lowerBound;
                $includesLowerBound = $rightInterval->includesLowerBound;
            } else {
                $lowerBound = $leftInterval->lowerBound;
                $includesLowerBound = $leftInterval->includesLowerBound
                    && $rightInterval->includesLowerBound;
            }

            if ($leftInterval->upperBound < $rightInterval->upperBound) {
                $upperBound = $leftInterval->upperBound;
                $includesUpperBound = $leftInterval->includesUpperBound;
            } elseif ($leftInterval->upperBound > $rightInterval->upperBound) {
                $upperBound = $rightInterval->upperBound;
                $includesUpperBound = $rightInterval->includesUpperBound;
            } else {
                $upperBound = $leftInterval->upperBound;
                $includesUpperBound = $leftInterval->includesUpperBound
                    && $rightInterval->includesUpperBound;
            }

            if ($lowerBound < $upperBound) {
                $intersections[] = new InequalityInterval(
                    $lowerBound,
                    $upperBound,
                    $includesLowerBound,
                    $includesUpperBound,
                );
            } elseif (
                $lowerBound === $upperBound
                && $includesLowerBound
                && $includesUpperBound
            ) {
                $intersections[] = new InequalityInterval($lowerBound, $upperBound, true, true);
            }

            if ($leftInterval->upperBound <= $rightInterval->upperBound) {
                ++$leftIndex;
            }
            if ($rightInterval->upperBound <= $leftInterval->upperBound) {
                ++$rightIndex;
            }
        }

        return $intersections;
    }

    /**
     * @param list<InequalityInterval> $intervals
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function removeDiscontinuousEqualityArtifacts(
        array $intervals,
        string $left,
        string $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): array {
        foreach ([[$left, $right], [$right, $left]] as [$signSource, $targetSource]) {
            try {
                $node = (new Parser(
                    (new Lexer($signSource, $evaluationOptions->limits))->tokenize(),
                    $evaluationOptions->limits,
                    $evaluationOptions->functions,
                ))->parse();
            } catch (MathException) {
                return $intervals;
            }
            while ($node instanceof GroupingNode) {
                $node = $node->expression;
            }
            if (
                !$node instanceof FunctionCallNode
                || $node->name !== 'sign'
                || \count($node->arguments) !== 1
                || !$evaluationOptions->functions->isBuiltIn('sign')
                || !$this->containsVariable($targetSource, $variable)
            ) {
                continue;
            }
            $span = $node->arguments[0]->span();
            $argumentSource = \substr($signSource, $span->start, $span->end - $span->start);
            $filtered = [];
            foreach ($intervals as $interval) {
                if ($interval->lowerBound !== $interval->upperBound) {
                    $filtered[] = $interval;
                    continue;
                }
                $values = [$variable => $interval->lowerBound] + $parameters;
                try {
                    $signValue = Math::evaluate($signSource, $values, $evaluationOptions);
                    $targetValue = Math::evaluate($targetSource, $values, $evaluationOptions);
                    $argumentValue = Math::evaluate($argumentSource, $values, $evaluationOptions);
                } catch (EvaluationException) {
                    continue;
                }
                if ((float) $signValue === (float) $targetValue) {
                    $filtered[] = $interval;
                }
            }

            return $filtered;
        }

        return $intervals;
    }

    /**
     * @param list<InequalityInterval> $intervals
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function removeDominatedBoundaryPoints(
        array $intervals,
        string $left,
        string $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        $filtered = [];
        foreach ($intervals as $interval) {
            if ($interval->lowerBound !== $interval->upperBound) {
                $filtered[] = $interval;
                continue;
            }

            $pointResidual = $this->residualAt(
                $left,
                $right,
                $variable,
                $interval->lowerBound,
                $parameters,
                $evaluationOptions,
            );
            $dominated = false;
            foreach ($intervals as $neighbor) {
                if ($neighbor->lowerBound === $neighbor->upperBound) {
                    continue;
                }
                if ($neighbor->contains($interval->lowerBound)) {
                    $dominated = true;
                    break;
                }
                $boundary = \abs($interval->lowerBound - $neighbor->lowerBound)
                    <= \abs($interval->lowerBound - $neighbor->upperBound)
                    ? $neighbor->lowerBound
                    : $neighbor->upperBound;
                if (
                    \abs($interval->lowerBound - $boundary)
                        > $solverOptions->positionTolerance($interval->lowerBound, $boundary)
                    || !$neighbor->contains($boundary)
                ) {
                    continue;
                }

                $boundaryResidual = $this->residualAt(
                    $left,
                    $right,
                    $variable,
                    $boundary,
                    $parameters,
                    $evaluationOptions,
                );
                if (
                    $pointResidual !== null
                    && $boundaryResidual !== null
                    && \abs($boundaryResidual) < \abs($pointResidual)
                ) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $filtered[] = $interval;
            }
        }

        return $filtered;
    }

    /**
     * @param list<InequalityInterval> $intervals
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    private function deduplicateNearbyPointIntervals(
        array $intervals,
        string $left,
        string $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        \usort(
            $intervals,
            static fn (InequalityInterval $first, InequalityInterval $second): int =>
                ($first->lowerBound <=> $second->lowerBound)
                ?: ($first->upperBound <=> $second->upperBound),
        );
        $deduplicated = [];
        foreach ($intervals as $interval) {
            if ($interval->lowerBound !== $interval->upperBound) {
                $deduplicated[] = $interval;
                continue;
            }
            $duplicateIndex = null;
            foreach ($deduplicated as $index => $candidate) {
                if (
                    $candidate->lowerBound === $candidate->upperBound
                    && \abs($candidate->lowerBound - $interval->lowerBound)
                        <= $solverOptions->positionTolerance($candidate->lowerBound, $interval->lowerBound)
                ) {
                    $duplicateIndex = $index;
                    break;
                }
            }
            if ($duplicateIndex === null) {
                $deduplicated[] = $interval;
                continue;
            }

            $existing = $deduplicated[$duplicateIndex];
            $existingResidual = $this->equationResidualAt(
                $left,
                $right,
                $variable,
                $existing->lowerBound,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
            $candidateResidual = $this->equationResidualAt(
                $left,
                $right,
                $variable,
                $interval->lowerBound,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
            if (
                $candidateResidual !== null
                && ($existingResidual === null || \abs($candidateResidual) < \abs($existingResidual))
            ) {
                $deduplicated[$duplicateIndex] = $interval;
            }
        }

        return \array_values($deduplicated);
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function equationResidualAt(
        string $left,
        string $right,
        string $variable,
        float $value,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?float {
        if ($value < $lowerBound || $value > $upperBound) {
            return null;
        }
        $variables = [...$parameters, $variable => $value];
        try {
            $leftValue = Math::evaluate($left, $variables, $evaluationOptions);
            $rightValue = Math::evaluate($right, $variables, $evaluationOptions);
        } catch (EvaluationException) {
            return null;
        }
        $residual = (float) $leftValue - (float) $rightValue;

        return \is_finite($residual) ? $residual : null;
    }

    /**
     * @param list<InequalityInterval> $intervals
     * @return list<InequalityInterval>
     */
    private function merge(array $intervals): array
    {
        $merged = [];
        foreach ($intervals as $interval) {
            $lastIndex = \count($merged) - 1;
            if ($lastIndex < 0) {
                $merged[] = $interval;
                continue;
            }

            $previous = $merged[$lastIndex];
            $overlap = $interval->lowerBound < $previous->upperBound;
            $touchesIncludedPoint = $interval->lowerBound === $previous->upperBound
                && ($interval->includesLowerBound || $previous->includesUpperBound);
            if (!$overlap && !$touchesIncludedPoint) {
                $merged[] = $interval;
                continue;
            }

            if ($interval->upperBound > $previous->upperBound) {
                $merged[$lastIndex] = new InequalityInterval(
                    $previous->lowerBound,
                    $interval->upperBound,
                    $previous->includesLowerBound,
                    $interval->includesUpperBound,
                );
            } elseif ($interval->upperBound === $previous->upperBound) {
                $merged[$lastIndex] = new InequalityInterval(
                    $previous->lowerBound,
                    $previous->upperBound,
                    $previous->includesLowerBound,
                    $previous->includesUpperBound || $interval->includesUpperBound,
                );
            }
        }

        return \array_values($merged);
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function residualAt(
        string $left,
        string $right,
        string $variable,
        float $position,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?float {
        try {
            $variables = $parameters;
            $variables[$variable] = $position;
            $leftValue = Math::evaluate($left, $variables, $evaluationOptions);
            $rightValue = Math::evaluate($right, $variables, $evaluationOptions);
            $residual = (float) $leftValue - (float) $rightValue;

            return \is_finite($residual) ? $residual : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
