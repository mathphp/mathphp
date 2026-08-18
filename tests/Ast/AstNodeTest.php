<?php

declare(strict_types=1);

namespace MathPHP\Tests\Ast;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\ConstantNode;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\UnaryOperator;
use MathPHP\Ast\VariableNode;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AstNodeTest extends TestCase
{
    public function testNumberNodePreservesItsTypedValueLiteralAndSpan(): void
    {
        $integer = new NumberNode(7, '007', new SourceSpan(2, 5));
        $float = new NumberNode(0.5, '.5', new SourceSpan(8, 10));

        self::assertSame(7, $integer->value);
        self::assertIsInt($integer->value);
        self::assertSame('007', $integer->literal);
        self::assertSpan($integer, 2, 5);
        self::assertSame(1, $integer->depth());

        self::assertSame(0.5, $float->value);
        self::assertIsFloat($float->value);
        self::assertSame('.5', $float->literal);
        self::assertSpan($float, 8, 10);
        self::assertSame(1, $float->depth());
    }

    #[DataProvider('nonFiniteNumberProvider')]
    public function testNumberNodeRejectsNonFiniteValues(float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be finite');

        new NumberNode($value, 'not-finite', new SourceSpan(0, 10));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteNumberProvider(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'NaN' => [\NAN];
    }

    public function testNamedLeafNodesHaveDepthOneAndPreserveTheirSpans(): void
    {
        $constant = new ConstantNode('pi', new SourceSpan(1, 3));
        $variable = new VariableNode('_value2', new SourceSpan(5, 12));

        self::assertSame('pi', $constant->name);
        self::assertSpan($constant, 1, 3);
        self::assertSame(1, $constant->depth());

        self::assertSame('_value2', $variable->name);
        self::assertSpan($variable, 5, 12);
        self::assertSame(1, $variable->depth());
    }

    public function testUnaryNodeCoversItsOperatorAndOperand(): void
    {
        $operand = new NumberNode(2, '2', new SourceSpan(4, 5));
        $operatorSpan = new SourceSpan(2, 3);
        $node = new UnaryOperationNode(
            UnaryOperator::Minus,
            $operand,
            $operatorSpan,
        );

        self::assertSame(UnaryOperator::Minus, $node->operator);
        self::assertSame($operand, $node->operand);
        self::assertSame($operatorSpan, $node->operatorSpan);
        self::assertSpan($node, 2, 5);
        self::assertSame(2, $node->depth());
    }

    public function testBinaryNodeCoversBothOperandsAndItsOperator(): void
    {
        $left = new NumberNode(2, '2', new SourceSpan(2, 3));
        $rightLeaf = new NumberNode(3, '3', new SourceSpan(6, 7));
        $right = new UnaryOperationNode(
            UnaryOperator::Minus,
            $rightLeaf,
            new SourceSpan(5, 6),
        );
        $operatorSpan = new SourceSpan(3, 4);
        $node = new BinaryOperationNode(
            BinaryOperator::Power,
            $left,
            $right,
            $operatorSpan,
        );

        self::assertSame(BinaryOperator::Power, $node->operator);
        self::assertSame($left, $node->left);
        self::assertSame($right, $node->right);
        self::assertSame($operatorSpan, $node->operatorSpan);
        self::assertSpan($node, 2, 7);
        self::assertSame(3, $node->depth());
    }

    public function testFactorialNodeCoversItsOperandAndOperator(): void
    {
        $operand = new NumberNode(3, '3', new SourceSpan(4, 5));
        $operatorSpan = new SourceSpan(5, 6);
        $node = new FactorialNode($operand, $operatorSpan);

        self::assertSame($operand, $node->operand);
        self::assertSame($operatorSpan, $node->operatorSpan);
        self::assertSpan($node, 4, 6);
        self::assertSame(2, $node->depth());
    }

    public function testGroupingNodeHasOneMoreDepthThanItsChild(): void
    {
        $child = new UnaryOperationNode(
            UnaryOperator::Minus,
            new NumberNode(2, '2', new SourceSpan(2, 3)),
            new SourceSpan(1, 2),
        );
        $node = new GroupingNode($child, new SourceSpan(0, 4));

        self::assertSame($child, $node->expression);
        self::assertSpan($node, 0, 4);
        self::assertSame(3, $node->depth());
    }

    public function testFunctionCallCopiesArgumentsAndUsesDeepestArgument(): void
    {
        $first = new NumberNode(1, '1', new SourceSpan(4, 5));
        $deepest = new GroupingNode(
            new UnaryOperationNode(
                UnaryOperator::Minus,
                new NumberNode(2, '2', new SourceSpan(9, 10)),
                new SourceSpan(8, 9),
            ),
            new SourceSpan(7, 11),
        );
        $arguments = [$first, $deepest];
        $nameSpan = new SourceSpan(0, 3);
        $node = new FunctionCallNode(
            'foo',
            $arguments,
            $nameSpan,
            new SourceSpan(0, 12),
        );

        $arguments[] = new NumberNode(9, '9', new SourceSpan(20, 21));

        self::assertSame('foo', $node->name);
        self::assertSame($nameSpan, $node->nameSpan);
        self::assertSame([$first, $deepest], $node->arguments);
        self::assertSpan($node, 0, 12);
        self::assertSame(4, $node->depth());
    }

    public function testZeroArgumentFunctionCallHasDepthOne(): void
    {
        $node = new FunctionCallNode(
            'now',
            [],
            new SourceSpan(2, 5),
            new SourceSpan(2, 7),
        );

        self::assertSame([], $node->arguments);
        self::assertSame(1, $node->depth());
        self::assertSpan($node, 2, 7);
    }

    /**
     * @param class-string<Node> $className
     */
    #[DataProvider('concreteNodeClassProvider')]
    public function testEveryConcreteNodeIsFinalAndReadonly(string $className): void
    {
        $reflection = new \ReflectionClass($className);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->implementsInterface(Node::class));
    }

    /**
     * @return iterable<string, array{class-string<Node>}>
     */
    public static function concreteNodeClassProvider(): iterable
    {
        yield 'number' => [NumberNode::class];
        yield 'constant' => [ConstantNode::class];
        yield 'variable' => [VariableNode::class];
        yield 'grouping' => [GroupingNode::class];
        yield 'unary operation' => [UnaryOperationNode::class];
        yield 'binary operation' => [BinaryOperationNode::class];
        yield 'factorial' => [FactorialNode::class];
        yield 'function call' => [FunctionCallNode::class];
    }

    public function testOperatorEnumsMapExactlyToLanguageTokens(): void
    {
        self::assertSame(
            ['+', '-', '*', '/', '%', '^'],
            \array_map(
                static fn (BinaryOperator $operator): string => $operator->value,
                BinaryOperator::cases(),
            ),
        );
        self::assertSame(
            ['+', '-'],
            \array_map(
                static fn (UnaryOperator $operator): string => $operator->value,
                UnaryOperator::cases(),
            ),
        );
    }

    private static function assertSpan(
        Node $node,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        self::assertSame($expectedStart, $node->span()->start);
        self::assertSame($expectedEnd, $node->span()->end);
    }
}
