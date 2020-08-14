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
     * BaseFetcher constructor.
     * @throws Exception
     */
    public function __construct()
    {
        if ($this->table === null) throw new Exception('table not set');
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

            if ($field === '' && count($params) === 2) {
                $field = $params[0];
                $value = $params[1];
            }

            $success = $this->handleWhere($field, $value);
            if (!$success && strpos($field, '.')) $success = $this->handleJoin($field, $value);
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
        $this->fieldGroup->addField($group);
        return true;
    }

    //-------------------------------------------
    // Handle where call
    //-------------------------------------------
    private function handleWhere($fullField, $param)
    {
        $field = $this->makeFieldObject($fullField);
        if ($field === null) return false;

        $field->setValue($param);

        $this->fieldObjectValidator->validate($field);

        $this->fieldGroup->addField($field);
        return true;
    }

    //-------------------------------------------
    // Handle join call
    //-------------------------------------------
    private function handleJoin($fullField, $param)
    {
        [$table, $field] = explode('.', $fullField);

        $join = $this->findJoin($table, $this->getJoins());
        if (!$join) return false;

        $fetcherClass = $join->getFetcherClass();
        $field = (new $fetcherClass)->makeFieldObject($field);
        if ($field === null) return false;

        $field->setJoin($join);
        $field->setValue($param);
        $this->fieldGroup->addField($field);

        if ($join !== null) $this->joinsToMake[$join->pathEnd()] = $join;

        return true;
    }

    private $searchedFetchers = [];

    private function findJoin($table, $availableJoins): ?Join
    {
        $availableJoins = array_diff($availableJoins, $this->searchedFetchers);
        foreach ($availableJoins as $availableJoin => $fetcherClass) {
            if (array_key_exists($table, $availableJoins)) {
                return new Join($table, $availableJoins[$table]);
            }

            $join = $this->findJoin($table, (new $fetcherClass)->getJoins());
            if ($join !== null) {
                $join->prependPath($fetcherClass::getTable());
                return $join;
            } else {
                $this->searchedFetchers[] = $fetcherClass;
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
     * @param string $fieldString
     * @return FieldObject|null
     */
    private function makeFieldObject(string $fieldString): ?FieldObject
    {
        if (preg_match($this->getFieldPrefixRegex(), $fieldString, $matches)) {
            return new FieldObject($matches[3], $this->getFields()[$matches[3]], $this->fieldPrefixes[$matches[2]]);
        } elseif (preg_match($this->getFieldSuffixRegex(), $fieldString, $matches)) {
            return new FieldObject($matches[2], $this->getFields()[$matches[2]], $this->fieldSuffixes[$matches[3]]);
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

        $query = "SELECT " . $selectString . " FROM " . $this->table . $joinString . $whereString . $limitString;

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

            foreach ($joinToMake->getTables() as $tableTo) {
                $joinMethod = 'join'.$this->studly($tableTo);
                if (!array_key_exists($tableFrom, $joinsMade) || !in_array($tableTo, $joinsMade[$tableFrom])) {
                    if (!method_exists($currentFetcher, $joinMethod)) throw new Exception(sprintf(
                        '%s misses join method %s', $currentFetcher->getName(), $joinMethod
                    ));

                    $js = $currentFetcher->{$joinMethod}();
                    if (!is_array($js)) $js = [$js];
                    foreach ($js as $j) $joinString .= ($joinToMake->isLeftJoin()?' LEFT':'').' JOIN '.$j;

                    $joinsMade[$tableFrom][] = $tableTo;
                    $fetcherTo = $currentFetcher->getJoins()[$tableTo];
                    $this->tableFetcherLookup[$tableTo] = $currentFetcher = new $fetcherTo();
                }
                $tableFrom = $tableTo;
            }
        }

        return $joinString;
    }

    private function getWhereString(array &$values = [])
    {
        $fieldToStringClosure = function (Field $field) use (&$fieldToStringClosure, &$values) {
            if ($field instanceof FieldObject) {
                $table = $field->getJoin()?$field->getJoin()->getFetcherClass()::getTable():$this::getTable();
                if (is_array($field->getValue())) {
                    $marks = [];
                    foreach ($field->getValue() as $v) {$values[] = $v; $marks[] = '?';}
                    $marks = '('.implode(', ', $marks).')';
                } else {
                    $values[] = $field->getValue(); $marks[] = '?';
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

        if ($this->isRaw) return $rows;
        throw new Exception('@TODO');
    }

    public function pluck(string $field)
    {
        $this->select([$field]);
        $this->isRaw = true;
        return $this->get();
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

        if ($this->isRaw) return $row;
        throw new Exception('@TODO');
    }

    public function value(string $field)
    {
        $this->select([$field]);
        $this->isRaw = true;
        $row = $this->first();
        return array_pop($row);
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
        if (array_key_exists(static::class, self::$tables)) self::$tables[static::class];
        $fetcher = new static();
        $table = $fetcher->table;
        self::$tables[static::class] = $table;
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

            if (strpos($field, '.') !== false) {
                [$table, $field] = explode('.', $field);
            } else {
                $table = $this->table;
            }

            $join = null;
            if ($table === $this->table) {
                $fields = array_keys($this->getFields());
            } elseif ($join = $this->findJoin($table, $this->getJoins())) {
                $class = $join->getFetcherClass();
                $fields = array_keys((new $class)->getFields());
            } else {
                throw new Exception('Could not find table '. $table);
            }

            if (!in_array($field, $fields) && $field !== '*') {
                throw new Exception(sprintf('Invalid field %s.%s', $table, $field));
            }

            if ($field === '*') {
                $this->addSelectFields($table, $fields);
            } else {
                $this->addSelectField($table, $field, $as);
            }

            if ($join !== null) {
                if (array_key_exists($join->pathEnd(), $this->joinsToMake)) {
                    $this->joinsToMake[$join->pathEnd()]->setFullJoin();
                } else {
                    $this->joinsToMake[$join->pathEnd()] = $join;
                }
            }
        }
        return $this;
    }

    private function addSelectFields(string $table, array $fields)
    {
        foreach ($fields as $field) {
            $as = null;
            if ($table !==  $this->table) $as = $table.'_'.$field;
            $this->addSelectField($table, $field, $as);
        }
    }

    private function addSelectField(string $table, string $field, ?string $as)
    {
        $selectString = sprintf('`%s`.`%s`%s', $table, $field, $as?' AS '.$as:'');
        $this->select[] = $selectString;
        $this->selectedFields[$as] = [$table, $field];
    }

    public function getSelect()
    {
        return $this->select;
    }

    public function limit(?int $limit)
    {
        $this->limit = $limit;
    }

    public function getLimit()
    {
        return $this->limit;
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
        if (preg_match('/(|^)([a-zA-Z._]+)( AS | as )([a-zA-Z]+)(|$)/', $field, $matches)){
            return [
                $matches[2],
                $matches[4]
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
