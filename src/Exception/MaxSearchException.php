<?php

namespace Fetcher\Exception;

class MaxSearchException extends FetcherException
{

    public function __construct(array $tablePath)
    {
        parent::__construct(sprintf("Path %s exceeded max search depth", json_encode($tablePath)));
    }
}