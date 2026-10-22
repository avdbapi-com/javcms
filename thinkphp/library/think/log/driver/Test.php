<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\log\driver;

/* *
 * No-op log driver for testing */
class Test
{
    /* *
 * Accept log messages without writing them
 * @access public
 * @param array $log Log messages
 * @return bool */
    public function save(array $log = [])
    {
        return true;
    }

}
