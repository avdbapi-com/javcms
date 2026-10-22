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

use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\RouteNotFoundException;

/* *
 * Application initialization and request dispatch
 * @author liu21st <liu21st@gmail.com>
 */
class App
{
    /* *
 * @var bool Whether initialization has completed */
    protected static $init = false;

    /* *
 * @var string Current module path */
    public static $modulePath;

    /* *
 * @var bool Application debug mode */
    public static $debug = false;

    /* *
 * @var string App library namespace */
    public static $namespace = 'app';

    /* *
 * @var bool Whether application class names use suffixes */
    public static $suffix = false;

    /* *
 * @var bool Whether route matching is enabled */
    protected static $routeCheck;

    /* *
 * @var bool Whether a route match is required */
    protected static $routeMust;

    /* *
 * @var array Request dispatch information */
    protected static $dispatch;

    /* *
 * @var array Additional files already loaded */
    protected static $file = [];

    /* *
 * Execute the application
 * @access public
 * @param Request $request request object
 * @return Response
 * @throws Exception */
    public static function run(?Request $request = null)
    {
        $request = is_null($request) ? Request::instance() : $request;

        try {
            $config = self::initCommon();

            // Module/controller binding
            if (defined('BIND_MODULE')) {
                BIND_MODULE && Route::bind(BIND_MODULE);
            } elseif ($config['auto_bind_module']) {
                // Bind a module using the entry script name.
                $name = pathinfo($request->baseFile(), PATHINFO_FILENAME);
                if ($name && 'index' != $name && is_dir(APP_PATH . $name)) {
                    Route::bind($name);
                }
            }

            $request->filter($config['default_filter']);

            // default language
            Lang::range($config['default_lang']);
            // Detect the current language when language switching is enabled.
            $config['lang_switch_on'] && Lang::detect();
            $request->langset(Lang::range());

            // Load framework and application language packs.
            Lang::load([
                THINK_PATH . 'lang' . DS . $request->langset() . EXT,
                APP_PATH . 'lang' . DS . $request->langset() . EXT,
            ]);

            // Run app_dispatch hook listeners.
            Hook::listen('app_dispatch', self::$dispatch);
            // Get the application dispatch information.
            $dispatch = self::$dispatch;

            // Check URL routing when no dispatch information has been supplied.
            if (empty($dispatch)) {
                $dispatch = self::routeCheck($request, $config);
            }

            // Store the dispatch information on the request.
            $request->dispatch($dispatch);

            // Record route and request information
            if (self::$debug) {
                Log::record('[ ROUTE ] ' . var_export($dispatch, true), 'info');
                Log::record('[ HEADER ] ' . var_export($request->header(), true), 'info');
                Log::record('[ PARAM ] ' . var_export($request->param(), true), 'info');
            }

            // Run app_begin hook listeners.
            Hook::listen('app_begin', $dispatch);

            // Request cache check
            $request->cache(
                $config['request_cache'],
                $config['request_cache_expire'],
                $config['request_cache_except']
            );

            $data = self::exec($dispatch, $config);
        } catch (HttpResponseException $exception) {
            $data = $exception->getResponse();
        }

        // Clear cached class instances.
        Loader::clearInstance();

        // Prepare the client response.
        if ($data instanceof Response) {
            $response = $data;
        } elseif (!is_null($data)) {
            // Select the configured response type for AJAX or other requests.
            $type = $request->isAjax() ?
            Config::get('default_ajax_return') :
            Config::get('default_return_type');

            $response = Response::create($data, $type);
        } else {
            $response = Response::create();
        }

        // Run app_end hook listeners.
        Hook::listen('app_end', $response);

        return $response;
    }

    /* *
 * Initialize the application and return configuration information
 * @access public
 * @return array */
    public static function initCommon()
    {
        if (empty(self::$init)) {
            if (defined('APP_NAMESPACE')) {
                self::$namespace = APP_NAMESPACE;
            }

            Loader::addNamespace(self::$namespace, APP_PATH);

            // Initialize the app
            $config       = self::init();
            self::$suffix = $config['class_suffix'];

            // App Debug Mode
            self::$debug = Env::get('app_debug', Config::get('app_debug'));

            if (!self::$debug) {
                ini_set('display_errors', 'Off');
            } elseif (!IS_CLI) {
                // Restart output buffering, preserving captured output.
                if (ob_get_level() > 0) {
                    $output = ob_get_clean();
                }

                ob_start();

                if (!empty($output)) {
                    echo $output;
                }

            }

            if (!empty($config['root_namespace'])) {
                Loader::addNamespace($config['root_namespace']);
            }

            // Load additional files
            if (!empty($config['extra_file_list'])) {
                foreach ($config['extra_file_list'] as $file) {
                    $file = strpos($file, '.') ? $file : APP_PATH . $file . EXT;
                    if (is_file($file) && !isset(self::$file[$file])) {
                        include $file;
                        self::$file[$file] = true;
                    }
                }
            }

            // Set the system time zone
            date_default_timezone_set($config['default_timezone']);

            // Run app_init hook listeners.
            Hook::listen('app_init');

            self::$init = true;
        }

        return Config::get();
    }

    /* *
 * Initialize the application or module
 * @access private
 * @param string $module module name
 * @return array */
    private static function init($module = '')
    {
        // Build the relative module directory path.
        $module = $module ? $module . DS : '';

        // Load the initialization file
        if (is_file(APP_PATH . $module . 'init' . EXT)) {
            include APP_PATH . $module . 'init' . EXT;
        } elseif (is_file(RUNTIME_PATH . $module . 'init' . EXT)) {
            include RUNTIME_PATH . $module . 'init' . EXT;
        } else {
            // Load module configuration
            $config = Config::load(CONF_PATH . $module . 'config' . CONF_EXT);

            // Read the database configuration file
            $filename = CONF_PATH . $module . 'database' . CONF_EXT;
            Config::load($filename, 'database');

            // Load additional configuration files.
            if (is_dir(CONF_PATH . $module . 'extra')) {
                $dir   = CONF_PATH . $module . 'extra';
                $files = scandir($dir);
                foreach ($files as $file) {
                    if ('.' . pathinfo($file, PATHINFO_EXTENSION) === CONF_EXT) {
                        $filename = $dir . DS . $file;
                        Config::load($filename, pathinfo($file, PATHINFO_FILENAME));
                    }
                }
            }

            // Load app state configuration
            if ($config['app_status']) {
                Config::load(CONF_PATH . $module . $config['app_status'] . CONF_EXT);
            }

            // Load hook and behavior definitions.
            if (is_file(CONF_PATH . $module . 'tags' . EXT)) {
                Hook::import(include CONF_PATH . $module . 'tags' . EXT);
            }

            // Load the application or module common file.
            $path = APP_PATH . $module;
            if (is_file($path . 'common' . EXT)) {
                include $path . 'common' . EXT;
            }

            // Load the current module language pack
            if ($module) {
                Lang::load($path . 'lang' . DS . Request::instance()->langset() . EXT);
            }
        }

        return Config::get();
    }

    /* *
 * Set dispatch information for the current request.
 * @access public
 * @param array|string $dispatch Dispatch target
 * @param string $type Dispatch type
 * @return void */
    public static function dispatch($dispatch, $type = 'module')
    {
        self::$dispatch = ['type' => $type, $type => $dispatch];
    }

    /* *
 * Invoke a function or closure with bound arguments.
 * @access public
 * @param string|\Closure $function Function name or closure
 * @param array $vars Argument values
 * @return mixed */
    public static function invokeFunction($function, $vars = [])
    {
        $reflect = new \ReflectionFunction($function);
        $args    = self::bindParams($reflect, $vars);

        // Record execution information
        self::$debug && Log::record('[ RUN ] ' . $reflect->__toString(), 'info');

        return $reflect->invokeArgs($args);
    }

    /* *
 * Invoke a class method through reflection with bound arguments.
 * @access public
 * @param string|array $method
 * @param array $vars Argument values
 * @return mixed */
    public static function invokeMethod($method, $vars = [])
    {
        if (is_array($method)) {
            $class   = is_object($method[0]) ? $method[0] : self::invokeClass($method[0]);
            $reflect = new \ReflectionMethod($class, $method[1]);
        } else {
            // static method
            $reflect = new \ReflectionMethod(...explode('::', $method, 2));
        }

        $args = self::bindParams($reflect, $vars);

        self::$debug && Log::record('[ RUN ] ' . $reflect->class . '->' . $reflect->name . '[ ' . $reflect->getFileName() . ' ]', 'info');

        return $reflect->invokeArgs(isset($class) ? $class : null, $args);
    }

    /* *
 * Instantiate a class through reflection with bound constructor arguments.
 * @access public
 * @param string $class class name
 * @param array $vars Constructor argument values
 * @return mixed */
    public static function invokeClass($class, $vars = [])
    {
        $reflect     = new \ReflectionClass($class);
        $constructor = $reflect->getConstructor();
        $args        = $constructor ? self::bindParams($constructor, $vars) : [];

        return $reflect->newInstanceArgs($args);
    }

    /* *
 * Bind argument values to reflected parameters.
 * @access private
 * @param \ReflectionMethod|\ReflectionFunction $reflect Reflected method or function
 * @param array $vars Argument values
 * @return array */
    private static function bindParams($reflect, $vars = [])
    {
        // Get request variables automatically
        if (empty($vars)) {
            $vars = Config::get('url_param_type') ?
            Request::instance()->route() :
            Request::instance()->param();
        }

        $args = [];
        if ($reflect->getNumberOfParameters() > 0) {
            // Bind positionally when the first array key is integer zero.
            reset($vars);
            $type = key($vars) === 0 ? 1 : 0;

            foreach ($reflect->getParameters() as $param) {
                $args[] = self::getParamValue($param, $vars, $type);
            }
        }

        return $args;
    }

    /* *
 * Resolve the value for one reflected parameter.
 * @access private
 * @param \ReflectionParameter $param Reflected parameter
 * @param array $vars Available argument values, consumed for positional binding
 * @param int $type Binding mode: 1 for positional values, 0 for named values
 * @return mixed */
    private static function getParamValue($param, &$vars, $type)
    {
        $name = $param->getName();
        $hint = $param->getType();
        $types = $hint instanceof \ReflectionUnionType ? $hint->getTypes() : [$hint];
        $className = null;
        $hasArray = false;
        foreach ($types as $typeHint) {
            if (!$typeHint instanceof \ReflectionNamedType) {
                // Untyped, intersection and DNF arguments use supplied values.
                $className = null;
                break;
            }
            if (!$typeHint->isBuiltin()) {
                if ($className !== null) {
                    $className = null;
                    break;
                }
                $className = $typeHint->getName();
            } elseif ($typeHint->getName() === 'array') {
                $hasArray = true;
            }
        }
        // PHP8.2 expands iterable into Traversable|array; keep scalar binding.
        if ($className === 'Traversable' && $hasArray) {
            $className = null;
        }
        $class = null;
        if ($className !== null) {
            if (strtolower($className) === 'self') {
                $class = $param->getDeclaringClass();
            } elseif (strtolower($className) === 'parent') {
                $class = $param->getDeclaringClass()->getParentClass();
            } else {
                $class = new \ReflectionClass($className);
            }
        }

        if ($class) {
            $className = $class->getName();
            $bind      = Request::instance()->$name;

            if ($bind instanceof $className) {
                $result = $bind;
            } else {
                if (method_exists($className, 'invoke')) {
                    $method = new \ReflectionMethod($className, 'invoke');

                    if ($method->isPublic() && $method->isStatic()) {
                        return $className::invoke(Request::instance());
                    }
                }

                $result = method_exists($className, 'instance') ?
                $className::instance() :
                new $className;
            }
        } elseif (1 == $type && !empty($vars)) {
            $result = array_shift($vars);
        } elseif (0 == $type && isset($vars[$name])) {
            $result = $vars[$name];
        } elseif ($param->isDefaultValueAvailable()) {
            $result = $param->getDefaultValue();
        } else {
            throw new \InvalidArgumentException('method param miss:' . $name);
        }

        return $result;
    }

    /* *
 * Execute the selected request dispatch.
 * @access protected
 * @param array $dispatch Dispatch information
 * @param array $config configuration information
 * @return Response|mixed
 * @throws \InvalidArgumentException */
    protected static function exec($dispatch, $config)
    {
        switch ($dispatch['type']) {
            case 'redirect': // Redirect response
                $data = Response::create($dispatch['url'], 'redirect')
                    ->code($dispatch['status']);
                break;
            case 'module': // Module/controller/action
                $data = self::module(
                    $dispatch['module'],
                    $config,
                    isset($dispatch['convert']) ? $dispatch['convert'] : null
                );
                break;
            case 'controller': // Perform controller actions
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = Loader::action(
                    $dispatch['controller'],
                    $vars,
                    $config['url_controller_layer'],
                    $config['controller_suffix']
                );
                break;
            case 'method': // Callback method
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = self::invokeMethod($dispatch['method'], $vars);
                break;
            case 'function': // Function or closure
                $data = self::invokeFunction($dispatch['function']);
                break;
            case 'response': // Existing response object
                $data = $dispatch['response'];
                break;
            default:
                throw new \InvalidArgumentException('dispatch type not support');
        }

        return $data;
    }

    /* *
 * Execute module
 * @access public
 * @param array|string $result Module/controller/action target
 * @param array $config configuration parameters
 * @param bool|null $convert Whether to convert controller and action names; null uses configuration
 * @return mixed
 * @throws HttpException */
    public static function module($result, $config, $convert = null)
    {
        if (is_string($result)) {
            $result = explode('/', $result);
        }

        $request = Request::instance();

        if ($config['app_multi_module']) {
            // Multi-module deployment
            $module    = strip_tags(strtolower($result[0] ?: $config['default_module']));
            $bind      = Route::getBind('module');
            $available = false;

            if ($bind) {
                // Use the bound module.
                list($bindModule) = explode('/', $bind);

                if (empty($result[0])) {
                    $module    = $bindModule;
                    $available = true;
                } elseif ($module == $bindModule) {
                    $available = true;
                }
            } elseif (!in_array($module, $config['deny_module_list']) && is_dir(APP_PATH . $module)) {
                $available = true;
            }

            // module initialization
            if ($module && $available) {
                // Initialize the module
                $request->module($module);
                $config = self::init($module);

                // Module request cache check
                $request->cache(
                    $config['request_cache'],
                    $config['request_cache_expire'],
                    $config['request_cache_except']
                );
            } else {
                throw new HttpException(404, 'module not exists:' . $module);
            }
        } else {
            // Single module deployment
            $module = '';
            $request->module($module);
        }

        // Set the default filtering mechanism
        $request->filter($config['default_filter']);

        // Current module path
        App::$modulePath = APP_PATH . ($module ? $module . DS : '');

        // Determine whether to convert controller and action names.
        $convert = is_bool($convert) ? $convert : $config['url_convert'];

        // Get the controller name
        $controller = strip_tags($result[1] ?: $config['default_controller']);

        if (!preg_match('/^[A-Za-z](\w|\.)*$/', $controller)) {
            throw new HttpException(404, 'controller not exists:' . $controller);
        }

        $controller = $convert ? strtolower($controller) : $controller;

        // Get the action name.
        $actionName = strip_tags($result[2] ?: $config['default_action']);
        if (!empty($config['action_convert'])) {
            $actionName = Loader::parseName($actionName, 1);
        } else {
            $actionName = $convert ? strtolower($actionName) : $actionName;
        }

        // Set the controller and action on the current request.
        $request->controller(Loader::parseName($controller, 1))->action($actionName);

        // Run module_init hook listeners.
        Hook::listen('module_init', $request);

        try {
            $instance = Loader::controller(
                $controller,
                $config['url_controller_layer'],
                $config['controller_suffix'],
                $config['empty_controller']
            );
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'controller not exists:' . $e->getClass());
        }

        // Append the configured suffix to the action method name.
        $action = $actionName . $config['action_suffix'];

        $vars = [];
        if (is_callable([$instance, $action])) {
            // Select the callable action method.
            $call = [$instance, $action];
            // Recover the actual method name and remove its action suffix.
            $reflect    = new \ReflectionMethod($instance, $action);
            $methodName = $reflect->getName();
            $suffix     = $config['action_suffix'];
            $actionName = $suffix ? substr($methodName, 0, -strlen($suffix)) : $methodName;
            $request->action($actionName);

        } elseif (is_callable([$instance, '_empty'])) {
            // Use the fallback action for an undefined action name.
            $call = [$instance, '_empty'];
            $vars = [$actionName];
        } else {
            // The requested action does not exist.
            throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
        }

        Hook::listen('action_begin', $call);

        return self::invokeMethod($call, $vars);
    }

    /* *
 * URL route detection (according to PATH_INFO)
 * @access public
 * @param \think\Request $request Request instance
 * @param array $config configuration information
 * @return array
 * @throws \think\exception\RouteNotFoundException */
    public static function routeCheck($request, array $config)
    {
        $path   = $request->path();
        $depr   = $config['pathinfo_depr'];
        $result = false;

        // Route detection
        $check = !is_null(self::$routeCheck) ? self::$routeCheck : $config['url_route_on'];
        if ($check) {
            // Load route definitions.
            if (is_file(RUNTIME_PATH . 'route.php')) {
                // Read route cache
                $rules = include RUNTIME_PATH . 'route.php';
                is_array($rules) && Route::rules($rules);
            } else {
                $files = $config['route_config_file'];
                foreach ($files as $file) {
                    if (is_file(CONF_PATH . $file . CONF_EXT)) {
                        // Import route definitions from configuration files.
                        $rules = include CONF_PATH . $file . CONF_EXT;
                        is_array($rules) && Route::import($rules);
                    }
                }
            }

            // Match the request to a route and obtain its dispatch information.
            $result = Route::check($request, $path, $depr, $config['url_domain_deploy']);
            $must   = !is_null(self::$routeMust) ? self::$routeMust : $config['url_route_must'];

            if ($must && false === $result) {
                // A required route match was not found.
                throw new RouteNotFoundException();
            }
        }

        // Without a route match, parse module/controller/action/parameters from the URL.
        if (false === $result) {
            $result = Route::parseUrl($path, $depr, $config['controller_auto_search']);
        }

        return $result;
    }

    /* *
 * Configure route matching and whether a match is required.
 * @access public
 * @param bool $route Whether to enable route matching
 * @param bool $must Whether to require a route match
 * @return void */
    public static function route($route, $must = false)
    {
        self::$routeCheck = $route;
        self::$routeMust  = $must;
    }
}
