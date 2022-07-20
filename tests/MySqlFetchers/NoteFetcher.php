<?php
/**
 * User: Raphael Pelissier
 * Date: 27-12-21
 * Time: 13:09
 */

namespace Tests\MySqlFetchers;


use Fetcher\BaseFetcher;
use Fetcher\Field\FieldType;
use Fetcher\MySqlFetcher;

class NoteFetcher extends MySqlFetcher
{
    protected ?string $table = 'note';

    public function getFields(): array
    {
        return [
            'id' => FieldType::INT,
            'user_id' => FieldType::INT,
            'content' => FieldType::STRING
        ];
    }

    public function getJoins(): array
    {
        return [
            'user' => UserFetcher::class
        ];
    }

    public function joinUser()
    {
        return 'user.id = content.user_id';
    }
}
