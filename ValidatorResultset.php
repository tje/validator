<?php
/**
 * ValidatorResultset class - Utility class for handling results from Validator
 * evaluations. Note that this class extends ArrayObject - it shouldn't be
 * expected to behave exactly like an array (e.g. `empty($resultset)` always
 * returns `false`).
 *
 * // Get a resultset
 * $resultset = $validator->evaluate($postData);
 *
 * // Get a list of all the error messages
 * $resultset->failed->getMessages();
 *
 * // Get the results for a specific field
 * $resultset->get('some_input_field');
 *
 * // Determine if evaluation was successful as a whole
 * $resultset->status;
 */

class ValidatorResultset extends ArrayObject {

    /**
     * Alias properties like "failed" to return the result of `getFailed()`
     */
    public function offsetGet ($name) {
        switch ($name) {
            case 'failed':
            case 'passed':
            case 'status':
                return call_user_func(array($this, 'get' . ucfirst($name)));
            break;
        }

        return parent::offsetGet($name);
    }

    /**
     * Constructor
     */
    public function __construct ($items = array()) {
        parent::__construct($items, ArrayObject::ARRAY_AS_PROPS);
    }

    /**
     * Return results for a specific field.
     *
     * @param string $field The field name to narrow these results for
     * @return ValidatorResultset
     */
    public function get ($field) {
        return new self(array_filter($this->getArrayCopy(), function ($item) {
            return $item['field'] === $field;
        }));
    }

    /**
     * Return the overall "success" of the resultset - whether any of the
     * results have failed.
     *
     * @return bool
     */
    public function getStatus () {
        return count($this->getFailed()) === 0;
    }

    /**
     * Return only the failed results.
     *
     * @return ValidatorResultset
     */
    public function getFailed () {
        return new self(array_filter($this->getArrayCopy(), function ($item) {
            return $item['passed'] === false;
        }));
    }

    /**
     * Return only the passed results.
     *
     * @return ValidatorResultset
     */
    public function getPassed () {
        return new self(array_filter($this->getArrayCopy(), function ($item) {
            return $item['passed'] === true;
        }));
    }

    /**
     * Return only "active" results - a rule is only considered "inactive" when
     * it has a `when` condition that does not pass.
     *
     * @return ValidatorResultset
     */
    public function getActive () {
        return new self(array_filter($this->getArrayCopy(), function ($item) {
            return $item['active'] === true;
        }));
    }

    /**
     * Return all of the messages for the results.
     *
     * @return array
     */
    public function getMessages () {
        return array_values(array_map(function ($item) {
            return $item['message'];
        }, $this->getArrayCopy()));
    }
}
