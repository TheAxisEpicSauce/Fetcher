<?php

namespace Tests;

use Exception;
use Fetcher\BaseFetcher;
use Fetcher\FetcherCache;
use Fetcher\MySqlFetcher;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\MysqlDbHelper;
use Tests\MySqlFetchers\AddressFetcher;
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
        FetcherCache::Instance()->cacheFetchers();

        MySqlFetcher::setConnection(MysqlDbHelper::client());
        MysqlDbHelper::up();
    }

    protected function tearDown(): void
    {
        MysqlDbHelper::down();
    }

    public function testBuild()
    {
        $this->assertInstanceOf(
            PersonFetcher::class,
            PersonFetcher::build()
        );
    }

    public function testValidWhere()
    {
        $this->assertInstanceOf(
            PersonFetcher::class,
            PersonFetcher::build()->where('id', 5)
        );
    }

    public function testValidWhereIn()
    {
        $query = PersonFetcher::build()->where('id', 'IN', [1, 2, 3])->toSql();

        $this->assertEquals(
            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id` FROM `person` WHERE `person`.`id` IN (?, ?, ?) GROUP BY `person`.`id`',
            $query
        );

        $query = PersonFetcher::build()->whereIdIn([1])->toSql();

        $this->assertEquals(
            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id` FROM `person` WHERE `person`.`id` IN (?) GROUP BY `person`.`id`',
            $query
        );
    }

    public function testInvalidWhereValue()
    {
        $this->expectException(Exception::class);

        PersonFetcher::build()->where('id', "test");
    }

    public function testInvalidWhereInValue()
    {
        $this->expectException(Exception::class);

        PersonFetcher::build()->where('id', 'IN', [1, 2, "three"]);
    }

    public function testInvalidWhereField()
    {
        $this->expectException(Exception::class);

        PersonFetcher::build()->where('non_existing_field', 5);

        $this->expectException(Exception::class);

        PersonFetcher::build()->whereNonExistingField(5);
    }

    public function testAndGroup()
    {
        $query = PersonFetcher::build()
            ->where('id', 1)
            ->where('first_name', 'test')
            ->toSql();

        $this->assertEquals(
            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id` FROM `person` WHERE `person`.`id` = ? AND `person`.`first_name` = ? GROUP BY `person`.`id`',
            $query
        );
    }

    public function testOrGroup()
    {
        $query = PersonFetcher::buildOr()
            ->where('id', 1)
            ->where('id', 2)
            ->toSql();

        $this->assertEquals(
            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `person`.`address_id`, `person`.`job_id` FROM `person` WHERE `person`.`id` = ? OR `person`.`id` = ? GROUP BY `person`.`id`',
            $query
        );
    }

    public function testSelectAll()
    {
        $selectList = PersonFetcher::build()->getSelect();
        $this->assertEquals([
            '`person`.`id`', '`person`.`first_name`', '`person`.`last_name`', '`person`.`date_of_birth`', '`person`.`address_id`', '`person`.`job_id`'
        ], $selectList);

        $selectList = PersonFetcher::build()->select(['*'])->getSelect();
        $this->assertEquals([
            '`person`.`id`', '`person`.`first_name`', '`person`.`last_name`', '`person`.`date_of_birth`', '`person`.`address_id`', '`person`.`job_id`'
        ], $selectList);

        $selectList = PersonFetcher::build()->select(['person.*'])->getSelect();
        $this->assertEquals([
            '`person`.`id`', '`person`.`first_name`', '`person`.`last_name`', '`person`.`date_of_birth`', '`person`.`address_id`', '`person`.`job_id`'
        ], $selectList);
    }

    public function testValidSelectAs()
    {
        $q = PersonFetcher::build()->select(['first_name AS name']);

        $selectList = $q->getSelect();
        $this->assertEquals([
            '`person`.`first_name` AS name'
        ], $selectList);
    }

    public function testInvalidSelectAs()
    {
        $this->expectException(Exception::class);

        PersonFetcher::build()->select(['voornaam AS name']);
    }

    public function testSelectJoin()
    {
        $query = PersonFetcher::build()->select([
            'person.id',
            'person.first_name',
            'person.last_name',
            'person.date_of_birth',
            'address.street',
            'address.number',
            'address.postcode',
        ])->toSql();

        $this->assertEquals(
            'SELECT `person`.`id`, `person`.`first_name`, `person`.`last_name`, `person`.`date_of_birth`, `address`.`street`, `address`.`number`, `address`.`postcode` FROM `person` LEFT JOIN address ON address.id = person.address_id GROUP BY `person`.`id`',
            $query
        );
    }

    public function testCount()
    {
        $count = AddressFetcher::build()->count();

        $this->assertEquals(4, $count);
    }

    public function testSum()
    {
        $sum = JobFetcher::build()->sum('salary');

        $this->assertEquals(2600, $sum);

        $sum = JobFetcher::build()->sum('job.salary');

        $this->assertEquals(2600, $sum);
    }

    public function testNotIn()
    {
        $query = AddressFetcher::whereIdNotIn([1, 2, 4])->get();

        $this->assertEquals([
            ['id' => 3,  'street' => 'Boeing Avenue', 'number' => '215', 'postcode' => '1119PD', 'country_code' => 'NL', 'city_id' => 2]
        ], $query);
    }

//    public function testSubFetch()
//    {
//        $data = OrderFetcher::build()->sub('order_item', function (BaseFetcher $fetcher) {
//            $fetcher->select(['order_item.sales_price']);
//        }, 'get', 'items')
//            ->where('id', 1)
//            ->select(['id', 'sales_price'])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    'sales_price' => '4400.00',
//                    "items" => [
//                        ["sales_price" => "200.00"],
//                        ["sales_price" => "1200.00"],
//                        ["sales_price" => "200.00"],
//                        ["sales_price" => "300.00"],
//                        ["sales_price" => "500.00"],
//                        ["sales_price" => "2000.00"]
//                    ]
//                ]
//            ], $data);
//    }
//
//    public function testSubFetchArray()
//    {
//        $data = PassengerFetcher::buildFromArray([
//            'type' => 'and',
//            'fields' => [[
//                'table' => 'note',
//                'as' => 'notes',
//                'sub' => [
//                    'type' => 'and',
//                    'fields' => [],
//                    'select' => ['note.content'],
//
//                ],
//                'method' => 'get'
//            ]],
//            'select' => ['id', 'first_name', 'last_name']
//        ])->get();
//
//        $this->assertEquals(
//            [
//                [
//                    "id" => "1",
//                    "first_name" => "raphael",
//                    "last_name" => "pelissier",
//                    "notes" => [
//                        ["content" => "note 1 of raphael"],
//                        ["content" => "note 2 of raphael"],
//                        ["content" => "note 3 of raphael"],
//                        ["content" => "note 4 of raphael"]
//                    ]
//                ], [
//                    "id" => "2",
//                    "first_name" => "bruce",
//                    "last_name" => "pelissier",
//                    "notes" => [
//                        ["content" => "note 1 of bruce"]
//                    ]
//                ], [
//                    "id" => "3",
//                    "first_name" => "george",
//                    "last_name" => "pelissier",
//                    "notes" => [
//                        ["content" => "note 1 of george"],
//                        ["content" => "note 2 of george"]
//                    ]
//                ], [
//                    "id" => "4",
//                    "first_name" => "john",
//                    "last_name" => "doe",
//                    "notes" => []
//                ]
//            ], $data
//        );
//    }
//
//    public function testSubFetchSameTwice()
//    {
//        $data = PassengerFetcher::build()->sub('note', function (BaseFetcher $fetcher) {
//            $fetcher->select(['note.content']);
//        }, 'get', 'notes_a')->sub('note', function (BaseFetcher $fetcher) {
//            $fetcher->select(['note.content']);
//        }, 'get', 'notes_b')->select(['id'])->get();
//    }
}


