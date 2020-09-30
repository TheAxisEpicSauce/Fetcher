<?php
/**
 * User: Raphael Pelissier
 * Date: 04-08-20
 * Time: 11:12
 */

namespace Fetcher\Field;


use Fetcher\Enums\Enum;

class Operator extends Enum
{
    CONST EQUALS = '=';
    CONST NOT_EQUALS = '!=';
    CONST GREATER = '>';
    CONST GREATER_OR_EQUAL = '>=';
    CONST LESS = '<';
    CONST LESS_OR_EQUAL = '<=';
    CONST LIKE = 'LIKE';
    CONST IN = 'IN';
    CONST IN_LIKE = 'IN_LIKE';
    CONST NOT_IN = 'NOT_IN';
}
