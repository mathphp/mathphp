<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class BinaryOperationNode implements Node
{
    public SourceSpan $span;

    public function __construct(
        public BinaryOperator $operator,
        public Node $left,
        public Node $right,
        public SourceSpan $operatorSpan,
    ) {
        $this->span = $left->span()
            ->cover($operatorSpan)
            ->cover($right->span());
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }

    public function depth(): int
    {
        return \max($this->left->depth(), $this->right->depth()) + 1;
    }
}
