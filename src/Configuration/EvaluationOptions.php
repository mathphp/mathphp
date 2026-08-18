<?php

declare(strict_types=1);

namespace MathPHP\Configuration;

use MathPHP\Function\FunctionRegistry;

final readonly class EvaluationOptions
{
    public ResourceLimits $limits;

    public FunctionRegistry $functions;

    public function __construct(
        ?ResourceLimits $limits = null,
        ?FunctionRegistry $functions = null,
    ) {
        $this->limits = $limits ?? new ResourceLimits();
        $this->functions = $functions ?? FunctionRegistry::defaults();
    }
}
