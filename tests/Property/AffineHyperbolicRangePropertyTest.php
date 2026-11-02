<?php

declare(strict_types=1);

namespace MathPHP\Tests\Property;

use MathPHP\Math;
use PHPUnit\Framework\TestCase;

final class AffineHyperbolicRangePropertyTest extends TestCase
{
    public function testAffineHyperbolicRootCountsMatchExactRanges(): void
    {
        $cases = [
            'sinh' => [
                '-2' => 1,
                '-1/2' => 1,
                '0' => 1,
                '1/2' => 1,
                '2' => 1,
            ],
            'tanh' => [
                '-2' => 0,
                '-1' => 0,
                '-1/2' => 1,
                '0' => 1,
                '1/2' => 1,
                '1' => 0,
                '2' => 0,
            ],
            'csch' => [
                '-2' => 1,
                '-1/2' => 1,
                '0' => 0,
                '1/2' => 1,
                '2' => 1,
            ],
            'coth' => [
                '-2' => 1,
                '-1' => 0,
                '-1/2' => 0,
                '0' => 0,
                '1/2' => 0,
                '1' => 0,
                '2' => 1,
            ],
            'cosh' => [
                '0' => 0,
                '1' => 1,
                '2' => 2,
                '3' => 2,
            ],
            'sech' => [
                '-1' => 0,
                '0' => 0,
                '1/2' => 2,
                '1' => 1,
                '2' => 0,
            ],
        ];

        foreach ($cases as $function => $targets) {
            foreach ($targets as $target => $expectedCount) {
                foreach (['2*x + 1', '-3*x + 2'] as $phase) {
                    $equation = "{$function}({$phase}) = {$target}";

                    self::assertSame(
                        $expectedCount,
                        Math::countPolynomialRootsOnRealLine($equation, 'x'),
                        $equation,
                    );
                }
            }
        }
    }
}
