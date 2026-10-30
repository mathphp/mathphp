<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;

/** Exact bounded polynomial extraction using signed decimal integers. */
final readonly class IntegerPolynomialExtractor
{
    /**
     * @param array<string, int|float> $parameters
     */
    public function __construct(
        private string $variable,
        private array $parameters,
        private int $maxDegree = 256,
    ) {
    }

    /** @return list<string>|null Coefficients in ascending degree order. */
    public function extract(Node $node): ?array
    {
        if ($node instanceof NumberNode) {
            return \is_int($node->value) ? [(string) $node->value] : null;
        }

        if ($node instanceof VariableNode) {
            if ($node->name === $this->variable) {
                return ['0', '1'];
            }

            $parameter = $this->parameters[$node->name] ?? null;

            return \is_int($parameter) ? [(string) $parameter] : null;
        }

        if ($node instanceof GroupingNode) {
            return $this->extract($node->expression);
        }

        if ($node instanceof UnaryOperationNode) {
            $operand = $this->extract($node->operand);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            foreach ($operand as $index => $coefficient) {
                $operand[$index] = $this->negate($coefficient);
            }

            return $operand;
        }

        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->extract($node->left);
        $right = $this->extract($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        if (
            $node->operator === BinaryOperator::Power
            && $this->containsVariable($node->left)
            && \count($right) === 1
            && $right[0] === '0'
            && !$this->isDirectSignedVariable($node->left)
        ) {
            return null;
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->combine($left, $right, false),
            BinaryOperator::Subtract => $this->combine($left, $right, true),
            BinaryOperator::Multiply => $this->multiply($left, $right),
            BinaryOperator::Divide => $this->divide($left, $right),
            BinaryOperator::Power => $this->power($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /** Convert a ratio of exact integer coefficients to the nearest float. */
    public function ratio(string $numerator, string $denominator): float|false|null
    {
        if ($denominator === '0') {
            return null;
        }

        $negative = ($numerator[0] === '-') !== ($denominator[0] === '-');
        $numerator = \ltrim($numerator, '-');
        $denominator = \ltrim($denominator, '-');
        if ($numerator === '0') {
            return 0.0;
        }
        if ($denominator === '1') {
            $integer = (float) $numerator;
            if (!\is_finite($integer)) {
                return null;
            }

            return $negative ? -$integer : $integer;
        }

        $comparison = $this->compareMagnitudes($numerator, $denominator);
        if ($comparison === 0) {
            return $negative ? -1.0 : 1.0;
        }
        $exactQuotient = $this->divideIntegerValueExactly($numerator, $denominator);
        if ($exactQuotient !== null) {
            $integer = (float) $exactQuotient;
            if (!\is_finite($integer)) {
                return null;
            }

            return $negative ? -$integer : $integer;
        }
        $difference = $comparison > 0
            ? $this->subtractMagnitudes($numerator, $denominator)
            : $this->subtractMagnitudes($denominator, $numerator);
        if (\strlen($difference) <= \strlen($denominator) - 2) {
            $relativeDifference = $this->ratio($difference, $denominator);
            if (\is_float($relativeDifference)) {
                $magnitude = $comparison > 0
                    ? 1.0 + $relativeDifference
                    : 1.0 - $relativeDifference;

                return $negative ? -$magnitude : $magnitude;
            }
        }

        $numeratorDigits = \substr($numerator, 0, 16);
        $denominatorDigits = \substr($denominator, 0, 16);
        $numeratorMantissa = (float) $numeratorDigits / (10.0 ** (\strlen($numeratorDigits) - 1));
        $denominatorMantissa = (float) $denominatorDigits / (10.0 ** (\strlen($denominatorDigits) - 1));
        $exponent = \strlen($numerator) - \strlen($denominator);
        $mantissa = $numeratorMantissa / $denominatorMantissa;
        if ($exponent === 309) {
            $magnitude = ($mantissa * 10.0) * (10.0 ** 308);
        } elseif ($exponent > 309) {
            return null;
        } elseif ($exponent === -324) {
            $magnitude = ($mantissa / 10.0) * (10.0 ** -323);
        } elseif ($exponent < -324) {
            return false;
        } else {
            $magnitude = $mantissa * (10.0 ** $exponent);
        }
        if (!\is_finite($magnitude)) {
            return null;
        }
        if ($magnitude === 0.0) {
            return false;
        }

        return $negative ? -$magnitude : $magnitude;
    }

    /** Return the sign of an exact integer without converting it to a float. */
    public function sign(string $value): int
    {
        return $value === '0' ? 0 : ($value[0] === '-' ? -1 : 1);
    }

    /** Compare exact integer multiples with an exact rational bound. */
    public function compareIntegerRatioToFraction(
        string $numerator,
        string $denominator,
        string $boundNumerator,
        string $boundDenominator,
    ): ?int {
        if ($denominator === '0' || $boundDenominator === '0') {
            return null;
        }
        if (($denominator[0] === '-') !== ($boundDenominator[0] === '-')) {
            $numerator = $this->negate($numerator);
            $denominator = $this->negate($denominator);
        }
        if ($boundDenominator[0] === '-') {
            $boundNumerator = $this->negate($boundNumerator);
            $boundDenominator = \substr($boundDenominator, 1);
        }
        $left = $this->multiplyIntegers($numerator, $boundDenominator);
        $right = $this->multiplyIntegers($denominator, $boundNumerator);

        return $this->compareIntegerValues($left, $right);
    }

    public function compareIntegerValues(string $left, string $right): int
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $comparison = $this->compareMagnitudes(\ltrim($left, '-'), \ltrim($right, '-'));

        return $leftNegative ? -$comparison : $comparison;
    }

    public function subtract(string $left, string $right): string
    {
        return $this->add($left, $this->negate($right));
    }

    public function multiplyIntegerValues(string $left, string $right): string
    {
        return $this->multiplyIntegers($left, $right);
    }

    public function addIntegerValues(string $left, string $right): string
    {
        return $this->add($left, $right);
    }

    public function negateIntegerValue(string $value): string
    {
        return $this->negate($value);
    }

    public function divideIntegerValueExactly(string $numerator, string $denominator): ?string
    {
        if ($denominator === '0') {
            return null;
        }
        [$quotient, $remainder] = $this->divideIntegers($numerator, $denominator);

        return $remainder === '0' ? $quotient : null;
    }

    public function divideNonnegativeIntegerValuesFloor(string $numerator, string $denominator): string
    {
        if ($this->sign($numerator) < 0 || $this->sign($denominator) <= 0) {
            throw new \InvalidArgumentException(
                'Floor division requires a nonnegative numerator and positive denominator.',
            );
        }

        return $this->divideIntegers($numerator, $denominator)[0];
    }

    public function greatestCommonDivisorValues(string $left, string $right): string
    {
        $left = \ltrim($left, '-');
        $right = \ltrim($right, '-');
        while ($right !== '0') {
            [, $remainder] = $this->divideIntegers($left, $right);
            $left = $right;
            $right = $remainder;
        }

        return $left;
    }

    public function integerSquareRoot(string $value): ?string
    {
        if ($value[0] === '-') {
            return null;
        }
        $digits = \ltrim($value, '0');
        if ($digits === '') {
            return '0';
        }

        $firstGroupLength = \strlen($digits) % 2;
        if ($firstGroupLength === 0) {
            $firstGroupLength = 2;
        }
        $groups = [\substr($digits, 0, $firstGroupLength)];
        for ($offset = $firstGroupLength; $offset < \strlen($digits); $offset += 2) {
            $groups[] = \substr($digits, $offset, 2);
        }

        $root = '0';
        $remainder = '0';
        foreach ($groups as $group) {
            $remainder = $this->add(
                $this->multiplyIntegers($remainder, '100'),
                $group,
            );
            for ($digit = 9; $digit >= 0; --$digit) {
                $candidate = $this->multiplyIntegers(
                    $this->add(
                        $this->multiplyIntegers($root, '20'),
                        (string) $digit,
                    ),
                    (string) $digit,
                );
                if ($this->compareMagnitudes($candidate, $remainder) <= 0) {
                    $remainder = $this->subtractMagnitudes($remainder, $candidate);
                    $root = $this->add(
                        $this->multiplyIntegers($root, '10'),
                        (string) $digit,
                    );
                    break;
                }
            }
        }

        return $root;
    }

    public function integerNthRoot(string $value, int $degree): ?string
    {
        $floor = $this->integerNthRootFloor($value, $degree);
        if ($floor === null || $this->compareIntegerPowerTo($floor, $degree, $value) !== 0) {
            return null;
        }

        return $floor;
    }

    public function integerNthRootFloor(string $value, int $degree): ?string
    {
        if (
            preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > 4096
            || $degree < 1
            || $degree > 4096
        ) {
            return null;
        }
        if ($degree === 1 || $value === '0' || $value === '1') {
            return $value;
        }
        if ($degree === 2) {
            return $this->integerSquareRoot($value);
        }

        $lower = '0';
        $upper = '1' . str_repeat('0', intdiv(strlen($value) + $degree - 1, $degree));
        while ($this->subtractMagnitudes($upper, $lower) !== '1') {
            [$midpoint] = $this->divideIntegers($this->addMagnitudes($lower, $upper), '2');
            $comparison = $this->compareIntegerPowerTo($midpoint, $degree, $value);
            if ($comparison < 0) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return $this->compareIntegerPowerTo($upper, $degree, $value) === 0 ? $upper : $lower;
    }

    public function isZero(string $value): bool
    {
        return $value === '0';
    }

    /** @param list<string> $coefficients
     *  @return list<float>|null
     */
    public function normalizedCoefficients(array $coefficients, bool $allowUnderflow = false): ?array
    {
        $nativeCoefficients = [];
        $fitsNativeFloat = true;
        foreach ($coefficients as $coefficient) {
            $nativeCoefficient = (float) $coefficient;
            if (!\is_finite($nativeCoefficient)) {
                $fitsNativeFloat = false;
                break;
            }
            $nativeCoefficients[] = $nativeCoefficient;
        }
        if ($fitsNativeFloat) {
            return $nativeCoefficients;
        }

        $maximumDigits = 0;
        foreach ($coefficients as $coefficient) {
            $maximumDigits = \max($maximumDigits, \strlen(\ltrim($coefficient, '-')));
        }
        if ($maximumDigits === 0) {
            return null;
        }

        $normalized = [];
        foreach ($coefficients as $coefficient) {
            if ($coefficient === '0') {
                $normalized[] = 0.0;
                continue;
            }
            $negative = $coefficient[0] === '-';
            $digits = \ltrim($coefficient, '-');
            $prefix = \substr($digits, 0, 16);
            $mantissa = (float) $prefix / (10.0 ** (\strlen($prefix) - 1));
            $exponent = \strlen($digits) - $maximumDigits;
            $value = $mantissa * (10.0 ** $exponent);
            if (!\is_finite($value) || ($value === 0.0 && !$allowUnderflow)) {
                return null;
            }
            $normalized[] = $negative ? -$value : $value;
        }

        return $normalized;
    }

    /** @param list<string> $left
     *  @param list<string> $right
     *  @return list<string>
     */
    private function combine(array $left, array $right, bool $subtract): array
    {
        $length = \max(\count($left), \count($right));
        $result = [];
        for ($index = 0; $index < $length; ++$index) {
            $rightCoefficient = $right[$index] ?? '0';
            $result[] = $this->add(
                $left[$index] ?? '0',
                $subtract ? $this->negate($rightCoefficient) : $rightCoefficient,
            );
        }

        return $this->trim($result);
    }

    /** @param list<string> $left
     *  @param list<string> $right
     *  @return list<string>|null
     */
    private function multiply(array $left, array $right): ?array
    {
        $result = \array_fill(
            0,
            \min($this->maxDegree, \count($left) + \count($right) - 2) + 1,
            '0',
        );
        foreach ($left as $leftDegree => $leftCoefficient) {
            foreach ($right as $rightDegree => $rightCoefficient) {
                $degree = $leftDegree + $rightDegree;
                $product = $this->multiplyIntegers($leftCoefficient, $rightCoefficient);
                if ($degree > $this->maxDegree && $product !== '0') {
                    return null;
                }
                if ($degree <= $this->maxDegree) {
                    $result[$degree] = $this->add($result[$degree] ?? '0', $product);
                }
            }
        }

        return $this->trim([...$result]);
    }

    /** @param list<string> $numerator
     *  @param list<string> $denominator
     *  @return list<string>|null
     */
    private function divide(array $numerator, array $denominator): ?array
    {
        if (\count($denominator) !== 1 || $denominator[0] === '0') {
            return null;
        }

        $result = [];
        foreach ($numerator as $coefficient) {
            [$quotient, $remainder] = $this->divideIntegers($coefficient, $denominator[0]);
            if ($remainder !== '0') {
                return null;
            }
            $result[] = $quotient;
        }

        return $this->trim($result);
    }

    /** @param list<string> $base
     *  @param list<string> $exponent
     *  @return list<string>|null
     */
    private function power(array $base, array $exponent): ?array
    {
        if (\count($exponent) !== 1 || $exponent[0][0] === '-') {
            return null;
        }
        if (\strlen($exponent[0]) > 3 || (int) $exponent[0] > $this->maxDegree) {
            return null;
        }
        $power = (int) $exponent[0];
        if ($power === 0) {
            return ['1'];
        }

        $result = ['1'];
        $factor = $base;
        while ($power > 0) {
            if (($power & 1) === 1) {
                $result = $this->multiply($result, $factor);
                if ($result === null) {
                    return null;
                }
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $factor = $this->multiply($factor, $factor);
                if ($factor === null) {
                    return null;
                }
            }
        }

        return $result;
    }

    private function containsVariable(Node $node): bool
    {
        if ($node instanceof VariableNode) {
            return $node->name === $this->variable;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsVariable($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsVariable($node->operand);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariable($node->left)
                || $this->containsVariable($node->right);
        }

        return false;
    }

    private function isDirectSignedVariable(Node $node): bool
    {
        while ($node instanceof GroupingNode || $node instanceof UnaryOperationNode) {
            $node = $node instanceof GroupingNode ? $node->expression : $node->operand;
        }

        return $node instanceof VariableNode && $node->name === $this->variable;
    }

    /** @param list<string> $coefficients
     *  @return list<string>
     */
    private function trim(array $coefficients): array
    {
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === '0') {
            \array_pop($coefficients);
        }

        return $coefficients;
    }

    private function negate(string $value): string
    {
        return $value === '0' ? '0' : ($value[0] === '-' ? \substr($value, 1) : '-' . $value);
    }

    private function add(string $left, string $right): string
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        $leftMagnitude = \ltrim($left, '-');
        $rightMagnitude = \ltrim($right, '-');
        if ($leftNegative === $rightNegative) {
            $magnitude = $this->addMagnitudes($leftMagnitude, $rightMagnitude);

            return $leftNegative && $magnitude !== '0' ? '-' . $magnitude : $magnitude;
        }

        $comparison = $this->compareMagnitudes($leftMagnitude, $rightMagnitude);
        if ($comparison === 0) {
            return '0';
        }
        $magnitude = $comparison > 0
            ? $this->subtractMagnitudes($leftMagnitude, $rightMagnitude)
            : $this->subtractMagnitudes($rightMagnitude, $leftMagnitude);
        $negative = $comparison > 0 ? $leftNegative : $rightNegative;

        return $negative ? '-' . $magnitude : $magnitude;
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        $negative = ($left[0] === '-') !== ($right[0] === '-');
        $left = \ltrim($left, '-');
        $right = \ltrim($right, '-');
        if ($left === '0' || $right === '0') {
            return '0';
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

        return $negative ? '-' . $magnitude : $magnitude;
    }

    private function compareIntegerPowerTo(string $base, int $exponent, string $limit): int
    {
        $power = '1';
        while ($exponent > 0) {
            if ($exponent % 2 === 1) {
                $power = $this->multiplyIntegers($power, $base);
                if ($this->compareMagnitudes($power, $limit) > 0) {
                    return 1;
                }
            }
            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                $base = $this->multiplyIntegers($base, $base);
                if ($this->compareMagnitudes($base, $limit) > 0) {
                    return 1;
                }
            }
        }

        return $this->compareMagnitudes($power, $limit);
    }

    /** @return array{string, string} quotient and remainder */
    private function divideIntegers(string $numerator, string $denominator): array
    {
        $negative = ($numerator[0] === '-') !== ($denominator[0] === '-');
        $remainderNegative = $numerator[0] === '-';
        $numerator = \ltrim($numerator, '-');
        $denominator = \ltrim($denominator, '-');
        $quotient = '';
        $remainder = '0';
        for ($index = 0; $index < \strlen($numerator); ++$index) {
            $remainder = \ltrim(($remainder === '0' ? '' : $remainder) . $numerator[$index], '0');
            if ($remainder === '') {
                $remainder = '0';
            }
            $digit = 0;
            while ($this->compareMagnitudes($remainder, $denominator) >= 0) {
                $remainder = $this->subtractMagnitudes($remainder, $denominator);
                ++$digit;
            }
            $quotient .= (string) $digit;
        }
        $quotient = \ltrim($quotient, '0');
        $quotient = $quotient === '' ? '0' : $quotient;
        $remainder = $remainder === '0' || !$remainderNegative ? $remainder : '-' . $remainder;
        if ($negative && $quotient !== '0') {
            $quotient = '-' . $quotient;
        }

        return [$quotient, $remainder];
    }

    private function addMagnitudes(string $left, string $right): string
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

    private function subtractMagnitudes(string $left, string $right): string
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

    private function compareMagnitudes(string $left, string $right): int
    {
        return (\strlen($left) <=> \strlen($right))
            ?: (\strcmp($left, $right) <=> 0);
    }
}
