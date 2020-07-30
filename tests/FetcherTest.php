<?php

use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;
use PHPUnit\Framework\TestCase;
use Tests\Fetchers\UserFetcher;

/**
 * User: Raphael Pelissier
 * Date: 20-07-20
 * Time: 11:56
 */

class FetcherTest extends TestCase
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

    public function testInvalidWhereValue()
    {
        $this->expectException(Exception::class);

        UserFetcher::build()->whereId("test");
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
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM user WHERE `user`.`id` = ? AND `user`.`username` = ?',
            $query
        );
    }

    public function testOrGroup()
    {
        $query = UserFetcher::buildOr()->whereId(1)->whereId(2)->toSql();
        $this->assertEquals(
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id` FROM user WHERE `user`.`id` = ? OR `user`.`id` = ?',
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
            'SELECT `user`.`id`, `user`.`username`, `user`.`address_id`, `address`.`id` AS address_id, `address`.`street` AS address_street, `address`.`number` AS address_number FROM user LEFT JOIN address ON address.id = user.address_id',
            $query
        );
    }
}
