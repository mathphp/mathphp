<?php

declare(strict_types=1);

namespace MathPHP\Function;

final readonly class FunctionDefinition
{
    /**
     * @param \Closure(list<int|float>): mixed $callback
     * @param (\Closure(list<int|float>): mixed)|null $gradient Partials by argument.
     * @param (\Closure(list<array{float, float}>): mixed)|null $range Sound output enclosure.
     * @param (\Closure(list<array{float, float}>): mixed)|null $domain
     *        Return true only when the function is defined throughout every supplied interval.
     * @param int|null $exactIdentityArgument Assert that the function is total and returns this argument unchanged.
     */
    public function __construct(
        public string $name,
        public int $minArguments,
        public int $maxArguments,
        private \Closure $callback,
        private ?\Closure $gradient = null,
        private ?\Closure $range = null,
        private ?\Closure $domain = null,
        public ?int $exactIdentityArgument = null,
    ) {
        if (
            \preg_match(
                '/\A[A-Za-z_][A-Za-z0-9_]*\z/D',
                $name,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'A function name must be a valid ASCII identifier.',
            );
        }

        if ($minArguments < 0) {
            throw new \InvalidArgumentException(
                'A function minimum arity must be non-negative.',
            );
        }

        if ($maxArguments < $minArguments) {
            throw new \InvalidArgumentException(
                'A function maximum arity must not be less than its minimum arity.',
            );
        }

        if (
            $exactIdentityArgument !== null
            && ($exactIdentityArgument < 0 || $exactIdentityArgument >= $minArguments)
        ) {
            throw new \InvalidArgumentException(
                'An exact identity argument must be present for every allowed function call.',
            );
        }
        if ($exactIdentityArgument !== null && $domain !== null) {
            throw new \InvalidArgumentException(
                'An exact identity rewrite requires a total function without a domain extension.',
            );
        }
    }

    /**
     * @param array<array-key, int|float> $arguments
     */
    public function invoke(array $arguments): int|float
    {
        $validatedArguments = $this->validatedArguments($arguments);

        $result = ($this->callback)($validatedArguments);

        if (!\is_int($result) && !\is_float($result)) {
            throw new \UnexpectedValueException(
                \sprintf(
                    'Function "%s" must return an integer or float.',
                    $this->name,
                ),
            );
        }

        return $result;
    }

    /**
     * Return the partial derivative for each argument, or null when unavailable.
     *
     * @param array<array-key, int|float> $arguments
     * @return list<float>|null
     */
    public function gradient(array $arguments): ?array
    {
        if ($this->gradient === null) {
            return null;
        }
        $validatedArguments = $this->validatedArguments($arguments);
        try {
            $partials = ($this->gradient)($validatedArguments);
        } catch (\DomainException | \OverflowException) {
            return null;
        }
        if (!\is_array($partials) || !\array_is_list($partials) || \count($partials) !== \count($arguments)) {
            throw new \UnexpectedValueException(
                \sprintf(
                    'Function "%s" gradient must return one partial derivative per argument.',
                    $this->name,
                ),
            );
        }

        $gradient = [];
        foreach ($partials as $partial) {
            if (!\is_int($partial) && !\is_float($partial)) {
                throw new \UnexpectedValueException(
                    \sprintf('Function "%s" gradient values must be numeric.', $this->name),
                );
            }
            $partial = (float) $partial;
            if (!\is_finite($partial)) {
                return null;
            }
            $gradient[] = $partial;
        }

        return $gradient;
    }

    /** @param array<array-key, int|float> $arguments
     *  @return list<int|float>
     */
    private function validatedArguments(array $arguments): array
    {
        if (!\array_is_list($arguments)) {
            throw new \InvalidArgumentException(
                'Function arguments must be provided as a list.',
            );
        }

        $argumentCount = \count($arguments);

        if (
            $argumentCount < $this->minArguments
            || $argumentCount > $this->maxArguments
        ) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Function "%s" expects %s.',
                    $this->name,
                    $this->arityDescription(),
                ),
            );
        }

        foreach ($arguments as $argument) {
            if (\is_float($argument) && !\is_finite($argument)) {
                throw new \InvalidArgumentException(
                    'Function arguments must be finite numbers.',
                );
            }
        }

        return $arguments;
    }

    public function arityDescription(): string
    {
        if ($this->minArguments === $this->maxArguments) {
            return \sprintf(
                'exactly %d %s',
                $this->minArguments,
                $this->minArguments === 1 ? 'argument' : 'arguments',
            );
        }

        return \sprintf(
            'between %d and %d arguments',
            $this->minArguments,
            $this->maxArguments,
        );
    }

    public function hasGradient(): bool
    {
        return $this->gradient !== null;
    }

    /**
     * @param list<array{float, float}> $argumentIntervals
     * @return array{float, float}|null
     */
    public function intervalExtension(array $argumentIntervals): ?array
    {
        if ($this->range === null) {
            return null;
        }
        if (\count($argumentIntervals) < $this->minArguments || \count($argumentIntervals) > $this->maxArguments) {
            throw new \InvalidArgumentException(
                \sprintf('Function "%s" received an invalid number of argument intervals.', $this->name),
            );
        }
        foreach ($argumentIntervals as [$lower, $upper]) {
            if (!\is_finite($lower) || !\is_finite($upper) || $lower > $upper) {
                throw new \InvalidArgumentException('Argument intervals must have finite ordered bounds.');
            }
        }

        try {
            $bounds = ($this->range)($argumentIntervals);
        } catch (\DomainException | \OverflowException) {
            return null;
        }
        if (
            !\is_array($bounds)
            || !\array_is_list($bounds)
            || \count($bounds) !== 2
            || (!\is_int($bounds[0]) && !\is_float($bounds[0]))
            || (!\is_int($bounds[1]) && !\is_float($bounds[1]))
        ) {
            throw new \UnexpectedValueException(
                \sprintf('Function "%s" interval extension must return two numeric bounds.', $this->name),
            );
        }
        $lower = \is_int($bounds[0])
            ? self::outwardFloatIntegerBound($bounds[0], true)
            : $bounds[0];
        $upper = \is_int($bounds[1])
            ? self::outwardFloatIntegerBound($bounds[1], false)
            : $bounds[1];
        if (!\is_finite($lower) || !\is_finite($upper) || $lower > $upper) {
            throw new \UnexpectedValueException(
                \sprintf('Function "%s" interval extension returned invalid bounds.', $this->name),
            );
        }

        return [$lower, $upper];
    }

    private static function outwardFloatIntegerBound(int $value, bool $lowerBound): float
    {
        $float = (float) $value;
        $comparison = self::compareIntegerStrings(\sprintf('%.0f', $float), (string) $value);
        if (($lowerBound && $comparison > 0) || (!$lowerBound && $comparison < 0)) {
            $margin = 2.0 * \PHP_FLOAT_EPSILON * \abs($float);
            $float += $lowerBound ? -$margin : $margin;
        }

        return $float;
    }

    private static function compareIntegerStrings(string $left, string $right): int
    {
        $leftNegative = $left[0] === '-';
        $rightNegative = $right[0] === '-';
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $leftDigits = $leftNegative ? \substr($left, 1) : $left;
        $rightDigits = $rightNegative ? \substr($right, 1) : $right;
        $comparison = \strlen($leftDigits) <=> \strlen($rightDigits);
        if ($comparison === 0) {
            $comparison = \strcmp($leftDigits, $rightDigits) <=> 0;
        }

        return $leftNegative ? -$comparison : $comparison;
    }

    public function hasIntervalExtension(): bool
    {
        return $this->range !== null;
    }

    /**
     * @param list<array{float, float}> $argumentIntervals
     */
    public function isDefinedOnIntervals(array $argumentIntervals): bool
    {
        if ($this->domain === null) {
            return false;
        }
        if (
            \count($argumentIntervals) < $this->minArguments
            || \count($argumentIntervals) > $this->maxArguments
        ) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Function "%s" received an invalid number of argument intervals.',
                    $this->name,
                ),
            );
        }
        foreach ($argumentIntervals as [$lower, $upper]) {
            if (!\is_finite($lower) || !\is_finite($upper) || $lower > $upper) {
                throw new \InvalidArgumentException('Argument intervals must have finite ordered bounds.');
            }
        }

        try {
            $defined = ($this->domain)($argumentIntervals);
        } catch (\DomainException | \OverflowException) {
            return false;
        }
        if (!\is_bool($defined)) {
            throw new \UnexpectedValueException(
                \sprintf('Function "%s" domain extension must return a boolean.', $this->name),
            );
        }

        return $defined;
    }

    public function hasDomainExtension(): bool
    {
        return $this->domain !== null;
    }
}
