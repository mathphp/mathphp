<?php

declare(strict_types=1);

namespace MathPHP\Function;

final readonly class FunctionDefinition
{
    /**
     * @param \Closure(list<int|float>): mixed $callback
     */
    public function __construct(
        public string $name,
        public int $minArguments,
        public int $maxArguments,
        private \Closure $callback,
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
    }

    /**
     * @param array<array-key, int|float> $arguments
     */
    public function invoke(array $arguments): int|float
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

        $result = ($this->callback)($arguments);

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
}
