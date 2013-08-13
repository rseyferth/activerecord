<?php

	// Check minimum PHP version
	if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300) {
		die('PHP ActiveRecord requires PHP 5.3 or higher');
	}
	define('PHP_ACTIVERECORD_VERSION_ID','1.0');

	// Whether to prepend the autoloader
	if (!defined('PHP_ACTIVERECORD_AUTOLOAD_PREPEND')) {
		define('PHP_ACTIVERECORD_AUTOLOAD_PREPEND',true);
	}

	// Load main libraries
	require __DIR__.'/lib/Singleton.php';
	require __DIR__.'/lib/Config.php';
	require __DIR__.'/lib/Utils.php';
	require __DIR__.'/lib/DateTime.php';
	require __DIR__.'/lib/Model.php';
	require __DIR__.'/lib/Table.php';
	require __DIR__.'/lib/ConnectionManager.php';
	require __DIR__.'/lib/Connection.php';
	require __DIR__.'/lib/SQLBuilder.php';
	require __DIR__.'/lib/Reflections.php';
	require __DIR__.'/lib/Inflector.php';
	require __DIR__.'/lib/CallBack.php';
	require __DIR__.'/lib/Exceptions.php';
	require __DIR__.'/lib/Cache.php';

	// Add autoload function (if not disabled)
	if (!defined('PHP_ACTIVERECORD_AUTOLOAD_DISABLE')) {
		spl_autoload_register('activerecordAutoload',false,PHP_ACTIVERECORD_AUTOLOAD_PREPEND);
	}

	/**
	 * ActiveRecord autoloading function
	 * @param  string  The class name to lookup
	 * @return void
	 */
	function activerecordAutoload($className)
	{

		// Get ActiveRecord model path
		$path = ActiveRecord\Config::instance()->getModelDirectory();
		$root = realpath(isset($path) ? $path : '.');


		if (strstr($className, "\\") && ($namespaces = explode('\\', $className)))
		{
			$className = array_pop($namespaces);
			$directories = array();

			foreach ($namespaces as $directory)
				$directories[] = $directory;

			$root .= DIRECTORY_SEPARATOR . implode($directories, DIRECTORY_SEPARATOR);
		}

		$file = "$root/$className.php";

		if (file_exists($file))	{
			require_once $file;
		}

	}
?>
