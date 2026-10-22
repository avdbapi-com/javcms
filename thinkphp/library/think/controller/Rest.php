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

namespace think\controller;

use think\App;
use think\Request;
use think\Response;

abstract class Rest
{

    protected $method; // Current HTTP request method
    protected $type; // Current resource type
    // Supported request methods and response formats
    protected $restMethodList    = 'get|post|put|delete';
    protected $restDefaultMethod = 'get';
    protected $restTypeList      = 'html|xml|json|rss';
    protected $restDefaultType   = 'html';
    protected $restOutputType    = [ // MIME types for REST response formats
        'xml'  => 'application/xml',
        'json' => 'application/json',
        'html' => 'text/html',
    ];

    /* *
 * Detect the request method and resource format
 * @access public */
    public function __construct()
    {
        // Resource type detection
        $request = Request::instance();
        $ext     = $request->ext();
        if ('' == $ext) {
            // Detect the resource format from the request
            $this->type = $request->type();
        } elseif (!preg_match('/(' . $this->restTypeList . ')$/i', $ext)) {
            // Use the default resource format when the extension is unsupported
            $this->type = $this->restDefaultType;
        } else {
            $this->type = $ext;
        }
        // Request method detection
        $method = strtolower($request->method());
        if (!preg_match('/(' . $this->restMethodList . ')$/i', $method)) {
            // Use the default request method when the method is unsupported
            $method = $this->restDefaultMethod;
        }
        $this->method = $method;
    }

    /* *
 * Dispatch a REST action to the matching method and resource format
 * @access public
 * @param string $method method name
 * @return mixed
 * @throws \Exception */
    public function _empty($method)
    {
        if (method_exists($this, $method . '_' . $this->method . '_' . $this->type)) {
            // Prefer an action matching both the request method and resource format
            $fun = $method . '_' . $this->method . '_' . $this->type;
        } elseif ($this->method == $this->restDefaultMethod && method_exists($this, $method . '_' . $this->type)) {
            $fun = $method . '_' . $this->type;
        } elseif ($this->type == $this->restDefaultType && method_exists($this, $method . '_' . $this->method)) {
            $fun = $method . '_' . $this->method;
        }
        if (isset($fun)) {
            return App::invokeMethod([$this, $fun]);
        } else {
            // Throw an exception
            throw new \Exception('error action :' . $method);
        }
    }

    /* *
 * Create a response with the specified data, format, and status
 * @access protected
 * @param mixed $data The data to be returned
 * @param string $type Response format, such as json or xml
 * @param integer $code HTTP status code
 * @return Response */
    protected function response($data, $type = 'json', $code = 200)
    {
        return Response::create($data, $type)->code($code);
    }

}
