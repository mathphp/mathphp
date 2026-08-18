<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use PHPUnit\Framework\TestCase;

/**
 * PROP-001: bounded generated expressions use an independent integer oracle.
 */
final class GeneratedArithmeticPropertyTest extends TestCase
{
    private const SEED = 8675309;

    private const CASE_COUNT = 256;

    private int $randomState = self::SEED;

    public function testProp001GeneratedArithmeticMatchesIndependentExactOracle(): void
    {
        /** @var list<'+'|'-'|'*'> $forcedOperators */
        $forcedOperators = ['+', '-', '*'];
        $coveredOperators = [];
        $sawGrouping = false;
        $sawWhitespace = false;

        for ($case = 0; $case < self::CASE_COUNT; ++$case) {
            $forcedOperator = $forcedOperators[$case] ?? null;
            $generated = $this->generateExpression(0, $forcedOperator);
            $expression = $generated['expression'];

            if ($case === 0) {
                $expression = "(\t" . $expression . "\n)";
            }

            foreach ($forcedOperators as $operator) {
                if (\str_contains($expression, $operator)) {
                    $coveredOperators[$operator] = true;
                }
            }

            $sawGrouping = $sawGrouping || \str_contains($expression, '(');
            $sawWhitespace = $sawWhitespace
                || \preg_match('/[ \t\n\r\f\v]/', $expression) === 1;

            $context = self::failureContext($case, $expression);
            $actual = self::evaluateWithContext($expression, $context);

            self::assertIsInt($actual, $context);
            self::assertSame($generated['value'], $actual, $context);
        }

        self::assertSame(
            ['+' => true, '-' => true, '*' => true],
            $coveredOperators,
            \sprintf('PROP-001 operator coverage; seed=%d', self::SEED),
        );
        self::assertTrue(
            $sawGrouping,
            \sprintf('PROP-001 grouping coverage; seed=%d', self::SEED),
        );
        self::assertTrue(
            $sawWhitespace,
            \sprintf('PROP-001 whitespace coverage; seed=%d', self::SEED),
        );
    }

    /**
     * @param '+'|'-'|'*'|null $forcedOperator
     *
     * @return array{expression: string, value: int, precedence: int}
     */
    private function generateExpression(
        int $depth,
        ?string $forcedOperator = null,
    ): array {
        if (
            $forcedOperator === null
            && ($depth >= 3 || $this->randomInt(0, 3) === 0)
        ) {
            return $this->generateLiteral();
        }

        $operator = $forcedOperator ?? $this->randomOperator();
        $left = $this->generateExpression($depth + 1);
        $right = $this->generateExpression($depth + 1);
        $precedence = $operator === '*' ? 2 : 1;
        $value = match ($operator) {
            '+' => $left['value'] + $right['value'],
            '-' => $left['value'] - $right['value'],
            '*' => $left['value'] * $right['value'],
        };
        $expression = $this->serializeChild($left, $precedence, false)
            . $this->randomWhitespace()
            . $operator
            . $this->randomWhitespace()
            . $this->serializeChild($right, $precedence, true);

        if ($this->randomInt(0, 4) === 0) {
            return [
                'expression' => '('
                    . $this->randomWhitespace()
                    . $expression
                    . $this->randomWhitespace()
                    . ')',
                'value' => $value,
                'precedence' => 3,
            ];
        }

        return [
            'expression' => $expression,
            'value' => $value,
            'precedence' => $precedence,
        ];
    }

    /**
     * @return array{expression: string, value: int, precedence: int}
     */
    private function generateLiteral(): array
    {
        $value = $this->randomInt(-9, 9);

        return [
            'expression' => (string) $value,
            'value' => $value,
            'precedence' => 3,
        ];
    }

    /**
     * @param array{expression: string, value: int, precedence: int} $child
     */
    private function serializeChild(
        array $child,
        int $parentPrecedence,
        bool $rightChild,
    ): string {
        $needsParentheses = $child['precedence'] < $parentPrecedence
            || (
                $rightChild
                && $child['precedence'] === $parentPrecedence
            );

        if (!$needsParentheses) {
            return $child['expression'];
        }

        return '('
            . $this->randomWhitespace()
            . $child['expression']
            . $this->randomWhitespace()
            . ')';
    }

    /**
     * @return '+'|'-'|'*'
     */
    private function randomOperator(): string
    {
        return match ($this->randomInt(0, 2)) {
            0 => '+',
            1 => '-',
            2 => '*',
            default => throw new \LogicException(
                'The deterministic operator generator returned an invalid value.',
            ),
        };
    }

    private function randomWhitespace(): string
    {
        return match ($this->randomInt(0, 6)) {
            0 => '',
            1 => ' ',
            2 => "\t",
            3 => "\n",
            4 => "\r",
            5 => "\f",
            6 => "\v",
            default => throw new \LogicException(
                'The deterministic whitespace generator returned an invalid value.',
            ),
        };
    }

    private function randomInt(int $minimum, int $maximum): int
    {
        $high = \intdiv($this->randomState, 127773);
        $low = $this->randomState % 127773;
        $next = 16807 * $low - 2836 * $high;
        $this->randomState = $next > 0 ? $next : $next + 2147483647;

        return $minimum
            + ($this->randomState % ($maximum - $minimum + 1));
    }

    private static function evaluateWithContext(
        string $expression,
        string $context,
    ): int|float {
        try {
            return Math::evaluate($expression);
        } catch (\Throwable $exception) {
            self::fail(
                \sprintf(
                    '%s; unexpectedly threw %s: %s',
                    $context,
                    $exception::class,
                    $exception->getMessage(),
                ),
            );
        }
    }

    private static function failureContext(int $case, string $expression): string
    {
        return \sprintf(
            'PROP-001 seed=%d case=%d expression=%s',
            self::SEED,
            $case,
            \json_encode(
                $expression,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
