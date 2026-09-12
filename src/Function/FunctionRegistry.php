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
                'erfi',
                1,
                1,
                self::imaginaryErrorFunction(...),
            ),
            new FunctionDefinition(
                'gamma',
                1,
                1,
                self::gammaFunction(...),
            ),
            new FunctionDefinition(
                'lgamma',
                1,
                1,
                self::logGammaFunction(...),
            ),
            new FunctionDefinition(
                'gammainc',
                2,
                2,
                self::regularizedGammaLower(...),
            ),
            new FunctionDefinition(
                'gammaincc',
                2,
                2,
                self::regularizedGammaUpper(...),
            ),
            new FunctionDefinition(
                'beta',
                2,
                2,
                self::betaFunction(...),
            ),
            new FunctionDefinition(
                'logbeta',
                2,
                2,
                self::logBetaFunction(...),
            ),
            new FunctionDefinition(
                'zeta',
                1,
                1,
                self::zetaFunction(...),
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
                'polygamma',
                2,
                2,
                self::polygammaFunction(...),
            ),
            new FunctionDefinition(
                'besselj',
                2,
                2,
                self::besselJ(...),
            ),
            new FunctionDefinition(
                'besseli',
                2,
                2,
                self::modifiedBesselI(...),
            ),
            new FunctionDefinition(
                'bessely',
                2,
                2,
                self::besselY(...),
            ),
            new FunctionDefinition(
                'besselk',
                2,
                2,
                self::modifiedBesselK(...),
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
                'lambertw',
                1,
                2,
                self::lambertW(...),
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
                'sinc',
                1,
                1,
                self::sinc(...),
            ),
            new FunctionDefinition(
                'sinhc',
                1,
                1,
                self::sinhc(...),
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
            new FunctionDefinition(
                'factorial',
                1,
                1,
                self::factorialFunction(...),
            ),
            new FunctionDefinition(
                'binomial',
                2,
                2,
                self::binomial(...),
            ),
            new FunctionDefinition(
                'choose',
                2,
                2,
                self::binomial(...),
            ),
            new FunctionDefinition(
                'ncr',
                2,
                2,
                self::binomial(...),
            ),
            new FunctionDefinition(
                'permutation',
                2,
                2,
                self::permutation(...),
            ),
            new FunctionDefinition(
                'permute',
                2,
                2,
                self::permutation(...),
            ),
            new FunctionDefinition(
                'npr',
                2,
                2,
                self::permutation(...),
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

    /** @param list<int|float> $arguments */
    private static function errorFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value)) {
            throw new \DomainException('erf() requires a finite argument.');
        }
        if ($value == 0.0) {
            return 0.0;
        }

        // Abramowitz and Stegun 7.1.26: bounded error below 1.5e-7.
        $sign = $value < 0.0 ? -1.0 : 1.0;
        $absolute = abs($value);
        $t = 1.0 / (1.0 + 0.3275911 * $absolute);
        $polynomial = (
            (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592)
            * $t
        );
        $result = $sign * (1.0 - $polynomial * exp(-$absolute * $absolute));

        return self::finiteSpecialResult($result, 'erf');
    }

    /** @param list<int|float> $arguments */
    private static function complementaryErrorFunction(array $arguments): float
    {
        return self::finiteSpecialResult(1.0 - self::errorFunction($arguments), 'erfc');
    }

    /** @param list<int|float> $arguments */
    private static function imaginaryErrorFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value)) {
            throw new \DomainException('erfi() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 0.0;
        }
        $sign = $value < 0.0 ? -1.0 : 1.0;
        $absolute = abs($value);
        $square = $absolute * $absolute;
        if ($square < 500.0) {
            $term = $absolute;
            $sum = $term;
            for ($index = 1; $index <= 1000; ++$index) {
                $term *= $square / $index;
                $addend = $term / (2.0 * $index + 1.0);
                $sum += $addend;
                if (abs($addend) <= abs($sum) * 1.0E-15) {
                    break;
                }
            }
            return self::finiteSpecialResult($sign * (2.0 / sqrt(M_PI)) * $sum, 'erfi');
        }

        $term = 1.0;
        $sum = 1.0;
        for ($index = 1; $index <= 1000; ++$index) {
            $term *= (2.0 * $index - 1.0) / (2.0 * $square);
            $sum += $term;
            if (abs($term) <= abs($sum) * 1.0E-15) {
                break;
            }
        }
        $result = exp($square) * $sum / (sqrt(M_PI) * $absolute);
        return self::finiteSpecialResult($sign * $result, 'erfi');
    }

    /** @param list<int|float> $arguments */
    private static function sinc(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value)) {
            throw new \DomainException('sinc() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 1.0;
        }

        return sin($value) / $value;
    }

    /** @param list<int|float> $arguments */
    private static function sinhc(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value)) {
            throw new \DomainException('sinhc() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 1.0;
        }

        return self::finiteSpecialResult(sinh($value) / $value, 'sinhc');
    }

    /** @param list<int|float> $arguments */
    private static function gammaFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value) || abs($value) > 171.0 || ($value <= 0.0 && floor($value) === $value)) {
            throw new \DomainException('gamma() requires a finite non-pole argument with magnitude at most 171.');
        }

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
        if ($value < 0.5) {
            $sine = sin(M_PI * $value);
            if (abs($sine) <= 1.0e-15) {
                throw new \DomainException('gamma() is undefined at non-positive integers.');
            }

            return self::finiteSpecialResult(M_PI / ($sine * self::gammaFunction([1.0 - $value])), 'gamma');
        }

        $shifted = $value - 1.0;
        $sum = 0.99999999999980993;
        foreach ($coefficients as $index => $coefficient) {
            $sum += $coefficient / ($shifted + $index + 1.0);
        }
        $base = $shifted + 7.5;
        $result = sqrt(2.0 * M_PI) * ($base ** ($shifted + 0.5)) * exp(-$base) * $sum;

        return self::finiteSpecialResult($result, 'gamma');
    }

    /** @param list<int|float> $arguments */
    private static function logGammaFunction(array $arguments): float
    {
        $gamma = self::gammaFunction($arguments);

        return self::finiteSpecialResult(log(abs($gamma)), 'lgamma');
    }

    /** @param list<int|float> $arguments */
    private static function regularizedGammaLower(array $arguments): float
    {
        [$shape, $value] = self::requireIncompleteGammaArguments($arguments);
        if ($value === 0.0) {
            return 0.0;
        }
        if ($value >= $shape + 1.0) {
            return self::finiteSpecialResult(1.0 - self::regularizedGammaUpperValue($shape, $value), 'gammainc');
        }
        $term = 1.0 / $shape;
        $sum = $term;
        for ($index = 1; $index <= 10000; ++$index) {
            $term *= $value / ($shape + $index);
            $sum += $term;
            if (abs($term) <= abs($sum) * 1.0E-15) {
                break;
            }
        }
        $prefactor = exp(-$value + $shape * log($value) - self::logGammaPositive($shape));
        return self::finiteSpecialResult($prefactor * $sum, 'gammainc');
    }

    /** @param list<int|float> $arguments */
    private static function regularizedGammaUpper(array $arguments): float
    {
        [$shape, $value] = self::requireIncompleteGammaArguments($arguments);
        if ($value === 0.0) {
            return 1.0;
        }
        return self::finiteSpecialResult(self::regularizedGammaUpperValue($shape, $value), 'gammaincc');
    }

    /**
     * @param list<int|float> $arguments
     * @return array{0: float, 1: float}
     */
    private static function requireIncompleteGammaArguments(array $arguments): array
    {
        $shape = (float) $arguments[0];
        $value = (float) $arguments[1];
        if (!is_finite($shape) || $shape <= 0.0 || $shape > 171.0 || !is_finite($value) || $value < 0.0) {
            throw new \DomainException(
                'gammainc() and gammaincc() require a finite shape in (0, 171] and a non-negative value.',
            );
        }

        return [$shape, $value];
    }

    private static function regularizedGammaUpperValue(float $shape, float $value): float
    {
        if ($value < $shape + 1.0) {
            return 1.0 - self::regularizedGammaLower([$shape, $value]);
        }
        $fpmIn = 1.0E-300;
        $b = $value + 1.0 - $shape;
        $c = 1.0 / $fpmIn;
        $d = 1.0 / max($b, $fpmIn);
        $h = $d;
        for ($index = 1; $index <= 10000; ++$index) {
            $an = -$index * ($index - $shape);
            $b += 2.0;
            $d = $an * $d + $b;
            if (abs($d) < $fpmIn) {
                $d = $fpmIn;
            }
            $c = $b + $an / $c;
            if (abs($c) < $fpmIn) {
                $c = $fpmIn;
            }
            $d = 1.0 / $d;
            $delta = $d * $c;
            $h *= $delta;
            if (abs($delta - 1.0) <= 1.0E-15) {
                break;
            }
        }
        $prefactor = exp(-$value + $shape * log($value) - self::logGammaPositive($shape));

        return $prefactor * $h;
    }

    private static function logGammaPositive(float $value): float
    {
        return log(self::gammaFunction([$value]));
    }

    /** @param list<int|float> $arguments */
    private static function betaFunction(array $arguments): float
    {
        self::requirePositiveBetaArguments($arguments);
        $logValue = self::logBetaFunction($arguments);

        return self::finiteSpecialResult(exp($logValue), 'beta');
    }

    /** @param list<int|float> $arguments */
    private static function logBetaFunction(array $arguments): float
    {
        self::requirePositiveBetaArguments($arguments);
        $left = log(self::gammaFunction([$arguments[0]]));
        $right = log(self::gammaFunction([$arguments[1]]));
        $sum = log(self::gammaFunction([(float) $arguments[0] + (float) $arguments[1]]));
        $value = $left + $right - $sum;

        return self::finiteSpecialResult($value, 'logbeta');
    }

    /** @param list<int|float> $arguments */
    private static function requirePositiveBetaArguments(array $arguments): void
    {
        if (
            !is_finite((float) $arguments[0])
            || !is_finite((float) $arguments[1])
            || $arguments[0] <= 0
            || $arguments[1] <= 0
        ) {
            throw new \DomainException('beta() and logbeta() require two finite positive arguments.');
        }
    }

    /** @param list<int|float> $arguments */
    private static function zetaFunction(array $arguments): float
    {
        $exponent = (float) $arguments[0];
        if (!is_finite($exponent) || $exponent <= 1.0) {
            throw new \DomainException('zeta() currently requires a finite real argument greater than one.');
        }

        $limit = 128;
        $sum = 0.0;
        for ($index = 1; $index <= $limit; ++$index) {
            $sum += $index ** (-$exponent);
        }
        $tail = $limit ** (1.0 - $exponent) / ($exponent - 1.0) - 0.5 * $limit ** (-$exponent);
        $bernoulli = [
            1 => 1.0 / 6.0,
            2 => -1.0 / 30.0,
            3 => 1.0 / 42.0,
            4 => -1.0 / 30.0,
            5 => 5.0 / 66.0,
            6 => -691.0 / 2730.0,
            7 => 7.0 / 6.0,
            8 => -3617.0 / 510.0,
        ];
        $correction = 0.0;
        foreach ($bernoulli as $index => $number) {
            $order = 2 * $index;
            $rising = 1.0;
            $factorial = 1.0;
            for ($factor = 0; $factor < $order - 1; ++$factor) {
                $rising *= $exponent + $factor;
            }
            for ($factor = 2; $factor <= $order; ++$factor) {
                $factorial *= $factor;
            }
            $correction += $number / $factorial * $rising * $limit ** (-$exponent - $order + 1.0);
        }

        return self::finiteSpecialResult($sum + $tail + $correction, 'zeta');
    }

    /** @param list<int|float> $arguments */
    private static function digammaFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        self::requireGammaDerivativeArgument($value, 'digamma');
        if ($value < 0.5) {
            $reflected = self::digammaFunction([1.0 - $value]) - M_PI / tan(M_PI * $value);

            return self::finiteSpecialResult($reflected, 'digamma');
        }

        $result = 0.0;
        while ($value < 16.0) {
            $result -= 1.0 / $value;
            $value += 1.0;
        }
        $inverse = 1.0 / $value;
        $inverseSquared = $inverse * $inverse;
        $result += log($value) - 0.5 * $inverse - $inverseSquared * (
            1.0 / 12.0 - $inverseSquared * (1.0 / 120.0 - $inverseSquared * (1.0 / 252.0 - $inverseSquared / 240.0))
        );

        return self::finiteSpecialResult($result, 'digamma');
    }

    /** @param list<int|float> $arguments */
    private static function trigammaFunction(array $arguments): float
    {
        $value = (float) $arguments[0];
        self::requireGammaDerivativeArgument($value, 'trigamma');
        if ($value < 0.5) {
            $sine = sin(M_PI * $value);
            $reflected = (M_PI * M_PI) / ($sine * $sine) - self::trigammaFunction([1.0 - $value]);

            return self::finiteSpecialResult($reflected, 'trigamma');
        }

        $result = 0.0;
        while ($value < 16.0) {
            $result += 1.0 / ($value * $value);
            $value += 1.0;
        }
        $inverse = 1.0 / $value;
        $inverseSquared = $inverse * $inverse;
        $result += $inverse + 0.5 * $inverseSquared + $inverseSquared * $inverse / 6.0
            - $inverseSquared ** 2 * $inverse / 30.0
            + $inverseSquared ** 3 * $inverse / 42.0
            - $inverseSquared ** 4 * $inverse / 30.0;

        return self::finiteSpecialResult($result, 'trigamma');
    }

    private static function requireGammaDerivativeArgument(float $value, string $function): void
    {
        if (!is_finite($value) || ($value <= 0.0 && floor($value) === $value)) {
            throw new \DomainException($function . '() requires a finite non-pole real argument.');
        }
    }

    /** @param list<int|float> $arguments */
    private static function polygammaFunction(array $arguments): float
    {
        $order = (float) $arguments[0];
        $value = (float) $arguments[1];
        if (!is_finite($order) || floor($order) !== $order || $order < 0.0 || $order > 32.0) {
            throw new \DomainException('polygamma() requires an integer order from 0 through 32.');
        }
        self::requireGammaDerivativeArgument($value, 'polygamma');
        $integerOrder = (int) $order;
        if ($integerOrder === 0) {
            return self::digammaFunction([$value]);
        }
        if ($integerOrder === 1) {
            return self::trigammaFunction([$value]);
        }

        $factorial = 1.0;
        for ($index = 2; $index <= $integerOrder; ++$index) {
            $factorial *= $index;
        }
        $seriesSign = $integerOrder % 2 === 0 ? -1.0 : 1.0;
        $recurrenceSign = -$seriesSign;
        $result = 0.0;
        while ($value <= 16.0) {
            $result -= $recurrenceSign * $factorial / ($value ** ($integerOrder + 1));
            $value += 1.0;
        }
        $termScale = $seriesSign * $factorial;
        $sum = 0.0;
        $index = 0;
        for (; $index < 200000; ++$index) {
            $term = $termScale / (($value + $index) ** ($integerOrder + 1));
            $sum += $term;
            if (abs($term) <= max(1.0, abs($sum)) * 1.0E-15) {
                break;
            }
        }
        $power = $integerOrder + 1;
        $tailBase = $value + $index + 1.0;
        $tail = ($tailBase ** (1 - $power)) / ($power - 1)
            + 0.5 * ($tailBase ** (-$power))
            + ($power / 12.0) * ($tailBase ** (-$power - 1));
        $sum += $termScale * $tail;

        return self::finiteSpecialResult($result + $sum, 'polygamma');
    }

    /** @param list<int|float> $arguments */
    private static function besselJ(array $arguments): float
    {
        $order = self::requireIntegerOrder($arguments[0], 'besselj');
        $value = (float) $arguments[1];
        if (!is_finite($value)) {
            throw new \DomainException('besselj() requires a finite real argument.');
        }
        if ($value === 0.0) {
            return $order === 0 ? 1.0 : 0.0;
        }

        $sign = 1.0;
        if ($order < 0) {
            $order = -$order;
            if ($order % 2 !== 0) {
                $sign = -1.0;
            }
        }
        if ($value < 0.0) {
            if ($order % 2 !== 0) {
                $sign *= -1.0;
            }
            $value = -$value;
        }

        $result = $value <= 40.0
            ? self::besselSeries($order, $value, false)
            : self::besselJAsymptotic($order, $value);
        return self::finiteSpecialResult($sign * $result, 'besselj');
    }

    /** @param list<int|float> $arguments */
    private static function modifiedBesselI(array $arguments): float
    {
        $order = self::requireIntegerOrder($arguments[0], 'besseli');
        $value = (float) $arguments[1];
        if (!is_finite($value)) {
            throw new \DomainException('besseli() requires a finite real argument.');
        }
        if ($value === 0.0) {
            return $order === 0 ? 1.0 : 0.0;
        }

        $sign = 1.0;
        if ($order < 0) {
            $order = -$order;
        }
        if ($value < 0.0) {
            if ($order % 2 !== 0) {
                $sign *= -1.0;
            }
            $value = -$value;
        }

        $result = $value <= 40.0
            ? self::besselSeries($order, $value, true)
            : self::besselIAsymptotic($order, $value);
        return self::finiteSpecialResult($sign * $result, 'besseli');
    }

    /** @param list<int|float> $arguments */
    private static function besselY(array $arguments): float
    {
        $order = self::requireIntegerOrder($arguments[0], 'bessely');
        $value = (float) $arguments[1];
        if (!is_finite($value) || $value <= 0.0) {
            throw new \DomainException('bessely() requires a finite positive real argument.');
        }
        $sign = 1.0;
        if ($order < 0) {
            $order = -$order;
            if ($order % 2 !== 0) {
                $sign = -1.0;
            }
        }
        if ($value > 40.0) {
            return self::finiteSpecialResult($sign * self::besselYAsymptotic($order, $value), 'bessely');
        }
        [$previous, $current] = self::besselYSeriesSeeds($value);
        if ($order === 0) {
            return self::finiteSpecialResult($sign * $previous, 'bessely');
        }
        if ($order === 1) {
            return self::finiteSpecialResult($sign * $current, 'bessely');
        }
        for ($index = 1; $index < $order; ++$index) {
            [$previous, $current] = [$current, (2.0 * $index / $value) * $current - $previous];
        }

        return self::finiteSpecialResult($sign * $current, 'bessely');
    }

    /** @param list<int|float> $arguments */
    private static function modifiedBesselK(array $arguments): float
    {
        $order = self::requireIntegerOrder($arguments[0], 'besselk');
        $value = (float) $arguments[1];
        if (!is_finite($value) || $value <= 0.0) {
            throw new \DomainException('besselk() requires a finite positive real argument.');
        }
        $order = abs($order);
        if ($value > 40.0) {
            return self::finiteSpecialResult(self::besselKAsymptotic($order, $value), 'besselk');
        }
        [$previous, $current] = self::besselKSeriesSeeds($value);
        if ($order === 0) {
            return self::finiteSpecialResult($previous, 'besselk');
        }
        if ($order === 1) {
            return self::finiteSpecialResult($current, 'besselk');
        }
        for ($index = 1; $index < $order; ++$index) {
            [$previous, $current] = [$current, $previous + (2.0 * $index / $value) * $current];
        }

        return self::finiteSpecialResult($current, 'besselk');
    }

    /** @return array{0: float, 1: float} */
    private static function besselYSeriesSeeds(float $value): array
    {
        $quarter = ($value / 2.0) ** 2;
        $j0 = self::besselSeries(0, $value, false);
        $j1 = self::besselSeries(1, $value, false);
        $logTerm = log($value / 2.0) + 0.5772156649015328606;
        $series = 0.0;
        $derivativeSeries = 0.0;
        $term = 1.0;
        $harmonic = 0.0;
        for ($index = 1; $index <= 1000; ++$index) {
            $term *= -$quarter / ($index * $index);
            $harmonic += 1.0 / $index;
            $series -= $term * $harmonic;
            $derivativeSeries -= $term * $harmonic * (2.0 * $index / $value);
            if (abs($term) <= max(1.0, abs($series)) * 1.0E-16) {
                break;
            }
        }
        $factor = 2.0 / M_PI;
        $y0 = $factor * ($logTerm * $j0 + $series);
        $y1 = -$factor * ($j0 / $value - $logTerm * $j1 + $derivativeSeries);

        return [$y0, $y1];
    }

    /** @return array{0: float, 1: float} */
    private static function besselKSeriesSeeds(float $value): array
    {
        $quarter = ($value / 2.0) ** 2;
        $i0 = self::besselSeries(0, $value, true);
        $i1 = self::besselSeries(1, $value, true);
        $logTerm = log($value / 2.0) + 0.5772156649015328606;
        $series = 0.0;
        $derivativeSeries = 0.0;
        $term = 1.0;
        $harmonic = 0.0;
        for ($index = 1; $index <= 1000; ++$index) {
            $term *= $quarter / ($index * $index);
            $harmonic += 1.0 / $index;
            $series += $term * $harmonic;
            $derivativeSeries += $term * $harmonic * (2.0 * $index / $value);
            if (abs($term) <= max(1.0, abs($series)) * 1.0E-16) {
                break;
            }
        }
        $k0 = -$logTerm * $i0 + $series;
        $k1 = $i0 / $value + $logTerm * $i1 - $derivativeSeries;

        return [$k0, $k1];
    }

    private static function requireIntegerOrder(int|float $value, string $function): int
    {
        if (!is_finite((float) $value) || floor((float) $value) !== (float) $value || abs((float) $value) > 100000.0) {
            throw new \DomainException($function . '() currently requires a finite integer order.');
        }

        return (int) $value;
    }

    private static function besselSeries(int $order, float $value, bool $modified): float
    {
        $half = $value / 2.0;
        $term = 1.0;
        for ($index = 1; $index <= $order; ++$index) {
            $term *= $half / $index;
        }
        $sum = $term;
        $square = $half * $half;
        for ($index = 1; $index <= 1000; ++$index) {
            $term *= $square / ($index * ($index + $order));
            if (!$modified) {
                $term *= -1.0;
            }
            $sum += $term;
            if (abs($term) <= max(1.0, abs($sum)) * 1.0E-15) {
                break;
            }
        }

        return $sum;
    }

    private static function besselJAsymptotic(int $order, float $value): float
    {
        $mu = 4.0 * $order * $order;
        $z = 8.0 * $value;
        $phase = $value - $order * M_PI / 2.0 - M_PI / 4.0;
        $first = ($mu - 1.0) / $z;
        $second = ($mu - 1.0) * ($mu - 9.0) / (2.0 * $z * $z);
        $third = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) * ($mu - 49.0) / (24.0 * $z ** 4);
        $sineCorrection = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) / (6.0 * $z ** 3);
        $amplitude = sqrt(2.0 / (M_PI * $value));

        return $amplitude * ((1.0 - $second + $third) * cos($phase) - ($first - $sineCorrection) * sin($phase));
    }

    private static function besselYAsymptotic(int $order, float $value): float
    {
        $mu = 4.0 * $order * $order;
        $z = 8.0 * $value;
        $phase = $value - $order * M_PI / 2.0 - M_PI / 4.0;
        $first = ($mu - 1.0) / $z;
        $second = ($mu - 1.0) * ($mu - 9.0) / (2.0 * $z * $z);
        $third = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) * ($mu - 49.0) / (24.0 * $z ** 4);
        $sineCorrection = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) / (6.0 * $z ** 3);
        $amplitude = sqrt(2.0 / (M_PI * $value));

        return $amplitude * ((1.0 - $second + $third) * sin($phase) + ($first - $sineCorrection) * cos($phase));
    }

    private static function besselKAsymptotic(int $order, float $value): float
    {
        $mu = 4.0 * $order * $order;
        $inverse = 1.0 / (8.0 * $value);
        $first = ($mu - 1.0) * $inverse;
        $second = ($mu - 1.0) * ($mu - 9.0) * $inverse ** 2 / 2.0;
        $third = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) * $inverse ** 3 / 6.0;
        $fourth = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) * ($mu - 49.0) * $inverse ** 4 / 24.0;
        $amplitude = sqrt(M_PI / (2.0 * $value)) * exp(-$value);

        return $amplitude * (1.0 + $first + $second + $third + $fourth);
    }

    private static function besselIAsymptotic(int $order, float $value): float
    {
        $mu = 4.0 * $order * $order;
        $z = 8.0 * $value;
        $first = ($mu - 1.0) / $z;
        $second = ($mu - 1.0) * ($mu - 9.0) / (2.0 * $z * $z);
        $third = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) / (6.0 * $z ** 3);
        $fourth = ($mu - 1.0) * ($mu - 9.0) * ($mu - 25.0) * ($mu - 49.0) / (24.0 * $z ** 4);
        $amplitude = exp($value) / sqrt(2.0 * M_PI * $value);

        return $amplitude * (1.0 - $first + $second - $third + $fourth);
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
        return \M_PI / 2.0 - \atan($arguments[0]);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicSecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value) || $value <= 0.0 || $value > 1.0) {
            throw new \DomainException(
                'asech() requires a finite argument strictly greater than zero and at most one.',
            );
        }

        return \acosh(1.0 / $value);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicCosecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value) || abs($value) <= 1.0E-15) {
            throw new \DomainException('acsch() requires a finite non-zero argument.');
        }

        return \asinh(1.0 / $value);
    }

    /** @param list<int|float> $arguments */
    private static function arcHyperbolicCotangent(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (!is_finite($value) || abs($value) <= 1.0) {
            throw new \DomainException('acoth() requires a finite argument with absolute value greater than one.');
        }

        return \atanh(1.0 / $value);
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
        $value = (float) $arguments[0];
        $absolute = \abs($value);
        if ($absolute > 20.0) {
            $decay = \exp(-$absolute);
            $result = 2.0 * $decay / (1.0 + $decay * $decay);

            return self::finiteSpecialResult($result, 'sech');
        }

        return self::finiteSpecialResult(1.0 / \cosh($value), 'sech');
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicCosecant(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (\abs($value) <= 1.0E-15) {
            throw new \DomainException('csch() is undefined at zero.');
        }

        $absolute = \abs($value);
        if ($absolute <= 20.0) {
            return self::finiteSpecialResult(1.0 / \sinh($value), 'csch');
        }

        $decay = \exp(-$absolute);
        $result = 2.0 * $decay / (1.0 - $decay * $decay);
        if ($value < 0.0) {
            $result = -$result;
        }

        return self::finiteSpecialResult($result, 'csch');
    }

    /** @param list<int|float> $arguments */
    private static function hyperbolicCotangent(array $arguments): float
    {
        $value = (float) $arguments[0];
        if (\abs($value) <= 1.0E-15) {
            throw new \DomainException('coth() is undefined at zero.');
        }

        $absolute = \abs($value);
        if ($absolute <= 20.0) {
            return self::finiteSpecialResult(\cosh($value) / \sinh($value), 'coth');
        }

        $decay = \exp(-$absolute);
        $result = (1.0 + $decay * $decay) / (1.0 - $decay * $decay);
        if ($value < 0.0) {
            $result = -$result;
        }

        return self::finiteSpecialResult($result, 'coth');
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
     * Evaluate a real Lambert-W branch. The optional second argument selects
     * branch 0 (the default) or branch -1 on the interval [-1/e, 0).
     *
     * @param list<int|float> $arguments
     */
    private static function lambertW(array $arguments): float
    {
        $value = (float) $arguments[0];
        $branch = isset($arguments[1]) ? (float) $arguments[1] : 0.0;
        if (
            !is_finite($value)
            || !is_finite($branch)
            || floor($branch) !== $branch
            || !in_array((int) $branch, [0, -1], true)
        ) {
            throw new \DomainException('lambertw() supports finite real values and branches 0 or -1.');
        }
        $branch = (int) $branch;
        $critical = -1.0 / M_E;
        if ($branch === 0 && $value < $critical) {
            throw new \DomainException(
                'lambertw() principal branch requires an argument greater than or equal to -1/e.',
            );
        }
        if ($branch === -1 && ($value < $critical || $value >= 0.0)) {
            throw new \DomainException('lambertw() branch -1 requires an argument in [-1/e, 0).');
        }
        if (abs($value - $critical) <= 1.0e-15) {
            return -1.0;
        }
        if ($value === 0.0) {
            return 0.0;
        }

        if ($branch === -1) {
            $logValue = log(-$value);
            $estimate = $logValue - log(-$logValue);
            if ($value < -0.25) {
                $estimate = -1.0 - sqrt(2.0 * (1.0 + M_E * $value));
            }
        } elseif ($value < 1.0) {
            $estimate = $value;
        } else {
            $estimate = log($value);
            $estimate -= log(max(1.0, $estimate));
        }

        for ($iteration = 0; $iteration < 100; ++$iteration) {
            $exponential = exp($estimate);
            $residual = $estimate * $exponential - $value;
            $denominator = $exponential * ($estimate + 1.0);
            if (abs($estimate + 1.0) > 1.0e-12) {
                $denominator -= (($estimate + 2.0) * $residual) / (2.0 * $estimate + 2.0);
            }
            if (!is_finite($denominator) || abs($denominator) <= 1.0e-15) {
                $denominator = $exponential * ($estimate + 1.0);
            }
            if (!is_finite($denominator) || abs($denominator) <= 1.0e-15) {
                throw new \OverflowException('lambertw() failed to converge to a finite result.');
            }
            $delta = $residual / $denominator;
            $estimate -= $delta;
            if (!is_finite($estimate)) {
                throw new \OverflowException('lambertw() produced a non-finite result.');
            }
            if (abs($delta) <= 1.0e-13 * max(1.0, abs($estimate))) {
                return $estimate;
            }
        }

        if (abs($estimate * exp($estimate) - $value) <= 1.0e-10 * max(1.0, abs($value))) {
            return $estimate;
        }
        throw new \OverflowException('lambertw() failed to converge to a finite result.');
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

    /** @param list<int|float> $arguments */
    private static function factorialFunction(array $arguments): int
    {
        $operand = self::requireNonNegativeInteger($arguments[0], 'factorial');

        return self::factorialValue($operand, 'factorial');
    }

    /** @param list<int|float> $arguments */
    private static function binomial(array $arguments): int
    {
        $n = self::requireNonNegativeInteger($arguments[0], 'binomial');
        $k = self::requireNonNegativeInteger($arguments[1], 'binomial');
        if ($k > $n) {
            throw new \DomainException('binomial() requires k to be less than or equal to n.');
        }

        $k = min($k, $n - $k);
        $result = 1;
        for ($index = 1; $index <= $k; ++$index) {
            $numerator = $n - $k + $index;
            $denominator = $index;
            $common = self::integerGcd($numerator, $denominator);
            $numerator = intdiv($numerator, $common);
            $denominator = intdiv($denominator, $common);
            $common = self::integerGcd($result, $denominator);
            $result = intdiv($result, $common);
            $denominator = intdiv($denominator, $common);
            if ($denominator !== 1) {
                throw new \LogicException('binomial() reduction failed to produce an integer.');
            }
            if ($result > intdiv(\PHP_INT_MAX, $numerator)) {
                throw new \OverflowException('binomial() result exceeds the host integer range.');
            }
            $result *= $numerator;
        }

        return $result;
    }

    /** @param list<int|float> $arguments */
    private static function permutation(array $arguments): int
    {
        $n = self::requireNonNegativeInteger($arguments[0], 'permutation');
        $k = self::requireNonNegativeInteger($arguments[1], 'permutation');
        if ($k > $n) {
            throw new \DomainException('permutation() requires k to be less than or equal to n.');
        }
        if ($k === 0) {
            return 1;
        }

        $result = 1;
        for ($value = $n - $k + 1; $value <= $n; ++$value) {
            if ($value !== 0 && $result > intdiv(\PHP_INT_MAX, $value)) {
                throw new \OverflowException('permutation() result exceeds the host integer range.');
            }
            $result *= $value;
            if ($value === $n) {
                break;
            }
        }

        return $result;
    }

    private static function factorialValue(int $operand, string $function): int
    {
        $result = 1;
        for ($factor = 2; $factor <= $operand; ++$factor) {
            if ($result > intdiv(\PHP_INT_MAX, $factor)) {
                throw new \OverflowException($function . '() result exceeds the host integer range.');
            }
            $result *= $factor;
        }

        return $result;
    }

    private static function integerGcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return abs($left);
    }

    private static function requireNonNegativeInteger(int|float $value, string $function): int
    {
        $integer = self::requireInteger($value, $function);
        if ($integer < 0) {
            throw new \DomainException($function . '() requires non-negative integer arguments.');
        }

        return $integer;
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
