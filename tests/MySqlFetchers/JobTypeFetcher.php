<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class JobTypeFetcher extends MySqlFetcher
{
    protected ?string $table = 'job_type';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'name' => FieldType::STRING,
            'description' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'job' => JobFetcher::class
        ];
    }

    public function joinJob()
    {
        return 'job.job_type_id = job.id';
    }
}