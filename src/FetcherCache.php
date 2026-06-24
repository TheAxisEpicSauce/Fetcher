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
    private static int $graphDepth = 5;

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

        $instance = self::$_instance;
        $instance->fetcher = $fetcher::class;
        $instance->fetcherId = $instance->getFetcherId($fetcher::class);
        return $instance;
    }

    public static function setGraphDepth(int $graphDepth): void
    {
        self::$graphDepth = $graphDepth;
    }

    public function loadCache(): bool
    {
        if (self::$cache !== null) return true;

        if (self::$UseRedis) {
            self::$cache = [
                'keys' => self::$Redis->hGetAll('keys') ?: [],
                'fetchers' => self::$Redis->hGetAll('fetchers') ?: [],
                'fetcher_ids' => self::$Redis->hGetAll('fetcher_ids') ?: [],
                'graphs' => self::$Redis->hGetAll('graphs') ?: [],
            ];
        } else {
            $content = file_get_contents(self::$CachePath);
            self::$cache = json_decode($content, true);
        }

        if (empty(self::$cache) || empty(self::$cache['fetchers']))
        {
            self::CacheFetchers();
        }
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
            $depth = 1;
            $passedFetchers = [];
            /** @var BaseFetcher $fetcher */
            $fetcher = new $fetcherClass();

            $fetcherIds[$fetcherClass] = $fetcherId;
            $fetchers[$fetcherId] = $fetcherClass;
            $keys[$fetcherId] = $fetcher->getKey();

            $graph = [];

            $graphBuilder = function ($fetcher, $joinedAs, $depth) use (&$graph, &$passedFetchers, &$graphBuilder)
            {
                $passedFetchers[$fetcher::class] = $fetcher::class;
                $joins = $fetcher->getJoins();
                $graph[$joinedAs] = [];

                foreach ($joins as $joinName => $joinFetcherClass)
                {
                    $graph[$joinedAs][$joinName] = $joinFetcherClass;
                    if (!array_key_exists($joinName, $graph))
                        $graph[$joinName] = [];

                    if ((empty($graph[$joinName]) && $depth <= self::$graphDepth))
                    {
                        $graphBuilder(new ($joinFetcherClass), $joinName, $depth+1);
                    }
                }
            };

            $graphBuilder($fetcher, $fetcher::getTable(), $depth);

            $graphs[$fetcherId] = $graph;
        }

        self::$cache = [
            'keys' => $keys,
            'fetchers' => $fetchers,
            'fetcher_ids' => $fetcherIds,
            'graphs' => $graphs
        ];

        if (self::$UseRedis)
        {
            $tx = self::$Redis->multi();
            $tx->del('keys', 'fetchers', 'fetcher_ids', 'graphs');

            foreach ($keys as $fetcherId => $key)
            {
                $tx->hSet('keys', $fetcherId, $key);
            }
            foreach ($fetchers as $fetcherId => $fetcherClass)
            {
                $tx->hSet('fetchers', $fetcherId, $fetcherClass);
            }
            foreach ($fetcherIds as $fetcherClass => $fetcherId)
            {
                $tx->hSet('fetcher_ids', $fetcherClass, $fetcherId);
            }
            foreach ($graphs as $fetcherId => $graph)
            {
                $tx->hSet('graphs', $fetcherId, $graph);
            }

            $tx->exec();
        }

        file_put_contents(self::$CachePath, json_encode(self::$cache));

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
        return $this->getGraph()[$tableFrom][$tableTo];
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