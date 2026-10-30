<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class SolverOptions
{
    public const MAX_SCAN_STEPS = 65_536;

    public function __construct(
        public int $scanSteps = 256,
        public int $maxIterations = 512,
        public float $absoluteTolerance = 1.0E-10,
        public float $relativeTolerance = 1.0E-10,
        public float $residualTolerance = 1.0E-10,
        public int $maxPolynomialDegree = 256,
    ) {
        if ($scanSteps < 1 || $scanSteps > self::MAX_SCAN_STEPS) {
            throw new \InvalidArgumentException(
                \sprintf('scanSteps must be between 1 and %d inclusive.', self::MAX_SCAN_STEPS),
            );
        }

        if ($maxIterations < 1 || $maxIterations > 512) {
            throw new \InvalidArgumentException(
                'maxIterations must be between 1 and 512 inclusive.',
            );
        }

        if ($maxPolynomialDegree < 1 || $maxPolynomialDegree > 256) {
            throw new \InvalidArgumentException(
                'maxPolynomialDegree must be between 1 and 256 inclusive.',
            );
        }

        foreach (
            [
                'absoluteTolerance' => $absoluteTolerance,
                'relativeTolerance' => $relativeTolerance,
                'residualTolerance' => $residualTolerance,
            ] as $name => $tolerance
        ) {
            if (!\is_finite($tolerance) || $tolerance <= 0.0) {
                throw new \InvalidArgumentException(
                    \sprintf('%s must be finite and greater than zero.', $name),
                );
            }
        }
    }

    public function positionTolerance(float $left, float $right): float
    {
        return $this->absoluteTolerance + $this->relativeTolerance
            * \max(\abs($left), \abs($right));
    }
}
