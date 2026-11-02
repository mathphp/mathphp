<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Function\SpecialFunctions;
use MathPHP\Solver\InfiniteSolutionSetException;
use PHPUnit\Framework\TestCase;

final class ComplexRationalEquationSolverTest extends TestCase
{
    public function testSolvesRationalEquationOverComplexNumbers(): void
    {
        $solution = Math::solveRationalComplex('1/x=1', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertSame(1.0, $solution->roots[0]->real);
        self::assertSame(0.0, $solution->roots[0]->imaginary);

        $noRoots = Math::solveRationalComplex('1/x=0', 'x');
        self::assertTrue($noRoots->converged);
        self::assertSame([], $noRoots->roots);

        $complexRoots = Math::solveRationalComplex('(x^2+1)/(x-1)=0', 'x');
        self::assertTrue($complexRoots->converged);
        self::assertCount(2, $complexRoots->roots);
        self::assertEqualsWithDelta(0.0, $complexRoots->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $complexRoots->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $complexRoots->roots[1]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $complexRoots->roots[1]->imaginary, 1.0E-10);
    }

    public function testRepeatedComplexRootsRetainMultiplicityAndCanceledHolesStayExcluded(): void
    {
        $repeatedRoots = Math::solveRationalComplex('(x^2 + 1)^2/(x - 2) = 0', 'x');

        self::assertTrue($repeatedRoots->converged);
        self::assertCount(4, $repeatedRoots->roots);
        self::assertSame([0.0, 0.0, 0.0, 0.0], \array_map(
            static fn ($root): float => $root->real,
            $repeatedRoots->roots,
        ));
        self::assertSame([-1.0, -1.0, 1.0, 1.0], \array_map(
            static fn ($root): float => $root->imaginary,
            $repeatedRoots->roots,
        ));

        $canceledHole = Math::solveRationalComplex('(x - 1)^2*(x^2 + 1)/(x - 1) = 0', 'x');

        self::assertTrue($canceledHole->converged);
        self::assertCount(2, $canceledHole->roots);
        self::assertEqualsCanonicalizing(
            [[0.0, -1.0], [0.0, 1.0]],
            \array_map(
                static fn ($root): array => [$root->real, $root->imaginary],
                $canceledHole->roots,
            ),
        );
    }

    public function testSolvesRationalHypotenuseEquationsAndExcludesOriginalHoles(): void
    {
        $solution = Math::solveRationalComplex('hypot(1/(x-1),0)=1', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(2, $solution->roots);
        self::assertSame(0.0, $solution->roots[0]->real);
        self::assertSame(2.0, $solution->roots[1]->real);

        $reversed = Math::solveRationalComplex('1=hypot(1/(x-1),0)', 'x');
        self::assertSame(
            \array_map(
                static fn ($root): float => $root->real,
                $solution->roots,
            ),
            \array_map(
                static fn ($root): float => $root->real,
                $reversed->roots,
            ),
        );

        $holeOnlyRoot = Math::solveRationalComplex('hypot((x-1)/(x-1),x-1)=1', 'x');
        self::assertTrue($holeOnlyRoot->converged);
        self::assertSame([], $holeOnlyRoot->roots);
    }

    public function testSolvesAbsoluteValuesOfRationalFunctionsOverTheRealDomain(): void
    {
        $solution = Math::solveRationalComplex('abs(1/(x-1))=1', 'x');
        self::assertSame([0.0, 2.0], \array_map(
            static fn ($root): float => $root->real,
            $solution->roots,
        ));

        $reversed = Math::solveRationalComplex('1=abs(1/(x-1))', 'x');
        self::assertSame([0.0, 2.0], \array_map(
            static fn ($root): float => $root->real,
            $reversed->roots,
        ));

        self::assertSame([], Math::solveRationalComplex('abs(1/(x-1))=-1', 'x')->roots);
        self::assertSame([0.0], \array_map(
            static fn ($root): float => $root->real,
            Math::solveRationalComplex('abs(x^2+1)=1', 'x')->roots,
        ));
        self::assertSame(
            [-0.000000000001, 0.000000000001],
            \array_map(
                static fn ($root): float => $root->real,
                Math::solveRationalComplex('abs(x)=0.000000000001', 'x')->roots,
            ),
        );
        self::assertSame([0.0], \array_map(
            static fn ($root): float => $root->real,
            Math::solveRationalComplex(
                'abs(x^2+0.0000000000000000000001)=0.0000000000000000000001',
                'x',
            )->roots,
        ));
        self::assertSame(
            [],
            Math::solveRationalComplex('abs(((x-1)/(x-1))*(x-1))=0', 'x')->roots,
        );
    }

    public function testSolvesLogarithmsOfRationalFunctionsAndPreservesHoles(): void
    {
        $naturalLogarithm = Math::solveRationalComplex('ln(1/(x-1))=1', 'x');
        self::assertCount(1, $naturalLogarithm->roots);
        self::assertEqualsWithDelta(1.0 + (1.0 / \M_E), $naturalLogarithm->roots[0]->real, 1.0E-12);

        $commonLogarithm = Math::solveRationalComplex(
            'log10((x-1)/(x-2))=1',
            'x',
        );
        self::assertCount(1, $commonLogarithm->roots);
        self::assertEqualsWithDelta(19.0 / 9.0, $commonLogarithm->roots[0]->real, 1.0E-12);

        $basedLogarithm = Math::solveRationalComplex(
            'log((x-1)/(x-2),2)=3',
            'x',
        );
        self::assertCount(1, $basedLogarithm->roots);
        self::assertEqualsWithDelta(15.0 / 7.0, $basedLogarithm->roots[0]->real, 1.0E-12);

        $variableBase = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=2',
            'x',
        );
        self::assertCount(1, $variableBase->roots);
        self::assertEqualsWithDelta(3.0, $variableBase->roots[0]->real, 1.0E-12);

        $negativeLevel = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=-2',
            'x',
        );
        self::assertCount(1, $negativeLevel->roots);
        self::assertSame(0.0, $negativeLevel->roots[0]->real);

        $fractionalLevel = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=1/2',
            'x',
        );
        self::assertCount(1, $fractionalLevel->roots);
        self::assertEqualsWithDelta(31.0 / 15.0, $fractionalLevel->roots[0]->real, 1.0E-12);

        $radicalFractionalLevel = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=sqrt(1/4)',
            'x',
        );
        self::assertCount(1, $radicalFractionalLevel->roots);
        self::assertEqualsWithDelta(31.0 / 15.0, $radicalFractionalLevel->roots[0]->real, 1.0E-12);

        $negativeFractionalLevel = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=-1/2',
            'x',
        );
        self::assertCount(1, $negativeFractionalLevel->roots);
        self::assertEqualsWithDelta(14.0 / 15.0, $negativeFractionalLevel->roots[0]->real, 1.0E-12);

        $parameterLevel = Math::solveRationalComplex(
            'log(4,(x-1)/(x-2))=q',
            'x',
            ['q' => 1.0 / 3.0],
        );
        self::assertCount(1, $parameterLevel->roots);
        self::assertEqualsWithDelta(127.0 / 63.0, $parameterLevel->roots[0]->real, 1.0E-12);

        $irrationalLevel = Math::solveRationalComplex('log(4,x)=pi', 'x');
        self::assertCount(1, $irrationalLevel->roots);
        self::assertEqualsWithDelta(\exp(\log(4.0) / \M_PI), $irrationalLevel->roots[0]->real, 1.0E-12);

        $roundedUnitBase = Math::solveRationalComplex(
            'log(1.0000000000000002,x)=1e100',
            'x',
        );
        self::assertSame([], $roundedUnitBase->roots);
        self::assertFalse($roundedUnitBase->converged);

        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex('log(4,exp(x))=pi', 'x'),
        );

        $unsupportedBase = Math::solveRationalComplex('log(4,gamma(x))=pi', 'x');
        self::assertSame([], $unsupportedBase->roots);
        self::assertFalse($unsupportedBase->converged);

        self::assertSame([], Math::solveRationalComplex('log(x,x)=2', 'x')->roots);
        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex('log(x,x)=1', 'x'),
        );

        $holeOnlyRoot = Math::solveRationalComplex(
            'ln(((x-1)/(x-1))*(x-1+e))=1',
            'x',
        );
        self::assertSame([], $holeOnlyRoot->roots);
    }

    public function testSolvesExponentialFunctionsOfRationalExpressions(): void
    {
        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex('exp((x-1)/(x-2))=e^2', 'x'),
        );
        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex('expm1((x-1)/(x-2))=1', 'x'),
        );

        self::assertSame([], Math::solveRationalComplex('exp(1/(x-1))=0', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('expm1(1/(x-1))=-1', 'x')->roots);

        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex(
                'exp(((x-1)/(x-1))*(x-1+1))=e',
                'x',
            ),
        );

        self::assertSame([], Math::solveRationalComplex('exp(2)=e', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('expm1(2)=1', 'x')->roots);
        self::assertSame(
            [],
            Math::solveRationalComplex('exp((x^2+1)/(x^2+1))=e^2', 'x')->roots,
        );
        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex(
                'exp((x^2+1)/(x^2+1))=e',
                'x',
            ),
        );
    }

    public function testClassifiesComplexTranscendentalLevelsOfRationalExpressions(): void
    {
        foreach (
            [
                'sin(1/(x-1))=2',
                'tan((x-1)/(x-2))=-3',
                'sinh(1/(x-1))=0.5',
                'coth((x-1)/(x-2))=0',
            ] as $equation
        ) {
            $this->assertInfiniteSolutions(
                static fn () => Math::solveRationalComplex($equation, 'x'),
            );
        }

        foreach (
            [
                'tanh(1/(x-1))=1',
                'tanh(1/(x-1))=-1',
                'coth(1/(x-1))=1',
                'sec(1/(x-1))=0',
                'csc(1/(x-1))=0',
                'sech(1/(x-1))=0',
                'csch(1/(x-1))=0',
            ] as $equation
        ) {
            self::assertSame([], Math::solveRationalComplex($equation, 'x')->roots, $equation);
        }

        self::assertSame(
            [],
            Math::solveRationalComplex('sin((x^2+1)/(x^2+1))=0', 'x')->roots,
        );
        $constantSineLevel = \sprintf('%.17g', \sin(1.0));
        $this->assertInfiniteSolutions(
            static fn () => Math::solveRationalComplex(
                'sin(((x-1)/(x-1))*(x-1+1))=' . $constantSineLevel,
                'x',
            ),
        );
        self::assertSame(
            [],
            Math::solveRationalComplex('sin(0)=1', 'x')->roots,
        );
    }

    public function testSolvesInverseErrorFunctionsOfRationalExpressions(): void
    {
        $erfTarget = SpecialFunctions::erf(0.5);
        $erfExpectedRoot = (1.0 - (2.0 * $erfTarget)) / (1.0 - $erfTarget);
        $erfinv = Math::solveRationalComplex('erfinv((x-1)/(x-2))=0.5', 'x');
        self::assertCount(1, $erfinv->roots);
        self::assertEqualsWithDelta($erfExpectedRoot, $erfinv->roots[0]->real, 1.0E-12);

        $reversedErfinv = Math::solveRationalComplex('0.5=erfinv((x-1)/(x-2))', 'x');
        self::assertEqualsWithDelta($erfExpectedRoot, $reversedErfinv->roots[0]->real, 1.0E-12);

        $erfcTarget = SpecialFunctions::erfc(0.5);
        $erfcExpectedRoot = (1.0 - (2.0 * $erfcTarget)) / (1.0 - $erfcTarget);
        $erfcinv = Math::solveRationalComplex('erfcinv((x-1)/(x-2))=0.5', 'x');
        self::assertCount(1, $erfcinv->roots);
        self::assertEqualsWithDelta($erfcExpectedRoot, $erfcinv->roots[0]->real, 1.0E-12);

        self::assertSame(
            [],
            Math::solveRationalComplex('erfinv(((x-1)/(x-1))*(x-1))=0', 'x')->roots,
        );
    }

    public function testSolvesInverseTrigonometricAndHyperbolicFunctionsOfRationalExpressions(): void
    {
        $cotangentMidpoint = Math::solveRationalComplex('acot(x)=pi/2', 'x');
        self::assertCount(1, $cotangentMidpoint->roots);
        self::assertSame(0.0, $cotangentMidpoint->roots[0]->real);
        self::assertSame(0.0, $cotangentMidpoint->roots[0]->imaginary);

        $level = 0.5;
        $targets = [
            'asin' => \sin($level),
            'arccos' => \cos($level),
            'atan' => \tan($level),
            'acot' => \cos($level) / \sin($level),
            'asinh' => \sinh($level),
            'acosh' => \cosh($level),
            'atanh' => \tanh($level),
            'asec' => 1.0 / \cos($level),
            'acsc' => 1.0 / \sin($level),
            'asech' => 1.0 / \cosh($level),
            'acsch' => 1.0 / \sinh($level),
            'acoth' => 1.0 / \tanh($level),
        ];

        foreach ($targets as $function => $target) {
            $equation = $function . '((x-1)/(x-2))=' . $level;
            $solution = Math::solveRationalComplex($equation, 'x');
            $expectedRoot = (1.0 - (2.0 * $target)) / (1.0 - $target);
            self::assertCount(1, $solution->roots, $function);
            self::assertEqualsWithDelta($expectedRoot, $solution->roots[0]->real, 1.0E-10, $function);
        }

        $reversedAlias = Math::solveRationalComplex(
            '0.5=arcsin((x-1)/(x-2))',
            'x',
        );
        $asinTarget = \sin($level);
        $asinExpectedRoot = (1.0 - (2.0 * $asinTarget)) / (1.0 - $asinTarget);
        self::assertCount(1, $reversedAlias->roots);
        self::assertEqualsWithDelta($asinExpectedRoot, $reversedAlias->roots[0]->real, 1.0E-10);

        self::assertSame([], Math::solveRationalComplex('asin((x-1)/(x-2))=2', 'x')->roots);
        self::assertSame(
            [],
            Math::solveRationalComplex('asin(((x-1)/(x-1))*(x-1))=0', 'x')->roots,
        );
    }

    public function testInverseHyperbolicLevelsRemainRepresentableAfterOverflowThresholds(): void
    {
        foreach ([['asech', 711.0], ['acsch', 711.0], ['acsch', -711.0]] as [$function, $level]) {
            $solution = Math::solveRationalComplex($function . '(x)=' . $level, 'x');
            self::assertCount(1, $solution->roots, $function);
            self::assertGreaterThan(0.0, \abs($solution->roots[0]->real), $function);
            self::assertSame($level < 0.0, $solution->roots[0]->real < 0.0, $function);
            self::assertEqualsWithDelta(
                $level,
                Math::evaluate($function . '(' . \sprintf('%.17g', $solution->roots[0]->real) . ')'),
                1.0E-8,
                $function,
            );
        }

        self::assertSame([], Math::solveRationalComplex('asech(x)=-1', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('acsch(x)=0', 'x')->roots);
    }

    public function testInverseLevelsCollapsedToBranchEndpointsRemainUnresolved(): void
    {
        foreach (
            [
                'acos(x)=1e-9',
                'acosh(x)=1e-9',
                'asech(x)=1e-9',
                'asec(x)=1e-9',
                'lambertw(x)=-0.999999999',
                'lambertw(x,-1)=-1.000000001',
                'erfinv(x)=6',
                'erfcinv(x)=28',
                'asinh(x)=711',
                'atanh(x)=20',
                'acoth(x)=20',
                'asech(x)=746',
                'acsch(x)=746',
                'ln(x)=710',
                'ln(x)=-746',
                'log1p(x)=-746',
                'log1p(x)=746',
                'log10(x)=400',
                'log2(x)=-1075',
            ] as $equation
        ) {
            $solution = Math::solveRationalComplex($equation, 'x');
            self::assertSame([], $solution->roots, $equation);
            self::assertFalse($solution->converged, $equation);
        }
    }

    public function testSolvesInverseSpecialFunctionsOfRationalExpressions(): void
    {
        $targets = [
            'gammaincinv(2,(x-1)/(x-2))=1.5' => SpecialFunctions::regularizedGammaP(2.0, 1.5),
            'gammainccinv(2,(x-1)/(x-2))=1.5' => SpecialFunctions::regularizedGammaQ(2.0, 1.5),
            'betaincinv(2,3,(x-1)/(x-2))=0.75' => SpecialFunctions::regularizedBeta(2.0, 3.0, 0.75),
            'betainccinv(2,3,(x-1)/(x-2))=0.75'
                => SpecialFunctions::regularizedBetaComplement(2.0, 3.0, 0.75),
            'lambertw((x-1)/(x-2))=0.5' => 0.5 * \exp(0.5),
            'lambertw((x-1)/(x-2),-1)=-2' => -2.0 * \exp(-2.0),
        ];

        foreach ($targets as $equation => $target) {
            $solution = Math::solveRationalComplex($equation, 'x');
            $expectedRoot = (1.0 - (2.0 * $target)) / (1.0 - $target);
            self::assertCount(1, $solution->roots, $equation);
            self::assertEqualsWithDelta($expectedRoot, $solution->roots[0]->real, 1.0E-10, $equation);
        }

        self::assertSame([], Math::solveRationalComplex('gammaincinv(2,1/(x-1))=-1', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('lambertw(1/(x-1))=-2', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('lambertw(1/(x-1),-1)=0', 'x')->roots);
    }

    public function testSolvesErrorFunctionsOfRationalExpressions(): void
    {
        $erfTarget = SpecialFunctions::erf(0.5);
        $erf = Math::solveRationalComplex('erf((x-1)/(x-2))=' . $erfTarget, 'x');
        self::assertCount(1, $erf->roots);
        self::assertEqualsWithDelta(0.0, $erf->roots[0]->real, 1.0E-12);

        $erfcTarget = SpecialFunctions::erfc(0.5);
        $erfc = Math::solveRationalComplex('erfc((x-1)/(x-2))=' . $erfcTarget, 'x');
        self::assertCount(1, $erfc->roots);
        self::assertEqualsWithDelta(0.0, $erfc->roots[0]->real, 1.0E-12);

        $erfiTarget = SpecialFunctions::erfi(0.5);
        $erfi = Math::solveRationalComplex('erfi((x-1)/(x-2))=' . $erfiTarget, 'x');
        self::assertCount(1, $erfi->roots);
        self::assertEqualsWithDelta(0.0, $erfi->roots[0]->real, 1.0E-12);

        $erfcxTarget = SpecialFunctions::erfcx(0.5);
        $erfcx = Math::solveRationalComplex(
            'erfcx((x-1)/(x-2))=' . \sprintf('%.17g', $erfcxTarget),
            'x',
        );
        self::assertCount(1, $erfcx->roots);
        self::assertEqualsWithDelta(0.0, $erfcx->roots[0]->real, 1.0E-12);

        $reversed = Math::solveRationalComplex(
            \sprintf('%.17g=erf((x-1)/(x-2))', $erfTarget),
            'x',
        );
        self::assertCount(1, $reversed->roots);
        self::assertEqualsWithDelta(0.0, $reversed->roots[0]->real, 1.0E-12);

        self::assertSame([], Math::solveRationalComplex('erf(1/(x-1))=1', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('erfc(1/(x-1))=0', 'x')->roots);
        self::assertSame([], Math::solveRationalComplex('erfcx(1/(x-1))=0', 'x')->roots);
        self::assertSame(
            [],
            Math::solveRationalComplex('erf(((x-1)/(x-1))*(x-1))=0', 'x')->roots,
        );
    }

    public function testSolvesDawsonFunctionsOfRationalExpressionsAcrossBothBranches(): void
    {
        foreach ([0.5, -0.5] as $argument) {
            $level = SpecialFunctions::dawson($argument);
            $targets = SpecialFunctions::inverseDawson($level);
            $equation = 'dawson((x-1)/(x-2))=' . \sprintf('%.17g', $level);
            $solution = Math::solveRationalComplex($equation, 'x');
            $expectedRoots = \array_map(
                static fn (float $target): float => (1.0 - (2.0 * $target)) / (1.0 - $target),
                $targets,
            );
            \sort($expectedRoots);
            self::assertCount(2, $solution->roots);
            foreach ($solution->roots as $index => $root) {
                self::assertEqualsWithDelta($expectedRoots[$index], $root->real, 1.0E-10);
            }
        }

        self::assertSame([], Math::solveRationalComplex('dawson((x-1)/(x-2))=0.6', 'x')->roots);
        self::assertSame(
            [],
            Math::solveRationalComplex('dawson(((x-1)/(x-1))*(x-1))=0', 'x')->roots,
        );
    }

    public function testSolvesModifiedBesselFunctionsOfRationalExpressions(): void
    {
        foreach ([0, 1, 2] as $order) {
            $level = SpecialFunctions::besselI($order, 0.5);
            $targets = SpecialFunctions::inverseBesselI($order, $level);
            $solution = Math::solveRationalComplex(
                'besseli(' . $order . ',(x-1)/(x-2))=besseli(' . $order . ',0.5)',
                'x',
            );
            $expectedRoots = \array_map(
                static fn (float $target): float => (1.0 - (2.0 * $target)) / (1.0 - $target),
                $targets,
            );
            \sort($expectedRoots);
            self::assertCount(\count($expectedRoots), $solution->roots);
            foreach ($solution->roots as $index => $root) {
                self::assertEqualsWithDelta($expectedRoots[$index], $root->real, 1.0E-10);
            }
        }

        $negativeOddLevel = -SpecialFunctions::besselI(1, 0.5);
        $negativeOdd = Math::solveRationalComplex(
            'besseli(1,(x-1)/(x-2))=' . \sprintf('%.17g', $negativeOddLevel),
            'x',
        );
        $negativeTarget = SpecialFunctions::inverseBesselI(1, $negativeOddLevel)[0];
        self::assertCount(1, $negativeOdd->roots);
        self::assertEqualsWithDelta(
            (1.0 - (2.0 * $negativeTarget)) / (1.0 - $negativeTarget),
            $negativeOdd->roots[0]->real,
            1.0E-10,
        );

        self::assertSame([], Math::solveRationalComplex('besseli(0,(x-1)/(x-2))=0.5', 'x')->roots);
        self::assertSame([0.0], \array_map(
            static fn ($root): float => $root->real,
            Math::solveRationalComplex('besseli(0,x^2+1)=besseli(0,1)', 'x')->roots,
        ));
        self::assertSame(
            [],
            Math::solveRationalComplex('besseli(0,((x-1)/(x-1))*(x-1))=1', 'x')->roots,
        );

        $evenEndpoint = Math::solveRationalComplex(
            'besseli(0,x)=besseli(0,713)',
            'x',
        );
        self::assertSame([-713.0, 713.0], \array_map(
            static fn ($root): float => $root->real,
            $evenEndpoint->roots,
        ));

        $oddEndpoint = Math::solveRationalComplex(
            'besseli(1,x)=besseli(1,-713)',
            'x',
        );
        self::assertSame([-713.0], \array_map(
            static fn ($root): float => $root->real,
            $oddEndpoint->roots,
        ));
    }

    public function testSolvesSquareAndCubeRootsOfRationalExpressions(): void
    {
        $squareRoot = Math::solveRationalComplex('sqrt(1/(x-1))=2', 'x');
        self::assertCount(1, $squareRoot->roots);
        self::assertEqualsWithDelta(1.25, $squareRoot->roots[0]->real, 1.0E-12);

        $reversedSquareRoot = Math::solveRationalComplex('2=sqrt(1/(x-1))', 'x');
        self::assertEqualsWithDelta(1.25, $reversedSquareRoot->roots[0]->real, 1.0E-12);
        self::assertSame([], Math::solveRationalComplex('sqrt(1/(x-1))=-1', 'x')->roots);

        $cubeRoot = Math::solveRationalComplex('cbrt((x-1)/(x-2))=-2', 'x');
        self::assertCount(1, $cubeRoot->roots);
        self::assertEqualsWithDelta(17.0 / 9.0, $cubeRoot->roots[0]->real, 1.0E-12);

        $integerRoot = Math::solveRationalComplex('root(1/(x-1),2.000000000000001)=2', 'x');
        self::assertCount(1, $integerRoot->roots);
        self::assertEqualsWithDelta(1.25, $integerRoot->roots[0]->real, 1.0E-12);

        $negativeDegreeRoot = Math::solveRationalComplex('root(x,-2)=2', 'x');
        self::assertCount(1, $negativeDegreeRoot->roots);
        self::assertEqualsWithDelta(0.25, $negativeDegreeRoot->roots[0]->real, 1.0E-12);
        self::assertSame([], Math::solveRationalComplex('root(x,-2)=-2', 'x')->roots);

        $largeSquareLevel = Math::solveRationalComplex('sqrt(x)=1e150', 'x');
        self::assertCount(1, $largeSquareLevel->roots);
        self::assertEqualsWithDelta(1.0E300, $largeSquareLevel->roots[0]->real, 1.0E290);

        $largeCubeLevel = Math::solveRationalComplex('cbrt(x)=1e100', 'x');
        self::assertCount(1, $largeCubeLevel->roots);
        self::assertEqualsWithDelta(1.0E300, $largeCubeLevel->roots[0]->real, 1.0E290);

        $holeOnlyRoot = Math::solveRationalComplex('sqrt(((x-1)/(x-1))*(x-1))=0', 'x');
        self::assertSame([], $holeOnlyRoot->roots);

        $unsupportedRadicand = Math::solveRationalComplex('sqrt(gamma(x))=2', 'x');
        self::assertSame([], $unsupportedRadicand->roots);
        self::assertFalse($unsupportedRadicand->converged);
    }

    public function testComposesRootAndExponentialInversionsForRationalExpressions(): void
    {
        foreach (
            [
                'sqrt(exp((x-1)/(x-2)))=1',
                'sqrt(exp(((x-1)/(x-1))*(x-1)))=1',
                'cbrt(exp((x-1)/(x-2)))=e',
            ] as $equation
        ) {
            $this->assertInfiniteSolutions(
                static fn () => Math::solveRationalComplex($equation, 'x'),
            );
        }

        $logarithm = Math::solveRationalComplex('sqrt(ln((x-1)/(x-2)))=1', 'x');
        self::assertCount(1, $logarithm->roots);
        self::assertEqualsWithDelta(2.5819767068693267, $logarithm->roots[0]->real, 1.0E-12);

        $nestedRoot = Math::solveRationalComplex('root(ln((x-1)/(x-2)),3)=2', 'x');
        self::assertCount(1, $nestedRoot->roots);
        self::assertEqualsWithDelta(2.0003355752008414, $nestedRoot->roots[0]->real, 1.0E-12);
    }

    public function testPolynomialFallbackSupportsIrrationalConstantCoefficients(): void
    {
        $solution = Math::solveRationalComplex('x^2=pi', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(2, $solution->roots);
        self::assertEqualsWithDelta(-\sqrt(\M_PI), $solution->roots[0]->real, 1.0E-12);
        self::assertEqualsWithDelta(\sqrt(\M_PI), $solution->roots[1]->real, 1.0E-12);
        self::assertSame(0.0, $solution->roots[0]->imaginary);
        self::assertSame(0.0, $solution->roots[1]->imaginary);
    }

    public function testExcludesCommonNumeratorDenominatorRootsAsPoles(): void
    {
        $solution = Math::solveRationalComplex('(x^2-1)/(x-1)=0', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertSame(-1.0, $solution->roots[0]->real);
        self::assertSame(0.0, $solution->roots[0]->imaginary);
    }

    public function testDoesNotTreatNearbyDistinctNumeratorRootAsPole(): void
    {
        $solution = Math::solveRationalComplex(
            '((x-1)*(x-1.00000000001))/(x-1)=0',
            'x',
        );

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertEqualsWithDelta(1.00000000001, $solution->roots[0]->real, 1.0E-13);
        self::assertSame(0.0, $solution->roots[0]->imaginary);
    }

    public function testRemovesAllMultiplicityAtCommonNumeratorDenominatorRoots(): void
    {
        $solution = Math::solveRationalComplex('((x-1)^3*(x-2))/(x-1)^2=0', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertSame(2.0, $solution->roots[0]->real);
        self::assertSame(0.0, $solution->roots[0]->imaginary);
    }

    public function testNestedRemovableSingularityStaysExcludedWithExcessNumeratorMultiplicity(): void
    {
        $solution = Math::solveRationalComplex(
            '((x-1)/(x-1))*(x-1)^3*(x-2)=0',
            'x',
        );

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertSame(2.0, $solution->roots[0]->real);
        self::assertSame(0.0, $solution->roots[0]->imaginary);
    }

    public function testCanceledNonRealPolesAreExcludedFromComplexRoots(): void
    {
        $solution = Math::solveRationalComplex('(x^2+1)^3/(x^2+1)^2=0', 'x');

        self::assertTrue($solution->converged);
        self::assertSame([], $solution->roots);
    }

    public function testIdenticallyUndefinedRationalExpressionsHaveNoSolutions(): void
    {
        foreach (['1/(x-x)=0', '(x-x)^-1=0', '1/0=0'] as $equation) {
            $solution = Math::solveRationalComplex($equation, 'x');

            self::assertTrue($solution->converged, $equation);
            self::assertSame([], $solution->roots, $equation);
        }

        $definedEquation = Math::solveRationalComplex('(x-1)/(x+1)=0', 'x');
        self::assertCount(1, $definedEquation->roots);
        self::assertSame(1.0, $definedEquation->roots[0]->real);
    }

    public function testZeroPowersPreserveRationalDomainHolesAndInfiniteIdentities(): void
    {
        foreach (
            [
                '((x-1)/(x-1))^0=1',
                '(1/(x-1))^0=1',
                'x^0=1',
            ] as $equation
        ) {
            $threwInfiniteSolutionSet = false;
            try {
                Math::solveRationalComplex($equation, 'x');
            } catch (InfiniteSolutionSetException) {
                $threwInfiniteSolutionSet = true;
            }
            self::assertTrue($threwInfiniteSolutionSet, $equation);
        }

        self::assertSame(
            [],
            Math::solveRationalComplex('((x-1)/(x-1))^0=2', 'x')->roots,
        );
        self::assertSame([], Math::solveRationalComplex('x^0=0', 'x')->roots);
    }

    public function testRationalIdentityReportsInfiniteSolutions(): void
    {
        $this->expectException(InfiniteSolutionSetException::class);

        Math::solveRationalComplex('(x^2-1)/(x-1)=x+1', 'x');
    }

    public function testRationalEquationSupportsEvaluableNonRationalCoefficients(): void
    {
        $solution = Math::solveRationalComplex('(x-pi)/(x-1)=0', 'x');

        self::assertTrue($solution->converged);
        self::assertCount(1, $solution->roots);
        self::assertEqualsWithDelta(\M_PI, $solution->roots[0]->real, 1.0E-15);
        self::assertEqualsWithDelta(0.0, $solution->roots[0]->imaginary, 1.0E-15);

        $functionConstant = Math::solveRationalComplex('(x-sqrt(2))/(x-1)=0', 'x');
        self::assertCount(1, $functionConstant->roots);
        self::assertEqualsWithDelta(\sqrt(2.0), $functionConstant->roots[0]->real, 1.0E-15);

        $functionConstantPole = Math::solveRationalComplex(
            '((x-sqrt(2))^2)/(x-sqrt(2))=0',
            'x',
        );
        self::assertSame([], $functionConstantPole->roots);
    }

    public function testVariableDependentFunctionsRemainUnsupportedAsRationalCoefficients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Math::solveRationalComplex('sin(x)/(x-1)=0', 'x');
    }

    /** @param callable(): mixed $solve */
    private function assertInfiniteSolutions(callable $solve): void
    {
        try {
            $solve();
        } catch (InfiniteSolutionSetException) {
            return;
        }

        self::fail('Expected an infinite complex solution set.');
    }
}
