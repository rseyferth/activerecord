<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;
use Closure;

/**
 * Manages configuration options for ActiveRecord.
 *
 * <code>
 * ActiveRecord::initialize(function($cfg) {
 *   $cfg->set_model_home('models');
 *   $cfg->set_connections(array(
 *     'development' => 'mysql://user:pass@development.com/awesome_development',
 *     'production' => 'mysql://user:pass@production.com/awesome_production'));
 * });
 * </code>
 *
 * @package ActiveRecord
 */
class Config extends Singleton
{
	/**
	 * Name of the connection to use by default.
	 *
	 * <code>
	 * ActiveRecord\Config::initialize(function($cfg) {
	 *   $cfg->setModelDirectory('/your/app/models');
	 *   $cfg->setConnections(array(
	 *     'development' => 'mysql://user:pass@development.com/awesome_development',
	 *     'production' => 'mysql://user:pass@production.com/awesome_production'));
	 * });
	 * </code>
	 *
	 * This is a singleton class so you can retrieve the {@link Singleton} instance by doing:
	 *
	 * <code>
	 * $config = ActiveRecord\Config::instance();
	 * </code>
	 *
	 * @var string
	 */
	private $_defaultConnection = 'development';

	/**
	 * Contains the list of database connection strings.
	 *
	 * @var array
	 */
	private $_connections = array();

	/**
	 * Directory for the auto_loading of model classes.
	 *
	 * @see activerecord_autoload
	 * @var string
	 */
	private $_modelDirectory;

	/**
	 * Switch for logging.
	 *
	 * @var bool
	 */
	private $_logging = false;

	/**
	 * Contains a Logger object that must impelement a log() method.
	 *
	 * @var object
	 */
	private $_logger;

	/**
	 * The format to serialize DateTime values into.
	 *
	 * @var string
	 */
	private $_dateFormat = \DateTime::ISO8601;

	/**
	 * Allows config initialization using a closure.
	 *
	 * This method is just syntatic sugar.
	 *
	 * <code>
	 * ActiveRecord\Config::initialize(function($cfg) {
	 *   $cfg->setModelDirectory('/path/to/your/model_directory');
	 *   $cfg->setConnections(array(
	 *     'development' => 'mysql://username:password@127.0.0.1/database_name'));
	 * });
	 * </code>
	 *
	 * You can also initialize by grabbing the singleton object:
	 *
	 * <code>
	 * $cfg = ActiveRecord\Config::instance();
	 * $cfg->setModelDirectory('/path/to/your/model_directory');
	 * $cfg->setConnections(array('development' =>
  	 *   'mysql://username:password@localhost/database_name'));
	 * </code>
	 *
	 * @param Closure $initializer A closure
	 * @return void
	 */
	public static function initialize(Closure $initializer)
	{
		$initializer(parent::instance());
	}

	/**
	 * Sets the list of database connection strings.
	 *
	 * <code>
	 * $config->setConnections(array(
	 *     'development' => 'mysql://username:password@127.0.0.1/database_name'));
	 * </code>
	 *
	 * @param array 	Array of connections
	 * @param string 	Optionally specify the default_connection
	 * @return void
	 * @throws ActiveRecord\ConfigException
	 */
	public function setConnections($connections, $defaultConnection=null)
	{
		if (!is_array($connections)) {
			throw new ConfigException("Connections must be an array");
		}

		if ($defaultConnection) {
			$this->setDefaultConnection($defaultConnection);
		}

		// Store it!
		$this->_connections = $connections;

	}

	/**
	 * Returns the connection strings array.
	 *
	 * @return array
	 */
	public function getConnections()
	{
		return $this->_connections;
	}

	/**
	 * Returns a connection string if found otherwise null.
	 *
	 * @param string $name Name of connection to retrieve
	 * @return string connection info for specified connection name
	 */
	public function getConnection($name)
	{
		if (array_key_exists($name, $this->_connections))
			return $this->_connections[$name];

		return null;
	}

	/**
	 * Returns the default connection string or null if there is none.
	 *
	 * @return string
	 */
	public function getDefaultConnectionString()
	{
		return array_key_exists($this->_defaultConnection, $this->_connections) ?
			$this->_connections[$this->_defaultConnection] : null;
	}

	/**
	 * Returns the name of the default connection.
	 *
	 * @return string
	 */
	public function getDefaultConnection()
	{
		return $this->_defaultConnection;
	}

	/**
	 * Set the name of the default connection.
	 *
	 * @param string $name Name of a connection in the connections array
	 * @return void
	 */
	public function setDefaultConnection($name)
	{
		$this->_defaultConnection = $name;
	}

	/**
	 * Sets the directory where models are located.
	 *
	 * @param string $dir Directory path containing your models
	 * @return void
	 */
	public function setModelDirectory($dir)
	{
		$this->_modelDirectory = $dir;
	}

	/**
	 * Returns the model directory.
	 *
	 * @return string
	 * @throws ConfigException if specified directory was not found
	 */
	public function getModelDirectory()
	{
		if ($this->_modelDirectory && !file_exists($this->_modelDirectory))
			throw new ConfigException('Invalid or non-existent directory: '.$this->_modelDirectory);

		return $this->_modelDirectory;
	}

	/**
	 * Turn on/off logging
	 *
	 * @param boolean $bool
	 * @return void
	 */
	public function setLogging($bool)
	{
		$this->_logging = (bool)$bool;
	}

	/**
	 * Sets the logger object for future SQL logging
	 *
	 * @param object $logger
	 * @return void
	 * @throws ConfigException if Logger objecct does not implement public log()
	 */
	public function setLogger($logger)
	{

		// A monolog?
		if (!is_subclass_of($logger, "\\Psr\\Log\\LoggerInterface")) {
			throw new ConfigException("Logger objects must implement the Psr LoggerInterface.");
		}

		
		$this->_logger = $logger;
	}

	/**
	 * Return whether or not logging is on
	 *
	 * @return boolean
	 */
	public function getLogging()
	{
		return $this->_logging;
	}

	/**
	 * Returns the logger
	 *
	 * @return object
	 */
	public function getLogger()
	{
		return $this->_logger;
	}

	/**
	 * Sets the url for the cache server to enable query caching.
	 *
	 * Only table schema queries are cached at the moment. A general query cache
	 * will follow.
	 *
	 * Example:
	 *
	 * <code>
	 * $config->setCache("memcached://localhost");
	 * $config->setCache("memcached://localhost",array("expire" => 60));
	 * </code>
	 *
	 * @param string $url Url to your cache server.
	 * @param array $options Array of options
	 */
	public function setCache($url, $options=array())
	{
		Cache::initialize($url,$options);
	}
};
?>
