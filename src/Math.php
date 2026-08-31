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
     * Pass the observer as the second argument when no variables are needed.
     * The array-first form remains supported for backwards compatibility.
     *
     * @param array<string, int|float>|EvaluationObserver $variables
     */
    public static function evaluateWithObserver(
        string $expression,
        array|EvaluationObserver $variables = [],
        ?EvaluationObserver $observer = null,
        ?EvaluationOptions $options = null,
    ): int|float {
        if ($variables instanceof EvaluationObserver) {
            if ($observer !== null) {
                throw new \InvalidArgumentException(
                    'Pass either an observer or variables followed by an observer, not both observers.',
                );
            }
            $observer = $variables;
            $variables = [];
        }
        if ($observer === null) {
            throw new \InvalidArgumentException('An EvaluationObserver is required.');
        }

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
