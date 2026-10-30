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
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;

/** Bounded sparse polynomial extraction in two variables using floating coefficients. */
final readonly class BivariatePolynomialExtractor
{
    private const MAX_EQUAL_DEGREE_ROOT_NORM = 8;

    private const MAX_ADDITIVE_FRACTIONAL_TERMS = 32;

    /**
     * @param array<string, int|float> $parameters
     */
    public function __construct(
        private string $firstVariable,
        private string $secondVariable,
        private array $parameters,
        private EvaluationOptions $options,
        private int $maxTotalDegree = 8,
    ) {
    }

    /** @return array<int, list<float>>|null Coefficients indexed by degree in the second variable. */
    public function difference(Node $left, Node $right): ?array
    {
        $leftRational = $this->extractRationalSide($left);
        $rightRational = $this->extractRationalSide($right);
        if ($leftRational !== null && $rightRational !== null) {
            [$leftNumerator, $leftDenominator] = $leftRational;
            [$rightNumerator, $rightDenominator] = $rightRational;
            $leftProduct = $this->multiply($leftNumerator, $rightDenominator);
            $rightProduct = $this->multiply($rightNumerator, $leftDenominator);
            if ($leftProduct === null || $rightProduct === null) {
                return null;
            }
            $difference = $this->combine($leftProduct, $rightProduct, -1.0);
        } elseif (($additiveFractionalDifference = $this->additiveFractionalPowerDifference($left, $right)) !== null) {
            $difference = $additiveFractionalDifference;
        } else {
            $leftAbsoluteValue = $this->absoluteValueArgument($left);
            $rightAbsoluteValue = $this->absoluteValueArgument($right);
            if ($leftAbsoluteValue !== null || $rightAbsoluteValue !== null) {
                $leftPolynomial = $this->extract($leftAbsoluteValue ?? $left);
                $rightPolynomial = $this->extract($rightAbsoluteValue ?? $right);
                $leftSquare = $leftPolynomial === null
                    ? null
                    : $this->polynomialPower($leftPolynomial, 2);
                $rightSquare = $rightPolynomial === null
                    ? null
                    : $this->polynomialPower($rightPolynomial, 2);
                $difference = $leftSquare === null || $rightSquare === null
                    ? null
                    : $this->combine($leftSquare, $rightSquare, -1.0);
            } else {
                $leftSign = $this->signArgument($left);
                $rightSign = $this->signArgument($right);
                if ($leftSign !== null && $rightSign !== null) {
                    return null;
                }
                if ($leftSign !== null || $rightSign !== null) {
                    $signArgument = $leftSign ?? $rightSign;
                    $otherSide = $leftSign !== null ? $right : $left;
                    $otherPolynomial = $this->extract($otherSide);
                    if ($otherPolynomial === null) {
                        return null;
                    }
                    if (\count($otherPolynomial) === 1 && isset($otherPolynomial['0:0'])) {
                        $constant = $otherPolynomial['0:0'];
                        if ($constant === 0.0) {
                            $difference = $this->extract($signArgument);
                        } elseif ($constant === -1.0 || $constant === 1.0) {
                            return null;
                        } else {
                            $difference = ['0:0' => 1.0];
                        }
                    } else {
                        $signConstraint = $this->polynomialPower($otherPolynomial, 3);
                        $difference = $signConstraint === null
                            ? null
                            : $this->combine($signConstraint, $otherPolynomial, -1.0);
                    }
                } else {
                    $leftFractionalPower = $this->fractionalPowerExpression($left);
                    $rightFractionalPower = $this->fractionalPowerExpression($right);
                    if ($leftFractionalPower !== null && $rightFractionalPower !== null) {
                        return null;
                    }
                    if ($leftFractionalPower !== null || $rightFractionalPower !== null) {
                        $fractionalPower = $leftFractionalPower ?? $rightFractionalPower;
                        $otherSide = $leftFractionalPower !== null ? $right : $left;
                        [$base, $numerator, $denominator] = $fractionalPower;
                        $basePolynomial = $this->extract($base);
                        $otherPolynomial = $this->extract($otherSide);
                        $basePower = $basePolynomial === null
                            ? null
                            : $this->polynomialPower($basePolynomial, \abs($numerator));
                        $otherPower = $otherPolynomial === null
                            ? null
                            : $this->polynomialPower($otherPolynomial, $denominator);
                        if ($basePower === null || $otherPower === null) {
                            $difference = null;
                        } elseif ($numerator > 0) {
                            $difference = $leftFractionalPower !== null
                                ? $this->combine($basePower, $otherPower, -1.0)
                                : $this->combine($otherPower, $basePower, -1.0);
                        } else {
                            $product = $this->multiply($basePower, $otherPower);
                            $difference = $product === null
                                ? null
                                : $this->combine($product, ['0:0' => 1.0], -1.0);
                        }
                    } else {
                        $leftRoot = $this->polynomialRootCall($left);
                        $rightRoot = $this->polynomialRootCall($right);
                        if ($leftRoot !== null && $rightRoot !== null) {
                            [$leftRadicand, $leftDegree] = $leftRoot;
                            [$rightRadicand, $rightDegree] = $rightRoot;
                            $commonDegree = $this->greatestCommonDivisor(\abs($leftDegree), \abs($rightDegree));
                            $leftPower = \intdiv(\abs($rightDegree), $commonDegree);
                            $rightPower = \intdiv(\abs($leftDegree), $commonDegree);
                            $leftPolynomial = $this->extract($leftRadicand);
                            $rightPolynomial = $this->extract($rightRadicand);
                            $leftPowerPolynomial = $leftPolynomial === null
                            ? null
                            : $this->polynomialPower($leftPolynomial, $leftPower);
                            $rightPowerPolynomial = $rightPolynomial === null
                            ? null
                            : $this->polynomialPower($rightPolynomial, $rightPower);
                            if ($leftPowerPolynomial === null || $rightPowerPolynomial === null) {
                                $difference = null;
                            } elseif (($leftDegree < 0) === ($rightDegree < 0)) {
                                $difference = $this->combine($leftPowerPolynomial, $rightPowerPolynomial, -1.0);
                            } else {
                                $product = $this->multiply($leftPowerPolynomial, $rightPowerPolynomial);
                                $difference = $product === null
                                ? null
                                : $this->combine($product, ['0:0' => 1.0], -1.0);
                            }
                        } elseif ($leftRoot !== null) {
                            [$leftRadicand, $leftDegree] = $leftRoot;
                            $leftPolynomial = $this->extract($leftRadicand);
                            $rightPolynomial = $this->extract($right);
                            $rightPower = $rightPolynomial === null
                            ? null
                            : $this->polynomialPower($rightPolynomial, \abs($leftDegree));
                            if ($leftPolynomial === null || $rightPower === null) {
                                $difference = null;
                            } elseif ($leftDegree > 0) {
                                $difference = $this->combine($leftPolynomial, $rightPower, -1.0);
                            } else {
                                $product = $this->multiply($leftPolynomial, $rightPower);
                                $difference = $product === null
                                ? null
                                : $this->combine($product, ['0:0' => 1.0], -1.0);
                            }
                        } elseif ($rightRoot !== null) {
                            [$rightRadicand, $rightDegree] = $rightRoot;
                            $leftPolynomial = $this->extract($left);
                            $rightPolynomial = $this->extract($rightRadicand);
                            $leftPower = $leftPolynomial === null
                            ? null
                            : $this->polynomialPower($leftPolynomial, \abs($rightDegree));
                            if ($leftPower === null || $rightPolynomial === null) {
                                $difference = null;
                            } elseif ($rightDegree > 0) {
                                $difference = $this->combine($leftPower, $rightPolynomial, -1.0);
                            } else {
                                $product = $this->multiply($leftPower, $rightPolynomial);
                                $difference = $product === null
                                ? null
                                : $this->combine($product, ['0:0' => 1.0], -1.0);
                            }
                        } else {
                            return null;
                        }
                    }
                }
            }
        }
        if ($difference === null) {
            return null;
        }

        $bySecondDegree = [];
        foreach ($difference as $monomial => $coefficient) {
            if ($coefficient === 0.0) {
                continue;
            }
            [$firstDegree, $secondDegree] = \array_map('intval', \explode(':', $monomial));
            $bySecondDegree[$secondDegree][$firstDegree] = $coefficient;
        }
        if ($bySecondDegree === []) {
            return [[0.0]];
        }
        $denseBySecondDegree = [];
        $maximumSecondDegree = \max(\array_keys($bySecondDegree));
        for ($secondDegree = 0; $secondDegree <= $maximumSecondDegree; ++$secondDegree) {
            $sparseCoefficients = $bySecondDegree[$secondDegree] ?? [];
            if ($sparseCoefficients === []) {
                $denseBySecondDegree[$secondDegree] = [0.0];
                continue;
            }
            \ksort($sparseCoefficients);
            $degree = (int) \array_key_last($sparseCoefficients);
            $dense = [];
            for ($firstDegree = 0; $firstDegree <= $degree; ++$firstDegree) {
                $dense[] = $sparseCoefficients[$firstDegree] ?? 0.0;
            }
            $denseBySecondDegree[$secondDegree] = $dense;
        }

        $maximumMagnitude = 0.0;
        foreach ($denseBySecondDegree as $coefficients) {
            foreach ($coefficients as $coefficient) {
                $maximumMagnitude = \max($maximumMagnitude, \abs($coefficient));
            }
        }
        if ($maximumMagnitude === 0.0) {
            return [[0.0]];
        }
        foreach ($denseBySecondDegree as $secondDegree => $coefficients) {
            foreach ($coefficients as $firstDegree => $coefficient) {
                $normalizedCoefficient = $coefficient / $maximumMagnitude;
                if ($coefficient !== 0.0 && $normalizedCoefficient === 0.0) {
                    return null;
                }
                $denseBySecondDegree[$secondDegree][$firstDegree] = $normalizedCoefficient;
            }
        }

        return $denseBySecondDegree;
    }

    /** @return Node|null The argument of a top-level built-in abs call. */
    private function absoluteValueArgument(Node $node): ?Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            !$node instanceof FunctionCallNode
            || $node->name !== 'abs'
            || \count($node->arguments) !== 1
            || !$this->options->functions->isBuiltIn($node->name)
        ) {
            return null;
        }

        return $node->arguments[0];
    }

    /** @return Node|null The argument of a top-level built-in sign call. */
    private function signArgument(Node $node): ?Node
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            !$node instanceof FunctionCallNode
            || $node->name !== 'sign'
            || \count($node->arguments) !== 1
            || !$this->options->functions->isBuiltIn($node->name)
        ) {
            return null;
        }

        return $node->arguments[0];
    }

    /** @return array{Node, int}|null A top-level built-in nonzero integer root call. */
    private function polynomialRootCall(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$node instanceof FunctionCallNode || !$this->options->functions->isBuiltIn($node->name)) {
            return null;
        }
        if ($node->name === 'sqrt' && \count($node->arguments) === 1) {
            return [$node->arguments[0], 2];
        }
        if ($node->name === 'cbrt' && \count($node->arguments) === 1) {
            return [$node->arguments[0], 3];
        }
        if ($node->name !== 'root' || \count($node->arguments) !== 2) {
            return null;
        }
        $degree = $this->extract($node->arguments[1]);
        if (
            $degree === null
            || \count($degree) !== 1
            || !isset($degree['0:0'])
            || \floor($degree['0:0']) !== $degree['0:0']
            || $degree['0:0'] === 0.0
            || \abs($degree['0:0']) < 1.0
            || $degree['0:0'] > $this->maxTotalDegree
            || $degree['0:0'] < -$this->maxTotalDegree
        ) {
            return null;
        }

        return [$node->arguments[0], (int) $degree['0:0']];
    }

    private function greatestCommonDivisor(int $first, int $second): int
    {
        while ($second !== 0) {
            [$first, $second] = [$second, $first % $second];
        }

        return $first;
    }

    /** @return array{Node, int, int}|null Base, reduced exponent numerator, and denominator. */
    private function fractionalPower(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        $rootCall = $this->polynomialRootCall($node);
        if ($rootCall !== null) {
            [$radicand, $degree] = $rootCall;
            if (\abs($degree) > 1 && \abs($degree) <= $this->maxTotalDegree) {
                return [$radicand, $degree < 0 ? -1 : 1, \abs($degree)];
            }
        }
        if (!$node instanceof BinaryOperationNode || $node->operator !== BinaryOperator::Power) {
            return null;
        }
        $fraction = RationalLiteral::fromNode($node->right);
        if (
            $fraction === null
            || $fraction[1] === 1
            || $fraction[0] === 0
            || \abs($fraction[0]) > $this->maxTotalDegree
            || $fraction[1] > $this->maxTotalDegree
            || (\abs($fraction[0]) / $fraction[1]) > $this->options->limits->maxExponentMagnitude
        ) {
            return null;
        }

        return [$node->left, $fraction[0], $fraction[1]];
    }

    /** @return array{Node, int, int}|null An explicit rational-exponent power expression. */
    private function fractionalPowerExpression(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$node instanceof BinaryOperationNode || $node->operator !== BinaryOperator::Power) {
            return null;
        }

        return $this->fractionalPower($node);
    }

    /**
     * @return array{array{Node, int, int}, array<string, float>}|null
     *     Fractional power and polynomial multiplier.
     */
    private function fractionalPowerTerm(Node $node): ?array
    {
        if (!$this->containsSolvedVariable($node)) {
            return null;
        }
        $fractionalPower = $this->fractionalPower($node);
        if ($fractionalPower !== null) {
            return [$fractionalPower, ['0:0' => 1.0]];
        }
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if ($node->operator === BinaryOperator::Multiply) {
            $leftTerm = $this->fractionalPowerTerm($node->left);
            $rightTerm = $this->fractionalPowerTerm($node->right);
            if ($leftTerm !== null && $rightTerm === null) {
                $coefficient = $this->extract($node->right);
                $combinedCoefficient = $coefficient === null
                    ? null
                    : $this->multiply($leftTerm[1], $coefficient);

                return $combinedCoefficient === null || !$this->hasNonzeroCoefficient($combinedCoefficient)
                    ? null
                    : [$leftTerm[0], $combinedCoefficient];
            }
            if ($rightTerm !== null && $leftTerm === null) {
                $coefficient = $this->extract($node->left);
                $combinedCoefficient = $coefficient === null
                    ? null
                    : $this->multiply($rightTerm[1], $coefficient);

                return $combinedCoefficient === null || !$this->hasNonzeroCoefficient($combinedCoefficient)
                    ? null
                    : [$rightTerm[0], $combinedCoefficient];
            }

            return null;
        }
        if ($node->operator === BinaryOperator::Divide) {
            $fractionalPower = $this->fractionalPower($node->left);
            $denominator = $this->constantValue($node->right);
            if ($fractionalPower === null || $denominator === null || $denominator === 0.0) {
                return null;
            }
            $coefficient = 1.0 / $denominator;

            return \is_finite($coefficient)
                ? [$fractionalPower, ['0:0' => $coefficient]]
                : null;
        }

        return null;
    }

    private function constantValue(Node $node): ?float
    {
        if ($this->containsSolvedVariable($node)) {
            return null;
        }
        $constant = $this->extract($node);

        return $constant !== null && \count($constant) === 1 && isset($constant['0:0'])
            ? $constant['0:0']
            : null;
    }

    /** @return array<string, float>|null Polynomial relation for one additive fractional-power term. */
    private function additiveFractionalPowerDifference(Node $left, Node $right): ?array
    {
        $cleared = $this->clearRadicalPolynomialDenominator($left, $right);
        if ($cleared !== null) {
            return $this->additiveFractionalPowerDifference($cleared[0], $cleared[1]);
        }

        $terms = [];
        $this->appendAdditiveTerms($left, 1.0, $terms);
        $this->appendAdditiveTerms($right, -1.0, $terms);

        /** @var list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms */
        $fractionalTerms = [];
        $remainder = [];
        foreach ($terms as [$term, $scale]) {
            $fractionalPowerTerm = $this->fractionalPowerTerm($term);
            if ($fractionalPowerTerm !== null) {
                if (\count($fractionalTerms) >= self::MAX_ADDITIVE_FRACTIONAL_TERMS) {
                    return null;
                }
                [$fraction, $coefficient] = $fractionalPowerTerm;
                $effectiveCoefficient = $this->scale($coefficient, $scale);
                if (
                    $effectiveCoefficient === null
                    || !$this->hasNonzeroCoefficient($effectiveCoefficient)
                ) {
                    return null;
                }
                $fractionalTerms[] = [$fraction, $effectiveCoefficient];
                continue;
            }
            $polynomial = $this->extract($term);
            if ($polynomial === null) {
                return null;
            }
            $scaledPolynomial = $this->scale($polynomial, $scale);
            if ($scaledPolynomial === null) {
                return null;
            }
            $combinedRemainder = $this->combine($remainder, $scaledPolynomial, 1.0);
            if ($combinedRemainder === null) {
                return null;
            }
            $remainder = $combinedRemainder;
        }
        if ($fractionalTerms === []) {
            return null;
        }

        /** @var list<array{array{Node, int, int}, array<string, float>}> $combinedFractionalTerms */
        $combinedFractionalTerms = [];
        /** @var array<string, int> $fractionalTermIndexes */
        $fractionalTermIndexes = [];
        foreach ($fractionalTerms as [$fraction, $coefficient]) {
            [$base, $numerator, $denominator] = $fraction;
            $basePolynomial = $this->extract($base);
            if ($basePolynomial === null) {
                return null;
            }
            $basePolynomial = \array_filter(
                $basePolynomial,
                static fn (float $coefficient): bool => $coefficient !== 0.0,
            );
            \ksort($basePolynomial);
            $key = $numerator . ':' . $denominator . ':' . \serialize($basePolynomial);
            if (!isset($fractionalTermIndexes[$key])) {
                $fractionalTermIndexes[$key] = \count($combinedFractionalTerms);
                $combinedFractionalTerms[] = [$fraction, $coefficient];
                continue;
            }
            $index = $fractionalTermIndexes[$key];
            $combinedCoefficient = $this->combine($combinedFractionalTerms[$index][1], $coefficient, 1.0);
            if ($combinedCoefficient === null) {
                return null;
            }
            $combinedFractionalTerms[$index][1] = $combinedCoefficient;
        }
        /** @var list<array{array{Node, int, int}, array<string, float>}> $nonzeroFractionalTerms */
        $nonzeroFractionalTerms = [];
        foreach ($combinedFractionalTerms as $combinedTerm) {
            if (!isset($combinedTerm[0])) {
                return null;
            }
            [$fraction, $coefficient] = $combinedTerm;
            if ($this->hasNonzeroCoefficient($coefficient)) {
                $nonzeroFractionalTerms[] = [$fraction, $coefficient];
            }
        }
        $fractionalTerms = $nonzeroFractionalTerms;
        if ($fractionalTerms === []) {
            return ['0:0' => 0.0];
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && $fractionalTerms[0][0][2] === 3
            && $fractionalTerms[1][0][1] === 1
            && $fractionalTerms[1][0][2] === 3
        ) {
            return $this->twoCubeRootDifference($fractionalTerms, $remainder);
        }

        $threeCubeRoots = \count($fractionalTerms) === 3;
        foreach ($fractionalTerms as [$fraction]) {
            $threeCubeRoots = $threeCubeRoots && $fraction[1] === 1 && $fraction[2] === 3;
        }
        if ($threeCubeRoots) {
            return $this->threeCubeRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && $fractionalTerms[0][0][2] === 4
            && $fractionalTerms[1][0][1] === 1
            && $fractionalTerms[1][0][2] === 4
        ) {
            return $this->twoFourthRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] > 0
            && $fractionalTerms[1][0][1] > 0
            && $fractionalTerms[0][0][2] === $fractionalTerms[1][0][2]
            && $fractionalTerms[0][0][2] >= 2
            && $fractionalTerms[0][0][2] <= self::MAX_EQUAL_DEGREE_ROOT_NORM
        ) {
            return $this->twoRootNormDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && \in_array($fractionalTerms[0][0][2], [2, 4], true)
            && $fractionalTerms[1][0][1] === 1
            && \in_array($fractionalTerms[1][0][2], [2, 4], true)
            && $fractionalTerms[0][0][2] !== $fractionalTerms[1][0][2]
        ) {
            return $this->mixedSquareAndFourthRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && \in_array($fractionalTerms[0][0][2], [2, 5], true)
            && $fractionalTerms[1][0][1] === 1
            && \in_array($fractionalTerms[1][0][2], [2, 5], true)
            && $fractionalTerms[0][0][2] !== $fractionalTerms[1][0][2]
        ) {
            return $this->mixedSquareAndFifthRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && \in_array($fractionalTerms[0][0][2], [3, 4], true)
            && $fractionalTerms[1][0][1] === 1
            && \in_array($fractionalTerms[1][0][2], [3, 4], true)
            && $fractionalTerms[0][0][2] !== $fractionalTerms[1][0][2]
        ) {
            return $this->mixedCubeAndFourthRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] === 1
            && \in_array($fractionalTerms[0][0][2], [2, 3], true)
            && $fractionalTerms[1][0][1] === 1
            && \in_array($fractionalTerms[1][0][2], [2, 3], true)
            && $fractionalTerms[0][0][2] !== $fractionalTerms[1][0][2]
        ) {
            return $this->mixedSquareAndCubeRootDifference($fractionalTerms, $remainder);
        }

        if (
            \count($fractionalTerms) === 2
            && $fractionalTerms[0][0][1] > 0
            && $fractionalTerms[1][0][1] > 0
            && $fractionalTerms[0][0][2] >= 2
            && $fractionalTerms[0][0][2] <= self::MAX_EQUAL_DEGREE_ROOT_NORM
            && $fractionalTerms[1][0][2] >= 2
            && $fractionalTerms[1][0][2] <= self::MAX_EQUAL_DEGREE_ROOT_NORM
        ) {
            return $this->twoRootNormDifference($fractionalTerms, $remainder);
        }

        if (\count($fractionalTerms) === 2) {
            [[$firstBase, $firstNumerator, $firstDenominator], $firstCoefficient] = $fractionalTerms[0];
            [[$secondBase, $secondNumerator, $secondDenominator], $secondCoefficient] = $fractionalTerms[1];
            if (
                $firstNumerator !== 1
                || $firstDenominator !== 2
                || $secondNumerator !== 1
                || $secondDenominator !== 2
            ) {
                return null;
            }
            $firstPolynomial = $this->extract($firstBase);
            $secondPolynomial = $this->extract($secondBase);
            $targetPolynomial = $this->scale($remainder, -1.0);
            if ($firstPolynomial === null || $secondPolynomial === null || $targetPolynomial === null) {
                return null;
            }
            $firstCoefficientSquared = $this->polynomialPower($firstCoefficient, 2);
            $secondCoefficientSquared = $this->polynomialPower($secondCoefficient, 2);
            $firstTerm = $firstCoefficientSquared === null
                ? null
                : $this->multiply($firstPolynomial, $firstCoefficientSquared);
            $secondTerm = $secondCoefficientSquared === null
                ? null
                : $this->multiply($secondPolynomial, $secondCoefficientSquared);
            $product = $this->multiply($firstPolynomial, $secondPolynomial);
            $coefficientProduct = $firstCoefficientSquared === null || $secondCoefficientSquared === null
                ? null
                : $this->multiply($firstCoefficientSquared, $secondCoefficientSquared);
            $scaledProduct = $product === null || $coefficientProduct === null
                ? null
                : $this->multiply($product, $coefficientProduct);
            $scaledProduct = $scaledProduct === null ? null : $this->scale($scaledProduct, 4.0);
            if ($firstTerm === null || $secondTerm === null || $scaledProduct === null) {
                return null;
            }
            $targetSquare = $this->polynomialPower($targetPolynomial, 2);
            if ($targetSquare === null) {
                return null;
            }
            $sum = $this->combine($targetSquare, $firstTerm, -1.0);
            $sum = $sum === null ? null : $this->combine($sum, $secondTerm, -1.0);
            $sumSquare = $sum === null ? null : $this->polynomialPower($sum, 2);

            return $sumSquare === null
                ? null
                : $this->combine($sumSquare, $scaledProduct, -1.0);
        }

        if (\count($fractionalTerms) === 3) {
            return $this->threeSquareRootDifference($fractionalTerms, $remainder);
        }

        [[$base, $numerator, $denominator], $coefficient] = $fractionalTerms[0];
        $basePolynomial = $this->extract($base);
        $targetPolynomial = $this->scale($remainder, -1.0);
        if ($basePolynomial === null || $targetPolynomial === null) {
            return null;
        }
        $basePower = $this->polynomialPower($basePolynomial, \abs($numerator));
        $targetPower = $this->polynomialPower($targetPolynomial, $denominator);
        $coefficientPower = $this->polynomialPower($coefficient, $denominator);
        if ($basePower === null || $targetPower === null || $coefficientPower === null) {
            return null;
        }
        if ($numerator > 0) {
            $scaledBasePower = $this->multiply($basePower, $coefficientPower);

            return $scaledBasePower === null
                ? null
                : $this->combine($scaledBasePower, $targetPower, -1.0);
        }
        $product = $this->multiply($basePower, $targetPower);
        return $product === null ? null : $this->combine($product, $coefficientPower, -1.0);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function twoCubeRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        [[$firstBase], $firstCoefficient] = $fractionalTerms[0];
        [[$secondBase], $secondCoefficient] = $fractionalTerms[1];
        $first = $this->extract($firstBase);
        $second = $this->extract($secondBase);
        $target = $this->scale($remainder, -1.0);
        if ($first === null || $second === null || $target === null) {
            return null;
        }

        $firstCoefficientCube = $this->polynomialPower($firstCoefficient, 3);
        $secondCoefficientCube = $this->polynomialPower($secondCoefficient, 3);
        $firstCube = $firstCoefficientCube === null
            ? null
            : $this->multiply($first, $firstCoefficientCube);
        $secondCube = $secondCoefficientCube === null
            ? null
            : $this->multiply($second, $secondCoefficientCube);
        $targetCube = $this->polynomialPower($target, 3);
        if ($firstCube === null || $secondCube === null || $targetCube === null) {
            return null;
        }

        $difference = $this->combine($targetCube, $firstCube, -1.0);
        $difference = $difference === null ? null : $this->combine($difference, $secondCube, -1.0);
        $differenceCube = $difference === null ? null : $this->polynomialPower($difference, 3);
        $radicandProduct = $this->multiply($firstCube, $secondCube);
        $targetProduct = $radicandProduct === null ? null : $this->multiply($radicandProduct, $targetCube);
        $scaledTargetProduct = $targetProduct === null ? null : $this->scale($targetProduct, 27.0);

        return $differenceCube === null || $scaledTargetProduct === null
            ? null
            : $this->combine($differenceCube, $scaledTargetProduct, -1.0);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function threeCubeRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        $radicands = [];
        foreach ($fractionalTerms as [[$base], $coefficient]) {
            $radicand = $this->extract($base);
            $coefficientCube = $this->polynomialPower($coefficient, 3);
            $term = $radicand === null || $coefficientCube === null
                ? null
                : $this->multiply($radicand, $coefficientCube);
            if ($term === null) {
                return null;
            }
            $radicands[] = $term;
        }

        [$first, $second, $third] = $radicands;
        $target = $this->scale($remainder, -1.0);
        if ($target === null) {
            return null;
        }

        $targetInThirdRoot = [$target, ['0:0' => -1.0]];
        $squaredTarget = $this->multiplyAlgebraicPolynomials($targetInThirdRoot, $targetInThirdRoot);
        $cubedTarget = $squaredTarget === null
            ? null
            : $this->multiplyAlgebraicPolynomials($squaredTarget, $targetInThirdRoot);
        if ($cubedTarget === null) {
            return null;
        }

        $pairSum = $this->combine($first, $second, 1.0);
        $pairEquation = $pairSum === null
            ? null
            : $this->combineAlgebraicPolynomialConstant($cubedTarget, $pairSum, -1.0);
        if ($pairEquation === null) {
            return null;
        }
        $pairEquationCube = $this->multiplyAlgebraicPolynomials($pairEquation, $pairEquation);
        $pairEquationCube = $pairEquationCube === null
            ? null
            : $this->multiplyAlgebraicPolynomials($pairEquationCube, $pairEquation);
        $pairProduct = $this->multiply($first, $second);
        $scaledPairProduct = $pairProduct === null ? null : $this->scale($pairProduct, 27.0);
        $targetCubeProduct = $scaledPairProduct === null
            ? null
            : $this->multiplyAlgebraicPolynomialByBivariate($cubedTarget, $scaledPairProduct);
        $relation = $pairEquationCube === null || $targetCubeProduct === null
            ? null
            : $this->combineAlgebraicPolynomials($pairEquationCube, $targetCubeProduct, -1.0);
        if ($relation === null) {
            return null;
        }

        for ($degree = \count($relation) - 1; $degree >= 3; --$degree) {
            $coefficient = $relation[$degree];
            if (!$this->hasNonzeroCoefficient($coefficient)) {
                continue;
            }
            $reduced = $this->multiply($coefficient, $third);
            if ($reduced === null) {
                return null;
            }
            $combined = $this->combine($relation[$degree - 3] ?? [], $reduced, 1.0);
            if ($combined === null) {
                return null;
            }
            $relation[$degree - 3] = $combined;
            $relation[$degree] = [];
        }
        $relation = \array_slice($relation, 0, 3);
        while (\count($relation) < 3) {
            $relation[] = ['0:0' => 0.0];
        }
        $relation = \array_map(
            static fn (array $coefficient): array => $coefficient === [] ? ['0:0' => 0.0] : $coefficient,
            $relation,
        );
        [$constant, $linear, $quadratic] = $relation;
        $constantCubed = $this->polynomialPower($constant, 3);
        $constantLinearQuadratic = $this->multiply($constant, $linear);
        $constantLinearQuadratic = $constantLinearQuadratic === null
            ? null
            : $this->multiply($constantLinearQuadratic, $quadratic);
        $constantLinearQuadratic = $constantLinearQuadratic === null
            ? null
            : $this->multiply($constantLinearQuadratic, $third);
        $constantLinearQuadratic = $constantLinearQuadratic === null
            ? null
            : $this->scale($constantLinearQuadratic, -3.0);
        $quadraticCubed = $this->polynomialPower($quadratic, 3);
        $thirdSquared = $this->polynomialPower($third, 2);
        $quadraticCubedThirdSquared = $quadraticCubed === null || $thirdSquared === null
            ? null
            : $this->multiply($quadraticCubed, $thirdSquared);
        $linearCubed = $this->polynomialPower($linear, 3);
        $linearCubedThird = $linearCubed === null ? null : $this->multiply($linearCubed, $third);
        if (
            $constantCubed === null || $constantLinearQuadratic === null
            || $quadraticCubedThirdSquared === null || $linearCubedThird === null
        ) {
            return null;
        }

        $norm = $this->combine($constantCubed, $constantLinearQuadratic, 1.0);
        $norm = $norm === null ? null : $this->combine($norm, $quadraticCubedThirdSquared, 1.0);

        return $norm === null ? null : $this->combine($norm, $linearCubedThird, 1.0);
    }

    /**
     * @param list<array<string, float>> $left
     * @param list<array<string, float>> $right
     * @return list<array<string, float>>|null
     */
    private function multiplyAlgebraicPolynomials(array $left, array $right): ?array
    {
        $product = [];
        foreach ($left as $leftDegree => $leftCoefficient) {
            foreach ($right as $rightDegree => $rightCoefficient) {
                $term = $this->multiply($leftCoefficient, $rightCoefficient);
                if ($term === null) {
                    return null;
                }
                $degree = $leftDegree + $rightDegree;
                $combined = $this->combine($product[$degree] ?? [], $term, 1.0);
                if ($combined === null) {
                    return null;
                }
                $product[$degree] = $combined;
            }
        }
        \ksort($product);
        $denseProduct = [];
        for ($degree = 0, $maximum = (int) \array_key_last($product); $degree <= $maximum; ++$degree) {
            $denseProduct[] = $product[$degree] ?? [];
        }

        return $denseProduct;
    }

    /**
     * @param list<array<string, float>> $polynomial
     * @param array<string, float> $constant
     * @return list<array<string, float>>|null
     */
    private function combineAlgebraicPolynomialConstant(array $polynomial, array $constant, float $scale): ?array
    {
        $combined = $this->combine($polynomial[0] ?? [], $constant, $scale);
        if ($combined === null) {
            return null;
        }
        $polynomial[0] = $combined;
        return $polynomial;
    }

    /**
     * @param list<array<string, float>> $left
     * @param list<array<string, float>> $right
     * @return list<array<string, float>>|null
     */
    private function combineAlgebraicPolynomials(array $left, array $right, float $rightScale): ?array
    {
        $sum = [];
        for ($degree = 0, $maximum = \max(\count($left), \count($right)); $degree < $maximum; ++$degree) {
            $combined = $this->combine($left[$degree] ?? [], $right[$degree] ?? [], $rightScale);
            if ($combined === null) {
                return null;
            }
            $sum[$degree] = $combined;
        }
        return \array_values($sum);
    }

    /**
     * @param list<array<string, float>> $polynomial
     * @param array<string, float> $factor
     * @return list<array<string, float>>|null
     */
    private function multiplyAlgebraicPolynomialByBivariate(array $polynomial, array $factor): ?array
    {
        $product = [];
        foreach ($polynomial as $degree => $coefficient) {
            $term = $this->multiply($coefficient, $factor);
            if ($term === null) {
                return null;
            }
            $product[$degree] = $term;
        }
        return \array_values($product);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function twoFourthRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        [[$firstBase], $firstCoefficient] = $fractionalTerms[0];
        [[$secondBase], $secondCoefficient] = $fractionalTerms[1];
        $firstRadicand = $this->extract($firstBase);
        $secondRadicand = $this->extract($secondBase);
        $target = $this->scale($remainder, -1.0);
        if ($firstRadicand === null || $secondRadicand === null || $target === null) {
            return null;
        }

        $firstCoefficientFourth = $this->polynomialPower($firstCoefficient, 4);
        $secondCoefficientFourth = $this->polynomialPower($secondCoefficient, 4);
        $first = $firstCoefficientFourth === null
            ? null
            : $this->multiply($firstRadicand, $firstCoefficientFourth);
        $second = $secondCoefficientFourth === null
            ? null
            : $this->multiply($secondRadicand, $secondCoefficientFourth);
        $targetSquared = $this->polynomialPower($target, 2);
        $targetFourth = $targetSquared === null ? null : $this->polynomialPower($targetSquared, 2);
        $targetSixth = $targetFourth === null || $targetSquared === null
            ? null
            : $this->multiply($targetFourth, $targetSquared);
        if (
            $first === null
            || $second === null
            || $targetSquared === null
            || $targetFourth === null
            || $targetSixth === null
        ) {
            return null;
        }

        $constant = $this->combine($targetFourth, $first, -1.0);
        $constant = $constant === null ? null : $this->combine($constant, $second, -1.0);
        $constantSquare = $constant === null ? null : $this->polynomialPower($constant, 2);
        $eightTargetFourth = $this->scale($targetFourth, 8.0);
        $targetConstant = $eightTargetFourth === null || $constant === null
            ? null
            : $this->multiply($eightTargetFourth, $constant);
        $radicandProduct = $this->multiply($first, $second);
        $fourRadicandProduct = $radicandProduct === null ? null : $this->scale($radicandProduct, 4.0);
        $m = $constantSquare === null || $targetConstant === null || $fourRadicandProduct === null
            ? null
            : $this->combine($constantSquare, $targetConstant, -1.0);
        $m = $m === null || $fourRadicandProduct === null
            ? null
            : $this->combine($m, $fourRadicandProduct, -1.0);

        $eightTargetSixth = $this->scale($targetSixth, 8.0);
        $twoTargetSquared = $this->scale($targetSquared, 2.0);
        $twoTargetConstant = $twoTargetSquared === null || $constant === null
            ? null
            : $this->multiply($twoTargetSquared, $constant);
        $l = $eightTargetSixth === null || $twoTargetConstant === null
            ? null
            : $this->combine($eightTargetSixth, $twoTargetConstant, -1.0);
        $mSquare = $m === null ? null : $this->polynomialPower($m, 2);
        $mL = $m === null || $l === null ? null : $this->multiply($m, $l);
        $targetSquaredML = $mL === null ? null : $this->multiply($targetSquared, $mL);
        $scaledTargetSquaredML = $targetSquaredML === null ? null : $this->scale($targetSquaredML, 8.0);
        $lSquare = $l === null ? null : $this->polynomialPower($l, 2);
        $constantLSquare = $constant === null || $lSquare === null
            ? null
            : $this->multiply($constant, $lSquare);
        $scaledConstantLSquare = $constantLSquare === null ? null : $this->scale($constantLSquare, 8.0);
        $sum = $mSquare === null || $scaledTargetSquaredML === null
            ? null
            : $this->combine($mSquare, $scaledTargetSquaredML, 1.0);

        return $sum === null || $scaledConstantLSquare === null
            ? null
            : $this->combine($sum, $scaledConstantLSquare, 1.0);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function twoRootNormDifference(array $fractionalTerms, array $remainder): ?array
    {
        [[$firstBase, $firstNumerator, $firstDegree], $firstCoefficient] = $fractionalTerms[0];
        [[$secondBase, $secondNumerator, $secondDegree], $secondCoefficient] = $fractionalTerms[1];
        $firstRadicand = $this->extract($firstBase);
        $secondRadicand = $this->extract($secondBase);
        $target = $this->scale($remainder, -1.0);
        if (
            $firstDegree < 2
            || $firstDegree > self::MAX_EQUAL_DEGREE_ROOT_NORM
            || $secondDegree < 2
            || $secondDegree > self::MAX_EQUAL_DEGREE_ROOT_NORM
            || $firstNumerator < 1
            || $secondNumerator < 1
            || $firstRadicand === null
            || $secondRadicand === null
            || $target === null
        ) {
            return null;
        }

        $firstCoefficientPower = $this->polynomialPower($firstCoefficient, $firstDegree);
        $secondCoefficientPower = $this->polynomialPower($secondCoefficient, $secondDegree);
        $firstRadicandPower = $this->polynomialPower($firstRadicand, $firstNumerator);
        $secondRadicandPower = $this->polynomialPower($secondRadicand, $secondNumerator);
        $firstRootPower = $firstCoefficientPower === null || $firstRadicandPower === null
            ? null
            : $this->multiply($firstRadicandPower, $firstCoefficientPower);
        $secondRootPower = $secondCoefficientPower === null || $secondRadicandPower === null
            ? null
            : $this->multiply($secondRadicandPower, $secondCoefficientPower);
        if ($firstRootPower === null || $secondRootPower === null) {
            return null;
        }

        $linear = [$target, ['0:0' => -1.0]];
        $power = [['0:0' => 1.0]];
        for ($exponent = 0; $exponent < $secondDegree; ++$exponent) {
            $power = $this->multiplyAlgebraicPolynomials($power, $linear);
            if ($power === null) {
                return null;
            }
        }
        $constant = $this->combine($power[0] ?? [], $secondRootPower, -1.0);
        if ($constant === null) {
            return null;
        }
        $power[0] = $constant;

        $reduced = $this->reduceAlgebraicPolynomial($power, $firstDegree, $firstRootPower);

        return $reduced === null
            ? null
            : $this->algebraicPolynomialNorm($reduced, $firstDegree, $firstRootPower);
    }

    /**
     * @param list<array<string, float>> $polynomial
     * @param array<string, float> $radicand
     * @return list<array<string, float>>|null
     */
    private function reduceAlgebraicPolynomial(array $polynomial, int $rootDegree, array $radicand): ?array
    {
        for ($degree = \count($polynomial) - 1; $degree >= $rootDegree; --$degree) {
            $coefficient = $polynomial[$degree];
            if ($this->hasNonzeroCoefficient($coefficient)) {
                $reducedTerm = $this->multiply($coefficient, $radicand);
                if ($reducedTerm === null) {
                    return null;
                }
                $targetDegree = $degree - $rootDegree;
                $combined = $this->combine($polynomial[$targetDegree] ?? [], $reducedTerm, 1.0);
                if ($combined === null) {
                    return null;
                }
                $polynomial[$targetDegree] = $combined;
            }
            $polynomial[$degree] = ['0:0' => 0.0];
        }

        $polynomial = \array_slice($polynomial, 0, $rootDegree);
        while (\count($polynomial) < $rootDegree) {
            $polynomial[] = ['0:0' => 0.0];
        }
        foreach ($polynomial as $index => $coefficient) {
            if ($coefficient === []) {
                $polynomial[$index] = ['0:0' => 0.0];
            }
        }

        return $polynomial;
    }

    /**
     * @param list<array<string, float>> $polynomial
     * @param array<string, float> $radicand
     * @return array<string, float>|null
     */
    private function algebraicPolynomialNorm(array $polynomial, int $rootDegree, array $radicand): ?array
    {
        $matrix = [];
        for ($column = 0; $column < $rootDegree; ++$column) {
            $shifted = [...\array_fill(0, $column, ['0:0' => 0.0]), ...$polynomial];
            $reducedColumn = $this->reduceAlgebraicPolynomial($shifted, $rootDegree, $radicand);
            if ($reducedColumn === null) {
                return null;
            }
            foreach ($reducedColumn as $row => $coefficient) {
                $matrix[$row][$column] = $coefficient;
            }
        }

        $states = [0 => ['0:0' => 1.0]];
        $fullMask = (1 << $rootDegree) - 1;
        for ($row = 0; $row < $rootDegree; ++$row) {
            $nextStates = [];
            foreach ($states as $mask => $state) {
                for ($column = 0; $column < $rootDegree; ++$column) {
                    $bit = 1 << $column;
                    if (($mask & $bit) !== 0) {
                        continue;
                    }
                    $term = $this->multiply($state, $matrix[$row][$column]);
                    if ($term === null) {
                        return null;
                    }
                    $inversions = 0;
                    for ($higherColumn = $column + 1; $higherColumn < $rootDegree; ++$higherColumn) {
                        if (($mask & (1 << $higherColumn)) !== 0) {
                            ++$inversions;
                        }
                    }
                    $nextMask = $mask | $bit;
                    $combined = $this->combine(
                        $nextStates[$nextMask] ?? [],
                        $term,
                        $inversions % 2 === 0 ? 1.0 : -1.0,
                    );
                    if ($combined === null) {
                        return null;
                    }
                    $nextStates[$nextMask] = $combined;
                }
            }
            $states = $nextStates;
        }

        return $states[$fullMask] ?? null;
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function mixedSquareAndFourthRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        $squareRoot = null;
        $fourthRoot = null;
        foreach ($fractionalTerms as $term) {
            if ($term[0][2] === 2) {
                $squareRoot = $term;
            } else {
                $fourthRoot = $term;
            }
        }
        if ($squareRoot === null || $fourthRoot === null) {
            return null;
        }

        [$squareBase] = $squareRoot[0];
        [$fourthBase] = $fourthRoot[0];
        $squareRadicand = $this->extract($squareBase);
        $fourthRadicand = $this->extract($fourthBase);
        $target = $this->scale($remainder, -1.0);
        if ($squareRadicand === null || $fourthRadicand === null || $target === null) {
            return null;
        }

        $squareCoefficient = $this->polynomialPower($squareRoot[1], 2);
        $fourthCoefficient = $this->polynomialPower($fourthRoot[1], 4);
        $squareTerm = $squareCoefficient === null
            ? null
            : $this->multiply($squareRadicand, $squareCoefficient);
        $fourthTerm = $fourthCoefficient === null
            ? null
            : $this->multiply($fourthRadicand, $fourthCoefficient);
        $targetSquared = $this->polynomialPower($target, 2);
        $targetFourth = $targetSquared === null ? null : $this->polynomialPower($targetSquared, 2);
        if ($squareTerm === null || $fourthTerm === null || $targetSquared === null || $targetFourth === null) {
            return null;
        }

        $sixTargetSquared = $this->scale($targetSquared, 6.0);
        $sixTargetSquareTerm = $sixTargetSquared === null
            ? null
            : $this->multiply($sixTargetSquared, $squareTerm);
        $squareTermSquared = $this->polynomialPower($squareTerm, 2);
        $even = $sixTargetSquareTerm === null || $squareTermSquared === null
            ? null
            : $this->combine($targetFourth, $sixTargetSquareTerm, 1.0);
        $even = $even === null || $squareTermSquared === null
            ? null
            : $this->combine($even, $squareTermSquared, 1.0);
        $even = $even === null ? null : $this->combine($even, $fourthTerm, -1.0);

        $targetCubed = $this->polynomialPower($target, 3);
        $targetTimesSquare = $this->multiply($target, $squareTerm);
        $linear = $targetCubed === null || $targetTimesSquare === null
            ? null
            : $this->combine($targetCubed, $targetTimesSquare, 1.0);
        $linear = $linear === null ? null : $this->scale($linear, -4.0);
        $linearSquare = $linear === null ? null : $this->polynomialPower($linear, 2);
        $oddNorm = $linearSquare === null ? null : $this->multiply($linearSquare, $squareTerm);
        $evenSquare = $even === null ? null : $this->polynomialPower($even, 2);

        return $evenSquare === null || $oddNorm === null
            ? null
            : $this->combine($evenSquare, $oddNorm, -1.0);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function mixedSquareAndFifthRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        [$squareRoot, $fifthRoot] = $fractionalTerms;
        if ($squareRoot[0][2] !== 2 || $fifthRoot[0][2] !== 5) {
            [$squareRoot, $fifthRoot] = [$fifthRoot, $squareRoot];
        }
        if ($squareRoot[0][2] !== 2 || $fifthRoot[0][2] !== 5) {
            return null;
        }

        $squareRadicand = $this->extract($squareRoot[0][0]);
        $fifthRadicand = $this->extract($fifthRoot[0][0]);
        $target = $this->scale($remainder, -1.0);
        if ($squareRadicand === null || $fifthRadicand === null || $target === null) {
            return null;
        }

        $squareCoefficient = $this->polynomialPower($squareRoot[1], 2);
        $fifthCoefficient = $this->polynomialPower($fifthRoot[1], 5);
        $squareTerm = $squareCoefficient === null
            ? null
            : $this->multiply($squareRadicand, $squareCoefficient);
        $fifthTerm = $fifthCoefficient === null
            ? null
            : $this->multiply($fifthRadicand, $fifthCoefficient);
        if ($squareTerm === null || $fifthTerm === null) {
            return null;
        }

        $targetSquared = $this->polynomialPower($target, 2);
        $targetCubed = $this->polynomialPower($target, 3);
        $targetFourth = $targetSquared === null ? null : $this->polynomialPower($targetSquared, 2);
        $targetFifth = $targetFourth === null ? null : $this->multiply($targetFourth, $target);
        $squareTermSquared = $this->polynomialPower($squareTerm, 2);
        if (
            $targetSquared === null || $targetCubed === null || $targetFourth === null
            || $targetFifth === null || $squareTermSquared === null
        ) {
            return null;
        }

        $tenTargetCubed = $this->scale($targetCubed, 10.0);
        $tenTargetCubedSquare = $tenTargetCubed === null
            ? null
            : $this->multiply($tenTargetCubed, $squareTerm);
        $fiveTargetSquare = $this->scale($squareTermSquared, 5.0);
        $fiveTargetSquareTerm = $fiveTargetSquare === null
            ? null
            : $this->multiply($fiveTargetSquare, $target);
        $even = $tenTargetCubedSquare === null || $fiveTargetSquareTerm === null
            ? null
            : $this->combine($targetFifth, $tenTargetCubedSquare, 1.0);
        $even = $even === null || $fiveTargetSquareTerm === null
            ? null
            : $this->combine($even, $fiveTargetSquareTerm, 1.0);
        $even = $even === null ? null : $this->combine($even, $fifthTerm, -1.0);

        $fiveTargetFourth = $this->scale($targetFourth, -5.0);
        $tenTargetSquared = $this->scale($targetSquared, -10.0);
        $tenTargetSquaredSquare = $tenTargetSquared === null
            ? null
            : $this->multiply($tenTargetSquared, $squareTerm);
        $negativeSquareTermSquared = $this->scale($squareTermSquared, -1.0);
        $linear = $fiveTargetFourth === null || $tenTargetSquaredSquare === null
            ? null
            : $this->combine($fiveTargetFourth, $tenTargetSquaredSquare, 1.0);
        $linear = $linear === null || $negativeSquareTermSquared === null
            ? null
            : $this->combine($linear, $negativeSquareTermSquared, 1.0);
        $evenSquare = $even === null ? null : $this->polynomialPower($even, 2);
        $linearSquare = $linear === null ? null : $this->polynomialPower($linear, 2);
        $oddNorm = $linearSquare === null ? null : $this->multiply($linearSquare, $squareTerm);

        return $evenSquare === null || $oddNorm === null
            ? null
            : $this->combine($evenSquare, $oddNorm, -1.0);
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function mixedCubeAndFourthRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        [$cubeRoot, $fourthRoot] = $fractionalTerms;
        if ($cubeRoot[0][2] !== 3 || $fourthRoot[0][2] !== 4) {
            [$cubeRoot, $fourthRoot] = [$fourthRoot, $cubeRoot];
        }
        if ($cubeRoot[0][2] !== 3 || $fourthRoot[0][2] !== 4) {
            return null;
        }

        $cubeRadicand = $this->extract($cubeRoot[0][0]);
        $fourthRadicand = $this->extract($fourthRoot[0][0]);
        $target = $this->scale($remainder, -1.0);
        if ($cubeRadicand === null || $fourthRadicand === null || $target === null) {
            return null;
        }

        $cubeCoefficient = $this->polynomialPower($cubeRoot[1], 3);
        $fourthCoefficient = $this->polynomialPower($fourthRoot[1], 4);
        $cubeTerm = $cubeCoefficient === null
            ? null
            : $this->multiply($cubeRadicand, $cubeCoefficient);
        $fourthTerm = $fourthCoefficient === null
            ? null
            : $this->multiply($fourthRadicand, $fourthCoefficient);
        $targetSquared = $this->polynomialPower($target, 2);
        $targetFourth = $targetSquared === null ? null : $this->polynomialPower($targetSquared, 2);
        if ($cubeTerm === null || $fourthTerm === null || $targetSquared === null || $targetFourth === null) {
            return null;
        }

        $fourTarget = $this->scale($target, -4.0);
        $fourTargetCube = $fourTarget === null ? null : $this->multiply($fourTarget, $cubeTerm);
        $even = $fourTargetCube === null ? null : $this->combine($targetFourth, $fourTargetCube, 1.0);
        $even = $even === null ? null : $this->combine($even, $fourthTerm, -1.0);

        $targetCubed = $this->polynomialPower($target, 3);
        $fourTargetCubed = $targetCubed === null ? null : $this->scale($targetCubed, -4.0);
        $linear = $fourTargetCubed === null
            ? null
            : $this->combine($fourTargetCubed, $cubeTerm, 1.0);
        $quadratic = $this->scale($targetSquared, 6.0);
        if ($even === null || $linear === null || $quadratic === null) {
            return null;
        }

        $evenCubed = $this->polynomialPower($even, 3);
        $evenLinear = $this->multiply($even, $linear);
        $evenLinearQuadratic = $evenLinear === null ? null : $this->multiply($evenLinear, $quadratic);
        $evenLinearQuadraticCube = $evenLinearQuadratic === null
            ? null
            : $this->multiply($evenLinearQuadratic, $cubeTerm);
        $negativeThreeEvenLinearQuadraticCube = $evenLinearQuadraticCube === null
            ? null
            : $this->scale($evenLinearQuadraticCube, -3.0);

        $quadraticSquared = $this->polynomialPower($quadratic, 2);
        $cubeTermSquared = $this->polynomialPower($cubeTerm, 2);
        $quadraticCubed = $this->polynomialPower($quadratic, 3);
        $quadraticCubedTimesCubeTermSquared = $quadraticCubed === null || $cubeTermSquared === null
            ? null
            : $this->multiply($quadraticCubed, $cubeTermSquared);

        $linearCubed = $this->polynomialPower($linear, 3);
        $linearCubedTimesCubeTerm = $linearCubed === null ? null : $this->multiply($linearCubed, $cubeTerm);
        $result = $evenCubed;
        $normTerms = [
            $negativeThreeEvenLinearQuadraticCube,
            $quadraticCubedTimesCubeTermSquared,
            $linearCubedTimesCubeTerm,
        ];
        foreach ($normTerms as $term) {
            if ($result === null || $term === null) {
                return null;
            }
            $result = $this->combine($result, $term, 1.0);
        }

        return $result;
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function mixedSquareAndCubeRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        $squareRoot = null;
        $cubeRoot = null;
        foreach ($fractionalTerms as $term) {
            if ($term[0][2] === 2) {
                $squareRoot = $term;
            } else {
                $cubeRoot = $term;
            }
        }
        if ($squareRoot === null || $cubeRoot === null) {
            return null;
        }

        [$squareBase, $squareNumerator] = $squareRoot[0];
        [$cubeBase, $cubeNumerator] = $cubeRoot[0];
        if ($squareNumerator !== 1 || $cubeNumerator !== 1) {
            return null;
        }
        $squareRadicand = $this->extract($squareBase);
        $cubeRadicand = $this->extract($cubeBase);
        $target = $this->scale($remainder, -1.0);
        if ($squareRadicand === null || $cubeRadicand === null || $target === null) {
            return null;
        }

        $squareCoefficient = $this->polynomialPower($squareRoot[1], 2);
        $cubeCoefficient = $this->polynomialPower($cubeRoot[1], 3);
        $squareTerm = $squareCoefficient === null
            ? null
            : $this->multiply($squareRadicand, $squareCoefficient);
        $cubeTerm = $cubeCoefficient === null
            ? null
            : $this->multiply($cubeRadicand, $cubeCoefficient);
        $targetSquared = $this->polynomialPower($target, 2);
        $targetCubed = $this->polynomialPower($target, 3);
        if (
            $squareTerm === null
            || $cubeTerm === null
            || $targetSquared === null
            || $targetCubed === null
        ) {
            return null;
        }

        $threeTarget = $this->scale($target, 3.0);
        $threeTargetSquare = $this->scale($targetSquared, 3.0);
        $threeTargetSquareU = $threeTarget === null ? null : $this->multiply($threeTarget, $squareTerm);
        $threeTargetUSum = $threeTargetSquareU === null
            ? null
            : $this->combine($targetCubed, $threeTargetSquareU, 1.0);
        $constantPart = $threeTargetUSum === null
            ? null
            : $this->combine($threeTargetUSum, $cubeTerm, -1.0);
        $linearFactor = $threeTargetSquare === null
            ? null
            : $this->combine($threeTargetSquare, $squareTerm, 1.0);
        $constantSquare = $constantPart === null ? null : $this->polynomialPower($constantPart, 2);
        $linearSquare = $linearFactor === null ? null : $this->polynomialPower($linearFactor, 2);
        $radicandProduct = $linearSquare === null ? null : $this->multiply($linearSquare, $squareTerm);

        return $constantSquare === null || $radicandProduct === null
            ? null
            : $this->combine($constantSquare, $radicandProduct, -1.0);
    }

    /** @return array{Node, Node}|null */
    private function clearRadicalPolynomialDenominator(Node $left, Node $right): ?array
    {
        $terms = [];
        $this->appendAdditiveTerms($left, 1.0, $terms);
        $this->appendAdditiveTerms($right, -1.0, $terms);

        $descriptors = [];
        $denominators = [];
        $hasVariableDenominator = false;
        $hasUnsupportedDenominator = false;
        foreach ($terms as [$term, $scale]) {
            while ($term instanceof GroupingNode) {
                $term = $term->expression;
            }
            if (!$term instanceof BinaryOperationNode || $term->operator !== BinaryOperator::Divide) {
                $descriptors[] = [$term, null, $scale];
                continue;
            }
            while (true) {
                $nestedNumerator = $term->left;
                while ($nestedNumerator instanceof GroupingNode) {
                    $nestedNumerator = $nestedNumerator->expression;
                }
                if (
                    !$nestedNumerator instanceof BinaryOperationNode
                    || $nestedNumerator->operator !== BinaryOperator::Divide
                ) {
                    break;
                }
                $innerDenominator = $this->extract($nestedNumerator->right);
                $outerDenominator = $this->extract($term->right);
                if (
                    $innerDenominator === null
                    || $outerDenominator === null
                    || !$this->hasNonzeroCoefficient($innerDenominator)
                    || !$this->hasNonzeroCoefficient($outerDenominator)
                ) {
                    return null;
                }
                $combinedDenominator = new BinaryOperationNode(
                    BinaryOperator::Multiply,
                    $nestedNumerator->right,
                    $term->right,
                    $term->operatorSpan,
                );
                $term = new BinaryOperationNode(
                    BinaryOperator::Divide,
                    $nestedNumerator->left,
                    $combinedDenominator,
                    $term->operatorSpan,
                );
            }
            while (true) {
                $nestedDenominator = $term->right;
                while ($nestedDenominator instanceof GroupingNode) {
                    $nestedDenominator = $nestedDenominator->expression;
                }
                if (
                    !$nestedDenominator instanceof BinaryOperationNode
                    || $nestedDenominator->operator !== BinaryOperator::Divide
                ) {
                    break;
                }
                $nestedNumerator = $this->extract($nestedDenominator->left);
                $nestedDivisor = $this->extract($nestedDenominator->right);
                if (
                    $nestedNumerator === null
                    || $nestedDivisor === null
                    || !$this->hasNonzeroCoefficient($nestedNumerator)
                    || !$this->hasNonzeroCoefficient($nestedDivisor)
                ) {
                    return null;
                }
                $reciprocalProduct = $this->multiplyDistributingAddends(
                    $term->left,
                    $nestedDenominator->right,
                    $term->operatorSpan,
                );
                $term = new BinaryOperationNode(
                    BinaryOperator::Divide,
                    $reciprocalProduct,
                    $nestedDenominator->left,
                    $term->operatorSpan,
                );
            }
            $denominator = $this->extract($term->right);
            if ($denominator === null) {
                if ($this->containsSolvedVariable($term->right)) {
                    return null;
                }
                $hasUnsupportedDenominator = true;
                continue;
            }
            if (!$this->hasNonzeroCoefficient($denominator)) {
                return null;
            }
            $hasVariableDenominator = $hasVariableDenominator
                || $this->containsSolvedVariable($term->right);
            $denominators[] = $term->right;
            $numeratorTerms = [];
            $numerator = $this->distributePolynomialOverAdditiveNumerator($term->left);
            $this->appendAdditiveTerms($numerator, 1.0, $numeratorTerms);
            foreach ($numeratorTerms as [$numerator, $numeratorScale]) {
                $radicalNumerator = $this->fractionalPowerTerm($numerator);
                $polynomialNumerator = $radicalNumerator === null ? $this->extract($numerator) : null;
                if ($radicalNumerator === null && $polynomialNumerator === null) {
                    return null;
                }
                $descriptors[] = [$numerator, $term->right, $scale * $numeratorScale];
            }
        }

        if (!$hasVariableDenominator || $hasUnsupportedDenominator) {
            return null;
        }

        $positiveTerms = [];
        $negativeTerms = [];
        foreach ($descriptors as [$numerator, $ownDenominator, $scale]) {
            $multiplierFactors = [];
            foreach ($denominators as $denominatorIndex => $denominator) {
                if ($ownDenominator === null || $denominators[$denominatorIndex] !== $ownDenominator) {
                    $multiplierFactors[] = $denominator;
                }
            }
            $multiplier = $this->multiplyNodes($multiplierFactors, $numerator->span());
            $term = $multiplier === null
                ? $numerator
                : new BinaryOperationNode(BinaryOperator::Multiply, $numerator, $multiplier, $numerator->span());
            if ($scale > 0.0) {
                $positiveTerms[] = $term;
            } else {
                $negativeTerms[] = $term;
            }
        }

        return [
            $this->addNodes($positiveTerms, $left->span()),
            $this->addNodes($negativeTerms, $right->span()),
        ];
    }

    private function multiplyDistributingAddends(Node $left, Node $right, \MathPHP\Source\SourceSpan $span): Node
    {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        if ($left instanceof UnaryOperationNode) {
            return $left->operator === UnaryOperator::Minus
                ? new UnaryOperationNode(
                    UnaryOperator::Minus,
                    $this->multiplyDistributingAddends($left->operand, $right, $span),
                    $left->operatorSpan,
                )
                : $this->multiplyDistributingAddends($left->operand, $right, $span);
        }
        if (
            $left instanceof BinaryOperationNode
            && \in_array($left->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true)
        ) {
            return new BinaryOperationNode(
                $left->operator,
                $this->multiplyDistributingAddends($left->left, $right, $span),
                $this->multiplyDistributingAddends($left->right, $right, $span),
                $left->operatorSpan,
            );
        }

        return new BinaryOperationNode(BinaryOperator::Multiply, $left, $right, $span);
    }

    private function distributePolynomialOverAdditiveNumerator(Node $node): Node
    {
        if ($node instanceof GroupingNode) {
            return $this->distributePolynomialOverAdditiveNumerator($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            return new UnaryOperationNode(
                $node->operator,
                $this->distributePolynomialOverAdditiveNumerator($node->operand),
                $node->operatorSpan,
            );
        }
        if (!$node instanceof BinaryOperationNode || $node->operator !== BinaryOperator::Multiply) {
            return $node;
        }

        $left = $this->distributePolynomialOverAdditiveNumerator($node->left);
        $right = $this->distributePolynomialOverAdditiveNumerator($node->right);
        if ($this->isAdditiveNode($left) && $this->extract($right) !== null) {
            return $this->multiplyDistributingAddends($left, $right, $node->operatorSpan);
        }
        if ($this->isAdditiveNode($right) && $this->extract($left) !== null) {
            return $this->multiplyDistributingAddends($right, $left, $node->operatorSpan);
        }

        return new BinaryOperationNode(BinaryOperator::Multiply, $left, $right, $node->operatorSpan);
    }

    private function isAdditiveNode(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isAdditiveNode($node->operand);
        }

        return $node instanceof BinaryOperationNode
            && \in_array($node->operator, [BinaryOperator::Add, BinaryOperator::Subtract], true);
    }

    /** @param list<Node> $factors */
    private function multiplyNodes(array $factors, \MathPHP\Source\SourceSpan $span): ?Node
    {
        if ($factors === []) {
            return null;
        }
        $product = \array_shift($factors);
        foreach ($factors as $factor) {
            $product = new BinaryOperationNode(BinaryOperator::Multiply, $product, $factor, $span);
        }

        return $product;
    }

    /** @param list<Node> $terms */
    private function addNodes(array $terms, \MathPHP\Source\SourceSpan $span): Node
    {
        if ($terms === []) {
            return new NumberNode(0, '0', $span);
        }
        $sum = \array_shift($terms);
        foreach ($terms as $term) {
            $sum = new BinaryOperationNode(BinaryOperator::Add, $sum, $term, $span);
        }

        return $sum;
    }

    /**
     * @param list<array{array{Node, int, int}, array<string, float>}> $fractionalTerms
     * @param array<string, float> $remainder
     * @return array<string, float>|null
     */
    private function threeSquareRootDifference(array $fractionalTerms, array $remainder): ?array
    {
        $polynomials = [];
        $coefficients = [];
        foreach ($fractionalTerms as [[$base, $numerator, $denominator], $coefficient]) {
            if (
                $numerator !== 1
                || $denominator !== 2
                || !$this->hasNonzeroCoefficient($coefficient)
            ) {
                return null;
            }
            $polynomial = $this->extract($base);
            if ($polynomial === null) {
                return null;
            }
            $polynomials[] = $polynomial;
            $coefficients[] = $coefficient;
        }
        $target = $this->scale($remainder, -1.0);
        if ($target === null) {
            return null;
        }
        [$first, $second, $third] = $polynomials;
        [$firstCoefficient, $secondCoefficient, $thirdCoefficient] = $coefficients;

        $targetSquared = $this->polynomialPower($target, 2);
        $firstCoefficientSquared = $this->polynomialPower($firstCoefficient, 2);
        $secondCoefficientSquared = $this->polynomialPower($secondCoefficient, 2);
        $thirdCoefficientSquared = $this->polynomialPower($thirdCoefficient, 2);
        $firstTerm = $firstCoefficientSquared === null
            ? null
            : $this->multiply($first, $firstCoefficientSquared);
        $secondTerm = $secondCoefficientSquared === null
            ? null
            : $this->multiply($second, $secondCoefficientSquared);
        $thirdTerm = $thirdCoefficientSquared === null
            ? null
            : $this->multiply($third, $thirdCoefficientSquared);
        if (
            $targetSquared === null
            || $firstTerm === null
            || $secondTerm === null
            || $thirdTerm === null
        ) {
            return null;
        }

        $u = $this->combine($targetSquared, $firstTerm, 1.0);
        $u = $u === null ? null : $this->combine($u, $secondTerm, 1.0);
        $u = $u === null ? null : $this->combine($u, $thirdTerm, -1.0);
        $twiceTarget = $this->scale($target, -2.0);
        $v = $twiceTarget === null ? null : $this->multiply($twiceTarget, $firstCoefficient);
        $w = $twiceTarget === null ? null : $this->multiply($twiceTarget, $secondCoefficient);
        $z = $this->multiply($firstCoefficient, $secondCoefficient);
        $z = $z === null ? null : $this->scale($z, 2.0);
        if ($u === null || $v === null || $w === null || $z === null) {
            return null;
        }

        $uSquared = $this->polynomialPower($u, 2);
        $wSquared = $this->polynomialPower($w, 2);
        $vSquared = $this->polynomialPower($v, 2);
        $zSquared = $this->polynomialPower($z, 2);
        $uw = $this->multiply($u, $w);
        $vz = $this->multiply($v, $z);
        if (
            $uSquared === null
            || $wSquared === null
            || $vSquared === null
            || $zSquared === null
            || $uw === null
            || $vz === null
        ) {
            return null;
        }
        $wSquaredSecond = $this->multiply($wSquared, $second);
        $firstVSquared = $this->multiply($first, $vSquared);
        $firstZSquared = $this->multiply($first, $zSquared);
        $firstZSquaredSecond = $firstZSquared === null
            ? null
            : $this->multiply($firstZSquared, $second);
        if (
            $wSquaredSecond === null
            || $firstVSquared === null
            || $firstZSquaredSecond === null
        ) {
            return null;
        }
        $k = $this->combine($uSquared, $wSquaredSecond, 1.0);
        $k = $k === null ? null : $this->combine($k, $firstVSquared, -1.0);
        $k = $k === null ? null : $this->combine($k, $firstZSquaredSecond, -1.0);
        $twiceUw = $this->scale($uw, 2.0);
        $twiceFirstVz = $this->scale($vz, -2.0);
        $firstTimesTwiceVz = $twiceFirstVz === null
            ? null
            : $this->multiply($first, $twiceFirstVz);
        $l = $twiceUw === null || $firstTimesTwiceVz === null
            ? null
            : $this->combine($twiceUw, $firstTimesTwiceVz, 1.0);
        if ($k === null || $l === null) {
            return null;
        }
        $kSquared = $this->polynomialPower($k, 2);
        $lSquared = $this->polynomialPower($l, 2);
        $secondLSquared = $lSquared === null ? null : $this->multiply($second, $lSquared);

        return $kSquared === null || $secondLSquared === null
            ? null
            : $this->combine($kSquared, $secondLSquared, -1.0);
    }

    /**
     * @param list<array{Node, float}> $terms
     */
    private function appendAdditiveTerms(Node $node, float $scale, array &$terms): void
    {
        if ($node instanceof GroupingNode) {
            $this->appendAdditiveTerms($node->expression, $scale, $terms);

            return;
        }
        if ($node instanceof UnaryOperationNode) {
            $this->appendAdditiveTerms(
                $node->operand,
                $node->operator === UnaryOperator::Minus ? -$scale : $scale,
                $terms,
            );

            return;
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Add) {
            $this->appendAdditiveTerms($node->left, $scale, $terms);
            $this->appendAdditiveTerms($node->right, $scale, $terms);

            return;
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Subtract) {
            $this->appendAdditiveTerms($node->left, $scale, $terms);
            $this->appendAdditiveTerms($node->right, -$scale, $terms);

            return;
        }

        $terms[] = [$node, $scale];
    }

    /** @return array{array<string, float>, array<string, float>}|null */
    private function extractRationalSide(Node $node): ?array
    {
        $form = $this->extractRationalSideUnnormalized($node);
        if ($form === null) {
            return null;
        }

        [$numerator, $denominator] = $form;
        $scale = 0.0;
        foreach ([$numerator, $denominator] as $polynomial) {
            foreach ($polynomial as $coefficient) {
                $scale = \max($scale, \abs($coefficient));
            }
        }
        if ($scale === 0.0 || !\is_finite($scale)) {
            return null;
        }
        foreach ($numerator as $monomial => $coefficient) {
            $normalizedCoefficient = $coefficient / $scale;
            if ($coefficient !== 0.0 && $normalizedCoefficient === 0.0) {
                return null;
            }
            $numerator[$monomial] = $normalizedCoefficient;
        }
        foreach ($denominator as $monomial => $coefficient) {
            $normalizedCoefficient = $coefficient / $scale;
            if ($coefficient !== 0.0 && $normalizedCoefficient === 0.0) {
                return null;
            }
            $denominator[$monomial] = $normalizedCoefficient;
        }

        return [$numerator, $denominator];
    }

    /** @return array{array<string, float>, array<string, float>}|null */
    private function extractRationalSideUnnormalized(Node $node): ?array
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$this->containsSolvedVariable($node)) {
            $constant = $this->extract($node);

            return $constant === null ? null : [$constant, ['0:0' => 1.0]];
        }
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Power) {
            $rootCall = $this->polynomialRootCall($node->left);
            $absoluteValueArgument = $this->absoluteValueArgument($node->left);
            $fractionalPower = $this->fractionalPower($node->left);
            $exponent = $this->extract($node->right);
            if (
                $exponent !== null
                && \count($exponent) === 1
                && isset($exponent['0:0'])
                && \abs($exponent['0:0']) > $this->options->limits->maxExponentMagnitude
            ) {
                return null;
            }
            if (
                $fractionalPower !== null
                && $exponent !== null
                && \count($exponent) === 1
                && isset($exponent['0:0'])
                && \floor($exponent['0:0']) === $exponent['0:0']
                && \abs($exponent['0:0']) <= $this->maxTotalDegree * $fractionalPower[2]
            ) {
                [$base, $numerator, $denominator] = $fractionalPower;
                $combinedNumerator = $numerator * (int) $exponent['0:0'];
                if (
                    $combinedNumerator % $denominator === 0
                    && \abs($combinedNumerator / $denominator) <= $this->maxTotalDegree
                ) {
                    $basePolynomial = $this->extract($base);
                    if ($basePolynomial === null) {
                        return null;
                    }
                    $combinedExponent = intdiv($combinedNumerator, $denominator);
                    if ($combinedExponent >= 0) {
                        $numeratorPolynomial = $this->polynomialPower($basePolynomial, $combinedExponent);

                        return $numeratorPolynomial === null
                            ? null
                            : [$numeratorPolynomial, ['0:0' => 1.0]];
                    }
                    if (!$this->hasNonzeroCoefficient($basePolynomial)) {
                        return null;
                    }
                    $denominatorPolynomial = $this->polynomialPower($basePolynomial, -$combinedExponent);

                    return $denominatorPolynomial === null
                        ? null
                        : [['0:0' => 1.0], $denominatorPolynomial];
                }
            }
            if (
                $rootCall !== null
                && $exponent !== null
                && \count($exponent) === 1
                && isset($exponent['0:0'])
                && \floor($exponent['0:0']) === $exponent['0:0']
            ) {
                [$radicand, $rootDegree] = $rootCall;
                $polynomialExponent = $exponent['0:0'] / $rootDegree;
                if (
                    \floor($polynomialExponent) === $polynomialExponent
                    && \abs($polynomialExponent) <= $this->maxTotalDegree
                ) {
                    $radicandPolynomial = $this->extract($radicand);
                    if ($radicandPolynomial === null) {
                        return null;
                    }
                    if ($polynomialExponent >= 0.0) {
                        $numerator = $this->polynomialPower(
                            $radicandPolynomial,
                            (int) $polynomialExponent,
                        );

                        return $numerator === null ? null : [$numerator, ['0:0' => 1.0]];
                    }
                    if (!$this->hasNonzeroCoefficient($radicandPolynomial)) {
                        return null;
                    }
                    $denominator = $this->polynomialPower(
                        $radicandPolynomial,
                        (int) -$polynomialExponent,
                    );

                    return $denominator === null
                        ? null
                        : [['0:0' => 1.0], $denominator];
                }
            }
            if (
                $absoluteValueArgument !== null
                && $exponent !== null
                && \count($exponent) === 1
                && isset($exponent['0:0'])
                && \floor($exponent['0:0']) === $exponent['0:0']
                && ((int) $exponent['0:0']) % 2 === 0
                && \abs($exponent['0:0']) <= $this->maxTotalDegree
            ) {
                $polynomial = $this->extract($absoluteValueArgument);
                if ($polynomial === null) {
                    return null;
                }
                $power = (int) \abs($exponent['0:0']);
                $poweredPolynomial = $this->polynomialPower($polynomial, $power);
                if ($poweredPolynomial === null) {
                    return null;
                }
                if ($exponent['0:0'] >= 0.0) {
                    return [$poweredPolynomial, ['0:0' => 1.0]];
                }
                if (!$this->hasNonzeroCoefficient($polynomial)) {
                    return null;
                }

                return [['0:0' => 1.0], $poweredPolynomial];
            }
        }
        if ($node instanceof UnaryOperationNode) {
            $operand = $this->extractRationalSide($node->operand);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            $numerator = $this->scale($operand[0], -1.0);

            return $numerator === null ? null : [$numerator, $operand[1]];
        }
        if ($node instanceof FunctionCallNode && ($factor = $this->implicitProductFactor($node)) !== null) {
            $argument = $this->extractRationalSide($node->arguments[0]);
            if ($argument === null) {
                return null;
            }
            $numerator = $this->multiply($factor, $argument[0]);

            return $numerator === null ? null : [$numerator, $argument[1]];
        }
        if ($node instanceof BinaryOperationNode) {
            $left = $this->extractRationalSide($node->left);
            $right = $this->extractRationalSide($node->right);
            if ($left === null || $right === null) {
                return null;
            }

            [$leftNumerator, $leftDenominator] = $left;
            [$rightNumerator, $rightDenominator] = $right;
            if ($node->operator === BinaryOperator::Add || $node->operator === BinaryOperator::Subtract) {
                $leftProduct = $this->multiply($leftNumerator, $rightDenominator);
                $rightProduct = $this->multiply($rightNumerator, $leftDenominator);
                $denominator = $this->multiply($leftDenominator, $rightDenominator);
                if ($leftProduct === null || $rightProduct === null || $denominator === null) {
                    return null;
                }
                $numerator = $this->combine(
                    $leftProduct,
                    $rightProduct,
                    $node->operator === BinaryOperator::Add ? 1.0 : -1.0,
                );

                return $numerator === null ? null : [$numerator, $denominator];
            }
            if ($node->operator === BinaryOperator::Multiply) {
                $numerator = $this->multiply($leftNumerator, $rightNumerator);
                $denominator = $this->multiply($leftDenominator, $rightDenominator);

                return $numerator === null || $denominator === null
                    ? null
                    : [$numerator, $denominator];
            }
            if ($node->operator === BinaryOperator::Divide) {
                if (!$this->hasNonzeroCoefficient($rightNumerator)) {
                    return null;
                }
                $numerator = $this->multiply($leftNumerator, $rightDenominator);
                $denominator = $this->multiply($leftDenominator, $rightNumerator);

                return $numerator === null || $denominator === null
                    ? null
                    : [$numerator, $denominator];
            }
            if ($node->operator === BinaryOperator::Power) {
                $exponent = $this->extract($node->right);
                if (
                    $exponent === null
                    || \count($exponent) !== 1
                    || !isset($exponent['0:0'])
                    || \floor($exponent['0:0']) !== $exponent['0:0']
                    || \abs($exponent['0:0']) > $this->maxTotalDegree
                ) {
                    return null;
                }
                $power = (int) $exponent['0:0'];
                if ($power === 0) {
                    return $this->hasNonzeroCoefficient($leftDenominator)
                        ? [$leftDenominator, $leftDenominator]
                        : null;
                }
                if ($power < 0) {
                    if (!$this->hasNonzeroCoefficient($leftNumerator)) {
                        return null;
                    }
                    [$leftNumerator, $leftDenominator] = [$leftDenominator, $leftNumerator];
                    $power = -$power;
                }
                $numerator = $this->polynomialPower($leftNumerator, $power);
                $denominator = $this->polynomialPower($leftDenominator, $power);

                return $numerator === null || $denominator === null
                    ? null
                    : [$numerator, $denominator];
            }

            return null;
        }

        $polynomial = $this->extract($node);
        if ($polynomial === null) {
            return null;
        }

        return [$polynomial, ['0:0' => 1.0]];
    }

    /** @param array<string, float> $polynomial */
    private function hasNonzeroCoefficient(array $polynomial): bool
    {
        foreach ($polynomial as $coefficient) {
            if ($coefficient !== 0.0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, float> $polynomial
     *  @return array<string, float>|null
     */
    private function polynomialPower(array $polynomial, int $power): ?array
    {
        $result = ['0:0' => 1.0];
        while ($power > 0) {
            if ($power % 2 === 1) {
                $result = $this->multiply($result, $polynomial);
                if ($result === null) {
                    return null;
                }
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $polynomial = $this->multiply($polynomial, $polynomial);
                if ($polynomial === null) {
                    return null;
                }
            }
        }

        return $result;
    }

    /** @return array<string, float>|null */
    private function extract(Node $node): ?array
    {
        if (!$this->containsSolvedVariable($node)) {
            try {
                $value = (new Evaluator(
                    new Environment($this->parameters, $this->options->functions),
                    $this->options->limits,
                ))->evaluate($node);
            } catch (EvaluationException) {
                return null;
            }

            return \is_finite((float) $value) ? ['0:0' => (float) $value] : null;
        }
        if ($node instanceof NumberNode) {
            return ['0:0' => (float) $node->value];
        }
        if ($node instanceof VariableNode) {
            return match ($node->name) {
                $this->firstVariable => ['1:0' => 1.0],
                $this->secondVariable => ['0:1' => 1.0],
                default => null,
            };
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
        if ($node instanceof FunctionCallNode && ($factor = $this->implicitProductFactor($node)) !== null) {
            $argument = $this->extract($node->arguments[0]);

            return $argument === null ? null : $this->multiply($factor, $argument);
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

    private function containsSolvedVariable(Node $node): bool
    {
        if ($node instanceof VariableNode) {
            return $node->name === $this->firstVariable || $node->name === $this->secondVariable;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsSolvedVariable($node->expression);
        }
        if ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            return $this->containsSolvedVariable($node->operand);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsSolvedVariable($node->left)
                || $this->containsSolvedVariable($node->right);
        }
        if ($node instanceof FunctionCallNode) {
            if ($this->implicitProductFactor($node) !== null) {
                return true;
            }
            foreach ($node->arguments as $argument) {
                if ($this->containsSolvedVariable($argument)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, float>|null Sparse monomial or parameter factor. */
    private function implicitProductFactor(FunctionCallNode $node): ?array
    {
        if (
            \count($node->arguments) !== 1
            || $this->options->functions->find($node->name) !== null
        ) {
            return null;
        }
        if ($node->name === $this->firstVariable) {
            return ['1:0' => 1.0];
        }
        if ($node->name === $this->secondVariable) {
            return ['0:1' => 1.0];
        }
        if (!\array_key_exists($node->name, $this->parameters)) {
            return null;
        }
        $factor = (float) $this->parameters[$node->name];

        return \is_finite($factor) ? ['0:0' => $factor] : null;
    }

    /**
     * @param array<string, float> $left
     * @param array<string, float> $right
     * @return array<string, float>|null
     */
    private function combine(array $left, array $right, float $rightScale): ?array
    {
        foreach ($right as $monomial => $coefficient) {
            $scaledCoefficient = $rightScale * $coefficient;
            if ($rightScale !== 0.0 && $coefficient !== 0.0 && $scaledCoefficient === 0.0) {
                return null;
            }
            $previousCoefficient = $left[$monomial] ?? 0.0;
            $value = $previousCoefficient + $scaledCoefficient;
            if ($scaledCoefficient !== 0.0 && $value === $previousCoefficient) {
                return null;
            }
            if (!\is_finite($value)) {
                return null;
            }
            $left[$monomial] = $value;
        }

        return $left;
    }

    /**
     * @param array<string, float> $polynomial
     * @return array<string, float>|null
     */
    private function scale(array $polynomial, float $factor): ?array
    {
        foreach ($polynomial as $monomial => $coefficient) {
            $value = $coefficient * $factor;
            if (
                !\is_finite($value)
                || ($coefficient !== 0.0 && $factor !== 0.0 && $value === 0.0)
            ) {
                return null;
            }
            $polynomial[$monomial] = $value;
        }

        return $polynomial;
    }

    /**
     * @param array<string, float> $left
     * @param array<string, float> $right
     * @return array<string, float>|null
     */
    private function multiply(array $left, array $right): ?array
    {
        $product = [];
        foreach ($left as $leftMonomial => $leftCoefficient) {
            [$leftFirst, $leftSecond] = \array_map('intval', \explode(':', $leftMonomial));
            foreach ($right as $rightMonomial => $rightCoefficient) {
                [$rightFirst, $rightSecond] = \array_map('intval', \explode(':', $rightMonomial));
                $firstDegree = $leftFirst + $rightFirst;
                $secondDegree = $leftSecond + $rightSecond;
                if ($firstDegree + $secondDegree > $this->maxTotalDegree) {
                    return null;
                }
                $monomial = $firstDegree . ':' . $secondDegree;
                $term = $leftCoefficient * $rightCoefficient;
                if ($leftCoefficient !== 0.0 && $rightCoefficient !== 0.0 && $term === 0.0) {
                    return null;
                }
                $previousCoefficient = $product[$monomial] ?? 0.0;
                $value = $previousCoefficient + $term;
                if ($term !== 0.0 && $value === $previousCoefficient) {
                    return null;
                }
                if (!\is_finite($value)) {
                    return null;
                }
                $product[$monomial] = $value;
            }
        }

        return $product;
    }

    /**
     * @param array<string, float> $numerator
     * @param array<string, float> $denominator
     * @return array<string, float>|null
     */
    private function divide(array $numerator, array $denominator): ?array
    {
        if (
            \count($denominator) !== 1
            || !isset($denominator['0:0'])
            || $denominator['0:0'] === 0.0
        ) {
            return null;
        }

        foreach ($numerator as $monomial => $coefficient) {
            $quotient = $coefficient / $denominator['0:0'];
            if (
                !\is_finite($quotient)
                || ($coefficient !== 0.0 && $quotient === 0.0)
            ) {
                return null;
            }
            $numerator[$monomial] = $quotient;
        }

        return $numerator;
    }

    /**
     * @param array<string, float> $base
     * @param array<string, float> $exponent
     * @return array<string, float>|null
     */
    private function power(array $base, array $exponent): ?array
    {
        if (
            \count($exponent) !== 1
            || !isset($exponent['0:0'])
            || $exponent['0:0'] < 0.0
            || \floor($exponent['0:0']) !== $exponent['0:0']
            || $exponent['0:0'] > $this->maxTotalDegree
            || $exponent['0:0'] > $this->options->limits->maxExponentMagnitude
        ) {
            return null;
        }
        $power = (int) $exponent['0:0'];
        $result = ['0:0' => 1.0];
        for ($index = 0; $index < $power; ++$index) {
            $result = $this->multiply($result, $base);
            if ($result === null) {
                return null;
            }
        }

        return $result;
    }
}
