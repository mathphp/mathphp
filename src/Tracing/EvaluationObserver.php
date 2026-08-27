<?php

declare(strict_types=1);

namespace MathPHP\Tracing;

use MathPHP\Ast\Node;

/**
 * Receives completed AST-node evaluations in deterministic post-order.
 *
 * Implementations are an optional extension point. The default Math::evaluate
 * path does not allocate or invoke an observer.
 */
interface EvaluationObserver
{
    public function evaluated(Node $node, int|float $result, int $depth): void;
}
