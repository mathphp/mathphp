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
                'root',
                2,
                2,
                self::integerRoot(...),
            ),
            new FunctionDefinition(
                'erf',
                1,
                1,
                self::errorFunction(...),
            ),
            new FunctionDefinition(
                'erfc',
                1,
                1,
                self::complementaryErrorFunction(...),
            ),
            new FunctionDefinition(
                'erfcx',
                1,
                1,
                self::scaledComplementaryErrorFunction(...),
            ),
            new FunctionDefinition(
                'erfi',
                1,
                1,
                self::imaginaryErrorFunction(...),
            ),
            new FunctionDefinition(
                'dawson',
                1,
                1,
                self::dawsonIntegral(...),
            ),
            new FunctionDefinition(
                'erfinv',
                1,
                1,
                self::inverseErrorFunction(...),
                static fn (array $arguments): array => [
                    (\sqrt(\M_PI) / 2.0)
                    * \exp(SpecialFunctions::inverseErf((float) $arguments[0]) ** 2),
                ],
            ),
            new FunctionDefinition(
                'erfcinv',
                1,
                1,
                self::inverseComplementaryErrorFunction(...),
                static fn (array $arguments): array => [
                    - (\sqrt(\M_PI) / 2.0)
                    * \exp(SpecialFunctions::inverseErfc((float) $arguments[0]) ** 2),
                ],
            ),
            new FunctionDefinition(
                'gamma',
                1,
                1,
                self::gammaFunction(...),
            ),
            new FunctionDefinition(
                'gammainc',
                2,
                2,
                self::regularizedGammaPFunction(...),
            ),
            new FunctionDefinition(
                'gammaincc',
                2,
                2,
                self::regularizedGammaQFunction(...),
            ),
            new FunctionDefinition(
                'gammaincinv',
                2,
                2,
                self::inverseRegularizedGammaPFunction(...),
            ),
            new FunctionDefinition(
                'gammainccinv',
                2,
                2,
                self::inverseRegularizedGammaQFunction(...),
            ),
            new FunctionDefinition(
                'lgamma',
                1,
                1,
                self::logGammaFunction(...),
            ),
            new FunctionDefinition(
                'beta',
                2,
                2,
                self::betaFunction(...),
            ),
            new FunctionDefinition(
                'betainc',
                3,
                3,
                self::regularizedBetaFunction(...),
            ),
            new FunctionDefinition(
                'betaincc',
                3,
                3,
                self::regularizedBetaComplementFunction(...),
            ),
            new FunctionDefinition(
                'betaincinv',
                3,
                3,
                self::inverseRegularizedBetaFunction(...),
            ),
            new FunctionDefinition(
                'betainccinv',
                3,
                3,
                self::inverseRegularizedBetaComplementFunction(...),
            ),
            new FunctionDefinition(
                'zeta',
                1,
                1,
                self::riemannZetaFunction(...),
            ),
            new FunctionDefinition(
                'digamma',
                1,
                1,
                self::digammaFunction(...),
            ),
            new FunctionDefinition(
                'trigamma',
                1,
                1,
                self::trigammaFunction(...),
            ),
            new FunctionDefinition(
                'tetragamma',
                1,
                1,
                self::tetragammaFunction(...),
            ),
            new FunctionDefinition(
                'polygamma',
                2,
                2,
                self::polygammaFunction(...),
            ),
            new FunctionDefinition(
                'doublefactorial',
                1,
                1,
                self::doubleFactorialFunction(...),
            ),
            new FunctionDefinition(
                'besselj',
                2,
                2,
                self::besselJFunction(...),
            ),
            new FunctionDefinition(
                'besseli',
                2,
                2,
                self::besselIFunction(...),
            ),
            new FunctionDefinition(
                'bessely',
                2,
                2,
                self::besselYFunction(...),
            ),
            new FunctionDefinition(
                'lambertw',
                1,
                2,
                self::lambertWFunction(...),
                static function (array $arguments): array {
                    if (\count($arguments) !== 1) {
                        throw new \DomainException(
                            'The derivative with respect to a Lambert W branch index is undefined.',
                        );
                    }
                    $value = (float) $arguments[0];
                    $result = self::lambertWFunction([$value]);
                    if ($value === 0.0) {
                        return [1.0];
                    }
                    $denominator = $value * (1.0 + $result);
                    if ($denominator === 0.0) {
                        throw new \DomainException('lambertw() derivative is singular.');
                    }
                    $derivative = $result / $denominator;
                    if (!\is_finite($derivative)) {
                        throw new \DomainException('lambertw() derivative is not finite.');
                    }

                    return [$derivative];
                },
            ),
            new FunctionDefinition(
                'sin',
                1,
                1,
                self::sine(...),
            ),
            new FunctionDefinition(
                'sinc',
                1,
                1,
                self::sinc(...),
            ),
            new FunctionDefinition(
                'sincpi',
                1,
                1,
                self::sincPi(...),
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
                'asec',
                1,
                1,
                self::arcSecant(...),
            ),
            new FunctionDefinition(
                'acsc',
                1,
                1,
                self::arcCosecant(...),
            ),
            new FunctionDefinition(
                'acot',
                1,
                1,
                self::arcCotangent(...),
            ),
            new FunctionDefinition(
                'asin',
                1,
                1,
                self::arcSine(...),
            ),
            new FunctionDefinition(
                'arcsin',
                1,
                1,
                self::arcSine(...),
                static fn (array $arguments): array => [
                    1.0 / \sqrt((1.0 - $arguments[0]) * (1.0 + $arguments[0])),
                ],
            ),
            new FunctionDefinition(
                'acos',
                1,
                1,
                self::arcCosine(...),
            ),
            new FunctionDefinition(
                'arccos',
                1,
                1,
                self::arcCosine(...),
                static fn (array $arguments): array => [
                    -1.0 / \sqrt((1.0 - $arguments[0]) * (1.0 + $arguments[0])),
                ],
            ),
            new FunctionDefinition(
                'atan',
                1,
                1,
                self::arcTangent(...),
            ),
            new FunctionDefinition(
                'arctan',
                1,
                1,
                self::arcTangent(...),
                static fn (array $arguments): array => [
                    1.0 / \hypot(1.0, $arguments[0]) ** 2,
                ],
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
                'sech',
                1,
                1,
                self::hyperbolicSecant(...),
            ),
            new FunctionDefinition(
                'csch',
                1,
                1,
                self::hyperbolicCosecant(...),
            ),
            new FunctionDefinition(
                'coth',
                1,
                1,
                self::hyperbolicCotangent(...),
            ),
            new FunctionDefinition(
                'asinh',
                1,
                1,
                self::arcHyperbolicSine(...),
            ),
            new FunctionDefinition(
                'arcsinh',
                1,
                1,
                self::arcHyperbolicSine(...),
                static fn (array $arguments): array => [
                    1.0 / \hypot(1.0, $arguments[0]),
                ],
            ),
            new FunctionDefinition(
                'acosh',
                1,
                1,
                self::arcHyperbolicCosine(...),
            ),
            new FunctionDefinition(
                'arccosh',
                1,
                1,
                self::arcHyperbolicCosine(...),
                static fn (array $arguments): array => [
                    1.0 / \sqrt(($arguments[0] - 1.0) * ($arguments[0] + 1.0)),
                ],
            ),
            new FunctionDefinition(
                'atanh',
                1,
                1,
                self::arcHyperbolicTangent(...),
            ),
            new FunctionDefinition(
                'arctanh',
                1,
                1,
                self::arcHyperbolicTangent(...),
                static fn (array $arguments): array => [
                    1.0 / ((1.0 - $arguments[0]) * (1.0 + $arguments[0])),
                ],
            ),
            new FunctionDefinition(
                'asech',
                1,
                1,
                self::arcHyperbolicSecant(...),
            ),
            new FunctionDefinition(
                'acsch',
                1,
                1,
                self::arcHyperbolicCosecant(...),
            ),
            new FunctionDefinition(
                'acoth',
                1,
                1,
                self::arcHyperbolicCotangent(...),
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
                'ceiling',
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
                'trunc',
                1,
                1,
                self::truncate(...),
            ),
            new FunctionDefinition(
                'truncate',
                1,
                1,
                self::truncate(...),
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
            new FunctionDefinition(
                'binomial',
                2,
                2,
                self::binomialCoefficient(...),
            ),
            new FunctionDefinition(
                'permutation',
                2,
                2,
                self::permutationCount(...),
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

    /** @param list<int|float> $arguments */
    private static function doubleFactorialFunction(array $arguments): int
    {
        $operand = $arguments[0];
        if (!\is_finite((float) $operand) || \floor($operand) !== $operand || $operand < -1) {
            throw new \DomainException(
                'doublefactorial() requires an integer greater than or equal to -1.',
            );
        }
        if ($operand == -1 || $operand == 0) {
            return 1;
        }
        if ($operand > \PHP_INT_MAX) {
            throw new \OverflowException('doublefactorial() operand exceeds the host integer range.');
        }

        $result = 1;
        for ($factor = (int) $operand; $factor > 1; $factor -= 2) {
            if ($result > intdiv(\PHP_INT_MAX, $factor)) {
                throw new \OverflowException('doublefactorial() result exceeds the host integer range.');
            }
            $result *= $factor;
        }

        return $result;
    }

    /** @param list<int|float> $arguments */
    private static function binomialCoefficient(array $arguments): int
    {
        [$n, $k] = self::nonNegativeIntegerPair($arguments, 'binomial');
        if ($k > $n) {
            return 0;
        }
        $k = \min($k, $n - $k);
        $result = 1;
        for ($index = 1; $index <= $k; ++$index) {
            $numerator = $n - $k + $index;
            $divisor = self::greatestCommonDivisorPair($numerator, $index);
            $numerator = \intdiv($numerator, $divisor);
            $denominator = \intdiv($index, $divisor);
            $remainingDivisor = self::greatestCommonDivisorPair($result, $denominator);
            $result = \intdiv($result, $remainingDivisor);
            $denominator = \intdiv($denominator, $remainingDivisor);
            if ($denominator !== 1) {
                throw new \LogicException('Binomial coefficient reduction was not exact.');
            }
            if ($numerator !== 0 && $result > \intdiv(\PHP_INT_MAX, $numerator)) {
                throw new \OverflowException('binomial() result exceeds the host integer range.');
            }
            $result *= $numerator;
        }

        return $result;
    }

    /** @param list<int|float> $arguments */
    private static function permutationCount(array $arguments): int
    {
        [$n, $k] = self::nonNegativeIntegerPair($arguments, 'permutation');
        if ($k > $n) {
            return 0;
        }
        $result = 1;
        for ($index = 0; $index < $k; ++$index) {
            $factor = $n - $index;
            if ($factor !== 0 && $result > \intdiv(\PHP_INT_MAX, $factor)) {
                throw new \OverflowException('permutation() result exceeds the host integer range.');
            }
            $result *= $factor;
        }

        return $result;
    }

    /** @param list<int|float> $arguments
     *  @return array{int, int}
     */
    private static function nonNegativeIntegerPair(array $arguments, string $function): array
    {
        foreach ($arguments as $argument) {
            if (
                !\is_finite((float) $argument)
                || \floor((float) $argument) !== (float) $argument
                || $argument < 0
                || (\is_float($argument) && $argument >= (float) \PHP_INT_MAX)
            ) {
                throw new \DomainException(
                    $function . '() requires finite non-negative integer arguments.',
                );
            }
        }

        return [(int) $arguments[0], (int) $arguments[1]];
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

    /** @param list<int|float> $arguments */
    private static function integerRoot(array $arguments): float
    {
        $value = (float) $arguments[0];
        $degree = (float) $arguments[1];
        $nearestDegree = \round($degree);
        $degreeTolerance = \min(
            1.0E-9,
            16.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($degree)),
        );
        if (\abs($degree - $nearestDegree) <= $degreeTolerance) {
            $degree = $nearestDegree;
        }
        if (
            $degree == 0.0
            || \floor($degree) !== $degree
            || \abs($degree) > 9_007_199_254_740_991.0
        ) {
            throw new \DomainException(
                'root() requires a nonzero integer degree within the exact integer range.',
            );
        }

        $order = \abs($degree);
        if ($value < 0.0 && \fmod($order, 2.0) === 0.0) {
            throw new \DomainException(
                'root() requires an odd degree for negative values.',
            );
        }
        if ($value == 0.0 && $degree < 0.0) {
            throw new \DomainException(
                'root() is undefined for zero with a negative degree.',
            );
        }

        $root = $value < 0.0
            ? -\pow(-$value, 1.0 / $order)
            : \pow($value, 1.0 / $order);

        return self::finiteSpecialResult(
            $degree < 0.0 ? 1.0 / $root : $root,
            'root',
        );
    }

    /** @param list<int|float> $arguments */
    private static function errorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::erf((float) $arguments[0]),
            'erf',
        );
    }

    /** @param list<int|float> $arguments */
    private static function complementaryErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::erfc((float) $arguments[0]),
            'erfc',
        );
    }

    /** @param list<int|float> $arguments */
    private static function scaledComplementaryErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::erfcx((float) $arguments[0]),
            'erfcx',
        );
    }

    /** @param list<int|float> $arguments */
    private static function imaginaryErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::erfi((float) $arguments[0]),
            'erfi',
        );
    }

    /** @param list<int|float> $arguments */
    private static function dawsonIntegral(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::dawson((float) $arguments[0]),
            'dawson',
        );
    }

    /** @param list<int|float> $arguments */
    private static function inverseErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::inverseErf((float) $arguments[0]),
            'erfinv',
        );
    }

    /** @param list<int|float> $arguments */
    private static function inverseComplementaryErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::inverseErfc((float) $arguments[0]),
            'erfcinv',
        );
    }

    /** @param list<int|float> $arguments */
    private static function gammaFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        $logMagnitude = self::logAbsoluteGamma($value);
        $magnitude = exp($logMagnitude);
        $periodicFraction = $value - (2.0 * round($value / 2.0));
        $sign = $value < 0.0 && sin(M_PI * $periodicFraction) < 0.0 ? -1.0 : 1.0;

        return self::finiteSpecialResult($sign * $magnitude, 'gamma');
    }

    /** @param list<int|float> $arguments */
    private static function regularizedGammaPFunction(array $arguments): float
    {
        return SpecialFunctions::regularizedGammaP((float) $arguments[0], (float) $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function regularizedGammaQFunction(array $arguments): float
    {
        return SpecialFunctions::regularizedGammaQ((float) $arguments[0], (float) $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function inverseRegularizedGammaPFunction(array $arguments): float
    {
        return SpecialFunctions::inverseRegularizedGammaP((float) $arguments[0], (float) $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function inverseRegularizedGammaQFunction(array $arguments): float
    {
        return SpecialFunctions::inverseRegularizedGammaQ((float) $arguments[0], (float) $arguments[1]);
    }

    /** @param list<int|float> $arguments */
    private static function logGammaFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!\is_finite($value) || ($value <= 0.0 && \floor($value) === $value)) {
            throw new \DomainException('lgamma() requires a finite non-pole argument.');
        }
        if ($value === 1.0 || $value === 2.0) {
            return 0.0;
        }

        return self::finiteSpecialResult(
            $value < 0.5 ? self::logAbsoluteGamma($value) : self::logGammaPositive($value),
            'lgamma',
        );
    }

    /** @param list<int|float> $arguments */
    private static function betaFunction(array $arguments): float
    {
        $first = (float) $arguments[0];
        $second = (float) $arguments[1];
        $sum = $first + $second;
        $virtualSecond = $sum - $first;
        $sumError = ($first - ($sum - $virtualSecond)) + ($second - $virtualSecond);
        if (
            !\is_finite($first)
            || !\is_finite($second)
            || $first < -171.0
            || $second < -171.0
            || ($first <= 0.0 && \floor($first) === $first)
            || ($second <= 0.0 && \floor($second) === $second)
        ) {
            throw new \DomainException(
                'beta() requires finite non-pole real arguments; each argument must be greater than -171.',
            );
        }
        if (!\is_finite($sum)) {
            throw new \OverflowException('beta() argument sum exceeds the finite range.');
        }
        if ($sum <= 0.0 && \floor($sum) === $sum && $sumError === 0.0) {
            return 0.0;
        }
        if ($sum < -171.0 || ($sum === -171.0 && $sumError < 0.0)) {
            throw new \DomainException('beta() argument sum is outside the supported real range.');
        }
        if ($sum <= 0.0 && \floor($sum) === $sum && $sumError !== 0.0) {
            $sum = self::adjacentFloat($sum, $sumError < 0.0 ? -1 : 1);
        }
        if ($first === 1.0) {
            return self::finiteSpecialResult(1.0 / $second, 'beta');
        }
        if ($second === 1.0) {
            return self::finiteSpecialResult(1.0 / $first, 'beta');
        }

        foreach ([[$first, $second], [$second, $first]] as [$integerShape, $otherShape]) {
            if (
                $integerShape >= 2.0
                && $integerShape <= 64.0
                && \floor($integerShape) === $integerShape
                && $otherShape > 0.0
            ) {
                $logResult = self::logGammaPositive($integerShape);
                for ($offset = 0; $offset < (int) $integerShape; ++$offset) {
                    $logResult -= \log($otherShape + $offset);
                }

                return self::finiteSpecialResult(\exp($logResult), 'beta');
            }
        }

        $logResult = self::logAbsoluteGammaForBeta($first)
            + self::logAbsoluteGammaForBeta($second)
            - self::logAbsoluteGammaForBeta($sum);
        $sign = self::gammaSign($first) * self::gammaSign($second) * self::gammaSign($sum);

        return self::finiteSpecialResult($sign * \exp($logResult), 'beta');
    }

    /** @param list<int|float> $arguments */
    private static function regularizedBetaFunction(array $arguments): float
    {
        return SpecialFunctions::regularizedBeta(
            (float) $arguments[0],
            (float) $arguments[1],
            (float) $arguments[2],
        );
    }

    /** @param list<int|float> $arguments */
    private static function regularizedBetaComplementFunction(array $arguments): float
    {
        return SpecialFunctions::regularizedBetaComplement(
            (float) $arguments[0],
            (float) $arguments[1],
            (float) $arguments[2],
        );
    }

    /** @param list<int|float> $arguments */
    private static function inverseRegularizedBetaFunction(array $arguments): float
    {
        return SpecialFunctions::inverseRegularizedBeta(
            (float) $arguments[0],
            (float) $arguments[1],
            (float) $arguments[2],
        );
    }

    /** @param list<int|float> $arguments */
    private static function inverseRegularizedBetaComplementFunction(array $arguments): float
    {
        return SpecialFunctions::inverseRegularizedBetaComplement(
            (float) $arguments[0],
            (float) $arguments[1],
            (float) $arguments[2],
        );
    }

    private static function logAbsoluteGammaForBeta(float $value): float
    {
        return $value > 0.5
            ? self::logGammaPositive($value)
            : self::logAbsoluteGamma($value);
    }

    private static function adjacentFloat(float $value, int $direction): float
    {
        $packedBits = \unpack('Jvalue', \pack('E', \abs($value)));
        $bits = $packedBits['value'] ?? null;
        if (!\is_int($bits)) {
            throw new \DomainException('beta() could not resolve its argument sum at float precision.');
        }

        $increaseMagnitude = ($value > 0.0) === ($direction > 0);
        $magnitude = \unpack(
            'E',
            \pack('J', $bits + ($increaseMagnitude ? 1 : -1)),
        )[1] ?? null;
        if (!\is_float($magnitude)) {
            throw new \DomainException('beta() could not resolve its argument sum at float precision.');
        }

        return $value > 0.0 ? $magnitude : -$magnitude;
    }

    private static function gammaSign(float $value): float
    {
        if ($value > 0.0) {
            return 1.0;
        }

        $fraction = $value - (2.0 * \round($value / 2.0));

        return \sin(\M_PI * $fraction) < 0.0 ? -1.0 : 1.0;
    }

    /** @param list<int|float> $arguments */
    private static function riemannZetaFunction(array $arguments): float
    {
        $argument = $arguments[0];
        $value = (float) $argument;
        if (!\is_finite($value) || $value === 1.0) {
            throw new \DomainException('zeta() requires a finite real argument other than 1.');
        }
        $distanceFromPole = $value - 1.0;
        if (\abs($distanceFromPole) < 1.0E-3) {
            return (1.0 / $distanceFromPole)
                + 0.5772156649015329
                + (0.07281584548367672 * $distanceFromPole)
                - (0.004845181596436159 * ($distanceFromPole ** 2));
        }
        if ($value >= 64.0) {
            return 1.0;
        }
        if ($value >= 0.0) {
            return SpecialFunctions::riemannZetaContinuation($value);
        }
        if ($value >= -0.5) {
            return SpecialFunctions::riemannZetaContinuation($value);
        }
        if (\abs($value) < 1.0E-8) {
            return -0.5 - (0.5 * $value * \log(2.0 * \M_PI));
        }
        if (
            (\is_int($argument) && $argument < 0 && $argument % 2 === 0)
            || (!\is_int($argument) && $value / 2.0 === \floor($value / 2.0))
        ) {
            return 0.0;
        }

        $positiveArgument = 1.0 - $value;
        $positiveValue = SpecialFunctions::riemannZetaContinuation($positiveArgument);
        $sine = \sin(0.5 * \M_PI * $value);
        $logMagnitude = ($value * \log(2.0))
            + (($value - 1.0) * \log(\M_PI))
            + self::logGammaPositive($positiveArgument)
            + \log(\abs($sine))
            + \log($positiveValue);

        return self::finiteSpecialResult(
            ($sine < 0.0 ? -1.0 : 1.0) * \exp($logMagnitude),
            'zeta',
        );
    }

    /** @param list<int|float> $arguments */
    private static function digammaFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::digamma((float) $arguments[0]),
            'digamma',
        );
    }

    /** @param list<int|float> $arguments */
    private static function trigammaFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::trigamma((float) $arguments[0]),
            'trigamma',
        );
    }

    /** @param list<int|float> $arguments */
    private static function tetragammaFunction(array $arguments): float
    {
        return self::finiteSpecialResult(
            SpecialFunctions::tetragamma((float) $arguments[0]),
            'tetragamma',
        );
    }

    /** @param list<int|float> $arguments */
    private static function polygammaFunction(array $arguments): float
    {
        $order = $arguments[0];
        if (
            $order < 0
            || $order > SpecialFunctions::MAX_POLYGAMMA_ORDER
            || \floor((float) $order) !== (float) $order
        ) {
            throw new \DomainException(\sprintf(
                'polygamma() order must be an integer from 0 through %d.',
                SpecialFunctions::MAX_POLYGAMMA_ORDER,
            ));
        }

        return self::finiteSpecialResult(
            SpecialFunctions::polygamma((int) $order, (float) $arguments[1]),
            'polygamma',
        );
    }

    /** @param list<int|float> $arguments */
    private static function besselJFunction(array $arguments): float
    {
        $order = (float) $arguments[0];
        if (
            !\is_finite($order)
            || \floor($order) !== $order
            || \abs($order) > SpecialFunctions::MAX_BESSEL_ORDER
        ) {
            throw new \DomainException(\sprintf(
                'besselj() order must be an integer from -%1$d through %1$d.',
                SpecialFunctions::MAX_BESSEL_ORDER,
            ));
        }

        return self::finiteSpecialResult(
            SpecialFunctions::besselJ((int) $order, (float) $arguments[1]),
            'besselj',
        );
    }

    /** @param list<int|float> $arguments */
    private static function besselIFunction(array $arguments): float
    {
        $order = (float) $arguments[0];
        if (
            !\is_finite($order)
            || \floor($order) !== $order
            || \abs($order) > SpecialFunctions::MAX_BESSEL_ORDER
        ) {
            throw new \DomainException(\sprintf(
                'besseli() order must be an integer from -%1$d through %1$d.',
                SpecialFunctions::MAX_BESSEL_ORDER,
            ));
        }

        return self::finiteSpecialResult(
            SpecialFunctions::besselI((int) $order, (float) $arguments[1]),
            'besseli',
        );
    }

    /** @param list<int|float> $arguments */
    private static function besselYFunction(array $arguments): float
    {
        $order = (float) $arguments[0];
        if (
            !\is_finite($order)
            || \floor($order) !== $order
            || \abs($order) > SpecialFunctions::MAX_BESSEL_ORDER
        ) {
            throw new \DomainException(\sprintf(
                'bessely() order must be an integer from -%1$d through %1$d.',
                SpecialFunctions::MAX_BESSEL_ORDER,
            ));
        }

        return self::finiteSpecialResult(
            SpecialFunctions::besselY((int) $order, (float) $arguments[1]),
            'bessely',
        );
    }

    /** @param list<int|float> $arguments */
    private static function lambertWFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        $branch = $arguments[1] ?? 0;
        if ($branch !== 0 && $branch !== 0.0 && $branch !== -1 && $branch !== -1.0) {
            throw new \DomainException('lambertw() supports only real branches 0 and -1.');
        }
        $branch = (int) $branch;
        $branchPoint = -1.0 / \M_E;
        if ($branch === 0) {
            if ($value < $branchPoint) {
                throw new \DomainException('The real principal lambertw() branch requires x >= -1/e.');
            }
            if ($value === $branchPoint) {
                return -1.0;
            }
            $branchDistance = 1.0 - ($value / $branchPoint);
            if ($branchDistance >= 0.0 && $branchDistance < 1.0E-8) {
                return self::lambertWNearBranchPoint($branchDistance, false);
            }
            if ($value === 0.0) {
                return 0.0;
            }
            if (\abs($value) < 1.0E-4) {
                $square = $value * $value;
                $result = $value * (
                    1.0 - $value + (1.5 * $square)
                    - ((8.0 / 3.0) * $square * $value)
                    + ((125.0 / 24.0) * $square * $square)
                );

                return self::finiteSpecialResult($result, 'lambertw');
            }
            if ($value < 0.0) {
                $lower = -1.0;
                $upper = 0.0;
                for ($iteration = 0; $iteration < 128; ++$iteration) {
                    $midpoint = $lower + (($upper - $lower) / 2.0);
                    if ($midpoint === $lower || $midpoint === $upper) {
                        break;
                    }
                    if ($midpoint * \exp($midpoint) < $value) {
                        $lower = $midpoint;
                    } else {
                        $upper = $midpoint;
                    }
                }

                return self::finiteSpecialResult($lower + (($upper - $lower) / 2.0), 'lambertw');
            }

            $logTarget = \log($value);
            $lower = 0.0;
            $upper = \max(1.0, $logTarget + 1.0);
            for ($iteration = 0; $iteration < 128; ++$iteration) {
                $midpoint = $lower + (($upper - $lower) / 2.0);
                if ($midpoint === $lower || $midpoint === $upper) {
                    break;
                }
                if ($midpoint + \log($midpoint) < $logTarget) {
                    $lower = $midpoint;
                } else {
                    $upper = $midpoint;
                }
            }

            return self::finiteSpecialResult($lower + (($upper - $lower) / 2.0), 'lambertw');
        }

        if ($value < $branchPoint || $value >= 0.0) {
            throw new \DomainException('The real lambertw() branch -1 requires -1/e <= x < 0.');
        }
        if ($value === $branchPoint) {
            return -1.0;
        }
        $branchDistance = 1.0 - ($value / $branchPoint);
        if ($branchDistance >= 0.0 && $branchDistance < 1.0E-8) {
            return self::lambertWNearBranchPoint($branchDistance, true);
        }

        $logTarget = \log(-$value);
        $lower = \min(-2.0, (2.0 * $logTarget) - 2.0);
        $upper = -1.0;
        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            if ($midpoint + \log(-$midpoint) < $logTarget) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return self::finiteSpecialResult($lower + (($upper - $lower) / 2.0), 'lambertw');
    }

    private static function lambertWNearBranchPoint(float $distance, bool $lowerBranch): float
    {
        $parameter = \sqrt($distance);
        if ($lowerBranch) {
            $parameter = -$parameter;
        }
        $parameterSquared = $parameter * $parameter;
        $result = -1.0 + ($parameter * \sqrt(2.0))
            - ((2.0 / 3.0) * $parameterSquared)
            + ((11.0 * \sqrt(2.0) / 36.0) * $parameterSquared * $parameter)
            - ((43.0 / 135.0) * $parameterSquared * $parameterSquared);

        return self::finiteSpecialResult($result, 'lambertw');
    }

    private static function logAbsoluteGamma(float $value): float
    {
        if (
            !is_finite($value)
            || abs($value) > 171.0
            || ($value <= 0.0 && floor($value) === $value)
        ) {
            throw new \DomainException(
                'gamma() requires a finite non-pole argument with magnitude at most 171.',
            );
        }
        if ($value === 1.0 || $value === 2.0) {
            return 0.0;
        }

        if ($value < 0.5) {
            $fraction = $value - round($value);
            $sine = sin(M_PI * $fraction);
            if ($sine === 0.0) {
                throw new \DomainException('gamma() is undefined at non-positive integers.');
            }

            return log(M_PI) - log(abs($sine)) - self::logGammaPositive(1.0 - $value);
        }

        return self::logGammaPositive($value);
    }

    private static function logGammaPositive(float $value): float
    {
        $coefficients = [
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];
        $shifted = $value - 1.0;
        $sum = 0.99999999999980993;
        foreach ($coefficients as $index => $coefficient) {
            $sum += $coefficient / ($shifted + $index + 1.0);
        }
        $base = $shifted + 7.5;

        return 0.5 * log(2.0 * M_PI)
            + (($shifted + 0.5) * log($base))
            - $base
            + log($sum);
    }

    private static function finiteSpecialResult(float $value, string $function): float
    {
        if (!is_finite($value)) {
            throw new \OverflowException($function . '() produced a non-finite result.');
        }

        return $value;
    }

    /**
     * @param list<int|float> $arguments
     */
    private static function sine(array $arguments): float
    {
        return \sin($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function sinc(array $arguments): float
    {
        return self::sincValue((float) $arguments[0], 'sinc');
    }

    /** @param list<int|float> $arguments */
    private static function sincPi(array $arguments): float
    {
        return self::sincValue(\M_PI * (float) $arguments[0], 'sincpi');
    }

    private static function sincValue(float $value, string $function): float
    {
        if (!\is_finite($value)) {
            throw new \OverflowException($function . '() cannot resolve a non-finite phase.');
        }
        if ($value === 0.0) {
            return 1.0;
        }
        if (\abs($value) < 1.0E-4) {
            $square = $value * $value;
            $fourth = $square * $square;
            $result = 1.0 - ($square / 6.0) + ($fourth / 120.0)
                - (($fourth * $square) / 5040.0);

            return self::finiteSpecialResult($result, $function);
        }

        return self::finiteSpecialResult(\sin($value) / $value, $function);
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
        $value = (float) $arguments[0];
        $cosine = \cos($value);
        if ($cosine === 0.0 || self::isTrigonometricPole($value, \M_PI_2)) {
            throw new \DomainException('tan() is undefined where cosine is zero.');
        }

        return \sin($arguments[0]) / $cosine;
    }

    /** @param list<int|float> $arguments */
    private static function secant(array $arguments): float
    {
        $value = (float) $arguments[0];
        $cosine = \cos($value);
        if ($cosine === 0.0 || self::isTrigonometricPole($value, \M_PI_2)) {
            throw new \DomainException('sec() is undefined where cosine is zero.');
        }

        return 1.0 / $cosine;
    }

    /** @param list<int|float> $arguments */
    private static function cosecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        $sine = \sin($value);
        if ($sine === 0.0 || self::isTrigonometricPole($value, 0.0)) {
            throw new \DomainException('csc() is undefined where sine is zero.');
        }

        return 1.0 / $sine;
    }

    /** @param list<int|float> $arguments */
    private static function cotangent(array $arguments): float
    {
        $value = (float) $arguments[0];
        $sine = \sin($value);
        if ($sine === 0.0 || self::isTrigonometricPole($value, 0.0)) {
            throw new \DomainException('cot() is undefined where sine is zero.');
        }

        return \cos($value) / $sine;
    }

    private static function isTrigonometricPole(float $value, float $phase): bool
    {
        $turns = \round(($value - $phase) / \M_PI);
        if (!\is_finite($turns)) {
            return false;
        }

        if ($phase === \M_PI_2) {
            if (\abs($turns) > 4_503_599_627_370_495.0) {
                return false;
            }

            return $value === (((2.0 * $turns) + 1.0) * \M_PI) / 2.0
                || $value === $phase + ($turns * \M_PI);
        }

        if (\abs($turns) > 9_007_199_254_740_991.0) {
            return false;
        }

        return $value === $turns * \M_PI;
    }

    /** @param list<int|float> $arguments */
    private static function arcSecant(array $arguments): float
    {
        if (\abs($arguments[0]) < 1.0) {
            throw new \DomainException('asec() requires an argument with absolute value greater than or equal to one.');
        }

        return \acos(1.0 / $arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcCosecant(array $arguments): float
    {
        if (\abs($arguments[0]) < 1.0) {
            throw new \DomainException('acsc() requires an argument with absolute value greater than or equal to one.');
        }

        return \asin(1.0 / $arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcCotangent(array $arguments): float
    {
        return \atan2(1.0, (float) $arguments[0]);
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
    private static function hyperbolicSecant(array $arguments): float
    {
        $absolute = \abs((float) $arguments[0]);
        $exponential = \exp(-$absolute);

        return (2.0 * $exponential) / (1.0 + ($exponential * $exponential));
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicCosecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if ($value == 0.0) {
            throw new \DomainException('csch() is undefined at zero.');
        }

        $absolute = \abs($value);
        if ($absolute < 1.0) {
            return self::finiteSpecialResult(1.0 / \sinh($value), 'csch');
        }

        $exponential = \exp(-$absolute);
        $result = (2.0 * $exponential) / (1.0 - ($exponential * $exponential));

        return $value < 0.0 ? -$result : $result;
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicCotangent(array $arguments): float
    {
        $value = (float) $arguments[0];
        if ($value == 0.0) {
            throw new \DomainException('coth() is undefined at zero.');
        }

        $absolute = \abs($value);
        if ($absolute < 1.0) {
            return self::finiteSpecialResult(\cosh($value) / \sinh($value), 'coth');
        }

        $exponential = \exp(-2.0 * $absolute);
        $result = (1.0 + $exponential) / (1.0 - $exponential);

        return $value < 0.0 ? -$result : $result;
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

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicSecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if ($value <= 0.0 || $value > 1.0) {
            throw new \DomainException('asech() requires an argument in the interval (0, 1].');
        }

        $radical = \sqrt(1.0 - ($value * $value));

        return \log1p($radical) - \log($value);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicCosecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if ($value == 0.0) {
            throw new \DomainException('acsch() is undefined at zero.');
        }

        $absolute = \abs($value);
        if ($absolute >= 1.0) {
            return \asinh(1.0 / $value);
        }

        $result = \log1p(\sqrt(1.0 + ($absolute * $absolute))) - \log($absolute);

        return $value < 0.0 ? -$result : $result;
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicCotangent(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (\abs($value) <= 1.0) {
            throw new \DomainException('acoth() requires an argument with absolute value greater than one.');
        }

        $reciprocal = 1.0 / $value;

        return 0.5 * (\log1p($reciprocal) - \log1p(-$reciprocal));
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
    private static function truncate(array $arguments): float
    {
        return $arguments[0] < 0.0
            ? \ceil($arguments[0])
            : \floor($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function greatestCommonDivisor(array $arguments): int
    {
        $left = self::requireInteger($arguments[0], 'gcd');
        $right = self::requireInteger($arguments[1], 'gcd');

        if ($left === \PHP_INT_MIN || $right === \PHP_INT_MIN) {
            if ($left === 0 || $right === 0) {
                throw new \OverflowException('gcd() result exceeds the host integer range.');
            }
            if ($left === \PHP_INT_MIN && $right === \PHP_INT_MIN) {
                throw new \OverflowException('gcd() result exceeds the host integer range.');
            }

            $otherMagnitude = \abs($left === \PHP_INT_MIN ? $right : $left);
            $remainder = ((\PHP_INT_MAX % $otherMagnitude) + 1) % $otherMagnitude;

            return self::greatestCommonDivisorPair($otherMagnitude, $remainder);
        }

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

        if ($left === \PHP_INT_MIN || $right === \PHP_INT_MIN) {
            throw new \OverflowException('lcm() result exceeds the host integer range.');
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

    private static function greatestCommonDivisorPair(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }

    private static function requireInteger(int|float $value, string $function): int
    {
        if (\is_int($value)) {
            return $value;
        }

        $numericValue = (float) $value;
        $nearestInteger = \round($numericValue);
        $roundingTolerance = \min(
            1.0E-9,
            16.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($nearestInteger)),
        );
        if (\abs($numericValue - $nearestInteger) <= $roundingTolerance) {
            $numericValue = $nearestInteger;
        }

        $integerLimit = 2.0 ** (\PHP_INT_SIZE * 8 - 1);
        if (
            !\is_finite($numericValue)
            || \floor($numericValue) !== $numericValue
            || $numericValue < -$integerLimit
            || $numericValue >= $integerLimit
        ) {
            throw new \DomainException($function . '() requires finite integer arguments.');
        }

        return (int) $numericValue;
    }
}
