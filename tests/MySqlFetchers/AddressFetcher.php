<?php

namespace Tests\MySqlFetchers;

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
            'number' => FieldType::STRING,
            'postcode' => FieldType::STRING,
            'country_code' => FieldType::STRING,
            'city_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'country' => CountryFetcher::class,
            'city' => CityFetcher::class,
            'person' => PersonFetcher::class,
            'job' => JobFetcher::class,
        ];
    }

    public function joinCountry()
    {
        return '`country`.`code` = `address`.`country_code`';
    }

    public function joinCity()
    {
        return '`city`.`id` = `address`.`city_id`';
    }

    public function joinPerson()
    {
        return 'person.address_id = address.id';
    }

    public function joinJob()
    {
        return 'job.address_id = address.id';
    }
}