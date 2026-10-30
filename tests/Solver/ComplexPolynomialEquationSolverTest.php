<?php

declare(strict_types=1);

namespace MathPHP\Tests\Solver;

use MathPHP\Math;
use MathPHP\Solver\ComplexPolynomialSolver;
use MathPHP\Solver\InfiniteSolutionSetException;
use MathPHP\Solver\SolverOptions;
use PHPUnit\Framework\TestCase;

final class ComplexPolynomialEquationSolverTest extends TestCase
{
    public function testComplexSolverRejectsNonListExactCoefficientMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComplexPolynomialSolver())->solve(
            [1.0, 1.0],
            new SolverOptions(),
            [0 => '1', 2 => '1'],
        );
    }

    public function testComplexSolverRejectsNonIntegerExactCoefficients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComplexPolynomialSolver())->solve(
            [1.0, 1.0],
            new SolverOptions(),
            ['1', '01'],
        );
    }

    public function testComplexSolverRejectsNonFiniteCoefficientsBeforeExactRecovery(): void
    {
        $this->expectException(\DomainException::class);

        (new ComplexPolynomialSolver())->solve(
            [NAN, 0.0],
            new SolverOptions(),
            ['1', '1'],
        );
    }

    public function testComplexSolverRejectsNonListPolynomialCoefficients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComplexPolynomialSolver())->solve(
            [0 => 1.0, 2 => 1.0],
            new SolverOptions(),
        );
    }

    public function testQuadraticReturnsBothComplexConjugateRoots(): void
    {
        $result = Math::solvePolynomialComplex('x^2 + 1 = 0', 'x');

        self::assertSame(2, $result->degree);
        self::assertTrue($result->converged);
        self::assertCount(2, $result->roots);
        self::assertEqualsWithDelta(0.0, $result->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(-1.0, $result->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $result->roots[1]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $result->roots[1]->imaginary, 1.0E-10);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testZeroPreservingFunctionEquationsReduceToTheirPolynomialArgument(): void
    {
        $squareRoot = Math::solvePolynomialComplex('sqrt(x^2+1)=0', 'x');
        self::assertSame(2, $squareRoot->degree);
        self::assertTrue($squareRoot->converged);
        self::assertCount(2, $squareRoot->roots);
        self::assertEqualsWithDelta(-1.0, $squareRoot->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $squareRoot->roots[1]->imaginary, 1.0E-10);

        $cubeRoot = Math::solvePolynomialComplex('0.0=cbrt((x-1)^3)', 'x');
        self::assertSame(3, $cubeRoot->degree);
        self::assertTrue($cubeRoot->converged);
        self::assertCount(3, $cubeRoot->roots);
        foreach ($cubeRoot->roots as $root) {
            self::assertEqualsWithDelta(1.0, $root->real, 1.0E-10);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-10);
        }

        $integerRoot = Math::solvePolynomialComplex('root(x^2+1,5)=0', 'x');
        self::assertCount(2, $integerRoot->roots);
        self::assertEqualsWithDelta(-1.0, $integerRoot->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $integerRoot->roots[1]->imaginary, 1.0E-10);

        $exponential = Math::solvePolynomialComplex('exp(x)=0', 'x');
        self::assertSame(0, $exponential->degree);
        self::assertTrue($exponential->converged);
        self::assertSame([], $exponential->roots);
    }

    public function testComplexAbsoluteValueLevelsDistinguishFiniteAndInfiniteSolutionSets(): void
    {
        $zeroLevel = Math::solvePolynomialComplex('abs(x)=0', 'x');
        self::assertTrue($zeroLevel->converged);
        self::assertCount(1, $zeroLevel->roots);
        self::assertSame(0.0, $zeroLevel->roots[0]->real);
        self::assertSame(0.0, $zeroLevel->roots[0]->imaginary);

        foreach (['abs(x)=-1', '-1=abs(x)', 'abs(2)=3'] as $equation) {
            $result = Math::solvePolynomialComplex($equation, 'x');
            self::assertSame([], $result->roots, $equation);
            self::assertTrue($result->converged, $equation);
        }

        foreach (['abs(x)=1', '3=abs(x)', 'abs(x^2+1)=1', 'abs(2)=2'] as $equation) {
            try {
                Math::solvePolynomialComplex($equation, 'x');
                self::fail('Expected an infinite complex solution set: ' . $equation);
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testComplexTranscendentalLevelsClassifyInfiniteAndOmittedValues(): void
    {
        foreach (
            [
                'exp(x)=0',
                'expm1(x)=-1',
                'tanh(x)=1',
                'tanh(x)=-1',
                'coth(x)=1',
                'coth(x)=-1',
                'sech(x)=0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');
            self::assertSame([], $result->roots, $equation);
            self::assertTrue($result->converged, $equation);
        }

        foreach (
            [
                'exp(x)=1',
                'sin(x)=2',
                'cosh(x)=0',
                'tan(x)=-3',
                'tanh(x)=0.5',
                'coth(x)=0',
                'sech(x)=2',
                'csch(x)=-0.25',
            ] as $equation
        ) {
            try {
                Math::solvePolynomialComplex($equation, 'x');
                self::fail('Expected an infinite complex solution set: ' . $equation);
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['exp(1)=e', 'exp(x-x)=1'] as $equation) {
            try {
                Math::solvePolynomialComplex($equation, 'x');
                self::fail('Expected a constant identity to have infinitely many solutions.');
            } catch (InfiniteSolutionSetException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], Math::solvePolynomialComplex('exp(1)=2', 'x')->roots);
    }

    public function testZeroExponentTermsAreFoldedIntoPolynomialConstants(): void
    {
        $linear = Math::solvePolynomialComplex('2*x^0 + x = 0', 'x');

        self::assertSame(1, $linear->degree);
        self::assertTrue($linear->converged);
        self::assertCount(1, $linear->roots);
        self::assertSame(-2.0, $linear->roots[0]->real);
        self::assertSame(0.0, $linear->roots[0]->imaginary);

        $nonzeroConstant = Math::solvePolynomialComplex('x^0 = 0', 'x');
        self::assertSame(0, $nonzeroConstant->degree);
        self::assertSame([], $nonzeroConstant->roots);

        $this->expectException(InfiniteSolutionSetException::class);
        Math::solvePolynomialComplex('x^0 = 1', 'x');
    }

    public function testPrincipalSquareRootEquationsReduceAtRealConstantLevels(): void
    {
        $unitLevel = Math::solvePolynomialComplex('sqrt(x^2+1)=1', 'x');
        self::assertSame(2, $unitLevel->degree);
        self::assertCount(2, $unitLevel->roots);
        foreach ($unitLevel->roots as $root) {
            self::assertEqualsWithDelta(0.0, $root->real, 1.0E-10);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-10);
        }

        $reversed = Math::solvePolynomialComplex('2=sqrt(x^2+1)', 'x');
        self::assertCount(2, $reversed->roots);
        self::assertEqualsWithDelta(-\sqrt(3.0), $reversed->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(3.0), $reversed->roots[1]->real, 1.0E-10);

        $negativeLevel = Math::solvePolynomialComplex('sqrt(x^2+1)=-1', 'x');
        self::assertSame([], $negativeLevel->roots);
        self::assertTrue($negativeLevel->converged);

        try {
            Math::solvePolynomialComplex('sqrt(exp(x))=1', 'x');
            self::fail('The transformed exponential equation has infinitely many complex roots.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        try {
            Math::solvePolynomialComplex('sqrt(gamma(x))=2', 'x');
            self::fail('The transformed gamma equation has infinitely many complex roots.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }
    }

    public function testNonzeroIntegerRootLevelsReduceToPolynomialTargets(): void
    {
        $cubeRoot = Math::solvePolynomialComplex('cbrt(x^2+1)=2', 'x');
        self::assertCount(2, $cubeRoot->roots);
        self::assertEqualsWithDelta(-\sqrt(7.0), $cubeRoot->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(7.0), $cubeRoot->roots[1]->real, 1.0E-10);

        $negativeCubeRoot = Math::solvePolynomialComplex('cbrt(x^2+1)=-2', 'x');
        self::assertCount(2, $negativeCubeRoot->roots);
        self::assertEqualsWithDelta(-3.0, $negativeCubeRoot->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(3.0, $negativeCubeRoot->roots[1]->imaginary, 1.0E-10);

        $fourthRoot = Math::solvePolynomialComplex('root(x^2+1,4)=2', 'x');
        self::assertCount(2, $fourthRoot->roots);
        self::assertEqualsWithDelta(-\sqrt(15.0), $fourthRoot->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(15.0), $fourthRoot->roots[1]->real, 1.0E-10);

        $negativeDegree = Math::solvePolynomialComplex('root(x,-3)=-2', 'x');
        self::assertCount(1, $negativeDegree->roots);
        self::assertSame(-0.125, $negativeDegree->roots[0]->real);

        $negativeEvenRootLevel = Math::solvePolynomialComplex('root(x,4)=-2', 'x');
        self::assertSame([], $negativeEvenRootLevel->roots);
        self::assertTrue($negativeEvenRootLevel->converged);

        try {
            Math::solvePolynomialComplex('cbrt(exp(x))=2', 'x');
            self::fail('The transformed exponential equation has infinitely many complex roots.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        try {
            Math::solvePolynomialComplex('root(gamma(x),3)=2', 'x');
            self::fail('The transformed gamma equation has infinitely many complex roots.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }
    }

    public function testLogarithmEquationsReduceAtRealConstantLevels(): void
    {
        $naturalLogarithm = Math::solvePolynomialComplex('1=ln(x^2+1)', 'x');
        self::assertCount(2, $naturalLogarithm->roots);
        self::assertEqualsWithDelta(-\sqrt(\M_E - 1.0), $naturalLogarithm->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\M_E - 1.0), $naturalLogarithm->roots[1]->real, 1.0E-10);

        $commonLogarithm = Math::solvePolynomialComplex('log10(x^2+1)=1', 'x');
        self::assertCount(2, $commonLogarithm->roots);
        self::assertEqualsWithDelta(-3.0, $commonLogarithm->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(3.0, $commonLogarithm->roots[1]->real, 1.0E-10);

        $basedLogarithm = Math::solvePolynomialComplex('log(x^2+1,2)=2', 'x');
        self::assertCount(2, $basedLogarithm->roots);
        self::assertEqualsWithDelta(-\sqrt(3.0), $basedLogarithm->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(3.0), $basedLogarithm->roots[1]->real, 1.0E-10);

        $variableBase = Math::solvePolynomialComplex('log(4,x)=2', 'x');
        self::assertCount(1, $variableBase->roots);
        self::assertSame(2.0, $variableBase->roots[0]->real);
        self::assertSame(0.0, $variableBase->roots[0]->imaginary);

        $negativeLevel = Math::solvePolynomialComplex('log(4,x)=-2', 'x');
        self::assertCount(1, $negativeLevel->roots);
        self::assertSame(0.5, $negativeLevel->roots[0]->real);

        $fractionalLevel = Math::solvePolynomialComplex('log(4,x)=1/2', 'x');
        self::assertCount(1, $fractionalLevel->roots);
        self::assertEqualsWithDelta(16.0, $fractionalLevel->roots[0]->real, 1.0E-12);

        $radicalFractionalLevel = Math::solvePolynomialComplex('log(4,x)=sqrt(1/4)', 'x');
        self::assertCount(1, $radicalFractionalLevel->roots);
        self::assertEqualsWithDelta(16.0, $radicalFractionalLevel->roots[0]->real, 1.0E-12);

        $negativeFractionalLevel = Math::solvePolynomialComplex('log(4,x)=-1/2', 'x');
        self::assertCount(1, $negativeFractionalLevel->roots);
        self::assertEqualsWithDelta(1.0 / 16.0, $negativeFractionalLevel->roots[0]->real, 1.0E-12);

        $parameterLevel = Math::solvePolynomialComplex('log(4,x)=q', 'x', ['q' => 1.0 / 3.0]);
        self::assertCount(1, $parameterLevel->roots);
        self::assertEqualsWithDelta(64.0, $parameterLevel->roots[0]->real, 1.0E-12);

        $irrationalParameterLevel = Math::solvePolynomialComplex(
            'log(4,x)=q',
            'x',
            ['q' => \sqrt(2.0)],
        );
        self::assertCount(1, $irrationalParameterLevel->roots);
        self::assertEqualsWithDelta(\exp(\log(4.0) / \sqrt(2.0)), $irrationalParameterLevel->roots[0]->real, 1.0E-12);

        $piLevel = Math::solvePolynomialComplex('log(4,x)=pi', 'x');
        self::assertCount(1, $piLevel->roots);
        self::assertEqualsWithDelta(\exp(\log(4.0) / \M_PI), $piLevel->roots[0]->real, 1.0E-12);

        $roundedUnitBase = Math::solvePolynomialComplex(
            'log(1.0000000000000002,x)=1e100',
            'x',
        );
        self::assertSame([], $roundedUnitBase->roots);
        self::assertFalse($roundedUnitBase->converged);

        $variableArgumentIrrationalLevel = Math::solvePolynomialComplex(
            'log(x+1,x+2)=q',
            'x',
            ['q' => \sqrt(2.0)],
        );
        self::assertSame([], $variableArgumentIrrationalLevel->roots);
        self::assertFalse($variableArgumentIrrationalLevel->converged);

        try {
            Math::solvePolynomialComplex('log(4,exp(x))=pi', 'x');
            self::fail('A periodic complex base produces infinitely many solutions.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        try {
            Math::solvePolynomialComplex('log(4,gamma(x))=pi', 'x');
            self::fail('The transformed gamma equation has infinitely many complex roots.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        $tinyParameterLevel = Math::solvePolynomialComplex(
            'log(4,x)=q',
            'x',
            ['q' => 1.0E-20],
        );
        self::assertSame([], $tinyParameterLevel->roots);
        self::assertFalse($tinyParameterLevel->converged);

        self::assertSame([], Math::solvePolynomialComplex('log(x,x)=2', 'x')->roots);

        try {
            Math::solvePolynomialComplex('log(x,x)=1', 'x');
            self::fail('Every positive base other than one satisfies log(x, x) = 1.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }

        $logOnePlus = Math::solvePolynomialComplex('log1p(x^2)=1', 'x');
        self::assertCount(2, $logOnePlus->roots);
        self::assertEqualsWithDelta(-\sqrt(\M_E - 1.0), $logOnePlus->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\M_E - 1.0), $logOnePlus->roots[1]->real, 1.0E-10);

        $invalidBase = Math::solvePolynomialComplex('log(x,1)=0', 'x');
        self::assertSame([], $invalidBase->roots);
    }

    public function testPrincipalInverseTrigAndHyperbolicLevelsReduceToPolynomialTargets(): void
    {
        $inverseSine = Math::solvePolynomialComplex('asin(x^2+1)=pi/2', 'x');
        self::assertCount(2, $inverseSine->roots);
        foreach ($inverseSine->roots as $root) {
            self::assertEqualsWithDelta(0.0, $root->real, 1.0E-10);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-10);
        }

        $inverseCosine = Math::solvePolynomialComplex('acos(x^2)=pi', 'x');
        self::assertCount(2, $inverseCosine->roots);
        self::assertEqualsWithDelta(-1.0, $inverseCosine->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseCosine->roots[1]->imaginary, 1.0E-10);

        $inverseTangent = Math::solvePolynomialComplex('atan(x^2)=pi/4', 'x');
        self::assertCount(2, $inverseTangent->roots);
        self::assertEqualsWithDelta(-1.0, $inverseTangent->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseTangent->roots[1]->real, 1.0E-10);

        $inverseCotangent = Math::solvePolynomialComplex('acot(x^2)=pi/4', 'x');
        self::assertCount(2, $inverseCotangent->roots);
        self::assertEqualsWithDelta(-1.0, $inverseCotangent->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseCotangent->roots[1]->real, 1.0E-10);

        $inverseHyperbolicSine = Math::solvePolynomialComplex('asinh(x^2)=1', 'x');
        self::assertCount(2, $inverseHyperbolicSine->roots);
        self::assertEqualsWithDelta(-\sqrt(\sinh(1.0)), $inverseHyperbolicSine->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\sinh(1.0)), $inverseHyperbolicSine->roots[1]->real, 1.0E-10);

        $inverseHyperbolicCosine = Math::solvePolynomialComplex('acosh(x^2)=1', 'x');
        self::assertCount(2, $inverseHyperbolicCosine->roots);
        self::assertEqualsWithDelta(-\sqrt(\cosh(1.0)), $inverseHyperbolicCosine->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\cosh(1.0)), $inverseHyperbolicCosine->roots[1]->real, 1.0E-10);

        $inverseTanh = Math::solvePolynomialComplex('atanh(x^2)=1', 'x');
        self::assertCount(2, $inverseTanh->roots);
        self::assertEqualsWithDelta(-\sqrt(\tanh(1.0)), $inverseTanh->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\tanh(1.0)), $inverseTanh->roots[1]->real, 1.0E-10);

        $inverseSecant = Math::solvePolynomialComplex('asec(x^2)=pi/3', 'x');
        self::assertCount(2, $inverseSecant->roots);
        self::assertEqualsWithDelta(-\sqrt(2.0), $inverseSecant->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(2.0), $inverseSecant->roots[1]->real, 1.0E-10);

        $inverseCosecant = Math::solvePolynomialComplex('acsc(x^2)=pi/6', 'x');
        self::assertCount(2, $inverseCosecant->roots);
        self::assertEqualsWithDelta(-\sqrt(2.0), $inverseCosecant->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(2.0), $inverseCosecant->roots[1]->real, 1.0E-10);

        $inverseHyperbolicSecant = Math::solvePolynomialComplex('asech(x^2)=1', 'x');
        self::assertCount(2, $inverseHyperbolicSecant->roots);
        self::assertEqualsWithDelta(-\sqrt(1.0 / \cosh(1.0)), $inverseHyperbolicSecant->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(1.0 / \cosh(1.0)), $inverseHyperbolicSecant->roots[1]->real, 1.0E-10);

        $inverseHyperbolicCosecant = Math::solvePolynomialComplex('acsch(x^2)=1', 'x');
        self::assertCount(2, $inverseHyperbolicCosecant->roots);
        self::assertEqualsWithDelta(-\sqrt(1.0 / \sinh(1.0)), $inverseHyperbolicCosecant->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(1.0 / \sinh(1.0)), $inverseHyperbolicCosecant->roots[1]->real, 1.0E-10);

        $inverseHyperbolicCotangent = Math::solvePolynomialComplex('acoth(x^2)=1', 'x');
        self::assertCount(2, $inverseHyperbolicCotangent->roots);
        self::assertEqualsWithDelta(-\sqrt(1.0 / \tanh(1.0)), $inverseHyperbolicCotangent->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(1.0 / \tanh(1.0)), $inverseHyperbolicCotangent->roots[1]->real, 1.0E-10);

        $outOfRange = Math::solvePolynomialComplex('asin(x^2)=2', 'x');
        self::assertSame([], $outOfRange->roots);
        self::assertSame([], Math::solvePolynomialComplex('acot(x^2)=0', 'x')->roots);
        self::assertSame([], Math::solvePolynomialComplex('acot(x^2)=pi', 'x')->roots);
        $singularLevel = Math::solvePolynomialComplex('asec(x^2)=pi/2', 'x');
        self::assertSame([], $singularLevel->roots);
    }

    public function testInverseLevelsCollapsedToEndpointsAreUnresolvedForPolynomials(): void
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
            $solution = Math::solvePolynomialComplex($equation, 'x');
            self::assertSame([], $solution->roots, $equation);
            self::assertFalse($solution->converged, $equation);
        }
    }

    public function testInverseHyperbolicTransformsRemainStableAtLargeLevels(): void
    {
        foreach ([['asech', 711.0], ['acsch', 711.0], ['acsch', -711.0]] as [$function, $level]) {
            $solution = Math::solvePolynomialComplex($function . '(x)=' . $level, 'x');
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

        $cotangentMidpoint = Math::solvePolynomialComplex('acot(x)=pi/2', 'x');
        self::assertCount(1, $cotangentMidpoint->roots);
        self::assertSame(0.0, $cotangentMidpoint->roots[0]->real);
    }

    public function testInverseFunctionAliasesReduceToTheSamePolynomialTargets(): void
    {
        foreach (
            [
                ['arcsin(x^2)=pi/2', 1.0],
                ['arccos(x^2)=pi', -1.0],
                ['arctan(x^2)=pi/4', 1.0],
                ['arcsinh(x^2)=1', \sinh(1.0)],
                ['arccosh(x^2)=1', \cosh(1.0)],
                ['arctanh(x^2)=1', \tanh(1.0)],
            ] as [$equation, $target]
        ) {
            $solution = Math::solvePolynomialComplex($equation, 'x');
            self::assertCount(2, $solution->roots, $equation);
            if ($target < 0.0) {
                self::assertEqualsWithDelta(0.0, $solution->roots[0]->real, 1.0E-10, $equation);
                self::assertEqualsWithDelta(-1.0, $solution->roots[0]->imaginary, 1.0E-10, $equation);
                self::assertEqualsWithDelta(0.0, $solution->roots[1]->real, 1.0E-10, $equation);
                self::assertEqualsWithDelta(1.0, $solution->roots[1]->imaginary, 1.0E-10, $equation);
                continue;
            }

            $expectedRoot = \sqrt($target);
            self::assertEqualsWithDelta(-$expectedRoot, $solution->roots[0]->real, 1.0E-10, $equation);
            self::assertEqualsWithDelta($expectedRoot, $solution->roots[1]->real, 1.0E-10, $equation);
        }
    }

    public function testInverseErrorFunctionsReduceToForwardFunctionLevels(): void
    {
        $inverseError = Math::solvePolynomialComplex('erfinv(x^2)=0.5', 'x');
        $errorTarget = \MathPHP\Function\SpecialFunctions::erf(0.5);
        self::assertCount(2, $inverseError->roots);
        self::assertEqualsWithDelta(-\sqrt($errorTarget), $inverseError->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt($errorTarget), $inverseError->roots[1]->real, 1.0E-10);

        $inverseComplementaryError = Math::solvePolynomialComplex('erfcinv(x^2)=0.5', 'x');
        $complementaryErrorTarget = \MathPHP\Function\SpecialFunctions::erfc(0.5);
        self::assertCount(2, $inverseComplementaryError->roots);
        self::assertEqualsWithDelta(
            -\sqrt($complementaryErrorTarget),
            $inverseComplementaryError->roots[0]->real,
            1.0E-10,
        );
        self::assertEqualsWithDelta(
            \sqrt($complementaryErrorTarget),
            $inverseComplementaryError->roots[1]->real,
            1.0E-10,
        );
    }

    public function testInverseRegularizedGammaAndBetaLevelsReduceToPolynomialTargets(): void
    {
        foreach (
            [
                [
                    'gammaincinv(2,x^2)=0.5',
                    \MathPHP\Function\SpecialFunctions::regularizedGammaP(2.0, 0.5),
                ],
                [
                    'gammainccinv(2,x^2)=0.5',
                    \MathPHP\Function\SpecialFunctions::regularizedGammaQ(2.0, 0.5),
                ],
                [
                    'betaincinv(2,3,x^2)=0.4',
                    \MathPHP\Function\SpecialFunctions::regularizedBeta(2.0, 3.0, 0.4),
                ],
                [
                    'betainccinv(2,3,x^2)=0.4',
                    \MathPHP\Function\SpecialFunctions::regularizedBetaComplement(2.0, 3.0, 0.4),
                ],
            ] as [$equation, $target]
        ) {
            $solution = Math::solvePolynomialComplex($equation, 'x');
            $expectedRoot = \sqrt($target);
            self::assertCount(2, $solution->roots, $equation);
            self::assertEqualsWithDelta(-$expectedRoot, $solution->roots[0]->real, 1.0E-10, $equation);
            self::assertEqualsWithDelta($expectedRoot, $solution->roots[1]->real, 1.0E-10, $equation);
        }

        self::assertSame([], Math::solvePolynomialComplex('gammaincinv(2,x^2)=-1', 'x')->roots);
        self::assertSame([], Math::solvePolynomialComplex('gammainccinv(2,x^2)=-1', 'x')->roots);
        self::assertSame([], Math::solvePolynomialComplex('betaincinv(2,3,x^2)=2', 'x')->roots);
        self::assertSame([], Math::solvePolynomialComplex('betainccinv(2,3,x^2)=-1', 'x')->roots);
    }

    public function testLambertWConstantLevelsReduceOnSupportedRealBranches(): void
    {
        $principal = Math::solvePolynomialComplex('lambertw(x^2)=1', 'x');
        self::assertCount(2, $principal->roots);
        self::assertEqualsWithDelta(-\sqrt(\M_E), $principal->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(\M_E), $principal->roots[1]->real, 1.0E-10);

        $principalBranchPoint = Math::solvePolynomialComplex('lambertw(x^2)=-1', 'x');
        self::assertCount(2, $principalBranchPoint->roots);
        self::assertEqualsWithDelta(-1.0 / \sqrt(\M_E), $principalBranchPoint->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0 / \sqrt(\M_E), $principalBranchPoint->roots[1]->imaginary, 1.0E-10);

        $lowerBranch = Math::solvePolynomialComplex('lambertw(x^2,-1)=-2', 'x');
        self::assertCount(2, $lowerBranch->roots);
        self::assertEqualsWithDelta(-\sqrt(2.0 / \M_E ** 2), $lowerBranch->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(2.0 / \M_E ** 2), $lowerBranch->roots[1]->imaginary, 1.0E-10);

        self::assertSame([], Math::solvePolynomialComplex('lambertw(x^2)=-2', 'x')->roots);
        self::assertSame([], Math::solvePolynomialComplex('lambertw(x^2,-1)=0', 'x')->roots);
    }

    public function testPolynomialHypotenuseLevelsReduceToSumOfSquares(): void
    {
        $linear = Math::solvePolynomialComplex('hypot(x,0)=2', 'x');
        self::assertCount(2, $linear->roots);
        self::assertEqualsWithDelta(-2.0, $linear->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(2.0, $linear->roots[1]->real, 1.0E-10);

        $imaginaryRoots = Math::solvePolynomialComplex('hypot(x,1)=0', 'x');
        self::assertCount(2, $imaginaryRoots->roots);
        self::assertEqualsWithDelta(-1.0, $imaginaryRoots->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $imaginaryRoots->roots[1]->imaginary, 1.0E-10);

        $quartic = Math::solvePolynomialComplex('hypot(x^2-1,x)=1', 'x');
        self::assertCount(4, $quartic->roots);
        self::assertEqualsWithDelta(-1.0, $quartic->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $quartic->roots[1]->real, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $quartic->roots[2]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $quartic->roots[3]->real, 1.0E-10);

        $largeScale = Math::solvePolynomialComplex('hypot(x,0)=1e200', 'x');
        self::assertCount(2, $largeScale->roots);
        self::assertEqualsWithDelta(-1.0E200, $largeScale->roots[0]->real, 1.0E190);
        self::assertEqualsWithDelta(1.0E200, $largeScale->roots[1]->real, 1.0E190);

        $smallScale = Math::solvePolynomialComplex('hypot(x,0)=1e-200', 'x');
        self::assertCount(2, $smallScale->roots);
        self::assertEqualsWithDelta(-1.0E-200, $smallScale->roots[0]->real, 1.0E-210);
        self::assertEqualsWithDelta(1.0E-200, $smallScale->roots[1]->real, 1.0E-210);

        self::assertSame([], Math::solvePolynomialComplex('hypot(x,1)=-1', 'x')->roots);
    }

    public function testInverseFunctionZeroLevelsReduceToPolynomialLevels(): void
    {
        $logarithm = Math::solvePolynomialComplex('ln(x^2+1)=0', 'x');
        self::assertSame(2, $logarithm->degree);
        self::assertCount(2, $logarithm->roots);
        foreach ($logarithm->roots as $root) {
            self::assertEqualsWithDelta(0.0, $root->real, 1.0E-10);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-10);
        }

        $logOnePlus = Math::solvePolynomialComplex('log1p(x^2+1)=0', 'x');
        self::assertCount(2, $logOnePlus->roots);
        self::assertEqualsWithDelta(-1.0, $logOnePlus->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $logOnePlus->roots[1]->imaginary, 1.0E-10);

        $basedLogarithm = Math::solvePolynomialComplex('log(x^2+1,2)=0', 'x');
        self::assertSame(2, $basedLogarithm->degree);
        self::assertCount(2, $basedLogarithm->roots);
        foreach ($basedLogarithm->roots as $root) {
            self::assertEqualsWithDelta(0.0, $root->real, 1.0E-10);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-10);
        }

        $inverseSine = Math::solvePolynomialComplex('asin(x^2+1)=0', 'x');
        self::assertCount(2, $inverseSine->roots);
        self::assertEqualsWithDelta(-1.0, $inverseSine->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseSine->roots[1]->imaginary, 1.0E-10);

        $inverseCosine = Math::solvePolynomialComplex('acos(x^2)=0', 'x');
        self::assertCount(2, $inverseCosine->roots);
        self::assertEqualsWithDelta(-1.0, $inverseCosine->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseCosine->roots[1]->real, 1.0E-10);

        $inverseSecant = Math::solvePolynomialComplex('asec(x^2)=0', 'x');
        self::assertCount(2, $inverseSecant->roots);
        self::assertEqualsWithDelta(-1.0, $inverseSecant->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseSecant->roots[1]->real, 1.0E-10);

        $absoluteValue = Math::solvePolynomialComplex('abs(x^2+1)=0', 'x');
        self::assertCount(2, $absoluteValue->roots);
        self::assertEqualsWithDelta(-1.0, $absoluteValue->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $absoluteValue->roots[1]->imaginary, 1.0E-10);

        $inverseError = Math::solvePolynomialComplex('erfcinv(x^2)=0', 'x');
        self::assertCount(2, $inverseError->roots);
        self::assertEqualsWithDelta(-1.0, $inverseError->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(1.0, $inverseError->roots[1]->real, 1.0E-10);

        $gamma = Math::solvePolynomialComplex('gamma(x)=0', 'x');
        self::assertSame([], $gamma->roots);
        self::assertTrue($gamma->converged);

        $factorial = Math::solvePolynomialComplex('x! = 0', 'x');
        self::assertSame([], $factorial->roots);
        self::assertTrue($factorial->converged);
    }

    public function testPeriodicComplexZeroSetsReportInfiniteSolutions(): void
    {
        foreach (
            [
                'sin(x)=0', 'cos(x^2)=0', 'tan(2*x+1)=0', 'sinh(x)=0',
                'sinc(x)=0', 'sincpi(x^2)=0', 'expm1(x)=0',
                'besselj(0,x)=0', 'besselj(-1,x)=0',
                'besseli(1,x^2)=0', 'besseli(-1,x)=0',
                'bessely(0,x)=0', 'bessely(-1,x)=0',
            ] as $equation
        ) {
            try {
                Math::solvePolynomialComplex($equation, 'x');
                self::fail('Expected infinitely many complex roots for ' . $equation);
            } catch (InfiniteSolutionSetException $exception) {
                self::assertStringContainsString('infinitely many roots', $exception->getMessage());
            }
        }
    }

    public function testNonzeroGammaLevelsDistinguishInfiniteAndConstantSolutionSets(): void
    {
        foreach (['gamma(x)=1', '2=gamma(x^2)'] as $equation) {
            try {
                Math::solvePolynomialComplex($equation, 'x');
                self::fail('Expected infinitely many complex roots for ' . $equation);
            } catch (InfiniteSolutionSetException $exception) {
                self::assertStringContainsString('infinitely many roots', $exception->getMessage());
            }
        }

        self::assertSame([], Math::solvePolynomialComplex('gamma(1)=2', 'x')->roots);

        try {
            Math::solvePolynomialComplex('gamma(1)=1', 'x');
            self::fail('Expected a constant identity to have infinitely many solutions.');
        } catch (InfiniteSolutionSetException) {
            self::addToAssertionCount(1);
        }
    }

    public function testReciprocalTrigAndHyperbolicFunctionsHaveNoComplexZeros(): void
    {
        foreach (['sec(x)=0', 'csc(x)=0', 'sech(x)=0', 'csch(x)=0'] as $equation) {
            $solution = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame([], $solution->roots, $equation);
            self::assertTrue($solution->converged, $equation);
        }
    }

    public function testExactRationalCenteringRecoversClusteredComplexRoots(): void
    {
        $result = Math::solvePolynomialComplex(
            '1000000000000000000*x^6 - 3000000000000000000*x^5 + '
                . '3750001000000000000*x^4 - 2500002000000000000*x^3 + '
                . '937501500004000000*x^2 - 187500500004000000*x + '
                . '15625062501000004 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(6, $result->roots);
        $expected = [
            [0.499, -0.001], [0.499, 0.001],
            [0.5, -0.001], [0.5, 0.001],
            [0.501, -0.001], [0.501, 0.001],
        ];
        foreach ($expected as [$real, $imaginary]) {
            $matchingRoots = \array_filter(
                $result->roots,
                static fn ($root): bool => \abs($root->real - $real) <= 1.0E-5
                    && \abs($root->imaginary - $imaginary) <= 1.0E-5,
            );
            self::assertCount(1, $matchingRoots);
        }
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testQuadraticRootsAreInvariantAcrossExtremeCoefficientScales(): void
    {
        $expectedImaginary = \sqrt(3.0) / 2.0;
        foreach (
            [
                '1e308*x^2 + 1e308*x + 1e308 = 0',
                '1e-308*x^2 + 1e-308*x + 1e-308 = 0',
                '1e-320*x^2 + 1e-320*x + 1e-320 = 0',
                '5e-324*x^2 + 5e-324*x + 5e-324 = 0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            self::assertCount(2, $result->roots, $equation);
            self::assertEqualsWithDelta(-0.5, $result->roots[0]->real, 1.0E-14, $equation);
            self::assertEqualsWithDelta(-$expectedImaginary, $result->roots[0]->imaginary, 1.0E-14, $equation);
            self::assertEqualsWithDelta(-0.5, $result->roots[1]->real, 1.0E-14, $equation);
            self::assertEqualsWithDelta($expectedImaginary, $result->roots[1]->imaginary, 1.0E-14, $equation);
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testSparsePolynomialRootsSurviveExtremeCoefficientRatios(): void
    {
        foreach (
            [
                ['1e308*x^2 + 1e-308 = 0', 1.0E-308],
                ['1e-308*x^2 + 1e308 = 0', 1.0E308],
            ] as [$equation, $expectedRadius]
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            self::assertCount(2, $result->roots, $equation);
            self::assertSame(0.0, $result->roots[0]->real, $equation);
            self::assertEqualsWithDelta(
                -$expectedRadius,
                $result->roots[0]->imaginary,
                $expectedRadius * 1.0E-12,
                $equation,
            );
            self::assertSame(0.0, $result->roots[1]->real, $equation);
            self::assertEqualsWithDelta(
                $expectedRadius,
                $result->roots[1]->imaginary,
                $expectedRadius * 1.0E-12,
                $equation,
            );
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testNewtonPolygonSeedsAvoidLooseCauchyBoundForMixedRootScales(): void
    {
        $result = (new ComplexPolynomialSolver())->solve(
            [
                -556351448799.9152,
                7838394604.112857,
                8807511.520996563,
                -285058.35182076,
                -405.20000000000005,
                1.0,
            ],
            new SolverOptions(),
        );

        self::assertTrue($result->converged);
        self::assertCount(5, $result->roots);
        foreach (
            [
                [-273.0, -7.02],
                [-273.0, 7.02],
                [99.6, -0.0882],
                [99.6, 0.0882],
                [752.0, 0.0],
            ] as [$expectedReal, $expectedImaginary]
        ) {
            $matches = \array_filter(
                $result->roots,
                static fn ($root): bool => \abs($root->real - $expectedReal) <= 1.0E-8
                    && \abs($root->imaginary - $expectedImaginary) <= 1.0E-8,
            );
            self::assertCount(1, $matches);
        }
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testComplexRootsRemainCompleteAcrossSixteenOrdersOfMagnitude(): void
    {
        $result = (new ComplexPolynomialSolver())->solve(
            [1.0, -100000000.99999999, 99999998.99999999, 1.0],
            new SolverOptions(),
        );

        self::assertTrue($result->converged);
        self::assertCount(3, $result->roots);
        foreach ([-100000000.0, 1.0E-8, 1.0] as $expectedRoot) {
            $matches = \array_filter(
                $result->roots,
                static fn ($root): bool => \abs($root->real - $expectedRoot)
                    <= \max(1.0E-15, \abs($expectedRoot) * 1.0E-12)
                    && \abs($root->imaginary) <= 1.0E-10,
            );
            self::assertCount(1, $matches);
        }
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testScaledIterationHandlesGeneralPolynomialsWithExtremeCoefficientRatios(): void
    {
        $smallRoots = Math::solvePolynomialComplex(
            '1e308*x^3 + 1e-308*x + 1e-308 = 0',
            'x',
        );
        self::assertTrue($smallRoots->converged);
        self::assertCount(3, $smallRoots->roots);
        foreach ($smallRoots->roots as $root) {
            self::assertEqualsWithDelta(
                4.641588833612778E-206,
                \hypot($root->real, $root->imaginary),
                1.0E-215,
            );
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }

        $separatedRoots = Math::solvePolynomialComplex(
            '1e-308*x^3 + 1e308*x + 1e308 = 0',
            'x',
        );
        self::assertTrue($separatedRoots->converged);
        self::assertCount(3, $separatedRoots->roots);
        $unitRoot = \array_values(\array_filter(
            $separatedRoots->roots,
            static fn ($root): bool => \abs($root->real + 1.0) < 1.0E-12
                && \abs($root->imaginary) < 1.0E-12,
        ));
        self::assertCount(1, $unitRoot);
        $largeRoots = \array_values(\array_filter(
            $separatedRoots->roots,
            static fn ($root): bool => \hypot($root->real, $root->imaginary) > 1.0E100,
        ));
        self::assertCount(2, $largeRoots);
        foreach ($largeRoots as $root) {
            self::assertEqualsWithDelta(1.0E308, \hypot($root->real, $root->imaginary), 1.0E296);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactVariableScalingPreservesRootsFromUnderflowedCoefficients(): void
    {
        foreach (
            [
                'x^4 + 2e-200*x^2 + 1e-400 = 0',
                '(x^2 + 1e-200)^2 = 0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            self::assertSame(4, $result->degree, $equation);
            self::assertCount(4, $result->roots, $equation);
            foreach ($result->roots as $root) {
                self::assertSame(0.0, $root->real, $equation);
                self::assertEqualsWithDelta(1.0E-100, \abs($root->imaginary), 1.0E-112, $equation);
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testExactVariableScalingPreservesMultiplicityForIrrationalRootScales(): void
    {
        $result = Math::solvePolynomialComplex('(x^2 + 3e-200)^2 = 0', 'x');
        $expectedMagnitude = \sqrt(3.0) * 1.0E-100;

        self::assertTrue($result->converged);
        self::assertSame(4, $result->degree);
        self::assertCount(4, $result->roots);
        self::assertSame($result->roots[0]->imaginary, $result->roots[1]->imaginary);
        self::assertSame($result->roots[2]->imaginary, $result->roots[3]->imaginary);
        self::assertLessThan(0.0, $result->roots[0]->imaginary);
        self::assertGreaterThan(0.0, $result->roots[2]->imaginary);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->real);
            self::assertEqualsWithDelta($expectedMagnitude, \abs($root->imaginary), 1.0E-112);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactVariableScalingPreservesZeroAndNonzeroRootMultiplicity(): void
    {
        $result = Math::solvePolynomialComplex('x^2*(x^2 + 3e-200)^2 = 0', 'x');
        $expectedMagnitude = \sqrt(3.0) * 1.0E-100;

        self::assertTrue($result->converged);
        self::assertSame(6, $result->degree);
        self::assertCount(6, $result->roots);
        $zeroRoots = \array_values(\array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 0.0 && $root->imaginary === 0.0,
        ));
        self::assertCount(2, $zeroRoots);
        $nonzeroRoots = \array_values(\array_filter(
            $result->roots,
            static fn ($root): bool => $root->imaginary !== 0.0,
        ));
        self::assertCount(4, $nonzeroRoots);
        self::assertSame($nonzeroRoots[0]->imaginary, $nonzeroRoots[1]->imaginary);
        self::assertSame($nonzeroRoots[2]->imaginary, $nonzeroRoots[3]->imaginary);
        foreach ($nonzeroRoots as $root) {
            self::assertSame(0.0, $root->real);
            self::assertEqualsWithDelta($expectedMagnitude, \abs($root->imaginary), 1.0E-112);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExponentLatticeReductionAvoidsOverflowAcrossRootScales(): void
    {
        $result = Math::solvePolynomialComplex('x^4 + 1e308*x^2 + 1 = 0', 'x');

        self::assertTrue($result->converged);
        self::assertCount(4, $result->roots);
        self::assertSame(0.0, $result->roots[0]->real);
        self::assertEqualsWithDelta(-1.0E154, $result->roots[0]->imaginary, 1.0E140);
        self::assertSame(0.0, $result->roots[1]->real);
        self::assertEqualsWithDelta(-1.0E-154, $result->roots[1]->imaginary, 1.0E-167);
        self::assertSame(0.0, $result->roots[2]->real);
        self::assertEqualsWithDelta(1.0E-154, $result->roots[2]->imaginary, 1.0E-167);
        self::assertSame(0.0, $result->roots[3]->real);
        self::assertEqualsWithDelta(1.0E154, $result->roots[3]->imaginary, 1.0E140);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testNewtonPolygonInitializationSolvesSeparatedRootScales(): void
    {
        $result = Math::solvePolynomialComplex('x^5 + 1e308*x^2 + 1 = 0', 'x');

        self::assertTrue($result->converged);
        self::assertCount(5, $result->roots);
        self::assertEqualsWithDelta(-4.641588833612778E102, $result->roots[0]->real, 1.0E89);
        self::assertEqualsWithDelta(1.0E-154, $result->roots[1]->imaginary, 1.0E-167);
        self::assertEqualsWithDelta(-1.0E-154, $result->roots[2]->imaginary, 1.0E-167);
        foreach ([3, 4] as $index) {
            self::assertEqualsWithDelta(
                4.641588833612778E102,
                \hypot($result->roots[$index]->real, $result->roots[$index]->imaginary),
                1.0E89,
            );
        }
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testScaledIterationRecoversExactRepeatedRoots(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^4 + 999999999998*x^3 - 1999999999998*x^2 + 999999999998*x + 1 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(4, $result->roots);
        $repeatedRoots = \array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 1.0 && $root->imaginary === 0.0,
        );
        self::assertCount(2, $repeatedRoots);
        self::assertEqualsWithDelta(-1.0E12, $result->roots[0]->real, 1.0E-2);
        self::assertEqualsWithDelta(-1.0E-12, $result->roots[1]->real, 1.0E-24);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactDecimalCoefficientsPreserveHighPrecisionTripleRoot(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^3 - 0.370370367037037034*x^2 '
                . '+ 0.045724736259716509583904899295839052*x '
                . '- 0.001881676372353657731338003115679818096684294558605752 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(3, $result->roots);
        foreach ($result->roots as $root) {
            self::assertEqualsWithDelta(0.123456789012345678, $root->real, 1.0E-14);
            self::assertEqualsWithDelta(0.0, $root->imaginary, 1.0E-14);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testQuadraticHandlesWidelySeparatedRealRootsWithoutCancellation(): void
    {
        foreach (
            [
                'x^2 + 1e308*x + 1 = 0' => [-1.0E308, -1.0E-308],
                '1e-308*x^2 + x + 1 = 0' => [-1.0E308, -1.0],
                'x^2 - 2*x + 1 = 0' => [1.0, 1.0],
            ] as $equation => [$firstExpected, $secondExpected]
        ) {
            $result = Math::solvePolynomialComplex(
                $equation,
                'x',
                solverOptions: new SolverOptions(maxIterations: 1),
            );

            self::assertSame(2, $result->degree, $equation);
            self::assertSame(0, $result->iterations, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount(2, $result->roots, $equation);
            self::assertEqualsWithDelta(
                $firstExpected,
                $result->roots[0]->real,
                \abs($firstExpected) * 1.0E-14,
                $equation,
            );
            self::assertSame(0.0, $result->roots[0]->imaginary, $equation);
            self::assertEqualsWithDelta(
                $secondExpected,
                $result->roots[1]->real,
                \max(1.0E-320, \abs($secondExpected) * 1.0E-14),
                $equation,
            );
            self::assertSame(0.0, $result->roots[1]->imaginary, $equation);
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testQuadraticPreservesVeryCloseDistinctRootsFromExactDecimals(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^2 - 2.000000000001*x + 1.000000000001 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(2, $result->roots);
        self::assertEqualsWithDelta(1.0, $result->roots[0]->real, 1.0E-13);
        self::assertEqualsWithDelta(1.000000000001, $result->roots[1]->real, 1.0E-13);
        self::assertNotSame($result->roots[0]->real, $result->roots[1]->real);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testQuadraticPreservesAdjacentRootsFromLargeExactIntegerCoefficients(): void
    {
        $result = Math::solvePolynomialComplex(
            '9007199254740993*x^2 - 18014398509481985*x + 9007199254740992 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(2, $result->roots);
        self::assertSame(0.9999999999999999, $result->roots[0]->real);
        self::assertSame(1.0, $result->roots[1]->real);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactSquareDiscriminantWithLargeIntegerRootIsReducedExactly(): void
    {
        $result = Math::solvePolynomialComplex(
            '10000000000000000*x^2 - 20000000100000000*x + 10000000100000000 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertSame(1.0, $result->roots[0]->real);
        self::assertSame(1.00000001, $result->roots[1]->real);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testCloseRootsWithNonsquareLargeIntegerDiscriminantAreAccuratelyLocated(): void
    {
        $result = Math::solvePolynomialComplex(
            '10000000099999999*x^2 + 20000000399999999*x + 10000000300000001 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertSame(-1.0000000100000002, $result->roots[0]->real);
        self::assertSame(-1.0000000099999999, $result->roots[1]->real);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactQuadraticCoefficientsRemainAlignedAfterZeroRootRemoval(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^3 - 2.000000000001*x^2 + 1.000000000001*x = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertSame(3, $result->degree);
        self::assertCount(3, $result->roots);
        foreach ([0.0, 1.0, 1.000000000001] as $index => $expectedRoot) {
            self::assertEqualsWithDelta($expectedRoot, $result->roots[$index]->real, 1.0E-13);
            self::assertSame(0.0, $result->roots[$index]->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $result->roots[$index]->residual);
        }
    }

    public function testCubicReturnsAllRootsIncludingTheRealRoot(): void
    {
        $result = Math::solvePolynomialComplex('x^3 + 1 = 0', 'x');

        self::assertSame(3, $result->degree);
        self::assertTrue($result->converged);
        self::assertCount(3, $result->roots);
        self::assertEqualsWithDelta(-1.0, $result->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(0.0, $result->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.5, $result->roots[1]->real, 1.0E-10);
        self::assertEqualsWithDelta(-\sqrt(3.0) / 2.0, $result->roots[1]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.5, $result->roots[2]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(3.0) / 2.0, $result->roots[2]->imaginary, 1.0E-10);
    }

    public function testExactPositiveCubicDiscriminantPreservesThreeClusteredRealRoots(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^3 - 3.000003*x^2 + 3.000006000002*x - 1.000003000002 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertSame(3, $result->degree);
        self::assertCount(3, $result->roots);
        foreach ([1.0, 1.000001, 1.000002] as $index => $expectedRoot) {
            self::assertEqualsWithDelta($expectedRoot, $result->roots[$index]->real, 1.0E-9);
            self::assertSame(0.0, $result->roots[$index]->imaginary);
            self::assertLessThanOrEqual(1.0E-10, $result->roots[$index]->residual);
        }
    }

    public function testQuarticReturnsTwoComplexConjugatePairs(): void
    {
        $result = Math::solvePolynomialComplex('x^4 + 1 = 0', 'x');
        $component = 1.0 / \sqrt(2.0);

        self::assertSame(4, $result->degree);
        self::assertTrue($result->converged);
        self::assertCount(4, $result->roots);
        foreach ($result->roots as $root) {
            self::assertEqualsWithDelta($component, \abs($root->real), 1.0E-10);
            self::assertEqualsWithDelta($component, \abs($root->imaginary), 1.0E-10);
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testHighDegreeBinomialRootsUseTheClosedForm(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^129 - 1 = 0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 256),
        );

        self::assertSame(129, $result->degree);
        self::assertSame(0, $result->iterations);
        self::assertTrue($result->converged);
        self::assertCount(129, $result->roots);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
        self::assertEqualsWithDelta(1.0, $result->roots[128]->real, 1.0E-12);
        self::assertEqualsWithDelta(0.0, $result->roots[128]->imaginary, 1.0E-12);
    }

    public function testMonomialReturnsItsRepeatedZeroRootWithoutIteration(): void
    {
        $result = Math::solvePolynomialComplex('x^12 = 0', 'x');

        self::assertSame(12, $result->degree);
        self::assertSame(0, $result->iterations);
        self::assertTrue($result->converged);
        self::assertCount(12, $result->roots);
        foreach ($result->roots as $root) {
            self::assertSame(0.0, $root->real);
            self::assertSame(0.0, $root->imaginary);
            self::assertSame(0.0, $root->residual);
        }
    }

    public function testGeneralPolynomialFactorsRepeatedZeroRootsExactly(): void
    {
        $result = Math::solvePolynomialComplex('x^2 * (x^3 + x + 1) = 0', 'x');

        self::assertSame(5, $result->degree);
        self::assertTrue($result->converged);
        self::assertCount(5, $result->roots);
        $zeroRoots = \array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 0.0 && $root->imaginary === 0.0,
        );
        self::assertCount(2, $zeroRoots);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }

        $expandedOffset = Math::solvePolynomialComplex('x^5 + x^4 + x^3 = 0', 'x');
        self::assertTrue($expandedOffset->converged);
        self::assertCount(5, $expandedOffset->roots);
        $expandedZeroRoots = \array_filter(
            $expandedOffset->roots,
            static fn ($root): bool => $root->real === 0.0 && $root->imaginary === 0.0,
        );
        self::assertCount(3, $expandedZeroRoots);
        foreach ($expandedOffset->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testProductFactorsPreserveRepeatedRootsAcrossCoefficientExpansion(): void
    {
        $result = Math::solvePolynomialComplex(
            '(x - 1)^2 * (x - 3) * (x + 4) * (x^2 + 1) = 0',
            'x',
        );

        self::assertSame(6, $result->degree);
        self::assertTrue($result->converged);
        $rootsAtOne = \array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 1.0 && $root->imaginary === 0.0,
        );
        self::assertCount(2, $rootsAtOne);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testAberthFallbackRecoversRootsAcrossLargeModulusRanges(): void
    {
        $result = Math::solvePolynomialComplex(
            '(x - 1000000) * (x - 1) * (x + 0.000001) * (x^2 + 1) = 0',
            'x',
        );

        self::assertSame(5, $result->degree);
        self::assertTrue($result->converged);
        self::assertCount(5, $result->roots);
        $expectedRoots = [
            [-0.000001, 0.0],
            [0.0, -1.0],
            [0.0, 1.0],
            [1.0, 0.0],
            [1_000_000.0, 0.0],
        ];
        foreach ($expectedRoots as [$real, $imaginary]) {
            $minimumDistance = \INF;
            foreach ($result->roots as $root) {
                $minimumDistance = \min(
                    $minimumDistance,
                    \hypot($root->real - $real, $root->imaginary - $imaginary),
                );
            }
            self::assertLessThanOrEqual(1.0E-7, $minimumDistance);
        }
        $largeRoot = \array_values(\array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 1_000_000.0 && $root->imaginary === 0.0,
        ));
        self::assertCount(1, $largeRoot);
        self::assertGreaterThan(0.0, $largeRoot[0]->residual);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testExactCenteringResolvesCloselyClusteredComplexRoots(): void
    {
        foreach (
            [
                [
                    'x^6 - 6*x^5 + 15.000001*x^4 - 20.000004*x^3 '
                        . '+ 15.000006000004*x^2 - 6.000004000008*x '
                        . '+ 1.000001000004000004 = 0',
                    1.0E-3,
                ],
                [
                    'x^6 - 6*x^5 + 15.00000001*x^4 - 20.00000004*x^3 '
                        . '+ 15.0000000600000004*x^2 - 6.0000000400000008*x '
                        . '+ 1.000000010000000400000004 = 0',
                    1.0E-4,
                ],
            ] as [$equation, $spacing]
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            self::assertCount(6, $result->roots, $equation);
            $expectedRoots = [];
            foreach ([1.0 - $spacing, 1.0, 1.0 + $spacing] as $real) {
                $expectedRoots[] = [$real, -$spacing];
                $expectedRoots[] = [$real, $spacing];
            }
            foreach ($expectedRoots as [$real, $imaginary]) {
                $matches = \array_filter(
                    $result->roots,
                    static fn ($root): bool => \hypot(
                        $root->real - $real,
                        $root->imaginary - $imaginary,
                    ) <= 2.0E-8,
                );
                self::assertCount(1, $matches, $equation);
            }
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testMaximumDegreeBinomialReturnsAllRoots(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^256 - 1 = 0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 256),
        );

        self::assertSame(256, $result->degree);
        self::assertSame(0, $result->iterations);
        self::assertTrue($result->converged);
        self::assertCount(256, $result->roots);
        self::assertEqualsWithDelta(1.0, $result->roots[255]->real, 1.0E-12);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testHighDegreeTwoTermPolynomialFactorsRepeatedZeroRoots(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^129 - x = 0',
            'x',
            solverOptions: new SolverOptions(maxPolynomialDegree: 256),
        );

        self::assertSame(129, $result->degree);
        self::assertSame(0, $result->iterations);
        self::assertTrue($result->converged);
        self::assertCount(129, $result->roots);
        $zeroRoots = \array_filter(
            $result->roots,
            static fn ($root): bool => $root->real === 0.0 && $root->imaginary === 0.0,
        );
        self::assertCount(1, $zeroRoots);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testRepeatedLinearFactorIsReconstructedBeforeIteration(): void
    {
        foreach (
            [
                'x^4 - 4*x^3 + 6*x^2 - 4*x + 1 = 0' => 1.0,
                '(x - 0.5)^4 = 0' => 0.5,
            ] as $equation => $expectedRoot
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame(4, $result->degree, $equation);
            self::assertSame(0, $result->iterations, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount(4, $result->roots, $equation);
            foreach ($result->roots as $root) {
                self::assertEqualsWithDelta($expectedRoot, $root->real, 1.0E-12, $equation);
                self::assertSame(0.0, $root->imaginary, $equation);
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testExactCubicDiscriminantPreventsMergingDistinctNearbyRoots(): void
    {
        $repeated = Math::solvePolynomialComplex(
            'x^3 - 0.369*x^2 + 0.045387*x - 0.001860867 = 0',
            'x',
        );
        self::assertTrue($repeated->converged);
        self::assertCount(3, $repeated->roots);
        foreach ($repeated->roots as $root) {
            self::assertSame(0.123, $root->real);
            self::assertSame(0.0, $root->imaginary);
        }

        $distinct = Math::solvePolynomialComplex(
            'x^3 - 0.369*x^2 + 0.045387*x - 0.001860867000000001 = 0',
            'x',
        );
        self::assertTrue($distinct->converged);
        self::assertCount(3, $distinct->roots);
        self::assertEqualsWithDelta(0.1229995, $distinct->roots[0]->real, 1.0E-10);
        self::assertEqualsWithDelta(-\sqrt(3.0) / 2.0 * 1.0E-6, $distinct->roots[0]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.1229995, $distinct->roots[1]->real, 1.0E-10);
        self::assertEqualsWithDelta(\sqrt(3.0) / 2.0 * 1.0E-6, $distinct->roots[1]->imaginary, 1.0E-10);
        self::assertEqualsWithDelta(0.123001, $distinct->roots[2]->real, 1.0E-10);
        self::assertSame(0.0, $distinct->roots[2]->imaginary);
        foreach ($distinct->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }

        $perturbedQuartic = Math::solvePolynomialComplex(
            'x^4 - 4*x^3 + 6*x^2 - 4*x + 1.000000000000001 = 0',
            'x',
        );
        self::assertTrue($perturbedQuartic->converged);
        self::assertCount(4, $perturbedQuartic->roots);
        foreach ($perturbedQuartic->roots as $root) {
            self::assertGreaterThan(1.0E-5, \abs($root->imaginary));
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }

        $perturbedQuintic = Math::solvePolynomialComplex(
            'x^5 - 5*x^4 + 10*x^3 - 10*x^2 + 5*x - 1.000000000000001 = 0',
            'x',
        );
        self::assertTrue($perturbedQuintic->converged);
        self::assertCount(5, $perturbedQuintic->roots);
        self::assertGreaterThan(
            1.0E-4,
            $perturbedQuintic->roots[4]->real - $perturbedQuintic->roots[0]->real,
        );
        foreach ($perturbedQuintic->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testSquareFreePolynomialSkipsUnstableDerivativeMultiplicityRecovery(): void
    {
        foreach (
            [
                '-87*x^3 + 46*x^2 + 47*x - 66 = 0',
                '87*x^3 - 46*x^2 - 47*x + 66 = 0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame(3, $result->degree, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount(3, $result->roots, $equation);
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testRepeatedQuadraticFactorReturnsRepeatedComplexRoots(): void
    {
        foreach (
            [
                'x^4 + 2*x^2 + 1 = 0' => [[0.0, 0.0, 0.0, 0.0], [-1.0, -1.0, 1.0, 1.0]],
                'x^6 + 3*x^4 + 3*x^2 + 1 = 0' => [
                    [0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                    [-1.0, -1.0, -1.0, 1.0, 1.0, 1.0],
                ],
                'x^4 - 6*x^3 + 13*x^2 - 12*x + 4 = 0' => [
                    [1.0, 1.0, 2.0, 2.0],
                    [0.0, 0.0, 0.0, 0.0],
                ],
                'x^4 - 4*x^3 + 14*x^2 - 20*x + 25 = 0' => [
                    [1.0, 1.0, 1.0, 1.0],
                    [-2.0, -2.0, 2.0, 2.0],
                ],
                'x^4 - 4*x^3 + 6*x^2 - 4*x + 1 = 0' => [
                    [1.0, 1.0, 1.0, 1.0],
                    [0.0, 0.0, 0.0, 0.0],
                ],
            ] as $equation => [$expectedReal, $expectedImaginary]
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame(0, $result->iterations, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount($result->degree, $result->roots, $equation);
            foreach ($result->roots as $index => $root) {
                self::assertEqualsWithDelta($expectedReal[$index], $root->real, 1.0E-12, $equation);
                self::assertEqualsWithDelta(
                    \abs($expectedImaginary[$index]),
                    \abs($root->imaginary),
                    1.0E-12,
                    $equation,
                );
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testExpandedPerfectSquarePolynomialPreservesHigherDegreeRootMultiplicity(): void
    {
        foreach (
            [
                'x^6 + 2*x^4 + 2*x^3 + x^2 + 2*x + 1 = 0',
                '-x^6 - 2*x^4 - 2*x^3 - x^2 - 2*x - 1 = 0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame(6, $result->degree, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount(6, $result->roots, $equation);
            self::assertLessThan(175, $result->iterations, $equation);
            foreach (
                [
                    [-0.6823278038280193, 0.0],
                    [-0.6823278038280193, 0.0],
                    [0.3411639019140097, -1.161541399997252],
                    [0.3411639019140097, -1.161541399997252],
                    [0.3411639019140097, 1.161541399997252],
                    [0.3411639019140097, 1.161541399997252],
                ] as [$real, $imaginary]
            ) {
                $matches = \array_filter(
                    $result->roots,
                    static fn ($root): bool => \hypot(
                        $root->real - $real,
                        $root->imaginary - $imaginary,
                    ) <= 1.0E-10,
                );
                self::assertCount(2, $matches, $equation);
            }
            for ($index = 0; $index < 6; $index += 2) {
                self::assertSame(
                    $result->roots[$index]->real,
                    $result->roots[$index + 1]->real,
                    $equation,
                );
                self::assertSame(
                    $result->roots[$index]->imaginary,
                    $result->roots[$index + 1]->imaginary,
                    $equation,
                );
            }
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testExpandedPerfectCubePolynomialPreservesHigherDegreeRootMultiplicity(): void
    {
        foreach (
            [
                'x^9 + 3*x^7 + 3*x^6 + 3*x^5 + 6*x^4 + 4*x^3 + 3*x^2 + 3*x + 1 = 0',
                '-x^9 - 3*x^7 - 3*x^6 - 3*x^5 - 6*x^4 - 4*x^3 - 3*x^2 - 3*x - 1 = 0',
            ] as $equation
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertSame(9, $result->degree, $equation);
            self::assertTrue($result->converged, $equation);
            self::assertCount(9, $result->roots, $equation);
            self::assertLessThan(175, $result->iterations, $equation);
            foreach (
                [
                    [-0.6823278038280193, 0.0],
                    [-0.6823278038280193, 0.0],
                    [-0.6823278038280193, 0.0],
                    [0.3411639019140097, -1.161541399997252],
                    [0.3411639019140097, -1.161541399997252],
                    [0.3411639019140097, -1.161541399997252],
                    [0.3411639019140097, 1.161541399997252],
                    [0.3411639019140097, 1.161541399997252],
                    [0.3411639019140097, 1.161541399997252],
                ] as $index => [$real, $imaginary]
            ) {
                self::assertEqualsWithDelta($real, $result->roots[$index]->real, 1.0E-10, $equation);
                self::assertEqualsWithDelta(
                    $imaginary,
                    $result->roots[$index]->imaginary,
                    1.0E-10,
                    $equation,
                );
            }
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testMixedRepeatedFactorsArePolishedAtCommonDerivativeRoots(): void
    {
        foreach (
            [
                'x^4 - 2*x^3 + 2*x^2 - 2*x + 1 = 0' => [
                    [[0.0, -1.0], 1],
                    [[0.0, 1.0], 1],
                    [[1.0, 0.0], 2],
                ],
                'x^5 - 3*x^4 + 2*x^3 - 6*x^2 + x - 3 = 0' => [
                    [[0.0, -1.0], 2],
                    [[0.0, 1.0], 2],
                    [[3.0, 0.0], 1],
                ],
            ] as $equation => $expectedRoots
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            self::assertSame(\array_sum(\array_column($expectedRoots, 1)), \count($result->roots));
            foreach ($expectedRoots as [[$real, $imaginary], $multiplicity]) {
                $matchingRoots = \array_filter(
                    $result->roots,
                    static fn ($root): bool => \hypot(
                        $root->real - $real,
                        $root->imaginary - $imaginary,
                    ) <= 1.0E-10,
                );
                self::assertCount($multiplicity, $matchingRoots, $equation);
            }
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testHigherMixedRootMultiplicitiesAreRecoveredFromHigherDerivatives(): void
    {
        foreach (
            [
                'x^6 - 4*x^5 + 7*x^4 - 8*x^3 + 7*x^2 - 4*x + 1 = 0' => [
                    [[0.0, -1.0], 1],
                    [[0.0, 1.0], 1],
                    [[1.0, 0.0], 4],
                ],
                'x^7 - 3*x^6 + 3*x^5 - 9*x^4 + 3*x^3 - 9*x^2 + x - 3 = 0' => [
                    [[0.0, -1.0], 3],
                    [[0.0, 1.0], 3],
                    [[3.0, 0.0], 1],
                ],
            ] as $equation => $expectedRoots
        ) {
            $result = Math::solvePolynomialComplex($equation, 'x');

            self::assertTrue($result->converged, $equation);
            foreach ($expectedRoots as [[$real, $imaginary], $multiplicity]) {
                $matchingRoots = \array_filter(
                    $result->roots,
                    static fn ($root): bool => \hypot(
                        $root->real - $real,
                        $root->imaginary - $imaginary,
                    ) <= 1.0E-10,
                );
                self::assertCount($multiplicity, $matchingRoots, $equation);
            }
            foreach ($result->roots as $root) {
                self::assertLessThanOrEqual(1.0E-10, $root->residual, $equation);
            }
        }
    }

    public function testCloseDistinctRootsAreNotMergedByDerivativeValidation(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^4 - 2.00001*x^3 + 2.00001*x^2 - 2.00001*x + 1.00001 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertCount(4, $result->roots);
        self::assertEqualsWithDelta(1.0, $result->roots[2]->real, 1.0E-8);
        self::assertEqualsWithDelta(1.00001, $result->roots[3]->real, 1.0E-8);
        self::assertNotSame($result->roots[2]->real, $result->roots[3]->real);
    }

    public function testSimpleRootNearRepeatedRootRemainsDistinctWithRoundedCoefficients(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^5 - 3.00001*x^4 + 4.00002*x^3 - 4.00002*x^2 + 3.00002*x - 1.00001 = 0',
            'x',
        );

        self::assertTrue($result->converged);
        self::assertSame(5, $result->degree);
        self::assertCount(5, $result->roots);
        foreach (
            [
                [[0.0, -1.0], 1],
                [[0.0, 1.0], 1],
                [[1.0, 0.0], 2],
            ] as [[$real, $imaginary], $multiplicity]
        ) {
            $matchingRoots = \array_filter(
                $result->roots,
                static fn ($root): bool => \hypot(
                    $root->real - $real,
                    $root->imaginary - $imaginary,
                ) <= 1.0E-9,
            );
            self::assertCount($multiplicity, $matchingRoots);
        }
        $nearbySimpleRoots = \array_filter(
            $result->roots,
            static fn ($root): bool => \hypot($root->real - 1.00001, $root->imaginary) <= 1.0E-10,
        );
        self::assertCount(1, $nearbySimpleRoots);
        self::assertNotSame(1.0, \array_values($nearbySimpleRoots)[0]->real);
        foreach ($result->roots as $root) {
            self::assertLessThanOrEqual(1.0E-10, $root->residual);
        }
    }

    public function testNearbyDistinctQuadraticRootsDoNotUseRepeatedFactorShortcut(): void
    {
        $result = Math::solvePolynomialComplex(
            'x^4 + 2.000002*x^3 + 1.000003000001*x^2 + 0.000002000003*x + 0.000000000002000002 = 0',
            'x',
        );

        self::assertGreaterThan(0, $result->iterations);
    }

    public function testUsesExactIntegerCoefficientDifferencesAndFixedParameters(): void
    {
        $result = Math::solvePolynomialComplex(
            '9007199254740993*x^2 = 9007199254740992*x^2 + 2',
            'x',
        );

        self::assertSame(2, $result->degree);
        self::assertTrue($result->converged);
        self::assertEqualsWithDelta(-\sqrt(2.0), $result->roots[0]->real, 1.0E-9);
        self::assertEqualsWithDelta(0.0, $result->roots[0]->imaginary, 1.0E-9);
        self::assertEqualsWithDelta(\sqrt(2.0), $result->roots[1]->real, 1.0E-9);
        self::assertEqualsWithDelta(0.0, $result->roots[1]->imaginary, 1.0E-9);

        $parameterized = Math::solvePolynomialComplex(
            'scale*x^2 + 1 = 0',
            'x',
            ['scale' => 2],
        );
        self::assertTrue($parameterized->converged);
        self::assertEqualsWithDelta(1.0 / \sqrt(2.0), \abs($parameterized->roots[0]->imaginary), 1.0E-10);
    }

    public function testExactRepeatedRootsDoNotDependOnIterationBudget(): void
    {
        $result = Math::solvePolynomialComplex(
            '(x - 1)^2 = 0',
            'x',
            solverOptions: new SolverOptions(maxIterations: 1),
        );

        self::assertSame(2, $result->degree);
        self::assertCount(2, $result->roots);
        self::assertTrue($result->converged);
        self::assertSame(0, $result->iterations);
        foreach ($result->roots as $root) {
            self::assertSame(1.0, $root->real);
            self::assertSame(0.0, $root->imaginary);
            self::assertSame(0.0, $root->residual);
        }
    }

    public function testConstantAndIdentityPolynomialsAreDistinguished(): void
    {
        $noRoots = Math::solvePolynomialComplex('2 = 3', 'x');
        self::assertSame([], $noRoots->roots);
        self::assertSame(0, $noRoots->degree);
        self::assertTrue($noRoots->converged);

        $this->expectException(InfiniteSolutionSetException::class);
        Math::solvePolynomialComplex('x = x', 'x');
    }

    public function testLinearPolynomialUsesItsClosedFormRoot(): void
    {
        $result = Math::solvePolynomialComplex('2*x = 3', 'x');

        self::assertTrue($result->converged);
        self::assertSame(1, $result->degree);
        self::assertSame(0, $result->iterations);
        self::assertSame(1.5, $result->roots[0]->real);
        self::assertSame(0.0, $result->roots[0]->imaginary);
        self::assertSame(0.0, $result->roots[0]->residual);
    }

    public function testRejectsNonPolynomialEquations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Math::solvePolynomialComplex('sin(x) = x', 'x');
    }
}
