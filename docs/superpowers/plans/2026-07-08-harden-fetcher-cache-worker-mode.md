# Harden Fetcher Cache for Worker Mode — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `FetcherCache`'s join graph deterministic and complete, and make cache loading in FrankenPHP worker mode a read-only, race-free flow that trusts a build-time-baked file.

**Architecture:** Three changes. (1) Replace the depth-limited, order-dependent graph builder in `CacheFetchers()` with a cycle-safe visited-set walk that always produces the full reachable component — killing the intermittent "no join from X to Y" errors. (2) Make `loadCache()` file-first: read the baked `fetcher-cache.json` into the in-memory cache and (when Redis is enabled) seed Redis from it; only fall back to an on-demand rebuild when no baked file exists (dev only). File writes become atomic (temp + rename). (3) Ship a framework-agnostic `bin/cache-fetchers` CLI so the graph can be baked into a Docker image at build time (no DB, no Redis needed — building the graph only instantiates fetchers and reads `getJoins()`/`getTable()`/`getKey()`; the DB connection is lazy).

**Tech Stack:** PHP 8.5, PHPUnit 11, ext-redis (optional at runtime), Docker Compose test harness.

## Global Constraints

- PHP 8.5; no new Composer dependencies.
- Ponytail mode: smallest correct change, stdlib/native first, no speculative abstractions.
- Keep the public API stable. `setGraphDepth()` MUST remain callable (consuming apps call `FetcherCache::setGraphDepth(10)` in their deploy step) — retain it as a deprecated no-op rather than deleting it.
- Graph is served from the in-memory `self::$cache` (getters already read it). This plan does NOT change the getters.
- Redis extension is absent in the test image, so `SetupRedis()` early-returns and `self::$UseRedis` is `false` in tests. The Redis seed path is verified by code review + manual Redis check, not by PHPUnit.
- Tests run in a single shared process (no PHPUnit process isolation); `FetcherCache`'s static state persists across test methods and classes. `MySqlFetcherTest::setUp()` calls `CacheFetchers()` WITHOUT `Setup()`, so it depends on the last `Setup()` leaving `FetcherDir = tests/MySqlFetchers`. Any test that repoints `FetcherDir` MUST restore it.
- Run tests via: `docker compose run --rm phpunit --filter <TestName> tests` (targeted) or `make test` (full suite, boots MySQL/Mongo).
- **DO NOT COMMIT.** The user reviews the whole change set and commits in one go at the end. Each task ends by running its tests green and stopping for review — no `git commit`.

---

### Task 1: Deterministic, complete graph builder

Replaces the order-dependent, depth-truncating builder with a visited-set walk. Adds a deep linear-chain fixture set (the existing `MySqlFetchers` fixtures are all within ~3 hops of each other, so depth-5 truncation never triggers on them and can't act as a regression test).

**Files:**
- Create: `tests/DeepFetchers/Node1.php` … `tests/DeepFetchers/Node8.php`
- Modify: `src/FetcherCache.php` (remove field `private static int $graphDepth = 5;` at line 15; rewrite `graphBuilder` closure + its invocation inside `CacheFetchers()` lines 106-139; convert `setGraphDepth()` lines 67-70 to a deprecated no-op)
- Test: `tests/FetcherCacheTest.php` (add `tearDown()` + `testGraphIncludesNodesDeeperThanOldDefaultDepth`)

**Interfaces:**
- Consumes: `Fetcher\MySqlFetcher` (fixture base), `Fetcher\Field\FieldType`, `Fetcher\BaseFetcher::getJoins(): array`, `Fetcher\BaseFetcher::getTable(): ?string`.
- Produces: `FetcherCache::CacheFetchers(): bool` (unchanged signature; now builds a complete graph). `FetcherCache::setGraphDepth(int): void` (unchanged signature; now a no-op). Graph shape unchanged: `graphs[fetcherId][$tableOrAlias][$joinName] = $joinFetcherClass`.

- [ ] **Step 1: Create the deep-chain fixtures**

Create `tests/DeepFetchers/Node1.php` (chain link):

```php
<?php

namespace Tests\DeepFetchers;

use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class Node1 extends MySqlFetcher
{
    protected ?string $table = 'node1';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return ['node2' => Node2::class];
    }
}
```

Create `Node2.php` … `Node7.php` identically, incrementing everything: file `NodeN.php`, class `NodeN`, `$table = 'nodeN'`, and `getJoins()` returning `['node{N+1}' => Node{N+1}::class]`. Create `Node8.php` as the leaf — same shape but:

```php
    protected ?string $table = 'node8';

    public function getFields(): array
    {
        return ['id' => FieldType::INT];
    }

    public function getJoins(): array
    {
        return [];
    }
```

(PSR-4 `Tests\ => tests/` resolves these at runtime — no `composer dump-autoload` needed.)

- [ ] **Step 2: Add `tearDown()` + the failing test to `tests/FetcherCacheTest.php`**

Add these imports below the existing `use` lines:

```php
use Tests\DeepFetchers\Node1;
use Tests\DeepFetchers\Node8;
```

Add a `tearDown()` so any test that repoints `FetcherDir` restores the canonical one for sibling tests (runs even when a test fails):

```php
    protected function tearDown(): void
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();
    }
```

Add the regression test:

```php
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
```

- [ ] **Step 3: Run the test — verify it FAILS**

Run: `docker compose run --rm phpunit --filter testGraphIncludesNodesDeeperThanOldDefaultDepth tests`
Expected: FAIL — `node8` is absent from the graph and `graph['node7']` is `[]`, because the current builder stops recursing once DFS depth exceeds the default `graphDepth` of 5.

- [ ] **Step 4: Rewrite the builder in `src/FetcherCache.php`**

Remove the field declaration (line 15):

```php
    private static int $graphDepth = 5;
```

Convert `setGraphDepth()` (lines 67-70) into a deprecated no-op:

```php
    /**
     * @deprecated Graph depth is no longer used — the builder is depth-unbounded and
     * cycle-safe. Retained so existing deploy commands don't fatal.
     */
    public static function setGraphDepth(int $graphDepth): void
    {
        // ponytail: intentionally a no-op.
    }
```

Inside `CacheFetchers()`, replace the per-fetcher block (the `$depth = 1;` line, the `$passedFetchers = [];` line, the entire `$graphBuilder = function (...) { ... };` closure, and its `$graphBuilder($fetcher, $fetcher::getTable(), $depth);` call — lines 107-137) with:

```php
            $graph = [];
            $visited = [];

            $graphBuilder = function (BaseFetcher $fetcher, string $joinedAs) use (&$graph, &$visited, &$graphBuilder) {
                if (isset($visited[$joinedAs])) {
                    return; // already expanded — breaks cycles, no depth limit needed
                }
                $visited[$joinedAs] = true;
                $graph[$joinedAs] ??= [];

                foreach ($fetcher->getJoins() as $joinName => $joinFetcherClass) {
                    $graph[$joinedAs][$joinName] = $joinFetcherClass;
                    $graphBuilder(new $joinFetcherClass(), $joinName);
                }
            };

            $graphBuilder($fetcher, $fetcher::getTable());
```

(Leave the surrounding `foreach ($fetcherClasses ...)` loop, the `$fetcherIds`/`$fetchers`/`$keys` assignments, and `$graphs[$fetcherId] = $graph;` untouched.)

- [ ] **Step 5: Run the test — verify it PASSES**

Run: `docker compose run --rm phpunit --filter testGraphIncludesNodesDeeperThanOldDefaultDepth tests`
Expected: PASS — the visited-set walk reaches every node regardless of `getJoins()` order or chain length.

- [ ] **Step 6: Run the whole `FetcherCacheTest` — verify no regression**

Run: `docker compose run --rm phpunit tests/FetcherCacheTest.php`
Expected: PASS — all existing cache tests plus the new one green.

- [ ] **Step 7: Stop for review (no commit)**

Leave the changes staged in the working tree. Do NOT run `git commit` — the user reviews and commits the whole change set at the end.

---

### Task 2: File-first `loadCache`, atomic write, Redis seed helper, `flush()`

Makes worker startup read the baked file (no on-demand rebuild in production), seeds Redis from that file instead of reading four hashes non-atomically, writes the file atomically, and adds a `flush()` to force a reload (also used by the tests here).

**Files:**
- Modify: `src/FetcherCache.php` (add `flush()`; extract `seedRedis()`; rewrite `loadCache()` lines 72-93; atomic write + `seedRedis()` call in `CacheFetchers()` lines 149-174)
- Test: `tests/FetcherCacheTest.php` (add `testCacheFetchersWriteIsAtomic`, `testLoadCacheBuildsWhenFileEmpty`)

**Interfaces:**
- Consumes: `FetcherCache::CacheFetchers()` from Task 1; `self::$CachePath`, `self::$UseRedis`, `self::$Redis`, `self::$cache`, `self::$_instance` statics.
- Produces:
  - `FetcherCache::flush(): void` — nulls `self::$cache` and `self::$_instance` so the next access reloads from disk/Redis.
  - `FetcherCache::seedRedis(array $cache): void` (private) — atomically writes `keys`/`fetchers`/`fetcher_ids`/`graphs` hashes via one MULTI/EXEC.
  - `loadCache(): bool` — file-first; serves from `self::$cache`.

- [ ] **Step 1: Add `flush()` and `seedRedis()` to `src/FetcherCache.php`**

Add near the other lifecycle methods:

```php
    /** Drop the in-memory cache + singleton so the next access reloads from disk/Redis. */
    public static function flush(): void
    {
        self::$cache = null;
        self::$_instance = null;
    }

    private static function seedRedis(array $cache): void
    {
        $tx = self::$Redis->multi();
        $tx->del('keys', 'fetchers', 'fetcher_ids', 'graphs');

        foreach ($cache['keys'] as $fetcherId => $key) {
            $tx->hSet('keys', $fetcherId, $key);
        }
        foreach ($cache['fetchers'] as $fetcherId => $fetcherClass) {
            $tx->hSet('fetchers', $fetcherId, $fetcherClass);
        }
        foreach ($cache['fetcher_ids'] as $fetcherClass => $fetcherId) {
            $tx->hSet('fetcher_ids', $fetcherClass, $fetcherId);
        }
        foreach ($cache['graphs'] as $fetcherId => $graph) {
            $tx->hSet('graphs', $fetcherId, $graph);
        }

        $tx->exec();
    }
```

- [ ] **Step 2: Use `seedRedis()` + atomic write in `CacheFetchers()`**

Replace the inline Redis block and the final `file_put_contents(...)` (lines 149-174) with:

```php
        if (self::$UseRedis) {
            self::seedRedis(self::$cache);
        }

        // ponytail: temp + rename is an atomic swap on the same filesystem — a concurrent
        // reader never sees a half-written file (the dev-fallback rebuild path).
        $tmp = self::$CachePath . '.tmp';
        file_put_contents($tmp, json_encode(self::$cache));
        rename($tmp, self::$CachePath);

        return true;
```

- [ ] **Step 3: Rewrite `loadCache()` (file-first + dev fallback)**

Replace `loadCache()` (lines 72-93) with:

```php
    public function loadCache(): bool
    {
        if (self::$cache !== null) {
            return true;
        }

        $decoded = null;
        if (self::$CachePath !== '' && file_exists(self::$CachePath)) {
            $decoded = json_decode(file_get_contents(self::$CachePath), true);
        }

        // Production: a deterministic cache baked to disk at image-build time.
        if (!empty($decoded) && !empty($decoded['fetchers'])) {
            self::$cache = $decoded;
            if (self::$UseRedis) {
                self::seedRedis($decoded); // ponytail: idempotent — every worker re-seeds identical baked data
            }
            return true;
        }

        // Dev fallback: no baked file (or the '{}' placeholder Setup() writes) → build on demand.
        // ponytail: fine for a single dev process; production never hits this branch.
        self::CacheFetchers();
        return true;
    }
```

(Note: this removes the four non-transactional `hGetAll` reads. The graph is now always served from `self::$cache`, sourced from the file; Redis is seed-only.)

- [ ] **Step 4: Add the tests to `tests/FetcherCacheTest.php`**

Add the import:

```php
use Tests\MySqlFetchers\CountryFetcher;
```

Add:

```php
    public function testCacheFetchersWriteIsAtomic()
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();

        $this->assertFileDoesNotExist('tests/cache/fetcher-cache.json.tmp');

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
```

- [ ] **Step 5: Run the new tests — verify they PASS**

Run: `docker compose run --rm phpunit --filter 'testCacheFetchersWriteIsAtomic|testLoadCacheBuildsWhenFileEmpty' tests`
Expected: PASS.

- [ ] **Step 6: Run the full suite — verify no regression**

Run: `make test`
Expected: PASS — `FetcherCacheTest`, `MySqlFetcherTest`, and `QueryCacheTest` all green (confirms the `tearDown()` restore keeps sibling tests' shared static state intact).

- [ ] **Step 7: Stop for review (no commit)**

Leave the changes in the working tree. Do NOT run `git commit`.

---

### Task 3: Build-time cache CLI (`bin/cache-fetchers`)

Ships a framework-agnostic script so a consuming app can bake the graph into its Docker image at build time. No DB/Redis needed.

**Files:**
- Create: `bin/cache-fetchers`
- Modify: `composer.json` (add a `bin` entry)

**Interfaces:**
- Consumes: `Fetcher\FetcherCache::Setup(string $cacheDir, string $fetcherDir, string $namespace): void`, `Fetcher\FetcherCache::CacheFetchers(): bool`.
- Produces: an executable that writes `<cacheDir>/fetcher-cache.json`; exit 0 on success, exit 1 on bad usage.

- [ ] **Step 1: Create `bin/cache-fetchers`**

```php
#!/usr/bin/env php
<?php

// Bake the fetcher join-graph cache to disk. Framework-agnostic; no DB/Redis
// needed — building the graph only instantiates fetchers and reads their
// getJoins()/getTable()/getKey(). Intended to run at Docker image-build time.
//
// Usage: cache-fetchers <cacheDir> <fetcherDir> <namespace>

$autoloads = [
    __DIR__ . '/../../../autoload.php', // installed as a dependency: vendor/taes/fetcher/bin
    __DIR__ . '/../vendor/autoload.php', // running from the library repo itself
];
foreach ($autoloads as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

if ($argc < 4) {
    fwrite(STDERR, "Usage: cache-fetchers <cacheDir> <fetcherDir> <namespace>\n");
    exit(1);
}

[$script, $cacheDir, $fetcherDir, $namespace] = $argv;

Fetcher\FetcherCache::Setup($cacheDir, $fetcherDir, $namespace);
Fetcher\FetcherCache::CacheFetchers();

fwrite(STDOUT, "Fetcher cache written to {$cacheDir}/fetcher-cache.json\n");
```

- [ ] **Step 2: Make it executable and register it in `composer.json`**

Run: `chmod +x bin/cache-fetchers`

Add a top-level `bin` key to `composer.json` (a sibling of `autoload`), so Composer symlinks it into consumers' `vendor/bin`:

```json
    "bin": ["bin/cache-fetchers"],
```

- [ ] **Step 3: Verify it bakes a cache file**

Run: `docker compose run --rm php bin/cache-fetchers tests/cache tests/MySqlFetchers "Tests\\MySqlFetchers"`
Expected: prints `Fetcher cache written to tests/cache/fetcher-cache.json`, exit code 0, and `tests/cache/fetcher-cache.json` contains a populated `graphs` key. (Confirms the build path works with only the autoloader — no DB/Redis.)

- [ ] **Step 4: Verify bad usage fails cleanly**

Run: `docker compose run --rm php bin/cache-fetchers`
Expected: prints the usage line to stderr, exit code 1.

- [ ] **Step 5: Stop for review (no commit)**

Leave the changes in the working tree. Do NOT run `git commit`.

---

## Risks & Notes

- **Redis is now seed-only for the graph.** Nothing in this library reads the graph back from Redis after this change (getters read `self::$cache`, sourced from the baked file). Since the file is baked into every image, Redis graph storage is arguably redundant and could be dropped entirely (YAGNI) — kept here only because the agreed design explicitly wants "load file into Redis" for potential external/shared consumers. Flag for confirmation before adding any further Redis machinery.
- **Alias-name assumption.** The graph is keyed by join name / table alias, and the builder assumes an alias maps to one target consistently within a root's graph. This is unchanged from the current code (the old `empty($graph[$joinName])` guard made the same assumption), so no regression — but worth noting if aliases are ever reused for different targets.
- **Consuming-app integration (out of this repo).** The deploy/build step should run `FetcherCache::CacheFetchers()` to bake `fetcher-cache.json` into the Docker image; `setGraphDepth()` calls can stay (no-op) or be removed at the caller's leisure. Because re-baking produces a new image with fresh workers, in-memory staleness and singleton poisoning resolve at the deployment layer — no epoch/hot-reload machinery is added. `BaseFetcher::$pathCache` is left as-is: against an immutable per-image graph, permanent per-worker memoization is correct.
- **Unbounded graph size.** Dropping the depth cap means each root's graph is its full reachable component. For the test fixtures this is ~11 nodes; for a large production schema it is bounded by the number of fetchers and built once at image-build time. Acceptable.
