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

namespace think\cache;

/* *
 * Cache base class */
abstract class Driver
{
    protected $handler = null;
    protected $options = [];
    protected $tag;

    /* *
 * Check whether a cache entry exists
 * @access public
 * @param string $name Cache key
 * @return bool */
    abstract public function has($name);

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key
 * @param mixed $default Default value when the entry is unavailable
 * @return mixed */
    abstract public function get($name, $default = false);

    /* *
 * Store a cached value
 * @access public
 * @param string $name Cache key
 * @param mixed $value Value to store
 * @param int|null $expire Expiry in seconds; null uses the driver default
 * @return bool|int */
    abstract public function set($name, $value, $expire = null);

    /* *
 * Increment a numeric cached value
 * @access public
 * @param string $name Cache key
 * @param int $step Amount to add
 * @return false|int */
    abstract public function inc($name, $step = 1);

    /* *
 * Decrement a numeric cached value
 * @access public
 * @param string $name Cache key
 * @param int $step Amount to subtract
 * @return false|int */
    abstract public function dec($name, $step = 1);

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key
 * @return boolean */
    abstract public function rm($name);

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Optional tag name
 * @return bool|null */
    abstract public function clear($tag = null);

    /* *
 * Build the cache key with the configured prefix
 * @access protected
 * @param string $name Cache key without the configured prefix
 * @return string */
    protected function getCacheKey($name)
    {
        return $this->options['prefix'] . $name;
    }

    /* *
 * Read a cached value and delete the entry if the value is truthy
 * @access public
 * @param string $name Cache key
 * @return mixed */
    public function pull($name)
    {
        $result = $this->get($name, false);
        if ($result) {
            $this->rm($name);
            return $result;
        } else {
            return;
        }
    }

    /* *
 * Return a cached value, or create and cache it if absent
 * @access public
 * @param string $name Cache key
 * @param mixed $value Value to store, or a closure that produces it
 * @param int|null $expire Expiry in seconds; null uses the driver default
 * @return mixed */
    public function remember($name, $value, $expire = null)
    {
        if (!$this->has($name)) {
            $time = time();
            while ($time + 5 > time() && $this->has($name . '_lock')) {
                // Wait briefly while the lock key exists.
                usleep(200000);
            }

            try {
                // Set the lock key.
                $this->set($name . '_lock', true);
                if ($value instanceof \Closure) {
                    $value = call_user_func($value);
                }
                $this->set($name, $value, $expire);
                // Remove the lock key.
                $this->rm($name . '_lock');
            } catch (\Exception $e) {
                // Remove the lock key.
                $this->rm($name . '_lock');
                throw $e;
            } catch (\throwable $e) {
                $this->rm($name . '_lock');
                throw $e;
            }
        } else {
            $value = $this->get($name);
        }
        return $value;
    }

    /* *
 * Select a cache tag or update its associated keys
 * @access public
 * @param string|null $name Tag name; null leaves the current tag unchanged
 * @param string|array|null $keys Cache keys; a string is a comma-separated list
 * @param bool $overlay Whether to replace the existing tag keys when keys are supplied
 * @return $this */
    public function tag($name, $keys = null, $overlay = false)
    {
        if (is_null($name)) {

        } elseif (is_null($keys)) {
            $this->tag = $name;
        } else {
            $key = 'tag_' . md5($name);
            if (is_string($keys)) {
                $keys = explode(',', $keys);
            }
            $keys = array_map([$this, 'getCacheKey'], $keys);
            if ($overlay) {
                $value = $keys;
            } else {
                $value = array_unique(array_merge($this->getTagItem($name), $keys));
            }
            $this->set($key, implode(',', $value), 0);
        }
        return $this;
    }

    /* *
 * Record a cache key for the active tag and clear the active tag selection
 * @access protected
 * @param string $name Resolved cache key
 * @return void */
    protected function setTagItem($name)
    {
        if ($this->tag) {
            $key       = 'tag_' . md5($this->tag);
            $this->tag = null;
            if ($this->has($key)) {
                $value   = explode(',', $this->get($key));
                $value[] = $name;
                $value   = implode(',', array_unique($value));
            } else {
                $value = $name;
            }
            $this->set($key, $value, 0);
        }
    }

    /* *
 * Get the cache keys stored for a tag
 * @access protected
 * @param string $tag Tag name
 * @return array */
    protected function getTagItem($tag)
    {
        $key   = 'tag_' . md5($tag);
        $value = $this->get($key);
        if ($value) {
            return array_filter(explode(',', $value));
        } else {
            return [];
        }
    }

    /* *
 * Return the underlying driver handle for direct access
 *
 * @access public
 * @return mixed */
    public function handler()
    {
        return $this->handler;
    }
}
