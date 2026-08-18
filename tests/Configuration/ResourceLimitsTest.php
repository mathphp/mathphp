<?php

declare(strict_types=1);

namespace MathPHP\Tests\Configuration;

use MathPHP\Configuration\ResourceLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceLimitsTest extends TestCase
{
    public function testDefaultsMatchTheNormativeSecurityContract(): void
    {
        $limits = new ResourceLimits();

        self::assertSame(4096, $limits->maxExpressionLength);
        self::assertSame(1024, $limits->maxTokens);
        self::assertSame(64, $limits->maxNesting);
        self::assertSame(128, $limits->maxAstDepth);
        self::assertSame(16, $limits->maxFunctionArguments);
        self::assertSame(ResourceLimits::factorialMaximum(), $limits->maxFactorial);
        self::assertSame(1024, $limits->maxExponentMagnitude);
    }

    public function testFactorialMaximumMatchesTheLargestPlatformSafeOperand(): void
    {
        $expected = \PHP_INT_SIZE === 8 ? 20 : 12;

        self::assertSame($expected, ResourceLimits::factorialMaximum());

        $factorial = 1;
        for ($operand = 2; $operand <= $expected; ++$operand) {
            self::assertLessThanOrEqual(
                \intdiv(\PHP_INT_MAX, $operand),
                $factorial,
            );
            $factorial *= $operand;
        }

        self::assertGreaterThan(
            \intdiv(\PHP_INT_MAX, $expected + 1),
            $factorial,
        );
    }

    public function testInclusiveMinimumValuesCanBeConfigured(): void
    {
        $limits = new ResourceLimits(
            maxExpressionLength: 1,
            maxTokens: 1,
            maxNesting: 0,
            maxAstDepth: 1,
            maxFunctionArguments: 0,
            maxFactorial: 0,
            maxExponentMagnitude: 0.0,
        );

        self::assertSame(1, $limits->maxExpressionLength);
        self::assertSame(1, $limits->maxTokens);
        self::assertSame(0, $limits->maxNesting);
        self::assertSame(1, $limits->maxAstDepth);
        self::assertSame(0, $limits->maxFunctionArguments);
        self::assertSame(0, $limits->maxFactorial);
        self::assertSame(0.0, $limits->maxExponentMagnitude);
    }

    public function testCustomValuesAreStoredWithoutCoercion(): void
    {
        $limits = new ResourceLimits(
            maxExpressionLength: 50,
            maxTokens: 40,
            maxNesting: 3,
            maxAstDepth: 9,
            maxFunctionArguments: 2,
            maxFactorial: 5,
            maxExponentMagnitude: 7.5,
        );

        self::assertSame(50, $limits->maxExpressionLength);
        self::assertSame(40, $limits->maxTokens);
        self::assertSame(3, $limits->maxNesting);
        self::assertSame(9, $limits->maxAstDepth);
        self::assertSame(2, $limits->maxFunctionArguments);
        self::assertSame(5, $limits->maxFactorial);
        self::assertSame(7.5, $limits->maxExponentMagnitude);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfigurationIsRejected(
        \Closure $construct,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $construct();
    }

    /**
     * @return iterable<string, array{\Closure(): ResourceLimits, string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'expression length below one' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxExpressionLength: 0,
            ),
            'maxExpressionLength must be at least 1',
        ];
        yield 'token count below one' => [
            static fn (): ResourceLimits => new ResourceLimits(maxTokens: 0),
            'maxTokens must be at least 1',
        ];
        yield 'negative nesting' => [
            static fn (): ResourceLimits => new ResourceLimits(maxNesting: -1),
            'maxNesting must be at least 0',
        ];
        yield 'AST depth below one' => [
            static fn (): ResourceLimits => new ResourceLimits(maxAstDepth: 0),
            'maxAstDepth must be at least 1',
        ];
        yield 'negative function argument count' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxFunctionArguments: -1,
            ),
            'maxFunctionArguments must be at least 0',
        ];
        yield 'negative factorial operand' => [
            static fn (): ResourceLimits => new ResourceLimits(maxFactorial: -1),
            'maxFactorial must be between',
        ];
        yield 'factorial operand above platform maximum' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxFactorial: ResourceLimits::factorialMaximum() + 1,
            ),
            'maxFactorial must be between',
        ];
        yield 'negative exponent magnitude' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxExponentMagnitude: -0.5,
            ),
            'maxExponentMagnitude must be a finite non-negative number',
        ];
        yield 'infinite exponent magnitude' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxExponentMagnitude: \INF,
            ),
            'maxExponentMagnitude must be a finite non-negative number',
        ];
        yield 'NaN exponent magnitude' => [
            static fn (): ResourceLimits => new ResourceLimits(
                maxExponentMagnitude: \NAN,
            ),
            'maxExponentMagnitude must be a finite non-negative number',
        ];
    }

    public function testItIsAFinalReadonlyValueObject(): void
    {
        $reflection = new \ReflectionClass(ResourceLimits::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
