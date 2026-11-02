<?php

declare(strict_types=1);

namespace MathPHP\Solver;

final class NumericalDifferentiator
{
    /** @param callable(float): ?float $function */
    public function differentiate(callable $function, float $point): ?float
    {
        if (!\is_finite($point)) {
            return null;
        }
        $step = (\PHP_FLOAT_EPSILON ** (1.0 / 5.0)) * \max(1.0, \abs($point));
        $previousRawDerivative = null;
        $previousEstimate = null;
        $previousStep = null;
        $previousMethod = null;
        $previousScale = 0.0;
        $bestDerivative = null;
        $bestDifference = \INF;
        $increasingOneSidedMagnitudeCount = 0;

        for ($iteration = 0; $iteration < 48; ++$iteration) {
            $estimate = $this->differentiateAtStep($function, $point, $step);
            if ($estimate !== null) {
                [$derivative, $method, $scale] = $estimate;
                $extrapolated = $derivative;
                if ($previousRawDerivative !== null && $previousStep !== null && $previousMethod === $method) {
                    $ratio = $step / $previousStep;
                    $ratioFourthPower = $ratio ** 4;
                    if ($ratioFourthPower < 1.0) {
                        $extrapolated = $this->finiteResult(
                            ($derivative - ($ratioFourthPower * $previousRawDerivative))
                                / (1.0 - $ratioFourthPower),
                        ) ?? $derivative;
                    }
                }

                if ($bestDerivative === null) {
                    $bestDerivative = $extrapolated;
                }
                if ($previousEstimate !== null) {
                    if (
                        $method !== 'central'
                        && $method === $previousMethod
                        && (($extrapolated < 0.0) === ($previousEstimate < 0.0))
                        && \abs($extrapolated)
                            > \abs($previousEstimate) * (1.0 + 8.0 * \sqrt(\PHP_FLOAT_EPSILON))
                    ) {
                        ++$increasingOneSidedMagnitudeCount;
                        if ($increasingOneSidedMagnitudeCount >= 5) {
                            return null;
                        }
                    } else {
                        $increasingOneSidedMagnitudeCount = 0;
                    }

                    $difference = \abs($extrapolated - $previousEstimate);
                    $scale = \max($scale, $previousScale, \abs($extrapolated), \abs($previousEstimate));
                    if ($difference < $bestDifference) {
                        $bestDifference = $difference;
                        $bestDerivative = $extrapolated;
                    }
                    if ($difference <= 8.0 * \sqrt(\PHP_FLOAT_EPSILON) * $scale) {
                        return $extrapolated;
                    }
                }

                $previousRawDerivative = $derivative;
                $previousEstimate = $extrapolated;
                $previousStep = $step;
                $previousMethod = $method;
                $previousScale = $scale;
            }

            $nextStep = $step * ($iteration % 2 === 0 ? 0.5 : 0.731);
            if ($nextStep === 0.0 || $point + $nextStep === $point) {
                break;
            }
            $step = $nextStep;
        }

        return $bestDerivative;
    }

    /**
     * @param callable(float): ?float $function
     * @return array{float, 'central'|'forward'|'backward', float}|null
     */
    private function differentiateAtStep(
        callable $function,
        float $point,
        float $step,
    ): ?array {
        $sample = static function (float $offset) use ($function, $point): ?float {
            $position = $point + $offset;

            return \is_finite($position) ? $function($position) : null;
        };
        $center = $sample(0.0);
        if ($center === null) {
            return null;
        }

        $minusOne = $sample(-$step);
        $plusOne = $sample($step);
        $minusTwo = $sample(-2.0 * $step);
        $plusTwo = $sample(2.0 * $step);

        if (
            $minusTwo !== null
            && $minusOne !== null
            && $plusOne !== null
            && $plusTwo !== null
        ) {
            $derivative = $this->finiteResult(
                ($minusTwo - (8.0 * $minusOne) + (8.0 * $plusOne) - $plusTwo)
                    / (12.0 * $step),
            );
            return $derivative === null
                ? null
                : [
                    $derivative,
                    'central',
                    $this->sampleScale(
                        [$minusTwo, $minusOne, $plusOne, $plusTwo],
                        $center,
                        $step,
                    ),
                ];
        }

        $forward = [
            $sample(2.0 * $step),
            $sample(3.0 * $step),
            $sample(4.0 * $step),
        ];
        if (
            $plusOne !== null
            && $forward[0] !== null
            && $forward[1] !== null
            && $forward[2] !== null
        ) {
            $derivative = $this->finiteResult(
                (-25.0 * $center + (48.0 * $plusOne) - (36.0 * $forward[0])
                    + (16.0 * $forward[1]) - (3.0 * $forward[2]))
                    / (12.0 * $step),
            );
            return $derivative === null
                ? null
                : [$derivative, 'forward', $this->sampleScale([$plusOne, ...$forward], $center, $step)];
        }

        $backward = [
            $sample(-2.0 * $step),
            $sample(-3.0 * $step),
            $sample(-4.0 * $step),
        ];
        if (
            $minusOne !== null
            && $backward[0] !== null
            && $backward[1] !== null
            && $backward[2] !== null
        ) {
            $derivative = $this->finiteResult(
                (25.0 * $center - (48.0 * $minusOne) + (36.0 * $backward[0])
                    - (16.0 * $backward[1]) + (3.0 * $backward[2]))
                    / (12.0 * $step),
            );
            return $derivative === null
                ? null
                : [$derivative, 'backward', $this->sampleScale([$minusOne, ...$backward], $center, $step)];
        }

        return null;
    }

    private function finiteResult(float $value): ?float
    {
        return \is_finite($value) ? $value : null;
    }

    /** @param list<float|null> $samples */
    private function sampleScale(array $samples, float $center, float $step): float
    {
        $scale = 0.0;
        foreach ($samples as $sample) {
            if ($sample !== null) {
                $sampleScale = \abs(($sample - $center) / $step);
                $scale = \max($scale, \is_finite($sampleScale) ? $sampleScale : \PHP_FLOAT_MAX);
            }
        }

        return $scale;
    }
}
