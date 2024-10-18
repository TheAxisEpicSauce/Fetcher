<?php

namespace Fetcher\Helper;

class Str
{
    public static function studly($value): array|string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    public static function splitJoin(string $join)
    {
        $joinUpper = strtoupper($join);
        $joinUpper = str_replace('`', '', $joinUpper);

        $asIndex = strpos($joinUpper, ' AS ');
        $asIndexEnd = $asIndex!==false?$asIndex+4:null;

        $onIndex = strpos($join, ' ON ');
        $onIndexEnd = $onIndex!==false?$onIndex+4:null;

        $foreignTable = null;
        $foreignTableAs = null;
        if ($asIndexEnd !== null && $onIndex !== false) {
            $foreignTable = substr($joinUpper, 0, $onIndex);
            $foreignTableAs = substr($joinUpper, $asIndexEnd, $onIndex-$asIndexEnd);
        } elseif ($onIndex !== false) {
            $foreignTable = substr($joinUpper, 0, $onIndex);
        }

        $statements = explode('AND', substr($joinUpper, $onIndexEnd?:0));
        $statements = array_map('strtolower', $statements);

        return [
            'foreign_table' => $foreignTable,
            'foreign_table_as' => $foreignTableAs,
            'join_statements' => $statements
        ];
    }
}