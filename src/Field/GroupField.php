<?php
/**
 * User: Raphael Pelissier
 * Date: 18-05-20
 * Time: 17:52
 */

namespace Fetcher\Field;


class GroupField implements Field
{
    private $conjunction;
    /**
     * @var array|Field[]|ObjectField[]|GroupField[]
     */
    private $fields;

    /**
     * FieldGroup constructor.
     * @param $conjunction
     * @param array $fields
     */
    public function __construct(string $conjunction, array $fields = [])
    {
        $this->conjunction = $conjunction;
        $this->fields = $fields;
    }

    /**
     * @return string
     */
    public function getConjunction(): string
    {
        return $this->conjunction;
    }

    /**
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function addField(Field $field)
    {
        $this->fields[] = $field;
    }

    public function isEmpty(): bool
    {
        return count($this->fields) === 0;
    }
}
