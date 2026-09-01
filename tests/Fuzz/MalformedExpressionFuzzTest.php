<?php

declare(strict_types=1);

namespace MathPHP\Tests\Fuzz;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\EvaluationException;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\ParseException;
use MathPHP\Math;
use PHPUnit\Framework\TestCase;

/**
 * FUZZ-001: deterministic malformed inputs are bounded and guaranteed invalid.
 */
final class MalformedExpressionFuzzTest extends TestCase
{
    private const SEED = 424242;

    private const GENERATED_CASE_COUNT = 240;

    /**
     * @var list<string>
     */
    private const DOCUMENTED_CODES = [
        'lex.malformed_number',
        'lex.number_out_of_range',
        'lex.unknown_character',
        'parse.empty_expression',
        'parse.expected_expression',
        'parse.expected_token',
        'parse.trailing_input',
        'eval.unknown_variable',
        'eval.unknown_function',
        'eval.arity',
        'eval.domain',
        'eval.division_by_zero',
        'eval.modulo_by_zero',
        'eval.integer_overflow',
        'eval.non_finite',
        'eval.custom_function',
        'limit.expression_length',
        'limit.token_count',
        'limit.nesting',
        'limit.ast_depth',
        'limit.function_arguments',
        'limit.factorial',
        'limit.exponent',
    ];

    private int $randomState = self::SEED;

    public function testFuzz001DeterministicMalformedCorpusHasDocumentedFailures(): void
    {
        foreach (self::malformedCorpus() as $case) {
            self::assertDocumentedFailure(
                $case['expression'],
                $case['options'],
                \sprintf('FUZZ-001 corpus=%s', $case['label']),
                $case['code'],
            );
        }
    }

    public function testFuzz001GeneratedGuaranteedInvalidInputsCompleteWithinBounds(): void
    {
        $completed = 0;
        $maximumLength = 0;

        for ($case = 0; $case < self::GENERATED_CASE_COUNT; ++$case) {
            $expression = $this->generatedInvalidExpression($case);
            $length = \strlen($expression);
            $maximumLength = \max($maximumLength, $length);
            $context = \sprintf(
                'FUZZ-001 seed=%d case=%d expression=%s',
                self::SEED,
                $case,
                self::describe($expression),
            );

            self::assertLessThanOrEqual(64, $length, $context);
            self::assertDocumentedFailure(
                $expression,
                null,
                $context,
            );
            ++$completed;
        }

        self::assertSame(self::GENERATED_CASE_COUNT, $completed);
        self::assertLessThanOrEqual(64, $maximumLength);
    }

    /**
     * @return list<array{
     *     label: string,
     *     expression: string,
     *     options: EvaluationOptions|null,
     *     code: string
     * }>
     */
    private static function malformedCorpus(): array
    {
        return [
            [
                'label' => 'empty input',
                'expression' => '',
                'options' => null,
                'code' => 'parse.empty_expression',
            ],
            [
                'label' => 'missing exponent digits',
                'expression' => '1e+',
                'options' => null,
                'code' => 'lex.malformed_number',
            ],
            [
                'label' => 'second decimal point',
                'expression' => '1..2',
                'options' => null,
                'code' => 'lex.malformed_number',
            ],
            [
                'label' => 'integer outside host range',
                'expression' => (string) \PHP_INT_MAX . '0',
                'options' => null,
                'code' => 'lex.number_out_of_range',
            ],
            [
                'label' => 'non-finite float literal',
                'expression' => '1e309',
                'options' => null,
                'code' => 'lex.number_out_of_range',
            ],
            [
                'label' => 'unexpected opening delimiter',
                'expression' => '(',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'missing closing delimiter',
                'expression' => '(1',
                'options' => null,
                'code' => 'parse.expected_token',
            ],
            [
                'label' => 'extra closing delimiter',
                'expression' => '1)',
                'options' => null,
                'code' => 'parse.trailing_input',
            ],
            [
                'label' => 'empty grouping',
                'expression' => '()',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'leading binary operator',
                'expression' => '*1',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'dangling binary operator',
                'expression' => '1+',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'doubled binary operator',
                'expression' => '1**2',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'repeated factorial',
                'expression' => '1!!',
                'options' => null,
                'code' => 'parse.trailing_input',
            ],
            [
                'label' => 'leading comma',
                'expression' => ',1',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'empty first argument',
                'expression' => 'sqrt(,1)',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'empty final argument',
                'expression' => 'sqrt(1,)',
                'options' => null,
                'code' => 'parse.expected_expression',
            ],
            [
                'label' => 'adjacent identifier',
                'expression' => '1name',
                'options' => null,
                'code' => 'eval.unknown_variable',
            ],
            [
                'label' => 'unknown identifier',
                'expression' => 'unknown_name',
                'options' => null,
                'code' => 'eval.unknown_variable',
            ],
            [
                'label' => 'unknown function',
                'expression' => 'unknown_call()',
                'options' => null,
                'code' => 'eval.unknown_function',
            ],
            [
                'label' => 'known function arity',
                'expression' => 'sqrt()',
                'options' => null,
                'code' => 'eval.arity',
            ],
            [
                'label' => 'NUL byte',
                'expression' => "\0",
                'options' => null,
                'code' => 'lex.unknown_character',
            ],
            [
                'label' => 'non-ASCII bytes',
                'expression' => "\xC3\xA9",
                'options' => null,
                'code' => 'lex.unknown_character',
            ],
            [
                'label' => 'invalid high byte after tokens',
                'expression' => "1+\xFF",
                'options' => null,
                'code' => 'lex.unknown_character',
            ],
            [
                'label' => 'expression byte limit',
                'expression' => '1 ',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxExpressionLength: 1),
                ),
                'code' => 'limit.expression_length',
            ],
            [
                'label' => 'token count limit',
                'expression' => '1+2',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxTokens: 2),
                ),
                'code' => 'limit.token_count',
            ],
            [
                'label' => 'nesting limit',
                'expression' => '(1)',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxNesting: 0),
                ),
                'code' => 'limit.nesting',
            ],
            [
                'label' => 'AST depth limit',
                'expression' => '-1',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxAstDepth: 1),
                ),
                'code' => 'limit.ast_depth',
            ],
            [
                'label' => 'function argument limit',
                'expression' => 'sqrt(1)',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxFunctionArguments: 0),
                ),
                'code' => 'limit.function_arguments',
            ],
            [
                'label' => 'factorial limit',
                'expression' => '1!',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxFactorial: 0),
                ),
                'code' => 'limit.factorial',
            ],
            [
                'label' => 'exponent magnitude limit',
                'expression' => '2^1',
                'options' => new EvaluationOptions(
                    limits: new ResourceLimits(maxExponentMagnitude: 0),
                ),
                'code' => 'limit.exponent',
            ],
        ];
    }

    private function generatedInvalidExpression(int $case): string
    {
        $number = (string) $this->randomInt(0, 999);

        return match ($case % 10) {
            0 => self::insertAt(
                '(' . $number . '+1)',
                $this->randomInt(0, \strlen($number) + 4),
                $this->randomInvalidByte(),
            ),
            1 => $number . $this->randomBinaryOperator(),
            2 => \str_repeat('(', $this->randomInt(1, 6)) . $number,
            3 => $number . ',' . $this->randomInt(0, 999),
            4 => $number . $this->randomMalformedNumberTail(),
            5 => $number . '!!',
            6 => $number . 'name',
            7 => $number . \str_repeat(')', $this->randomInt(1, 4)),
            8 => $this->randomLeadingOperator() . $number,
            9 => $this->randomInvalidArgumentList($number),
            default => throw new \LogicException(
                'The deterministic fuzz strategy selector returned an invalid value.',
            ),
        };
    }

    private function randomInvalidByte(): string
    {
        return match ($this->randomInt(0, 5)) {
            0 => '@',
            1 => '$',
            2 => "\0",
            3 => "\xFF",
            4 => "\xC3",
            5 => '?',
            default => throw new \LogicException(
                'The deterministic invalid-byte generator returned an invalid value.',
            ),
        };
    }

    private function randomBinaryOperator(): string
    {
        return match ($this->randomInt(0, 5)) {
            0 => '+',
            1 => '-',
            2 => '*',
            3 => '/',
            4 => '%',
            5 => '^',
            default => throw new \LogicException(
                'The deterministic binary-operator generator returned an invalid value.',
            ),
        };
    }

    private function randomLeadingOperator(): string
    {
        return match ($this->randomInt(0, 4)) {
            0 => '*',
            1 => '/',
            2 => '%',
            3 => '^',
            4 => '!',
            default => throw new \LogicException(
                'The deterministic leading-operator generator returned an invalid value.',
            ),
        };
    }

    private function randomMalformedNumberTail(): string
    {
        return match ($this->randomInt(0, 3)) {
            0 => 'e+',
            1 => '..' . $this->randomInt(0, 9),
            2 => 'e_' . $this->randomInt(0, 9),
            3 => '_' . $this->randomInt(0, 9),
            default => throw new \LogicException(
                'The deterministic malformed-number generator returned an invalid value.',
            ),
        };
    }

    private function randomInvalidArgumentList(string $number): string
    {
        return match ($this->randomInt(0, 2)) {
            0 => 'sqrt(,' . $number . ')',
            1 => 'sqrt(' . $number . ',)',
            2 => 'sqrt(' . $number . ',,' . $number . ')',
            default => throw new \LogicException(
                'The deterministic argument-list generator returned an invalid value.',
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

    private static function insertAt(
        string $expression,
        int $offset,
        string $insertion,
    ): string {
        return \substr($expression, 0, $offset)
            . $insertion
            . \substr($expression, $offset);
    }

    private static function assertDocumentedFailure(
        string $expression,
        ?EvaluationOptions $options,
        string $context,
        ?string $expectedCode = null,
    ): void {
        try {
            $result = Math::evaluate(
                $expression,
                options: $options,
            );
        } catch (MathException $exception) {
            self::assertTrue(
                \in_array(
                    $exception::class,
                    [
                        LexicalException::class,
                        ParseException::class,
                        EvaluationException::class,
                    ],
                    true,
                ),
                \sprintf(
                    '%s; undocumented exception subclass %s',
                    $context,
                    $exception::class,
                ),
            );
            self::assertContains(
                $exception->errorCode(),
                self::DOCUMENTED_CODES,
                $context,
            );

            if ($expectedCode !== null) {
                self::assertSame(
                    $expectedCode,
                    $exception->errorCode(),
                    $context,
                );
            }

            self::assertGreaterThanOrEqual(
                0,
                $exception->span()->start,
                $context,
            );
            self::assertGreaterThanOrEqual(
                $exception->span()->start,
                $exception->span()->end,
                $context,
            );
            self::assertLessThanOrEqual(
                \strlen($expression),
                $exception->span()->end,
                $context,
            );
            self::assertStringContainsString(
                \sprintf('position %d', $exception->span()->start),
                $exception->getMessage(),
                $context,
            );

            return;
        } catch (\Throwable $exception) {
            self::fail(
                \sprintf(
                    '%s; unexpected host exception %s: %s',
                    $context,
                    $exception::class,
                    $exception->getMessage(),
                ),
            );
        }

        self::fail(
            \sprintf(
                '%s; unexpectedly returned %s',
                $context,
                self::describeResult($result),
            ),
        );
    }

    private static function describe(string $expression): string
    {
        return \json_encode(
            $expression,
            \JSON_THROW_ON_ERROR
                | \JSON_INVALID_UTF8_SUBSTITUTE
                | \JSON_UNESCAPED_SLASHES,
        );
    }

    private static function describeResult(int|float $result): string
    {
        return \sprintf(
            '%s(%s)',
            \get_debug_type($result),
            (string) $result,
        );
    }
}
