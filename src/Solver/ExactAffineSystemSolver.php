<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\ConstantNode;
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
use MathPHP\Exception\EvaluationException;

/** Exact Gaussian elimination for affine systems with rational coefficients. */
final class ExactAffineSystemSolver
{
    private const MAX_INTEGER_DIGITS = 4096;

    private const MAX_CONSTANT_POWER = 256;

    private readonly IntegerPolynomialExtractor $integerArithmetic;

    public function __construct()
    {
        $this->integerArithmetic = new IntegerPolynomialExtractor('', [], 1);
    }

    /**
     * @param list<array{Node, Node, int}> $equations
     * @param list<string> $variables
     * @param list<float> $initialValues
     * @param array<string, int|float> $parameters
     * @return list<float>|false|null False means inconsistent; null means unsupported.
     */
    public function solve(
        array $equations,
        array $variables,
        array $initialValues,
        array $parameters,
        EvaluationOptions $options,
    ): array|false|null {
        $variableSet = \array_fill_keys($variables, true);
        $constantEvaluator = new Evaluator(
            new Environment($parameters, $options->functions),
            $options->limits,
        );
        /** @var list<list<array{string, string}>> $matrix */
        $matrix = [];
        foreach ($equations as [$left, $right]) {
            $leftForm = $this->affineForm($left, $variableSet, $parameters, $constantEvaluator, $options);
            $rightForm = $this->affineForm($right, $variableSet, $parameters, $constantEvaluator, $options);
            if ($leftForm === null || $rightForm === null) {
                return null;
            }
            $difference = $this->addForms($leftForm, $rightForm, true);
            if ($difference === null) {
                return null;
            }
            $row = [];
            foreach ($variables as $variable) {
                $row[] = $difference['coefficients'][$variable] ?? ['0', '1'];
            }
            $row[] = $this->negate($difference['constant']);
            $matrix[] = $row;
        }

        $pivotColumns = [];
        $pivotRow = 0;
        foreach ($variables as $column => $_variable) {
            $selectedRow = null;
            for ($row = $pivotRow; $row < \count($matrix); ++$row) {
                if (!$this->isZero($matrix[$row][$column])) {
                    $selectedRow = $row;
                    break;
                }
            }
            if ($selectedRow === null) {
                continue;
            }
            if ($selectedRow !== $pivotRow) {
                [$matrix[$selectedRow], $matrix[$pivotRow]] = [$matrix[$pivotRow], $matrix[$selectedRow]];
            }

            $pivot = $matrix[$pivotRow][$column];
            for ($entry = $column; $entry <= \count($variables); ++$entry) {
                $divided = $this->divide($matrix[$pivotRow][$entry], $pivot);
                if ($divided === null) {
                    return null;
                }
                $matrix[$pivotRow][$entry] = $divided;
            }
            for ($row = 0; $row < \count($matrix); ++$row) {
                if ($row === $pivotRow || $this->isZero($matrix[$row][$column])) {
                    continue;
                }
                $factor = $matrix[$row][$column];
                for ($entry = $column; $entry <= \count($variables); ++$entry) {
                    $product = $this->multiply($factor, $matrix[$pivotRow][$entry]);
                    if ($product === null) {
                        return null;
                    }
                    $difference = $this->subtract($matrix[$row][$entry], $product);
                    if ($difference === null) {
                        return null;
                    }
                    $matrix[$row][$entry] = $difference;
                }
            }
            $pivotColumns[] = $column;
            ++$pivotRow;
        }

        foreach ($matrix as $row) {
            $allCoefficientsZero = true;
            for ($column = 0; $column < \count($variables); ++$column) {
                if (!$this->isZero($row[$column])) {
                    $allCoefficientsZero = false;
                    break;
                }
            }
            if ($allCoefficientsZero && !$this->isZero($row[\count($variables)])) {
                return false;
            }
        }
        /** @var list<array{string, string}> $solution */
        $solution = [];
        foreach ($initialValues as $value) {
            $fraction = \MathPHP\Ast\RationalLiteral::fromFloatExactAsStrings($value);
            if ($fraction === null) {
                return null;
            }
            $solution[] = $fraction;
        }
        if (\count($pivotColumns) < \count($variables)) {
            $rank = \count($pivotColumns);
            if ($rank === 0) {
                return $initialValues;
            }

            // Project the initial guess onto the affine constraints in exact
            // rational arithmetic: x = x0 + A^T (A A^T)^-1 (b - A x0).
            /** @var list<list<array{string, string}>> $gram */
            $gram = [];
            /** @var list<array{string, string}> $rightHandSide */
            $rightHandSide = [];
            for ($row = 0; $row < $rank; ++$row) {
                $gramRow = [];
                $constraintAtInitial = ['0', '1'];
                for ($column = 0; $column < \count($variables); ++$column) {
                    $coefficient = $matrix[$row][$column];
                    $initialTerm = $this->multiply($coefficient, $solution[$column]);
                    if ($initialTerm === null) {
                        return null;
                    }
                    $constraintAtInitial = $this->add($constraintAtInitial, $initialTerm);
                    if ($constraintAtInitial === null) {
                        return null;
                    }
                }
                $rowRightHandSide = $this->subtract(
                    $matrix[$row][\count($variables)],
                    $constraintAtInitial,
                );
                if ($rowRightHandSide === null) {
                    return null;
                }
                $rightHandSide[] = $rowRightHandSide;
                $gram[] = $gramRow;
            }
            for ($row = 0; $row < $rank; ++$row) {
                for ($otherRow = 0; $otherRow < $rank; ++$otherRow) {
                    $entry = ['0', '1'];
                    for ($column = 0; $column < \count($variables); ++$column) {
                        $product = $this->multiply(
                            $matrix[$row][$column],
                            $matrix[$otherRow][$column],
                        );
                        if ($product === null) {
                            return null;
                        }
                        $entry = $this->add($entry, $product);
                        if ($entry === null) {
                            return null;
                        }
                    }
                    $gram[$row][$otherRow] = $entry;
                }
            }

            $multipliers = $this->solveRationalLinearSystem($gram, $rightHandSide);
            if ($multipliers === null) {
                return null;
            }
            foreach ($solution as $column => $initial) {
                $change = ['0', '1'];
                for ($row = 0; $row < $rank; ++$row) {
                    $term = $this->multiply($matrix[$row][$column], $multipliers[$row]);
                    if ($term === null) {
                        return null;
                    }
                    $change = $this->add($change, $term);
                    if ($change === null) {
                        return null;
                    }
                }
                $projectedValue = $this->add($initial, $change);
                if ($projectedValue === null) {
                    return null;
                }
                $solution[$column] = $projectedValue;
            }
        }
        if (\count($pivotColumns) === \count($variables)) {
            foreach ($pivotColumns as $row => $column) {
                $value = $matrix[$row][\count($variables)];
                for ($freeColumn = 0; $freeColumn < \count($variables); ++$freeColumn) {
                    if (\in_array($freeColumn, $pivotColumns, true) || $this->isZero($matrix[$row][$freeColumn])) {
                        continue;
                    }
                    $product = $this->multiply($matrix[$row][$freeColumn], $solution[$freeColumn]);
                    if ($product === null) {
                        return null;
                    }
                    $updatedValue = $this->subtract($value, $product);
                    if ($updatedValue === null) {
                        return null;
                    }
                    $value = $updatedValue;
                }
                $solution[$column] = $value;
            }
        }

        $values = [];
        foreach ($solution as [$numerator, $denominator]) {
            $value = $this->integerArithmetic->ratio($numerator, $denominator);
            if (!\is_float($value) || !\is_finite($value)) {
                return null;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Extract the exact affine form of one equation after moving its right
     * side to the left.
     *
     * @param list<string> $variables
     * @param array<string, int|float> $parameters
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    public function equationCoefficientForm(
        Node $left,
        Node $right,
        array $variables,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        $variableSet = \array_fill_keys($variables, true);
        $constantEvaluator = new Evaluator(
            new Environment($parameters, $options->functions),
            $options->limits,
        );
        $leftForm = $this->affineForm($left, $variableSet, $parameters, $constantEvaluator, $options);
        $rightForm = $this->affineForm($right, $variableSet, $parameters, $constantEvaluator, $options);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }

        return $this->addForms($leftForm, $rightForm, true);
    }

    /**
     * @param list<list<array{string, string}>> $matrix
     * @param list<array{string, string}> $rightHandSide
     * @return list<array{string, string}>|null
     */
    private function solveRationalLinearSystem(array $matrix, array $rightHandSide): ?array
    {
        $size = \count($rightHandSide);
        for ($column = 0; $column < $size; ++$column) {
            $pivotRow = null;
            for ($row = $column; $row < $size; ++$row) {
                if (!$this->isZero($matrix[$row][$column])) {
                    $pivotRow = $row;
                    break;
                }
            }
            if ($pivotRow === null) {
                return null;
            }
            if ($pivotRow !== $column) {
                [$matrix[$pivotRow], $matrix[$column]] = [$matrix[$column], $matrix[$pivotRow]];
                [$rightHandSide[$pivotRow], $rightHandSide[$column]] =
                    [$rightHandSide[$column], $rightHandSide[$pivotRow]];
            }

            $pivot = $matrix[$column][$column];
            for ($entry = $column; $entry < $size; ++$entry) {
                $value = $this->divide($matrix[$column][$entry], $pivot);
                if ($value === null) {
                    return null;
                }
                $matrix[$column][$entry] = $value;
            }
            $value = $this->divide($rightHandSide[$column], $pivot);
            if ($value === null) {
                return null;
            }
            $rightHandSide[$column] = $value;
            for ($row = 0; $row < $size; ++$row) {
                if ($row === $column || $this->isZero($matrix[$row][$column])) {
                    continue;
                }
                $factor = $matrix[$row][$column];
                for ($entry = $column; $entry < $size; ++$entry) {
                    $product = $this->multiply($factor, $matrix[$column][$entry]);
                    if ($product === null) {
                        return null;
                    }
                    $value = $this->subtract($matrix[$row][$entry], $product);
                    if ($value === null) {
                        return null;
                    }
                    $matrix[$row][$entry] = $value;
                }
                $product = $this->multiply($factor, $rightHandSide[$column]);
                if ($product === null) {
                    return null;
                }
                $value = $this->subtract($rightHandSide[$row], $product);
                if ($value === null) {
                    return null;
                }
                $rightHandSide[$row] = $value;
            }
        }

        return $rightHandSide;
    }

    /**
     * @param array<string, true> $variableSet
     * @param array<string, int|float> $parameters
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function affineForm(
        Node $node,
        array $variableSet,
        array $parameters,
        Evaluator $constantEvaluator,
        EvaluationOptions $options,
    ): ?array {
        if (
            !$node instanceof VariableNode
            && !$this->containsSolvedVariable($node, $variableSet)
        ) {
            $fraction = \MathPHP\Ast\RationalLiteral::fromNodeAsStrings($node);
            if ($fraction !== null) {
                return $this->constantForm($fraction);
            }
            try {
                $value = $constantEvaluator->evaluate($node);
            } catch (EvaluationException) {
                return null;
            }
            $fraction = \is_int($value)
                ? [(string) $value, '1']
                : \MathPHP\Ast\RationalLiteral::fromFloatExactAsStrings($value);

            return $fraction === null ? null : $this->constantForm($fraction);
        }
        if ($node instanceof NumberNode) {
            $fraction = \MathPHP\Ast\RationalLiteral::fromNodeAsStrings($node);

            return $fraction === null ? null : $this->constantForm($fraction);
        }
        if ($node instanceof VariableNode) {
            if (isset($variableSet[$node->name])) {
                return [
                    'constant' => ['0', '1'],
                    'coefficients' => [$node->name => ['1', '1']],
                ];
            }
            if (!\array_key_exists($node->name, $parameters)) {
                return null;
            }
            $parameter = $parameters[$node->name];
            $fraction = \is_int($parameter)
                ? [(string) $parameter, '1']
                : \MathPHP\Ast\RationalLiteral::fromFloatExactAsStrings($parameter);

            return $fraction === null ? null : $this->constantForm($fraction);
        }
        if ($node instanceof GroupingNode) {
            return $this->affineForm($node->expression, $variableSet, $parameters, $constantEvaluator, $options);
        }
        if ($node instanceof UnaryOperationNode) {
            $operand = $this->affineForm($node->operand, $variableSet, $parameters, $constantEvaluator, $options);
            if ($operand === null || $node->operator === UnaryOperator::Plus) {
                return $operand;
            }

            return $this->scaleForm($operand, ['-1', '1']);
        }
        if ($node instanceof FactorialNode) {
            $operand = $this->affineForm($node->operand, $variableSet, $parameters, $constantEvaluator, $options);
            if ($operand === null || $operand['coefficients'] !== [] || $operand['constant'][1] !== '1') {
                return null;
            }
            $integer = \filter_var($operand['constant'][0], FILTER_VALIDATE_INT);
            if (
                !\is_int($integer)
                || $integer < 0
                || $integer > $options->limits->maxFactorial
            ) {
                return null;
            }
            $factorial = '1';
            for ($factor = 2; $factor <= $integer; ++$factor) {
                $factorial = $this->integerArithmetic->multiplyIntegerValues($factorial, (string) $factor);
            }

            return $this->constantForm([$factorial, '1']);
        }
        if ($node instanceof FunctionCallNode) {
            if ($node->name === 'doublefactorial' && \count($node->arguments) === 1) {
                $argument = $this->affineForm(
                    $node->arguments[0],
                    $variableSet,
                    $parameters,
                    $constantEvaluator,
                    $options,
                );
                if (
                    $argument === null
                    || $argument['coefficients'] !== []
                    || $argument['constant'][1] !== '1'
                ) {
                    return null;
                }
                $integer = \filter_var($argument['constant'][0], FILTER_VALIDATE_INT);
                if (!\is_int($integer) || $integer < -1) {
                    return null;
                }
                $result = 1;
                for ($factor = $integer; $factor > 1; $factor -= 2) {
                    if ($result > \intdiv(\PHP_INT_MAX, $factor)) {
                        return null;
                    }
                    $result *= $factor;
                }

                return $this->constantForm([(string) $result, '1']);
            }
            if (
                \in_array($node->name, ['gcd', 'lcm', 'binomial', 'permutation'], true)
                && \count($node->arguments) === 2
            ) {
                $integers = [];
                foreach ($node->arguments as $argumentNode) {
                    $argument = $this->affineForm(
                        $argumentNode,
                        $variableSet,
                        $parameters,
                        $constantEvaluator,
                        $options,
                    );
                    if (
                        $argument === null
                        || $argument['coefficients'] !== []
                        || $argument['constant'][1] !== '1'
                    ) {
                        return null;
                    }
                    $integer = \filter_var($argument['constant'][0], FILTER_VALIDATE_INT);
                    if (!\is_int($integer) || $integer === \PHP_INT_MIN) {
                        return null;
                    }
                    $integers[] = $integer;
                }
                [$left, $right] = $integers;
                if ($node->name === 'gcd') {
                    $left = \abs($left);
                    $right = \abs($right);
                    while ($right !== 0) {
                        [$left, $right] = [$right, $left % $right];
                    }

                    return $this->constantForm([(string) $left, '1']);
                }
                if ($node->name === 'lcm') {
                    if ($left === 0 || $right === 0) {
                        return $this->constantForm(['0', '1']);
                    }
                    $left = \abs($left);
                    $right = \abs($right);
                    $divisor = $left;
                    $remainder = $right;
                    while ($remainder !== 0) {
                        [$divisor, $remainder] = [$remainder, $divisor % $remainder];
                    }
                    $reduced = \intdiv($left, $divisor);
                    if ($reduced > \intdiv(\PHP_INT_MAX, $right)) {
                        return null;
                    }

                    return $this->constantForm([(string) ($reduced * $right), '1']);
                }
                if ($left < 0 || $right < 0) {
                    return null;
                }
                if ($right > $left) {
                    return $this->constantForm(['0', '1']);
                }
                $work = $node->name === 'binomial' ? \min($right, $left - $right) : $right;
                if ($work > 65_536) {
                    return null;
                }
                $result = 1;
                if ($node->name === 'binomial') {
                    for ($index = 1; $index <= $work; ++$index) {
                        $numerator = $left - $work + $index;
                        $divisor = $this->integerArithmetic->greatestCommonDivisorValues(
                            (string) $numerator,
                            (string) $index,
                        );
                        $numerator = \intdiv($numerator, (int) $divisor);
                        $denominator = \intdiv($index, (int) $divisor);
                        $remainingDivisor = (int) $this->integerArithmetic->greatestCommonDivisorValues(
                            (string) $result,
                            (string) $denominator,
                        );
                        $result = \intdiv($result, $remainingDivisor);
                        $denominator = \intdiv($denominator, $remainingDivisor);
                        if ($denominator !== 1 || $result > \intdiv(\PHP_INT_MAX, $numerator)) {
                            return null;
                        }
                        $result *= $numerator;
                    }
                } else {
                    for ($index = 0; $index < $right; ++$index) {
                        $factor = $left - $index;
                        if ($factor !== 0 && $result > \intdiv(\PHP_INT_MAX, $factor)) {
                            return null;
                        }
                        $result *= $factor;
                    }
                }

                return $this->constantForm([(string) $result, '1']);
            }
            if ($node->name === 'root' && \count($node->arguments) === 2) {
                $argument = $this->affineForm(
                    $node->arguments[0],
                    $variableSet,
                    $parameters,
                    $constantEvaluator,
                    $options,
                );
                $degree = $this->affineForm(
                    $node->arguments[1],
                    $variableSet,
                    $parameters,
                    $constantEvaluator,
                    $options,
                );
                if (
                    $argument === null
                    || $argument['coefficients'] !== []
                    || $degree === null
                    || $degree['coefficients'] !== []
                    || $degree['constant'][1] !== '1'
                ) {
                    return null;
                }
                $integerDegree = \filter_var($degree['constant'][0], FILTER_VALIDATE_INT);
                if (
                    !\is_int($integerDegree)
                    || $integerDegree === 0
                    || \abs($integerDegree) > self::MAX_CONSTANT_POWER
                ) {
                    return null;
                }
                $root = \MathPHP\Ast\RationalLiteral::exactRationalPowerAsStrings(
                    $argument['constant'],
                    1,
                    $integerDegree,
                );

                return $root === null ? null : $this->constantForm($root);
            }
            if ($node->name === 'hypot' && \count($node->arguments) === 2) {
                $squaredSum = ['0', '1'];
                foreach ($node->arguments as $argumentNode) {
                    $argument = $this->affineForm(
                        $argumentNode,
                        $variableSet,
                        $parameters,
                        $constantEvaluator,
                        $options,
                    );
                    if ($argument === null || $argument['coefficients'] !== []) {
                        return null;
                    }
                    $square = $this->multiply($argument['constant'], $argument['constant']);
                    if ($square === null) {
                        return null;
                    }
                    $squaredSum = $this->add($squaredSum, $square);
                    if ($squaredSum === null) {
                        return null;
                    }
                }
                $hypotenuse = \MathPHP\Ast\RationalLiteral::exactRationalPowerAsStrings(
                    $squaredSum,
                    1,
                    2,
                );

                return $hypotenuse === null ? null : $this->constantForm($hypotenuse);
            }
            if (
                \in_array($node->name, ['min', 'max'], true)
                && $node->arguments !== []
                && \count($node->arguments) <= 16
            ) {
                $selected = null;
                foreach ($node->arguments as $argumentNode) {
                    $argument = $this->affineForm(
                        $argumentNode,
                        $variableSet,
                        $parameters,
                        $constantEvaluator,
                        $options,
                    );
                    if ($argument === null) {
                        return null;
                    }
                    if ($selected === null) {
                        $selected = $argument;
                        continue;
                    }
                    $difference = $this->addForms($argument, $selected, true);
                    if ($difference === null || $difference['coefficients'] !== []) {
                        return null;
                    }
                    $comparison = $this->integerArithmetic->sign($difference['constant'][0]);
                    if (
                        ($node->name === 'min' && $comparison < 0)
                        || ($node->name === 'max' && $comparison > 0)
                    ) {
                        $selected = $argument;
                    }
                }

                return $selected;
            }
            if (\count($node->arguments) !== 1) {
                return $this->builtInConstantFunctionForm(
                    $node,
                    $variableSet,
                    $parameters,
                    $constantEvaluator,
                    $options,
                );
            }
            $argument = $this->affineForm($node->arguments[0], $variableSet, $parameters, $constantEvaluator, $options);
            if ($argument === null || $argument['coefficients'] !== []) {
                return null;
            }
            if ($node->name === 'abs') {
                return $this->constantForm([
                    $argument['constant'][0][0] === '-'
                        ? \substr($argument['constant'][0], 1)
                        : $argument['constant'][0],
                    $argument['constant'][1],
                ]);
            }
            if ($node->name === 'sign') {
                $numerator = $argument['constant'][0];

                return $this->constantForm([$numerator === '0' ? '0' : ($numerator[0] === '-' ? '-1' : '1'), '1']);
            }
            if (\in_array($node->name, ['floor', 'ceil', 'round', 'trunc'], true)) {
                $integer = \MathPHP\Ast\RationalLiteral::roundedRationalIntegerAsString(
                    $argument['constant'],
                    $node->name,
                );

                return $integer === null ? null : $this->constantForm([$integer, '1']);
            }
            if ($node->name === 'sqrt' || $node->name === 'cbrt') {
                $root = \MathPHP\Ast\RationalLiteral::exactRationalPowerAsStrings(
                    $argument['constant'],
                    1,
                    $node->name === 'sqrt' ? 2 : 3,
                );

                return $root === null ? null : $this->constantForm($root);
            }
            return $this->builtInConstantFunctionForm(
                $node,
                $variableSet,
                $parameters,
                $constantEvaluator,
                $options,
            );
        }
        if ($node instanceof ConstantNode) {
            return null;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        $left = $this->affineForm($node->left, $variableSet, $parameters, $constantEvaluator, $options);
        $right = $this->affineForm($node->right, $variableSet, $parameters, $constantEvaluator, $options);
        if ($left === null || $right === null) {
            return null;
        }
        if ($node->operator === BinaryOperator::Power && $right['coefficients'] === []) {
            $exponent = $this->integerArithmetic->ratio(
                $right['constant'][0],
                $right['constant'][1],
            );
            if (
                !\is_float($exponent)
                || \abs($exponent) > $options->limits->maxExponentMagnitude
            ) {
                return null;
            }
        }

        return match ($node->operator) {
            BinaryOperator::Add => $this->addForms($left, $right),
            BinaryOperator::Subtract => $this->addForms($left, $right, true),
            BinaryOperator::Multiply => $this->multiplyForms($left, $right),
            BinaryOperator::Divide => $this->divideForms($left, $right),
            BinaryOperator::Power => $this->powerForm($left, $right),
            BinaryOperator::Modulo => null,
        };
    }

    /** @param array<string, true> $variableSet
     *  @param array<string, int|float> $parameters
     *  @return array{constant: array{string, string}, coefficients: array{}}|null
     */
    private function builtInConstantFunctionForm(
        FunctionCallNode $node,
        array $variableSet,
        array $parameters,
        Evaluator $constantEvaluator,
        EvaluationOptions $options,
    ): ?array {
        if (!$options->functions->isBuiltIn($node->name)) {
            return null;
        }
        $definition = $options->functions->find($node->name);
        if (
            $definition === null
            || \count($node->arguments) < $definition->minArguments
            || \count($node->arguments) > $definition->maxArguments
        ) {
            return null;
        }
        $numericArguments = [];
        foreach ($node->arguments as $argumentNode) {
            $argument = $this->affineForm(
                $argumentNode,
                $variableSet,
                $parameters,
                $constantEvaluator,
                $options,
            );
            if ($argument === null || $argument['coefficients'] !== []) {
                return null;
            }
            $numericValue = $this->integerArithmetic->ratio(
                $argument['constant'][0],
                $argument['constant'][1],
            );
            if (!\is_float($numericValue) || !\is_finite($numericValue)) {
                return null;
            }
            $numericArguments[] = $numericValue;
        }
        $numericNodes = [];
        foreach ($numericArguments as $index => $numericArgument) {
            $numericNodes[] = new NumberNode(
                $numericArgument,
                (string) $numericArgument,
                $node->arguments[$index]->span(),
            );
        }
        try {
            $value = $constantEvaluator->evaluate(new FunctionCallNode(
                $node->name,
                $numericNodes,
                $node->nameSpan,
                $node->span,
            ));
        } catch (EvaluationException) {
            return null;
        }
        $fraction = \is_int($value)
            ? [(string) $value, '1']
            : \MathPHP\Ast\RationalLiteral::fromFloatExactAsStrings($value);

        return $fraction === null ? null : $this->constantForm($fraction);
    }

    /** @param array<string, true> $variableSet */
    private function containsSolvedVariable(Node $node, array $variableSet): bool
    {
        if ($node instanceof VariableNode) {
            return isset($variableSet[$node->name]);
        }
        if ($node instanceof GroupingNode) {
            return $this->containsSolvedVariable($node->expression, $variableSet);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsSolvedVariable($node->operand, $variableSet);
        }
        if ($node instanceof FactorialNode) {
            return $this->containsSolvedVariable($node->operand, $variableSet);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsSolvedVariable($node->left, $variableSet)
                || $this->containsSolvedVariable($node->right, $variableSet);
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsSolvedVariable($argument, $variableSet)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{string, string} $constant
     * @return array{constant: array{string, string}, coefficients: array{}}|null
     */
    private function constantForm(array $constant): ?array
    {
        $reduced = $this->reduce($constant);

        return $reduced === null
            ? null
            : ['constant' => $reduced, 'coefficients' => []];
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $left
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $right
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function addForms(array $left, array $right, bool $subtract = false): ?array
    {
        $rightConstant = $subtract ? $this->negate($right['constant']) : $right['constant'];
        $constant = $this->add($left['constant'], $rightConstant);
        if ($constant === null) {
            return null;
        }
        $coefficients = $left['coefficients'];
        foreach ($right['coefficients'] as $name => $coefficient) {
            if ($subtract) {
                $coefficient = $this->negate($coefficient);
            }
            $sum = $this->add($coefficients[$name] ?? ['0', '1'], $coefficient);
            if ($sum === null) {
                return null;
            }
            if ($this->isZero($sum)) {
                unset($coefficients[$name]);
            } else {
                $coefficients[$name] = $sum;
            }
        }

        return ['constant' => $constant, 'coefficients' => $coefficients];
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $form
     * @param array{string, string} $factor
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function scaleForm(array $form, array $factor): ?array
    {
        $constant = $this->multiply($form['constant'], $factor);
        if ($constant === null) {
            return null;
        }
        $coefficients = [];
        foreach ($form['coefficients'] as $name => $coefficient) {
            $product = $this->multiply($coefficient, $factor);
            if ($product === null) {
                return null;
            }
            if (!$this->isZero($product)) {
                $coefficients[$name] = $product;
            }
        }

        return ['constant' => $constant, 'coefficients' => $coefficients];
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $left
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $right
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function multiplyForms(array $left, array $right): ?array
    {
        if ($left['coefficients'] !== [] && $right['coefficients'] !== []) {
            return null;
        }

        return $left['coefficients'] === []
            ? $this->scaleForm($right, $left['constant'])
            : $this->scaleForm($left, $right['constant']);
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $left
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $right
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function divideForms(array $left, array $right): ?array
    {
        if ($right['coefficients'] !== [] || $this->isZero($right['constant'])) {
            return null;
        }

        return $this->scaleForm($left, [$right['constant'][1], $right['constant'][0]]);
    }

    /**
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $base
     * @param array{constant: array{string, string}, coefficients: array<string, array{string, string}>} $exponent
     * @return array{constant: array{string, string}, coefficients: array<string, array{string, string}>}|null
     */
    private function powerForm(array $base, array $exponent): ?array
    {
        if ($exponent['coefficients'] !== []) {
            return null;
        }

        if ($base['coefficients'] === []) {
            $powerNumerator = \filter_var($exponent['constant'][0], FILTER_VALIDATE_INT);
            $powerDenominator = \filter_var($exponent['constant'][1], FILTER_VALIDATE_INT);
            if (
                !\is_int($powerNumerator)
                || !\is_int($powerDenominator)
                || $powerDenominator < 1
                || $powerNumerator < -self::MAX_CONSTANT_POWER
                || $powerNumerator > self::MAX_CONSTANT_POWER
                || $powerDenominator > self::MAX_CONSTANT_POWER
            ) {
                return null;
            }
            if ($powerDenominator !== 1) {
                $powered = \MathPHP\Ast\RationalLiteral::exactRationalPowerAsStrings(
                    $base['constant'],
                    $powerNumerator,
                    $powerDenominator,
                );

                return $powered === null ? null : $this->constantForm($powered);
            }
            if ($powerNumerator === 0) {
                return $this->constantForm(['1', '1']);
            }
            if ($powerNumerator < 0 && $this->isZero($base['constant'])) {
                return null;
            }

            $result = ['1', '1'];
            $factor = $base['constant'];
            $remainingPower = \abs($powerNumerator);
            while ($remainingPower > 0) {
                if ($remainingPower % 2 === 1) {
                    $product = $this->multiply($result, $factor);
                    if ($product === null) {
                        return null;
                    }
                    $result = $product;
                }
                $remainingPower = \intdiv($remainingPower, 2);
                if ($remainingPower > 0) {
                    $square = $this->multiply($factor, $factor);
                    if ($square === null) {
                        return null;
                    }
                    $factor = $square;
                }
            }
            if ($powerNumerator < 0) {
                $result = [$result[1], $result[0]];
            }

            return $this->constantForm($result);
        }
        if ($this->isZero($exponent['constant'])) {
            return $this->constantForm(['1', '1']);
        }

        if ($exponent['constant'] !== ['1', '1']) {
            return null;
        }

        return $base;
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function add(array $left, array $right): ?array
    {
        $numerator = $this->integerArithmetic->addIntegerValues(
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->integerArithmetic->multiplyIntegerValues($right[0], $left[1]),
        );
        $denominator = $this->integerArithmetic->multiplyIntegerValues($left[1], $right[1]);

        return $this->reduce([$numerator, $denominator]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function subtract(array $left, array $right): ?array
    {
        return $this->add($left, $this->negate($right));
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function multiply(array $left, array $right): ?array
    {
        return $this->reduce([
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[0]),
            $this->integerArithmetic->multiplyIntegerValues($left[1], $right[1]),
        ]);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     *  @return array{string, string}|null
     */
    private function divide(array $left, array $right): ?array
    {
        if ($this->isZero($right)) {
            return null;
        }

        return $this->reduce([
            $this->integerArithmetic->multiplyIntegerValues($left[0], $right[1]),
            $this->integerArithmetic->multiplyIntegerValues($left[1], $right[0]),
        ]);
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}
     */
    private function negate(array $fraction): array
    {
        return [$this->integerArithmetic->negateIntegerValue($fraction[0]), $fraction[1]];
    }

    /** @param array{string, string} $fraction
     *  @return array{string, string}|null
     */
    private function reduce(array $fraction): ?array
    {
        if ($fraction[1] === '0') {
            return null;
        }
        if ($fraction[1][0] === '-') {
            $fraction[0] = $this->integerArithmetic->negateIntegerValue($fraction[0]);
            $fraction[1] = \substr($fraction[1], 1);
        }
        if ($fraction[0] === '0') {
            return ['0', '1'];
        }
        $gcd = $this->integerArithmetic->greatestCommonDivisorValues($fraction[0], $fraction[1]);
        $numerator = $this->integerArithmetic->divideIntegerValueExactly($fraction[0], $gcd);
        $denominator = $this->integerArithmetic->divideIntegerValueExactly($fraction[1], $gcd);
        if ($numerator === null || $denominator === null) {
            return null;
        }
        if (
            \strlen(\ltrim($numerator, '-')) > self::MAX_INTEGER_DIGITS
            || \strlen($denominator) > self::MAX_INTEGER_DIGITS
        ) {
            return null;
        }

        return [$numerator, $denominator];
    }

    /** @param array{string, string} $fraction */
    private function isZero(array $fraction): bool
    {
        return $fraction[0] === '0';
    }
}
