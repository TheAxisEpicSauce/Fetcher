<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node7 extends MySqlFetcher
{
    protected ?string $table = 'node7';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node8' => Node8::class];
    }
}
