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
     * @var array
     */
    private $tableMapping = [];

    /**
     * Join constructor.
     * @param string $path
     * @param string $fetcherClass
     * @param string $type
     */
    public function __construct(string $path, string $fetcherClass, string $type = 'left')
    {
        $this->path = $path;
        $this->fetcherClass = $fetcherClass;
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

    public function pathEndAs()
    {
        return $this->getTableAs($this->pathEnd());
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


    public function addTableMapping(string $table, string $as)
    {
        $this->tableMapping[$table] = $as;
    }

    public function getTableAs(string $table)
    {
        if (array_key_exists($table, $this->tableMapping)) return $this->tableMapping[$table];
        return $table;
    }
}
