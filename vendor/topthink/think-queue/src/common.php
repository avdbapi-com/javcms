<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

\think\Console::addDefaultCommands([
    "think\\queue\\command\\Work",
    "think\\queue\\command\\Restart",
    "think\\queue\\command\\Listen",
    "think\\queue\\command\\Subscribe"
]);

if (!function_exists('queue')) {

    /* *
 * Add a job to the queue
 * @param mixed $job Job handler or queued command
 * @param mixed $data Job payload
 * @param int $delay Delay in seconds
 * @param string|null $queue Queue name */
    function queue($job, $data = '', $delay = 0, $queue = null)
    {
        if ($delay > 0) {
            \think\Queue::later($delay, $job, $data, $queue);
        } else {
            \think\Queue::push($job, $data, $queue);
        }
    }
}
