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
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Tracing\EvaluationObserver;

final readonly class Evaluator
{
    private NumericOperations $numbers;

    public function __construct(
        private Environment $environment,
        ResourceLimits $limits,
        private ?EvaluationObserver $observer = null,
    ) {
        $this->numbers = new NumericOperations($limits);
    }

    public function evaluate(Node $node): int|float
    {
        return $this->evaluateNode($node, 0);
    }

    private function evaluateNode(Node $node, int $depth): int|float
    {
        $result = match (true) {
            $node instanceof NumberNode => $this->numbers->ensureFinite(
                $node->value,
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
    ): int|float
    {
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
    ): int|float
    {
        $left = $this->evaluateNode($node->left, $depth + 1);
        $right = $this->evaluateNode($node->right, $depth + 1);

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
            ),
        };
    }

    private function evaluateFunction(
        FunctionCallNode $node,
        int $depth,
    ): int|float
    {
        $definition = $this->environment->functions->find($node->name);

        if ($definition === null) {
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

        $arguments = [];
        foreach ($node->arguments as $argument) {
            $arguments[] = $this->evaluateNode($argument, $depth + 1);
        }

        try {
            $result = $definition->invoke($arguments);
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
}
