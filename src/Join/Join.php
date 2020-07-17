<?php
/**
 * User: Raphael Pelissier
 * Date: 15-07-20
 * Time: 16:03
 */

namespace Fetcher\Join;


class Join
{
    /**
     * @var string
     */
    private $path;
    /**
     * @var string
     */
    private $fetcherClass;

    /**
     * Join constructor.
     * @param string $path
     * @param string $reporterClass
     */
    public function __construct(string $path, string $reporterClass)
    {
        $this->path = $path;
        $this->fetcherClass = $reporterClass;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function prependPath(string $table)
    {
        $this->path = $table.'.'.$this->path;
    }

    /**
     * @return string
     */
    public function getFetcherClass(): string
    {
        return $this->fetcherClass;
    }

    public function getTables(): array
    {
        return explode('.', $this->path);
    }
}
