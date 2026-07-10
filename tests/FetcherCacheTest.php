<?php

namespace Tests;

require __DIR__.'/../vendor/autoload.php';

use Exception;
use Fetcher\BaseFetcher;
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

    public function testV2RoundTripMatchesDirectlyBuiltGraphs()
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();

        $baked = json_decode(file_get_contents('tests/cache/fetcher-cache.json'), true);
        $this->assertSame(2, $baked['v'] ?? null, 'baked file is not v2');
        $this->assertArrayHasKey('nodes', $baked);
        $this->assertContainsOnly('int', array_merge(...array_values($baked['graphs'])), true, 'graphs must hold pool indexes, not join maps');

        // Cold-load from the baked v2 file; getGraph() must expand to exactly what
        // walking the fetchers' joins produces.
        FetcherCache::flush();
        foreach ($baked['fetchers'] as $fetcherClass) {
            $this->assertEquals(
                $this->buildExpandedGraph(new $fetcherClass()),
                FetcherCache::Instance(new $fetcherClass())->getGraph(),
                "round-tripped graph differs for {$fetcherClass}"
            );
        }
    }

    public function testLoadedV2CacheStaysSmallInMemory()
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');

        // Synthetic bake at real-app scale: 286 fetchers x 214 nodes over a pool of
        // 345 distinct join maps. Expanded this hydrates to tens of MB; v2 must not.
        $pool = [];
        for ($i = 0; $i < 345; $i++) {
            $pool[] = [
                "join_{$i}_a" => "App\\Fetchers\\Fake{$i}AFetcher",
                "join_{$i}_b" => "App\\Fetchers\\Fake{$i}BFetcher",
                "join_{$i}_c" => "App\\Fetchers\\Fake{$i}CFetcher",
                "join_{$i}_d" => "App\\Fetchers\\Fake{$i}DFetcher",
            ];
        }
        $keys = $fetchers = $fetcherIds = $graphs = [];
        for ($f = 0; $f < 286; $f++) {
            $class = $f === 0 ? CountryFetcher::class : "App\\Fetchers\\Fake{$f}Fetcher";
            $fetchers[$f] = $class;
            $fetcherIds[$class] = $f;
            $keys[$f] = 'id';
            $graph = [];
            for ($n = 0; $n < 214; $n++) {
                $graph["node_{$n}"] = ($f + $n) % 345;
            }
            $graphs[$f] = $graph;
        }
        file_put_contents('tests/cache/fetcher-cache.json', json_encode([
            'v' => 2, 'keys' => $keys, 'fetchers' => $fetchers,
            'fetcher_ids' => $fetcherIds, 'nodes' => $pool, 'graphs' => $graphs,
        ]));
        $this->assertLessThan(2 * 1024 * 1024, filesize('tests/cache/fetcher-cache.json'), 'baked v2 file too large');

        unset($pool, $keys, $fetchers, $fetcherIds, $graphs, $graph);
        FetcherCache::flush();
        gc_collect_cycles();

        $before = memory_get_usage();
        $instance = FetcherCache::Instance(new CountryFetcher());
        $loaded = $instance->getGraph();

        // Long-lived worker end state: expand every graph, not just one. Only fetcher 0
        // is a real class, so point clones at the other ids via reflection.
        $fetcherId = new \ReflectionProperty(FetcherCache::class, 'fetcherId');
        $fetcherId->setAccessible(true);
        for ($f = 1; $f < 286; $f++) {
            $clone = clone $instance;
            $fetcherId->setValue($clone, $f);
            $this->assertCount(214, $clone->getGraph());
        }
        $retained = memory_get_usage() - $before;

        $this->assertCount(214, $loaded);
        $this->assertSame(4, count($loaded['node_0']));
        $this->assertEquals($loaded, FetcherCache::Instance(new CountryFetcher())->getGraph());
        $this->assertLessThan(
            10 * 1024 * 1024,
            $retained,
            sprintf('loadCache + expanding all graphs retained %.1fMB — dedup is not holding', $retained / 1048576)
        );
    }

    /** Independent reference implementation: expand a fetcher's join graph directly. */
    private function buildExpandedGraph(BaseFetcher $fetcher): array
    {
        $graph = [];
        $walk = function (BaseFetcher $f, string $joinedAs) use (&$graph, &$walk) {
            if (isset($graph[$joinedAs])) return;
            $graph[$joinedAs] = [];
            foreach ($f->getJoins() as $joinName => $joinFetcherClass) {
                $graph[$joinedAs][$joinName] = $joinFetcherClass;
                $walk(new $joinFetcherClass(), $joinName);
            }
        };
        $walk($fetcher, $fetcher::getTable());
        return $graph;
    }
}