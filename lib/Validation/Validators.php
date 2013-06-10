<?php

	namespace ActiveRecord\Validation;

	abstract class Validator
	{

		public function setOptions($options)
		{

			return $this->_setOptions($options);

		}

		abstract protected function _setOptions($options);


		/**
		 * Validate given field and model against this Validator
		 * @param  string 		The fieldname to look for in the model
		 * @param  array|Model  An associative array or a ActiveRecord Model instance
		 * @return string|true 	When validation succeeds true, otherwise the error-key for the validation error.
		 */
		public function validate($field, $model) 
		{

			return $this->_validate($this->getValue($model, $field), $field, $model);

		}

		abstract protected function _validate($value, $field, $model);


		protected function getValue($model, $field) {

			// Is it an array?
			if (is_array($model)) return $model[$field];

			// Then it must be a model...
			if (!$model->hasAttribute($field)) return null;
			return $model->$field;

		}

	}

	class PresenceValidator extends Validator
	{


		protected function _setOptions($options)
		{

		}

		
		protected function _validate($value, $field, $model)
		{

			// Value is null?
			if (is_null($value)) return 'presence';

			// Empty?
			if (empty($value)) return 'presence';


			// Passed the test
			return true;

		}

	}


	/**
	 * static $validates = array("name" => array("format" => "alpha"));
	 * static $validates = array("name" => array("format" => "/blabla-regex/", "as" => "errormessagekey"));
	 */
	class FormatValidator extends Validator
	{


		public static $formats = array(
			"alpha" => '/^[a-zA-Z]*$/',
			"alphanumeric" => '/^[a-zA-Z0-9]*$/',
			"numeric" => '/^[0-9]*$/'
		);
 

		private $_format; 
		private $_as;


		protected function _setOptions($options)
		{

			// Is it not an array?
			if (!is_array($options)) {
				$options = array("with" => $options);
			}

			// Is the with a regex already?
			if (preg_match('/^\/.*\/[a-z]*$/', $options['with'])) {

				// Store regex
				$this->_format = $options['with'];

				// Look for 'as' key
				if (!array_key_exists("as", $options)) {
					throw new \Exception("If you use the FormatValidator with a custom regular expression, you need to pass an 'as' parameter as well, to indicate the error message key.", 1);
					
				}
				$this->_as = $options['as'];

			} else {

				// Look it up in the formats list
				if (!array_key_exists($options['with'], self::$formats)) {
					throw new \Exception("Unknown format '" . $options['with'] . "' for the FormatValidator.", 1);					
				}

				// Store it
				$this->_format = self::$formats[$options['with']];

				// As?
				if (array_key_exists('as', $options)) {
					$this->_as = $options['as'];
				} else {
					$this->_as = $options['with'];
				}

			}

		}


		protected function _validate($value, $field, $model)
		{

			// Run the regex
			if (preg_match($this->_format, $value)) {
				return true;
			} else {
				return "format." . $this->_as;
			}	



		}

	}

	/**
	 * static $validates = array("name" => 
	 * 				array("length" => 5,	"allowNull" => true),
	 * 				array("length" => "..5"),
	 * 				array("length" => "3..15", "allowBlank" => true),
	 * 				array("length" => "2..")
	 * 				array("length" => array(
	 * 					"minimum" => 2,
	 * 					"maximum" => 5
	 * 				))
	 * 				array("length" => array(
	 * 					"is" => 5
	 * 				)),
	 * 				array("length" => array(
	 * 					"between" => array(1,15)
	 * 				)) 				
	 * 	);
	 */
	class LengthValidator extends Validator
	{

		private $_is;
		private $_min;
		private $_max;

		protected function _setOptions($options)
		{

			// Reset
			$this->_is = null;
			$this->_min = null;
			$this->_max = null;

			// Is it a string?
			if (is_string($options)) {

				// .. in there? (range)
				if (strstr($options, '..') !== false && $options != '..') {

					// Parse it
					preg_match('/^(?<min>[0-9]+)?\.\.(?<max>[0-9]+)?/', $options, $matches);
					if (!empty($matches['min'])) $this->_min = intval($matches['min']);
					if (!empty($matches['max'])) $this->_max = intval($matches['max']);

				} else {

					// Just parse it as a number...
					$this->_is = intval($options);

				}

			// Or a number?
			} elseif (is_numeric($options)) {

				// Store it
				$this->_is = $options;

			} elseif (is_array($options)) {

				// Look for the keys?
				if (array_key_exists("is", $options)) {
					$this->_is = intval($options['is']);
					return;
				} elseif (array_key_exists("maximum", $options) || array_key_exists("minimum", $options)) {

					if (array_key_exists("maximum", $options)) $this->_max = $options['maximum'];
					if (array_key_exists("minimum", $options)) $this->_min = $options['minimum'];					
					return;

				} elseif (array_key_exists("between", $options)) {

					if (!is_array($options['between']) || count($options['between']) != 2) {
						throw new \Exception("The 'between' parameter expects an array with two values, like array(2, 5).", 1);						
					}
					$this->_min = $options['between'][0];
					$this->_max = $options['between'][1];
					return;

				} else {

					// Invalid
					throw new \Exception("The LengthValidator needs at least a 'minimum', 'maximum', 'is', or 'between' parameter.", 1);					

				}


			} else {
				throw new \Exception("Invalid setting for LengthValidator.", 1);
				
			}


		}

		protected function _validate($value, $field, $model)
		{

			// is null?
			if (is_null($value)) {

				// Make it an empty string
				$value = '';

			}

			// Not a string?
			if (!is_string($value)) {
				throw new \Exception("The LengthValidator only works on strings.", 1);				
			}

			// Is 'is' defined?
			if (!is_null($this->_is)) {
				return (strlen($value) != $this->_is) ? "length.is(" . $this->_is . ")" : true;
			}

			// Is 'min' and 'max' defined?
			if (!is_null($this->_min) && !is_null($this->_max)) {

				// Do the range
				return (strlen($value) >= $this->_min && strlen($value) <= $this->_max) ? true : "length.between(" . $this->_min . "," . $this->_max . ")";

			}

			// Only a min?
			if (!is_null($this->_min)) {
				return (strlen($value) >= $this->_min) ? true : "length.min(" . $this->_min . ")";
			}

			// Only a max?
			if (!is_null($this->_max)) {
				return (strlen($value) <= $this->_max) ? true : "length.max(" . $this->_max . ")";
			}

			return true;

		}



	}

	/**
	 * static $validates = array(
	 * 		"accept_terms" => "acceptance",
	 * 		"accept_terms" => array("acceptance" => true),
	 * 		"accept_terms" => array(
	 * 			"acceptance" => "yes"
	 * 		),
	 * 		"accept_terms" => array(
	 * 			"acceptance" => array(
	 * 				"accept" => 1
	 * 			)
	 * 		)
	 * 
	 * )
	 */
	class AcceptanceValidator extends Validator
	{

		public static $defaultAcceptValue = 1;

		private $_accept;

		protected function _setOptions($options)
		{

			// True?
			if ($options === true) {

				// Default accept value
				$this->_accept = self::$defaultAcceptValue;

			} else {

				// An array?
				if (is_array($options)) {

					// Acceptor?
					if (array_key_exists("accept", $options)) {

						// Use that.
						$this->_accept = $options['accept'];

					} else {

						// Default value anyway
						$this->_accept = self::$defaultAcceptValue;

					}

				} else {

					// Just use the value
					$this->_accept = $options;

				}

			}

		}

		protected function _validate($value, $field, $model)
		{

			// Same?
			return $value === $this->_accept ? true : "acceptance";

		}

	}

	/**
	 * static $validates = array(
	 * 		"email" => "confirmation",
	 * 		"email" => array("confirmation"),
	 * 		"email" => array(
	 * 			"confirmation" => true
	 * 		),
	 * 		"email" => array(
	 * 			"confirmation" => "email_confirmation"
	 * 		),
	 * 		"email" => array(
	 * 			"confirmation" => array(
	 * 				"with" => "email_confirmation"
	 * 			)
	 * 		)
	 * 		
	 * );
	 */
	class ConfirmationValidator extends Validator
	{

		private $_field;

		protected function _setOptions($options)
		{
			
			// Just true?
			if ($options === true) {

				// Just add confirmation
				$this->_field = null;
				return;

			}

			// Array?
			if (is_array($options)) {

				// With field?
				if (array_key_exists("with", $options)) {

					// Use that.
					$this->_field = $options['with'];

				} else {

					// Default value anyway
					$this->_field = null;

				}

			} elseif (is_string($options)) {

				// Apply value
				$this->_field = $options;

			}






		}

		protected function _validate($value, $field, $model)
		{

			// Check field name
			if (is_null($this->_field)) {
				$confirmField = $field . "_confirmation";
			} else {
				$confirmField = $this->_field;
			}

			// Get confirmation value
			$confirmValue = $this->getValue($model, $field . "_confirmation");

			// Does that field exist?
			if (is_null($confirmValue)) {

				// Apparently not... That should be checked by a presence validator on the confirm field!
				return true;

			}

			// Compare values
			return ($value == $confirmValue) ? true : "confirmation";

		}

	}

	/**
	 * static $validates = array(
	 * 		"size" => array("inclusion" => "small"),
	 * 		"size" => array("inclusion" => array("small", "medium", "large")),
	 * 		"size" => array(
	 * 			"inclusion" => array(
	 * 				"in" => array("small", "medium", "large")
	 * 			)
	 * 		)
	 * );
	 */
	class InclusionValidator extends Validator
	{


		private $_in;

		protected function _setOptions($options)
		{

			// A string?
			if (is_string($options)) {

				// Just the one option...
				$this->_in = array($options);
				return;

			}

			// Does the array have keys?
			if (\ChickenTools\Arry::isHash($options)) {

				// In given?
				if (!array_key_exists("in", $options)) {
					throw new \Exception("The InclusionValidator needs an 'in' parameter.", 1);					
				}
				$this->_in = $options['in'];

			} else {

				// Use array as values
				$this->_in = $options;

			}



		}

		protected function _validate($value, $field, $model)
		{

			// Value in the array?
			return (in_array($value, $this->_in)) ? true : "inclusion";

		}



	}

	/**
	 * static $validates = array(
	 * 		"points" => "numericality"
	 * 		"points" => array("numericality")
	 * 		"points" => array("numericality" => true)
	 * 		"points" => array(
	 * 			"numericality" => array(
	 * 				"onlyInteger" => true,
	 * 				"greaterThan" => 100,
	 * 				"greaterThanOrEqualTo" => 99,
	 * 				"equalTo" => 10,
	 * 				"lessThan" => 100,
	 * 				"lessThanOrEqualTo" => 99,
	 * 				"odd" => true,
	 * 				"even" => true
	 * 			)
	 * 		)
	 * 		"points" => array(
	 * 			"numericality" => "odd"
	 * 		)
	 * 		"points" => array(
	 * 			"numericality" => "even"
	 * 		)
	 * );
	 */
	class NumericalityValidator extends Validator
	{

		private $_onlyInt;
		private $_gt;
		private $_lt;
		private $_equal;
		private $_gte;
		private $_lte;
		private $_odd;
		private $_even;


		protected function _setOptions($options)
		{

			// Default options
			$this->_onlyInt = false;
			$this->_gt = null;
			$this->_lt = null;
			$this->_equal = null;
			$this->_gte = null;
			$this->_lte = null;
			$this->_odd = null;
			$this->_even = null;

			// Just true?
			if ($options === true) return;

			// String given?
			if (is_string($options)) {

				// Odd?
				if ($options == 'odd') {
					$this->_odd = true;
				} elseif ($options == 'even') {
					$this->_even = true;
				} else {
					throw new \Exception("The NumericalityValidator only accepts the string parameters 'odd' and 'even'.", 1);					
				}
				return;

			}

			// Array?
			if (is_array($options)) {

				// True settings
				if (array_key_exists("onlyInteger", $options) || in_array("onlyInteger", $options)) { $this->_onlyInt = true; }
				if (array_key_exists("odd", $options) || in_array("odd", $options)) { $this->_odd = true; }
				if (array_key_exists("even", $options) || in_array("even", $options)) { $this->_even = true; }
				
				// Greater thans
				if (array_key_exists("equalTo", $options)) { $this->_equal = $options['equalTo']; }
				if (array_key_exists("greaterThan", $options)) { $this->_gt = $options['greaterThan']; }
				if (array_key_exists("greaterThanOrEqualTo", $options)) { $this->_gte = $options['greaterThanOrEqualTo']; }
				if (array_key_exists("lessThan", $options)) { $this->_lt = $options['lessThan']; }
				if (array_key_exists("lessThanOrEqualTo", $options)) { $this->_lte = $options['lessThanOrEqualTo']; }

				return;

			}
			throw new \Exception("Invalid setting of the NumericalityValidator", 1);

		}

		protected function _validate($value, $field, $model)
		{

			// Is it a number?
			if (!is_numeric($value)) {
				return "numericality.numeric";
			}

			// Just int?
			if ($this->_onlyInt && intval($value) != $value) {
				return "numericality.integer";
			}

			// Greater than?
			if ($this->_gt && $value <= $this->_gt) return "numericality.greaterThan(" . $this->_gt . ")";
			if ($this->_gte && $value < $this->_gte) return "numericality.greaterThanOrEqualTo(" . $this->_gte . ")";

			// Less than?
			if ($this->_lt && $value >= $this->_lt) return "numericality.lessThan(" . $this->_lt . ")";
			if ($this->_lte && $value > $this->_lte) return "numericality.lessThanOrEqualTo(" . $this->_lte . ")";
		
			// Equal?
			if ($this->_equal && $value != $this->_equal) return "numericality.equalTo(" . $this->_equal . ")";

			// Odd?
			if ($this->_odd && $value % 2 != 1) {
				return "numericality.odd";
			}

			// Even?
			if ($this->_even && $value % 2 != 0) {
				return "numericality.even";
			}

			// No faults found
			return true;

		}



	}


	/**
	 * Empty validator
	 *
	class ...Validator extends Validator
	{


		protected function _setOptions($options)
		{

		}

		protected function _validate($value, $field, $model)
		{



		}



	}
	 **/



?>