<?php

namespace Fetcher;

use Redis;

class FetcherCache
{
    private static string $CacheDir = '';
    private static string $CachePath = '';
    private static string $FetcherDir = '';
    private static string $Namespace = '';
    private static ?FetcherCache $_instance = null;
    private static ?array $cache = null;
    private static bool $UseRedis = false;
    private static ?Redis $Redis = null;

    private ?string $fetcher = null;
    private ?int $fetcherId = null;

    public static function Setup(string $cacheDir, string $fetcherDir, string $namespace): void
    {
        if (!is_dir($cacheDir)) throw new \Exception($cacheDir. ' doesn`t not exists or is not a directory');

        $cachePath = $cacheDir.'/fetcher-cache.json';

        if (!file_exists($cachePath)) {
            file_put_contents($cachePath, '{}');
        }

        if (!is_dir($fetcherDir)) throw new \Exception($fetcherDir. ' doesn`t not exists or is not a directory');

        self::$CacheDir = $cacheDir;
        self::$CachePath = $cachePath;
        self::$FetcherDir = $fetcherDir;
        self::$Namespace = $namespace;
    }

    public static function SetupRedis(string $redisHost, string $redisCredentials)
    {
        if (!extension_loaded('redis')) return;

        static::$Redis = $redis = new Redis();
        $redis->connect($redisHost);
        $redis->auth($redisCredentials);

        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);

        static::$UseRedis = true;
    }

    public static function Instance(BaseFetcher $fetcher): FetcherCache
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
            self::$_instance->loadCache();
        }

        $instance = clone self::$_instance;
        $instance->fetcher = $fetcher::class;
        $instance->fetcherId = $instance->getFetcherId($fetcher::class);
        return $instance;
    }

    /**
     * @deprecated Graph depth is no longer used — the builder is depth-unbounded and
     * cycle-safe. Retained so existing deploy commands don't fatal.
     */
    public static function setGraphDepth(int $graphDepth): void
    {
        // ponytail: intentionally a no-op.
    }

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

    public static function CacheFetchers(): bool
    {
        $fetcherClasses = [];
        self::scanDir(self::$FetcherDir, $fetcherClasses);

        $keys = [];
        $fetchers = [];
        $fetcherIds = [];

        $graphs = [];

        foreach ($fetcherClasses as $fetcherId => $fetcherClass) {
            /** @var BaseFetcher $fetcher */
            $fetcher = new $fetcherClass();

            $fetcherIds[$fetcherClass] = $fetcherId;
            $fetchers[$fetcherId] = $fetcherClass;
            $keys[$fetcherId] = $fetcher->getKey();

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

            $graphs[$fetcherId] = $graph;
        }

        self::$cache = [
            'keys' => $keys,
            'fetchers' => $fetchers,
            'fetcher_ids' => $fetcherIds,
            'graphs' => $graphs
        ];

        if (self::$UseRedis) {
            self::seedRedis(self::$cache);
        }

        // ponytail: temp + rename is an atomic swap on the same filesystem — a concurrent
        // reader never sees a half-written file (the dev-fallback rebuild path).
        $tmp = self::$CachePath . '.tmp';
        file_put_contents($tmp, json_encode(self::$cache));
        rename($tmp, self::$CachePath);

        return true;
    }

    private static function ScanDir(string $path, array &$fetchers)
    {
        $files = glob($path.'/*');

        foreach ($files as $file) {
            if (is_dir($file)) {
                self::ScanDir($file, $fetchers);
                continue;
            }
            $file = str_replace(
                [self::$FetcherDir, '/', '.php'],
                [self::$Namespace, '\\', ''],
                $file
            );

            $file = implode('\\', array_map(fn($a) => ucfirst($a), explode('\\', $file)));
            if (is_subclass_of($file, BaseFetcher::class)) {
                $fetchers[] = $file;
            }
        }
    }

    //-------------------------------------------
    // Getters
    //-------------------------------------------
    public function getFetcherKey(int $id)
    {
        return self::$cache['keys'][$id];
    }

    public function getFetchers()
    {
        return self::$cache['fetchers'];
    }

    public function getFetcher(int $id)
    {
        return self::$cache['fetchers'][$id];
    }

    public function getFetcherClass(string $tableFrom, string $tableTo): string
    {
        $graph = $this->getGraph();
        if (!isset($graph[$tableFrom][$tableTo])) {
            $available = isset($graph[$tableFrom]) ? implode(', ', array_keys($graph[$tableFrom])) : '(table not in graph)';
            throw new \RuntimeException(sprintf(
                'FetcherCache: no join from "%s" to "%s" in graph for fetcher %s. Available joins from "%s": [%s]',
                $tableFrom, $tableTo, $this->fetcher ?? 'unknown', $tableFrom, $available
            ));
        }
        return $graph[$tableFrom][$tableTo];
    }

    public function getFetcherIds()
    {
        return self::$cache['fetcher_ids'];
    }

    public function getFetcherId(string $fetcherClass)
    {
        return self::$cache['fetcher_ids'][$fetcherClass];
    }

    public function getGraph()
    {
        return self::$cache['graphs'][$this->fetcherId];
    }
}