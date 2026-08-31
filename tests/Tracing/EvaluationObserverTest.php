<?php

declare(strict_types=1);

namespace MathPHP\Tests\Tracing;

use MathPHP\Ast\Node;
use MathPHP\Math;
use MathPHP\Tracing\EvaluationObserver;
use PHPUnit\Framework\TestCase;

final class EvaluationObserverTest extends TestCase
{
    public function testDefaultEvaluationDoesNotRequireAnObserver(): void
    {
        self::assertSame(20, Math::evaluate('(5*2)*2'));
    }

    public function testObserverCanBePassedWithoutVariables(): void
    {
        $observer = new class implements EvaluationObserver {
            public function evaluated(Node $node, int|float $result, int $depth): void
            {
            }
        };

        self::assertSame(20, Math::evaluateWithObserver('(5*2)*2', $observer));
    }

    public function testObserverIsStillRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Math::evaluateWithObserver('1');
    }

    public function testObserverReceivesDeterministicPostOrderWithDepth(): void
    {
        $events = [];
        $observer = new class ($events) implements EvaluationObserver {
            /** @var list<array{expression: string, result: int|float, depth: int}> */
            private array $events;

            /** @param list<array{expression: string, result: int|float, depth: int}> $events */
            public function __construct(array &$events)
            {
                $this->events =& $events;
            }

            public function evaluated(Node $node, int|float $result, int $depth): void
            {
                $this->events[] = [
                    'expression' => $node instanceof \MathPHP\Ast\NumberNode
                        ? $node->literal
                        : 'span:' . $node->span()->start . '-' . $node->span()->end,
                    'result' => $result,
                    'depth' => $depth,
                ];
            }

            /** @return list<array{expression: string, result: int|float, depth: int}> */
            public function events(): array
            {
                return $this->events;
            }
        };

        self::assertSame(20, Math::evaluateWithObserver('(5*2)*2', [], $observer));
        $recordedEvents = $observer->events();
        self::assertSame(
            [5, 2, 10, 10, 2, 20],
            array_column($recordedEvents, 'result'),
        );
        self::assertSame(0, $recordedEvents[5]['depth']);
    }
}
