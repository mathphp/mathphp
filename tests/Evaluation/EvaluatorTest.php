<?php

declare(strict_types=1);

namespace MathPHP\Tests\Evaluation;

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
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private const FLOAT_TOLERANCE = 1.0E-12;

    public function testLeafAndGroupingNodesPreserveTheirSpecifiedTypes(): void
    {
        $evaluator = self::evaluator(
            [
                'integer' => 17,
                'decimal' => 2.5,
            ],
        );

        self::assertSame(42, $evaluator->evaluate(self::number(42)));
        self::assertSame(42.0, $evaluator->evaluate(self::number(42.0)));
        self::assertSame(
            17,
            $evaluator->evaluate(
                new VariableNode(
                    'integer',
                    new SourceSpan(3, 10),
                ),
            ),
        );
        self::assertSame(
            2.5,
            $evaluator->evaluate(
                new VariableNode(
                    'decimal',
                    new SourceSpan(3, 10),
                ),
            ),
        );
        self::assertSame(
            7,
            $evaluator->evaluate(
                new GroupingNode(
                    self::number(7, 5, 6),
                    new SourceSpan(4, 7),
                ),
            ),
        );
    }

    public function testConstantsEvaluateToFiniteFloats(): void
    {
        $evaluator = self::evaluator();

        $pi = $evaluator->evaluate(
            new ConstantNode('pi', new SourceSpan(0, 2)),
        );
        $e = $evaluator->evaluate(
            new ConstantNode('e', new SourceSpan(0, 1)),
        );

        self::assertSame(\M_PI, $pi);
        self::assertSame(\M_E, $e);
        self::assertSame('float', \get_debug_type($pi));
        self::assertSame('float', \get_debug_type($e));
    }

    public function testFinalResultNormalizationRemovesVariableSignedZero(): void
    {
        $evaluator = self::evaluator(['negative_zero' => -0.0]);
        $result = $evaluator->evaluate(
            new VariableNode(
                'negative_zero',
                new SourceSpan(0, 13),
            ),
        );

        self::assertSame('float', \get_debug_type($result));
        self::assertSame(0.0, $result);
        self::assertSame(
            '0000000000000000',
            \bin2hex(\pack('E', $result)),
        );
    }

    #[DataProvider('unaryOperationProvider')]
    public function testEveryUnaryOperatorIsDispatchedWithExactTypes(
        UnaryOperator $operator,
        int|float $operand,
        int|float $expected,
        string $expectedType,
    ): void {
        $node = new UnaryOperationNode(
            $operator,
            self::number($operand, 4, 7),
            new SourceSpan(3, 4),
        );
        $actual = self::evaluator()->evaluate($node);

        self::assertSame($expected, $actual);
        self::assertSame($expectedType, \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{
     *     UnaryOperator,
     *     int|float,
     *     int|float,
     *     'int'|'float'
     * }>
     */
    public static function unaryOperationProvider(): iterable
    {
        yield 'positive integer' => [
            UnaryOperator::Plus,
            4,
            4,
            'int',
        ];
        yield 'positive float' => [
            UnaryOperator::Plus,
            4.0,
            4.0,
            'float',
        ];
        yield 'negative integer' => [
            UnaryOperator::Minus,
            4,
            -4,
            'int',
        ];
        yield 'negative float' => [
            UnaryOperator::Minus,
            4.5,
            -4.5,
            'float',
        ];
    }

    #[DataProvider('binaryOperationProvider')]
    public function testEveryBinaryOperatorIsDispatchedWithExactTypes(
        BinaryOperator $operator,
        int|float $left,
        int|float $right,
        int|float $expected,
        string $expectedType,
    ): void {
        $node = new BinaryOperationNode(
            $operator,
            self::number($left, 0, 1),
            self::number($right, 2, 3),
            new SourceSpan(1, 2),
        );
        $actual = self::evaluator()->evaluate($node);

        self::assertSame($expected, $actual);
        self::assertSame($expectedType, \get_debug_type($actual));
    }

    /**
     * @return iterable<string, array{
     *     BinaryOperator,
     *     int|float,
     *     int|float,
     *     int|float,
     *     'int'|'float'
     * }>
     */
    public static function binaryOperationProvider(): iterable
    {
        yield 'addition' => [BinaryOperator::Add, 2, 3, 5, 'int'];
        yield 'subtraction' => [
            BinaryOperator::Subtract,
            2.0,
            3,
            -1.0,
            'float',
        ];
        yield 'multiplication' => [
            BinaryOperator::Multiply,
            -2,
            3,
            -6,
            'int',
        ];
        yield 'division' => [
            BinaryOperator::Divide,
            6,
            3,
            2.0,
            'float',
        ];
        yield 'modulo' => [
            BinaryOperator::Modulo,
            -7,
            3,
            -1,
            'int',
        ];
        yield 'power' => [
            BinaryOperator::Power,
            2,
            10,
            1024,
            'int',
        ];
    }

    public function testFactorialNodeDispatchesToExactFactorial(): void
    {
        $node = new FactorialNode(
            self::number(5, 0, 1),
            new SourceSpan(1, 2),
        );

        self::assertSame(120, self::evaluator()->evaluate($node));
    }

    public function testUnknownVariableUsesTheIdentifierSpan(): void
    {
        $span = new SourceSpan(8, 15);
        $node = new VariableNode('missing', $span);

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.unknown_variable',
            $span,
            'Unknown variable "missing"',
        );
    }

    public function testUnaryOverflowUsesTheOperatorSpan(): void
    {
        $operatorSpan = new SourceSpan(2, 3);
        $node = new UnaryOperationNode(
            UnaryOperator::Minus,
            self::number(\PHP_INT_MIN, 3, 22),
            $operatorSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.integer_overflow',
            $operatorSpan,
            'Unary negation exceeds the host integer range',
        );
    }

    #[DataProvider('binaryErrorProvider')]
    public function testBinaryFailuresUseStableCodesAndOperatorSpans(
        BinaryOperator $operator,
        int|float $left,
        int|float $right,
        ResourceLimits $limits,
        string $expectedCode,
        string $expectedMessage,
    ): void {
        $operatorSpan = new SourceSpan(11, 12);
        $node = new BinaryOperationNode(
            $operator,
            self::number($left, 0, 10),
            self::number($right, 12, 20),
            $operatorSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator(
                limits: $limits,
            )->evaluate($node),
            $expectedCode,
            $operatorSpan,
            $expectedMessage,
        );
    }

    /**
     * @return iterable<string, array{
     *     BinaryOperator,
     *     int|float,
     *     int|float,
     *     ResourceLimits,
     *     string,
     *     string
     * }>
     */
    public static function binaryErrorProvider(): iterable
    {
        yield 'addition overflow' => [
            BinaryOperator::Add,
            \PHP_INT_MAX,
            1,
            new ResourceLimits(),
            'eval.integer_overflow',
            'Integer addition exceeds the host integer range',
        ];
        yield 'division by zero' => [
            BinaryOperator::Divide,
            1,
            -0.0,
            new ResourceLimits(),
            'eval.division_by_zero',
            'Division by zero',
        ];
        yield 'modulo domain' => [
            BinaryOperator::Modulo,
            1.5,
            1,
            new ResourceLimits(),
            'eval.domain',
            'Modulo dividend must be an integer within the host range',
        ];
        yield 'modulo by zero' => [
            BinaryOperator::Modulo,
            1,
            0,
            new ResourceLimits(),
            'eval.modulo_by_zero',
            'Modulo by zero',
        ];
        yield 'power domain' => [
            BinaryOperator::Power,
            -2,
            0.5,
            new ResourceLimits(),
            'eval.domain',
            'A negative base requires an integer exponent',
        ];
        yield 'power overflow' => [
            BinaryOperator::Power,
            2,
            63,
            new ResourceLimits(),
            'eval.integer_overflow',
            'Integer exponentiation exceeds the host integer range',
        ];
        yield 'exponent limit' => [
            BinaryOperator::Power,
            2,
            5,
            new ResourceLimits(maxExponentMagnitude: 4),
            'limit.exponent',
            'Exponent exceeds the configured magnitude limit',
        ];
    }

    public function testFactorialFailuresUseTheFactorialTokenSpan(): void
    {
        $operatorSpan = new SourceSpan(6, 7);
        $node = new FactorialNode(
            self::number(-1, 4, 6),
            $operatorSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.domain',
            $operatorSpan,
            'Factorial requires a non-negative integer',
        );
    }

    public function testEvaluationVisitsBinaryOperandsLeftToRight(): void
    {
        $leftSpan = new SourceSpan(0, 4);
        $rightSpan = new SourceSpan(7, 12);
        $node = new BinaryOperationNode(
            BinaryOperator::Add,
            new VariableNode('left', $leftSpan),
            new VariableNode('right', $rightSpan),
            new SourceSpan(5, 6),
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.unknown_variable',
            $leftSpan,
            'Unknown variable "left"',
        );
    }

    /**
     * @param list<int|float> $arguments
     */
    #[DataProvider('builtInSuccessProvider')]
    public function testEveryBuiltInEvaluatesThroughTheAllowlist(
        string $name,
        array $arguments,
        int|float $expected,
        string $expectedType,
    ): void {
        $node = self::call($name, $arguments);
        $actual = self::evaluator()->evaluate($node);

        self::assertSame($expectedType, \get_debug_type($actual));

        if (\is_float($expected)) {
            self::assertFloatConforms($expected, $actual);
        } else {
            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<int|float>,
     *     int|float,
     *     'int'|'float'
     * }>
     */
    public static function builtInSuccessProvider(): iterable
    {
        yield 'absolute integer' => ['abs', [-4], 4, 'int'];
        yield 'absolute float' => ['abs', [-4.5], 4.5, 'float'];
        yield 'square root lower boundary' => [
            'sqrt',
            [0],
            0.0,
            'float',
        ];
        yield 'square root' => ['sqrt', [9], 3.0, 'float'];
        yield 'sine' => ['sin', [\M_PI / 2], 1.0, 'float'];
        yield 'cosine' => ['cos', [0], 1.0, 'float'];
        yield 'exponential' => ['exp', [1], \M_E, 'float'];
        yield 'exponential underflow' => ['exp', [-1000], 0.0, 'float'];
        yield 'natural logarithm' => ['ln', [\M_E], 1.0, 'float'];
        yield 'logarithm' => ['log', [100, 10], 2.0, 'float'];
        yield 'floor' => ['floor', [-1.2], -2.0, 'float'];
        yield 'ceiling' => ['ceil', [-1.2], -1.0, 'float'];
        yield 'round positive halfway' => [
            'round',
            [2.5],
            3.0,
            'float',
        ];
        yield 'round negative halfway' => [
            'round',
            [-2.5],
            -3.0,
            'float',
        ];
    }

    /**
     * @param list<int|float>          $arguments
     * @param class-string<\Throwable> $expectedPreviousClass
     */
    #[DataProvider('builtInDomainProvider')]
    public function testEveryBuiltInDomainFailureUsesTheCompleteCallSpan(
        string $name,
        array $arguments,
        string $expectedCode,
        string $expectedMessage,
        string $expectedPreviousClass,
    ): void {
        $callSpan = new SourceSpan(2, 24);
        $node = self::call(
            $name,
            $arguments,
            new SourceSpan(2, 2 + \strlen($name)),
            $callSpan,
        );

        $exception = $this->captureEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            $expectedCode,
            $callSpan,
            $expectedMessage,
        );

        self::assertInstanceOf(
            $expectedPreviousClass,
            $exception->getPrevious(),
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<int|float>,
     *     string,
     *     string,
     *     class-string<\Throwable>
     * }>
     */
    public static function builtInDomainProvider(): iterable
    {
        yield 'absolute integer overflow' => [
            'abs',
            [\PHP_INT_MIN],
            'eval.integer_overflow',
            'The absolute value of PHP_INT_MIN is not representable as an integer.',
            \OverflowException::class,
        ];
        yield 'negative square root' => [
            'sqrt',
            [-1],
            'eval.domain',
            'sqrt() requires a non-negative argument.',
            \DomainException::class,
        ];
        yield 'natural logarithm zero' => [
            'ln',
            [0],
            'eval.domain',
            'ln() requires a positive argument.',
            \DomainException::class,
        ];
        yield 'natural logarithm negative' => [
            'ln',
            [-1],
            'eval.domain',
            'ln() requires a positive argument.',
            \DomainException::class,
        ];
        yield 'logarithm value zero' => [
            'log',
            [0, 2],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
        yield 'logarithm value negative' => [
            'log',
            [-1, 2],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
        yield 'logarithm base zero' => [
            'log',
            [2, 0],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
        yield 'logarithm base negative' => [
            'log',
            [2, -1],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
        yield 'logarithm integer base one' => [
            'log',
            [2, 1],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
        yield 'logarithm float base one' => [
            'log',
            [2, 1.0],
            'eval.domain',
            'log() requires a positive value and a positive base other than one.',
            \DomainException::class,
        ];
    }

    public function testBuiltInNonFiniteResultUsesTheCompleteCallSpan(): void
    {
        $callSpan = new SourceSpan(9, 17);
        $node = self::call(
            'exp',
            [710],
            new SourceSpan(9, 12),
            $callSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.non_finite',
            $callSpan,
            'Operation produced a non-finite result',
        );
    }

    /**
     * @param list<int|float> $arguments
     */
    #[DataProvider('wrongBuiltInArityProvider')]
    public function testEveryBuiltInRejectsBothWrongArityBoundaries(
        string $name,
        array $arguments,
        string $expectation,
    ): void {
        $callSpan = new SourceSpan(4, 30);
        $node = self::call(
            $name,
            $arguments,
            new SourceSpan(4, 4 + \strlen($name)),
            $callSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.arity',
            $callSpan,
            \sprintf(
                'Function "%s" expects %s but received %d',
                $name,
                $expectation,
                \count($arguments),
            ),
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<int|float>,
     *     string
     * }>
     */
    public static function wrongBuiltInArityProvider(): iterable
    {
        $unaryFunctions = [
            'abs',
            'sqrt',
            'sin',
            'cos',
            'exp',
            'ln',
            'floor',
            'ceil',
            'round',
        ];

        foreach ($unaryFunctions as $name) {
            yield $name . ' too few' => [
                $name,
                [],
                'exactly 1 argument',
            ];
            yield $name . ' too many' => [
                $name,
                [1, 2],
                'exactly 1 argument',
            ];
        }

        yield 'log too few' => [
            'log',
            [10],
            'exactly 2 arguments',
        ];
        yield 'log too many' => [
            'log',
            [10, 10, 10],
            'exactly 2 arguments',
        ];
    }

    #[DataProvider('unknownFunctionProvider')]
    public function testUnknownAndCaseMismatchedFunctionsUseTheNameSpan(
        string $name,
    ): void {
        $nameSpan = new SourceSpan(6, 6 + \strlen($name));
        $node = self::call(
            $name,
            [],
            $nameSpan,
            new SourceSpan(6, 20),
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.unknown_function',
            $nameSpan,
            \sprintf('Unknown function "%s"', $name),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownFunctionProvider(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'case-mismatched built-in' => ['Sqrt'];
        yield 'host function absent from allowlist' => ['system'];
    }

    public function testFunctionResolutionPrecedesArgumentEvaluation(): void
    {
        $nameSpan = new SourceSpan(1, 8);
        $argumentSpan = new SourceSpan(9, 16);
        $node = new FunctionCallNode(
            'missing',
            [new VariableNode('unknown', $argumentSpan)],
            $nameSpan,
            new SourceSpan(1, 17),
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.unknown_function',
            $nameSpan,
            'Unknown function "missing"',
        );
    }

    public function testFunctionArityPrecedesArgumentEvaluation(): void
    {
        $callSpan = new SourceSpan(1, 24);
        $node = new FunctionCallNode(
            'abs',
            [
                new VariableNode(
                    'first_unknown',
                    new SourceSpan(5, 18),
                ),
                self::number(2, 20, 21),
            ],
            new SourceSpan(1, 4),
            $callSpan,
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.arity',
            $callSpan,
            'Function "abs" expects exactly 1 argument but received 2',
        );
    }

    public function testFunctionArgumentsEvaluateLeftToRight(): void
    {
        $firstSpan = new SourceSpan(4, 9);
        $secondSpan = new SourceSpan(11, 17);
        $node = new FunctionCallNode(
            'log',
            [
                new VariableNode('first', $firstSpan),
                new VariableNode('second', $secondSpan),
            ],
            new SourceSpan(0, 3),
            new SourceSpan(0, 18),
        );

        $this->assertEvaluationError(
            fn (): int|float => self::evaluator()->evaluate($node),
            'eval.unknown_variable',
            $firstSpan,
            'Unknown variable "first"',
        );
    }

    public function testCustomFunctionReceivesEvaluatedArgumentsInOrder(): void
    {
        $received = null;
        $definition = new FunctionDefinition(
            'weighted',
            3,
            3,
            static function (array $arguments) use (&$received): float {
                $received = $arguments;

                return $arguments[0] + ($arguments[1] * $arguments[2]);
            },
        );
        $registry = FunctionRegistry::defaults()->with($definition);
        $node = self::call('weighted', [1, 2.5, 4]);

        $result = self::evaluator(functions: $registry)->evaluate($node);

        self::assertSame([1, 2.5, 4], $received);
        self::assertSame(11.0, $result);
    }

    #[DataProvider('customThrowableProvider')]
    public function testCustomThrowableKindsAreWrappedAtTheCallSpan(
        \Throwable $callbackFailure,
    ): void {
        $definition = new FunctionDefinition(
            'failing',
            0,
            0,
            static function (array $arguments) use ($callbackFailure): never {
                self::assertSame([], $arguments);

                throw $callbackFailure;
            },
        );
        $registry = FunctionRegistry::defaults()->with($definition);
        $callSpan = new SourceSpan(3, 12);
        $node = self::call(
            'failing',
            [],
            new SourceSpan(3, 10),
            $callSpan,
        );

        $exception = $this->captureEvaluationError(
            fn (): int|float => self::evaluator(
                functions: $registry,
            )->evaluate($node),
            'eval.custom_function',
            $callSpan,
            'Custom function "failing" failed',
        );

        self::assertSame($callbackFailure, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function customThrowableProvider(): iterable
    {
        yield 'domain exception' => [
            new \DomainException('custom domain'),
        ];
        yield 'overflow exception' => [
            new \OverflowException('custom overflow'),
        ];
        yield 'runtime exception' => [
            new \RuntimeException('custom runtime'),
        ];
    }

    public function testInvalidCustomReturnIsWrappedAtTheCallSpan(): void
    {
        $definition = new FunctionDefinition(
            'invalid',
            0,
            0,
            static fn (array $arguments): bool => $arguments === [],
        );
        $registry = FunctionRegistry::defaults()->with($definition);
        $callSpan = new SourceSpan(2, 11);
        $node = self::call(
            'invalid',
            [],
            new SourceSpan(2, 9),
            $callSpan,
        );

        $exception = $this->captureEvaluationError(
            fn (): int|float => self::evaluator(
                functions: $registry,
            )->evaluate($node),
            'eval.custom_function',
            $callSpan,
            'Custom function "invalid" failed',
        );

        self::assertInstanceOf(
            \UnexpectedValueException::class,
            $exception->getPrevious(),
        );
    }

    #[DataProvider('customNonFiniteProvider')]
    public function testNonFiniteCustomReturnIsWrappedAtTheCallSpan(
        float $result,
    ): void {
        $definition = new FunctionDefinition(
            'nonfinite',
            0,
            0,
            static fn (array $arguments): float => $result
                + (0.0 * \count($arguments)),
        );
        $registry = FunctionRegistry::defaults()->with($definition);
        $callSpan = new SourceSpan(2, 13);
        $node = self::call(
            'nonfinite',
            [],
            new SourceSpan(2, 11),
            $callSpan,
        );

        $exception = $this->captureEvaluationError(
            fn (): int|float => self::evaluator(
                functions: $registry,
            )->evaluate($node),
            'eval.custom_function',
            $callSpan,
            'Custom function "nonfinite" failed',
        );
        $previous = $exception->getPrevious();

        self::assertInstanceOf(EvaluationException::class, $previous);
        self::assertSame('eval.non_finite', $previous->errorCode());
        self::assertSame($callSpan, $previous->span());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function customNonFiniteProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }

    public function testUnsupportedNodeIsAnInternalLogicError(): void
    {
        $span = new SourceSpan(0, 1);
        $node = new class ($span) implements Node {
            public function __construct(
                private readonly SourceSpan $sourceSpan,
            ) {
            }

            public function span(): SourceSpan
            {
                return $this->sourceSpan;
            }

            public function depth(): int
            {
                return 1;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported AST node');

        self::evaluator()->evaluate($node);
    }

    public function testItIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(Evaluator::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    /**
     * @param array<string, int|float> $variables
     */
    private static function evaluator(
        array $variables = [],
        ?FunctionRegistry $functions = null,
        ?ResourceLimits $limits = null,
    ): Evaluator {
        $registry = $functions ?? FunctionRegistry::defaults();

        return new Evaluator(
            new Environment($variables, $registry),
            $limits ?? new ResourceLimits(),
        );
    }

    private static function number(
        int|float $value,
        int $start = 0,
        int $end = 1,
    ): NumberNode {
        return new NumberNode(
            $value,
            (string) $value,
            new SourceSpan($start, $end),
        );
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function call(
        string $name,
        array $arguments,
        ?SourceSpan $nameSpan = null,
        ?SourceSpan $callSpan = null,
    ): FunctionCallNode {
        $argumentNodes = [];

        foreach ($arguments as $index => $argument) {
            $start = 10 + ($index * 3);
            $argumentNodes[] = self::number(
                $argument,
                $start,
                $start + 1,
            );
        }

        return new FunctionCallNode(
            $name,
            $argumentNodes,
            $nameSpan ?? new SourceSpan(2, 2 + \strlen($name)),
            $callSpan ?? new SourceSpan(2, 20),
        );
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
        $this->captureEvaluationError(
            $operation,
            $expectedCode,
            $expectedSpan,
            $expectedMessage,
        );
    }

    /**
     * @param \Closure(): (int|float) $operation
     */
    private function captureEvaluationError(
        \Closure $operation,
        string $expectedCode,
        SourceSpan $expectedSpan,
        string $expectedMessage,
    ): EvaluationException {
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
            self::assertSame($expectedSpan, $exception->span());
            self::assertSame(
                $expectedMessage . ' at position ' . $expectedSpan->start,
                $exception->getMessage(),
            );

            return $exception;
        }
    }

    private static function assertFloatConforms(
        float $expected,
        int|float $actual,
    ): void {
        self::assertIsFloat($actual);

        $tolerance = \max(
            self::FLOAT_TOLERANCE,
            self::FLOAT_TOLERANCE
                * \max(\abs($actual), \abs($expected)),
        );

        self::assertEqualsWithDelta($expected, $actual, $tolerance);
    }
}
