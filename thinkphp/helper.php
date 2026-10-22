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

// ------------------------
// ThinkPHP helper functions
// -------------------------

use think\Cache;
use think\Config;
use think\Cookie;
use think\Db;
use think\Debug;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\Lang;
use think\Loader;
use think\Log;
use think\Model;
use think\Request;
use think\Response;
use think\Session;
use think\Url;
use think\View;

if (!function_exists('load_trait')) {
    /* *
 * Import a trait from TRAIT_PATH
 * @param string $class trait library
 * @param string $ext class library extension
 * @return boolean */
    function load_trait($class, $ext = EXT)
    {
        return Loader::import($class, TRAIT_PATH, $ext);
    }
}

if (!function_exists('exception')) {
    /* *
 * Throw an exception
 *
 * @param string $msg Exception message
 * @param integer $code The exception code is 0 by default
 * @param string $exception Exception class
 *
 * @throws Exception */
    function exception($msg, $code = 0, $exception = '')
    {
        $e = $exception ?: '\think\Exception';
        throw new $e($msg, $code);
    }
}

if (!function_exists('debug')) {
    /* *
 * Record a timing/memory mark or retrieve elapsed time in seconds or a memory difference
 * @param string $start start tag
 * @param string $end end tag
 * @param integer|string $dec Decimal places for elapsed time, or 'm' for memory usage
 * @return mixed */
    function debug($start, $end = '', $dec = 6)
    {
        if ('' == $end) {
            Debug::remark($start);
        } else {
            return 'm' == $dec ? Debug::getRangeMem($start, $end) : Debug::getRangeTime($start, $end, $dec);
        }
    }
}

if (!function_exists('lang')) {
    /* *
 * Get language variable values
 * @param string $name language variable name
 * @param array $vars dynamic variable values
 * @param string $lang language
 * @return mixed */
    function lang($name, $vars = [], $lang = '')
    {
        return Lang::get($name, $vars, $lang);
    }
}

if (!function_exists('config')) {
    /* *
 * Get and set configuration parameters
 * @param string|array $name parameter name
 * @param mixed $value parameter value
 * @param string $range scope
 * @return mixed */
    function config($name = '', $value = null, $range = '')
    {
        if (is_null($value) && is_string($name)) {
            return 0 === strpos($name, '?') ? Config::has(substr($name, 1), $range) : Config::get($name, $range);
        } else {
            return Config::set($name, $value, $range);
        }
    }
}

if (!function_exists('input')) {
    /* *
 * Get input data with optional default values and filters
 * @param string $key Input variable name, optionally prefixed with its source
 * @param mixed $default Default value
 * @param string $filter filter method
 * @return mixed */
    function input($key = '', $default = null, $filter = '')
    {
        if (0 === strpos($key, '?')) {
            $key = substr($key, 1);
            $has = true;
        }
        if ($pos = strpos($key, '.')) {
            // Specify the source of the parameter
            list($method, $key) = explode('.', $key, 2);
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'route', 'param', 'request', 'session', 'cookie', 'server', 'env', 'path', 'file'])) {
                $key    = $method . '.' . $key;
                $method = 'param';
            }
        } else {
            // Use the combined request parameters by default
            $method = 'param';
        }
        if (isset($has)) {
            return request()->has($key, $method, $default);
        } else {
            return request()->$method($key, $default, $filter);
        }
    }
}

if (!function_exists('widget')) {
    /* *
 * Call a widget action
 * @param string $name widget
 * @param array $data incoming parameters
 * @return mixed */
    function widget($name, $data = [])
    {
        return Loader::action($name, $data, 'widget');
    }
}

if (!function_exists('model')) {
    /* *
 * Get a model instance
 * @param string $name model
 * @param string $layer Model layer name
 * @param bool $appendSuffix Whether to append the layer name to the class name
 * @return \think\Model */
    function model($name = '', $layer = 'model', $appendSuffix = false)
    {
        return Loader::model($name, $layer, $appendSuffix);
    }
}

if (!function_exists('validate')) {
    /* *
 * Instantiate validators
 * @param string $name Validator name
 * @param string $layer Business layer name
 * @param bool $appendSuffix whether to add a class name suffix
 * @return \think\Validate */
    function validate($name = '', $layer = 'validate', $appendSuffix = false)
    {
        return Loader::validate($name, $layer, $appendSuffix);
    }
}

if (!function_exists('db')) {
    /* *
 * Get a database query instance for a table
 * @param string $name The name of the data table for the operation (without prefix)
 * @param array|string $config database configuration parameters
 * @param bool $force Whether to force a reconnection
 * @return \think\db\Query */
    function db($name = '', $config = [], $force = false)
    {
        return Db::connect($config, $force)->name($name);
    }
}

if (!function_exists('controller')) {
    /* *
 * Instantiate a controller using the format [module/]controller
 * @param string $name Controller name
 * @param string $layer Controller layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @return \think\Controller */
    function controller($name, $layer = 'controller', $appendSuffix = false)
    {
        return Loader::controller($name, $layer, $appendSuffix);
    }
}

if (!function_exists('action')) {
    /* *
 * Call an action using the format [module/controller/]action
 * @param string $url Action address
 * @param string|array $vars Action arguments as a string or array
 * @param string $layer Controller layer to use
 * @param bool $appendSuffix Whether to add the class name suffix
 * @return mixed */
    function action($url, $vars = [], $layer = 'controller', $appendSuffix = false)
    {
        return Loader::action($url, $vars, $layer, $appendSuffix);
    }
}

if (!function_exists('import')) {
    /* *
 * Import a class library file, reusing the import cache for repeated requests
 * @param string $class library namespace string
 * @param string $baseUrl starting path
 * @param string $ext imported file extension
 * @return boolean */
    function import($class, $baseUrl = '', $ext = EXT)
    {
        return Loader::import($class, $baseUrl, $ext);
    }
}

if (!function_exists('vendor')) {
    /* *
 * Import a third-party class library file from VENDOR_PATH
 * @param string $class class library
 * @param string $ext class library suffix
 * @return boolean */
    function vendor($class, $ext = EXT)
    {
        return Loader::import($class, VENDOR_PATH, $ext);
    }
}

if (!function_exists('dump')) {
    /* *
 * Browser-friendly variable output
 * @param mixed $var variables
 * @param boolean $echo Whether to print the output; false returns it as a string
 * @param string $label Optional output label
 * @return void|string */
    function dump($var, $echo = true, $label = null)
    {
        return Debug::dump($var, $echo, $label);
    }
}

if (!function_exists('url')) {
    /* *
 * Url generation
 * @param string $url Route address
 * @param string|array $vars URL parameters
 * @param bool|string $suffix URL suffix generated
 * @param bool|string $domain
 * @return string */
    function url($url = '', $vars = '', $suffix = true, $domain = false)
    {
        return Url::build($url, $vars, $suffix, $domain);
    }
}

if (!function_exists('session')) {
    /* *
 * Session management
 * @param string|array $name Session key or an array of session settings
 * @param mixed $value session value
 * @param string $prefix
 * @return mixed */
    function session($name, $value = '', $prefix = null)
    {
        if (is_array($name)) {
            // Initialize session settings
            Session::init($name);
        } elseif (is_null($name)) {
            // Clear session data
            Session::clear('' === $value ? null : $value);
        } elseif ('' === $value) {
            // Check whether a session key exists or retrieve its value
            return 0 === strpos($name, '?') ? Session::has(substr($name, 1), $prefix) : Session::get($name, $prefix);
        } elseif (is_null($value)) {
            // Delete a session key
            return Session::delete($name, $prefix);
        } else {
            // Set a session value
            return Session::set($name, $value, $prefix);
        }
    }
}

if (!function_exists('cookie')) {
    /* *
 * Cookie management
 * @param string|array $name Cookie name or an array of cookie settings
 * @param mixed $value cookie value
 * @param mixed $option parameters
 * @return mixed */
    function cookie($name, $value = '', $option = null)
    {
        if (is_array($name)) {
            // Initialize cookie settings
            Cookie::init($name);
        } elseif (is_null($name)) {
            // Clear cookies
            Cookie::clear($value);
        } elseif ('' === $value) {
            // Check whether a cookie exists or retrieve its value
            return 0 === strpos($name, '?') ? Cookie::has(substr($name, 1), $option) : Cookie::get($name, $option);
        } elseif (is_null($value)) {
            // Delete a cookie
            return Cookie::delete($name);
        } else {
            // Set a cookie value
            return Cookie::set($name, $value, $option);
        }
    }
}

if (!function_exists('cache')) {
    /* *
 * Cache management
 * @param mixed $name Cache key or an array of cache connection settings
 * @param mixed $value cached value
 * @param mixed $options cache parameters
 * @param string $tag Cache tag
 * @return mixed */
    function cache($name, $value = '', $options = null, $tag = null)
    {
        if (is_array($options)) {
            // Use the supplied connection settings for this cache operation
            $cache = Cache::connect($options);
        } elseif (is_array($name)) {
            // Return a cache connection using the supplied settings
            return Cache::connect($name);
        } else {
            $cache = Cache::init();
        }

        if (is_null($name)) {
            return $cache->clear($value);
        } elseif ('' === $value) {
            // Check whether a cache key exists or retrieve its value
            return 0 === strpos($name, '?') ? $cache->has(substr($name, 1)) : $cache->get($name);
        } elseif (is_null($value)) {
            // Delete a cache entry
            return $cache->rm($name);
        } elseif (0 === strpos($name, '?') && '' !== $value) {
            $expire = is_numeric($options) ? $options : null;
            return $cache->remember(substr($name, 1), $value, $expire);
        } else {
            // Store the cache value
            if (is_array($options)) {
                $expire = isset($options['expire']) ? $options['expire'] : null; // Use the expiration from the options array
            } else {
                $expire = is_numeric($options) ? $options : null; // A numeric options value specifies the expiration
            }
            if (is_null($tag)) {
                return $cache->set($name, $value, $expire);
            } else {
                return $cache->tag($tag)->set($name, $value, $expire);
            }
        }
    }
}

if (!function_exists('trace')) {
    /* *
 * Retrieve recorded logs or record a log entry
 * @param mixed $log log information supports strings and arrays
 * @param string $level log level
 * @return void|array */
    function trace($log = '[think]', $level = 'log')
    {
        if ('[think]' === $log) {
            return Log::getLog();
        } else {
            Log::record($log, $level);
        }
    }
}

if (!function_exists('request')) {
    /* *
 * Get the current Request instance
 * @return Request */
    function request()
    {
        return Request::instance();
    }
}

if (!function_exists('response')) {
    /* *
 * Create a Response object
 * @param mixed $data output data
 * @param int|string $code status code
 * @param array $header header info
 * @param string $type
 * @return Response */
    function response($data = [], $code = 200, $header = [], $type = 'html')
    {
        return Response::create($data, $type, $code, $header);
    }
}

if (!function_exists('view')) {
    /* *
 * Create a template response
 * @param string $template template file
 * @param array $vars Template variables
 * @param array $replace Output string replacements
 * @param integer $code status code
 * @return \think\response\View */
    function view($template = '', $vars = [], $replace = [], $code = 200)
    {
        return Response::create($template, 'view', $code)->replace($replace)->assign($vars);
    }
}

if (!function_exists('json')) {
    /* *
 * Create a JSON response
 * @param mixed $data returned data
 * @param integer $code status code
 * @param array $header Response headers
 * @param array $options
 * @return \think\response\Json */
    function json($data = [], $code = 200, $header = [], $options = [])
    {
        return Response::create($data, 'json', $code, $header, $options);
    }
}

if (!function_exists('jsonp')) {
    /* *
 * Create a JSONP response
 * @param mixed $data returned data
 * @param integer $code status code
 * @param array $header header
 * @param array $options parameters
 * @return \think\response\Jsonp */
    function jsonp($data = [], $code = 200, $header = [], $options = [])
    {
        return Response::create($data, 'jsonp', $code, $header, $options);
    }
}

if (!function_exists('xml')) {
    /* *
 * Create an XML response
 * @param mixed $data returned data
 * @param integer $code status code
 * @param array $header Response headers
 * @param array $options
 * @return \think\response\Xml */
    function xml($data = [], $code = 200, $header = [], $options = [])
    {
        return Response::create($data, 'xml', $code, $header, $options);
    }
}

if (!function_exists('redirect')) {
    /* *
 * Create a redirect response
 * @param mixed $url Redirect target, including addresses supported by Url::build
 * @param array|integer $params URL parameters or the HTTP status code
 * @param integer $code status code
 * @param array $with Data to flash to the session for the redirect
 * @return \think\response\Redirect */
    function redirect($url = [], $params = [], $code = 302, $with = [])
    {
        if (is_integer($params)) {
            $code   = $params;
            $params = [];
        }
        return Response::create($url, 'redirect', $code)->params($params)->with($with);
    }
}

if (!function_exists('abort')) {
    /* *
 * Throw an HTTP exception
 * @param integer|Response $code HTTP status code or a Response instance
 * @param string $message error message
 * @param array $header Response headers */
    function abort($code, $message = null, $header = [])
    {
        if ($code instanceof Response) {
            throw new HttpResponseException($code);
        } else {
            throw new HttpException($code, $message, null, $header);
        }
    }
}

if (!function_exists('halt')) {
    /* *
 * Dump a variable and interrupt request processing
 * @param mixed $var debugging variables or information */
    function halt($var)
    {
        dump($var);
        throw new HttpResponseException(new Response);
    }
}

if (!function_exists('token')) {
    /* *
 * Generate a hidden form input containing a token
 * @param string $name Token name
 * @param mixed $type Token generation method
 * @return string */
    function token($name = '__token__', $type = 'md5')
    {
        $token = Request::instance()->token($name, $type);
        return '<input type="hidden" name="' . $name . '" value="' . $token . '" />';
    }
}

if (!function_exists('load_relation')) {
    /* *
 * Eagerly load relations for a model result set
 * @param mixed $resultSet Result set
 * @param mixed $relation Relations to load
 * @return array */
    function load_relation($resultSet, $relation)
    {
        $item = current($resultSet);
        if ($item instanceof Model) {
            $item->eagerlyResultSet($resultSet, $relation);
        }
        return $resultSet;
    }
}

if (!function_exists('collection')) {
    /* *
 * Convert an array to a collection object
 * @param array $resultSet Result set
 * @return \think\model\Collection|\think\Collection */
    function collection($resultSet)
    {
        $item = current($resultSet);
        if ($item instanceof Model) {
            return \think\model\Collection::make($resultSet);
        } else {
            return \think\Collection::make($resultSet);
        }
    }
}
