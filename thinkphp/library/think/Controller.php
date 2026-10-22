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

use think\exception\ValidateException;
use traits\controller\Jump;

Loader::import('controller/Jump', TRAIT_PATH, EXT);

class Controller
{
    use Jump;

    /* *
 * @var \\think\\View View instance */
    protected $view;

    /* *
 * @var \\think\\Request Request Instance */
    protected $request;

    /* *
 * @var bool Whether validation failure throws an exception */
    protected $failException = false;

    /* *
 * @var bool Whether to validate in batches */
    protected $batchValidate = false;

    /* *
 * @var array List of before-action methods */
    protected $beforeActionList = [];

    /* *
 * Constructor
 * @access public
 * @param Request $request Request object */
    public function __construct(?Request $request = null)
    {
        $this->view    = View::instance(Config::get('template'), Config::get('view_replace_str'));
        $this->request = is_null($request) ? Request::instance() : $request;

        // Controller initialization
        $this->_initialize();

        // Run before-action methods
        if ($this->beforeActionList) {
            foreach ($this->beforeActionList as $method => $options) {
                is_numeric($method) ?
                $this->beforeAction($options) :
                $this->beforeAction($method, $options);
            }
        }
    }

    /* *
 * Initialize the controller
 * @access protected */
    protected function _initialize()
    {
    }

    /* *
 * Run a before-action method
 * @access protected
 * @param string $method Before-action method name
 * @param array $options call parameters [\'only\' => [...]] or [\'except\' => [...]]
 * @return void */
    protected function beforeAction($method, $options = [])
    {
        if (isset($options['only'])) {
            if (is_string($options['only'])) {
                $options['only'] = explode(',', $options['only']);
            }

            if (!in_array($this->request->action(), $options['only'])) {
                return;
            }
        } elseif (isset($options['except'])) {
            if (is_string($options['except'])) {
                $options['except'] = explode(',', $options['except']);
            }

            if (in_array($this->request->action(), $options['except'])) {
                return;
            }
        }

        call_user_func([$this, $method]);
    }

    /* *
 * Render a template file
 * @access protected
 * @param string $template template filename
 * @param array $vars Template variables
 * @param array $replace Template replacement strings
 * @param array $config template parameters
 * @return mixed */
    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        return $this->view->fetch($template, $vars, $replace, $config);
    }

    /* *
 * Render a template string
 * @access protected
 * @param string $content template content
 * @param array $vars Template variables
 * @param array $replace Template replacement strings
 * @param array $config template parameters
 * @return mixed */
    protected function display($content = '', $vars = [], $replace = [], $config = [])
    {
        return $this->view->display($content, $vars, $replace, $config);
    }

    /* *
 * Template variable assignment
 * @access protected
 * @param mixed $name Template variable name or array of variables
 * @param mixed $value Template variable value
 * @return $this */
    protected function assign($name, $value = '')
    {
        $this->view->assign($name, $value);

        return $this;
    }

    /* *
 * Initialize the template engine
 * @access protected
 * @param array|string $engine Template engine configuration
 * @return $this */
    protected function engine($engine)
    {
        $this->view->engine($engine);

        return $this;
    }

    /* *
 * Set whether validation failure throws an exception
 * @access protected
 * @param bool $file Whether to throw an exception
 * @return $this */
    protected function validateFailException($fail = true)
    {
        $this->failException = $fail;

        return $this;
    }

    /* *
 * Validate data
 * @access protected
 * @param array $data
 * @param string|array $validate validator name or array of validation rules
 * @param array $message Validation error messages
 * @param bool $batch Whether to validate in batches
 * @param mixed $callback Callback to run before validation (closure)
 * @return array|string|true
 * @throws validateException */
    protected function validate($data, $validate, $message = [], $batch = false, $callback = null)
    {
        if (is_array($validate)) {
            $v = Loader::validate();
            $v->rule($validate);
        } else {
            // Select the validation scene
            if (strpos($validate, '.')) {
                list($validate, $scene) = explode('.', $validate);
            }

            $v = Loader::validate($validate);

            !empty($scene) && $v->scene($scene);
        }

        // Enable batch validation
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        // Setting error messages
        if (is_array($message)) {
            $v->message($message);
        }

        // Run the validation callback
        if ($callback && is_callable($callback)) {
            call_user_func_array($callback, [$v, &$data]);
        }

        if (!$v->check($data)) {
            if ($this->failException) {
                throw new ValidateException($v->getError());
            }

            return $v->getError();
        }

        return true;
    }
}
