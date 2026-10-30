<?php

declare(strict_types=1);

namespace MathPHP\Tests\Facade;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\FunctionDomainException;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Math;
use MathPHP\Solver\IntegerPolynomialExtractor;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathTest extends TestCase
{
    #[DataProvider('publicResultProvider')]
    public function testPublicResultsHaveTheSpecifiedExactNumericType(
        string $expression,
        int|float $expected,
        string $expectedType,
    ): void {
        $actual = Math::evaluate($expression);

        self::assertSame($expectedType, \get_debug_type($actual));
        if (\is_float($expected)) {
            self::assertEqualsWithDelta($expected, $actual, 1.0E-12);
        } else {
            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{string, int|float, 'int'|'float'}>
     */
    public static function publicResultProvider(): iterable
    {
        yield 'integer literal' => ['42', 42, 'int'];
        yield 'decimal literal' => ['42.0', 42.0, 'float'];
        yield 'integer arithmetic' => ['2 + 3 * 4', 14, 'int'];
        yield 'division is always float' => ['6 / 3', 2.0, 'float'];
        yield 'negative base with an odd rational exponent' => ['(-8)^(1/3)', -2.0, 'float'];
        yield 'decimal integer ratio exponent' => ['(-8)^(1.0/3.0)', -2.0, 'float'];
        yield 'rational exponent is reduced before domain checking' => ['(-64)^(2/6)', -4.0, 'float'];
        yield 'even numerator gives a positive real root' => ['(-8)^(2/3)', 4.0, 'float'];
        yield 'negative odd rational exponent' => ['(-8)^(-1/3)', -0.5, 'float'];
        yield 'exact decimal odd rational exponent' => ['(-32)^0.2', -2.0, 'float'];
        yield 'constant is float' => ['pi', \M_PI, 'float'];
        yield 'tau constant is float' => ['tau', 2 * \M_PI, 'float'];
        yield 'phi constant is float' => ['phi', (1 + \sqrt(5)) / 2, 'float'];
        yield 'unicode multiplication and pi' => ['2 × π', 2 * \M_PI, 'float'];
        yield 'unicode division' => ['6 ÷ 2', 3.0, 'float'];
        yield 'unicode minus' => ['−2 + 5', 3, 'int'];
        yield 'superscript square' => ['2²', 4, 'int'];
        yield 'multi-digit superscript power' => ['2¹⁰', 1024, 'int'];
        yield 'negative superscript power' => ['2⁻³', 0.125, 'float'];
        yield 'superscript power is right associative with caret' => ['2²^3', 256, 'int'];
        yield 'caret is right associative with superscript power' => ['2^3²', 512, 'int'];
        yield 'negative superscript preserves unary precedence' => ['2⁻³^2', 1.0 / 512.0, 'float'];
        yield 'nested negative superscript preserves precedence' => ['2²⁻³^2', 1.0013547198921082, 'float'];
        yield 'unicode square root' => ['√16', 4.0, 'float'];
        yield 'unicode cube root of a negative value' => ['∛(-8)', -2.0, 'float'];
        yield 'base two logarithm' => ['log2(8)', 3.0, 'float'];
        yield 'atan2 geometry' => ['atan2(1, 1)', \M_PI / 4, 'float'];
        yield 'integer gcd' => ['gcd(84, 30)', 6, 'int'];
        yield 'binomial coefficient' => ['binomial(52, 5)', 2_598_960, 'int'];
        yield 'permutation count' => ['permutation(10, 3)', 720, 'int'];
        yield 'double factorial postfix' => ['5!!', 15, 'int'];
        yield 'double factorial function' => ['doublefactorial(6)', 48, 'int'];
        yield 'negative-one double factorial' => ['(-1)!!', 1, 'int'];
        yield 'error function' => ['erf(0)', 0.0, 'float'];
        yield 'imaginary error function' => ['erfi(0)', 0.0, 'float'];
        yield 'scaled complementary error function' => ['erfcx(0)', 1.0, 'float'];
        yield 'Dawson integral' => ['dawson(0)', 0.0, 'float'];
        yield 'Bessel J0' => ['besselj(0, 0)', 1.0, 'float'];
        yield 'Bessel Y0' => ['bessely(0, 1)', 0.08825696421567696, 'float'];
        yield 'modified Bessel I0' => ['besseli(0, 0)', 1.0, 'float'];
        yield 'gamma function' => ['gamma(5)', 24.0, 'float'];
        yield 'regularized lower incomplete gamma' => ['gammainc(1, 2)', 1.0 - \exp(-2.0), 'float'];
        yield 'regularized upper incomplete gamma' => ['gammaincc(1, 2)', \exp(-2.0), 'float'];
        yield 'regularized incomplete beta' => ['betainc(1, 1, 0.4)', 0.4, 'float'];
        yield 'regularized incomplete beta complement' => ['betaincc(1, 1, 0.4)', 0.6, 'float'];
        yield 'beta function' => ['beta(2, 3)', 1.0 / 12.0, 'float'];
        yield 'beta at negative non-integers' => ['beta(-0.5, 1.5)', -\M_PI, 'float'];
        yield 'Riemann zeta at a negative half' => ['zeta(-0.5)', -0.2078862249773546, 'float'];
        yield 'hyperbolic reciprocal identity' => ['sech(0) + coth(1) * tanh(1)', 2.0, 'float'];
        yield 'inverse hyperbolic reciprocal identity' => [
            'acoth(2) + acoth(-2)',
            0.0,
            'float',
        ];
    }

    public function testNegativeBaseRejectsAnEvenDenominatorRationalExponent(): void
    {
        try {
            Math::evaluate('(-8)^(1/2)');
            self::fail('Expected an even-denominator real power to be rejected.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.domain', $error->errorCode());
            self::assertStringStartsWith(
                'A negative base requires an integer exponent',
                $error->getMessage(),
            );
        }
    }

    public function testGreekVariableAliasesResolveAgainstPortableNames(): void
    {
        self::assertSame(13, Math::evaluate('α² + λ', ['alpha' => 2, 'lambda' => 9]));
    }

    public function testAtan2UsesScaledPolynomialCoordinatesAcrossFloatRange(): void
    {
        self::assertEqualsWithDelta(
            1.0E-200,
            Math::evaluate('atan2(x^2, x)', ['x' => 1.0E-200]),
            5.0E-214,
        );
        self::assertEqualsWithDelta(
            1.0E-200,
            Math::evaluate('atan2(x^2 + x^4, x)', ['x' => 1.0E-200]),
            5.0E-214,
        );
        self::assertEqualsWithDelta(
            -1.0E-200,
            Math::evaluate('atan2(x^2 - 2*x^2, x)', ['x' => 1.0E-200]),
            2.0E-213,
        );
        self::assertSame(
            0.0,
            Math::evaluate('atan2(x^2 - x^2, x)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            \M_PI,
            Math::evaluate('atan2(x^2, x)', ['x' => -1.0E-200]),
        );
        self::assertSame(
            \M_PI,
            Math::evaluate('atan2(x^3, -2*x^2)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            \M_PI_2,
            Math::evaluate('atan2(x^2, 0)', ['x' => 1.0E-200]),
        );
        self::assertSame(
            0.0,
            Math::evaluate('atan2(0, x^2)', ['x' => 1.0E-200]),
        );
        self::assertEqualsWithDelta(
            \atan(0.5),
            Math::evaluate('atan2(x^2, 2*x^2)', ['x' => 1.0E200]),
            5.0E-14,
        );
        self::assertSame(
            \M_PI_2,
            Math::evaluate('atan2(x^3, -2*x^2)', ['x' => 1.0E200]),
        );
        self::assertSame(\M_PI_4, Math::evaluate('atan2(2^64, 2^64)'));
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(sqrt(x^2), x)', ['x' => 1.0E-200]),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(cbrt(x^3), x)', ['x' => 1.0E-200]),
            1.0E-13,
        );
        self::assertEqualsWithDelta(
            \atan(\M_E),
            Math::evaluate('atan2(exp(-1000), exp(-1001))'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan(1.0 / \M_E),
            Math::evaluate('atan2(exp(1000), exp(1001))'),
            1.0E-14,
        );
    }

    public function testScaledAtan2SupportsIntegerRootsAndPreservesRealDomains(): void
    {
        self::assertEqualsWithDelta(
            \M_PI_4,
            Math::evaluate('atan2(root(x^4, 4), x)', ['x' => 1.0E-100]),
            1.0E-13,
        );

        foreach (['sqrt(-x^2)', 'root(-x^2, 2)'] as $expression) {
            try {
                Math::evaluate('atan2(' . $expression . ', 1)', ['x' => 1.0E-200]);
                self::fail('A negative radicand must remain outside the real domain.');
            } catch (EvaluationException $error) {
                self::assertSame('eval.domain', $error->errorCode());
                self::assertInstanceOf(\DomainException::class, $error->getPrevious());
            }
        }
    }

    public function testScaledAtan2SupportsLogarithmsBeyondFloatRange(): void
    {
        self::assertEqualsWithDelta(
            \atan2(-1000.0, 1.0),
            Math::evaluate('atan2(ln(exp(-1000)), 1)'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan2(-1000.0 / \log(2.0), 1.0),
            Math::evaluate('atan2(log2(exp(-1000)), 1)'),
            1.0E-14,
        );
        self::assertEqualsWithDelta(
            \atan2(1000.0, 1.0),
            Math::evaluate('atan2(log(exp(1000), exp(1)), 1)'),
            1.0E-14,
        );

        try {
            Math::evaluate('atan2(ln(-exp(-1000)), 1)');
            self::fail('A negative logarithm argument must remain outside the real domain.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.domain', $error->errorCode());
            self::assertInstanceOf(\DomainException::class, $error->getPrevious());
        }
    }

    public function testScaledAtan2CarriesTinyElementaryFunctionValues(): void
    {
        foreach (['sin', 'tan', 'sinh', 'tanh', 'asin', 'asinh', 'atan', 'atanh'] as $function) {
            self::assertEqualsWithDelta(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), exp(-1000))',
                ),
                1.0E-14,
                $function,
            );
        }

        foreach (['csc', 'cot', 'csch', 'coth'] as $function) {
            self::assertEqualsWithDelta(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), exp(1000))',
                ),
                1.0E-14,
                $function,
            );
        }

        foreach (['sec', 'sinc', 'sincpi'] as $function) {
            self::assertSame(
                \M_PI_4,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)), 1)',
                ),
                $function,
            );
        }

        $functionSigns = [
            'sin' => -1,
            'tan' => 1,
            'sinh' => 1,
            'tanh' => -1,
            'asin' => 1,
            'asinh' => -1,
            'atan' => -1,
            'atanh' => 1,
        ];
        foreach ($functionSigns as $function => $sign) {
            self::assertSame(
                $sign < 0 ? -\M_PI_2 : \M_PI_2,
                Math::evaluate(
                    'atan2(' . $function . '(exp(-1000)) - exp(-1000), exp(-4000))',
                ),
                $function,
            );
        }
    }

    public function testBetaOverflowAtTinyPositiveArgumentIsReportedAsEvaluationError(): void
    {
        foreach (['beta(5e-324, 1)', 'beta(1, 5e-324)'] as $expression) {
            try {
                Math::evaluate($expression);
                self::fail('The beta value is larger than the finite floating-point range.');
            } catch (EvaluationException $error) {
                self::assertSame('eval.integer_overflow', $error->errorCode());
            }
        }
    }

    public function testVariablesAndConstantsAreResolvedCaseSensitively(): void
    {
        $result = Math::evaluate(
            'radius * Pi + pi + E + e',
            [
                'radius' => 2,
                'Pi' => 3,
                'E' => 4.0,
            ],
        );

        self::assertEqualsWithDelta(
            (2 * 3) + \M_PI + 4.0 + \M_E,
            $result,
            1.0E-12,
        );
    }

    public function testRegisteredFunctionTakesPrecedenceOverSameNamedVariable(): void
    {
        $function = new FunctionDefinition(
            'scale',
            1,
            1,
            static fn (array $arguments): int|float => 3 * $arguments[0],
        );

        self::assertSame(
            6,
            Math::evaluate(
                'scale(2)',
                ['scale' => 10],
                self::optionsWith($function),
            ),
        );
    }

    /**
     * @param array<string, int|float> $variables
     */
    #[DataProvider('invalidFacadeVariableProvider')]
    public function testInvalidHostVariablesAreRejectedBeforeExpressionProcessing(
        array $variables,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Math::evaluate('@', $variables);
    }

    /**
     * @return iterable<string, array{array<string, int|float>, string}>
     */
    public static function invalidFacadeVariableProvider(): iterable
    {
        yield 'invalid name' => [
            ['not-valid' => 1],
            'Every variable name must be a valid ASCII identifier.',
        ];
        yield 'reserved pi' => [
            ['pi' => 3],
            'The constant name "pi" is reserved and cannot be overridden.',
        ];
        yield 'reserved e' => [
            ['e' => 3],
            'The constant name "e" is reserved and cannot be overridden.',
        ];
        yield 'positive infinity' => [
            ['value' => \INF],
            'Variable "value" must have a finite integer or float value.',
        ];
        yield 'negative infinity' => [
            ['value' => -\INF],
            'Variable "value" must have a finite integer or float value.',
        ];
        yield 'NaN' => [
            ['value' => \NAN],
            'Variable "value" must have a finite integer or float value.',
        ];
    }

    public function testUnknownVariableHasTheIdentifierSpan(): void
    {
        try {
            Math::evaluate('1 + missing * 2');
            self::fail('An unknown variable should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
            self::assertSame(4, $error->span()->start);
            self::assertSame(11, $error->span()->end);
            self::assertSame(
                'Unknown variable "missing" at position 4',
                $error->getMessage(),
            );
        }
    }

    #[DataProvider('unknownFunctionProvider')]
    public function testOnlyExplicitCaseSensitiveFunctionsCanBeCalled(
        string $expression,
        int $spanStart,
        int $spanEnd,
    ): void {
        try {
            Math::evaluate($expression);
            self::fail('An unregistered function should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame($spanStart, $error->span()->start);
            self::assertSame($spanEnd, $error->span()->end);
            self::assertStringContainsString(
                'Unknown function',
                $error->getMessage(),
            );
            self::assertStringEndsWith(
                \sprintf('at position %d', $spanStart),
                $error->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function unknownFunctionProvider(): iterable
    {
        yield 'ordinary unknown function' => ['1 + mystery(2)', 4, 11];
        yield 'built-in with wrong case' => ['Sqrt(4)', 0, 4];
        yield 'PHP function is not dynamically dispatched' => [
            'phpversion()',
            0,
            10,
        ];
        yield 'dangerous name is not dynamically dispatched' => [
            'system(1)',
            0,
            6,
        ];
    }

    public function testCustomFunctionReceivesEvaluatedArgumentsInOrder(): void
    {
        $received = null;
        $weighted = new FunctionDefinition(
            'weighted',
            3,
            3,
            static function (array $arguments) use (&$received): float {
                $received = $arguments;

                return $arguments[0] + ($arguments[1] * $arguments[2]);
            },
        );
        $options = self::optionsWith($weighted);

        $result = Math::evaluate(
            'weighted(x + 1, 2.5, 4)',
            ['x' => 3],
            $options,
        );

        self::assertSame([4, 2.5, 4], $received);
        self::assertSame(14.0, $result);
    }

    public function testCustomFunctionResultsPreserveIntegerAndFloatTypes(): void
    {
        $integer = new FunctionDefinition(
            'integer',
            0,
            0,
            static fn (array $arguments): int => 7 + \count($arguments),
        );
        $decimal = new FunctionDefinition(
            'decimal',
            0,
            0,
            static fn (array $arguments): float => 7.0 + \count($arguments),
        );
        $registry = FunctionRegistry::defaults()
            ->with($integer)
            ->with($decimal);
        $options = new EvaluationOptions(functions: $registry);

        self::assertSame(7, Math::evaluate('integer()', options: $options));
        self::assertSame(7.0, Math::evaluate('decimal()', options: $options));
    }

    public function testFunctionArityIsCheckedBeforeAnyArgumentsAreEvaluated(): void
    {
        try {
            Math::evaluate('sqrt(missing, 1 / 0)');
            self::fail('Wrong arity should fail before argument evaluation.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.arity', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(
                \strlen('sqrt(missing, 1 / 0)'),
                $error->span()->end,
            );
            self::assertSame(
                'Function "sqrt" expects exactly 1 argument but received 2 at position 0',
                $error->getMessage(),
            );
        }
    }

    public function testUnknownFunctionIsResolvedBeforeItsArguments(): void
    {
        try {
            Math::evaluate('missing_function(unknown_variable, 1 / 0)');
            self::fail('Function resolution should precede argument evaluation.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen('missing_function'), $error->span()->end);
        }
    }

    public function testArgumentsAreEvaluatedFromLeftToRightBeforeInvocation(): void
    {
        $order = [];
        $first = new FunctionDefinition(
            'first',
            0,
            0,
            static function (array $arguments) use (&$order): int {
                self::assertSame([], $arguments);
                $order[] = 'first';

                return 1;
            },
        );
        $second = new FunctionDefinition(
            'second',
            0,
            0,
            static function (array $arguments) use (&$order): int {
                self::assertSame([], $arguments);
                $order[] = 'second';

                return 2;
            },
        );
        $combine = new FunctionDefinition(
            'combine',
            2,
            2,
            static function (array $arguments) use (&$order): int|float {
                $order[] = 'combine';

                return $arguments[0] + $arguments[1];
            },
        );
        $registry = FunctionRegistry::defaults()
            ->with($first)
            ->with($second)
            ->with($combine);

        $result = Math::evaluate(
            'combine(first(), second())',
            options: new EvaluationOptions(functions: $registry),
        );

        self::assertSame(3, $result);
        self::assertSame(['first', 'second', 'combine'], $order);
    }

    public function testArgumentFailurePreventsCallbackInvocation(): void
    {
        $invoked = false;
        $combine = new FunctionDefinition(
            'combine',
            2,
            2,
            static function (array $arguments) use (&$invoked): int|float {
                $invoked = true;

                return $arguments[0] + $arguments[1];
            },
        );

        try {
            Math::evaluate(
                'combine(missing, 1 / 0)',
                options: self::optionsWith($combine),
            );
            self::fail('The first argument should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
            self::assertSame(8, $error->span()->start);
            self::assertSame(15, $error->span()->end);
            self::assertFalse($invoked);
        }
    }

    #[DataProvider('customThrowableProvider')]
    public function testCustomCallbackErrorsAreWrappedAtTheCompleteCallSpan(
        \Throwable $failure,
    ): void {
        $function = new FunctionDefinition(
            'fail',
            1,
            1,
            static function (array $arguments) use ($failure): never {
                self::assertSame([3], $arguments);

                throw $failure;
            },
        );
        $expression = '1 + fail(1 + 2)';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('The custom callback should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(4, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertSame(
                'Custom function "fail" failed at position 4',
                $error->getMessage(),
            );
            self::assertSame($failure, $error->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function customThrowableProvider(): iterable
    {
        yield 'runtime failure' => [new \RuntimeException('runtime')];
        yield 'domain failure' => [new \DomainException('domain')];
        yield 'overflow failure' => [new \OverflowException('overflow')];
        yield 'type failure' => [new \TypeError('type')];
    }

    public function testRegistryOwnedProvenanceKeepsCustomDomainFailuresCustom(): void
    {
        $failure = new \DomainException('custom domain');
        $spoof = new FunctionDefinition(
            'spoof',
            0,
            0,
            static function (array $arguments) use ($failure): never {
                self::assertSame([], $arguments);

                throw $failure;
            },
        );
        $registry = FunctionRegistry::defaults()->with($spoof);

        self::assertFalse($registry->isBuiltIn('spoof'));

        try {
            Math::evaluate(
                'spoof()',
                options: new EvaluationOptions(functions: $registry),
            );
            self::fail('A custom domain failure must remain custom.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(7, $error->span()->end);
            self::assertSame($failure, $error->getPrevious());
        }
    }

    public function testCustomFunctionCanSignalAnUndefinedDomain(): void
    {
        $function = new FunctionDefinition(
            'rightdomain',
            1,
            1,
            static function (array $arguments): float {
                if ($arguments[0] <= 0.123) {
                    throw new FunctionDomainException('outside domain');
                }

                return $arguments[0] - 0.123;
            },
        );

        try {
            Math::evaluate('rightdomain(0.1)', options: self::optionsWith($function));
            self::fail('The custom function should reject the input domain.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function_domain', $error->errorCode());
            self::assertSame('outside domain at position 0', $error->getMessage());
            self::assertInstanceOf(FunctionDomainException::class, $error->getPrevious());
        }
    }

    /**
     * @param class-string<\Throwable> $previousClass
     */
    #[DataProvider('invalidCustomResultProvider')]
    public function testInvalidCustomResultsAreWrapped(
        \Closure $callback,
        string $previousClass,
    ): void {
        $function = new FunctionDefinition('invalid', 0, 0, $callback);
        $expression = 'invalid()';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('An invalid custom result should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertInstanceOf($previousClass, $error->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{
     *     \Closure(list<int|float>): mixed,
     *     class-string<\Throwable>
     * }>
     */
    public static function invalidCustomResultProvider(): iterable
    {
        yield 'string' => [
            static fn (array $arguments): string => (string) \count($arguments),
            \UnexpectedValueException::class,
        ];
        yield 'boolean' => [
            static fn (array $arguments): bool => $arguments === [],
            \UnexpectedValueException::class,
        ];
        yield 'null' => [
            static fn (array $arguments): mixed => $arguments[0] ?? null,
            \UnexpectedValueException::class,
        ];
    }

    #[DataProvider('nonFiniteCustomResultProvider')]
    public function testNonFiniteCustomResultsMapToCustomFunctionFailure(
        float $result,
    ): void {
        $function = new FunctionDefinition(
            'nonfinite',
            0,
            0,
            static fn (array $arguments): float => $result + \count($arguments),
        );
        $expression = 'nonfinite()';

        try {
            Math::evaluate($expression, options: self::optionsWith($function));
            self::fail('A non-finite custom result should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.custom_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            self::assertInstanceOf(
                EvaluationException::class,
                $error->getPrevious(),
            );
            self::assertSame(
                'eval.non_finite',
                $error->getPrevious()->errorCode(),
            );
        }
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteCustomResultProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'NaN' => [\NAN];
    }

    /**
     * @param array<string, int|float> $variables
     * @param class-string<\Throwable>|null $previousClass
     */
    #[DataProvider('builtInFailureProvider')]
    public function testBuiltInFailuresMapToStableEvaluationErrors(
        string $expression,
        array $variables,
        string $expectedCode,
        ?string $previousClass,
    ): void {
        try {
            Math::evaluate($expression, $variables);
            self::fail('The built-in function should fail.');
        } catch (EvaluationException $error) {
            self::assertSame($expectedCode, $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(\strlen($expression), $error->span()->end);
            if ($previousClass === null) {
                self::assertNull($error->getPrevious());
            } else {
                self::assertInstanceOf($previousClass, $error->getPrevious());
            }
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, int|float>,
     *     string,
     *     class-string<\Throwable>|null
     * }>
     */
    public static function builtInFailureProvider(): iterable
    {
        yield 'sqrt domain' => [
            'sqrt(-1)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'ln domain' => [
            'ln(0)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'log domain' => [
            'log(10, 1)',
            [],
            'eval.domain',
            \DomainException::class,
        ];
        yield 'absolute integer overflow' => [
            'abs(value)',
            ['value' => \PHP_INT_MIN],
            'eval.integer_overflow',
            \OverflowException::class,
        ];
        yield 'exponential non-finite output' => [
            'exp(10000)',
            [],
            'eval.non_finite',
            null,
        ];
    }

    public function testNegativeZeroFromVariablesAndCustomFunctionsIsNormalized(): void
    {
        $negativeZero = -0.0;
        $function = new FunctionDefinition(
            'negative_zero',
            0,
            0,
            static fn (array $arguments): float => $negativeZero
                + \count($arguments),
        );

        $variableResult = Math::evaluate('value', ['value' => $negativeZero]);
        $customResult = Math::evaluate(
            'negative_zero()',
            options: self::optionsWith($function),
        );

        self::assertSame(0.0, $variableResult);
        self::assertSame(0.0, $customResult);
        self::assertSame('d:0;', \serialize($variableResult));
        self::assertSame('d:0;', \serialize($customResult));
    }

    public function testSequentialVariableEvaluationsAreIsolated(): void
    {
        self::assertSame(3, Math::evaluate('value + 1', ['value' => 2]));
        self::assertSame(10, Math::evaluate('value + 1', ['value' => 9]));

        try {
            Math::evaluate('value + 1');
            self::fail('A prior variable map must not leak.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
        }
    }

    public function testSequentialAndInterleavedRegistriesAreIsolated(): void
    {
        $first = self::optionsWith(
            new FunctionDefinition(
                'value',
                0,
                0,
                static fn (array $arguments): int => 1 + \count($arguments),
            ),
        );
        $second = self::optionsWith(
            new FunctionDefinition(
                'value',
                0,
                0,
                static fn (array $arguments): int => 2 + \count($arguments),
            ),
        );

        self::assertSame(1, Math::evaluate('value()', options: $first));
        self::assertSame(2, Math::evaluate('value()', options: $second));
        self::assertSame(1, Math::evaluate('value()', options: $first));

        try {
            Math::evaluate('value()');
            self::fail('A custom registry must not leak into default options.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
        }
    }

    public function testNestedEvaluationsDoNotLeakVariablesOrFunctions(): void
    {
        $innerFunction = new FunctionDefinition(
            'inner',
            0,
            0,
            static fn (array $arguments): int => 7 + \count($arguments),
        );
        $innerOptions = self::optionsWith($innerFunction);
        $outerFunction = new FunctionDefinition(
            'outer',
            0,
            0,
            static fn (array $arguments): int|float => Math::evaluate(
                'inner() + value',
                ['value' => 10 + \count($arguments)],
                $innerOptions,
            ),
        );
        $outerOptions = self::optionsWith($outerFunction);

        self::assertSame(
            18,
            Math::evaluate('outer() + value', ['value' => 1], $outerOptions),
        );

        try {
            Math::evaluate('inner() + value', ['value' => 1], $outerOptions);
            self::fail('The nested registry must not leak into its caller.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_function', $error->errorCode());
            self::assertSame(0, $error->span()->start);
            self::assertSame(5, $error->span()->end);
        }
    }

    public function testAReusedOptionsObjectCarriesNoEvaluationState(): void
    {
        $identity = new FunctionDefinition(
            'identity',
            1,
            1,
            static fn (array $arguments): int|float => $arguments[0],
        );
        $options = self::optionsWith($identity);

        self::assertSame(
            1,
            Math::evaluate('identity(value)', ['value' => 1], $options),
        );
        self::assertSame(
            2.5,
            Math::evaluate('identity(value)', ['value' => 2.5], $options),
        );
    }

    public function testFacadeUsesTheConfiguredExponentLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxExponentMagnitude: 2),
        );

        self::assertSame(4, Math::evaluate('2^2', options: $options));

        try {
            Math::evaluate('2^3', options: $options);
            self::fail('The exponent should exceed the configured limit.');
        } catch (EvaluationException $error) {
            self::assertSame('limit.exponent', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testFacadeUsesTheConfiguredFactorialLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxFactorial: 3),
        );

        self::assertSame(6, Math::evaluate('3!', options: $options));

        try {
            Math::evaluate('4!', options: $options);
            self::fail('The factorial should exceed the configured limit.');
        } catch (EvaluationException $error) {
            self::assertSame('limit.factorial', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testFacadeUsesTheConfiguredExpressionLengthLimit(): void
    {
        $options = new EvaluationOptions(
            limits: new ResourceLimits(maxExpressionLength: 1),
        );

        self::assertSame(1, Math::evaluate('1', options: $options));

        try {
            Math::evaluate('12', options: $options);
            self::fail('The expression should exceed the configured limit.');
        } catch (\MathPHP\Exception\LexicalException $error) {
            self::assertSame('limit.expression_length', $error->errorCode());
            self::assertSame(1, $error->span()->start);
            self::assertSame(2, $error->span()->end);
        }
    }

    public function testMathFacadeIsFinalAndHasNoMutableStaticProperties(): void
    {
        $reflection = new \ReflectionClass(Math::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC));
    }

    private static function optionsWith(
        FunctionDefinition $definition,
    ): EvaluationOptions {
        return new EvaluationOptions(
            functions: FunctionRegistry::defaults()->with($definition),
        );
    }
}
