<?php

declare(strict_types=1);

namespace MathPHP\Function;

final readonly class FunctionRegistry
{
    /**
     * @var array<string, FunctionDefinition>
     */
    private array $definitions;

    /**
     * @param array<string, FunctionDefinition> $definitions
     * @param array<string, true> $builtInNames
     */
    private function __construct(
        array $definitions,
        private array $builtInNames,
    ) {
        $this->definitions = $definitions;
    }

    public static function defaults(): self
    {
        $definitions = [
            new FunctionDefinition(
                'abs',
                1,
                1,
                self::absolute(...),
            ),
            new FunctionDefinition(
                'sqrt',
                1,
                1,
                self::squareRoot(...),
            ),
            new FunctionDefinition(
                'sin',
                1,
                1,
                self::sine(...),
            ),
            new FunctionDefinition(
                'cos',
                1,
                1,
                self::cosine(...),
            ),
            new FunctionDefinition(
                'exp',
                1,
                1,
                self::exponential(...),
            ),
            new FunctionDefinition(
                'ln',
                1,
                1,
                self::naturalLogarithm(...),
            ),
            new FunctionDefinition(
                'log',
                2,
                2,
                self::logarithm(...),
            ),
            new FunctionDefinition(
                'floor',
                1,
                1,
                self::floor(...),
            ),
            new FunctionDefinition(
                'ceil',
                1,
                1,
                self::ceiling(...),
            ),
            new FunctionDefinition(
                'round',
                1,
                1,
                self::round(...),
            ),
        ];

        $indexedDefinitions = [];
        $builtInNames = [];

        foreach ($definitions as $definition) {
            $indexedDefinitions[$definition->name] = $definition;
            $builtInNames[$definition->name] = true;
        }

        return new self($indexedDefinitions, $builtInNames);
    }

    public function with(FunctionDefinition $definition): self
    {
        if ($definition->name === 'pi' || $definition->name === 'e') {
            throw new \InvalidArgumentException(
                \sprintf(
                    'The constant name "%s" is reserved and cannot be registered as a function.',
                    $definition->name,
                ),
            );
        }

        if (isset($this->definitions[$definition->name])) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Function "%s" is already registered and cannot be overridden.',
                    $definition->name,
                ),
            );
        }

        $definitions = $this->definitions;
        $definitions[$definition->name] = $definition;

        return new self($definitions, $this->builtInNames);
    }

    public function find(string $name): ?FunctionDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    public function isBuiltIn(string $name): bool
    {
        return isset($this->builtInNames[$name]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function absolute(array $arguments): int|float
    {
        $value = $arguments[0];

        if ($value === \PHP_INT_MIN) {
            throw new \OverflowException(
                'The absolute value of PHP_INT_MIN is not representable as an integer.',
            );
        }

        return \abs($value);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function squareRoot(array $arguments): float
    {
        $value = $arguments[0];

        if ($value < 0) {
            throw new \DomainException(
                'sqrt() requires a non-negative argument.',
            );
        }

        return \sqrt($value);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function sine(array $arguments): float
    {
        return \sin($arguments[0]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function cosine(array $arguments): float
    {
        return \cos($arguments[0]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function exponential(array $arguments): float
    {
        return \exp($arguments[0]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function naturalLogarithm(array $arguments): float
    {
        $value = $arguments[0];

        if ($value <= 0) {
            throw new \DomainException(
                'ln() requires a positive argument.',
            );
        }

        return \log($value);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function logarithm(array $arguments): float
    {
        $value = $arguments[0];
        $base = $arguments[1];

        if (
            $value <= 0
            || $base <= 0
            || $base === 1
            || $base === 1.0
        ) {
            throw new \DomainException(
                'log() requires a positive value and a positive base other than one.',
            );
        }

        return \log($value, $base);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function floor(array $arguments): float
    {
        return \floor($arguments[0]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function ceiling(array $arguments): float
    {
        return \ceil($arguments[0]);
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function round(array $arguments): float
    {
        return \round(
            $arguments[0],
            0,
            \PHP_ROUND_HALF_UP,
        );
    }
}
