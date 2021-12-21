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

    public function testSubFetch()
    {
        $data = UserFetcher::build()->sub('address', function($q) {}, 'count')->get();
        dd($data);
    }
}
