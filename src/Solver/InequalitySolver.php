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
use MathPHP\Ast\RationalLiteral;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\ParseException;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Source\SourceSpan;

final class InequalitySolver
{
    private const MAX_PIECEWISE_BOUNDARIES = 65_536;

    private const MAX_DISCRETE_DOMAIN_POINTS = 4_096;

    private const MAX_SMOOTH_MODULO_LEVELS = 4_096;

    private const MAX_STRUCTURAL_BOUNDARY_PROBES = 16_384;


    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    public function solve(
        string $inequality,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        if (\strlen($inequality) > $evaluationOptions->limits->maxExpressionLength) {
            $position = $evaluationOptions->limits->maxExpressionLength;
            throw new LexicalException(
                'Inequality exceeds the configured byte limit',
                'limit.expression_length',
                new SourceSpan($position, $position + 1),
            );
        }

        $this->validateInputs($variable, $lowerBound, $upperBound, $parameters);
        $chain = $this->splitChainedInequality($inequality);
        if ($chain !== null) {
            [$parts, $operators, $partOffsets] = $chain;
            $solutions = [new InequalityInterval($lowerBound, $upperBound, true, true)];
            foreach ($operators as $index => $operator) {
                try {
                    $linkSolutions = $this->solve(
                        $parts[$index] . $operator . $parts[$index + 1],
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (MathException $error) {
                    throw $this->shiftException($error, $partOffsets[$index]);
                }
                $solutions = $this->intersectIntervals($solutions, $linkSolutions);
                if ($solutions === []) {
                    return [];
                }
            }

            return $solutions;
        }
        [$leftExpression, $operator, $rightExpression, $operatorOffset] =
            $this->splitInequality($inequality);
        $rightOffset = \strlen($inequality) - \strlen($rightExpression);
        $left = $this->parseSide($leftExpression, 0, $evaluationOptions);
        $right = $this->parseSide($rightExpression, $rightOffset, $evaluationOptions);

        if (
            !$this->containsVariable($left, $variable)
            && !$this->containsVariable($right, $variable)
            && !$this->containsVariableCall($left, $variable, $evaluationOptions)
            && !$this->containsVariableCall($right, $variable, $evaluationOptions)
        ) {
            try {
                $leftValue = $this->evaluate($left, $parameters, $evaluationOptions);
                $rightValue = $this->evaluate($right, $parameters, $evaluationOptions);
            } catch (EvaluationException $error) {
                if (
                    \in_array(
                        $error->errorCode(),
                        [
                            'eval.domain',
                            'eval.division_by_zero',
                            'eval.modulo_by_zero',
                            'eval.non_finite',
                            'eval.integer_overflow',
                            'limit.factorial',
                        ],
                        true,
                    )
                ) {
                    return [];
                }

                throw $error;
            }
            $matches = match ($operator) {
                '<' => $leftValue < $rightValue,
                '<=' => $leftValue <= $rightValue,
                '!=' => ($leftValue <=> $rightValue) !== 0,
                '>' => $leftValue > $rightValue,
                '>=' => $leftValue >= $rightValue,
                default => throw new \LogicException('Unexpected inequality operator.'),
            };

            return $matches
                ? [new InequalityInterval($lowerBound, $upperBound, true, true)]
                : [];
        }

        $incompleteSpecialFunctionShapeSolutions = $this->solvePositiveIncompleteSpecialFunctionShapeInequality(
            $left,
            $right,
            $operator,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
        );
        if ($incompleteSpecialFunctionShapeSolutions !== null) {
            return $incompleteSpecialFunctionShapeSolutions;
        }
        $besselDomainSolutions = $this->solveBesselDomainInequality(
            $left,
            $right,
            $operator,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions,
        );
        if ($besselDomainSolutions !== null) {
            return $besselDomainSolutions;
        }

        if (!\is_finite($upperBound - $lowerBound)) {
            $wideIntervalExtractor = new PolynomialExtractor(
                $variable,
                $parameters,
                $evaluationOptions,
                $solverOptions->maxPolynomialDegree,
            );
            $widePolynomial = $this->polynomialDifferenceCoefficients(
                $left,
                $right,
                $wideIntervalExtractor,
            );
            $wideRational = null;
            $wideExactRational = null;
            if ($widePolynomial === null) {
                try {
                    $wideRational = $wideIntervalExtractor->extractRationalDifferenceForm(
                        $left,
                        $right,
                    );
                } catch (EvaluationException) {
                    $wideRational = null;
                }
                if ($wideRational === null) {
                    $wideExactRational = $wideIntervalExtractor->extractExactRationalDifference(
                        $left,
                        $right,
                    );
                }
            }
            if ($widePolynomial === null && $wideRational === null && $wideExactRational === null) {
                throw new \InvalidArgumentException(
                    'An inequality interval with non-finite width requires a polynomial or rational comparison.',
                );
            }
        }

        $moduloPreflightExtractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        [$hasUnboundedModuloDomain, $moduloPreflightPolynomials] = $this->moduloDomainPolynomials(
            $left,
            $variable,
            $moduloPreflightExtractor,
        );
        [$rightHasUnboundedModulo, $rightModuloPreflightPolynomials] = $this->moduloDomainPolynomials(
            $right,
            $variable,
            $moduloPreflightExtractor,
        );
        $hasUnboundedModuloDomain = $hasUnboundedModuloDomain || $rightHasUnboundedModulo;
        $moduloPreflightPolynomials = [
            ...$moduloPreflightPolynomials,
            ...$rightModuloPreflightPolynomials,
        ];
        if ($hasUnboundedModuloDomain && $moduloPreflightPolynomials === []) {
            $smoothModuloSolutions = $this->solveSmoothModuloInequalityDomain(
                $left,
                $right,
                $leftExpression,
                $rightExpression,
                $operator,
                $operatorOffset + \strlen($operator),
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($smoothModuloSolutions !== null) {
                return $smoothModuloSolutions;
            }
            throw new \DomainException(
                'The modulo expression integer domain could not be bounded for the requested interval.',
            );
        }

        $translatedEquation = $this->containsUnderflowedNumberLiteral($left)
            || $this->containsUnderflowedNumberLiteral($right)
            ? '(' . $leftExpression . ')-(' . $rightExpression . ')=0'
            : $leftExpression . '='
                . \str_repeat(' ', \strlen($operator) - 1)
                . $rightExpression;
        $roots = [];
        if (
            !$this->isCompletelyPartitionedQuantizerComparison(
                $left,
                $right,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            )
            && !(
                $this->isSyntacticZeroExpression($left)
                && $this->isSyntacticZeroExpression($right)
            )
        ) {
            try {
                $roots = (new EquationSolver())->solve(
                    $translatedEquation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (InfiniteRootAccumulationException $error) {
                throw $error;
            } catch (InfiniteSolutionSetException) {
                // Infinite equality regions can still define a finite interval set.
                $roots = [];
            } catch (RootSearchLimitException) {
                // Structural boundaries and interval samples still classify the inequality.
                $roots = [];
            } catch (\DomainException) {
                // An identity has no finite root list; scan partitions still classify it.
                $roots = [];
            }
        }

        $points = [$lowerBound, $upperBound];
        for ($index = 1; $index < $solverOptions->scanSteps; ++$index) {
            $points[] = $this->interpolate(
                $lowerBound,
                $upperBound,
                $index / $solverOptions->scanSteps,
            );
        }
        $discontinuityPoints = [];
        $extractor = new PolynomialExtractor(
            $variable,
            $parameters,
            $evaluationOptions,
            $solverOptions->maxPolynomialDegree,
        );
        $polynomialDifferenceCoefficients = $this->polynomialDifferenceCoefficients(
            $left,
            $right,
            $extractor,
        );
        $exactExtractor = new ExactRationalPolynomialExtractor(
            $variable,
            $parameters,
            $solverOptions->maxPolynomialDegree,
            $evaluationOptions,
        );
        $exactPolynomialDifferenceCoefficients = $exactExtractor->difference($left, $right);
        $exactRationalDifferenceForm = $exactExtractor->rationalDifference($left, $right);
        try {
            $rationalDifferenceForm = $extractor->extractRationalDifferenceForm(
                $left,
                $right,
            );
        } catch (EvaluationException) {
            $rationalDifferenceForm = null;
        }
        [$hasModuloDomain, $moduloPolynomials] = $this->moduloDomainPolynomials(
            $left,
            $variable,
            $extractor,
        );
        [$rightHasModulo, $rightModuloPolynomials] = $this->moduloDomainPolynomials(
            $right,
            $variable,
            $extractor,
        );
        $hasModuloDomain = $hasModuloDomain || $rightHasModulo;
        $moduloPolynomials = [...$moduloPolynomials, ...$rightModuloPolynomials];
        if ($hasModuloDomain && $moduloPolynomials === []) {
            $smoothModuloSolutions = $this->solveSmoothModuloInequalityDomain(
                $left,
                $right,
                $leftExpression,
                $rightExpression,
                $operator,
                $operatorOffset + \strlen($operator),
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
            if ($smoothModuloSolutions !== null) {
                return $smoothModuloSolutions;
            }
            throw new \DomainException(
                'The modulo expression integer domain could not be bounded for the requested interval.',
            );
        }
        $moduloDomainPoints = $hasModuloDomain
            ? $this->enumeratePolynomialModuloPoints(
                $moduloPolynomials,
                $lowerBound,
                $upperBound,
                $solverOptions,
            )
            : [];
        if ($hasModuloDomain && $moduloDomainPoints === []) {
            return [];
        }
        $moduloDomainPointTolerances = [];
        foreach ($moduloDomainPoints as [$point, $roundingUlps]) {
            $points[] = $point;
            $discontinuityPoints[$this->pointKey($point)] = true;
            $moduloDomainPointTolerances[$this->pointKey($point)] = $roundingUlps;
        }
        $piecewiseCandidatePoints = [];
        $piecewiseBoundaryOverrides = [];
        foreach (
            $this->piecewiseDiscontinuityPoints(
                $left,
                $leftExpression,
                $variable,
                $lowerBound,
                $upperBound,
                $extractor,
                $solverOptions,
                $parameters,
                $evaluationOptions,
                'left',
                $piecewiseCandidatePoints,
                $piecewiseBoundaryOverrides,
            ) as $point
        ) {
            $points[] = $point;
            $discontinuityPoints[$this->pointKey($point)] = true;
        }
        foreach (
            $this->piecewiseDiscontinuityPoints(
                $right,
                $rightExpression,
                $variable,
                $lowerBound,
                $upperBound,
                $extractor,
                $solverOptions,
                $parameters,
                $evaluationOptions,
                'right',
                $piecewiseCandidatePoints,
                $piecewiseBoundaryOverrides,
            ) as $point
        ) {
            $points[] = $point;
            $discontinuityPoints[$this->pointKey($point)] = true;
        }
        $rootPoints = [];
        $excludedPoints = [];
        $definedBoundaryPoints = [];
        foreach ($piecewiseCandidatePoints as $point) {
            $points[] = $point;
            try {
                $variables = [...$parameters, $variable => $point];
                $leftValue = $this->evaluate($left, $variables, $evaluationOptions);
                $rightValue = $this->evaluate($right, $variables, $evaluationOptions);
                $scale = \max(1.0, \abs($leftValue), \abs($rightValue));
                $exactSign = $exactPolynomialDifferenceCoefficients === null
                    ? null
                    : $this->exactPolynomialSignAt($exactPolynomialDifferenceCoefficients, $point);
                if (
                    $exactSign === 0
                    || (
                        $exactSign === null
                        && $exactPolynomialDifferenceCoefficients === null
                        && \abs($leftValue - $rightValue) <= $solverOptions->residualTolerance * $scale
                    )
                ) {
                    $rootPoints[$this->pointKey($point)] = true;
                }
            } catch (EvaluationException) {
                // Undefined candidates still split sampling intervals.
            }
        }
        foreach ($roots as $root) {
            $points[] = $root->value;
            $rootPoints[$this->pointKey($root->value)] = true;
        }
        $singularityEquations = \array_unique([
                ...$this->singularityEquations(
                    $left,
                    $leftExpression,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
                ...$this->singularityEquations(
                    $right,
                    $rightExpression,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
            ]);
        foreach ($singularityEquations as $singularityEquation) {
            if (\strlen($singularityEquation) > $evaluationOptions->limits->maxExpressionLength) {
                continue;
            }
            try {
                $singularities = (new EquationSolver())->solve(
                    $singularityEquation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (InfiniteRootAccumulationException $error) {
                throw $error;
            } catch (\DomainException) {
                continue;
            } catch (\InvalidArgumentException) {
                continue;
            }
            foreach ($singularities as $singularity) {
                $points[] = $singularity->value;
                $excludedPoints[$this->pointKey($singularity->value)] = true;
            }
        }
        $definedBoundaryEquations = \array_unique([
            ...$this->definedBoundaryEquations(
                $left,
                $leftExpression,
                $variable,
                $parameters,
                $evaluationOptions,
            ),
            ...$this->definedBoundaryEquations(
                $right,
                $rightExpression,
                $variable,
                $parameters,
                $evaluationOptions,
            ),
        ]);
        foreach ($definedBoundaryEquations as $boundaryEquation) {
            if (\strlen($boundaryEquation) > $evaluationOptions->limits->maxExpressionLength) {
                continue;
            }
            try {
                $boundaries = (new EquationSolver())->solve(
                    $boundaryEquation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (\DomainException | \InvalidArgumentException) {
                continue;
            }
            foreach ($boundaries as $boundary) {
                $points[] = $boundary->value;
                $definedBoundaryPoints[$this->pointKey($boundary->value)] = true;
            }
        }
        $discreteDomainEquations = \array_unique([
            ...$this->discreteDomainEquations(
                $left,
                $leftExpression,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            ),
            ...$this->discreteDomainEquations(
                $right,
                $rightExpression,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            ),
        ]);
        foreach ($discreteDomainEquations as $domainEquation) {
            if (\strlen($domainEquation) > $evaluationOptions->limits->maxExpressionLength) {
                continue;
            }
            try {
                $domainPoints = (new EquationSolver())->solve(
                    $domainEquation,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                );
            } catch (InfiniteSolutionSetException) {
                continue;
            }
            foreach ($domainPoints as $domainPoint) {
                $points[] = $domainPoint->value;
                $definedBoundaryPoints[$this->pointKey($domainPoint->value)] = true;
            }
        }
        $piecewiseBoundaryNodes = $this->piecewiseBoundaryNodes(
            $piecewiseBoundaryOverrides,
            $leftExpression,
            $rightExpression,
            $evaluationOptions,
        );
        if ($solverOptions->scanSteps === 1) {
            $structuralPoints = $definedBoundaryPoints + $excludedPoints + $discontinuityPoints;
            $probeBasePoints = $points;
            \sort($probeBasePoints, SORT_NUMERIC);
            $probeBasePoints = \array_values(\array_unique($probeBasePoints, SORT_REGULAR));
            $probeCount = 0;
            foreach ($probeBasePoints as $index => $point) {
                if (
                    $point === 0.0
                    && $index > 0
                    && isset($structuralPoints[$this->pointKey($point)])
                ) {
                    $segmentLeft = $probeBasePoints[$index - 1];
                    $width = $point - $segmentLeft;
                    for ($power = 1; $power <= 32 && $probeCount < self::MAX_STRUCTURAL_BOUNDARY_PROBES; ++$power) {
                        $probe = $point - ($width * (2.0 ** (-$power)));
                        if ($probe > $segmentLeft && $probe < $point) {
                            $points[] = $probe;
                            ++$probeCount;
                        }
                    }
                }
                if (
                    isset($structuralPoints[$this->pointKey($point)])
                    && $point === 0.0
                    && isset($probeBasePoints[$index + 1])
                ) {
                    $segmentRight = $probeBasePoints[$index + 1];
                    $width = $segmentRight - $point;
                    for ($power = 1; $power <= 32 && $probeCount < self::MAX_STRUCTURAL_BOUNDARY_PROBES; ++$power) {
                        $probe = $point + ($width * (2.0 ** (-$power)));
                        if ($probe > $point && $probe < $segmentRight) {
                            $points[] = $probe;
                            ++$probeCount;
                        }
                    }
                }
                if ($probeCount >= self::MAX_STRUCTURAL_BOUNDARY_PROBES) {
                    break;
                }
            }
        }
        \sort($points, SORT_NUMERIC);
        $points = \array_values(\array_unique($points, SORT_REGULAR));

        $pointMatches = [];
        foreach ($points as $point) {
            $key = $this->pointKey($point);
            $isRoot = isset($rootPoints[$key]);
            $boundaryNodes = $piecewiseBoundaryNodes[$key] ?? [];
            $matches = isset($excludedPoints[$key])
                ? false
                : ($isRoot
                    ? \in_array($operator, ['<=', '>='], true)
                    : $this->matchesAt(
                        $boundaryNodes['left'] ?? $left,
                        $boundaryNodes['right'] ?? $right,
                        $operator,
                        $variable,
                        $point,
                        $parameters,
                        $evaluationOptions,
                        $boundaryNodes['rightOffset'] ?? $rightOffset,
                        $moduloDomainPointTolerances[$key] ?? 0.0,
                    ));
            if (
                !$isRoot
                && !isset($excludedPoints[$key])
            ) {
                $sign = $exactPolynomialDifferenceCoefficients !== null
                    ? $this->exactPolynomialSignAt($exactPolynomialDifferenceCoefficients, $point)
                    : null;
                if (
                    $sign === null
                    && $polynomialDifferenceCoefficients !== null
                    && $this->differenceAt(
                        $left,
                        $right,
                        $variable,
                        $point,
                        $parameters,
                        $evaluationOptions,
                    ) === 0.0
                ) {
                    $sign = $this->polynomialSignAt($polynomialDifferenceCoefficients, $point);
                }
                if ($sign !== null) {
                    $matches = $this->signMatches($sign, $operator);
                }
            }
            if (
                !$isRoot
                && !isset($excludedPoints[$key])
                && ($rationalDifferenceForm !== null || $exactRationalDifferenceForm !== null)
                && $this->differenceAt(
                    $left,
                    $right,
                    $variable,
                    $point,
                    $parameters,
                    $evaluationOptions,
                ) === null
            ) {
                $sign = $exactRationalDifferenceForm === null
                    ? null
                    : $this->exactRationalSignAt($exactRationalDifferenceForm, $point);
                if ($sign === null && $rationalDifferenceForm !== null) {
                    $sign = $this->rationalSignAt($rationalDifferenceForm, $point);
                }
                if ($sign !== null) {
                    $matches = $this->signMatches($sign, $operator);
                }
            }
            $pointMatches[] = $matches;
        }

        $transitionPoints = [];
        $samplePointCount = \count($points);
        for ($index = 0; $index < $samplePointCount - 1; ++$index) {
            $leftPoint = $points[$index];
            $rightPoint = $points[$index + 1];
            $leftState = $pointMatches[$index];
            $rightState = $pointMatches[$index + 1];
            if (
                !\is_bool($leftState)
                || !\is_bool($rightState)
                || $leftState === $rightState
                || isset($rootPoints[$this->pointKey($leftPoint)])
                || isset($rootPoints[$this->pointKey($rightPoint)])
                || isset($excludedPoints[$this->pointKey($leftPoint)])
                || isset($excludedPoints[$this->pointKey($rightPoint)])
                || isset($definedBoundaryPoints[$this->pointKey($leftPoint)])
                || isset($definedBoundaryPoints[$this->pointKey($rightPoint)])
                || isset($discontinuityPoints[$this->pointKey($leftPoint)])
                || isset($discontinuityPoints[$this->pointKey($rightPoint)])
            ) {
                continue;
            }

            $transition = $this->bisectTransition(
                $left,
                $right,
                $operator,
                $variable,
                $leftPoint,
                $leftState,
                $rightPoint,
                $parameters,
                $evaluationOptions,
                $rightOffset,
                $solverOptions,
            );
            if ($transition === null) {
                continue;
            }

            $points[] = $transition;
            $residual = $this->differenceAt(
                $left,
                $right,
                $variable,
                $transition,
                $parameters,
                $evaluationOptions,
            );
            $transitionPoints[$this->pointKey($transition)] = $residual !== null
                && \abs($residual) <= $solverOptions->residualTolerance
                && \in_array($operator, ['<=', '>='], true);
        }

        if ($transitionPoints !== []) {
            \sort($points, SORT_NUMERIC);
            $points = \array_values(\array_unique($points, SORT_REGULAR));
            $pointMatches = [];
            foreach ($points as $point) {
                $key = $this->pointKey($point);
                $boundaryNodes = $piecewiseBoundaryNodes[$key] ?? [];
                $pointMatches[] = isset($excludedPoints[$key])
                    ? false
                    : (isset($rootPoints[$key])
                        ? \in_array($operator, ['<=', '>='], true)
                        : (\array_key_exists($key, $transitionPoints)
                        ? $transitionPoints[$key]
                        : $this->matchesAt(
                            $boundaryNodes['left'] ?? $left,
                            $boundaryNodes['right'] ?? $right,
                            $operator,
                            $variable,
                            $point,
                            $parameters,
                            $evaluationOptions,
                            $boundaryNodes['rightOffset'] ?? $rightOffset,
                            $moduloDomainPointTolerances[$key] ?? 0.0,
                        )));
            }
        }

        $intervals = [];
        for ($index = 0; $index < \count($points) - 1; ++$index) {
            $leftPoint = $points[$index];
            $rightPoint = $points[$index + 1];
            $middle = $this->midpoint($leftPoint, $rightPoint);
            if ($middle === $leftPoint || $middle === $rightPoint) {
                $matchesInside = null;
                if (
                    isset($rootPoints[$this->pointKey($leftPoint)])
                    && isset($rootPoints[$this->pointKey($rightPoint)])
                ) {
                    $matchesInside = $polynomialDifferenceCoefficients !== null
                        ? $this->polynomialMatchesBetweenRoots(
                            $polynomialDifferenceCoefficients,
                            $leftPoint,
                            $rightPoint,
                            $operator,
                        )
                        : ($rationalDifferenceForm === null
                            ? null
                            : $this->rationalMatchesBetweenRoots(
                                $rationalDifferenceForm,
                                $leftPoint,
                                $rightPoint,
                                $operator,
                            ));
                } elseif (
                    !isset($discontinuityPoints[$this->pointKey($leftPoint)])
                    && !isset($discontinuityPoints[$this->pointKey($rightPoint)])
                    && !isset($definedBoundaryPoints[$this->pointKey($leftPoint)])
                    && !isset($definedBoundaryPoints[$this->pointKey($rightPoint)])
                ) {
                    if (
                        isset($excludedPoints[$this->pointKey($leftPoint)])
                        && $rationalDifferenceForm !== null
                    ) {
                        $matchesInside = $this->rationalMatchesAdjacentToPole(
                            $rationalDifferenceForm,
                            $leftPoint,
                            $rightPoint,
                            1,
                            $operator,
                        );
                    } elseif (
                        isset($excludedPoints[$this->pointKey($rightPoint)])
                        && $rationalDifferenceForm !== null
                    ) {
                        $matchesInside = $this->rationalMatchesAdjacentToPole(
                            $rationalDifferenceForm,
                            $rightPoint,
                            $leftPoint,
                            -1,
                            $operator,
                        );
                    } elseif (
                        !isset($excludedPoints[$this->pointKey($leftPoint)])
                        && !isset($excludedPoints[$this->pointKey($rightPoint)])
                        && $polynomialDifferenceCoefficients !== null
                        && isset($rootPoints[$this->pointKey($leftPoint)])
                    ) {
                        $sign = $this->polynomialSignAdjacentToRoot(
                            $polynomialDifferenceCoefficients,
                            $leftPoint,
                            1,
                        );
                        $matchesInside = $sign === null ? null : $this->signMatches($sign, $operator);
                    } elseif (
                        !isset($excludedPoints[$this->pointKey($leftPoint)])
                        && !isset($excludedPoints[$this->pointKey($rightPoint)])
                        && $polynomialDifferenceCoefficients !== null
                        && isset($rootPoints[$this->pointKey($rightPoint)])
                    ) {
                        $sign = $this->polynomialSignAdjacentToRoot(
                            $polynomialDifferenceCoefficients,
                            $rightPoint,
                            -1,
                        );
                        $matchesInside = $sign === null ? null : $this->signMatches($sign, $operator);
                    } elseif ($pointMatches[$index] === $pointMatches[$index + 1]) {
                        $matchesInside = $pointMatches[$index];
                    } elseif (isset($rootPoints[$this->pointKey($leftPoint)])) {
                        $matchesInside = $pointMatches[$index + 1];
                    } elseif (isset($rootPoints[$this->pointKey($rightPoint)])) {
                        $matchesInside = $pointMatches[$index];
                    }
                }
                if ($matchesInside === true) {
                    $intervals[] = new InequalityInterval(
                        $leftPoint,
                        $rightPoint,
                        $pointMatches[$index] === true,
                        $pointMatches[$index + 1] === true,
                    );
                }
                continue;
            }

            $middleSign = $exactPolynomialDifferenceCoefficients === null
                ? null
                : $this->exactPolynomialSignAt($exactPolynomialDifferenceCoefficients, $middle);
            if ($middleSign === null && $exactRationalDifferenceForm !== null) {
                $middleSign = $this->exactRationalSignAt($exactRationalDifferenceForm, $middle);
            }
            $matchesInside = $middleSign === null
                ? $this->matchesAt(
                    $left,
                    $right,
                    $operator,
                    $variable,
                    $middle,
                    $parameters,
                    $evaluationOptions,
                    $rightOffset,
                )
                : $this->signMatches($middleSign, $operator);
            if (!$matchesInside) {
                continue;
            }

            $intervals[] = new InequalityInterval(
                $leftPoint,
                $rightPoint,
                $pointMatches[$index] === true,
                $pointMatches[$index + 1] === true,
            );
        }

        foreach ($points as $index => $point) {
            if ($pointMatches[$index] !== true) {
                continue;
            }

            $covered = false;
            foreach ($intervals as $interval) {
                if (
                    ($interval->lowerBound === $point && $interval->includesLowerBound)
                    || ($interval->upperBound === $point && $interval->includesUpperBound)
                ) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $intervals[] = new InequalityInterval($point, $point, true, true);
            }
        }

        return $this->merge($intervals);
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveSmoothModuloInequalityDomain(
        Node $left,
        Node $right,
        string $leftSource,
        string $rightSource,
        string $operator,
        int $rightOffset,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): ?array {
        foreach (
            [
                [$left, $leftSource, 0, $right, $rightSource, 0],
                [$right, $rightSource, 0, $left, $leftSource, 0],
            ] as [
                $moduloSide,
                $moduloSource,
                $moduloBase,
                $targetSide,
                $targetSource,
                $targetBase,
            ]
        ) {
            while ($moduloSide instanceof GroupingNode) {
                $moduloSide = $moduloSide->expression;
            }
            if (
                !$moduloSide instanceof BinaryOperationNode
                || $moduloSide->operator !== BinaryOperator::Modulo
                || !$this->containsVariable($moduloSide, $variable)
                || $this->containsVariable($targetSide, $variable)
                || !$this->isSmoothModuloInequalityOperand($moduloSide->left, $evaluationOptions)
                || $this->containsVariable($moduloSide->right, $variable)
            ) {
                continue;
            }

            $numeratorRange = $this->boundedExpressionRange(
                $moduloSide->left,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
            $numeratorSource = $this->nodeSource($moduloSide->left, $moduloSource, $moduloBase);
            $divisorSource = $this->nodeSource($moduloSide->right, $moduloSource, $moduloBase);
            $targetText = $this->nodeSource($targetSide, $targetSource, $targetBase);
            if (
                $numeratorRange === null
                || $numeratorSource === null
                || $divisorSource === null
                || $targetText === null
            ) {
                continue;
            }

            try {
                $divisor = $this->evaluate($moduloSide->right, $parameters, $evaluationOptions);
                $target = $this->evaluate($targetSide, $parameters, $evaluationOptions);
            } catch (EvaluationException) {
                continue;
            }
            if (
                !\is_finite((float) $divisor)
                || (float) $divisor === 0.0
                || (float) $divisor !== \floor((float) $divisor)
                || !\is_finite((float) $target)
            ) {
                continue;
            }

            $firstLevel = \ceil($numeratorRange[0]);
            $lastLevel = \floor($numeratorRange[1]);
            if (
                !\is_finite($firstLevel)
                || !\is_finite($lastLevel)
                || $lastLevel < $firstLevel
                || $lastLevel - $firstLevel + 1 > self::MAX_SMOOTH_MODULO_LEVELS
            ) {
                continue;
            }

            $candidates = [];
            for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
                try {
                    $levelRoots = (new EquationSolver())->solve(
                        $numeratorSource . ' = ' . \sprintf('%.17g', $level),
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    );
                } catch (InfiniteSolutionSetException | \DomainException) {
                    return null;
                }
                foreach ($levelRoots as $root) {
                    $candidates[$this->pointKey($root->value)] = $root->value;
                }
            }

            $intervals = [];
            foreach ($candidates as $candidate) {
                $matches = $this->matchesAt(
                    $left,
                    $right,
                    $operator,
                    $variable,
                    $candidate,
                    $parameters,
                    $evaluationOptions,
                    $rightOffset,
                    4.0,
                );
                if ($matches === true) {
                    $intervals[] = new InequalityInterval($candidate, $candidate, true, true);
                }
            }

            return $this->merge($intervals);
        }

        return null;
    }

    private function isSmoothModuloInequalityOperand(Node $node, EvaluationOptions $options): bool
    {
        if ($node instanceof NumberNode || $node instanceof ConstantNode || $node instanceof VariableNode) {
            return true;
        }
        if ($node instanceof GroupingNode) {
            return $this->isSmoothModuloInequalityOperand($node->expression, $options);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isSmoothModuloInequalityOperand($node->operand, $options);
        }
        if ($node instanceof BinaryOperationNode) {
            return $node->operator !== BinaryOperator::Modulo
                && $this->isSmoothModuloInequalityOperand($node->left, $options)
                && $this->isSmoothModuloInequalityOperand($node->right, $options);
        }
        if ($node instanceof FunctionCallNode) {
            if (
                !$options->functions->isBuiltIn($node->name)
                || !\in_array($node->name, [
                    'abs', 'sqrt', 'cbrt', 'exp', 'expm1', 'ln', 'log', 'log10', 'log2', 'log1p',
                    'sin', 'cos', 'tan', 'sec', 'csc', 'cot', 'sinh', 'cosh', 'tanh', 'sech',
                    'csch', 'coth', 'asinh', 'acosh', 'atanh', 'asin', 'acos', 'atan', 'asec',
                    'acsc', 'acot', 'erf', 'erfc', 'sinc', 'sincpi',
                ], true)
            ) {
                return false;
            }
            foreach ($node->arguments as $argument) {
                if (!$this->isSmoothModuloInequalityOperand($argument, $options)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function nodeSource(Node $node, string $source, int $baseOffset): ?string
    {
        $span = $node->span();
        $start = $span->start - $baseOffset;
        $length = $span->end - $span->start;
        if ($start < 0 || $length < 0 || $start + $length > \strlen($source)) {
            return null;
        }

        return \substr($source, $start, $length);
    }

    /** @return array{string, string, string, int} */
    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solvePositiveIncompleteSpecialFunctionShapeInequality(
        Node $left,
        Node $right,
        string $operator,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?array {
        foreach ([[$left, $right]] as [$call, $target]) {
            while ($call instanceof GroupingNode) {
                $call = $call->expression;
            }
            while ($target instanceof GroupingNode) {
                $target = $target->expression;
            }
            if (
                !$call instanceof FunctionCallNode
                || $call->arguments === []
                || !$call->arguments[0] instanceof VariableNode
                || $call->arguments[0]->name !== $variable
                || !$evaluationOptions->functions->isBuiltIn($call->name)
            ) {
                continue;
            }
            if (
                $this->containsVariable($target, $variable)
                || $this->containsVariableCall($target, $variable, $evaluationOptions)
            ) {
                continue;
            }
            try {
                $targetValue = (float) $this->evaluate($target, $parameters, $evaluationOptions);
                if ($targetValue !== 0.0) {
                    continue;
                }
                if (\in_array($call->name, ['gammainc', 'gammaincc'], true) && \count($call->arguments) === 2) {
                    $shapeWitness = (float) $this->evaluate(
                        $call->arguments[1],
                        $parameters,
                        $evaluationOptions,
                    );
                    if ($this->containsVariableCall($call->arguments[1], $variable, $evaluationOptions)) {
                        continue;
                    }
                    $validShapeWitness = \is_finite($shapeWitness) && $shapeWitness > 0.0;
                } elseif (
                    \in_array($call->name, ['betainc', 'betaincc'], true)
                    && \count($call->arguments) === 3
                    && !$this->containsVariable($call->arguments[1], $variable)
                    && !$this->containsVariable($call->arguments[2], $variable)
                    && !$this->containsVariableCall($call->arguments[1], $variable, $evaluationOptions)
                    && !$this->containsVariableCall($call->arguments[2], $variable, $evaluationOptions)
                ) {
                    $secondShape = (float) $this->evaluate(
                        $call->arguments[1],
                        $parameters,
                        $evaluationOptions,
                    );
                    $valueArgument = (float) $this->evaluate(
                        $call->arguments[2],
                        $parameters,
                        $evaluationOptions,
                    );
                    $validShapeWitness = \is_finite($secondShape)
                        && $secondShape > 0.0
                        && \is_finite($valueArgument)
                        && $valueArgument > 0.0
                        && $valueArgument < 1.0;
                } else {
                    continue;
                }
            } catch (EvaluationException) {
                continue;
            }
            if (!$validShapeWitness) {
                continue;
            }

            if (\in_array($operator, ['<', '<='], true)) {
                return [];
            }
            if (!\in_array($operator, ['>', '>=', '!='], true) || $upperBound <= 0.0) {
                return [];
            }

            $intervalLower = \max(0.0, $lowerBound);

            return [new InequalityInterval(
                $intervalLower,
                $upperBound,
                $intervalLower > 0.0,
                true,
            )];
        }

        return null;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>|null
     */
    private function solveBesselDomainInequality(
        Node $left,
        Node $right,
        string $operator,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): ?array {
        $expression = $left;
        $threshold = $right;
        while ($expression instanceof GroupingNode) {
            $expression = $expression->expression;
        }
        while ($threshold instanceof GroupingNode) {
            $threshold = $threshold->expression;
        }
        $isSquared = false;
        if ($expression instanceof BinaryOperationNode && $expression->operator->value === '^') {
            $exponent = $expression->right;
            while ($exponent instanceof GroupingNode) {
                $exponent = $exponent->expression;
            }
            if ($exponent instanceof NumberNode && (float) $exponent->value === 2.0) {
                $isSquared = true;
                $expression = $expression->left;
                while ($expression instanceof GroupingNode) {
                    $expression = $expression->expression;
                }
            }
        }
        if (
            !$expression instanceof FunctionCallNode
            || !$evaluationOptions->functions->isBuiltIn($expression->name)
            || !\in_array($expression->name, ['besselj', 'besseli', 'bessely'], true)
            || \count($expression->arguments) !== 2
            || !$expression->arguments[1] instanceof VariableNode
            || $expression->arguments[1]->name !== $variable
            || $this->containsVariable($expression->arguments[0], $variable)
            || $this->containsVariableCall($expression->arguments[0], $variable, $evaluationOptions)
        ) {
            return null;
        }
        try {
            $order = (float) $this->evaluate($expression->arguments[0], $parameters, $evaluationOptions);
            $target = (float) $this->evaluate($threshold, $parameters, $evaluationOptions);
        } catch (EvaluationException) {
            return null;
        }
        if (!\is_finite($order) || $order !== 0.0 || $target !== 0.0) {
            return null;
        }

        if ($isSquared && \in_array($expression->name, ['besselj', 'bessely'], true)) {
            if ($operator !== '>=') {
                return \in_array($operator, ['<', '<='], true) ? [] : null;
            }
        } elseif (!$isSquared && $expression->name === 'besseli') {
            if (\in_array($operator, ['>', '>=', '!='], true)) {
                // I_0 is strictly positive throughout its supported real domain.
            } elseif (\in_array($operator, ['<', '<='], true)) {
                return [];
            } else {
                return null;
            }
        } else {
            return null;
        }

        $domainLower = match ($expression->name) {
            'besselj' => -SpecialFunctions::MAX_BESSEL_J_ARGUMENT,
            'besseli' => -SpecialFunctions::MAX_BESSEL_I_ARGUMENT,
            'bessely' => 0.0,
        };
        $domainUpper = match ($expression->name) {
            'besselj' => SpecialFunctions::MAX_BESSEL_J_ARGUMENT,
            'besseli' => SpecialFunctions::MAX_BESSEL_I_ARGUMENT,
            'bessely' => SpecialFunctions::MAX_BESSEL_Y_ARGUMENT,
        };
        $intervalLower = \max($lowerBound, $domainLower);
        $intervalUpper = \min($upperBound, $domainUpper);
        if ($intervalLower > $intervalUpper) {
            return [];
        }
        $includesLower = $expression->name !== 'bessely' || $intervalLower > 0.0;
        if ($intervalLower === $intervalUpper && !$includesLower) {
            return [];
        }

        return [new InequalityInterval($intervalLower, $intervalUpper, $includesLower, true)];
    }

    /** @return array{string, string, string, int} */
    private function splitInequality(string $inequality): array
    {
        \preg_match_all('/<=|>=|!=|≤|≥|≠|<|>/', $inequality, $matches, PREG_OFFSET_CAPTURE);
        if (\count($matches[0]) !== 1) {
            $secondOperator = $matches[0][1] ?? null;
            $offset = $secondOperator !== null
                ? $secondOperator[1]
                : \strlen($inequality);
            $length = $secondOperator !== null
                ? \strlen($secondOperator[0])
                : 0;
            throw new ParseException(
                'Inequality must contain exactly one comparison operator',
                'parse.inequality_operator',
                new SourceSpan($offset, $offset + $length),
            );
        }

        [$sourceOperator, $offset] = $matches[0][0];
        $left = \substr($inequality, 0, $offset);
        $right = \substr($inequality, $offset + \strlen($sourceOperator));
        if (\trim($left) === '' || \trim($right) === '') {
            throw new ParseException(
                'Both sides of the inequality must contain an expression',
                'parse.empty_inequality_side',
                new SourceSpan($offset, $offset + \strlen($sourceOperator)),
            );
        }
        $operator = match ($sourceOperator) {
            '≤' => '<=',
            '≥' => '>=',
            '≠' => '!=',
            default => $sourceOperator,
        };

        return [$left, $operator, $right, $offset];
    }

    /**
     * @return array{list<string>, list<string>, list<int>}|null
     */
    private function splitChainedInequality(string $inequality): ?array
    {
        \preg_match_all('/<=|>=|!=|≤|≥|≠|<|>/', $inequality, $matches, PREG_OFFSET_CAPTURE);
        if (\count($matches[0]) < 2) {
            return null;
        }

        $parts = [];
        $operators = [];
        $partOffsets = [];
        $cursor = 0;
        foreach ($matches[0] as [$operator, $offset]) {
            $part = \substr($inequality, $cursor, $offset - $cursor);
            if (\trim($part) === '') {
                throw new ParseException(
                    'Both sides of every chained inequality operator must contain an expression',
                    'parse.empty_inequality_side',
                    new SourceSpan($offset, $offset + \strlen($operator)),
                );
            }
            $parts[] = $part;
            $partOffsets[] = $cursor;
            $operators[] = $operator;
            $cursor = $offset + \strlen($operator);
        }
        $lastPart = \substr($inequality, $cursor);
        if (\trim($lastPart) === '') {
            $lastOperator = $matches[0][\count($matches[0]) - 1];
            throw new ParseException(
                'Both sides of every chained inequality operator must contain an expression',
                'parse.empty_inequality_side',
                new SourceSpan($lastOperator[1], $lastOperator[1] + \strlen($lastOperator[0])),
            );
        }
        $parts[] = $lastPart;
        $partOffsets[] = $cursor;

        return [$parts, $operators, $partOffsets];
    }

    /**
     * @param list<InequalityInterval> $left
     * @param list<InequalityInterval> $right
     * @return list<InequalityInterval>
     */
    private function intersectIntervals(array $left, array $right): array
    {
        $intersections = [];
        $leftIndex = 0;
        $rightIndex = 0;
        while (isset($left[$leftIndex], $right[$rightIndex])) {
            $leftInterval = $left[$leftIndex];
            $rightInterval = $right[$rightIndex];
            if ($leftInterval->lowerBound > $rightInterval->lowerBound) {
                $lowerBound = $leftInterval->lowerBound;
                $includesLowerBound = $leftInterval->includesLowerBound;
            } elseif ($leftInterval->lowerBound < $rightInterval->lowerBound) {
                $lowerBound = $rightInterval->lowerBound;
                $includesLowerBound = $rightInterval->includesLowerBound;
            } else {
                $lowerBound = $leftInterval->lowerBound;
                $includesLowerBound = $leftInterval->includesLowerBound
                    && $rightInterval->includesLowerBound;
            }

            if ($leftInterval->upperBound < $rightInterval->upperBound) {
                $upperBound = $leftInterval->upperBound;
                $includesUpperBound = $leftInterval->includesUpperBound;
            } elseif ($leftInterval->upperBound > $rightInterval->upperBound) {
                $upperBound = $rightInterval->upperBound;
                $includesUpperBound = $rightInterval->includesUpperBound;
            } else {
                $upperBound = $leftInterval->upperBound;
                $includesUpperBound = $leftInterval->includesUpperBound
                    && $rightInterval->includesUpperBound;
            }

            if ($lowerBound < $upperBound) {
                $intersections[] = new InequalityInterval(
                    $lowerBound,
                    $upperBound,
                    $includesLowerBound,
                    $includesUpperBound,
                );
            } elseif (
                $lowerBound === $upperBound
                && $includesLowerBound
                && $includesUpperBound
            ) {
                $intersections[] = new InequalityInterval($lowerBound, $upperBound, true, true);
            }

            if ($leftInterval->upperBound <= $rightInterval->upperBound) {
                ++$leftIndex;
            }
            if ($rightInterval->upperBound <= $leftInterval->upperBound) {
                ++$rightIndex;
            }
        }

        return $this->merge($intersections);
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<string> Equations whose roots are excluded from the expression domain.
     */
    private function singularityEquations(
        Node $node,
        string $source,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
        SolverOptions $solverOptions,
    ): array {
        $equations = [];
        if ($node instanceof BinaryOperationNode) {
            if (
                $node->operator->value === '/'
                && $this->containsVariable($node->right, $variable)
            ) {
                $equations[] = $this->equationForNode($node->right, $source, '0');
            }
            if (
                $node->operator->value === '^'
                && !$this->containsVariable($node->right, $variable)
                && $this->containsVariable($node->left, $variable)
            ) {
                try {
                    $exponent = $this->evaluate($node->right, $parameters, $options);
                    if ($exponent < 0) {
                        $equations[] = $this->equationForNode($node->left, $source, '0');
                    }
                } catch (EvaluationException) {
                    // An invalid constant exponent is handled by normal evaluation.
                }
            }
            $equations = [
                ...$equations,
                ...$this->singularityEquations(
                    $node->left,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                    $solverOptions,
                ),
                ...$this->singularityEquations(
                    $node->right,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                    $solverOptions,
                ),
            ];
        } elseif ($node instanceof UnaryOperationNode) {
            $equations = $this->singularityEquations(
                $node->operand,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
                $solverOptions,
            );
        } elseif ($node instanceof GroupingNode) {
            $equations = $this->singularityEquations(
                $node->expression,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
                $solverOptions,
            );
        } elseif ($node instanceof FactorialNode) {
            $equations = $this->singularityEquations(
                $node->operand,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
                $solverOptions,
            );
        } elseif ($node instanceof FunctionCallNode) {
            $arguments = [];
            foreach ($node->arguments as $argument) {
                $arguments[] = \substr(
                    $source,
                    $argument->span()->start,
                    $argument->span()->end - $argument->span()->start,
                );
                $equations = [
                    ...$equations,
                    ...$this->singularityEquations(
                        $argument,
                        $source,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $options,
                        $solverOptions,
                    ),
                ];
            }

            $conditions = match ($node->name) {
                'csch', 'coth', 'acsch', 'asech' => [[0, '0']],
                'acoth', 'atanh' => [[0, '-1'], [0, '1']],
                'erfinv' => [[0, '-1'], [0, '1']],
                'erfcinv' => [[0, '0'], [0, '2']],
                'ln', 'log10', 'log2' => [[0, '0']],
                'log1p' => [[0, '-1']],
                'log' => [[0, '0'], [1, '0'], [1, '1']],
                'zeta' => [[0, '1']],
                'tan', 'sec' => isset($arguments[0])
                    ? [['cos(' . $arguments[0] . ')', '0']]
                    : [],
                'csc', 'cot' => isset($arguments[0])
                    ? [['sin(' . $arguments[0] . ')', '0']]
                    : [],
                default => [],
            };
            if ($node->name === 'lambertw') {
                $branch = $this->lambertWBranch($node, $parameters, $options);
                if ($branch === -1.0) {
                    $conditions = [[0, '0']];
                }
            }
            foreach ($conditions as [$argumentIndex, $value]) {
                $argumentNode = \is_int($argumentIndex)
                    ? ($node->arguments[$argumentIndex] ?? null)
                    : ($node->arguments[0] ?? null);
                if ($argumentNode === null || !$this->containsVariable($argumentNode, $variable)) {
                    continue;
                }
                $expression = \is_int($argumentIndex)
                    ? ($arguments[$argumentIndex] ?? null)
                    : $argumentIndex;
                if ($expression !== null) {
                    $equations[] = $expression . '=' . $value;
                }
            }

            if (
                $node->name === 'root'
                && isset($node->arguments[0], $node->arguments[1], $arguments[0])
                && !$this->containsVariable($node->arguments[1], $variable)
                && $this->containsVariable($node->arguments[0], $variable)
            ) {
                try {
                    $degree = $this->evaluate($node->arguments[1], $parameters, $options);
                    if (
                        $degree < 0
                        && \floor((float) $degree) === (float) $degree
                        && \abs($degree) <= 9_007_199_254_740_991.0
                    ) {
                        $equations[] = $arguments[0] . '=0';
                    }
                } catch (EvaluationException) {
                    // An invalid degree is handled by ordinary evaluation.
                }
            }

            if (
                \in_array(
                    $node->name,
                    ['gamma', 'lgamma', 'digamma', 'trigamma', 'tetragamma', 'polygamma'],
                    true,
                )
                && isset($node->arguments[$node->name === 'polygamma' ? 1 : 0])
                && isset($arguments[$node->name === 'polygamma' ? 1 : 0])
                && $this->containsVariable(
                    $node->arguments[$node->name === 'polygamma' ? 1 : 0],
                    $variable,
                )
            ) {
                $specialArgumentIndex = $node->name === 'polygamma' ? 1 : 0;
                $specialArgument = $node->arguments[$specialArgumentIndex];
                $specialArgumentSource = $arguments[$specialArgumentIndex];
                $argumentCoefficients = (new PolynomialExtractor(
                    $variable,
                    $parameters,
                    $options,
                    $solverOptions->maxPolynomialDegree,
                ))->extract($specialArgument);
                $argumentRange = $argumentCoefficients === null
                    ? null
                    : $this->polynomialRange(
                        $argumentCoefficients,
                        $lowerBound,
                        $upperBound,
                        $solverOptions,
                    );
                if (
                    $argumentRange === null
                    && \in_array($node->name, ['digamma', 'trigamma', 'tetragamma', 'polygamma'], true)
                    && (
                        $this->unboundedLogDirection(
                            $specialArgument,
                            $variable,
                            $lowerBound,
                            $upperBound,
                            $parameters,
                            $options,
                        ) < 0
                        || $this->unboundedReciprocalDirection(
                            $specialArgument,
                            $variable,
                            $lowerBound,
                            $upperBound,
                            $parameters,
                            $options,
                            $solverOptions,
                        ) < 0
                    )
                ) {
                    throw new InfiniteSolutionSetException(
                        'Polygamma poles accumulate at a domain boundary; infinitely many intervals are required.',
                    );
                }
                if (
                    $argumentRange === null
                ) {
                    $argumentRange = $this->boundedExpressionRange(
                        $specialArgument,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $options,
                    );
                }
                if ($argumentRange === null && \in_array($node->name, ['gamma', 'lgamma'], true)) {
                    $argumentRange = [-171.0, 171.0];
                }
                $minimum = null;
                $maximum = null;
                if ($argumentRange !== null) {
                    [$minimum, $maximum] = $argumentRange;
                } else {
                    $orientation = $this->signedVariableOrientation(
                        $specialArgument,
                        $variable,
                    );
                    if ($orientation !== null) {
                        $minimum = \min(
                            $orientation * $lowerBound,
                            $orientation * $upperBound,
                        );
                        $maximum = \max(
                            $orientation * $lowerBound,
                            $orientation * $upperBound,
                        );
                    }
                }

                if ($minimum !== null && $maximum !== null) {
                    $rangeTolerance = 16.0 * \PHP_FLOAT_EPSILON
                        * \max(1.0, \abs($minimum), \abs($maximum));
                    $first = \ceil($minimum - $rangeTolerance);
                    $last = \min(0.0, \floor($maximum + $rangeTolerance));
                    if ($node->name === 'gamma' || $node->name === 'lgamma') {
                        $first = \max(-171.0, $first);
                    }

                    if ($first <= $last) {
                        if ($last - $first + 1.0 > self::MAX_PIECEWISE_BOUNDARIES) {
                            throw new \DomainException(
                                'The special-function pole set exceeds the inequality boundary limit.',
                            );
                        }

                        for ($pole = (int) $first; $pole <= (int) $last; ++$pole) {
                            $equations[] = $specialArgumentSource . '=' . $pole;
                        }
                    }
                }
            }

            if ($node->name === 'beta') {
                foreach ($node->arguments as $index => $argument) {
                    if (!$this->containsVariable($argument, $variable)) {
                        continue;
                    }
                    $range = $this->boundedExpressionRange(
                        $argument,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $options,
                    );
                    if ($range === null || !\is_finite($range[0]) || !\is_finite($range[1])) {
                        $firstPole = -171.0;
                        $lastPole = 0.0;
                    } else {
                        [$minimum, $maximum] = $range;
                        $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                            * \max(1.0, \abs($minimum), \abs($maximum));
                        $firstPole = \max(-171.0, \ceil($minimum - $tolerance));
                        $lastPole = \min(0.0, \floor($maximum + $tolerance));
                    }
                    if ($lastPole >= $firstPole) {
                        if ($lastPole - $firstPole + 1.0 > self::MAX_PIECEWISE_BOUNDARIES) {
                            throw new \DomainException(
                                'The beta-function pole set exceeds the inequality boundary limit.',
                            );
                        }
                        for ($pole = (int) $firstPole; $pole <= (int) $lastPole; ++$pole) {
                            $equations[] = $arguments[$index] . '=' . $pole;
                        }
                    }
                }
            }
        }

        return $equations;
    }

    /**
     * @return list<string> Equations for included endpoints of restricted domains.
     * @param array<string, int|float> $parameters
     */
    private function definedBoundaryEquations(
        Node $node,
        string $source,
        string $variable,
        array $parameters,
        EvaluationOptions $options,
    ): array {
        $equations = [];
        if ($node instanceof BinaryOperationNode) {
            return [
                ...$this->definedBoundaryEquations(
                    $node->left,
                    $source,
                    $variable,
                    $parameters,
                    $options,
                ),
                ...$this->definedBoundaryEquations(
                    $node->right,
                    $source,
                    $variable,
                    $parameters,
                    $options,
                ),
            ];
        }
        if (
            $node instanceof UnaryOperationNode
            || $node instanceof GroupingNode
            || $node instanceof FactorialNode
        ) {
            $child = match (true) {
                $node instanceof UnaryOperationNode => $node->operand,
                $node instanceof GroupingNode => $node->expression,
                default => $node->operand,
            };

            return $this->definedBoundaryEquations(
                $child,
                $source,
                $variable,
                $parameters,
                $options,
            );
        }
        if (!$node instanceof FunctionCallNode) {
            return [];
        }

        if (\in_array($node->name, ['gammainc', 'gammaincc', 'gammaincinv', 'gammainccinv'], true)) {
            foreach ($node->arguments as $index => $argument) {
                if (!$this->containsVariable($argument, $variable)) {
                    continue;
                }
                $expression = \substr(
                    $source,
                    $argument->span()->start,
                    $argument->span()->end - $argument->span()->start,
                );
                $equations[] = $expression . '=0';
                if ($index === 1 && \str_ends_with($node->name, 'inv')) {
                    $equations[] = $expression . '=1';
                }
            }
        } elseif (
            \in_array($node->name, ['betainc', 'betaincc', 'betaincinv', 'betainccinv'], true)
        ) {
            foreach ([0, 1] as $shapeIndex) {
                $shape = $node->arguments[$shapeIndex] ?? null;
                if ($shape === null || !$this->containsVariable($shape, $variable)) {
                    continue;
                }
                $equations[] = \substr(
                    $source,
                    $shape->span()->start,
                    $shape->span()->end - $shape->span()->start,
                ) . '=0';
            }
            $value = $node->arguments[2] ?? null;
            if ($value !== null && $this->containsVariable($value, $variable)) {
                $expression = \substr(
                    $source,
                    $value->span()->start,
                    $value->span()->end - $value->span()->start,
                );
                $equations[] = $expression . '=0';
                $equations[] = $expression . '=1';
            }
        }

        if ($node->name === 'beta') {
            foreach ($node->arguments as $argument) {
                if (!$this->containsVariable($argument, $variable)) {
                    continue;
                }
                $expression = \substr(
                    $source,
                    $argument->span()->start,
                    $argument->span()->end - $argument->span()->start,
                );
                $equations[] = $expression . '=-171';
            }

            if (
                isset($node->arguments[1])
                && ($this->containsVariable($node->arguments[0], $variable)
                    || $this->containsVariable($node->arguments[1], $variable))
            ) {
                $first = \substr(
                    $source,
                    $node->arguments[0]->span()->start,
                    $node->arguments[0]->span()->end - $node->arguments[0]->span()->start,
                );
                $second = \substr(
                    $source,
                    $node->arguments[1]->span()->start,
                    $node->arguments[1]->span()->end - $node->arguments[1]->span()->start,
                );
                $equations[] = '(' . $first . ')+(' . $second . ')=-171';
                for ($sum = -342; $sum <= -172; ++$sum) {
                    $equations[] = '(' . $first . ')+(' . $second . ')=' . $sum;
                }
            }
        }

        $boundaries = match ($node->name) {
            'sqrt' => ['0'],
            'asin', 'acos', 'asec', 'acsc' => ['-1', '1'],
            'acosh', 'asech' => ['1'],
            'gamma', 'lgamma' => ['-171', '171'],
            'besselj' => [
                \sprintf('%.17g', -SpecialFunctions::MAX_BESSEL_J_ARGUMENT),
                \sprintf('%.17g', SpecialFunctions::MAX_BESSEL_J_ARGUMENT),
            ],
            'besseli' => [
                \sprintf('%.17g', -SpecialFunctions::MAX_BESSEL_I_ARGUMENT),
                \sprintf('%.17g', SpecialFunctions::MAX_BESSEL_I_ARGUMENT),
            ],
            'bessely' => [
                '0',
                \sprintf('%.17g', SpecialFunctions::MAX_BESSEL_Y_ARGUMENT),
            ],
            default => [],
        };
        if ($node->name === 'lambertw') {
            $branch = $this->lambertWBranch($node, $parameters, $options);
            if ($branch !== null) {
                $boundaries = ['-1/e'];
            } elseif (
                isset($node->arguments[1])
                && $this->containsVariable($node->arguments[1], $variable)
            ) {
                $boundaries = ['-1/e'];
                $branchExpression = \substr(
                    $source,
                    $node->arguments[1]->span()->start,
                    $node->arguments[1]->span()->end - $node->arguments[1]->span()->start,
                );
                $equations[] = $branchExpression . '=0';
                $equations[] = $branchExpression . '=-1';
            }
        }
        $boundaryArgumentIndex = \in_array($node->name, ['besselj', 'besseli', 'bessely'], true) ? 1 : 0;
        $argument = $node->arguments[$boundaryArgumentIndex] ?? null;
        if (
            $node->name === 'root'
            && isset($node->arguments[1])
            && !$this->containsVariable($node->arguments[1], $variable)
        ) {
            try {
                $degree = $this->evaluate($node->arguments[1], $parameters, $options);
                if (
                    $degree > 0
                    && \floor((float) $degree) === (float) $degree
                    && $degree <= 9_007_199_254_740_991.0
                    && \fmod((float) $degree, 2.0) === 0.0
                ) {
                    $boundaries = ['0'];
                }
            } catch (EvaluationException) {
                // An invalid degree is handled by ordinary evaluation.
            }
        }
        if (
            $argument !== null && $boundaries !== []
            && $this->containsVariable($argument, $variable)
        ) {
            $expression = \substr(
                $source,
                $argument->span()->start,
                $argument->span()->end - $argument->span()->start,
            );
            foreach ($boundaries as $value) {
                $equations[] = $expression . '=' . $value;
            }
        }

        foreach ($node->arguments as $child) {
            $equations = [
                ...$equations,
                ...$this->definedBoundaryEquations(
                    $child,
                    $source,
                    $variable,
                    $parameters,
                    $options,
                ),
            ];
        }

        return $equations;
    }

    /**
     * @param array<string, int|float> $parameters
     * @return list<string>
     */
    private function discreteDomainEquations(
        Node $node,
        string $source,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        SolverOptions $solverOptions,
    ): array {
        if ($node instanceof FunctionCallNode) {
            $equations = [];
            $integerArgumentIndices = match ($node->name) {
                'besselj', 'besseli', 'bessely', 'polygamma' => [0],
                'doublefactorial' => [0],
                'gcd', 'lcm' => [0, 1],
                default => [],
            };
            foreach ($integerArgumentIndices as $argumentIndex) {
                if (!isset($node->arguments[$argumentIndex])) {
                    continue;
                }
                $argument = $node->arguments[$argumentIndex];
                if (!$this->containsVariable($argument, $variable)) {
                    continue;
                }
                $range = $this->boundedExpressionRange(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($range === null) {
                    throw new \DomainException(
                        'The integer-valued function domain cannot be bounded over the solving interval.',
                    );
                }
                if (\in_array($node->name, ['besselj', 'besseli', 'bessely', 'polygamma'], true)) {
                    $minimum = $node->name === 'polygamma'
                        ? 0.0
                        : -(float) SpecialFunctions::MAX_BESSEL_ORDER;
                    $maximum = (float) ($node->name === 'polygamma'
                        ? SpecialFunctions::MAX_POLYGAMMA_ORDER
                        : SpecialFunctions::MAX_BESSEL_ORDER);
                    $range = [\max($range[0], $minimum), \min($range[1], $maximum)];
                    if ($range[0] > $range[1]) {
                        continue;
                    }
                } elseif ($node->name === 'doublefactorial') {
                    $range = [\max($range[0], -1.0), \min(
                        $range[1],
                        (float) ResourceLimits::factorialMaximum(),
                    )];
                    if ($range[0] > $range[1]) {
                        continue;
                    }
                } elseif (
                    $range[0] < -9_007_199_254_740_991.0
                    || $range[1] > 9_007_199_254_740_991.0
                ) {
                    throw new \DomainException(
                        'The integer-valued function domain exceeds the exact numeric range.',
                    );
                }

                $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                    * \max(1.0, \abs($range[0]), \abs($range[1]));
                $first = \ceil($range[0] - $tolerance);
                $last = \floor($range[1] + $tolerance);
                if ($first > $last) {
                    continue;
                }
                if ($last - $first + 1.0 > self::MAX_DISCRETE_DOMAIN_POINTS) {
                    throw new \DomainException(
                        'The integer-valued function domain exceeds the inequality boundary limit.',
                    );
                }

                $span = $argument->span();
                $expression = \substr($source, $span->start, $span->end - $span->start);
                for ($level = (int) $first; $level <= (int) $last; ++$level) {
                    $equations[] = $expression . '=' . $level;
                }
            }
            foreach ($node->arguments as $argument) {
                $equations = [
                    ...$equations,
                    ...$this->discreteDomainEquations(
                        $argument,
                        $source,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $solverOptions,
                    ),
                ];
            }

            return $equations;
        }
        if ($node instanceof BinaryOperationNode) {
            $equations = [];
            if (
                $node->operator->value === '^'
                && $this->containsVariable($node->right, $variable)
            ) {
                $baseRange = $this->boundedExpressionRange(
                    $node->left,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($baseRange === null) {
                    throw new \DomainException(
                        'The variable-power base range cannot be bounded over the solving interval.',
                    );
                }
                if ($baseRange[0] <= 0.0 && $baseRange[1] >= 0.0) {
                    if ($this->containsVariable($node->left, $variable)) {
                        $baseSpan = $node->left->span();
                        $baseSource = \substr(
                            $source,
                            $baseSpan->start,
                            $baseSpan->end - $baseSpan->start,
                        );
                        $equations[] = $baseSource . '=0';
                    } elseif ($baseRange[0] === 0.0 && $baseRange[1] === 0.0) {
                        $exponentSpan = $node->right->span();
                        $exponentSource = \substr(
                            $source,
                            $exponentSpan->start,
                            $exponentSpan->end - $exponentSpan->start,
                        );
                        $equations[] = $exponentSource . '=0';
                    }
                }
                if ($baseRange[0] < 0.0) {
                    $exponentRange = $this->boundedExpressionRange(
                        $node->right,
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                    );
                    if ($exponentRange === null) {
                        throw new \DomainException(
                            'The variable-power exponent range cannot be bounded over the solving interval.',
                        );
                    }
                    if (
                        $exponentRange[0] < -9_007_199_254_740_991.0
                        || $exponentRange[1] > 9_007_199_254_740_991.0
                    ) {
                        throw new \DomainException(
                            'The variable-power exponent range exceeds the exact integer range.',
                        );
                    }
                    $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                        * \max(1.0, \abs($exponentRange[0]), \abs($exponentRange[1]));
                    $first = \ceil($exponentRange[0] - $tolerance);
                    $last = \floor($exponentRange[1] + $tolerance);
                    if ($last - $first + 1.0 > self::MAX_DISCRETE_DOMAIN_POINTS) {
                        throw new \DomainException(
                            'The variable-power domain exceeds the inequality boundary limit.',
                        );
                    }
                    $span = $node->right->span();
                    $expression = \substr($source, $span->start, $span->end - $span->start);
                    for ($level = (int) $first; $level <= (int) $last; ++$level) {
                        $equations[] = $expression . '=' . $level;
                    }
                }
            }

            return [
                ...$equations,
                ...$this->discreteDomainEquations(
                    $node->left,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
                ...$this->discreteDomainEquations(
                    $node->right,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
            ];
        }
        if ($node instanceof FactorialNode) {
            $equations = [];
            if ($this->containsVariable($node->operand, $variable)) {
                $range = $this->boundedExpressionRange(
                    $node->operand,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                );
                if ($range === null) {
                    throw new \DomainException(
                        'The factorial operand range cannot be bounded over the solving interval.',
                    );
                }
                $minimum = \max(0.0, $range[0]);
                $maximum = \min((float) $evaluationOptions->limits->maxFactorial, $range[1]);
                $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                    * \max(1.0, \abs($minimum), \abs($maximum));
                $first = \ceil($minimum - $tolerance);
                $last = \floor($maximum + $tolerance);
                $span = $node->operand->span();
                $expression = \substr($source, $span->start, $span->end - $span->start);
                for ($level = (int) $first; $level <= (int) $last; ++$level) {
                    $equations[] = $expression . '=' . $level;
                }
            }

            return [
                ...$equations,
                ...$this->discreteDomainEquations(
                    $node->operand,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                    $solverOptions,
                ),
            ];
        }
        if (
            $node instanceof UnaryOperationNode
            || $node instanceof GroupingNode
        ) {
            $child = match (true) {
                $node instanceof UnaryOperationNode => $node->operand,
                default => $node->expression,
            };

            return $this->discreteDomainEquations(
                $child,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
                $solverOptions,
            );
        }

        return [];
    }

    /** @param array<string, int|float> $parameters */
    private function lambertWBranch(
        FunctionCallNode $node,
        array $parameters,
        EvaluationOptions $options,
    ): ?float {
        if (\count($node->arguments) === 1) {
            return 0.0;
        }
        if (\count($node->arguments) !== 2) {
            return null;
        }
        try {
            $branch = (float) $this->evaluate($node->arguments[1], $parameters, $options);
        } catch (EvaluationException) {
            return null;
        }

        return $branch === 0.0 || $branch === -1.0 ? $branch : null;
    }

    private function equationForNode(Node $node, string $source, string $value): string
    {
        $expression = \substr(
            $source,
            $node->span()->start,
            $node->span()->end - $node->span()->start,
        );

        return $expression . '=' . $value;
    }

    private function signedVariableOrientation(Node $node, string $variable): ?float
    {
        return match (true) {
            $node instanceof VariableNode && $node->name === $variable => 1.0,
            $node instanceof GroupingNode => $this->signedVariableOrientation(
                $node->expression,
                $variable,
            ),
            $node instanceof UnaryOperationNode => $this->orientUnaryVariable(
                $node,
                $variable,
            ),
            default => null,
        };
    }

    /**
     * @return list<float>
     * @param array<string, int|float> $parameters
     * @param list<float> $candidatePoints
     * @param array<array-key, list<array{side: string, start: int, end: int, value: float}>> $boundaryOverrides
     * @param-out array<array-key, list<array{side: string, start: int, end: int, value: float}>> $boundaryOverrides
     * @param 'left'|'right' $side
     */
    private function piecewiseDiscontinuityPoints(
        Node $node,
        string $source,
        string $variable,
        float $lowerBound,
        float $upperBound,
        PolynomialExtractor $extractor,
        SolverOptions $options,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        string $side,
        array &$candidatePoints,
        array &$boundaryOverrides,
    ): array {
        if ($node instanceof BinaryOperationNode) {
            return [
                ...$this->piecewiseDiscontinuityPoints(
                    $node->left,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $extractor,
                    $options,
                    $parameters,
                    $evaluationOptions,
                    $side,
                    $candidatePoints,
                    $boundaryOverrides,
                ),
                ...$this->piecewiseDiscontinuityPoints(
                    $node->right,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $extractor,
                    $options,
                    $parameters,
                    $evaluationOptions,
                    $side,
                    $candidatePoints,
                    $boundaryOverrides,
                ),
            ];
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->piecewiseDiscontinuityPoints(
                $node->operand,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $extractor,
                $options,
                $parameters,
                $evaluationOptions,
                $side,
                $candidatePoints,
                $boundaryOverrides,
            );
        }
        if ($node instanceof GroupingNode) {
            return $this->piecewiseDiscontinuityPoints(
                $node->expression,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $extractor,
                $options,
                $parameters,
                $evaluationOptions,
                $side,
                $candidatePoints,
                $boundaryOverrides,
            );
        }
        if ($node instanceof FactorialNode) {
            return $this->piecewiseDiscontinuityPoints(
                $node->operand,
                $source,
                $variable,
                $lowerBound,
                $upperBound,
                $extractor,
                $options,
                $parameters,
                $evaluationOptions,
                $side,
                $candidatePoints,
                $boundaryOverrides,
            );
        }
        if (!$node instanceof FunctionCallNode) {
            return [];
        }

        $points = [];
        if ($node->name === 'hypot' && \count($node->arguments) === 2) {
            foreach (
                $this->hypotEqualityPoints(
                    $node->arguments[0],
                    $node->arguments[1],
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $extractor,
                    $options,
                    $parameters,
                    $evaluationOptions,
                ) as $point
            ) {
                $candidatePoints[] = $point;
            }
        }
        if ($node->name === 'atan2' && isset($node->arguments[0])) {
            $coordinate = $node->arguments[0];
            $form = $extractor->extractRationalForm($coordinate);
            if ($form !== null && $form[0] === [0.0]) {
                $coordinate = $node->arguments[1] ?? $coordinate;
                $form = $extractor->extractRationalForm($coordinate);
            }
            if ($form === null) {
                foreach ($node->arguments as $coordinate) {
                    $coordinateSource = \substr(
                        $source,
                        $coordinate->span()->start,
                        $coordinate->span()->end - $coordinate->span()->start,
                    );
                    if (
                        $coordinateSource === ''
                        || !$this->containsVariable($coordinate, $variable)
                        || \strlen($coordinateSource) + 2
                            > $evaluationOptions->limits->maxExpressionLength
                    ) {
                        continue;
                    }
                    try {
                        $roots = (new EquationSolver())->solve(
                            $coordinateSource . '=0',
                            $variable,
                            $lowerBound,
                            $upperBound,
                            $parameters,
                            $evaluationOptions,
                            $options,
                        );
                        foreach ($roots as $root) {
                            $points[] = $root->value;
                        }
                    } catch (InfiniteRootAccumulationException $error) {
                        throw $error;
                    } catch (InfiniteSolutionSetException) {
                        // The other coordinate can still partition the origin.
                    } catch (\DomainException) {
                        // Unsupported zero-level expressions remain covered by sampling.
                    }
                }
            }
            if ($form !== null && \count($form[0]) > 1) {
                $coefficients = $form[0];
                $polynomialSolver = new PolynomialSolver();
                $roots = $polynomialSolver->solve(
                    $coefficients,
                    $lowerBound,
                    $upperBound,
                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
                        $coefficients,
                        $value,
                    ),
                    $options,
                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                        $coefficients,
                        $value,
                    ),
                );
                if ($roots === null) {
                    $coordinateSource = \substr(
                        $source,
                        $coordinate->span()->start,
                        $coordinate->span()->end - $coordinate->span()->start,
                    );
                    if (
                        $coordinateSource === ''
                        || \strlen($coordinateSource) + 2
                            > $evaluationOptions->limits->maxExpressionLength
                    ) {
                        throw new RootSearchLimitException(
                            'Could not isolate the polynomial boundary of atan2() reliably.',
                        );
                    }
                    foreach (
                        (new EquationSolver())->solve(
                            $coordinateSource . '=0',
                            $variable,
                            $lowerBound,
                            $upperBound,
                            $parameters,
                            $evaluationOptions,
                            $options,
                        ) as $root
                    ) {
                        $points[] = $root->value;
                    }
                } else {
                    foreach ($roots as $root) {
                        $points[] = $root->value;
                    }
                }
            }
        }
        if (
            \in_array($node->name, ['floor', 'ceil', 'round', 'trunc'], true)
            && isset($node->arguments[0])
        ) {
            $argument = $node->arguments[0];
            $coefficients = $extractor->extract($argument);
            $range = $coefficients === null
                ? $this->boundedExpressionRange(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $evaluationOptions,
                )
                : $this->polynomialRange($coefficients, $lowerBound, $upperBound, $options);
            if ($range !== null) {
                [$minimum, $maximum] = $range;
                $maximumExactInteger = 9_007_199_254_740_991.0;
                if (
                    $minimum >= -$maximumExactInteger
                    && $maximum <= $maximumExactInteger
                ) {
                    $offset = $node->name === 'round' ? 0.5 : 0.0;
                    $tolerance = 8.0 * \PHP_FLOAT_EPSILON
                        * \max(1.0, \abs($minimum), \abs($maximum));
                    $firstLevel = (int) \ceil($minimum - $offset - $tolerance);
                    $lastLevel = (int) \floor($maximum - $offset + $tolerance);
                    if (
                        $lastLevel >= $firstLevel
                        && $lastLevel - $firstLevel <= self::MAX_PIECEWISE_BOUNDARIES
                    ) {
                        $argumentSource = \substr(
                            $source,
                            $argument->span()->start,
                            $argument->span()->end - $argument->span()->start,
                        );
                        if ($coefficients !== null) {
                            $polynomialSolver = new PolynomialSolver();
                            for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
                                $levelValue = $level + $offset;
                                $levelCoefficients = $coefficients;
                                $levelCoefficients[0] -= $levelValue;
                                if (!\is_finite($levelCoefficients[0])) {
                                    continue;
                                }
                                $levelRoots = $polynomialSolver->solve(
                                    $levelCoefficients,
                                    $lowerBound,
                                    $upperBound,
                                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
                                        $levelCoefficients,
                                        $value,
                                    ),
                                    $options,
                                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                                        $levelCoefficients,
                                        $value,
                                    ),
                                );
                                if ($levelRoots === null) {
                                    try {
                                        $levelRoots = (new EquationSolver())->solve(
                                            $this->equationForNode(
                                                $argument,
                                                $source,
                                                \sprintf('%.17g', $levelValue),
                                            ),
                                            $variable,
                                            $lowerBound,
                                            $upperBound,
                                            $parameters,
                                            $evaluationOptions,
                                            $options,
                                        );
                                    } catch (InfiniteSolutionSetException) {
                                        continue;
                                    }
                                }
                                $levelRoots = $polynomialSolver->recoverRepeatedRoots(
                                    $levelCoefficients,
                                    $levelRoots,
                                    $lowerBound,
                                    $upperBound,
                                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
                                        $levelCoefficients,
                                        $value,
                                    ),
                                    fn (float $value): ?float =>
                                        $polynomialSolver->evaluatePolynomialMagnitude(
                                            $levelCoefficients,
                                            $value,
                                        ),
                                    $options,
                                );
                                foreach ($levelRoots as $root) {
                                    $points[] = $root->value;
                                    $span = $node->span();
                                    $boundaryOverrides[$this->pointKey($root->value)][] = [
                                        'side' => $side,
                                        'start' => $span->start,
                                        'end' => $span->end,
                                        'value' => $this->roundingFunctionValue(
                                            $node->name,
                                            $levelValue,
                                        ),
                                    ];
                                }
                            }
                        } else {
                            for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
                                if ($node->name === 'trunc' && $level === 0) {
                                    continue;
                                }
                                $levelValue = $level + $offset;
                                $literal = \sprintf('%.17g', $levelValue);
                                try {
                                    $levelRoots = (new EquationSolver())->solve(
                                        '(' . $argumentSource . ') = ' . $literal,
                                        $variable,
                                        $lowerBound,
                                        $upperBound,
                                        $parameters,
                                        $evaluationOptions,
                                        $options,
                                    );
                                } catch (InfiniteSolutionSetException | \DomainException) {
                                    continue;
                                }
                                foreach ($levelRoots as $root) {
                                    $points[] = $root->value;
                                    $span = $node->span();
                                    $boundaryOverrides[$this->pointKey($root->value)][] = [
                                        'side' => $side,
                                        'start' => $span->start,
                                        'end' => $span->end,
                                        'value' => $this->roundingFunctionValue(
                                            $node->name,
                                            $levelValue,
                                        ),
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($node->name === 'sign' && isset($node->arguments[0])) {
            $coefficients = $extractor->extract($node->arguments[0]);
            if ($coefficients === null || \count($coefficients) > 1) {
                $polynomialSolver = new PolynomialSolver();
                $roots = $coefficients === null ? null : $polynomialSolver->solve(
                    $coefficients,
                    $lowerBound,
                    $upperBound,
                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
                        $coefficients,
                        $value,
                    ),
                    $options,
                    fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                        $coefficients,
                        $value,
                    ),
                );
                if ($roots === null) {
                    try {
                        $roots = (new EquationSolver())->solve(
                            $this->equationForNode($node->arguments[0], $source, '0'),
                            $variable,
                            $lowerBound,
                            $upperBound,
                            $parameters,
                            $evaluationOptions,
                            $options,
                        );
                    } catch (InfiniteSolutionSetException) {
                        $roots = [];
                    } catch (\DomainException) {
                        // Unsupported zero-level expressions remain covered by sampling.
                        $roots = [];
                    }
                }
                if ($coefficients !== null) {
                    $roots = $polynomialSolver->recoverRepeatedRoots(
                        $coefficients,
                        $roots,
                        $lowerBound,
                        $upperBound,
                        fn (float $value): ?float => $polynomialSolver->evaluatePolynomial(
                            $coefficients,
                            $value,
                        ),
                        fn (float $value): ?float =>
                            $polynomialSolver->evaluatePolynomialMagnitude(
                                $coefficients,
                                $value,
                            ),
                        $options,
                    );
                }
                foreach ($roots as $root) {
                    $points[] = $root->value;
                    $span = $node->span();
                    $boundaryOverrides[$this->pointKey($root->value)][] = [
                        'side' => $side,
                        'start' => $span->start,
                        'end' => $span->end,
                        'value' => 0.0,
                    ];
                }
            }
        }

        foreach ($node->arguments as $argument) {
            $points = [
                ...$points,
                ...$this->piecewiseDiscontinuityPoints(
                    $argument,
                    $source,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $extractor,
                    $options,
                    $parameters,
                    $evaluationOptions,
                    $side,
                    $candidatePoints,
                    $boundaryOverrides,
                ),
            ];
        }

        return $points;
    }

    /**
     * Find common zeros of hypot coordinates so sparse inequality scans retain
     * the equality boundary even when neither coordinate is polynomial.
     *
     * @param array<string, int|float> $parameters
     * @return list<float>
     */
    private function hypotEqualityPoints(
        Node $first,
        Node $second,
        string $source,
        string $variable,
        float $lowerBound,
        float $upperBound,
        PolynomialExtractor $extractor,
        SolverOptions $options,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): array {
        $coordinateRoots = function (Node $coordinate) use (
            $source,
            $variable,
            $lowerBound,
            $upperBound,
            $extractor,
            $options,
            $parameters,
            $evaluationOptions,
        ): ?array {
            $form = $extractor->extractRationalForm($coordinate);
            if ($form !== null && $form[0] === [0.0]) {
                return null;
            }
            if (!$this->containsVariable($coordinate, $variable)) {
                try {
                    return $this->evaluate(
                        $coordinate,
                        $parameters,
                        $evaluationOptions,
                    ) == 0.0 ? null : [];
                } catch (EvaluationException) {
                    return [];
                }
            }

            $coordinateSource = \substr(
                $source,
                $coordinate->span()->start,
                $coordinate->span()->end - $coordinate->span()->start,
            );
            if (
                $coordinateSource === ''
                || \strlen($coordinateSource) + 2
                    > $evaluationOptions->limits->maxExpressionLength
            ) {
                return [];
            }
            try {
                return \array_map(
                    static fn (EquationSolution $root): float => $root->value,
                    (new EquationSolver())->solve(
                        $coordinateSource . '=0',
                        $variable,
                        $lowerBound,
                        $upperBound,
                        $parameters,
                        $evaluationOptions,
                        $options,
                    ),
                );
            } catch (InfiniteRootAccumulationException $error) {
                throw $error;
            } catch (InfiniteSolutionSetException) {
                return null;
            } catch (\DomainException) {
                return [];
            }
        };

        $firstRoots = $coordinateRoots($first);
        $secondRoots = $coordinateRoots($second);
        if ($firstRoots === null && $secondRoots === null) {
            return [];
        }
        if ($firstRoots === null) {
            $candidates = $secondRoots;
        } elseif ($secondRoots === null) {
            $candidates = $firstRoots;
        } else {
            $candidates = [];
            foreach ($firstRoots as $firstRoot) {
                foreach ($secondRoots as $secondRoot) {
                    if (
                        \abs($firstRoot - $secondRoot)
                        <= $options->positionTolerance($firstRoot, $secondRoot)
                    ) {
                        $candidates[] = ($firstRoot + $secondRoot) / 2.0;
                    }
                }
            }
        }

        $points = [];
        foreach ($candidates as $candidate) {
            try {
                $variables = [...$parameters, $variable => $candidate];
                $firstValue = $this->evaluate($first, $variables, $evaluationOptions);
                $secondValue = $this->evaluate($second, $variables, $evaluationOptions);
            } catch (EvaluationException) {
                continue;
            }
            $residualTolerance = $options->residualTolerance;
            if (
                \abs($firstValue) <= $residualTolerance * \max(1.0, \abs($firstValue))
                && \abs($secondValue) <= $residualTolerance * \max(1.0, \abs($secondValue))
            ) {
                $points[] = $candidate;
            }
        }

        return \array_values(\array_unique($points));
    }

    /**
     * @param array<array-key, list<array{side: string, start: int, end: int, value: float}>> $overrides
     * @return array<array-key, array{left?: Node, right?: Node, rightOffset?: int}>
     */
    private function piecewiseBoundaryNodes(
        array $overrides,
        string $leftExpression,
        string $rightExpression,
        EvaluationOptions $options,
    ): array {
        $nodes = [];
        foreach ($overrides as $key => $pointOverrides) {
            $leftOverrides = \array_values(\array_filter(
                $pointOverrides,
                static fn (array $override): bool => $override['side'] === 'left',
            ));
            $rightOverrides = \array_values(\array_filter(
                $pointOverrides,
                static fn (array $override): bool => $override['side'] === 'right',
            ));
            if ($leftOverrides !== []) {
                $nodes[$key]['left'] = $this->parseSide(
                    $this->replaceSourceSpans($leftExpression, $leftOverrides),
                    0,
                    $options,
                );
            }
            if ($rightOverrides !== []) {
                $nodes[$key]['right'] = $this->parseSide(
                    $this->replaceSourceSpans($rightExpression, $rightOverrides),
                    0,
                    $options,
                );
                $nodes[$key]['rightOffset'] = 0;
            }
        }

        return $nodes;
    }

    /**
     * @param list<array{side: string, start: int, end: int, value: float}> $overrides
     */
    private function replaceSourceSpans(string $source, array $overrides): string
    {
        \usort(
            $overrides,
            static fn (array $left, array $right): int =>
                ($left['start'] <=> $right['start']) ?: ($right['end'] <=> $left['end']),
        );
        $selected = [];
        foreach ($overrides as $override) {
            $isNested = false;
            foreach ($selected as $outer) {
                if ($outer['start'] <= $override['start'] && $outer['end'] >= $override['end']) {
                    $isNested = true;
                    break;
                }
            }
            if (!$isNested) {
                $selected[] = $override;
            }
        }
        \usort(
            $selected,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start'],
        );
        foreach ($selected as $override) {
            $literal = \json_encode($override['value'], JSON_PRESERVE_ZERO_FRACTION);
            if ($literal === false) {
                continue;
            }
            $source = \substr($source, 0, $override['start'])
                . $literal
                . \substr($source, $override['end']);
        }

        return $source;
    }

    private function roundingFunctionValue(string $function, float $value): float
    {
        return match ($function) {
            'floor' => \floor($value),
            'ceil' => \ceil($value),
            'round' => \round($value),
            'trunc' => $value < 0.0 ? \ceil($value) : \floor($value),
            default => throw new \LogicException('Unexpected piecewise rounding function.'),
        };
    }

    /**
     * A scalar threshold against one rounding function changes truth only at
     * its enumerated step boundaries, so solving the equality itself is redundant.
     * @param array<string, int|float> $parameters
     */
    private function isCompletelyPartitionedQuantizerComparison(
        Node $left,
        Node $right,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $evaluationOptions,
    ): bool {
        foreach ([[$left, $right], [$right, $left]] as [$quantizer, $threshold]) {
            while ($quantizer instanceof GroupingNode) {
                $quantizer = $quantizer->expression;
            }
            if (
                !$quantizer instanceof FunctionCallNode
                || \count($quantizer->arguments) !== 1
                || !\in_array($quantizer->name, ['floor', 'ceil', 'round', 'trunc'], true)
                || $this->containsVariable($threshold, $variable)
                || $this->containsVariableCall($threshold, $variable, $evaluationOptions)
            ) {
                continue;
            }
            $argumentRange = $this->boundedExpressionRange(
                $quantizer->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $evaluationOptions,
            );
            if ($argumentRange === null) {
                continue;
            }
            $rangeMinimum = $argumentRange[0];
            $rangeMaximum = $argumentRange[1];
            if (
                $rangeMinimum < -9_007_199_254_740_991.0
                || $rangeMaximum > 9_007_199_254_740_991.0
            ) {
                continue;
            }
            $offset = $quantizer->name === 'round' ? 0.5 : 0.0;
            $tolerance = 8.0 * \PHP_FLOAT_EPSILON
                * \max(1.0, \abs($rangeMinimum), \abs($rangeMaximum));
            $firstLevel = \ceil($rangeMinimum - $offset - $tolerance);
            $lastLevel = \floor($rangeMaximum - $offset + $tolerance);
            if (
                $lastLevel < $firstLevel
                || $lastLevel - $firstLevel > self::MAX_PIECEWISE_BOUNDARIES
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{bool, list<list<float>>}
     */
    private function moduloDomainPolynomials(
        Node $node,
        string $variable,
        PolynomialExtractor $extractor,
    ): array {
        if ($node instanceof BinaryOperationNode) {
            [$leftHasModulo, $leftPolynomials] = $this->moduloDomainPolynomials(
                $node->left,
                $variable,
                $extractor,
            );
            [$rightHasModulo, $rightPolynomials] = $this->moduloDomainPolynomials(
                $node->right,
                $variable,
                $extractor,
            );
            $hasModulo = $leftHasModulo || $rightHasModulo;
            $polynomials = [...$leftPolynomials, ...$rightPolynomials];
            if (
                $node->operator->value === '%'
                && ($this->containsVariable($node->left, $variable)
                    || $this->containsVariable($node->right, $variable))
            ) {
                $hasModulo = true;
                foreach ([$node->left, $node->right] as $operand) {
                    if (!$this->containsVariable($operand, $variable)) {
                        continue;
                    }
                    try {
                        $coefficients = $extractor->extract($operand);
                    } catch (EvaluationException $error) {
                        if ($this->mustPropagate($error)) {
                            throw $error;
                        }
                        $coefficients = null;
                    }
                    if ($coefficients !== null && \count($coefficients) > 1) {
                        $polynomials[] = $coefficients;
                    }
                }
            }

            return [$hasModulo, $polynomials];
        }
        if (
            $node instanceof UnaryOperationNode
            || $node instanceof GroupingNode
            || $node instanceof FactorialNode
        ) {
            $child = match (true) {
                $node instanceof UnaryOperationNode => $node->operand,
                $node instanceof GroupingNode => $node->expression,
                default => $node->operand,
            };

            return $this->moduloDomainPolynomials($child, $variable, $extractor);
        }
        if ($node instanceof FunctionCallNode) {
            $hasModulo = false;
            $polynomials = [];
            foreach ($node->arguments as $argument) {
                [$argumentHasModulo, $argumentPolynomials] = $this->moduloDomainPolynomials(
                    $argument,
                    $variable,
                    $extractor,
                );
                $hasModulo = $hasModulo || $argumentHasModulo;
                $polynomials = [...$polynomials, ...$argumentPolynomials];
            }

            return [$hasModulo, $polynomials];
        }

        return [false, []];
    }

    /**
     * @param list<list<float>> $polynomials
     * @return list<array{float, float}>
     */
    private function enumeratePolynomialModuloPoints(
        array $polynomials,
        float $lowerBound,
        float $upperBound,
        SolverOptions $solverOptions,
    ): array {
        $maximumExactInteger = 9_007_199_254_740_991.0;
        $selected = null;
        $selectedCount = \INF;
        foreach ($polynomials as $coefficients) {
            $range = $this->polynomialRange(
                $coefficients,
                $lowerBound,
                $upperBound,
                $solverOptions,
            );
            if ($range === null) {
                continue;
            }
            [$minimum, $maximum] = $range;
            if (
                $minimum < -$maximumExactInteger
                || $maximum > $maximumExactInteger
            ) {
                continue;
            }
            $tolerance = 8.0 * \PHP_FLOAT_EPSILON
                * \max(1.0, \abs($minimum), \abs($maximum));
            $first = \ceil($minimum - $tolerance);
            $last = \floor($maximum + $tolerance);
            if ($first > $last) {
                return [];
            }

            $levelCount = $last - $first + 1.0;
            if (
                $levelCount <= self::MAX_DISCRETE_DOMAIN_POINTS
                && $levelCount < $selectedCount
            ) {
                $selected = [$coefficients, (int) $first, (int) $last];
                $selectedCount = $levelCount;
            }
        }
        if ($selected === null) {
            throw new \DomainException(
                'The modulo polynomial integer domain exceeds the configured point limit.',
            );
        }

        [$coefficients, $firstLevel, $lastLevel] = $selected;
        $polynomialSolver = new PolynomialSolver();
        $points = [];
        $roundingUlps = 4.0 * \max(1, \count($coefficients) - 1);
        for ($level = $firstLevel; $level <= $lastLevel; ++$level) {
            $levelPolynomial = $coefficients;
            $levelPolynomial[0] -= $level;
            $roots = $polynomialSolver->solve(
                $levelPolynomial,
                $lowerBound,
                $upperBound,
                function (float $value) use ($coefficients, $level): ?float {
                    $operand = $this->polynomialValue($coefficients, $value);

                    return $operand === null ? null : $operand - $level;
                },
                $solverOptions,
                fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                    $levelPolynomial,
                    $value,
                ),
            );
            if ($roots === null) {
                throw new \DomainException(
                    'The modulo polynomial integer levels could not be isolated reliably.',
                );
            }
            foreach ($roots as $root) {
                $key = \sprintf('%.17g', $root->value);
                $points[$key] = [$root->value, $roundingUlps];
                if (\count($points) > self::MAX_DISCRETE_DOMAIN_POINTS) {
                    throw new \DomainException(
                        'The modulo integer domain exceeds the configured point limit.',
                    );
                }
            }
        }

        $points = \array_values($points);
        \usort(
            $points,
            static fn (array $first, array $second): int => $first[0] <=> $second[0],
        );

        return $points;
    }

    /**
     * @param list<float> $coefficients
     * @return array{float, float}|null
     */
    private function polynomialRange(
        array $coefficients,
        float $lowerBound,
        float $upperBound,
        SolverOptions $solverOptions,
    ): ?array {
        $values = [
            $this->polynomialValue($coefficients, $lowerBound),
            $this->polynomialValue($coefficients, $upperBound),
        ];
        if ($values[0] === null || $values[1] === null) {
            return null;
        }
        $derivative = [];
        foreach ($coefficients as $power => $coefficient) {
            if ($power === 0) {
                continue;
            }
            $derivativeCoefficient = $power * $coefficient;
            if (!\is_finite($derivativeCoefficient)) {
                return null;
            }
            $derivative[] = $derivativeCoefficient;
        }
        if (\count($derivative) > 1) {
            $polynomialSolver = new PolynomialSolver();
            $criticalPoints = $polynomialSolver->solve(
                $derivative,
                $lowerBound,
                $upperBound,
                fn (float $value): ?float => $this->polynomialValue($derivative, $value),
                $solverOptions,
                fn (float $value): ?float => $polynomialSolver->evaluatePolynomialMagnitude(
                    $derivative,
                    $value,
                ),
            );
            if ($criticalPoints === null) {
                return null;
            }
            foreach ($criticalPoints as $criticalPoint) {
                $value = $this->polynomialValue($coefficients, $criticalPoint->value);
                if ($value === null) {
                    return null;
                }
                $values[] = $value;
            }
        }

        return [\min(...$values), \max(...$values)];
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     */
    private function boundedExpressionRange(
        Node $node,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        if (!$this->containsVariable($node, $variable)) {
            try {
                $value = (float) $this->evaluate($node, $parameters, $options);

                return \is_finite($value) ? [$value, $value] : null;
            } catch (EvaluationException) {
                return null;
            }
        }

        if ($node instanceof VariableNode) {
            return $node->name === $variable ? [$lowerBound, $upperBound] : null;
        }

        if ($node instanceof GroupingNode) {
            return $this->boundedExpressionRange(
                $node->expression,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
        }

        if ($node instanceof UnaryOperationNode) {
            $range = $this->boundedExpressionRange(
                $node->operand,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($range === null || $node->operator->value !== '-') {
                return $range;
            }

            return [-$range[1], -$range[0]];
        }

        if ($node instanceof BinaryOperationNode) {
            $left = $this->boundedExpressionRange(
                $node->left,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            $right = $this->boundedExpressionRange(
                $node->right,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($left === null || $right === null) {
                return null;
            }

            return match ($node->operator->value) {
                '+' => $this->finiteRange($left[0] + $right[0], $left[1] + $right[1]),
                '-' => $this->finiteRange($left[0] - $right[1], $left[1] - $right[0]),
                '*' => $this->productRange($left, $right),
                '/' => $this->quotientRange($left, $right),
                '^' => $this->integerPowerRange($left, $right),
                default => null,
            };
        }

        if ($node instanceof FactorialNode) {
            $operandRange = $this->boundedExpressionRange(
                $node->operand,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($operandRange === null) {
                return null;
            }
            $tolerance = 16.0 * \PHP_FLOAT_EPSILON
                * \max(1.0, \abs($operandRange[0]), \abs($operandRange[1]));
            $first = \ceil(\max(0.0, $operandRange[0]) - $tolerance);
            $last = \floor(\min(
                (float) $options->limits->maxFactorial,
                $operandRange[1],
            ) + $tolerance);
            if ($first > $last) {
                // Keep an empty domain bounded; candidate equations are validated later.
                return [0.0, 0.0];
            }

            $factorialAt = static function (int $operand): int {
                $value = 1;
                for ($factor = 2; $factor <= $operand; ++$factor) {
                    $value *= $factor;
                }

                return $value;
            };

            return [
                (float) $factorialAt((int) $first),
                (float) $factorialAt((int) $last),
            ];
        }

        if (!$node instanceof FunctionCallNode) {
            return null;
        }

        if ($node->name === 'besselj' && \count($node->arguments) === 2) {
            $argumentRange = $this->boundedExpressionRange(
                $node->arguments[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($argumentRange === null) {
                return null;
            }

            return [-1.0, 1.0];
        }

        if ($node->name === 'besseli' && \count($node->arguments) === 2) {
            $argumentRange = $this->boundedExpressionRange(
                $node->arguments[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if (
                $argumentRange === null
                || $argumentRange[0] < -SpecialFunctions::MAX_BESSEL_I_ARGUMENT
                || $argumentRange[1] > SpecialFunctions::MAX_BESSEL_I_ARGUMENT
            ) {
                return null;
            }
            try {
                $order = (float) $this->evaluate($node->arguments[0], $parameters, $options);
            } catch (EvaluationException) {
                return null;
            }
            if (
                !\is_finite($order)
                || $order !== \floor($order)
                || \abs($order) > SpecialFunctions::MAX_BESSEL_ORDER
            ) {
                return null;
            }
            $function = $options->functions->find('besseli');
            if ($function === null) {
                return null;
            }
            $integerOrder = (int) $order;
            $absoluteOrder = \abs($integerOrder);
            if (($absoluteOrder & 1) === 0) {
                $minimumArgument = $argumentRange[0] <= 0.0 && $argumentRange[1] >= 0.0
                    ? 0.0
                    : \min(\abs($argumentRange[0]), \abs($argumentRange[1]));
                $maximumArgument = \max(\abs($argumentRange[0]), \abs($argumentRange[1]));
            } else {
                $minimumArgument = $argumentRange[0];
                $maximumArgument = $argumentRange[1];
            }
            try {
                $minimum = (float) $function->invoke([$absoluteOrder, $minimumArgument]);
                $maximum = (float) $function->invoke([$absoluteOrder, $maximumArgument]);
            } catch (EvaluationException | \DomainException | \OverflowException | \InvalidArgumentException) {
                return null;
            }

            return $this->finiteRange($minimum, $maximum);
        }

        if (!$options->functions->isBuiltIn($node->name)) {
            $definition = $options->functions->find($node->name);
            if ($definition === null) {
                return null;
            }
            $argumentRanges = [];
            foreach ($node->arguments as $argument) {
                $argumentRange = $this->boundedExpressionRange(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                );
                if ($argumentRange === null) {
                    return null;
                }
                $argumentRanges[] = $argumentRange;
            }

            return $definition->intervalExtension($argumentRanges);
        }

        if ($node->name === 'root' && \count($node->arguments) === 2) {
            $radicandRange = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($radicandRange === null || $this->containsVariable($node->arguments[1], $variable)) {
                return null;
            }
            try {
                $degree = (float) $this->evaluate($node->arguments[1], $parameters, $options);
            } catch (EvaluationException) {
                return null;
            }

            return $this->integerRootRange($radicandRange, $degree);
        }

        if ($node->name === 'hypot' && \count($node->arguments) === 2) {
            $first = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            $second = $this->boundedExpressionRange(
                $node->arguments[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($first === null || $second === null) {
                return null;
            }
            $firstMinimum = $first[0] <= 0.0 && $first[1] >= 0.0
                ? 0.0
                : \min(\abs($first[0]), \abs($first[1]));
            $secondMinimum = $second[0] <= 0.0 && $second[1] >= 0.0
                ? 0.0
                : \min(\abs($second[0]), \abs($second[1]));

            return $this->finiteRange(
                \hypot($firstMinimum, $secondMinimum),
                \hypot(\max(\abs($first[0]), \abs($first[1])), \max(\abs($second[0]), \abs($second[1]))),
            );
        }

        if ($node->name === 'atan2' && \count($node->arguments) === 2) {
            $first = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            $second = $this->boundedExpressionRange(
                $node->arguments[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if (
                $first === null
                || $second === null
                || ($first[0] === 0.0 && $first[1] === 0.0
                    && $second[0] === 0.0 && $second[1] === 0.0)
            ) {
                return null;
            }

            return [-\M_PI, \M_PI];
        }

        if ($node->name === 'log' && \count($node->arguments) === 2) {
            $valueRange = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            $baseRange = $this->boundedExpressionRange(
                $node->arguments[1],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if (
                $valueRange === null
                || $baseRange === null
                || $valueRange[0] <= 0.0
                || $baseRange[0] <= 0.0
                || ($baseRange[0] <= 1.0 && $baseRange[1] >= 1.0)
            ) {
                return null;
            }

            return $this->quotientRange(
                [\log($valueRange[0]), \log($valueRange[1])],
                [\log($baseRange[0]), \log($baseRange[1])],
            );
        }

        if (
            $node->name === 'lambertw'
            && (\count($node->arguments) === 1 || \count($node->arguments) === 2)
        ) {
            if (\count($node->arguments) === 2 && $this->containsVariable($node->arguments[1], $variable)) {
                return null;
            }
            try {
                $branch = \count($node->arguments) === 1
                    ? 0.0
                    : (float) $this->evaluate($node->arguments[1], $parameters, $options);
            } catch (EvaluationException) {
                return null;
            }
            if ($branch !== 0.0 && $branch !== -1.0) {
                return null;
            }
            $argumentRange = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($argumentRange === null) {
                return null;
            }

            $branchPoint = -1.0 / \M_E;
            if ($branch === 0.0) {
                if ($argumentRange[1] < $branchPoint) {
                    return null;
                }
                $minimum = \max($argumentRange[0], $branchPoint);
                $first = $this->functionValueAt('lambertw', $minimum, $options);
                $second = $this->functionValueAt('lambertw', $argumentRange[1], $options);

                return $first === null || $second === null
                    ? null
                    : [$first, $second];
            }

            if (
                $argumentRange[1] < $branchPoint
                || $argumentRange[0] >= 0.0
                || $argumentRange[1] >= 0.0
            ) {
                return null;
            }
            $minimum = \max($argumentRange[0], $branchPoint);
            $first = $this->functionValueAt('lambertw', $argumentRange[1], $options, [-1]);
            $second = $this->functionValueAt('lambertw', $minimum, $options, [-1]);

            return $first === null || $second === null
                ? null
                : [$first, $second];
        }

        if (\in_array($node->name, ['betaincinv', 'betainccinv'], true) && \count($node->arguments) === 3) {
            if (
                $this->containsVariable($node->arguments[0], $variable)
                || $this->containsVariable($node->arguments[1], $variable)
            ) {
                return null;
            }
            try {
                $firstShape = (float) $this->evaluate($node->arguments[0], $parameters, $options);
                $secondShape = (float) $this->evaluate($node->arguments[1], $parameters, $options);
            } catch (EvaluationException) {
                return null;
            }
            if ($firstShape <= 0.0 || $secondShape <= 0.0) {
                return null;
            }
            $probabilityRange = $this->boundedExpressionRange(
                $node->arguments[2],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );

            return $probabilityRange !== null
                && $probabilityRange[0] >= 0.0
                && $probabilityRange[1] <= 1.0
                ? [0.0, 1.0]
                : null;
        }

        if ($node->name === 'min' || $node->name === 'max') {
            $ranges = [];
            foreach ($node->arguments as $argument) {
                $argumentRange = $this->boundedExpressionRange(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                );
                if ($argumentRange === null) {
                    return null;
                }
                $ranges[] = $argumentRange;
            }
            if ($ranges === []) {
                return null;
            }

            $lowerValues = \array_column($ranges, 0);
            $upperValues = \array_column($ranges, 1);

            return $node->name === 'min'
                ? [\min(...$lowerValues), \min(...$upperValues)]
                : [\max(...$lowerValues), \max(...$upperValues)];
        }

        if (
            ($node->name === 'gcd' || $node->name === 'lcm')
            && \count($node->arguments) === 2
        ) {
            $ranges = [];
            foreach ($node->arguments as $argument) {
                $argumentRange = $this->boundedExpressionRange(
                    $argument,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                );
                if ($argumentRange === null) {
                    return null;
                }
                $ranges[] = $argumentRange;
            }
            $maximumAbsoluteValues = [];
            foreach ($ranges as [$minimum, $maximum]) {
                $maximumAbsoluteValues[] = \max(\abs($minimum), \abs($maximum));
            }
            if ($node->name === 'gcd') {
                $firstCanBeZero = $ranges[0][0] <= 0.0 && $ranges[0][1] >= 0.0;
                $secondCanBeZero = $ranges[1][0] <= 0.0 && $ranges[1][1] >= 0.0;
                $upper = $firstCanBeZero || $secondCanBeZero
                    ? \max(...$maximumAbsoluteValues)
                    : \min(...$maximumAbsoluteValues);
            } else {
                $upper = $maximumAbsoluteValues[0] * $maximumAbsoluteValues[1];
            }
            if (!\is_finite($upper)) {
                return null;
            }

            return [0.0, \min($upper, (float) \PHP_INT_MAX)];
        }

        if (\count($node->arguments) !== 1) {
            return null;
        }

        $range = $this->boundedExpressionRange(
            $node->arguments[0],
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $options,
        );
        if ($range === null) {
            return null;
        }
        [$minimum, $maximum] = $range;

        if (\in_array($node->name, ['sin', 'cos', 'tan', 'cot', 'sec', 'csc'], true)) {
            return $this->trigonometricFunctionRange($node->name, $minimum, $maximum, $options);
        }

        if ($node->name === 'cosh') {
            $first = $this->functionValueAt($node->name, $minimum, $options);
            $second = $this->functionValueAt($node->name, $maximum, $options);
            if ($first === null || $second === null) {
                return null;
            }

            return [
                $minimum <= 0.0 && $maximum >= 0.0
                    ? 1.0
                    : \min($first, $second),
                \max($first, $second),
            ];
        }

        if ($node->name === 'csch' || $node->name === 'coth') {
            if ($minimum <= 0.0 && $maximum >= 0.0) {
                return null;
            }
            $first = $this->functionValueAt($node->name, $minimum, $options);
            $second = $this->functionValueAt($node->name, $maximum, $options);
            if ($first === null || $second === null) {
                return null;
            }

            return [\min($first, $second), \max($first, $second)];
        }

        return match ($node->name) {
            'erf', 'sinc', 'sincpi' => $this->finiteRange(-1.0, 1.0),
            'erfc' => $this->finiteRange(0.0, 2.0),
            'erfcx' => $this->scaledComplementaryErrorFunctionRange($minimum, $maximum, $options),
            'erfi' => $this->increasingSpecialFunctionRange('erfi', $minimum, $maximum, $options),
            'dawson' => $this->dawsonFunctionRange($minimum, $maximum, $options),
            'erfinv' => $minimum > -1.0 && $maximum < 1.0
                ? $this->finiteRange(
                    SpecialFunctions::inverseErf($minimum),
                    SpecialFunctions::inverseErf($maximum),
                )
                : null,
            'erfcinv' => $minimum > 0.0 && $maximum < 2.0
                ? $this->finiteRange(
                    SpecialFunctions::inverseErfc($maximum),
                    SpecialFunctions::inverseErfc($minimum),
                )
                : null,
            'sech' => $this->finiteRange(0.0, 1.0),
            'exp' => $this->finiteRange(\exp($minimum), \exp($maximum)),
            'expm1' => $this->finiteRange(\expm1($minimum), \expm1($maximum)),
            'sinh' => $this->finiteRange(\sinh($minimum), \sinh($maximum)),
            'tanh' => $this->finiteRange(\tanh($minimum), \tanh($maximum)),
            'asinh' => $this->finiteRange(\asinh($minimum), \asinh($maximum)),
            'atan' => $this->finiteRange(\atan($minimum), \atan($maximum)),
            'acot' => [0.0, \M_PI],
            'sign' => match (true) {
                $minimum > 0.0 => [1.0, 1.0],
                $maximum < 0.0 => [-1.0, -1.0],
                $minimum === 0.0 && $maximum === 0.0 => [0.0, 0.0],
                $minimum < 0.0 && $maximum > 0.0 => [-1.0, 1.0],
                $minimum < 0.0 => [-1.0, 0.0],
                default => [0.0, 1.0],
            },
            'asin' => $minimum <= 1.0 && $maximum >= -1.0
                ? [-\M_PI_2, \M_PI_2]
                : null,
            'acos' => $minimum <= 1.0 && $maximum >= -1.0
                ? [0.0, \M_PI]
                : null,
            'acosh' => $maximum >= 1.0
                ? $this->finiteRange(\acosh(\max(1.0, $minimum)), \acosh($maximum))
                : null,
            'atanh' => $minimum > -1.0 && $maximum < 1.0
                ? $this->finiteRange(\atanh($minimum), \atanh($maximum))
                : null,
            'acsch' => $minimum > 0.0 || $maximum < 0.0
                ? $this->finiteRange(\asinh(1.0 / $maximum), \asinh(1.0 / $minimum))
                : null,
            'acoth' => $minimum > 1.0 || $maximum < -1.0
                ? $this->finiteRange(
                    0.5 * \log(($maximum + 1.0) / ($maximum - 1.0)),
                    0.5 * \log(($minimum + 1.0) / ($minimum - 1.0)),
                )
                : null,
            'asec' => $maximum >= 1.0 || $minimum <= -1.0
                ? [0.0, \M_PI]
                : null,
            'acsc' => $maximum >= 1.0 || $minimum <= -1.0
                ? [-\M_PI_2, \M_PI_2]
                : null,
            'asech' => $maximum > 0.0 && $minimum <= 1.0 && $minimum > 0.0
                ? $this->finiteRange(
                    \acosh(1.0 / \min($maximum, 1.0)),
                    \acosh(1.0 / $minimum),
                )
                : null,
            'sqrt' => $maximum >= 0.0
                ? $this->finiteRange(\sqrt(\max(0.0, $minimum)), \sqrt($maximum))
                : null,
            'ln' => $minimum > 0.0
                ? $this->finiteRange(\log($minimum), \log($maximum))
                : null,
            'log2' => $minimum > 0.0
                ? $this->finiteRange(\log($minimum, 2.0), \log($maximum, 2.0))
                : null,
            'log10' => $minimum > 0.0
                ? $this->finiteRange(\log10($minimum), \log10($maximum))
                : null,
            'log1p' => $minimum > -1.0
                ? $this->finiteRange(\log1p($minimum), \log1p($maximum))
                : null,
            'abs' => $this->finiteRange(
                $minimum <= 0.0 && $maximum >= 0.0
                    ? 0.0
                    : \min(\abs($minimum), \abs($maximum)),
                \max(\abs($minimum), \abs($maximum)),
            ),
            'floor' => $this->finiteRange(\floor($minimum), \floor($maximum)),
            'ceil' => $this->finiteRange(\ceil($minimum), \ceil($maximum)),
            'round' => $this->finiteRange(\round($minimum), \round($maximum)),
            'trunc' => $this->finiteRange(
                $minimum < 0.0 ? \ceil($minimum) : \floor($minimum),
                $maximum < 0.0 ? \ceil($maximum) : \floor($maximum),
            ),
            'cbrt' => $this->integerRootRange($range, 3.0),
            default => null,
        };
    }

    /**
     * @param array<string, int|float> $parameters
     * @return array{float, float}|null
     * @internal Used by equation-set solvers to enumerate bounded integer levels.
     */
    public function boundedExpressionRangeForSolver(
        Node $node,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
    ): ?array {
        return $this->boundedExpressionRange(
            $node,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $options,
        );
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function unboundedLogDirection(
        Node $node,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
    ): int {
        if ($node instanceof GroupingNode) {
            return $this->unboundedLogDirection(
                $node->expression,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
        }

        if ($node instanceof UnaryOperationNode) {
            $direction = $this->unboundedLogDirection(
                $node->operand,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );

            return $node->operator->value === '-' ? -$direction : $direction;
        }

        if ($node instanceof BinaryOperationNode) {
            $left = $this->unboundedLogDirection(
                $node->left,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            $right = $this->unboundedLogDirection(
                $node->right,
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );
            if ($node->operator->value === '-') {
                $right = -$right;
            }
            if ($node->operator->value === '+' || $node->operator->value === '-') {
                if ($left === 0) {
                    return $right;
                }

                return $right === 0 || $left === $right ? $left : 0;
            }

            if ($left !== 0 && $right === 0) {
                $factor = $this->boundedExpressionRange(
                    $node->right,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                );
                if ($factor !== null && ($factor[0] > 0.0 || $factor[1] < 0.0)) {
                    return $factor[0] < 0.0 ? -$left : $left;
                }
            }

            if ($node->operator->value === '*' && $right !== 0 && $left === 0) {
                $factor = $this->boundedExpressionRange(
                    $node->left,
                    $variable,
                    $lowerBound,
                    $upperBound,
                    $parameters,
                    $options,
                );
                if ($factor !== null && ($factor[0] > 0.0 || $factor[1] < 0.0)) {
                    return $factor[0] < 0.0 ? -$right : $right;
                }
            }

            return 0;
        }

        if (!$node instanceof FunctionCallNode || \count($node->arguments) !== 1) {
            return 0;
        }

        $boundary = match ($node->name) {
            'ln', 'log2', 'log10' => 0.0,
            'log1p' => -1.0,
            default => null,
        };
        if ($boundary !== null) {
            $range = $this->boundedExpressionRange(
                $node->arguments[0],
                $variable,
                $lowerBound,
                $upperBound,
                $parameters,
                $options,
            );

            return $range !== null && $range[0] <= $boundary && $range[1] > $boundary ? -1 : 0;
        }

        $direction = $this->unboundedLogDirection(
            $node->arguments[0],
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $options,
        );

        return match ($node->name) {
            'abs' => $direction === 0 ? 0 : 1,
            'cbrt', 'sinh' => $direction,
            default => 0,
        };
    }

    /**
     * Detect a reciprocal of the solved variable that tends to negative
     * infinity from either side of zero, where polygamma poles accumulate.
     *
     * @param array<string, int|float> $parameters
     */
    private function unboundedReciprocalDirection(
        Node $node,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
        SolverOptions $solverOptions,
    ): int {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }
        if (
            !$node instanceof BinaryOperationNode
            || $node->operator !== BinaryOperator::Divide
            || $this->containsVariable($node->left, $variable)
        ) {
            return 0;
        }
        if (
            $lowerBound > 0.0
            || $upperBound < 0.0
            || ($lowerBound === 0.0 && $upperBound === 0.0)
        ) {
            return 0;
        }

        $denominatorCoefficients = (new PolynomialExtractor(
            $variable,
            $parameters,
            $options,
            $solverOptions->maxPolynomialDegree,
        ))->extract($node->right);
        if ($denominatorCoefficients === null || ($denominatorCoefficients[0] ?? null) !== 0.0) {
            return 0;
        }
        $denominatorDegree = 0;
        while (
            isset($denominatorCoefficients[$denominatorDegree])
            && $denominatorCoefficients[$denominatorDegree] === 0.0
        ) {
            ++$denominatorDegree;
        }
        $leadingCoefficient = $denominatorCoefficients[$denominatorDegree] ?? 0.0;
        if ($leadingCoefficient === 0.0) {
            return 0;
        }

        try {
            $numerator = (float) $this->evaluate($node->left, $parameters, $options);
        } catch (EvaluationException) {
            return 0;
        }
        if ($numerator === 0.0 || !\is_finite($numerator)) {
            return 0;
        }

        $numeratorIsNegative = $numerator < 0.0;
        $denominatorIsNegativeLeftOfZero = ($leadingCoefficient < 0.0)
            !== (($denominatorDegree % 2) === 1);
        $denominatorIsNegativeRightOfZero = $leadingCoefficient < 0.0;
        $negativeInfinityOnLeft = $lowerBound < 0.0
            && ($numeratorIsNegative !== $denominatorIsNegativeLeftOfZero);
        $negativeInfinityOnRight = $upperBound > 0.0
            && ($numeratorIsNegative !== $denominatorIsNegativeRightOfZero);

        return $negativeInfinityOnLeft || $negativeInfinityOnRight ? -1 : 1;
    }

    /**
     * @param array{float, float} $base
     * @param array{float, float} $exponent
     * @return array{float, float}|null
     */
    private function integerPowerRange(array $base, array $exponent): ?array
    {
        if (
            $exponent[0] !== $exponent[1]
            || \floor($exponent[0]) !== $exponent[0]
            || \abs($exponent[0]) > 9_007_199_254_740_991.0
        ) {
            return null;
        }
        $degree = $exponent[0];
        if ($degree === 0.0) {
            return $base[0] <= 0.0 && $base[1] >= 0.0
                ? null
                : $this->finiteRange(1.0, 1.0);
        }
        if ($degree < 0.0 && $base[0] <= 0.0 && $base[1] >= 0.0) {
            return null;
        }

        $first = \pow($base[0], $degree);
        $second = \pow($base[1], $degree);
        if (!\is_finite($first) || !\is_finite($second)) {
            return null;
        }
        $minimum = \min($first, $second);
        if (
            $degree > 0.0
            && \fmod($degree, 2.0) === 0.0
            && $base[0] <= 0.0
            && $base[1] >= 0.0
        ) {
            $minimum = 0.0;
        }

        return $this->finiteRange($minimum, \max($first, $second));
    }

    /**
     * @param array{float, float} $radicand
     * @return array{float, float}|null
     */
    private function integerRootRange(array $radicand, float $degree): ?array
    {
        if (
            !\is_finite($degree)
            || $degree === 0.0
            || \floor($degree) !== $degree
            || \abs($degree) > 9_007_199_254_740_991.0
        ) {
            return null;
        }
        $order = \abs($degree);
        $odd = \fmod($order, 2.0) === 1.0;
        if (!$odd && $degree > 0.0 && $radicand[0] < 0.0) {
            if ($radicand[1] < 0.0) {
                return null;
            }
            $radicand[0] = 0.0;
        }
        if (($degree < 0.0 && $radicand[0] <= 0.0 && $radicand[1] >= 0.0)) {
            return null;
        }
        $root = static function (float $value) use ($order, $odd, $degree): ?float {
            if ($value < 0.0 && !$odd) {
                return null;
            }
            $result = $value < 0.0
                ? -\pow(-$value, 1.0 / $order)
                : \pow($value, 1.0 / $order);
            if ($degree < 0.0) {
                if ($result === 0.0) {
                    return null;
                }
                $result = 1.0 / $result;
            }

            return \is_finite($result) ? $result : null;
        };
        $first = $root($radicand[0]);
        $second = $root($radicand[1]);
        if ($first === null || $second === null) {
            return null;
        }

        return $this->finiteRange(\min($first, $second), \max($first, $second));
    }

    /**
     * @param array{float, float} $left
     * @param array{float, float} $right
     * @return array{float, float}|null
     */
    private function productRange(array $left, array $right): ?array
    {
        $products = [
            $left[0] * $right[0],
            $left[0] * $right[1],
            $left[1] * $right[0],
            $left[1] * $right[1],
        ];
        foreach ($products as $product) {
            if (!\is_finite($product)) {
                return null;
            }
        }

        return [\min(...$products), \max(...$products)];
    }

    /**
     * @param array{float, float} $numerator
     * @param array{float, float} $denominator
     * @return array{float, float}|null
     */
    private function quotientRange(array $numerator, array $denominator): ?array
    {
        if ($denominator[0] <= 0.0 && $denominator[1] >= 0.0) {
            return null;
        }

        return $this->productRange(
            $numerator,
            [1.0 / $denominator[1], 1.0 / $denominator[0]],
        );
    }

    /** @return array{float, float}|null */
    private function finiteRange(float $lower, float $upper): ?array
    {
        return \is_finite($lower) && \is_finite($upper)
            ? [$lower, $upper]
            : null;
    }

    /**
     * @return array{float, float}|null
     */
    private function trigonometricFunctionRange(
        string $name,
        float $minimum,
        float $maximum,
        EvaluationOptions $options,
    ): ?array {
        if ($name === 'sin' || $name === 'cos') {
            $width = $maximum - $minimum;
            if (!\is_finite($width) || $width >= 2.0 * \M_PI) {
                return [-1.0, 1.0];
            }
            $first = $this->functionValueAt($name, $minimum, $options);
            $second = $this->functionValueAt($name, $maximum, $options);
            if ($first === null || $second === null) {
                return null;
            }
            $lower = \min($first, $second);
            $upper = \max($first, $second);
            $minimumPhase = $name === 'sin' ? -\M_PI_2 : \M_PI;
            $maximumPhase = $name === 'sin' ? \M_PI_2 : 0.0;
            if ($this->containsPeriodicPhase($minimum, $maximum, $minimumPhase)) {
                $lower = -1.0;
            }
            if ($this->containsPeriodicPhase($minimum, $maximum, $maximumPhase)) {
                $upper = 1.0;
            }

            return [$lower, $upper];
        }

        $polePhase = $name === 'tan' || $name === 'sec' ? \M_PI_2 : 0.0;
        if ($this->containsPeriodicPhase($minimum, $maximum, $polePhase, \M_PI)) {
            return null;
        }
        $first = $this->functionValueAt($name, $minimum, $options);
        $second = $this->functionValueAt($name, $maximum, $options);
        if ($first === null || $second === null) {
            return null;
        }
        $lower = \min($first, $second);
        $upper = \max($first, $second);
        if ($name === 'sec') {
            if ($this->containsPeriodicPhase($minimum, $maximum, 0.0)) {
                $lower = 1.0;
            }
            if ($this->containsPeriodicPhase($minimum, $maximum, \M_PI)) {
                $upper = -1.0;
            }
        } elseif ($name === 'csc') {
            if ($this->containsPeriodicPhase($minimum, $maximum, \M_PI_2)) {
                $lower = 1.0;
            }
            if ($this->containsPeriodicPhase($minimum, $maximum, -\M_PI_2)) {
                $upper = -1.0;
            }
        }

        return $this->finiteRange($lower, $upper);
    }

    /** @param list<int|float> $additionalArguments */
    private function functionValueAt(
        string $name,
        float $argument,
        EvaluationOptions $options,
        array $additionalArguments = [],
    ): ?float {
        $function = $options->functions->find($name);
        if ($function === null) {
            return null;
        }
        try {
            $value = (float) $function->invoke([$argument, ...$additionalArguments]);
        } catch (\DomainException | \OverflowException | \InvalidArgumentException) {
            return null;
        }

        return \is_finite($value) ? $value : null;
    }

    /** @return array{float, float}|null */
    private function scaledComplementaryErrorFunctionRange(
        float $minimum,
        float $maximum,
        EvaluationOptions $options,
    ): ?array {
        $atMinimum = $this->functionValueAt('erfcx', $minimum, $options);
        $atMaximum = $this->functionValueAt('erfcx', $maximum, $options);
        if ($atMinimum === null || $atMaximum === null) {
            return null;
        }

        return $this->finiteRange($atMaximum, $atMinimum);
    }

    /** @return array{float, float}|null */
    private function increasingSpecialFunctionRange(
        string $name,
        float $minimum,
        float $maximum,
        EvaluationOptions $options,
    ): ?array {
        $atMinimum = $this->functionValueAt($name, $minimum, $options);
        $atMaximum = $this->functionValueAt($name, $maximum, $options);
        if ($atMinimum === null || $atMaximum === null) {
            return null;
        }

        return $this->finiteRange($atMinimum, $atMaximum);
    }

    /** @return array{float, float}|null */
    private function dawsonFunctionRange(
        float $minimum,
        float $maximum,
        EvaluationOptions $options,
    ): ?array {
        if ($minimum >= 1.0 || $maximum <= -1.0) {
            // D'(x) = 1 - 2*x*D(x) is negative for x >= 1; odd symmetry
            // makes Dawson increasing on the negative tail.
            $atMinimum = $this->functionValueAt('dawson', $minimum, $options);
            $atMaximum = $this->functionValueAt('dawson', $maximum, $options);
            if ($atMinimum === null || $atMaximum === null) {
                return null;
            }

            return $this->finiteRange(
                \min($atMinimum, $atMaximum),
                \max($atMinimum, $atMaximum),
            );
        }

        // Dawson's derivative has magnitude at most one, and |D(x)| < 1.
        $center = ($minimum / 2.0) + ($maximum / 2.0);
        $radius = ($maximum / 2.0) - ($minimum / 2.0);
        $centerValue = $this->functionValueAt('dawson', $center, $options);
        if ($centerValue === null || !\is_finite($radius)) {
            return null;
        }
        $roundingAllowance = 32.0 * \PHP_FLOAT_EPSILON
            * \max(1.0, \abs($centerValue), $radius);

        return [
            \max(-1.0, $centerValue - $radius - $roundingAllowance),
            \min(1.0, $centerValue + $radius + $roundingAllowance),
        ];
    }

    private function containsPeriodicPhase(
        float $lowerBound,
        float $upperBound,
        float $phase,
        float $period = 2.0 * \M_PI,
    ): bool {
        $scale = \max(1.0, \abs($lowerBound), \abs($upperBound));
        if ($scale > 1.0E14) {
            return true;
        }
        $tolerance = 8.0 * \PHP_FLOAT_EPSILON * $scale;
        $firstIndex = \ceil(($lowerBound - $phase - $tolerance) / $period);
        $lastIndex = \floor(($upperBound - $phase + $tolerance) / $period);

        return !\is_finite($firstIndex)
            || !\is_finite($lastIndex)
            || $firstIndex <= $lastIndex;
    }

    /** @param list<float> $coefficients */
    private function polynomialValue(array $coefficients, float $value): ?float
    {
        $result = 0.0;
        for ($index = \count($coefficients) - 1; $index >= 0; --$index) {
            $result = ($result * $value) + $coefficients[$index];
            if (!\is_finite($result)) {
                return null;
            }
        }

        return $result;
    }

    private function orientUnaryVariable(
        UnaryOperationNode $node,
        string $variable,
    ): ?float {
        $orientation = $this->signedVariableOrientation($node->operand, $variable);
        if ($orientation === null) {
            return null;
        }

        return $node->operator->value === '-' ? -$orientation : $orientation;
    }

    /** @param array<string, int|float> $parameters */
    private function validateInputs(
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
    ): void {
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
        if (
            !\is_finite($lowerBound)
            || !\is_finite($upperBound)
            || $lowerBound >= $upperBound
        ) {
            throw new \InvalidArgumentException(
                'The solving interval must have finite, increasing bounds.',
            );
        }
    }

    private function interpolate(float $lower, float $upper, float $fraction): float
    {
        if ($fraction <= 0.0) {
            return $lower;
        }
        if ($fraction >= 1.0) {
            return $upper;
        }

        return ($lower * (1.0 - $fraction)) + ($upper * $fraction);
    }

    private function midpoint(float $lower, float $upper): float
    {
        if ($lower < 0.0 && $upper > 0.0) {
            return ($lower / 2.0) + ($upper / 2.0);
        }

        return $lower + (($upper - $lower) / 2.0);
    }

    private function intervalWidthAtMostTolerance(
        float $lower,
        float $upper,
        float $tolerance,
    ): bool {
        return (($upper / 2.0) - ($lower / 2.0)) <= ($tolerance / 2.0);
    }

    private function parseSide(string $expression, int $offset, EvaluationOptions $options): Node
    {
        try {
            $tokens = (new Lexer($expression, $options->limits))->tokenize();

            return (new Parser($tokens, $options->limits, $options->functions))->parse();
        } catch (LexicalException | ParseException $error) {
            throw $this->shiftException($error, $offset);
        }
    }

    private function shiftException(MathException $error, int $offset): MathException
    {
        if ($offset === 0) {
            return $error;
        }

        $message = \preg_replace('/ at position \d+$/', '', $error->getMessage());
        $span = new SourceSpan(
            $error->span()->start + $offset,
            $error->span()->end + $offset,
        );

        return match (true) {
            $error instanceof LexicalException => new LexicalException(
                $message ?? 'Inequality expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
            $error instanceof ParseException => new ParseException(
                $message ?? 'Inequality expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
            default => new EvaluationException(
                $message ?? 'Inequality expression failed',
                $error->errorCode(),
                $span,
                $error,
            ),
        };
    }

    private function isSyntacticZeroExpression(Node $node): bool
    {
        while ($node instanceof GroupingNode) {
            $node = $node->expression;
        }

        if ($node instanceof NumberNode) {
            return (float) $node->value === 0.0
                && \preg_match('/[1-9]/', $node->literal) !== 1;
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->isSyntacticZeroExpression($node->operand);
        }
        if (!$node instanceof BinaryOperationNode) {
            return false;
        }

        return match ($node->operator) {
            BinaryOperator::Add, BinaryOperator::Subtract =>
                $this->isSyntacticZeroExpression($node->left)
                && $this->isSyntacticZeroExpression($node->right),
            BinaryOperator::Multiply =>
                $this->isSyntacticZeroExpression($node->left)
                || $this->isSyntacticZeroExpression($node->right),
            default => false,
        };
    }

    private function containsUnderflowedNumberLiteral(Node $node): bool
    {
        return match (true) {
            $node instanceof NumberNode => \is_float($node->value)
                && $node->value === 0.0
                && \preg_match('/[1-9]/', $node->literal) === 1,
            $node instanceof GroupingNode => $this->containsUnderflowedNumberLiteral($node->expression),
            $node instanceof UnaryOperationNode => $this->containsUnderflowedNumberLiteral($node->operand),
            $node instanceof BinaryOperationNode => $this->containsUnderflowedNumberLiteral($node->left)
                || $this->containsUnderflowedNumberLiteral($node->right),
            $node instanceof FactorialNode => $this->containsUnderflowedNumberLiteral($node->operand),
            $node instanceof FunctionCallNode => $this->containsUnderflowedNumberLiteralInArguments(
                $node->arguments,
            ),
            default => false,
        };
    }

    /** @param list<Node> $arguments */
    private function containsUnderflowedNumberLiteralInArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($this->containsUnderflowedNumberLiteral($argument)) {
                return true;
            }
        }

        return false;
    }

    private function containsVariable(Node $node, string $variable): bool
    {
        return match (true) {
            $node instanceof VariableNode => $node->name === $variable,
            $node instanceof GroupingNode => $this->containsVariable($node->expression, $variable),
            $node instanceof UnaryOperationNode => $this->containsVariable($node->operand, $variable),
            $node instanceof BinaryOperationNode => $this->containsVariable($node->left, $variable)
                || $this->containsVariable($node->right, $variable),
            $node instanceof FactorialNode => $this->containsVariable($node->operand, $variable),
            $node instanceof FunctionCallNode => $this->containsVariableInArguments(
                $node->arguments,
                $variable,
            ),
            default => false,
        };
    }

    private function containsVariableCall(
        Node $node,
        string $variable,
        EvaluationOptions $evaluationOptions,
    ): bool {
        if ($node instanceof FunctionCallNode) {
            if (
                $node->name === $variable
                && \count($node->arguments) === 1
                && $evaluationOptions->functions->find($node->name) === null
            ) {
                return true;
            }
            foreach ($node->arguments as $argument) {
                if ($this->containsVariableCall($argument, $variable, $evaluationOptions)) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof GroupingNode) {
            return $this->containsVariableCall($node->expression, $variable, $evaluationOptions);
        }
        if ($node instanceof UnaryOperationNode) {
            return $this->containsVariableCall($node->operand, $variable, $evaluationOptions);
        }
        if ($node instanceof BinaryOperationNode) {
            return $this->containsVariableCall($node->left, $variable, $evaluationOptions)
                || $this->containsVariableCall($node->right, $variable, $evaluationOptions);
        }
        if ($node instanceof FactorialNode) {
            return $this->containsVariableCall($node->operand, $variable, $evaluationOptions);
        }

        return false;
    }

    /** @param list<Node> $arguments */
    private function containsVariableInArguments(array $arguments, string $variable): bool
    {
        foreach ($arguments as $argument) {
            if ($this->containsVariable($argument, $variable)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, int|float> $parameters */
    private function matchesAt(
        Node $left,
        Node $right,
        string $operator,
        string $variable,
        float $value,
        array $parameters,
        EvaluationOptions $options,
        int $rightOffset,
        float $moduloRoundingUlps = 0.0,
    ): ?bool {
        $variables = [...$parameters, $variable => $value];
        try {
            $leftValue = $this->evaluate($left, $variables, $options, $moduloRoundingUlps);
        } catch (EvaluationException $error) {
            if ($this->mustPropagate($error)) {
                throw $error;
            }

            return null;
        }
        try {
            $rightValue = $this->evaluate($right, $variables, $options, $moduloRoundingUlps);
        } catch (EvaluationException $error) {
            if ($this->mustPropagate($error)) {
                throw $this->shiftException($error, $rightOffset);
            }

            return null;
        }

        return match ($operator) {
            '<' => $leftValue < $rightValue,
            '<=' => $leftValue <= $rightValue,
            '!=' => ($leftValue <=> $rightValue) !== 0,
            '>' => $leftValue > $rightValue,
            '>=' => $leftValue >= $rightValue,
            default => throw new \LogicException('Unexpected inequality operator.'),
        };
    }

    /** @param array<string, int|float> $variables */
    private function evaluate(
        Node $node,
        array $variables,
        EvaluationOptions $options,
        float $moduloRoundingUlps = 0.0,
    ): int|float {
        return (new Evaluator(
            new Environment($variables, $options->functions),
            $options->limits,
            moduloRoundingUlps: $moduloRoundingUlps,
        ))->evaluate($node);
    }

    private function mustPropagate(EvaluationException $error): bool
    {
        return \in_array(
            $error->errorCode(),
            ['eval.unknown_variable', 'eval.unknown_function', 'eval.arity', 'eval.custom_function'],
            true,
        );
    }

    /**
     * Refine a sampled comparison-state change that has no detected equation root.
     *
     * @param array<string, int|float> $parameters
     */
    private function bisectTransition(
        Node $left,
        Node $right,
        string $operator,
        string $variable,
        float $lower,
        bool $lowerState,
        float $upper,
        array $parameters,
        EvaluationOptions $evaluationOptions,
        int $rightOffset,
        SolverOptions $solverOptions,
    ): ?float {
        for ($iteration = 0; $iteration < $solverOptions->maxIterations; ++$iteration) {
            $middle = $this->midpoint($lower, $upper);
            if (
                $middle === $lower
                || $middle === $upper
                || $this->intervalWidthAtMostTolerance(
                    $lower,
                    $upper,
                    $solverOptions->positionTolerance($lower, $upper),
                )
            ) {
                return $middle;
            }

            $state = $this->matchesAt(
                $left,
                $right,
                $operator,
                $variable,
                $middle,
                $parameters,
                $evaluationOptions,
                $rightOffset,
            );
            if ($state === null || $state !== $lowerState) {
                $upper = $middle;
            } else {
                $lower = $middle;
            }
        }

        return null;
    }

    /** @param array<string, int|float> $parameters */
    private function differenceAt(
        Node $left,
        Node $right,
        string $variable,
        float $value,
        array $parameters,
        EvaluationOptions $options,
    ): ?float {
        $variables = [...$parameters, $variable => $value];
        try {
            $leftValue = $this->evaluate($left, $variables, $options);
            $rightValue = $this->evaluate($right, $variables, $options);
        } catch (EvaluationException) {
            return null;
        }

        $difference = (float) $leftValue - (float) $rightValue;

        return \is_finite($difference) ? $difference : null;
    }

    /**
     * @return list<float>|null
     */
    private function polynomialDifferenceCoefficients(
        Node $left,
        Node $right,
        PolynomialExtractor $extractor,
    ): ?array {
        try {
            $leftCoefficients = $extractor->extract($left);
            $rightCoefficients = $extractor->extract($right);
        } catch (EvaluationException) {
            return null;
        }
        if ($leftCoefficients === null || $rightCoefficients === null) {
            return null;
        }

        $difference = [];
        $length = \max(\count($leftCoefficients), \count($rightCoefficients));
        for ($degree = 0; $degree < $length; ++$degree) {
            $coefficient = ($leftCoefficients[$degree] ?? 0.0)
                - ($rightCoefficients[$degree] ?? 0.0);
            if (!\is_finite($coefficient)) {
                return null;
            }
            $difference[] = $coefficient;
        }
        while (\count($difference) > 1 && $difference[\array_key_last($difference)] === 0.0) {
            \array_pop($difference);
        }

        return $difference;
    }

    private function pointKey(float $point): string
    {
        if ($point === 0.0) {
            return '0';
        }

        return \sprintf('%.17g', $point);
    }

    /** @param list<float> $coefficients */
    private function polynomialSignAt(array $coefficients, float $value): ?int
    {
        if ($value === 0.0) {
            $constant = $coefficients[0] ?? 0.0;

            return $constant > 0.0 ? 1 : ($constant < 0.0 ? -1 : null);
        }

        $logMagnitude = \log(\abs($value));
        $terms = [];
        $maximumLog = -\INF;
        foreach ($coefficients as $degree => $coefficient) {
            if ($coefficient === 0.0) {
                continue;
            }
            $termLog = \log(\abs($coefficient)) + ($degree * $logMagnitude);
            if ($value < 0.0 && $degree % 2 === 1) {
                $coefficient = -$coefficient;
            }
            $terms[] = [$coefficient > 0.0 ? 1.0 : -1.0, $termLog];
            $maximumLog = \max($maximumLog, $termLog);
        }
        if ($terms === [] || !\is_finite($maximumLog)) {
            return null;
        }

        $sum = 0.0;
        $magnitude = 0.0;
        foreach ($terms as [$sign, $termLog]) {
            $term = \exp($termLog - $maximumLog);
            $sum += $sign * $term;
            $magnitude += $term;
        }
        if (\abs($sum) <= 8.0 * \PHP_FLOAT_EPSILON * $magnitude) {
            return null;
        }

        return $sum > 0.0 ? 1 : -1;
    }

    /** @param list<string> $coefficients */
    private function exactPolynomialSignAt(array $coefficients, float $value): ?int
    {
        if ($coefficients === [] || !\is_finite($value)) {
            return null;
        }
        $fraction = RationalLiteral::fromFloatExactAsStrings($value);
        if ($fraction === null || $fraction[1] === '0') {
            return null;
        }
        [$numerator, $denominator] = $fraction;
        $arithmetic = new IntegerPolynomialExtractor('', [], \count($coefficients) - 1);
        if ($denominator[0] === '-') {
            $numerator = $arithmetic->negateIntegerValue($numerator);
            $denominator = \substr($denominator, 1);
        }

        $valueNumerator = $coefficients[\array_key_last($coefficients)];
        $valueDenominator = '1';
        $maximumDigits = 8192;
        for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
            $valueDenominator = $arithmetic->multiplyIntegerValues($valueDenominator, $denominator);
            $valueNumerator = $arithmetic->addIntegerValues(
                $arithmetic->multiplyIntegerValues($valueNumerator, $numerator),
                $arithmetic->multiplyIntegerValues($coefficients[$degree], $valueDenominator),
            );
            if (
                \strlen(\ltrim($valueNumerator, '-')) > $maximumDigits
                || \strlen($valueDenominator) > $maximumDigits
            ) {
                return null;
            }
        }

        return $arithmetic->sign($valueNumerator);
    }

    /** @param array{list<string>, list<string>} $form */
    private function exactRationalSignAt(array $form, float $point): ?int
    {
        [$numerator, $denominator] = $form;
        $numeratorSign = $this->exactPolynomialSignAt($numerator, $point);
        $denominatorSign = $this->exactPolynomialSignAt($denominator, $point);
        if ($numeratorSign === null || $denominatorSign === null || $denominatorSign === 0) {
            return null;
        }
        if ($numeratorSign === 0) {
            return 0;
        }

        return $numeratorSign === $denominatorSign ? 1 : -1;
    }

    /** @param list<float> $coefficients */
    private function polynomialSignAdjacentToRoot(
        array $coefficients,
        float $root,
        int $direction,
    ): ?int {
        if ($coefficients === []) {
            return null;
        }
        $shifted = [$coefficients[\count($coefficients) - 1]];
        for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
            $next = \array_fill(0, \count($shifted) + 1, 0.0);
            foreach ($shifted as $power => $coefficient) {
                $next[$power] += $root * $coefficient;
                $next[$power + 1] += $coefficient;
                if (!\is_finite($next[$power]) || !\is_finite($next[$power + 1])) {
                    return null;
                }
            }
            $next[0] += $coefficients[$degree];
            if (!\is_finite($next[0])) {
                return null;
            }
            $shifted = \array_values($next);
        }

        for ($degree = 1; $degree < \count($shifted); ++$degree) {
            $coefficient = $shifted[$degree];
            if ($coefficient === 0.0) {
                continue;
            }
            if ($direction < 0 && $degree % 2 === 1) {
                $coefficient = -$coefficient;
            }

            return $coefficient > 0.0 ? 1 : -1;
        }

        return null;
    }

    private function signMatches(int $sign, string $operator): bool
    {
        return match ($operator) {
            '<' => $sign < 0,
            '<=' => $sign <= 0,
            '!=' => $sign !== 0,
            '>' => $sign > 0,
            '>=' => $sign >= 0,
            default => false,
        };
    }

    /** @param list<float> $coefficients */
    private function polynomialMatchesBetweenRoots(
        array $coefficients,
        float $leftRoot,
        float $rightRoot,
        string $operator,
    ): ?bool {
        if ($rightRoot <= $leftRoot || \count($coefficients) < 2) {
            return null;
        }

        if (\count($coefficients) === 3) {
            $valueSign = -$coefficients[2];
        } else {
            $shifted = [$coefficients[\array_key_last($coefficients)]];
            for ($degree = \count($coefficients) - 2; $degree >= 0; --$degree) {
                $next = \array_fill(0, \count($shifted) + 1, 0.0);
                foreach ($shifted as $index => $coefficient) {
                    $next[$index] += $leftRoot * $coefficient;
                    $next[$index + 1] += $coefficient;
                    if (
                        !\is_finite($next[$index])
                        || !\is_finite($next[$index + 1])
                    ) {
                        return null;
                    }
                }
                $next[0] += $coefficients[$degree];
                if (!\is_finite($next[0])) {
                    return null;
                }
                $shifted = \array_values($next);
            }

            $offset = ($rightRoot - $leftRoot) / 2.0;
            $valueSign = $shifted[\array_key_last($shifted)];
            for ($degree = \count($shifted) - 2; $degree >= 1; --$degree) {
                $valueSign = ($valueSign * $offset) + $shifted[$degree];
                if (!\is_finite($valueSign)) {
                    return null;
                }
            }
        }
        if ($valueSign === 0.0 || !\is_finite($valueSign)) {
            return null;
        }

        return match ($operator) {
            '<', '<=' => $valueSign < 0.0,
            '!=' => true,
            '>', '>=' => $valueSign > 0.0,
            default => null,
        };
    }

    /**
     * @param array{list<float>, list<float>} $form
     */
    private function rationalMatchesBetweenRoots(
        array $form,
        float $leftRoot,
        float $rightRoot,
        string $operator,
    ): ?bool {
        [$numerator, $denominator] = $form;
        $numeratorIsNegative = $this->polynomialMatchesBetweenRoots(
            $numerator,
            $leftRoot,
            $rightRoot,
            '<',
        );
        if ($numeratorIsNegative === null) {
            return null;
        }

        $denominatorValue = (new PolynomialSolver())->evaluatePolynomial(
            $denominator,
            $leftRoot,
        );
        if ($denominatorValue === null || $denominatorValue === 0.0) {
            return null;
        }
        $quotientIsNegative = $numeratorIsNegative !== ($denominatorValue < 0.0);

        return match ($operator) {
            '<', '<=' => $quotientIsNegative,
            '!=' => true,
            '>', '>=' => !$quotientIsNegative,
            default => null,
        };
    }

    /**
     * @param array{list<float>, list<float>} $form
     */
    private function rationalMatchesAdjacentToPole(
        array $form,
        float $pole,
        float $neighbor,
        int $direction,
        string $operator,
    ): ?bool {
        [$numerator, $denominator] = $form;
        $numeratorSign = $this->polynomialSignAt($numerator, $neighbor);
        $denominatorSign = $this->polynomialSignAdjacentToRoot(
            $denominator,
            $pole,
            $direction,
        );
        if ($numeratorSign === null || $denominatorSign === null) {
            return null;
        }
        $quotientSign = $numeratorSign === $denominatorSign ? 1 : -1;

        return $this->signMatches($quotientSign, $operator);
    }

    /**
     * @param array{list<float>, list<float>} $form
     */
    private function rationalSignAt(array $form, float $point): ?int
    {
        [$numerator, $denominator] = $form;
        $numeratorSign = $this->polynomialSignAt($numerator, $point);
        $denominatorSign = $this->polynomialSignAt($denominator, $point);
        if ($numeratorSign === null || $denominatorSign === null) {
            return null;
        }

        return $numeratorSign === $denominatorSign ? 1 : -1;
    }

    /**
     * @param list<InequalityInterval> $intervals
     * @return list<InequalityInterval>
     */
    private function merge(array $intervals): array
    {
        \usort(
            $intervals,
            static fn (InequalityInterval $left, InequalityInterval $right): int =>
                [$left->lowerBound, $left->upperBound] <=> [$right->lowerBound, $right->upperBound],
        );
        /** @var list<InequalityInterval> $merged */
        $merged = [];
        foreach ($intervals as $interval) {
            if ($merged === []) {
                $merged[] = $interval;
                continue;
            }

            $lastIndex = \count($merged) - 1;
            $previous = $merged[$lastIndex];
            $touches = $interval->lowerBound < $previous->upperBound
                || ($interval->lowerBound === $previous->upperBound
                    && ($previous->includesUpperBound || $interval->includesLowerBound));
            if (!$touches) {
                $merged[] = $interval;
                continue;
            }

            $upperBound = \max($previous->upperBound, $interval->upperBound);
            $includesUpper = $interval->upperBound > $previous->upperBound
                ? $interval->includesUpperBound
                : ($interval->upperBound < $previous->upperBound
                    ? $previous->includesUpperBound
                    : ($previous->includesUpperBound || $interval->includesUpperBound));
            $merged[$lastIndex] = new InequalityInterval(
                $previous->lowerBound,
                $upperBound,
                $previous->includesLowerBound,
                $includesUpper,
            );
        }

        return $merged;
    }
}
