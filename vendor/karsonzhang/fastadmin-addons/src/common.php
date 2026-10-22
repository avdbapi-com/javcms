<?php

use think\App;
use think\Cache;
use think\Config;
use think\Exception;
use think\Hook;
use think\Loader;
use think\Route;

// Plugin directory
define('ADDON_PATH', ROOT_PATH . 'addons' . DS);

// Defining routes
Route::any('addons/:addon/[:controller]/[:action]', "\\think\\addons\\Route@execute");

// Create the plugin directory if it does not exist
if (!is_dir(ADDON_PATH)) {
    @mkdir(ADDON_PATH, 0755, true);
}

// Register the root namespace for classes
Loader::addNamespace('addons', ADDON_PATH);

// Invoke the addon_init hook
Hook::listen('addon_init');

// Register automatic plugin configuration loading
Hook::add('app_init', function () {
    // Get the switch
    $autoload = (bool)Config::get('addons.autoload', false);
    // Skip automatic loading when disabled
    if (!$autoload) {
        return;
    }
    // The configuration is not cached when debugging
    $config = App::$debug ? [] : Cache::get('addons', []);
    if (empty($config)) {
        $config = get_addon_autoload_config();
        Cache::set('addons', $config);
    }
});

// Register plugin routes and hooks during application initialization
Hook::add('app_init', function () {
    // Register routes
    $routeArr = (array)Config::get('addons.route');
    $domains = [];
    $rules = [];
    $execute = "\\think\\addons\\Route@execute?addon=%s&controller=%s&action=%s";
    foreach ($routeArr as $k => $v) {
        if (is_array($v)) {
            $addon = $v['addon'];
            $domain = $v['domain'];
            $drules = [];
            foreach ($v['rule'] as $m => $n) {
                list($addon, $controller, $action) = explode('/', $n);
                $drules[$m] = sprintf($execute . '&indomain=1', $addon, $controller, $action);
            }
            // $domains[$domain] = $drules ? $drules : \"\\\\addons\\\\{$k}\\\\controller\";
            $domains[$domain] = $drules ? $drules : [];
            $domains[$domain][':controller/[:action]'] = sprintf($execute . '&indomain=1', $addon, ":controller", ":action");
        } else {
            if (!$v) {
                continue;
            }
            list($addon, $controller, $action) = explode('/', $v);
            $rules[$k] = sprintf($execute, $addon, $controller, $action);
        }
    }
    Route::rule($rules);
    if ($domains) {
        Route::domain($domains);
    }

    // Get system configuration
    $hooks = App::$debug ? [] : Cache::get('hooks', []);
    if (empty($hooks)) {
        $hooks = (array)Config::get('addons.hooks');
        // Initialize hooks
        foreach ($hooks as $key => $values) {
            if (is_string($values)) {
                $values = explode(',', $values);
            } else {
                $values = (array)$values;
            }
            $hooks[$key] = array_filter(array_map('get_addon_class', $values));
        }
        Cache::set('hooks', $hooks);
    }
    // If app_init is defined in the plugin, execute it directly
    if (isset($hooks['app_init'])) {
        foreach ($hooks['app_init'] as $k => $v) {
            Hook::exec($v, 'app_init');
        }
    }
    Hook::import($hooks, true);
});

/* *
 * Invoke a plugin hook
 * @param string $hook hook name
 * @param mixed $params Incoming parameters
 * @return void */
function hook($hook, $params = [])
{
    Hook::listen($hook, $params);
}

/* *
 * Remove empty directories
 * @param string $dir directory */
function remove_empty_folder($dir)
{
    try {
        $isDirEmpty = !(new \FilesystemIterator($dir))->valid();
        if ($isDirEmpty) {
            @rmdir($dir);
            remove_empty_folder(dirname($dir));
        }
    } catch (\UnexpectedValueException $e) {

    } catch (\Exception $e) {

    }
}

/* *
 * Get the list of plugins
 * @return array */
function get_addon_list()
{
    $results = scandir(ADDON_PATH);
    $list = [];
    foreach ($results as $name) {
        if ($name === '.' or $name === '..') {
            continue;
        }
        if (is_file(ADDON_PATH . $name)) {
            continue;
        }
        $addonDir = ADDON_PATH . $name . DS;
        if (!is_dir($addonDir)) {
            continue;
        }

        if (!is_file($addonDir . ucfirst($name) . '.php')) {
            continue;
        }

        // Read metadata directly to avoid cached plugin information
        // $info = get_addon_info($name);
        $info_file = $addonDir . 'info.ini';
        if (!is_file($info_file)) {
            continue;
        }

        $info = Config::parse($info_file, '', "addon-info-{$name}");
        if (!isset($info['name'])) {
            continue;
        }
        $info['url'] = addon_url($name);
        $list[$name] = $info;
    }
    return $list;
}

/* *
 * Get the configuration for automatic plugin loading
 * @param bool $truncate Whether to clear manually configured hooks
 * @return array */
function get_addon_autoload_config($truncate = false)
{
    // Read plugin configuration
    $config = (array)Config::get('addons');
    if ($truncate) {
        // Clear manually configured hooks
        $config['hooks'] = [];
    }

    // Plugin URL rewrite priority
    $priority = isset($config['priority']) && $config['priority'] ? is_array($config['priority']) ? $config['priority'] : explode(',', $config['priority']) : [];

    $route = [];
    // Read the plugin directory and hook list
    $base = get_class_methods("\\think\\Addons");
    $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);

    $url_domain_deploy = Config::get('url_domain_deploy');
    $addons = get_addon_list();
    $domain = [];

    $priority = array_merge($priority, array_keys($addons));

    $orderedAddons = array();
    foreach ($priority as $key) {
        if (!isset($addons[$key])) {
            continue;
        }
        $orderedAddons[$key] = $addons[$key];
    }

    foreach ($orderedAddons as $name => $addon) {
        if (!$addon['state']) {
            continue;
        }

        // Get all public methods
        $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . ucfirst($name));
        // Identify plugin hook methods not defined on the base class
        $hooks = array_diff($methods, $base);
        // Register plugin hook methods in the configuration
        foreach ($hooks as $hook) {
            $hook = Loader::parseName($hook, 0, false);
            if (!isset($config['hooks'][$hook])) {
                $config['hooks'][$hook] = [];
            }
            // Normalize manually configured hook lists
            if (is_string($config['hooks'][$hook])) {
                $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
            }
            if (!in_array($name, $config['hooks'][$hook])) {
                $config['hooks'][$hook][] = $name;
            }
        }
        $conf = get_addon_config($addon['name']);
        if ($conf) {
            $conf['rewrite'] = isset($conf['rewrite']) && is_array($conf['rewrite']) ? $conf['rewrite'] : [];
            $rule = array_map(function ($value) use ($addon) {
                return "{$addon['name']}/{$value}";
            }, array_flip($conf['rewrite']));
            if ($url_domain_deploy && isset($conf['domain']) && $conf['domain']) {
                $domain[] = [
                    'addon'  => $addon['name'],
                    'domain' => $conf['domain'],
                    'rule'   => $rule
                ];
            } else {
                $route = array_merge($route, $rule);
            }
        }
    }
    $config['route'] = $route;
    $config['route'] = array_merge($config['route'], $domain);
    return $config;
}

/* *
 * Resolve the plugin class name
 * @param string $name plugin name
 * @param string $type Plugin class category
 * @param string $class Current class name
 * @return string */
function get_addon_class($name, $type = 'hook', $class = null)
{
    $name = Loader::parseName($name);
    // Resolve nested controller names
    if (!is_null($class) && strpos($class, '.')) {
        $class = explode('.', $class);

        $class[count($class) - 1] = Loader::parseName(end($class), 1);
        $class = implode('\\', $class);
    } else {
        $class = Loader::parseName(is_null($class) ? $name : $class, 1);
    }
    switch ($type) {
        case 'controller':
            $namespace = "\\addons\\" . $name . "\\controller\\" . $class;
            break;
        default:
            $namespace = "\\addons\\" . $name . "\\" . $class;
    }
    return class_exists($namespace) ? $namespace : '';
}

/* *
 * Read plugin metadata
 * @param string $name plugin name
 * @return array */
function get_addon_info($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getInfo($name);
}

/* *
 * Get the plugin class configuration array
 * @param string $name plugin name
 * @return array */
function get_addon_fullconfig($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getFullConfig($name);
}

/* *
 * Get plugin configuration values
 * @param string $name plugin name
 * @return array */
function get_addon_config($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getConfig($name);
}

/* *
 * Get a cached plugin instance
 * @param string $name plugin name
 * @return mixed|null */
function get_addon_instance($name)
{
    static $_addons = [];
    if (isset($_addons[$name])) {
        return $_addons[$name];
    }
    $class = get_addon_class($name);
    if (class_exists($class)) {
        $_addons[$name] = new $class();
        return $_addons[$name];
    } else {
        return null;
    }
}

/* *
 * Generate a plugin URL
 * @param string $url Address Format: Plugin Name/Controller/Method
 * @param array $vars variable parameters
 * @param bool|string $suffix URL suffix generated
 * @param bool|string $domain
 * @return bool|string */
function addon_url($url, $vars = [], $suffix = true, $domain = false)
{
    $url = ltrim($url, '/');
    $addon = substr($url, 0, stripos($url, '/'));
    if (!is_array($vars)) {
        parse_str($vars, $params);
        $vars = $params;
    }
    $params = [];
    foreach ($vars as $k => $v) {
        if (substr($k, 0, 1) === ':') {
            $params[$k] = $v;
            unset($vars[$k]);
        }
    }
    $val = "@addons/{$url}";
    $config = get_addon_config($addon);
    $dispatch = think\Request::instance()->dispatch();
    $indomain = isset($dispatch['var']['indomain']) && $dispatch['var']['indomain'] ? true : false;
    $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : '';
    $domain = $domainprefix && Config::get('url_domain_deploy') ? $domainprefix : $domain;
    $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
    if ($rewrite) {
        $path = substr($url, stripos($url, '/') + 1);
        if (isset($rewrite[$path]) && $rewrite[$path]) {
            $val = $rewrite[$path];
            array_walk($params, function ($value, $key) use (&$val) {
                $val = str_replace("[{$key}]", $value, $val);
            });
            $val = str_replace(['^', '$'], '', $val);
            if (substr($val, -1) === '/') {
                $suffix = false;
            }
        } else {
            // If domain name deployment is used, the first two sections need to be removed
            if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }
        }
    } else {
        // If domain name deployment is used, the first two sections need to be removed
        if ($indomain && $domainprefix) {
            $arr = explode("/", $val);
            $val = implode("/", array_slice($arr, 2));
        }
        foreach ($params as $k => $v) {
            $vars[substr($k, 1)] = $v;
        }
    }
    $url = url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
    $url = preg_replace("/\/((?!index)[\w]+)\.php\//i", "/", $url);
    return $url;
}

/* *
 * Set plugin metadata
 * @param string $name plugin name
 * @param array $array configuration data
 * @return boolean
 * @throws Exception */
function set_addon_info($name, $array)
{
    $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'info.ini';
    $addon = get_addon_instance($name);
    $array = $addon->setInfo($name, $array);
    if (!isset($array['name']) || !isset($array['title']) || !isset($array['version'])) {
        throw new Exception("Plugin metadata must include a name, title, and version");
    }
    $res = array();
    foreach ($array as $key => $val) {
        if (is_array($val)) {
            $res[] = "[$key]";
            foreach ($val as $skey => $sval) {
                $res[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
            }
        } else {
            $res[] = "$key = " . (is_numeric($val) ? $val : $val);
        }
    }
    if ($handle = fopen($file, 'w')) {
        fwrite($handle, implode("\n", $res) . "\n");
        fclose($handle);
        // Clear the current configuration cache
        Config::set($name, null, 'addoninfo');
    } else {
        throw new Exception("The file is not writable");
    }
    return true;
}

/* *
 * Write the configuration file
 * @param string $name plugin name
 * @param array $config configuration data
 * @param boolean $writefile Whether to persist configuration to disk
 * @return bool
 * @throws Exception */
function set_addon_config($name, $config, $writefile = true)
{
    $addon = get_addon_instance($name);
    $addon->setConfig($name, $config);
    $fullconfig = get_addon_fullconfig($name);
    foreach ($fullconfig as $k => &$v) {
        if (isset($config[$v['name']])) {
            $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(',', $config[$v['name']]) : $config[$v['name']];
            $v['value'] = $value;
        }
    }
    if ($writefile) {
        // Write the configuration file
        set_addon_fullconfig($name, $fullconfig);
    }
    return true;
}

/* *
 * Write the configuration file
 *
 * @param string $name plugin name
 * @param array $array configuration data
 * @return boolean
 * @throws Exception */
function set_addon_fullconfig($name, $array)
{
    $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_really_writable($file)) {
        throw new Exception("The file is not writable");
    }
    if ($handle = fopen($file, 'w')) {
        fwrite($handle, "<?php\n\n" . "return " . var_export($array, true) . ";\n");
        fclose($handle);
    } else {
        throw new Exception("The file is not writable");
    }
    return true;
}
