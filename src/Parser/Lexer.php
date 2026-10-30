<?php

declare(strict_types=1);

namespace MathPHP\Parser;

use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\LexicalException;
use MathPHP\Source\SourceSpan;

final class Lexer
{
    /** @var array<string, string> */
    private const SUPERSCRIPT_DIGITS = [
        '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4',
        '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
    ];

    /** @var array<string, string> */
    private const SUBSCRIPT_DIGITS = [
        '₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4',
        '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
    ];

    private readonly int $length;

    private int $position = 0;

    private ResourceLimits $limits;

    public function __construct(
        private readonly string $expression,
        ?ResourceLimits $limits = null,
        private readonly bool $allowUnrepresentableNumericLiterals = false,
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

            if ($character === '|' && $this->startsLogicalOr($tokens)) {
                $operator = $tokens[\count($tokens) - 1];
                throw new LexicalException(
                    'Unknown character |',
                    'lex.unknown_character',
                    new SourceSpan($operator->span->start, $operator->span->start + 1),
                );
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

    /** @param list<Token> $tokens */
    private function startsLogicalOr(array $tokens): bool
    {
        $barIndex = \count($tokens) - 1;
        if (
            $barIndex < 1
            || $tokens[$barIndex]->type !== TokenType::AbsoluteValueBar
        ) {
            return false;
        }

        $leftOperandType = $tokens[$barIndex - 1]->type;
        if (
            !\in_array(
                $leftOperandType,
                [
                    TokenType::Number,
                    TokenType::Identifier,
                    TokenType::RightParenthesis,
                    TokenType::Factorial,
                    TokenType::Superscript,
                    TokenType::AbsoluteValueBar,
                ],
                true,
            )
        ) {
            return false;
        }

        $nextPosition = $this->position;
        while (
            $nextPosition < $this->length
            && $this->isWhitespace($this->expression[$nextPosition])
        ) {
            ++$nextPosition;
        }

        return $nextPosition < $this->length
            && $this->expression[$nextPosition] === '|';
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
                if (
                    !\is_finite((float) $literal)
                    && !$this->allowUnrepresentableNumericLiterals
                ) {
                    throw new LexicalException(
                        'Integer literal is outside the finite numeric range',
                        'lex.number_out_of_range',
                        $span,
                    );
                }
            }
        } elseif (
            !\is_finite((float) $literal)
            && !$this->allowUnrepresentableNumericLiterals
        ) {
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

        $lexeme = \substr($this->expression, $start, $this->position - $start);
        $lexeme .= $this->readSubscriptSuffix();

        return new Token(
            TokenType::Identifier,
            $lexeme,
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
            '|' => TokenType::AbsoluteValueBar,
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
        if (\str_starts_with($remaining, '⁺') || \str_starts_with($remaining, '⁻')) {
            $negative = \str_starts_with($remaining, '⁻');
            $this->position += \strlen($negative ? '⁻' : '⁺');
            $digits = '';
            while ($this->position < $this->length) {
                $digit = $this->readSuperscriptDigit();
                if ($digit === null) {
                    break;
                }
                $digits .= $digit;
            }
            if ($digits === '') {
                throw new LexicalException(
                    'A superscript sign must be followed by a digit',
                    'lex.malformed_superscript',
                    new SourceSpan($start, $this->position),
                );
            }

            $lexeme = ($negative ? '-' : '') . $this->validatedSuperscriptInteger(
                $digits,
                $negative,
                new SourceSpan($start, $this->position),
            );

            return new Token(
                TokenType::Superscript,
                $lexeme,
                new SourceSpan($start, $this->position),
            );
        }
        if ($this->isSuperscriptDigitAt($this->position)) {
            $digits = '';
            while (($digit = $this->readSuperscriptDigit()) !== null) {
                $digits .= $digit;
            }
            $digits = $this->validatedSuperscriptInteger(
                $digits,
                false,
                new SourceSpan($start, $this->position),
            );

            return new Token(
                TokenType::Superscript,
                $digits,
                new SourceSpan($start, $this->position),
            );
        }

        $symbols = [
            '×' => [TokenType::Multiply, '*'],
            '·' => [TokenType::Multiply, '*'],
            '÷' => [TokenType::Divide, '/'],
            '−' => [TokenType::Minus, '-'],
            'π' => [TokenType::Identifier, 'pi'],
            'τ' => [TokenType::Identifier, 'tau'],
            'φ' => [TokenType::Identifier, 'phi'],
            // Common Greek variable notation is normalized to ASCII names so
            // host-language variable maps remain portable and case-sensitive.
            'α' => [TokenType::Identifier, 'alpha'],
            'β' => [TokenType::Identifier, 'beta'],
            'γ' => [TokenType::Identifier, 'gamma'],
            'δ' => [TokenType::Identifier, 'delta'],
            'ε' => [TokenType::Identifier, 'epsilon'],
            'ϵ' => [TokenType::Identifier, 'epsilon'],
            'ζ' => [TokenType::Identifier, 'zeta'],
            'η' => [TokenType::Identifier, 'eta'],
            'θ' => [TokenType::Identifier, 'theta'],
            'ϑ' => [TokenType::Identifier, 'theta'],
            'ι' => [TokenType::Identifier, 'iota'],
            'κ' => [TokenType::Identifier, 'kappa'],
            'λ' => [TokenType::Identifier, 'lambda'],
            'μ' => [TokenType::Identifier, 'mu'],
            'ν' => [TokenType::Identifier, 'nu'],
            'ξ' => [TokenType::Identifier, 'xi'],
            'ο' => [TokenType::Identifier, 'omicron'],
            'ρ' => [TokenType::Identifier, 'rho'],
            'ϱ' => [TokenType::Identifier, 'rho'],
            'σ' => [TokenType::Identifier, 'sigma'],
            'ς' => [TokenType::Identifier, 'sigma'],
            'υ' => [TokenType::Identifier, 'upsilon'],
            'ϕ' => [TokenType::Identifier, 'phi'],
            'χ' => [TokenType::Identifier, 'chi'],
            'ψ' => [TokenType::Identifier, 'psi'],
            'ω' => [TokenType::Identifier, 'omega'],
            'Α' => [TokenType::Identifier, 'Alpha'],
            'Β' => [TokenType::Identifier, 'Beta'],
            'Γ' => [TokenType::Identifier, 'Gamma'],
            'Δ' => [TokenType::Identifier, 'Delta'],
            'Ε' => [TokenType::Identifier, 'Epsilon'],
            'Ζ' => [TokenType::Identifier, 'Zeta'],
            'Η' => [TokenType::Identifier, 'Eta'],
            'Θ' => [TokenType::Identifier, 'Theta'],
            'Ι' => [TokenType::Identifier, 'Iota'],
            'Κ' => [TokenType::Identifier, 'Kappa'],
            'Λ' => [TokenType::Identifier, 'Lambda'],
            'Μ' => [TokenType::Identifier, 'Mu'],
            'Ν' => [TokenType::Identifier, 'Nu'],
            'Ξ' => [TokenType::Identifier, 'Xi'],
            'Ο' => [TokenType::Identifier, 'Omicron'],
            'Ρ' => [TokenType::Identifier, 'Rho'],
            'Σ' => [TokenType::Identifier, 'Sigma'],
            'Τ' => [TokenType::Identifier, 'Tau'],
            'Υ' => [TokenType::Identifier, 'Upsilon'],
            'Φ' => [TokenType::Identifier, 'Phi'],
            'Χ' => [TokenType::Identifier, 'Chi'],
            'Ψ' => [TokenType::Identifier, 'Psi'],
            'Ω' => [TokenType::Identifier, 'Omega'],
            '√' => [TokenType::SquareRoot, 'sqrt'],
            '∛' => [TokenType::CubeRoot, 'cbrt'],
        ];

        foreach ($symbols as $symbol => [$type, $lexeme]) {
            if (!\str_starts_with($remaining, $symbol)) {
                continue;
            }

            $this->position += \strlen($symbol);
            if ($type === TokenType::Identifier) {
                $lexeme .= $this->readSubscriptSuffix();
            }

            return new Token($type, $lexeme, new SourceSpan($start, $this->position));
        }

        return null;
    }

    private function readSubscriptSuffix(): string
    {
        $digits = '';
        while ($this->position < $this->length) {
            $matched = false;
            foreach (self::SUBSCRIPT_DIGITS as $symbol => $digit) {
                if (\substr($this->expression, $this->position, \strlen($symbol)) !== $symbol) {
                    continue;
                }
                $this->position += \strlen($symbol);
                $digits .= $digit;
                $matched = true;
                break;
            }
            if (!$matched) {
                break;
            }
        }

        return $digits === '' ? '' : '_' . $digits;
    }

    private function readSuperscriptDigit(): ?string
    {
        foreach (self::SUPERSCRIPT_DIGITS as $symbol => $digit) {
            if (\substr($this->expression, $this->position, \strlen($symbol)) !== $symbol) {
                continue;
            }
            $this->position += \strlen($symbol);

            return $digit;
        }

        return null;
    }

    private function validatedSuperscriptInteger(
        string $digits,
        bool $negative,
        SourceSpan $span,
    ): string {
        $normalized = \ltrim($digits, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = $negative ? \substr((string) \PHP_INT_MIN, 1) : (string) \PHP_INT_MAX;
        if (
            \strlen($normalized) > \strlen($maximum)
            || (
                \strlen($normalized) === \strlen($maximum)
                && \strcmp($normalized, $maximum) > 0
            )
        ) {
            throw new LexicalException(
                'Superscript exponent is outside the host integer range',
                'lex.number_out_of_range',
                $span,
            );
        }

        return $normalized;
    }

    private function isSuperscriptDigitAt(int $position): bool
    {
        $remaining = \substr($this->expression, $position);
        foreach (self::SUPERSCRIPT_DIGITS as $symbol => $_digit) {
            if (\str_starts_with($remaining, $symbol)) {
                return true;
            }
        }

        return false;
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
