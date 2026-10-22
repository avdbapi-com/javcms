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

class Loader
{
    /* *
 * @var array array of instances */
    protected static $instance = [];

    /* *
 * @var array Class-to-file map */
    protected static $classMap = [];

    /* *
 * @var array namespace aliases */
    protected static $namespaceAlias = [];

    /* *
 * @var array PSR-4 namespace prefix length mapping */
    private static $prefixLengthsPsr4 = [];

    /* *
 * @var array Directories for PSR-4 namespace prefixes */
    private static $prefixDirsPsr4 = [];

    /* *
 * @var array PSR-4 fallback directories */
    private static $fallbackDirsPsr4 = [];

    /* *
 * @var array PSR-0 namespace prefix map */
    private static $prefixesPsr0 = [];

    /* *
 * @var array PSR-0 fallback directories */
    private static $fallbackDirsPsr0 = [];

    /* *
 * @var array Files to load */
    private static $files = [];

    /* *
 * Autoload a class.
 * @access public
 * @param string $class class name
 * @return bool */
    public static function autoload($class)
    {
        // Detect namespace aliases
        if (!empty(self::$namespaceAlias)) {
            $namespace = dirname($class);
            if (isset(self::$namespaceAlias[$namespace])) {
                $original = self::$namespaceAlias[$namespace] . '\\' . basename($class);
                if (class_exists($original)) {
                    return class_alias($original, $class, false);
                }
            }
        }

        if ($file = self::findFile($class)) {
            // Check filename case on Windows before including the file.
            if (!IS_WIN || pathinfo($file, PATHINFO_FILENAME) == pathinfo(realpath($file), PATHINFO_FILENAME)) {
                __include_file($file);
                return true;
            }
        }

        return false;
    }

    /* *
 * Find the file for a class.
 * @access private
 * @param string $class class name
 * @return bool|string */
    private static function findFile($class)
    {
        // Check the class-to-file map.
        if (!empty(self::$classMap[$class])) {
            return self::$classMap[$class];
        }

        // Search PSR-4 namespace directories.
        $logicalPathPsr4 = strtr($class, '\\', DS) . EXT;
        $first           = $class[0];

        if (isset(self::$prefixLengthsPsr4[$first])) {
            foreach (self::$prefixLengthsPsr4[$first] as $prefix => $length) {
                if (0 === strpos($class, $prefix)) {
                    foreach (self::$prefixDirsPsr4[$prefix] as $dir) {
                        if (is_file($file = $dir . DS . substr($logicalPathPsr4, $length))) {
                            return $file;
                        }
                    }
                }
            }
        }

        // Search PSR-4 fallback directories.
        foreach (self::$fallbackDirsPsr4 as $dir) {
            if (is_file($file = $dir . DS . $logicalPathPsr4)) {
                return $file;
            }
        }

        // Search PSR-0 namespace directories.
        if (false !== $pos = strrpos($class, '\\')) {
            // namespace class name
            $logicalPathPsr0 = substr($logicalPathPsr4, 0, $pos + 1)
            . strtr(substr($logicalPathPsr4, $pos + 1), '_', DS);
        } else {
            // PEAR-like class name
            $logicalPathPsr0 = strtr($class, '_', DS) . EXT;
        }

        if (isset(self::$prefixesPsr0[$first])) {
            foreach (self::$prefixesPsr0[$first] as $prefix => $dirs) {
                if (0 === strpos($class, $prefix)) {
                    foreach ($dirs as $dir) {
                        if (is_file($file = $dir . DS . $logicalPathPsr0)) {
                            return $file;
                        }
                    }
                }
            }
        }

        // Search PSR-0 fallback directories.
        foreach (self::$fallbackDirsPsr0 as $dir) {
            if (is_file($file = $dir . DS . $logicalPathPsr0)) {
                return $file;
            }
        }

        // If not found, set the mapping to false and return
        return self::$classMap[$class] = false;
    }

    /* *
 * Register class-to-file mappings.
 * @access public
 * @param string|array $class class name
 * @param string $map File path
 * @return void */
    public static function addClassMap($class, $map = '')
    {
        if (is_array($class)) {
            self::$classMap = array_merge(self::$classMap, $class);
        } else {
            self::$classMap[$class] = $map;
        }
    }

    /* *
 * Register namespaces
 * @access public
 * @param string|array $namespace namespace
 * @param string $path path
 * @return void */
    public static function addNamespace($namespace, $path = '')
    {
        if (is_array($namespace)) {
            foreach ($namespace as $prefix => $paths) {
                self::addPsr4($prefix . '\\', rtrim($paths, DS), true);
            }
        } else {
            self::addPsr4($namespace . '\\', rtrim($path, DS), true);
        }
    }

    /* *
 * Add PSR-0 namespace directories.
 * @access private
 * @param array|string $prefix Namespace prefix
 * @param array $paths Directories
 * @param bool $prepend Whether to search these directories first
 * @return void */
    private static function addPsr0($prefix, $paths, $prepend = false)
    {
        if (!$prefix) {
            self::$fallbackDirsPsr0 = $prepend ?
            array_merge((array) $paths, self::$fallbackDirsPsr0) :
            array_merge(self::$fallbackDirsPsr0, (array) $paths);
        } else {
            $first = $prefix[0];

            if (!isset(self::$prefixesPsr0[$first][$prefix])) {
                self::$prefixesPsr0[$first][$prefix] = (array) $paths;
            } else {
                self::$prefixesPsr0[$first][$prefix] = $prepend ?
                array_merge((array) $paths, self::$prefixesPsr0[$first][$prefix]) :
                array_merge(self::$prefixesPsr0[$first][$prefix], (array) $paths);
            }
        }
    }

    /* *
 * Add PSR-4 namespace directories.
 * @access private
 * @param array|string $prefix Namespace prefix
 * @param string $paths Directories
 * @param bool $prepend Whether to search these directories first
 * @return void */
    private static function addPsr4($prefix, $paths, $prepend = false)
    {
        if (!$prefix) {
            // Register directories for the root namespace.
            self::$fallbackDirsPsr4 = $prepend ?
            array_merge((array) $paths, self::$fallbackDirsPsr4) :
            array_merge(self::$fallbackDirsPsr4, (array) $paths);

        } elseif (!isset(self::$prefixDirsPsr4[$prefix])) {
            // Register directories for a new namespace.
            $length = strlen($prefix);
            if ('\\' !== $prefix[$length - 1]) {
                throw new \InvalidArgumentException(
                    "A non-empty PSR-4 prefix must end with a namespace separator."
                );
            }

            self::$prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
            self::$prefixDirsPsr4[$prefix]                = (array) $paths;

        } else {
            self::$prefixDirsPsr4[$prefix] = $prepend ?
            // Prepend directories for an already registered namespace.
            array_merge((array) $paths, self::$prefixDirsPsr4[$prefix]) :
            // Append directories for an already registered namespace.
            array_merge(self::$prefixDirsPsr4[$prefix], (array) $paths);
        }
    }

    /* *
 * Register a namespace alias
 * @access public
 * @param array|string $namespace
 * @param string $original Original namespace
 * @return void */
    public static function addNamespaceAlias($namespace, $original = '')
    {
        if (is_array($namespace)) {
            self::$namespaceAlias = array_merge(self::$namespaceAlias, $namespace);
        } else {
            self::$namespaceAlias[$namespace] = $original;
        }
    }

    /* *
 * Register the autoload mechanism
 * @access public
 * @param callable $autoload Custom autoload callback
 * @return void */
    public static function register($autoload = null)
    {
        // Register the supplied callback or the framework autoloader.
        spl_autoload_register($autoload ?: 'think\\Loader::autoload', true, true);

        // Composer autoload support
        if (is_dir(VENDOR_PATH . 'composer')) {
            if (PHP_VERSION_ID >= 50600 && is_file(VENDOR_PATH . 'composer' . DS . 'autoload_static.php')) {
                require VENDOR_PATH . 'composer' . DS . 'autoload_static.php';

                $declaredClass = get_declared_classes();
                $composerClass = array_pop($declaredClass);

                foreach (['prefixLengthsPsr4', 'prefixDirsPsr4', 'fallbackDirsPsr4', 'prefixesPsr0', 'fallbackDirsPsr0', 'classMap', 'files'] as $attr) {
                    if (property_exists($composerClass, $attr)) {
                        self::${$attr} = $composerClass::${$attr};
                    }
                }
            } else {
                self::registerComposerLoader();
            }
        }

        // Register a namespace definition
        self::addNamespace([
            'think'    => LIB_PATH . 'think' . DS,
            'behavior' => LIB_PATH . 'behavior' . DS,
            'traits'   => LIB_PATH . 'traits' . DS,
        ]);

        // Load the class library mapping file
        if (is_file(RUNTIME_PATH . 'classmap' . EXT)) {
            self::addClassMap(__include_file(RUNTIME_PATH . 'classmap' . EXT));
        }

        self::loadComposerAutoloadFiles();

        // Register the extend directory as a PSR-4 fallback.
        self::$fallbackDirsPsr4[] = rtrim(EXTEND_PATH, DS);
    }

    /* *
 * Register Composer autoload mappings.
 * @access private
 * @return void */
    private static function registerComposerLoader()
    {
        if (is_file(VENDOR_PATH . 'composer/autoload_namespaces.php')) {
            $map = require VENDOR_PATH . 'composer/autoload_namespaces.php';
            foreach ($map as $namespace => $path) {
                self::addPsr0($namespace, $path);
            }
        }

        if (is_file(VENDOR_PATH . 'composer/autoload_psr4.php')) {
            $map = require VENDOR_PATH . 'composer/autoload_psr4.php';
            foreach ($map as $namespace => $path) {
                self::addPsr4($namespace, $path);
            }
        }

        if (is_file(VENDOR_PATH . 'composer/autoload_classmap.php')) {
            $classMap = require VENDOR_PATH . 'composer/autoload_classmap.php';
            if ($classMap) {
                self::addClassMap($classMap);
            }
        }

        if (is_file(VENDOR_PATH . 'composer/autoload_files.php')) {
            self::$files = require VENDOR_PATH . 'composer/autoload_files.php';
        }
    }

    // Load Composer autoload files.
    public static function loadComposerAutoloadFiles()
    {
        foreach (self::$files as $fileIdentifier => $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
                __require_file($file);

                $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;
            }
        }
    }

    /* *
 * Import a class-library file and cache successful imports.
 * @access public
 * @param string $class Class-library namespace string
 * @param string $baseUrl Base directory
 * @param string $ext imported file extension
 * @return bool */
    public static function import($class, $baseUrl = '', $ext = EXT)
    {
        static $_file = [];
        $key          = $class . $baseUrl;
        $class        = str_replace(['.', '#'], [DS, '.'], $class);

        if (isset($_file[$key])) {
            return true;
        }

        if (empty($baseUrl)) {
            list($name, $class) = explode(DS, $class, 2);

            if (isset(self::$prefixDirsPsr4[$name . '\\'])) {
                // Registered namespaces
                $baseUrl = self::$prefixDirsPsr4[$name . '\\'];
            } elseif ('@' == $name) {
                // Load the current module application class library
                $baseUrl = App::$modulePath;
            } elseif (is_dir(EXTEND_PATH . $name)) {
                $baseUrl = EXTEND_PATH . $name . DS;
            } else {
                // Load libraries for other modules
                $baseUrl = APP_PATH . $name . DS;
            }
        } elseif (substr($baseUrl, -1) != DS) {
            $baseUrl .= DS;
        }

        // Resolve the class-library file path.
        if (is_array($baseUrl)) {
            foreach ($baseUrl as $path) {
                if (is_file($filename = $path . DS . $class . $ext)) {
                    break;
                }
            }
        } else {
            $filename = $baseUrl . $class . $ext;
        }

        if (!empty($filename) &&
            is_file($filename) &&
            (!IS_WIN || pathinfo($filename, PATHINFO_FILENAME) == pathinfo(realpath($filename), PATHINFO_FILENAME))
        ) {
            __include_file($filename);
            $_file[$key] = true;

            return true;
        }

        return false;
    }

    /* *
 * Instantiate a model from the requested layer.
 * @access public
 * @param string $name Model name
 * @param string $layer business layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @param string $common Shared module name
 * @return object
 * @throws classNotFoundException */
    public static function model($name = '', $layer = 'model', $appendSuffix = false, $common = 'common')
    {
        $uid = $name . $layer;

        if (isset(self::$instance[$uid])) {
            return self::$instance[$uid];
        }

        list($module, $class) = self::getModuleAndClass($name, $layer, $appendSuffix);

        if (class_exists($class)) {
            $model = new $class();
        } else {
            $class = str_replace('\\' . $module . '\\', '\\' . $common . '\\', $class);

            if (class_exists($class)) {
                $model = new $class();
            } else {
                throw new ClassNotFoundException('class not exists:' . $class, $class);
            }
        }

        return self::$instance[$uid] = $model;
    }

    /* *
 * Instantiate a controller using [module name/]controller name.
 * @access public
 * @param string $name resource address
 * @param string $layer Controller layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @param string $empty Fallback controller name
 * @return object
 * @throws classNotFoundException */
    public static function controller($name, $layer = 'controller', $appendSuffix = false, $empty = '')
    {
        list($module, $class) = self::getModuleAndClass($name, $layer, $appendSuffix);

        if (class_exists($class)) {
            return App::invokeClass($class);
        }

        if ($empty) {
            $emptyClass = self::parseClass($module, $layer, $empty, $appendSuffix);

            if (class_exists($emptyClass)) {
                return new $emptyClass(Request::instance());
            }
        }

        throw new ClassNotFoundException('class not exists:' . $class, $class);
    }

    /* *
 * Instantiate a validator using [module name/]validator name.
 * @access public
 * @param string $name resource address
 * @param string $layer Validation layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @param string $common Shared module name
 * @return object|false
 * @throws classNotFoundException */
    public static function validate($name = '', $layer = 'validate', $appendSuffix = false, $common = 'common')
    {
        $name = $name ?: Config::get('default_validate');

        if (empty($name)) {
            return new Validate;
        }

        $uid = $name . $layer;
        if (isset(self::$instance[$uid])) {
            return self::$instance[$uid];
        }

        list($module, $class) = self::getModuleAndClass($name, $layer, $appendSuffix);

        if (class_exists($class)) {
            $validate = new $class;
        } else {
            $class = str_replace('\\' . $module . '\\', '\\' . $common . '\\', $class);

            if (class_exists($class)) {
                $validate = new $class;
            } else {
                throw new ClassNotFoundException('class not exists:' . $class, $class);
            }
        }

        return self::$instance[$uid] = $validate;
    }

    /* *
 * Parse module and class names
 * @access protected
 * @param string $name resource address
 * @param string $layer Layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @return array */
    protected static function getModuleAndClass($name, $layer, $appendSuffix)
    {
        if (false !== strpos($name, '\\')) {
            $module = Request::instance()->module();
            $class  = $name;
        } else {
            if (strpos($name, '/')) {
                list($module, $name) = explode('/', $name, 2);
            } else {
                $module = Request::instance()->module();
            }

            $class = self::parseClass($module, $layer, $name, $appendSuffix);
        }

        return [$module, $class];
    }

    /* *
 * Get a database connection instance.
 * @access public
 * @param mixed $config database configuration
 * @param bool|string $name connection ID true to force a reconnection
 * @return \think\db\Connection */
    public static function db($config = [], $name = false)
    {
        return Db::connect($config, $name);
    }

    /* *
 * Call a controller action using [module/controller/]action.
 * @access public
 * @param string $url Action address
 * @param string|array $vars Arguments as a string or array
 * @param string $layer Controller layer name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @return mixed */
    public static function action($url, $vars = [], $layer = 'controller', $appendSuffix = false)
    {
        $info   = pathinfo($url);
        $action = $info['basename'];
        $module = '.' != $info['dirname'] ? $info['dirname'] : Request::instance()->controller();
        $class  = self::controller($module, $layer, $appendSuffix);

        if ($class) {
            if (is_scalar($vars)) {
                if (strpos($vars, '=')) {
                    parse_str($vars, $vars);
                } else {
                    $vars = [$vars];
                }
            }

            return App::invokeMethod([$class, $action . Config::get('action_suffix')], $vars);
        }

        return false;
    }

    /* *
 * String naming style conversion
 * Type 0 converts camelCase to snake_case; type 1 converts snake_case to camelCase.
 * @access public
 * @param string $name string
 * @param integer $type conversion type
 * @param bool $ucfirst Whether to capitalize the first letter for camelCase
 * @return string */
    public static function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);

            return $ucfirst ? ucfirst($name) : lcfirst($name);
        }

        return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
    }

    /* *
 * Build an application class name.
 * @access public
 * @param string $module module name
 * @param string $layer Layer name, such as controller or model
 * @param string $name Class name
 * @param bool $appendSuffix Whether to add the class name suffix
 * @return string */
    public static function parseClass($module, $layer, $name, $appendSuffix = false)
    {

        $array = explode('\\', str_replace(['/', '.'], '\\', $name));
        $class = self::parseName(array_pop($array), 1);
        $class = $class . (App::$suffix || $appendSuffix ? ucfirst($layer) : '');
        $path  = $array ? implode('\\', $array) . '\\' : '';

        return App::$namespace . '\\' .
            ($module ? $module . '\\' : '') .
            $layer . '\\' . $path . $class;
    }

    /* *
 * Clear cached class instances.
 * @access public
 * @return void */
    public static function clearInstance()
    {
        self::$instance = [];
    }
}

// Isolate file inclusion from the loader class scope.

/* *
 * Include a file in an isolated function scope.
 * @param string $file file path
 * @return mixed */
function __include_file($file)
{
    return include $file;
}

/* *
 * Require a file in an isolated function scope.
 * @param string $file file path
 * @return mixed */
function __require_file($file)
{
    return require $file;
}
