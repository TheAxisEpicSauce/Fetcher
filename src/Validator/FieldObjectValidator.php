<?php
/**
 * User: Raphael Pelissier
 * Date: 04-08-20
 * Time: 11:08
 */

namespace Fetcher\Validator;


use Exception;
use Fetcher\Field\FieldObject;
use Fetcher\Field\FieldType;
use Fetcher\Field\Operator;

class FieldObjectValidator
{
    /**
     * @param FieldObject $fieldObject
     * @throws Exception
     */
    public function validate(FieldObject $fieldObject)
    {
        if ($this->isArrayOperator($fieldObject->getOperator())) {
            if (!is_array($fieldObject->getValue())) {
                throw new Exception('value should be of type array');
            }
            foreach ($fieldObject->getValue() as $value)
                $this->validateFromType($fieldObject->getType(), $value);
        } else{
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
        if (!$valid) throw new Exception('value should be of type '.$type);
        return $valid;
    }
}
