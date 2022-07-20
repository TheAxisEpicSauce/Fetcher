<?php
/**
 * User: Raphael Pelissier
 * Date: 20-09-21
 * Time: 13:51
 */

namespace Tests\MongoFetchers;


use Fetcher\Field\FieldType;
use Fetcher\MongoFetcher;

class CountryFetcher extends MongoFetcher
{
    protected ?string $table = 'country';
    protected ?string $key = 'code';

    public function getFields(): array
    {
        return [
            'code' => FieldType::STRING,
            'name' => FieldType::STRING,
            'continent' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [];
    }
}
