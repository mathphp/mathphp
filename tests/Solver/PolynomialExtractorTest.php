<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Configuration\EvaluationOptions;
use MathPHP\Function\FunctionDefinition;
use MathPHP\Function\FunctionRegistry;
use MathPHP\Parser\Lexer;
use MathPHP\Parser\Parser;
use MathPHP\Solver\PolynomialExtractor;
use MathPHP\Solver\ExactRationalPolynomialExtractor;
use MathPHP\Solver\IntegerPolynomialExtractor;
use PHPUnit\Framework\TestCase;

final class PolynomialExtractorTest extends TestCase
{
    public function testExactIntegerRatiosAvoidMantissaRounding(): void
    {
        $extractor = new IntegerPolynomialExtractor('', []);

        self::assertSame(-3.0, $extractor->ratio('-330', '110'));
        self::assertSame(3.0, $extractor->ratio('330', '110'));
    }

    public function testIntegerNthRootFloorIncludesExactUpperBoundary(): void
    {
        $extractor = new IntegerPolynomialExtractor('', []);

        self::assertSame('10', $extractor->integerNthRootFloor('1000', 3));
        self::assertSame('10', $extractor->integerNthRoot('1000', 3));
        self::assertSame('9', $extractor->integerNthRootFloor('999', 3));
        self::assertNull($extractor->integerNthRoot('999', 3));
    }

    public function testExactRationalReductionRetainsOriginalDenominator(): void
    {
        $extractor = new ExactRationalPolynomialExtractor('x', [], 256, new EvaluationOptions());

        self::assertSame(
            [
                [['0', '1'], ['1', '1']],
                [['1', '1']],
                [['0', '1'], ['1', '1']],
            ],
            $extractor->reducedRationalFunction(self::parse('x^2/x')),
        );
        self::assertSame(
            [
                [['0', '1'], ['1', '1']],
                [['1', '1']],
                [['-1', '1'], ['1', '1']],
            ],
            $extractor->reducedRationalFunction(self::parse('(x^2-1)/(x-1)-1')),
        );
        self::assertSame(
            [
                [['0', '1'], ['1', '1']],
                [['1', '1']],
                [['1', '1'], ['1', '1']],
            ],
            $extractor->reducedRationalFunction(self::parse('x*(x+1)/(x+1)')),
        );
    }

    public function testUnregisteredVariableCallExtractsAsPolynomialProduct(): void
    {
        $extractor = new PolynomialExtractor('x', [], new EvaluationOptions());

        self::assertSame([0.0, 2.0], $extractor->extract(self::parse('x(2)')));
        self::assertSame(
            [6.0, 4.0],
            $extractor->extractInAffineCoordinate(self::parse('x(2)'), 3.0, 2.0),
        );
    }

    public function testParameterCallExtractsAsConstantTimesPolynomial(): void
    {
        $extractor = new PolynomialExtractor(
            'x',
            ['scale' => 3],
            new EvaluationOptions(),
        );

        self::assertSame([3.0, 3.0], $extractor->extract(self::parse('scale(x + 1)')));
    }

    public function testImplicitProductParticipatesInRationalExtraction(): void
    {
        $extractor = new PolynomialExtractor('x', [], new EvaluationOptions());

        self::assertSame(
            [[0.0, 1.0], [-1.0, 1.0]],
            $extractor->extractRationalForm(self::parse('x(1)/(x - 1)')),
        );
    }

    public function testZeroPowerExtractsAsOneAndPreservesRationalBaseHoles(): void
    {
        $extractor = new PolynomialExtractor('x', [], new EvaluationOptions());

        self::assertSame([1.0], $extractor->extract(self::parse('x^0')));
        self::assertSame(
            [1.0],
            $extractor->extractInAffineCoordinate(self::parse('x^0'), 7.0, 3.0),
        );
        self::assertSame(
            [[-1.0, 1.0], [-1.0, 1.0]],
            $extractor->extractRationalForm(self::parse('(1/(x-1))^0')),
        );

        $exactExtractor = new ExactRationalPolynomialExtractor('x', []);
        self::assertSame(
            ['2', '1'],
            $exactExtractor->difference(self::parse('2*x^0+x'), self::parse('0')),
        );
    }

    public function testExactRationalPowerDifferenceHonorsDegreeBudgetBeforeExponentiation(): void
    {
        $extractor = new ExactRationalPolynomialExtractor('x', [], 8);
        $one = self::parse('1');

        self::assertNull($extractor->rationalPowerDifference($one, \PHP_INT_MAX, $one, 1));
        self::assertSame(
            [['0'], ['1']],
            $extractor->rationalPowerDifference($one, 16, $one, 1),
        );
        self::assertNull($extractor->rationalPowerDifference($one, 1, $one, -17));
    }

    public function testRegisteredFunctionNameIsNotTreatedAsImplicitMultiplication(): void
    {
        $function = new FunctionDefinition(
            'scale',
            1,
            1,
            static fn (array $arguments): float => 2.0 * $arguments[0],
        );
        $extractor = new PolynomialExtractor(
            'x',
            ['scale' => 3],
            new EvaluationOptions(functions: FunctionRegistry::defaults()->with($function)),
        );

        self::assertNull($extractor->extract(self::parse('scale(x + 1)')));
    }

    private static function parse(string $expression): \MathPHP\Ast\Node
    {
        return (new Parser((new Lexer($expression))->tokenize()))->parse();
    }
}
