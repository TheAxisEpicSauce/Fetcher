<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class JobFetcher extends MySqlFetcher
{
    protected ?string $table = 'job';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'job_type_id' => FieldType::INT,
            'name' => FieldType::STRING,
            'salary' => FieldType::STRING,
            'address_id' => FieldType::INT,
            'company_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'job_type' => JobTypeFetcher::class,
            'person' => PersonFetcher::class,
            'address' => AddressFetcher::class,
            'company' => CompanyFetcher::class
        ];
    }

    public function joinJobType()
    {
        return 'job_type.id = job.job_type_id';
    }

    public function joinPerson()
    {
        return 'person.job_id = job.id';
    }

    public function joinAddress()
    {
        return 'address.id = job.address_id';
    }

    public function joinCompany()
    {
        return 'company.id = job.company_id';
    }
}