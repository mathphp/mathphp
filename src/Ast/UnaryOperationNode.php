<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class UnaryOperationNode implements Node
{
    public SourceSpan $span;

    public function __construct(
        public UnaryOperator $operator,
        public Node $operand,
        public SourceSpan $operatorSpan,
    ) {
        $this->span = $operatorSpan->cover($operand->span());
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
