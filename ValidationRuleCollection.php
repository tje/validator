<?php
/**
 * ValidationRuleCollection class - Wrapper for ValidationRule instances. This
 * class is intended to delegate any ordinary ValidationRule methods to all of
 * the instances contained in its "pool", such as `when()`.
 */

class ValidationRuleCollection {
    /**
     * An array of ValidationRule instances.
     * @var array
     */
    private $_pool = array();

    /**
     * Constructor - sets the `_pool` property.
     */
    public function __construct ($rules) {
        $this->_pool = $rules;
    }

    /**
     * Magic method intended to delegate any ValidationRule methods to each of
     * the pool nodes. Returns itself afterwards.
     *
     * @return self
     */
    public function __call ($fn, $args) {
        if (method_exists('ValidationRule', $fn)) {
            foreach ($this->_pool as $rule) {
                call_user_func_array(array($rule, $fn), $args);
            }
        }

        return $this;
    }

    /**
     * Returns an array containing all of the rule definitions that belong in
     * the collection pool.
     *
     * @return array
     */
    public function getDefinitions () {
        return array_map(function ($rule) {
            return $rule->getDefinition();
        }, $this->_pool);
    }
}
