<?php

declare(strict_types=1);

namespace MathPHP\Exception;

use MathPHP\Source\SourceSpan;

abstract class MathException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly SourceSpan $span,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('%s at position %d', \rtrim($message), $span->start),
            0,
            $previous,
        );
    }

    final public function errorCode(): string
    {
        return $this->errorCode;
    }

    final public function span(): SourceSpan
    {
        return $this->span;
    }
}
