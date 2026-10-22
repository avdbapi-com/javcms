<?php

namespace think\addons;

use think\Config;
use think\exception\HttpException;
use think\Hook;
use think\Loader;
use think\Request;

/* *
 * Dispatch requests to plugin controllers
 * @package think\\ addons */
class Route
{

    /* *
 * Execute a plugin controller action */
    public function execute($addon = null, $controller = null, $action = null)
    {
        $request = Request::instance();
        // Check whether controller and action names are normalized
        $convert = Config::get('url_convert');
        $filter = $convert ? 'strtolower' : 'trim';

        $addon = $addon ? trim(call_user_func($filter, $addon)) : '';
        $controller = $controller ? trim(call_user_func($filter, $controller)) : 'index';
        $action = $action ? trim(call_user_func($filter, $action)) : 'index';

        Hook::listen('addon_begin', $request);
        if (!empty($addon) && !empty($controller) && !empty($action)) {
            $info = get_addon_info($addon);
            if (!$info) {
                throw new HttpException(404, __('addon %s not found', $addon));
            }
            if (!$info['state']) {
                throw new HttpException(500, __('addon %s is disabled', $addon));
            }
            $dispatch = $request->dispatch();
            if (isset($dispatch['var']) && $dispatch['var']) {
                // $request->route($dispatch[\'var\']);
            }

            // Set the controller and action for the current request
            $request->controller($controller)->action($action);

            // Invoke the addon_module_init hook
            Hook::listen('addon_module_init', $request);
            // Invoke the deprecated addons_init compatibility hook
            Hook::listen('addons_init', $request);

            $class = get_addon_class($addon, 'controller', $controller);
            if (!$class) {
                throw new HttpException(404, __('addon controller %s not found', Loader::parseName($controller, 1)));
            }

            $instance = new $class($request);

            $vars = [];
            if (is_callable([$instance, $action])) {
                // Execute the action method
                $call = [$instance, $action];
            } elseif (is_callable([$instance, '_empty'])) {
                // Use the fallback action handler
                $call = [$instance, '_empty'];
                $vars = [$action];
            } else {
                // Action does not exist
                throw new HttpException(404, __('addon action %s not found', get_class($instance) . '->' . $action . '()'));
            }

            Hook::listen('addon_action_begin', $call);

            return call_user_func_array($call, $vars);
        } else {
            abort(500, lang('addon can not be empty'));
        }
    }

}
