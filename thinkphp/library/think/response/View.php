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

namespace think\response;

use think\Config;
use think\Response;
use think\View as ViewTemplate;

class View extends Response
{
    // output parameters
    protected $options     = [];
    protected $vars        = [];
    protected $replace     = [];
    protected $contentType = 'text/html';

    /* *
 * Render a template as response content
 * @access protected
 * @param mixed $data Template to render
 * @return mixed */
    protected function output($data)
    {
        // Render template output
        return ViewTemplate::instance(Config::get('template'), Config::get('view_replace_str'))
            ->fetch($data, $this->vars, $this->replace);
    }

    /* *
 * Get one template variable, or all variables when no name is supplied
 * @access public
 * @param string|null $name Template variable name, or null for all variables
 * @return mixed */
    public function getVars($name = null)
    {
        if (is_null($name)) {
            return $this->vars;
        } else {
            return isset($this->vars[$name]) ? $this->vars[$name] : null;
        }
    }

    /* *
 * Assign one or more template variables
 * @access public
 * @param string|array $name Variable name or array of variables
 * @param mixed $value Variable value
 * @return $this */
    public function assign($name, $value = '')
    {
        if (is_array($name)) {
            $this->vars = array_merge($this->vars, $name);
            return $this;
        } else {
            $this->vars[$name] = $value;
        }
        return $this;
    }

    /* *
 * Configure replacements for rendered view content
 * @access public
 * @param string|array $content Text to replace, or an array of replacements
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

}
