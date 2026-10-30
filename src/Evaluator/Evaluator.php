<?php

declare(strict_types=1);

namespace MathPHP\Evaluator;

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
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\FunctionDomainException;
use MathPHP\Tracing\EvaluationObserver;

final readonly class Evaluator
{
    private NumericOperations $numbers;

    public function __construct(
        private Environment $environment,
        private ResourceLimits $limits,
        private ?EvaluationObserver $observer = null,
        float $factorialRoundingUlps = 4.0,
        float $moduloRoundingUlps = 0.0,
    ) {
        $this->numbers = new NumericOperations(
            $limits,
            $factorialRoundingUlps,
            $moduloRoundingUlps,
        );
    }

    public function evaluate(Node $node): int|float
    {
        return $this->evaluateNode($node, 0);
    }

    private function evaluateNode(Node $node, int $depth): int|float
    {
        $result = match (true) {
            $node instanceof NumberNode => $node->hasFiniteApproximation
                ? $this->numbers->ensureFinite($node->value, $node->span)
                : throw new EvaluationException(
                    'Numeric literal exceeds the finite floating-point range',
                    'eval.non_finite',
                    $node->span,
                ),
            $node instanceof ConstantNode => $this->numbers->ensureFinite(
                $this->environment->constant($node->name),
                $node->span,
            ),
            $node instanceof VariableNode => $this->numbers->ensureFinite(
                $this->environment->variable($node->name, $node->span),
                $node->span,
            ),
            $node instanceof GroupingNode => $this->evaluateNode(
                $node->expression,
                $depth + 1,
            ),
            $node instanceof UnaryOperationNode => $this->evaluateUnary(
                $node,
                $depth,
            ),
            $node instanceof BinaryOperationNode => $this->evaluateBinary(
                $node,
                $depth,
            ),
            $node instanceof FactorialNode => $this->numbers->factorial(
                $this->evaluateNode($node->operand, $depth + 1),
                $node->operatorSpan,
            ),
            $node instanceof FunctionCallNode => $this->evaluateFunction(
                $node,
                $depth,
            ),
            default => throw new \LogicException(
                \sprintf('Unsupported AST node %s.', $node::class),
            ),
        };

        $result = $this->numbers->ensureFinite($result, $node->span());
        $this->observer?->evaluated($node, $result, $depth);

        return $result;
    }

    private function evaluateUnary(
        UnaryOperationNode $node,
        int $depth,
    ): int|float {
        $operand = $this->evaluateNode($node->operand, $depth + 1);

        return match ($node->operator) {
            UnaryOperator::Plus => $this->numbers->positive(
                $operand,
                $node->operatorSpan,
            ),
            UnaryOperator::Minus => $this->numbers->negate(
                $operand,
                $node->operatorSpan,
            ),
        };
    }

    private function evaluateBinary(
        BinaryOperationNode $node,
        int $depth,
    ): int|float {
        if (
            $this->observer === null
            && ($additiveCancellation = $this->additiveCancellationValue($node, $depth)) !== null
        ) {
            return $this->numbers->ensureFinite($additiveCancellation, $node->operatorSpan);
        }

        if (
            $this->observer === null
            && ($nestedDifference = $this->nestedDifferenceValue($node, $depth)) !== null
        ) {
            return $this->numbers->ensureFinite($nestedDifference, $node->operatorSpan);
        }

        if (
            $this->observer === null
            && $node->operator === BinaryOperator::Subtract
            && ($stableDifference = $this->stableSubtractionValue($node, $depth)) !== null
        ) {
            return $this->numbers->ensureFinite($stableDifference, $node->operatorSpan);
        }

        try {
            $left = $this->evaluateNode($node->left, $depth + 1);
            $right = $this->evaluateNode($node->right, $depth + 1);
        } catch (EvaluationException $exception) {
            if (
                $this->observer === null
                && $exception->errorCode() === 'eval.non_finite'
            ) {
                $scaledValue = $this->scaledBinaryValue($node);
                if ($scaledValue !== null) {
                    return $scaledValue;
                }
            }

            throw $exception;
        }

        if ($node->operator === BinaryOperator::Divide && $right == 0.0) {
            $scaledQuotient = $this->scaledDivisionValue($node);
            if ($scaledQuotient !== null) {
                return $scaledQuotient;
            }
        }

        if (
            $node->operator === BinaryOperator::Power
            && $left == 0.0
            && $right == 0.0
            && ($scaledExponent = $this->scaledLogMagnitude($node->right)) !== null
            && !$scaledExponent[2]
        ) {
            if ($scaledExponent[0] < 0) {
                throw new EvaluationException(
                    'Zero cannot be raised to a negative exponent',
                    'eval.division_by_zero',
                    $node->operatorSpan,
                );
            }

            return 0.0;
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->numbers->add(
                $left,
                $right,
                $node->operatorSpan,
            ),
            BinaryOperator::Subtract => $this->numbers->subtract(
                $left,
                $right,
                $node->operatorSpan,
            ),
            BinaryOperator::Multiply => $this->numbers->multiply(
                $left,
                $right,
                $node->operatorSpan,
            ),
            BinaryOperator::Divide => $this->numbers->divide(
                $left,
                $right,
                $node->operatorSpan,
            ),
            BinaryOperator::Modulo => $this->numbers->modulo(
                $left,
                $right,
                $node->operatorSpan,
            ),
            BinaryOperator::Power => $this->numbers->power(
                $left,
                $right,
                $node->operatorSpan,
                RationalLiteral::reducedParity($node->right),
            ),
        };
    }

    private function stableSubtractionValue(BinaryOperationNode $node, int $depth): ?float
    {
        $left = $this->unwrapGrouping($node->left);
        $right = $this->unwrapGrouping($node->right);
        $smallDifference = $this->stableSmallFunctionDifferenceValue($node, $depth);
        if ($smallDifference !== null) {
            return $smallDifference;
        }

        foreach ([[$left, $right, 1.0], [$right, $left, -1.0]] as [$functionNode, $unitNode, $orientation]) {
            if (
                !$functionNode instanceof FunctionCallNode
                || \count($functionNode->arguments) !== 1
                || !$this->environment->functions->isBuiltIn($functionNode->name)
                || $this->signedUnitValue($unitNode) !== 1.0
            ) {
                continue;
            }
            $argument = $functionNode->arguments[0];
            try {
                $value = (float) $this->evaluateNode($argument, $depth + 1);
            } catch (EvaluationException) {
                return null;
            }

            if ($functionNode->name === 'cosh') {
                return $orientation * (2.0 * (\sinh($value / 2.0) ** 2));
            }
            if ($functionNode->name === 'cos') {
                return -$orientation * (2.0 * (\sin($value / 2.0) ** 2));
            }
            if ($functionNode->name === 'exp') {
                return $orientation * \expm1($value);
            }
        }

        $erfDifference = $this->stableErfLinearDifference($left, $right, $depth);

        return $erfDifference;
    }

    private function additiveCancellationValue(
        BinaryOperationNode $node,
        int $depth,
    ): int|float|null {
        if (
            $node->operator !== BinaryOperator::Subtract
            || $this->containsFunctionCall($node)
        ) {
            return null;
        }

        $terms = [];
        $this->collectAdditiveTerms($node->left, 1, $terms);
        $this->collectAdditiveTerms($node->right, -1, $terms);
        $cancelled = [];
        $usedNegativeTerms = [];
        $foundCancellation = false;

        foreach ($terms as $positiveIndex => [$positiveNode, $positiveSign]) {
            if ($positiveSign < 0 || isset($cancelled[$positiveIndex])) {
                continue;
            }
            foreach ($terms as $negativeIndex => [$negativeNode, $negativeSign]) {
                if (
                    $negativeSign >= 0
                    || isset($usedNegativeTerms[$negativeIndex])
                    || !$this->nodesAreEquivalent($positiveNode, $negativeNode)
                ) {
                    continue;
                }

                $cancelled[$positiveIndex] = true;
                $cancelled[$negativeIndex] = true;
                $usedNegativeTerms[$negativeIndex] = true;
                $foundCancellation = true;
                break;
            }
        }

        if (!$foundCancellation) {
            return null;
        }

        try {
            $this->evaluateAdditiveTree($node, $depth);
        } catch (EvaluationException $exception) {
            if ($exception->errorCode() !== 'eval.non_finite') {
                throw $exception;
            }
        }

        $result = null;
        foreach ($terms as $index => [$term, $sign]) {
            try {
                $value = $this->evaluateNode($term, $depth + 1);
            } catch (EvaluationException $exception) {
                if (isset($cancelled[$index]) && $exception->errorCode() === 'eval.non_finite') {
                    continue;
                }
                if ($exception->errorCode() === 'eval.non_finite') {
                    return null;
                }

                throw $exception;
            }

            if (isset($cancelled[$index])) {
                continue;
            }

            if ($sign < 0) {
                $value = $this->numbers->negate($value, $node->operatorSpan);
            }
            try {
                $result = $result === null
                    ? $value
                    : $this->numbers->add($result, $value, $node->operatorSpan);
            } catch (EvaluationException $exception) {
                if ($exception->errorCode() === 'eval.non_finite') {
                    return null;
                }

                throw $exception;
            }
        }

        if ($result !== null) {
            return $result;
        }

        foreach ($terms as [$term]) {
            if (!$this->isIntegerLiteralTree($term)) {
                return 0.0;
            }
        }

        return 0;
    }

    private function isIntegerLiteralTree(Node $node): bool
    {
        $node = $this->unwrapGrouping($node);
        if ($node instanceof NumberNode) {
            return \is_int($node->value);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isIntegerLiteralTree($node->operand);
        }

        return false;
    }

    private function evaluateAdditiveTree(Node $node, int $depth): int|float
    {
        $node = $this->unwrapGrouping($node);
        if (
            !$node instanceof BinaryOperationNode
            || !\in_array($node->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
        ) {
            return $this->evaluateNode($node, $depth);
        }

        $left = $this->evaluateAdditiveTree($node->left, $depth + 1);
        $right = $this->evaluateAdditiveTree($node->right, $depth + 1);

        return $node->operator === BinaryOperator::Add
            ? $this->numbers->add($left, $right, $node->operatorSpan)
            : $this->numbers->subtract($left, $right, $node->operatorSpan);
    }

    /** @param list<array{Node, int}> $terms */
    private function collectAdditiveTerms(Node $node, int $sign, array &$terms): void
    {
        $node = $this->unwrapGrouping($node);
        if (
            $node instanceof BinaryOperationNode
            && \in_array($node->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
        ) {
            $this->collectAdditiveTerms($node->left, $sign, $terms);
            $this->collectAdditiveTerms(
                $node->right,
                $node->operator === BinaryOperator::Subtract ? -$sign : $sign,
                $terms,
            );

            return;
        }

        $terms[] = [$node, $sign];
    }

    private function nestedDifferenceValue(
        BinaryOperationNode $node,
        int $depth,
    ): int|float|null {
        if ($node->operator !== BinaryOperator::Subtract) {
            return null;
        }

        $left = $this->unwrapGrouping($node->left);
        $right = $this->unwrapGrouping($node->right);
        $common = null;
        $residual = null;
        $orientation = 1;

        if (
            $right instanceof BinaryOperationNode
            && \in_array($right->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
            && $this->nodesAreEquivalent($left, $right->left)
        ) {
            $common = $left;
            $residual = $right->right;
            $orientation = $right->operator === BinaryOperator::Add ? -1 : 1;
        } elseif (
            $left instanceof BinaryOperationNode
            && \in_array($left->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
            && $this->nodesAreEquivalent($left->left, $right)
        ) {
            $common = $right;
            $residual = $left->right;
            $orientation = $left->operator === BinaryOperator::Add ? 1 : -1;
        }

        if (
            $common === null
            || $this->containsFunctionCall($common)
        ) {
            return null;
        }

        try {
            $this->evaluateNode($common, $depth + 1);
        } catch (EvaluationException $exception) {
            if ($exception->errorCode() !== 'eval.non_finite') {
                throw $exception;
            }
        }

        $value = $this->evaluateNode($residual, $depth + 1);

        return $orientation > 0
            ? $value
            : $this->numbers->negate($value, $node->operatorSpan);
    }

    private function stableSmallFunctionDifferenceValue(
        BinaryOperationNode $node,
        int $depth,
    ): ?float {
        $left = $this->unwrapGrouping($node->left);
        $right = $this->unwrapGrouping($node->right);
        $call = null;
        $argument = null;
        $orientation = 1.0;
        if (
            $left instanceof FunctionCallNode
            && \count($left->arguments) === 1
            && $this->nodesAreEquivalent($left->arguments[0], $right)
        ) {
            $call = $left;
            $argument = $left->arguments[0];
        } elseif (
            $right instanceof FunctionCallNode
            && \count($right->arguments) === 1
            && $this->nodesAreEquivalent($right->arguments[0], $left)
        ) {
            $call = $right;
            $argument = $right->arguments[0];
            $orientation = -1.0;
        }
        if (
            $call === null
            || !$this->environment->functions->isBuiltIn($call->name)
        ) {
            return null;
        }

        $coefficients = match ($call->name) {
            'sin' => [-1.0 / 6.0, 1.0 / 120.0, -1.0 / 5040.0, 1.0 / 362880.0, -1.0 / 39916800.0, 1.0 / 6227020800.0],
            'tan' => [1.0 / 3.0, 2.0 / 15.0, 17.0 / 315.0, 62.0 / 2835.0, 1382.0 / 155925.0, 21844.0 / 6081075.0],
            'sinh' => [1.0 / 6.0, 1.0 / 120.0, 1.0 / 5040.0, 1.0 / 362880.0, 1.0 / 39916800.0, 1.0 / 6227020800.0],
            'tanh' => [-1.0 / 3.0, 2.0 / 15.0, -17.0 / 315.0, 62.0 / 2835.0, -1382.0 / 155925.0, 21844.0 / 6081075.0],
            'asin' => [1.0 / 6.0, 3.0 / 40.0, 5.0 / 112.0, 35.0 / 1152.0, 63.0 / 2816.0, 231.0 / 13312.0],
            'asinh' => [-1.0 / 6.0, 3.0 / 40.0, -5.0 / 112.0, 35.0 / 1152.0, -63.0 / 2816.0, 231.0 / 13312.0],
            'atan' => [-1.0 / 3.0, 1.0 / 5.0, -1.0 / 7.0, 1.0 / 9.0, -1.0 / 11.0, 1.0 / 13.0],
            'atanh' => [1.0 / 3.0, 1.0 / 5.0, 1.0 / 7.0, 1.0 / 9.0, 1.0 / 11.0, 1.0 / 13.0],
            default => null,
        };
        if ($coefficients === null) {
            return null;
        }

        try {
            $value = (float) $this->evaluateNode($argument, $depth + 1);
        } catch (EvaluationException) {
            return null;
        }
        if (\abs($value) >= 1.0E-3) {
            return null;
        }

        $square = $value * $value;
        $polynomial = $coefficients[\count($coefficients) - 1];
        for ($index = \count($coefficients) - 2; $index >= 0; --$index) {
            $polynomial = $coefficients[$index] + ($square * $polynomial);
        }

        return $orientation * (($value * $square) * $polynomial);
    }

    private function stableErfLinearDifference(Node $left, Node $right, int $depth): ?float
    {
        $orientation = 1.0;
        $call = $left;
        $linear = $right;
        if (
            !$call instanceof FunctionCallNode
            || $call->name !== 'erf'
            || \count($call->arguments) !== 1
        ) {
            $call = $right;
            $linear = $left;
            $orientation = -1.0;
        }
        if (
            !$call instanceof FunctionCallNode
            || $call->name !== 'erf'
            || \count($call->arguments) !== 1
            || !$this->environment->functions->isBuiltIn('erf')
        ) {
            return null;
        }

        $argument = $call->arguments[0];
        $coefficient = $this->linearCoefficientForArgument($linear, $argument, $depth);
        if ($coefficient !== (2.0 / \sqrt(\M_PI))) {
            return null;
        }
        try {
            $value = (float) $this->evaluateNode($argument, $depth + 1);
        } catch (EvaluationException) {
            return null;
        }
        if (\abs($value) >= 1.0E-3) {
            return null;
        }

        $square = $value * $value;
        $term = $value;
        $sum = 0.0;
        for ($index = 0; $index < 6; ++$index) {
            $term *= -$square * ((2.0 * $index + 1.0) / (($index + 1.0) * (2.0 * $index + 3.0)));
            $sum += $term;
        }

        return $orientation * ((2.0 / \sqrt(\M_PI)) * $sum);
    }

    private function linearCoefficientForArgument(Node $linear, Node $argument, int $depth): ?float
    {
        $linear = $this->unwrapGrouping($linear);
        if (!$linear instanceof BinaryOperationNode || $linear->operator !== BinaryOperator::Multiply) {
            return null;
        }

        $factors = [[$linear->left, $linear->right], [$linear->right, $linear->left]];
        foreach ($factors as [$factor, $coefficientNode]) {
            if (!$this->nodesAreEquivalent($factor, $argument)) {
                continue;
            }
            try {
                return (float) $this->evaluateNode($coefficientNode, $depth + 1);
            } catch (EvaluationException) {
                return null;
            }
        }

        return null;
    }

    private function evaluateFunction(
        FunctionCallNode $node,
        int $depth,
    ): int|float {
        if (
            $this->observer === null
            && $node->name === 'log'
            && $this->environment->functions->isBuiltIn('log')
            && \count($node->arguments) === 2
            && ($valueDelta = $this->unitPlusOffsetNode($node->arguments[0])) !== null
            && ($baseDelta = $this->unitPlusOffsetNode($node->arguments[1])) !== null
        ) {
            $valueOffset = (float) $this->evaluateNode($valueDelta, $depth + 1);
            $baseOffset = (float) $this->evaluateNode($baseDelta, $depth + 1);
            if ($valueOffset <= -1.0 || $baseOffset <= -1.0 || $baseOffset === 0.0) {
                throw new EvaluationException(
                    'log() requires a positive value and a positive base other than one.',
                    'eval.domain',
                    $node->span,
                );
            }

            return $this->numbers->divide(
                \log1p($valueOffset),
                \log1p($baseOffset),
                $node->span,
            );
        }
        if (
            $this->observer === null
            && $this->environment->functions->isBuiltIn($node->name)
            && \count($node->arguments) === 1
            && ($stableInverse = $this->stableInverseUnitOffset(
                $node->name,
                $node->arguments[0],
                $depth,
            )) !== null
        ) {
            return $this->numbers->ensureFinite($stableInverse, $node->span);
        }

        if ($node->name === 'doublefactorial' && \count($node->arguments) === 1) {
            return $this->numbers->doubleFactorial(
                $this->evaluateNode($node->arguments[0], $depth + 1),
                $node->nameSpan,
            );
        }

        $definition = $this->environment->functions->find($node->name);

        if ($definition === null) {
            if (\count($node->arguments) === 1 && $this->environment->hasVariable($node->name)) {
                return $this->numbers->multiply(
                    $this->environment->variable($node->name, $node->nameSpan),
                    $this->evaluateNode($node->arguments[0], $depth + 1),
                    $node->span,
                );
            }

            throw new EvaluationException(
                \sprintf('Unknown function "%s"', $node->name),
                'eval.unknown_function',
                $node->nameSpan,
            );
        }

        $isBuiltIn = $this->environment->functions->isBuiltIn($node->name);
        $argumentCount = \count($node->arguments);
        if (
            $argumentCount < $definition->minArguments
            || $argumentCount > $definition->maxArguments
        ) {
            throw new EvaluationException(
                \sprintf(
                    'Function "%s" expects %s but received %d',
                    $node->name,
                    $definition->arityDescription(),
                    $argumentCount,
                ),
                'eval.arity',
                $node->span,
            );
        }

        $stableAtan2Value = null;
        if (
            $node->name === 'atan2'
            && $isBuiltIn
            && $this->observer === null
            && \count($node->arguments) === 2
        ) {
            $stableAtan2Value = $this->scaledAtan2Value(
                $node->arguments[0],
                $node->arguments[1],
            );
        }

        $arguments = [];
        try {
            foreach ($node->arguments as $argument) {
                $arguments[] = $this->evaluateNode($argument, $depth + 1);
            }
        } catch (EvaluationException $error) {
            if (
                $stableAtan2Value !== null
                && $this->observer === null
                && \in_array(
                    $error->errorCode(),
                    ['eval.domain', 'eval.integer_overflow', 'eval.non_finite'],
                    true,
                )
            ) {
                return $this->numbers->ensureFinite($stableAtan2Value, $node->span);
            }

            throw $error;
        }

        if (
            $stableAtan2Value === null
            && $node->name === 'atan2'
            && $isBuiltIn
            && \count($node->arguments) === 2
            && ($arguments[0] == 0.0 || $arguments[1] == 0.0)
        ) {
            $stableAtan2Value = $this->scaledAtan2Value(
                $node->arguments[0],
                $node->arguments[1],
            );
        }

        if (
            $stableAtan2Value !== null
            && ($arguments[0] == 0.0 || $arguments[1] == 0.0)
        ) {
            return $this->numbers->ensureFinite($stableAtan2Value, $node->span);
        }

        try {
            $result = $definition->invoke($arguments);
        } catch (FunctionDomainException $error) {
            throw new EvaluationException(
                $error->getMessage(),
                'eval.custom_function_domain',
                $node->span,
                $error,
            );
        } catch (\DomainException $error) {
            if (!$isBuiltIn) {
                throw $this->customFunctionException($node, $error);
            }

            throw new EvaluationException(
                $error->getMessage(),
                'eval.domain',
                $node->span,
                $error,
            );
        } catch (\OverflowException $error) {
            if (!$isBuiltIn) {
                throw $this->customFunctionException($node, $error);
            }

            throw new EvaluationException(
                $error->getMessage(),
                'eval.integer_overflow',
                $node->span,
                $error,
            );
        } catch (\Throwable $error) {
            throw $this->customFunctionException($node, $error);
        }

        try {
            return $this->numbers->ensureFinite($result, $node->span);
        } catch (EvaluationException $error) {
            if (
                !$isBuiltIn
                && $error->errorCode() === 'eval.non_finite'
            ) {
                throw $this->customFunctionException($node, $error);
            }

            throw $error;
        }
    }

    private function stableInverseUnitOffset(string $function, Node $argument, int $depth): ?float
    {
        while ($argument instanceof GroupingNode) {
            $argument = $argument->expression;
        }
        if (!$argument instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->unwrapGrouping($argument->left);
        $right = $this->unwrapGrouping($argument->right);
        $unit = $left;
        $offset = $right;
        $unitValue = $this->signedUnitValue($unit);
        if ($unitValue === null && $argument->operator === BinaryOperator::Add) {
            $unit = $right;
            $offset = $left;
            $unitValue = $this->signedUnitValue($unit);
        }
        if ($unitValue === null) {
            return null;
        }

        $subtractFromUnit = $argument->operator === BinaryOperator::Subtract;
        if (
            ($argument->operator !== BinaryOperator::Add && !$subtractFromUnit)
            || ($unitValue < 0.0 && !$subtractFromUnit && $function !== 'asin' && $function !== 'acos')
            || ($unitValue > 0.0 && $subtractFromUnit && !\in_array($function, ['asin', 'acos', 'asech'], true))
        ) {
            return null;
        }

        try {
            $offsetValue = (float) $this->evaluateNode($offset, $depth + 1);
        } catch (EvaluationException) {
            return null;
        }
        if (!\is_finite($offsetValue) || $offsetValue <= 0.0) {
            return null;
        }

        $smallAngle = null;
        if ($offsetValue <= 2.0) {
            $smallAngle = 2.0 * \asin(\sqrt($offsetValue / 2.0));
        }
        $smallAsecAngle = 2.0 * \atan(\sqrt($offsetValue / (2.0 + $offsetValue)));

        return match ($function) {
            'acoth' => $this->stableAcothOffsetValue($unitValue, $subtractFromUnit, $offsetValue),
            'acosh' => $unitValue === 1.0 && !$subtractFromUnit
                ? 2.0 * \asinh(\sqrt($offsetValue / 2.0))
                : null,
            'acos' => $smallAngle === null
                ? null
                : ($unitValue > 0.0 && $subtractFromUnit
                    ? $smallAngle
                    : ($unitValue < 0.0 && !$subtractFromUnit ? \M_PI - $smallAngle : null)),
            'asin' => $smallAngle === null
                ? null
                : ($unitValue > 0.0 && $subtractFromUnit
                    ? (\M_PI / 2.0) - $smallAngle
                    : ($unitValue < 0.0 && !$subtractFromUnit ? (-\M_PI / 2.0) + $smallAngle : null)),
            'asech' => $unitValue === 1.0 && $subtractFromUnit && $offsetValue < 1.0
                ? 2.0 * \asinh(\sqrt($offsetValue / (2.0 * (1.0 - $offsetValue))))
                : null,
            'asec' => $unitValue > 0.0 && !$subtractFromUnit
                ? $smallAsecAngle
                : ($unitValue < 0.0 && $subtractFromUnit ? \M_PI - $smallAsecAngle : null),
            'acsc' => $unitValue > 0.0 && !$subtractFromUnit
                ? (\M_PI / 2.0) - $smallAsecAngle
                : ($unitValue < 0.0 && $subtractFromUnit ? (-\M_PI / 2.0) + $smallAsecAngle : null),
            default => null,
        };
    }

    private function stableAcothOffsetValue(float $unit, bool $subtractFromUnit, float $offset): ?float
    {
        if (($unit === 1.0 && $subtractFromUnit) || ($unit === -1.0 && !$subtractFromUnit)) {
            return null;
        }

        return ($unit < 0.0 ? -1.0 : 1.0)
            * (0.5 * (\log(2.0 + $offset) - \log($offset)));
    }

    private function signedUnitValue(Node $node): ?float
    {
        $node = $this->unwrapGrouping($node);
        $sign = 1.0;
        if ($node instanceof UnaryOperationNode && $node->operator === UnaryOperator::Minus) {
            $node = $this->unwrapGrouping($node->operand);
            $sign = -1.0;
        }

        return $node instanceof NumberNode && $node->value == 1.0 ? $sign : null;
    }

    private function unitPlusOffsetNode(Node $node): ?Node
    {
        $node = $this->unwrapGrouping($node);
        if (!$node instanceof BinaryOperationNode || $node->operator !== BinaryOperator::Add) {
            return null;
        }
        if ($this->signedUnitValue($node->left) === 1.0) {
            return $node->right;
        }
        if ($this->signedUnitValue($node->right) === 1.0) {
            return $node->left;
        }

        return null;
    }

    private function customFunctionException(
        FunctionCallNode $node,
        \Throwable $previous,
    ): EvaluationException {
        return new EvaluationException(
            \sprintf('Custom function "%s" failed', $node->name),
            'eval.custom_function',
            $node->span,
            $previous,
        );
    }

    private function scaledAtan2Value(Node $yNode, Node $xNode): ?float
    {
        $y = $this->scaledLogMagnitude($yNode);
        $x = $this->scaledLogMagnitude($xNode);
        if ($y === null || $x === null || ($y[2] && $x[2])) {
            return null;
        }

        $largestLog = \max(
            $y[2] ? -\INF : $y[1],
            $x[2] ? -\INF : $x[1],
        );
        $scaledY = $y[2]
            ? $y[0] * 0.0
            : $y[0] * \exp($y[1] - $largestLog);
        $scaledX = $x[2]
            ? $x[0] * 0.0
            : $x[0] * \exp($x[1] - $largestLog);

        return \atan2($scaledY, $scaledX);
    }

    /** @return array{int, float, bool}|null Sign, log magnitude, and zero flag. */
    private function scaledLogMagnitude(Node $node): ?array
    {
        if ($node instanceof FunctionCallNode) {
            if (!$this->environment->functions->isBuiltIn($node->name)) {
                return null;
            }

            if (\count($node->arguments) === 1 && \in_array($node->name, ['abs', 'sqrt', 'cbrt'], true)) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if ($argument === null) {
                    return null;
                }

                if ($node->name === 'abs') {
                    return [1, $argument[1], $argument[2]];
                }
                if ($node->name === 'sqrt') {
                    if ($argument[0] < 0 && !$argument[2]) {
                        $message = 'sqrt() requires a non-negative argument.';

                        throw new EvaluationException(
                            $message,
                            'eval.domain',
                            $node->span,
                            new \DomainException($message),
                        );
                    }

                    return $argument[2]
                        ? [1, 0.0, true]
                        : [1, $argument[1] / 2.0, false];
                }

                return $argument[2]
                    ? [$argument[0], 0.0, true]
                    : [$argument[0], $argument[1] / 3.0, false];
            }

            if (
                $node->name === 'exp'
                && \count($node->arguments) === 1
                && !$this->containsFunctionCall($node->arguments[0])
            ) {
                $exponent = (new self($this->environment, $this->limits))
                    ->evaluate($node->arguments[0]);

                return [1, (float) $exponent, false];
            }

            if (
                \count($node->arguments) === 1
                && \in_array(
                    $node->name,
                    ['sin', 'tan', 'sinh', 'tanh', 'asin', 'asinh', 'atan', 'atanh'],
                    true,
                )
            ) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if ($argument === null || (!$argument[2] && $argument[1] >= \log(1.0E-8))) {
                    return null;
                }

                return $argument;
            }

            if (
                \count($node->arguments) === 1
                && \in_array($node->name, ['csc', 'cot', 'csch', 'coth'], true)
            ) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if (
                    $argument === null
                    || $argument[2]
                    || $argument[1] >= \log(1.0E-8)
                ) {
                    return null;
                }

                return [$argument[0], -$argument[1], false];
            }

            if (
                \count($node->arguments) === 1
                && \in_array($node->name, ['sec', 'sinc', 'sincpi'], true)
            ) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if ($argument === null || (!$argument[2] && $argument[1] >= \log(1.0E-8))) {
                    return null;
                }

                return [1, 0.0, false];
            }

            if (
                \in_array($node->name, ['ln', 'log2', 'log10'], true)
                && \count($node->arguments) === 1
            ) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if ($argument === null) {
                    return null;
                }
                if ($argument[2] || $argument[0] < 0) {
                    $message = match ($node->name) {
                        'ln' => 'ln() requires a positive argument.',
                        'log2' => 'log2() requires a positive argument.',
                        default => 'log10() requires a positive argument.',
                    };

                    throw new EvaluationException(
                        $message,
                        'eval.domain',
                        $node->span,
                        new \DomainException($message),
                    );
                }

                $logarithm = match ($node->name) {
                    'ln' => $argument[1],
                    'log2' => $argument[1] / \log(2.0),
                    default => $argument[1] / \log(10.0),
                };

                return $this->logMagnitudeOf($logarithm);
            }

            if (
                $node->name === 'log'
                && \count($node->arguments) === 2
            ) {
                $value = $this->scaledLogMagnitude($node->arguments[0]);
                $base = $this->scaledLogMagnitude($node->arguments[1]);
                if ($value === null || $base === null) {
                    return null;
                }
                if ($value[2] || $value[0] < 0 || $base[2] || $base[0] < 0 || $base[1] === 0.0) {
                    $message = 'log() requires a positive value and a positive base other than one.';

                    throw new EvaluationException(
                        $message,
                        'eval.domain',
                        $node->span,
                        new \DomainException($message),
                    );
                }

                return $this->logMagnitudeOf($value[1] / $base[1]);
            }

            if (
                $node->name === 'root'
                && \count($node->arguments) === 2
                && !$this->containsFunctionCall($node->arguments[1])
            ) {
                $argument = $this->scaledLogMagnitude($node->arguments[0]);
                if ($argument === null) {
                    return null;
                }

                $degree = (float) (new self($this->environment, $this->limits))
                    ->evaluate($node->arguments[1]);
                $nearestDegree = \round($degree);
                $degreeTolerance = \min(
                    1.0E-9,
                    16.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($degree)),
                );
                if (\abs($degree - $nearestDegree) <= $degreeTolerance) {
                    $degree = $nearestDegree;
                }
                if (
                    $degree == 0.0
                    || \floor($degree) !== $degree
                    || \abs($degree) > 9_007_199_254_740_991.0
                ) {
                    return null;
                }

                $order = \abs($degree);
                if ($argument[0] < 0 && !$argument[2] && \fmod($order, 2.0) === 0.0) {
                    $message = 'root() requires an odd degree for negative values.';

                    throw new EvaluationException(
                        $message,
                        'eval.domain',
                        $node->span,
                        new \DomainException($message),
                    );
                }
                if ($argument[2] && $degree < 0.0) {
                    return null;
                }
                if ($argument[2]) {
                    return [1, 0.0, true];
                }

                return [
                    $argument[0],
                    ($degree < 0.0 ? -1.0 : 1.0) * ($argument[1] / $order),
                    false,
                ];
            }

            return null;
        }

        if ($node instanceof NumberNode) {
            return $this->logMagnitudeOfNumberNode($node);
        }

        if ($node instanceof ConstantNode) {
            return $this->logMagnitudeOf($this->environment->constant($node->name));
        }

        if ($node instanceof VariableNode) {
            return $this->logMagnitudeOf(
                $this->environment->variable($node->name, $node->span),
            );
        }

        if ($node instanceof GroupingNode) {
            return $this->scaledLogMagnitude($node->expression);
        }

        if ($node instanceof UnaryOperationNode) {
            $operand = $this->scaledLogMagnitude($node->operand);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            return [-$operand[0], $operand[1], $operand[2]];
        }

        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        if ($node->operator === BinaryOperator::Power) {
            if ($this->containsFunctionCall($node->right)) {
                return null;
            }
            $base = $this->scaledLogMagnitude($node->left);
            if ($base === null) {
                return null;
            }
            $exponent = (new self($this->environment, $this->limits))->evaluate($node->right);
            if (\abs((float) $exponent) > $this->limits->maxExponentMagnitude) {
                return null;
            }
            if ($base[2] && $exponent <= 0.0) {
                return null;
            }
            $negative = false;
            if ($base[0] < 0) {
                $rationalExponentParity = RationalLiteral::reducedParity($node->right);
                if ($rationalExponentParity !== null) {
                    if (!$rationalExponentParity[1]) {
                        return null;
                    }
                    $negative = $rationalExponentParity[0];
                } else {
                    if ($exponent != \floor($exponent)) {
                        return null;
                    }
                    $negative = \fmod(\abs((float) $exponent), 2.0) !== 0.0;
                }
            }

            $sign = $negative ? -1 : 1;
            if ($base[2]) {
                return [$sign, 0.0, true];
            }
            $logMagnitude = $base[1] * $exponent;
            if (!\is_finite($logMagnitude)) {
                return null;
            }

            return [$sign, $logMagnitude, false];
        }

        $left = $this->scaledLogMagnitude($node->left);
        $right = $this->scaledLogMagnitude($node->right);
        if ($left === null || $right === null) {
            return null;
        }

        if ($node->operator === BinaryOperator::Add || $node->operator === BinaryOperator::Subtract) {
            if ($node->operator === BinaryOperator::Subtract) {
                $smallDifference = $this->scaledSmallFunctionDifference($node);
                if ($smallDifference !== null) {
                    return $smallDifference;
                }
            }
            if ($node->operator === BinaryOperator::Subtract) {
                $right = [-$right[0], $right[1], $right[2]];
            }

            if (
                !$left[2]
                && !$right[2]
                && $left[0] !== $right[0]
                && (
                    $this->containsSmallArgumentApproximation($node->left)
                    || $this->containsSmallArgumentApproximation($node->right)
                )
            ) {
                return null;
            }

            return $this->addScaledLogValues(
                $left,
                $right,
                $node->operator === BinaryOperator::Subtract
                    && $this->nodesAreEquivalent($node->left, $node->right),
            );
        }

        if ($node->operator === BinaryOperator::Multiply) {
            $logMagnitude = $left[1] + $right[1];
            if (!$left[2] && !$right[2] && !\is_finite($logMagnitude)) {
                return null;
            }

            return [$left[0] * $right[0], $logMagnitude, $left[2] || $right[2]];
        }

        if ($node->operator === BinaryOperator::Divide) {
            if ($right[2]) {
                return null;
            }
            if ($left[2]) {
                return [$left[0] * $right[0], 0.0, true];
            }
            $logMagnitude = $left[1] - $right[1];
            if (!\is_finite($logMagnitude)) {
                return null;
            }

            return [$left[0] * $right[0], $logMagnitude, false];
        }

        return null;
    }

    /** @return array{int, float, bool}|null */
    private function scaledSmallFunctionDifference(Node $node): ?array
    {
        if (!$node instanceof BinaryOperationNode || $node->operator !== BinaryOperator::Subtract) {
            return null;
        }

        $left = $this->unwrapGrouping($node->left);
        $right = $this->unwrapGrouping($node->right);
        $function = null;
        $argument = null;
        $orientation = 1.0;
        if (
            $left instanceof FunctionCallNode
            && \count($left->arguments) === 1
            && $this->nodesAreEquivalent($left->arguments[0], $right)
        ) {
            $function = $left->name;
            $argument = $left->arguments[0];
        } elseif (
            $right instanceof FunctionCallNode
            && \count($right->arguments) === 1
            && $this->nodesAreEquivalent($right->arguments[0], $left)
        ) {
            $function = $right->name;
            $argument = $right->arguments[0];
            $orientation = -1.0;
        }
        if (
            $function === null
            || !$this->environment->functions->isBuiltIn($function)
        ) {
            return null;
        }

        $coefficient = match ($function) {
            'sin', 'asinh' => -1.0 / 6.0,
            'tan' => 1.0 / 3.0,
            'sinh', 'asin' => 1.0 / 6.0,
            'tanh', 'atan' => -1.0 / 3.0,
            'atanh' => 1.0 / 3.0,
            default => null,
        };
        if ($coefficient === null) {
            return null;
        }

        $value = $this->scaledLogMagnitude($argument);
        if ($value === null || (!$value[2] && $value[1] >= \log(1.0E-8))) {
            return null;
        }
        if ($value[2]) {
            return [1, 0.0, true];
        }

        $logMagnitude = (3.0 * $value[1]) + \log(\abs($coefficient));
        if (!\is_finite($logMagnitude)) {
            return null;
        }

        $sign = $value[0] * ($coefficient < 0.0 ? -1 : 1);

        return [$sign * (int) $orientation, $logMagnitude, false];
    }

    private function unwrapGrouping(Node $node): Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node;
    }

    private function nodesAreEquivalent(Node $left, Node $right): bool
    {
        $left = $this->unwrapGrouping($left);
        $right = $this->unwrapGrouping($right);
        if ($left::class !== $right::class) {
            return false;
        }

        if ($left instanceof NumberNode && $right instanceof NumberNode) {
            return $left->value === $right->value
                && $left->literal === $right->literal
                && $left->hasFiniteApproximation === $right->hasFiniteApproximation;
        }
        if ($left instanceof ConstantNode && $right instanceof ConstantNode) {
            return $left->name === $right->name;
        }
        if ($left instanceof VariableNode && $right instanceof VariableNode) {
            return $left->name === $right->name;
        }
        if ($left instanceof UnaryOperationNode && $right instanceof UnaryOperationNode) {
            return $left->operator === $right->operator
                && $this->nodesAreEquivalent($left->operand, $right->operand);
        }
        if ($left instanceof BinaryOperationNode && $right instanceof BinaryOperationNode) {
            return $left->operator === $right->operator
                && $this->nodesAreEquivalent($left->left, $right->left)
                && $this->nodesAreEquivalent($left->right, $right->right);
        }
        if ($left instanceof FunctionCallNode && $right instanceof FunctionCallNode) {
            if ($left->name !== $right->name || \count($left->arguments) !== \count($right->arguments)) {
                return false;
            }
            foreach ($left->arguments as $index => $argument) {
                if (!$this->nodesAreEquivalent($argument, $right->arguments[$index])) {
                    return false;
                }
            }

            return true;
        }
        if ($left instanceof FactorialNode && $right instanceof FactorialNode) {
            return $this->nodesAreEquivalent($left->operand, $right->operand);
        }

        return false;
    }

    /**
     * @param array{int, float, bool} $left
     * @param array{int, float, bool} $right
     * @return array{int, float, bool}
     */
    private function addScaledLogValues(array $left, array $right, bool $exactCancellation): ?array
    {
        if ($left[2] && $right[2]) {
            return [1, 0.0, true];
        }
        if ($left[2]) {
            return $right;
        }
        if ($right[2]) {
            return $left;
        }

        if ($left[0] === $right[0]) {
            $largestLog = \max($left[1], $right[1]);
            $smallestLog = \min($left[1], $right[1]);

            return [
                $left[0],
                $largestLog + \log1p(\exp($smallestLog - $largestLog)),
                false,
            ];
        }

        $logDifference = \abs($left[1] - $right[1]);
        if (
            $logDifference <= 8.0 * \PHP_FLOAT_EPSILON
                * \max(1.0, \abs($left[1]), \abs($right[1]))
        ) {
            return $exactCancellation ? [1, 0.0, true] : null;
        }

        $larger = $left[1] > $right[1] ? $left : $right;
        $smaller = $left[1] > $right[1] ? $right : $left;

        return [
            $larger[0],
            $larger[1] + \log(-\expm1($smaller[1] - $larger[1])),
            false,
        ];
    }

    private function scaledDivisionValue(BinaryOperationNode $node): ?float
    {
        $left = $this->scaledLogMagnitude($node->left);
        $right = $this->scaledLogMagnitude($node->right);
        if ($left === null || $right === null || $right[2]) {
            return null;
        }
        if ($left[2]) {
            return 0.0;
        }

        $logMagnitude = $left[1] - $right[1];
        if ($logMagnitude > \log(\PHP_FLOAT_MAX)) {
            throw new EvaluationException(
                'Operation produced a non-finite result',
                'eval.non_finite',
                $node->operatorSpan,
            );
        }

        $sign = $left[0] * $right[0];

        return $this->numbers->ensureFinite(
            $sign * \exp($logMagnitude),
            $node->operatorSpan,
        );
    }

    private function scaledBinaryValue(BinaryOperationNode $node): ?float
    {
        $scaled = $this->scaledLogMagnitude($node);
        if ($scaled === null) {
            return null;
        }
        if ($scaled[2]) {
            return 0.0;
        }
        if ($scaled[1] > \log(\PHP_FLOAT_MAX)) {
            throw new EvaluationException(
                'Operation produced a non-finite result',
                'eval.non_finite',
                $node->operatorSpan,
            );
        }

        return $this->numbers->ensureFinite(
            $scaled[0] * \exp($scaled[1]),
            $node->operatorSpan,
        );
    }

    /** @return array{int, float, bool} */
    private function logMagnitudeOf(int|float $value): array
    {
        $floatValue = (float) $value;
        if ($floatValue === 0.0) {
            $sign = \fdiv(1.0, $floatValue) < 0.0 ? -1 : 1;

            return [$sign, 0.0, true];
        }

        return [$floatValue < 0.0 ? -1 : 1, \log(\abs($floatValue)), false];
    }

    /** @return array{int, float, bool} */
    private function logMagnitudeOfNumberNode(NumberNode $node): array
    {
        if ($node->value != 0.0) {
            return $this->logMagnitudeOf($node->value);
        }

        $fraction = RationalLiteral::fromNodeAsStrings($node);
        if ($fraction === null || $fraction[0] === '0') {
            return $this->logMagnitudeOf($node->value);
        }

        $numerator = \ltrim($fraction[0], '-');

        return [
            $fraction[0][0] === '-' ? -1 : 1,
            $this->logMagnitudeOfIntegerString($numerator)
                - $this->logMagnitudeOfIntegerString($fraction[1]),
            false,
        ];
    }

    private function logMagnitudeOfIntegerString(string $value): float
    {
        $leadingDigits = \substr($value, 0, 16);

        return \log((float) $leadingDigits)
            + (\strlen($value) - \strlen($leadingDigits)) * \log(10.0);
    }

    private function containsFunctionCall(Node $node): bool
    {
        return match (true) {
            $node instanceof FunctionCallNode => true,
            $node instanceof GroupingNode => $this->containsFunctionCall($node->expression),
            $node instanceof UnaryOperationNode => $this->containsFunctionCall($node->operand),
            $node instanceof FactorialNode => $this->containsFunctionCall($node->operand),
            $node instanceof BinaryOperationNode => $this->containsFunctionCall($node->left)
                || $this->containsFunctionCall($node->right),
            default => false,
        };
    }

    private function containsSmallArgumentApproximation(Node $node): bool
    {
        return match (true) {
            $node instanceof FunctionCallNode => \in_array(
                $node->name,
                [
                    'sin', 'tan', 'sinh', 'tanh', 'asin', 'asinh', 'atan', 'atanh',
                    'csc', 'cot', 'csch', 'coth', 'sec', 'sinc', 'sincpi',
                ],
                true,
            ) || $this->containsAnyApproximationInArguments($node),
            $node instanceof GroupingNode => $this->containsSmallArgumentApproximation($node->expression),
            $node instanceof UnaryOperationNode => $this->containsSmallArgumentApproximation($node->operand),
            $node instanceof FactorialNode => $this->containsSmallArgumentApproximation($node->operand),
            $node instanceof BinaryOperationNode => $this->containsSmallArgumentApproximation($node->left)
                || $this->containsSmallArgumentApproximation($node->right),
            default => false,
        };
    }

    private function containsAnyApproximationInArguments(FunctionCallNode $node): bool
    {
        foreach ($node->arguments as $argument) {
            if ($this->containsSmallArgumentApproximation($argument)) {
                return true;
            }
        }

        return false;
    }
}
