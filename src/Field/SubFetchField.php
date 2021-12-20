<?php
/**
 * User: Raphael Pelissier
 * Date: 20-12-21
 * Time: 12:33
 */

namespace Fetcher\Field;


use Fetcher\BaseFetcher;
use Fetcher\Join\Join;

class SubFetchField implements Field
{
    private $fetcher;
    private $join;

    public function __construct(BaseFetcher $fetcher, Join $join)
    {
        $this->fetcher = $fetcher;
        $this->join = $join;
    }

    public function getFetcher(): BaseFetcher
    {
        return $this->fetcher;
    }

    public function getJoin(): Join
    {
        return $this->join;
    }

}
