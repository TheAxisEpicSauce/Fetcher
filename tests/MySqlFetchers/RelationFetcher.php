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
        return [];
    }
}