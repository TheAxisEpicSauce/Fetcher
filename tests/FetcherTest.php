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
            $fetcher = $this->userFetcher::build()
        );
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
