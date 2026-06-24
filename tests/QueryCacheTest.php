<?php

namespace Tests;

require __DIR__.'/../vendor/autoload.php';

use Fetcher\BaseFetcher;
use Fetcher\FetcherCache;
use Fetcher\MySqlFetcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Helpers\MysqlDbHelper;
use Tests\MySqlFetchers\AddressFetcher;
use Tests\MySqlFetchers\PersonFetcher;
use Tests\MySqlFetchers\JobFetcher;

class QueryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        FetcherCache::Setup('tests/cache', 'tests/MySqlFetchers', 'Tests\\MySqlFetchers');
        FetcherCache::CacheFetchers();
        MySqlFetcher::setConnection(MysqlDbHelper::client());

        $this->clearPathCache();
        $this->clearTemplateCache();
    }

    //-------------------------------------------
    // Path Cache Tests
    //-------------------------------------------

    public function testPathCachePopulatedAfterJoinQuery()
    {
        $this->assertEmpty($this->getPathCache());

        PersonFetcher::build()->select(['address.street'])->toSql();

        $cache = $this->getPathCache();
        $this->assertNotEmpty($cache);

        $key = $this->findPathCacheKey($cache, 'person', 'address');
        $this->assertNotNull($key, 'Path cache should contain person:address entry');
    }

    public function testPathCacheReusedOnSecondQuery()
    {
        PersonFetcher::build()->where('address.street', 'test')->toSql();
        $cacheAfterFirst = $this->getPathCache();

        PersonFetcher::build()->where('address.postcode', '1234AB')->toSql();
        $cacheAfterSecond = $this->getPathCache();

        $this->assertEquals($cacheAfterFirst, $cacheAfterSecond, 'Path cache should not grow when the same path is queried again');
    }

    public function testPathCacheGrowsForNewPaths()
    {
        PersonFetcher::build()->where('address.street', 'test')->toSql();
        $countAfterFirst = count($this->getPathCache());

        PersonFetcher::build()->where('job.name', 'dev')->toSql();
        $countAfterSecond = count($this->getPathCache());

        $this->assertGreaterThan($countAfterFirst, $countAfterSecond, 'Path cache should grow when a new path is queried');
    }

    public function testPathCacheScopedByFetcherClass()
    {
        PersonFetcher::build()->where('address.street', 'test')->toSql();
        AddressFetcher::build()->where('city.name', 'Amsterdam')->toSql();

        $cache = $this->getPathCache();

        $personKey = $this->findPathCacheKey($cache, 'person', 'address');
        $addressKey = $this->findPathCacheKey($cache, 'address', 'city');

        $this->assertNotNull($personKey);
        $this->assertNotNull($addressKey);
        $this->assertNotEquals($personKey, $addressKey, 'Different fetcher classes should have separate cache entries');
    }

    public function testPathCacheProducesSameResultAsFreshBuild()
    {
        $sqlFirst = PersonFetcher::build()
            ->select(['address.street', 'address.postcode'])
            ->where('address.street', 'Main St')
            ->toSql();

        $sqlSecond = PersonFetcher::build()
            ->select(['address.street', 'address.postcode'])
            ->where('address.street', 'Other St')
            ->toSql();

        $this->assertEquals($sqlFirst, $sqlSecond, 'Same query shape should produce identical SQL regardless of cache state');
    }

    //-------------------------------------------
    // Query Template Cache Tests
    //-------------------------------------------

    public function testTemplateCachePopulatedAfterBuild()
    {
        $this->assertEmpty($this->getTemplateCache());

        PersonFetcher::build()->where('id', 1)->toSql();

        $this->assertNotEmpty($this->getTemplateCache(), 'Template cache should have an entry after first build');
    }

    public function testTemplateCacheHitProducesSameSql()
    {
        $sql1 = PersonFetcher::build()->where('id', 1)->toSql();
        $sql2 = PersonFetcher::build()->where('id', 2)->toSql();

        $this->assertEquals($sql1, $sql2, 'Same query shape with different values should produce identical SQL template');
    }

    public function testTemplateCacheExtractsCorrectValues()
    {
        $sql1 = PersonFetcher::build()->where('id', 1)->toSql(true);
        $sql2 = PersonFetcher::build()->where('id', 99)->toSql(true);

        $this->assertNotEquals($sql1, $sql2, 'Bound values should differ between queries');
        $this->assertStringContainsString('99', $sql2);
    }

    public function testTemplateCacheDifferentShapesAreSeparate()
    {
        PersonFetcher::build()->where('id', 1)->toSql();
        $countAfterFirst = count($this->getTemplateCache());

        PersonFetcher::build()->where('id', 1)->where('first_name', 'test')->toSql();
        $countAfterSecond = count($this->getTemplateCache());

        $this->assertGreaterThan($countAfterFirst, $countAfterSecond, 'Different query shapes should create separate cache entries');
    }

    public function testTemplateCacheWithMultipleWheres()
    {
        $sql1 = PersonFetcher::build()
            ->where('id', 1)
            ->where('first_name', 'Alice')
            ->toSql(true);

        $sql2 = PersonFetcher::build()
            ->where('id', 2)
            ->where('first_name', 'Bob')
            ->toSql(true);

        $this->assertStringContainsString('2', $sql2);
        $this->assertStringContainsString('Bob', $sql2);
        $this->assertStringNotContainsString('Alice', $sql2);
    }

    public function testTemplateCacheWithInOperator()
    {
        $sql1 = PersonFetcher::build()->where('id', 'IN', [1, 2, 3])->toSql();
        $cacheCount1 = count($this->getTemplateCache());

        // Same shape, same array length — should hit cache
        $sql2 = PersonFetcher::build()->where('id', 'IN', [4, 5, 6])->toSql();
        $cacheCount2 = count($this->getTemplateCache());

        $this->assertEquals($sql1, $sql2);
        $this->assertEquals($cacheCount1, $cacheCount2, 'Same IN-array length should hit cache');

        // Different array length — new cache entry
        PersonFetcher::build()->where('id', 'IN', [1, 2])->toSql();
        $cacheCount3 = count($this->getTemplateCache());

        $this->assertGreaterThan($cacheCount2, $cacheCount3, 'Different IN-array length should create new cache entry');
    }

    public function testTemplateCacheWithNullValue()
    {
        $sqlNull = PersonFetcher::build()->where('first_name', null)->toSql();
        $sqlValue = PersonFetcher::build()->where('first_name', 'test')->toSql();

        $this->assertNotEquals($sqlNull, $sqlValue, 'NULL where should produce IS NULL, not a parameterized query');
        $this->assertStringContainsString('IS NULL', $sqlNull);
    }

    public function testTemplateCacheWithJoin()
    {
        $sql1 = PersonFetcher::build()->where('address.street', 'Main St')->toSql();
        $sql2 = PersonFetcher::build()->where('address.street', 'Other St')->toSql();

        $this->assertEquals($sql1, $sql2, 'Joined queries with same shape should hit cache');
        $this->assertStringContainsString('JOIN', $sql1);
    }

    public function testTemplateCacheWithOrGroup()
    {
        $sql1 = PersonFetcher::buildOr()->where('id', 1)->where('id', 2)->toSql();
        $sql2 = PersonFetcher::buildOr()->where('id', 3)->where('id', 4)->toSql();

        $this->assertEquals($sql1, $sql2, 'OR queries with same shape should hit cache');
    }

    public function testTemplateCacheAndVsOrAreDifferent()
    {
        PersonFetcher::build()->where('id', 1)->where('id', 2)->toSql();
        $countAnd = count($this->getTemplateCache());

        PersonFetcher::buildOr()->where('id', 1)->where('id', 2)->toSql();
        $countOr = count($this->getTemplateCache());

        $this->assertGreaterThan($countAnd, $countOr, 'AND vs OR should be separate cache entries');
    }

    public function testTemplateCacheDifferentFetchersAreSeparate()
    {
        PersonFetcher::build()->where('id', 1)->toSql();
        $count1 = count($this->getTemplateCache());

        AddressFetcher::build()->where('id', 1)->toSql();
        $count2 = count($this->getTemplateCache());

        $this->assertGreaterThan($count1, $count2, 'Different fetcher classes should have separate cache entries');
    }

    public function testTemplateCacheDifferentSelectsAreSeparate()
    {
        PersonFetcher::build()->where('id', 1)->toSql();
        $count1 = count($this->getTemplateCache());

        PersonFetcher::build()->select(['id', 'first_name'])->where('id', 1)->toSql();
        $count2 = count($this->getTemplateCache());

        $this->assertGreaterThan($count1, $count2, 'Different select lists should create separate cache entries');
    }

    //-------------------------------------------
    // Integration: both caches working together
    //-------------------------------------------

    public function testCachesWorkTogetherOnRepeatedJoinQueries()
    {
        $sql1 = PersonFetcher::build()
            ->where('address.street', 'Elm St')
            ->where('job.name', 'dev')
            ->toSql();

        $pathCount = count($this->getPathCache());
        $templateCount = count($this->getTemplateCache());

        $sql2 = PersonFetcher::build()
            ->where('address.street', 'Oak Ave')
            ->where('job.name', 'ops')
            ->toSql();

        $this->assertEquals($sql1, $sql2, 'Second query should produce identical SQL');
        $this->assertEquals($pathCount, count($this->getPathCache()), 'Path cache should not grow on repeated query');
        $this->assertEquals($templateCount, count($this->getTemplateCache()), 'Template cache should not grow on repeated query');
    }

    //-------------------------------------------
    // SubFetch template cache tests
    //-------------------------------------------

    public function testTemplateCachePopulatesWithSubFetch()
    {
        $this->assertEmpty($this->getTemplateCache());

        PersonFetcher::build()
            ->sub('job', function ($f) { $f->select(['name']); }, 'get', 'jobs')
            ->where('id', 1)
            ->toSql();

        $this->assertNotEmpty($this->getTemplateCache(), 'Template cache should populate for queries with sub-fetches');
    }

    public function testTemplateCacheHitWithSubFetch()
    {
        $sql1 = PersonFetcher::build()
            ->sub('job', function ($f) { $f->select(['name']); }, 'get', 'jobs')
            ->where('id', 1)
            ->toSql();

        $sql2 = PersonFetcher::build()
            ->sub('job', function ($f) { $f->select(['name']); }, 'get', 'jobs')
            ->where('id', 2)
            ->toSql();

        $this->assertEquals($sql1, $sql2, 'Sub-fetch queries with same shape should hit template cache');
    }

    public function testSubFetchRepeatedBuildQueryDoesNotCorrupt()
    {
        $fetcher = PersonFetcher::build()
            ->sub('job', function ($f) { $f->select(['name']); }, 'get', 'jobs')
            ->where('id', 1);

        $sql1 = $fetcher->toSql();
        $sql2 = $fetcher->toSql();

        $this->assertEquals($sql1, $sql2, 'Repeated toSql() should produce identical SQL (no state corruption from cloning)');
    }

    public function testSubFetchDifferentMethodsSeparateCacheEntries()
    {
        PersonFetcher::build()
            ->sub('job', function ($f) {}, 'get', 'jobs')
            ->where('id', 1)
            ->toSql();
        $count1 = count($this->getTemplateCache());

        PersonFetcher::build()
            ->sub('job', function ($f) {}, 'count', 'job_count')
            ->where('id', 1)
            ->toSql();
        $count2 = count($this->getTemplateCache());

        $this->assertGreaterThan($count1, $count2, 'Different sub-fetch methods (get vs count) should create separate cache entries');
    }

    //-------------------------------------------
    // Helpers
    //-------------------------------------------

    private function getPathCache(): array
    {
        $ref = new ReflectionClass(BaseFetcher::class);
        $prop = $ref->getProperty('pathCache');
        return $prop->getValue();
    }

    private function clearPathCache(): void
    {
        $ref = new ReflectionClass(BaseFetcher::class);
        $prop = $ref->getProperty('pathCache');
        $prop->setValue(null, []);
    }

    private function getTemplateCache(): array
    {
        $ref = new ReflectionClass(MySqlFetcher::class);
        $prop = $ref->getProperty('queryTemplateCache');
        return $prop->getValue();
    }

    private function clearTemplateCache(): void
    {
        $ref = new ReflectionClass(MySqlFetcher::class);
        $prop = $ref->getProperty('queryTemplateCache');
        $prop->setValue(null, []);
    }

    private function findPathCacheKey(array $cache, string $from, string $to): ?string
    {
        foreach (array_keys($cache) as $key) {
            if (str_contains($key, $from) && str_contains($key, $to)) {
                return $key;
            }
        }
        return null;
    }
}
