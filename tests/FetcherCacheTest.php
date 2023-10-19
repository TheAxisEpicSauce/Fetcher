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
        $this->expectNotToPerformAssertions();

        FetcherCache::Instance()->cacheFetchers();
    }
}