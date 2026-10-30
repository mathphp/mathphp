<?php

declare(strict_types=1);

namespace MathPHP\Parser;

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
use MathPHP\Exception\ParseException;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Source\SourceSpan;

final class Parser
{
    private int $position = 0;

    private int $nesting = 0;

    private int $absoluteValueNesting = 0;

    private ResourceLimits $limits;

    private FunctionRegistry $functions;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(
        private readonly array $tokens,
        ?ResourceLimits $limits = null,
        ?FunctionRegistry $functions = null,
    ) {
        if ($tokens === []) {
            throw new \InvalidArgumentException('Parser requires an EOF-terminated token list.');
        }

        if ($tokens[\array_key_last($tokens)]->type !== TokenType::EndOfInput) {
            throw new \InvalidArgumentException('Parser token list must end with EOF.');
        }

        $this->limits = $limits ?? new ResourceLimits();
        $this->functions = $functions ?? FunctionRegistry::defaults();
    }

    public function parse(): Node
    {
        $this->position = 0;
        $this->nesting = 0;
        $this->absoluteValueNesting = 0;

        if ($this->check(TokenType::EndOfInput)) {
            throw new ParseException(
                'Expression is empty',
                'parse.empty_expression',
                $this->current()->span,
            );
        }

        $expression = $this->parseAdditive();

        if (!$this->check(TokenType::EndOfInput)) {
            throw new ParseException(
                \sprintf('Unexpected trailing token "%s"', $this->current()->lexeme),
                'parse.trailing_input',
                $this->current()->span,
            );
        }

        return $expression;
    }

    private function parseAdditive(): Node
    {
        $node = $this->parseMultiplicative();

        while ($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) {
            $operator = $this->advance();
            $right = $this->parseMultiplicative();
            $node = new BinaryOperationNode(
                $operator->type === TokenType::Plus
                    ? BinaryOperator::Add
                    : BinaryOperator::Subtract,
                $node,
                $right,
                $operator->span,
            );
            $this->guardDepth($node, $operator->span);
        }

        return $node;
    }

    private function parseMultiplicative(): Node
    {
        $node = $this->parsePrefix();

        while (
            $this->check(TokenType::Multiply)
            || $this->check(TokenType::Divide)
            || $this->check(TokenType::Modulo)
            || $this->startsImplicitMultiplication()
        ) {
            $implicit = $this->startsImplicitMultiplication();
            $operator = $implicit ? $this->current() : $this->advance();
            $right = $this->parsePrefix();
            $node = new BinaryOperationNode(
                $implicit
                    ? BinaryOperator::Multiply
                    : match ($operator->type) {
                    TokenType::Multiply => BinaryOperator::Multiply,
                    TokenType::Divide => BinaryOperator::Divide,
                    TokenType::Modulo => BinaryOperator::Modulo,
                    default => throw new \LogicException('Unexpected multiplicative operator.'),
                    },
                $node,
                $right,
                $operator->span,
            );
            $this->guardDepth($node, $operator->span);
        }

        return $node;
    }

    /**
     * Mathematical notation commonly omits the multiplication sign between
     * two factors (for example `2x`, `2(x + 1)`, or `(x + 1)(x - 1)`).
     *
     * Identifiers remain atomic: `xy` is one variable, while `x y` is
     * interpreted as `x * y`. Calls remain represented as call nodes; at
     * evaluation, a registered function wins over a same-named variable,
     * while a bound variable with no registered function multiplies its single
     * parenthesized argument.
     */
    private function startsImplicitMultiplication(): bool
    {
        return $this->check(TokenType::Number)
            || $this->check(TokenType::LeftParenthesis)
            || $this->check(TokenType::Identifier)
            || $this->startsIndexedRoot()
            || $this->check(TokenType::SquareRoot)
            || $this->check(TokenType::CubeRoot)
            || ($this->absoluteValueNesting === 0 && $this->check(TokenType::AbsoluteValueBar));
    }

    private function parsePrefix(int $reservedDepth = 0): Node
    {
        if ($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) {
            $operator = $this->current();
            $this->guardProspectiveDepth($reservedDepth, $operator->span);
            $this->advance();
            $operand = $this->parsePrefix($reservedDepth + 1);
            $node = new UnaryOperationNode(
                $operator->type === TokenType::Plus
                    ? UnaryOperator::Plus
                    : UnaryOperator::Minus,
                $operand,
                $operator->span,
            );
            $this->guardDepth($node, $operator->span);

            return $node;
        }

        if ($this->startsIndexedRoot()) {
            $index = $this->advance();
            $radical = $this->advance();
            $degree = (int) $index->lexeme;
            if ($degree === 0) {
                throw new ParseException(
                    'A radical index must be a nonzero integer',
                    'parse.invalid_root_degree',
                    $index->span,
                );
            }
            $this->guardProspectiveDepth($reservedDepth, $index->span);
            $radicand = $this->parsePrefix($reservedDepth + 1);
            $nameSpan = $index->span->cover($radical->span);
            $node = new FunctionCallNode(
                'root',
                [
                    $radicand,
                    new NumberNode($degree, $index->lexeme, $index->span),
                ],
                $nameSpan,
                $nameSpan->cover($radicand->span()),
            );
            $this->guardDepth($node, $index->span);

            return $node;
        }

        if ($this->check(TokenType::SquareRoot) || $this->check(TokenType::CubeRoot)) {
            $symbol = $this->advance();
            $this->guardProspectiveDepth($reservedDepth, $symbol->span);
            $operand = $this->parsePrefix($reservedDepth + 1);
            $node = new FunctionCallNode(
                $symbol->lexeme,
                [$operand],
                $symbol->span,
                new SourceSpan($symbol->span->start, $operand->span()->end),
            );
            $this->guardDepth($node, $symbol->span);

            return $node;
        }

        return $this->parsePower($reservedDepth);
    }

    private function parsePower(int $reservedDepth): Node
    {
        $base = $this->parsePostfix($reservedDepth);

        if (!$this->check(TokenType::Power) && !$this->check(TokenType::Superscript)) {
            return $base;
        }

        return $this->parsePowerFromBase($base, $reservedDepth);
    }

    private function parsePowerFromBase(Node $base, int $reservedDepth): Node
    {
        $operator = $this->current();
        $this->guardProspectiveDepth($reservedDepth, $operator->span);
        $this->advance();
        $exponent = $operator->type === TokenType::Superscript
            ? $this->superscriptNumber($operator)
            : $this->parsePrefix($reservedDepth + 1);
        if (
            $operator->type === TokenType::Superscript
            && ($this->check(TokenType::Power) || $this->check(TokenType::Superscript))
        ) {
            $negative = \str_starts_with($operator->lexeme, '-');
            if ($negative && $exponent instanceof NumberNode) {
                $magnitude = $exponent->value === \PHP_INT_MIN
                    ? (float) \PHP_INT_MAX + 1.0
                    : -$exponent->value;
                $exponent = new NumberNode($magnitude, (string) $magnitude, $operator->span);
            }
            $exponent = $this->parsePowerFromBase($exponent, $reservedDepth + 1);
            if ($negative) {
                $exponent = new UnaryOperationNode(UnaryOperator::Minus, $exponent, $operator->span);
                $this->guardDepth($exponent, $operator->span);
            }
        }
        $node = new BinaryOperationNode(BinaryOperator::Power, $base, $exponent, $operator->span);
        $this->guardDepth($node, $operator->span);

        return $node;
    }

    private function superscriptNumber(Token $token): Node
    {
        $value = (int) $token->lexeme;

        return new NumberNode($value, (string) $value, $token->span);
    }

    private function parsePostfix(int $reservedDepth): Node
    {
        $node = $this->parsePrimary($reservedDepth);

        while ($this->check(TokenType::Factorial)) {
            $operator = $this->advance();
            if ($this->check(TokenType::Factorial)) {
                $secondOperator = $this->advance();
                $node = new FunctionCallNode(
                    'doublefactorial',
                    [$node],
                    $operator->span,
                    $node->span()->cover($secondOperator->span),
                );
            } else {
                $node = new FactorialNode($node, $operator->span);
            }
            $this->guardDepth($node, $operator->span);
        }

        return $node;
    }

    private function parsePrimary(int $reservedDepth): Node
    {
        if ($this->check(TokenType::AbsoluteValueBar)) {
            $opening = $this->advance();
            $this->enterNesting($opening->span);
            ++$this->absoluteValueNesting;

            try {
                $expression = $this->parseAdditive();
                $closing = $this->consume(
                    TokenType::AbsoluteValueBar,
                    'Expected "|" after absolute-value expression',
                );
            } finally {
                --$this->absoluteValueNesting;
                --$this->nesting;
            }

            $node = new FunctionCallNode(
                'abs',
                [$expression],
                $opening->span,
                new SourceSpan($opening->span->start, $closing->span->end),
            );
            $this->guardDepth($node, $opening->span);

            return $node;
        }

        if ($this->check(TokenType::Number)) {
            $token = $this->advance();
            [$value, $hasFiniteApproximation] = $this->numberValue($token->lexeme);

            return new NumberNode(
                $value,
                $token->lexeme,
                $token->span,
                $hasFiniteApproximation,
            );
        }

        if ($this->check(TokenType::Superscript)) {
            return $this->superscriptNumber($this->advance());
        }

        if ($this->check(TokenType::Identifier)) {
            $identifier = $this->advance();

            if ($this->check(TokenType::LeftParenthesis)) {
                return $this->parseFunctionCall($identifier);
            }

            $functionName = $this->normalizedFunctionName($identifier->lexeme);
            $function = $this->functions->find($functionName);
            $functionPowerSuffix = $this->functionPowerSuffix();
            if (
                $function !== null
                && $functionName === 'sqrt'
                && $this->check(TokenType::Number)
            ) {
                throw new ParseException(
                    'Function calls require parentheses',
                    'parse.function_parentheses_required',
                    $identifier->span,
                );
            }
            if (
                $function !== null
                && $function->minArguments === 1
                && $function->maxArguments === 1
                && $functionPowerSuffix !== null
            ) {
                [$exponent, $powerSpan] = $this->consumeFunctionPowerExponent(
                    $functionPowerSuffix,
                );
                $this->guardProspectiveDepth($reservedDepth, $identifier->span);
                $argument = $this->parsePrefix($reservedDepth + 1);
                $inverseFunctionName = $this->isNegativeOne($exponent)
                    ? $this->inverseFunctionName($functionName)
                    : null;
                $callName = $inverseFunctionName ?? $functionName;
                $call = new FunctionCallNode(
                    $callName,
                    [$argument],
                    $identifier->span,
                    $identifier->span->cover($argument->span()),
                );
                if ($inverseFunctionName !== null) {
                    $this->guardDepth($call, $identifier->span);

                    return $call;
                }
                $node = new BinaryOperationNode(
                    BinaryOperator::Power,
                    $call,
                    $exponent,
                    $powerSpan,
                );
                $this->guardDepth($node, $identifier->span);

                return $node;
            }
            if (
                $function !== null
                && $function->minArguments === 1
                && $function->maxArguments === 1
                && $this->startsFunctionArgument()
            ) {
                $this->guardProspectiveDepth($reservedDepth, $identifier->span);
                $argument = $this->parsePrefix($reservedDepth + 1);
                $node = new FunctionCallNode(
                    $functionName,
                    [$argument],
                    $identifier->span,
                    $identifier->span->cover($argument->span()),
                );
                $this->guardDepth($node, $identifier->span);

                return $node;
            }
            if (
                \in_array(
                    $identifier->lexeme,
                    ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'],
                    true,
                )
            ) {
                return new ConstantNode($identifier->lexeme, $identifier->span);
            }

            return new VariableNode($identifier->lexeme, $identifier->span);
        }

        if ($this->check(TokenType::LeftParenthesis)) {
            $opening = $this->advance();
            $this->enterNesting($opening->span);

            try {
                $expression = $this->parseAdditive();
                $closing = $this->consume(
                    TokenType::RightParenthesis,
                    'Expected ")" after grouped expression',
                );
            } finally {
                --$this->nesting;
            }

            $node = new GroupingNode(
                $expression,
                new SourceSpan($opening->span->start, $closing->span->end),
            );
            $this->guardDepth($node, $opening->span);

            return $node;
        }

        throw new ParseException(
            $this->check(TokenType::EndOfInput)
                ? 'Expected expression before end of input'
                : \sprintf('Expected expression, found "%s"', $this->current()->lexeme),
            'parse.expected_expression',
            $this->current()->span,
        );
    }

    /** @return array{int|float, bool} */
    private function numberValue(string $literal): array
    {
        if (\strpbrk($literal, '.eE') !== false) {
            $value = (float) $literal;

            return \is_finite($value) ? [$value, true] : [0.0, false];
        }

        $normalized = \ltrim($literal, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) \PHP_INT_MAX;
        if (
            \strlen($normalized) < \strlen($maximum)
            || (
                \strlen($normalized) === \strlen($maximum)
                && \strcmp($normalized, $maximum) <= 0
            )
        ) {
            return [(int) $literal, true];
        }

        $value = (float) $literal;

        return \is_finite($value) ? [$value, true] : [0.0, false];
    }

    private function parseFunctionCall(Token $identifier): Node
    {
        $opening = $this->consume(
            TokenType::LeftParenthesis,
            'Expected "(" after function name',
        );
        $this->enterNesting($opening->span);
        $arguments = [];

        try {
            if (!$this->check(TokenType::RightParenthesis)) {
                while (true) {
                    if (\count($arguments) >= $this->limits->maxFunctionArguments) {
                        throw new ParseException(
                            'Function call exceeds the configured argument limit',
                            'limit.function_arguments',
                            $this->current()->span,
                        );
                    }

                    $arguments[] = $this->parseAdditive();

                    if (!$this->check(TokenType::Comma)) {
                        break;
                    }

                    $this->advance();
                }
            }

            $closing = $this->consume(
                TokenType::RightParenthesis,
                'Expected ")" after function arguments',
            );
        } finally {
            --$this->nesting;
        }

        $functionName = $this->normalizedFunctionName($identifier->lexeme);
        $node = new FunctionCallNode(
            $functionName,
            $arguments,
            $identifier->span,
            new SourceSpan($identifier->span->start, $closing->span->end),
        );
        $this->guardDepth($node, $opening->span);

        return $node;
    }

    private function normalizedFunctionName(string $name): string
    {
        return match ($name) {
            'arcsin' => 'asin',
            'arccos' => 'acos',
            'arctan' => 'atan',
            'arcsinh' => 'asinh',
            'arccosh' => 'acosh',
            'arctanh' => 'atanh',
            default => $name,
        };
    }

    private function startsFunctionArgument(): bool
    {
        return $this->check(TokenType::Number)
            || $this->check(TokenType::Identifier)
            || $this->check(TokenType::LeftParenthesis)
            || $this->startsIndexedRoot()
            || $this->check(TokenType::SquareRoot)
            || $this->check(TokenType::CubeRoot)
            || $this->check(TokenType::AbsoluteValueBar)
            || $this->check(TokenType::Plus)
            || $this->check(TokenType::Minus);
    }

    private function startsIndexedRoot(): bool
    {
        return $this->check(TokenType::Superscript)
            && $this->checkNext(TokenType::SquareRoot);
    }

    private function functionPowerSuffix(): ?bool
    {
        if ($this->check(TokenType::Superscript)) {
            return $this->startsFunctionPowerArgumentAt(1) ? true : null;
        }
        if (!$this->check(TokenType::Power)) {
            return null;
        }

        $exponentOffset = 1;
        if (
            $this->checkAtOffset($exponentOffset, TokenType::Plus)
            || $this->checkAtOffset($exponentOffset, TokenType::Minus)
        ) {
            ++$exponentOffset;
        }
        if (
            !$this->checkAtOffset($exponentOffset, TokenType::Number)
            || !$this->startsFunctionPowerArgumentAt($exponentOffset + 1)
        ) {
            return null;
        }

        return false;
    }

    /** @return array{Node, SourceSpan} */
    private function consumeFunctionPowerExponent(bool $superscript): array
    {
        if ($superscript) {
            $token = $this->advance();

            return [$this->superscriptNumber($token), $token->span];
        }

        $operator = $this->advance();
        $sign = null;
        if ($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) {
            $sign = $this->advance();
        }
        $number = $this->advance();
        [$value, $hasFiniteApproximation] = $this->numberValue($number->lexeme);
        $exponent = new NumberNode(
            $value,
            $number->lexeme,
            $number->span,
            $hasFiniteApproximation,
        );
        if ($sign !== null && $sign->type === TokenType::Minus) {
            $exponent = new UnaryOperationNode(
                UnaryOperator::Minus,
                $exponent,
                $sign->span,
            );
        }

        return [$exponent, $operator->span];
    }

    private function startsFunctionPowerArgumentAt(int $offset): bool
    {
        return $this->checkAtOffset($offset, TokenType::Number)
            || $this->checkAtOffset($offset, TokenType::Identifier)
            || $this->checkAtOffset($offset, TokenType::LeftParenthesis)
            || $this->checkAtOffset($offset, TokenType::SquareRoot)
            || $this->checkAtOffset($offset, TokenType::CubeRoot)
            || $this->checkAtOffset($offset, TokenType::AbsoluteValueBar)
            || (
                $this->checkAtOffset($offset, TokenType::Superscript)
                && $this->checkAtOffset($offset + 1, TokenType::SquareRoot)
            );
    }

    private function isNegativeOne(Node $node): bool
    {
        if ($node instanceof NumberNode) {
            return (float) $node->value === -1.0;
        }

        return $node instanceof UnaryOperationNode
            && $node->operator === UnaryOperator::Minus
            && $node->operand instanceof NumberNode
            && (float) $node->operand->value === 1.0;
    }

    private function inverseFunctionName(string $name): ?string
    {
        $inverse = match ($name) {
            'sin' => 'asin',
            'asin' => 'sin',
            'cos' => 'acos',
            'acos' => 'cos',
            'tan' => 'atan',
            'atan' => 'tan',
            'sec' => 'asec',
            'asec' => 'sec',
            'csc' => 'acsc',
            'acsc' => 'csc',
            'cot' => 'acot',
            'acot' => 'cot',
            'sinh' => 'asinh',
            'asinh' => 'sinh',
            'cosh' => 'acosh',
            'acosh' => 'cosh',
            'tanh' => 'atanh',
            'atanh' => 'tanh',
            'sech' => 'asech',
            'asech' => 'sech',
            'csch' => 'acsch',
            'acsch' => 'csch',
            'coth' => 'acoth',
            'acoth' => 'coth',
            'erf' => 'erfinv',
            'erfinv' => 'erf',
            'erfc' => 'erfcinv',
            'erfcinv' => 'erfc',
            'exp' => 'ln',
            'ln' => 'exp',
            'expm1' => 'log1p',
            'log1p' => 'expm1',
            default => null,
        };

        return $inverse !== null && $this->functions->find($inverse) !== null
            ? $inverse
            : null;
    }

    private function checkNext(TokenType $type): bool
    {
        return $this->checkAtOffset(1, $type);
    }

    private function checkAtOffset(int $offset, TokenType $type): bool
    {
        return ($this->tokens[$this->position + $offset] ?? null)?->type === $type;
    }

    private function enterNesting(SourceSpan $openingSpan): void
    {
        if ($this->nesting >= $this->limits->maxNesting) {
            throw new ParseException(
                'Expression exceeds the configured nesting limit',
                'limit.nesting',
                $openingSpan,
            );
        }
        if ($this->nesting >= $this->limits->maxAstDepth) {
            throw new ParseException(
                'Expression exceeds the configured AST depth limit',
                'limit.ast_depth',
                $openingSpan,
            );
        }

        ++$this->nesting;
    }

    private function guardProspectiveDepth(int $reservedDepth, SourceSpan $span): void
    {
        if ($reservedDepth + 2 > $this->limits->maxAstDepth) {
            throw new ParseException(
                'Expression exceeds the configured AST depth limit',
                'limit.ast_depth',
                $span,
            );
        }
    }

    private function guardDepth(Node $node, SourceSpan $triggerSpan): void
    {
        if ($node->depth() > $this->limits->maxAstDepth) {
            throw new ParseException(
                'Expression exceeds the configured AST depth limit',
                'limit.ast_depth',
                $triggerSpan,
            );
        }
    }

    private function consume(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw new ParseException(
            $message,
            'parse.expected_token',
            $this->current()->span,
        );
    }

    /**
     * @phpstan-impure Depends on the parser's mutable token position.
     */
    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    private function advance(): Token
    {
        $token = $this->current();

        if ($token->type !== TokenType::EndOfInput) {
            ++$this->position;
        }

        return $token;
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }
}
