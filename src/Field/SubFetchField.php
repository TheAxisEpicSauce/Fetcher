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
    private $name;
    private $method;
    private ?string $as;

    public function __construct(BaseFetcher $fetcher, Join $join, string $name, string $method, ?string $as)
    {
        $this->fetcher = $fetcher;
        $this->join = $join;
        $this->name = $name;
        $this->method = $method;
        $this->as = $as;
    }

    public function getFetcher(): BaseFetcher
    {
        return $this->fetcher;
    }

    public function getJoin(): Join
    {
        return $this->join;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getAs(): ?string
    {
        return $this->as;
    }
}
