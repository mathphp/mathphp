<?php

declare(strict_types=1);

namespace MathPHP\Tests\Documentation;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\MathException;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentationExamplesTest extends TestCase
{
    /**
     * DOC-001: every expression/result pair published in the README's quick
     * start and precedence table executes through the public facade.
     */
    #[DataProvider('publicExpressionProvider')]
    public function testPublishedExpressionResults(
        string $expression,
        int|float $expected,
    ): void {
        self::assertSame($expected, Math::evaluate($expression));
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function publicExpressionProvider(): iterable
    {
        yield 'quick start arithmetic' => ['2 + 3 * 4', 14];
        yield 'right associative power' => ['2^3^2', 512];
        yield 'conventional unary precedence' => ['-2^2', -4];
        yield 'built-in and factorial' => ['sqrt(81) + 3!', 15.0];
        yield 'negative exponent' => ['2^-2', 0.25];
        yield 'grouped negative base' => ['(-2)^2', 4];
        yield 'factorial before unary sign' => ['-3!', -6];
    }

    public function testPublishedVariableExample(): void
    {
        self::assertSame(
            100.0,
            Math::evaluate(
                'gross * (1 - discount)',
                ['gross' => 125, 'discount' => 0.2],
            ),
        );
    }

    public function testPublishedConstantExample(): void
    {
        $area = Math::evaluate('pi * radius^2', ['radius' => 3]);

        self::assertIsFloat($area);
        self::assertEqualsWithDelta(
            28.274333882308138,
            $area,
            1.0e-12,
        );
    }

    public function testPublishedResourceLimitExample(): void
    {
        $limits = new ResourceLimits(
            maxExpressionLength: 256,
            maxExponentMagnitude: 8,
        );
        $options = new EvaluationOptions(limits: $limits);

        self::assertSame(256, Math::evaluate('2^8', options: $options));
    }

    public function testPublishedCustomFunctionExample(): void
    {
        $triple = new FunctionDefinition(
            'triple',
            1,
            1,
            static fn (array $arguments): int|float => $arguments[0] * 3,
        );

        $functions = FunctionRegistry::defaults()->with($triple);
        $options = new EvaluationOptions(functions: $functions);

        self::assertSame(
            21,
            Math::evaluate('triple(7)', options: $options),
        );
    }

    public function testPublishedSourceAwareErrorExample(): void
    {
        try {
            Math::evaluate('10 / 0');
            self::fail('The documented division-by-zero example must fail.');
        } catch (MathException $error) {
            self::assertSame(
                'eval.division_by_zero',
                $error->errorCode(),
            );
            self::assertSame(3, $error->span()->start);
            self::assertSame(4, $error->span()->end);
        }
    }

    /**
     * DOC-001 / DEF-001: every explicitly rejected README input remains
     * outside the documented language.
     */
    #[DataProvider('deferredSyntaxProvider')]
    public function testPublishedDeferredSyntaxIsRejected(string $expression): void
    {
        $this->expectException(MathException::class);

        Math::evaluate($expression);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deferredSyntaxProvider(): iterable
    {
        yield 'repeated factorial' => ['3!!'];
        yield 'comparison' => ['1 < 2'];
        yield 'call without parentheses' => ['sqrt 4'];
    }

    /** @param array<string, int|float> $variables */
    #[DataProvider('implicitMultiplicationProvider')]
    public function testDocumentedImplicitMultiplicationEvaluates(
        string $expression,
        array $variables,
        int|float $expected,
    ): void {
        self::assertSame($expected, Math::evaluate($expression, $variables));
    }

    /**
     * @return iterable<string, array{string, array<string, int|float>, int|float}>
     */
    public static function implicitMultiplicationProvider(): iterable
    {
        yield 'constant factor' => ['2pi', [], 2 * \M_PI];
        yield 'number and grouping' => ['2(3 + 4)', [], 14];
        yield 'adjacent groupings' => ['(2 + 1)(3 + 1)', [], 12];
        yield 'coefficient and variable' => ['2x + 1', ['x' => 4], 9];
    }
}
