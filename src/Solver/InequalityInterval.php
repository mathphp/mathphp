<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class InequalityInterval
{
    public function __construct(
        public float $lowerBound,
        public float $upperBound,
        public bool $includesLowerBound,
        public bool $includesUpperBound,
    ) {
        if (
            !\is_finite($lowerBound)
            || !\is_finite($upperBound)
            || $lowerBound > $upperBound
            || ($lowerBound === $upperBound && (!$includesLowerBound || !$includesUpperBound))
        ) {
            throw new \InvalidArgumentException(
                'An inequality interval requires finite ordered bounds and a non-empty endpoint set.',
            );
        }
    }

    public function contains(float $value): bool
    {
        if (!\is_finite($value)) {
            return false;
        }

        return ($value > $this->lowerBound
                || ($this->includesLowerBound && $value === $this->lowerBound))
            && ($value < $this->upperBound
                || ($this->includesUpperBound && $value === $this->upperBound));
    }
}
