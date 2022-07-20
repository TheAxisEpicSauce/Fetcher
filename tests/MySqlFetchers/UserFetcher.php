<?php
/**
 * User: Raphael Pelissier
 * Date: 23-07-20
 * Time: 11:05
 */

namespace Tests\MySqlFetchers;

use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class UserFetcher extends MySqlFetcher
{
    protected ?string $table = 'user';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'first_name' => FieldType::STRING,
            'last_name' => FieldType::STRING,
            'age' => FieldType::INT,
            'address_id' => FieldType::INT
        ];
    }

    public function getJoins(): array
    {
        return [
            'address' => AddressFetcher::class,
            'note' => NoteFetcher::class
        ];
    }

    public function joinAddress()
    {
        return 'address.id = user.address_id';
    }

    public function joinNote()
    {
        return 'note.user_id = user.id';
    }
}
