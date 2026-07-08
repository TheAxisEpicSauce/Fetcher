<?php

namespace Tests;

require __DIR__.'/../vendor/autoload.php';

use Exception;
use Fetcher\FetcherCache;
use PHPUnit\Framework\TestCase;
use Tests\DeepFetchers\Node1;
use Tests\DeepFetchers\Node8;
use Tests\MySqlFetchers\CountryFetcher;

class FetcherCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();
    }

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

        FetcherCache::CacheFetchers();
    }

    public function testGraphIncludesNodesDeeperThanOldDefaultDepth()
    {
        FetcherCache::Setup('tests/cache', 'tests/DeepFetchers', 'Tests\\DeepFetchers');
        FetcherCache::CacheFetchers();

        $graph = FetcherCache::Instance(new Node1())->getGraph();

        // node8 is 7 hops from node1 — beyond the old default depth of 5.
        $this->assertArrayHasKey('node8', $graph, 'deepest node was truncated by the builder');
        $this->assertSame(
            ['node8' => Node8::class],
            $graph['node7'],
            'edge from node7 to the deepest node is missing'
        );

        // Completeness: every reachable node except the leaf has its full adjacency.
        foreach ($graph as $node => $edges) {
            if ($node === 'node8') {
                continue; // leaf has no joins
            }
            $this->assertNotEmpty($edges, "node '{$node}' was truncated to an empty adjacency");
        }
    }

    public function testCacheFetchersWriteIsAtomic()
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();

        $this->assertFileNotExists('tests/cache/fetcher-cache.json.tmp');

        $decoded = json_decode(file_get_contents('tests/cache/fetcher-cache.json'), true);
        $this->assertArrayHasKey('graphs', $decoded);
        $this->assertNotEmpty($decoded['fetchers']);
    }

    public function testLoadCacheBuildsWhenFileEmpty()
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        file_put_contents('tests/cache/fetcher-cache.json', '{}');
        FetcherCache::flush();

        // First access with an empty file must trigger the dev-fallback rebuild.
        $graph = FetcherCache::Instance(new CountryFetcher())->getGraph();

        $this->assertNotEmpty($graph, 'dev fallback did not rebuild an empty cache');
    }
}