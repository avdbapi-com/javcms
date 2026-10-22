<?php

namespace think\addons;

use think\Config;
use think\Hook;
use think\Lang;
use think\Loader;
use think\Request;

/* *
 * Base controller for plugins
 * @package think\\ addons */
class Controller extends \think\Controller
{

    // Current plugin, controller, and action
    protected $addon = null;
    protected $controller = null;
    protected $action = null;
    // Current template
    protected $template;

    /* *
 * Actions that do not require login
 * @var array */
    protected $noNeedLogin = ['*'];

    /* *
 * Actions that do not require permission checks
 * @var array */
    protected $noNeedRight = ['*'];

    /* *
 * Authorization instance
 * @var Auth */
    protected $auth = null;

    /* *
 * Layout template
 * @var string */
    protected $layout = null;

    /* *
 * Initialize the plugin controller
 * @param Request $request Request instance
 * @access public */
    public function __construct(?Request $request = null)
    {
        if (is_null($request))
        {
            $request = Request::instance();
        }
        // Use the provided request
        $this->request = $request;

        // Remove HTML tags
        $this->request->filter('trim,strip_tags,htmlspecialchars');

        // Check whether controller and action names are normalized
        $convert = Config::get('url_convert');

        $filter = $convert ? 'strtolower' : 'trim';
        // Merge route parameters
        $param = $this->request->param();
        $dispatch = $this->request->dispatch();
        $var = isset($dispatch['var']) ? $dispatch['var'] : [];
        $var = array_merge($param, $var);
        if (isset($dispatch['method']) && substr($dispatch['method'][0], 0, 7) == "\\addons")
        {
            $arr = explode("\\", $dispatch['method'][0]);
            $addon = strtolower($arr[2]);
            $controller = strtolower(end($arr));
            $action = $dispatch['method'][1];
        }
        else
        {
            $addon = isset($var['addon']) ? $var['addon'] : '';
            $controller = isset($var['controller']) ? $var['controller'] : '';
            $action = isset($var['action']) ? $var['action'] : '';
        }

        $this->addon = $addon ? call_user_func($filter, $addon) : '';
        $this->controller = $controller ? call_user_func($filter, $controller) : 'index';
        $this->action = $action ? call_user_func($filter, $action) : 'index';

        // Reset the configuration
        Config::set('template.view_path', ADDON_PATH . $this->addon . DS . 'view' . DS);

        // The parent class call must be placed after setting the template path
        parent::__construct($this->request);
    }

    protected function _initialize()
    {
        // Assign the plugin configuration to the view
        $config = get_addon_config($this->addon);
        $this->view->assign("config", $config);

        // Load the system language pack
        Lang::load([
            ADDON_PATH . $this->addon . DS . 'lang' . DS . $this->request->langset() . EXT,
        ]);

        // Set replacement strings
        $cdnurl = Config::get('site.cdnurl');
        $this->view->replace('__ADDON__', $cdnurl . "/assets/addons/" . $this->addon);
    }

    /* *
 * Render a plugin template
 * @access protected
 * @param string $template template filename
 * @param array $vars template output variables
 * @param array $replace template replacement
 * @param array $config template parameters
 * @return mixed */
    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        $controller = Loader::parseName($this->controller);
        if ('think' == strtolower(Config::get('template.type')) && $controller && 0 !== strpos($template, '/'))
        {
            $depr = Config::get('template.view_depr');
            $template = str_replace(['/', ':'], $depr, $template);
            if ('' == $template)
            {
                // Resolve an empty template name using the default rules
                $template = str_replace('.', DS, $controller) . $depr . $this->action;
            }
            elseif (false === strpos($template, $depr))
            {
                $template = str_replace('.', DS, $controller) . $depr . $template;
            }
        }
        return parent::fetch($template, $vars, $replace, $config);
    }

}
