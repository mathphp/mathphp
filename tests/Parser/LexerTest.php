<?php

declare(strict_types=1);

namespace MathPHP\Tests\Parser;

use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\LexicalException;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Token;
use MathPHP\Parser\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    #[DataProvider('validNumberProvider')]
    public function testEveryDocumentedNumberFormIsOneNumberToken(
        string $literal,
    ): void {
        $tokens = (new Lexer($literal))->tokenize();

        self::assertCount(2, $tokens);
        self::assertToken(
            $tokens[0],
            TokenType::Number,
            $literal,
            0,
            \strlen($literal),
        );
        self::assertToken(
            $tokens[1],
            TokenType::EndOfInput,
            '',
            \strlen($literal),
            \strlen($literal),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNumberProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'leading zeroes' => ['007'];
        yield 'integer' => ['42'];
        yield 'trailing decimal point' => ['1.'];
        yield 'leading decimal point' => ['.5'];
        yield 'decimal fraction' => ['0.125'];
        yield 'positive exponent' => ['1e3'];
        yield 'uppercase negative exponent' => ['1E-3'];
        yield 'signed exponent after leading point' => ['.5e+2'];
        yield 'exponent after trailing point' => ['1.e2'];
    }

    #[DataProvider('identifierProvider')]
    public function testAsciiIdentifiersArePreservedCaseSensitively(
        string $identifier,
    ): void {
        $tokens = (new Lexer($identifier))->tokenize();

        self::assertToken(
            $tokens[0],
            TokenType::Identifier,
            $identifier,
            0,
            \strlen($identifier),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifierProvider(): iterable
    {
        yield 'underscore' => ['_'];
        yield 'leading underscore' => ['_value2'];
        yield 'lowercase' => ['alpha'];
        yield 'uppercase' => ['ALPHA'];
        yield 'mixed case and underscore' => ['a_B9'];
        yield 'constant spelling pi' => ['pi'];
        yield 'constant spelling e' => ['e'];
        yield 'NaN is an identifier' => ['NaN'];
        yield 'INF is an identifier' => ['INF'];
        yield 'Infinity is an identifier' => ['Infinity'];
    }

    public function testAllSixAsciiWhitespaceBytesAreIgnoredBetweenTokens(): void
    {
        $tokens = (new Lexer(" \t\n\r\f\vfoo\v+\tbar"))->tokenize();

        self::assertCount(4, $tokens);
        self::assertToken($tokens[0], TokenType::Identifier, 'foo', 6, 9);
        self::assertToken($tokens[1], TokenType::Plus, '+', 10, 11);
        self::assertToken($tokens[2], TokenType::Identifier, 'bar', 12, 15);
        self::assertToken($tokens[3], TokenType::EndOfInput, '', 15, 15);
    }

    public function testEveryOperatorAndDelimiterHasItsOwnToken(): void
    {
        $source = '+-*/%^!(),';
        $expectedTypes = [
            TokenType::Plus,
            TokenType::Minus,
            TokenType::Multiply,
            TokenType::Divide,
            TokenType::Modulo,
            TokenType::Power,
            TokenType::Factorial,
            TokenType::LeftParenthesis,
            TokenType::RightParenthesis,
            TokenType::Comma,
        ];
        $tokens = (new Lexer($source))->tokenize();

        self::assertCount(11, $tokens);

        foreach ($expectedTypes as $position => $expectedType) {
            self::assertToken(
                $tokens[$position],
                $expectedType,
                $source[$position],
                $position,
                $position + 1,
            );
        }

        self::assertToken($tokens[10], TokenType::EndOfInput, '', 10, 10);
    }

    public function testAlphabeticTextAfterANumberStartsASeparateToken(): void
    {
        $tokens = (new Lexer('2pi 1foo'))->tokenize();

        self::assertSame(
            [
                TokenType::Number,
                TokenType::Identifier,
                TokenType::Number,
                TokenType::Identifier,
                TokenType::EndOfInput,
            ],
            \array_map(
                static fn (Token $token): TokenType => $token->type,
                $tokens,
            ),
        );
        self::assertSame(
            ['2', 'pi', '1', 'foo', ''],
            \array_map(
                static fn (Token $token): string => $token->lexeme,
                $tokens,
            ),
        );
        self::assertToken($tokens[0], TokenType::Number, '2', 0, 1);
        self::assertToken($tokens[1], TokenType::Identifier, 'pi', 1, 3);
        self::assertToken($tokens[2], TokenType::Number, '1', 4, 5);
        self::assertToken($tokens[3], TokenType::Identifier, 'foo', 5, 8);
    }

    public function testHostIntegerMaximumIsAcceptedWithLeadingZeroes(): void
    {
        $literal = '000' . (string) \PHP_INT_MAX;
        $tokens = (new Lexer($literal))->tokenize();

        self::assertToken(
            $tokens[0],
            TokenType::Number,
            $literal,
            0,
            \strlen($literal),
        );
    }

    public function testIntegerAboveHostRangeIsRejected(): void
    {
        $source = (string) \PHP_INT_MAX . '0';

        self::assertLexicalError(
            $source,
            'lex.number_out_of_range',
            0,
            \strlen($source),
        );
    }

    public function testNonFiniteFloatLiteralIsRejected(): void
    {
        self::assertLexicalError(
            '1e309',
            'lex.number_out_of_range',
            0,
            5,
        );
    }

    #[DataProvider('malformedNumberProvider')]
    public function testMalformedNumberUsesTheCompleteNormativeRun(
        string $source,
        int $expectedEnd,
    ): void {
        self::assertLexicalError(
            $source,
            'lex.malformed_number',
            0,
            $expectedEnd,
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function malformedNumberProvider(): iterable
    {
        yield 'missing exponent digits' => ['1e', 2];
        yield 'missing digits after exponent sign' => ['1e+', 3];
        yield 'letters after exponent marker' => ['1efoo', 5];
        yield 'underscore after exponent marker' => ['1e_2', 4];
        yield 'decimal point after exponent' => ['1e2.3', 5];
        yield 'missing exponent after trailing point' => ['1.e', 3];
        yield 'two decimal points' => ['1..2', 4];
        yield 'second decimal point in fraction' => ['1.2.3', 5];
        yield 'two exponent markers' => ['1e2e3', 5];
        yield 'second exponent marker with plus sign' => ['1e2e+3', 6];
        yield 'second exponent marker with minus sign' => ['1e2e-3', 6];
        yield 'numeric separator' => ['1_000', 5];
    }

    public function testMalformedNumberRunStopsBeforeAnOperator(): void
    {
        self::assertLexicalError(
            '1efoo+2',
            'lex.malformed_number',
            0,
            5,
        );
    }

    #[DataProvider('unknownByteProvider')]
    public function testUnknownAsciiNulAndNonAsciiBytesAreNeverSkipped(
        string $source,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        self::assertLexicalError(
            $source,
            'lex.unknown_character',
            $expectedStart,
            $expectedEnd,
        );
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function unknownByteProvider(): iterable
    {
        yield 'unknown printable ASCII' => ['@', 0, 1];
        yield 'unknown byte after valid token' => ['1 @ 2', 2, 3];
        yield 'NUL byte' => ["\0", 0, 1];
        yield 'NUL after operator' => ["1+\0", 2, 3];
        yield 'unsupported Unicode letter' => ['λ', 0, 1];
        yield 'four-byte Unicode symbol' => ['😀', 0, 1];
        yield 'invalid UTF-8 byte' => ["\xFF", 0, 1];
        yield 'bare decimal point' => ['.', 0, 1];
    }

    public function testEmptyAndWhitespaceOnlySourcesStillEmitExactlyOneEof(): void
    {
        foreach (['', " \t\n"] as $source) {
            $tokens = (new Lexer($source))->tokenize();

            self::assertCount(1, $tokens);
            self::assertToken(
                $tokens[0],
                TokenType::EndOfInput,
                '',
                \strlen($source),
                \strlen($source),
            );
        }
    }

    public function testTokenizeCanBeCalledAgainWithoutLeakingPosition(): void
    {
        $lexer = new Lexer('1 + x');

        $first = $lexer->tokenize();
        $second = $lexer->tokenize();

        self::assertEquals($first, $second);
        self::assertNotSame($first, $second);
    }

    public function testDefaultExpressionLengthAllowsExactly4096Bytes(): void
    {
        $source = '1' . \str_repeat(' ', 4095);
        $tokens = (new Lexer($source))->tokenize();

        self::assertCount(2, $tokens);
        self::assertToken(
            $tokens[1],
            TokenType::EndOfInput,
            '',
            4096,
            4096,
        );
    }

    public function testDefaultExpressionLengthRejectsTheFirstByteOver4096(): void
    {
        $source = '1' . \str_repeat(' ', 4096);

        self::assertLexicalError(
            $source,
            'limit.expression_length',
            4096,
            4097,
        );
    }

    public function testConfiguredExpressionLengthIsInclusive(): void
    {
        $limits = new ResourceLimits(maxExpressionLength: 3);

        self::assertCount(4, (new Lexer('1+2', $limits))->tokenize());
        self::assertLexicalError(
            '1+2 ',
            'limit.expression_length',
            3,
            4,
            $limits,
        );
    }

    public function testDefaultTokenLimitAllowsExactly1024NonEofTokens(): void
    {
        $tokens = (new Lexer(\str_repeat('+', 1024)))->tokenize();

        self::assertCount(1025, $tokens);
        self::assertSame(TokenType::EndOfInput, $tokens[1024]->type);
    }

    public function testDefaultTokenLimitRejectsTheFirstTokenOver1024(): void
    {
        self::assertLexicalError(
            \str_repeat('+', 1025),
            'limit.token_count',
            1024,
            1025,
        );
    }

    public function testConfiguredTokenLimitIsInclusiveAndDoesNotCountEof(): void
    {
        $threeTokens = new ResourceLimits(maxTokens: 3);
        $oneToken = new ResourceLimits(maxTokens: 1);

        self::assertCount(4, (new Lexer('1+2', $threeTokens))->tokenize());
        self::assertCount(2, (new Lexer('1   ', $oneToken))->tokenize());

        self::assertLexicalError(
            '1+2',
            'limit.token_count',
            2,
            3,
            new ResourceLimits(maxTokens: 2),
        );
    }

    private static function assertToken(
        Token $token,
        TokenType $expectedType,
        string $expectedLexeme,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        self::assertSame($expectedType, $token->type);
        self::assertSame($expectedLexeme, $token->lexeme);
        self::assertSame($expectedStart, $token->span->start);
        self::assertSame($expectedEnd, $token->span->end);
    }

    private static function assertLexicalError(
        string $source,
        string $expectedCode,
        int $expectedStart,
        int $expectedEnd,
        ?ResourceLimits $limits = null,
    ): void {
        try {
            (new Lexer($source, $limits))->tokenize();
            self::fail('Expected a lexical exception.');
        } catch (LexicalException $exception) {
            self::assertSame($expectedCode, $exception->errorCode());
            self::assertSame($expectedStart, $exception->span()->start);
            self::assertSame($expectedEnd, $exception->span()->end);
            self::assertStringContainsString(
                \sprintf('position %d', $expectedStart),
                $exception->getMessage(),
            );
        }
    }
}
