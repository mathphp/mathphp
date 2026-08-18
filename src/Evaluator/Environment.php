<?php

declare(strict_types=1);

namespace MathPHP\Evaluator;

use MathPHP\Exception\EvaluationException;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Source\SourceSpan;

final readonly class Environment
{
    /**
     * @var array<string, int|float>
     */
    private array $variables;

    /**
     * @param array<mixed, mixed> $variables
     */
    public function __construct(
        array $variables,
        public FunctionRegistry $functions,
    ) {
        $validatedVariables = [];

        foreach ($variables as $name => $value) {
            if (
                !\is_string($name)
                || \preg_match(
                    '/\A[A-Za-z_][A-Za-z0-9_]*\z/D',
                    $name,
                ) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Every variable name must be a valid ASCII identifier.',
                );
            }

            if ($name === 'pi' || $name === 'e') {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'The constant name "%s" is reserved and cannot be overridden.',
                        $name,
                    ),
                );
            }

            if (
                (!\is_int($value) && !\is_float($value))
                || (\is_float($value) && !\is_finite($value))
            ) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Variable "%s" must have a finite integer or float value.',
                        $name,
                    ),
                );
            }

            $validatedVariables[$name] = $value;
        }

        $this->variables = $validatedVariables;
    }

    public function variable(string $name, SourceSpan $span): int|float
    {
        if (!\array_key_exists($name, $this->variables)) {
            throw new EvaluationException(
                \sprintf('Unknown variable "%s"', $name),
                'eval.unknown_variable',
                $span,
            );
        }

        return $this->variables[$name];
    }

    public function constant(string $name): float
    {
        return match ($name) {
            'pi' => \M_PI,
            'e' => \M_E,
            default => throw new \LogicException(
                \sprintf('Unknown parser-produced constant "%s".', $name),
            ),
        };
    }
}
