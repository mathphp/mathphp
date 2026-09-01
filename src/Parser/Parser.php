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
use MathPHP\Source\SourceSpan;

final class Parser
{
    private int $position = 0;

    private int $nesting = 0;

    private ResourceLimits $limits;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(
        private readonly array $tokens,
        ?ResourceLimits $limits = null,
    ) {
        if ($tokens === []) {
            throw new \InvalidArgumentException('Parser requires an EOF-terminated token list.');
        }

        if ($tokens[\array_key_last($tokens)]->type !== TokenType::EndOfInput) {
            throw new \InvalidArgumentException('Parser token list must end with EOF.');
        }

        $this->limits = $limits ?? new ResourceLimits();
    }

    public function parse(): Node
    {
        $this->position = 0;
        $this->nesting = 0;

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
     * interpreted as `x * y`. An identifier followed immediately by `(` is
     * still parsed as a function call because custom functions are supported.
     */
    private function startsImplicitMultiplication(): bool
    {
        return $this->check(TokenType::Number)
            || $this->check(TokenType::LeftParenthesis)
            || $this->check(TokenType::Identifier);
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

        return $this->parsePower($reservedDepth);
    }

    private function parsePower(int $reservedDepth): Node
    {
        $base = $this->parsePostfix();

        if (!$this->check(TokenType::Power)) {
            return $base;
        }

        $operator = $this->current();
        $this->guardProspectiveDepth($reservedDepth, $operator->span);
        $this->advance();
        $exponent = $this->parsePrefix($reservedDepth + 1);
        $node = new BinaryOperationNode(
            BinaryOperator::Power,
            $base,
            $exponent,
            $operator->span,
        );
        $this->guardDepth($node, $operator->span);

        return $node;
    }

    private function parsePostfix(): Node
    {
        $node = $this->parsePrimary();

        if ($this->check(TokenType::Factorial)) {
            $operator = $this->advance();
            $node = new FactorialNode($node, $operator->span);
            $this->guardDepth($node, $operator->span);
        }

        return $node;
    }

    private function parsePrimary(): Node
    {
        if ($this->check(TokenType::Number)) {
            $token = $this->advance();
            $value = \strpbrk($token->lexeme, '.eE') === false
                ? (int) $token->lexeme
                : (float) $token->lexeme;

            return new NumberNode($value, $token->lexeme, $token->span);
        }

        if ($this->check(TokenType::Identifier)) {
            $identifier = $this->advance();

            if ($this->check(TokenType::LeftParenthesis)) {
                return $this->parseFunctionCall($identifier);
            }

            if (in_array($identifier->lexeme, ['pi', 'e', 'tau', 'phi'], true)) {
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

        $node = new FunctionCallNode(
            $identifier->lexeme,
            $arguments,
            $identifier->span,
            new SourceSpan($identifier->span->start, $closing->span->end),
        );
        $this->guardDepth($node, $opening->span);

        return $node;
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
