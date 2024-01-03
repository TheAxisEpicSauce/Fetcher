<?php

namespace Tests;

require __DIR__.'/../vendor/autoload.php';

use Exception;
use Fetcher\FetcherCache;
use PHPUnit\Framework\TestCase;

class FetcherCacheTest extends TestCase
{
    public function testSetup()
    {
        $this->expectNotToPerformAssertions();

        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
    }

    public function testInvalidCacheDirSetup()
    {
        $this->expectException(Exception::class);

        FetcherCache::Setup('tests/error-folder', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
    }

    public function testInvalidFetcherDirSetup()
    {
        $this->expectException(Exception::class);

        FetcherCache::Setup('tests/cache', 'tests/Fetchers', 'Tests\\MySqlFetchers');
    }

    public function testFetcherCache()
    {
        $this->expectNotToPerformAssertions();

        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');

        FetcherCache::Instance()->cacheFetchers();
    }
}