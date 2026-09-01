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
                'cbrt',
                1,
                1,
                self::cubeRoot(...),
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
                'tan',
                1,
                1,
                self::tangent(...),
            ),
            new FunctionDefinition(
                'sec',
                1,
                1,
                self::secant(...),
            ),
            new FunctionDefinition(
                'csc',
                1,
                1,
                self::cosecant(...),
            ),
            new FunctionDefinition(
                'cot',
                1,
                1,
                self::cotangent(...),
            ),
            new FunctionDefinition(
                'asin',
                1,
                1,
                self::arcSine(...),
            ),
            new FunctionDefinition(
                'acos',
                1,
                1,
                self::arcCosine(...),
            ),
            new FunctionDefinition(
                'atan',
                1,
                1,
                self::arcTangent(...),
            ),
            new FunctionDefinition(
                'sinh',
                1,
                1,
                self::hyperbolicSine(...),
            ),
            new FunctionDefinition(
                'cosh',
                1,
                1,
                self::hyperbolicCosine(...),
            ),
            new FunctionDefinition(
                'tanh',
                1,
                1,
                self::hyperbolicTangent(...),
            ),
            new FunctionDefinition(
                'asinh',
                1,
                1,
                self::arcHyperbolicSine(...),
            ),
            new FunctionDefinition(
                'acosh',
                1,
                1,
                self::arcHyperbolicCosine(...),
            ),
            new FunctionDefinition(
                'atanh',
                1,
                1,
                self::arcHyperbolicTangent(...),
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
                'log10',
                1,
                1,
                self::logBaseTen(...),
            ),
            new FunctionDefinition(
                'log2',
                1,
                1,
                self::logBaseTwo(...),
            ),
            new FunctionDefinition(
                'log1p',
                1,
                1,
                self::logOnePlus(...),
            ),
            new FunctionDefinition(
                'expm1',
                1,
                1,
                self::expMinusOne(...),
            ),
            new FunctionDefinition(
                'atan2',
                2,
                2,
                self::arcTangentTwo(...),
            ),
            new FunctionDefinition(
                'hypot',
                2,
                2,
                self::hypotenuse(...),
            ),
            new FunctionDefinition(
                'sign',
                1,
                1,
                self::sign(...),
            ),
            new FunctionDefinition(
                'min',
                1,
                16,
                self::minimum(...),
            ),
            new FunctionDefinition(
                'max',
                1,
                16,
                self::maximum(...),
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
            new FunctionDefinition(
                'gcd',
                2,
                2,
                self::greatestCommonDivisor(...),
            ),
            new FunctionDefinition(
                'lcm',
                2,
                2,
                self::leastCommonMultiple(...),
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

    /** @param list<int|float> $arguments */
    private static function cubeRoot(array $arguments): float
    {
        $value = (float) $arguments[0];

        return $value < 0.0 ? -\pow(-$value, 1.0 / 3.0) : \pow($value, 1.0 / 3.0);
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

    /** @param list<int|float> $arguments */
    private static function tangent(array $arguments): float
    {
        $cosine = \cos($arguments[0]);
        if (\abs($cosine) <= 1.0E-15) {
            throw new \DomainException('tan() is undefined where cosine is zero.');
        }

        return \sin($arguments[0]) / $cosine;
    }

    /** @param list<int|float> $arguments */
    private static function secant(array $arguments): float
    {
        $cosine = \cos($arguments[0]);
        if (\abs($cosine) <= 1.0E-15) {
            throw new \DomainException('sec() is undefined where cosine is zero.');
        }

        return 1.0 / $cosine;
    }

    /** @param list<int|float> $arguments */
    private static function cosecant(array $arguments): float
    {
        $sine = \sin($arguments[0]);
        if (\abs($sine) <= 1.0E-15) {
            throw new \DomainException('csc() is undefined where sine is zero.');
        }

        return 1.0 / $sine;
    }

    /** @param list<int|float> $arguments */
    private static function cotangent(array $arguments): float
    {
        $sine = \sin($arguments[0]);
        if (\abs($sine) <= 1.0E-15) {
            throw new \DomainException('cot() is undefined where sine is zero.');
        }

        return \cos($arguments[0]) / $sine;
    }

    /** @param list<int|float> $arguments */
    private static function arcSine(array $arguments): float
    {
        self::requireClosedUnitInterval($arguments[0], 'asin');
        return \asin($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcCosine(array $arguments): float
    {
        self::requireClosedUnitInterval($arguments[0], 'acos');
        return \acos($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcTangent(array $arguments): float
    {
        return \atan($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicSine(array $arguments): float
    {
        return \sinh($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicCosine(array $arguments): float
    {
        return \cosh($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicTangent(array $arguments): float
    {
        return \tanh($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicSine(array $arguments): float
    {
        return \asinh($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicCosine(array $arguments): float
    {
        if ($arguments[0] < 1) {
            throw new \DomainException('acosh() requires an argument greater than or equal to one.');
        }

        return \acosh($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicTangent(array $arguments): float
    {
        if ($arguments[0] <= -1 || $arguments[0] >= 1) {
            throw new \DomainException('atanh() requires an argument strictly between -1 and 1.');
        }

        return \atanh($arguments[0]);
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

    /** @param list<int|float> $arguments */
    private static function logBaseTen(array $arguments): float
    {
        if ($arguments[0] <= 0) {
            throw new \DomainException('log10() requires a positive argument.');
        }

        return \log10($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function logBaseTwo(array $arguments): float
    {
        if ($arguments[0] <= 0) {
            throw new \DomainException('log2() requires a positive argument.');
        }

        return \log($arguments[0], 2.0);
    }

    /** @param list<int|float> $arguments */
    private static function logOnePlus(array $arguments): float
    {
        if ($arguments[0] <= -1.0) {
            throw new \DomainException('log1p() requires an argument greater than -1.');
        }

        return \log1p($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function expMinusOne(array $arguments): float
    {
        return \expm1($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcTangentTwo(array $arguments): float
    {
        if ($arguments[0] == 0 && $arguments[1] == 0) {
            throw new \DomainException('atan2() is undefined when both arguments are zero.');
        }

        return \atan2($arguments[0], $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function hypotenuse(array $arguments): float
    {
        return \hypot($arguments[0], $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function sign(array $arguments): int
    {
        return $arguments[0] <=> 0;
    }

    /** @param list<int|float> $arguments */
    private static function minimum(array $arguments): int|float
    {
        return \min(...$arguments);
    }

    /** @param list<int|float> $arguments */
    private static function maximum(array $arguments): int|float
    {
        return \max(...$arguments);
    }

    private static function requireClosedUnitInterval(int|float $value, string $function): void
    {
        if ($value < -1 || $value > 1) {
            throw new \DomainException($function . '() requires an argument between -1 and 1.');
        }
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

    /** @param list<int|float> $arguments */
    private static function greatestCommonDivisor(array $arguments): int
    {
        $left = self::requireInteger($arguments[0], 'gcd');
        $right = self::requireInteger($arguments[1], 'gcd');
        $left = abs($left);
        $right = abs($right);
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }

    /** @param list<int|float> $arguments */
    private static function leastCommonMultiple(array $arguments): int
    {
        $left = self::requireInteger($arguments[0], 'lcm');
        $right = self::requireInteger($arguments[1], 'lcm');
        if ($left === 0 || $right === 0) {
            return 0;
        }

        $left = abs($left);
        $right = abs($right);
        $divisor = self::greatestCommonDivisor([$left, $right]);
        $reduced = intdiv($left, $divisor);
        if ($reduced > intdiv(\PHP_INT_MAX, $right)) {
            throw new \OverflowException('lcm() result exceeds the host integer range.');
        }

        return $reduced * $right;
    }

    private static function requireInteger(int|float $value, string $function): int
    {
        if (
            !is_finite((float) $value)
            || floor((float) $value) !== (float) $value
            || abs((float) $value) > (float) \PHP_INT_MAX
        ) {
            throw new \DomainException($function . '() requires finite integer arguments.');
        }

        return (int) $value;
    }
}
