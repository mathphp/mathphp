<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;

final readonly class PolynomialExtractor
{
    /**
     * @param array<string, int|float> $parameters
     */
    public function __construct(
        private string $variable,
        private array $parameters,
        private EvaluationOptions $options,
        private int $maxDegree = 256,
    ) {
    }

    /**
     * Coefficients are returned in ascending degree order, up to maxDegree.
     *
     * @return list<float>|null
     */
    public function extract(Node $node): ?array
    {
        if (!$this->containsVariable($node)) {
            return [(float) (new Evaluator(
                new Environment($this->parameters, $this->options->functions),
                $this->options->limits,
            ))->evaluate($node)];
        }

        if ($node instanceof NumberNode) {
            return [(float) $node->value];
        }

        if ($node instanceof VariableNode) {
            return [0.0, 1.0];
        }

        if ($node instanceof GroupingNode) {
            return $this->extract($node->expression);
        }

        if ($node instanceof UnaryOperationNode) {
            $operand = $this->extract($node->operand);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            return $this->scale($operand, -1.0);
        }

        if ($node instanceof FunctionCallNode && $this->isImplicitVariableProduct($node)) {
            $factor = $this->implicitProductFactor($node);
            $argument = $this->extract($node->arguments[0]);

            return $factor === null || $argument === null
                ? null
                : $this->multiply($factor, $argument);
        }

        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->extract($node->left);
        $right = $this->extract($node->right);
        if ($left === null || $right === null) {
            return null;
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->combine($left, $right, 1.0),
            BinaryOperator::Subtract => $this->combine($left, $right, -1.0),
            BinaryOperator::Multiply => $this->multiply($left, $right),
            BinaryOperator::Divide => $this->divide($left, $right),
            BinaryOperator::Power => $this->power($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /**
     * Extract polynomial coefficients after substituting x = center + scale*t.
     * This avoids expanding translated powers in the original coordinate system.
     *
     * @return list<float>|null
     */
    public function extractInAffineCoordinate(Node $node, float $center, float $scale): ?array
    {
        if (!\is_finite($center) || !\is_finite($scale)) {
            return null;
        }

        return $this->extractWithVariablePolynomial($node, [$center, $scale]);
    }

    /**
     * @param list<float> $variablePolynomial
     * @return list<float>|null
     */
    private function extractWithVariablePolynomial(Node $node, array $variablePolynomial): ?array
    {
        if (!$this->containsVariable($node)) {
            return [(float) (new Evaluator(
                new Environment($this->parameters, $this->options->functions),
                $this->options->limits,
            ))->evaluate($node)];
        }

        if ($node instanceof VariableNode) {
            return $variablePolynomial;
        }

        if ($node instanceof GroupingNode) {
            return $this->extractWithVariablePolynomial($node->expression, $variablePolynomial);
        }

        if ($node instanceof UnaryOperationNode) {
            $operand = $this->extractWithVariablePolynomial($node->operand, $variablePolynomial);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            return $this->scale($operand, -1.0);
        }

        if ($node instanceof FunctionCallNode && $this->isImplicitVariableProduct($node)) {
            $factor = $this->implicitProductFactor($node, $variablePolynomial);
            $argument = $this->extractWithVariablePolynomial(
                $node->arguments[0],
                $variablePolynomial,
            );

            return $factor === null || $argument === null
                ? null
                : $this->multiply($factor, $argument);
        }

        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->extractWithVariablePolynomial($node->left, $variablePolynomial);
        $right = $this->extractWithVariablePolynomial($node->right, $variablePolynomial);
        if ($left === null || $right === null) {
            return null;
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->combine($left, $right, 1.0),
            BinaryOperator::Subtract => $this->combine($left, $right, -1.0),
            BinaryOperator::Multiply => $this->multiply($left, $right),
            BinaryOperator::Divide => $this->divide($left, $right),
            BinaryOperator::Power => $this->power($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /**
     * Extract the numerator of a rational difference, with the shared
     * denominator removed. Coefficients are in ascending degree order.
     *
     * @return list<float>|null
     */
    public function extractRationalDifference(Node $left, Node $right): ?array
    {
        $form = $this->extractRationalDifferenceForm($left, $right);

        return $form === null ? null : $form[0];
    }

    /**
     * @return array{list<string>, list<string>}|null Exact numerator and denominator.
     */
    public function extractExactRationalDifference(Node $left, Node $right): ?array
    {
        return (new ExactRationalPolynomialExtractor(
            $this->variable,
            $this->parameters,
            $this->maxDegree,
            $this->options,
        ))->rationalDifference($left, $right);
    }

    /**
     * Extract the numerator and denominator of a rational difference.
     * Coefficients are in ascending degree order.
     *
     * @return array{list<float>, list<float>}|null
     */
    public function extractRationalDifferenceForm(Node $left, Node $right): ?array
    {
        $leftRational = $this->extractRational($left);
        $rightRational = $this->extractRational($right);
        if ($leftRational === null || $rightRational === null) {
            return null;
        }
        $rightDenominatorScale = $this->proportionalScale(
            $leftRational[1],
            $rightRational[1],
        );
        if ($rightDenominatorScale !== null) {
            $scaledLeftNumerator = $this->scale(
                $leftRational[0],
                $rightDenominatorScale,
            );
            $numerator = $scaledLeftNumerator === null
                ? null
                : $this->combine($scaledLeftNumerator, $rightRational[0], -1.0);

            return $numerator === null
                ? null
                : [$numerator, $rightRational[1]];
        }
        $leftProduct = $this->multiply($leftRational[0], $rightRational[1]);
        $rightProduct = $this->multiply($rightRational[0], $leftRational[1]);
        $denominator = $this->multiply($leftRational[1], $rightRational[1]);
        if ($leftProduct === null || $rightProduct === null || $denominator === null) {
            return null;
        }
        $numerator = $this->combine($leftProduct, $rightProduct, -1.0);
        if ($numerator === null) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /** @return array{list<float>, list<float>}|null */
    public function extractRationalForm(Node $node): ?array
    {
        return $this->extractRational($node);
    }

    /**
     * @return array{list<float>, list<float>}|null
     */
    private function extractRational(Node $node): ?array
    {
        if (!$this->containsVariable($node)) {
            $polynomial = $this->extract($node);

            return $polynomial === null ? null : [$polynomial, [1.0]];
        }
        if ($node instanceof GroupingNode) {
            return $this->extractRational($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            $rational = $this->extractRational($node->operand);
            if ($rational === null || $node->operator === UnaryOperator::Plus) {
                return $rational;
            }
            $numerator = $this->scale($rational[0], -1.0);

            return $numerator === null ? null : [$numerator, $rational[1]];
        }
        if ($node instanceof FunctionCallNode && $this->isImplicitVariableProduct($node)) {
            $factor = $this->implicitProductFactor($node);
            $argument = $this->extractRational($node->arguments[0]);
            if ($factor === null || $argument === null) {
                return null;
            }
            $numerator = $this->multiply($factor, $argument[0]);

            return $numerator === null ? null : [$numerator, $argument[1]];
        }
        if (!$node instanceof BinaryOperationNode) {
            $polynomial = $this->extract($node);

            return $polynomial === null ? null : [$polynomial, [1.0]];
        }
        if ($node->operator === BinaryOperator::Modulo) {
            return null;
        }

        if ($node->operator === BinaryOperator::Power) {
            if ($this->containsVariable($node->right)) {
                return null;
            }
            $exponent = $this->extract($node->right);
            $base = $this->extractRational($node->left);
            if (
                $exponent === null
                || \count($exponent) !== 1
                || \floor($exponent[0]) !== $exponent[0]
                || \abs($exponent[0]) > $this->maxDegree
                || \abs($exponent[0]) > $this->options->limits->maxExponentMagnitude
                || $base === null
            ) {
                return null;
            }
            $power = (int) $exponent[0];
            if ($power === 0) {
                return [$base[1], $base[1]];
            }
            if ($power < 0) {
                if ($base[0] === [0.0]) {
                    return null;
                }
                [$numerator, $denominator] = [$base[1], $base[0]];
                $power = -$power;
            } else {
                [$numerator, $denominator] = $base;
            }

            $poweredNumerator = $this->polynomialPower($numerator, $power);
            $poweredDenominator = $this->polynomialPower($denominator, $power);
            if ($poweredNumerator === null || $poweredDenominator === null) {
                return null;
            }

            return [$poweredNumerator, $poweredDenominator];
        }

        $left = $this->extractRational($node->left);
        $right = $this->extractRational($node->right);
        if ($left === null || $right === null) {
            return null;
        }

        if ($node->operator === BinaryOperator::Add || $node->operator === BinaryOperator::Subtract) {
            return $this->addRationals(
                $left,
                $right,
                $node->operator === BinaryOperator::Add ? 1.0 : -1.0,
            );
        }
        if ($node->operator === BinaryOperator::Multiply) {
            return $this->multiplyRationals($left, $right);
        }

        return $this->divideRationals($left, $right);
    }

    /**
     * @param array{list<float>, list<float>} $left
     * @param array{list<float>, list<float>} $right
     * @return array{list<float>, list<float>}|null
     */
    private function addRationals(array $left, array $right, float $rightScale): ?array
    {
        $rightDenominatorScale = $this->proportionalScale($left[1], $right[1]);
        if ($rightDenominatorScale !== null) {
            $scaledLeftNumerator = $this->scale($left[0], $rightDenominatorScale);
            if ($scaledLeftNumerator === null) {
                return null;
            }
            $numerator = $this->combine($scaledLeftNumerator, $right[0], $rightScale);

            return $numerator === null ? null : [$numerator, $right[1]];
        }
        $leftDenominatorScale = $this->proportionalScale($right[1], $left[1]);
        if ($leftDenominatorScale !== null) {
            $scaledRightNumerator = $this->scale(
                $right[0],
                $leftDenominatorScale,
            );
            if ($scaledRightNumerator === null) {
                return null;
            }
            $numerator = $this->combine($left[0], $scaledRightNumerator, $rightScale);

            return $numerator === null ? null : [$numerator, $left[1]];
        }

        $leftNumerator = $this->multiply($left[0], $right[1]);
        $rightNumerator = $this->multiply($right[0], $left[1]);
        $denominator = $this->multiply($left[1], $right[1]);
        if ($leftNumerator === null || $rightNumerator === null || $denominator === null) {
            return null;
        }
        $numerator = $this->combine($leftNumerator, $rightNumerator, $rightScale);

        return $numerator === null ? null : [$numerator, $denominator];
    }

    /**
     * @param array{list<float>, list<float>} $left
     * @param array{list<float>, list<float>} $right
     * @return array{list<float>, list<float>}|null
     */
    private function multiplyRationals(array $left, array $right): ?array
    {
        $numerator = $this->multiply($left[0], $right[0]);
        $denominator = $this->multiply($left[1], $right[1]);

        return $numerator === null || $denominator === null
            ? null
            : [$numerator, $denominator];
    }

    /**
     * @param array{list<float>, list<float>} $left
     * @param array{list<float>, list<float>} $right
     * @return array{list<float>, list<float>}|null
     */
    private function divideRationals(array $left, array $right): ?array
    {
        if ($right[0] === [0.0]) {
            return null;
        }
        $numerator = $this->multiply($left[0], $right[1]);
        $denominator = $this->multiply($left[1], $right[0]);

        return $numerator === null || $denominator === null
            ? null
            : [$numerator, $denominator];
    }

    /** @param list<float> $polynomial
     *  @return list<float>|null
     */
    private function polynomialPower(array $polynomial, int $power): ?array
    {
        $result = [1.0];
        for ($index = 0; $index < $power; ++$index) {
            $result = $this->multiply($result, $polynomial);
            if ($result === null) {
                return null;
            }
        }

        return $result;
    }

    /**
     * Return the factor that scales the first polynomial into the second,
     * requiring exact floating-point coefficient equality.
     *
     * @param list<float> $base
     * @param list<float> $candidate
     */
    private function proportionalScale(array $base, array $candidate): ?float
    {
        if (\count($base) !== \count($candidate)) {
            return null;
        }
        $scale = null;
        foreach ($base as $index => $coefficient) {
            if ($coefficient === 0.0) {
                if ($candidate[$index] !== 0.0) {
                    return null;
                }
                continue;
            }
            $currentScale = $candidate[$index] / $coefficient;
            if (!\is_finite($currentScale)) {
                return null;
            }
            if ($scale === null) {
                $scale = $currentScale;
            } elseif ($scale !== $currentScale) {
                return null;
            }
        }
        if ($scale === null) {
            return null;
        }
        foreach ($base as $index => $coefficient) {
            if ($coefficient * $scale !== $candidate[$index]) {
                return null;
            }
        }

        return $scale;
    }

    private function containsVariable(Node $node): bool
    {
        return match (true) {
            $node instanceof VariableNode => $node->name === $this->variable,
            $node instanceof GroupingNode => $this->containsVariable($node->expression),
            $node instanceof UnaryOperationNode => $this->containsVariable($node->operand),
            $node instanceof BinaryOperationNode => $this->containsVariable($node->left)
                || $this->containsVariable($node->right),
            $node instanceof FactorialNode => $this->containsVariable($node->operand),
            $node instanceof FunctionCallNode => $this->isImplicitVariableProduct($node)
                ? $node->name === $this->variable
                    || $this->containsVariableInArguments($node->arguments)
                : $this->containsVariableInArguments($node->arguments),
            default => false,
        };
    }

    private function isImplicitVariableProduct(FunctionCallNode $node): bool
    {
        return \count($node->arguments) === 1
            && $this->options->functions->find($node->name) === null
            && ($node->name === $this->variable || \array_key_exists($node->name, $this->parameters));
    }

    /**
     * @param list<float>|null $variablePolynomial
     * @return list<float>|null
     */
    private function implicitProductFactor(
        FunctionCallNode $node,
        ?array $variablePolynomial = null,
    ): ?array {
        if ($node->name === $this->variable) {
            return $variablePolynomial ?? [0.0, 1.0];
        }

        return \array_key_exists($node->name, $this->parameters)
            ? [(float) $this->parameters[$node->name]]
            : null;
    }

    /**
     * @param list<Node> $arguments
     */
    private function containsVariableInArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($this->containsVariable($argument)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>|null
     */
    private function combine(array $left, array $right, float $rightScale): ?array
    {
        $coefficients = [];
        $length = \max(\count($left), \count($right));
        for ($degree = 0; $degree < $length; ++$degree) {
            $coefficient = ($left[$degree] ?? 0.0)
                + ($rightScale * ($right[$degree] ?? 0.0));
            if (!\is_finite($coefficient)) {
                return null;
            }
            $coefficients[] = $coefficient;
        }

        return $this->trim($coefficients);
    }

    /**
     * @param list<float> $polynomial
     * @return list<float>|null
     */
    private function scale(array $polynomial, float $factor): ?array
    {
        $coefficients = [];
        foreach ($polynomial as $coefficient) {
            $scaled = $coefficient * $factor;
            if (!\is_finite($scaled)) {
                return null;
            }
            $coefficients[] = $scaled;
        }

        return $this->trim($coefficients);
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     * @return list<float>|null
     */
    private function multiply(array $left, array $right): ?array
    {
        $degree = \count($left) + \count($right) - 2;
        if ($degree > $this->maxDegree) {
            return null;
        }

        $coefficients = \array_fill(0, $degree + 1, 0.0);
        $corrections = \array_fill(0, $degree + 1, 0.0);
        foreach ($left as $leftDegree => $leftCoefficient) {
            foreach ($right as $rightDegree => $rightCoefficient) {
                $index = $leftDegree + $rightDegree;
                $product = $leftCoefficient * $rightCoefficient;
                if (!\is_finite($product)) {
                    return null;
                }
                $sum = $coefficients[$index];
                $next = $sum + $product;
                if (!\is_finite($next)) {
                    return null;
                }
                $corrections[$index] += \abs($sum) >= \abs($product)
                    ? ($sum - $next) + $product
                    : ($product - $next) + $sum;
                if (!\is_finite($corrections[$index])) {
                    return null;
                }
                $coefficients[$index] = $next;
            }
        }
        foreach ($coefficients as $index => $coefficient) {
            $coefficients[$index] = $coefficient + $corrections[$index];
            if (!\is_finite($coefficients[$index])) {
                return null;
            }
        }

        return $this->trim(\array_values($coefficients));
    }

    /**
     * @param list<float> $numerator
     * @param list<float> $denominator
     * @return list<float>|null
     */
    private function divide(array $numerator, array $denominator): ?array
    {
        if (\count($denominator) !== 1 || $denominator[0] == 0.0) {
            return null;
        }

        $coefficients = [];
        foreach ($numerator as $coefficient) {
            $quotient = $coefficient / $denominator[0];
            if (!\is_finite($quotient)) {
                return null;
            }
            $coefficients[] = $quotient;
        }

        return $this->trim($coefficients);
    }

    /**
     * @param list<float> $base
     * @param list<float> $exponent
     * @return list<float>|null
     */
    private function power(array $base, array $exponent): ?array
    {
        if (
            \count($exponent) !== 1
            || $exponent[0] < 0.0
            || $exponent[0] > $this->maxDegree
            || $exponent[0] > $this->options->limits->maxExponentMagnitude
            || \floor($exponent[0]) !== $exponent[0]
        ) {
            return null;
        }

        $result = [1.0];
        for ($power = 0; $power < (int) $exponent[0]; ++$power) {
            $result = $this->multiply($result, $base);
            if ($result === null) {
                return null;
            }
        }

        return $result;
    }

    /**
     * @param list<float> $coefficients
     * @return list<float>
     */
    private function trim(array $coefficients): array
    {
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)] === 0.0) {
            \array_pop($coefficients);
        }

        return $coefficients;
    }
}
