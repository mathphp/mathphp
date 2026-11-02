<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class SystemSolution
{
    /**
     * @param array<string, float> $values
     * @param float $residualNorm Euclidean norm of raw residuals in equation units.
     */
    public function __construct(
        public array $values,
        public float $residualNorm,
        public int $iterations,
    ) {
        if (
            $values === []
            || !\is_finite($residualNorm)
            || $residualNorm < 0.0
            || $iterations < 0
        ) {
            throw new \InvalidArgumentException(
                'A system solution requires values, a finite non-negative residual norm, '
                . 'and non-negative iterations.',
            );
        }

        foreach ($values as $value) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException(
                    'System solution values must be finite.',
                );
            }
        }
    }
}
