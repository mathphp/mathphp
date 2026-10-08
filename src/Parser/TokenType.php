<?php

declare(strict_types=1);

namespace MathPHP\Parser;

enum TokenType: string
{
    case Number = 'number';
    case MinimumIntegerMagnitude = 'minimum_integer_magnitude';
    case Identifier = 'identifier';
    case Plus = '+';
    case Minus = '-';
    case Multiply = '*';
    case Divide = '/';
    case Modulo = '%';
    case Power = '^';
    case Superscript = 'superscript';
    case SquareRoot = 'square_root';
    case Factorial = '!';
    case LeftParenthesis = '(';
    case RightParenthesis = ')';
    case Comma = ',';
    case AbsoluteValueBar = '|';
    case FloorLeft = '⌊';
    case FloorRight = '⌋';
    case CeilingLeft = '⌈';
    case CeilingRight = '⌉';
    case EndOfInput = 'eof';
}
