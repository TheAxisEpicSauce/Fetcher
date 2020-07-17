<?php
/**
 * User: Raphael Pelissier
 * Date: 18-05-20
 * Time: 17:17
 */

namespace Fetcher\Field;


use Fetcher\Join\Join;

class FieldObject implements Field
{
    private $field;
    private $type;
    private $operator;
    private $value;
    private $join;

    /**
     * FieldObject constructor.
     * @param $field
     * @param $type
     * @param $operator
     * @param $value
     */
    public function __construct(string $field, string $type, string $operator, $value = null)
    {
        FieldType::validateValue($type, 2);
        $this->field = $field;
        $this->type = $type;
        $this->operator = $operator;
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param null $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return mixed
     */
    public function getJoin(): ?Join
    {
        return $this->join;
    }

    /**
     * @param mixed $join
     */
    public function setJoin(Join $join): void
    {
        $this->join = $join;
    }
}
