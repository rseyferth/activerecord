<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;

use ChickenTools\Arry;

/**
 * Manages reading and writing to a database table.
 *
 * This class manages a database table and is used by the Model class for
 * reading and writing to its database table. There is one instance of Table
 * for every table you have a model for.
 *
 * @package ActiveRecord
 */
class Table
{
	private static $cache = array();

	public $class;
	public $conn;
	public $pk;
	public $lastSql;

	// Name/value pairs of columns in this table
	public $columns = array();

	/**
	 * Name of the table.
	 */
	public $table;

	/**
	 * Name of the database (optional)
	 */
	public $dbName;

	/**
	 * Name of the sequence for this table (optional). Defaults to {$table}_seq
	 */
	public $sequence;

	/**
	 * A instance of CallBack for this model/table
	 * @static
	 * @var object ActiveRecord\CallBack
	 */
	public $callback;

	/**
	 * List of relationships for this table.
	 */
	private $_relationships = array();

	public static function load($modelClassName) {
		
		if (!isset(self::$cache[$modelClassName]))
		{
			/* do not place set_assoc in constructor..it will lead to infinite loop due to
			   relationships requesting the model's table, but the cache hasn't been set yet */
			self::$cache[$modelClassName] = new Table($modelClassName);
			self::$cache[$modelClassName]->setAssociations();
		}

		return self::$cache[$modelClassName];
	}

	public static function clearCache($modelClassName = null)
	{
		if ($modelClassName && array_key_exists($modelClassName,self::$cache))
			unset(self::$cache[$modelClassName]);
		else
			self::$cache = array();
	}

	public function __construct($className)
	{
		$this->class = Reflections::instance()->add($className)->get($className);

		$this->reestablishConnection(false);
		$this->setTableName();
		$this->getMetaData();
		$this->setPrimaryKey();
		$this->setSequenceName();
		$this->setDelegates();

		$this->callback = new CallBack($className);
		$this->callback->register('beforeSave', function(Model $model) { $model->setTimestamps(); }, array('prepend' => true));
		$this->callback->register('afterSave', function(Model $model) { $model->resetDirty(); }, array('prepend' => true));
	}

	public function reestablishConnection($close = true)
	{
		// if connection name property is null the connection manager will use the default connection
		$connection = $this->class->getStaticPropertyValue('connection',null);

		if ($close)	{
			ConnectionManager::dropConnection($connection);
			static::clearCache();
		}
		return ($this->conn = ConnectionManager::getConnection($connection));
	}

	public function createJoins($joins)
	{
		if (!is_array($joins))
			return $joins;

		$self = $this->table;
		$ret = $space = '';

		$existingTables = array();
		foreach ($joins as $value)
		{
			$ret .= $space;

			if (stripos($value,'JOIN ') === false)
			{
				if (array_key_exists($value, $this->_relationships))
				{
					$rel = $this->getRelationship($value);

					// if there is more than 1 join for a given table we need to alias the table names
					if (array_key_exists($rel->className, $existingTables))
					{
						$alias = $value;
						$existingTables[$rel->className]++;
					}
					else
					{
						$existingTables[$rel->className] = true;
						$alias = null;
					}

					$ret .= $rel->constructInnerJoinSql($this, false, $alias);
				}
				else
					throw new RelationshipException("Relationship named $value has not been declared for class: {$this->class->getName()}");
			}
			else
				$ret .= $value;

			$space = ' ';
		}
		return $ret;
	}

	public function optionsToSql($options)
	{
		$table = array_key_exists('from', $options) ? $options['from'] : $this->getFullyQualifiedTableName();
		$sql = new SQLBuilder($this->conn, $table);

		if (array_key_exists('joins',$options))
		{
			$sql->joins($this->createJoins($options['joins']));

			// by default, an inner join will not fetch the fields from the joined table
			if (!array_key_exists('select', $options))
				$options['select'] = $this->getFullyQualifiedTableName() . '.*';
		}

		if (array_key_exists('select',$options))
			$sql->select($options['select']);

		if (array_key_exists('conditions',$options))
		{
			if (!Arry::isHash($options['conditions']))
			{
				if (is_string($options['conditions']))
					$options['conditions'] = array($options['conditions']);

				call_user_func_array(array($sql,'where'),$options['conditions']);
			}
			else
			{
				if (!empty($options['mappedNames']))
					$options['conditions'] = $this->mapNames($options['conditions'],$options['mapped_names']);

				$sql->where($options['conditions']);
			}
		}

		if (array_key_exists('order',$options))
			$sql->order($options['order']);

		if (array_key_exists('limit',$options))
			$sql->limit($options['limit']);

		if (array_key_exists('offset',$options))
			$sql->offset($options['offset']);

		if (array_key_exists('group',$options))
			$sql->group($options['group']);

		if (array_key_exists('having',$options))
			$sql->having($options['having']);

		return $sql;
	}

	public function find($options)
	{
		$sql = $this->optionsToSql($options);
		$readonly = (array_key_exists('readonly',$options) && $options['readonly']) ? true : false;
		$eagerLoad = array_key_exists('include',$options) ? $options['include'] : null;

		return $this->findBySql($sql->toString(), $sql->getWhereValues(), $readonly, $eagerLoad);
	}

	public function findBySql($sql, $values = null, $readonly = false, $includes = null)
	{
		$this->lastSql = $sql;

		$collectAttrsForIncludes = is_null($includes) ? false : true;
		$list = $attrs = array();
		$sth = $this->conn->query($sql, $this->processData($values));

		while (($row = $sth->fetch()))
		{
			$model = new $this->class->name($row, false, true, false);

			if ($readonly) $model->readonly();

			if ($collectAttrsForIncludes)
				$attrs[] = $model->attributes();

			$list[] = $model;
		}

		if ($collectAttrsForIncludes && !empty($list))
			$this->executeEagerLoad($list, $attrs, $includes);

		return $list;
	}

	/**
	 * Executes an eager load of a given named relationship for this table.
	 *
	 * @param $models array found modesl for this table
	 * @param $attrs array of attrs from $models
	 * @param $includes array eager load directives
	 * @return void
	 */
	private function executeEagerLoad($models = array(), $attrs = array(), $includes = array())
	{
		if (!is_array($includes))
			$includes = array($includes);

		foreach ($includes as $index => $name)
		{
			// nested include
			if (is_array($name))
			{
				$nestedIncludes = count($name) > 0 ? $name : $name[0];
				$name = $index;
			}
			else
				$nestedIncludes = array();

			$rel = $this->getRelationship($name, true);
			$rel->loadEagerly($models, $attrs, $nestedIncludes, $this);
		}
	}

	public function getColumnByInflectedName($inflectedName)
	{
		foreach ($this->columns as $rawName => $column) {
			if ($column->inflectedName == $inflectedName) return $column;
		}
		return null;
	}

	public function getFullyQualifiedTableName($quoteName = true)
	{
		$table = $quoteName ? $this->conn->quoteName($this->table) : $this->table;

		if ($this->dbName)
			$table = $this->conn->quoteName($this->dbName) . ".$table";

		return $table;
	}

	/**
	 * Retrieve a relationship object for this table. Strict as true will throw an error
	 * if the relationship name does not exist.
	 *
	 * @param $name string name of Relationship
	 * @param $strict bool
	 * @throws RelationshipException
	 * @return Relationship or null
	 */
	public function getRelationship($name, $strict = false)
	{
		if ($this->hasRelationship($name))
			return $this->_relationships[$name];

		if ($strict)
			throw new RelationshipException("Relationship named $name has not been declared for class: {$this->class->getName()}");

		return null;
	}

	/**
	 * Does a given relationship exist?
	 *
	 * @param $name string name of Relationship
	 * @return bool
	 */
	public function hasRelationship($name)
	{
		return array_key_exists($name, $this->_relationships);
	}

	public function insert(&$data, $pk = null, $sequenceName=null)
	{
		$data = $this->processData($data);

		$sql = new SQLBuilder($this->conn, $this->getFullyQualifiedTableName());
		$sql->insert($data, $pk, $sequence_name);

		$values = array_values($data);
		return $this->conn->query(($this->lastSql = $sql->toString()), $values);
	}

	public function update(&$data, $where)
	{
		$data = $this->processData($data);

		$sql = new SQLBuilder($this->conn, $this->getFullyQualifiedTableName());
		$sql->update($data)->where($where);

		$values = $sql->bindValues();
		return $this->conn->query(($this->lastSql = $sql->toString()), $values);
	}

	public function delete($data)
	{
		$data = $this->processData($data);

		$sql = new SQLBuilder($this->conn, $this->getFullyQualifiedTableName());
		$sql->delete($data);

		$values = $sql->bindValues();
		return $this->conn->query(($this->lastSql = $sql->toString()), $values);
	}

	/**
	 * Add a relationship.
	 *
	 * @param Relationship $relationship a Relationship object
	 */
	private function addRelationship($relationship)
	{
		$this->_relationships[$relationship->attributeName] = $relationship;
	}

	private function getMetaData()
	{
		// as more adapters are added probably want to do this a better way
		// than using instanceof but gud enuff for now
		$quoteName = !($this->conn instanceof PgsqlAdapter);

		$tableName = $this->getFullyQualifiedTableName($quoteName);
		$conn = $this->conn;
		$this->columns = Cache::get("getMetaData-$tableName", function() use ($conn, $tableName) { return $conn->columns($tableName); });
	}

	/**
	 * Replaces any aliases used in a hash based condition.
	 *
	 * @param $hash array A hash
	 * @param $map array Hash of used_name => real_name
	 * @return array Array with any aliases replaced with their read field name
	 */
	private function mapNames(&$hash, &$map)
	{
		$ret = array();

		foreach ($hash as $name => &$value)
		{
			if (array_key_exists($name,$map))
				$name = $map[$name];

			$ret[$name] = $value;
		}
		return $ret;
	}

	private function &processData($hash)
	{
		if (!$hash)	return $hash;

		foreach ($hash as $name => &$value) {
			if ($value instanceof \DateTime) {
				if (isset($this->columns[$name]) && $this->columns[$name]->type == Column::DATE) {
					$hash[$name] = $this->conn->dateToString($value);
				} else {
					$hash[$name] = $this->conn->dateTimeToString($value);
				}
			}
			else
				$hash[$name] = $value;
		}
		return $hash;
	}

	private function setPrimaryKey()
	{
		if (($pk = $this->class->getStaticPropertyValue('pk',null)) || ($pk = $this->class->getStaticPropertyValue('primaryKey',null)))
			$this->pk = is_array($pk) ? $pk : array($pk);
		else
		{
			$this->pk = array();

			foreach ($this->columns as $c)
			{
				if ($c->pk)
					$this->pk[] = $c->inflectedName;
			}
		}
	}

	private function setTableName()
	{
		if (($table = $this->class->getStaticPropertyValue('table',null)) || ($table = $this->class->getStaticPropertyValue('tableName',null)))
			$this->table = $table;
		else
		{
			// infer table name from the class name
			$this->table = Inflector::instance()->tableize($this->class->getName());

			// strip namespaces from the table name if any
			$parts = explode('\\',$this->table);
			$this->table = $parts[count($parts)-1];
		}

		if(($db = $this->class->getStaticPropertyValue('db',null)) || ($db = $this->class->getStaticPropertyValue('dbName',null)))
			$this->dbName = $db;
	}

	private function setSequenceName()
	{
		if (!$this->conn->supportsSequences())
			return;

		if (!($this->sequence = $this->class->getStaticPropertyValue('sequence')))
			$this->sequence = $this->conn->getSequenceName($this->table, $this->pk[0]);
	}

	private function setAssociations()
	{
		require_once 'Relationship.php';
		$namespace = $this->class->getNamespaceName();

		foreach ($this->class->getStaticProperties() as $name => $definitions)
		{
			if (!$definitions)# || !is_array($definitions))
				continue;

			foreach (Arry::wrapStringsInArrays($definitions) as $definition)
			{
				$relationship = null;
				$definition += compact('namespace');

				switch ($name)
				{
					case 'hasMany':
						$relationship = new HasMany($definition);
						break;

					case 'hasOne':
						$relationship = new HasOne($definition);
						break;

					case 'belongsTo':
						$relationship = new BelongsTo($definition);
						break;

					case 'hasAndBelongsToMany':
						$relationship = new HasAndBelongsToMany($definition);
						break;
				}

				if ($relationship)
					$this->addRelationship($relationship);
			}
		}
	}

	/**
	 * Rebuild the delegates array into format that we can more easily work with in Model.
	 * Will end up consisting of array of:
	 *
	 * array('delegate' => array('field1','field2',...),
	 *       'to'       => 'delegate_to_relationship',
	 *       'prefix'	=> 'prefix')
	 */
	private function setDelegates()
	{
		$delegates = $this->class->getStaticPropertyValue('delegate',array());
		$new = array();

		if (!array_key_exists('processed', $delegates))
			$delegates['processed'] = false;

		if (!empty($delegates) && !$delegates['processed'])
		{
			foreach ($delegates as &$delegate)
			{
				if (!is_array($delegate) || !isset($delegate['to']))
					continue;

				if (!isset($delegate['prefix']))
					$delegate['prefix'] = null;

				$newDelegate = array(
					'to'		=> $delegate['to'],
					'prefix'	=> $delegate['prefix'],
					'delegate'	=> array());

				foreach ($delegate as $name => $value)
				{
					if (is_numeric($name))
						$newDelegate['delegate'][] = $value;
				}

				$new[] = $newDelegate;
			}

			$new['processed'] = true;
			$this->class->setStaticPropertyValue('delegate',$new);
		}
	}

};
?>
