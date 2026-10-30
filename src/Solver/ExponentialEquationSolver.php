<?php

declare(strict_types=1);

namespace MathPHP\Solver;

/** Certified isolation for exp(x) = q on a finite rational interval. */
final class ExponentialEquationSolver
{
    /**
     * @param array{string, string} $target Positive rational target.
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth Positive rational width.
     * @return list<RationalRootInterval>|null Empty if no root; null if certification exceeds limits.
     */
    public function isolate(
        array $target,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        $this->validateIsolationRequest($lowerBound, $upperBound, $maximumWidth, $maxDepth);
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->compareLowerToRational('0', '1') <= 0) {
            return [];
        }
        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);

        try {
            $lowerSign = $this->compareExponentialAt($domain->lower, $target);
            $upperSign = $this->compareExponentialAt($domain->upper, $target);
        } catch (\LengthException) {
            return null;
        }
        if ($lowerSign === null || $upperSign === null) {
            return null;
        }
        if ($lowerSign === 0) {
            return [$this->exactRoot($domain->lower)];
        }
        if ($upperSign === 0) {
            return [$this->exactRoot($domain->upper)];
        }
        if ($lowerSign === $upperSign) {
            return [];
        }

        $lower = $domain->lower;
        $upper = $domain->upper;
        for ($iteration = 0; $iteration < $maxDepth; ++$iteration) {
            $bracket = new ExactRationalInterval($lower, $upper);
            $widthInterval = $bracket->subtract(new ExactRationalInterval($lower, $lower));
            if ($widthInterval->compareUpperToRational($width->upper[0], $width->upper[1]) <= 0) {
                return [$this->rootInterval($lower, $upper)];
            }
            $midpoint = (new ExactRationalInterval($lower, $lower))
                ->add(new ExactRationalInterval($upper, $upper))
                ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                ->lower;
            try {
                $midpointSign = $this->compareExponentialAt($midpoint, $target);
            } catch (\LengthException) {
                return null;
            }
            if ($midpointSign === null) {
                return null;
            }
            if ($midpointSign === 0) {
                return [$this->exactRoot($midpoint)];
            }
            if ($midpointSign === $lowerSign) {
                $lower = $midpoint;
                $lowerSign = $midpointSign;
            } else {
                $upper = $midpoint;
            }
        }

        return null;
    }

    /**
     * Certifiably isolate roots of exp(a*x+b) = target on a finite rational interval.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffine(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        $this->validateIsolationRequest($lowerBound, $upperBound, $maximumWidth, $maxDepth);
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        if ($slopeInterval->containsZero()) {
            if (
                $interceptInterval->containsZero()
                && (new ExactRationalInterval($target, $target))->containsRational('1', '1')
            ) {
                throw new InfiniteSolutionSetException('The exponential equation is an identity.');
            }

            return [];
        }

        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $argumentDomain = $domain->multiply($slopeInterval)->add($interceptInterval);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        $absoluteSlope = $slopeInterval->lower[0][0] === '-'
            ? new ExactRationalInterval(
                [$this->absoluteNumerator($slopeInterval->lower[0]), $slopeInterval->lower[1]],
                [$this->absoluteNumerator($slopeInterval->upper[0]), $slopeInterval->upper[1]],
            )
            : $slopeInterval;
        $argumentWidth = $width->multiply($absoluteSlope);
        $argumentRoots = $this->isolate(
            $target,
            $argumentDomain->lower,
            $argumentDomain->upper,
            $argumentWidth->upper,
            $maxDepth,
        );
        if ($argumentRoots === null || $argumentRoots === []) {
            return $argumentRoots;
        }

        $inverseSlope = $slopeInterval->reciprocal();
        $roots = [];
        foreach ($argumentRoots as $root) {
            $argumentInterval = new ExactRationalInterval(
                [$root->lowerNumerator, $root->lowerDenominator],
                [$root->upperNumerator, $root->upperDenominator],
            );
            $variableInterval = $argumentInterval
                ->subtract($interceptInterval)
                ->multiply($inverseSlope);
            $roots[] = $root->isExactRoot
                ? $this->exactRoot($variableInterval->lower)
                : $this->rootInterval($variableInterval->lower, $variableInterval->upper);
        }

        return $roots;
    }

    /**
     * Certifiably isolate roots of exp(a*x^2 + b*x + c) = target.
     *
     * @param array{string, string} $target Positive rational target.
     * @param array{string, string} $quadraticCoefficient
     * @param array{string, string} $linearCoefficient
     * @param array{string, string} $constant
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateQuadratic(
        array $target,
        array $quadraticCoefficient,
        array $linearCoefficient,
        array $constant,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        $this->validateIsolationRequest($lowerBound, $upperBound, $maximumWidth, $maxDepth);
        $quadratic = new ExactRationalInterval($quadraticCoefficient, $quadraticCoefficient);
        $linear = new ExactRationalInterval($linearCoefficient, $linearCoefficient);
        $constantInterval = new ExactRationalInterval($constant, $constant);
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->compareLowerToRational('0', '1') <= 0) {
            return [];
        }
        if ($quadratic->containsZero()) {
            return $this->isolateAffine(
                $target,
                $linearCoefficient,
                $constant,
                $lowerBound,
                $upperBound,
                $maximumWidth,
                $maxDepth,
            );
        }

        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        try {
            $vertex = $linear->negateInterval()->divide(
                $quadratic->multiply(new ExactRationalInterval(['2', '1'], ['2', '1'])),
            );
            /** @var list<array{string, string}> $points */
            $points = [$domain->lower];
            if (
                $vertex->compareLowerToRational($domain->lower[0], $domain->lower[1]) > 0
                && $vertex->compareUpperToRational($domain->upper[0], $domain->upper[1]) < 0
            ) {
                if ($vertex->lower !== $vertex->upper) {
                    return null;
                }
                $points[] = $vertex->lower;
            }
            if ($domain->upper !== $domain->lower) {
                $points[] = $domain->upper;
            }
            /** @var list<int|null> $signs */
            $signs = [];
            foreach ($points as $point) {
                $signs[] = $this->compareExponentialAt(
                    $this->quadraticValue($point, $quadratic, $linear, $constantInterval),
                    $target,
                );
            }
        } catch (\LengthException) {
            return null;
        }
        $roots = [];
        foreach ($points as $index => $point) {
            $sign = $signs[$index] ?? null;
            $nextSign = $signs[$index + 1] ?? null;
            $nextPoint = $points[$index + 1] ?? null;
            if ($sign === null) {
                return null;
            }
            if ($sign === 0) {
                $roots[] = $this->exactRoot($point);
            }
            if (
                $nextPoint === null
                || $nextSign === null
                || $sign === 0
                || $nextSign === 0
                || $sign === $nextSign
            ) {
                continue;
            }
            try {
                $root = $this->isolateQuadraticBranch(
                    $point,
                    $nextPoint,
                    $sign,
                    $target,
                    $quadratic,
                    $linear,
                    $constantInterval,
                    $width,
                    $maxDepth,
                );
            } catch (\LengthException) {
                return null;
            }
            if ($root === null) {
                return null;
            }
            $roots[] = $root;
        }

        return $roots;
    }

    /**
     * @param array{string, string} $point
     * @return array{string, string}
     */
    private function quadraticValue(
        array $point,
        ExactRationalInterval $quadratic,
        ExactRationalInterval $linear,
        ExactRationalInterval $constant,
    ): array {
        $x = new ExactRationalInterval($point, $point);

        return $quadratic->multiply($x)->multiply($x)
            ->add($linear->multiply($x))
            ->add($constant)->lower;
    }

    /**
     * @param array{string, string} $lower
     * @param array{string, string} $upper
     * @param array{string, string} $target
     */
    private function isolateQuadraticBranch(
        array $lower,
        array $upper,
        int $lowerSign,
        array $target,
        ExactRationalInterval $quadratic,
        ExactRationalInterval $linear,
        ExactRationalInterval $constant,
        ExactRationalInterval $maximumWidth,
        int $maxDepth,
    ): ?RationalRootInterval {
        try {
            for ($iteration = 0; $iteration < $maxDepth; ++$iteration) {
                $bracket = new ExactRationalInterval($lower, $upper);
                $bracketWidth = $bracket->subtract(new ExactRationalInterval($lower, $lower));
                if (
                    $bracketWidth->compareUpperToRational(
                        $maximumWidth->upper[0],
                        $maximumWidth->upper[1],
                    ) <= 0
                ) {
                    return $this->rootInterval($lower, $upper);
                }
                $midpoint = (new ExactRationalInterval($lower, $lower))
                    ->add(new ExactRationalInterval($upper, $upper))
                    ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                    ->lower;
                if ($midpoint === $lower || $midpoint === $upper) {
                    return null;
                }
                $midpointSign = $this->compareExponentialAt(
                    $this->quadraticValue($midpoint, $quadratic, $linear, $constant),
                    $target,
                );
                if ($midpointSign === null) {
                    return null;
                }
                if ($midpointSign === 0) {
                    return $this->exactRoot($midpoint);
                }
                if ($midpointSign === $lowerSign) {
                    $lower = $midpoint;
                } else {
                    $upper = $midpoint;
                }
            }
        } catch (\LengthException) {
            return null;
        }

        return null;
    }

    /**
     * Certifiably isolate roots of ln(a*x+b) = target on a finite rational interval.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineLogarithm(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
        if ($slopeInterval->containsZero()) {
            if (
                $interceptInterval->containsRational('1', '1')
                && $targetInterval->containsZero()
            ) {
                throw new InfiniteSolutionSetException('The logarithmic equation is an identity.');
            }

            return [];
        }

        try {
            $exponential = $targetInterval->exp();
        } catch (\LengthException) {
            return null;
        }
        $root = $exponential
            ->subtract($interceptInterval)
            ->divide($slopeInterval);
        if (
            $root->compareUpperToRational($domain->lower[0], $domain->lower[1]) < 0
            || $root->compareLowerToRational($domain->upper[0], $domain->upper[1]) > 0
        ) {
            return [];
        }
        if (
            $root->compareLowerToRational($domain->lower[0], $domain->lower[1]) < 0
            || $root->compareUpperToRational($domain->upper[0], $domain->upper[1]) > 0
        ) {
            return null;
        }

        $rootWidth = $root->subtract(new ExactRationalInterval($root->lower, $root->lower));
        if ($rootWidth->compareUpperToRational($width->upper[0], $width->upper[1]) > 0) {
            return null;
        }
        if ($root->lower === $root->upper) {
            return [$this->exactRoot($root->lower)];
        }

        return [$this->rootInterval($root->lower, $root->upper)];
    }

    /**
     * Certifiably isolate roots of log_base(slope*x + intercept) = target.
     *
     * @param array{string, string} $base Positive rational base other than one.
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineLogarithmWithBase(
        array $base,
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $baseInterval = new ExactRationalInterval($base, $base);
        if (
            $baseInterval->compareLowerToRational('0', '1') <= 0
            || $baseInterval->containsRational('1', '1')
        ) {
            throw new \InvalidArgumentException('A real logarithm base must be positive and different from one.');
        }

        $targetInterval = new ExactRationalInterval($target, $target);
        $level = $this->exactRationalPowerLevel($baseInterval->lower, $targetInterval->lower);
        if ($level === null) {
            try {
                $level = $baseInterval->ln()->multiply($targetInterval)->exp();
            } catch (\DomainException | \LengthException) {
                return null;
            }
        }

        return $this->isolateQuadraticPolynomialAtLevel(
            $level,
            ['0', '1'],
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Certifiably isolate roots of ln(a*x^2+c) = target on a finite rational interval.
     *
     * @param array{string, string} $target
     * @param array{string, string} $quadraticCoefficient
     * @param array{string, string} $constant
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateQuadraticLogarithm(
        array $target,
        array $quadraticCoefficient,
        array $constant,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        return $this->isolateQuadraticPolynomialLogarithm(
            $target,
            $quadraticCoefficient,
            ['0', '1'],
            $constant,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Certifiably isolate roots of ln(a*x^2 + b*x + c) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $quadraticCoefficient
     * @param array{string, string} $linearCoefficient
     * @param array{string, string} $constant
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateQuadraticPolynomialLogarithm(
        array $target,
        array $quadraticCoefficient,
        array $linearCoefficient,
        array $constant,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        try {
            $exponential = $targetInterval->exp();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateQuadraticPolynomialAtLevel(
            $exponential,
            $quadraticCoefficient,
            $linearCoefficient,
            $constant,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Certifiably isolate roots of log_base(a*x^2 + b*x + c) = target.
     *
     * @param array{string, string} $base Positive rational base other than one.
     * @param array{string, string} $target
     * @param array{string, string} $quadraticCoefficient
     * @param array{string, string} $linearCoefficient
     * @param array{string, string} $constant
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateQuadraticPolynomialLogarithmWithBase(
        array $base,
        array $target,
        array $quadraticCoefficient,
        array $linearCoefficient,
        array $constant,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $baseInterval = new ExactRationalInterval($base, $base);
        if (
            $baseInterval->compareLowerToRational('0', '1') <= 0
            || $baseInterval->containsRational('1', '1')
        ) {
            throw new \InvalidArgumentException('A real logarithm base must be positive and different from one.');
        }

        $targetInterval = new ExactRationalInterval($target, $target);
        $level = $this->exactRationalPowerLevel($baseInterval->lower, $targetInterval->lower);
        if ($level === null) {
            try {
                $level = $baseInterval->ln()->multiply($targetInterval)->exp();
            } catch (\DomainException | \LengthException) {
                return null;
            }
        }

        return $this->isolateQuadraticPolynomialAtLevel(
            $level,
            $quadraticCoefficient,
            $linearCoefficient,
            $constant,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Return an exact rational base power for bounded integer exponents.
     *
     * @param array{string, string} $base
     * @param array{string, string} $exponent
     */
    private function exactRationalPowerLevel(array $base, array $exponent): ?ExactRationalInterval
    {
        if ($exponent[1] !== '1') {
            if (strlen($exponent[1]) > 4) {
                return null;
            }
            $degree = (int) $exponent[1];
            if ($degree < 2 || $degree > 4096) {
                return null;
            }
            $arithmetic = new IntegerPolynomialExtractor('', [], 1);
            $numeratorRoot = $arithmetic->integerNthRoot($base[0], $degree);
            $denominatorRoot = $arithmetic->integerNthRoot($base[1], $degree);
            if ($numeratorRoot === null || $denominatorRoot === null) {
                return null;
            }
            $base = [$numeratorRoot, $denominatorRoot];
        }
        if (strlen(ltrim($exponent[0], '-')) > 4) {
            return null;
        }
        $power = (int) $exponent[0];
        if (abs($power) > 1024) {
            return null;
        }

        try {
            $factor = new ExactRationalInterval($base, $base);
            $level = new ExactRationalInterval(['1', '1'], ['1', '1']);
            $remainingPower = abs($power);
            while ($remainingPower > 0) {
                if ($remainingPower % 2 === 1) {
                    $level = $level->multiply($factor);
                }
                $remainingPower = intdiv($remainingPower, 2);
                if ($remainingPower > 0) {
                    $factor = $factor->multiply($factor);
                }
            }

            return $power < 0 ? $level->reciprocal() : $level;
        } catch (\LengthException) {
            return null;
        }
    }

    /**
     * Certifiably isolate roots of a*x^2+b*x+c = an exact interval level.
     *
     * @param array{string, string} $quadraticCoefficient
     * @param array{string, string} $linearCoefficient
     * @param array{string, string} $constant
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateQuadraticPolynomialAtLevel(
        ExactRationalInterval $level,
        array $quadraticCoefficient,
        array $linearCoefficient,
        array $constant,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $coefficient = new ExactRationalInterval($quadraticCoefficient, $quadraticCoefficient);
        $linear = new ExactRationalInterval($linearCoefficient, $linearCoefficient);
        $constantInterval = new ExactRationalInterval($constant, $constant);
        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
        if ($coefficient->containsZero()) {
            if ($linear->containsZero()) {
                if (
                    $constantInterval->lower === $level->lower
                    && $constantInterval->upper === $level->upper
                ) {
                    throw new InfiniteSolutionSetException('The constant polynomial equation is an identity.');
                }
                if (
                    $constantInterval->compareUpperToRational($level->lower[0], $level->lower[1]) < 0
                    || $constantInterval->compareLowerToRational($level->upper[0], $level->upper[1]) > 0
                ) {
                    return [];
                }

                return null;
            }
            $domain = new ExactRationalInterval($lowerBound, $upperBound);
            $root = $level
                ->subtract($constantInterval)
                ->divide($linear);
            if (
                $root->compareUpperToRational($domain->lower[0], $domain->lower[1]) < 0
                || $root->compareLowerToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                return [];
            }
            if (
                $root->compareLowerToRational($domain->lower[0], $domain->lower[1]) < 0
                || $root->compareUpperToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                return null;
            }
            $rootWidth = $root->subtract(new ExactRationalInterval($root->lower, $root->lower));
            if ($rootWidth->compareUpperToRational($width->upper[0], $width->upper[1]) > 0) {
                return null;
            }

            return $root->lower === $root->upper
                ? [$this->exactRoot($root->lower)]
                : [$this->rootInterval($root->lower, $root->upper)];
        }
        $discriminant = $linear->multiply($linear)->add(
            $coefficient
                ->multiply(new ExactRationalInterval(['4', '1'], ['4', '1']))
                ->multiply($level->subtract($constantInterval)),
        );
        if ($discriminant->compareUpperToRational('0', '1') < 0) {
            return [];
        }
        if ($discriminant->compareLowerToRational('0', '1') < 0) {
            return null;
        }
        try {
            $squareRoot = $discriminant->sqrt();
        } catch (\LengthException) {
            return null;
        }
        $denominator = $coefficient->multiply(new ExactRationalInterval(['2', '1'], ['2', '1']));
        $negativeLinear = $linear->negateInterval();
        $candidates = $squareRoot->lower === $squareRoot->upper && $squareRoot->lower[0] === '0'
            ? [$negativeLinear->divide($denominator)]
            : [
                $negativeLinear->subtract($squareRoot)->divide($denominator),
                $negativeLinear->add($squareRoot)->divide($denominator),
            ];
        usort($candidates, static fn (ExactRationalInterval $left, ExactRationalInterval $right): int =>
            $left->compareLowerEndpoint($right));
        if (
            count($candidates) === 2
            && $candidates[0]->compareUpperToRational(
                $candidates[1]->lower[0],
                $candidates[1]->lower[1],
            ) >= 0
        ) {
            return null;
        }
        $roots = [];
        foreach ($candidates as $candidate) {
            if (
                $candidate->compareUpperToRational($domain->lower[0], $domain->lower[1]) < 0
                || $candidate->compareLowerToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                continue;
            }
            if (
                $candidate->compareLowerToRational($domain->lower[0], $domain->lower[1]) < 0
                || $candidate->compareUpperToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                return null;
            }
            $candidateWidth = $candidate->subtract(
                new ExactRationalInterval($candidate->lower, $candidate->lower),
            );
            if ($candidateWidth->compareUpperToRational($width->upper[0], $width->upper[1]) > 0) {
                return null;
            }
            if ($candidate->lower === $candidate->upper) {
                $roots[] = $this->exactRoot($candidate->lower);
            } else {
                $roots[] = $this->rootInterval($candidate->lower, $candidate->upper);
            }
        }

        return $roots;
    }

    /**
     * @param array{string, string} $point
     * @param array{string, string} $target
     */
    private function compareExponentialAt(array $point, array $target): ?int
    {
        $exponential = (new ExactRationalInterval($point, $point))->exp();
        if (
            $exponential->compareLowerToRational($target[0], $target[1]) === 0
            && $exponential->compareUpperToRational($target[0], $target[1]) === 0
        ) {
            return 0;
        }
        if ($exponential->compareUpperToRational($target[0], $target[1]) < 0) {
            return -1;
        }
        if ($exponential->compareLowerToRational($target[0], $target[1]) > 0) {
            return 1;
        }

        return null;
    }

    /** @param array{string, string} $lowerBound
     *  @param array{string, string} $upperBound
     *  @param array{string, string} $maximumWidth
     */
    private function validateIsolationRequest(
        array $lowerBound,
        array $upperBound,
        array $maximumWidth,
        int $maxDepth,
    ): void {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
    }

    /** @param array{string, string} $point */
    private function exactRoot(array $point): RationalRootInterval
    {
        return new RationalRootInterval($point[0], $point[1], $point[0], $point[1], true);
    }

    /**
     * @param array{string, string} $lower
     * @param array{string, string} $upper
     */
    private function rootInterval(array $lower, array $upper): RationalRootInterval
    {
        return new RationalRootInterval($lower[0], $lower[1], $upper[0], $upper[1]);
    }

    private function absoluteNumerator(string $numerator): string
    {
        return str_starts_with($numerator, '-') ? substr($numerator, 1) : $numerator;
    }
}
