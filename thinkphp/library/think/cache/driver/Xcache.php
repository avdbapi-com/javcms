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
 * Xcache
 * @author    liu21st <liu21st@gmail.com>
 */
class Xcache extends Driver
{
    protected $options = [
        'prefix' => '',
        'expire' => 0,
    ];

    /* *
 * Initialize the XCache driver options
 * @param array $options
 * @access public
 * @throws \BadFunctionCallException */
    public function __construct($options = [])
    {
        if (!function_exists('xcache_info')) {
            throw new \BadFunctionCallException('not support: Xcache');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
    }

    /* *
 * Check whether a cache key exists
 * @access public
 * @param string $name Cache key name
 * @return bool */
    public function has($name)
    {
        $key = $this->getCacheKey($name);
        return xcache_isset($key);
    }

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key name
 * @param mixed $default Value returned when the cache key does not exist
 * @return mixed */
    public function get($name, $default = false)
    {
        $key = $this->getCacheKey($name);
        return xcache_isset($key) ? xcache_get($key) : $default;
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
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp() - time();
        }
        if ($this->tag && !$this->has($name)) {
            $first = true;
        }
        $key = $this->getCacheKey($name);
        if (xcache_set($key, $value, $expire)) {
            isset($first) && $this->setTagItem($key);
            return true;
        }
        return false;
    }

    /* *
 * Increment a numeric cached value
 * @access public
 * @param string $name Cache key name
 * @param int $step Increment amount
 * @return false|int */
    public function inc($name, $step = 1)
    {
        $key = $this->getCacheKey($name);
        return xcache_inc($key, $step);
    }

    /* *
 * Decrement a numeric cached value
 * @access public
 * @param string $name Cache key name
 * @param int $step Decrement amount
 * @return false|int */
    public function dec($name, $step = 1)
    {
        $key = $this->getCacheKey($name);
        return xcache_dec($key, $step);
    }

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key name
 * @return boolean */
    public function rm($name)
    {
        return xcache_unset($this->getCacheKey($name));
    }

    /* *
 * Clear tagged entries, or clear by prefix when supported
 * @access public
 * @param string|null $tag Tag name; a falsy value selects prefix-based clearing
 * @return boolean */
    public function clear($tag = null)
    {
        if ($tag) {
            // Delete cache entries associated with the tag
            $keys = $this->getTagItem($tag);
            foreach ($keys as $key) {
                xcache_unset($key);
            }
            $this->rm('tag_' . md5($tag));
            return true;
        }
        if (function_exists('xcache_unset_by_prefix')) {
            return xcache_unset_by_prefix($this->options['prefix']);
        } else {
            return false;
        }
    }
}
