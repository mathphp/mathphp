<?php

declare(strict_types=1);

namespace MathPHP;

use MathPHP\Ast\RationalLiteral;
use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Exception\EvaluationException;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Solver\ArithmeticDifferentiator;
use MathPHP\Solver\ComplexPolynomialEquationSolver;
use MathPHP\Solver\ComplexPolynomialSolution;
use MathPHP\Solver\ComplexRationalEquationSolver;
use MathPHP\Solver\ComplexRationalSolution;
use MathPHP\Solver\EquationSolver;
use MathPHP\Solver\EquationSetSolver;
use MathPHP\Solver\EquationSolution;
use MathPHP\Solver\ExponentialEquationSolver;
use MathPHP\Solver\HyperbolicEquationSolver;
use MathPHP\Solver\InequalityInterval;
use MathPHP\Solver\InequalitySolver;
use MathPHP\Solver\IntegrationSolver;
use MathPHP\Solver\NumericalDifferentiator;
use MathPHP\Solver\NondifferentiablePointException;
use MathPHP\Solver\PolynomialRootCountingSolver;
use MathPHP\Solver\SolverOptions;
use MathPHP\Solver\SystemSolution;
use MathPHP\Solver\SystemSolver;
use MathPHP\Tracing\EvaluationObserver;

final class Math
{
    /**
     * Find real-root candidates for a one-variable equation in an interval,
     * including a singleton interval evaluated at its one point. Infinite
     * interval ends support exact rational-function equations, equal
     * injective-function reductions to supported equations, and
     * classification of direct trigonometric equations with polynomial
     * phases and constant targets. Exponential sums whose phases are real
     * multiples of one polynomial phase are also reduced to bounded
     * exponential-polynomial root searches when their tails admit dominance
     * bounds. Supported unbounded hypot equations are squared and candidates
     * are verified against the original equation.
     * Results are returned in ascending order. If subtracting the
     * finite bounds overflows, polynomial and rational equations are still
     * supported; other equations require a finite representable width.
     * Affine periodic equations exceeding the candidate limit throw
     * RootSearchLimitException instead of returning an incomplete root list.
     *
     * @param array<string, int|float> $parameters Values for variables other
     *                                              than the solved variable.
     * @return list<EquationSolution>
     */
    public static function solve(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): array {
        return (new EquationSolver())->solve(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Count distinct real roots of supported exact rational polynomial,
     * rational-function, polynomial square-root/absolute-value, affine exponential/logarithmic/
     * inverse-hyperbolic, or exact affine trigonometric equations in an open interval.
     * Returns null when extraction or certified counting exceeds
     * supported limits. Polynomial identities throw InfiniteSolutionSetException
     * because their roots are not a finite set.
     *
     * @param array<string, int|float> $parameters
     */
    public static function countPolynomialRootsInInterval(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ?int {
        return (new PolynomialRootCountingSolver())->count(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Certifiably isolate the real root of exp(x) = target in a rational interval.
     * Rational arguments are exact expressions such as '2', '1/3', and '-1'.
     * Returns [] when the interval contains no root and null when limits prevent certification.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateExponentialEquation(
        string $target,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new ExponentialEquationSolver())->isolate(
            self::exactRationalExpression($target),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of exp(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineExponentialEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new ExponentialEquationSolver())->isolateAffine(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of exp(a*x^2 + b*x + c) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateQuadraticExponentialEquation(
        string $target,
        string $quadraticCoefficient,
        string $linearCoefficient,
        string $constant,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new ExponentialEquationSolver())->isolateQuadratic(
            self::exactRationalExpression($target),
            self::exactRationalExpression($quadraticCoefficient),
            self::exactRationalExpression($linearCoefficient),
            self::exactRationalExpression($constant),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of sinh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicSineEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicSine(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of cosh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicCosineEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicCosine(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of tanh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicTangentEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicTangent(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of asinh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineInverseHyperbolicSineEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicSine(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of acosh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineInverseHyperbolicCosineEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicCosine(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of atanh(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineInverseHyperbolicTangentEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicTangent(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /** @return list<\MathPHP\Solver\RationalRootInterval>|null */
    public static function isolateAffineInverseHyperbolicSecantEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicSecant(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /** @return list<\MathPHP\Solver\RationalRootInterval>|null */
    public static function isolateAffineInverseHyperbolicCosecantEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicCosecant(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /** @return list<\MathPHP\Solver\RationalRootInterval>|null */
    public static function isolateAffineInverseHyperbolicCotangentEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineInverseHyperbolicCotangent(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of sech(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicSecantEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicSecant(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of csch(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicCosecantEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicCosecant(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of coth(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineHyperbolicCotangentEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
        int $maxDepth = 256,
    ): ?array {
        return (new HyperbolicEquationSolver())->isolateAffineHyperbolicCotangent(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
            $maxDepth,
        );
    }

    /**
     * Certifiably isolate roots of ln(slope*x + intercept) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineLogarithmEquation(
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new ExponentialEquationSolver())->isolateAffineLogarithm(
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of log_base(slope*x + intercept) = target.
     * The base and all numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateAffineLogarithmWithBaseEquation(
        string $base,
        string $target,
        string $slope,
        string $intercept,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new ExponentialEquationSolver())->isolateAffineLogarithmWithBase(
            self::exactRationalExpression($base),
            self::exactRationalExpression($target),
            self::exactRationalExpression($slope),
            self::exactRationalExpression($intercept),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of ln(quadraticCoefficient*x^2 + constant) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateQuadraticLogarithmEquation(
        string $target,
        string $quadraticCoefficient,
        string $constant,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new ExponentialEquationSolver())->isolateQuadraticLogarithm(
            self::exactRationalExpression($target),
            self::exactRationalExpression($quadraticCoefficient),
            self::exactRationalExpression($constant),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of ln(a*x^2 + b*x + c) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateQuadraticPolynomialLogarithmEquation(
        string $target,
        string $quadraticCoefficient,
        string $linearCoefficient,
        string $constant,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new ExponentialEquationSolver())->isolateQuadraticPolynomialLogarithm(
            self::exactRationalExpression($target),
            self::exactRationalExpression($quadraticCoefficient),
            self::exactRationalExpression($linearCoefficient),
            self::exactRationalExpression($constant),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Certifiably isolate roots of log_base(a*x^2 + b*x + c) = target.
     * All numeric inputs must be exact rational expressions.
     *
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolateQuadraticPolynomialLogarithmWithBaseEquation(
        string $base,
        string $target,
        string $quadraticCoefficient,
        string $linearCoefficient,
        string $constant,
        string $lowerBound,
        string $upperBound,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new ExponentialEquationSolver())->isolateQuadraticPolynomialLogarithmWithBase(
            self::exactRationalExpression($base),
            self::exactRationalExpression($target),
            self::exactRationalExpression($quadraticCoefficient),
            self::exactRationalExpression($linearCoefficient),
            self::exactRationalExpression($constant),
            self::exactRationalExpression($lowerBound),
            self::exactRationalExpression($upperBound),
            self::exactRationalExpression($maximumWidth),
        );
    }

    /**
     * Count distinct real roots over all of R for supported exact rational
     * polynomial, rational-function, or affine monotone-function equations.
     * Polynomial square-root sums include up to three supported additive
     * radicals. Monotone forms include selected exponential, logarithmic, hyperbolic,
     * error, inverse-sine, inverse-cosine, arctangent, inverse-cotangent,
     * inverse-secant, and inverse-cosecant functions.
     * Denominator roots are excluded. Returns null when exact extraction or
     * certified counting exceeds supported limits; polynomial identities throw
     * InfiniteSolutionSetException.
     *
     * @param array<string, int|float> $parameters
     */
    public static function countPolynomialRootsOnRealLine(
        string $equation,
        string $variable,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ?int {
        return (new PolynomialRootCountingSolver())->countAllReal(
            $equation,
            $variable,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Isolate every distinct real root of supported exact rational polynomial,
     * rational-function, selected polynomial square-root sum, or affine
     * monotone-function equations in disjoint rational intervals.
     *
     * @param array<string, int|float> $parameters
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolatePolynomialRootsOnRealLine(
        string $equation,
        string $variable,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
        int $maxDepth = 256,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new PolynomialRootCountingSolver())->isolateAllReal(
            $equation,
            $variable,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
            $maxDepth,
            $maximumWidth,
        );
    }

    /**
     * Count distinct roots using exact rational endpoint expressions such as
     * `'1/3'` and `'2/3'`; both endpoints are excluded. Supported affine
     * exponential, logarithmic, hyperbolic, inverse-trigonometric, and error
     * function levels, plus rational-base exponential and logarithmic levels,
     * are counted through certified isolation. Exact affine trigonometric
     * levels are counted using exact phase bounds.
     *
     * @param array<string, int|float> $parameters
     */
    public static function countPolynomialRootsInRationalInterval(
        string $equation,
        string $variable,
        string $lowerBound,
        string $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ?int {
        return (new PolynomialRootCountingSolver())->count(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Return disjoint exact-rational intervals, each containing exactly one
     * distinct root. Rational roots found at subdivision points are returned
     * as singleton intervals. Supported affine exponential, logarithmic,
     * hyperbolic, inverse-trigonometric, and error-function levels, rational-base
     * exponential and logarithmic levels, and exact affine trigonometric
     * equations with rational levels are isolated on rational intervals.
     * Non-singleton intervals are refined to at most $maximumWidth (default
     * 1/1000000). Returns null when exact isolation is unsupported or exceeds
     * configured limits.
     *
     * @param array<string, int|float> $parameters
     * @return list<\MathPHP\Solver\RationalRootInterval>|null
     */
    public static function isolatePolynomialRootsInRationalInterval(
        string $equation,
        string $variable,
        string $lowerBound,
        string $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
        int $maxDepth = 256,
        string $maximumWidth = '1/1000000',
    ): ?array {
        return (new PolynomialRootCountingSolver())->isolate(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
            $maxDepth,
            $maximumWidth,
        );
    }

    /**
     * Find approximate complex roots of a univariate polynomial equality.
     *
     * The roots are returned with algebraic multiplicity. Durand-Kerner
     * convergence is reported explicitly and is not guaranteed for repeated
     * or ill-conditioned roots.
     *
     * @param array<string, int|float> $parameters
     */
    public static function solvePolynomialComplex(
        string $equation,
        string $variable,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ComplexPolynomialSolution {
        return (new ComplexPolynomialEquationSolver())->solve(
            $equation,
            $variable,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Solve a one-variable rational equation over the complex numbers.
     * Roots that are poles of the original rational expression are excluded.
     * Root residuals are backward residuals for the exact numerator polynomial.
     *
     * @param array<string, int|float> $parameters
     */
    public static function solveRationalComplex(
        string $equation,
        string $variable,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ComplexRationalSolution {
        return (new ComplexRationalEquationSolver())->solve(
            $equation,
            $variable,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Return the detected solution set for an equation in a finite interval.
     * Isolated roots are represented as intervals with equal bounds.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    public static function solveEquationSet(
        string $equation,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): array {
        return (new EquationSetSolver())->solve(
            $equation,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Return detected solution intervals for one-variable real inequalities
     * over a finite interval. Chained comparisons are interpreted as
     * conjunctions, so `a < x <= b` means `a < x` and `x <= b`.
     *
     * @param array<string, int|float> $parameters
     * @return list<InequalityInterval>
     */
    public static function solveInequality(
        string $inequality,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): array {
        return (new InequalitySolver())->solve(
            $inequality,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * Find a local solution of a system from an initial guess. Systems may be
     * underdetermined or overdetermined; underdetermined systems can have many
     * solutions, and the returned one depends on the initial guess. Returns null
     * when the iteration cannot converge to a common solution.
     *
     * @param list<string> $equations
     * @param array<string, int|float> $initialValues
     * @param array<string, int|float> $parameters
     */
    public static function solveSystem(
        array $equations,
        array $initialValues,
        array $parameters = [],
        ?EvaluationOptions $evaluationOptions = null,
        ?SolverOptions $solverOptions = null,
    ): ?SystemSolution {
        return (new SystemSolver())->solve(
            $equations,
            $initialValues,
            $parameters,
            $evaluationOptions ?? new EvaluationOptions(),
            $solverOptions ?? new SolverOptions(),
        );
    }

    /**
     * @param array<string, int|float> $variables
     */
    public static function evaluate(
        string $expression,
        array $variables = [],
        ?EvaluationOptions $options = null,
    ): int|float {
        $resolvedOptions = $options ?? new EvaluationOptions();
        $environment = new Environment($variables, $resolvedOptions->functions);
        $tokens = (new Lexer($expression, $resolvedOptions->limits))->tokenize();
        $ast = (new Parser($tokens, $resolvedOptions->limits, $resolvedOptions->functions))->parse();

        return (new Evaluator(
            $environment,
            $resolvedOptions->limits,
        ))->evaluate($ast);
    }

    /**
     * Numerically integrate an expression over a finite interval using
     * adaptive Gauss-Kronrod quadrature.
     *
     * @param array<string, int|float> $parameters
     */
    public static function integrate(
        string $expression,
        string $variable,
        float $lowerBound,
        float $upperBound,
        array $parameters = [],
        float $absoluteTolerance = 1.0E-10,
        float $relativeTolerance = 1.0E-10,
        ?EvaluationOptions $options = null,
    ): float {
        return (new IntegrationSolver())->integrate(
            $expression,
            $variable,
            $lowerBound,
            $upperBound,
            $parameters,
            $options ?? new EvaluationOptions(),
            $absoluteTolerance,
            $relativeTolerance,
        );
    }

    /**
     * Differentiate supported arithmetic, elementary, and piecewise
     * expressions directly and estimate other derivatives with a
     * fourth-order finite-difference stencil.
     *
     * @param array<string, int|float> $parameters
     */
    public static function differentiate(
        string $expression,
        string $variable,
        float $at,
        array $parameters = [],
        ?EvaluationOptions $options = null,
    ): float {
        if (
            \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) !== 1
            || \in_array($variable, ['pi', 'e', 'tau', 'phi', 'eulerGamma', 'catalan', 'apery'], true)
        ) {
            throw new \InvalidArgumentException(
                'The differentiation variable must be a non-reserved ASCII identifier.',
            );
        }
        if (\array_key_exists($variable, $parameters)) {
            throw new \InvalidArgumentException(
                'The differentiation variable cannot also be supplied as a parameter.',
            );
        }
        if (!\is_finite($at)) {
            throw new \InvalidArgumentException('The differentiation point must be finite.');
        }

        $resolvedOptions = $options ?? new EvaluationOptions();
        $tokens = (new Lexer($expression, $resolvedOptions->limits))->tokenize();
        $ast = (new Parser($tokens, $resolvedOptions->limits, $resolvedOptions->functions))->parse();

        $evaluate = static function (float $value) use (
            $ast,
            $variable,
            $parameters,
            $resolvedOptions,
        ): ?float {
            try {
                $environment = new Environment(
                    [...$parameters, $variable => $value],
                    $resolvedOptions->functions,
                );
                return (float) (new Evaluator(
                    $environment,
                    $resolvedOptions->limits,
                ))->evaluate($ast);
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
        };
        try {
            $exactDerivative = (new ArithmeticDifferentiator())->differentiate(
                $ast,
                $variable,
                $at,
                $parameters,
                $resolvedOptions,
            );
        } catch (NondifferentiablePointException $error) {
            throw new \DomainException(
                'The expression cannot be differentiated numerically at the requested point.',
                0,
                $error,
            );
        }
        if ($exactDerivative !== null) {
            return $exactDerivative;
        }

        $derivative = (new NumericalDifferentiator())->differentiate($evaluate, $at);
        if ($derivative === null) {
            throw new \DomainException(
                'The expression cannot be differentiated numerically at the requested point.',
            );
        }

        return $derivative;
    }

    /**
     * Evaluate while notifying an optional extension observer after each AST
     * node completes. The returned scalar is identical to evaluate().
     *
     * Pass the observer as the second argument when no variables are needed.
     * The array-first form remains supported for backwards compatibility.
     *
     * @param array<string, int|float>|EvaluationObserver $variables
     */
    public static function evaluateWithObserver(
        string $expression,
        array|EvaluationObserver $variables = [],
        ?EvaluationObserver $observer = null,
        ?EvaluationOptions $options = null,
    ): int|float {
        if ($variables instanceof EvaluationObserver) {
            if ($observer !== null) {
                throw new \InvalidArgumentException(
                    'Pass either an observer or variables followed by an observer, not both observers.',
                );
            }
            $observer = $variables;
            $variables = [];
        }
        if ($observer === null) {
            throw new \InvalidArgumentException('An EvaluationObserver is required.');
        }

        $resolvedOptions = $options ?? new EvaluationOptions();
        $environment = new Environment($variables, $resolvedOptions->functions);
        $tokens = (new Lexer($expression, $resolvedOptions->limits))->tokenize();
        $ast = (new Parser($tokens, $resolvedOptions->limits, $resolvedOptions->functions))->parse();

        return (new Evaluator(
            $environment,
            $resolvedOptions->limits,
            $observer,
        ))->evaluate($ast);
    }

    /** @return array{string, string} */
    private static function exactRationalExpression(string $expression): array
    {
        $evaluationOptions = new EvaluationOptions();
        $node = (new Parser(
            (new Lexer($expression, $evaluationOptions->limits))->tokenize(),
            $evaluationOptions->limits,
        ))->parse();
        $fraction = RationalLiteral::fromNode($node);
        if ($fraction === null) {
            throw new \InvalidArgumentException('Certified exponential isolation requires exact rational inputs.');
        }

        return [(string) $fraction[0], (string) $fraction[1]];
    }
}
