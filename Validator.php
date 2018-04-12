<?php
/**
 * Validator class - Utility class for defining validation rules and evaluating
 * them against provided form data.
 *
 * Example usage:
 *
 * // Create a new Validator object
 * $validator = new Validator('namespace');
 *
 * // Add a couple of rules to it
 * $validator->addRule(array(
 *   'type' => 'regex',
 *   'field' => 'some_field_name',
 *   'value' => '/.{3,}/',
 *   'message' => 'Some field must be at least 3 characters',
 *   'optional' => false
 * ));
 * $validator->addRule(array(
 *   'type' => 'minValue',
 *   'field' => 'another_field',
 *   'value' => 10,
 *   'message' => 'Another field must at least 10',
 *   'optional' => true
 * ));
 *
 * // Alternative shorthand method:
 * $validator->addRule('another_field', 'minValue', 10);
 *
 * // A rule can have a subset of conditional rules that must pass before
 * // it evaluates itself
 * $validator->addRule('state', 'oneOf', $statesList)
 *           ->when('country', 'equals', 'USA');
 *
 * // ...Or:
 * $isUsaRule = new ValidationRule('country', 'equals', 'USA');
 * $validator->addRule('state', 'oneOf', $statesList)
 *           ->when($isUsaRule);
 *
 * // Test data - real-world example might include form data from _POST, etc.
 * $testData = array(
 *   'some_field_name' => 'sup dog',
 *   'another_field' => 5
 * );
 *
 * // Evaluate against $testData and return the results (ValidatorResultset
 * // object) - this will include results for all of the validation rules
 * $results = $validator->evaluate($testData);
 *
 * // Print all of the results
 * print_r($results->getArrayCopy());
 * > Array
 * > (
 * >     [0] => Array
 * >         (
 * >             [field] => some_field_name
 * >             [message] => Some field must be at least 3 characters
 * >             [namespace] => namespace
 * >             [passed] => 1
 * >         )
 * >     [1] => Array
 * >         (
 * >             [field] => another_field
 * >             [message] => Another field must be at least 10
 * >             [namespace] => namespace
 * >             [passed] =>
 * >         )
 * > )
 *
 * // We can send the errors to the view
 * $view->set('validationErrors', $results->failed);
 */

require_once 'ValidationRule.php';
require_once 'ValidationRuleCollection.php';
require_once 'ValidatorResultset.php';

class Validator {

    /**
     * A list of Validator instances. Every time a new Validator is
     * instantiated, it is pushed into this array.
     * @var array
     */
    private static $_instances = array();

    /**
     * The namespace for a Validator instance. Used to differentiate between
     * multiple Validators, useful for front-end evaluation when there are
     * several forms.
     * @var string|null
     */
    private $_namespace = null;

    /**
     * A list of ValidationRule objects, stored here as they are added via
     * `addRules` or `addRule`.
     * @var array
     */
    private $_rules = array();

    /**
     * Constructor method - upon instantiation, push this new instance to the
     * static `_instances` property.
     *
     * @param string|null $namespace
     * @return void
     */
    public function __construct ($namespace = null) {
        if (is_string($namespace)) {
            $this->_namespace = $namespace;
        }

        array_push(self::$_instances, $this);
    }

    /**
     * Return an instance's `_namespace`
     *
     * @return string|null
     */
    public function getNamespace () {
        return $this->_namespace;
    }

    public static function get ($namespace) {
        foreach (self::$_instances as $instance) {
            if ($instance->getNamespace() === $namespace) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Add multiple rules at once - applies the `addRule` method to each item
     * in the given array.
     *
     * @see addRule()
     * @param array $rules An array of rule definitions
     * @return void
     */
    public function addRules () {
        $rules = func_get_args();

        if (
            count($rules) === 1
            && array_keys($rules[0]) === range(0, count($rules[0]) - 1)
        ) {
            $rules = $rules[0];
        }

        $objects = array();
        foreach ($rules as $rule) {
            if (array_keys($rule) !== range(0, count($rule) - 1)) {
                $rule = array($rule);
            }
            $objects[] = call_user_func_array(array($this, 'addRule'), $rule);
        }

        $collection = new ValidationRuleCollection($objects);
        return $collection;
    }

    /**
     * Add a single validation rule. The `$rule` provided should contain the
     * following keys:
     *   - type         string  Type of rule: regex, minValue, minLength, etc.
     *   - field        string  Name of the field that the rule applies to
     *   - value        mixed   A value to correspond to the rule type, such as
     *                            a regex pattern, minimum value, etc.
     *   - message      string  Message to display if evaluation doesn't pass
     *   - optional     bool    Evaluate only if input isn't "empty"
     *
     * For a complete list of available rules, see the `ValidationRule` class'
     * methods that begin with "_evaluate".
     *
     * @param array|string $rule The definition to add, or field name
     * @param string $type (optional) Rule type
     * @param mixed $value (optional) Rule value/pattern to match
     * @param string $message
     * @return void
     */
    public function addRule () {
        $newRule = ValidationRule::create(func_get_args());

        // If there's already an existing rule for the same field and rule type,
        // replace it (unless it's regex)
        // @todo is this the right thing to do?
        foreach ($this->_rules as $idx => $rule) {
            if (
                $rule->field === $newRule->field
                && $rule->type === $newRule->type
                && $rule->type !== 'regex'
            ) {
                unset($this->_rules[$idx]);
            }
        }

        $this->_rules[] = $newRule;

        return $newRule;
    }

    /**
     * Remove all the currently stored rules.
     *
     * @return void
     */
    public function clearRules () {
        $this->_rules = array();
    }

    /**
     * Get a list of the current rule definitions. If `$returnObjects` is set to
     * true, returns the `ValidationRule` objects - otherwise the rules are
     * reduced to simple arrays.
     *
     * @param bool $returnObjects Whether to return arrays or objects
     * @return array An array of definitions or `ValidationRule`s
     */
    public function getRules ($returnObjects = false) {
        if ($returnObjects === true) {
            return $this->_rules;
        }

        $definitions = array();

        foreach ($this->_rules as $rule) {
            $definition = $rule->getDefinition();
            $definition['namespace'] = $this->_namespace;

            array_push($definitions, $definition);
        }

        return $definitions;
    }

    /**
     * Similar to `getRules`, however intended to be called from a static
     * perspective. This is used in the `Layout` class during render to send all
     * of the Validators' rules to the front-end.
     *
     * If a namespace is provided, this will return rules for the Validator
     * instance(s) using that namespace *AND* rules attached to Validators
     * without a namespace specified (null).
     *
     * @param string|null $namespace Optional namespace to limit export to.
     * @return array
     */
    public static function export ($namespace = null) {
        $instances = self::$_instances;

        $definitions = array();

        foreach ($instances as $instance) {
            $instanceDefs = $instance->getRules();
            $ns = $instance->getNamespace();

            if ($namespace !== null && $namespace !== $ns) {
                continue;
            }

            if (!isset($definitions[$ns])) {
                $definitions[$ns] = array();
            }

            $definitions[$ns] = array_merge($definitions[$ns], $instanceDefs);
        }

        return $definitions;
    }

    /**
     * Get a list of the current rule definitions, serialized as JSON. Probably
     * unnecessary when using with the Rendering stack, as a 'json' helper is
     * provided there.
     *
     * @deprecated
     * @return string
     */
    public function toJson () {
        return json_encode($this->getRules(), 0);
    }

    /**
     * Cycle through the current rule definitions and evaluate them against the
     * provided `$data`. Returns a list of errors (or an empty array) including
     * the rejected field name and appropriate error message.
     *
     * INSTANCE:
     * This method accepts either an array of key value pairs as its single
     * argument, or a field name (string) and a value to coincide with it.
     *
     * $Validator->evaluate(array('one' => 'two'));
     * $Validator->evaluate('one', 'two');
     *
     * STATIC:
     * Alternatively this method can be called statically, where the second
     * argument is expected to be a namespace. If no namespace is provided, all
     * rules are evaluated againsted the provided data.
     *
     * Validator::evaluate($data, 'namespace');
     *
     * @param array|string $data
     * @param mixed $value
     * @return array List of errors, if there are any
     */
    public function evaluate ($data, $value = null, $rulesets = null) {
        if (isset($this) && is_string($data)) {
            $data = array($data => $value);
        }

        if (isset($this) && (!is_array($data) || empty($data))) {
            return null;
        }

        // Compile a list of rules to evaluate against $data
        if ($rulesets === null && !isset($this)) {
            // If this method was called statically, cycle through the tracked
            // instances and fetch their rules based on the namespace ($value)
            $instances = self::$_instances;
            $rulesets = array();
            foreach (self::$_instances as $instance) {
                $ns = $instance->getNamespace();
                if ($ns === null || $ns === $value || $value === null) {
                    $rulesets[$ns] = $instance->getRules(true);
                }
            }
        } elseif ($rulesets === null) {
            // If this method was called from an instance of the Validator
            // object, then we only need its own rules
            $rulesets = array($this->getNamespace() => $this->_rules);
        }

        $errors = new ValidatorResultset();
        foreach ($rulesets as $ns => $rules) {
            foreach ($rules as $rule) {
                $field = $rule->field;
                $subject = self::_resolveSubject($data, $field);

                // If the rule has extra conditionals, those must be met in
                // order for this rule to even be valid
                if ($rule->when !== null) {
                    $subsetResult = self::evaluate($data, null, array(
                        $ns => $rule->when
                    ));

                    if ($subsetResult->getStatus() === false) {
                        $errors[] = array(
                            'field' => $field,
                            'type' => $rule->type,
                            'active' => false,
                            'message' => $rule->getMessage(),
                            'namespace' => $ns,
                            'passed' => true
                        );
                        continue;
                    }
                }

                // Evaluate the rule and push the result to the `$errors` list
                $result = $rule->evaluate($subject);
                if (is_bool($result)) {
                    $errors[] = array(
                        'field' => $field,
                        'type' => $rule->type,
                        'active' => true,
                        'message' => $rule->getMessage(),
                        'namespace' => $ns,
                        'passed' => $result
                    );
                } else {
                    $def = print_r($rule->getDefinition(), true);
                    throw new Exception(
                        'Unexpected evaluation result from rule: ' . $def
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Returns the "subject" to be evaluated for a rule from the provided
     * `$data` set.
     *
     * Examples:
     * _resolveSubject($data, 'first_name');    // $data['first_name']
     * _resolveSubject($data, 'customer[id]');  // $data['customer']['id']
     *
     * @param array $data The data to search
     * @param string $field The field name to search for
     * @return mixed The subject from $data, or null if nothing is found
     */
    public static function _resolveSubject ($data, $field) {
        $hashKey = preg_replace('/\[\]/', '', $field);
        $hashKey = preg_replace('/\[([^\]]+)\]/', '::$1', $hashKey);

        $target = $data;
        $crumbs = explode('::', $hashKey);
        while (count($crumbs) > 0 && $crumb = array_shift($crumbs)) {
            if (array_key_exists($crumb, $target)) {
                $target = $target[$crumb];
            } else {
                return null;
            }
        }
        return $target;
    }

}
