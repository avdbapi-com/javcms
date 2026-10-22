<?php

namespace think;

use think\Config;
use think\View;

/* *
 * Plugin base class
 * Class Addons
 * @author Byron Sampson <xiaobo.sun@qq.com>
 * @package think\\addons */
abstract class Addons
{

    // View instance
    protected $view = null;
    // Current error message
    protected $error;
    // Plugin directory
    public $addons_path = '';
    // Plugin configuration scope
    protected $configRange = 'addonconfig';
    // Plugin metadata scope
    protected $infoRange = 'addoninfo';

    /* *
 * Initialize the plugin
 * @access public */
    public function __construct()
    {
        $name = $this->getName();
        // Get the current plugin directory
        $this->addons_path = ADDON_PATH . $name . DS;

        // Initialize the template view
        $config = ['view_path' => $this->addons_path];
        $config = array_merge(Config::get('template'), $config);
        $this->view = new View($config, Config::get('view_replace_str'));

        // Controller initialization
        if (method_exists($this, '_initialize')) {
            $this->_initialize();
        }
    }

    /* *
 * Read plugin metadata
 * @param string $name
 * @return array */
    final public function getInfo($name = '')
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $info = Config::get($name, $this->infoRange);
        if ($info) {
            return $info;
        }
        $info_file = $this->addons_path . 'info.ini';
        if (is_file($info_file)) {
            $info = Config::parse($info_file, '', $name, $this->infoRange);
            $info['url'] = addon_url($name);
        }
        Config::set($name, $info, $this->infoRange);

        return $info ? $info : [];
    }

    /* *
 * Get plugin configuration values
 * @param string $name Optional plugin name
 * @return array */
    final public function getConfig($name = '')
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $config = Config::get($name, $this->configRange);
        if ($config) {
            return $config;
        }
        $config_file = $this->addons_path . 'config.php';
        if (is_file($config_file)) {
            $temp_arr = include $config_file;
            foreach ($temp_arr as $key => $value) {
                $config[$value['name']] = $value['value'];
            }
            unset($temp_arr);
        }
        Config::set($name, $config, $this->configRange);

        return $config;
    }

    /* *
* Merge plugin configuration values
* @param $name
* @param array $value
* @return array */
    final public function setConfig($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $config = $this->getConfig($name);
        $config = array_merge($config, $value);
        Config::set($name, $config, $this->configRange);
        return $config;
    }

    /* *
* Merge plugin metadata
* @param $name
* @param array $value
* @return array */
    final public function setInfo($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $info = $this->getInfo($name);
        $info = array_merge($info, $value);
        Config::set($name, $info, $this->infoRange);
        return $info;
    }

    /* *
 * Get the full plugin configuration definitions
 * @param string $name
 * @return array */
    final public function getFullConfig($name = '')
    {
        $fullConfigArr = [];
        if (empty($name)) {
            $name = $this->getName();
        }
        $config_file = $this->addons_path . 'config.php';
        if (is_file($config_file)) {
            $fullConfigArr = include $config_file;
        }
        return $fullConfigArr;
    }

    /* *
 * Get the current plugin name
 * @return string */
    final public function getName()
    {
        $data = explode('\\', get_class($this));
        return strtolower(array_pop($data));
    }

    /* *
 * Check the required plugin metadata fields
 * @return bool */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'intro', 'author', 'version', 'state'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }

    /* *
 * Render and output a template
 * @access public
 * @param string $template template file name or content
 * @param array $vars template output variables
 * @param array $replace replace content
 * @param array $config template parameters
 * @return mixed
 * @throws\\ Exception */
    public function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        if (!is_file($template)) {
            $template = '/' . $template;
        }
        // Disable template layout
        $this->view->engine->layout(false);

        echo $this->view->fetch($template, $vars, $replace, $config);
    }

    /* *
 * Render and output content
 * @access public
 * @param string $content content
 * @param array $vars template output variables
 * @param array $replace replace content
 * @param array $config template parameters
 * @return mixed */
    public function display($content, $vars = [], $replace = [], $config = [])
    {
        // Disable template layout
        $this->view->engine->layout(false);

        echo $this->view->display($content, $vars, $replace, $config);
    }

    /* *
 * Render and output content
 * @access public
 * @param string $content content
 * @param array $vars template output variables
 * @return mixed */
    public function show($content, $vars = [])
    {
        // Disable template layout
        $this->view->engine->layout(false);

        echo $this->view->fetch($content, $vars, [], [], true);
    }

    /* *
 * Template variable assignment
 * @access protected
 * @param mixed $name Template variable name or array
 * @param mixed $value Variable value
 * @return void */
    public function assign($name, $value = '')
    {
        $this->view->assign($name, $value);
    }

    /* *
 * Get current error information
 * @return mixed */
    public function getError()
    {
        return $this->error;
    }

    // Subclasses must implement plugin installation
    abstract public function install();

    // Subclasses must implement plugin uninstallation
    abstract public function uninstall();
}
