<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\db;

use PDO;
use think\App;
use think\Cache;
use think\Collection;
use think\Config;
use think\Db;
use think\db\exception\BindParamException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\Loader;
use think\Model;
use think\model\Relation;
use think\model\relation\OneToOne;
use think\Paginator;

class Query
{
    // Database Connection object instance
    protected $connection;
    // Database Builder object instance
    protected $builder;
    // Current model class name
    protected $model;
    // Current data table name (with prefix)
    protected $table = '';
    // Current data table name (without prefix)
    protected $name = '';
    // Current data table primary key
    protected $pk;
    // Current data table prefix
    protected $prefix = '';
    // Query parameters
    protected $options = [];
    // parameter binding
    protected $bind = [];
    // Table information
    protected static $info = [];
    // Callback events
    private static $event = [];
    // Tables whose reads use the master server
    protected static $readMaster = [];

    /* *
 * Constructor
 * @access public
 * @param Connection $connection Database connection object
 * @param Model $model Model instance */
    public function __construct(?Connection $connection = null, $model = null)
    {
        $this->connection = $connection ?: Db::connect([], true);
        $this->prefix     = $this->connection->getConfig('prefix');
        $this->model      = $model;
        // Set the currently connected Builder object
        $this->setBuilder();
    }

    /* *
 * Use the __call method to implement some special model methods
 * @access public
 * @param string $method name
 * @param array $args call parameters
 * @return mixed
 * @throws DbException
 * @throws Exception */
    public function __call($method, $args)
    {
        if (strtolower(substr($method, 0, 5)) == 'getby') {
            // Get records based on a field
            $field         = Loader::parseName(substr($method, 5));
            $where[$field] = $args[0];
            return $this->where($where)->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // Get a record\'s value based on a field
            $name         = Loader::parseName(substr($method, 10));
            $where[$name] = $args[0];
            return $this->where($where)->value($args[1]);
        } elseif ($this->model && method_exists($this->model, 'scope' . $method)) {
            // Call a named model query scope
            $method = 'scope' . $method;
            array_unshift($args, $this);

            call_user_func_array([$this->model, $method], $args);
            return $this;
        } else {
            throw new Exception('method not exist:' . __CLASS__ . '->' . $method);
        }
    }

    /* *
 * Get the current database connection object
 * @access public
 * @return Connection */
    public function getConnection()
    {
        return $this->connection;
    }

    /* *
 * Switch the current database connection
 * @access public
 * @param mixed $config
 * @return $this */
    public function connect($config)
    {
        $this->connection = Db::connect($config);
        $this->setBuilder();
        $this->prefix = $this->connection->getConfig('prefix');
        return $this;
    }

    /* *
 * Create the builder for the current database connection
 * @access protected
 * @return void */
    protected function setBuilder()
    {
        $class         = $this->connection->getBuilder();
        $this->builder = new $class($this->connection, $this);
    }

    /* *
 * Get the current model object instance
 * @access public
 * @return Model|null */
    public function getModel()
    {
        return $this->model;
    }

    /* *
 * Use the master server for subsequent reads
 * @access public
 * @param bool $allTable
 * @return void */
    public function readMaster($allTable = false)
    {
        if ($allTable) {
            $table = '*';
        } else {
            $table = isset($this->options['table']) ? $this->options['table'] : $this->getTable();
        }

        static::$readMaster[$table] = true;

        return $this;
    }

    /* *
 * Get the current builder instance object
 * @access public
 * @return Builder */
    public function getBuilder()
    {
        return $this->builder;
    }

    /* *
 * Specify the default data table name (without prefix)
 * @access public
 * @param string $name
 * @return $this */
    public function name($name)
    {
        $this->name = $name;
        return $this;
    }

    /* *
 * Specify the default data table name (including prefix)
 * @access public
 * @param string $table table name
 * @return $this */
    public function setTable($table)
    {
        $this->table = $table;
        return $this;
    }

    /* *
 * Get the current or specified table name
 * @access public
 * @param string $name
 * @return string */
    public function getTable($name = '')
    {
        if ($name || empty($this->table)) {
            $name      = $name ?: $this->name;
            $tableName = $this->prefix;
            if ($name) {
                $tableName .= Loader::parseName($name);
            }
        } else {
            $tableName = $this->table;
        }
        return $tableName;
    }

    /* *
 * Replace the __TABLE_NAME__ string in the SQL statement with a prefixed table name (lowercase)
 * @access public
 * @param string $sql sql statement
 * @return string */
    public function parseSqlTable($sql)
    {
        if (false !== strpos($sql, '__')) {
            $prefix = $this->prefix;
            $sql    = preg_replace_callback("/__([A-Z0-9_-]+)__/sU", function ($match) use ($prefix) {
                return $prefix . strtolower($match[1]);
            }, $sql);
        }
        return $sql;
    }

    /* *
 * Execute the query to return the data set
 * @access public
 * @param string $sql sql instructions
 * @param array $bind parameter binding
 * @param boolean $master Whether to read from the master server
 * @param bool|string $class specifies the returned dataset object
 * @return mixed
 * @throws BindParamException
 * @throws PDOException */
    public function query($sql, $bind = [], $master = false, $class = false)
    {
        return $this->connection->query($sql, $bind, $master, $class);
    }

    /* *
 * Execute a SQL write statement
 * @access public
 * @param string $sql sql
 * @param array $bind Parameter bindings
 * @return int
 * @throws BindParamException
 * @throws PDOException */
    public function execute($sql, $bind = [])
    {
        return $this->connection->execute($sql, $bind, $this);
    }

    /* *
 * Get the last inserted ID
 * @access public
 * @param string $sequence Auto-increment sequence name
 * @return string */
    public function getLastInsID($sequence = null)
    {
        return $this->connection->getLastInsID($sequence);
    }

    /* *
 * Get the SQL statement of the most recent query
 * @access public
 * @return string */
    public function getLastSql()
    {
        return $this->connection->getLastSql();
    }

    /* *
 * Execute a transaction callback
 * @access public
 * @param callable $callback
 * @return mixed */
    public function transaction($callback)
    {
        return $this->connection->transaction($callback);
    }

    /* *
 * Start a transaction
 * @access public
 * @return void */
    public function startTrans()
    {
        $this->connection->startTrans();
    }

    /* *
 * Commit the current transaction
 * @access public
 * @return void
 * @throws PDOException */
    public function commit()
    {
        $this->connection->commit();
    }

    /* *
 * Transaction rollback
 * @access public
 * @return void
 * @throws PDOException */
    public function rollback()
    {
        $this->connection->rollback();
    }

    /* *
 * Execute SQL statements in batches
 * All batch instructions are considered execute operations
 * @access public
 * @param array $sql SQL batch instructions
 * @return boolean */
    public function batchQuery($sql = [], $bind = [])
    {
        return $this->connection->batchQuery($sql, $bind);
    }

    /* *
 * Get database configuration parameters
 * @access public
 * @param string $name parameter name
 * @return boolean */
    public function getConfig($name = '')
    {
        return $this->connection->getConfig($name);
    }

    /* *
 * Get the name of the data table for the partitioned table
 * @access public
 * @param array $data The data for the operation
 * @param string $field The field the table is based on
 * @param array $rule table partitioning rules
 * @return string */
    public function getPartitionTableName($data, $field, $rule = [])
    {
        // Partition the data table
        if ($field && isset($data[$field])) {
            $value = $data[$field];
            $type  = $rule['type'];
            switch ($type) {
                case 'id':
                    // Partition by ID range
                    $step = $rule['expr'];
                    $seq  = floor($value / $step) + 1;
                    break;
                case 'year':
                    // Partition by year
                    if (!is_numeric($value)) {
                        $value = strtotime($value);
                    }
                    $seq = date('Y', $value) - $rule['expr'] + 1;
                    break;
                case 'mod':
                    // Partition using the ID modulo the partition count
                    $seq = ($value % $rule['num']) + 1;
                    break;
                case 'md5':
                    // Partition using an MD5 hash
                    $seq = (ord(substr(md5($value), 0, 1)) % $rule['num']) + 1;
                    break;
                default:
                    if (function_exists($type)) {
                        // Partition using a custom hash function
                        $seq = (ord(substr($type($value), 0, 1)) % $rule['num']) + 1;
                    } else {
                        // Partition using the first character of the field value
                        $seq = (ord($value[0]) % $rule['num']) + 1;
                    }
            }
            return $this->getTable() . '_' . $seq;
        } else {
            // When the specified table field is not in the search conditions or data
            // For joint queries, partition [\'num\'] must be set
            $tableName = [];
            for ($i = 0; $i < $rule['num']; $i++) {
                $tableName[] = 'SELECT * FROM ' . $this->getTable() . '_' . ($i + 1);
            }

            $tableName = '( ' . implode(" UNION ", $tableName) . ') AS ' . $this->name;
            return $tableName;
        }
    }

    /* *
 * Get the value of a field
 * @access public
 * @param string $field name
 * @param mixed $default value
 * @param bool $force Whether to cast the result to a number
 * @return mixed */
    public function value($field, $default = null, $force = false)
    {
        $result = false;
        if (empty($this->options['fetch_sql']) && !empty($this->options['cache'])) {
            // Determine the query cache
            $cache = $this->options['cache'];
            if (empty($this->options['table'])) {
                $this->options['table'] = $this->getTable();
            }
            $key    = is_string($cache['key']) ? $cache['key'] : md5($this->connection->getConfig('database') . '.' . $field . serialize($this->options) . serialize($this->bind));
            $result = Cache::get($key);
        }
        if (false === $result) {
            if (isset($this->options['field'])) {
                unset($this->options['field']);
            }
            $pdo = $this->field($field)->limit(1)->getPdo();
            if (is_string($pdo)) {
                // Returns an SQL statement
                return $pdo;
            }

            $result = $pdo->fetchColumn();
            if ($force) {
                $result = (float) $result;
            }

            if (isset($cache) && false !== $result) {
                // Caching data
                $this->cacheData($key, $result, $cache);
            }
        } else {
            // Clear search conditions
            $this->options = [];
        }
        return false !== $result ? $result : $default;
    }

    /* *
 * Get an array of columns
 * @access public
 * @param string $field name Multiple fields separated by commas
 * @param string $key index
 * @return array */
    public function column($field, $key = '')
    {
        $result = false;
        if (empty($this->options['fetch_sql']) && !empty($this->options['cache'])) {
            // Determine the query cache
            $cache = $this->options['cache'];
            if (empty($this->options['table'])) {
                $this->options['table'] = $this->getTable();
            }
            $guid   = is_string($cache['key']) ? $cache['key'] : md5($this->connection->getConfig('database') . '.' . $field . serialize($this->options) . serialize($this->bind));
            $result = Cache::get($guid);
        }
        if (false === $result) {
            if (isset($this->options['field'])) {
                unset($this->options['field']);
            }
            if (is_null($field)) {
                $field = '*';
            } elseif ($key && '*' != $field) {
                $field = $key . ',' . $field;
            }
            $pdo = $this->field($field)->getPdo();
            if (is_string($pdo)) {
                // Returns an SQL statement
                return $pdo;
            }
            if (1 == $pdo->columnCount()) {
                $result = $pdo->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $resultSet = $pdo->fetchAll(PDO::FETCH_ASSOC);
                if ($resultSet) {
                    $fields = array_keys($resultSet[0]);
                    $count  = count($fields);
                    $key1   = array_shift($fields);
                    $key2   = $fields ? array_shift($fields) : '';
                    $key    = $key ?: $key1;
                    if (strpos($key, '.')) {
                        list($alias, $key) = explode('.', $key);
                    }
                    foreach ($resultSet as $val) {
                        if ($count > 2) {
                            $result[$val[$key]] = $val;
                        } elseif (2 == $count) {
                            $result[$val[$key]] = $val[$key2];
                        } elseif (1 == $count) {
                            $result[$val[$key]] = $val[$key1];
                        }
                    }
                } else {
                    $result = [];
                }
            }
            if (isset($cache) && isset($guid)) {
                // Caching data
                $this->cacheData($guid, $result, $cache);
            }
        } else {
            // Clear search conditions
            $this->options = [];
        }
        return $result;
    }

    /* *
 * Count query
 * @access public
 * @param string $field field name
 * @return integer|string */
    public function count($field = '*')
    {
        if (isset($this->options['group'])) {
            if (!preg_match('/^[\w\.\*]+$/', $field)) {
                throw new Exception('not support data:' . $field);
            }
            // Support GROUP
            $options = $this->getOptions();
            $subSql  = $this->options($options)->field('count(' . $field . ')')->bind($this->bind)->buildSql();

            $count = $this->table([$subSql => '_group_count_'])->value('COUNT(*) AS tp_count', 0, true);
        } else {
            $count = $this->aggregate('COUNT', $field, true);
        }

        return is_string($count) ? $count : (int) $count;

    }

    /* *
 * Aggregated Queries
 * @access public
 * @param string $aggregate aggregation method
 * @param string $field name
 * @param bool $force Whether to cast the result to a number
 * @return mixed */
    public function aggregate($aggregate, $field, $force = false)
    {
        if (0 === stripos($field, 'DISTINCT ')) {
            list($distinct, $field) = explode(' ', $field);
        }

        if (!preg_match('/^[\w\.\+\-\*]+$/', $field)) {
            throw new Exception('not support data:' . $field);
        }

        $result = $this->value($aggregate . '(' . (!empty($distinct) ? 'DISTINCT ' : '') . $field . ') AS tp_' . strtolower($aggregate), 0, $force);

        return $result;
    }

    /* *
 * Get the sum of a field
 * @access public
 * @param string $field string name
 * @return float|int */
    public function sum($field)
    {
        return $this->aggregate('SUM', $field, true);
    }

    /* *
 * Get the minimum field value
 * @access public
 * @param string $field name
 * @param bool $force Whether to cast the result to a number
 * @return mixed */
    public function min($field, $force = true)
    {
        return $this->aggregate('MIN', $field, $force);
    }

    /* *
 * Get the maximum field value
 * @access public
 * @param string $field name
 * @param bool $force Whether to cast the result to a number
 * @return mixed */
    public function max($field, $force = true)
    {
        return $this->aggregate('MAX', $field, $force);
    }

    /* *
 * Get the average field value
 * @access public
 * @param string $field field name
 * @return float|int */
    public function avg($field)
    {
        return $this->aggregate('AVG', $field, true);
    }

    /* *
 * Set a field value for a record
 * Supports the use of database fields and methods
 * @access public
 * @param string|array $field name
 * @param mixed $value field value
 * @return integer */
    public function setField($field, $value = '')
    {
        if (is_array($field)) {
            $data = $field;
        } else {
            $data[$field] = $value;
        }
        return $this->update($data);
    }

    /* *
 * Increment a field value, optionally delaying the write
 * @access public
 * @param string $field name
 * @param integer $step growth value
 * @param integer $lazyTime Delay time (s)
 * @return integer|true
 * @throws Exception */
    public function setInc($field, $step = 1, $lazyTime = 0)
    {
        $condition = !empty($this->options['where']) ? $this->options['where'] : [];
        if (empty($condition)) {
            // No conditions, no updates
            throw new Exception('no data to update');
        }
        if ($lazyTime > 0) {
            // Delayed writing
            $guid = md5($this->getTable() . '_' . $field . '_' . serialize($condition) . serialize($this->bind));
            $step = $this->lazyWrite('inc', $guid, $step, $lazyTime);
            if (false === $step) {
                // Clear search conditions
                $this->options = [];
                return true;
            }
        }
        return $this->setField($field, ['inc', $step]);
    }

    /* *
 * Decrement a field value, optionally delaying the write
 * @access public
 * @param string $field name
 * @param integer $step reduced value
 * @param integer $lazyTime Delay time (s)
 * @return integer|true
 * @throws Exception */
    public function setDec($field, $step = 1, $lazyTime = 0)
    {
        $condition = !empty($this->options['where']) ? $this->options['where'] : [];
        if (empty($condition)) {
            // No conditions, no updates
            throw new Exception('no data to update');
        }
        if ($lazyTime > 0) {
            // Delayed writing
            $guid = md5($this->getTable() . '_' . $field . '_' . serialize($condition) . serialize($this->bind));
            $step = $this->lazyWrite('dec', $guid, $step, $lazyTime);
            if (false === $step) {
                // Clear search conditions
                $this->options = [];
                return true;
            }
            return $this->setField($field, ['inc', $step]);
        }
        return $this->setField($field, ['dec', $step]);
    }

    /* *
 * Delayed update check returns false to indicate that a delay is required
 * Otherwise, the actual value written is returned
 * @access protected
 * @param string $type self increasing or decreasing
 * @param string $guid Write ID
 * @param integer $step Write the step value
 * @param integer $lazyTime Delay time (s)
 * @return false|integer */
    protected function lazyWrite($type, $guid, $step, $lazyTime)
    {
        if (!Cache::has($guid . '_time')) {
            // The timing starts
            Cache::set($guid . '_time', $_SERVER['REQUEST_TIME'], 0);
            Cache::$type($guid, $step);
        } elseif ($_SERVER['REQUEST_TIME'] > Cache::get($guid . '_time') + $lazyTime) {
            // Delete the cache
            $value = Cache::$type($guid, $step);
            Cache::rm($guid);
            Cache::rm($guid . '_time');
            return 0 === $value ? false : $value;
        } else {
            // Update the cache
            Cache::$type($guid, $step);
        }
        return false;
    }

    /* *
 * Add a JOIN clause
 * @access public
 * @param mixed $join associated table name
 * @param mixed $condition condition
 * @param string $type JOIN type
 * @return $this */
    public function join($join, $condition = null, $type = 'INNER')
    {
        if (empty($condition)) {
            // Add each JOIN definition from the supplied array
            foreach ($join as $key => $value) {
                if (is_array($value) && 2 <= count($value)) {
                    $this->join($value[0], $value[1], isset($value[2]) ? $value[2] : $type);
                }
            }
        } else {
            $table = $this->getJoinTable($join);

            $this->options['join'][] = [$table, strtoupper($type), $condition];
        }
        return $this;
    }

    /* *
 * Get support for Join table names and aliases
 * [\'prefix_table or sub -query\'=>\'alias\'] \'prefix_table alias\' \'table alias\'
 * @access public
 * @param array|string $join
 * @return array|string */
    protected function getJoinTable($join, &$alias = null)
    {
        // The table name passed in is an array
        if (is_array($join)) {
            $table = $join;
            $alias = array_shift($join);
        } else {
            $join = trim($join);
            if (false !== strpos($join, '(')) {
                // Using subqueries
                $table = $join;
            } else {
                $prefix = $this->prefix;
                if (strpos($join, ' ')) {
                    // Using aliases
                    list($table, $alias) = explode(' ', $join);
                } else {
                    $table = $join;
                    if (false === strpos($join, '.') && 0 !== strpos($join, '__')) {
                        $alias = $join;
                    }
                }
                if ($prefix && false === strpos($table, '.') && 0 !== strpos($table, $prefix) && 0 !== strpos($table, '__')) {
                    $table = $this->getTable($table);
                }
            }
            if (isset($alias) && $table != $alias) {
                $table = [$table => $alias];
            }
        }
        return $table;
    }

    /* *
 * Add a UNION clause
 * @access public
 * @param mixed $union
 * @param boolean $all
 * @return $this */
    public function union($union, $all = false)
    {
        $this->options['union']['type'] = $all ? 'UNION ALL' : 'UNION';

        if (is_array($union)) {
            $this->options['union'] = array_merge($this->options['union'], $union);
        } else {
            $this->options['union'][] = $union;
        }
        return $this;
    }

    /* *
 * The specified query field supports field exclusion and specified data tables
 * @access public
 * @param mixed $field
 * @param boolean $except Whether to exclude the supplied fields
 * @param string $tableName Data table name
 * @param string $prefix field prefix
 * @param string $alias alias prefix
 * @return $this */
    public function field($field, $except = false, $tableName = '', $prefix = '', $alias = '')
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Expression) {
            $this->options['field'][] = $field;
            return $this;
        }

        if (is_string($field)) {
            if (preg_match('/[\<\'\"\(]/', $field)) {
                return $this->fieldRaw($field);
            }
            $field = array_map('trim', explode(',', $field));
        }
        if (true === $field) {
            // Get all fields
            $fields = $this->getTableInfo($tableName ?: (isset($this->options['table']) ? $this->options['table'] : ''), 'fields');
            $field  = $fields ?: ['*'];
        } elseif ($except) {
            // Field exclusion
            $fields = $this->getTableInfo($tableName ?: (isset($this->options['table']) ? $this->options['table'] : ''), 'fields');
            $field  = $fields ? array_diff($fields, $field) : $field;
        }
        if ($tableName) {
            // Add uniform prefixes
            $prefix = $prefix ?: $tableName;
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $val = $prefix . '.' . $val . ($alias ? ' AS ' . $alias . $val : '');
                }
                $field[$key] = $val;
            }
        }

        if (isset($this->options['field'])) {
            $field = array_merge((array) $this->options['field'], $field);
        }
        $this->options['field'] = array_unique($field);
        return $this;
    }

    /* *
 * Specify selected fields using a raw SQL expression
 * @access public
 * @param string $field name
 * @param array $bind parameter binding
 * @return $this */
    public function fieldRaw($field, array $bind = [])
    {
        $this->options['field'][] = $this->raw($field);

        if ($bind) {
            $this->bind($bind);
        }

        return $this;
    }

    /* *
 * Set up data
 * @access public
 * @param mixed $field name or data
 * @param mixed $value Field value
 * @return $this */
    public function data($field, $value = null)
    {
        if (is_array($field)) {
            $this->options['data'] = isset($this->options['data']) ? array_merge($this->options['data'], $field) : $field;
        } else {
            $this->options['data'][$field] = $value;
        }
        return $this;
    }

    /* *
 * Increment field values
 * @access public
 * @param string|array $field name
 * @param integer $step growth value
 * @return $this */
    public function inc($field, $step = 1)
    {
        $fields = is_string($field) ? explode(',', $field) : $field;
        foreach ($fields as $field) {
            $this->data($field, ['inc', $step]);
        }
        return $this;
    }

    /* *
 * Decrement field values
 * @access public
 * @param string|array $field name
 * @param integer $step growth value
 * @return $this */
    public function dec($field, $step = 1)
    {
        $fields = is_string($field) ? explode(',', $field) : $field;
        foreach ($fields as $field) {
            $this->data($field, ['dec', $step]);
        }
        return $this;
    }

    /* *
 * Use expressions to set data
 * @access public
 * @param string $field name
 * @param string $value field value
 * @return $this */
    public function exp($field, $value)
    {
        $this->data($field, $this->raw($value));
        return $this;
    }

    /* *
 * Use expressions to set data
 * @access public
 * @param mixed $value expression
 * @return Expression */
    public function raw($value)
    {
        return new Expression($value);
    }

    /* *
 * Specify the JOIN query field
 * @access public
 * @param string|array $join Table name or JOIN definitions
 * @param string|array $field query field
 * @param mixed $on JOIN conditions
 * @param string $type JOIN type
 * @return $this */
    public function view($join, $field = true, $on = null, $type = 'INNER')
    {
        $this->options['view'] = true;
        if (is_array($join) && key($join) === 0) {
            foreach ($join as $key => $val) {
                $this->view($val[0], $val[1], isset($val[2]) ? $val[2] : null, isset($val[3]) ? $val[3] : 'INNER');
            }
        } else {
            $fields = [];
            $table  = $this->getJoinTable($join, $alias);

            if (true === $field) {
                $fields = $alias . '.*';
            } else {
                if (is_string($field)) {
                    $field = explode(',', $field);
                }
                foreach ($field as $key => $val) {
                    if (is_numeric($key)) {
                        $fields[]                   = $alias . '.' . $val;
                        $this->options['map'][$val] = $alias . '.' . $val;
                    } else {
                        if (preg_match('/[,=\.\'\"\(\s]/', $key)) {
                            $name = $key;
                        } else {
                            $name = $alias . '.' . $key;
                        }
                        $fields[$name]              = $val;
                        $this->options['map'][$val] = $name;
                    }
                }
            }
            $this->field($fields);
            if ($on) {
                $this->join($table, $on, $type);
            } else {
                $this->table($table);
            }
        }
        return $this;
    }

    /* *
 * Set table partitioning rules
 * @access public
 * @param array $data The data for the operation
 * @param string $field The field the table is based on
 * @param array $rule table partitioning rules
 * @return $this */
    public function partition($data, $field, $rule = [])
    {
        $this->options['table'] = $this->getPartitionTableName($data, $field, $rule);
        return $this;
    }

    /* *
 * Specify AND search conditions
 * @access public
 * @param mixed $field query field
 * @param mixed $op query expression
 * @param mixed $condition search conditions
 * @return $this */
    public function where($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        $this->parseWhereExp('AND', $field, $op, $condition, $param);
        return $this;
    }

    /* *
 * Specify OR search conditions
 * @access public
 * @param mixed $field query field
 * @param mixed $op query expression
 * @param mixed $condition search conditions
 * @return $this */
    public function whereOr($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        $this->parseWhereExp('OR', $field, $op, $condition, $param);
        return $this;
    }

    /* *
 * Specify XOR search conditions
 * @access public
 * @param mixed $field query field
 * @param mixed $op query expression
 * @param mixed $condition search conditions
 * @return $this */
    public function whereXor($field, $op = null, $condition = null)
    {
        $param = func_get_args();
        array_shift($param);
        $this->parseWhereExp('XOR', $field, $op, $condition, $param);
        return $this;
    }

    /* *
 * Specify expression search conditions
 * @access public
 * @param string $where search conditions
 * @param array $bind parameter binding
 * @param string $logic query logic and or xor
 * @return $this */
    public function whereRaw($where, $bind = [], $logic = 'AND')
    {
        $this->options['where'][$logic][] = $this->raw($where);

        if ($bind) {
            $this->bind($bind);
        }

        return $this;
    }

    /* *
 * Specify expression search conditions OR
 * @access public
 * @param string $where search conditions
 * @param array $bind parameter binding
 * @return $this */
    public function whereOrRaw($where, $bind = [])
    {
        return $this->whereRaw($where, $bind, 'OR');
    }

    /* *
 * Specify null search conditions
 * @access public
 * @param mixed $field Query field
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereNull($field, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'null', null, [], true);
        return $this;
    }

    /* *
 * Specifies NotNull lookup conditions
 * @access public
 * @param mixed $field Query field
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereNotNull($field, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'notnull', null, [], true);
        return $this;
    }

    /* *
 * Specify Exists query conditions
 * @access public
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereExists($condition, $logic = 'AND')
    {
        $this->options['where'][strtoupper($logic)][] = ['exists', $condition];
        return $this;
    }

    /* *
 * Specify NOT EXISTS conditions
 * @access public
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereNotExists($condition, $logic = 'AND')
    {
        $this->options['where'][strtoupper($logic)][] = ['not exists', $condition];
        return $this;
    }

    /* *
 * Specify In search conditions
 * @access public
 * @param mixed $field query field
 * @param mixed $condition search conditions
 * @param string $logic query logic and or xor
 * @return $this */
    public function whereIn($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'in', $condition, [], true);
        return $this;
    }

    /* *
 * Specify NotIn search conditions
 * @access public
 * @param mixed $field query field
 * @param mixed $condition search conditions
 * @param string $logic query logic and or xor
 * @return $this */
    public function whereNotIn($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'not in', $condition, [], true);
        return $this;
    }

    /* *
 * Specify Like search conditions
 * @access public
 * @param mixed $field Query field
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereLike($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'like', $condition, [], true);
        return $this;
    }

    /* *
 * Specifies NotLike request conditions
 * @access public
 * @param mixed $field Query field
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereNotLike($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'not like', $condition, [], true);
        return $this;
    }

    /* *
 * Specify Between search conditions
 * @access public
 * @param mixed $field Query field
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereBetween($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'between', $condition, [], true);
        return $this;
    }

    /* *
 * Specify NotBetween search conditions
 * @access public
 * @param mixed $field Query field
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereNotBetween($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'not between', $condition, [], true);
        return $this;
    }

    /* *
 * Specify Exp search conditions
 * @access public
 * @param mixed $field Query field
 * @param mixed $condition search conditions
 * @param string $logic Query logic and or xor
 * @return $this */
    public function whereExp($field, $condition, $logic = 'AND')
    {
        $this->parseWhereExp($logic, $field, 'exp', $this->raw($condition), [], true);
        return $this;
    }

    /* *
 * Set soft delete fields and conditions
 * @access public
 * @param false|string $field Query field
 * @param mixed $condition search conditions
 * @return $this */
    public function useSoftDelete($field, $condition = null)
    {
        if ($field) {
            $this->options['soft_delete'] = [$field, $condition ?: ['null', '']];
        }
        return $this;
    }

    /* *
 * Analyze query expressions
 * @access public
 * @param string $logic query logic and or xor
 * @param string|array|\Closure $field query field
 * @param mixed $op query expression
 * @param mixed $condition search conditions
 * @param array $param query parameters
 * @param bool $strict strict mode
 * @return void */
    protected function parseWhereExp($logic, $field, $op, $condition, $param = [], $strict = false)
    {
        $logic = strtoupper($logic);
        if ($field instanceof \Closure) {
            $this->options['where'][$logic][] = is_string($op) ? [$op, $field] : $field;
            return;
        }

        if (is_string($field) && !empty($this->options['via']) && !strpos($field, '.')) {
            $field = $this->options['via'] . '.' . $field;
        }

        if ($field instanceof Expression) {
            return $this->whereRaw($field, is_array($op) ? $op : []);
        } elseif ($strict) {
            // Querying using strict mode
            $where[$field] = [$op, $condition];

            // Record multiple search conditions in one field
            $this->options['multi'][$logic][$field][] = $where[$field];
        } elseif (is_string($field) && preg_match('/[,=\>\<\'\"\(\s]/', $field)) {
            $where[] = ['exp', $this->raw($field)];
            if (is_array($op)) {
                // parameter binding
                $this->bind($op);
            }
        } elseif (is_null($op) && is_null($condition)) {
            if (is_array($field)) {
                // Batch array query
                $where = $field;
                foreach ($where as $k => $val) {
                    $this->options['multi'][$logic][$k][] = $val;
                }
            } elseif ($field && is_string($field)) {
                // string query
                $where[$field]                            = ['null', ''];
                $this->options['multi'][$logic][$field][] = $where[$field];
            }
        } elseif (is_array($op)) {
            $where[$field] = $param;
        } elseif (in_array(strtolower($op), ['null', 'notnull', 'not null'])) {
            // NULL conditions
            $where[$field] = [$op, ''];

            $this->options['multi'][$logic][$field][] = $where[$field];
        } elseif (is_null($condition)) {
            // Field equality queries
            $where[$field] = ['eq', $op];

            $this->options['multi'][$logic][$field][] = $where[$field];
        } else {
            if ('exp' == strtolower($op)) {
                $where[$field] = ['exp', $this->raw($condition)];
                // parameter binding
                if (isset($param[2]) && is_array($param[2])) {
                    $this->bind($param[2]);
                }
            } else {
                $where[$field] = [$op, $condition];
            }
            // Record multiple search conditions in one field
            $this->options['multi'][$logic][$field][] = $where[$field];
        }

        if (!empty($where)) {
            if (!isset($this->options['where'][$logic])) {
                $this->options['where'][$logic] = [];
            }
            if (is_string($field) && $this->checkMultiField($field, $logic)) {
                $where[$field] = $this->options['multi'][$logic][$field];
            } elseif (is_array($field)) {
                foreach ($field as $key => $val) {
                    if ($this->checkMultiField($key, $logic)) {
                        $where[$key] = $this->options['multi'][$logic][$key];
                    }
                }
            }
            $this->options['where'][$logic] = array_merge($this->options['where'][$logic], $where);
        }
    }

    /* *
 * Check if there are multiple search conditions for one field
 * @access public
 * @param string $field query field
 * @param string $logic query logic and or xor
 * @return bool */
    private function checkMultiField($field, $logic)
    {
        return isset($this->options['multi'][$logic][$field]) && count($this->options['multi'][$logic][$field]) > 1;
    }

    /* *
 * Remove a search condition
 * @access public
 * @param string $field Query field
 * @param string $logic Query logic and or xor
 * @return $this */
    public function removeWhereField($field, $logic = 'AND')
    {
        $logic = strtoupper($logic);
        if (isset($this->options['where'][$logic][$field])) {
            unset($this->options['where'][$logic][$field]);
            unset($this->options['multi'][$logic][$field]);
        }
        return $this;
    }

    /* *
 * Remove query parameters
 * @access public
 * @param string|bool $option parameter name true indicates that all parameters are removed
 * @return $this */
    public function removeOption($option = true)
    {
        if (true === $option) {
            $this->options = [];
        } elseif (is_string($option) && isset($this->options[$option])) {
            unset($this->options[$option]);
        }
        return $this;
    }

    /* *
 * Limit the number of returned rows
 * @access public
 * @param mixed $offset starting position
 * @param mixed $length Number of rows
 * @return $this */
    public function limit($offset, $length = null)
    {
        if (is_null($length) && strpos($offset, ',')) {
            list($offset, $length) = explode(',', $offset);
        }
        $this->options['limit'] = intval($offset) . ($length ? ',' . intval($length) : '');
        return $this;
    }

    /* *
 * Specify a page
 * @access public
 * @param mixed $page Page number
 * @param mixed $listRows number per page
 * @return $this */
    public function page($page, $listRows = null)
    {
        if (is_null($listRows) && strpos($page, ',')) {
            list($page, $listRows) = explode(',', $page);
        }
        $this->options['page'] = [intval($page), intval($listRows)];
        return $this;
    }

    /* *
 * Paginate query results
 * @param int|array $listRows number per page The array represents the configuration parameters
 * @param int|bool $simple Whether to use simple pagination, or the supplied total record count
 * @param array $config configuration parameters
 * page: current page,
 * path: url path,
 * query:url additional parameters,
 * fragment: url anchor,
 * var_page: pagination variable,
 * list_rows: number per page
 * type: pagination class name
 * @return \think\Paginator
 * @throws DbException */
    public function paginate($listRows = null, $simple = false, $config = [])
    {
        if (is_int($simple)) {
            $total  = $simple;
            $simple = false;
        }
        if (is_array($listRows)) {
            $config   = array_merge(Config::get('paginate'), $listRows);
            $listRows = $config['list_rows'];
        } else {
            $config   = array_merge(Config::get('paginate'), $config);
            $listRows = $listRows ?: $config['list_rows'];
        }

        /* * @var Paginator $class */
        $class = false !== strpos($config['type'], '\\') ? $config['type'] : '\\think\\paginator\\driver\\' . ucwords($config['type']);
        $page  = isset($config['page']) ? (int) $config['page'] : call_user_func([
            $class,
            'getCurrentPage',
        ], $config['var_page']);

        $page = $page < 1 ? 1 : $page;

        $config['path'] = isset($config['path']) ? $config['path'] : call_user_func([$class, 'getCurrentPath']);

        if (!isset($total) && !$simple) {
            $options = $this->getOptions();

            unset($this->options['order'], $this->options['limit'], $this->options['page'], $this->options['field']);

            $bind    = $this->bind;
            $total   = $this->count();
            $results = $this->options($options)->bind($bind)->page($page, $listRows)->select();
        } elseif ($simple) {
            $results = $this->limit(($page - 1) * $listRows, $listRows + 1)->select();
            $total   = null;
        } else {
            $results = $this->page($page, $listRows)->select();
        }
        return $class::make($results, $listRows, $page, $total, $simple, $config);
    }

    /* *
 * Specify the table for the current operation
 * @access public
 * @param mixed $table table name
 * @return $this */
    public function table($table)
    {
        if (is_string($table)) {
            if (strpos($table, ')')) {
                // Sub Queries
            } elseif (strpos($table, ',')) {
                $tables = explode(',', $table);
                $table  = [];
                foreach ($tables as $item) {
                    list($item, $alias) = explode(' ', trim($item));
                    if ($alias) {
                        $this->alias([$item => $alias]);
                        $table[$item] = $alias;
                    } else {
                        $table[] = $item;
                    }
                }
            } elseif (strpos($table, ' ')) {
                list($table, $alias) = explode(' ', $table);

                $table = [$table => $alias];
                $this->alias($table);
            }
        } else {
            $tables = $table;
            $table  = [];
            foreach ($tables as $key => $val) {
                if (is_numeric($key)) {
                    $table[] = $val;
                } else {
                    $this->alias([$key => $val]);
                    $table[$key] = $val;
                }
            }
        }
        $this->options['table'] = $table;
        return $this;
    }

    /* *
 * USING support for multi-table deletions
 * @access public
 * @param mixed $using
 * @return $this */
    public function using($using)
    {
        $this->options['using'] = $using;
        return $this;
    }

    /* *
 * Specify sort order (\'id\', \'desc\') or order ([\'id\' => \'desc\', \'create_time\' => \'desc\'])
 * @access public
 * @param string|array $field sort field
 * @param string $order sorting
 * @return $this */
    public function order($field, $order = null)
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Expression) {
            $this->options['order'][] = $field;
            return $this;
        }

        if (is_string($field)) {
            if (!empty($this->options['via'])) {
                $field = $this->options['via'] . '.' . $field;
            }
            if (strpos($field, ',')) {
                $field = array_map('trim', explode(',', $field));
            } else {
                $field = empty($order) ? $field : [$field => $order];
            }
        } elseif (!empty($this->options['via'])) {
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $field[$key] = $this->options['via'] . '.' . $val;
                } else {
                    $field[$this->options['via'] . '.' . $key] = $val;
                    unset($field[$key]);
                }
            }
        }
        if (!isset($this->options['order'])) {
            $this->options['order'] = [];
        }
        if (is_array($field)) {
            $this->options['order'] = array_merge($this->options['order'], $field);
        } else {
            $this->options['order'][] = $field;
        }

        return $this;
    }

    /* *
 * The expression method specifies field sorting
 * @access public
 * @param string $field sort field
 * @param array $bind parameter binding
 * @return $this */
    public function orderRaw($field, array $bind = [])
    {
        $this->options['order'][] = $this->raw($field);

        if ($bind) {
            $this->bind($bind);
        }

        return $this;
    }

    /* *
 * query cache
 * @access public
 * @param mixed $key Cache key
 * @param integer|\DateTime $expire Cache lifetime or expiration time
 * @param string $tag
 * @return $this */
    public function cache($key = true, $expire = null, $tag = null)
    {
        // Adding the shortcut method cache (10) is equivalent to cache (true, 10)
        if ($key instanceof \DateTime || (is_numeric($key) && is_null($expire))) {
            $expire = $key;
            $key    = true;
        }

        if (false !== $key) {
            $this->options['cache'] = ['key' => $key, 'expire' => $expire, 'tag' => $tag];
        }
        return $this;
    }

    /* *
 * Search by specified group
 * @access public
 * @param string $group GROUP
 * @return $this */
    public function group($group)
    {
        $this->options['group'] = $group;
        return $this;
    }

    /* *
 * Specify the HAVING clause
 * @access public
 * @param string $having having
 * @return $this */
    public function having($having)
    {
        $this->options['having'] = $having;
        return $this;
    }

    /* *
 * Specify a query lock
 * @access public
 * @param bool|string $lock Whether to lock
 * @return $this */
    public function lock($lock = false)
    {
        $this->options['lock']   = $lock;
        $this->options['master'] = true;
        return $this;
    }

    /* *
 * Specify a distinct query
 * @access public
 * @param bool $distinct Whether to select distinct results
 * @return $this */
    public function distinct($distinct)
    {
        $this->options['distinct'] = $distinct;
        return $this;
    }

    /* *
 * Specify a data table alias
 * @access public
 * @param mixed $alias data table alias
 * @return $this */
    public function alias($alias)
    {
        if (is_array($alias)) {
            foreach ($alias as $key => $val) {
                if (false !== strpos($key, '__')) {
                    $table = $this->parseSqlTable($key);
                } else {
                    $table = $key;
                }
                $this->options['alias'][$table] = $val;
            }
        } else {
            if (isset($this->options['table'])) {
                $table = is_array($this->options['table']) ? key($this->options['table']) : $this->options['table'];
                if (false !== strpos($table, '__')) {
                    $table = $this->parseSqlTable($table);
                }
            } else {
                $table = $this->getTable();
            }

            $this->options['alias'][$table] = $alias;
        }
        return $this;
    }

    /* *
 * Specify mandatory indexing
 * @access public
 * @param string $force Index name
 * @return $this */
    public function force($force)
    {
        $this->options['force'] = $force;
        return $this;
    }

    /* *
 * Add a SQL comment
 * @access public
 * @param string $comment
 * @return $this */
    public function comment($comment)
    {
        $this->options['comment'] = $comment;
        return $this;
    }

    /* *
 * Get the executed SQL statement
 * @access public
 * @param boolean $fetch Whether to return SQL without executing it
 * @return $this */
    public function fetchSql($fetch = true)
    {
        $this->options['fetch_sql'] = $fetch;
        return $this;
    }

    /* *
 * Return a PDOStatement instead of fetching the result set
 * @access public
 * @param bool $pdo Whether to return a PDOStatement object
 * @return $this */
    public function fetchPdo($pdo = true)
    {
        $this->options['fetch_pdo'] = $pdo;
        return $this;
    }

    /* *
 * Set up data to be read from the master server
 * @access public
 * @return $this */
    public function master()
    {
        $this->options['master'] = true;
        return $this;
    }

    /* *
 * Set whether to strictly check field names
 * @access public
 * @param bool $strict Whether to check field names strictly
 * @return $this */
    public function strict($strict = true)
    {
        $this->options['strict'] = $strict;
        return $this;
    }

    /* *
 * Set whether to throw an exception if the query data does not exist
 * @access public
 * @param bool $fail Whether to throw if no data is found
 * @return $this */
    public function failException($fail = true)
    {
        $this->options['fail'] = $fail;
        return $this;
    }

    /* *
 * Set the auto-increment sequence name
 * @access public
 * @param string $sequence Auto-increment sequence name
 * @return $this */
    public function sequence($sequence = null)
    {
        $this->options['sequence'] = $sequence;
        return $this;
    }

    /* *
 * Specify the primary key of the data table
 * @access public
 * @param string $pk primary key
 * @return $this */
    public function pk($pk)
    {
        $this->pk = $pk;
        return $this;
    }

    /* *
 * Specify a date or time condition
 * @access public
 * @param string $field date field name
 * @param string|array $op comparison operator or expression
 * @param string|array $range comparison range
 * @return $this */
    public function whereTime($field, $op, $range = null)
    {
        if (is_null($range)) {
            if (is_array($op)) {
                $range = $op;
            } else {
                // Using date expressions
                switch (strtolower($op)) {
                    case 'today':
                    case 'd':
                        $range = ['today', 'tomorrow'];
                        break;
                    case 'week':
                    case 'w':
                        $range = ['this week 00:00:00', 'next week 00:00:00'];
                        break;
                    case 'month':
                    case 'm':
                        $range = ['first Day of this month 00:00:00', 'first Day of next month 00:00:00'];
                        break;
                    case 'year':
                    case 'y':
                        $range = ['this year 1/1', 'next year 1/1'];
                        break;
                    case 'yesterday':
                        $range = ['yesterday', 'today'];
                        break;
                    case 'last week':
                        $range = ['last week 00:00:00', 'this week 00:00:00'];
                        break;
                    case 'last month':
                        $range = ['first Day of last month 00:00:00', 'first Day of this month 00:00:00'];
                        break;
                    case 'last year':
                        $range = ['last year 1/1', 'this year 1/1'];
                        break;
                    default:
                        $range = $op;
                }
            }
            $op = is_array($range) ? 'between' : '>';
        }
        $this->where($field, strtolower($op) . ' time', $range);
        return $this;
    }

    /* *
 * Get table metadata
 * @access public
 * @param mixed $tableName Leave the table name blank will be obtained automatically
 * @param string $fetch The types of information to be obtained include fields type bind pk
 * @return mixed */
    public function getTableInfo($tableName = '', $fetch = '')
    {
        if (!$tableName) {
            $tableName = $this->getTable();
        }
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }

        if (strpos($tableName, ',')) {
            // Multiple tables do not get field information
            return false;
        } else {
            $tableName = $this->parseSqlTable($tableName);
        }

        // Fixed an issue with subqueries being used as table names
        if (strpos($tableName, ')')) {
            return [];
        }

        list($guid) = explode(' ', $tableName);
        $db         = $this->getConfig('database');
        if (!isset(self::$info[$db . '.' . $guid])) {
            if (!strpos($guid, '.')) {
                $schema = $db . '.' . $guid;
            } else {
                $schema = $guid;
            }
            // Read the cache
            if (!App::$debug && is_file(RUNTIME_PATH . 'schema/' . $schema . '.php')) {
                $info = include RUNTIME_PATH . 'schema/' . $schema . '.php';
            } else {
                $info = $this->connection->getFields($guid);
            }
            $fields = array_keys($info);
            $bind   = $type   = [];
            foreach ($info as $key => $val) {
                // Record field types
                $type[$key] = $val['type'];
                $bind[$key] = $this->getFieldBindType($val['type']);
                if (!empty($val['primary'])) {
                    $pk[] = $key;
                }
            }
            if (isset($pk)) {
                // Set primary key
                $pk = count($pk) > 1 ? $pk : $pk[0];
            } else {
                $pk = null;
            }
            self::$info[$db . '.' . $guid] = ['fields' => $fields, 'type' => $type, 'bind' => $bind, 'pk' => $pk];
        }
        return $fetch ? self::$info[$db . '.' . $guid][$fetch] : self::$info[$db . '.' . $guid];
    }

    /* *
 * Get the primary key of the current data table
 * @access public
 * @param string|array $options data table name or query parameters
 * @return string|array */
    public function getPk($options = '')
    {
        if (!empty($this->pk)) {
            $pk = $this->pk;
        } else {
            $pk = $this->getTableInfo(is_array($options) ? $options['table'] : $options, 'pk');
        }
        return $pk;
    }

    // Get current data table field information
    public function getTableFields($table = '')
    {
        return $this->getTableInfo($table ?: $this->getOptions('table'), 'fields');
    }

    // Get the current data table field type
    public function getFieldsType($table = '')
    {
        return $this->getTableInfo($table ?: $this->getOptions('table'), 'type');
    }

    // Get current data table binding information
    public function getFieldsBind($table = '')
    {
        $types = $this->getFieldsType($table);
        $bind  = [];
        if ($types) {
            foreach ($types as $key => $type) {
                $bind[$key] = $this->getFieldBindType($type);
            }
        }
        return $bind;
    }

    /* *
 * Get the field binding type
 * @access public
 * @param string $type field type
 * @return integer */
    protected function getFieldBindType($type)
    {
        if (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $bind = PDO::PARAM_STR;
        } elseif (preg_match('/(int|double|float|decimal|real|numeric|serial|bit)/is', $type)) {
            $bind = PDO::PARAM_INT;
        } elseif (preg_match('/bool/is', $type)) {
            $bind = PDO::PARAM_BOOL;
        } else {
            $bind = PDO::PARAM_STR;
        }
        return $bind;
    }

    /* *
 * Parameter binding
 * @access public
 * @param mixed $key parameter name
 * @param mixed $value bound variable value
 * @param integer $type Binding type
 * @return $this */
    public function bind($key, $value = false, $type = PDO::PARAM_STR)
    {
        if (is_array($key)) {
            $this->bind = array_merge($this->bind, $key);
        } else {
            $this->bind[$key] = [$value, $type];
        }
        return $this;
    }

    /* *
 * Check whether the parameters have been bound
 * @access public
 * @param string $key parameter name
 * @return bool */
    public function isBind($key)
    {
        return isset($this->bind[$key]);
    }

    /* *
 * Query parameter assignment
 * @access protected
 * @param array $options Query options parameters
 * @return $this */
    protected function options(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /* *
 * Get current query parameters
 * @access public
 * @param string $name parameter name
 * @return mixed */
    public function getOptions($name = '')
    {
        if ('' === $name) {
            return $this->options;
        } else {
            return isset($this->options[$name]) ? $this->options[$name] : null;
        }
    }

    /* *
 * Configure relation eager-loading
 * @access public
 * @param string|array $with Related method name
 * @return $this */
    public function with($with)
    {
        if (empty($with)) {
            return $this;
        }

        if (is_string($with)) {
            $with = explode(',', $with);
        }

        $first = true;

        /* * @var Model $class */
        $class = $this->model;
        foreach ($with as $key => $relation) {
            $subRelation = '';
            $closure     = false;
            if ($relation instanceof \Closure) {
                // Filter related query conditions using a closure
                $closure    = $relation;
                $relation   = $key;
                $with[$key] = $key;
            } elseif (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (is_string($relation) && strpos($relation, '.')) {
                $with[$key]                   = $relation;
                list($relation, $subRelation) = explode('.', $relation, 2);
            }

            /* * @var Relation $model */
            $relation = Loader::parseName($relation, 1, false);
            $model    = $class->$relation();
            if ($model instanceof OneToOne && 0 == $model->getEagerlyType()) {
                $model->eagerly($this, $relation, $subRelation, $closure, $first);
                $first = false;
            } elseif ($closure) {
                $with[$key] = $closure;
            }
        }
        $this->via();
        if (isset($this->options['with'])) {
            $this->options['with'] = array_merge($this->options['with'], $with);
        } else {
            $this->options['with'] = $with;
        }
        return $this;
    }

    /* *
 * Count related records
 * @access public
 * @param string|array $relation Relation method names
 * @param bool $subQuery whether to use subqueries
 * @return $this */
    public function withCount($relation, $subQuery = true)
    {
        if (!$subQuery) {
            $this->options['with_count'] = $relation;
        } else {
            $relations = is_string($relation) ? explode(',', $relation) : $relation;
            if (!isset($this->options['field'])) {
                $this->field('*');
            }
            foreach ($relations as $key => $relation) {
                $closure = $name = null;
                if ($relation instanceof \Closure) {
                    $closure  = $relation;
                    $relation = $key;
                } elseif (!is_int($key)) {
                    $name     = $relation;
                    $relation = $key;
                }
                $relation = Loader::parseName($relation, 1, false);

                $count = '(' . $this->model->$relation()->getRelationCountQuery($closure, $name) . ')';

                if (empty($name)) {
                    $name = Loader::parseName($relation) . '_count';
                }

                $this->field([$count => $name]);
            }
        }
        return $this;
    }

    /* *
 * Specify the fields selected during relation eager-loading
 * example:
 * Model::with([\'relation\' => function($query){
 * $query->withField(\"id,name\");
 * }])
 *
 * @param string|array $field specifies the fields to fetch
 * @return $this */
    public function withField($field)
    {
        $this->options['with_field'] = $field;
        return $this;
    }

    /* *
 * Set the table alias added to the current field
 * @access public
 * @param string $via
 * @return $this */
    public function via($via = '')
    {
        $this->options['via'] = $via;
        return $this;
    }

    /* *
 * Set up related queries
 * @access public
 * @param string|array $relation Associated name
 * @return $this */
    public function relation($relation)
    {
        if (empty($relation)) {
            return $this;
        }
        if (is_string($relation)) {
            $relation = explode(',', $relation);
        }
        if (isset($this->options['relation'])) {
            $this->options['relation'] = array_merge($this->options['relation'], $relation);
        } else {
            $this->options['relation'] = $relation;
        }
        return $this;
    }

    /* *
 * Converting primary key values to search conditions supports composite primary keys
 * @access public
 * @param array|string $data primary key data
 * @param mixed $options expression parameters
 * @return void
 * @throws Exception */
    protected function parsePkWhere($data, &$options)
    {
        $pk = $this->getPk($options);
        // Get the current table
        $table = is_array($options['table']) ? key($options['table']) : $options['table'];
        if (!empty($options['alias'][$table])) {
            $alias = $options['alias'][$table];
        }
        if (is_string($pk)) {
            $key = isset($alias) ? $alias . '.' . $pk : $pk;
            // Search by primary key
            if (is_array($data)) {
                $where[$key] = isset($data[$pk]) ? $data[$pk] : ['in', $data];
            } else {
                $where[$key] = strpos($data, ',') ? ['IN', $data] : $data;
            }
        } elseif (is_array($pk) && is_array($data) && !empty($data)) {
            // Querying based on composite primary keys
            foreach ($pk as $key) {
                if (isset($data[$key])) {
                    $attr         = isset($alias) ? $alias . '.' . $key : $key;
                    $where[$attr] = $data[$key];
                } else {
                    throw new Exception('miss complex primary data');
                }
            }
        }

        if (!empty($where)) {
            if (isset($options['where']['AND'])) {
                $options['where']['AND'] = array_merge($options['where']['AND'], $where);
            } else {
                $options['where']['AND'] = $where;
            }
        }
        return;
    }

    /* *
 * Insert records
 * @access public
 * @param mixed $data
 * @param boolean $replace Whether to replace
 * @param boolean $getLastInsID Whether to return the auto-increment primary key
 * @param string $sequence Auto-increment sequence name
 * @return integer|string */
    public function insert(array $data = [], $replace = false, $getLastInsID = false, $sequence = null)
    {
        // Analyzing query expressions
        $options = $this->parseExpress();
        $data    = array_merge($options['data'], $data);
        // Generating SQL statements
        $sql = $this->builder->insert($data, $options, $replace);
        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        }

        // Execute an action
        $result = 0 === $sql ? 0 : $this->execute($sql, $bind, $this);
        if ($result) {
            $sequence  = $sequence ?: (isset($options['sequence']) ? $options['sequence'] : null);
            $lastInsId = $this->getLastInsID($sequence);
            if ($lastInsId) {
                $pk = $this->getPk($options);
                if (is_string($pk)) {
                    $data[$pk] = $lastInsId;
                }
            }
            $options['data'] = $data;
            $this->trigger('after_insert', $options);

            if ($getLastInsID) {
                return $lastInsId;
            }
        }
        return $result;
    }

    /* *
 * Insert a record and return its auto-increment ID
 * @access public
 * @param mixed $data
 * @param boolean $replace Whether to replace
 * @param string $sequence Auto-increment sequence name
 * @return integer|string */
    public function insertGetId(array $data, $replace = false, $sequence = null)
    {
        return $this->insert($data, $replace, true, $sequence);
    }

    /* *
 * Insert records in batches
 * @access public
 * @param mixed $dataSet
 * @param boolean $replace Whether to replace
 * @param integer $limit limit per data write
 * @return integer|string */
    public function insertAll(array $dataSet, $replace = false, $limit = null)
    {
        // Analyzing query expressions
        $options = $this->parseExpress();
        if (!is_array(reset($dataSet))) {
            return false;
        }

        // Generating SQL statements
        if (is_null($limit)) {
            $sql = $this->builder->insertAll($dataSet, $options, $replace);
        } else {
            $array = array_chunk($dataSet, $limit, true);
            foreach ($array as $item) {
                $sql[] = $this->builder->insertAll($item, $options, $replace);
            }
        }

        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        } elseif (is_array($sql)) {
            // Execute an action
            return $this->batchQuery($sql, $bind, $this);
        } else {
            // Execute an action
            return $this->execute($sql, $bind, $this);
        }
    }

    /* *
 * Insert records by selecting
 * @access public
 * @param string $fields The name of the data table field to insert
 * @param string $table name of the data table to insert
 * @return integer|string
 * @throws PDOException */
    public function selectInsert($fields, $table)
    {
        // Analyzing query expressions
        $options = $this->parseExpress();
        // Generating SQL statements
        $table = $this->parseSqlTable($table);
        $sql   = $this->builder->selectInsert($fields, $table, $options);
        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        } else {
            // Execute an action
            return $this->execute($sql, $bind, $this);
        }
    }

    /* *
 * Update records
 * @access public
 * @param mixed $data
 * @return integer|string
 * @throws Exception
 * @throws PDOException */
    public function update(array $data = [])
    {
        $options = $this->parseExpress();
        $data    = array_merge($options['data'], $data);
        $pk      = $this->getPk($options);
        if (isset($options['cache']) && is_string($options['cache']['key'])) {
            $key = $options['cache']['key'];
        }

        if (empty($options['where'])) {
            // If primary key data exists, it is automatically used as an update condition
            if (is_string($pk) && isset($data[$pk])) {
                $where[$pk] = $data[$pk];
                if (!isset($key)) {
                    $key = 'think:' . $options['table'] . '|' . $data[$pk];
                }
                unset($data[$pk]);
            } elseif (is_array($pk)) {
                // Add composite primary key support
                foreach ($pk as $field) {
                    if (isset($data[$field])) {
                        $where[$field] = $data[$field];
                    } else {
                        // Does not execute if composite primary key data is missing
                        throw new Exception('miss complex primary data');
                    }
                    unset($data[$field]);
                }
            }
            if (!isset($where)) {
                // If there are no update conditions, it will not be executed
                throw new Exception('miss update condition');
            } else {
                $options['where']['AND'] = $where;
            }
        } elseif (!isset($key) && is_string($pk) && isset($options['where']['AND'][$pk])) {
            $key = $this->getCacheKey($options['where']['AND'][$pk], $options, $this->bind);
        }

        // Generating an UPDATE SQL statement
        $sql = $this->builder->update($data, $options);
        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        } else {
            // Test the cache
            if (isset($key) && Cache::get($key)) {
                // Delete the cache
                Cache::rm($key);
            } elseif (!empty($options['cache']['tag'])) {
                Cache::clear($options['cache']['tag']);
            }
            // Execute an action
            $result = '' == $sql ? 0 : $this->execute($sql, $bind, $this);
            if ($result) {
                if (is_string($pk) && isset($where[$pk])) {
                    $data[$pk] = $where[$pk];
                } elseif (is_string($pk) && isset($key) && strpos($key, '|')) {
                    list($a, $val) = explode('|', $key);
                    $data[$pk]     = $val;
                }
                $options['data'] = $data;
                $this->trigger('after_update', $options);
            }
            return $result;
        }
    }

    /* *
 * Get the PDOStatement for the query
 * @access public
 * @return \PDOStatement|string */
    public function getPdo()
    {
        // Analyzing query expressions
        $options = $this->parseExpress();
        // Generating SQL Queries
        $sql = $this->builder->select($options);
        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        }
        // Execute query operations
        return $this->query($sql, $bind, $options['master'], true);
    }

    /* *
 * Find records
 * @access public
 * @param array|string|Query|\Closure $data
 * @return Collection|false|\PDOStatement|string
 * @throws DbException
 * @throws ModelNotFoundException
 * @throws DataNotFoundException */
    public function select($data = null)
    {
        if ($data instanceof Query) {
            return $data->select();
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $this]);
            $data = null;
        }
        // Analyzing query expressions
        $options = $this->parseExpress();

        if (false === $data) {
            // Used for subqueries, only SQL is returned without querying
            $options['fetch_sql'] = true;
        } elseif (!is_null($data)) {
            // Parse primary key conditions
            $this->parsePkWhere($data, $options);
        }

        $resultSet = false;
        if (empty($options['fetch_sql']) && !empty($options['cache'])) {
            // Determine the query cache
            $cache = $options['cache'];
            unset($options['cache']);
            $key       = is_string($cache['key']) ? $cache['key'] : md5($this->connection->getConfig('database') . '.' . serialize($options) . serialize($this->bind));
            $resultSet = Cache::get($key);
        }
        if (false === $resultSet) {
            // Generating SQL Queries
            $sql = $this->builder->select($options);
            // Get parameter bindings
            $bind = $this->getBind();
            if ($options['fetch_sql']) {
                // Get the actual executed SQL statement
                return $this->connection->getRealSql($sql, $bind);
            }

            $options['data'] = $data;
            if ($resultSet = $this->trigger('before_select', $options)) {
            } else {
                // Execute query operations
                $resultSet = $this->query($sql, $bind, $options['master'], $options['fetch_pdo']);

                if ($resultSet instanceof \PDOStatement) {
                    // Return the PDOStatement object
                    return $resultSet;
                }
            }

            if (isset($cache) && false !== $resultSet) {
                // Caching data sets
                $this->cacheData($key, $resultSet, $cache);
            }
        }

        // Processing after reading the data list
        if (!empty($this->model)) {
            // Generating model objects
            if (count($resultSet) > 0) {
                foreach ($resultSet as $key => $result) {
                    /* * @var Model $model */
                    $model = $this->model->newInstance($result);
                    $model->isUpdate(true);

                    // Related Queries
                    if (!empty($options['relation'])) {
                        $model->relationQuery($options['relation']);
                    }
                    // Count related records
                    if (!empty($options['with_count'])) {
                        $model->relationCount($model, $options['with_count']);
                    }
                    $resultSet[$key] = $model;
                }
                if (!empty($options['with'])) {
                    // Eager-load relations
                    $model->eagerlyResultSet($resultSet, $options['with']);
                }
                // Model data set conversion
                $resultSet = $model->toCollection($resultSet);
            } else {
                $resultSet = $this->model->toCollection($resultSet);
            }
        } elseif ('collection' == $this->connection->getConfig('resultset_type')) {
            // Returns a Collection object
            $resultSet = new Collection($resultSet);
        }
        // Return result processing
        if (!empty($options['fail']) && count($resultSet) == 0) {
            $this->throwNotFound($options);
        }
        return $resultSet;
    }

    /* *
 * Caching data
 * @access public
 * @param string $key Cache ID
 * @param mixed $data cached data
 * @param array $config cache parameters */
    protected function cacheData($key, $data, $config = [])
    {
        if (isset($config['tag'])) {
            Cache::tag($config['tag'])->set($key, $data, $config['expire']);
        } else {
            Cache::set($key, $data, $config['expire']);
        }
    }

    /* *
 * Generate cache ID
 * @access public
 * @param mixed $value cached data
 * @param array $options cache parameters
 * @param array $bind binding parameters
 * @return string */
    protected function getCacheKey($value, $options, $bind = [])
    {
        if (is_scalar($value)) {
            $data = $value;
        } elseif (is_array($value) && is_string($value[0]) && 'eq' == strtolower($value[0])) {
            $data = $value[1];
        }
        $prefix = $this->connection->getConfig('database') . '.';

        if (isset($data)) {
            return 'think:' . $prefix . (is_array($options['table']) ? key($options['table']) : $options['table']) . '|' . $data;
        }

        try {
            return md5($prefix . serialize($options) . serialize($bind));
        } catch (\Exception $e) {
            throw new Exception('closure not support cache(true)');
        }
    }

    /* *
 * Find a single record
 * @access public
 * @param array|string|Query|\Closure $data
 * @return array|false|\PDOStatement|string|Model
 * @throws DbException
 * @throws ModelNotFoundException
 * @throws DataNotFoundException */
    public function find($data = null)
    {
        if ($data instanceof Query) {
            return $data->find();
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $this]);
            $data = null;
        }
        // Analyzing query expressions
        $options = $this->parseExpress();
        $pk      = $this->getPk($options);
        if (!is_null($data)) {
            // Convert primary key values into query conditions
            $this->parsePkWhere($data, $options);
        } elseif (!empty($options['cache']) && true === $options['cache']['key'] && is_string($pk) && isset($options['where']['AND'][$pk])) {
            $key = $this->getCacheKey($options['where']['AND'][$pk], $options, $this->bind);
        }

        $options['limit'] = 1;
        $result           = false;
        if (empty($options['fetch_sql']) && !empty($options['cache'])) {
            // Determine the query cache
            $cache = $options['cache'];
            if (true === $cache['key'] && !is_null($data) && !is_array($data)) {
                $key = 'think:' . $this->connection->getConfig('database') . '.' . (is_array($options['table']) ? key($options['table']) : $options['table']) . '|' . $data;
            } elseif (is_string($cache['key'])) {
                $key = $cache['key'];
            } elseif (!isset($key)) {
                $key = md5($this->connection->getConfig('database') . '.' . serialize($options) . serialize($this->bind));
            }
            $result = Cache::get($key);
        }
        if (false === $result) {
            // Generating SQL Queries
            $sql = $this->builder->select($options);
            // Get parameter bindings
            $bind = $this->getBind();
            if ($options['fetch_sql']) {
                // Get the actual executed SQL statement
                return $this->connection->getRealSql($sql, $bind);
            }
            if (is_string($pk)) {
                if (!is_array($data)) {
                    if (isset($key) && strpos($key, '|')) {
                        list($a, $val) = explode('|', $key);
                        $item[$pk]     = $val;
                    } else {
                        $item[$pk] = $data;
                    }
                    $data = $item;
                }
            }
            $options['data'] = $data;
            // Event callbacks
            if ($result = $this->trigger('before_find', $options)) {
            } else {
                // Execute the query
                $resultSet = $this->query($sql, $bind, $options['master'], $options['fetch_pdo']);

                if ($resultSet instanceof \PDOStatement) {
                    // Return the PDOStatement object
                    return $resultSet;
                }
                $result = isset($resultSet[0]) ? $resultSet[0] : null;
            }

            if (isset($cache) && $result) {
                // Caching data
                $this->cacheData($key, $result, $cache);
            }
        }

        // data processing
        if (!empty($result)) {
            if (!empty($this->model)) {
                // Returns the model object
                $result = $this->model->newInstance($result);
                $result->isUpdate(true, isset($options['where']['AND']) ? $options['where']['AND'] : null);
                // Related Queries
                if (!empty($options['relation'])) {
                    $result->relationQuery($options['relation']);
                }
                // Eager-load relations
                if (!empty($options['with'])) {
                    $result->eagerlyResult($result, $options['with']);
                }
                // Count related records
                if (!empty($options['with_count'])) {
                    $result->relationCount($result, $options['with_count']);
                }
            }
        } elseif (!empty($options['fail'])) {
            $this->throwNotFound($options);
        }
        return $result;
    }

    /* *
 * The query failed and an exception was thrown
 * @access public
 * @param array $options Query options
 * @throws ModelNotFoundException
 * @throws DataNotFoundException */
    protected function throwNotFound($options = [])
    {
        if (!empty($this->model)) {
            $class = get_class($this->model);
            throw new ModelNotFoundException('model data Not Found:' . $class, $class, $options);
        } else {
            $table = is_array($options['table']) ? key($options['table']) : $options['table'];
            throw new DataNotFoundException('table data not Found:' . $table, $table, $options);
        }
    }

    /* *
 * Find multiple records and throw an exception if they don't exist
 * @access public
 * @param array|string|Query|\Closure $data
 * @return array|\PDOStatement|string|Model
 * @throws DbException
 * @throws ModelNotFoundException
 * @throws DataNotFoundException */
    public function selectOrFail($data = null)
    {
        return $this->failException(true)->select($data);
    }

    /* *
 * Find a single record and throw an exception if it doesn't exist
 * @access public
 * @param array|string|Query|\Closure $data
 * @return array|\PDOStatement|string|Model
 * @throws DbException
 * @throws ModelNotFoundException
 * @throws DataNotFoundException */
    public function findOrFail($data = null)
    {
        return $this->failException(true)->find($data);
    }

    /* *
 * Process query results in batches
 * @access public
 * @param integer $count The number of data processed each time
 * @param callable $callback processing callback method
 * @param string $column field name for batch processing
 * @param string $order Sorting rules
 * @return boolean
 * @throws \LogicException */
    public function chunk($count, $callback, $column = null, $order = 'asc')
    {
        $options = $this->getOptions();
        if (empty($options['table'])) {
            $options['table'] = $this->getTable();
        }
        $column = $column ?: $this->getPk($options);

        if (isset($options['order'])) {
            if (App::$debug) {
                throw new \LogicException('chunk not support call order');
            }
            unset($options['order']);
        }
        $bind = $this->bind;
        if (is_array($column)) {
            $times = 1;
            $query = $this->options($options)->page($times, $count);
        } else {
            if (strpos($column, '.')) {
                list($alias, $key) = explode('.', $column);
            } else {
                $key = $column;
            }
            $query = $this->options($options)->limit($count);
        }
        $resultSet = $query->order($column, $order)->select();

        while (count($resultSet) > 0) {
            if ($resultSet instanceof Collection) {
                $resultSet = $resultSet->all();
            }

            if (false === call_user_func($callback, $resultSet)) {
                return false;
            }

            if (is_array($column)) {
                $times++;
                $query = $this->options($options)->page($times, $count);
            } else {
                $end    = end($resultSet);
                $lastId = is_array($end) ? $end[$key] : $end->getData($key);
                $query  = $this->options($options)
                    ->limit($count)
                    ->where($column, 'asc' == strtolower($order) ? '>' : '<', $lastId);
            }

            $resultSet = $query->bind($bind)->order($column, $order)->select();
        }

        return true;
    }

    /* *
 * Get the bound parameters and clear them
 * @access public
 * @return array */
    public function getBind()
    {
        $bind       = $this->bind;
        $this->bind = [];
        return $bind;
    }

    /* *
 * Create a subquery SQL
 * @access public
 * @param bool $sub
 * @return string
 * @throws DbException */
    public function buildSql($sub = true)
    {
        return $sub ? '( ' . $this->select(false) . ' )' : $this->select(false);
    }

    /* *
 * Delete records
 * @access public
 * @param mixed $data expression true indicates forced deletion
 * @return int
 * @throws Exception
 * @throws PDOException */
    public function delete($data = null)
    {
        // Analyzing query expressions
        $options = $this->parseExpress();
        $pk      = $this->getPk($options);
        if (isset($options['cache']) && is_string($options['cache']['key'])) {
            $key = $options['cache']['key'];
        }

        if (!is_null($data) && true !== $data) {
            if (!isset($key) && !is_array($data)) {
                // cache ID
                $key = 'think:' . $options['table'] . '|' . $data;
            }
            // Convert primary key values into query conditions
            $this->parsePkWhere($data, $options);
        } elseif (!isset($key) && is_string($pk) && isset($options['where']['AND'][$pk])) {
            $key = $this->getCacheKey($options['where']['AND'][$pk], $options, $this->bind);
        }

        if (true !== $data && empty($options['where'])) {
            // If the condition is empty, no delete operation is performed unless 1=1 is set
            throw new Exception('delete without condition');
        }
        // Generate the DELETE statement
        $sql = $this->builder->delete($options);
        // Get parameter bindings
        $bind = $this->getBind();
        if ($options['fetch_sql']) {
            // Get the actual executed SQL statement
            return $this->connection->getRealSql($sql, $bind);
        }

        // Test the cache
        if (isset($key) && Cache::get($key)) {
            // Delete the cache
            Cache::rm($key);
        } elseif (!empty($options['cache']['tag'])) {
            Cache::clear($options['cache']['tag']);
        }
        // Execute an action
        $result = $this->execute($sql, $bind, $this);
        if ($result) {
            if (!is_array($data) && is_string($pk) && isset($key) && strpos($key, '|')) {
                list($a, $val) = explode('|', $key);
                $item[$pk]     = $val;
                $data          = $item;
            }
            $options['data'] = $data;
            $this->trigger('after_delete', $options);
        }
        return $result;
    }

    /* *
 * Prepare query options for reads or writes
 * @access protected
 * @return array */
    protected function parseExpress()
    {
        $options = $this->options;

        // Get the table
        if (empty($options['table'])) {
            $options['table'] = $this->getTable();
        }

        if (!isset($options['where'])) {
            $options['where'] = [];
        } elseif (isset($options['view'])) {
            // View query condition processing
            foreach (['AND', 'OR'] as $logic) {
                if (isset($options['where'][$logic])) {
                    foreach ($options['where'][$logic] as $key => $val) {
                        if (array_key_exists($key, $options['map'])) {
                            $options['where'][$logic][$options['map'][$key]] = $val;
                            unset($options['where'][$logic][$key]);
                        }
                    }
                }
            }

            if (isset($options['order'])) {
                // View query sorting processing
                if (is_string($options['order'])) {
                    $options['order'] = explode(',', $options['order']);
                }
                foreach ($options['order'] as $key => $val) {
                    if (is_numeric($key)) {
                        if (strpos($val, ' ')) {
                            list($field, $sort) = explode(' ', $val);
                            if (array_key_exists($field, $options['map'])) {
                                $options['order'][$options['map'][$field]] = $sort;
                                unset($options['order'][$key]);
                            }
                        } elseif (array_key_exists($val, $options['map'])) {
                            $options['order'][$options['map'][$val]] = 'asc';
                            unset($options['order'][$key]);
                        }
                    } elseif (array_key_exists($key, $options['map'])) {
                        $options['order'][$options['map'][$key]] = $val;
                        unset($options['order'][$key]);
                    }
                }
            }
        }

        if (!isset($options['field'])) {
            $options['field'] = '*';
        }

        if (!isset($options['data'])) {
            $options['data'] = [];
        }

        if (!isset($options['strict'])) {
            $options['strict'] = $this->getConfig('fields_strict');
        }

        foreach (['master', 'lock', 'fetch_pdo', 'fetch_sql', 'distinct'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = false;
            }
        }

        if (isset(static::$readMaster['*']) || (is_string($options['table']) && isset(static::$readMaster[$options['table']]))) {
            $options['master'] = true;
        }

        foreach (['join', 'union', 'group', 'having', 'limit', 'order', 'force', 'comment'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = '';
            }
        }

        if (isset($options['page'])) {
            // Calculate the row limit from the page number
            list($page, $listRows) = $options['page'];
            $page                  = $page > 0 ? $page : 1;
            $listRows              = $listRows > 0 ? $listRows : (is_numeric($options['limit']) ? $options['limit'] : 20);
            $offset                = $listRows * ($page - 1);
            $options['limit']      = $offset . ',' . $listRows;
        }

        $this->options = [];
        return $options;
    }

    /* *
 * Register an event callback
 * @access public
 * @param string $event event name
 * @param callable $callback callback method
 * @return void */
    public static function event($event, $callback)
    {
        self::$event[$event] = $callback;
    }

    /* *
 * Trigger event
 * @access protected
 * @param string $event event name
 * @param mixed $params Additional parameters
 * @return bool */
    protected function trigger($event, $params = [])
    {
        $result = false;
        if (isset(self::$event[$event])) {
            $callback = self::$event[$event];
            $result   = call_user_func_array($callback, [$params, $this]);
        }
        return $result;
    }
}
