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
        $sql ="CREATE table address(id INT( 11 ) AUTO_INCREMENT PRIMARY KEY, street VARCHAR( 255 ) NOT NULL, number VARCHAR( 255 ) NOT NULL);";
        self::client()->exec($sql);

        self::insertData("INSERT INTO address (street, number) VALUES (?,?)", [
            ['Ommerbos','30'],
            ['Burgemeester Pabstlaan','8-35'],
            ['Ommerbos','28']
        ]);

        $sql ="CREATE table user(id INT( 11 ) AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR( 255 ) NOT NULL, last_name VARCHAR( 255 ) NOT NULL, age INT( 11 ) NOT NULL, address_id INT( 11 ));";
        self::client()->exec($sql);

        self::insertData("INSERT INTO user (first_name, last_name, age, address_id) VALUES (?,?,?,?)", [
            ['raphael','pelissier','24',null],
            ['bruce','pelissier','20',null],
            ['george','pelissier','16',null]
        ]);
    }

    private static function insertData(string $query, array $data)
    {
        $stmt = self::client()->prepare($query);
        self::client()->beginTransaction();
        foreach ($data as $row) $stmt->execute($row);
        self::client()->commit();
    }

    public static function down()
    {
        self::client()->exec('DROP table address;');
        self::client()->exec('DROP table user;');
    }
}
