<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue;

trait Queueable
{

    /* * @var string Queue name */
    public $queue;

    /* * @var integer Delay in seconds */
    public $delay;

    /* *
 * Set the queue name
 * @param string $queue Queue name
 * @return $this */
    public function queue($queue)
    {
        $this->queue = $queue;

        return $this;
    }

    /* *
 * Set the delay in seconds
 * @param int $delay Delay in seconds
 * @return $this */
    public function delay($delay)
    {
        $this->delay = $delay;

        return $this;
    }
}
