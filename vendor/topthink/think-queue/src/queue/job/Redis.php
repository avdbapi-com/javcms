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

namespace think\queue\job;

use think\queue\Job;
use think\queue\connector\Redis as RedisQueue;

class Redis extends Job
{

    /* *
 * The Redis queue instance.
 * @var RedisQueue */
    protected $redis;

    /* *
 * The Redis job payload.
 * @var string */
    protected $job;

    public function __construct(RedisQueue $redis, $job, $queue)
    {
        $this->job   = $job;
        $this->queue = $queue;
        $this->redis = $redis;
    }

    /* *
 * Fire the job.
 * @return void */
    public function fire()
    {
        $this->resolveAndFire(json_decode($this->getRawBody(), true));
    }

    /* *
 * Get the number of times the job has been attempted.
 * @return int */
    public function attempts()
    {
        return json_decode($this->job, true)['attempts'];
    }

    /* *
 * Get the raw body string for the job.
 * @return string */
    public function getRawBody()
    {
        return $this->job;
    }

    /* *
 * Delete the job from the queue
 *
 * @return void */
    public function delete()
    {
        parent::delete();

        $this->redis->deleteReserved($this->queue, $this->job);
    }

    /* *
 * Release the job back to the queue
 *
 * @param int $delay
 * @return void */
    public function release($delay = 0)
    {
        parent::release($delay);

        $this->delete();

        $this->redis->release($this->queue, $this->job, $delay, $this->attempts() + 1);
    }
}
