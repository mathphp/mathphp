<?php

declare(strict_types=1);

namespace MathPHP;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Evaluator\Environment;
use MathPHP\Evaluator\Evaluator;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Tracing\EvaluationObserver;

final class Math
{
    /**
     * @param array<string, int|float> $variables
     */
    public static function evaluate(
        string $expression,
        array $variables = [],
        ?EvaluationOptions $options = null,
    ): int|float {
        $resolvedOptions = $options ?? new EvaluationOptions();
        $environment = new Environment($variables, $resolvedOptions->functions);
        $tokens = (new Lexer($expression, $resolvedOptions->limits))->tokenize();
        $ast = (new Parser($tokens, $resolvedOptions->limits))->parse();

        return (new Evaluator(
            $environment,
            $resolvedOptions->limits,
        ))->evaluate($ast);
    }

    /**
     * Evaluate while notifying an optional extension observer after each AST
     * node completes. The returned scalar is identical to evaluate().
     *
     * @param array<string, int|float> $variables
     */
    public static function evaluateWithObserver(
        string $expression,
        array $variables,
        EvaluationObserver $observer,
        ?EvaluationOptions $options = null,
    ): int|float {
        $resolvedOptions = $options ?? new EvaluationOptions();
        $environment = new Environment($variables, $resolvedOptions->functions);
        $tokens = (new Lexer($expression, $resolvedOptions->limits))->tokenize();
        $ast = (new Parser($tokens, $resolvedOptions->limits))->parse();

        return (new Evaluator(
            $environment,
            $resolvedOptions->limits,
            $observer,
        ))->evaluate($ast);
    }
}
