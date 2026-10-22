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
 * File-based cache driver
 * @author    liu21st <liu21st@gmail.com>
 */
class File extends Driver
{
    protected $options = [
        'expire'        => 0,
        'cache_subdir'  => true,
        'prefix'        => '',
        'path'          => CACHE_PATH,
        'data_compress' => false,
    ];

    protected $expire;

    /* *
 * Initialize the file cache driver
 * @param array $options */
    public function __construct($options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        if (substr($this->options['path'], -1) != DS) {
            $this->options['path'] .= DS;
        }
        $this->init();
    }

    /* *
 * Create the cache directory if it does not exist
 * @access private
 * @return bool Whether the cache directory was created */
    private function init()
    {
        // Create the configured cache directory.
        if (!is_dir($this->options['path'])) {
            if (mkdir($this->options['path'], 0755, true)) {
                return true;
            }
        }
        return false;
    }

    /* *
 * Get the storage path for a cache entry
 * @access protected
 * @param string $name Cache key
 * @param bool $auto Whether to automatically create the directory
 * @return string */
    protected function getCacheKey($name, $auto = false)
    {
        $name = md5($name);
        if ($this->options['cache_subdir']) {
            // Use the first two hash characters as a subdirectory.
            $name = substr($name, 0, 2) . DS . substr($name, 2);
        }
        if ($this->options['prefix']) {
            $name = $this->options['prefix'] . DS . $name;
        }
        $filename = $this->options['path'] . $name . '.php';
        $dir      = dirname($filename);

        if ($auto && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $filename;
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
        if (!is_file($filename)) {
            return $default;
        }
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            return $default;
        }
        $header = @fread($handle, 32);
        $this->expire = null;
        if (false === $header || strlen($header) !== 32) {
            fclose($handle);
            return $default;
        }

        $expire = (int) substr($header, 8, 12);
        if (0 != $expire && time() > filemtime($filename) + $expire) {
            // Garbage collection: delete only the expired key being read.
            fclose($handle);
            $this->unlink($filename);
            return $default;
        }
        $this->expire = $expire;
        $content = stream_get_contents($handle);
        fclose($handle);
        if ($content === false) {
            return $default;
        }
        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            // Decompress the stored data.
            $content = gzuncompress($content);
        }
        $content = unserialize($content);
        return $content;
    }

    /* *
 * Store a cached value
 * @access public
 * @param string $name Cache key
 * @param mixed $value Value to store
 * @param int|\DateTime|null $expire Lifetime in seconds, expiration date, or null for the configured default
 * @return boolean */
    public function set($name, $value, $expire = null)
    {
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp() - time();
        }
        $filename = $this->getCacheKey($name, true);
        if ($this->tag && !is_file($filename)) {
            $first = true;
        }
        $data = serialize($value);
        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            // Compress the serialized data.
            $data = gzcompress($data, 3);
        }
        $data = "<?php\n//" . sprintf('%012d', $expire) . "\n exit();?>\n" . $data;

        // Write beside the destination and rename only after the complete cache
        // payload is on disk. Readers therefore never see a truncated value
        // while several PHP workers regenerate the same cache key.
        $tempname = $filename . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
        $result   = @file_put_contents($tempname, $data, LOCK_EX);
        if (false === $result || $result !== strlen($data)) {
            @unlink($tempname);
            return false;
        }
        if (!@rename($tempname, $filename)) {
            @unlink($tempname);
            return false;
        }

        if ($result) {
            isset($first) && $this->setTagItem($filename);
            clearstatcache();

            return true;
        } else {
            return false;
        }
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
            $value  = $this->get($name) + $step;
            $expire = $this->expire;
        } else {
            $value  = $step;
            $expire = 0;
        }

        return $this->set($name, $value, $expire) ? $value : false;
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
            $value  = $this->get($name) - $step;
            $expire = $this->expire;
        } else {
            $value  = -$step;
            $expire = 0;
        }

        return $this->set($name, $value, $expire) ? $value : false;
    }

    /* *
 * Delete a cache entry
 * @access public
 * @param string $name Cache key
 * @return bool|null */
    public function rm($name)
    {
        $filename = $this->getCacheKey($name);
        try {
            return $this->unlink($filename);
        } catch (\Exception $e) {
        }
    }

    /* *
 * Clear cache
 * @access public
 * @param string|null $tag Optional tag name
 * @return boolean */
    public function clear($tag = null)
    {
        if ($tag) {
            // Delete the entries associated with this tag.
            $keys = $this->getTagItem($tag);
            foreach ($keys as $key) {
                $this->unlink($key);
            }
            $this->rm('tag_' . md5($tag));
            return true;
        }
        $files = (array) glob($this->options['path'] . ($this->options['prefix'] ? $this->options['prefix'] . DS : '') . '*');
        foreach ($files as $path) {
            if (is_dir($path)) {
                $matches = glob($path . '/*.php');
                if (is_array($matches)) {
                    array_map('unlink', $matches);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        return true;
    }

    /* *
 * Delete the path if it is a file
 * @param string $path File path
 * @return bool
 * @author byron sampson <xiaobo.sun@qq.com>
 * Returns false if the path is not a file. */
    private function unlink($path)
    {
        return is_file($path) && @unlink($path);
    }

}
