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

namespace think\view\driver;

use think\App;
use think\exception\TemplateNotFoundException;
use think\Loader;
use think\Log;
use think\Request;
use think\Template;

class Think
{
    // Template engine instance
    private $template;
    // Template engine parameters
    protected $config = [
        // View base directory (centralized)
        'view_base'   => '',
        // Template base path
        'view_path'   => '',
        // template file suffix
        'view_suffix' => 'html',
        // template file name separator
        'view_depr'   => DS,
        // Whether to enable the template compilation cache. If set to false, it will be recompiled every time
        'tpl_cache'   => true,
        // Default action-name rule: 1 uses underscore notation; other values use the request action name
        'auto_rule'   => 1,
    ];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
        if (empty($this->config['view_path'])) {
            $this->config['view_path'] = App::$modulePath . 'view' . DS;
        }

        $this->template = new Template($this->config);
    }

    /* *
 * Check if a template file exists
 * @access public
 * @param string $template Template file path or template name
 * @return bool */
    public function exists($template)
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // Get the template file name
            $template = $this->parseTemplate($template);
        }
        return is_file($template);
    }

    /* *
 * Render a template file
 * @access public
 * @param string $template Template file path or template name
 * @param array $data template variables
 * @param array $config Template rendering options
 * @return void */
    public function fetch($template, $data = [], $config = [])
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // Get the template file name
            $template = $this->parseTemplate($template);
        }
        // Throw an exception if the template file does not exist
        if (!is_file($template)) {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }
        // Record view information
        App::$debug && Log::record('[ VIEW ] ' . $template . ' [ ' . var_export(array_keys($data), true) . ' ]', 'info');
        $this->template->fetch($template, $data, $config);
    }

    /* *
 * Render template content
 * @access public
 * @param string $template Template content
 * @param array $data template variables
 * @param array $config Template rendering options
 * @return void */
    public function display($template, $data = [], $config = [])
    {
        $this->template->display($template, $data, $config);
    }

    /* *
 * Automatically locate template files
 * @access private
 * @param string $template Template file path or template name
 * @return string */
    private function parseTemplate($template)
    {
        // Resolve the template name to a file path
        $request = Request::instance();
        // Get the view root directory
        if (strpos($template, '@')) {
            // Extract an explicitly specified module
            list($module, $template) = explode('@', $template);
        }
        if ($this->config['view_base']) {
            // Use the shared view base directory
            $module = isset($module) ? $module : $request->module();
            $path   = $this->config['view_base'] . ($module ? $module . DS : '');
        } else {
            $path = isset($module) ? APP_PATH . $module . DS . 'view' . DS : $this->config['view_path'];
        }

        $depr = $this->config['view_depr'];
        if (0 !== strpos($template, '/')) {
            $template   = str_replace(['/', ':'], $depr, $template);
            $controller = Loader::parseName($request->controller());
            if ($controller) {
                if ('' == $template) {
                    // Use the controller and action to locate an unnamed template
                    $template = str_replace('.', DS, $controller) . $depr . (1 == $this->config['auto_rule'] ? Loader::parseName($request->action(true)) : $request->action());
                } elseif (false === strpos($template, $depr)) {
                    $template = str_replace('.', DS, $controller) . $depr . $template;
                }
            }
        } else {
            $template = str_replace(['/', ':'], $depr, substr($template, 1));
        }
        return $path . ltrim($template, '/') . '.' . ltrim($this->config['view_suffix'], '.');
    }

    /* *
 * Set or get template engine settings
 * @access public
 * @param string|array $name Setting name or array of settings
 * @param mixed $value Setting value
 * @return mixed */
    public function config($name, $value = null)
    {
        if (is_array($name)) {
            $this->template->config($name);
            $this->config = array_merge($this->config, $name);
        } elseif (is_null($value)) {
            return $this->template->config($name);
        } else {
            $this->template->$name = $value;
            $this->config[$name]   = $value;
        }
    }

    public function __call($method, $params)
    {
        return call_user_func_array([$this->template, $method], $params);
    }
}
