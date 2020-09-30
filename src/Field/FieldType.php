<?php
/**
 * User: Raphael Pelissier
 * Date: 18-05-20
 * Time: 16:43
 */

namespace Fetcher\Field;

use Fetcher\Enums\Enum;

class FieldType extends Enum
{
    CONST INT = 'int';
    CONST STRING = 'string';
    CONST FLOAT = 'float';
    CONST BOOLEAN = 'boolean';
    CONST DATE = 'date';
    CONST DATE_TIME = 'date_time';
}
