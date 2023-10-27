<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class CityFetcher extends MySqlFetcher
{
    protected ?string $table = 'city';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'country_code' => FieldType::STRING,
            'name' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'country' => CountryFetcher::class,
            'address' => AddressFetcher::class
        ];
    }

    public function joinCountry()
    {
        return '`country`.`code` = `city`.`country_code`';
    }

    public function joinAddress()
    {
        return '`address`.`city_id` = `city`.`id`';
    }
}