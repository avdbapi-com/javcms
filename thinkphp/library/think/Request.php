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

class Request
{
    /* *
 * @var object Shared request instance */
    protected static $instance;

    protected $method;
    /* *
 * @var string The domain name (including protocol and port) */
    protected $domain;

    /* *
 * @var string Request URL */
    protected $url;

    /* *
 * @var string Base URL */
    protected $baseUrl;

    /* *
 * @var string Currently executed file */
    protected $baseFile;

    /* *
 * @var string Request root URL */
    protected $root;

    /* *
 * @var string pathinfo */
    protected $pathinfo;

    /* *
 * @var string pathinfo without the URL suffix */
    protected $path;

    /* *
 * @var array Current route information */
    protected $routeInfo = [];

    /* *
 * @var array environment variables */
    protected $env;

    /* *
 * @var array Current dispatch information */
    protected $dispatch = [];
    protected $module;
    protected $controller;
    protected $action;
    // Current language set
    protected $langset;

    /* *
 * @var array Request parameters */
    protected $param   = [];
    protected $get     = [];
    protected $post    = [];
    protected $request = [];
    protected $route   = [];
    protected $put;
    protected $session = [];
    protected $file    = [];
    protected $cookie  = [];
    protected $server  = [];
    protected $header  = [];

    /* *
 * @var array Supported MIME-type mappings */
    protected $mimeType = [
        'xml'   => 'application/xml,text/xml,application/x-xml',
        'json'  => 'application/json,text/x-json,application/jsonrequest,text/json',
        'js'    => 'text/javascript,application/javascript,application/x-javascript',
        'css'   => 'text/css',
        'rss'   => 'application/rss+xml',
        'yaml'  => 'application/x-yaml,text/yaml',
        'atom'  => 'application/atom+xml',
        'pdf'   => 'application/pdf',
        'text'  => 'text/plain',
        'image' => 'image/png,image/jpg,image/jpeg,image/pjpeg,image/gif,image/webp,image/*',
        'csv'   => 'text/csv',
        'html'  => 'text/html,application/xhtml+xml,*/*',
    ];

    protected $content;

    // Global filter rules
    protected $filter;
    // Registered extension callbacks
    protected static $hook = [];
    // Bound properties
    protected $bind = [];
    // php://input
    protected $input;
    // Request caching
    protected $cache;
    // Whether the cache is checked
    protected $isCheckCache;
    /* *
 * Whether request parameters have been merged
 * @var bool */
    protected $mergeParam = false;

    /* *
 * Constructor
 * @access protected
 * @param array $options Initial request properties */
    protected function __construct($options = [])
    {
        foreach ($options as $name => $item) {
            if (property_exists($this, $name)) {
                $this->$name = $item;
            }
        }
        if (is_null($this->filter)) {
            $this->filter = Config::get('default_filter');
        }

        // Read the raw request body from php://input
        $this->input = file_get_contents('php://input');
    }

    public function __call($method, $args)
    {
        if (array_key_exists($method, self::$hook)) {
            array_unshift($args, $this);
            return call_user_func_array(self::$hook[$method], $args);
        } else {
            throw new Exception('method not exists:' . __CLASS__ . '->' . $method);
        }
    }

    /* *
 * Register extension callbacks
 * @access public
 * @param string|array $method method name
 * @param mixed $callback callable
 * @return void */
    public static function hook($method, $callback = null)
    {
        if (is_array($method)) {
            self::$hook = array_merge(self::$hook, $method);
        } else {
            self::$hook[$method] = $callback;
        }
    }

    /* *
 * Get the shared request instance
 * @access public
 * @param array $options
 * @return \think\Request */
    public static function instance($options = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new static($options);
        }
        return self::$instance;
    }

    /* *
 * Clear the shared request instance
 * @access public
 * @return void */
    public static function destroy()
    {
        if (!is_null(self::$instance)) {
            self::$instance = null;
        }
    }

    /* *
 * Create a request instance from a URL and supplied data
 * @access public
 * @param string $uri URL
 * @param string $method HTTP method
 * @param array $params request parameters
 * @param array $cookie
 * @param array $files
 * @param array $server
 * @param string $content
 * @return \think\Request */
    public static function create($uri, $method = 'GET', $params = [], $cookie = [], $files = [], $server = [], $content = null)
    {
        $server['PATH_INFO']      = '';
        $server['REQUEST_METHOD'] = strtoupper($method);
        $info                     = parse_url($uri);
        if (isset($info['host'])) {
            $server['SERVER_NAME'] = $info['host'];
            $server['HTTP_HOST']   = $info['host'];
        }
        if (isset($info['scheme'])) {
            if ('https' === $info['scheme']) {
                $server['HTTPS']       = 'on';
                $server['SERVER_PORT'] = 443;
            } else {
                unset($server['HTTPS']);
                $server['SERVER_PORT'] = 80;
            }
        }
        if (isset($info['port'])) {
            $server['SERVER_PORT'] = $info['port'];
            $server['HTTP_HOST']   = $server['HTTP_HOST'] . ':' . $info['port'];
        }
        if (isset($info['user'])) {
            $server['PHP_AUTH_USER'] = $info['user'];
        }
        if (isset($info['pass'])) {
            $server['PHP_AUTH_PW'] = $info['pass'];
        }
        if (!isset($info['path'])) {
            $info['path'] = '/';
        }
        $options                      = [];
        $options[strtolower($method)] = $params;
        $queryString                  = '';
        if (isset($info['query'])) {
            parse_str(html_entity_decode($info['query']), $query);
            if (!empty($params)) {
                $params      = array_replace($query, $params);
                $queryString = http_build_query($params, '', '&');
            } else {
                $params      = $query;
                $queryString = $info['query'];
            }
        } elseif (!empty($params)) {
            $queryString = http_build_query($params, '', '&');
        }
        if ($queryString) {
            parse_str($queryString, $get);
            $options['get'] = isset($options['get']) ? array_merge($get, $options['get']) : $get;
        }

        $server['REQUEST_URI']  = $info['path'] . ('' !== $queryString ? '?' . $queryString : '');
        $server['QUERY_STRING'] = $queryString;
        $options['cookie']      = $cookie;
        $options['param']       = $params;
        $options['file']        = $files;
        $options['server']      = $server;
        $options['url']         = $server['REQUEST_URI'];
        $options['baseUrl']     = $info['path'];
        $options['pathinfo']    = '/' == $info['path'] ? '/' : ltrim($info['path'], '/');
        $options['method']      = $server['REQUEST_METHOD'];
        $options['domain']      = isset($info['scheme']) ? $info['scheme'] . '://' . $server['HTTP_HOST'] : '';
        $options['content']     = $content;
        self::$instance         = new self($options);
        return self::$instance;
    }

    /* *
 * Get or set the request domain, including scheme and port
 * @access public
 * @param string $domain
 * @return string */
    public function domain($domain = null)
    {
        if (!is_null($domain)) {
            $this->domain = $domain;
            return $this;
        } elseif (!$this->domain) {
            $this->domain = $this->scheme() . '://' . $this->host();
        }
        return $this->domain;
    }

    /* *
 * Set or get the current full URL including QUERY_STRING
 * @access public
 * @param string|true $url URL to set; true includes the domain when retrieving
 * @return string */
    public function url($url = null)
    {
        if (!is_null($url) && true !== $url) {
            $this->url = $url;
            return $this;
        } elseif (!$this->url) {
            if (IS_CLI) {
                $this->url = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
            } elseif (isset($_SERVER['HTTP_X_REWRITE_URL'])) {
                $this->url = $_SERVER['HTTP_X_REWRITE_URL'];
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $this->url = $_SERVER['REQUEST_URI'];
            } elseif (isset($_SERVER['ORIG_PATH_INFO'])) {
                $this->url = $_SERVER['ORIG_PATH_INFO'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
            } else {
                $this->url = '';
            }
        }
        return true === $url ? $this->domain() . $this->url : $this->url;
    }

    /* *
 * Set or get the current URL without QUERY_STRING
 * @access public
 * @param string $url URL
 * @return string */
    public function baseUrl($url = null)
    {
        if (!is_null($url) && true !== $url) {
            $this->baseUrl = $url;
            return $this;
        } elseif (!$this->baseUrl) {
            $str           = $this->url();
            $this->baseUrl = strpos($str, '?') ? strstr($str, '?', true) : $str;
        }
        return true === $url ? $this->domain() . $this->baseUrl : $this->baseUrl;
    }

    /* *
 * Set or get the currently executed file SCRIPT_NAME
 * @access public
 * @param string $file Currently executed file
 * @return string */
    public function baseFile($file = null)
    {
        if (!is_null($file) && true !== $file) {
            $this->baseFile = $file;
            return $this;
        } elseif (!$this->baseFile) {
            $url = '';
            if (!IS_CLI) {
                $script_name = basename($_SERVER['SCRIPT_FILENAME']);
                if (basename($_SERVER['SCRIPT_NAME']) === $script_name) {
                    $url = $_SERVER['SCRIPT_NAME'];
                } elseif (basename($_SERVER['PHP_SELF']) === $script_name) {
                    $url = $_SERVER['PHP_SELF'];
                } elseif (isset($_SERVER['ORIG_SCRIPT_NAME']) && basename($_SERVER['ORIG_SCRIPT_NAME']) === $script_name) {
                    $url = $_SERVER['ORIG_SCRIPT_NAME'];
                } elseif (($pos = strpos($_SERVER['PHP_SELF'], '/' . $script_name)) !== false) {
                    $url = substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/' . $script_name;
                } elseif (isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['SCRIPT_FILENAME'], $_SERVER['DOCUMENT_ROOT']) === 0) {
                    $url = str_replace('\\', '/', str_replace($_SERVER['DOCUMENT_ROOT'], '', $_SERVER['SCRIPT_FILENAME']));
                }
            }
            $this->baseFile = $url;
        }
        return true === $file ? $this->domain() . $this->baseFile : $this->baseFile;
    }

    /* *
 * Get or set the request root URL
 * @access public
 * @param string $url URL
 * @return string */
    public function root($url = null)
    {
        if (!is_null($url) && true !== $url) {
            $this->root = $url;
            return $this;
        } elseif (!$this->root) {
            $file = $this->baseFile();
            if ($file && 0 !== strpos($this->url(), $file)) {
                $file = str_replace('\\', '/', dirname($file));
            }
            $this->root = rtrim($file, '/');
        }
        return true === $url ? $this->domain() . $this->root : $this->root;
    }

    /* *
 * Get the pathinfo information of the current request URL (including URL suffix)
 * @access public
 * @return string */
    public function pathinfo()
    {
        if (is_null($this->pathinfo)) {
            if (isset($_GET[Config::get('var_pathinfo')])) {
                // Use the configured compatibility-mode path parameter
                $_SERVER['PATH_INFO'] = $_GET[Config::get('var_pathinfo')];
                unset($_GET[Config::get('var_pathinfo')]);
            } elseif (IS_CLI) {
                // CLI argument format: index.php module/controller/action/params/...
                $_SERVER['PATH_INFO'] = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
            }

            // Resolve PATH_INFO from the configured server variables
            if (!isset($_SERVER['PATH_INFO'])) {
                foreach (Config::get('pathinfo_fetch') as $type) {
                    if (!empty($_SERVER[$type])) {
                        $_SERVER['PATH_INFO'] = (0 === strpos($_SERVER[$type], $_SERVER['SCRIPT_NAME'])) ?
                        substr($_SERVER[$type], strlen($_SERVER['SCRIPT_NAME'])) : $_SERVER[$type];
                        break;
                    }
                }
            }
            $this->pathinfo = empty($_SERVER['PATH_INFO']) ? '/' : ltrim($_SERVER['PATH_INFO'], '/');
        }
        return $this->pathinfo;
    }

    /* *
 * Get the pathinfo information of the current request URL (without URL suffix)
 * @access public
 * @return string */
    public function path()
    {
        if (is_null($this->path)) {
            $suffix   = Config::get('url_html_suffix');
            $pathinfo = $this->pathinfo();
            if (false === $suffix) {
                // Keep the path unchanged when suffix handling is disabled
                $this->path = $pathinfo;
            } elseif ($suffix) {
                // Remove a configured URL suffix
                $this->path = preg_replace('/\.(' . ltrim($suffix, '.') . ')$/i', '', $pathinfo);
            } else {
                // Remove any URL suffix
                $this->path = preg_replace('/\.' . $this->ext() . '$/i', '', $pathinfo);
            }
        }
        return $this->path;
    }

    /* *
 * Get the filename extension of the current request path
 * @access public
 * @return string */
    public function ext()
    {
        return pathinfo($this->pathinfo(), PATHINFO_EXTENSION);
    }

    /* *
 * Get the time of the current request
 * @access public
 * @param bool $float Whether to return the floating-point request time
 * @return integer|float */
    public function time($float = false)
    {
        return $float ? $_SERVER['REQUEST_TIME_FLOAT'] : $_SERVER['REQUEST_TIME'];
    }

    /* *
 * Get the first supported resource type matching the Accept header
 * @access public
 * @return false|string */
    public function type()
    {
        $accept = $this->server('HTTP_ACCEPT');
        if (empty($accept)) {
            return false;
        }

        foreach ($this->mimeType as $key => $val) {
            $array = explode(',', $val);
            foreach ($array as $k => $v) {
                if (stristr($accept, $v)) {
                    return $key;
                }
            }
        }
        return false;
    }

    /* *
 * Add or merge MIME-type mappings
 * @access public
 * @param string|array $type Resource type name or mapping array
 * @param string $val Comma-separated MIME types
 * @return void */
    public function mimeType($type, $val = '')
    {
        if (is_array($type)) {
            $this->mimeType = array_merge($this->mimeType, $type);
        } else {
            $this->mimeType[$type] = $val;
        }
    }

    /* *
 * Get the HTTP request method
 * @access public
 * @param bool $method True returns the original HTTP method without overrides
 * @return string */
    public function method($method = false)
    {
        if (true === $method) {
            // Get the original HTTP request method
            return $this->server('REQUEST_METHOD') ?: 'GET';
        } elseif (!$this->method) {
            if (isset($_POST[Config::get('var_method')])) {
                $method = strtoupper($_POST[Config::get('var_method')]);
                if (in_array($method, ['GET', 'POST', 'DELETE', 'PUT', 'PATCH'])) {
                    $this->method = $method;
                    $this->{$this->method}($_POST);
                } else {
                    $this->method = 'POST';
                }
                unset($_POST[Config::get('var_method')]);
            } elseif (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $this->method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
            } else {
                $this->method = $this->server('REQUEST_METHOD') ?: 'GET';
            }
        }
        return $this->method;
    }

    /* *
 * Whether this is a GET request
 * @access public
 * @return bool */
    public function isGet()
    {
        return $this->method() == 'GET';
    }

    /* *
 * Whether this is a POST request
 * @access public
 * @return bool */
    public function isPost()
    {
        return $this->method() == 'POST';
    }

    /* *
 * Whether this is a PUT request
 * @access public
 * @return bool */
    public function isPut()
    {
        return $this->method() == 'PUT';
    }

    /* *
 * Whether this is a DELETE request
 * @access public
 * @return bool */
    public function isDelete()
    {
        return $this->method() == 'DELETE';
    }

    /* *
 * Whether this is a HEAD request
 * @access public
 * @return bool */
    public function isHead()
    {
        return $this->method() == 'HEAD';
    }

    /* *
 * Whether this is a PATCH request
 * @access public
 * @return bool */
    public function isPatch()
    {
        return $this->method() == 'PATCH';
    }

    /* *
 * Whether this is an OPTIONS request
 * @access public
 * @return bool */
    public function isOptions()
    {
        return $this->method() == 'OPTIONS';
    }

    /* *
 * Whether PHP is running in CLI mode
 * @access public
 * @return bool */
    public function isCli()
    {
        return PHP_SAPI == 'cli';
    }

    /* *
 * Whether PHP is running in CGI mode
 * @access public
 * @return bool */
    public function isCgi()
    {
        return strpos(PHP_SAPI, 'cgi') === 0;
    }

    /* *
 * Get the parameters of the current request
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function param($name = '', $default = null, $filter = '')
    {
        if (empty($this->mergeParam)) {
            $method = $this->method(true);
            // Get body parameters for the original HTTP method
            switch ($method) {
                case 'POST':
                    $vars = $this->post(false);
                    break;
                case 'PUT':
                case 'DELETE':
                case 'PATCH':
                    $vars = $this->put(false);
                    break;
                default:
                    $vars = [];
            }
            // Merge existing, GET, body and route parameters in that order
            $this->param      = array_merge($this->param, $this->get(false), $vars, $this->route(false));
            $this->mergeParam = true;
        }
        if (true === $name) {
            // Include uploaded files with the request parameters
            $file = $this->file();
            $data = is_array($file) ? array_merge($this->param, $file) : $this->param;
            return $this->input($data, '', $default, $filter);
        }
        return $this->input($this->param, $name, $default, $filter);
    }

    /* *
 * Get or merge route parameters
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function route($name = '', $default = null, $filter = '')
    {
        if (is_array($name)) {
            $this->param        = [];
            $this->mergeParam   = false;
            return $this->route = array_merge($this->route, $name);
        }
        return $this->input($this->route, $name, $default, $filter);
    }

    /* *
 * Get or merge GET parameters
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function get($name = '', $default = null, $filter = '')
    {
        if (empty($this->get)) {
            $this->get = $_GET;
        }
        if (is_array($name)) {
            $this->param      = [];
            $this->mergeParam = false;
            return $this->get = array_merge($this->get, $name);
        }
        return $this->input($this->get, $name, $default, $filter);
    }

    /* *
 * Get or merge POST parameters
 * @access public
 * @param string $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function post($name = '', $default = null, $filter = '')
    {
        if (empty($this->post)) {
            $content = $this->input;
            if (empty($_POST) && false !== strpos($this->contentType(), 'application/json')) {
                $this->post = (array) json_decode($content, true);
            } else {
                $this->post = $_POST;
            }
        }
        if (is_array($name)) {
            $this->param       = [];
            $this->mergeParam  = false;
            return $this->post = array_merge($this->post, $name);
        }
        return $this->input($this->post, $name, $default, $filter);
    }

    /* *
 * Get or merge PUT body parameters
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function put($name = '', $default = null, $filter = '')
    {
        if (is_null($this->put)) {
            $content = $this->input;
            if (false !== strpos($this->contentType(), 'application/json')) {
                $this->put = (array) json_decode($content, true);
            } else {
                parse_str($content, $this->put);
            }
        }
        if (is_array($name)) {
            $this->param      = [];
            $this->mergeParam = false;
            return $this->put = is_null($this->put) ? $name : array_merge($this->put, $name);
        }

        return $this->input($this->put, $name, $default, $filter);
    }

    /* *
 * Get or merge DELETE body parameters
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function delete($name = '', $default = null, $filter = '')
    {
        return $this->put($name, $default, $filter);
    }

    /* *
 * Get or merge PATCH body parameters
 * @access public
 * @param string|array $name variable name
 * @param mixed $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function patch($name = '', $default = null, $filter = '')
    {
        return $this->put($name, $default, $filter);
    }

    /* *
 * Get request variables
 * @param string $name data name
 * @param string $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function request($name = '', $default = null, $filter = '')
    {
        if (empty($this->request)) {
            $this->request = $_REQUEST;
        }
        if (is_array($name)) {
            $this->param          = [];
            $this->mergeParam     = false;
            return $this->request = array_merge($this->request, $name);
        }
        return $this->input($this->request, $name, $default, $filter);
    }

    /* *
 * Get session data
 * @access public
 * @param string|array $name data name
 * @param string $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function session($name = '', $default = null, $filter = '')
    {
        if (empty($this->session)) {
            $this->session = Session::get();
        }
        if (is_array($name)) {
            return $this->session = array_merge($this->session, $name);
        }
        return $this->input($this->session, $name, $default, $filter);
    }

    /* *
 * Get cookie parameters
 * @access public
 * @param string|array $name data name
 * @param string $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function cookie($name = '', $default = null, $filter = '')
    {
        if (empty($this->cookie)) {
            $this->cookie = Cookie::get();
        }
        if (is_array($name)) {
            return $this->cookie = array_merge($this->cookie, $name);
        } elseif (!empty($name)) {
            $data = Cookie::has($name) ? Cookie::get($name) : $default;
        } else {
            $data = $this->cookie;
        }

        // Resolve filters and the default value
        $filter = $this->getFilter($filter, $default);

        if (is_array($data)) {
            array_walk_recursive($data, [$this, 'filterValue'], $filter);
            reset($data);
        } else {
            $this->filterValue($data, $name, $filter);
        }
        return $data;
    }

    /* *
 * Get server parameters
 * @access public
 * @param string|array $name data name
 * @param string $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function server($name = '', $default = null, $filter = '')
    {
        if (empty($this->server)) {
            $this->server = $_SERVER;
        }
        if (is_array($name)) {
            return $this->server = array_merge($this->server, $name);
        }
        return $this->input($this->server, false === $name ? false : strtoupper($name), $default, $filter);
    }

    /* *
 * Get uploaded file information
 * @access public
 * @param string|array $name name
 * @return null|array|\think\File */
    public function file($name = '')
    {
        if (empty($this->file)) {
            $this->file = isset($_FILES) ? $_FILES : [];
        }
        if (is_array($name)) {
            return $this->file = array_merge($this->file, $name);
        }
        $files = $this->file;
        if (!empty($files)) {
            // Convert valid uploaded files to File objects
            $array = [];
            foreach ($files as $key => $file) {
                if (is_array($file['name'])) {
                    $item  = [];
                    $keys  = array_keys($file);
                    $count = count($file['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if (empty($file['tmp_name'][$i]) || !is_file($file['tmp_name'][$i])) {
                            continue;
                        }
                        $temp['key'] = $key;
                        foreach ($keys as $_key) {
                            $temp[$_key] = $file[$_key][$i];
                        }
                        $item[] = (new File($temp['tmp_name']))->setUploadInfo($temp);
                    }
                    $array[$key] = $item;
                } else {
                    if ($file instanceof File) {
                        $array[$key] = $file;
                    } else {
                        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
                            continue;
                        }
                        $array[$key] = (new File($file['tmp_name']))->setUploadInfo($file);
                    }
                }
            }
            if (strpos($name, '.')) {
                list($name, $sub) = explode('.', $name);
            }
            if ('' === $name) {
                // Get all files
                return $array;
            } elseif (isset($sub) && isset($array[$name][$sub])) {
                return $array[$name][$sub];
            } elseif (isset($array[$name])) {
                return $array[$name];
            }
        }
        return;
    }

    /* *
 * Get environment variables
 * @param string|array $name data name
 * @param string $default value
 * @param string|array $filter filter method
 * @return mixed */
    public function env($name = '', $default = null, $filter = '')
    {
        if (empty($this->env)) {
            $this->env = $_ENV;
        }
        if (is_array($name)) {
            return $this->env = array_merge($this->env, $name);
        }
        return $this->input($this->env, false === $name ? false : strtoupper($name), $default, $filter);
    }

    /* *
 * Get or merge request headers
 * @access public
 * @param string|array $name header name
 * @param string $default default value
 * @return mixed */
    public function header($name = '', $default = null)
    {
        if (empty($this->header)) {
            $header = [];
            if (function_exists('apache_request_headers') && $result = apache_request_headers()) {
                $header = $result;
            } else {
                $server = $this->server ?: $_SERVER;
                foreach ($server as $key => $val) {
                    if (0 === strpos($key, 'HTTP_')) {
                        $key          = str_replace('_', '-', strtolower(substr($key, 5)));
                        $header[$key] = $val;
                    }
                }
                if (isset($server['CONTENT_TYPE'])) {
                    $header['content-type'] = $server['CONTENT_TYPE'];
                }
                if (isset($server['CONTENT_LENGTH'])) {
                    $header['content-length'] = $server['CONTENT_LENGTH'];
                }
            }
            $this->header = array_change_key_case($header);
        }
        if (is_array($name)) {
            return $this->header = array_merge($this->header, $name);
        }
        if ('' === $name) {
            return $this->header;
        }
        $name = str_replace('_', '-', strtolower($name));
        return isset($this->header[$name]) ? $this->header[$name] : $default;
    }

    /* *
 * Get variables that support filtering and default values
 * @param array $data data source
 * @param string|false $name field name
 * @param mixed $default value
 * @param string|array $filter filter function
 * @return mixed */
    public function input($data = [], $name = '', $default = null, $filter = '')
    {
        if (false === $name) {
            // Get raw data
            return $data;
        }
        $name = (string) $name;
        if ('' != $name) {
            // Separate the field name from an optional type suffix
            if (strpos($name, '/')) {
                list($name, $type) = explode('/', $name);
            } else {
                $type = 's';
            }
            // Traverse nested array keys using dot notation
            foreach (explode('.', $name) as $val) {
                if (isset($data[$val])) {
                    $data = $data[$val];
                } else {
                    // Return the default value when a key is missing
                    return $default;
                }
            }
            if (is_object($data)) {
                return $data;
            }
        }

        // Resolve filters and the default value
        $filter = $this->getFilter($filter, $default);

        if (is_array($data)) {
            array_walk_recursive($data, [$this, 'filterValue'], $filter);
            reset($data);
        } else {
            $this->filterValue($data, $name, $filter);
        }

        if (isset($type) && $data !== $default) {
            // Convert to the requested type
            $this->typeCast($data, $type);
        }
        return $data;
    }

    /* *
 * Set or get the current filter rules
 * @param mixed $filter filter rules
 * @return mixed */
    public function filter($filter = null)
    {
        if (is_null($filter)) {
            return $this->filter;
        } else {
            $this->filter = $filter;
        }
    }

    protected function getFilter($filter, $default)
    {
        if (is_null($filter)) {
            $filter = [];
        } else {
            $filter = $filter ?: $this->filter;
            if (is_string($filter) && false === strpos($filter, '/')) {
                $filter = explode(',', $filter);
            } else {
                $filter = (array) $filter;
            }
        }

        $filter[] = $default;
        return $filter;
    }

    /* *
 * Recursively filter the given values
 * @param mixed $value Value to filter in place
 * @param mixed $key key name
 * @param array $filters Filters followed by the default value
 * @return mixed */
    private function filterValue(&$value, $key, $filters)
    {
        $default = array_pop($filters);
        foreach ($filters as $filter) {
            if (is_callable($filter)) {
                // Call a function or method filter
                $value = call_user_func($filter, $value);
            } elseif (is_scalar($value)) {
                if (false !== strpos($filter, '/')) {
                    // Apply a regular-expression filter
                    if (!preg_match($filter, $value)) {
                        // Use the default value when the pattern does not match
                        $value = $default;
                        break;
                    }
                } elseif (!empty($filter)) {
                    // Apply a built-in filter using filter_var
                    // Resolve a non-integer filter name with filter_id
                    $value = filter_var($value, is_int($filter) ? $filter : filter_id($filter));
                    if (false === $value) {
                        $value = $default;
                        break;
                    }
                }
            }
        }
        return $this->filterExp($value);
    }

    /* *
 * Filter query expressions in submitted values
 * @param string $value
 * @return void */
    public function filterExp(&$value)
    {
        // Append a space to values that match query expression keywords
        if (is_string($value) && preg_match('/^(EXP|NEQ|GT|EGT|LT|ELT|OR|XOR|LIKE|NOTLIKE|NOT LIKE|NOT BETWEEN|NOTBETWEEN|BETWEEN|NOT EXISTS|NOTEXISTS|EXISTS|NOT NULL|NOTNULL|NULL|BETWEEN TIME|NOT BETWEEN TIME|NOTBETWEEN TIME|NOTIN|NOT IN|IN)$/i', $value)) {
            $value .= ' ';
        }
        // TODO Other safety filters
    }

    /* *
 * Convert a value to the requested type
 * @param string $data
 * @param string $type
 * @return mixed */
    private function typeCast(&$data, $type)
    {
        switch (strtolower($type)) {
            // arrays
            case 'a':
                $data = (array) $data;
                break;
            // integers
            case 'd':
                $data = (int) $data;
                break;
            // floating point
            case 'f':
                $data = (float) $data;
                break;
            // booleans
            case 'b':
                $data = (bool) $data;
                break;
            // strings
            case 's':
            default:
                if (is_scalar($data)) {
                    $data = (string) $data;
                } else {
                    throw new \InvalidArgumentException('variable type error: ' . gettype($data));
                }
        }
    }

    /* *
 * Check whether a request parameter exists
 * @access public
 * @param string $name variable name
 * @param string $type variable type
 * @param bool $checkEmpty Whether to reject empty-string values
 * @return bool */
    public function has($name, $type = 'param', $checkEmpty = false)
    {
        if (empty($this->$type)) {
            $param = $this->$type();
        } else {
            $param = $this->$type;
        }
        // Traverse nested array keys using dot notation
        foreach (explode('.', $name) as $val) {
            if (isset($param[$val])) {
                $param = $param[$val];
            } else {
                return false;
            }
        }
        return ($checkEmpty && '' === $param) ? false : true;
    }

    /* *
 * Get the specified parameters
 * @access public
 * @param string|array $name variable name
 * @param string $type variable type
 * @return mixed */
    public function only($name, $type = 'param')
    {
        $param = $this->$type();
        if (is_string($name)) {
            $name = explode(',', $name);
        }
        $item = [];
        foreach ($name as $key) {
            if (isset($param[$key])) {
                $item[$key] = $param[$key];
            }
        }
        return $item;
    }

    /* *
 * Get parameters excluding the specified keys
 * @access public
 * @param string|array $name variable name
 * @param string $type variable type
 * @return mixed */
    public function except($name, $type = 'param')
    {
        $param = $this->$type();
        if (is_string($name)) {
            $name = explode(',', $name);
        }
        foreach ($name as $key) {
            if (isset($param[$key])) {
                unset($param[$key]);
            }
        }
        return $param;
    }

    /* *
 * Check whether the request uses HTTPS
 * @access public
 * @return bool */
    public function isSsl()
    {
        $server = array_merge($_SERVER, $this->server);
        if (isset($server['HTTPS']) && ('1' == $server['HTTPS'] || 'on' == strtolower($server['HTTPS']))) {
            return true;
        } elseif (isset($server['REQUEST_SCHEME']) && 'https' == $server['REQUEST_SCHEME']) {
            return true;
        } elseif (isset($server['SERVER_PORT']) && ('443' == $server['SERVER_PORT'])) {
            return true;
        } elseif (isset($server['HTTP_X_FORWARDED_PROTO']) && 'https' == $server['HTTP_X_FORWARDED_PROTO']) {
            return true;
        } elseif (Config::get('https_agent_name') && isset($server[Config::get('https_agent_name')])) {
            return true;
        }
        return false;
    }

    /* *
 * Check whether this is an Ajax request
 * @access public
 * @param bool $ajax True checks only the request header
 * @return bool */
    public function isAjax($ajax = false)
    {
        $value  = $this->server('HTTP_X_REQUESTED_WITH', '', 'strtolower');
        $result = ('xmlhttprequest' == $value) ? true : false;
        if (true === $ajax) {
            return $result;
        } else {
            $result           = $this->param(Config::get('var_ajax')) ? true : $result;
            $this->mergeParam = false;
            return $result;
        }
    }

    /* *
 * Check whether this is a Pjax request
 * @access public
 * @param bool $pjax True checks only the request header
 * @return bool */
    public function isPjax($pjax = false)
    {
        $result = !is_null($this->server('HTTP_X_PJAX')) ? true : false;
        if (true === $pjax) {
            return $result;
        } else {
            $result           = $this->param(Config::get('var_pjax')) ? true : $result;
            $this->mergeParam = false;
            return $result;
        }
    }

    /* *
 * Get the IP address of the client
 * @param integer $type 0 returns the IP address; 1 returns its numeric IPv4 value
 * @param boolean $adv Whether to inspect forwarding/client headers, which may be spoofed
 * @return mixed */
    public function ip($type = 0, $adv = true)
    {
        $type      = $type ? 1 : 0;
        static $ip = null;
        if (null !== $ip) {
            return $ip[$type];
        }

        $httpAgentIp = Config::get('http_agent_ip');

        if ($httpAgentIp && isset($_SERVER[$httpAgentIp])) {
            $ip = $_SERVER[$httpAgentIp];
        } elseif ($adv) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $pos = array_search('unknown', $arr);
                if (false !== $pos) {
                    unset($arr[$pos]);
                }
                $ip = trim(current($arr));
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // Convert the IPv4 address and fall back when conversion yields zero
        $long = sprintf("%u", ip2long($ip));
        $ip   = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
    }

    /* *
 * Check whether the request appears to come from a mobile device
 * @access public
 * @return bool */
    public function isMobile()
    {
        if (isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], "wap")) {
            return true;
        } elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtoupper($_SERVER['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        } elseif (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
            return true;
        } elseif (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        } else {
            return false;
        }
    }

    /* *
 * Get the URL scheme for the current request
 * @access public
 * @return string */
    public function scheme()
    {
        return $this->isSsl() ? 'https' : 'http';
    }

    /* *
 * Get the query string of the current request URL
 * @access public
 * @return string */
    public function query()
    {
        return $this->server('QUERY_STRING');
    }

    /* *
 * The host of the current request
 * @access public
 * @param bool $strict True returns the host without its port
 * @return string */
    public function host($strict = false)
    {
        if (isset($_SERVER['HTTP_X_REAL_HOST'])) {
            $host = $_SERVER['HTTP_X_REAL_HOST'];
        } else {
            $host = $this->server('HTTP_HOST');
        }

        return true === $strict && strpos($host, ':') ? strstr($host, ':', true) : $host;
    }

    /* *
 * Get the current request server port
 * @access public
 * @return integer */
    public function port()
    {
        return $this->server('SERVER_PORT');
    }

    /* *
 * Get the current request SERVER_PROTOCOL value
 * @access public
 * @return integer */
    public function protocol()
    {
        return $this->server('SERVER_PROTOCOL');
    }

    /* *
 * Current request REMOTE_PORT
 * @access public
 * @return integer */
    public function remotePort()
    {
        return $this->server('REMOTE_PORT');
    }

    /* *
 * Get the request content type without its parameters
 * @access public
 * @return string */
    public function contentType()
    {
        $contentType = $this->server('CONTENT_TYPE');
        if ($contentType) {
            if (strpos($contentType, ';')) {
                list($type) = explode(';', $contentType);
            } else {
                $type = $contentType;
            }
            return trim($type);
        }
        return '';
    }

    /* *
 * Get the route information for the current request
 * @access public
 * @param array $route Route information to set
 * @return array */
    public function routeInfo($route = [])
    {
        if (!empty($route)) {
            $this->routeInfo = $route;
        } else {
            return $this->routeInfo;
        }
    }

    /* *
 * Get or set dispatch information for the current request
 * @access public
 * @param array $dispatch Dispatch information
 * @return array */
    public function dispatch($dispatch = null)
    {
        if (!is_null($dispatch)) {
            $this->dispatch = $dispatch;
        }
        return $this->dispatch;
    }

    /* *
 * Set or get the current module name
 * @access public
 * @param string $module module name
 * @return string|Request */
    public function module($module = null)
    {
        if (!is_null($module)) {
            $this->module = $module;
            return $this;
        } else {
            return $this->module ?: '';
        }
    }

    /* *
 * Set or get the current controller name
 * @access public
 * @param string $controller controller name
 * @return string|Request */
    public function controller($controller = null)
    {
        if (!is_null($controller)) {
            $this->controller = $controller;
            return $this;
        } else {
            return $this->controller ?: '';
        }
    }

    /* *
 * Get or set the current action name
 * @access public
 * @param string $action Action name
 * @return string|Request */
    public function action($action = null)
    {
        if (!is_null($action) && !is_bool($action)) {
            $this->action = $action;
            return $this;
        } else {
            $name = $this->action ?: '';
            return true === $action ? $name : strtolower($name);
        }
    }

    /* *
 * Set or get the current language
 * @access public
 * @param string $lang language name
 * @return string|Request */
    public function langset($lang = null)
    {
        if (!is_null($lang)) {
            $this->langset = $lang;
            return $this;
        } else {
            return $this->langset ?: '';
        }
    }

    /* *
 * Get the content of the current request
 * @access public
 * @return string */
    public function getContent()
    {
        if (is_null($this->content)) {
            $this->content = $this->input;
        }
        return $this->content;
    }

    /* *
 * Get the raw php://input body of the current request
 * @access public
 * @return string */
    public function getInput()
    {
        return $this->input;
    }

    /* *
 * Generate a request token
 * @access public
 * @param string $name Token name
 * @param mixed $type Token generation method
 * @return string */
    public function token($name = '__token__', $type = null)
    {
        // Default tokens must be unpredictable; callers may still provide an
        // explicit callable for legacy/custom token formats.
        if ($type === null) {
            $token = bin2hex(random_bytes(32));
        } else {
            $type = is_callable($type) ? $type : 'md5';
            $token = call_user_func($type, $_SERVER['REQUEST_TIME_FLOAT']);
        }
        if ($this->isAjax()) {
            header($name . ': ' . $token);
        }
        Session::set($name, $token);
        return $token;
    }

    /* *
 * Configure caching for the current request URL
 * @access public
 * @param string|bool|\Closure $key Cache key or rule (such as item/:name/:id), boolean flag, or key-generating callback
 * @param mixed $expire Cache lifetime
 * @param array $except URL prefixes excluded from automatic caching
 * @param string $tag Cache tag
 * @return void */
    public function cache($key, $expire = null, $except = [], $tag = null)
    {
        if (!is_array($except)) {
            $tag    = $except;
            $except = [];
        }

        if (false !== $key && $this->isGet() && !$this->isCheckCache) {
            // Mark the request cache check as performed
            $this->isCheckCache = true;
            if (false === $expire) {
                // Disable caching for the current request
                return;
            }
            if ($key instanceof \Closure) {
                $key = call_user_func_array($key, [$this]);
            } elseif (true === $key) {
                foreach ($except as $rule) {
                    if (0 === stripos($this->url(), $rule)) {
                        return;
                    }
                }
                // Derive the automatic cache key from the full request URL
                $key = '__URL__';
            } elseif (strpos($key, '|')) {
                list($key, $fun) = explode('|', $key);
            }
            // Replace special cache-key placeholders
            if (false !== strpos($key, '__')) {
                $key = str_replace(['__MODULE__', '__CONTROLLER__', '__ACTION__', '__URL__', ''], [$this->module, $this->controller, $this->action, md5($this->url(true))], $key);
            }

            if (false !== strpos($key, ':')) {
                $param = $this->param();
                foreach ($param as $item => $val) {
                    if (is_string($val) && false !== strpos($key, ':' . $item)) {
                        $key = str_replace(':' . $item, $val, $key);
                    }
                }
            } elseif (strpos($key, ']')) {
                if ('[' . $this->ext() . ']' == $key) {
                    // Cache requests whose suffix matches the rule
                    $key = md5($this->url());
                } else {
                    return;
                }
            }
            if (isset($fun)) {
                $key = $fun($key);
            }

            if (strtotime($this->server('HTTP_IF_MODIFIED_SINCE')) + $expire > $_SERVER['REQUEST_TIME']) {
                // Return a not-modified response within the freshness period
                $response = Response::create()->code(304);
                throw new \think\exception\HttpResponseException($response);
            } elseif (Cache::has($key)) {
                list($content, $header) = Cache::get($key);
                $response               = Response::create($content)->header($header);
                throw new \think\exception\HttpResponseException($response);
            } else {
                $this->cache = [$key, $expire, $tag];
            }
        }
    }

    /* *
 * Read request cache settings
 * @access public
 * @return array */
    public function getCache()
    {
        return $this->cache;
    }

    /* *
 * Set the object instance bound to the current request
 * @access public
 * @param string|array $name The bound object ID
 * @param mixed $obj bound object instance
 * @return mixed */
    public function bind($name, $obj = null)
    {
        if (is_array($name)) {
            $this->bind = array_merge($this->bind, $name);
        } else {
            $this->bind[$name] = $obj;
        }
    }

    public function __set($name, $value)
    {
        $this->bind[$name] = $value;
    }

    public function __get($name)
    {
        return isset($this->bind[$name]) ? $this->bind[$name] : null;
    }

    public function __isset($name)
    {
        return isset($this->bind[$name]);
    }
}
