<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node2 extends MySqlFetcher
{
    protected ?string $table = 'node2';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node3' => Node3::class];
    }
}
