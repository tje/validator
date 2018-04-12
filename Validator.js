/**
 * Validator
 */
function Validator (namespace) {
  var self = this;

  this.rules = [];

  this.namespace = namespace;

  Validator._instances[namespace] = this;
};

Validator.prototype.addRules = function (rules) {
  rules.map(this.addRule, this);
};

Validator.prototype.addRule = function (rule) {
  this.rules.push(new ValidationRule(rule));
};

Validator.prototype.clearRules = function () {
  this.rules = [];
};

Validator.prototype.evaluate = function (data, value, rules) {
  if (
    data && data.nodeName === 'FORM'
    && jQuery && jQuery.fn.serializeObject
  ) {
    data = jQuery(data).serializeObject();
  }

  if (typeof data === 'string' && value !== undefined) {
    // <3 IE
    var k = data;
    data = {};
    data[k] = value;
  }

  if (!data || typeof data !== 'object') {
    // data.constructor !== Object
    return null;
  }

  var errors = new ValidatorResultset();

  rules = rules || this.rules;

  for (var i = 0, rule = rules[i]; i < rules.length; rule = rules[++i]) {
    var field = rule.field,
        subject = this._resolveSubject(data, field);

    // If we're only checking a specific field, skip ahead
    if (data && value !== undefined && subject === undefined) {
      continue;
    }

    if (rule.when !== null) {
      var subsetResult = this.evaluate(data, undefined, rule.when).getStatus();
      if (subsetResult === false) {
        errors.push({
          field: field,
          type: rule.type,
          active: false,
          message: rule.getMessage(),
          namespace: this.namespace,
          passed: true
        });
        continue;
      }
    }

    var result = rule.evaluate(subject);

    if (result !== undefined) {
      errors.push({
        field: field,
        type: rule.type,
        active: true,
        message: rule.getMessage(),
        namespace: this.namespace,
        passed: result
      });
    } else {
      console.warn('Unexpected evaluation result from rule:', rule);
    }
  }

  return errors;
};

Validator.prototype._resolveSubject = function (data, field) {
  var subject = data[field];

  if (subject === undefined) {
    subject = data;
    var m, rexp = /^([^\]]+)\[([^\]]+)\]/;

    field = field.replace(/\[\]/g, '');

    while (typeof subject === 'object' && (m = field.match(rexp))) {
      subject = subject[m[1]];
      field = field.replace(rexp, '$2');
    }

    subject = subject && subject[field];
  }
  return subject;
};

/**
 * Validator (global)
 */
Validator._instances = {};

Validator.get = function (namespace) {
  var instances = this._instances;
  namespace = namespace || '';

  for (var ns in instances) if (instances.hasOwnProperty(ns)) {
    if (ns === namespace) {
      return instances[ns];
    }
  }

  return null;
};

Validator.evaluate = function (el, namespace) {
  var errors = new ValidatorResultset();
  var instances = this._instances;
  var container = this.findContainer(el);

  var containerNs = namespace || container.dataset.validatorNamespace || '';

  for (var ns in instances) if (instances.hasOwnProperty(ns)) {
    if (ns !== '' && ns !== containerNs) {
      continue;
    }

    errors = errors.concat(instances[ns].evaluate(el));
  }

  console.log(errors);
  return errors;
};

Validator.findContainer = function (el) {
  for (; el && el !== document; el = el.parentNode) {
    if (el.dataset.validatorNamespace) {
      return el;
    }
  }
};

/**
 * ValidationRule
 */
function ValidationRule (rule) {
  if (rule.type === 'regex' && typeof rule.value === 'string') {
    var rexp = rule.value.match(/\/(.+)\/([a-z]*)/),
        pattern = rexp[1],
        flags = rexp[2];
    rule.value = new RegExp(pattern, flags);
  }

  if (!rule.type || !rule.field) {
    console.warn('ValidationRule "type" or "field" can\'t be null');
  }

//  if (rule.type.match(/^not[A-Z]/)) {
//    rule.type = rule.type.substr(3);
//    rule.type = rule.type[0].toLowerCase() + rule.type.substr(1);
//    rule.inverse = true;
//  }

  if (rule.type === 'oneOf') {
    if (typeof rule.value !== 'object') {
      console.warn('The value for a "oneOf" rule type must be an array');
    }
  }

  this.type = rule.type;
  this.value = rule.value;
  this.field = rule.field;
  this.message = rule.message;
  this.optional = rule.optional;
  this.inverse = !!rule.inverse;
  this.when = this._buildWhen(rule.when);
};

ValidationRule.prototype._buildWhen = function (when) {
  if (!when || !Array.isArray(when)) {
    return null;
  }

  for (var i = 0; i < when.length; i++) {
    when[i] = new ValidationRule(when[i]);
  }

  return when;
};

ValidationRule.prototype.getMessage = function () {
  return this.message;
};

ValidationRule.prototype.evaluate = function (subject) {
  var type = this.type;
  var fn = '_evaluate' + (type[0].toUpperCase() + type.substr(1));

  if (this[fn] && typeof this[fn] === 'function') {
    return !!(this[fn].call(this, subject) ^ this.inverse);
  }
};

ValidationRule.prototype._evaluateRegex = function (subject) {
  if (!isNaN(subject) && typeof subject !== 'string') {
    var subject = subject.toString();
  }

  return typeof subject === 'string' && !!subject.match(this.value);
};

ValidationRule.prototype._evaluateMinLength = function (subject) {
  return (!!subject && this.value > 0)
      && subject.toString().length >= this.value;
};

ValidationRule.prototype._evaluateMaxLength = function (subject) {
  return !subject || subject.toString().length <= this.value;
};

ValidationRule.prototype._evaluateExactLength = function (subject) {
  return !!subject && subject.toString().length === this.value;
};

ValidationRule.prototype._evaluateRequired = function (subject) {
  return !this.value || (
      subject !== null
      && subject !== undefined
      && subject !== ''
  );
};

ValidationRule.prototype._evaluateMinValue = function (subject) {
  return !isNaN(subject) && parseFloat(subject) >= this.value;
};

ValidationRule.prototype._evaluateMaxValue = function (subject) {
  return !subject
      || (!isNaN(subject) && parseFloat(subject) <= this.value);
};

ValidationRule.prototype._evaluateOneOf = function (subject) {
  var values = this.value;
  if (!Array.isArray(values)) {
    values = Object.keys(values);
  }

  return values.indexOf(subject) !== -1;
};

ValidationRule.prototype._evaluateLuhn = function (subject) {
  if (!subject || subject.length === 0) {
    return false;
  }

  var subject = subject.replace(/[^\d]/g, '');
  var sum = 0;

  var str = subject.split('').reverse().join('');
  for (var i = 0; i < str.length; i++) {
    var digit = parseInt(str[i], 10);

    if (i & 1 === 1) {
      digit *= 2;
    }

    if (digit > 9) {
      digit -= 9;
    }

    sum += digit;
  }

  return sum % 10 === 0;
};

ValidationRule.prototype._evaluateEquals = function (subject) {
  if (typeof subject !== 'boolean') {
    var subject = this.value.constructor.call(this.value.constructor, subject);
  }

  return subject === this.value;
};

ValidationRule.prototype._evaluateExactCount = function (subject) {
  return Array.isArray(subject) && subject.length === parseInt(this.value, 10);
};

ValidationRule.prototype._evaluateMinCount = function (subject) {
  return Array.isArray(subject) && subject.length >= parseInt(this.value, 10);
};

ValidationRule.prototype._evaluateMaxCount = function (subject) {
  return Array.isArray(subject) && subject.length <= parseInt(this.value, 10);
};

/**
 * ValidatorResultset
 */
function ValidatorResultset () {
  var args = arguments;

  if (args.length === 1 && Array.isArray(args[0])) {
    args = args[0];
  }

  this.push.apply(this, args);

  return this;
};

ValidatorResultset.prototype = Object.create(Array.prototype);

ValidatorResultset.prototype.filter = function (fn) {
  return new ValidatorResultset(Array.prototype.filter.call(this, fn));
};

ValidatorResultset.prototype.get = function (fields) {
  if (typeof fields === 'string') {
    fields = [fields];
  }

  return this.filter(function (item) {
    return fields.indexOf(item.field) !== -1;
  });
};

ValidatorResultset.prototype.getType = function (ruleTypes) {
  if (typeof ruleTypes === 'string') {
    ruleTypes = [ruleTypes];
  }

  return this.filter(function (item) {
    return ruleTypes.indexOf(item.type) !== -1;
  });
};

ValidatorResultset.prototype.getStatus = function () {
  return this.getFailed().length === 0;
};

ValidatorResultset.prototype.getPassed = function () {
  return this.filter(function (item) {
    return item.passed === true;
  });
};

ValidatorResultset.prototype.getFailed = function () {
  return this.filter(function (item) {
    return item.passed === false;
  });
};

ValidatorResultset.prototype.getActive = function () {
  return this.filter(function (item) {
    return item.active === true;
  });
};

ValidatorResultset.prototype.getMessages = function () {
  return this.map(function (item) {
    return item.message;
  });
};

/*
// Pending removal in favor of backward compatibility
Object.defineProperties(ValidatorResultset.prototype, {
  failed: {
    enumerable: true,
    writeable: false,
    get: function () {
      return this.getFailed();
    }
  },
  passed: {
    enumerable: true,
    writeable: false,
    get: function () {
      return this.getPassed();
    }
  },
  status: {
    enumerable: false,
    writeable: false,
    get: function () {
      return this.getStatus();
    }
  }
});
*/

/**
 * Automagic
 */
(function (w) {
  if (w.VALIDATION_RULES) {
    for (var ns in VALIDATION_RULES) if (VALIDATION_RULES.hasOwnProperty(ns)) {
      var validator = new Validator(ns);
      validator.addRules(VALIDATION_RULES[ns]);
    }
  }

  if (w.VALIDATION_RESULTS && w.Errors && w.jQuery) {
    jQuery(document).ready(function () {
      var res = new ValidatorResultset(VALIDATION_RESULTS);
      Errors.toggle(res);
    });
  }
}(window));
