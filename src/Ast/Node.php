<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

interface Node
{
    public function span(): SourceSpan;

    public function depth(): int;
}
