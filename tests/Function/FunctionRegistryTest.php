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
        yield 'cube root positive' => ['cbrt', [27], 3.0, 'float'];
        yield 'cube root negative' => ['cbrt', [-8], -2.0, 'float'];
        yield 'error function at zero' => ['erf', [0], 0.0, 'float'];
        yield 'complementary error function at zero' => ['erfc', [0], 1.0, 'float'];
        yield 'imaginary error function at one' => ['erfi', [1], 1.6504257587975428, 'float'];
        yield 'Airy Ai at zero' => ['airyai', [0], 0.3550280538878172, 'float'];
        yield 'Airy Ai at one' => ['airyai', [1], 0.13529241631288147, 'float'];
        yield 'Airy Bi at zero' => ['airybi', [0], 0.6149266274460007, 'float'];
        yield 'Airy Bi at one' => ['airybi', [1], 1.2074235949528712, 'float'];
        yield 'Airy Ai at negative one' => ['airyai', [-1], 0.5355608832923522, 'float'];
        yield 'Airy Bi at negative one' => ['airybi', [-1], 0.10399738949694468, 'float'];
        yield 'complete elliptic K at zero' => ['ellipk', [0], M_PI / 2.0, 'float'];
        yield 'complete elliptic K at one half' => ['ellipk', [0.5], 1.8540746773013719, 'float'];
        yield 'complete elliptic E at zero' => ['ellipe', [0], M_PI / 2.0, 'float'];
        yield 'complete elliptic E at one half' => ['ellipe', [0.5], 1.3506438810476755, 'float'];
        yield 'complete elliptic E at one' => ['ellipe', [1], 1.0, 'float'];
        yield 'complete elliptic K at negative one' => ['ellipk', [-1], 1.3110287771460598, 'float'];
        yield 'complete elliptic E at negative one' => ['ellipe', [-1], 1.9100988945138562, 'float'];
        yield 'gamma at five' => ['gamma', [5], 24.0, 'float'];
        yield 'log gamma at five' => ['lgamma', [5], log(24.0), 'float'];
        yield 'regularized lower gamma at one and one' => ['gammainc', [1, 1], 0.6321205588285577, 'float'];
        yield 'regularized upper gamma at one and one' => ['gammaincc', [1, 1], 0.3678794411714423, 'float'];
        yield 'regularized lower gamma at one half and one' => ['gammainc', [0.5, 1], 0.8427007929497149, 'float'];
        yield 'regularized lower gamma at two and three' => ['gammainc', [2, 3], 0.8008517265285442, 'float'];
        yield 'beta at two and three' => ['beta', [2, 3], 1.0 / 12.0, 'float'];
        yield 'log beta at two and three' => ['logbeta', [2, 3], log(1.0 / 12.0), 'float'];
        yield 'zeta at two' => ['zeta', [2], M_PI * M_PI / 6.0, 'float'];
        yield 'zeta at three' => ['zeta', [3], 1.2020569031595942, 'float'];
        yield 'zeta at one point five' => ['zeta', [1.5], 2.612375348685488, 'float'];
        yield 'digamma at one' => ['digamma', [1], -0.5772156649015329, 'float'];
        yield 'digamma at one half' => ['digamma', [0.5], -0.5772156649015329 - 2.0 * log(2.0), 'float'];
        yield 'trigamma at one' => ['trigamma', [1], M_PI * M_PI / 6.0, 'float'];
        yield 'trigamma at one half' => ['trigamma', [0.5], M_PI * M_PI / 2.0, 'float'];
        yield 'polygamma order two at one' => ['polygamma', [2, 1], -2.4041138063191885, 'float'];
        yield 'polygamma order three at one' => ['polygamma', [3, 1], M_PI ** 4 / 15.0, 'float'];
        yield 'polygamma order two at one half' => ['polygamma', [2, 0.5], -14.0 * 1.2020569031595942, 'float'];
        yield 'polygamma order zero delegates to digamma' => ['polygamma', [0, 1], -0.5772156649015329, 'float'];
        yield 'Bessel J zero at zero' => ['besselj', [0, 0], 1.0, 'float'];
        yield 'Bessel J zero at two' => ['besselj', [0, 2], 0.22389077914123567, 'float'];
        yield 'modified Bessel I zero at zero' => ['besseli', [0, 0], 1.0, 'float'];
        yield 'modified Bessel I zero at one' => ['besseli', [0, 1], 1.2660658777520084, 'float'];
        yield 'modified Bessel I negative order matches positive order' => [
            'besseli',
            [-1, 1],
            0.565159103992485,
            'float',
        ];
        yield 'Bessel Y zero at one' => ['bessely', [0, 1], 0.08825696421567697, 'float'];
        yield 'Bessel Y one at one' => ['bessely', [1, 1], -0.7812128213002887, 'float'];
        yield 'Bessel Y two at one' => ['bessely', [2, 1], -1.650682606816254, 'float'];
        yield 'modified Bessel K zero at one' => ['besselk', [0, 1], 0.4210244382407083, 'float'];
        yield 'modified Bessel K one at one' => ['besselk', [1, 1], 0.6019072301972346, 'float'];
        yield 'modified Bessel K two at one' => ['besselk', [2, 1], 1.6248388986351774, 'float'];
        yield 'sine' => ['sin', [\M_PI / 2], 1.0, 'float'];
        yield 'cosine' => ['cos', [0], 1.0, 'float'];
        yield 'tangent' => ['tan', [\M_PI / 4], 1.0, 'float'];
        yield 'secant' => ['sec', [0], 1.0, 'float'];
        yield 'cosecant' => ['csc', [\M_PI / 2], 1.0, 'float'];
        yield 'cotangent' => ['cot', [\M_PI / 4], 1.0, 'float'];
        yield 'arc secant' => ['asec', [2], \acos(0.5), 'float'];
        yield 'arc cosecant' => ['acsc', [2], \asin(0.5), 'float'];
        yield 'arc cotangent' => ['acot', [1], \M_PI / 4, 'float'];
        yield 'arc hyperbolic secant' => ['asech', [1], 0.0, 'float'];
        yield 'arc hyperbolic cosecant' => ['acsch', [1], \asinh(1), 'float'];
        yield 'arc hyperbolic cotangent' => ['acoth', [2], \atanh(0.5), 'float'];
        yield 'arc sine' => ['asin', [1], \M_PI / 2, 'float'];
        yield 'arc cosine' => ['acos', [0], \M_PI / 2, 'float'];
        yield 'arc tangent' => ['atan', [1], \M_PI / 4, 'float'];
        yield 'hyperbolic sine' => ['sinh', [0], 0.0, 'float'];
        yield 'hyperbolic cosine' => ['cosh', [0], 1.0, 'float'];
        yield 'hyperbolic tangent' => ['tanh', [0], 0.0, 'float'];
        yield 'hyperbolic secant' => ['sech', [0], 1.0, 'float'];
        yield 'hyperbolic cosecant' => ['csch', [\asinh(1)], 1.0, 'float'];
        yield 'hyperbolic cotangent' => ['coth', [\asinh(1)], \sqrt(2.0), 'float'];
        yield 'inverse hyperbolic sine' => ['asinh', [0], 0.0, 'float'];
        yield 'inverse hyperbolic cosine' => ['acosh', [1], 0.0, 'float'];
        yield 'inverse hyperbolic tangent' => ['atanh', [0], 0.0, 'float'];
        yield 'exponential' => ['exp', [1], \M_E, 'float'];
        yield 'Lambert W principal branch' => ['lambertw', [1], 0.5671432904097838, 'float'];
        yield 'Lambert W lower real branch' => ['lambertw', [-0.1, -1], -3.577152063957297, 'float'];
        yield 'natural logarithm' => ['ln', [\M_E], 1.0, 'float'];
        yield 'logarithm' => ['log', [100, 10], 2.0, 'float'];
        yield 'base ten logarithm' => ['log10', [100], 2.0, 'float'];
        yield 'base two logarithm' => ['log2', [8], 3.0, 'float'];
        yield 'log one plus' => ['log1p', [0], 0.0, 'float'];
        yield 'exponential minus one' => ['expm1', [0], 0.0, 'float'];
        yield 'normalized sinc at zero' => ['sinc', [0], 1.0, 'float'];
        yield 'normalized sinc at pi' => ['sinc', [M_PI], 0.0, 'float'];
        yield 'normalized hyperbolic sinc at zero' => ['sinhc', [0], 1.0, 'float'];
        yield 'normalized hyperbolic sinc at one' => ['sinhc', [1], 1.1752011936438014, 'float'];
        yield 'atan2' => ['atan2', [1, 1], \M_PI / 4, 'float'];
        yield 'hypotenuse' => ['hypot', [3, 4], 5.0, 'float'];
        yield 'sign' => ['sign', [-4], -1, 'int'];
        yield 'minimum' => ['min', [4, 2, 8], 2, 'int'];
        yield 'maximum' => ['max', [4, 2, 8], 8, 'int'];
        yield 'floor' => ['floor', [-1.2], -2.0, 'float'];
        yield 'ceiling' => ['ceil', [-1.2], -1.0, 'float'];
        yield 'round positive halfway' => ['round', [2.5], 3.0, 'float'];
        yield 'round negative halfway' => ['round', [-2.5], -3.0, 'float'];
        yield 'greatest common divisor' => ['gcd', [84, 30], 6, 'int'];
        yield 'least common multiple' => ['lcm', [12, 18], 36, 'int'];
        yield 'factorial function' => ['factorial', [5], 120, 'int'];
        yield 'binomial coefficient' => ['binomial', [5, 2], 10, 'int'];
        yield 'choose alias' => ['choose', [5, 2], 10, 'int'];
        yield 'ncr alias' => ['ncr', [5, 2], 10, 'int'];
        yield 'permutation count' => ['permutation', [5, 2], 20, 'int'];
        yield 'permute alias' => ['permute', [5, 2], 20, 'int'];
        yield 'npr alias' => ['npr', [5, 2], 20, 'int'];
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
        yield 'cbrt' => ['cbrt', 1, 1];
        yield 'erf' => ['erf', 1, 1];
        yield 'erfc' => ['erfc', 1, 1];
        yield 'gamma' => ['gamma', 1, 1];
        yield 'lgamma' => ['lgamma', 1, 1];
        yield 'beta' => ['beta', 2, 2];
        yield 'logbeta' => ['logbeta', 2, 2];
        yield 'zeta' => ['zeta', 1, 1];
        yield 'digamma' => ['digamma', 1, 1];
        yield 'trigamma' => ['trigamma', 1, 1];
        yield 'besselj' => ['besselj', 2, 2];
        yield 'besseli' => ['besseli', 2, 2];
        yield 'sin' => ['sin', 1, 1];
        yield 'cos' => ['cos', 1, 1];
        yield 'tan' => ['tan', 1, 1];
        yield 'sec' => ['sec', 1, 1];
        yield 'csc' => ['csc', 1, 1];
        yield 'cot' => ['cot', 1, 1];
        yield 'asec' => ['asec', 1, 1];
        yield 'acsc' => ['acsc', 1, 1];
        yield 'acot' => ['acot', 1, 1];
        yield 'asech' => ['asech', 1, 1];
        yield 'acsch' => ['acsch', 1, 1];
        yield 'acoth' => ['acoth', 1, 1];
        yield 'asin' => ['asin', 1, 1];
        yield 'acos' => ['acos', 1, 1];
        yield 'atan' => ['atan', 1, 1];
        yield 'sinh' => ['sinh', 1, 1];
        yield 'cosh' => ['cosh', 1, 1];
        yield 'tanh' => ['tanh', 1, 1];
        yield 'sech' => ['sech', 1, 1];
        yield 'csch' => ['csch', 1, 1];
        yield 'coth' => ['coth', 1, 1];
        yield 'asinh' => ['asinh', 1, 1];
        yield 'acosh' => ['acosh', 1, 1];
        yield 'atanh' => ['atanh', 1, 1];
        yield 'exp' => ['exp', 1, 1];
        yield 'lambertw' => ['lambertw', 1, 2];
        yield 'ln' => ['ln', 1, 1];
        yield 'log' => ['log', 2, 2];
        yield 'log10' => ['log10', 1, 1];
        yield 'log2' => ['log2', 1, 1];
        yield 'log1p' => ['log1p', 1, 1];
        yield 'expm1' => ['expm1', 1, 1];
        yield 'atan2' => ['atan2', 2, 2];
        yield 'hypot' => ['hypot', 2, 2];
        yield 'sign' => ['sign', 1, 1];
        yield 'min' => ['min', 1, 16];
        yield 'max' => ['max', 1, 16];
        yield 'floor' => ['floor', 1, 1];
        yield 'ceil' => ['ceil', 1, 1];
        yield 'round' => ['round', 1, 1];
        yield 'gcd' => ['gcd', 2, 2];
        yield 'lcm' => ['lcm', 2, 2];
        yield 'factorial' => ['factorial', 1, 1];
        yield 'binomial' => ['binomial', 2, 2];
        yield 'choose' => ['choose', 2, 2];
        yield 'ncr' => ['ncr', 2, 2];
        yield 'permutation' => ['permutation', 2, 2];
        yield 'permute' => ['permute', 2, 2];
        yield 'npr' => ['npr', 2, 2];
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
        yield 'gamma pole at zero' => [
            'gamma',
            [0],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'gamma pole at negative integer' => [
            'gamma',
            [-3],
            \DomainException::class,
            'gamma() requires a finite non-pole argument',
        ];
        yield 'regularized gamma zero shape' => [
            'gammainc',
            [0, 1],
            \DomainException::class,
            'require a finite shape in (0, 171]',
        ];
        yield 'regularized gamma negative value' => [
            'gammaincc',
            [1, -1],
            \DomainException::class,
            'require a finite shape in (0, 171]',
        ];
        yield 'complete elliptic K at one' => [
            'ellipk',
            [1],
            \DomainException::class,
            'diverges at parameter one',
        ];
        yield 'complete elliptic E above one' => [
            'ellipe',
            [1.1],
            \DomainException::class,
            'requires a finite real parameter less than or equal to one',
        ];
        yield 'Bessel J non-integer order' => [
            'besselj',
            [0.5, 1],
            \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'modified Bessel I non-integer order' => [
            'besseli',
            [0.5, 1],
            \DomainException::class,
            'requires a finite integer order',
        ];
        yield 'Bessel Y zero argument' => [
            'bessely',
            [0, 0],
            \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'modified Bessel K negative argument' => [
            'besselk',
            [0, -1],
            \DomainException::class,
            'requires a finite positive real argument',
        ];
        yield 'beta non-positive first argument' => [
            'beta',
            [0, 2],
            \DomainException::class,
            'require two finite positive arguments',
        ];
        yield 'log beta non-positive second argument' => [
            'logbeta',
            [2, 0],
            \DomainException::class,
            'require two finite positive arguments',
        ];
        yield 'zeta pole at one' => [
            'zeta',
            [1],
            \DomainException::class,
            'requires a finite real argument greater than one',
        ];
        yield 'Lambert W principal branch below real domain' => [
            'lambertw',
            [-1],
            \DomainException::class,
            'principal branch requires an argument greater than or equal to -1/e',
        ];
        yield 'Lambert W lower branch at zero' => [
            'lambertw',
            [0, -1],
            \DomainException::class,
            'branch -1 requires an argument in [-1/e, 0)',
        ];
        yield 'digamma pole at zero' => [
            'digamma',
            [0],
            \DomainException::class,
            'requires a finite non-pole real argument',
        ];
        yield 'trigamma pole at negative integer' => [
            'trigamma',
            [-2],
            \DomainException::class,
            'requires a finite non-pole real argument',
        ];
        yield 'polygamma non-integer order' => [
            'polygamma',
            [0.5, 1],
            \DomainException::class,
            'requires an integer order',
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
        yield 'inverse sine out of range' => [
            'asin',
            [2],
            \DomainException::class,
            'asin() requires an argument between -1 and 1.',
        ];
        yield 'inverse hyperbolic cosine below one' => [
            'acosh',
            [0],
            \DomainException::class,
            'acosh() requires an argument greater than or equal to one.',
        ];
        yield 'inverse hyperbolic tangent at pole' => [
            'atanh',
            [1],
            \DomainException::class,
            'atanh() requires an argument strictly between -1 and 1.',
        ];
        yield 'inverse hyperbolic secant above one' => [
            'asech',
            [2],
            \DomainException::class,
            'asech() requires a finite argument strictly greater than zero and at most one.',
        ];
        yield 'inverse hyperbolic cosecant at zero' => [
            'acsch',
            [0],
            \DomainException::class,
            'acsch() requires a finite non-zero argument.',
        ];
        yield 'inverse hyperbolic cotangent inside range' => [
            'acoth',
            [1],
            \DomainException::class,
            'acoth() requires a finite argument with absolute value greater than one.',
        ];
        yield 'hyperbolic cosecant at pole' => [
            'csch',
            [0],
            \DomainException::class,
            'csch() is undefined at zero.',
        ];
        yield 'hyperbolic cotangent at pole' => [
            'coth',
            [0],
            \DomainException::class,
            'coth() is undefined at zero.',
        ];
        yield 'tangent at pole' => [
            'tan',
            [\M_PI / 2],
            \DomainException::class,
            'tan() is undefined where cosine is zero.',
        ];
        yield 'secant at pole' => [
            'sec',
            [\M_PI / 2],
            \DomainException::class,
            'sec() is undefined where cosine is zero.',
        ];
        yield 'cosecant at pole' => [
            'csc',
            [0],
            \DomainException::class,
            'csc() is undefined where sine is zero.',
        ];
        yield 'cotangent at pole' => [
            'cot',
            [0],
            \DomainException::class,
            'cot() is undefined where sine is zero.',
        ];
        yield 'arc secant inside range gap' => [
            'asec',
            [0.5],
            \DomainException::class,
            'asec() requires an argument with absolute value greater than or equal to one.',
        ];
        yield 'arc cosecant inside range gap' => [
            'acsc',
            [-0.5],
            \DomainException::class,
            'acsc() requires an argument with absolute value greater than or equal to one.',
        ];
        yield 'log2 zero' => [
            'log2',
            [0],
            \DomainException::class,
            'log2() requires a positive argument.',
        ];
        yield 'log1p negative one' => [
            'log1p',
            [-1],
            \DomainException::class,
            'log1p() requires an argument greater than -1.',
        ];
        yield 'atan2 origin' => [
            'atan2',
            [0, 0],
            \DomainException::class,
            'atan2() is undefined when both arguments are zero.',
        ];
        yield 'gcd non-integer' => [
            'gcd',
            [2.5, 5],
            \DomainException::class,
            'gcd() requires finite integer arguments.',
        ];
        yield 'lcm overflow' => [
            'lcm',
            [\PHP_INT_MAX, 2],
            \OverflowException::class,
            'lcm() result exceeds the host integer range.',
        ];
        yield 'factorial negative' => [
            'factorial',
            [-1],
            \DomainException::class,
            'factorial() requires non-negative integer arguments.',
        ];
        yield 'binomial non-integer' => [
            'binomial',
            [5.5, 2],
            \DomainException::class,
            'binomial() requires finite integer arguments.',
        ];
        yield 'binomial k above n' => [
            'binomial',
            [2, 3],
            \DomainException::class,
            'binomial() requires k to be less than or equal to n.',
        ];
        yield 'binomial overflow' => [
            'binomial',
            [67, 33],
            \OverflowException::class,
            'binomial() result exceeds the host integer range.',
        ];
        yield 'permutation k above n' => [
            'permutation',
            [2, 3],
            \DomainException::class,
            'permutation() requires k to be less than or equal to n.',
        ];
        yield 'permutation overflow' => [
            'permutation',
            [21, 21],
            \OverflowException::class,
            'permutation() result exceeds the host integer range.',
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
