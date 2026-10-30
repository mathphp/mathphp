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
use MathPHP\Parser\TokenType;
use MathPHP\Source\SourceSpan;

final class ComplexRationalEquationSolver
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
    ): ComplexRationalSolution {
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
            return $this->solveChainedRationalPolynomialEquation(
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
        $normalizedLeft = $this->replaceConstantSubexpressionsWithLiterals(
            $leftText,
            $left,
            $variable,
            $parameters,
            $evaluationOptions,
        );
        $normalizedRight = $this->replaceConstantSubexpressionsWithLiterals(
            $rightText,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
        );
        if ($normalizedLeft !== $leftText || $normalizedRight !== $rightText) {
            return $this->solve(
                $normalizedLeft . '=' . $normalizedRight,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        foreach ([$left, $right] as $candidate) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (!$this->isLogarithmCall($candidate)) {
                continue;
            }
            foreach ($candidate->arguments as $argument) {
                while ($argument instanceof GroupingNode) {
                    $argument = $argument->expression;
                }
                if (
                    $argument instanceof FunctionCallNode
                    && $argument->name === 'exp'
                    && $this->containsVariable($argument, $variable)
                ) {
                    throw new InfiniteSolutionSetException(
                        'A logarithm of a nonconstant complex exponential has infinitely many solutions.',
                    );
                }
                if (
                    $argument instanceof FunctionCallNode
                    && $argument->name === 'gamma'
                    && $this->containsVariable($argument, $variable)
                ) {
                    return new ComplexRationalSolution([], false, 0);
                }
            }
        }
        $rootSolutions = $this->solveRootLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($rootSolutions !== null) {
            return $rootSolutions;
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
        $this->assertSincEquationIsInfinite(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        $absoluteSolutions = $this->solveAbsoluteValueLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($absoluteSolutions !== null) {
            return $absoluteSolutions;
        }
        $matchingAbsoluteSolutions = $this->solveMatchingAbsoluteValueEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingAbsoluteSolutions !== null) {
            return $matchingAbsoluteSolutions;
        }
        $inverseFunctionSolutions = $this->solveInverseFunctionLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($inverseFunctionSolutions !== null) {
            return $inverseFunctionSolutions;
        }
        $integerShapeRegularizedGammaSolutions = $this->solveIntegerShapeRegularizedGammaEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($integerShapeRegularizedGammaSolutions !== null) {
            return $integerShapeRegularizedGammaSolutions;
        }
        $errorFunctionSolutions = $this->solveErrorFunctionLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($errorFunctionSolutions !== null) {
            return $errorFunctionSolutions;
        }
        $dawsonSolutions = $this->solveDawsonLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($dawsonSolutions !== null) {
            return $dawsonSolutions;
        }
        $besselSolutions = $this->solveBesselLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($besselSolutions !== null) {
            return $besselSolutions;
        }
        $inverseErrorSolutions = $this->solveInverseErrorFunctionLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($inverseErrorSolutions !== null) {
            return $inverseErrorSolutions;
        }
        $transcendentalSolutions = $this->solveTranscendentalLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($transcendentalSolutions !== null) {
            return $transcendentalSolutions;
        }
        $exponentialSolutions = $this->solveExponentialLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($exponentialSolutions !== null) {
            return $exponentialSolutions;
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
        $mixedExponentialSolutions = $this->solveMixedExponentialEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($mixedExponentialSolutions !== null) {
            return $mixedExponentialSolutions;
        }
        $scaledExponentialSolutions = $this->solveScaledExponentialEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($scaledExponentialSolutions !== null) {
            return $scaledExponentialSolutions;
        }
        $mixedTangentCotangentSolutions = $this->solveMixedRationalTangentCotangentEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($mixedTangentCotangentSolutions !== null) {
            return $mixedTangentCotangentSolutions;
        }
        $matchingTangentSolutions = $this->solveMatchingRationalTangentEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingTangentSolutions !== null) {
            return $matchingTangentSolutions;
        }
        $mixedReciprocalPeriodicSolutions = $this->solveMixedReciprocalPeriodicEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($mixedReciprocalPeriodicSolutions !== null) {
            return $mixedReciprocalPeriodicSolutions;
        }
        $matchingReciprocalPeriodicSolutions = $this->solveMatchingReciprocalPeriodicEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($matchingReciprocalPeriodicSolutions !== null) {
            return $matchingReciprocalPeriodicSolutions;
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
        $logarithmSolutions = $this->solveLogarithmLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($logarithmSolutions !== null) {
            return $logarithmSolutions;
        }
        $hypotenuseSolutions = $this->solveHypotenuseLevelEquation(
            $left,
            $right,
            $leftText,
            $rightText,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($hypotenuseSolutions !== null) {
            return $hypotenuseSolutions;
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
        $gammaSolutions = $this->solveGammaLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($gammaSolutions !== null) {
            return $gammaSolutions;
        }
        $digammaSolutions = $this->solveDigammaLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($digammaSolutions !== null) {
            return $digammaSolutions;
        }
        $zetaSolutions = $this->solveZetaLevelEquation(
            $left,
            $right,
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions,
        );
        if ($zetaSolutions !== null) {
            return $zetaSolutions;
        }
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        if (
            $this->containsIdenticallyUndefinedRationalOperation($left, $extractor)
            || $this->containsIdenticallyUndefinedRationalOperation($right, $extractor)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }
        $rationalDifference = $extractor->rationalDifference($left, $right);
        if ($rationalDifference === null) {
            if (
                $this->containsVariableFunctionCall($left, $variable)
                || $this->containsVariableFunctionCall($right, $variable)
            ) {
                throw new \InvalidArgumentException(
                    'Complex rational solving does not support variable-dependent function coefficients.',
                );
            }
            $polynomialSolution = (new ComplexPolynomialEquationSolver())->solve(
                $equation,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );

            return new ComplexRationalSolution(
                $polynomialSolution->roots,
                $polynomialSolution->converged,
                $polynomialSolution->iterations,
            );
        }
        [$numerator, $denominator] = $rationalDifference;
        if (\count($numerator) === 1 && $numerator[0] === '0') {
            throw new InfiniteSolutionSetException(
                'The rational equation is an identity on its domain and has infinitely many solutions.',
            );
        }

        $integerExtractor = new IntegerPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
        );
        $rootCounter = new ExactPolynomialRootCounter();
        while (true) {
            $commonFactor = $rootCounter->greatestCommonDivisor($numerator, $denominator);
            if ($commonFactor === null) {
                throw new \DomainException('Common numerator and denominator factors could not be certified.');
            }
            if (\count($commonFactor) < 2) {
                break;
            }
            $reducedNumerator = $rootCounter->dividePolynomialExactly($numerator, $commonFactor);
            if ($reducedNumerator === null || \count($reducedNumerator) >= \count($numerator)) {
                throw new \DomainException('A common denominator factor could not be removed exactly.');
            }
            $numerator = $reducedNumerator;
        }

        $numeratorCoefficients = $integerExtractor->normalizedCoefficients($numerator, true);
        if ($numeratorCoefficients === null) {
            throw new \DomainException('Rational polynomial coefficients could not be normalized.');
        }
        $numeratorRoots = (new ComplexPolynomialSolver())->solve(
            $numeratorCoefficients,
            $solverOptions,
            $numerator,
        );

        return new ComplexRationalSolution(
            $numeratorRoots->roots,
            $numeratorRoots->converged,
            $numeratorRoots->iterations,
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveRootLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isRootCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isRootCall($right)) {
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

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $degree = 2.0;
        if ($wrapper->name === 'root') {
            $degreeCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($wrapper->arguments[1]);
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
            if (
                !\is_finite($degree)
                || $degree === 0.0
                || \floor($degree) !== $degree
                || \abs($degree) > 9_007_199_254_740_991.0
            ) {
                return null;
            }
        } elseif ($wrapper->name === 'cbrt') {
            $degree = 3.0;
        } elseif ($wrapper->name !== 'sqrt') {
            return null;
        }
        if (
            ($wrapper->name === 'sqrt' && $level[0] < 0.0)
            || ($wrapper->name === 'root' && \fmod(\abs($degree), 2.0) === 0.0
                && (($degree > 0.0 && $level[0] < 0.0) || ($degree < 0.0 && $level[0] <= 0.0)))
            || ($wrapper->name === 'root' && $degree < 0.0 && $level[0] === 0.0)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $argument = $wrapper->arguments[0];
        $innerFunction = $argument;
        while ($innerFunction instanceof GroupingNode) {
            $innerFunction = $innerFunction->expression;
        }
        if (
            $innerFunction instanceof FunctionCallNode
            && $innerFunction->name === 'gamma'
            && $this->containsVariable($innerFunction, $variable)
        ) {
            return new ComplexRationalSolution([], false, 0);
        }
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        if ($argumentSource === '') {
            return null;
        }
        if ($level[0] === 0.0 && $degree > 0.0) {
            $transformedEquation = $argumentSource . '=0';
        } else {
            $scale = \json_encode($level[0], \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_string($scale)) {
                return null;
            }
            $argumentTarget = $level[0] ** $degree;
            $targetSource = \is_finite($argumentTarget) && $argumentTarget !== 0.0
                ? \json_encode($argumentTarget, \JSON_PRESERVE_ZERO_FRACTION)
                : false;
            if ($targetSource !== false) {
                $transformedEquation = $argumentSource . '=' . $targetSource;
            } elseif ($wrapper->name === 'root' && $degree < 0.0) {
                $positiveDegree = (int) \abs($degree);
                $transformedEquation = '(' . $argumentSource . ')*(' . $scale . '^' . $positiveDegree . ')=1';
            } else {
                $positiveDegree = (int) \abs($degree);
                $transformedEquation = '(' . $argumentSource . ')/(' . $scale . '^' . $positiveDegree . ')=1';
            }
        }

        try {
            return $this->solve(
                $transformedEquation,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\InvalidArgumentException) {
            return new ComplexRationalSolution([], false, 0);
        }
    }

    private function isRootCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && ((\in_array($node->name, ['sqrt', 'cbrt'], true) && \count($node->arguments) === 1)
                || ($node->name === 'root' && \count($node->arguments) === 2));
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
    ): ?ComplexRationalSolution {
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
        if ($leftSign !== $rightSign) {
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
                    return new ComplexRationalSolution([], false, 0);
                }
            }
            $extractor = new ExactRationalPolynomialExtractor(
                $variable,
                $parameters,
                $solverOptions->maxPolynomialDegree,
                $evaluationOptions,
            );
            if (
                $this->containsIdenticallyUndefinedRationalOperation($leftArgument, $extractor)
                || $this->containsIdenticallyUndefinedRationalOperation($rightArgument, $extractor)
            ) {
                return new ComplexRationalSolution([], true, 0);
            }
            $leftForm = $extractor->rationalFunction($leftArgument);
            $rightForm = $extractor->rationalFunction($rightArgument);
            if ($leftForm === null || $rightForm === null) {
                return null;
            }
            if (
                $this->isZeroRationalPolynomial($leftForm[0])
                && $this->isZeroRationalPolynomial($rightForm[0])
            ) {
                throw new InfiniteSolutionSetException(
                    'Both opposite principal square roots vanish identically on their common domain.',
                );
            }

            return $this->solveCommonRationalZeros($leftForm, $rightForm, $solverOptions);
        }

        try {
            return $this->solve(
                '(' . $leftSource . ')=(' . $rightSource . ')',
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        } catch (\InvalidArgumentException) {
            return new ComplexRationalSolution([], false, 0);
        }
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

    /** @param array<string, int|float> $parameters */
    private function assertSincEquationIsInfinite(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): void {
        $wrapper = null;
        $levelSide = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && \in_array($candidate->name, ['sinc', 'sincpi'], true)
                && \count($candidate->arguments) === 1
                && $evaluationOptions->functions->isBuiltIn($candidate->name)
            ) {
                $wrapper = $candidate;
                $levelSide = $other;
                break;
            }
        }
        if (!$wrapper instanceof FunctionCallNode) {
            return;
        }

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($levelSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return;
        }
        $argumentIsConstant = (new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        ))->isConstantRationalFunction($wrapper->arguments[0]);
        if ($argumentIsConstant === false) {
            throw new InfiniteSolutionSetException(
                'A nonconstant rational argument to sinc or sincpi has infinitely many complex solutions '
                    . 'at every finite real level.',
            );
        }
    }

    /** @param array<string, int|float> $parameters */
    private function solveAbsoluteValueLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isAbsoluteValueCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isAbsoluteValueCall($right)) {
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

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        if ($level[0] < 0.0) {
            return new ComplexRationalSolution([], true, 0);
        }

        $argument = $wrapper->arguments[0];
        if ($level[0] === 0.0) {
            $span = $argument->span();
            $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
            if ($argumentSource === '') {
                return null;
            }

            return $this->solve(
                $argumentSource . '=0',
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        if ($this->containsIdenticallyUndefinedRationalOperation($argument, $extractor)) {
            return new ComplexRationalSolution([], true, 0);
        }
        $argumentIsConstant = $extractor->isConstantRationalFunction($argument);
        if ($argumentIsConstant === false) {
            $argumentSpan = $argument->span();
            $argumentSource = \substr(
                $wrapperSource,
                $argumentSpan->start,
                $argumentSpan->end - $argumentSpan->start,
            );
            $constantSource = $constantSide === $left ? $leftText : $rightText;
            $levelSpan = $constantSide->span();
            $levelSource = \substr($constantSource, $levelSpan->start, $levelSpan->end - $levelSpan->start);
            if ($argumentSource === '' || $levelSource === '') {
                return new ComplexRationalSolution([], false, 0);
            }
            $positive = $this->solve(
                $argumentSource . '=' . $levelSource,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $negative = $this->solve(
                $argumentSource . '=-(' . $levelSource . ')',
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $roots = [];
            foreach ([...$positive->roots, ...$negative->roots] as $root) {
                if (\abs($root->imaginary) > 1.0e-12 * \max(1.0, \abs($root->real))) {
                    continue;
                }
                $duplicate = false;
                foreach ($roots as $existing) {
                    if (\abs($existing->real - $root->real) <= 1.0e-12 * \max(1.0, \abs($root->real))) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $roots[] = new ComplexRoot($root->real, 0.0, $root->residual);
                }
            }
            \usort($roots, static fn (ComplexRoot $a, ComplexRoot $b): int => $a->real <=> $b->real);

            return new ComplexRationalSolution(
                $roots,
                $positive->converged && $negative->converged,
                $positive->iterations + $negative->iterations,
            );
        }
        if ($argumentIsConstant !== true) {
            return new ComplexRationalSolution([], false, 0);
        }

        $argumentValue = $this->constantRationalValue($argument, $extractor);
        $levelValue = $extractor->polynomial($constantSide);
        if ($argumentValue === null || $levelValue === null || \count($levelValue) !== 1) {
            return new ComplexRationalSolution([], false, 0);
        }
        $argumentSquare = $extractor->powerExactFraction($argumentValue, 2);
        $levelSquare = $extractor->powerExactFraction($levelValue[0], 2);
        if ($argumentSquare === null || $levelSquare === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        if ($this->fractionsAreEqual($argumentSquare, $levelSquare)) {
            throw new InfiniteSolutionSetException(
                'The constant complex absolute-value equation holds on its rational domain.',
            );
        }

        return new ComplexRationalSolution([], true, 0);
    }

    private function isAbsoluteValueCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode && $node->name === 'abs' && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingAbsoluteValueEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        if (!$this->isAbsoluteValueCall($left) || !$this->isAbsoluteValueCall($right)) {
            return null;
        }
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (!$left instanceof FunctionCallNode || !$right instanceof FunctionCallNode) {
            return null;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftArgument = $left->arguments[0];
        $rightArgument = $right->arguments[0];
        if (
            $this->containsIdenticallyUndefinedRationalOperation($leftArgument, $extractor)
            || $this->containsIdenticallyUndefinedRationalOperation($rightArgument, $extractor)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $leftForm = $extractor->rationalFunction($leftArgument);
        $rightForm = $extractor->rationalFunction($rightArgument);
        if ($leftForm === null || $rightForm === null) {
            return null;
        }
        $leftIsZero = $this->isZeroRationalPolynomial($leftForm[0]);
        $rightIsZero = $this->isZeroRationalPolynomial($rightForm[0]);
        if ($leftIsZero && $rightIsZero) {
            throw new InfiniteSolutionSetException(
                'Both modulus arguments vanish identically, so the equation has infinitely many solutions.',
            );
        }

        $ratio = $extractor->rationalFunctionRatio($leftArgument, $rightArgument);
        if ($ratio === false) {
            if (!$leftIsZero && !$rightIsZero) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant rational-function ratio has infinitely many preimages of the unit circle.',
                );
            }

            return $this->solveCommonRationalZeros(
                $leftForm,
                $rightForm,
                $solverOptions,
            );
        }
        if ($ratio === null) {
            return null;
        }
        $ratioSquare = $extractor->powerExactFraction($ratio, 2);
        if ($ratioSquare === null) {
            return null;
        }
        if ($this->fractionsAreEqual($ratioSquare, ['1', '1'])) {
            throw new InfiniteSolutionSetException(
                'A constant unit-modulus rational-function ratio makes both moduli equal on their common domain.',
            );
        }

        return $this->solveCommonRationalZeros(
            $leftForm,
            $rightForm,
            $solverOptions,
        );
    }

    /**
     * @param array{list<array{string, string}>, list<array{string, string}>} $leftForm
     * @param array{list<array{string, string}>, list<array{string, string}>} $rightForm
     */
    private function solveCommonRationalZeros(
        array $leftForm,
        array $rightForm,
        SolverOptions $solverOptions,
    ): ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree);
        $leftNumerator = $extractor->integerCoefficients($leftForm[0]);
        $rightNumerator = $extractor->integerCoefficients($rightForm[0]);
        $leftDenominator = $extractor->integerCoefficients($leftForm[1]);
        $rightDenominator = $extractor->integerCoefficients($rightForm[1]);
        if (
            $leftNumerator === null || $rightNumerator === null
            || $leftDenominator === null || $rightDenominator === null
        ) {
            return new ComplexRationalSolution([], false, 0);
        }

        $rootCounter = new ExactPolynomialRootCounter();
        $commonNumerator = $rootCounter->greatestCommonDivisor($leftNumerator, $rightNumerator);
        if ($commonNumerator === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        foreach ([$leftDenominator, $rightDenominator] as $domainDenominator) {
            $domainFactor = $rootCounter->greatestCommonDivisor($commonNumerator, $domainDenominator);
            if ($domainFactor === null) {
                return new ComplexRationalSolution([], false, 0);
            }
            if (\count($domainFactor) > 1) {
                $reduced = $rootCounter->dividePolynomialExactly($commonNumerator, $domainFactor);
                if ($reduced === null) {
                    return new ComplexRationalSolution([], false, 0);
                }
                $commonNumerator = $reduced;
            }
        }
        if (\count($commonNumerator) === 1) {
            return new ComplexRationalSolution([], true, 0);
        }

        $coefficients = (new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree))
            ->normalizedCoefficients($commonNumerator, true);
        if ($coefficients === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $solution = (new ComplexPolynomialSolver())->solve($coefficients, $solverOptions, $commonNumerator);

        return new ComplexRationalSolution($solution->roots, $solution->converged, $solution->iterations);
    }

    /** @param list<array{string, string}> $polynomial */
    private function isZeroRationalPolynomial(array $polynomial): bool
    {
        foreach ($polynomial as [$numerator]) {
            if ($numerator !== '0') {
                return false;
            }
        }

        return true;
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
    ): ?ComplexRationalSolution {
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

        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $levelCoefficients = $extractor->extract($constantSide);
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
            case 'gammaincinv':
            case 'gammainccinv':
                $shape = $extractor->extract($wrapper->arguments[0]);
                if ($shape === null || \count($shape) !== 1 || !\is_finite($shape[0]) || $shape[0] <= 0.0) {
                    return null;
                }
                if ($level < 0.0) {
                    $noSolutions = true;
                    break;
                }
                $argumentIndex = 1;
                $argumentTarget = $functionName === 'gammaincinv'
                    ? SpecialFunctions::regularizedGammaP($shape[0], $level)
                    : SpecialFunctions::regularizedGammaQ($shape[0], $level);
                break;
            case 'betaincinv':
            case 'betainccinv':
                $firstShape = $extractor->extract($wrapper->arguments[0]);
                $secondShape = $extractor->extract($wrapper->arguments[1]);
                if (
                    $firstShape === null
                    || \count($firstShape) !== 1
                    || !\is_finite($firstShape[0])
                    || $firstShape[0] <= 0.0
                    || $secondShape === null
                    || \count($secondShape) !== 1
                    || !\is_finite($secondShape[0])
                    || $secondShape[0] <= 0.0
                ) {
                    return null;
                }
                if ($level < 0.0 || $level > 1.0) {
                    $noSolutions = true;
                    break;
                }
                $argumentIndex = 2;
                $argumentTarget = $functionName === 'betaincinv'
                    ? SpecialFunctions::regularizedBeta($firstShape[0], $secondShape[0], $level)
                    : SpecialFunctions::regularizedBetaComplement($firstShape[0], $secondShape[0], $level);
                break;
            case 'lambertw':
                if (\count($wrapper->arguments) === 2) {
                    $branchCoefficients = $extractor->extract($wrapper->arguments[1]);
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
                    return new ComplexRationalSolution([], false, 0);
                }
                break;
        }
        if ($noSolutions) {
            return new ComplexRationalSolution([], true, 0);
        }
        if (!\is_finite($argumentTarget)) {
            return new ComplexRationalSolution([], false, 0);
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
            return new ComplexRationalSolution([], false, 0);
        }
        if (\in_array($functionName, ['asech', 'acsch'], true) && $argumentTarget === 0.0) {
            return new ComplexRationalSolution([], false, 0);
        }
        if (
            ($functionName === 'atanh' && \abs($argumentTarget) >= 1.0)
            || ($functionName === 'acoth' && \abs($argumentTarget) <= 1.0)
            || ($functionName === 'gammaincinv' && $argumentTarget >= 1.0)
            || ($functionName === 'gammainccinv' && $argumentTarget <= 0.0)
        ) {
            return new ComplexRationalSolution([], false, 0);
        }

        $argument = $wrapper->arguments[$argumentIndex];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $argumentSource = $this->replaceNamedConstantsWithNumericLiterals(
            $argumentSource,
            $parameters,
            $evaluationOptions,
        );
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
                'asec', 'acsc', 'asech', 'acsch', 'acoth',
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
    private function solveIntegerShapeRegularizedGammaEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
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
        if (
            $shape === null
            || \count($shape) !== 1
            || !\is_finite($shape[0])
            || $shape[0] <= 0.0
            || $shape[0] !== \floor($shape[0])
            || $level === null
            || \count($level) !== 1
            || !\is_finite($level[0])
        ) {
            return null;
        }

        $argument = $wrapper->arguments[1];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            $omittedLevel = $wrapper->name === 'gammainc' ? 1.0 : 0.0;
            if ($level[0] === $omittedLevel) {
                if ($shape[0] === 1.0) {
                    return new ComplexRationalSolution([], true, 0);
                }

                return $this->solveIntegerShapeGammaExceptionalLevel(
                    $argument,
                    (int) $shape[0],
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }

            throw new InfiniteSolutionSetException(
                \sprintf(
                    'The complex equation %s(a, R(x)) = c has infinitely many solutions at this level.',
                    $wrapper->name,
                ),
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $rationalExtractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                continue;
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant regularized-gamma equation holds throughout its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveIntegerShapeGammaExceptionalLevel(
        Node $argument,
        int $shape,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexRationalSolution {
        if ($shape > $solverOptions->maxPolynomialDegree + 1) {
            return new ComplexRationalSolution([], false, 0);
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
        $composition = $extractor->rationalPolynomialInRationalFunction(
            $argument,
            $coefficients,
            [$argument],
        );
        if ($composition === null) {
            return new ComplexRationalSolution([], false, 0);
        }

        [$numerator, $denominator] = $composition;
        $rootCounter = new ExactPolynomialRootCounter();
        $commonFactor = $rootCounter->greatestCommonDivisor($numerator, $denominator);
        if ($commonFactor === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        if (\count($commonFactor) > 1) {
            $numerator = $rootCounter->dividePolynomialExactly($numerator, $commonFactor);
            if ($numerator === null) {
                return new ComplexRationalSolution([], false, 0);
            }
        }
        if (\count($numerator) <= 1) {
            return new ComplexRationalSolution([], true, 0);
        }

        $normalized = $integerExtractor->normalizedCoefficients($numerator, true);
        if ($normalized === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $algebraic = (new ComplexPolynomialSolver())->solve($normalized, $solverOptions, $numerator);

        return new ComplexRationalSolution($algebraic->roots, $algebraic->converged, $algebraic->iterations);
    }

    /** @param array<string, int|float> $parameters */
    private function solveErrorFunctionLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        if ($this->isErrorFunctionCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
        } elseif ($this->isErrorFunctionCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $argument = $wrapper->arguments[0];
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $extractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            $form = $extractor->rationalPolynomialForm($argument);
            if ($form === null) {
                return new ComplexRationalSolution([], false, 0);
            }
            try {
                $target = match ($wrapper->name) {
                    'erf' => SpecialFunctions::inverseErf($level[0]),
                    'erfc' => SpecialFunctions::inverseErfc($level[0]),
                    'erfi' => SpecialFunctions::inverseErfi($level[0]),
                    'erfcx' => SpecialFunctions::inverseErfcx($level[0]),
                    default => throw new \LogicException('Unsupported error function.'),
                };
            } catch (\DomainException) {
                return new ComplexRationalSolution([], true, 0);
            }

            return $this->solveRationalArgumentTargets(
                $argument,
                $wrapper,
                $left,
                $leftText,
                $rightText,
                [$target],
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        if ($isConstant !== true) {
            return null;
        }
        if (
            ($wrapper->name === 'erf' && \abs($level[0]) >= 1.0)
            || ($wrapper->name === 'erfc' && ($level[0] <= 0.0 || $level[0] >= 2.0))
            || ($wrapper->name === 'erfcx' && $level[0] <= 0.0)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $form = $extractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                continue;
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    private function isErrorFunctionCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && \in_array($node->name, ['erf', 'erfc', 'erfi', 'erfcx'], true)
            && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveDawsonLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        if ($this->isDawsonCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
        } elseif ($this->isDawsonCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
        }
        while ($wrapper instanceof GroupingNode) {
            $wrapper = $wrapper->expression;
        }
        if (!$wrapper instanceof FunctionCallNode || $constantSide === null) {
            return null;
        }

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $argument = $wrapper->arguments[0];
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $extractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            if ($extractor->rationalPolynomialForm($argument) === null) {
                return new ComplexRationalSolution([], false, 0);
            }

            return $this->solveRationalArgumentTargets(
                $argument,
                $wrapper,
                $left,
                $leftText,
                $rightText,
                SpecialFunctions::inverseDawson($level[0]),
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $extractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                continue;
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    private function isDawsonCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode && $node->name === 'dawson' && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveBesselLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        if ($this->isBesselCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
        } elseif ($this->isBesselCall($right)) {
            $wrapper = $right;
            $constantSide = $left;
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
        $orderCoefficients = $extractor->extract($wrapper->arguments[0]);
        $levelCoefficients = $extractor->extract($constantSide);
        if (
            $orderCoefficients === null
            || \count($orderCoefficients) !== 1
            || !\is_finite($orderCoefficients[0])
            || $orderCoefficients[0] !== \floor($orderCoefficients[0])
            || \abs($orderCoefficients[0]) > SpecialFunctions::MAX_BESSEL_ORDER
            || $levelCoefficients === null
            || \count($levelCoefficients) !== 1
            || !\is_finite($levelCoefficients[0])
        ) {
            return null;
        }
        $order = (int) $orderCoefficients[0];
        $argument = $wrapper->arguments[1];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            if (
                $this->containsVariableFunctionCall($argument, $variable)
                || $rationalExtractor->rationalPolynomialForm($argument) === null
            ) {
                return new ComplexRationalSolution([], false, 0);
            }

            return $this->solveRationalArgumentTargets(
                $argument,
                $wrapper,
                $left,
                $leftText,
                $rightText,
                SpecialFunctions::inverseBesselI($order, $levelCoefficients[0]),
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $rationalExtractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                continue;
            }
            if ($value === $levelCoefficients[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        $identicallyZeroArgument = !\array_filter(
            $form[0],
            static fn (array $coefficient): bool => $coefficient[0] !== '0',
        );

        return new ComplexRationalSolution(
            [],
            $wrapper->name === 'bessely' && $identicallyZeroArgument,
            0,
        );
    }

    /**
     * Invert a supported real branch first, then solve the rational argument
     * at each resulting level while preserving its original denominator holes.
     *
     * @param list<float> $targets
     * @param array<string, int|float> $parameters
     */
    private function solveRationalArgumentTargets(
        Node $argument,
        FunctionCallNode $wrapper,
        Node $left,
        string $leftText,
        string $rightText,
        array $targets,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexRationalSolution {
        $wrapperStart = $wrapper->span()->start;
        $onLeft = \substr($leftText, $wrapperStart, \strlen($wrapper->name)) === $wrapper->name;
        $source = $onLeft ? $leftText : $rightText;
        $span = $argument->span();
        $argumentSource = \substr($source, $span->start, $span->end - $span->start);
        if ($argumentSource === '') {
            return new ComplexRationalSolution([], false, 0);
        }

        $roots = [];
        $converged = true;
        $iterations = 0;
        foreach ($targets as $target) {
            if (!\is_finite($target)) {
                $converged = false;
                continue;
            }
            $targetSource = \json_encode($target, \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_string($targetSource)) {
                $converged = false;
                continue;
            }
            $solution = $this->solve(
                $argumentSource . '=' . $targetSource,
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            $converged = $converged && $solution->converged;
            $iterations += $solution->iterations;
            foreach ($solution->roots as $root) {
                if (\abs($root->imaginary) > 1.0e-12 * \max(1.0, \abs($root->real))) {
                    continue;
                }
                $duplicate = false;
                foreach ($roots as $existing) {
                    if (
                        \hypot($existing->real - $root->real, $existing->imaginary - $root->imaginary)
                        <= 1.0e-12 * \max(1.0, \hypot($root->real, $root->imaginary))
                    ) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $roots[] = $root;
                }
            }
        }
        \usort($roots, static fn (ComplexRoot $a, ComplexRoot $b): int =>
            ($a->real <=> $b->real) ?: ($a->imaginary <=> $b->imaginary));

        return new ComplexRationalSolution($roots, $converged, $iterations);
    }

    private function isBesselCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && \in_array($node->name, ['besselj', 'besseli', 'bessely'], true)
            && \count($node->arguments) === 2;
    }

    /** @param array<string, int|float> $parameters */
    private function solveInverseErrorFunctionLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isInverseErrorFunctionCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isInverseErrorFunctionCall($right)) {
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

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }

        $target = $wrapper->name === 'erfinv'
            ? SpecialFunctions::erf($level[0])
            : SpecialFunctions::erfc($level[0]);
        if (!\is_finite($target)) {
            return new ComplexRationalSolution([], false, 0);
        }
        if (
            ($wrapper->name === 'erfinv' && \abs($target) >= 1.0)
            || ($wrapper->name === 'erfcinv' && ($target <= 0.0 || $target >= 2.0))
        ) {
            return new ComplexRationalSolution([], false, 0);
        }

        $argument = $wrapper->arguments[0];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $targetSource = \json_encode($target, \JSON_PRESERVE_ZERO_FRACTION);
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

    private function isInverseErrorFunctionCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && \in_array($node->name, ['erfinv', 'erfcinv'], true)
            && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveExponentialLevelEquation(
        Node $left,
        Node $right,
        string $leftText,
        string $rightText,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $constantSide = null;
        $wrapperSource = '';
        if ($this->isExponentialCall($left)) {
            $wrapper = $left;
            $constantSide = $right;
            $wrapperSource = $leftText;
        } elseif ($this->isExponentialCall($right)) {
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

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        if (
            ($wrapper->name === 'exp' && $level[0] == 0.0)
            || ($wrapper->name === 'expm1' && $level[0] == -1.0)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $argument = $wrapper->arguments[0];
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $argumentIsConstant = $extractor->isConstantRationalFunction($argument);
        if ($argumentIsConstant === null) {
            return null;
        }
        if (!$argumentIsConstant) {
            throw new InfiniteSolutionSetException(
                'A nonconstant rational argument to a complex exponential has infinitely many solutions '
                . 'for every non-omitted level.',
            );
        }
        if (
            ($wrapper->name === 'exp' && $level[0] < 0.0)
            || ($wrapper->name === 'expm1' && $level[0] < -1.0)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $argumentTarget = $wrapper->name === 'exp'
            ? \log($level[0])
            : \log1p($level[0]);
        if (!\is_finite($argumentTarget)) {
            return null;
        }
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

    private function isExponentialCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        return $node instanceof FunctionCallNode
            && \in_array($node->name, ['exp', 'expm1'], true)
            && \count($node->arguments) === 1;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingExponentialEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (
            !$this->isExponentialCall($left)
            || !$this->isExponentialCall($right)
            || !$left instanceof FunctionCallNode
            || !$right instanceof FunctionCallNode
            || $left->name !== $right->name
        ) {
            return null;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $difference = $extractor->rationalDifference($left->arguments[0], $right->arguments[0]);
        if ($difference === null) {
            return null;
        }
        [$numerator, $denominator] = $difference;
        $isZero = true;
        foreach ($numerator as $coefficient) {
            if ($coefficient !== '0') {
                $isZero = false;
                break;
            }
        }
        if ($isZero) {
            throw new InfiniteSolutionSetException(
                'The matching exponential equation is an identity on its domain.',
            );
        }

        $isConstant = $this->isConstantPolynomialRatio($numerator, $denominator);
        if ($isConstant === null) {
            return null;
        }
        if ($isConstant) {
            return new ComplexRationalSolution([], true, 0);
        }

        throw new InfiniteSolutionSetException(
            'A nonconstant rational phase difference gives infinitely many complex solutions '
                . 'to matching exponential equations.',
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveMixedExponentialEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftTerm = $this->extractScaledExponentialTerm($left, $extractor);
        $rightTerm = $this->extractScaledExponentialTerm($right, $extractor);
        if ($leftTerm === null || $rightTerm === null) {
            return null;
        }
        [$leftCall, $leftAmplitude] = $leftTerm;
        [$rightCall, $rightAmplitude] = $rightTerm;
        if ($leftCall->name === 'exp' && $rightCall->name === 'expm1') {
            [$exponential, $exponentialAmplitude] = [$leftCall, $leftAmplitude];
            [$exponentialMinusOne, $expm1Amplitude] = [$rightCall, $rightAmplitude];
        } elseif ($rightCall->name === 'exp' && $leftCall->name === 'expm1') {
            [$exponential, $exponentialAmplitude] = [$rightCall, $rightAmplitude];
            [$exponentialMinusOne, $expm1Amplitude] = [$leftCall, $leftAmplitude];
        } else {
            return null;
        }
        $difference = $extractor->rationalDifference(
            $exponential->arguments[0],
            $exponentialMinusOne->arguments[0],
        );
        if ($difference === null) {
            return null;
        }
        [$numerator, $denominator] = $difference;
        $isZero = true;
        foreach ($numerator as $coefficient) {
            if ($coefficient !== '0') {
                $isZero = false;
                break;
            }
        }
        if ($isZero) {
            if ($this->fractionsAreEqual($exponentialAmplitude, $expm1Amplitude)) {
                return new ComplexRationalSolution([], true, 0);
            }
            if ($extractor->isConstantRationalFunction($exponentialMinusOne->arguments[0]) === false) {
                throw new InfiniteSolutionSetException(
                    'Unequal constant multiples of exp and expm1 at the same nonconstant phase '
                        . 'have infinitely many complex solutions.',
                );
            }

            return null;
        }

        if ($extractor->isConstantRationalFunction($exponentialMinusOne->arguments[0]) === false) {
            $phaseRatio = $extractor->rationalFunctionRatio(
                $exponential->arguments[0],
                $exponentialMinusOne->arguments[0],
            );
            if (\is_array($phaseRatio)) {
                $phaseNumerator = \filter_var($phaseRatio[0], FILTER_VALIDATE_INT);
                $phaseDenominator = \filter_var($phaseRatio[1], FILTER_VALIDATE_INT);
                if (
                    \is_int($phaseNumerator)
                    && \is_int($phaseDenominator)
                    && $phaseDenominator > 0
                    && !($phaseNumerator === 1 && $phaseDenominator === 1)
                ) {
                    if ($phaseNumerator === 0) {
                        $constantTerm = $extractor->addExactFractions(
                            $exponentialAmplitude,
                            $expm1Amplitude,
                        );
                        if ($constantTerm === null) {
                            return null;
                        }
                        if ($constantTerm[0] === '0') {
                            return new ComplexRationalSolution([], true, 0);
                        }
                    }

                    throw new InfiniteSolutionSetException(
                        'A rationally proportional nonconstant phase pair reduces the mixed '
                            . 'exponential equation to a polynomial with nonzero roots.',
                    );
                }
            }
        }

        $phaseDifferenceIsConstant = $this->isConstantPolynomialRatio($numerator, $denominator);
        if ($phaseDifferenceIsConstant === true) {
            if (
                $extractor->isConstantRationalFunction($exponentialMinusOne->arguments[0]) !== false
                || !$this->isExactRationalExpression(
                    $exponential->arguments[0],
                    $variable,
                    $parameters,
                    $evaluationOptions->limits->maxExponentMagnitude,
                )
                || !$this->isExactRationalExpression(
                    $exponentialMinusOne->arguments[0],
                    $variable,
                    $parameters,
                    $evaluationOptions->limits->maxExponentMagnitude,
                )
            ) {
                return null;
            }

            throw new InfiniteSolutionSetException(
                'A nonzero constant phase difference and a nonconstant rational expm1 phase '
                    . 'give infinitely many complex solutions.',
            );
        }
        if ($phaseDifferenceIsConstant === false) {
            $phaseSum = $extractor->rationalSum(
                $exponential->arguments[0],
                $exponentialMinusOne->arguments[0],
            );
            if (
                $phaseSum !== null
                && $this->isConstantPolynomialRatio($phaseSum[0], $phaseSum[1]) === true
                && $extractor->isConstantRationalFunction($exponential->arguments[0]) === false
            ) {
                throw new InfiniteSolutionSetException(
                    'A constant phase sum reduces the mixed exponential equation to a quadratic '
                        . 'with nonzero exponential roots.',
                );
            }
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function solveScaledExponentialEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftTerm = $this->extractScaledExponentialTerm($left, $extractor);
        $rightTerm = $this->extractScaledExponentialTerm($right, $extractor);
        if ($leftTerm === null || $rightTerm === null) {
            return null;
        }

        [$leftCall, $leftAmplitude] = $leftTerm;
        [$rightCall, $rightAmplitude] = $rightTerm;
        $leftPhaseIsExactConstant =
            $extractor->isConstantRationalFunction($leftCall->arguments[0]) === true
            && $this->isExactRationalExpression(
                $leftCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            );
        $rightPhaseIsExactConstant =
            $extractor->isConstantRationalFunction($rightCall->arguments[0]) === true
            && $this->isExactRationalExpression(
                $rightCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            );
        if ($leftCall->name !== $rightCall->name) {
            return $leftPhaseIsExactConstant && $rightPhaseIsExactConstant
                ? new ComplexRationalSolution([], true, 0)
                : null;
        }
        $difference = $extractor->rationalDifference($leftCall->arguments[0], $rightCall->arguments[0]);
        if ($difference === null) {
            return null;
        }
        [$numerator, $denominator] = $difference;
        $isZero = true;
        foreach ($numerator as $coefficient) {
            if ($coefficient !== '0') {
                $isZero = false;
                break;
            }
        }
        if ($isZero) {
            if ($this->fractionsAreEqual($leftAmplitude, $rightAmplitude)) {
                throw new InfiniteSolutionSetException(
                    'The scaled exponential equation is an identity on its domain.',
                );
            }
            if (
                $leftCall->name === 'expm1'
                && $this->isZeroRationalFunction($rightCall->arguments[0], $extractor)
            ) {
                throw new InfiniteSolutionSetException(
                    'Both scaled expm1 sides vanish identically at zero phase.',
                );
            }
            if (
                $leftCall->name === 'expm1'
                && $extractor->isConstantRationalFunction($rightCall->arguments[0]) === false
            ) {
                throw new InfiniteSolutionSetException(
                    'Unequal nonzero multiples of the same nonconstant expm1 phase equal '
                        . 'infinitely often over the complex numbers.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        $isConstant = $this->isConstantPolynomialRatio($numerator, $denominator);
        if ($isConstant === false) {
            if (
                $leftCall->name === 'exp'
                || $this->fractionsAreEqual($leftAmplitude, $rightAmplitude)
            ) {
                throw new InfiniteSolutionSetException(
                    'Nonzero constant multiples of matching exponential functions with a '
                        . 'nonconstant rational phase difference have infinitely many complex solutions.',
                );
            }
            if ($leftCall->name === 'expm1') {
                if ($extractor->isConstantRationalFunction($rightCall->arguments[0]) === false) {
                    $phaseRatio = $extractor->rationalFunctionRatio(
                        $leftCall->arguments[0],
                        $rightCall->arguments[0],
                    );
                    if (\is_array($phaseRatio)) {
                        throw new InfiniteSolutionSetException(
                            'Rationally proportional nonconstant expm1 phases vanish together at '
                                . 'infinitely many period levels.',
                        );
                    }
                }
                $phaseSum = $extractor->rationalSum(
                    $leftCall->arguments[0],
                    $rightCall->arguments[0],
                );
                $phaseSumIsConstant = $phaseSum === null
                    ? null
                    : $this->isConstantPolynomialRatio($phaseSum[0], $phaseSum[1]);
                if (
                    $phaseSumIsConstant === true
                    && $extractor->isConstantRationalFunction($leftCall->arguments[0]) === false
                ) {
                    throw new InfiniteSolutionSetException(
                        'Expm1 phases with constant sum reduce to a quadratic in the exponential '
                            . 'of their nonconstant rational phase.',
                    );
                }
            }

            return null;
        }
        if ($isConstant === true) {
            if (
                $leftCall->name === 'expm1'
                && !$this->fractionsAreEqual($leftAmplitude, $rightAmplitude)
                && $extractor->isConstantRationalFunction($rightCall->arguments[0]) === false
                && $this->isExactRationalExpression(
                    $leftCall->arguments[0],
                    $variable,
                    $parameters,
                    $evaluationOptions->limits->maxExponentMagnitude,
                )
                && $this->isExactRationalExpression(
                    $rightCall->arguments[0],
                    $variable,
                    $parameters,
                    $evaluationOptions->limits->maxExponentMagnitude,
                )
            ) {
                throw new InfiniteSolutionSetException(
                    'Unequal nonzero multiples of expm1 with a constant phase offset and '
                        . 'nonconstant rational phase have infinitely many complex solutions.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return null;
    }

    private function isZeroRationalFunction(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
    ): bool {
        $form = $extractor->rationalPolynomialForm($node);
        if ($form === null) {
            return false;
        }
        foreach ($form[0] as [$coefficient]) {
            if ($coefficient !== '0') {
                return false;
            }
        }

        return true;
    }

    /** @return array{FunctionCallNode, array{string, string}}|null */
    private function extractScaledExponentialTerm(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
    ): ?array {
        return $this->extractScaledFunctionTerm($node, $extractor, ['exp', 'expm1']);
    }

    /** @param list<string> $functionNames
     *  @return array{FunctionCallNode, array{string, string}}|null
     */
    private function extractScaledFunctionTerm(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
        array $functionNames,
    ): ?array {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            $node instanceof FunctionCallNode
            && \in_array($node->name, $functionNames, true)
            && \count($node->arguments) === 1
        ) {
            return [$node, ['1', '1']];
        }
        if ($node instanceof UnaryOperationNode) {
            $term = $this->extractScaledFunctionTerm($node->operand, $extractor, $functionNames);
            if ($term === null || $node->operator === UnaryOperator::Plus) {
                return $term;
            }
            $term[1][0] = $this->integerArithmetic()->negateIntegerValue($term[1][0]);

            return $term;
        }
        if (!$node instanceof BinaryOperationNode) {
            return null;
        }
        if ($node->operator === BinaryOperator::Multiply) {
            $factor = $this->nonzeroConstantValue($node->left, $extractor);
            $termNode = $node->right;
            if ($factor === null) {
                $factor = $this->nonzeroConstantValue($node->right, $extractor);
                $termNode = $node->left;
            }
            if ($factor === null) {
                return null;
            }
            $term = $this->extractScaledFunctionTerm($termNode, $extractor, $functionNames);
            if ($term === null) {
                return null;
            }
            $term[1] = $extractor->multiplyExactFractions($factor, $term[1]);

            return $term[1] === null ? null : $term;
        }
        if ($node->operator === BinaryOperator::Divide) {
            $factor = $this->nonzeroConstantValue($node->right, $extractor);
            $term = $factor === null
                ? null
                : $this->extractScaledFunctionTerm($node->left, $extractor, $functionNames);
            if ($term === null) {
                return null;
            }
            $inverse = $extractor->powerExactFraction($factor, -1);
            if ($inverse === null) {
                return null;
            }
            $term[1] = $extractor->multiplyExactFractions($inverse, $term[1]);

            return $term[1] === null ? null : $term;
        }

        return null;
    }

    /** @param array{string, string} $left
     *  @param array{string, string} $right
     */
    private function fractionsAreEqual(array $left, array $right): bool
    {
        $arithmetic = $this->integerArithmetic();

        return $arithmetic->multiplyIntegerValues($left[0], $right[1])
            === $arithmetic->multiplyIntegerValues($right[0], $left[1]);
    }

    /** @return array{string, string}|null */
    private function nonzeroConstantValue(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
    ): ?array {
        $value = $this->constantRationalValue($node, $extractor);

        return $value === null || $value[0] === '0' ? null : $value;
    }

    /** @return array{string, string}|null */
    private function constantRationalValue(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
    ): ?array {
        if ($extractor->isConstantRationalFunction($node) !== true) {
            return null;
        }
        $form = $extractor->rationalPolynomialForm($node);
        if ($form === null) {
            return null;
        }
        foreach ($form[1] as $degree => $denominatorCoefficient) {
            if ($denominatorCoefficient[0] === '0') {
                continue;
            }
            $numeratorCoefficient = $form[0][$degree] ?? ['0', '1'];
            $inverse = $extractor->powerExactFraction($denominatorCoefficient, -1);

            return $inverse === null
                ? null
                : $extractor->multiplyExactFractions($numeratorCoefficient, $inverse);
        }

        return null;
    }

    private function integerArithmetic(): IntegerPolynomialExtractor
    {
        return new IntegerPolynomialExtractor('', [], 1);
    }

    /** @param list<string> $numerator
     *  @param list<string> $denominator
     */
    private function isConstantPolynomialRatio(array $numerator, array $denominator): ?bool
    {
        while (\count($numerator) > 1 && $numerator[\array_key_last($numerator)] === '0') {
            \array_pop($numerator);
        }
        while (\count($denominator) > 1 && $denominator[\array_key_last($denominator)] === '0') {
            \array_pop($denominator);
        }
        $referenceDegree = null;
        foreach ($denominator as $degree => $coefficient) {
            if ($coefficient !== '0') {
                $referenceDegree = $degree;
                break;
            }
        }
        if ($referenceDegree === null) {
            return null;
        }

        $arithmetic = new IntegerPolynomialExtractor('', [], 1);
        $referenceNumerator = $numerator[$referenceDegree] ?? '0';
        $referenceDenominator = $denominator[$referenceDegree];
        for ($degree = 0, $length = \max(\count($numerator), \count($denominator)); $degree < $length; ++$degree) {
            if (
                $arithmetic->multiplyIntegerValues($numerator[$degree] ?? '0', $referenceDenominator)
                !== $arithmetic->multiplyIntegerValues(
                    $denominator[$degree] ?? '0',
                    $referenceNumerator,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMixedRationalTangentCotangentEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $functionNames = ['tan', 'cot', 'tanh', 'coth'];
        $leftTerm = $this->extractScaledFunctionTerm($left, $extractor, $functionNames);
        $rightTerm = $this->extractScaledFunctionTerm($right, $extractor, $functionNames);
        if (
            $leftTerm === null
            || $rightTerm === null
            || !$evaluationOptions->functions->isBuiltIn($leftTerm[0]->name)
            || !$evaluationOptions->functions->isBuiltIn($rightTerm[0]->name)
        ) {
            return null;
        }

        $leftIsTrig = \in_array($leftTerm[0]->name, ['tan', 'cot'], true);
        $rightIsTrig = \in_array($rightTerm[0]->name, ['tan', 'cot'], true);
        $leftIsHyperbolic = \in_array($leftTerm[0]->name, ['tanh', 'coth'], true);
        $rightIsHyperbolic = \in_array($rightTerm[0]->name, ['tanh', 'coth'], true);
        $leftIsTangent = \in_array($leftTerm[0]->name, ['tan', 'tanh'], true);
        $rightIsTangent = \in_array($rightTerm[0]->name, ['tan', 'tanh'], true);
        if (
            !($leftIsTrig && $rightIsTrig)
            && !($leftIsHyperbolic && $rightIsHyperbolic)
        ) {
            return null;
        }
        if ($leftIsTangent === $rightIsTangent) {
            return null;
        }

        $phaseDifference = $extractor->rationalDifference(
            $leftTerm[0]->arguments[0],
            $rightTerm[0]->arguments[0],
        );
        $phaseSum = $extractor->rationalSum(
            $leftTerm[0]->arguments[0],
            $rightTerm[0]->arguments[0],
        );
        if ($phaseDifference === null || $phaseSum === null) {
            return null;
        }
        $samePhase = \count($phaseDifference[0]) === 1 && $phaseDifference[0][0] === '0';
        $oppositePhase = \count($phaseSum[0]) === 1 && $phaseSum[0][0] === '0';
        if (!$samePhase && !$oppositePhase) {
            return null;
        }

        $tangentScale = $leftIsTangent ? $leftTerm[1] : $rightTerm[1];
        $cotangentScale = $leftIsTangent ? $rightTerm[1] : $leftTerm[1];
        $inverseTangentScale = $extractor->powerExactFraction($tangentScale, -1);
        $scaleRatio = $inverseTangentScale === null
            ? null
            : $extractor->multiplyExactFractions($cotangentScale, $inverseTangentScale);
        if ($scaleRatio === null) {
            return null;
        }
        $target = $extractor->multiplyExactFractions(
            $oppositePhase ? ['-1', '1'] : ['1', '1'],
            $scaleRatio,
        );
        if ($target === null) {
            return null;
        }
        if (
            $leftIsHyperbolic
            && (
                $this->fractionsAreEqual($target, ['1', '1'])
                || $this->fractionsAreEqual($target, ['-1', '1'])
            )
        ) {
            return new ComplexRationalSolution([], true, 0);
        }
        if ($leftIsTrig && $this->fractionsAreEqual($target, ['-1', '1'])) {
            return new ComplexRationalSolution([], true, 0);
        }

        $phase = $leftTerm[0]->arguments[0];
        $phaseIsConstant = $extractor->isConstantRationalFunction($phase);
        if ($phaseIsConstant === false) {
            throw new InfiniteSolutionSetException(
                'A nonconstant rational phase gives infinitely many mixed tangent/cotangent roots.',
            );
        }
        if (
            $phaseIsConstant === true
            && $this->isExactRationalExpression(
                $phase,
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            )
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingRationalTangentEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        while ($left instanceof GroupingNode) {
            $left = $left->expression;
        }
        while ($right instanceof GroupingNode) {
            $right = $right->expression;
        }
        if (
            !$left instanceof FunctionCallNode || !$right instanceof FunctionCallNode
            || $left->name !== $right->name
            || !\in_array($left->name, ['tan', 'cot', 'tanh', 'coth', 'csch'], true)
            || \count($left->arguments) !== 1 || \count($right->arguments) !== 1
            || !$evaluationOptions->functions->isBuiltIn($left->name)
        ) {
            return null;
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $leftPhaseIsConstant = $extractor->isConstantRationalFunction($left->arguments[0]);
        $rightPhaseIsConstant = $extractor->isConstantRationalFunction($right->arguments[0]);
        if ($leftPhaseIsConstant === null || $rightPhaseIsConstant === null) {
            return null;
        }
        if (\in_array($left->name, ['cot', 'coth', 'csch'], true)) {
            foreach (
                [
                    [$leftPhaseIsConstant, $left->arguments[0]],
                    [$rightPhaseIsConstant, $right->arguments[0]],
                ] as [$isConstant, $phase]
            ) {
                if ($isConstant === true) {
                    $value = $this->constantRationalValue($phase, $extractor);
                    if ($value === null) {
                        return null;
                    }
                    if ($value[0] === '0') {
                        return new ComplexRationalSolution([], true, 0);
                    }
                }
            }
        }

        $phaseDifference = $extractor->rationalDifference($left->arguments[0], $right->arguments[0]);
        if ($phaseDifference === null) {
            return null;
        }
        [$numerator, $denominator] = $phaseDifference;
        $numeratorIsZero = \count($numerator) === 1 && $numerator[0] === '0';
        if ($numeratorIsZero) {
            throw new InfiniteSolutionSetException(
                'Matching periodic calls with identical rational phases agree on their common domain.',
            );
        }

        if (\in_array($left->name, ['csch', 'coth'], true)) {
            $phaseSum = $extractor->rationalSum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            [$sumNumerator] = $phaseSum;
            if (\count($sumNumerator) === 1 && $sumNumerator[0] === '0') {
                if ($left->name === 'csch') {
                    return new ComplexRationalSolution([], true, 0);
                }
                if ($leftPhaseIsConstant === false) {
                    throw new InfiniteSolutionSetException(
                        'A nonconstant rational phase reaches infinitely many zeros of hyperbolic cotangent.',
                    );
                }
                return new ComplexRationalSolution([], true, 0);
            }
        }

        $differenceIsConstant = $this->isConstantPolynomialRatio($numerator, $denominator);
        if ($left->name === 'csch' && $differenceIsConstant === true) {
            $phaseSum = $extractor->rationalSum($left->arguments[0], $right->arguments[0]);
            if ($phaseSum === null) {
                return null;
            }
            [$sumNumerator, $sumDenominator] = $phaseSum;
            $phaseSumIsConstant = $this->isConstantPolynomialRatio($sumNumerator, $sumDenominator);
            if ($phaseSumIsConstant === false) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant phase sum reaches infinitely many hyperbolic-cosecant equality branches.',
                );
            }
            if ($phaseSumIsConstant !== true) {
                return null;
            }
        }
        if ($differenceIsConstant === true) {
            return new ComplexRationalSolution([], true, 0);
        }
        if (
            $differenceIsConstant === false
            && ($leftPhaseIsConstant === true || $rightPhaseIsConstant === true)
        ) {
            throw new InfiniteSolutionSetException(
                'A nonconstant rational phase has infinitely many valid periodic-function preimages '
                    . 'of a constant phase modulo their period.',
            );
        }
        if (\in_array($left->name, ['tan', 'tanh'], true) && $differenceIsConstant === false) {
            $leftPolynomial = $extractor->polynomial($left->arguments[0]);
            $rightPolynomial = $extractor->polynomial($right->arguments[0]);
            if (
                $leftPolynomial !== null && $rightPolynomial !== null
                && \count($leftPolynomial) <= 2 && \count($rightPolynomial) <= 2
            ) {
                throw new InfiniteSolutionSetException(
                    'Distinct affine rational-polynomial tangent phases have infinitely many equal values '
                        . 'away from poles.',
                );
            }
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingPeriodicEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $functionNames = ['sin', 'cos', 'sinh', 'cosh'];
        $leftTerm = $this->extractScaledFunctionTerm($left, $extractor, $functionNames);
        $rightTerm = $this->extractScaledFunctionTerm($right, $extractor, $functionNames);
        if ($leftTerm === null || $rightTerm === null) {
            return null;
        }
        [$leftCall] = $leftTerm;
        [$rightCall] = $rightTerm;
        $functionFamilies = [['sin', 'cos'], ['sinh'], ['cosh']];
        $mixedHyperbolicSineCosh = \in_array(
            [$leftCall->name, $rightCall->name],
            [['sinh', 'cosh'], ['cosh', 'sinh']],
            true,
        );
        if (
            !$this->sharePeriodicFunctionFamily($leftCall->name, $rightCall->name, $functionFamilies)
            && !$mixedHyperbolicSineCosh
        ) {
            return null;
        }
        $mixedSineCosinePair =
            \in_array($leftCall->name, ['sin', 'cos'], true)
            && \in_array($rightCall->name, ['sin', 'cos'], true)
            && $leftCall->name !== $rightCall->name;
        $hasNonconstantPhase =
            $extractor->isConstantRationalFunction($leftCall->arguments[0]) === false
            || $extractor->isConstantRationalFunction($rightCall->arguments[0]) === false;
        if (
            !$this->fractionsAreEqual($leftTerm[1], $rightTerm[1])
            && (!$mixedSineCosinePair || !$hasNonconstantPhase)
            && !$mixedHyperbolicSineCosh
        ) {
            return null;
        }
        $phaseCombinations = [
            $extractor->rationalDifference($leftCall->arguments[0], $rightCall->arguments[0]),
            $extractor->rationalSum($leftCall->arguments[0], $rightCall->arguments[0]),
        ];
        $phaseCombinationIsZero = [];
        $phaseCombinationIsConstant = [];
        foreach ($phaseCombinations as $phaseCombination) {
            if ($phaseCombination === null) {
                return null;
            }
            [$numerator, $denominator] = $phaseCombination;
            $phaseCombinationIsZero[] = \count($numerator) === 1 && $numerator[0] === '0';
            $constantStatus = $this->isConstantPolynomialRatio($numerator, $denominator);
            $phaseCombinationIsConstant[] = $constantStatus === true;
            if ($constantStatus === false) {
                if ($mixedHyperbolicSineCosh) {
                    continue;
                }
                throw new InfiniteSolutionSetException(
                    'A nonconstant rational phase sum or difference gives infinitely many complex '
                        . 'solutions to entire periodic equations.',
                );
            }
        }

        if ($mixedHyperbolicSineCosh) {
            if (
                !$evaluationOptions->functions->isBuiltIn($leftCall->name)
                || !$evaluationOptions->functions->isBuiltIn($rightCall->name)
            ) {
                return null;
            }
            $hyperbolicSinePhase = $leftCall->name === 'sinh'
                ? $leftCall->arguments[0]
                : $rightCall->arguments[0];
            $phaseIsConstant = $extractor->isConstantRationalFunction($hyperbolicSinePhase);
            if ($phaseIsConstant === null) {
                return null;
            }
            if ($phaseIsConstant) {
                if (!$phaseCombinationIsZero[0] && !$phaseCombinationIsZero[1]) {
                    return null;
                }
                if (!$this->isExactRationalExpression(
                    $hyperbolicSinePhase,
                    $variable,
                    $parameters,
                    $evaluationOptions->limits->maxExponentMagnitude,
                )) {
                    return null;
                }

                // For nonzero algebraic phases, exp(2P) is transcendental and cannot equal the rational
                // amplitude ratio required by a sinh/cosh equality.
                return new ComplexRationalSolution([], true, 0);
            }
            if (!$phaseCombinationIsZero[0] && !$phaseCombinationIsZero[1]) {
                $hyperbolicCosinePhase = $leftCall->name === 'cosh'
                    ? $leftCall->arguments[0]
                    : $rightCall->arguments[0];
                $cosinePhaseIsConstant = $extractor->isConstantRationalFunction($hyperbolicCosinePhase);
                if ($cosinePhaseIsConstant !== false) {
                    return null;
                }
                if (!$phaseCombinationIsConstant[0] && !$phaseCombinationIsConstant[1]) {
                    $phaseRatio = $extractor->rationalFunctionRatio(
                        $hyperbolicSinePhase,
                        $hyperbolicCosinePhase,
                    );
                    if (\is_array($phaseRatio)) {
                        $phaseNumerator = \filter_var($phaseRatio[0], FILTER_VALIDATE_INT);
                        $phaseDenominator = \filter_var($phaseRatio[1], FILTER_VALIDATE_INT);
                        if (
                            \is_int($phaseNumerator)
                            && \is_int($phaseDenominator)
                            && $phaseNumerator !== 0
                            && $phaseDenominator > 0
                            && \abs($phaseNumerator) !== $phaseDenominator
                        ) {
                            throw new InfiniteSolutionSetException(
                                'Rationally proportional mixed sinh/cosh phases with unequal absolute integer '
                                    . 'weights reduce to a Laurent polynomial with nonzero exponential roots.',
                            );
                        }
                    }

                    return null;
                }

                throw new InfiniteSolutionSetException(
                    'A nonzero rational phase sum or difference reduces mixed sinh/cosh calls to an attainable '
                        . 'hyperbolic-tangent level with infinitely many complex preimages.',
                );
            }
            $negativeRightAmplitude = [
                $this->integerArithmetic()->negateIntegerValue($rightTerm[1][0]),
                $rightTerm[1][1],
            ];
            if (
                $this->fractionsAreEqual($leftTerm[1], $rightTerm[1])
                || $this->fractionsAreEqual($leftTerm[1], $negativeRightAmplitude)
            ) {
                return new ComplexRationalSolution([], true, 0);
            }

            throw new InfiniteSolutionSetException(
                'Identical or opposite rational sinh/cosh phases with other nonzero rational amplitudes reduce '
                    . 'to a nonzero exponential level attained infinitely often.',
            );
        }

        $leftPhaseIsExactConstant =
            $extractor->isConstantRationalFunction($leftCall->arguments[0]) === true
            && $this->isExactRationalExpression(
                $leftCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            );
        $rightPhaseIsExactConstant =
            $extractor->isConstantRationalFunction($rightCall->arguments[0]) === true
            && $this->isExactRationalExpression(
                $rightCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            );
        if ($leftCall->name !== $rightCall->name) {
            return $leftPhaseIsExactConstant && $rightPhaseIsExactConstant
                ? new ComplexRationalSolution([], true, 0)
                : null;
        }
        if ($phaseCombinationIsZero[0]) {
            throw new InfiniteSolutionSetException(
                'Matching entire periodic calls with identical rational phases agree on their domain.',
            );
        }
        if (
            $phaseCombinationIsZero[1]
            && \in_array($leftCall->name, ['cos', 'cosh'], true)
        ) {
            throw new InfiniteSolutionSetException(
                'Even entire periodic calls agree at opposite rational phases.',
            );
        }
        if (
            $phaseCombinationIsZero[1]
            && \in_array($leftCall->name, ['sin', 'sinh'], true)
        ) {
            $leftPhaseIsConstant = $extractor->isConstantRationalFunction($leftCall->arguments[0]);
            if ($leftPhaseIsConstant === null) {
                return null;
            }
            if (!$leftPhaseIsConstant) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant rational phase has infinitely many complex zeros of sine or hyperbolic sine.',
                );
            }
            $phaseValue = $this->constantRationalValue($leftCall->arguments[0], $extractor);
            if ($phaseValue === null) {
                return null;
            }
            if ($phaseValue[0] === '0') {
                throw new InfiniteSolutionSetException(
                    'The zero phase makes the opposite-phase sine equation an identity.',
                );
            }
            if (!$this->isExactRationalExpression(
                $leftCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            )) {
                return null;
            }

            return new ComplexRationalSolution([], true, 0);
        }

        if ($leftPhaseIsExactConstant && $rightPhaseIsExactConstant) {
            return new ComplexRationalSolution([], true, 0);
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function solveMatchingReciprocalPeriodicEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $functionNames = ['sec', 'csc', 'sech'];
        $leftTerm = $this->extractScaledFunctionTerm($left, $extractor, $functionNames);
        $rightTerm = $this->extractScaledFunctionTerm($right, $extractor, $functionNames);
        if (
            $leftTerm === null
            || $rightTerm === null
            || $leftTerm[0]->name !== $rightTerm[0]->name
            || !$this->fractionsAreEqual($leftTerm[1], $rightTerm[1])
            || !$evaluationOptions->functions->isBuiltIn($leftTerm[0]->name)
            || !$evaluationOptions->functions->isBuiltIn($rightTerm[0]->name)
        ) {
            return null;
        }

        [$leftCall] = $leftTerm;
        [$rightCall] = $rightTerm;
        $phaseDifference = $extractor->rationalDifference(
            $leftCall->arguments[0],
            $rightCall->arguments[0],
        );
        $phaseSum = $extractor->rationalSum(
            $leftCall->arguments[0],
            $rightCall->arguments[0],
        );
        if ($phaseDifference === null || $phaseSum === null) {
            return null;
        }
        $samePhase = \count($phaseDifference[0]) === 1 && $phaseDifference[0][0] === '0';
        $oppositePhase = \count($phaseSum[0]) === 1 && $phaseSum[0][0] === '0';
        if ($leftCall->name === 'csc' && $oppositePhase) {
            return new ComplexRationalSolution([], true, 0);
        }
        if (!$samePhase && !($leftCall->name !== 'csc' && $oppositePhase)) {
            return null;
        }

        $phase = $leftCall->arguments[0];
        $isConstant = $extractor->isConstantRationalFunction($phase);
        if ($isConstant === null) {
            return null;
        }
        if ($isConstant) {
            if (!$this->isExactRationalExpression(
                $phase,
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            )) {
                return null;
            }
            $value = $this->constantRationalValue($phase, $extractor);
            if ($value === null) {
                return null;
            }
            if ($leftCall->name === 'csc' && $value[0] === '0') {
                return new ComplexRationalSolution([], true, 0);
            }
        }

        throw new InfiniteSolutionSetException(
            'Identical or even-function opposite reciprocal-periodic phases agree on their common domain.',
        );
    }

    /** @param array<string, int|float> $parameters */
    private function solveMixedReciprocalPeriodicEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $functionNames = ['sec', 'csc', 'sech', 'csch'];
        $leftTerm = $this->extractScaledFunctionTerm($left, $extractor, $functionNames);
        $rightTerm = $this->extractScaledFunctionTerm($right, $extractor, $functionNames);
        if (
            $leftTerm === null
            || $rightTerm === null
            || $leftTerm[0]->name === $rightTerm[0]->name
            || !$evaluationOptions->functions->isBuiltIn($leftTerm[0]->name)
            || !$evaluationOptions->functions->isBuiltIn($rightTerm[0]->name)
        ) {
            return null;
        }

        [$leftCall] = $leftTerm;
        [$rightCall] = $rightTerm;
        $phaseDifference = $extractor->rationalDifference(
            $leftCall->arguments[0],
            $rightCall->arguments[0],
        );
        $phaseSum = $extractor->rationalSum(
            $leftCall->arguments[0],
            $rightCall->arguments[0],
        );
        if ($phaseDifference === null || $phaseSum === null) {
            return null;
        }
        $samePhase = \count($phaseDifference[0]) === 1 && $phaseDifference[0][0] === '0';
        $oppositePhase = \count($phaseSum[0]) === 1 && $phaseSum[0][0] === '0';
        $leftIsTrigonometric = \in_array($leftCall->name, ['sec', 'csc'], true);
        $rightIsTrigonometric = \in_array($rightCall->name, ['sec', 'csc'], true);
        $leftIsHyperbolic = \in_array($leftCall->name, ['sech', 'csch'], true);
        $rightIsHyperbolic = \in_array($rightCall->name, ['sech', 'csch'], true);
        $trigonometricPair = $leftIsTrigonometric && $rightIsTrigonometric;
        $hyperbolicPair = $leftIsHyperbolic && $rightIsHyperbolic;
        $trigonometricHyperbolicPair = (
            $leftIsTrigonometric && $rightIsHyperbolic
        ) || (
            $leftIsHyperbolic && $rightIsTrigonometric
        );
        if (!$trigonometricPair && !$hyperbolicPair && !$trigonometricHyperbolicPair) {
            return null;
        }
        if (!$samePhase && !$oppositePhase) {
            if ($hyperbolicPair) {
                $sinhPhase = $leftCall->name === 'csch'
                    ? $leftCall->arguments[0]
                    : $rightCall->arguments[0];
                $coshPhase = $leftCall->name === 'sech'
                    ? $leftCall->arguments[0]
                    : $rightCall->arguments[0];
                $sinhPhaseIsNonconstant = $extractor->isConstantRationalFunction($sinhPhase) === false;
                $coshPhaseIsNonconstant = $extractor->isConstantRationalFunction($coshPhase) === false;
                if ($sinhPhaseIsNonconstant && $coshPhaseIsNonconstant) {
                    $hasNonzeroConstantRelation = false;
                    foreach ([$phaseDifference, $phaseSum] as [$numerator, $denominator]) {
                        if (
                            $numerator[0] !== '0'
                            && $this->isConstantPolynomialRatio($numerator, $denominator) === true
                        ) {
                            $hasNonzeroConstantRelation = true;
                            break;
                        }
                    }
                    if ($hasNonzeroConstantRelation) {
                        throw new InfiniteSolutionSetException(
                            'A nonzero constant phase sum or difference reduces mixed sech/csch calls to a shifted '
                                . 'sinh/cosh equation with infinitely many complex roots.',
                        );
                    }

                    $phaseRatio = $extractor->rationalFunctionRatio($sinhPhase, $coshPhase);
                    if (\is_array($phaseRatio)) {
                        $phaseNumerator = \filter_var($phaseRatio[0], FILTER_VALIDATE_INT);
                        $phaseDenominator = \filter_var($phaseRatio[1], FILTER_VALIDATE_INT);
                        if (
                            \is_int($phaseNumerator)
                            && \is_int($phaseDenominator)
                            && $phaseNumerator !== 0
                            && $phaseDenominator > 0
                            && \abs($phaseNumerator) !== $phaseDenominator
                        ) {
                            throw new InfiniteSolutionSetException(
                                'Rationally proportional nonconstant sech/csch phases with unequal absolute '
                                    . 'integer weights reduce to a Laurent polynomial with nonzero roots.',
                            );
                        }
                    }
                }
            }

            return null;
        }

        if ($hyperbolicPair) {
            $negativeRightScale = [
                $this->integerArithmetic()->negateIntegerValue($rightTerm[1][0]),
                $rightTerm[1][1],
            ];
            if (
                $this->fractionsAreEqual($leftTerm[1], $rightTerm[1])
                || $this->fractionsAreEqual($leftTerm[1], $negativeRightScale)
            ) {
                return new ComplexRationalSolution([], true, 0);
            }
        }

        $phaseIsConstant = $extractor->isConstantRationalFunction($leftCall->arguments[0]);
        if ($phaseIsConstant === false) {
            if ($trigonometricHyperbolicPair) {
                throw new InfiniteSolutionSetException(
                    'A nonconstant rational phase gives infinitely many roots of mixed reciprocal '
                        . 'trigonometric/hyperbolic equations.',
                );
            }
            throw new InfiniteSolutionSetException(
                $trigonometricPair
                    ? 'A nonconstant rational phase gives infinitely many roots of the mixed secant/cosecant equation.'
                    : 'A nonconstant rational phase gives infinitely many roots when the reciprocal-hyperbolic '
                        . 'scales differ in magnitude.',
            );
        }
        if (
            $phaseIsConstant === true
            && $this->isExactRationalExpression(
                $leftCall->arguments[0],
                $variable,
                $parameters,
                $evaluationOptions->limits->maxExponentMagnitude,
            )
        ) {
            if ($trigonometricHyperbolicPair) {
                $phaseValue = $this->constantRationalValue($leftCall->arguments[0], $extractor);
                if ($phaseValue === null) {
                    return null;
                }
                $hasDefinedMatchingValuesAtZero = $phaseValue[0] === '0'
                    && \in_array(
                        [$leftCall->name, $rightCall->name],
                        [['sec', 'sech'], ['sech', 'sec']],
                        true,
                    );
                if ($hasDefinedMatchingValuesAtZero && $this->fractionsAreEqual($leftTerm[1], $rightTerm[1])) {
                    throw new InfiniteSolutionSetException(
                        'Equal scaled secant and hyperbolic-secant calls at zero phase are identical.',
                    );
                }

                return new ComplexRationalSolution([], true, 0);
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function isExactRationalExpression(
        Node $node,
        string $variable,
        array $parameters,
        int|float $maxExponentMagnitude,
    ): bool
    {
        if ($node instanceof BinaryOperationNode && $node->operator === BinaryOperator::Power) {
            $exponent = RationalLiteral::fromNodeAsStrings($node->right);
            $maximum = RationalLiteral::fromFloatExactAsStrings((float) $maxExponentMagnitude);
            if (
                $exponent === null
                || $exponent[1] !== '1'
                || $maximum === null
            ) {
                return false;
            }
            $comparison = $this->integerArithmetic()->compareIntegerRatioToFraction(
                \ltrim($exponent[0], '-'),
                $exponent[1],
                $maximum[0],
                $maximum[1],
            );
            if ($comparison === null || $comparison > 0) {
                return false;
            }
        }
        if (RationalLiteral::fromNodeAsStrings($node) !== null) {
            return true;
        }
        if ($node instanceof VariableNode) {
            if ($node->name === $variable) {
                return true;
            }
            $parameter = $parameters[$node->name] ?? null;

            return \is_int($parameter)
                || (\is_float($parameter) && RationalLiteral::fromFloatExactAsStrings($parameter) !== null);
        }
        if ($node instanceof GroupingNode) {
            return $this->isExactRationalExpression(
                $node->expression,
                $variable,
                $parameters,
                $maxExponentMagnitude,
            );
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isExactRationalExpression(
                $node->operand,
                $variable,
                $parameters,
                $maxExponentMagnitude,
            );
        }
        if ($node instanceof BinaryOperationNode) {
            if ($node->operator === BinaryOperator::Modulo) {
                return false;
            }

            return $this->isExactRationalExpression(
                $node->left,
                $variable,
                $parameters,
                $maxExponentMagnitude,
            ) && $this->isExactRationalExpression(
                $node->right,
                $variable,
                $parameters,
                $maxExponentMagnitude,
            );
        }

        return false;
    }

    /**
     * @param list<list<string>> $families
     */
    private function sharePeriodicFunctionFamily(string $left, string $right, array $families): bool
    {
        foreach ($families as $family) {
            if (\in_array($left, $family, true) && \in_array($right, $family, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, int|float> $parameters */
    private function solveTranscendentalLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $levelSide = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && \in_array(
                    $candidate->name,
                    [
                        'sin', 'cos', 'tan', 'cot', 'sinh', 'cosh', 'tanh', 'coth',
                        'sec', 'csc', 'sech', 'csch', 'sinc', 'sincpi',
                    ],
                    true,
                )
                && \count($candidate->arguments) === 1
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

        $levelCoefficients = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($levelSide);
        if (
            $levelCoefficients === null
            || \count($levelCoefficients) !== 1
            || !\is_finite($levelCoefficients[0])
        ) {
            return null;
        }
        $level = $levelCoefficients[0];
        if (
            (\in_array($wrapper->name, ['tanh', 'coth'], true) && \abs($level) === 1.0)
            || (\in_array($wrapper->name, ['sec', 'csc', 'sech', 'csch'], true) && $level === 0.0)
        ) {
            return new ComplexRationalSolution([], true, 0);
        }

        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $argumentForm = $extractor->rationalPolynomialForm($wrapper->arguments[0]);
        $argumentIsConstant = $extractor->isConstantRationalFunction($wrapper->arguments[0]);
        if ($argumentIsConstant === false) {
            throw new InfiniteSolutionSetException(
                \sprintf(
                    'The complex equation %s(R(x)) = c has infinitely many solutions at this level.',
                    $wrapper->name,
                ),
            );
        }
        if ($argumentIsConstant === true && $argumentForm !== null) {
            $probeCount = \count($argumentForm[1]);
            for ($probe = 0; $probe < $probeCount; ++$probe) {
                $environment = new Environment(
                    [...$parameters, $variable => (float) $probe],
                    $evaluationOptions->functions,
                );
                $evaluator = new Evaluator($environment, $evaluationOptions->limits);
                try {
                    $argumentValue = $evaluator->evaluate($wrapper->arguments[0]);
                } catch (EvaluationException) {
                    continue;
                }
                try {
                    $functionValue = $evaluator->evaluate($wrapper);
                } catch (EvaluationException) {
                    $knownPoleAtZero = \in_array($wrapper->name, ['cot', 'csc', 'csch', 'coth'], true)
                        && (float) $argumentValue === 0.0;

                    return new ComplexRationalSolution([], $knownPoleAtZero, 0);
                }
                if ($functionValue === $level) {
                    throw new InfiniteSolutionSetException(
                        'The constant complex equation holds for every value in its rational argument domain.',
                    );
                }

                return new ComplexRationalSolution([], true, 0);
            }
        }

        return null;
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
    ): ?ComplexRationalSolution {
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
        $level = $extractor->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $targetLevel = $level[0];
        if ($wrapper->name === 'log') {
            $baseNode = $wrapper->arguments[1];
            $polynomialBase = $extractor->extract($baseNode);
            $exactExtractor = new ExactRationalPolynomialExtractor(
                $variable,
                $parameters,
                $solverOptions->maxPolynomialDegree,
                $evaluationOptions,
            );
            $baseIsConstant = $exactExtractor->isConstantRationalFunction($baseNode);
            if (
                ($polynomialBase !== null && \count($polynomialBase) > 1)
                || ($polynomialBase === null && $baseIsConstant === false)
            ) {
                return $this->solveVariableBaseLogEquation(
                    $left,
                    $right,
                    $wrapper,
                    $wrapperSource,
                    $targetLevel,
                    $constantSide,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            }
        }
        $base = match ($wrapper->name) {
            'ln', 'log1p' => \M_E,
            'log10' => 10.0,
            'log2' => 2.0,
            default => NAN,
        };
        if ($wrapper->name === 'log') {
            $baseCoefficients = $extractor->extract($wrapper->arguments[1]);
            if ($baseCoefficients === null || \count($baseCoefficients) !== 1) {
                return null;
            }
            $base = $baseCoefficients[0];
            if (!\is_finite($base) || $base <= 0.0 || $base === 1.0) {
                return new ComplexRationalSolution([], true, 0);
            }
        }

        $argumentTarget = match ($wrapper->name) {
            'ln' => \exp($targetLevel),
            'log1p' => \expm1($targetLevel),
            'log10', 'log2', 'log' => \pow($base, $targetLevel),
            default => NAN,
        };
        if (
            !\is_finite($argumentTarget)
            || ($argumentTarget === 0.0 && !($wrapper->name === 'log1p' && $targetLevel === 0.0))
            || ($wrapper->name === 'log1p' && $argumentTarget <= -1.0)
        ) {
            return new ComplexRationalSolution([], false, 0);
        }

        $argument = $wrapper->arguments[0];
        $span = $argument->span();
        $argumentSource = \substr($wrapperSource, $span->start, $span->end - $span->start);
        $argumentSource = $this->replaceNamedConstantsWithNumericLiterals(
            $argumentSource,
            $parameters,
            $evaluationOptions,
        );
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
    ): ComplexRationalSolution {
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
            return new ComplexRationalSolution([], false, 0);
        }
        if ($fraction === null) {
            $argumentCoefficients = (new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            ))->extract($argument);
            if ($argumentCoefficients === null || \count($argumentCoefficients) !== 1) {
                return new ComplexRationalSolution([], false, 0);
            }
            $argumentValue = $argumentCoefficients[0];
            if ($argumentValue <= 0.0) {
                return new ComplexRationalSolution([], true, 0);
            }
            if ($level === 0.0) {
                return new ComplexRationalSolution([], false, 0);
            }
            $targetBase = \exp(\log($argumentValue) / $level);
            $targetSource = \json_encode($targetBase, \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_finite($targetBase) || $targetBase <= 0.0 || !\is_string($targetSource)) {
                return new ComplexRationalSolution([], false, 0);
            }
            if ($targetBase === 1.0) {
                return new ComplexRationalSolution([], $argumentValue === 1.0, 0);
            }
            $transformedEquation = '(' . $baseSource . ')=' . $targetSource;
        } else {
            if (
                \abs($fraction[0]) > $solverOptions->maxPolynomialDegree
                || $fraction[1] > $solverOptions->maxPolynomialDegree
            ) {
                return new ComplexRationalSolution([], false, 0);
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
            return new ComplexRationalSolution([], false, 0);
        }

        $roots = [];
        $converged = $algebraic->converged;
        foreach ($algebraic->roots as $root) {
            $scale = \max(1.0, \abs($root->real), \abs($root->imaginary));
            if (\abs($root->imaginary) > 128.0 * \PHP_FLOAT_EPSILON * $scale) {
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

        return new ComplexRationalSolution($roots, $converged, $algebraic->iterations);
    }

    /** @param array<string, int|float> $parameters */
    private function replaceConstantSubexpressionsWithLiterals(
        string $source,
        Node $node,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): string {
        $replacements = [];
        $this->collectConstantSubexpressionReplacements(
            $node,
            $source,
            $variable,
            $parameters,
            $evaluationOptions,
            $replacements,
        );
        foreach (\array_reverse($replacements) as [$start, $length, $literal]) {
            $source = \substr_replace($source, $literal, $start, $length);
        }

        return $source;
    }

    /**
     * @param array<string, int|float> $parameters
     * @param list<array{int, int, string}> $replacements
     */
    private function collectConstantSubexpressionReplacements(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        array &$replacements,
    ): void {
        if (!$this->containsVariable($node, $variable)) {
            try {
                $value = (new Evaluator(
                    new Environment($parameters, $evaluationOptions->functions),
                    $evaluationOptions->limits,
                ))->evaluate($node);
                $literal = \json_encode((float) $value, \JSON_PRESERVE_ZERO_FRACTION);
                $span = $node->span();
                if (
                    \is_string($literal)
                    && \is_finite((float) $value)
                    && $span->start >= 0
                    && $span->end <= \strlen($source)
                    && \substr($source, $span->start, $span->end - $span->start) !== $literal
                ) {
                    $replacements[] = [$span->start, $span->end - $span->start, $literal];
                }
            } catch (EvaluationException | \DomainException | \OverflowException) {
                // If this constant expression cannot be evaluated, keep searching its children.
            }
            if ($replacements !== []) {
                $last = $replacements[\array_key_last($replacements)];
                if ($last[0] === $node->span()->start && $last[1] === $node->span()->end - $node->span()->start) {
                    return;
                }
            }
        }
        if ($node instanceof GroupingNode) {
            $this->collectConstantSubexpressionReplacements(
                $node->expression,
                $source,
                $variable,
                $parameters,
                $evaluationOptions,
                $replacements,
            );
        } elseif ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            $this->collectConstantSubexpressionReplacements(
                $node->operand,
                $source,
                $variable,
                $parameters,
                $evaluationOptions,
                $replacements,
            );
        } elseif ($node instanceof BinaryOperationNode) {
            $this->collectConstantSubexpressionReplacements(
                $node->left,
                $source,
                $variable,
                $parameters,
                $evaluationOptions,
                $replacements,
            );
            $this->collectConstantSubexpressionReplacements(
                $node->right,
                $source,
                $variable,
                $parameters,
                $evaluationOptions,
                $replacements,
            );
        } elseif ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $this->collectConstantSubexpressionReplacements(
                    $argument,
                    $source,
                    $variable,
                    $parameters,
                    $evaluationOptions,
                    $replacements,
                );
            }
        }
    }

    /** @param array<string, int|float> $parameters */
    private function replaceNamedConstantsWithNumericLiterals(
        string $source,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): string {
        $tokens = (new Lexer($source, $evaluationOptions->limits))->tokenize();
        $replacements = [];
        $environment = new Environment($parameters, $evaluationOptions->functions);
        foreach ($tokens as $token) {
            if (
                $token->type !== TokenType::Identifier
                || !\in_array($token->lexeme, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
            ) {
                continue;
            }
            $value = (new Evaluator($environment, $evaluationOptions->limits))->evaluate(
                new ConstantNode($token->lexeme, $token->span),
            );
            $literal = \json_encode((float) $value, \JSON_PRESERVE_ZERO_FRACTION);
            if (\is_string($literal)) {
                $replacements[] = [$token->span->start, $token->span->end - $token->span->start, $literal];
            }
        }
        foreach (\array_reverse($replacements) as [$start, $length, $literal]) {
            $source = \substr_replace($source, $literal, $start, $length);
        }

        return $source;
    }

    private function isLogarithmCall(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (!$node instanceof FunctionCallNode) {
            return false;
        }

        return (\in_array($node->name, ['ln', 'log10', 'log2', 'log1p'], true)
                && \count($node->arguments) === 1)
            || ($node->name === 'log' && \count($node->arguments) === 2);
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
    ): ?ComplexRationalSolution {
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

        $level = (new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        ))->extract($constantSide);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        if ($level[0] < 0.0) {
            return new ComplexRationalSolution([], true, 0);
        }

        $firstSpan = $wrapper->arguments[0]->span();
        $secondSpan = $wrapper->arguments[1]->span();
        $firstArgument = \substr($wrapperSource, $firstSpan->start, $firstSpan->end - $firstSpan->start);
        $secondArgument = \substr($wrapperSource, $secondSpan->start, $secondSpan->end - $secondSpan->start);
        if ($firstArgument === '' || $secondArgument === '') {
            return null;
        }
        if ($level[0] === 0.0) {
            $transformedEquation = '(' . $firstArgument . ')^2+(' . $secondArgument . ')^2=0';
        } else {
            $scale = \json_encode($level[0], \JSON_PRESERVE_ZERO_FRACTION);
            if (!\is_string($scale)) {
                return null;
            }
            $transformedEquation = '((' . $firstArgument . ')/' . $scale . ')^2'
                . '+((' . $secondArgument . ')/' . $scale . ')^2=1';
        }

        return $this->solve(
            $transformedEquation,
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
    private function solveIntegerShapeRegularizedBetaEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
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
        ) {
            return null;
        }

        $argument = $wrapper->arguments[2];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === null) {
            return null;
        }
        $shapeDegree = $alpha[0] + $beta[0] - 1.0;
        if (!\is_finite($shapeDegree) || $shapeDegree > $solverOptions->maxPolynomialDegree) {
            if ($isConstant) {
                $form = $rationalExtractor->rationalPolynomialForm($argument);
                if ($form === null) {
                    return null;
                }
                for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
                    $evaluator = new Evaluator(
                        new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                        $evaluationOptions->limits,
                    );
                    try {
                        $value = $evaluator->evaluate($wrapper);
                    } catch (EvaluationException) {
                        continue;
                    }
                    $levelForm = $rationalExtractor->rationalPolynomialForm($levelNode);
                    if ($levelForm === null || \count($levelForm[0]) !== 1 || \count($levelForm[1]) !== 1) {
                        return new ComplexRationalSolution([], false, 0);
                    }
                    if ($value === (float) $levelForm[0][0] / (float) $levelForm[1][0]) {
                        throw new InfiniteSolutionSetException(
                            'The constant regularized-beta equation holds throughout its rational argument domain.',
                        );
                    }

                    return new ComplexRationalSolution([], true, 0);
                }

                return new ComplexRationalSolution([], false, 0);
            }

            return new ComplexRationalSolution([], false, 0);
        }
        if ($rationalExtractor->rationalPolynomialForm($levelNode) === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $integerExtractor = new IntegerPolynomialExtractor('', [], $solverOptions->maxPolynomialDegree);
        $equationCoefficients = IntegerShapeBetaPolynomial::equationCoefficients(
            (int) $alpha[0],
            (int) $beta[0],
            $wrapper->name === 'betaincc',
            ['0', '1'],
            $rationalExtractor,
            $integerExtractor,
        );
        if ($equationCoefficients === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $composition = $rationalExtractor->rationalPolynomialDifferenceInRationalFunction(
            $argument,
            $equationCoefficients,
            $levelNode,
        );
        if ($composition === null) {
            return new ComplexRationalSolution([], false, 0);
        }

        [$numerator, $denominator] = $composition;
        $rootCounter = new ExactPolynomialRootCounter();
        while (true) {
            $commonFactor = $rootCounter->greatestCommonDivisor($numerator, $denominator);
            if ($commonFactor === null) {
                return new ComplexRationalSolution([], false, 0);
            }
            if (\count($commonFactor) <= 1) {
                break;
            }
            $reducedNumerator = $rootCounter->dividePolynomialExactly($numerator, $commonFactor);
            if ($reducedNumerator === null || \count($reducedNumerator) >= \count($numerator)) {
                return new ComplexRationalSolution([], false, 0);
            }
            $numerator = $reducedNumerator;
        }
        if ($isConstant) {
            if (\count($numerator) === 1 && $numerator[0] === '0') {
                throw new InfiniteSolutionSetException(
                    'The constant regularized-beta equation holds throughout its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }
        if (\count($numerator) === 1 && $numerator[0] === '0') {
            throw new InfiniteSolutionSetException(
                'The regularized-beta equation holds throughout its rational domain.',
            );
        }
        if (\count($numerator) <= 1) {
            return new ComplexRationalSolution([], true, 0);
        }

        $normalized = $integerExtractor->normalizedCoefficients($numerator, true);
        if ($normalized === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $algebraic = (new ComplexPolynomialSolver())->solve($normalized, $solverOptions, $numerator);

        return new ComplexRationalSolution($algebraic->roots, $algebraic->converged, $algebraic->iterations);
    }

    /** @param array<string, int|float> $parameters */
    private function solveGammaLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && $candidate->name === 'gamma'
                && \count($candidate->arguments) === 1
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
        $level = $extractor->extract($levelNode);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        if ($level[0] === 0.0) {
            return new ComplexRationalSolution([], true, 0);
        }

        $argument = $wrapper->arguments[0];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            if ($rationalExtractor->rationalPolynomialForm($argument) === null) {
                return new ComplexRationalSolution([], false, 0);
            }
            throw new InfiniteSolutionSetException(
                'The complex equation gamma(R(x)) = c has infinitely many solutions for every nonzero c.',
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $rationalExtractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $argumentValue = (float) $evaluator->evaluate($argument);
            } catch (EvaluationException) {
                continue;
            }
            if ($argumentValue <= 0.0 && $argumentValue === \floor($argumentValue)) {
                return new ComplexRationalSolution([], true, 0);
            }
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                return new ComplexRationalSolution([], false, 0);
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveDigammaLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && (\in_array($candidate->name, ['digamma', 'trigamma', 'tetragamma'], true)
                    && \count($candidate->arguments) === 1
                    || ($candidate->name === 'polygamma' && \count($candidate->arguments) === 2))
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
        $level = $extractor->extract($levelNode);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }
        $argumentIndex = $wrapper->name === 'polygamma' ? 1 : 0;
        if ($wrapper->name === 'polygamma') {
            $order = $extractor->extract($wrapper->arguments[0]);
            if ($order === null || \count($order) !== 1 || !\is_finite($order[0])) {
                return null;
            }
            if ($order[0] < 0.0 || $order[0] > 128.0 || $order[0] !== \floor($order[0])) {
                return null;
            }
        }

        $argument = $wrapper->arguments[$argumentIndex];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            throw new InfiniteSolutionSetException(
                \sprintf(
                    'The complex equation %s(R(x)) = c has infinitely many solutions at this level.',
                    $wrapper->name,
                ),
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $rationalExtractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $argumentValue = (float) $evaluator->evaluate($argument);
            } catch (EvaluationException) {
                continue;
            }
            if ($argumentValue <= 0.0 && $argumentValue === \floor($argumentValue)) {
                return new ComplexRationalSolution([], true, 0);
            }
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                return new ComplexRationalSolution([], false, 0);
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    /** @param array<string, int|float> $parameters */
    private function solveZetaLevelEquation(
        Node $left,
        Node $right,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?ComplexRationalSolution {
        $wrapper = null;
        $levelNode = null;
        foreach ([[$left, $right], [$right, $left]] as [$candidate, $other]) {
            while ($candidate instanceof GroupingNode) {
                $candidate = $candidate->expression;
            }
            if (
                $candidate instanceof FunctionCallNode
                && $candidate->name === 'zeta'
                && \count($candidate->arguments) === 1
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
        $level = $extractor->extract($levelNode);
        if ($level === null || \count($level) !== 1 || !\is_finite($level[0])) {
            return null;
        }

        $argument = $wrapper->arguments[0];
        $rationalExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $isConstant = $rationalExtractor->isConstantRationalFunction($argument);
        if ($isConstant === false) {
            throw new InfiniteSolutionSetException(
                'The complex equation zeta(R(x)) = c has infinitely many solutions for every real c.',
            );
        }
        if ($isConstant !== true) {
            return null;
        }

        $form = $rationalExtractor->rationalPolynomialForm($argument);
        if ($form === null) {
            return null;
        }
        for ($probe = 0, $probeCount = \count($form[1]); $probe < $probeCount; ++$probe) {
            $evaluator = new Evaluator(
                new Environment([...$parameters, $variable => (float) $probe], $evaluationOptions->functions),
                $evaluationOptions->limits,
            );
            try {
                $argumentValue = (float) $evaluator->evaluate($argument);
            } catch (EvaluationException) {
                continue;
            }
            if ($argumentValue === 1.0) {
                return new ComplexRationalSolution([], true, 0);
            }
            try {
                $value = $evaluator->evaluate($wrapper);
            } catch (EvaluationException) {
                return new ComplexRationalSolution([], false, 0);
            }
            if ($value === $level[0]) {
                throw new InfiniteSolutionSetException(
                    'The constant complex equation holds for every value in its rational argument domain.',
                );
            }

            return new ComplexRationalSolution([], true, 0);
        }

        return new ComplexRationalSolution([], false, 0);
    }

    private function containsIdenticallyUndefinedRationalOperation(
        Node $node,
        ExactRationalPolynomialExtractor $extractor,
    ): bool {
        if ($node instanceof GroupingNode) {
            return $this->containsIdenticallyUndefinedRationalOperation($node->expression, $extractor);
        }
        if ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            return $this->containsIdenticallyUndefinedRationalOperation($node->operand, $extractor);
        }
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsIdenticallyUndefinedRationalOperation($argument, $extractor)) {
                    return true;
                }
            }

            return false;
        }
        if (!$node instanceof BinaryOperationNode) {
            return false;
        }

        if (
            $node->operator === BinaryOperator::Divide
            && $this->isIdenticallyZeroPolynomial($extractor->polynomial($node->right))
        ) {
            return true;
        }
        if ($node->operator === BinaryOperator::Power) {
            $exponent = $extractor->polynomial($node->right);
            if (
                $exponent !== null
                && \count($exponent) === 1
                && $exponent[0][1] === '1'
                && \str_starts_with($exponent[0][0], '-')
                && $this->isIdenticallyZeroPolynomial($extractor->polynomial($node->left))
            ) {
                return true;
            }
        }

        return $this->containsIdenticallyUndefinedRationalOperation($node->left, $extractor)
            || $this->containsIdenticallyUndefinedRationalOperation($node->right, $extractor);
    }

    private function containsVariableFunctionCall(Node $node, string $variable): bool
    {
        if ($node instanceof GroupingNode) {
            return $this->containsVariableFunctionCall($node->expression, $variable);
        }
        if ($node instanceof UnaryOperationNode || $node instanceof FactorialNode) {
            return $this->containsVariableFunctionCall($node->operand, $variable);
        }
        if ($node instanceof FunctionCallNode) {
            if ($this->containsVariable($node, $variable)) {
                return true;
            }
            foreach ($node->arguments as $argument) {
                if ($this->containsVariableFunctionCall($argument, $variable)) {
                    return true;
                }
            }

            return false;
        }
        if (!$node instanceof BinaryOperationNode) {
            return false;
        }

        return $this->containsVariableFunctionCall($node->left, $variable)
            || $this->containsVariableFunctionCall($node->right, $variable);
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
        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                if ($this->containsVariable($argument, $variable)) {
                    return true;
                }
            }

            return false;
        }
        if (!$node instanceof BinaryOperationNode) {
            return false;
        }

        return $this->containsVariable($node->left, $variable)
            || $this->containsVariable($node->right, $variable);
    }

    /** @param list<array{string, string}>|null $polynomial */
    private function isIdenticallyZeroPolynomial(?array $polynomial): bool
    {
        if ($polynomial === null) {
            return false;
        }
        foreach ($polynomial as [$numerator]) {
            if ($numerator !== '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * Solve chained rational-polynomial equalities by taking the exact GCD of
     * their numerator polynomials and excluding every denominator root.
     *
     * @param array<string, int|float> $parameters
     */
    private function solveChainedRationalPolynomialEquation(
        string $equation,
        string $variable,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ComplexRationalSolution {
        $parts = \explode('=', $equation);
        $extractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $rootCounter = new ExactPolynomialRootCounter();
        $commonNumerator = null;
        $denominators = [];
        $partOffset = 0;

        for ($index = 0; $index + 1 < \count($parts); ++$index) {
            $leftText = $parts[$index];
            $rightText = $parts[$index + 1];
            $rightOffset = \strlen($leftText) + 1;
            $left = $this->parseSide($leftText, $partOffset, $evaluationOptions);
            $right = $this->parseSide($rightText, $partOffset + $rightOffset, $evaluationOptions);
            $rationalDifference = $extractor->rationalDifference($left, $right);
            if ($rationalDifference === null) {
                return new ComplexRationalSolution([], false, 0);
            }
            [$numerator, $denominator] = $rationalDifference;
            $denominators[] = $denominator;
            if ($numerator !== ['0']) {
                $commonNumerator = $commonNumerator === null
                    ? $numerator
                    : $rootCounter->greatestCommonDivisor($commonNumerator, $numerator);
                if ($commonNumerator === null) {
                    return new ComplexRationalSolution([], false, 0);
                }
            }
            $partOffset += $rightOffset;
        }

        if ($commonNumerator === null) {
            throw new InfiniteSolutionSetException(
                'The chained rational equation is an identity on its domain and has infinitely many solutions.',
            );
        }
        if (\count($commonNumerator) < 2) {
            return new ComplexRationalSolution([], true, 0);
        }

        foreach ($denominators as $denominator) {
            while (true) {
                $poleFactor = $rootCounter->greatestCommonDivisor($commonNumerator, $denominator);
                if ($poleFactor === null) {
                    return new ComplexRationalSolution([], false, 0);
                }
                if (\count($poleFactor) < 2) {
                    break;
                }
                $reducedNumerator = $rootCounter->dividePolynomialExactly($commonNumerator, $poleFactor);
                if ($reducedNumerator === null || \count($reducedNumerator) >= \count($commonNumerator)) {
                    return new ComplexRationalSolution([], false, 0);
                }
                $commonNumerator = $reducedNumerator;
            }
            if (\count($commonNumerator) < 2) {
                return new ComplexRationalSolution([], true, 0);
            }
        }

        $coefficients = (new IntegerPolynomialExtractor(
            '',
            [],
            $solverOptions->maxPolynomialDegree,
        ))->normalizedCoefficients($commonNumerator, true);
        if ($coefficients === null) {
            return new ComplexRationalSolution([], false, 0);
        }
        $solution = (new ComplexPolynomialSolver())->solve(
            $coefficients,
            $solverOptions,
            $commonNumerator,
        );

        return new ComplexRationalSolution(
            $solution->roots,
            $solution->converged,
            $solution->iterations,
        );
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

    private function parseSide(string $expression, int $offset, EvaluationOptions $options): \MathPHP\Ast\Node
    {
        try {
            return (new Parser(
                (new Lexer($expression, $options->limits))->tokenize(),
                $options->limits,
                $options->functions,
            ))->parse();
        } catch (LexicalException | ParseException $error) {
            if ($offset === 0) {
                throw $error;
            }
            $span = $error->span();
            throw new ParseException(
                $error->getMessage(),
                $error->errorCode(),
                new SourceSpan($span->start + $offset, $span->end + $offset),
                $error,
            );
        }
    }
}
