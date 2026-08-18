<?php

declare(strict_types=1);

namespace MathPHP\Ast;

use MathPHP\Source\SourceSpan;

final readonly class FunctionCallNode implements Node
{
    /**
     * @var list<Node>
     */
    public array $arguments;

    /**
     * @param array<array-key, Node> $arguments
     */
    public function __construct(
        public string $name,
        array $arguments,
        public SourceSpan $nameSpan,
        public SourceSpan $span,
    ) {
        $this->arguments = \array_values($arguments);
    }

    public function span(): SourceSpan
    {
        return $this->span;
    }

    public function depth(): int
    {
        $maximumArgumentDepth = 0;

        foreach ($this->arguments as $argument) {
            $maximumArgumentDepth = \max(
                $maximumArgumentDepth,
                $argument->depth(),
            );
        }

        return $maximumArgumentDepth + 1;
    }
}
