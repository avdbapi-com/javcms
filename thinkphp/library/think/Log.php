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

use think\exception\ClassNotFoundException;

/* *
 * Class Log
 * @package think
 *
 * @method void log ($msg) static Record a general log message
 * @method void error ($msg) static Record an error message
 * @method void info ($msg) static Record an informational message
 * @method void sql ($msg) static Record a SQL query log entry
 * @method void notice ($msg) static Record a notice message
 * @method void alert ($msg) static Record an alert message */
class Log
{
    const LOG    = 'log';
    const ERROR  = 'error';
    const INFO   = 'info';
    const SQL    = 'sql';
    const NOTICE = 'notice';
    const ALERT  = 'alert';
    const DEBUG  = 'debug';

    /* *
 * @var array Buffered log messages */
    protected static $log = [];

    /* *
 * @var array configuration parameters */
    protected static $config = [];

    /* *
 * @var array Supported log levels */
    protected static $type = ['log', 'error', 'info', 'sql', 'notice', 'alert', 'debug'];

    /* *
 * @var log\driver\File|log\driver\Test|log\driver\Socket Log writing driver */
    protected static $driver;

    /* *
 * @var string Current log authorization key */
    protected static $key;

    /* *
 * Initialize the log writing driver
 * @access public
 * @param array $config configuration parameters
 * @return void */
    public static function init($config = [])
    {
        $type  = isset($config['type']) ? $config['type'] : 'File';
        $class = false !== strpos($type, '\\') ? $type : '\\think\\log\\driver\\' . ucwords($type);

        self::$config = $config;
        unset($config['type']);

        if (class_exists($class)) {
            self::$driver = new $class($config);
        } else {
            throw new ClassNotFoundException('class not exists:' . $class, $class);
        }

        // Record initialization information
        App::$debug && Log::record('[ LOG ] INIT ' . $type, 'info');
    }

    /* *
 * Get buffered log messages
 * @access public
 * @param string $type Log level
 * @return array|null */
    public static function getLog($type = '')
    {
        return $type ? self::$log[$type] : self::$log;
    }

    /* *
 * Add a message to the log buffer
 * @access public
 * @param mixed $msg Log message
 * @param string $type Log level
 * @return void */
    public static function record($msg, $type = 'log')
    {
        self::$log[$type][] = $msg;

        // Save buffered messages immediately in CLI mode
        IS_CLI && self::save();
    }

    /* *
 * Clear buffered log messages
 * @access public
 * @return void */
    public static function clear()
    {
        self::$log = [];
    }

    /* *
 * Set the log writing authorization key
 * @access public
 * @param string $key Authorization key
 * @return void */
    public static function key($key)
    {
        self::$key = $key;
    }

    /* *
 * Check permission to write logs
 * @access public
 * @param array $config Current log configuration parameters
 * @return bool */
    public static function check($config)
    {
        return !self::$key || empty($config['allow_key']) || in_array(self::$key, $config['allow_key']);
    }

    /* *
 * Save buffered log messages
 * @access public
 * @return bool */
    public static function save()
    {
        // Return success when there are no buffered messages
        if (empty(self::$log)) {
            return true;
        }

        is_null(self::$driver) && self::init(Config::get('log'));

        // Check permission to write buffered log messages
        if (!self::check(self::$config)) {
            return false;
        }

        if (empty(self::$config['level'])) {
            // Use buffered messages, omitting debug messages when debugging is disabled
            $log = self::$log;
            if (!App::$debug && isset($log['debug'])) {
                unset($log['debug']);
            }
        } else {
            // Select buffered messages from the configured log levels
            $log = [];
            foreach (self::$config['level'] as $level) {
                if (isset(self::$log[$level])) {
                    $log[$level] = self::$log[$level];
                }
            }
        }

        if ($result = self::$driver->save($log, true)) {
            self::$log = [];
        }

        Hook::listen('log_write_done', $log);

        return $result;
    }

    /* *
 * Write a log message immediately and invoke the log_write hook
 * @access public
 * @param mixed $msg Log message
 * @param string $type Log level
 * @param bool $force Whether to bypass the configured level filter
 * @return bool */
    public static function write($msg, $type = 'log', $force = false)
    {
        $log = self::$log;

        // Apply a nonempty level filter unless writing is forced
        if (true !== $force && !empty(self::$config['level']) && !in_array($type, self::$config['level'])) {
            return false;
        }

        // Add the message to a copy of the buffered logs
        $log[$type][] = $msg;

        // Invoke the log_write hook
        Hook::listen('log_write', $log);

        is_null(self::$driver) && self::init(Config::get('log'));

        // Save the prepared messages through the log driver
        if ($result = self::$driver->save($log, false)) {
            self::$log = [];
        }

        return $result;
    }

    /* *
 * Handle a supported log-level method call
 * @access public
 * @param string $method method to call
 * @param mixed $args Arguments to pass to record
 * @return void */
    public static function __callStatic($method, $args)
    {
        if (in_array($method, self::$type)) {
            array_push($args, $method);

            call_user_func_array('\\think\\Log::record', $args);
        }
    }

}
