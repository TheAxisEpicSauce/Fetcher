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
use Fetcher\Field\Graph;
use Fetcher\Field\Operator;
use Fetcher\Field\SubFetchField;
use Fetcher\Validator\FieldObjectValidator;
use Fetcher\Field\Conjunction;
use Fetcher\Field\GroupField;
use Fetcher\Field\ObjectField;
use Fetcher\Join\Join;

/**
 * Class BaseFetcher
 * @package Fetcher
 * @method self where(string $field, null|string|int $operator, null|string|int|array $value = null)
 */
abstract class BaseFetcher implements Fetcher
{
    static mixed $connection = null;

    private array $fieldPrefixes = [
        '' => Operator::EQUALS,
        'is_' => Operator::EQUALS
    ];

    private array $fieldSuffixes = [
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

    public function getKey()
    {
        return $this->key;
    }

    public function getName(): string
    {
        return str_replace(['/', '\\'], '.', get_class($this));
    }

    private function fetcherId()
    {
        return FetcherCache::Instance()->getFetcherIds()[static::class];
    }

    //-------------------------------------------
    // New instance
    //-------------------------------------------
    public static function build(): self
    {
        return self::buildAnd();
    }

    public static function buildAnd(): self
    {
        $fetcher = new static();

        $fetcher->reset();

        $fetcher->fieldGroup = new GroupField(Conjunction::AND, []);

        return $fetcher;
    }

    public static function buildOr(): self
    {
        $fetcher = new static();

        $fetcher->reset();

        $fetcher->fieldGroup = new GroupField(Conjunction::OR, []);

        return $fetcher;
    }

    private function reset()
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

    //-------------------------------------------
    // Fetcher mapping
    //-------------------------------------------
    private array $fetcherIds = [];
    private array $fetchers = [];
    private array $tableIds = [];
    private array $tables = [];
    private array $visitedFetchers = [];
    private array $fetcherNodes = [];
    private array $tableNodes = [];
    private array $tableFetcherMap = [];

    private function joinIdList(int $fromId, int $toId): ?string
    {
        $graph = new Graph(FetcherCache::Instance()->getGraph());
        return $graph->breadthFirstSearch($fromId, $toId);;
        return null;
    }

    private function getTableId($table): int
    {
        if (!array_key_exists($table, $this->tableIds)) {
            static $tableId = 0; $tableId++;
            $this->tableIds[$table] = $tableId;
            $this->tables[$tableId] = $table;
        }

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
            if (!$success && strpos($field, '.')) {
                $success = $this->handleJoin($field, $value, $operator);
            }
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

        $fetcherClass = $join->getFetcherClass();


        $fetcher = $fetcherClass::buildAnd();
        $reverseJoin = $fetcher->findJoin([$this->table]);
        $closure($fetcher);
        $this->fieldGroup->addField(new SubFetchField($fetcher, $reverseJoin, $table, $method, null, $as));
        return $this;
    }

    private function isWhereCall(string $method): bool
    {
        return substr($method, 0, 5) === "where";
    }

    //-------------------------------------------
    // Handle where call
    //-------------------------------------------
    private function handleWhere($fullField, $param, ?string $operator = null): bool
    {
        $field = $this->makeFieldObject($fullField, $param, $operator);
        if ($field === null) return false;

        $this->fieldGroup->addField($field);

        return true;
    }

    //-------------------------------------------
    // Handle join call
    //-------------------------------------------
    private function handleJoin($fullField, $param, ?string $operator = null): bool
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

        $this->joinsToMake[$join->pathEndAs()] = $join;

        return true;
    }

    private function findJoin(array $tablePath): ?Join
    {
        $pathEnd = $tableAs = $table = array_pop($tablePath);
        if (array_key_exists($table, self::$joinsAs)) $table = $pathEnd = self::$joinsAs[$table]['table'];

        $pathTraveled = false;

        $baseId = $fromId = $this->fetcherId();

        $list = null;
        $toId = null;

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

            $toId = FetcherCache::Instance()->getTableId($tableTo);

            $list = $this->joinIdList($fromId, $toId);
            $fromId = $toId;

        } while (!$pathTraveled);

        $list = array_reverse(explode('->', $list));

        $tableTo = FetcherCache::Instance()->getTable($toId);
        $join = new Join(FetcherCache::Instance()->getFetcher($toId));

        foreach ($list as $id) {
            $id = (int) $id;
            if ($id === $toId) continue;
            $join->addLink(FetcherCache::Instance()->getTable($id), $tableTo, $this->joinType($id, $toId));
            $tableTo = FetcherCache::Instance()->getTable($id);
        }

        foreach ($tableMappings as $a => $b) {
            $join->addTableMapping($b, $a);
        }
        $join->addTableMapping($table, $tableAs);

        return $join;
    }

    protected function joinType(int $fromId, int $toId): string
    {
        $fetcherFrom = new (FetcherCache::Instance()->getFetcher($fromId));

        $keyFrom = FetcherCache::Instance()->getFetcherKey($fromId);
        $keyTo = FetcherCache::Instance()->getFetcherKey($toId);

        $tableFrom = FetcherCache::Instance()->getTable($fromId);
        $tableTo = FetcherCache::Instance()->getTable($toId);
        $method = 'join'.$this->studly($tableTo);

        if (is_array($fetcherFrom->$method())) return 'MANY_TO_MANY';

        $string = strtolower($fetcherFrom->$method());
        if(strpos($string, 'and')) $string = substr($string, strpos($string, 'and'));
        $string = str_replace(['`', ' '], '', $string);

        $list = explode('=', $string);
        $fromMatches = false;
        $toMatches = false;
        foreach ($list as $item)
        {
            [$table, $field] = $this->explodeField($item);
            if ($table === $tableFrom && $field === $keyFrom) $fromMatches = true;
            if ($table === $tableTo && $field == $keyTo) $toMatches = true;
        }

        $type = 'HAS_MANY';
        if ($fromMatches) $type = 'HAS_MANY';
        elseif ($toMatches) $type = 'BELONGS_TO';
        else $type = 'MANY_TO_MANY';

        return $type;
    }

    //-------------------------------------------
    // Handle array call
    //-------------------------------------------
    public static function buildFromArray(array $data): static
    {
        $fetcher = new static();
//        $fetcher->mapFetchers(get_class($fetcher), $fetcher->table);

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

    private function handleArray(array $fields)
    {
        foreach ($fields as $field) {
            if ($this->isParamField($field)) {
                $success = $this->handleWhere($field['param'], $field['value']);
                if (!$success) $this->handleJoin($field['param'], $field['value']);
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
    private function handleGroup($fullField, $param): bool
    {
        $repo = $fullField===Conjunction::OR?self::buildOr():self::buildAnd();
        $param($repo);
        $group = $repo->fieldGroup;
        foreach ($repo->joinsToMake as $joinToMake) {
            $this->joinsToMake[$joinToMake->pathEndAs()] = $joinToMake;
        }
        $this->fieldGroup->addField($group);
        return true;
    }

    //-------------------------------------------
    // Field object
    //-------------------------------------------
    private function makeFieldObject(string $fullField, $value, ?string $operator = null): ?ObjectField
    {
        if ($operator === null) {
            $fieldData = $this->splitFullField($fullField);
            if ($fieldData === null) return null;
            [$field, $operator] = $fieldData;
        } else {
            $field = $fullField;
        }

        [$table, $field] = $this->explodeField($field);
        if ($table !== $this->table) return null;

        if (!$this->validateField($field)) return null;
        if (!$this->validateOperator($operator)) return null;

        $object = new ObjectField($field, $this->getFieldType($field), $operator, $value);

        $this->fieldObjectValidator->validate($object);

        return $object;
    }

    private function splitFullField(string $fullField): ?array
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

    //-------------------------------------------
    // Public Other
    //-------------------------------------------
    public function toSql(bool $withBindings = false): string
    {
        $this->buildQuery();

        if (!$withBindings) return $this->queryString;
        return sprintf(str_replace('?', '%s', $this->queryString), ...$this->queryValues);
    }

    //-------------------------------------------
    // Private
    //-------------------------------------------
    public abstract function getFields(): array;

    private function getFieldType(string $field): string
    {
        return $this->getFields()[$field];
    }

    public abstract function getJoins(): array;

    private function getFieldPrefixRegex(): string
    {
        return FetcherCache::Instance()->getPrefix($this->fetcherId());
    }

    private function getFieldSuffixRegex(): string
    {
        return FetcherCache::Instance()->getSuffix($this->fetcherId());
    }

    public static function getTable(): ?string
    {
        $fetcher = new static();
        return $fetcher->table;
    }

    //-------------------------------------------
    // QueryParams
    //-------------------------------------------
    public function select(?array $select = null): static
    {
        if ($select === null) $select = ["*"];

        $this->select = [];

        foreach ($select as $field) {
            [$field, $as] = $this->separateAs($field);
            [$field, $modifier] = $this->separateModifier($field);

            $tables = null;
            if (strpos($field, '.') !== false) {
                $tables = explode('.', $field);
                $field = array_pop($tables);
                $table = array_pop($tables);
                $tables[] = $table;
            } else {
                $table = $tables[] = $this->table;
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

            if ($field === '*') {
                $this->addSelectFields($table, $fields);
            } else {
                $this->addSelectField($table, $field, $as, $modifier);
            }

            if ($join !== null) {
                if (array_key_exists($join->pathEndAs(), $this->joinsToMake)) {
//                    $this->joinsToMake[$join->pathEndAs()]->setFullJoin();
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

    public function take(?int $take): static
    {
        $this->take = $take;
        return $this;
    }

    public function getTake(): ?int
    {
        return $this->take;
    }

    public function skip(?int $skip): static
    {
        $this->skip = $skip;
        return $this;
    }

    public function getSkip(): ?int
    {
        return $this->skip;
    }

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

    public function addJoinAs(string $table, string $as, ?string $filter): static
    {
        self::$joinsAs[$as] = [
            'table' => $table,
            'as' => $as,
            'filter' => $filter
        ];
        return $this;
    }

    //-------------------------------------------
    // Validate
    //-------------------------------------------
    private function validateField(string $field): bool
    {
        return array_key_exists($field, $this->getFields());
    }

    private function validateOperator(string $operator): bool
    {
        return Operator::isValidValue($operator);
    }

    //-------------------------------------------
    // Helpers
    //-------------------------------------------
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
        if (preg_match('/(|^)([()a-zA-Z._*]+)( AS | as )([()a-zA-Z._]+)(|$)/', $field, $matches)){
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

    //-------------------------------------------
    // Table field helper
    //-------------------------------------------
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

    //-------------------------------------------
    // Field Parsing
    //-------------------------------------------
    private array $parseFunctions = [];

    private function addParseClosure(string $field, Closure $closure)
    {
        [$table, $field] = $this->explodeField($field);

        $this->parseFunctions[$table][$field] = $closure;
    }

    private function hasParseMethod(string $field): bool
    {
        [$table, $field] = $this->explodeField($field);

        if (array_key_exists($table, $this->parseFunctions)) {
            if (array_key_exists($field, $this->parseFunctions[$table])) {
                if ($this->parseFunctions[$table][$field] === null) return false;
                return true;
            }
        }

        $method = 'parse'.$this->studly($field).'Field';

        $exists = method_exists($this->tableFetcherLookup[$table], $method);
        $this->parseFunctions[$table][$field] = $exists?$method:null;
        return $exists;
    }

    private function parseField(string $field, $value)
    {
        [$table, $field] = $this->explodeField($field);

        if (!$this->hasParseMethod($field)) return $value;

        $function = $this->parseFunctions[$table][$field];

        if (is_string($function)) {
            return (new $this->tableFetcherLookup[$table])->{$function}($value);
        } elseif ($function instanceof Closure) {
            return $function($value);
        }

        return $value;
    }

    //-------------------------------------------
    // Error
    //-------------------------------------------
    protected array $buildErrors = [];

    protected function isValidBuild(): bool
    {
        return count($this->buildErrors) === 0;
    }

    protected function addBuildError(string $error)
    {
        $this->buildErrors[] = $error;
    }
}
