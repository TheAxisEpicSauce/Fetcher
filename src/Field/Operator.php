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

    CONST EQUALS_FIELD = '$=';
    CONST NOT_EQUALS_FIELD = '$!=';
    CONST GREATER_FIELD = '$>';
    CONST GREATER_OR_EQUAL_FIELD = '$>=';
    CONST LESS_FIELD = '$<';
    CONST LESS_OR_EQUAL_FIELD = '$<=';

    CONST LIKE = 'LIKE';
    CONST IN = 'IN';
    CONST IN_LIKE = 'IN LIKE';
    CONST NOT_IN = 'NOT IN';

    public static function IsFieldOperator(string $operator): bool
    {
        return ($operator === self::EQUALS_FIELD ||
            $operator === self::NOT_EQUALS_FIELD ||
            $operator === self::GREATER_FIELD ||
            $operator === self::GREATER_OR_EQUAL_FIELD ||
            $operator === self::LESS_FIELD ||
            $operator === self::LESS_OR_EQUAL_FIELD
        );

    }
}
