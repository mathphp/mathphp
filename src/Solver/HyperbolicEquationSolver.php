<?php

declare(strict_types=1);

namespace MathPHP\Solver;

/** Certified isolation for affine hyperbolic sine equations. */
final class HyperbolicEquationSolver
{
    /**
     * Isolate all roots of sinh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicSine(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }

        $targetInterval = new ExactRationalInterval($target, $target);
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        if ($slopeInterval->containsZero()) {
            if (
                $interceptInterval->containsZero()
                && $targetInterval->containsZero()
            ) {
                throw new InfiniteSolutionSetException('The hyperbolic sine equation is an identity.');
            }
            if ($targetInterval->containsZero()) {
                return [];
            }
            try {
                $comparison = $this->compareSinhAt($interceptInterval->lower, $target);
            } catch (\LengthException) {
                return null;
            }
            if ($comparison === null) {
                return null;
            }
            if ($comparison === 0) {
                throw new InfiniteSolutionSetException('The hyperbolic sine equation is an identity.');
            }

            return [];
        }

        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
        if ($targetInterval->containsZero()) {
            $root = $interceptInterval->negateInterval()->divide($slopeInterval);
            if (
                $root->compareLowerToRational($domain->lower[0], $domain->lower[1]) < 0
                || $root->compareUpperToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                return [];
            }

            return [$this->exactRoot($root->lower)];
        }
        $phaseDomain = $domain->multiply($slopeInterval)->add($interceptInterval);
        $absoluteSlope = $slopeInterval->lower[0][0] === '-'
            ? new ExactRationalInterval(
                [$this->absoluteNumerator($slopeInterval->lower[0]), $slopeInterval->lower[1]],
                [$this->absoluteNumerator($slopeInterval->upper[0]), $slopeInterval->upper[1]],
            )
            : $slopeInterval;
        $phaseWidth = $width->multiply($absoluteSlope);
        $phaseRoots = $this->isolateIncreasingFunction(
            $phaseDomain->lower,
            $phaseDomain->upper,
            $phaseWidth->upper,
            fn (array $point): ?int => $this->compareSinhAt($point, $target),
            $maxDepth,
        );
        if ($phaseRoots === null || $phaseRoots === []) {
            return $phaseRoots;
        }

        $inverseSlope = $slopeInterval->reciprocal();
        $roots = [];
        foreach ($phaseRoots as $root) {
            $phaseInterval = new ExactRationalInterval(
                [$root->lowerNumerator, $root->lowerDenominator],
                [$root->upperNumerator, $root->upperDenominator],
            );
            $variableInterval = $phaseInterval
                ->subtract($interceptInterval)
                ->multiply($inverseSlope);
            $roots[] = $root->isExactRoot
                ? $this->exactRoot($variableInterval->lower)
                : $this->rootInterval($variableInterval->lower, $variableInterval->upper);
        }

        return $roots;
    }

    /**
     * Isolate all roots of cosh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicCosine(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        $targetInterval = new ExactRationalInterval($target, $target);
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        if ($targetInterval->compareLowerToRational('1', '1') < 0) {
            return [];
        }
        if ($slopeInterval->containsZero()) {
            if (
                $interceptInterval->containsZero()
                && $targetInterval->containsRational('1', '1')
            ) {
                throw new InfiniteSolutionSetException('The hyperbolic cosine equation is an identity.');
            }
            try {
                $comparison = $this->compareCoshAt($interceptInterval->lower, $target);
            } catch (\LengthException) {
                return null;
            }
            if ($comparison === null) {
                return null;
            }
            if ($comparison === 0) {
                throw new InfiniteSolutionSetException('The hyperbolic cosine equation is an identity.');
            }

            return [];
        }

        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
        if ($targetInterval->containsRational('1', '1')) {
            $root = $interceptInterval->negateInterval()->divide($slopeInterval);
            if (
                $root->compareLowerToRational($domain->lower[0], $domain->lower[1]) < 0
                || $root->compareUpperToRational($domain->upper[0], $domain->upper[1]) > 0
            ) {
                return [];
            }

            return [$this->exactRoot($root->lower)];
        }

        $phaseDomain = $domain->multiply($slopeInterval)->add($interceptInterval);
        $absoluteSlope = $slopeInterval->lower[0][0] === '-'
            ? new ExactRationalInterval(
                [$this->absoluteNumerator($slopeInterval->lower[0]), $slopeInterval->lower[1]],
                [$this->absoluteNumerator($slopeInterval->upper[0]), $slopeInterval->upper[1]],
            )
            : $slopeInterval;
        $phaseWidth = $width->multiply($absoluteSlope)->upper;
        $phaseRoots = [];
        if ($phaseDomain->compareUpperToRational('0', '1') > 0) {
            $positiveLower = $phaseDomain->compareLowerToRational('0', '1') < 0
                ? ['0', '1']
                : $phaseDomain->lower;
            $positiveRoots = $this->isolatePositiveCosh(
                $target,
                $positiveLower,
                $phaseDomain->upper,
                $phaseWidth,
                $maxDepth,
            );
            if ($positiveRoots === null) {
                return null;
            }
            array_push($phaseRoots, ...$positiveRoots);
        }
        if ($phaseDomain->compareLowerToRational('0', '1') < 0) {
            $negativePhaseDomain = $phaseDomain->negateInterval();
            $negativeLower = $negativePhaseDomain->compareLowerToRational('0', '1') < 0
                ? ['0', '1']
                : $negativePhaseDomain->lower;
            $negativeRoots = $this->isolatePositiveCosh(
                $target,
                $negativeLower,
                $negativePhaseDomain->upper,
                $phaseWidth,
                $maxDepth,
            );
            if ($negativeRoots === null) {
                return null;
            }
            foreach ($negativeRoots as $root) {
                $phaseRoots[] = $this->negateRoot($root);
            }
        }

        $inverseSlope = $slopeInterval->reciprocal();
        $roots = [];
        foreach ($phaseRoots as $root) {
            $phaseInterval = new ExactRationalInterval(
                [$root->lowerNumerator, $root->lowerDenominator],
                [$root->upperNumerator, $root->upperDenominator],
            );
            $variableInterval = $phaseInterval
                ->subtract($interceptInterval)
                ->multiply($inverseSlope);
            $roots[] = $root->isExactRoot
                ? $this->exactRoot($variableInterval->lower)
                : $this->rootInterval($variableInterval->lower, $variableInterval->upper);
        }
        usort(
            $roots,
            static fn (RationalRootInterval $left, RationalRootInterval $right): int =>
                (new ExactRationalInterval(
                    [$left->lowerNumerator, $left->lowerDenominator],
                    [$left->lowerNumerator, $left->lowerDenominator],
                ))->compareLowerEndpoint(new ExactRationalInterval(
                    [$right->lowerNumerator, $right->lowerDenominator],
                    [$right->lowerNumerator, $right->lowerDenominator],
                )),
        );

        return $roots;
    }

    /**
     * Isolate all roots of tanh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicTangent(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        $targetInterval = new ExactRationalInterval($target, $target);
        if (
            $targetInterval->compareLowerToRational('-1', '1') <= 0
            || $targetInterval->compareUpperToRational('1', '1') >= 0
        ) {
            return [];
        }
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        if ($slopeInterval->containsZero()) {
            if (
                $interceptInterval->containsZero()
                && $targetInterval->containsZero()
            ) {
                throw new InfiniteSolutionSetException('The hyperbolic tangent equation is an identity.');
            }
            try {
                $comparison = $this->compareTanhAt($interceptInterval->lower, $target);
            } catch (\LengthException) {
                return null;
            }
            if ($comparison === null) {
                return null;
            }
            if ($comparison === 0) {
                throw new InfiniteSolutionSetException('The hyperbolic tangent equation is an identity.');
            }

            return [];
        }

        $domain = new ExactRationalInterval($lowerBound, $upperBound);
        $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
        if ($width->compareUpperToRational('0', '1') <= 0) {
            throw new \InvalidArgumentException('maximumWidth must be positive.');
        }
        $phaseDomain = $domain->multiply($slopeInterval)->add($interceptInterval);
        $absoluteSlope = $slopeInterval->lower[0][0] === '-'
            ? new ExactRationalInterval(
                [$this->absoluteNumerator($slopeInterval->lower[0]), $slopeInterval->lower[1]],
                [$this->absoluteNumerator($slopeInterval->upper[0]), $slopeInterval->upper[1]],
            )
            : $slopeInterval;
        $phaseWidth = $width->multiply($absoluteSlope);
        $phaseRoots = $this->isolateIncreasingFunction(
            $phaseDomain->lower,
            $phaseDomain->upper,
            $phaseWidth->upper,
            fn (array $point): ?int => $this->compareTanhAt($point, $target),
            $maxDepth,
        );
        if ($phaseRoots === null || $phaseRoots === []) {
            return $phaseRoots;
        }

        $inverseSlope = $slopeInterval->reciprocal();
        $roots = [];
        foreach ($phaseRoots as $root) {
            $phaseInterval = new ExactRationalInterval(
                [$root->lowerNumerator, $root->lowerDenominator],
                [$root->upperNumerator, $root->upperDenominator],
            );
            $variableInterval = $phaseInterval
                ->subtract($interceptInterval)
                ->multiply($inverseSlope);
            $roots[] = $root->isExactRoot
                ? $this->exactRoot($variableInterval->lower)
                : $this->rootInterval($variableInterval->lower, $variableInterval->upper);
        }

        return $roots;
    }

    /**
     * Isolate roots of asinh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicSine(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        try {
            $level = (new ExactRationalInterval($target, $target))->sinh();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Isolate roots of acosh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicCosine(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->compareLowerToRational('0', '1') < 0) {
            return [];
        }
        try {
            $level = $targetInterval->cosh();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Isolate roots of atanh(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicTangent(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        try {
            $level = $targetInterval->tanh();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicSecant(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->compareLowerToRational('0', '1') < 0) {
            return [];
        }
        try {
            $level = $targetInterval->cosh()->reciprocal();
        } catch (\DomainException | \LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicCosecant(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->containsZero()) {
            return [];
        }
        try {
            $level = $targetInterval->sinh()->reciprocal();
        } catch (\DomainException | \LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineInverseHyperbolicCotangent(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
    ): ?array {
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->containsZero()) {
            return [];
        }
        try {
            $level = $targetInterval->cosh()->divide($targetInterval->sinh());
        } catch (\DomainException | \LengthException) {
            return null;
        }

        return $this->isolateAffineLevel(
            $level,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
        );
    }

    /**
     * Isolate all roots of sech(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicSecant(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        $targetInterval = new ExactRationalInterval($target, $target);
        if (
            $targetInterval->compareLowerToRational('0', '1') <= 0
            || $targetInterval->compareUpperToRational('1', '1') > 0
        ) {
            return [];
        }

        try {
            $reciprocalTarget = $targetInterval->reciprocal();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineHyperbolicCosine(
            $reciprocalTarget->lower,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
            $maxDepth,
        );
    }

    /**
     * Isolate all roots of csch(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicCosecant(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        $targetInterval = new ExactRationalInterval($target, $target);
        if ($targetInterval->containsZero()) {
            return [];
        }

        try {
            $reciprocalTarget = $targetInterval->reciprocal();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineHyperbolicSine(
            $reciprocalTarget->lower,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
            $maxDepth,
        );
    }

    /**
     * Isolate all roots of coth(slope*x + intercept) = target.
     *
     * @param array{string, string} $target
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    public function isolateAffineHyperbolicCotangent(
        array $target,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth = ['1', '1000000'],
        int $maxDepth = 256,
    ): ?array {
        if ($maxDepth < 1 || $maxDepth > 4096) {
            throw new \InvalidArgumentException('maxDepth must be between 1 and 4096 inclusive.');
        }
        $targetInterval = new ExactRationalInterval($target, $target);
        if (
            $targetInterval->compareLowerToRational('-1', '1') >= 0
            && $targetInterval->compareUpperToRational('1', '1') <= 0
        ) {
            return [];
        }

        try {
            $reciprocalTarget = $targetInterval->reciprocal();
        } catch (\LengthException) {
            return null;
        }

        return $this->isolateAffineHyperbolicTangent(
            $reciprocalTarget->lower,
            $slope,
            $intercept,
            $lowerBound,
            $upperBound,
            $maximumWidth,
            $maxDepth,
        );
    }

    /**
     * @param array{string, string} $target
     * @param array{string, string} $lower
     * @param array{string, string} $upper
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    private function isolatePositiveCosh(
        array $target,
        array $lower,
        array $upper,
        array $maximumWidth,
        int $maxDepth,
    ): ?array {
        try {
            $lowerSign = $this->compareCoshAt($lower, $target);
            $upperSign = $this->compareCoshAt($upper, $target);
        } catch (\LengthException) {
            return null;
        }
        if ($lowerSign === null || $upperSign === null) {
            return null;
        }
        if ($lowerSign === 0) {
            return [$this->exactRoot($lower)];
        }
        if ($upperSign === 0) {
            return [$this->exactRoot($upper)];
        }
        if ($lowerSign > 0 || $upperSign < 0) {
            return [];
        }

        try {
            for ($iteration = 0; $iteration < $maxDepth; ++$iteration) {
                $bracket = new ExactRationalInterval($lower, $upper);
                $bracketWidth = $bracket->subtract(new ExactRationalInterval($lower, $lower));
                if ($bracketWidth->compareUpperToRational($maximumWidth[0], $maximumWidth[1]) <= 0) {
                    return [$this->rootInterval($lower, $upper)];
                }
                $midpoint = (new ExactRationalInterval($lower, $lower))
                    ->add(new ExactRationalInterval($upper, $upper))
                    ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                    ->lower;
                if ($midpoint === $lower || $midpoint === $upper) {
                    return null;
                }
                $midpointSign = $this->compareCoshAt($midpoint, $target);
                if ($midpointSign === null) {
                    return null;
                }
                if ($midpointSign === 0) {
                    return [$this->exactRoot($midpoint)];
                }
                if ($midpointSign < 0) {
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
     * @param array{string, string} $slope
     * @param array{string, string} $intercept
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @return list<RationalRootInterval>|null
     */
    private function isolateAffineLevel(
        ExactRationalInterval $level,
        array $slope,
        array $intercept,
        array $lowerBound,
        array $upperBound,
        array $maximumWidth,
    ): ?array {
        $slopeInterval = new ExactRationalInterval($slope, $slope);
        $interceptInterval = new ExactRationalInterval($intercept, $intercept);
        if ($slopeInterval->containsZero()) {
            if (
                $level->compareLowerToRational($interceptInterval->lower[0], $interceptInterval->lower[1]) === 0
                && $level->compareUpperToRational($interceptInterval->lower[0], $interceptInterval->lower[1]) === 0
            ) {
                throw new InfiniteSolutionSetException('The inverse hyperbolic equation is an identity.');
            }
            if (
                $level->compareUpperToRational($interceptInterval->lower[0], $interceptInterval->lower[1]) < 0
                || $level->compareLowerToRational($interceptInterval->lower[0], $interceptInterval->lower[1]) > 0
            ) {
                return [];
            }

            return null;
        }

        try {
            $domain = new ExactRationalInterval($lowerBound, $upperBound);
            $width = new ExactRationalInterval(['0', '1'], $maximumWidth);
            if ($width->compareUpperToRational('0', '1') <= 0) {
                throw new \InvalidArgumentException('maximumWidth must be positive.');
            }
            $root = $level->subtract($interceptInterval)->divide($slopeInterval);
        } catch (\LengthException | \DomainException) {
            return null;
        }
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

    /**
     * @param array{string, string} $point
     * @param array{string, string} $target
     */
    private function compareCoshAt(array $point, array $target): ?int
    {
        $value = (new ExactRationalInterval($point, $point))->cosh();
        if (
            $value->compareLowerToRational($target[0], $target[1]) === 0
            && $value->compareUpperToRational($target[0], $target[1]) === 0
        ) {
            return 0;
        }
        if ($value->compareUpperToRational($target[0], $target[1]) < 0) {
            return -1;
        }
        if ($value->compareLowerToRational($target[0], $target[1]) > 0) {
            return 1;
        }

        return null;
    }

    /**
     * @param array{string, string} $point
     * @param array{string, string} $target
     */
    private function compareTanhAt(array $point, array $target): ?int
    {
        $value = (new ExactRationalInterval($point, $point))->tanh();
        if (
            $value->compareLowerToRational($target[0], $target[1]) === 0
            && $value->compareUpperToRational($target[0], $target[1]) === 0
        ) {
            return 0;
        }
        if ($value->compareUpperToRational($target[0], $target[1]) < 0) {
            return -1;
        }
        if ($value->compareLowerToRational($target[0], $target[1]) > 0) {
            return 1;
        }

        return null;
    }

    private function negateRoot(RationalRootInterval $root): RationalRootInterval
    {
        $interval = (new ExactRationalInterval(
            [$root->lowerNumerator, $root->lowerDenominator],
            [$root->upperNumerator, $root->upperDenominator],
        ))->negateInterval();

        return $root->isExactRoot
            ? $this->exactRoot($interval->lower)
            : $this->rootInterval($interval->lower, $interval->upper);
    }

    /**
     * @param array{string, string} $lowerBound
     * @param array{string, string} $upperBound
     * @param array{string, string} $maximumWidth
     * @param callable(array{string, string}): (int|null) $compare
     * @return list<RationalRootInterval>|null
     */
    private function isolateIncreasingFunction(
        array $lowerBound,
        array $upperBound,
        array $maximumWidth,
        callable $compare,
        int $maxDepth,
    ): ?array {
        try {
            $lowerSign = $compare($lowerBound);
            $upperSign = $compare($upperBound);
        } catch (\LengthException) {
            return null;
        }
        if ($lowerSign === null || $upperSign === null) {
            return null;
        }
        if ($lowerSign === 0) {
            return [$this->exactRoot($lowerBound)];
        }
        if ($upperSign === 0) {
            return [$this->exactRoot($upperBound)];
        }
        if ($lowerSign === $upperSign) {
            return [];
        }

        $lower = $lowerBound;
        $upper = $upperBound;
        try {
            for ($iteration = 0; $iteration < $maxDepth; ++$iteration) {
                $bracket = new ExactRationalInterval($lower, $upper);
                $bracketWidth = $bracket->subtract(new ExactRationalInterval($lower, $lower));
                if ($bracketWidth->compareUpperToRational($maximumWidth[0], $maximumWidth[1]) <= 0) {
                    return [$this->rootInterval($lower, $upper)];
                }
                $midpoint = (new ExactRationalInterval($lower, $lower))
                    ->add(new ExactRationalInterval($upper, $upper))
                    ->divide(new ExactRationalInterval(['2', '1'], ['2', '1']))
                    ->lower;
                if ($midpoint === $lower || $midpoint === $upper) {
                    return null;
                }
                $midpointSign = $compare($midpoint);
                if ($midpointSign === null) {
                    return null;
                }
                if ($midpointSign === 0) {
                    return [$this->exactRoot($midpoint)];
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
     * @param array{string, string} $point
     * @param array{string, string} $target
     */
    private function compareSinhAt(array $point, array $target): ?int
    {
        $value = (new ExactRationalInterval($point, $point))->sinh();
        if (
            $value->compareLowerToRational($target[0], $target[1]) === 0
            && $value->compareUpperToRational($target[0], $target[1]) === 0
        ) {
            return 0;
        }
        if ($value->compareUpperToRational($target[0], $target[1]) < 0) {
            return -1;
        }
        if ($value->compareLowerToRational($target[0], $target[1]) > 0) {
            return 1;
        }

        return null;
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
