<?php
/**
 * ValidationRule class - Utility class for defining validation rules and
 * evaluating them against provided form data.
 */

class ValidationRule {
    /**
     * The validation rule "type". Should correspond with any of the methods
     * prefixed with "_evaluate".
     * @var string
     */
    var $type;

    /**
     * Optional "value" to coincide with the given rule type, such as a pattern
     * for "regex" types or the minimum value for "minValue" types.
     * @var mixed
     */
    var $value;

    /**
     * The field name that this rule is intended to be evaluated against.
     * @var string
     */
    var $field;

    /**
     * A message to use in the event that data fails to pass evaluation.
     * @var string
     */
    var $message;

    /**
     * A subset of nested ValidationRules. If this property is populated, then
     * these rules must pass before the containing ValidationRule can be
     * evaluated.
     * @var array
     */
    var $when;

    /**
     * Whether this rule's evaluation result should be inverted. A "notEquals"
     * rule will resolve to "equals" with this flag enabled.
     */
    var $inverse;

    /**
     * Constructor method - accepts a configuration array that is used to
     * populate this instance's properties, or a sequence of rule parameters for
     * shorthand creation.
     */
    public function __construct (
        $rule,
        $type = null,
        $value = null,
        $message = null
    ) {
        if (!is_array($rule) && is_string($type) && $value !== null) {
            $rule = array(
                'field' => $rule,
                'type' => $type,
                'value' => $value,
                'message' => $message
            );
        }

        if (!is_array($rule) || empty($rule)) {
            throw new Exception('Invalid definition provided');
        }

        $rule = array_merge(array(
            'type' => null,
            'value' => null,
            'field' => null,
            'message' => null,
            'when' => null,
            'inverse' => false
        ), $rule);

        // A valid rule must not contain null values for "type" or "field"
        if (is_null($rule['type']) || is_null($rule['field'])) {
            throw new Exception('ValidationRule type or field can\'t be null');
        }

        // A valid rule must contain the following keys
        $diff = array_diff(array(
            'type',
            'value',
            'field',
            'message'
        ), array_keys($rule));

        if (!empty($diff)) {
            $keys = implode(',', $diff);
            throw new Exception('Missing rule information: ' . $keys);
        }

        // Automatically convert any inverse rules ("notEquals", "notOneOf",
        // ...) to their respective positives ("equals", "oneOf", ...) and set
        // the "inverse" flag to true
        if (preg_match('/^not[A-Z]/', $rule['type'])) {
            $ruleType = substr($rule['type'], 3);
            if (method_exists($this, '_evaluate' . $ruleType)) {
                $rule['type'] = strtolower($ruleType{0}) . substr($ruleType, 1);
                $rule['inverse'] = true;
            }
        }

        // The "oneOf" type must have an array for a value
        if ($rule['type'] === 'oneOf' && !is_array($rule['value'])) {
            throw new Exception('The value for a "oneOf" rule type must be an'
                                . ' array');
        }

        $this->type = $rule['type'];
        $this->value = $rule['value'];
        $this->field = $rule['field'];
        $this->message = $rule['message'];
        $this->when = $this->_buildWhen($rule['when']);
        $this->inverse = $rule['inverse'];

        return $this;
    }

    /**
     * Returns a list of `ValidationRule`s ready to be attached to the `$when`
     * property.
     *
     * @todo Possibly remove this method and collapse it into `when()`
     * @todo Better documentation I guess
     * @param mixed $when Array of rules or objects or whatever
     * @return array
     */
    private function _buildWhen ($when) {
        // Normalize a single rule to multiple rules
        if (
            (
                is_array($when)
                && array_keys($when) !== range(0, count($when) - 1)
            ) || (
                is_object($when) && is_a($when, __CLASS__)
            )
        ) {
            $when = array($when);
        }

        // Downgrade a collection of rules to a plain array
        if (is_a($when, 'ValidationRuleCollection')) {
            $when = $when->getDefinitions();
        }


        if (!is_array($when)) {
            return null;
        }

        foreach ($when as &$rule) {
            if (is_array($rule)) {
                $rule = new self($rule);
            }
        }

        return $when;
    }

   /**
    * Populates the `$when` property with `ValidationRule`s. If a
    * `ValidationRule` contains nested `ValidationRule`s in its `$when`
    * property, then these rules must pass first before this rule can be
    * evaluated itself.
    *
    * @param array $rules Rule configuration array
    * @return self
    */
    public function when ($rules) {
        if (
            !is_object($rules) || (
                !is_a($rules, __CLASS__)
                && !is_a($rules, 'ValidationRuleCollection')
            )
        ) {
            $rules = self::create(func_get_args());
        }

        if (!$this->when) {
            $this->when = array();
        }

        $this->when = array_merge($this->when, $this->_buildWhen($rules));

        return $this;
    }

    /**
     * Static method for creating a new instance. Used by the `Validator` class.
     *
     * @param array $args
     * @return self
     */
    public static function create ($args) {
        return self::_getReflector()->newInstanceArgs($args);
    }

    /**
     * Return `ReflectionClass` of itself. Used for creating rules, particularly
     * for shorthand instantation.
     *
     * @return ReflectionClass
     */
    private static function _getReflector () {
        static $reflector = null;

        if (!$reflector) {
            $reflector = new ReflectionClass(__CLASS__);
        }

        return $reflector;
    }

    /**
     * Evaluate this rule against the given `$subject`. Delegates conditional
     * logic to the appropriate "_evaluate"-prefixed methods.
     *
     * This method should return true or false based on the results of the
     * evaluation. It may return `null` if something went wrong, in which case
     * the definition may be set up incorrectly.
     *
     * @param mixed $subject The value to run evaluation against.
     * @return boolean|null
     */
    public function evaluate ($subject) {
        $fn = '_evaluate' . ucfirst($this->type);
        if (method_exists($this, $fn)) {
            $res = call_user_func(array($this, $fn), $subject);
            return !!($res ^ $this->inverse);
        } else {
            throw new Exception('Undefined rule type: ' . $this->type);
        }
    }

    /**
     * Return an array containing the rule definition.
     *
     * @return array
     */
    public function getDefinition () {
        if ($when = $this->when) {
            $when = array_map(function ($rule) {
                return $rule->getDefinition();
            }, $when);
        }

        return array(
            'type' => $this->type,
            'value' => $this->value,
            'field' => $this->field,
            'message' => $this->getMessage(),
            'when' => $when,
            'inverse' => $this->inverse
        );
    }

    /**
     * Wrapper for fetching the message associated with the rule - prepared for
     * possible translation stuff in the future. If the rule's message is `null`
     * then fetch a generic message via `_defaultMessage()`.
     *
     * @param boolean $format Replaces {{field}}, {{value}} upon return if true
     * @return string
     */
    public function getMessage ($format = true) {
        $message = $this->message;

        if ($message === null) {
            $message = $this->_defaultMessage();
        }

        if ($format === true) {
            $fieldNice = ucfirst(str_replace('_', ' ', (string) $this->field));
            $valueNice = (string) $this->value;
            if (is_array($this->value)) {
                $valueNice = implode(', ', $this->value);
            }

            $message = str_replace(
                array('{{field}}', '{{value}}'),
                array($fieldNice, $valueNice),
                $message
            );

            if (preg_match('/^[a-z0-9_]+$/', $message)) {
                // internal translation hook, stripping out for now
                // $message = Language::translate($message);
            }
        }

        return $message;
    }

    /**
     * Build and return some kind of generic "default" message based on the
     * rule type.
     *
     * @return string
     */
    private function _defaultMessage () {
        $message = '{{field}} is invalid.';
        switch ($this->type) {
            case 'regex':
                $message = '{{field}} must match pattern: {{value}}';
            break;
            case 'minLength':
                $message = '{{field}} must be at least {{value}} characters.';
            break;
            case 'maxLength':
                $message = '{{field}} must not exceed {{value}} characters.';
            break;
            case 'exactLength':
                $message = '{{field}} must be exactly {{value}} characters.';
            break;
            case 'required':
                $message = '{{field}} is required.';
            break;
            case 'minValue':
                $message = '{{field}} must be at least {{value}}.';
            break;
            case 'maxValue':
                $message = '{{field}} must not exceed {{value}}.';
            break;
            case 'oneOf':
                $message = '{{field}} must be one of: {{value}}';
            break;
            //case 'luhn': break;
            case 'equals':
                $message = '{{field}} must be {{value}}.';
            break;
            case 'exactCount':
                $message = 'There should be exactly {{value}} {{field}}.';
            break;
            case 'minCount':
                $message = 'There should be at least {{value}} {{field}}.';
            break;
            case 'maxCount':
                $message = 'There should be no more than {{value}} {{field}}.';
            break;
        }

        return $message;
    }

    /**
     * Regex evaluation
     * @return boolean
     */
    private function _evaluateRegex ($subject) {
        if (is_numeric($subject) && !is_string($subject)) {
            $subject = (string) $subject;
        }

        return is_string($subject) && preg_match($this->value, $subject);
    }

    /**
     * Minimum character length
     * @return boolean
     */
    private function _evaluateMinLength ($subject) {
        return strlen((string) $subject) >= $this->value;
    }

    /**
     * Maximum character length
     * @return boolean
     */
    private function _evaluateMaxLength ($subject) {
        return strlen((string) $subject) <= $this->value;
    }

    /**
     * Exact character length
     * @return boolean
     */
    private function _evaluateExactLength ($subject) {
        return strlen((string) $subject) === $this->value;
    }

    /**
     * Evaluate whether `$subject` is "empty" - this is used for the `optional`
     * rule property as well.
     * @todo revisit - this doesn't seem right
     * @return boolean
     */
    private function _evaluateRequired ($subject) {
        return !$this->value || (
            isset($subject)
            && ($subject !== '' || $this->value === '')
        );
    }

    /**
     * Minimum numeric value
     * @return boolean
     */
    private function _evaluateMinValue ($subject) {
        return is_numeric($subject) && floatval($subject) >= $this->value;
    }

    /**
     * Maximum numeric value
     * @return boolean
     */
    private function _evaluateMaxValue ($subject) {
        return !$subject
            || (is_numeric($subject) && floatval($subject) <= $this->value);
    }

    /**
     * Evaluate whether `$subject` exists in an array of whitelisted outcomes in
     * the `value` property.
     * @return boolean
     */
    private function _evaluateOneOf ($subject) {
        $values = $this->value;
        if (array_keys($values) !== range(0, count($values) - 1)) {
            $values = array_keys($this->value);
        }

        return in_array($subject, $values);
    }

    /**
     * Luhn algorithm
     * @return boolean
     */
    private function _evaluateLuhn ($subject) {
        if (strlen((string) $subject) === 0) {
            return false;
        }

        $subject = preg_replace('/[^\d]/', '', $subject);
        $sum = 0;

        $str = strrev($subject);
        for ($i = 0; $i < strlen($str); $i++) {
            $digit = $str{$i};

            if ($i & 1 === 1) {
                $digit *= 2;
            }

            if ($digit > 9) {
                $digit -= 9;
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Simple equals shit going on here
     *
     * This method will typecast the $subject to match its $value to accommodate
     * for some complex data type comparisons - the reasoning for this being
     * that data posted from a form is, in most cases, submitted and interpreted
     * as string values. Integers are always cast as floats, and in which case
     * both the $subject and the $value are converted.
     *
     * @return boolean
     */
    private function _evaluateEquals ($subject) {
        $value = $this->value;
        if (!is_bool($subject)) {
            $type = gettype($value);
            if ($type === 'integer') {
                $type = 'float';
                settype($value, $type);
            }
            settype($subject, $type);
        }

        return $subject === $value;
    }

    /**
     * Exact array length
     * @return boolean
     */
    private function _evaluateExactCount ($subject) {
        return is_array($subject) && count($subject) === $this->value;
    }

    /**
     * Minimum array length
     * @return boolean
     */
    private function _evaluateMinCount ($subject) {
        return is_array($subject) && count($subject) >= $this->value;
    }

    /**
     * Maximum array length
     * @return boolean
     */
    private function _evaluateMaxCount ($subject) {
        return is_array($subject) && count($subject) <= $this->value;
    }

}
