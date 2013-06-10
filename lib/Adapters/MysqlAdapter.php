<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;

/**
 * Adapter for MySQL.
 *
 * @package ActiveRecord
 */
class MysqlAdapter extends Connection
{
	static $DEFAULT_PORT = 3306;

	public function limit($sql, $offset, $limit)
	{
		$offset = is_null($offset) ? '' : intval($offset) . ',';
		$limit = intval($limit);
		return "$sql LIMIT {$offset}$limit";
	}

	public function queryColumnInfo($table)
	{
		return $this->query("SHOW COLUMNS FROM $table");
	}

	public function queryForTables()
	{
		return $this->query('SHOW TABLES');
	}

	public function createColumn(&$column)
	{
		$c = new Column();
		$c->inflectedName	= Inflector::instance()->variablize($column['field']);
		$c->name			= $column['field'];
		$c->nullable		= ($column['null'] === 'YES' ? true : false);
		$c->pk				= ($column['key'] === 'PRI' ? true : false);
		$c->autoIncrement	= ($column['extra'] === 'auto_increment' ? true : false);

		if ($column['type'] == 'timestamp' || $column['type'] == 'datetime')
		{
			$c->rawType = 'datetime';
			$c->length = 19;
		}
		elseif ($column['type'] == 'date')
		{
			$c->rawType = 'date';
			$c->length = 10;
		}
		elseif ($column['type'] == 'time')
		{
			$c->rawType = 'time';
			$c->length = 8;
		}
		else
		{
			preg_match('/^([A-Za-z0-9_]+)(\(([0-9]+(,[0-9]+)?)\))?/', $column['type'], $matches);

			$c->rawType = (count($matches) > 0 ? $matches[1] : $column['type']);

			if (count($matches) >= 4)
				$c->length = intval($matches[3]);
		}

		$c->mapRawType();
		$c->default = $c->cast($column['default'], $this);

		return $c;
	}

	public function setEncoding($charset)
	{
		$params = array($charset);
		$this->query('SET NAMES ?', $params);		
	}

	public function acceptsLimitAndOrderForUpdateAndDelete() { return true; }

	public function nativeDatabaseTypes()
	{
		return array(
			'primary_key' => 'int(11) UNSIGNED DEFAULT NULL auto_increment PRIMARY KEY',
			'string' => array('name' => 'varchar', 'length' => 255),
			'text' => array('name' => 'text'),
			'integer' => array('name' => 'int', 'length' => 11),
			'float' => array('name' => 'float'),
			'datetime' => array('name' => 'datetime'),
			'timestamp' => array('name' => 'datetime'),
			'time' => array('name' => 'time'),
			'date' => array('name' => 'date'),
			'binary' => array('name' => 'blob'),
			'boolean' => array('name' => 'tinyint', 'length' => 1)
		);
	}

}
?>
