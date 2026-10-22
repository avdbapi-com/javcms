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

class View
{
    // Shared view instance
    protected static $instance;
    // Template engine instance
    public $engine;
    // template variables
    protected $data = [];
    // Template variables for static assignments
    protected static $var = [];
    // View output replacement
    protected $replace = [];

    /* *
 * Constructor
 * @access public
 * @param array $engine template engine parameters
 * @param array $replace string replacement parameters */
    public function __construct($engine = [], $replace = [])
    {
        // Initialize the template engine
        $this->engine($engine);
        // Basic replacement strings
        $request = Request::instance();
        $base    = $request->root();
        $root    = strpos($base, '.') ? ltrim(dirname($base), DS) : $base;
        if ('' != $root) {
            $root = '/' . ltrim($root, '/');
        }
        $baseReplace = [
            '__ROOT__'   => $root,
            '__URL__'    => $base . '/' . $request->module() . '/' . Loader::parseName($request->controller()),
            '__STATIC__' => $root . '/static',
            '__CSS__'    => $root . '/static/css',
            '__JS__'     => $root . '/static/js',
        ];
        $this->replace = array_merge($baseReplace, (array) $replace);
    }

    /* *
 * Get the shared view instance
 * @access public
 * @param array $engine template engine parameters
 * @param array $replace String replacement parameters
 * @return object */
    public static function instance($engine = [], $replace = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($engine, $replace);
        }
        return self::$instance;
    }

    /* *
 * Assign shared template variables
 * @access public
 * @param mixed $name variable name
 * @param mixed $value variable value
 * @return void */
    public static function share($name, $value = '')
    {
        if (is_array($name)) {
            self::$var = array_merge(self::$var, $name);
        } else {
            self::$var[$name] = $value;
        }
    }

    /* *
 * Template variable assignment
 * @access public
 * @param mixed $name variable name
 * @param mixed $value Variable value
 * @return $this */
    public function assign($name, $value = '')
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }
        return $this;
    }

    /* *
 * Select and initialize the template engine
 * @access public
 * @param array|string $options Engine type or configuration
 * @return $this */
    public function engine($options = [])
    {
        if (is_string($options)) {
            $type    = $options;
            $options = [];
        } else {
            $type = !empty($options['type']) ? $options['type'] : 'Think';
        }

        $class = false !== strpos($type, '\\') ? $type : '\\think\\view\\driver\\' . ucfirst($type);
        if (isset($options['type'])) {
            unset($options['type']);
        }
        $this->engine = new $class($options);
        return $this;
    }

    /* *
 * Configure the template engine
 * @access private
 * @param string|array $name parameter name
 * @param mixed $value parameter value
 * @return $this */
    public function config($name, $value = null)
    {
        $this->engine->config($name, $value);
        return $this;
    }

    /* *
 * Render a template file or string and return its output
 * @param string $template template file name or content
 * @param array $vars template output variables
 * @param array $replace Template replacement strings
 * @param array $config template parameters
 * @param bool $renderContent Whether the template argument contains a template string
 * @return string
 * @throws Exception */
    public function fetch($template = '', $vars = [], $replace = [], $config = [], $renderContent = false)
    {
        // template variables
        $vars = array_merge(self::$var, $this->data, $vars);

        // Capture template output in a buffer
        ob_start();
        ob_implicit_flush(0);

        // Render output
        try {
            $method = $renderContent ? 'display' : 'fetch';
            // Allows the user to customize string replacement for templates
            $replace = array_merge($this->replace, $replace, (array) $this->engine->config('tpl_replace_string'));
            $this->engine->config('tpl_replace_string', $replace);
            $this->engine->$method($template, $vars, $config);
        } catch (\Exception $e) {
            ob_end_clean();
            throw $e;
        }

        // Get the output and clear the buffer
        $content = ob_get_clean();
        // Invoke the view_filter hook
        Hook::listen('view_filter', $content);
        return $content;
    }

    /* *
 * Set view output replacement strings
 * @access public
 * @param string|array $content Text to replace or an array of replacements
 * @param string $replace Replacement text
 * @return $this */
    public function replace($content, $replace = '')
    {
        if (is_array($content)) {
            $this->replace = array_merge($this->replace, $content);
        } else {
            $this->replace[$content] = $replace;
        }
        return $this;
    }

    /* *
 * Render a template string
 * @access public
 * @param string $content content
 * @param array $vars template output variables
 * @param array $replace Template replacement strings
 * @param array $config template parameters
 * @return mixed */
    public function display($content, $vars = [], $replace = [], $config = [])
    {
        return $this->fetch($content, $vars, $replace, $config, true);
    }

    /* *
 * Template variable assignment
 * @access public
 * @param string $name variable name
 * @param mixed $value variable value */
    public function __set($name, $value)
    {
        $this->data[$name] = $value;
    }

    /* *
 * Get a template variable value
 * @access protected
 * @param string $name template variable
 * @return mixed */
    public function __get($name)
    {
        return $this->data[$name];
    }

    /* *
 * Check if the template variable is set
 * @access public
 * @param string $name template variable name
 * @return bool */
    public function __isset($name)
    {
        return isset($this->data[$name]);
    }
}
