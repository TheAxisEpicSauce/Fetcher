<?php

namespace Tests;

require __DIR__.'/../vendor/autoload.php';

use Exception;
use Fetcher\BaseFetcher;
use Fetcher\FetcherCache;
use Fetcher\MongoFetcher;
use Fetcher\MySqlFetcher;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\MysqlDbHelper;
use Tests\MySqlFetchers\AddressFetcher;
use Tests\MySqlFetchers\UserFetcher;

class FetcherCacheTest extends TestCase
{
    public function testSetup()
    {
        $this->expectNotToPerformAssertions();

        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers');
    }

    public function testInvalidCacheDirSetup()
    {
        $this->expectException(Exception::class);

        FetcherCache::Setup('tests/error-folder', 'tests/MySqlFetchers');
    }

    public function testInvalidFetcherDirSetup()
    {
        $this->expectException(Exception::class);

        FetcherCache::Setup('tests/cache', 'tests/Fetchers');
    }

    public function testFetcherCache()
    {
        FetcherCache::Instance()->cacheFetchers();
    }
}