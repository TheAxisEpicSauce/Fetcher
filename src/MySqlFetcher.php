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

        $stmt = static::$connection->prepare($this->queryString);
        $stmt->execute($this->queryValues);

        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($this->subFetches) > 0) {
            $keyField = $this->key;
            $primaryKeys = array_map(fn ($item) => (int) $item[$keyField], $list);
            if (count($primaryKeys) > 0) {
                foreach ($this->subFetches as $name => [$field, $subFetch]) {
                    /** @var MySqlFetcher $subFetch */
                    $subFetch = $subFetch->where(sprintf('%s.%s', $this->table, $this->key), 'IN', $primaryKeys);

                    $subFetchedData[$name] = [];
                    $subFetchFields[$name] = $field;

                    $subFetch->buildQuery();
                    $data = $subFetch->executeQuery();
                    foreach ($data as $item) {
                        $keyVal = $item['copium'];
                        unset($item['copium']);
                        $subFetchedData[$name][$keyVal][] = $item;
                    }
                }

                foreach ($list as $index => $item)
                {
                    foreach ($this->subFetches as $name => $copium)
                    {
                        $subData = $subFetchedData[$name];
                        /** @var SubFetchField $field */
                        $field = $subFetchFields[$name];
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
            $tableFrom = $this->table;
            $this->tableFetcherLookup[$tableFrom] = $this;

            $tablesAs = [];

            foreach ($joinToMake->getTables() as $tableTo) {
                $joinMethod = 'join'.$this->studly($tableTo);

                $tableAs = $joinToMake->getTableAs($tableTo);
                $fetcherTo = $currentFetcher->getJoins()[$tableTo];

                if (!array_key_exists($tableFrom, $joinsMade) || !in_array($tableAs, $joinsMade[$tableFrom])) {
                    if (!method_exists($currentFetcher, $joinMethod)) {
                        $this->addBuildError(sprintf(
                            '%s misses join method %s', $currentFetcher->getName(), $joinMethod
                        ));
                        continue;
                    }

                    $joinsMade[$tableFrom][] = $tableAs;

                    $js = $currentFetcher->{$joinMethod}();
                    if (!is_array($js)) $js = [$js];
                    foreach ($js as $j) {
                        $type = $joinToMake->isLeftJoin()?'LEFT JOIN':'JOIN';
                        $originalTable = $fetcherTo::getTable();

                        if (preg_match('/([a-zA-Z_`]+)( AS | as | ON | on )([`a-zA-Z_]+)( ON |)([ a-zA-Z._=\'`"]+)/', $j, $matches)) {
                            if ($matches[2] === ' AS ' || $matches[2] === ' as ') {
                                $j = $matches[5];
                                $tableTo = $matches[3];
                            } elseif ($matches[2] === ' ON ' || $matches[2] === ' on ') {
                                $j = $matches[3].$matches[5];
                                $tableTo = $matches[1];
                            }
                            $originalTable = $matches[1];
                        }

                        $as = $tableTo;

                        $tableTo = $joinToMake->getTableAs($tableTo);
                        $filter = array_key_exists($tableTo, self::$joinsAs)?self::$joinsAs[$tableTo]['filter']:null;
                        if ($filter !== null) $j .= ' AND '.$tableTo.'.'.$filter;

                        if ($originalTable!==$tableTo) {
                            $as = $originalTable.' AS '.$tableTo;
                            $tablesAs[$originalTable] = $tableTo;
                        }

                        foreach ($tablesAs as $tableA => $tableB) {
                            $j = preg_replace(sprintf('/(\.)(%s)(\.)/', $tableA), '.'.$tableB.'.', $j);
                            $j = preg_replace(sprintf('/(^)(%s)(\.)/', $tableA), $tableB.'.', $j);
                            $j = preg_replace(sprintf('/( )(%s)(\.)/', $tableA), ' '.$tableB.'.', $j);
                        }

                        $joinString .= sprintf(' %s %s ON %s', $type, $as, $j);
                    }
                } else {
                    $tableTo = $tableAs;
                }
                $this->tableFetcherLookup[$tableTo] = $currentFetcher = new $fetcherTo();
                $tableFrom = $tableTo;
            }
        }

        return $joinString;
    }

    private function getWhereString(array &$values = [])
    {
        $fieldToStringClosure = function (Field $field) use (&$fieldToStringClosure, &$values) {
            if ($field instanceof ObjectField) {
                $join = $field->getJoin();
                if ($join !== null){
                    $table = $join->pathEndAs();
                } else {
                    $table = $this::getTable();
                }

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
            } elseif ($field instanceof GroupField) {
                $fields = [];
                foreach ($field->getFields() as $f) {
                    if ($f instanceof SubFetchField) $fieldToStringClosure($f);
                    else $fields[] = $fieldToStringClosure($f);
                }
                return '('.implode($field->getConjunction()===Conjunction::AND?' AND ':' OR ', $fields).')';
            } elseif ($field instanceof SubFetchField) {
                $fetcher = $field->getFetcher();
                $fetcher->joinsToMake[] = $field->getJoin();

                $fieldGroup = new GroupField(Conjunction::AND, []);
                if (!$field->getFetcher()->fieldGroup->isEmpty()) {
                    $fieldGroup->addField($field->getFetcher()->fieldGroup);
                }

                $fetcher->fieldGroup = $fieldGroup;
                $fetcher->select = $field->getFetcher()->select;
                $fetcher->groupByFields = [];
                $fetcher->select[] = sprintf('`%s`.`%s` AS `copium`', $this->table, $this->key);

                $this->subFetches[$field->getAs()?:$field->getName()] = [
                    $field,
                    $fetcher
                ];
            }
            return '';
        };

        $where = substr($fieldToStringClosure($this->fieldGroup), 1, -1);
        $emptyAnd = substr($where, 0, 7) === '() AND ';
        if ($emptyAnd) {
            $where = substr($where, 7);
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
