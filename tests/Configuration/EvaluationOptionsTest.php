<?php

declare(strict_types=1);

namespace MathPHP\Tests\Configuration;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use PHPUnit\Framework\TestCase;

final class EvaluationOptionsTest extends TestCase
{
    public function testDefaultsContainFreshImmutableLimitsAndDefaultFunctions(): void
    {
        $first = new EvaluationOptions();
        $second = new EvaluationOptions();

        self::assertInstanceOf(ResourceLimits::class, $first->limits);
        self::assertInstanceOf(FunctionRegistry::class, $first->functions);
        self::assertInstanceOf(FunctionDefinition::class, $first->functions->find('sqrt'));
        self::assertNotSame($first->limits, $second->limits);
        self::assertNotSame($first->functions, $second->functions);
    }

    public function testExplicitLimitsAndRegistryAreRetainedExactly(): void
    {
        $limits = new ResourceLimits(
            maxExpressionLength: 20,
            maxTokens: 10,
            maxNesting: 2,
            maxAstDepth: 5,
            maxFunctionArguments: 1,
            maxFactorial: 3,
            maxExponentMagnitude: 4,
        );
        $custom = new FunctionDefinition(
            'custom',
            0,
            0,
            static fn (array $arguments): int => \count($arguments),
        );
        $registry = FunctionRegistry::defaults()->with($custom);

        $options = new EvaluationOptions($limits, $registry);

        self::assertSame($limits, $options->limits);
        self::assertSame($registry, $options->functions);
        self::assertSame($custom, $options->functions->find('custom'));
    }

    public function testItIsAFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(EvaluationOptions::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
