<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node1 extends MySqlFetcher
{
    protected ?string $table = 'node1';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node2' => Node2::class];
    }
}
