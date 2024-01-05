<?php
/**
 * User: Raphael Pelissier
 * Date: 15-07-20
 * Time: 16:03
 */

namespace Fetcher\Join;


class Join
{
    private string $path;
    private string $fetcherClass;
    private string $type;
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

    public function addLink(string $tableFrom, string $tableTo, string $joinName)
    {
        $link = new JoinLink($tableFrom, $tableTo, $joinName);
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

        while ($link->next !== null){
            $link = $link->next;
            $path.='.'.$link->getTableTo();
        };

        $this->path = $path;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getPathAs(): string
    {
        $pathParts = explode('.', $this->path);
        $pathAsParts = [];
        foreach ($pathParts as $pathPart)
            $pathAsParts[] = $this->getTableAs($pathPart);

        return implode('.', $pathAsParts);
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

    public function getJoinName(string $tableFrom, string $tableTo): ?string
    {
        foreach ($this->links as $link)
            if ($link->getTableFrom() === $tableFrom && $link->getTableTo() === $tableTo) return $link->getJoinName();

        return null;
    }
}

class JoinLink
{
    private string $tableFrom = '';
    private string $tableTo = '';
    private string $joinName = '';
    public ?self $prev = null;
    public ?self $next = null;

    public function __construct(string $tableFrom, string $tableTo, string $joinName)
    {
        $this->tableFrom = $tableFrom;
        $this->tableTo = $tableTo;
        $this->joinName = $joinName;
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

    public function getJoinName(): string
    {
        return $this->joinName;
    }
}


