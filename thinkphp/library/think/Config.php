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

class Config
{
    /* *
 * @var array configuration parameters */
    private static $config = [];

    /* *
 * @var string parameter scope */
    private static $range = '_sys_';

    /* *
 * Set the scope of the configuration parameters
 * @access public
 * @param string $range scope
 * @return void */
    public static function range($range)
    {
        self::$range = $range;

        if (!isset(self::$config[$range])) self::$config[$range] = [];
    }

    /* *
 * Parse configuration files or content
 * @access public
 * @param string $config configuration file path or content
 * @param string $type Parsing format or parser class name
 * @param string $name Configuration section name, if supplied
 * @param string $range scope
 * @return mixed */
    public static function parse($config, $type = '', $name = '', $range = '')
    {
        $range = $range ?: self::$range;

        if (empty($type)) $type = pathinfo($config, PATHINFO_EXTENSION);

        $class = false !== strpos($type, '\\') ?
            $type :
            '\\think\\config\\driver\\' . ucwords($type);

        return self::set((new $class())->parse($config), $name, $range);
    }

    /* *
 * Load a configuration file.
 * @access public
 * @param string $file configuration file name
 * @param string $name Configuration section name, if supplied
 * @param string $range scope
 * @return mixed */
    public static function load($file, $name = '', $range = '')
    {
        $range = $range ?: self::$range;

        if (!isset(self::$config[$range])) self::$config[$range] = [];

        if (is_file($file)) {
            $name = strtolower($name);
            $type = pathinfo($file, PATHINFO_EXTENSION);

            if ('php' == $type) {
                return self::set(include $file, $name, $range);
            }

            if ('yaml' == $type && function_exists('yaml_parse_file')) {
                return self::set(yaml_parse_file($file), $name, $range);
            }

            return self::parse($file, $type, $name, $range);
        }

        return self::$config[$range];
    }

    /* *
 * Check if the configuration exists
 * @access public
 * @param string $name Configuration key; a dot separates a nested key
 * @param string $range scope
 * @return bool */
    public static function has($name, $range = '')
    {
        $range = $range ?: self::$range;

        if (!strpos($name, '.')) {
            return isset(self::$config[$range][strtolower($name)]);
        }

        // Split a nested configuration key at the first dot.
        $name = explode('.', $name, 2);
        return isset(self::$config[$range][strtolower($name[0])][$name[1]]);
    }

    /* *
 * Get a configuration value, or all settings when the name is empty.
 * @access public
 * @param string $name Configuration key; a dot separates a nested key
 * @param string $range scope
 * @return mixed */
    public static function get($name = null, $range = '')
    {
        $range = $range ?: self::$range;

        // Return all settings when the name is empty.
        if (empty($name) && isset(self::$config[$range])) {
            return self::$config[$range];
        }

        // Return a top-level configuration value.
        if (!strpos($name, '.')) {
            $name = strtolower($name);
            return isset(self::$config[$range][$name]) ? self::$config[$range][$name] : null;
        }

        // Split a nested configuration key at the first dot.
        $name    = explode('.', $name, 2);
        $name[0] = strtolower($name[0]);

        if (!isset(self::$config[$range][$name[0]])) {
            // Dynamically load additional configurations
            $module = Request::instance()->module();
            $file   = CONF_PATH . ($module ? $module . DS : '') . 'extra' . DS . $name[0] . CONF_EXT;

            is_file($file) && self::load($file, $name[0]);
        }

        return isset(self::$config[$range][$name[0]][$name[1]]) ?
            self::$config[$range][$name[0]][$name[1]] :
            null;
    }

    /* *
 * Set configuration values; an array sets multiple values.
 * @access public
 * @param string|array $name Configuration key, including a nested key, or an array of settings
 * @param mixed $value configuration value
 * @param string $range scope
 * @return mixed */
    public static function set($name, $value = null, $range = '')
    {
        $range = $range ?: self::$range;

        if (!isset(self::$config[$range])) self::$config[$range] = [];

        // The string represents a single configuration setting
        if (is_string($name)) {
            if (!strpos($name, '.')) {
                self::$config[$range][strtolower($name)] = $value;
            } else {
                // Set a nested configuration value.
                $name = explode('.', $name, 2);
                self::$config[$range][strtolower($name[0])][$name[1]] = $value;
            }

            return $value;
        }

        // Arrays indicate batch settings
        if (is_array($name)) {
            if (!empty($value)) {
                self::$config[$range][$value] = isset(self::$config[$range][$value]) ?
                    array_merge(self::$config[$range][$value], $name) :
                    $name;

                return self::$config[$range][$value];
            }

            return self::$config[$range] = array_merge(
                self::$config[$range], array_change_key_case($name)
            );
        }

        // Return the existing configuration for unsupported parameter types.
        return self::$config[$range];
    }

    /* *
 * Reset configuration parameters
 * @access public
 * @param string $range scope
 * @return void */
    public static function reset($range = '')
    {
        $range = $range ?: self::$range;

        if (true === $range) {
            self::$config = [];
        } else {
            self::$config[$range] = [];
        }
    }
}
