<?php

declare(strict_types=1);

namespace MathPHP\Tests\Parser;

use MathPHP\Parser\Token;
use MathPHP\Parser\TokenType;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    public function testTokenIsAFinalReadonlyValueObject(): void
    {
        $span = new SourceSpan(3, 6);
        $token = new Token(TokenType::Identifier, 'foo', $span);

        self::assertSame(TokenType::Identifier, $token->type);
        self::assertSame('foo', $token->lexeme);
        self::assertSame($span, $token->span);

        $reflection = new \ReflectionClass(Token::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function testTokenTypesMapExactlyToTheLexicalContract(): void
    {
        self::assertSame(
            [
                'number',
                'identifier',
                '+',
                '-',
                '*',
                '/',
                '%',
                '^',
                'superscript',
                'square_root',
                '!',
                '(',
                ')',
                ',',
                'eof',
            ],
            \array_map(
                static fn (TokenType $type): string => $type->value,
                TokenType::cases(),
            ),
        );
    }
}
