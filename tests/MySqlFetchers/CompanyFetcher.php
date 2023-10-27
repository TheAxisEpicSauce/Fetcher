<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class CompanyFetcher extends MySqlFetcher
{
    protected ?string $table = 'company';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'name' => FieldType::STRING,
            'description' => FieldType::STRING,
            'employee_count' => FieldType::INT,
            'boss_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'boss' => PersonFetcher::class
        ];
    }

    public function joinBoss()
    {
        return 'person AS boss on boss.id = company.boss_id';
    }

}