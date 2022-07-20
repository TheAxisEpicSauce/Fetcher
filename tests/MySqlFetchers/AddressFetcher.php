<?php
/**
 * User: Raphael Pelissier
 * Date: 23-07-20
 * Time: 10:44
 */

namespace Tests\MySqlFetchers;


use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class AddressFetcher extends MySqlFetcher
{
    protected ?string $table = 'address';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'street' => FieldType::STRING,
            'number' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [];
    }
}
