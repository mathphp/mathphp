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

/** Exact polynomial extraction with arbitrary-size decimal rational coefficients. */
final readonly class ExactRationalPolynomialExtractor
{
    private const MAX_INTEGER_DIGITS = 8192;

    private IntegerPolynomialExtractor $integerArithmetic;

    /**
     * @param array<string, int|float> $parameters
     */
    public function __construct(
        private string $variable,
        private array $parameters,
        private int $maxDegree = 256,
        private ?EvaluationOptions $evaluationOptions = null,
    ) {
        $this->integerArithmetic = new IntegerPolynomialExtractor('', [], $maxDegree);
    }

    /** @return list<string>|null Integer coefficients in ascending degree order. */
    public function difference(Node $left, Node $right): ?array
    {
        return $this->polynomialCombination($left, $right, true);
    }

    /** @return list<string>|null Integer coefficients in ascending degree order. */
    public function sum(Node $left, Node $right): ?array
    {
        return $this->polynomialCombination($left, $right, false);
    }

    /** @return list<string>|null Integer coefficients in ascending degree order. */
    private function polynomialCombination(Node $left, Node $right, bool $subtract): ?array
    {
        $leftPolynomial = $this->polynomial($left);
        $rightPolynomial = $this->polynomial($right);
        if ($leftPolynomial === null || $rightPolynomial === null) {
            return null;
        }

        $length = \max(\count($leftPolynomial), \count($rightPolynomial));
        $difference = [];
        for ($degree = 0; $degree < $length; ++$degree) {
            $rightCoefficient = $rightPolynomial[$degree] ?? ['0', '1'];
            if ($subtract && $rightCoefficient[0] !== '0') {
                $rightCoefficient[0] = $this->integerArithmetic->negateIntegerValue($rightCoefficient[0]);
            }
            $coefficient = $this->addFractions(
                $leftPolynomial[$degree] ?? ['0', '1'],
                $rightCoefficient,
            );
            if ($coefficient === null) {
                return null;
            }
            $difference[] = $coefficient;
        }

        return $this->integerCoefficients($this->trim($difference));
    }

    /**
     * Extract the numerator and denominator of a rational-function difference.
     *
     * @return array{list<string>, list<string>}|null Integer coefficients in ascending order.
     */
    public function rationalDifference(Node $left, Node $right): ?array
    {
        return $this->rationalCombination($left, $right, true);
    }

    /**
     * Extract a rational function without independently rescaling its numerator and denominator.
     *
     * @return array{
     *     list<array{string, string}>,
     *     list<array{string, string}>
     * }|null
     */
    public function rationalFunction(Node $node): ?array
    {
        return $this->rationalForm($node);
    }

    /**
     * Reduce a rational function by its exact polynomial gcd.
     *
     * @return array{list<array{string, string}>, list<array{string, string}>, list<array{string, string}>}|null
     */
    public function reducedRationalFunction(Node $node): ?array
    {
        $form = $this->rationalForm($node);
        if ($form === null) {
            return null;
        }
        [$numerator, $denominator] = $form;
        $common = $this->polynomialGcd($numerator, $denominator);
        if ($common === null) {
            return null;
        }
        $reducedNumerator = $this->dividePolynomialExactlyByPolynomial($numerator, $common);
        $reducedDenominator = $this->dividePolynomialExactlyByPolynomial($denominator, $common);

        return $reducedNumerator === null || $reducedDenominator === null
            ? null
            : [$reducedNumerator, $reducedDenominator, $denominator];
    }

    /**
     * @param list<array{string, string}> $left
     * @param list<array{string, string}> $right
     * @return list<array{string, string}>|null
     */
    private function polynomialGcd(array $left, array $right): ?array
    {
        $left = $this->monicPolynomial($left);
        $right = $this->monicPolynomial($right);
        if ($left === null || $right === null) {
            return null;
        }
        while (!$this->isZeroPolynomial($right)) {
            $remainder = $this->polynomialRemainder($left, $right);
            if ($remainder === null) {
                return null;
            }
            $left = $right;
            $right = $this->monicPolynomial($remainder);
            if ($right === null) {
                return null;
            }
        }

        return $this->monicPolynomial($left);
    }

    /**
     * @param list<array{string, string}> $polynomial
     * @return list<array{string, string}>|null
     */
    private function monicPolynomial(array $polynomial): ?array
    {
        $polynomial = $this->trim($polynomial);
        if ($this->isZeroPolynomial($polynomial)) {
            return [['0', '1']];
        }
        $leadingCoefficient = $polynomial[\array_key_last($polynomial) ?? 0] ?? null;
        if ($leadingCoefficient === null) {
            return [['0', '1']];
        }
        $inverse = $this->powerExactFraction($leadingCoefficient, -1);
        if ($inverse === null) {
            return null;
        }
        $normalized = [];
        foreach ($polynomial as $coefficient) {
            $normalizedCoefficient = $this->multiplyFractions($coefficient, $inverse);
            if ($normalizedCoefficient === null) {
                return null;
            }
            $normalized[] = $normalizedCoefficient;
        }

        return $this->trim($normalized);
    }

    /**
     * @param list<array{string, string}> $dividend
     * @param list<array{string, string}> $divisor
     * @return list<array{string, string}>|null
     */
    private function polynomialRemainder(array $dividend, array $divisor): ?array
    {
        if ($this->isZeroPolynomial($divisor)) {
            return null;
        }
        $remainder = $this->trim($dividend);
        $divisorDegree = \count($divisor) - 1;
        while (!$this->isZeroPolynomial($remainder) && \count($remainder) > $divisorDegree) {
            $shift = \count($remainder) - \count($divisor);
            $divisorLeadingCoefficient = $divisor[\array_key_last($divisor) ?? 0] ?? null;
            $remainderLeadingCoefficient = $remainder[\array_key_last($remainder) ?? 0] ?? null;
            if ($divisorLeadingCoefficient === null || $remainderLeadingCoefficient === null) {
                return null;
            }
            $inverse = $this->powerExactFraction($divisorLeadingCoefficient, -1);
            $factor = $inverse === null
                ? null
                : $this->multiplyFractions($remainderLeadingCoefficient, $inverse);
            if ($factor === null) {
                return null;
            }
            foreach ($divisor as $degree => $coefficient) {
                $product = $this->multiplyFractions($factor, $coefficient);
                $difference = $product === null
                    ? null
                    : $this->subtractExactFractions($remainder[$shift + $degree], $product);
                if ($difference === null) {
                    return null;
                }
                $remainder[$shift + $degree] = $difference;
            }
            $remainder = $this->trim($remainder);
        }

        return $remainder;
    }

    /**
     * @param list<array{string, string}> $dividend
     * @param list<array{string, string}> $divisor
     * @return list<array{string, string}>|null
     */
    private function dividePolynomialExactlyByPolynomial(array $dividend, array $divisor): ?array
    {
        if ($this->isZeroPolynomial($divisor)) {
            return null;
        }
        $remainder = $this->trim($dividend);
        $quotient = \array_fill(
            0,
            \max(1, \count($remainder) - \count($divisor) + 1),
            ['0', '1'],
        );
        while (!$this->isZeroPolynomial($remainder) && \count($remainder) >= \count($divisor)) {
            $shift = \count($remainder) - \count($divisor);
            $divisorLeadingCoefficient = $divisor[\array_key_last($divisor) ?? 0] ?? null;
            $remainderLeadingCoefficient = $remainder[\array_key_last($remainder) ?? 0] ?? null;
            if ($divisorLeadingCoefficient === null || $remainderLeadingCoefficient === null) {
                return null;
            }
            $inverse = $this->powerExactFraction($divisorLeadingCoefficient, -1);
            $factor = $inverse === null
                ? null
                : $this->multiplyFractions($remainderLeadingCoefficient, $inverse);
            if ($factor === null) {
                return null;
            }
            $quotient[$shift] = $factor;
            foreach ($divisor as $degree => $coefficient) {
                $product = $this->multiplyFractions($factor, $coefficient);
                $difference = $product === null
                    ? null
                    : $this->subtractExactFractions($remainder[$shift + $degree], $product);
                if ($difference === null) {
                    return null;
                }
                $remainder[$shift + $degree] = $difference;
            }
            $remainder = $this->trim($remainder);
        }
        if (!$this->isZeroPolynomial($remainder)) {
            return null;
        }

        return $this->trim($quotient);
    }

    /**
     * Determine whether the rational function left/right is constant.
     *
     * @return array{string, string}|false|null Exact ratio, false if not constant, null if unsupported.
     */
    public function rationalFunctionRatio(Node $left, Node $right): array|false|null
    {
        $leftForm = $this->rationalForm($left);
        $rightForm = $this->rationalForm($right);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        $numerator = $this->multiplyPolynomials($leftForm[0], $rightForm[1]);
        $denominator = $this->multiplyPolynomials($leftForm[1], $rightForm[0]);
        if ($numerator === null || $denominator === null) {
            return null;
        }
        if ($this->isZeroPolynomial($denominator)) {
            return false;
        }

        $referenceDegree = null;
        foreach ($denominator as $degree => $coefficient) {
            if ($coefficient[0] !== '0') {
                $referenceDegree = $degree;
                break;
            }
        }
        if ($referenceDegree === null) {
            return false;
        }
        $referenceDenominator = $denominator[$referenceDegree];
        $referenceNumerator = $numerator[$referenceDegree] ?? ['0', '1'];
        $inverse = $this->powerExactFraction($referenceDenominator, -1);
        $ratio = $inverse === null
            ? null
            : $this->multiplyExactFractions($referenceNumerator, $inverse);
        if ($ratio === null) {
            return null;
        }

        for ($degree = 0, $length = \max(\count($numerator), \count($denominator)); $degree < $length; ++$degree) {
            $scaledDenominator = $this->multiplyExactFractions(
                $ratio,
                $denominator[$degree] ?? ['0', '1'],
            );
            $coefficient = $numerator[$degree] ?? ['0', '1'];
            if ($scaledDenominator === null) {
                return null;
            }
            if ($scaledDenominator !== $coefficient) {
                return false;
            }
        }

        return $ratio;
    }

    /**
     * Extract the rational polynomial equation left^leftPower = right^rightPower.
     *
     * @return array{list<string>, list<string>}|false|null False means a reciprocal base is identically zero.
     */
    public function rationalPowerDifference(
        Node $left,
        int $leftPower,
        Node $right,
        int $rightPower,
    ): array|false|null {
        if (
            $leftPower === 0 || $leftPower === \PHP_INT_MIN
            || $rightPower === 0 || $rightPower === \PHP_INT_MIN
            || \abs($leftPower) > 2 * $this->maxDegree
            || \abs($rightPower) > 2 * $this->maxDegree
        ) {
            return null;
        }
        $leftForm = $this->rationalForm($left);
        $rightForm = $this->rationalForm($right);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        if (
            ($leftPower < 0 && $this->isZeroPolynomial($leftForm[0]))
            || ($rightPower < 0 && $this->isZeroPolynomial($rightForm[0]))
        ) {
            return false;
        }
        $leftDomainDenominator = null;
        if ($leftPower < 0) {
            $leftDomainDenominator = $leftForm[1];
            $leftForm = [$leftForm[1], $leftForm[0]];
            $leftPower = \abs($leftPower);
            if ($this->isZeroPolynomial($leftForm[1])) {
                return null;
            }
        }
        $rightDomainDenominator = null;
        if ($rightPower < 0) {
            $rightDomainDenominator = $rightForm[1];
            $rightForm = [$rightForm[1], $rightForm[0]];
            $rightPower = \abs($rightPower);
            if ($this->isZeroPolynomial($rightForm[1])) {
                return null;
            }
        }
        $leftNumerator = $this->power($leftForm[0], [[(string) $leftPower, '1']]);
        $leftDenominator = $this->power($leftForm[1], [[(string) $leftPower, '1']]);
        $rightNumerator = $this->power($rightForm[0], [[(string) $rightPower, '1']]);
        $rightDenominator = $this->power($rightForm[1], [[(string) $rightPower, '1']]);
        if (
            $leftNumerator === null || $leftDenominator === null
            || $rightNumerator === null || $rightDenominator === null
        ) {
            return null;
        }
        $leftTerm = $this->multiplyPolynomials($leftNumerator, $rightDenominator);
        $rightTerm = $this->multiplyPolynomials($rightNumerator, $leftDenominator);
        $denominator = $this->multiplyPolynomials($leftDenominator, $rightDenominator);
        $domainFactors = [
            [$leftDomainDenominator, $leftPower],
            [$rightDomainDenominator, $rightPower],
        ];
        foreach ($domainFactors as [$domainFactor, $power]) {
            if ($domainFactor === null) {
                continue;
            }
            $poweredDomainFactor = $this->power($domainFactor, [[(string) $power, '1']]);
            if ($poweredDomainFactor === null) {
                return null;
            }
            $denominator = $denominator === null
                ? null
                : $this->multiplyPolynomials($denominator, $poweredDomainFactor);
        }
        $numerator = $leftTerm === null || $rightTerm === null
            ? null
            : $this->combine($leftTerm, $rightTerm, true);
        if ($numerator === null || $denominator === null) {
            return null;
        }
        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);
        if ($integerNumerator === null || $integerDenominator === null || $this->isZeroPolynomial($denominator)) {
            return null;
        }

        return [$integerNumerator, $integerDenominator];
    }

    /**
     * Extract the equation between products of integer powers of rational functions.
     *
     * @param list<array{Node, int}> $leftFactors
     * @param list<array{Node, int}> $rightFactors
     * @return array{list<string>, list<string>}|false|null
     */
    public function rationalMonomialDifference(array $leftFactors, array $rightFactors): array|false|null
    {
        $leftForm = $this->rationalMonomialForm($leftFactors);
        $rightForm = $this->rationalMonomialForm($rightFactors);
        if ($leftForm === false || $rightForm === false) {
            return false;
        }
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        $leftTerm = $this->multiplyPolynomials($leftForm[0], $rightForm[1]);
        $rightTerm = $this->multiplyPolynomials($rightForm[0], $leftForm[1]);
        $denominator = $this->multiplyPolynomials($leftForm[1], $rightForm[1]);
        if ($leftTerm === null || $rightTerm === null || $denominator === null) {
            return null;
        }
        $numerator = $this->combine($leftTerm, $rightTerm, true);
        if ($numerator === null) {
            return null;
        }
        foreach ([...$leftForm[2], ...$rightForm[2]] as $excludedFactor) {
            $denominator = $this->multiplyPolynomials($denominator, $excludedFactor);
            if ($denominator === null) {
                return null;
            }
        }
        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);
        if (
            $integerNumerator === null
            || $integerDenominator === null
            || $this->isZeroPolynomial($denominator)
        ) {
            return null;
        }

        return [$integerNumerator, $integerDenominator];
    }

    /**
     * @param list<array{Node, int}> $factors
     * @return array{
     *     list<array{string, string}>,
     *     list<array{string, string}>,
     *     list<list<array{string, string}>>
     * }|false|null
     */
    private function rationalMonomialForm(array $factors): array|false|null
    {
        $numerator = [['1', '1']];
        $denominator = [['1', '1']];
        $excludedFactors = [];
        foreach ($factors as [$base, $power]) {
            if ($power === 0 || $power === \PHP_INT_MIN) {
                return null;
            }
            $form = $this->rationalForm($base);
            if ($form === null) {
                return null;
            }
            if ($power < 0) {
                if ($this->isZeroPolynomial($form[0])) {
                    return false;
                }
                $excludedFactors[] = $form[1];
                $form = [$form[1], $form[0]];
                $power = \abs($power);
            }
            $poweredNumerator = $this->power($form[0], [[(string) $power, '1']]);
            $poweredDenominator = $this->power($form[1], [[(string) $power, '1']]);
            if ($poweredNumerator === null || $poweredDenominator === null) {
                return null;
            }
            $numerator = $this->multiplyPolynomials($numerator, $poweredNumerator);
            $denominator = $this->multiplyPolynomials($denominator, $poweredDenominator);
            if ($numerator === null || $denominator === null) {
                return null;
            }
        }

        return [$numerator, $denominator, $excludedFactors];
    }

    /**
     * Extract the numerator and denominator of a rational-function sum.
     *
     * @return array{list<string>, list<string>}|null Integer coefficients in ascending order.
     */
    public function rationalSum(Node $left, Node $right): ?array
    {
        return $this->rationalCombination($left, $right, false);
    }

    /**
     * @return array{list<string>, list<string>}|null
     */
    private function rationalCombination(Node $left, Node $right, bool $subtract): ?array
    {
        $leftForm = $this->rationalForm($left);
        $rightForm = $this->rationalForm($right);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        $leftTerm = $this->multiplyPolynomials($leftForm[0], $rightForm[1]);
        $rightTerm = $this->multiplyPolynomials($rightForm[0], $leftForm[1]);
        $numerator = $leftTerm === null || $rightTerm === null
            ? null
            : $this->combine($leftTerm, $rightTerm, $subtract);
        $denominator = $this->multiplyPolynomials($leftForm[1], $rightForm[1]);
        if ($numerator === null || $denominator === null) {
            return null;
        }
        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);
        if ($integerNumerator === null || $integerDenominator === null) {
            return null;
        }
        foreach ($integerDenominator as $coefficient) {
            if ($coefficient !== '0') {
                return [$integerNumerator, $integerDenominator];
            }
        }

        return null;
    }

    /**
     * Extract the rational polynomial equation equivalent to |left| = |right|.
     * The product of the sum and difference includes both sign branches.
     *
     * @return array{list<string>, list<string>}|null Integer coefficients in ascending order.
     */
    public function absoluteRationalDifference(
        Node $left,
        Node $right,
        bool $excludeZeroArguments = false,
    ): ?array {
        $leftForm = $this->rationalForm($left);
        $rightForm = $this->rationalForm($right);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        if (
            $excludeZeroArguments
            && ($this->isZeroPolynomial($leftForm[0]) || $this->isZeroPolynomial($rightForm[0]))
        ) {
            return [['1'], ['1']];
        }

        $leftCrossProduct = $this->multiplyPolynomials($leftForm[0], $rightForm[1]);
        $rightCrossProduct = $this->multiplyPolynomials($rightForm[0], $leftForm[1]);
        $denominator = $this->multiplyPolynomials($leftForm[1], $rightForm[1]);
        if ($leftCrossProduct === null || $rightCrossProduct === null || $denominator === null) {
            return null;
        }
        $difference = $this->combine($leftCrossProduct, $rightCrossProduct, true);
        $sum = $this->combine($leftCrossProduct, $rightCrossProduct, false);
        if ($difference === null || $sum === null) {
            return null;
        }
        $numerator = $this->multiplyPolynomials($difference, $sum);
        if ($numerator === null) {
            return null;
        }
        if ($excludeZeroArguments) {
            $denominator = $this->multiplyPolynomials($denominator, $leftForm[0]);
            $denominator = $denominator === null
                ? null
                : $this->multiplyPolynomials($denominator, $rightForm[0]);
            if ($denominator === null) {
                return null;
            }
        }

        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);
        if ($integerNumerator === null || $integerDenominator === null) {
            return null;
        }

        return [$integerNumerator, $integerDenominator];
    }

    /**
     * Extract the numerator and denominator of a rational-function difference from an exact fraction.
     *
     * @param array{string, string} $right
     * @return array{list<string>, list<string>}|null
     */
    public function rationalDifferenceFromFraction(Node $left, array $right): ?array
    {
        $leftForm = $this->rationalForm($left);
        $right = $this->reduceFraction($right);
        if ($leftForm === null || $right === null) {
            return null;
        }
        $rightTerm = $this->multiplyPolynomials([$right], $leftForm[1]);
        $numerator = $rightTerm === null ? null : $this->combine($leftForm[0], $rightTerm, true);
        if ($numerator === null) {
            return null;
        }
        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($leftForm[1]);
        if ($integerNumerator === null || $integerDenominator === null) {
            return null;
        }
        foreach ($integerDenominator as $coefficient) {
            if ($coefficient !== '0') {
                return [$integerNumerator, $integerDenominator];
            }
        }

        return null;
    }

    /**
     * Extract left = 0 while retaining the rational-function holes of equivalent forms.
     *
     * @param list<Node> $domainNodes Algebraically equivalent forms whose denominator zeros remain excluded.
     * @return array{list<string>, list<string>}|null
     */
    public function rationalZeroWithDomains(Node $left, array $domainNodes): ?array
    {
        $leftForm = $this->rationalForm($left);
        if ($leftForm === null) {
            return null;
        }
        $denominator = $leftForm[1];
        foreach ($domainNodes as $domainNode) {
            $domainForm = $this->rationalForm($domainNode);
            if ($domainForm === null) {
                return null;
            }
            $denominator = $this->multiplyPolynomials($denominator, $domainForm[1]);
            if ($denominator === null) {
                return null;
            }
        }
        $integerNumerator = $this->integerCoefficients($leftForm[0]);
        $integerDenominator = $this->integerCoefficients($denominator);
        if (
            $integerNumerator === null
            || $integerDenominator === null
            || $this->isZeroPolynomial($denominator)
        ) {
            return null;
        }

        return [$integerNumerator, $integerDenominator];
    }

    /**
     * Extract base^power = target, optionally unioned with base = 0.
     *
     * @param array{string, string} $target
     * @param list<Node> $domainNodes Equivalent rational forms whose holes remain excluded.
     * @return array{list<string>, list<string>}|null
     */
    public function rationalPowerLevelEquation(
        Node $base,
        int $power,
        array $target,
        bool $includeZero,
        array $domainNodes = [],
    ): ?array {
        if ($power < 1) {
            return null;
        }
        $form = $this->rationalForm($base);
        $target = $this->reduceFraction($target);
        if ($form === null || $target === null) {
            return null;
        }
        $poweredNumerator = $this->power($form[0], [[(string) $power, '1']]);
        $poweredDenominator = $this->power($form[1], [[(string) $power, '1']]);
        if ($poweredNumerator === null || $poweredDenominator === null) {
            return null;
        }
        $targetTerm = $this->multiplyPolynomials([$target], $poweredDenominator);
        if ($targetTerm === null) {
            return null;
        }
        $numerator = $this->combine($poweredNumerator, $targetTerm, true);
        if ($numerator === null) {
            return null;
        }
        if ($includeZero) {
            $numerator = $this->multiplyPolynomials($numerator, $form[0]);
            if ($numerator === null) {
                return null;
            }
        }
        $denominator = $poweredDenominator;
        foreach ($domainNodes as $domainNode) {
            $domainForm = $this->rationalForm($domainNode);
            if ($domainForm === null) {
                return null;
            }
            $denominator = $this->multiplyPolynomials($denominator, $domainForm[1]);
            if ($denominator === null) {
                return null;
            }
        }
        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);
        if (
            $integerNumerator === null
            || $integerDenominator === null
            || $this->isZeroPolynomial($denominator)
        ) {
            return null;
        }

        return [$integerNumerator, $integerDenominator];
    }

    /**
     * Compose an exact rational polynomial with a rational-function base.
     *
     * @param list<array{string, string}> $coefficients Ascending powers.
     * @param list<Node> $domainNodes Additional rational-function domains to preserve.
     * @return array{list<string>, list<string>}|null
     */
    public function rationalPolynomialInRationalFunction(
        Node $base,
        array $coefficients,
        array $domainNodes = [],
        bool $includeBaseZero = false,
        bool $excludeBaseZero = false,
    ): ?array {
        $baseForm = $this->rationalForm($base);
        if ($baseForm === null || $coefficients === []) {
            return null;
        }
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)][0] === '0') {
            \array_pop($coefficients);
        }

        $highestDegree = \array_key_last($coefficients);
        if ($highestDegree === null) {
            return null;
        }
        $numerator = [$coefficients[$highestDegree]];
        $denominator = [['1', '1']];
        for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
            $nextNumerator = $this->multiplyPolynomials($numerator, $baseForm[0]);
            $denominator = $this->multiplyPolynomials($denominator, $baseForm[1]);
            if ($nextNumerator === null || $denominator === null) {
                return null;
            }
            $constantTerm = $this->multiplyPolynomials([$coefficients[$degree]], $denominator);
            if ($constantTerm === null) {
                return null;
            }
            $numerator = $this->combine($nextNumerator, $constantTerm, false);
            if ($numerator === null) {
                return null;
            }
        }

        if ($includeBaseZero) {
            $numerator = $this->multiplyPolynomials($numerator, $baseForm[0]);
            if ($numerator === null) {
                return null;
            }
        }
        if ($excludeBaseZero) {
            $denominator = $this->multiplyPolynomials($denominator, $baseForm[0]);
            if ($denominator === null) {
                return null;
            }
        }
        foreach ($domainNodes as $domainNode) {
            $domainForm = $this->rationalForm($domainNode);
            if ($domainForm === null) {
                return null;
            }
            $denominator = $this->multiplyPolynomials($denominator, $domainForm[1]);
            if ($denominator === null) {
                return null;
            }
        }
        if ($this->isZeroPolynomial($denominator)) {
            return null;
        }

        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);

        return $integerNumerator === null || $integerDenominator === null
            ? null
            : [$integerNumerator, $integerDenominator];
    }

    /**
     * Compose a rational polynomial with a rational-function base and subtract a target function.
     *
     * @param list<array{string, string}> $coefficients Ascending powers.
     * @param list<Node> $domainNodes Additional domain expressions to preserve.
     * @return array{list<string>, list<string>}|null Integer numerator and denominator coefficients.
     */
    public function rationalPolynomialDifferenceInRationalFunction(
        Node $base,
        array $coefficients,
        Node $target,
        array $domainNodes = [],
    ): ?array {
        $baseForm = $this->rationalForm($base);
        $targetForm = $this->rationalForm($target);
        if ($baseForm === null || $targetForm === null || $coefficients === []) {
            return null;
        }
        while (\count($coefficients) > 1 && $coefficients[\array_key_last($coefficients)][0] === '0') {
            \array_pop($coefficients);
        }

        $highestDegree = \array_key_last($coefficients);
        if ($highestDegree === null) {
            return null;
        }
        $numerator = [$coefficients[$highestDegree]];
        $denominator = [['1', '1']];
        for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
            $nextNumerator = $this->multiplyPolynomials($numerator, $baseForm[0]);
            $denominator = $this->multiplyPolynomials($denominator, $baseForm[1]);
            if ($nextNumerator === null || $denominator === null) {
                return null;
            }
            $constantTerm = $this->multiplyPolynomials([$coefficients[$degree]], $denominator);
            if ($constantTerm === null) {
                return null;
            }
            $numerator = $this->combine($nextNumerator, $constantTerm, false);
            if ($numerator === null) {
                return null;
            }
        }

        $leftTerm = $this->multiplyPolynomials($numerator, $targetForm[1]);
        $rightTerm = $this->multiplyPolynomials($targetForm[0], $denominator);
        $denominator = $this->multiplyPolynomials($denominator, $targetForm[1]);
        if ($leftTerm === null || $rightTerm === null || $denominator === null) {
            return null;
        }
        $numerator = $this->combine($leftTerm, $rightTerm, true);
        if ($numerator === null) {
            return null;
        }

        foreach ([...$domainNodes, $base, $target] as $domainNode) {
            $domainForm = $this->rationalForm($domainNode);
            if ($domainForm === null) {
                return null;
            }
            $denominator = $this->multiplyPolynomials($denominator, $domainForm[1]);
            if ($denominator === null) {
                return null;
            }
        }
        if ($this->isZeroPolynomial($denominator)) {
            return null;
        }

        $integerNumerator = $this->integerCoefficients($numerator);
        $integerDenominator = $this->integerCoefficients($denominator);

        return $integerNumerator === null || $integerDenominator === null
            ? null
            : [$integerNumerator, $integerDenominator];
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    public function multiplyExactFractions(array $left, array $right): ?array
    {
        $left = $this->reduceFraction($left);
        $right = $this->reduceFraction($right);

        return $left === null || $right === null ? null : $this->multiplyFractions($left, $right);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    public function addExactFractions(array $left, array $right): ?array
    {
        $left = $this->reduceFraction($left);
        $right = $this->reduceFraction($right);

        return $left === null || $right === null ? null : $this->addFractions($left, $right);
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    public function powerExactFraction(array $fraction, int $power): ?array
    {
        if ($power === \PHP_INT_MIN) {
            return null;
        }
        $fraction = $this->reduceFraction($fraction);
        if ($fraction === null) {
            return null;
        }
        if ($power < 0) {
            $fraction = $this->reduceFraction([$fraction[1], $fraction[0]]);
            if ($fraction === null) {
                return null;
            }
            $power = \abs($power);
        }
        $result = ['1', '1'];
        while ($power > 0) {
            if (($power & 1) === 1) {
                $result = $this->multiplyFractions($result, $fraction);
                if ($result === null) {
                    return null;
                }
            }
            $power = intdiv($power, 2);
            if ($power > 0) {
                $fraction = $this->multiplyFractions($fraction, $fraction);
                if ($fraction === null) {
                    return null;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{list<array{string, string}>, list<array{string, string}>}|null
     */
    public function rationalPolynomialForm(Node $node): ?array
    {
        return $this->rationalForm($node);
    }

    public function isConstantRationalFunction(Node $node): ?bool
    {
        $form = $this->rationalForm($node);
        if ($form === null) {
            return null;
        }
        [$numerator, $denominator] = $form;
        $referenceDegree = null;
        foreach ($denominator as $degree => $coefficient) {
            if ($coefficient[0] !== '0') {
                $referenceDegree = $degree;
                break;
            }
        }
        if ($referenceDegree === null) {
            return null;
        }
        $referenceNumerator = $numerator[$referenceDegree] ?? ['0', '1'];
        $referenceDenominator = $denominator[$referenceDegree];
        for ($degree = 0, $length = \max(\count($numerator), \count($denominator)); $degree < $length; ++$degree) {
            $scaledDenominator = $this->multiplyFractions(
                $referenceNumerator,
                $denominator[$degree] ?? ['0', '1'],
            );
            $scaledNumerator = $this->multiplyFractions(
                $referenceDenominator,
                $numerator[$degree] ?? ['0', '1'],
            );
            if ($scaledDenominator === null || $scaledNumerator === null) {
                return null;
            }
            if ($scaledDenominator !== $scaledNumerator) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{list<array{string, string}>, list<array{string, string}>}|null
     */
    private function rationalForm(Node $node): ?array
    {
        if (!$this->containsVariable($node)) {
            $constant = RationalLiteral::fromNodeAsStrings($node);
            if ($constant !== null) {
                $reducedConstant = $this->reduceFraction($constant);

                return $reducedConstant === null
                    ? null
                    : [[ $reducedConstant ], [['1', '1']]];
            }
        }
        if ($node instanceof GroupingNode) {
            return $this->rationalForm($node->expression);
        }
        if ($node instanceof UnaryOperationNode) {
            $form = $this->rationalForm($node->operand);
            if ($form === null || $node->operator === UnaryOperator::Plus) {
                return $form;
            }
            foreach ($form[0] as &$coefficient) {
                if ($coefficient[0] !== '0') {
                    $coefficient[0] = $this->integerArithmetic->negateIntegerValue($coefficient[0]);
                }
            }
            unset($coefficient);

            return $form;
        }
        if (
            $node instanceof FunctionCallNode
            && \count($node->arguments) === 1
            && $this->evaluationOptions !== null
            && $this->evaluationOptions->functions->find($node->name) === null
            && ($node->name === $this->variable || \array_key_exists($node->name, $this->parameters))
        ) {
            if ($node->name === $this->variable) {
                $factor = [['0', '1'], ['1', '1']];
            } else {
                $parameter = $this->parameters[$node->name];
                $fraction = \is_int($parameter)
                    ? [(string) $parameter, '1']
                    : RationalLiteral::fromFloatExactAsStrings($parameter);
                $factor = $fraction === null ? null : [[$fraction[0], $fraction[1]]];
            }
            $argument = $this->rationalForm($node->arguments[0]);
            if ($factor === null || $argument === null) {
                return null;
            }
            $numerator = $this->multiplyPolynomials($factor, $argument[0]);

            return $numerator === null ? null : [$numerator, $argument[1]];
        }
        if (!$node instanceof BinaryOperationNode) {
            $polynomial = $this->polynomial($node);

            return $polynomial === null ? null : [$polynomial, [['1', '1']]];
        }

        $left = $this->rationalForm($node->left);
        $right = $this->rationalForm($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        if ($node->operator === BinaryOperator::Power) {
            $exponent = $this->polynomial($node->right);
            if ($exponent === null || \count($exponent) !== 1 || $exponent[0][1] !== '1') {
                return null;
            }
            $power = \filter_var($exponent[0][0], FILTER_VALIDATE_INT);
            if (
                !\is_int($power)
                || \abs($power) > $this->maxDegree
                || ($this->evaluationOptions !== null
                    && \abs($power) > $this->evaluationOptions->limits->maxExponentMagnitude)
            ) {
                return null;
            }
            if ($power === 0) {
                return [$left[1], $left[1]];
            }
            $numerator = $this->power($left[0], [[(string) \abs($power), '1']]);
            $denominator = $this->power($left[1], [[(string) \abs($power), '1']]);
            if ($numerator === null || $denominator === null) {
                return null;
            }
            if ($power < 0) {
                [$numerator, $denominator] = [$denominator, $numerator];
                $numerator = $this->multiplyPolynomials($numerator, $left[1]);
                $denominator = $this->multiplyPolynomials($denominator, $left[1]);
                if ($numerator === null || $denominator === null) {
                    return null;
                }
            }

            return $this->isZeroPolynomial($denominator)
                ? null
                : [$numerator, $denominator];
        }

        $numerator = null;
        $denominator = $this->multiplyPolynomials($left[1], $right[1]);
        if ($denominator === null) {
            return null;
        }
        switch ($node->operator) {
            case BinaryOperator::Add:
            case BinaryOperator::Subtract:
                $leftTerm = $this->multiplyPolynomials($left[0], $right[1]);
                $rightTerm = $this->multiplyPolynomials($right[0], $left[1]);
                $numerator = $leftTerm === null || $rightTerm === null
                    ? null
                    : $this->combine(
                        $leftTerm,
                        $rightTerm,
                        $node->operator === BinaryOperator::Subtract,
                    );
                break;
            case BinaryOperator::Multiply:
                $numerator = $this->multiplyPolynomials($left[0], $right[0]);
                break;
            case BinaryOperator::Divide:
                $numerator = $this->multiplyPolynomials($left[0], $right[1]);
                $denominator = $this->multiplyPolynomials($left[1], $right[0]);
                if ($numerator !== null && $denominator !== null) {
                    $numerator = $this->multiplyPolynomials($numerator, $right[1]);
                    $denominator = $this->multiplyPolynomials($denominator, $right[1]);
                }
                break;
            case BinaryOperator::Modulo:
                return null;
            case BinaryOperator::Power:
                return null;
        }
        if ($numerator === null || $denominator === null || $this->isZeroPolynomial($denominator)) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /** @param list<array{string, string}> $polynomial */
    private function isZeroPolynomial(array $polynomial): bool
    {
        foreach ($polynomial as [$coefficient]) {
            if ($coefficient !== '0') {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{string, string}>|null $polynomial
     *  @return list<string>|null
     */
    public function integerCoefficients(?array $polynomial): ?array
    {
        if ($polynomial === null) {
            return null;
        }

        $commonDenominator = '1';
        foreach ($polynomial as [, $denominator]) {
            $gcd = $this->integerArithmetic->greatestCommonDivisorValues(
                $commonDenominator,
                $denominator,
            );
            $factor = $this->integerArithmetic->divideIntegerValueExactly($denominator, $gcd);
            if ($factor === null) {
                return null;
            }
            $commonDenominator = $this->integerArithmetic->multiplyIntegerValues(
                $commonDenominator,
                $factor,
            );
            if (!$this->withinLimit($commonDenominator)) {
                return null;
            }
        }

        $coefficients = [];
        foreach ($polynomial as [$numerator, $denominator]) {
            $factor = $this->integerArithmetic->divideIntegerValueExactly($commonDenominator, $denominator);
            if ($factor === null) {
                return null;
            }
            $coefficient = $this->integerArithmetic->multiplyIntegerValues($numerator, $factor);
            $coefficients[] = $coefficient;
        }

        $commonFactor = '0';
        foreach ($coefficients as $coefficient) {
            $commonFactor = $this->integerArithmetic->greatestCommonDivisorValues(
                $commonFactor,
                $coefficient,
            );
        }
        if ($commonFactor !== '0' && $commonFactor !== '1') {
            foreach ($coefficients as $index => $coefficient) {
                $primitiveCoefficient = $this->integerArithmetic->divideIntegerValueExactly(
                    $coefficient,
                    $commonFactor,
                );
                if ($primitiveCoefficient === null) {
                    return null;
                }
                $coefficients[$index] = $primitiveCoefficient;
            }
        }
        foreach ($coefficients as $coefficient) {
            if (!$this->withinLimit($coefficient)) {
                return null;
            }
        }

        return $coefficients;
    }

    /**
     * @param array{int, int}|null $fraction
     * @return array{string, string}|null
     */
    public function parameterFraction(?array $fraction): ?array
    {
        if ($fraction === null || $fraction[1] === 0) {
            return null;
        }

        return $this->reduceFraction([(string) $fraction[0], (string) $fraction[1]]);
    }

    /** @return list<array{string, string}>|null */
    public function polynomial(Node $node): ?array
    {
        if (!$this->containsVariable($node)) {
            $constant = RationalLiteral::fromNodeAsStrings($node);
            if ($constant !== null) {
                $reducedConstant = $this->reduceFraction($constant);

                return $reducedConstant === null ? null : [$reducedConstant];
            }
        }
        if ($node instanceof VariableNode) {
            if ($node->name === $this->variable) {
                return [['0', '1'], ['1', '1']];
            }
            $parameter = $this->parameters[$node->name] ?? null;
            if (\is_int($parameter)) {
                return [[(string) $parameter, '1']];
            }
            if (\is_float($parameter)) {
                $fraction = RationalLiteral::fromFloatExactAsStrings($parameter);

                return $fraction === null ? null : [$fraction];
            }

            return null;
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
                if ($fraction[0] !== '0') {
                    $fraction[0] = $this->integerArithmetic->negateIntegerValue($fraction[0]);
                }
            }
            unset($fraction);

            return $operand;
        }
        if (
            $node instanceof FunctionCallNode
            && \count($node->arguments) === 1
            && $this->evaluationOptions !== null
            && $this->evaluationOptions->functions->find($node->name) === null
            && ($node->name === $this->variable || \array_key_exists($node->name, $this->parameters))
        ) {
            if ($node->name === $this->variable) {
                $factor = [['0', '1'], ['1', '1']];
            } else {
                $parameter = $this->parameters[$node->name];
                $fraction = \is_int($parameter)
                    ? [(string) $parameter, '1']
                    : RationalLiteral::fromFloatExactAsStrings($parameter);
                $factor = $fraction === null ? null : [[$fraction[0], $fraction[1]]];
            }
            $argument = $this->polynomial($node->arguments[0]);
            if ($factor === null || $argument === null) {
                return null;
            }

            return $this->multiplyPolynomials($factor, $argument);
        }

        if (!$node instanceof BinaryOperationNode) {
            $fraction = RationalLiteral::fromNodeAsStrings($node);
            if ($fraction !== null) {
                $reducedFraction = $this->reduceFraction($fraction);

                return $reducedFraction === null ? null : [$reducedFraction];
            }

            return null;
        }

        $left = $this->polynomial($node->left);
        $right = $this->polynomial($node->right);
        if ($left === null || $right === null) {
            return null;
        }
        return match ($node->operator) {
            BinaryOperator::Add => $this->combine($left, $right, false),
            BinaryOperator::Subtract => $this->combine($left, $right, true),
            BinaryOperator::Multiply => $this->multiplyPolynomials($left, $right),
            BinaryOperator::Divide => $this->dividePolynomial($left, $right),
            BinaryOperator::Power => $this->power($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /**
     * @param list<array{string, string}> $left
     * @param list<array{string, string}> $right
     * @return list<array{string, string}>|null
     */
    private function combine(array $left, array $right, bool $subtract): ?array
    {
        $length = \max(\count($left), \count($right));
        $result = [];
        for ($index = 0; $index < $length; ++$index) {
            $leftFraction = $left[$index] ?? ['0', '1'];
            $rightFraction = $right[$index] ?? ['0', '1'];
            if ($subtract && $rightFraction[0] !== '0') {
                $rightFraction[0] = $this->integerArithmetic->negateIntegerValue($rightFraction[0]);
            }
            $sum = $this->addFractions($leftFraction, $rightFraction);
            if ($sum === null) {
                return null;
            }
            $result[] = $sum;
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{string, string}> $left
     * @param list<array{string, string}> $right
     * @return list<array{string, string}>|null
     */
    private function multiplyPolynomials(array $left, array $right): ?array
    {
        $degree = \count($left) + \count($right) - 2;
        if ($degree > $this->maxDegree) {
            return null;
        }
        $result = \array_fill(0, $degree + 1, ['0', '1']);
        foreach ($left as $leftDegree => $leftFraction) {
            foreach ($right as $rightDegree => $rightFraction) {
                $product = $this->multiplyFractions($leftFraction, $rightFraction);
                if ($product === null) {
                    return null;
                }
                $index = $leftDegree + $rightDegree;
                $sum = $this->addFractions($result[$index], $product);
                if ($sum === null) {
                    return null;
                }
                $result[$index] = $sum;
            }
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{string, string}> $numerator
     * @param list<array{string, string}> $denominator
     * @return list<array{string, string}>|null
     */
    private function dividePolynomial(array $numerator, array $denominator): ?array
    {
        if (\count($denominator) !== 1 || $denominator[0][0] === '0') {
            return null;
        }
        [$divisorNumerator, $divisorDenominator] = $denominator[0];
        $reciprocal = $this->reduceFraction([$divisorDenominator, $divisorNumerator]);
        if ($reciprocal === null) {
            return null;
        }
        $result = [];
        foreach ($numerator as $fraction) {
            $quotient = $this->multiplyFractions($fraction, $reciprocal);
            if ($quotient === null) {
                return null;
            }
            $result[] = $quotient;
        }

        return $this->trim($result);
    }

    /**
     * @param list<array{string, string}> $base
     * @param list<array{string, string}> $base
     * @param list<array{string, string}> $exponent
     * @return list<array{string, string}>|null
     */
    private function power(array $base, array $exponent): ?array
    {
        if (\count($exponent) !== 1 || $exponent[0][1] !== '1') {
            return null;
        }
        $power = \filter_var($exponent[0][0], FILTER_VALIDATE_INT);
        if (
            !\is_int($power)
            || $power < 0
            || ($this->evaluationOptions !== null
                && $power > $this->evaluationOptions->limits->maxExponentMagnitude)
            || ($power > 1 && (\count($base) - 1) * $power > $this->maxDegree)
        ) {
            return null;
        }
        $result = [['1', '1']];
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
     * @param array{string, string} $left
     * @param array{string, string} $right
     * @return array{string, string}|null
     */
    private function addFractions(array $left, array $right): ?array
    {
        $gcd = $this->integerArithmetic->greatestCommonDivisorValues($left[1], $right[1]);
        $leftScale = $this->integerArithmetic->divideIntegerValueExactly($right[1], $gcd);
        $rightScale = $this->integerArithmetic->divideIntegerValueExactly($left[1], $gcd);
        if ($leftScale === null || $rightScale === null) {
            return null;
        }
        $leftNumerator = $this->integerArithmetic->multiplyIntegerValues($left[0], $leftScale);
        $rightNumerator = $this->integerArithmetic->multiplyIntegerValues($right[0], $rightScale);
        $numerator = $this->integerArithmetic->addIntegerValues($leftNumerator, $rightNumerator);
        $denominator = $this->integerArithmetic->multiplyIntegerValues($left[1], $leftScale);

        return $this->reduceFraction([$numerator, $denominator]);
    }

    /**
     * @param array{string, string} $left
     * @param array{string, string} $right
     * @return array{string, string}|null
     */
    private function multiplyFractions(array $left, array $right): ?array
    {
        $leftReduction = $this->integerArithmetic->greatestCommonDivisorValues($left[0], $right[1]);
        $rightReduction = $this->integerArithmetic->greatestCommonDivisorValues($right[0], $left[1]);
        $leftNumerator = $this->integerArithmetic->divideIntegerValueExactly($left[0], $leftReduction);
        $rightDenominator = $this->integerArithmetic->divideIntegerValueExactly($right[1], $leftReduction);
        $rightNumerator = $this->integerArithmetic->divideIntegerValueExactly($right[0], $rightReduction);
        $leftDenominator = $this->integerArithmetic->divideIntegerValueExactly($left[1], $rightReduction);
        if (
            $leftNumerator === null
            || $rightDenominator === null
            || $rightNumerator === null
            || $leftDenominator === null
        ) {
            return null;
        }

        return $this->reduceFraction([
            $this->integerArithmetic->multiplyIntegerValues($leftNumerator, $rightNumerator),
            $this->integerArithmetic->multiplyIntegerValues($leftDenominator, $rightDenominator),
        ]);
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private function reduceFraction(array $fraction): ?array
    {
        [$numerator, $denominator] = $fraction;
        if ($denominator === '0') {
            return null;
        }
        if ($denominator[0] === '-') {
            $denominator = \substr($denominator, 1);
            if ($numerator !== '0') {
                $numerator = $this->integerArithmetic->negateIntegerValue($numerator);
            }
        }
        if ($numerator === '0') {
            return ['0', '1'];
        }
        $gcd = $this->integerArithmetic->greatestCommonDivisorValues($numerator, $denominator);
        $reducedNumerator = $this->integerArithmetic->divideIntegerValueExactly($numerator, $gcd);
        $reducedDenominator = $this->integerArithmetic->divideIntegerValueExactly($denominator, $gcd);
        if ($reducedNumerator === null || $reducedDenominator === null) {
            return null;
        }
        if (!$this->withinLimit($reducedNumerator) || !$this->withinLimit($reducedDenominator)) {
            return null;
        }

        return [$reducedNumerator, $reducedDenominator];
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function subtractExactFractions(array $left, array $right): ?array
    {
        if ($right[0] !== '0') {
            $right[0] = $this->integerArithmetic->negateIntegerValue($right[0]);
        }

        return $this->addFractions($left, $right);
    }

    /**
     * @param list<array{string, string}> $polynomial
     * @return list<array{string, string}>
     */
    private function trim(array $polynomial): array
    {
        while (\count($polynomial) > 1 && $polynomial[\array_key_last($polynomial)][0] === '0') {
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
        if ($node instanceof FactorialNode) {
            return $this->containsVariable($node->operand);
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsVariable($argument)) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariable($node->left) || $this->containsVariable($node->right);
        }

        return false;
    }

    private function withinLimit(string $integer): bool
    {
        return \strlen(\ltrim($integer, '-')) <= self::MAX_INTEGER_DIGITS;
    }
}
