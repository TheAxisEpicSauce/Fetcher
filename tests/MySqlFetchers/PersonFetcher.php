<?php

namespace Tests\MySqlFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class PersonFetcher extends MySqlFetcher
{
    protected ?string $table = 'person';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'first_name' => FieldType::STRING,
            'last_name' => FieldType::STRING,
            'date_of_birth' => FieldType::DATE,
            'address_id' => FieldType::INT,
            'job_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'relation' => RelationFetcher::class,
            'address' => AddressFetcher::class,
            'job' => JobFetcher::class,
            'company' => CompanyFetcher::class,
            'owned_company' => CompanyFetcher::class
        ];
    }

    public function joinRelation()
    {
        return 'relation.person_a_id = person.id OR relation.person_b_id = person.id';
    }

    public function joinAddress()
    {
        return 'address.id = person.address_id';
    }

    public function joinJob()
    {
        return 'job.id = person.job_id';
    }

    public function joinCompany()
    {
        return [
            'company_person ON company_person.person_id = person.id',
            'company ON company_person.company_id = company.id'
        ];
    }

    public function joinOwnedCompany()
    {
        return 'company AS owned_company ON owned_company.boss_id = person.id';
    }
}