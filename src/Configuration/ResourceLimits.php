<?php

declare(strict_types=1);

namespace MathPHP\Configuration;

final readonly class ResourceLimits
{
    public int $maxFactorial;

    public function __construct(
        public int $maxExpressionLength = 4096,
        public int $maxTokens = 1024,
        public int $maxNesting = 64,
        public int $maxAstDepth = 128,
        public int $maxFunctionArguments = 16,
        ?int $maxFactorial = null,
        public int|float $maxExponentMagnitude = 1024,
    ) {
        self::requireAtLeast(
            $maxExpressionLength,
            1,
            'maxExpressionLength',
        );
        self::requireAtLeast($maxTokens, 1, 'maxTokens');
        self::requireAtLeast($maxNesting, 0, 'maxNesting');
        self::requireAtLeast($maxAstDepth, 1, 'maxAstDepth');
        self::requireAtLeast(
            $maxFunctionArguments,
            0,
            'maxFunctionArguments',
        );

        $factorialMaximum = self::factorialMaximum();
        $resolvedMaxFactorial = $maxFactorial ?? $factorialMaximum;

        if (
            $resolvedMaxFactorial < 0
            || $resolvedMaxFactorial > $factorialMaximum
        ) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'maxFactorial must be between 0 and %d inclusive.',
                    $factorialMaximum,
                ),
            );
        }

        if (
            !\is_finite((float) $maxExponentMagnitude)
            || $maxExponentMagnitude < 0
        ) {
            throw new \InvalidArgumentException(
                'maxExponentMagnitude must be a finite non-negative number.',
            );
        }

        $this->maxFactorial = $resolvedMaxFactorial;
    }

    public static function factorialMaximum(): int
    {
        $factorial = 1;
        $operand = 1;

        while (
            $operand < 20
            && $factorial <= \intdiv(\PHP_INT_MAX, $operand + 1)
        ) {
            ++$operand;
            $factorial *= $operand;
        }

        return $operand;
    }

    private static function requireAtLeast(
        int $value,
        int $minimum,
        string $name,
    ): void {
        if ($value < $minimum) {
            throw new \InvalidArgumentException(
                \sprintf('%s must be at least %d.', $name, $minimum),
            );
        }
    }
}
