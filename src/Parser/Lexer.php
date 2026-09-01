<?php

declare(strict_types=1);

namespace MathPHP\Parser;

use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\LexicalException;
use MathPHP\Source\SourceSpan;

final class Lexer
{
    private readonly int $length;

    private int $position = 0;

    private ResourceLimits $limits;

    public function __construct(
        private readonly string $expression,
        ?ResourceLimits $limits = null,
    ) {
        $this->length = \strlen($expression);
        $this->limits = $limits ?? new ResourceLimits();
    }

    /**
     * @return list<Token>
     */
    public function tokenize(): array
    {
        if ($this->length > $this->limits->maxExpressionLength) {
            $position = $this->limits->maxExpressionLength;

            throw new LexicalException(
                'Expression exceeds the configured byte limit',
                'limit.expression_length',
                new SourceSpan($position, $position + 1),
            );
        }

        $this->position = 0;
        $tokens = [];

        while ($this->position < $this->length) {
            $character = $this->expression[$this->position];

            if ($this->isWhitespace($character)) {
                ++$this->position;
                continue;
            }

            if (
                $this->isDigit($character)
                || (
                    $character === '.'
                    && $this->position + 1 < $this->length
                    && $this->isDigit($this->expression[$this->position + 1])
                )
            ) {
                $this->appendToken($tokens, $this->readNumber());
                continue;
            }

            if ($this->isIdentifierStart($character)) {
                $this->appendToken($tokens, $this->readIdentifier());
                continue;
            }

            $unicodeToken = $this->readUnicodeToken();
            if ($unicodeToken !== null) {
                $this->appendToken($tokens, $unicodeToken);
                continue;
            }

            $this->appendToken($tokens, $this->readOperator());
        }

        $tokens[] = new Token(
            TokenType::EndOfInput,
            '',
            new SourceSpan($this->length, $this->length),
        );

        return $tokens;
    }

    private function readNumber(): Token
    {
        $start = $this->position;

        if ($this->expression[$this->position] === '.') {
            ++$this->position;
            $this->consumeDigits();
        } else {
            $this->consumeDigits();

            if ($this->currentCharacter() === '.') {
                ++$this->position;
                $this->consumeDigits();
            }
        }

        $character = $this->currentCharacter();
        if ($character === 'e' || $character === 'E') {
            ++$this->position;

            $character = $this->currentCharacter();
            if ($character === '+' || $character === '-') {
                ++$this->position;
            }

            if (!$this->isCurrentDigit()) {
                $this->consumeMalformedNumberTail();
                $this->throwMalformedNumber($start);
            }

            $this->consumeDigits();
        }

        $character = $this->currentCharacter();
        if (
            $character === '.'
            || $character === '_'
            || $character === 'e'
            || $character === 'E'
        ) {
            $this->consumeMalformedNumberTail();
            $this->throwMalformedNumber($start);
        }

        $literal = \substr($this->expression, $start, $this->position - $start);
        $span = new SourceSpan($start, $this->position);

        if (\strpbrk($literal, '.eE') === false) {
            $normalized = \ltrim($literal, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $maximum = (string) \PHP_INT_MAX;

            if (
                \strlen($normalized) > \strlen($maximum)
                || (
                    \strlen($normalized) === \strlen($maximum)
                    && \strcmp($normalized, $maximum) > 0
                )
            ) {
                throw new LexicalException(
                    'Integer literal is outside the host integer range',
                    'lex.number_out_of_range',
                    $span,
                );
            }
        } elseif (!\is_finite((float) $literal)) {
            throw new LexicalException(
                'Float literal is outside the finite range',
                'lex.number_out_of_range',
                $span,
            );
        }

        return new Token(TokenType::Number, $literal, $span);
    }

    private function readIdentifier(): Token
    {
        $start = $this->position;
        ++$this->position;

        while (
            ($character = $this->currentCharacter()) !== null
            && $this->isIdentifierPart($character)
        ) {
            ++$this->position;
        }

        return new Token(
            TokenType::Identifier,
            \substr($this->expression, $start, $this->position - $start),
            new SourceSpan($start, $this->position),
        );
    }

    private function readOperator(): Token
    {
        $start = $this->position;
        $character = $this->expression[$this->position];
        ++$this->position;

        $type = match ($character) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Multiply,
            '/' => TokenType::Divide,
            '%' => TokenType::Modulo,
            '^' => TokenType::Power,
            '!' => TokenType::Factorial,
            '(' => TokenType::LeftParenthesis,
            ')' => TokenType::RightParenthesis,
            ',' => TokenType::Comma,
            default => throw new LexicalException(
                \sprintf('Unknown character %s', $this->describeByte($character)),
                'lex.unknown_character',
                new SourceSpan($start, $this->position),
            ),
        };

        return new Token(
            $type,
            $character,
            new SourceSpan($start, $this->position),
        );
    }

    private function readUnicodeToken(): ?Token
    {
        $start = $this->position;
        $remaining = \substr($this->expression, $start);
        $symbols = [
            '×' => [TokenType::Multiply, '*'],
            '·' => [TokenType::Multiply, '*'],
            '÷' => [TokenType::Divide, '/'],
            '−' => [TokenType::Minus, '-'],
            'π' => [TokenType::Identifier, 'pi'],
            'τ' => [TokenType::Identifier, 'tau'],
            'φ' => [TokenType::Identifier, 'phi'],
        ];

        foreach ($symbols as $symbol => [$type, $lexeme]) {
            if (!\str_starts_with($remaining, $symbol)) {
                continue;
            }

            $this->position += \strlen($symbol);
            return new Token($type, $lexeme, new SourceSpan($start, $this->position));
        }

        return null;
    }

    /**
     * @param list<Token> $tokens
     */
    private function appendToken(array &$tokens, Token $token): void
    {
        if (\count($tokens) >= $this->limits->maxTokens) {
            throw new LexicalException(
                'Expression exceeds the configured token limit',
                'limit.token_count',
                $token->span,
            );
        }

        $tokens[] = $token;
    }

    private function consumeDigits(): void
    {
        while ($this->isCurrentDigit()) {
            ++$this->position;
        }
    }

    private function consumeMalformedNumberTail(): void
    {
        $previousWasExponentMarker = false;

        while (($character = $this->currentCharacter()) !== null) {
            if (
                $this->isIdentifierPart($character)
                || $character === '.'
            ) {
                $previousWasExponentMarker = $character === 'e'
                    || $character === 'E';
                ++$this->position;
                continue;
            }

            if (
                $previousWasExponentMarker
                && ($character === '+' || $character === '-')
            ) {
                $previousWasExponentMarker = false;
                ++$this->position;
                continue;
            }

            break;
        }
    }

    private function throwMalformedNumber(int $start): never
    {
        throw new LexicalException(
            'Malformed numeric literal',
            'lex.malformed_number',
            new SourceSpan($start, $this->position),
        );
    }

    private function isCurrentDigit(): bool
    {
        $character = $this->currentCharacter();

        return $character !== null && $this->isDigit($character);
    }

    private function currentCharacter(): ?string
    {
        if ($this->position >= $this->length) {
            return null;
        }

        return $this->expression[$this->position];
    }

    private function isWhitespace(string $character): bool
    {
        return \str_contains(" \t\n\r\f\v", $character);
    }

    private function isDigit(string $character): bool
    {
        return $character >= '0' && $character <= '9';
    }

    private function isIdentifierStart(string $character): bool
    {
        return $character === '_'
            || ($character >= 'A' && $character <= 'Z')
            || ($character >= 'a' && $character <= 'z');
    }

    private function isIdentifierPart(string $character): bool
    {
        return $this->isIdentifierStart($character)
            || $this->isDigit($character);
    }

    private function describeByte(string $character): string
    {
        $ordinal = \ord($character);

        if ($ordinal >= 0x20 && $ordinal <= 0x7e) {
            return \sprintf('"%s"', $character);
        }

        return \sprintf('0x%02X', $ordinal);
    }
}
