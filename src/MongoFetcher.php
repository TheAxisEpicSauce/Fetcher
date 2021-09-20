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
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;

abstract class MongoFetcher extends BaseFetcher
{
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
        foreach ($this->fieldGroup->getFields() as $field) {
            if ($field instanceof FieldObject) {
                $match[$field->getField()] =  ['$eq' => $field->getValue()];
            } else {
                $match[] = $this->buildMatch($field);
            }
        }

        if ($group->getConjunction() === FieldConjunction::AND) {
            return ['$and' => [$match]];
        } else {
            return ['$or' => [$match]];
        }
    }

    protected function executeQuery(): array
    {
        /** @var Collection $collection */
        $collection = self::$connection->{$this->table};

        $result = $collection->aggregate([
            ['$match' => $this->match]
        ]);

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
