<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class FactorialNode implements Node
{
    public SourceSpan $span;

    public function __construct(
        public Node $operand,
        public SourceSpan $operatorSpan,
    ) {
        $this->span = $operand->span()->cover($operatorSpan);
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }

    public function depth(): int
    {
        return $this->operand->depth() + 1;
    }
}
