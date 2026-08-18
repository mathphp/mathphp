<?php

declare(strict_types=1);

namespace MathPHP\Tests\Parser;

use MathPHP\Ast\BinaryOperationNode;
use MathPHP\Ast\BinaryOperator;
use MathPHP\Ast\ConstantNode;
use MathPHP\Ast\FactorialNode;
use MathPHP\Ast\FunctionCallNode;
use MathPHP\Ast\GroupingNode;
use MathPHP\Ast\Node;
use MathPHP\Ast\NumberNode;
use MathPHP\Ast\UnaryOperationNode;
use MathPHP\Ast\VariableNode;
use MathPHP\Configuration\ResourceLimits;
use MathPHP\Exception\LexicalException;
use MathPHP\Exception\MathException;
use MathPHP\Exception\ParseException;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Parser\Token;
use MathPHP\Parser\TokenType;
use MathPHP\Source\SourceSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    #[DataProvider('numericLiteralProvider')]
    public function testNumericLiteralSyntaxDeterminesTheExactPhpType(
        string $source,
        int|float $expectedValue,
        string $expectedType,
    ): void {
        $node = self::parseExpression($source);

        self::assertInstanceOf(NumberNode::class, $node);
        self::assertSame($source, $node->literal);
        self::assertSame($expectedValue, $node->value);
        self::assertSame($expectedType, \get_debug_type($node->value));
        self::assertSame(0, $node->span()->start);
        self::assertSame(\strlen($source), $node->span()->end);
    }

    /**
     * @return iterable<string, array{string, int|float, 'int'|'float'}>
     */
    public static function numericLiteralProvider(): iterable
    {
        yield 'zero integer' => ['0', 0, 'int'];
        yield 'leading zero integer' => ['007', 7, 'int'];
        yield 'integer' => ['42', 42, 'int'];
        yield 'host maximum integer' => [
            (string) \PHP_INT_MAX,
            \PHP_INT_MAX,
            'int',
        ];
        yield 'trailing decimal point' => ['1.', 1.0, 'float'];
        yield 'leading decimal point' => ['.5', 0.5, 'float'];
        yield 'fraction' => ['0.125', 0.125, 'float'];
        yield 'scientific notation' => ['1e3', 1000.0, 'float'];
        yield 'negative exponent' => ['1E-3', 0.001, 'float'];
        yield 'signed exponent' => ['.5e+2', 50.0, 'float'];
    }

    public function testConstantsAndVariablesAreDistinguishedCaseSensitively(): void
    {
        $pi = self::parseExpression('pi');
        $e = self::parseExpression('e');
        $capitalPi = self::parseExpression('Pi');
        $capitalE = self::parseExpression('E');
        $variable = self::parseExpression('_value2');

        self::assertInstanceOf(ConstantNode::class, $pi);
        self::assertSame('pi', $pi->name);
        self::assertInstanceOf(ConstantNode::class, $e);
        self::assertSame('e', $e->name);

        self::assertInstanceOf(VariableNode::class, $capitalPi);
        self::assertSame('Pi', $capitalPi->name);
        self::assertInstanceOf(VariableNode::class, $capitalE);
        self::assertSame('E', $capitalE->name);
        self::assertInstanceOf(VariableNode::class, $variable);
        self::assertSame('_value2', $variable->name);
    }

    public function testIdentifierFollowedByParenthesisAcrossWhitespaceIsACall(): void
    {
        $node = self::parseExpression('f (1, x + pi)');

        self::assertInstanceOf(FunctionCallNode::class, $node);
        self::assertSame(
            [
                'call',
                'f',
                [
                    ['number', '1', 1],
                    [
                        'binary',
                        '+',
                        ['variable', 'x'],
                        ['constant', 'pi'],
                    ],
                ],
            ],
            self::shape($node),
        );
        self::assertSame(0, $node->nameSpan->start);
        self::assertSame(1, $node->nameSpan->end);
        self::assertSame(0, $node->span()->start);
        self::assertSame(13, $node->span()->end);
    }

    public function testZeroArgumentCallAndNestedCallsAreRepresentedExplicitly(): void
    {
        $node = self::parseExpression('outer(1, inner(), 2 + 3)');

        self::assertSame(
            [
                'call',
                'outer',
                [
                    ['number', '1', 1],
                    ['call', 'inner', []],
                    [
                        'binary',
                        '+',
                        ['number', '2', 2],
                        ['number', '3', 3],
                    ],
                ],
            ],
            self::shape($node),
        );
        self::assertSame(3, $node->depth());
    }

    #[DataProvider('binaryOperatorProvider')]
    public function testEveryBinaryGrammarOperatorBuildsItsTypedNode(
        string $source,
        BinaryOperator $expectedOperator,
    ): void {
        $node = self::parseExpression($source);

        self::assertInstanceOf(BinaryOperationNode::class, $node);
        self::assertSame($expectedOperator, $node->operator);
        self::assertSame(1, $node->operatorSpan->start);
        self::assertSame(2, $node->operatorSpan->end);
        self::assertSame(0, $node->span()->start);
        self::assertSame(3, $node->span()->end);
    }

    /**
     * @return iterable<string, array{string, BinaryOperator}>
     */
    public static function binaryOperatorProvider(): iterable
    {
        yield 'addition' => ['1+2', BinaryOperator::Add];
        yield 'subtraction' => ['1-2', BinaryOperator::Subtract];
        yield 'multiplication' => ['1*2', BinaryOperator::Multiply];
        yield 'division' => ['1/2', BinaryOperator::Divide];
        yield 'modulo' => ['1%2', BinaryOperator::Modulo];
        yield 'power' => ['1^2', BinaryOperator::Power];
    }

    public function testExponentiationIsRightAssociative(): void
    {
        $node = self::parseExpression('2^3^2');

        self::assertSame(
            [
                'binary',
                '^',
                ['number', '2', 2],
                [
                    'binary',
                    '^',
                    ['number', '3', 3],
                    ['number', '2', 2],
                ],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(BinaryOperationNode::class, $node);
        self::assertSame(1, $node->operatorSpan->start);
        self::assertInstanceOf(BinaryOperationNode::class, $node->right);
        self::assertSame(3, $node->right->operatorSpan->start);
        self::assertSame(3, $node->depth());
    }

    public function testUnaryMinusBindsLessTightlyThanPower(): void
    {
        $node = self::parseExpression('-2^2');

        self::assertSame(
            [
                'unary',
                '-',
                [
                    'binary',
                    '^',
                    ['number', '2', 2],
                    ['number', '2', 2],
                ],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(UnaryOperationNode::class, $node);
        self::assertSame(0, $node->operatorSpan->start);
        self::assertSame(0, $node->span()->start);
        self::assertSame(4, $node->span()->end);
    }

    public function testPowerAcceptsAUnarySignedExponent(): void
    {
        $node = self::parseExpression('2^-2');

        self::assertSame(
            [
                'binary',
                '^',
                ['number', '2', 2],
                ['unary', '-', ['number', '2', 2]],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(BinaryOperationNode::class, $node);
        self::assertInstanceOf(UnaryOperationNode::class, $node->right);
        self::assertSame(2, $node->right->operatorSpan->start);
    }

    public function testGroupingMakesANegativePowerBaseExplicit(): void
    {
        $node = self::parseExpression('(-2)^2');

        self::assertSame(
            [
                'binary',
                '^',
                ['group', ['unary', '-', ['number', '2', 2]]],
                ['number', '2', 2],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(BinaryOperationNode::class, $node);
        self::assertInstanceOf(GroupingNode::class, $node->left);
        self::assertSame(0, $node->left->span()->start);
        self::assertSame(4, $node->left->span()->end);
        self::assertSame(4, $node->operatorSpan->start);
    }

    public function testFactorialBindsMoreTightlyThanUnaryMinus(): void
    {
        $node = self::parseExpression('-3!');

        self::assertSame(
            [
                'unary',
                '-',
                ['factorial', ['number', '3', 3]],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(UnaryOperationNode::class, $node);
        self::assertInstanceOf(FactorialNode::class, $node->operand);
        self::assertSame(2, $node->operand->operatorSpan->start);
    }

    public function testGroupedNegativeFactorialKeepsTheGroupingAsOperand(): void
    {
        $node = self::parseExpression('(-3)!');

        self::assertSame(
            [
                'factorial',
                ['group', ['unary', '-', ['number', '3', 3]]],
            ],
            self::shape($node),
        );
        self::assertInstanceOf(FactorialNode::class, $node);
        self::assertInstanceOf(GroupingNode::class, $node->operand);
        self::assertSame(4, $node->operatorSpan->start);
        self::assertSame(0, $node->span()->start);
        self::assertSame(5, $node->span()->end);
    }

    public function testMultiplicationBindsMoreTightlyThanAddition(): void
    {
        self::assertSame(
            [
                'binary',
                '+',
                ['number', '1', 1],
                [
                    'binary',
                    '*',
                    ['number', '2', 2],
                    ['number', '3', 3],
                ],
            ],
            self::shape(self::parseExpression('1+2*3')),
        );
    }

    public function testUnarySignsBindMoreTightlyThanMultiplication(): void
    {
        self::assertSame(
            [
                'binary',
                '*',
                ['unary', '-', ['number', '2', 2]],
                ['unary', '+', ['number', '3', 3]],
            ],
            self::shape(self::parseExpression('-2*+3')),
        );
    }

    #[DataProvider('leftAssociativeOperatorProvider')]
    public function testNonPowerBinaryOperatorsAreLeftAssociative(
        string $source,
        string $operator,
    ): void {
        self::assertSame(
            [
                'binary',
                $operator,
                [
                    'binary',
                    $operator,
                    ['number', '8', 8],
                    ['number', '4', 4],
                ],
                ['number', '2', 2],
            ],
            self::shape(self::parseExpression($source)),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function leftAssociativeOperatorProvider(): iterable
    {
        yield 'addition' => ['8+4+2', '+'];
        yield 'subtraction' => ['8-4-2', '-'];
        yield 'multiplication' => ['8*4*2', '*'];
        yield 'division' => ['8/4/2', '/'];
        yield 'modulo' => ['8%4%2', '%'];
    }

    public function testCompositeSpanExcludesSurroundingWhitespace(): void
    {
        $node = self::parseExpression(' 1 + 2 ');

        self::assertInstanceOf(BinaryOperationNode::class, $node);
        self::assertSame(1, $node->span()->start);
        self::assertSame(6, $node->span()->end);
        self::assertSame(3, $node->operatorSpan->start);
        self::assertSame(4, $node->operatorSpan->end);
    }

    #[DataProvider('parseFailureProvider')]
    public function testMalformedGrammarReportsStableCodeAndExactSpan(
        string $source,
        string $expectedCode,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        self::assertParseError(
            $source,
            $expectedCode,
            $expectedStart,
            $expectedEnd,
        );
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function parseFailureProvider(): iterable
    {
        yield 'empty input' => ['', 'parse.empty_expression', 0, 0];
        yield 'whitespace input' => [" \t", 'parse.empty_expression', 2, 2];
        yield 'missing additive operand' => [
            '1+',
            'parse.expected_expression',
            2,
            2,
        ];
        yield 'missing prefix operand' => [
            '-',
            'parse.expected_expression',
            1,
            1,
        ];
        yield 'missing power operand' => [
            '2^',
            'parse.expected_expression',
            2,
            2,
        ];
        yield 'operator instead of operand' => [
            '1+*2',
            'parse.expected_expression',
            2,
            3,
        ];
        yield 'empty grouping' => [
            '()',
            'parse.expected_expression',
            1,
            2,
        ];
        yield 'opening delimiter only' => [
            '(',
            'parse.expected_expression',
            1,
            1,
        ];
        yield 'unclosed grouping' => [
            '(1',
            'parse.expected_token',
            2,
            2,
        ];
        yield 'unclosed populated call' => [
            'f(1',
            'parse.expected_token',
            3,
            3,
        ];
        yield 'unclosed empty call' => [
            'f(',
            'parse.expected_expression',
            2,
            2,
        ];
        yield 'empty first argument' => [
            'f(,1)',
            'parse.expected_expression',
            2,
            3,
        ];
        yield 'trailing comma' => [
            'f(1,)',
            'parse.expected_expression',
            4,
            5,
        ];
        yield 'empty middle argument' => [
            'f(1,,2)',
            'parse.expected_expression',
            4,
            5,
        ];
        yield 'missing comma between arguments' => [
            'f(1 2)',
            'parse.expected_token',
            4,
            5,
        ];
        yield 'stray leading comma' => [
            ',1',
            'parse.expected_expression',
            0,
            1,
        ];
        yield 'stray trailing comma' => [
            '1,',
            'parse.trailing_input',
            1,
            2,
        ];
        yield 'unmatched closing delimiter' => [
            '1)',
            'parse.trailing_input',
            1,
            2,
        ];
        yield 'leading closing delimiter' => [
            ')',
            'parse.expected_expression',
            0,
            1,
        ];
        yield 'repeated postfix factorial' => [
            '3!!',
            'parse.trailing_input',
            2,
            3,
        ];
        yield 'prefix factorial' => [
            '!3',
            'parse.expected_expression',
            0,
            1,
        ];
    }

    #[DataProvider('implicitMultiplicationProvider')]
    public function testFullConsumptionRejectsImplicitMultiplication(
        string $source,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        self::assertParseError(
            $source,
            'parse.trailing_input',
            $expectedStart,
            $expectedEnd,
        );
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function implicitMultiplicationProvider(): iterable
    {
        yield 'number then identifier' => ['2pi', 1, 3];
        yield 'number then grouping' => ['2(3)', 1, 2];
        yield 'adjacent groupings' => ['(2)(3)', 3, 4];
        yield 'adjacent numbers' => ['1 2', 2, 3];
        yield 'call then identifier' => ['f(1)g(2)', 4, 5];
    }

    #[DataProvider('deferredSyntaxProvider')]
    public function testDeferredSyntaxIsRejectedLexicallyAtItsFirstUnknownByte(
        string $source,
        int $expectedStart,
    ): void {
        try {
            self::parseExpression($source);
            self::fail('Expected deferred syntax to be rejected.');
        } catch (LexicalException $exception) {
            self::assertSame('lex.unknown_character', $exception->errorCode());
            self::assertSame($expectedStart, $exception->span()->start);
            self::assertSame($expectedStart + 1, $exception->span()->end);
            self::assertStringContainsString(
                \sprintf('position %d', $expectedStart),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function deferredSyntaxProvider(): iterable
    {
        yield 'assignment' => ['x=1', 1];
        yield 'comparison' => ['1<2', 1];
        yield 'logical and' => ['1&&2', 1];
        yield 'logical or' => ['1||2', 1];
        yield 'array syntax' => ['x[1]', 1];
        yield 'string syntax' => ['"x"', 0];
        yield 'comment syntax' => ['1#comment', 1];
        yield 'colon' => ['1:2', 1];
    }

    public function testParserRejectsAnEmptyTokenList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EOF-terminated token list');

        new Parser([]);
    }

    public function testParserRejectsATokenListWithoutFinalEof(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must end with EOF');

        new Parser([
            new Token(
                TokenType::Number,
                '1',
                new SourceSpan(0, 1),
            ),
        ]);
    }

    public function testDefaultNestingLimitAllowsExactly64OpenGroups(): void
    {
        $source = \str_repeat('(', 64)
            . '1'
            . \str_repeat(')', 64);
        $node = self::parseExpression($source);

        self::assertSame(65, $node->depth());
        self::assertSame(0, $node->span()->start);
        self::assertSame(129, $node->span()->end);
    }

    public function testDefaultNestingLimitRejectsThe65thOpeningGroup(): void
    {
        $source = \str_repeat('(', 65)
            . '1'
            . \str_repeat(')', 65);

        self::assertParseError(
            $source,
            'limit.nesting',
            64,
            65,
        );
    }

    public function testZeroNestingAllowsLeavesButRejectsGroupsAndCalls(): void
    {
        $limits = new ResourceLimits(maxNesting: 0);

        self::assertInstanceOf(
            NumberNode::class,
            self::parseExpression('1', $limits),
        );
        self::assertParseError('(1)', 'limit.nesting', 0, 1, $limits);
        self::assertParseError('f()', 'limit.nesting', 1, 2, $limits);
    }

    public function testDefaultAstDepthAllowsA128LevelUnaryChain(): void
    {
        $node = self::parseExpression(\str_repeat('-', 127) . '1');

        self::assertSame(128, $node->depth());
    }

    public function testDefaultAstDepthRejectsTheUnaryOperatorAtLevel129(): void
    {
        self::assertParseError(
            \str_repeat('-', 128) . '1',
            'limit.ast_depth',
            127,
            128,
        );
    }

    public function testDefaultAstDepthAllowsA128LevelPowerChain(): void
    {
        $node = self::parseExpression(self::powerChain(128));

        self::assertSame(128, $node->depth());
    }

    public function testDefaultAstDepthRejectsThePowerAtLevel129(): void
    {
        self::assertParseError(
            self::powerChain(129),
            'limit.ast_depth',
            255,
            256,
        );
    }

    public function testConfiguredAstDepthIsInclusiveForIterativeOperators(): void
    {
        $limits = new ResourceLimits(maxAstDepth: 3);
        $node = self::parseExpression('1+2+3', $limits);

        self::assertSame(3, $node->depth());
        self::assertParseError(
            '1+2+3+4',
            'limit.ast_depth',
            5,
            6,
            $limits,
        );
    }

    public function testDefaultFunctionArgumentLimitAllowsExactly16Arguments(): void
    {
        $node = self::parseExpression(self::callWithArguments(16));

        self::assertInstanceOf(FunctionCallNode::class, $node);
        self::assertCount(16, $node->arguments);
    }

    public function testDefaultFunctionArgumentLimitRejectsThe17thArgument(): void
    {
        self::assertParseError(
            self::callWithArguments(17),
            'limit.function_arguments',
            34,
            35,
        );
    }

    public function testZeroFunctionArgumentLimitAllowsOnlyEmptyCalls(): void
    {
        $limits = new ResourceLimits(maxFunctionArguments: 0);
        $node = self::parseExpression('f()', $limits);

        self::assertInstanceOf(FunctionCallNode::class, $node);
        self::assertSame([], $node->arguments);
        self::assertParseError(
            'f(1)',
            'limit.function_arguments',
            2,
            3,
            $limits,
        );
    }

    private static function parseExpression(
        string $source,
        ?ResourceLimits $limits = null,
    ): Node {
        $tokens = (new Lexer($source, $limits))->tokenize();

        return (new Parser($tokens, $limits))->parse();
    }

    /**
     * @return array<mixed>
     */
    private static function shape(Node $node): array
    {
        if ($node instanceof NumberNode) {
            return ['number', $node->literal, $node->value];
        }

        if ($node instanceof ConstantNode) {
            return ['constant', $node->name];
        }

        if ($node instanceof VariableNode) {
            return ['variable', $node->name];
        }

        if ($node instanceof GroupingNode) {
            return ['group', self::shape($node->expression)];
        }

        if ($node instanceof UnaryOperationNode) {
            return [
                'unary',
                $node->operator->value,
                self::shape($node->operand),
            ];
        }

        if ($node instanceof BinaryOperationNode) {
            return [
                'binary',
                $node->operator->value,
                self::shape($node->left),
                self::shape($node->right),
            ];
        }

        if ($node instanceof FactorialNode) {
            return ['factorial', self::shape($node->operand)];
        }

        if ($node instanceof FunctionCallNode) {
            return [
                'call',
                $node->name,
                \array_map(
                    self::shape(...),
                    $node->arguments,
                ),
            ];
        }

        throw new \LogicException(
            \sprintf('Unhandled AST node %s.', $node::class),
        );
    }

    private static function assertParseError(
        string $source,
        string $expectedCode,
        int $expectedStart,
        int $expectedEnd,
        ?ResourceLimits $limits = null,
    ): void {
        try {
            self::parseExpression($source, $limits);
            self::fail('Expected a parse exception.');
        } catch (ParseException $exception) {
            self::assertInstanceOf(MathException::class, $exception);
            self::assertSame($expectedCode, $exception->errorCode());
            self::assertSame($expectedStart, $exception->span()->start);
            self::assertSame($expectedEnd, $exception->span()->end);
            self::assertStringContainsString(
                \sprintf('position %d', $expectedStart),
                $exception->getMessage(),
            );
        }
    }

    private static function powerChain(int $terms): string
    {
        return \implode('^', \array_fill(0, $terms, '1'));
    }

    private static function callWithArguments(int $count): string
    {
        return 'f('
            . \implode(',', \array_fill(0, $count, '1'))
            . ')';
    }
}
