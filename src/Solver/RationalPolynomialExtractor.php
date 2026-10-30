<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;

/** Exact bounded-rational polynomial extraction for numeric literals and parameters. */
final readonly class RationalPolynomialExtractor
{
    /**
     * @param array<string, int|float> $parameters
     */
    public function __construct(
        private string $variable,
        private array $parameters,
        private int $maxDegree = 256,
        private ?EvaluationOptions $evaluationOptions = null,
    ) {
    }

    /** @return list<string>|null Integer coefficients with denominators cleared. */
    public function extract(Node $node): ?array
    {
        return $this->integerCoefficients($this->polynomial($node));
    }

    /** @return list<string>|null Integer coefficients of left minus right. */
    public function difference(Node $left, Node $right): ?array
    {
        $leftPolynomial = $this->polynomial($left);
        $rightPolynomial = $this->polynomial($right);
        if ($leftPolynomial === null || $rightPolynomial === null) {
            return null;
        }

        return $this->integerCoefficients($this->combine($leftPolynomial, $rightPolynomial, true));
    }

    /**
     * @param list<array{int, int}>|null $polynomial
     * @return list<string>|null
     */
    private function integerCoefficients(?array $polynomial): ?array
    {
        if ($polynomial === null) {
            return null;
        }

        $commonDenominator = 1;
        foreach ($polynomial as [$numerator, $denominator]) {
            $divisor = $this->gcd($commonDenominator, $denominator);
            $factor = intdiv($denominator, $divisor);
            if (!$this->multiply($commonDenominator, $factor, $commonDenominator)) {
                return null;
            }
        }

        $integers = [];
        foreach ($polynomial as [$numerator, $denominator]) {
            $factor = intdiv($commonDenominator, $denominator);
            $integer = 0;
            if (!$this->multiply($numerator, $factor, $integer)) {
                return null;
            }
            $integers[] = (string) $integer;
        }

        return $integers;
    }

    /** @return list<array{int, int}>|null */
    private function polynomial(Node $node): ?array
    {
        if ($node instanceof NumberNode) {
            $fraction = RationalLiteral::fromNode($node);

            return $fraction === null ? null : [$fraction];
        }
        if ($node instanceof VariableNode) {
            if ($node->name === $this->variable) {
                return [[0, 1], [1, 1]];
            }
            $parameter = $this->parameters[$node->name] ?? null;
            if (\is_int($parameter)) {
                return [[$parameter, 1]];
            }
            $fraction = \is_float($parameter) ? RationalLiteral::fromFloat($parameter) : null;

            return $fraction === null ? null : [$fraction];
        }
        if ($node instanceof GroupingNode) {
            return $this->polynomial($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            $operand = $this->polynomial($node->operand);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }
            foreach ($operand as &$fraction) {
                if ($fraction[0] === \PHP_INT_MIN) {
                    return null;
                }
                $fraction[0] = -$fraction[0];
            }
            unset($fraction);

            return $operand;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->polynomial($node->left);
        $right = $this->polynomial($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        if (
            $node->operator === BinaryOperator::Power
            && \count($right) === 1
            && $right[0][1] === 1
            && $right[0][0] === 0
            && $this->containsVariable($node->left)
            && !$this->isDirectSignedVariable($node->left)
        ) {
            return null;
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->combine($left, $right, false),
            BinaryOperator::Subtract => $this->combine($left, $right, true),
            BinaryOperator::Multiply => $this->multiplyPolynomials($left, $right),
            BinaryOperator::Divide => $this->divide($left, $right),
            BinaryOperator::Power => $this->power($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /**
     * @param list<array{int, int}> $left
     * @param list<array{int, int}> $right
     * @return list<array{int, int}>|null
     */
    private function combine(array $left, array $right, bool $subtract): ?array
    {
        $length = \max(\count($left), \count($right));
        $result = \array_fill(0, $length, $this->zeroFraction());
        for ($index = 0; $index < $length; ++$index) {
            $a = $left[$index] ?? [0, 1];
            $b = $right[$index] ?? [0, 1];
            if ($subtract) {
                if ($b[0] === \PHP_INT_MIN) {
                    return null;
                }
                $b[0] = -$b[0];
            }
            $sum = $this->addFractions($a, $b);
            if ($sum === null) {
                return null;
            }
            $result[$index] = $sum;
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{int, int}> $left
     * @param list<array{int, int}> $right
     * @return list<array{int, int}>|null
     */
    private function multiplyPolynomials(array $left, array $right): ?array
    {
        $degree = \count($left) + \count($right) - 2;
        if ($degree > $this->maxDegree) {
            return null;
        }
        $result = \array_fill(0, $degree + 1, $this->zeroFraction());
        foreach ($left as $leftDegree => $leftFraction) {
            foreach ($right as $rightDegree => $rightFraction) {
                $product = $this->multiplyFractions($leftFraction, $rightFraction);
                if ($product === null) {
                    return null;
                }
                $sum = $this->addFractions($result[$leftDegree + $rightDegree], $product);
                if ($sum === null) {
                    return null;
                }
                $result[$leftDegree + $rightDegree] = $sum;
            }
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{int, int}> $numerator
     * @param list<array{int, int}> $denominator
     * @return list<array{int, int}>|null
     */
    private function divide(array $numerator, array $denominator): ?array
    {
        if (\count($denominator) !== 1 || $denominator[0][0] === 0) {
            return null;
        }
        [$divisor, $divisorDenominator] = $denominator[0];
        if ($divisor === \PHP_INT_MIN || $divisorDenominator < 1) {
            return null;
        }
        $result = \array_fill(0, \count($numerator), $this->zeroFraction());
        foreach ($numerator as $index => [$value, $valueDenominator]) {
            if ($value === \PHP_INT_MIN && $divisor < 0) {
                return null;
            }
            $fraction = $this->normalizeFraction(
                $value * ($divisor < 0 ? -1 : 1),
                $valueDenominator,
                \abs($divisor),
            );
            $newDenominator = 0;
            if ($fraction === null || !$this->multiply($fraction[1], $divisorDenominator, $newDenominator)) {
                return null;
            }
            $fraction[1] = $newDenominator;
            $reduced = $this->reduce($fraction);
            if ($reduced === null) {
                return null;
            }
            $result[$index] = $reduced;
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{int, int}> $base
     * @param list<array{int, int}> $exponent
     * @return list<array{int, int}>|null
     */
    private function power(array $base, array $exponent): ?array
    {
        if (\count($exponent) !== 1 || $exponent[0][1] !== 1 || $exponent[0][0] < 0) {
            return null;
        }
        $power = $exponent[0][0];
        if (
            $power > $this->maxDegree
            || ($this->evaluationOptions !== null
                && $power > $this->evaluationOptions->limits->maxExponentMagnitude)
            || ($power > 1 && (\count($base) - 1) * $power > $this->maxDegree)
        ) {
            return null;
        }
        $result = [[1, 1]];
        $factor = $base;
        while ($power > 0) {
            if (($power & 1) === 1) {
                $result = $this->multiplyPolynomials($result, $factor);
                if ($result === null) {
                    return null;
                }
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $factor = $this->multiplyPolynomials($factor, $factor);
                if ($factor === null) {
                    return null;
                }
            }
        }

        return $result;
    }

    /**
     * @param array{int, int} $left
     * @param array{int, int} $right
     * @return array{int, int}|null
     */
    private function addFractions(array $left, array $right): ?array
    {
        $divisor = $this->gcd($left[1], $right[1]);
        $leftFactor = intdiv($right[1], $divisor);
        $rightFactor = intdiv($left[1], $divisor);
        $a = 0;
        $b = 0;
        $denominator = 0;
        if (
            !$this->multiply($left[0], $leftFactor, $a)
            || !$this->multiply($right[0], $rightFactor, $b)
        ) {
            return null;
        }
        if (
            ($b > 0 && $a > \PHP_INT_MAX - $b)
            || ($b < 0 && $a < \PHP_INT_MIN - $b)
            || !$this->multiply($left[1], $leftFactor, $denominator)
        ) {
            return null;
        }
        $numerator = $a + $b;

        return $this->normalizeFraction($numerator, $denominator);
    }

    /**
     * @param array{int, int} $left
     * @param array{int, int} $right
     * @return array{int, int}|null
     */
    private function multiplyFractions(array $left, array $right): ?array
    {
        if (
            $left[1] <= 0 || $right[1] <= 0
            || $left[0] === \PHP_INT_MIN || $right[0] === \PHP_INT_MIN
        ) {
            return null;
        }
        $a = $this->gcd(\abs($left[0]), $right[1]);
        $b = $this->gcd(\abs($right[0]), $left[1]);
        $leftNumerator = intdiv($left[0], $a);
        $rightNumerator = intdiv($right[0], $b);
        $leftDenominator = intdiv($left[1], $b);
        $rightDenominator = intdiv($right[1], $a);
        $numerator = 0;
        $denominator = 0;
        if (
            !$this->multiply($leftNumerator, $rightNumerator, $numerator)
            || !$this->multiply($leftDenominator, $rightDenominator, $denominator)
        ) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /**
     * @param array{int, int} $fraction
     * @return array{int, int}|null
     */
    private function reduce(array $fraction): ?array
    {
        if ($fraction[0] === \PHP_INT_MIN || $fraction[1] <= 0) {
            return null;
        }
        $divisor = $this->gcd(\abs($fraction[0]), $fraction[1]);

        return [intdiv($fraction[0], $divisor), intdiv($fraction[1], $divisor)];
    }

    /** @return array{int, int}|null */
    private function normalizeFraction(int $numerator, int $denominator, int $extra = 1): ?array
    {
        $scaledDenominator = 0;
        if (
            $numerator === \PHP_INT_MIN
            || $denominator <= 0
            || $extra <= 0
            || !$this->multiply($denominator, $extra, $scaledDenominator)
        ) {
            return null;
        }
        $divisor = $this->gcd(\abs($numerator), $scaledDenominator);

        return [intdiv($numerator, $divisor), intdiv($scaledDenominator, $divisor)];
    }

    private function multiply(int $left, int $right, int &$result): bool
    {
        if ($left === 0 || $right === 0) {
            $result = 0;
            return true;
        }
        if (
            $left === \PHP_INT_MIN
            || $right === \PHP_INT_MIN
            || \abs($left) > intdiv(\PHP_INT_MAX, \abs($right))
        ) {
            $result = 0;
            return false;
        }
        $result = $left * $right;

        return true;
    }

    private function gcd(int $left, int $right): int
    {
        $left = \abs($left);
        $right = \abs($right);
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left === 0 ? 1 : $left;
    }

    /**
     * @param list<array{int, int}> $polynomial
     * @return list<array{int, int}>
     */
    private function trim(array $polynomial): array
    {
        while (\count($polynomial) > 1 && $polynomial[\array_key_last($polynomial)][0] === 0) {
            \array_pop($polynomial);
        }

        return $polynomial;
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
            return $this->containsVariable($node->left) || $this->containsVariable($node->right);
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

    /** @return array{int, int} */
    private function zeroFraction(): array
    {
        return [0, 1];
    }
}
