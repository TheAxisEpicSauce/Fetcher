<?php

use Fetcher\BaseFetcher;
use PHPUnit\Framework\TestCase;
use Tests\MySqlFetchers\UserFetcher;

/**
 * User: Raphael Pelissier
 * Date: 20-07-20
 * Time: 11:56
 */

class MySqlFetcherTest extends TestCase
{
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
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM `user` WHERE `user`.`id` IN (?, ?, ?) GROUP BY `user`.`id`',
            $query
        );

        $query = UserFetcher::build()->whereIdIn([1])->toSql();

        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM `user` WHERE `user`.`id` IN (?) GROUP BY `user`.`id`',
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
        $query = UserFetcher::build()->whereId(1)->whereUsername('test')->toSql();
        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM `user` WHERE `user`.`id` = ? AND `user`.`username` = ? GROUP BY `user`.`id`',
            $query
        );
    }

    public function testOrGroup()
    {
        $query = UserFetcher::buildOr()->whereId(1)->whereId(2)->toSql();
        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM `user` WHERE `user`.`id` = ? OR `user`.`id` = ? GROUP BY `user`.`id`',
            $query
        );
    }

    public function testSelectAll()
    {
        $selectList = UserFetcher::build()->getSelect();
        $this->assertEquals([
            '`user`.`id`', '`user`.`username`', '`user`.`address_id`'
        ], $selectList);

        $selectList = UserFetcher::build()->select(['user.*'])->getSelect();
        $this->assertEquals([
            '`user`.`id`', '`user`.`username`', '`user`.`address_id`'
        ], $selectList);
    }

    public function testValidSelectAs()
    {
        $this->assertInstanceOf(
            BaseFetcher::class,
            UserFetcher::build()->select(['username AS name'])
        );
    }

    public function testInvalidSelectAs()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->select(['user_name AS name']);
    }

    public function testSelectJoin()
    {
        $query = UserFetcher::build()->select(['user.*', 'address.*'])->toSql();

        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id`, `address`.`id` AS address_id, `address`.`street` AS address_street, `address`.`number` AS address_number FROM `user` LEFT JOIN address ON address.id = user.address_id GROUP BY `user`.`id`',
            $query
        );
    }
}
