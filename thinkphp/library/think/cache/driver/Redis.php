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
 * Redis cache driver for standalone deployments and high-availability setups with a front-end proxy, offering the best performance
 * For application-level read/write separation or RedisCluster support, use the Redisd driver
 *
 * Requires the phpredis extension: https://github.com/nicolasff/phpredis
 * @author    尘缘 <130775@qq.com>
 */
class Redis extends Driver
{
    protected $options = [
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'username'   => '',
        'password'   => '',
        'select'     => 0,
        'timeout'    => 0,
        'expire'     => 0,
        'persistent' => true,
        'prefix'     => '',
    ];

    /* *
 * Initialize the Redis handler and connection
 * @param array $options Driver options
 * @access public */
    public function __construct($options = [])
    {
        if (!extension_loaded('redis')) {
            throw new \BadFunctionCallException('not support: redis');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->handler = new \Redis;
        if ($this->options['persistent']) {
            // Connection identity includes credentials so a reused socket cannot inherit another user's AUTH.
            $persistentId = 'persistent_id_' . hash('sha256', serialize([
                $this->options['host'], $this->options['port'], $this->options['select'],
                $this->options['username'], $this->options['password'],
            ]));
            $this->handler->pconnect($this->options['host'], $this->options['port'], $this->options['timeout'], $persistentId);
        } else {
            $this->handler->connect($this->options['host'], $this->options['port'], $this->options['timeout']);
        }

        if ('' !== $this->options['username'] || '' !== $this->options['password']) {
            $credentials = $this->options['username'] === ''
                ? $this->options['password']
                : [$this->options['username'], $this->options['password']];
            try {
                $authenticated = $this->handler->auth($credentials);
            } catch (\Throwable $error) {
                throw new \RuntimeException('Redis authentication failed.');
            }
            if ($authenticated !== true) {
                throw new \RuntimeException('Redis authentication failed.');
            }
        }

        if (0 != $this->options['select']) {
            $this->handler->select($this->options['select']);
        }
    }

    /* *
 * Check whether a cache key exists
 * @access public
 * @param string $name Cache key name
 * @return bool */
    public function has($name)
    {
        return $this->handler->exists($this->getCacheKey($name));
    }

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key name
 * @param mixed $default Fallback value
 * @return mixed */
    public function get($name, $default = false)
    {
        $value = $this->handler->get($this->getCacheKey($name));
        if (is_null($value) || false === $value) {
            return $default;
        }

        try {
            $result = 0 === strpos($value, 'think_serialize:') ? unserialize(substr($value, 16)) : $value;
        } catch (\Exception $e) {
            $result = $default;
        }

        return $result;
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
        $key   = $this->getCacheKey($name);
        $value = is_scalar($value) ? $value : 'think_serialize:' . serialize($value);
        if ($expire) {
            $result = $this->handler->setex($key, $expire, $value);
        } else {
            $result = $this->handler->set($key, $value);
        }
        isset($first) && $this->setTagItem($key);
        return $result;
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

        return $this->handler->incrby($key, $step);
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

        return $this->handler->decrby($key, $step);
    }

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key name
 * @return boolean */
    public function rm($name)
    {
        return $this->handler->delete($this->getCacheKey($name));
    }

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Tag name; a falsy value clears the selected Redis database
 * @return boolean */
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
        return $this->handler->flushDB();
    }

}
