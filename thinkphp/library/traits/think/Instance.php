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

namespace traits\think;

use think\Exception;

trait Instance
{
    /* *
 * @var null|static Shared instance */
    protected static $instance = null;

    /* *
 * Get the shared instance
 * @param array $options Instance configuration
 * @return static */
    public static function instance($options = [])
    {
        if (is_null(self::$instance)) self::$instance = new self($options);

        return self::$instance;
    }

    /* *
 * Forward an underscore-prefixed static call to the shared instance
 * @param string $method Static method name with an underscore prefix
 * @param array $params Method arguments
 * @return mixed
 * @throws Exception */
    public static function __callStatic($method, array $params)
    {
        if (is_null(self::$instance)) self::$instance = new self();

        $call = substr($method, 1);

        if (0 !== strpos($method, '_') || !is_callable([self::$instance, $call])) {
            throw new Exception("method not exists:" . $method);
        }

        return call_user_func_array([self::$instance, $call], $params);
    }
}
