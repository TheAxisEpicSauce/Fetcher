<?php
/**
 * User: Raphael Pelissier
 * Date: 04-08-20
 * Time: 11:08
 */

namespace Fetcher\Validator;


use Exception;
use Fetcher\Field\ObjectField;
use Fetcher\Field\FieldType;
use Fetcher\Field\Operator;

class FieldObjectValidator
{
    /**
     * @var ObjectField
     */
    private $object;

    /**
     * @param ObjectField $fieldObject
     * @throws Exception
     */
    public function validate(ObjectField $fieldObject)
    {
        $this->object = $fieldObject;

        if ($this->isArrayOperator($fieldObject->getOperator())) {
            if (!is_array($fieldObject->getValue())) {
                throw new Exception(sprintf('value of %s should be of type array', $this->object->getField()));
            }
            foreach ($fieldObject->getValue() as $value)
                $this->validateFromType($fieldObject->getType(), $value);
        }
        elseif (Operator::IsFieldOperator($fieldObject->getOperator()))
        {
            if (!is_string($fieldObject->getValue())) {
                throw new Exception(sprintf('value of %s should be of type string', $this->object->getField()));
            }
        }
        else
        {
            $this->validateFromType($fieldObject->getType(), $fieldObject->getValue());
        }
    }

    private function isArrayOperator(string $operator)
    {
        return $operator === Operator::IN || $operator === Operator::NOT_IN || $operator === Operator::IN_LIKE;
    }

    private function validateFromType(string $type, $value)
    {
        $valid = false;
        switch ($type) {
            case FieldType::INT; $valid = is_int($value); break;
            case FieldType::FLOAT; $valid = is_float($value); break;
            case FieldType::STRING; $valid = is_string($value); break;
            case FieldType::BOOLEAN; $valid = is_bool($value); break;
            case FieldType::DATE;
            case FieldType::DATE_TIME;
                $valid = true;
                break;
        }
        if (!$valid && $value !== null) throw new Exception(sprintf(
            'value of %s should be of type %s', $this->object->getField(), $type
        ));

        return $valid;
    }
}
