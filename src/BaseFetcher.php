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
use Fetcher\Exception\MaxSearchException;
use Fetcher\Field\Graph;
use Fetcher\Field\Operator;
use Fetcher\Field\SubFetchField;
use Fetcher\Validator\FieldObjectValidator;
use Fetcher\Field\Conjunction;
use Fetcher\Field\GroupField;
use Fetcher\Field\ObjectField;
use Fetcher\Join\Join;
use http\Message;

/**
 * Class BaseFetcher
 * @package Fetcher
 */
abstract class BaseFetcher implements Fetcher
{
    static mixed $connection = null;
    private ?FetcherCache $cache = null;

    private array $fieldPrefixes = [
        '' => Operator::EQUALS,
        'is_' => Operator::EQUALS,
        '$_' =>  Operator::EQUALS_FIELD,
        '$_is' =>  Operator::EQUALS_FIELD,
    ];

    private array $fieldSuffixes = [
        '' =>  Operator::EQUALS,
        '_is' =>  Operator::EQUALS,
        '_is_not' =>  Operator::NOT_EQUALS,
        '_gt' =>  Operator::GREATER,
        '_gte' =>  Operator::GREATER_OR_EQUAL,
        '_lt' =>  Operator::LESS,
        '_lte' =>  Operator::LESS_OR_EQUAL,
        '_$' =>  Operator::EQUALS_FIELD,
        '_is_$' =>  Operator::EQUALS_FIELD,
        '_is_not_$' =>  Operator::NOT_EQUALS_FIELD,
        '_gt_$' =>  Operator::GREATER_FIELD,
        '_gte_$' =>  Operator::GREATER_OR_EQUAL_FIELD,
        '_lt_$' =>  Operator::LESS_FIELD,
        '_lte_$' =>  Operator::LESS_OR_EQUAL_FIELD,
        '_like' =>  Operator::LIKE,
        '_in' =>  Operator::IN,
        '_in_like' =>  Operator::IN_LIKE,
        '_not_in' =>  Operator::NOT_IN
    ];

    protected ?string $table = null;
    protected ?string $key = 'id';
    protected ?GroupField $fieldGroup = null;
    /**
     * @var array|Join[]
     */
    protected array $joinsToMake = [];
    protected ?string $queryString;
    protected ?array $queryValues;
    protected array $select;
    /**
     * @var array|string[]
     */
    private array $selectedFields;
    /**
     * @var array|BaseFetcher[]
     */
    protected array $tableFetcherLookup;
    protected ?int $take = null;
    protected ?int $skip = null;
    private FieldObjectValidator $fieldObjectValidator;
    protected ?array $groupByFields = null;
    protected ?array $orderByFields;
    private array $groupedFields = [];
    protected string $orderByDirection;

    protected static array $joinsAs = [];

    protected array $subFetches = [];

    protected static ?int $MaxSearchDepth = null;
    protected static ?int $MaxJoinDepth = null;


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

    public abstract static function setConnection($connection): void;

    #region Fetcher Getters/Setters
    public static function getTable(): ?string
    {
        $fetcher = new static();
        return $fetcher->table;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return str_replace(['/', '\\'], '.', get_class($this));
    }

    public abstract function getFields(): array;

    private function getFieldType(string $field): string
    {
        return $this->getFields()[$field];
    }

    public abstract function getJoins(): array;
    #endregion

    #region Settings Getters/Setters
    public static function getMaxSearchDepth(): ?int
    {
        return self::$MaxSearchDepth;
    }

    public static function setMaxSearchDepth(?int $MaxSearchDepth): void
    {
        self::$MaxSearchDepth = $MaxSearchDepth;
    }

    public static function getMaxJoinDepth(): ?int
    {
        return self::$MaxJoinDepth;
    }

    public static function setMaxJoinDepth(?int $MaxJoinDepth): void
    {
        self::$MaxJoinDepth = $MaxJoinDepth;
    }
    #endregion

    #region Build Methods
    public static function build(): self
    {
        return self::buildAnd();
    }

    public static function buildAnd(): self
    {
        $fetcher = new static();
        $fetcher->cache = FetcherCache::Instance($fetcher);

        $fetcher->reset();

        $fetcher->fieldGroup = new GroupField(Conjunction::AND, []);

        return $fetcher;
    }

    public static function buildOr(): self
    {
        $fetcher = new static();
        $fetcher->cache = FetcherCache::Instance($fetcher);

        $fetcher->reset();

        $fetcher->fieldGroup = new GroupField(Conjunction::OR, []);

        return $fetcher;
    }

    private function reset(): void
    {
        $this->joinsToMake = [];

        $this->queryString = null;
        $this->queryValues = null;

        $this->groupedFields = [];

        if ($this->key !== null) {
            $this->groupByFields[$this->table][] = $this->key;
        } else {
            $this->groupByFields = null;
        }

        $this->orderByFields = null;
        $this->orderByDirection = 'desc';

        $this->select();
    }

    #endregion

    //-------------------------------------------
    // Fetcher mapping
    //-------------------------------------------
    private function getTablePath(string $tableFrom, string $tableTo): ?array
    {
        $graph = new Graph($this->cache->getGraph());
        $tablePath = $graph->breadthFirstSearch($tableFrom, $tableTo);
        if ($tablePath === null)
        {
            dd($tableFrom, $tableTo, $this->cache->getGraph());
        }
        if ($tablePath === null) return null;
        if (self::getMaxSearchDepth() !== null && (count($tablePath) - 1) > self::getMaxSearchDepth())
        {
            throw new MaxSearchException($tablePath);
        }

        return $tablePath;
    }

    #region Magic Method
    public function __call($method, $params)
    {
        if ($this->isWhereCall($method)) {
            if (count($params) === 0) throw new Exception('Missing parameter 1');

            $fullField = $this->snake(substr($method, 5));
            [$field, $operator] = $this->separateOperator($fullField);
            $value = $params[0];

            if ($field === null)
                throw new Exception('Cannot find field '.$fullField);

            $success = $this->handleWhere($field, $operator, $value);
            if (!$success)
                throw new Exception('Cannot find field '.$field);
        } else {
//            dd($this::class, $method);
            throw new BadMethodCallException(sprintf('Call to unknown method %s from fetcher %s', $method, static::class));
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

    private function isWhereCall(string $method): bool
    {
        return substr($method, 0, 5) === "where";
    }
    #endregion

    public function or(Closure $closure): static
    {
        $this->handleGroup(Conjunction::OR, $closure);
        return $this;
    }

    public function and(Closure $closure): static
    {
        $this->handleGroup(Conjunction::AND, $closure);
        return $this;
    }

    public function sub(string $table, Closure $closure, ?string $method, ?string $as): static
    {
        $join = $this->findJoin([$table]);

        /** @var MySqlFetcher $fetcherClass */
        $fetcherClass = $join->getFetcherClass();

        $fetcher = $fetcherClass::buildAnd();
        $reverseJoin = $fetcher->findJoin([$this->table]);
        $closure($fetcher);
        $this->fieldGroup->addField(new SubFetchField($fetcher, $reverseJoin, $table, $method, null, $as));
        return $this;
    }

    //-------------------------------------------
    // Handle where call
    //-------------------------------------------
    public function where($fullField, $operator, $value = null): self
    {
        if (func_num_args() === 2)
        {
            $value = $operator;
            $operator = '=';
        }

        $this->addFieldObject($fullField, $operator, $value);

        return $this;
    }

    private function handleWhere($fullField, $operator, $value): bool
    {
        $field = $this->addFieldObject($fullField, $operator, $value);
        return $field?true:false;
    }

    //-------------------------------------------
    // Handle join call
    //-------------------------------------------
    private function handleJoin(string $tablePath, string $field): ?Join
    {
        $tables = explode('.', $tablePath);

        $join = $this->findJoin($tables);
        if ($join === null) return null;

        /** @var BaseFetcher $foundFetcher */
        $foundFetcher = new ($join->getFetcherClass())();

        if (!$foundFetcher->hasField($field))
            return null;

        $join->setValueType($foundFetcher->getFieldType($field));

        $this->joinsToMake[$join->pathEndAs()] = $join;

        return $join;
    }

    private function findJoin(array $tablePath): ?Join
    {
        $originalTablePath = $tablePath;
        $pathEnd = $tableAs = $table = array_pop($tablePath);
        if (array_key_exists($table, self::$joinsAs)) $table = $pathEnd = self::$joinsAs[$table]['table'];

        $pathTraveled = false;

        $fullJoinPath = null;

        $baseTableFrom = $tableFrom = $this->table;
        $toId = null;

        $tableMappings = [];
        $joinNames = [];

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

            $joinPath = $this->getTablePath($tableFrom, $tableTo);
            if ($joinPath === null)
            {
                $this->addBuildError(sprintf('Missing path from %s -> %s', $tableFrom, $tableTo));
                return null;
            }
            if ($fullJoinPath)
            {
                array_shift($joinPath);
                $fullJoinPath = array_merge($fullJoinPath, $joinPath);
            }
            else
            {
                $fullJoinPath = $joinPath;
            }

            $tableFrom = $tableTo;

        } while (!$pathTraveled);

        $tableFrom = $fetcherTableFrom = null;

        $join = new Join();

        foreach ($fullJoinPath as $joinName) {
            if ($tableFrom === null) {
                $tableFrom = $joinName;
                continue;
            }

            $fetcherClass = $this->cache->getFetcherClass($tableFrom, $joinName);
            $tableTo = $fetcherClass::getTable();

            $join->addLink($tableFrom, $tableTo, $joinName, $fetcherClass);
            $tableFrom = $tableTo;
        }

        foreach ($tableMappings as $a => $b) {
            $join->addTableMapping($b, $a);
        }
        $passedTablePath = null;
        foreach ($originalTablePath as $tableInPath)
        {
            $tableInPath = $join->getTableAs($tableInPath);

            if ($passedTablePath === null) {
                $passedTablePath = $tableInPath;
                continue;
            }
            $passedTablePath .= '_'.$tableInPath;
            $join->addTableMapping($tableInPath, $passedTablePath);
        }

        return $join;
    }

    //-------------------------------------------
    // Handle array call
    //-------------------------------------------
    public static function buildFromArray(array $data): static
    {
        $fetcher = new static();
        $fetcher->cache = FetcherCache::Instance($fetcher);

        $fetcher->reset();

        $fetcher->fieldGroup = new GroupField($data['type'], []);

        if (array_key_exists('joins_as', $data)) {
            foreach ($data['joins_as'] as $joinAs) {
                $fetcher->addJoinAs($joinAs['table'], $joinAs['as'], $joinAs['filter']);
            }
        }

        $fields = $data['fields'];
        $fetcher->handleArray($fields);

        if (array_key_exists('select', $data)) $fetcher->select($data['select']);

        return $fetcher;
    }

    private function handleArray(array $fields): void
    {
        foreach ($fields as $field) {
            if ($this->isParamField($field)) {
                $fullField = $field['param'];
                [$fullField, $operator] = $this->separateOperator($fullField);
                $success = $this->handleWhere($fullField, $operator, $field['value']);

                if (!$success)
                    throw new Exception('Cannot find field '.$fullField);
            } elseif ($this->isGroupField($field)) {
                $repo = $field['type']===Conjunction::OR?self::buildOr():self::buildAnd();
                $repo->handleArray($field['fields']);
                $this->fieldGroup->addField($repo->fieldGroup);
                $this->joinsToMake = array_merge($this->joinsToMake, $repo->joinsToMake);
            }  elseif ($this->isSubField($field)) {
                $method = $field['method']?:'get';
                $methodField = null;
                if (str_contains($method, ':')) [$method, $methodField] = explode(':', $method);

                $join = $this->findJoin([$field['table']]);
                $fetcher = $join->getFetcherClass()::buildFromArray($field['sub']);
                $reverseJoin = $fetcher->findJoin([$this->table]);
                $this->fieldGroup->addField(new SubFetchField(
                    $fetcher,
                    $reverseJoin,
                    $field['table'],
                    $method,
                    $methodField,
                    array_key_exists('as', $field)?$field['as']:null)
                );
            } else {
                throw new Exception('Cannot handle given field');
            }
        }
    }

    private function isParamField(array $field): bool
    {
        return array_key_exists('param', $field) && array_key_exists('value', $field);
    }

    private function isGroupField(array $group): bool
    {
        return array_key_exists('type', $group) && array_key_exists('fields', $group);
    }

    private function isSubField(array $sub): bool
    {
        return array_key_exists('sub', $sub) && array_key_exists('table', $sub);
    }

    //-------------------------------------------
    // Handle group call
    //-------------------------------------------
    private function handleGroup($fullField, $param): void
    {
        $repo = $fullField===Conjunction::OR?self::buildOr():self::buildAnd();
        $param($repo);
        $group = $repo->fieldGroup;
        foreach ($repo->joinsToMake as $joinToMake) {
            $this->joinsToMake[$joinToMake->getPathAs()] = $joinToMake;
        }
        $this->fieldGroup->addField($group);
    }

    //-------------------------------------------
    // Field object
    //-------------------------------------------
    private function addFieldObject(string $fullField, string $operator, $value): ?ObjectField
    {
        $object = $this->makeFieldObject($fullField, $operator, $value);

        if ($object === null) return null;

        $this->fieldGroup->addField($object);

        return $object;
    }

    private function makeFieldObject(string $fullField, string $operator, $value): ?ObjectField
    {
        if (!$this->validateOperator($operator)) return null;

        $join = null;
        $valueJoin = null;

        [$tablePath, $field] = $this->separateField($fullField);

        if ($tablePath !== null)
        {
            $join = $this->handleJoin($tablePath, $field);
            if ($join === null) return null;
        }
        elseif (!$this->hasField($field))
        {
            return null;
        }

        if (Operator::IsFieldOperator($operator))
        {
            [$valueTablePath, $valueField] = $this->separateField($value);

            if ($valueTablePath !== null)
            {
                $valueJoin = $this->handleJoin($valueTablePath, $valueField);
                if ($valueJoin === null) return null;
                $value = $valueField;
            }
            elseif (!$this->hasField($valueField))
            {
                return null;
            }
        }

        $object = new ObjectField($field, $join?$join->getValueType():$this->getFieldType($field), $operator, $value);
        if ($join !== null) $object->setJoin($join);
        if ($valueJoin !== null) $object->setValueJoin($valueJoin);

        $this->fieldObjectValidator->validate($object);

        return $object;
    }

    private function separateOperator(string $fullField): ?array
    {
        $fieldParts = explode('.', $fullField);
        $columnPart = array_pop($fieldParts);

//        $prefixRegex = '/( |^)(\$_|\$_is_)([a-z_]+?)( |$)/';
        $suffixRegex = '/( |^)([a-z_]+?)(_is|_is_not|_gt|_gte|_lt|_lte|_\$|_is_\$|_is_not_\$|_gt_\$|_gte_\$|_lt_\$|_lte_\$|_like|_not_in|_in|_in_like)( |$)/';

//        if (preg_match($prefixRegex, $columnPart, $matches))
//        {
//            $fieldParts[] = $matches[3];
//            $operator = $this->fieldPrefixes[$matches[2]];
//        }
        if (preg_match($suffixRegex, $columnPart, $matches))
        {
            $fieldParts[] = $matches[2];
            $operator = $this->fieldSuffixes[$matches[3]];
        }
        else
        {
            $fieldParts[] = $columnPart;
            $operator = '=';
        }

        $fullField = implode('.', $fieldParts);

        return [$fullField, $operator];
    }

    private function separateField(string $fullField)
    {
        $fieldParts = explode('.', $fullField);
        $field = array_pop($fieldParts);

        if (count($fieldParts) > 0 && $fieldParts[0] === self::getTable())
        {
            array_shift($fieldParts);
        }

        $tablePath = null;
        if (count($fieldParts) > 0)
            $tablePath = implode('.', $fieldParts);

        return [$tablePath, $field];
    }

    //-------------------------------------------
    // Execution
    //-------------------------------------------
    abstract protected function buildQuery();

    abstract protected function executeQuery(): array;
    
    public function get(): array
    {
        $this->buildQuery();

        $rows = $this->executeQuery();

        if (count($this->groupedFields) > 0) {
            foreach ($rows as $index => $row) {
                foreach ($this->groupedFields as $groupField)
                {
                    if (array_key_exists($groupField, $row) && $row[$groupField] !== null) {
                        $rows[$index][$groupField] = array_values(array_unique(explode(',', $row[$groupField])));
                    } else {
                        $rows[$index][$groupField] = [];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Return and array containing the single field
     *
     * @param string $field
     * @return array
     * @throws Exception
     */
    public function pluck(string $field): array
    {
        $this->select([$field]);
        return array_map(fn ($i) => array_pop($i), $this->get());
    }

    /**
     * Get the first result or null if empty
     *
     * @return mixed|null
     * @throws Exception
     */
    public function first(): ?array
    {
        $this->take(1);
        $this->buildQuery();

        $rows = $this->executeQuery();
        $row = array_pop($rows);
        if ($row === null) return null;

        if (count($this->groupedFields) > 0) {
            foreach ($this->groupedFields as $groupField)
            {
                if (array_key_exists($groupField, $row) && $row[$groupField] !== null) {
                    $row[$groupField] = array_values(array_unique(explode(',', $row[$groupField])));
                } else {
                    $row[$groupField] = [];
                }
            }
        }

        return $row;
    }

    /**
     * Get a single value from first result null if empty
     *
     * @param string $field
     * @return mixed|null
     * @throws Exception
     */
    public function value(string $field)
    {
        $this->select([$field]);
        $row = $this->first();
        if ($row === null) return null;
        return array_pop($row);
    }

    public function count(): int
    {
        $this->select = [sprintf('count(DISTINCT `%s`.`%s`) as total', $this->table, $this->key)];
        $this->groupByFields = null;
        $row = $this->first();

        $count = $row?(int) $row['total']:0;

        return $count;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function sum(string $field): int
    {
        $this->select([$field]);

        [$table, $field] = $this->explodeField($field);

        $this->select = [sprintf('sum(`%s`.`%s`) as total', $table, $field)];
        $this->groupByFields = null;
        $row = $this->first();

        $sum = $row?(int) $row['total']:0;

        return $sum;
    }

    #region Debug/Helper methods
    public function toSql(bool $withBindings = false): string
    {
        $this->buildQuery();

        if (!$withBindings) return $this->queryString;
        return sprintf(str_replace('?', '%s', $this->queryString), ...$this->queryValues);
    }

    public function dumpInfo(): array
    {
        return [
            'joins' => $this->joinsToMake,
            'select' => $this->select
        ];
    }
    # endregion

    #region Select Method
    public function select(?array $select = null): static
    {
        if ($select === null) $select = ["*"];

        $this->select = [];

        foreach ($select as $field) {
            [$field, $as] = $this->separateAs($field);
            [$field, $modifier] = $this->separateModifier($field);

            $tables = null;
            if (str_contains($field, '.')) {
                $tables = explode('.', $field);
                $field = array_pop($tables);
                $table = array_pop($tables);
                $tables[] = $table;
            } else {
                $table = $tables[] = $this->table;
            }

            $join = null;
            if ($table === $this->table)
            {
                $fields = array_keys($this->getFields());
            }
            elseif ($join = $this->findJoin($tables))
            {
                $class = $join->getFetcherClass();
                $fields = array_keys((new $class)->getFields());
            }
            else
            {
                throw new Exception('Could not find table '. $table);
            }

            if (!in_array($field, $fields) && $field !== '*') {
                throw new Exception(sprintf('Invalid field %s.%s', $table, $field));
            }

            $tableAs = $table;
            if ($join)
            {
                $tableAs = $join->getTableAs($table);
                if ($tableAs !== $table)
                {
                    $as = $tableAs.'_'.$field;
                }
            }

            if ($field === '*') {
                $this->addSelectFields($tableAs, $fields);
            } else {
                $this->addSelectField($tableAs, $field, $as, $modifier);
            }


            if ($join !== null)
            {
                if (!array_key_exists($join->getPathAs(), $this->joinsToMake))
                {
                    $this->joinsToMake[$join->getPathAs()] = $join;
                }
            }
        }
        return $this;
    }

    private function addSelectFields(string $table, array $fields): void
    {
        foreach ($fields as $field) {
            $as = null;
            if ($table !== $this->table) $as = $table.'_'.$field;
            $this->addSelectField($table, $field, $as, null);
        }
    }

    private function addSelectField(string $table, string $field, ?string $as, ?string $modifier): void
    {
        $fullField = sprintf('`%s`.`%s`', $table, $field);
        if ($modifier === 'group') {
            $fullField = sprintf('GROUP_CONCAT(%s)', $fullField);
            $as = $as?:$field;
            $this->groupedFields[] = $as;
        } elseif ($modifier === 'count') {
            $fullField = sprintf('COUNT(%s)', $fullField);
            $as = $as?:$field;
        }

        $selectString = sprintf('%s%s', $fullField, $as?' AS '.$as:'');
        $this->select[] = $selectString;
        $this->selectedFields[$as?:$field] = [$table, $field, $modifier];
    }

    public function getSelect(): array
    {
        return $this->select;
    }
    #endregion

    #region Take Method
    public function take(?int $take): static
    {
        $this->take = $take;
        return $this;
    }

    public function getTake(): ?int
    {
        return $this->take;
    }
    #endregion

    #region Skip Method
    public function skip(?int $skip): static
    {
        $this->skip = $skip;
        return $this;
    }

    public function getSkip(): ?int
    {
        return $this->skip;
    }
    #endregion

    #region OrderBy Method
    public function orderBy(array $fields, string $direction): static
    {
        if (empty($fields)) return $this->clearOrderBy();
        foreach ($fields as $field) {
            [$table, $field] = $this->explodeField($field);
            $this->orderByFields[$table][] = $field;
        }
        $this->orderByDirection = $direction;
        return $this;
    }

    public function clearOrderBy(): static
    {
        $this->orderByFields = null;
        return $this;
    }
    #endregion

    public function addJoinAs(string $table, string $as, ?string $filter): static
    {
        self::$joinsAs[$as] = [
            'table' => $table,
            'as' => $as,
            'filter' => $filter
        ];
        return $this;
    }

    #region Validation Methods
    private function hasField(string $field): bool
    {
        return array_key_exists($field, $this->getFields());
    }

    private function validateOperator(string $operator): bool
    {
        return Operator::isValidValue($operator);
    }
    #endregion

    #region String Helpers
    protected function studly($value): array|string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    protected function snake($value)
    {
        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));

            $value = strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $value));
        }
        return $value;
    }

    private function separateAs(string $field): array
    {
        if (preg_match('/(|^)([()a-zA-Z._*]+)( AS | as | As | aS )([()a-zA-Z._]+)(|$)/', $field, $matches)){
            return [
                $matches[2],
                $matches[4]
            ];
        }
        return [$field, null];
    }

    private function separateModifier(string $field): array
    {
        if (preg_match('/(|^)(group|count|)(\()([()a-zA-Z._*]+)(\))(|$)/', $field, $matches)){
            return [
                $matches[4],
                $matches[2]
            ];
        }
        return [$field, null];
    }

    private array $tableFields = [];

    protected function explodeField(string $field): array
    {
        if (array_key_exists($field, $this->tableFields)) return $this->tableFields[$field];
        $field = str_replace('`', '', $field);

        $pos = strpos($field, '.');
        if ($pos === false) {
            $table = $this->table;
        } else {
            [$table, $field] = explode('.', $field);
        }

        return $this->tableFields[$field] = [$table, $field];
    }


    #endregion

    //-------------------------------------------
    // Error
    //-------------------------------------------
    protected array $buildErrors = [];

    protected function isValidBuild(): bool
    {
        return count($this->buildErrors) === 0;
    }

    protected function addBuildError(string $error): void
    {
        $this->buildErrors[] = $error;
    }
}
