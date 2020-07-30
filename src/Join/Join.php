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
     * @var string
     */
    private $type;

    /**
     * Join constructor.
     * @param string $path
     * @param string $reporterClass
     * @param string $type
     */
    public function __construct(string $path, string $reporterClass, string $type = 'left')
    {
        $this->path = $path;
        $this->fetcherClass = $reporterClass;
        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function pathEnd()
    {
        $tables = $this->getTables();
        return array_pop($tables);
    }

    public function pathLength()
    {
        return count($this->getTables());
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

    public function setLeftJoin()
    {
        $this->type = 'left';
    }

    public function isLeftJoin()
    {
        return $this->type = 'left';
    }

    public function setFullJoin()
    {
        $this->type = 'full';
    }
}
