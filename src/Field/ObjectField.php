<?php
/**
 * User: Raphael Pelissier
 * Date: 18-05-20
 * Time: 17:17
 */

namespace Fetcher\Field;


use Fetcher\Join\Join;

class ObjectField implements Field
{
    private string $field;
    private string $type;
    private string $operator;
    private mixed $value;
    private ?Join $join = null;
    private ?Join $valueJoin = null;

    public function __construct(string $field, string $type, string $operator, mixed $value)
    {
        FieldType::validateValue($type, 2);
        $this->field = $field;
        $this->type = $type;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue($value): void
    {
        $this->value = $value;
    }

    public function getJoin(): ?Join
    {
        return $this->join;
    }

    public function setJoin(Join $join): void
    {
        $this->join = $join;
    }

    public function getValueJoin(): ?Join
    {
        return $this->valueJoin;
    }

    public function setValueJoin(?Join $valueJoin): void
    {
        $this->valueJoin = $valueJoin;
    }
}
