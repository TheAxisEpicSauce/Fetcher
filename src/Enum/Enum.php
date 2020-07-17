<?php
/**
 * User: Raphael Pelissier
 * Date: 6-3-19
 * Time: 15:54
 */

namespace Fetcher\Enums;


use InvalidArgumentException;
use ReflectionClass;

abstract class Enum
{
    private static $constCacheArray = NULL;

    private static function getConstants()
    {
        if (self::$constCacheArray == NULL) {
            self::$constCacheArray = [];
        }
        $calledClass = get_called_class();
        if (!array_key_exists($calledClass, self::$constCacheArray)) {
            $reflect = new ReflectionClass($calledClass);
            self::$constCacheArray[$calledClass] = $reflect->getConstants();
        }
        return self::$constCacheArray[$calledClass];
    }

    public static function isValidName($name, $strict = false)
    {
        $constants = self::getConstants();

        if ($strict) {
            return array_key_exists($name, $constants);
        }

        $keys = array_map('strtolower', array_keys($constants));
        return in_array(strtolower($name), $keys);
    }

    public static function isValidValue($value, $strict = true)
    {
        $values = array_values(self::getConstants());
        return in_array($value, $values, $strict);
    }

    public static function validateValue($value, $pos = null)
    {
        if (!static::isValidValue($value)) throw new InvalidArgumentException(sprintf(
            '%s should be of type %s, % given', ($pos?'argument '.$pos:'value'),static::class, $value
        ));
    }

    public static function toArray()
    {
        return self::getConstants();
    }
}
