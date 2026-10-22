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

namespace think\cache\driver;

use think\cache\Driver;

/* *
 * SQLite cache driver
 * @author    liu21st <liu21st@gmail.com>
 */
class Sqlite extends Driver
{
    protected $options = [
        'db'         => ':memory:',
        'table'      => 'sharedmemory',
        'prefix'     => '',
        'expire'     => 0,
        'persistent' => false,
    ];

    /* *
 * Initialize the SQLite cache connection
 * @param array $options
 * @throws \BadFunctionCallException
 * @access public */
    public function __construct($options = [])
    {
        if (!extension_loaded('sqlite')) {
            throw new \BadFunctionCallException('not support: sqlite');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $func          = $this->options['persistent'] ? 'sqlite_popen' : 'sqlite_open';
        $this->handler = $func($this->options['db']);
    }

    /* *
 * Get the prefixed and escaped cache key
 * @access protected
 * @param string $name Cache key name
 * @return string */
    protected function getCacheKey($name)
    {
        return $this->options['prefix'] . sqlite_escape_string($name);
    }

    /* *
 * Return the matching valid cache row count
 * @access public
 * @param string $name Cache key name
 * @return int */
    public function has($name)
    {
        $name   = $this->getCacheKey($name);
        $sql    = 'SELECT value FROM ' . $this->options['table'] . ' WHERE var=\'' . $name . '\' AND (expire=0 OR expire >' . $_SERVER['REQUEST_TIME'] . ') LIMIT 1';
        $result = sqlite_query($this->handler, $sql);
        return sqlite_num_rows($result);
    }

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key name
 * @param mixed $default Value returned when no matching cache row is found
 * @return mixed */
    public function get($name, $default = false)
    {
        $name   = $this->getCacheKey($name);
        $sql    = 'SELECT value FROM ' . $this->options['table'] . ' WHERE var=\'' . $name . '\' AND (expire=0 OR expire >' . $_SERVER['REQUEST_TIME'] . ') LIMIT 1';
        $result = sqlite_query($this->handler, $sql);
        if (sqlite_num_rows($result)) {
            $content = sqlite_fetch_single($result);
            if (function_exists('gzcompress')) {
                // Decompress the cached data
                $content = gzuncompress($content);
            }
            return unserialize($content);
        }
        return $default;
    }

    /* *
 * Store a value in the cache
 * @access public
 * @param string $name Cache key name
 * @param mixed $value Value to cache
 * @param int|\DateTime|null $expire Lifetime in seconds or expiration time; null uses the configured lifetime
 * @return boolean */
    public function set($name, $value, $expire = null)
    {
        $name  = $this->getCacheKey($name);
        $value = sqlite_escape_string(serialize($value));
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp();
        } else {
            $expire = (0 == $expire) ? 0 : (time() + $expire); // A cache expiration period of 0 indicates a permanent cache
        }
        if (function_exists('gzcompress')) {
            // Compress the cached data
            $value = gzcompress($value, 3);
        }
        if ($this->tag) {
            $tag       = $this->tag;
            $this->tag = null;
        } else {
            $tag = '';
        }
        $sql = 'REPLACE INTO ' . $this->options['table'] . ' (var, value, expire, tag) VALUES (\'' . $name . '\', \'' . $value . '\', \'' . $expire . '\', \'' . $tag . '\')';
        if (sqlite_query($this->handler, $sql)) {
            return true;
        }
        return false;
    }

    /* *
 * Increment a numeric cached value
 * @access public
 * @param string $name Cache key name
 * @param int $step Increment amount
 * @return int|float|false */
    public function inc($name, $step = 1)
    {
        if ($this->has($name)) {
            $value = $this->get($name) + $step;
        } else {
            $value = $step;
        }
        return $this->set($name, $value, 0) ? $value : false;
    }

    /* *
 * Decrement a numeric cached value
 * @access public
 * @param string $name Cache key name
 * @param int $step Decrement amount
 * @return int|float|false */
    public function dec($name, $step = 1)
    {
        if ($this->has($name)) {
            $value = $this->get($name) - $step;
        } else {
            $value = -$step;
        }
        return $this->set($name, $value, 0) ? $value : false;
    }

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key name
 * @return boolean */
    public function rm($name)
    {
        $name = $this->getCacheKey($name);
        $sql  = 'DELETE FROM ' . $this->options['table'] . ' WHERE var=\'' . $name . '\'';
        sqlite_query($this->handler, $sql);
        return true;
    }

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Tag name; a falsy value clears the cache table
 * @return boolean */
    public function clear($tag = null)
    {
        if ($tag) {
            $name = sqlite_escape_string($tag);
            $sql  = 'DELETE FROM ' . $this->options['table'] . ' WHERE tag=\'' . $name . '\'';
            sqlite_query($this->handler, $sql);
            return true;
        }
        $sql = 'DELETE FROM ' . $this->options['table'];
        sqlite_query($this->handler, $sql);
        return true;
    }
}
