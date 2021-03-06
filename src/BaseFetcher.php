<?php
/**
 * User: Raphael Pelissier
 * Date: 18-05-20
 * Time: 16:27
 */

namespace Fetcher;


use BadMethodCallException;
use Closure;
use Exception;
use Fetcher\Field\Operator;
use Fetcher\Validator\FieldObjectValidator;
use PDO;
use Fetcher\Field\Field;
use Fetcher\Field\FieldConjunction;
use Fetcher\Field\FieldGroup;
use Fetcher\Field\FieldObject;
use Fetcher\Field\FieldType;
use Fetcher\Join\Join;
use ReflectionClass;

/**
 * Class BaseFetcher
 * @package Fetcher
 */
abstract class BaseFetcher implements Fetcher
{
    /**
     * @var PDO
     */
    static $connection = null;

    private $fieldPrefixRegex = null;
    private $fieldPrefixes = [
        '' => Operator::EQUALS,
        'is_' => Operator::EQUALS
    ];

    private $fieldSuffixRegex = null;
    private $fieldSuffixes = [
        '' =>  Operator::EQUALS,
        '_is' =>  Operator::EQUALS,
        '_is_not' =>  Operator::NOT_EQUALS,
        '_gt' =>  Operator::GREATER,
        '_gte' =>  Operator::GREATER_OR_EQUAL,
        '_lt' =>  Operator::LESS,
        '_lte' =>  Operator::LESS_OR_EQUAL,
        '_like' =>  Operator::LIKE,
        '_in' =>  Operator::IN,
        '_in_like' =>  Operator::IN_LIKE,
        '_not_in' =>  Operator::NOT_IN
    ];

    /**
     * @var string
     */
    protected $table = null;
    /**
     * @var string
     */
    protected $key = 'id';
    /**
     * @var null
     */
    protected $model = null;
    /**
     * @var bool
     */
    private $isRaw;
    /**
     * @var null|FieldGroup
     */
    private $fieldGroup = null;
    /**
     * @var array|Join[]
     */
    private $joinsToMake = [];
    /**
     * @var string
     */
    private $queryString;
    /**
     * @var array
     */
    private $queryValues;
    /**
     * @var array
     */
    private $select;
    /**
     * @var array|string[]
     */
    private $selectedFields;
    /**
     * @var array|BaseFetcher[]
     */
    private $tableFetcherLookup;
    /**
     * @var array|string[]
     */
    private $parseMethods;
    /**
     * @var null|int
     */
    private $limit;
    /**
     * @var FieldObjectValidator
     */
    private $fieldObjectValidator;
    /**
     * @var bool
     */
    private $needsGroupBy = false;
    /**
     * @var null|string|array
     */
    private $groupBy = null;
    /**
     * @var array
     */
    private $groupFields = [];
    /**
     * @var |null
     */
    private $orderByFields;
    /**
     * @var string
     */
    private $orderByDirection;

    private static $joinsAs = [];

    /**
     * BaseFetcher constructor.
     * @param string|null $as
     * @throws Exception
     */
    public function __construct(?string $as = null)
    {
        if ($this->table === null) throw new Exception('table not set');
        if ($as !== null) $this->table = $as;
        $this->fieldObjectValidator = new FieldObjectValidator();
    }

    /**
     * @param PDO $connection
     */
    public static function setConnection(PDO $connection): void
    {
        self::$connection = $connection;
    }

    public function getName(): ?string
    {
        try {
            return (new ReflectionClass($this))->getShortName();
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    //-------------------------------------------
    // New instance
    //-------------------------------------------
    public static function build(bool $isRaw = true): self
    {
        return self::buildAnd($isRaw);
    }

    public static function buildAnd(bool $isRaw = true): self
    {
        $fetcher = new static();
        $fetcher->isRaw = $isRaw;
        $fetcher->fieldGroup = new FieldGroup(FieldConjunction::AND, []);
        $fetcher->reset();

        return $fetcher;
    }

    public static function buildOr(bool $isRaw = true): self
    {
        $fetcher = new static();
        $fetcher->isRaw = $isRaw;
        $fetcher->fieldGroup = new FieldGroup(FieldConjunction::OR, []);
        $fetcher->reset();

        return $fetcher;
    }

    public static function buildFromArray(array $data, bool $isRaw = true)
    {
        $fetcher = new static();
        $fetcher->isRaw = $isRaw;
        $fetcher->fieldGroup = new FieldGroup($data['type'], []);
        if (array_key_exists('joins_as', $data)) {
            foreach ($data['joins_as'] as $joinAs) {
                $fetcher->addJoinAs($joinAs['table'], $joinAs['as'], $joinAs['filter']);
            }
        }
        $fetcher->reset();

        $fields = $data['fields'];
        $fetcher->handleArray($fields);

        return $fetcher;
    }

    private function reset()
    {
        $this->joinsToMake = [];
        $this->select();
        $this->queryString = null;
        $this->queryValues = null;
        if ($this->key !== null) {
            $this->needsGroupBy = true;
            $this->groupBy = "`$this->table`".'.'.$this->key;
        } else {
            $this->needsGroupBy = false;
            $this->groupBy = null;
        }
        $this->groupFields = [];
        $this->orderByFields = null;
        $this->orderByDirection = 'desc';

        $this->mapFetchers(get_class($this), $this->table);
    }

    private $fetcherIds = [];
    private $tableIds = [];
    private $visitedFetchers = [];
    private $fetcherNodes = [];
    private $tableNodes = [];
    private $tableFetcherMap = [];

    private function mapFetchers(string $currentFetcher, string $currentTable)
    {
        $currentFetcherId = $this->getFetcherId($currentFetcher);
        $currentTableId = $this->getTableId($currentTable);

        $this->visitedFetchers[$currentFetcherId] = $currentFetcher;

        $fetchers = (new $currentFetcher)->getJoins();

        foreach ($fetchers as $table => $fetcher) {
            $fetcherId = $this->getFetcherId($fetcher);
            $tableId = $this->getTableId($table);

            $this->tableFetcherMap[$tableId] = $fetcherId;

            $this->fetcherNodes[$currentFetcherId] = $fetcherId;
            $this->tableNodes[$tableId][$currentTableId] = $currentTableId;

            if (array_key_exists($fetcherId, $this->visitedFetchers)) continue;

            $this->mapFetchers($fetcher, $table);
        }
    }

    private function findJoin(array $tablePath): ?Join
    {
        $pathEnd = $tableAs = $table = array_pop($tablePath);
        if (array_key_exists($table, self::$joinsAs)) $table = $pathEnd = self::$joinsAs[$table]['table'];

        $pathTraveled = false;

        $baseId = $this->getTableId($this->table);

        $list = null;
        $tableId = null;

        $tableMappings = [];

        do {
            if (count($tablePath) > 0) {
                $tableTo = array_shift($tablePath);

                if (array_key_exists($tableTo, self::$joinsAs)) {
                    $tableTo = $tableMappings[$tableTo] = self::$joinsAs[$tableTo]['table'];
                }
            } else {
                $tableTo = $pathEnd;
                $pathTraveled = true;
            }

            if ($list !== null && $tableId !== null) $list .= $tableId.'|';

            $tableId = array_key_exists($tableTo, $this->tableIds)?$this->tableIds[$tableTo]:null;
            if ($tableId === null) throw new Exception(sprintf('table %s not found', $tableTo));

            $list .= $this->joinIdList($baseId, $tableId);
            $baseId = $tableId;

        } while (!$pathTraveled);

        $join = null;
        if ($list !== null) {
            $fetchers = array_flip($this->fetcherIds);
            $tables = array_flip($this->tableIds);
            $list = array_reverse(explode('|', $list));
            $join = new Join($tables[$tableId], $fetchers[$this->tableFetcherMap[$tableId]]);
            foreach ($list as $id) {
                if (empty($id)) continue;
                $join->prependPath($tables[$id]);
            }
            foreach ($tableMappings as $a => $b) {
                $join->addTableMapping($b, $a);
            }
            $join->addTableMapping($table, $tableAs);
        }

        return $join;
    }

    private function joinIdList(int $fromId, int $toId): ?string
    {
        $ids = $this->tableNodes[$toId];
        if (array_key_exists($fromId, $ids)) return "";

        foreach ($ids as $id) {
            $list = $this->joinIdList($fromId, $id);
            if ($list === null) return null;
            else return "$list$id|";
        }
        return null;
    }


    private function getFetcherId($fetcherClass): int
    {
        if (!array_key_exists($fetcherClass, $this->fetcherIds)) {
            static $fetcherId = 0; $fetcherId++;
            $this->fetcherIds[$fetcherClass] = $fetcherId;
        };

        return $this->fetcherIds[$fetcherClass];
    }

    private function getTableId($table): int
    {
        if (!array_key_exists($table, $this->tableIds)) {
            static $tableId = 0; $tableId++;
            $this->tableIds[$table] = $tableId;
        };

        return $this->tableIds[$table];
    }

    //-------------------------------------------
    // Fetcher Calls
    //-------------------------------------------
    public function __call($method, $params)
    {
        if ($this->isWhereCall($method)) {
            if (count($params) === 0) throw new Exception('Missing parameter 1');

            $field = $this->snake(substr($method, 5));
            $value = $params[0];
            $operator = null;

            if ($field === '' && count($params) === 2) {
                $field = $params[0];
                $value = $params[1];
            } elseif ($field === '' && count($params) === 3) {
                $field = $params[0];
                $operator = $params[1];
                $value = $params[2];
            }

            $success = $this->handleWhere($field, $value, $operator);
            if (!$success && strpos($field, '.')) $success = $this->handleJoin($field, $value, $operator);
            if (!$success) throw new Exception('Cannot find field '.$field);
        } else {
            throw new BadMethodCallException(sprintf('Call to unknown method %s', $method));
        }

        return $this;
    }

    public static function __callStatic($method, $params)
    {
        $fetcher = self::build();
        if (!$fetcher->isWhereCall($method)) {
            unset($fetcher);
            throw new BadMethodCallException(sprintf('Call to unknown method %s', $method));
        }

        return $fetcher->$method(...$params);
    }

    public function or(Closure $closure)
    {
        $this->handleGroup(FieldConjunction::OR, $closure);
        return $this;
    }

    public function and(Closure $closure)
    {
        $this->handleGroup(FieldConjunction::AND, $closure);
        return $this;
    }

    private function isWhereCall(string $method)
    {
        return substr($method, 0, 5) === "where";
    }

    //-------------------------------------------
    // Handle array call
    //-------------------------------------------
    private function handleArray(array $fields)
    {
        foreach ($fields as $field) {
            if ($this->isArrayField($field)) {
                $success = $this->handleWhere($field['param'], $field['value']);
                if (!$success) $this->handleJoin($field['param'], $field['value']);
            } elseif ($this->isArrayGroup($field)) {
                $repo = $field['type']===FieldConjunction::OR?self::buildOr():self::buildAnd();
                $repo->handleArray($field['fields']);
                $this->fieldGroup->addField($repo->fieldGroup);
                $this->joinsToMake = array_merge($this->joinsToMake, $repo->joinsToMake);
            } else {
                throw new Exception('Cannot handle given field');
            }
        }
    }

    private function isArrayField(array $field)
    {
        return array_key_exists('param', $field) && array_key_exists('value', $field);
    }

    private function isArrayGroup(array $group)
    {
        return array_key_exists('type', $group) && array_key_exists('fields', $group);
    }

    //-------------------------------------------
    // Handle group call
    //-------------------------------------------
    private function handleGroup($fullField, $param)
    {
        $repo = $fullField===FieldConjunction::OR?self::buildOr():self::buildAnd();
        $param($repo);
        $group = $repo->fieldGroup;
        foreach ($repo->joinsToMake as $joinToMake) {
            $this->joinsToMake[$joinToMake->pathEndAs()] = $joinToMake;
        }
        $this->fieldGroup->addField($group);
        return true;
    }

    //-------------------------------------------
    // Handle where call
    //-------------------------------------------
    private function handleWhere($fullField, $param, ?string $operator = null)
    {
        $field = $this->makeFieldObject($fullField, $param, $operator);
        if ($field === null) return false;

        $this->fieldGroup->addField($field);

        return true;
    }

    //-------------------------------------------
    // Handle join call
    //-------------------------------------------
    private function handleJoin($fullField, $param, ?string $operator = null)
    {
        if (!strpos($fullField, '.')) return false;
        $tables = explode('.', $fullField);
        $fullField = array_pop($tables);

        $join = $this->findJoin($tables);
        if (!$join) return false;

        $fetcherClass = $join->getFetcherClass();
        $field = (new $fetcherClass)->makeFieldObject($fullField, $param, $operator);
        if ($field === null) return false;

        $field->setJoin($join);
        $field->setValue($param);
        $this->fieldGroup->addField($field);

        if ($join !== null) $this->joinsToMake[$join->pathEndAs()] = $join;

        return true;
    }

    private $searchedFetchers = [];
    private $fullJoinTable = null;

    private function findJoinDeprecated($tables, $availableJoins): ?Join
    {
        $this->searchedFetchers = [];
        if (!is_array($tables)) $tables = [$tables];

        $this->fullJoinTable = '';

        return $this->findJoinClosure($tables, $availableJoins);
    }

    private function findJoinClosure($tables, $availableJoins): ?Join
    {
        $table = $tables[0];
        $fullJoinTable = $this->fullJoinTable;

        $availableJoins = array_diff($availableJoins, $this->searchedFetchers);

        foreach ($availableJoins as $availableJoin => $fetcherClass) {
            $this->searchedFetchers[] = $fetcherClass;
            if (array_key_exists($table, $availableJoins)) {
                if (count($tables) === 1) {
                    $join = new Join($table, $availableJoins[$table]);
                    $join->addTableMapping($table, $this->fullJoinTable.$table);
                    return $join;
                } else {
                    $table = array_shift($tables);
                    $this->fullJoinTable .= $table.'_';
                }
            } else {
                $table = $fetcherClass::getTable();
            }

            $join = $this->findJoinClosure($tables, (new $fetcherClass)->getJoins());
            if ($join !== null) {
                $join->prependPath($table);
                $join->addTableMapping($table, $fullJoinTable.$table);
                return $join;
            }
        }

        return null;
    }

    //-------------------------------------------
    // Field object
    //-------------------------------------------
    /**
     * Returns the field object if string is valid, else null
     *
     * @param string $fullField
     * @param $value
     * @param string|null $operator
     * @return FieldObject|null
     */
    private function makeFieldObject(string $fullField, $value, ?string $operator = null): ?FieldObject
    {
        if ($operator === null) {
            $fieldData = $this->splitFullField($fullField);
            if ($fieldData === null) return null;
            [$field, $operator] = $fieldData;
        } else {
            $field = $fullField;
        }

        if (!$this->validateField($field)) return null;
        if (!$this->validateOperator($operator)) return null;

        $object = new FieldObject($field, $this->getFieldType($field), $operator, $value);

        $this->fieldObjectValidator->validate($object);

        return $object;
    }

    private function splitFullField(string $fullField)
    {
        if (preg_match($this->getFieldPrefixRegex(), $fullField, $matches)) {
            return [$matches[3], $this->fieldPrefixes[$matches[2]]];
        } elseif (preg_match($this->getFieldSuffixRegex(), $fullField, $matches)) {
            return [$matches[2], $this->fieldSuffixes[$matches[3]]];
        }
        return null;
    }

    //-------------------------------------------
    // Execution
    //-------------------------------------------
    private function buildQuery()
    {
        $values = [];

        $selectString   = $this->getSelectString();
        $joinString     = $this->getJoinString();
        $whereString    = $this->getWhereString($values);
        $limitString    = $this->limit?' LIMIT '.$this->limit:'';
        $groupString    = $this->getGroupString();
        $orderByString  = $this->getOrderByString();

        $query = "SELECT " . $selectString . " FROM " . "`$this->table`" . $joinString . $whereString . $groupString . $orderByString . $limitString;

        $this->queryString = $query;
        $this->queryValues = $values;
    }

    private function getSelectString()
    {
        return $this->select?implode(', ', $this->select):$this->table.'.*';
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
                    if (!method_exists($currentFetcher, $joinMethod)) throw new Exception(sprintf(
                        '%s misses join method %s', $currentFetcher->getName(), $joinMethod
                    ));
                    $joinsMade[$tableFrom][] = $tableAs;

                    $js = $currentFetcher->{$joinMethod}();
                    if (!is_array($js)) $js = [$js];
                    foreach ($js as $j) {
                        $type = $joinToMake->isLeftJoin()?'LEFT JOIN':'JOIN';
                        $originalTable = $fetcherTo::getTable();

                        if (preg_match('/([a-zA-Z_`]+)( AS | as | ON | on )([`a-zA-Z_]+)( ON |)([ a-zA-Z._=\'`]+)/', $j, $matches)) {
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
            if ($field instanceof FieldObject) {
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
            } elseif ($field instanceof FieldGroup) {
                $fields = [];
                foreach ($field->getFields() as $f) $fields[] = $fieldToStringClosure($f);
                return '('.implode($field->getConjunction()===FieldConjunction::AND?' AND ':' OR ', $fields).')';
            }
            return '';
        };

        $where = substr($fieldToStringClosure($this->fieldGroup), 1, -1);

        return empty($where)?'':' WHERE '.$where;
    }

    private function getGroupString()
    {
        if (!$this->needsGroupBy) return '';
        return ' GROUP BY '.$this->groupBy;
    }

    private function getOrderByString()
    {
        if ($this->orderByFields !== null && is_array($this->orderByFields) && !empty($this->orderByFields)) {
            return ' ORDER BY '.implode(', ', $this->orderByFields).($this->orderByDirection==='desc'?' DESC':' ASC');
        }
        return '';
    }

    /**
     * Get all the elements
     *
     * @return array
     * @throws Exception
     */
    public function get()
    {
        $this->buildQuery();
        $stmt = $this->getStatement();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($this->groupFields) > 0) {
            foreach ($rows as $index => $row) {
                foreach ($this->groupFields as $groupField)
                    $rows[$index][$groupField] = array_key_exists($groupField, $row)?explode(',', $row[$groupField]):[];
            }
        }

        if ($this->isRaw) return $rows;
        throw new Exception('@TODO');
    }

    public function pluck(string $field)
    {
        $this->select([$field]);
        $this->isRaw = true;
        return array_map('array_pop', $this->get());
    }

    /**
     * Get the first result or null if empty
     *
     * @return mixed|null
     * @throws Exception
     */
    public function first()
    {
        $this->limit(1);
        $this->buildQuery();

        $stmt = $this->getStatement();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $row = array_pop($rows);
        if ($row === null) return null;

        if (count($this->groupFields) > 0) {
            foreach ($this->groupFields as $groupField)
                $row[$groupField] = array_key_exists($groupField, $row)?explode(',', $row[$groupField]):[];
        }

        if ($this->isRaw) return $row;
        throw new Exception('@TODO');
    }

    public function value(string $field)
    {
        $this->select([$field]);
        $this->isRaw = true;
        $row = $this->first();
        if ($row === null) return null;
        return array_pop($row);
    }

    public function count()
    {
        $this->select = ['count(*) as total'];
        $this->isRaw = true;
        $this->needsGroupBy = false;
        $row = $this->first();

        return $row?$row['total']:0;
    }

    public function sum(string $field)
    {
        $this->select([$field]);

        $this->select = ['sum('.$this->select[0].') as total'];
        $this->isRaw = true;
        $this->needsGroupBy = false;
        $row = $this->first();

        return $row?$row['total']:0;
    }

    /**
     * @return bool|\PDOStatement
     * @throws Exception
     */
    private function getStatement()
    {
        if (self::$connection === null) throw new Exception('Connection no set');
        $pdo = self::$connection;

        $stmt = $pdo->prepare($this->queryString);
        $stmt->execute($this->queryValues);

        return $stmt;
    }

    //-------------------------------------------
    // Public Other
    //-------------------------------------------
    public function toSql(bool $withBindings = false)
    {
        $this->buildQuery();

        if (!$withBindings) return $this->queryString;
        return sprintf(str_replace('?', '%s', $this->queryString), ...$this->queryValues);
    }

    //-------------------------------------------
    // Private
    //-------------------------------------------
    protected abstract function getFields(): array;

    private function getFieldType(string $field)
    {
        return $this->getFields()[$field];
    }

    protected abstract function getJoins(): array;

    private function getFieldPrefixRegex()
    {
        if ($this->fieldPrefixRegex) return $this->fieldPrefixRegex;
        $this->fieldPrefixRegex = '/( |^)('.implode("|", array_keys($this->fieldPrefixes)).')('.implode("|", array_keys($this->getFields())).')( |$)/';
        return $this->fieldPrefixRegex;
    }

    private function getFieldSuffixRegex()
    {
        if ($this->fieldSuffixRegex) return $this->fieldSuffixRegex;
        $this->fieldSuffixRegex = '/( |^)('.implode("|", array_keys($this->getFields())).')('.implode("|", array_keys($this->fieldSuffixes)).')( |$)/';
        return $this->fieldSuffixRegex;
    }

    private static $tables = [];

    private static function getTable()
    {
//        if (array_key_exists(static::class, self::$tables)) self::$tables[static::class];
        $fetcher = new static();
        $table = $fetcher->table;
//        self::$tables[static::class] = $table;
        return $table;
    }

    //-------------------------------------------
    // QueryParams
    //-------------------------------------------
    /**
     * @param array|null $select
     * @return $this
     * @throws Exception
     */
    public function select(?array $select = null)
    {
        if ($select === null) $select = ["*"];

        $this->select = [];
        $this->isRaw = true;

        foreach ($select as $field) {
            [$field, $as] = $this->separateAs($field);
            [$field, $modifier] = $this->separateModifier($field);
            if ($modifier === 'group') $this->needsGroupBy = true;

            $tables = null;
            if (strpos($field, '.') !== false) {
                $tables = explode('.', $field);
                $field = array_pop($tables);
                $table = array_pop($tables);
                $tables[] = $table;
            } else {
                $table = $this->table;
            }

            $join = null;
            if ($table === $this->table) {
                $fields = array_keys($this->getFields());
            } elseif ($join = $this->findJoin($tables)) {
                $class = $join->getFetcherClass();
                $fields = array_keys((new $class)->getFields());
            } else {
                throw new Exception('Could not find table '. $table);
            }

            if (!in_array($field, $fields) && $field !== '*') {
                throw new Exception(sprintf('Invalid field %s.%s', $table, $field));
            }

//            if ($tables !== null) $table = implode('_', $tables);

            if ($field === '*') {
                $this->addSelectFields($table, $fields);
            } else {
                $this->addSelectField($table, $field, $as, $modifier);
            }

            if ($join !== null) {
                if (array_key_exists($join->pathEndAs(), $this->joinsToMake)) {
                    $this->joinsToMake[$join->pathEndAs()]->setFullJoin();
                } else {
                    $this->joinsToMake[$join->pathEndAs()] = $join;
                }
            }
        }
        return $this;
    }

    private function addSelectFields(string $table, array $fields)
    {
        foreach ($fields as $field) {
            $as = null;
            if ($table !== $this->table) $as = $table.'_'.$field;
            $this->addSelectField($table, $field, $as, null);
        }
    }

    private function addSelectField(string $table, string $field, ?string $as, ?string $modifier)
    {
        $fullField = sprintf('`%s`.`%s`', $table, $field);
        if ($modifier === 'group') {
            $fullField = sprintf('GROUP_CONCAT(%s)', $fullField);
            $as = $as?:$field;
            $this->groupFields[] = $as;
        }

        $selectString = sprintf('%s%s', $fullField, $as?' AS '.$as:'');
        $this->select[] = $selectString;
        $this->selectedFields[$as] = [$table, $field, $modifier];
    }

    public function getSelect()
    {
        return $this->select;
    }

    public function limit(?int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function orderBy(array $fields, string $direction)
    {
        $this->orderByFields = $fields;
        $this->orderByDirection = $direction;
        return $this;
    }

    public function clearOrderBy()
    {
        $this->orderByFields = null;
        return $this;
    }

    public function addJoinAs(string $table, string $as, ?string $filter)
    {
        self::$joinsAs[$as] = [
            'table' => $table,
            'as' => $as,
            'filter' => $filter
        ];
    }

    //-------------------------------------------
    // Validate
    //-------------------------------------------
    private function validateField(string $field)
    {
        return array_key_exists($field, $this->getFields());
    }

    private function validateOperator(string $operator)
    {
        return Operator::isValidValue($operator);
    }

    //-------------------------------------------
    // Helpers
    //-------------------------------------------
    private function studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    private function snake($value)
    {
        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));

            $value = strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $value));
        }
        return $value;
    }

    private function separateAs(string $field)
    {
        if (preg_match('/(|^)([()a-zA-Z._]+)( AS | as )([()a-zA-Z._]+)(|$)/', $field, $matches)){
            return [
                $matches[2],
                $matches[4]
            ];
        }
        return [$field, null];
    }

    private function separateModifier(string $field)
    {
        if (preg_match('/(|^)(group|)(\()([()a-zA-Z._]+)(\))(|$)/', $field, $matches)){
            return [
                $matches[4],
                $matches[2]
            ];
        }
        return [$field, null];
    }

    private function hasParseMethod(string $table, string $field)
    {
        $field = $this->studly($field);
        $method = 'parse'.$field.'Field';
        $index = $table.'.'.$field;

        if (array_key_exists($index, $this->parseMethods)) $this->parseMethods[$index];

        $exists = method_exists($this->tableFetcherLookup[$table], $method);
        $this->parseMethods[$index] = $exists;
        return $exists;
    }
}
