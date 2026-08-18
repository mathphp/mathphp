<?php

declare(strict_types=1);

namespace MathPHP\Source;

final readonly class SourceSpan
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start < 0) {
            throw new \InvalidArgumentException('A source span start must be non-negative.');
        }

        if ($end < $start) {
            throw new \InvalidArgumentException(
                'A source span end must not precede its start.',
            );
        }
    }

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public function cover(self $other): self
    {
        return new self(
            \min($this->start, $other->start),
            \max($this->end, $other->end),
        );
    }
}
