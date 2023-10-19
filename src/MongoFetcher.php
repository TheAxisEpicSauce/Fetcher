<?php
/**
 * User: Raphael Pelissier
 * Date: 20-09-21
 * Time: 13:29
 */

namespace Fetcher;


use Exception;
use Fetcher\Field\Conjunction;
use Fetcher\Field\GroupField;
use Fetcher\Field\ObjectField;
use Fetcher\Field\Operator;
use Fetcher\Join\Join;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;

abstract class MongoFetcher extends BaseFetcher
{
    static mixed $connection = null;

    private $operators = [
        Operator::EQUALS => '$eq',
        Operator::NOT_EQUALS => '$ne',
        Operator::GREATER => '$gt',
        Operator::GREATER_OR_EQUAL => '$gte',
        Operator::LESS => '$lt',
        Operator::LESS_OR_EQUAL => '$lte'
    ];


    public static function setConnection($connection): void
    {
        if (!$connection instanceof Database) throw new \Exception('invalid connection type');

        static::$connection = $connection;
    }

    private $lookup = null;
    private $match = null;
    private $project = null;
    private $sort = null;

    protected function buildQuery()
    {
//        $this->lookup = $this->buildLookup($this->joinsToMake);
        $this->match = $this->buildMatch($this->fieldGroup);
        $this->project = $this->buildProject($this->select);
        $this->sort = $this->buildSort($this->orderByFields, $this->orderByDirection);
    }

    private function buildLookup(array $joins)
    {
        $lookup = [];

        /** @var Join[] $joins */
        usort($joins, function ($a, $b) {
            return $b->pathLength() - $a->pathLength();
        });

        $joinsMade = [];

        foreach ($joins as $join) {
            $fetcher = $this;
            $table = $this->table;


            $tables = $join->getTables();
            foreach ($tables as $tableTo) {
                $tableToAs = $join->getTableAs($tableTo);
                if (array_key_exists($table, $joinsMade) && in_array($tableToAs, $joinsMade[$table])) {
                    # Join already added, continue to next
                    echo '(continue 1)';
                    continue;
                }


                $joinMethod = 'join'.$this->studly($tableTo);

                if (!method_exists($fetcher, $joinMethod)) {
                    $this->addBuildError(sprintf('%s misses join method %s', $fetcher->getName(), $joinMethod));
                    echo '(continue 2)';
                    continue;
                }

                $fetcherTo = $fetcher->getJoins()[$tableTo];

                $params = $fetcher->{$joinMethod}();

                $lookup[] = [
                    'from' => $tableTo,
                    'localField' => $params[0],
                    'foreignField' => $params[1],
                    'as' => $tableToAs
                ];

                $joinsMade[$table] = $tableToAs;

                $fetcher = $fetcherTo;
            }
        }

        return $lookup;
    }

    private function buildMatch(GroupField $group)
    {
        $match = [];

        foreach ($group->getFields() as $field) {
            if ($field instanceof ObjectField) {
                $match[] =  [$field->getField() => [$this->operators[$field->getOperator()] => $field->getValue()]];
            } else {
                $match[] = $this->buildMatch($field);
            }
        }

        if (empty($match)) return [];

        if ($group->getConjunction() === Conjunction::AND) {
            return ['$and' => $match];
        } else {
            return ['$or' => $match];
        }
    }

    private function buildProject(array $select)
    {
        $project = [];
        foreach ($select as $field) {
            $field = str_replace([$this->table, '.', '`'], '', $field);
            $project[$field] = 1;
        }
        return $project;
    }

    private function buildSort(?array $orderByFields, string $directions)
    {
        if ($orderByFields === null) return [];

        $sort = [];

        foreach ($orderByFields as $table => $fields) {
            foreach ($fields as $field) {
                $sort[$field] = $directions==='DESC'?-1:1;
            }
        }

        return $sort;
    }

    protected function executeQuery(): array
    {
        if (static::$connection === null) throw new Exception('Connection not set');

        /** @var Collection $collection */
        $collection = static::$connection->{$this->table};


        $aggregate = [];
        #Join
//        foreach ($this->lookup as $lookup) $aggregate[] = ['$lookup' => $lookup];
        # Where
        if (!empty($this->match)) $aggregate[] = ['$match' => $this->match];
        # Select
        $aggregate[] = ['$project' => $this->project];
        # Order By
        if (!empty($this->sort)) $aggregate[] = ['$sort' => $this->sort];

        $result = $collection->aggregate($aggregate);

        $data = [];
        foreach ($result as $item) {
            /** @var BSONDocument $item */
            $item = $item->getArrayCopy();
            $item['id'] = (string) $item['_id'];
            unset($item['_id']);
            $data[] = $item;
        }

        return $data;
    }


}
