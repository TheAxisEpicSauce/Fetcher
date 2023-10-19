<?php
/**
 * User: Raphael Pelissier
 * Date: 24-09-21
 * Time: 13:21
 */

namespace Tests\Helpers;


class MysqlDbHelper
{
    static $client = null;

    public static function client(): \PDO
    {
        if (self::$client === null) {
            self::$client = new \PDO('mysql:host=mysql:3306;dbname=db_app;charset=utf8', 'root', 'p0epsteen');
        }
        return self::$client;
    }

    public static function up()
    {

    }

    private static function insertData(string $query, array $data)
    {

    }

    public static function down()
    {

    }
}
