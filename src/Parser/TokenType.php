<?php

declare(strict_types=1);

namespace MathPHP\Parser;

enum TokenType: string
{
    case Number = 'number';
    case Identifier = 'identifier';
    case Plus = '+';
    case Minus = '-';
    case Multiply = '*';
    case Divide = '/';
    case Modulo = '%';
    case Power = '^';
    case Factorial = '!';
    case LeftParenthesis = '(';
    case RightParenthesis = ')';
    case Comma = ',';
    case EndOfInput = 'eof';
}
