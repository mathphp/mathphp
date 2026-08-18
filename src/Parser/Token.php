<?php

declare(strict_types=1);

namespace MathPHP\Parser;

use MathPHP\Source\SourceSpan;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public SourceSpan $span,
    ) {
    }
}
