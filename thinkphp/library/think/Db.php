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

namespace think;

use think\db\Connection;
use think\db\Query;

/* *
 * Class Db
 * @package think
 * @method Query table(string $table) static Specify the data table (including prefix)
 * @method Query name (string $name) static specifies the data table (without prefix)
 * @method Query where(mixed $field, string $op = null, mixed $condition = null) static Query conditions
 * @method Query join(mixed $join, mixed $condition = null, string $type = \'INNER\') static Join query
 * @method Query union(mixed $union, boolean $all = false) static Union query
 * @method Query limit(mixed $offset, integer $length = null) static Query Limit
 * @method Query order(mixed $field, string $order = null) static Query order
 * @method Query cache(mixed $key = null , integer $expire = null) static Set the query cache
 * @method mixed value(string $field) static Get the value of a field
 * @method array column(string $field, string $key = \'\') static Get the value of a column
 * @method Query view(mixed $join, mixed $field = null, mixed $on = null, string $type = \'INNER\') static View query
 * @method mixed find(mixed $data = null) static Query a single record
 * @method mixed select(mixed $data = null) static Query multiple records
 * @method integer insert(array $data, boolean $replace = false, boolean $getLastInsID = false, string $sequence = null) static Insert a record
 * @method integer insertGetId(array $data, boolean $replace = false, string $sequence = null) static Insert a record and return its auto-increment ID
 * @method integer insertAll(array $dataSet) static Insert multiple records
 * @method integer update(array $data) static Update a record
 * @method integer delete(mixed $data = null) static Delete a record
 * @method boolean chunk(integer $count, callable $callback, string $column = null) static Read records in chunks
 * @method mixed query(string $sql, array $bind = [], boolean $master = false, bool $pdo = false) static SQL query
 * @method integer execute(string $sql, array $bind = [], boolean $fetch = false, boolean $getLastInsID = false, string $sequence = null) static SQL execution
 * @method Paginator paginate(integer $listRows = 15, mixed $simple = null, array $config = []) static Query paginated records
 * @method mixed transaction(callable $callback) static Perform database transactions
 * @method void startTrans () static Start a transaction
 * @method void commit () static Commit a transaction
 * @method void rollback() static Roll back a transaction
 * @method boolean batchQuery(array $sqlArray) static Execute SQL statements in a batch
 * @method string quote(string $str) static Quote a string for SQL
 * @method string getLastInsID($sequence = null) static Get the last inserted ID */
class Db
{
    /* *
 * @var Connection[] Database connection instances */
    private static $instance = [];

    /* *
 * @var int number of queries */
    public static $queryTimes = 0;

    /* *
 * @var int Number of execution operations */
    public static $executeTimes = 0;

    /* *
 * Get a database connection instance
 * @access public
 * @param mixed $config connection configuration
 * @param bool|string $name Connection identifier; true creates a new connection
 * @return Connection
 * @throws Exception */
    public static function connect($config = [], $name = false)
    {
        if (false === $name) {
            $name = md5(serialize($config));
        }

        if (true === $name || !isset(self::$instance[$name])) {
            // Parse the connection configuration, supplied as an array or string
            $options = self::parseConfig($config);

            if (empty($options['type'])) {
                throw new \InvalidArgumentException('Undefined db type');
            }

            $class = false !== strpos($options['type'], '\\') ?
            $options['type'] :
            '\\think\\db\\connector\\' . ucwords($options['type']);

            // Record initialization information
            if (App::$debug) {
                Log::record('[ DB ] INIT ' . $options['type'], 'info');
            }

            if (true === $name) {
                $name = md5(serialize($config));
            }

            self::$instance[$name] = new $class($options);
        }

        return self::$instance[$name];
    }

    /* *
 * Clear stored connection instances
 * @access public
 * @return void */
    public static function clear()
    {
        self::$instance = [];
    }

    /* *
 * Parse database connection parameters
 * @access private
 * @param mixed $config connection parameters
 * @return array */
    private static function parseConfig($config)
    {
        if (empty($config)) {
            $config = Config::get('database');
        } elseif (is_string($config) && false === strpos($config, '/')) {
            $config = Config::get($config); // Supports reading configuration parameters
        }

        return is_string($config) ? self::parseDsn($config) : $config;
    }

    /* *
 * Parse a DSN string
 * Format: mysql://username:passwd@localhost:3306/dbName?param1=val1&param2=val2#utf8
 * @access private
 * @param string $dsnStr Database DSN string
 * @return array */
    private static function parseDsn($dsnStr)
    {
        $info = parse_url($dsnStr);

        if (!$info) {
            return [];
        }

        $dsn = [
            'type'     => $info['scheme'],
            'username' => isset($info['user']) ? $info['user'] : '',
            'password' => isset($info['pass']) ? $info['pass'] : '',
            'hostname' => isset($info['host']) ? $info['host'] : '',
            'hostport' => isset($info['port']) ? $info['port'] : '',
            'database' => !empty($info['path']) ? ltrim($info['path'], '/') : '',
            'charset'  => isset($info['fragment']) ? $info['fragment'] : 'utf8',
        ];

        if (isset($info['query'])) {
            parse_str($info['query'], $dsn['params']);
        } else {
            $dsn['params'] = [];
        }

        return $dsn;
    }

    /* *
 * Call the driver class method
 * @access public
 * @param string $method method name
 * @param array $params parameters
 * @return mixed */
    public static function __callStatic($method, $params)
    {
        return call_user_func_array([self::connect(), $method], $params);
    }
}
