<?php

namespace Tests;

use Exception;
use Fetcher\MongoFetcher;
use Fetcher\MySqlFetcher;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\MysqlDbHelper;
use Tests\MySqlFetchers\AddressFetcher;
use Tests\MySqlFetchers\UserFetcher;
require __DIR__.'/../vendor/autoload.php';

class MySqlFetcherTest extends TestCase
{
    /**
     * @var \PDO
     */
    private $client;

    protected function setUp(): void
    {
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
            UserFetcher::class,
            UserFetcher::build()
        );
    }

    public function testValidWhere()
    {
        $this->assertInstanceOf(
            UserFetcher::class,
            UserFetcher::build()->whereId(5)
        );
    }

    public function testValidWhereIn()
    {
        $query = UserFetcher::build()->whereIdIn([1, 2, 3])->toSql();

        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`first_name`, `user`.`last_name`, `user`.`age`, `user`.`address_id` FROM `user` WHERE `user`.`id` IN (?, ?, ?) GROUP BY `user`.`id`',
            $query
        );

        $query = UserFetcher::build()->whereIdIn([1])->toSql();

        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`first_name`, `user`.`last_name`, `user`.`age`, `user`.`address_id` FROM `user` WHERE `user`.`id` IN (?) GROUP BY `user`.`id`',
            $query
        );
    }

    public function testInvalidWhereValue()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->whereId("test");
    }

    public function testInvalidWhereInValue()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->whereIdIn([1, 2, "three"]);
    }

    public function testInvalidWhereField()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->whereNonExistingField(5);
    }

    public function testAndGroup()
    {
        $query = UserFetcher::build()->whereId(1)->whereFirstName('test')->toSql();
        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`first_name`, `user`.`last_name`, `user`.`age`, `user`.`address_id` FROM `user` WHERE `user`.`id` = ? AND `user`.`first_name` = ? GROUP BY `user`.`id`',
            $query
        );
    }

    public function testOrGroup()
    {
        $query = UserFetcher::buildOr()->whereId(1)->whereId(2)->toSql();
        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`first_name`, `user`.`last_name`, `user`.`age`, `user`.`address_id` FROM `user` WHERE `user`.`id` = ? OR `user`.`id` = ? GROUP BY `user`.`id`',
            $query
        );
    }

    public function testSelectAll()
    {
        $selectList = UserFetcher::build()->getSelect();
        $this->assertEquals([
            '`user`.`id`', '`user`.`first_name`', '`user`.`last_name`', '`user`.`age`', '`user`.`address_id`'
        ], $selectList);

        $selectList = UserFetcher::build()->select(['user.*'])->getSelect();
        $this->assertEquals([
            '`user`.`id`', '`user`.`first_name`', '`user`.`last_name`', '`user`.`age`', '`user`.`address_id`'
        ], $selectList);
    }

    public function testValidSelectAs()
    {
        $this->assertInstanceOf(
            MySqlFetcher::class,
            UserFetcher::build()->select(['first_name AS name'])
        );
    }

    public function testInvalidSelectAs()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->select(['firstname AS name']);
    }

    public function testSelectJoin()
    {
        $query = UserFetcher::build()->select(['user.*', 'address.*'])->toSql();

        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`first_name`, `user`.`last_name`, `user`.`age`, `user`.`address_id`, `address`.`id` AS address_id, `address`.`street` AS address_street, `address`.`number` AS address_number FROM `user` LEFT JOIN address ON address.id = user.address_id GROUP BY `user`.`id`',
            $query
        );
    }

    public function testCount()
    {
        $count = AddressFetcher::build()->count();

        $this->assertEquals(3, $count);
    }

    public function testSum()
    {
        $sum = UserFetcher::build()->sum('age');

        $this->assertEquals(60, $sum);

        $sum = UserFetcher::build()->sum('user.age');

        $this->assertEquals(60, $sum);
    }

    public function testNotIn()
    {
        $query = AddressFetcher::whereIdNotIn([1, 2])->get();

        $this->assertEquals([
            ['id' => 3, 'street' => 'Ommerbos', 'number' => '28']
        ], $query);
    }

    public function testSubFetch()
    {
        $data = UserFetcher::build()->sub('address', function($q) {}, 'count')->get();
    }
}
