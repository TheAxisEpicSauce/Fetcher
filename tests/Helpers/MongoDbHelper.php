<?php
/**
 * User: Raphael Pelissier
 * Date: 24-09-21
 * Time: 15:16
 */

namespace Tests\Helpers;


use MongoDB\Client;
use MongoDB\Database;

class MongoDbHelper
{
    /**
     * @var null|Database
     */
    static $client = null;

    public static function client(): Database
    {
        if (self::$client === null) {
            self::$client = (new Client("mongodb://root:p0epsteen@mongodb:27017"))->db_app;
        }
        return self::$client;
    }

    public static function up()
    {
        self::client()->country->insertMany([[
            'code' => 'NL',
            'name' => 'Netherlands',
            'continent' => 'Europe'
        ], [
            'code' => 'FR',
            'name' => 'France',
            'continent' => 'Europe'
        ]]);
    }

    public static function down()
    {
        self::client()->drop();

    }
}
