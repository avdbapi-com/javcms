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

class Cookie
{
    /* *
 * @var array Cookie configuration */
    protected static $config = [
        'prefix'    => '', // cookie name prefix
        'expire'    => 0, // Cookie lifetime in seconds; zero creates a session cookie
        'path'      => '/', // URL path where the cookie is available
        'domain'    => '', // Valid domain name for cookies
        'secure'    => false, // Enable secure transmission of cookies
        'httponly'  => false, // Whether to set the HttpOnly flag
        'setcookie' => true, // Whether to use setcookie
    ];

    /* *
 * @var bool Whether initialization has completed */
    protected static $init;

    /* *
 * Initialize cookie configuration.
 * @access public
 * @param array $config configuration parameters
 * @return void */
    public static function init(array $config = [])
    {
        if (empty($config)) {
            $config = Config::get('cookie');
        }

        self::$config = array_merge(self::$config, array_change_key_case($config));

        if (!empty(self::$config['httponly'])) {
            ini_set('session.cookie_httponly', 1);
        }

        self::$init = true;
    }

    /* *
 * Set or get the cookie scope (prefix)
 * @access public
 * @param string $prefix
 * @return string */
    public static function prefix($prefix = '')
    {
        if (empty($prefix)) {
            return self::$config['prefix'];
        }

        return self::$config['prefix'] = $prefix;
    }

    /* *
 * Set a cookie value.
 * @access public
 * @param string $name cookie name
 * @param mixed $value cookie value
 * @param mixed $option Lifetime in seconds, query-string options, an options array, or null
 * @return void */
    public static function set($name, $value = '', $option = null)
    {
        !isset(self::$init) && self::init();

        // Per-call options override the default configuration.
        if (!is_null($option)) {
            if (is_numeric($option)) {
                $option = ['expire' => $option];
            } elseif (is_string($option)) {
                parse_str($option, $option);
            }

            $config = array_merge(self::$config, array_change_key_case($option));
        } else {
            $config = self::$config;
        }

        $name = $config['prefix'] . $name;

        // Encode array values with the framework cookie marker.
        if (is_array($value)) {
            array_walk_recursive($value, [self::class, 'jsonFormatProtect'], 'encode');
            $value = 'think:' . json_encode($value);
        }

        $expire = !empty($config['expire']) ?
        $_SERVER['REQUEST_TIME'] + intval($config['expire']) :
        0;

        if ($config['setcookie']) {
            setcookie(
                $name, is_null($value) ? '' : $value, $expire, $config['path'], $config['domain'],
                $config['secure'], $config['httponly']
            );
        }

        $_COOKIE[$name] = $value;
    }

    /* *
 * Save a cookie with a lifetime of 315360000 seconds.
 * @access public
 * @param string $name cookie name
 * @param mixed $value cookie value
 * @param mixed $option Query-string options or an options array; numeric values and null use defaults
 * @return void */
    public static function forever($name, $value = '', $option = null)
    {
        if (is_null($option) || is_numeric($option)) {
            $option = [];
        } elseif (is_string($option)) {
            parse_str($option, $option);
        }

        $option['expire'] = 315360000;

        self::set($name, $value, $option);
    }

    /* *
 * Check whether a cookie value is set.
 * @access public
 * @param string $name cookie name
 * @param string|null $prefix Cookie prefix; null uses the configured prefix
 * @return bool */
    public static function has($name, $prefix = null)
    {
        !isset(self::$init) && self::init();

        $prefix = !is_null($prefix) ? $prefix : self::$config['prefix'];

        return isset($_COOKIE[$prefix . $name]);
    }

    /* *
 * Get a cookie value, or all cookies matching the selected prefix.
 * @access public
 * @param string $name Cookie name
 * @param string|null $prefix Cookie prefix; null uses the configured prefix
 * @return mixed */
    public static function get($name = '', $prefix = null)
    {
        !isset(self::$init) && self::init();

        $prefix = !is_null($prefix) ? $prefix : self::$config['prefix'];
        $key    = $prefix . $name;

        if ('' == $name) {
            // Get all matching cookies, retaining their full names.
            if ($prefix) {
                $value = [];

                foreach ($_COOKIE as $k => $val) {
                    if (0 === strpos($k, $prefix)) {
                        $value[$k] = $val;
                    }

                }
            } else {
                $value = $_COOKIE;
            }
        } elseif (isset($_COOKIE[$key])) {
            $value = $_COOKIE[$key];

            if (is_string($value) && 0 === strpos($value, 'think:')) {
                $value = json_decode(substr($value, 6), true);
                if (is_array($value)) {
                    array_walk_recursive($value, [self::class, 'jsonFormatProtect'], 'decode');
                }
            }
        } else {
            $value = null;
        }

        return $value;
    }

    /* *
 * Delete a cookie.
 * @access public
 * @param string $name cookie name
 * @param string|null $prefix Cookie prefix; null uses the configured prefix
 * @return void */
    public static function delete($name, $prefix = null)
    {
        !isset(self::$init) && self::init();

        $config = self::$config;
        $prefix = !is_null($prefix) ? $prefix : $config['prefix'];
        $name   = $prefix . $name;

        if ($config['setcookie']) {
            setcookie(
                $name, '', $_SERVER['REQUEST_TIME'] - 3600, $config['path'],
                $config['domain'], $config['secure'], $config['httponly']
            );
        }

        // Delete the specified cookie
        unset($_COOKIE[$name]);
    }

    /* *
 * Delete cookies matching a nonempty prefix.
 * @access public
 * @param string|null $prefix Cookie prefix; null uses the configured prefix
 * @return void */
    public static function clear($prefix = null)
    {
        if (empty($_COOKIE)) {
            return;
        }

        !isset(self::$init) && self::init();

        // Use the supplied prefix, or the configured prefix when null.
        $config = self::$config;
        $prefix = !is_null($prefix) ? $prefix : $config['prefix'];

        if ($prefix) {
            foreach ($_COOKIE as $key => $val) {
                if (0 === strpos($key, $prefix)) {
                    if ($config['setcookie']) {
                        setcookie(
                            $key, '', $_SERVER['REQUEST_TIME'] - 3600, $config['path'],
                            $config['domain'], $config['secure'], $config['httponly']
                        );
                    }

                    unset($_COOKIE[$key]);
                }
            }
        }
    }

    /* *
 * URL-encode or decode nonempty, non-true array values for JSON storage.
 * @access protected
 * @param mixed $val the value to convert
 * @param string $key key
 * @param string $type Conversion mode: decode or encode
 * @return void */
    protected static function jsonFormatProtect(&$val, $key, $type = 'encode')
    {
        if (!empty($val) && true !== $val) {
            $val = 'decode' == $type ? urldecode($val) : urlencode($val);
        }
    }
}
