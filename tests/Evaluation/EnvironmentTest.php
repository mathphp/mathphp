<?php

declare(strict_types=1);

namespace MathPHP\Tests\Evaluation;

use MathPHP\Evaluator\Environment;
use MathPHP\Exception\EvaluationException;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testFiniteIntegerAndFloatVariablesAreStoredWithoutCoercion(): void
    {
        $environment = new Environment(
            [
                'integer' => 42,
                'decimal' => 2.5,
                'Pi' => 3,
                'E' => 4.0,
                '_value2' => -7,
            ],
            FunctionRegistry::defaults(),
        );
        $span = new SourceSpan(0, 1);

        self::assertSame(42, $environment->variable('integer', $span));
        self::assertSame(2.5, $environment->variable('decimal', $span));
        self::assertSame(3, $environment->variable('Pi', $span));
        self::assertSame(4.0, $environment->variable('E', $span));
        self::assertSame(-7, $environment->variable('_value2', $span));
    }

    /**
     * @param array<mixed, mixed> $variables
     */
    #[DataProvider('invalidNameProvider')]
    public function testInvalidVariableNamesAreRejected(array $variables): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Every variable name must be a valid ASCII identifier.',
        );

        new Environment($variables, FunctionRegistry::defaults());
    }

    /**
     * @return iterable<string, array{array<mixed, mixed>}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'integer key' => [[0 => 1]];
        yield 'empty name' => [['' => 1]];
        yield 'leading digit' => [['2value' => 1]];
        yield 'hyphen' => [['some-value' => 1]];
        yield 'space' => [['some value' => 1]];
        yield 'non ASCII' => [['välue' => 1]];
        yield 'null byte' => [["value\0" => 1]];
    }

    #[DataProvider('reservedNameProvider')]
    public function testReservedConstantsCannotBeOverridden(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'The constant name "%s" is reserved and cannot be overridden.',
                $name,
            ),
        );

        new Environment([$name => 1], FunctionRegistry::defaults());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNameProvider(): iterable
    {
        yield 'pi' => ['pi'];
        yield 'e' => ['e'];
        yield 'tau' => ['tau'];
        yield 'phi' => ['phi'];
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidVariableValuesAreRejected(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Variable "value" must have a finite integer or float value.',
        );

        new Environment(
            ['value' => $value],
            FunctionRegistry::defaults(),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'numeric string' => ['1'];
        yield 'null' => [null];
        yield 'array' => [[1]];
        yield 'object' => [new \stdClass()];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'NaN' => [\NAN];
    }

    public function testResourceVariableValuesAreRejected(): void
    {
        $resource = \fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage(
                'Variable "value" must have a finite integer or float value.',
            );

            new Environment(
                ['value' => $resource],
                FunctionRegistry::defaults(),
            );
        } finally {
            \fclose($resource);
        }
    }

    public function testAnUnknownVariableIncludesItsStableCodeAndExactSpan(): void
    {
        $environment = new Environment([], FunctionRegistry::defaults());
        $span = new SourceSpan(4, 11);

        try {
            $environment->variable('missing', $span);
            self::fail('An unknown variable should fail.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
            self::assertSame($span, $error->span());
            self::assertSame(4, $error->span()->start);
            self::assertSame(11, $error->span()->end);
            self::assertSame(
                'Unknown variable "missing" at position 4',
                $error->getMessage(),
            );
        }
    }

    public function testConstantsAreExactCaseSensitiveFiniteFloats(): void
    {
        $environment = new Environment([], FunctionRegistry::defaults());

        self::assertSame(\M_PI, $environment->constant('pi'));
        self::assertSame(\M_E, $environment->constant('e'));
        self::assertSame(2 * \M_PI, $environment->constant('tau'));
        self::assertEqualsWithDelta((1 + \sqrt(5)) / 2, $environment->constant('phi'), 1.0E-12);
        self::assertIsFloat($environment->constant('pi'));
        self::assertIsFloat($environment->constant('e'));
        self::assertTrue(\is_finite($environment->constant('pi')));
        self::assertTrue(\is_finite($environment->constant('e')));
        self::assertTrue(\is_finite($environment->constant('tau')));
        self::assertTrue(\is_finite($environment->constant('phi')));
    }

    public function testParserCannotProduceAnyOtherConstantName(): void
    {
        $environment = new Environment([], FunctionRegistry::defaults());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Unknown parser-produced constant "Pi".',
        );

        $environment->constant('Pi');
    }

    public function testVariablesAreDefensivelyCopied(): void
    {
        $variables = ['value' => 1];
        $environment = new Environment(
            $variables,
            FunctionRegistry::defaults(),
        );

        $variables['value'] = 999;
        $variables['new_value'] = 2;

        self::assertSame(
            1,
            $environment->variable('value', new SourceSpan(0, 5)),
        );

        try {
            $environment->variable('new_value', new SourceSpan(0, 9));
            self::fail('A later caller-side mutation should not be visible.');
        } catch (EvaluationException $error) {
            self::assertSame('eval.unknown_variable', $error->errorCode());
        }
    }

    public function testTheProvidedImmutableRegistryIsRetainedExactly(): void
    {
        $registry = FunctionRegistry::defaults();
        $environment = new Environment([], $registry);

        self::assertSame($registry, $environment->functions);
    }

    public function testItIsAFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(Environment::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
