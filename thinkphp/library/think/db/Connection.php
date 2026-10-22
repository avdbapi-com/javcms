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
use PDOStatement;
use think\Db;
use think\db\exception\BindParamException;
use think\Debug;
use think\Exception;
use think\exception\PDOException;
use think\Log;

/* *
 * Class Connection
 * @package think\db
 * @method Query table(string $table) Specify a table name including its prefix
 * @method Query name(string $name) Specify a table name without its prefix
 * */
abstract class Connection
{

    /* * @var PDOStatement Current prepared statement */
    protected $PDOStatement;

    /* * @var string Current SQL statement */
    protected $queryStr = '';
    // Number of returned or affected rows
    protected $numRows = 0;
    // Transaction nesting level
    protected $transTimes = 0;
    // Last error message
    protected $error = '';

    /* * @var PDO[] Database connections indexed by connection number */
    protected $links = [];

    /* * @var PDO Current database connection */
    protected $linkID;
    protected $linkRead;
    protected $linkWrite;

    // Type of query result
    protected $fetchType = PDO::FETCH_ASSOC;
    // Column-name letter case
    protected $attrCase = PDO::CASE_LOWER;
    // SQL listener callbacks
    protected static $event = [];
    // SQL builder class
    protected $builder;
    // Database connection parameter configuration
    protected $config = [
        // database type
        'type'            => '',
        // server address
        'hostname'        => '',
        // database name
        'database'        => '',
        // username
        'username'        => '',
        // passwords
        'password'        => '',
        // ports
        'hostport'        => '',
        // Connection DSN
        'dsn'             => '',
        // database connection parameters
        'params'          => [],
        // Database character encoding; defaults to utf8
        'charset'         => 'utf8',
        // database table prefix
        'prefix'          => '',
        // database debugging mode
        'debug'           => false,
        // Database deployment method: 0 centralized (single server), 1 distributed (master/slave server)
        'deploy'          => 0,
        // Whether master/slave read and write separation is enabled
        'rw_separate'     => false,
        // Number of primary servers after read/write separation
        'master_num'      => 1,
        // Selected slave server index
        'slave_no'        => '',
        // Read from the master server after a model write
        'read_master'     => false,
        // Whether to strictly check whether the field exists
        'fields_strict'   => true,
        // Data return type
        'result_type'     => PDO::FETCH_ASSOC,
        // Data set return type
        'resultset_type'  => 'array',
        // Automatically write to the timestamp field
        'auto_timestamp'  => false,
        // Default output format for date and time fields
        'datetime_format' => 'Y-m-d H:i:s',
        // Whether to analyze SQL query performance
        'sql_explain'     => false,
        // Builder Type
        'builder'         => '',
        // Query class
        'query'           => '\\think\\db\\Query',
        // Whether to reconnect after a lost connection
        'break_reconnect' => false,
    ];

    // PDO connection parameters
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES  => false,
    ];

    // Binding parameters
    protected $bind = [];

    /* *
 * The constructor reads database configuration information
 * @access public
 * @param array $config array of database configurations */
    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    /* *
 * Create a query object for this connection
 * @access protected
 * @return Query */
    protected function getQuery()
    {
        $class = $this->config['query'];
        return new $class($this);
    }

    /* *
 * Get the Builder class corresponding to the current connector class
 * @access public
 * @return string */
    public function getBuilder()
    {
        if (!empty($this->builder)) {
            return $this->builder;
        } else {
            return $this->getConfig('builder') ?: '\\think\\db\\builder\\' . ucfirst($this->getConfig('type'));
        }
    }

    /* *
 * Call the query method of the Query class
 * @access public
 * @param string $method name
 * @param array $args call parameters
 * @return mixed */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->getQuery(), $method], $args);
    }

    /* *
 * Build the PDO connection DSN
 * @access protected
 * @param array $config connection information
 * @return string */
    abstract protected function parseDsn($config);

    /* *
 * Get table column metadata
 * @access public
 * @param string $tableName
 * @return array */
    abstract public function getFields($tableName);

    /* *
 * Get database table names
 * @access public
 * @param string $dbName
 * @return array */
    abstract public function getTables($dbName);

    /* *
 * Analyze SQL query performance
 * @access protected
 * @param string $sql
 * @return array */
    abstract protected function getExplain($sql);

    /* *
 * Apply the configured letter case to column metadata keys
 * @access public
 * @param array $info field info
 * @return array */
    public function fieldCase($info)
    {
        // Field case conversion
        switch ($this->attrCase) {
            case PDO::CASE_LOWER:
                $info = array_change_key_case($info);
                break;
            case PDO::CASE_UPPER:
                $info = array_change_key_case($info, CASE_UPPER);
                break;
            case PDO::CASE_NATURAL:
            default:
                // No conversion
        }
        return $info;
    }

    /* *
 * Get database configuration parameters
 * @access public
 * @param string $config configuration name
 * @return mixed */
    public function getConfig($config = '')
    {
        return $config ? $this->config[$config] : $this->config;
    }

    /* *
 * Set database configuration parameters
 * @access public
 * @param string|array $config configuration name
 * @param mixed $value configuration value
 * @return void */
    public function setConfig($config, $value = '')
    {
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        } else {
            $this->config[$config] = $value;
        }
    }

    /* *
 * Connect to the database
 * @access public
 * @param array $config connection parameters
 * @param integer $linkNum connection number
 * @param array|bool $autoConnection Master connection configuration used for distributed connection fallback
 * @return PDO
 * @throws Exception */
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false)
    {
        if (!isset($this->links[$linkNum])) {
            if (!$config) {
                $config = $this->config;
            } else {
                $config = array_merge($this->config, $config);
            }
            // connection parameters
            if (isset($config['params']) && is_array($config['params'])) {
                $params = $config['params'] + $this->params;
            } else {
                $params = $this->params;
            }
            // Record the configured column-name letter case
            $this->attrCase = $params[PDO::ATTR_CASE];

            // Data return type
            if (isset($config['result_type'])) {
                $this->fetchType = $config['result_type'];
            }
            try {
                if (empty($config['dsn'])) {
                    $config['dsn'] = $this->parseDsn($config);
                }
                if ($config['debug']) {
                    $startTime = microtime(true);
                }
                $this->links[$linkNum] = new PDO($config['dsn'], $config['username'], $config['password'], $params);
                if ($config['debug']) {
                    // Record database connection information
                    Log::record('[ DB ] CONNECT:[ UseTime:' . number_format(microtime(true) - $startTime, 6) . 's ] ' . $config['dsn'], 'sql');
                }
            } catch (\PDOException $e) {
                if ($autoConnection) {
                    Log::record($e->getMessage(), 'error');
                    return $this->connect($autoConnection, $linkNum);
                } else {
                    throw $e;
                }
            }
        }
        return $this->links[$linkNum];
    }

    /* *
 * Release query results
 * @access public */
    public function free()
    {
        $this->PDOStatement = null;
    }

    /* *
 * Get the current PDO connection
 * @access public
 * @return PDO|false */
    public function getPdo()
    {
        if (!$this->linkID) {
            return false;
        } else {
            return $this->linkID;
        }
    }

    /* *
 * Execute the query to return the data set
 * @access public
 * @param string $sql sql instructions
 * @param array $bind parameter binding
 * @param bool $master Whether to read from the master server
 * @param bool $pdo Whether to return the PDOStatement object
 * @return mixed
 * @throws PDOException
 * @throws\Exception */
    public function query($sql, $bind = [], $master = false, $pdo = false)
    {
        $this->initConnect($master);
        if (!$this->linkID) {
            return false;
        }

        // Record SQL statements
        $this->queryStr = $sql;
        if ($bind) {
            $this->bind = $bind;
        }

        Db::$queryTimes++;
        try {
            // Start query timing
            $this->debug(true);

            // Prepare the SQL statement
            $this->PDOStatement = $this->linkID->prepare($sql);

            // Whether it is a stored procedure call
            $procedure = in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);
            // parameter binding
            if ($procedure) {
                $this->bindParam($bind);
            } else {
                $this->bindValue($bind);
            }
            // Execute the query
            $this->PDOStatement->execute();
            // Finish query timing
            $this->debug(false, '', $master);
            // Return the query results
            return $this->getResult($pdo, $procedure);
        } catch (\PDOException $e) {
            if ($this->isBreak($e)) {
                return $this->close()->query($sql, $bind, $master, $pdo);
            }
            throw new PDOException($e, $this->config, $this->getLastsql());
        } catch (\Throwable $e) {
            if ($this->isBreak($e)) {
                return $this->close()->query($sql, $bind, $master, $pdo);
            }
            throw $e;
        } catch (\Exception $e) {
            if ($this->isBreak($e)) {
                return $this->close()->query($sql, $bind, $master, $pdo);
            }
            throw $e;
        }
    }

    /* *
 * Execute statement
 * @access public
 * @param string $sql SQL instruction
 * @param array $bind parameter binding
 * @param Query $query object
 * @return int
 * @throws PDOException
 * @throws \Exception */
    public function execute($sql, $bind = [], ?Query $query = null)
    {
        $this->initConnect(true);
        if (!$this->linkID) {
            return false;
        }

        // Record SQL statements
        $this->queryStr = $sql;
        if ($bind) {
            $this->bind = $bind;
        }

        Db::$executeTimes++;
        try {
            // Start query timing
            $this->debug(true);

            // Prepare the SQL statement
            $this->PDOStatement = $this->linkID->prepare($sql);

            // Whether it is a stored procedure call
            $procedure = in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);
            // parameter binding
            if ($procedure) {
                $this->bindParam($bind);
            } else {
                $this->bindValue($bind);
            }
            // Execute statement
            $this->PDOStatement->execute();
            // Finish query timing
            $this->debug(false, '', true);

            if ($query && !empty($this->config['deploy']) && !empty($this->config['read_master'])) {
                $query->readMaster();
            }

            $this->numRows = $this->PDOStatement->rowCount();
            return $this->numRows;
        } catch (\PDOException $e) {
            if ($this->isBreak($e)) {
                return $this->close()->execute($sql, $bind, $query);
            }
            throw new PDOException($e, $this->config, $this->getLastsql());
        } catch (\Throwable $e) {
            if ($this->isBreak($e)) {
                return $this->close()->execute($sql, $bind, $query);
            }
            throw $e;
        } catch (\Exception $e) {
            if ($this->isBreak($e)) {
                return $this->close()->execute($sql, $bind, $query);
            }
            throw $e;
        }
    }

    /* *
 * Assemble the final SQL statement according to parameter bindings for easy debugging
 * @access public
 * @param string $sql SQL statement with parameter bindings
 * @param array $bind list of parameter bindings
 * @return string */
    public function getRealSql($sql, array $bind = [])
    {
        if (is_array($sql)) {
            $sql = implode(';', $sql);
        }

        foreach ($bind as $key => $val) {
            $value = is_array($val) ? $val[0] : $val;
            $type  = is_array($val) ? $val[1] : PDO::PARAM_STR;
            if (PDO::PARAM_STR == $type) {
                $value = $this->quote($value);
            } elseif (PDO::PARAM_INT == $type) {
                $value = (float) $value;
            }
            // Replace positional or named placeholders
            $sql = is_numeric($key) ?
            substr_replace($sql, $value, strpos($sql, '?'), 1) :
            str_replace(
                [':' . $key . ')', ':' . $key . ',', ':' . $key . ' ', ':' . $key . PHP_EOL],
                [$value . ')', $value . ',', $value . ' ', $value . PHP_EOL],
                $sql . ' ');
        }
        return rtrim($sql);
    }

    /* *
 * Parameter binding
 * Named placeholders support ['name' => 'value', 'id' => 123]
 * Positional question-mark placeholders support ['value', 123]
 * @access public
 * @param array $bind list of parameters to bind
 * @return void
 * @throws BindParamException */
    protected function bindValue(array $bind = [])
    {
        foreach ($bind as $key => $val) {
            // placeholder
            $param = is_numeric($key) ? $key + 1 : ':' . $key;
            if (is_array($val)) {
                if (PDO::PARAM_INT == $val[1] && '' === $val[0]) {
                    $val[0] = 0;
                }
                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }
            if (!$result) {
                throw new BindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->getLastsql(),
                    $bind
                );
            }
        }
    }

    /* *
 * Input and output parameter binding for stored procedures
 * @access public
 * @param array $bind list of parameters to bind
 * @return void
 * @throws BindParamException */
    protected function bindParam($bind)
    {
        foreach ($bind as $key => $val) {
            $param = is_numeric($key) ? $key + 1 : ':' . $key;
            if (is_array($val)) {
                array_unshift($val, $param);
                $result = call_user_func_array([$this->PDOStatement, 'bindParam'], $val);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }
            if (!$result) {
                $param = array_shift($val);
                throw new BindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->getLastsql(),
                    $bind
                );
            }
        }
    }

    /* *
 * Get the query results
 * @access protected
 * @param bool $pdo Whether to return the PDOStatement object
 * @param bool $procedure Whether this is a stored procedure call
 * @return PDOStatement|array */
    protected function getResult($pdo = false, $procedure = false)
    {
        if ($pdo) {
            // Return the PDOStatement object
            return $this->PDOStatement;
        }
        if ($procedure) {
            // Stored procedures return results
            return $this->procedure();
        }
        $result        = $this->PDOStatement->fetchAll($this->fetchType);
        $this->numRows = count($result);
        return $result;
    }

    /* *
 * Get result sets returned by a stored procedure
 * @access protected
 * @return array */
    protected function procedure()
    {
        $item = [];
        do {
            $result = $this->getResult();
            if ($result) {
                $item[] = $result;
            }
        } while ($this->PDOStatement->nextRowset());
        $this->numRows = count($item);
        return $item;
    }

    /* *
 * Execute database transactions
 * @access public
 * @param callable $callback Transaction callback
 * @return mixed
 * @throws PDOException
 * @throws \Exception
 * @throws \Throwable */
    public function transaction($callback)
    {
        $this->startTrans();
        try {
            $result = null;
            if (is_callable($callback)) {
                $result = call_user_func_array($callback, [$this]);
            }
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /* *
 * Initiate a transaction
 * @access public
 * @return bool|mixed
 * @throws \Exception */
    public function startTrans()
    {
        $this->initConnect(true);
        if (!$this->linkID) {
            return false;
        }

        ++$this->transTimes;
        try {
            if (1 == $this->transTimes) {
                $this->linkID->beginTransaction();
            } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
                $this->linkID->exec(
                    $this->parseSavepoint('trans' . $this->transTimes)
                );
            }

        } catch (\Exception $e) {
            if ($this->isBreak($e)) {
                --$this->transTimes;
                return $this->close()->startTrans();
            }
            throw $e;
        } catch (\Error $e) {
            if ($this->isBreak($e)) {
                --$this->transTimes;
                return $this->close()->startTrans();
            }
            throw $e;
        }
    }

    /* *
 * Commit the current transaction
 * @access public
 * @return void
 * @throws PDOException */
    public function commit()
    {
        $this->initConnect(true);

        if (1 == $this->transTimes) {
            $this->linkID->commit();
        }

        --$this->transTimes;
    }

    /* *
 * Transaction rollback
 * @access public
 * @return void
 * @throws PDOException */
    public function rollback()
    {
        $this->initConnect(true);

        if (1 == $this->transTimes) {
            $this->linkID->rollBack();
        } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
            $this->linkID->exec(
                $this->parseSavepointRollBack('trans' . $this->transTimes)
            );
        }

        $this->transTimes = max(0, $this->transTimes - 1);
    }

    /* *
 * Whether to support nested transactions
 * @return bool */
    protected function supportSavepoint()
    {
        return false;
    }

    /* *
 * Generate SQL defining savepoints
 * @param $name
 * @return string */
    protected function parseSavepoint($name)
    {
        return 'SAVEPOINT ' . $name;
    }

    /* *
 * Generate SQL to roll back to the savepoint
 * @param $name
 * @return string */
    protected function parseSavepointRollBack($name)
    {
        return 'ROLLBACK TO SAVEPOINT ' . $name;
    }

    /* *
 * Execute SQL statements in batches
 * All batch instructions are considered execute operations
 * @access public
 * @param array $sqlArray SQL batch instructions
 * @return boolean */
    public function batchQuery($sqlArray = [], $bind = [], ?Query $query = null)
    {
        if (!is_array($sqlArray)) {
            return false;
        }
        // Start a transaction automatically
        $this->startTrans();
        try {
            foreach ($sqlArray as $sql) {
                $this->execute($sql, $bind, $query);
            }
            // Commit the transaction
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }

        return true;
    }

    /* *
 * Get the query count
 * @access public
 * @param boolean $execute Whether to include write statements in the count
 * @return integer */
    public function getQueryTimes($execute = false)
    {
        return $execute ? Db::$queryTimes + Db::$executeTimes : Db::$queryTimes;
    }

    /* *
 * Get the write statement count
 * @access public
 * @return integer */
    public function getExecuteTimes()
    {
        return Db::$executeTimes;
    }

    /* *
 * Close the database (or reconnect)
 * @access public
 * @return $this */
    public function close()
    {
        $this->linkID    = null;
        $this->linkWrite = null;
        $this->linkRead  = null;
        $this->links     = [];
        // Release a query
        $this->free();
        return $this;
    }

    /* *
 * Detect a lost database connection
 * @access protected
 * @param \Exception|\Throwable $e Exception to inspect
 * @return bool */
    protected function isBreak($e)
    {
        if (!$this->config['break_reconnect']) {
            return false;
        }

        $info = [
            'server has gone away',
            'no connection to the server',
            'Lost connection',
            'is dead or not enabled',
            'Error while sending',
            'decryption failed or bad record mac',
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'Resource deadlock avoided',
            'failed with errno',
        ];

        $error = $e->getMessage();

        foreach ($info as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }
        return false;
    }

    /* *
 * Get the SQL statement of the most recent query
 * @access public
 * @return string */
    public function getLastSql()
    {
        return $this->getRealSql($this->queryStr, $this->bind);
    }

    /* *
 * Get the last inserted ID
 * @access public
 * @param string $sequence Auto-increment sequence name
 * @return string */
    public function getLastInsID($sequence = null)
    {
        return $this->linkID->lastInsertId($sequence);
    }

    /* *
 * Get the number of records returned or affected
 * @access public
 * @return integer */
    public function getNumRows()
    {
        return $this->numRows;
    }

    /* *
 * Get recent error information
 * @access public
 * @return string */
    public function getError()
    {
        if ($this->PDOStatement) {
            $error = $this->PDOStatement->errorInfo();
            $error = $error[1] . ':' . $error[2];
        } else {
            $error = '';
        }
        if ('' != $this->queryStr) {
            $error .= "\n [ SQL statement ] : " . $this->getLastsql();
        }
        return $error;
    }

    /* *
 * Quote a string for use in SQL
 * @access public
 * @param string $str SQL string
 * @param bool $master Whether to use the master connection
 * @return string */
    public function quote($str, $master = true)
    {
        $this->initConnect($master);
        return $this->linkID ? $this->linkID->quote($str) : $str;
    }

    /* *
 * Database debugging records current SQL and analysis performance
 * @access protected
 * @param boolean $start debugging start flag true start false end
 * @param string $sql The SQL statement executed is automatically obtained by leaving it blank
 * @param boolean $master Whether the master connection was used
 * @return void */
    protected function debug($start, $sql = '', $master = false)
    {
        if (!empty($this->config['debug'])) {
            // Start database debugging mode
            if ($start) {
                Debug::remark('queryStartTime', 'time');
            } else {
                // Record the end time of the operation
                Debug::remark('queryEndTime', 'time');
                $runtime = Debug::getRangeTime('queryStartTime', 'queryEndTime');
                $sql     = $sql ?: $this->getLastsql();
                $result  = [];
                // Analyze SQL query performance
                if ($this->config['sql_explain'] && 0 === stripos(trim($sql), 'select')) {
                    $result = $this->getExplain($sql);
                }
                // Notify SQL listeners
                $this->trigger($sql, $runtime, $result, $master);
            }
        }
    }

    /* *
 * Listen to SQL execution
 * @access public
 * @param callable $callback callback method
 * @return void */
    public function listen($callback)
    {
        self::$event[] = $callback;
    }

    /* *
 * Trigger an SQL event
 * @access protected
 * @param string $sql SQL statement
 * @param float $runtime SQL runtime
 * @param mixed $explain SQL execution plan
 * @param bool $master Whether the master connection was used
 * @return void */
    protected function trigger($sql, $runtime, $explain = [], $master = false)
    {
        if (!empty(self::$event)) {
            foreach (self::$event as $callback) {
                if (is_callable($callback)) {
                    call_user_func_array($callback, [$sql, $runtime, $explain, $master]);
                }
            }
        } else {
            // If the listener is not registered, it is recorded in the log
            if ($this->config['deploy']) {
                // Distributed recording of current operations master-slave
                $master = $master ? 'master|' : 'slave|';
            } else {
                $master = '';
            }

            Log::record('[ SQL ] ' . $sql . ' [ ' . $master . 'RunTime:' . $runtime . 's ]', 'sql');
            if (!empty($explain)) {
                Log::record('[ EXPLAIN : ' . var_export($explain, true) . ' ]', 'sql');
            }
        }
    }

    /* *
 * Initialize the database connection
 * @access protected
 * @param boolean $master Whether to use the master server
 * @return void */
    protected function initConnect($master = true)
    {
        if (!empty($this->config['deploy'])) {
            // Using distributed databases
            if ($master || $this->transTimes) {
                if (!$this->linkWrite) {
                    $this->linkWrite = $this->multiConnect(true);
                }
                $this->linkID = $this->linkWrite;
            } else {
                if (!$this->linkRead) {
                    $this->linkRead = $this->multiConnect(false);
                }
                $this->linkID = $this->linkRead;
            }
        } elseif (!$this->linkID) {
            // Default single database
            $this->linkID = $this->connect();
        }
    }

    /* *
 * Connect to distributed servers
 * @access protected
 * @param boolean $master primary server
 * @return PDO */
    protected function multiConnect($master = false)
    {
        $_config = [];
        // Distributed database configuration analysis
        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $_config[$name] = explode(',', $this->config[$name]);
        }

        // Master server index
        $m = floor(mt_rand(0, $this->config['master_num'] - 1));

        if ($this->config['rw_separate']) {
            // Master-slave mode uses read/write separation
            if ($master) // Write through the master server
            {
                $r = $m;
            } elseif (is_numeric($this->config['slave_no'])) {
                // Specify the server to read
                $r = $this->config['slave_no'];
            } else {
                // Choose a slave server randomly for reads
                $r = floor(mt_rand($this->config['master_num'], count($_config['hostname']) - 1));
            }
        } else {
            // Choose a server randomly for both reads and writes
            $r = floor(mt_rand(0, count($_config['hostname']) - 1));
        }
        $dbMaster = false;
        if ($m != $r) {
            $dbMaster = [];
            foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
                $dbMaster[$name] = isset($_config[$name][$m]) ? $_config[$name][$m] : $_config[$name][0];
            }
        }
        $dbConfig = [];
        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $dbConfig[$name] = isset($_config[$name][$r]) ? $_config[$name][$r] : $_config[$name][0];
        }
        return $this->connect($dbConfig, $r, $r == $m ? false : $dbMaster);
    }

    /* *
 * Destructor
 * @access public */
    public function __destruct()
    {
        // Release a query
        if ($this->PDOStatement) {
            $this->free();
        }
        // Close the connection
        $this->close();
    }
}
