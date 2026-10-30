<?php

declare(strict_types=1);

namespace MathPHP\Tests\Function;

use MathPHP\Function\FunctionDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionDefinitionTest extends TestCase
{
    #[DataProvider('validNameProvider')]
    public function testValidAsciiIdentifiersAreAccepted(string $name): void
    {
        $definition = new FunctionDefinition(
            $name,
            0,
            0,
            static fn (array $arguments): int => \count($arguments),
        );

        self::assertSame($name, $definition->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNameProvider(): iterable
    {
        yield 'single letter' => ['f'];
        yield 'underscore' => ['_'];
        yield 'leading underscore' => ['_sum2'];
        yield 'mixed case and digits' => ['Sum2'];
    }

    #[DataProvider('invalidNameProvider')]
    public function testInvalidFunctionNamesAreRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A function name must be a valid ASCII identifier.',
        );

        new FunctionDefinition(
            $name,
            0,
            0,
            static fn (array $arguments): int => \count($arguments),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['2sum'];
        yield 'hyphen' => ['sum-all'];
        yield 'dot' => ['sum.all'];
        yield 'space' => ['sum all'];
        yield 'non ASCII' => ['süm'];
        yield 'null byte' => ["sum\0"];
    }

    public function testNegativeMinimumArityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A function minimum arity must be non-negative.',
        );

        new FunctionDefinition(
            'f',
            -1,
            0,
            static fn (array $arguments): int => \count($arguments),
        );
    }

    public function testMaximumArityBelowMinimumIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A function maximum arity must not be less than its minimum arity.',
        );

        new FunctionDefinition(
            'f',
            2,
            1,
            static fn (array $arguments): int => \count($arguments),
        );
    }

    public function testExactIdentityArgumentIsExposedForSymbolicSolving(): void
    {
        $definition = new FunctionDefinition(
            'identity',
            1,
            1,
            static fn (array $arguments): int|float => $arguments[0],
            exactIdentityArgument: 0,
        );

        self::assertSame(0, $definition->exactIdentityArgument);
    }

    public function testExactIdentityArgumentMustExistForEveryAllowedArity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An exact identity argument must be present for every allowed function call.',
        );

        new FunctionDefinition(
            'identity',
            0,
            1,
            static fn (array $arguments): int|float => $arguments[0] ?? 0,
            exactIdentityArgument: 0,
        );
    }

    public function testExactIdentityArgumentCannotClaimAPartialDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An exact identity rewrite requires a total function without a domain extension.',
        );

        new FunctionDefinition(
            'partialIdentity',
            1,
            1,
            static fn (array $arguments): int|float => $arguments[0],
            domain: static fn (array $_): bool => true,
            exactIdentityArgument: 0,
        );
    }

    public function testIntervalExtensionReceivesArgumentBoundsAndValidatesItsResult(): void
    {
        $received = null;
        $definition = new FunctionDefinition(
            'bounded',
            1,
            1,
            static fn (array $arguments): float => $arguments[0],
            range: static function (array $intervals) use (&$received): array {
                $received = $intervals;

                return [$intervals[0][0] - 1.0, $intervals[0][1] + 1.0];
            },
        );

        self::assertTrue($definition->hasIntervalExtension());
        self::assertSame([-2.0, 3.0], $definition->intervalExtension([[-1.0, 2.0]]));
        self::assertSame([[-1.0, 2.0]], $received);

        $invalid = new FunctionDefinition(
            'invalidRange',
            1,
            1,
            static fn (array $arguments): float => $arguments[0],
            range: static fn (array $_): array => [2.0, -2.0],
        );
        $this->expectException(\UnexpectedValueException::class);
        $invalid->intervalExtension([[0.0, 1.0]]);
    }

    public function testInvokePassesAnOrderedNumericListAndPreservesResultType(): void
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

        $result = $definition->invoke([1, 2.5, 4]);

        self::assertSame([1, 2.5, 4], $received);
        self::assertSame(11.0, $result);
        self::assertSame('float', \get_debug_type($result));
    }

    public function testInvokePreservesAnIntegerResult(): void
    {
        $definition = new FunctionDefinition(
            'answer',
            0,
            0,
            static fn (array $arguments): int => 42 + \count($arguments),
        );

        self::assertSame(42, $definition->invoke([]));
    }

    public function testGradientReturnsPartialsInArgumentOrder(): void
    {
        $definition = new FunctionDefinition(
            'product',
            2,
            2,
            static fn (array $arguments): float => $arguments[0] * $arguments[1],
            static fn (array $arguments): array => [$arguments[1], $arguments[0]],
        );

        self::assertSame([3.0, 2.0], $definition->gradient([2.0, 3.0]));
    }

    public function testGradientIsUnavailableWhenNoCallbackWasProvided(): void
    {
        $definition = new FunctionDefinition(
            'square',
            1,
            1,
            static fn (array $arguments): float => $arguments[0] ** 2,
        );

        self::assertNull($definition->gradient([2.0]));
    }

    public function testGradientDomainFailureMeansUnavailable(): void
    {
        $definition = new FunctionDefinition(
            'partial',
            1,
            1,
            static fn (array $arguments): float => $arguments[0] ** 2,
            static function (array $arguments): array {
                throw new \DomainException('Derivative is unavailable at this point.');
            },
        );

        self::assertNull($definition->gradient([2.0]));
    }

    public function testGradientRequiresOneNumericPartialPerArgument(): void
    {
        $definition = new FunctionDefinition(
            'invalid_gradient',
            2,
            2,
            static fn (array $arguments): float => $arguments[0] + $arguments[1],
            static fn (array $arguments): array => [1.0],
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Function "invalid_gradient" gradient must return one partial derivative per argument.',
        );

        $definition->gradient([1.0, 2.0]);
    }

    public function testGradientRejectsNonNumericPartialValues(): void
    {
        $definition = new FunctionDefinition(
            'invalid_gradient',
            1,
            1,
            static fn (array $arguments): float => $arguments[0],
            static fn (array $arguments): array => [true],
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Function "invalid_gradient" gradient values must be numeric.',
        );

        $definition->gradient([1.0]);
    }

    public function testInvokeRejectsAnAssociativeArgumentArray(): void
    {
        $definition = new FunctionDefinition(
            'f',
            1,
            1,
            static fn (array $arguments): int => \count($arguments),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Function arguments must be provided as a list.',
        );

        $definition->invoke(['value' => 1]);
    }

    /**
     * @param list<int|float> $arguments
     */
    #[DataProvider('wrongArityProvider')]
    public function testInvokeRejectsWrongArity(
        array $arguments,
        string $expectedMessage,
    ): void {
        $definition = new FunctionDefinition(
            'range',
            1,
            2,
            static fn (array $values): int => \count($values),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $definition->invoke($arguments);
    }

    /**
     * @return iterable<string, array{list<int|float>, string}>
     */
    public static function wrongArityProvider(): iterable
    {
        yield 'too few' => [
            [],
            'Function "range" expects between 1 and 2 arguments.',
        ];
        yield 'too many' => [
            [1, 2, 3],
            'Function "range" expects between 1 and 2 arguments.',
        ];
    }

    #[DataProvider('nonFiniteArgumentProvider')]
    public function testInvokeRejectsNonFiniteArguments(float $value): void
    {
        $definition = new FunctionDefinition(
            'finite',
            1,
            1,
            static fn (array $arguments): float => $arguments[0],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Function arguments must be finite numbers.',
        );

        $definition->invoke([$value]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteArgumentProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'NaN' => [\NAN];
    }

    #[DataProvider('invalidReturnProvider')]
    public function testInvokeRejectsNonNumericCallbackResults(
        \Closure $callback,
    ): void {
        $definition = new FunctionDefinition('invalid', 0, 0, $callback);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Function "invalid" must return an integer or float.',
        );

        $definition->invoke([]);
    }

    /**
     * @return iterable<string, array{\Closure(list<int|float>): mixed}>
     */
    public static function invalidReturnProvider(): iterable
    {
        yield 'boolean' => [static fn (array $arguments): bool => $arguments === []];
        yield 'string' => [static fn (array $arguments): string => (string) \count($arguments)];
        yield 'array' => [static fn (array $arguments): array => $arguments];
        yield 'null' => [static fn (array $arguments): mixed => $arguments[0] ?? null];
        yield 'object' => [
            static fn (array $arguments): object => (object) [
                'count' => \count($arguments),
            ],
        ];
    }

    public function testCallbackExceptionsAreNotHiddenByTheDefinition(): void
    {
        $failure = new \RuntimeException('callback failed');
        $definition = new FunctionDefinition(
            'failing',
            0,
            0,
            static function (array $arguments) use ($failure): never {
                self::assertSame([], $arguments);

                throw $failure;
            },
        );

        try {
            $definition->invoke([]);
            self::fail('The callback exception should have escaped.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    #[DataProvider('arityDescriptionProvider')]
    public function testArityDescription(
        int $minimum,
        int $maximum,
        string $expected,
    ): void {
        $definition = new FunctionDefinition(
            'f',
            $minimum,
            $maximum,
            static fn (array $arguments): int => \count($arguments),
        );

        self::assertSame($expected, $definition->arityDescription());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function arityDescriptionProvider(): iterable
    {
        yield 'zero arguments' => [0, 0, 'exactly 0 arguments'];
        yield 'one argument' => [1, 1, 'exactly 1 argument'];
        yield 'multiple exact arguments' => [2, 2, 'exactly 2 arguments'];
        yield 'range' => [1, 3, 'between 1 and 3 arguments'];
    }

    public function testBuiltInProvenanceCannotBeForgedThroughTheDefinitionApi(): void
    {
        $reflection = new \ReflectionClass(FunctionDefinition::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(
            [
                'name',
                'minArguments',
                'maxArguments',
                'callback',
                'gradient',
                'range',
                'domain',
                'exactIdentityArgument',
            ],
            \array_map(
                static fn (
                    \ReflectionParameter $parameter,
                ): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
        );
        self::assertFalse($reflection->hasProperty('builtIn'));
    }

    public function testItIsAFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(FunctionDefinition::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
