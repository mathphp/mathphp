<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\Node;
use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\ParseException;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Source\SourceSpan;

final class ComplexPolynomialEquationSolver
{
    /**
     * @param array<string, int|float> $parameters
     */
    public function solve(
        string $equation,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexPolynomialSolution {
        if (\strlen($equation) > $evaluationOptions->limits->maxExpressionLength) {
            $position = $evaluationOptions->limits->maxExpressionLength;
            throw new LexicalException(
                'Equation exceeds the configured byte limit',
                'limit.expression_length',
                new SourceSpan($position, $position + 1),
            );
        }
        if (
            \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) !== 1
            || \in_array($variable, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
        ) {
            throw new \InvalidArgumentException(
                'The solved variable must be a non-reserved ASCII identifier.',
            );
        }
        if (\array_key_exists($variable, $parameters)) {
            throw new \InvalidArgumentException(
                'The solved variable cannot also be supplied as a parameter.',
            );
        }
        new Environment($parameters, $evaluationOptions->functions);

        if (\substr_count($equation, '=') > 1) {
            return $this->solveChainedPolynomialEquation(
                $equation,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        [$leftText, $rightText, $rightOffset] = $this->splitEquation($equation);
        $left = $this->parseSide($leftText, 0, $evaluationOptions);
        $right = $this->parseSide($rightText, $rightOffset, $evaluationOptions);
        $absoluteValueLevelSolutions = $this->solveAbsoluteValueLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($absoluteValueLevelSolutions !== null) {
            return $absoluteValueLevelSolutions;
        }
        $matchingAbsoluteValueSolutions = $this->solveMatchingAbsoluteValueEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingAbsoluteValueSolutions !== null) {
            return $matchingAbsoluteValueSolutions;
        }
        $matchingExponentialSolutions = $this->solveMatchingExponentialEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingExponentialSolutions !== null) {
            return $matchingExponentialSolutions;
        }
        $matchingPeriodicSolutions = $this->solveMatchingPeriodicEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingPeriodicSolutions !== null) {
            return $matchingPeriodicSolutions;
        }
        $transcendentalLevelSolutions = $this->solveTranscendentalLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($transcendentalLevelSolutions !== null) {
            return $transcendentalLevelSolutions;
        }
        $regularizedGammaSolutions = $this->solveIntegerShapeRegularizedGammaEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($regularizedGammaSolutions !== null) {
            return $regularizedGammaSolutions;
        }
        $regularizedBetaSolutions = $this->solveIntegerShapeRegularizedBetaEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($regularizedBetaSolutions !== null) {
            return $regularizedBetaSolutions;
        }
        $besselLevelSolutions = $this->solveBesselLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($besselLevelSolutions !== null) {
            return $besselLevelSolutions;
        }
        $logarithmLevelSolutions = $this->solveLogarithmLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($logarithmLevelSolutions !== null) {
            return $logarithmLevelSolutions;
        }
        $inverseLevelSolutions = $this->solveInverseFunctionLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($inverseLevelSolutions !== null) {
            return $inverseLevelSolutions;
        }
        $hypotenuseLevelSolutions = $this->solveHypotenuseLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($hypotenuseLevelSolutions !== null) {
            return $hypotenuseLevelSolutions;
        }
        $squareRootLevelSolutions = $this->solveSquareRootLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($squareRootLevelSolutions !== null) {
            return $squareRootLevelSolutions;
        }
        $matchingSquareRootSolutions = $this->solveMatchingSquareRootEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingSquareRootSolutions !== null) {
            return $matchingSquareRootSolutions;
        }
        $integerRootLevelSolutions = $this->solveIntegerRootLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($integerRootLevelSolutions !== null) {
            return $integerRootLevelSolutions;
        }
        $wrappedZeroSolutions = $this->solveZeroWrapperEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($wrappedZeroSolutions !== null) {
            return $wrappedZeroSolutions;
        }
        $factorized = $this->solveProductEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $exactCoefficients = null;
        $coefficients = null;
        if ($factorized !== null) {
            $coefficients = $this->polynomialDifference(
                $left,
                $right,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                $exactCoefficients,
            );
            $exactUnderflow = false;
            if ($coefficients !== null && $exactCoefficients !== null) {
                foreach ($coefficients as $power => $coefficient) {
                    if ($coefficient === 0.0 && $exactCoefficients[$power] !== '0') {
                        $exactUnderflow = true;
                        break;
                    }
                }
            }
            if ($coefficients !== null && !$exactUnderflow) {
                return (new ComplexPolynomialSolver())->recalculateResiduals(
                    $coefficients,
                    $factorized,
                    $solverOptions,
                );
            }
        }
        $coefficients ??= $this->polynomialDifference(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
            $exactCoefficients,
        );
        if ($coefficients === null) {
            throw new \InvalidArgumentException(
                'Complex polynomial solving requires both equation sides to be polynomials in the solved variable.',
            );
        }

        return (new ComplexPolynomialSolver())->solve(
            $coefficients,
            $solverOptions,
            $exactCoefficients,
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveBesselLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && \in_array($candidate->name, ['besselj', 'besseli', 'bessely'], true)
                && \count($candidate->arguments) === 2
            ) {
                $wrapper = $candidate;
                $levelNode = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $order = $extractor->extract($wrapper->arguments[0]);
        $level = $extractor->extract($levelNode);
        $argument = $extractor->extract($wrapper->arguments[1]);
        if (
            $order === null
            || \count($order) !== 1
            || !\is_finite($order[0])
            || $order[0] !== \floor($order[0])
            || \abs($order[0]) > SpecialFunctions::MAX_BESSEL_ORDER
            || $level === null
            || \count($level) !== 1
            || !\is_finite($level[0])
            || $argument === null
        ) {
            return null;
        }

        $integerOrder = (int) $order[0];
        if (\count($argument) === 1) {
            try {
                $value = (new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                ))->evaluate($wrapper);
            } catch (EvaluationException) {
                $knownPole = $wrapper->name === 'bessely' && $argument[0] === 0.0;

                return new ComplexPolynomialSolution([], 0, $knownPole, 0);
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex Bessel equation holds for every value of the solved variable.',
                );
            }

            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        throw new InfiniteSolutionSetException(
            $level[0] === 0.0
                ? \sprintf(
                    'The complex equation %s(%d, P(x)) = 0 has infinitely many roots.',
                    $wrapper->name,
                    $integerOrder,
                )
                : \sprintf(
                    'The complex equation %s(%d, P(x)) = c has infinitely many roots.',
                    $wrapper->name,
                    $integerOrder,
                ),
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveAbsoluteValueLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $levelSide = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && $candidate->name === 'abs'
                && \count($candidate->arguments) === 1
            ) {
                $wrapper = $candidate;
                $levelSide = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $levelCoefficients = $extractor->extract($levelSide);
        if (
            $levelCoefficients === null
            || \count($levelCoefficients) !== 1
            || !\is_finite($levelCoefficients[0])
        ) {
            return null;
        }
        $level = $levelCoefficients[0];
        if ($level < 0.0) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if ($level === 0.0) {
            $exactCoefficients = null;
            $coefficients = $this->polynomialDifference(
                $wrapper->arguments[0],
                $levelSide,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
                $exactCoefficients,
            );
            if ($coefficients === null) {
                return null;
            }

            return (new ComplexPolynomialSolver())->solve(
                $coefficients,
                $solverOptions,
                $exactCoefficients,
            );
        }
        $argumentCoefficients = $extractor->extract($wrapper->arguments[0]);
        if ($argumentCoefficients === null) {
            return null;
        }
        if (\count($argumentCoefficients) > 1) {
            throw new InfiniteSolutionSetException(
                'The complex equation abs(P(x)) = c has infinitely many roots for nonconstant P and c > 0.',
            );
        }
        if (\abs($argumentCoefficients[0]) === $level) {
            throw new InfiniteSolutionSetException(
                'The constant equation abs(P) = c holds for every complex value.',
            );
        }

        return new ComplexPolynomialSolution([], 0, true, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingAbsoluteValueEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (
            !$left instanceof FunctionCallNode || $left->name !== 'abs' || \count($left->arguments) !== 1
            || !$right instanceof FunctionCallNode || $right->name !== 'abs' || \count($right->arguments) !== 1
        ) {
            return null;
        }

        $leftArgument = $left->arguments[0];
        $rightArgument = $right->arguments[0];
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftPolynomial = $extractor->polynomial($leftArgument);
        $rightPolynomial = $extractor->polynomial($rightArgument);
        $leftCoefficients = $extractor->integerCoefficients($leftPolynomial);
        $rightCoefficients = $extractor->integerCoefficients($rightPolynomial);
        if ($leftCoefficients === null || $rightCoefficients === null) {
            return null;
        }
        $leftIsZero = $this->isZeroIntegerPolynomial($leftCoefficients);
        $rightIsZero = $this->isZeroIntegerPolynomial($rightCoefficients);
        if ($leftIsZero && $rightIsZero) {
            throw new InfiniteSolutionSetException(
                'Both modulus arguments vanish identically, so every complex value is a solution.',
            );
        }

        $ratio = $extractor->rationalFunctionRatio($leftArgument, $rightArgument);
        if ($ratio === false) {
            if (!$leftIsZero && !$rightIsZero) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial ratio has infinitely many preimages of the unit circle.',
                );
            }
        } elseif ($ratio !== null) {
            $ratioSquare = $extractor->powerExactFraction($ratio, 2);
            if ($ratioSquare === null) {
                return null;
            }
            if ($this->exactFractionsAreEqual($ratioSquare, ['1', '1'])) {
                throw new InfiniteSolutionSetException(
                    'A constant unit-modulus ratio makes the polynomial moduli equal everywhere.',
                );
            }
        } else {
            return null;
        }

        $commonZeros = (new ExactPolynomialRootCounter())->greatestCommonDivisor(
            $leftCoefficients,
            $rightCoefficients,
        );
        if ($commonZeros === null) {
            return null;
        }
        if (\count($commonZeros) === 1) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        $coefficients = (new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree))
            ->normalizedCoefficients($commonZeros, true);
        if ($coefficients === null) {
            return null;
        }

        return (new ComplexPolynomialSolver())->solve($coefficients, $solverOptions, $commonZeros);
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     */
    private function exactFractionsAreEqual(array $left, array $right): bool
    {
        $integerArithmetic = new IntegerPolynomialExtractor('', [], 1);

        return $integerArithmetic->multiplyIntegerValues($left[0], $right[1])
            === $integerArithmetic->multiplyIntegerValues($right[0], $left[1]);
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingExponentialEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (
            !$left instanceof FunctionCallNode || !$right instanceof FunctionCallNode
            || !\in_array($left->name, ['exp', 'expm1'], true)
            || !\in_array($right->name, ['exp', 'expm1'], true)
            || \count($left->arguments) !== 1 || \count($right->arguments) !== 1
            || !$evaluationOptions->functions->isBuiltIn($left->name)
            || !$evaluationOptions->functions->isBuiltIn($right->name)
        ) {
            return null;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $phaseDifference = $extractor->difference($left->arguments[0], $right->arguments[0]);
        if ($phaseDifference === null) {
            return null;
        }
        if ($left->name !== $right->name) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            if (\count($phaseDifference) !== 1) {
                $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
                if ($phaseSum === null || \count($phaseSum) !== 1) {
                    return null;
                }
                throw new InfiniteSolutionSetException(
                    'A constant phase sum reduces mixed exp/expm1 calls to a quadratic with nonzero exponential '
                        . 'levels and infinitely many complex preimages.',
                );
            }
            if (\count($leftPhase) === 1 && \count($rightPhase) === 1) {
                $evaluator = new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                );
                try {
                    $leftValue = $evaluator->evaluate($left);
                    $rightValue = $evaluator->evaluate($right);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, false, 0);
                }
                if ($leftValue === $rightValue) {
                    throw new InfiniteSolutionSetException(
                        'The constant mixed exponential equation holds for every value of the solved variable.',
                    );
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if ($this->isZeroIntegerPolynomial($phaseDifference)) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            throw new InfiniteSolutionSetException(
                'A nonzero constant phase difference reduces mixed exp/expm1 calls to a nonzero exponential '
                    . 'level with infinitely many complex preimages.',
            );
        }
        if ($this->isZeroIntegerPolynomial($phaseDifference)) {
            throw new InfiniteSolutionSetException(
                'Matching exponential calls with identical phases are equal for every complex value.',
            );
        }
        if (\count($phaseDifference) > 1) {
            throw new InfiniteSolutionSetException(
                'A nonconstant polynomial phase difference meets the exponential period lattice infinitely often.',
            );
        }

        return new ComplexPolynomialSolution([], 0, true, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingPeriodicEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (
            !$left instanceof FunctionCallNode || !$right instanceof FunctionCallNode
            || \count($left->arguments) !== 1 || \count($right->arguments) !== 1
            || !$evaluationOptions->functions->isBuiltIn($left->name)
            || !$evaluationOptions->functions->isBuiltIn($right->name)
        ) {
            return null;
        }
        $isMatchingFunction = $left->name === $right->name
            && \in_array(
                $left->name,
                ['sin', 'cos', 'sec', 'csc', 'sinh', 'cosh', 'sech', 'tan', 'cot', 'tanh', 'coth', 'csch'],
                true,
            );
        $isMixedSineCosine = \in_array([$left->name, $right->name], [['sin', 'cos'], ['cos', 'sin']], true);
        $isMixedTrigReciprocal = (
            \in_array($left->name, ['sin', 'cos'], true)
            && \in_array($right->name, ['sec', 'csc'], true)
        ) || (
            \in_array($left->name, ['sec', 'csc'], true)
            && \in_array($right->name, ['sin', 'cos'], true)
        );
        $isMixedEntireTrigHyperbolic = (
            \in_array($left->name, ['sin', 'cos'], true)
            && \in_array($right->name, ['sinh', 'cosh'], true)
        ) || (
            \in_array($left->name, ['sinh', 'cosh'], true)
            && \in_array($right->name, ['sin', 'cos'], true)
        );
        $isMixedSecantCosecant = \in_array(
            [$left->name, $right->name],
            [['sec', 'csc'], ['csc', 'sec']],
            true,
        );
        $isMixedTanhCoth = \in_array(
            [$left->name, $right->name],
            [['tanh', 'coth'], ['coth', 'tanh']],
            true,
        );
        $isMixedTanCot = \in_array(
            [$left->name, $right->name],
            [['tan', 'cot'], ['cot', 'tan']],
            true,
        );
        $isMixedHyperbolicTangentReciprocal = (
            \in_array($left->name, ['tanh', 'coth'], true)
            && \in_array($right->name, ['sech', 'csch'], true)
        ) || (
            \in_array($left->name, ['sech', 'csch'], true)
            && \in_array($right->name, ['tanh', 'coth'], true)
        );
        $isMixedCoshSech = \in_array(
            [$left->name, $right->name],
            [['cosh', 'sech'], ['sech', 'cosh']],
            true,
        );
        $isMixedSechCsch = \in_array(
            [$left->name, $right->name],
            [['sech', 'csch'], ['csch', 'sech']],
            true,
        );
        $isMixedSinhCsch = \in_array(
            [$left->name, $right->name],
            [['sinh', 'csch'], ['csch', 'sinh']],
            true,
        );
        $isMixedCoshCsch = \in_array(
            [$left->name, $right->name],
            [['cosh', 'csch'], ['csch', 'cosh']],
            true,
        );
        $isMixedSinhCosh = \in_array(
            [$left->name, $right->name],
            [['sinh', 'cosh'], ['cosh', 'sinh']],
            true,
        );
        $isMixedSinhSech = \in_array(
            [$left->name, $right->name],
            [['sinh', 'sech'], ['sech', 'sinh']],
            true,
        );
        if (
            !$isMatchingFunction && !$isMixedSineCosine && !$isMixedTrigReciprocal
            && !$isMixedEntireTrigHyperbolic
            && !$isMixedSecantCosecant && !$isMixedTanhCoth
            && !$isMixedTanCot && !$isMixedHyperbolicTangentReciprocal
            && !$isMixedCoshSech && !$isMixedSechCsch && !$isMixedSinhCsch
            && !$isMixedCoshCsch && !$isMixedSinhCosh && !$isMixedSinhSech
        ) {
            return null;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $phaseDifference = $extractor->difference($left->arguments[0], $right->arguments[0]);
        if ($phaseDifference === null) {
            return null;
        }

        if ($isMixedTanhCoth) {
            if (\count($phaseDifference) === 1) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            if (\count($leftPhase) === 1 || \count($rightPhase) === 1) {
                $constantPhase = \count($leftPhase) === 1 ? $leftPhase[0] : $rightPhase[0];
                $constantFunction = \count($leftPhase) === 1 ? $left->name : $right->name;
                if ($constantFunction === 'coth' && $constantPhase[0] === '0') {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial phase equals a constant value of the opposite hyperbolic '
                        . 'tangent function infinitely often.',
                );
            }

            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum reduces mixed tanh/coth calls to a nonconstant cosh zero set with '
                        . 'infinitely many defined complex solutions.',
                );
            }

            return null;
        }

        if ($isMixedTanCot) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if (!$leftIsConstant && !$rightIsConstant) {
                $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
                if ($phaseSum === null) {
                    return null;
                }

                return \count($phaseSum) === 1
                    ? new ComplexPolynomialSolution([], 0, true, 0)
                    : null;
            }
            $constantPhase = $leftIsConstant ? $leftPhase[0] : $rightPhase[0];
            $constantFunction = $leftIsConstant ? $left->name : $right->name;
            if ($constantFunction === 'cot' && $constantPhase[0] === '0') {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            throw new InfiniteSolutionSetException(
                'A nonconstant polynomial phase equals a constant valid tan/cot level infinitely often.',
            );
        }

        if ($isMixedCoshSech) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial cosh/sech phase has infinitely many complex preimages of the '
                        . 'nonzero level set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (
                \count($phaseDifference) > 1
                && \count($phaseSum) > 1
            ) {
                throw new InfiniteSolutionSetException(
                    'Mixed cosh/sech calls with nonconstant exact rational-polynomial phase sum and difference have '
                        . 'infinitely many complex solutions.',
                );
            }
            throw new InfiniteSolutionSetException(
                'A constant phase sum or difference reduces mixed cosh/sech calls to a nonconstant cosh level '
                    . 'with infinitely many complex preimages.',
            );
        }

        if ($isMixedSechCsch) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                $constantPhase = $leftIsConstant ? $leftPhase[0] : $rightPhase[0];
                $constantFunction = $leftIsConstant ? $left->name : $right->name;
                if ($constantFunction === 'csch' && $constantPhase[0] === '0') {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial sech/csch phase has infinitely many preimages of the finite '
                        . 'nonzero level set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseDifference) === 1 || \count($phaseSum) === 1) {
                $constantRelation = \count($phaseDifference) === 1 ? $phaseDifference : $phaseSum;
                if ($this->isZeroIntegerPolynomial($constantRelation)) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonzero constant phase sum or difference gives infinitely many mixed sech/csch roots.',
                );
            }

            return null;
        }

        if ($isMixedSinhCsch) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                $constantPhase = $leftIsConstant ? $leftPhase[0] : $rightPhase[0];
                if ($constantPhase[0] === '0') {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial sinh/csch phase has infinitely many preimages of the nonzero '
                        . 'level set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseDifference) === 1 || \count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum or difference reduces mixed sinh/csch calls to a nonconstant cosh level '
                        . 'with infinitely many complex preimages.',
                );
            }

            throw new InfiniteSolutionSetException(
                'Mixed sinh/csch calls with nonconstant exact rational-polynomial phase sum and difference have '
                    . 'infinitely many complex solutions.',
            );
        }

        if ($isMixedCoshCsch) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                $constantPhase = $leftIsConstant ? $leftPhase[0] : $rightPhase[0];
                $constantFunction = $leftIsConstant ? $left->name : $right->name;
                if ($constantFunction === 'csch' && $constantPhase[0] === '0') {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial cosh/csch phase has infinitely many preimages of the nonzero '
                        . 'level set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseDifference) === 1 || \count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum or difference reduces mixed cosh/csch calls to a nonconstant sinh '
                        . 'level with infinitely many complex preimages.',
                );
            }

            throw new InfiniteSolutionSetException(
                'Mixed cosh/csch calls with nonconstant exact rational-polynomial phase sum and difference have '
                    . 'infinitely many complex solutions.',
            );
        }

        if ($isMixedSinhCosh) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial sinh/cosh phase has infinitely many preimages of the finite level '
                        . 'set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (
                \count($phaseDifference) === 1
                || \count($phaseSum) === 1
            ) {
                $constantRelation = \count($phaseDifference) === 1 ? $phaseDifference : $phaseSum;
                if ($this->isZeroIntegerPolynomial($constantRelation)) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonzero constant phase sum or difference gives infinitely many mixed sinh/cosh solutions.',
                );
            }

            throw new InfiniteSolutionSetException(
                'Mixed sinh/cosh calls with nonconstant exact rational-polynomial phase sum and difference have '
                    . 'infinitely many complex solutions.',
            );
        }

        if ($isMixedSinhSech) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                return null;
            }
            if ($leftIsConstant || $rightIsConstant) {
                $constantPhase = $leftIsConstant ? $leftPhase[0] : $rightPhase[0];
                $constantFunction = $leftIsConstant ? $left->name : $right->name;
                if ($constantFunction === 'sinh' && $constantPhase[0] === '0') {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial sinh/sech phase has infinitely many preimages of the nonzero '
                        . 'level set by the constant opposite phase.',
                );
            }
            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseDifference) === 1 || \count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum or difference reduces mixed sinh/sech calls to a nonconstant sinh level '
                        . 'with infinitely many complex preimages.',
                );
            }

            throw new InfiniteSolutionSetException(
                'Mixed sinh/sech calls with nonconstant exact rational-polynomial phase sum and difference have '
                    . 'infinitely many complex solutions.',
            );
        }

        if ($isMixedHyperbolicTangentReciprocal) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if (!$leftIsConstant && !$rightIsConstant) {
                return null;
            }

            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            if ($leftIsConstant && $rightIsConstant) {
                try {
                    $leftValue = (float) $evaluator->evaluate($left);
                    $rightValue = (float) $evaluator->evaluate($right);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if ($leftValue === $rightValue) {
                    throw new InfiniteSolutionSetException(
                        'The constant hyperbolic-tangent/reciprocal equation holds for every value of the solved '
                            . 'variable.',
                    );
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            $constantCall = $leftIsConstant ? $left : $right;
            try {
                $constantValue = (float) $evaluator->evaluate($constantCall);
            } catch (EvaluationException) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            $constantFunction = $constantCall->name;
            if (
                \in_array($constantFunction, ['tanh', 'coth'], true)
                && $constantValue === 0.0
            ) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if (
                \in_array($constantFunction, ['sech', 'csch'], true)
                && \abs($constantValue) === 1.0
            ) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            throw new InfiniteSolutionSetException(
                'A nonconstant polynomial phase attains the finite level of the opposite hyperbolic function '
                    . 'infinitely often.',
            );
        }

        if (\in_array($left->name, ['sec', 'csc', 'sech'], true)) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            if (\count($leftPhase) === 1 && \count($rightPhase) === 1) {
                return null;
            }
            if (\in_array($left->name, ['sec', 'csc'], true)) {
                $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
                if ($phaseSum === null) {
                    return null;
                }
                $differenceIsConstant = \count($phaseDifference) === 1;
                $sumIsConstant = \count($phaseSum) === 1;
                if ($differenceIsConstant || $sumIsConstant) {
                    $evaluator = new Evaluator(
                        new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    );
                    try {
                        $leftValue = (float) $evaluator->evaluate($left->arguments[0]);
                        $rightValue = (float) $evaluator->evaluate($right->arguments[0]);
                    } catch (EvaluationException) {
                        return null;
                    }
                    $constantDifference = $leftValue - $rightValue;
                    $constantSum = $leftValue + $rightValue;
                    if (!\is_finite($constantDifference) || !\is_finite($constantSum)) {
                        return null;
                    }
                    $differenceZero = $differenceIsConstant
                        && $this->isEquivalentModuloTwoPi($constantDifference, 0.0);
                    $differencePi = $differenceIsConstant
                        && $this->isEquivalentModuloTwoPi($constantDifference, \M_PI);
                    $sumIdentityTarget = $left->name === 'sec' ? 0.0 : \M_PI;
                    $sumPoleTarget = $left->name === 'sec' ? \M_PI : 0.0;
                    $sumIdentity = $sumIsConstant
                        && $this->isEquivalentModuloTwoPi($constantSum, $sumIdentityTarget);
                    $sumPoleOnly = $sumIsConstant
                        && $this->isEquivalentModuloTwoPi($constantSum, $sumPoleTarget);
                    if ($differenceZero || $sumIdentity) {
                        throw new InfiniteSolutionSetException(
                            'Equal secant/cosecant calls with an identity phase relation agree on their common '
                                . 'domain.',
                        );
                    }
                    if ($differencePi || $sumPoleOnly) {
                        return new ComplexPolynomialSolution([], 0, true, 0);
                    }
                }
            }

            throw new InfiniteSolutionSetException(
                \sprintf(
                    'Equal %s values of nonconstant polynomial phases have infinitely many complex solutions.',
                    match ($left->name) {
                        'sec' => 'secant',
                        'csc' => 'cosecant',
                        default => 'hyperbolic-secant',
                    },
                ),
            );
        }

        if (\in_array($left->name, ['tan', 'tanh'], true)) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant || $rightIsConstant) {
                $evaluator = new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                );
                if ($leftIsConstant && $rightIsConstant) {
                    try {
                        $leftValue = (float) $evaluator->evaluate($left);
                        $rightValue = (float) $evaluator->evaluate($right);
                    } catch (EvaluationException) {
                        return new ComplexPolynomialSolution([], 0, true, 0);
                    }
                    if ($leftValue === $rightValue) {
                        throw new InfiniteSolutionSetException(
                            'The constant matching tangent-family equation holds for every value of the solved '
                                . 'variable.',
                        );
                    }

                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                $constantCall = $leftIsConstant ? $left : $right;
                try {
                    $constantValue = (float) $evaluator->evaluate($constantCall);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if ($left->name === 'tanh' && \abs($constantValue) >= 1.0) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial tangent-family phase has infinitely many preimages of the finite '
                        . 'constant level.',
                );
            }
            if ($this->isZeroIntegerPolynomial($phaseDifference)) {
                throw new InfiniteSolutionSetException(
                    'Matching tangent or hyperbolic-tangent calls with identical phases agree on their domain.',
                );
            }
            if (\count($phaseDifference) === 1) {
                if ($left->name === 'tan') {
                    $evaluator = new Evaluator(
                        new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    );
                    try {
                        $leftValue = (float) $evaluator->evaluate($left->arguments[0]);
                        $rightValue = (float) $evaluator->evaluate($right->arguments[0]);
                    } catch (EvaluationException) {
                        return null;
                    }
                    $constantDifference = $leftValue - $rightValue;
                    if (!\is_finite($constantDifference)) {
                        return null;
                    }
                    if (
                        $this->isEquivalentModuloTwoPi($constantDifference, 0.0)
                        || $this->isEquivalentModuloTwoPi($constantDifference, \M_PI)
                    ) {
                        throw new InfiniteSolutionSetException(
                            'Matching tangent phases differing by an integer multiple of pi agree on their common '
                                . 'domain.',
                        );
                    }
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if (
                \count($leftPhase) === 1
                || \count($rightPhase) === 1
                || (\count($leftPhase) <= 2 && \count($rightPhase) <= 2)
            ) {
                throw new InfiniteSolutionSetException(
                    'These polynomial phase shapes give infinitely many tangent equalities away from poles.',
                );
            }

            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum gives infinitely many valid matching tangent-family roots.',
                );
            }

            return null;
        }
        if (\in_array($left->name, ['cot', 'coth'], true)) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant || $rightIsConstant) {
                $evaluator = new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                );
                if ($leftIsConstant && $rightIsConstant) {
                    try {
                        $leftValue = (float) $evaluator->evaluate($left);
                        $rightValue = (float) $evaluator->evaluate($right);
                    } catch (EvaluationException) {
                        return new ComplexPolynomialSolution([], 0, true, 0);
                    }
                    if ($leftValue === $rightValue) {
                        throw new InfiniteSolutionSetException(
                            'The constant matching cotangent-family equation holds for every value of the solved '
                                . 'variable.',
                        );
                    }

                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                $constantCall = $leftIsConstant ? $left : $right;
                try {
                    $constantValue = (float) $evaluator->evaluate($constantCall);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if ($left->name === 'coth' && \abs($constantValue) <= 1.0) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                throw new InfiniteSolutionSetException(
                    'A nonconstant polynomial cotangent-family phase has infinitely many preimages of the finite '
                        . 'constant level.',
                );
            }
            if ($this->isZeroIntegerPolynomial($phaseDifference)) {
                throw new InfiniteSolutionSetException(
                    'Matching cotangent or hyperbolic-cotangent calls with identical phases are equal on their '
                        . 'common domain.',
                );
            }
            if (\count($phaseDifference) === 1) {
                if ($left->name === 'cot') {
                    $evaluator = new Evaluator(
                        new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    );
                    try {
                        $leftValue = (float) $evaluator->evaluate($left->arguments[0]);
                        $rightValue = (float) $evaluator->evaluate($right->arguments[0]);
                    } catch (EvaluationException) {
                        return null;
                    }
                    $constantDifference = $leftValue - $rightValue;
                    if (!\is_finite($constantDifference)) {
                        return null;
                    }
                    if (
                        $this->isEquivalentModuloTwoPi($constantDifference, 0.0)
                        || $this->isEquivalentModuloTwoPi($constantDifference, \M_PI)
                    ) {
                        throw new InfiniteSolutionSetException(
                            'Matching cotangent phases differing by an integer multiple of pi agree on their common '
                                . 'domain.',
                        );
                    }
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            if (\count($phaseSum) === 1) {
                throw new InfiniteSolutionSetException(
                    \sprintf(
                        'A constant phase sum gives infinitely many valid matching %s roots.',
                        $left->name === 'cot' ? 'cotangent' : 'hyperbolic-cotangent',
                    ),
                );
            }

            return null;
        }

        $phaseSum = $extractor->sum($left->arguments[0], $right->arguments[0]);
        if ($phaseSum === null) {
            return null;
        }

        if ($left->name === 'csch') {
            if ($this->isZeroIntegerPolynomial($phaseSum)) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if (\count($phaseSum) > 1 || \count($phaseDifference) > 1) {
                throw new InfiniteSolutionSetException(
                    'Matching hyperbolic-cosecant calls have infinitely many complex solutions for these '
                        . 'nonconstant polynomial phase combinations.',
                );
            }

            return null;
        }

        if ($isMixedSineCosine) {
            if (\count($phaseDifference) > 1 || \count($phaseSum) > 1) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant sine/cosine phase sum or difference gives infinitely many complex solutions.',
                );
            }

            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        if ($isMixedTrigReciprocal) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            $leftIsConstant = \count($leftPhase) === 1;
            $rightIsConstant = \count($rightPhase) === 1;
            if ($leftIsConstant && $rightIsConstant) {
                $evaluator = new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                );
                try {
                    $leftValue = (float) $evaluator->evaluate($left);
                    $rightValue = (float) $evaluator->evaluate($right);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if ($leftValue === $rightValue) {
                    throw new InfiniteSolutionSetException(
                        'The constant trigonometric/reciprocal-trigonometric equation holds for every value of '
                            . 'the solved variable.',
                    );
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if ($leftIsConstant || $rightIsConstant) {
                $constantCall = $leftIsConstant ? $left : $right;
                try {
                    $constantValue = (float) (new Evaluator(
                        new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    ))->evaluate($constantCall);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if (\in_array($constantCall->name, ['sin', 'cos'], true) && $constantValue === 0.0) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
            }

            throw new InfiniteSolutionSetException(
                'A mixed trigonometric/reciprocal-trigonometric equation with a nonconstant exact polynomial phase '
                    . 'has infinitely many complex solutions.',
            );
        }

        if ($isMixedEntireTrigHyperbolic) {
            $leftPhase = $extractor->polynomial($left->arguments[0]);
            $rightPhase = $extractor->polynomial($right->arguments[0]);
            if ($leftPhase === null || $rightPhase === null) {
                return null;
            }
            if (\count($leftPhase) === 1 && \count($rightPhase) === 1) {
                return null;
            }

            throw new InfiniteSolutionSetException(
                'A mixed entire trigonometric/hyperbolic equation with a nonconstant exact polynomial phase has '
                    . 'infinitely many complex solutions.',
            );
        }

        if ($isMixedSecantCosecant) {
            $secantCall = $left->name === 'sec' ? $left : $right;
            $cosecantCall = $left->name === 'csc' ? $left : $right;
            $secantPhase = $secantCall->arguments[0];
            $cosecantPhase = $cosecantCall->arguments[0];
            $secantCoefficients = $extractor->polynomial($secantPhase);
            $cosecantCoefficients = $extractor->polynomial($cosecantPhase);
            if ($secantCoefficients === null || $cosecantCoefficients === null) {
                return null;
            }
            $secantIsConstant = \count($secantCoefficients) === 1;
            $cosecantIsConstant = \count($cosecantCoefficients) === 1;
            if ($secantIsConstant && $cosecantIsConstant) {
                return null;
            }
            if ($secantIsConstant || $cosecantIsConstant) {
                $constantCall = $secantIsConstant ? $secantCall : $cosecantCall;
                try {
                    $constantValue = (float) (new Evaluator(
                        new Environment($parameters, $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    ))->evaluate($constantCall);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if (!\is_finite($constantValue)) {
                    return null;
                }

                throw new InfiniteSolutionSetException(
                    'A defined constant secant/cosecant value equals a nonconstant periodic phase infinitely often.',
                );
            }

            $phaseDifference = $extractor->difference($secantPhase, $cosecantPhase);
            $phaseSum = $extractor->sum($secantPhase, $cosecantPhase);
            if ($phaseSum === null) {
                return null;
            }
            if ($phaseDifference === null) {
                return null;
            }
            if (\count($phaseDifference) === 1 || \count($phaseSum) === 1) {
                $isConstantDifference = \count($phaseDifference) === 1;
                $evaluator = new Evaluator(
                    new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                    $evaluationOptions->limits,
                );
                try {
                    $secantValue = (float) $evaluator->evaluate($secantPhase);
                    $cosecantValue = (float) $evaluator->evaluate($cosecantPhase);
                } catch (EvaluationException) {
                    return null;
                }
                $constantRelation = $isConstantDifference
                    ? $secantValue - $cosecantValue
                    : $secantValue + $cosecantValue;
                if (!\is_finite($constantRelation)) {
                    return null;
                }
                $positiveHalfTurns = $this->isEquivalentModuloTwoPi($constantRelation, \M_PI_2);
                $negativeHalfTurns = $this->isEquivalentModuloTwoPi($constantRelation, -\M_PI_2);
                if (($isConstantDifference && $positiveHalfTurns) || (!$isConstantDifference && $negativeHalfTurns)) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }
                if (($isConstantDifference && $negativeHalfTurns) || (!$isConstantDifference && $positiveHalfTurns)) {
                    throw new InfiniteSolutionSetException(
                        'A constant half-period relation makes mixed secant/cosecant calls identical on their '
                            . 'common domain.',
                    );
                }
            }

            throw new InfiniteSolutionSetException(
                'Mixed secant/cosecant calls with nonconstant exact rational-polynomial phases have '
                    . 'infinitely many complex solutions.',
            );
        }

        if (
            $this->isZeroIntegerPolynomial($phaseDifference)
            || (\in_array($left->name, ['cos', 'sec', 'cosh'], true)
                && $this->isZeroIntegerPolynomial($phaseSum))
        ) {
            throw new InfiniteSolutionSetException(
                'The matching periodic calls are identical for every complex value.',
            );
        }
        if (\count($phaseDifference) > 1 || \count($phaseSum) > 1) {
            throw new InfiniteSolutionSetException(
                'A nonconstant polynomial phase sum or difference gives infinitely many complex solutions.',
            );
        }

        return new ComplexPolynomialSolution([], 0, true, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveTranscendentalLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $levelSide = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && (
                    (\count($candidate->arguments) === 1
                        && \in_array(
                            $candidate->name,
                            [
                                'exp', 'expm1', 'gamma', 'zeta', 'digamma', 'trigamma', 'tetragamma',
                                'sin', 'cos', 'tan', 'cot',
                                'sinh', 'cosh', 'tanh', 'coth',
                                'sec', 'csc', 'sech', 'csch',
                                'erf', 'erfi', 'erfc', 'erfcx', 'dawson',
                                'sinc', 'sincpi',
                            ],
                            true,
                        ))
                    || ($candidate->name === 'polygamma' && \count($candidate->arguments) === 2)
                )
                && $evaluationOptions->functions->isBuiltIn($candidate->name)
            ) {
                $wrapper = $candidate;
                $levelSide = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $levelCoefficients = $extractor->extract($levelSide);
        $argumentIndex = 0;
        if ($wrapper->name === 'polygamma') {
            $orderCoefficients = $extractor->extract($wrapper->arguments[0]);
            if (
                $orderCoefficients === null
                || \count($orderCoefficients) !== 1
                || !\is_finite($orderCoefficients[0])
                || $orderCoefficients[0] < 0.0
                || $orderCoefficients[0] > SpecialFunctions::MAX_POLYGAMMA_ORDER
                || $orderCoefficients[0] !== \floor($orderCoefficients[0])
            ) {
                return null;
            }
            $argumentIndex = 1;
        }
        $argumentCoefficients = $extractor->extract($wrapper->arguments[$argumentIndex]);
        if (
            $levelCoefficients === null
            || \count($levelCoefficients) !== 1
            || !\is_finite($levelCoefficients[0])
            || $argumentCoefficients === null
        ) {
            return null;
        }
        $level = $levelCoefficients[0];
        if ($this->transcendentalLevelIsOmitted($wrapper->name, $level)) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if (\count($argumentCoefficients) > 1) {
            throw new InfiniteSolutionSetException(
                \sprintf(
                    'The complex equation %s(P(x)) = c has infinitely many roots at this level.',
                    $wrapper->name,
                ),
            );
        }
        if (
            ($wrapper->name === 'erf' && \abs($level) >= 1.0)
            || ($wrapper->name === 'erfc' && ($level <= 0.0 || $level >= 2.0))
        ) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        try {
            $value = (float) (new Evaluator(
                new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                $evaluationOptions->limits,
            ))->evaluate($wrapper);
        } catch (EvaluationException) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if ($value === $level) {
            throw new InfiniteSolutionSetException(
                'The constant complex equation holds for every value of the solved variable.',
            );
        }

        return new ComplexPolynomialSolution([], 0, true, 0);
    }

    private function transcendentalLevelIsOmitted(string $function, float $level): bool
    {
        return match ($function) {
            'exp' => $level === 0.0,
            'expm1' => $level === -1.0,
            'gamma' => $level === 0.0,
            'tanh' => \abs($level) === 1.0,
            'coth' => \abs($level) === 1.0,
            'sec', 'csc', 'sech', 'csch' => $level === 0.0,
            default => false,
        };
    }

    /** @param array<string, int|float> $parameters */
    private function solveIntegerShapeRegularizedGammaEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && \in_array($candidate->name, ['gammainc', 'gammaincc'], true)
                && \count($candidate->arguments) === 2
                && $evaluationOptions->functions->isBuiltIn($candidate->name)
            ) {
                $wrapper = $candidate;
                $levelNode = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $shape = $extractor->extract($wrapper->arguments[0]);
        $level = $extractor->extract($levelNode);
        $argument = $extractor->extract($wrapper->arguments[1]);
        if (
            $shape === null
            || \count($shape) !== 1
            || !\is_finite($shape[0])
            || $shape[0] <= 0.0
            || $shape[0] !== \floor($shape[0])
            || $level === null
            || \count($level) !== 1
            || !\is_finite($level[0])
            || $argument === null
        ) {
            return null;
        }

        $omittedLevel = $wrapper->name === 'gammainc' ? 1.0 : 0.0;
        if (\count($argument) > 1) {
            if ($level[0] === $omittedLevel) {
                if ($shape[0] === 1.0) {
                    return new ComplexPolynomialSolution([], 0, true, 0);
                }

                return $this->solveIntegerShapeGammaExceptionalLevel(
                    $wrapper->arguments[1],
                    (int) $shape[0],
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }

            throw new InfiniteSolutionSetException(
                \sprintf(
                    'The complex equation %s(a, P(x)) = c has infinitely many roots at this level.',
                    $wrapper->name,
                ),
            );
        }

        try {
            $value = (new Evaluator(
                new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                $evaluationOptions->limits,
            ))->evaluate($wrapper);
        } catch (EvaluationException) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if ($value === $level[0]) {
            throw new InfiniteSolutionSetException(
                'The constant regularized-gamma equation holds for every complex value.',
            );
        }

        return new ComplexPolynomialSolution([], 0, true, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveIntegerShapeGammaExceptionalLevel(
        Node $argument,
        int $shape,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexPolynomialSolution {
        if ($shape > $solverOptions->maxPolynomialDegree + 1) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        // The omitted level reduces to sum(argument^k / k!, k=0..shape-1) = 0.
        $integerExtractor = new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree);
        $coefficients = [];
        $factorial = '1';
        for ($degree = 0; $degree < $shape; ++$degree) {
            $coefficients[] = ['1', $factorial];
            $factorial = $integerExtractor->multiplyIntegerValues($factorial, (string) ($degree + 1));
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $composition = $extractor->rationalPolynomialInRationalFunction($argument, $coefficients);
        $numerator = $composition[0] ?? null;
        $normalized = $numerator === null ? null : $integerExtractor->normalizedCoefficients($numerator, true);
        if ($numerator === null || $normalized === null) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        return (new ComplexPolynomialSolver())->solve($normalized, $solverOptions, $numerator);
    }

    /** @param array<string, int|float> $parameters */
    private function solveIntegerShapeRegularizedBetaEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && \in_array($candidate->name, ['betainc', 'betaincc'], true)
                && \count($candidate->arguments) === 3
                && $evaluationOptions->functions->isBuiltIn($candidate->name)
            ) {
                $wrapper = $candidate;
                $levelNode = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $alpha = $extractor->extract($wrapper->arguments[0]);
        $beta = $extractor->extract($wrapper->arguments[1]);
        $argument = $extractor->extract($wrapper->arguments[2]);
        if (
            $alpha === null
            || \count($alpha) !== 1
            || !\is_finite($alpha[0])
            || $alpha[0] < 1.0
            || $alpha[0] !== \floor($alpha[0])
            || $beta === null
            || \count($beta) !== 1
            || !\is_finite($beta[0])
            || $beta[0] < 1.0
            || $beta[0] !== \floor($beta[0])
            || $argument === null
        ) {
            return null;
        }

        $shapeDegree = $alpha[0] + $beta[0] - 1.0;
        if (!\is_finite($shapeDegree) || $shapeDegree > $solverOptions->maxPolynomialDegree) {
            if (\count($argument) === 1) {
                try {
                    $value = (new Evaluator(
                        new Environment([...$parameters, $variable => 0.0], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    ))->evaluate($wrapper);
                } catch (EvaluationException) {
                    return new ComplexPolynomialSolution([], 0, false, 0);
                }
                $exactExtractor = new ExactRationalPolynomialExtractor(
                    $variable,
                    $parameters,
                    $solverOptions->maxPolynomialDegree,
                    $evaluationOptions,
                );
                $levelForm = $exactExtractor->polynomial($levelNode);
                if ($levelForm === null || \count($levelForm) !== 1) {
                    return new ComplexPolynomialSolution([], 0, false, 0);
                }
                if ($value === (float) $levelForm[0][0] / (float) $levelForm[0][1]) {
                    throw new InfiniteSolutionSetException(
                        'The constant regularized-beta equation holds for every complex value.',
                    );
                }

                return new ComplexPolynomialSolution([], 0, true, 0);
            }

            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        $exactExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        if ($exactExtractor->polynomial($levelNode) === null) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        $integerExtractor = new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree);
        $equationCoefficients = IntegerShapeBetaPolynomial::equationCoefficients(
            (int) $alpha[0],
            (int) $beta[0],
            $wrapper->name === 'betaincc',
            ['0', '1'],
            $exactExtractor,
            $integerExtractor,
        );
        if ($equationCoefficients === null) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        $composition = $exactExtractor->rationalPolynomialDifferenceInRationalFunction(
            $wrapper->arguments[2],
            $equationCoefficients,
            $levelNode,
        );
        $numerator = $composition[0] ?? null;
        $normalized = $numerator === null ? null : $integerExtractor->normalizedCoefficients($numerator, true);
        if ($numerator === null || $normalized === null) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if (\count($argument) === 1) {
            if (\count($numerator) === 1 && $numerator[0] === '0') {
                throw new InfiniteSolutionSetException(
                    'The constant regularized-beta equation holds for every complex value.',
                );
            }

            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        return (new ComplexPolynomialSolver())->solve($normalized, $solverOptions, $numerator);
    }

    /** @param array<string, int|float> $parameters */
    private function solveLogarithmLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isLogarithmCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isLogarithmCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
            $wrapperSource = $rightText;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $targetCoefficients = $extractor->extract($constantSide);
        if (
            $targetCoefficients === null
            || \count($targetCoefficients) !== 1
            || !\is_finite($targetCoefficients[0])
        ) {
            return null;
        }
        $target = $targetCoefficients[0];
        if ($wrapper->name === 'log' && \count($wrapper->arguments) === 2) {
            $baseCoefficients = $extractor->extract($wrapper->arguments[1]);
            if (
                ($baseCoefficients !== null && \count($baseCoefficients) > 1)
                || ($baseCoefficients === null && $this->containsVariable($wrapper->arguments[1], $variable))
            ) {
                return $this->solveVariableBaseLogEquation(
                    $left,
                    $right,
                    $wrapper,
                    $wrapperSource,
                    $target,
                    $constantSide,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }
        }
        $base = match ($wrapper->name) {
            'ln', 'log1p' => M_E,
            'log10' => 10.0,
            'log2' => 2.0,
            'log' => 10.0,
            default => 0.0,
        };
        if ($wrapper->name === 'log' && \count($wrapper->arguments) === 2) {
            $baseCoefficients = $extractor->extract($wrapper->arguments[1]);
            if ($baseCoefficients === null || \count($baseCoefficients) !== 1) {
                return null;
            }
            $base = $baseCoefficients[0];
        } elseif (\count($wrapper->arguments) !== 1) {
            return null;
        }
        if ($wrapper->name === 'log' && (!\is_finite($base) || $base <= 0.0 || $base === 1.0)) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        $argumentTarget = match ($wrapper->name) {
            'ln' => \exp($target),
            'log1p' => \expm1($target),
            'log', 'log10', 'log2' => \pow($base, $target),
            default => NAN,
        };
        if (
            !\is_finite($argumentTarget)
            || ($argumentTarget === 0.0 && !($wrapper->name === 'log1p' && $target === 0.0))
            || ($wrapper->name === 'log1p' && $argumentTarget <= -1.0)
        ) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        $argument = $wrapper->arguments[0];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $argumentTargetSource = \json_encode($argumentTarget, \JSON_PRESERVE_ZERO_FRACTION);
        if ($argumentSource === '' || !\is_string($argumentTargetSource)) {
            return null;
        }

        return $this->solve(
            $argumentSource . '=' . $argumentTargetSource,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveVariableBaseLogEquation(
        Node $left,
        Node $right,
        FunctionCallNode $wrapper,
        string $wrapperSource,
        float $level,
        Node $levelNode,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexPolynomialSolution {
        $fraction = RationalLiteral::fromNodeBounded(
            $levelNode,
            $solverOptions->maxPolynomialDegree,
            $solverOptions->maxPolynomialDegree,
        );
        if ($fraction === null) {
            $fraction = RationalLiteral::fromFloatBounded(
                $level,
                $solverOptions->maxPolynomialDegree,
                $solverOptions->maxPolynomialDegree,
            );
        }
        $argument = $wrapper->arguments[0];
        $base = $wrapper->arguments[1];
        $argumentSpan = $argument->span();
        $baseSpan = $base->span();
        $argumentSource = \substr(
            $wrapperSource,
            $argumentSpan->start,
            $argumentSpan->end - $argumentSpan->start,
        );
        $baseSource = \substr($wrapperSource, $baseSpan->start, $baseSpan->end - $baseSpan->start);
        if ($argumentSource === '' || $baseSource === '') {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if ($fraction === null) {
            $argumentCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($argument);
            if ($argumentCoefficients === null || \count($argumentCoefficients) !== 1) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
            $argumentValue = $argumentCoefficients[0];
            if ($argumentValue <= 0.0) {
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
            if ($level === 0.0) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
            $targetBase = \exp(\log($argumentValue) / $level);
            $targetSource = \json_encode($targetBase, \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_finite($targetBase) || $targetBase <= 0.0 || !\is_string($targetSource)) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
            if ($targetBase === 1.0) {
                return new ComplexPolynomialSolution([], 0, $argumentValue === 1.0, 0);
            }
            $transformedEquation = '(' . $baseSource . ')=' . $targetSource;
        } else {
            if (
                \abs($fraction[0]) > $solverOptions->maxPolynomialDegree
                || $fraction[1] > $solverOptions->maxPolynomialDegree
            ) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
            [$levelNumerator, $levelDenominator] = $fraction;
            if ($levelNumerator === 0) {
                $transformedEquation = '(' . $argumentSource . ')=1';
            } elseif ($levelNumerator > 0) {
                $transformedEquation = '(' . $baseSource . ')^' . $levelNumerator . '=(' . $argumentSource . ')^'
                    . $levelDenominator;
            } else {
                $transformedEquation = '(' . $argumentSource . ')^' . $levelDenominator . '*(' . $baseSource . ')^'
                    . \abs($levelNumerator) . '=1';
            }
        }
        try {
            $algebraic = $this->solve(
                $transformedEquation,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\InvalidArgumentException) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        $roots = [];
        $converged = $algebraic->converged;
        foreach ($algebraic->roots as $root) {
            $scale = \max(1.0, \abs($root->real), \abs($root->imaginary));
            if (
                \abs($root->imaginary)
                    > \min(1.0E-8, 128.0 * \PHP_FLOAT_EPSILON * $scale)
            ) {
                continue;
            }
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => $root->real], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $leftValue = $evaluator->evaluate($left);
                $rightValue = $evaluator->evaluate($right);
            } catch (EvaluationException) {
                continue;
            }
            $residual = \abs($leftValue - $rightValue)
                / \max(1.0, \abs($leftValue), \abs($rightValue));
            if ($residual > $solverOptions->residualTolerance) {
                $converged = false;
                continue;
            }
            $roots[] = new ComplexRoot($root->real, 0.0, $residual);
        }

        return new ComplexPolynomialSolution($roots, \count($roots), $converged, $algebraic->iterations);
    }

    private function isLogarithmCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && \in_array($node->name, ['ln', 'log', 'log10', 'log2', 'log1p'], true);
    }

    private function containsVariable(Node $node, string $variable): bool
    {
        if ($node instanceof VariableNode) {
            return $node->name === $variable;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsVariable($node->expression, $variable);
        }
        if ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            return $this->containsVariable($node->operand, $variable);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariable($node->left, $variable)
                || $this->containsVariable($node->right, $variable);
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

    /** @param array<string, int|float> $parameters */
    private function solveInverseFunctionLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isInverseFunctionCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isInverseFunctionCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
            $wrapperSource = $rightText;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }
        $polynomialExtractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $levelCoefficients = $polynomialExtractor->extract($constantSide);
        if (
            $levelCoefficients === null
            || \count($levelCoefficients) !== 1
            || !\is_finite($levelCoefficients[0])
        ) {
            return null;
        }
        $level = $levelCoefficients[0];
        $functionName = match ($wrapper->name) {
            'arcsin' => 'asin',
            'arccos' => 'acos',
            'arctan' => 'atan',
            'arcsinh' => 'asinh',
            'arccosh' => 'acosh',
            'arctanh' => 'atanh',
            default => $wrapper->name,
        };
        $halfPi = \M_PI / 2.0;
        $noSolutions = false;
        $argumentTarget = NAN;
        $argumentIndex = 0;
        $branch = 0.0;
        switch ($functionName) {
            case 'asin':
                if ($level < -$halfPi || $level > $halfPi) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = \sin($level);
                }
                break;
            case 'acos':
                if ($level < 0.0 || $level > \M_PI) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = $level === $halfPi ? 0.0 : \cos($level);
                }
                break;
            case 'atan':
                if (\abs($level) >= $halfPi) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = \tan($level);
                }
                break;
            case 'acot':
                if ($level <= 0.0 || $level >= \M_PI) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = $level === $halfPi ? 0.0 : \cos($level) / \sin($level);
                }
                break;
            case 'asinh':
                $argumentTarget = \sinh($level);
                break;
            case 'acosh':
                if ($level < 0.0) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = \cosh($level);
                }
                break;
            case 'atanh':
                $argumentTarget = \tanh($level);
                break;
            case 'erfinv':
                $argumentTarget = SpecialFunctions::erf($level);
                break;
            case 'erfcinv':
                $argumentTarget = SpecialFunctions::erfc($level);
                break;
            case 'gammaincinv':
            case 'gammainccinv':
                $shapeCoefficients = $polynomialExtractor->extract($wrapper->arguments[0]);
                if (
                    $shapeCoefficients === null
                    || \count($shapeCoefficients) !== 1
                    || !\is_finite($shapeCoefficients[0])
                    || $shapeCoefficients[0] <= 0.0
                ) {
                    return null;
                }
                if ($level < 0.0) {
                    $noSolutions = true;
                    break;
                }
                $argumentIndex = 1;
                $argumentTarget = $functionName === 'gammaincinv'
                    ? SpecialFunctions::regularizedGammaP($shapeCoefficients[0], $level)
                    : SpecialFunctions::regularizedGammaQ($shapeCoefficients[0], $level);
                break;
            case 'betaincinv':
            case 'betainccinv':
                $firstShapeCoefficients = $polynomialExtractor->extract($wrapper->arguments[0]);
                $secondShapeCoefficients = $polynomialExtractor->extract($wrapper->arguments[1]);
                if (
                    $firstShapeCoefficients === null
                    || \count($firstShapeCoefficients) !== 1
                    || !\is_finite($firstShapeCoefficients[0])
                    || $firstShapeCoefficients[0] <= 0.0
                    || $secondShapeCoefficients === null
                    || \count($secondShapeCoefficients) !== 1
                    || !\is_finite($secondShapeCoefficients[0])
                    || $secondShapeCoefficients[0] <= 0.0
                ) {
                    return null;
                }
                if ($level < 0.0 || $level > 1.0) {
                    $noSolutions = true;
                    break;
                }
                $argumentIndex = 2;
                $argumentTarget = $functionName === 'betaincinv'
                    ? SpecialFunctions::regularizedBeta(
                        $firstShapeCoefficients[0],
                        $secondShapeCoefficients[0],
                        $level,
                    )
                    : SpecialFunctions::regularizedBetaComplement(
                        $firstShapeCoefficients[0],
                        $secondShapeCoefficients[0],
                        $level,
                    );
                break;
            case 'lambertw':
                if (\count($wrapper->arguments) === 2) {
                    $branchCoefficients = $polynomialExtractor->extract($wrapper->arguments[1]);
                    if (
                        $branchCoefficients === null
                        || \count($branchCoefficients) !== 1
                        || !\is_finite($branchCoefficients[0])
                    ) {
                        return null;
                    }
                    $branch = $branchCoefficients[0];
                }
                if ($branch !== 0.0 && $branch !== -1.0) {
                    return null;
                }
                if (($branch === 0.0 && $level < -1.0) || ($branch === -1.0 && $level > -1.0)) {
                    $noSolutions = true;
                    break;
                }
                $argumentTarget = $level * \exp($level);
                if ($branch === -1.0 && $argumentTarget === 0.0) {
                    return new ComplexPolynomialSolution([], 0, false, 0);
                }
                break;
            case 'asec':
                if ($level < 0.0 || $level > \M_PI || $level === $halfPi) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = 1.0 / \cos($level);
                }
                break;
            case 'acsc':
                if ($level < -$halfPi || $level > $halfPi || $level === 0.0) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = 1.0 / \sin($level);
                }
                break;
            case 'asech':
                if ($level < 0.0) {
                    $noSolutions = true;
                } else {
                    $decayingExponential = \exp(-$level);
                    $argumentTarget = (2.0 * $decayingExponential)
                        / (1.0 + ($decayingExponential * $decayingExponential));
                }
                break;
            case 'acsch':
                if ($level === 0.0) {
                    $noSolutions = true;
                } elseif (\abs($level) < 20.0) {
                    $argumentTarget = 1.0 / \sinh($level);
                } else {
                    $decayingExponential = \exp(-\abs($level));
                    $argumentTarget = ($level < 0.0 ? -1.0 : 1.0)
                        * (2.0 * $decayingExponential)
                        / (1.0 - ($decayingExponential * $decayingExponential));
                }
                break;
            case 'acoth':
                if ($level === 0.0) {
                    $noSolutions = true;
                } else {
                    $argumentTarget = 1.0 / \tanh($level);
                }
                break;
        }
        if ($noSolutions) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if (!\is_finite($argumentTarget)) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        $endpointRoundedAway = match ($functionName) {
            'asin' => ($level > -$halfPi && $argumentTarget === -1.0)
                || ($level < $halfPi && $argumentTarget === 1.0),
            'acos', 'asec' => ($level > 0.0 && $argumentTarget === 1.0)
                || ($level < \M_PI && $argumentTarget === -1.0),
            'acsc' => ($level > -$halfPi && $argumentTarget === -1.0)
                || ($level < $halfPi && $argumentTarget === 1.0),
            'acosh', 'asech' => $level > 0.0 && $argumentTarget === 1.0,
            'lambertw' => $level !== -1.0
                && \abs($argumentTarget - (-1.0 / \M_E))
                    <= 4.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($argumentTarget)),
            default => false,
        };
        if ($endpointRoundedAway) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if (\in_array($functionName, ['asech', 'acsch'], true) && $argumentTarget === 0.0) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if ($functionName === 'atanh' && \abs($argumentTarget) >= 1.0) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if ($functionName === 'acoth' && \abs($argumentTarget) <= 1.0) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if (
            ($functionName === 'erfinv' && \abs($argumentTarget) >= 1.0)
            || ($functionName === 'erfcinv' && ($argumentTarget <= 0.0 || $argumentTarget >= 2.0))
            || ($functionName === 'gammaincinv' && $argumentTarget >= 1.0)
            || ($functionName === 'gammainccinv' && $argumentTarget <= 0.0)
        ) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        $argument = $wrapper->arguments[$argumentIndex];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $targetSource = \json_encode($argumentTarget, \JSON_PRESERVE_ZERO_FRACTION);
        if ($argumentSource === '' || !\is_string($targetSource)) {
            return null;
        }

        return $this->solve(
            $argumentSource . '=' . $targetSource,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    private function isInverseFunctionCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        if (!$node instanceof FunctionCallNode) {
            return false;
        }

        return (\count($node->arguments) === 1 && \in_array(
            $node->name,
            [
                'asin', 'arcsin', 'acos', 'arccos', 'atan', 'arctan', 'acot',
                'asinh', 'arcsinh', 'acosh', 'arccosh', 'atanh', 'arctanh',
                'asec', 'acsc', 'asech', 'acsch', 'acoth', 'erfinv', 'erfcinv',
            ],
            true,
        )) || (\count($node->arguments) === 2 && \in_array(
            $node->name,
            ['gammaincinv', 'gammainccinv'],
            true,
        )) || (\count($node->arguments) === 3 && \in_array(
            $node->name,
            ['betaincinv', 'betainccinv'],
            true,
        )) || ($node->name === 'lambertw' && \in_array(\count($node->arguments), [1, 2], true));
    }

    /** @param array<string, int|float> $parameters */
    private function solveHypotenuseLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isHypotenuseCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isHypotenuseCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
            $wrapperSource = $rightText;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $level = $extractor->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        if ($level[0] < 0.0) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if (
            $extractor->extract($wrapper->arguments[0]) === null
            || $extractor->extract($wrapper->arguments[1]) === null
        ) {
            return null;
        }

        $firstSpan = $wrapper->arguments[0]->span();
        $secondSpan = $wrapper->arguments[1]->span();
        $firstArgument = \substr($wrapperSource, $firstSpan->start, $firstSpan->end - $firstSpan->start);
        $secondArgument = \substr($wrapperSource, $secondSpan->start, $secondSpan->end - $secondSpan->start);
        if ($firstArgument === '' || $secondArgument === '') {
            return null;
        }
        if ($level[0] === 0.0) {
            $equation = '(' . $firstArgument . ')^2+(' . $secondArgument . ')^2=0';
        } else {
            $scale = \json_encode($level[0], \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_string($scale)) {
                return null;
            }
            $equation = '((' . $firstArgument . ')/' . $scale . ')^2'
                . '+((' . $secondArgument . ')/' . $scale . ')^2=1';
        }

        return $this->solve(
            $equation,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    private function isHypotenuseCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode && $node->name === 'hypot' && \count($node->arguments) === 2;
    }

    /** @param array<string, int|float> $parameters */
    private function solveSquareRootLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isSquareRootCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isSquareRootCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
            $wrapperSource = $rightText;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }

        $target = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($target === null || \count($target) !== 1 || !\is_finite($target[0])) {
            return null;
        }
        if ($target[0] < 0.0) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        $argument = $wrapper->arguments[0];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $targetSquared = \json_encode($target[0] * $target[0], \JSON_PRESERVE_ZERO_FRACTION);
        if ($argumentSource === '') {
            return null;
        }
        if (!\is_string($targetSquared)) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        try {
            return $this->solve(
                $argumentSource . '=' . $targetSquared,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\InvalidArgumentException) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
    }

    private function isSquareRootCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && $node->name === 'sqrt'
            && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingSquareRootEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $leftTerm = $this->signedSquareRootTerm($left);
        $rightTerm = $this->signedSquareRootTerm($right);
        if ($leftTerm === null || $rightTerm === null) {
            return null;
        }
        [$leftCall, $leftSign] = $leftTerm;
        [$rightCall, $rightSign] = $rightTerm;
        $leftArgument = $leftCall->arguments[0];
        $rightArgument = $rightCall->arguments[0];
        $leftSpan = $leftArgument->span();
        $rightSpan = $rightArgument->span();
        $leftSource = \substr($leftText, $leftSpan->start, $leftSpan->end - $leftSpan->start);
        $rightSource = \substr($rightText, $rightSpan->start, $rightSpan->end - $rightSpan->start);
        if ($leftSource === '' || $rightSource === '') {
            return null;
        }

        if ($leftSign === $rightSign) {
            try {
                return $this->solve(
                    '(' . $leftSource . ')=(' . $rightSource . ')',
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (\InvalidArgumentException) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
        }
        if ($leftSource === $rightSource) {
            try {
                return $this->solve(
                    $leftSource . '=0',
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (\InvalidArgumentException) {
                return new ComplexPolynomialSolution([], 0, false, 0);
            }
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftPolynomial = $extractor->polynomial($leftArgument);
        $rightPolynomial = $extractor->polynomial($rightArgument);
        $leftCoefficients = $extractor->integerCoefficients($leftPolynomial);
        $rightCoefficients = $extractor->integerCoefficients($rightPolynomial);
        if ($leftCoefficients === null || $rightCoefficients === null) {
            return null;
        }
        if ($this->isZeroIntegerPolynomial($leftCoefficients) && $this->isZeroIntegerPolynomial($rightCoefficients)) {
            throw new InfiniteSolutionSetException(
                'Both opposite principal square roots vanish identically, so every complex value is a solution.',
            );
        }

        $commonZeros = (new ExactPolynomialRootCounter())->greatestCommonDivisor(
            $leftCoefficients,
            $rightCoefficients,
        );
        if ($commonZeros === null) {
            return null;
        }
        if (\count($commonZeros) === 1) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        $coefficients = (new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree))
            ->normalizedCoefficients($commonZeros, true);
        if ($coefficients === null) {
            return null;
        }

        return (new ComplexPolynomialSolver())->solve($coefficients, $solverOptions, $commonZeros);
    }

    /** @return array{FunctionCallNode, int}|null */
    private function signedSquareRootTerm(Node $node): ?array
    {
        $sign = 1;
        while (true) {
            if ($node instanceof GroupingNode) {
                $node = $node->expression;
                continue;
            }
            if ($node instanceof UnaryOperationNode) {
                if ($node->operator === UnaryOperator::Minus) {
                    $sign = -$sign;
                }
                $node = $node->operand;
                continue;
            }
            break;
        }

        return $node instanceof FunctionCallNode && $node->name === 'sqrt' && \count($node->arguments) === 1
            ? [$node, $sign]
            : null;
    }

    private function isEquivalentModuloTwoPi(float $value, float $target): bool
    {
        $turns = \round(($value - $target) / (2.0 * \M_PI));
        if (!\is_finite($turns)) {
            return false;
        }

        return $value === $target + (2.0 * $turns * \M_PI);
    }

    /** @param list<string> $coefficients */
    private function isZeroIntegerPolynomial(array $coefficients): bool
    {
        foreach ($coefficients as $coefficient) {
            if ($coefficient !== '0') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int|float> $parameters */
    private function solveIntegerRootLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        foreach ([[$left, $right, $leftText], [$right, $left, $rightText]] as [$candidate, $other, $source]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && ((\in_array($candidate->name, ['cbrt'], true) && \count($candidate->arguments) === 1)
                    || ($candidate->name === 'root' && \count($candidate->arguments) === 2))
            ) {
                $wrapper = $candidate;
                $constantSide = $other;
                $wrapperSource = $source;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $level = $extractor->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $degree = 3.0;
        if ($wrapper->name === 'root') {
            $degreeCoefficients = $extractor->extract($wrapper->arguments[1]);
            if ($degreeCoefficients === null || \count($degreeCoefficients) !== 1) {
                return null;
            }
            $degree = $degreeCoefficients[0];
            $nearestDegree = \round($degree);
            $degreeTolerance = \min(
                1.0E-9,
                16.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($degree)),
            );
            if (\abs($degree - $nearestDegree) <= $degreeTolerance) {
                $degree = $nearestDegree;
            }
        }
        if (
            !\is_finite($degree)
            || $degree === 0.0
            || \floor($degree) !== $degree
            || \abs($degree) > $solverOptions->maxPolynomialDegree
        ) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        if (\fmod(\abs($degree), 2.0) === 0.0 && $level[0] < 0.0) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if ($degree < 0.0 && $level[0] === 0.0) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        $argument = $wrapper->arguments[0];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        if ($argumentSource === '') {
            return null;
        }
        $target = $level[0] ** $degree;
        if (
            !\is_finite($target)
            || ($target === 0.0 && $level[0] !== 0.0)
        ) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
        $targetSource = \json_encode($target, \JSON_PRESERVE_ZERO_FRACTION);
        if (!\is_string($targetSource)) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }

        try {
            return $this->solve(
                $argumentSource . '=' . $targetSource,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\InvalidArgumentException) {
            return new ComplexPolynomialSolution([], 0, false, 0);
        }
    }

    /** @param array<string, int|float> $parameters */
    private function solveZeroWrapperEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $wrapper = null;
        $source = '';
        if ($this->isZero($right)) {
            $wrapper = $left;
            $source = $leftText;
        } elseif ($this->isZero($left)) {
            $wrapper = $right;
            $source = $rightText;
        }
        if ($wrapper === null) {
            return null;
        }
        $sourceBase = $wrapper->span()->start;
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if ($wrapper instanceof FactorialNode) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return null;
        }
        if (
            \in_array($wrapper->name, ['erf', 'erfi', 'erfc', 'erfcx', 'dawson', 'zeta'], true)
            && \count($wrapper->arguments) === 1
        ) {
            $argumentCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($wrapper->arguments[0]);
            if ($argumentCoefficients !== null) {
                if (\count($argumentCoefficients) > 1) {
                    throw new InfiniteSolutionSetException(
                        \sprintf('The complex equation %s(P(x)) = 0 has infinitely many roots.', $wrapper->name),
                    );
                }
                $argumentValue = $argumentCoefficients[0];
                $hasRealZero = \in_array($wrapper->name, ['erf', 'erfi', 'dawson'], true)
                    ? $argumentValue === 0.0
                    : ($wrapper->name === 'zeta'
                        && $argumentValue < 0.0
                        && $argumentValue / 2.0 === \floor($argumentValue / 2.0));
                if ($hasRealZero) {
                    throw new InfiniteSolutionSetException(
                        \sprintf('The complex equation %s(%s) = 0 is an identity.', $wrapper->name, $argumentValue),
                    );
                }
                return new ComplexPolynomialSolution([], 0, true, 0);
            }
        }
        if (
            \in_array($wrapper->name, ['besselj', 'besseli', 'bessely'], true)
            && \count($wrapper->arguments) === 2
        ) {
            $polynomialExtractor = new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            );
            $order = $polynomialExtractor->extract($wrapper->arguments[0]);
            $argumentCoefficients = $polynomialExtractor->extract($wrapper->arguments[1]);
            if (
                $order !== null
                && \count($order) === 1
                && \is_finite($order[0])
                && $order[0] === \floor($order[0])
                && \abs($order[0]) <= SpecialFunctions::MAX_BESSEL_ORDER
                && $argumentCoefficients !== null
                && \count($argumentCoefficients) > 1
            ) {
                throw new InfiniteSolutionSetException(
                    \sprintf('The complex equation %s(n, P(x)) = 0 has infinitely many roots.', $wrapper->name),
                );
            }
        }
        if (
            \in_array(
                $wrapper->name,
                [
                    'sin', 'cos', 'tan', 'cot', 'sinh', 'cosh', 'tanh', 'coth',
                    'sinc', 'sincpi', 'expm1',
                ],
                true,
            )
            && \count($wrapper->arguments) === 1
        ) {
            $argumentCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($wrapper->arguments[0]);
            if ($argumentCoefficients !== null && \count($argumentCoefficients) > 1) {
                throw new InfiniteSolutionSetException(
                    \sprintf('The complex equation %s(P(x)) = 0 has infinitely many roots.', $wrapper->name),
                );
            }
        }
        if (
            \in_array(
                $wrapper->name,
                ['exp', 'gamma', 'sec', 'csc', 'sech', 'csch', 'acsc', 'acot', 'acsch', 'acoth'],
                true,
            )
            && \count($wrapper->arguments) === 1
        ) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        $argument = null;
        $target = '0';
        if (
            \in_array($wrapper->name, ['sqrt', 'cbrt'], true)
            && \count($wrapper->arguments) === 1
        ) {
            $argument = $wrapper->arguments[0];
        } elseif ($wrapper->name === 'root' && \count($wrapper->arguments) === 2) {
            try {
                $degree = (new \MathPHP\Evaluator\Evaluator(
                    new Environment($parameters, $evaluationOptions->functions),
                    $evaluationOptions->limits,
                ))->evaluate($wrapper->arguments[1]);
            } catch (\MathPHP\Exception\EvaluationException) {
                return null;
            }
            if (!\is_finite((float) $degree) || (float) $degree < 1.0 || (float) $degree !== \floor((float) $degree)) {
                return null;
            }
            $argument = $wrapper->arguments[0];
        } elseif (
            \in_array($wrapper->name, ['ln', 'log10', 'log2'], true)
            && \count($wrapper->arguments) === 1
        ) {
            $argument = $wrapper->arguments[0];
            $target = '1';
        } elseif ($wrapper->name === 'log1p' && \count($wrapper->arguments) === 1) {
            $argument = $wrapper->arguments[0];
        } elseif (
            $wrapper->name === 'log'
            && \count($wrapper->arguments) === 1
        ) {
            $argument = $wrapper->arguments[0];
            $target = '1';
        } elseif ($wrapper->name === 'log' && \count($wrapper->arguments) === 2) {
            $baseCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($wrapper->arguments[1]);
            if (
                $baseCoefficients === null
                || \count($baseCoefficients) !== 1
                || !\is_finite($baseCoefficients[0])
                || $baseCoefficients[0] <= 0.0
                || $baseCoefficients[0] === 1.0
            ) {
                return null;
            }
            $argument = $wrapper->arguments[0];
            $target = '1';
        } elseif (
            \in_array($wrapper->name, ['asin', 'atan', 'asinh', 'atanh', 'abs'], true)
            && \count($wrapper->arguments) === 1
        ) {
            $argument = $wrapper->arguments[0];
        } elseif (
            \in_array($wrapper->name, ['acos', 'acosh', 'asec', 'asech'], true)
            && \count($wrapper->arguments) === 1
        ) {
            $argument = $wrapper->arguments[0];
            $target = '1';
        } elseif ($wrapper->name === 'erfinv' && \count($wrapper->arguments) === 1) {
            $argument = $wrapper->arguments[0];
        } elseif ($wrapper->name === 'erfcinv' && \count($wrapper->arguments) === 1) {
            $argument = $wrapper->arguments[0];
            $target = '1';
        }
        if ($argument === null) {
            return null;
        }

        $argumentSpan = $argument->span();
        $argumentSource = \substr(
            $source,
            $argumentSpan->start - $sourceBase,
            $argumentSpan->end - $argumentSpan->start,
        );
        if ($argumentSource === '') {
            return null;
        }

        return $this->solve(
            $argumentSource . '=' . $target,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
    }

    /**
     * Preserve explicitly written product factors so floating-point expansion
     * cannot split exact repeated roots before the root solver sees them.
     *
     * @param array<string, int|float> $parameters
     */
    private function solveProductEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        if ($this->isZero($left)) {
            return $this->solveProductNode(
                $right,
                $rightText,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        if ($this->isZero($right)) {
            return $this->solveProductNode(
                $left,
                $leftText,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        return null;
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function solveProductNode(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }

        if ($node->operator === BinaryOperator::Power && $node->right instanceof NumberNode) {
            $power = $node->right->value;
            if (
                !\is_int($power)
                || $power < 1
                || $power > $evaluationOptions->limits->maxExponentMagnitude
            ) {
                return null;
            }
            $baseSource = \substr(
                $source,
                $node->left->span()->start - $node->span()->start,
                $node->left->span()->end - $node->left->span()->start,
            );
            $baseSolution = $this->solveFactorOrProduct(
                $node->left,
                $baseSource,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if (
                $baseSolution === null
                || $baseSolution->degree * $power > $solverOptions->maxPolynomialDegree
            ) {
                return null;
            }
            $roots = [];
            for ($index = 0; $index < $power; ++$index) {
                array_push($roots, ...$baseSolution->roots);
            }
            \usort(
                $roots,
                static fn (ComplexRoot $left, ComplexRoot $right): int =>
                    [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
            );

            return new ComplexPolynomialSolution(
                $roots,
                $baseSolution->degree * $power,
                $baseSolution->converged,
                $baseSolution->iterations,
            );
        }
        if ($node->operator !== BinaryOperator::Multiply) {
            return null;
        }

        $leftSource = \substr(
            $source,
            $node->left->span()->start - $node->span()->start,
            $node->left->span()->end - $node->left->span()->start,
        );
        $rightSource = \substr(
            $source,
            $node->right->span()->start - $node->span()->start,
            $node->right->span()->end - $node->right->span()->start,
        );
        $leftSolution = $this->solveFactorOrProduct(
            $node->left,
            $leftSource,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $rightSolution = $this->solveFactorOrProduct(
            $node->right,
            $rightSource,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if (
            $leftSolution === null
            || $rightSolution === null
            || $leftSolution->degree + $rightSolution->degree > $solverOptions->maxPolynomialDegree
        ) {
            return null;
        }

        $roots = [...$leftSolution->roots, ...$rightSolution->roots];
        \usort(
            $roots,
            static fn (ComplexRoot $left, ComplexRoot $right): int =>
                [$left->real, $left->imaginary] <=> [$right->real, $right->imaginary],
        );

        return new ComplexPolynomialSolution(
            $roots,
            $leftSolution->degree + $rightSolution->degree,
            $leftSolution->converged && $rightSolution->converged,
            $leftSolution->iterations + $rightSolution->iterations,
        );
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function solveFactorOrProduct(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexPolynomialSolution {
        $product = $this->solveProductNode(
            $node,
            $source,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($product !== null) {
            return $product;
        }
        $exactCoefficients = null;
        $coefficients = $this->polynomialDifference(
            $node,
            new NumberNode(0, '0', $node->span()),
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
            $exactCoefficients,
        );
        if ($coefficients === null) {
            $factor = $node;
            while ($factor instanceof GroupingNode) {
                $factor = $factor->expression;
            }
            if ($factor instanceof FunctionCallNode || $factor instanceof FactorialNode) {
                return $this->solveZeroWrapperEquation(
                    $node,
                    new NumberNode(0, '0', $node->span()),
                    $source,
                    '0',
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }

            return null;
        }

        return (new ComplexPolynomialSolver())->solve(
            $coefficients,
            $solverOptions,
            $exactCoefficients,
        );
    }

    private function isZero(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof NumberNode
            && ($node->value === 0 || $node->value === 0.0);
    }

    /**
     * Solve chained polynomial equalities by intersecting their exact zero sets.
     *
     * @param array<string, int|float> $parameters
     */
    private function solveChainedPolynomialEquation(
        string $equation,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexPolynomialSolution {
        $parts = \explode('=', $equation);
        $nodes = [];
        $partOffset = 0;
        foreach ($parts as $part) {
            $nodes[] = $this->parseSide($part, $partOffset, $evaluationOptions);
            $partOffset += \strlen($part) + 1;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $counter = new ExactPolynomialRootCounter();
        $commonPolynomial = null;
        for ($index = 0; $index + 1 < \count($nodes); ++$index) {
            $polynomial = $extractor->difference($nodes[$index], $nodes[$index + 1]);
            if ($polynomial === null) {
                throw new \InvalidArgumentException(
                    'Chained complex polynomial solving requires polynomial expressions in every link.',
                );
            }
            if ($polynomial !== ['0']) {
                $commonPolynomial = $commonPolynomial === null
                    ? $polynomial
                    : $counter->greatestCommonDivisor($commonPolynomial, $polynomial);
                if ($commonPolynomial === null) {
                    throw new \InvalidArgumentException(
                        'The chained polynomial intersection exceeded exact arithmetic limits.',
                    );
                }
            }
        }
        if ($commonPolynomial === null) {
            throw new InfiniteSolutionSetException(
                'The chained polynomial equation is an identity and has infinitely many complex solutions.',
            );
        }
        if (\count($commonPolynomial) === 1) {
            return new ComplexPolynomialSolution([], 0, true, 0);
        }

        $coefficients = (new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree))
            ->normalizedCoefficients($commonPolynomial, true);
        if ($coefficients === null) {
            throw new \InvalidArgumentException(
                'The chained polynomial intersection exceeded numeric representation limits.',
            );
        }

        return (new ComplexPolynomialSolver())->solve($coefficients, $solverOptions, $commonPolynomial);
    }

    /** @return array{string, string, int} */
    private function splitEquation(string $equation): array
    {
        $equalsPosition = \strpos($equation, '=');
        if (
            $equalsPosition === false
            || \strpos($equation, '=', $equalsPosition + 1) !== false
        ) {
            $position = $equalsPosition === false ? \strlen($equation) : $equalsPosition;
            throw new ParseException(
                'Equation must contain exactly one "=" sign',
                'parse.equation_equals',
                new SourceSpan($position, $position + ($equalsPosition === false ? 0 : 1)),
            );
        }
        $left = \substr($equation, 0, $equalsPosition);
        $right = \substr($equation, $equalsPosition + 1);
        if (\trim($left) === '' || \trim($right) === '') {
            throw new ParseException(
                'Both sides of the equation must contain an expression',
                'parse.empty_equation_side',
                new SourceSpan($equalsPosition, $equalsPosition + 1),
            );
        }

        return [$left, $right, $equalsPosition + 1];
    }

    private function parseSide(string $expression, int $offset, EvaluationOptions $options): Node
    {
        try {
            $tokens = (new Lexer($expression, $options->limits))->tokenize();

            return (new Parser($tokens, $options->limits, $options->functions))->parse();
        } catch (LexicalException | ParseException $error) {
            if ($offset === 0) {
                throw $error;
            }
            $message = \preg_replace('/ at position \d+$/', '', $error->getMessage());
            $span = new SourceSpan(
                $error->span()->start + $offset,
                $error->span()->end + $offset,
            );
            if ($error instanceof LexicalException) {
                throw new LexicalException(
                    $message ?? 'Equation expression failed',
                    $error->errorCode(),
                    $span,
                    $error,
                );
            }

            throw new ParseException(
                $message ?? 'Equation expression failed',
                $error->errorCode(),
                $span,
                $error,
            );
        }
    }

    /**
     * @param array<string, int|float> $parameters
     * @param list<string>|null $exactCoefficients
     * @return list<float>|null
     * @param-out list<string>|null $exactCoefficients
     */
    private function polynomialDifference(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
        ?array &$exactCoefficients = null,
    ): ?array {
        $exactCoefficients = null;
        $exactRationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $exactRationalDifference = $exactRationalExtractor->difference($left, $right);
        if ($exactRationalDifference !== null) {
            $integerExtractor = new IntegerPolynomialExtractor(
                $variable,
                $parameters,
                $solverOptions->maxPolynomialDegree,
            );
            $normalizedExactDifference = $integerExtractor->normalizedCoefficients(
                $exactRationalDifference,
                true,
            );
            if ($normalizedExactDifference !== null) {
                $exactCoefficients = $exactRationalDifference;

                return $normalizedExactDifference;
            }
        }

        $rationalExtractor = new RationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $rationalDifference = $rationalExtractor->difference($left, $right);
        if ($rationalDifference !== null) {
            $integerExtractor = new IntegerPolynomialExtractor(
                $variable,
                $parameters,
                $solverOptions->maxPolynomialDegree,
            );
            $normalizedRationalDifference = $integerExtractor->normalizedCoefficients(
                $rationalDifference,
                true,
            );
            if ($normalizedRationalDifference !== null) {
                $exactCoefficients = $rationalDifference;

                return $normalizedRationalDifference;
            }
        }

        $integerExtractor = new IntegerPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
        );
        $integerLeft = $integerExtractor->extract($left);
        $integerRight = $integerExtractor->extract($right);
        if ($integerLeft !== null && $integerRight !== null) {
            $length = \max(\count($integerLeft), \count($integerRight));
            $difference = [];
            for ($degree = 0; $degree < $length; ++$degree) {
                $difference[] = $integerExtractor->subtract(
                    $integerLeft[$degree] ?? '0',
                    $integerRight[$degree] ?? '0',
                );
            }
            while (
                \count($difference) > 1
                && $integerExtractor->isZero($difference[\array_key_last($difference)])
            ) {
                \array_pop($difference);
            }
            $normalized = $integerExtractor->normalizedCoefficients($difference, true);
            if ($normalized !== null) {
                $exactCoefficients = $difference;

                return $normalized;
            }
        }

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $leftCoefficients = $extractor->extract($left);
        $rightCoefficients = $extractor->extract($right);
        if ($leftCoefficients === null || $rightCoefficients === null) {
            return null;
        }

        $length = \max(\count($leftCoefficients), \count($rightCoefficients));
        $difference = [];
        for ($degree = 0; $degree < $length; ++$degree) {
            $coefficient = ($leftCoefficients[$degree] ?? 0.0)
                - ($rightCoefficients[$degree] ?? 0.0);
            if (!\is_finite($coefficient)) {
                throw new \DomainException('Polynomial coefficient difference is not finite.');
            }
            $difference[] = $coefficient;
        }

        return $difference;
    }
}
