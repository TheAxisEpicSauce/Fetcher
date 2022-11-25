<?php

namespace Tests\MySqlFetchers\Sub;

use Fetcher\MySqlFetcher;

class subFetcher extends MySqlFetcher
{
    protected ?string $table = 'sub';
    public function getFields(): array
    {
        return [];
    }

    public function getJoins(): array
    {
        return [];
    }
}