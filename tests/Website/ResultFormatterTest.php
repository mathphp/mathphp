<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- load the website-only production class under test.

namespace MathPHP\Tests\Website;

use MathPHP\Website\ResultFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/website/ResultFormatter.php';

final class ResultFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function formattingCases(): iterable
    {
        yield 'large exponent with trailing zero' => [1.23e20, '1.23e+20'];
        yield 'small exponent with trailing zero' => [1.23e-20, '1.23e-20'];
        yield 'negative large exponent' => [-1.23e20, '-1.23e+20'];
        yield 'negative small exponent' => [-1.23e-20, '-1.23e-20'];
        yield 'exponent without trailing zero' => [1.23e21, '1.23e+21'];
        yield 'ordinary decimal' => [1.250, '1.25'];
        yield 'integral float' => [42.0, '42'];
        yield 'integer' => [42, '42'];
        yield 'zero' => [0.0, '0'];
    }

    #[DataProvider('formattingCases')]
    public function testPreservesMagnitudeAndReadableFormatting(int|float $result, string $expected): void
    {
        self::assertSame($expected, ResultFormatter::format($result));
    }
}
