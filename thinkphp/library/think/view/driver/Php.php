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

class Php
{
    // Template engine parameters
    protected $config = [
        // View base directory (centralized)
        'view_base'   => '',
        // Template base path
        'view_path'   => '',
        // template file suffix
        'view_suffix' => 'php',
        // template file name separator
        'view_depr'   => DS,
        // Default action-name rule: 1 uses underscore notation; other values use the request action name
        'auto_rule'   => 1,
    ];
    protected $template;
    protected $content;

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
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
 * Render a PHP template file
 * @access public
 * @param string $template Template file path or template name
 * @param array $data template variables
 * @return void */
    public function fetch($template, $data = [])
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            // Get the template file name
            $template = $this->parseTemplate($template);
        }
        // Throw an exception if the template file does not exist
        if (!is_file($template)) {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }
        $this->template = $template;
        // Record view information
        App::$debug && Log::record('[ VIEW ] ' . $template . ' [ ' . var_export(array_keys($data), true) . ' ]', 'info');

        extract($data, EXTR_OVERWRITE);
        include $this->template;
    }

    /* *
 * Render template content
 * @access public
 * @param string $content template content
 * @param array $data template variables
 * @return void */
    public function display($content, $data = [])
    {
        $this->content = $content;

        extract($data, EXTR_OVERWRITE);
        eval('?>' . $this->content);
    }

    /* *
 * Automatically locate template files
 * @access private
 * @param string $template Template file path or template name
 * @return string */
    private function parseTemplate($template)
    {
        if (empty($this->config['view_path'])) {
            $this->config['view_path'] = App::$modulePath . 'view' . DS;
        }

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
            $this->config = array_merge($this->config, $name);
        } elseif (is_null($value)) {
            return isset($this->config[$name]) ? $this->config[$name] : null;
        } else {
            $this->config[$name] = $value;
        }
    }

}
