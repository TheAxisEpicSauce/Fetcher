<?php

namespace Fetcher;

use Composer\Autoload\ClassLoader;
use Fetcher\Field\Operator;

class FetcherCache
{
    private static string $CacheDir = '';
    private static string $CachePath = '';
    private static string $FetcherDir = '';
    private static ?FetcherCache $_instance = null;
    private static array $cache;

    private array $fieldPrefixes = [
        '' => Operator::EQUALS,
        'is_' => Operator::EQUALS
    ];

    private array $fieldSuffixes = [
        '' =>  Operator::EQUALS,
        '_is' =>  Operator::EQUALS,
        '_is_not' =>  Operator::NOT_EQUALS,
        '_gt' =>  Operator::GREATER,
        '_gte' =>  Operator::GREATER_OR_EQUAL,
        '_lt' =>  Operator::LESS,
        '_lte' =>  Operator::LESS_OR_EQUAL,
        '_like' =>  Operator::LIKE,
        '_in' =>  Operator::IN,
        '_in_like' =>  Operator::IN_LIKE,
        '_not_in' =>  Operator::NOT_IN
    ];

    public static function Setup(string $cacheDir, string $fetcherDir): void
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
    }

    public static function Instance(): FetcherCache
    {
        if (self::$_instance === null) return self::$_instance = self::Init();
        return self::$_instance;
    }

    private static function Init(): FetcherCache
    {
        $instance = new self();
        $instance->loadCache();
        return $instance;
    }

    public function loadCache(): bool
    {
        
    }

    public function cacheFetchers(): bool
    {
        $fetcherClasses = [];
        $this->scanDir(self::$FetcherDir, $fetcherClasses);

        $fetchers = [];
        $fetcherIds = [];
        $tables = [];
        $tableIds = [];

        $prefixes = [];
        $suffixes = [];

        /** @var BaseFetcher[] $objects */
        $objects = [];

        $graph = [];

        foreach ($fetcherClasses as $index => $fetcherClass) {
            $fetcher = $objects[$index] = new $fetcherClass();

            $fetchers[$index] = $fetcherClass;
            $fetcherIds[$fetcherClass] = $index;

            $table = $fetcher::getTable();

            $tables[$index] = $table;
            $tableIds[$table] = $index;
        }

        foreach ($objects as $index => $object) {
            $joins = $object->getJoins();
            $graph[$index] = [];
            foreach ($joins as $join => $fetcher) {
                $graph[$index][$join] = $fetcherIds[$fetcher];
            }

            $prefixes[$index] = '/( |^)('.implode("|", array_keys($this->fieldPrefixes)).')('.implode("|", array_keys($object->getFields())).')( |$)/';
            $suffixes[$index] = '/( |^)('.implode("|", array_keys($object->getFields())).')('.implode("|", array_keys($this->fieldSuffixes)).')( |$)/';
        }

        self::$cache = [
            'fetchers' => $fetchers,
            'fetcher_ids' => $fetcherIds,
            'tables' => $tables,
            'tableIds' => $tableIds,
            'prefixes' => $prefixes,
            'suffixes' => $suffixes,
            'graph' => $graph
        ];

        file_put_contents(self::$CachePath, json_encode(self::$cache));

        return true;
    }

    private function scanDir(string $path, array &$fetchers)
    {
        $files = glob($path.'/*');

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->scanDir($file, $fetchers);
                continue;
            }

            $file = str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file
            );

            $file = implode('\\', array_map(fn($a) => ucfirst($a), explode('\\', $file)));

            if (is_subclass_of($file, BaseFetcher::class)) {
                $fetchers[] = $file;
            }
        }
    }
}