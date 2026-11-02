<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Function\SpecialFunctions;

final class ArithmeticDifferentiator
{
    /**
     * @param array<string, int|float> $parameters
     */
    public function differentiate(
        Node $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?float {
        try {
            $result = $this->differentiateNode($node, $variable, $at, $parameters, $options);
        } catch (NondifferentiablePointException $error) {
            throw $error;
        } catch (\ArithmeticError | \DomainException | \OverflowException | \ValueError) {
            return null;
        }

        return $result[1] ?? null;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null Value and derivative.
     */
    private function differentiateNode(
        Node $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (!$this->containsVariable($node, $variable)) {
            $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);

            return $value === null ? null : [$value, 0.0];
        }

        if ($node instanceof VariableNode) {
            return $node->name === $variable ? [$at, 1.0] : null;
        }

        if ($node instanceof GroupingNode) {
            return $this->differentiateNode(
                $node->expression,
                $variable,
                $at,
                $parameters,
                $options,
            );
        }

        if ($node instanceof UnaryOperationNode) {
            $operand = $this->differentiateNode(
                $node->operand,
                $variable,
                $at,
                $parameters,
                $options,
            );
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            return [-$operand[0], -$operand[1]];
        }

        if ($node instanceof FunctionCallNode) {
            if (\in_array($node->name, ['min', 'max'], true)) {
                return $this->differentiateMinMax(
                    $node,
                    $variable,
                    $at,
                    $parameters,
                    $options,
                );
            }

            if (
                $options->functions->find($node->name) === null
                && \count($node->arguments) === 1
                && ($node->name === $variable || \array_key_exists($node->name, $parameters))
            ) {
                $argument = $this->differentiateNode(
                    $node->arguments[0],
                    $variable,
                    $at,
                    $parameters,
                    $options,
                );
                if ($argument === null) {
                    return null;
                }
                $factor = $node->name === $variable
                    ? [$at, 1.0]
                    : [(float) $parameters[$node->name], 0.0];
                $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
                $derivative = ($factor[1] * $argument[0]) + ($factor[0] * $argument[1]);

                return $value !== null && \is_finite($derivative)
                    ? [$value, $derivative]
                    : null;
            }

            return $this->differentiateFunction($node, $variable, $at, $parameters, $options);
        }

        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        if (
            $node->operator === BinaryOperator::Power
            && !$this->containsVariable($node->right, $variable)
            && $this->evaluateAt($node->right, $variable, $at, $parameters, $options) === 0.0
        ) {
            $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);

            return $value === null ? null : [$value, 0.0];
        }

        $powerBase = $node->left;
        while ($powerBase instanceof GroupingNode) {
            $powerBase = $powerBase->expression;
        }
        $radicalDegree = $powerBase instanceof FunctionCallNode
            ? match ($powerBase->name) {
                'sqrt' => 2,
                'cbrt' => 3,
                default => null,
            }
            : null;
        if (
            $node->operator === BinaryOperator::Power
            && $powerBase instanceof FunctionCallNode
            && $radicalDegree !== null
            && \count($powerBase->arguments) === 1
        ) {
            $exactOuterExponent = RationalLiteral::fromNode($node->right);
            if (
                $exactOuterExponent !== null
                && $exactOuterExponent[1] === 1
                && $exactOuterExponent[0] >= 2
                && $exactOuterExponent[0] <= 512
            ) {
                $radicand = $this->differentiateNode(
                    $powerBase->arguments[0],
                    $variable,
                    $at,
                    $parameters,
                    $options,
                );
                if ($radicand === null) {
                    return null;
                }
                $exponent = $exactOuterExponent[0] / $radicalDegree;
                $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
                if ($value === null) {
                    return null;
                }
                $result = $this->differentiatePower(
                    $radicand,
                    [$exponent, 0.0],
                    [$exactOuterExponent[0], $radicalDegree],
                    $options,
                );

                return $result === null ? null : [$value, $result[1]];
            }
        }

        if (
            $node->operator === BinaryOperator::Power
            && $powerBase instanceof FunctionCallNode
            && \in_array($powerBase->name, ['abs', 'min', 'max'], true)
            && !$this->containsVariable($node->right, $variable)
        ) {
            $outerExponent = $this->evaluateAt($node->right, $variable, $at, $parameters, $options);
            if (
                $outerExponent !== null
                && $outerExponent > 1.0
                && $outerExponent <= $options->limits->maxExponentMagnitude
            ) {
                $baseValue = $this->evaluateAt($powerBase, $variable, $at, $parameters, $options);
                if ($baseValue === 0.0) {
                    foreach ($powerBase->arguments as $argumentNode) {
                        try {
                            if (
                                $this->differentiateNode(
                                    $argumentNode,
                                    $variable,
                                    $at,
                                    $parameters,
                                    $options,
                                ) === null
                            ) {
                                return null;
                            }
                        } catch (NondifferentiablePointException $error) {
                            throw $error;
                        } catch (\DomainException) {
                            return null;
                        }
                    }
                    $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);

                    return $value === null ? null : [$value, 0.0];
                }
            }
        }

        $left = $this->differentiateNode($node->left, $variable, $at, $parameters, $options);
        $right = $this->differentiateNode($node->right, $variable, $at, $parameters, $options);
        if ($left === null || $right === null) {
            return null;
        }

        $result = match ($node->operator) {
            BinaryOperator::Add => [$left[0] + $right[0], $left[1] + $right[1]],
            BinaryOperator::Subtract => [$left[0] - $right[0], $left[1] - $right[1]],
            BinaryOperator::Multiply => [
                $left[0] * $right[0],
                ($left[1] * $right[0]) + ($left[0] * $right[1]),
            ],
            BinaryOperator::Divide => $right[0] === 0.0
                ? null
                : [
                    $left[0] / $right[0],
                    ($left[1] - (($left[0] / $right[0]) * $right[1])) / $right[0],
                ],
            BinaryOperator::Power => $this->differentiatePower(
                $left,
                $right,
                RationalLiteral::fromNode($node->right),
                $options,
            ),
            BinaryOperator::Modulo => null,
        };

        return $result !== null && \is_finite($result[0]) && \is_finite($result[1])
            ? $result
            : null;
    }

    /**
     * @param array{float, float} $base
     * @param array{float, float} $exponent
     * @param array{int, int}|null $exactExponent
     * @return array{float, float}|null
     */
    private function differentiatePower(
        array $base,
        array $exponent,
        ?array $exactExponent,
        EvaluationOptions $options,
    ): ?array {
        if (\abs($exponent[0]) > $options->limits->maxExponentMagnitude) {
            return null;
        }

        if ($exponent[1] !== 0.0) {
            if ($base[0] <= 0.0) {
                return null;
            }

            $value = $base[0] ** $exponent[0];
            $baseTerm = $this->multiplyDivide(
                $exponent[0],
                $base[1],
                $base[0],
            );
            if ($baseTerm === null) {
                return null;
            }
            $derivative = $value * (
                ($exponent[1] * \log($base[0]))
                + $baseTerm
            );

            return \is_finite($value) && \is_finite($derivative)
                ? [$value, $derivative]
                : null;
        }

        if (\floor($exponent[0]) === $exponent[0]) {
            if (
                \abs($exponent[0]) > 256.0
                || ($base[0] === 0.0 && $exponent[0] < 0.0)
            ) {
                return null;
            }

            $power = (int) $exponent[0];
            $value = $base[0] ** $power;
            $derivative = $power === 0
                ? 0.0
                : $power * ($base[0] ** ($power - 1)) * $base[1];

            return \is_finite($value) && \is_finite($derivative)
                ? [$value, $derivative]
                : null;
        }

        if (
            $base[0] < 0.0
            && $exactExponent !== null
            && $exactExponent[1] % 2 !== 0
        ) {
            $magnitude = \abs($base[0]);
            $valueSign = $exactExponent[0] % 2 === 0 ? 1.0 : -1.0;
            $valueMagnitude = $magnitude ** $exponent[0];
            $derivativeMagnitude = $this->multiplyDivide(
                $exponent[0],
                $valueMagnitude,
                $magnitude,
            );
            if ($derivativeMagnitude === null) {
                return null;
            }
            $derivativeSign = -$valueSign;
            $value = $valueSign * $valueMagnitude;
            $derivative = $derivativeSign * $derivativeMagnitude * $base[1];

            return \is_finite($value) && \is_finite($derivative)
                ? [$value, $derivative]
                : null;
        }

        if ($base[0] === 0.0 && $exactExponent !== null) {
            if ($exponent[0] > 1.0) {
                return [0.0, 0.0];
            }
            if (
                $exponent[0] > 0.0
                && $exponent[0] < 1.0
                && $base[1] !== 0.0
            ) {
                throw new NondifferentiablePointException(
                    'A positive rational power below one has no finite derivative at a zero base.',
                );
            }
        }

        if ($base[0] <= 0.0) {
            return null;
        }

        $value = $base[0] ** $exponent[0];
        $derivative = ($exponent[0] * ($value / $base[0])) * $base[1];

        return \is_finite($value) && \is_finite($derivative)
            ? [$value, $derivative]
            : null;
    }

    private function multiplyDivide(float $first, float $second, float $divisor): ?float
    {
        if ($divisor === 0.0) {
            return null;
        }
        if ($first === 0.0 || $second === 0.0) {
            return 0.0;
        }

        $quotient = $first / $divisor;
        if (\is_finite($quotient) && $quotient !== 0.0) {
            $result = $quotient * $second;
            if (\is_finite($result) && $result !== 0.0) {
                return $result;
            }
        }

        $product = $first * $second;
        if (\is_finite($product) && $product !== 0.0) {
            $result = $product / $divisor;
            if (\is_finite($result) && $result !== 0.0) {
                return $result;
            }
        }

        $logMagnitude = \log(\abs($first))
            + \log(\abs($second))
            - \log(\abs($divisor));
        if ($logMagnitude > \log(\PHP_FLOAT_MAX)) {
            return null;
        }
        if ($logMagnitude < \log(5.0E-324)) {
            return 0.0;
        }

        $negative = (($first < 0.0) !== ($second < 0.0)) !== ($divisor < 0.0);
        $magnitude = \exp($logMagnitude);

        return $negative ? -$magnitude : $magnitude;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     */
    private function differentiateFunction(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (!$options->functions->isBuiltIn($node->name)) {
            $definition = $options->functions->find($node->name);
            if ($definition === null || !$definition->hasGradient()) {
                return null;
            }

            $arguments = [];
            $derivatives = [];
            foreach ($node->arguments as $argumentNode) {
                $argument = $this->differentiateNode(
                    $argumentNode,
                    $variable,
                    $at,
                    $parameters,
                    $options,
                );
                if ($argument === null) {
                    return null;
                }
                $arguments[] = $argument[0];
                $derivatives[] = $argument[1];
            }

            $gradient = $definition->gradient($arguments);
            if ($gradient === null) {
                return null;
            }

            $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
            if ($value === null) {
                return null;
            }

            $slope = $this->scaledDotProduct($gradient, $derivatives);

            return $slope === null ? null : [$value, $slope];
        }

        if (\count($node->arguments) === 2) {
            return $this->differentiateTwoArgumentFunction(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
            );
        }
        if (\count($node->arguments) === 3) {
            return $this->differentiateThreeArgumentFunction(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
            );
        }
        if (\count($node->arguments) !== 1) {
            return null;
        }

        $argument = $this->differentiateNode(
            $node->arguments[0],
            $variable,
            $at,
            $parameters,
            $options,
        );
        if ($argument === null) {
            return null;
        }
        $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
        if ($value === null) {
            return null;
        }

        $x = $argument[0];
        if (\in_array($node->name, ['sqrt', 'cbrt'], true) && $x === 0.0) {
            return [$value, $this->differentiateAtFiniteEndpoint(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
                $value,
                $node->name === 'sqrt',
            )];
        }
        if (
            (\in_array($node->name, ['asin', 'acos'], true) && \abs($x) === 1.0)
            || ($node->name === 'acosh' && $x === 1.0)
            || ($node->name === 'asech' && $x === 1.0)
        ) {
            return [$value, $this->differentiateAtFiniteEndpoint(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
                $value,
                true,
            )];
        }
        try {
            $slope = match ($node->name) {
                'sin' => \cos($x),
                'cos' => -\sin($x),
                'tan' => 1.0 / (\cos($x) ** 2),
                'sec' => ($value * \sin($x)) / \cos($x),
                'csc' => -($value * \cos($x)) / \sin($x),
                'cot' => -1.0 / (\sin($x) ** 2),
                'asin', 'arcsin' => \abs($x) < 1.0 ? 1.0 / \sqrt((1.0 - $x) * (1.0 + $x)) : \INF,
                'acos', 'arccos' => \abs($x) < 1.0 ? -1.0 / \sqrt((1.0 - $x) * (1.0 + $x)) : \INF,
                'atan', 'arctan' => (1.0 / \hypot(1.0, $x)) / \hypot(1.0, $x),
                'asec' => $this->inverseSecantSlope($x),
                'acsc' => -$this->inverseSecantSlope($x),
                'acot' => -((1.0 / \hypot(1.0, $x)) / \hypot(1.0, $x)),
                'sinh' => \cosh($x),
                'cosh' => \sinh($x),
                'tanh' => $this->hyperbolicTangentSlope($x),
                'sech' => -$value * \tanh($x),
                'csch' => -$value * $this->hyperbolicCotangent($x),
                'coth' => -($this->hyperbolicCosecant($x) ** 2),
                'asinh', 'arcsinh' => 1.0 / \hypot(1.0, $x),
                'acosh', 'arccosh' => $this->inverseCoshSlope($x),
                'atanh', 'arctanh' => \abs($x) < 1.0
                ? 1.0 / ((1.0 - $x) * (1.0 + $x))
                : \INF,
                'asech' => $this->inverseSechSlope($x),
                'acsch' => $this->inverseCschSlope($x),
                'acoth' => $this->inverseCothSlope($x),
                'exp' => $value,
                'expm1' => \exp($x),
                'ln' => $x > 0.0 ? 1.0 / $x : \INF,
                'log10' => $x > 0.0 ? 1.0 / ($x * \log(10.0)) : \INF,
                'log2' => $x > 0.0 ? 1.0 / ($x * \log(2.0)) : \INF,
                'log1p' => $x > -1.0 ? 1.0 / (1.0 + $x) : \INF,
                'sqrt' => $x > 0.0 ? 0.5 / $value : \INF,
                'cbrt' => $x !== 0.0 ? 1.0 / (3.0 * ($value * $value)) : \INF,
                'sinc' => $this->sincSlope($x, 1.0),
                'sincpi' => $this->sincSlope($x, \M_PI),
                'gamma' => $value * SpecialFunctions::digamma($x),
                'zeta' => SpecialFunctions::riemannZetaDerivative($x) ?? \INF,
                'lgamma' => SpecialFunctions::digamma($x),
                'digamma' => SpecialFunctions::trigamma($x),
                'trigamma' => SpecialFunctions::tetragamma($x),
                'tetragamma' => SpecialFunctions::polygamma(3, $x),
                'lambertw' => $this->lambertWSlope($x, $value, 0) ?? \INF,
                'abs' => $this->absoluteSlope($x, $argument[1]),
                'sign', 'floor', 'ceil', 'ceiling', 'round', 'trunc', 'truncate' => $this->piecewiseConstantSlope($node->name, $x),
                'erf', 'erfc' => ($node->name === 'erf' ? 1.0 : -1.0)
                * (2.0 / \sqrt(\M_PI))
                * \exp(-($x * $x)),
                'erfi' => (2.0 / \sqrt(\M_PI)) * \exp($x * $x),
                'dawson' => 1.0 - (2.0 * ($x * $value)),
                'erfcx' => SpecialFunctions::erfcxDerivative($x),
                'erfinv', 'erfcinv' => ($node->name === 'erfinv' ? 1.0 : -1.0)
                * (\sqrt(\M_PI) / 2.0)
                * \exp($value * $value),
                default => \INF,
            };
        } catch (NondifferentiablePointException $error) {
            throw $error;
        } catch (\ArithmeticError | \DomainException | \ValueError) {
            return null;
        }
        $derivative = $slope * $argument[1];

        return \is_finite($derivative) ? [$value, $derivative] : null;
    }

    /** @param array<string, int|float> $parameters */
    private function differentiateAtFiniteEndpoint(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
        float $centerValue,
        bool $allowOneSidedDomain,
    ): float {
        $scale = \max(1.0, \abs($at));
        $step = 1.0E-2 * $scale;
        $samples = ['left' => [], 'right' => []];

        for ($iteration = 0; $iteration < 9; ++$iteration) {
            $leftPoint = $at - $step;
            $rightPoint = $at + $step;
            if (
                !\is_finite($leftPoint)
                || !\is_finite($rightPoint)
                || $leftPoint === $at
                || $rightPoint === $at
            ) {
                break;
            }
            $leftValue = $this->evaluateAt($node, $variable, $leftPoint, $parameters, $options);
            $rightValue = $this->evaluateAt($node, $variable, $rightPoint, $parameters, $options);
            if (!$allowOneSidedDomain && ($leftValue === null || $rightValue === null)) {
                throw new NondifferentiablePointException(
                    $node->name . '() is not defined on both sides of the requested point.',
                );
            }
            if (
                ($leftValue !== null && $leftValue === $centerValue)
                || ($rightValue !== null && $rightValue === $centerValue)
            ) {
                break;
            }
            if ($leftValue === null && $rightValue === null) {
                throw new NondifferentiablePointException(
                    $node->name . '() has no real values near the requested point.',
                );
            }
            if ($leftValue !== null) {
                $samples['left'][] = ($centerValue - $leftValue) / $step;
            }
            if ($rightValue !== null) {
                $samples['right'][] = ($rightValue - $centerValue) / $step;
            }
            $step *= 0.1;
        }

        $availableSides = \array_filter($samples, static fn (array $values): bool => $values !== []);
        if ($availableSides === []) {
            throw new NondifferentiablePointException($node->name . '() is not differentiable at a zero argument.');
        }

        if (\count($availableSides) !== 2 && !$allowOneSidedDomain) {
            throw new NondifferentiablePointException(
                $node->name . '() is not defined on both sides of the requested point.',
            );
        }

        if (\count($availableSides) === 2) {
            $leftShrinksToZero = $this->differenceQuotientsShrinkToZero($samples['left']);
            $rightShrinksToZero = $this->differenceQuotientsShrinkToZero($samples['right']);
            if ($leftShrinksToZero && $rightShrinksToZero) {
                return 0.0;
            }
        }

        if (\count($availableSides) === 2) {
            $left = $samples['left'];
            $right = $samples['right'];
            $last = \count($left) - 1;
            if ($last >= 2) {
                $leftDerivative = $left[$last];
                $rightDerivative = $right[$last];
                $scale = \max(\abs($leftDerivative), \abs($rightDerivative), \PHP_FLOAT_MIN);
                $previousScale = \max(
                    \abs($left[$last - 1]),
                    \abs($right[$last - 1]),
                    \PHP_FLOAT_MIN,
                );
                $beforePreviousScale = \max(
                    \abs($left[$last - 2]),
                    \abs($right[$last - 2]),
                    \PHP_FLOAT_MIN,
                );
                $lastChange = \max(
                    \abs($leftDerivative - $left[$last - 1]),
                    \abs($rightDerivative - $right[$last - 1]),
                );
                $previousChange = \max(
                    \abs($left[$last - 1] - $left[$last - 2]),
                    \abs($right[$last - 1] - $right[$last - 2]),
                );
                if (
                    \abs($leftDerivative - $rightDerivative) <= 1.0E-5 * $scale
                        && \abs($leftDerivative - $rightDerivative)
                            <= 1.0E-8 * \max(1.0, \abs($leftDerivative), \abs($rightDerivative))
                    && $lastChange <= 1.0E-5 * $scale
                    && $previousChange <= 1.0E-3 * $previousScale
                    && $scale <= 2.0 * $beforePreviousScale
                ) {
                    return ($leftDerivative / 2.0) + ($rightDerivative / 2.0);
                }
            }
        }
        if (\count($availableSides) === 1 && $allowOneSidedDomain) {
            $values = \reset($availableSides);
            if ($this->differenceQuotientsShrinkToZero($values)) {
                return 0.0;
            }
        }
        if (\count($availableSides) === 1) {
            $values = \reset($availableSides);
            $last = \count($values) - 1;
            if ($last >= 2) {
                $derivative = $values[$last];
                $scale = \max(\abs($derivative), \PHP_FLOAT_MIN);
                $previousScale = \max(\abs($values[$last - 1]), \PHP_FLOAT_MIN);
                $beforePreviousScale = \max(\abs($values[$last - 2]), \PHP_FLOAT_MIN);
                if (
                    \abs($derivative - $values[$last - 1]) <= 1.0E-5 * $scale
                    && \abs($values[$last - 1] - $values[$last - 2]) <= 1.0E-3 * $previousScale
                    && $scale <= 2.0 * $beforePreviousScale
                ) {
                    return $derivative;
                }
            }
        }

        throw new NondifferentiablePointException($node->name . '() is not differentiable at a zero argument.');
    }

    /** @param list<float> $values */
    private function differenceQuotientsShrinkToZero(array $values): bool
    {
        if (\count($values) < 2) {
            return false;
        }
        $first = \abs($values[0]);
        $last = \abs($values[\count($values) - 1]);
        if (\count($values) === 2) {
            return $last <= 0.5 * $first;
        }
        $middle = \abs($values[\count($values) - 2]);

        return $first === 0.0
            || ($last <= 2.0E-2 * $first && $middle <= 0.8 * $first && $last <= 0.8 * $middle);
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    private function scaledDotProduct(array $left, array $right): ?float
    {
        if ($left === [] || $right === [] || \count($left) !== \count($right)) {
            return null;
        }

        $leftScale = 0.0;
        $rightScale = 0.0;
        foreach ($left as $index => $value) {
            $leftScale = \max($leftScale, \abs($value));
            $rightScale = \max($rightScale, \abs($right[$index]));
        }
        if ($leftScale === 0.0 || $rightScale === 0.0) {
            return 0.0;
        }

        $sum = 0.0;
        $compensation = 0.0;
        foreach ($left as $index => $value) {
            $product = ($value / $leftScale) * ($right[$index] / $rightScale);
            $adjusted = $product - $compensation;
            $next = $sum + $adjusted;
            $compensation = ($next - $sum) - $adjusted;
            $sum = $next;
        }
        if ($sum === 0.0) {
            return 0.0;
        }

        $logMagnitude = \log(\abs($sum)) + \log($leftScale) + \log($rightScale);
        if ($logMagnitude > \log(\PHP_FLOAT_MAX)) {
            return null;
        }
        if ($logMagnitude < \log(5.0E-324)) {
            return 0.0;
        }

        $magnitude = \exp($logMagnitude);

        return $sum < 0.0 ? -$magnitude : $magnitude;
    }

    /**
     * Differentiate incomplete-beta functions, including shape partials with a unit shape.
     *
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     */
    private function differentiateThreeArgumentFunction(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (!\in_array($node->name, ['betainc', 'betaincc', 'betaincinv', 'betainccinv'], true)) {
            return null;
        }
        $inverse = \in_array($node->name, ['betaincinv', 'betainccinv'], true);
        $firstShape = $this->differentiateNode(
            $node->arguments[0],
            $variable,
            $at,
            $parameters,
            $options,
        );
        $secondShape = $this->differentiateNode(
            $node->arguments[1],
            $variable,
            $at,
            $parameters,
            $options,
        );
        $argument = $this->differentiateNode(
            $node->arguments[2],
            $variable,
            $at,
            $parameters,
            $options,
        );
        if (
            $firstShape === null
            || $secondShape === null
            || $argument === null
        ) {
            return null;
        }
        if (
            $argument[0] < 0.0
            || $argument[0] > 1.0
        ) {
            return null;
        }

        if (
            ($argument[0] === 0.0 || $argument[0] === 1.0)
            && !$this->containsVariable($node->arguments[2], $variable)
        ) {
            $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);

            return $value === null ? null : [$value, 0.0];
        }

        try {
            $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
            if ($value === null) {
                return null;
            }
            $density = SpecialFunctions::regularizedBetaDensity(
                $firstShape[0],
                $secondShape[0],
                $inverse ? $value : $argument[0],
            );
        } catch (\DomainException) {
            return null;
        }
        $sign = match ($node->name) {
            'betainc', 'betaincinv' => 1.0,
            default => -1.0,
        };
        if ($inverse) {
            $firstShapePartial = $firstShape[1] === 0.0
                ? 0.0
                : $this->betaShapeDerivative(
                    $firstShape[0],
                    $secondShape[0],
                    $value,
                    true,
                );
            $secondShapePartial = $secondShape[1] === 0.0
                ? 0.0
                : $this->betaShapeDerivative(
                    $firstShape[0],
                    $secondShape[0],
                    $value,
                    false,
                );
            if ($firstShapePartial === null || $secondShapePartial === null) {
                return null;
            }
            $probabilitySign = $node->name === 'betaincinv' ? 1.0 : -1.0;
            $derivative = (
                ($probabilitySign * $argument[1])
                - ($firstShapePartial * $firstShape[1])
                - ($secondShapePartial * $secondShape[1])
            ) / $density;
        } else {
            $firstShapePartial = $firstShape[1] === 0.0 || $argument[0] === 0.0
                ? 0.0
                : ($secondShape[0] === 1.0
                    ? \pow($argument[0], $firstShape[0]) * \log($argument[0])
                    : $this->betaShapeDerivative(
                        $firstShape[0],
                        $secondShape[0],
                        $argument[0],
                        true,
                    ));
            $secondShapePartial = $secondShape[1] === 0.0 || $argument[0] === 1.0
                ? 0.0
                : ($firstShape[0] === 1.0
                    ? -\pow(1.0 - $argument[0], $secondShape[0]) * \log1p(-$argument[0])
                    : $this->betaShapeDerivative(
                        $firstShape[0],
                        $secondShape[0],
                        $argument[0],
                        false,
                    ));
            if ($firstShapePartial === null || $secondShapePartial === null) {
                return null;
            }
            $derivative = $sign * (
                ($firstShapePartial * $firstShape[1])
                + ($secondShapePartial * $secondShape[1])
                + ($density * $argument[1])
            );
        }

        return \is_finite($derivative)
            ? [$value, $derivative]
            : null;
    }

    private function betaShapeDerivative(
        float $firstShape,
        float $secondShape,
        float $value,
        bool $differentiateFirstShape,
    ): ?float {
        $sign = 1.0;
        $shapeSum = $firstShape + $secondShape;
        if (!\is_finite($shapeSum)) {
            return null;
        }
        $complementThreshold = ($firstShape + 1.0) / ($shapeSum + 2.0);
        if ($value >= $complementThreshold) {
            [$firstShape, $secondShape] = [$secondShape, $firstShape];
            $value = 1.0 - $value;
            $differentiateFirstShape = !$differentiateFirstShape;
            $sign = -1.0;
        }
        $shape = $differentiateFirstShape ? $firstShape : $secondShape;
        $differentiator = new NumericalDifferentiator();

        $derivative = $differentiator->differentiate(
            static function (float $candidate) use (
                $firstShape,
                $secondShape,
                $value,
                $differentiateFirstShape,
            ): ?float {
                if ($candidate <= 0.0) {
                    return null;
                }

                try {
                    return $differentiateFirstShape
                        ? SpecialFunctions::regularizedBeta($candidate, $secondShape, $value)
                        : SpecialFunctions::regularizedBeta($firstShape, $candidate, $value);
                } catch (\DomainException) {
                    return null;
                }
            },
            $shape,
        );

        return $derivative === null ? null : $sign * $derivative;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     */
    private function differentiateTwoArgumentFunction(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (
            !\in_array(
                $node->name,
                [
                    'log', 'hypot', 'atan2', 'root', 'lambertw', 'polygamma',
                    'besselj', 'besseli', 'bessely', 'beta', 'gammainc', 'gammaincc',
                    'gammaincinv', 'gammainccinv',
                ],
                true,
            )
        ) {
            return null;
        }

        $first = $this->differentiateNode(
            $node->arguments[0],
            $variable,
            $at,
            $parameters,
            $options,
        );
        $second = $this->differentiateNode(
            $node->arguments[1],
            $variable,
            $at,
            $parameters,
            $options,
        );
        if ($first === null || $second === null) {
            return null;
        }

        if ($node->name === 'atan2') {
            $monomialResult = $this->monomialAtan2Result(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
            );
            if ($monomialResult !== null) {
                return $monomialResult;
            }
        }

        $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
        if ($value === null) {
            return null;
        }

        if (
            \in_array($node->name, ['gammainc', 'gammaincc'], true)
            && $second[0] === 0.0
            && !$this->containsVariable($node->arguments[1], $variable)
        ) {
            return [$value, 0.0];
        }
        if (
            \in_array($node->name, ['gammaincinv', 'gammainccinv'], true)
            && ($second[0] <= 0.0 || $second[0] >= 1.0)
        ) {
            return null;
        }
        if ($node->name === 'root' && $first[0] === 0.0) {
            return [$value, $this->differentiateAtFiniteEndpoint(
                $node,
                $variable,
                $at,
                $parameters,
                $options,
                $value,
                true,
            )];
        }

        $derivative = match ($node->name) {
            'log' => $this->logarithmDerivative($first, $second),
            'hypot' => $this->hypotenuseDerivative($first, $second),
            'atan2' => $this->arcTangentTwoDerivative($first, $second),
            'root' => $this->rootDerivative($first, $second, $value),
            'lambertw' => $this->lambertWDerivative($first, $second, $value),
            'polygamma' => $this->polygammaDerivative($first, $second),
            'besselj' => $this->besselJDerivative($first, $second),
            'besseli' => $this->besselIDerivative($first, $second),
            'bessely' => $this->besselYDerivative($first, $second),
            'beta' => $this->betaDerivative($first, $second, $value),
            'gammainc', 'gammaincc' => $this->incompleteGammaDerivative($node->name, $first, $second),
            'gammaincinv', 'gammainccinv' => $this->inverseIncompleteGammaDerivative(
                $node->name,
                $first,
                $second,
                $value,
            ),
        };

        return $derivative !== null && \is_finite($derivative)
            ? [$value, $derivative]
            : null;
    }

    /**
     * Evaluate the atan2 chain rule in log scale when both coordinates are monomials.
     *
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null Value and derivative.
     */
    private function monomialAtan2Result(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if ($at === 0.0) {
            return null;
        }
        $extractor = new PolynomialExtractor($variable, $parameters, $options);
        $coordinates = [];
        foreach ($node->arguments as $argument) {
            $coefficients = $extractor->extract($argument);
            if ($coefficients === null) {
                return null;
            }
            $nonzero = [];
            foreach ($coefficients as $degree => $coefficient) {
                if ($coefficient !== 0.0) {
                    $nonzero[] = [$degree, $coefficient];
                }
            }
            if (\count($nonzero) > 1) {
                return null;
            }
            $coordinates[] = $nonzero[0] ?? [null, 0.0];
        }

        [$yDegree, $yCoefficient] = $coordinates[0];
        [$xDegree, $xCoefficient] = $coordinates[1];
        if ($yDegree === null || $xDegree === null) {
            if ($yDegree === null && $xDegree === null) {
                return null;
            }
            if ($yDegree === null) {
                $xNegative = ($xCoefficient < 0.0)
                    !== ($at < 0.0 && ($xDegree % 2) !== 0);

                return [$xNegative ? \M_PI : 0.0, 0.0];
            }
            $yNegative = ($yCoefficient < 0.0)
                !== ($at < 0.0 && ($yDegree % 2) !== 0);

            return [$yNegative ? -\M_PI_2 : \M_PI_2, 0.0];
        }
        $logAbsAt = \log(\abs($at));
        $logY = \log(\abs($yCoefficient)) + ($yDegree * $logAbsAt);
        $logX = \log(\abs($xCoefficient)) + ($xDegree * $logAbsAt);
        $largestCoordinate = \max($logY, $logX);
        $yScaled = ($yCoefficient < 0.0 ? -1.0 : 1.0) * \exp($logY - $largestCoordinate);
        $xScaled = ($xCoefficient < 0.0 ? -1.0 : 1.0) * \exp($logX - $largestCoordinate);
        $value = \atan2($yScaled, $xScaled);
        $degreeDifference = $yDegree - $xDegree;
        if ($degreeDifference === 0) {
            return [$value, 0.0];
        }

        $numeratorDegree = $yDegree + $xDegree - 1;
        $commonDegree = 2 * \min($yDegree, $xDegree);
        $logNumerator = \log(\abs($yCoefficient))
            + \log(\abs($xCoefficient))
            + \log(\abs((float) $degreeDifference))
            + (($numeratorDegree - $commonDegree) * $logAbsAt);
        $logYTerm = (2.0 * \log(\abs($yCoefficient)))
            + ((2 * $yDegree - $commonDegree) * $logAbsAt);
        $logXTerm = (2.0 * \log(\abs($xCoefficient)))
            + ((2 * $xDegree - $commonDegree) * $logAbsAt);
        $largestDenominatorTerm = \max($logYTerm, $logXTerm);
        $logDenominator = $largestDenominatorTerm
            + \log1p(\exp(\min($logYTerm, $logXTerm) - $largestDenominatorTerm));
        $logMagnitude = $logNumerator - $logDenominator;
        if ($logMagnitude > \log(\PHP_FLOAT_MAX)) {
            return null;
        }
        if ($logMagnitude < \log(5.0E-324)) {
            return [$value, 0.0];
        }

        $negative = (($yCoefficient < 0.0) !== ($xCoefficient < 0.0))
            !== ($degreeDifference < 0);
        if ($at < 0.0 && ($numeratorDegree % 2) !== 0) {
            $negative = !$negative;
        }
        $magnitude = \exp($logMagnitude);

        return [$value, $negative ? -$magnitude : $magnitude];
    }

    /**
     * @param array{0: float, 1: float} $first
     * @param array{0: float, 1: float} $second
     */
    private function betaDerivative(array $first, array $second, float $value): ?float
    {
        if (
            ($first[0] <= 0.0 && \floor($first[0]) === $first[0])
            || ($second[0] <= 0.0 && \floor($second[0]) === $second[0])
            || ($first[0] + $second[0] <= 0.0
                && \floor($first[0] + $second[0]) === $first[0] + $second[0])
        ) {
            return null;
        }

        $sum = $first[0] + $second[0];
        $firstSlope = SpecialFunctions::digamma($first[0]) - SpecialFunctions::digamma($sum);
        $secondSlope = SpecialFunctions::digamma($second[0]) - SpecialFunctions::digamma($sum);
        $derivative = $value * (($firstSlope * $first[1]) + ($secondSlope * $second[1]));

        return \is_finite($derivative) ? $derivative : null;
    }

    /** @param array{0: float, 1: float} $shape
     *  @param array{0: float, 1: float} $argument
     */
    private function incompleteGammaDerivative(string $name, array $shape, array $argument): ?float
    {
        if ($argument[0] < 0.0) {
            return null;
        }
        try {
            $density = SpecialFunctions::regularizedGammaDensity($shape[0], $argument[0]);
            $shapeDerivative = $shape[1] === 0.0
                ? 0.0
                : SpecialFunctions::regularizedGammaShapeDerivative(
                    $shape[0],
                    $argument[0],
                ) * $shape[1];
        } catch (\DomainException) {
            return null;
        }
        $sign = $name === 'gammainc' ? 1.0 : -1.0;
        $derivative = $sign * (($density * $argument[1]) + $shapeDerivative);

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $shape
     * @param array{0: float, 1: float} $probability
     */
    private function inverseIncompleteGammaDerivative(
        string $name,
        array $shape,
        array $probability,
        float $value,
    ): ?float {
        try {
            $density = SpecialFunctions::regularizedGammaDensity($shape[0], $value);
            $shapeDerivative = $shape[1] === 0.0
                ? 0.0
                : SpecialFunctions::regularizedGammaShapeDerivative(
                    $shape[0],
                    $value,
                ) * $shape[1];
        } catch (\DomainException) {
            return null;
        }
        $probabilitySign = $name === 'gammaincinv' ? 1.0 : -1.0;
        $derivative = (($probabilitySign * $probability[1]) - $shapeDerivative) / $density;

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $order
     * @param array{0: float, 1: float} $argument
     */
    private function polygammaDerivative(array $order, array $argument): ?float
    {
        if (
            $order[1] !== 0.0
            || $order[0] < 0.0
            || $order[0] >= SpecialFunctions::MAX_POLYGAMMA_ORDER
            || \floor($order[0]) !== $order[0]
        ) {
            return null;
        }

        $derivative = SpecialFunctions::polygamma(
            (int) $order[0] + 1,
            $argument[0],
        ) * $argument[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $order
     * @param array{0: float, 1: float} $argument
     */
    private function besselJDerivative(array $order, array $argument): ?float
    {
        if (
            $order[1] !== 0.0
            || \floor($order[0]) !== $order[0]
            || \abs($order[0]) > SpecialFunctions::MAX_BESSEL_ORDER
        ) {
            return null;
        }

        $integerOrder = (int) $order[0];
        $x = $argument[0];
        if ($x === 0.0) {
            $slope = match ($integerOrder) {
                1 => 0.5,
                -1 => -0.5,
                default => 0.0,
            };
        } elseif ($integerOrder === SpecialFunctions::MAX_BESSEL_ORDER) {
            $maximumOrder = SpecialFunctions::MAX_BESSEL_ORDER;
            $slope = SpecialFunctions::besselJ($maximumOrder - 1, $x)
                - (($maximumOrder / $x) * SpecialFunctions::besselJ($maximumOrder, $x));
        } elseif ($integerOrder === -SpecialFunctions::MAX_BESSEL_ORDER) {
            $maximumOrder = SpecialFunctions::MAX_BESSEL_ORDER;
            $slope = ((-$maximumOrder / $x) * SpecialFunctions::besselJ(-$maximumOrder, $x))
                - SpecialFunctions::besselJ(-$maximumOrder + 1, $x);
        } else {
            $slope = 0.5 * (
                SpecialFunctions::besselJ($integerOrder - 1, $x)
                - SpecialFunctions::besselJ($integerOrder + 1, $x)
            );
        }
        $derivative = $slope * $argument[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $order
     * @param array{0: float, 1: float} $argument
     */
    private function besselIDerivative(array $order, array $argument): ?float
    {
        if (
            $order[1] !== 0.0
            || \floor($order[0]) !== $order[0]
            || \abs($order[0]) > SpecialFunctions::MAX_BESSEL_ORDER
        ) {
            return null;
        }

        $integerOrder = (int) $order[0];
        $x = $argument[0];
        if ($x === 0.0) {
            $slope = \abs($integerOrder) === 1 ? 0.5 : 0.0;
        } elseif (\abs($integerOrder) === SpecialFunctions::MAX_BESSEL_ORDER) {
            $maximumOrder = SpecialFunctions::MAX_BESSEL_ORDER;
            $slope = SpecialFunctions::besselI($maximumOrder - 1, $x)
                - (($maximumOrder / $x) * SpecialFunctions::besselI($maximumOrder, $x));
        } else {
            $slope = 0.5 * (
                SpecialFunctions::besselI($integerOrder - 1, $x)
                + SpecialFunctions::besselI($integerOrder + 1, $x)
            );
        }
        $derivative = $slope * $argument[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $order
     * @param array{0: float, 1: float} $argument
     */
    private function besselYDerivative(array $order, array $argument): ?float
    {
        if (
            $order[1] !== 0.0
            || \floor($order[0]) !== $order[0]
            || \abs($order[0]) > SpecialFunctions::MAX_BESSEL_ORDER
            || $argument[0] <= 0.0
        ) {
            return null;
        }

        $integerOrder = (int) $order[0];
        $x = $argument[0];
        try {
            if ($integerOrder === -SpecialFunctions::MAX_BESSEL_ORDER) {
                $slope = -SpecialFunctions::besselY($integerOrder + 1, $x)
                    + (($integerOrder / $x) * SpecialFunctions::besselY($integerOrder, $x));
            } else {
                $slope = SpecialFunctions::besselY($integerOrder - 1, $x)
                    - (($integerOrder / $x) * SpecialFunctions::besselY($integerOrder, $x));
            }
        } catch (\DomainException) {
            return null;
        }

        $derivative = $slope * $argument[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $value
     * @param array{0: float, 1: float} $base
     */
    private function logarithmDerivative(array $value, array $base): ?float
    {
        if ($value[0] <= 0.0 || $base[0] <= 0.0 || $base[0] === 1.0) {
            return null;
        }

        $logBase = \log($base[0]);
        $numerator = ($value[1] / $value[0]) * $logBase
            - (\log($value[0]) * ($base[1] / $base[0]));
        $denominator = $logBase * $logBase;
        $derivative = $numerator / $denominator;

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $radicand
     * @param array{0: float, 1: float} $degree
     */
    private function rootDerivative(array $radicand, array $degree, float $root): ?float
    {
        if ($degree[1] !== 0.0) {
            return null;
        }
        if ($radicand[0] === 0.0) {
            return $degree[0] === 1.0 ? $radicand[1] : null;
        }

        $derivative = ($root / ($degree[0] * $radicand[0])) * $radicand[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    private function sincSlope(float $value, float $phaseScale): float
    {
        $phase = $phaseScale * $value;
        if (\abs($phase) < 1.0E-3) {
            $square = $phase * $phase;
            $fourth = $square * $square;
            $sixth = $fourth * $square;

            return $phaseScale * (
                -($phase / 3.0)
                + ($phase * $square / 30.0)
                - ($phase * $fourth / 840.0)
                + ($phase * $sixth / 45360.0)
            );
        }

        return $phaseScale * ((\cos($phase) - (\sin($phase) / $phase)) / $phase);
    }

    private function lambertWSlope(float $argument, float $value, int $branch): ?float
    {
        if ($argument === 0.0) {
            return $branch === 0 ? 1.0 : null;
        }
        if ($value === -1.0) {
            return null;
        }

        $derivative = $value / ($argument * (1.0 + $value));

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $argument
     * @param array{0: float, 1: float} $branch
     */
    private function lambertWDerivative(array $argument, array $branch, float $value): ?float
    {
        if (
            $branch[1] !== 0.0
            || ($branch[0] !== 0.0 && $branch[0] !== -1.0)
        ) {
            return null;
        }
        $slope = $this->lambertWSlope($argument[0], $value, (int) $branch[0]);
        if ($slope === null) {
            return null;
        }

        $derivative = $slope * $argument[1];

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $first
     * @param array{0: float, 1: float} $second
     */
    private function hypotenuseDerivative(array $first, array $second): ?float
    {
        if ($first[0] === 0.0 && $second[0] === 0.0) {
            if ($first[1] !== 0.0 || $second[1] !== 0.0) {
                throw new NondifferentiablePointException('hypot() is not differentiable at a first-order zero.');
            }

            return 0.0;
        }

        $scale = \hypot($first[0], $second[0]);
        $derivative = (($first[0] / $scale) * $first[1])
            + (($second[0] / $scale) * $second[1]);

        return \is_finite($derivative) ? $derivative : null;
    }

    /**
     * @param array{0: float, 1: float} $y
     * @param array{0: float, 1: float} $x
     */
    private function arcTangentTwoDerivative(array $y, array $x): ?float
    {
        if ($x[0] === 0.0 && $y[0] === 0.0) {
            throw new NondifferentiablePointException('atan2() has no derivative at the origin.');
        }

        $scale = \max(\abs($x[0]), \abs($y[0]));
        $scaledX = $x[0] / $scale;
        $scaledY = $y[0] / $scale;
        $denominator = ($scaledX * $scaledX) + ($scaledY * $scaledY);
        $numerator = ($scaledX * $y[1]) - ($scaledY * $x[1]);
        $derivative = ($numerator / $scale) / $denominator;

        return \is_finite($derivative) ? $derivative : null;
    }

    private function absoluteSlope(float $value, float $derivative): float
    {
        if ($value === 0.0) {
            if ($derivative !== 0.0) {
                throw new NondifferentiablePointException('abs() is not differentiable at a first-order zero.');
            }

            return 0.0;
        }

        return $value < 0.0 ? -1.0 : 1.0;
    }

    private function piecewiseConstantSlope(string $function, float $value): float
    {
        $isJump = match ($function) {
            'sign' => $value === 0.0,
            'floor', 'ceil', 'ceiling' => $value === \floor($value),
            'trunc', 'truncate' => $value !== 0.0 && $value === \floor($value),
            'round' => ($value - \floor($value)) === 0.5,
            default => false,
        };
        if ($isJump) {
            throw new NondifferentiablePointException(
                $function . '() is not differentiable at a jump boundary.',
            );
        }

        return 0.0;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     */
    private function differentiateMinMax(
        FunctionCallNode $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (!$options->functions->isBuiltIn($node->name)) {
            return null;
        }

        $argumentValues = [];
        foreach ($node->arguments as $argumentNode) {
            $argumentValue = $this->evaluateAt(
                $argumentNode,
                $variable,
                $at,
                $parameters,
                $options,
            );
            if ($argumentValue === null) {
                return null;
            }
            $argumentValues[] = $argumentValue;
        }

        $value = $this->evaluateAt($node, $variable, $at, $parameters, $options);
        if ($value === null) {
            return null;
        }

        $derivative = null;
        foreach ($node->arguments as $index => $argumentNode) {
            if ($argumentValues[$index] !== $value) {
                continue;
            }
            try {
                $argument = $this->differentiateNode(
                    $argumentNode,
                    $variable,
                    $at,
                    $parameters,
                    $options,
                );
            } catch (\DomainException) {
                return [$value, $this->differentiateAtFiniteEndpoint(
                    $node,
                    $variable,
                    $at,
                    $parameters,
                    $options,
                    $value,
                    true,
                )];
            }
            if ($argument === null) {
                return null;
            }
            $argumentDerivative = $argument[1];
            if ($derivative !== null && $argumentDerivative !== $derivative) {
                throw new NondifferentiablePointException(
                    $node->name . '() is not differentiable where active arguments tie with different slopes.',
                );
            }
            $derivative = $argumentDerivative;
        }

        return $derivative === null ? null : [$value, $derivative];
    }

    private function hyperbolicTangentSlope(float $value): float
    {
        $exponential = \exp(-2.0 * \abs($value));
        $denominator = 1.0 + $exponential;

        return (4.0 * $exponential) / ($denominator * $denominator);
    }

    private function hyperbolicCosecant(float $value): float
    {
        if (\abs($value) < 1.0) {
            return 1.0 / \sinh($value);
        }

        $exponential = \exp(-\abs($value));
        $result = (2.0 * $exponential) / (1.0 - ($exponential * $exponential));

        return $value < 0.0 ? -$result : $result;
    }

    private function hyperbolicCotangent(float $value): float
    {
        if (\abs($value) < 1.0) {
            return \cosh($value) / \sinh($value);
        }

        $exponential = \exp(-2.0 * \abs($value));
        $result = (1.0 + $exponential) / (1.0 - $exponential);

        return $value < 0.0 ? -$result : $result;
    }

    private function inverseSecantSlope(float $value): float
    {
        $absolute = \abs($value);
        if ($absolute <= 1.0) {
            return \INF;
        }
        if ($absolute > 2.0) {
            $reciprocal = 1.0 / $absolute;

            return ($reciprocal / $absolute)
                / \sqrt((1.0 - $reciprocal) * (1.0 + $reciprocal));
        }

        return 1.0 / ($absolute * \sqrt(($absolute - 1.0) * ($absolute + 1.0)));
    }

    private function inverseSechSlope(float $value): float
    {
        if ($value <= 0.0 || $value >= 1.0) {
            return \INF;
        }

        return -1.0 / ($value * \sqrt((1.0 - $value) * (1.0 + $value)));
    }

    private function inverseCschSlope(float $value): float
    {
        $absolute = \abs($value);
        if ($absolute > 1.0) {
            return -(1.0 / $absolute) / \hypot(1.0, $value);
        }

        return -1.0 / ($absolute * \hypot(1.0, $value));
    }

    private function inverseCothSlope(float $value): float
    {
        $absolute = \abs($value);
        if ($absolute <= 1.0) {
            return \INF;
        }
        if ($absolute > 2.0) {
            $reciprocal = 1.0 / $value;

            return -(($reciprocal / $value) / (1.0 - ($reciprocal * $reciprocal)));
        }

        return 1.0 / ((1.0 - $value) * (1.0 + $value));
    }

    private function inverseCoshSlope(float $value): float
    {
        if ($value <= 1.0) {
            return \INF;
        }
        if ($value > 2.0) {
            $reciprocal = 1.0 / $value;

            return $reciprocal / \sqrt((1.0 - $reciprocal) * (1.0 + $reciprocal));
        }

        return 1.0 / \sqrt(($value - 1.0) * ($value + 1.0));
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function evaluateAt(
        Node $node,
        string $variable,
        float $at,
        array $parameters,
        EvaluationOptions $options,
    ): ?float {
        try {
            $value = (float) (new Evaluator(
                new Environment([...$parameters, $variable => $at], $options->functions),
                $options->limits,
            ))->evaluate($node);
        } catch (EvaluationException $error) {
            if (
                \in_array(
                    $error->errorCode(),
                    ['eval.unknown_variable', 'eval.unknown_function', 'eval.arity', 'eval.custom_function'],
                    true,
                )
            ) {
                throw $error;
            }

            return null;
        }

        return \is_finite($value) ? $value : null;
    }

    private function containsVariable(Node $node, string $variable): bool
    {
        if ($node instanceof VariableNode) {
            return $node->name === $variable;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsVariable($node->expression, $variable);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsVariable($node->operand, $variable);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariable($node->left, $variable)
                || $this->containsVariable($node->right, $variable);
        }
        if ($node instanceof FactorialNode) {
            return $this->containsVariable($node->operand, $variable);
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsVariable($argument, $variable)) {
                    return true;
                }
            }
        }

        return false;
    }
}
