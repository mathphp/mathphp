<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class GroupingNode implements Node
{
    public function __construct(
        public Node $expression,
        public SourceSpan $span,
    ) {
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }

    public function depth(): int
    {
        return $this->expression->depth() + 1;
    }
}
