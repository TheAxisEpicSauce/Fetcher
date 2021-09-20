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
use Tests\MongoFetchers\CountryFetcher;

class MongoFetcherTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    protected function setUp(): void
    {
        $this->client = $client = new Client("mongodb://root:p0epsteen@mongodb:27017");

        MongoFetcher::setConnection($client->db_app);

        $client->db_app->country->insertOne([
            'code' => 'NL',
            'name' => 'Netherlands',
            'continent' => 'Europe'
        ], [
            'code' => 'FR',
            'name' => 'France',
            'continent' => 'Europe'
        ]);
    }

    protected function tearDown(): void
    {
        $this->client->dropDatabase('db_app');
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
    public function testBigQuery()
    {
        $result = CountryFetcher::build()->whereContinent('Europe')->or(function($q) {
            $q->whereCode('Nl')->whereCode('FR');
        })->first();

        $this->assertEquals(['code' => 'NL', 'name' => 'Netherlands', 'continent' => 'Europe'], $result);

    }
}
