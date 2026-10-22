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

use think\cache\Driver;

class Cache
{
    /* *
 * @var array Cache driver instances */
    public static $instance = [];

    /* *
 * @var int Number of cache read operations */
    public static $readTimes = 0;

    /* *
 * @var int number of cache writes */
    public static $writeTimes = 0;

    /* *
 * @var object Active cache driver */
    public static $handler;

    /* *
 * Connect to the cache driver
 * @access public
 * @param array $options configuration array
 * @param bool|string $name Cache connection identifier; true creates a new driver instance
 * @return Driver */
    public static function connect(array $options = [], $name = false)
    {
        $type = !empty($options['type']) ? $options['type'] : 'File';

        if (false === $name) {
            $name = md5(serialize($options));
        }

        if (true === $name || !isset(self::$instance[$name])) {
            $class = false === strpos($type, '\\') ?
            '\\think\\cache\\driver\\' . ucwords($type) :
            $type;

            // Record initialization information
            App::$debug && Log::record('[ CACHE ] INIT ' . $type, 'info');

            if (true === $name) {
                return new $class($options);
            }

            self::$instance[$name] = new $class($options);
        }

        return self::$instance[$name];
    }

    /* *
 * Automatically initialize the cache
 * @access public
 * @param array $options Configuration array
 * @return Driver */
    public static function init(array $options = [])
    {
        if (is_null(self::$handler)) {
            if (empty($options) && 'complex' == Config::get('cache.type')) {
                $default = Config::get('cache.default');
                // Get the default cache configuration and connect
                $options = Config::get('cache.' . $default['type']) ?: $default;
            } elseif (empty($options)) {
                $options = Config::get('cache');
            }
            self::$handler = self::connect($options);
        }

        return self::$handler;
    }

    public static function reset_init($options)
    {
        self::$handler = self::connect($options);
        return self::$handler;
    }
    /* *
 * Select a named cache store when cache.type is complex
 * @access public
 * @param string $name Cache store name
 * @return Driver */
    public static function store($name = '')
    {
        if ('' !== $name && 'complex' == Config::get('cache.type')) {
            return self::connect(Config::get('cache.' . $name), strtolower($name));
        }

        return self::init();
    }

    /* *
 * Check whether a cache entry exists
 * @access public
 * @param string $name Cache key
 * @return bool */
    public static function has($name)
    {
        self::$readTimes++;

        return self::init()->has($name);
    }

    /* *
 * Read a cache entry
 * @access public
 * @param string $name Cache key
 * @param mixed $default default value
 * @return mixed */
    public static function get($name, $default = false)
    {
        self::$readTimes++;

        return self::init()->get($name, $default);
    }

    /* *
 * Write to cache
 * @access public
 * @param string $name Cache key
 * @param mixed $value Data to store
 * @param int|null $expire Expiration time; 0 means no expiration
 * @return boolean */
    public static function set($name, $value, $expire = null)
    {
        self::$writeTimes++;

        return self::init()->set($name, $value, $expire);
    }

    /* *
 * Increment a numeric cache value
 * @access public
 * @param string $name Cache key
 * @param int $step step
 * @return false|int */
    public static function inc($name, $step = 1)
    {
        self::$writeTimes++;

        return self::init()->inc($name, $step);
    }

    /* *
 * Decrement a numeric cache value
 * @access public
 * @param string $name Cache key
 * @param int $step step
 * @return false|int */
    public static function dec($name, $step = 1)
    {
        self::$writeTimes++;

        return self::init()->dec($name, $step);
    }

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key
 * @return boolean */
    public static function rm($name)
    {
        self::$writeTimes++;

        return self::init()->rm($name);
    }

    /* *
 * Clear cache
 * @access public
 * @param string $tag tag name
 * @return boolean */
    public static function clear($tag = null)
    {
        self::$writeTimes++;

        return self::init()->clear($tag);
    }

    /* *
 * Read and delete a cache entry
 * @access public
 * @param string $name Cache key
 * @return mixed */
    public static function pull($name)
    {
        self::$readTimes++;
        self::$writeTimes++;

        return self::init()->pull($name);
    }

    /* *
 * Store a value if the cache entry does not exist
 * @access public
 * @param string $name Cache key
 * @param mixed $value Data to store
 * @param int $expire Expiration time; 0 means no expiration
 * @return mixed */
    public static function remember($name, $value, $expire = null)
    {
        self::$readTimes++;

        return self::init()->remember($name, $value, $expire);
    }

    /* *
 * Cache tag operations
 * @access public
 * @param string $name tag name
 * @param string|array $keys Cache keys
 * @param bool $overlay Whether to replace the existing tag keys when keys are supplied
 * @return Driver */
    public static function tag($name, $keys = null, $overlay = false)
    {
        return self::init()->tag($name, $keys, $overlay);
    }

}
