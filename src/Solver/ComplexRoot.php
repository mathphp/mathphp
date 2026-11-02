<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class ComplexRoot
{
    public function __construct(
        public float $real,
        public float $imaginary,
        public float $residual,
    ) {
        if (
            !\is_finite($real)
            || !\is_finite($imaginary)
            || !\is_finite($residual)
            || $residual < 0.0
        ) {
            throw new \InvalidArgumentException(
                'A complex root requires finite components and a non-negative finite residual.',
            );
        }
    }
}
