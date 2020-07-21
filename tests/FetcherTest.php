<?php

use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * User: Raphael Pelissier
 * Date: 20-07-20
 * Time: 11:56
 */

class FetcherTest extends TestCase
{
    /**
     * @var BaseFetcher
     */
    private $userFetcher;

    protected function setUp(): void
    {
        // @UserFetcher
        $this->userFetcher = new class extends BaseFetcher {
            protected $table = 'user';

            protected function getFields(): array
            {
                return [
                    'id' => FieldType::INT,
                    'username' => FieldType::STRING
                ];
            }

            protected function getJoins(): array
            {
                return [];
            }
        };

        parent::setUp();
    }

    public function testBuild()
    {
        $this->assertInstanceOf(
            get_class($this->userFetcher),
            $this->userFetcher::build()
        );
    }

    public function testValidWhere()
    {
        $this->assertInstanceOf(
            get_class($this->userFetcher),
            $this->userFetcher::build()->whereId(5)
        );
    }

    public function testInvalidWhereValue()
    {
        $this->expectException(Exception::class);

        $this->userFetcher::build()->whereId("test");
    }

    public function testInvalidWhereField()
    {
        $this->expectException(Exception::class);

        $this->userFetcher::build()->whereNonExistingField(5);
    }

    public function testAndGroup()
    {
        $query = $this->userFetcher::build()->whereId(1)->whereUsername('test')->toSql();
        $this->assertEquals('SELECT user.* FROM user WHERE `user`.`id` = ? AND `user`.`username` = ?', $query);
    }

    public function testOrGroup()
    {
        $query = $this->userFetcher::buildOr()->whereId(1)->whereId(2)->toSql();
        $this->assertEquals('SELECT user.* FROM user WHERE `user`.`id` = ? OR `user`.`id` = ?', $query);
    }

    public function testValidSelectAs()
    {
        $this->assertInstanceOf(
            BaseFetcher::class,
            $this->userFetcher->select(['username AS name'])
        );
    }

    public function testInvalidSelectAs()
    {
        $this->expectException(Exception::class);

        $this->userFetcher->select(['user_name AS name']);
    }
}
