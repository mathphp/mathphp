<?php

declare(strict_types=1);

namespace MathPHP\Ast;

final class RationalLiteral
{
    private const MAX_STRING_INTEGER_DIGITS = 16_384;

    private const MAX_STRING_DIVISION_DIGIT_WORK = 2_000_000;

    private const MAX_STRING_ROOT_DIGIT_WORK = 2_000_000;

    private const MAX_EXACT_ROOT_DEGREE = 1024;

    private const MAX_EXACT_BERNOULLI_ORDER = 256;

    private const MAX_EXACT_INTEGER_FUNCTION_WORK = 65_536;

    /**
     * Extracts exact integer, finite-decimal, and integer-ratio literals.
     *
     * @return array{int, int}|null Reduced numerator and positive denominator.
     */
    public static function fromNode(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof UnaryOperationNode) {
            $fraction = self::fromNode($node->operand);
            if (
                $fraction === null
                || (
                    $node->operator === UnaryOperator::Minus
                    && $fraction[0] === \PHP_INT_MIN
                )
            ) {
                return null;
            }

            return $node->operator === UnaryOperator::Minus
                ? [-$fraction[0], $fraction[1]]
                : [$fraction[0], $fraction[1]];
        }
        if ($node instanceof NumberNode) {
            return self::number($node);
        }
        if ($node instanceof FactorialNode) {
            $fraction = self::fromNodeAsStrings($node);
            if ($fraction === null) {
                return null;
            }
            $numerator = \filter_var($fraction[0], FILTER_VALIDATE_INT);
            $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);

            return \is_int($numerator) && \is_int($denominator) && $denominator > 0
                ? [$numerator, $denominator]
                : null;
        }
        if ($node instanceof FunctionCallNode) {
            $fraction = self::fromNodeAsStrings($node);
            if ($fraction === null) {
                return null;
            }
            $numerator = \filter_var($fraction[0], FILTER_VALIDATE_INT);
            $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);

            return \is_int($numerator) && \is_int($denominator) && $denominator > 0
                ? [$numerator, $denominator]
                : null;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        $left = self::fromNode($node->left);
        $right = self::fromNode($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        [$leftNumerator, $leftDenominator] = $left;
        [$rightNumerator, $rightDenominator] = $right;
        if (
            \in_array(
                $node->operator,
                [BinaryOperator::Add, BinaryOperator::Subtract],
                true,
            )
        ) {
            $denominatorGcd = self::greatestCommonDivisor($leftDenominator, $rightDenominator);
            $leftFactor = intdiv($rightDenominator, $denominatorGcd);
            $rightFactor = intdiv($leftDenominator, $denominatorGcd);
            if ($rightFactor > intdiv(\PHP_INT_MAX, $rightDenominator)) {
                return null;
            }
            $commonDenominator = $rightDenominator * $rightFactor;
            if (
                self::wouldMultiplyOverflow($leftNumerator, $leftFactor)
                || self::wouldMultiplyOverflow($rightNumerator, $rightFactor)
            ) {
                return null;
            }
            $leftProduct = $leftNumerator * $leftFactor;
            $rightProduct = $rightNumerator * $rightFactor;
            if ($node->operator === BinaryOperator::Subtract) {
                if ($rightProduct === \PHP_INT_MIN) {
                    return null;
                }
                $rightProduct = -$rightProduct;
            }
            if (
                ($rightProduct > 0 && $leftProduct > \PHP_INT_MAX - $rightProduct)
                || ($rightProduct < 0 && $leftProduct < \PHP_INT_MIN - $rightProduct)
            ) {
                return null;
            }
            $numerator = $leftProduct + $rightProduct;
            $denominator = $commonDenominator;
        } elseif ($node->operator === BinaryOperator::Multiply) {
            return self::multiplyFractions($left, $right);
        } elseif ($node->operator === BinaryOperator::Divide) {
            if ($rightNumerator === 0) {
                return null;
            }
            $numeratorReduction = self::greatestCommonDivisor(
                $leftNumerator,
                $rightNumerator,
            );
            $denominatorReduction = self::greatestCommonDivisor(
                $leftDenominator,
                $rightDenominator,
            );
            $leftNumerator = intdiv($leftNumerator, $numeratorReduction);
            $rightNumerator = intdiv($rightNumerator, $numeratorReduction);
            $leftDenominator = intdiv($leftDenominator, $denominatorReduction);
            $rightDenominator = intdiv($rightDenominator, $denominatorReduction);
            if ($rightNumerator === \PHP_INT_MIN) {
                return null;
            }
            if (
                self::wouldMultiplyOverflow($leftNumerator, $rightDenominator)
                || self::wouldMultiplyOverflow($leftDenominator, \abs($rightNumerator))
            ) {
                return null;
            }
            $numerator = $leftNumerator * $rightDenominator;
            $denominator = $leftDenominator * \abs($rightNumerator);
            if ($rightNumerator < 0) {
                if ($numerator === \PHP_INT_MIN) {
                    return null;
                }
                $numerator = -$numerator;
            }
        } elseif ($node->operator === BinaryOperator::Modulo) {
            if (
                $leftDenominator !== 1
                || $rightDenominator !== 1
                || $rightNumerator === 0
            ) {
                return null;
            }

            return [$leftNumerator === \PHP_INT_MIN && $rightNumerator === -1
                ? 0
                : $leftNumerator % $rightNumerator, 1];
        } else {
            if ($rightDenominator !== 1 || $rightNumerator === \PHP_INT_MIN) {
                return null;
            }
            $power = \abs($rightNumerator);
            if ($power > 1024 || ($rightNumerator < 0 && $leftNumerator === 0)) {
                return null;
            }
            if ($rightNumerator < 0) {
                [$leftNumerator, $leftDenominator] = [$leftDenominator, $leftNumerator];
                if ($leftDenominator < 0) {
                    $leftNumerator = -$leftNumerator;
                    $leftDenominator = -$leftDenominator;
                }
            }
            $result = [1, 1];
            $factor = [$leftNumerator, $leftDenominator];
            while ($power > 0) {
                if (($power % 2) === 1) {
                    $result = self::multiplyFractions($result, $factor);
                    if ($result === null) {
                        return null;
                    }
                }
                $power = intdiv($power, 2);
                if ($power > 0) {
                    $factor = self::multiplyFractions($factor, $factor);
                    if ($factor === null) {
                        return null;
                    }
                }
            }

            return $result;
        }
        if ($denominator <= 0) {
            return null;
        }

        $divisor = self::greatestCommonDivisor($numerator, $denominator);

        return [intdiv($numerator, $divisor), intdiv($denominator, $divisor)];
    }

    /**
     * Extracts an exact rational whose components fit the requested bounds.
     *
     * @return array{int, int}|null
     */
    public static function fromNodeBounded(
        Node $node,
        int $maximumAbsoluteNumerator,
        int $maximumDenominator,
    ): ?array {
        if ($maximumAbsoluteNumerator < 0 || $maximumDenominator < 1) {
            throw new \InvalidArgumentException('Rational bounds must be non-negative with a positive denominator.');
        }
        $fraction = self::fromNodeAsStrings($node);
        if ($fraction === null) {
            return null;
        }
        $numerator = \filter_var($fraction[0], FILTER_VALIDATE_INT);
        $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);
        if (
            !\is_int($numerator)
            || !\is_int($denominator)
            || $numerator === \PHP_INT_MIN
            || $denominator < 1
            || \abs($numerator) > $maximumAbsoluteNumerator
            || $denominator > $maximumDenominator
        ) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /**
     * Recover a small exact rational when its float representation differs only by rounding.
     *
     * @return array{int, int}|null
     */
    public static function fromFloatBounded(
        float $value,
        int $maximumAbsoluteNumerator,
        int $maximumDenominator,
    ): ?array {
        if (
            !\is_finite($value)
            || $maximumAbsoluteNumerator < 0
            || $maximumDenominator < 1
            || \abs($value) > $maximumAbsoluteNumerator
        ) {
            return null;
        }
        $tolerance = 4.0 * \PHP_FLOAT_EPSILON * \abs($value);
        for ($denominator = 1; $denominator <= $maximumDenominator; $denominator++) {
            $numerator = (int) \round($value * $denominator);
            if (\abs($numerator) > $maximumAbsoluteNumerator) {
                continue;
            }
            $error = \abs($value - ($numerator / $denominator));
            if ($error <= $tolerance) {
                $divisor = self::greatestCommonDivisor($numerator, $denominator);

                return [intdiv($numerator, $divisor), intdiv($denominator, $divisor)];
            }
        }

        return null;
    }

    /** @return array{string, string}|null Reduced-sign rational as decimal integers. */
    public static function fromNodeAsStrings(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof UnaryOperationNode) {
            $fraction = self::fromNodeAsStrings($node->operand);
            if ($fraction === null || $node->operator === UnaryOperator::Plus) {
                return $fraction;
            }

            return [$fraction[0] === '0' ? '0' : self::negateString($fraction[0]), $fraction[1]];
        }
        if ($node instanceof NumberNode) {
            return self::decimalAsStrings($node->literal);
        }
        if ($node instanceof FactorialNode) {
            $operand = self::fromNodeAsStrings($node->operand);
            if ($operand === null || $operand[1] !== '1') {
                return null;
            }
            $integer = \filter_var($operand[0], FILTER_VALIDATE_INT);
            if (
                !\is_int($integer)
                || $integer < 0
                || $integer > \MathPHP\Configuration\ResourceLimits::factorialMaximum()
            ) {
                return null;
            }
            $factorial = '1';
            for ($factor = 2; $factor <= $integer; ++$factor) {
                $factorial = self::multiplyIntegerStrings($factorial, (string) $factor);
                if ($factorial === null) {
                    return null;
                }
            }

            return [$factorial, '1'];
        }
        if ($node instanceof FunctionCallNode) {
            $exactHypotenuseValue = self::exactHypotenuseValue($node);
            if ($exactHypotenuseValue !== null) {
                return $exactHypotenuseValue;
            }
            $exactRationalExtremumValue = self::exactRationalExtremumValue($node);
            if ($exactRationalExtremumValue !== null) {
                return $exactRationalExtremumValue;
            }
            $exactIntegerFunctionValue = self::exactIntegerFunctionValue($node);
            if ($exactIntegerFunctionValue !== null) {
                return $exactIntegerFunctionValue;
            }
            $exactInverseCompositionValue = self::exactInverseCompositionValue($node);
            if ($exactInverseCompositionValue !== null) {
                return $exactInverseCompositionValue;
            }
            $exactRegularizedFunctionValue = self::exactRegularizedFunctionValue($node);
            if ($exactRegularizedFunctionValue !== null) {
                return $exactRegularizedFunctionValue;
            }
            $exactSpecialFunctionValue = self::exactSpecialFunctionValue($node);
            if ($exactSpecialFunctionValue !== null) {
                return $exactSpecialFunctionValue;
            }
            if (
                $node->name === 'lambertw'
                && \in_array(\count($node->arguments), [1, 2], true)
                && self::fromNodeAsStrings($node->arguments[0]) === ['0', '1']
                && (\count($node->arguments) === 1
                    || self::fromNodeAsStrings($node->arguments[1]) === ['0', '1'])
            ) {
                return ['0', '1'];
            }
            $exactPowerLogarithmValue = self::exactPowerLogarithmValue($node);
            if ($exactPowerLogarithmValue !== null) {
                return $exactPowerLogarithmValue;
            }
            $exactLogarithmCompositionValue = self::exactLogarithmCompositionValue($node);
            if ($exactLogarithmCompositionValue !== null) {
                return $exactLogarithmCompositionValue;
            }
            if (
                \in_array($node->name, ['sin', 'cos', 'tan', 'cot', 'sec', 'csc'], true)
                && \count($node->arguments) === 1
            ) {
                $exactTrigonometricValue = self::exactRationalTrigonometricValue($node);
                if ($exactTrigonometricValue !== null) {
                    return $exactTrigonometricValue;
                }
            }
            if ($node->name === 'sinc' && \count($node->arguments) === 1) {
                $phase = self::piMultiple(self::unwrapGroupingNode($node->arguments[0]));
                if ($phase !== null && $phase[1] === 1 && $phase[0] !== 0) {
                    return ['0', '1'];
                }
            }
            if (\in_array($node->name, ['sqrt', 'cbrt'], true) && \count($node->arguments) === 1) {
                $argument = self::fromNodeAsStrings($node->arguments[0]);
                $degree = $node->name === 'sqrt' ? 2 : 3;

                return $argument === null ? null : self::exactRootAsStrings($argument, $degree);
            }
            if (\count($node->arguments) === 1) {
                $argument = self::fromNodeAsStrings($node->arguments[0]);
                if ($argument === null) {
                    return null;
                }
                if ($node->name === 'abs') {
                    return [
                        $argument[0][0] === '-' ? self::negateString($argument[0]) : $argument[0],
                        $argument[1],
                    ];
                }
                if ($node->name === 'sign') {
                    return [$argument[0] === '0' ? '0' : ($argument[0][0] === '-' ? '-1' : '1'), '1'];
                }
                if (\in_array($node->name, ['floor', 'ceil'], true)) {
                    $integer = self::roundedIntegerAsString($argument, $node->name);

                    return $integer === null ? null : [$integer, '1'];
                }
                if (\in_array($node->name, ['round', 'trunc'], true)) {
                    $integer = self::roundedIntegerAsString($argument, $node->name);

                    return $integer === null ? null : [$integer, '1'];
                }
                if (
                    $argument === ['0', '1']
                    && \in_array(
                        $node->name,
                        [
                            'sin', 'tan', 'sinh', 'tanh', 'expm1', 'asin', 'arcsin',
                            'atan', 'arctan', 'asinh', 'arcsinh', 'atanh', 'arctanh',
                            'erf', 'erfi', 'dawson', 'erfinv',
                        ],
                        true,
                    )
                ) {
                    return ['0', '1'];
                }
                if (
                    $argument === ['0', '1']
                    && \in_array(
                        $node->name,
                        ['cos', 'cosh', 'exp', 'erfc', 'erfcx', 'sinc', 'sincpi', 'sech'],
                        true,
                    )
                ) {
                    return ['1', '1'];
                }
                if (
                    $argument === ['1', '1']
                    && \in_array($node->name, ['acosh', 'arccosh', 'asech', 'erfcinv'], true)
                ) {
                    return ['0', '1'];
                }
                if (
                    $node->name === 'sincpi'
                    && $argument[1] === '1'
                    && $argument[0] !== '0'
                ) {
                    return ['0', '1'];
                }
                if (
                    $argument === ['1', '1']
                    && \in_array($node->name, ['ln', 'log10', 'log2'], true)
                ) {
                    return ['0', '1'];
                }
            }
            if ($node->name === 'root' && \count($node->arguments) === 2) {
                $argument = self::fromNodeAsStrings($node->arguments[0]);
                $degree = self::fromNodeAsStrings($node->arguments[1]);
                if ($argument === null || $degree === null || $degree[1] !== '1') {
                    return null;
                }
                $integerDegree = \filter_var($degree[0], FILTER_VALIDATE_INT);
                if (
                    !\is_int($integerDegree)
                    || $integerDegree === 0
                    || \abs($integerDegree) > self::MAX_EXACT_ROOT_DEGREE
                ) {
                    return null;
                }
                $root = self::exactRootAsStrings($argument, \abs($integerDegree));
                if ($root === null || $integerDegree > 0) {
                    return $root;
                }
                if ($root[0] === '0') {
                    return null;
                }

                return [
                    $root[0][0] === '-' ? self::negateString($root[1]) : $root[1],
                    $root[0][0] === '-' ? \substr($root[0], 1) : $root[0],
                ];
            }

            return null;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if ($node->operator === BinaryOperator::Power) {
            $base = self::fromNodeAsStrings($node->left);
            $exponent = self::fromNodeAsStrings($node->right);
            if ($base !== null && $exponent !== null && $exponent[1] !== '1') {
                $degree = \filter_var($exponent[1], FILTER_VALIDATE_INT);
                $power = \filter_var($exponent[0], FILTER_VALIDATE_INT);
                if (
                    !\is_int($degree)
                    || !\is_int($power)
                    || $degree < 2
                    || $power === \PHP_INT_MIN
                ) {
                    return null;
                }
                $exponentDivisor = self::greatestCommonDivisor($power, $degree);
                $degree = intdiv($degree, $exponentDivisor);
                $power = intdiv($power, $exponentDivisor);
                if (
                    $degree > self::MAX_EXACT_ROOT_DEGREE
                    || \abs($power) > 1024
                ) {
                    return null;
                }
                if ($degree === 1) {
                    return self::exactPowerAsStrings($base, $power);
                }
                $root = self::exactRootAsStrings($base, $degree);

                return $root === null ? null : self::exactPowerAsStrings($root, $power);
            }
            if ($base === null || $exponent === null || $exponent[1] !== '1') {
                return null;
            }
            $power = \filter_var($exponent[0], FILTER_VALIDATE_INT);
            if (!\is_int($power) || $power === \PHP_INT_MIN || \abs($power) > 1024) {
                return null;
            }

            return self::exactPowerAsStrings($base, $power);
        }
        if ($node->operator === BinaryOperator::Divide) {
            $numerator = self::integerLiteralAsString($node->left);
            $denominator = self::integerLiteralAsString($node->right);
            if ($numerator !== null && $denominator !== null) {
                $fraction = self::fromNode($node);
                if ($fraction !== null) {
                    return [(string) $fraction[0], (string) $fraction[1]];
                }

                return self::normalizeStringFraction($numerator, $denominator);
            }
        }

        $fraction = self::fromNode($node);
        if ($fraction !== null) {
            return [(string) $fraction[0], (string) $fraction[1]];
        }
        if (
            !\in_array(
                $node->operator,
                [
                    BinaryOperator::Add,
                    BinaryOperator::Subtract,
                    BinaryOperator::Multiply,
                    BinaryOperator::Divide,
                    BinaryOperator::Modulo,
                ],
                true,
            )
        ) {
            return null;
        }

        $left = self::fromNodeAsStrings($node->left);
        $right = self::fromNodeAsStrings($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        if ($node->operator === BinaryOperator::Modulo) {
            if ($left[1] !== '1' || $right[1] !== '1' || $right[0] === '0') {
                return null;
            }
            $negative = $left[0][0] === '-';
            $numerator = \ltrim($left[0], '-');
            $denominator = \ltrim($right[0], '-');
            $integerDenominator = \filter_var($denominator, FILTER_VALIDATE_INT);
            if (self::compareIntegerMagnitudes($numerator, $denominator) < 0) {
                $remainder = $numerator;
            } elseif (
                \is_int($integerDenominator)
                && $integerDenominator > 0
                && $integerDenominator <= intdiv(\PHP_INT_MAX - 9, 10)
            ) {
                [, $remainder] = self::divideIntegerMagnitudeBySmall(
                    $numerator,
                    $integerDenominator,
                );
                $remainder = (string) $remainder;
            } else {
                $division = self::divideIntegerMagnitudes($numerator, $denominator);
                if ($division === null) {
                    return null;
                }
                [, $remainder] = $division;
            }

            return [
                $negative && $remainder !== '0' ? self::negateString($remainder) : $remainder,
                '1',
            ];
        }
        if ($node->operator === BinaryOperator::Multiply) {
            [$leftNumerator, $rightDenominator] = self::cancelCommonIntegerFactor(
                $left[0],
                $right[1],
            );
            [$rightNumerator, $leftDenominator] = self::cancelCommonIntegerFactor(
                $right[0],
                $left[1],
            );
            $numerator = self::multiplyIntegerStrings($leftNumerator, $rightNumerator);
            $denominator = self::multiplyIntegerStrings($leftDenominator, $rightDenominator);
        } elseif ($node->operator === BinaryOperator::Divide) {
            if ($right[0] === '0') {
                return null;
            }
            [$leftNumerator, $rightNumerator] = self::cancelCommonIntegerFactor(
                $left[0],
                $right[0],
            );
            [$rightDenominator, $leftDenominator] = self::cancelCommonIntegerFactor(
                $right[1],
                $left[1],
            );
            $numerator = self::multiplyIntegerStrings($leftNumerator, $rightDenominator);
            $denominator = self::multiplyIntegerStrings($leftDenominator, $rightNumerator);
        } elseif ($left[1] === $right[1]) {
            $rightNumerator = $node->operator === BinaryOperator::Subtract
                ? self::negateString($right[0])
                : $right[0];
            $numerator = self::addIntegerStrings($left[0], $rightNumerator);
            $denominator = $left[1];
        } else {
            $leftDenominator = \filter_var($left[1], FILTER_VALIDATE_INT);
            $rightDenominator = \filter_var($right[1], FILTER_VALIDATE_INT);
            if (
                \is_int($leftDenominator)
                && \is_int($rightDenominator)
                && $leftDenominator > 0
                && $rightDenominator > 0
            ) {
                $commonFactor = self::greatestCommonDivisor($leftDenominator, $rightDenominator);
                $leftFactor = (string) intdiv($rightDenominator, $commonFactor);
                $rightFactor = (string) intdiv($leftDenominator, $commonFactor);
                $leftNumerator = self::multiplyIntegerStrings($left[0], $leftFactor);
                $rightNumerator = self::multiplyIntegerStrings($right[0], $rightFactor);
                $denominator = self::multiplyIntegerStrings($left[1], $leftFactor);
            } else {
                $commonFactor = self::greatestCommonDivisorAsString($left[1], $right[1]);
                $reducedDenominators = false;
                if ($commonFactor !== null) {
                    $leftReduction = self::divideIntegerMagnitudes($right[1], $commonFactor);
                    $rightReduction = self::divideIntegerMagnitudes($left[1], $commonFactor);
                    if (
                        $leftReduction !== null
                        && $rightReduction !== null
                        && $leftReduction[1] === '0'
                        && $rightReduction[1] === '0'
                    ) {
                        $leftNumerator = self::multiplyIntegerStrings($left[0], $leftReduction[0]);
                        $rightNumerator = self::multiplyIntegerStrings($right[0], $rightReduction[0]);
                        $denominator = self::multiplyIntegerStrings($left[1], $leftReduction[0]);
                        $reducedDenominators = true;
                    }
                }
                if (!$reducedDenominators) {
                    $leftNumerator = self::multiplyIntegerStrings($left[0], $right[1]);
                    $rightNumerator = self::multiplyIntegerStrings($right[0], $left[1]);
                    $denominator = self::multiplyIntegerStrings($left[1], $right[1]);
                }
            }
            if ($leftNumerator === null || $rightNumerator === null) {
                return null;
            }
            if ($node->operator === BinaryOperator::Subtract) {
                $rightNumerator = self::negateString($rightNumerator);
            }
            $numerator = self::addIntegerStrings($leftNumerator, $rightNumerator);
        }
        if ($numerator === null || $denominator === null) {
            return null;
        }

        return self::normalizeStringFraction($numerator, $denominator);
    }

    /** @return array{bool, bool}|null Reduced numerator-odd and denominator-odd flags. */
    public static function reducedParity(Node $node): ?array
    {
        $fraction = self::fromNode($node);
        if ($fraction !== null) {
            if ($fraction[1] === 1) {
                return [($fraction[0] % 2) !== 0, true];
            }

            return [($fraction[0] % 2) !== 0, ($fraction[1] % 2) !== 0];
        }

        $fraction = self::fromNodeAsStrings($node);
        if (
            $fraction === null
            || $fraction[1] === '0'
        ) {
            return null;
        }

        if ($fraction[0] === '0') {
            return [false, true];
        }

        if ($fraction[1] === '1') {
            return [((int) \substr($fraction[0], -1) % 2) !== 0, true];
        }

        $numerator = \ltrim($fraction[0], '-');
        $numeratorPower = self::factorsOfTwo($numerator);
        $denominatorPower = self::factorsOfTwo($fraction[1]);

        return [
            $numerator !== '0' && $numeratorPower <= $denominatorPower,
            $denominatorPower <= $numeratorPower,
        ];
    }

    /**
     * Evaluate a rational power when its exact rational value exists.
     *
     * @param array{string, string} $fraction
     * @return array{string, string}|null
     */
    public static function exactRationalPowerAsStrings(
        array $fraction,
        int $numerator,
        int $denominator,
    ): ?array {
        if ($denominator === 0) {
            return null;
        }
        $fraction = self::normalizeStringFraction($fraction[0], $fraction[1]);
        if ($fraction === null) {
            return null;
        }
        if ($numerator === 0) {
            return ['1', '1'];
        }
        if ($numerator === $denominator) {
            return $fraction;
        }
        if ($numerator === \PHP_INT_MIN) {
            return null;
        }
        if ($denominator < 0) {
            if ($denominator === \PHP_INT_MIN) {
                return null;
            }
            $numerator = -$numerator;
            $denominator = -$denominator;
        }
        $divisor = self::greatestCommonDivisor($numerator, $denominator);
        $numerator = intdiv($numerator, $divisor);
        $denominator = intdiv($denominator, $divisor);
        if ($denominator > self::MAX_EXACT_ROOT_DEGREE) {
            return null;
        }
        $root = $denominator === 1
            ? $fraction
            : self::exactRootAsStrings($fraction, $denominator);

        return $root === null ? null : self::exactPowerAsStrings($root, $numerator);
    }

    /**
     * Convert an exact rational to an integer using a supported built-in rounding mode.
     *
     * @param array{string, string} $fraction
     */
    public static function roundedRationalIntegerAsString(array $fraction, string $mode): ?string
    {
        if (!\in_array($mode, ['floor', 'ceil', 'round', 'trunc'], true)) {
            return null;
        }
        $normalized = self::normalizeStringFraction($fraction[0], $fraction[1]);

        return $normalized === null ? null : self::roundedIntegerAsString($normalized, $mode);
    }

    /**
     * Extracts a bounded rational from PHP's shortest round-trip decimal form.
     *
     * @return array{int, int}|null
     */
    public static function fromFloat(float $value): ?array
    {
        if (!\is_finite($value)) {
            return null;
        }

        $negative = $value < 0.0;
        $precision = \ini_get('serialize_precision');
        if ($precision === false) {
            return null;
        }
        $restorePrecision = $precision !== '-1';
        if ($restorePrecision && \ini_set('serialize_precision', '-1') === false) {
            return null;
        }
        try {
            $literal = \json_encode(\abs($value), \JSON_PRESERVE_ZERO_FRACTION);
        } finally {
            if ($restorePrecision) {
                \ini_set('serialize_precision', $precision);
            }
        }
        if (!\is_string($literal)) {
            return null;
        }
        $fraction = self::decimal($literal);
        if ($fraction === null || (!$negative) || $fraction[0] === 0) {
            return $fraction;
        }

        return [-$fraction[0], $fraction[1]];
    }

    /** @return array{string, string}|null */
    public static function fromFloatAsStrings(float $value): ?array
    {
        if (!\is_finite($value)) {
            return null;
        }

        $precision = \ini_get('serialize_precision');
        if ($precision === false) {
            return null;
        }
        $restorePrecision = $precision !== '-1';
        if ($restorePrecision && \ini_set('serialize_precision', '-1') === false) {
            return null;
        }
        try {
            $literal = \json_encode(\abs($value), \JSON_PRESERVE_ZERO_FRACTION);
        } finally {
            if ($restorePrecision) {
                \ini_set('serialize_precision', $precision);
            }
        }
        if (!\is_string($literal)) {
            return null;
        }
        $fraction = self::decimalAsStrings($literal);
        if ($fraction === null || $value >= 0.0 || $fraction[0] === '0') {
            return $fraction;
        }

        return [self::negateString($fraction[0]), $fraction[1]];
    }

    /** @return array{string, string}|null Exact rational value of the binary float. */
    public static function fromFloatExactAsStrings(float $value): ?array
    {
        if (!\is_finite($value)) {
            return null;
        }
        $unpacked = \unpack('C*', \pack('E', $value));
        if ($unpacked === false || \count($unpacked) !== 8) {
            return null;
        }
        $bytes = [];
        foreach ($unpacked as $byte) {
            if (!\is_int($byte)) {
                return null;
            }
            $bytes[] = $byte;
        }

        $negative = ($bytes[0] & 0x80) !== 0;
        $exponent = (($bytes[0] & 0x7f) << 4) | ($bytes[1] >> 4);
        $significand = $exponent === 0 ? '0' : '1';
        for ($bit = 3; $bit >= 0; --$bit) {
            $significand = self::appendBinaryBit($significand, ($bytes[1] >> $bit) & 1);
            if ($significand === null) {
                return null;
            }
        }
        for ($index = 2; $index < 8; ++$index) {
            for ($bit = 7; $bit >= 0; --$bit) {
                $significand = self::appendBinaryBit($significand, ($bytes[$index] >> $bit) & 1);
                if ($significand === null) {
                    return null;
                }
            }
        }
        if ($significand === '0') {
            return ['0', '1'];
        }

        $binaryExponent = ($exponent === 0 ? 1 - 1023 : $exponent - 1023) - 52;
        while ($binaryExponent < 0) {
            $half = self::divideIntegerMagnitudes($significand, '2');
            if ($half === null || $half[1] !== '0') {
                break;
            }
            $significand = $half[0];
            ++$binaryExponent;
        }

        $numerator = $significand;
        $denominator = '1';
        if ($binaryExponent >= 0) {
            for ($shift = 0; $shift < $binaryExponent; ++$shift) {
                $numerator = self::multiplyIntegerStrings($numerator, '2');
                if ($numerator === null) {
                    return null;
                }
            }
        } else {
            for ($shift = 0; $shift < -$binaryExponent; ++$shift) {
                $denominator = self::multiplyIntegerStrings($denominator, '2');
                if ($denominator === null) {
                    return null;
                }
            }
        }

        return [$negative ? self::negateString($numerator) : $numerator, $denominator];
    }

    private static function appendBinaryBit(string $value, int $bit): ?string
    {
        $doubled = self::multiplyIntegerStrings($value, '2');
        if ($doubled === null) {
            return null;
        }

        return $bit === 0 ? $doubled : self::addIntegerStrings($doubled, '1');
    }

    /** @return array{string, string}|null */
    private static function exactRationalTrigonometricValue(FunctionCallNode $node): ?array
    {
        $multiple = self::piMultiple(self::unwrapGroupingNode($node->arguments[0]));
        if ($multiple === null) {
            return null;
        }
        if (\in_array($node->name, ['tan', 'cot'], true)) {
            if (self::wouldMultiplyOverflow($multiple[0], 4)) {
                return null;
            }
            $scaledNumerator = $multiple[0] * 4;
            if ($scaledNumerator % $multiple[1] !== 0) {
                return null;
            }
            $step = intdiv($scaledNumerator, $multiple[1]) % 4;
            if ($step < 0) {
                $step += 4;
            }

            return match ([$node->name, $step]) {
                ['tan', 0], ['cot', 2] => ['0', '1'],
                ['tan', 1], ['cot', 1] => ['1', '1'],
                ['tan', 3], ['cot', 3] => ['-1', '1'],
                default => null,
            };
        }
        if (self::wouldMultiplyOverflow($multiple[0], 6)) {
            return null;
        }
        $scaledNumerator = $multiple[0] * 6;
        if ($scaledNumerator % $multiple[1] !== 0) {
            return null;
        }
        $step = intdiv($scaledNumerator, $multiple[1]) % 12;
        $cosine = \in_array($node->name, ['cos', 'sec'], true);
        $reciprocal = \in_array($node->name, ['sec', 'csc'], true);
        if ($cosine) {
            $step = ($step + 3) % 12;
        }
        $step %= 12;
        if ($step < 0) {
            $step += 12;
        }

        $value = match ($step) {
            0, 6 => ['0', '1'],
            3 => ['1', '1'],
            9 => ['-1', '1'],
            1, 5 => ['1', '2'],
            7, 11 => ['-1', '2'],
            default => null,
        };
        if (!$reciprocal || $value === null || $value[0] === '0') {
            return $value;
        }

        return self::normalizeStringFraction($value[1], $value[0]);
    }

    /** @return array{string, string}|null */
    private static function exactSpecialFunctionValue(FunctionCallNode $node): ?array
    {
        if (
            \in_array($node->name, ['besselj', 'besseli'], true)
            && \count($node->arguments) === 2
        ) {
            $order = self::fromNodeAsStrings($node->arguments[0]);
            $argument = self::fromNodeAsStrings($node->arguments[1]);
            if (
                $order === null
                || $order[1] !== '1'
                || $argument !== ['0', '1']
            ) {
                return null;
            }
            $integerOrder = \filter_var($order[0], FILTER_VALIDATE_INT);
            if (
                !\is_int($integerOrder)
                || \abs($integerOrder) > \MathPHP\Function\SpecialFunctions::MAX_BESSEL_ORDER
            ) {
                return null;
            }

            return $integerOrder === 0 ? ['1', '1'] : ['0', '1'];
        }
        if ($node->name === 'beta' && \count($node->arguments) === 2) {
            return self::exactIntegerBetaValue($node);
        }
        if ($node->name === 'lgamma' && \count($node->arguments) === 1) {
            $argument = self::fromNodeAsStrings($node->arguments[0]);

            return $argument !== null
                && $argument[1] === '1'
                && \in_array($argument[0], ['1', '2'], true)
                    ? ['0', '1']
                    : null;
        }
        if (
            !\in_array($node->name, ['gamma', 'zeta'], true)
            || \count($node->arguments) !== 1
        ) {
            return null;
        }
        $argument = self::fromNodeAsStrings($node->arguments[0]);
        if ($argument === null || $argument[1] !== '1') {
            return null;
        }
        if ($node->name === 'gamma') {
            $integer = \filter_var($argument[0], FILTER_VALIDATE_INT);
            if (!\is_int($integer) || $integer < 1 || $integer > 171) {
                return null;
            }
            $factorial = '1';
            for ($factor = 2; $factor < $integer; ++$factor) {
                $factorial = self::multiplyIntegerStrings($factorial, (string) $factor);
                if ($factorial === null) {
                    return null;
                }
            }

            return [$factorial, '1'];
        }
        if ($argument[0] === '0') {
            return ['-1', '2'];
        }
        $knownNegativeOddValue = match ($argument[0]) {
            '-1' => ['-1', '12'],
            '-3' => ['1', '120'],
            '-5' => ['-1', '252'],
            '-7' => ['1', '240'],
            '-9' => ['-1', '132'],
            '-11' => ['691', '32760'],
            '-13' => ['-1', '12'],
            '-15' => ['3617', '8160'],
            default => null,
        };
        if ($knownNegativeOddValue !== null) {
            return $knownNegativeOddValue;
        }
        $negativeOddInteger = \filter_var($argument[0], FILTER_VALIDATE_INT);
        if (
            \is_int($negativeOddInteger)
            && $negativeOddInteger <= -17
            && $negativeOddInteger >= -(self::MAX_EXACT_BERNOULLI_ORDER - 1)
            && ($negativeOddInteger % 2) !== 0
        ) {
            $bernoulli = self::bernoulliNumber(-$negativeOddInteger + 1);

            return $bernoulli === null
                ? null
                : self::multiplyStringFractions(
                    $bernoulli,
                    ['-1', (string) (-$negativeOddInteger + 1)],
                );
        }
        if (
            $argument[0][0] === '-'
            && ((int) \substr($argument[0], -1) % 2) === 0
        ) {
            return ['0', '1'];
        }

        return null;
    }

    /** @return array{string, string}|null */
    private static function bernoulliNumber(int $order): ?array
    {
        if ($order < 0 || $order > self::MAX_EXACT_BERNOULLI_ORDER) {
            return null;
        }
        $numbers = [['1', '1']];
        $work = 0;
        for ($currentOrder = 1; $currentOrder <= $order; ++$currentOrder) {
            $sum = ['0', '1'];
            $binomial = '1';
            for ($index = 0; $index < $currentOrder; ++$index) {
                $work += \strlen($binomial)
                    + \strlen($numbers[$index][0])
                    + \strlen($numbers[$index][1])
                    + \strlen($sum[0])
                    + \strlen($sum[1]);
                if ($work > self::MAX_STRING_DIVISION_DIGIT_WORK) {
                    return null;
                }
                $term = self::multiplyStringFractions(
                    $numbers[$index],
                    [$binomial, '1'],
                );
                if ($term === null) {
                    return null;
                }
                $sum = self::addStringFractions($sum, $term);
                if ($sum === null) {
                    return null;
                }
                if ($index + 1 < $currentOrder) {
                    $numeratorFactor = $currentOrder + 1 - $index;
                    $denominatorFactor = $index + 1;
                    $commonFactor = self::greatestCommonDivisor(
                        $numeratorFactor,
                        $denominatorFactor,
                    );
                    $numeratorFactor = intdiv($numeratorFactor, $commonFactor);
                    $denominatorFactor = intdiv($denominatorFactor, $commonFactor);
                    [, $binomialRemainder] = self::divideIntegerMagnitudeBySmall(
                        $binomial,
                        $denominatorFactor,
                    );
                    $commonFactor = self::greatestCommonDivisor(
                        $denominatorFactor,
                        $binomialRemainder,
                    );
                    $denominatorFactor = intdiv($denominatorFactor, $commonFactor);
                    [$binomialQuotient, $binomialRemainder] = self::divideIntegerMagnitudeBySmall(
                        $binomial,
                        $commonFactor,
                    );
                    if ($denominatorFactor !== 1 || $binomialRemainder !== 0) {
                        return null;
                    }
                    $binomial = self::multiplyIntegerStrings(
                        $binomialQuotient,
                        (string) $numeratorFactor,
                    );
                    if ($binomial === null) {
                        return null;
                    }
                }
            }
            $number = self::multiplyStringFractions(
                $sum,
                ['-1', (string) ($currentOrder + 1)],
            );
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
        }

        return $numbers[$order];
    }

    /** @return array{string, string}|null */
    private static function exactIntegerBetaValue(FunctionCallNode $node): ?array
    {
        $left = self::fromNodeAsStrings($node->arguments[0]);
        $right = self::fromNodeAsStrings($node->arguments[1]);
        if ($left === null || $right === null) {
            return null;
        }

        if (
            $left[0] === '0'
            || $right[0] === '0'
            || $left[0][0] === '-'
            || $right[0][0] === '-'
        ) {
            return null;
        }

        if ($left === ['1', '1']) {
            return self::normalizeStringFraction($right[1], $right[0]);
        }
        if ($right === ['1', '1']) {
            return self::normalizeStringFraction($left[1], $left[0]);
        }

        if ($left[1] !== '1' || $right[1] !== '1') {
            $integerSide = $left[1] === '1' ? $left : ($right[1] === '1' ? $right : null);
            $rationalSide = $left[1] === '1' ? $right : ($right[1] === '1' ? $left : null);
            if ($integerSide === null || $rationalSide === null) {
                return null;
            }

            $integerShape = \filter_var($integerSide[0], FILTER_VALIDATE_INT);
            if (!\is_int($integerShape) || $integerShape < 1) {
                return null;
            }

            $fraction = self::normalizeStringFraction($rationalSide[1], $rationalSide[0]);
            if ($fraction === null) {
                return null;
            }

            $work = 0;
            for ($index = 1; $index < $integerShape; ++$index) {
                $scaledIndex = self::multiplyIntegerStrings((string) $index, $rationalSide[1]);
                if ($scaledIndex === null) {
                    return null;
                }
                $factorDenominator = self::addIntegerStrings($scaledIndex, $rationalSide[0]);
                if ($factorDenominator === null) {
                    return null;
                }

                $stepWork = \strlen((string) $index) * \strlen($rationalSide[1])
                    + \strlen($scaledIndex) + \strlen($rationalSide[0])
                    + \strlen($fraction[0]) + \strlen($fraction[1])
                    + \strlen($fraction[0]) * \strlen($scaledIndex)
                    + \strlen($fraction[1]) * \strlen($factorDenominator);
                if ($stepWork > self::MAX_STRING_DIVISION_DIGIT_WORK - $work) {
                    return null;
                }
                $work += $stepWork;

                $fraction = self::multiplyStringFractions(
                    $fraction,
                    [$scaledIndex, $factorDenominator],
                );
                if ($fraction === null) {
                    return null;
                }
            }

            return $fraction;
        }

        $leftShape = \filter_var($left[0], FILTER_VALIDATE_INT);
        $rightShape = \filter_var($right[0], FILTER_VALIDATE_INT);
        if (
            !\is_int($leftShape)
            || !\is_int($rightShape)
            || $leftShape < 1
            || $rightShape < 1
        ) {
            return null;
        }

        $smallerShape = \min($leftShape, $rightShape);
        $largerShape = \max($leftShape, $rightShape);
        $fraction = ['1', (string) $largerShape];
        $work = 0;
        for ($index = 1; $index < $smallerShape; ++$index) {
            if ($largerShape > \PHP_INT_MAX - $index) {
                return null;
            }
            $nextDenominator = $largerShape + $index;
            [, $denominatorRemainder] = self::divideIntegerMagnitudeBySmall(
                $fraction[1],
                $index,
            );
            $indexDivisor = self::greatestCommonDivisor($index, $denominatorRemainder);
            [, $numeratorRemainder] = self::divideIntegerMagnitudeBySmall(
                $fraction[0],
                $nextDenominator,
            );
            $denominatorDivisor = self::greatestCommonDivisor(
                $nextDenominator,
                $numeratorRemainder,
            );
            $reducedIndex = intdiv($index, $indexDivisor);
            $reducedNextDenominator = intdiv($nextDenominator, $denominatorDivisor);
            $stepWork = \strlen($fraction[1]) + \strlen($fraction[0])
                + (\strlen($fraction[0]) * \strlen((string) $reducedIndex))
                + (\strlen($fraction[1]) * \strlen((string) $reducedNextDenominator));
            if ($stepWork > self::MAX_STRING_DIVISION_DIGIT_WORK - $work) {
                return null;
            }
            $work += $stepWork;
            $reducedDenominator = self::divideIntegerMagnitudeBySmall(
                $fraction[1],
                $indexDivisor,
            )[0];
            $reducedNumerator = self::divideIntegerMagnitudeBySmall(
                $fraction[0],
                $denominatorDivisor,
            )[0];
            $numerator = self::multiplyIntegerStrings($reducedNumerator, (string) $reducedIndex);
            $denominator = self::multiplyIntegerStrings(
                $reducedDenominator,
                (string) $reducedNextDenominator,
            );
            if ($numerator === null || $denominator === null) {
                return null;
            }
            $fraction = [$numerator, $denominator];
        }

        return $fraction;
    }

    /** @return array{string, string}|null */
    private static function exactIntegerFunctionValue(FunctionCallNode $node): ?array
    {
        if ($node->name === 'doublefactorial') {
            if (\count($node->arguments) !== 1) {
                return null;
            }
            $operand = self::fromNode($node->arguments[0]);
            if ($operand === null || $operand[1] !== 1 || $operand[0] < -1) {
                return null;
            }
            $result = 1;
            for ($factor = $operand[0]; $factor > 1; $factor -= 2) {
                if ($result > intdiv(\PHP_INT_MAX, $factor)) {
                    return null;
                }
                $result *= $factor;
            }

            return [(string) $result, '1'];
        }
        if (
            !\in_array($node->name, ['gcd', 'lcm', 'binomial', 'permutation'], true)
            || \count($node->arguments) !== 2
        ) {
            return null;
        }
        $left = self::fromNode($node->arguments[0]);
        $right = self::fromNode($node->arguments[1]);
        if (
            $left === null
            || $right === null
            || $left[1] !== 1
            || $right[1] !== 1
        ) {
            return null;
        }
        [$first, $second] = [$left[0], $right[0]];
        if ($first === \PHP_INT_MIN || $second === \PHP_INT_MIN) {
            return null;
        }

        if ($node->name === 'gcd') {
            if ($first === 0 && $second === 0) {
                return ['0', '1'];
            }
            $result = self::greatestCommonDivisor($first, $second);

            return [(string) $result, '1'];
        }
        if ($node->name === 'lcm') {
            if ($first === 0 || $second === 0) {
                return ['0', '1'];
            }
            $first = \abs($first);
            $second = \abs($second);
            $divisor = self::greatestCommonDivisor($first, $second);
            $reducedFirst = intdiv($first, $divisor);
            if ($reducedFirst > intdiv(\PHP_INT_MAX, $second)) {
                return null;
            }

            return [(string) ($reducedFirst * $second), '1'];
        }
        if ($first < 0 || $second < 0) {
            return null;
        }
        if ($second > $first) {
            return ['0', '1'];
        }

        $result = 1;
        if ($node->name === 'binomial') {
            $termCount = \min($second, $first - $second);
            if ($termCount > self::MAX_EXACT_INTEGER_FUNCTION_WORK) {
                return null;
            }
            for ($index = 1; $index <= $termCount; ++$index) {
                $numerator = $first - $termCount + $index;
                $divisor = self::greatestCommonDivisor($numerator, $index);
                $numerator = intdiv($numerator, $divisor);
                $denominator = intdiv($index, $divisor);
                $remainingDivisor = self::greatestCommonDivisor($result, $denominator);
                $result = intdiv($result, $remainingDivisor);
                $denominator = intdiv($denominator, $remainingDivisor);
                if ($denominator !== 1 || $result > intdiv(\PHP_INT_MAX, $numerator)) {
                    return null;
                }
                $result *= $numerator;
            }
        } else {
            if ($second > self::MAX_EXACT_INTEGER_FUNCTION_WORK) {
                return null;
            }
            for ($index = 0; $index < $second; ++$index) {
                $factor = $first - $index;
                if ($factor !== 0 && $result > intdiv(\PHP_INT_MAX, $factor)) {
                    return null;
                }
                $result *= $factor;
            }
        }

        return [(string) $result, '1'];
    }

    /** @return array{string, string}|null */
    private static function exactRationalExtremumValue(FunctionCallNode $node): ?array
    {
        if (
            !\in_array($node->name, ['min', 'max'], true)
            || $node->arguments === []
            || \count($node->arguments) > 16
        ) {
            return null;
        }
        $selected = self::fromNodeAsStrings($node->arguments[0]);
        if ($selected === null) {
            return null;
        }
        foreach (\array_slice($node->arguments, 1) as $argument) {
            $candidate = self::fromNodeAsStrings($argument);
            if ($candidate === null) {
                return null;
            }
            if ($candidate === $selected) {
                continue;
            }
            if ($candidate[0] === '0' || $selected[0] === '0') {
                $comparison = $candidate[0] === '0'
                    ? ($selected[0][0] === '-' ? 1 : -1)
                    : ($candidate[0][0] === '-' ? -1 : 1);
                if (
                    ($node->name === 'min' && $comparison < 0)
                    || ($node->name === 'max' && $comparison > 0)
                ) {
                    $selected = $candidate;
                }
                continue;
            }
            $leftWork = \strlen(\ltrim($selected[0], '-')) * \strlen($candidate[1]);
            $rightWork = \strlen(\ltrim($candidate[0], '-')) * \strlen($selected[1]);
            if (
                $leftWork > self::MAX_STRING_DIVISION_DIGIT_WORK
                || $rightWork > self::MAX_STRING_DIVISION_DIGIT_WORK
            ) {
                return null;
            }
            $selectedScaled = self::multiplyIntegerStrings($selected[0], $candidate[1]);
            $candidateScaled = self::multiplyIntegerStrings($candidate[0], $selected[1]);
            if ($selectedScaled === null || $candidateScaled === null) {
                return null;
            }
            $comparison = self::compareSignedIntegerStrings($candidateScaled, $selectedScaled);
            if (
                ($node->name === 'min' && $comparison < 0)
                || ($node->name === 'max' && $comparison > 0)
            ) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    private static function compareSignedIntegerStrings(string $left, string $right): int
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $comparison = self::compareIntegerMagnitudes(
            \ltrim($left, '-'),
            \ltrim($right, '-'),
        );

        return $leftNegative ? -$comparison : $comparison;
    }

    /** @return array{string, string}|null */
    private static function exactHypotenuseValue(FunctionCallNode $node): ?array
    {
        if ($node->name !== 'hypot' || \count($node->arguments) !== 2) {
            return null;
        }
        $left = self::fromNodeAsStrings($node->arguments[0]);
        $right = self::fromNodeAsStrings($node->arguments[1]);
        if ($left === null || $right === null) {
            return null;
        }
        if ($left[0] === '0') {
            return [\ltrim($right[0], '-'), $right[1]];
        }
        if ($right[0] === '0') {
            return [\ltrim($left[0], '-'), $left[1]];
        }
        $leftNumerator = \ltrim($left[0], '-');
        $rightNumerator = \ltrim($right[0], '-');
        if (
            \strlen($leftNumerator) ** 2 > self::MAX_STRING_DIVISION_DIGIT_WORK
            || \strlen($left[1]) ** 2 > self::MAX_STRING_DIVISION_DIGIT_WORK
            || \strlen($rightNumerator) ** 2 > self::MAX_STRING_DIVISION_DIGIT_WORK
            || \strlen($right[1]) ** 2 > self::MAX_STRING_DIVISION_DIGIT_WORK
        ) {
            return null;
        }
        $leftSquare = [
            self::multiplyIntegerStrings($leftNumerator, $leftNumerator),
            self::multiplyIntegerStrings($left[1], $left[1]),
        ];
        $rightSquare = [
            self::multiplyIntegerStrings($rightNumerator, $rightNumerator),
            self::multiplyIntegerStrings($right[1], $right[1]),
        ];
        if (
            $leftSquare[0] === null
            || $leftSquare[1] === null
            || $rightSquare[0] === null
            || $rightSquare[1] === null
        ) {
            return null;
        }
        $denominatorGcd = self::greatestCommonDivisorAsString($leftSquare[1], $rightSquare[1]);
        if ($denominatorGcd === null) {
            return null;
        }
        $leftScaleDivision = self::divideIntegerMagnitudes($rightSquare[1], $denominatorGcd);
        $rightScaleDivision = self::divideIntegerMagnitudes($leftSquare[1], $denominatorGcd);
        if (
            $leftScaleDivision === null
            || $rightScaleDivision === null
            || $leftScaleDivision[1] !== '0'
            || $rightScaleDivision[1] !== '0'
        ) {
            return null;
        }
        $leftScaled = self::multiplyIntegerStrings($leftSquare[0], $leftScaleDivision[0]);
        $rightScaled = self::multiplyIntegerStrings($rightSquare[0], $rightScaleDivision[0]);
        $denominator = self::multiplyIntegerStrings($leftSquare[1], $leftScaleDivision[0]);
        if ($leftScaled === null || $rightScaled === null || $denominator === null) {
            return null;
        }
        $numerator = self::addIntegerStrings($leftScaled, $rightScaled);
        if ($numerator === null) {
            return null;
        }
        $sum = self::normalizeStringFraction($numerator, $denominator);
        if ($sum === null) {
            return null;
        }

        return self::exactRootAsStrings($sum, 2);
    }

    /** @return array{string, string}|null */
    private static function exactRegularizedFunctionValue(FunctionCallNode $node): ?array
    {
        if (\in_array($node->name, ['gammainc', 'gammaincc', 'gammaincinv', 'gammainccinv'], true)) {
            if (\count($node->arguments) !== 2) {
                return null;
            }
            $shape = self::fromNode($node->arguments[0]);
            $level = self::fromNode($node->arguments[1]);
            if ($shape === null || $shape[0] <= 0) {
                return null;
            }
            if (
                $level === null
                && $shape === [1, 1]
                && \in_array($node->name, ['gammainc', 'gammaincc'], true)
            ) {
                $logarithm = self::unwrapGroupingNode($node->arguments[1]);
                if (
                    $logarithm instanceof FunctionCallNode
                    && $logarithm->name === 'ln'
                    && \count($logarithm->arguments) === 1
                ) {
                    $logArgument = self::fromNodeAsStrings($logarithm->arguments[0]);
                    $finiteLogArgument = self::fromNode($logarithm->arguments[0]);
                    if (
                        $logArgument !== null
                        && $finiteLogArgument !== null
                        && $finiteLogArgument[0] > 0
                        && $logArgument[0][0] !== '-'
                        && self::compareIntegerMagnitudes($logArgument[0], $logArgument[1]) >= 0
                        && $logArgument[0] !== '0'
                    ) {
                        $tail = self::normalizeStringFraction($logArgument[1], $logArgument[0]);

                        return $node->name === 'gammaincc'
                            ? $tail
                            : ($tail === null ? null : self::oneMinusFraction($tail));
                    }
                }
            }
            if ($level === null) {
                return null;
            }
            if ($node->name === 'gammainc' && $level === [0, 1]) {
                return ['0', '1'];
            }
            if ($node->name === 'gammaincc' && $level === [0, 1]) {
                return ['1', '1'];
            }
            if (
                ($node->name === 'gammaincinv' && $level === [0, 1])
                || ($node->name === 'gammainccinv' && $level === [1, 1])
            ) {
                return ['0', '1'];
            }

            return null;
        }
        if (
            !\in_array(
                $node->name,
                ['betainc', 'betaincc', 'betaincinv', 'betainccinv'],
                true,
            )
            || \count($node->arguments) !== 3
        ) {
            return null;
        }
        $firstShape = self::fromNode($node->arguments[0]);
        $secondShape = self::fromNode($node->arguments[1]);
        $level = self::fromNode($node->arguments[2]);
        if (
            $firstShape === null
            || $secondShape === null
            || $firstShape[0] <= 0
            || $secondShape[0] <= 0
            || $level === null
        ) {
            return null;
        }
        if (\in_array($node->name, ['betaincinv', 'betainccinv'], true)) {
            if ($level[0] < 0 || $level[0] > $level[1]) {
                return null;
            }
            $probability = [(string) $level[0], (string) $level[1]];
            $complement = $node->name === 'betainccinv';
            if ($level[0] === 0 || $level[0] === $level[1]) {
                $zeroEndpoint = (!$complement && $level[0] === 0)
                    || ($complement && $level[0] === $level[1]);

                return $zeroEndpoint
                    ? ['0', '1']
                    : ['1', '1'];
            }
            if ($firstShape === $secondShape && $level === [1, 2]) {
                return ['1', '2'];
            }

            if ($secondShape === [1, 1]) {
                $radicand = $complement ? self::oneMinusFraction($probability) : $probability;

                return $radicand === null
                    ? null
                    : self::exactRationalPowerAsStrings(
                        $radicand,
                        $firstShape[1],
                        $firstShape[0],
                    );
            }
            if ($firstShape === [1, 1]) {
                $radicand = $complement ? $probability : self::oneMinusFraction($probability);
                $root = $radicand === null
                    ? null
                    : self::exactRationalPowerAsStrings(
                        $radicand,
                        $secondShape[1],
                        $secondShape[0],
                    );

                return $root === null ? null : self::oneMinusFraction($root);
            }

            return null;
        }
        if ($level[0] < 0 || $level[0] > $level[1]) {
            return null;
        }
        if ($level === [0, 1]) {
            return \in_array($node->name, ['betainc', 'betaincinv'], true)
                ? ['0', '1']
                : ['1', '1'];
        }
        if ($level === [1, 1]) {
            return \in_array($node->name, ['betainc', 'betaincinv'], true)
                ? ['1', '1']
                : ['0', '1'];
        }
        if ($firstShape === $secondShape && $level === [1, 2]) {
            return ['1', '2'];
        }

        if ($firstShape === [1, 1]) {
            $base = self::oneMinusFraction([(string) $level[0], (string) $level[1]]);
            $poweredBase = $base === null
                ? null
                : self::exactRationalPowerAsStrings(
                    $base,
                    $secondShape[0],
                    $secondShape[1],
                );
            $regularizedValue = $poweredBase === null ? null : self::oneMinusFraction($poweredBase);
        } elseif ($secondShape === [1, 1]) {
            $regularizedValue = self::exactRationalPowerAsStrings(
                [(string) $level[0], (string) $level[1]],
                $firstShape[0],
                $firstShape[1],
            );
        } else {
            $firstIntegerShape = self::integerShape($firstShape);
            $secondIntegerShape = self::integerShape($secondShape);
            if ($firstIntegerShape === null || $secondIntegerShape === null) {
                return null;
            }
            $regularizedValue = self::exactIntegerRegularizedBetaValue(
                $firstIntegerShape,
                $secondIntegerShape,
                [(string) $level[0], (string) $level[1]],
            );
        }
        if ($regularizedValue === null) {
            return null;
        }

        return $node->name === 'betainc'
            ? $regularizedValue
            : self::oneMinusFraction($regularizedValue);
    }

    /** @return array{string, string}|null */
    private static function exactInverseCompositionValue(FunctionCallNode $node): ?array
    {
        if (\in_array($node->name, ['erf', 'erfc'], true) && \count($node->arguments) === 1) {
            $inverseName = $node->name === 'erf' ? 'erfinv' : 'erfcinv';
            $inverse = self::unwrapGroupingNode($node->arguments[0]);
            if (
                !$inverse instanceof FunctionCallNode
                || $inverse->name !== $inverseName
                || \count($inverse->arguments) !== 1
            ) {
                return null;
            }
            $fraction = self::fromNodeAsStrings($inverse->arguments[0]);
            if ($fraction === null) {
                return null;
            }
            if ($node->name === 'erf') {
                $numeratorMagnitude = \ltrim($fraction[0], '-');

                return self::compareIntegerMagnitudes($numeratorMagnitude, $fraction[1]) < 0
                    ? $fraction
                    : null;
            }
            if ($fraction[0][0] === '-') {
                return null;
            }
            $twiceDenominator = self::multiplyIntegerStrings($fraction[1], '2');

            return $fraction[0] !== '0'
                && $twiceDenominator !== null
                && self::compareIntegerMagnitudes($fraction[0], $twiceDenominator) < 0
                    ? $fraction
                    : null;
        }

        if (\in_array($node->name, ['gammainc', 'gammaincc'], true) && \count($node->arguments) === 2) {
            $inverse = self::unwrapGroupingNode($node->arguments[1]);
            if (
                !$inverse instanceof FunctionCallNode
                || !\in_array($inverse->name, ['gammaincinv', 'gammainccinv'], true)
                || \count($inverse->arguments) !== 2
            ) {
                return null;
            }
            $shape = self::fromNode($node->arguments[0]);
            $inverseShape = self::fromNode($inverse->arguments[0]);
            $probability = self::fromNode($inverse->arguments[1]);
            if (
                $shape === null
                || $shape[0] <= 0
                || $shape !== $inverseShape
                || $probability === null
                || $probability[0] < 0
                || $probability[0] > $probability[1]
            ) {
                return null;
            }
            if (
                ($inverse->name === 'gammaincinv' && $probability[0] === $probability[1])
                || ($inverse->name === 'gammainccinv' && $probability[0] === 0)
            ) {
                return null;
            }
            $fraction = [(string) $probability[0], (string) $probability[1]];
            $complement = ($node->name === 'gammaincc') !== ($inverse->name === 'gammainccinv');

            return $complement ? self::oneMinusFraction($fraction) : $fraction;
        }

        if (\in_array($node->name, ['betainc', 'betaincc'], true) && \count($node->arguments) === 3) {
            $inverse = self::unwrapGroupingNode($node->arguments[2]);
            if (
                !$inverse instanceof FunctionCallNode
                || !\in_array($inverse->name, ['betaincinv', 'betainccinv'], true)
                || \count($inverse->arguments) !== 3
            ) {
                return null;
            }
            $firstShape = self::fromNode($node->arguments[0]);
            $secondShape = self::fromNode($node->arguments[1]);
            $inverseFirstShape = self::fromNode($inverse->arguments[0]);
            $inverseSecondShape = self::fromNode($inverse->arguments[1]);
            $probability = self::fromNode($inverse->arguments[2]);
            if (
                $firstShape === null
                || $secondShape === null
                || $firstShape[0] <= 0
                || $secondShape[0] <= 0
                || $firstShape !== $inverseFirstShape
                || $secondShape !== $inverseSecondShape
                || $probability === null
                || $probability[0] < 0
                || $probability[0] > $probability[1]
            ) {
                return null;
            }
            $fraction = [(string) $probability[0], (string) $probability[1]];
            $complement = ($node->name === 'betaincc') !== ($inverse->name === 'betainccinv');

            return $complement ? self::oneMinusFraction($fraction) : $fraction;
        }

        return null;
    }

    /** @param array{int, int} $shape */
    private static function integerShape(array $shape): ?int
    {
        return $shape[1] === 1 && $shape[0] > 0
            ? $shape[0]
            : null;
    }

    /**
     * @param array{string, string} $level
     * @return array{string, string}|null
     */
    private static function exactIntegerRegularizedBetaValue(
        int $firstShape,
        int $secondShape,
        array $level,
    ): ?array {
        $complement = $firstShape < $secondShape;
        $largeShape = \max($firstShape, $secondShape);
        $termCount = \min($firstShape, $secondShape);
        $base = $complement ? self::oneMinusFraction($level) : $level;
        $step = $complement ? $level : self::oneMinusFraction($level);
        if ($base === null || $step === null) {
            return null;
        }
        $poweredBase = self::exactPowerAsStrings($base, $largeShape);
        if ($poweredBase === null) {
            return null;
        }
        $sum = ['1', '1'];
        $stepPower = ['1', '1'];
        $coefficient = '1';
        $work = 0;
        for ($index = 1; $index < $termCount; ++$index) {
            if ($largeShape > \PHP_INT_MAX - ($index - 1)) {
                return null;
            }
            $numeratorFactor = $largeShape + $index - 1;
            $commonFactor = self::greatestCommonDivisor($numeratorFactor, $index);
            $reducedNumeratorFactor = intdiv($numeratorFactor, $commonFactor);
            $reducedDenominatorFactor = intdiv($index, $commonFactor);
            [$coefficientQuotient, $coefficientRemainder] = self::divideIntegerMagnitudeBySmall(
                $coefficient,
                $reducedDenominatorFactor,
            );
            if ($coefficientRemainder !== 0) {
                return null;
            }
            $coefficient = self::multiplyIntegerStrings(
                $coefficientQuotient,
                (string) $reducedNumeratorFactor,
            );
            if ($coefficient === null) {
                return null;
            }
            $work += \strlen($coefficient)
                + \strlen($sum[0])
                + \strlen($sum[1])
                + \strlen($stepPower[0])
                + \strlen($stepPower[1]);
            if ($work > self::MAX_STRING_DIVISION_DIGIT_WORK) {
                return null;
            }
            $stepPower = self::multiplyStringFractions($stepPower, $step);
            if ($stepPower === null) {
                return null;
            }
            $term = self::multiplyStringFractions([$coefficient, '1'], $stepPower);
            if ($term === null) {
                return null;
            }
            $sum = self::addStringFractions($sum, $term);
            if ($sum === null) {
                return null;
            }
        }
        $value = self::multiplyStringFractions($poweredBase, $sum);
        if ($value === null) {
            return null;
        }

        return $complement ? self::oneMinusFraction($value) : $value;
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private static function multiplyStringFractions(array $left, array $right): ?array
    {
        [$leftNumerator, $rightDenominator] = self::cancelCommonIntegerFactor(
            $left[0],
            $right[1],
        );
        [$rightNumerator, $leftDenominator] = self::cancelCommonIntegerFactor(
            $right[0],
            $left[1],
        );
        $numerator = self::multiplyIntegerStrings($leftNumerator, $rightNumerator);
        $denominator = self::multiplyIntegerStrings($leftDenominator, $rightDenominator);

        return $numerator === null || $denominator === null
            ? null
            : self::normalizeStringFraction($numerator, $denominator);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private static function addStringFractions(array $left, array $right): ?array
    {
        $commonFactor = self::greatestCommonDivisorAsString($left[1], $right[1]);
        if ($commonFactor === null) {
            return null;
        }
        $leftScale = self::divideIntegerMagnitudes($right[1], $commonFactor);
        $rightScale = self::divideIntegerMagnitudes($left[1], $commonFactor);
        if (
            $leftScale === null
            || $rightScale === null
            || $leftScale[1] !== '0'
            || $rightScale[1] !== '0'
        ) {
            return null;
        }
        $leftNumerator = self::multiplyIntegerStrings($left[0], $leftScale[0]);
        $rightNumerator = self::multiplyIntegerStrings($right[0], $rightScale[0]);
        $denominator = self::multiplyIntegerStrings($left[1], $leftScale[0]);
        if ($leftNumerator === null || $rightNumerator === null || $denominator === null) {
            return null;
        }
        $numerator = self::addIntegerStrings($leftNumerator, $rightNumerator);

        return $numerator === null
            ? null
            : self::normalizeStringFraction($numerator, $denominator);
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private static function oneMinusFraction(array $fraction): ?array
    {
        if (
            self::compareIntegerMagnitudes($fraction[0], $fraction[1]) > 0
        ) {
            return null;
        }

        return self::normalizeStringFraction(
            self::subtractIntegerMagnitudes($fraction[1], $fraction[0]),
            $fraction[1],
        );
    }

    /** @return array{string, string}|null */
    private static function exactLogarithmCompositionValue(FunctionCallNode $node): ?array
    {
        if (
            !\in_array(
                $node->name,
                ['exp', 'expm1', 'sinh', 'cosh', 'tanh', 'sech', 'csch', 'coth'],
                true,
            )
            || \count($node->arguments) !== 1
        ) {
            return null;
        }
        $logarithm = self::unwrapGroupingNode($node->arguments[0]);
        if (
            !$logarithm instanceof FunctionCallNode
            || $logarithm->name !== 'ln'
            || \count($logarithm->arguments) !== 1
        ) {
            return null;
        }
        $argument = self::fromNodeAsStrings($logarithm->arguments[0]);
        if ($argument === null || $argument[0] === '0' || $argument[0][0] === '-') {
            return null;
        }
        if ($node->name === 'exp') {
            return $argument;
        }
        if ($node->name === 'expm1') {
            return self::addStringFractions($argument, ['-1', '1']);
        }

        if ($node->name === 'sinh' || $node->name === 'cosh') {
            $reciprocal = [$argument[1], $argument[0]];
            if ($node->name === 'sinh') {
                $reciprocal[0] = self::negateString($reciprocal[0]);
            }
            $sum = self::addStringFractions($argument, $reciprocal);

            return $sum === null
                ? null
                : self::multiplyStringFractions($sum, ['1', '2']);
        }

        $square = self::multiplyStringFractions($argument, $argument);
        if ($square === null) {
            return null;
        }
        $difference = self::addStringFractions($square, ['-1', '1']);
        $sum = self::addStringFractions($square, ['1', '1']);
        if ($difference === null || $sum === null) {
            return null;
        }
        $numerator = match ($node->name) {
            'tanh' => $difference,
            'sech', 'csch' => $argument,
            default => $sum,
        };
        $denominator = \in_array($node->name, ['tanh', 'sech'], true) ? $sum : $difference;
        if ($denominator[0] === '0') {
            return null;
        }
        if ($node->name === 'sech' || $node->name === 'csch') {
            $numerator = self::multiplyStringFractions($numerator, ['2', '1']);
            if ($numerator === null) {
                return null;
            }
        }
        $result = self::multiplyStringFractions(
            $numerator,
            [$denominator[1], $denominator[0]],
        );

        return $result;
    }

    /** @return array{string, string}|null */
    private static function exactPowerLogarithmValue(FunctionCallNode $node): ?array
    {
        if ($node->name === 'ln' && \count($node->arguments) === 1) {
            $exponential = self::unwrapGroupingNode($node->arguments[0]);
            if (
                $exponential instanceof FunctionCallNode
                && $exponential->name === 'exp'
                && \count($exponential->arguments) === 1
            ) {
                return self::fromNodeAsStrings($exponential->arguments[0]);
            }
        }

        if (
            !\in_array($node->name, ['log', 'log10', 'log2'], true)
            || ($node->name === 'log' && \count($node->arguments) !== 2)
            || ($node->name !== 'log' && \count($node->arguments) !== 1)
        ) {
            return null;
        }
        $power = self::unwrapGroupingNode($node->arguments[0]);
        if (!$power instanceof BinaryOperationNode || $power->operator !== BinaryOperator::Power) {
            return null;
        }
        $base = self::fromNode($power->left);
        $exponent = self::fromNodeAsStrings($power->right);
        if ($base === null || $base[0] <= 0 || $exponent === null) {
            return null;
        }
        if ($node->name === 'log10') {
            return $base === [10, 1] ? $exponent : null;
        }
        if ($node->name === 'log2') {
            return $base === [2, 1] ? $exponent : null;
        }

        $logarithmBase = self::fromNode($node->arguments[1]);
        if (
            $logarithmBase === null
            || $logarithmBase[0] <= 0
            || $logarithmBase[0] === $logarithmBase[1]
            || $base !== $logarithmBase
        ) {
            return null;
        }

        return $exponent;
    }

    /** @return array{int, int}|null Coefficient of pi as a reduced rational. */
    private static function piMultiple(Node $node): ?array
    {
        $node = self::unwrapGroupingNode($node);
        if ($node instanceof ConstantNode) {
            return match ($node->name) {
                'pi' => [1, 1],
                'tau' => [2, 1],
                default => null,
            };
        }
        if ($node instanceof UnaryOperationNode) {
            $multiple = self::piMultiple($node->operand);
            if ($multiple === null || $node->operator === UnaryOperator::Plus) {
                return $multiple;
            }
            if ($multiple[0] === \PHP_INT_MIN) {
                return null;
            }

            return [-$multiple[0], $multiple[1]];
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        if (
            \in_array(
                $node->operator,
                [BinaryOperator::Add, BinaryOperator::Subtract],
                true,
            )
        ) {
            $left = self::piMultiple($node->left);
            $right = self::piMultiple($node->right);
            $subtract = $node->operator === BinaryOperator::Subtract;
            if ($left !== null && $right !== null) {
                return self::addFractions($left, $right, $subtract);
            }
            if ($left !== null && self::fromNode($node->right) === [0, 1]) {
                return $left;
            }
            if ($right !== null && self::fromNode($node->left) === [0, 1]) {
                return $subtract
                    ? ($right[0] === \PHP_INT_MIN ? null : [-$right[0], $right[1]])
                    : $right;
            }

            return null;
        }
        if ($node->operator === BinaryOperator::Multiply) {
            $leftMultiple = self::piMultiple($node->left);
            $rightRational = self::fromNode($node->right);
            if ($leftMultiple !== null && $rightRational !== null) {
                return self::multiplyFractions($leftMultiple, $rightRational);
            }
            $rightMultiple = self::piMultiple($node->right);
            $leftRational = self::fromNode($node->left);
            if ($rightMultiple !== null && $leftRational !== null) {
                return self::multiplyFractions($rightMultiple, $leftRational);
            }

            return null;
        }
        if ($node->operator === BinaryOperator::Divide) {
            $multiple = self::piMultiple($node->left);
            $divisor = self::fromNode($node->right);
            if ($multiple === null || $divisor === null || $divisor[0] === 0) {
                return null;
            }
            if ($divisor[0] < 0) {
                if ($divisor[0] === \PHP_INT_MIN) {
                    return null;
                }
                $divisor = [-$divisor[0], $divisor[1]];
                $multiple = [-$multiple[0], $multiple[1]];
            }

            return self::multiplyFractions($multiple, [$divisor[1], $divisor[0]]);
        }

        return null;
    }

    private static function unwrapGroupingNode(Node $node): Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node;
    }

    /** @param array{int, int} $left
     *  @param array{int, int} $right
     *  @return array{int, int}|null
     */
    private static function addFractions(array $left, array $right, bool $subtract): ?array
    {
        $denominatorGcd = self::greatestCommonDivisor($left[1], $right[1]);
        $leftFactor = intdiv($right[1], $denominatorGcd);
        $rightFactor = intdiv($left[1], $denominatorGcd);
        if (
            self::wouldMultiplyOverflow($left[0], $leftFactor)
            || self::wouldMultiplyOverflow($right[0], $rightFactor)
            || self::wouldMultiplyOverflow($right[1], $rightFactor)
        ) {
            return null;
        }
        $leftNumerator = $left[0] * $leftFactor;
        $rightNumerator = $right[0] * $rightFactor;
        if ($subtract) {
            if ($rightNumerator === \PHP_INT_MIN) {
                return null;
            }
            $rightNumerator = -$rightNumerator;
        }
        if (
            ($rightNumerator > 0 && $leftNumerator > \PHP_INT_MAX - $rightNumerator)
            || ($rightNumerator < 0 && $leftNumerator < \PHP_INT_MIN - $rightNumerator)
        ) {
            return null;
        }
        $numerator = $leftNumerator + $rightNumerator;
        $denominator = $right[1] * $rightFactor;
        $divisor = self::greatestCommonDivisor($numerator, $denominator);

        return [intdiv($numerator, $divisor), intdiv($denominator, $divisor)];
    }

    /** @return array{int, int}|null */
    private static function number(NumberNode $node): ?array
    {
        if (\is_int($node->value)) {
            return [$node->value, 1];
        }
        return self::decimal($node->literal);
    }

    /** @return array{int, int}|null */
    private static function decimal(string $literal): ?array
    {
        $fraction = self::decimalAsStrings($literal);
        if ($fraction === null) {
            return null;
        }
        $numerator = \filter_var($fraction[0], FILTER_VALIDATE_INT);
        $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);
        if (!\is_int($numerator) || !\is_int($denominator) || $denominator < 1) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /** @return array{array{string, string}, array{string, string}}|null Exact a,b in a*pi+b. */
    public static function piAffineAsStrings(Node $node): ?array
    {
        return self::constantAffineAsStrings($node, 'pi');
    }

    /** @return array{array{string, string}, array{string, string}}|null Exact a,b in a*e+b. */
    public static function eAffineAsStrings(Node $node): ?array
    {
        return self::constantAffineAsStrings($node, 'e');
    }

    /** @return array{array{string, string}, array{string, string}}|null Exact a,b in a*phi+b. */
    public static function phiAffineAsStrings(Node $node): ?array
    {
        return self::constantAffineAsStrings($node, 'phi');
    }

    /** @return array{array{string, string}, array{string, string}}|null */
    private static function constantAffineAsStrings(Node $node, string $constant): ?array
    {
        $affine = self::constantAffine($node, $constant);
        if ($affine === null) {
            return null;
        }

        return [
            [(string) $affine[0][0], (string) $affine[0][1]],
            [(string) $affine[1][0], (string) $affine[1][1]],
        ];
    }

    /** @return array{array{int, int}, array{int, int}}|null */
    private static function constantAffine(Node $node, string $constant): ?array
    {
        $node = self::unwrapGroupingNode($node);
        $rational = self::fromNode($node);
        if ($rational !== null) {
            return [[0, 1], $rational];
        }
        $multiple = match ($constant) {
            'pi' => self::piMultiple($node),
            'e' => self::eMultiple($node),
            'phi' => self::phiMultiple($node),
            default => null,
        };
        if ($multiple !== null) {
            return [$multiple, [0, 1]];
        }
        if ($node instanceof UnaryOperationNode) {
            $affine = self::constantAffine($node->operand, $constant);
            if ($affine === null || $node->operator === UnaryOperator::Plus) {
                return $affine;
            }
            if ($affine[0][0] === \PHP_INT_MIN || $affine[1][0] === \PHP_INT_MIN) {
                return null;
            }

            return [[-$affine[0][0], $affine[0][1]], [-$affine[1][0], $affine[1][1]]];
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if (
            $node->operator === BinaryOperator::Add
            || $node->operator === BinaryOperator::Subtract
        ) {
            $left = self::constantAffine($node->left, $constant);
            $right = self::constantAffine($node->right, $constant);
            if ($left === null || $right === null) {
                return null;
            }
            $subtract = $node->operator === BinaryOperator::Subtract;
            $multiple = self::addFractions($left[0], $right[0], $subtract);
            $offset = self::addFractions($left[1], $right[1], $subtract);

            return $multiple === null || $offset === null ? null : [$multiple, $offset];
        }
        if ($node->operator === BinaryOperator::Multiply) {
            foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$scaleNode, $affineNode]) {
                $scale = self::fromNode($scaleNode);
                $affine = self::constantAffine($affineNode, $constant);
                if ($scale === null || $affine === null) {
                    continue;
                }
                $multiple = self::multiplyFractions($scale, $affine[0]);
                $offset = self::multiplyFractions($scale, $affine[1]);

                return $multiple === null || $offset === null ? null : [$multiple, $offset];
            }

            return null;
        }
        if ($node->operator === BinaryOperator::Divide) {
            $denominator = self::fromNode($node->right);
            $affine = self::constantAffine($node->left, $constant);
            if ($denominator === null || $denominator[0] === 0 || $affine === null) {
                return null;
            }
            if ($denominator[0] === \PHP_INT_MIN) {
                return null;
            }
            $reciprocal = [$denominator[1], \abs($denominator[0])];
            if ($denominator[0] < 0) {
                $reciprocal[0] = -$reciprocal[0];
            }
            $multiple = self::multiplyFractions($affine[0], $reciprocal);
            $offset = self::multiplyFractions($affine[1], $reciprocal);

            return $multiple === null || $offset === null ? null : [$multiple, $offset];
        }

        return null;
    }

    /** @return array{int, int}|null */
    private static function eMultiple(Node $node): ?array
    {
        $node = self::unwrapGroupingNode($node);
        if ($node instanceof ConstantNode && $node->name === 'e') {
            return [1, 1];
        }
        if (
            $node instanceof FunctionCallNode
            && $node->name === 'exp'
            && \count($node->arguments) === 1
            && self::fromNode($node->arguments[0]) === [1, 1]
        ) {
            return [1, 1];
        }
        if ($node instanceof UnaryOperationNode) {
            $multiple = self::eMultiple($node->operand);
            if ($multiple === null || $node->operator === UnaryOperator::Plus) {
                return $multiple;
            }

            return $multiple[0] === \PHP_INT_MIN ? null : [-$multiple[0], $multiple[1]];
        }

        return null;
    }

    /** @return array{int, int}|null */
    private static function phiMultiple(Node $node): ?array
    {
        $node = self::unwrapGroupingNode($node);
        if ($node instanceof ConstantNode && $node->name === 'phi') {
            return [1, 1];
        }
        if ($node instanceof UnaryOperationNode) {
            $multiple = self::phiMultiple($node->operand);
            if ($multiple === null || $node->operator === UnaryOperator::Plus) {
                return $multiple;
            }

            return $multiple[0] === \PHP_INT_MIN ? null : [-$multiple[0], $multiple[1]];
        }

        return null;
    }

    private static function integerLiteralAsString(Node $node): ?string
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof NumberNode) {
            $fraction = self::decimalAsStrings($node->literal);

            return $fraction !== null && $fraction[1] === '1' ? $fraction[0] : null;
        }
        if (!$node instanceof UnaryOperationNode) {
            return null;
        }
        $value = self::integerLiteralAsString($node->operand);
        if ($value === null || $node->operator === UnaryOperator::Plus || $value === '0') {
            return $value;
        }

        return self::negateString($value);
    }

    /** @return array{string, string}|null */
    private static function decimalAsStrings(string $literal): ?array
    {
        $parts = \preg_split('/[eE]/', $literal);
        if ($parts === false || \count($parts) > 2) {
            return null;
        }
        $mantissa = $parts[0];
        if (\preg_match('/\A(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)\z/D', $mantissa) !== 1) {
            return null;
        }
        $exponent = 0;
        if (isset($parts[1])) {
            $parsedExponent = \filter_var($parts[1], FILTER_VALIDATE_INT);
            if (!\is_int($parsedExponent) || \abs($parsedExponent) > 10000) {
                return null;
            }
            $exponent = $parsedExponent;
        }
        [$integerPart, $fractionPart] = \array_pad(\explode('.', $mantissa, 2), 2, '');
        $digits = \ltrim(($integerPart === '' ? '0' : $integerPart) . $fractionPart, '0');
        if ($digits === '') {
            return ['0', '1'];
        }
        $scale = \strlen($fractionPart) - $exponent;
        if ($scale <= 0) {
            return [$digits . \str_repeat('0', -$scale), '1'];
        }

        $removableZeros = \min($scale, \strlen($digits) - \strlen(\rtrim($digits, '0')));
        $denominator = '1' . \str_repeat('0', $scale - $removableZeros);
        if ($removableZeros > 0) {
            $digits = \substr($digits, 0, -$removableZeros);
        }
        foreach ([[2, 1_048_576], [5, 390_625]] as [$factor, $chunk]) {
            while ($denominator !== '1') {
                [$numeratorQuotient, $numeratorRemainder] = self::divideIntegerMagnitudeBySmall(
                    $digits,
                    $chunk,
                );
                [$denominatorQuotient, $denominatorRemainder] = self::divideIntegerMagnitudeBySmall(
                    $denominator,
                    $chunk,
                );
                if ($numeratorRemainder === 0 && $denominatorRemainder === 0) {
                    $digits = $numeratorQuotient;
                    $denominator = $denominatorQuotient;
                    continue;
                }
                [$numeratorQuotient, $numeratorRemainder] = self::divideIntegerMagnitudeBySmall(
                    $digits,
                    $factor,
                );
                [$denominatorQuotient, $denominatorRemainder] = self::divideIntegerMagnitudeBySmall(
                    $denominator,
                    $factor,
                );
                if ($numeratorRemainder !== 0 || $denominatorRemainder !== 0) {
                    break;
                }
                $digits = $numeratorQuotient;
                $denominator = $denominatorQuotient;
            }
        }

        return [$digits, $denominator];
    }

    /** @return array{string, int} */
    private static function divideIntegerMagnitudeBySmall(string $value, int $divisor): array
    {
        $remainder = 0;
        $quotient = '';
        for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
            $partial = ($remainder * 10) + (int) $value[$index];
            $quotient .= (string) \intdiv($partial, $divisor);
            $remainder = $partial % $divisor;
        }

        $quotient = \ltrim($quotient, '0');

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }

    /** @return array{string, string}|null Quotient and remainder; null when the work cap is exceeded. */
    private static function divideIntegerMagnitudes(string $numerator, string $denominator): ?array
    {
        if (
            $denominator === '0'
            || \strlen($numerator) * \strlen($denominator) > self::MAX_STRING_DIVISION_DIGIT_WORK
        ) {
            return null;
        }

        $quotient = '';
        $remainder = '0';
        for ($index = 0, $length = \strlen($numerator); $index < $length; ++$index) {
            $remainder = \ltrim(($remainder === '0' ? '' : $remainder) . $numerator[$index], '0');
            if ($remainder === '') {
                $remainder = '0';
            }
            $digit = 0;
            while (self::compareIntegerMagnitudes($remainder, $denominator) >= 0) {
                $remainder = self::subtractIntegerMagnitudes($remainder, $denominator);
                ++$digit;
            }
            $quotient .= (string) $digit;
        }

        $quotient = \ltrim($quotient, '0');

        return [$quotient === '' ? '0' : $quotient, $remainder];
    }

    /** @param array{string, string} $fraction */
    private static function roundedIntegerAsString(array $fraction, string $mode): ?string
    {
        if ($fraction[1] === '0') {
            return null;
        }
        if ($fraction[1] === '1') {
            return $fraction[0];
        }

        $negative = $fraction[0][0] === '-';
        $magnitude = \ltrim($fraction[0], '-');
        $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);
        $safeDivisor = \is_int($denominator)
            && $denominator > 0
            && $denominator <= intdiv(\PHP_INT_MAX - 9, 10);
        if ($safeDivisor) {
            [$quotient, $smallRemainder] = self::divideIntegerMagnitudeBySmall(
                $magnitude,
                $denominator,
            );
            $hasRemainder = $smallRemainder !== 0;
            $roundUp = $mode === 'round'
                && $smallRemainder >= intdiv($denominator, 2) + ($denominator % 2);
        } else {
            if (self::compareIntegerMagnitudes($magnitude, $fraction[1]) < 0) {
                $quotient = '0';
                $remainder = $magnitude;
            } else {
                $division = self::divideIntegerMagnitudes($magnitude, $fraction[1]);
                if ($division === null) {
                    return null;
                }
                [$quotient, $remainder] = $division;
            }
            $hasRemainder = $remainder !== '0';
            [$halfThreshold, $halfRemainder] = self::divideIntegerMagnitudeBySmall(
                $fraction[1],
                2,
            );
            if ($halfRemainder !== 0) {
                $halfThreshold = self::addIntegerStrings($halfThreshold, '1');
                if ($halfThreshold === null) {
                    return null;
                }
            }
            $roundUp = $mode === 'round'
                && self::compareIntegerMagnitudes($remainder, $halfThreshold) >= 0;
        }

        if (
            ($mode === 'round' && $roundUp)
            || ($hasRemainder && $mode === 'floor' && $negative)
            || ($hasRemainder && $mode === 'ceil' && !$negative)
        ) {
            $quotient = self::addIntegerStrings($quotient, '1');
            if ($quotient === null) {
                return null;
            }
        }

        return $negative && $quotient !== '0' ? self::negateString($quotient) : $quotient;
    }

    private static function negateString(string $value): string
    {
        return $value[0] === '-' ? \substr($value, 1) : '-' . $value;
    }

    /** @return array{string, string}|null */
    private static function normalizeStringFraction(string $numerator, string $denominator): ?array
    {
        if ($denominator === '0') {
            return null;
        }
        if ($numerator === '0') {
            return ['0', '1'];
        }
        if ($denominator[0] === '-') {
            $numerator = self::negateString($numerator);
            $denominator = \substr($denominator, 1);
        }
        [$numerator, $denominator] = self::cancelCommonIntegerFactor($numerator, $denominator);
        if ($numerator === $denominator) {
            return ['1', '1'];
        }
        if ($numerator === self::negateString($denominator)) {
            return ['-1', '1'];
        }
        $numeratorMagnitude = \ltrim($numerator, '-');
        if ($numeratorMagnitude !== '1' && $denominator !== '1') {
            $commonFactor = self::greatestCommonDivisorAsString($numeratorMagnitude, $denominator);
            if ($commonFactor !== null && $commonFactor !== '1') {
                $numeratorDivision = self::divideIntegerMagnitudes($numeratorMagnitude, $commonFactor);
                $denominatorDivision = self::divideIntegerMagnitudes($denominator, $commonFactor);
                if (
                    $numeratorDivision !== null
                    && $denominatorDivision !== null
                    && $numeratorDivision[1] === '0'
                    && $denominatorDivision[1] === '0'
                ) {
                    return [
                        $numerator[0] === '-' && $numeratorDivision[0] !== '0'
                            ? self::negateString($numeratorDivision[0])
                            : $numeratorDivision[0],
                        $denominatorDivision[0],
                    ];
                }
            }
        }
        if (
            \strlen(\ltrim($numerator, '-')) > self::MAX_STRING_INTEGER_DIGITS
            || \strlen($denominator) > self::MAX_STRING_INTEGER_DIGITS
        ) {
            return null;
        }

        return [$numerator, $denominator];
    }

    private static function greatestCommonDivisorAsString(string $left, string $right): ?string
    {
        $left = \ltrim($left, '0');
        $right = \ltrim($right, '0');
        if ($left === '') {
            return $right === '' ? '1' : $right;
        }
        if ($right === '') {
            return $left;
        }

        $work = 0;
        while ($right !== '0') {
            $divisionWork = \strlen($left) * \strlen($right);
            if ($divisionWork > self::MAX_STRING_DIVISION_DIGIT_WORK - $work) {
                return null;
            }
            $division = self::divideIntegerMagnitudes($left, $right);
            if ($division === null) {
                return null;
            }
            $work += $divisionWork;
            [$left, $right] = [$right, $division[1]];
        }

        return $left;
    }

    private static function addIntegerStrings(string $left, string $right): ?string
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        $leftMagnitude = \ltrim($left, '-');
        $rightMagnitude = \ltrim($right, '-');
        if ($leftNegative === $rightNegative) {
            $magnitude = self::addIntegerMagnitudes($leftMagnitude, $rightMagnitude);
            $result = $leftNegative && $magnitude !== '0' ? '-' . $magnitude : $magnitude;
        } else {
            $comparison = self::compareIntegerMagnitudes($leftMagnitude, $rightMagnitude);
            if ($comparison === 0) {
                return '0';
            }
            $magnitude = $comparison > 0
                ? self::subtractIntegerMagnitudes($leftMagnitude, $rightMagnitude)
                : self::subtractIntegerMagnitudes($rightMagnitude, $leftMagnitude);
            $negative = $comparison > 0 ? $leftNegative : $rightNegative;
            $result = $negative ? '-' . $magnitude : $magnitude;
        }

        return \strlen(\ltrim($result, '-')) <= self::MAX_STRING_INTEGER_DIGITS ? $result : null;
    }

    /** @return array{string, string} Factors divided by their known common factor. */
    private static function cancelCommonIntegerFactor(string $left, string $right): array
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        $leftMagnitude = \ltrim($left, '-');
        $rightMagnitude = \ltrim($right, '-');
        if ($leftMagnitude === '0' || $rightMagnitude === '0') {
            return [$left, $right];
        }
        if ($leftMagnitude === $rightMagnitude) {
            return [$leftNegative ? '-1' : '1', $rightNegative ? '-1' : '1'];
        }

        $leftInteger = \filter_var($leftMagnitude, FILTER_VALIDATE_INT);
        $rightInteger = \filter_var($rightMagnitude, FILTER_VALIDATE_INT);
        if (\is_int($leftInteger) && \is_int($rightInteger)) {
            $commonFactor = self::greatestCommonDivisor($leftInteger, $rightInteger);
            if ($commonFactor <= 1) {
                return [$left, $right];
            }
            $leftQuotient = (string) intdiv($leftInteger, $commonFactor);
            $rightQuotient = (string) intdiv($rightInteger, $commonFactor);
        } else {
            $safeMaximum = intdiv(\PHP_INT_MAX - 9, 10);
            if (\is_int($leftInteger) && $leftInteger > 0 && $leftInteger <= $safeMaximum) {
                [, $remainder] = self::divideIntegerMagnitudeBySmall($rightMagnitude, $leftInteger);
                $commonFactor = self::greatestCommonDivisor($leftInteger, $remainder);
                if ($commonFactor <= 1) {
                    return [$left, $right];
                }
                [$leftQuotient, $leftRemainder] = self::divideIntegerMagnitudeBySmall(
                    $leftMagnitude,
                    $commonFactor,
                );
                [$rightQuotient, $rightRemainder] = self::divideIntegerMagnitudeBySmall(
                    $rightMagnitude,
                    $commonFactor,
                );
            } elseif (\is_int($rightInteger) && $rightInteger > 0 && $rightInteger <= $safeMaximum) {
                [, $remainder] = self::divideIntegerMagnitudeBySmall($leftMagnitude, $rightInteger);
                $commonFactor = self::greatestCommonDivisor($rightInteger, $remainder);
                if ($commonFactor <= 1) {
                    return [$left, $right];
                }
                [$leftQuotient, $leftRemainder] = self::divideIntegerMagnitudeBySmall(
                    $leftMagnitude,
                    $commonFactor,
                );
                [$rightQuotient, $rightRemainder] = self::divideIntegerMagnitudeBySmall(
                    $rightMagnitude,
                    $commonFactor,
                );
            } else {
                $commonFactor = self::greatestCommonDivisorAsString($leftMagnitude, $rightMagnitude);
                if ($commonFactor === null || $commonFactor === '1') {
                    return [$left, $right];
                }
                $leftDivision = self::divideIntegerMagnitudes($leftMagnitude, $commonFactor);
                $rightDivision = self::divideIntegerMagnitudes($rightMagnitude, $commonFactor);
                if (
                    $leftDivision === null
                    || $rightDivision === null
                    || $leftDivision[1] !== '0'
                    || $rightDivision[1] !== '0'
                ) {
                    return [$left, $right];
                }
                [$leftQuotient, $rightQuotient] = [$leftDivision[0], $rightDivision[0]];
                $leftRemainder = 0;
                $rightRemainder = 0;
            }
            if ($leftRemainder !== 0 || $rightRemainder !== 0) {
                return [$left, $right];
            }
        }

        return [
            $leftNegative && $leftQuotient !== '0' ? self::negateString($leftQuotient) : $leftQuotient,
            $rightNegative && $rightQuotient !== '0' ? self::negateString($rightQuotient) : $rightQuotient,
        ];
    }

    private static function multiplyIntegerStrings(string $left, string $right): ?string
    {
        $negative = ($left[0] === '-') !== ($right[0] === '-');
        $left = \ltrim($left, '-');
        $right = \ltrim($right, '-');
        if ($left === '0' || $right === '0') {
            return '0';
        }
        if (\strlen($left) + \strlen($right) - 1 > self::MAX_STRING_INTEGER_DIGITS) {
            return null;
        }

        $digits = \array_fill(0, \strlen($left) + \strlen($right), 0);
        for ($i = \strlen($left) - 1; $i >= 0; --$i) {
            for ($j = \strlen($right) - 1; $j >= 0; --$j) {
                $position = $i + $j + 1;
                $product = ((int) $left[$i] * (int) $right[$j]) + $digits[$position];
                $digits[$position] = $product % 10;
                $digits[$position - 1] += \intdiv($product, 10);
            }
        }
        $magnitude = \ltrim(\implode('', $digits), '0');
        if (\strlen($magnitude) > self::MAX_STRING_INTEGER_DIGITS) {
            return null;
        }

        return $negative ? '-' . $magnitude : $magnitude;
    }

    private static function addIntegerMagnitudes(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = \strlen($left) - 1;
        $rightIndex = \strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $result = (string) ($sum % 10) . $result;
            $carry = \intdiv($sum, 10);
        }

        return $result;
    }

    private static function subtractIntegerMagnitudes(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $rightIndex = \strlen($right) - 1;
        for ($leftIndex = \strlen($left) - 1; $leftIndex >= 0; --$leftIndex) {
            $digit = (int) $left[$leftIndex] - $borrow
                - ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit) . $result;
        }

        $result = \ltrim($result, '0');

        return $result === '' ? '0' : $result;
    }

    private static function compareIntegerMagnitudes(string $left, string $right): int
    {
        return (\strlen($left) <=> \strlen($right))
            ?: (\strcmp($left, $right) <=> 0);
    }

    private static function factorsOfTwo(string $value): int
    {
        $value = \ltrim($value, '0');
        if ($value === '') {
            return 0;
        }
        $factors = 0;
        $chunkPower = \PHP_INT_SIZE >= 8 ? 50 : 26;
        $chunk = 1 << $chunkPower;
        while ($value !== '0') {
            $remainder = 0;
            for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
                $remainder = (($remainder * 10) + (int) $value[$index]) % $chunk;
            }
            $divisor = $chunk;
            $power = $chunkPower;
            if ($remainder !== 0) {
                $divisor = $remainder;
                $power = 0;
                while (($divisor % 2) === 0) {
                    $divisor = intdiv($divisor, 2);
                    ++$power;
                }
                if ($power === 0) {
                    break;
                }
                $divisor = 2 ** $power;
            }
            $carry = 0;
            $quotient = '';
            $length = \strlen($value);
            for ($index = 0; $index < $length; ++$index) {
                $partial = $carry * 10 + (int) $value[$index];
                $quotient .= (string) \intdiv($partial, $divisor);
                $carry = $partial % $divisor;
            }
            $value = \ltrim($quotient, '0');
            $factors += $power;
        }

        return $factors;
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private static function exactRootAsStrings(array $fraction, int $degree): ?array
    {
        $fraction = self::normalizeStringFraction($fraction[0], $fraction[1]);
        if ($fraction === null) {
            return null;
        }
        $numeratorNegative = $fraction[0][0] === '-';
        $numeratorMagnitude = \ltrim($fraction[0], '-');
        $numerator = \filter_var($fraction[0], FILTER_VALIDATE_INT);
        $denominator = \filter_var($fraction[1], FILTER_VALIDATE_INT);
        if ($fraction[1] === '0' || $fraction[1][0] === '-' || $degree < 2) {
            return null;
        }
        if ($numeratorNegative && ($degree % 2) === 0) {
            return null;
        }
        if (\is_int($numerator) && \is_int($denominator) && $denominator > 0 && $numerator !== \PHP_INT_MIN) {
            $rootNumerator = self::exactIntegerRoot(\abs($numerator), $degree);
            $rootDenominator = self::exactIntegerRoot($denominator, $degree);
            if ($rootNumerator === null || $rootDenominator === null) {
                return null;
            }
            if ($numeratorNegative) {
                $rootNumerator = -$rootNumerator;
            }
            $divisor = self::greatestCommonDivisor($rootNumerator, $rootDenominator);

            return [
                (string) \intdiv($rootNumerator, $divisor),
                (string) \intdiv($rootDenominator, $divisor),
            ];
        }
        $rootNumerator = match ($degree) {
            2 => self::exactIntegerSquareRootAsString($numeratorMagnitude),
            3 => self::exactIntegerCubeRootAsString($numeratorMagnitude),
            default => self::exactIntegerRootAsString($numeratorMagnitude, $degree),
        };
        $rootDenominator = match ($degree) {
            2 => self::exactIntegerSquareRootAsString($fraction[1]),
            3 => self::exactIntegerCubeRootAsString($fraction[1]),
            default => self::exactIntegerRootAsString($fraction[1], $degree),
        };
        if ($rootNumerator === null || $rootDenominator === null) {
            return null;
        }

        [$rootNumerator, $rootDenominator] = self::cancelCommonIntegerFactor(
            $numeratorNegative ? self::negateString($rootNumerator) : $rootNumerator,
            $rootDenominator,
        );

        return [$rootNumerator, $rootDenominator];
    }

    private static function exactIntegerSquareRootAsString(string $value): ?string
    {
        $value = \ltrim($value, '0');
        if ($value === '') {
            return '0';
        }

        $firstGroupLength = \strlen($value) % 2;
        if ($firstGroupLength === 0) {
            $firstGroupLength = 2;
        }
        $groups = [\substr($value, 0, $firstGroupLength)];
        for ($offset = $firstGroupLength; $offset < \strlen($value); $offset += 2) {
            $groups[] = \substr($value, $offset, 2);
        }

        $root = '0';
        $remainder = '0';
        $work = 0;
        foreach ($groups as $group) {
            $remainder = self::addIntegerMagnitudes(
                self::multiplyIntegerMagnitudeBySmall($remainder, 100),
                $group,
            );
            $shiftedRoot = self::multiplyIntegerMagnitudeBySmall($root, 20);
            $lowerDigit = 0;
            $upperDigit = 9;
            $bestDigit = 0;
            while ($lowerDigit <= $upperDigit) {
                $work += \strlen($remainder) + \strlen($shiftedRoot);
                if ($work > self::MAX_STRING_ROOT_DIGIT_WORK) {
                    return null;
                }
                $digit = $lowerDigit + intdiv($upperDigit - $lowerDigit, 2);
                $candidate = self::multiplyIntegerMagnitudeBySmall(
                    self::addIntegerMagnitudes($shiftedRoot, (string) $digit),
                    $digit,
                );
                if (self::compareIntegerMagnitudes($candidate, $remainder) <= 0) {
                    $bestDigit = $digit;
                    $lowerDigit = $digit + 1;
                } else {
                    $upperDigit = $digit - 1;
                }
            }

            $candidate = self::multiplyIntegerMagnitudeBySmall(
                self::addIntegerMagnitudes($shiftedRoot, (string) $bestDigit),
                $bestDigit,
            );
            $remainder = self::subtractIntegerMagnitudes($remainder, $candidate);
            $root = self::addIntegerMagnitudes(
                self::multiplyIntegerMagnitudeBySmall($root, 10),
                (string) $bestDigit,
            );
        }

        return $remainder === '0' ? $root : null;
    }

    private static function exactIntegerCubeRootAsString(string $value): ?string
    {
        $value = \ltrim($value, '0');
        if ($value === '') {
            return '0';
        }

        $firstGroupLength = \strlen($value) % 3;
        if ($firstGroupLength === 0) {
            $firstGroupLength = 3;
        }
        $groups = [\substr($value, 0, $firstGroupLength)];
        for ($offset = $firstGroupLength; $offset < \strlen($value); $offset += 3) {
            $groups[] = \substr($value, $offset, 3);
        }

        $root = '0';
        $remainder = '0';
        $work = 0;
        foreach ($groups as $group) {
            $remainder = self::addIntegerMagnitudes(
                self::multiplyIntegerMagnitudeBySmall($remainder, 1000),
                $group,
            );
            $base = self::multiplyIntegerMagnitudeBySmall($root, 10);
            $work += \strlen($base) * \strlen($base);
            if ($work > self::MAX_STRING_ROOT_DIGIT_WORK) {
                return null;
            }
            $baseSquared = self::multiplyIntegerStrings($base, $base);
            if ($baseSquared === null) {
                return null;
            }

            $lowerDigit = 0;
            $upperDigit = 9;
            $bestDigit = 0;
            while ($lowerDigit <= $upperDigit) {
                $digit = $lowerDigit + intdiv($upperDigit - $lowerDigit, 2);
                $increment = self::addIntegerMagnitudes(
                    self::addIntegerMagnitudes(
                        self::multiplyIntegerMagnitudeBySmall($baseSquared, 3 * $digit),
                        self::multiplyIntegerMagnitudeBySmall($base, 3 * $digit * $digit),
                    ),
                    (string) ($digit * $digit * $digit),
                );
                if (self::compareIntegerMagnitudes($increment, $remainder) <= 0) {
                    $bestDigit = $digit;
                    $lowerDigit = $digit + 1;
                } else {
                    $upperDigit = $digit - 1;
                }
            }

            $increment = self::addIntegerMagnitudes(
                self::addIntegerMagnitudes(
                    self::multiplyIntegerMagnitudeBySmall($baseSquared, 3 * $bestDigit),
                    self::multiplyIntegerMagnitudeBySmall($base, 3 * $bestDigit * $bestDigit),
                ),
                (string) ($bestDigit * $bestDigit * $bestDigit),
            );
            $remainder = self::subtractIntegerMagnitudes($remainder, $increment);
            $root = self::addIntegerMagnitudes($base, (string) $bestDigit);
        }

        return $remainder === '0' ? $root : null;
    }

    private static function exactIntegerRootAsString(string $value, int $degree): ?string
    {
        $value = \ltrim($value, '0');
        if ($value === '') {
            return '0';
        }
        if ($degree < 2) {
            return null;
        }

        $firstGroupLength = \strlen($value) % $degree;
        if ($firstGroupLength === 0) {
            $firstGroupLength = $degree;
        }
        $groups = [\substr($value, 0, $firstGroupLength)];
        for ($offset = $firstGroupLength; $offset < \strlen($value); $offset += $degree) {
            $groups[] = \substr($value, $offset, $degree);
        }

        $root = '0';
        $remainder = '0';
        $work = 0;
        foreach ($groups as $index => $group) {
            if ($index === 0) {
                $remainder = $group;
            } else {
                $remainder = \ltrim(
                    ($remainder === '0' ? '' : $remainder)
                        . \str_pad($group, $degree, '0', STR_PAD_LEFT),
                    '0',
                ) ?: '0';
            }
            $base = $root === '0' ? '0' : $root . '0';
            $basePower = self::integerPowerWithinRootWork($base, $degree, $work);
            if ($basePower === null) {
                return null;
            }

            $lowerDigit = 0;
            $upperDigit = 9;
            $bestDigit = 0;
            $bestIncrement = '0';
            while ($lowerDigit <= $upperDigit) {
                $digit = $lowerDigit + intdiv($upperDigit - $lowerDigit, 2);
                $candidate = self::addIntegerMagnitudes($base, (string) $digit);
                $candidatePower = self::integerPowerWithinRootWork($candidate, $degree, $work);
                if ($candidatePower === null) {
                    return null;
                }
                $increment = self::subtractIntegerMagnitudes($candidatePower, $basePower);
                if (self::compareIntegerMagnitudes($increment, $remainder) <= 0) {
                    $bestDigit = $digit;
                    $bestIncrement = $increment;
                    $lowerDigit = $digit + 1;
                } else {
                    $upperDigit = $digit - 1;
                }
            }

            $remainder = self::subtractIntegerMagnitudes($remainder, $bestIncrement);
            $root = self::addIntegerMagnitudes($base, (string) $bestDigit);
        }

        return $remainder === '0' ? $root : null;
    }

    private static function integerPowerWithinRootWork(
        string $base,
        int $power,
        int &$work,
    ): ?string {
        if ($base === '0') {
            return '0';
        }
        $result = '1';
        $factor = $base;
        while ($power > 0) {
            if (($power % 2) === 1) {
                $work += \strlen($result) * \strlen($factor);
                if ($work > self::MAX_STRING_ROOT_DIGIT_WORK) {
                    return null;
                }
                $result = self::multiplyIntegerStrings($result, $factor);
                if ($result === null) {
                    return null;
                }
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $work += \strlen($factor) * \strlen($factor);
                if ($work > self::MAX_STRING_ROOT_DIGIT_WORK) {
                    return null;
                }
                $factor = self::multiplyIntegerStrings($factor, $factor);
                if ($factor === null) {
                    return null;
                }
            }
        }

        return $result;
    }

    private static function multiplyIntegerMagnitudeBySmall(string $value, int $factor): string
    {
        $carry = 0;
        $result = '';
        for ($index = \strlen($value) - 1; $index >= 0; --$index) {
            $product = ((int) $value[$index] * $factor) + $carry;
            $result = (string) ($product % 10) . $result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return \ltrim($result, '0') ?: '0';
    }

    private static function exactIntegerRoot(int $value, int $degree): ?int
    {
        if ($value < 0 || $degree < 2) {
            return null;
        }
        if ($value < 2) {
            return $value;
        }

        $lower = 1;
        $upper = $value;
        while ($lower <= $upper) {
            $candidate = $lower + intdiv($upper - $lower, 2);
            $comparison = self::compareIntegerPower($candidate, $degree, $value);
            if ($comparison === 0) {
                return $candidate;
            }
            if ($comparison < 0) {
                $lower = $candidate + 1;
            } else {
                $upper = $candidate - 1;
            }
        }

        return null;
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private static function exactPowerAsStrings(array $fraction, int $power): ?array
    {
        if (
            $fraction[1] === '0'
            || $power === \PHP_INT_MIN
            || ($power < 0 && $fraction[0] === '0')
        ) {
            return null;
        }
        $fraction = self::normalizeStringFraction($fraction[0], $fraction[1]);
        if ($fraction === null) {
            return null;
        }
        if ($power < 0) {
            [$fraction[0], $fraction[1]] = [$fraction[1], $fraction[0]];
            $power = -$power;
        }

        $result = ['1', '1'];
        $factor = $fraction;
        while ($power > 0) {
            if (($power % 2) === 1) {
                $numerator = self::multiplyIntegerStrings($result[0], $factor[0]);
                $denominator = self::multiplyIntegerStrings($result[1], $factor[1]);
                if ($numerator === null || $denominator === null) {
                    return null;
                }
                $result = [$numerator, $denominator];
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $numerator = self::multiplyIntegerStrings($factor[0], $factor[0]);
                $denominator = self::multiplyIntegerStrings($factor[1], $factor[1]);
                if ($numerator === null || $denominator === null) {
                    return null;
                }
                $factor = [$numerator, $denominator];
            }
        }

        return self::normalizeStringFraction($result[0], $result[1]);
    }

    private static function compareIntegerPower(int $base, int $degree, int $limit): int
    {
        $power = 1;
        for ($index = 0; $index < $degree; ++$index) {
            if ($power > intdiv($limit, $base)) {
                return 1;
            }
            $power *= $base;
        }

        return $power <=> $limit;
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            $remainder = $left % $right;
            [$left, $right] = [$right, $remainder < 0 ? -$remainder : $remainder];
        }

        if ($left === \PHP_INT_MIN) {
            return 1;
        }

        return \abs($left) ?: 1;
    }

    /**
     * @param array{int, int} $left
     * @param array{int, int} $right
     * @return array{int, int}|null
     */
    private static function multiplyFractions(array $left, array $right): ?array
    {
        [$leftNumerator, $leftDenominator] = $left;
        [$rightNumerator, $rightDenominator] = $right;
        $leftReduction = self::greatestCommonDivisor($leftNumerator, $rightDenominator);
        $rightReduction = self::greatestCommonDivisor($rightNumerator, $leftDenominator);
        $leftNumerator = intdiv($leftNumerator, $leftReduction);
        $rightDenominator = intdiv($rightDenominator, $leftReduction);
        $rightNumerator = intdiv($rightNumerator, $rightReduction);
        $leftDenominator = intdiv($leftDenominator, $rightReduction);
        if (
            self::wouldMultiplyOverflow($leftNumerator, $rightNumerator)
            || self::wouldMultiplyOverflow($leftDenominator, $rightDenominator)
        ) {
            return null;
        }

        return [
            $leftNumerator * $rightNumerator,
            $leftDenominator * $rightDenominator,
        ];
    }

    private static function wouldMultiplyOverflow(int $left, int $right): bool
    {
        if ($left === 0 || $right === 0) {
            return false;
        }
        if ($left === \PHP_INT_MIN || $right === \PHP_INT_MIN) {
            return !($left === 1 || $right === 1);
        }

        return \abs($left) > intdiv(\PHP_INT_MAX, \abs($right));
    }
}
