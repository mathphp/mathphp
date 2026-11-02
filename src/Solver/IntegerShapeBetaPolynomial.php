<?php

declare(strict_types=1);

namespace MathPHP\Solver;

/** Exact polynomial form of the regularized incomplete beta for integer shapes. */
final class IntegerShapeBetaPolynomial
{
    /**
     * @param array{string, string} $target
     * @return list<array{string, string}> Coefficients in ascending powers.
     */
    public static function equationCoefficients(
        int $alpha,
        int $beta,
        bool $complement,
        array $target,
        ExactRationalPolynomialExtractor $rationalArithmetic,
        IntegerPolynomialExtractor $integerArithmetic,
    ): ?array {
        $degree = $alpha + $beta - 1;
        $coefficients = \array_fill(0, $degree + 1, '0');
        for ($successes = $alpha; $successes <= $degree; ++$successes) {
            $outerBinomial = self::binomial($degree, $successes, $integerArithmetic);
            if ($outerBinomial === null) {
                return null;
            }
            $innerBinomial = '1';
            $maximumPower = $degree - $successes;
            for ($complementPower = 0; $complementPower <= $maximumPower; ++$complementPower) {
                $term = $integerArithmetic->multiplyIntegerValues($outerBinomial, $innerBinomial);
                if (($complementPower & 1) !== 0) {
                    $term = $integerArithmetic->negateIntegerValue($term);
                }
                $power = $successes + $complementPower;
                $coefficients[$power] = $integerArithmetic->addIntegerValues($coefficients[$power], $term);
                if ($complementPower < $maximumPower) {
                    $product = $integerArithmetic->multiplyIntegerValues(
                        $innerBinomial,
                        (string) ($maximumPower - $complementPower),
                    );
                    $innerBinomial = $integerArithmetic->divideIntegerValueExactly(
                        $product,
                        (string) ($complementPower + 1),
                    );
                    if ($innerBinomial === null) {
                        return null;
                    }
                }
            }
        }

        if ($complement) {
            $coefficients[0] = $integerArithmetic->addIntegerValues('1', $integerArithmetic->negateIntegerValue(
                $coefficients[0],
            ));
            foreach ($coefficients as $power => $coefficient) {
                if ($power > 0) {
                    $coefficients[$power] = $integerArithmetic->negateIntegerValue($coefficient);
                }
            }
        }

        $negativeTarget = [
            $integerArithmetic->negateIntegerValue($target[0]),
            $target[1],
        ];
        $constant = $rationalArithmetic->addExactFractions(
            [$coefficients[0], '1'],
            $negativeTarget,
        );
        if ($constant === null) {
            return null;
        }

        $result = [$constant];
        for ($power = 1, $count = \count($coefficients); $power < $count; ++$power) {
            $result[] = [$coefficients[$power], '1'];
        }

        return $result;
    }

    private static function binomial(
        int $n,
        int $k,
        IntegerPolynomialExtractor $integerArithmetic,
    ): ?string {
        $k = \min($k, $n - $k);
        $result = '1';
        for ($factor = 1; $factor <= $k; ++$factor) {
            $product = $integerArithmetic->multiplyIntegerValues($result, (string) ($n - $k + $factor));
            $result = $integerArithmetic->divideIntegerValueExactly($product, (string) $factor);
            if ($result === null) {
                return null;
            }
        }

        return $result;
    }
}
