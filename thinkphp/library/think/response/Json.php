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

use think\Response;

class Json extends Response
{
    // output parameters
    protected $options = [
        'json_encode_param' => JSON_UNESCAPED_UNICODE,
    ];

    protected $contentType = 'application/json';

    /* *
 * Encode response data as JSON
 * @access protected
 * @param mixed $data Data to encode
 * @return string
 * @throws \Exception */
    protected function output($data)
    {
        try {
            // Encode response data using the configured JSON flags
            $data = json_encode($data, $this->options['json_encode_param']);

            if ($data === false) {
                throw new \InvalidArgumentException(json_last_error_msg());
            }

            return $data;
        } catch (\Exception $e) {
            if ($e->getPrevious()) {
                throw $e->getPrevious();
            }
            throw $e;
        }
    }

}
