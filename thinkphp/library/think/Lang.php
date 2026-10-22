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

class Lang
{
    /* *
 * @var array language data */
    private static $lang = [];

    /* *
 * @var string Language scope */
    private static $range = 'en-us';

    /* *
 * @var string Request variable name used for language detection */
    protected static $langDetectVar = 'lang';

    /* *
 * @var string Language cookie name */
    protected static $langCookieVar = 'think_var';

    /* *
 * @var int Language cookie expiration time */
    protected static $langCookieExpire = 3600;

    /* *
 * @var array list of allowed languages */
    protected static $allowLangList = [];

    /* *
 * @var array Default mappings from Accept-Language values to language-pack names */
    protected static $acceptLanguage = ['zh-hans-cn' => 'en-us'];

    /* *
 * Get or set the current language scope.
 * @access public
 * @param string $range language scope
 * @return string */
    public static function range($range = '')
    {
        if ($range) {
            self::$range = $range;
        }

        return self::$range;
    }

    /* *
 * Set language definitions (not case sensitive)
 * @access public
 * @param string|array $name Language key or array of definitions
 * @param string $value language value
 * @param string $range language scope
 * @return mixed */
    public static function set($name, $value = null, $range = '')
    {
        $range = $range ?: self::$range;

        if (!isset(self::$lang[$range])) {
            self::$lang[$range] = [];
        }

        if (is_array($name)) {
            return self::$lang[$range] = array_change_key_case($name) + self::$lang[$range];
        }

        return self::$lang[$range][strtolower($name)] = $value;
    }

    /* *
 * Load language definitions (case insensitive)
 * @access public
 * @param array|string $file Language file or list of files
 * @param string $range language scope
 * @return mixed */
    public static function load($file, $range = '')
    {
        $range = $range ?: self::$range;
        $file  = is_string($file) ? [$file] : $file;

        if (!isset(self::$lang[$range])) {
            self::$lang[$range] = [];
        }

        $lang = [];

        foreach ($file as $_file) {
            if (is_file($_file)) {
                // Record loading information
                App::$debug && Log::record('[ LANG ] ' . $_file, 'info');

                $_lang = include $_file;

                if (is_array($_lang)) {
                    $lang = array_change_key_case($_lang) + $lang;
                }
            }
        }

        if (!empty($lang)) {
            self::$lang[$range] = $lang + self::$lang[$range];
        }

        return self::$lang[$range];
    }

    /* *
 * Check whether a language definition exists (case insensitive).
 * @access public
 * @param string|null $name language variable
 * @param string $range language scope
 * @return mixed */
    public static function has($name, $range = '')
    {
        $range = $range ?: self::$range;

        return isset(self::$lang[$range][strtolower($name)]);
    }

    /* *
 * Get language definitions (case insensitive)
 * @access public
 * @param string|null $name language variable
 * @param array $vars Replacement values
 * @param string $range language scope
 * @return mixed */
    public static function get($name = null, $vars = [], $range = '')
    {
        $range = $range ?: self::$range;

        // Return all definitions when the name is empty.
        if (empty($name)) {
            return self::$lang[$range];
        }

        $key   = strtolower($name);
        $value = isset(self::$lang[$range][$key]) ? self::$lang[$range][$key] : $name;

        // Substitute replacement values.
        if (!empty($vars) && is_array($vars)) {
            /* *
 * Notes:
 * Use positional replacement when the current array key is integer 0.
 * Use sprintf for positional replacement; see its documentation for formatting details. */
            if (key($vars) === 0) {
                // Format positional replacements with sprintf.
                array_unshift($vars, $value);
                $value = call_user_func_array('sprintf', $vars);
            } else {
                // Replace placeholders based on array keys.
                $replace = array_keys($vars);
                foreach ($replace as &$v) {
                    $v = "{:{$v}}";
                }
                $value = str_replace($replace, $vars, $value);
            }

        }

        return $value;
    }

    /* *
 * Detect language from request variables, cookies or headers.
 * @access public
 * @return string */
    public static function detect()
    {
        $langSet = '';

        if (isset($_GET[self::$langDetectVar])) {
            // Use the language supplied in the URL.
            $langSet = is_scalar($_GET[self::$langDetectVar]) ? strtolower((string) $_GET[self::$langDetectVar]) : "";
        } elseif (isset($_COOKIE[self::$langCookieVar])) {
            // Use the language stored in the cookie.
            $langSet = is_scalar($_COOKIE[self::$langCookieVar]) ? strtolower((string) $_COOKIE[self::$langCookieVar]) : "";
        } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            // Detect browser language automatically
            if (is_scalar($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('/\A([a-z\d]+(?:[-_][a-z\d]+)*)(?=[ \t]*(?:[,;]|\z))/i', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches)) {
                $langSet     = strtolower($matches[1]);
                $acceptLangs = Config::get('header_accept_lang');

                if (isset($acceptLangs[$langSet])) {
                    $langSet = $acceptLangs[$langSet];
                } elseif (isset(self::$acceptLanguage[$langSet])) {
                    $langSet = self::$acceptLanguage[$langSet];
                }
            }
        }

        // Accept a valid language identifier allowed by the configured list.
        if (is_string($langSet) && preg_match('/\A[a-z\d]+(?:[-_][a-z\d]+)*\z/i', $langSet)
            && (empty(self::$allowLangList) || in_array($langSet, self::$allowLangList))) {
            self::$range = $langSet ?: self::$range;
        }

        return self::$range;
    }

    /* *
 * Set the request variable name for language detection.
 * @access public
 * @param string $var variable name
 * @return void */
    public static function setLangDetectVar($var)
    {
        self::$langDetectVar = $var;
    }

    /* *
 * Set the language cookie name.
 * @access public
 * @param string $var variable name
 * @return void */
    public static function setLangCookieVar($var)
    {
        self::$langCookieVar = $var;
    }

    /* *
 * Set the expiration time of the language cookie
 * @access public
 * @param string $expire Expiry time
 * @return void */
    public static function setLangCookieExpire($expire)
    {
        self::$langCookieExpire = $expire;
    }

    /* *
 * Set the list of allowed languages
 * @access public
 * @param array $list language list
 * @return void */
    public static function setAllowLangList($list)
    {
        self::$allowLangList = $list;
    }
}
