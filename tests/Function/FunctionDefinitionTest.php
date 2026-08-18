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
            ['name', 'minArguments', 'maxArguments', 'callback'],
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
