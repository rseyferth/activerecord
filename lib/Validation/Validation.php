<?php

	namespace ActiveRecord\Validation;

	class Validation
	{

		private static $_registeredValidators = array(
			"presence" => "PresenceValidator",
			"format" => "FormatValidator",
			"length" => "LengthValidator",
			"acceptance" => "AcceptanceValidator",
			"uniqueness" => "UniquenessValidator",
			"confirmation" => "ConfirmationValidator",
			"inclusion" => "InclusionValidator"
		);

		// Array of Validator instances
		private static $_validators = array();


		private $_map;

		

		public function __construct()
		{

			// Create empty map
			$this->_map = array();
			
		}


		
		public function validate($model)
		{

			// Anything in the map?
			if (sizeof($this->_map) == 0) return new ValidationResult(true);

			// Make sure validators are loaded
			require_once('Validators.php');

			// Errors
			$errors = array();

			// Loop through the map
			foreach ($this->_map as $field => $validations) {

				// Do we have an instance for this validator?
				foreach ($validations as $validation) {
					
					// Check if validator was instantiated
					if (!array_key_exists($validation['validator'], self::$_validators)) {

						// Check if namespaced
						$valClass = $validation['validator'];
						if (strpos($valClass, '\\') === false) {
							$valClass = "\\ActiveRecord\\Validation\\" . $valClass;
						}

						// Try to create one
						$validator = new $valClass();
						self::$_validators[$validation['validator']] = $validator;

					} else {

						// Get the validator instance
						$validator = self::$_validators[$validation['validator']];

					}

					// Set options
					$validator->setOptions($validation['options']);

					// Validate it
					$result = $validator->validate($field, $model);

					// Not good?
					if ($result !== true) {

						// Add error
						if (!array_key_exists($field, $errors)) {
							$errors[$field] = array();
						}

						// Already in there?
						if (!in_array($result, $errors[$field])) {
							$errors[$field][] = $result;
						}

					}

				}

			}

			// Create the result
			$result = new ValidationResult(count($errors) == 0, $errors);
			return $result;
			

		}

		
		private function _parseOptions($field, $options)
		{

			// Already present in the map?
			if (!array_key_exists($field, $this->_map)) {
				$this->_map[$field] = array();
			}

			// Is the options param a string!?
			if (is_string($options)) {

				// Use the string as a key for the options array
				$options = array($options => true);

			}

			// Is it not an array?
			if (!is_array($options)) {
				throw new \Exception("Invalid validation options.", 1);				
			}

			// Loop through options
			foreach ($options as $type => $config) {

				// Is the key numeric?
				if (is_numeric($type)) {

					// Then the value must be the type
					$type = $config;
					$config = true;

				}

				// Lookup the type in my validators
				if (!array_key_exists($type, self::$_registeredValidators)) {
					throw new \Exception("Unknown validator '$type'. Use Validation::register() to add a validator.", 1);					
				}

				// Is it false..?
				if ($config === false) continue;

				// Add it
				$this->_map[$field][] = array("validator" => self::$_registeredValidators[$type], "options" => $config);

			}

		}


		static function onModel($class)
		{

			// Create new instance
			$validation = new Validation();

			// Check if it's already a reflection
			if (is_string($class)) {
				$class = new \ReflectionClass($class);
			} elseif ($class instanceof \ReflectionClass) {
				$class = $class;
			} else {
				throw new \Exception("The class parameter must either be a string (containing the full class name) or a ReflectionClass instance.", 1);				
			}

			// Get static configurators
			$validates = $class->getStaticPropertyValue("validates");
			
			// Loop through validations
			foreach ($validates as $field => $options) {

				// Split on ,'s
				$fields = preg_split('/,([\s|\t|\n|\r]+)?/', $field);
				
				// Loop through all fields
				foreach ($fields as $field) {
					$validation->_parseOptions($field, $options);
				}


			}

			// Done
			return $validation;

		}




	}


	class ValidationResult
	{

		public $success;
		public $errors;

		public function __construct($success = true, $errors = array())
		{

			// Localize
			$this->success = $success;
			$this->errors = $errors;

		}


	}


?>