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

namespace think\queue\connector;

use Exception;
use think\helper\Str;
use think\queue\Connector;
use think\queue\job\Redis as RedisJob;

class Redis extends Connector
{
    /* * @var \Redis */
    protected $redis;

    protected $options = [
        'expire'     => 60,
        'default'    => 'default',
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'select'     => 0,
        'timeout'    => 0,
        'persistent' => false
    ];

    public function __construct($options)
    {
        if (!extension_loaded('redis')) {
            throw new Exception('Redis extension is not installed');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $func        = $this->options['persistent'] ? 'pconnect' : 'connect';
        $this->redis = new \Redis;
        $this->redis->$func($this->options['host'], $this->options['port'], $this->options['timeout']);

        if ('' != $this->options['password']) {
            $this->redis->auth($this->options['password']);
        }

        if (0 != $this->options['select']) {
            $this->redis->select($this->options['select']);
        }
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $data);

        $this->redis->zAdd($this->getQueue($queue) . ':delayed', time() + $delay, $payload);
    }

    public function pop($queue = null)
    {
        $original = $queue ?: $this->options['default'];

        $queue = $this->getQueue($queue);

        $this->migrateExpiredJobs($queue . ':delayed', $queue, false);

        if (!is_null($this->options['expire'])) {
            $this->migrateExpiredJobs($queue . ':reserved', $queue);
        }

        $job = $this->redis->lPop($queue);

        if ($job !== false) {
            $this->redis->zAdd($queue . ':reserved', time() + $this->options['expire'], $job);

            return new RedisJob($this, $job, $original);
        }
    }

    /* *
 * Release the job back to the queue
 *
 * @param string $queue
 * @param string $payload
 * @param int $delay
 * @param int $attempts
 * @return void */
    public function release($queue, $payload, $delay, $attempts)
    {
        $payload = $this->setMeta($payload, 'attempts', $attempts);

        $this->redis->zAdd($this->getQueue($queue) . ':delayed', time() + $delay, $payload);
    }

    public function pushRaw($payload, $queue = null)
    {
        $this->redis->rPush($this->getQueue($queue), $payload);

        return json_decode($payload, true)['id'];
    }

    protected function createPayload($job, $data = '', $queue = null)
    {
        $payload = $this->setMeta(
            parent::createPayload($job, $data), 'id', $this->getRandomId()
        );

        return $this->setMeta($payload, 'attempts', 1);
    }

    /* *
 * Delete a reserved job
 *
 * @param string $queue
 * @param string $job
 * @return void */
    public function deleteReserved($queue, $job)
    {
        $this->redis->zRem($this->getQueue($queue) . ':reserved', $job);
    }

    /* *
 * Move expired delayed or reserved jobs to the ready queue
 *
 * @param string $from
 * @param string $to
 * @param bool $attempt */
    public function migrateExpiredJobs($from, $to, $attempt = true)
    {
        $this->redis->watch($from);

        $jobs = $this->getExpiredJobs(
            $from, $time = time()
        );
        if (count($jobs) > 0) {
            $this->transaction(function () use ($from, $to, $time, $jobs, $attempt) {
                $this->removeExpiredJobs($from, $time);
                $this->pushExpiredJobsOntoNewQueue($to, $jobs, $attempt);
            });
        }
        $this->redis->unwatch();
    }

    /* *
 * Execute a Redis transaction
 * @param \Closure $closure */
    protected function transaction(\Closure $closure)
    {
        $this->redis->multi();
        try {
            call_user_func($closure);
            if (!$this->redis->exec()) {
                $this->redis->discard();
            }
        } catch (Exception $e) {
            $this->redis->discard();
        }
    }

    /* *
 * Get all jobs whose delay or reservation has expired
 *
 * @param string $from
 * @param int $time
 * @return array */
    protected function getExpiredJobs($from, $time)
    {
        return $this->redis->zRangeByScore($from, '-inf', $time);
    }

    /* *
 * Remove jobs whose delay or reservation has expired
 *
 * @param string $from
 * @param int $time
 * @return void */
    protected function removeExpiredJobs($from, $time)
    {
        $this->redis->zRemRangeByScore($from, '-inf', $time);
    }

    /* *
 * Return expired jobs to the ready queue
 *
 * @param string $to
 * @param array $jobs
 * @param boolean $attempt */
    protected function pushExpiredJobsOntoNewQueue($to, $jobs, $attempt = true)
    {
        if ($attempt) {
            foreach ($jobs as &$job) {
                $attempts = json_decode($job, true)['attempts'];
                $job      = $this->setMeta($job, 'attempts', $attempts + 1);
            }
        }
        call_user_func_array([$this->redis, 'rPush'], array_merge([$to], $jobs));
    }

    /* *
 * Generate a random job ID
 *
 * @return string */
    protected function getRandomId()
    {
        return Str::random(32);
    }

    /* *
 * Get the queue name
 *
 * @param string|null $queue
 * @return string */
    protected function getQueue($queue)
    {
        return 'queues:' . ($queue ?: $this->options['default']);
    }
}
