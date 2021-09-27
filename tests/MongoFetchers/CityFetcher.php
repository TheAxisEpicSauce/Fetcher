<?php
/**
 * User: Raphael Pelissier
 * Date: 27-09-21
 * Time: 16:40
 */

namespace Tests\MongoFetchers;


use Fetcher\Field\FieldType;
use Fetcher\MongoFetcher;

class CityFetcher extends MongoFetcher
{
    protected $table = 'city';

    public function getFields(): array
    {
        return [
            'city_code' => FieldType::STRING,
            'name' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'country' => CountryFetcher::class
        ];
    }

    public function joinCountry()
    {
        return ['city_code', 'code'];
    }
}
