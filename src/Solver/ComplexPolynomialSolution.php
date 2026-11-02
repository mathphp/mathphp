<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class ComplexPolynomialSolution
{
    /**
     * @param list<ComplexRoot> $roots Roots are listed with algebraic multiplicity.
     */
    public function __construct(
        public array $roots,
        public int $degree,
        public bool $converged,
        public int $iterations,
    ) {
        if ($degree < 0 || $iterations < 0 || \count($roots) !== $degree) {
            throw new \InvalidArgumentException(
                'A complex polynomial result must contain one candidate per polynomial degree.',
            );
        }
    }
}
