<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class NumberNode implements Node
{
    public function __construct(
        public int|float $value,
        public string $literal,
        public SourceSpan $span,
    ) {
        if (\is_float($value) && !\is_finite($value)) {
            throw new \InvalidArgumentException(
                'A number node value must be finite.',
            );
        }
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
