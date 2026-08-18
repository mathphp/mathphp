<?php

declare(strict_types=1);

namespace MathPHP\Tests\Function;

use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionRegistryTest extends TestCase
{
    /**
     * @param list<int|float> $arguments
     */
    #[DataProvider('builtInSuccessProvider')]
    public function testDefaultAllowlistFunctionsProduceSpecifiedResults(
        string $name,
        array $arguments,
        int|float $expected,
        string $expectedType,
    ): void {
        $registry = FunctionRegistry::defaults();
        $definition = $registry->find($name);

        self::assertInstanceOf(FunctionDefinition::class, $definition);
        self::assertSame($name, $definition->name);
        self::assertTrue($registry->isBuiltIn($name));

        $actual = $definition->invoke($arguments);

        self::assertSame($expectedType, \get_debug_type($actual));
        if (\is_float($expected)) {
            self::assertEqualsWithDelta($expected, $actual, 1.0E-12);
        } else {
            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{string, list<int|float>, int|float, 'int'|'float'}>
     */
    public static function builtInSuccessProvider(): iterable
    {
        yield 'absolute integer' => ['abs', [-4], 4, 'int'];
        yield 'absolute float' => ['abs', [-4.5], 4.5, 'float'];
        yield 'square root' => ['sqrt', [9], 3.0, 'float'];
        yield 'sine' => ['sin', [\M_PI / 2], 1.0, 'float'];
        yield 'cosine' => ['cos', [0], 1.0, 'float'];
        yield 'exponential' => ['exp', [1], \M_E, 'float'];
        yield 'natural logarithm' => ['ln', [\M_E], 1.0, 'float'];
        yield 'logarithm' => ['log', [100, 10], 2.0, 'float'];
        yield 'floor' => ['floor', [-1.2], -2.0, 'float'];
        yield 'ceiling' => ['ceil', [-1.2], -1.0, 'float'];
        yield 'round positive halfway' => ['round', [2.5], 3.0, 'float'];
        yield 'round negative halfway' => ['round', [-2.5], -3.0, 'float'];
    }

    #[DataProvider('builtInDefinitionProvider')]
    public function testDefaultRegistryHasExactlyTheSpecifiedArities(
        string $name,
        int $minimum,
        int $maximum,
    ): void {
        $definition = FunctionRegistry::defaults()->find($name);

        self::assertInstanceOf(FunctionDefinition::class, $definition);
        self::assertSame($minimum, $definition->minArguments);
        self::assertSame($maximum, $definition->maxArguments);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function builtInDefinitionProvider(): iterable
    {
        yield 'abs' => ['abs', 1, 1];
        yield 'sqrt' => ['sqrt', 1, 1];
        yield 'sin' => ['sin', 1, 1];
        yield 'cos' => ['cos', 1, 1];
        yield 'exp' => ['exp', 1, 1];
        yield 'ln' => ['ln', 1, 1];
        yield 'log' => ['log', 2, 2];
        yield 'floor' => ['floor', 1, 1];
        yield 'ceil' => ['ceil', 1, 1];
        yield 'round' => ['round', 1, 1];
    }

    /**
     * @param list<int|float> $arguments
     * @param class-string<\Throwable> $expectedException
     */
    #[DataProvider('builtInDomainFailureProvider')]
    public function testBuiltInDefinitionsEnforceTheirDomains(
        string $name,
        array $arguments,
        string $expectedException,
        string $expectedMessage,
    ): void {
        $definition = FunctionRegistry::defaults()->find($name);
        self::assertInstanceOf(FunctionDefinition::class, $definition);

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $definition->invoke($arguments);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<int|float>,
     *     class-string<\Throwable>,
     *     string
     * }>
     */
    public static function builtInDomainFailureProvider(): iterable
    {
        yield 'absolute minimum integer' => [
            'abs',
            [\PHP_INT_MIN],
            \OverflowException::class,
            'absolute value of PHP_INT_MIN',
        ];
        yield 'negative square root' => [
            'sqrt',
            [-1],
            \DomainException::class,
            'sqrt() requires a non-negative argument.',
        ];
        yield 'natural log zero' => [
            'ln',
            [0],
            \DomainException::class,
            'ln() requires a positive argument.',
        ];
        yield 'natural log negative' => [
            'ln',
            [-1],
            \DomainException::class,
            'ln() requires a positive argument.',
        ];
        yield 'log value zero' => [
            'log',
            [0, 2],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base zero' => [
            'log',
            [2, 0],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base one integer' => [
            'log',
            [2, 1],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log base one float' => [
            'log',
            [2, 1.0],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
        yield 'log negative base' => [
            'log',
            [2, -10],
            \DomainException::class,
            'positive value and a positive base other than one',
        ];
    }

    public function testOnlyTheExactCaseSensitiveAllowlistIsPresentByDefault(): void
    {
        $registry = FunctionRegistry::defaults();

        self::assertNull($registry->find('unknown'));
        self::assertNull($registry->find('ABS'));
        self::assertNull($registry->find('Sqrt'));
        self::assertNull($registry->find('system'));
        self::assertNull($registry->find('eval'));
    }

    public function testWithReturnsANewRegistryWithoutMutatingTheOriginal(): void
    {
        $original = FunctionRegistry::defaults();
        $definition = self::definition('double', 1, 1, 2);

        $extended = $original->with($definition);

        self::assertNotSame($original, $extended);
        self::assertNull($original->find('double'));
        self::assertSame($definition, $extended->find('double'));
        self::assertSame($original->find('sqrt'), $extended->find('sqrt'));
    }

    public function testCustomDefinitionsNeverAcquireBuiltInProvenance(): void
    {
        $registry = FunctionRegistry::defaults()->with(
            self::definition('spoof', 0, 0, 1),
        );

        self::assertFalse($registry->isBuiltIn('spoof'));
        self::assertFalse($registry->isBuiltIn('Spoof'));
        self::assertFalse($registry->isBuiltIn('unknown'));
        self::assertTrue($registry->isBuiltIn('abs'));
    }

    public function testIndependentExtensionsDoNotLeakMembership(): void
    {
        $defaults = FunctionRegistry::defaults();
        $firstDefinition = self::definition('first', 0, 0, 1);
        $secondDefinition = self::definition('second', 0, 0, 2);

        $first = $defaults->with($firstDefinition);
        $second = $defaults->with($secondDefinition);

        self::assertSame($firstDefinition, $first->find('first'));
        self::assertNull($first->find('second'));
        self::assertSame($secondDefinition, $second->find('second'));
        self::assertNull($second->find('first'));
        self::assertNull($defaults->find('first'));
        self::assertNull($defaults->find('second'));
    }

    #[DataProvider('reservedNameProvider')]
    public function testConstantsCannotBeRegisteredAsFunctions(string $name): void
    {
        $registry = FunctionRegistry::defaults();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'The constant name "%s" is reserved and cannot be registered as a function.',
                $name,
            ),
        );

        $registry->with(self::definition($name, 0, 0, 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNameProvider(): iterable
    {
        yield 'pi' => ['pi'];
        yield 'e' => ['e'];
    }

    #[DataProvider('existingNameProvider')]
    public function testExistingFunctionsCannotBeOverridden(string $name): void
    {
        $registry = FunctionRegistry::defaults();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'Function "%s" is already registered and cannot be overridden.',
                $name,
            ),
        );

        $registry->with(self::definition($name, 0, 0, 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingNameProvider(): iterable
    {
        yield 'abs' => ['abs'];
        yield 'sqrt' => ['sqrt'];
        yield 'sin' => ['sin'];
        yield 'cos' => ['cos'];
        yield 'exp' => ['exp'];
        yield 'ln' => ['ln'];
        yield 'log' => ['log'];
        yield 'floor' => ['floor'];
        yield 'ceil' => ['ceil'];
        yield 'round' => ['round'];
    }

    public function testAnAlreadyAddedCustomFunctionCannotBeOverridden(): void
    {
        $registry = FunctionRegistry::defaults()->with(
            self::definition('custom', 0, 0, 1),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Function "custom" is already registered and cannot be overridden.',
        );

        $registry->with(self::definition('custom', 0, 0, 2));
    }

    public function testNamesThatOnlyDifferByCaseRemainDistinct(): void
    {
        $upperAbs = self::definition('Abs', 0, 0, 99);
        $upperPi = self::definition('Pi', 0, 0, 3);
        $registry = FunctionRegistry::defaults()
            ->with($upperAbs)
            ->with($upperPi);

        self::assertSame($upperAbs, $registry->find('Abs'));
        self::assertSame($upperPi, $registry->find('Pi'));
        self::assertNotSame($upperAbs, $registry->find('abs'));
        self::assertNull($registry->find('pi'));
    }

    public function testItIsAFinalAndReadonlyWithNoPublicConstructor(): void
    {
        $reflection = new \ReflectionClass(FunctionRegistry::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    private static function definition(
        string $name,
        int $minimum,
        int $maximum,
        int $result,
    ): FunctionDefinition {
        return new FunctionDefinition(
            $name,
            $minimum,
            $maximum,
            static fn (array $arguments): int => $result + (0 * \count($arguments)),
        );
    }
}
