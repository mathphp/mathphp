<?php

declare(strict_types=1);

namespace MathPHP\Tests\Evaluation;

use MathPHP\Configuration\ResourceLimits;
use MathPHP\Evaluator\NumericOperations;
use MathPHP\Exception\EvaluationException;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericOperationsTest extends TestCase
{
    private NumericOperations $numbers;

    private SourceSpan $operatorSpan;

    protected function setUp(): void
    {
        $this->numbers = new NumericOperations(new ResourceLimits());
        $this->operatorSpan = new SourceSpan(7, 8);
    }

    public function testUnaryPositivePreservesExactTypesAndNormalizesSignedZero(): void
    {
        self::assertSame(
            42,
            $this->numbers->positive(42, $this->operatorSpan),
        );
        self::assertSame(
            42.0,
            $this->numbers->positive(42.0, $this->operatorSpan),
        );

        $zero = $this->numbers->positive(-0.0, $this->operatorSpan);

        self::assertSame('float', \get_debug_type($zero));
        self::assertPositiveZero($zero);
    }

    public function testUnaryNegationPreservesExactTypesAndNormalizesSignedZero(): void
    {
        self::assertSame(
            -42,
            $this->numbers->negate(42, $this->operatorSpan),
        );
        self::assertSame(
            -42.5,
            $this->numbers->negate(42.5, $this->operatorSpan),
        );

        $zero = $this->numbers->negate(0.0, $this->operatorSpan);

        self::assertSame('float', \get_debug_type($zero));
        self::assertPositiveZero($zero);
    }

    public function testNegatingTheHostIntegerMinimumIsAnExactOverflow(): void
    {
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->negate(
                \PHP_INT_MIN,
                $this->operatorSpan,
            ),
            'eval.integer_overflow',
            $this->operatorSpan,
            'Unary negation exceeds the host integer range',
        );
    }

    #[DataProvider('exactIntegerArithmeticProvider')]
    public function testOrdinaryIntegerArithmeticReturnsExactIntegers(
        string $operation,
        int $left,
        int $right,
        int $expected,
    ): void {
        $actual = $this->invokeBinary($operation, $left, $right);

        self::assertSame($expected, $actual);
        self::assertSame('int', \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{string, int, int, int}>
     */
    public static function exactIntegerArithmeticProvider(): iterable
    {
        yield 'addition' => ['add', 12, 5, 17];
        yield 'subtraction' => ['subtract', 12, 5, 7];
        yield 'multiplication' => ['multiply', -12, 5, -60];
        yield 'maximum plus zero' => [
            'add',
            \PHP_INT_MAX,
            0,
            \PHP_INT_MAX,
        ];
        yield 'minimum minus zero' => [
            'subtract',
            \PHP_INT_MIN,
            0,
            \PHP_INT_MIN,
        ];
        yield 'maximum times one' => [
            'multiply',
            \PHP_INT_MAX,
            1,
            \PHP_INT_MAX,
        ];
    }

    #[DataProvider('floatingArithmeticProvider')]
    public function testAFloatOperandMakesOrdinaryArithmeticReturnAFloat(
        string $operation,
        int|float $left,
        int|float $right,
        float $expected,
    ): void {
        $actual = $this->invokeBinary($operation, $left, $right);

        self::assertSame($expected, $actual);
        self::assertSame('float', \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{string, int|float, int|float, float}>
     */
    public static function floatingArithmeticProvider(): iterable
    {
        yield 'addition with float on left' => ['add', 1.5, 2, 3.5];
        yield 'addition with float on right' => ['add', 1, 2.0, 3.0];
        yield 'subtraction with float' => ['subtract', 5, 2.5, 2.5];
        yield 'multiplication with float' => ['multiply', -2.0, 3, -6.0];
    }

    #[DataProvider('integerOverflowProvider')]
    public function testOrdinaryIntegerOverflowIsNeverPromotedToFloat(
        string $operation,
        int $left,
        int $right,
        string $message,
    ): void {
        $this->assertEvaluationError(
            fn (): int|float => $this->invokeBinary(
                $operation,
                $left,
                $right,
            ),
            'eval.integer_overflow',
            $this->operatorSpan,
            $message,
        );
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function integerOverflowProvider(): iterable
    {
        yield 'addition above maximum' => [
            'add',
            \PHP_INT_MAX,
            1,
            'Integer addition exceeds the host integer range',
        ];
        yield 'addition below minimum' => [
            'add',
            \PHP_INT_MIN,
            -1,
            'Integer addition exceeds the host integer range',
        ];
        yield 'subtraction below minimum' => [
            'subtract',
            \PHP_INT_MIN,
            1,
            'Integer subtraction exceeds the host integer range',
        ];
        yield 'subtraction above maximum' => [
            'subtract',
            \PHP_INT_MAX,
            -1,
            'Integer subtraction exceeds the host integer range',
        ];
        yield 'positive multiplication overflow' => [
            'multiply',
            \PHP_INT_MAX,
            2,
            'Integer multiplication exceeds the host integer range',
        ];
        yield 'negative multiplication overflow' => [
            'multiply',
            \PHP_INT_MIN,
            -1,
            'Integer multiplication exceeds the host integer range',
        ];
    }

    #[DataProvider('nonFiniteArithmeticProvider')]
    public function testNonFiniteFloatingArithmeticIsRejected(
        string $operation,
        float $left,
        float $right,
    ): void {
        $this->assertEvaluationError(
            fn (): int|float => $this->invokeBinary(
                $operation,
                $left,
                $right,
            ),
            'eval.non_finite',
            $this->operatorSpan,
            'Operation produced a non-finite result',
        );
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function nonFiniteArithmeticProvider(): iterable
    {
        yield 'addition' => ['add', \PHP_FLOAT_MAX, \PHP_FLOAT_MAX];
        yield 'subtraction' => [
            'subtract',
            -\PHP_FLOAT_MAX,
            \PHP_FLOAT_MAX,
        ];
        yield 'multiplication' => ['multiply', \PHP_FLOAT_MAX, 2.0];
    }

    public function testDivisionAlwaysReturnsAFloat(): void
    {
        self::assertSame(
            3.0,
            $this->numbers->divide(6, 2, $this->operatorSpan),
        );
        self::assertSame(
            2.5,
            $this->numbers->divide(5.0, 2, $this->operatorSpan),
        );
    }

    #[DataProvider('zeroDivisorProvider')]
    public function testEverySignedAndTypedZeroDivisorIsRejected(
        int|float $zero,
    ): void {
        $this->assertEvaluationError(
            fn (): float => $this->numbers->divide(
                1,
                $zero,
                $this->operatorSpan,
            ),
            'eval.division_by_zero',
            $this->operatorSpan,
            'Division by zero',
        );
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function zeroDivisorProvider(): iterable
    {
        yield 'integer zero' => [0];
        yield 'positive float zero' => [0.0];
        yield 'negative float zero' => [-0.0];
    }

    public function testDivisionRejectsOverflowAndAllowsNormalizedUnderflow(): void
    {
        $this->assertEvaluationError(
            fn (): float => $this->numbers->divide(
                \PHP_FLOAT_MAX,
                0.5,
                $this->operatorSpan,
            ),
            'eval.non_finite',
            $this->operatorSpan,
            'Operation produced a non-finite result',
        );

        $underflow = $this->numbers->divide(
            -\PHP_FLOAT_MIN,
            \PHP_FLOAT_MAX,
            $this->operatorSpan,
        );

        self::assertSame('float', \get_debug_type($underflow));
        self::assertPositiveZero($underflow);
    }

    #[DataProvider('validModuloProvider')]
    public function testModuloMatchesTruncatedIntegerRemainderRules(
        int|float $left,
        int|float $right,
        int $expected,
    ): void {
        $actual = $this->numbers->modulo(
            $left,
            $right,
            $this->operatorSpan,
        );

        self::assertSame($expected, $actual);
        self::assertSame('int', \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{int|float, int|float, int}>
     */
    public static function validModuloProvider(): iterable
    {
        yield 'positive operands' => [12, 5, 2];
        yield 'negative dividend' => [-12, 5, -2];
        yield 'negative divisor' => [12, -5, 2];
        yield 'both negative' => [-12, -5, -2];
        yield 'integral floats' => [5.0, 2.0, 1];
        yield 'minimum by negative one special case' => [
            \PHP_INT_MIN,
            -1,
            0,
        ];
        yield 'inclusive lower float boundary' => [
            (float) \PHP_INT_MIN,
            2,
            0,
        ];
    }

    #[DataProvider('invalidModuloOperandProvider')]
    public function testModuloRequiresExactlyIntegralHostRangeOperands(
        int|float $left,
        int|float $right,
        string $message,
    ): void {
        $this->assertEvaluationError(
            fn (): int => $this->numbers->modulo(
                $left,
                $right,
                $this->operatorSpan,
            ),
            'eval.domain',
            $this->operatorSpan,
            $message,
        );
    }

    /**
     * @return iterable<string, array{int|float, int|float, string}>
     */
    public static function invalidModuloOperandProvider(): iterable
    {
        $upperBound = 2.0 ** (\PHP_INT_SIZE * 8 - 1);

        yield 'fractional dividend' => [
            5.5,
            2,
            'Modulo dividend must be an integer within the host range',
        ];
        yield 'fractional divisor' => [
            5,
            2.5,
            'Modulo divisor must be an integer within the host range',
        ];
        yield 'exclusive upper dividend bound' => [
            $upperBound,
            2,
            'Modulo dividend must be an integer within the host range',
        ];
        yield 'exclusive upper divisor bound' => [
            2,
            $upperBound,
            'Modulo divisor must be an integer within the host range',
        ];
        yield 'below lower dividend bound' => [
            -$upperBound * 2.0,
            2,
            'Modulo dividend must be an integer within the host range',
        ];
    }

    #[DataProvider('moduloZeroProvider')]
    public function testModuloRejectsTypedZeroDivisors(int|float $zero): void
    {
        $this->assertEvaluationError(
            fn (): int => $this->numbers->modulo(
                1,
                $zero,
                $this->operatorSpan,
            ),
            'eval.modulo_by_zero',
            $this->operatorSpan,
            'Modulo by zero',
        );
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function moduloZeroProvider(): iterable
    {
        yield 'integer zero' => [0];
        yield 'float zero' => [0.0];
        yield 'negative float zero' => [-0.0];
    }

    #[DataProvider('validPowerProvider')]
    public function testPowerFollowsExactTypeAndDomainRules(
        int|float $base,
        int|float $exponent,
        int|float $expected,
        string $expectedType,
    ): void {
        $actual = $this->numbers->power(
            $base,
            $exponent,
            $this->operatorSpan,
        );

        self::assertSame($expected, $actual);
        self::assertSame($expectedType, \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{int|float, int|float, int|float, string}>
     */
    public static function validPowerProvider(): iterable
    {
        yield 'zero to zero' => [0, 0, 1, 'int'];
        yield 'positive integer exponent' => [2, 10, 1024, 'int'];
        yield 'negative integer base' => [-2, 3, -8, 'int'];
        yield 'negative exponent' => [2, -2, 0.25, 'float'];
        yield 'fractional exponent' => [4, 0.5, 2.0, 'float'];
        yield 'float base preserves float result' => [2.0, 3, 8.0, 'float'];
        yield 'integral float exponent preserves float result' => [
            -2,
            3.0,
            -8.0,
            'float',
        ];
    }

    public function testNegativeTwoToSixtyThreeIsTheExactEndpointOn64Bit(): void
    {
        if (\PHP_INT_SIZE === 8) {
            self::assertSame(
                \PHP_INT_MIN,
                $this->numbers->power(
                    -2,
                    63,
                    $this->operatorSpan,
                ),
            );

            return;
        }

        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                -2,
                63,
                $this->operatorSpan,
            ),
            'eval.integer_overflow',
            $this->operatorSpan,
            'Integer exponentiation exceeds the host integer range',
        );
    }

    public function testTwoToSixtyThreeIsNeverSilentlyPromotedOn64Bit(): void
    {
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                2,
                63,
                $this->operatorSpan,
            ),
            'eval.integer_overflow',
            $this->operatorSpan,
            'Integer exponentiation exceeds the host integer range',
        );
    }

    public function testPowerRejectsIntegerOverflowAndNonFiniteFloatResults(): void
    {
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                \PHP_INT_MAX,
                2,
                $this->operatorSpan,
            ),
            'eval.integer_overflow',
            $this->operatorSpan,
            'Integer exponentiation exceeds the host integer range',
        );
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                \PHP_FLOAT_MAX,
                2.0,
                $this->operatorSpan,
            ),
            'eval.non_finite',
            $this->operatorSpan,
            'Operation produced a non-finite result',
        );
    }

    public function testPowerDomainErrorsPointAtTheOperator(): void
    {
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                -2,
                0.5,
                $this->operatorSpan,
            ),
            'eval.domain',
            $this->operatorSpan,
            'A negative base requires an integer exponent',
        );
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->power(
                0,
                -1,
                $this->operatorSpan,
            ),
            'eval.division_by_zero',
            $this->operatorSpan,
            'Zero cannot be raised to a negative exponent',
        );
    }

    public function testExponentLimitIsInclusiveAndCheckedBeforeTheOperation(): void
    {
        $limits = new ResourceLimits(maxExponentMagnitude: 4);
        $numbers = new NumericOperations($limits);

        self::assertSame(1, $numbers->power(1, 4, $this->operatorSpan));
        self::assertSame(1.0, $numbers->power(1, -4.0, $this->operatorSpan));

        $this->assertEvaluationError(
            fn (): int|float => $numbers->power(
                0,
                -5,
                $this->operatorSpan,
            ),
            'limit.exponent',
            $this->operatorSpan,
            'Exponent exceeds the configured magnitude limit',
        );
    }

    public function testFactorialReturnsExactIntegersAtBothEndpoints(): void
    {
        self::assertSame(
            1,
            $this->numbers->factorial(0, $this->operatorSpan),
        );
        self::assertSame(
            1,
            $this->numbers->factorial(1.0, $this->operatorSpan),
        );

        $maximum = ResourceLimits::factorialMaximum();
        self::assertSame(
            self::factorial($maximum),
            $this->numbers->factorial($maximum, $this->operatorSpan),
        );
    }

    public function testConfiguredFactorialLimitIsInclusive(): void
    {
        $numbers = new NumericOperations(
            new ResourceLimits(maxFactorial: 5),
        );

        self::assertSame(120, $numbers->factorial(5, $this->operatorSpan));

        $this->assertEvaluationError(
            fn (): int => $numbers->factorial(
                6,
                $this->operatorSpan,
            ),
            'limit.factorial',
            $this->operatorSpan,
            'Factorial operand exceeds the configured limit',
        );
    }

    #[DataProvider('invalidFactorialProvider')]
    public function testFactorialRejectsNegativeAndNonIntegralOperands(
        int|float $operand,
        string $expectedMessage,
    ): void {
        $this->assertEvaluationError(
            fn (): int => $this->numbers->factorial(
                $operand,
                $this->operatorSpan,
            ),
            'eval.domain',
            $this->operatorSpan,
            $expectedMessage,
        );
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function invalidFactorialProvider(): iterable
    {
        yield 'negative integer' => [
            -1,
            'Factorial requires a non-negative integer',
        ];
        yield 'negative integral float' => [
            -1.0,
            'Factorial requires a non-negative integer',
        ];
        yield 'positive fraction' => [
            2.5,
            'Factorial operand must be an integer within the host range',
        ];
        yield 'negative fraction' => [
            -2.5,
            'Factorial operand must be an integer within the host range',
        ];
    }

    #[DataProvider('nonFiniteProvider')]
    public function testEnsureFiniteRejectsEveryNonFiniteValue(float $value): void
    {
        $this->assertEvaluationError(
            fn (): int|float => $this->numbers->ensureFinite(
                $value,
                $this->operatorSpan,
            ),
            'eval.non_finite',
            $this->operatorSpan,
            'Operation produced a non-finite result',
        );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }

    public function testEnsureFinitePreservesFiniteTypesAndNormalizesZero(): void
    {
        self::assertSame(
            \PHP_INT_MAX,
            $this->numbers->ensureFinite(
                \PHP_INT_MAX,
                $this->operatorSpan,
            ),
        );
        self::assertSame(
            1.25,
            $this->numbers->ensureFinite(1.25, $this->operatorSpan),
        );

        $zero = $this->numbers->ensureFinite(-0.0, $this->operatorSpan);

        self::assertPositiveZero($zero);
    }

    private function invokeBinary(
        string $operation,
        int|float $left,
        int|float $right,
    ): int|float {
        return match ($operation) {
            'add' => $this->numbers->add(
                $left,
                $right,
                $this->operatorSpan,
            ),
            'subtract' => $this->numbers->subtract(
                $left,
                $right,
                $this->operatorSpan,
            ),
            'multiply' => $this->numbers->multiply(
                $left,
                $right,
                $this->operatorSpan,
            ),
            default => throw new \LogicException(
                \sprintf('Unknown test operation "%s".', $operation),
            ),
        };
    }

    /**
     * @param \Closure(): (int|float) $operation
     */
    private function assertEvaluationError(
        \Closure $operation,
        string $expectedCode,
        SourceSpan $expectedSpan,
        string $expectedMessage,
    ): void {
        try {
            $operation();
            self::fail(
                \sprintf(
                    'Expected EvaluationException with code "%s".',
                    $expectedCode,
                ),
            );
        } catch (EvaluationException $exception) {
            self::assertSame($expectedCode, $exception->errorCode());
            self::assertSame(
                $expectedSpan->start,
                $exception->span()->start,
            );
            self::assertSame($expectedSpan->end, $exception->span()->end);
            self::assertSame(
                $expectedMessage . ' at position ' . $expectedSpan->start,
                $exception->getMessage(),
            );
        }
    }

    private static function assertPositiveZero(float $value): void
    {
        self::assertSame(0.0, $value);
        self::assertSame(
            '0000000000000000',
            \bin2hex(\pack('E', $value)),
        );
    }

    private static function factorial(int $operand): int
    {
        $result = 1;

        for ($factor = 2; $factor <= $operand; ++$factor) {
            $result *= $factor;
        }

        return $result;
    }
}
