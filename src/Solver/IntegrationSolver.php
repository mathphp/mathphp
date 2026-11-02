<?php

declare(strict_types=1);

namespace MathPHP\Solver;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;

/** Adaptive Gauss-Kronrod integration of supported scalar expressions. */
final class IntegrationSolver
{
    private const MAX_INTERVALS = 4096;

    private const INITIAL_INTERVALS = 64;

    private const MAX_FEATURE_BOUNDARIES = 4000;

    /** Kronrod abscissae on [0, 1], with the embedded 7-point Gauss weights. */
    private const X = [
        0.9914553711208126, 0.9491079123427585, 0.8648644233597691,
        0.7415311855993945, 0.5860872354676911, 0.4058451513773972,
        0.2077849550078985, 0.0,
    ];

    private const WK = [
        0.02293532201052922, 0.06309209262997855, 0.1047900103222502,
        0.1406532597155259, 0.1690047266392679, 0.1903505780647854,
        0.2044329400752989, 0.2094821410847278,
    ];

    private const WG = [
        0.4179591836734694, 0.3818300505051189,
        0.2797053914892767, 0.1294849661688697,
    ];

    /**
     * @param array<string, int|float> $parameters
     */
    public function integrate(
        string $expression,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters,
        EvaluationOptions $options,
        float $absoluteTolerance,
        float $relativeTolerance,
    ): float {
        if (
            \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) !== 1
            || \in_array($variable, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
        ) {
            throw new \InvalidArgumentException('The integration variable must be a non-reserved ASCII identifier.');
        }
        if (\array_key_exists($variable, $parameters)) {
            throw new \InvalidArgumentException('The integration variable cannot also be supplied as a parameter.');
        }
        if (
            \is_nan($lowerBound)
            || \is_nan($upperBound)
        ) {
            throw new \InvalidArgumentException('Integration bounds must not be NaN.');
        }
        if (
            $absoluteTolerance <= 0.0
            || !\is_finite($absoluteTolerance)
            || $relativeTolerance < 0.0 || !\is_finite($relativeTolerance)
        ) {
            throw new \InvalidArgumentException(
                'Integration tolerances must be finite, with absolute > 0 and relative >= 0.',
            );
        }
        if ($lowerBound === $upperBound && \is_finite($lowerBound)) {
            return 0.0;
        }
        if ($lowerBound === $upperBound) {
            throw new \InvalidArgumentException('An infinite bound cannot form a zero-width integration interval.');
        }

        $sign = 1.0;
        if ($lowerBound > $upperBound) {
            [$lowerBound, $upperBound] = [$upperBound, $lowerBound];
            $sign = -1.0;
        }
        if ($lowerBound === \INF || $upperBound === -\INF) {
            throw new \InvalidArgumentException('Integration bounds must define a nonempty interval.');
        }
        $finiteBounds = \is_finite($lowerBound) && \is_finite($upperBound);
        $wholeLine = $lowerBound === -\INF && $upperBound === \INF;
        if (!$finiteBounds && !$wholeLine && \is_infinite($lowerBound) && \is_infinite($upperBound)) {
            throw new \InvalidArgumentException('Integration bounds must define a nonempty ordered interval.');
        }
        $width = $finiteBounds ? $upperBound - $lowerBound : 0.0;
        if ($finiteBounds && !\is_finite($width)) {
            throw new \InvalidArgumentException('The finite integration interval width exceeds the numeric range.');
        }

        $tokens = (new Lexer($expression, $options->limits))->tokenize();
        $ast = (new Parser($tokens, $options->limits, $options->functions))->parse();
        if ($finiteBounds) {
            $linearAbsoluteIntegral = $this->integrateLinearPower(
                $ast,
                $variable,
                $parameters,
                $options,
                $lowerBound,
                $upperBound,
            );
            if ($linearAbsoluteIntegral !== null) {
                return $sign * $linearAbsoluteIntegral;
            }
        }
        $higherOrderLowerMap = $finiteBounds
            && $lowerBound === 0.0
            && $this->hasLowerEndpointAlgebraicSingularity($ast, $variable, $parameters, $options);
        $evaluate = static function (float $unit) use (
            $ast,
            $variable,
            $parameters,
            $options,
            $lowerBound,
            $upperBound,
            $width,
            $finiteBounds,
            $wholeLine,
            $higherOrderLowerMap,
        ): float {
            if ($finiteBounds) {
                if ($higherOrderLowerMap) {
                    $sine = \sin((\M_PI / 2.0) * $unit);
                    $trigonometricUnit = $sine * $sine;
                    $denominator = ($trigonometricUnit * $trigonometricUnit) + (1.0 - $trigonometricUnit);
                    $mappedUnit = ($trigonometricUnit * $trigonometricUnit) / $denominator;
                    $unitJacobian = (
                        $trigonometricUnit * (2.0 - $trigonometricUnit) / ($denominator * $denominator)
                    ) * ((\M_PI / 2.0) * \sin(\M_PI * $unit));
                } else {
                    $angle = (\M_PI / 2.0) * $unit;
                    $sine = \sin($angle);
                    $mappedUnit = $sine * $sine;
                    $unitJacobian = (\M_PI / 2.0) * \sin(\M_PI * $unit);
                }
            } else {
                $mappedUnit = $unit;
                $unitJacobian = 1.0;
            }
            if ($mappedUnit <= 0.0 || $mappedUnit >= 1.0) {
                throw new \DomainException(
                    'The integral transformation reached an endpoint at floating-point resolution.',
                );
            }
            if ($finiteBounds) {
                $x = $lowerBound + ($width * $mappedUnit);
                $jacobian = $width * $unitJacobian;
            } elseif ($wholeLine) {
                $lineAngle = \M_PI * ($mappedUnit - 0.5);
                $cosine = \cos($lineAngle);
                $x = \tan($lineAngle);
                $jacobian = (\M_PI / ($cosine * $cosine)) * $unitJacobian;
            } else {
                $tailUnit = $mappedUnit / (1.0 - $mappedUnit);
                $x = \is_infinite($upperBound)
                    ? $lowerBound + $tailUnit
                    : $upperBound - $tailUnit;
                $jacobian = $unitJacobian / ((1.0 - $mappedUnit) ** 2);
            }
            if (!\is_finite($x) || !\is_finite($jacobian)) {
                throw new \DomainException('The improper integral exceeds the representable transformation range.');
            }
            $environment = new Environment([...$parameters, $variable => $x], $options->functions);
            return $jacobian * (float) (new Evaluator($environment, $options->limits))->evaluate($ast);
        };

        $unitBoundaries = $this->featureAwareUnitBoundaries(
            $ast,
            $variable,
            $parameters,
            $options,
            $lowerBound,
            $upperBound,
            $finiteBounds,
            $wholeLine,
            $higherOrderLowerMap,
        );
        /** @var list<array{float, float, float, float}> */
        $intervals = [];
        $total = 0.0;
        $totalError = 0.0;
        $boundaries = [0.0, 1.0];
        for ($index = 1; $index < self::INITIAL_INTERVALS; ++$index) {
            $boundaries[] = $index / self::INITIAL_INTERVALS;
        }
        \array_push($boundaries, ...$unitBoundaries);
        \sort($boundaries, SORT_NUMERIC);
        $boundaries = \array_values(\array_unique($boundaries, SORT_REGULAR));
        for ($index = 0; $index < \count($boundaries) - 1; ++$index) {
            $left = $boundaries[$index];
            $right = $boundaries[$index + 1];
            [$estimate, $error] = $this->estimate($evaluate, $left, $right);
            $intervals[] = [$left, $right, $estimate, $error];
            $total += $estimate;
            $totalError += $error;
        }
        if (!\is_finite($total) || !\is_finite($totalError)) {
            throw new \DomainException('The integral exceeds the finite numeric range.');
        }
        while (!$this->withinTolerance($total, $totalError, $absoluteTolerance, $relativeTolerance)) {
            if (\count($intervals) >= self::MAX_INTERVALS) {
                throw new \DomainException('The integral did not converge within the interval limit.');
            }
            $worst = 0;
            foreach ($intervals as $index => $interval) {
                if ($interval[3] > $intervals[$worst][3]) {
                    $worst = $index;
                }
            }
            [$a, $b] = $intervals[$worst];
            $middle = $a + (($b - $a) / 2.0);
            if ($middle === $a || $middle === $b) {
                throw new \DomainException(
                    'The integral cannot meet its error tolerance at floating-point resolution.',
                );
            }
            [$left, $leftError] = $this->estimate($evaluate, $a, $middle);
            [$right, $rightError] = $this->estimate($evaluate, $middle, $b);
            $intervals[$worst] = [$a, $middle, $left, $leftError];
            $intervals[] = [$middle, $b, $right, $rightError];
            $total = 0.0;
            $totalError = 0.0;
            foreach ($intervals as $interval) {
                $total += $interval[2];
                $totalError += $interval[3];
            }
            if (!\is_finite($total) || !\is_finite($totalError)) {
                throw new \DomainException('The integral exceeds the finite numeric range.');
            }
        }

        return $sign * $total;
    }

    /**
     * Integrate c/|a*x+b|^p exactly for finite bounds and 0 < p < 1.
     *
     * @param array<string, int|float> $parameters
     */
    private function integrateLinearPower(
        Node $ast,
        string $variable,
        array $parameters,
        EvaluationOptions $options,
        float $lowerBound,
        float $upperBound,
    ): ?float {
        $expression = $ast;
        while ($expression instanceof GroupingNode) {
            $expression = $expression->expression;
        }
        $extractor = new PolynomialExtractor($variable, $parameters, $options);
        $coefficient = 1.0;
        $absolutePower = null;
        while (true) {
            while ($expression instanceof GroupingNode) {
                $expression = $expression->expression;
            }
            if ($expression instanceof UnaryOperationNode) {
                if ($expression->operator === UnaryOperator::Minus) {
                    $coefficient = -$coefficient;
                }
                $expression = $expression->operand;
                continue;
            }
            if (!$expression instanceof BinaryOperationNode || $expression->operator !== BinaryOperator::Multiply) {
                break;
            }
            $leftScalar = $extractor->extract($expression->left);
            $rightScalar = $extractor->extract($expression->right);
            if ($leftScalar !== null && \count($leftScalar) === 1) {
                $coefficient *= $leftScalar[0];
                $expression = $expression->right;
            } elseif ($rightScalar !== null && \count($rightScalar) === 1) {
                $coefficient *= $rightScalar[0];
                $expression = $expression->left;
            } else {
                break;
            }
        }
        if ($expression instanceof BinaryOperationNode && $expression->operator === BinaryOperator::Divide) {
            $numerator = $extractor->extract($expression->left);
            $denominator = $expression->right;
            while ($denominator instanceof GroupingNode) {
                $denominator = $denominator->expression;
            }
            if (
                $numerator === null
                || \count($numerator) !== 1
                || !$denominator instanceof BinaryOperationNode
                || $denominator->operator !== BinaryOperator::Power
            ) {
                return null;
            }
            $exponent = $extractor->extract($denominator->right);
            if ($exponent === null || \count($exponent) !== 1 || $exponent[0] <= 0.0 || $exponent[0] >= 1.0) {
                return null;
            }
            $coefficient *= $numerator[0];
            $absolutePower = $denominator->left;
            $power = $exponent[0];
        } elseif ($expression instanceof BinaryOperationNode && $expression->operator === BinaryOperator::Power) {
            $exponent = $extractor->extract($expression->right);
            if ($exponent === null || \count($exponent) !== 1 || $exponent[0] <= -1.0 || $exponent[0] >= 0.0) {
                return null;
            }
            $absolutePower = $expression->left;
            $power = -$exponent[0];
        } else {
            return null;
        }

        while ($absolutePower instanceof GroupingNode) {
            $absolutePower = $absolutePower->expression;
        }
        $absoluteValue = $absolutePower instanceof FunctionCallNode
            && $absolutePower->name === 'abs'
            && \count($absolutePower->arguments) === 1;
        $base = $absoluteValue ? $absolutePower->arguments[0] : $absolutePower;
        $linear = $extractor->extract($base);
        if ($linear === null || \count($linear) !== 2 || $linear[1] === 0.0) {
            return null;
        }

        $root = -($linear[0] / $linear[1]);
        if (!\is_finite($root)) {
            return null;
        }
        if (!$absoluteValue) {
            $lowerBase = $linear[0] + ($linear[1] * $lowerBound);
            $upperBase = $linear[0] + ($linear[1] * $upperBound);
            if ($lowerBase < 0.0 || $upperBase < 0.0) {
                return null;
            }
        }
        $primitive = static function (float $x) use ($linear, $root, $power, $absoluteValue): float {
            $baseValue = $linear[0] + ($linear[1] * $x);
            if ($absoluteValue) {
                return ($x < $root ? -1.0 : 1.0)
                    * (\abs($x - $root) ** (1.0 - $power))
                    / (1.0 - $power);
            }

            return ($baseValue ** (1.0 - $power)) / ($linear[1] * (1.0 - $power));
        };
        $scaledCoefficient = $absoluteValue
            ? $coefficient / (\abs($linear[1]) ** $power)
            : $coefficient;
        $integral = $scaledCoefficient * ($primitive($upperBound) - $primitive($lowerBound));

        return \is_finite($integral) ? $integral : null;
    }

    /**
     * @param array<string, int|float> $parameters
     */
    private function hasLowerEndpointAlgebraicSingularity(
        Node $ast,
        string $variable,
        array $parameters,
        EvaluationOptions $options,
    ): bool {
        $extractor = new PolynomialExtractor($variable, $parameters, $options);
        $found = false;
        $visit = function (Node $node) use (&$visit, &$found, $extractor): void {
            if ($node instanceof BinaryOperationNode) {
                if ($node->operator === BinaryOperator::Divide) {
                    $denominator = $node->right;
                    while ($denominator instanceof GroupingNode) {
                        $denominator = $denominator->expression;
                    }
                    if (
                        $denominator instanceof BinaryOperationNode
                        && $denominator->operator === BinaryOperator::Power
                    ) {
                        $base = $extractor->extract($denominator->left);
                        $exponent = $extractor->extract($denominator->right);
                        if (
                            $base !== null
                            && \count($base) > 1
                            && $base[0] === 0.0
                            && $exponent !== null
                            && \count($exponent) === 1
                            && $exponent[0] > 0.0
                            && $exponent[0] < 1.0
                        ) {
                            $found = true;
                        }
                    }
                }
                $visit($node->left);
                $visit($node->right);
            } elseif ($node instanceof FunctionCallNode) {
                foreach ($node->arguments as $argument) {
                    $visit($argument);
                }
            } elseif ($node instanceof UnaryOperationNode || $node instanceof GroupingNode) {
                $visit($node instanceof UnaryOperationNode ? $node->operand : $node->expression);
            } elseif ($node instanceof FactorialNode) {
                $visit($node->operand);
            }
        };
        $visit($ast);

        return $found;
    }

    /**
     * Seed the adaptive rule around narrow polynomial features scaled by a
     * small constant divisor, which a uniform pilot mesh can otherwise miss.
     *
     * @param array<string, int|float> $parameters
     * @return list<float>
     */
    private function featureAwareUnitBoundaries(
        Node $ast,
        string $variable,
        array $parameters,
        EvaluationOptions $options,
        float $lowerBound,
        float $upperBound,
        bool $finiteBounds,
        bool $wholeLine,
        bool $higherOrderLowerMap,
    ): array {
        $width = $finiteBounds ? $upperBound - $lowerBound : 0.0;
        $extractor = new PolynomialExtractor($variable, $parameters, $options);
        $polynomialSolver = new PolynomialSolver();
        $rootsByScale = [];
        $visit = function (Node $node) use (
            &$visit,
            &$rootsByScale,
            $extractor,
            $polynomialSolver,
            $lowerBound,
            $upperBound,
            $width,
            $finiteBounds,
            $wholeLine,
        ): void {
            if ($node instanceof BinaryOperationNode) {
                if ($node->operator === BinaryOperator::Divide) {
                    $scaleCoefficients = $extractor->extract($node->right);
                    $numeratorCoefficients = $extractor->extract($node->left);
                    if (
                        $scaleCoefficients !== null
                        && \count($scaleCoefficients) === 1
                        && $numeratorCoefficients !== null
                        && \count($numeratorCoefficients) > 1
                    ) {
                        $scale = \abs($scaleCoefficients[0]);
                        if ($scale > 0.0) {
                            $this->recordScaledPolynomialFeatures(
                                $rootsByScale,
                                $node->left,
                                $numeratorCoefficients,
                                $scale,
                                true,
                                $extractor,
                                $polynomialSolver,
                                $lowerBound,
                                $upperBound,
                                $width,
                                $finiteBounds,
                                $wholeLine,
                            );
                        }
                    }
                } elseif ($node->operator === BinaryOperator::Multiply) {
                    $factorizations = [
                        [$node->left, $node->right],
                        [$node->right, $node->left],
                    ];
                    foreach ($factorizations as [$scaleNode, $polynomialNode]) {
                        $scaleCoefficients = $extractor->extract($scaleNode);
                        $polynomialCoefficients = $extractor->extract($polynomialNode);
                        if (
                            $scaleCoefficients === null
                            || \count($scaleCoefficients) !== 1
                            || $polynomialCoefficients === null
                            || \count($polynomialCoefficients) < 2
                        ) {
                            continue;
                        }
                        $multiplier = \abs($scaleCoefficients[0]);
                        if ($multiplier <= 0.0) {
                            continue;
                        }
                        $this->recordScaledPolynomialFeatures(
                            $rootsByScale,
                            $polynomialNode,
                            $polynomialCoefficients,
                            $multiplier,
                            false,
                            $extractor,
                            $polynomialSolver,
                            $lowerBound,
                            $upperBound,
                            $width,
                            $finiteBounds,
                            $wholeLine,
                        );
                    }
                }
                $visit($node->left);
                $visit($node->right);
            } elseif ($node instanceof FunctionCallNode) {
                foreach ($node->arguments as $argument) {
                    $visit($argument);
                }
            } elseif ($node instanceof UnaryOperationNode || $node instanceof GroupingNode) {
                $visit($node instanceof UnaryOperationNode ? $node->operand : $node->expression);
            } elseif ($node instanceof FactorialNode) {
                $visit($node->operand);
            }
        };
        $visit($ast);

        $boundaries = [];
        foreach ($rootsByScale as $feature) {
            foreach ($feature['roots'] ?? [] as $root) {
                $scale = $feature['scale'];
                foreach ([-8.0, -4.0, -2.0, -1.0, 0.0, 1.0, 2.0, 4.0, 8.0] as $multiple) {
                    $x = $root + ($multiple * $scale);
                    if (!\is_finite($x) || $x <= $lowerBound || $x >= $upperBound) {
                        continue;
                    }
                    $unit = $this->unitCoordinateForX(
                        $x,
                        $lowerBound,
                        $upperBound,
                        $width,
                        $finiteBounds,
                        $wholeLine,
                        $higherOrderLowerMap,
                    );
                    if ($unit > 0.0 && $unit < 1.0 && \is_finite($unit)) {
                        $boundaries[] = $unit;
                        if (\count($boundaries) >= self::MAX_FEATURE_BOUNDARIES) {
                            break 3;
                        }
                    }
                }
            }
        }

        return $boundaries;
    }

    /**
     * @param array<int|string, array{scale: float, roots?: list<float>}> $rootsByScale
     * @param list<float> $coefficients
     */
    private function recordScaledPolynomialFeatures(
        array &$rootsByScale,
        Node $polynomialNode,
        array $coefficients,
        float $scale,
        bool $isDivisor,
        PolynomialExtractor $extractor,
        PolynomialSolver $polynomialSolver,
        float $lowerBound,
        float $upperBound,
        float $width,
        bool $finiteBounds,
        bool $wholeLine,
    ): void {
        try {
            $roots = $polynomialSolver->solve(
                $coefficients,
                $lowerBound,
                $upperBound,
                static fn (float $x): ?float => $polynomialSolver->evaluatePolynomial($coefficients, $x),
                new SolverOptions(),
                static fn (float $x): ?float => $polynomialSolver->evaluatePolynomialMagnitude($coefficients, $x),
            );
        } catch (\DomainException) {
            return;
        }

        foreach ($roots ?? [] as $root) {
            $taylor = $extractor->extractInAffineCoordinate($polynomialNode, $root->value, 1.0);
            if ($taylor === null) {
                continue;
            }
            $coefficientScale = 0.0;
            foreach ($taylor as $coefficient) {
                $coefficientScale = \max($coefficientScale, \abs($coefficient));
            }
            for ($order = 1; $order < \count($taylor); ++$order) {
                $localCoefficient = \abs($taylor[$order]);
                if ($localCoefficient <= $coefficientScale * 1.0E-10) {
                    continue;
                }
                $scaledCoefficient = $isDivisor
                    ? $localCoefficient / $scale
                    : $localCoefficient * $scale;
                if (!\is_finite($scaledCoefficient) || $scaledCoefficient <= 0.0) {
                    continue;
                }
                $featureScale = (1.0 / $scaledCoefficient) ** (1.0 / $order);
                if ($featureScale > 0.0) {
                    $this->recordFeatureRoots(
                        $rootsByScale,
                        $coefficients,
                        $featureScale,
                        $polynomialSolver,
                        $lowerBound,
                        $upperBound,
                        $width,
                        $finiteBounds,
                        $wholeLine,
                        [$root->value],
                    );
                }
            }
        }
    }

    /**
     * @param array<int|string, array{scale: float, roots?: list<float>}> $rootsByScale
     * @param list<float> $coefficients
     * @param list<float>|null $knownRoots
     */
    private function recordFeatureRoots(
        array &$rootsByScale,
        array $coefficients,
        float $scale,
        PolynomialSolver $polynomialSolver,
        float $lowerBound,
        float $upperBound,
        float $width,
        bool $finiteBounds,
        bool $wholeLine,
        ?array $knownRoots = null,
    ): void {
        if ($scale <= 0.0) {
            return;
        }
        if ($knownRoots === null) {
            try {
                $roots = $polynomialSolver->solve(
                    $coefficients,
                    $lowerBound,
                    $upperBound,
                    static fn (float $x): ?float => $polynomialSolver->evaluatePolynomial($coefficients, $x),
                    new SolverOptions(),
                    static fn (float $x): ?float => $polynomialSolver->evaluatePolynomialMagnitude($coefficients, $x),
                );
            } catch (\DomainException) {
                return;
            }
            $knownRoots = \array_map(static fn (EquationSolution $root): float => $root->value, $roots ?? []);
        }
        foreach ($knownRoots as $root) {
            if (
                !$this->featureScaleIsNarrow(
                    $scale,
                    $root,
                    $lowerBound,
                    $upperBound,
                    $width,
                    $finiteBounds,
                    $wholeLine,
                )
            ) {
                continue;
            }
            $scaleKey = \sprintf('%.17g', $scale);
            $rootsByScale[$scaleKey]['scale'] = $scale;
            $rootsByScale[$scaleKey]['roots'][] = $root;
        }
    }

    private function featureScaleIsNarrow(
        float $scale,
        float $root,
        float $lowerBound,
        float $upperBound,
        float $width,
        bool $finiteBounds,
        bool $wholeLine,
    ): bool {
        if ($finiteBounds) {
            return $scale < $width / self::INITIAL_INTERVALS;
        }

        if ($wholeLine) {
            $localPanelWidth = \M_PI * (1.0 + ($root * $root)) / self::INITIAL_INTERVALS;
        } else {
            $distance = \is_infinite($upperBound)
                ? $root - $lowerBound
                : $upperBound - $root;
            $localPanelWidth = ((1.0 + $distance) ** 2) / self::INITIAL_INTERVALS;
        }

        return $scale < $localPanelWidth;
    }

    private function unitCoordinateForX(
        float $x,
        float $lowerBound,
        float $upperBound,
        float $width,
        bool $finiteBounds,
        bool $wholeLine,
        bool $higherOrderLowerMap,
    ): float {
        if ($finiteBounds) {
            if (!$higherOrderLowerMap) {
                return (2.0 / \M_PI) * \asin(\sqrt(($x - $lowerBound) / $width));
            }
            $relative = ($x - $lowerBound) / $width;
            $discriminant = ($relative * $relative) + (4.0 * $relative * (1.0 - $relative));
            $trigonometricUnit = (2.0 * $relative) / ($relative + \sqrt($discriminant));

            return (2.0 / \M_PI) * \asin(\sqrt($trigonometricUnit));
        }
        if ($wholeLine) {
            return 0.5 + (\atan($x) / \M_PI);
        }

        $distance = \is_infinite($upperBound)
            ? $x - $lowerBound
            : $upperBound - $x;

        return $distance > 1.0
            ? 1.0 - (1.0 / (1.0 + $distance))
            : $distance / (1.0 + $distance);
    }

    private function withinTolerance(
        float $estimate,
        float $error,
        float $absoluteTolerance,
        float $relativeTolerance,
    ): bool {
        return $error <= $absoluteTolerance
            || ($estimate !== 0.0 && ($error / \abs($estimate)) <= $relativeTolerance);
    }

    /**
     * @param callable(float): float $function
     * @return array{float, float}
     */
    private function estimate(callable $function, float $lower, float $upper): array
    {
        $center = $lower + (($upper - $lower) / 2.0);
        $halfWidth = ($upper - $lower) / 2.0;
        $centerValue = $function($center);
        $kronrod = self::WK[7] * $centerValue;
        $gauss = self::WG[0] * $centerValue;
        $absolute = self::WK[7] * \abs($centerValue);
        $samples = [$centerValue];
        for ($index = 0; $index < 7; ++$index) {
            $offset = $halfWidth * self::X[$index];
            $left = $function($center - $offset);
            $right = $function($center + $offset);
            $samples[] = $left;
            $samples[] = $right;
            $pair = $left + $right;
            $kronrod += self::WK[$index] * $pair;
            $absolute += self::WK[$index] * (\abs($left) + \abs($right));
            if ($index === 1 || $index === 3 || $index === 5) {
                $gauss += self::WG[3 - intdiv($index - 1, 2)] * $pair;
            }
        }
        $estimate = $kronrod * $halfWidth;
        $mean = $kronrod / 2.0;
        $asc = self::WK[7] * \abs($centerValue - $mean);
        for ($index = 0; $index < 7; ++$index) {
            $offset = $halfWidth * self::X[$index];
            $asc += self::WK[$index] * (
                \abs($samples[1 + 2 * $index] - $mean)
                + \abs($samples[2 + 2 * $index] - $mean)
            );
        }
        $rawError = \abs(($kronrod - $gauss) * $halfWidth);
        $absolute *= \abs($halfWidth);
        $asc *= \abs($halfWidth);
        $error = $asc === 0.0 || $rawError === 0.0
            ? $rawError
            : $asc * \min(1.0, (200.0 * $rawError / $asc) ** 1.5);
        $error = \max($error, 50.0 * \PHP_FLOAT_EPSILON * $absolute);
        if (!\is_finite($estimate) || !\is_finite($error)) {
            throw new \DomainException('The integrand produced a non-finite quadrature estimate.');
        }
        return [$estimate, $error];
    }
}
