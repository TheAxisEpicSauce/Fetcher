<?php
/**
 * User: Raphael Pelissier
 * Date: 20-09-21
 * Time: 13:29
 */

namespace Fetcher;


use Fetcher\Field\FieldConjunction;
use Fetcher\Field\FieldGroup;
use Fetcher\Field\FieldObject;
use Fetcher\Field\Operator;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;

abstract class MongoFetcher extends BaseFetcher
{
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

        $connection->test;
        self::$connection = $connection;
    }

    private $match = null;

    protected function buildQuery()
    {
        /** @var Collection $collection */
        $collection = self::$connection->{$this->table};

        $this->match = $this->buildMatch($this->fieldGroup);
    }

    private function buildMatch(FieldGroup $group)
    {
        $match = [];

        foreach ($group->getFields() as $field) {
            if ($field instanceof FieldObject) {
                $match[] =  [$field->getField() => [$this->operators[$field->getOperator()] => $field->getValue()]];
            } else {
                $match[] = $this->buildMatch($field);
            }
        }

        if (empty($match)) return [];

        if ($group->getConjunction() === FieldConjunction::AND) {
            return ['$and' => $match];
        } else {
            return ['$or' => $match];
        }
    }

    protected function executeQuery(): array
    {
        /** @var Collection $collection */
        $collection = self::$connection->{$this->table};

        $select = $this->select;

        foreach ($select as $field) {
            $field = str_replace([$this->table, '.', '`'], '', $field);
            $project[$field] = 1;
        }

        $aggregate = [];
        if (!empty($this->match)) $aggregate[] = ['$match' => $this->match];

        $aggregate[] = ['$project' => $project];

        $result = $collection->aggregate($aggregate);

        $data = [];
        foreach ($result as $item) {
            /** @var BSONDocument $item */
            $item = $item->getArrayCopy();
            unset($item['_id']);
            $data[] = $item;
        }

        return $data;
    }


}
