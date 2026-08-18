<?php

declare(strict_types=1);

namespace MathPHP\Tests\Source;

use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceSpanTest extends TestCase
{
    #[DataProvider('spanProvider')]
    public function testItStoresEndExclusiveByteOffsetsAndReportsLength(
        int $start,
        int $end,
        int $expectedLength,
    ): void {
        $span = new SourceSpan($start, $end);

        self::assertSame($start, $span->start);
        self::assertSame($end, $span->end);
        self::assertSame($expectedLength, $span->length());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function spanProvider(): iterable
    {
        yield 'empty span at start' => [0, 0, 0];
        yield 'one byte' => [0, 1, 1];
        yield 'empty EOF span' => [17, 17, 0];
        yield 'non-zero range' => [4, 11, 7];
    }

    #[DataProvider('coverProvider')]
    public function testCoverReturnsTheSmallestSpanContainingBothInputs(
        SourceSpan $left,
        SourceSpan $right,
        SourceSpan $expected,
    ): void {
        $covered = $left->cover($right);

        self::assertNotSame($left, $covered);
        self::assertNotSame($right, $covered);
        self::assertSame($expected->start, $covered->start);
        self::assertSame($expected->end, $covered->end);

        $reverse = $right->cover($left);

        self::assertSame($covered->start, $reverse->start);
        self::assertSame($covered->end, $reverse->end);
    }

    /**
     * @return iterable<string, array{SourceSpan, SourceSpan, SourceSpan}>
     */
    public static function coverProvider(): iterable
    {
        yield 'disjoint spans' => [
            new SourceSpan(2, 4),
            new SourceSpan(7, 9),
            new SourceSpan(2, 9),
        ];
        yield 'overlapping spans' => [
            new SourceSpan(2, 8),
            new SourceSpan(5, 11),
            new SourceSpan(2, 11),
        ];
        yield 'contained span' => [
            new SourceSpan(2, 11),
            new SourceSpan(4, 7),
            new SourceSpan(2, 11),
        ];
        yield 'empty boundary span' => [
            new SourceSpan(3, 3),
            new SourceSpan(3, 8),
            new SourceSpan(3, 8),
        ];
    }

    public function testItRejectsANegativeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start must be non-negative');

        new SourceSpan(-1, 0);
    }

    public function testItRejectsAnEndBeforeTheStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end must not precede');

        new SourceSpan(3, 2);
    }

    public function testItIsAFinalReadonlyValueObject(): void
    {
        $reflection = new \ReflectionClass(SourceSpan::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
