<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;
use Closure;

/**
 * Callbacks allow the programmer to hook into the life cycle of a {@link Model}.
 *
 * You can control the state of your object by declaring certain methods to be
 * called before or after methods are invoked on your object inside of ActiveRecord.
 *
 * Valid callbacks are:
 * <ul>
 * <li><b>afterConstruct:</b> called after a model has been constructed</li>
 * <li><b>beforeSave:</b> called before a model is saved</li>
 * <li><b>afterSave:</b> called after a model is saved</li>
 * <li><b>beforeCreate:</b> called before a NEW model is to be inserted into the database</li>
 * <li><b>afterCreate:</b> called after a NEW model has been inserted into the database</li>
 * <li><b>beforeUpdate:</b> called before an existing model has been saved</li>
 * <li><b>afterUpdate:</b> called after an existing model has been saved</li>
 * <li><b>beforeValidation:</b> called before running validators</li>
 * <li><b>afterValidation:</b> called after running validators</li>
 * <li><b>beforeValidation_on_create:</b> called before validation on a NEW model being inserted</li>
 * <li><b>afterValidation_on_create:</b> called after validation on a NEW model being inserted</li>
 * <li><b>beforeValidation_on_update:</b> see above except for an existing model being saved</li>
 * <li><b>afterValidation_on_update:</b> ...</li>
 * <li><b>beforeDestroy:</b> called after a model has been deleted</li>
 * <li><b>afterDestroy:</b> called after a model has been deleted</li>
 * </ul>
 *
 * This class isn't meant to be used directly. Callbacks are defined on your model like the example below:
 *
 * <code>
 * class Person extends ActiveRecord\Model {
 *   static $beforeSave = array('makeNameUppercase');
 *   static $afterSave = array('doHappyDance');
 *
 *   public function makeNameUppercase() {
 *     $this->name = strtoupper($this->name);
 *   }
 *
 *   public function doHappyDance() {
 *     happyDance();
 *   }
 * }
 * </code>
 *
 * Available options for callbacks:
 *
 * <ul>
 * <li><b>prepend:</b> puts the callback at the top of the callback chain instead of the bottom</li>
 * </ul>
 *
 * @package ActiveRecord
 * @link http://www.phpactiverecord.org/guides/callbacks
 */
class CallBack
{
	/**
	 * List of available callbacks.
	 *
	 * @var array
	 */
	static protected $validCallbacks = array(
		'afterConstruct',
		'beforeSave',
		'afterSave',
		'beforeCreate',
		'afterCreate',
		'beforeUpdate',
		'afterUpdate',
		'beforeValidation',
		'afterValidation',
		'beforeValidationOnCreate',
		'afterValidationOnCreate',
		'beforeValidationOnUpdate',
		'afterValidationOnUpdate',
		'beforeDestroy',
		'afterDestroy'
	);

	/**
	 * Container for reflection class of given model
	 *
	 * @var object
	 */
	private $klass;

	/**
	 * List of public methods of the given model
	 * @var array
	 */
	private $publicMethods;

	/**
	 * Holds data for registered callbacks.
	 *
	 * @var array
	 */
	private $registry = array();

	/**
	 * Creates a CallBack.
	 *
	 * @param string 	The name of a {@link Model} class
	 * @return CallBack
	 */
	public function __construct($modelClassName)
	{

		// Lookup the model class
		$this->klass = Reflections::instance()->get($modelClassName);

		// Loop through valid callbacks
		foreach (static::$validCallbacks as $name)
		{
			// look for explicitly defined static callback
			if (($definition = $this->klass->getStaticPropertyValue($name,null))) {

				// Wrapped in array?
				if (!is_array($definition))	$definition = array($definition);

				// Loop through definitions and register the callback
				foreach ($definition as $method_name) {
					$this->register($name, $method_name);
				}

			}

			// implicit callbacks that don't need to have a static definition
			// simply define a method named the same as something in $VALID_CALLBACKS
			// and the callback is auto-registered
			elseif ($this->klass->hasMethod($name)) {
				$this->register($name, $name);
			}
		}
	}

	/**
	 * Returns all the callbacks registered for a callback type.
	 *
	 * @param string 	Name of a callback (see $validCallbacks)
	 * @return array 	Array of callbacks or null if invalid callback name.
	 */
	public function getCallbacks($name)
	{
		return isset($this->registry[$name]) ? $this->registry[$name] : null;
	}

	/**
	 * Invokes a callback.
	 *
	 * @internal This is the only piece of the CallBack class that carries its own logic for the
	 * model object. For (after|before)_(create|update) callbacks, it will merge with
	 * a generic 'save' callback which is called first for the lease amount of precision.
	 *
	 * @param string 	Model to invoke the callback on.
	 * @param string  	Name of the callback to invoke
	 * @param boolean  	Set to true to raise an exception if the callback does not exist.
	 * @return mixed null if $name was not a valid callback type or false if a method was invoked
	 * that was for a before_* callback and that method returned false. If this happens, execution
	 * of any other callbacks after the offending callback will not occur.
	 */
	public function invoke($model, $name, $mustExist=true)
	{

		// Check if callback exists
		if ($mustExist && !array_key_exists($name, $this->registry)) {
			throw new ActiveRecordException("No callbacks were defined for: $name on " . get_class($model));
		}

		// if it doesn't exist it might be a /(after|before)_(create|update)/ so we still need to run the Save callback
		if (!array_key_exists($name, $this->registry)) {
			$registry = array();
		} else {
			$registry = $this->registry[$name];
		}

		//@TODO: Make this work with the new names, and a preg_match...
		//
		// starts with /(after|before)_(create|update)/
		// 
		if (preg_match('/^(?<when>after|before)(?<action>Create|Update)$/', $name, $matches))
		{

			// Replace action with Save
			$temporalSave = $matches['when'] . "Save";

			if (!isset($this->registry[$temporalSave]))
				$this->registry[$temporalSave] = array();

			$registry = array_merge($this->registry[$temporalSave], $registry ? $registry : array());
		}
		

		if ($registry)
		{
			foreach ($registry as $method)
			{
				$ret = ($method instanceof Closure ? $method($model) : $model->$method());

				if (false === $ret && $first === 'before')
					return false;
			}
		}
		return true;
	}

	/**
	 * Register a new callback.
	 *
	 * The option array can contain the following parameters:
	 * <ul>
	 * <li><b>prepend:</b> Add this callback at the beginning of the existing callbacks (true) or at the end (false, default)</li>
	 * </ul>
	 *
	 * @param string  	Name of callback type (see $validCallbacks)
	 * @param mixed  	Either a closure or the name of a method on the {@link Model}
	 * @param array  	Options array
	 * @return void
	 * @throws ActiveRecordException if invalid callback type or callback method was not found
	 */
	public function register($name, $closure = null, $options = array())
	{

		// Set default options
		$options = array_merge(array(
			'prepend' => false)
		, $options);

		// No method given?
		if (!$closure) {

			// Use the name instead
			$closure = $name;
		}

		// Not valid?
		if (!in_array($name,self::$validCallbacks)) {
			throw new ActiveRecordException("Invalid callback: $name");
		}

		// Is it a method?
		if (!($closure instanceof Closure))
		{

			// Load class its public methods
			if (!isset($this->publicMethods)) {
				$this->publicMethods = get_class_methods($this->klass->getName());
			}

			// Is there no public method by that name?
			if (!in_array($closure, $this->publicMethods)) {

				// Was the method private?
				if ($this->klass->hasMethod($closure)) {

					// Method is private or protected
					throw new ActiveRecordException("Callback methods need to be public (or anonymous closures). " .
						"Please change the visibility of " . $this->klass->getName() . "->" . $closure . "()");

				} else {

					// Just a non-existing method				
					throw new ActiveRecordException("Unknown method for callback: $name" .
						(is_string($closure) ? ": #$closure" : ""));

				}
			}
		}

		// Add method/closure to the registry
		if (!isset($this->registry[$name])) {
			$this->registry[$name] = array();
		}
		if ($options['prepend']) {
			array_unshift($this->registry[$name], $closure);
		} else {
			$this->registry[$name][] = $closure;
		}
	}
}
?>
