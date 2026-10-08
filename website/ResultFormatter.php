<?php

declare(strict_types=1);

namespace MathPHP\Website;

final class ResultFormatter
{
    public static function format(int|float $result): string
    {
        if (is_int($result)) {
            return (string) $result;
        }

        $formatted = sprintf('%.14g', $result);
        $exponentPosition = strcspn($formatted, 'eE');
        if ($exponentPosition < strlen($formatted)) {
            $mantissa = substr($formatted, 0, $exponentPosition);
            $exponent = substr($formatted, $exponentPosition);
            $mantissa = str_contains($mantissa, '.')
                ? rtrim(rtrim($mantissa, '0'), '.')
                : $mantissa;

            return $mantissa . $exponent;
        }

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }
}
