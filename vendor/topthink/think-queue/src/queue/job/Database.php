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
use think\queue\connector\Database as DatabaseQueue;

class Database extends Job
{
    /* *
 * The database queue instance.
 * @var DatabaseQueue */
    protected $database;

    /* *
 * The database job payload.
 * @var object */
    protected $job;

    public function __construct(DatabaseQueue $database, $job, $queue)
    {
        $this->job           = $job;
        $this->queue         = $queue;
        $this->database      = $database;
        $this->job->attempts = $this->job->attempts + 1;
    }

    /* *
 * Execute the queued job
 * @return void */
    public function fire()
    {
        $this->resolveAndFire(json_decode($this->job->payload, true));
    }

    /* *
 * Delete the job from the queue
 * @return void */
    public function delete()
    {
        parent::delete();
        $this->database->deleteReserved($this->job->id);
    }

    /* *
 * Release the job back to the queue
 * @param int $delay
 * @return void */
    public function release($delay = 0)
    {
        parent::release($delay);

        $this->delete();

        $this->database->release($this->queue, $this->job, $delay);
    }

    /* *
 * Get the number of times the job has been attempted
 * @return int */
    public function attempts()
    {
        return (int) $this->job->attempts;
    }

    /* *
 * Get the raw body string for the job.
 * @return string */
    public function getRawBody()
    {
        return $this->job->payload;
    }
}
