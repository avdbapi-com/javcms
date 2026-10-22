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

class Memcache extends Driver
{
    protected $options = [
        'host'       => '127.0.0.1',
        'port'       => 11211,
        'expire'     => 0,
        'timeout'    => 0, // Connection timeout in seconds
        'persistent' => true,
        'prefix'     => '',
    ];

    /* *
 * Initialize the Memcache handler and configured servers
 * @param array $options
 * @access public
 * @throws \BadFunctionCallException */
    public function __construct($options = [])
    {
        if (!extension_loaded('memcache')) {
            throw new \BadFunctionCallException('not support: memcache');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->handler = new \Memcache;
        // Parse the configured server hosts and ports
        $hosts = explode(',', $this->options['host']);
        $ports = explode(',', $this->options['port']);
        if (empty($ports[0])) {
            $ports[0] = 11211;
        }
        // Register the configured servers
        foreach ((array) $hosts as $i => $host) {
            $port = isset($ports[$i]) ? $ports[$i] : $ports[0];
            $this->options['timeout'] > 0 ?
            $this->handler->addServer($host, $port, $this->options['persistent'], 1, $this->options['timeout']) :
            $this->handler->addServer($host, $port, $this->options['persistent'], 1);
        }
    }

    /* *
 * Check whether the cached value is not false
 * @access public
 * @param string $name Cache key name
 * @return bool */
    public function has($name)
    {
        $key = $this->getCacheKey($name);
        return false !== $this->handler->get($key);
    }

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key name
 * @param mixed $default Value returned when retrieval returns false
 * @return mixed */
    public function get($name, $default = false)
    {
        $result = $this->handler->get($this->getCacheKey($name));
        return false !== $result ? $result : $default;
    }

    /* *
 * Store a value in the cache
 * @access public
 * @param string $name Cache key name
 * @param mixed $value Value to cache
 * @param int|\DateTime|null $expire Lifetime in seconds or expiration time; null uses the configured lifetime
 * @return bool */
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
        if ($this->handler->set($key, $value, 0, $expire)) {
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
 * @return bool|int */
    public function inc($name, $step = 1)
    {
        $key = $this->getCacheKey($name);
        if ($this->handler->get($key)) {
            return $this->handler->increment($key, $step);
        }
        return $this->handler->set($key, $step);
    }

    /* *
 * Decrement a numeric cached value
 * @access public
 * @param string $name Cache key name
 * @param int $step Decrement amount
 * @return int|float|false */
    public function dec($name, $step = 1)
    {
        $key   = $this->getCacheKey($name);
        $value = $this->handler->get($key) - $step;
        $res   = $this->handler->set($key, $value);
        if (!$res) {
            return false;
        } else {
            return $value;
        }
    }

    /* *
 * Delete a cache entry
 * @param string $name Cache key name
 * @param mixed $ttl Optional delete argument; false omits it
 * @return bool */
    public function rm($name, $ttl = false)
    {
        $key = $this->getCacheKey($name);
        return false === $ttl ?
        $this->handler->delete($key) :
        $this->handler->delete($key, $ttl);
    }

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Tag name; a falsy value clears the whole cache
 * @return bool */
    public function clear($tag = null)
    {
        if ($tag) {
            // Delete cache entries associated with the tag
            $keys = $this->getTagItem($tag);
            foreach ($keys as $key) {
                $this->handler->delete($key);
            }
            $this->rm('tag_' . md5($tag));
            return true;
        }
        return $this->handler->flush();
    }
}
