<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\controller;

/* *
 * ThinkPHP Yar controller class */
abstract class Yar
{

    /* *
 * Initialize the controller and handle the Yar RPC request
 * @access public */
    public function __construct()
    {
        // Controller initialization
        if (method_exists($this, '_initialize')) {
            $this->_initialize();
        }

        // Check whether the Yar extension is loaded
        if (!extension_loaded('yar')) {
            throw new \Exception('not support yar');
        }

        // Create a Yar_Server for this controller
        $server = new \Yar_Server($this);
        // Start the server
        $server->handle();
    }

    /* *
 * Handle calls to undefined controller methods
 * @access public
 * @param string $method method name
 * @param array $args Method arguments
 * @return mixed */
    public function __call($method, $args)
    {}
}
