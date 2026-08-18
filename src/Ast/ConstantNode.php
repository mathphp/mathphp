<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class ConstantNode implements Node
{
    public function __construct(
        public string $name,
        public SourceSpan $span,
    ) {
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }

    public function depth(): int
    {
        return 1;
    }
}
