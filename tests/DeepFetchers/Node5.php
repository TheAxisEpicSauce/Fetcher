<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node5 extends MySqlFetcher
{
    protected ?string $table = 'node5';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node6' => Node6::class];
    }
}
