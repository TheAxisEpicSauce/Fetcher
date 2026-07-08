<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node3 extends MySqlFetcher
{
    protected ?string $table = 'node3';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node4' => Node4::class];
    }
}
