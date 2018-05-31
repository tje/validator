# Validator

This is a utility I wrote to handle server- and client-side form validation. The
objective of this is to consolidate validation rule definitions to one side,
rather than having to write and maintain two near-identical rule sets in two
different languages.

At the time of writing, the documentation in this readme is sorely incomplete.
Refer to the comments in the source code for more information.

## Back-end setup

Put the PHP files wherever your heart desires - `Validator.php` is the entry
point file you'll want to import.

```php
require_once 'Validator.php';

// Create a new Validator instance and give it a namespace
$validator = new Validator('namespace');

// Define some rules, in this example I have a "year" and "month" field that
// must be supplied
$validator->addRule('year', 'required', true);
$validator->addRule('month', 'required', true);

// Require "year" to be current or in the future
$validator->addRule('year', 'minValue', date('Y'));

// Rules can be chained to conditional rules; require that the "month" be
// current or in the future, but only if "year" matches that of today's date
$validator->addRule('month', 'minValue', date('n'))
          ->when('year', 'equals', date('Y'));

// Run the validator and return the results
$sampleData = array(
  'month' => '5',
  'year' => '2018'
);
$results = $validator->evaluate($sampleData);

// Handle the results
$errors = $results->getFailed();
if (count($errors) > 0) {
  echo 'Oh noooo!';
  foreach ($errors as $error) {
    echo $error['message'];
  }
}
```

## Front-end setup

Include the `Validator.js` file somewhere. This script will create a
`window.Validator` object to use for validation.

This object depends on a global `VALIDATION_RULES` object. We can pull that over
from the server by calling the `export()` method, either statically or on an
individual instance. Here's a crude vanilla example:

```php
window.VALIDATION_RULES = <?php echo json_encode(Validator::export()); ?>
```

And to evaluate on the client side JavaScript:

```js
// Refer to a Validator instance with the same namespace we used before
const validator = Validator.get('namespace')

// Evaluate some tasty data
const sampleData = {
  month: '5',
  year: '2018'
}
const results = validator.evaluate(sampleData)

// Handle the results
const errors = results.getFailed()
if (errors.length > 0) {
  console.log('Oh noooo!')
  for (const err of errors) {
    console.log(err.message)
  }
}

// We can also manipulate some page elements
for (const result of results.getActive()) {
  const $el = document.querySelector(`[name="${result.field}"]`)
  $el.classList.remove('passed', 'failed')
  $el.classList.add(result.passed ? 'passed' : 'failed')
}
```

## Available rules

| Rule name | Description |
| :--- | :--- |
| `required` | If true, value must not be empty. |
| `regex` | Value must match the provided regular expression. |
| `minLength` | Length of value must meet or exceed a number. |
| `maxLength` | Length of value must not exceed a number. |
| `exactLength` | Length of value must be exactly a number. |
| `minValue` | Value must be greater than or equal to a number. |
| `maxValue` | Value must be less than or equal to a number. |
| `oneOf` | Value must belong to an array of whitelisted options. |
| `luhn` | Value must pass the [Luhn algorithm](https://en.wikipedia.org/wiki/Luhn_algorithm). |
| `equals` | Value must be equal to another. |
| `exactCount` | Value must contain no more and no less of a number of items. |
| `minCount` | Value must contain no less than a number of items. |
| `maxCount` | Value must contain no more than a number of items. |

## Todo list

* Better documentation :(
* Provide complete examples
* Organize project files
* Add tests
* Maybe a demo
