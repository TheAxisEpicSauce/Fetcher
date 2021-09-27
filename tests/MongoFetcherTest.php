<?php
/**
 * User: Raphael Pelissier
 * Date: 20-09-21
 * Time: 13:50
 */

namespace Tests;

use Exception;
use Fetcher\MongoFetcher;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\MongoDbHelper;
use Tests\MongoFetchers\CityFetcher;
use Tests\MongoFetchers\CountryFetcher;

class MongoFetcherTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    protected function setUp(): void
    {
        MongoFetcher::setConnection(MongoDbHelper::client());

        MongoDbHelper::up();
    }

    protected function tearDown(): void
    {
        MongoDbHelper::down();
    }

    public function testBuild()
    {
        $this->assertInstanceOf(
            CountryFetcher::class,
            CountryFetcher::build()
        );
    }

    public function testValidWhere()
    {
        $this->assertInstanceOf(
            CountryFetcher::class,
            CountryFetcher::build()->whereCode('NL')
        );
    }

    public function testInvalidWhere()
    {
        $this->expectException(Exception::class);

        CountryFetcher::build()->whereCode(1);
    }

    public function testSimpleQuery()
    {
        $result = CountryFetcher::build()->whereCode('NL')->first();

        $this->assertEquals(['code' => 'NL', 'name' => 'Netherlands', 'continent' => 'Europe'], $result);

    }
    public function testAndOrQuery()
    {
        $result = CountryFetcher::build()->whereContinent('Europe')->or(function($q) {
            $q->whereCode('NL')->whereCode('FR');
        })->get();

        $this->assertEquals(
            [
                ['code' => 'NL', 'name' => 'Netherlands', 'continent' => 'Europe'],
                ['code' => 'FR', 'name' => 'France', 'continent' => 'Europe']
            ],
            $result
        );
    }

    public function testSelect()
    {
        $result = CountryFetcher::build()->select(['country.name'])->get();

        $this->assertEquals(
            [
                ['name' => 'Netherlands'],
                ['name' => 'France']
            ],
            $result
        );
    }

    public function testOrderByAsc()
    {
        $result = CountryFetcher::build()->select(['country.name'])->orderBy(['country.name'], 'ASC')->get();

        $this->assertEquals(
            [
                ['name' => 'France'],
                ['name' => 'Netherlands']
            ],
            $result
        );
    }

    public function testOrderByDesc()
    {
        $result = CountryFetcher::build()->select(['country.name'])->orderBy(['country.name'], 'DESC')->get();

        $this->assertEquals(
            [
                ['name' => 'Netherlands'],
                ['name' => 'France']
            ],
            $result
        );
    }

    public function testJoin()
    {
        $result = CityFetcher::build()->select(['city.*', 'country.name AS country_name'])->get();
        var_dump($result);

    }
}
