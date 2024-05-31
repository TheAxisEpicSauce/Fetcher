<?php
/**
 * User: Raphael Pelissier
 * Date: 20-09-21
 * Time: 11:07
 */

namespace Fetcher;


use Exception;
use Fetcher\Field\Field;
use Fetcher\Field\Conjunction;
use Fetcher\Field\GroupField;
use Fetcher\Field\ObjectField;
use Fetcher\Field\Operator;
use Fetcher\Field\SubFetchField;
use Fetcher\Join\Join;
use PDO;

abstract class MySqlFetcher extends BaseFetcher
{
    static mixed $connection = null;

    /**
     * @param PDO $connection
     * @throws Exception
     */
    public static function setConnection($connection): void
    {
        if (!$connection instanceof PDO) throw new Exception('invalid connection type');
        static::$connection = $connection;
    }

    protected function buildQuery()
    {
        $values = [];

        $selectString   = $this->getSelectString();
        $joinString     = $this->getJoinString();
        $whereString    = $this->getWhereString($values);
        $limitString    = $this->take?' LIMIT '.$this->take:'';
        $offsetString    = $this->skip?' OFFSET '.$this->skip:'';
        $groupString    = $this->getGroupByString();
        $orderByString  = $this->getOrderByString();

        $query = "SELECT " . $selectString . " FROM " . "`$this->table`" . $joinString . $whereString . $groupString . $orderByString . $limitString . $offsetString;

        $this->queryString = $query;
        $this->queryValues = $values;

        if (!$this->isValidBuild()) {
            throw new Exception();
        }
    }

    protected function executeQuery(): array
    {
        $subFetchedData = [];
        $subFetchFields = [];

        try
        {
            $stmt = static::$connection->prepare($this->queryString);
            $stmt->execute($this->queryValues);

            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        catch (Exception $e)
        {
            throw new Exception(sprintf(
                "failed to execute query %s", $this->queryString
            ), (int) $e->getCode(), $e);
        }

        if (count($this->subFetches) > 0)
        {
            $keyField = $this->key;
            $primaryKeys = array_map(fn ($item) => (int) $item[$keyField], $list);
            if (count($primaryKeys) > 0)
            {
                foreach ($this->subFetches as $name => [$field, $subFetch]) {
                    /** @var MySqlFetcher $subFetch */
                    $subFetch = $subFetch->where(sprintf('%s.%s', $this->table, $this->key), 'IN', $primaryKeys);

                    $subFetchedData[$name] = [];
                    $subFetchFields[$name] = $field;

                    $subFetch->buildQuery();
                    $data = $subFetch->executeQuery();
                    foreach ($data as $item) {
                        $keyVal = $item[$field->getColumnName()];
                        unset($item[$field->getColumnName()]);
                        $subFetchedData[$name][$keyVal][] = $item;
                    }
                }

                foreach ($list as $index => $item)
                {
                    foreach ($this->subFetches as $name => [$field, $subFetch])
                    {
                        $subData = $subFetchedData[$name];
                        $hasKey = array_key_exists($item[$keyField], $subData);

                        $list[$index][$name] = match ($field->getMethod()) {
                            'count' => $hasKey ? count($subData[$item[$keyField]]) : 0,
                            'first' => $hasKey ? $subData[$item[$keyField]][0] : null,
                            'sum' => $hasKey ? array_reduce($subData[$item[$keyField]], fn ($c, $i) => $c+$i[$field->getMethodField()], 0): 0,
                            default => $hasKey ? $subData[$item[$keyField]] : [],
                        };
                    }
                }
            }
        }

        return $list;
    }

    private function getSelectString()
    {
        return $this->select?implode(', ', $this->select):"`$this->table`".'.*';
    }

    private function getJoinString()
    {
        $joinsMade = [];
        $joinString = '';

        usort($this->joinsToMake, function(Join $a, Join $b) {
            return $b->pathLength() - $a->pathLength();
        });

        foreach ($this->joinsToMake as $joinToMake) {
            $currentFetcher = $this;
            $tableFrom = $originalTableFrom = $this->table;
            $this->tableFetcherLookup[$tableFrom] = $this;

            $tablesAs = [];
            foreach ($joinToMake->getTables() as $tableTo) {
                $tableAs = $joinToMake->getTableAs($tableTo);

                $joinName = $joinToMake->getJoinName($originalTableFrom, $tableTo);
                $joinMethod = 'join'.$this->studly($joinName);

                $fetcherTo = $currentFetcher->getJoins()[$joinName];

                $originalTableFrom = $tableTo;

                if (array_key_exists($tableFrom, $joinsMade) && array_key_exists($tableAs, $joinsMade[$tableFrom]))
                {
                    $tablesAs = array_merge($tablesAs, $joinsMade[$tableFrom][$tableAs]);
                    $tableTo = $tableAs;
                }
                else
                {
                    if (!method_exists($currentFetcher, $joinMethod)) {
                        $this->addBuildError(sprintf(
                            '%s misses join method %s', $currentFetcher->getName(), $joinMethod
                        ));
                        continue;
                    }

                    $joinParts = $currentFetcher->{$joinMethod}();
                    if (!is_array($joinParts)) $joinParts = [$joinParts];

                    $lastIndex = count($joinParts)-1;
                    foreach ($joinParts as $joinIndex => $joinPart) {
                        $joinType = $joinToMake->isLeftJoin()?'LEFT JOIN':'JOIN';
                        $originalTable = $fetcherTo::getTable();

                        if (preg_match('/([a-zA-Z_`]+)( AS | as | ON | on )([`a-zA-Z_]+)( ON |)([ a-zA-Z._=\'`"]+)/', $joinPart, $matches)) {
                            if ($matches[2] === ' AS ' || $matches[2] === ' as ') {
                                $joinPart = $matches[5];
                                $tableTo = $matches[3];
                            } elseif ($matches[2] === ' ON ' || $matches[2] === ' on ') {
                                $joinPart = $matches[3].$matches[5];
                                $tableTo = $matches[1];
                            }
                            $originalTable = $matches[1];
                        }

                        $tableTo = str_replace('`', '', $tableTo);
                        $asPart = $tableTo;

                        $tableAs = $joinToMake->getTableAs($tableTo);
                        $filter = array_key_exists($tableTo, self::$joinsAs)?self::$joinsAs[$tableTo]['filter']:null;
                        if ($filter !== null) $joinPart .= ' AND '.$tableTo.'.'.$filter;

                        if ($lastIndex === $joinIndex)
                        {
                            if ($tableAs!==$originalTable) {
                                $asPart = $originalTable.' AS '.$tableAs;
                                if ($joinName!==$originalTable)
                                {
                                    $tablesAs[$originalTable] = $joinName;
                                    $tablesAs[$joinName] = $tableAs;
                                }
                                else
                                {
                                    $tablesAs[$originalTable] = $tableAs;
                                }
                            }
                            elseif ($joinName!==$originalTable)
                            {
                                $asPart = $originalTable.' AS '.$joinName;
                                $tablesAs[$originalTable] = $joinName;
                                $tablesAs[$joinName] = $tableAs;
                            }

                            foreach ($tablesAs as $tableA => $tableB) {
                                $tableA = str_replace('`', '', $tableA);
                                $tableB = str_replace('`', '', $tableB);

                                $joinPart = preg_replace(sprintf('/(\.`)(%s)(`\.)/', $tableA), '.`'.$tableB.'`.', $joinPart);
                                $joinPart = preg_replace(sprintf('/(^`)(%s)(`\.)/', $tableA), '`'.$tableB.'`.', $joinPart);
                                $joinPart = preg_replace(sprintf('/( `)(%s)(`\.)/', $tableA), ' `'.$tableB.'`.', $joinPart);
                                $joinPart = preg_replace(sprintf('/(\.)(%s)(\.)/', $tableA), '.`'.$tableB.'`.', $joinPart);
                                $joinPart = preg_replace(sprintf('/(^)(%s)(\.)/', $tableA), '`'.$tableB.'`.', $joinPart);
                                $joinPart = preg_replace(sprintf('/( )(%s)(\.)/', $tableA), ' `'.$tableB.'`.', $joinPart);
                            }

                            $asPart = str_replace('`', '', $asPart);
                            $asPart = implode(' AS ', array_map(fn($s) => '`'.$s.'`', explode(' AS ', $asPart)));
                        }

                        $joinString .= sprintf(' %s %s ON %s', $joinType, $asPart, $joinPart);
                    }

                    $joinsMade[$tableFrom][$tableAs] = $tablesAs;

                }
                $this->tableFetcherLookup[$tableTo] = $currentFetcher = new $fetcherTo();
                $tableFrom = $tableAs;
            }
        }

        return $joinString;
    }

    private function getWhereString(array &$values = [])
    {
        $fieldToStringClosure = function (Field $field) use (&$fieldToStringClosure, &$values) {
            if ($field instanceof ObjectField)
            {
                $join = $field->getJoin();
                if ($join !== null) {
                    $table = $join->pathEndAs();
                } else {
                    $table = $this::getTable();
                }

                if (Operator::IsFieldOperator($field->getOperator()))
                {
                    $valueTable = $field->getValueJoin()?$field->getValueJoin()->pathEndAs():$this::getTable();

                    $simpleOperator = str_replace('$', '', $field->getOperator());
                    return sprintf('`%s`.`%s` %s `%s`.`%s`', $table, $field->getField(), $simpleOperator, $valueTable, $field->getValue());
                }
                else
                {
                    if (is_array($field->getValue())) {
                        $marks = [];
                        foreach ($field->getValue() as $v) {$values[] = $v; $marks[] = '?';}
                        $marks = '('.implode(', ', $marks).')';
                    } elseif ($field->getOperator() === Operator::EQUALS && $field->getValue() === null) {
                        return sprintf('`%s`.`%s` %s', $table, $field->getField(), 'IS NULL');
                    } elseif ($field->getOperator() === Operator::NOT_EQUALS && $field->getValue() === null) {
                        return sprintf('`%s`.`%s` %s', $table, $field->getField(), 'IS NOT NULL');
                    } else {
                        $values[] = $field->getValue();
                        $marks = '?';
                    }

                    return sprintf('`%s`.`%s` %s %s', $table, $field->getField(), $field->getOperator(), $marks);
                }
            }
            elseif ($field instanceof GroupField)
            {
                $fields = [];
                foreach ($field->getFields() as $f) {
                    if ($f instanceof SubFetchField) $fieldToStringClosure($f);
                    else $fields[] = $fieldToStringClosure($f);
                }
                return '('.implode($field->getConjunction()===Conjunction::AND?' AND ':' OR ', $fields).')';
            }
            elseif ($field instanceof SubFetchField)
            {
                $fetcher = $field->getFetcher();
                if (!array_key_exists($field->getJoin()->getPathAs(), $fetcher->joinsToMake))
                {
                    $fetcher->joinsToMake[$field->getJoin()->getPathAs()] = $field->getJoin();
                }

                $fieldGroup = new GroupField(Conjunction::AND, []);
                if (!$field->getFetcher()->fieldGroup->isEmpty()) {
                    $fieldGroup->addField($field->getFetcher()->fieldGroup);
                }


                $fetcher->fieldGroup = $fieldGroup;
                if ($field->getMethod() === 'count')
                {
                    $fetcher->select = [
                        $field->getFetcher()->table.'.'.$field->getFetcher()->key,
                        sprintf('`%s`.`%s` AS `%s`', $this->table, $this->key, $field->getColumnName())
                    ];
                }
                else
                {
                    $fetcher->select = $field->getFetcher()->select;
                    $fetcher->select[] = sprintf('`%s`.`%s` AS `%s`', $this->table, $this->key, $field->getColumnName());
                }

                $fetcher->groupByFields = [];

                $this->subFetches[$field->getAs()?:$field->getName()] = [
                    $field,
                    $fetcher
                ];
            }
            return '';
        };

        $where = substr($fieldToStringClosure($this->fieldGroup), 1, -1);
        if (str_starts_with($where, '() AND ')) {
            $where = substr($where, 7);
        }
        elseif (str_starts_with($where, '(()) AND '))
        {
            $where = substr($where, 9);
        }

        return empty($where)?'':' WHERE '.$where;
    }

    private function getGroupByString()
    {
        if ($this->groupByFields === null) return '';
        $string = null;
        foreach ($this->groupByFields as $table => $fields) {
            foreach ($fields as $field) {
                if ($string === null) {
                    $string = " GROUP BY `$table`.`$field`";
                } else {
                    $string .= ", `$table`.`$field`";
                }
            }
        }
        return $string;
    }

    private function getOrderByString()
    {
        if ($this->orderByFields === null) return '';
        $string = null;
        foreach ($this->orderByFields as $table => $fields) {
            foreach ($fields as $field) {
                if ($string === null) {
                    $string = " ORDER BY `$table`.`$field`";
                } else {
                    $string .= ", `$table`.`$field`";
                }
            }
        }
        return $string . ($this->orderByDirection==='desc'?' DESC':' ASC');
    }
}
