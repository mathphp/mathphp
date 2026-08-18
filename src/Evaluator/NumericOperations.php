<?php

declare(strict_types=1);

namespace MathPHP\Evaluator;

use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Source\SourceSpan;

final readonly class NumericOperations
{
    public function __construct(
        private ResourceLimits $limits,
    ) {
    }

    public function positive(int|float $operand, SourceSpan $operatorSpan): int|float
    {
        return $this->ensureFinite($operand, $operatorSpan);
    }

    public function negate(int|float $operand, SourceSpan $operatorSpan): int|float
    {
        if (\is_int($operand)) {
            if ($operand === \PHP_INT_MIN) {
                $this->throwIntegerOverflow('Unary negation', $operatorSpan);
            }

            return -$operand;
        }

        return $this->normalizeFloat(-$operand, $operatorSpan);
    }

    public function add(
        int|float $left,
        int|float $right,
        SourceSpan $operatorSpan,
    ): int|float {
        $result = $left + $right;

        if (\is_int($left) && \is_int($right)) {
            if (!\is_int($result)) {
                $this->throwIntegerOverflow('Integer addition', $operatorSpan);
            }

            return $result;
        }

        return $this->normalizeFloat((float) $result, $operatorSpan);
    }

    public function subtract(
        int|float $left,
        int|float $right,
        SourceSpan $operatorSpan,
    ): int|float {
        $result = $left - $right;

        if (\is_int($left) && \is_int($right)) {
            if (!\is_int($result)) {
                $this->throwIntegerOverflow('Integer subtraction', $operatorSpan);
            }

            return $result;
        }

        return $this->normalizeFloat((float) $result, $operatorSpan);
    }

    public function multiply(
        int|float $left,
        int|float $right,
        SourceSpan $operatorSpan,
    ): int|float {
        $result = $left * $right;

        if (\is_int($left) && \is_int($right)) {
            if (!\is_int($result)) {
                $this->throwIntegerOverflow('Integer multiplication', $operatorSpan);
            }

            return $result;
        }

        return $this->normalizeFloat((float) $result, $operatorSpan);
    }

    public function divide(
        int|float $left,
        int|float $right,
        SourceSpan $operatorSpan,
    ): float {
        if ($right == 0.0) {
            throw new EvaluationException(
                'Division by zero',
                'eval.division_by_zero',
                $operatorSpan,
            );
        }

        return $this->normalizeFloat((float) ($left / $right), $operatorSpan);
    }

    public function modulo(
        int|float $left,
        int|float $right,
        SourceSpan $operatorSpan,
    ): int {
        $dividend = $this->toHostInteger($left, $operatorSpan, 'Modulo dividend');
        $divisor = $this->toHostInteger($right, $operatorSpan, 'Modulo divisor');

        if ($divisor === 0) {
            throw new EvaluationException(
                'Modulo by zero',
                'eval.modulo_by_zero',
                $operatorSpan,
            );
        }

        if ($dividend === \PHP_INT_MIN && $divisor === -1) {
            return 0;
        }

        return $dividend % $divisor;
    }

    public function power(
        int|float $base,
        int|float $exponent,
        SourceSpan $operatorSpan,
    ): int|float {
        if (\abs((float) $exponent) > $this->limits->maxExponentMagnitude) {
            throw new EvaluationException(
                'Exponent exceeds the configured magnitude limit',
                'limit.exponent',
                $operatorSpan,
            );
        }

        if ($base == 0.0 && $exponent < 0) {
            throw new EvaluationException(
                'Zero cannot be raised to a negative exponent',
                'eval.division_by_zero',
                $operatorSpan,
            );
        }

        if ($base < 0 && !$this->isMathematicalInteger($exponent)) {
            throw new EvaluationException(
                'A negative base requires an integer exponent',
                'eval.domain',
                $operatorSpan,
            );
        }

        $result = $base ** $exponent;

        if (\is_int($base) && \is_int($exponent) && $exponent >= 0) {
            if (!\is_int($result)) {
                $this->throwIntegerOverflow('Integer exponentiation', $operatorSpan);
            }

            return $result;
        }

        return $this->normalizeFloat((float) $result, $operatorSpan);
    }

    public function factorial(
        int|float $operand,
        SourceSpan $operatorSpan,
    ): int {
        $integer = $this->toHostInteger(
            $operand,
            $operatorSpan,
            'Factorial operand',
        );

        if ($integer < 0) {
            throw new EvaluationException(
                'Factorial requires a non-negative integer',
                'eval.domain',
                $operatorSpan,
            );
        }

        if ($integer > $this->limits->maxFactorial) {
            throw new EvaluationException(
                'Factorial operand exceeds the configured limit',
                'limit.factorial',
                $operatorSpan,
            );
        }

        $result = 1;

        for ($factor = 2; $factor <= $integer; ++$factor) {
            $result *= $factor;
        }

        return $result;
    }

    public function ensureFinite(
        int|float $value,
        SourceSpan $sourceSpan,
    ): int|float {
        if (\is_int($value)) {
            return $value;
        }

        return $this->normalizeFloat($value, $sourceSpan);
    }

    private function normalizeFloat(float $value, SourceSpan $sourceSpan): float
    {
        if (!\is_finite($value)) {
            throw new EvaluationException(
                'Operation produced a non-finite result',
                'eval.non_finite',
                $sourceSpan,
            );
        }

        return $value == 0.0 ? 0.0 : $value;
    }

    private function toHostInteger(
        int|float $value,
        SourceSpan $sourceSpan,
        string $subject,
    ): int {
        if (\is_int($value)) {
            return $value;
        }

        $magnitudeBits = \PHP_INT_SIZE * 8 - 1;
        $lowerBound = -(2.0 ** $magnitudeBits);
        $upperBound = 2.0 ** $magnitudeBits;

        if (
            \floor($value) !== $value
            || $value < $lowerBound
            || $value >= $upperBound
        ) {
            throw new EvaluationException(
                \sprintf('%s must be an integer within the host range', $subject),
                'eval.domain',
                $sourceSpan,
            );
        }

        return (int) $value;
    }

    private function isMathematicalInteger(int|float $value): bool
    {
        return \is_int($value) || \floor($value) === $value;
    }

    private function throwIntegerOverflow(string $operation, SourceSpan $span): never
    {
        throw new EvaluationException(
            $operation . ' exceeds the host integer range',
            'eval.integer_overflow',
            $span,
        );
    }
}
