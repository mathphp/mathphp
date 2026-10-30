<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final readonly class RationalRootInterval
{
    public function __construct(
        public string $lowerNumerator,
        public string $lowerDenominator,
        public string $upperNumerator,
        public string $upperDenominator,
        public bool $isExactRoot = false,
    ) {
        if (
            \preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $lowerNumerator) !== 1
            || \preg_match('/\A[1-9][0-9]*\z/D', $lowerDenominator) !== 1
            || \preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $upperNumerator) !== 1
            || \preg_match('/\A[1-9][0-9]*\z/D', $upperDenominator) !== 1
        ) {
            throw new \InvalidArgumentException('Root interval bounds must be normalized rational numbers.');
        }
        if ($isExactRoot) {
            if (
                $lowerNumerator !== $upperNumerator
                || $lowerDenominator !== $upperDenominator
            ) {
                throw new \InvalidArgumentException('An exact root interval must have equal bounds.');
            }

            return;
        }
        $arithmetic = new IntegerPolynomialExtractor('', []);
        if (
            $arithmetic->compareIntegerValues(
                $arithmetic->multiplyIntegerValues($lowerNumerator, $upperDenominator),
                $arithmetic->multiplyIntegerValues($upperNumerator, $lowerDenominator),
            ) >= 0
        ) {
            throw new \InvalidArgumentException('A root isolating interval must be increasing.');
        }
    }
}
