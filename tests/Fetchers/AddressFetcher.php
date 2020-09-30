<?php
/**
 * User: Raphael Pelissier
 * Date: 23-07-20
 * Time: 10:44
 */

namespace Tests\Fetchers;


use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;

class AddressFetcher extends BaseFetcher
{
    protected $table = 'address';

    protected function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'street' => FieldType::STRING,
            'number' => FieldType::STRING
        ];
    }

    protected function getJoins(): array
    {
        return [];
    }
}
