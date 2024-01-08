<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class RelationFetcher extends MySqlFetcher
{
    protected ?string $table = 'relation';

    public function getFields(): array
    {
        return [
            'person_a_id' => FieldType::INT,
            'person_b_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'person' => PersonFetcher::class
        ];
    }

    public function joinPerson()
    {
        return 'person.id = relation.person_a_id';
    }
}