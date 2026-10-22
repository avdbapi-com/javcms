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

use think\Request;
use think\Response;

class Jsonp extends Response
{
    // output parameters
    protected $options = [
        'var_jsonp_handler'     => 'callback',
        'default_jsonp_handler' => 'jsonpReturn',
        'json_encode_param'     => JSON_UNESCAPED_UNICODE,
    ];

    protected $contentType = 'application/javascript';

    /* *
 * Encode response data as a JSONP callback invocation
 * @access protected
 * @param mixed $data Data to encode
 * @return string
 * @throws \Exception */
    protected function output($data)
    {
        try {
            // < xiaobo.sun@qq.com > Read the JSONP callback name through Request
            $var_jsonp_handler = Request::instance()->param($this->options['var_jsonp_handler'], "");
            $defaultHandler    = $this->options['default_jsonp_handler'];
            $handler           = !empty($var_jsonp_handler) ? $var_jsonp_handler : $defaultHandler;

            // A JSONP callback is executable JavaScript syntax. Accept only a
            // dotted identifier chain so request data cannot inject statements.
            if (!is_string($handler) || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/D', $handler)) {
                $handler = is_string($defaultHandler) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/D', $defaultHandler)
                    ? $defaultHandler
                    : 'jsonpReturn';
            }

            $data = json_encode($data, $this->options['json_encode_param']);

            if ($data === false) {
                throw new \InvalidArgumentException(json_last_error_msg());
            }

            $data = $handler . '(' . $data . ');';
            return $data;
        } catch (\Exception $e) {
            if ($e->getPrevious()) {
                throw $e->getPrevious();
            }
            throw $e;
        }
    }

}
