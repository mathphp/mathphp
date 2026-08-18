<?php

declare(strict_types=1);

namespace MathPHP\Ast;

enum BinaryOperator: string
{
    case Add = '+';
    case Subtract = '-';
    case Multiply = '*';
    case Divide = '/';
    case Modulo = '%';
    case Power = '^';
}
