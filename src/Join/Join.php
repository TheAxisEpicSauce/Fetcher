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
    private string $path;
    /**
     * @var string
     */
    private string $fetcherClass;
    /**
     * @var string
     */
    private string $type;
    /**
     * @var array
     */
    private array $tableMapping = [];

    /**
     * @var JoinLink[]
     */
    private array $links;


    private ?string $valueType = null;

    public function __construct(string $fetcherClass, string $type = 'left')
    {
        $this->path = '';
        $this->fetcherClass = $fetcherClass;
        $this->type = $type;
        $this->links = [];
    }

    public function addLink(string $tableFrom, string $tableTo, string $type)
    {
        $link = new JoinLink($tableFrom, $tableTo, $type);
        $linkB = array_key_exists($tableTo, $this->links)?$this->links[$tableTo]:null;
        $link->next = $linkB;
        if ($linkB) $linkB->prev = $link;
        $this->links[$tableFrom] = $link;

        $this->generatePath();
    }

    private function generatePath()
    {
        $link = null;
        foreach ($this->links as $link) if ($link->prev === null) break;
        if ($link === null) return;

        $path = $link->getTableTo();
        if ($link->next !== null) {
            do {
                $link = $link->next;
                $path.='.'.$link->getTableTo();
            } while ($link->next !== null);
        }
        $this->path = $path;
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

    public function pathStart()
    {
        $tables = $this->getTables();
        return array_shift($tables);
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
        return $this->type === 'left';
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

    public function getValueType(): ?string
    {
        return $this->valueType;
    }

    public function setValueType(?string $valueType): void
    {
        $this->valueType = $valueType;
    }
}

class JoinLink
{
    private $tableFrom;
    private $tableTo;
    private $type;
    public $prev;
    public $next;

    public function __construct(string $tableFrom, string $tableTo, string $type)
    {
        $this->tableFrom = $tableFrom;
        $this->tableTo = $tableTo;
        $this->type = $type;
        $prev = null;
        $next = null;
    }

    public function getTableFrom(): string
    {
        return $this->tableFrom;
    }

    public function getTableTo(): string
    {
        return $this->tableTo;
    }

    public function getType(): string
    {
        return $this->type;
    }


}


