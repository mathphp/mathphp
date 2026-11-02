<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class ComplexRationalSolution
{
    /**
     * @param list<ComplexRoot> $roots Numerator roots that are not denominator poles.
     */
    public function __construct(
        public array $roots,
        public bool $converged,
        public int $iterations,
    ) {
        if ($iterations < 0) {
            throw new \InvalidArgumentException('Iteration count must be non-negative.');
        }
    }
}
