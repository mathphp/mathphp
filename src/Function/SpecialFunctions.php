<?php

declare(strict_types=1);

namespace MathPHP\Function;

final class SpecialFunctions
{
    private const MAX_ITERATIONS = 10_000;

    private const CONVERGENCE_TOLERANCE = 4.0 * \PHP_FLOAT_EPSILON;

    public const MAX_POLYGAMMA_ORDER = 128;

    public const MAX_BESSEL_ORDER = 256;

    public const MAX_BESSEL_Y_ARGUMENT = 1_000.0;

    public const MAX_BESSEL_J_ARGUMENT = 1_000.0;

    public const MAX_BESSEL_I_ARGUMENT = 713.0;

    public static function riemannZetaContinuation(float $value): float
    {
        if (!\is_finite($value) || $value < -0.5 || $value === 1.0) {
            throw new \DomainException('zeta() continuation requires finite s >= -0.5 and s != 1.');
        }
        if ($value < 0.5) {
            return self::riemannZetaAndDerivativeByEulerMaclaurin($value)[0];
        }

        $differences = [];
        for ($index = 1; $index <= 129; ++$index) {
            $differences[] = $index ** (-$value);
        }

        $eta = 0.0;
        $weight = 0.5;
        $converged = false;
        for ($order = 0; $order < 129; ++$order) {
            $term = $weight * $differences[0];
            $eta += $term;
            if ($order >= 24 && \abs($term) <= 8.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($eta))) {
                $converged = true;
                break;
            }
            $count = \count($differences) - 1;
            for ($index = 0; $index < $count; ++$index) {
                $differences[$index] -= $differences[$index + 1];
            }
            \array_pop($differences);
            $weight *= 0.5;
        }
        if (!$converged) {
            throw new \DomainException('zeta() could not produce a converged result.');
        }

        $denominator = -\expm1((1.0 - $value) * \log(2.0));
        $result = $eta / $denominator;
        if (!\is_finite($result)) {
            throw new \DomainException('zeta() could not produce a finite result.');
        }

        return $result;
    }

    /** @return array{float, float} Value and derivative. */
    private static function riemannZetaAndDerivativeByEulerMaclaurin(float $value): array
    {
        $terms = [
            1.0 / 6.0,
            -1.0 / 30.0,
            1.0 / 42.0,
            -1.0 / 30.0,
            5.0 / 66.0,
            -691.0 / 2730.0,
            7.0 / 6.0,
            -3617.0 / 510.0,
        ];
        $cutoff = 16;
        $result = 0.0;
        $resultCompensation = 0.0;
        $derivative = 0.0;
        $derivativeCompensation = 0.0;
        for ($index = 1; $index < $cutoff; ++$index) {
            $term = $index ** (-$value);
            $adjustedTerm = $term - $resultCompensation;
            $nextResult = $result + $adjustedTerm;
            $resultCompensation = ($nextResult - $result) - $adjustedTerm;
            $result = $nextResult;

            $derivativeTerm = -\log((float) $index) * $term;
            $adjustedDerivative = $derivativeTerm - $derivativeCompensation;
            $nextDerivative = $derivative + $adjustedDerivative;
            $derivativeCompensation = ($nextDerivative - $derivative) - $adjustedDerivative;
            $derivative = $nextDerivative;
        }
        $tail = ($cutoff ** (1.0 - $value)) / ($value - 1.0);
        $halfTerm = 0.5 * ($cutoff ** (-$value));
        foreach ([$tail, $halfTerm] as $term) {
            $adjustedTerm = $term - $resultCompensation;
            $nextResult = $result + $adjustedTerm;
            $resultCompensation = ($nextResult - $result) - $adjustedTerm;
            $result = $nextResult;
        }
        foreach (
            [
                $tail * (-\log((float) $cutoff) - (1.0 / ($value - 1.0))),
                -\log((float) $cutoff) * $halfTerm,
            ] as $term
        ) {
            $adjustedTerm = $term - $derivativeCompensation;
            $nextDerivative = $derivative + $adjustedTerm;
            $derivativeCompensation = ($nextDerivative - $derivative) - $adjustedTerm;
            $derivative = $nextDerivative;
        }

        $rising = $value;
        $risingDerivative = 1.0;
        $factorial = 2.0;
        foreach ($terms as $index => $bernoulli) {
            $order = $index + 1;
            if ($order > 1) {
                $firstFactor = $value + (2 * $order) - 3;
                $secondFactor = $value + (2 * $order) - 2;
                $risingDerivative = ($risingDerivative * $firstFactor * $secondFactor)
                    + ($rising * ($firstFactor + $secondFactor));
                $rising *= $firstFactor * $secondFactor;
                $factorial *= ((2 * $order) - 1) * (2 * $order);
            }
            $power = $cutoff ** (-$value - (2 * $order) + 1);
            $coefficient = $bernoulli / $factorial;
            $term = $coefficient * $rising * $power;
            $adjustedTerm = $term - $resultCompensation;
            $nextResult = $result + $adjustedTerm;
            $resultCompensation = ($nextResult - $result) - $adjustedTerm;
            $result = $nextResult;

            $derivativeTerm = $coefficient * $power
                * ($risingDerivative - (\log((float) $cutoff) * $rising));
            $adjustedDerivative = $derivativeTerm - $derivativeCompensation;
            $nextDerivative = $derivative + $adjustedDerivative;
            $derivativeCompensation = ($nextDerivative - $derivative) - $adjustedDerivative;
            $derivative = $nextDerivative;
        }

        if (!\is_finite($result) || !\is_finite($derivative)) {
            throw new \DomainException('zeta() could not produce a finite result.');
        }

        return [$result, $derivative];
    }

    public static function riemannZetaDerivative(float $value): ?float
    {
        if (!\is_finite($value) || $value === 1.0) {
            return null;
        }
        if ($value >= 64.0) {
            return 0.0;
        }
        if ($value >= -0.5 && $value < 0.5) {
            return self::riemannZetaAndDerivativeByEulerMaclaurin($value)[1];
        }
        if ($value < 0.0) {
            if ($value > -0.25) {
                return self::riemannZetaDerivativeFromEta($value);
            }
            $positiveArgument = 1.0 - $value;
            $positiveValue = $positiveArgument >= 64.0
                ? 1.0
                : self::riemannZetaContinuation($positiveArgument);
            if (\floor($value / 2.0) === $value / 2.0) {
                $cosine = \cos(0.5 * \M_PI * $value);
                $logMagnitude = ($value * \log(2.0))
                    + (($value - 1.0) * \log(\M_PI))
                    + self::logGammaPositive($positiveArgument)
                    + \log(0.5 * \M_PI)
                    + \log($positiveValue);
                $derivative = ($cosine < 0.0 ? -1.0 : 1.0) * \exp($logMagnitude);

                return \is_finite($derivative) ? $derivative : null;
            }

            $positiveDerivative = $positiveArgument >= 64.0
                ? 0.0
                : self::riemannZetaDerivative($positiveArgument);
            if ($positiveDerivative === null || $positiveValue === 0.0) {
                return null;
            }
            $sine = \sin(0.5 * \M_PI * $value);
            $cosine = \cos(0.5 * \M_PI * $value);
            $logMagnitude = ($value * \log(2.0))
                + (($value - 1.0) * \log(\M_PI))
                + self::logGammaPositive($positiveArgument)
                + \log(\abs($sine))
                + \log($positiveValue);
            $zetaValue = ($sine < 0.0 ? -1.0 : 1.0) * \exp($logMagnitude);
            $derivative = $zetaValue * (\log(2.0) + \log(\M_PI)
                - self::digamma($positiveArgument)
                - ($positiveDerivative / $positiveValue))
                + (\exp($logMagnitude - \log(\abs($sine)))
                    * (0.5 * \M_PI * $cosine));

            return \is_finite($derivative) ? $derivative : null;
        }
        $distanceFromPole = $value - 1.0;
        if (\abs($distanceFromPole) < 1.0E-3) {
            return -(1.0 / ($distanceFromPole * $distanceFromPole))
                + 0.07281584548367672
                - (0.009690363192872318 * $distanceFromPole);
        }

        $differences = [];
        $derivativeDifferences = [];
        for ($index = 1; $index <= 129; ++$index) {
            $term = $index ** (-$value);
            $differences[] = $term;
            $derivativeDifferences[] = -\log((float) $index) * $term;
        }

        $eta = 0.0;
        $etaDerivative = 0.0;
        $weight = 0.5;
        for ($order = 0; $order < 129; ++$order) {
            $etaTerm = $weight * $differences[0];
            $derivativeTerm = $weight * $derivativeDifferences[0];
            $eta += $etaTerm;
            $etaDerivative += $derivativeTerm;
            if (
                $order >= 24
                && \abs($etaTerm) <= 8.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($eta))
                && \abs($derivativeTerm)
                    <= 8.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($etaDerivative))
            ) {
                break;
            }

            $count = \count($differences) - 1;
            for ($index = 0; $index < $count; ++$index) {
                $differences[$index] -= $differences[$index + 1];
                $derivativeDifferences[$index] -= $derivativeDifferences[$index + 1];
            }
            \array_pop($differences);
            \array_pop($derivativeDifferences);
            $weight *= 0.5;
        }

        $powerOfTwo = \exp((1.0 - $value) * \log(2.0));
        $denominator = -\expm1((1.0 - $value) * \log(2.0));
        $derivative = ($etaDerivative / $denominator)
            - (($eta * \log(2.0) * $powerOfTwo) / ($denominator * $denominator));

        return \is_finite($derivative) ? $derivative : null;
    }

    private static function riemannZetaDerivativeFromEta(float $value): ?float
    {
        $differences = [];
        $derivativeDifferences = [];
        for ($index = 1; $index <= 129; ++$index) {
            $term = $index ** (-$value);
            $differences[] = $term;
            $derivativeDifferences[] = -\log((float) $index) * $term;
        }

        $eta = 0.0;
        $etaDerivative = 0.0;
        $weight = 0.5;
        for ($order = 0; $order < 129; ++$order) {
            $etaTerm = $weight * $differences[0];
            $derivativeTerm = $weight * $derivativeDifferences[0];
            $eta += $etaTerm;
            $etaDerivative += $derivativeTerm;
            if (
                $order >= 24
                && \abs($etaTerm) <= 8.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($eta))
                && \abs($derivativeTerm)
                    <= 8.0 * \PHP_FLOAT_EPSILON * \max(1.0, \abs($etaDerivative))
            ) {
                $denominator = -\expm1((1.0 - $value) * \log(2.0));
                $derivative = ($etaDerivative / $denominator)
                    - (($eta * \log(2.0) * \exp((1.0 - $value) * \log(2.0))) / ($denominator * $denominator));

                return \is_finite($derivative) ? $derivative : null;
            }

            $count = \count($differences) - 1;
            for ($index = 0; $index < $count; ++$index) {
                $differences[$index] -= $differences[$index + 1];
                $derivativeDifferences[$index] -= $derivativeDifferences[$index + 1];
            }
            \array_pop($differences);
            \array_pop($derivativeDifferences);
            $weight *= 0.5;
        }

        return null;
    }

    public static function regularizedBeta(float $firstShape, float $secondShape, float $value): float
    {
        if (
            !\is_finite($firstShape)
            || !\is_finite($secondShape)
            || !\is_finite($value)
            || $firstShape <= 0.0
            || $secondShape <= 0.0
            || $value < 0.0
            || $value > 1.0
        ) {
            throw new \DomainException('betainc() requires a > 0, b > 0, and x in [0, 1].');
        }
        if ($value === 0.0) {
            return 0.0;
        }
        if ($value === 1.0) {
            return 1.0;
        }
        if ($firstShape === 1.0 && $secondShape === 1.0) {
            return $value;
        }
        if ($firstShape === 1.0) {
            return -\expm1($secondShape * \log1p(-$value));
        }
        if ($secondShape === 1.0) {
            return \exp($firstShape * \log($value));
        }
        $largeShapeTail = self::regularizedBetaLargeSecondShapeTail($firstShape, $secondShape, $value);
        if ($largeShapeTail !== null) {
            return 1.0 - $largeShapeTail;
        }
        $shapeSum = $firstShape + $secondShape;
        if (!\is_finite($shapeSum)) {
            throw new \DomainException('betainc() shape sum exceeds the finite numeric range.');
        }

        $logBeta = self::logGammaPositive(\min($firstShape, $secondShape))
            - self::logGammaRatioPositive(\max($firstShape, $secondShape), \min($firstShape, $secondShape));
        $logFront = ($firstShape * \log($value))
            + ($secondShape * \log1p(-$value))
            - $logBeta;
        if ($value < ($firstShape + 1.0) / ($shapeSum + 2.0)) {
            $result = \exp($logFront)
                * self::betaContinuedFraction($firstShape, $secondShape, $value)
                / $firstShape;
        } else {
            $complement = \exp($logFront)
                * self::betaContinuedFraction($secondShape, $firstShape, 1.0 - $value)
                / $secondShape;
            $result = 1.0 - $complement;
        }
        if (!\is_finite($result)) {
            throw new \DomainException('betainc() could not produce a finite converged result.');
        }

        return \min(1.0, \max(0.0, $result));
    }

    public static function regularizedBetaComplement(
        float $firstShape,
        float $secondShape,
        float $value,
    ): float {
        if (
            !\is_finite($firstShape)
            || !\is_finite($secondShape)
            || !\is_finite($value)
            || $firstShape <= 0.0
            || $secondShape <= 0.0
            || $value < 0.0
            || $value > 1.0
        ) {
            throw new \DomainException('betaincc() requires a > 0, b > 0, and x in [0, 1].');
        }
        if ($value === 0.0) {
            return 1.0;
        }
        if ($value === 1.0) {
            return 0.0;
        }
        if ($firstShape === 1.0 || $secondShape === 1.0) {
            self::regularizedBeta($firstShape, $secondShape, $value);
            if ($firstShape === 1.0) {
                return \exp($secondShape * \log1p(-$value));
            }

            return -\expm1($firstShape * \log($value));
        }
        $largeShapeTail = self::regularizedBetaLargeSecondShapeTail($firstShape, $secondShape, $value);
        if ($largeShapeTail !== null) {
            return $largeShapeTail;
        }

        if (1.0 - $value === 1.0) {
            return 1.0 - self::regularizedBeta($firstShape, $secondShape, $value);
        }

        return self::regularizedBeta($secondShape, $firstShape, 1.0 - $value);
    }

    private static function regularizedBetaLargeSecondShapeTail(
        float $firstShape,
        float $secondShape,
        float $value,
    ): ?float {
        if ($firstShape > 1.0E-12 || $secondShape < 1.0E8 || ($secondShape * $value) > 1.0) {
            return null;
        }

        // For a tiny first shape and large second shape, b*X converges to Gamma(a, 1).
        return self::regularizedGammaQ($firstShape, $secondShape * $value);
    }

    public static function inverseRegularizedBeta(
        float $firstShape,
        float $secondShape,
        float $probability,
    ): float {
        return self::inverseRegularizedBetaLevel($firstShape, $secondShape, $probability, false);
    }

    public static function inverseRegularizedBetaComplement(
        float $firstShape,
        float $secondShape,
        float $probability,
    ): float {
        return self::inverseRegularizedBetaLevel($firstShape, $secondShape, $probability, true);
    }

    private static function inverseRegularizedBetaLevel(
        float $firstShape,
        float $secondShape,
        float $probability,
        bool $complement,
    ): float {
        if (
            !\is_finite($firstShape)
            || !\is_finite($secondShape)
            || !\is_finite($probability)
            || $firstShape <= 0.0
            || $secondShape <= 0.0
            || $probability < 0.0
            || $probability > 1.0
        ) {
            $function = $complement ? 'betainccinv' : 'betaincinv';
            throw new \DomainException(
                $function . '() requires a > 0, b > 0, and p in [0, 1].',
            );
        }
        if ($probability === 0.0) {
            return $complement ? 1.0 : 0.0;
        }
        if ($probability === 1.0) {
            return $complement ? 0.0 : 1.0;
        }

        $useComplement = $probability > 0.5 ? !$complement : $complement;
        $target = $probability > 0.5 ? 1.0 - $probability : $probability;
        $increasing = !$useComplement;
        $evaluate = $useComplement
            ? static fn (float $value): float => self::regularizedBetaComplement(
                $firstShape,
                $secondShape,
                $value,
            )
            : static fn (float $value): float => self::regularizedBeta(
                $firstShape,
                $secondShape,
                $value,
            );
        $lower = 0.0;
        $upper = 1.0;
        for ($iteration = 0; $iteration < 1100; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            $value = $evaluate($midpoint);
            if (($value < $target) === $increasing) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }
        $lowerError = \abs($evaluate($lower) - $target);
        $upperError = \abs($evaluate($upper) - $target);
        $quantile = $lowerError <= $upperError ? $lower : $upper;
        if ($quantile === 0.0 || $quantile === 1.0) {
            throw new \DomainException(
                'The inverse regularized beta quantile is below floating-point resolution.',
            );
        }

        return $quantile;
    }

    public static function regularizedBetaDensity(
        float $firstShape,
        float $secondShape,
        float $value,
    ): float {
        if (
            !\is_finite($firstShape)
            || !\is_finite($secondShape)
            || !\is_finite($value)
            || $firstShape <= 0.0
            || $secondShape <= 0.0
            || $value < 0.0
            || $value > 1.0
        ) {
            throw new \DomainException('The beta density requires a > 0, b > 0, and x in [0, 1].');
        }
        if ($value === 0.0) {
            if ($firstShape < 1.0) {
                throw new \DomainException('The beta density is unbounded at x = 0 when a < 1.');
            }

            return $firstShape === 1.0 ? $secondShape : 0.0;
        }
        if ($value === 1.0) {
            if ($secondShape < 1.0) {
                throw new \DomainException('The beta density is unbounded at x = 1 when b < 1.');
            }

            return $secondShape === 1.0 ? $firstShape : 0.0;
        }
        if ($firstShape === 1.0) {
            $logDensity = \log($secondShape) + (($secondShape - 1.0) * \log1p(-$value));
        } elseif ($secondShape === 1.0) {
            $logDensity = \log($firstShape) + (($firstShape - 1.0) * \log($value));
        } else {
            $shapeSum = $firstShape + $secondShape;
            if (!\is_finite($shapeSum)) {
                throw new \DomainException('The beta density shape sum exceeds the finite numeric range.');
            }
            $logBeta = self::logGammaPositive(\min($firstShape, $secondShape))
                - self::logGammaRatioPositive(
                    \max($firstShape, $secondShape),
                    \min($firstShape, $secondShape),
                );
            $logDensity = (($firstShape - 1.0) * \log($value))
                + (($secondShape - 1.0) * \log1p(-$value))
                - $logBeta;
        }
        if (!\is_finite($logDensity) || $logDensity > \log(\PHP_FLOAT_MAX)) {
            throw new \DomainException('The beta density exceeds the finite numeric range.');
        }

        return \exp($logDensity);
    }

    private static function betaContinuedFraction(float $firstShape, float $secondShape, float $value): float
    {
        $tiny = 1.0E-300;
        $sum = $firstShape + $secondShape;
        $firstPlusOne = $firstShape + 1.0;
        $firstMinusOne = $firstShape - 1.0;
        $denominator = 1.0 - (($sum * $value) / $firstPlusOne);
        if (\abs($denominator) < $tiny) {
            $denominator = $tiny;
        }
        $denominator = 1.0 / $denominator;
        $fraction = $denominator;
        $numerator = 1.0;
        for ($iteration = 1; $iteration <= self::MAX_ITERATIONS; ++$iteration) {
            $twiceIteration = 2.0 * $iteration;
            $coefficient = ($iteration * ($secondShape - $iteration) * $value)
                / (($firstMinusOne + $twiceIteration) * ($firstShape + $twiceIteration));
            $denominator = 1.0 + ($coefficient * $denominator);
            if (\abs($denominator) < $tiny) {
                $denominator = $tiny;
            }
            $numerator = 1.0 + ($coefficient / $numerator);
            if (\abs($numerator) < $tiny) {
                $numerator = $tiny;
            }
            $denominator = 1.0 / $denominator;
            $fraction *= $denominator * $numerator;

            $coefficient = -(($firstShape + $iteration) * ($sum + $iteration) * $value)
                / (($firstShape + $twiceIteration) * ($firstPlusOne + $twiceIteration));
            $denominator = 1.0 + ($coefficient * $denominator);
            if (\abs($denominator) < $tiny) {
                $denominator = $tiny;
            }
            $numerator = 1.0 + ($coefficient / $numerator);
            if (\abs($numerator) < $tiny) {
                $numerator = $tiny;
            }
            $denominator = 1.0 / $denominator;
            $delta = $denominator * $numerator;
            $fraction *= $delta;
            if (\abs($delta - 1.0) <= self::CONVERGENCE_TOLERANCE) {
                return $fraction;
            }
        }

        throw new \DomainException('betainc() did not converge within its iteration limit.');
    }

    public static function regularizedGammaP(float $shape, float $value): float
    {
        self::validateRegularizedGammaArguments($shape, $value);
        if ($value === 0.0) {
            return 0.0;
        }
        if ($value === \INF) {
            return 1.0;
        }
        $logFactor = (-$value) + ($shape * \log($value)) - self::logGammaPositive($shape);
        if ($value >= $shape + 1.0) {
            return 1.0 - self::regularizedGammaQContinuedFraction($shape, $value, $logFactor);
        }

        $term = 1.0;
        $sum = $term;
        $denominator = $shape + 1.0;
        $seriesLogFactor = (-$value) + ($shape * \log($value))
            - self::logGammaPositive($shape + 1.0);
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $term *= $value / $denominator;
            $denominator += 1.0;
            $sum += $term;
            if (\abs($term) <= \abs($sum) * self::CONVERGENCE_TOLERANCE) {
                $result = $sum * \exp($seriesLogFactor);
                if (!\is_finite($result)) {
                    throw new \DomainException('gammaP() could not produce a finite converged result.');
                }

                return \min(1.0, \max(0.0, $result));
            }
        }

        throw new \DomainException('gammaP() did not converge within its iteration limit.');
    }

    public static function regularizedGammaQ(float $shape, float $value): float
    {
        self::validateRegularizedGammaArguments($shape, $value);
        if ($value === 0.0) {
            return 1.0;
        }
        if ($shape <= 1.0E-8) {
            // Avoid both complement cancellation and the continued fraction's small-x convergence loss.
            if ($value < 5.0E-3) {
                return $shape * self::exponentialIntegralE1($value);
            }

            $logFactor = (-$value) + ($shape * \log($value)) - self::logGammaPositive($shape);
            try {
                return self::regularizedGammaQContinuedFraction($shape, $value, $logFactor);
            } catch (\DomainException) {
                return $shape * self::exponentialIntegralE1($value);
            }
        }
        if ($value < $shape + 1.0) {
            return 1.0 - self::regularizedGammaP($shape, $value);
        }
        $logFactor = (-$value) + ($shape * \log($value)) - self::logGammaPositive($shape);

        return self::regularizedGammaQContinuedFraction($shape, $value, $logFactor);
    }

    private static function exponentialIntegralE1(float $value): float
    {
        if ($value > 1.0) {
            $tiny = 1.0E-300;
            $b = $value + 1.0;
            $c = 1.0 / $tiny;
            $d = 1.0 / $b;
            $fraction = $d;
            for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
                $coefficient = -$index * $index;
                $b += 2.0;
                $d = ($coefficient * $d) + $b;
                if (\abs($d) < $tiny) {
                    $d = $tiny;
                }
                $c = $b + ($coefficient / $c);
                if (\abs($c) < $tiny) {
                    $c = $tiny;
                }
                $d = 1.0 / $d;
                $delta = $d * $c;
                $fraction *= $delta;
                if (\abs($delta - 1.0) <= self::CONVERGENCE_TOLERANCE) {
                    return \exp(-$value) * $fraction;
                }
            }

            throw new \DomainException('Exponential integral E1 did not converge.');
        }

        $term = 1.0;
        $series = 0.0;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $term *= -$value / $index;
            $addition = $term / $index;
            $series += $addition;
            if (\abs($addition) <= self::CONVERGENCE_TOLERANCE * \max(1.0, \abs($series))) {
                return -0.5772156649015329 - \log($value) - $series;
            }
        }

        throw new \DomainException('gammaincc() small-shape expansion did not converge.');
    }

    public static function inverseRegularizedGammaP(float $shape, float $probability): float
    {
        return self::inverseRegularizedGammaLevel($shape, $probability, false);
    }

    public static function inverseRegularizedGammaQ(float $shape, float $probability): float
    {
        return self::inverseRegularizedGammaLevel($shape, $probability, true);
    }

    private static function inverseRegularizedGammaLevel(
        float $shape,
        float $probability,
        bool $complement,
    ): float {
        if (
            !\is_finite($shape)
            || !\is_finite($probability)
            || $shape <= 0.0
            || $probability < 0.0
            || $probability > 1.0
        ) {
            $function = $complement ? 'gammainccinv' : 'gammaincinv';
            throw new \DomainException($function . '() requires a > 0 and p in [0, 1].');
        }
        if (($complement && $probability === 1.0) || (!$complement && $probability === 0.0)) {
            return 0.0;
        }
        if ($probability === 0.0 || $probability === 1.0) {
            throw new \DomainException('The requested inverse regularized gamma quantile is infinite.');
        }

        $useComplement = $complement ? $probability <= 0.5 : $probability > 0.5;
        $target = $probability > 0.5 ? 1.0 - $probability : $probability;
        $increasing = !$useComplement;
        $evaluate = $useComplement
            ? static fn (float $value): float => self::regularizedGammaQ($shape, $value)
            : static fn (float $value): float => self::regularizedGammaP($shape, $value);

        $lower = 0.0;
        $upper = max(1.0, $shape);
        while ($increasing ? $evaluate($upper) < $target : $evaluate($upper) > $target) {
            if ($upper >= \PHP_FLOAT_MAX / 2.0) {
                throw new \DomainException('The inverse regularized gamma quantile exceeds the finite numeric range.');
            }
            $upper *= 2.0;
        }

        for ($iteration = 0; $iteration < 1100; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            $value = $evaluate($midpoint);
            if (($value < $target) === $increasing) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        $lowerError = \abs($evaluate($lower) - $target);
        $upperError = \abs($evaluate($upper) - $target);
        $quantile = $lowerError <= $upperError ? $lower : $upper;
        if ($quantile === 0.0) {
            throw new \DomainException(
                'The inverse regularized gamma quantile is below positive floating-point resolution.',
            );
        }
        $verifiedProbability = $evaluate($quantile);
        if ($verifiedProbability === 0.0 || !\is_finite($verifiedProbability)) {
            throw new \DomainException(
                'The inverse regularized gamma probability is below reliable floating-point resolution.',
            );
        }
        return $quantile;
    }

    public static function regularizedGammaDensity(float $shape, float $value): float
    {
        self::validateRegularizedGammaArguments($shape, $value);
        if ($value === 0.0) {
            if ($shape > 1.0) {
                return 0.0;
            }
            if ($shape === 1.0) {
                return 1.0;
            }

            throw new \DomainException('The regularized gamma density is unbounded at x = 0 when a < 1.');
        }
        $logDensity = (($shape - 1.0) * \log($value)) - $value
            - self::logGammaPositive($shape);
        if (!\is_finite($logDensity) || $logDensity > \log(\PHP_FLOAT_MAX)) {
            throw new \DomainException('The regularized gamma density exceeds the finite numeric range.');
        }

        return \exp($logDensity);
    }

    public static function regularizedGammaShapeDerivativeAtOne(float $value): float
    {
        return self::regularizedGammaShapeDerivative(1.0, $value);
    }

    public static function regularizedGammaShapeDerivativeAtPositiveInteger(
        float $shape,
        float $value,
    ): float {
        self::validateRegularizedGammaArguments($shape, $value);
        if ($shape !== \floor($shape) || $shape > self::MAX_ITERATIONS) {
            throw new \DomainException(
                'The gamma shape derivative requires an integer shape within its iteration limit.',
            );
        }
        return self::regularizedGammaShapeDerivative($shape, $value);
    }

    public static function regularizedGammaShapeDerivative(float $shape, float $value): float
    {
        self::validateRegularizedGammaArguments($shape, $value);
        if ($value === 0.0) {
            return 0.0;
        }

        $logValue = \log($value);
        $currentShape = $shape;
        $recurrenceCorrection = 0.0;
        $shifts = 0;
        while ($value >= $currentShape + 1.0) {
            if (++$shifts > self::MAX_ITERATIONS) {
                throw new \DomainException('gammaP() shape derivative exceeded its recurrence limit.');
            }
            $nextShape = $currentShape + 1.0;
            if ($nextShape <= $currentShape) {
                throw new \DomainException('gammaP() shape derivative recurrence cannot advance at this scale.');
            }
            $logTerm = (-$value) + ($currentShape * $logValue) - self::logGammaPositive($nextShape);
            $term = $logTerm < \log(5.0E-324) ? 0.0 : \exp($logTerm);
            $recurrenceCorrection += $term * ($logValue - self::digamma($nextShape));
            $currentShape = $nextShape;
        }

        $term = 1.0;
        $termDerivative = 0.0;
        $sum = 1.0;
        $derivativeSum = 0.0;
        $converged = false;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $denominator = $currentShape + $index;
            $ratio = $value / $denominator;
            $nextTerm = $term * $ratio;
            $nextTermDerivative = ($termDerivative * $ratio) - ($nextTerm / $denominator);
            $sum += $nextTerm;
            $derivativeSum += $nextTermDerivative;
            $term = $nextTerm;
            $termDerivative = $nextTermDerivative;
            if (
                \abs($term) <= \abs($sum) * self::CONVERGENCE_TOLERANCE
                && \abs($termDerivative) <= self::CONVERGENCE_TOLERANCE * \max(1.0, \abs($derivativeSum))
            ) {
                $converged = true;
                break;
            }
        }
        if (!$converged) {
            throw new \DomainException('gammaP() shape derivative series did not converge.');
        }

        $logPrefactor = (-$value) + ($currentShape * $logValue)
            - self::logGammaPositive($currentShape + 1.0);
        if (!\is_finite($logPrefactor)) {
            throw new \DomainException('gammaP() shape derivative exceeded the finite numeric range.');
        }
        $prefactor = $logPrefactor < \log(5.0E-324) ? 0.0 : \exp($logPrefactor);
        $derivative = $recurrenceCorrection + ($prefactor * (
            $derivativeSum + ($sum * ($logValue - self::digamma($currentShape + 1.0)))
        ));

        if (!\is_finite($derivative)) {
            throw new \DomainException('gammaP() shape derivative could not produce a finite result.');
        }

        return $derivative;
    }

    private static function validateRegularizedGammaArguments(float $shape, float $value): void
    {
        if (!\is_finite($shape) || $shape <= 0.0 || !\is_finite($value) || $value < 0.0) {
            throw new \DomainException('gammaP()/gammaQ() require a > 0 and x >= 0 finite arguments.');
        }
    }

    private static function regularizedGammaQContinuedFraction(
        float $shape,
        float $value,
        float $logFactor,
    ): float {
        $tiny = 1.0E-300;
        $b = $value + 1.0 - $shape;
        if (\abs($b) < $tiny) {
            $b = $tiny;
        }
        $c = 1.0 / $tiny;
        $d = 1.0 / $b;
        $fraction = $d;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $coefficient = -$index * ($index - $shape);
            $b += 2.0;
            $d = ($coefficient * $d) + $b;
            if (\abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = $b + ($coefficient / $c);
            if (\abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $delta = $d * $c;
            $fraction *= $delta;
            if (\abs($delta - 1.0) <= self::CONVERGENCE_TOLERANCE) {
                $result = \exp($logFactor) * $fraction;
                if (!\is_finite($result)) {
                    throw new \DomainException('gammaQ() could not produce a finite converged result.');
                }

                return \min(1.0, \max(0.0, $result));
            }
        }

        throw new \DomainException('gammaQ() did not converge within its iteration limit.');
    }

    private static function logGammaPositive(float $value): float
    {
        if ($value === 1.0) {
            return 0.0;
        }
        if ($value < 0.5) {
            return self::logGammaPositive($value + 1.0) - \log($value);
        }

        $coefficients = [
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716E-6,
            1.5056327351493116E-7,
        ];
        $shifted = $value - 1.0;
        $series = 0.99999999999980993;
        foreach ($coefficients as $index => $coefficient) {
            $series += $coefficient / ($shifted + $index + 1.0);
        }
        $base = $shifted + 7.5;

        return (0.5 * \log(2.0 * \M_PI))
            + (($shifted + 0.5) * \log($base))
            - $base
            + \log($series);
    }

    private static function logGammaRatioPositive(float $base, float $increment): float
    {
        if ($increment / $base <= 1.0E-8) {
            $firstOrder = $increment * self::digamma($base);
            $secondOrder = 0.5 * ($increment ** 2) * self::trigamma($base);
            $thirdOrder = (($increment ** 3) / 6.0) * self::tetragamma($base);

            return $firstOrder + $secondOrder + $thirdOrder;
        }

        return self::logGammaPositive($base + $increment) - self::logGammaPositive($base);
    }

    public static function besselJ(int $order, float $value): float
    {
        if (
            \abs($order) > self::MAX_BESSEL_ORDER
            || !\is_finite($value)
            || \abs($value) > self::MAX_BESSEL_J_ARGUMENT
        ) {
            throw new \DomainException(\sprintf(
                'besselj() requires an integer order from -%1$d through %1$d and an argument in [-1000, 1000].',
                self::MAX_BESSEL_ORDER,
            ));
        }

        if ($order < 0) {
            $sign = ($order & 1) === 0 ? 1.0 : -1.0;

            return $sign * self::besselJ(-$order, $value);
        }
        if ($value < 0.0) {
            $sign = ($order & 1) === 0 ? 1.0 : -1.0;

            return $sign * self::besselJ($order, -$value);
        }
        if ($value === 0.0) {
            return $order === 0 ? 1.0 : 0.0;
        }

        if ($value > 12.0) {
            return self::besselJByBackwardRecurrence($order, $value);
        }

        $halfValue = $value / 2.0;
        $term = 1.0;
        for ($factor = 1; $factor <= $order; ++$factor) {
            $term *= $halfValue / $factor;
        }
        $sum = $term;
        $squaredQuarter = ($value * $value) / 4.0;
        $converged = false;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $term *= -$squaredQuarter / ($index * ($index + $order));
            $sum += $term;
            if (\abs($term) <= \abs($sum) * self::CONVERGENCE_TOLERANCE) {
                $converged = true;
                break;
            }
        }

        if (!\is_finite($sum) || !$converged) {
            throw new \DomainException('besselj() could not produce a finite converged result.');
        }
        if ($sum === 0.0 && $order > $value) {
            return \PHP_FLOAT_MIN * \PHP_FLOAT_EPSILON;
        }

        return $sum;
    }

    public static function besselY(int $order, float $value): float
    {
        if (
            \abs($order) > self::MAX_BESSEL_ORDER
            || !\is_finite($value)
            || $value <= 0.0
            || $value > self::MAX_BESSEL_Y_ARGUMENT
        ) {
            throw new \DomainException(\sprintf(
                'bessely() requires an integer order from -%1$d through %1$d and an argument in (0, 1000].',
                self::MAX_BESSEL_ORDER,
            ));
        }

        $positiveOrder = \abs($order);
        if ($value < 1.0E-300 && $positiveOrder === 0) {
            return (2.0 / \M_PI) * (\log($value) - \log(2.0) + 0.5772156649015329);
        }
        if ($value < 1.0E-300 && $positiveOrder > 0) {
            // The leading small-argument form avoids rejecting representable Y1 values.
            if ($positiveOrder === 1) {
                $result = -2.0 / (\M_PI * $value);
                if (!\is_finite($result)) {
                    throw new \DomainException('bessely() has no finite result for this order and argument.');
                }

                return $order < 0 ? -$result : $result;
            }

            $logMagnitude = self::logGammaPositive((float) $positiveOrder)
                + ($positiveOrder * (\log(2.0) - \log($value)))
                - \log(\M_PI);
            if ($logMagnitude >= \log(\PHP_FLOAT_MAX)) {
                throw new \DomainException('bessely() has no finite result for this order and argument.');
            }

            $result = -\exp($logMagnitude);

            return $order < 0 && (($positiveOrder & 1) === 1) ? -$result : $result;
        }

        [$zeroOrder, $firstOrder] = $value > 12.0
            ? [self::besselYAsymptotic(0, $value), self::besselYAsymptotic(1, $value)]
            : self::besselYSeriesSeeds($value);

        if ($positiveOrder === 0) {
            $result = $zeroOrder;
        } elseif ($positiveOrder === 1) {
            $result = $firstOrder;
        } else {
            $previous = $zeroOrder;
            $current = $firstOrder;
            for ($currentOrder = 1; $currentOrder < $positiveOrder; ++$currentOrder) {
                $next = ((2.0 * $currentOrder / $value) * $current) - $previous;
                if (!\is_finite($next)) {
                    throw new \DomainException('bessely() could not produce a finite result for this order.');
                }
                $previous = $current;
                $current = $next;
            }
            $result = $current;
        }

        if (!\is_finite($result)) {
            throw new \DomainException('bessely() could not produce a finite result.');
        }

        return $order < 0 && (($positiveOrder & 1) === 1) ? -$result : $result;
    }

    /** @return array{float, float} */
    private static function besselYSeriesSeeds(float $value): array
    {
        $besselZero = self::besselJ(0, $value);
        $besselOne = self::besselJ(1, $value);
        $quarterSquare = ($value * $value) / 4.0;
        $power = -1.0;
        $harmonic = 0.0;
        $series = 0.0;
        $seriesCompensation = 0.0;
        $derivativeSeries = 0.0;
        $derivativeCompensation = 0.0;
        $converged = false;

        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $power *= -$quarterSquare / ($index * $index);
            $harmonic += 1.0 / $index;
            $term = $harmonic * $power;
            $derivativeTerm = $term * (2.0 * $index / $value);

            $adjustedTerm = $term - $seriesCompensation;
            $nextSeries = $series + $adjustedTerm;
            $seriesCompensation = ($nextSeries - $series) - $adjustedTerm;
            $series = $nextSeries;

            $adjustedDerivativeTerm = $derivativeTerm - $derivativeCompensation;
            $nextDerivativeSeries = $derivativeSeries + $adjustedDerivativeTerm;
            $derivativeCompensation = ($nextDerivativeSeries - $derivativeSeries)
                - $adjustedDerivativeTerm;
            $derivativeSeries = $nextDerivativeSeries;

            if (
                $index > ($value / 2.0)
                && \abs($term) <= self::CONVERGENCE_TOLERANCE * \max(1.0, \abs($series))
                && \abs($derivativeTerm)
                    <= self::CONVERGENCE_TOLERANCE * \max(1.0, \abs($derivativeSeries))
            ) {
                $converged = true;
                break;
            }
        }

        if (!$converged || !\is_finite($series) || !\is_finite($derivativeSeries)) {
            throw new \DomainException('bessely() power series did not converge to a finite result.');
        }

        $logarithmicFactor = \log($value) - \log(2.0) + 0.5772156649015329;
        $zeroOrder = (2.0 / \M_PI) * (($logarithmicFactor * $besselZero) + $series);
        $firstOrder = (2.0 / \M_PI)
            * (($logarithmicFactor * $besselOne) - ($besselZero / $value) - $derivativeSeries);

        return [$zeroOrder, $firstOrder];
    }

    private static function besselYAsymptotic(int $order, float $value): float
    {
        $evenSeries = 1.0;
        $oddSeries = 0.0;
        $coefficient = 1.0;
        $inversePower = 1.0;
        $previousTermMagnitude = \INF;

        for ($index = 1; $index <= 64; ++$index) {
            $coefficient *= ((4.0 * $order * $order) - ((2.0 * $index - 1.0) ** 2))
                / (8.0 * $index);
            $inversePower /= $value;
            $term = $coefficient * $inversePower;
            $magnitude = \abs($term);
            if ($magnitude > $previousTermMagnitude) {
                break;
            }

            if (($index & 1) === 0) {
                $evenSeries += ((intdiv($index, 2) & 1) === 0 ? 1.0 : -1.0) * $term;
            } else {
                $oddSeries += ((intdiv($index - 1, 2) & 1) === 0 ? 1.0 : -1.0) * $term;
            }

            if ($magnitude <= self::CONVERGENCE_TOLERANCE) {
                break;
            }
            $previousTermMagnitude = $magnitude;
        }

        $phase = $value - (0.5 * $order * \M_PI) - (0.25 * \M_PI);

        return \sqrt(2.0 / (\M_PI * $value))
            * ((\sin($phase) * $evenSeries) + (\cos($phase) * $oddSeries));
    }

    private static function besselJByBackwardRecurrence(int $order, float $value): float
    {
        $argumentSeedOrder = (int) \ceil($value + (50.0 * ($value ** (1.0 / 3.0))) + 100.0);
        $requestedOrderSeed = $order + (50 * (int) \ceil($order ** (1.0 / 3.0))) + 100;
        $maximumOrder = \max($argumentSeedOrder, $requestedOrderSeed);
        if (($maximumOrder & 1) !== 0) {
            ++$maximumOrder;
        }
        $sequence = \array_fill(0, $maximumOrder + 2, 0.0);
        $sequence[$maximumOrder] = 1.0;
        for ($currentOrder = $maximumOrder; $currentOrder > 0; --$currentOrder) {
            $sequence[$currentOrder - 1] = (2.0 * $currentOrder / $value)
                * $sequence[$currentOrder]
                - $sequence[$currentOrder + 1];
            if (\abs($sequence[$currentOrder - 1]) > 1.0E100) {
                for ($index = $currentOrder - 1; $index <= $maximumOrder + 1; ++$index) {
                    $sequence[$index] *= 1.0E-100;
                }
            }
        }

        $normalizer = $sequence[0];
        $compensation = 0.0;
        for ($evenOrder = 2; $evenOrder <= $maximumOrder; $evenOrder += 2) {
            $term = (2.0 * $sequence[$evenOrder]) - $compensation;
            $next = $normalizer + $term;
            $compensation = ($next - $normalizer) - $term;
            $normalizer = $next;
        }
        if ($normalizer === 0.0 || !\is_finite($normalizer)) {
            throw new \DomainException('besselj() could not normalize its recurrence.');
        }

        $result = $sequence[$order] / $normalizer;
        if (!\is_finite($result)) {
            throw new \DomainException('besselj() could not produce a finite converged result.');
        }
        if ($result === 0.0 && $order > $value) {
            return \PHP_FLOAT_MIN * \PHP_FLOAT_EPSILON;
        }

        return $result;
    }

    public static function besselI(int $order, float $value): float
    {
        if (
            \abs($order) > self::MAX_BESSEL_ORDER
            || !\is_finite($value)
            || \abs($value) > self::MAX_BESSEL_I_ARGUMENT
        ) {
            throw new \DomainException(\sprintf(
                'besseli() requires an integer order from -%1$d through %1$d and an argument in [-713, 713].',
                self::MAX_BESSEL_ORDER,
            ));
        }

        $order = \abs($order);
        $sign = $value < 0.0 && ($order & 1) === 1 ? -1.0 : 1.0;
        $value = \abs($value);
        if ($value === 0.0) {
            return $order === 0 ? 1.0 : 0.0;
        }

        $halfValue = $value / 2.0;
        $term = 1.0;
        for ($factor = 1; $factor <= $order; ++$factor) {
            $term *= $halfValue / $factor;
        }
        $sum = $term;
        $compensation = 0.0;
        $squaredQuarter = ($value * $value) / 4.0;
        $converged = false;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $term *= $squaredQuarter / ($index * ($index + $order));
            $adjustedTerm = $term - $compensation;
            $nextSum = $sum + $adjustedTerm;
            $compensation = ($nextSum - $sum) - $adjustedTerm;
            $sum = $nextSum;
            if ($term <= $sum * self::CONVERGENCE_TOLERANCE) {
                $converged = true;
                break;
            }
        }

        if (!\is_finite($sum) || !$converged) {
            throw new \DomainException('besseli() could not produce a finite converged result.');
        }

        return $sign * $sum;
    }

    /** @return list<float> Real preimages, in ascending order. */
    public static function inverseBesselI(int $order, float $value): array
    {
        if (
            \abs($order) > self::MAX_BESSEL_ORDER
            || !\is_finite($value)
        ) {
            throw new \DomainException('besseli inverse requires a supported integer order and finite level.');
        }

        $order = \abs($order);
        $evenOrder = ($order & 1) === 0;
        if ($evenOrder && ($value < 0.0 || ($order === 0 && $value < 1.0))) {
            return [];
        }
        if ($order === 0 && $value === 1.0) {
            return [0.0];
        }
        if ($value === 0.0) {
            return $order === 0 ? [] : [0.0];
        }

        $sign = $evenOrder || $value > 0.0 ? 1.0 : -1.0;
        $target = \abs($value);
        $maximum = self::besselI($order, self::MAX_BESSEL_I_ARGUMENT);
        if ($target > $maximum) {
            return [];
        }

        if ($order === 0 && ($target - 1.0) < 1.0E-8) {
            $delta = $target - 1.0;
            $squaredQuarter = $delta - (($delta ** 2) / 4.0) + ((7.0 * ($delta ** 3)) / 72.0);
            $positiveRoot = 2.0 * \sqrt(\max(0.0, $squaredQuarter));

            return [-$positiveRoot, $positiveRoot];
        }

        $smallArgumentTarget = \exp(
            ($order * \log(5.0E-7)) - self::logGammaPositive($order + 1.0),
        );
        if ($order > 0 && $target < $smallArgumentTarget) {
            $logRoot = (
                \log($target)
                + ($order * \log(2.0))
                + self::logGammaPositive($order + 1.0)
            ) / $order;
            $positiveRoot = \exp($logRoot);
        } else {
            $lower = 0.0;
            $upper = self::MAX_BESSEL_I_ARGUMENT;
            for ($iteration = 0; $iteration < 128; ++$iteration) {
                $midpoint = $lower + (($upper - $lower) / 2.0);
                if ($midpoint === $lower || $midpoint === $upper) {
                    break;
                }
                if (self::besselI($order, $midpoint) < $target) {
                    $lower = $midpoint;
                } else {
                    $upper = $midpoint;
                }
            }
            $positiveRoot = $lower + (($upper - $lower) / 2.0);
        }

        if (!$evenOrder) {
            return [$sign * $positiveRoot];
        }
        if ($positiveRoot === 0.0) {
            return [0.0];
        }

        return [-$positiveRoot, $positiveRoot];
    }

    public static function erf(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erf() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 0.0;
        }

        $absolute = \abs($value);
        if ($absolute < 0.5) {
            $square = $absolute * $absolute;
            $term = $absolute;
            $sum = $term;
            for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
                $term *= -$square / $index;
                $addition = $term / ((2.0 * $index) + 1.0);
                $sum += $addition;
                if (\abs($addition) <= \abs($sum) * self::CONVERGENCE_TOLERANCE) {
                    break;
                }
            }
            $result = (2.0 / \sqrt(\M_PI)) * $sum;

            return $value < 0.0 ? -$result : $result;
        }

        $complement = self::positiveErfc($absolute);
        $result = 1.0 - $complement;

        return $value < 0.0 ? -$result : $result;
    }

    public static function erfi(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erfi() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 0.0;
        }

        $absolute = \abs($value);
        $square = $absolute * $absolute;
        if (!\is_finite($square)) {
            throw new \DomainException('erfi() has no finite result for this argument.');
        }

        $term = $absolute;
        $sum = $term;
        $compensation = 0.0;
        $converged = false;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $term *= ($square / $index)
                * (((2.0 * $index) - 1.0) / ((2.0 * $index) + 1.0));
            if (!\is_finite($term)) {
                throw new \DomainException('erfi() has no finite result for this argument.');
            }

            $adjustedTerm = $term - $compensation;
            $nextSum = $sum + $adjustedTerm;
            $compensation = ($nextSum - $sum) - $adjustedTerm;
            $sum = $nextSum;
            if ($index > $square && $term <= $sum * self::CONVERGENCE_TOLERANCE) {
                $converged = true;
                break;
            }
        }

        $result = (2.0 / \sqrt(\M_PI)) * $sum;
        if (!$converged || !\is_finite($result)) {
            throw new \DomainException('erfi() has no finite converged result for this argument.');
        }

        return $value < 0.0 ? -$result : $result;
    }

    public static function inverseErfi(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erfi inverse requires a finite argument.');
        }
        if ($value === 0.0) {
            return 0.0;
        }

        $sign = $value < 0.0 ? -1.0 : 1.0;
        $target = \abs($value);
        if ($target < 1.0E-8) {
            $scaled = 0.5 * \sqrt(\M_PI) * $target;

            return $sign * ($scaled - (($scaled ** 3) / 3.0));
        }

        $lower = 0.0;
        $upper = 1.0;
        while (true) {
            try {
                if (self::erfi($upper) >= $target) {
                    break;
                }
            } catch (\DomainException) {
                break;
            }
            $lower = $upper;
            $upper *= 2.0;
            if (!\is_finite($upper)) {
                throw new \DomainException('erfi inverse could not bracket its finite result.');
            }
        }

        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            try {
                $midpointValue = self::erfi($midpoint);
            } catch (\DomainException) {
                $upper = $midpoint;
                continue;
            }
            if ($midpointValue < $target) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return $sign * ($lower + (($upper - $lower) / 2.0));
    }

    public static function dawson(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('dawson() requires a finite argument.');
        }
        if ($value === 0.0) {
            return 0.0;
        }

        $absolute = \abs($value);
        if ($absolute < 8.0) {
            $square = $absolute * $absolute;
            $term = $absolute;
            $sum = $term;
            $compensation = 0.0;
            $converged = false;
            for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
                $term *= ($square / $index)
                    * (((2.0 * $index) - 1.0) / ((2.0 * $index) + 1.0));
                $adjustedTerm = $term - $compensation;
                $nextSum = $sum + $adjustedTerm;
                $compensation = ($nextSum - $sum) - $adjustedTerm;
                $sum = $nextSum;
                if ($index > $square && $term <= $sum * self::CONVERGENCE_TOLERANCE) {
                    $converged = true;
                    break;
                }
            }

            $result = \exp(-$square) * $sum;
            if (!$converged || !\is_finite($result)) {
                throw new \DomainException('dawson() could not produce a finite converged result.');
            }
        } else {
            $square = $absolute * $absolute;
            $term = 0.5 / $absolute;
            $sum = $term;
            $compensation = 0.0;
            for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
                $nextTerm = $term * (((2.0 * $index) - 1.0) / (2.0 * $square));
                if ($nextTerm >= $term || $nextTerm === 0.0) {
                    break;
                }

                $term = $nextTerm;
                $adjustedTerm = $term - $compensation;
                $nextSum = $sum + $adjustedTerm;
                $compensation = ($nextSum - $sum) - $adjustedTerm;
                $sum = $nextSum;
                if ($term <= $sum * self::CONVERGENCE_TOLERANCE) {
                    break;
                }
            }
            $result = $sum;
        }

        return $value < 0.0 ? -$result : $result;
    }

    /** @return list<float> Real preimages, in ascending order. */
    public static function inverseDawson(float $value): array
    {
        if (!\is_finite($value)) {
            throw new \DomainException('Dawson inverse requires a finite argument.');
        }
        if ($value === 0.0) {
            return [0.0];
        }

        $sign = $value < 0.0 ? -1.0 : 1.0;
        $target = \abs($value);
        $maximumArgument = self::dawsonMaximumArgument();
        $maximumValue = self::dawson($maximumArgument);
        if ($target > $maximumValue) {
            return [];
        }
        if ($target === $maximumValue) {
            return [$sign * $maximumArgument];
        }

        $lowerBranch = $target < 1.0E-4
            ? $target + ((2.0 / 3.0) * ($target ** 3)) + ((16.0 / 15.0) * ($target ** 5))
            : self::invertDawsonOnInterval($target, 0.0, $maximumArgument, true);

        if ($target < self::dawson(\PHP_FLOAT_MAX)) {
            throw new \DomainException('Dawson inverse exceeds the finite floating-point range.');
        }
        $upper = 2.0 * $maximumArgument;
        while (self::dawson($upper) > $target) {
            $upper *= 2.0;
            if (!\is_finite($upper)) {
                throw new \DomainException('Dawson inverse exceeds the finite floating-point range.');
            }
        }
        $upperBranch = self::invertDawsonOnInterval($target, $maximumArgument, $upper, false);
        $positiveRoots = [$lowerBranch, $upperBranch];
        if ($sign < 0.0) {
            return [-$positiveRoots[1], -$positiveRoots[0]];
        }

        return $positiveRoots;
    }

    private static function dawsonMaximumArgument(): float
    {
        $lower = 0.0;
        $upper = 1.0;
        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            if (1.0 - (2.0 * $midpoint * self::dawson($midpoint)) > 0.0) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return $lower + (($upper - $lower) / 2.0);
    }

    private static function invertDawsonOnInterval(
        float $target,
        float $lower,
        float $upper,
        bool $increasing,
    ): float {
        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            $midpointValue = self::dawson($midpoint);
            if (($midpointValue < $target) === $increasing) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return $lower + (($upper - $lower) / 2.0);
    }

    public static function erfc(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erfc() requires a finite argument.');
        }
        if ($value < 0.0) {
            return 2.0 - self::positiveErfc(-$value);
        }

        return self::positiveErfc($value);
    }

    public static function erfcx(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erfcx() requires a finite argument.');
        }

        if ($value < 0.0) {
            $square = $value * $value;
            $result = \exp($square) * (1.0 + self::erf(-$value));
            if (!\is_finite($result)) {
                throw new \DomainException('erfcx() has no finite result for this argument.');
            }

            return $result;
        }

        if ($value < 8.0) {
            return \exp($value * $value) * self::erfc($value);
        }

        return self::asymptoticErfcx($value)[0];
    }

    public static function inverseErfcx(float $value): float
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new \DomainException('erfcx inverse requires a finite positive argument.');
        }
        if ($value === 1.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            $lower = -1.0;
            $upper = 0.0;
            while (true) {
                try {
                    if (self::erfcx($lower) >= $value) {
                        break;
                    }
                } catch (\DomainException) {
                    break;
                }
                $lower *= 2.0;
                if (!\is_finite($lower)) {
                    throw new \DomainException('erfcx inverse could not bracket its finite result.');
                }
            }
        } else {
            $lower = 0.0;
            $upper = 1.0;
            while (self::erfcx($upper) > $value) {
                $lower = $upper;
                $upper *= 2.0;
                if (!\is_finite($upper) || $upper === $lower) {
                    throw new \DomainException('erfcx inverse result is outside the finite floating-point range.');
                }
            }
        }

        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            try {
                $midpointValue = self::erfcx($midpoint);
            } catch (\DomainException) {
                $lower = $midpoint;
                continue;
            }
            if ($midpointValue > $value) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }

        return $lower + (($upper - $lower) / 2.0);
    }

    public static function erfcxDerivative(float $value): float
    {
        if (!\is_finite($value)) {
            throw new \DomainException('erfcx() requires a finite argument.');
        }
        if ($value >= 8.0) {
            return self::asymptoticErfcx($value)[1];
        }

        return (2.0 * $value * self::erfcx($value)) - (2.0 / \sqrt(\M_PI));
    }

    /** @return array{float, float} Value and derivative. */
    private static function asymptoticErfcx(float $value): array
    {
        $square = $value * $value;
        $term = 1.0 / $value;
        $sum = $term;
        $sumCompensation = 0.0;
        $derivativeTerm = -1.0 / $square;
        $derivativeSum = $derivativeTerm;
        $derivativeCompensation = 0.0;

        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $nextTerm = -$term * (((2.0 * $index) - 1.0) / (2.0 * $square));
            if (\abs($nextTerm) >= \abs($term) || $nextTerm === 0.0) {
                break;
            }
            $term = $nextTerm;
            $adjustedTerm = $term - $sumCompensation;
            $nextSum = $sum + $adjustedTerm;
            $sumCompensation = ($nextSum - $sum) - $adjustedTerm;
            $sum = $nextSum;

            $derivativeTerm = -((2.0 * $index) + 1.0) * ($term / $value);
            $adjustedDerivative = $derivativeTerm - $derivativeCompensation;
            $nextDerivativeSum = $derivativeSum + $adjustedDerivative;
            $derivativeCompensation = ($nextDerivativeSum - $derivativeSum) - $adjustedDerivative;
            $derivativeSum = $nextDerivativeSum;

            if (\abs($term) <= \abs($sum) * self::CONVERGENCE_TOLERANCE) {
                break;
            }
        }

        return [$sum / \sqrt(\M_PI), $derivativeSum / \sqrt(\M_PI)];
    }

    public static function inverseErf(float $value): float
    {
        if (!\is_finite($value) || \abs($value) >= 1.0) {
            throw new \DomainException('erfinv() requires a finite argument strictly between -1 and 1.');
        }
        if ($value === 0.0) {
            return 0.0;
        }

        $target = \abs($value);
        if ($target < 1.0E-8) {
            $square = $target * $target;
            $result = $target;
            $term = $target;
            for ($index = 1; $index < 8; ++$index) {
                $term *= $square * \M_PI * (2.0 * $index - 1.0) ** 2
                    / (4.0 * $index * (2.0 * $index + 1.0));
                $result += $term;
            }
            $result *= 0.5 * \sqrt(\M_PI);

            return $value < 0.0 ? -$result : $result;
        }
        $lower = 0.0;
        $upper = 1.0;
        while (self::erf($upper) < $target) {
            $upper *= 2.0;
            if ($upper > 32.0) {
                throw new \DomainException('erfinv() could not bracket its finite result.');
            }
        }
        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            if (self::erf($midpoint) < $target) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }
        $lowerError = \abs(self::erf($lower) - $target);
        $upperError = \abs(self::erf($upper) - $target);
        $result = $lowerError <= $upperError ? $lower : $upper;

        return $value < 0.0 ? -$result : $result;
    }

    public static function inverseErfc(float $value): float
    {
        if (!\is_finite($value) || $value <= 0.0 || $value >= 2.0) {
            throw new \DomainException('erfcinv() requires a finite argument strictly between 0 and 2.');
        }
        if ($value === 1.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return -self::inverseErfc(2.0 - $value);
        }

        $lower = 0.0;
        $upper = 1.0;
        while (self::erfc($upper) > $value && $upper < 32.0) {
            $upper *= 2.0;
        }
        for ($iteration = 0; $iteration < 128; ++$iteration) {
            $midpoint = $lower + (($upper - $lower) / 2.0);
            if ($midpoint === $lower || $midpoint === $upper) {
                break;
            }
            if (self::erfc($midpoint) > $value) {
                $lower = $midpoint;
            } else {
                $upper = $midpoint;
            }
        }
        $lowerError = \abs(self::erfc($lower) - $value);
        $upperError = \abs(self::erfc($upper) - $value);

        return $lowerError <= $upperError ? $lower : $upper;
    }

    public static function digamma(float $value): float
    {
        if (!\is_finite($value) || ($value <= 0.0 && \floor($value) === $value)) {
            throw new \DomainException('digamma() requires a finite non-pole argument.');
        }

        if ($value < 0.5) {
            $fraction = $value - \round($value);
            $angle = \M_PI * $fraction;
            $sine = \sin($angle);
            if ($sine === 0.0) {
                throw new \DomainException('digamma() is undefined at non-positive integers.');
            }

            return self::digamma(1.0 - $value)
                - (\M_PI * (\cos($angle) / $sine));
        }

        $result = 0.0;
        while ($value < 16.0) {
            $result -= 1.0 / $value;
            $value += 1.0;
        }

        $inverse = 1.0 / $value;
        $square = $inverse * $inverse;
        $result += \log($value) - (0.5 * $inverse)
            - ($square / 12.0)
            + (($square ** 2) / 120.0)
            - (($square ** 3) / 252.0)
            + (($square ** 4) / 240.0)
            - (5.0 * ($square ** 5) / 660.0);

        return $result;
    }

    public static function trigamma(float $value): float
    {
        if (!\is_finite($value) || ($value <= 0.0 && \floor($value) === $value)) {
            throw new \DomainException('trigamma() requires a finite non-pole argument.');
        }

        if ($value < 0.5) {
            $fraction = $value - \round($value);
            $sine = \sin(\M_PI * $fraction);
            if ($sine === 0.0) {
                throw new \DomainException('trigamma() is undefined at non-positive integers.');
            }

            return ((\M_PI / $sine) ** 2) - self::trigamma(1.0 - $value);
        }

        $result = 0.0;
        while ($value < 16.0) {
            $result += 1.0 / ($value * $value);
            $value += 1.0;
        }

        $inverse = 1.0 / $value;
        $square = $inverse * $inverse;
        $result += $inverse + (0.5 * $square)
            + ($square * $inverse / 6.0)
            - (($square ** 2) * $inverse / 30.0)
            + (($square ** 3) * $inverse / 42.0)
            - (($square ** 4) * $inverse / 30.0)
            + (5.0 * ($square ** 5) * $inverse / 66.0)
            - (691.0 * ($square ** 6) * $inverse / 2730.0);

        return $result;
    }

    public static function tetragamma(float $value): float
    {
        if (!\is_finite($value) || ($value <= 0.0 && \floor($value) === $value)) {
            throw new \DomainException('tetragamma() requires a finite non-pole argument.');
        }

        if ($value < 0.5) {
            $fraction = $value - \round($value);
            $angle = \M_PI * $fraction;
            $sine = \sin($angle);
            if ($sine === 0.0) {
                throw new \DomainException('tetragamma() is undefined at non-positive integers.');
            }

            $cosecant = 1.0 / $sine;
            $reflection = 2.0 * (\M_PI ** 3)
                * (\cos($angle) * $cosecant)
                * $cosecant
                * $cosecant;

            return self::tetragamma(1.0 - $value)
                - $reflection;
        }

        $result = 0.0;
        while ($value < 16.0) {
            $result -= 2.0 / ($value ** 3);
            $value += 1.0;
        }

        $inverse = 1.0 / $value;
        $square = $inverse * $inverse;
        $result -= ($square * (1.0 + $inverse + (0.5 * $square)))
            - (($square ** 3) / 6.0)
            + (($square ** 4) / 6.0)
            - (3.0 * ($square ** 5) / 10.0)
            + (5.0 * ($square ** 6) / 6.0)
            - (691.0 * ($square ** 7) / 210.0);

        return $result;
    }

    public static function polygamma(int $order, float $value): float
    {
        if (
            $order < 0
            || $order > self::MAX_POLYGAMMA_ORDER
            || !\is_finite($value)
            || ($value <= 0.0 && \floor($value) === $value)
        ) {
            throw new \DomainException(\sprintf(
                'polygamma() requires an integer order from 0 through %d and a finite non-pole argument.',
                self::MAX_POLYGAMMA_ORDER,
            ));
        }
        if ($order === 0) {
            return self::digamma($value);
        }
        if ($order === 1) {
            return self::trigamma($value);
        }
        if ($order === 2) {
            return self::tetragamma($value);
        }

        if ($value < 0.5) {
            $fraction = $value - \round($value);
            $sine = \sin(\M_PI * $fraction);
            if ($sine === 0.0) {
                throw new \DomainException('polygamma() is undefined at non-positive integers.');
            }
            $cotangent = \cos(\M_PI * $fraction) / $sine;
            $reflection = (\M_PI ** ($order + 1))
                * self::cotangentDerivativePolynomial($order, $cotangent);

            return (((($order & 1) === 0) ? 1.0 : -1.0) * self::polygamma($order, 1.0 - $value))
                - $reflection;
        }

        $factorial = 1.0;
        for ($factor = 2; $factor <= $order; ++$factor) {
            $factorial *= $factor;
        }
        $sign = ($order & 1) === 0 ? -1.0 : 1.0;
        $result = 0.0;
        while ($value < 16.0) {
            $term = $factorial / ($value ** ($order + 1));
            if (!\is_finite($term)) {
                throw new \DomainException('polygamma() produced a non-finite result.');
            }
            $result += $sign * $term;
            $value += 1.0;
        }

        $tail = self::hurwitzZetaTail($order + 1, $value);
        $result += $sign * $factorial * $tail;

        if (!\is_finite($result)) {
            throw new \DomainException('polygamma() produced a non-finite result.');
        }

        return $result;
    }

    private static function hurwitzZetaTail(int $power, float $value): float
    {
        $result = ($value ** (1 - $power)) / ($power - 1)
            + (0.5 * ($value ** -$power));
        $bernoulliTerms = [
            1.0 / 12.0,
            -1.0 / 720.0,
            1.0 / 30240.0,
            -1.0 / 1209600.0,
            1.0 / 47900160.0,
            -691.0 / 1307674368000.0,
            7.0 / 523069747200.0,
            -3617.0 / 10670622842880000.0,
        ];

        foreach ($bernoulliTerms as $index => $bernoulli) {
            $rising = 1.0;
            for ($factor = 0; $factor < (2 * $index) + 1; ++$factor) {
                $rising *= $power + $factor;
            }
            $term = $bernoulli * $rising
                * ($value ** (-$power - (2 * $index) - 1));
            $result += $term;
            if (\abs($term) <= \abs($result) * self::CONVERGENCE_TOLERANCE) {
                break;
            }
        }

        return $result;
    }

    private static function cotangentDerivativePolynomial(int $order, float $cotangent): float
    {
        $polynomial = [0.0, 1.0];
        for ($derivative = 0; $derivative < $order; ++$derivative) {
            $next = \array_fill(0, \count($polynomial) + 1, 0.0);
            foreach ($polynomial as $degree => $coefficient) {
                if ($degree === 0) {
                    continue;
                }
                $next[$degree - 1] -= $degree * $coefficient;
                $next[$degree + 1] -= $degree * $coefficient;
            }
            $polynomial = $next;
        }

        $result = 0.0;
        for ($degree = \count($polynomial) - 1; $degree >= 0; --$degree) {
            $result = ($result * $cotangent) + $polynomial[$degree];
        }

        return $result;
    }

    private static function positiveErfc(float $value): float
    {
        if ($value < 0.5) {
            return 1.0 - self::erf($value);
        }

        $square = $value * $value;
        if (!\is_finite($square) || $square > 745.0) {
            return 0.0;
        }

        $shape = 0.5;
        $logFactor = (-$square) + ($shape * \log($square)) - (0.5 * \log(\M_PI));
        if ($square < $shape + 1.0) {
            $term = 1.0 / $shape;
            $sum = $term;
            $incrementedShape = $shape;
            for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
                ++$incrementedShape;
                $term *= $square / $incrementedShape;
                $sum += $term;
                if (\abs($term) <= \abs($sum) * self::CONVERGENCE_TOLERANCE) {
                    break;
                }
            }
            $regularizedLowerGamma = $sum * \exp($logFactor);

            return \max(0.0, 1.0 - $regularizedLowerGamma);
        }

        $tiny = 1.0E-300;
        $b = $square + 1.0 - $shape;
        $c = 1.0 / $tiny;
        $d = 1.0 / $b;
        $fraction = $d;
        for ($index = 1; $index <= self::MAX_ITERATIONS; ++$index) {
            $coefficient = -$index * ($index - $shape);
            $b += 2.0;
            $d = ($coefficient * $d) + $b;
            if (\abs($d) < $tiny) {
                $d = $tiny;
            }
            $c = $b + ($coefficient / $c);
            if (\abs($c) < $tiny) {
                $c = $tiny;
            }
            $d = 1.0 / $d;
            $delta = $d * $c;
            $fraction *= $delta;
            if (\abs($delta - 1.0) <= self::CONVERGENCE_TOLERANCE) {
                break;
            }
        }

        return \max(0.0, \exp($logFactor) * $fraction);
    }
}
