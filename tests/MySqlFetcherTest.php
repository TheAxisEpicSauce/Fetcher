<?php

namespace Tests;

use Exception;
use Fetcher\BaseFetcher;
use Fetcher\Exception\MaxSearchException;
use Fetcher\FetcherCache;
use Fetcher\MySqlFetcher;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\MysqlDbHelper;
use Tests\MySqlFetchers\AddressFetcher;
use Tests\MySqlFetchers\CompanyFetcher;
use Tests\MySqlFetchers\JobFetcher;
use Tests\MySqlFetchers\PersonFetcher;

require __DIR__.'/../vendor/autoload.php';

class MySqlFetcherTest extends TestCase
{
    /**
     * @var \PDO
     */
    private $client;

    protected function setUp(): void
    {
        FetcherCache::CacheFetchers();

        MySqlFetcher::setConnection(MysqlDbHelper::client());
        MysqlDbHelper::up();
    }

    protected function tearDown(): void
    {
        MysqlDbHelper::down();

        BaseFetcher::setMaxSearchDepth(null);
    }

//    public function testBuild()
//    {
//        $this->assertInstanceOf(
//            PersonFetcher::class,
//            PersonFetcher::build()
//        );
//    }
//
//    public function testValidWhere()
//    {
//        $this->assertInstanceOf(
//            PersonFetcher::class,
//            PersonFetcher::build()->where('id', 5)
//        );
//    }
//
//    public function testValidWhereIn()
//    {
//        $query = PersonFetcher::build()->where('id', 'IN', [1, 2, 3])->toSql();
//
//        $this->assertEquals(
//            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id`, `person`.`referrer_id` FROM `person` WHERE `person`.`id` IN (?, ?, ?) GROUP BY `person`.`id`',
//            $query
//        );
//
//        $query = PersonFetcher::build()->whereIdIn([1])->toSql();
//
//        $this->assertEquals(
//            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id`, `person`.`referrer_id` FROM `person` WHERE `person`.`id` IN (?) GROUP BY `person`.`id`',
//            $query
//        );
//    }
//
//    public function testInvalidWhereValue()
//    {
//        $this->expectException(Exception::class);
//
//        PersonFetcher::build()->where('id', "test");
//    }
//
//    public function testInvalidWhereInValue()
//    {
//        $this->expectException(Exception::class);
//
//        PersonFetcher::build()->where('id', 'IN', [1, 2, "three"]);
//    }
//
//    public function testInvalidWhereField()
//    {
//        $this->expectException(Exception::class);
//
//        PersonFetcher::build()->where('non_existing_field', 5);
//
//        $this->expectException(Exception::class);
//
//        PersonFetcher::build()->whereNonExistingField(5);
//    }
//
//    public function testAndGroup()
//    {
//        $query = PersonFetcher::build()
//            ->where('id', 1)
//            ->where('first_name', 'test')
//            ->toSql();
//
//        $this->assertEquals(
//            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id`, `person`.`referrer_id` FROM `person` WHERE `person`.`id` = ? AND `person`.`first_name` = ? GROUP BY `person`.`id`',
//            $query
//        );
//    }
//
//    public function testOrGroup()
//    {
//        $query = PersonFetcher::buildOr()
//            ->where('id', 1)
//            ->where('id', 2)
//            ->toSql();
//
//        $this->assertEquals(
//            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id`, `person`.`referrer_id` FROM `person` WHERE `person`.`id` = ? OR `person`.`id` = ? GROUP BY `person`.`id`',
//            $query
//        );
//    }
//
//    public function testSelectAll()
//    {
//        $selectAllValue = [
//            '`person`.`id`', '`person`.`first_name`', '`person`.`last_name`', '`person`.`date_of_birth`', '`person`.`address_id`', '`person`.`job_id`', '`person`.`referrer_id`'
//        ];
//
//        $selectList = PersonFetcher::build()->getSelect();
//        $this->assertEquals($selectAllValue, $selectList);
//
//        $selectList = PersonFetcher::build()->select(['*'])->getSelect();
//        $this->assertEquals($selectAllValue, $selectList);
//
//        $selectList = PersonFetcher::build()->select(['person.*'])->getSelect();
//        $this->assertEquals($selectAllValue, $selectList);
//    }
//
//    public function testValidSelectAs()
//    {
//        $q = PersonFetcher::build()->select(['first_name AS name']);
//
//        $selectList = $q->getSelect();
//        $this->assertEquals([
//            '`person`.`first_name` AS name'
//        ], $selectList);
//    }
//
//    public function testInvalidSelectAs()
//    {
//        $this->expectException(Exception::class);
//
//        PersonFetcher::build()->select(['voornaam AS name']);
//    }
//
//    public function testSelectJoin()
//    {
//        $query = PersonFetcher::build()->select([
//            'person.id',
//            'person.first_name',
//            'person.last_name',
//            'person.date_of_birth',
//            'address.street',
//            'address.number',
//            'address.postcode',
//        ])->toSql();
//
//        $this->assertEquals(
//            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `address`.`street`, `address`.`number`, `address`.`postcode` FROM `person` LEFT JOIN `address` ON address.id = person.address_id GROUP BY `person`.`id`',
//            $query
//        );
//    }
//
//    public function testCount()
//    {
//        $count = AddressFetcher::build()->count();
//
//        $this->assertEquals(4, $count);
//    }
//
//    public function testSum()
//    {
//        $sum = JobFetcher::build()->sum('salary');
//
//        $this->assertEquals(2600, $sum);
//
//        $sum = JobFetcher::build()->sum('job.salary');
//
//        $this->assertEquals(2600, $sum);
//    }
//
//    public function testNotIn()
//    {
//        $query = AddressFetcher::whereIdNotIn([1, 2, 4])->get();
//
//        $this->assertEquals([
//            ['id' => 3,  'street' => 'Boeing Avenue', 'number' => '215', 'postcode' => '1119PD', 'country_code' => 'NL', 'city_id' => 2]
//        ], $query);
//    }
//
//    public function testSubFetch()
//    {
//        $data = JobFetcher::build()
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->select(['person.first_name']);
//            }, 'get', 'employees')
//            ->where('id', 2)
//            ->select(['id', 'name'])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "2",
//                    "name" => "Vakkenvuller",
//                    "employees" => [
//                        ["first_name" => "Bruce"],
//                        ["first_name" => "George"]
//                    ]
//                ]
//            ], $data);
//    }
//
//    public function testSubFetchWhere()
//    {
//        $data = JobFetcher::build()
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->where('id', 1)
//                    ->select(['person.first_name']);
//            }, 'get', 'employees')
//            ->where('id', 1)
//            ->select(['id', 'name'])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    "name" => "Software engineer",
//                    "employees" => [
//                        ["first_name" => "Raphael"]
//                    ]
//                ]
//            ], $data);
//    }
//
//    public function testSubFetchTwice()
//    {
//        $data = JobFetcher::build()
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->select(['person.first_name']);
//            }, 'get', 'employees')
//            ->sub('company', function (BaseFetcher $fetcher) {
//                $fetcher->select(['name']);
//            }, 'get', 'company')
//            ->where('id', 1)
//            ->select(['id', 'name'])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    "name" => "Software engineer",
//                    "employees" => [
//                        ["first_name" => "Raphael"],
//                        ["first_name" => "Roy"]
//                    ],
//                    "company" =>  [
//                        ["name" => "Kenneth & Koh"]
//                    ]
//                ]
//            ], $data);
//    }
//
//    public function testSubFetch2Deep()
//    {
//        $data = JobFetcher::build()
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->sub('address', function (BaseFetcher $fetcher) {
//                        $fetcher->select(['*']);
//                    }, 'get', 'address')
//                    ->select(['person.id', 'person.first_name']);
//            }, 'get', 'employees')
//            ->where('id', 1)
//            ->select(['id', 'name'])
//            ->get();
//
//        $this->assertEquals([
//            [
//                "id" => "1",
//                "name" => "Software engineer",
//                "employees" => [
//                    [
//                        "id" => "1",
//                        "first_name" => "Raphael",
//                        "address" => [
//                            [
//                                "id" => "2",
//                                "street" => "Burgemeester Pabstlaan",
//                                "number" => "8-35",
//                                "postcode" => "2131XE",
//                                "country_code" => "NL",
//                                "city_id" => "1"
//                            ]
//                        ]
//                    ],
//                    [
//                        "id" => "4",
//                        "first_name" => "Roy",
//                        "address" => [
//                            [
//                                "id" => "3",
//                                "street" => "Boeing Avenue",
//                                "number" => "215",
//                                "postcode" => "1119PD",
//                                "country_code" => "NL",
//                                "city_id" => "2"
//                            ]
//                        ]
//                    ]
//                ]
//            ]
//        ], $data);
//    }
//
//    public function testSubFetchArray()
//    {
//        $data = JobFetcher::buildFromArray([
//            'type' => 'and',
//            'fields' => [[
//                'table' => 'person',
//                'as' => 'employees',
//                'sub' => [
//                    'type' => 'and',
//                    'fields' => [],
//                    'select' => ['first_name'],
//
//                ],
//                'method' => 'get'
//            ]],
//            'select' => ['id', 'name']
//        ])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    "name" => "Software engineer",
//                    "employees" => [
//                        ["first_name" => "Raphael"],
//                        ["first_name" => "Roy"],
//                    ]
//                ], [
//                "id" => "2",
//                "name" => "Vakkenvuller",
//                "employees" => [
//                    ["first_name" => "Bruce"],
//                    ["first_name" => "George"]
//                ]
//            ]
//            ], $data
//        );
//    }
//
//    public function testSubFetchSameTwice()
//    {
//        $data = JobFetcher::build()
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->select(['first_name']);
//            }, 'get', 'employees_a')
//            ->sub('person', function (BaseFetcher $fetcher) {
//                $fetcher->select(['last_name']);
//            }, 'get', 'employees_b')->select(['id', 'name'])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    "name" => "Software engineer",
//                    "employees_a" => [
//                        ["first_name" => "Raphael"],
//                        ["first_name" => "Roy"],
//                    ],
//                    "employees_b" => [
//                        ["last_name" => "Pelissier"],
//                        ["last_name" => "Karte"],
//                    ]
//                ], [
//                    "id" => "2",
//                    "name" => "Vakkenvuller",
//                    "employees_a" => [
//                        ["first_name" => "Bruce"],
//                        ["first_name" => "George"]
//                    ],
//                    "employees_b" => [
//                        ["last_name" => "Pelissier"],
//                        ["last_name" => "Pelissier"]
//                    ]
//                ]
//            ], $data
//        );
//    }

    public function testJoinSameTableFromDifferentOrigin()
    {
        $q = PersonFetcher::build()
            ->where('id', 'IN', [1, 2, 3])
            ->select([
                'id',
                'first_name',
                'address.*',
                'job.*',
                'job.address.*'
            ]);


        $data = $q->get();

        $this->assertEquals(
            [
                [
                    "id" => "1",
                    "first_name" => "Raphael",
                    "address_id" => "2",
                    "address_street" => "Burgemeester Pabstlaan",
                    "address_number" => "8-35",
                    "address_postcode" => "2131XE",
                    "address_country_code" => "NL",
                    "address_city_id" => "1",
                    "job_id" => "1",
                    "job_job_type_id" => "1",
                    "job_name" => "Software engineer",
                    "job_salary" => "2000.00",
                    "job_address_id" => "3",
                    "job_company_id" => "1",
                    "job_address_street" => "Boeing Avenue",
                    "job_address_number" => "215",
                    "job_address_postcode" => "1119PD",
                    "job_address_country_code" => "NL",
                    "job_address_city_id" => "2"
                ],
                [
                    "id" => "2",
                    "first_name" => "Bruce",
                    "address_id" => "1",
                    "address_street" => "Ommerbos",
                    "address_number" => "30",
                    "address_postcode" => "2134KD",
                    "address_country_code" => "NL",
                    "address_city_id" => "1",
                    "job_id" => "2",
                    "job_job_type_id" => "2",
                    "job_name" => "Vakkenvuller",
                    "job_salary" => "600.00",
                    "job_address_id" => "4",
                    "job_company_id" => "2",
                    "job_address_street" => "Muiderbos",
                    "job_address_number" => "110",
                    "job_address_postcode" => "2134SV",
                    "job_address_country_code" => "NL",
                    "job_address_city_id" => "1"

                ],
                [
                    "id" => "3",
                    "first_name" => "George",
                    "address_id" => "1",
                    "address_street" => "Ommerbos",
                    "address_number" => "30",
                    "address_postcode" => "2134KD",
                    "address_country_code" => "NL",
                    "address_city_id" => "1",
                    "job_id" => "2",
                    "job_job_type_id" => "2",
                    "job_name" => "Vakkenvuller",
                    "job_salary" => "600.00",
                    "job_address_id" => "4",
                    "job_company_id" => "2",
                    "job_address_street" => "Muiderbos",
                    "job_address_number" => "110",
                    "job_address_postcode" => "2134SV",
                    "job_address_country_code" => "NL",
                    "job_address_city_id" => "1"
                ],
            ], $data
        );
    }

//    public function testWhereMaxSearchDepth()
//    {
//        BaseFetcher::setMaxSearchDepth(1);
//
//        $this->expectException(MaxSearchException::class);
//
//        $q = PersonFetcher::build()
//            ->where('country.code', 'NL');
//
//        $q->get();
//    }
//
//    public function testSelectMaxSearchDepth()
//    {
//        BaseFetcher::setMaxSearchDepth(1);
//
//        $this->expectException(MaxSearchException::class);
//
//        $q = PersonFetcher::build()
//            ->select(['id', 'country.code']);
//
//        $q->get();
//    }
//
//    public function testArrayBuild()
//    {
//        $data = PersonFetcher::buildFromArray([
//            'type' => 'and',
//            'fields' => [[
//                'param' => 'address.country_code_is',
//                'value' => 'NL'
//            ], [
//                'param' => 'id_in',
//                "value" => [1, 2, 3]
//            ]],
//            'select' => ['id', 'first_name', 'job.name']
//        ])->get();
//
//        $this->assertEquals([
//            [
//                "id" => "1",
//                "first_name" => "Raphael",
//                "name" => "Software engineer"
//            ],
//            [
//                "id" => "2",
//                "first_name" => "Bruce",
//                "name" => "Vakkenvuller"
//            ],
//            [
//                "id" => "3",
//                "first_name" => "George",
//                "name" => "Vakkenvuller"
//            ]
//        ], $data);
//    }
//
//    public function testJoinTwice()
//    {
//        $q = PersonFetcher::build()
//            ->where('id', 'IN', [1, 2, 3])
//            ->select(['id', 'first_name', 'address.*', 'address.city.name', 'job.address.*', 'job.address.city.name']);
//
//        $data = $q->get();
//
//        $this->assertEquals([
//            [
//                "id" => "1",
//                "first_name" => "Raphael",
//                "address_id" => "2",
//                "address_street" => "Burgemeester Pabstlaan",
//                "address_number" => "8-35",
//                "address_postcode" => "2131XE",
//                "address_country_code" => "NL",
//                "address_city_id" => "1",
//                "address_city_name" => "Hoofddorp",
//                "job_address_id" => "3",
//                "job_address_street" => "Boeing Avenue",
//                "job_address_number" => "215",
//                "job_address_postcode" => "1119PD",
//                "job_address_country_code" => "NL",
//                "job_address_city_id" => "2",
//                "job_address_city_name" => "Schiphol-Rijk",
//            ],
//            [
//                "id" => "2",
//                "first_name" => "Bruce",
//                "address_id" => "1",
//                "address_street" => "Ommerbos",
//                "address_number" => "30",
//                "address_postcode" => "2134KD",
//                "address_country_code" => "NL",
//                "address_city_id" => "1",
//                "address_city_name" => "Hoofddorp",
//                "job_address_id" => "4",
//                "job_address_street" => "Muiderbos",
//                "job_address_number" => "110",
//                "job_address_postcode" => "2134SV",
//                "job_address_country_code" => "NL",
//                "job_address_city_id" => "1",
//                "job_address_city_name" => "Hoofddorp",
//            ],
//            [
//                "id" => "3",
//                "first_name" => "George",
//                "address_id" => "1",
//                "address_street" => "Ommerbos",
//                "address_number" => "30",
//                "address_postcode" => "2134KD",
//                "address_country_code" => "NL",
//                "address_city_id" => "1",
//                "address_city_name" => "Hoofddorp",
//                "job_address_id" => "4",
//                "job_address_street" => "Muiderbos",
//                "job_address_number" => "110",
//                "job_address_postcode" => "2134SV",
//                "job_address_country_code" => "NL",
//                "job_address_city_id" => "1",
//                "job_address_city_name" => "Hoofddorp",
//            ],
//        ], $data);
//    }
//
//    public function testColumnEqual()
//    {
//        $q = PersonFetcher::build()
//            ->where('address_id', '$=', 'job.address_id')
//            ->select(['person.*', 'job.*']);
//
//        $data = $q->get();
//
//        $this->assertEquals([
//            [
//                "id" => "4",
//                "first_name" => "Roy",
//                "last_name" => "Karte",
//                "date_of_birth" => "1997-04-30",
//                "address_id" => "3",
//                "job_id" => "1",
//                "job_job_type_id" => "1",
//                "job_name" => "Software engineer",
//                "job_salary" => "2000.00",
//                "job_address_id" => "3",
//                "job_company_id" => "1",
//                'referrer_id' => null
//            ],
//        ], $data);
//    }
//
//    public function testColumnNotEqual()
//    {
//        $q = PersonFetcher::build()
//            ->where('address_id', '$!=', 'job.address_id')
//            ->select(['person.*', 'job.*']);
//
//        $data = $q->get();
//
//
//        $this->assertEquals([
//            [
//                "id" => "1",
//                "first_name" => "Raphael",
//                "last_name" => "Pelissier",
//                "date_of_birth" => "1997-04-30",
//                "address_id" => "2",
//                "job_id" => "1",
//                "job_job_type_id" => "1",
//                "job_name" => "Software engineer",
//                "job_salary" => "2000.00",
//                "job_address_id" => "3",
//                "job_company_id" => "1",
//                'referrer_id' => null
//            ],
//            [
//                "id" => "2",
//                "first_name" => "Bruce",
//                "last_name" => "Pelissier",
//                "date_of_birth" => "2001-06-25",
//                "address_id" => "1",
//                "job_id" => "2",
//                "job_job_type_id" => "2",
//                "job_name" => "Vakkenvuller",
//                "job_salary" => "600.00",
//                "job_address_id" => "4",
//                "job_company_id" => "2",
//                'referrer_id' => "1"
//            ],
//            [
//                "id" => "3",
//                "first_name" => "George",
//                "last_name" => "Pelissier",
//                "date_of_birth" => "2005-05-04",
//                "address_id" => "1",
//                "job_id" => "2",
//                "job_job_type_id" => "2",
//                "job_name" => "Vakkenvuller",
//                "job_salary" => "600.00",
//                "job_address_id" => "4",
//                "job_company_id" => "2",
//                'referrer_id' => "1"
//            ],
//        ], $data);
//    }
//
//    public function testCustomJoin()
//    {
//        $q = PersonFetcher::build()
//            ->select(['person.id', 'address_double.*']);
//
//        $data = $q->first();
//
//        $this->assertEquals([
//            "id" => "1",
//            "address_double_id" => "2",
//            "address_double_street" => "Burgemeester Pabstlaan",
//            "address_double_number" => "8-35",
//            "address_double_postcode" => "2131XE",
//            "address_double_country_code" => "NL",
//            "address_double_city_id" => "1"
//        ], $data);
//    }
//
//    public function testMultiCustomJoin()
//    {
//        $q = PersonFetcher::build()
//            ->select([
//                'person.id',
//                'address_double.*',
//                'address_double.city.name',
//                'address.*',
//                'address.city.name'
//            ]);
//
//        $data = $q->first();
//
//        $this->assertEquals([
//            "id" => "1",
//            "address_double_id" => "2",
//            "address_double_street" => "Burgemeester Pabstlaan",
//            "address_double_number" => "8-35",
//            "address_double_postcode" => "2131XE",
//            "address_double_country_code" => "NL",
//            "address_double_city_id" => "1",
//            "address_double_city_name" => "Hoofddorp",
//            "address_id" => "2",
//            "address_street" => "Burgemeester Pabstlaan",
//            "address_number" => "8-35",
//            "address_postcode" => "2131XE",
//            "address_country_code" => "NL",
//            "address_city_id" => "1",
//            "address_city_name" => "Hoofddorp"
//        ], $data);
//    }
//
//    public function testTwoDeepAs()
//    {
//        $q = PersonFetcher::build()
//            ->select(['id', 'address.country.code AS country_code']);
//
//        $data = $q->first();
//
//        $this->assertEquals([
//            "id" => "1",
//            "country_code" => "NL"
//        ], $data);
//
//    }
//
//    /**
//     * @group single
//     */
//    public function testSameTableJoin()
//    {
//        $persons = PersonFetcher::build()
//            ->where('referrer_id', '!=', null)
//            ->select(['person.first_name', 'referrer.first_name'])
//            ->get();
//
//        dd($persons);
//
//        $this->assertEquals('a', 'b');
//    }
}


