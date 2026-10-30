<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class EquationSolution
{
    public float $value;

    public float $residual;

    public int $iterations;

    public function __construct(float $value, float $residual, int $iterations)
    {
        if (
            !\is_finite($value)
            || !\is_finite($residual)
            || $residual < 0.0
            || $iterations < 0
        ) {
            throw new \InvalidArgumentException(
                'An equation solution requires a finite value, non-negative residual, '
                    . 'and non-negative iteration count.',
            );
        }

        $this->value = $value === 0.0 ? 0.0 : $value;
        $this->residual = $residual === 0.0 ? 0.0 : $residual;
        $this->iterations = $iterations;
    }
}
