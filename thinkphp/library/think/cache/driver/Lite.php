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
 * Lightweight file-based cache driver
 * @author    liu21st <liu21st@gmail.com>
 */
class Lite extends Driver
{
    protected $options = [
        'prefix' => '',
        'path'   => '',
        'expire' => 0, // Integer zero uses a ten-year lifetime (10*365*24*3600 seconds)
    ];

    /* *
 * Initialize the lightweight file cache driver
 * @access public
 *
 * @param array $options */
    public function __construct($options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        if (substr($this->options['path'], -1) != DS) {
            $this->options['path'] .= DS;
        }

    }

    /* *
 * Get the storage path for a cache entry
 * @access protected
 * @param string $name Cache key
 * @return string */
    protected function getCacheKey($name)
    {
        return $this->options['path'] . $this->options['prefix'] . md5($name) . '.php';
    }

    /* *
 * Check whether the cached value is truthy
 * @access public
 * @param string $name Cache key
 * @return bool */
    public function has($name)
    {
        return $this->get($name) ? true : false;
    }

    /* *
 * Read a cached value
 * @access public
 * @param string $name Cache key
 * @param mixed $default Default value when the entry is unavailable
 * @return mixed */
    public function get($name, $default = false)
    {
        $filename = $this->getCacheKey($name);
        if (is_file($filename)) {
            // Check the expiration timestamp stored as the file modification time.
            $mtime = filemtime($filename);
            if ($mtime < time()) {
                // Delete this expired cache file.
                unlink($filename);
                return $default;
            }
            return include $filename;
        } else {
            return $default;
        }
    }

    /* *
 * Store a cached value
 * @access public
 * @param string $name Cache key
 * @param mixed $value Value to store
 * @param int|\DateTime|null $expire Lifetime in seconds, expiration date, or null for the configured default
 * @return int|false Number of bytes written, or false on failure */
    public function set($name, $value, $expire = null)
    {
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp();
        } else {
            $expire = 0 === $expire ? 10 * 365 * 24 * 3600 : $expire;
            $expire = time() + $expire;
        }
        $filename = $this->getCacheKey($name);
        if ($this->tag && !is_file($filename)) {
            $first = true;
        }
        $ret = file_put_contents($filename, ("<?php return " . var_export($value, true) . ";"));
        // Store the expiration timestamp as the file modification time.
        if ($ret) {
            isset($first) && $this->setTagItem($filename);
            touch($filename, $expire);
        }
        return $ret;
    }

    /* *
 * Increment a numeric cached value
 * @access public
 * @param string $name Cache key
 * @param int $step Amount to add
 * @return false|int */
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
 * @param string $name Cache key
 * @param int $step Amount to subtract
 * @return false|int */
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
 * @param string $name Cache key
 * @return boolean */
    public function rm($name)
    {
        return unlink($this->getCacheKey($name));
    }

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Optional tag name
 * @return bool|null */
    public function clear($tag = null)
    {
        if ($tag) {
            // Delete the entries associated with this tag.
            $keys = $this->getTagItem($tag);
            foreach ($keys as $key) {
                unlink($key);
            }
            $this->rm('tag_' . md5($tag));
            return true;
        }
        array_map("unlink", glob($this->options['path'] . ($this->options['prefix'] ? $this->options['prefix'] . DS : '') . '*.php'));
    }
}
