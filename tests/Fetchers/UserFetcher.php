<?php
/**
 * User: Raphael Pelissier
 * Date: 23-07-20
 * Time: 11:05
 */

namespace Tests\Fetchers;

use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;

class UserFetcher extends BaseFetcher
{
    protected $table = 'user';

    protected function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'username' => FieldType::STRING,
            'address_id' => FieldType::INT
        ];
    }

    protected function getJoins(): array
    {
        return [
            'address' => AddressFetcher::class
        ];
    }

    public function joinAddress()
    {
        return 'address.id = user.address_id';
    }
}
