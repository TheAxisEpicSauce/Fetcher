<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class CountryFetcher extends MySqlFetcher
{
    protected ?string $table = 'country';

    protected ?string $key = 'code';

    public function getFields(): array
    {
        return [
            'code' => FieldType::STRING,
            'name' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'address' => AddressFetcher::class,
            'city' => CityFetcher::class
        ];
    }

    public function joinAddress()
    {
        return '`address`.`country_code` = `country`.`code`';
    }

    public function joinCity()
    {
        return '`city`.`country_code` = `country`.`code`';
    }
}