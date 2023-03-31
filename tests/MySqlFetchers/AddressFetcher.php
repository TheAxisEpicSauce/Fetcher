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
            'postcode' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'person' => PersonFetcher::class,
            'job' => JobFetcher::class
        ];
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